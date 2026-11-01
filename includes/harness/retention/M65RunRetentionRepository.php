<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionContracts.php';
require_once dirname(__DIR__) . '/persistence/RunEventIdAllocator.php';

final class M65RunRetentionRepository implements RunRetentionRepository
{
    /**
     * A Retention purge physically deletes live Events, so any side effect
     * that could still append or settle afterwards must block eligibility:
     * outstanding model-budget reservations (late settlement resolves them
     * against the live reserved Event), live post-processing jobs (their
     * budget authority may reserve against this Run), and attempts with
     * unknown or running settlement state (late paid responses).
     */
    private const SIDE_EFFECT_BARRIER_SQL = "AND NOT EXISTS (
                SELECT 1 FROM generation_events e
                WHERE e.run_id=gr.id AND e.event_type='model.budget.reserved'
                  AND NOT EXISTS (
                      SELECT 1 FROM generation_events s
                      WHERE s.run_id=e.run_id
                        AND s.event_key=CONCAT('model-budget:settled:',
                                               SUBSTRING(e.event_key, 23))
                  )
            )
            AND NOT EXISTS (
                SELECT 1 FROM chapter_commit_ledger ccl
                JOIN postprocess_jobs pj ON pj.id=ccl.postprocess_job_id
                WHERE ccl.run_id=gr.id AND pj.state IN ('queued','retry','running')
            )
            AND NOT EXISTS (
                SELECT 1 FROM generation_attempts a
                JOIN generation_steps s ON s.id=a.step_id
                WHERE s.run_id=gr.id
                  AND (a.state='running' OR a.error_code='usage_unknown')
            )
            AND NOT EXISTS (
                SELECT 1 FROM generation_side_effect_operations op
                WHERE op.run_id=gr.id AND op.state IN ('reserved','invoking','unknown')
            )";

    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function eligibleRunIds(DateTimeImmutable $cutoff, int $limit): array
    {
        $limit = HarnessPersistenceValue::boundedInt($limit, 'retention.limit', 1, 1000);
        $rows = $this->db->fetchAll(
            "SELECT gr.id
             FROM generation_runs gr
             LEFT JOIN harness_retention_archives hra ON hra.run_id=gr.id
             WHERE gr.state IN ('succeeded','aborted_after_commit','failed','canceled')
               AND gr.completed_at IS NOT NULL AND gr.completed_at<?
               AND hra.id IS NULL
               " . self::SIDE_EFFECT_BARRIER_SQL . "
             ORDER BY gr.completed_at,gr.id LIMIT {$limit}",
            [HarnessPersistenceValue::mysqlDate($cutoff)]
        );
        return array_map(
            static fn (array $row): int => HarnessPersistenceValue::positiveInt(
                $row['id'] ?? null,
                'retention.eligible_id'
            ),
            $rows
        );
    }

    public function loadCandidate(
        int $runId,
        DateTimeImmutable $cutoff
    ): ?RunRetentionCandidate {
        $runId = HarnessPersistenceValue::positiveInt($runId, 'retention.run_id');
        $row = $this->db->fetchOne(
            "SELECT gr.* FROM generation_runs gr
             LEFT JOIN harness_retention_archives hra ON hra.run_id=gr.id
             WHERE gr.id=?
               AND gr.state IN ('succeeded','aborted_after_commit','failed','canceled')
               AND gr.completed_at IS NOT NULL AND gr.completed_at<?
               AND hra.id IS NULL
               " . self::SIDE_EFFECT_BARRIER_SQL,
            [$runId, HarnessPersistenceValue::mysqlDate($cutoff)]
        );
        return $row === null ? null : $this->candidateFromRun($row);
    }

    public function commitArchive(
        RunRetentionCandidate $candidate,
        RunRetentionArchive $archive
    ): bool {
        if ($candidate->runId !== $archive->runId
            || !hash_equals($candidate->snapshotHash, $archive->snapshotHash)) {
            throw new InvalidArgumentException('Retention archive coordinates do not match.');
        }
        return $this->db->transactional(function () use ($candidate, $archive): bool {
            $run = $this->db->fetchOne(
                "SELECT * FROM generation_runs WHERE id=?
                 AND state IN ('succeeded','aborted_after_commit','failed','canceled')
                 FOR UPDATE",
                [$candidate->runId]
            );
            if ($run === null) {
                return false;
            }
            $existing = $this->db->fetchOne(
                'SELECT * FROM harness_retention_archives WHERE run_id=? FOR UPDATE',
                [$candidate->runId]
            );
            if ($existing !== null) {
                $same = ($existing['archive_uid'] ?? null) === $archive->archiveUid
                    && ($existing['snapshot_hash'] ?? null) === $archive->snapshotHash
                    && ($existing['content_hash'] ?? null) === $archive->contentHash
                    && (int)($existing['size_bytes'] ?? -1) === $archive->sizeBytes
                    && ($existing['storage_uri'] ?? null) === $archive->storageUri;
                if (!$same) {
                    throw new HarnessIdempotencyConflictException(
                        'Run already has a different Retention archive.'
                    );
                }
                return true;
            }

            $current = $this->candidateFromRun($run);
            if (!hash_equals($current->snapshotHash, $candidate->snapshotHash)) {
                return false;
            }
            // Fail-closed re-validation of the side-effect barrier inside the
            // locked transaction: the candidate was loaded before the archive
            // bytes were written, so a side effect may have appeared in that
            // window. A violation aborts the whole batch loudly instead of
            // looking like a stale candidate that silently retries.
            $this->assertNoOutstandingSideEffects($current->runId);
            EventStreamIntegrityValidator::validate(
                $current->runId,
                $current->nextEventNo,
                $current->events,
                static fn (mixed $event, int $index): int => $current->runId,
                static fn (mixed $event, int $index): mixed => is_array($event)
                    ? ($event['event_no'] ?? null)
                    : null
            );
            $eventNumbers = array_map(
                static fn (array $event): int => (int)($event['event_no'] ?? 0),
                $current->events
            );
            $firstEvent = $eventNumbers === [] ? null : min($eventNumbers);
            $lastEvent = $eventNumbers === [] ? null : max($eventNumbers);
            $inserted = $this->db->execute(
                "INSERT INTO harness_retention_archives
                    (archive_uid,run_id,snapshot_hash,content_hash,storage_uri,
                     size_bytes,event_count,artifact_count,first_event_no,last_event_no,
                     completed_at,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",
                [
                    $archive->archiveUid,
                    $archive->runId,
                    $archive->snapshotHash,
                    $archive->contentHash,
                    $archive->storageUri,
                    $archive->sizeBytes,
                    $archive->eventCount,
                    $archive->artifactCount,
                    $firstEvent,
                    $lastEvent,
                    $candidate->completedAt,
                ]
            );
            if ($inserted !== 1) {
                throw new HarnessPersistenceException('Retention ledger insert failed.');
            }

            foreach ($current->artifacts as $artifact) {
                if (($artifact['storage_kind'] ?? null) !== 'inline') {
                    continue;
                }
                $artifactId = HarnessPersistenceValue::positiveInt(
                    $artifact['id'] ?? null,
                    'retention.artifact_id'
                );
                $artifactUid = HarnessPersistenceValue::uid(
                    $artifact['artifact_uid'] ?? null,
                    'retention.artifact_uid'
                );
                $contentHash = HarnessPersistenceValue::hash(
                    $artifact['content_hash'] ?? null,
                    'retention.artifact_hash'
                );
                $uri = $archive->storageUri . '#artifact=' . $artifactUid;
                HarnessSensitiveDataGuard::assertSafeStorageUri($uri);
                $tiered = $this->db->execute(
                    "UPDATE generation_artifacts
                     SET storage_kind='external',inline_text=NULL,inline_json=NULL,
                         storage_uri=?
                     WHERE id=? AND run_id=? AND storage_kind='inline'
                       AND content_hash=?",
                    [$uri, $artifactId, $candidate->runId, $contentHash]
                );
                if ($tiered !== 1) {
                    throw new HarnessPersistenceException(
                        'Artifact changed during verified Retention tiering.'
                    );
                }
            }

            // Imported or legacy rows may predate allocator admission. Persist
            // their maximum identity before removing MySQL's live high-water mark.
            (new RunEventIdAllocator($this->db))->observe(max([0, ...array_column($current->events, 'id')]));
            $deleted = $this->db->execute(
                'DELETE FROM generation_events WHERE run_id=?',
                [$candidate->runId]
            );
            if ($deleted !== count($current->events)) {
                throw new HarnessPersistenceException(
                    'Event count changed during verified Retention purge.'
                );
            }
            // A live-prefix checkpoint hashes materialized rows. Once those
            // rows move to the verified base archive, retaining that seed
            // would make every subsequent Poll attempt to hash an empty table.
            $this->db->execute('DELETE FROM generation_event_checkpoints WHERE run_id=?', [$candidate->runId]);
            return true;
        });
    }

    /**
     * Destructive purge is forbidden while any side effect could still write
     * or settle against this Run's live Events. Called inside the already-held
     * Run row transaction so the reads are serialized against appends.
     */
    private function assertNoOutstandingSideEffects(int $runId): void
    {
        $outstanding = $this->db->fetchOne(
            "SELECT
               (SELECT COUNT(*)
                  FROM generation_events e
                 WHERE e.run_id=? AND e.event_type='model.budget.reserved'
                   AND NOT EXISTS (
                       SELECT 1 FROM generation_events s
                        WHERE s.run_id=e.run_id
                          AND s.event_key=CONCAT('model-budget:settled:',
                                                 SUBSTRING(e.event_key, 23))
                   )) AS outstanding_reservations,
               (SELECT COUNT(*)
                  FROM chapter_commit_ledger ccl
                  JOIN postprocess_jobs pj ON pj.id=ccl.postprocess_job_id
                 WHERE ccl.run_id=? AND pj.state IN ('queued','retry','running')
               ) AS active_postprocess_jobs,
               (SELECT COUNT(*)
                  FROM generation_attempts a
                  JOIN generation_steps s ON s.id=a.step_id
                 WHERE s.run_id=?
                   AND (a.state='running' OR a.error_code='usage_unknown')
               ) AS unsettled_attempts,
               (SELECT COUNT(*) FROM generation_side_effect_operations op
                 WHERE op.run_id=? AND op.state IN ('reserved','invoking','unknown')
               ) AS unresolved_operations",
            [$runId, $runId, $runId, $runId]
        );
        if ($outstanding === null
            || (int)($outstanding['outstanding_reservations'] ?? -1) !== 0) {
            throw new HarnessPersistenceException(
                'Run has an outstanding model budget reservation; Retention purge is fail-closed.'
            );
        }
        if ((int)($outstanding['active_postprocess_jobs'] ?? -1) !== 0) {
            throw new HarnessPersistenceException(
                'Run still owns a live post-processing job; Retention purge is fail-closed.'
            );
        }
        if ((int)($outstanding['unsettled_attempts'] ?? -1) !== 0) {
            throw new HarnessPersistenceException(
                'Run has an attempt with unknown settlement; Retention purge is fail-closed.'
            );
        }
        if ((int)($outstanding['unresolved_operations'] ?? -1) !== 0) {
            throw new HarnessPersistenceException(
                'Run has an unresolved side-effect operation; Retention purge is fail-closed.'
            );
        }
    }

    /** @param array<string,mixed> $run */
    private function candidateFromRun(array $run): RunRetentionCandidate
    {
        $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'retention.run_id');
        $runPayload = [
            'state' => $run['state'] ?? null,
            'progress' => (int)($run['progress'] ?? 0),
            'workflow_name' => $run['workflow_name'] ?? null,
            'workflow_version' => $run['workflow_version'] ?? null,
            'authority_mode' => $run['authority_mode'] ?? null,
            'user_id' => (int)($run['user_id'] ?? 0),
            'novel_id' => (int)($run['novel_id'] ?? 0),
            'chapter_id' => $run['chapter_id'] === null ? null : (int)$run['chapter_id'],
            'result' => $this->jsonOrEmpty($run['result_json'] ?? null, 'retention.run_result'),
            'error_code' => $run['error_code'] ?? null,
            'error_message' => $run['error_message'] ?? null,
        ];
        $events = array_map(function (array $event): array {
            return [
                'id' => (int)$event['id'],
                'event_no' => (int)$event['event_no'],
                'step_id' => $event['step_id'] === null ? null : (int)$event['step_id'],
                'attempt_id' => $event['attempt_id'] === null ? null : (int)$event['attempt_id'],
                'event_type' => $event['event_type'],
                'event_version' => (int)$event['event_version'],
                'severity' => $event['severity'],
                'event_key' => $event['event_key'],
                'actor_type' => $event['actor_type'],
                'actor_id' => $event['actor_id'],
                'causation_event_id' => $event['causation_event_id'] === null
                    ? null : (int)$event['causation_event_id'],
                'client_visible' => (bool)$event['client_visible'],
                'payload' => $this->jsonOrEmpty($event['payload_json'], 'retention.event_payload'),
                'payload_hash' => $event['payload_hash'],
                'created_at' => $event['created_at'],
            ];
        }, $this->db->fetchAll(
            'SELECT * FROM generation_events WHERE run_id=? ORDER BY event_no',
            [$runId]
        ));
        $artifacts = array_map(function (array $artifact): array {
            return [
                'id' => (int)$artifact['id'],
                'artifact_uid' => $artifact['artifact_uid'],
                'step_id' => $artifact['step_id'] === null ? null : (int)$artifact['step_id'],
                'artifact_type' => $artifact['artifact_type'],
                'logical_key' => $artifact['logical_key'],
                'revision_no' => (int)$artifact['revision_no'],
                'content_hash' => $artifact['content_hash'],
                'mime_type' => $artifact['mime_type'],
                'encoding' => $artifact['encoding'],
                'storage_kind' => $artifact['storage_kind'],
                'inline_text' => $artifact['inline_text'],
                'inline_json' => $artifact['inline_json'] === null
                    ? null
                    : $this->jsonOrEmpty($artifact['inline_json'], 'retention.artifact_json'),
                'storage_uri' => $artifact['storage_uri'],
                'size_bytes' => (int)$artifact['size_bytes'],
                'sensitivity' => $artifact['sensitivity'],
                'metadata' => $this->jsonOrEmpty($artifact['metadata_json'], 'retention.metadata'),
                'created_at' => $artifact['created_at'],
            ];
        }, $this->db->fetchAll(
            'SELECT * FROM generation_artifacts WHERE run_id=? ORDER BY id',
            [$runId]
        ));
        return new RunRetentionCandidate(
            $runId,
            HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'retention.run_uid'),
            HarnessPersistenceValue::optionalMysqlDate(
                $run['completed_at'] ?? null,
                'retention.completed_at'
            ) ?? throw new HarnessPersistenceException('Terminal Run has no completion time.'),
            $runPayload,
            $events,
            $artifacts,
            HarnessPersistenceValue::positiveInt(
                $run['next_event_no'] ?? null,
                'retention.next_event_no'
            )
        );
    }

    /** @return array<string,mixed> */
    private function jsonOrEmpty(mixed $json, string $field): array
    {
        return $json === null || $json === ''
            ? []
            : HarnessPersistenceValue::decodeJsonArray($json, $field);
    }
}
