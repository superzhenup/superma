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
// 此脚本第 33 行会伪造 $_SESSION['logged_in']=true 跳过登录校验，
// 必须确保仅 CLI 模式可入。HTTP 访问立刻 403 退出。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI mode only');
}

// output_buffering 是 PHP_INI_PERDIR 级别，ini_set() 无法修改
// 改用 ob_end_clean() 在运行时清除缓冲区
while (ob_get_level()) ob_end_clean();

define('APP_LOADED', true);
define('CLI_MODE', true);

// CLI 模式下不需要 session，但 auth.php 会调用 session_start()
// 提前模拟 session 已启动以避免报错
if (session_status() === PHP_SESSION_NONE) {
    // 在 CLI 下 session_start() 可能失败，但不影响写作
    @session_start();
}

// 模拟 HTTP 环境
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/api/write_chapter.php';
// 模拟已登录状态（write_start.php 已验证登录）
$_SESSION['logged_in'] = true;

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
    if (in_array($progress['status'] ?? '', ['done', 'completed', 'error'])) {
        flock($fp, LOCK_UN); fclose($fp); return;
    }
    $errMsg = ($err['message'] ?? 'unknown fatal error') . " in {$err['file']}:{$err['line']}";
    $progress['status'] = 'error';
    $progress['error']  = 'Worker致命错误：' . $errMsg;
    $progress['updated_at'] = time();
    fseek($fp, 0);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
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

function flushChunkBuffer(): void {
    global $asyncProgressFile, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
    if ($chunkBuffer === '' || !$asyncProgressFile || !file_exists($asyncProgressFile)) return;
    $fp = fopen($asyncProgressFile, 'r+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = stream_get_contents($fp);
    $progress = json_decode($data, true) ?: [];
    $progress['content'] = ($progress['content'] ?? '') . $chunkBuffer;
    $progress['status'] = 'writing';
    $progress['progress'] = min(90, ($progress['progress'] ?? 0) + $chunkBufferCount * 0.1);
    $progress['updated_at'] = time();
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
    global $asyncProgressFile, $chunkBuffer, $chunkBufferCount;
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
    fseek($fp, 0);
    ftruncate($fp, 0);
    $progress = array_merge($progress, $updates, ['updated_at' => time()]);
    fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
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
        } catch (\Throwable) {}
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
            } catch (\Throwable) {}
        }
        updateAsyncProgress(['status' => 'error', 'error' => "写作全局超时（{$elapsed}秒），已自动恢复"]);
        exit(1);
    }
    if ($chunkBuffer !== '' && ($now - $lastFlushTime >= CHUNK_FLUSH_INTERVAL || $chunkBufferCount >= CHUNK_FLUSH_COUNT)) {
        flushChunkBuffer();
    }
    if ($now - $lastHeartbeat < 10) return;
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

function sseMsgWrite(array $payload): void {
    global $asyncMessages;
    sendHeartbeatWrite();
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

// 注册全局心跳函数（供 AIClient 的 CURLOPT_PROGRESSFUNCTION 调用）
$GLOBALS['sendHeartbeat'] = 'sendHeartbeatWrite';
$GLOBALS['sendWaiting'] = function(int $elapsedSeconds) {
    global $asyncMessages;
    $asyncMessages[] = ['waiting' => true, 'msg' => "AI 思考中（已等待 {$elapsedSeconds} 秒）…", 'elapsed' => $elapsedSeconds];
    updateAsyncProgress(['messages' => $asyncMessages, 'status' => 'waiting']);
};

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
    updateAsyncProgress(['status' => 'error', 'error' => $e->getMessage()]);
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
    updateAsyncProgress(['status' => 'error', 'error' => 'Prompt构建失败：' . $e->getMessage()]);
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
        (int)$novel['target_chapters']
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
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    updateAsyncProgress(['status' => 'error', 'error' => $msg, 'canceled' => $isCancel]);
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

    // 更新进度：正文已完成
    updateAsyncProgress([
        'status'     => 'completed',
        'progress'   => 95,
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
    updateAsyncProgress(['status' => 'error', 'error' => $errMsg, 'validation_blocked' => true]);
    exit(1);
} catch (WriteEnginePersistenceException $e) {
    $errMsg = $e->getMessage();
    if ($errMsg === 'canceled') {
        updateAsyncProgress(['status' => 'error', 'error' => '用户已取消写作', 'canceled' => true]);
        exit(1);
    }
    addLog($novelId, 'error', '落盘异常：' . $errMsg);
    $backupSaved = false;
    if (!empty($fullContent)) {
        $currentCh = DB::fetch('SELECT status FROM chapters WHERE id=?', [$ch['id']]);
        if ($currentCh && $currentCh['status'] === 'writing') {
            $words = countWords($fullContent);
            DB::update('chapters', ['content' => $fullContent, 'words' => $words, 'status' => 'completed'], 'id=?', [$ch['id']]);
            updateNovelStats($novelId);
            $backupSaved = true;
        }
    }
    if ($backupSaved) {
        updateAsyncProgress(['status' => 'completed', 'error' => '正文已保存但落盘异常：' . $errMsg]);
    } else {
        updateAsyncProgress(['status' => 'error', 'error' => $errMsg]);
        exit(1);
    }
} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    if ($errMsg === 'canceled') {
        updateAsyncProgress(['status' => 'error', 'error' => '用户已取消写作', 'canceled' => true]);
        exit(1);
    }
    addLog($novelId, 'error', 'Unexpected save failure: ' . $errMsg);
    DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$ch['id']]);
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    updateAsyncProgress(['status' => 'error', 'error' => $errMsg]);
    exit(1);
}

// ---- Phase 6: WriteEngine 后处理 ----
WriteEngine::postProcess($novelId, $ch, $fullContent, $engine);

// 标记最终完成（确保缓冲区刷入）
flushChunkBuffer();
updateAsyncProgress(['status' => 'done', 'progress' => 100]);
