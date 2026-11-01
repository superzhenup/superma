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
require_once dirname(__DIR__) . '/includes/NovelProjectionFence.php';
require_once dirname(__DIR__) . '/includes/quality/WritePolicySnapshotFactory.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';
require_once dirname(__DIR__) . '/includes/stats_tracker.php';
require_once dirname(__DIR__) . '/includes/harness/runtime/bootstrap.php';
require_once dirname(__DIR__) . '/includes/harness/stages/LegacyWriteEngineStageAdapter.php';
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
// 审计 P0（2026-08-04）：本请求预占章节时写入的认领令牌（取消→重启竞态防护）
$ssePreclaimToken = '';
$sseBaseChapterSnapshot = null;
$writePolicySnapshot = null;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/sse_error_handler.php';

if (!function_exists('write_chapter_persist_error')) {
    function write_chapter_persist_error(string $rid, Throwable $e, string $where): void {
        $line = sprintf('[%s] %s %s: %s in %s:%d',
            $rid, $where, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        error_log($line);
        $dir = defined('CFG_PROGRESS_DIR') ? CFG_PROGRESS_DIR : '';
        if ($dir !== '' && is_dir($dir)) {
            @file_put_contents(
                $dir . '/last_write_error.log',
                $line . "\n" . $e->getTraceAsString() . "\n"
            );
        }
    }
}

if (!function_exists('write_chapter_preclaim_client_message')) {
    function write_chapter_preclaim_client_message(Throwable $e, string $rid): string {
        if ($e instanceof PDOException) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, [1205, 1213], true) || str_contains($e->getMessage(), 'Lock wait')) {
                return '系统繁忙，请稍后重试';
            }
            if ($code === 1054 || str_contains($e->getMessage(), 'Unknown column')) {
                return '数据库结构需要升级，请刷新页面后再试（追踪号 ' . $rid . '）';
            }
        }
        return '写作启动失败，请稍后重试（追踪号 ' . $rid . '）';
    }
}

