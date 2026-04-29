<?php
/**
 * 写作章节 API（流式 SSE + 模型自动 fallback）
 * 优化：修复摘要生成竞态条件——摘要同步完成后再发送完成信号
 * POST JSON: { novel_id, chapter_id? }
 * 
 * v4 优化：
 * - 添加 SSE 心跳机制，每 10 秒发送心跳防止连接超时
 * - 强制禁用输出缓冲，确保 SSE 实时传输
 */

// 强制禁用输出缓冲（确保 SSE 实时传输）
// 注意：output_buffering 是 PHP_INI_PERDIR 级别，ini_set() 无法修改，
// 改用 ob_end_clean() 在运行时清除缓冲区
while (ob_get_level()) ob_end_clean();
ini_set('implicit_flush', 'On');
ini_set('zlib.output_compression', 'Off');

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
requireLoginApi();
session_write_close();

ob_end_clean();
set_time_limit(CFG_TIME_LONG);
// 关键：即使前端断开连接，后端也要继续执行完成（保存内容、生成摘要等）
// 否则 ERR_INCOMPLETE_CHUNKED_ENCODING 会导致后端中断，章节内容丢失
ignore_user_abort(true);

while (ob_get_level()) ob_end_clean();

// ---- 异步任务变量（必须在异常处理器之前初始化，避免 Undefined variable）----
$asyncTaskId = null;
$asyncProgressFile = null;
$asyncMessages = [];

// 全局异常捕获，确保发生错误时正常结束SSE连接，避免触发 ERR_INCOMPLETE_CHUNKED_ENCODING
set_exception_handler(function (Throwable $e) {
    global $asyncTaskId;
    if ($asyncTaskId) {
        updateAsyncProgress([
            'status' => 'error',
            'error'  => 'Fatal Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    } else {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
        }
        echo 'data: ' . json_encode([
            'error' => 'Fatal Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
});

set_error_handler(function ($severity, $message, $file, $line) {
    // 尊重 @ 错误抑制符：error_reporting() 在 @ 抑制时返回 0
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    global $asyncTaskId;
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if ($asyncTaskId) {
            updateAsyncProgress([
                'status' => 'error',
                'error'  => 'Fatal Shutdown Error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'],
            ]);
        } else {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: text/event-stream; charset=utf-8');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no');
            }
            echo 'data: ' . json_encode([
                'error' => 'Fatal Shutdown Error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            echo "data: [DONE]\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
    }
});

// ---- 异步任务模式检测 ----
// 当 _task_id 参数存在时，写作过程不再输出 SSE 到浏览器，
// 而是将进度写入临时文件，由 write_poll.php 轮询读取。
// 这彻底绕过 Nginx/FPM 的长连接超时限制。
// （$asyncTaskId / $asyncProgressFile / $asyncMessages 已在文件顶部初始化）

// ---- 解析入参 ----
$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId   = (int)($input['novel_id']   ?? 0);
$chapterId = (int)($input['chapter_id'] ?? 0);

if (!empty($input['_task_id'])) {
    $asyncTaskId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['_task_id']);
    $progressDir = CFG_PROGRESS_DIR;
    $asyncProgressFile = $progressDir . '/' . $asyncTaskId . '.json';
    if (!file_exists($asyncProgressFile)) {
        // 进度文件不存在，任务可能已过期
        $asyncTaskId = null;
        $asyncProgressFile = null;
    }
}

// 根据模式发送不同的响应头/输出（必须在任何内容输出前执行）
if (!$asyncTaskId) {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    header('Content-Encoding: none');
    header('Transfer-Encoding: chunked');
} else {
    // 异步模式：返回简单的 JSON 确认，Nginx 会很快关闭这个连接
    // 实际写作进度在后台进行，通过 write_poll.php 轮询
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'task_id' => $asyncTaskId, 'async' => true], JSON_UNESCAPED_UNICODE);
    if (ob_get_level()) ob_flush();
    flush();
    // 关闭输出缓冲，后续输出不再发送给浏览器
    while (ob_get_level()) ob_end_clean();
}

// ---- retry helper classes ----
class RetryExhaustedException extends RuntimeException {}
class SwitchModelException extends RuntimeException {
    public string $reason;
    public function __construct(string $reason) {
        parent::__construct($reason);
        $this->reason = $reason;
    }
}

// ============================================================
// SSE 心跳机制 + 异步进度写入
// ============================================================
$lastHeartbeat = time();

