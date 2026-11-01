<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionRestoreService.php';

/**
 * Conservative inverse of M65RunRetentionRepository::commitArchive().
 *
 * The ledger is intentionally retained. No Run, Step, Attempt or Artifact row
 * is created. The only allowed mutations are exact Event reinsertion and an
 * exact external->inline payload transition for Artifact rows that the source
 * archive proves were inline before tiering.
 */
final class M65RunRetentionRestoreRepository implements RunRetentionRestoreRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function restore(
        VerifiedRunRetentionArchive $archive,
        bool $apply
    ): RetentionRestoreResult {
        // Do not trust a hand-constructed DTO at this destructive boundary.
        // Re-run the canonical/hash/row verifier before opening a transaction.
        $reverified = (new RetentionArchiveReader())->verifyBytes(
            $archive->bytes,
            $archive->storageUri
        );
        if ($archive->archiveUid !== $reverified->archiveUid
            || $archive->runId !== $reverified->runId
            || $archive->runUid !== $reverified->runUid
            || $archive->completedAt !== $reverified->completedAt
            || $archive->contentHash !== $reverified->contentHash
            || $archive->snapshotHash !== $reverified->snapshotHash
            || $archive->eventCount !== $reverified->eventCount
            || $archive->artifactCount !== $reverified->artifactCount
            || $archive->firstEventNo !== $reverified->firstEventNo
            || $archive->lastEventNo !== $reverified->lastEventNo) {
            throw new HarnessIdempotencyConflictException(
                'Retention restore received an inconsistent verified archive object.'
            );
        }
        $archive = $reverified;
        return $this->db->transactional(function () use ($archive, $apply): RetentionRestoreResult {
            $run = $this->db->fetchOne(
                "SELECT * FROM generation_runs
                 WHERE id=? AND state IN ('succeeded','aborted_after_commit','failed','canceled')
                 FOR UPDATE",
                [$archive->runId]
            );
            if ($run === null) {
                throw new HarnessIdempotencyConflictException(
                    'Retention restore requires the exact existing terminal Run.'
                );
            }
            $this->assertRunMatches($archive, $run);

            $ledger = $this->db->fetchOne(
                'SELECT * FROM harness_retention_archives WHERE run_id=? FOR UPDATE',
                [$archive->runId]
            );
            if ($ledger === null) {
                throw new HarnessIdempotencyConflictException(
                    'Retention restore requires the original archive ledger.'
                );
            }
            $this->assertLedgerMatches($archive, $ledger);

            $eventRows = $this->db->fetchAll(
                'SELECT * FROM generation_events WHERE run_id=? ORDER BY event_no FOR UPDATE',
                [$archive->runId]
            );
            $eventPhase = $this->eventPhase($archive, $eventRows);

            $artifactRows = $this->db->fetchAll(
                'SELECT * FROM generation_artifacts WHERE run_id=? ORDER BY id FOR UPDATE',
                [$archive->runId]
            );
            [$artifactPhase, $inlineArtifacts] = $this->artifactPhase(
                $archive,
                $artifactRows
            );

            $phases = array_values(array_filter(
                [$eventPhase, $artifactPhase],
                static fn (string $phase): bool => $phase !== 'empty'
            ));
            if (count(array_unique($phases, SORT_STRING)) > 1) {
                throw new HarnessIdempotencyConflictException(
                    'Retention restore found a forbidden partial restore state.'
                );
            }
            $idempotent = $phases === [] || ($phases[0] ?? null) === 'restored';

            // All collision and reference checks happen before the first write.
            // A failed verification therefore executes no mutation even before
            // the transaction rollback boundary is considered.
            if ($eventPhase === 'purged') {
                $this->assertEventIdsAreAvailable($archive);
                $this->assertEventReferencesExist($archive);
            }

            if ($apply && !$idempotent) {
                foreach ($archive->events() as $event) {
                    $inserted = $this->db->execute(
                        "INSERT INTO generation_events
                            (id,run_id,step_id,attempt_id,event_no,event_type,event_version,
                             severity,event_key,actor_type,actor_id,causation_event_id,
                             client_visible,payload_json,payload_hash,created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                        [
                            $event['id'],
                            $archive->runId,
                            $event['step_id'],
                            $event['attempt_id'],
                            $event['event_no'],
                            $event['event_type'],
                            $event['event_version'],
                            $event['severity'],
                            $event['event_key'],
                            $event['actor_type'],
                            $event['actor_id'],
                            $event['causation_event_id'],
                            $event['client_visible'] ? 1 : 0,
                            HarnessPersistenceValue::canonicalMysqlJson($event['payload']),
                            $event['payload_hash'],
                            $event['created_at'],
                        ]
                    );
                    if ($inserted !== 1) {
                        throw new HarnessPersistenceException(
                            'Retention Event restore did not insert exactly one row.'
                        );
                    }
                }
                foreach ($inlineArtifacts as $artifact) {
                    $tieredUri = $archive->storageUri . '#artifact=' . $artifact['artifact_uid'];
                    $restored = $this->db->execute(
                        "UPDATE generation_artifacts
                         SET storage_kind='inline',inline_text=?,inline_json=?,storage_uri=NULL
                         WHERE id=? AND run_id=? AND artifact_uid=? AND content_hash=?
                           AND storage_kind='external' AND inline_text IS NULL
                           AND inline_json IS NULL AND storage_uri=?",
                        [
                            $artifact['inline_text'],
                            $artifact['inline_json'] === null
                                ? null
                                : HarnessPersistenceValue::canonicalMysqlJson($artifact['inline_json']),
                            $artifact['id'],
                            $archive->runId,
                            $artifact['artifact_uid'],
                            $artifact['content_hash'],
                            $tieredUri,
                        ]
                    );
                    if ($restored !== 1) {
                        throw new HarnessPersistenceException(
                            'Retention Artifact payload changed during locked restore.'
                        );
                    }
                }
            }

            return new RetentionRestoreResult(
                $apply ? 'apply' : 'dry-run',
                $archive->archiveUid,
                $archive->runId,
                $archive->eventCount,
                count($inlineArtifacts),
                $idempotent
            );
        });
    }

    /** @param array<string,mixed> $run */
    private function assertRunMatches(VerifiedRunRetentionArchive $archive, array $run): void
    {
        $id = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'retention_restore.run.id');
        $uid = HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'retention_restore.run.run_uid');
        $completedAt = HarnessPersistenceValue::optionalMysqlDate(
            $run['completed_at'] ?? null,
            'retention_restore.run.completed_at'
        );
        if ($id !== $archive->runId || $uid !== $archive->runUid
            || $completedAt !== $archive->completedAt) {
            throw new HarnessIdempotencyConflictException(
                'Retention archive Run coordinates do not match the locked Run.'
            );
        }
        $projection = [
            'state' => $run['state'] ?? null,
            'progress' => (int)($run['progress'] ?? -1),
            'workflow_name' => $run['workflow_name'] ?? null,
            'workflow_version' => $run['workflow_version'] ?? null,
            'authority_mode' => $run['authority_mode'] ?? null,
            'user_id' => (int)($run['user_id'] ?? 0),
            'novel_id' => (int)($run['novel_id'] ?? 0),
            'chapter_id' => ($run['chapter_id'] ?? null) === null
                ? null : (int)$run['chapter_id'],
            'result' => $this->jsonOrEmpty($run['result_json'] ?? null, 'retention_restore.run.result'),
            'error_code' => $run['error_code'] ?? null,
            'error_message' => $run['error_message'] ?? null,
        ];
        if (!$this->same($projection, $archive->bundle['run'])) {
            throw new HarnessIdempotencyConflictException(
                'Retention archive Run snapshot does not match the locked Run.'
            );
        }
        $next = HarnessPersistenceValue::positiveInt(
            $run['next_event_no'] ?? null,
            'retention_restore.run.next_event_no'
        );
        if (($archive->lastEventNo === null && $next !== 1)
            || ($archive->lastEventNo !== null && $next !== $archive->lastEventNo + 1)) {
            throw new HarnessIdempotencyConflictException(
                'Retention archive Event range does not match the Run sequencer.'
            );
        }
    }

    /** @param array<string,mixed> $ledger */
    private function assertLedgerMatches(
        VerifiedRunRetentionArchive $archive,
        array $ledger
    ): void {
        $actual = [
            'archive_uid' => $ledger['archive_uid'] ?? null,
            'run_id' => (int)($ledger['run_id'] ?? 0),
            'snapshot_hash' => $ledger['snapshot_hash'] ?? null,
            'content_hash' => $ledger['content_hash'] ?? null,
            'storage_uri' => $ledger['storage_uri'] ?? null,
            'size_bytes' => (int)($ledger['size_bytes'] ?? -1),
            'event_count' => (int)($ledger['event_count'] ?? -1),
            'artifact_count' => (int)($ledger['artifact_count'] ?? -1),
            'first_event_no' => ($ledger['first_event_no'] ?? null) === null
                ? null : (int)$ledger['first_event_no'],
            'last_event_no' => ($ledger['last_event_no'] ?? null) === null
                ? null : (int)$ledger['last_event_no'],
            'completed_at' => $ledger['completed_at'] ?? null,
        ];
        $expected = [
            'archive_uid' => $archive->archiveUid,
            'run_id' => $archive->runId,
            'snapshot_hash' => $archive->snapshotHash,
            'content_hash' => $archive->contentHash,
            'storage_uri' => $archive->storageUri,
            'size_bytes' => strlen($archive->bytes),
            'event_count' => $archive->eventCount,
            'artifact_count' => $archive->artifactCount,
            'first_event_no' => $archive->firstEventNo,
            'last_event_no' => $archive->lastEventNo,
            'completed_at' => $archive->completedAt,
        ];
        if (!$this->same($actual, $expected)) {
            throw new HarnessIdempotencyConflictException(
                'Retention archive does not exactly match its locked ledger.'
            );
        }
        HarnessSensitiveDataGuard::assertSafeStorageUri((string)$actual['storage_uri']);
    }

    /** @param list<array<string,mixed>> $rows */
    private function eventPhase(
        VerifiedRunRetentionArchive $archive,
        array $rows
    ): string {
        if ($archive->eventCount === 0) {
            if ($rows !== []) {
                throw new HarnessIdempotencyConflictException(
                    'Retention restore found Events absent from the archive.'
                );
            }
            return 'empty';
        }
        if ($rows === []) {
            return 'purged';
        }
        if (count($rows) !== $archive->eventCount) {
            throw new HarnessIdempotencyConflictException(
                'Retention restore found a partial Event set.'
            );
        }
        foreach ($archive->events() as $index => $event) {
            $row = $rows[$index] ?? null;
            if (!is_array($row) || !$this->same($this->eventProjection($row), $event)) {
                throw new HarnessIdempotencyConflictException(
                    'Existing Retention Event is not byte-identical to the archive.'
                );
            }
        }
        return 'restored';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{string,list<array<string,mixed>>}
     */
    private function artifactPhase(
        VerifiedRunRetentionArchive $archive,
        array $rows
    ): array {
        if (count($rows) !== $archive->artifactCount) {
            throw new HarnessIdempotencyConflictException(
                'Retention restore requires the complete original Artifact row set.'
            );
        }
        $byId = [];
        foreach ($rows as $row) {
            $id = HarnessPersistenceValue::positiveInt(
                $row['id'] ?? null,
                'retention_restore.artifact.id'
            );
            if (isset($byId[$id])) {
                throw new HarnessIdempotencyConflictException(
                    'Locked Retention Artifact identities collide.'
                );
            }
            $byId[$id] = $row;
        }
        $inline = [];
        $phases = [];
        foreach ($archive->artifacts() as $artifact) {
            $id = (int)$artifact['id'];
            $row = $byId[$id] ?? null;
            if (!is_array($row) || !$this->same(
                $this->artifactCoreProjection($row),
                $this->artifactCoreProjection($artifact)
            )) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Artifact coordinates do not match the existing row.'
                );
            }
            if ($artifact['storage_kind'] === 'external') {
                if (!$this->same($this->artifactStorageProjection($row), [
                    'storage_kind' => 'external',
                    'inline_text' => null,
                    'inline_json' => null,
                    'storage_uri' => $artifact['storage_uri'],
                ])) {
                    throw new HarnessIdempotencyConflictException(
                        'Pre-existing external Retention Artifact changed after archival.'
                    );
                }
                continue;
            }
            $inline[] = $artifact;
            $restored = $this->same($this->artifactStorageProjection($row), [
                'storage_kind' => 'inline',
                'inline_text' => $artifact['inline_text'],
                'inline_json' => $artifact['inline_json'],
                'storage_uri' => null,
            ]);
            $tieredUri = $archive->storageUri . '#artifact=' . $artifact['artifact_uid'];
            HarnessSensitiveDataGuard::assertSafeStorageUri($tieredUri);
            $tiered = $this->same($this->artifactStorageProjection($row), [
                'storage_kind' => 'external',
                'inline_text' => null,
                'inline_json' => null,
                'storage_uri' => $tieredUri,
            ]);
            if (!$restored && !$tiered) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Artifact is neither exactly tiered nor exactly restored.'
                );
            }
            $phases[] = $restored ? 'restored' : 'purged';
        }
        if ($phases === []) {
            return ['empty', $inline];
        }
        if (count(array_unique($phases, SORT_STRING)) !== 1) {
            throw new HarnessIdempotencyConflictException(
                'Retention restore found a partial Artifact payload set.'
            );
        }
        return [$phases[0], $inline];
    }

    private function assertEventIdsAreAvailable(VerifiedRunRetentionArchive $archive): void
    {
        foreach ($archive->events() as $event) {
            $collision = $this->db->fetchOne(
                'SELECT id,run_id FROM generation_events WHERE id=? FOR UPDATE',
                [$event['id']]
            );
            if ($collision !== null) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Event ID is already owned by another row.'
                );
            }
        }
    }

    private function assertEventReferencesExist(VerifiedRunRetentionArchive $archive): void
    {
        $steps = [];
        /** @var array<int,int> $attempts */
        $attempts = [];
        foreach ($archive->events() as $event) {
            $stepId = $event['step_id'];
            if ($stepId !== null && !isset($steps[$stepId])) {
                $step = $this->db->fetchOne(
                    'SELECT id,run_id FROM generation_steps WHERE id=? FOR UPDATE',
                    [$stepId]
                );
                if ($step === null || (int)($step['run_id'] ?? 0) !== $archive->runId
                    || (int)($step['id'] ?? 0) !== $stepId) {
                    throw new HarnessIdempotencyConflictException(
                        'Retention Event Step reference is missing or belongs to another Run.'
                    );
                }
                $steps[$stepId] = true;
            }
            $attemptId = $event['attempt_id'];
            if ($attemptId !== null && !isset($attempts[$attemptId])) {
                $attempt = $this->db->fetchOne(
                    "SELECT ga.id,ga.step_id,gs.run_id
                     FROM generation_attempts ga
                     JOIN generation_steps gs ON gs.id=ga.step_id
                     WHERE ga.id=? FOR UPDATE",
                    [$attemptId]
                );
                if ($attempt === null || (int)($attempt['run_id'] ?? 0) !== $archive->runId
                    || (int)($attempt['id'] ?? 0) !== $attemptId) {
                    throw new HarnessIdempotencyConflictException(
                        'Retention Event Attempt reference is missing or belongs to another Run.'
                    );
                }
                $attempts[$attemptId] = (int)$attempt['step_id'];
            }
            if ($attemptId !== null && $stepId !== null
                && ($attempts[$attemptId] ?? 0) !== $stepId) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Event Attempt does not belong to its referenced Step.'
                );
            }
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function eventProjection(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'event_no' => (int)($row['event_no'] ?? 0),
            'step_id' => ($row['step_id'] ?? null) === null ? null : (int)$row['step_id'],
            'attempt_id' => ($row['attempt_id'] ?? null) === null ? null : (int)$row['attempt_id'],
            'event_type' => $row['event_type'] ?? null,
            'event_version' => (int)($row['event_version'] ?? 0),
            'severity' => $row['severity'] ?? null,
            'event_key' => $row['event_key'] ?? null,
            'actor_type' => $row['actor_type'] ?? null,
            'actor_id' => $row['actor_id'] ?? null,
            'causation_event_id' => ($row['causation_event_id'] ?? null) === null
                ? null : (int)$row['causation_event_id'],
            'client_visible' => HarnessPersistenceValue::bool(
                $row['client_visible'] ?? null,
                'retention_restore.event.client_visible'
            ),
            'payload' => $this->jsonOrEmpty(
                $row['payload_json'] ?? null,
                'retention_restore.event.payload'
            ),
            'payload_hash' => $row['payload_hash'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function artifactCoreProjection(array $row): array
    {
        $metadata = array_key_exists('metadata', $row)
            ? $row['metadata']
            : $this->jsonOrEmpty(
                $row['metadata_json'] ?? null,
                'retention_restore.artifact.metadata'
            );
        return [
            'id' => (int)($row['id'] ?? 0),
            'artifact_uid' => $row['artifact_uid'] ?? null,
            'step_id' => ($row['step_id'] ?? null) === null ? null : (int)$row['step_id'],
            'artifact_type' => $row['artifact_type'] ?? null,
            'logical_key' => $row['logical_key'] ?? null,
            'revision_no' => (int)($row['revision_no'] ?? 0),
            'content_hash' => $row['content_hash'] ?? null,
            'mime_type' => $row['mime_type'] ?? null,
            'encoding' => $row['encoding'] ?? null,
            'size_bytes' => (int)($row['size_bytes'] ?? -1),
            'sensitivity' => $row['sensitivity'] ?? null,
            'metadata' => $metadata,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function artifactStorageProjection(array $row): array
    {
        $inlineJson = $row['inline_json'] ?? null;
        if ($inlineJson !== null && !is_array($inlineJson)) {
            $inlineJson = HarnessPersistenceValue::decodeJsonArray(
                $inlineJson,
                'retention_restore.artifact.inline_json'
            );
        }
        return [
            'storage_kind' => $row['storage_kind'] ?? null,
            'inline_text' => $row['inline_text'] ?? null,
            'inline_json' => $inlineJson,
            'storage_uri' => $row['storage_uri'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function jsonOrEmpty(mixed $json, string $field): array
    {
        return $json === null || $json === ''
            ? []
            : HarnessPersistenceValue::decodeJsonArray($json, $field);
    }

    private function same(mixed $left, mixed $right): bool
    {
        return hash_equals(
            HarnessPersistenceValue::canonicalJson($left),
            HarnessPersistenceValue::canonicalJson($right)
        );
    }
}