set_exception_handler(function (Throwable $e) {
    global $asyncTaskId;
    // 审计 P0：异常细节只写服务端日志；异步任务与 SSE 都只回传友好文案 + 追踪号。
    $rid = error_trace_id();
    write_chapter_persist_error($rid, $e, 'write_chapter uncaught');
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
    // 审计修复 P2-2（2026-10-18）：不得自行声明 Transfer-Encoding——这是逐跳头，
    // 分块由 Web 服务器/SAPI 负责。脚本输出并非分块格式，直通该头的环境
    // （如 php -S）里客户端会在首段正文处报 chunked 编码错误并断流。
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
    private int $writingNovelId = 0;
    private string $writingClaimToken = '';
    /** 审计修复 H2（2026-08-16）：DB 心跳节流时间戳，与父类 SSE 心跳节流对齐 */
    private int $lastDbHeartbeat = 0;
    /** 审计修复 E2（2026-09-27）：认领丢失标志。心跳在本类内被 SSE chunk 回调
     * 驱动（可能运行于 cURL 回调内，禁止抛异常/exit），丢失时仅置位；由
     * streamWrite 的 abortProbe 在安全边界消费并中止生成。 */
    private bool $claimLost = false;

    public function setWritingChapterId(int $id, int $novelId, string $claimToken): void {
        $this->writingChapterId = $id;
        $this->writingNovelId = $novelId;
        $this->writingClaimToken = $claimToken;
    }

    public function hasLostClaim(): bool {
        return $this->claimLost;
    }

    protected function modifyProgress(callable $modifier): void {
        // 审计修复 E1（2026-09-27）：SSE 写进度前校验文件仍属于本任务。
        // 基类 modifyProgress 只有锁保护、无所有权校验——FastCGI 超时
        // 残留的旧 SSE 进程会把过期 error/writing 状态写回已被新任务
        // 重建的进度文件，污染新任务前端视图。worker 侧同类写入已有
        // exact-lease 校验（writeWorkerProgressMatchesLease），此处对齐：
        // 文件 _task_id 已变为其他任务时拒写。文件无 _task_id（纯 SSE
        // 模式自建文件）时不设防，保持原行为。
        $file = $this->sseProgressFile();
        $taskId = $this->sseTaskId();
        if ($file !== null && $taskId !== null && $taskId !== '' && is_file($file)) {
            $probe = @fopen($file, 'r');
            if ($probe !== false) {
                $probeData = stream_get_contents($probe);
                fclose($probe);
                $probeArr = json_decode((string)$probeData, true);
                if (is_array($probeArr)) {
                    $fileTaskId = (string)($probeArr['_task_id'] ?? '');
                    if ($fileTaskId !== '' && $fileTaskId !== $taskId) {
                        error_log('[write_sse] progress file now belongs to task ' . $fileTaskId . '; dropping stale SSE write');
                        return;
                    }
                }
            }
        }
        parent::modifyProgress($modifier);
    }

    public function heartbeat(): void {
        // 审计修复 PERF-C3（2026-07-01）：事务期间跳过 DB 心跳 UPDATE，
        // 避免与 backupChapterVersion / saveChapter 的事务交叉。
        // 场景：backupChapterVersion 持有 chapter_versions 行锁 + chapters 事务上下文时，
        // 心跳的 UPDATE chapters 会嵌入同一事务，若引发锁等待会拖长事务甚至死锁。
        // 事务期间章节 updated_at 由后续 saveChapter 落盘时刷新，无需心跳维护。
        //
        // 审计修复 H2（2026-08-16）：DB 心跳增加 10 秒节流。
        // chunk() 每个流式 token 都会调用 heartbeat()，原实现每个 token 执行一次
        // UPDATE chapters（3000 字章节 ≈ 3000~5000 次 DB 往返）。updated_at 只被
        // novel.php 的僵尸章节 watchdog 消费（阈值远大于 10 秒），节流后语义不变。
        $now = time();
        if ($this->writingChapterId > 0
            && $this->writingNovelId > 0
            && preg_match('/^[a-f0-9]{32}$/D', $this->writingClaimToken) === 1
            && !DB::inTransaction()
            && $now - $this->lastDbHeartbeat >= 10) {
            try {
                // 审计修复 E2（2026-09-27）：校验认领存活。原实现 UPDATE 后
                // 不检查受影响行数——认领已被取消/释放/新任务接管时
                // （affected=0），本 SSE 进程仍继续生成直到落盘 CAS 才失败，
                // 期间浪费整章模型费用且与新任务双写竞态。affected=0 时
                // 置位 claimLost（cURL 回调内禁止抛异常），由 streamWrite
                // 的 abortProbe 在 cURL 之外安全中止。
                $affected = DB::execute(
                    'UPDATE chapters SET updated_at=GREATEST('
                    . 'NOW(), DATE_ADD(updated_at, INTERVAL 1 SECOND))'
                    . ' WHERE id=? AND novel_id=? AND status="writing" AND claim_token=?',
                    [$this->writingChapterId, $this->writingNovelId, $this->writingClaimToken]
                );
                if ($affected === 0) {
                    $this->claimLost = true;
                    error_log('[write_sse] chapter claim lost (heartbeat affected 0); generation will abort at next probe');
                }
                $this->lastDbHeartbeat = $now;
            } catch (\Throwable $e) { error_log('write_chapter heartbeat update failed: ' . $e->getMessage()); }
        }
        parent::heartbeat();
    }

    public function chunk(string $text): void {
        $this->heartbeat();
        if ($this->isAsync()) {
            // 审计修复（2026-08-30 M-6）：与 SseChannel::chunk 同构——正文追加独立
            // .content 文件（每 token O(1)），JSON 主文件只做节流后的元数据更新。
            // 原实现每 token 全量重写整个进度 JSON（content 字段随正文线性增长），
            // 3000 字章节 ≈ 3000~5000 次全量重写，累计 IO O(n²)。
            // 读取端 write_poll.php 已兼容 _content_split；终态清理一并删除 .content。
            if ($this->appendChunkToFile($text)) {
                $elapsed = microtime(true) - ($this->_contentLastFlush ?? 0);
                if ($elapsed < 2) return;
                $this->_contentLastFlush = microtime(true);
                $this->modifyProgress(function (array $progress): array {
                    $progress['status'] = 'writing';
                    $progress['progress'] = min(90, ($progress['progress'] ?? 0) + 1);
                    $progress['_content_split'] = true;
                    $progress['updated_at'] = time();
                    return $progress;
                });
                return;
            }
            // 回退：.content 追加不可用（IO 故障）时保持旧全量路径，内容不丢。
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
            // The parent owns its private transport state and flush method.
            parent::chunk($text);
        }
    }
}

// $asyncTaskId / $asyncProgressFile 已在顶部初始化
$sse = new WriteSseChannel($asyncTaskId, $asyncProgressFile, defined('CFG_SSE_HEARTBEAT') ? CFG_SSE_HEARTBEAT : 10);

function updateAsyncProgress(array $updates): void {
    global $asyncProgressFile, $asyncTaskId;
    if (!$asyncProgressFile || !file_exists($asyncProgressFile)) return;
    // 审计修复 E1（2026-09-27）：同 WriteSseChannel::modifyProgress 的任务归属
    // 校验——进度文件已被新任务重建（_task_id 变化）时拒写，防止残留的旧
    // SSE 进程把过期状态污染新任务的前端视图。文件无 _task_id 时保持原行为。
    if (is_string($asyncTaskId) && $asyncTaskId !== '') {
        $probe = @fopen($asyncProgressFile, 'r');
        if ($probe !== false) {
            $probeData = stream_get_contents($probe);
            fclose($probe);
            $probeArr = json_decode((string)$probeData, true);
            if (is_array($probeArr)) {
                $fileTaskId = (string)($probeArr['_task_id'] ?? '');
                if ($fileTaskId !== '' && $fileTaskId !== $asyncTaskId) {
                    error_log('[write_sse] progress file now belongs to task ' . $fileTaskId . '; updateAsyncProgress dropped');
                    return;
                }
            }
        }
    }
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
    // 审计修复 P2（2026-09-27）：本地弱校验版（仅 completed + 内容哈希）收敛为
    // WriteEngine 的唯一强校验实现（canonical_state + claim_token 清空 +
    // revision_hash 自洽），与 daemon/worker 路径同强度，消除三入口行为漂移。
    return WriteEngine::recoverCommittedChapterSnapshot($novelId, $chapter, $generatedContent);
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
    $pdo = DB::connect();
    $sseClaimProjectionFence = NovelProjectionFence::acquire($novelId, 30);
    $releaseSseClaimProjectionFence = static function () use (&$sseClaimProjectionFence): void {
        if ($sseClaimProjectionFence === null) {
            return;
        }
        $token = $sseClaimProjectionFence;
        $sseClaimProjectionFence = null;
        NovelProjectionFence::release($token);
    };
    // 数据库行级锁：防止两个 SSE 请求同时通过 findActiveTask 检查。
    // 修复：以前事务持有到写作完成（5 分钟以上），并把同一 PDO 单例的事务上下文
    // 传给 WriteEngine::resolveChapter，触发嵌套 beginTransaction 异常；
    // 同时长时间持有 novels 行级锁会阻塞所有针对该小说的并发只读/写操作。
    // 现在改为：守卫期间持锁→二次校验通过后立刻 commit 释放锁，
    // 之后的并发控制由进度文件 + chapters.status='writing' + cancel_flag 接管。
    try {
        $activeTask = WriteEngine::findActiveTask($novelId);
        if ($activeTask !== null) {
            $releaseSseClaimProjectionFence();
            sseMsgWrite(['error' => '该小说已有写作任务在运行中，请等待完成或先取消写作']);
            sseDoneWrite();
            exit;
        }
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Could not start SSE chapter claim transaction.');
        }
        // 全部写作/取消入口统一采用 chapters -> novels 的加锁顺序。
        // cancel_write.php 会先锁定本书章节再更新小说行；若 SSE 反向先锁
        // novels，就会形成 SSE 等 chapter、cancel 等 novel 的确定性死锁。
        // 这里锁完整章节集合也让下方 stale recovery 与自动选章共享同一
        // 一致快照，避免选章期间插入/状态漂移造成新的并发窗口。
        DB::fetchAll(
            'SELECT id FROM chapters WHERE novel_id=? ORDER BY id FOR UPDATE',
            [$novelId]
        );
        $locked = DB::fetch('SELECT id FROM novels WHERE id=? FOR UPDATE', [$novelId]);
        if (!$locked) {
            $pdo->rollBack();
            $releaseSseClaimProjectionFence();
            sseMsgWrite(['error' => '小说不存在']);
            sseDoneWrite();
            exit;
        }
        // 持有锁后重新确认无活跃任务
        $activeTask = WriteEngine::findActiveTask($novelId);
        if ($activeTask !== null) {
            $pdo->rollBack();
            $releaseSseClaimProjectionFence();
            sseMsgWrite(['error' => '该小说已有写作任务在运行中，请等待完成或先取消写作']);
            sseDoneWrite();
            exit;
        }
        // 无活跃任务后先回收过期 writing，再选章；否则自动模式可能越过一个刚恢复的
        // 更早章节，直接预占后面的 outlined 章节，破坏章节顺序。
        try {
            WriteEngine::recoverStaleWritingChapters($novelId);
        } catch (\Throwable $e) {
            error_log('[write_chapter] recoverStaleWritingChapters: ' . $e->getMessage());
        }
        // 守卫通过：在同一事务中选章并占位。自动选章也必须预占，否则释放 novels
        // 行锁到 resolveChapter 真正 claim 之间仍有并发窗口。
        if ($chapterId > 0) {
            // 人工显式指定：允许用户有意识地处理历史 outlined/skipped 缺口；
            // 审计修复（2026-08-17 写作逻辑 B0）：质检 failed 章也允许显式重写
            // （保留正文作参考）。自动入口不受影响，仍只认 outlined/skipped。
            $claimChapter = DB::fetch(
                'SELECT id FROM chapters WHERE id=? AND novel_id=? AND status IN ("outlined","skipped","failed") FOR UPDATE',
                [$chapterId, $novelId]
            );
        } else {
            // 自动入口统一使用完成进度线契约，禁止静默倒序补写旧章。
            $autoClaimChapterId = WriteEngine::findNextAutomaticChapterId($novelId, true);
            $claimChapter = $autoClaimChapterId ? ['id' => $autoClaimChapterId] : null;
        }
        if (!$claimChapter) {
            $message = $chapterId <= 0
                ? WriteEngine::automaticWriteBlockMessage($novelId)
                : '指定章节当前不可写作。仅「待写 / 已跳过 / 质检失败」的章节可以写作。';
            if ($pdo->inTransaction()) $pdo->rollBack();
            $releaseSseClaimProjectionFence();
            sseMsgWrite(['error' => $message]);
            sseDoneWrite();
            exit;
        }

        $claimChapterId = (int)$claimChapter['id'];
        $claimBaseChapter = DB::fetch(
            'SELECT ' . WriteEngine::chapterWriteSelectList()
            . ' FROM chapters WHERE id=? AND novel_id=? FOR UPDATE',
            [$claimChapterId, $novelId]
        );
        if (!$claimBaseChapter) {
            throw new RuntimeException('The chapter disappeared before its writing claim.');
        }
        // 审计 P0（2026-08-04）：预占同时写入认领令牌。取消→立刻重启竞态下，
        // 已取消旧任务的 saveChapter CAS 因令牌不匹配而无法命中本请求预占的行。
        // 审计修复（2026-08-17 写作逻辑 B0）：显式章节预占允许 failed→writing。
        $isExplicitChapterClaim = $chapterId > 0;
        $claimStatusSql = $isExplicitChapterClaim ? '"outlined","skipped","failed"' : '"outlined","skipped"';
        $ssePreclaimToken = WriteEngine::newClaimToken();
        $sseBaseChapterSnapshot = WriteEngine::captureChapterBaseSnapshot(
            $claimBaseChapter,
            $ssePreclaimToken
        );
        $claimAffected = DB::update(
            'chapters',
            ['status' => 'writing', 'claim_token' => $ssePreclaimToken],
            "id=? AND novel_id=? AND status IN ({$claimStatusSql})",
            [$claimChapterId, $novelId]
        );
        if ($claimAffected !== 1) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $releaseSseClaimProjectionFence();
            sseMsgWrite(['error' => '章节已被其他写作任务占用，请稍后重试。']);
            sseDoneWrite();
            exit;
        }
        DB::update('novels', ['status' => 'writing'], 'id=?', [$novelId]);
        $chapterId = $claimChapterId;
        $ssePreclaimedChapterId = $claimChapterId;
        $pdo->commit();
        $releaseSseClaimProjectionFence();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try {
            $releaseSseClaimProjectionFence();
        } catch (\Throwable $releaseError) {
            error_log('[write_chapter] projection fence release failed: ' . $releaseError->getMessage());
        }
        // 审计修复（2026-08-19）：预占失败不得再 throw 给未捕获处理器。
        $rid = error_trace_id();
        write_chapter_persist_error($rid, $e, 'write_chapter preclaim');
        sseMsgWrite(safe_sse_error_payload($e, write_chapter_preclaim_client_message($e, $rid)));
        sseDoneWrite();
        exit;
    }
}

