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
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI mode only');
}

// output_buffering 是 PHP_INI_PERDIR 级别，ini_set() 无法修改
// 改用 ob_end_clean() 在运行时清除缓冲区
while (ob_get_level()) ob_end_clean();

define('APP_LOADED', true);

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

// v1.11.9: 清理上一次取消写作残留的 flag 文件，防止新任务误判为"已取消"
// 修复：路径统一到 CFG_PROGRESS_DIR（与 WriteEngine::cancelFlagPath 一致）
@unlink(CFG_PROGRESS_DIR . "/write_cancel_{$novelId}.flag");

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
    global $asyncProgressFile, $asyncTaskId;
    $err = error_get_last();
    if ($err === null) return;
    if (!in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) return;
    if (!$asyncProgressFile || !file_exists($asyncProgressFile)) return;
    $fp = @fopen($asyncProgressFile, 'r+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = stream_get_contents($fp);
    $progress = json_decode($data, true) ?: [];
    // 真正终态只有 done/error。saved/postprocessing 期间若 fatal，必须转入显式 error，
    // 否则轮询端会永远停在中间态；同时保留“正文已保存”的精确信息。
    $previousStatus = (string)($progress['status'] ?? '');
    if (in_array($previousStatus, ['done', 'error'], true)) {
        flock($fp, LOCK_UN); fclose($fp); return;
    }
    $contentSaved = in_array($previousStatus, ['saved', 'postprocessing', 'completed'], true);
    $errMsg = ($err['message'] ?? 'unknown fatal error') . " in {$err['file']}:{$err['line']}";
    $rid = substr(md5(uniqid('', true)), 0, 12);
    $progress['status'] = 'error';
    $progress['error']  = $contentSaved
        ? '正文已保存，但章节后处理失败（追踪号 ' . $rid . '）'
        : '后台写作进程异常，请稍后重试（追踪号 ' . $rid . '）';
    $progress['content_saved'] = $contentSaved;
    $progress['postprocess_failed'] = $contentSaved;
    $progress['progress'] = $contentSaved ? 100 : (int)($progress['progress'] ?? 0);
    $progress['request_id'] = $rid;
    $progress['updated_at'] = time();

    if ($contentSaved) {
        $flagNovelId = (int)($progress['novel_id'] ?? 0);
        $flagChapterNumber = (int)($progress['chapter_number'] ?? 0);
        if ($flagNovelId > 0 && $flagChapterNumber > 0) {
            @unlink(CFG_PROGRESS_DIR . "/pp_pending_{$flagNovelId}_{$flagChapterNumber}.flag");
        }
    }
    fseek($fp, 0);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($contentSaved && class_exists('DB', false)) {
        try {
            DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [(int)($progress['novel_id'] ?? 0)]);
            $fatalChapterId = (int)($progress['chapter_id'] ?? 0);
            if ($fatalChapterId > 0 && class_exists('PostprocessJobRepository', false)) {
                $fatalChapter = DB::fetch(
                    'SELECT novel_id, content FROM chapters WHERE id=? AND status="completed" LIMIT 1',
                    [$fatalChapterId]
                );
                if ($fatalChapter && (string)($fatalChapter['content'] ?? '') !== '') {
                    PostprocessJobRepository::enqueue(
                        (int)$fatalChapter['novel_id'],
                        $fatalChapterId,
                        hash('sha256', (string)$fatalChapter['content'])
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
    if (class_exists('WritingTaskRepository', false)) {
        try {
            WritingTaskRepository::markFailed(
                $asyncTaskId,
                $progress['error'],
                $contentSaved,
                false,
                'worker_fatal'
            );
        } catch (Throwable $taskStateError) {
            error_log('[write_worker] fatal durable task update failed: ' . $taskStateError->getMessage());
        }
    }
    error_log("[write_worker] 致命错误: {$errMsg}");
});

// 关键：尽早把状态置为 'writing'，让 write_start 立刻确认 worker 已启动。
// 必须在加载 ai.php / write_engine.php 等重型依赖之前——CLI（通常无 OPcache）冷启动编译这些大文件
// 可能耗时较久；若拖过 write_start 的确认窗口，会被误判为“启动失败”而回退到 SSE（无实时输出）。
(function() use ($asyncProgressFile) {
    $fp = @fopen($asyncProgressFile, 'r+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $d = json_decode(stream_get_contents($fp), true) ?: [];
    $d['status'] = 'writing';
    $d['pid'] = getmypid();
    $d['updated_at'] = time();
    fseek($fp, 0); ftruncate($fp, 0);
    fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN); fclose($fp);
})();

// 重型依赖（编译较慢，但此前已上报 'writing'，write_start 不会误判为启动失败）
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/tasks/WritingTaskRepository.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';

// config.php 加载后方可使用其常量
set_time_limit(CFG_TIME_UNLIMITED);  // CLI 模式不限时
ignore_user_abort(true);

$workerStartTime = time();
$workerGlobalTimeout = 1800;

// ---- 引入 write_chapter.php 的核心逻辑 ----
// 不能直接 require，因为 headers 已发。我们只复用函数定义。

$lastHeartbeat = time();
$_writingChapterId = null;

// 写入缓冲：攒一批 token 再刷新进度文件，减少 I/O 压力
$chunkBuffer = '';
$chunkBufferCount = 0;
$lastFlushTime = microtime(true);
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

function flushChunkBuffer(): void {
    global $asyncProgressFile, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
    if ($chunkBuffer === '' || !$asyncProgressFile || !file_exists($asyncProgressFile)) return;

    // W-17 修复：内容追加到独立的 .content 文件（O(buffer) 写入），元数据 JSON 保持小尺寸。
    // 长章节场景：500+ 次 flush 时，原方案每次完整 decode/encode 增长的 JSON（content 字段越来越大），
    // 改进后内容追加为 O(buffer)，元数据 JSON 不再含 content。write_poll.php 从 .content 读取。
    $contentFile = $asyncProgressFile . '.content';
    @file_put_contents($contentFile, $chunkBuffer, FILE_APPEND | LOCK_EX);

    // 元数据 JSON 仅更新进度/心跳/状态字段，不再追加 content 字符串
    $fp = fopen($asyncProgressFile, 'r+');
    if (!$fp) { $chunkBuffer = ''; $chunkBufferCount = 0; $lastFlushTime = microtime(true); return; }
    flock($fp, LOCK_EX);
    $data = stream_get_contents($fp);
    $progress = json_decode($data, true) ?: [];
    $progress['status'] = 'writing';
    $progress['progress'] = min(90, ($progress['progress'] ?? 0) + $chunkBufferCount * 0.1);
    $progress['updated_at'] = time();
    // 标记内容已分离（write_poll.php 优先读 .content 文件）
    $progress['_content_split'] = true;
    fseek($fp, 0);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    $chunkBuffer = '';
    $chunkBufferCount = 0;
    $lastFlushTime = microtime(true);
}

function updateAsyncProgress(array $updates): void {
    global $asyncProgressFile, $asyncTaskId, $chunkBuffer, $chunkBufferCount;
    // 先刷新未写入的缓冲内容
    if ($chunkBuffer !== '') {
        $updates['content'] = ($updates['content'] ?? '') . $chunkBuffer;
        $chunkBuffer = '';
        $chunkBufferCount = 0;
    }
    if (!$asyncProgressFile || !file_exists($asyncProgressFile)) return;
    $fp = fopen($asyncProgressFile, 'r+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = stream_get_contents($fp);
    $progress = json_decode($data, true) ?: [];
    // W-17 修复：终态时（done/completed/error）合并 .content 文件回 JSON 的 content 字段，
    // 让下游消费者（write_poll、清理逻辑）拿到完整正文；并清理拆分文件。
    $terminalStatus = $updates['status'] ?? null;
    // Task 2：仅 done/error 触发 W-17 的 .content 终态合并。saved 不再是终态——合并
    // 推迟到真正的 done（此时 _content_split 仍为真，合并照常发生，已静态验证兼容）。
    $isTerminal = isWriteProgressTerminalStatus($terminalStatus);
    if ($isTerminal && !empty($progress['_content_split'])) {
        $contentFile = $asyncProgressFile . '.content';
        if (file_exists($contentFile)) {
            $splitContent = @file_get_contents($contentFile) ?: '';
            // 如果 updates 已经带了 content（来自 chunkBuffer），追加而非覆盖
            $updates['content'] = ($splitContent) . ($updates['content'] ?? '');
            @unlink($contentFile);
        }
        $progress['_content_split'] = false;
    }
    fseek($fp, 0);
    ftruncate($fp, 0);
    $progress = array_merge($progress, $updates, ['updated_at' => time()]);
    fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);

    // v53: keep streamed content on disk, but mirror low-frequency lifecycle state to MySQL.
    try {
        $status = (string)($progress['status'] ?? 'writing');
        if ($status === 'done') {
            $result = array_intersect_key($progress, array_flip([
                'words', 'chapter_id', 'messages', 'model_used', 'postprocess_pending', 'postprocess_failed',
            ]));
            $revisionHash = null;
            $durableChapterId = (int)($progress['chapter_id'] ?? 0);
            if ($durableChapterId > 0) {
                $storedContent = DB::fetchColumn('SELECT content FROM chapters WHERE id=? LIMIT 1', [$durableChapterId]);
                if (is_string($storedContent) && $storedContent !== '') {
                    $revisionHash = hash('sha256', $storedContent);
                }
            }
            WritingTaskRepository::markDone(
                $asyncTaskId,
                $result,
                !empty($progress['content_saved']),
                $revisionHash
            );
        } elseif ($status === 'error') {
            WritingTaskRepository::markFailed(
                $asyncTaskId,
                (string)($progress['error'] ?? 'Worker failed'),
                !empty($progress['content_saved']),
                !empty($progress['canceled']),
                !empty($progress['canceled']) ? 'canceled' : 'worker_failed'
            );
        } else {
            $durableState = match ($status) {
                'starting' => 'queued',
                'saved' => 'content_saved',
                'postprocessing' => 'postprocessing',
                default => 'generating',
            };
            WritingTaskRepository::heartbeat(
                $asyncTaskId,
                $durableState,
                (int)($progress['progress'] ?? 0),
                isset($progress['error']) ? (string)$progress['error'] : null,
                $durableState === 'postprocessing' ? 1800 : 180
            );
        }
    } catch (Throwable $taskStateError) {
        error_log('[write_worker] durable task state sync failed: ' . $taskStateError->getMessage());
    }
}

function sendHeartbeatWrite(): void {
    global $lastHeartbeat, $asyncTaskId, $chunkBuffer, $chunkBufferCount, $lastFlushTime, $_writingChapterId;
    global $workerStartTime, $workerGlobalTimeout, $novelId, $ch;
    global $lastContentTime;  // v2 fix: 追踪最后一次收到 chunk 的时间
    $now = microtime(true);

    // 每次调用都刷新章节 updated_at（防止 Watchdog 误杀）
    // 必须在间隔检查之前：深度思考模型可能长时间无 chunk，心跳间隔不稳定
    if ($_writingChapterId > 0) {
        try {
            DB::query('UPDATE chapters SET updated_at = NOW() WHERE id = ? AND status = "writing"', [$_writingChapterId]);
        } catch (\Throwable $e) { error_log('write_chapter_worker heartbeat update failed: ' . $e->getMessage()); }
    }

    if ($workerStartTime > 0 && (time() - $workerStartTime) > $workerGlobalTimeout) {
        // 如果近期有内容产出（AI 仍在工作），自动延长超时而非误杀
        // $lastContentTime 在 sseChunkWrite 中更新
        if (isset($lastContentTime) && (time() - $lastContentTime) < 120) {
            $workerGlobalTimeout += 600;  // 额外给10分钟
            addLog($novelId, 'warn', '全局超时但AI仍在产出内容，自动延长600秒');
            return;
        }
        flushChunkBuffer();
        $elapsed = time() - $workerStartTime;
        error_log("[write_worker] 全局超时（{$elapsed}s > {$workerGlobalTimeout}s），强制退出");
        if ($_writingChapterId > 0) {
            try {
                DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$_writingChapterId]);
                DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
            } catch (\Throwable $e) { error_log('worker status reset failed: ' . $e->getMessage()); }
        }
        updateAsyncProgress(['status' => 'error', 'error' => "写作全局超时（{$elapsed}秒），已自动恢复"]);
        exit(1);
    }
    if ($chunkBuffer !== '' && ($now - $lastFlushTime >= CHUNK_FLUSH_INTERVAL || $chunkBufferCount >= CHUNK_FLUSH_COUNT)) {
        flushChunkBuffer();
    }
    if ($now - $lastHeartbeat < 10) return;
    try {
        if (WritingTaskRepository::isCancelRequested($asyncTaskId)) {
            @file_put_contents(WriteEngine::cancelFlagPath($novelId), (string)time(), LOCK_EX);
        }
    } catch (Throwable $cancelStateError) {
        error_log('[write_worker] durable cancel check failed: ' . $cancelStateError->getMessage());
    }
    updateAsyncProgress(['status' => 'writing', 'heartbeat' => $now]);
    $lastHeartbeat = $now;
}

function sseChunkWrite(string $chunk): void {
    global $asyncProgressFile, $asyncMessages, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
    global $lastContentTime;  // v2 fix: 追踪最后一次内容产出时间
    $lastContentTime = time();
    // 缓冲 token，减少磁盘 I/O
    $chunkBuffer .= $chunk;
    $chunkBufferCount++;
    // 达到刷新阈值时写入文件
    if ($chunkBufferCount >= CHUNK_FLUSH_COUNT || (microtime(true) - $lastFlushTime >= CHUNK_FLUSH_INTERVAL)) {
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

    if (file_put_contents($asyncProgressFile . '.content', '', LOCK_EX) === false) {
        throw new RuntimeException('重试时无法清空失败正文，已停止生成以防正文串联');
    }
    $fp = fopen($asyncProgressFile, 'r+');
    if (!$fp) {
        throw new RuntimeException('重试时无法更新正文版本，已停止生成以防正文串联');
    }
    flock($fp, LOCK_EX);
    try {
        $data = stream_get_contents($fp);
        $progress = json_decode($data, true) ?: [];
        $progress['content'] = '';
        $progress['_content_split'] = true;
        $progress['content_revision'] = (int)($progress['content_revision'] ?? 0) + 1;
        $progress['updated_at'] = time();
        fseek($fp, 0);
        ftruncate($fp, 0);
        if (fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('重试时正文版本写入失败，已停止生成以防正文串联');
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    $chunkBuffer = '';
    $chunkBufferCount = 0;
    $lastFlushTime = microtime(true);
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
$lastThinkingFlush = microtime(true);

function sseThinkingWrite(string $chunk): void {
    global $thinkingBuffer, $lastThinkingFlush, $thinkingFlushInterval;
    $thinkingBuffer .= $chunk;
    // 每隔一段时间将思考过程写入进度文件
    if (microtime(true) - $lastThinkingFlush >= $thinkingFlushInterval) {
        flushThinkingBuffer();
    }
}

function flushThinkingBuffer(): void {
    global $thinkingBuffer, $lastThinkingFlush, $asyncProgressFile;
    if ($thinkingBuffer === '' || !$asyncProgressFile || !file_exists($asyncProgressFile)) return;
    $fp = fopen($asyncProgressFile, 'r+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = stream_get_contents($fp);
    $progress = json_decode($data, true) ?: [];
    $progress['thinking_content'] = ($progress['thinking_content'] ?? '') . $thinkingBuffer;
    $progress['updated_at'] = time();
    fseek($fp, 0);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    $thinkingBuffer = '';
    $lastThinkingFlush = microtime(true);
}

function sseDoneWrite(): void {
    flushThinkingBuffer(); // 确保最后一批思考内容也写入
    updateAsyncProgress(['status' => 'done', 'progress' => 100]);
}

function recoverCommittedChapterAfterSaveError(int $novelId, array $chapter, string $generatedContent): ?array {
    if ($generatedContent === '' || empty($chapter['id'])) return null;
    $stored = DB::fetch(
        'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" LIMIT 1',
        [(int)$chapter['id'], $novelId]
    );
    if (!$stored) return null;

    $expected = stripMetaLeaks(stripSegmentMarkers($generatedContent));
    if (!hash_equals(hash('sha256', $expected), hash('sha256', (string)($stored['content'] ?? '')))) {
        return null;
    }
    return $stored;
}

// 注册全局心跳函数（供 AIClient 的 CURLOPT_PROGRESSFUNCTION 调用）
$GLOBALS['sendHeartbeat'] = 'sendHeartbeatWrite';
// 审计优化 P2-1（2026-06-16）：保留 $GLOBALS['sendWaiting'] 作为向后兼容回退，
// 同时定义为局部变量 $sendWaitingCb 显式传入 streamWrite。
$GLOBALS['sendWaiting'] = function(int $elapsedSeconds) {
    global $asyncMessages;
    $asyncMessages[] = ['waiting' => true, 'msg' => "AI 思考中（已等待 {$elapsedSeconds} 秒）…", 'elapsed' => $elapsedSeconds];
    updateAsyncProgress(['messages' => $asyncMessages, 'status' => 'waiting']);
};
$sendWaitingCb = $GLOBALS['sendWaiting'];

try {
    if (!WritingTaskRepository::markRunning(
        $taskId,
        $chapterId ?: null,
        'write-worker:' . (gethostname() ?: 'localhost') . ':' . getmypid()
    )) {
        $wasCanceled = WritingTaskRepository::isCancelRequested($taskId);
        updateAsyncProgress([
            'status' => 'error',
            'error' => $wasCanceled ? '用户已取消写作' : '写作任务租约无效或已结束',
            'canceled' => $wasCanceled,
        ]);
        exit(1);
    }
} catch (Throwable $taskStartError) {
    updateAsyncProgress(['status' => 'error', 'error' => '写作任务持久状态初始化失败']);
    error_log('[write_worker] durable task startup failed: ' . $taskStartError->getMessage());
    exit(1);
}

// ---- 核心写作逻辑（与 write_chapter.php 相同）----
updateAsyncProgress(['status' => 'writing', 'pid' => getmypid()]);

// Phase 1-3: WriteEngine 解析章节 / 记忆初始化 / 组装 Prompt
try {
    $resolved = WriteEngine::resolveChapter($novelId, $chapterId);
    $novel    = $resolved['n'];
    $ch       = $resolved['ch'];
    $_writingChapterId = (int)$ch['id'];

    // 小说自身的 chapter_words 优先，全局 ws_chapter_words 仅作为兜底默认值
    $novelWords = (int)($novel['chapter_words'] ?? 0);
    if ($novelWords >= 500) {
        // 小说已有有效字数设置，保留
    } else {
        // 小说未设置或值异常，使用全局默认值
        $novelWords = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
    }
    $novel['chapter_words'] = max(500, $novelWords);
} catch (RuntimeException $e) {
    error_log("[write_worker] Phase1 resolveChapter 失败: {$e->getMessage()}");
    updateAsyncProgress(['status' => 'error', 'error' => '章节准备失败，请稍后重试']);
    exit(1);
}

updateAsyncProgress(['chapter_id' => $ch['id'], 'chapter_number' => (int)$ch['chapter_number']]);

$preAiClient = null;
try {
    $preAiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
    if ($preAiClient->is1MContext()) {
        addLog($novelId, 'info', '检测到1M上下文模型，将使用完整上下文模式');
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
    addLog($novelId, 'error', 'MemoryEngine 初始化失败：' . $e->getMessage());
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    $engine    = new MemoryEngine($novelId);
    $memoryCtx = null;
}

try {
    $messages = WriteEngine::buildPrompt($novel, $ch, $memoryCtx);
} catch (Throwable $e) {
    error_log("[write_worker] Phase3 buildPrompt 失败: {$e->getMessage()}");
    addLog($novelId, 'error', 'buildPrompt 失败：' . $e->getMessage());
    updateAsyncProgress(['status' => 'error', 'error' => '写作提示词构建失败，请稍后重试']);
    DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    exit(1);
}
$targetWords = (int)$novel['chapter_words'];
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
        $sendWaitingCb
    );
    $fullContent       = $result['content'];
    $usedModel         = $result['model'];
    $streamUsage       = $result['usage'] ?? null;
    $streamDurationMs  = $result['duration_ms'] ?? null;
} catch (Exception $e) {
    $msg = $e->getMessage();
    error_log("[write_worker] Phase4 streamWrite 失败: {$msg}");
    $isCancel = strpos($msg, '取消') !== false;
    flushChunkBuffer();
    DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
    // W-13 修复：streamWrite 失败属于可重试的瞬时错误（API 限流/网络），改 'draft' 让用户直接重试
    DB::update('novels', ['status' => 'draft'], 'id=?', [$novelId]);
    updateAsyncProgress(['status' => 'error', 'error' => $isCancel ? '用户已取消写作' : '模型生成失败，请稍后重试', 'canceled' => $isCancel]);
    exit(1);
}

// ---- Phase 5: WriteEngine 保存章节 ----
try {
    $saveResult = WriteEngine::saveChapter(
        (int)$ch['id'], $novelId, $fullContent, $targetWords, $usedModel, $ch, $streamUsage, $streamDurationMs
    );
    $words     = $saveResult['words'];
    $ch        = $saveResult['chapter'];
    $allDone   = $saveResult['all_done'];
    $modelInfo = $saveResult['model_info'];

    // 更新进度：正文已落盘（Task 2：saved 而非 completed——后处理尚未执行，不是任务终态）。
    // 字段 words/chapter_id/messages 经 updateAsyncProgress 的 array_merge 会保留到最终 done，
    // 前端在 done 时仍能读到（前端完成处理读顶层 words/chapter_id，见 app.js）。
    updateAsyncProgress([
        'status'     => 'saved',
        'progress'   => 95,
        'content_saved' => true,
        'words'      => $words,
        'model_used' => $usedModel?->modelLabel,
        'messages'   => array_merge($asyncMessages, [[
            'stats'            => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字{$modelInfo}",
            'chapter_id'       => $ch['id'],
            'words'            => $words,
            'done'             => $allDone,
            'model_used'       => $usedModel?->modelLabel,
            'next_chapter_id'  => $saveResult['next_chapter_id'] ?? null,
            'next_chapter_num' => $saveResult['next_chapter_num'] ?? null,
        ]]),
    ]);

} catch (WriteEngineValidationException $e) {
    $errMsg = $e->getMessage();
    addLog($novelId, 'error', 'P0 strict validation blocked chapter save: ' . $errMsg);
    DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$ch['id']]);
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    updateAsyncProgress(['status' => 'error', 'error' => '章节未通过质量校验，已暂停写作', 'validation_blocked' => true]);
    exit(1);
} catch (WriteEnginePersistenceException $e) {
    $errMsg = $e->getMessage();
    // 取消只能由明确的取消信号判定。章节已 completed 可能表示正文事务已经
    // 成功提交，不能再把“状态非 writing”误报成用户取消。
    $isCancel = ($errMsg === 'canceled')
        || file_exists(WriteEngine::cancelFlagPath($novelId));

    if ($isCancel) {
        updateAsyncProgress(['status' => 'error', 'error' => '用户已取消写作', 'canceled' => true]);
        exit(1);
    }
    addLog($novelId, 'error', '落盘异常：' . $errMsg);
    $committed = recoverCommittedChapterAfterSaveError($novelId, $ch, $fullContent);
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        updateAsyncProgress([
            'status' => 'saved',
            'content_saved' => true,
            'words' => $words,
            'error' => '正文事务已提交，正在恢复后处理',
        ]);
    } else {
        DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$ch['id']]);
        DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
        updateAsyncProgress(['status' => 'error', 'error' => '章节保存失败，请稍后重试']);
        exit(1);
    }
} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    $isCancel = ($errMsg === 'canceled')
        || file_exists(WriteEngine::cancelFlagPath($novelId));
    if ($isCancel) {
        updateAsyncProgress(['status' => 'error', 'error' => '用户已取消写作', 'canceled' => true]);
        exit(1);
    }
    addLog($novelId, 'error', 'Unexpected save failure: ' . $errMsg);
    $committed = recoverCommittedChapterAfterSaveError($novelId, $ch, $fullContent);
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        updateAsyncProgress([
            'status' => 'saved',
            'content_saved' => true,
            'words' => $words,
            'error' => '正文已保存，正在恢复后处理',
        ]);
    } else {
        DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$ch['id']]);
        DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
        updateAsyncProgress(['status' => 'error', 'error' => '章节保存失败，请稍后重试']);
        exit(1);
    }
}

// ---- Phase 6: WriteEngine 后处理 ----
try {
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
        'write-worker:' . (gethostname() ?: 'localhost') . ':' . getmypid()
    );
    $postprocessState = (string)($postprocessResult['state'] ?? 'pending');
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
    WriteEngine::clearPostProcessPending($novelId, (int)$ch['chapter_number']);
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
    try {
        DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [$novelId]);
        addLog($novelId, 'error', '章节正文已保存，但后处理失败：' . $safePostProcessError);
    } catch (Throwable $stateError) {
        error_log('[write_worker] postProcess failure state update failed: ' . $stateError->getMessage());
    }
    exit(1);
}
