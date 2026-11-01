<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessSensitiveDataGuard.php';
require_once __DIR__ . '/GenerationStateMachine.php';

final readonly class GenerationAttemptDraft
{
    public int $stepId;
    public int $attemptNo;
    public string $provider;
    public string $model;
    public string $modelConfigJson;
    public ?string $providerRequestId;
    public ?int $inputArtifactId;

    /** @param array<string,mixed> $modelConfig */
    public function __construct(
        int $stepId,
        int $attemptNo,
        string $provider,
        string $model,
        array $modelConfig,
        ?string $providerRequestId = null,
        ?int $inputArtifactId = null
    ) {
        $this->stepId = HarnessPersistenceValue::positiveInt($stepId, 'step_id');
        $this->attemptNo = HarnessPersistenceValue::boundedInt($attemptNo, 'attempt_no', 1, 65535);
        $this->provider = HarnessPersistenceValue::identifier($provider, 'provider', 64);
        $this->model = HarnessPersistenceValue::string($model, 'model', 1, 191);
        HarnessSensitiveDataGuard::assertSafeStructure($modelConfig, 'attempt.model_config');
        $this->modelConfigJson = HarnessPersistenceValue::canonicalMysqlJson($modelConfig);
        HarnessSensitiveDataGuard::assertSafeText(
            $this->modelConfigJson,
            'attempt.model_config'
        );
        $this->providerRequestId = HarnessPersistenceValue::optionalString(
            $providerRequestId,
            'provider_request_id',
            191
        );
        $this->inputArtifactId = HarnessPersistenceValue::optionalPositiveInt(
            $inputArtifactId,
            'input_artifact_id'
        );
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        HarnessPersistenceValue::requireFields($row, [
            'step_id', 'attempt_no', 'provider', 'model', 'model_config_json',
            'provider_request_id', 'input_artifact_id',
        ], 'generation_attempt');
        $storedModelConfig = HarnessPersistenceValue::decodeJsonArray(
            $row['model_config_json'],
            'model_config_json'
        );
        HarnessSensitiveDataGuard::assertSafeStructure(
            $storedModelConfig,
            'attempt.model_config'
        );
        $storedModelConfigJson = HarnessPersistenceValue::canonicalMysqlJson(
            $storedModelConfig
        );
        HarnessSensitiveDataGuard::assertSafeText(
            $storedModelConfigJson,
            'attempt.model_config'
        );
        $matches = (int)$row['step_id'] === $this->stepId
            && (int)$row['attempt_no'] === $this->attemptNo
            && (string)$row['provider'] === $this->provider
            && (string)$row['model'] === $this->model
            && ($row['provider_request_id'] ?? null) === $this->providerRequestId
            && HarnessPersistenceValue::optionalPositiveInt(
                $row['input_artifact_id'],
                'input_artifact_id'
            ) === $this->inputArtifactId
            && $storedModelConfigJson === $this->modelConfigJson;
        if (!$matches) {
            throw new HarnessIdempotencyConflictException(
                'Attempt number resolved to a different immutable provider request.'
            );
        }
    }
}

final readonly class GenerationAttempt
{
    public function __construct(
        public int $id,
        public int $stepId,
        public int $attemptNo,
        public GenerationAttemptState $state,
        public string $provider,
        public string $model,
        public ?string $providerRequestId,
        public ?int $inputArtifactId,
        public ?int $outputArtifactId,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheTokens,
        public string $cost,
        public ?int $latencyMs
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'step_id', 'attempt_no', 'state', 'provider', 'model',
            'provider_request_id', 'input_artifact_id', 'output_artifact_id',
            'input_tokens', 'output_tokens', 'cache_tokens', 'cost', 'latency_ms',
        ], 'generation_attempt');
        $state = is_string($row['state']) ? GenerationAttemptState::tryFrom($row['state']) : null;
        if (!$state instanceof GenerationAttemptState) {
            throw new InvalidArgumentException('generation_attempt.state is unknown.');
        }
        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            HarnessPersistenceValue::positiveInt($row['step_id'], 'step_id'),
            HarnessPersistenceValue::boundedInt($row['attempt_no'], 'attempt_no', 1, 65535),
            $state,
            HarnessPersistenceValue::identifier($row['provider'], 'provider', 64),
            HarnessPersistenceValue::string($row['model'], 'model', 1, 191),
            HarnessPersistenceValue::optionalString($row['provider_request_id'], 'provider_request_id', 191),
            HarnessPersistenceValue::optionalPositiveInt($row['input_artifact_id'], 'input_artifact_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['output_artifact_id'], 'output_artifact_id'),
            HarnessPersistenceValue::nonNegativeInt($row['input_tokens'], 'input_tokens'),
            HarnessPersistenceValue::nonNegativeInt($row['output_tokens'], 'output_tokens'),
            HarnessPersistenceValue::nonNegativeInt($row['cache_tokens'], 'cache_tokens'),
            HarnessPersistenceValue::decimal((string)$row['cost'], 'cost'),
            HarnessPersistenceValue::optionalNonNegativeInt($row['latency_ms'], 'latency_ms')
        );
    }
}

final readonly class GenerationAttemptResult
{
    public ?int $outputArtifactId;
    public int $inputTokens;
    public int $outputTokens;
    public int $cacheTokens;
    public string $cost;
    public int $latencyMs;
    public ?string $finishReason;
    public ?string $errorCode;
    public ?string $errorMessage;

    public function __construct(
        public int $attemptId,
        public GenerationAttemptState $next,
        ?int $outputArtifactId = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cacheTokens = 0,
        string $cost = '0',
        int $latencyMs = 0,
        ?string $finishReason = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ) {
        HarnessPersistenceValue::positiveInt($attemptId, 'attempt_id');
        GenerationStateMachine::assertAttemptTransition(GenerationAttemptState::RUNNING, $next);
        $this->outputArtifactId = HarnessPersistenceValue::optionalPositiveInt(
            $outputArtifactId,
            'output_artifact_id'
        );
        $this->inputTokens = HarnessPersistenceValue::nonNegativeInt($inputTokens, 'input_tokens');
        $this->outputTokens = HarnessPersistenceValue::nonNegativeInt($outputTokens, 'output_tokens');
        $this->cacheTokens = HarnessPersistenceValue::nonNegativeInt($cacheTokens, 'cache_tokens');
        $this->cost = HarnessPersistenceValue::decimal($cost, 'cost');
        $this->latencyMs = HarnessPersistenceValue::nonNegativeInt($latencyMs, 'latency_ms');
        $this->finishReason = HarnessPersistenceValue::optionalString(
            $finishReason,
            'finish_reason',
            64
        );
        $this->errorCode = HarnessPersistenceValue::optionalString($errorCode, 'error_code', 64);
        $this->errorMessage = HarnessPersistenceValue::optionalString(
            $errorMessage,
            'error_message',
            500
        );
    }
}