// ============================================================
// Phase 1–3: WriteEngine 解析章节 / 记忆初始化 / 组装 Prompt
// 发送状态消息，避免前端在初始化期间看到空白 Modal
// ============================================================
sseMsgWrite(['waiting' => true, 'msg' => '正在解析章节状态...']);

try {
    $resolved   = WriteEngine::resolveChapter(
        $novelId,
        $chapterId,
        $ssePreclaimedChapterId > 0,
        // 审计修复（2026-08-16 S-2）：SSE 预占路径传入本请求的预占令牌，
        // resolveChapter 复验令牌未被取消→重启的新任务覆写，杜绝毫秒窗口内
        // 旧任务采纳新令牌通过 saveChapter CAS 覆盖新任务正文。
        $ssePreclaimedChapterId > 0 ? $ssePreclaimToken : null,
        $ssePreclaimedChapterId > 0 ? $sseBaseChapterSnapshot : null
    );
    $novel      = $resolved['n'];
    $ch         = $resolved['ch'];
    $_writingChapterId = (int)$ch['id'];
    $sse->setWritingChapterId(
        $_writingChapterId,
        $novelId,
        (string)($ch['claim_token'] ?? '')
    );

    // 小说自身的 chapter_words 优先，全局 ws_chapter_words 仅作为兜底默认值
    $novelWords = (int)($novel['chapter_words'] ?? 0);
    if ($novelWords >= 500) {
        // 小说已有有效字数设置，保留
    } else {
        // 小说未设置或值异常，使用全局默认值
        $novelWords = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
    }
    $novel['chapter_words'] = max(500, $novelWords);
    $writePolicySnapshot = WritePolicySnapshotFactory::captureForChapter(
        $novelId,
        (int)$novel['chapter_words']
    );
    WritePolicySnapshotFactory::assertEmergencyEpochCurrent($writePolicySnapshot);
    // 审计修复 P2（2026-09-27）：冻结本请求五关通过线，同任务的质检与
    // 生成承诺不得随配置漂移。
    WriteEngine::freezeQualityGateMinScore(WriteEngine::resolveQualityGateMinScore());
    $chapterFeatureSnapshot = HarnessFeatureSnapshot::captureForNovel($novelId);
} catch (Throwable $e) {
    // 仅释放本请求刚刚成功预占的章节；普通异步路径不进入此分支的恢复逻辑。
    if ($ssePreclaimedChapterId > 0) {
        try {
            // 审计 P0：按本请求的认领令牌精确释放，防误释放他人认领
            WriteEngine::releaseChapterClaimAndPauseNovel(
                $novelId,
                $ssePreclaimedChapterId,
                [
                    'claim_token' => $ssePreclaimToken,
                    '_base_snapshot' => $sseBaseChapterSnapshot ?? null,
                ]
            );
            clearNovelCache($novelId);
        } catch (Throwable $recoverError) {
            error_log('write_chapter preclaim recovery failed: ' . $recoverError->getMessage());
        }
    }
    sseMsgWrite(safe_sse_error_payload($e, '写作失败，请稍后重试'));
    sseDoneWrite(); exit;
}

