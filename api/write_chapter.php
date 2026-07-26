<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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

// 审计修复（2026-07-19 M6-9）：允许被 chapter_actions.php 的 regenerate 分支
// 内部转发包含，此时 APP_LOADED 已由上游定义，避免重复 define 触发 warning。
defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';
require_once dirname(__DIR__) . '/includes/stats_tracker.php';
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
$_writingChapterId = null;
$ssePreclaimedChapterId = 0;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/sse_error_handler.php';

set_exception_handler(function (Throwable $e) {
    global $asyncTaskId;
    // 审计 P0：异常细节只写服务端日志；异步任务与 SSE 都只回传友好文案 + 追踪号。
    $rid = error_trace_id();
    error_log(sprintf('[%s] write_chapter uncaught %s: %s in %s:%d',
        $rid, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    if ($asyncTaskId) {
        updateAsyncProgress([
            'status'     => 'error',
            'error'      => '服务器内部错误，请稍后重试（追踪号 ' . $rid . '）',
            'request_id' => $rid,
        ]);
    } else {
        // sseFatalError 内部已记录细节并只回传友好文案（同一追踪号）。
        sseFatalError('Exception', $e->getMessage(), basename($e->getFile()), $e->getLine());
    }
});

// v2: 断连遗嘱——连接中断时写标记文件，前端可识别"后端仍在写作"vs"真正失败"
register_shutdown_function(function () {
    global $_writingChapterId, $novelId;
    if (!$_writingChapterId || !$novelId) return;
    // W-2 修复：FPM + ignore_user_abort(true) 下 connection_aborted() 不可靠，
    // 改用 connection_status() 双重判断；任一信号触发即写标记
    $aborted = (function_exists('connection_aborted') && connection_aborted())
        || (function_exists('connection_status') && connection_status() !== CONNECTION_NORMAL);
    if (!$aborted) return;

    $markerDir = CFG_PROGRESS_DIR;
    if (!is_dir($markerDir)) @mkdir($markerDir, 0755, true);
    $markerFile = $markerDir . "/reconnect_{$novelId}.marker";
    @file_put_contents($markerFile, json_encode([
        'chapter_id' => $_writingChapterId,
        'timestamp'  => time(),
        'status'     => 'writing',
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
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
            // 审计 P2-B（2026-06-11）：致命错误细节只写服务端日志，
            // 进度文件经 write_poll 回传客户端，只携带友好文案 + 追踪号（与 SSE 分支的 sseFatalError 对齐）。
            $rid = error_trace_id();
            error_log(sprintf('[%s] write_chapter fatal shutdown: %s in %s:%d',
                $rid, $error['message'], $error['file'], $error['line']));
            updateAsyncProgress([
                'status'     => 'error',
                'error'      => '服务器内部错误，请稍后重试（追踪号 ' . $rid . '）',
                'request_id' => $rid,
            ]);
        } else {
            sseFatalError('Shutdown', $error['message'], basename($error['file']), $error['line']);
        }
    }
});

// ---- 异步任务模式检测 ----
// 当 _task_id 参数存在时，写作过程不再输出 SSE 到浏览器，
// 而是将进度写入临时文件，由 write_poll.php 轮询读取。
// 这彻底绕过 Nginx/FPM 的长连接超时限制。
// （$asyncTaskId / $asyncProgressFile / $asyncMessages 已在文件顶部初始化）

// ---- 解析入参 ----
// 审计修复（2026-07-19 M6-9）：chapter_actions.php 的 regenerate 分支通过
// $GLOBALS['write_chapter_input'] 注入预解析入参（php://input 的原始 body
// 不含 novel_id），正常 HTTP 直连仍走 php://input。
$input = is_array($GLOBALS['write_chapter_input'] ?? null)
    ? $GLOBALS['write_chapter_input']
    : (json_decode(file_get_contents('php://input'), true) ?? []);
$novelId   = (int)($input['novel_id']   ?? 0);
$chapterId = (int)($input['chapter_id'] ?? 0);

// 审计修复 H1（2026-06-12）：写作端点必须先校验小说归属，
// 否则任意已登录用户可对他人的 novel_id 发起写作请求。
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($novelId > 0) checkNovelOwnership($novelId, $userId);
if ($chapterId > 0) checkChapterOwnership($chapterId, $userId);

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
// SseChannel 实例化 + 写作专用心跳
// ============================================================

class WriteSseChannel extends SseChannel {
    private int $writingChapterId = 0;

    public function setWritingChapterId(int $id): void {
        $this->writingChapterId = $id;
    }

    public function heartbeat(): void {
        // 审计修复 PERF-C3（2026-07-01）：事务期间跳过 DB 心跳 UPDATE，
        // 避免与 backupChapterVersion / saveChapter 的事务交叉。
        // 场景：backupChapterVersion 持有 chapter_versions 行锁 + chapters 事务上下文时，
        // 心跳的 UPDATE chapters 会嵌入同一事务，若引发锁等待会拖长事务甚至死锁。
        // 事务期间章节 updated_at 由后续 saveChapter 落盘时刷新，无需心跳维护。
        if ($this->writingChapterId > 0 && !DB::inTransaction()) {
            try {
                DB::query('UPDATE chapters SET updated_at = NOW() WHERE id = ? AND status = "writing"', [$this->writingChapterId]);
            } catch (\Throwable $e) { error_log('write_chapter heartbeat update failed: ' . $e->getMessage()); }
        }
        parent::heartbeat();
    }

    public function chunk(string $text): void {
        $this->heartbeat();
        if ($this->isAsync) {
            $fp = fopen($this->getProgressFile(), 'r+');
            if ($fp) {
                flock($fp, LOCK_EX);
                $data = stream_get_contents($fp);
                $progress = json_decode($data, true) ?: [];
                $progress['content'] = ($progress['content'] ?? '') . $text;
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
            echo 'data: ' . json_encode(['chunk' => $text], JSON_UNESCAPED_UNICODE) . "\n\n";
            $this->flush();
        }
    }
}

// $asyncTaskId / $asyncProgressFile 已在顶部初始化
$sse = new WriteSseChannel($asyncTaskId, $asyncProgressFile, defined('CFG_SSE_HEARTBEAT') ? CFG_SSE_HEARTBEAT : 10);

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

// 保留全局函数名供 WriteEngine 回调
function sendHeartbeatWrite(): void {
    global $sse;
    $sse->heartbeat();
}

function sseChunkWrite(string $chunk): void {
    global $sse;
    $sse->chunk($chunk);
}

function sseMsgWrite(array $payload): void {
    global $sse;
    $sse->msg($payload);
}

function sseThinkingWrite(string $thinkingChunk): void {
    global $sse;
    $sse->thinking($thinkingChunk);
}

function sseDoneWrite(): void {
    global $sse;
    $sse->done();
}

function recoverCommittedChapterForSse(int $novelId, array $chapter, string $generatedContent): ?array {
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

$GLOBALS['sendHeartbeat'] = 'sendHeartbeatWrite';
// 审计优化 P2-1（2026-06-16）：保留 $GLOBALS['sendWaiting'] 作为向后兼容回退，
// 同时定义为局部变量 $sendWaitingCb 显式传入 streamWrite。
$GLOBALS['sendWaiting'] = function(int $elapsedSeconds) {
    global $sse;
    $sse->msg([
        'waiting'  => true,
        'msg'      => "AI 思考中（已等待 {$elapsedSeconds} 秒）…",
        'elapsed'  => $elapsedSeconds,
    ]);
};
$sendWaitingCb = $GLOBALS['sendWaiting'];

// 字数截断：streamWrite() 内已恢复自动截断，AI 超字时自动修剪至容差上限

// ============================================================
// 并发守卫（仅 SSE 直连模式）
// 异步任务模式 (_task_id) 由 write_start.php 启动前已检查过；
// SSE 直连此前没有任何"已有任务在跑"的判断——前端把启动失败一律回退到
// 这里时，resolveChapter 的僵死清理会把后台 worker 正在写的章节强制
// 重置为 outlined，造成同一本书两路并发写作。
// ============================================================
if (!$asyncTaskId && $novelId > 0) {
    $activeTask = WriteEngine::findActiveTask($novelId);
    if ($activeTask !== null) {
        sseMsgWrite(['error' => '该小说已有写作任务在运行中，请等待完成或先取消写作']);
        sseDoneWrite();
        exit;
    }
    // 数据库行级锁：防止两个 SSE 请求同时通过 findActiveTask 检查。
    // 修复：以前事务持有到写作完成（5 分钟以上），并把同一 PDO 单例的事务上下文
    // 传给 WriteEngine::resolveChapter，触发嵌套 beginTransaction 异常；
    // 同时长时间持有 novels 行级锁会阻塞所有针对该小说的并发只读/写操作。
    // 现在改为：守卫期间持锁→二次校验通过后立刻 commit 释放锁，
    // 之后的并发控制由进度文件 + chapters.status='writing' + cancel_flag 接管。
    $pdo = DB::connect();
    $pdo->beginTransaction();
    try {
        $locked = DB::fetch('SELECT id FROM novels WHERE id=? FOR UPDATE', [$novelId]);
        if (!$locked) {
            $pdo->rollBack();
            sseMsgWrite(['error' => '小说不存在']);
            sseDoneWrite();
            exit;
        }
        // 持有锁后重新确认无活跃任务
        $activeTask = WriteEngine::findActiveTask($novelId);
        if ($activeTask !== null) {
            $pdo->rollBack();
            sseMsgWrite(['error' => '该小说已有写作任务在运行中，请等待完成或先取消写作']);
            sseDoneWrite();
            exit;
        }
        // 无活跃任务后先回收过期 writing，再选章；否则自动模式可能越过一个刚恢复的
        // 更早章节，直接预占后面的 outlined 章节，破坏章节顺序。
        WriteEngine::recoverStaleWritingChapters($novelId);
        // 守卫通过：在同一事务中选章并占位。自动选章也必须预占，否则释放 novels
        // 行锁到 resolveChapter 真正 claim 之间仍有并发窗口。
        if ($chapterId > 0) {
            // 人工显式指定：允许用户有意识地处理历史 outlined/skipped 缺口。
            $claimChapter = DB::fetch(
                'SELECT id FROM chapters WHERE id=? AND novel_id=? AND status IN ("outlined","skipped") FOR UPDATE',
                [$chapterId, $novelId]
            );
        } else {
            // 自动入口统一使用完成进度线契约，禁止静默倒序补写旧章。
            $autoClaimChapterId = WriteEngine::findNextAutomaticChapterId($novelId, true);
            $claimChapter = $autoClaimChapterId ? ['id' => $autoClaimChapterId] : null;
        }
        if (!$claimChapter) {
            $manualBackfill = WriteEngine::countManualBackfillChapters($novelId);
            $message = $chapterId <= 0 && $manualBackfill > 0
                ? "没有可安全自动写作的章节；{$manualBackfill}章历史缺口请人工显式选择。"
                : '没有可写章节，或指定章节当前状态不可写作。';
            throw new RuntimeException($message);
        }

        $claimChapterId = (int)$claimChapter['id'];
        $claimAffected = DB::update(
            'chapters',
            ['status' => 'writing'],
            'id=? AND novel_id=? AND status IN ("outlined","skipped")',
            [$claimChapterId, $novelId]
        );
        if ($claimAffected !== 1) {
            throw new RuntimeException('章节已被其他写作任务占用。');
        }
        DB::update('novels', ['status' => 'writing'], 'id=?', [$novelId]);
        $chapterId = $claimChapterId;
        $ssePreclaimedChapterId = $claimChapterId;
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ============================================================
// Phase 1–3: WriteEngine 解析章节 / 记忆初始化 / 组装 Prompt
// 发送状态消息，避免前端在初始化期间看到空白 Modal
// ============================================================
sseMsgWrite(['waiting' => true, 'msg' => '正在解析章节状态...']);

try {
    $resolved   = WriteEngine::resolveChapter($novelId, $chapterId, $ssePreclaimedChapterId > 0);
    $novel      = $resolved['n'];
    $ch         = $resolved['ch'];
    $_writingChapterId = (int)$ch['id'];
    $sse->setWritingChapterId($_writingChapterId);

    // 小说自身的 chapter_words 优先，全局 ws_chapter_words 仅作为兜底默认值
    $novelWords = (int)($novel['chapter_words'] ?? 0);
    if ($novelWords >= 500) {
        // 小说已有有效字数设置，保留
    } else {
        // 小说未设置或值异常，使用全局默认值
        $novelWords = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
    }
    $novel['chapter_words'] = max(500, $novelWords);
} catch (Throwable $e) {
    // 仅释放本请求刚刚成功预占的章节；普通异步路径不进入此分支的恢复逻辑。
    if ($ssePreclaimedChapterId > 0) {
        try {
            DB::update(
                'chapters',
                ['status' => 'outlined'],
                'id=? AND novel_id=? AND status="writing"',
                [$ssePreclaimedChapterId, $novelId]
            );
            DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [$novelId]);
            clearNovelCache($novelId);
        } catch (Throwable $recoverError) {
            error_log('write_chapter preclaim recovery failed: ' . $recoverError->getMessage());
        }
    }
    sseMsgWrite(safe_sse_error_payload($e, '写作失败，请稍后重试'));
    sseDoneWrite(); exit;
}

// 提前检测模型是否支持 1M 上下文（用于决定上下文构建模式）
$preAiClient = null;
try {
    $preAiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
    if ($preAiClient->is1MContext()) {
        addLog($novelId, 'info', '检测到1M上下文模型，将使用完整上下文模式');
        // 1M 模式需要更长的超时时间
        if (defined('CFG_TIME_LONG_1M')) {
            set_time_limit(CFG_TIME_LONG_1M);
        }
    }
} catch (Throwable $e) {
    // 忽略，后续会重试
}

sseMsgWrite(['waiting' => true, 'msg' => '正在加载记忆引擎...']);

try {
    $memResult  = WriteEngine::initMemory($novelId, $ch, $preAiClient);
    $engine     = $memResult['engine'];
    $memoryCtx  = $memResult['memoryCtx'];
} catch (Throwable $e) {
    addLog($novelId, 'error', 'MemoryEngine 初始化失败：' . $e->getMessage());
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    $engine    = new MemoryEngine($novelId);
    $memoryCtx = null;
}

sseMsgWrite(['waiting' => true, 'msg' => '正在构思中...']);

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
    error_log("[write_chapter] Phase4 streamWrite 失败: {$msg}");
    $isCancel = strpos($msg, '取消') !== false;
    DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
    // W-13 修复：streamWrite 失败属于可重试的瞬时错误（API 限流/网络），改 'draft' 让用户直接重试
    DB::update('novels', ['status' => 'draft'], 'id=?', [$novelId]);
    sseMsgWrite(['error' => $isCancel ? '用户已取消写作' : '模型生成失败，请稍后重试', 'canceled' => $isCancel]);
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
        'status'           => 'saved',  // Task 2：进度语义统一——正文已落盘，后处理在后台进行
        'stats'            => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字{$modelInfo}",
        'chapter_id'       => $ch['id'],
        'words'            => $words,
        'done'             => $allDone,
        'next_chapter_id'  => $saveResult['next_chapter_id'] ?? null,
        'next_chapter_num' => $saveResult['next_chapter_num'] ?? null,
        'model_used' => $usedModel?->modelLabel,
        'postprocessing' => true,  // 告知前端后处理将在后台进行
    ]);

    // ---- 记录使用统计 ----
    StatsTracker::record($words, 1);

} catch (WriteEngineValidationException $e) {
    $errMsg = $e->getMessage();
    error_log("[write_chapter] validation blocked: {$errMsg}");
    addLog($novelId, 'error', 'P0 strict validation blocked chapter save: ' . $errMsg);
    DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$ch['id']]);
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    sseMsgWrite(['error' => '章节未通过质量校验，已暂停写作', 'validation_blocked' => true]);
    sseDoneWrite(); exit;
} catch (WriteEnginePersistenceException $e) {
    $errMsg = $e->getMessage();
    if ($errMsg === 'canceled' || file_exists(WriteEngine::cancelFlagPath($novelId))) {
        sseMsgWrite(['error' => '用户已取消写作', 'canceled' => true]);
        sseDoneWrite(); exit;
    }
    addLog($novelId, 'error', '正文落盘异常：' . $errMsg);
    $committed = recoverCommittedChapterForSse($novelId, $ch, $fullContent);
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        sseMsgWrite([
            'status' => 'saved',
            'warning' => '正文事务已提交，正在恢复后处理',
            'stats' => "第{$ch['chapter_number']}章已保存，共 {$words} 字",
            'chapter_id' => $ch['id'],
            'words' => $words,
            'postprocessing' => true,
        ]);
        StatsTracker::record($words, 1);
    } else {
        DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$ch['id']]);
        DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
        sseMsgWrite(['error' => '章节保存失败，请稍后重试']);
        sseDoneWrite(); exit;
    }
} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    error_log("[write_chapter] Unexpected save failure: {$errMsg}");
    if ($errMsg === 'canceled' || file_exists(WriteEngine::cancelFlagPath($novelId))) {
        sseMsgWrite(['error' => '用户已取消写作', 'canceled' => true]);
        sseDoneWrite(); exit;
    }
    addLog($novelId, 'error', 'Unexpected save failure: ' . $errMsg);
    $committed = recoverCommittedChapterForSse($novelId, $ch, $fullContent);
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        sseMsgWrite([
            'status' => 'saved',
            'warning' => '正文已保存，正在恢复后处理',
            'stats' => "第{$ch['chapter_number']}章已保存，共 {$words} 字",
            'chapter_id' => $ch['id'],
            'words' => $words,
            'postprocessing' => true,
        ]);
        StatsTracker::record($words, 1);
    } else {
        DB::update('chapters', ['status' => 'outlined'], 'id=? AND status="writing"', [$ch['id']]);
        DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
        sseMsgWrite(['error' => '章节保存失败，请稍后重试']);
        sseDoneWrite(); exit;
    }
}

