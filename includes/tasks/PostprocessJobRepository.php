<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/quality/WritePolicySnapshot.php';
require_once dirname(__DIR__) . '/harness/support/HarnessRuntime.php';
require_once __DIR__ . '/PostprocessLeaseLostException.php';
require_once dirname(__DIR__) . '/harness/jobs/LegacyJobShadow.php';

final class PostprocessJobRepository
{
    public static function enqueue(
        int $novelId,
        int $chapterId,
        string $revisionHash,
        string $stage = 'full',
        int $maxAttempts = 3,
        ?WritePolicySnapshot $policySnapshot = null,
        ?string $acceptedCandidateUid = null,
        bool $rewriteAllowed = false
    ): int {
        self::assertIdentity($novelId, $chapterId, $revisionHash, $stage);
        self::assertOptionalCandidateUid($acceptedCandidateUid);
        if ($rewriteAllowed && $acceptedCandidateUid === null) {
            throw new InvalidArgumentException(
                'Only a Candidate-owned Canon job may be eligible for rewrite.'
            );
        }
        $snapshotJson = $policySnapshot?->toJson();
        $snapshotHash = $policySnapshot?->checksum();
        $jobUid = self::jobUid(
            $chapterId,
            $revisionHash,
            $stage,
            $acceptedCandidateUid
        );

        // Fast-path conflict detection avoids changing the retry state of an
        // existing idempotent job when a caller presents a different frozen
        // policy. The post-write check below remains authoritative for races.
        $existing = DB::fetch(
            'SELECT novel_id, chapter_id, revision_hash, stage, state,
                    policy_snapshot_json, policy_snapshot_hash,
                    accepted_candidate_uid, job_uid, rewrite_allowed
             FROM postprocess_jobs
             WHERE job_uid=? LIMIT 1',
            [$jobUid]
        );
        if (is_array($existing)) {
            self::assertExpectedCoordinates(
                $existing,
                $novelId,
                $chapterId,
                $revisionHash,
                $stage
            );
            self::assertEnqueueSnapshotPreflight($existing, $policySnapshot);
            self::assertEnqueueCandidatePreflight($existing, $acceptedCandidateUid);
            self::assertExpectedRewriteEligibility($existing, $rewriteAllowed);
        }
        // Duplicate enqueue is state-idempotent: resolve the durable id and only
        // raise max_attempts monotonically. Existing lifecycle state, attempt,
        // backoff, lease, error, and completion fields must remain untouched.
        DB::execute(
            "INSERT INTO postprocess_jobs
                (novel_id, chapter_id, revision_hash, stage, state, attempt,
                  max_attempts, available_at, policy_snapshot_json,
                  policy_snapshot_hash, accepted_candidate_uid, rewrite_allowed)
             VALUES (?, ?, ?, ?, 'queued', 0, ?, NOW(), ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
                 id=LAST_INSERT_ID(id),
                 max_attempts=GREATEST(max_attempts, VALUES(max_attempts))",
            [
                $novelId,
                $chapterId,
                $revisionHash,
                $stage,
                max(1, min(20, $maxAttempts)),
                $snapshotJson,
                $snapshotHash,
                $acceptedCandidateUid,
                $rewriteAllowed ? 1 : 0,
            ]
        );
        $jobId = (int)DB::lastId();

        // A legacy idempotent row may have been created before policy
        // persistence existed. Attach the pair atomically only while both
        // columns are NULL and no worker owns the running lease. A concurrent
        // different snapshot can win this CAS, but it can never be overwritten;
        // the validation below then fails authoritatively.
        if ($policySnapshot !== null) {
            DB::execute(
                "UPDATE postprocess_jobs
                 SET policy_snapshot_json=?, policy_snapshot_hash=?
                 WHERE id=? AND state<>'running'
                   AND lease_owner IS NULL
                   AND policy_snapshot_json IS NULL AND policy_snapshot_hash IS NULL",
                [$snapshotJson, $snapshotHash, $jobId]
            );
        }

        $persisted = self::findById($jobId);
        if ($persisted === null) {
            throw new RuntimeException('Post-processing job disappeared after enqueue.');
        }
        self::assertExpectedSnapshot($persisted, $policySnapshot);
        self::assertExpectedCoordinates(
            $persisted,
            $novelId,
            $chapterId,
            $revisionHash,
            $stage
        );
        self::assertExpectedCandidate($persisted, $acceptedCandidateUid);
        self::assertExpectedJobUid($persisted, $jobUid);
        self::assertExpectedRewriteEligibility($persisted, $rewriteAllowed);

        LegacyJobShadowBridge::tryMap(LegacyJobShadowMapper::POSTPROCESS, $jobId);

        return $jobId;
    }

    /**
     * Restore a frozen policy from a job row.
     *
     * Both NULL is the only accepted representation of a pre-M60 legacy job.
     * A missing column, a half-written pair, malformed JSON, or any checksum
     * disagreement is corruption and must stop post-processing.
     *
     * @param array<string,mixed> $row
     */
    public static function snapshotFromRow(array $row): ?WritePolicySnapshot
    {
        if (
            !array_key_exists('policy_snapshot_json', $row)
            || !array_key_exists('policy_snapshot_hash', $row)
        ) {
            throw new RuntimeException('Post-processing job is missing frozen policy columns.');
        }

        $json = $row['policy_snapshot_json'];
        $hash = $row['policy_snapshot_hash'];
        if ($json === null && $hash === null) {
            return null;
        }
        if (!is_string($json) || $json === '' || !is_string($hash) || $hash === '') {
            throw new RuntimeException('Post-processing job has an incomplete frozen policy snapshot.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Post-processing job has an invalid frozen policy hash.');
        }

        try {
            $snapshot = WritePolicySnapshot::fromJson($json);
        } catch (Throwable $e) {
            throw new RuntimeException('Post-processing job has an invalid frozen policy snapshot.', 0, $e);
        }

        if (!hash_equals($snapshot->checksum(), $hash)) {
            throw new RuntimeException('Post-processing job frozen policy hash mismatch.');
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $row */
    public static function acceptedCandidateFromRow(array $row): ?string
    {
        if (!array_key_exists('accepted_candidate_uid', $row)) {
            throw new RuntimeException('Post-processing job is missing accepted Candidate metadata.');
        }
        $candidateUid = $row['accepted_candidate_uid'];
        if ($candidateUid === null) {
            return null;
        }
        if (!is_string($candidateUid) || preg_match('/^[a-f0-9]{32}$/D', $candidateUid) !== 1) {
            throw new RuntimeException('Post-processing job has an invalid accepted Candidate UID.');
        }
        return $candidateUid;
    }

    /** @param array<string,mixed> $row */
    public static function rewriteAllowedFromRow(array $row): bool
    {
        if (!array_key_exists('rewrite_allowed', $row)) {
            throw new RuntimeException('Post-processing job is missing rewrite eligibility.');
        }
        $value = $row['rewrite_allowed'];
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        throw new RuntimeException('Post-processing job has invalid rewrite eligibility.');
    }

    public static function jobUid(
        int $chapterId,
        string $revisionHash,
        string $stage,
        ?string $acceptedCandidateUid
    ): string {
        if ($chapterId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $revisionHash) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $stage) !== 1) {
            throw new InvalidArgumentException('Invalid post-processing job UID input.');
        }
        self::assertOptionalCandidateUid($acceptedCandidateUid);
        $owner = $acceptedCandidateUid === null
            ? "legacy:{$chapterId}:{$revisionHash}"
            : "candidate:{$acceptedCandidateUid}";
        return substr(hash('sha256', "postprocess_job_v2:{$owner}:{$stage}"), 0, 32);
    }

    public static function findById(int $jobId): ?array
    {
        if ($jobId <= 0) return null;
        $row = DB::fetch('SELECT * FROM postprocess_jobs WHERE id=? LIMIT 1', [$jobId]);
        return is_array($row) ? $row : null;
    }

    public static function claimNext(string $workerId, int $leaseSeconds = 1800): ?array
    {
        return self::claim(null, $workerId, $leaseSeconds);
    }

    public static function claimById(int $jobId, string $workerId, int $leaseSeconds = 1800): ?array
    {
        if ($jobId <= 0) return null;
        return self::claim($jobId, $workerId, $leaseSeconds);
    }

    /**
     * Finish only the exact owner/token/generation returned by claim. There is
     * intentionally no owner-only compatibility path: a reclaimed lease can
     * reuse the same worker id, while its token and generation must be new.
     */
    public static function markDone(
        int $jobId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration,
        ?string $acceptedCandidateUid = null
    ): bool {
        self::assertOptionalCandidateUid($acceptedCandidateUid);
        [$leaseOwner, $leaseToken, $fenceGeneration] = self::normalizeLeaseIdentity(
            $jobId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );

        return DB::execute(
            "UPDATE postprocess_jobs
             SET state='done', completed_at=NOW(), lease_owner=NULL,
                 lease_token=NULL, lease_expires_at=NULL, last_error=NULL
             WHERE id=? AND state='running' AND lease_owner=?
               AND lease_token=? AND fence_generation=?
               AND accepted_candidate_uid <=> ?",
            [$jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid]
        ) > 0;
    }

    /** @return 'retry'|'failed'|null NULL means that the exact lease was lost. */
    public static function markRetryOrFailed(
        int $jobId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration,
        string $error,
        int $baseDelaySeconds = 30
    ): ?string {
        [$leaseOwner, $leaseToken, $fenceGeneration] = self::normalizeLeaseIdentity(
            $jobId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        $row = DB::fetch(
            "SELECT attempt, max_attempts FROM postprocess_jobs
             WHERE id=? AND state='running' AND lease_owner=?
               AND lease_token=? AND fence_generation=? LIMIT 1",
            [$jobId, $leaseOwner, $leaseToken, $fenceGeneration]
        );
        if (!is_array($row)) {
            return null;
        }

        $attempt = (int)($row['attempt'] ?? 0);
        $maxAttempts = max(1, (int)($row['max_attempts'] ?? 3));
        $failed = $attempt >= $maxAttempts;
        $delay = min(3600, max(5, $baseDelaySeconds) * (2 ** max(0, $attempt - 1)));
        $availableAt = HarnessRuntime::sqlAfterSeconds($delay);
        HarnessRuntime::fault(HarnessFaultPoints::POSTPROCESS_RETRY_BEFORE_WRITE, [
            'job_id' => $jobId,
            'attempt' => $attempt,
            'terminal' => $failed,
        ]);
        $updated = DB::execute(
            'UPDATE postprocess_jobs
             SET state=?, available_at=?, last_error=?, lease_owner=NULL,
                 lease_token=NULL, lease_expires_at=NULL,
                 completed_at=IF(?="failed", NOW(), NULL)
             WHERE id=? AND state="running" AND lease_owner=?
               AND lease_token=? AND fence_generation=?',
            [
                $failed ? 'failed' : 'retry',
                $availableAt,
                self::limit($error, 4000),
                $failed ? 'failed' : 'retry',
                $jobId,
                $leaseOwner,
                $leaseToken,
                $fenceGeneration,
            ]
        );
        if ($updated !== 1) {
            return null;
        }
        return $failed ? 'failed' : 'retry';
    }

    public static function extendLease(
        int $jobId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration,
        int $leaseSeconds = 1800
    ): bool {
        [$leaseOwner, $leaseToken, $fenceGeneration] = self::normalizeLeaseIdentity(
            $jobId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        return DB::execute(
            "UPDATE postprocess_jobs
             SET lease_expires_at=GREATEST(?, DATE_ADD(lease_expires_at, INTERVAL 1 SECOND))
             WHERE id=? AND state='running' AND lease_owner=?
               AND lease_token=? AND fence_generation=?",
            [
                HarnessRuntime::sqlAfterSeconds(max(60, $leaseSeconds)),
                $jobId,
                $leaseOwner,
                $leaseToken,
                $fenceGeneration,
            ]
        ) > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{owner:string,token:string,generation:int}
     */
    public static function leaseIdentityFromRow(array $row): array
    {
        if ((string)($row['state'] ?? '') !== 'running') {
            throw new RuntimeException('Post-processing job is not running.');
        }
        $jobId = (int)($row['id'] ?? 0);
        $owner = $row['lease_owner'] ?? null;
        $token = $row['lease_token'] ?? null;
        $generation = $row['fence_generation'] ?? null;
        if (!is_string($owner) || !is_string($token)
            || (!is_int($generation) && !is_string($generation))) {
            throw new RuntimeException('Post-processing job has incomplete lease fencing.');
        }
        if (is_string($generation) && preg_match('/^[1-9][0-9]*$/D', $generation) !== 1) {
            throw new RuntimeException('Post-processing job has an invalid fence generation.');
        }
        if (is_string($generation)) {
            $rawGeneration = $generation;
            $generation = (int)$rawGeneration;
            if ((string)$generation !== $rawGeneration) {
                throw new RuntimeException('Post-processing fence generation is out of range.');
            }
        }
        try {
            [$owner, $token, $generation] = self::normalizeLeaseIdentity(
                $jobId,
                $owner,
                $token,
                $generation
            );
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('Post-processing job has invalid lease fencing.', 0, $e);
        }
        return ['owner' => $owner, 'token' => $token, 'generation' => $generation];
    }

    /**
     * Verify the exact lease, optionally locking it through the caller's
     * surrounding transaction. Canon promotion uses the locking form as its
     * final predecessor-job fence.
     */
    public static function assertLeaseOwned(
        int $jobId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration,
        bool $lockForUpdate = false
    ): void {
        [$leaseOwner, $leaseToken, $fenceGeneration] = self::normalizeLeaseIdentity(
            $jobId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        if ($lockForUpdate && !DB::connect()->inTransaction()) {
            throw new LogicException('Locking lease authentication requires an active transaction.');
        }
        $sql = "SELECT id FROM postprocess_jobs
                WHERE id=? AND state='running' AND lease_owner=?
                  AND lease_token=? AND fence_generation=? LIMIT 1";
        if ($lockForUpdate) {
            $sql .= ' FOR UPDATE';
        }
        if (!is_array(DB::fetch($sql, [$jobId, $leaseOwner, $leaseToken, $fenceGeneration]))) {
            throw new WriteEnginePostprocessLeaseLostException(
                'Post-processing lease was lost to a newer fence generation.'
            );
        }
    }

    private static function claim(?int $jobId, string $workerId, int $leaseSeconds): ?array
    {
        $pdo = DB::connect();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Post-processing claims require an independent transaction.');
        }

        $pdo->beginTransaction();
        try {
            $claimNow = HarnessRuntime::sqlNow();
            // A worker that dies while holding its final permitted attempt can
            // never satisfy the claim query's attempt<max_attempts predicate.
            // Close that expired lease under the same row-lock transaction so it
            // becomes an explicit failed terminal state instead of permanent
            // running limbo. Fence generation is intentionally monotonic.
            $reaperSql = "UPDATE postprocess_jobs
                          SET state='failed', completed_at=NOW(),
                              last_error=COALESCE(NULLIF(last_error,''),
                                  'Lease expired after final attempt'),
                              lease_owner=NULL, lease_token=NULL,
                              lease_expires_at=NULL
                          WHERE state='running' AND attempt>=max_attempts
                            AND lease_expires_at IS NOT NULL
                            AND lease_expires_at<?";
            $reaperParams = [$claimNow];
            if ($jobId !== null) {
                $reaperSql .= ' AND id=?';
                $reaperParams[] = $jobId;
            }
            DB::execute($reaperSql, $reaperParams);

            $params = [];
            $idFilter = '';
            if ($jobId !== null) {
                $idFilter = 'id=? AND ';
                $params[] = $jobId;
            }
            $row = DB::fetch(
                "SELECT * FROM postprocess_jobs
                 WHERE {$idFilter}attempt < max_attempts
                   AND ((state IN ('queued','retry') AND available_at <= ?)
                     OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at < ?))
                   AND NOT EXISTS (
                     SELECT 1 FROM postprocess_jobs AS active_sibling
                     WHERE active_sibling.chapter_id=postprocess_jobs.chapter_id
                       AND active_sibling.id<>postprocess_jobs.id
                       AND active_sibling.state='running'
                       AND active_sibling.lease_expires_at IS NOT NULL
                       AND active_sibling.lease_expires_at >= ?
                   )
                 ORDER BY available_at, id LIMIT 1 FOR UPDATE",
                // 审计修复（2026-07-19 M1-4）：过期判定使用统一 PHP 时间源，与
                // available_at/lease_expires_at 写入端一致，避免 NOW() 时区偏移。
                // 同章任务还要串行化：旧 revision worker 晋升新 Canon 后会在事务内
                // 生成 successor；旧 worker 完成 fresh-Canon 投影前，successor 不得并跑
                // 非幂等 downstream safeRun。租约过期后守卫自然释放，保留崩溃恢复。
                array_merge($params, [
                    $claimNow,
                    $claimNow,
                    $claimNow,
                ])
            );
            if (!$row) {
                $pdo->commit();
                return null;
            }
            HarnessRuntime::fault(HarnessFaultPoints::POSTPROCESS_CLAIM_AFTER_LOCK, [
                'job_id' => (int)$row['id'],
            ]);

            $workerId = self::cleanWorkerId($workerId);
            $leaseToken = HarnessRuntime::randomHex(16);
            $rawGeneration = $row['fence_generation'] ?? null;
            if (is_string($rawGeneration)) {
                if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawGeneration) !== 1) {
                    throw new RuntimeException('Post-processing job has an invalid fence generation.');
                }
                $previousGeneration = (int)$rawGeneration;
                if ((string)$previousGeneration !== $rawGeneration) {
                    throw new RuntimeException('Post-processing fence generation is out of range.');
                }
            } elseif (is_int($rawGeneration) && $rawGeneration >= 0) {
                $previousGeneration = $rawGeneration;
            } else {
                throw new RuntimeException('Post-processing job has an invalid fence generation.');
            }
            $updated = DB::execute(
                "UPDATE postprocess_jobs
                 SET state='running', attempt=attempt+1, lease_owner=?, lease_expires_at=?,
                     lease_token=?, fence_generation=fence_generation+1,
                     started_at=COALESCE(started_at, NOW()), last_error=NULL
                 WHERE id=? AND fence_generation=?",
                [
                    $workerId,
                    HarnessRuntime::sqlAfterSeconds(max(60, $leaseSeconds)),
                    $leaseToken,
                    (int)$row['id'],
                    $previousGeneration,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Post-processing claim lost its fence CAS.');
            }
            HarnessRuntime::fault(HarnessFaultPoints::POSTPROCESS_CLAIM_AFTER_CAS, [
                'job_id' => (int)$row['id'],
                'fence_generation' => $previousGeneration + 1,
            ]);
            $claimed = DB::fetch(
                'SELECT * FROM postprocess_jobs WHERE id=? LIMIT 1',
                [(int)$row['id']]
            );
            if (!is_array($claimed)) {
                throw new RuntimeException('Post-processing job disappeared during claim.');
            }
            $identity = self::leaseIdentityFromRow($claimed);
            if (!hash_equals($leaseToken, $identity['token'])
                || $identity['generation'] !== $previousGeneration + 1) {
                throw new RuntimeException('Post-processing claim returned inconsistent lease fencing.');
            }
            $pdo->commit();
            return $claimed;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function assertIdentity(int $novelId, int $chapterId, string $hash, string $stage): void
    {
        if ($novelId <= 0 || $chapterId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new InvalidArgumentException('Invalid post-processing job identity.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $stage)) {
            throw new InvalidArgumentException('Invalid post-processing stage.');
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertExpectedSnapshot(
        array $row,
        ?WritePolicySnapshot $expected
    ): void {
        $persisted = self::snapshotFromRow($row);
        if ($expected === null) {
            return;
        }
        if ($persisted === null) {
            throw new RuntimeException('Post-processing job did not persist its frozen policy snapshot.');
        }
        if (!hash_equals($expected->checksum(), $persisted->checksum())) {
            throw new RuntimeException(
                'Post-processing job already exists with a different frozen policy snapshot.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertExpectedCoordinates(
        array $row,
        int $novelId,
        int $chapterId,
        string $revisionHash,
        string $stage
    ): void {
        $persistedNovelId = filter_var(
            $row['novel_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $persistedChapterId = filter_var(
            $row['chapter_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $persistedRevision = $row['revision_hash'] ?? null;
        $persistedStage = $row['stage'] ?? null;
        if ($persistedNovelId !== $novelId
            || $persistedChapterId !== $chapterId
            || !is_string($persistedRevision)
            || !hash_equals($revisionHash, $persistedRevision)
            || !is_string($persistedStage)
            || !hash_equals($stage, $persistedStage)) {
            throw new RuntimeException(
                'Post-processing job UID resolved to different immutable coordinates.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertExpectedCandidate(array $row, ?string $expected): void
    {
        $persisted = self::acceptedCandidateFromRow($row);
        if ($expected === null) {
            if ($persisted !== null) {
                throw new RuntimeException(
                    'Legacy post-processing job unexpectedly belongs to a Candidate.'
                );
            }
            return;
        }
        if ($persisted === null || !hash_equals($expected, $persisted)) {
            throw new RuntimeException(
                'Post-processing job did not persist the accepted Candidate identity.'
            );
        }
    }

    /**
     * Preflight differs deliberately from the authoritative post-CAS check:
     * a non-running legacy row with a NULL pair is allowed to reach the CAS,
     * while a running row is immutable for the lifetime of its lease.
     *
     * @param array<string,mixed> $row
     */
    private static function assertEnqueueSnapshotPreflight(
        array $row,
        ?WritePolicySnapshot $expected
    ): void {
        $persisted = self::snapshotFromRow($row);
        if ($expected === null) {
            return;
        }
        if ($persisted === null) {
            if (!array_key_exists('state', $row) || !is_string($row['state'])) {
                throw new RuntimeException('Post-processing job is missing a valid state.');
            }
            if ($row['state'] === 'running') {
                throw new RuntimeException(
                    'Cannot attach a frozen policy snapshot to a running post-processing job.'
                );
            }
            return;
        }
        if (!hash_equals($expected->checksum(), $persisted->checksum())) {
            throw new RuntimeException(
                'Post-processing job already exists with a different frozen policy snapshot.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertEnqueueCandidatePreflight(array $row, ?string $expected): void
    {
        $persisted = self::acceptedCandidateFromRow($row);
        if ($expected === null) {
            if ($persisted !== null) {
                throw new RuntimeException(
                    'Legacy post-processing job unexpectedly belongs to a Candidate.'
                );
            }
            return;
        }
        if ($persisted === null || !hash_equals($expected, $persisted)) {
            throw new RuntimeException(
                'Post-processing job already belongs to a different accepted Candidate.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertExpectedJobUid(array $row, string $expected): void
    {
        $persisted = $row['job_uid'] ?? null;
        if (!is_string($persisted)
            || preg_match('/^[a-f0-9]{32}$/D', $persisted) !== 1
            || !hash_equals($expected, $persisted)) {
            throw new RuntimeException('Post-processing job identity key mismatch.');
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertExpectedRewriteEligibility(array $row, bool $expected): void
    {
        if (self::rewriteAllowedFromRow($row) !== $expected) {
            throw new RuntimeException(
                'Post-processing job already exists with different rewrite eligibility.'
            );
        }
    }

    private static function assertOptionalCandidateUid(?string $candidateUid): void
    {
        if ($candidateUid !== null && preg_match('/^[a-f0-9]{32}$/D', $candidateUid) !== 1) {
            throw new InvalidArgumentException(
                'accepted_candidate_uid must be a lowercase 32-character hex UID.'
            );
        }
    }

    /** @return array{string,string,int} */
    private static function normalizeLeaseIdentity(
        int $jobId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration
    ): array {
        if ($jobId <= 0) {
            throw new InvalidArgumentException('Post-processing lease requires a positive job id.');
        }
        $normalizedOwner = self::cleanWorkerId($leaseOwner);
        if ($leaseOwner === '' || !hash_equals($normalizedOwner, $leaseOwner)) {
            throw new InvalidArgumentException('Post-processing lease owner is not canonical.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $leaseToken) !== 1) {
            throw new InvalidArgumentException('Post-processing lease token must be lowercase 32-hex.');
        }
        if ($fenceGeneration <= 0) {
            throw new InvalidArgumentException('Post-processing fence generation must be positive.');
        }
        return [$normalizedOwner, $leaseToken, $fenceGeneration];
    }

    private static function cleanWorkerId(string $workerId): string
    {
        $workerId = preg_replace('/[^a-zA-Z0-9_.:@-]/', '_', $workerId) ?? '';
        return self::limit($workerId !== '' ? $workerId : 'unknown-worker', 120);
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