// P2 direct-SSE authority adapter. Snapshot capture above is the rollout
// boundary; off/shadow requests continue through the unchanged legacy phases.
if (!$asyncTaskId && ChapterRunService::shouldUseAuthority(
    $writePolicySnapshot,
    $chapterFeatureSnapshot
)) {
    $authoritySseClosed = false;
    try {
        $claimToken = (string)($ch['claim_token'] ?? '');
        $baseSnapshot = $ch['_base_snapshot'] ?? null;
        if (preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1
            || !is_array($baseSnapshot)) {
            throw new PermanentStageException(
                'Direct SSE authority path has no authenticated Chapter claim.'
            );
        }
        $chapterInputSnapshot = ChapterRunInputSnapshotFactory::capture(
            $resolved['n'],
            $resolved['ch']
        );
        // 专项一（2026-09-28）：authority 编排骨架（invocation 组装、执行工厂、
        // 恢复闭包、adapter 工厂、runToTerminal 调用与终态映射）收敛进
        // ChapterWriteOrchestrator；本入口保留输出流回调与 [DONE] 收尾。
        $transport = new CallbackLegacyChapterTransport([
            'waiting' => static function (string $message): void {
                sseMsgWrite(['waiting' => true, 'msg' => $message]);
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
            'acquired' => static function (
                array $resolvedNovel,
                array $resolvedChapter
            ) use (&$novel, &$ch, &$sse, $novelId): void {
                $novel = $resolvedNovel;
                $ch = $resolvedChapter;
                $sse->setWritingChapterId(
                    (int)$ch['id'],
                    $novelId,
                    (string)$ch['claim_token']
                );
            },
            'saved' => static function (array $saveResult) use (
                &$ch,
                &$fullContent,
                &$authoritySseClosed
            ): void {
                $ch = $saveResult['chapter'];
                $fullContent = (string)$saveResult['accepted_content'];
                $words = (int)$saveResult['words'];
                sseMsgWrite([
                    'status' => 'saved',
                    'stats' => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字"
                        . (string)($saveResult['model_info'] ?? ''),
                    'chapter_id' => (int)$ch['id'],
                    'words' => $words,
                    'done' => (bool)($saveResult['all_done'] ?? false),
                    'next_chapter_id' => $saveResult['next_chapter_id'] ?? null,
                    'next_chapter_num' => $saveResult['next_chapter_num'] ?? null,
                    'model_used' => $saveResult['_model_label'] ?? null,
                    'quality_warning' => !empty($saveResult['quality_warning']),
                    'quality_warning_reason' =>
                        (string)($saveResult['quality_warning_reason'] ?? ''),
                    'postprocessing' => true,
                ]);
                StatsTracker::record($words, 1);
                sseDoneWrite();
                $authoritySseClosed = true;
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    while (ob_get_level() > 0) {
                        @ob_end_flush();
                    }
                    flush();
                }
            },
            'postprocessed' => static function (array $result) use ($novelId): void {
                if (!in_array(
                    (string)($result['state'] ?? ''),
                    ['done', 'stale', 'skipped'],
                    true
                )) {
                    addLog(
                        $novelId,
                        'warn',
                        '章节后处理已进入持久重试队列，任务ID：'
                            . (int)($result['job_id'] ?? 0)
                    );
                }
            },
        ]);
        $sseRestoreVerifier = static function (
            RunContext $context,
            array $restoredChapter,
            array $authenticatedBase
        ) use ($claimToken): void {
            if (!hash_equals(
                $claimToken,
                (string)($restoredChapter['claim_token'] ?? '')
            )) {
                throw new StaleRevisionException(
                    'Direct SSE Chapter claim was replaced.'
                );
            }
        };
        // 终态收尾：SSE 消息 + [DONE]；未落盘章节释放认领并暂停小说。
        $sseTermination = new CallbackWriteTermination([
            'succeeded' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ): void {
                exit;
            },
            'quarantined' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ) use (&$ch, &$authoritySseClosed, $novelId): void {
                if (!$authoritySseClosed) {
                    sseMsgWrite([
                        'error' => '章节未通过质量校验，已暂停写作',
                        'validation_blocked' => true,
                    ]);
                    sseDoneWrite();
                }
                if (!$contentSaved) {
                    WriteEngine::releaseChapterClaimAndPauseNovel(
                        $novelId,
                        (int)$ch['id'],
                        $ch
                    );
                }
                exit;
            },
            'canceled' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ) use (&$ch, &$authoritySseClosed, $novelId): void {
                if (!$authoritySseClosed) {
                    sseMsgWrite([
                        'error' => $contentSaved
                            ? '用户已取消，但本章正文已保存完成'
                            : '用户已取消写作',
                        'canceled' => true,
                        'canceled_but_saved' => $contentSaved,
                    ]);
                    sseDoneWrite();
                }
                if (!$contentSaved) {
                    WriteEngine::releaseChapterClaimAndPauseNovel(
                        $novelId,
                        (int)$ch['id'],
                        $ch
                    );
                }
                exit;
            },
            'failed' => static function (
                WorkflowExecutionResult $result,
                bool $contentSaved
            ) use (&$ch, &$authoritySseClosed, $novelId): void {
                if (!$authoritySseClosed) {
                    sseMsgWrite([
                        'error' => $contentSaved
                            ? '正文已保存，但章节后处理未完成'
                            : '写作工作流执行失败，请稍后重试',
                    ]);
                    sseDoneWrite();
                }
                if (!$contentSaved) {
                    WriteEngine::releaseChapterClaimAndPauseNovel(
                        $novelId,
                        (int)$ch['id'],
                        $ch
                    );
                }
                exit;
            },
        ]);

        $runRequest = new ChapterWriteRunRequest(
            entryPoint: ChapterRunEntryPoint::SSE,
            userId: $userId,
            novelId: $novelId,
            chapterId: (int)$ch['id'],
            authorityIdentity: $claimToken,
            policySnapshot: $writePolicySnapshot,
            featureSnapshot: $chapterFeatureSnapshot,
            inputSnapshot: $chapterInputSnapshot,
            workerId: substr(
                'sse:' . (gethostname() ?: 'localhost') . ':' . getmypid()
                    . ':' . HarnessRuntime::randomHex(8),
                0,
                120
            ),
            transport: $transport,
            acquireChapter: static fn (
                Closure $restore,
                RunContext $context
            ): array => $resolved,
            restoreBase: static fn (RunContext $context, array $chapter): array => $baseSnapshot,
            restoreMissingMessage: 'Direct SSE recovery source disappeared.',
            restoreInactiveMessage: 'Direct SSE Chapter claim is no longer active.',
            postprocessWorkerId: substr(
                'sse:' . (gethostname() ?: 'localhost') . ':' . getmypid(),
                0,
                120
            ),
            termination: $sseTermination,
            baseSnapshot: $baseSnapshot,
            restoreVerifier: $sseRestoreVerifier,
            legacyCancellationProbe: static fn (): bool =>
                file_exists(WriteEngine::cancelFlagPath($novelId)),
            waitingCallback: $sendWaitingCb,
            retryTick: static function (
                string $runUid,
                WorkflowExecutionResult $retryResult
            ): ?WorkflowExecutionResult {
                sendHeartbeatWrite();
                return null;
            }
        );

        (new ChapterWriteOrchestrator(DB::getPdo()))->run($runRequest);
        // 四个终态回调内均已 exit；此兜底确保永不坠入下方 legacy 编排。
        exit;
    } catch (Throwable $authorityError) {
        error_log(
            '[write_chapter] authority Chapter Run failed ('
            . $authorityError::class . '): ' . $authorityError->getMessage()
        );
        if (!$authoritySseClosed) {
            WriteEngine::releaseChapterClaimAndPauseNovel(
                $novelId,
                (int)$ch['id'],
                $ch
            );
            sseMsgWrite(safe_sse_error_payload(
                $authorityError,
                '写作工作流持久执行失败，请稍后重试'
            ));
            sseDoneWrite();
        }
        exit;
    }
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

