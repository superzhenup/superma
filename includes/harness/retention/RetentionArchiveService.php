<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionContracts.php';

final readonly class RetentionBatchResult
{
    /** @param list<string> $archiveUids @param list<int> $staleRunIds */
    public function __construct(
        public int $scanned,
        public int $archived,
        public array $archiveUids,
        public array $staleRunIds
    ) {
    }
}

final class RetentionArchiveService
{
    public function __construct(
        private readonly RunRetentionRepository $repository,
        private readonly RetentionArchiveSink $sink
    ) {
    }

    public function archiveEligible(
        DateTimeImmutable $now,
        int $retentionDays,
        int $limit = 100
    ): RetentionBatchResult {
        HarnessPersistenceValue::boundedInt($retentionDays, 'retention.days', 1, 3650);
        HarnessPersistenceValue::boundedInt($limit, 'retention.limit', 1, 1000);
        $cutoff = $now->setTimezone(new DateTimeZone('UTC'))
            ->modify("-{$retentionDays} days");
        $runIds = $this->repository->eligibleRunIds($cutoff, $limit);
        $archives = [];
        $stale = [];
        foreach ($runIds as $runId) {
            $runId = HarnessPersistenceValue::positiveInt($runId, 'retention.eligible_run_id');
            $candidate = $this->repository->loadCandidate($runId, $cutoff);
            if (!$candidate instanceof RunRetentionCandidate) {
                $stale[] = $runId;
                continue;
            }
            EventStreamIntegrityValidator::validate(
                $candidate->runId,
                $candidate->nextEventNo,
                $candidate->events,
                static fn (mixed $event, int $index): int => $candidate->runId,
                static fn (mixed $event, int $index): mixed => is_array($event)
                    ? ($event['event_no'] ?? null)
                    : null
            );
            $bytes = HarnessPersistenceValue::canonicalJson($candidate->bundle());
            $contentHash = hash('sha256', $bytes);
            $archiveUid = substr(hash('sha256', implode("\0", [
                'retention-archive-v1',
                $candidate->runUid,
                $candidate->snapshotHash,
            ])), 0, 32);
            $uri = $this->sink->putIdempotent($archiveUid, $bytes, $contentHash);
            $archive = new RunRetentionArchive(
                $archiveUid,
                $candidate->runId,
                $candidate->snapshotHash,
                $contentHash,
                strlen($bytes),
                count($candidate->events),
                count($candidate->artifacts),
                $uri
            );
            // Destructive tiering is forbidden until the external bytes pass
            // an independent size/hash verification.
            if (!$this->sink->verify($uri, $contentHash, strlen($bytes))) {
                throw new RuntimeException('Retention archive verification failed.');
            }
            if (!$this->repository->commitArchive($candidate, $archive)) {
                $stale[] = $runId;
                continue;
            }
            $archives[] = $archiveUid;
        }
        sort($archives, SORT_STRING);
        sort($stale, SORT_NUMERIC);
        return new RetentionBatchResult(
            count($runIds),
            count($archives),
            $archives,
            $stale
        );
    }
}
