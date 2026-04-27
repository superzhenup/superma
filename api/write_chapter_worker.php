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

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';

// config.php 加载后方可使用其常量
set_time_limit(CFG_TIME_UNLIMITED);  // CLI 模式不限时
ignore_user_abort(true);

// CLI 参数
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

// ---- 引入 write_chapter.php 的核心逻辑 ----
// 不能直接 require，因为 headers 已发。我们只复用函数定义。

$lastHeartbeat = time();

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
    global $lastHeartbeat, $asyncTaskId, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
    $now = microtime(true);
    // 检查是否需要刷新缓冲（时间到了或数量够了）
    if ($chunkBuffer !== '' && ($now - $lastFlushTime >= CHUNK_FLUSH_INTERVAL || $chunkBufferCount >= CHUNK_FLUSH_COUNT)) {
        flushChunkBuffer();
    }
    if ($now - $lastHeartbeat < 10) return;
    updateAsyncProgress(['status' => 'writing', 'heartbeat' => $now]);
    $lastHeartbeat = $now;
}

function sseChunkWrite(string $chunk): void {
    global $asyncProgressFile, $asyncMessages, $chunkBuffer, $chunkBufferCount, $lastFlushTime;
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

function sseDoneWrite(): void {
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
} catch (RuntimeException $e) {
    updateAsyncProgress(['status' => 'error', 'error' => $e->getMessage()]);
    exit(1);
}

updateAsyncProgress(['chapter_id' => $ch['id'], 'chapter_number' => (int)$ch['chapter_number']]);

try {
    $memResult = WriteEngine::initMemory($novelId, $ch);
    $engine    = $memResult['engine'];
    $memoryCtx = $memResult['memoryCtx'];
} catch (Throwable $e) {
    addLog($novelId, 'error', 'MemoryEngine 初始化失败：' . $e->getMessage());
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    $engine    = new MemoryEngine($novelId);
    $memoryCtx = null;
}

$messages    = WriteEngine::buildPrompt($novel, $ch, $memoryCtx);
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
        function() { sendHeartbeatWrite(); }
    );
    $fullContent = $result['content'];
    $usedModel   = $result['model'];
} catch (Exception $e) {
    $msg = $e->getMessage();
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
        (int)$ch['id'], $novelId, $fullContent, $targetWords, $usedModel, $ch
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
            'stats'      => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字{$modelInfo}",
            'chapter_id' => $ch['id'],
            'words'      => $words,
            'done'       => $allDone,
            'model_used' => $usedModel?->modelLabel,
        ]]),
    ]);

} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    if ($errMsg === 'canceled') {
        updateAsyncProgress(['status' => 'error', 'error' => '用户已取消写作', 'canceled' => true]);
        exit(1);
    }
    addLog($novelId, 'error', '落盘异常：' . $errMsg);
    if (!empty($fullContent)) {
        $currentCh = DB::fetch('SELECT status FROM chapters WHERE id=?', [$ch['id']]);
        if ($currentCh && $currentCh['status'] === 'writing') {
            $words = countWords($fullContent);
            DB::update('chapters', ['content' => $fullContent, 'words' => $words, 'status' => 'completed'], 'id=?', [$ch['id']]);
            updateNovelStats($novelId);
        }
    }
    updateAsyncProgress(['status' => 'error', 'error' => '正文已保存但落盘异常：' . $errMsg]);
}

// ---- Phase 6: WriteEngine 后处理 ----
WriteEngine::postProcess($novelId, $ch, $fullContent, $engine);

// 标记最终完成（确保缓冲区刷入）
flushChunkBuffer();
updateAsyncProgress(['status' => 'done', 'progress' => 100]);
