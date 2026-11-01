<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/HarnessControlPlane.php';

interface LegacyJobShadowRecordReader
{
    public function load(string $legacyType, int $legacyId): ?LegacyJobShadowRecord;
}

final readonly class LegacyJobShadowRecord
{
    /** @param array<string,mixed> $coordinates */
    public function __construct(
        public string $legacyType,
        public int $legacyId,
        public string $sourceUid,
        public int $userId,
        public int $novelId,
        public ?int $chapterId,
        public string $state,
        public int $progress,
        public int $attempt,
        public string $createdAt,
        public array $coordinates
    ) {
        if (!in_array($legacyType, LegacyJobShadowMapper::LEGACY_TYPES, true)) {
            throw new InvalidArgumentException('Unknown legacy Job shadow type.');
        }
        HarnessPersistenceValue::positiveInt($legacyId, 'legacy_shadow.id');
        HarnessPersistenceValue::asciiString($sourceUid, 'legacy_shadow.source_uid', 1, 64);
        HarnessPersistenceValue::positiveInt($userId, 'legacy_shadow.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'legacy_shadow.novel_id');
        if ($chapterId !== null) {
            HarnessPersistenceValue::positiveInt($chapterId, 'legacy_shadow.chapter_id');
        }
        HarnessPersistenceValue::identifier($state, 'legacy_shadow.state', 32);
        HarnessPersistenceValue::boundedInt($progress, 'legacy_shadow.progress', 0, 100);
        HarnessPersistenceValue::nonNegativeInt($attempt, 'legacy_shadow.attempt');
        HarnessPersistenceValue::optionalMysqlDate($createdAt, 'legacy_shadow.created_at')
            ?? throw new InvalidArgumentException('Legacy shadow record needs created_at.');
        HarnessSensitiveDataGuard::assertSafeStructure($coordinates, 'legacy_shadow.coordinates');
    }

    public function terminalClass(): string
    {
        return match ($this->legacyType) {
            LegacyJobShadowMapper::WRITING => match ($this->state) {
                'done' => 'succeeded',
                'failed' => 'failed',
                'canceled' => 'canceled',
                default => 'active',
            },
            LegacyJobShadowMapper::POSTPROCESS => match ($this->state) {
                'done' => 'succeeded',
                'failed' => 'failed',
                default => 'active',
            },
            LegacyJobShadowMapper::DRAMA => match ($this->state) {
                'done' => 'succeeded',
                'failed' => 'failed',
                'canceled' => 'canceled',
                default => 'active',
            },
        };
    }

    public function coordinateHash(): string
    {
        return hash('sha256', HarnessPersistenceValue::canonicalJson([
            'source_uid' => $this->sourceUid,
            'user_id' => $this->userId,
            'novel_id' => $this->novelId,
            'chapter_id' => $this->chapterId,
            'coordinates' => $this->coordinates,
        ]));
    }
}

/**
 * Additive M64 migration bridge. Legacy queues remain the sole authority;
 * these Jobs are isolated mirrors and can never dispatch legacy work.
 */
final class LegacyJobShadowMapper implements LegacyJobShadowRecordReader
{
    public const QUEUE = 'legacy-shadow';
    public const WRITING = 'writing_task';
    public const POSTPROCESS = 'postprocess_job';
    public const DRAMA = 'drama_task';
    public const LEGACY_TYPES = [self::WRITING, self::POSTPROCESS, self::DRAMA];

    private HarnessPersistenceDriver $db;

    public function __construct(
        private readonly HarnessJobEnqueuer $jobs,
        PDO|HarnessPersistenceDriver $connection
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function mapById(string $legacyType, int $legacyId): ?HarnessJob
    {
        $record = $this->load($legacyType, $legacyId);
        return $record === null ? null : $this->map($record);
    }

    public function map(LegacyJobShadowRecord $record): HarnessJob
    {
        $identity = $record->legacyType . ':' . $record->legacyId;
        $payload = [
            'schema' => 'harness.legacy_job_shadow.v1',
            'legacy_type' => $record->legacyType,
            'legacy_id' => $record->legacyId,
            'source_uid' => $record->sourceUid,
            'state_at_mapping' => $record->state,
            'progress_at_mapping' => $record->progress,
            'attempt_at_mapping' => $record->attempt,
            'coordinates' => $record->coordinates,
            'source_hash' => $record->coordinateHash(),
        ];
        return $this->jobs->enqueue(new HarnessJobDraft(
            substr(hash('sha256', 'legacy-job-shadow:v1:' . $identity), 0, 32),
            null,
            null,
            self::QUEUE,
            'legacy.' . str_replace('_', '.', $record->legacyType),
            $record->userId,
            $record->novelId,
            $record->chapterId,
            'legacy-shadow:v1:' . $identity,
            $payload,
            -100,
            1000,
            new DateTimeImmutable($record->createdAt, new DateTimeZone('UTC')),
            $record->legacyType,
            $record->legacyId
        ));
    }

    public function load(string $legacyType, int $legacyId): ?LegacyJobShadowRecord
    {
        if (!in_array($legacyType, self::LEGACY_TYPES, true)) {
            throw new InvalidArgumentException('Unknown legacy Job shadow type.');
        }
        $legacyId = HarnessPersistenceValue::positiveInt($legacyId, 'legacy_shadow.id');
        $row = match ($legacyType) {
            self::WRITING => $this->db->fetchOne(
                'SELECT wt.id,wt.task_id AS source_uid,wt.user_id,wt.novel_id,'
                . ' wt.chapter_id,wt.state,wt.progress,wt.attempt,wt.created_at,'
                . ' wt.policy_snapshot_hash,wt.chapter_revision_hash'
                . ' FROM writing_tasks wt WHERE wt.id=?',
                [$legacyId]
            ),
            self::POSTPROCESS => $this->db->fetchOne(
                'SELECT pj.id,pj.job_uid AS source_uid,n.user_id,pj.novel_id,'
                . ' pj.chapter_id,pj.state,pj.attempt,pj.created_at,pj.revision_hash,'
                . ' pj.stage,pj.policy_snapshot_hash,pj.accepted_candidate_uid'
                . ' FROM postprocess_jobs pj INNER JOIN novels n ON n.id=pj.novel_id'
                . ' WHERE pj.id=?',
                [$legacyId]
            ),
            self::DRAMA => $this->db->fetchOne(
                'SELECT dt.id,CAST(dt.id AS CHAR) AS source_uid,dp.user_id,dp.novel_id,'
                . ' NULL AS chapter_id,dt.status AS state,dt.progress,dt.attempts AS attempt,'
                . ' dt.created_at,dt.project_id,dt.episode_id,dt.type,dt.ref_id'
                . ' FROM drama_tasks dt INNER JOIN drama_projects dp ON dp.id=dt.project_id'
                . ' WHERE dt.id=?',
                [$legacyId]
            ),
        };
        if ($row === null) {
            return null;
        }
        $coordinates = match ($legacyType) {
            self::WRITING => [
                'policy_snapshot_hash' => $row['policy_snapshot_hash'] ?? null,
                'chapter_revision_hash' => $row['chapter_revision_hash'] ?? null,
            ],
            self::POSTPROCESS => [
                'revision_hash' => $row['revision_hash'] ?? null,
                'stage' => $row['stage'] ?? null,
                'policy_snapshot_hash' => $row['policy_snapshot_hash'] ?? null,
                'accepted_candidate_uid' => $row['accepted_candidate_uid'] ?? null,
            ],
            self::DRAMA => [
                'project_id' => (int)($row['project_id'] ?? 0),
                'episode_id' => $row['episode_id'] === null ? null : (int)$row['episode_id'],
                'type' => $row['type'] ?? null,
                'ref_id' => (int)($row['ref_id'] ?? 0),
            ],
        };
        return new LegacyJobShadowRecord(
            $legacyType,
            $legacyId,
            (string)($row['source_uid'] ?? ''),
            (int)($row['user_id'] ?? 0),
            (int)($row['novel_id'] ?? 0),
            $row['chapter_id'] === null ? null : (int)$row['chapter_id'],
            (string)($row['state'] ?? ''),
            (int)($row['progress'] ?? 0),
            (int)($row['attempt'] ?? 0),
            (string)($row['created_at'] ?? ''),
            $coordinates
        );
    }

    public function backfillNext(string $legacyType): ?HarnessJob
    {
        $table = match ($legacyType) {
            self::WRITING => 'writing_tasks',
            self::POSTPROCESS => 'postprocess_jobs',
            self::DRAMA => 'drama_tasks',
            default => throw new InvalidArgumentException('Unknown legacy Job shadow type.'),
        };
        $row = $this->db->fetchOne(
            "SELECT legacy.id FROM {$table} legacy"
            . ' LEFT JOIN jobs j ON j.legacy_type=? AND j.legacy_id=legacy.id'
            . ' WHERE j.id IS NULL ORDER BY legacy.id LIMIT 1',
            [$legacyType]
        );
        return $row === null
            ? null
            : $this->mapById(
                $legacyType,
                HarnessPersistenceValue::positiveInt($row['id'] ?? null, 'legacy_shadow.id')
            );
    }
}

final class LegacyJobShadowBridge
{
    public static function tryMap(string $legacyType, int $legacyId): void
    {
        try {
            $pdo = DB::getPdo();
            if ($pdo->inTransaction()) {
                return;
            }
            $jobs = new HarnessJobRepository($pdo);
            $mapper = new LegacyJobShadowMapper($jobs, $pdo);
            $record = $mapper->load($legacyType, $legacyId);
            if ($record === null) {
                return;
            }
            $controls = HarnessControlPlane::captureForNovel($record->novelId);
            if (!HarnessControlPlane::allowsNewHarnessRun($controls['harness_mode'])) {
                return;
            }
            $mapper->map($record);
        } catch (Throwable $exception) {
            error_log('Legacy Job shadow mapping skipped (' . get_class($exception) . ').');
        }
    }

    private function __construct()
    {
    }
}

final class LegacyJobShadowWorker
{
    public function __construct(
        private readonly HarnessJobQueue $jobs,
        private readonly LegacyJobShadowRecordReader $records,
        private readonly ?LegacyJobShadowMapper $backfill = null
    ) {
    }

    /** @return array<string,mixed>|null */
    public function runNext(string $owner, int $leaseSeconds = 120): ?array
    {
        foreach (LegacyJobShadowMapper::LEGACY_TYPES as $type) {
            $this->backfill?->backfillNext($type);
        }
        $lease = $this->jobs->claim(LegacyJobShadowMapper::QUEUE, $owner, $leaseSeconds);
        if ($lease === null) {
            return null;
        }
        $payload = $lease->job->payload();
        HarnessPersistenceValue::requireFields($payload, [
            'schema', 'legacy_type', 'legacy_id', 'source_uid',
            'state_at_mapping', 'progress_at_mapping', 'attempt_at_mapping',
            'coordinates', 'source_hash',
        ], 'legacy_shadow.payload');
        $type = (string)$payload['legacy_type'];
        $id = HarnessPersistenceValue::positiveInt($payload['legacy_id'], 'legacy_shadow.id');
        if (($payload['schema'] ?? null) !== 'harness.legacy_job_shadow.v1'
            || !in_array($type, LegacyJobShadowMapper::LEGACY_TYPES, true)
            || $lease->job->legacyType !== $type
            || $lease->job->legacyId !== $id) {
            return $this->result($this->jobs->fail(
                $lease,
                'legacy_shadow_mapping_invalid',
                'Legacy shadow Job coordinates are invalid.'
            ));
        }
        if ($this->jobs->cancellationRequested($lease)) {
            return $this->result($this->jobs->cancelLease($lease));
        }
        $record = $this->records->load($type, $id);
        if ($record === null) {
            return $this->result($this->jobs->fail(
                $lease,
                'legacy_shadow_source_missing',
                'Legacy shadow source no longer exists.'
            ));
        }
        if ($record->userId !== $lease->job->userId
            || $record->novelId !== $lease->job->novelId
            || $record->chapterId !== $lease->job->chapterId
            || !hash_equals($record->sourceUid, (string)$payload['source_uid'])
            || !hash_equals(
                $record->coordinateHash(),
                HarnessPersistenceValue::hash($payload['source_hash'], 'legacy_shadow.source_hash')
            )) {
            return $this->result($this->jobs->fail(
                $lease,
                'legacy_shadow_scope_changed',
                'Legacy shadow source ownership changed.'
            ));
        }
        $result = [
            'schema' => 'harness.legacy_job_shadow_result.v1',
            'legacy_type' => $type,
            'legacy_id' => $id,
            'source_state' => $record->state,
            'source_progress' => $record->progress,
            'source_attempt' => $record->attempt,
        ];
        $job = match ($record->terminalClass()) {
            'succeeded' => $this->jobs->succeed($lease, $result, true),
            'failed' => $this->jobs->fail(
                $lease,
                'legacy_source_failed',
                'The authoritative legacy job failed.'
            ),
            'canceled' => $this->jobs->cancelLease($lease),
            default => $this->jobs->retryAfter(
                $lease,
                300,
                'legacy_source_active',
                'The authoritative legacy job is still active.'
            ),
        };
        return $this->result($job);
    }

    /** @return array<string,mixed> */
    private function result(HarnessJob $job): array
    {
        return [
            'job_id' => $job->jobUid,
            'status' => $job->state->value,
            'legacy_type' => $job->legacyType,
            'legacy_id' => $job->legacyId,
            'attempt' => $job->attempt,
        ];
    }
}
