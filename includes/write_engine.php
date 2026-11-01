<?php
/**
 * WriteEngine — 章节写作核心引擎
 * 提取 write_chapter.php 和 write_chapter_worker.php 的共享逻辑，
 * 通过回调注入实现 SSE / 进度文件 两种 I/O 模式的解耦。
 *
 * 6 个阶段：
 *   1. resolveChapter() — 解析待写章节 + 僵死任务清理
 *   2. initMemory()     — 初始化记忆引擎 + 语义召回
 *   3. buildPrompt()    — 组装 AI 写作 prompt
 *   4. streamWrite()    — 带模型回退的流式写作
 *   5. saveChapter()    — 落盘正文 + 版本备份
 *   6. postProcess()    — 摘要/记忆/知识库/质检
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/quality/WritePolicySnapshotFactory.php';
require_once __DIR__ . '/quality/CanonicalQualityGate.php';
require_once __DIR__ . '/quality/ChapterGenerationIntegrity.php';
require_once __DIR__ . '/quality/ChapterCandidateRepository.php';
require_once __DIR__ . '/quality/CanonicalCommitService.php';
require_once __DIR__ . '/ChapterMutationService.php';
require_once __DIR__ . '/NovelProjectionFence.php';
require_once __DIR__ . '/tasks/PostprocessJobRepository.php';
require_once __DIR__ . '/tasks/PostprocessLeaseLostException.php';
require_once __DIR__ . '/harness/support/HarnessRuntime.php';
require_once __DIR__ . '/harness/HarnessFeatureSnapshot.php';
require_once __DIR__ . '/harness/candidates/M63CandidateProjectionBridge.php';
require_once __DIR__ . '/harness/candidates/ApprovalRequestRepository.php';

class WriteEngineValidationException extends RuntimeException {}
class WriteEnginePersistenceException extends RuntimeException {}
class WriteEnginePostprocessStaleException extends RuntimeException {}
class WriteEnginePostProcessPendingException extends RuntimeException {}
class WriteEngineCanceledException extends RuntimeException {}
/**
 * streamWrite 已耗尽全部有界重试（每模型 RT_SAME_MODEL_MAX 次 × 全部 fallback
 * 模型 + 429 指数退避 + bestAttempt/超字兜底）后仍未产出可用正文的终态失败。
 *
 * 审计修复 S-2（2026-09-27）：此前该路径抛通用 RuntimeException，被 Harness
 * 适配层一律再包成 RetryableModelException 令 workflow 整阶段重试——最坏
 * API 调用被放大为 模型数×3×3 次、每次从零重写整章；而 legacy 直连 worker
 * 对同一异常直接终止不重试，两条生产路径行为分歧。携带本类型即代表"引擎
 * 内部已尽力"，上游不得再自动重试。
 */
class WriteEngineGenerationExhaustedException extends RuntimeException {}

class WriteEngine
{
    private static function resolveWritePolicySnapshot(
        int $novelId,
        int $targetWords,
        ?WritePolicySnapshot $snapshot
    ): WritePolicySnapshot {
        $snapshot ??= WritePolicySnapshotFactory::captureForChapter($novelId, $targetWords);
        WritePolicySnapshotFactory::assertExecutionSupported($snapshot);
        if ($snapshot->targetWords() !== $targetWords) {
            throw new InvalidArgumentException(
                'Frozen write policy target_words does not match the chapter write target.'
            );
        }
        return $snapshot;
    }

    /**
     * 写作 Prompt / 认领所需章节列。刻意不 SELECT *，避免把无关 LONGTEXT 带进热路径，
     * 但必须覆盖 ChapterPromptBuilder 消费的大纲元数据。
     *
     * @param string[] $extra 额外列（如挂机路径的 retry_count）
     */
    public static function chapterWriteSelectList(array $extra = []): string
    {
        $cols = [
            'id', 'novel_id', 'chapter_number', 'title', 'outline', 'key_points',
            'hook', 'pacing', 'hook_type', 'cool_point_type', 'opening_type',
            'suspense', 'synopsis_id', 'status', 'content', 'words',
            'quality_score', 'critic_scores', 'ai_pattern_issues',
            'claim_token', 'canonical_state', 'content_revision_hash',
            'canonical_candidate_uid', 'write_policy_snapshot_json',
            'canonicalized_at', 'created_at', 'updated_at',
        ];
        foreach ($extra as $col) {
            $col = trim((string)$col);
            if ($col !== '' && !in_array($col, $cols, true)) {
                $cols[] = $col;
            }
        }
        return implode(', ', $cols);
    }

