<?php
/**
 * ================================================================
 * daemon_write.php — 挂机写作 Worker
 * ================================================================
 * 用途：由宝塔计划任务（Cron）定期 HTTP 访问此文件，实现无人值守写作。
 *
 * 宝塔 Cron 配置示例（推荐用请求头传令牌——请求头不会进访问日志/代理日志/浏览器历史）：
 *   执行周期：每 1 分钟
 *   脚本类型：Shell 脚本
 *   脚本内容：curl -s -H "X-Daemon-Token: 你的TOKEN" "http://你的域名/api/daemon_write.php"
 *
 * 安全机制（审计 P1：令牌仅从请求头读取，彻底移除 URL/POST 兜底）：
 *   - 仅通过请求头 X-Daemon-Token（或 Authorization: Bearer 你的TOKEN）鉴权，跳过 Session 登录检查
 *   - 不再接受 ?token=xxx / POST token（URL 令牌会进访问日志/代理日志，存在泄露风险）
 *   - Token 存储在 system_settings 表中，key = daemon_token
 *   - Token 仅由登录态管理端点初始化；本接口绝不返回 token
 *
 * 工作逻辑：
 *   1. 全局只允许一个小说开启挂机（enable 时自动关闭其他）
 *   2. 检查当前是否有其他进程正在写作（lock file 机制，防并发）
 *   3. 直接在进程内执行写作逻辑（不走 HTTP/Session/CSRF），调用 AIClient 同步写作
 *   4. 写完后更新统计、记录日志
 *   5. 如果所有章节完成，自动关闭挂机
 * ================================================================
 *
 * ==============================
 * 【可配置项】失败重试策略
 * ==============================
 *
 *   DAEMON_RETRY_MODE
 *     'skip'    — 重试 DAEMON_MAX_RETRIES 次后跳过该章节，继续写下一章（默认）
 *     'forever' — 一直重试，永不跳过（直到写成功为止）
 *
 *   DAEMON_MAX_RETRIES
 *     重试次数上限，仅在 DAEMON_RETRY_MODE = 'skip' 时生效。
 *     默认 3，即失败 3 次后标记为 skipped 跳过。
 *     设为 0 表示失败立即跳过（不重试）。
 * ================================================================
 */

// ================================================================
// 【配置区】修改这里调整重试策略
// ================================================================

/**
 * 失败重试模式：
 *   'skip'    — 超过最大重试次数后跳过该章节
 *   'forever' — 永远重试，直到写成功
 */
define('DAEMON_RETRY_MODE',    'skip');

/**
 * 最大重试次数（仅 DAEMON_RETRY_MODE = 'skip' 时有效）
 * 设为 0 表示失败后立即跳过，不重试
 */
define('DAEMON_MAX_RETRIES',   3);

header('Cache-Control: no-cache, no-store');
header('Content-Type: application/json; charset=utf-8');

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/daemon_auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/memory.php';
require_once dirname(__DIR__) . '/includes/prompt.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';

ob_end_clean();

// ================================================================
// 1. 鉴权：Token 验证
// ================================================================

// 审计 P1：仅从请求头读取令牌（请求头不进访问日志/代理日志/浏览器历史）。
// 不再接受 URL/POST 参数中的 token（会进日志，存在泄露风险）。
$hdrs = function_exists('getallheaders') ? array_change_key_case((array)getallheaders(), CASE_LOWER) : [];
$inputToken = trim($hdrs['x-daemon-token'] ?? $_SERVER['HTTP_X_DAEMON_TOKEN'] ?? '');
if ($inputToken === '') {
    $authHeader = $hdrs['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $authHeader, $m)) {
        $inputToken = trim($m[1]);
    }
}
$novelId    = (int)($_GET['novel_id'] ?? $_POST['novel_id'] ?? 0);
$action     = trim($_GET['action'] ?? $_POST['action'] ?? 'run');
if (in_array($action, ['enable', 'disable'], true) && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($inputToken === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Token required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$daemonToken = getDaemonToken();
if ($daemonToken === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'Daemon token is not initialized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals($daemonToken, $inputToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token 错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// P0-3 修复：检测 session 用户身份，供 enable/disable 操作做所有权校验。
// daemon_write.php 不引入 auth.php（避免自动 session_start 干扰 cron 调用），
// 此处手动启动 session 以读取已登录用户 ID。
// L-1 修复：仅在需要所有权校验的 enable/disable 动作才启动 session。run/status 走
// cron（curl 无 cookie），无条件 session_start 会让每分钟一次的挂机轮询都新建一个空
// session 文件，长期累积孤儿文件直到 gc——cron 路径不需要 session。
$sessionUserId = 0;
if (in_array($action, ['enable', 'disable'], true)) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (!empty($_SESSION['user_id'])) {
        $sessionUserId = (int)$_SESSION['user_id'];
    } elseif (!empty($_SESSION['uid'])) {
        $sessionUserId = (int)$_SESSION['uid'];
    }
}

// ================================================================
// 2. 管理操作（启用/停用/状态查询）
// ================================================================

// Lock 目录（提前定义，供 enable/disable/status/run 共用）
$lockDir = defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__) . '/storage';
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);

