<?php
/**
 * 写作章节 CLI 入口 — 绕过 Nginx/FPM 超时限制
 * 
 * 用法：php write_chapter_worker.php <novel_id> <chapter_id> <task_id>
 * 
 * 由 write_start.php 通过 exec() 后台启动，
 * 写作进度写入进度文件，前端通过 write_poll.php 轮询。
 * 
 * 此脚本通过 PHP CLI 运行，不受 Nginx/FPM 超时限制。
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// v1.8: CLI 模式硬校验——防止 HTTP 直接访问绕过登录
// 此脚本通过 CliContext::activate() 显式声明 CLI 上下文，
// auth.php 检测到后短路登录校验，不再依赖伪造 $_SESSION['logged_in']。
// 必须确保仅 CLI 模式可入。HTTP 访问立刻 403 退出。
require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardWorker();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI mode only');
}

// output_buffering 是 PHP_INI_PERDIR 级别，ini_set() 无法修改
// 改用 ob_end_clean() 在运行时清除缓冲区
while (ob_get_level()) ob_end_clean();

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/harness/support/HarnessRuntime.php';

// 审计修复 R7（2026-08-10）：提前初始化 shutdown 函数依赖的全局变量为 null，
// 防止 require 阶段（如 config.php / CliContext.php）发生 fatal 时变量未定义，
// 导致 shutdown 函数内 global 声明获取到未初始化值。L81 的 null 检查会安全返回。
$asyncProgressFile = null;
$asyncTaskId = null;
$writingTaskLease = null;

// 审计优化 P3-9（2026-06-16）：激活 CLI 上下文，替代伪造 $_SESSION['logged_in']
// CliContext::activate() 内部会定义 CLI_MODE 常量并标记上下文活跃
require_once dirname(__DIR__) . '/includes/CliContext.php';
CliContext::activate();

// CLI 模式下不需要 session，但 auth.php 会调用 session_start()
// 提前模拟 session 已启动以避免报错
if (session_status() === PHP_SESSION_NONE) {
    // 在 CLI 下 session_start() 可能失败，但不影响写作
    @session_start();
}

// 模拟 HTTP 环境（部分库依赖这些 $_SERVER 字段）
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/api/write_chapter.php';

// 仅先加载 config.php 拿到 CFG_PROGRESS_DIR；重型依赖(ai/write_engine)留到上报 'writing' 之后再加载。
require_once dirname(__DIR__) . '/config.php';

// CLI 参数（提前解析，供尽早写入 'writing' 状态用）
$novelId   = (int)($argv[1] ?? 0);
$chapterId = (int)($argv[2] ?? 0);
$taskId    = preg_replace('/[^a-zA-Z0-9_]/', '', $argv[3] ?? '');

if (!$novelId || !$taskId) {
    error_log("[write_worker] 缺少参数: novel_id={$novelId}, task_id={$taskId}");
    exit(1);
}

// 初始化异步进度
$progressDir = CFG_PROGRESS_DIR;
$asyncProgressFile = $progressDir . '/' . $taskId . '.json';
$asyncTaskId = $taskId;
$asyncMessages = [];

if (!file_exists($asyncProgressFile)) {
    error_log("[write_worker] 进度文件不存在: {$asyncProgressFile}");
    exit(1);
}

// 致命错误兜底：在加载重型依赖之前就注册，确保 require 阶段的 fatal 也能写回 'error'（不会让前端卡死）
register_shutdown_function(function() {
    global $asyncProgressFile, $asyncTaskId, $writingTaskLease;
    $err = error_get_last();
    if ($err === null) return;
    if (!in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) return;
    if (!$asyncProgressFile || !file_exists($asyncProgressFile)) return;
    $progress = writeWorkerReadProgressSnapshot();
    $lease = writeWorkerLeaseCarrier();
    // 真正终态只有 done/error。saved/postprocessing 期间若 fatal，必须转入显式 error，
    // 否则轮询端会永远停在中间态；同时保留“正文已保存”的精确信息。
    $previousStatus = (string)($progress['status'] ?? '');
    if ($lease !== null
        && writeWorkerProgressMatchesLease($progress, $lease)
        && in_array($previousStatus, ['done', 'error'], true)) {
        return;
    }
    if ($lease === null
        || !class_exists('DB', false)
        || !class_exists('WritingTaskRepository', false)) {
        // require/boot 阶段尚未取得 durable lease 时，不能用一个未经认证的
        // shutdown 写覆盖已被其他 generation 接管的共享进度文件。
        error_log('[write_worker] fatal before exact task lease; progress publication skipped');
        return;
    }
    if (!writeWorkerProgressMatchesLease($progress, $lease)) {
        $progress = writeWorkerFreshProgressForLease($progress);
        $previousStatus = '';
    }
    try {
        // A fatal may interrupt the Canon transaction itself. Roll it back
        // before reading durable evidence or attempting the terminal task CAS.
        if (DB::inTransaction() && !DB::rollBack()) {
            throw new RuntimeException('Unable to roll back fatal worker transaction.');
        }
        // The compatibility file can still say `writing` after the Canon
        // transaction committed. Only the exact task generation plus its
        // committed Candidate/current Canon hashes may prove content_saved.
        $durableCanon = WritingTaskRepository::authenticatedCommittedCanonForLease(
            (string)$asyncTaskId,
            (string)$lease['lease_owner'],
            (string)$lease['lease_token'],
            (int)$lease['fence_generation']
        );
    } catch (Throwable $canonEvidenceError) {
        error_log('[write_worker] fatal Canon authentication failed: ' . $canonEvidenceError->getMessage());
        return;
    }
    $contentSaved = is_array($durableCanon);
    if ($contentSaved) {
        $progress['novel_id'] = (int)$durableCanon['novel_id'];
        $progress['chapter_id'] = (int)$durableCanon['chapter_id'];
    }
    $errMsg = ($err['message'] ?? 'unknown fatal error') . " in {$err['file']}:{$err['line']}";
    // Fatal recovery must not acquire a new failure mode when the OS entropy
    // provider (or a deterministic test seam) is unavailable.
    $rid = HarnessRuntime::diagnosticHex(6);
    $progress['status'] = 'error';
    $progress['error']  = $contentSaved
        ? '正文已保存，但章节后处理失败（追踪号 ' . $rid . '）'
        : '后台写作进程异常，请稍后重试（追踪号 ' . $rid . '）';
    $progress['content_saved'] = $contentSaved;
    $progress['postprocess_failed'] = $contentSaved;
    $progress['progress'] = $contentSaved ? 100 : (int)($progress['progress'] ?? 0);
    $progress['request_id'] = $rid;
    $progress['updated_at'] = HarnessRuntime::unixTime();

    try {
        $terminalPersisted = WritingTaskRepository::markFailed(
            (string)$asyncTaskId,
            (string)$progress['error'],
            $contentSaved,
            false,
            'worker_fatal',
            (string)$lease['lease_owner'],
            (string)$lease['lease_token'],
            (int)$lease['fence_generation']
        );
    } catch (Throwable $taskStateError) {
        error_log('[write_worker] fatal durable task update failed: ' . $taskStateError->getMessage());
        return;
    }
    if (!$terminalPersisted) {
        error_log('[write_worker] lease_lost during fatal terminal fence; progress publication skipped');
        return;
    }
    try {
        // 终态契约：exact DB CAS 成功后才允许发布兼容进度文件。
        writeWorkerPublishTerminalProgress($progress, $lease);
    } catch (Throwable $progressError) {
        error_log('[write_worker] fatal progress publication failed: ' . $progressError->getMessage());
    }

    if ($contentSaved) {
        $flagNovelId = (int)($progress['novel_id'] ?? 0);
        $flagChapterNumber = (int)($progress['chapter_number'] ?? 0);
        if ($flagNovelId > 0 && $flagChapterNumber > 0) {
            @unlink(CFG_PROGRESS_DIR . "/pp_pending_{$flagNovelId}_{$flagChapterNumber}.flag");
        }
    }
    if ($contentSaved && class_exists('DB', false)) {
        try {
            writeWorkerSetNovelStatusAfterTerminal(
                (int)($progress['novel_id'] ?? 0),
                'paused'
            );
            $fatalChapterId = (int)($progress['chapter_id'] ?? 0);
            if ($fatalChapterId > 0 && class_exists('PostprocessJobRepository', false)) {
                $fatalChapter = DB::fetch(
                    'SELECT novel_id, content, canonical_state, content_revision_hash,
                            write_policy_snapshot_json, canonical_candidate_uid
                     FROM chapters WHERE id=? AND status="completed" LIMIT 1',
                    [$fatalChapterId]
                );
                if ($fatalChapter && (string)($fatalChapter['content'] ?? '') !== '') {
                    $fatalContent = (string)$fatalChapter['content'];
                    $fatalRevision = hash('sha256', $fatalContent);
                    $fatalSnapshot = null;
                    $fatalCandidateUid = null;
                    $fatalRewriteAllowed = false;
                    $fatalSnapshotJson = $fatalChapter['write_policy_snapshot_json'] ?? null;
                    if (is_string($fatalSnapshotJson) && $fatalSnapshotJson !== '') {
                        if (!class_exists('WritePolicySnapshot', false)) {
                            require_once dirname(__DIR__) . '/includes/quality/WritePolicySnapshot.php';
                        }
                        $fatalSnapshot = WritePolicySnapshot::fromJson($fatalSnapshotJson);
                    }
                    if ((string)($fatalChapter['canonical_state'] ?? '') === 'canonical') {
                        $storedRevision = $fatalChapter['content_revision_hash'] ?? null;
                        $fatalCandidateUid = $fatalChapter['canonical_candidate_uid'] ?? null;
                        if ($fatalSnapshot === null
                            || !is_string($storedRevision)
                            || preg_match('/^[a-f0-9]{64}$/D', $storedRevision) !== 1
                            || !is_string($fatalCandidateUid)
                            || preg_match('/^[a-f0-9]{32}$/D', $fatalCandidateUid) !== 1
                            || !hash_equals($fatalRevision, $storedRevision)) {
                            throw new RuntimeException(
                                'Fatal recovery refused an unauthenticated Canon revision.'
                            );
                        }
                        $fatalCandidate = DB::fetch(
                            'SELECT source, lifecycle, content_hash, policy_snapshot_hash
                             FROM chapter_candidates
                             WHERE candidate_uid=? AND chapter_id=? AND novel_id=? LIMIT 1',
                            [$fatalCandidateUid, $fatalChapterId, (int)$fatalChapter['novel_id']]
                        );
                        if (!is_array($fatalCandidate)
                            || (string)($fatalCandidate['lifecycle'] ?? '') !== 'committed'
                            || !is_string($fatalCandidate['content_hash'] ?? null)
                            || !hash_equals($fatalRevision, $fatalCandidate['content_hash'])
                            || !is_string($fatalCandidate['policy_snapshot_hash'] ?? null)
                            || !hash_equals($fatalSnapshot->checksum(), $fatalCandidate['policy_snapshot_hash'])) {
                            throw new RuntimeException(
                                'Fatal recovery refused inconsistent Candidate ownership.'
                            );
                        }
                        $fatalSource = (string)($fatalCandidate['source'] ?? '');
                        if (!in_array($fatalSource, ['initial', 'rewrite'], true)) {
                            throw new RuntimeException('Fatal recovery found an invalid Candidate source.');
                        }
                        $fatalRewriteAllowed = $fatalSource === 'initial';
                    }
                    PostprocessJobRepository::enqueue(
                        (int)$fatalChapter['novel_id'],
                        $fatalChapterId,
                        $fatalRevision,
                        'full',
                        3,
                        $fatalSnapshot,
                        is_string($fatalCandidateUid) ? $fatalCandidateUid : null,
                        $fatalRewriteAllowed
                    );
                }
            }
            if (function_exists('clearNovelCache')) {
                clearNovelCache((int)($progress['novel_id'] ?? 0));
            }
        } catch (\Throwable $stateError) {
            error_log('[write_worker] fatal state recovery failed: ' . $stateError->getMessage());
        }
    }
    error_log("[write_worker] 致命错误: {$errMsg}");
});

// 共享进度文件只能在 durable claim 成功后发布；write_start 的迟到租约
// 复核负责覆盖 CLI 冷启动超过确认窗口的场景。
// 重型依赖加载完成后，worker 会先取得 exact lease，再上报 writing。
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/tasks/WritingTaskRepository.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';
require_once dirname(__DIR__) . '/includes/quality/WritePolicySnapshotFactory.php';
require_once dirname(__DIR__) . '/includes/harness/runtime/bootstrap.php';
require_once dirname(__DIR__) . '/includes/harness/stages/LegacyWriteEngineStageAdapter.php';

// config.php 加载后方可使用其常量
set_time_limit(CFG_TIME_UNLIMITED);  // CLI 模式不限时
ignore_user_abort(true);

$workerStartTime = HarnessRuntime::unixTime();
$workerGlobalTimeout = 1800;
// 审计修复（2026-08-17 P3）：后处理中间态标记。后处理阶段无正文 chunk（$lastContentTime
// 停更），非流式 AI 调用仅靠周期心跳触发全局超时检查；若仍按写作阶段 1800s 上限硬杀，
// exit(1) 会跳过常规清理路径导致 pp_pending_{novel}_{ch}.flag 残留，阻塞下一章启动。
$_inPostProcessPhase = false;
$_workerContentSaved = false;

// ---- 引入 write_chapter.php 的核心逻辑 ----
// 不能直接 require，因为 headers 已发。我们只复用函数定义。

$lastHeartbeat = HarnessRuntime::unixTime();
$_writingChapterId = null;

// 写入缓冲：攒一批 token 再刷新进度文件，减少 I/O 压力
$chunkBuffer = '';
$chunkBufferCount = 0;
$lastFlushTime = HarnessRuntime::preciseTime();
const CHUNK_FLUSH_INTERVAL = 0.15;   // 至少 0.15 秒刷新一次
const CHUNK_FLUSH_COUNT = 3;     // 至少 3 个 token 刷新一次

// Task 2：统一写作进度状态机。任务真正终态只有 done / error；
// saved（正文已落盘）与 postprocessing（后处理进行中）是中间态，前端应继续轮询，
// poll 不得据此删除进度文件、updateAsyncProgress 不得据此触发 .content 终态合并。
const WRITE_PROGRESS_TERMINAL_STATUSES = ['done', 'error'];

function isWriteProgressTerminalStatus(?string $status): bool
{
    return in_array((string)$status, WRITE_PROGRESS_TERMINAL_STATUSES, true);
}

final class WriteWorkerLeaseLostException extends RuntimeException
{
}

/** @return array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string}|null */
function writeWorkerLeaseCarrier(): ?array
{
    global $asyncTaskId, $writingTaskLease;
    if (!is_string($asyncTaskId) || $asyncTaskId === '' || !is_array($writingTaskLease)) {
        return null;
    }
    $taskId = $writingTaskLease['task_id'] ?? null;
    $owner = $writingTaskLease['lease_owner'] ?? null;
    $token = $writingTaskLease['lease_token'] ?? null;
    $generation = $writingTaskLease['fence_generation'] ?? null;
    $expiresAt = $writingTaskLease['lease_expires_at'] ?? null;
    if (!is_string($taskId) || !hash_equals($asyncTaskId, $taskId)
        || !is_string($owner) || $owner === ''
        || !is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
        || filter_var($generation, FILTER_VALIDATE_INT) === false || (int)$generation <= 0
        || !is_string($expiresAt) || $expiresAt === '') {
        return null;
    }
    return [
        'task_id' => $taskId,
        'lease_owner' => $owner,
        'lease_token' => $token,
        'fence_generation' => (int)$generation,
        'lease_expires_at' => $expiresAt,
    ];
}

