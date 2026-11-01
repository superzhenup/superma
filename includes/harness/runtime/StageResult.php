<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RuntimeData.php';

enum StageDisposition: string
{
    case CONTINUE = 'continue';
    case RETRY = 'retry';
    case WAIT_APPROVAL = 'wait_approval';
    case QUARANTINE = 'quarantine';
    case COMPLETE = 'complete';
    case FAILED = 'failed';
    case CANCELED = 'canceled';

    public function checkpointsOutput(): bool
    {
        return in_array($this, [
            self::CONTINUE,
            self::WAIT_APPROVAL,
            self::QUARANTINE,
            self::COMPLETE,
        ], true);
    }
}

final readonly class StageResult
{
    /** @var array<string,mixed> */
    public array $attributePatch;
    /** @var array<string,array<string,mixed>> */
    public array $artifactPatch;
    /** @var array<string,mixed> */
    public array $metadata;
    public int $inputTokens;
    public int $outputTokens;
    public string $cost;
    public ?string $errorCode;

    /**
     * @param array<string,mixed> $attributePatch
     * @param array<string,array<string,mixed>> $artifactPatch
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public StageDisposition $disposition,
        array $attributePatch = [],
        array $artifactPatch = [],
        int $inputTokens = 0,
        int $outputTokens = 0,
        string $cost = '0',
        ?string $errorCode = null,
        array $metadata = []
    ) {
        $this->inputTokens = HarnessPersistenceValue::nonNegativeInt(
            $inputTokens,
            'stage_result.input_tokens'
        );
        $this->outputTokens = HarnessPersistenceValue::nonNegativeInt(
            $outputTokens,
            'stage_result.output_tokens'
        );
        $this->cost = HarnessPersistenceValue::decimal($cost, 'stage_result.cost');
        $this->errorCode = HarnessPersistenceValue::optionalString(
            $errorCode,
            'stage_result.error_code',
            64
        );
        if ($this->errorCode !== null) {
            HarnessPersistenceValue::identifier(
                $this->errorCode,
                'stage_result.error_code',
                64
            );
        }

        $this->attributePatch = RuntimeData::normalizeArray(
            $attributePatch,
            'stage_result.attribute_patch'
        );
        $normalizedArtifacts = RuntimeData::normalizeArray(
            $artifactPatch,
            'stage_result.artifact_patch'
        );
        foreach ($normalizedArtifacts as $logicalKey => $reference) {
            if (!is_string($logicalKey) || !is_array($reference)) {
                throw new InvalidArgumentException(
                    'Stage artifact patches must map logical keys to reference objects.'
                );
            }
        }
        /** @var array<string,array<string,mixed>> $normalizedArtifacts */
        $this->artifactPatch = $normalizedArtifacts;
        $this->metadata = RuntimeData::normalizeArray($metadata, 'stage_result.metadata');

        if (!$this->disposition->checkpointsOutput()
            && ($this->attributePatch !== []
                || $this->artifactPatch !== []
                || $this->inputTokens !== 0
                || $this->outputTokens !== 0
                || $this->cost !== '0')) {
            throw new InvalidArgumentException(
                'Non-checkpoint Stage results cannot mutate durable Run context.'
            );
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,array<string,mixed>> $artifacts
     * @param array<string,mixed> $metadata
     */
    public static function continue(
        array $attributes = [],
        array $artifacts = [],
        int $inputTokens = 0,
        int $outputTokens = 0,
        string $cost = '0',
        array $metadata = []
    ): self {
        return new self(
            StageDisposition::CONTINUE,
            $attributes,
            $artifacts,
            $inputTokens,
            $outputTokens,
            $cost,
            null,
            $metadata
        );
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,array<string,mixed>> $artifacts
     * @param array<string,mixed> $metadata
     */
    public static function complete(
        array $attributes = [],
        array $artifacts = [],
        int $inputTokens = 0,
        int $outputTokens = 0,
        string $cost = '0',
        array $metadata = []
    ): self {
        return new self(
            StageDisposition::COMPLETE,
            $attributes,
            $artifacts,
            $inputTokens,
            $outputTokens,
            $cost,
            null,
            $metadata
        );
    }

    public static function retry(string $errorCode = 'stage_retry_requested'): self
    {
        return new self(StageDisposition::RETRY, errorCode: $errorCode);
    }

    /** @param array<string,mixed> $metadata */
    public static function waitApproval(
        array $metadata = [],
        string $errorCode = 'approval_required'
    ): self {
        return new self(
            StageDisposition::WAIT_APPROVAL,
            errorCode: $errorCode,
            metadata: $metadata
        );
    }

    /** @param array<string,mixed> $metadata */
    public static function quarantine(
        array $metadata = [],
        string $errorCode = 'validation_blocked'
    ): self {
        return new self(
            StageDisposition::QUARANTINE,
            errorCode: $errorCode,
            metadata: $metadata
        );
    }

    public static function failed(string $errorCode = 'stage_failed'): self
    {
        return new self(StageDisposition::FAILED, errorCode: $errorCode);
    }

    public static function canceled(string $errorCode = 'run_canceled'): self
    {
        return new self(StageDisposition::CANCELED, errorCode: $errorCode);
    }

    /** @return array<string,mixed> */
    public function checkpointSummary(): array
    {
        if (!$this->disposition->checkpointsOutput()) {
            throw new LogicException('Only completed Stage results have checkpoint summaries.');
        }
        return [
            'disposition' => $this->disposition->value,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost' => $this->cost,
            'metadata' => $this->metadata,
        ];
    }
}