try {
    $messages = WriteEngine::buildPrompt(
        $novel,
        $ch,
        $memoryCtx,
        null,
        $writePolicySnapshot,
        null,
        $chapterFeatureSnapshot
    );
} catch (Throwable $e) {
    error_log('[write_chapter] Phase3 buildPrompt 失败: ' . $e->getMessage());
    addLog($novelId, 'error', 'buildPrompt 失败：' . $e->getMessage());
    WriteEngine::releaseChapterClaimAndPauseNovel($novelId, (int)$ch['id'], $ch);
    sseMsgWrite(safe_sse_error_payload($e, '写作提示词构建失败，请稍后重试'));
    sseDoneWrite();
    exit;
}
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
        $sendWaitingCb,
        function () use ($sse, $writePolicySnapshot): void {
            // 审计修复 E2（2026-09-27）：认领丢失（心跳 affected=0 置位）时在
            // abortProbe 安全边界中止生成——抛出的异常经 streamWrite 的
            // abortFailure 通道在 cURL 之外 rethrow，Phase 4 catch 统一释放
            // 与收尾（认领已丢失时释放为幂等 no-op）。
            if ($sse->hasLostClaim()) {
                throw new WriteEngineCanceledException('章节认领已被释放或接管，停止生成');
            }
            WritePolicySnapshotFactory::assertEmergencyEpochCurrent($writePolicySnapshot);
        }
    );
    $fullContent       = $result['content'];
    $usedModel         = $result['model'];
    $streamUsage       = $result['usage'] ?? null;
    $streamDurationMs  = $result['duration_ms'] ?? null;
} catch (Throwable $e) {
    $msg = $e->getMessage();
    error_log("[write_chapter] Phase4 streamWrite 失败: {$msg}");
    $isCancel = $e instanceof WriteEngineCanceledException;
    // 审计修复（2026-08-02 M1）：恢复路径加状态守卫（AND status="writing"），
    // 防止旧任务失败恢复时覆盖新任务已预占的同章状态（High-4 同源竞态的另一条路径）。
    // 审计 P0（2026-08-04）：升级为按认领令牌精确释放。
    // W-13 修复：streamWrite 失败属于可重试的瞬时错误（API 限流/网络），改 'draft' 让用户直接重试。
    WriteEngine::releaseChapterClaimAndSetNovelStatus(
        $novelId,
        (int)$ch['id'],
        $ch,
        'draft'
    );
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
    // H4 修复：把 task_id 注入 $ch，供 saveChapter 事务内查 writing_tasks.cancel_requested
    // 做权威取消校验（文件取消标志可被新任务 B 清除，DB task 标记不可被 B 清除）。
    $ch['_task_id'] = $asyncTaskId ?: null;
    $saveResult = WriteEngine::saveChapter(
        (int)$ch['id'], $novelId, $fullContent, $targetWords, $usedModel, $ch,
        $streamUsage, $streamDurationMs, $writePolicySnapshot
    );
    $words        = $saveResult['words'];
    $ch           = $saveResult['chapter'];
    $modelInfo    = $saveResult['model_info'];
    $allDone      = $saveResult['all_done'];
    $fullContent  = (string)$saveResult['accepted_content'];

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
        'quality_warning' => !empty($saveResult['quality_warning']),
        'quality_warning_reason' => (string)($saveResult['quality_warning_reason'] ?? ''),
        'postprocessing' => true,  // 告知前端后处理将在后台进行
    ]);

    // ---- 记录使用统计 ----
    StatsTracker::record($words, 1);

} catch (WriteEngineValidationException $e) {
    $errMsg = $e->getMessage();
    error_log("[write_chapter] validation blocked: {$errMsg}");
    addLog($novelId, 'error', 'P0 strict validation blocked chapter save: ' . $errMsg);
    WriteEngine::releaseChapterClaimAndPauseNovel($novelId, (int)$ch['id'], $ch);
    sseMsgWrite(['error' => '章节未通过质量校验，已暂停写作', 'validation_blocked' => true]);
    sseDoneWrite(); exit;
} catch (WriteEnginePersistenceException $e) {
    $errMsg = $e->getMessage();
    $isCancel = file_exists(WriteEngine::cancelFlagPath($novelId));
    // 审计修复（2026-08-17 写作逻辑 D2）：取消与临近落盘竞态——事务已提交的正文
    // 不因取消回滚。取消路径先探测是否已提交；已提交则如实告知「本章已保存，
    // 自动写已停」并继续后处理；未提交才按纯取消终止。
    $committed = recoverCommittedChapterForSse($novelId, $ch, $fullContent);
    if ($isCancel && !$committed) {
        sseMsgWrite(['error' => '用户已取消写作', 'canceled' => true]);
        sseDoneWrite(); exit;
    }
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        sseMsgWrite([
            'status' => 'saved',
            'canceled_but_saved' => $isCancel,
            'warning' => $isCancel
                ? '用户已取消，但本章正文已在取消前保存完成（不回滚），自动写已停'
                : '正文事务已提交，正在恢复后处理',
            'stats' => "第{$ch['chapter_number']}章已保存，共 {$words} 字",
            'chapter_id' => $ch['id'],
            'words' => $words,
            'postprocessing' => true,
        ]);
        StatsTracker::record($words, 1);
    } else {
        addLog($novelId, 'error', '正文落盘异常：' . $errMsg);
        WriteEngine::releaseChapterClaimAndPauseNovel($novelId, (int)$ch['id'], $ch);
        sseMsgWrite(['error' => '章节保存失败，请稍后重试']);
        sseDoneWrite(); exit;
    }
} catch (Throwable $e) {
    $errMsg = $e->getMessage();
    error_log("[write_chapter] Unexpected save failure: {$errMsg}");
    $isCancel = $e instanceof WriteEngineCanceledException
        || file_exists(WriteEngine::cancelFlagPath($novelId));
    // 审计修复（2026-08-17 写作逻辑 D2）：同上——取消竞态下已提交正文不回滚。
    $committed = recoverCommittedChapterForSse($novelId, $ch, $fullContent);
    if ($isCancel && !$committed) {
        sseMsgWrite(['error' => '用户已取消写作', 'canceled' => true]);
        sseDoneWrite(); exit;
    }
    if ($committed) {
        $ch = array_merge($ch, $committed);
        $fullContent = (string)$committed['content'];
        $words = (int)$committed['words'];
        sseMsgWrite([
            'status' => 'saved',
            'canceled_but_saved' => $isCancel,
            'warning' => $isCancel
                ? '用户已取消，但本章正文已在取消前保存完成（不回滚），自动写已停'
                : '正文已保存，正在恢复后处理',
            'stats' => "第{$ch['chapter_number']}章已保存，共 {$words} 字",
            'chapter_id' => $ch['id'],
            'words' => $words,
            'postprocessing' => true,
        ]);
        StatsTracker::record($words, 1);
    } else {
        addLog($novelId, 'error', 'Unexpected save failure: ' . $errMsg);
        WriteEngine::releaseChapterClaimAndPauseNovel($novelId, (int)$ch['id'], $ch);
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
try {
    $postprocessResult = PostprocessRunner::enqueueAndRun(
        $novelId,
        $ch,
        $fullContent,
        $engine,
        'sse:' . (gethostname() ?: 'localhost') . ':' . getmypid(),
        $writePolicySnapshot
    );
    if (!in_array((string)($postprocessResult['state'] ?? ''), ['done', 'stale', 'skipped'], true)) {
        addLog($novelId, 'warn', '章节后处理已进入持久重试队列，任务ID：' . (int)($postprocessResult['job_id'] ?? 0));
    }
} catch (Throwable $e) {
    // SSE 已结束；此处抛错会变成「服务器内部错误（追踪号）」，但正文可能已落盘。
    error_log('[write_chapter] postprocess after SSE: ' . $e->getMessage());
    addLog($novelId, 'warn', '章节后处理失败：' . $e->getMessage());
}