// 正文落盘后必须立即结束 SSE 流，这是防止 ERR_INCOMPLETE_CHUNKED_ENCODING 的关键
sseDoneWrite();

// 关键修复（2026-06-18）：立即结束 FPM 请求，把 SSE 响应发送给 nginx → 客户端
// 与 generate_outline.php 同类问题——原代码 sseDoneWrite() 后直接调用 postProcess，
// 但 PHP-FPM 下 register_shutdown_function/后续代码执行完毕前 FPM 不会发送响应。
// postProcess 耗时数分钟（非流式 AI 调用），nginx fastcgi_read_timeout 到期 → 504。
// 前端收到 504 网络错误 → 抛错 → "5秒后自动重试（已保留0字）"，
// 但 postProcess 在后台继续跑完，章节已落库 → 刷新后看到章节已完成。
// 修复：fastcgi_finish_request() 让 FPM 立即发送响应，postProcess 在后台继续执行。
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    flush();
}

// ============================================================
// Phase 6: WriteEngine 后处理（摘要/记忆/知识库/质检）
// SSE 流已关闭，这些操作在后台执行，不阻塞前端连接
// ignore_user_abort(true) 保证即使前端断开后端也继续运行
// ============================================================
$postprocessResult = PostprocessRunner::enqueueAndRun(
    $novelId,
    $ch,
    $fullContent,
    $engine,
    'sse:' . (gethostname() ?: 'localhost') . ':' . getmypid()
);
if (!in_array((string)($postprocessResult['state'] ?? ''), ['done', 'stale'], true)) {
    addLog($novelId, 'warn', '章节后处理已进入持久重试队列，任务ID：' . (int)($postprocessResult['job_id'] ?? 0));
}
