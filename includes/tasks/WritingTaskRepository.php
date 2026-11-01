<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/quality/WritePolicySnapshot.php';
require_once dirname(__DIR__) . '/harness/support/HarnessRuntime.php';
require_once dirname(__DIR__) . '/harness/events/ShadowTracePublisher.php';
require_once dirname(__DIR__) . '/harness/jobs/LegacyJobShadow.php';

final class WritingTaskRepository
{
    private const ACTIVE_STATES = [
        'queued', 'preparing', 'generating', 'validating', 'content_saved', 'postprocessing',
    ];

    /**
     * Lease handles owned by this PHP process.
     *
     * This process-local cache keeps the historical heartbeat signature usable
     * for in-process callers such as WriteEngine::waitForPostProcess().  It is
     * never an authority: every mutation still authenticates all four durable
     * fence coordinates in SQL.
     *
     * @var array<string,array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string}>
     */
    private static array $processLeases = [];

    public static function createQueued(
        string $taskId,
        int $userId,
        int $novelId,
        ?int $chapterId = null,
        int $leaseSeconds = 120,
        ?WritePolicySnapshot $policySnapshot = null
    ): void {
        self::assertTaskId($taskId);
        if ($userId <= 0 || $novelId <= 0) {
            throw new InvalidArgumentException('Invalid writing task owner.');
        }

        $data = [
            'task_id'          => $taskId,
            'user_id'          => $userId,
            'novel_id'         => $novelId,
            'chapter_id'       => $chapterId ?: null,
            'state'            => 'queued',
            'progress'         => 0,
            'lease_expires_at' => HarnessRuntime::sqlAfterSeconds(max(30, $leaseSeconds)),
        ];
        if ($policySnapshot !== null) {
            $data['policy_snapshot_json'] = $policySnapshot->toJson();
            $data['policy_snapshot_hash'] = $policySnapshot->checksum();
        }

        DB::insert('writing_tasks', $data);
        $legacyId = (int)DB::lastId();
        ShadowTracePublisher::publishApplication(ShadowTrace::queued($taskId));
        LegacyJobShadowBridge::tryMap(LegacyJobShadowMapper::WRITING, $legacyId);
    }

    /**
     * Restore and authenticate a frozen task policy.
     *
     * A pre-M60 row may have both fields empty and is represented as null only
     * by this compatibility reader. A half-written pair, malformed JSON, a bad
     * embedded checksum, or a row hash mismatch always fails closed.
     */
    public static function optionalPolicySnapshotFromRow(array $row): ?WritePolicySnapshot
    {
        if (!array_key_exists('policy_snapshot_json', $row)
            || !array_key_exists('policy_snapshot_hash', $row)) {
            throw new RuntimeException('Writing task row is missing policy snapshot columns.');
        }
        $json = $row['policy_snapshot_json'] ?? null;
        $hash = $row['policy_snapshot_hash'] ?? null;
        $jsonMissing = $json === null || $json === '';
        $hashMissing = $hash === null || $hash === '';

        if ($jsonMissing && $hashMissing) {
            return null;
        }
        if ($jsonMissing !== $hashMissing) {
            throw new RuntimeException('Writing task policy snapshot JSON/hash pair is incomplete.');
        }
        if (!is_string($json) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Writing task policy snapshot fields are malformed.');
        }

        try {
            $snapshot = WritePolicySnapshot::fromJson($json);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Writing task policy snapshot JSON/checksum is invalid.',
                0,
                $exception
            );
        }
        if (!hash_equals($snapshot->checksum(), $hash)) {
            throw new RuntimeException('Writing task policy snapshot row hash does not match its checksum.');
        }

