<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RunRequest.php';
require_once __DIR__ . '/StageResult.php';

final readonly class RunContext
{
    /** @var array<string,mixed> */
    public array $attributes;
    /** @var array<string,array<string,mixed>> */
    public array $artifacts;
    /** @var array<string,array<string,mixed>> */
    public array $completedStages;
    /** @var array<string,array<string,mixed>> */
    public array $commitReceipts;

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,array<string,mixed>> $artifacts
     * @param array<string,array<string,mixed>> $completedStages
     * @param array<string,array<string,mixed>> $commitReceipts
     */
    public function __construct(
        public RunRequest $request,
        public RunBudget $budget,
        array $attributes = [],
        array $artifacts = [],
        array $completedStages = [],
        array $commitReceipts = []
    ) {
        $this->attributes = RuntimeData::normalizeArray($attributes, 'runtime.context.attributes');
        $this->artifacts = self::normalizeObjectMap(
            $artifacts,
            'runtime.context.artifacts'
        );
        $this->completedStages = self::normalizeObjectMap(
            $completedStages,
            'runtime.context.completed_stages'
        );
        $this->commitReceipts = self::normalizeObjectMap(
            $commitReceipts,
            'runtime.context.commit_receipts'
        );

        if ($budget->tokenLimit !== $request->budget->tokenLimit) {
            throw new InvalidArgumentException(
                'Run context cannot replace the immutable budget limits from its request.'
            );
        }
    }

    public static function initial(RunRequest $request): self
    {
        return new self($request, $request->budget);
    }

    public function assertForRequest(RunRequest $request): void
    {
        if (!hash_equals($this->request->identityHash(), $request->identityHash())) {
            throw new WorkflowCheckpointException(
                'Checkpoint Run request does not match the active immutable request.'
            );
        }
    }

    public function withStageResult(
        string $stageKey,
        int $ordinal,
        string $inputHash,
        StageResult $result,
        bool $recovered = false
    ): self {
        $stageKey = HarnessPersistenceValue::identifier(
            $stageKey,
            'runtime.stage_key',
            64
        );
        $ordinal = HarnessPersistenceValue::boundedInt(
            $ordinal,
            'runtime.stage_ordinal',
            0,
            65535
        );
        $inputHash = HarnessPersistenceValue::hash($inputHash, 'runtime.stage_input_hash');
        if (!$result->disposition->checkpointsOutput()) {
            throw new InvalidArgumentException(
                'Only completed Stage results may advance Run context.'
            );
        }
        if (isset($this->completedStages[$stageKey])) {
            throw new WorkflowCheckpointException(
                'A completed Stage cannot be applied twice to the same Run context.'
            );
        }

        $attributes = array_replace($this->attributes, $result->attributePatch);
        $artifacts = array_replace($this->artifacts, $result->artifactPatch);
        $completed = $this->completedStages;
        $completed[$stageKey] = [
            'ordinal' => $ordinal,
            'input_hash' => $inputHash,
            'disposition' => $result->disposition->value,
            'recovered' => $recovered,
            'summary' => $result->checkpointSummary(),
        ];

        return new self(
            $this->request,
            $this->budget->consume(
                $result->inputTokens,
                $result->outputTokens,
                $result->cost
            ),
            $attributes,
            $artifacts,
            $completed,
            $this->commitReceipts
        );
    }

    /** @param array<string,mixed> $receipt */
    public function withCommitReceipt(string $stageKey, array $receipt): self
    {
        $stageKey = HarnessPersistenceValue::identifier(
            $stageKey,
            'runtime.commit_stage_key',
            64
        );
        $receipts = $this->commitReceipts;
        $normalized = RuntimeData::normalizeArray(
            $receipt,
            'runtime.context.commit_receipt'
        );
        if (isset($receipts[$stageKey])
            && !hash_equals(
                RuntimeData::hashArray($receipts[$stageKey], 'runtime.existing_commit_receipt'),
                RuntimeData::hashArray($normalized, 'runtime.new_commit_receipt')
            )) {
            throw new WorkflowCheckpointException(
                'A Stage commit receipt changed across checkpoint recovery.'
            );
        }
        $receipts[$stageKey] = $normalized;

        return new self(
            $this->request,
            $this->budget,
            $this->attributes,
            $this->artifacts,
            $this->completedStages,
            $receipts
        );
    }

    public function hasCompletedStage(string $stageKey): bool
    {
        return isset($this->completedStages[$stageKey]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 'harness.runtime.context.v1',
            'request' => $this->request->toArray(),
            'budget' => $this->budget->toArray(),
            'attributes' => $this->attributes,
            'artifacts' => $this->artifacts,
            'completed_stages' => $this->completedStages,
            'commit_receipts' => $this->commitReceipts,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        foreach ([
            'schema', 'request', 'budget', 'attributes', 'artifacts',
            'completed_stages', 'commit_receipts',
        ] as $field) {
            if (!array_key_exists($field, $value)) {
                throw new WorkflowCheckpointException(
                    'Runtime checkpoint context is missing a required field.'
                );
            }
        }
        if ($value['schema'] !== 'harness.runtime.context.v1'
            || !is_array($value['request'])
            || !is_array($value['budget'])
            || !is_array($value['attributes'])
            || !is_array($value['artifacts'])
            || !is_array($value['completed_stages'])
            || !is_array($value['commit_receipts'])) {
            throw new WorkflowCheckpointException('Runtime checkpoint context is malformed.');
        }

        try {
            return new self(
                RunRequest::fromArray($value['request']),
                RunBudget::fromArray($value['budget']),
                $value['attributes'],
                $value['artifacts'],
                $value['completed_stages'],
                $value['commit_receipts']
            );
        } catch (WorkflowRuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WorkflowCheckpointException(
                'Runtime checkpoint context failed validation.',
                0,
                $exception
            );
        }
    }

    public function hash(): string
    {
        return RuntimeData::hashArray($this->toArray(), 'runtime.context');
    }

    /**
     * @param array<string,array<string,mixed>> $value
     * @return array<string,array<string,mixed>>
     */
    private static function normalizeObjectMap(array $value, string $path): array
    {
        $normalized = RuntimeData::normalizeArray($value, $path);
        foreach ($normalized as $key => $item) {
            if (!is_string($key) || !is_array($item)) {
                throw new InvalidArgumentException(
                    'Runtime context maps must use string keys and object values.'
                );
            }
        }
        /** @var array<string,array<string,mixed>> $normalized */
        return $normalized;
    }
}
