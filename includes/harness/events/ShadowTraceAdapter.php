<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ShadowTrace.php';
require_once __DIR__ . '/WorkflowStateSemantics.php';
require_once dirname(__DIR__) . '/persistence/bootstrap.php';
require_once dirname(__DIR__, 2) . '/quality/WritePolicySnapshot.php';

interface ShadowTraceAdapter
{
    /** @return array<string,mixed>|null */
    public function findLegacyTask(string $taskId): ?array;

    /** @return list<array<string,mixed>> */
    public function findCancellationScope(int $novelId, ?string $taskId): array;

    /** @param array<string,mixed> $legacyTask */
    public function persist(
        ShadowTrace $trace,
        array $legacyTask,
        WritePolicySnapshot $policySnapshot,
        string $authorityMode
    ): void;
}

/**
 * Production projection adapter. It never copies prompt/result/error text or
 * a legacy lease token into Harness payloads. A deterministic, non-secret
 * shadow fence keeps projected running rows structurally readable.
 */
final class M62ShadowTraceAdapter implements ShadowTraceAdapter
{
    private HarnessPersistenceDriver $db;
    private ?Closure $now;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $now = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->now = $now === null ? null : Closure::fromCallable($now);
    }

    public function findLegacyTask(string $taskId): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . $this->legacyColumns()
                . ' FROM writing_tasks WHERE task_id=? LIMIT 1',
            [$taskId]
        );
    }

    public function findCancellationScope(int $novelId, ?string $taskId): array
    {
        if ($novelId <= 0) {
            throw new InvalidArgumentException('Shadow cancellation novel_id must be positive.');
        }
        if ($taskId !== null) {
            return $this->db->fetchAll(
                'SELECT ' . $this->legacyColumns()
                    . ' FROM writing_tasks'
                    . ' WHERE novel_id=? AND task_id=? AND cancel_requested=1'
                    . ' ORDER BY id',
                [$novelId, $taskId]
            );
        }

        return $this->db->fetchAll(
            'SELECT ' . $this->legacyColumns()
                . ' FROM writing_tasks'
                . ' WHERE novel_id=? AND cancel_requested=1'
                . ' ORDER BY id',
            [$novelId]
        );
    }

    public function persist(
        ShadowTrace $trace,
        array $legacyTask,
        WritePolicySnapshot $policySnapshot,
        string $authorityMode
    ): void {
        if ($authorityMode !== LegacyWritingTaskRunIdentity::AUTHORITY_MODE) {
            throw new InvalidArgumentException(
                'A legacy writing-task projection must always remain shadow authority.'
            );
        }

        $task = $this->normalizeLegacyTask($legacyTask, $trace->taskId);
        if (($trace->fenceGeneration !== null
                && $trace->fenceGeneration !== $task['fence_generation'])
            || ($trace->transition === ShadowTrace::TERMINAL
                && $trace->requestedState !== $task['state'])) {
            throw new HarnessIdempotencyConflictException(
                'Shadow transition no longer matches its legacy fence or terminal outcome.'
            );
        }
        $runState = WorkflowStateSemantics::runFromLegacy($task['state']);
        $nowSql = $this->nowSql();
        $now = new DateTimeImmutable($nowSql, new DateTimeZone('UTC'));
        $running = $runState === GenerationRunState::RUNNING;
        $terminal = WorkflowStateSemantics::isLifecycleTerminal($runState);
        $fenceGeneration = $running
            ? max(1, $task['fence_generation'])
            : $task['fence_generation'];
        $leaseOwner = $running ? 'legacy-shadow-adapter' : null;
        $leaseToken = $running
            ? substr(
                hash(
                    'sha256',
                    'writing-task-shadow-fence:' . $trace->taskId . ':' . $fenceGeneration
                ),
                0,
                32
            )
            : null;
        $leaseExpiresAt = $running
            ? ($task['lease_expires_at'] ?? HarnessPersistenceValue::mysqlDate(
                $now->modify('+180 seconds')
            ))
            : null;
        $progress = $runState === GenerationRunState::SUCCEEDED
            ? 100
            : $task['progress'];
        $attempt = min(100, $task['attempt']);
        $maxAttempts = max(3, $attempt);
        $startedAt = $running || $terminal ? $nowSql : null;
        $completedAt = $terminal ? $nowSql : null;
        $errorCode = match ($runState) {
            GenerationRunState::FAILED => 'legacy_task_failed',
            GenerationRunState::CANCELED => 'legacy_task_canceled',
            default => null,
        };

        $flagSnapshot = [
            'source' => 'writing_tasks',
            'projection' => 'shadow',
            'frozen_harness_mode' => $policySnapshot->harnessMode(),
            'policy_checksum' => $policySnapshot->checksum(),
        ];
        $goal = [
            'kind' => 'chapter_write',
            'legacy_task_id' => $trace->taskId,
        ];
        if ($task['chapter_id'] !== null) {
            $goal['chapter_id'] = $task['chapter_id'];
        }
        HarnessSensitiveDataGuard::assertSafeStructure($flagSnapshot, 'shadow.flag_snapshot');
        HarnessSensitiveDataGuard::assertSafeStructure($goal, 'shadow.goal');
        $flagSnapshotJson = HarnessPersistenceValue::canonicalJson($flagSnapshot);
        $goalJson = HarnessPersistenceValue::canonicalJson($goal);

        $payload = $trace->payload(
            $task['state'],
            $progress,
            $task['fence_generation'],
            $task['cancel_requested'],
            $task['content_saved']
        );
        HarnessSensitiveDataGuard::assertSafeStructure($payload, 'shadow.event');

        $this->db->transactional(function () use (
            $trace,
            $task,
            $authorityMode,
            $runState,
            $progress,
            $attempt,
            $maxAttempts,
            $fenceGeneration,
            $leaseOwner,
            $leaseToken,
            $leaseExpiresAt,
            $startedAt,
            $completedAt,
            $errorCode,
            $flagSnapshotJson,
            $goalJson,
            $payload,
            $nowSql
        ): void {
            $this->db->execute(
                "INSERT INTO generation_runs
                    (run_uid, user_id, novel_id, chapter_id, queue, run_type,
                     workflow_name, workflow_version, authority_mode, state,
                     progress, idempotency_key, attempt, max_attempts,
                     available_at, next_event_no, input_hash, cancel_requested,
                     fence_generation, lease_owner, lease_token, lease_expires_at,
                     flag_snapshot_json, goal_json, started_at, completed_at,
                     error_code)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1,
                         ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $trace->runUid(),
                    $task['user_id'],
                    $task['novel_id'],
                    $task['chapter_id'],
                    LegacyWritingTaskRunIdentity::QUEUE,
                    LegacyWritingTaskRunIdentity::RUN_TYPE,
                    LegacyWritingTaskRunIdentity::WORKFLOW_NAME,
                    LegacyWritingTaskRunIdentity::WORKFLOW_VERSION,
                    $authorityMode,
                    $runState->value,
                    $progress,
                    $trace->runIdempotencyKey(),
                    $attempt,
                    $maxAttempts,
                    $nowSql,
                    $trace->inputHash(),
                    $task['cancel_requested'] ? 1 : 0,
                    $fenceGeneration,
                    $leaseOwner,
                    $leaseToken,
                    $leaseExpiresAt,
                    $flagSnapshotJson,
                    $goalJson,
                    $startedAt,
                    $completedAt,
                    $errorCode,
                ]
            );

            $run = $this->db->fetchOne(
                'SELECT id, run_uid, user_id, novel_id, chapter_id, queue, run_type,'
                    . ' workflow_name, workflow_version, authority_mode, idempotency_key'
                    . ', fence_generation, event_stream_version'
                    . ' FROM generation_runs'
                    . ' WHERE user_id=? AND idempotency_key=? FOR UPDATE',
                [$task['user_id'], $trace->runIdempotencyKey()]
            );
            if ($run === null) {
                throw new HarnessIdempotencyConflictException(
                    'Shadow Run idempotency key did not resolve after upsert.'
                );
            }
            $runId = $this->assertRunIdentity(
                $run,
                $trace,
                $task,
                $authorityMode
            );

            if ($task['fence_generation'] < HarnessPersistenceValue::nonNegativeInt(
                $run['fence_generation'] ?? null,
                'shadow.run_fence_generation'
            )) {
                throw new HarnessIdempotencyConflictException(
                    'Shadow observation belongs to a superseded Run fence.'
                );
            }
            $committed = $this->committedLifecycleDraft($runId, $trace, $payload, (int)($run['event_stream_version'] ?? 1) > 1);
            if ($committed !== null) {
                // A lifecycle Event records the first committed observation.
                // Retrying it must not reset a newer Run projection or replace
                // that Event's immutable progress/content/cancellation sample.
                // Still use append: all immutable and Retention gates apply.
                (new RunEventStore($this->db))->append($committed);
                return;
            }

            $this->db->execute(
                "UPDATE generation_runs
                 SET chapter_id=COALESCE(chapter_id, ?), state=?, progress=?,
                     attempt=GREATEST(attempt, ?),
                     max_attempts=GREATEST(max_attempts, ?),
                     cancel_requested=?,
                     fence_generation=GREATEST(fence_generation, ?),
                     lease_owner=?, lease_token=?, lease_expires_at=?,
                     started_at=CASE WHEN ? IS NULL THEN started_at
                                     ELSE COALESCE(started_at, ?) END,
                     completed_at=CASE WHEN ? IS NULL THEN completed_at
                                       ELSE COALESCE(completed_at, ?) END,
                     error_code=?, error_message=NULL, result_json=NULL,
                     updated_at=?
                 WHERE id=?",
                [
                    $task['chapter_id'],
                    $runState->value,
                    $progress,
                    $attempt,
                    $maxAttempts,
                    $task['cancel_requested'] ? 1 : 0,
                    $fenceGeneration,
                    $leaseOwner,
                    $leaseToken,
                    $leaseExpiresAt,
                    $startedAt,
                    $startedAt,
                    $completedAt,
                    $completedAt,
                    $errorCode,
                    $nowSql,
                    $runId,
                ]
            );

            $severity = match ($runState) {
                GenerationRunState::FAILED => GenerationEventSeverity::ERROR,
                GenerationRunState::CANCELED => GenerationEventSeverity::WARNING,
                default => GenerationEventSeverity::INFO,
            };
            (new RunEventStore($this->db))->append(new GenerationEventDraft(
                $runId,
                null,
                null,
                $trace->eventType(),
                1,
                $severity,
                $trace->eventKey($payload),
                'legacy_adapter',
                null,
                null,
                // The payload contains only the guarded state whitelist above,
                // so poll cursors may expose it without leaking legacy internals.
                true,
                $payload
            ));
        });
    }

    /**
     * Resolve both deployed key formats under the Run sequencer lock. Looking
     * up the logical coordinates (not the new observed payload hash) prevents
     * an in-flight pre-deployment retry from creating a second lifecycle fact.
     * @param array<string,mixed> $payload
     */
    private function committedLifecycleDraft(
        int $runId,
        ShadowTrace $trace,
        array $payload,
        bool $segmented = false
    ): ?GenerationEventDraft {
        if (!$trace->hasStableLifecycleIdentity()) {
            return null;
        }
        $rows = $segmented ? [] : $this->db->fetchAll(
            "SELECT * FROM generation_events
             WHERE run_id=? AND (event_key=? OR (
                 event_type=?
                 AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.legacy_task_id'))=?
                 AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.fence_generation'))=?))
             ORDER BY event_no LIMIT 2 FOR UPDATE",
            [$runId, $trace->eventKey($payload), $trace->eventType(),
                $trace->taskId, $payload['fence_generation']]
        );
        if ($segmented) {
            foreach ((new RunEventStore($this->db))->iterateIncludingArchives($runId) as $historical) {
                $observed = HarnessPersistenceValue::decodeJsonArray($historical->payloadJson, 'shadow.payload');
                if ($historical->eventKey === $trace->eventKey($payload)
                    || ($historical->eventType === $trace->eventType()
                        && ($observed['legacy_task_id'] ?? null) === $trace->taskId
                        && ($observed['fence_generation'] ?? null) === $payload['fence_generation'])) {
                    $rows[] = RetentionEventSegments::row($historical);
                    if (count($rows) > 1) break;
                }
            }
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new HarnessIdempotencyConflictException(
                'Shadow lifecycle already has multiple Events at one fence.'
            );
        }
        $event = GenerationEvent::fromRow($rows[0]);
        $stored = json_decode($event->payloadJson, true, 32, JSON_THROW_ON_ERROR);
        if (($stored['source'] ?? null) !== 'writing_tasks'
            || ($stored['legacy_task_id'] ?? null) !== $trace->taskId
            || ($stored['transition'] ?? null) !== $trace->transition
            || ($stored['fence_generation'] ?? null) !== $payload['fence_generation']
            || !is_string($stored['legacy_state'] ?? null)
            || !is_int($stored['progress'] ?? null)
            || !is_bool($stored['cancel_requested'] ?? null)
            || !is_bool($stored['content_saved'] ?? null)
            || ($trace->transition === ShadowTrace::TERMINAL
                && $stored['legacy_state'] !== $trace->requestedState)) {
            throw new HarnessIdempotencyConflictException(
                'Shadow lifecycle coordinates or terminal outcome conflict.'
            );
        }
        // Rebuild the exact historical field order for the old payload hash.
        $frozen = $trace->payload(
            $stored['legacy_state'], $stored['progress'], $stored['fence_generation'],
            $stored['cancel_requested'], $stored['content_saved']
        );
        if ($event->eventKey !== $trace->eventKey($frozen)
            && $event->eventKey !== $trace->legacyEventKey($frozen)) {
            throw new HarnessIdempotencyConflictException('Shadow lifecycle Event key is invalid.');
        }
        $severity = match (WorkflowStateSemantics::runFromLegacy($stored['legacy_state'])) {
            GenerationRunState::FAILED => GenerationEventSeverity::ERROR,
            GenerationRunState::CANCELED => GenerationEventSeverity::WARNING,
            default => GenerationEventSeverity::INFO,
        };
        $draft = new GenerationEventDraft(
            $runId, null, null, $trace->eventType(), 1, $severity,
            $event->eventKey, 'legacy_adapter', null, null, true, $frozen
        );
        $draft->assertIdempotentRow($rows[0]);
        return $draft;
    }

    private function nowSql(): string
    {
        if ($this->now !== null) {
            $now = ($this->now)();
            if (!$now instanceof DateTimeImmutable) {
                throw new LogicException('Shadow Trace clock must return DateTimeImmutable.');
            }
            return HarnessPersistenceValue::mysqlDate($now);
        }

        $row = $this->db->fetchOne('SELECT UTC_TIMESTAMP(6) AS now_sql');
        $nowSql = HarnessPersistenceValue::optionalMysqlDate(
            $row['now_sql'] ?? null,
            'shadow.database_now'
        );
        if ($nowSql === null) {
            throw new HarnessPersistenceException(
                'Shadow Trace database clock did not return UTC_TIMESTAMP(6).'
            );
        }
        return $nowSql;
    }

    private function legacyColumns(): string
    {
        // Intentionally excludes result/error/status text and the real lease
        // token so they cannot accidentally cross the shadow boundary.
        return 'id, task_id, user_id, novel_id, chapter_id, state, progress,'
            . ' attempt, fence_generation, lease_expires_at, cancel_requested,'
            . ' content_saved, policy_snapshot_json, policy_snapshot_hash';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   user_id:int,
     *   novel_id:int,
     *   chapter_id:?int,
     *   state:string,
     *   progress:int,
     *   attempt:int,
     *   fence_generation:int,
     *   lease_expires_at:?string,
     *   cancel_requested:bool,
     *   content_saved:bool
     * }
     */
    private function normalizeLegacyTask(array $row, string $expectedTaskId): array
    {
        $taskId = $row['task_id'] ?? null;
        if (!is_string($taskId) || !hash_equals($expectedTaskId, $taskId)) {
            throw new HarnessPersistenceException(
                'Shadow adapter received a different legacy task identity.'
            );
        }
        $state = $row['state'] ?? null;
        if (!is_string($state)) {
            throw new HarnessPersistenceException('Legacy writing task state is malformed.');
        }
        try {
            WorkflowStateSemantics::runFromLegacy($state);
        } catch (InvalidArgumentException $exception) {
            throw new HarnessPersistenceException(
                "Unknown legacy writing task state {$state}.",
                0,
                $exception
            );
        }

        return [
            'user_id' => HarnessPersistenceValue::positiveInt($row['user_id'] ?? null, 'shadow.user_id'),
            'novel_id' => HarnessPersistenceValue::positiveInt($row['novel_id'] ?? null, 'shadow.novel_id'),
            'chapter_id' => HarnessPersistenceValue::optionalPositiveInt(
                $row['chapter_id'] ?? null,
                'shadow.chapter_id'
            ),
            'state' => $state,
            'progress' => HarnessPersistenceValue::boundedInt(
                $row['progress'] ?? null,
                'shadow.progress',
                0,
                100
            ),
            'attempt' => HarnessPersistenceValue::nonNegativeInt(
                $row['attempt'] ?? null,
                'shadow.attempt'
            ),
            'fence_generation' => HarnessPersistenceValue::nonNegativeInt(
                $row['fence_generation'] ?? null,
                'shadow.fence_generation'
            ),
            'lease_expires_at' => HarnessPersistenceValue::optionalMysqlDate(
                $row['lease_expires_at'] ?? null,
                'shadow.lease_expires_at'
            ),
            'cancel_requested' => HarnessPersistenceValue::bool(
                $row['cancel_requested'] ?? 0,
                'shadow.cancel_requested'
            ),
            'content_saved' => HarnessPersistenceValue::bool(
                $row['content_saved'] ?? 0,
                'shadow.content_saved'
            ),
        ];
    }

    /**
     * @param array<string,mixed> $run
     * @param array{user_id:int,novel_id:int,chapter_id:?int} $task
     */
    private function assertRunIdentity(
        array $run,
        ShadowTrace $trace,
        array $task,
        string $authorityMode
    ): int {
        $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'shadow.run_id');
        $storedChapterId = HarnessPersistenceValue::optionalPositiveInt(
            $run['chapter_id'] ?? null,
            'shadow.run_chapter_id'
        );
        if ((string)($run['run_uid'] ?? '') !== $trace->runUid()
            || (int)($run['user_id'] ?? 0) !== $task['user_id']
            || (int)($run['novel_id'] ?? 0) !== $task['novel_id']
            || ($storedChapterId !== null
                && $task['chapter_id'] !== null
                && $storedChapterId !== $task['chapter_id'])
            || (string)($run['queue'] ?? '') !== LegacyWritingTaskRunIdentity::QUEUE
            || (string)($run['run_type'] ?? '') !== LegacyWritingTaskRunIdentity::RUN_TYPE
            || (string)($run['workflow_name'] ?? '') !== LegacyWritingTaskRunIdentity::WORKFLOW_NAME
            || (string)($run['workflow_version'] ?? '') !== LegacyWritingTaskRunIdentity::WORKFLOW_VERSION
            || (string)($run['authority_mode'] ?? '') !== $authorityMode
            || (string)($run['idempotency_key'] ?? '') !== $trace->runIdempotencyKey()) {
            throw new HarnessIdempotencyConflictException(
                'Legacy task resolved to a different immutable shadow Run.'
            );
        }
        return $runId;
    }
}
