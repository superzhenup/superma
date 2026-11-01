<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessSensitiveDataGuard.php';
require_once __DIR__ . '/GenerationStateMachine.php';

final readonly class GenerationStepDraft
{
    public int $runId;
    public string $stepKey;
    public int $iterationNo;
    public int $ordinal;
    public string $stepType;
    public ?string $inputHash;
    public int $maxAttempts;
    public string $policyJson;

    /** @param array<string,mixed> $policy */
    public function __construct(
        int $runId,
        string $stepKey,
        int $iterationNo,
        int $ordinal,
        string $stepType,
        ?string $inputHash,
        int $maxAttempts,
        array $policy
    ) {
        $this->runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $this->stepKey = HarnessPersistenceValue::identifier($stepKey, 'step_key', 64);
        $this->iterationNo = HarnessPersistenceValue::boundedInt($iterationNo, 'iteration_no', 0, 65535);
        $this->ordinal = HarnessPersistenceValue::boundedInt($ordinal, 'ordinal', 0, 65535);
        $this->stepType = HarnessPersistenceValue::identifier($stepType, 'step_type', 64);
        $this->inputHash = HarnessPersistenceValue::optionalHash($inputHash, 'input_hash');
        $this->maxAttempts = HarnessPersistenceValue::boundedInt(
            $maxAttempts,
            'max_attempts',
            1,
            100
        );
        HarnessSensitiveDataGuard::assertSafeStructure($policy, 'step.policy');
        $this->policyJson = HarnessPersistenceValue::canonicalMysqlJson($policy);
        HarnessSensitiveDataGuard::assertSafeText($this->policyJson, 'step.policy');
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        HarnessPersistenceValue::requireFields($row, [
            'run_id', 'step_key', 'iteration_no', 'ordinal', 'step_type',
            'input_hash', 'max_attempts', 'policy_json',
        ], 'generation_step');
        $storedPolicy = HarnessPersistenceValue::decodeJsonArray(
            $row['policy_json'],
            'policy_json'
        );
        HarnessSensitiveDataGuard::assertSafeStructure($storedPolicy, 'step.policy');
        $storedPolicyJson = HarnessPersistenceValue::canonicalMysqlJson($storedPolicy);
        HarnessSensitiveDataGuard::assertSafeText($storedPolicyJson, 'step.policy');
        $matches = (int)$row['run_id'] === $this->runId
            && (string)$row['step_key'] === $this->stepKey
            && (int)$row['iteration_no'] === $this->iterationNo
            && (int)$row['ordinal'] === $this->ordinal
            && (string)$row['step_type'] === $this->stepType
            && ($row['input_hash'] ?? null) === $this->inputHash
            && (int)$row['max_attempts'] === $this->maxAttempts
            && $storedPolicyJson === $this->policyJson;
        if (!$matches) {
            throw new HarnessIdempotencyConflictException(
                'Step identity resolved to a different immutable definition.'
            );
        }
    }
}

final readonly class GenerationStep
{
    public function __construct(
        public int $id,
        public int $runId,
        public string $stepKey,
        public int $iterationNo,
        public int $ordinal,
        public string $stepType,
        public GenerationStepState $state,
        public int $progress,
        public ?string $inputHash,
        public ?string $outputHash,
        public ?int $outputArtifactId,
        public int $attempt,
        public int $maxAttempts,
        public ?string $retryAt,
        public ?string $leaseToken,
        public int $fenceGeneration,
        public ?string $leaseExpiresAt
    ) {
        if ($attempt > $maxAttempts) {
            throw new InvalidArgumentException('generation Step attempt exceeds max_attempts.');
        }
        if ($state === GenerationStepState::SUCCEEDED && $progress !== 100) {
            throw new InvalidArgumentException('Succeeded generation Step must report 100 progress.');
        }
        if ($state === GenerationStepState::SUCCEEDED
            && ($outputHash === null || $outputArtifactId === null)) {
            throw new InvalidArgumentException(
                'Succeeded generation Step must reference its output Artifact and hash.'
            );
        }
        if ($state === GenerationStepState::RETRY_WAIT && $retryAt === null) {
            throw new InvalidArgumentException('retry_wait generation Step requires retry_at.');
        }
        if ($state !== GenerationStepState::RETRY_WAIT && $retryAt !== null) {
            throw new InvalidArgumentException('Only retry_wait generation Steps may retain retry_at.');
        }
        if ($state === GenerationStepState::RUNNING) {
            if ($leaseToken === null
                || $fenceGeneration < 1
                || $leaseExpiresAt === null
                || $attempt < 1) {
                throw new InvalidArgumentException('Running generation Step has an incomplete lease fence.');
            }
        } elseif ($leaseToken !== null || $leaseExpiresAt !== null) {
            throw new InvalidArgumentException('Non-running generation Step retains lease ownership.');
        }
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'run_id', 'step_key', 'iteration_no', 'ordinal', 'step_type',
            'state', 'progress', 'input_hash', 'output_hash', 'output_artifact_id',
            'attempt', 'max_attempts', 'retry_at', 'lease_token',
            'fence_generation', 'lease_expires_at',
        ], 'generation_step');
        $state = is_string($row['state']) ? GenerationStepState::tryFrom($row['state']) : null;
        if (!$state instanceof GenerationStepState) {
            throw new InvalidArgumentException('generation_step.state is unknown.');
        }
        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            HarnessPersistenceValue::positiveInt($row['run_id'], 'run_id'),
            HarnessPersistenceValue::identifier($row['step_key'], 'step_key', 64),
            HarnessPersistenceValue::boundedInt($row['iteration_no'], 'iteration_no', 0, 65535),
            HarnessPersistenceValue::boundedInt($row['ordinal'], 'ordinal', 0, 65535),
            HarnessPersistenceValue::identifier($row['step_type'], 'step_type', 64),
            $state,
            HarnessPersistenceValue::boundedInt($row['progress'], 'progress', 0, 100),
            HarnessPersistenceValue::optionalHash($row['input_hash'], 'input_hash'),
            HarnessPersistenceValue::optionalHash($row['output_hash'], 'output_hash'),
            HarnessPersistenceValue::optionalPositiveInt($row['output_artifact_id'], 'output_artifact_id'),
            HarnessPersistenceValue::nonNegativeInt($row['attempt'], 'attempt'),
            HarnessPersistenceValue::boundedInt($row['max_attempts'], 'max_attempts', 1, 100),
            HarnessPersistenceValue::optionalMysqlDate($row['retry_at'], 'retry_at'),
            $row['lease_token'] === null || $row['lease_token'] === ''
                ? null
                : HarnessPersistenceValue::uid($row['lease_token'], 'lease_token'),
            HarnessPersistenceValue::nonNegativeInt($row['fence_generation'], 'fence_generation'),
            HarnessPersistenceValue::optionalMysqlDate($row['lease_expires_at'], 'lease_expires_at')
        );
    }
}

