<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessSensitiveDataGuard.php';
require_once __DIR__ . '/GenerationStateMachine.php';

final readonly class GenerationRunDraft
{
    public string $runUid;
    public int $userId;
    public int $novelId;
    public ?int $chapterId;
    public string $queue;
    public string $runType;
    public string $workflowName;
    public string $workflowVersion;
    public string $authorityMode;
    public ?int $parentRunId;
    public ?int $rootRunId;
    public string $idempotencyKey;
    public ?string $activeLockKey;
    public int $maxAttempts;
    public DateTimeImmutable $availableAt;
    public string $inputHash;
    public ?string $promptManifestHash;
    public ?string $contextSnapshotHash;
    public ?DateTimeImmutable $deadlineAt;
    public int $tokenBudget;
    public string $costBudget;
    public string $flagSnapshotJson;
    public string $goalJson;

    /**
     * @param array<string,mixed> $flagSnapshot
     * @param array<string,mixed> $goal
     */
    public function __construct(
        string $runUid,
        int $userId,
        int $novelId,
        ?int $chapterId,
        string $queue,
        string $runType,
        string $workflowName,
        string $workflowVersion,
        string $authorityMode,
        string $idempotencyKey,
        ?string $activeLockKey,
        string $inputHash,
        array $flagSnapshot,
        array $goal,
        DateTimeImmutable $availableAt,
        int $maxAttempts = 3,
        ?int $parentRunId = null,
        ?int $rootRunId = null,
        ?string $promptManifestHash = null,
        ?string $contextSnapshotHash = null,
        ?DateTimeImmutable $deadlineAt = null,
        int $tokenBudget = 0,
        string $costBudget = '0'
    ) {
        $this->runUid = HarnessPersistenceValue::uid($runUid, 'run_uid');
        $this->userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $this->novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $this->chapterId = HarnessPersistenceValue::optionalPositiveInt($chapterId, 'chapter_id');
        $this->queue = HarnessPersistenceValue::identifier($queue, 'queue', 32);
        $this->runType = HarnessPersistenceValue::identifier($runType, 'run_type', 64);
        $this->workflowName = HarnessPersistenceValue::identifier($workflowName, 'workflow_name', 64);
        $this->workflowVersion = HarnessPersistenceValue::identifier(
            $workflowVersion,
            'workflow_version',
            32
        );
        if (!in_array($authorityMode, ['shadow', 'authority'], true)) {
            throw new InvalidArgumentException('authority_mode must be shadow or authority.');
        }
        $this->authorityMode = $authorityMode;
        $this->parentRunId = HarnessPersistenceValue::optionalPositiveInt($parentRunId, 'parent_run_id');
        $this->rootRunId = HarnessPersistenceValue::optionalPositiveInt($rootRunId, 'root_run_id');
        $this->idempotencyKey = HarnessPersistenceValue::asciiString(
            $idempotencyKey,
            'idempotency_key',
            1,
            191
        );
        $this->activeLockKey = HarnessPersistenceValue::optionalAsciiString(
            $activeLockKey,
            'active_lock_key',
            191
        );
        $this->maxAttempts = HarnessPersistenceValue::boundedInt(
            $maxAttempts,
            'max_attempts',
            1,
            100
        );
        $this->availableAt = $availableAt;
        $this->inputHash = HarnessPersistenceValue::hash($inputHash, 'input_hash');
        $this->promptManifestHash = HarnessPersistenceValue::optionalHash(
            $promptManifestHash,
            'prompt_manifest_hash'
        );
        $this->contextSnapshotHash = HarnessPersistenceValue::optionalHash(
            $contextSnapshotHash,
            'context_snapshot_hash'
        );
        $this->deadlineAt = $deadlineAt;
        $this->tokenBudget = HarnessPersistenceValue::nonNegativeInt($tokenBudget, 'token_budget');
        $this->costBudget = '0'; // Legacy storage placeholder; no monetary budget.
        HarnessSensitiveDataGuard::assertSafeStructure($flagSnapshot, 'run.flag_snapshot');
        HarnessSensitiveDataGuard::assertSafeStructure($goal, 'run.goal');
        $this->flagSnapshotJson = HarnessPersistenceValue::canonicalMysqlJson($flagSnapshot);
        $this->goalJson = HarnessPersistenceValue::canonicalMysqlJson($goal);
        HarnessSensitiveDataGuard::assertSafeText($this->flagSnapshotJson, 'run.flag_snapshot');
        HarnessSensitiveDataGuard::assertSafeText($this->goalJson, 'run.goal');
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        HarnessPersistenceValue::requireFields($row, [
            'run_uid', 'user_id', 'novel_id', 'chapter_id', 'queue', 'run_type',
            'workflow_name', 'workflow_version', 'authority_mode', 'parent_run_id',
            'root_run_id', 'idempotency_key', 'active_lock_key', 'max_attempts',
            'available_at', 'deadline_at', 'input_hash',
            'prompt_manifest_hash', 'context_snapshot_hash', 'token_budget',
            'cost_budget', 'flag_snapshot_json', 'goal_json',
        ], 'generation_run');

        $storedFlagSnapshot = HarnessPersistenceValue::decodeJsonArray(
            $row['flag_snapshot_json'],
            'flag_snapshot_json'
        );
        $storedGoal = HarnessPersistenceValue::decodeJsonArray($row['goal_json'], 'goal_json');
        HarnessSensitiveDataGuard::assertSafeStructure($storedFlagSnapshot, 'run.flag_snapshot');
        HarnessSensitiveDataGuard::assertSafeStructure($storedGoal, 'run.goal');
        $storedFlagSnapshotJson = HarnessPersistenceValue::canonicalMysqlJson(
            $storedFlagSnapshot
        );
        $storedGoalJson = HarnessPersistenceValue::canonicalMysqlJson($storedGoal);
        HarnessSensitiveDataGuard::assertSafeText($storedFlagSnapshotJson, 'run.flag_snapshot');
        HarnessSensitiveDataGuard::assertSafeText($storedGoalJson, 'run.goal');

        $matches = (string)$row['run_uid'] === $this->runUid
            && (int)$row['user_id'] === $this->userId
            && (int)$row['novel_id'] === $this->novelId
            && HarnessPersistenceValue::optionalPositiveInt($row['chapter_id'], 'chapter_id') === $this->chapterId
            && (string)$row['queue'] === $this->queue
            && (string)$row['run_type'] === $this->runType
            && (string)$row['workflow_name'] === $this->workflowName
            && (string)$row['workflow_version'] === $this->workflowVersion
            && (string)$row['authority_mode'] === $this->authorityMode
            && HarnessPersistenceValue::optionalPositiveInt($row['parent_run_id'], 'parent_run_id') === $this->parentRunId
            && HarnessPersistenceValue::optionalPositiveInt($row['root_run_id'], 'root_run_id') === $this->rootRunId
            && (string)$row['idempotency_key'] === $this->idempotencyKey
            && HarnessPersistenceValue::optionalAsciiString(
                $row['active_lock_key'],
                'active_lock_key',
                191
            ) === $this->activeLockKey
            && (int)$row['max_attempts'] === $this->maxAttempts
            && HarnessPersistenceValue::optionalMysqlDate($row['available_at'], 'available_at')
                === HarnessPersistenceValue::mysqlDate($this->availableAt)
            && HarnessPersistenceValue::optionalMysqlDate($row['deadline_at'], 'deadline_at')
                === ($this->deadlineAt === null
                    ? null
                    : HarnessPersistenceValue::mysqlDate($this->deadlineAt))
            && (string)$row['input_hash'] === $this->inputHash
            && ($row['prompt_manifest_hash'] ?? null) === $this->promptManifestHash
            && ($row['context_snapshot_hash'] ?? null) === $this->contextSnapshotHash
            && (int)$row['token_budget'] === $this->tokenBudget
            && $storedFlagSnapshotJson === $this->flagSnapshotJson
            && $storedGoalJson === $this->goalJson;

        if (!$matches) {
            throw new HarnessIdempotencyConflictException(
                'Run idempotency key resolved to a different immutable request.'
            );
        }
    }
}