if ($action === 'enable' || $action === 'disable') {
    if (!$novelId) {
        echo json_encode(['ok' => false, 'msg' => '缺少 novel_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // P0-3 修复：所有权校验——区分 Web UI 调用与 Cron 调用。
    // Web UI（有 session user_id）：必须校验小说所有权，防止越权操作他人小说。
    // Cron（无 session，仅 daemon token）：daemon token 即为授权，设计上允许操作任意小说。
    if ($sessionUserId > 0) {
        require_once dirname(__DIR__) . '/includes/auth.php';
        checkNovelOwnership($novelId, $sessionUserId);
    }
    // else: daemon token 鉴权已通过，Cron 需要操作任意小说——此为设计意图。
    $val = $action === 'enable' ? 1 : 0;
    try {
        $switchedFrom = null;
        if ($val === 1) {
            // 全局唯一：先关闭其他已开启的挂机
            $existing = DB::fetch(
                "SELECT id, title FROM novels WHERE daemon_write=1 AND id!=?",
                [$novelId]
            );
            if ($existing) {
                DB::update('novels', ['daemon_write' => 0], 'id=?', [$existing['id']]);
                clearNovelCache((int)$existing['id']);
                $switchedFrom = ['id' => (int)$existing['id'], 'title' => $existing['title']];
            }
        }
        DB::update('novels', ['daemon_write' => $val], 'id=?', [$novelId]);
        // 关键：失效 getNovel() 的小说缓存（novel:{id}，TTL 300s）。否则停用挂机后刷新
        // novel.php 仍读到缓存里的 daemon_write=1，「挂机写作进行中」面板会再次出现。
        clearNovelCache($novelId);
        $resp = [
            'ok'  => true,
            'msg' => $val ? "小说#{$novelId} 挂机写作已启用" : "小说#{$novelId} 挂机写作已停用",
        ];
        if ($switchedFrom) $resp['switched_from'] = $switchedFrom;
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(safe_api_error_payload($e, '操作失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'status') {
    try {
        // 无论是否传 novel_id，优先找当前开启挂机的书
        if (!$novelId) {
            $row = DB::fetch("SELECT id FROM novels WHERE daemon_write=1 LIMIT 1");
            if ($row) $novelId = (int)$row['id'];
        }
        if (!$novelId) {
            echo json_encode(['ok' => true, 'data' => null, 'msg' => '没有开启挂机的小说'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $novel     = DB::fetch("SELECT id, title, status, current_chapter, total_words, daemon_write FROM novels WHERE id=?", [$novelId]);
        $completed = DB::count('chapters', 'novel_id=? AND status="completed"', [$novelId]);
        $outlined  = DB::count('chapters', 'novel_id=? AND status IN ("outlined","writing","completed","skipped")', [$novelId]);
        $skipped   = DB::count('chapters', 'novel_id=? AND status="skipped"', [$novelId]);
        $autoWritable = WriteEngine::countAutomaticWritableChapters($novelId);
        $manualBackfill = WriteEngine::countManualBackfillChapters($novelId);
        $locked    = file_exists($lockDir . "/daemon_lock_{$novelId}.tmp");
        $logs      = DB::fetchAll(
            "SELECT action, message, created_at FROM writing_logs WHERE novel_id=? ORDER BY created_at DESC LIMIT 10",
            [$novelId]
        );
        echo json_encode([
            'ok'   => true,
            'data' => array_merge($novel ?: [], [
                'completed' => $completed,
                'outlined'  => $outlined,
                'skipped'   => $skipped,
                'auto_writable' => $autoWritable,
                'manual_backfill' => $manualBackfill,
                'auto_blocked' => $autoWritable === 0 && $manualBackfill > 0,
                'locked'    => $locked,
                'logs'      => $logs,
            ]),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(safe_api_error_payload($e, '操作失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// W-3 修复：删除重复的 session 初始化块（已在文件前部完成）。
// 之前此处有第二份 P0-3 修复代码，会导致 run 路径下 session_start 被调用两次，
// cron 调用可能被 session 锁阻塞。

// ================================================================
// 3. 主执行逻辑：run（由 Cron 触发）
// ================================================================

// 未传 novel_id 时自动找当前开启挂机的书（全局唯一）
if (!$novelId) {
    try {
        $row = DB::fetch("SELECT id FROM novels WHERE daemon_write=1 LIMIT 1");
        if ($row) $novelId = (int)$row['id'];
    } catch (\Throwable $e) {
        error_log('daemon_write: 查询挂机小说失败 — ' . $e->getMessage());
    }
}

if (!$novelId) {
    echo json_encode(['ok' => false, 'msg' => '没有启用挂机写作的小说', 'skipped' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 审计修复（2026-07-19 H-06）：与交互式 resolveChapter 一致取全列，
    // buildPrompt/initMemory 需要 title/genre/protagonist_name 等元数据
    $novel = DB::fetch("SELECT * FROM novels WHERE id=?", [$novelId]);
} catch (\Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] daemon_write: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(safe_api_error_payload($e, '查询小说失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$novel) {
    echo json_encode(['ok' => false, 'msg' => "小说#{$novelId} 不存在"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($novel['daemon_write'])) {
    echo json_encode(['ok' => false, 'msg' => "小说#{$novelId} 未启用挂机写作", 'skipped' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$activeWriteTask = WriteEngine::findActiveTask($novelId);
if ($activeWriteTask !== null) {
    echo json_encode([
        'ok'         => false,
        'msg'        => 'Detected active foreground writing task; skipped daemon write.',
        'skipped'    => true,
        'chapter_id' => $activeWriteTask['chapter_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 启动新任务前显式回收 DB 中已超过心跳阈值的 writing。findActiveTask 已先排除
// 有效进度文件/新鲜 DB 心跳，因此这里不会抢走真实活跃的前台任务。
try {
    $recoveredWriting = WriteEngine::recoverStaleWritingChapters($novelId);
    if ($recoveredWriting) clearNovelCache($novelId);
} catch (\Throwable $e) {
    error_log('daemon_write stale-writing recovery failed: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'msg' => '无法恢复上次中断的写作状态，本次挂机已停止',
        'skipped' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Lock 文件：防止并发 ----
$lockFile = $lockDir . "/daemon_lock_{$novelId}.tmp";
$lockFp   = null;
$claimedChapterId = 0;
$claimedChapterNumber = 0;
$daemonStateFinalized = false;

// v1.11.8: 使用 flock 实现原子锁，解决竞态条件
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp) {
    echo json_encode(['ok' => false, 'msg' => '无法创建 Lock 文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    $lockAge = file_exists($lockFile) ? (time() - filemtime($lockFile)) : 0;

    if ($lockAge > 600) {
        addLog($novelId, 'daemon_unlock', "检测到死锁（已持续 {$lockAge}s > 600s），等待接管");
        @fclose($lockFp);
        $lockFp = @fopen($lockFile, 'c');
        if (!$lockFp) {
            echo json_encode(['ok' => false, 'msg' => '无法创建 Lock 文件'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $waitStart = time();
        $acquired = false;
        while (time() - $waitStart < 30) {
            if (flock($lockFp, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            sleep(1);
        }
        if (!$acquired) {
            @fclose($lockFp);
            echo json_encode(['ok' => false, 'msg' => '等待锁超时，跳过本次'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        addLog($novelId, 'daemon_unlock', "死锁接管成功（等待" . (time() - $waitStart) . "秒）");
        // 锁文件年龄只能证明锁可能失效，不能证明所有 writing 都已僵死；
        // 仍按 DB 心跳阈值逐章恢复，避免误重置活跃章节。
        WriteEngine::recoverStaleWritingChapters($novelId);
    } else {
        @fclose($lockFp);
        echo json_encode([
            'ok'       => false,
            'msg'      => "上一章仍在写作中（Lock 已持续 {$lockAge} 秒），跳过本次",
            'skipped'  => true,
            'lock_age' => $lockAge,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 检查章节是否已完成（进程可能意外终止但 Lock 残留）
$lockContent = @stream_get_contents($lockFp);
if ($lockContent && preg_match('/chapter#(\d+)/', $lockContent, $m)) {
    $lockedChId = (int)$m[1];
    $lockedCh   = DB::fetch("SELECT status FROM chapters WHERE id=?", [$lockedChId]);
    if ($lockedCh && $lockedCh['status'] === 'completed') {
        addLog($novelId, 'daemon_unlock', "章节#{$lockedChId} 已完成但 Lock 残留，继续执行");
    }
}

// shutdown 是 finally 的最后一道保险：进程 fatal/被异常终止时，释放本进程认领的
// writing，并把小说从 writing 恢复到 paused。WHERE 限定保证不覆盖已完成正文。
register_shutdown_function(function() use (
    &$lockFp,
    &$claimedChapterId,
    &$claimedChapterNumber,
    &$daemonStateFinalized,
    $novelId
) {
    if (!$daemonStateFinalized && $claimedChapterId > 0) {
        try {
            $restored = DB::update(
                'chapters',
                ['status' => 'outlined'],
                'id=? AND novel_id=? AND status="writing"',
                [$claimedChapterId, $novelId]
            );
            WriteEngine::clearPostProcessPending($novelId, $claimedChapterNumber);
            DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [$novelId]);
            clearNovelCache($novelId);
            if ($restored > 0) {
                addLog($novelId, 'daemon_recover', "挂机进程异常退出，已恢复第{$claimedChapterNumber}章为待写状态");
            }
        } catch (\Throwable $e) {
            error_log('daemon_write shutdown recovery failed: ' . $e->getMessage());
        }
    }
    if (is_resource($lockFp)) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
        $lockFp = null;
    }
});

// 查找下一章：与交互式自动写作共用同一个“完成进度线”契约。
// 任何编号不高于已完成最大章节号的 outlined/skipped 都是历史缺口，只能人工显式处理。
$nextAutoChapterId = WriteEngine::findNextAutomaticChapterId($novelId);
// 审计修复（2026-07-19 H-06）：列清单与 WriteEngine::resolveChapter 对齐（另加 retry_count），
// 否则 buildPrompt 拿不到 outline/key_points/hook/pacing，挂机章节会脱离本章大纲写作。
$nextChapter = $nextAutoChapterId
    ? DB::fetch(
        'SELECT id, novel_id, chapter_number, title, outline, key_points, hook, pacing, status, content, words, quality_score, critic_scores, ai_pattern_issues, retry_count, created_at, updated_at '
        . 'FROM chapters WHERE id=? AND novel_id=?',
        [$nextAutoChapterId, $novelId]
    )
    : null;
$isSkippedRetry = $nextChapter && (string)$nextChapter['status'] === 'skipped';

if (!$nextChapter) {
    // 没有“可写”章节不等于全书完成：pending/failed/writing 都是未完成状态。
    // 只有所有章节均为 completed 才能把小说标记为 completed。
    $totalChapterRows = DB::count('chapters', 'novel_id=?', [$novelId]);
    $unfinished = DB::count('chapters', 'novel_id=? AND status<>"completed"', [$novelId]);
    $manualBackfill = WriteEngine::countManualBackfillChapters($novelId);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $lockFp = null;

    if ($totalChapterRows > 0 && $unfinished === 0) {
        DB::update('novels', ['daemon_write' => 0, 'status' => 'completed'], 'id=?', [$novelId]);
        clearNovelCache($novelId);
        $daemonStateFinalized = true;
        addLog($novelId, 'daemon_done', '挂机写作完成，所有章节已生成，已自动关闭挂机');
        echo json_encode(['ok' => true, 'msg' => '所有章节写作完成，挂机已自动关闭', 'done' => true], JSON_UNESCAPED_UNICODE);
    } else {
        DB::update('novels', ['daemon_write' => 0, 'status' => 'paused'], 'id=?', [$novelId]);
        clearNovelCache($novelId);
        $daemonStateFinalized = true;
        addLog($novelId, 'daemon_blocked', "没有可安全自动写作的章节；章节总数{$totalChapterRows}、未完成{$unfinished}、需人工补写{$manualBackfill}，挂机已暂停");
        echo json_encode([
            'ok' => false,
            'msg' => $totalChapterRows === 0
                ? '尚未生成章节大纲，挂机已暂停'
                : "仍有{$unfinished}章未完成，其中{$manualBackfill}章位于完成进度线之前，需人工显式处理；挂机已暂停",
            'done' => false,
            'blocked' => true,
            'unfinished' => $unfinished,
            'manual_backfill_count' => $manualBackfill,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 写入 Lock 内容（已有 flock，直接写入即可）
ftruncate($lockFp, 0);
rewind($lockFp);
fwrite($lockFp, date('Y-m-d H:i:s') . " chapter#{$nextChapter['id']} PID:" . getmypid());
fflush($lockFp);

DB::update('novels',   ['status' => 'writing', 'cancel_flag' => 0], 'id=?', [$novelId]);
if ($isSkippedRetry) {
    $currentRetries = (int)(DB::fetch("SELECT retry_count FROM chapters WHERE id=?", [$nextChapter['id']])['retry_count'] ?? 0);
    if ($currentRetries >= DAEMON_MAX_RETRIES * 2) {
        addLog($novelId, 'daemon_permanent_skip', "第{$nextChapter['chapter_number']}章累计失败{$currentRetries}次，永久跳过");
        DB::update('chapters', ['status' => 'failed'], 'id=?', [$nextChapter['id']]);
        DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [$novelId]);
        clearNovelCache($novelId);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $lockFp = null;
        $daemonStateFinalized = true;
        echo json_encode(['ok' => false, 'msg' => "第{$nextChapter['chapter_number']}章永久跳过（累计失败过多）"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $claimAffected = DB::update('chapters', ['status' => 'writing'], 'id=? AND status IN ("outlined","skipped")', [$nextChapter['id']]);
} else {
    // 失败次数必须跨认领持续累积；只允许 saveChapter 成功提交时清零。
    $claimAffected = DB::update('chapters', ['status' => 'writing'], 'id=? AND status IN ("outlined","skipped")', [$nextChapter['id']]);
}

if (($claimAffected ?? 0) === 0) {
    DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [$novelId]);
    clearNovelCache($novelId);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $lockFp = null;
    $daemonStateFinalized = true;
    echo json_encode([
        'ok'      => false,
        'msg'     => 'Chapter is already claimed by another writing task.',
        'skipped' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$claimedChapterId = (int)$nextChapter['id'];
$claimedChapterNumber = (int)$nextChapter['chapter_number'];

// 审计修复（2026-07-19 H-05）：认领成功后清除可能残留的取消标志文件。
// 与上方 cancel_flag=0 的 DB 复位保持同语义——否则历史取消留下的 flag 文件会让
// 本次认领的章节在 streamWrite 第一时间被误判取消，并被当作失败累计 retry_count。
@unlink(WriteEngine::cancelFlagPath($novelId));

WriteEngine::waitForPreviousPostProcess($novelId, (int)$nextChapter['chapter_number']);

addLog($novelId, 'daemon_start',
    ($isSkippedRetry ? '[跳过章重试]' : '') . "挂机开始写作第{$nextChapter['chapter_number']}章《{$nextChapter['title']}》"
);

// ================================================================
// 4. 立即返回 202 响应，后台继续执行写作（避免 nginx 502 超时）
// ================================================================

set_time_limit(CFG_TIME_LONG);
ignore_user_abort(true);

// 先把响应发回给 nginx/curl，断开 HTTP 连接
http_response_code(202);
echo json_encode([
    'ok'             => true,
    'msg'            => "第{$nextChapter['chapter_number']}章开始写作，后台执行中...",
    'novel_id'       => $novelId,
    'chapter_number' => $nextChapter['chapter_number'],
    'is_catchup'     => false,
    'is_skipped_retry' => $isSkippedRetry,
    'timestamp'      => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);

// FPM 环境：关闭连接，PHP 进程继续在后台运行
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // 非 FPM 环境兜底：flush 后脚本继续
    if (ob_get_level()) ob_end_flush();
    flush();
}

$writeResult  = ['ok' => false, 'msg' => '未执行'];
$fullContent  = '';
$usedModel    = null;
$usage        = null;
$streamStart  = time();
$ch           = $nextChapter; // 别名，与 write_chapter.php 保持一致

try {

    // ---- Agent决策系统 ----
    try {
        // 检查是否启用Agent
        require_once dirname(__DIR__) . '/includes/config_center.php';
        if (ConfigCenter::get('agent.enabled', true)) {
            // 加载Agent协调器
            require_once dirname(__DIR__) . '/includes/agents/AgentCoordinator.php';
            
            $coordinator = new \AgentCoordinator($novelId);
            
            // 收集决策上下文
            $context = [
                'pending_foreshadowing_count' => (int)DB::fetch(
                    'SELECT COUNT(*) as cnt FROM foreshadowing_items WHERE novel_id = ? AND resolved_chapter IS NULL',
                    [$novelId]
                )['cnt'] ?? 0,
                'recent_chapters' => DB::fetchAll(
                    // 修复：补 updated_at 列（2026-07-22）。AgentCoordinator::estimateWritingSpeed()
                    // 依赖 recent_chapters[*]['updated_at']，缺列时挂机路径全部 fallback 到 time()，
                    // 写作速度恒为默认值 2.0，动态间隔 speedAdjustment 失效。
                    'SELECT id, chapter_number, title, status, words, quality_score, updated_at FROM chapters WHERE novel_id = ? AND status = "completed" ORDER BY chapter_number DESC LIMIT 5',
                    [$novelId]
                ) ?: [],
                'current_progress' => (function() use ($novelId) {
                    $novel = DB::fetch('SELECT target_chapters FROM novels WHERE id = ?', [$novelId]);
                    $target = (int)($novel['target_chapters'] ?? 0);
                    if ($target <= 0) return 0;
                    $completed = DB::fetch(
                        'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id = ? AND status = "completed"',
                        [$novelId]
                    );
                    return (int)($completed['cnt'] ?? 0) / $target;
                })(),
                'current_chapter_number' => (int)$nextChapter['chapter_number'],
            ];
            
            // 运行Agent决策
            $decisionResult = $coordinator->runDecisionCycle($context);
            
            // 记录决策结果
            if (!empty($decisionResult['execution_summary'])) {
                $summary = $decisionResult['execution_summary'];
                addLog($novelId, 'info', sprintf(
                    'Agent决策完成：决策%d次，执行%d个动作，成功%d个',
                    $summary['total_decisions'],
                    $summary['total_actions'],
                    $summary['successful_actions']
                ));
            }
        }
    } catch (\Throwable $e) {
        // Agent决策失败不影响主流程
        addLog($novelId, 'warn', 'Agent决策失败：' . $e->getMessage());
    }

    // ---- 记忆 / 写作 / 落盘：复用交互式写作同一条引擎链路 ----
    $preAiClient = null;
    try {
        $preAiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
        if ($preAiClient->is1MContext()) {
            addLog($novelId, 'info', '挂机写作检测到1M上下文模型，将使用完整上下文模式');
            if (defined('CFG_TIME_LONG_1M')) {
                set_time_limit(CFG_TIME_LONG_1M);
            }
        }
    } catch (\Throwable $e) {
        // 忽略，后续模型调用会按 WriteEngine 的 fallback 逻辑重试。
    }

    $memResult = WriteEngine::initMemory($novelId, $ch, $preAiClient);
    $engine    = $memResult['engine'];
    $memoryCtx = $memResult['memoryCtx'];
    $messages  = WriteEngine::buildPrompt($novel, $ch, $memoryCtx);

    $targetWords = max(500, (int)($novel['chapter_words'] ?? 0));
    $daemonHeartbeatAt = 0;
    $result = WriteEngine::streamWrite(
        $messages,
        $targetWords,
        $novelId,
        function(string $token) use ($lockFile) {
            @touch($lockFile);
        },
        function(array $payload) use ($novelId) {
            if (!empty($payload['model'])) {
                addLog($novelId, 'daemon_model', "{$payload['model']} 第" . ($payload['attempt'] ?? 1) . "次尝试");
            } elseif (!empty($payload['model_switch'])) {
                addLog($novelId, 'daemon_model_switch', '切换模型：' . ($payload['next_model'] ?? 'unknown'));
            } elseif (!empty($payload['warning'])) {
                addLog($novelId, 'warn', $payload['warning']);
            } elseif (!empty($payload['reason'])) {
                addLog($novelId, 'daemon_retry_model', $payload['reason']);
            } elseif (!empty($payload['info'])) {
                addLog($novelId, 'info', $payload['info']);
            }
        },
        function() use (&$daemonHeartbeatAt, $lockFile, $ch) {
            $now = time();
            @touch($lockFile);
            if ($now - $daemonHeartbeatAt >= 10) {
                $daemonHeartbeatAt = $now;
                try {
                    DB::query('UPDATE chapters SET updated_at = NOW() WHERE id = ? AND status = "writing"', [$ch['id']]);
                } catch (\Throwable $e) { error_log('daemon_write heartbeat update failed: ' . $e->getMessage()); }
            }
        },
        null,
        $novel['model_id'] ? (int)$novel['model_id'] : null,
        (int)$ch['chapter_number'],
        (int)$novel['target_chapters']
    );

    $fullContent      = $result['content'];
    $usedModel        = $result['model'];
    $streamUsage      = $result['usage'] ?? null;
    $streamDurationMs = $result['duration_ms'] ?? null;

    $saveResult = WriteEngine::saveChapter(
        (int)$ch['id'],
        $novelId,
        $fullContent,
        $targetWords,
        $usedModel,
        $ch,
        $streamUsage,
        $streamDurationMs
    );
    $words     = $saveResult['words'];
    $ch        = $saveResult['chapter'];
    $modelInfo = $saveResult['model_info'];

    $postprocessResult = PostprocessRunner::enqueueAndRun(
        $novelId,
        $ch,
        $fullContent,
        $engine,
        'daemon:' . (gethostname() ?: 'localhost') . ':' . getmypid()
    );
    if (!in_array((string)($postprocessResult['state'] ?? ''), ['done', 'stale'], true)) {
        addLog($novelId, 'warn', 'Daemon 后处理已进入持久重试队列，任务ID：' . (int)($postprocessResult['job_id'] ?? 0));
    }

    // W-7 修复：Arc 摘要已迁移到 WriteEngine::postProcess() 内部，此处不再单独生成（避免双重生成）。
    // 这样 SSE/async/daemon 三条写作路径都能一致积累 L2 弧段记忆。

    $writeResult = [
        'ok'    => true,
        'msg'   => "第{$ch['chapter_number']}章写作完成（{$words}字）{$modelInfo}",
        'words' => $words,
    ];

} catch (WriteEngineValidationException $e) {
    $errMsg = $e->getMessage();

    DB::update('chapters',
        ['status' => 'outlined'],
        'id=? AND status NOT IN ("completed")',
        [$ch['id']]
    );
    DB::update('novels', ['daemon_write' => 0, 'status' => 'paused'], 'id=?', [$novelId]);
    clearNovelCache($novelId);

    addLog($novelId, 'daemon_validation_blocked',
        "第{$ch['chapter_number']}章 strict P0 阻止落盘，挂机已暂停：" . $errMsg
    );
    $writeResult = [
        'ok'  => false,
        'msg' => "第{$ch['chapter_number']}章 strict P0 阻止落盘，挂机已暂停：" . $errMsg,
    ];

} catch (\Throwable $e) {
    // 审计修复（2026-07-19 H-05）：用户取消不是写作失败——
    // 不累计 retry_count、不标 skipped/failed，而是退出挂机并等待用户重新启动。
    if ($e->getMessage() === '用户取消了写作') {
        DB::update('chapters',
            ['status' => 'outlined'],
            'id=? AND status NOT IN ("completed")', [$ch['id']]
        );
        DB::update('novels', ['daemon_write' => 0, 'status' => 'paused'], 'id=?', [$novelId]);
        clearNovelCache($novelId);
        addLog($novelId, 'daemon_cancel', "第{$ch['chapter_number']}章已被用户取消，挂机写作已停止");
        $writeResult = ['ok' => false, 'msg' => '用户取消了写作，挂机已停止', 'canceled' => true];
        // 不 return：落入 finally 释放锁，并由下方收尾块统一固化小说状态
    }

    // 写作彻底失败 → 根据重试策略决定重试或跳过
    $retryRow = DB::fetch("SELECT retry_count FROM chapters WHERE id=?", [$ch['id']]);
    $retryCount = (int)($retryRow['retry_count'] ?? 0) + 1;

    $shouldSkip = (DAEMON_RETRY_MODE === 'skip') && ($retryCount > DAEMON_MAX_RETRIES);

    // 审计修复 H-3（2026-06-17）：脱敏 AI 错误后再写入日志/返回结果，
    // 防止 API key、URL、provider 内部 JSON 泄露给日志阅读者或前端。
    $safeErr = sanitizeAiErrorMessage($e->getMessage());

    if ($shouldSkip) {
        DB::update('chapters',
            ['status' => 'skipped', 'retry_count' => $retryCount],
            'id=? AND status NOT IN ("completed")', [$ch['id']]
        );
        addLog($novelId, 'daemon_skip',
            "第{$ch['chapter_number']}章超过最大重试次数（" . DAEMON_MAX_RETRIES . "次），标记跳过：" . $safeErr
        );
        $writeResult = ['ok' => false, 'msg' => "第{$ch['chapter_number']}章已跳过（失败{$retryCount}次）：" . $safeErr];
    } else {
        DB::update('chapters',
            ['status' => 'outlined', 'retry_count' => $retryCount],
            'id=? AND status NOT IN ("completed")', [$ch['id']]
        );
        $modeHint = DAEMON_RETRY_MODE === 'forever' ? '永久重试模式' : ('将重试，第' . $retryCount . '/' . DAEMON_MAX_RETRIES . '次');
        addLog($novelId, 'daemon_error',
            "第{$ch['chapter_number']}章出错（{$modeHint}）：" . $safeErr
        );
        $writeResult = ['ok' => false, 'msg' => "第{$ch['chapter_number']}章出错（{$modeHint}）：" . $safeErr];
    }
} finally {
    // 普通异常路径的确定性恢复。仅把仍处于 writing 的本次认领章节退回 outlined，
    // 已由 saveChapter 完成的正文不会被覆盖；锁在所有路径中只释放一次。
    if ($claimedChapterId > 0) {
        try {
            $restored = DB::update(
                'chapters',
                ['status' => 'outlined'],
                'id=? AND novel_id=? AND status="writing"',
                [$claimedChapterId, $novelId]
            );
            if ($restored > 0) {
                WriteEngine::clearPostProcessPending($novelId, $claimedChapterNumber);
                DB::update('novels', ['status' => 'paused'], 'id=? AND status="writing"', [$novelId]);
                clearNovelCache($novelId);
                addLog($novelId, 'daemon_recover', "挂机任务提前结束，已恢复第{$claimedChapterNumber}章为待写状态");
            }
        } catch (\Throwable $recoveryError) {
            error_log('daemon_write finally recovery failed: ' . $recoveryError->getMessage());
        }
    }

    if (is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $lockFp = null;
    }
}

// 更新小说状态：完成判定必须覆盖 pending/failed/stale-writing 等全部未完成状态。
$remainingWritable = WriteEngine::countAutomaticWritableChapters($novelId);
$manualBackfill = WriteEngine::countManualBackfillChapters($novelId);
$unfinished = DB::count('chapters', 'novel_id=? AND status<>"completed"', [$novelId]);
if ($unfinished === 0 && $writeResult['ok']) {
    DB::update('novels', ['daemon_write' => 0, 'status' => 'completed'], 'id=?', [$novelId]);
    clearNovelCache($novelId);
    addLog($novelId, 'daemon_all_done', '挂机写作：全部章节完成，已自动关闭挂机');
} else {
    if ($remainingWritable === 0 && $unfinished > 0) {
        DB::update('novels', ['daemon_write' => 0, 'status' => 'paused'], 'id=?', [$novelId]);
        clearNovelCache($novelId);
        addLog($novelId, 'daemon_blocked', "仍有{$unfinished}章未完成、{$manualBackfill}章需人工补写，没有可安全自动写作章节，挂机已暂停");
    } else {
        DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    }
}
// 本次 Cron 改动了 daemon_write/status，失效缓存，确保 novel.php 刷新即时同步
clearNovelCache($novelId);
$daemonStateFinalized = true;

// 响应已在写作开始前发出，此处只记录最终结果日志
addLog($novelId, 'daemon_result', implode(' | ', array_filter([
    $writeResult['msg'] ?? '',
    isset($writeResult['words']) ? "字数:{$writeResult['words']}" : '',
    "剩余可写:{$remainingWritable}章",
    "需人工补写:{$manualBackfill}章",
    "未完成:{$unfinished}章",
])));