// 更新异步进度文件（线程安全）
function updateAsyncProgress(array $updates): void {
    global $asyncProgressFile;
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
    global $lastHeartbeat, $asyncTaskId;
    $now = time();
    if ($now - $lastHeartbeat < CFG_SSE_HEARTBEAT) return;
    
    if ($asyncTaskId) {
        // 异步模式：更新进度文件的时间戳（保活）
        updateAsyncProgress(['status' => 'writing', 'heartbeat' => $now]);
    } else {
        // SSE 模式：发送心跳事件
        echo "event: heartbeat\n";
        echo "data: " . json_encode(['time' => $now, 'msg' => 'keep-alive']) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
    $lastHeartbeat = $now;
}

function sseChunkWrite(string $chunk): void {
    global $asyncTaskId, $asyncProgressFile, $asyncMessages;
    sendHeartbeatWrite();
    
    if ($asyncTaskId) {
        // 异步模式：将新文字追加到进度文件
        $fp = fopen($asyncProgressFile, 'r+');
        if ($fp) {
            flock($fp, LOCK_EX);
            $data = stream_get_contents($fp);
            $progress = json_decode($data, true) ?: [];
            $progress['content'] = ($progress['content'] ?? '') . $chunk;
            $progress['status'] = 'writing';
            $progress['progress'] = min(90, ($progress['progress'] ?? 0) + 0.1);
            $progress['updated_at'] = time();
            fseek($fp, 0);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    } else {
        // SSE 模式
        echo 'data: ' . json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

function sseMsgWrite(array $payload): void {
    global $asyncTaskId, $asyncMessages;
    sendHeartbeatWrite();
    
    // 收集消息供异步模式使用
    $asyncMessages[] = $payload;
    
    if ($asyncTaskId) {
        // 异步模式：将消息追加到进度文件的 messages 数组
        updateAsyncProgress([
            'messages' => $asyncMessages,
            'status'   => $payload['status'] ?? ($payload['waiting'] ? 'waiting' : 'writing'),
        ]);
    } else {
        // SSE 模式
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

function sseThinkingWrite(string $thinkingChunk): void {
    global $asyncTaskId;
    // SSE 模式：发送 thinking 事件，前端可以据此展示深度思考过程
    // 异步模式：思考过程不写入进度文件（write_chapter_worker 有独立实现）
    echo "event: thinking\n";
    echo 'data: ' . json_encode(['thinking' => $thinkingChunk], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function sseDoneWrite(): void {
    global $asyncTaskId;
    if ($asyncTaskId) {
        // 异步模式：标记完成
        updateAsyncProgress(['status' => 'done', 'progress' => 100]);
    } else {
        // SSE 模式
        echo "data: [DONE]\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

// 注册全局心跳函数，供 AIClient 的 CURLOPT_PROGRESSFUNCTION 调用
$GLOBALS['sendHeartbeat'] = 'sendHeartbeatWrite';
// 注册全局等待状态函数，AI 长时间无输出时通知前端
$GLOBALS['sendWaiting'] = function(int $elapsedSeconds) {
    echo 'data: ' . json_encode([
        'waiting'  => true,
        'msg'      => "AI 思考中（已等待 {$elapsedSeconds} 秒）…",
        'elapsed'  => $elapsedSeconds,
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
};

// 字数截断机制已移除——AI 可自然写完，不再强制截断

// ============================================================
// Phase 1–3: WriteEngine 解析章节 / 记忆初始化 / 组装 Prompt
// 发送状态消息，避免前端在初始化期间看到空白 Modal
// ============================================================
sseMsgWrite(['waiting' => true, 'msg' => '正在解析章节状态...']);

try {
    $resolved   = WriteEngine::resolveChapter($novelId, $chapterId);
    $novel      = $resolved['n'];
    $ch         = $resolved['ch'];
} catch (RuntimeException $e) {
    sseMsgWrite(['error' => $e->getMessage()]);
    sseDoneWrite(); exit;
}

sseMsgWrite(['waiting' => true, 'msg' => '正在加载记忆引擎...']);

try {
    $memResult  = WriteEngine::initMemory($novelId, $ch);
    $engine     = $memResult['engine'];
    $memoryCtx  = $memResult['memoryCtx'];
} catch (Throwable $e) {
    addLog($novelId, 'error', 'MemoryEngine 初始化失败：' . $e->getMessage());
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    $engine    = new MemoryEngine($novelId);
    $memoryCtx = null;
}

sseMsgWrite(['waiting' => true, 'msg' => '正在组装写作提示词...']);

$messages    = WriteEngine::buildPrompt($novel, $ch, $memoryCtx);
$targetWords = (int)$novel['chapter_words'];
$fullContent = '';
$usedModel   = null;
$canceled    = false;
$cancelCheckCounter = 0;

// ---- Phase 4: WriteEngine 流式写作（SSE I/O 回调） ----
try {
    $result = WriteEngine::streamWrite(
        $messages,
        $targetWords,
        $novelId,
        function(string $token) { sseChunkWrite($token); },
        function(array $payload) { sseMsgWrite($payload); },
        function() { sendHeartbeatWrite(); },
        function(string $reasoning) { sseThinkingWrite($reasoning); }
    );
    $fullContent       = $result['content'];
    $usedModel         = $result['model'];
    $streamUsage       = $result['usage'] ?? null;
    $streamDurationMs  = $result['duration_ms'] ?? null;
} catch (Exception $e) {
    $msg = $e->getMessage();
    $isCancel = strpos($msg, '取消') !== false;
    DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    sseMsgWrite(['error' => $msg, 'canceled' => $isCancel]);
    sseDoneWrite();
    exit;
}

// ============================================================
// 架构优化：先落盘正文 + 发 [DONE] 结束 SSE 流，再异步执行后处理
// ============================================================
// 根因：写作+摘要+记忆引擎+知识库+质检 总耗时可能超 5 分钟，
// Nginx 的 fastcgi_read_timeout 默认 60s，超时后强制切断连接，
// 浏览器收到不完整的 chunked 响应 → ERR_INCOMPLETE_CHUNKED_ENCODING
//
// 解决：正文落盘后立即结束 SSE 流，后处理（摘要/记忆/知识库/质检）
// 由后台 HTTP 请求异步完成。即使 Nginx 超时，前端也已收到 [DONE]。
// ============================================================

// ---- Phase 5: WriteEngine 保存章节 ----
try {
    $saveResult = WriteEngine::saveChapter(
        (int)$ch['id'], $novelId, $fullContent, $targetWords, $usedModel, $ch, $streamUsage, $streamDurationMs
    );
    $words        = $saveResult['words'];
    $ch           = $saveResult['chapter'];
    $modelInfo    = $saveResult['model_info'];
    $allDone      = $saveResult['all_done'];

    // ---- 【关键】正文已落盘，立即发送完成信号并结束 SSE 流 ----
    // 这样 Nginx/FPM 超时不会影响前端——前端已收到 [DONE] + 章节完成数据
    // 后处理（摘要/记忆/知识库/质检）通过后台异步请求完成，不阻塞 SSE 连接
    sseMsgWrite([
        'stats'      => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字{$modelInfo}",
        'chapter_id' => $ch['id'],
        'words'      => $words,
        'done'       => $allDone,
        'model_used' => $usedModel?->modelLabel,
        'postprocessing' => true,  // 告知前端后处理将在后台进行
    ]);

} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    if ($errMsg === 'canceled') {
        sseMsgWrite(['error' => '用户已取消写作', 'canceled' => true]);
        sseDoneWrite(); exit;
    }
    addLog($novelId, 'error', '正文落盘异常：' . $errMsg);

    // 保底：确保正文被保存
    if (!empty($fullContent)) {
        $currentCh = DB::fetch('SELECT status FROM chapters WHERE id=?', [$ch['id']]);
        if ($currentCh && $currentCh['status'] === 'writing') {
            $words = countWords($fullContent);
            $backupUpdates = [
                'content' => $fullContent,
                'words'   => $words,
                'status'  => 'completed',
            ];
            if ($streamUsage !== null && isset($streamUsage['total_tokens'])) {
                $backupUpdates['tokens_used'] = (int)$streamUsage['total_tokens'];
            }
            if ($streamDurationMs !== null) {
                $backupUpdates['duration_ms'] = $streamDurationMs;
            }
            DB::update('chapters', $backupUpdates, 'id=?', [$ch['id']]);
            updateNovelStats($novelId);
            addLog($novelId, 'write', "落盘异常后保底保存：第{$ch['chapter_number']}章，{$words}字");
        }
    }

    sseMsgWrite(['warning' => '⚠️ 正文已保存，但落盘异常：' . $errMsg]);
}

// 正文落盘后必须立即结束 SSE 流，这是防止 ERR_INCOMPLETE_CHUNKED_ENCODING 的关键
sseDoneWrite();

// ============================================================
// Phase 6: WriteEngine 后处理（摘要/记忆/知识库/质检）
// SSE 流已关闭，这些操作在后台执行，不阻塞前端连接
// ignore_user_abort(true) 保证即使前端断开后端也继续运行
// ============================================================
WriteEngine::postProcess($novelId, $ch, $fullContent, $engine);