final readonly class GenerationRun
{
    public function __construct(
        public int $id,
        public string $runUid,
        public int $userId,
        public int $novelId,
        public ?int $chapterId,
        public string $queue,
        public string $runType,
        public string $workflowName,
        public string $workflowVersion,
        public string $authorityMode,
        public GenerationRunState $state,
        public int $progress,
        public int $attempt,
        public int $maxAttempts,
        public int $nextEventNo,
        public bool $cancelRequested,
        public string $idempotencyKey,
        public ?string $activeLockKey,
        public ?string $leaseOwner,
        public ?string $leaseToken,
        public int $fenceGeneration,
        public ?string $leaseExpiresAt,
        public string $availableAt,
        public ?string $deadlineAt,
        public string $inputHash,
        public ?string $contextSnapshotHash,
        public string $goalJson,
        public string $createdAt
    ) {
        if (!in_array($authorityMode, ['shadow', 'authority'], true)) {
            throw new InvalidArgumentException('generation Run authority_mode is unknown.');
        }
        if ($state === GenerationRunState::RUNNING) {
            if ($leaseOwner === null || $leaseToken === null || $fenceGeneration < 1 || $leaseExpiresAt === null) {
                throw new InvalidArgumentException('Running generation Run has an incomplete lease fence.');
            }
        } elseif ($leaseOwner !== null || $leaseToken !== null || $leaseExpiresAt !== null) {
            throw new InvalidArgumentException('Non-running generation Run retains lease ownership.');
        }
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'run_uid', 'user_id', 'novel_id', 'chapter_id', 'queue', 'run_type',
            'workflow_name', 'workflow_version', 'authority_mode',
            'state', 'progress', 'attempt', 'max_attempts', 'next_event_no',
            'cancel_requested', 'idempotency_key', 'active_lock_key', 'lease_owner',
            'lease_token', 'fence_generation', 'lease_expires_at', 'available_at',
            'deadline_at', 'input_hash', 'context_snapshot_hash', 'goal_json', 'created_at',
        ], 'generation_run');
        $state = is_string($row['state']) ? GenerationRunState::tryFrom($row['state']) : null;
        if (!$state instanceof GenerationRunState) {
            throw new InvalidArgumentException('generation_run.state is unknown.');
        }

        $owner = HarnessPersistenceValue::optionalString($row['lease_owner'], 'lease_owner', 120);
        $token = $row['lease_token'] === null || $row['lease_token'] === ''
            ? null
            : HarnessPersistenceValue::uid($row['lease_token'], 'lease_token');

        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            HarnessPersistenceValue::uid($row['run_uid'], 'run_uid'),
            HarnessPersistenceValue::positiveInt($row['user_id'], 'user_id'),
            HarnessPersistenceValue::positiveInt($row['novel_id'], 'novel_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['chapter_id'], 'chapter_id'),
            HarnessPersistenceValue::identifier($row['queue'], 'queue', 32),
            HarnessPersistenceValue::identifier($row['run_type'], 'run_type', 64),
            HarnessPersistenceValue::identifier($row['workflow_name'], 'workflow_name', 64),
            HarnessPersistenceValue::identifier($row['workflow_version'], 'workflow_version', 32),
            HarnessPersistenceValue::identifier($row['authority_mode'], 'authority_mode', 16),
            $state,
            HarnessPersistenceValue::boundedInt($row['progress'], 'progress', 0, 100),
            HarnessPersistenceValue::nonNegativeInt($row['attempt'], 'attempt'),
            HarnessPersistenceValue::boundedInt($row['max_attempts'], 'max_attempts', 1, 100),
            HarnessPersistenceValue::positiveInt($row['next_event_no'], 'next_event_no'),
            HarnessPersistenceValue::bool($row['cancel_requested'], 'cancel_requested'),
            HarnessPersistenceValue::asciiString($row['idempotency_key'], 'idempotency_key', 1, 191),
            HarnessPersistenceValue::optionalAsciiString($row['active_lock_key'], 'active_lock_key', 191),
            $owner,
            $token,
            HarnessPersistenceValue::nonNegativeInt($row['fence_generation'], 'fence_generation'),
            HarnessPersistenceValue::optionalMysqlDate($row['lease_expires_at'], 'lease_expires_at'),
            HarnessPersistenceValue::optionalMysqlDate($row['available_at'], 'available_at')
                ?? throw new InvalidArgumentException('available_at is required.'),
            HarnessPersistenceValue::optionalMysqlDate($row['deadline_at'], 'deadline_at'),
            HarnessPersistenceValue::hash($row['input_hash'], 'input_hash'),
            HarnessPersistenceValue::optionalHash(
                $row['context_snapshot_hash'],
                'context_snapshot_hash'
            ),
            HarnessPersistenceValue::canonicalMysqlJson(
                HarnessPersistenceValue::decodeJsonArray($row['goal_json'], 'goal_json')
            ),
            HarnessPersistenceValue::optionalMysqlDate($row['created_at'], 'created_at')
                ?? throw new InvalidArgumentException('created_at is required.')
        );
    }
}