/** @param array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string} $lease */
function writeWorkerProgressMatchesLease(array $progress, array $lease): bool
{
    $taskId = $progress['_task_id'] ?? null;
    $owner = $progress['_lease_owner'] ?? null;
    $token = $progress['_lease_token'] ?? null;
    $generation = $progress['_fence_generation'] ?? null;
    return is_string($taskId) && hash_equals($lease['task_id'], $taskId)
        && is_string($owner) && hash_equals($lease['lease_owner'], $owner)
        && is_string($token) && hash_equals($lease['lease_token'], $token)
        && filter_var($generation, FILTER_VALIDATE_INT) !== false
        && (int)$generation === $lease['fence_generation'];
}

function writeWorkerFreshProgressForLease(array $previous): array
{
    $fresh = array_intersect_key($previous, array_flip([
        'chapter_id', 'novel_id', 'user_id', 'task_sig', 'started_at',
    ]));
    return array_merge($fresh, [
        'status' => 'starting',
        'progress' => 0,
        'content' => '',
        'thinking_content' => '',
        'messages' => [],
        'model_used' => null,
        'words' => 0,
        'content_revision' => (int)($previous['content_revision'] ?? 0) + 1,
        '_content_split' => false,
    ]);
}

/** @param array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string} $lease */
function writeWorkerStampProgress(array $progress, array $lease): array
{
    $progress['_task_id'] = $lease['task_id'];
    $progress['_lease_owner'] = $lease['lease_owner'];
    $progress['_lease_token'] = $lease['lease_token'];
    $progress['_fence_generation'] = $lease['fence_generation'];
    $progress['updated_at'] = HarnessRuntime::unixTime();
    return $progress;
}