final readonly class GenerationStepLease
{
    public function __construct(
        public GenerationStep $step,
        public string $token,
        public int $generation,
        public int $attempt,
        public string $expiresAt
    ) {
        if ($step->state !== GenerationStepState::RUNNING
            || $step->leaseToken !== $token
            || $step->fenceGeneration !== $generation
            || $step->attempt !== $attempt
            || $step->leaseExpiresAt !== $expiresAt) {
            throw new InvalidArgumentException('Generation Step lease does not match its durable row.');
        }
    }

    public static function fromStep(GenerationStep $step): self
    {
        if ($step->leaseToken === null || $step->leaseExpiresAt === null) {
            throw new InvalidArgumentException('Generation Step has no complete lease.');
        }
        return new self(
            $step,
            $step->leaseToken,
            $step->fenceGeneration,
            $step->attempt,
            $step->leaseExpiresAt
        );
    }
}

final readonly class GenerationStepTransition
{
    public ?string $outputHash;
    public ?int $outputArtifactId;
    public ?DateTimeImmutable $retryAt;
    public ?string $errorCode;
    public ?string $errorMessage;
    public ?int $expectedAttempt;
    public ?int $expectedFenceGeneration;

    public function __construct(
        public int $stepId,
        public GenerationStepState $expected,
        public GenerationStepState $next,
        public int $progress,
        ?string $outputHash = null,
        ?int $outputArtifactId = null,
        ?DateTimeImmutable $retryAt = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $expectedAttempt = null,
        ?int $expectedFenceGeneration = null
    ) {
        HarnessPersistenceValue::positiveInt($stepId, 'step_id');
        GenerationStateMachine::assertStepTransition($expected, $next);
        HarnessPersistenceValue::boundedInt($progress, 'progress', 0, 100);
        if ($next === GenerationStepState::SUCCEEDED && $progress !== 100) {
            throw new InvalidArgumentException('Succeeded Step transition requires 100 progress.');
        }
        if ($next === GenerationStepState::SUCCEEDED
            && ($outputHash === null || $outputArtifactId === null)) {
            throw new InvalidArgumentException(
                'Succeeded Step transition requires an output Artifact and its content hash.'
            );
        }
        if ($next === GenerationStepState::RETRY_WAIT && $retryAt === null) {
            throw new InvalidArgumentException('retry_wait Step transition requires retry_at.');
        }
        if ($next !== GenerationStepState::RETRY_WAIT && $retryAt !== null) {
            throw new InvalidArgumentException('Only retry_wait Step transitions may set retry_at.');
        }
        if (($expectedAttempt === null) !== ($expectedFenceGeneration === null)) {
            throw new InvalidArgumentException(
                'Step transition expected_attempt and expected_fence_generation must be supplied together.'
            );
        }
        if ($expected === GenerationStepState::RETRY_WAIT && $expectedAttempt === null) {
            throw new InvalidArgumentException(
                'retry_wait Step transition requires an attempt/fence snapshot to prevent ABA.'
            );
        }
        $this->outputHash = HarnessPersistenceValue::optionalHash($outputHash, 'output_hash');
        $this->outputArtifactId = HarnessPersistenceValue::optionalPositiveInt(
            $outputArtifactId,
            'output_artifact_id'
        );
        $this->retryAt = $retryAt;
        $this->errorCode = HarnessPersistenceValue::optionalString($errorCode, 'error_code', 64);
        $this->errorMessage = HarnessPersistenceValue::optionalString(
            $errorMessage,
            'error_message',
            500
        );
        $this->expectedAttempt = $expectedAttempt === null
            ? null
            : HarnessPersistenceValue::nonNegativeInt($expectedAttempt, 'expected_attempt');
        $this->expectedFenceGeneration = $expectedFenceGeneration === null
            ? null
            : HarnessPersistenceValue::nonNegativeInt(
                $expectedFenceGeneration,
                'expected_fence_generation'
            );
    }
}
