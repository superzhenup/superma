<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RunBudget.php';

final readonly class RunRequest
{
    /** @var array<string,mixed> */
    public array $flagSnapshot;
    /** @var array<string,mixed> */
    public array $goal;

    /**
     * @param array<string,mixed> $flagSnapshot
     * @param array<string,mixed> $goal
     */
    public function __construct(
        public int $runId,
        public string $runUid,
        public int $userId,
        public int $novelId,
        public ?int $chapterId,
        public string $queue,
        public string $runType,
        public string $workflowName,
        public string $workflowVersion,
        public string $authorityMode,
        public string $idempotencyKey,
        public string $inputHash,
        array $flagSnapshot,
        array $goal,
        public ?string $deadlineAt,
        public RunBudget $budget
    ) {
        HarnessPersistenceValue::positiveInt($runId, 'runtime.run_id');
        HarnessPersistenceValue::uid($runUid, 'runtime.run_uid');
        HarnessPersistenceValue::positiveInt($userId, 'runtime.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'runtime.novel_id');
        HarnessPersistenceValue::optionalPositiveInt($chapterId, 'runtime.chapter_id');
        HarnessPersistenceValue::identifier($queue, 'runtime.queue', 32);
        HarnessPersistenceValue::identifier($runType, 'runtime.run_type', 64);
        HarnessPersistenceValue::identifier($workflowName, 'runtime.workflow_name', 64);
        HarnessPersistenceValue::identifier($workflowVersion, 'runtime.workflow_version', 32);
        if (!in_array($authorityMode, ['shadow', 'authority'], true)) {
            throw new InvalidArgumentException('Runtime authority mode must be shadow or authority.');
        }
        HarnessPersistenceValue::asciiString(
            $idempotencyKey,
            'runtime.idempotency_key',
            1,
            191
        );
        HarnessPersistenceValue::hash($inputHash, 'runtime.input_hash');
        HarnessPersistenceValue::optionalMysqlDate($deadlineAt, 'runtime.deadline_at');

        $this->flagSnapshot = RuntimeData::normalizeArray(
            $flagSnapshot,
            'runtime.flag_snapshot'
        );
        $this->goal = RuntimeData::normalizeArray($goal, 'runtime.goal');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'run_uid' => $this->runUid,
            'user_id' => $this->userId,
            'novel_id' => $this->novelId,
            'chapter_id' => $this->chapterId,
            'queue' => $this->queue,
            'run_type' => $this->runType,
            'workflow_name' => $this->workflowName,
            'workflow_version' => $this->workflowVersion,
            'authority_mode' => $this->authorityMode,
            'idempotency_key' => $this->idempotencyKey,
            'input_hash' => $this->inputHash,
            'flag_snapshot' => $this->flagSnapshot,
            'goal' => $this->goal,
            'deadline_at' => $this->deadlineAt,
            'budget' => $this->budget->toArray(),
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $required = [
            'run_id', 'run_uid', 'user_id', 'novel_id', 'chapter_id', 'queue',
            'run_type', 'workflow_name', 'workflow_version', 'authority_mode',
            'idempotency_key', 'input_hash', 'flag_snapshot', 'goal',
            'deadline_at', 'budget',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $value)) {
                throw new InvalidArgumentException('Run request is missing a required field.');
            }
        }
        if (!is_array($value['flag_snapshot'])
            || !is_array($value['goal'])
            || !is_array($value['budget'])) {
            throw new InvalidArgumentException('Run request contains a malformed structured field.');
        }

        return new self(
            HarnessPersistenceValue::positiveInt($value['run_id'], 'runtime.run_id'),
            HarnessPersistenceValue::uid($value['run_uid'], 'runtime.run_uid'),
            HarnessPersistenceValue::positiveInt($value['user_id'], 'runtime.user_id'),
            HarnessPersistenceValue::positiveInt($value['novel_id'], 'runtime.novel_id'),
            HarnessPersistenceValue::optionalPositiveInt($value['chapter_id'], 'runtime.chapter_id'),
            HarnessPersistenceValue::identifier($value['queue'], 'runtime.queue', 32),
            HarnessPersistenceValue::identifier($value['run_type'], 'runtime.run_type', 64),
            HarnessPersistenceValue::identifier(
                $value['workflow_name'],
                'runtime.workflow_name',
                64
            ),
            HarnessPersistenceValue::identifier(
                $value['workflow_version'],
                'runtime.workflow_version',
                32
            ),
            HarnessPersistenceValue::identifier(
                $value['authority_mode'],
                'runtime.authority_mode',
                16
            ),
            HarnessPersistenceValue::asciiString(
                $value['idempotency_key'],
                'runtime.idempotency_key',
                1,
                191
            ),
            HarnessPersistenceValue::hash($value['input_hash'], 'runtime.input_hash'),
            $value['flag_snapshot'],
            $value['goal'],
            HarnessPersistenceValue::optionalMysqlDate(
                $value['deadline_at'],
                'runtime.deadline_at'
            ),
            RunBudget::fromArray($value['budget'])
        );
    }

    public function identityHash(): string
    {
        return RuntimeData::hashArray($this->toArray(), 'runtime.request');
    }
}
