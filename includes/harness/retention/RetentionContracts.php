<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';
require_once dirname(__DIR__) . '/events/EventStreamIntegrityValidator.php';

final readonly class RunRetentionCandidate
{
    /** @var array<string,mixed> */
    public array $run;
    /** @var list<array<string,mixed>> */
    public array $events;
    /** @var list<array<string,mixed>> */
    public array $artifacts;
    public string $snapshotHash;

    /**
     * @param array<string,mixed> $run
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $artifacts
     */
    public function __construct(
        public int $runId,
        public string $runUid,
        public string $completedAt,
        array $run,
        array $events,
        array $artifacts,
        public int $nextEventNo
    ) {
        HarnessPersistenceValue::positiveInt($runId, 'retention.run_id');
        HarnessPersistenceValue::uid($runUid, 'retention.run_uid');
        HarnessPersistenceValue::positiveInt($nextEventNo, 'retention.next_event_no');
        HarnessPersistenceValue::optionalMysqlDate($completedAt, 'retention.completed_at')
            ?? throw new InvalidArgumentException('Retention candidate requires completion time.');
        HarnessSensitiveDataGuard::assertSafeStructure($run, 'retention.run');
        HarnessSensitiveDataGuard::assertSafeStructure($events, 'retention.events');
        HarnessSensitiveDataGuard::assertSafeStructure($artifacts, 'retention.artifacts');
        $this->run = $run;
        $this->events = array_values($events);
        $this->artifacts = array_values($artifacts);
        $this->snapshotHash = hash('sha256', HarnessPersistenceValue::canonicalJson(
            $this->bundle()
        ));
    }

    /** @return array<string,mixed> */
    public function bundle(): array
    {
        return [
            'schema' => 'harness.retention.run.v1',
            'run_id' => $this->runId,
            'run_uid' => $this->runUid,
            'completed_at' => $this->completedAt,
            'run' => $this->run,
            'events' => $this->events,
            'artifacts' => $this->artifacts,
        ];
    }
}

final readonly class RunRetentionArchive
{
    public function __construct(
        public string $archiveUid,
        public int $runId,
        public string $snapshotHash,
        public string $contentHash,
        public int $sizeBytes,
        public int $eventCount,
        public int $artifactCount,
        public string $storageUri
    ) {
        HarnessPersistenceValue::uid($archiveUid, 'retention.archive_uid');
        HarnessPersistenceValue::positiveInt($runId, 'retention.archive_run_id');
        HarnessPersistenceValue::hash($snapshotHash, 'retention.snapshot_hash');
        HarnessPersistenceValue::hash($contentHash, 'retention.content_hash');
        HarnessPersistenceValue::nonNegativeInt($sizeBytes, 'retention.size_bytes');
        HarnessPersistenceValue::nonNegativeInt($eventCount, 'retention.event_count');
        HarnessPersistenceValue::nonNegativeInt($artifactCount, 'retention.artifact_count');
        HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
    }
}

interface RetentionArchiveSink
{
    public function putIdempotent(string $archiveUid, string $bytes, string $contentHash): string;
    public function verify(string $storageUri, string $contentHash, int $sizeBytes): bool;
}

interface RunRetentionRepository
{
    /** @return list<int> */
    public function eligibleRunIds(DateTimeImmutable $cutoff, int $limit): array;
    public function loadCandidate(int $runId, DateTimeImmutable $cutoff): ?RunRetentionCandidate;

    /**
     * Atomically records the archive then purges Events and tiers inline
     * Artifact payloads. Must CAS candidate.snapshotHash before mutation.
     */
    public function commitArchive(
        RunRetentionCandidate $candidate,
        RunRetentionArchive $archive
    ): bool;
}
