<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionEventSegments.php';
require_once dirname(__DIR__) . '/persistence/RunEventStore.php';

/** Explicit opt-in and immutable tail archival. The old purge default remains closed. */
final class EventSegmentService
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function enableContinuation(int $runId, bool $apply = false): array
    {
        return $this->db->transactional(function () use ($runId, $apply): array {
            $run = $this->lockRun($runId);
            $manifests = RetentionEventSegments::manifests($this->db, $runId);
            $lastArchived = $this->verifyArchivedPrefix($manifests, $runId);
            $live = $this->liveRows($runId);
            if ((int)$run['event_stream_version'] > 1) {
                EventStreamIntegrityValidator::validateRange($runId, $lastArchived, (int)$run['next_event_no'],
                    array_map(GenerationEvent::fromRow(...), $live));
                return ['run_id' => $runId, 'stream_version' => (int)$run['event_stream_version'], 'idempotent' => true];
            }
            if ($live !== []) {
                throw new HarnessPersistenceException('Continuation opt-in requires a fully purged Run.');
            }
            EventStreamIntegrityValidator::validateRange($runId, $lastArchived, (int)$run['next_event_no'], []);
            $this->assertNoSideEffects($runId);
            if ($apply) {
                $this->db->execute('UPDATE generation_runs SET event_stream_version=2 WHERE id=? AND event_stream_version=1', [$runId]);
                $this->db->execute('DELETE FROM generation_event_checkpoints WHERE run_id=?', [$runId]);
            }
            return ['run_id' => $runId, 'stream_version' => 2, 'idempotent' => false, 'apply' => $apply];
        });
    }

    public function archiveTail(int $runId, RetentionArchiveSink $sink, bool $apply = false): array
    {
        // No filesystem mutation runs inside a deadlock-replayed DB callback.
        $snapshot = $this->db->transactional(fn(): array => $this->tailSnapshot($runId));
        if ($snapshot['rows'] === []) {
            return ['run_id' => $runId, 'archived' => 0, 'idempotent' => true];
        }
        $bytes = HarnessPersistenceValue::canonicalJson(['schema' => RetentionEventSegments::FORMAT,
            'run_id' => $runId, 'segment_no' => $snapshot['segment_no'], 'events' => $snapshot['rows']]);
        if (strlen($bytes) > RetentionEventSegments::MAX_BYTES) {
            throw new HarnessPersistenceException('Event tail exceeds the segment byte limit.');
        }
        $hash = hash('sha256', $bytes);
        if (!$apply) {
            return ['run_id' => $runId, 'archived' => count($snapshot['rows']), 'apply' => false, 'content_hash' => $hash];
        }
        $uid = substr(hash('sha256', $runId . ':' . $snapshot['segment_no'] . ':' . $hash), 0, 32);
        $uri = $sink->putIdempotent($uid, $bytes, $hash);
        if (!$sink->verify($uri, $hash, strlen($bytes))) {
            throw new HarnessPersistenceException('New Event segment failed verification before purge.');
        }
        return $this->db->transactional(function () use ($runId, $snapshot, $hash, $uri, $bytes, $sink): array {
            $current = $this->tailSnapshot($runId);
            if (HarnessPersistenceValue::canonicalJson($snapshot) !== HarnessPersistenceValue::canonicalJson($current)) {
                throw new HarnessPersistenceException('Event tail changed while archive was written; no Events were purged.');
            }
            if (!$sink->verify($uri, $hash, strlen($bytes))) {
                throw new HarnessPersistenceException('Event segment bytes changed before locked purge.');
            }
            $rows = $snapshot['rows'];
            $this->db->execute('INSERT INTO generation_event_segments
                (run_id,segment_no,first_event_no,last_event_no,event_count,storage_uri,content_hash,size_bytes,format_version,created_at)
                VALUES (?,?,?,?,?,?,?,?,1,UTC_TIMESTAMP(6))',
                [$runId, $snapshot['segment_no'], $rows[0]['event_no'], $rows[array_key_last($rows)]['event_no'],
                    count($rows), $uri, $hash, strlen($bytes)]);
            (new RunEventIdAllocator($this->db))->observe(max([0, ...array_column($rows, 'id')]));
            $deleted = $this->db->execute('DELETE FROM generation_events WHERE run_id=?', [$runId]);
            if ($deleted !== count($rows)) {
                throw new HarnessPersistenceException('Event segment purge count changed.');
            }
            $this->db->execute('UPDATE generation_runs SET event_stream_version=event_stream_version+1 WHERE id=?', [$runId]);
            return ['run_id' => $runId, 'archived' => $deleted, 'segment_no' => $snapshot['segment_no'], 'content_hash' => $hash];
        });
    }

    /** Materialize every Event exactly; verified external Artifact URIs stay valid. */
    public function restoreEvents(int $runId, bool $apply = false): array
    {
        return $this->db->transactional(function () use ($runId, $apply): array {
            $run = $this->lockRun($runId);
            if ((int)$run['event_stream_version'] === 1) {
                $live = array_map(GenerationEvent::fromRow(...), $this->liveRows($runId));
                EventStreamIntegrityValidator::validate($runId, (int)$run['next_event_no'], $live);
                return ['run_id' => $runId, 'restored' => 0, 'idempotent' => true];
            }
            $manifests = RetentionEventSegments::manifests($this->db, $runId);
            $archiveCount = $manifests === [] ? 0 : (int)$manifests[array_key_last($manifests)]['last_event_no'];
            $tail = array_map(GenerationEvent::fromRow(...), $this->liveRows($runId));
            $complete = function () use ($manifests, $runId, $tail): iterable {
                yield from $this->archivedEvents($manifests, $runId);
                yield from $tail;
            };
            // The former live-only causation FK is intentionally absent in
            // M70. Validate every reference before any materialization, and
            // include the live tail so restore cannot bless a corrupt suffix.
            $seenIds = $seenKeys = $steps = $attempts = [];
            $nextNo = 1;
            foreach ($complete() as $event) {
                if ($event->runId !== $runId || $event->eventNo !== $nextNo++) {
                    throw new HarnessPersistenceException('Event restore complete stream has a gap or overlap.');
                }
                EventContractRegistry::validateHistorical($event->eventType, $event->eventVersion,
                    HarnessPersistenceValue::decodeJsonArray($event->payloadJson, 'restore.event_payload'));
                if (isset($seenIds[$event->id]) || isset($seenKeys[$event->eventKey])) {
                    throw new HarnessIdempotencyConflictException('Event restore stream has duplicate immutable identities.');
                }
                if ($event->causationEventId !== null && !isset($seenIds[$event->causationEventId])) {
                    throw new HarnessIdempotencyConflictException('Event restore causation must reference an earlier Event in the same Run.');
                }
                $stepId = $event->stepId;
                if ($stepId !== null && !isset($steps[$stepId])) {
                    $step = $this->db->fetchOne('SELECT id,run_id FROM generation_steps WHERE id=? FOR UPDATE', [$stepId]);
                    if ($step === null || (int)$step['id'] !== $stepId || (int)$step['run_id'] !== $runId) {
                        throw new HarnessIdempotencyConflictException('Event restore Step is missing or belongs to another Run.');
                    }
                    $steps[$stepId] = true;
                }
                $attemptId = $event->attemptId;
                if ($attemptId !== null && !isset($attempts[$attemptId])) {
                    $attempt = $this->db->fetchOne('SELECT ga.id,ga.step_id,gs.run_id FROM generation_attempts ga
                        JOIN generation_steps gs ON gs.id=ga.step_id WHERE ga.id=? FOR UPDATE', [$attemptId]);
                    if ($attempt === null || (int)$attempt['id'] !== $attemptId || (int)$attempt['run_id'] !== $runId) {
                        throw new HarnessIdempotencyConflictException('Event restore Attempt is missing or belongs to another Run.');
                    }
                    $attempts[$attemptId] = (int)$attempt['step_id'];
                }
                if ($attemptId !== null && $stepId !== null && $attempts[$attemptId] !== $stepId) {
                    throw new HarnessIdempotencyConflictException('Event restore Attempt does not belong to its referenced Step.');
                }
                $seenIds[$event->id] = $seenKeys[$event->eventKey] = true;
            }
            if ($nextNo !== (int)$run['next_event_no']) {
                throw new HarnessPersistenceException('Event restore complete stream does not match the Run sequencer.');
            }
            foreach ($this->archivedEvents($manifests, $runId) as $event) {
                $collision = $this->db->fetchOne('SELECT id FROM generation_events WHERE id=? OR (run_id=? AND event_key=?) FOR UPDATE',
                    [$event->id, $runId, $event->eventKey]);
                if ($collision !== null) {
                    throw new HarnessIdempotencyConflictException('Archived Event identity is already occupied.');
                }
            }
            if ($apply) {
                foreach ($this->archivedEvents($manifests, $runId) as $event) {
                    $row = RetentionEventSegments::row($event);
                    $this->db->execute('INSERT INTO generation_events (' . implode(',', array_keys($row))
                        . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
                }
                $this->db->execute('UPDATE generation_runs SET event_stream_version=1 WHERE id=?', [$runId]);
                $this->db->execute('DELETE FROM generation_event_checkpoints WHERE run_id=?', [$runId]);
            }
            return ['run_id' => $runId, 'restored' => $archiveCount, 'apply' => $apply, 'artifacts' => 'verified_external'];
        });
    }

    private function tailSnapshot(int $runId): array
    {
        $run = $this->lockRun($runId);
        if ((int)$run['event_stream_version'] < 2) {
            throw new HarnessPersistenceException('Event tail archival requires explicit continuation opt-in.');
        }
        $manifests = RetentionEventSegments::manifests($this->db, $runId);
        $lastArchived = $this->verifyArchivedPrefix($manifests, $runId);
        $rows = $this->liveRows($runId);
        if (count($rows) > RetentionArchiveReader::MAX_ARCHIVE_ROWS) {
            throw new HarnessPersistenceException('Event tail exceeds the segment row limit.');
        }
        EventStreamIntegrityValidator::validateRange($runId, $lastArchived, (int)$run['next_event_no'],
            array_map(GenerationEvent::fromRow(...), $rows));
        $this->assertNoSideEffects($runId);
        $number = 1;
        foreach ($manifests as $manifest) {
            $number = max($number, (int)$manifest['segment_no'] + 1);
        }
        return ['rows' => $rows, 'segment_no' => $number, 'stream_version' => (int)$run['event_stream_version']];
    }

    private function lockRun(int $runId): array
    {
        HarnessPersistenceValue::positiveInt($runId, 'segment.run_id');
        return $this->db->fetchOne('SELECT * FROM generation_runs WHERE id=? FOR UPDATE', [$runId])
            ?? throw new HarnessPersistenceException('Event segment Run does not exist.');
    }

    private function liveRows(int $runId): array
    {
        return $this->db->fetchAll('SELECT * FROM generation_events WHERE run_id=? ORDER BY event_no FOR UPDATE', [$runId]);
    }

    private function archivedEvents(array $manifests, int $runId): iterable
    {
        $keys = [];
        $ids = [];
        foreach ($manifests as $manifest) {
            foreach (RetentionEventSegments::events($manifest, $runId) as $event) {
                if (isset($keys[$event->eventKey]) || isset($ids[$event->id])) {
                    throw new HarnessPersistenceException('Event segments contain duplicate immutable identities.');
                }
                $keys[$event->eventKey] = $ids[$event->id] = true;
                yield $event;
            }
        }
    }

    private function verifyArchivedPrefix(array $manifests, int $runId): int
    {
        $last = 0;
        foreach ($manifests as $manifest) {
            $events = RetentionEventSegments::events($manifest, $runId);
            EventStreamIntegrityValidator::validateRange($runId, $last, (int)$manifest['last_event_no'] + 1, $events);
            $last = (int)$manifest['last_event_no'];
            unset($events);
        }
        return $last;
    }

    private function assertNoSideEffects(int $runId): void
    {
        $row = $this->db->fetchOne("SELECT
            (SELECT COUNT(*) FROM generation_side_effect_operations WHERE run_id=? AND state IN ('reserved','invoking','unknown')) AS operations,
            (SELECT COUNT(*) FROM generation_events e WHERE e.run_id=? AND e.event_type='model.budget.reserved'
              AND NOT EXISTS (SELECT 1 FROM generation_events s WHERE s.run_id=e.run_id
                AND s.event_key=CONCAT('model-budget:settled:',SUBSTRING(e.event_key,23)))) AS budgets,
            (SELECT COUNT(*) FROM generation_attempts a JOIN generation_steps s ON s.id=a.step_id
              WHERE s.run_id=? AND (a.state='running' OR a.error_code='usage_unknown')) AS attempts,
            (SELECT COUNT(*) FROM chapter_commit_ledger c JOIN postprocess_jobs p ON p.id=c.postprocess_job_id
              WHERE c.run_id=? AND p.state IN ('queued','retry','running')) AS jobs", [$runId,$runId,$runId,$runId]);
        if ($row === null || array_sum(array_map('intval', array_values($row))) !== 0
            || count($row) !== 4) {
            throw new HarnessPersistenceException('Unsettled side effects block Event segment archival.');
        }
    }
}