final readonly class GenerationRunLease
{
    public function __construct(
        public GenerationRun $run,
        public string $owner,
        public string $token,
        public int $generation,
        public string $expiresAt
    ) {
        if ($run->state !== GenerationRunState::RUNNING
            || $run->leaseOwner !== $owner
            || $run->leaseToken !== $token
            || $run->fenceGeneration !== $generation
            || $run->leaseExpiresAt !== $expiresAt) {
            throw new InvalidArgumentException('Generation Run lease does not match its durable row.');
        }
    }

    public static function fromRun(GenerationRun $run): self
    {
        if ($run->leaseOwner === null || $run->leaseToken === null || $run->leaseExpiresAt === null) {
            throw new InvalidArgumentException('Generation Run has no complete lease.');
        }
        return new self(
            $run,
            $run->leaseOwner,
            $run->leaseToken,
            $run->fenceGeneration,
            $run->leaseExpiresAt
        );
    }
}

enum GenerationRunClaimKind: string
{
    /** No Run was claimed; targeted claims also finalized nothing. */
    case NONE = 'none';
    /** queued/retry_wait Run entered running for the first time. */
    case CLAIMED = 'claimed';
    /** An expired running Run was reclaimed under a new fence. */
    case RECLAIMED = 'reclaimed';
    /** A max-attempt Run was reclaimed through its Chapter commit ledger. */
    case RECOVERY = 'recovery';
    /** The targeted Run was automatically canceled instead of claimed. */
    case AUTO_CANCELED = 'auto_canceled';
    /** The targeted Run exhausted its attempt budget instead of claiming. */
    case EXHAUSTED = 'exhausted';
}

final readonly class GenerationRunClaimResult
{
    /**
     * The claim kind describes only the Run this call selected or targeted;
     * queue claims that additionally finalize other Runs expose those
     * lifecycle transitions through their facts.
     *
     * @param list<GenerationTransitionFact> $facts
     */
    public function __construct(
        public GenerationRunClaimKind $kind,
        public ?GenerationRunLease $lease,
        public array $facts
    ) {
        if ($this->lease !== null
            && !in_array($this->kind, [
                GenerationRunClaimKind::CLAIMED,
                GenerationRunClaimKind::RECLAIMED,
                GenerationRunClaimKind::RECOVERY,
            ], true)) {
            throw new InvalidArgumentException(
                'Generation Run claim kind disagrees with its acquired lease.'
            );
        }
        if ($this->lease === null
            && in_array($this->kind, [
                GenerationRunClaimKind::CLAIMED,
                GenerationRunClaimKind::RECLAIMED,
                GenerationRunClaimKind::RECOVERY,
            ], true)) {
            throw new InvalidArgumentException(
                'Lease-acquiring Run claim kinds must carry their lease.'
            );
        }
    }

    public function claimed(): bool
    {
        return $this->lease !== null;
    }
}
