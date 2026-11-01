<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessSensitiveDataGuard.php';
require_once __DIR__ . '/GenerationRun.php';
require_once __DIR__ . '/GenerationTransition.php';
require_once dirname(__DIR__) . '/HarnessRunAdmission.php';

final class GenerationRunRepository
{
    private HarnessPersistenceDriver $db;
    private ?Closure $now;
    private Closure $tokenFactory;
    private ?HarnessRunAdmissionGuard $admissionGuard;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $now = null,
        ?callable $tokenFactory = null,
        ?HarnessRunAdmissionGuard $admissionGuard = null
    ) {
        $productionPdo = $connection instanceof PDO;
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->now = $now === null ? null : Closure::fromCallable($now);
        $this->tokenFactory = $tokenFactory === null
            ? static fn (): string => bin2hex(random_bytes(16))
            : Closure::fromCallable($tokenFactory);
        // Every production repository is constructed from its PDO and cannot
        // accidentally opt out. Deterministic in-memory drivers may inject the
        // real guard when admission behavior is the subject of their test.
        $this->admissionGuard = $admissionGuard
            ?? ($productionPdo ? new DurableHarnessRunAdmissionGuard() : null);
    }

    public function createIdempotent(GenerationRunDraft $draft): GenerationRun
    {
        return $this->db->transactional(function () use ($draft): GenerationRun {
            $this->admissionGuard?->assertDraftAdmitted($this->db, $draft);
            $this->db->execute(
                "INSERT INTO generation_runs
                    (run_uid, user_id, novel_id, chapter_id, queue, run_type,
                     workflow_name, workflow_version, authority_mode, state,
                     parent_run_id, root_run_id, idempotency_key, active_lock_key,
                     attempt, max_attempts, available_at, next_event_no,
                     input_hash, prompt_manifest_hash, context_snapshot_hash,
                     cancel_requested, deadline_at, token_budget, cost_budget,
                     input_tokens, output_tokens, cost_total, flag_snapshot_json,
                     goal_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?, ?, ?,
                         0, ?, ?, 1, ?, ?, ?, 0, ?, ?, ?, 0, 0, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->runUid,
                    $draft->userId,
                    $draft->novelId,
                    $draft->chapterId,
                    $draft->queue,
                    $draft->runType,
                    $draft->workflowName,
                    $draft->workflowVersion,
                    $draft->authorityMode,
                    $draft->parentRunId,
                    $draft->rootRunId,
                    $draft->idempotencyKey,
                    $draft->activeLockKey,
                    $draft->maxAttempts,
                    HarnessPersistenceValue::mysqlDate($draft->availableAt),
                    $draft->inputHash,
                    $draft->promptManifestHash,
                    $draft->contextSnapshotHash,
                    $draft->deadlineAt === null
                        ? null
                        : HarnessPersistenceValue::mysqlDate($draft->deadlineAt),
                    $draft->tokenBudget,
                    $draft->costBudget,
                    $draft->flagSnapshotJson,
                    $draft->goalJson,
                ]
            );

            $row = $this->db->fetchOne(
                'SELECT * FROM generation_runs WHERE user_id=? AND idempotency_key=? FOR UPDATE',
                [$draft->userId, $draft->idempotencyKey]
            );
            if ($row === null) {
                throw new HarnessIdempotencyConflictException(
                    'Run insert collided with another run_uid or active writer lock.'
                );
            }
            $draft->assertIdempotentRow($row);
            return GenerationRun::fromRow($row);
        });
    }

    public function findByUid(string $runUid, bool $lockForUpdate = false): ?GenerationRun
    {
        $uid = HarnessPersistenceValue::uid($runUid, 'run_uid');
        $row = $this->db->fetchOne(
            'SELECT * FROM generation_runs WHERE run_uid=?' . ($lockForUpdate ? ' FOR UPDATE' : ''),
            [$uid]
        );
        return $row === null ? null : GenerationRun::fromRow($row);
    }

    public function findByIdempotency(
        int $userId,
        string $idempotencyKey,
        bool $lockForUpdate = false
    ): ?GenerationRun {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $idempotencyKey = HarnessPersistenceValue::asciiString(
            $idempotencyKey,
            'idempotency_key',
            1,
            191
        );
        $row = $this->db->fetchOne(
            'SELECT * FROM generation_runs WHERE user_id=? AND idempotency_key=?'
                . ($lockForUpdate ? ' FOR UPDATE' : ''),
            [$userId, $idempotencyKey]
        );
        return $row === null ? null : GenerationRun::fromRow($row);
    }

    public function claimNext(
        string $queue,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): ?GenerationRunLease {
        return $this->claimNextWithEvents($queue, $owner, $leaseSeconds, $activeLockKey);
    }

    public function claimNextWithTransitions(
        string $queue,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): GenerationTransitionResult {
        return $this->claimNextMutation($queue, $owner, $leaseSeconds, $activeLockKey);
    }

    private function claimNextMutation(
        string $queue,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): GenerationTransitionResult {
        $queue = HarnessPersistenceValue::identifier($queue, 'queue', 32);
        $owner = HarnessPersistenceValue::identifier($owner, 'lease_owner', 120);
        $activeLockKey = HarnessPersistenceValue::optionalAsciiString(
            $activeLockKey,
            'active_lock_key',
            191
        );
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'lease_seconds',
            15,
            3600
        );

        return $this->db->transactional(function () use (
            $queue,
            $owner,
            $leaseSeconds,
            $activeLockKey
        ): GenerationTransitionResult {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $facts = [];

            // Automatically canceled and attempt-exhausted Runs finalize row by
            // row so every silent queue lifecycle migration is captured as a
            // typed transition fact instead of a bulk side effect.
            foreach ($this->db->fetchAll(
                "SELECT * FROM generation_runs
                 WHERE queue=? AND cancel_requested=1
                   AND (state IN ('queued','retry_wait','waiting_approval','quarantined')
                     OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<?))
                 ORDER BY available_at, id
                 FOR UPDATE",
                [$queue, $nowSql]
            ) as $row) {
                $prior = GenerationRun::fromRow($row);
                $affected = $this->db->execute(
                    "UPDATE generation_runs
                     SET state='canceled', active_lock_key=NULL,
                         lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                         error_code=NULL, error_message=NULL,
                         completed_at=?, updated_at=?
                     WHERE id=? AND fence_generation=? AND queue=? AND cancel_requested=1
                       AND (state IN ('queued','retry_wait','waiting_approval','quarantined')
                         OR (state='running' AND lease_expires_at IS NOT NULL
                             AND lease_expires_at<?))",
                    [$nowSql, $nowSql, $prior->id, $prior->fenceGeneration, $queue, $nowSql]
                );
                if ($affected !== 1) {
                    throw new HarnessPersistenceException(
                        'Automatically canceled generation Run lost its row-locked CAS.'
                    );
                }
                $facts[] = $this->claimTerminalFact(
                    $prior,
                    $this->requireRun($prior->id, true),
                    'run.canceled'
                );
            }

            foreach ($this->db->fetchAll(
                "SELECT * FROM generation_runs
                 WHERE queue=? AND cancel_requested=0 AND attempt>=max_attempts
                   AND NOT (attempt=max_attempts AND EXISTS (
                       SELECT 1 FROM chapter_commit_ledger c
                       WHERE c.run_id=generation_runs.id
                         AND c.chapter_id=generation_runs.chapter_id))
                   AND ((state IN ('queued','retry_wait') AND available_at<=?)
                     OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<?))
                 ORDER BY available_at, id
                 FOR UPDATE",
                [$queue, $nowSql, $nowSql]
            ) as $row) {
                $prior = GenerationRun::fromRow($row);
                $affected = $this->db->execute(
                    "UPDATE generation_runs
                     SET state='failed', active_lock_key=NULL,
                         lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                         error_code='max_attempts_exhausted',
                         error_message='Run exhausted its durable attempt budget.',
                         completed_at=?, updated_at=?
                     WHERE id=? AND fence_generation=? AND queue=? AND cancel_requested=0
                       AND attempt>=max_attempts
                       AND NOT (attempt=max_attempts AND EXISTS (
                           SELECT 1 FROM chapter_commit_ledger c
                           WHERE c.run_id=generation_runs.id
                             AND c.chapter_id=generation_runs.chapter_id))
                       AND ((state IN ('queued','retry_wait') AND available_at<=?)
                         OR (state='running' AND lease_expires_at IS NOT NULL
                             AND lease_expires_at<?))",
                    [
                        $nowSql,
                        $nowSql,
                        $prior->id,
                        $prior->fenceGeneration,
                        $queue,
                        $nowSql,
                        $nowSql,
                    ]
                );
                if ($affected !== 1) {
                    throw new HarnessPersistenceException(
                        'Exhausted generation Run lost its row-locked CAS.'
                    );
                }
                $facts[] = $this->claimTerminalFact(
                    $prior,
                    $this->requireRun($prior->id, true),
                    'run.failed'
                );
            }

            $row = $this->db->fetchOne(
                "SELECT * FROM generation_runs
                 WHERE queue=? AND cancel_requested=0
                   AND (attempt<max_attempts OR (attempt=max_attempts AND EXISTS (
                       SELECT 1 FROM chapter_commit_ledger c
                       WHERE c.run_id=generation_runs.id
                         AND c.chapter_id=generation_runs.chapter_id)))
                   AND ((state IN ('queued','retry_wait') AND available_at<=?)
                     OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<?))
                 ORDER BY available_at, id
                 LIMIT 1 FOR UPDATE",
                [$queue, $nowSql, $nowSql]
            );
            if ($row === null) {
                return $this->claimTransition(GenerationRunClaimKind::NONE, null, $facts);
            }
            $this->admissionGuard?->assertStoredRunAdmitted($this->db, $row);
            $prior = GenerationRun::fromRow($row);
            if ($activeLockKey !== null
                && $prior->activeLockKey !== null
                && !hash_equals($prior->activeLockKey, $activeLockKey)) {
                throw new HarnessIdempotencyConflictException(
                    'Claim requested a different active writer lock for this Run.'
                );
            }
            $token = HarnessPersistenceValue::uid(($this->tokenFactory)(), 'lease_token');
            $expiresAt = $now->modify("+{$leaseSeconds} seconds");
            $expiresSql = HarnessPersistenceValue::mysqlDate($expiresAt);

            $affected = $this->db->execute(
                "UPDATE generation_runs
                 SET state='running', attempt=attempt+1,
                     fence_generation=fence_generation+1,
                     active_lock_key=COALESCE(active_lock_key, ?),
                     lease_owner=?, lease_token=?, lease_expires_at=?,
                     started_at=COALESCE(started_at, ?),
                     error_code=NULL, error_message=NULL, updated_at=?
                 WHERE id=? AND fence_generation=? AND cancel_requested=0
                   AND (attempt<max_attempts OR (attempt=max_attempts AND EXISTS (
                       SELECT 1 FROM chapter_commit_ledger c
                       WHERE c.run_id=generation_runs.id
                         AND c.chapter_id=generation_runs.chapter_id)))
                   AND ((state IN ('queued','retry_wait') AND available_at<=?)
                     OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<?))",
                [
                    $activeLockKey,
                    $owner,
                    $token,
                    $expiresSql,
                    $nowSql,
                    $nowSql,
                    $prior->id,
                    $prior->fenceGeneration,
                    $nowSql,
                    $nowSql,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Generation Run claim lost its fenced CAS.');
            }
            $claimed = $this->db->fetchOne(
                'SELECT * FROM generation_runs WHERE id=? FOR UPDATE',
                [$prior->id]
            );
            if ($claimed === null) {
                throw new HarnessPersistenceException('Claimed generation Run disappeared.');
            }
            $claimedRun = GenerationRun::fromRow($claimed);
            if ($activeLockKey !== null && $claimedRun->activeLockKey !== $activeLockKey) {
                throw new HarnessPersistenceException('Claim did not acquire the requested active writer lock.');
            }
            $lease = GenerationRunLease::fromRun($claimedRun);
            $facts[] = $this->claimedFact($lease, $prior);
            return $this->claimTransition($this->claimKind($prior), $lease, $facts);
        });
    }

    /**
     * Claim one known durable Run without allowing an entry-specific worker to
     * consume another queued Chapter. It uses the same state/attempt/fencing
     * protocol as claimNext(); only the selector is narrowed to run_uid.
     */
    public function claimByUid(
        string $runUid,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): ?GenerationRunLease {
        return $this->claimByUidWithEvents($runUid, $owner, $leaseSeconds, $activeLockKey);
    }

    public function claimByUidWithTransitions(
        string $runUid,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): GenerationTransitionResult {
        return $this->claimByUidMutation($runUid, $owner, $leaseSeconds, $activeLockKey);
    }

    private function claimByUidMutation(
        string $runUid,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): GenerationTransitionResult {
        $runUid = HarnessPersistenceValue::uid($runUid, 'run_uid');
        $owner = HarnessPersistenceValue::identifier($owner, 'lease_owner', 120);
        $activeLockKey = HarnessPersistenceValue::optionalAsciiString(
            $activeLockKey,
            'active_lock_key',
            191
        );
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'lease_seconds',
            15,
            3600
        );

        return $this->db->transactional(function () use (
            $runUid,
            $owner,
            $leaseSeconds,
            $activeLockKey
        ): GenerationTransitionResult {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $row = $this->db->fetchOne(
                'SELECT * FROM generation_runs WHERE run_uid=? FOR UPDATE',
                [$runUid]
            );
            if ($row === null) {
                return $this->claimTransition(GenerationRunClaimKind::NONE, null, []);
            }
            $this->admissionGuard?->assertStoredRunAdmitted($this->db, $row);
            $prior = GenerationRun::fromRow($row);

            $expiredRunning = $prior->state === GenerationRunState::RUNNING
                && $prior->leaseExpiresAt !== null
                && $prior->leaseExpiresAt < $nowSql;
            $scheduled = in_array($prior->state, [
                GenerationRunState::QUEUED,
                GenerationRunState::RETRY_WAIT,
            ], true) && $prior->availableAt <= $nowSql;

            if ($prior->cancelRequested) {
                if (!($scheduled || $expiredRunning)) {
                    return $this->claimTransition(GenerationRunClaimKind::NONE, null, []);
                }
                $affected = $this->db->execute(
                    "UPDATE generation_runs
                     SET state='canceled', active_lock_key=NULL,
                         lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                         error_code=NULL, error_message=NULL,
                         completed_at=?, updated_at=?
                     WHERE id=? AND fence_generation=? AND cancel_requested=1",
                    [$nowSql, $nowSql, $prior->id, $prior->fenceGeneration]
                );
                if ($affected !== 1) {
                    throw new HarnessLeaseLostException(
                        'Canceled generation Run claim lost its fenced CAS.'
                    );
                }
                $after = $this->requireRun($prior->id, true);
                return $this->claimTransition(
                    GenerationRunClaimKind::AUTO_CANCELED,
                    null,
                    [$this->claimTerminalFact($prior, $after, 'run.canceled')]
                );
            }
            $commitRecovery = $prior->attempt === $prior->maxAttempts
                && ($scheduled || $expiredRunning)
                && $this->db->fetchOne(
                    'SELECT id FROM chapter_commit_ledger WHERE run_id=? AND chapter_id=?',
                    [$prior->id, $prior->chapterId]
                ) !== null;
            if ($prior->attempt >= $prior->maxAttempts && !$commitRecovery) {
                if (!($scheduled || $expiredRunning)) {
                    return $this->claimTransition(GenerationRunClaimKind::NONE, null, []);
                }
                $affected = $this->db->execute(
                    "UPDATE generation_runs
                     SET state='failed', active_lock_key=NULL,
                         lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                         error_code='max_attempts_exhausted',
                         error_message='Run exhausted its durable attempt budget.',
                         completed_at=?, updated_at=?
                     WHERE id=? AND fence_generation=? AND cancel_requested=0",
                    [$nowSql, $nowSql, $prior->id, $prior->fenceGeneration]
                );
                if ($affected !== 1) {
                    throw new HarnessLeaseLostException(
                        'Exhausted generation Run claim lost its fenced CAS.'
                    );
                }
                $after = $this->requireRun($prior->id, true);
                return $this->claimTransition(
                    GenerationRunClaimKind::EXHAUSTED,
                    null,
                    [$this->claimTerminalFact($prior, $after, 'run.failed')]
                );
            }
            if (!$scheduled && !$expiredRunning) {
                return $this->claimTransition(GenerationRunClaimKind::NONE, null, []);
            }
            if ($activeLockKey !== null
                && $prior->activeLockKey !== null
                && !hash_equals($prior->activeLockKey, $activeLockKey)) {
                throw new HarnessIdempotencyConflictException(
                    'Claim requested a different active writer lock for this Run.'
                );
            }

            $token = HarnessPersistenceValue::uid(($this->tokenFactory)(), 'lease_token');
            $expiresSql = HarnessPersistenceValue::mysqlDate(
                $now->modify("+{$leaseSeconds} seconds")
            );
            $affected = $this->db->execute(
                "UPDATE generation_runs
                 SET state='running', attempt=attempt+1,
                     fence_generation=fence_generation+1,
                     active_lock_key=COALESCE(active_lock_key, ?),
                     lease_owner=?, lease_token=?, lease_expires_at=?,
                     started_at=COALESCE(started_at, ?),
                     error_code=NULL, error_message=NULL, updated_at=?
                 WHERE id=? AND run_uid=? AND fence_generation=?
                   AND cancel_requested=0
                   AND (attempt<max_attempts OR (attempt=max_attempts AND EXISTS (
                       SELECT 1 FROM chapter_commit_ledger c
                       WHERE c.run_id=generation_runs.id
                         AND c.chapter_id=generation_runs.chapter_id)))
                   AND ((state IN ('queued','retry_wait') AND available_at<=?)
                     OR (state='running' AND lease_expires_at IS NOT NULL
                         AND lease_expires_at<?))",
                [
                    $activeLockKey,
                    $owner,
                    $token,
                    $expiresSql,
                    $nowSql,
                    $nowSql,
                    $prior->id,
                    $runUid,
                    $prior->fenceGeneration,
                    $nowSql,
                    $nowSql,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException(
                    'Generation Run targeted claim lost its fenced CAS.'
                );
            }
            $claimed = $this->db->fetchOne(
                'SELECT * FROM generation_runs WHERE id=? FOR UPDATE',
                [$prior->id]
            );
            if ($claimed === null) {
                throw new HarnessPersistenceException(
                    'Targeted generation Run disappeared after claim.'
                );
            }
            $claimedRun = GenerationRun::fromRow($claimed);
            if ($activeLockKey !== null
                && $claimedRun->activeLockKey !== $activeLockKey) {
                throw new HarnessPersistenceException(
                    'Targeted claim did not acquire the requested writer lock.'
                );
            }
            $lease = GenerationRunLease::fromRun($claimedRun);
            return $this->claimTransition(
                $this->claimKind($prior),
                $lease,
                [$this->claimedFact($lease, $prior)]
            );
        });
    }

    public function claimNextWithEvents(
        string $queue,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): ?GenerationRunLease {
        $result = (new GenerationTransitionCoordinator($this->db))->execute(
            fn (): GenerationTransitionResult =>
                $this->claimNextMutation($queue, $owner, $leaseSeconds, $activeLockKey)
        );
        return $this->claimResult($result)->lease;
    }

    public function claimByUidWithEvents(
        string $runUid,
        string $owner,
        int $leaseSeconds = 120,
        ?string $activeLockKey = null
    ): ?GenerationRunLease {
        $result = (new GenerationTransitionCoordinator($this->db))->execute(
            fn (): GenerationTransitionResult =>
                $this->claimByUidMutation($runUid, $owner, $leaseSeconds, $activeLockKey)
        );
        return $this->claimResult($result)->lease;
    }

    public function renew(GenerationRunLease $lease, int $leaseSeconds = 120): GenerationRunLease
    {
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'lease_seconds',
            15,
            3600
        );
        return $this->db->transactional(function () use ($lease, $leaseSeconds): GenerationRunLease {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $expiresSql = HarnessPersistenceValue::mysqlDate($now->modify("+{$leaseSeconds} seconds"));
            $affected = $this->db->execute(
                "UPDATE generation_runs
                 SET lease_expires_at=?, updated_at=?
                 WHERE id=? AND state='running' AND cancel_requested=0
                   AND lease_owner=? AND lease_token=? AND fence_generation=?
                   AND lease_expires_at=? AND lease_expires_at>=?",
                [
                    $expiresSql,
                    $nowSql,
                    $lease->run->id,
                    $lease->owner,
                    $lease->token,
                    $lease->generation,
                    $lease->expiresAt,
                    $nowSql,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Generation Run lease renewal lost ownership.');
            }
            $renewed = GenerationRunLease::fromRun($this->requireRun($lease->run->id, true));
            if ($renewed->owner !== $lease->owner
                || $renewed->token !== $lease->token
                || $renewed->generation !== $lease->generation
                || $renewed->expiresAt !== $expiresSql) {
                throw new HarnessPersistenceException(
                    'Renewed generation Run does not match the expected lease fence.'
                );
            }
            return $renewed;
        });
    }

    /** @param array<string,mixed>|null $result */
    public function release(
        GenerationRunLease $lease,
        GenerationRunState $next,
        int $progress,
        ?DateTimeImmutable $availableAt = null,
        ?array $result = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): GenerationRun {
        GenerationStateMachine::assertRunTransition(GenerationRunState::RUNNING, $next);
        $progress = HarnessPersistenceValue::boundedInt($progress, 'progress', 0, 100);
        if ($next === GenerationRunState::SUCCEEDED && $progress !== 100) {
            throw new InvalidArgumentException('Succeeded Run release requires 100 progress.');
        }
        if ($next === GenerationRunState::RETRY_WAIT && $availableAt === null) {
            throw new InvalidArgumentException('retry_wait Run release requires available_at.');
        }
        $errorCode = HarnessPersistenceValue::optionalString($errorCode, 'error_code', 64);
        $errorMessage = HarnessPersistenceValue::optionalString($errorMessage, 'error_message', 500);
        if ($result !== null) {
            HarnessSensitiveDataGuard::assertSafeStructure($result, 'run.result');
        }
        $resultJson = $result === null
            ? null
            : HarnessPersistenceValue::canonicalMysqlJson($result);
        if ($resultJson !== null) {
            HarnessSensitiveDataGuard::assertSafeText($resultJson, 'run.result');
        }

        return $this->db->transactional(function () use (
            $lease,
            $next,
            $progress,
            $availableAt,
            $resultJson,
            $errorCode,
            $errorMessage
        ): GenerationRun {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $availableSql = $availableAt === null
                ? null
                : HarnessPersistenceValue::mysqlDate($availableAt);
            $terminal = $next->isTerminal() ? 1 : 0;
            $affected = $this->db->execute(
                "UPDATE generation_runs
                 SET state=?, progress=?,
                     available_at=IF(? IS NULL, available_at, ?),
                     active_lock_key=NULL,
                     lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                     result_json=?, error_code=?, error_message=?,
                     completed_at=IF(?=1, ?, NULL), updated_at=?
                 WHERE id=? AND state='running'
                   AND lease_owner=? AND lease_token=? AND fence_generation=?
                   AND lease_expires_at=? AND lease_expires_at>=?
                   AND (?='canceled' OR cancel_requested=0)",
                [
                    $next->value,
                    $progress,
                    $availableSql,
                    $availableSql,
                    $resultJson,
                    $errorCode,
                    $errorMessage,
                    $terminal,
                    $nowSql,
                    $nowSql,
                    $lease->run->id,
                    $lease->owner,
                    $lease->token,
                    $lease->generation,
                    $lease->expiresAt,
                    $nowSql,
                    $next->value,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Generation Run release lost ownership.');
            }
            return $this->requireRun($lease->run->id, true);
        });
    }

    public function releaseWithTransition(
        GenerationRunLease $lease,
        GenerationRunState $next,
        int $progress,
        ?DateTimeImmutable $availableAt = null,
        ?array $result = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): GenerationTransitionResult {
        $run = $this->release(
            $lease,
            $next,
            $progress,
            $availableAt,
            $result,
            $errorCode,
            $errorMessage
        );
        return new GenerationTransitionResult($run, [new GenerationTransitionFact(
            GenerationTransitionSubject::RUN,
            $run->id,
            GenerationRunState::RUNNING->value,
            $run->state->value,
            'run_released',
            GenerationTransitionClassification::LIFECYCLE,
            $this->releaseEvent($run),
            ['attempt' => $run->attempt, 'fence_generation' => $run->fenceGeneration]
        )]);
    }

    public function releaseWithEvents(
        GenerationRunLease $lease,
        GenerationRunState $next,
        int $progress,
        ?DateTimeImmutable $availableAt = null,
        ?array $result = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): GenerationRun {
        $transition = (new GenerationTransitionCoordinator($this->db))->execute(
            fn (): GenerationTransitionResult => $this->releaseWithTransition(
                $lease,
                $next,
                $progress,
                $availableAt,
                $result,
                $errorCode,
                $errorMessage
            )
        );
        if (!$transition->value instanceof GenerationRun) {
            throw new HarnessPersistenceException('Generation Run release result is missing.');
        }
        return $transition->value;
    }

    public function requestCancelWithTransitions(string $runUid): GenerationTransitionResult
    {
        $uid = HarnessPersistenceValue::uid($runUid, 'run_uid');
        return $this->db->transactional(function () use ($uid): GenerationTransitionResult {
            $before = $this->findByUid($uid, true);
            if ($before === null || $before->state->isTerminal()) {
                return new GenerationTransitionResult(false, []);
            }
            $changed = $this->requestCancel($uid);
            if (!$changed) {
                return new GenerationTransitionResult(false, []);
            }
            $after = $this->findByUid($uid, true)
                ?? throw new HarnessPersistenceException('Canceled generation Run disappeared.');
            $intent = new GenerationTransitionFact(
                GenerationTransitionSubject::RUN,
                $after->id,
                $before->state->value,
                $before->state->value,
                'cancel_requested',
                GenerationTransitionClassification::INTENT,
                $this->cancelEvent($after, 'run.cancel_requested', $before->state->value),
                ['cancel_requested' => true]
            );
            $facts = [$intent];
            if ($after->state === GenerationRunState::CANCELED) {
                $facts[] = new GenerationTransitionFact(
                    GenerationTransitionSubject::RUN,
                    $after->id,
                    $before->state->value,
                    GenerationRunState::CANCELED->value,
                    'cancel_finalized',
                    GenerationTransitionClassification::LIFECYCLE,
                    $this->cancelEvent($after, 'run.canceled', GenerationRunState::CANCELED->value),
                    ['cancel_requested' => true]
                );
            }
            return new GenerationTransitionResult(true, $facts);
        });
    }

    public function requestCancelWithEvents(string $runUid): bool
    {
        $result = (new GenerationTransitionCoordinator($this->db))->execute(
            fn (): GenerationTransitionResult => $this->requestCancelWithTransitions($runUid)
        );
        return $result->value === true;
    }

    public function requestCancel(string $runUid): bool
    {
        $uid = HarnessPersistenceValue::uid($runUid, 'run_uid');
        return $this->db->transactional(function () use ($uid): bool {
            $nowSql = HarnessPersistenceValue::mysqlDate($this->nowValue());
            $canceled = $this->db->execute(
                "UPDATE generation_runs
                 SET state='canceled', cancel_requested=1, active_lock_key=NULL,
                     lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                     error_code=NULL, error_message=NULL,
                     completed_at=?, updated_at=?
                 WHERE run_uid=?
                   AND (state IN ('queued','retry_wait','waiting_approval','quarantined')
                     OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<?))",
                [$nowSql, $nowSql, $uid, $nowSql]
            );
            if ($canceled === 1) {
                return true;
            }

            return $this->db->execute(
                "UPDATE generation_runs
                 SET cancel_requested=1, updated_at=?
                 WHERE run_uid=? AND state='running' AND cancel_requested=0
                   AND lease_token IS NOT NULL AND lease_expires_at>=?",
                [$nowSql, $uid, $nowSql]
            ) === 1;
        });
    }

    /** @param list<GenerationTransitionFact> $facts */
    private function claimTransition(
        GenerationRunClaimKind $kind,
        ?GenerationRunLease $lease,
        array $facts
    ): GenerationTransitionResult {
        return new GenerationTransitionResult(
            new GenerationRunClaimResult($kind, $lease, $facts),
            $facts
        );
    }

    private function claimResult(GenerationTransitionResult $result): GenerationRunClaimResult
    {
        if (!$result->value instanceof GenerationRunClaimResult) {
            throw new HarnessPersistenceException('Generation Run claim result is missing.');
        }
        return $result->value;
    }

    private function claimKind(GenerationRun $before): GenerationRunClaimKind
    {
        if ($before->attempt === $before->maxAttempts) {
            return GenerationRunClaimKind::RECOVERY;
        }
        return $before->state === GenerationRunState::RUNNING
            ? GenerationRunClaimKind::RECLAIMED
            : GenerationRunClaimKind::CLAIMED;
    }

    private function claimTerminalFact(
        GenerationRun $before,
        GenerationRun $after,
        string $eventType
    ): GenerationTransitionFact {
        return new GenerationTransitionFact(
            GenerationTransitionSubject::RUN,
            $after->id,
            $before->state->value,
            $after->state->value,
            'claim_auto_finalized',
            GenerationTransitionClassification::LIFECYCLE,
            $this->terminalClaimEvent($after, $eventType),
            ['attempt' => $after->attempt, 'fence_generation' => $after->fenceGeneration]
        );
    }

    private function claimedFact(
        GenerationRunLease $lease,
        ?GenerationRun $before
    ): GenerationTransitionFact {
        $reclaimed = $before?->state === GenerationRunState::RUNNING;
        $recovery = $before !== null && $before->attempt === $before->maxAttempts;
        $run = $lease->run;
        return new GenerationTransitionFact(
            GenerationTransitionSubject::RUN,
            $run->id,
            $before?->state->value ?? GenerationRunState::QUEUED->value,
            GenerationRunState::RUNNING->value,
            'run_claimed',
            GenerationTransitionClassification::OWNERSHIP,
            new GenerationEventDraft(
                $run->id,
                null,
                null,
                'run.claimed',
                1,
                GenerationEventSeverity::INFO,
                'run.claimed:' . $run->fenceGeneration,
                'worker',
                $lease->owner,
                null,
                false,
                [
                    'attempt' => $run->attempt,
                    'fence_generation' => $run->fenceGeneration,
                    'lease_owner' => $lease->owner,
                    'reclaimed' => $reclaimed,
                    'recovery' => $recovery,
                ]
            ),
            ['attempt' => $run->attempt, 'fence_generation' => $run->fenceGeneration]
        );
    }

    private function releaseEvent(GenerationRun $run): GenerationEventDraft
    {
        $eventType = match ($run->state) {
            GenerationRunState::SUCCEEDED => 'run.completed',
            GenerationRunState::RETRY_WAIT => 'run.retry_scheduled',
            GenerationRunState::FAILED => 'run.failed',
            GenerationRunState::CANCELED => 'run.canceled',
            default => throw new LogicException('Run release state has no lifecycle Event contract.'),
        };
        return new GenerationEventDraft(
            $run->id,
            null,
            null,
            $eventType,
            1,
            match ($run->state) {
                GenerationRunState::FAILED => GenerationEventSeverity::ERROR,
                GenerationRunState::CANCELED => GenerationEventSeverity::WARNING,
                default => GenerationEventSeverity::INFO,
            },
            $eventType . ':release:' . $run->fenceGeneration . ':' . $run->attempt,
            'worker',
            null,
            null,
            true,
            [
                'state' => $run->state->value,
                'progress' => $run->progress,
            ]
        );
    }

    private function terminalClaimEvent(
        GenerationRun $run,
        string $eventType
    ): GenerationEventDraft {
        return new GenerationEventDraft(
            $run->id,
            null,
            null,
            $eventType,
            1,
            $eventType === 'run.failed'
                ? GenerationEventSeverity::ERROR
                : GenerationEventSeverity::WARNING,
            $eventType . ':claim:' . $run->fenceGeneration . ':' . $run->attempt,
            'system',
            null,
            null,
            true,
            [
                'state' => $run->state->value,
                'progress' => $run->progress,
                'cancel_requested' => $run->cancelRequested,
            ]
        );
    }

    private function cancelEvent(
        GenerationRun $run,
        string $eventType,
        string $state
    ): GenerationEventDraft {
        return new GenerationEventDraft(
            $run->id,
            null,
            null,
            $eventType,
            1,
            $eventType === 'run.canceled'
                ? GenerationEventSeverity::WARNING
                : GenerationEventSeverity::INFO,
            $eventType . ':' . $run->fenceGeneration . ':' . $run->attempt,
            'system',
            null,
            null,
            true,
            [
                'state' => $state,
                'progress' => $run->progress,
                'cancel_requested' => true,
            ]
        );
    }

    private function requireRun(int $id, bool $lockForUpdate): GenerationRun
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM generation_runs WHERE id=?' . ($lockForUpdate ? ' FOR UPDATE' : ''),
            [HarnessPersistenceValue::positiveInt($id, 'run_id')]
        );
        if ($row === null) {
            throw new HarnessPersistenceException('Generation Run does not exist.');
        }
        return GenerationRun::fromRow($row);
    }

    private function nowValue(): DateTimeImmutable
    {
        if ($this->now === null) {
            $row = $this->db->fetchOne('SELECT UTC_TIMESTAMP(6) AS now_utc');
            $value = HarnessPersistenceValue::optionalMysqlDate(
                $row['now_utc'] ?? null,
                'database_now_utc'
            );
            if ($value === null) {
                throw new HarnessPersistenceException(
                    'Generation Run database clock did not return UTC_TIMESTAMP(6).'
                );
            }
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new HarnessPersistenceException('Generation Run clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }
}