function writeWorkerReadProgressSnapshot(): array
{
    global $asyncProgressFile;
    if (!is_string($asyncProgressFile) || !is_file($asyncProgressFile)) {
        return [];
    }
    $fp = @fopen($asyncProgressFile, 'r');
    if ($fp === false) {
        return [];
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return [];
    }
    try {
        $decoded = json_decode((string)stream_get_contents($fp), true);
        return is_array($decoded) ? $decoded : [];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function writeWorkerStopForLeaseLoss(string $context): void
{
    error_log('[write_worker] lease_lost at ' . $context . '; stale worker exiting without progress publication');
    exit(0);
}

/**
 * 审计修复 P0（2026-09-27）：cURL 回调内禁止 exit/throw 的致命退出通道。
 *
 * sendHeartbeatWrite 会经 AIClient 的 CURLOPT_WRITE/PROGRESSFUNCTION 回调执行。
 * 在回调内直接 exit 会跨越 libcurl 的内部 C 帧触发 bailout（Windows CLI 下有
 * 进程级闪退风险），且跳过全部 shutdown 清理，章节只能靠 300 秒僵尸回收。
 *
 * 致命条件（租约丢失/全局超时）现在只登记标志：
 *   1. AIClient::setFatalAbortProbe 注册的探测器在下一个回调边界发现标志，
 *      以 CURLE_WRITE_ERROR 安全中止进行中的传输；
 *   2. 异常在 curl_exec 之外抛出（AIRequestCanceledException 通道）；
 *   3. 清理与退出由 writeWorkerAbortIfFatal() 在 cURL 之外的统一安全点执行。
 */
function writeWorkerRequestFatalAbort(string $reason, int $exitCode, string $detail = ''): void
{
    if (isset($GLOBALS['writeWorkerFatalAbort']) && is_array($GLOBALS['writeWorkerFatalAbort'])) {
        return; // 首个致命条件优先
    }
    $GLOBALS['writeWorkerFatalAbort'] = [
        'reason' => $reason,
        'exit_code' => $exitCode,
        'detail' => $detail,
    ];
}

function writeWorkerFatalAbortState(): ?array
{
    return isset($GLOBALS['writeWorkerFatalAbort'])
        && is_array($GLOBALS['writeWorkerFatalAbort'])
        ? $GLOBALS['writeWorkerFatalAbort']
        : null;
}

/**
 * 统一的致命退出安全点：只在 cURL 之外调用（主循环阶段边界 / streamWrite 与
 * saveChapter 的 catch 处）。global_timeout 走完整的超时清理链（与原
 * sendHeartbeatWrite 内联逻辑一致）；lease_lost 只按认领令牌幂等释放章节，
 * 绝不触碰共享进度文件或任务行——新 claimant 已拥有它们。
 */
function writeWorkerAbortIfFatal(): void
{
    $abort = writeWorkerFatalAbortState();
    if ($abort === null) {
        return;
    }
    global $_writingChapterId, $ch, $novelId, $_inPostProcessPhase;
    if (($abort['reason'] ?? '') === 'global_timeout') {
        $elapsed = (string)($abort['detail'] ?? '0');
        flushChunkBuffer();
        if ($_writingChapterId > 0) {
            try {
                // 审计 P0：按认领令牌精确释放，防误释放他人重新认领的同章
                WriteEngine::releaseChapterClaim($_writingChapterId, is_array($ch) ? $ch : []);
            } catch (\Throwable $e) {
                error_log('worker status reset failed: ' . $e->getMessage());
            }
        }
        updateAsyncProgress(['status' => 'error', 'error' => "写作全局超时（{$elapsed}秒），已自动恢复"]);
        // Returning from terminal publication proves that this exact lease won
        // markFailed. The guarded post-terminal update cannot pause a new task.
        writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
        // exit 跳过常规清理路径：后处理中间态时显式清理 pp_pending flag
        // （与 Phase 6 失败路径同款），防止残留阻塞下一章启动。
        if (!empty($_inPostProcessPhase) && is_array($ch)) {
            try {
                WriteEngine::clearPostProcessPending($novelId, (int)$ch['chapter_number']);
            } catch (\Throwable $ppFlagError) {
                error_log('worker timeout postprocess flag cleanup failed: ' . $ppFlagError->getMessage());
            }
        }
        exit(1);
    }
    // lease_lost：与 writeWorkerStopForLeaseLoss 语义一致——只释放章节认领，
    // 不碰共享进度文件/任务行，静默退出交由新 claimant 接管。
    if ($_writingChapterId > 0 && is_array($ch)) {
        try {
            WriteEngine::releaseChapterClaim($_writingChapterId, $ch);
        } catch (\Throwable $releaseError) {
            error_log('[write_worker] fatal lease_lost chapter release failed: ' . $releaseError->getMessage());
        }
    }
    exit(0);
}

/**
 * Change the novel lifecycle only while this exact task generation is still
 * current. The predicate is part of the UPDATE statement, so cancellation or
 * reclaim cannot cross a check-then-write window.
 */
function writeWorkerSetNovelStatusWhileLeaseCurrent(
    string $context,
    int $novelId,
    string $status
): bool {
    $lease = writeWorkerLeaseCarrier();
    if ($lease === null || !in_array($status, ['draft', 'paused'], true)) {
        writeWorkerStopForLeaseLoss($context);
    }
    if (DB::inTransaction()) {
        throw new RuntimeException("{$context}: novel finalization cannot borrow a transaction.");
    }

    $projectionFence = NovelProjectionFence::acquire($novelId, 30);
    $startedTransaction = false;
    $leaseLost = false;
    $changed = false;
    try {
        if (!DB::beginTransaction()) {
            throw new RuntimeException("{$context}: unable to start novel finalization transaction.");
        }
        $startedTransaction = true;
        $novel = DB::fetch('SELECT status FROM novels WHERE id=? LIMIT 1 FOR UPDATE', [$novelId]);
        if (!is_array($novel)) {
            throw new RuntimeException("{$context}: novel was not found.");
        }
        // Novel -> exact task matches write_start; the projection fence keeps
        // chapter/task -> novel mutation paths from overlapping this order.
        $owned = DB::fetch(
            "SELECT task_id FROM writing_tasks
             WHERE task_id=? AND novel_id=?
               AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             LIMIT 1 FOR UPDATE",
            [
                $lease['task_id'],
                $novelId,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );
        if (!is_array($owned)) {
            $leaseLost = true;
        } elseif ((string)($novel['status'] ?? '') === 'writing') {
            $changed = DB::execute(
                "UPDATE novels SET status=? WHERE id=? AND status='writing'",
                [$status, $novelId]
            ) === 1;
        }
        if (!DB::commit()) {
            throw new RuntimeException("{$context}: unable to commit novel finalization.");
        }
        $startedTransaction = false;
    } finally {
        if ($startedTransaction && DB::inTransaction()) {
            DB::rollBack();
        }
        NovelProjectionFence::release($projectionFence);
    }
    if ($leaseLost) {
        writeWorkerStopForLeaseLoss($context);
    }
    return $changed;
}

/**
 * A successful exact terminal CAS authorizes cleanup, but a new task may start
 * immediately afterwards. Serialize on the novel row and refuse to overwrite
 * any newly active durable task or any freshly claimed chapter.
 */
function writeWorkerSetNovelStatusAfterTerminal(int $novelId, string $status): bool
{
    if ($novelId <= 0 || !in_array($status, ['draft', 'paused'], true)) {
        return false;
    }
    if (DB::inTransaction()) {
        throw new RuntimeException('Post-terminal novel finalization cannot borrow a transaction.');
    }

    $projectionFence = NovelProjectionFence::acquire($novelId, 30);
    $startedTransaction = false;
    $changed = false;
    try {
        if (!DB::beginTransaction()) {
            throw new RuntimeException('Unable to start post-terminal novel finalization.');
        }
        $startedTransaction = true;
        // Global chapters -> novels -> writing_tasks order (see write_start).
        // The fence-free claim heartbeat locks chapter -> task, so taking the
        // task row before the chapter here would form a 1213 deadlock cycle.
        $writingChapter = DB::fetch(
            "SELECT id FROM chapters
             WHERE novel_id=? AND status='writing'
             ORDER BY id LIMIT 1 FOR UPDATE",
            [$novelId]
        );
        $novel = DB::fetch('SELECT status FROM novels WHERE id=? LIMIT 1 FOR UPDATE', [$novelId]);
        if (!is_array($novel)) {
            throw new RuntimeException('Post-terminal novel finalization could not find the novel.');
        }
        $activeTask = DB::fetch(
            "SELECT task_id FROM writing_tasks
             WHERE novel_id=? AND cancel_requested=0
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [$novelId]
        );
        if (!is_array($activeTask)
            && !is_array($writingChapter)
            && (string)($novel['status'] ?? '') === 'writing') {
            $changed = DB::execute(
                "UPDATE novels SET status=? WHERE id=? AND status='writing'",
                [$status, $novelId]
            ) === 1;
        }
        if (!DB::commit()) {
            throw new RuntimeException('Unable to commit post-terminal novel finalization.');
        }
        $startedTransaction = false;
    } finally {
        if ($startedTransaction && DB::inTransaction()) {
            DB::rollBack();
        }
        NovelProjectionFence::release($projectionFence);
    }
    return $changed;
}

/**
 * Authenticate the exact worker generation and hold the durable task row lock
 * for the complete shared-file mutation. A cancel/reclaim therefore cannot
 * cross the DB-check/file-write boundary.
 */
function writeWorkerWithExactLease(string $context, callable $callback): void
{
    $lease = writeWorkerLeaseCarrier();
    if ($lease === null || !class_exists('DB', false) || !class_exists('WritingTaskRepository', false)) {
        writeWorkerStopForLeaseLoss($context);
    }

    $startedTransaction = false;
    try {
        if (!DB::inTransaction()) {
            if (!DB::beginTransaction()) {
                throw new RuntimeException('Unable to start progress fence transaction.');
            }
            $startedTransaction = true;
        }
        $owned = DB::fetch(
            "SELECT task_id FROM writing_tasks
             WHERE task_id=? AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND cancel_requested=0 AND lease_expires_at>=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             LIMIT 1 FOR UPDATE",
            [
                $lease['task_id'],
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation'],
                HarnessRuntime::sqlNow(),
            ]
        );
        if (!is_array($owned)) {
            throw new WriteWorkerLeaseLostException($context);
        }

        $callback($lease);
        if ($startedTransaction && !DB::commit()) {
            throw new RuntimeException('Unable to commit progress fence transaction.');
        }
    } catch (WriteWorkerLeaseLostException $leaseLost) {
        if ($startedTransaction && DB::inTransaction()) {
            DB::rollBack();
        }
        writeWorkerStopForLeaseLoss($context);
    } catch (Throwable $error) {
        if ($startedTransaction && DB::inTransaction()) {
            DB::rollBack();
        }
        throw $error;
    }
}

/**
 * @param callable(array,array,string):array $mutation
 * @return array<string,mixed>|null
 */
function writeWorkerMutateActiveProgress(
    string $context,
    callable $mutation,
    bool $requireFile = false
): ?array {
    global $asyncProgressFile;
    $published = null;
    writeWorkerWithExactLease($context, function(array $lease) use (
        $context,
        $mutation,
        $requireFile,
        &$published,
        $asyncProgressFile
    ): void {
        if (!is_string($asyncProgressFile) || !is_file($asyncProgressFile)) {
            if ($requireFile) {
                throw new RuntimeException("{$context}: progress file is unavailable.");
            }
            return;
        }
        $fp = @fopen($asyncProgressFile, 'r+');
        if ($fp === false) {
            throw new RuntimeException("{$context}: unable to open progress file.");
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException("{$context}: unable to lock progress file.");
        }
        try {
            $decoded = json_decode((string)stream_get_contents($fp), true);
            $progress = is_array($decoded) ? $decoded : [];
            $contentFile = $asyncProgressFile . '.content';
            if (!writeWorkerProgressMatchesLease($progress, $lease)) {
                $progress = writeWorkerFreshProgressForLease($progress);
                if (file_put_contents($contentFile, '', LOCK_EX) === false) {
                    throw new RuntimeException("{$context}: unable to reset stale generation content.");
                }
            }
            $progress = $mutation($progress, $lease, $contentFile);
            if (!is_array($progress)) {
                throw new RuntimeException("{$context}: progress mutation returned invalid data.");
            }
            $progress = writeWorkerStampProgress($progress, $lease);
            $encoded = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                throw new RuntimeException("{$context}: unable to encode progress data.");
            }
            fseek($fp, 0);
            if (!ftruncate($fp, 0) || fwrite($fp, $encoded) === false || !fflush($fp)) {
                throw new RuntimeException("{$context}: unable to publish progress data.");
            }
            $published = $progress;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    });
    return $published;
}

/** @param array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string} $lease */
function writeWorkerSyncHeartbeat(array $progress, array $lease): void
{
    global $asyncTaskId;
    $status = (string)($progress['status'] ?? 'writing');
    $durableState = match ($status) {
        'starting' => 'queued',
        'saved' => 'content_saved',
        'postprocessing' => 'postprocessing',
        default => 'generating',
    };
    $persisted = WritingTaskRepository::heartbeat(
        (string)$asyncTaskId,
        $durableState,
        (int)($progress['progress'] ?? 0),
        isset($progress['error']) ? (string)$progress['error'] : null,
        $durableState === 'postprocessing' ? 1800 : 180,
        $lease['lease_owner'],
        $lease['lease_token'],
        $lease['fence_generation']
    );
    if (!$persisted) {
        throw new WriteWorkerLeaseLostException('progress heartbeat');
    }
}

/**
 * Publish a terminal compatibility file only after markDone/markFailed won its
 * exact durable CAS. Terminal durable states cannot be reclaimed.
 *
 * @param array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int,lease_expires_at:string} $lease
 */
function writeWorkerPublishTerminalProgress(array $updates, array $lease): void
{
    global $asyncProgressFile;
    if (!is_string($asyncProgressFile) || !is_file($asyncProgressFile)) {
        return;
    }
    $fp = @fopen($asyncProgressFile, 'r+');
    if ($fp === false) {
        throw new RuntimeException('Unable to open terminal progress file.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Unable to lock terminal progress file.');
    }
    $contentFile = $asyncProgressFile . '.content';
    $deleteContentFile = false;
    try {
        $decoded = json_decode((string)stream_get_contents($fp), true);
        $progress = is_array($decoded) ? $decoded : [];
        if (!writeWorkerProgressMatchesLease($progress, $lease)) {
            $progress = writeWorkerFreshProgressForLease($progress);
            if (file_put_contents($contentFile, '', LOCK_EX) === false) {
                throw new RuntimeException('Unable to reset stale terminal content.');
            }
        }
        if (!empty($progress['_content_split']) && is_file($contentFile)) {
            $contentFp = @fopen($contentFile, 'r');
            if ($contentFp !== false) {
                if (flock($contentFp, LOCK_SH)) {
                    $updates['content'] = (string)stream_get_contents($contentFp)
                        . (string)($updates['content'] ?? '');
                    flock($contentFp, LOCK_UN);
                    $deleteContentFile = true;
                }
                fclose($contentFp);
            }
            $progress['_content_split'] = false;
        }
        $progress = writeWorkerStampProgress(array_merge($progress, $updates), $lease);
        $encoded = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode terminal progress data.');
        }
        fseek($fp, 0);
        if (!ftruncate($fp, 0) || fwrite($fp, $encoded) === false || !fflush($fp)) {
            throw new RuntimeException('Unable to publish terminal progress data.');
        }
        if ($deleteContentFile) {
            @unlink($contentFile);
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function flushChunkBuffer(): void {
    global $asyncProgressFile, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
    if ($chunkBuffer === '' || !$asyncProgressFile || !file_exists($asyncProgressFile)) return;

    $pendingChunk = $chunkBuffer;
    $pendingCount = $chunkBufferCount;
    $published = writeWorkerMutateActiveProgress(
        'chunk_flush',
        static function(array $progress, array $lease, string $contentFile) use (
            $pendingChunk,
            $pendingCount
        ): array {
            // W-17：正文追加和 JSON 元数据发布都处于同一 exact lease 行锁内。
            if (file_put_contents($contentFile, $pendingChunk, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Unable to append fenced progress content.');
            }
            $progress['status'] = 'writing';
            $progress['progress'] = min(90, ($progress['progress'] ?? 0) + $pendingCount * 0.1);
            $progress['_content_split'] = true;
            return $progress;
        }
    );
    if ($published === null) {
        return;
    }
    $chunkBuffer = '';
    $chunkBufferCount = 0;
    $lastFlushTime = HarnessRuntime::preciseTime();
}

function updateAsyncProgress(array $updates): void {
    global $asyncTaskId, $chunkBuffer;
    // 所有剩余正文先走同一条 exact-fenced 追加路径。
    if ($chunkBuffer !== '') {
        flushChunkBuffer();
    }

    $requestedStatus = isset($updates['status']) ? (string)$updates['status'] : null;
    if (isWriteProgressTerminalStatus($requestedStatus)) {
        $lease = writeWorkerLeaseCarrier();
        if ($lease === null) {
            writeWorkerStopForLeaseLoss('terminal progress without lease');
        }
        if (DB::inTransaction()) {
            // 文件终态绝不能领先于尚未提交的 durable CAS。
            throw new RuntimeException('Terminal progress publication attempted inside a transaction.');
        }

        $progress = writeWorkerReadProgressSnapshot();
        if (!writeWorkerProgressMatchesLease($progress, $lease)) {
            $progress = writeWorkerFreshProgressForLease($progress);
        }
        $progress = array_merge($progress, $updates);

        if ($requestedStatus === 'done') {
            $result = array_intersect_key($progress, array_flip([
                'words', 'chapter_id', 'messages', 'model_used', 'postprocess_pending', 'postprocess_failed',
                'quality_warning', 'quality_warning_reason',
            ]));
            $revisionHash = null;
            $durableChapterId = (int)($progress['chapter_id'] ?? 0);
            if ($durableChapterId > 0) {
                $storedContent = DB::fetchColumn('SELECT content FROM chapters WHERE id=? LIMIT 1', [$durableChapterId]);
                if (is_string($storedContent) && $storedContent !== '') {
                    $revisionHash = hash('sha256', $storedContent);
                }
            }
            $terminalPersisted = WritingTaskRepository::markDone(
                (string)$asyncTaskId,
                $result,
                !empty($progress['content_saved']),
                $revisionHash,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation']
            );
        } else {
            $canceled = !empty($progress['canceled']);
            $errorCode = (string)($progress['_durable_error_code']
                ?? ($canceled ? 'canceled' : 'worker_failed'));
            $terminalPersisted = WritingTaskRepository::markFailed(
                (string)$asyncTaskId,
                (string)($progress['error'] ?? 'Worker failed'),
                !empty($progress['content_saved']),
                $canceled,
                $errorCode,
                $lease['lease_owner'],
                $lease['lease_token'],
                $lease['fence_generation']
            );
        }

        if (!$terminalPersisted) {
            writeWorkerStopForLeaseLoss('terminal durable CAS');
        }
        // markDone/markFailed 已提交 exact fence，false 路径绝不触碰文件。
        writeWorkerPublishTerminalProgress($progress, $lease);
        return;
    }

    $progress = writeWorkerMutateActiveProgress(
        'progress_update',
        static function(array $progress, array $lease, string $contentFile) use ($updates): array {
            $progress = array_merge($progress, $updates);
            // heartbeat 的 affected===1 是紧邻文件发布的第二道 exact CAS；
            // false 作为 lease_lost 抛出，外层回滚并退出。
            writeWorkerSyncHeartbeat($progress, $lease);
            return $progress;
        }
    );
    if ($progress === null) {
        // 兼容文件可能被清理；持久状态仍需续租，但不得重建一个缺少 HMAC
        // 元数据的文件。legacy/shadow poll 会从 writing_tasks 安全降级。
        writeWorkerWithExactLease('progress heartbeat without file', static function(array $lease) use ($updates): void {
            writeWorkerSyncHeartbeat($updates, $lease);
        });
    }
}

function sendHeartbeatWrite(): void {
    global $lastHeartbeat, $asyncTaskId, $chunkBuffer, $chunkBufferCount, $lastFlushTime, $_writingChapterId;
    global $workerStartTime, $workerGlobalTimeout, $novelId, $ch, $_inPostProcessPhase, $_workerContentSaved;
    global $writingTaskLease;
    global $lastContentTime;  // v2 fix: 追踪最后一次收到 chunk 的时间
    $now = HarnessRuntime::preciseTime();
    // Canon commit retires the chapter claim. Continue renewing the exact
    // writing-task lease and leave the job lease to PostprocessRunner.
    $heartbeatStatus = $_inPostProcessPhase ? 'postprocessing' : ($_workerContentSaved ? 'saved' : 'writing');

    // 刷新章节 updated_at（防止 Watchdog 误杀）
    // 审计修复 H2（2026-08-16）：增加 10 秒节流。sseChunkWrite() 每个 token 都调用本函数，
    // 原实现每个 token 执行一次 UPDATE（3000 字章节 ≈ 3000~5000 次 DB 往返）。
    // watchdog 僵尸判定阈值远大于 10 秒，节流后防误杀语义不变；
    // 深度思考模型长时间无 chunk 的场景由本函数末尾的定时心跳路径覆盖。
    // 注：必须避开事务期间（与 write_chapter.php PERF-C3 同因）。
    static $lastDbHeartbeatAt = 0;
    if (!$_workerContentSaved && $_writingChapterId > 0 && !DB::inTransaction() && ($now - $lastDbHeartbeatAt) >= 10) {
        try {
            $claimToken = is_array($ch) ? (string)($ch['claim_token'] ?? '') : '';
            $lease = writeWorkerLeaseCarrier();
            if ($lease !== null && preg_match('/^[a-f0-9]{32}$/D', $claimToken) === 1) {
                $claimHeartbeat = WritingTaskRepository::heartbeatOwnedChapterClaim(
                    $novelId,
                    $_writingChapterId,
                    $claimToken,
                    180
                );
                if ($claimHeartbeat !== true) {
                    // 审计修复 P0（2026-09-27）：此处可能运行在 cURL 回调内，
                    // exit 会跨 C 边界引发进程闪退并跳过 shutdown 清理。
                    // 只登记致命标志，由 cURL 外的 writeWorkerAbortIfFatal() 统一退出。
                    writeWorkerRequestFatalAbort('lease_lost', 0, 'chapter claim heartbeat');
                    return;
                }
            } else {
                writeWorkerRequestFatalAbort('lease_lost', 0, 'chapter claim heartbeat authority');
                return;
            }
            $lastDbHeartbeatAt = $now;
        } catch (\Throwable $e) { error_log('write_chapter_worker heartbeat update failed: ' . $e->getMessage()); }
    }

    if ($workerStartTime > 0 && (HarnessRuntime::unixTime() - $workerStartTime) > $workerGlobalTimeout) {
        // 如果近期有内容产出（AI 仍在工作），自动延长超时而非误杀
        // $lastContentTime 在 sseChunkWrite 中更新
        if (isset($lastContentTime) && (HarnessRuntime::unixTime() - $lastContentTime) < 120) {
            // Authenticate the exact generation before extending timeout state
            // or emitting a durable log entry.
            updateAsyncProgress(['status' => $heartbeatStatus, 'heartbeat' => $now]);
            $lastHeartbeat = $now;
            $workerGlobalTimeout += 600;  // 额外给10分钟
            writeWorkerWithExactLease('timeout extension log', static function(array $lease) use ($novelId): void {
                addLog($novelId, 'warn', '全局超时但AI仍在产出内容，自动延长600秒');
            });
            return;
        }
        $elapsed = HarnessRuntime::unixTime() - $workerStartTime;
        error_log("[write_worker] 全局超时（{$elapsed}s > {$workerGlobalTimeout}s），登记致命退出");
        // 审计修复 P0（2026-09-27）：本函数可能运行在 cURL 回调内，原 exit(1)
        // 跨 C 边界有进程闪退风险且跳过 shutdown 清理。清理与退出由
        // writeWorkerAbortIfFatal() 在 cURL 外执行（释放认领、终态上报、
        // pp_pending 清理、小说状态回落）。
        writeWorkerRequestFatalAbort('global_timeout', 1, (string)$elapsed);
        return;
    }
    if ($chunkBuffer !== '' && ($now - $lastFlushTime >= CHUNK_FLUSH_INTERVAL || $chunkBufferCount >= CHUNK_FLUSH_COUNT)) {
        flushChunkBuffer();
    }
    if ($now - $lastHeartbeat < 10) return;
    try {
        if (!is_array($writingTaskLease)
            || !WritingTaskRepository::isLeaseCurrent(
                $asyncTaskId,
                (string)$writingTaskLease['lease_owner'],
                (string)$writingTaskLease['lease_token'],
                (int)$writingTaskLease['fence_generation']
            )) {
            // A newer claimant or a control-plane cancellation invalidated this
            // exact worker generation.  Do not touch the shared progress file
            // or task row again; chapter release remains guarded by claim_token.
            if ($_writingChapterId > 0 && is_array($ch)) {
                try {
                    WriteEngine::releaseChapterClaim($_writingChapterId, $ch);
                } catch (Throwable $releaseError) {
                    error_log('[write_worker] stale lease chapter release failed: ' . $releaseError->getMessage());
                }
            }
            error_log('[write_worker] durable task lease lost; stale worker exiting');
            // 审计修复 P0（2026-09-27）：cURL 回调内不 exit；登记致命标志，
            // 由 writeWorkerAbortIfFatal() 在 cURL 外统一退出。
            writeWorkerRequestFatalAbort('lease_lost', 0, 'durable task lease lost');
            return;
        }
        // isLeaseCurrent already authenticates cancel_requested=0. If a
        // control-plane cancel commits between this check and the next fenced
        // progress update, that update exits lease_lost. The worker must never
        // re-arm the novel-wide cancel file: only the committed control-plane
        // transaction may publish it, otherwise an old generation can kill B.
    } catch (Throwable $cancelStateError) {
        error_log('[write_worker] durable cancel check failed: ' . $cancelStateError->getMessage());
    }
    updateAsyncProgress(['status' => $heartbeatStatus, 'heartbeat' => $now]);
    $lastHeartbeat = $now;
}

function sseChunkWrite(string $chunk): void {
    global $asyncProgressFile, $asyncMessages, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
    global $lastContentTime;  // v2 fix: 追踪最后一次内容产出时间
    $lastContentTime = HarnessRuntime::unixTime();
    // 缓冲 token，减少磁盘 I/O
    $chunkBuffer .= $chunk;
    $chunkBufferCount++;
    // 达到刷新阈值时写入文件
    if ($chunkBufferCount >= CHUNK_FLUSH_COUNT || (HarnessRuntime::preciseTime() - $lastFlushTime >= CHUNK_FLUSH_INTERVAL)) {
        flushChunkBuffer();
    }
    // 心跳检查（低频）
    sendHeartbeatWrite();
}

function resetAsyncContentForRetry(): void {
    global $asyncProgressFile, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
    // 失败尝试已经写入的 token 不属于下一次生成。先刷新并立即截断，
    // 再递增 revision，前端轮询据此同步清空显示区。
    if ($chunkBuffer !== '') {
        flushChunkBuffer();
    }
    if (!$asyncProgressFile || !file_exists($asyncProgressFile)) {
        throw new RuntimeException('重试时无法定位写作进度文件，已停止生成以防正文串联');
    }

    writeWorkerMutateActiveProgress(
        'retry_content_reset',
        static function(array $progress, array $lease, string $contentFile): array {
            if (file_put_contents($contentFile, '', LOCK_EX) === false) {
                throw new RuntimeException('重试时无法清空失败正文，已停止生成以防正文串联');
            }
            $progress['content'] = '';
            $progress['_content_split'] = true;
            $progress['content_revision'] = (int)($progress['content_revision'] ?? 0) + 1;
            return $progress;
        },
        true
    );
    $chunkBuffer = '';
    $chunkBufferCount = 0;
    $lastFlushTime = HarnessRuntime::preciseTime();
}

function sseMsgWrite(array $payload): void {
    global $asyncMessages;
    sendHeartbeatWrite();
    if (!empty($payload['reset_content'])) {
        resetAsyncContentForRetry();
    }
    $asyncMessages[] = $payload;
    updateAsyncProgress([
        'messages' => $asyncMessages,
        'status'   => $payload['status'] ?? (($payload['waiting'] ?? false) ? 'waiting' : 'writing'),
    ]);
}

// ---- 思考过程缓冲（异步模式专用） ----
$thinkingBuffer = '';
$thinkingFlushInterval = 2; // 秒
$lastThinkingFlush = HarnessRuntime::preciseTime();

function sseThinkingWrite(string $chunk): void {
    global $thinkingBuffer, $lastThinkingFlush, $thinkingFlushInterval;
    $thinkingBuffer .= $chunk;
    // 每隔一段时间将思考过程写入进度文件
    if (HarnessRuntime::preciseTime() - $lastThinkingFlush >= $thinkingFlushInterval) {
        flushThinkingBuffer();
    }
}

function flushThinkingBuffer(): void {
    global $thinkingBuffer, $lastThinkingFlush, $asyncProgressFile;
    if ($thinkingBuffer === '' || !$asyncProgressFile || !file_exists($asyncProgressFile)) return;
    $pendingThinking = $thinkingBuffer;
    $published = writeWorkerMutateActiveProgress(
        'thinking_flush',
        static function(array $progress, array $lease, string $contentFile) use ($pendingThinking): array {
            $progress['thinking_content'] = ($progress['thinking_content'] ?? '') . $pendingThinking;
            return $progress;
        }
    );
    if ($published === null) return;
    $thinkingBuffer = '';
    $lastThinkingFlush = HarnessRuntime::preciseTime();
}

function sseDoneWrite(): void {
    flushThinkingBuffer(); // 确保最后一批思考内容也写入
    updateAsyncProgress(['status' => 'done', 'progress' => 100]);
}

function recoverCommittedChapterAfterSaveError(int $novelId, array $chapter, string $generatedContent): ?array {
    // 审计修复 P2（2026-09-27）：本地弱校验版（仅 completed + 内容哈希）收敛为
    // WriteEngine 的唯一强校验实现（canonical_state + claim_token 清空 +
    // revision_hash 自洽），与 daemon 路径同强度，消除三入口行为漂移。
    return WriteEngine::recoverCommittedChapterSnapshot($novelId, $chapter, $generatedContent);
}

// 注册全局心跳函数（供 AIClient 的 CURLOPT_PROGRESSFUNCTION 调用）
$GLOBALS['sendHeartbeat'] = 'sendHeartbeatWrite';
// 审计修复 P0（2026-09-27）：登记 worker 致命标志后，AIClient 在 cURL 回调
// 边界发现标志并以 CURLE_WRITE_ERROR 安全中止传输（回调内绝不 exit）。
AIClient::setFatalAbortProbe(static fn (): bool => writeWorkerFatalAbortState() !== null);
// 审计优化 P2-1（2026-06-16）：保留 $GLOBALS['sendWaiting'] 作为向后兼容回退，
// 同时定义为局部变量 $sendWaitingCb 显式传入 streamWrite。
$GLOBALS['sendWaiting'] = function(int $elapsedSeconds) {
    global $asyncMessages;
    $asyncMessages[] = ['waiting' => true, 'msg' => "AI 思考中（已等待 {$elapsedSeconds} 秒）…", 'elapsed' => $elapsedSeconds];
    updateAsyncProgress(['messages' => $asyncMessages, 'status' => 'waiting']);
};
$sendWaitingCb = $GLOBALS['sendWaiting'];

try {
    $workerBootUuid = HarnessRuntime::randomHex(16);
    $workerLeaseOwner = 'write-worker:'
        . (gethostname() ?: 'localhost')
        . ':' . getmypid()
        . ':' . $workerBootUuid;
    $writingTaskLease = WritingTaskRepository::claimLease(
        $taskId,
        $chapterId ?: null,
        $workerLeaseOwner
    );
    if (!is_array($writingTaskLease)) {
        $wasCanceled = WritingTaskRepository::isCancelRequested($taskId);
        updateAsyncProgress([
            'status' => 'error',
            'error' => $wasCanceled ? '用户已取消写作' : '写作任务租约无效或已结束',
            'canceled' => $wasCanceled,
        ]);
        exit(1);
    }

    // M60 policy restore: normal tasks already carry the snapshot captured by
    // write_start. A pre-M60 row may freeze one compatibility snapshot via CAS;
    // after that point this worker consumes only the authenticated durable row.
    $taskPolicyRow = WritingTaskRepository::findByTaskId($taskId);
    if (!is_array($taskPolicyRow) || (int)($taskPolicyRow['novel_id'] ?? 0) !== $novelId) {
        throw new RuntimeException('Writing task ownership does not match the worker request.');
    }
    if (!is_array($writingTaskLease)
        || !hash_equals(
            (string)$writingTaskLease['lease_owner'],
            (string)($taskPolicyRow['lease_owner'] ?? '')
        )
        || !hash_equals(
            (string)$writingTaskLease['lease_token'],
            (string)($taskPolicyRow['lease_token'] ?? '')
        )
        || (int)$writingTaskLease['fence_generation']
            !== (int)($taskPolicyRow['fence_generation'] ?? 0)
        || !WritingTaskRepository::isLeaseCurrent(
            $taskId,
            (string)$writingTaskLease['lease_owner'],
            (string)$writingTaskLease['lease_token'],
            (int)$writingTaskLease['fence_generation']
        )) {
        throw new RuntimeException('Writing task lease changed during worker startup.');
    }
    $taskChapterId = (int)($taskPolicyRow['chapter_id'] ?? 0);
    if ($chapterId > 0 && $taskChapterId > 0 && $taskChapterId !== $chapterId) {
        throw new RuntimeException('Writing task chapter does not match the worker request.');
    }

    $writePolicySnapshot = WritingTaskRepository::optionalPolicySnapshotFromRow($taskPolicyRow);
    if ($writePolicySnapshot === null) {
        $legacyTargetWords = DB::fetchColumn(
            'SELECT chapter_words FROM novels WHERE id=? LIMIT 1',
            [$novelId]
        );
        if ($legacyTargetWords === false) {
            throw new RuntimeException('Novel was not found while freezing legacy task policy.');
        }
        $legacyTargetWords = (int)$legacyTargetWords;
        if ($legacyTargetWords < 500) {
            $legacyTargetWords = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
        }
        $legacyTargetWords = max(500, $legacyTargetWords);
        $legacyPolicySnapshot = WritePolicySnapshotFactory::captureForChapter(
            $novelId,
            $legacyTargetWords
        );
        $writePolicySnapshot = WritingTaskRepository::freezePolicySnapshot(
            $taskId,
            $legacyPolicySnapshot,
            (string)$writingTaskLease['lease_owner'],
            (string)$writingTaskLease['lease_token'],
            (int)$writingTaskLease['fence_generation']
        );
    }
    WritePolicySnapshotFactory::assertWritingWorkerFenceEnforced(
        $writePolicySnapshot,
        $writingTaskLease,
        $taskPolicyRow
    );
    WritePolicySnapshotFactory::assertEmergencyEpochCurrent($writePolicySnapshot);
    // 审计修复 P2（2026-09-27）：冻结本任务五关通过线，同任务的质检与
    // 生成承诺不得随配置漂移。
    WriteEngine::freezeQualityGateMinScore(WriteEngine::resolveQualityGateMinScore());
    $chapterFeatureSnapshot = HarnessFeatureSnapshot::captureForNovel($novelId);
    // 取消 flag 也是跨 worker 共享文件：只有 durable claim 的 exact owner
    // 能清理上一任务残留。claim 失败的重复进程不得触碰任何共享文件。
    writeWorkerWithExactLease('cancel flag reset', static function(array $lease) use ($novelId): void {
        @unlink(CFG_PROGRESS_DIR . "/write_cancel_{$novelId}.flag");
    });
} catch (Throwable $taskStartError) {
    $startupErrorCode = $taskStartError instanceof HarnessFencingEnforcementException
        ? 'harness_fence_required'
        : 'policy_snapshot_invalid';
    updateAsyncProgress([
        'status' => 'error',
        'error' => '写作任务持久状态初始化失败',
        '_durable_error_code' => $startupErrorCode,
    ]);
    error_log('[write_worker] durable task startup failed: ' . $taskStartError->getMessage());
    exit(1);
}

// P2 authority path. The frozen snapshot is the sole router; default off and
// shadow tasks continue through the byte-compatible legacy orchestration below.
if (ChapterRunService::shouldUseAuthority(
    $writePolicySnapshot,
    $chapterFeatureSnapshot
)) {
    try {
        $effectiveChapterId = $taskChapterId > 0
            ? $taskChapterId
            : (int)(WriteEngine::findNextAutomaticChapterId($novelId) ?? 0);
        if ($effectiveChapterId <= 0) {
            throw new PermanentStageException('No writable Chapter is available for this Run.');
        }

        $runInputNovel = DB::fetch(
            'SELECT * FROM novels WHERE id=? LIMIT 1',
            [$novelId]
        );
        $runInputChapter = DB::fetch(
            'SELECT ' . WriteEngine::chapterWriteSelectList(['retry_count'])
                . ' FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
            [$effectiveChapterId, $novelId]
        );
        if (!is_array($runInputNovel) || !is_array($runInputChapter)) {
            throw new StaleRevisionException(
                'Chapter Run input source disappeared before admission.'
            );
        }
        $chapterInputSnapshot = ChapterRunInputSnapshotFactory::capture(
            $runInputNovel,
            $runInputChapter
        );
        // 专项一（2026-09-28）：authority 编排骨架（invocation 组装、执行工厂、
        // 恢复闭包、adapter 工厂、runToTerminal 调用与终态映射）收敛进
        // ChapterWriteOrchestrator；本入口保留传输回调、任务租约认证与终态收尾。
        $transport = new CallbackLegacyChapterTransport([
            'waiting' => static function (string $message) use (&$asyncMessages): void {
                $asyncMessages[] = ['waiting' => true, 'msg' => $message];
                updateAsyncProgress([
                    'messages' => $asyncMessages,
                    'status' => 'waiting',
                ]);
            },
            'chunk' => static function (string $chunk): void {
                sseChunkWrite($chunk);
            },
            'message' => static function (array $payload): void {
                sseMsgWrite($payload);
            },
            'heartbeat' => static function (): void {
                sendHeartbeatWrite();
            },
            'thinking' => static function (string $chunk): void {
                sseThinkingWrite($chunk);
            },
            'acquired' => static function (array $resolvedNovel, array $resolvedChapter) use (
                &$novel,
                &$ch,
                &$_writingChapterId
            ): void {
                $novel = $resolvedNovel;
                $ch = $resolvedChapter;
                $_writingChapterId = (int)$ch['id'];
                updateAsyncProgress([
                    'status' => 'writing',
                    'chapter_id' => (int)$ch['id'],
                    'chapter_number' => (int)$ch['chapter_number'],
                ]);
            },
            'saved' => static function (array $saveResult) use (
                $novelId,
                &$ch,
                &$fullContent,
                &$workerGlobalTimeout,
                &$_inPostProcessPhase,
                &$_workerContentSaved,
                &$_writingChapterId,
                &$asyncMessages
            ): void {
                $ch = $saveResult['chapter'];
                $_workerContentSaved = true;
                $_writingChapterId = null;
                $fullContent = (string)$saveResult['accepted_content'];
                $words = (int)$saveResult['words'];
                $modelLabel = $saveResult['_model_label'] ?? null;
                flushChunkBuffer();
                updateAsyncProgress([
                    'status' => 'saved',
                    'progress' => 95,
                    'content_saved' => true,
                    'words' => $words,
                    'model_used' => is_string($modelLabel) ? $modelLabel : null,
                    'quality_warning' => !empty($saveResult['quality_warning']),
                    'quality_warning_reason' =>
                        (string)($saveResult['quality_warning_reason'] ?? ''),
                    'messages' => array_merge($asyncMessages, [[
                        'stats' => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字"
                            . (string)($saveResult['model_info'] ?? ''),
                        'chapter_id' => (int)$ch['id'],
                        'words' => $words,
                        'done' => (bool)($saveResult['all_done'] ?? false),
                        'model_used' => is_string($modelLabel) ? $modelLabel : null,
                        'next_chapter_id' => $saveResult['next_chapter_id'] ?? null,
                        'next_chapter_num' => $saveResult['next_chapter_num'] ?? null,
                        'quality_warning' => !empty($saveResult['quality_warning']),
                        'quality_warning_reason' =>
                            (string)($saveResult['quality_warning_reason'] ?? ''),
                    ]]),
                ]);
                try {
                    writeWorkerSetNovelStatusWhileLeaseCurrent(
                        'authority success novel finalize',
                        $novelId,
                        'paused'
                    );
                } catch (Throwable $stateError) {
                    error_log(
                        '[write_worker] authority novel finalize failed: '
                        . $stateError->getMessage()
                    );
                }
                $workerGlobalTimeout += 1900;
                $_inPostProcessPhase = true;
                updateAsyncProgress([
                    'status' => 'postprocessing',
                    'progress' => 98,
                    'content_saved' => true,
                ]);
            },
            'postprocessed' => static function (array $result): void {
                $state = (string)($result['state'] ?? 'pending');
                flushChunkBuffer();
                updateAsyncProgress([
                    'status' => 'done',
                    'progress' => 100,
                    'content_saved' => true,
                    'postprocess_pending' => in_array(
                        $state,
                        ['queued', 'retry', 'running', 'pending'],
                        true
                    ),
                    'postprocess_failed' => $state === 'failed',
                    'postprocess_job_id' => (int)($result['job_id'] ?? 0),
                ]);
            },
        ]);

        // 认领恢复认证：任务行必须仍持有本 worker 的精确租约四元组。
        $restoreBase = static function (RunContext $context, array $chapter) use (
            $taskId,
            $novelId,
            $writingTaskLease
        ): array {
            $task = WritingTaskRepository::findByTaskId($taskId);
            if (!is_array($task)
                || (int)($task['novel_id'] ?? 0) !== $novelId
                || (int)($task['chapter_id'] ?? 0)
                    !== $context->request->chapterId
                || !hash_equals(
                    (string)$writingTaskLease['lease_owner'],
                    (string)($task['lease_owner'] ?? '')
                )
                || !hash_equals(
                    (string)$writingTaskLease['lease_token'],
                    (string)($task['lease_token'] ?? '')
                )
                || (int)$writingTaskLease['fence_generation']
                    !== (int)($task['fence_generation'] ?? 0)) {
                throw new LeaseLostException(
                    'Writing task claim changed during Run recovery.'
                );
            }
            return WritingTaskRepository::baseChapterSnapshotFromRow($task);
        };

        // 首参 $restore 由编排器注入（共享的恢复认证闭包）。
        $acquireClaim = static function (Closure $restore, RunContext $context) use (
            $novelId,
            $effectiveChapterId,
            $taskId,
            $writingTaskLease
        ): array {
            // If the process died after freezing the exact task/chapter
            // claim but before the Acquire checkpoint was written, adopt
            // that authenticated claim instead of trying to claim again.
            $existingTask = WritingTaskRepository::findByTaskId($taskId);
            if (is_array($existingTask)
                && is_string($existingTask['base_chapter_snapshot_json'] ?? null)
                && $existingTask['base_chapter_snapshot_json'] !== '') {
                return $restore($context);
            }
            $resolved = WriteEngine::resolveChapter($novelId, $effectiveChapterId);
            $base = $resolved['ch']['_base_snapshot'] ?? null;
            if (!is_array($base)) {
                throw new PermanentStageException(
                    'Resolved Chapter omitted its frozen base snapshot.'
                );
            }
            WritingTaskRepository::freezeBaseChapterSnapshot(
                $taskId,
                (int)$resolved['ch']['id'],
                $base,
                (string)$writingTaskLease['lease_owner'],
                (string)$writingTaskLease['lease_token'],
                (int)$writingTaskLease['fence_generation']
            );
            return $resolved;
        };

        // 终态收尾：四分支各自完成进度终态发布、小说状态固化与退出码。
        $authorityTermination = new CallbackWriteTermination([
            'succeeded' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ): void {
                exit(0);
            },
            'quarantined' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ) use (&$ch, $novelId): void {
                if (is_array($ch ?? null)) {
                    WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
                }
                updateAsyncProgress([
                    'status' => 'error',
                    'error' => '章节未通过质量校验，已暂停写作',
                    'validation_blocked' => true,
                    '_durable_error_code' => 'validation_blocked',
                ]);
                writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
                exit(1);
            },
            'canceled' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ) use (&$ch, $novelId): void {
                if (!$contentSaved && is_array($ch ?? null)) {
                    WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
                }
                updateAsyncProgress([
                    'status' => 'error',
                    'error' => $contentSaved
                        ? '用户已取消，但本章正文已保存完成，后处理任务可恢复'
                        : '用户已取消写作',
                    'canceled' => true,
                    'content_saved' => $contentSaved,
                    'canceled_but_saved' => $contentSaved,
                    '_durable_error_code' => 'run_canceled',
                ]);
                writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
                exit(1);
            },
            'failed' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ) use (&$ch, $novelId): void {
                if (!$contentSaved && is_array($ch ?? null)) {
                    WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
                }
                updateAsyncProgress([
                    'status' => 'error',
                    'error' => $contentSaved
                        ? '正文已保存，但章节后处理未完成，请稍后重试后处理'
                        : '写作工作流执行失败，请稍后重试',
                    'content_saved' => $contentSaved,
                    'postprocess_failed' => $contentSaved,
                    '_durable_error_code' => $result->errorCode ?? 'workflow_failed',
                ]);
                writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
                exit(1);
            },
        ]);

        $runRequest = new ChapterWriteRunRequest(
            entryPoint: ChapterRunEntryPoint::ASYNC_WORKER,
            userId: (int)($taskPolicyRow['user_id'] ?? 0),
            novelId: $novelId,
            chapterId: $effectiveChapterId,
            authorityIdentity: $taskId,
            policySnapshot: $writePolicySnapshot,
            featureSnapshot: $chapterFeatureSnapshot,
            inputSnapshot: $chapterInputSnapshot,
            workerId: $workerLeaseOwner,
            transport: $transport,
            acquireChapter: $acquireClaim,
            restoreBase: $restoreBase,
            restoreMissingMessage: 'Chapter Run recovery source disappeared.',
            restoreInactiveMessage: 'Chapter claim is neither active nor committed for this Run.',
            postprocessWorkerId: $workerLeaseOwner,
            termination: $authorityTermination,
            goal: [
                'legacy_task_id' => $taskId,
                'requested_chapter_id' => $chapterId > 0 ? $chapterId : null,
            ],
            writingTaskId: $taskId,
            writingTaskLease: $writingTaskLease,
            legacyCancellationProbe: static fn (): bool =>
                WritingTaskRepository::isCancelRequested($taskId)
                || file_exists(WriteEngine::cancelFlagPath($novelId)),
            waitingCallback: $sendWaitingCb,
            onRetryScheduled: static function (WorkflowExecutionResult $retryResult): void {
                updateAsyncProgress([
                    'status' => 'waiting',
                    'error' => null,
                    '_durable_error_code' => $retryResult->errorCode,
                ]);
            },
            retryTick: static function (
                string $runUid,
                WorkflowExecutionResult $retryResult
            ): ?WorkflowExecutionResult {
                if (WritingTaskRepository::isCancelRequested($taskId)) {
                    (new GenerationRunRepository(DB::getPdo()))->requestCancelWithEvents($runUid);
                    return new WorkflowExecutionResult(
                        GenerationRunState::CANCELED,
                        $retryResult->context,
                        $retryResult->stageName,
                        errorCode: 'run_canceled'
                    );
                }
                sendHeartbeatWrite();
                writeWorkerAbortIfFatal();
                return null;
            },
            postRun: static function (WorkflowExecutionResult $result): void {
                // 审计修复 P0（2026-09-27）：authority 路径的统一致命退出安全点。
                // runToTerminal 期间的心跳可能已登记租约丢失/全局超时标志。
                writeWorkerAbortIfFatal();
            }
        );

        (new ChapterWriteOrchestrator(DB::getPdo()))->run($runRequest);
        // 四个终态回调内均已 exit；此兜底确保永不坠入下方 legacy 编排。
        exit(1);

    } catch (Throwable $authorityError) {
        error_log(
            '[write_worker] authority Chapter Run failed ('
            . $authorityError::class . '): ' . $authorityError->getMessage()
        );
        if (is_array($ch ?? null) && !empty($ch['id'])) {
            WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
        }
        updateAsyncProgress([
            'status' => 'error',
            'error' => '写作工作流持久执行失败，请稍后重试',
            '_durable_error_code' => $authorityError instanceof WorkflowRuntimeException
                ? $authorityError->errorCode()
                : 'workflow_boot_failed',
        ]);
        writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
        exit(1);
    }
}

// ---- 核心写作逻辑（与 write_chapter.php 相同）----
updateAsyncProgress(['status' => 'writing', 'pid' => getmypid()]);

// Phase 1-3: WriteEngine 解析章节 / 记忆初始化 / 组装 Prompt
try {
    $resolved = WriteEngine::resolveChapter($novelId, $chapterId);
    $novel    = $resolved['n'];
    $ch       = $resolved['ch'];
    $_writingChapterId = (int)$ch['id'];

    // Prompt and generation target remain frozen even if mutable novel/config
    // settings changed while this task waited in the queue.
    $novel['chapter_words'] = $writePolicySnapshot->targetWords();

    // resolveChapter captures the pre-acquisition state before its claim CAS
    // and returns it bound to the exact claim token. Persist that transported
    // snapshot before the first model call; do not reconstruct it from live DB.
    $baseChapterSnapshot = $ch['_base_snapshot'] ?? null;
    if (!is_array($baseChapterSnapshot)) {
        throw new RuntimeException('Resolved chapter omitted its frozen base snapshot.');
    }
    WritingTaskRepository::freezeBaseChapterSnapshot(
        $taskId,
        (int)$ch['id'],
        $baseChapterSnapshot,
        (string)$writingTaskLease['lease_owner'],
        (string)$writingTaskLease['lease_token'],
        (int)$writingTaskLease['fence_generation']
    );
    WritePolicySnapshotFactory::assertEmergencyEpochCurrent($writePolicySnapshot);
} catch (Throwable $e) {
    error_log("[write_worker] Phase1 resolveChapter 失败: {$e->getMessage()}");
    if (isset($ch) && is_array($ch) && !empty($ch['id'])) {
        try {
            WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
        } catch (Throwable $releaseError) {
            error_log('[write_worker] base snapshot failure claim release failed: ' . $releaseError->getMessage());
        }
    }
    $clientErr = '章节准备失败，请稍后重试';
    if (!($e instanceof PDOException)) {
        $msg = trim($e->getMessage());
        if ($msg !== '' && !preg_match('/SQLSTATE|Unknown column|\.php:\d/i', $msg)) {
            $clientErr = $msg;
        }
    }
    updateAsyncProgress(['status' => 'error', 'error' => $clientErr]);
    exit(1);
}

updateAsyncProgress(['chapter_id' => $ch['id'], 'chapter_number' => (int)$ch['chapter_number']]);

$preAiClient = null;
try {
    $preAiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
    if ($preAiClient->is1MContext()) {
        writeWorkerWithExactLease('1m model setup log', static function(array $lease) use ($novelId): void {
            addLog($novelId, 'info', '检测到1M上下文模型，将使用完整上下文模式');
        });
        if (defined('CFG_TIME_LONG_1M')) {
            set_time_limit(CFG_TIME_LONG_1M);
        }
    }
} catch (Throwable $e) {
    // 忽略，后续模型调用会按原有 fallback 逻辑重试。
}

try {
    $memResult = WriteEngine::initMemory($novelId, $ch, $preAiClient);
    $engine    = $memResult['engine'];
    $memoryCtx = $memResult['memoryCtx'];
} catch (Throwable $e) {
    writeWorkerWithExactLease('memory fallback log', static function(array $lease) use ($novelId, $e): void {
        addLog($novelId, 'error', 'MemoryEngine 初始化失败：' . $e->getMessage());
    });
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    $engine    = new MemoryEngine($novelId);
    $memoryCtx = null;
}

try {
    $messages = WriteEngine::buildPrompt(
        $novel,
        $ch,
        $memoryCtx,
        $taskId,
        $writePolicySnapshot,
        $writingTaskLease,
        $chapterFeatureSnapshot
    );
} catch (Throwable $e) {
    error_log("[write_worker] Phase3 buildPrompt 失败: {$e->getMessage()}");
    // claim_token release is the only recovery mutation allowed before the
    // exact writing-task terminal CAS.
    WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
    updateAsyncProgress(['status' => 'error', 'error' => '写作提示词构建失败，请稍后重试']);
    addLog($novelId, 'error', 'buildPrompt 失败：' . $e->getMessage());
    writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
    exit(1);
}
$targetWords = $writePolicySnapshot->targetWords();
$fullContent = '';
$usedModel   = null;

// Phase 4: WriteEngine 流式写作（进度文件 I/O 回调）
try {
    $result = WriteEngine::streamWrite(
        $messages,
        $targetWords,
        $novelId,
        function(string $token) { sseChunkWrite($token); },
        function(array $payload) { sseMsgWrite($payload); },
        function() { sendHeartbeatWrite(); },
        function(string $reasoning) { sseThinkingWrite($reasoning); },
        $novel['model_id'] ? (int)$novel['model_id'] : null,
        (int)$ch['chapter_number'],
        (int)$novel['target_chapters'],
        $sendWaitingCb,
        static function () use ($writePolicySnapshot): void {
            WritePolicySnapshotFactory::assertEmergencyEpochCurrent($writePolicySnapshot);
        }
    );
    $fullContent       = $result['content'];
    $usedModel         = $result['model'];
    $streamUsage       = $result['usage'] ?? null;
    $streamDurationMs  = $result['duration_ms'] ?? null;
    // 审计修复 P0（2026-09-27）：streamWrite 期间登记的致命标志（租约丢失/
    // 全局超时）在此统一执行清理与退出（cURL 之外的安全点）。
    writeWorkerAbortIfFatal();
} catch (Exception $e) {
    $msg = $e->getMessage();
    error_log("[write_worker] Phase4 streamWrite 失败: {$msg}");
    // 致命退出优先于普通取消/失败语义：lease_lost 不得写共享进度（新
    // claimant 已拥有），global_timeout 走完整超时清理链。
    writeWorkerAbortIfFatal();
    $isCancel = $e instanceof WriteEngineCanceledException;
    flushChunkBuffer();
    // 审计修复（2026-08-02 M1）：恢复路径加状态守卫，防止旧任务失败恢复时覆盖新任务已预占的同章状态。
    WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
    updateAsyncProgress(['status' => 'error', 'error' => $isCancel ? '用户已取消写作' : '模型生成失败，请稍后重试', 'canceled' => $isCancel]);
    // W-13：仅 exact terminal winner 可把无新任务的小说回落为 draft。
    writeWorkerSetNovelStatusAfterTerminal($novelId, 'draft');
    exit(1);
}

// ---- Phase 5: WriteEngine 保存章节 ----
try {
    // H4 修复：把 task_id 注入 $ch，供 saveChapter 事务内查 writing_tasks.cancel_requested
    // 做权威取消校验（文件取消标志可被新任务 B 清除，DB task 标记不可被 B 清除）。
    // Transport the exact durable task fence into saveChapter.  The Canon
    // transaction validates all four coordinates together with cancellation;
    // direct SSE/daemon writes intentionally have no writing_tasks carrier.
    $ch['_task_id'] = $asyncTaskId ?: null;
    $ch['_task_lease_owner'] = (string)$writingTaskLease['lease_owner'];
    $ch['_task_lease_token'] = (string)$writingTaskLease['lease_token'];
    $ch['_task_fence_generation'] = (int)$writingTaskLease['fence_generation'];
    $saveResult = WriteEngine::saveChapter(
        (int)$ch['id'],
        $novelId,
        $fullContent,
        $targetWords,
        $usedModel,
        $ch,
        $streamUsage,
        $streamDurationMs,
        $writePolicySnapshot
    );
    $fullContent = (string)$saveResult['accepted_content'];
    $words     = $saveResult['words'];
    $ch        = $saveResult['chapter'];
    $_workerContentSaved = true;
    $_writingChapterId = null;
    $allDone   = $saveResult['all_done'];
    $modelInfo = $saveResult['model_info'];
    $qualityWarning = !empty($saveResult['quality_warning']);
    $qualityWarningReason = (string)($saveResult['quality_warning_reason'] ?? '');

    // 更新进度：正文已落盘（Task 2：saved 而非 completed——后处理尚未执行，不是任务终态）。
    // 字段 words/chapter_id/messages 经 updateAsyncProgress 的 array_merge 会保留到最终 done，
    // 前端在 done 时仍能读到（前端完成处理读顶层 words/chapter_id，见 app.js）。
    updateAsyncProgress([
        'status'     => 'saved',
        'progress'   => 95,
        'content_saved' => true,
        'words'      => $words,
        'model_used' => $usedModel?->modelLabel,
        'quality_warning' => $qualityWarning,
        'quality_warning_reason' => $qualityWarningReason,
        'messages'   => array_merge($asyncMessages, [[
            'stats'            => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字{$modelInfo}",
            'chapter_id'       => $ch['id'],
            'words'            => $words,
            'done'             => $allDone,
            'model_used'       => $usedModel?->modelLabel,
            'next_chapter_id'  => $saveResult['next_chapter_id'] ?? null,
            'next_chapter_num' => $saveResult['next_chapter_num'] ?? null,
            'quality_warning'  => $qualityWarning,
            'quality_warning_reason' => $qualityWarningReason,
        ]]),
    ]);

} catch (WriteEngineValidationException $e) {
    $errMsg = $e->getMessage();
    writeWorkerAbortIfFatal();
    WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
    updateAsyncProgress(['status' => 'error', 'error' => '章节未通过质量校验，已暂停写作', 'validation_blocked' => true]);
    addLog($novelId, 'error', 'P0 strict validation blocked chapter save: ' . $errMsg);
    writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
    exit(1);
} catch (WriteEnginePersistenceException $e) {
    $errMsg = $e->getMessage();
    writeWorkerAbortIfFatal();
    // 取消只能由明确的取消信号判定。章节已 completed 可能表示正文事务已经
    // 成功提交，不能再把“状态非 writing”误报成用户取消。
    $isCancel = file_exists(WriteEngine::cancelFlagPath($novelId));

    // 审计修复（2026-08-17 写作逻辑 D2）：取消与临近落盘竞态——事务已提交的正文
    // 不因取消回滚。取消路径先探测是否已提交；已提交则标记 canceled_but_saved
    // 并继续走后处理恢复；未提交才按纯取消终止。
    $committed = recoverCommittedChapterAfterSaveError($novelId, $ch, $fullContent);
    if ($isCancel && !$committed) {
        updateAsyncProgress(['status' => 'error', 'error' => '用户已取消写作', 'canceled' => true]);
        exit(1);
    }
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        updateAsyncProgress([
            'status' => 'saved',
            'content_saved' => true,
            'canceled_but_saved' => $isCancel,
            'words' => $words,
            'error' => $isCancel
                ? '用户已取消，但本章正文已在取消前保存完成（不回滚），自动写已停'
                : '正文事务已提交，正在恢复后处理',
        ]);
    } else {
        WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
        updateAsyncProgress(['status' => 'error', 'error' => '章节保存失败，请稍后重试']);
        addLog($novelId, 'error', '落盘异常：' . $errMsg);
        writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
        exit(1);
    }
} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    writeWorkerAbortIfFatal();
    $isCancel = $e instanceof WriteEngineCanceledException
        || file_exists(WriteEngine::cancelFlagPath($novelId));
    // 审计修复（2026-08-17 写作逻辑 D2）：同上——取消竞态下已提交正文不回滚。
    $committed = recoverCommittedChapterAfterSaveError($novelId, $ch, $fullContent);
    if ($isCancel && !$committed) {
        updateAsyncProgress(['status' => 'error', 'error' => '用户已取消写作', 'canceled' => true]);
        exit(1);
    }
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        updateAsyncProgress([
            'status' => 'saved',
            'content_saved' => true,
            'canceled_but_saved' => $isCancel,
            'words' => $words,
            'error' => $isCancel
                ? '用户已取消，但本章正文已在取消前保存完成（不回滚），自动写已停'
                : '正文已保存，正在恢复后处理',
        ]);
    } else {
        WriteEngine::releaseChapterClaim((int)$ch['id'], $ch);
        updateAsyncProgress(['status' => 'error', 'error' => '章节保存失败，请稍后重试']);
        addLog($novelId, 'error', 'Unexpected save failure: ' . $errMsg);
        writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
        exit(1);
    }
}