        return $snapshot;
    }

    public static function policySnapshotFromRow(array $row): WritePolicySnapshot
    {
        $snapshot = self::optionalPolicySnapshotFromRow($row);
        if ($snapshot === null) {
            throw new RuntimeException('Writing task has no frozen policy snapshot.');
        }
        return $snapshot;
    }

    /**
     * One-time compatibility freeze for a queued task created before M60.
     *
     * This method never overwrites an existing value. After the CAS it always
     * rereads and authenticates the durable row, including when another worker
     * won the race. A different winner is an integrity error, not config drift.
     */
    public static function freezePolicySnapshot(
        string $taskId,
        WritePolicySnapshot $snapshot,
        ?string $leaseOwner = null,
        ?string $leaseToken = null,
        ?int $fenceGeneration = null
    ): WritePolicySnapshot {
        self::assertTaskId($taskId);
        $lease = self::requireLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        $row = self::fetchPolicySnapshotRow($taskId, $lease);
        $existing = self::optionalPolicySnapshotFromRow($row);
        if ($existing !== null) {
            self::assertSamePolicySnapshot($existing, $snapshot);
            return $existing;
        }

        DB::execute(
            "UPDATE writing_tasks
             SET policy_snapshot_json=?, policy_snapshot_hash=?
             WHERE task_id=?
               AND policy_snapshot_json IS NULL
               AND policy_snapshot_hash IS NULL
               AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?",
            [
                $snapshot->toJson(),
                $snapshot->checksum(),
                $taskId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );

        $frozen = self::policySnapshotFromRow(self::fetchPolicySnapshotRow($taskId, $lease));
        self::assertSamePolicySnapshot($frozen, $snapshot);
        return $frozen;
    }

    /**
     * Freeze the exact chapter state observed at acquisition time.
     *
     * The helper intentionally does not read or mutate chapters. The worker
     * supplies the pre-acquisition status plus the post-CAS claim token, while
     * this repository only validates and durably freezes that payload.
     *
     * @param array<string,mixed> $baseSnapshot
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}
     */
    public static function freezeBaseChapterSnapshot(
        string $taskId,
        int $chapterId,
        array $baseSnapshot,
        ?string $leaseOwner = null,
        ?string $leaseToken = null,
        ?int $fenceGeneration = null
    ): array {
        self::assertTaskId($taskId);
        if ($chapterId <= 0) {
            throw new InvalidArgumentException('Invalid base chapter id.');
        }
        $normalized = self::normalizeBaseChapterSnapshot($baseSnapshot);
        $json = self::encodeJson($normalized);

        $lease = self::requireLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );

        $row = self::fetchBaseChapterSnapshotRow($taskId, $lease);
        self::assertTaskChapterCompatible($row, $chapterId);
        $existing = self::optionalBaseChapterSnapshotFromRow($row);
        if ($existing !== null) {
            if (($row['chapter_id'] ?? null) === null) {
                throw new RuntimeException(
                    'Writing task base snapshot exists without a bound chapter id.'
                );
            }
            self::assertSameBaseChapterSnapshot($existing, $normalized);
            return $existing;
        }

        DB::execute(
            "UPDATE writing_tasks
             SET chapter_id=COALESCE(chapter_id, ?), base_chapter_snapshot_json=?
             WHERE task_id=?
               AND (chapter_id IS NULL OR chapter_id=?)
               AND base_chapter_snapshot_json IS NULL
               AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?",
            [
                $chapterId,
                $json,
                $taskId,
                $chapterId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );

        $frozenRow = self::fetchBaseChapterSnapshotRow($taskId, $lease);
        self::assertTaskChapterCompatible($frozenRow, $chapterId);
        if (($frozenRow['chapter_id'] ?? null) === null) {
            throw new RuntimeException('Writing task base snapshot freeze did not bind a chapter id.');
        }
        $frozen = self::baseChapterSnapshotFromRow($frozenRow);
        self::assertSameBaseChapterSnapshot($frozen, $normalized);
        return $frozen;
    }

    /**
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}
     */
    public static function baseChapterSnapshotFromRow(array $row): array
    {
        $snapshot = self::optionalBaseChapterSnapshotFromRow($row);
        if ($snapshot === null) {
            throw new RuntimeException('Writing task has no frozen base chapter snapshot.');
        }
        return $snapshot;
    }

    public static function findByTaskId(string $taskId): ?array
    {
        self::assertTaskId($taskId);
        $row = DB::fetch('SELECT * FROM writing_tasks WHERE task_id=? LIMIT 1', [$taskId]);
        return is_array($row) ? $row : null;
    }

    public static function findActiveForNovel(int $novelId): ?array
    {
        if ($novelId <= 0) return null;

        // 审计修复（2026-07-19 M1-4）：租约过期判定改用与写入端一致的 PHP 时间源，
        // 避免与 MySQL NOW() 时区不一致时租约窗口偏移（健康任务被误杀或僵死任务永不超时）。
        $now = HarnessRuntime::sqlNow();

        // 审计修复（2026-08-02 H3）：postprocessing 是同步长任务（摘要/记忆/知识库串联可远超
        // 1800s 租约），期间无心跳源。若按统一租约过期即失败，用户在此时重新发起写作会被
        // write_start 误判"无活跃任务"而拉起第二个 worker（双进程并发）。
        // 因此对 postprocessing 额外放宽 1800 秒宽限期；其余活跃态维持原语义。
        DB::execute(
            "UPDATE writing_tasks
             SET state='failed', error_code='lease_expired',
                 error_message='Worker lease expired before reaching a terminal state',
                 completed_at=?, lease_owner=NULL, lease_token=NULL,
                 fence_generation=fence_generation+1, lease_expires_at=NULL
             WHERE novel_id=?
               AND state IN ('queued','preparing','generating','validating','content_saved')
               AND lease_expires_at IS NOT NULL AND lease_expires_at < ?",
            [$now, $novelId, $now]
        );
        // 审计修复 P0（2026-08-04）：postprocessing 孤儿任务立即清扫。
        // worker 被 kill -9/重启后无人续租，原逻辑要等满 180+1800≈33 分钟宽限才释放，
        // 期间 findActiveForNovel 持续返回死行锁住整本书。现与 postprocess_jobs 联动：
        // 租约过期且该章节不存在存活 job（queued/retry 待跑，或 running 且 job 租约仍有效）
        // → 说明没有任何进程在做后处理，立即判失败释放书籍。
        DB::execute(
            "UPDATE writing_tasks wt
             SET wt.state='failed', wt.error_code='postprocess_orphaned',
                 wt.error_message='Post-processing lease expired and no live postprocess job exists',
                 wt.completed_at=?, wt.lease_owner=NULL, wt.lease_token=NULL,
                 wt.fence_generation=wt.fence_generation+1, wt.lease_expires_at=NULL
             WHERE wt.novel_id=?
               AND wt.state='postprocessing'
               AND wt.chapter_id IS NOT NULL
               AND wt.lease_expires_at IS NOT NULL AND wt.lease_expires_at < ?
               AND NOT EXISTS (
                   SELECT 1 FROM postprocess_jobs pj
                   WHERE pj.chapter_id = wt.chapter_id
                     AND (pj.state IN ('queued','retry')
                       OR (pj.state='running' AND pj.lease_expires_at IS NOT NULL AND pj.lease_expires_at >= ?))
               )",
            [$now, $novelId, $now, $now]
        );
        // H3 宽限兜底：确有存活 job 的长后处理，超过 1800s 宽限仍强制收尾。
        DB::execute(
            "UPDATE writing_tasks
             SET state='failed', error_code='lease_expired',
                 error_message='Worker lease expired before reaching a terminal state',
                 completed_at=?, lease_owner=NULL, lease_token=NULL,
                 fence_generation=fence_generation+1, lease_expires_at=NULL
             WHERE novel_id=?
               AND state='postprocessing'
               AND lease_expires_at IS NOT NULL AND lease_expires_at < DATE_SUB(?, INTERVAL 1800 SECOND)",
            [$now, $novelId, $now]
        );

        $row = DB::fetch(
            "SELECT * FROM writing_tasks
             WHERE novel_id=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             ORDER BY id DESC LIMIT 1",
            [$novelId]
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Atomically claim or reclaim a writing task and return its durable fence.
     *
     * A queued task without an owner is the normal first claim.  An active row
     * may be reclaimed only after its prior lease expired.  The random token
     * and monotonically increasing generation make a stale worker's later
     * heartbeat/terminal write affect zero rows even when it reuses the same
     * task id and lease owner string.
     *
     * @return array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string}|null
     */
    public static function claimLease(
        string $taskId,
        ?int $chapterId,
        string $leaseOwner,
        int $leaseSeconds = 180
    ): ?array {
        self::assertTaskId($taskId);
        if ($chapterId !== null && $chapterId <= 0) {
            throw new InvalidArgumentException('Invalid writing task chapter id.');
        }

        $owner = self::cleanLeaseOwner($leaseOwner);
        $token = HarnessRuntime::randomHex(16);
        $now = HarnessRuntime::sqlNow();
        $expiresAt = HarnessRuntime::sqlAfterSeconds(max(30, $leaseSeconds));
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state='preparing', chapter_id=COALESCE(?, chapter_id),
                 attempt=attempt+1, lease_owner=?, lease_token=?,
                 fence_generation=fence_generation+1, lease_expires_at=?,
                 started_at=COALESCE(started_at, NOW()), error_code=NULL, error_message=NULL
             WHERE task_id=? AND cancel_requested=0
               AND (chapter_id IS NULL OR ? IS NULL OR chapter_id=?)
               AND (
                    (state='queued' AND lease_owner IS NULL)
                    OR (
                        state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
                        AND lease_expires_at IS NOT NULL AND lease_expires_at < ?
                    )
               )",
            [
                $chapterId,
                $owner,
                $token,
                $expiresAt,
                $taskId,
                $chapterId,
                $chapterId,
                $now,
            ]
        );
        if ($affected !== 1) {
            return null;
        }
        HarnessRuntime::fault(HarnessFaultPoints::WRITING_CLAIM_AFTER_CAS, [
            'task_id' => $taskId,
            'chapter_id' => $chapterId,
        ]);

        $row = DB::fetch(
            "SELECT task_id, lease_owner, lease_token, fence_generation, lease_expires_at
             FROM writing_tasks
             WHERE task_id=? AND lease_owner=? AND lease_token=?
               AND state='preparing' AND cancel_requested=0
               AND lease_expires_at>=?
             LIMIT 1",
            [$taskId, $owner, $token, $now]
        );
        if (!is_array($row)) {
            // A management action (normally user cancel) invalidated the claim
            // between UPDATE and readback.  Never manufacture a local lease.
            return null;
        }

        $lease = self::leaseFromRow($row, $taskId, $owner, $token);
        self::$processLeases[$taskId] = $lease;
        ShadowTracePublisher::publishApplication(ShadowTrace::claimed(
            $taskId,
            $lease['fence_generation']
        ));
        return $lease;
    }

    /**
     * Backward-compatible boolean claim wrapper.
     *
     * New production workers should call claimLease() and transport the
     * returned token/generation explicitly.  The handle is also registered in
     * process memory so older in-process heartbeat calls remain fenced.
     */
    public static function markRunning(
        string $taskId,
        ?int $chapterId,
        string $leaseOwner,
        int $leaseSeconds = 180
    ): bool {
        return self::claimLease($taskId, $chapterId, $leaseOwner, $leaseSeconds) !== null;
    }

    public static function heartbeat(
        string $taskId,
        ?string $state = null,
        ?int $progress = null,
        ?string $message = null,
        int $leaseSeconds = 180,
        ?string $leaseOwner = null,
        ?string $leaseToken = null,
        ?int $fenceGeneration = null
    ): bool {
        self::assertTaskId($taskId);
        if ($state !== null && !in_array($state, self::ACTIVE_STATES, true)) {
            throw new InvalidArgumentException('Invalid active writing task state.');
        }

        $lease = self::resolveLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        if ($lease === null) {
            return false;
        }

        // PDO/MySQL reports changed rows, not matched rows.  Two heartbeats in
        // the same second would otherwise write the same timestamp and return
        // 0, which looks indistinguishable from a lost fence.  Advancing by at
        // least one second preserves a reliable affected===1 ownership signal.
        $sets = ['lease_expires_at=GREATEST(?, DATE_ADD(lease_expires_at, INTERVAL 1 SECOND))'];
        $now = HarnessRuntime::sqlNow();
        $expiresAt = HarnessRuntime::sqlAfterSeconds(max(30, $leaseSeconds));
        $params = [$expiresAt];
        if ($state !== null) {
            $sets[] = 'state=?';
            $params[] = $state;
        }
        if ($progress !== null) {
            $sets[] = 'progress=?';
            $params[] = max(0, min(100, $progress));
        }
        if ($message !== null) {
            $sets[] = 'status_message=?';
            $params[] = self::limit($message, 500);
        }
        array_push(
            $params,
            $taskId,
            $lease['lease_owner'],
            $lease['lease_token'],
            $lease['fence_generation'],
            $now
        );

        $affected = DB::execute(
            'UPDATE writing_tasks SET ' . implode(', ', $sets)
            . " WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
                 AND cancel_requested=0 AND lease_expires_at>=?
                 AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
            $params
        );
        if ($affected === 1) {
            $lease['lease_expires_at'] = $expiresAt;
            self::$processLeases[$taskId] = $lease;
            ShadowTracePublisher::publishApplication(ShadowTrace::heartbeat(
                $taskId,
                $state,
                $progress,
                $lease['fence_generation']
            ));
            return true;
        }

        self::forgetLeaseIfSame($lease);
        return false;
    }

    /**
     * Renew the process-owned writing lease and its exact chapter claim in one
     * SQL ownership check.
     *
     * null means this PHP process has no writing-task lease (the synchronous
     * SSE/daemon paths); false means it did have a lease but no registered
     * generation still owns this novel/chapter claim.  Callers must not fall
     * back to a chapter-only heartbeat after false, otherwise a stale worker
     * could keep another generation's abandoned claim alive.
     */
    public static function heartbeatOwnedChapterClaim(
        int $novelId,
        int $chapterId,
        string $claimToken,
        int $leaseSeconds = 180
    ): ?bool {
        if ($novelId <= 0 || $chapterId <= 0) {
            throw new InvalidArgumentException('Invalid writing chapter heartbeat scope.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1) {
            throw new InvalidArgumentException('Invalid writing chapter claim token.');
        }
        if (self::$processLeases === []) {
            return null;
        }

        $now = HarnessRuntime::sqlNow();
        $expiresAt = HarnessRuntime::sqlAfterSeconds(max(30, $leaseSeconds));
        foreach (array_values(self::$processLeases) as $lease) {
            $affected = DB::execute(
                "UPDATE chapters c
                 STRAIGHT_JOIN writing_tasks wt ON wt.novel_id=c.novel_id
                 SET wt.lease_expires_at=GREATEST(?, DATE_ADD(wt.lease_expires_at, INTERVAL 1 SECOND)),
                     c.updated_at=NOW()
                 WHERE wt.task_id=? AND wt.novel_id=?
                   AND (wt.chapter_id IS NULL OR wt.chapter_id=c.id)
                   AND wt.lease_owner=? AND wt.lease_token=? AND wt.fence_generation=?
                   AND wt.cancel_requested=0 AND wt.lease_expires_at>=?
                   AND wt.state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
                   AND c.id=? AND c.status='writing' AND c.claim_token=?",
                [
                    $expiresAt,
                    $lease['task_id'],
                    $novelId,
                    $lease['lease_owner'],
                    $lease['lease_token'],
                    $lease['fence_generation'],
                    $now,
                    $chapterId,
                    $claimToken,
                ]
            );
            // MySQL may report one or both changed table rows for a multi-table
            // UPDATE. Any positive count proves every WHERE fence matched.
            if ($affected > 0) {
                $lease['lease_expires_at'] = $expiresAt;
                self::$processLeases[$lease['task_id']] = $lease;
                ShadowTracePublisher::publishApplication(ShadowTrace::heartbeat(
                    $lease['task_id'],
                    null,
                    null,
                    $lease['fence_generation']
                ));
                return true;
            }
        }

        return false;
    }

    public static function requestCancel(int $novelId, ?string $taskId = null): int
    {
        if ($novelId <= 0) return 0;
        if ($taskId !== null && $taskId !== '') {
            self::assertTaskId($taskId);
            $affected = DB::execute(
                "UPDATE writing_tasks SET cancel_requested=1, status_message='Cancellation requested'
                 WHERE novel_id=? AND task_id=?
                   AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
                [$novelId, $taskId]
            );
        } else {
            $affected = DB::execute(
                "UPDATE writing_tasks SET cancel_requested=1, status_message='Cancellation requested'
             WHERE novel_id=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
                [$novelId]
            );
        }

        // 审计修复 P0（2026-08-04）：取消即解锁。
        // 原行为只置 cancel_requested=1 不改 state：worker 若已死（租约过期无人续租），
        // 任务仍算"活跃"，书籍被锁到宽限期结束，用户连取消都无法解锁。
        // 存活 worker 每次心跳都会续租（默认 +180s），租约过期即证明无人处理，
        // 直接终结为 canceled；postprocessing 额外要求该章节无存活 postprocess job，
        // 避免误杀正在合法运行的长后处理。
        $now = HarnessRuntime::sqlNow();
        $scoped = ($taskId !== null && $taskId !== '');
        $taskFilter = $scoped ? ' AND task_id=?' : '';
        $tailParams = $scoped ? [$taskId] : [];
        // 用户点取消时，生成中的任务必须立刻离开活跃态。否则 write_start
        // 会把这条正在停的任务当「仍在跑」恢复轮询，再次写作立刻失败。
        // 正文已保存 / 后处理中的任务仍走下方租约过期解锁，避免误杀记忆入库。
        DB::execute(
            "UPDATE writing_tasks
             SET state='canceled', completed_at=?, lease_owner=NULL, lease_token=NULL,
                 fence_generation=fence_generation+1, lease_expires_at=NULL,
                 error_code='user_cancel', error_message='Canceled by user'
             WHERE novel_id=? AND cancel_requested=1
               AND state IN ('queued','preparing','generating','validating')" . $taskFilter,
            array_merge([$now, $novelId], $tailParams)
        );
        DB::execute(
            "UPDATE writing_tasks
             SET state='canceled', completed_at=?, lease_owner=NULL, lease_token=NULL,
                 fence_generation=fence_generation+1, lease_expires_at=NULL,
                 error_code='cancel_unlocked', error_message='Canceled after worker lease expired'
             WHERE novel_id=? AND cancel_requested=1
               AND state IN ('queued','preparing','generating','validating','content_saved')
               AND lease_expires_at IS NOT NULL AND lease_expires_at < ?" . $taskFilter,
            array_merge([$now, $novelId, $now], $tailParams)
        );
        DB::execute(
            "UPDATE writing_tasks wt
             SET wt.state='canceled', wt.completed_at=?, wt.lease_owner=NULL, wt.lease_token=NULL,
                 wt.fence_generation=wt.fence_generation+1, wt.lease_expires_at=NULL,
                 wt.error_code='cancel_unlocked', wt.error_message='Canceled after worker lease expired'
             WHERE wt.novel_id=? AND wt.cancel_requested=1
               AND wt.state='postprocessing'
               AND wt.lease_expires_at IS NOT NULL AND wt.lease_expires_at < ?
               AND (wt.chapter_id IS NULL OR NOT EXISTS (
                   SELECT 1 FROM postprocess_jobs pj
                   WHERE pj.chapter_id = wt.chapter_id
                     AND (pj.state IN ('queued','retry')
                       OR (pj.state='running' AND pj.lease_expires_at IS NOT NULL AND pj.lease_expires_at >= ?))
               ))" . ($scoped ? ' AND wt.task_id=?' : ''),
            array_merge([$now, $novelId, $now, $now], $tailParams)
        );

        if ($affected > 0) {
            ShadowTracePublisher::publishCancellationApplication(
                $novelId,
                $scoped ? $taskId : null
            );
        }
        return $affected;
    }

    public static function isCancelRequested(
        string $taskId,
        ?string $leaseOwner = null,
        ?string $leaseToken = null,
        ?int $fenceGeneration = null
    ): bool
    {
        self::assertTaskId($taskId);

        $hasExplicitFence = $leaseOwner !== null
            || $leaseToken !== null
            || $fenceGeneration !== null;
        if ($hasExplicitFence || isset(self::$processLeases[$taskId])) {
            $lease = self::resolveLease(
                $taskId,
                $leaseOwner,
                $leaseToken,
                $fenceGeneration
            );
            if ($lease === null) {
                return true;
            }

            $row = DB::fetch(
                "SELECT cancel_requested
                 FROM writing_tasks
                 WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
                   AND lease_expires_at>=?
                   AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
                 LIMIT 1",
                [
                    $taskId,
                    $lease['lease_owner'],
                    $lease['lease_token'],
                    $lease['fence_generation'],
                    HarnessRuntime::sqlNow(),
                ]
            );
            if (!is_array($row)) {
                self::forgetLeaseIfSame($lease);
                return true;
            }
            return (int)($row['cancel_requested'] ?? 0) === 1;
        }

        return (int)DB::fetchColumn(
            'SELECT cancel_requested FROM writing_tasks WHERE task_id=? LIMIT 1',
            [$taskId]
        ) === 1;
    }

    public static function isLeaseCurrent(
        string $taskId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration
    ): bool {
        self::assertTaskId($taskId);
        $lease = self::resolveLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        if ($lease === null) {
            return false;
        }

        $exists = DB::fetchColumn(
            "SELECT 1 FROM writing_tasks
             WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             LIMIT 1",
            [
                $taskId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );
        if ((int)$exists === 1) {
            return true;
        }

        self::forgetLeaseIfSame($lease);
        return false;
    }

    /**
     * Authenticate durable evidence that this exact worker generation already
     * committed its initial Candidate and that the chapter still owns a valid
     * Canon. This is intentionally independent from the compatibility progress
     * file: a fatal error can happen after the Canon transaction commits but
     * before the worker publishes status=saved.
     *
     * @return array{novel_id:int,chapter_id:int,accepted_candidate_uid:string,current_candidate_uid:string,revision_hash:string}|null
     */
    public static function authenticatedCommittedCanonForLease(
        string $taskId,
        ?string $leaseOwner = null,
        ?string $leaseToken = null,
        ?int $fenceGeneration = null
    ): ?array {
        self::assertTaskId($taskId);
        $lease = self::resolveLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        if ($lease === null) {
            return null;
        }

        $row = DB::fetch(
            "SELECT wt.novel_id AS task_novel_id,
                    wt.chapter_id AS task_chapter_id,
                    wt.policy_snapshot_json AS task_policy_snapshot_json,
                    wt.policy_snapshot_hash AS task_policy_snapshot_hash,
                    wt.accepted_candidate_uid,
                    ac.candidate_uid AS accepted_row_uid,
                    ac.novel_id AS accepted_novel_id,
                    ac.chapter_id AS accepted_chapter_id,
                    ac.source AS accepted_source,
                    ac.source_run_uid AS accepted_source_run_uid,
                    ac.source_task_id AS accepted_source_task_id,
                    ac.content_hash AS accepted_content_hash,
                    ac.lifecycle AS accepted_lifecycle,
                    ac.policy_snapshot_json AS accepted_policy_snapshot_json,
                    ac.policy_snapshot_hash AS accepted_policy_snapshot_hash,
                    c.status AS chapter_status,
                    c.canonical_state AS chapter_canonical_state,
                    c.content AS chapter_content,
                    c.content_revision_hash AS chapter_revision_hash,
                    c.canonical_candidate_uid AS current_candidate_uid,
                    c.write_policy_snapshot_json AS chapter_policy_snapshot_json,
                    cc.candidate_uid AS current_row_uid,
                    cc.novel_id AS current_novel_id,
                    cc.chapter_id AS current_chapter_id,
                    cc.content_hash AS current_content_hash,
                    cc.lifecycle AS current_lifecycle,
                    cc.policy_snapshot_json AS current_policy_snapshot_json,
                    cc.policy_snapshot_hash AS current_policy_snapshot_hash
             FROM writing_tasks wt
             LEFT JOIN chapter_candidates ac
               ON ac.candidate_uid=wt.accepted_candidate_uid
             LEFT JOIN chapters c
               ON c.id=wt.chapter_id AND c.novel_id=wt.novel_id
             LEFT JOIN chapter_candidates cc
               ON cc.candidate_uid=c.canonical_candidate_uid
             WHERE wt.task_id=? AND wt.lease_owner=? AND wt.lease_token=?
               AND wt.fence_generation=? AND wt.cancel_requested=0
               AND wt.lease_expires_at>=?
               AND wt.state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             LIMIT 1",
            [
                $taskId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );
        if (!is_array($row)) {
            self::forgetLeaseIfSame($lease);
            return null;
        }
        if (($row['accepted_candidate_uid'] ?? null) === null
            || $row['accepted_candidate_uid'] === '') {
            return null;
        }

        return self::normalizeCommittedCanonEvidence(
            $row,
            $taskId,
            $lease['lease_token']
        );
    }

    public static function markDone(
        string $taskId,
        array $result = [],
        bool $contentSaved = true,
        ?string $revisionHash = null,
        ?string $leaseOwner = null,
        ?string $leaseToken = null,
        ?int $fenceGeneration = null
    ): bool {
        self::assertTaskId($taskId);
        $lease = self::resolveLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        if ($lease === null) {
            return false;
        }

        $json = $result === [] ? null : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = HarnessRuntime::sqlNow();
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state='done', progress=100, content_saved=?, chapter_revision_hash=?,
                 result_json=?, completed_at=NOW(), lease_owner=NULL, lease_token=NULL,
                 lease_expires_at=NULL,
                 error_code=NULL, error_message=NULL
             WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
            [
                $contentSaved ? 1 : 0,
                self::validRevisionHash($revisionHash),
                $json,
                $taskId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                $now,
            ]
        );
        if ($affected === 1) {
            self::forgetLeaseIfSame($lease);
            ShadowTracePublisher::publishApplication(ShadowTrace::terminal(
                $taskId,
                'done',
                $lease['fence_generation']
            ));
            return true;
        }
        self::forgetLeaseIfSame($lease);
        return false;
    }

    public static function markFailed(
        string $taskId,
        string $errorMessage,
        bool $contentSaved = false,
        bool $canceled = false,
        string $errorCode = 'worker_failed',
        ?string $leaseOwner = null,
        ?string $leaseToken = null,
        ?int $fenceGeneration = null
    ): bool {
        self::assertTaskId($taskId);
        $lease = self::resolveLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        if ($lease === null) {
            return false;
        }

        $now = HarnessRuntime::sqlNow();
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state=?, content_saved=?, error_code=?, error_message=?,
                 completed_at=NOW(), lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL
             WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
            [
                $canceled ? 'canceled' : 'failed',
                $contentSaved ? 1 : 0,
                self::limit($errorCode, 64),
                self::limit($errorMessage, 500),
                $taskId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                $now,
            ]
        );
        self::forgetLeaseIfSame($lease);
        $completed = $affected === 1;
        if ($completed) {
            ShadowTracePublisher::publishApplication(ShadowTrace::terminal(
                $taskId,
                $canceled ? 'canceled' : 'failed',
                $lease['fence_generation']
            ));
        }
        return $completed;
    }

    /**
     * Control-plane failure before a worker acquired any lease.
     *
     * This is intentionally separate from markFailed(): launch supervision is
     * authorized to close only an untouched queued row.  If a worker won the
     * claim concurrently, the predicate fails and its lease remains intact.
     */
    public static function markLaunchFailed(
        string $taskId,
        string $errorMessage,
        string $errorCode = 'worker_start_failed'
    ): bool {
        self::assertTaskId($taskId);
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state='failed', content_saved=0, error_code=?, error_message=?,
                 completed_at=NOW(), lease_owner=NULL, lease_token=NULL,
                 fence_generation=fence_generation+1, lease_expires_at=NULL
             WHERE task_id=? AND state='queued' AND cancel_requested=0
               AND fence_generation=0
               AND lease_owner IS NULL AND lease_token IS NULL",
            [
                self::limit($errorCode, 64),
                self::limit($errorMessage, 500),
                $taskId,
            ]
        );
        $completed = $affected === 1;
        if ($completed) {
            ShadowTracePublisher::publishApplication(ShadowTrace::terminal(
                $taskId,
                'failed',
                1
            ));
        }
        return $completed;
    }

    /**
     * 审计修复（2026-08-30 H-1）：按 task_id 收口租约已过期的死任务。
     *
     * write_poll 原为纯读者：worker 被 SIGKILL 后租约过期，轮询端只把
     * generating/preparing/validating 映射为 status:'writing'，无人触发
     * findActiveForNovel 的三段清扫，前端将无限轮询"写作中"。
     * 本方法复用 findActiveForNovel 的三条收口 UPDATE（同一 SQL 语义，
     * 范围从 novel 收窄到 task_id——轮询端只对该任务有已验证的归属权），
     * 死任务将被判为 failed，toPollPayload 随之返回 status:'error'。
     *
     * 注意：本方法只做状态转移，不持有调用方事务（DB::execute 独立提交），
     * 与 findActiveForNovel 的事务语义一致，可安全在任何请求路径调用。
     *
     * @return bool 是否发生了状态转移（调用方可据此重新读取行）
     */
    public static function reapExpiredLeaseForTask(string $taskId): bool
    {
        self::assertTaskId($taskId);
        $row = self::findByTaskId($taskId);
        if (!is_array($row)) return false;

        $state = (string)($row['state'] ?? '');
        if (!in_array($state, [
            'queued', 'preparing', 'generating', 'validating', 'content_saved', 'postprocessing',
        ], true)) {
            return false;
        }
        // 未设置租约截止时间的行（如刚入队未认领）不在收口范围。
        if (empty($row['lease_expires_at'])) return false;

        $now = HarnessRuntime::sqlNow();
        $taskIdIn = (string)$row['task_id'];
        $observedFenceGeneration = (int)($row['fence_generation'] ?? 0);

        // 活跃态（postprocessing 除外）：租约已过期 → 立即判失败。
        // 与 findActiveForNovel 第一段 UPDATE 完全同构。
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state='failed', error_code='lease_expired',
                 error_message='Worker lease expired before reaching a terminal state',
                 completed_at=?, lease_owner=NULL, lease_token=NULL,
                 fence_generation=fence_generation+1, lease_expires_at=NULL
             WHERE task_id=? AND novel_id=?
               AND fence_generation=?
               AND state IN ('queued','preparing','generating','validating','content_saved')
               AND lease_expires_at IS NOT NULL AND lease_expires_at < ?",
            [$now, $taskIdIn, (int)$row['novel_id'], $observedFenceGeneration, $now]
        );
        if ($affected > 0) {
            ShadowTracePublisher::publishApplication(ShadowTrace::terminal(
                $taskIdIn,
                'failed',
                $observedFenceGeneration + 1
            ));
            return true;
        }

        // postprocessing：与 postprocess_jobs 联动的孤儿立即清扫。
        // 有存活 job 的长后处理不在此收口——交由 findActiveForNovel 的
        // 1800s 宽限兜底（轮询端不应杀活跃后处理）。
        $affected = DB::execute(
            "UPDATE writing_tasks wt
             SET wt.state='failed', wt.error_code='postprocess_orphaned',
                 wt.error_message='Post-processing lease expired and no live postprocess job exists',
                 wt.completed_at=?, wt.lease_owner=NULL, wt.lease_token=NULL,
                 wt.fence_generation=wt.fence_generation+1, wt.lease_expires_at=NULL
             WHERE wt.task_id=?
               AND wt.fence_generation=?
               AND wt.state='postprocessing'
               AND wt.chapter_id IS NOT NULL
               AND wt.lease_expires_at IS NOT NULL AND wt.lease_expires_at < ?
               AND NOT EXISTS (
                   SELECT 1 FROM postprocess_jobs pj
                   WHERE pj.chapter_id = wt.chapter_id
                     AND (pj.state IN ('queued','retry')
                       OR (pj.state='running' AND pj.lease_expires_at IS NOT NULL AND pj.lease_expires_at >= ?))
               )",
            [$now, $taskIdIn, $observedFenceGeneration, $now, $now]
        );
        if ($affected > 0) {
            ShadowTracePublisher::publishApplication(ShadowTrace::terminal(
                $taskIdIn,
                'failed',
                $observedFenceGeneration + 1
            ));
            return true;
        }
        return false;
    }

    public static function toPollPayload(array $row): array
    {
        $state = (string)($row['state'] ?? 'unknown');
        $status = match ($state) {
            'done' => 'done',
            'failed', 'canceled' => 'error',
            'content_saved' => 'saved',
            'postprocessing' => 'postprocessing',
            default => 'writing',
        };
        $result = json_decode((string)($row['result_json'] ?? ''), true);
        if (!is_array($result)) $result = [];

        return array_merge($result, [
            'ok' => true,
            'status' => $status,
            'progress' => (int)($row['progress'] ?? 0),
            'chapter_id' => isset($row['chapter_id']) ? (int)$row['chapter_id'] : null,
            'error' => $state === 'canceled'
                ? '用户已取消写作'
                : ($row['error_message'] ?? null),
            'canceled' => $state === 'canceled',
            'content_saved' => !empty($row['content_saved']),
            'durable_fallback' => true,
        ]);
    }

    /** @return array<string,mixed> */
    /**
     * @param array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string} $lease
     * @return array<string,mixed>
     */
    private static function fetchPolicySnapshotRow(string $taskId, array $lease): array
    {
        $row = DB::fetch(
            "SELECT policy_snapshot_json, policy_snapshot_hash
             FROM writing_tasks
             WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?
             LIMIT 1",
            [
                $taskId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );
        if (!is_array($row)) {
            self::forgetLeaseIfSame($lease);
            throw new RuntimeException('Writing task lease was lost while freezing policy snapshot.');
        }
        return $row;
    }

    private static function assertSamePolicySnapshot(
        WritePolicySnapshot $stored,
        WritePolicySnapshot $expected
    ): void {
        if (!hash_equals($stored->checksum(), $expected->checksum())
            || !hash_equals($stored->toJson(), $expected->toJson())) {
            throw new RuntimeException('Writing task already contains a different policy snapshot.');
        }
    }

    /**
     * @return array{novel_id:int,chapter_id:int,accepted_candidate_uid:string,current_candidate_uid:string,revision_hash:string}
     */
    private static function normalizeCommittedCanonEvidence(
        array $row,
        string $taskId,
        string $leaseToken
    ): array {
        $novelId = (int)($row['task_novel_id'] ?? 0);
        $chapterId = (int)($row['task_chapter_id'] ?? 0);
        if ($novelId <= 0 || $chapterId <= 0) {
            throw new RuntimeException('Committed Canon evidence is missing task ownership.');
        }

        $acceptedUid = self::requireEvidenceUid(
            $row['accepted_candidate_uid'] ?? null,
            'accepted_candidate_uid'
        );
        $acceptedRowUid = self::requireEvidenceUid(
            $row['accepted_row_uid'] ?? null,
            'accepted Candidate row'
        );
        $acceptedContentHash = self::requireEvidenceSha256(
            $row['accepted_content_hash'] ?? null,
            'accepted Candidate content hash'
        );
        if (!hash_equals($acceptedUid, $acceptedRowUid)
            || (int)($row['accepted_novel_id'] ?? 0) !== $novelId
            || (int)($row['accepted_chapter_id'] ?? 0) !== $chapterId
            || (string)($row['accepted_source'] ?? '') !== 'initial'
            || (string)($row['accepted_lifecycle'] ?? '') !== 'committed'
            || !is_string($row['accepted_source_task_id'] ?? null)
            || !hash_equals($taskId, (string)$row['accepted_source_task_id'])
            || !is_string($row['accepted_source_run_uid'] ?? null)
            || !hash_equals($leaseToken, (string)$row['accepted_source_run_uid'])) {
            throw new RuntimeException(
                'Committed Canon evidence does not belong to this writing-task generation.'
            );
        }

        $taskPolicy = self::policySnapshotFromRow([
            'policy_snapshot_json' => $row['task_policy_snapshot_json'] ?? null,
            'policy_snapshot_hash' => $row['task_policy_snapshot_hash'] ?? null,
        ]);
        $acceptedPolicy = self::policySnapshotFromRow([
            'policy_snapshot_json' => $row['accepted_policy_snapshot_json'] ?? null,
            'policy_snapshot_hash' => $row['accepted_policy_snapshot_hash'] ?? null,
        ]);
        if (!hash_equals($taskPolicy->checksum(), $acceptedPolicy->checksum())) {
            throw new RuntimeException(
                'Committed Canon evidence has a mismatched accepted Candidate policy.'
            );
        }

        if ((string)($row['chapter_status'] ?? '') !== 'completed'
            || (string)($row['chapter_canonical_state'] ?? '') !== 'canonical') {
            throw new RuntimeException('Committed Canon evidence no longer points to a completed Canon.');
        }
        $chapterContent = $row['chapter_content'] ?? null;
        if (!is_string($chapterContent)) {
            throw new RuntimeException('Committed Canon evidence is missing chapter content.');
        }
        $revisionHash = self::requireEvidenceSha256(
            $row['chapter_revision_hash'] ?? null,
            'chapter revision hash'
        );
        if (!hash_equals(hash('sha256', $chapterContent), $revisionHash)) {
            throw new RuntimeException('Committed Canon evidence has a stale chapter revision hash.');
        }

        $currentUid = self::requireEvidenceUid(
            $row['current_candidate_uid'] ?? null,
            'current Canon Candidate UID'
        );
        $currentRowUid = self::requireEvidenceUid(
            $row['current_row_uid'] ?? null,
            'current Canon Candidate row'
        );
        $currentContentHash = self::requireEvidenceSha256(
            $row['current_content_hash'] ?? null,
            'current Canon Candidate content hash'
        );
        if (!hash_equals($currentUid, $currentRowUid)
            || (int)($row['current_novel_id'] ?? 0) !== $novelId
            || (int)($row['current_chapter_id'] ?? 0) !== $chapterId
            || (string)($row['current_lifecycle'] ?? '') !== 'committed'
            || !hash_equals($revisionHash, $currentContentHash)) {
            throw new RuntimeException('Committed Canon evidence has inconsistent current Candidate ownership.');
        }

        $chapterPolicyJson = $row['chapter_policy_snapshot_json'] ?? null;
        if (!is_string($chapterPolicyJson) || $chapterPolicyJson === '') {
            throw new RuntimeException('Committed Canon evidence is missing the chapter policy snapshot.');
        }
        $chapterPolicy = WritePolicySnapshot::fromJson($chapterPolicyJson);
        $currentPolicy = self::policySnapshotFromRow([
            'policy_snapshot_json' => $row['current_policy_snapshot_json'] ?? null,
            'policy_snapshot_hash' => $row['current_policy_snapshot_hash'] ?? null,
        ]);
        if (!hash_equals($chapterPolicy->checksum(), $currentPolicy->checksum())
            || !hash_equals($taskPolicy->checksum(), $chapterPolicy->checksum())) {
            throw new RuntimeException('Committed Canon evidence has inconsistent frozen policy ownership.');
        }
        if (hash_equals($acceptedUid, $currentUid)
            && !hash_equals($acceptedContentHash, $revisionHash)) {
            throw new RuntimeException('Accepted Candidate content no longer matches its Canon revision.');
        }

        return [
            'novel_id' => $novelId,
            'chapter_id' => $chapterId,
            'accepted_candidate_uid' => $acceptedUid,
            'current_candidate_uid' => $currentUid,
            'revision_hash' => $revisionHash,
        ];
    }

    private static function requireEvidenceUid(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new RuntimeException("{$label} is invalid.");
        }
        return $value;
    }

    private static function requireEvidenceSha256(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException("{$label} is invalid.");
        }
        return $value;
    }

    /** @return array<string,mixed> */
    /**
     * @param array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string} $lease
     * @return array<string,mixed>
     */
    private static function fetchBaseChapterSnapshotRow(string $taskId, array $lease): array
    {
        $row = DB::fetch(
            "SELECT chapter_id, base_chapter_snapshot_json
             FROM writing_tasks
             WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?
             LIMIT 1",
            [
                $taskId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );
        if (!is_array($row)) {
            self::forgetLeaseIfSame($lease);
            throw new RuntimeException('Writing task lease was lost while freezing base chapter snapshot.');
        }
        return $row;
    }

    /**
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}|null
     */
    private static function optionalBaseChapterSnapshotFromRow(array $row): ?array
    {
        $json = $row['base_chapter_snapshot_json'] ?? null;
        if ($json === null || $json === '') {
            return null;
        }
        if (!is_string($json)) {
            throw new RuntimeException('Writing task base chapter snapshot must be JSON text.');
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Writing task base chapter snapshot JSON is invalid.', 0, $exception);
        }
        try {
            return self::normalizeBaseChapterSnapshot($decoded);
        } catch (Throwable $exception) {
            throw new RuntimeException('Writing task base chapter snapshot payload is invalid.', 0, $exception);
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}
     */
    private static function normalizeBaseChapterSnapshot(array $snapshot): array
    {
        $expected = [
            'status',
            'canonical_state',
            'content_revision_hash',
            'content_hash',
            'claim_token',
        ];
        $actual = array_keys($snapshot);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Base chapter snapshot fields must match the frozen contract.');
        }

        $status = $snapshot['status'];
        if (!is_string($status)
            || preg_match('/^[a-z][a-z0-9_]{0,23}$/D', $status) !== 1
            || $status === 'writing') {
            throw new InvalidArgumentException(
                'Base chapter status must describe the state before acquisition.'
            );
        }

        $canonicalState = $snapshot['canonical_state'];
        if (!is_string($canonicalState)
            || !in_array($canonicalState, ['none', 'legacy', 'canonical'], true)) {
            throw new InvalidArgumentException('Base chapter canonical_state is invalid.');
        }

        $revisionHash = self::optionalSha256(
            $snapshot['content_revision_hash'],
            'content_revision_hash'
        );
        $contentHash = self::optionalSha256($snapshot['content_hash'], 'content_hash');
        $claimToken = $snapshot['claim_token'];
        if (!is_string($claimToken) || preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1) {
            throw new InvalidArgumentException('Base chapter claim_token is invalid.');
        }

        if ($canonicalState === 'none') {
            if ($revisionHash !== null) {
                throw new InvalidArgumentException(
                    'A base chapter without Canon must not carry a revision hash.'
                );
            }
        } elseif ($revisionHash === null
            || $contentHash === null
            || !hash_equals($revisionHash, $contentHash)) {
            throw new InvalidArgumentException(
                'A Canon base chapter requires matching revision and content hashes.'
            );
        }

        return [
            'status' => $status,
            'canonical_state' => $canonicalState,
            'content_revision_hash' => $revisionHash,
            'content_hash' => $contentHash,
            'claim_token' => $claimToken,
        ];
    }

    private static function optionalSha256(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Base chapter {$field} is invalid.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function assertTaskChapterCompatible(array $row, int $chapterId): void
    {
        $storedChapterId = $row['chapter_id'] ?? null;
        if ($storedChapterId !== null && (int)$storedChapterId !== $chapterId) {
            throw new RuntimeException('Writing task is already bound to a different chapter.');
        }
    }

    /**
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $expected
     */
    private static function assertSameBaseChapterSnapshot(array $stored, array $expected): void
    {
        if (!hash_equals(self::encodeJson($stored), self::encodeJson($expected))) {
            throw new RuntimeException('Writing task already contains a different base chapter snapshot.');
        }
    }

    /** @param array<string,mixed> $value */
    private static function encodeJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private static function assertTaskId(string $taskId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]{8,64}$/', $taskId)) {
            throw new InvalidArgumentException('Invalid task id.');
        }
    }

    /**
     * @return array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string}|null
     */
    private static function resolveLease(
        string $taskId,
        ?string $leaseOwner,
        ?string $leaseToken,
        ?int $fenceGeneration
    ): ?array {
        $provided = ($leaseOwner !== null ? 1 : 0)
            + ($leaseToken !== null ? 1 : 0)
            + ($fenceGeneration !== null ? 1 : 0);
        if ($provided === 0) {
            return self::$processLeases[$taskId] ?? null;
        }
        if ($provided !== 3) {
            throw new InvalidArgumentException(
                'Writing task lease owner, token and generation must be supplied together.'
            );
        }

        if ($leaseOwner === '' || self::cleanLeaseOwner($leaseOwner) !== $leaseOwner) {
            throw new InvalidArgumentException('Invalid writing task lease owner.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', (string)$leaseToken) !== 1) {
            throw new InvalidArgumentException('Invalid writing task lease token.');
        }
        if ((int)$fenceGeneration <= 0) {
            throw new InvalidArgumentException('Invalid writing task fence generation.');
        }

        return [
            'task_id' => $taskId,
            'lease_owner' => $leaseOwner,
            'lease_token' => (string)$leaseToken,
            'fence_generation' => (int)$fenceGeneration,
            'lease_expires_at' => '',
        ];
    }

    /**
     * @return array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string}
     */
    private static function requireLease(
        string $taskId,
        ?string $leaseOwner,
        ?string $leaseToken,
        ?int $fenceGeneration
    ): array {
        $lease = self::resolveLease(
            $taskId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration
        );
        if ($lease === null) {
            throw new RuntimeException('Writing task mutation requires an authenticated lease fence.');
        }
        return $lease;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string}
     */
    private static function leaseFromRow(
        array $row,
        string $expectedTaskId,
        string $expectedOwner,
        string $expectedToken
    ): array {
        $taskId = $row['task_id'] ?? null;
        $owner = $row['lease_owner'] ?? null;
        $token = $row['lease_token'] ?? null;
        $generation = $row['fence_generation'] ?? null;
        $expiresAt = $row['lease_expires_at'] ?? null;

        if (!is_string($taskId) || !hash_equals($expectedTaskId, $taskId)
            || !is_string($owner) || !hash_equals($expectedOwner, $owner)
            || !is_string($token) || !hash_equals($expectedToken, $token)
            || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || filter_var($generation, FILTER_VALIDATE_INT) === false
            || (int)$generation <= 0
            || !is_string($expiresAt) || $expiresAt === '') {
            throw new RuntimeException('Writing task claim returned a malformed lease fence.');
        }

        return [
            'task_id' => $taskId,
            'lease_owner' => $owner,
            'lease_token' => $token,
            'fence_generation' => (int)$generation,
            'lease_expires_at' => $expiresAt,
        ];
    }

    /**
     * @param array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string} $lease
     */
    private static function forgetLeaseIfSame(array $lease): void
    {
        $taskId = $lease['task_id'];
        $current = self::$processLeases[$taskId] ?? null;
        if (!is_array($current)) {
            return;
        }
        if (hash_equals($current['lease_owner'], $lease['lease_owner'])
            && hash_equals($current['lease_token'], $lease['lease_token'])
            && $current['fence_generation'] === $lease['fence_generation']) {
            unset(self::$processLeases[$taskId]);
        }
    }

    private static function cleanLeaseOwner(string $owner): string
    {
        $owner = preg_replace('/[^a-zA-Z0-9_.:@-]/', '_', $owner) ?? '';
        return self::limit($owner !== '' ? $owner : 'unknown-worker', 120);
    }

    private static function validRevisionHash(?string $hash): ?string
    {
        if ($hash === null || $hash === '') return null;
        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : null;
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