    /**
     * Capture the chapter state that a Candidate must compare against when it
     * later attempts an atomic Canon transition. Call this before changing the
     * chapter status to writing; the supplied claim token is the new lease
     * identity that will guard the transition.
     *
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}
     */
    public static function captureChapterBaseSnapshot(array $chapter, string $claimToken): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1) {
            throw new InvalidArgumentException('Chapter claim token must be a lowercase 32-character hex UID.');
        }

        $status = (string)($chapter['status'] ?? '');
        if ($status === '' || $status === 'writing') {
            throw new InvalidArgumentException(
                'Chapter base snapshot must be captured before the writing claim.'
            );
        }

        $canonicalState = (string)($chapter['canonical_state'] ?? 'none');
        if (!in_array($canonicalState, ['none', 'legacy', 'canonical'], true)) {
            throw new InvalidArgumentException('Chapter canonical_state is invalid.');
        }

        $content = (string)($chapter['content'] ?? '');
        $contentHash = $content === '' ? null : hash('sha256', $content);
        $revisionHash = $chapter['content_revision_hash'] ?? null;
        $revisionHash = is_string($revisionHash) && $revisionHash !== ''
            ? strtolower($revisionHash)
            : null;
        if ($revisionHash !== null && preg_match('/^[a-f0-9]{64}$/D', $revisionHash) !== 1) {
            throw new InvalidArgumentException('Chapter content_revision_hash is invalid.');
        }

        if ($canonicalState === 'none') {
            if ($revisionHash !== null) {
                throw new InvalidArgumentException(
                    'A chapter without Canon cannot carry a Canon revision hash.'
                );
            }
        } elseif (
            $contentHash === null
            || $revisionHash === null
            || !hash_equals($contentHash, $revisionHash)
        ) {
            throw new InvalidArgumentException(
                'A legacy/canonical chapter must have content matching its revision hash.'
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

    /**
     * Validate a snapshot transported across a pre-claim boundary (SSE).
     *
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}
     */
    public static function validateChapterBaseSnapshot(array $snapshot, string $claimToken): array
    {
        $expected = [
            'status',
            'canonical_state',
            'content_revision_hash',
            'content_hash',
            'claim_token',
        ];
        $actual = array_keys($snapshot);
        sort($actual, SORT_STRING);
        $sortedExpected = $expected;
        sort($sortedExpected, SORT_STRING);
        if ($actual !== $sortedExpected) {
            throw new InvalidArgumentException('Chapter base snapshot fields are invalid.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1
            || !is_string($snapshot['claim_token'])
            || !hash_equals($claimToken, $snapshot['claim_token'])) {
            throw new InvalidArgumentException('Chapter base snapshot claim token mismatch.');
        }

        $status = is_string($snapshot['status']) ? $snapshot['status'] : '';
        if ($status === '' || $status === 'writing'
            || preg_match('/^[a-z][a-z0-9_]{0,23}$/D', $status) !== 1) {
            throw new InvalidArgumentException('Chapter base snapshot status is invalid.');
        }
        $canonicalState = is_string($snapshot['canonical_state'])
            ? $snapshot['canonical_state']
            : '';
        if (!in_array($canonicalState, ['none', 'legacy', 'canonical'], true)) {
            throw new InvalidArgumentException('Chapter base snapshot canonical state is invalid.');
        }

        $revisionHash = $snapshot['content_revision_hash'];
        $contentHash = $snapshot['content_hash'];
        foreach ([
            'content_revision_hash' => $revisionHash,
            'content_hash' => $contentHash,
        ] as $field => $hash) {
            if ($hash !== null
                && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) {
                throw new InvalidArgumentException("Chapter base snapshot {$field} is invalid.");
            }
        }
        if ($canonicalState === 'none') {
            if ($revisionHash !== null) {
                throw new InvalidArgumentException(
                    'A base snapshot without Canon cannot carry a revision hash.'
                );
            }
        } elseif (
            !is_string($revisionHash)
            || !is_string($contentHash)
            || !hash_equals($revisionHash, $contentHash)
        ) {
            throw new InvalidArgumentException(
                'A legacy/canonical base snapshot requires matching revision and content hashes.'
            );
        }

        /** @var array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string} $snapshot */
        return $snapshot;
    }

    /**
     * 取消写作标志文件路径
     * 修复：以前写到 BASE_PATH/storage/ 根目录，与进度文件分散在两个目录，
     * 部署一致性差且根目录 .htaccess 无独立保护规则。
     * 现统一到 CFG_PROGRESS_DIR（已含 storage/write_progress/.htaccess deny all）。
     */
    public static function cancelFlagPath(int $novelId): string
    {
        return CFG_PROGRESS_DIR . "/write_cancel_{$novelId}.flag";
    }

    /** 后处理「进行中」标志文件路径（防止下一章在记忆/指令落库前就开写） */
    private static function postProcessPendingFlag(int $novelId, int $chNum): string
    {
        return CFG_PROGRESS_DIR . "/pp_pending_{$novelId}_{$chNum}.flag";
    }

    /**
     * 清除章节后处理占位。worker/daemon 的异常与 shutdown 路径也必须能调用，
     * 否则正文已保存但后处理崩溃时，下一章会一直等待到 stale timeout。
     */
    public static function clearPostProcessPending(int $novelId, int $chNum): void
    {
        if ($novelId > 0 && $chNum > 0) {
            @unlink(self::postProcessPendingFlag($novelId, $chNum));
        }
    }

    /**
     * 等待指定章节的后处理完成（摘要/记忆入库/Agent指令落库）。
     * 仅当存在「进行中」标志时才等待，避免对历史章节空等。
     * 超时不放行：抛 WriteEnginePostProcessPendingException，调用方须释放认领并稍后重试。
     * 僵死残留由 findActiveTask 按龄清理 pp_pending 后才允许下一章启动。
     * 上限可由 ws_pp_wait_sec 配置（默认 300 秒），并 clamp 到 CFG_ZOMBIE_DB - 120。
     */
    private static function waitForPostProcess(
        int $novelId,
        int $chNum,
        ?int $maxWaitSec = null,
        ?int $waitingChapterId = null,
        ?string $waitingClaimToken = null
    ): void
    {
        if ($chNum < 1) return;
        if ($maxWaitSec === null) {
            $maxWaitSec = max(60, (int)getSystemSetting('ws_pp_wait_sec', 300, 'int'));
        }
        // 审计修复（2026-08-02 H1 第4点）：等待上限与 CFG_ZOMBIE_DB 间必须保留 ≥120s
        // 安全边际。原默认 300 == CFG_ZOMBIE_DB(300)，零边际：心跳刷新 updated_at 一旦
        // 短暂失败（DB 抖动），等待接近上限时 recoverStaleWritingChapters 即可把仍健康
        // 等待中的章节误判为僵尸重置为 outlined，导致 saveChapter 的
        // WHERE status="writing" 失配失败。clamp 到 CFG_ZOMBIE_DB - 120，下限 60s。
        $zombieSec = defined('CFG_ZOMBIE_DB') ? (int)CFG_ZOMBIE_DB : 300;
        $safeCeiling = max(60, $zombieSec - 120);
        if ($maxWaitSec > $safeCeiling) {
            $maxWaitSec = $safeCeiling;
        }
        $flag = self::postProcessPendingFlag($novelId, $chNum);
        // The filesystem flag is only an acceleration hint. Canon promotion
        // and its durable job commit atomically, while a process may crash
        // before creating the flag. Always pair it with the current Canon's
        // exact revision/Candidate-owned active queue row.
        if (!self::hasDurablePostProcessPending($novelId, $chNum)
            && !file_exists($flag)) {
            // Clearing/deleting a body has no completed revision to enqueue.
            // Reconcile local counters before a writer can consume that history.
            require_once __DIR__ . '/constraints/ConstraintProjectionService.php';
            ConstraintProjectionService::refresh($novelId);
            return;
        }

        // 队列中的任务（入库内联执行失败转 retry、手动保存入队等）没有常驻消费者，
        // 拉起后台 worker 消化积压；启动器自带 15 秒节流，DB 租约决定归属。
        self::launchPostprocessWorker();
        $startedAt = HarnessRuntime::unixTime();
        $deadline = $startedAt + $maxWaitSec;
        $lastHeartbeat = 0;
        while (HarnessRuntime::unixTime() < $deadline) {
            HarnessRuntime::sleepMicroseconds(400000); // 0.4s
            // 审计修复（2026-07-19 H-中2）：等待期间每 30 秒续租当前章任务租约，
            // 避免 300 秒等待超过 180 秒租约 -> 健康任务被 watchdog 误判 failed。
            $now = HarnessRuntime::unixTime();
            if ($now - $lastHeartbeat >= 30) {
                $lastHeartbeat = $now;
                // retry 退避到期后 worker 已退出，需周期性再次拉起
                self::launchPostprocessWorker();
                $claimHeartbeatResult = true;
                try {
                    if ($waitingChapterId !== null
                        && $waitingChapterId > 0
                        && is_string($waitingClaimToken)
                        && preg_match('/^[a-f0-9]{32}$/D', $waitingClaimToken) === 1) {
                        // One exact multi-table CAS authenticates both ownership
                        // domains without a check/update gap. Do not wait on the
                        // projection fence here: the previous chapter's long
                        // post-process owns it while this task must keep leasing.
                        $claimHeartbeatResult = \WritingTaskRepository::heartbeatOwnedChapterClaim(
                            $novelId,
                            $waitingChapterId,
                            $waitingClaimToken,
                            180
                        );
                        if ($claimHeartbeatResult === null) {
                            // Synchronous SSE/daemon writers have no durable
                            // writing_tasks lease. Their exact random claim is
                            // the only authority; never refresh by novel/status.
                            $claimHeartbeatResult = DB::execute(
                                'UPDATE chapters SET updated_at=GREATEST('
                                . 'NOW(), DATE_ADD(updated_at, INTERVAL 1 SECOND))'
                                . ' WHERE id=? AND novel_id=? AND status="writing" AND claim_token=?',
                                [$waitingChapterId, $novelId, $waitingClaimToken]
                            ) === 1;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('waitForPostProcess heartbeat failed: ' . $e->getMessage());
                }
                if ($claimHeartbeatResult === false) {
                    throw new RuntimeException(
                        'Writing chapter claim or task lease was lost while waiting for post-processing.'
                    );
                }
            }
            if (!self::hasDurablePostProcessPending($novelId, $chNum)
                && !file_exists($flag)) {
                require_once __DIR__ . '/constraints/ConstraintProjectionService.php';
                ConstraintProjectionService::refresh($novelId);
                $waited = HarnessRuntime::unixTime() - $startedAt;
                addLog($novelId, 'info', "已等待第{$chNum}章后处理完成（{$waited}秒），开始写下一章");
                return;
            }
        }
        // 超时不放行：保留 pp_pending，让后处理继续跑；调用方释放本章认领后重试。
        // 若后处理已僵死，findActiveTask 会按龄清理 flag 并写入降级标记。
        addLog($novelId, 'warn', "等待第{$chNum}章后处理超时（{$maxWaitSec}秒），不放行下一章（记忆/约束尚未入库）");
        throw new WriteEnginePostProcessPendingException("第{$chNum}章后处理尚未完成，请稍后重试");
    }

    private static function launchPostprocessWorker(): void
    {
        try {
            require_once __DIR__ . '/tasks/PostprocessWorkerLauncher.php';
            \PostprocessWorkerLauncher::launch();
        } catch (\Throwable $e) {
            error_log('launchPostprocessWorker failed: ' . $e->getMessage());
        }
    }

    /**
     * P1-7 优化：检测并消费后处理降级标记
     *
     * 由 buildPrompt 调用。若检测到前一章的降级标记，返回提示文本注入 prompt，
     * 并清除标记（一次性消费）。返回空字符串表示无降级。
     *
     * @param int $novelId      小说ID
     * @param int $chapterNum   当前章节号（检查前一章的标记）
     * @return string 降级提示文本（空字符串表示无降级）
     */
    private static function consumePostProcessDegradedFlag(int $novelId, int $chapterNum): string
    {
        $prevChNum = $chapterNum - 1;
        if ($prevChNum < 1) return '';

        $degradedFlag = CFG_PROGRESS_DIR . "/pp_degraded_n{$novelId}_c{$prevChNum}.marker";
        if (!file_exists($degradedFlag)) return '';

        $content = @file_get_contents($degradedFlag);
        @unlink($degradedFlag); // 一次性消费

        $data = json_decode($content ?: '', true);
        $waitSec = is_array($data) ? (int)($data['wait_seconds'] ?? 0) : 0;

        addLog($novelId, 'info', "检测到第{$prevChNum}章后处理降级标记，本章将注入衔接保护提示");
        return "【衔接保护】前章（第{$prevChNum}章）的后处理（摘要/记忆/约束状态更新）未完全完成"
            . ($waitSec > 0 ? "（等待{$waitSec}秒超时）" : '')
            . "。本章写作时请注意：\n"
            . "1. 前章结尾衔接请以正文实际内容为准，不要依赖摘要\n"
            . "2. 若发现角色状态/伏笔线索与前文有出入，以前文正文为准\n"
            . "3. 避免引用可能未更新的记忆数据\n";
    }

    /**
     * W-12 修复（审计 2026-08-30 M-5 提取为公共方法）：按龄清理孤立的 pp_pending flag。
     *
     * 背景：write_start.php 在持有 novels/chapters 行锁的事务内部调用
     * findActiveTask，事务内清理被主动跳过（持锁复验不得改 DB / 补跑后处理）。
     * 此前该清理只挂在 findActiveTask 的懒触发上——纯 Web 用户的 write_start
     * 路径永远处于事务内 → stale pp_pending 永不清理 → waitForPostProcess
     * 每次硬等 180 秒后抛异常，形成"每次重试都失败"的小说级死锁。
     * 现在提取为公共方法，write_start 在事务外（获取行锁前）显式调用，
     * 事务内调用则保持原有短路语义。
     *
     * @return int 本次清理（含恢复或降级）的 flag 数量
     */
    public static function cleanStalePostProcessFlags(int $novelId): int
    {
        $progressDir = CFG_PROGRESS_DIR;
        if (!is_dir($progressDir)) return 0;

        // 持锁事务内不得改 DB / 补跑后处理，避免预占事务被误 commit 或长时间占锁。
        $inCallerTx = false;
        try {
            $inCallerTx = DB::connect()->inTransaction();
        } catch (\Throwable $e) {
            $inCallerTx = false;
        }
        if ($inCallerTx) return 0;

        $ppFlags = glob($progressDir . '/pp_pending_' . $novelId . '_*.flag') ?: [];
        $ppWaitSec = function_exists('getSystemSetting')
            ? max(60, (int)getSystemSetting('ws_pp_wait_sec', 300, 'int'))
            : 300;
        $ppStaleAfter = $ppWaitSec + 30;
        $cleaned = 0;
        foreach ($ppFlags as $flagFile) {
            if (preg_match('/pp_pending_\d+_(\d+)\.flag$/', basename($flagFile), $m)) {
                $ppChNum = (int)$m[1];
                $mtime = @filemtime($flagFile);
                if ($mtime !== false && (HarnessRuntime::unixTime() - $mtime) > $ppStaleAfter) {
                    // 审计修复（2026-08-17 写作逻辑 D1）：stale pp_pending 不再裸放行。
                    // 该章 completed 且记忆未最终化时，重新入队并同步补跑后处理；
                    // 补跑成功（或本已 finalized）才允许下一章继续，否则保留降级标记，
                    // 由 resolveChapter 的 assertNoDegradedPostProcess 继续拦截。
                    $recovered = self::recoverStalePostProcess($novelId, $ppChNum);
                    @unlink($flagFile);
                    if (!$recovered) {
                        $degradedFlag = CFG_PROGRESS_DIR . "/pp_degraded_n{$novelId}_c{$ppChNum}.marker";
                        @file_put_contents($degradedFlag, json_encode([
                            'novel_id'     => $novelId,
                            'chapter'      => $ppChNum,
                            'degraded_at'  => HarnessRuntime::unixTime(),
                            'wait_seconds' => $ppStaleAfter,
                            'reason'       => 'stale_pp_pending',
                        ]));
                    }
                    addLog($novelId, 'info', "Cleaned stale pp_pending flag for chapter {$ppChNum} after {$ppStaleAfter}s (recovered=" . var_export($recovered, true) . ')');
                    $cleaned++;
                }
            }
        }
        return $cleaned;
    }

    /**
     * 扫描进度目录，返回指定小说当前活跃（非僵死）的写作任务。
     * 顺带清理已完成/僵死的进度文件并复位相关 DB 状态。
     *
     * write_start.php（异步启动）与 write_chapter.php（SSE 直连）共用，
     * 保证两个写作入口受同一套并发守卫约束——此前 SSE 直连没有任何检查，
     * 前端回退到该入口会把正在写作的后台任务章节强制重置，造成双写。
     *
     * @return ?array 活跃任务的进度数据（含 task_file），无活跃任务返回 null
     */
    public static function findActiveTask(int $novelId): ?array
    {
        $progressDir = CFG_PROGRESS_DIR;
        if (!is_dir($progressDir)) return null;

        $staleTimeout = CFG_ZOMBIE_PROGRESS;

        $inCallerTx = false;
        try {
            $inCallerTx = DB::connect()->inTransaction();
        } catch (\Throwable $e) {
            $inCallerTx = false;
        }

        foreach (glob($progressDir . '/w*.json') ?: [] as $existingFile) {
            $fp = @fopen($existingFile, 'r');
            if (!$fp) continue;
            flock($fp, LOCK_SH);
            $data = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            $p = json_decode($data, true);
            if (!is_array($p)) {
                // 审计修复 E3（2026-09-27）：半截 JSON（进度文件非原子写入的
                // 崩溃残留）只删 .json 会漏删伴生 .content（正文侧车）与
                // .sh——对比下方取消路径（.content 三连删）与僵死路径均清理。
                @unlink($existingFile);
                @unlink($existingFile . '.content');
                @unlink(preg_replace('/\.json$/', '.sh', $existingFile));
                continue;
            }

            $fileNovelId = (int)($p['novel_id'] ?? 0);
            $fileStatus  = $p['status'] ?? '';

            // ① 已完成/报错的进度文件直接清理，不阻塞新任务
            if (in_array($fileStatus, ['done', 'error', 'completed'])) {
                // 审计修复 E3（2026-09-27）：同步清理 .content 侧车与 .sh，
                // 防止终态任务残留半截正文文件占盘并污染后续同名重建。
                @unlink($existingFile);
                @unlink($existingFile . '.content');
                @unlink(preg_replace('/\.json$/', '.sh', $existingFile)); // 清理对应 wrapper.sh
                continue;
            }

            // ② 不属于当前小说的进度文件，跳过（不干预其他小说）
            if ($fileNovelId !== $novelId) continue;

            // 用户已取消的任务不得再当作活跃任务返回，否则 write_start 会
            // 把正在停写的旧进度恢复给前端，表现为「取消后再写立刻失败」。
            $taskIdFromFile = preg_replace('/\.json$/', '', basename($existingFile));
            if (is_string($taskIdFromFile) && $taskIdFromFile !== '') {
                try {
                    if (!class_exists('WritingTaskRepository', false)) {
                        require_once __DIR__ . '/tasks/WritingTaskRepository.php';
                    }
                    if (\WritingTaskRepository::isCancelRequested($taskIdFromFile)) {
                        @unlink($existingFile);
                        @unlink(preg_replace('/\.json$/', '.sh', $existingFile));
                        @unlink($existingFile . '.content');
                        continue;
                    }
                } catch (\Throwable $e) {
                    // 非法文件名或任务表不可用时回退到后续僵死/状态判断
                }
            }

            // ③ 僵死检测：优先相信可验证的进程存活状态。
            // 预处理、Embedding 和 Prompt 构建都可能长时间没有正文 chunk；仅凭
            // updated_at 超时清理，会删除仍在工作的任务并启动第二个生成进程。
            $updatedAt = (int)($p['updated_at'] ?? 0);
            $startedAt = (int)($p['started_at'] ?? 0);
            $refTime   = $updatedAt > 0 ? $updatedAt : $startedAt;
            $isStale   = ($refTime > 0 && (HarnessRuntime::unixTime() - $refTime) > $staleTimeout);

            $pidChecked = false;
            $pidAlive = false;
            if (!empty($p['pid'])) {
                $pid = (int)$p['pid'];
                if ($pid > 0) {
                    if (PHP_OS_FAMILY === 'Windows') {
                        if (function_exists('exec')) {
                            // H-11 修复（2026-07-25）：原代码无论 exec 是否成功都设 $pidChecked=true，
                            // 若 exec 被 disable_functions 禁用或命令执行失败（$out 为空），
                            // $pidAlive=false 会把活跃进程误判为僵尸并清理，触发双写。
                            // 改为：通过 exit code 和输出判断 exec 是否真正执行成功，
                            // 仅在确认命令执行成功时才信任结果，否则回退到时间戳判断。
                            $out = [];
                            $exitCode = 1;
                            @exec("tasklist /FI \"PID eq {$pid}\" /NH 2>nul", $out, $exitCode);
                            // tasklist 成功时总有输出（PID 行或 "No tasks" 提示）；
                            // 输出为空表示 exec 未真正执行（被禁用/命令不存在）
                            $execRan = ($exitCode === 0) || !empty($out);
                            if ($execRan) {
                                $pidChecked = true;
                                $pidAlive = !empty($out) && preg_match('/\b' . $pid . '\b/', implode('', $out)) === 1;
                            }
                            // execRan=false 时 $pidChecked 保持 false，回退到时间戳判断
                        }
                    } elseif (is_dir('/proc')) {
                        $pidChecked = true;
                        $pidAlive = file_exists("/proc/{$pid}");
                    } elseif (function_exists('posix_kill')) {
                        $pidChecked = true;
                        $pidAlive = @posix_kill($pid, 0);
                    }
                }
            }

            if ($pidChecked) {
                $taskAge = $startedAt > 0 ? HarnessRuntime::unixTime() - $startedAt : 0;
                $hardStaleTimeout = max(1800, $staleTimeout * 4);
                // 已退出进程立即回收；仍存活进程只有在同时超过硬上限且心跳陈旧时
                // 才视为挂死，避免无限保留真正卡住的进程。
                $isStale = !$pidAlive || ($isStale && $taskAge > $hardStaleTimeout);
            }

            if ($isStale) {
                // 持锁复验不得改 DB / 补跑后处理，避免预占事务被误 commit 或长时间占锁。
                if ($inCallerTx) {
                    continue;
                }
                // W-24 修复：先 rename 到 .stale 后台再删除——避免 Windows 下若另一进程刚开始写入该文件
                // 时被并发删除（advisory lock 在 Windows 下不完全可靠）。rename 是原子操作。
                $stalePath = $existingFile . '.stale.' . getmypid();
                if (@rename($existingFile, $stalePath)) {
                    @unlink($stalePath);
                } else {
                    @unlink($existingFile);  // rename 失败则直接删（旧行为）
                }
                @unlink(preg_replace('/\.json$/', '.sh', $existingFile));
                @unlink($existingFile . '.content');  // W-17 拆分内容文件一并清理
                if ($fileNovelId > 0) {
                    // 进度文件只是一份可丢失的投影，不能作为章节所有权凭据。统一走
                    // Canon-aware 的 DB 恢复：它会锁行、复验真实心跳与后继任务，并把
                    // 已提交 Canon 恢复为 completed，而不是盲目降级成 outlined。
                    $recovered = self::recoverStaleWritingChapters($fileNovelId);
                    addLog(
                        $fileNovelId,
                        'warn',
                        "清理僵死写作任务（超时{$staleTimeout}秒无响应，恢复" . count($recovered) . '章）'
                    );
                }
                continue;
            }

            // ④ 当前小说确实有任务在进行中（且非僵死）
            // 审计修复（2026-08-16 P1，A-1）：saved/postprocessing 是正文落盘后的
            // 后处理中间态（20+ 子任务，可达数分钟），此前不在活跃白名单 → 守卫在该
            // 窗口返回 null，SSE/daemon 可预占下一章，与后处理并发读写记忆表。
            // 两态不进 ① 清理列表；③ 僵死判定照常生效（心跳超时/PID 退出仍会回收）。
            if (in_array($fileStatus, ['starting', 'writing', 'waiting', 'saved', 'postprocessing'])) {
                // 生成中的进度文件：用户刚取消则不得再阻塞新写作。
                // saved/postprocessing 仍视为活跃，避免下一章与记忆入库并发。
                if (in_array($fileStatus, ['starting', 'writing', 'waiting'], true)
                    && file_exists(self::cancelFlagPath($novelId))) {
                    continue;
                }
                $p['task_file'] = $existingFile;
                return $p;
            }
        }

        // W-12 修复：扫描孤立的 pp_pending flag——基于文件年龄清理（超过等待上限即视为
        // 僵死残留），避免 worker 进程崩溃后留下的 stale flag 让下一章空等 300s 超时。
        // 文件名格式必须与 postProcessPendingFlag() 一致：pp_pending_{novelId}_{chNum}.flag。
        // （历史 bug：曾用 pp_pending_n{id}_c{ch} 格式 glob，与生成器不符 → 永不匹配 = 死代码。）
        self::cleanStalePostProcessFlags($novelId);

        // cleanStalePostProcessFlags has its own local timeout. The stale
        // evidence cleanup below must retain the same age threshold here.
        $ppWaitSec = function_exists('getSystemSetting')
            ? max(60, (int)getSystemSetting('ws_pp_wait_sec', 300, 'int'))
            : 300;
        $ppStaleAfter = $ppWaitSec + 30;

        // 审计修复（2026-08-16 #13）：补齐两类残留文件的按龄清理——
        // a) pp_pending_*.flag.stale.<pid>：waitForPostProcess 超时 rename 的证据文件
        //    （M-15 修复产物），原清理 glob 以 .flag 结尾不匹配 .stale 变体，且无任何
        //    消费/清理方，每次等待超时都永久残留一个文件；
        // b) pp_degraded_n{id}_c{ch}.marker：后处理降级标记（P1-7），正常由下一章
        //    buildPrompt 一次性消费；但全书完结/停更时无人消费，超 24h 后兜底清理。
        foreach (glob($progressDir . '/pp_pending_' . $novelId . '_*.flag.stale.*') ?: [] as $staleFlagFile) {
            $staleFlagMtime = @filemtime($staleFlagFile);
            if ($staleFlagMtime !== false && (HarnessRuntime::unixTime() - $staleFlagMtime) > $ppStaleAfter) {
                @unlink($staleFlagFile);
            }
        }
        foreach (glob($progressDir . '/pp_degraded_n' . $novelId . '_c*.marker') ?: [] as $oldDegradedFile) {
            $degradedMtime = @filemtime($oldDegradedFile);
            if ($degradedMtime !== false && (HarnessRuntime::unixTime() - $degradedMtime) > 86400) {
                @unlink($oldDegradedFile);
            }
        }

        // W-11 修复：进度文件未找到时，查 DB 兜底——避免进度文件意外丢失时 zombie 检测失效。
        // 若 DB 中存在 status='writing' 且 updated_at 在 zombie 阈值内的章节，视为活跃任务。
        // 用户刚取消时取消标志仍在：章节可能尚未从 writing 清掉，不得据此锁死新写作。
        try {
            if (file_exists(self::cancelFlagPath($novelId))) {
                return null;
            }
            $staleSeconds = defined('CFG_ZOMBIE_DB') ? CFG_ZOMBIE_DB : 300;
            $dbActive = DB::fetch(
                'SELECT ch.id, ch.chapter_number FROM chapters ch
                 WHERE ch.novel_id=? AND ch.status="writing"
                   AND (
                       (ch.updated_at IS NOT NULL
                        AND ch.updated_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$staleSeconds . ' SECOND))
                       OR EXISTS (
                           SELECT 1 FROM writing_tasks wt
                           WHERE wt.chapter_id=ch.id AND wt.cancel_requested=0
                             AND wt.state IN ("preparing","generating","validating","content_saved","postprocessing")
                             AND wt.lease_expires_at IS NOT NULL AND wt.lease_expires_at>=NOW()
                       )
                       OR EXISTS (
                           SELECT 1 FROM postprocess_jobs pj
                           WHERE pj.chapter_id=ch.id
                             AND (pj.state IN ("queued","retry")
                                  OR (pj.state="running" AND pj.lease_expires_at IS NOT NULL
                                      AND pj.lease_expires_at>=NOW()))
                       )
                   )
                 ORDER BY ch.chapter_number ASC LIMIT 1',
                [$novelId]
            );
            if ($dbActive) {
                return [
                    'status'     => 'writing',
                    'novel_id'   => $novelId,
                    'chapter_id' => (int)$dbActive['id'],
                    'task_file'  => null,
                    '_db_only'   => true,  // 标记为 DB 兜底信号（无进度文件）
                ];
            }
        } catch (\Throwable $e) {
            // DB 不可用时静默降级
        }

        return null;
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 D1）：僵死 pp_pending 的恢复路径。
     *
     * 对应章 completed 且无 memory_finalized 日志 → 重新 enqueueAndRun 同步补跑
     * 后处理（成功后记忆入库，标记可清除）；已 finalized 或章未完成（重写中/
     * failed 等）→ 无需恢复。补跑异常或返回 retry/failed 状态 → 返回 false，
     * 调用方保留 pp_degraded 降级标记，下一章会被 assertNoDegradedPostProcess 拦下。
     */
    private static function recoverStalePostProcess(int $novelId, int $chNum): bool
    {
        try {
            $ch = DB::fetch(
                'SELECT id, novel_id, chapter_number FROM chapters
                 WHERE novel_id=? AND chapter_number=? AND status="completed" LIMIT 1',
                [$novelId, $chNum]
            );
            if (!$ch) {
                // 未完成章（failed/重写中）没有可补的记忆投影，按无需恢复处理。
                return true;
            }
            $finalized = DB::fetch(
                'SELECT id FROM writing_logs
                 WHERE novel_id=? AND chapter_id=? AND action="memory_finalized" LIMIT 1',
                [$novelId, (int)$ch['id']]
            );
            if ($finalized) {
                return true;
            }
            require_once __DIR__ . '/tasks/PostprocessRunner.php';
            $result = PostprocessRunner::enqueueAndRun(
                $novelId,
                ['id' => (int)$ch['id']],
                '',
                null,
                'stale-pp-recover:' . getmypid()
            );
            $state = (string)($result['state'] ?? '');
            addLog($novelId, 'warn', "第{$chNum}章僵死后处理已重新入队补跑（state={$state}），不放行下一章直至完成");
            return in_array($state, ['done', 'stale'], true);
        } catch (\Throwable $e) {
            error_log('recoverStalePostProcess failed: ' . $e->getMessage());
            addLog($novelId, 'warn', "第{$chNum}章僵死后处理补跑失败：{$e->getMessage()}");
            return false;
        }
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 D1）：pp_degraded 降级标记未消除前不放行。
     *
     * 前一章存在降级标记且该章尚无 memory_finalized 日志 → 抛
     * WriteEnginePostProcessPendingException，SSE/worker 释放认领、daemon 走
     * retry_later。仅当记忆已最终化（或用户显式删除标记）才允许继续写下一章；
     * 已最终化的陈旧标记在此处顺带清除。
     */
    private static function assertNoDegradedPostProcess(int $novelId, int $prevChNum): void
    {
        if ($prevChNum < 1) return;
        $degradedFlag = CFG_PROGRESS_DIR . "/pp_degraded_n{$novelId}_c{$prevChNum}.marker";
        if (!file_exists($degradedFlag)) return;
        try {
            $finalized = DB::fetch(
                'SELECT c.id FROM chapters c
                 JOIN writing_logs wl
                   ON wl.novel_id=c.novel_id AND wl.chapter_id=c.id AND wl.action="memory_finalized"
                 WHERE c.novel_id=? AND c.chapter_number=? AND c.status="completed"
                 LIMIT 1',
                [$novelId, $prevChNum]
            );
            if ($finalized) {
                @unlink($degradedFlag);
                addLog($novelId, 'info', "第{$prevChNum}章记忆已最终化，清除后处理降级标记");
                return;
            }
        } catch (\Throwable $e) {
            error_log('assertNoDegradedPostProcess probe failed: ' . $e->getMessage());
        }
        throw new WriteEnginePostProcessPendingException(
            "第{$prevChNum}章后处理降级未恢复（记忆未入库），本章暂缓，稍后自动重试"
        );
    }

    /**
     * 原子回收没有心跳、已经超过 DB 僵死阈值的 writing 章节。
     *
     * 该方法不会触碰阈值窗口内的活跃章节。调用方应先用 findActiveTask()
     * 排除仍有有效进度文件的前台任务，再在启动新任务前执行本恢复步骤。
     *
     * @return array<int, array{id:int, chapter_number:int}> 已恢复的章节
     */
    /**
     * Recover the old completed Canon after a post-process rewrite worker died
     * between acquiring its chapter claim and entering ChapterCanonTransition.
     *
     * This method never changes content or Canon metadata. It only releases
     * the exact rewrite claim after authenticating all three owners of that
     * claim: the frozen old Canon, its draft Rewrite Candidate, and the current
     * fenced post-process job. A caller that cannot prove all three identities
     * must leave the row untouched and retry later.
     *
     * @param array{owner:string,token:string,generation:int} $postprocessLease
     */
    public static function recoverOrphanedRewriteClaim(
        int $novelId,
        int $chapterId,
        int $postprocessJobId,
        string $expectedRevisionHash,
        string $expectedClaimToken,
        WritePolicySnapshot $policySnapshot,
        array $postprocessLease
    ): bool {
        if ($novelId <= 0 || $chapterId <= 0 || $postprocessJobId <= 0) {
            throw new InvalidArgumentException('Rewrite-claim recovery requires positive identities.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedRevisionHash) !== 1) {
            throw new InvalidArgumentException('Rewrite-claim recovery revision is invalid.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $expectedClaimToken) !== 1) {
            throw new InvalidArgumentException('Rewrite-claim recovery token is invalid.');
        }
        $leaseOwner = $postprocessLease['owner'] ?? null;
        $leaseToken = $postprocessLease['token'] ?? null;
        $fenceGeneration = $postprocessLease['generation'] ?? null;
        if (!is_string($leaseOwner) || trim($leaseOwner) === ''
            || !is_string($leaseToken)
            || preg_match('/^[a-f0-9]{32}$/D', $leaseToken) !== 1
            || (!is_int($fenceGeneration)
                && !(is_string($fenceGeneration)
                    && preg_match('/^[1-9][0-9]*$/D', $fenceGeneration) === 1))
            || (int)$fenceGeneration <= 0) {
            throw new InvalidArgumentException('Rewrite-claim recovery lease is invalid.');
        }
        $leaseOwner = trim($leaseOwner);
        $fenceGeneration = (int)$fenceGeneration;

        $pdo = DB::connect();
        if ($pdo->inTransaction()) {
            throw new LogicException('Rewrite-claim recovery requires an independent transaction.');
        }
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Unable to start rewrite-claim recovery transaction.');
        }

        try {
            $chapter = DB::fetch(
                'SELECT id, novel_id, status, content, words, claim_token,
                        canonical_state, content_revision_hash,
                        canonical_candidate_uid, write_policy_snapshot_json
                 FROM chapters
                 WHERE id=? AND novel_id=? LIMIT 1
                 FOR UPDATE',
                [$chapterId, $novelId]
            );
            if (!is_array($chapter)) {
                $pdo->commit();
                return false;
            }

            $status = (string)($chapter['status'] ?? '');
            $content = $chapter['content'] ?? null;
            $revisionHash = $chapter['content_revision_hash'] ?? null;
            $parentCandidateUid = $chapter['canonical_candidate_uid'] ?? null;
            $chapterPolicyJson = $chapter['write_policy_snapshot_json'] ?? null;
            $currentClaimToken = (string)($chapter['claim_token'] ?? '');
            $canonAuthenticated = in_array($status, ['writing', 'completed'], true)
                && (string)($chapter['canonical_state'] ?? '') === 'canonical'
                && is_string($content)
                && is_string($revisionHash)
                && hash_equals($expectedRevisionHash, $revisionHash)
                && hash_equals($revisionHash, hash('sha256', $content))
                && is_string($parentCandidateUid)
                && preg_match('/^[a-f0-9]{32}$/D', $parentCandidateUid) === 1
                && is_string($chapterPolicyJson)
                && $chapterPolicyJson !== '';
            if (!$canonAuthenticated
                || ($status === 'writing' && !hash_equals($expectedClaimToken, $currentClaimToken))
                || ($status === 'completed' && $currentClaimToken !== '')) {
                $pdo->commit();
                return false;
            }
            try {
                $chapterPolicy = WritePolicySnapshot::fromJson($chapterPolicyJson);
            } catch (Throwable) {
                $pdo->commit();
                return false;
            }
            if (!hash_equals($policySnapshot->checksum(), $chapterPolicy->checksum())) {
                $pdo->commit();
                return false;
            }

            $sourceTaskId = 'postprocess_' . $postprocessJobId;
            $candidateRows = DB::fetchAll(
                "SELECT * FROM chapter_candidates
                 WHERE novel_id=? AND chapter_id=? AND source='rewrite'
                   AND source_task_id=? AND parent_candidate_uid=?
                   AND base_revision_hash=? AND lifecycle='draft'
                   AND gate_state='pending' AND validation_json IS NULL
                 ORDER BY id DESC
                 FOR UPDATE",
                [
                    $novelId,
                    $chapterId,
                    $sourceTaskId,
                    $parentCandidateUid,
                    $expectedRevisionHash,
                ]
            );
            $matchingCandidate = null;
            foreach ($candidateRows as $candidate) {
                $candidateUid = $candidate['candidate_uid'] ?? null;
                $candidateContent = $candidate['inline_content'] ?? null;
                $candidateHash = $candidate['content_hash'] ?? null;
                $candidatePolicyJson = $candidate['policy_snapshot_json'] ?? null;
                $candidatePolicyHash = $candidate['policy_snapshot_hash'] ?? null;
                $baseJson = $candidate['base_snapshot_json'] ?? null;
                if (!is_string($candidateUid)
                    || preg_match('/^[a-f0-9]{32}$/D', $candidateUid) !== 1
                    || !hash_equals(
                        $expectedClaimToken,
                        substr(hash('sha256', 'rewrite_claim_v1:' . $candidateUid), 0, 32)
                    )
                    || !is_string($candidateContent)
                    || !is_string($candidateHash)
                    || !hash_equals($candidateHash, hash('sha256', $candidateContent))
                    || (int)($candidate['words'] ?? -1) !== countWords($candidateContent)
                    || !is_string($candidatePolicyJson)
                    || !is_string($candidatePolicyHash)
                    || !hash_equals($policySnapshot->checksum(), $candidatePolicyHash)
                    || !is_string($baseJson)) {
                    continue;
                }
                try {
                    $candidatePolicy = WritePolicySnapshot::fromJson($candidatePolicyJson);
                    $baseSnapshot = json_decode($baseJson, true, 16, JSON_THROW_ON_ERROR);
                    if (!is_array($baseSnapshot) || array_is_list($baseSnapshot)) {
                        continue;
                    }
                    $baseSnapshot = self::validateChapterBaseSnapshot(
                        $baseSnapshot,
                        $expectedClaimToken
                    );
                } catch (Throwable) {
                    continue;
                }
                if (!hash_equals($policySnapshot->checksum(), $candidatePolicy->checksum())
                    || $baseSnapshot['status'] !== 'completed'
                    || $baseSnapshot['canonical_state'] !== 'canonical'
                    || !is_string($baseSnapshot['content_revision_hash'])
                    || !hash_equals($expectedRevisionHash, $baseSnapshot['content_revision_hash'])
                    || !is_string($baseSnapshot['content_hash'])
                    || !hash_equals($expectedRevisionHash, $baseSnapshot['content_hash'])) {
                    continue;
                }
                if ($matchingCandidate !== null) {
                    // One deterministic claim token must identify exactly one
                    // immutable Candidate. Ambiguity is corruption, not a cue
                    // to guess which draft owns the chapter.
                    $pdo->commit();
                    return false;
                }
                $matchingCandidate = $candidate;
            }
            if ($matchingCandidate === null) {
                $pdo->commit();
                return false;
            }

            // Lock order intentionally matches ChapterCanonTransition:
            // chapter -> Candidate -> predecessor job.
            $job = DB::fetch(
                "SELECT id FROM postprocess_jobs
                 WHERE id=? AND novel_id=? AND chapter_id=? AND revision_hash=?
                   AND state='running' AND lease_owner=? AND lease_token=?
                   AND fence_generation=? AND accepted_candidate_uid=?
                   AND rewrite_allowed=1
                 LIMIT 1 FOR UPDATE",
                [
                    $postprocessJobId,
                    $novelId,
                    $chapterId,
                    $expectedRevisionHash,
                    $leaseOwner,
                    $leaseToken,
                    $fenceGeneration,
                    $parentCandidateUid,
                ]
            );
            if (!is_array($job)) {
                $pdo->commit();
                return false;
            }

            if ($status === 'writing') {
                $restored = DB::execute(
                    "UPDATE chapters
                     SET status='completed', claim_token=''
                     WHERE id=? AND novel_id=? AND status='writing'
                       AND claim_token=? AND canonical_state='canonical'
                       AND content_revision_hash=? AND canonical_candidate_uid=?
                       AND content=? AND write_policy_snapshot_json=?",
                    [
                        $chapterId,
                        $novelId,
                        $expectedClaimToken,
                        $expectedRevisionHash,
                        $parentCandidateUid,
                        $content,
                        $chapterPolicyJson,
                    ]
                );
                if ($restored !== 1) {
                    $pdo->rollBack();
                    return false;
                }
            }

            $pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Locate the immutable pre-claim snapshot for one stale initial-write
     * claim. Async workers freeze it on writing_tasks; SSE/daemon attempts may
     * already have frozen the same payload on a draft Candidate. The random
     * claim token is the authority that distinguishes the current attempt from
     * every historical task/Candidate for this chapter.
     *
     * @param array<string,mixed> $chapter
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}|null
     */
    private static function authenticatedStaleBaseSnapshot(
        int $novelId,
        array $chapter
    ): ?array {
        $chapterId = (int)($chapter['id'] ?? 0);
        $claimToken = (string)($chapter['claim_token'] ?? '');
        if ($novelId <= 0
            || $chapterId <= 0
            || preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1) {
            return null;
        }

        // Candidate is locked before task everywhere in the Canon path. These
        // rows are immutable after creation/freezing, so consistent reads are
        // sufficient and avoid introducing a reverse lock edge here.
        $snapshotRows = DB::fetchAll(
            'SELECT base_snapshot_json AS snapshot_json
             FROM chapter_candidates
             WHERE novel_id=? AND chapter_id=? AND base_snapshot_json IS NOT NULL
             ORDER BY id DESC',
            [$novelId, $chapterId]
        );
        $taskSnapshotRows = DB::fetchAll(
            'SELECT base_chapter_snapshot_json AS snapshot_json
             FROM writing_tasks
             WHERE novel_id=? AND chapter_id=?
               AND base_chapter_snapshot_json IS NOT NULL
             ORDER BY id DESC',
            [$novelId, $chapterId]
        );
        foreach ($taskSnapshotRows as $taskSnapshotRow) {
            $snapshotRows[] = $taskSnapshotRow;
        }

        $currentCanonicalState = (string)($chapter['canonical_state'] ?? 'none');
        if ($currentCanonicalState === '') {
            $currentCanonicalState = 'none';
        }
        $currentRevisionHash = $chapter['content_revision_hash'] ?? null;
        if ($currentRevisionHash === '') {
            $currentRevisionHash = null;
        }
        $currentContent = (string)($chapter['content'] ?? '');
        $currentContentHash = $currentContent === ''
            ? null
            : hash('sha256', $currentContent);

        $authenticated = null;
        foreach ($snapshotRows as $snapshotRow) {
            $json = $snapshotRow['snapshot_json'] ?? null;
            if (!is_string($json) || $json === '') {
                continue;
            }
            try {
                $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || array_is_list($decoded)) {
                    continue;
                }
                $snapshot = self::validateChapterBaseSnapshot($decoded, $claimToken);
            } catch (Throwable) {
                continue;
            }

            // Only states that can legitimately precede an initial-write claim
            // may be restored here. A completed Canon is handled by the
            // authenticated Canon branch below/above, never by this helper.
            if (!in_array($snapshot['status'], ['outlined', 'skipped', 'failed'], true)
                || $snapshot['canonical_state'] !== $currentCanonicalState
                || $snapshot['content_revision_hash'] !== $currentRevisionHash
                || $snapshot['content_hash'] !== $currentContentHash) {
                continue;
            }

            if ($authenticated !== null && $authenticated !== $snapshot) {
                error_log(
                    "recoverStaleWritingChapters found conflicting base snapshots: chapter={$chapterId}"
                );
                return null;
            }
            $authenticated = $snapshot;
        }

        return $authenticated;
    }

    /**
     * @return array<int,array{id:int,chapter_number:int,restored_status:string,snapshot_authenticated:bool}>
     */
    public static function recoverStaleWritingChapters(int $novelId): array
    {
        if ($novelId <= 0) return [];

        $recoveryProjectionFence = NovelProjectionFence::acquire($novelId, 30);
        try {
        $staleSeconds = defined('CFG_ZOMBIE_DB') ? CFG_ZOMBIE_DB : 300;
        $pdo = DB::connect();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx && !$pdo->beginTransaction()) {
            throw new RuntimeException('无法开启僵死写作恢复事务');
        }

        try {
            $stuck = DB::fetchAll(
                'SELECT ch.id, ch.chapter_number, ch.content, ch.claim_token,
                        ch.canonical_state, ch.content_revision_hash,
                        ch.canonical_candidate_uid, ch.write_policy_snapshot_json
                 FROM chapters ch
                 WHERE ch.novel_id=? AND ch.status="writing"
                   AND (ch.updated_at IS NULL OR ch.updated_at < DATE_SUB(NOW(), INTERVAL ' . (int)$staleSeconds . ' SECOND))
                   AND NOT EXISTS (
                       SELECT 1 FROM writing_tasks wt
                       WHERE wt.chapter_id=ch.id AND wt.cancel_requested=0
                         AND wt.state IN ("preparing","generating","validating","content_saved","postprocessing")
                         AND wt.lease_expires_at IS NOT NULL AND wt.lease_expires_at>=NOW()
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM postprocess_jobs pj
                       WHERE pj.chapter_id=ch.id
                         AND (pj.state IN ("queued","retry")
                              OR (pj.state="running" AND pj.lease_expires_at IS NOT NULL
                                  AND pj.lease_expires_at>=NOW()))
                   )
                 FOR UPDATE',
                [$novelId]
            );
            $recovered = [];
            foreach ($stuck as $row) {
                $chapterId = (int)$row['id'];
                $restoredStatus = 'failed';
                $snapshotAuthenticated = false;
                if ((string)($row['canonical_state'] ?? '') === 'canonical') {
                    // A rewrite claim wraps an already accepted Canon. Zombie
                    // cleanup may release that claim, but must never downgrade
                    // the old Canon to an initial-write state.
                    $content = $row['content'] ?? null;
                    $revisionHash = $row['content_revision_hash'] ?? null;
                    $candidateUid = $row['canonical_candidate_uid'] ?? null;
                    $policyJson = $row['write_policy_snapshot_json'] ?? null;
                    $claimToken = $row['claim_token'] ?? null;
                    $authenticated = is_string($content)
                        && is_string($revisionHash)
                        && preg_match('/^[a-f0-9]{64}$/D', $revisionHash) === 1
                        && hash_equals($revisionHash, hash('sha256', $content))
                        && is_string($candidateUid)
                        && preg_match('/^[a-f0-9]{32}$/D', $candidateUid) === 1
                        && is_string($policyJson)
                        && $policyJson !== ''
                        && is_string($claimToken)
                        && preg_match('/^[a-f0-9]{32}$/D', $claimToken) === 1;
                    if ($authenticated) {
                        try {
                            WritePolicySnapshot::fromJson($policyJson);
                        } catch (Throwable) {
                            $authenticated = false;
                        }
                    }
                    if (!$authenticated) {
                        error_log(
                            "recoverStaleWritingChapters refused unauthenticated Canon claim: chapter={$chapterId}"
                        );
                        continue;
                    }
                    $affected = DB::update(
                        'chapters',
                        ['status' => 'completed', 'claim_token' => ''],
                        'id=? AND status="writing" AND claim_token=?'
                            . ' AND canonical_state="canonical" AND content_revision_hash=?'
                            . ' AND canonical_candidate_uid=? AND content=?'
                            . ' AND write_policy_snapshot_json=?',
                        [
                            $chapterId,
                            $claimToken,
                            $revisionHash,
                            $candidateUid,
                            $content,
                            $policyJson,
                        ]
                    );
                    $restoredStatus = 'completed';
                    $snapshotAuthenticated = true;
                } else {
                    // Never guess outlined for an orphaned initial-write claim.
                    // Restore the exact frozen base status only when a durable
                    // snapshot authenticates this row's current claim token and
                    // unchanged body/Canon metadata. Otherwise fail closed so a
                    // former skipped/failed chapter cannot silently re-enter
                    // automatic writing.
                    $baseSnapshot = self::authenticatedStaleBaseSnapshot($novelId, $row);
                    if ($baseSnapshot !== null) {
                        $restoredStatus = $baseSnapshot['status'];
                        $snapshotAuthenticated = true;
                    }
                    $claimToken = (string)($row['claim_token'] ?? '');
                    $affected = DB::update(
                        'chapters',
                        ['status' => $restoredStatus, 'claim_token' => ''],
                        'id=? AND novel_id=? AND status="writing" AND claim_token=?'
                            . ' AND COALESCE(canonical_state,"none")<>"canonical"',
                        [$chapterId, $novelId, $claimToken]
                    );
                }
                if ($affected > 0) {
                    $recovered[] = [
                        'id' => $chapterId,
                        'chapter_number' => (int)$row['chapter_number'],
                        'restored_status' => $restoredStatus,
                        'snapshot_authenticated' => $snapshotAuthenticated,
                    ];
                }
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 审计修复（2026-08-19）：僵死清理失败不得中止写作启动。
            // SSE 预占事务里调用本方法时 ownTx=false，原先 rethrow 会把
            // 整次点击写作打成「写作启动失败（追踪号）」。
            error_log('recoverStaleWritingChapters failed: ' . $e->getMessage());
            return [];
        }

        // 同一本书可能还有另一个带有效心跳的 writing 章节；仅在全部 writing
        // 都已清空时恢复小说状态，避免把真实活跃任务显示成 paused。
        // 审计修复（2026-08-16 #15a）：统一僵尸恢复口径为 paused（原 draft），
        // 与 daemon_write 恢复路径及 findActiveTask 僵尸清理保持一致。
        // 审计修复（2026-08-24 H1）：复位不再以「本次回收了僵死章节」为前提。
        // 章节认领与 novels 置 writing 恒在同一事务（见 resolveChapter 与
        // daemon_write 认领路径），因此「无任何 writing 章节但小说残留
        // writing」必然是孤儿状态（worker 在 saveChapter 提交后、Phase 5.5
        // 回写前被 SIGKILL/DB 瞬断的残留）；原先该场景无自愈路径，正是
        // daemon 挂机认领 changed-rows 死锁的残留来源。自愈先锁小说行再
        // 清点 writing 章节：并发认领事务若已提交，清点可见其 writing 章节
        // 而跳过复位；若未提交，本复位会阻塞至其提交后按最新行重评 WHERE，
        // 认领方最终胜出，不会把活跃任务误置 paused。
        try {
            $healOwnTx = !DB::connect()->inTransaction();
            if ($healOwnTx && !DB::beginTransaction()) {
                throw new RuntimeException('无法开启小说状态自愈事务');
            }
            try {
                DB::fetch('SELECT id FROM novels WHERE id=? FOR UPDATE', [$novelId]);
                $activeWriting = DB::count('chapters', 'novel_id=? AND status="writing"', [$novelId]);
                if ($activeWriting === 0) {
                    DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [$novelId]);
                }
                if ($healOwnTx) DB::commit();
            } catch (\Throwable $healError) {
                if ($healOwnTx && DB::connect()->inTransaction()) {
                    DB::rollBack();
                }
                throw $healError;
            }
            foreach ($recovered as $row) {
                if (($row['restored_status'] ?? 'failed') === 'completed') {
                    addLog(
                        $novelId,
                        'info',
                        "Chapter {$row['chapter_number']} restored to completed Canon after orphaned rewrite claim"
                    );
                    continue;
                }
                if (!empty($row['snapshot_authenticated'])) {
                    addLog(
                        $novelId,
                        'info',
                        "第{$row['chapter_number']}章按持久 base snapshot 恢复为 {$row['restored_status']}（僵死清理）"
                    );
                } else {
                    addLog(
                        $novelId,
                        'warn',
                        "第{$row['chapter_number']}章缺少可认证 base snapshot，安全恢复为 failed（僵死清理）"
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('recoverStaleWritingChapters finalize failed: ' . $e->getMessage());
        }

        return $recovered;
        } finally {
            NovelProjectionFence::release($recoveryProjectionFence);
        }
    }

    /**
     * 计划 Task 1：章节可写状态白名单（单一事实来源）。
     * 仅 outlined / skipped 可进入写作。chapters.status 枚举现已包含 'failed'
     * （新装 schema 定义 pending/outlined/writing/completed/skipped/failed），
     * 但 'failed' 仅保留给真正的写作/任务失败。内容已安全落盘但质检未过的章节
     * 从 2026-08-20 起仍以 completed 向后推进，并在 gate_results 中保留警告。
     * 自动选章还会额外应用 automaticChapterFrontier()，只沿章节号向前推进。
     */
    private const WRITABLE_CHAPTER_STATUSES = ['outlined', 'skipped'];

    /** 可写状态集合的 SQL 片段（与 WRITABLE_CHAPTER_STATUSES 保持一致）。 */
    private static function writableChapterStatusSql(): string
    {
        return '"outlined","skipped"';
    }

    private static function isWritableChapterStatus(string $status): bool
    {
        return in_array($status, self::WRITABLE_CHAPTER_STATUSES, true);
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 B0）：章节认领（→writing）可接受的状态集合。
     *
     * 自动路径恒为 outlined/skipped——质检 failed 章必须停机等人，禁止挂机吞掉；
     * 显式 chapter_id 人工路径额外允许 failed，支撑「保留正文重写」。
     * findNextAutomaticChapterId 不使用本方法，自动白名单不变。
     */
    private static function claimableChapterStatusSql(bool $allowFailed): string
    {
        return $allowFailed ? '"outlined","skipped","failed"' : self::writableChapterStatusSql();
    }

    /**
     * 自动写作进度线：当前已完成章节中的最大编号。
     *
     * 自动任务绝不能选择该进度线之前的 outlined/skipped 旧章，否则 prompt 会读取
     * 更晚章节形成的记忆与人物状态，产生“时间旅行”正文。人工显式传 chapter_id
     * 仍走 WRITABLE_CHAPTER_STATUSES，可在用户确认后处理历史缺口。
     */
    public static function automaticChapterFrontier(int $novelId): int
    {
        return (int)(DB::fetchColumn(
            'SELECT COALESCE(MAX(chapter_number), 0) FROM chapters '
            . 'WHERE novel_id=? AND status="completed"',
            [$novelId]
        ) ?: 0);
    }

    /**
     * Return the earliest Candidate that must be handled by a person before
     * automatic writing may advance. An approved override remains a blocker
     * until its Canon commit actually succeeds; approval alone is not Canon.
     *
     * @return array<string,mixed>|null
     */
    private static function automaticCandidateBlock(int $novelId): ?array
    {
        $row = DB::fetch(
            'SELECT cc.id AS candidate_id, cc.candidate_uid, cc.lifecycle, '
            . 'c.id AS chapter_id, c.chapter_number '
            . 'FROM chapter_candidates cc '
            . 'INNER JOIN chapters c ON c.id=cc.chapter_id AND c.novel_id=cc.novel_id '
            . 'WHERE cc.novel_id=? AND ('
            . 'cc.lifecycle IN ("quarantined","approved") OR '
            . '(cc.source="branch_merge" AND cc.lifecycle="draft" '
            . 'AND cc.gate_state="passed" AND EXISTS ('
            . 'SELECT 1 FROM approval_requests ar WHERE ar.candidate_id=cc.id '
            . 'AND ar.action_type="branch_merge" AND ar.state="open"))) '
            . 'ORDER BY c.chapter_number ASC, cc.id ASC LIMIT 1',
            [$novelId]
        );

        return is_array($row) ? $row : null;
    }

    /** 返回下一章可安全自动写作的章节 ID；显式人工选章不使用本方法。 */
    public static function findNextAutomaticChapterId(int $novelId, bool $forUpdate = false): ?int
    {
        // A quarantined/approved-but-uncommitted Candidate is an explicit
        // human-review barrier. Automatic retry must not consume it and must
        // never jump to a later chapter; only an explicit same-chapter action
        // may resolve the barrier.
        if (self::automaticCandidateBlock($novelId) !== null) {
            return null;
        }
        $frontier = self::automaticChapterFrontier($novelId);
        $sql = 'SELECT id, status FROM chapters '
            . 'WHERE novel_id=? AND chapter_number>? '
            . 'ORDER BY chapter_number ASC LIMIT 1';
        if ($forUpdate) $sql .= ' FOR UPDATE';

        $row = DB::fetch($sql, [$novelId, $frontier]);
        if (!$row) {
            return null;
        }
        // failed/writing/pending 卡在进度线后第一格时不得跳去更后面的 outlined，
        // 否则会留下坏章空洞继续往下写。
        if (!self::isWritableChapterStatus((string)($row['status'] ?? ''))) {
            return null;
        }
        return (int)$row['id'];
    }

    /**
     * 自动写作无法认领下一章时的用户可读原因。
     * 进度线后第一格若是质检 failed，必须停机等人，禁止跳章续写。
     */
    public static function automaticWriteBlockMessage(int $novelId): string
    {
        $candidateBlock = self::automaticCandidateBlock($novelId);
        if ($candidateBlock !== null) {
            $num = (int)($candidateBlock['chapter_number'] ?? 0);
            $lifecycle = (string)($candidateBlock['lifecycle'] ?? 'quarantined');
            if ($lifecycle === 'approved') {
                return "第{$num}章候选稿已批准但尚未提交正史，自动写作已停止；请先完成本章处理。";
            }
            return "第{$num}章有隔离候选稿等待处理，自动写作已停止；请预览、重试、拒绝或审批该候选稿。";
        }
        $frontier = self::automaticChapterFrontier($novelId);
        $row = DB::fetch(
            'SELECT chapter_number, status FROM chapters '
            . 'WHERE novel_id=? AND chapter_number>? '
            . 'ORDER BY chapter_number ASC LIMIT 1',
            [$novelId, $frontier]
        );
        if (!$row) {
            $manualBackfill = self::countManualBackfillChapters($novelId);
            if ($manualBackfill > 0) {
                return "没有可安全自动写作的章节；{$manualBackfill}章历史缺口请人工显式选择。";
            }
            return '没有待写章节，请先生成大纲，或确认全书是否已经写完。';
        }
        $num = (int)($row['chapter_number'] ?? 0);
        $status = (string)($row['status'] ?? '');
        if ($status === 'failed') {
            return "第{$num}章质检未通过，自动写作已停止。请在章节列表对该章点击「写作」重写后再继续。";
        }
        if ($status === 'writing') {
            return "第{$num}章仍在写作中，请等待完成或先取消写作。";
        }
        if (!self::isWritableChapterStatus($status)) {
            return "第{$num}章当前状态为「{$status}」，无法自动续写。";
        }
        return '没有可写章节，或指定章节当前状态不可写作。';
    }

    /** 自动任务真正可认领的章节数（不含完成进度线之前、只能人工处理的历史缺口）。 */
    public static function countAutomaticWritableChapters(int $novelId): int
    {
        if (self::automaticCandidateBlock($novelId) !== null) {
            return 0;
        }
        $frontier = self::automaticChapterFrontier($novelId);
        $rows = DB::fetchAll(
            'SELECT status FROM chapters WHERE novel_id=? AND chapter_number>? ORDER BY chapter_number ASC',
            [$novelId, $frontier]
        ) ?: [];
        $n = 0;
        foreach ($rows as $row) {
            if (!self::isWritableChapterStatus((string)($row['status'] ?? ''))) {
                break;
            }
            $n++;
        }
        return $n;
    }

    /** 已落后于完成进度线、需人工显式处理的可写历史章节数。 */
    public static function countManualBackfillChapters(int $novelId): int
    {
        $frontier = self::automaticChapterFrontier($novelId);
        if ($frontier <= 0) return 0;
        return DB::count(
            'chapters',
            'novel_id=? AND status IN ("outlined","skipped") AND chapter_number<=?',
            [$novelId, $frontier]
        );
    }

    /**
     * 将旧版“正文已保存但质检未过”的 failed 章节转为已完成警告章。
     *
     * 只处理同时具备正文和 hard_fail_reason 标记的行；真正的任务失败、
     * 空正文 failed 仍保持阻断，避免静默跳过未写章节。
     */
    public static function promoteQualityWarningChapters(int $novelId): int
    {
        if ($novelId <= 0) {
            throw new InvalidArgumentException('Invalid novel for quality-warning promotion.');
        }

        $pdo = DB::getPdo();
        $projectionFence = NovelProjectionFence::acquire($novelId, 30);
        $promoted = 0;
        $skipped = [
            'canonical' => 0,
            'invalid_state' => 0,
            'invalid_provenance' => 0,
            'revision_mismatch' => 0,
            'cas_conflict' => 0,
        ];

        try {
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                throw new RuntimeException('Unable to start quality-warning promotion transaction.');
            }

            $rows = DB::fetchAll(
                'SELECT id, content, canonical_state, content_revision_hash, '
                . 'canonicalized_at, canonical_candidate_uid, write_policy_snapshot_json '
                . 'FROM chapters WHERE novel_id=? AND status="failed" '
                . 'AND content IS NOT NULL AND TRIM(content)<>"" '
                . 'AND gate_results LIKE ? FOR UPDATE',
                [$novelId, '%"hard_fail_reason"%']
            ) ?: [];

            foreach ($rows as $row) {
                $chapterId = (int)($row['id'] ?? 0);
                $content = $row['content'] ?? null;
                $canonicalState = $row['canonical_state'] ?? null;
                $revisionHash = $row['content_revision_hash'] ?? null;
                $hasCleanLegacyProvenance =
                    ($row['canonicalized_at'] ?? null) === null
                    && ($row['canonical_candidate_uid'] ?? null) === null
                    && ($row['write_policy_snapshot_json'] ?? null) === null;

                if ($canonicalState === 'canonical') {
                    $skipped['canonical']++;
                    continue;
                }
                if (!in_array($canonicalState, ['none', 'legacy'], true)) {
                    $skipped['invalid_state']++;
                    continue;
                }
                if ($chapterId <= 0 || !is_string($content) || trim($content) === ''
                    || !$hasCleanLegacyProvenance) {
                    $skipped['invalid_provenance']++;
                    continue;
                }

                $contentHash = hash('sha256', $content);
                if ($canonicalState === 'none') {
                    if ($revisionHash !== null) {
                        $skipped['invalid_provenance']++;
                        continue;
                    }
                    $affected = DB::execute(
                        'UPDATE chapters '
                        . 'SET status="completed", claim_token="", canonical_state="legacy", '
                        . 'content_revision_hash=?, canonicalized_at=NULL, '
                        . 'canonical_candidate_uid=NULL, write_policy_snapshot_json=NULL '
                        . 'WHERE id=? AND novel_id=? AND status="failed" '
                        . 'AND canonical_state="none" AND content_revision_hash IS NULL '
                        . 'AND canonicalized_at IS NULL AND canonical_candidate_uid IS NULL '
                        . 'AND write_policy_snapshot_json IS NULL '
                        . 'AND OCTET_LENGTH(content)=? AND SHA2(content,256)=? '
                        . 'AND BINARY content=BINARY ? AND gate_results LIKE ?',
                        [
                            $contentHash,
                            $chapterId,
                            $novelId,
                            strlen($content),
                            $contentHash,
                            $content,
                            '%"hard_fail_reason"%',
                        ]
                    );
                } else {
                    if (!is_string($revisionHash)
                        || preg_match('/^[a-f0-9]{64}$/D', $revisionHash) !== 1
                        || !hash_equals($contentHash, $revisionHash)) {
                        $skipped['revision_mismatch']++;
                        continue;
                    }
                    $affected = DB::execute(
                        'UPDATE chapters SET status="completed", claim_token="" '
                        . 'WHERE id=? AND novel_id=? AND status="failed" '
                        . 'AND canonical_state="legacy" AND content_revision_hash=? '
                        . 'AND canonicalized_at IS NULL AND canonical_candidate_uid IS NULL '
                        . 'AND write_policy_snapshot_json IS NULL '
                        . 'AND OCTET_LENGTH(content)=? AND SHA2(content,256)=? '
                        . 'AND BINARY content=BINARY ? AND gate_results LIKE ?',
                        [
                            $chapterId,
                            $novelId,
                            $revisionHash,
                            strlen($content),
                            $contentHash,
                            $content,
                            '%"hard_fail_reason"%',
                        ]
                    );
                }

                if ($affected === 1) {
                    // A historical failed row may already own a terminal legacy
                    // job that was marked done merely because failed chapters
                    // were previously ineligible. If no projection marker was
                    // ever published, retire that terminal identity first so
                    // the idempotent enqueue below creates real recovery work.
                    $legacyJobUid = PostprocessJobRepository::jobUid(
                        $chapterId,
                        $contentHash,
                        'full',
                        null
                    );
                    $terminalJob = DB::fetch(
                        'SELECT id, state FROM postprocess_jobs
                         WHERE job_uid=? AND state IN ("done","failed")
                         LIMIT 1 FOR UPDATE',
                        [$legacyJobUid]
                    );
                    if (is_array($terminalJob)) {
                        $projectionCompleted = DB::fetch(
                            'SELECT id FROM chapter_projection_runs
                             WHERE novel_id=? AND chapter_id=?
                               AND revision_hash=? AND stage="full" LIMIT 1',
                            [$novelId, $chapterId, $contentHash]
                        );
                        if (!$projectionCompleted) {
                            DB::delete(
                                'postprocess_jobs',
                                'id=? AND state IN ("done","failed")',
                                [(int)$terminalJob['id']]
                            );
                        }
                    }

                    // The legacy job has no model Candidate or frozen policy
                    // and can never rewrite Canon. Persist it in this same
                    // transaction; workers execute AI only after commit.
                    $postprocessJobId = PostprocessJobRepository::enqueue(
                        $novelId,
                        $chapterId,
                        $contentHash,
                        'full',
                        3,
                        null,
                        null,
                        false
                    );
                    if ($postprocessJobId <= 0) {
                        throw new WriteEnginePersistenceException(
                            'Quality-warning promotion could not persist legacy post-processing work.'
                        );
                    }
                    $promoted++;
                } else {
                    $skipped['cas_conflict']++;
                }
            }

            if ($promoted > 0) {
                updateNovelStats($novelId);
                addLog(
                    $novelId,
                    'info',
                    "已将 {$promoted} 个历史质检失败章转为质量警告，自动写作将继续向后推进"
                );
            }
            if (array_sum($skipped) > 0) {
                addLog(
                    $novelId,
                    'warn',
                    'Quality-warning promotion skipped unauthenticated chapters: '
                    . json_encode($skipped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            if (!$pdo->commit()) {
                throw new RuntimeException('Quality-warning promotion commit failed.');
            }
            return $promoted;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            NovelProjectionFence::release($projectionFence);
        }
    }

    /**
     * Durable counterpart to pp_pending. Only a job bound to the chapter's
     * current authenticated revision/Canon generation may block the next
     * chapter; historical A -> B -> A jobs must not do so by hash alone.
     */
    private static function hasDurablePostProcessPending(int $novelId, int $chNum): bool
    {
        $pending = DB::fetch(
            'SELECT pj.id, pj.state, c.chapter_number
             FROM chapters c
             INNER JOIN postprocess_jobs pj
               ON pj.novel_id=c.novel_id AND pj.chapter_id=c.id
             WHERE c.novel_id=? AND c.chapter_number<=? AND c.status="completed"
               AND c.content IS NOT NULL AND c.content<>""
               AND c.canonical_state IN ("legacy","canonical")
               AND pj.state IN ("queued","retry","running","failed")
               AND pj.revision_hash=c.content_revision_hash
               AND SHA2(c.content,256)=pj.revision_hash
               AND pj.accepted_candidate_uid <=> c.canonical_candidate_uid
               AND (c.canonical_state="canonical"
                    OR (c.canonical_state="legacy"
                        AND c.canonicalized_at IS NULL
                        AND c.canonical_candidate_uid IS NULL
                        AND c.write_policy_snapshot_json IS NULL
                        AND pj.accepted_candidate_uid IS NULL
                        AND pj.rewrite_allowed=0))
             ORDER BY (pj.state="failed") DESC, c.chapter_number ASC
             LIMIT 1',
            [$novelId, $chNum]
        );
        if ($pending && $pending['state'] === 'failed') {
            throw new WriteEnginePostProcessPendingException(
                '第' . (int)$pending['chapter_number'] . '章记忆与约束重建失败，请打开该章节，点击“重试摘要与记忆重建”后再继续写作'
            );
        }
        return (bool)$pending;
    }

    public static function waitForPreviousPostProcess(
        int $novelId,
        int $chapterNumber,
        ?int $waitingChapterId = null,
        ?string $waitingClaimToken = null
    ): void
    {
        if ($chapterNumber <= 1) {
            return;
        }
        self::waitForPostProcess(
            $novelId,
            $chapterNumber - 1,
            null,
            $waitingChapterId,
            $waitingClaimToken
        );
    }

    /**
     * 显式传入 chapter_id 写作时，章节可能是 completed/writing 等不可写状态。
     * 此守护在进入写作事务前快速失败，避免覆盖已完成章节、造成统计漂移。
     *
     * 审计修复（2026-08-17 写作逻辑 B0）：$allowFailed 仅由显式 chapter_id 路径
     * 置 true——质检 failed 章允许人工「保留正文重写」；自动选章契约不变仍拒绝。
     */
    private static function assertChapterWritable(array $chapter, bool $allowFailed = false): void
    {
        $status = (string)($chapter['status'] ?? '');
        $writable = self::isWritableChapterStatus($status) || ($allowFailed && $status === 'failed');
        if (!$writable) {
            $chapterNumber = (int)($chapter['chapter_number'] ?? 0);
            throw new RuntimeException("第{$chapterNumber}章当前状态不可写作：{$status}");
        }
    }

    /**
     * 审计修复 P0（2026-08-04）：生成章节认领令牌。
     *
     * 竞态背景：任务 A 流式结束进入 saveChapter 后用户取消并立刻重启，
     * 新任务 B 把同一章重新置回 writing；A 的落盘 CAS（WHERE status="writing"）
     * 恰好命中 B 刚预占的行 → A 把已取消的半成品提交、B 的整章正文丢失。
     * SSE 直连（无 writing_tasks 行，_task_id 恒 null）与 daemon 路径不受
     * H4 的 cancel_requested 校验保护。认领时写入随机 token、落盘 CAS 附加
     * claim_token 匹配后，A 的 UPDATE 命中不了 B 的行，三条路径统一闭环。
     */
    public static function newClaimToken(): string
    {
        return HarnessRuntime::randomHex(16);
    }

    /** 任务级冻结的五关通过线（qualityGatePassScore 的任务内口径）。 */
    private static ?float $frozenGateMinScore = null;

    /**
     * 冻结本任务五关判定通过线（审计修复 P2，2026-09-27）。
     *
     * 原实现 qualityGatePassScore() 在每次 Gate 判定时实时读
     * ws_quality_min_score，任务运行（写作数分钟~数小时）期间管理员改配置
     * 会使同章的生成承诺与五关判定使用不同标准。三个入口（worker/SSE/
     * daemon）在任务启动时调用本方法把通过线冻结到进程内；五关经由
     * qualityGatePassScore() 优先消费本值。
     *
     * daemon 是长驻进程：每个任务开始时必须重新冻结，任务结束时调用
     * freezeQualityGateMinScore(null) 清除，防止跨任务泄漏。
     */
    public static function freezeQualityGateMinScore(?float $score): void
    {
        if ($score === null) {
            self::$frozenGateMinScore = null;
            return;
        }
        // 与 qualityGatePassScore() 相同的夹紧口径（50-90）
        self::$frozenGateMinScore = max(50.0, min(90.0, $score));
    }

    /** 当前任务冻结的五关通过线；未冻结返回 null。 */
    public static function frozenQualityGateMinScore(): ?float
    {
        return self::$frozenGateMinScore;
    }

    /**
     * 按任务启动口径解析五关通过线（worker/SSE/daemon 冻结时的取值逻辑，
     * 与 qualityGatePassScore() 的实时回退路径保持完全一致）。
     */
    public static function resolveQualityGateMinScore(): float
    {
        $raw = 70.0;
        try {
            if (function_exists('getSystemSetting')) {
                $raw = (float)getSystemSetting('ws_quality_min_score', 6.0, 'float') * 10;
            }
        } catch (Throwable $e) {
            $raw = 70.0;
        }
        return max(50.0, min(90.0, $raw));
    }

    /**
     * saveChapter 抛错后的"事务是否其实已提交"竞态探测（强校验版）。
     *
     * 审计修复 P2（2026-09-27）：worker/SSE/daemon 三入口原各自复制本函数，
     * 认证强度分歧——worker 与 SSE 版仅凭 status=completed + 内容 SHA-256
     * 即认领（同章新任务若生成字节级相同内容可被误认领），daemon 版已有
     * canonical_state/claim_token/revision_hash 守卫。收敛为唯一实现并统一
     * 到 daemon 的强度：
     *   1) canonical_state 必须为 canonical（真实走完正典提交链）；
     *   2) claim_token 必须已清空（无另一任务正在处理该章）；
     *   3) 存储的 content_revision_hash 必须与内容自哈希一致（排除账本与
     *      canon 脱节的脏状态）；
     *   4) 清洗顺序与 saveChapter 完全一致（stripMetaLeaks → stripSegmentMarkers）。
     * daemon 路径的 daemonRecoverCommittedCanon 保留其 claim-token 专属流程，
     * 但章节快照语义与本实现保持同强度。
     *
     * @param array $chapter 失败前认领的章节行（含 id）
     * @return array|null 已提交的章节行快照；未提交或校验不通过返回 null
     */
    public static function recoverCommittedChapterSnapshot(
        int $novelId,
        array $chapter,
        string $generatedContent
    ): ?array {
        $chapterId = (int)($chapter['id'] ?? 0);
        if ($chapterId <= 0 || $generatedContent === '') {
            return null;
        }
        try {
            $stored = DB::fetch(
                'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                [$chapterId, $novelId]
            );
        } catch (Throwable $e) {
            error_log("recoverCommittedChapterSnapshot lookup failed: chapter={$chapterId}; " . $e->getMessage());
            return null;
        }
        if (!is_array($stored)
            || (string)($stored['status'] ?? '') !== 'completed'
            || (string)($stored['canonical_state'] ?? '') !== 'canonical'
            || (string)($stored['claim_token'] ?? '') !== '') {
            return null;
        }
        $storedContent = $stored['content'] ?? null;
        $storedRevisionHash = $stored['content_revision_hash'] ?? null;
        if (!is_string($storedContent) || !is_string($storedRevisionHash)) {
            return null;
        }
        // Keep this normalization in exactly the same order as saveChapter().
        $expected = stripMetaLeaks(stripSegmentMarkers($generatedContent));
        if ($expected === ''
            || !hash_equals($expected, $storedContent)
            || !hash_equals($storedRevisionHash, hash('sha256', $storedContent))) {
            return null;
        }
        return $stored;
    }

    /**
     * Release exactly this initial-write claim back to its authenticated base
     * status. A token without its frozen base snapshot is not authority: fail
     * closed and let the shared stale-claim reconciler recover it later.
     */
    public static function releaseChapterClaim(int $chapterId, array $chapter = []): bool
    {
        if ($chapterId <= 0) return false;
        $token = (string)($chapter['claim_token'] ?? '');
        $rawSnapshot = $chapter['_base_snapshot'] ?? null;
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || !is_array($rawSnapshot)) {
            error_log("releaseChapterClaim refused incomplete claim authority: chapter={$chapterId}");
            return false;
        }

        try {
            $snapshot = self::validateChapterBaseSnapshot($rawSnapshot, $token);
        } catch (Throwable $e) {
            error_log(
                "releaseChapterClaim refused invalid base snapshot: chapter={$chapterId}; "
                . $e->getMessage()
            );
            return false;
        }

        $baseStatus = $snapshot['status'];
        if (!in_array($baseStatus, ['outlined', 'skipped', 'failed'], true)) {
            error_log(
                "releaseChapterClaim refused non-claimable base status: chapter={$chapterId}; status={$baseStatus}"
            );
            return false;
        }

        $contentHash = $snapshot['content_hash'];
        return DB::execute(
            'UPDATE chapters SET status=?, claim_token=""'
            . ' WHERE id=? AND status="writing" AND claim_token=?'
            . ' AND COALESCE(canonical_state,"none")=?'
            . ' AND content_revision_hash <=> ?'
            . ' AND ((? IS NULL AND (content IS NULL OR content=""))'
            . '      OR (? IS NOT NULL AND SHA2(content,256)=?))',
            [
                $baseStatus,
                $chapterId,
                $token,
                $snapshot['canonical_state'],
                $snapshot['content_revision_hash'],
                $contentHash,
                $contentHash,
                $contentHash,
            ]
        ) === 1;
    }

    /**
     * Phase 1: 解析待写章节（含僵死 writing 状态清理 + Agent决策）
     *
     * @param int      $novelId              小说ID
     * @param int|null $chapterId            指定章节ID（null=自动选章）
     * @param bool     $chapterAlreadyClaimed 调用方（SSE 直连）已在本事务外预占章节
     * @param string|null $expectedClaimToken 预占时写入的认领令牌。非 null 时复验行上
     *                                        令牌仍与本请求一致（审计 2026-08-16 S-2），
     *                                        防止取消→重启竞态窗口内旧任务采纳新任务令牌。
     * @return array{n: array, ch: array}
     * @throws RuntimeException
     */
    public static function resolveChapter(
        int $novelId,
        ?int $chapterId = null,
        bool $chapterAlreadyClaimed = false,
        ?string $expectedClaimToken = null,
        ?array $preclaimedBaseSnapshot = null
    ): array
    {
        $novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
        if (!$novel) throw new RuntimeException('小说不存在');

        // 审计功能（2026-08-16 P3-金手指设计模板）：结构化金手指设定并入 novel 数组，
        // 供黄金三章实体核验/点名（GoldenThreeChapters 结构化优先，无设定回退文本挖掘）；
        // $novel 随 resolveChapter 结果流向 buildPrompt → ChapterPromptBuilder。失败静默。
        try {
            require_once __DIR__ . '/GoldenFinger.php';
            $novel['golden_finger'] = (new GoldenFinger($novelId))->get();
        } catch (\Throwable $e) {
            // 读取失败不阻断写作
        }

        // PERF-C2：列裁剪避免 SELECT *；content 仍需用于重写/校验。
        // 审计 P0（2026-08-17）：补齐大纲元数据列（cool_point_type/hook_type 等），
        // 否则 ChapterPromptBuilder 读不到已入库的爽点/钩子/概要蓝图。
        $cc = self::chapterWriteSelectList();
        $ch = $chapterId
            ? DB::fetch("SELECT {$cc} FROM chapters WHERE id=? AND novel_id=?", [$chapterId, $novelId])
            : null;

        // 僵死 writing → 按持久 base snapshot 精确恢复；无权威快照则 failed
        // （指定章节ID时也需要清理该小说所有 writing 状态）
        // 修复：增加 updated_at 静态阈值守卫——只有超过 CFG_ZOMBIE_DB 秒
        // 没有任何更新的 writing 章节，才能视为真正的僵尸状态被重置；
        // 否则后台 worker 正在写的章节会被这里强行抢成 outlined，触发双写。
        // 心跳路径会持续刷新 chapters.updated_at（见 write_chapter_worker 的
        // sendHeartbeatWrite 与 WriteSseChannel::heartbeat），活跃任务的
        // updated_at 始终在阈值窗口内。
        // M-19 修复：僵尸清理用事务 + FOR UPDATE 防并发抢占，避免两个进程同时重置同一章节。
        // SSE 自己预占的 writing 是新鲜状态，绝不会被本恢复步骤误回收。
        $stuck = [];
        try {
            $stuck = self::recoverStaleWritingChapters($novelId);
        } catch (\Throwable $e) {
            error_log('resolveChapter zombie cleanup failed: ' . $e->getMessage());
        }
        if (!empty($stuck)) {
            // 如果指定章节刚被恢复，局部快照也必须采用 DB 实际恢复状态。
            // 硬编码 outlined 会再次擦掉 failed/skipped authority，并让后续
            // captureChapterBaseSnapshot 冻结一份虚假的 pre-claim 状态。
            if ($ch) {
                foreach ($stuck as $recoveredChapter) {
                    if ((int)$ch['id'] !== (int)($recoveredChapter['id'] ?? 0)) {
                        continue;
                    }
                    $ch['status'] = (string)($recoveredChapter['restored_status'] ?? 'failed');
                    $ch['claim_token'] = '';
                    break;
                }
            }
        }

        // 未指定章节时只走自动选章契约：完成进度线之前的历史缺口必须由用户显式指定。
        if (!$chapterId) {
            $autoChapterId = self::findNextAutomaticChapterId($novelId);
            if ($autoChapterId) {
                $ch = DB::fetch(
                    "SELECT {$cc} FROM chapters WHERE id=? AND novel_id=?",
                    [$autoChapterId, $novelId]
                );
            }
        }

        if (!$ch) {
            if (!$chapterId && self::countManualBackfillChapters($novelId) > 0) {
                throw new RuntimeException('没有可安全自动写作的章节；完成进度线之前的历史缺口请人工显式选择。');
            }
            throw new RuntimeException('没有待写章节，请先生成大纲。');
        }

        // Task 1：进入写作事务前先快速校验可写状态。显式 chapter_id 路径取章节时
        // 没有状态过滤，僵死清理后若仍是 completed/active-writing 等状态，必须拒绝，
        // 否则下方会把已完成章节强制改回 writing 并在写完后覆盖正文/重复计入统计。
        $baseChapterSnapshot = null;
        if ($chapterAlreadyClaimed) {
            if ($expectedClaimToken === null || $preclaimedBaseSnapshot === null) {
                throw new RuntimeException(
                    'A pre-claimed chapter requires its frozen base snapshot and claim token.'
                );
            }
            $baseChapterSnapshot = self::validateChapterBaseSnapshot(
                $preclaimedBaseSnapshot,
                $expectedClaimToken
            );
            $currentContent = (string)($ch['content'] ?? '');
            $currentContentHash = $currentContent === '' ? null : hash('sha256', $currentContent);
            $currentRevisionHash = $ch['content_revision_hash'] ?? null;
            $currentRevisionHash = is_string($currentRevisionHash) && $currentRevisionHash !== ''
                ? strtolower($currentRevisionHash)
                : null;
            if (
                (string)($ch['canonical_state'] ?? 'none') !== $baseChapterSnapshot['canonical_state']
                || $currentRevisionHash !== $baseChapterSnapshot['content_revision_hash']
                || $currentContentHash !== $baseChapterSnapshot['content_hash']
            ) {
                throw new RuntimeException(
                    'The pre-claimed chapter changed after its base snapshot was captured.'
                );
            }
            if (!$chapterId || (int)$ch['id'] !== $chapterId || (string)$ch['status'] !== 'writing') {
                throw new RuntimeException('预占章节状态已变化，写作已停止。');
            }
            // 审计修复（2026-08-16 S-2）：预占复验必须绑定认领令牌。仅验 status 时，
            // 若取消→重启的新任务恰在本请求"预占 commit → 此处 SELECT"窗口内以新令牌
            // 重新认领同一章，旧请求会采纳新令牌并通过后续 saveChapter CAS，覆盖新任务正文。
            if ($expectedClaimToken !== null
                && !hash_equals($expectedClaimToken, (string)($ch['claim_token'] ?? ''))) {
                throw new RuntimeException('预占已被其他写作任务接管，写作已停止。');
            }
        } else {
            // 审计修复（2026-08-17 写作逻辑 B0）：显式 chapter_id 路径允许质检 failed
            // 章重写（保留正文作参考）；自动选章仍拒绝 failed，维持停机等人契约。
            self::assertChapterWritable($ch, $chapterId !== null && $chapterId > 0);
        }

        // P0-3 优化：Agent决策移到僵死清理之后、章节置 writing 之前
        // 原实现将 runPreWriteAgents 放在 resolveChapter 最开头，此时僵死清理尚未执行，
        // getCurrentChapterNumber 返回的"下一个待写章节"可能与实际写作章节不一致
        // （僵死章节被重置为 outlined 后会改变选章结果）。
        // 现在基于已确定的 $ch['chapter_number'] 调用，确保决策上下文与实际写作章节对齐。
        // 写前决策在下方前章投影屏障通过后执行。

        // 事务包裹：取消标志清零 + 章节置 writing + 小说置 writing 必须原子执行。
        // 自有事务必须先进入 novel projection fence，再严格按 chapter -> novel
        // 取行锁。取消、daemon/SSE 预占和 Canon 提交采用同一顺序，避免
        // novel <-> chapter 反序死锁。
        $flagFile = self::cancelFlagPath($novelId);

        $pdo = DB::connect();
        $ownTx = !$pdo->inTransaction();
        $claimProjectionFence = null;
        try {
            if ($ownTx) {
                $claimProjectionFence = NovelProjectionFence::acquire($novelId, 30);
                if (!$pdo->beginTransaction()) {
                    throw new RuntimeException('Could not start chapter claim transaction.');
                }
            }

            $lockedClaimChapter = DB::fetch(
                'SELECT ' . self::chapterWriteSelectList()
                . ' FROM chapters WHERE id=? AND novel_id=? FOR UPDATE',
                [(int)$ch['id'], $novelId]
            );
            if (!is_array($lockedClaimChapter)) {
                throw new RuntimeException('章节在认领前已不存在。');
            }
            $ch = $lockedClaimChapter;

            // Task 1：原子守护——把"可写状态"放进 WHERE 谓词，与并发请求/僵死清理后的
            // 状态变化构成 TOCTOU 防护。若 0 行受影响，说明章节已被其它进程抢占或不可写，
            // 立即中止（事务回滚），避免覆盖。
            if (!$chapterAlreadyClaimed) {
                self::assertChapterWritable($ch, $chapterId !== null && $chapterId > 0);
                // 审计 P0（2026-08-04）：认领时写入随机令牌，saveChapter 落盘 CAS
                // 按令牌匹配，杜绝已取消旧任务覆盖新任务预占行（取消→重启竞态）。
                // 审计修复（2026-08-17 写作逻辑 B0）：显式 chapter_id 认领允许 failed
                // → writing（保留正文重写）；自动路径白名单不变。
                $claimToken = self::newClaimToken();
                $baseChapterSnapshot = self::captureChapterBaseSnapshot($ch, $claimToken);
                $affectedChapter = DB::update(
                    'chapters',
                    ['status' => 'writing', 'claim_token' => $claimToken],
                    'id=? AND status IN (' . self::claimableChapterStatusSql($chapterId !== null && $chapterId > 0) . ')',
                    [$ch['id']]
                );
                if ($affectedChapter === 0) {
                    throw new RuntimeException('章节当前状态不可写作，写作已停止。');
                }
                $ch['claim_token'] = $claimToken;
            } else {
                // 预占必须仍由本请求持有；只读校验，绝不把其它状态强改回 writing。
                // 审计修复（2026-08-16 S-2）：二次校验同时取 claim_token，令牌不一致
                // 视为预占已被新任务接管；并将本请求的预占令牌回写 $ch，保证
                // saveChapter 的 CAS 用预占时写入的令牌，而非中途被覆写的"现值"。
                if ((string)$ch['status'] !== 'writing') {
                    throw new RuntimeException('预占章节已被释放，写作已停止。');
                }
                if ($expectedClaimToken !== null
                    && !hash_equals($expectedClaimToken, (string)($ch['claim_token'] ?? ''))) {
                    throw new RuntimeException('预占已被其他写作任务接管，写作已停止。');
                }
                $currentContent = (string)($ch['content'] ?? '');
                $currentContentHash = $currentContent === '' ? null : hash('sha256', $currentContent);
                $currentRevisionHash = $ch['content_revision_hash'] ?? null;
                $currentRevisionHash = is_string($currentRevisionHash) && $currentRevisionHash !== ''
                    ? strtolower($currentRevisionHash)
                    : null;
                if ((string)($ch['canonical_state'] ?? 'none') !== $baseChapterSnapshot['canonical_state']
                    || $currentRevisionHash !== $baseChapterSnapshot['content_revision_hash']
                    || $currentContentHash !== $baseChapterSnapshot['content_hash']) {
                    throw new RuntimeException(
                        'The pre-claimed chapter changed before its locked claim verification.'
                    );
                }
                if ($expectedClaimToken !== null) {
                    $ch['claim_token'] = $expectedClaimToken;
                }
            }

            $lockedClaimNovel = DB::fetch(
                'SELECT id FROM novels WHERE id=? FOR UPDATE',
                [$novelId]
            );
            if (!is_array($lockedClaimNovel)) {
                throw new RuntimeException('小说在章节认领前已不存在。');
            }
            DB::update(
                'novels',
                ['cancel_flag' => 0, 'status' => 'writing'],
                'id=?',
                [$novelId]
            );
            if ($ownTx) {
                if (!$pdo->commit()) {
                    throw new RuntimeException('Could not commit chapter claim transaction.');
                }
            }
            // 事务成功后再清除取消标志文件，确保状态一致性
            if (file_exists($flagFile)) {
                @unlink($flagFile);
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($claimProjectionFence !== null) {
                NovelProjectionFence::release($claimProjectionFence);
            }
        }

        if ($baseChapterSnapshot === null) {
            throw new RuntimeException('Chapter base snapshot was not captured.');
        }
        // Error/cancellation paths after this point must transport the exact
        // pre-claim state back into releaseChapterClaim; do this before waiting
        // on prior post-processing because that wait itself may throw.
        $ch['_base_snapshot'] = $baseChapterSnapshot;

        // v2: 记忆竞态修复——若上一章后处理（摘要/记忆/指令）仍在后台进行，
        // 先等它完成再构建本章 prompt，否则本章会读到滞后的记忆与漏掉的 Agent 指令。
        try {
            self::waitForPostProcess(
                $novelId,
                (int)$ch['chapter_number'] - 1,
                null,
                (int)$ch['id'],
                (string)($ch['claim_token'] ?? '')
            );
            // 审计修复（2026-08-17 写作逻辑 D1）：上一章存在 pp_degraded 降级标记且
            // 记忆未最终化时同样不放行——stale 清理路径只补跑不豁免，下一章继续等。
            self::assertNoDegradedPostProcess($novelId, (int)$ch['chapter_number'] - 1);
            self::runPreWriteAgents($novelId, (int)$ch['chapter_number']);
        } catch (\Throwable $e) {
            // waitForPostProcess can now fail immediately when the durable task
            // lease is lost. The caller cannot see this local $ch until return,
            // so every exceptional exit must release only this exact claim.
            self::releaseChapterClaim((int)$ch['id'], $ch);
            // Do not mutate novel.status here. A stale waiter has no authority
            // to pause a replacement worker; lease-fenced terminal handlers
            // reconcile the novel after they prove no newer claim owns it.
            throw $e;
        }

        return ['n' => $novel, 'ch' => $ch];
    }

    /**
     * Phase 2: 初始化记忆引擎 + 语义召回
     * @param int $novelId 小说ID
     * @param array $chapter 章节数据
     * @param ?AIClient $aiClient AI客户端（用于检测1M上下文支持）
     * @return array{engine: MemoryEngine, memoryCtx: ?array}
     */
    public static function initMemory(int $novelId, array $chapter, ?AIClient $aiClient = null): array
    {
        require_once __DIR__ . '/memory/MemoryEngine.php';
        $engine = new MemoryEngine($novelId);

        try { $engine->ensureEmbeddings(30); }
        catch (Throwable $e) {
            if (is_a($e, 'RuntimeControlException')
                || is_a($e, 'WorkflowCheckpointException')) {
                throw $e;
            }
            addLog($novelId, 'warn', 'ensureEmbeddings 失败：' . $e->getMessage());
        }

        $queryText = trim(($chapter['title'] ?? '') . '：' . ($chapter['outline'] ?? ''));
        // 审计修复（2026-08-09 R9-6）：默认与 UI / config_constants 对齐为 5
        $semanticTopK = max(1, min(40, (int)getSystemSetting('ws_embedding_top_k', 5, 'int')));

        $contextMode = getSystemSetting('ws_context_mode', 'auto', 'string');
        $is1MSupported = $aiClient ? $aiClient->is1MContext() : false;
        $useFullContext = false;
        $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);

        if ($contextMode === 'full' && $is1MSupported) {
            $useFullContext = true;
        } elseif ($contextMode === 'auto' && $is1MSupported) {
            $fullContextThreshold = (int)getSystemSetting('ws_full_context_threshold', 10, 'int');
            if ($chNum >= $fullContextThreshold) {
                $useFullContext = true;
            }
        }

        try {
            if ($useFullContext) {
                // 1M 上下文模式仍保留硬预算：预留输出、系统指令和厂商 tokenizer 差异空间。
                $contextLimit = $aiClient?->getContextLimit() ?? 1000000;
                $reservedTokens = max(50000, ($aiClient?->getMaxTokens() ?? 8192) * 2);
                $fullContextBudget = max(100000, min(900000, $contextLimit - $reservedTokens));
                // 审计修复（2026-08-09 R10-1）：novel 记忆预算收紧时按比例缩放 full 预算，
                // 使 compress_context 在 auto/full+1M 路径也生效（相对默认 20000）。
                $novelMemBudget = max(2000, (int)ConfigCenter::getNovel($novelId, 'ws_memory_token_budget', 20000, 'int'));
                // R11-1：缩放地板降至 0.1，使 2000/20000 收紧能真正咬合；结果下限仍 50k
                $budgetScale = max(0.1, min(1.0, $novelMemBudget / 20000.0));
                $fullContextBudget = max(50000, (int)round($fullContextBudget * $budgetScale));
                $maxHistoryChapters = max(0, (int)getSystemSetting('ws_1m_history_chapters', 0, 'int'));
                $memoryCtx = $engine->getFullPromptContext(
                    (int)$chapter['chapter_number'],
                    $maxHistoryChapters,
                    $queryText,
                    $fullContextBudget,
                    $semanticTopK
                );
                addLog($novelId, 'info', sprintf(
                    '使用1M完整上下文模式：大纲%d章 / 正文%d章 / 伏笔%d条 / 角色%d个',
                    $memoryCtx['full_context_stats']['total_outlines'] ?? 0,
                    $memoryCtx['full_context_stats']['full_content_chapters'] ?? 0,
                    $memoryCtx['full_context_stats']['foreshadowing_count'] ?? 0,
                    $memoryCtx['full_context_stats']['character_count'] ?? 0
                ));
            } else {
                // 标准压缩模式
                // v41: token 预算配置化（缓存让大上下文便宜，默认从 6000 提到 20000）
                // 审计修复（2026-08-09 R9-1）：OptimizationAgent compress_context 写 novel 级，
                // 读侧必须 ConfigCenter::getNovel，否则调整永不生效。
                $memBudget = max(2000, (int)ConfigCenter::getNovel($novelId, 'ws_memory_token_budget', 20000, 'int'));
                $memoryCtx = $engine->getPromptContext((int)$chapter['chapter_number'], $queryText, $memBudget, 20, $semanticTopK);
            }
        } catch (Throwable $e) {
            if (is_a($e, 'RuntimeControlException')
                || is_a($e, 'WorkflowCheckpointException')) {
                throw $e;
            }
            addLog($novelId, 'error', 'MemoryEngine 上下文构建失败：' . $e->getMessage());
            $memoryCtx = null;
        }

        $hitCount = is_array($memoryCtx['semantic_hits'] ?? null) ? count($memoryCtx['semantic_hits']) : 0;
        if ($hitCount > 0) {
            addLog($novelId, 'info', "语义召回生效：命中{$hitCount}条相关线索");
        } elseif (isset($memoryCtx['debug']['semantic_error'])) {
            addLog($novelId, 'warn', '语义召回失败：' . $memoryCtx['debug']['semantic_error']);
        }

        return ['engine' => $engine, 'memoryCtx' => $memoryCtx];
    }

    /**
     * Phase 3: 组装 AI 写作 prompt
     * @return array AI messages 数组
     */
    public static function buildPrompt(
        array $novel,
        array $chapter,
        ?array $memoryCtx,
        ?string $artifactTaskId = null,
        ?WritePolicySnapshot $artifactPolicySnapshot = null,
        ?array $artifactTaskLease = null,
        ?HarnessFeatureSnapshot $featureSnapshot = null,
        ?array &$harnessEvidence = null,
        ?array $toolDirective = null
    ): array
    {
        $previousSummary = getPreviousSummary($novel['id'], (int)$chapter['chapter_number']);
        // L4_previous_tail 总是字符串（拿不到时为 ''，非 null），用 ?? 会短路掉带回溯的
        // getPreviousTail() 兜底。空串时显式回退，确保上一章未 completed（乱序/重写/章节空洞）
        // 时仍能取到最近已完成章的结尾，保证上下章衔接。
        $previousTail    = !empty($memoryCtx['L4_previous_tail'])
            ? $memoryCtx['L4_previous_tail']
            : getPreviousTail($novel['id'], (int)$chapter['chapter_number']);

        // === v1.7 PRO Blueprint 代理：细化大纲为写作蓝图 ===
        if (getSystemSetting('ws_blueprint_enabled', false, 'bool')) {
            try {
                $enrichedOutline = self::runBlueprint($novel, $chapter, $previousTail, $memoryCtx);
                if ($enrichedOutline !== null && mb_strlen($enrichedOutline) > mb_strlen($chapter['outline'] ?? '')) {
                    $chapter['outline'] = $enrichedOutline;
                    addLog((int)$novel['id'], 'info', 'Blueprint 代理：大纲已细化为写作蓝图（' . mb_strlen($enrichedOutline) . '字）');
                }
            } catch (\Throwable $e) {
                addLog((int)$novel['id'], 'warn', 'Blueprint 代理跳过：' . $e->getMessage());
            }
        }

        // v1.4: 使用 ChapterPromptBuilder 替代 497 行函数，段落独立可测试
        require_once __DIR__ . '/ChapterPromptBuilder.php';
        $builder = new ChapterPromptBuilder($novel, $chapter, $previousSummary, $previousTail, $memoryCtx);

        $resolverResult = null;
        try {
            $resolverResult = self::runForeshadowingResolver($novel, $chapter);
        } catch (\Throwable $e) {
            addLog((int)$novel['id'], 'warn', 'ForeshadowingResolver 失败：' . $e->getMessage());
        }
        if ($resolverResult) {
            $builder->setResolverResult($resolverResult);
        }

        // 审计优化（2026-07-20）：降级提示放 user tail 强注意区，不再拼进 outline 被稀释
        $degradedHint = self::consumePostProcessDegradedFlag((int)$novel['id'], (int)$chapter['chapter_number']);
        if ($degradedHint !== '') {
            $builder->setDegradedHint($degradedHint);
        }

        $legacyMessages = $builder->build();
        $messages = $legacyMessages;
        if ($featureSnapshot?->toolLoop === true) {
            if ($toolDirective === null) {
                throw new RuntimeException(
                    'Frozen Tool-loop Run produced no required Planner/Researcher/Reviewer directive.'
                );
            }
            require_once __DIR__ . '/harness/tools/ChapterToolLoop.php';
            ToolLoopPromptDirective::assertValid($toolDirective);
            if ((int)$toolDirective['chapter_id'] !== (int)($chapter['id'] ?? 0)) {
                throw new RuntimeException(
                    'Tool-loop directive belongs to a different Chapter.'
                );
            }
            $messages = ToolLoopPromptDirective::decorate($messages, $toolDirective);
        } elseif ($toolDirective !== null) {
            throw new RuntimeException(
                'Tool-loop directive cannot alter a Run whose frozen flag is disabled.'
            );
        }
        $promptPackage = self::compileHarnessPromptPackage(
            $artifactPolicySnapshot,
            $featureSnapshot,
            $novel,
            $chapter,
            $previousSummary,
            $previousTail,
            $memoryCtx,
            $resolverResult,
            $messages
        );
        $promptDiff = $promptPackage === null
            ? null
            : PromptShadowComparator::compare($legacyMessages, $promptPackage);
        if ($promptPackage !== null && $featureSnapshot?->contextV2 === true) {
            $messages = $promptPackage->messages;
        }
        $harnessEvidence = $promptPackage === null ? null : [
            'schema' => 'harness.prompt.evidence.v1',
            'package_manifest' => $promptPackage->manifest,
            'shadow_diff' => $promptDiff,
            'tool_directive_hash' => $toolDirective === null
                ? null
                : hash(
                    'sha256',
                    HarnessPersistenceValue::canonicalJson($toolDirective)
                ),
        ];
        require_once __DIR__ . '/agents/AgentDirectives.php';
        AgentDirectives::capturePrompt(
            array_merge($chapter, ['novel_id' => (int)$novel['id']]),
            $builder->getInjectedAgentDirectives(),
            $messages
        );
        self::captureLegacyPromptArtifacts(
            $artifactTaskId,
            $artifactPolicySnapshot,
            $artifactTaskLease,
            (int)($novel['id'] ?? 0),
            (int)($chapter['id'] ?? 0),
            $messages,
            $memoryCtx,
            $promptPackage,
            $promptDiff
        );
        return $messages;
    }

    /**
     * Best-effort Harness evidence capture for the durable worker only.
     * Legacy SSE/daemon callers omit the carrier arguments and return here.
     * Frozen off mode returns before the Artifact implementation is loaded.
     *
     * @param list<array<string,mixed>> $messages
     */
    private static function captureLegacyPromptArtifacts(
        ?string $taskId,
        ?WritePolicySnapshot $policySnapshot,
        ?array $taskLease,
        int $novelId,
        int $chapterId,
        array $messages,
        ?array $memoryContext,
        ?PromptPackage $promptPackage = null,
        ?array $promptDiff = null
    ): void {
        try {
            $scope = self::legacyArtifactCaptureScope(
                $taskId,
                $policySnapshot,
                $taskLease,
                $novelId,
                $chapterId
            );
            if ($scope === null) {
                return;
            }
            self::legacyArtifactCaptureService()->capturePromptBundle(
                $scope,
                $messages,
                $memoryContext
            );
            if ($promptPackage !== null) {
                self::legacyArtifactCaptureService()->capturePromptManifest(
                    $scope,
                    $promptPackage->manifest
                );
                if ($promptDiff !== null) {
                    self::legacyArtifactCaptureService()->capturePromptShadowDiff(
                        $scope,
                        $promptDiff
                    );
                }
            }
        } catch (Throwable $exception) {
            self::reportLegacyArtifactCaptureFailure(
                $taskId,
                'write_engine.prompt_bundle',
                $exception
            );
        }
    }

    /**
     * Compile a deterministic P4 manifest beside the legacy prompt. This path
     * never invokes a model. A frozen context_v2 authority Run may select the
     * compiled package; trace-only and legacy callers retain the exact messages.
     */
    private static function compileHarnessPromptPackage(
        ?WritePolicySnapshot $policySnapshot,
        ?HarnessFeatureSnapshot $featureSnapshot,
        array $novel,
        array $chapter,
        string $previousSummary,
        string $previousTail,
        ?array $memoryContext,
        ?array $resolverResult,
        array $legacyMessages
    ): ?PromptPackage {
        if ($policySnapshot === null
            || $policySnapshot->harnessMode() === HarnessControlPlane::MODE_OFF
            || ($featureSnapshot !== null
                && !$featureSnapshot->contextV2
                && !$featureSnapshot->trace)) {
            return null;
        }

        try {
            require_once __DIR__ . '/harness/prompt/bootstrap.php';
            require_once __DIR__ . '/harness/skills/bootstrap.php';
            $estimator = new ConservativeContextTokenEstimator();
            $novelId = max(1, (int)($novel['id'] ?? 1));
            $chapterId = max(1, (int)($chapter['id'] ?? 1));
            $chapterNumber = max(1, (int)($chapter['chapter_number'] ?? 1));
            $chapterType = (string)($chapter['chapter_type'] ?? $chapter['scene_type'] ?? 'general');
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:@\/-]*$/D', $chapterType) !== 1) {
                $chapterType = 'general';
            }
            $outlineText = implode("\n", array_filter([
                (string)($chapter['title'] ?? ''),
                (string)($chapter['outline'] ?? ''),
                (string)($chapter['hook'] ?? ''),
            ], static fn (string $value): bool => $value !== ''));
            $outlineTags = [];
            foreach (['hook_type', 'cool_point_type', 'pacing'] as $field) {
                $value = (string)($chapter[$field] ?? '');
                if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:@\/-]*$/D', $value) === 1) {
                    $outlineTags[] = strtolower($value);
                }
            }

            static $skillRegistry = null;
            if (!$skillRegistry instanceof NovelSkillRegistry) {
                $skillRegistry = NovelSkillRegistry::load(__DIR__ . '/skills');
            }
            $skillSelection = (new DeterministicSkillSelector($skillRegistry))->select(
                new SkillSelectionRequest(
                    strtolower($chapterType),
                    $outlineText,
                    $outlineTags,
                    $chapterNumber >= max(1, (int)($novel['target_chapters'] ?? PHP_INT_MAX)),
                    2200
                )
            );
            $skillInstructions = implode("\n\n", array_map(
                static fn (NovelSkillManifest $skill): string => $skill->instructions,
                $skillSelection->selected
            ));

            $builderPath = __DIR__ . '/ChapterPromptBuilder.php';
            $builderRevision = hash_file('sha256', $builderPath) ?: str_repeat('0', 64);
            $memoryContext ??= [];
            $canonical = static fn (mixed $value): string =>
                HarnessPersistenceValue::canonicalJson(['value' => $value]);
            $platformRuleSections = [];
            foreach (PromptPackage::normalizeMessages($legacyMessages) as $message) {
                if (in_array($message['role'], ['system', 'developer'], true)
                    && is_string($message['content'])) {
                    $platformRuleSections[] = $message['content'];
                }
            }
            if ($platformRuleSections === []) {
                throw new RuntimeException(
                    'Legacy Chapter Prompt has no system/developer rules for authority rendering.'
                );
            }
            $platformRulesContent = implode("\n\n", $platformRuleSections);
            $sources = [
                'platform_rules' => [
                    'content' => $platformRulesContent,
                    'source_id' => 'ChapterPromptBuilder',
                    'source_revision' => hash(
                        'sha256',
                        $builderRevision . "\0" . $platformRulesContent
                    ),
                ],
                'novel_profile' => [
                    'content' => $canonical(array_intersect_key($novel, array_flip([
                        'id', 'title', 'genre', 'style', 'tone', 'worldview',
                        'target_chapters', 'target_words',
                    ]))),
                    'source_id' => 'novel:' . (int)($novel['id'] ?? 0),
                    'source_revision' => hash('sha256', $canonical($novel['updated_at'] ?? $novel)),
                ],
                'chapter_outline' => [
                    'content' => $canonical(array_intersect_key($chapter, array_flip([
                        'id', 'chapter_number', 'title', 'outline', 'key_points',
                        'hook', 'pacing', 'suspense', 'hook_type', 'cool_point_type',
                    ]))),
                    'source_id' => 'chapter:' . (int)($chapter['id'] ?? 0),
                    'source_revision' => hash('sha256', $canonical($chapter['updated_at'] ?? $chapter)),
                ],
                'canon_memory' => [
                    'content' => $canonical($memoryContext),
                    'source_id' => 'novel:' . (int)($novel['id'] ?? 0),
                    'source_revision' => hash('sha256', $canonical($memoryContext)),
                ],
                'previous_chapter' => [
                    'content' => $canonical(['summary' => $previousSummary, 'tail' => $previousTail]),
                    'source_id' => 'before:' . $chapterNumber,
                    'source_revision' => hash('sha256', $previousSummary . "\0" . $previousTail),
                ],
                'foreshadow' => [
                    'content' => $canonical([
                        'pending' => $memoryContext['pending_foreshadowing'] ?? [],
                        'resolver' => $resolverResult ?? [],
                    ]),
                    'source_id' => 'chapter:' . (int)($chapter['id'] ?? 0),
                    'source_revision' => hash('sha256', $canonical([
                        $memoryContext['pending_foreshadowing'] ?? [],
                        $resolverResult ?? [],
                    ])),
                ],
                'character_state' => [
                    'content' => $canonical([
                        'states' => $memoryContext['character_states'] ?? [],
                        'returning' => $memoryContext['returning_characters'] ?? [],
                    ]),
                    'source_id' => 'novel:' . (int)($novel['id'] ?? 0),
                    'source_revision' => hash('sha256', $canonical([
                        $memoryContext['character_states'] ?? [],
                        $memoryContext['returning_characters'] ?? [],
                    ])),
                ],
                'agent_directive' => [
                    'content' => $canonical($resolverResult ?? []),
                    'source_id' => 'chapter:' . (int)($chapter['id'] ?? 0),
                    'source_revision' => hash('sha256', $canonical($resolverResult ?? [])),
                ],
                'author_style' => [
                    'content' => $canonical(array_intersect_key($novel, array_flip([
                        'style', 'tone', 'writing_style', 'narrative_perspective',
                    ]))),
                    'source_id' => 'novel:' . (int)($novel['id'] ?? 0),
                    'source_revision' => hash('sha256', $canonical([
                        $novel['style'] ?? null,
                        $novel['tone'] ?? null,
                        $novel['writing_style'] ?? null,
                    ])),
                ],
                'skill_context' => $skillInstructions === '' ? null : [
                    'content' => $skillInstructions,
                    'source_id' => implode(',', array_map(
                        static fn (NovelSkillManifest $skill): string => $skill->id,
                        $skillSelection->selected
                    )),
                    'source_revision' => hash('sha256', $canonical($skillSelection->coordinates())),
                ],
            ];
            $request = new ContextProviderRequest(
                max(1, (int)($novel['user_id'] ?? 1)),
                $novelId,
                $chapterId,
                $chapterNumber,
                $sources
            );
            $items = [];
            foreach ([
                new PlatformRuleProvider(),
                new NovelProfileProvider(),
                new ChapterOutlineProvider(),
                new CanonMemoryProvider(),
                new PreviousChapterProvider(),
                new ForeshadowProvider(),
                new CharacterStateProvider(),
                new AgentDirectiveProvider(),
                new AuthorStyleProvider(),
                new SkillContextProvider(),
            ] as $provider) {
                array_push($items, ...$provider->provide($request, $estimator));
            }
            $outputReserve = max(
                1024,
                (int)getSystemSetting('ws_max_tokens_chapter', 12000, 'int')
            );
            $contextAllowance = max(
                4000,
                (int)ConfigCenter::getNovel($novelId, 'ws_memory_token_budget', 20000, 'int')
            );
            if (($memoryContext['debug']['mode'] ?? '') === 'full_1m') {
                $contextAllowance = max(
                    $contextAllowance,
                    min(900000, (int)($memoryContext['debug']['prompt_char_budget'] ?? 700000))
                );
            }
            $fixedOverhead = 2048;
            $budget = (new DeterministicContextBudgeter($estimator))->select(
                $items,
                $outputReserve + $contextAllowance + $fixedOverhead,
                $outputReserve,
                $fixedOverhead
            );
            $authorityRender = null;
            $compiledMessages = $legacyMessages;
            if ($featureSnapshot?->contextV2 === true) {
                $authorityRender = (new AuthorityContextPromptRenderer($estimator))
                    ->render($budget);
                $budget = $authorityRender->context;
                $compiledMessages = $authorityRender->messages;
            }
            $selectedSkillCoordinates = $skillSelection->coordinates();
            if ($authorityRender !== null) {
                $skillContextRendered = false;
                foreach ($budget->selected as $selectedItem) {
                    if ($selectedItem->kind === 'skill.context') {
                        $skillContextRendered = true;
                        break;
                    }
                }
                if (!$skillContextRendered) {
                    $selectedSkillCoordinates = [];
                }
            }
            $package = (new LegacyPromptCompiler($estimator))->compile(
                static fn (): array => $compiledMessages,
                $budget,
                $selectedSkillCoordinates,
                [
                    'novel_id' => (int)($novel['id'] ?? 0),
                    'chapter_id' => (int)($chapter['id'] ?? 0),
                    'chapter_number' => $chapterNumber,
                    'policy_checksum' => $policySnapshot->checksum(),
                    'authority_mode' => $featureSnapshot?->contextV2 === true
                        ? 'authority' : 'shadow',
                    'authority_renderer' => $authorityRender === null
                        ? null : AuthorityContextPromptRenderer::VERSION,
                    'final_input_token_limit' => $authorityRender?->finalInputTokenLimit,
                    'builder_revision' => $builderRevision,
                    'skill_decisions_hash' => hash(
                        'sha256',
                        $canonical($skillSelection->decisions)
                    ),
                ]
            );
            $diff = PromptShadowComparator::compare($legacyMessages, $package);
            if (!HarnessControlPlane::usesAuthority($policySnapshot->harnessMode())
                && ($diff['same_messages'] ?? false) !== true) {
                throw new RuntimeException('Legacy Prompt compiler changed message content in shadow mode.');
            }
            return $package;
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[HarnessPrompt][ERROR] compile_failed exception=%s code=%d',
                $exception::class,
                (int)$exception->getCode()
            ));
            if ($featureSnapshot?->contextV2 === true) {
                throw new RuntimeException(
                    'Required Harness Context/Prompt compilation failed.',
                    0,
                    $exception
                );
            }
            return null;
        }
    }

    private static function captureLegacyDraftArtifact(
        ?string $taskId,
        WritePolicySnapshot $policySnapshot,
        ?array $taskLease,
        int $novelId,
        int $chapterId,
        string $cleanDraft
    ): void {
        try {
            $scope = self::legacyArtifactCaptureScope(
                $taskId,
                $policySnapshot,
                $taskLease,
                $novelId,
                $chapterId
            );
            if ($scope === null) {
                return;
            }
            self::legacyArtifactCaptureService()->captureDraft($scope, $cleanDraft);
        } catch (Throwable $exception) {
            self::reportLegacyArtifactCaptureFailure(
                $taskId,
                'write_engine.clean_draft',
                $exception
            );
        }
    }

    /** @param array<string,mixed> $result */
    private static function captureLegacyQualityArtifacts(
        ?string $taskId,
        WritePolicySnapshot $policySnapshot,
        ?array $taskLease,
        int $novelId,
        int $chapterId,
        QualityGateDecision $decision,
        array $result
    ): void {
        try {
            $scope = self::legacyArtifactCaptureScope(
                $taskId,
                $policySnapshot,
                $taskLease,
                $novelId,
                $chapterId
            );
            if ($scope === null) {
                return;
            }
            self::legacyArtifactCaptureService()->captureQuality(
                $scope,
                $decision,
                $result
            );
        } catch (Throwable $exception) {
            self::reportLegacyArtifactCaptureFailure(
                $taskId,
                'write_engine.quality',
                $exception
            );
        }
    }

    /**
     * Missing carrier data is deliberately a no-op: no task/run identity is
     * guessed from novel/chapter coordinates. Present data is validated by
     * the capture DTO and the database resolver before any write.
     */
    private static function legacyArtifactCaptureScope(
        ?string $taskId,
        ?WritePolicySnapshot $policySnapshot,
        ?array $taskLease,
        int $novelId,
        int $chapterId
    ): ?LegacyArtifactCaptureScope {
        if ($policySnapshot === null
            || $policySnapshot->harnessMode() === HarnessControlPlane::MODE_OFF) {
            return null;
        }
        if ($taskId === null || $taskId === '' || $taskLease === null
            || $novelId <= 0 || $chapterId <= 0
            || !array_key_exists('lease_owner', $taskLease)
            || !array_key_exists('lease_token', $taskLease)
            || !array_key_exists('fence_generation', $taskLease)
            || $taskLease['lease_owner'] === null
            || $taskLease['lease_token'] === null
            || $taskLease['fence_generation'] === null) {
            return null;
        }

        require_once __DIR__ . '/harness/artifacts/LegacyArtifactCapture.php';
        return new LegacyArtifactCaptureScope(
            $taskId,
            $taskLease['lease_owner'],
            $taskLease['lease_token'],
            $taskLease['fence_generation'],
            $novelId,
            $chapterId,
            $policySnapshot
        );
    }

    private static function legacyArtifactCaptureService(): LegacyArtifactCapture
    {
        require_once __DIR__ . '/harness/artifacts/LegacyArtifactCapture.php';
        static $capture = null;
        if (!$capture instanceof LegacyArtifactCapture) {
            $capture = LegacyArtifactCapture::production();
        }
        return $capture;
    }

    private static function reportLegacyArtifactCaptureFailure(
        ?string $taskId,
        string $boundary,
        Throwable $exception
    ): void {
        $safeTaskId = is_string($taskId)
            && preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $taskId) === 1
                ? $taskId
                : 'unknown_task';
        error_log(sprintf(
            '[HarnessArtifact][ERROR] capture_failed task_id=%s boundary=%s exception=%s code=%d',
            $safeTaskId,
            preg_replace('/[^a-z0-9_.-]/i', '_', $boundary) ?: 'unknown',
            $exception::class,
            (int)$exception->getCode()
        ));
    }

    /**
     * Phase 2.5: 主动伏笔回收规划
     *
     * 根据当前章节大纲和全书进度，从待回收伏笔中挑选最合适的伏笔，
     * 生成具体的回收指令，注入到 Prompt 中引导 AI 回收。
     */
    private static function runForeshadowingResolver(array $novel, array $chapter): ?array
    {
        $novelId = (int)$novel['id'];
        $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);
        $targetChapters = (int)($novel['target_chapters'] ?? 0);

        // 调试日志：检查前置条件
        addLog($novelId, 'debug', sprintf(
            '伏笔回收检查：章节=%d, target_chapters=%d',
            $chNum,
            $targetChapters
        ));

        if ($targetChapters <= 0) {
            if (!function_exists('estimateNovelTargetChapters')) {
                require_once __DIR__ . '/memory.php';
            }
            $targetChapters = estimateNovelTargetChapters($novelId, $chNum, 100);
            addLog($novelId, 'info', "伏笔回收：target_chapters 未设置，按已完成章估算 {$targetChapters} 章继续规划");
        }

        require_once __DIR__ . '/memory/ForeshadowingResolver.php';
        $resolver = new ForeshadowingResolver($novelId, $chNum, $targetChapters);
        $outline = trim((string)($chapter['outline'] ?? ''));
        $result = $resolver->planResolution($outline);

        // 调试日志：输出规划结果统计
        addLog($novelId, 'debug', sprintf(
            '伏笔回收规划结果：should_resolve=%s, pending=%d, phase=%s, pressure=%.2f',
            $result['should_resolve'] ? 'true' : 'false',
            $result['stats']['pending_count'] ?? 0,
            $result['stats']['plan']['phase'] ?? 'unknown',
            $result['stats']['pressure'] ?? 0
        ));

        if (!empty($result['should_resolve'])) {
            $itemDescs = array_map(
                fn($it) => "第{$it['planted_chapter']}章:{$it['description']}",
                $result['items']
            );
            addLog($novelId, 'info', sprintf(
                '伏笔回收规划：选中%d条 → %s',
                count($result['items']),
                implode(' | ', $itemDescs)
            ));
        }

        return $result;
    }

    /** 大纲强度关键词 → 温度偏移评分（computeTemperatureDelta 专用） */
    private const INTENSITY_KEYWORDS = [
        'high' => ['决战','反杀','爆发','突破','觉醒','大战','终极','生死','逆袭','翻盘','对决','屠杀','毁灭','渡劫','天劫','围攻','血战','绝杀','拼死'],
        'mid'  => ['对峙','阴谋','追杀','围困','抉择','考验','危机','伏击','暗算','冲突','对抗','谈判','布局','陷阱','试探'],
        'low'  => ['休整','修炼','日常','回忆','感悟','疗伤','整顿','休憩','闲聊','游历','探索','冥想','闭关','温习'],
    ];

    /**
     * 按全书进度/卷末位置/大纲强度/节奏标记/爽点计划计算温度偏移。
     * 结果与具体模型无关——streamWrite 在进入模型重试循环前调用一次，
     * 不再每次重试都重查 volume_outlines/chapters 并重扫关键词。
     */
    private static function computeTemperatureDelta(int $novelId, int $chapterNumber, int $targetChapters): float
    {
        if ($chapterNumber <= 0 || $targetChapters <= 0) return 0.0;

        $progressRatio = $chapterNumber / $targetChapters;
        $tempDelta = 0.0;

        $isVolumeEnd = false;
        try {
            // 向导新建的表只有 chapter_to（无 end_chapter），优先查新列
            $volEnd = DB::fetch(
                'SELECT 1 FROM volume_outlines WHERE novel_id=? AND chapter_to=?',
                [$novelId, $chapterNumber]
            );
            $isVolumeEnd = !empty($volEnd);
        } catch (Throwable $e) {
            // 审计修复 C-5（2026-06-17）：外层 catch 不再静默吞异常
            // 旧 schema 只有 end_chapter 列时，chapter_to 查询失败是预期的，
            // 但若为其它原因（连接失败/字段改名）须排查。
            if (stripos($e->getMessage(), 'end_chapter') === false
                && stripos($e->getMessage(), 'chapter_to') === false
                && stripos($e->getMessage(), 'Unknown column') === false) {
                error_log('write_engine: 异常无法识别（旧 schema 兼容?）— ' . $e->getMessage());
            }
            try {
                $volEnd = DB::fetch(
                    'SELECT 1 FROM volume_outlines WHERE novel_id=? AND end_chapter=?',
                    [$novelId, $chapterNumber]
                );
                $isVolumeEnd = !empty($volEnd);
            } catch (Throwable $e2) { error_log('write_engine volume end check failed: ' . $e2->getMessage()); }
        }

        $chMeta = null;
        try {
            $chMeta = DB::fetch(
                'SELECT outline, pacing, cool_point_type FROM chapters WHERE novel_id=? AND chapter_number=?',
                [$novelId, $chapterNumber]
            );
        } catch (Throwable $e) { error_log('write_engine chapter meta fetch failed: ' . $e->getMessage()); }

        $outline = trim((string)($chMeta['outline'] ?? ''));
        $pacing  = $chMeta['pacing'] ?? '';
        $cpType  = $chMeta['cool_point_type'] ?? '';

        $highHits = 0; $midHits = 0; $lowHits = 0;
        if ($outline !== '') {
            foreach (self::INTENSITY_KEYWORDS['high'] as $kw) { if (mb_strpos($outline, $kw) !== false) $highHits++; }
            foreach (self::INTENSITY_KEYWORDS['mid']  as $kw) { if (mb_strpos($outline, $kw) !== false) $midHits++; }
            foreach (self::INTENSITY_KEYWORDS['low']  as $kw) { if (mb_strpos($outline, $kw) !== false) $lowHits++; }
        }

        $outlineScore = min(0.15, $highHits * 0.05)
                      + min(0.06, $midHits * 0.02)
                      - min(0.12, $lowHits * 0.04);

        $pacingDelta = match($pacing) {
            '快' => 0.05,
            'slow', '慢' => -0.05,
            default => 0.0,
        };

        $cpDelta = 0.0;
        if ($cpType !== '' && $cpType !== 'none') {
            $highCp = ['underdog_win','face_slap','last_stand','truth_reveal'];
            $cpDelta = in_array($cpType, $highCp, true) ? 0.06 : 0.03;
        }

        if ($isVolumeEnd) {
            $tempDelta += 0.12;
        } elseif ($progressRatio > 0.85) {
            $tempDelta += 0.10;
        } elseif ($progressRatio < 0.1) {
            $tempDelta -= 0.08;
        }

        $tempDelta += $outlineScore + $pacingDelta + $cpDelta;
        return max(-0.2, min(0.25, $tempDelta));
    }

    /**
     * 正文可被视为完整章节的绝对截断下限（最终防线）。
     *
     * 正常章节至少达到目标字数的 30%，且不低于 200 字；对于本身小于
     * 200 字的特殊短章，不把下限抬高到目标值以上。
     *
     * P2 口径对齐（2026-08-16，审计 B-1）+ B1 修订（2026-08-17 写作逻辑）：
     * 本函数是 saveChapter 的「疑似截断」拒绝线与 streamWrite 重试兜底的准入线；
     * 流式完成检查的重试触发按承诺区间下限（max(本下限, 目标-动态容差)），
     * 见 streamWrite 内 $completionMinWords。三层语义：
     *   1) < 本下限            → 疑似截断：重试，绝不落盘（优先换模型）；
     *   2) 本下限 ~ 承诺下限   → 重试拉质量；全模型耗尽时最优尝试可落盘，但
     *                            由 shortChapterFailureReason 标 failed（B1），
     *                            不再 silently completed；
     *   3) >= 承诺下限         → 直接过（再按上限保钩子修剪）。
     */
    private static function hardMinimumWords(int $targetWords): int
    {
        if ($targetWords <= 0) {
            return 200;
        }

        return min($targetWords, max(200, (int)ceil($targetWords * 0.30)));
    }

    /**
     * 落盘前硬质检：跟纲覆盖过低或死人复出无交代时不进入 completed。
     * 启用 RewriteAgent 时 saveChapter 跳过本闸，留给后处理重写队列；
     * 重写结束后必须再跑一次（见 recordCompletedChapterQualityWarning），
     * 仍失败则回退 failed，禁止把坏稿写入记忆。
     */
    /**
     * Run the expensive content checks without consulting mutable policy.
     * The caller decides whether these findings are enabled using the frozen
     * WritePolicySnapshot and CanonicalQualityGate applies their disposition.
     *
     * @return array{
     *   hard_findings:list<array{rule_id:string,message:string,evidence:array}>,
     *   evaluator_error:?string
     * }
     */
    private static function collectCanonicalQualityFindings(
        int $novelId,
        array $ch,
        string $content
    ): array {
        $findings = [];
        $errors = [];

        if (!function_exists('countWords')) {
            require_once __DIR__ . '/helpers.php';
        }
        try {
            require_once __DIR__ . '/quality/Gates.php';
        } catch (Throwable $exception) {
            return [
                'hard_findings' => [],
                'evaluator_error' => 'quality gates could not be loaded: ' . $exception->getMessage(),
            ];
        }

        if (function_exists('checkGate6_OutlineAdherence')) {
            try {
                $gate = checkGate6_OutlineAdherence($ch, $content);
                if (empty($gate['status'])) {
                    $issues = array_values(array_map(
                        static fn(mixed $issue): string => (string)$issue,
                        array_slice((array)($gate['issues'] ?? []), 0, 5)
                    ));
                    $findings[] = [
                        'rule_id' => 'outline.severe_drift',
                        'message' => 'Chapter content severely diverges from its frozen outline.',
                        'evidence' => ['issues' => $issues],
                    ];
                }
            } catch (Throwable $exception) {
                $errors[] = 'outline gate failed: ' . $exception->getMessage();
            }
        }

        $chapterId = (int)($ch['id'] ?? 0);
        if ($chapterId > 0 && function_exists('checkGate5_Consistency')) {
            try {
                $gate = checkGate5_Consistency($chapterId, $novelId, $content);
                $issues = array_values(array_map(
                    static fn(mixed $issue): string => (string)$issue,
                    array_slice((array)($gate['issues'] ?? []), 0, 5)
                ));
                $severe = false;
                foreach ($issues as $issue) {
                    if (str_contains($issue, '❌')) {
                        $severe = true;
                        break;
                    }
                }
                if (empty($gate['status']) && $severe) {
                    $findings[] = [
                        'rule_id' => 'continuity.severe',
                        'message' => 'Chapter content contains a severe continuity violation.',
                        'evidence' => ['issues' => $issues],
                    ];
                }
            } catch (Throwable $exception) {
                $errors[] = 'continuity gate failed: ' . $exception->getMessage();
            }
        }

        return [
            'hard_findings' => $findings,
            'evaluator_error' => $errors === [] ? null : implode('; ', $errors),
        ];
    }

    /** @param array<string,mixed> $updates */
    private static function assertCanonMetadataUpdates(array $updates): void
    {
        $allowed = [
            'retry_count',
            'gate_results',
            'title',
            'tokens_used',
            'cache_hit_tokens',
            'duration_ms',
            'hook_type',
            'opening_type',
        ];
        $unknown = array_values(array_diff(array_keys($updates), $allowed));
        if ($unknown !== []) {
            throw new LogicException(
                'Candidate commit callback attempted to mutate Canon fields: ' . implode(', ', $unknown)
            );
        }
    }

    /**
     * Authenticate the frozen P6 decision before Candidate creation. The
     * decision carries only bounded hashes/coordinates; Reviewer prose remains
     * in its restricted Artifact and is never trusted as a Canon instruction.
     *
     * @param array<string,mixed>|null $decision
     * @return array<string,mixed>|null
     */
    private static function validatePreCanonReviewDecision(
        ?array $decision,
        string $selectedContent
    ): ?array {
        if ($decision === null) {
            return null;
        }
        $expected = [
            'schema', 'result_uid', 'disposition', 'commit_allowed',
            'raw_draft_hash', 'selected_draft_hash', 'round_count',
            'bypass_reason',
        ];
        $actual = array_keys($decision);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        $disposition = $decision['disposition'] ?? null;
        $commitAllowed = $decision['commit_allowed'] ?? null;
        $validDisposition = is_string($disposition)
            && in_array($disposition, [
                'accepted', 'waiting_approval', 'quarantined',
            ], true);
        if ($actual !== $expected
            || ($decision['schema'] ?? null) !== 'harness.review.decision.v1'
            || !is_string($decision['result_uid'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $decision['result_uid']) !== 1
            || !$validDisposition
            || !is_bool($commitAllowed)
            || $commitAllowed !== ($disposition === 'accepted')
            || !is_string($decision['raw_draft_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $decision['raw_draft_hash']) !== 1
            || !is_string($decision['selected_draft_hash'] ?? null)
            || !hash_equals(
                hash('sha256', $selectedContent),
                $decision['selected_draft_hash']
            )
            || !is_int($decision['round_count'])
            || $decision['round_count'] < 1
            || $decision['round_count'] > 2
            || ($decision['bypass_reason'] ?? null) !== null) {
            throw new WriteEngineValidationException(
                'Frozen post-generation Review decision is malformed or stale.'
            );
        }
        return $decision;
    }

    private static function qualityDecisionReason(QualityGateDecision $decision): string
    {
        $messages = [];
        foreach ($decision->hardIssues() as $issue) {
            $messages[] = $issue['message'];
        }
        foreach ($decision->softWarnings() as $warning) {
            $messages[] = $warning['message'];
        }
        $messages = array_values(array_unique(array_filter(array_map('trim', $messages))));
        return implode('; ', $messages);
    }

    private static function hardQualityFailureReason(int $novelId, array $ch, string $content): string
    {
        try {
            if (!ConfigCenter::get('ws_quality_check_enabled', true, 'bool')) {
                return '';
            }
            if (!function_exists('countWords')) {
                require_once __DIR__ . '/helpers.php';
            }
            require_once __DIR__ . '/quality/Gates.php';
            if (function_exists('checkGate6_OutlineAdherence')) {
                $g6 = checkGate6_OutlineAdherence($ch, $content);
                if (empty($g6['status'])) {
                    $issues = implode('；', array_slice((array)($g6['issues'] ?? []), 0, 2));
                    return '跟纲不足' . ($issues !== '' ? "：{$issues}" : '');
                }
            }
            $chId = (int)($ch['id'] ?? 0);
            if ($chId > 0 && function_exists('checkGate5_Consistency')) {
                $g5 = checkGate5_Consistency($chId, $novelId, $content);
                $severe = false;
                foreach ((array)($g5['issues'] ?? []) as $issue) {
                    if (str_contains((string)$issue, '❌')) {
                        $severe = true;
                        break;
                    }
                }
                if (empty($g5['status']) && $severe) {
                    $issues = implode('；', array_slice((array)($g5['issues'] ?? []), 0, 2));
                    return '因果链严重问题' . ($issues !== '' ? "：{$issues}" : '');
                }
            }
        } catch (\Throwable $e) {
            error_log('hardQualityFailureReason skipped: ' . $e->getMessage());
        }
        return '';
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 B1）：承诺区间下限硬拦的原因文案。
     *
     * hardMinimumWords（目标 30%、≥200）只作为「疑似截断」的流式重试/拒绝落盘
     * 阈值；落在 30%～承诺区间下限之间的短章此前会被 silently completed。
     * 现返回非空原因走 failed 通道（与跟纲/死人复出同闸门）；弱模型书可通过
     * 小说级开关 ws_block_short_chapter 关闭（默认开）。
     * 不读 ws_chapter_word_tolerance（固定 ±N 字死旋钮），与 Prompt/流式重试
     * 共用 calculateDynamicTolerance 单一来源，避免双源漂移。
     */
    private static function shortChapterFailureReason(int $novelId, int $targetWords, int $words): string
    {
        try {
            if (!ConfigCenter::getNovel($novelId, 'ws_block_short_chapter', true, 'bool')) {
                return '';
            }
            if (!function_exists('calculateDynamicTolerance')) {
                require_once __DIR__ . '/prompt.php';
            }
            $tolerance = calculateDynamicTolerance($targetWords, $novelId);
            $minWords = (int)($tolerance['min'] ?? 0);
            if ($minWords <= 0 || $words >= $minWords) {
                return '';
            }
            return sprintf(
                '未达承诺字数：正文%d字低于承诺区间下限%d字（目标%d字）；疑似截断请优先换模型重写',
                $words,
                $minWords,
                $targetWords
            );
        } catch (\Throwable $e) {
            error_log('shortChapterFailureReason skipped: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 B0）：把落盘硬质检失败原因写进 gate_results JSON。
     *
     * 失败（$reason 非空）：附加一条 name=落盘硬质检 的门结果（含 hard_fail_reason），
     * 保留其余历史门结果；成功（$reason 为空）：剔除历史失败条目。
     * 返回 null 表示无需覆写（无历史失败标记且本次成功）。
     */
    private static function stampHardFailGateResults(int $chId, string $reason): ?string
    {
        $prev = [];
        $hasPrev = false;
        try {
            $raw = DB::fetchColumn('SELECT gate_results FROM chapters WHERE id=?', [$chId]);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $hasPrev = true;
                    $prev = $decoded;
                }
            }
        } catch (\Throwable $e) {
            // 旧值不可读时按空历史处理，仅写本次结果
        }
        if (!array_is_list($prev)) {
            $prev = []; // gate_results 期望门结果列表，防脏数据扩散
        }
        $kept = array_values(array_filter($prev, static function ($g) {
            return is_array($g) && empty($g['hard_fail_reason']);
        }));
        if ($reason === '') {
            if (!$hasPrev || count($kept) === count($prev)) {
                return null;
            }
            return $kept === [] ? '[]' : json_encode($kept, JSON_UNESCAPED_UNICODE);
        }
        $kept[] = [
            'name'             => '落盘硬质检',
            'status'           => '',
            'score'            => 0,
            'issues'           => [$reason],
            'hard_fail_reason' => $reason,
            'failed_at'        => HarnessRuntime::sqlNow(),
        ];
        return json_encode($kept, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 重写逃生舱用完后：硬质检仍失败时仅记录警告，不再回退 completed。
     * 这样后处理与记忆最终化仍会运行，后续章节能读到上一章的正文上下文。
     */
    private static function recordCompletedChapterQualityWarning(
        int $novelId,
        int $chId,
        int $chNum,
        string $reason
    ): bool {
        $stamped = self::stampHardFailGateResults($chId, $reason);
        if ($stamped === null) return true;
        $affected = DB::update(
            'chapters',
            ['gate_results' => $stamped],
            'id=? AND novel_id=? AND status="completed"',
            [$chId, $novelId]
        );
        if ($affected === 0) {
            return false;
        }
        clearChapterCache($chId, $novelId);
        return true;
    }

    /**
     * Phase 4: 带模型回退的流式写作
     * @param callable $onChunk      fn(string $token): void
     * @param callable $onMsg        fn(array $payload): void
     * @param callable $onHeartbeat  fn(): void
     * @param callable|null $onThinking fn(string $reasoningChunk): void  深度思考过程回调
     * @return array{content: string, model: ?AIClient}
     * @throws Exception 取消或全部模型失败
     */
    public static function streamWrite(
        array $messages,
        int $targetWords,
        int $novelId,
        callable $onChunk,
        callable $onMsg,
        callable $onHeartbeat,
        ?callable $onThinking = null,
        ?int $preferredModelId = null,
        int $chapterNumber = 0,
        int $targetChapters = 0,
        ?callable $onWaiting = null,
        ?callable $abortProbe = null,
        ?callable $modelListGuard = null
    ): array {
        // A frozen write-policy emergency epoch is deliberately the one live
        // control input. Callers provide a fail-closed probe so model fallback,
        // retry sleeps and rate-limit backoff cannot outlive an operator stop.
        $assertMayContinue = static function () use ($abortProbe): void {
            if ($abortProbe !== null) {
                $abortProbe();
            }
        };
        $assertMayContinue();
        $modelList   = getModelFallbackList($preferredModelId);
        if ($modelListGuard !== null) {
            $modelListGuard($modelList);
        }
        $modelErrors = [];
        $fullContent = '';
        $usedModel   = null;
        $estTokens   = (int)($targetWords * CFG_TOKEN_RATIO) + CFG_TOKEN_BUFFER;
        $usage       = null;
        $durationMs  = null;
        $hardMinWords = self::hardMinimumWords($targetWords);
        $lengthTolerance = calculateDynamicTolerance($targetWords, $novelId);
        $silenceMinWords = max($hardMinWords, (int)($lengthTolerance['min'] ?? $hardMinWords));
        // 审计修复（2026-08-16 P2，B-1）：完成检查下限与静默超时保全下限同口径。
        // 此前完成检查只用 30% 绝对下限：目标 2000 字、承诺区间下限 1800 字时，
        // 601 字即通过全部硬校验落盘，远松于 prompt 承诺的容差区间。
        // 现以承诺区间下限触发重试拉质量；重试耗尽后由最优尝试兜底（见循环后分支）。
        $completionMinWords = $silenceMinWords;
        $bestAttempt = null; // 未达承诺下限但高于截断下限的最优尝试（全模型耗尽时保全）
        $bestOverlongAttempt = null; // Complete drafts only; never remove middle events to fit.
        // 不同模型 tokenizer 差异很大；按 1 个 Unicode 字符≈1 token 保守估算输入。
        $promptChars = 0;
        foreach ($messages as $message) {
            $promptChars += mb_strlen((string)($message['content'] ?? ''));
        }

        // 审计修复（2026-08-02 P2）：流式风格实时监测。
        // 在 chunk 累积过程中节流检测 AI 痕迹密度，前 1200 字严重超标时
        // 中止当前流并注入修正指令重试 1 次（MAX_ABORT_RETRIES）。
        // 纯 PHP 正则，无 AI 调用开销；关闭时 $styleMonitor 为 null，零成本短路。
        $styleMonitorEnabled = (bool)getSystemSetting('ws_stream_style_monitor_enabled', true, 'bool');
        $styleMonitor = null;
        $styleAbort   = false;
        $retryMessages = null; // 风格中止重试时用的带修正指令的 messages 副本
        if ($styleMonitorEnabled) {
            require_once __DIR__ . '/agents/StreamStyleMonitor.php';
            $styleMonitor = new \StreamStyleMonitor();
        }
        // 温度偏移与模型无关，进入重试循环前只算一次
        $tempDelta   = self::computeTemperatureDelta($novelId, $chapterNumber, $targetChapters);
        $resetPartialContent = static function (string $reason) use (&$fullContent, $onMsg): void {
            if ($fullContent === '') {
                return;
            }
            $discardedWords = countWords($fullContent);
            $fullContent = '';
            $onMsg([
                'reset_content'  => true,
                'discarded_words' => $discardedWords,
                'reason'         => $reason,
            ]);
        };
        // 审计优化（2026-10-23）：重试纠偏指令按类别各保留一条（风格/篇幅），
        // 重建 retryMessages 而非逐次追加——连续超字时旧实现会叠加多条相同指令，
        // 且先超字后风格中止时风格修正因 retryMessages 非空被跳过。
        $retryCorrections = ['style' => null, 'length' => null];
        $applyRetryCorrections = static function () use ($messages, &$retryCorrections, &$retryMessages): void {
            $retryMessages = $messages;
            foreach ($retryCorrections as $correctionText) {
                if (is_string($correctionText) && $correctionText !== '') {
                    $retryMessages[] = ['role' => 'user', 'content' => $correctionText];
                }
            }
        };
        // 审计优化（2026-10-23）：字数/超字/风格中止属于内容判定，与接口限流无关，
        // 下一次尝试跳过 RT_RETRY_DELAY 线性等待（默认 15s、30s）；接口异常/超时仍按原节奏退避。
        $skipNextRetryDelay = false;

        foreach ($modelList as $modelCfg) {
            $assertMayContinue();
            $modelId    = (int)($modelCfg['id'] ?? 0);
            $modelLabel = $modelCfg['name'] ?? "模型{$modelId}";
            $isThinking = !empty($modelCfg['thinking_enabled']);
            $timeoutSec = $isThinking ? RT_THINKING_TIMEOUT : RT_NONTHINKING_TIMEOUT;

            if (($modelErrors[$modelId] ?? 0) >= RT_MODEL_ERR_MAX) {
                $onMsg(['info' => "模型 {$modelLabel} 错误次数过多，跳过"]);
                continue;
            }

            $sameModelRetries = 0;
            while ($sameModelRetries < RT_SAME_MODEL_MAX) {
                $assertMayContinue();
                $skipThisRetryDelay = $skipNextRetryDelay;
                $skipNextRetryDelay = false;
                if ($sameModelRetries > 0 && !$skipThisRetryDelay) {
                    $retryDelay = RT_RETRY_DELAY * $sameModelRetries;
                    $onMsg(['waiting' => true, 'msg' => "等待 {$retryDelay} 秒后重试..."]);
                    for ($w = 0; $w < $retryDelay; $w += RT_POLL_INTERVAL) {
                        HarnessRuntime::sleepSeconds((int)min(RT_POLL_INTERVAL, $retryDelay - $w));
                        $onHeartbeat();
                        $assertMayContinue();
                        // v1.4 文件系统检查替代 DB 查询，file_exists() 比 PDO prepare+execute 快 100+ 倍
                        if (file_exists(self::cancelFlagPath($novelId))) {
                            throw new WriteEngineCanceledException('用户取消了写作');
                        }
                    }
                }

                $streamStart = HarnessRuntime::unixTime();
                $fullContent = '';
                $styleAbort = false; // 每次流开始重置（保留 $styleMonitor->abortRetriesUsed 跨重试计数）
                // 审计修复（2026-08-09 R3）：非风格中止的重试（API 错误/超时半截失败）此前不清监测器 buffer，
                // 旧流残留与新流叠加会导致密度虚高、误 abort。每次开流统一部分重置（保留 abortRetriesUsed 上限计数）。
                $styleMonitor?->resetForNewStream();
                $ai = new AIClient($modelCfg);
                $usedModel = $ai;
                // 审计优化 P2-1（2026-06-16）：显式传入 $onWaiting，替代 $GLOBALS['sendWaiting']
                $waitingCb = $onWaiting;
                if ($waitingCb === null && isset($GLOBALS['sendWaiting']) && is_callable($GLOBALS['sendWaiting'])) {
                    $waitingCb = $GLOBALS['sendWaiting'];
                }
                $abortFailure = null;
                // cancelCheckCallback 同时检查用户取消标志和风格中止标志
                $ai->setCallbacks(
                    $onHeartbeat,
                    $waitingCb,
                    function() use ($novelId, &$styleAbort, $abortProbe, &$abortFailure) {
                        if ($abortProbe !== null) {
                            try {
                                $abortProbe();
                            } catch (Throwable $exception) {
                                $abortFailure ??= $exception;
                                return true;
                            }
                        }
                        if (file_exists(self::cancelFlagPath($novelId))) return true;
                        if ($styleAbort) return true; // 风格中止也通过此机制停止 cURL
                        return false;
                    }
                );

                $desired = max($ai->getMaxTokens(), $estTokens);
                if ($desired > $ai->getMaxTokens()) {
                    $ai->setMaxTokens($desired);
                    $onMsg(['info' => "📊 max_tokens 调至 {$desired}"]);
                }

                // 完整上下文是按首选模型构建的。回退到上下文较小的模型时，绝不能
                // 原样发送一个超窗 prompt；宁可跳过该模型并给出明确诊断。
                $contextLimit = $ai->getContextLimit();
                $safeInputLimit = (int)floor($contextLimit * 0.90);
                if ($promptChars + $desired > $safeInputLimit) {
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $onMsg([
                        'info' => "跳过模型 {$modelLabel}：上下文容量不足（估算输入{$promptChars} token，模型上限{$contextLimit}）",
                        'context_too_large' => true,
                    ]);
                    continue 2;
                }

                $onMsg([
                    'model' => $modelLabel, 'attempt' => $sameModelRetries + 1,
                    'timeout' => $timeoutSec, 'thinking' => $isThinking,
                ]);

                if (abs($tempDelta) >= 0.02) {
                    $ai->setTemperature($ai->getTemperature() + $tempDelta);
                }

                $canceled = false; $cancelCount = 0;
                $cancelCheckInterval = 10;
                // 风格监测节流：每 50 个 chunk 检查一次 shouldAbort（避免每个 chunk 都调）
                $styleCheckInterval = 50;
                try {
                    // 风格中止重试时用带修正指令的 messages 副本，否则用原始 $messages
                    $activeMessages = $retryMessages ?? $messages;
                    $usage = $ai->chatStream($activeMessages, function(string $token) use (&$fullContent, $novelId, &$canceled, &$cancelCount, $cancelCheckInterval, $onChunk, $styleMonitor, &$styleAbort, $styleCheckInterval, $onMsg) {
                        if (!$canceled && ++$cancelCount % $cancelCheckInterval === 0) {
                            if (file_exists(self::cancelFlagPath($novelId))) $canceled = true;
                        }
                        // v1.11.10: 在 cURL 内部 throw 异常会跨 C 边界，Windows CLI/Web FastCGI 易发进程级闪退。
                        // 改为 return 让本回调安全退出；由 AIClient 的 progress 钩子
                        // cancelCheckCallback 在 cURL 外部抛 AIRequestCanceledException。
                        if ($canceled) return;
                        if ($token === '[DONE]') return;
                        $fullContent .= $token;
                        $onChunk($token);

                        // 流式风格实时监测：节流 feed + 周期性检查 shouldAbort
                        if ($styleMonitor !== null) {
                            $styleMonitor->feed($token);
                            if ($cancelCount % $styleCheckInterval === 0) {
                                // 推送新警告给用户（实时反馈）
                                foreach ($styleMonitor->drainWarnings() as $warn) {
                                    $onMsg(['warning' => '⚠️ 风格监测：' . $warn['message']]);
                                }
                                // 严重超标 → 触发中止（设置 flag，cancelCheckCallback 会在下个 tick 停止 cURL）
                                if (!$styleAbort && $styleMonitor->shouldAbort()) {
                                    $styleAbort = true;
                                    $onMsg(['warning' => '🚨 风格漂移严重，中止本次流并注入修正指令重试…']);
                                }
                            }
                        }
                    }, 'creative', $onThinking);
                    ChapterGenerationIntegrity::assertComplete($ai->lastFinishReason);
                } catch (Exception $e) {
                    // Never convert a kill-switch/read-failure abort into an
                    // ordinary model retry or a misleading user cancellation.
                    if ($abortFailure instanceof Throwable) {
                        throw $abortFailure;
                    }
                    $assertMayContinue();
                    $errMsg = $e->getMessage();
                    // 风格中止优先处理：在用户取消 re-throw 之前拦截（两者都触发 'canceled' lastFinishReason）
                    if ($styleAbort && $styleMonitor !== null) {
                        $resetPartialContent('风格漂移早期预警，已清除本次未完成正文');
                        $abortReason = $styleMonitor->abortReason() ?? '风格漂移';
                        error_log("WriteEngine streamWrite style abort model#{$modelId}: {$abortReason}");
                        $onMsg([
                            'waiting' => true,
                            'reason' => "风格漂移（{$abortReason}），注入修正指令重试",
                            'retry' => "第" . ($sameModelRetries + 1) . "次 / " . RT_SAME_MODEL_MAX,
                        ]);
                        // 构造带修正指令的 messages 副本，供下一次重试用
                        $correction = $styleMonitor->getCorrectionDirective();
                        if ($correction !== '') {
                            // 追加为新的 user message（不污染原始 $messages）
                            $retryCorrections['style'] = $correction;
                            $applyRetryCorrections();
                        }
                        $styleMonitor->markAbortRetryUsed();
                        $skipNextRetryDelay = true;
                        $sameModelRetries++;
                        if ($sameModelRetries >= RT_SAME_MODEL_MAX) continue 2;
                        continue; // 重试当前模型（带修正指令）
                    }
                    if ($e instanceof AIRequestCanceledException
                        || $e instanceof WriteEngineCanceledException) {
                        throw $e instanceof WriteEngineCanceledException
                            ? $e
                            : new WriteEngineCanceledException(
                                '用户取消了写作',
                                0,
                                $e
                            );
                    }
                    // A long partial response is still incomplete. Silence
                    // timeouts and explicit token limits use the same bounded
                    // retry path; neither can be rescued by word count alone.
                    // chatStream 可能已经推送过若干 chunk 才抛错；失败尝试的半截
                    // 缓冲绝不能在最后一个模型耗尽后被当成成功结果返回。
                    $resetPartialContent('模型调用失败，已清除本次未完成正文');
                    error_log("WriteEngine streamWrite model#{$modelId} failed: {$errMsg}");
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $sameModelRetries++;

                    // W-15 修复：429 速率限制单独识别并指数退避（3s/6s/12s），与 withModelFallback 一致。
                    // 之前所有异常一视同仁，限流被当成普通失败立即重试，浪费重试预算。
                    $is429 = stripos($errMsg, '429') !== false || stripos($errMsg, 'rate limit') !== false || stripos($errMsg, 'too many') !== false;
                    if ($is429 && $sameModelRetries < RT_SAME_MODEL_MAX) {
                        $rateLimitDelay = 3 * (1 << min($sameModelRetries - 1, 2)); // 3s, 6s, 12s
                        $onMsg([
                            'waiting' => true,
                            'reason' => "API 限流(429)，{$rateLimitDelay}秒后重试",
                            'retry' => "第{$sameModelRetries}次 / " . RT_SAME_MODEL_MAX,
                        ]);
                        // P0-1 优化：429 退避期间响应取消标志，避免用户取消后仍等待完整退避时长
                        for ($s = 0; $s < $rateLimitDelay; $s++) {
                            $assertMayContinue();
                            if (file_exists(self::cancelFlagPath($novelId))) {
                                throw new WriteEngineCanceledException('用户取消了写作');
                            }
                            HarnessRuntime::sleepSeconds(1);
                            $onHeartbeat();
                            $assertMayContinue();
                        }
                        continue;
                    }

                    $onMsg([
                        'waiting' => true,
                        'reason' => '模型调用失败，正在重试（已耗时' . (HarnessRuntime::unixTime() - $streamStart) . '秒）',
                        'retry' => "第{$sameModelRetries}次 / " . RT_SAME_MODEL_MAX,
                    ]);
                    // 同模型内重试：未达上限则留在当前 while 循环，达上限则跳到下一模型
                    if ($sameModelRetries >= RT_SAME_MODEL_MAX) continue 2;
                    continue; // 重试当前模型
                }

                $assertMayContinue();
                // v1.4: 采集 token 用量和实际耗时，为 OptimizationAgent 提供真实数据基础
                $durationMs = (HarnessRuntime::unixTime() - $streamStart) * 1000;

                $sinceLast = HarnessRuntime::unixTime() - ($ai->lastChunkTime ?: $streamStart);
                if ($sinceLast >= $timeoutSec) {
                    $timedOutWords = countWords($fullContent);
                    $resetPartialContent('AI 输出超时，已清除本次未完成正文');
                    $sameModelRetries++;
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $onMsg([
                        'waiting' => true,
                        'reason' => "超时（{$sinceLast}秒无有效输出，丢弃{$timedOutWords}字部分正文），重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX,
                    ]);
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                    continue 2;
                }

                // Normalize before checking length. Complete overlong drafts
                // are regenerated within the existing retry budget, never cut.
                $fullContent = stripMetaLeaks(stripSegmentMarkers($fullContent));
                $actualWords = countWords($fullContent);
                if ($actualWords < $completionMinWords) {
                    // P2 对齐（2026-08-16）：未达承诺区间下限 → 重试拉质量；
                    // 同时记录高于绝对截断下限的最优尝试，供全部模型耗尽时兜底保全。
                    if ($actualWords >= $hardMinWords
                        && ($bestAttempt === null || $actualWords > $bestAttempt['words'])) {
                        $bestAttempt = [
                            'content'     => $fullContent,
                            'words'       => $actualWords,
                            'model'       => $usedModel,
                            'usage'       => $usage,
                            'duration_ms' => $durationMs,
                        ];
                    }
                    $floorReason = $actualWords < $hardMinWords
                        ? "模型返回正文疑似截断（{$actualWords}字 < 截断下限{$hardMinWords}字）"
                        : "正文低于承诺字数下限（{$actualWords}字 < 下限{$completionMinWords}字，目标{$targetWords}字）";
                    $resetPartialContent('正文未达字数下限，已清除本次未完成正文');
                    $sameModelRetries++;
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    // 审计优化（2026-10-23）：原样重发同一提示词常得到同样偏短的结果；
                    // 重试时明确告知上次篇幅与承诺区间（与超字分支对称，替换而非叠加）。
                    $retryCorrections['length'] = "上一次输出约{$actualWords}字，低于本章要求的{$completionMinWords}–{$lengthTolerance['max']}字。"
                        . "请重新生成完整章节：按大纲把全部情节写足，不得提前收尾或省略结局，篇幅控制在{$completionMinWords}–{$lengthTolerance['max']}字。";
                    $applyRetryCorrections();
                    $skipNextRetryDelay = true;
                    $onMsg([
                        'waiting' => true,
                        'reason' => "{$floorReason}，重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX,
                    ]);
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                    continue 2;
                }

                $modelErrors[$modelId] = 0;
                $lenMax = $lengthTolerance['max'];
                // 审计优化（2026-10-23，用户确认放宽）：上限之上再留一个容差的软区间。
                // 软区间内的完整稿直接保留（落盘时仍由 CanonicalQualityGate 记
                // word_count.maximum 软警告供审阅），只有超出软区间才整章重写——
                // 原先超上限 1 字即丢弃完整稿重写，持续偏长的模型会把单章成本放大数倍。
                $overlongRegenLimit = $lenMax + max(0, (int)($lengthTolerance['tolerance'] ?? 0));
                if ($actualWords > $lenMax && $actualWords <= $overlongRegenLimit) {
                    $onMsg(['warning' => "正文{$actualWords}字略超上限{$lenMax}字（未超过重写线{$overlongRegenLimit}字），已完整保留，不再整章重写。"]);
                } elseif ($actualWords > $lenMax) {
                    if ($bestOverlongAttempt === null || $actualWords < $bestOverlongAttempt['words']) {
                        $bestOverlongAttempt = [
                            'content' => $fullContent, 'words' => $actualWords,
                            'model' => $usedModel, 'usage' => $usage, 'duration_ms' => $durationMs,
                        ];
                    }
                    $resetPartialContent('正文超字，完整候选已保留，正在尝试重新生成');
                    $sameModelRetries++;
                    $retryCorrections['length'] =
                        "请重新生成完整章节，控制在{$completionMinWords}–{$lenMax}字。保留必要事件与因果衔接，压缩冗余描写，不得截去中间剧情或省略结局。";
                    $applyRetryCorrections();
                    $skipNextRetryDelay = true;
                    $onMsg(['warning' => "正文{$actualWords}字超过上限{$lenMax}字，将在剩余重试预算内重新生成。"]);
                    if ($sameModelRetries >= RT_SAME_MODEL_MAX) continue 2;
                    continue;
                }

                // 流式风格监测：成功完成后推送最终报告（含对话标签单一性等需全章统计的项）
                if ($styleMonitor !== null) {
                    $report = $styleMonitor->getFinalReport();
                    // 推送剩余未推送的警告
                    foreach ($styleMonitor->drainWarnings() as $warn) {
                        $onMsg(['warning' => '⚠️ 风格监测：' . $warn['message']]);
                    }
                    foreach ($report['dialogue_issues'] as $issue) {
                        $onMsg(['warning' => '⚠️ 风格监测：' . $issue]);
                    }
                    if ($report['aborted']) {
                        $onMsg(['info' => '✅ 风格中止重试后本次输出已通过早期预警（前次原因：' . $report['abort_reason'] . '）']);
                    }
                }

                break 2;
            }
        }

        $restoredAttempt = false;
        if (empty($fullContent)) {
            if ($bestOverlongAttempt !== null) {
                $restoredAttempt = true;
                $fullContent = $bestOverlongAttempt['content'];
                $usedModel = $bestOverlongAttempt['model'];
                $usage = $bestOverlongAttempt['usage'];
                $durationMs = $bestOverlongAttempt['duration_ms'];
                $onMsg([
                    'warning' => "重试后仍超字，已完整保留{$bestOverlongAttempt['words']}字正文，请按篇幅提示审阅。",
                    'content_restored' => true, 'restored_words' => $bestOverlongAttempt['words'],
                ]);
            }
        }
        if (empty($fullContent)) {
            // 审计修复（2026-08-16 P2，B-1）：全部模型均未达到承诺区间下限时的兜底保全。
            // 修复前 30%~承诺下限之间的正文不做任何重试直接放行；修复后先重试拉质量，
            // 重试耗尽则保全最优尝试（仍须高于绝对截断下限）——弱模型书的可用性
            // 不劣于修复前，正文最终仍受 saveChapter 的截断防线约束。
            if ($bestAttempt !== null && $bestAttempt['words'] >= $hardMinWords) {
                $restoredAttempt = true;
                $fullContent = $bestAttempt['content'];
                $usedModel   = $bestAttempt['model'];
                $usage       = $bestAttempt['usage'];
                $durationMs  = $bestAttempt['duration_ms'];
                $onMsg([
                    'warning' => "⚠️ 全部模型重试后仍未达承诺字数下限（{$completionMinWords}字），"
                        . "已保全最优尝试 {$bestAttempt['words']} 字（目标{$targetWords}字）",
                    'content_restored' => true,
                    'restored_words'   => $bestAttempt['words'],
                ]);
            } else {
                $errorSummary = [];
                foreach ($modelErrors as $mid => $cnt) {
                    $errorSummary[] = "模型#{$mid}失败{$cnt}次";
                }
                // 审计修复 S-2（2026-09-27）：抛专类型标记"引擎内部全部有界
                // 重试已耗尽"，Harness 适配层据此落终态而非再放大重试。
                throw new WriteEngineGenerationExhaustedException(
                    '所有模型均未产出内容：' . implode('；', $errorSummary ?: ['无可用模型'])
                );
            }
        }

        $assertMayContinue();
        if ($restoredAttempt) {
            // SSE and worker content files consume reset_content and chunks.
            // A metadata-only restoration would leave their cleared view empty.
            $onMsg(['reset_content' => true, 'reason' => '恢复最终选定的完整候选']);
            $onChunk($fullContent);
        }
        return ['content' => $fullContent, 'model' => $usedModel, 'usage' => $usage, 'duration_ms' => $durationMs];
    }

    /**
     * Phase 5: 落盘正文 + 版本备份 + 取消检测
     * @param ?array $usage     chatStream() 返回的 usage 数组 ['prompt_tokens','completion_tokens','total_tokens']
     * @param ?int   $durationMs 本章生成耗时（毫秒）
     * @param ?array $preCanonReviewDecision frozen P6 decision Artifact payload
     * @return array{words: int, chapter: array}
     * @throws RuntimeException
     */
    public static function saveChapter(
        int $chapterId,
        int $novelId,
        string $fullContent,
        int $targetWords,
        ?AIClient $usedModel,
        array $chapter,
        ?array $usage = null,
        ?int $durationMs = null,
        ?WritePolicySnapshot $policySnapshot = null,
        ?array $preCanonReviewDecision = null
    ): array
    {
        $ch = $chapter;
        $chId = $chapterId;
        $policySnapshot = self::resolveWritePolicySnapshot(
            $novelId,
            $targetWords,
            $policySnapshot
        );

        // 落盘前取消检测（v1.4 文件系统加速）——置于版本备份之前，
        // 用户已取消时不再白写一条 chapter_versions
        if (file_exists(self::cancelFlagPath($novelId))) {
            throw new WriteEngineCanceledException('canceled');
        }

        // 过滤AI误生成的段落标记
        $fullContent = stripSegmentMarkers($fullContent);
        // 过滤AI误把章节坐标写进正文（伏笔回收穿帮）
        $fullContent = stripMetaLeaks($fullContent);
        $preCanonReviewDecision = self::validatePreCanonReviewDecision(
            $preCanonReviewDecision,
            $fullContent
        );

        // 保存层独立兜底：无论上游来自流式写作、worker 还是其他调用方，
        // 明显截断的正文都不能进入任何落盘状态。该规则不受“严格模式”开关影响。
        // B1 修订（2026-08-17 写作逻辑）：本层只拦「疑似截断」（<30% 绝对下限）；
        // 30%～承诺区间下限的短章允许落盘，但由 shortChapterFailureReason 标
        // failed，不再 silently completed——弱模型书可以保全正文供人工重写。
        $words = countWords($fullContent);
        $hardMinWords = $policySnapshot->hardMinWords();

        // Persist the cleaned model output before any quality decision. This
        // includes empty and obviously truncated attempts: they are audit
        // evidence and must be quarantined instead of disappearing in an
        // early validation exception.
        $claimToken = (string)($chapter['claim_token'] ?? '');
        $baseSnapshot = self::validateChapterBaseSnapshot(
            $chapter['_base_snapshot'] ?? [],
            $claimToken
        );
        $owner = DB::fetch('SELECT user_id FROM novels WHERE id=? LIMIT 1', [$novelId]);
        $userId = (int)($owner['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new WriteEnginePersistenceException(
                'Unable to resolve the Candidate owner for this novel.'
            );
        }
        $sourceTaskId = $chapter['_task_id'] ?? null;
        $sourceTaskId = is_string($sourceTaskId) && $sourceTaskId !== ''
            ? $sourceTaskId
            : null;
        $taskLeaseOwner = $chapter['_task_lease_owner'] ?? null;
        $taskLeaseToken = $chapter['_task_lease_token'] ?? null;
        $taskFenceGeneration = $chapter['_task_fence_generation'] ?? null;
        if ($sourceTaskId !== null) {
            if (!is_string($taskLeaseOwner) || trim($taskLeaseOwner) === ''
                || !is_string($taskLeaseToken)
                || preg_match('/^[a-f0-9]{32}$/D', $taskLeaseToken) !== 1
                || (!is_int($taskFenceGeneration)
                    && !(is_string($taskFenceGeneration)
                        && preg_match('/^[1-9][0-9]*$/D', $taskFenceGeneration) === 1))
                || (int)$taskFenceGeneration <= 0) {
                throw new WriteEnginePersistenceException(
                    'Durable writing task is missing its lease fence.'
                );
            }
            $taskLeaseOwner = trim($taskLeaseOwner);
            $taskFenceGeneration = (int)$taskFenceGeneration;
        } elseif ($taskLeaseOwner !== null
            || $taskLeaseToken !== null
            || $taskFenceGeneration !== null) {
            throw new InvalidArgumentException(
                'A writing-task lease fence cannot be supplied without a task id.'
            );
        }
        $sourceRunUid = $chapter['_run_uid'] ?? null;
        if ($sourceRunUid === null && $sourceTaskId !== null) {
            $sourceRunUid = $taskLeaseToken;
        }
        $sourceRunUid = is_string($sourceRunUid)
            && preg_match('/^[a-f0-9]{32}$/D', $sourceRunUid) === 1
                ? $sourceRunUid
                : null;
        // P2 authority carrier. Legacy/SSE/daemon calls omit every field and
        // retain their exact pre-Harness behavior. A partially supplied fence
        // is never downgraded to a legacy commit.
        $sourceRunId = $chapter['_run_id'] ?? null;
        $runLeaseOwner = $chapter['_run_lease_owner'] ?? null;
        $runLeaseToken = $chapter['_run_lease_token'] ?? null;
        $runFenceGeneration = $chapter['_run_fence_generation'] ?? null;
        $hasAnyRunFence = $sourceRunId !== null
            || $runLeaseOwner !== null
            || $runLeaseToken !== null
            || $runFenceGeneration !== null;
        if ($hasAnyRunFence) {
            if ((!is_int($sourceRunId)
                    && !(is_string($sourceRunId)
                        && preg_match('/^[1-9][0-9]*$/D', $sourceRunId) === 1))
                || (int)$sourceRunId <= 0
                || $sourceRunUid === null
                || !is_string($runLeaseOwner)
                || preg_match('/^[a-zA-Z0-9_.:@-]{1,120}$/D', $runLeaseOwner) !== 1
                || !is_string($runLeaseToken)
                || preg_match('/^[a-f0-9]{32}$/D', $runLeaseToken) !== 1
                || (!is_int($runFenceGeneration)
                    && !(is_string($runFenceGeneration)
                        && preg_match('/^[1-9][0-9]*$/D', $runFenceGeneration) === 1))
                || (int)$runFenceGeneration <= 0) {
                throw new WriteEnginePersistenceException(
                    'Harness authority commit is missing an exact Run lease fence.'
                );
            }
            $sourceRunId = (int)$sourceRunId;
            $runFenceGeneration = (int)$runFenceGeneration;
        } else {
            $sourceRunId = null;
            $runLeaseOwner = null;
            $runLeaseToken = null;
            $runFenceGeneration = null;
        }
        $artifactTaskLease = $sourceTaskId === null
            ? null
            : [
                'lease_owner' => $taskLeaseOwner,
                'lease_token' => $taskLeaseToken,
                'fence_generation' => $taskFenceGeneration,
            ];
        self::captureLegacyDraftArtifact(
            $sourceTaskId,
            $policySnapshot,
            $artifactTaskLease,
            $novelId,
            $chId,
            $fullContent
        );
        $candidateContentHash = hash('sha256', $fullContent);
        $candidateAttemptUid = $sourceRunId === null
            ? HarnessRuntime::randomHex(16)
            : substr(hash(
                'sha256',
                implode("\0", [
                    'authority-candidate-v1',
                    (string)$sourceRunUid,
                    $candidateContentHash,
                    (string)($baseSnapshot['content_revision_hash'] ?? 'none'),
                ])
            ), 0, 32);
        try {
            $candidate = ChapterCandidateRepository::createDraft([
                'candidate_uid' => $candidateAttemptUid,
                'user_id' => $userId,
                'novel_id' => $novelId,
                'chapter_id' => $chId,
                'source' => ChapterCandidateRepository::SOURCE_INITIAL,
                'source_run_uid' => $sourceRunUid,
                'source_task_id' => $sourceTaskId,
                'parent_candidate_uid' => null,
                'base_revision_hash' => $baseSnapshot['content_revision_hash'],
                'base_snapshot_json' => $baseSnapshot,
                'inline_content' => $fullContent,
                'content_hash' => $candidateContentHash,
                'words' => $words,
            ], $policySnapshot);
        } catch (Throwable $exception) {
            throw new WriteEnginePersistenceException(
                'Unable to persist the pre-Canon chapter Candidate: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        $candidateUid = (string)($candidate['candidate_uid'] ?? '');
        if ($sourceRunId !== null) {
            try {
                (new M63CandidateProjectionBridge())->bindDraft(
                    $sourceRunId,
                    (string)$sourceRunUid,
                    (int)$runFenceGeneration,
                    $userId,
                    $novelId,
                    $chId,
                    $candidateUid,
                    $fullContent
                );
                $candidate = ChapterCandidateRepository::findByUid($candidateUid) ?? $candidate;
            } catch (Throwable $artifactError) {
                throw new WriteEnginePersistenceException(
                    'Authority Candidate Draft Artifact binding failed.',
                    0,
                    $artifactError
                );
            }
        }

        // 旧版本备份将在最终数据库事务内执行，确保备份、正文、统计和后处理
        // 失效标记共享同一个提交边界。

        // === 标题禁用词「落盘前自动改写」（根治：标题P0阻断落盘→挂机死锁）===
        // 必须在后置校验之前执行，使校验看到的是已清洗的标题，从根上避免 P0 阻断。
        $titleAutoFixed = false;
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/TitleSanitizer.php';
            $frozenConstraintEnabled = $policySnapshot->constraintEnabled();
            $frozenBannedWords = $policySnapshot->constraintBannedWords();
            if ($frozenConstraintEnabled === true && is_array($frozenBannedWords)) {
                $san = TitleSanitizer::sanitize(
                    (string)($ch['title'] ?? ''),
                    (int)($ch['chapter_number'] ?? 0),
                    $frozenBannedWords
                );
                if ($san['changed']) {
                    $oldTitle       = (string)$ch['title'];
                    $ch['title']    = $san['title'];
                    $titleAutoFixed = true;
                    addLog($novelId, 'info', sprintf(
                        '第%d章标题含禁用词「%s」，已自动改写：《%s》→《%s》（不再阻断落盘）',
                        (int)$ch['chapter_number'],
                        implode('、', $san['hit_words']),
                        $oldTitle,
                        $ch['title']
                    ), $chId);
                }
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '标题自动改写跳过：' . $e->getMessage());
        }

        // === 约束框架后置校验 ===
        // Validators produce Candidate findings only. CanonicalQualityGate is
        // the single place that applies the frozen strict/warn disposition.
        $validationResult = null;
        $hardFindings = ChapterGenerationIntegrity::findings($usedModel?->lastFinishReason);
        $softWarnings = [];
        $urgentMsg = null;
        if ($preCanonReviewDecision !== null
            && $preCanonReviewDecision['commit_allowed'] === false) {
            $hardFindings[] = [
                'rule_id' => 'review.blocked',
                'message' => '生成后独立评审未允许候选稿进入 Canon。',
                'evidence' => [
                    'result_uid' => $preCanonReviewDecision['result_uid'],
                    'disposition' => $preCanonReviewDecision['disposition'],
                    'raw_draft_hash' => $preCanonReviewDecision['raw_draft_hash'],
                    'selected_draft_hash' =>
                        $preCanonReviewDecision['selected_draft_hash'],
                    'round_count' => $preCanonReviewDecision['round_count'],
                ],
            ];
        }
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/ConstraintStateDB.php';
            require_once __DIR__ . '/constraints/PostWriteValidator.php';
            $validator = new PostWriteValidator(
                $novelId,
                $ch,
                $fullContent,
                $policySnapshot->targetWords(),
                $policySnapshot
            );
            $validationResult = $validator->run();
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '约束后置校验跳过：' . $e->getMessage());
            $constraintUnavailable = [
                'rule_id' => $policySnapshot->constraintStrict()
                    ? 'constraint.p0'
                    : 'constraint.validator_unavailable',
                'message' => '约束后置校验不可用：' . $e->getMessage(),
                'evidence' => ['exception' => get_class($e)],
            ];
            if ($policySnapshot->constraintStrict()) {
                $hardFindings[] = $constraintUnavailable;
            } else {
                $softWarnings[] = $constraintUnavailable;
            }
        }

        if ($validationResult && !empty($validationResult['has_p0'])) {
            $urgentIssues = [];
            $recordedP0Finding = false;
            foreach ((array)($validationResult['p0_issues'] ?? []) as $p0) {
                $issue = trim((string)($p0['issue_desc'] ?? ''));
                if ($issue === '') {
                    continue;
                }
                $recordedP0Finding = true;
                $urgentIssues[] = $issue;
                $hardFindings[] = [
                    'rule_id' => 'constraint.p0',
                    'message' => $issue,
                    'evidence' => ['validator_issue' => $p0],
                ];
            }
            if (!$recordedP0Finding) {
                // has_p0 without a usable issue violates the validator
                // contract. Strict constraint policy must fail closed instead
                // of silently accepting an unexplainable P0 result.
                $hardFindings[] = [
                    'rule_id' => 'constraint.p0',
                    'message' => '约束校验报告 P0，但未返回可审计的问题明细。',
                    'evidence' => ['validator_contract_error' => 'missing_p0_issue'],
                ];
                $urgentIssues[] = '约束校验报告了未附明细的 P0 问题';
            }
            if ($urgentIssues !== []) {
                $urgentMsg = "【紧急修复】上章发生严重问题：" . implode('；', $urgentIssues)
                    . "。本章必须立即修正，避免问题延续。";
            }
        }
        if ($validationResult && !empty($validationResult['has_p1'])) {
            $p1Issues = (array)($validationResult['p1_issues'] ?? []);
            foreach ($p1Issues as $p1) {
                $issue = trim((string)($p1['issue_desc'] ?? ''));
                if ($issue === '') {
                    continue;
                }
                $softWarnings[] = [
                    'rule_id' => 'constraint.p1',
                    'message' => $issue,
                    'evidence' => ['validator_issue' => $p1],
                ];
            }
            $p1Count = count($p1Issues);
            addLog($novelId, 'warn', "第{$ch['chapter_number']}章触发{$p1Count}项P1约束");
        }

        $gateCh = $ch;
        $gateCh['novel_id'] = $novelId;
        $gateCh['chapter_words'] = $targetWords;
        $qualityFindings = [
            'hard_findings' => [],
            'evaluator_error' => null,
        ];
        if ($policySnapshot->qualityCheckEnabled()) {
            $qualityFindings = self::collectCanonicalQualityFindings(
                $novelId,
                $gateCh,
                $fullContent
            );
        }
        $hardFindings = array_merge(
            $hardFindings,
            $qualityFindings['hard_findings']
        );

        $decision = CanonicalQualityGate::evaluate(
            $policySnapshot,
            $fullContent,
            $words,
            $hardFindings,
            $softWarnings,
            $qualityFindings['evaluator_error']
        );
        $qualityBlock = self::qualityDecisionReason($decision);
        self::captureLegacyQualityArtifacts(
            $sourceTaskId,
            $policySnapshot,
            $artifactTaskLease,
            $novelId,
            $chId,
            $decision,
            [
                'content_hash' => $decision->contentHash(),
                'words' => $words,
                'target_words' => $targetWords,
                'hard_min_words' => $hardMinWords,
                'disposition' => $decision->disposition(),
                'would_quarantine' => $decision->wouldQuarantine(),
                'should_quarantine' => $decision->shouldQuarantine(),
                'hard_issue_count' => count($decision->hardIssues()),
                'soft_warning_count' => count($decision->softWarnings()),
                'constraint' => [
                    'validator_completed' => $validationResult !== null,
                    'has_p0' => !empty($validationResult['has_p0']),
                    'has_p1' => !empty($validationResult['has_p1']),
                    'p0_issue_count' => count((array)($validationResult['p0_issues'] ?? [])),
                    'p1_issue_count' => count((array)($validationResult['p1_issues'] ?? [])),
                ],
                'quality_evaluator' => [
                    'enabled' => $policySnapshot->qualityCheckEnabled(),
                    'completed' => $qualityFindings['evaluator_error'] === null,
                ],
            ]
        );
        if ($sourceRunId !== null) {
            try {
                (new M63CandidateProjectionBridge())->bindValidation(
                    $sourceRunId,
                    (string)$sourceRunUid,
                    (int)$runFenceGeneration,
                    $userId,
                    $novelId,
                    $chId,
                    $candidateUid,
                    $decision
                );
            } catch (Throwable $artifactError) {
                throw new WriteEnginePersistenceException(
                    'Authority Candidate Validation Artifact binding failed.',
                    0,
                    $artifactError
                );
            }
        }

        $updates = [
            'retry_count' => 0,
        ];
        // 审计修复（2026-08-17 写作逻辑 B0）：质检失败原因写入 chapters.gate_results
        // 的 hard_fail_reason 条目，主界面失败章直接展示原因；成功落盘时剔除历史
        // 失败标记，防止上一轮 failed 残留误导 UI（无变化时返回 null 不覆写）。
        // Candidate findings live in validation_json. The chapter-level
        // hard_fail marker describes only the current Canon, so an accepted
        // Candidate clears stale legacy markers instead of projecting soft
        // warnings as a hard failure. Quarantined Candidates never execute the
        // metadata callback below and therefore cannot mutate the old Canon.
        $stampedGates = self::stampHardFailGateResults($chId, '');
        if ($stampedGates !== null) {
            $updates['gate_results'] = $stampedGates;
        }
        // 标题被自动改写时，连同新标题一起落盘（否则恢复后仍是旧标题，死锁复现）
        if ($titleAutoFixed) {
            $updates['title'] = (string)$ch['title'];
        }
        // v1.4: 落盘 token 用量和耗时数据，为 OptimizationAgent 提供真实数据基础
        if ($usage !== null && isset($usage['total_tokens'])) {
            $updates['tokens_used'] = (int)$usage['total_tokens'];
        }
        // v41: 落盘提示词缓存命中 token（1000章优化埋点）
        if ($usage !== null && isset($usage['cache_hit_tokens'])) {
            $updates['cache_hit_tokens'] = (int)$usage['cache_hit_tokens'];
            $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
            if ($promptTokens > 0 && $usage['cache_hit_tokens'] > 0) {
                $hitPct = round($usage['cache_hit_tokens'] / $promptTokens * 100, 1);
                addLog($novelId, 'info', "提示词缓存命中：{$usage['cache_hit_tokens']}/{$promptTokens} tokens（{$hitPct}%）", $chId);
            }
        }
        if ($durationMs !== null) {
            $updates['duration_ms'] = $durationMs;
        }
        // v1.5: 落盘 hook_type，激活 suggestHookType 的"防连续重复"机制
        // 之前该字段从未被写入，导致防重复逻辑形同虚设
        // 审计修复 A02（2026-06-16）：移除防御性 try/catch
        // suggestHookType 内部仅做正则与数组操作，不会抛 RuntimeException
        // 审计修复（2026-08-09 R9 / R8-1）：优先按正文尾段分类实写钩子。
        // null 时仅回退「大纲关键词高置信」结果；禁止用默认轮换污染防重复历史。
        // R9-7：无可靠分类时清空陈旧 hook_type，避免历史类型永久锁死。
        $actualHookType = classifyHookTypeFromText($fullContent);
        if ($actualHookType !== null) {
            $updates['hook_type'] = $actualHookType;
        } else {
            $hookSuggestion = suggestHookType($ch);
            $reason = (string)($hookSuggestion['reason'] ?? '');
            if (!empty($hookSuggestion['type']) && strpos($reason, '默认轮换') === false) {
                $updates['hook_type'] = $hookSuggestion['type'];
            } else {
                $updates['hook_type'] = null;
            }
        }
        // v1.7: 落盘 opening_type，与 hook_type 同模式（R9-4：禁止默认轮换落盘）
        // R10-7：无可靠匹配时清空陈旧 opening_type，避免污染防重复
        $openingSuggestion = suggestOpeningType($ch);
        $openingReason = (string)($openingSuggestion['reason'] ?? '');
        if (!empty($openingSuggestion['type']) && strpos($openingReason, '默认轮换') === false) {
            $updates['opening_type'] = $openingSuggestion['type'];
        } else {
            $updates['opening_type'] = null;
        }
        self::assertCanonMetadataUpdates($updates);
        $pendingCount = 0;
        $postprocessJobId = 0;
        $nextCh = null;

        try {
            // Recheck after all validators, before either the allow or
            // quarantine transaction. The accepted path repeats this check
            // under the transition lock in its commit callback.
            if (file_exists(self::cancelFlagPath($novelId))) {
                throw new WriteEngineCanceledException('canceled');
            }
            $claimTaskId = $chapter['_task_id'] ?? null;
            if ($claimTaskId !== null && $claimTaskId !== '') {
                $taskRow = DB::fetch(
                    'SELECT cancel_requested FROM writing_tasks
                     WHERE task_id=? AND lease_owner=? AND lease_token=?
                       AND fence_generation=? AND lease_expires_at IS NOT NULL
                       AND lease_expires_at>=? LIMIT 1',
                    [
                        $claimTaskId,
                        $taskLeaseOwner,
                        $taskLeaseToken,
                        $taskFenceGeneration,
                        HarnessRuntime::sqlNow(),
                    ]
                );
                if (!$taskRow) {
                    throw new WriteEnginePersistenceException(
                        'Writing task lease fence was lost before Candidate submission.'
                    );
                }
                if ((int)$taskRow['cancel_requested'] === 1) {
                    throw new WriteEngineCanceledException('canceled');
                }
            }

            $commitService = new CanonicalCommitService();
            $transitionResult = $commitService->commitCandidate(
                $userId,
                $novelId,
                $chId,
                $candidateUid,
                $claimToken,
                $policySnapshot,
                $decision,
                static function (array $context) use (
                    $chId,
                    $novelId,
                    $chapter,
                    $claimToken,
                    $sourceTaskId,
                    $taskLeaseOwner,
                    $taskLeaseToken,
                    $taskFenceGeneration,
                    $sourceRunId,
                    $sourceRunUid,
                    $runLeaseOwner,
                    $runLeaseToken,
                    $runFenceGeneration,
                    $baseSnapshot,
                    $updates,
                    $words,
                    $urgentMsg,
                    $ch,
                    $policySnapshot,
                    &$pendingCount,
                    &$postprocessJobId
                ): void {
                    // The transition owns this transaction. Backup and all
                    // accepted-only projections happen while the old locked
                    // chapter is still intact, immediately before promotion.
                    if (file_exists(self::cancelFlagPath($novelId))) {
                        throw new WriteEngineCanceledException('canceled');
                    }
                    $claimTaskId = $chapter['_task_id'] ?? null;
                    if ($claimTaskId !== null && $claimTaskId !== '') {
                        $taskRow = DB::fetch(
                            'SELECT cancel_requested FROM writing_tasks
                             WHERE task_id=? AND lease_owner=? AND lease_token=?
                               AND fence_generation=? AND lease_expires_at IS NOT NULL
                               AND lease_expires_at>=? LIMIT 1
                             FOR UPDATE',
                            [
                                $claimTaskId,
                                $taskLeaseOwner,
                                $taskLeaseToken,
                                $taskFenceGeneration,
                                HarnessRuntime::sqlNow(),
                            ]
                        );
                        if (!$taskRow) {
                            throw new WriteEnginePersistenceException(
                                'Writing task lease fence was lost before Canon promotion.'
                            );
                        }
                        if ((int)$taskRow['cancel_requested'] === 1) {
                            throw new WriteEngineCanceledException('canceled');
                        }
                    }

                    // Authority commits bind the still-live M62 Run fence to
                    // this exact Canon transaction. Cancellation or takeover
                    // can therefore never race a stale worker into the ledger.
                    if ($sourceRunId !== null) {
                        $runRow = DB::fetch(
                            "SELECT id, run_uid FROM generation_runs
                             WHERE id=? AND run_uid=? AND user_id=?
                               AND novel_id=? AND chapter_id=?
                               AND queue='chapter' AND run_type='chapter.generate'
                               AND workflow_name='chapter_generate'
                               AND workflow_version='v1'
                               AND authority_mode='authority' AND state='running'
                               AND cancel_requested=0
                               AND lease_owner=? AND lease_token=?
                               AND fence_generation=?
                               AND lease_expires_at IS NOT NULL
                               AND lease_expires_at>=UTC_TIMESTAMP(6)
                             LIMIT 1 FOR UPDATE",
                            [
                                $sourceRunId,
                                $sourceRunUid,
                                (int)($context['candidate']['user_id'] ?? 0),
                                $novelId,
                                $chId,
                                $runLeaseOwner,
                                $runLeaseToken,
                                $runFenceGeneration,
                            ]
                        );
                        if (!is_array($runRow)) {
                            throw new WriteEnginePersistenceException(
                                'Harness authority Run lease was lost before Canon promotion.'
                            );
                        }
                    }

                    // Back up only after both cancellation sources have been
                    // rechecked, while the locked old chapter is still intact.
                    backupChapterVersion($chId, true);

                    DB::update(
                        'chapters',
                        $updates,
                        'id=? AND status="writing" AND novel_id=? AND claim_token=?',
                        [$chId, $novelId, $claimToken]
                    );
                    DB::delete(
                        'writing_logs',
                        'novel_id=? AND chapter_id=? AND action="memory_finalized"',
                        [$novelId, $chId]
                    );
                    $statsAffected = DB::execute(
                        'UPDATE novels
                         SET current_chapter=GREATEST(0, current_chapter + 1),
                             total_words=GREATEST(0, total_words + ?)
                         WHERE id=?',
                        [$words, $novelId]
                    );
                    if ($statsAffected !== 1) {
                        throw new WriteEnginePersistenceException(
                            'Candidate commit could not update novel statistics.'
                        );
                    }

                    if ($sourceTaskId !== null) {
                        $acceptedCandidateUid = (string)($context['result']['candidate_uid'] ?? '');
                        $taskAffected = DB::execute(
                            "UPDATE writing_tasks
                             SET accepted_candidate_uid=?
                             WHERE task_id=? AND novel_id=? AND chapter_id=?
                               AND cancel_requested=0
                               AND lease_owner=? AND lease_token=? AND fence_generation=?
                               AND lease_expires_at IS NOT NULL AND lease_expires_at>=?
                               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
                            [
                                $acceptedCandidateUid,
                                $sourceTaskId,
                                $novelId,
                                $chId,
                                $taskLeaseOwner,
                                $taskLeaseToken,
                                $taskFenceGeneration,
                                HarnessRuntime::sqlNow(),
                            ]
                        );
                        if ($taskAffected !== 1) {
                            throw new WriteEnginePersistenceException(
                                'Candidate commit lost its active writing task fence.'
                            );
                        }
                    }

                    // The durable post-processing intent is part of the same
                    // atomic boundary as the first Canon promotion. A process
                    // crash immediately after commit can therefore only leave
                    // a queued/retryable job, never an accepted Canon with no
                    // recovery work recorded. Every Canon generation owns a
                    // distinct immutable job identity, even when two attempts
                    // produce byte-identical content. Rewrite eligibility is
                    // an explicit bit; Candidate NULL is reserved for legacy.
                    require_once __DIR__ . '/tasks/PostprocessJobRepository.php';
                    $revisionHash = (string)($context['result']['revision_hash'] ?? '');
                    $acceptedCandidateUid = (string)($context['result']['candidate_uid'] ?? '');
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    AgentDirectives::bindContentVersion($novelId, $chId, $claimToken,
                        $acceptedCandidateUid, $revisionHash, (int)$chapter['chapter_number']);
                    $postprocessJobId = PostprocessJobRepository::enqueue(
                        $novelId,
                        $chId,
                        $revisionHash,
                        'full',
                        3,
                        $policySnapshot,
                        $acceptedCandidateUid,
                        true
                    );
                    if ($postprocessJobId <= 0) {
                        throw new WriteEnginePersistenceException(
                            'Candidate commit could not persist its post-processing job.'
                        );
                    }

                    if ($sourceRunId !== null) {
                        require_once __DIR__
                            . '/harness/persistence/ChapterCommitLedgerRepository.php';
                        $committedHash = (string)($context['result']['revision_hash'] ?? '');
                        try {
                            (new ChapterCommitLedgerRepository(DB::getPdo()))
                                ->recordIdempotent(new ChapterCommitDraft(
                                    $sourceRunId,
                                    $chId,
                                    hash('sha256', (string)$context['result']['accepted_content']),
                                    is_string($baseSnapshot['content_revision_hash'] ?? null)
                                        ? $baseSnapshot['content_revision_hash']
                                        : null,
                                    $committedHash,
                                    $postprocessJobId,
                                    (int)($context['candidate']['id'] ?? 0) ?: null
                                ));
                        } catch (Throwable $ledgerError) {
                            throw new WriteEnginePersistenceException(
                                'Harness authority commit ledger could not be recorded.',
                                0,
                                $ledgerError
                            );
                        }
                    }

                    // Promotion follows this callback in the same transaction,
                    // so exclude the locked current row from the pending count.
                    $pendingCount = DB::count(
                        'chapters',
                        'novel_id=? AND id<>? AND status != "completed"',
                        [$novelId, $chId]
                    );
                    if ($pendingCount === 0) {
                        DB::update('novels', ['status' => 'completed'], 'id=?', [$novelId]);
                    }

                    // A quarantined P0 must not influence the next chapter.
                    // The callback only runs for an accepted Candidate.
                    if ($urgentMsg !== null) {
                        require_once __DIR__ . '/agents/AgentDirectives.php';
                        if (!AgentDirectives::add(
                            $novelId,
                            (int)$ch['chapter_number'] + 1,
                            'urgent',
                            $urgentMsg,
                            1,
                            24
                        )) {
                            throw new WriteEnginePersistenceException(
                                'Accepted P0 Candidate could not persist its urgent directive.'
                            );
                        }
                        addLog($novelId, 'warn', sprintf(
                            '紧急通道触发：第%d章P0问题已写紧急指令',
                            (int)$ch['chapter_number']
                        ));
                    }
                }
            );
        } catch (\Throwable $e) {
            if ($e instanceof WriteEngineCanceledException) {
                // Cancellation remains a typed protocol signal consumed by
                // daemon/SSE/Workflow adapters; never wrap or translate it.
                throw $e;
            }
            if ($e instanceof ChapterCanonTransitionException) {
                $transitionMessage = $e->getMessage();
                if (str_contains($transitionMessage, 'Chapter is no longer')
                    || str_contains($transitionMessage, 'chapter claim token mismatch')
                    || str_contains($transitionMessage, 'Current chapter Canon')
                    || str_contains($transitionMessage, 'Current chapter content')
                    || str_contains($transitionMessage, 'Canon promotion CAS failed')) {
                    throw new WriteEnginePersistenceException(
                        '写作已被中断（章节状态已变更）',
                        0,
                        $e
                    );
                }
            }
            if ($e instanceof WriteEnginePersistenceException) {
                throw $e;
            }
            throw new WriteEnginePersistenceException(
                'Candidate to Canon transaction failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!empty($transitionResult['quarantined'])) {
            if ($sourceRunId !== null) {
                try {
                    $quarantinedCandidate = ChapterCandidateRepository::findByUid($candidateUid);
                    if (!is_array($quarantinedCandidate)
                        || (int)($quarantinedCandidate['run_id'] ?? 0) !== $sourceRunId) {
                        throw new RuntimeException('Quarantined Candidate lost its Run provenance.');
                    }
                    $approvalUid = substr(hash(
                        'sha256',
                        'approval-force-commit-v1:' . $candidateUid
                    ), 0, 32);
                    (new ApprovalRequestRepository(DB::getPdo()))->requestIdempotent(
                        new ApprovalRequestDraft(
                            $approvalUid,
                            $userId,
                            $novelId,
                            $chId,
                            $sourceRunId,
                            (int)$quarantinedCandidate['id'],
                            'force-commit-' . $candidateUid,
                            ApprovalActionType::FORCE_COMMIT,
                            ApprovalRiskLevel::CRITICAL,
                            is_string($baseSnapshot['content_revision_hash'] ?? null)
                                ? $baseSnapshot['content_revision_hash']
                                : null,
                            $policySnapshot->toArray(),
                            null,
                            'quality-gate',
                            new DateTimeImmutable('+7 days', new DateTimeZone('UTC'))
                        )
                    );
                } catch (Throwable $approvalError) {
                    throw new WriteEnginePersistenceException(
                        'Quarantined authority Candidate could not create its approval request.',
                        0,
                        $approvalError
                    );
                }
            }
            try {
                addLog(
                    $novelId,
                    'error',
                    "第{$ch['chapter_number']}章 Candidate 已隔离，未进入 Canon：{$qualityBlock}",
                    $chId
                );
                clearChapterCache($chId, $novelId);
            } catch (Throwable $logError) {
                error_log('saveChapter quarantine log failed: ' . $logError->getMessage());
            }
            throw new WriteEngineValidationException(
                $qualityBlock !== ''
                    ? $qualityBlock
                    : 'Chapter Candidate was quarantined and did not enter Canon.'
            );
        }

        $acceptedContent = (string)$transitionResult['accepted_content'];
        $revisionHash = (string)$transitionResult['revision_hash'];
        $words = countWords($acceptedContent);
        $canonicalRow = DB::fetch(
            'SELECT ' . self::chapterWriteSelectList(['retry_count'])
                . ' FROM chapters WHERE id=? AND novel_id=?'
                . ' AND status="completed" AND canonical_state="canonical"'
                . ' AND content_revision_hash=? AND canonical_candidate_uid=? LIMIT 1',
            [$chId, $novelId, $revisionHash, $candidateUid]
        );
        if (!is_array($canonicalRow)
            || !is_string($canonicalRow['content'] ?? null)
            || !hash_equals($acceptedContent, $canonicalRow['content'])) {
            throw new WriteEnginePersistenceException(
                'Committed Canon could not be read back authoritatively.'
            );
        }
        $acceptedContent = $canonicalRow['content'];
        $words = (int)($canonicalRow['words'] ?? $words);

        $nextAutoChapterId = self::findNextAutomaticChapterId($novelId);
        $nextCh = $nextAutoChapterId
            ? DB::fetch(
                'SELECT id, chapter_number FROM chapters WHERE id=? AND novel_id=?',
                [$nextAutoChapterId, $novelId]
            )
            : null;

        if ($qualityBlock !== '') {
            try {
                addLog(
                    $novelId,
                    'warn',
                    "第{$ch['chapter_number']}章质检警告，Candidate 已提升为 Canon 并继续自动写作：{$qualityBlock}",
                    $chId
                );
            } catch (Throwable $logError) {
                error_log('saveChapter quality warning log failed: ' . $logError->getMessage());
            }
        }

        try {
            clearChapterCache($chId, $novelId);
            clearNovelCache($novelId);
        } catch (\Throwable $cacheError) {
            error_log('saveChapter cache clear failed: ' . $cacheError->getMessage());
        }

        // v2: 标记本章「后处理进行中」——下一章 resolveChapter 会等待此标志清除，
        // 确保摘要/记忆/Agent指令落库后再构建下一章 prompt（消除记忆竞态）。
        try {
            @file_put_contents(
                self::postProcessPendingFlag($novelId, (int)$ch['chapter_number']),
                (string)HarnessRuntime::unixTime()
            );
        } catch (\Throwable $e) { /* 标志写入失败不影响落盘 */ }

        $modelInfo = $usedModel ? "（{$usedModel->modelLabel}）" : '';
        $wordDiff = $words - $targetWords;
        $wordDiffPct = $targetWords > 0 ? round(abs($wordDiff) / $targetWords * 100, 1) : 0;
        $diffMark = $wordDiff > 0 ? "+{$wordDiff}" : "{$wordDiff}";
        try {
            addLog($novelId, 'write',
                "完成第{$ch['chapter_number']}章《{$ch['title']}》，共{$words}字（目标{$targetWords}字，偏差{$diffMark}字/{$wordDiffPct}%）{$modelInfo}",
                $chId
            );
        } catch (\Throwable $logError) {
            error_log('saveChapter success log failed: ' . $logError->getMessage());
        }

        $acceptedChapter = array_merge($ch, $canonicalRow);

        return [
            'words'            => $words,
            'chapter'          => $acceptedChapter,
            'accepted_content' => $acceptedContent,
            'revision_hash'    => $revisionHash,
            'candidate_uid'    => $candidateUid,
            'postprocess_job_id' => $postprocessJobId,
            'policy_snapshot_hash' => $policySnapshot->checksum(),
            'all_done'         => $pendingCount === 0,
            'model_info'       => $modelInfo,
            'next_chapter_id'  => $nextCh['id'] ?? null,
            'next_chapter_num' => $nextCh['chapter_number'] ?? null,
            'quality_warning'  => $qualityBlock !== '',
            'quality_warning_reason' => $qualityBlock,
        ];
    }

    /**
     * 安全执行后处理任务，统一捕获异常并记录日志
     *
     * 审计修复 B02（2026-06-16）：替代 postProcess 中 20+ 个重复的
     * try { ... } catch (Throwable $e) { addLog(..., 'warn', '...跳过：' . $e->getMessage()); }
     * 模板，减少约 200 行冗余代码。
     *
     * @param int      $novelId 小说ID
     * @param string   $label   任务标签（用于日志，如"伏笔提及追踪"）
     * @param callable $fn      任务回调 fn(): void
     * @param string   $level   日志级别（默认 warn）
     */
    private static function safeRun(int $novelId, string $label, callable $fn, string $level = 'warn'): void
    {
        $stepStartedAt = HarnessRuntime::preciseTime();
        $hb = self::$postProcessHeartbeat;
        if ($hb !== null) {
            try {
                $hb();
            } catch (WriteEnginePostprocessLeaseLostException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new WriteEnginePostprocessLeaseLostException(
                    'Post-processing heartbeat failed before projection.',
                    0,
                    $e
                );
            }
        }
        try {
            $fn();
        } catch (WriteEnginePostprocessLeaseLostException $e) {
            throw $e;
        } catch (PostprocessPublicationFailedException $e) {
            // A durable computed result must not be stranded behind a full-run
            // completion marker. Retry the parent and reuse this exact result.
            throw $e;
        } catch (Throwable $e) {
            addLog($novelId, $level, $label . '跳过：' . $e->getMessage());
        }
        self::recordPostProcessTiming($novelId, $label, $stepStartedAt);
        // 审计修复 P1-8（2026-08-08）：每个后处理小节结束都触发一次租约心跳（闭包内节流）。
        // 后处理是分钟级同步长任务，job/task 租约若无续租会被 findActiveForNovel 的
        // 孤儿清扫误杀（active worker 被判 postprocess_orphaned → 双进程）。
        $hb = self::$postProcessHeartbeat;
        if ($hb !== null) {
            try {
                $hb();
            } catch (WriteEnginePostprocessLeaseLostException $e) {
                // A stale worker must stop before it can emit another
                // projection or promote a Rewrite Candidate under a lease now
                // owned by somebody else.
                throw $e;
            } catch (Throwable $e) {
                throw new WriteEnginePostprocessLeaseLostException(
                    'Post-processing heartbeat failed after projection.',
                    0,
                    $e
                );
            }
        }
    }

    /**
     * 审计修复 P1-8（2026-08-08）：后处理期间的租约续期回调（由 PostprocessRunner 注入）。
     * 仅在 postProcess 执行期间生效，finally 中恢复原值。
     * @var callable|null
     */
    private static $postProcessHeartbeat = null;

    /**
     * 后处理步骤耗时收集（性能观测）：label => 累计秒数。
     * 同标签多次执行（如重写后重跑质量六关）累加；汇总在 postProcess 的 finally 输出。
     * @var array<string,float>
     */
    private static $postProcessTimings = [];

    /**
     * 记录单个后处理步骤的耗时：累加到收集器，单步 ≥2s 时即时记一条 info 日志，
     * 便于写作过程中实时观察哪一步慢，而不必等整章后处理结束。
     */
    private static function recordPostProcessTiming(int $novelId, string $label, float $startTs): void
    {
        $elapsed = max(0.0, HarnessRuntime::preciseTime() - $startTs);
        self::$postProcessTimings[$label] = (self::$postProcessTimings[$label] ?? 0.0) + $elapsed;
        if ($elapsed >= 2.0) {
            addLog($novelId, 'info', sprintf('后处理步骤耗时：%s %.1fs', $label, $elapsed));
        }
    }

    /**
     * Evaluate a rewrite draft with the same immutable Canon policy used by the
     * initial write path. Validators only produce findings here; no chapter or
     * projection is mutated before ChapterCanonTransition owns the decision.
     */
    private static function evaluateRewriteCandidate(
        int $novelId,
        array $chapter,
        string $content,
        WritePolicySnapshot $policySnapshot,
        array $generationIntegrity = []
    ): QualityGateDecision {
        $hardFindings = ChapterGenerationIntegrity::findings(
            $generationIntegrity['finish_reason'] ?? null,
            $generationIntegrity['generation_error'] ?? null
        );
        $softWarnings = [];
        $evaluatorErrors = [];

        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/ConstraintStateDB.php';
            require_once __DIR__ . '/constraints/PostWriteValidator.php';
            $validator = new PostWriteValidator(
                $novelId,
                $chapter,
                $content,
                $policySnapshot->targetWords(),
                $policySnapshot
            );
            $validation = $validator->run();
            $validP0Findings = 0;
            foreach ((array)($validation['p0_issues'] ?? []) as $issue) {
                $message = is_array($issue)
                    ? trim((string)($issue['issue_desc'] ?? ''))
                    : '';
                if ($message !== '') {
                    $hardFindings[] = [
                        'rule_id' => 'constraint.p0',
                        'message' => $message,
                        'evidence' => ['validator_issue' => $issue],
                    ];
                    $validP0Findings++;
                }
            }
            if (!empty($validation['has_p0']) && $validP0Findings === 0) {
                $hardFindings[] = [
                    // Keep the canonical P0 rule id so the pure gate treats
                    // this malformed validator contract as always-block.
                    'rule_id' => 'constraint.p0',
                    'message' => 'Constraint validator reported has_p0 without a valid P0 issue.',
                    'evidence' => [
                        'contract_error' => true,
                        'validator_result' => $validation,
                    ],
                ];
            }
            foreach ((array)($validation['p1_issues'] ?? []) as $issue) {
                $message = is_array($issue)
                    ? trim((string)($issue['issue_desc'] ?? ''))
                    : '';
                if ($message !== '') {
                    $softWarnings[] = [
                        'rule_id' => 'constraint.p1',
                        'message' => $message,
                        'evidence' => ['validator_issue' => $issue],
                    ];
                }
            }
        } catch (Throwable $exception) {
            if ($policySnapshot->constraintStrict()) {
                $hardFindings[] = [
                    'rule_id' => 'constraint.p0',
                    'message' => 'Constraint validation is unavailable: ' . $exception->getMessage(),
                    'evidence' => ['exception' => get_class($exception)],
                ];
            } else {
                $softWarnings[] = [
                    'rule_id' => 'constraint.validator_unavailable',
                    'message' => 'Constraint validation is unavailable: ' . $exception->getMessage(),
                    'evidence' => ['exception' => get_class($exception)],
                ];
            }
        }

        if ($policySnapshot->qualityCheckEnabled()) {
            $quality = self::collectCanonicalQualityFindings($novelId, $chapter, $content);
            $hardFindings = array_merge($hardFindings, $quality['hard_findings']);
            if (is_string($quality['evaluator_error']) && $quality['evaluator_error'] !== '') {
                $evaluatorErrors[] = $quality['evaluator_error'];
            }
        }

        return CanonicalQualityGate::evaluate(
            $policySnapshot,
            $content,
            countWords($content),
            $hardFindings,
            $softWarnings,
            $evaluatorErrors === [] ? null : implode('; ', $evaluatorErrors)
        );
    }

    private static function deterministicRewriteCandidateUid(
        int $jobId,
        int $chapterId,
        string $baseRevisionHash,
        string $parentCandidateUid,
        string $contentHash
    ): string {
        return substr(hash(
            'sha256',
            implode("\0", [
                'postprocess_rewrite_candidate_v1',
                (string)$jobId,
                (string)$chapterId,
                $baseRevisionHash,
                $parentCandidateUid,
                $contentHash,
            ])
        ), 0, 32);
    }

    /** @param array<string,mixed> $baseChapter */
    private static function claimCompletedCanonForRewrite(
        int $novelId,
        int $chapterId,
        array $baseChapter,
        string $claimToken
    ): bool {
        return DB::execute(
            "UPDATE chapters
             SET status='writing', claim_token=?
             WHERE id=? AND novel_id=? AND status='completed' AND claim_token=''
               AND canonical_state='canonical' AND content_revision_hash=?
               AND canonical_candidate_uid=? AND content=?
               AND write_policy_snapshot_json=?",
            [
                $claimToken,
                $chapterId,
                $novelId,
                $baseChapter['content_revision_hash'],
                $baseChapter['canonical_candidate_uid'],
                $baseChapter['content'],
                $baseChapter['write_policy_snapshot_json'],
            ]
        ) === 1;
    }

    /**
     * Atomically release this exact claim and reconcile the novel lifecycle.
     *
     * The projection fence closes the former A-release -> B-claim -> A-pause
     * window. The novel is changed only after the chapter CAS succeeds and
     * only when no writing claim remains for the book.
     */
    public static function releaseChapterClaimAndSetNovelStatus(
        int $novelId,
        int $chapterId,
        array $chapter,
        string $novelStatus,
        bool $disableDaemon = false
    ): bool {
        if ($novelId <= 0 || $chapterId <= 0) return false;
        if (!in_array($novelStatus, ['draft', 'paused'], true)) {
            throw new InvalidArgumentException('Invalid released-claim novel status.');
        }

        $pdo = DB::connect();
        if ($pdo->inTransaction()) {
            throw new LogicException('Released-claim novel reconciliation requires its own transaction.');
        }
        $projectionFence = NovelProjectionFence::acquire($novelId, 30);
        $startedTransaction = false;
        try {
            if (!$pdo->beginTransaction()) {
                throw new RuntimeException('Could not start released-claim reconciliation.');
            }
            $startedTransaction = true;

            // Global row order: exact chapter -> novel. The chapter read also
            // proves that a mismatched novel id can never authorize the CAS.
            $lockedChapter = DB::fetch(
                'SELECT id FROM chapters WHERE id=? AND novel_id=? FOR UPDATE',
                [$chapterId, $novelId]
            );
            if (!is_array($lockedChapter)
                || !self::releaseChapterClaim($chapterId, $chapter)) {
                $pdo->rollBack();
                $startedTransaction = false;
                return false;
            }

            $lockedNovel = DB::fetch(
                'SELECT id FROM novels WHERE id=? FOR UPDATE',
                [$novelId]
            );
            if (!is_array($lockedNovel)) {
                throw new RuntimeException('Novel disappeared during released-claim reconciliation.');
            }
            $remainingWriting = (int)DB::fetchColumn(
                'SELECT COUNT(*) FROM chapters WHERE novel_id=? AND status="writing"',
                [$novelId]
            );
            if ($remainingWriting === 0) {
                $updates = ['status' => $novelStatus];
                if ($disableDaemon) {
                    $updates['daemon_write'] = 0;
                }
                DB::update('novels', $updates, 'id=?', [$novelId]);
            }

            if (!$pdo->commit()) {
                throw new RuntimeException('Could not commit released-claim reconciliation.');
            }
            $startedTransaction = false;
            return true;
        } finally {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            NovelProjectionFence::release($projectionFence);
        }
    }

    public static function releaseChapterClaimAndPauseNovel(
        int $novelId,
        int $chapterId,
        array $chapter,
        bool $disableDaemon = false
    ): bool {
        return self::releaseChapterClaimAndSetNovelStatus(
            $novelId,
            $chapterId,
            $chapter,
            'paused',
            $disableDaemon
        );
    }

    /**
     * Release only the exact old Canon claim. It deliberately cannot restore
     * content or Canon metadata, so a concurrent successful promotion is never
     * overwritten by exception cleanup.
     *
     * @param array<string,mixed> $baseChapter
     */
    private static function restoreRewriteClaim(
        int $novelId,
        int $chapterId,
        array $baseChapter,
        string $claimToken
    ): bool {
        return DB::execute(
            "UPDATE chapters
             SET status='completed', claim_token=''
             WHERE id=? AND novel_id=? AND status='writing' AND claim_token=?
               AND canonical_state='canonical' AND content_revision_hash=?
               AND canonical_candidate_uid=? AND content=?
               AND write_policy_snapshot_json=?",
            [
                $chapterId,
                $novelId,
                $claimToken,
                $baseChapter['content_revision_hash'],
                $baseChapter['canonical_candidate_uid'],
                $baseChapter['content'],
                $baseChapter['write_policy_snapshot_json'],
            ]
        ) === 1;
    }

    /**
     * Persist, evaluate and atomically promote/quarantine one RewriteAgent
     * result. The caller invokes this only after the AI call has completed.
     *
     * @param array<string,mixed> $baseChapter
     * @param array<string,mixed> $novelData
     * @param array<string,mixed> $rewriteUpdates non-content metadata only
     * @param null|array{owner:string,token:string,generation:int} $postprocessLease
     * @return array{
     *   committed:bool,
     *   quarantined:bool,
     *   accepted_content:string,
     *   revision_hash:string,
     *   candidate_uid:string,
     *   successor_job_id:?int,
     *   decision:QualityGateDecision,
     *   chapter:array<string,mixed>
     * }
     */
    private static function commitPostprocessRewriteCandidate(
        int $novelId,
        int $chapterId,
        array $baseChapter,
        array $novelData,
        string $candidateContent,
        WritePolicySnapshot $policySnapshot,
        array $rewriteUpdates,
        int $postprocessJobId,
        string $postprocessStage,
        ?array $postprocessLease,
        array $generationIntegrity = []
    ): array {
        $baseRevisionHash = $baseChapter['content_revision_hash'] ?? null;
        $parentCandidateUid = $baseChapter['canonical_candidate_uid'] ?? null;
        $basePolicyJson = $baseChapter['write_policy_snapshot_json'] ?? null;
        $baseContent = $baseChapter['content'] ?? null;
        if ((string)($baseChapter['status'] ?? '') !== 'completed'
            || (string)($baseChapter['canonical_state'] ?? '') !== 'canonical'
            || !is_string($baseRevisionHash)
            || preg_match('/^[a-f0-9]{64}$/D', $baseRevisionHash) !== 1
            || !is_string($parentCandidateUid)
            || preg_match('/^[a-f0-9]{32}$/D', $parentCandidateUid) !== 1
            || !is_string($basePolicyJson)
            || $basePolicyJson === ''
            || !is_string($baseContent)
            || !hash_equals($baseRevisionHash, hash('sha256', $baseContent))) {
            throw new WriteEnginePersistenceException(
                'Rewrite Candidate requires an authenticated completed Canon parent.'
            );
        }

        $userId = (int)($novelData['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new WriteEnginePersistenceException('Unable to resolve the rewrite Candidate owner.');
        }

        $candidateWords = countWords($candidateContent);
        $candidateHash = hash('sha256', $candidateContent);
        $candidateUid = self::deterministicRewriteCandidateUid(
            $postprocessJobId,
            $chapterId,
            $baseRevisionHash,
            $parentCandidateUid,
            $candidateHash
        );
        $claimToken = substr(hash('sha256', 'rewrite_claim_v1:' . $candidateUid), 0, 32);
        $baseSnapshot = self::captureChapterBaseSnapshot($baseChapter, $claimToken);

        try {
            $candidateRow = ChapterCandidateRepository::createDraft([
                'candidate_uid' => $candidateUid,
                'user_id' => $userId,
                'novel_id' => $novelId,
                'chapter_id' => $chapterId,
                'source' => ChapterCandidateRepository::SOURCE_REWRITE,
                'source_run_uid' => null,
                'source_task_id' => $postprocessJobId > 0
                    ? 'postprocess_' . $postprocessJobId
                    : null,
                'parent_candidate_uid' => $parentCandidateUid,
                'base_revision_hash' => $baseRevisionHash,
                'base_snapshot_json' => $baseSnapshot,
                'inline_content' => $candidateContent,
                'content_hash' => $candidateHash,
                'words' => $candidateWords,
            ], $policySnapshot);
        } catch (Throwable $exception) {
            throw new WriteEnginePersistenceException(
                'Unable to persist the Rewrite Candidate: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $lifecycle = (string)($candidateRow['lifecycle'] ?? '');
        if ($lifecycle === ChapterCandidateRepository::LIFECYCLE_COMMITTED) {
            $validationJson = $candidateRow['validation_json'] ?? null;
            if (!is_string($validationJson) || $validationJson === '') {
                throw new WriteEnginePersistenceException(
                    'Persisted committed Rewrite Candidate has no decision.'
                );
            }
            $decision = QualityGateDecision::fromJson($validationJson);
            $canonicalRow = DB::fetch(
                'SELECT * FROM chapters
                 WHERE id=? AND novel_id=? AND status="completed"
                   AND canonical_state="canonical" AND content_revision_hash=?
                   AND canonical_candidate_uid=? LIMIT 1',
                [$chapterId, $novelId, $candidateHash, $candidateUid]
            );
            $successorJobId = (int)(DB::fetchColumn(
                'SELECT id FROM postprocess_jobs
                 WHERE chapter_id=? AND revision_hash=? AND stage=?
                   AND accepted_candidate_uid=? LIMIT 1',
                [$chapterId, $candidateHash, $postprocessStage, $candidateUid]
            ) ?? 0);
            if (!is_array($canonicalRow)
                || !is_string($canonicalRow['content'] ?? null)
                || !hash_equals($candidateContent, $canonicalRow['content'])
                || $successorJobId <= 0) {
                throw new WriteEnginePersistenceException(
                    'Persisted Rewrite Candidate commit is missing its authoritative Canon state.'
                );
            }
            return [
                'committed' => true,
                'quarantined' => false,
                'accepted_content' => $candidateContent,
                'revision_hash' => $candidateHash,
                'candidate_uid' => $candidateUid,
                'successor_job_id' => $successorJobId,
                'decision' => $decision,
                'chapter' => $canonicalRow,
            ];
        }
        if ($lifecycle === ChapterCandidateRepository::LIFECYCLE_QUARANTINED) {
            $validationJson = $candidateRow['validation_json'] ?? null;
            if (!is_string($validationJson) || $validationJson === '') {
                throw new WriteEnginePersistenceException(
                    'Persisted quarantined Rewrite Candidate has no decision.'
                );
            }
            $decision = QualityGateDecision::fromJson($validationJson);
            return [
                'committed' => false,
                'quarantined' => true,
                'accepted_content' => $baseContent,
                'revision_hash' => $baseRevisionHash,
                'candidate_uid' => $candidateUid,
                'successor_job_id' => null,
                'decision' => $decision,
                'chapter' => $baseChapter,
            ];
        }
        if ($lifecycle !== ChapterCandidateRepository::LIFECYCLE_DRAFT) {
            throw new WriteEnginePersistenceException(
                'Persisted Rewrite Candidate is not an unevaluated draft.'
            );
        }

        $decision = self::evaluateRewriteCandidate(
            $novelId,
            $baseChapter,
            $candidateContent,
            $policySnapshot,
            $generationIntegrity
        );

        WritePolicySnapshotFactory::assertEmergencyEpochCurrent($policySnapshot);
        if ($postprocessJobId > 0) {
            if ($postprocessLease === null) {
                throw new WriteEnginePostprocessLeaseLostException(
                    'Rewrite Candidate submission is missing its post-processing lease fence.'
                );
            }
            require_once __DIR__ . '/tasks/PostprocessJobRepository.php';
            PostprocessJobRepository::assertLeaseOwned(
                $postprocessJobId,
                $postprocessLease['owner'],
                $postprocessLease['token'],
                $postprocessLease['generation']
            );
        }
        if (!self::claimCompletedCanonForRewrite(
            $novelId,
            $chapterId,
            $baseChapter,
            $claimToken
        )) {
            $current = DB::fetch(
                'SELECT status, content, canonical_state, content_revision_hash,
                        canonical_candidate_uid, write_policy_snapshot_json, claim_token
                 FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                [$chapterId, $novelId]
            );
            $completedBaseChanged = is_array($current)
                && (string)($current['status'] ?? '') === 'completed'
                && ((string)($current['canonical_state'] ?? '') !== 'canonical'
                    || !is_string($current['content_revision_hash'] ?? null)
                    || !hash_equals($baseRevisionHash, $current['content_revision_hash'])
                    || !is_string($current['canonical_candidate_uid'] ?? null)
                    || !hash_equals($parentCandidateUid, $current['canonical_candidate_uid'])
                    || !is_string($current['content'] ?? null)
                    || !hash_equals($baseContent, $current['content'])
                    || !is_string($current['write_policy_snapshot_json'] ?? null)
                    || !hash_equals($basePolicyJson, $current['write_policy_snapshot_json']));
            if ($completedBaseChanged) {
                // Only an authoritative completed Canon with a different base
                // can retire this draft. A writing row may belong to another
                // worker evaluating the same deterministic Candidate.
                try {
                    ChapterCandidateRepository::markSuperseded(
                        $candidateUid,
                        ChapterCandidateRepository::LIFECYCLE_DRAFT
                    );
                } catch (Throwable) {
                }
                throw new WriteEnginePostprocessStaleException(
                    'Rewrite base Canon changed before the submission claim.'
                );
            }
            throw new WriteEnginePersistenceException(
                'Unable to acquire the exact completed Canon rewrite claim; the draft remains retryable.'
            );
        }

        $successorJobId = null;
        $transitionSettled = false;
        $transitionResult = null;
        try {
            $commitService = new CanonicalCommitService();
            $transitionResult = $commitService->commitCandidate(
                $userId,
                $novelId,
                $chapterId,
                $candidateUid,
                $claimToken,
                $policySnapshot,
                $decision,
                static function (array $context) use (
                    $chapterId,
                    $novelId,
                    $claimToken,
                    $rewriteUpdates,
                    $candidateWords,
                    $candidateUid,
                    $candidateHash,
                    $postprocessStage,
                    $policySnapshot,
                    $postprocessJobId,
                    $postprocessLease,
                    &$successorJobId
                ): void {
                    if ($postprocessJobId > 0) {
                        if ($postprocessLease === null) {
                            throw new WriteEnginePostprocessLeaseLostException(
                                'Rewrite Canon commit is missing its post-processing lease fence.'
                            );
                        }
                        require_once __DIR__ . '/tasks/PostprocessJobRepository.php';
                        PostprocessJobRepository::assertLeaseOwned(
                            $postprocessJobId,
                            $postprocessLease['owner'],
                            $postprocessLease['token'],
                            $postprocessLease['generation'],
                            true
                        );
                    }
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    AgentDirectives::bindContentVersion($novelId, $chapterId, $claimToken,
                        $candidateUid, $candidateHash, (int)$context['chapter']['chapter_number']);
                    $oldWords = (int)($context['chapter']['words'] ?? 0);
                    $chapterNumber = (int)($context['chapter']['chapter_number'] ?? 0);
                    if ($chapterNumber <= 0) {
                        throw new WriteEnginePersistenceException(
                            'Rewrite Canon promotion is missing a valid chapter number.'
                        );
                    }
                    backupChapterVersion($chapterId, true);
                    ChapterMutationService::resetGeneratedProjectionsForReingest(
                        $novelId,
                        $chapterNumber,
                        $chapterId
                    );
                    $metadataAffected = DB::update(
                        'chapters',
                        $rewriteUpdates,
                        'id=? AND novel_id=? AND status="writing" AND claim_token=?',
                        [$chapterId, $novelId, $claimToken]
                    );
                    if ($metadataAffected !== 1) {
                        throw new WriteEnginePersistenceException(
                            'Rewrite metadata CAS failed before Canon promotion.'
                        );
                    }

                    $wordDelta = $candidateWords - $oldWords;
                    if ($wordDelta !== 0) {
                        $statsAffected = DB::execute(
                            'UPDATE novels
                             SET total_words=GREATEST(0, total_words + ?)
                             WHERE id=?',
                            [$wordDelta, $novelId]
                        );
                        if ($statsAffected !== 1) {
                            throw new WriteEnginePersistenceException(
                                'Rewrite Candidate could not update novel word statistics.'
                            );
                        }
                    }

                    require_once __DIR__ . '/tasks/PostprocessJobRepository.php';
                    $successorJobId = PostprocessJobRepository::enqueue(
                        $novelId,
                        $chapterId,
                        $candidateHash,
                        $postprocessStage,
                        3,
                        $policySnapshot,
                        $candidateUid,
                        false
                    );
                }
            );
            $transitionSettled = true;
        } catch (Throwable $exception) {
            $restored = false;
            if (!$transitionSettled) {
                $restored = self::restoreRewriteClaim(
                    $novelId,
                    $chapterId,
                    $baseChapter,
                    $claimToken
                );
            }
            if (!$transitionSettled && !$restored) {
                // An ambiguous transaction/connection failure may mean the
                // transition committed despite the exception. Re-read both
                // authorities before deciding whether this is success, a
                // safely restored base, or a concurrent Canon change.
                $after = DB::fetch(
                    'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                    [$chapterId, $novelId]
                );
                $persistedCandidate = ChapterCandidateRepository::findByUid($candidateUid);
                $afterIsCommittedCandidate = is_array($after)
                    && (string)($after['status'] ?? '') === 'completed'
                    && (string)($after['claim_token'] ?? '') === ''
                    && (string)($after['canonical_state'] ?? '') === 'canonical'
                    && is_string($after['content_revision_hash'] ?? null)
                    && hash_equals($candidateHash, $after['content_revision_hash'])
                    && is_string($after['canonical_candidate_uid'] ?? null)
                    && hash_equals($candidateUid, $after['canonical_candidate_uid'])
                    && is_string($after['content'] ?? null)
                    && hash_equals($candidateContent, $after['content'])
                    && (string)($persistedCandidate['lifecycle'] ?? '')
                        === ChapterCandidateRepository::LIFECYCLE_COMMITTED;
                if ($afterIsCommittedCandidate) {
                    $successorJobId = (int)(DB::fetchColumn(
                        'SELECT id FROM postprocess_jobs
                         WHERE chapter_id=? AND revision_hash=? AND stage=?
                           AND accepted_candidate_uid=? LIMIT 1',
                        [$chapterId, $candidateHash, $postprocessStage, $candidateUid]
                    ) ?? 0);
                    if ($successorJobId <= 0) {
                        throw new WriteEnginePersistenceException(
                            'Committed Rewrite Canon is missing its transactional successor job.',
                            0,
                            $exception
                        );
                    }
                    $transitionResult = [
                        'committed' => true,
                        'quarantined' => false,
                        'accepted_content' => $candidateContent,
                        'revision_hash' => $candidateHash,
                        'candidate_uid' => $candidateUid,
                        'decision' => $decision,
                    ];
                    $transitionSettled = true;
                } else {
                    $afterIsRestoredBase = is_array($after)
                        && (string)($after['status'] ?? '') === 'completed'
                        && (string)($after['claim_token'] ?? '') === ''
                        && (string)($after['canonical_state'] ?? '') === 'canonical'
                        && is_string($after['content_revision_hash'] ?? null)
                        && hash_equals($baseRevisionHash, $after['content_revision_hash'])
                        && is_string($after['canonical_candidate_uid'] ?? null)
                        && hash_equals($parentCandidateUid, $after['canonical_candidate_uid'])
                        && is_string($after['content'] ?? null)
                        && hash_equals($baseContent, $after['content']);
                    $afterIsQuarantinedCandidate = $afterIsRestoredBase
                        && (string)($persistedCandidate['lifecycle'] ?? '')
                            === ChapterCandidateRepository::LIFECYCLE_QUARANTINED;
                    if ($afterIsQuarantinedCandidate) {
                        $transitionResult = [
                            'committed' => false,
                            'quarantined' => true,
                            'accepted_content' => $baseContent,
                            'revision_hash' => $baseRevisionHash,
                            'candidate_uid' => $candidateUid,
                            'decision' => $decision,
                        ];
                        $transitionSettled = true;
                    } elseif (!$afterIsRestoredBase
                        && is_array($after)
                        && (string)($after['status'] ?? '') === 'completed') {
                        throw new WriteEnginePostprocessStaleException(
                            'Rewrite claim restoration observed a different completed Canon.',
                            0,
                            $exception
                        );
                    } elseif (!$afterIsRestoredBase) {
                        throw new WriteEnginePersistenceException(
                            'Rewrite claim could not be restored because ownership changed.',
                            0,
                            $exception
                        );
                    }
                }
            }
            if (!$transitionSettled) {
                if ($exception instanceof WriteEnginePostprocessStaleException
                    || $exception instanceof WriteEnginePostprocessLeaseLostException
                    || $exception instanceof WriteEnginePersistenceException) {
                    throw $exception;
                }
                throw new WriteEnginePersistenceException(
                    'Rewrite Candidate to Canon transition failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        if (!is_array($transitionResult)) {
            throw new WriteEnginePersistenceException('Rewrite transition returned no authoritative result.');
        }

        if (!empty($transitionResult['quarantined'])) {
            return [
                'committed' => false,
                'quarantined' => true,
                'accepted_content' => $baseContent,
                'revision_hash' => $baseRevisionHash,
                'candidate_uid' => $candidateUid,
                'successor_job_id' => null,
                'decision' => $decision,
                'chapter' => $baseChapter,
            ];
        }

        $canonicalRow = DB::fetch(
            'SELECT * FROM chapters
             WHERE id=? AND novel_id=? AND status="completed"
               AND canonical_state="canonical" AND content_revision_hash=?
               AND canonical_candidate_uid=? LIMIT 1',
            [$chapterId, $novelId, $candidateHash, $candidateUid]
        );
        if (!is_array($canonicalRow)
            || !is_string($canonicalRow['content'] ?? null)
            || !hash_equals($candidateContent, $canonicalRow['content'])) {
            throw new WriteEnginePersistenceException(
                'Rewritten Canon could not be read back authoritatively.'
            );
        }

        return [
            'committed' => true,
            'quarantined' => false,
            'accepted_content' => $candidateContent,
            'revision_hash' => $candidateHash,
            'candidate_uid' => $candidateUid,
            'successor_job_id' => $successorJobId,
            'decision' => $decision,
            'chapter' => $canonicalRow,
        ];
    }

    /**
     * @return array{state:string,revision_hash:string,candidate_uid:?string,successor_job_id:?int}
     */
    private static function postProcessOutcome(
        string $state,
        string $revisionHash,
        ?string $candidateUid = null,
        ?int $successorJobId = null
    ): array {
        return [
            'state' => $state,
            'revision_hash' => $revisionHash,
            'candidate_uid' => $candidateUid,
            'successor_job_id' => $successorJobId,
        ];
    }

    public static function postProcess(
        int $novelId,
        array $chapter,
        string $fullContent,
        MemoryEngine $engine,
        bool $resumeAfterFinalization = false,
        ?callable $progressHeartbeat = null,
        ?WritePolicySnapshot $policySnapshot = null,
        array $postprocessContext = []
    ): array {
        $chId = $chapter['id'];
        // 审计修复 B07（2026-06-16）：$chNum 统一定义一次，替代方法内 20+ 次重复
        $chNum = (int)($chapter['chapter_number'] ?? 0);
        // P1-8：安装租约心跳（finally 恢复）
        $prevHeartbeat = self::$postProcessHeartbeat;
        self::$postProcessHeartbeat = $progressHeartbeat;
        if ($progressHeartbeat !== null) {
            try {
                $progressHeartbeat();
            } catch (WriteEnginePostprocessLeaseLostException $e) {
                self::$postProcessHeartbeat = $prevHeartbeat;
                throw $e;
            } catch (Throwable $e) {
                self::$postProcessHeartbeat = $prevHeartbeat;
                throw new WriteEnginePostprocessLeaseLostException(
                    'Initial post-processing heartbeat could not authenticate ownership.',
                    0,
                    $e
                );
            }
        }

        $keepPpPending = false;
        $parallelBatch = null;
        $postprocessJobId = max(0, (int)($postprocessContext['job_id'] ?? 0));
        $postprocessStage = (string)($postprocessContext['stage'] ?? 'full');
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $postprocessStage) !== 1) {
            throw new InvalidArgumentException('Post-processing stage is invalid.');
        }
        $alreadyAcceptedCandidateUid = $postprocessContext['accepted_candidate_uid'] ?? null;
        if ($alreadyAcceptedCandidateUid !== null
            && (!is_string($alreadyAcceptedCandidateUid)
                || preg_match('/^[a-f0-9]{32}$/D', $alreadyAcceptedCandidateUid) !== 1)) {
            throw new InvalidArgumentException('Post-processing accepted Candidate UID is invalid.');
        }
        $jobRewriteAllowed = $postprocessContext['rewrite_allowed'] ?? null;
        $postprocessLease = null;
        $leaseOwner = $postprocessContext['lease_owner'] ?? null;
        $leaseToken = $postprocessContext['lease_token'] ?? null;
        $fenceGeneration = $postprocessContext['fence_generation'] ?? null;
        if ($postprocessJobId > 0) {
            if (!is_string($leaseOwner) || trim($leaseOwner) === ''
                || !is_string($leaseToken)
                || preg_match('/^[a-f0-9]{32}$/D', $leaseToken) !== 1
                || (!is_int($fenceGeneration)
                    && !(is_string($fenceGeneration)
                        && preg_match('/^[1-9][0-9]*$/D', $fenceGeneration) === 1))
                || (int)$fenceGeneration <= 0) {
                throw new WriteEnginePostprocessLeaseLostException(
                    'Post-processing job context has incomplete lease fencing.'
                );
            }
            if (!is_bool($jobRewriteAllowed)
                || ($jobRewriteAllowed && $alreadyAcceptedCandidateUid === null)) {
                throw new WriteEnginePersistenceException(
                    'Post-processing job context has invalid rewrite eligibility.'
                );
            }
            $postprocessLease = [
                'owner' => trim($leaseOwner),
                'token' => $leaseToken,
                'generation' => (int)$fenceGeneration,
            ];
        } elseif ($leaseOwner !== null || $leaseToken !== null || $fenceGeneration !== null) {
            throw new InvalidArgumentException(
                'A post-processing lease fence cannot be supplied without a job id.'
            );
        } else {
            $jobRewriteAllowed = false;
        }
        $effectiveRevisionHash = '';
        $effectiveCandidateUid = is_string($alreadyAcceptedCandidateUid)
            ? $alreadyAcceptedCandidateUid
            : null;
        $successorJobId = null;
        // 性能观测：整章后处理总耗时与步骤级耗时收集（finally 输出汇总）。
        self::$postProcessTimings = [];
        $postProcessStartedAt = HarnessRuntime::preciseTime();
        try {

        // --- 最终正文版本守卫 + 单次记忆最终化 ---
        // 初稿、重写中间稿都不得写入长期记忆。只有仍与数据库正文完全一致的
        // 最终版本，才允许生成摘要并执行一次 MemoryEngine::ingestChapter()。
        require_once __DIR__ . '/ChapterMemoryFinalizer.php';

        $summaryData = [];
        $ingest = [];
        $novelData = DB::fetch(
            'SELECT id, user_id, title, genre, writing_style, protagonist_name, protagonist_info, model_id, chapter_words
             FROM novels WHERE id=?',
            [$novelId]
        ) ?: ['id' => $novelId];

        $storedChapter = DB::fetch(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" LIMIT 1',
            [$chId, $novelId]
        );
        // saveChapter 会在落盘前清洗段落标记/章节坐标；调用方仍可能持有清洗前
        // 的流式缓冲，因此版本比较必须使用同一规范化规则。
        $requestedContent = stripMetaLeaks(stripSegmentMarkers($fullContent));
        $requestedHash = hash('sha256', $requestedContent);
        $effectiveRevisionHash = $requestedHash;
        if (!$storedChapter
            || !hash_equals($requestedHash, hash('sha256', (string)($storedChapter['content'] ?? '')))) {
            addLog($novelId, 'warn', "第{$chNum}章后处理已过期：正文版本已变化，丢弃旧任务", $chId);
            return self::postProcessOutcome('stale', $requestedHash);
        }

        // 后续所有质量检测、约束、知识库和记忆均使用数据库确认过的同一正文。
        $storedPolicyJson = $storedChapter['write_policy_snapshot_json'] ?? null;
        if ($policySnapshot === null && is_string($storedPolicyJson) && $storedPolicyJson !== '') {
            $policySnapshot = WritePolicySnapshot::fromJson($storedPolicyJson);
        }
        $postProcessTarget = max(1, (int)($novelData['chapter_words'] ?? 0));
        if ($postProcessTarget < 500) {
            $postProcessTarget = max(500, (int)getSystemSetting('ws_chapter_words', 2000, 'int'));
        }
        if ($policySnapshot !== null) {
            $postProcessTarget = $policySnapshot->targetWords();
        }
        if ($policySnapshot === null) {
            if ((string)($storedChapter['canonical_state'] ?? 'none') === 'canonical') {
                throw new WriteEnginePersistenceException(
                    'Canonical post-processing requires the frozen write policy snapshot.'
                );
            }
            // Compatibility path for pre-M60/legacy rows only. These historical
            // jobs retain their per-attempt live-policy behavior; new Canon jobs
            // are required to restore the snapshot committed with the chapter.
            $policySnapshot = WritePolicySnapshotFactory::captureForChapter(
                $novelId,
                $postProcessTarget
            );
        } else {
            $policySnapshot = self::resolveWritePolicySnapshot(
                $novelId,
                $postProcessTarget,
                $policySnapshot
            );
        }
        if (is_string($storedPolicyJson) && $storedPolicyJson !== '') {
            $storedPolicy = WritePolicySnapshot::fromJson($storedPolicyJson);
            if (!hash_equals($storedPolicy->checksum(), $policySnapshot->checksum())) {
                throw new WriteEnginePersistenceException(
                    'Post-processing policy does not match the committed chapter snapshot.'
                );
            }
        }
        WritePolicySnapshotFactory::assertEmergencyEpochCurrent($policySnapshot);

        $chapter = array_merge($chapter, $storedChapter);
        $fullContent = (string)$storedChapter['content'];
        $memoryAlreadyFinalized = ChapterMemoryFinalizer::hasFinalizedRevision($novelId, $chId, $requestedHash);
        if ($memoryAlreadyFinalized && !$resumeAfterFinalization) {
            $keepPpPending = true;
            require_once __DIR__ . '/constraints/ConstraintProjectionService.php';
            ConstraintProjectionService::refresh($novelId, $progressHeartbeat);
            $keepPpPending = false;
            addLog($novelId, 'info', "第{$chNum}章相同正文版本已完成后处理，跳过重复任务", $chId);
            return self::postProcessOutcome(
                'duplicate',
                $effectiveRevisionHash,
                $effectiveCandidateUid,
                $successorJobId
            );
        }
        if ($memoryAlreadyFinalized) {
            // 审计修复（2026-08-08）：chapter_summary 是纯文本 narrative（finalize 落盘），
            // json_decode 恒失败 → $summaryData=[] → 恢复路径的爽点检测丢失 LLM 判定类型。
            // 结构化字段各有独立列（cool_point_type 在 finalize 时单独落盘），直接重建。
            $summaryData = [
                'narrative_summary' => (string)($storedChapter['chapter_summary'] ?? ''),
                'cool_point_type'   => $storedChapter['cool_point_type'] ?? null,
            ];
            addLog($novelId, 'info', "第{$chNum}章记忆已最终化，继续恢复后续投影", $chId);
        }

        // --- 质量检测 + 纯函数式重写决策 ---
        // RewriteAgent 仅返回候选正文；任何正文/记忆写入都由本方法统一提交。
        // 审计修复（2026-08-09 R14）：UI「迭代重写」标签页自 2026-07-19 起只写小说级
        // iterative_settings.rewrite.enabled，不再写全局 ws_rewrite_enabled——本闸门原来
        // 只读全局键，导致后台开关是假旋钮（打开也不生效）。改为优先读小说级开关，
        // 无小说级配置时回退全局键（兼容旧安装）。
        $rewriteEnabled = $policySnapshot->rewriteEnabled();
        $gatesResult = ['gates' => [], 'score' => 100.0];
        $wasRewritten = false;
        $acceptedRewriteDecision = null;
        $rewriteEligibleCanon = (string)($storedChapter['canonical_state'] ?? '') === 'canonical'
            && is_string($storedChapter['content_revision_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $storedChapter['content_revision_hash']) === 1
            && hash_equals($requestedHash, $storedChapter['content_revision_hash'])
            && is_string($storedChapter['canonical_candidate_uid'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/D', $storedChapter['canonical_candidate_uid']) === 1
            && is_string($storedChapter['write_policy_snapshot_json'] ?? null)
            && $storedChapter['write_policy_snapshot_json'] !== '';
        $frozenRewriteLimit = $policySnapshot->rewriteLimit();
        $rewriteAllowed = $rewriteEnabled
            && $frozenRewriteLimit > 0
            && !$memoryAlreadyFinalized
            && $jobRewriteAllowed === true
            && $rewriteEligibleCanon;
        if ($rewriteEnabled
            && !$memoryAlreadyFinalized
            && !$rewriteAllowed) {
            $skipReason = $frozenRewriteLimit <= 0
                ? '冻结策略的重写次数为 0'
                : ($jobRewriteAllowed !== true
                    ? '当前任务已绑定采纳 Candidate，留给该版本最终化'
                    : '缺少可认证的 Canon 父版本（legacy/no-parent）');
            addLog($novelId, 'info', "RewriteAgent跳过：{$skipReason}", $chId);
        }
        $gatesRewriteStartedAt = HarnessRuntime::preciseTime();
        if ($rewriteEnabled && !$memoryAlreadyFinalized) {
            if ($rewriteAllowed) {
            $gatesResult = self::runQualityGates(
                $novelId,
                $chId,
                $fullContent,
                $policySnapshot->qualityCheckEnabled()
            );

            try {
                require_once __DIR__ . '/agents/RewriteAgent.php';
                // chapterId=0 禁止迭代控制器在候选被采纳前提前写入章节元数据。
                $rewriter = new RewriteAgent($novelId, 0, $frozenRewriteLimit);
                $rewriteResult = $rewriter->rewriteIfNeeded(
                    $chapter,
                    $fullContent,
                    $gatesResult['gates'] ?? [],
                    (float)($gatesResult['score'] ?? 100.0),
                    isset($novelData['model_id']) ? (int)$novelData['model_id'] : null
                );

                // RewriteAgent reports whether a model generation was actually
                // attempted; `rewritten` is no longer an adoption verdict.
                // array_key_exists + is_string deliberately accepts the empty
                // string so empty/partial/short/low-gain attempts are frozen as
                // Candidates and judged by the one canonical quality gate.
                if (!empty($rewriteResult['generation_attempted'])
                    && array_key_exists('content', $rewriteResult)
                    && is_string($rewriteResult['content'])) {
                    $candidate = stripMetaLeaks(stripSegmentMarkers(trim($rewriteResult['content'])));
                    $rwTarget = $policySnapshot->targetWords();
                    $rwTolerance = [
                        'min' => $policySnapshot->minWords(),
                        'max' => $policySnapshot->maxWords(),
                    ];
                    $candidateWords = countWords($candidate);
                    $hardMinimum = $policySnapshot->hardMinWords();

                    if ($candidateWords < $hardMinimum) {
                        addLog(
                            $novelId,
                            'warn',
                            "RewriteAgent候选疑似截断（{$candidateWords}字 < 安全下限{$hardMinimum}字），提交统一质量门隔离判定",
                            $chId
                        );
                    }
                    // 空文本和短文本也必须先冻结成 Candidate，再由同一
                    // CanonicalQualityGate 决定隔离，不能在持久化前静默丢弃。
                        if ($candidateWords > (int)$rwTolerance['max']) {
                            addLog(
                                $novelId,
                                'warn',
                                "RewriteAgent候选{$candidateWords}字超出篇幅，保留完整稿交质量门审阅",
                                $chId
                            );
                        }

                        $originalContent = $fullContent;
                        $originalWords = countWords($originalContent);
                        if (!hash_equals(hash('sha256', $originalContent), hash('sha256', $candidate))) {
                            $history = $rewriteResult['iteration_history'] ?? [];
                            $evaluation = $rewriteResult['improvement_report'] ?? null;
                            $newScore = isset($rewriteResult['new_score'])
                                ? (float)$rewriteResult['new_score']
                                : (float)($gatesResult['score'] ?? 0);
                            $rewriteUpdates = [
                                // 初稿五关结果不能继续挂在重写后的正文上；完整五关会在
                                // 后续重新计算，期间仅保留 RewriteAgent 的候选评分。
                                'quality_score' => $newScore > 0 ? $newScore : null,
                                'gate_results' => null,
                                'rewritten' => 1,
                                'iterations_used' => max(0, (int)($rewriteResult['iterations_used'] ?? 0)),
                                'total_improvement' => max(
                                    0.0,
                                    $newScore - (float)($gatesResult['score'] ?? 0)
                                ),
                                'iterative_history' => $history !== []
                                    ? json_encode($history, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                                    : null,
                                'iteration_evaluation' => $evaluation !== null
                                    ? json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                                    : null,
                                'rewrite_time' => HarnessRuntime::sqlNow(),
                            ];

                            $rewriteOutcome = self::commitPostprocessRewriteCandidate(
                                $novelId,
                                $chId,
                                $storedChapter,
                                $novelData,
                                $candidate,
                                $policySnapshot,
                                $rewriteUpdates,
                                $postprocessJobId,
                                $postprocessStage,
                                $postprocessLease,
                                (array)($rewriteResult['generation_integrity'] ?? [])
                            );
                            $acceptedRewriteDecision = $rewriteOutcome['decision'];
                            if ($rewriteOutcome['quarantined']) {
                                $reason = self::qualityDecisionReason($acceptedRewriteDecision);
                                addLog(
                                    $novelId,
                                    'warn',
                                    "RewriteAgent候选已隔离，原 Canon 保持不变：{$reason}",
                                    $chId
                                );
                            } else {
                                $storedChapter = $rewriteOutcome['chapter'];
                                $chapter = array_merge($chapter, $storedChapter);
                                $fullContent = $rewriteOutcome['accepted_content'];
                                $requestedHash = $rewriteOutcome['revision_hash'];
                                $effectiveRevisionHash = $requestedHash;
                                $effectiveCandidateUid = $rewriteOutcome['candidate_uid'];
                                $successorJobId = $rewriteOutcome['successor_job_id'];
                                $memoryAlreadyFinalized = false;
                                $wasRewritten = true;
                                $keepPpPending = true;
                                clearChapterCache($chId, $novelId);
                                addLog(
                                    $novelId,
                                    'info',
                                    "RewriteAgent候选已晋升 Canon：{$originalWords}字 → {$candidateWords}字",
                                    $chId
                                );
                            }
                    }
                }
            } catch (WriteEnginePostprocessStaleException $e) {
                addLog($novelId, 'warn', "第{$chNum}章重写提交已过期：" . $e->getMessage(), $chId);
                return self::postProcessOutcome(
                    'stale',
                    $effectiveRevisionHash,
                    $effectiveCandidateUid,
                    $successorJobId
                );
            } catch (WriteEnginePostprocessLeaseLostException $e) {
                // Lease loss is not an optional RewriteAgent failure. The
                // entire stale worker must stop before downstream projections.
                throw $e;
            } catch (WriteEnginePersistenceException $e) {
                throw $e;
            } catch (Throwable $e) {
                // 重写属于可选优化；失败时保留已校验的原正文继续最终化。
                addLog($novelId, 'warn', 'RewriteAgent跳过：' . $e->getMessage(), $chId);
            }
            }
        }
        self::recordPostProcessTiming($novelId, '质量门禁与重写', $gatesRewriteStartedAt);

        // Candidate 已在 Canon 提交前通过统一质量门；这里仅记录该不可变
        // 决策，不能再用另一套完成态检查绕过/推翻 CanonicalQualityGate。
        if ($wasRewritten && $acceptedRewriteDecision instanceof QualityGateDecision) {
            $postRewriteBlock = self::qualityDecisionReason($acceptedRewriteDecision);
            if ($postRewriteBlock !== '') {
                addLog(
                    $novelId,
                    'warn',
                    "第{$chNum}章重写 Candidate 质量决策含警告：{$postRewriteBlock}",
                    $chId
                );
            }
        }

        // v72: compute-only sidecar overlaps KB/Critic with the original memory
        // and graph path. The existing novel fence and final completion barrier stay intact.
        if ($postprocessJobId > 0) {
            require_once __DIR__ . '/tasks/PostprocessSubtaskRepository.php';
            if (getSystemSetting('ws_postprocess_parallel_enabled', false, 'bool')
                || PostprocessSubtaskRepository::hasTasks($postprocessJobId)) {
                require_once __DIR__ . '/tasks/PostprocessParallelBatch.php';
                $parallelBatch = PostprocessParallelBatch::start(
                    $postprocessContext, $novelData, $chapter, $fullContent, $progressHeartbeat
                );
            }
        }

        // 摘要、MemoryEngine、伏笔提及、金句回调和角色出场在同一事务边界内
        // 只针对最终正文执行一次；失败则整批回滚，不留下半套投影。
        if ($memoryAlreadyFinalized) {
            $finalized = ['ok' => true, 'summary' => $summaryData, 'ingest' => []];
        } else {
            if ($progressHeartbeat !== null) {
                try {
                    $progressHeartbeat();
                } catch (WriteEnginePostprocessLeaseLostException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new WriteEnginePostprocessLeaseLostException(
                        'Post-processing heartbeat failed before memory finalization.',
                        0,
                        $e
                    );
                }
            }
            $finalizeStartedAt = HarnessRuntime::preciseTime();
            try {
                $finalized = ChapterMemoryFinalizer::finalize(
                    $novelId,
                    $chId,
                    $fullContent,
                    $engine
                );
            } catch (Throwable $memoryError) {
            // 重写正文已 CAS 落盘、但最终化事务失败时，宁可让本章暂时没有
            // 记忆，也不能保留可能来自旧正文的投影。锁竞争由另一任务负责，
            // 不在这里越过其 advisory lock 清理。
            if ($wasRewritten && !str_contains($memoryError->getMessage(), '正在由另一任务处理')) {
                try {
                    ChapterMutationService::resetGeneratedProjectionsForReingest(
                        $novelId,
                        $chNum,
                        $chId
                    );
                } catch (Throwable $cleanupError) {
                    addLog($novelId, 'error', '重写后失效旧记忆失败：' . $cleanupError->getMessage(), $chId);
                }
            }
                throw $memoryError;
            } finally {
                self::recordPostProcessTiming($novelId, '记忆最终化', $finalizeStartedAt);
            }
        }
        if (!empty($finalized['stale'])) {
            addLog($novelId, 'warn', "第{$chNum}章记忆最终化已过期，丢弃旧任务", $chId);
            return self::postProcessOutcome(
                'stale',
                $effectiveRevisionHash,
                $effectiveCandidateUid,
                $successorJobId
            );
        }
        if (!empty($finalized['historical_deferred'])) {
            addLog(
                $novelId,
                'warn',
                "第{$chNum}章属于历史补写/修改：正文已保存，开始按章节顺序重放累计记忆",
                $chId
            );
            $replayStartedAt = HarnessRuntime::preciseTime();
            try {
                $replay = ChapterMemoryFinalizer::replayFrom(
                    $novelId,
                    $chNum,
                    $engine,
                    [$chNum => (array)($finalized['summary'] ?? [])],
                    $progressHeartbeat
                );
            } finally {
                self::recordPostProcessTiming($novelId, '记忆有序重放', $replayStartedAt);
            }
            if (empty($replay['ok'])) {
                addLog(
                    $novelId,
                    'error',
                    "第{$chNum}章记忆有序重放失败：" . (string)($replay['error'] ?? 'unknown'),
                    $chId
                );
                $keepPpPending = true;
                return self::postProcessOutcome(
                    'pending',
                    $effectiveRevisionHash,
                    $effectiveCandidateUid,
                    $successorJobId
                );
            }
            addLog(
                $novelId,
                'info',
                sprintf(
                    '第%d章起有序重放完成：%d章（至第%d章）',
                    (int)($replay['from'] ?? $chNum),
                    (int)($replay['replayed'] ?? 0),
                    (int)($replay['to'] ?? $chNum)
                ),
                $chId
            );
            $finalized['ok'] = true;
            $finalized['ingest'] = (array)($replay['ingest'] ?? []);
        }
        if (!empty($finalized['duplicate'])) {
            $keepPpPending = true;
            require_once __DIR__ . '/constraints/ConstraintProjectionService.php';
            ConstraintProjectionService::refresh($novelId, $progressHeartbeat);
            $keepPpPending = false;
            addLog($novelId, 'info', "第{$chNum}章记忆已由并发任务最终化，跳过重复后处理", $chId);
            return self::postProcessOutcome(
                'duplicate',
                $effectiveRevisionHash,
                $effectiveCandidateUid,
                $successorJobId
            );
        }
        if (empty($finalized['ok'])) {
            throw new RuntimeException("第{$chNum}章记忆最终化失败");
        }

        $summaryData = (array)($finalized['summary'] ?? []);
        $ingest = (array)($finalized['ingest'] ?? []);
        $resolvedDetails = (array)($ingest['resolved_details'] ?? []);
        $resolvedLog = $resolvedDetails === []
            ? ''
            : ' → ' . implode(' | ', array_map(
                fn($row) => 'ID:' . ($row['id'] ?? '?') . '「' . ($row['desc'] ?? '') . '」',
                $resolvedDetails
            ));
        addLog($novelId, 'info', sprintf(
            '最终正文记忆入库：人物%d / 特征%d / 事件%d / 伏笔+%d / 回收%d%s',
            $ingest['cards_upserted'] ?? 0,
            $ingest['traits_added'] ?? 0,
            $ingest['events_added'] ?? 0,
            $ingest['foreshadowing_added'] ?? 0,
            $ingest['foreshadowing_resolved'] ?? 0,
            $resolvedLog
        ), $chId);

        // 最终化已完成提及追踪；这里只做伏笔健康告警，避免二次计数。
        self::safeRun($novelId, '伏笔健康检测', function() use ($novelId, $chNum) {
            require_once __DIR__ . '/memory/ForeshadowingRepo.php';
            $alerts = (new ForeshadowingRepo($novelId))->checkHealth($chNum);
            if ($alerts === []) {
                return;
            }
            $highAlerts = array_values(array_filter(
                $alerts,
                fn($alert) => ($alert['severity'] ?? '') === 'high'
            ));
            if ($highAlerts !== []) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $message = implode('；', array_map(
                    fn($alert) => (string)($alert['message'] ?? ''),
                    array_slice($highAlerts, 0, 2)
                ));
                $suggestion = implode('；', array_map(
                    fn($alert) => (string)($alert['suggestion'] ?? ''),
                    array_slice($highAlerts, 0, 2)
                ));
                AgentDirectives::addCapped(
                    $novelId,
                    $chNum + 1,
                    'quality',
                    "伏笔健康警告：{$message}。{$suggestion}",
                    2,
                    48,
                    2
                );
            }
            addLog(
                $novelId,
                'info',
                sprintf('伏笔健康检测：%d条告警（高危%d）', count($alerts), count($highAlerts))
            );
        });



        // === 约束框架状态更新（Phase 1）===
        // 下一章 prompt 要读约束状态，且须基于重写后的最终正文 → 留在标志窗口内
        $keepPpPending = true;
        require_once __DIR__ . '/constraints/ConstraintProjectionService.php';
        ConstraintProjectionService::refresh($novelId, $progressHeartbeat);
        $keepPpPending = false;

        // 图谱关系提取依赖一次同步 AI 调用，移出 pp_pending 窗口外执行——
        // 它只影响后续章节的语义召回，迟到一章可接受；放在窗口内会与摘要/重写
        // 一起串联同步 AI 调用，极易把下一章的 waitForPostProcess 顶到超时
        // 强放行（导致下一章读到不一致的记忆状态）。
        // v2: 硬依赖（摘要/记忆/本地追踪/重写/约束状态）已全部落库，
        // 立即清除「后处理进行中」标志放行下一章。以下环节均为咨询型/统计型，
        // 写出的指令面向后续章节，迟到一章只损失一次提示，不值得阻塞写作管线
        // （原先标志直到全书圣经之前才清除，重活全压在 waitForPostProcess 的等待窗口里）。
        @unlink(self::postProcessPendingFlag($novelId, (int)$chapter['chapter_number']));

        // 图谱提取（移出 pp_pending 窗口外，与 KB 同档执行）
        self::safeRun($novelId, '图谱关系提取', function() use ($novelId, $chNum, $fullContent, $novelData) {
            if ($chNum > 0 && getSystemSetting('ws_graph_search_enabled', true, 'bool')) {
                self::extractAndStoreGraphRelations($novelId, $chNum, $fullContent, $novelData['model_id'] ?? null);
            }
        });

        // --- v1.11.2 认知负荷检测（信息密度管理）---
        // 审计修复（2026-08-09 R9-5）：后处理只分析+落库+打日志；
        // 指令由 AgentCoordinator::checkCognitiveLoad 统一生产，避免双写/阈值不一致。
        self::safeRun($novelId, '认知负荷检测', function() use ($novelId, $chNum, $chId, $ingest) {
            require_once __DIR__ . '/CognitiveLoadMonitor.php';
            $loadMonitor = new CognitiveLoadMonitor($novelId);
            $loadResult = $loadMonitor->analyze($chNum, $ingest ?? []);
            $loadMonitor->persistMetrics($chId, $loadResult);

            if (isset($loadResult['severity']) && in_array($loadResult['severity'], ['high', 'medium'], true)) {
                addLog($novelId, 'warn', sprintf(
                    '认知负荷警告：本章引入 %d 个新元素（近5章累计 %d 个）',
                    $loadResult['total_new'] ?? 0,
                    $loadResult['recent_5_sum'] ?? 0
                ));
            }
        });

        // --- v41 钩子回收验证（闭环：本章是否承接了上章章末钩子）---
        self::safeRun($novelId, '钩子回收验证', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/HookPayoffChecker.php';
            HookPayoffChecker::run($novelId, $chapter, $fullContent);
        });

        // --- 审计功能（2026-08-16 P3-黄金三章）：开篇里程碑写后核验（仅第1-3章）---
        // 检测正文是否达成各章里程碑词组；未达成走补救链指令（第N章缺失→第N+1章
        // 定向补救，第3章缺失→第4章一次性「开篇收尾」）。applyRange=1 只影响目标章，
        // AgentDirectives 内建指纹去重防重复注入。无法判定（正文过短）不误报。
        self::safeRun($novelId, '黄金三章核验', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/GoldenThreeChapters.php';
            $g3ChNum = (int)($chapter['chapter_number'] ?? 0);
            if ($g3ChNum < 1 || $g3ChNum > 3 || !GoldenThreeChapters::enabled()) return;

            $result = GoldenThreeChapters::verifyWrittenChapter($g3ChNum, $fullContent, $chapter);
            if ($result === null) return;

            if ($result['passed']) {
                addLog($novelId, 'info', "黄金三章：第{$g3ChNum}章开篇里程碑达成", (int)($chapter['id'] ?? 0));
                return;
            }

            $misses = implode('、', $result['misses']);
            addLog($novelId, 'warn',
                "黄金三章：第{$g3ChNum}章里程碑未达成（缺失：{$misses}）",
                (int)($chapter['id'] ?? 0));
            try {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $remedies = [
                    1 => "第1章开篇里程碑未达成（缺失：{$misses}）。本章（第2章）必须补足：让金手指/核心机遇显性亮相并首次显效，开篇200字内抛出悬念或危机。",
                    2 => "第2章首次兑现里程碑未达成（缺失：{$misses}）。本章（第3章）必须完整兑现首个爽点（压抑-释放全流程），并让核心冲突显性化。",
                    3 => "第3章追读锚点里程碑未达成（缺失：{$misses}）。开篇收尾：本章（第4章）必须让核心冲突具象化（反派/长期目标清晰亮相），完成黄金三章补救，随后回到正常节奏。",
                ];
                if (isset($remedies[$g3ChNum])) {
                    AgentDirectives::add(
                        $novelId,
                        $g3ChNum + 1,
                        'quality',
                        $remedies[$g3ChNum],
                        1,   // applyRange=1：一次性补救，只影响目标章
                        24   // 24 小时过期
                    );
                }
            } catch (\Throwable $e) { /* 指令写入失败不影响主流程 */ }
        });

        // --- 审计功能（2026-08-16 P3-金手指设计模板）：金手指使用代价核验 ---
        // 正文出现金手指名称≥2次视为使用场景，检测 cost/limit 关键词覆盖
        // （仿转折点覆盖模式）；零命中 → 下一章一次性修正指令。名称未出现/
        // 无设定 → null 不误报（本章可能未使用金手指）。
        self::safeRun($novelId, '金手指代价核验', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/GoldenFinger.php';
            $gf = new GoldenFinger($novelId);
            $result = $gf->verifyWrittenChapter((int)($chapter['chapter_number'] ?? 0), $fullContent);
            if ($result === null) return;

            if ($result['passed']) {
                addLog($novelId, 'info', "金手指核验：本章使用场景已体现代价/限制（提及{$result['uses']}次）", (int)($chapter['id'] ?? 0));
                return;
            }

            addLog($novelId, 'warn',
                "金手指核验：本章使用场景未体现代价/限制（提及{$result['uses']}次）",
                (int)($chapter['id'] ?? 0));
            try {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                AgentDirectives::add(
                    $novelId,
                    (int)($chapter['chapter_number'] ?? 0) + 1,
                    'quality',
                    "上一章金手指使用场景（共{$result['uses']}次提及）未体现任何代价/限制。本章必须补上负面反馈：让角色承受使用代价（伤势/消耗/损失等）或触发限制失灵，并让该后果影响后续剧情走向；禁止无代价开挂。",
                    1,   // applyRange=1：一次性修正，只影响下一章
                    24   // 24 小时过期
                );
            } catch (\Throwable $e) { /* 指令写入失败不影响主流程 */ }
        });

        // --- 知识库提取（重排后基于重写后的最终正文提取；原先在重写前跑，存在内容不一致）---
        self::safeRun($novelId, '知识库提取', function() use ($novelId, $chapter, $fullContent, $parallelBatch) {
            require_once __DIR__ . '/embedding.php';
            $kb = new KnowledgeBase($novelId);
            $kbStats = $parallelBatch !== null && $parallelBatch->has('knowledge')
                ? $parallelBatch->apply('knowledge', static fn(array $result): array =>
                    $kb->applyChapterExtraction((int)$chapter['chapter_number'], $fullContent, (int)$chapter['id'], $result))
                : $kb->extractFromChapter((int)$chapter['chapter_number'], $fullContent, (int)($chapter['id'] ?? 0));
            $kbStats ??= [];
            if (!empty(array_filter($kbStats))) {
                addLog($novelId, 'info', '知识库提取完成：角色' . ($kbStats['characters']??0) . '个，世界观' . ($kbStats['worldbuilding']??0) . '个，情节' . ($kbStats['plots']??0) . '个');
            }
        }, 'error');

        // --- 质量检测 ---
        // 未启用重写：正常跑；启用重写但未触发重写：重写前已跑，跳过；
        // 启用重写且触发了重写：最终正文必须完整重跑五关，不能复用初稿的
        // 人物、爽点和一致性结果，否则质量元数据仍会对应旧版本。
        if (!$rewriteEnabled) {
            self::runQualityGates(
                $novelId,
                $chId,
                $fullContent,
                $policySnapshot->qualityCheckEnabled()
            );
        } elseif ($wasRewritten) {
            self::runQualityGates(
                $novelId,
                $chId,
                $fullContent,
                $policySnapshot->qualityCheckEnabled()
            );
        }

        self::safeRun($novelId, '意境冷却落盘', function () use ($novelId, $chNum, $fullContent, $novelData) {
            require_once __DIR__ . '/SceneAtmosphereBuilder.php';
            SceneAtmosphereBuilder::commitPendingIfUsed(
                $novelId,
                $chNum,
                $fullContent,
                (string)($novelData['genre'] ?? '')
            );
        });

        // --- v1.9 CriticAgent + StyleGuard — 统一纳入 AgentCoordinator ---
        self::safeRun($novelId, '后置Agent协调器', function() use ($novelId, $chapter, $fullContent, $novelData, $parallelBatch) {
            require_once __DIR__ . '/agents/AgentCoordinator.php';
            $agentContext = [
                'title'            => $novelData['title'] ?? '',
                'genre'            => $novelData['genre'] ?? '',
                'protagonist_name' => $novelData['protagonist_name'] ?? '',
                'model_id'         => $novelData['model_id'] ?? null,
            ];
            if ($parallelBatch !== null && $parallelBatch->has('critic')) {
                try {
                    $parallelBatch->apply('critic', static function (array $result) use ($novelId, $chapter, $fullContent, $agentContext): array {
                        $published = AgentCoordinator::postWriteAgents($novelId, $chapter, $fullContent, $agentContext, $result);
                        if (!empty($result['scores']) && empty($published['critic']['scores'])) {
                            throw new RuntimeException('Critic result publication failed.');
                        }
                        return $published;
                    });
                } catch (PostprocessComputationFailedException $error) {
                    addLog($novelId, 'warn', $error->getMessage(), (int)$chapter['id']);
                    // Preserve local StyleGuard/Dialogue checks even if optional AI review fails.
                    AgentCoordinator::postWriteAgents($novelId, $chapter, $fullContent, $agentContext, ['scores' => []]);
                }
            } else {
                AgentCoordinator::postWriteAgents($novelId, $chapter, $fullContent, $agentContext);
            }
        });

        // --- v1.5 情绪密度检测（激活 EmotionDictionary）---
        // 之前 EmotionDictionary 模块完全是死代码，prompt 里教 AI 满足情绪密度
        // 但写完后从未验证。本节将统计落盘，并在偏低时让 Agent 写指令影响下一章
        self::safeRun($novelId, '情绪密度检测', function() use ($novelId, $chapter, $chId, $fullContent) {
            require_once __DIR__ . '/emotion_dict.php';
            $emoDensity = EmotionDictionary::countEmotionDensity($fullContent);
            $emoEval    = EmotionDictionary::evaluateDensity($emoDensity);

            DB::update('chapters', [
                'emotion_density' => json_encode($emoDensity, JSON_UNESCAPED_UNICODE),
                'emotion_score'   => (float)$emoEval['overall_score'],
            ], 'id=?', [$chId]);

            addLog($novelId, 'info', sprintf(
                '情绪密度：得分 %.1f/100（%d 项问题）',
                $emoEval['overall_score'],
                count($emoEval['issues'] ?? [])
            ));

            // 偏低时写一条 Agent 指令影响下章
            if ($emoEval['overall_score'] < 60 && !empty($emoEval['issues'])) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $issuesText = implode('；', array_slice($emoEval['issues'], 0, 2));
                AgentDirectives::addCapped(
                    $novelId,
                    (int)$chapter['chapter_number'] + 1,
                    'quality',
                    "前章情绪密度偏低（得分{$emoEval['overall_score']}）。问题：{$issuesText}。本章须加大相应情绪锚点（动作/微表情），勿堆砌情绪词。",
                    3,  // 持续 3 章
                    24, // 24 小时过期
                    2
                );
            }
        });

        // --- v1.10.3 情绪曲线异常检测（每10章触发）---
        self::safeRun($novelId, '情绪曲线异常检测', function() use ($novelId, $chNum) {
            if ($chNum > 0 && $chNum % 10 === 0) {
                $emotionAnomaly = detectEmotionCurveAnomaly($novelId);
                if ($emotionAnomaly) {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    $msg = match ($emotionAnomaly['type']) {
                        'low_emotion_streak' => "近10章情绪均值仅" . round($emotionAnomaly['avg']) . "分，持续低位（建议<50分需干预）。请在本章安排高强度情绪事件（冲突/反转/危机），打破低潮。",
                        'flat_emotion_curve' => "近10章情绪方差仅" . round($emotionAnomaly['variance']) . "，起伏极小，读者疲劳。本章必须有明显的情绪高低峰落差。",
                        default => '情绪曲线异常，请注意情绪节奏。',
                    };
                    AgentDirectives::addCapped(
                        $novelId, $chNum + 1, 'quality',
                        "情绪曲线告警：{$msg}",
                        3, 48, 2
                    );
                    addLog($novelId, 'info', sprintf(
                        '情绪曲线异常检测：%s（均值%.1f，方差%.1f）',
                        $emotionAnomaly['type'], $emotionAnomaly['avg'], $emotionAnomaly['variance']
                    ));
                }
            }
        });

        // --- v1.11.5 角色情绪异常跳变检测（事后检测）---
        self::safeRun($novelId, '情绪异常跳变检测', function() use ($novelId, $chNum) {
            require_once __DIR__ . '/memory/CharacterEmotionRepo.php';
            $emotionRepo = new CharacterEmotionRepo($novelId);
            $emotionAnomalies = $emotionRepo->detectEmotionAnomalies($chNum);
            if (!empty($emotionAnomalies)) {
                $highAnomalies = array_filter($emotionAnomalies, fn($a) => $a['severity'] === 'high');
                if (!empty($highAnomalies)) {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    $msg = implode('；', array_map(fn($a) => $a['message'], array_slice($highAnomalies, 0, 2)));
                    AgentDirectives::addCapped(
                        $novelId, $chNum + 1, 'quality',
                        "情绪断裂告警：{$msg}。下章请安排合理的情绪过渡或回调。",
                        2, 24, 2
                    );
                }
                addLog($novelId, 'info', sprintf(
                    '情绪异常跳变检测：%d项异常（高危%d）',
                    count($emotionAnomalies),
                    count($highAnomalies)
                ));
            }
        });

        // --- v1.6 爽点实际类型检测（P1#7: 反馈闭环）---
        // 之前 calculateCoolPointSchedule 的 lastUsed 记录的是"计划排期"
        // 而非 AI 实际写到的类型。本节用关键词匹配检测正文中实际出现的爽点类型
        // v1.5.2: 关键词检测无命中时回退到 LLM summary 给出的类型
        self::safeRun($novelId, '爽点检测', function() use ($novelId, $chId, $fullContent, $summaryData) {
            $llmJudgedType = (isset($summaryData) && is_array($summaryData))
                ? ($summaryData['cool_point_type'] ?? null)
                : null;
            $actualCoolTypes = detectCoolPointTypes($fullContent, $llmJudgedType);
            DB::update('chapters', [
                'actual_cool_point_types' => !empty($actualCoolTypes)
                    ? json_encode($actualCoolTypes, JSON_UNESCAPED_UNICODE)
                    : null,
            ], 'id=?', [$chId]);

            if (!empty($actualCoolTypes)) {
                $typeNames = array_map(fn($t) => COOL_POINT_TYPES[$t]['name'] ?? $t, $actualCoolTypes);
                addLog($novelId, 'info', sprintf(
                    '爽点检测：识别到 %d 种类型 —— %s',
                    count($actualCoolTypes),
                    implode('、', $typeNames)
                ));
            }
        });

        // --- v1.6 开篇类型实际检测（P1#7: 反馈闭环）---
        // 检测正文实际使用的开篇类型，与 suggestOpeningType 建议的 opening_type 对比
        // 可发现 AI 写作时是否偏离了推荐的开篇策略
        self::safeRun($novelId, '开篇检测', function() use ($novelId, $chId, $fullContent) {
            $actualOpening = detectOpeningType($fullContent);
            if (!empty($actualOpening['type'])) {
                DB::update('chapters', [
                    'actual_opening_type' => $actualOpening['type']
                ], 'id=?', [$chId]);
                addLog($novelId, 'info', sprintf(
                    '开篇检测：识别为 %s（%s）',
                    $actualOpening['type'],
                    OPENING_TYPES[$actualOpening['type']]['name'] ?? $actualOpening['type']
                ));
            } else {
                // R10-7：无可靠命中时清空陈旧 actual，避免防重复吃脏数据
                DB::update('chapters', ['actual_opening_type' => null], 'id=?', [$chId]);
            }
        });

        // --- v1.11 场景模板检测（语义级防套路化）---
        self::safeRun($novelId, '场景模板检测', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/memory/SceneTemplateRepo.php';
            $sceneTemplates = detectSceneTemplates($fullContent);
            if (!empty($sceneTemplates)) {
                $stRepo = new SceneTemplateRepo($novelId);
                $saved = $stRepo->batchAdd($sceneTemplates, (int)$chapter['chapter_number']);
                $tplNames = array_map(fn($t) => SCENE_TEMPLATES[$t]['name'] ?? $t, $sceneTemplates);
                addLog($novelId, 'info', sprintf(
                    '场景模板检测：识别到 %d 种 —— %s（入库%d条）',
                    count($sceneTemplates),
                    implode('、', $tplNames),
                    $saved
                ));
            }
        });

        // --- v1.5 收尾期合规检查（激活 EndingEnforcer.checkEndingCompliance）---
        // 之前该方法是死代码，收尾期 AI 可能继续埋新伏笔/写新支线，系统不会发现
        self::safeRun($novelId, '收尾合规检查', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/ending_enforcer.php';
            $enforcer = new EndingEnforcer($novelId, (int)$chapter['chapter_number']);
            if ($enforcer->needsEndingEnforcement()) {
                $compliance = $enforcer->checkEndingCompliance($fullContent);

                if (!empty($compliance['issues'])) {
                    $stage = $enforcer->getEndingStage();
                    $issues = implode('；', array_slice($compliance['issues'], 0, 3));
                    addLog($novelId, 'warn', sprintf(
                        '收尾合规警告（%s阶段）：%s',
                        $stage, $issues
                    ));

                    // 让下一章 prompt 注意修正
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    AgentDirectives::addCapped(
                        $novelId,
                        (int)$chapter['chapter_number'] + 1,
                        'quality',
                        "前章收尾合规警告（{$stage}阶段）：{$issues}。本章必须按收尾阶段规则写作，回收旧伏笔，禁止引入新支线。",
                        2,
                        24,
                        2
                    );
                }
            }
        });

        // --- Agent 指令效果反馈闭环（v1.5） ---
        self::safeRun($novelId, 'Agent反馈闭环', function() use ($novelId, $chapter) {
            require_once __DIR__ . '/agents/AgentDirectives.php';
            $outcomeResult = AgentDirectives::recordOutcomes($novelId, (int)$chapter['chapter_number']);
            if ($outcomeResult['recorded'] > 0) {
                $improved = count(array_filter($outcomeResult['outcomes'], fn($o) => $o['quality_change'] > 0));
                addLog($novelId, 'info', sprintf(
                    'Agent反馈闭环：评估%d条指令效果，%d条正向改善',
                    $outcomeResult['recorded'], $improved
                ));
            }
            // 审计修复（2026-08-09 R11）：cleanup 此前零调用方，过期/停用行只增不减。
            // 每 20 章物理清理一次 30 天前的过期与停用指令，防库表膨胀。
            $chNum = (int)$chapter['chapter_number'];
            if ($chNum > 0 && $chNum % 20 === 0) {
                $cleaned = AgentDirectives::cleanup($novelId);
                if ($cleaned > 0) {
                    addLog($novelId, 'info', "Agent指令清理：删除{$cleaned}条过期/停用指令");
                }
            }
        });

        // === v41 角色语音指纹生成（每 N 章为缺指纹的主要角色补差异化语音规则）===
        self::safeRun($novelId, '角色语音指纹生成', function() use ($novelId, $chNum, $novelData) {
            $vpInterval = max(3, (int)getSystemSetting('ws_voice_profile_interval', 12, 'int'));
            // 半周期错峰（默认 6,18,30…）：避开全书圣经/控制器/体检的触发章，减少同章重型任务叠加
            if ($chNum > 0 && getSystemSetting('ws_voice_profile_enabled', true, 'bool')
                && $chNum % $vpInterval === intdiv($vpInterval, 2)) {
                require_once __DIR__ . '/VoiceProfileGenerator.php';
                VoiceProfileGenerator::generateMissing($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        });

        // === PID控制器（工程控制论：P/I/D整定）===
        // 每章写完后对4个核心控制变量做PID评估，产生智能调控建议
        self::safeRun($novelId, 'PID控制器', function() use ($novelId, $chNum, $chapter) {
            require_once __DIR__ . '/PIDController.php';
            $pid = new PIDController($novelId);

            $recentScores = DB::fetchAll(
                'SELECT quality_score, emotion_score FROM chapters
                 WHERE novel_id=? AND chapter_number <= ? AND (quality_score IS NOT NULL OR emotion_score IS NOT NULL)
                 ORDER BY chapter_number DESC LIMIT 1',
                [$novelId, $chNum]
            );
            $currentQuality = $recentScores[0]['quality_score'] ?? null;
            $currentEmotion = $recentScores[0]['emotion_score'] ?? null;

            $targetWords = (int)($chapter['words'] ?? 0) > 0
                ? (int)(DB::fetch('SELECT chapter_words FROM novels WHERE id=?', [$novelId])['chapter_words'] ?? 2000)
                : 0;
            $actualWords = (int)($chapter['words'] ?? 0);
            $wordAccuracy = ($targetWords > 0 && $actualWords > 0)
                ? round(min(1.0, $actualWords / $targetWords), 3)
                : null;

            // 审计修复（2026-08-09 R8）：优先实测 actual_cool_point_types，空则回退计划列
            $recentCool = DB::fetchAll(
                'SELECT cool_point_type, actual_cool_point_types FROM chapters
                 WHERE novel_id=? AND chapter_number BETWEEN ? AND ? AND status="completed"
                 ORDER BY chapter_number DESC LIMIT 10',
                [$novelId, max(1, $chNum - 9), $chNum]
            );
            $coolCount = 0;
            foreach ($recentCool as $rc) {
                $actual = json_decode((string)($rc['actual_cool_point_types'] ?? ''), true);
                if (is_array($actual) && !empty($actual)) {
                    $coolCount++;
                } elseif (!empty($rc['cool_point_type'])) {
                    $coolCount++;
                }
            }
            $coolDensity = count($recentCool) > 0
                ? round($coolCount / count($recentCool), 3)
                : null;

            $pidResults = $pid->evaluateAll([
                'quality_score'  => $currentQuality,
                'emotion_score'  => $currentEmotion,
                'word_count_accuracy' => $wordAccuracy,
                'cool_point_density'  => $coolDensity,
            ]);

            $criticalIssues = array_filter($pidResults, fn($r) => $r['severity'] === 'critical');
            if (!empty($criticalIssues)) {
                $vars = array_keys($criticalIssues);
                $msgs = array_map(fn($v, $r) => $r['recommendation'], $vars, $criticalIssues);
                require_once __DIR__ . '/agents/AgentDirectives.php';
                AgentDirectives::addCapped(
                    $novelId, $chNum + 1, 'quality',
                    implode('；', $msgs),
                    2, 24, 2
                );
                addLog($novelId, 'info', sprintf(
                    'PID控制器：%d项严重偏差，已写入指令',
                    count($criticalIssues)
                ));
            }
        });

        // === 参数自适应调优（工程控制论：自适应控制）===
        // 每 10 章分析历史数据，自动调整迭代参数
        if ($chNum > 0 && $chNum % 10 === 0) {
            self::safeRun($novelId, '参数自适应调优', function() use ($novelId, $chNum) {
                require_once __DIR__ . '/AdaptiveParameterTuner.php';
                $tuner = new AdaptiveParameterTuner($novelId);
                $tuner->tune($chNum);
            });
        }

        // === 全书级控制器（工程控制论：层级控制）===
        // 每 20 章触发一次，做全书级5项检查
        self::safeRun($novelId, '全书级控制器', function() use ($novelId, $chNum, $novelData) {
            require_once __DIR__ . '/GlobalNovelController.php';
            $globalResult = GlobalNovelController::regulate(
                $novelId,
                $chNum,
                $novelData['model_id'] ?? null
            );
            if ($globalResult['triggered']) {
                $directivesWritten = $globalResult['directives'];
                $checkSummary = [];
                foreach ($globalResult['checks'] as $name => $check) {
                    if (!empty($check['issue'])) {
                        $checkSummary[] = $name . ':有问题';
                    } elseif ($check['checked'] ?? false) {
                        $checkSummary[] = $name . ':正常';
                    }
                }
                addLog($novelId, 'info', sprintf(
                    '全书级控制器执行：%d项检查，%d条全局指令（%s）',
                    count($globalResult['checks']),
                    $directivesWritten,
                    implode(', ', $checkSummary)
                ));
            }
        });

        // === 系统健康监控（工程控制论：鲁棒性）===
        // 每 20 章执行一次系统体检
        if ($chNum > 0 && $chNum % 20 === 0) {
            self::safeRun($novelId, '系统健康监控', function() use ($novelId) {
                require_once __DIR__ . '/SystemHealthMonitor.php';
                $healthMonitor = new SystemHealthMonitor($novelId);
                $healthResult = $healthMonitor->check();
                if ($healthResult['score'] < 70) {
                    foreach ($healthResult['alerts'] as $alert) {
                        addLog($novelId, 'warn', sprintf(
                            '系统健康告警[%s]：%s',
                            $alert['level'] ?? 'warning',
                            $alert['message']
                        ));
                    }
                }
                if (!empty($healthResult['alerts'])) {
                    addLog($novelId, 'info', sprintf(
                        '系统健康分：%d/100，%d条告警',
                        $healthResult['score'], count($healthResult['alerts'])
                    ));
                }
            });
        }

        // （pp_pending 标志已在约束状态更新后提前清除，见上方「后处理重排」说明）

        // === v41 全书圣经（每 N 章增量压缩，根治中段遗忘）===
        self::safeRun($novelId, '全书圣经更新', function() use ($novelId, $chNum, $novelData) {
            $bibleInterval = max(5, (int)getSystemSetting('ws_bible_interval', 20, 'int'));
            if ($chNum > 0 && getSystemSetting('ws_story_bible_enabled', true, 'bool')
                && $chNum % $bibleInterval === 0) {
                require_once __DIR__ . '/memory/StoryBible.php';
                StoryBible::regenerate($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        });

        // === v41 全书一致性体检（每 N 章一次的宏观审计，存报告供作者查看）===
        self::safeRun($novelId, '全书体检', function() use ($novelId, $chNum, $novelData) {
            $auditInterval = max(10, (int)getSystemSetting('ws_fullbook_audit_interval', 50, 'int'));
            // 半周期错峰（默认 25,75…）：与全书圣经(%20===0)/控制器(%20===10)在默认值下两两不撞
            if ($chNum > 0 && getSystemSetting('ws_fullbook_audit_enabled', true, 'bool')
                && $chNum % $auditInterval === intdiv($auditInterval, 2)) {
                require_once __DIR__ . '/FullBookAudit.php';
                FullBookAudit::run($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        });

        // W-7 修复：Arc 摘要从 daemon_write 迁移到 postProcess()，让所有写作路径（SSE/async/daemon）一致积累 L2 记忆
        self::safeRun($novelId, '弧段摘要生成', function() use ($novelId, $chapter) {
            $chNumForArc = (int)$chapter['chapter_number'];
            if ($chNumForArc % 10 === 0 && $chNumForArc > 0) {
                $arcFrom = max(1, $chNumForArc - 9);
                $novelForArc = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
                if ($novelForArc) {
                    require_once __DIR__ . '/memory.php';
                    if (function_exists('generateAndSaveArcSummary')) {
                        if (generateAndSaveArcSummary($novelForArc, $arcFrom, $chNumForArc)) {
                            addLog($novelId, 'info', "弧段摘要已生成：第{$arcFrom}-{$chNumForArc}章");
                        } else {
                            addLog($novelId, 'warn', "弧段摘要未完成：第{$arcFrom}-{$chNumForArc}章，请稍后重试");
                        }
                    }
                }
            }
        }, 'error');

        // 审计功能（2026-08-16 P3-卷级摘要）：卷末触发卷级摘要生成（L2 再压缩层）。
        // 独立于上方弧段块的 chNum%10 条件——卷末与 10 章边界不必然重合
        // （向导自定义卷边界 / API 链路 40 章卷）；无卷书由 resolveVolumeBounds
        // 走 50 章回退分组。卷中（is_end=false）或卷数据不一致（null）不触发；
        // 生成失败静默（下次卷末触发 / 重优化链 rebuildAllVolumeSummaries 自动补）。
        self::safeRun($novelId, '卷级摘要生成', function() use ($novelId, $chNum, $novelData) {
            if ($chNum <= 0 || !getSystemSetting('ws_volume_summary_enabled', true, 'bool')) return;
            require_once __DIR__ . '/memory.php';
            if (!function_exists('resolveVolumeBounds') || !function_exists('generateAndSaveVolumeSummary')) return;
            $bounds = resolveVolumeBounds($novelId, $chNum);
            if ($bounds === null || empty($bounds['is_end'])) return;
            $vol  = (int)$bounds['volume_number'];
            $from = (int)$bounds['chapter_from'];
            $to   = (int)$bounds['chapter_to'];
            if (generateAndSaveVolumeSummary($novelId, $vol, $from, $to, $novelData['model_id'] ?? null)) {
                addLog($novelId, 'info', "卷级摘要已生成：第{$vol}卷（第{$from}-{$to}章）");
            }
        }, 'error');

        addLog($novelId, 'info', "第{$chapter['chapter_number']}章后处理完成（摘要/记忆/知识库/质检）");
        $keepPpPending = false;
        return self::postProcessOutcome(
            'processed',
            $effectiveRevisionHash,
            $effectiveCandidateUid,
            $successorJobId
        );
        } finally {
            if ($parallelBatch !== null) {
                try { $parallelBatch->close(); }
                catch (Throwable $closeError) { error_log('[postprocess-parallel] cleanup: ' . $closeError->getMessage()); }
            }
            // 性能观测汇总：总耗时 + 最慢前 8 步。提前返回的无步骤路径（版本过期/
            // 幂等跳过等）不输出，避免噪音。
            $postProcessTotalSec = HarnessRuntime::preciseTime() - $postProcessStartedAt;
            if (self::$postProcessTimings !== [] || $postProcessTotalSec >= 1.0) {
                $timings = self::$postProcessTimings;
                arsort($timings);
                $topSteps = [];
                $rank = 0;
                foreach ($timings as $label => $sec) {
                    if (++$rank > 8) break;
                    $topSteps[] = sprintf('%s %.1fs', $label, $sec);
                }
                addLog($novelId, 'info', sprintf(
                    '第%d章后处理总耗时%.1fs%s',
                    $chNum,
                    $postProcessTotalSec,
                    $topSteps === [] ? '' : '（最慢：' . implode('；', $topSteps) . '）'
                ), $chId);
            }
            if (!$keepPpPending) {
                self::clearPostProcessPending($novelId, $chNum);
            }
            self::$postProcessHeartbeat = $prevHeartbeat;
        }
    }

    /**
     * 质量五门检测：计算并落盘 quality_score / gate_results。
     * 启用重写时在 RewriteAgent 之前跑（它依赖这两个字段）；
     * 未启用时移到 pp_pending 窗口外跑，不阻塞下一章。
     *
     * W-19 修复：返回结构化结果 ['gates' => array, 'score' => float]，
     * 调用方（RewriteAgent 路径）可直接复用，避免两次 SELECT 重查。
     */
    private static function runQualityGates(
        int $novelId,
        int $chId,
        string $fullContent,
        bool $qualityCheckEnabled
    ): array
    {
        $output = ['gates' => [], 'score' => 100.0];
        try {
            // 后处理可能在写作数分钟后才运行，必须消费启动时冻结的开关，
            // 不能重新读取 ws_quality_check_enabled 令同一任务随配置漂移。
            if (!$qualityCheckEnabled) {
                addLog($novelId, 'info', '质量检测已由冻结写作策略关闭，跳过');
                return $output;
            }
            // 修复：从 includes 层加载，不再反向依赖 api/validate_consistency.php
            // （api 文件含 HTTP 副作用，CLI worker 加载需要 CLI_MODE 守卫，分层不清）
            // 修复（2026-06-18）：确保 countWords() 可用（Gates.php 结构检查依赖此函数）
            if (!function_exists('countWords')) {
                require_once __DIR__ . '/helpers.php';
            }
            require_once __DIR__ . '/quality/Gates.php';

            $vChapter = DB::fetch(
                'SELECT c.*, n.genre, n.chapter_words, n.writing_style '
                . 'FROM chapters c JOIN novels n ON c.novel_id = n.id '
                . 'WHERE c.id = ? AND c.novel_id = ?',
                [$chId, $novelId]
            );
            $vContent = $vChapter['content'] ?? $fullContent;

            if ($vChapter && !empty(trim($vContent))) {
                $results = [];
                $results[] = checkGate1_Structure($vChapter, $vContent);
                $results[] = checkGate2_Characters($novelId, $vContent);
                $results[] = checkGate3_Description($vChapter['genre'] ?? null, $vContent);
                $results[] = checkGate4_CoolPoint($vContent, $vChapter['outline'] ?? null, $vChapter);
                $results[] = checkGate5_Consistency($chId, $novelId, $vContent);
                $results[] = checkGate6_OutlineAdherence($vChapter, $vContent);

                $scores = array_column($results, 'score');
                $avgScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

                // 五关结果会覆写 gate_results；保留 saveChapter/重写复检写入的
                // hard_fail_reason，供章节列表持续显示“质检未过”警告。
                $persistedResults = $results;
                $previousGates = is_string($vChapter['gate_results'] ?? null)
                    ? json_decode((string)$vChapter['gate_results'], true)
                    : null;
                if (is_array($previousGates)) {
                    foreach ($previousGates as $previousGate) {
                        if (is_array($previousGate) && !empty($previousGate['hard_fail_reason'])) {
                            $persistedResults[] = $previousGate;
                        }
                    }
                }

                $qUpdates = [];
                if ($avgScore > 0) { $qUpdates['quality_score'] = (float)$avgScore; }
                $qUpdates['gate_results'] = json_encode($persistedResults, JSON_UNESCAPED_UNICODE);
                if (!empty($qUpdates)) {
                    DB::update('chapters', $qUpdates, 'id=?', [$chId]);
                }
                addLog($novelId, 'info', sprintf('质量检测：总分 %.1f/100', $avgScore));

                try {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    $chNum = (int)($vChapter['chapter_number'] ?? 0);
                    $nextCh = $chNum + 1;
                    foreach ($results as $gate) {
                        if (!empty($gate['status'])) continue;
                        $name = (string)($gate['name'] ?? '');
                        $issueLine = implode('；', array_slice((array)($gate['issues'] ?? []), 0, 2));
                        if ($issueLine === '' || $nextCh <= 0) continue;
                        if (str_contains($name, '跟纲')) {
                            AgentDirectives::addCapped(
                                $novelId,
                                $nextCh,
                                'quality',
                                '上章跟纲不足：' . $issueLine . '。本章必须把未兑现的情节点自然补上或给出合理转场，禁止再开无关支线。',
                                1,
                                48,
                                2
                            );
                        } elseif (str_contains($name, '连贯')) {
                            AgentDirectives::addCapped(
                                $novelId,
                                $nextCh,
                                'quality',
                                '上章因果链问题：' . $issueLine . '。本章禁止死人无交代复出；若必须提及，只用回忆/幻象/遗物。',
                                1,
                                48,
                                2
                            );
                        }
                    }
                } catch (Throwable $e) {
                    addLog($novelId, 'warn', '质量关指令回灌跳过：' . $e->getMessage());
                }

                $output['gates'] = $results;
                $output['score'] = (float)$avgScore;
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '质量检测跳过：' . $e->getMessage());
        }
        return $output;
    }

    /**
     * Agent决策：在写作前运行Agent决策流程
     *
     * 审计修复 M-3（2026-06-18）：加文件锁防止并发重复决策。
     * 原实现在 resolveChapter 最开头调用，此时章节尚未置 writing，
     * 两个并发请求可能同时进入 runPreWriteAgents，导致 agent_decision_logs
     * 重复写入（BaseAgent::logDecision 直接 INSERT 无去重）。
     * 现用基于章节号的文件锁串行化，第二个请求检测到锁存在则跳过（决策已由
     * 先到者执行），不影响写作主流程。
     *
     * P0-3 优化（2026-06-18）：接受 $chNum 参数，避免内部 getCurrentChapterNumber
     * 重复查询且与实际写作章节错位。调用方在僵死清理后传入已确定的章节号。
     *
     * @param int $novelId 小说ID
     * @param int $chNum   已确定的待写章节号（由 resolveChapter 传入）
     * @return void
     */
    public static function runPreWriteAgents(int $novelId, int $chNum = 0): void
    {
        if ($chNum <= 0) {
            $chNum = self::getCurrentChapterNumber($novelId);
        }
        // daemon 等直调入口也必须经过屏障；屏障失败不能被 Agent 的可选失败处理吞掉。
        self::waitForPostProcess($novelId, $chNum - 1);
        self::assertNoDegradedPostProcess($novelId, $chNum - 1);
        try {
            if (!ConfigCenter::get('agent.enabled', true)) {
                return;
            }

            // P0-3：优先使用传入的章节号；未传入时回退到查询（保持向后兼容）
            if ($chNum <= 0) {
                $chNum = self::getCurrentChapterNumber($novelId);
            }

            // 调度只保留一层：AgentCoordinator 内部按 3/4/5/8 等动态间隔决定
            // 本章需要运行哪些 Agent。外层再用 5/10/20 过滤会让内部计划永远失效。

            // M-3 修复：基于章节号的非阻塞文件锁，防止并发重复决策
            // 锁文件名含章节号，确保每个章节的决策周期独立加锁
            // 锁在请求结束时自动释放（fclose 或 PHP shutdown）
            $lockFile = CFG_PROGRESS_DIR . "/agent_lock_n{$novelId}_c{$chNum}.lock";
            $lockFp = @fopen($lockFile, 'c');
            if (!$lockFp) {
                addLog($novelId, 'debug', "Agent决策锁文件无法创建，跳过：{$lockFile}");
                return;
            }
            if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
                // 已有其他进程在跑此章节的 Agent 决策，跳过
                fclose($lockFp);
                addLog($novelId, 'debug', "第{$chNum}章 Agent 决策已由其他进程执行，跳过");
                return;
            }

            try {
                require_once __DIR__ . '/agents/AgentCoordinator.php';

                $coordinator = new AgentCoordinator($novelId);

                $context = [
                    'pending_foreshadowing_count' => self::countPendingForeshadowings($novelId, $chNum),
                    'recent_chapters' => self::getRecentChapters($novelId, 5, $chNum),
                    'current_progress' => self::getCurrentProgress($novelId, $chNum),
                    'current_chapter_number' => $chNum,
                ];

                $decisionResult = $coordinator->runDecisionCycle($context);

                if (!empty($decisionResult['execution_summary'])) {
                    $summary = $decisionResult['execution_summary'];
                    addLog($novelId, 'info', sprintf(
                        'Agent决策完成：决策%d次，执行%d个动作，成功%d个',
                        $summary['total_decisions'],
                        $summary['total_actions'],
                        $summary['successful_actions']
                    ));
                }
            } finally {
                // 释放锁并清理锁文件
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                @unlink($lockFile);
            }

        } catch (Throwable $e) {
            addLog($novelId, 'warn', 'Agent决策失败：' . $e->getMessage());
        }
    }
    
    /**
     * 统计待回收伏笔数量
     */
    private static function countPendingForeshadowings(int $novelId, int $beforeChapter): int
    {
        try {
            $result = DB::fetch(
                'SELECT COUNT(*) as cnt FROM foreshadowing_items WHERE novel_id = ? AND planted_chapter < ? AND (resolved_chapter IS NULL OR resolved_chapter >= ?)',
                [$novelId, $beforeChapter, $beforeChapter]
            );
            return (int)($result['cnt'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取最近的章节
     */
    private static function getRecentChapters(int $novelId, int $limit, int $beforeChapter): array
    {
        try {
            // 审计修复 PERF-C2（2026-07-01）：列裁剪，近章上下文只需元数据 + 尾文
            // 补 updated_at 列（2026-07-22）：AgentCoordinator::estimateWritingSpeed()
            // 依赖 recent_chapters[*]['updated_at']，缺列时挂机路径全部 fallback 到 time()，
            // 与 daemon_write.php 的 recent_chapters 查询保持列集一致。
            return DB::fetchAll(
                'SELECT id, novel_id, chapter_number, title, outline, key_points, hook, pacing, status, content, words, quality_score, gate_results, updated_at FROM chapters WHERE novel_id = ? AND status = "completed" AND chapter_number < ? ORDER BY chapter_number DESC LIMIT ?',
                [$novelId, $beforeChapter, $limit]
            );
        } catch (Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取当前进度
     */
    private static function getCurrentProgress(int $novelId, int $beforeChapter): float
    {
        try {
            $novel = DB::fetch('SELECT target_chapters FROM novels WHERE id = ?', [$novelId]);
            $target = (int)($novel['target_chapters'] ?? 0);
            
            if ($target <= 0) return 0;
            
            return min(1.0, max(0, $beforeChapter - 1) / $target);
        } catch (Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取当前章节号（下一个待写章节，与 resolveChapter 的选章逻辑一致）
     *
     * 注意不能用 MAX(chapter_number)+1：大纲全量生成后 chapters 表已有全部章节行，
     * MAX+1 恒等于"目标章数+1"且不随写作进度变化，导致 runPreWriteAgents 的
     * %5/10/20 触发条件恒真或恒假——Agent 决策周期实际从未按进度执行。
     */
    private static function getCurrentChapterNumber(int $novelId): int
    {
        try {
            $nextId = self::findNextAutomaticChapterId($novelId);
            $next = $nextId
                ? DB::fetch(
                    'SELECT chapter_number FROM chapters WHERE id=? AND novel_id=?',
                    [$nextId, $novelId]
                )
                : null;
            if ($next) return (int)$next['chapter_number'];

            // 全部写完：退回 MAX+1（保持原兜底语义）
            $chapter = DB::fetch(
                'SELECT COALESCE(MAX(chapter_number), 0) + 1 as next_chapter FROM chapters WHERE novel_id = ?',
                [$novelId]
            );
            return (int)($chapter['next_chapter'] ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    // =================================================================
    // v1.7 PRO: 图谱关系增量同步
    // =================================================================

    /**
     * 从章节正文中提取实体关系三元组并存入 story_relations
     * 同时标记 graph_start_chapter（首次触发时）
     *
     * @param int    $novelId    小说ID
     * @param int    $chNum      章节号
     * @param string $content    章节正文
     * @param int|null $modelId  AI模型ID
     */
    private static function extractAndStoreGraphRelations(int $novelId, int $chNum, string $content, ?int $modelId = null): void
    {
        // 标记 graph_start_chapter（首次启用图谱功能时）
        try {
            $state = DB::fetch("SELECT graph_start_chapter FROM novel_state WHERE novel_id=?", [$novelId]);
            if (!$state || !isset($state['graph_start_chapter']) || $state['graph_start_chapter'] === null) {
                $existing = DB::fetch("SELECT novel_id FROM novel_state WHERE novel_id=?", [$novelId]);
                if ($existing) {
                    DB::update('novel_state', ['graph_start_chapter' => $chNum], 'novel_id=?', [$novelId]);
                } else {
                    DB::insert('novel_state', ['novel_id' => $novelId, 'graph_start_chapter' => $chNum, 'last_ingested_chapter' => 0]);
                }
                addLog($novelId, 'info', "图谱关系构建起始章节已标记为第{$chNum}章");
            }
        } catch (\Throwable $e) {
            // 标记失败不阻塞关系提取
        }

        // 提取章节正文的前 4000 字（避免 token 过长）
        $truncated = mb_substr($content, 0, 4000);
        if (mb_strlen($truncated) < 100) return;

        // 获取已知角色名列表（供 AI 参考）
        $charNames = [];
        try {
            $names = DB::fetchAll("SELECT name FROM character_cards WHERE novel_id=?", [$novelId]);
            $charNames = array_column($names, 'name');
        } catch (\Throwable $e) { error_log('write_engine charNames fetch failed: ' . $e->getMessage()); }
        $charList = !empty($charNames) ? '已知角色: ' . implode(', ', array_slice($charNames, 0, 20)) : '';

        $novel = DB::fetch("SELECT title, protagonist_name FROM novels WHERE id=?", [$novelId]);
        $title = sanitizeForPrompt((string)($novel['title'] ?? ''));
        $protagonist = sanitizeForPrompt((string)($novel['protagonist_name'] ?? ''));
        $charList    = sanitizeForPrompt($charList);

        $sys = "你是小说《{$title}》的实体关系提取器。主角为「{$protagonist}」。"
            . "从章节正文中提取角色、物品、组织之间的关键关系，输出三元组。"
            . "只提取对后续写作有长期价值的关系（拥有/击杀/结盟/师徒/敌对/爱情/隶属等）。"
            . "严禁编造未出现在正文中的关系。{$charList}";

        $user = "【第{$chNum}章正文片段】\n{$truncated}\n\n"
            . "请提取本章中的实体关系三元组。要求：\n"
            . "1. source 和 target 为实体名（角色/物品/组织/地点）\n"
            . "2. relation 为关系谓词（英文：owns/killed/allied_with/mentors/enemies/loves/belongs_to/located_at/member_of 等）\n"
            . "3. desc 为一句话描述（中文）\n"
            . "4. 只输出重要的关系，不要提取琐碎的（如对话中的称呼）\n"
            . "5. 无关系则输出空数组 []\n"
            . "6. 严格输出 JSON 数组：\n"
            . "   [{\"source\":\"林枫\",\"relation\":\"owns\",\"target\":\"无双剑\",\"desc\":\"林枫意外获得了上古神兵无双剑\"}, ...]";

        try {
            $ai = getAIClient($modelId);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            // 容错解析 JSON 数组
            $relations = self::parseGraphJson($raw);
            if ($relations === null || !is_array($relations)) return;

            $validRelations = [];
            $seen = [];
            foreach ($relations as $rel) {
                if (!is_array($rel)) continue;
                $source = trim((string)($rel['source'] ?? ''));
                $relation = trim((string)($rel['relation'] ?? ''));
                $target = trim((string)($rel['target'] ?? ''));
                $desc = trim((string)($rel['desc'] ?? ''));

                if ($source === '' || $relation === '' || $target === '') continue;

                $key = $source . "\0" . $relation . "\0" . $target;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $validRelations[] = [$source, $relation, $target, $desc];
            }

            if (!empty($relations) && empty($validRelations)) return;

            if (!DB::beginTransaction()) {
                throw new \RuntimeException('无法开启图谱关系替换事务');
            }
            try {
                DB::execute(
                    "DELETE FROM story_relations WHERE novel_id=? AND source_chapter=?",
                    [$novelId, $chNum]
                );
                foreach ($validRelations as [$source, $relation, $target, $desc]) {
                    DB::execute(
                        "INSERT INTO story_relations (novel_id, source_entity, relation_type, target_entity, source_chapter, description)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$novelId, $source, $relation, $target, $chNum, $desc]
                    );
                }
                if (!DB::commit()) {
                    throw new \RuntimeException('图谱关系替换事务提交失败');
                }
            } catch (\Throwable $writeError) {
                try {
                    DB::rollBack();
                } catch (\Throwable $rbErr) {
                    // 审计修复 M10（2026-08-16）：回滚失败意味着事务可能仍挂着锁，
                    // 会阻塞后续写作——必须留痕供运维排查（原实现静默吞掉）。
                    error_log('write_engine relation rollback failed (novel=' . $novelId . ', ch=' . $chNum . '): ' . $rbErr->getMessage());
                }
                throw $writeError;
            }

            $inserted = count($validRelations);
            addLog($novelId, 'info', "图谱关系同步：第{$chNum}章替换为{$inserted}条关系三元组");
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '图谱关系AI提取失败：' . $e->getMessage());
        }
    }

    // =================================================================
    // v1.7 PRO: Blueprint 代理（行文蓝图化）
    // =================================================================

    /**
     * Blueprint 代理：将粗略大纲细化为包含感官细节、对话要点、转折节点的写作蓝图
     *
     * @param array  $novel        小说信息
     * @param array  $chapter      章节信息（含大纲）
     * @param string $previousTail 前章尾文
     * @param ?array $memoryCtx    记忆上下文
     * @return string|null 细化后的蓝图，失败返回 null
     */
    private static function runBlueprint(array $novel, array $chapter, string $previousTail, ?array $memoryCtx): ?string
    {
        $novelId = (int)$novel['id'];
        $chNum   = (int)$chapter['chapter_number'];
        $outline = trim((string)($chapter['outline'] ?? ''));
        $title   = trim((string)($chapter['title'] ?? ''));

        if ($outline === '' || mb_strlen($outline) > 2000) return null; // 大纲为空或已足够详细则跳过

        $genre       = $novel['genre'] ?? '都市';
        $protagonist = $novel['protagonist_name'] ?? '主角';
        $style       = $novel['writing_style'] ?? '';

        // 前章尾文截取（给 Blueprint 代理参考衔接）
        $tailSnippet = mb_substr($previousTail, 0, 500);

        // 图谱关联上下文（如果有）
        $graphContext = '';
        if (!empty($memoryCtx['semantic_hits'])) {
            $graphHits = array_filter($memoryCtx['semantic_hits'], fn($h) => ($h['source'] ?? '') === 'graph');
            if (!empty($graphHits)) {
                $graphLines = [];
                foreach (array_slice($graphHits, 0, 5) as $hit) {
                    $graphLines[] = $hit['content'];
                }
                $graphContext = "【相关图谱关系】\n" . implode("\n", $graphLines) . "\n";
            }
        }

        $sys = "你是一位资深网文策划师，擅长将粗略大纲细化为可执行的写作蓝图。"
            . "小说类型：{$genre}，主角：{$protagonist}。"
            . ($style !== '' ? "写作风格参考：{$style}。" : "")
            . "输出的蓝图将被直接用于指导AI写手撰写正文。";

        $user = "请将以下粗略大纲细化为详细的写作蓝图。\n\n"
            . "【第{$chNum}章标题】{$title}\n"
            . "【粗略大纲】{$outline}\n\n"
            . ($tailSnippet !== '' ? "【前章结尾（衔接参考）】{$tailSnippet}\n\n" : "")
            . $graphContext
            . "请输出细化后的写作蓝图，要求：\n"
            . "1. **场景规划**：本章涉及几个场景，每个场景的时间/地点/氛围\n"
            . "2. **核心转折**：本章的关键转折点或冲突升级点\n"
            . "3. **对话要点**：列出 2-3 段关键对话的参与者和要点（不需要写完整对话）\n"
            . "4. **感官细节**：每个场景需要突出的视觉/听觉/触觉/嗅觉细节\n"
            . "5. **情绪走向**：本章的情绪曲线（从什么情绪开始→经过什么变化→以什么情绪结束）\n"
            . "6. **字数分配建议**：各场景/段落的大致字数占比\n"
            . "控制在 600 字以内，精炼不啰嗦。直接输出蓝图内容，不要加前缀说明。";

        try {
            $ai = getAIClient($novel['model_id'] ?? null);
            $blueprint = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            if (mb_strlen($blueprint) < 100) return null;
            return $blueprint;
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', 'Blueprint AI 调用失败：' . $e->getMessage());
            return null;
        }
    }

    /** 容错解析图谱 JSON（数组或 {"relations": [...]} 格式） */
    private static function parseGraphJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        // 去 markdown 代码块
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            // 如果是数组直接返回
            if (empty($data) || isset($data[0])) return $data;
            // 如果是对象且有 relations 字段
            if (isset($data['relations']) && is_array($data['relations'])) return $data['relations'];
            return null;
        }
        // 截取首个 [ 到末个 ]
        $s = strpos($raw, '[');
        $e = strrpos($raw, ']');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