// ---- Phase 5.5: 成功路径回写小说状态 ----
// 审计修复（2026-08-16 P1，A-3）：此前 worker 仅失败/超时/shutdown 路径更新
// novels.status，单章成功后小说状态残留 'writing'，只能靠僵死清理复位为 draft，
// 与 daemon 收尾（显式置 paused）行为不一致。此处守卫式回落为 paused：
//   - saveChapter 已在全部章节完成时置 completed，WHERE status="writing" 不会覆盖；
//   - 放在 done 上报之前：此刻进度文件仍为 saved（非终态），并发守卫会拦截
//     新任务，不存在「新任务刚置 writing 又被本更新误暂停」的竞态窗口；
//   - Phase 6 后处理失败路径同样守卫式回落 paused，与本更新幂等无冲突。
try {
    writeWorkerSetNovelStatusWhileLeaseCurrent(
        'success novel finalize',
        $novelId,
        'paused'
    );
} catch (Throwable $stateError) {
    // 状态回写失败不阻断后处理与终态上报
    error_log('[write_worker] success state finalize failed: ' . $stateError->getMessage());
}

// ---- Phase 6: WriteEngine 后处理 ----
try {
    // 审计修复（2026-08-17 P3）：进入后处理时同步抬升全局超时（与后处理租约同口径）。
    // 后处理阶段无正文 chunk，$lastContentTime 停更导致延长条件失效，原 1800s 写作
    // 上限会把仍在跑的非流式后处理 AI 调用心跳检查硬杀（exit 跳过清理、flag 残留）。
    $workerGlobalTimeout += 1900;
    $_inPostProcessPhase = true;
    // postprocessing 是中间态；无论成功、异常还是 fatal，之后都必须进入 done/error。
    updateAsyncProgress([
        'status' => 'postprocessing',
        'progress' => 98,
        'content_saved' => true,
    ]);
    $postprocessResult = PostprocessRunner::enqueueAndRun(
        $novelId,
        $ch,
        $fullContent,
        $engine,
        $workerLeaseOwner,
        $writePolicySnapshot,
        $writingTaskLease
    );
    $postprocessState = (string)($postprocessResult['state'] ?? 'pending');
    // 审计修复 P0（2026-09-27）：后处理 AI 调用（非流式 PROGRESSFUNCTION 心跳）
    // 期间可能登记了致命标志，在终态上报前统一退出。
    writeWorkerAbortIfFatal();
    if ($postprocessState === 'lease_lost') {
        // The postprocess job or writing-task fence was taken over while this
        // process was running.  Never mirror the stale process as task=done.
        throw new RuntimeException('Post-processing lease fence was lost.');
    }
    $postprocessPending = in_array($postprocessState, ['queued', 'retry', 'running', 'pending'], true);
    $postprocessFailed = $postprocessState === 'failed';

    // done 是唯一成功终态，此时触发 W-17 的 .content 合并。
    flushChunkBuffer();
    updateAsyncProgress([
        'status' => 'done',
        'progress' => 100,
        'content_saved' => true,
        'postprocess_pending' => $postprocessPending,
        'postprocess_failed' => $postprocessFailed,
        'postprocess_job_id' => (int)($postprocessResult['job_id'] ?? 0),
    ]);
} catch (Throwable $e) {
    // 正文已经 completed，后处理失败不能伪装成整章未保存，也不能遗留等待标志。
    // 致命退出优先（global_timeout 分支内部会执行 pp_pending 清理与状态回落）。
    writeWorkerAbortIfFatal();
    flushChunkBuffer();
    $safePostProcessError = function_exists('sanitizeAiErrorMessage')
        ? sanitizeAiErrorMessage($e->getMessage())
        : $e->getMessage();
    error_log('[write_worker] postProcess failed: ' . $safePostProcessError);
    updateAsyncProgress([
        'status' => 'error',
        'progress' => 100,
        'error' => '正文已保存，但章节后处理失败，请稍后重试后处理',
        'content_saved' => true,
        'postprocess_failed' => true,
    ]);
    // Only the exact terminal winner may remove the shared chapter flag. Its
    // presence blocks a replacement write until this point.
    WriteEngine::clearPostProcessPending($novelId, (int)$ch['chapter_number']);
    try {
        writeWorkerSetNovelStatusAfterTerminal($novelId, 'paused');
        addLog($novelId, 'error', '章节正文已保存，但后处理失败：' . $safePostProcessError);
    } catch (Throwable $stateError) {
        error_log('[write_worker] postProcess failure state update failed: ' . $stateError->getMessage());
    }
    exit(1);
}
