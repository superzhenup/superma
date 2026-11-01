<?php
/**
 * ================================================================
 * drama_cron.php — 漫剧任务队列监控 / 自动驱动入口
 * ================================================================
 * 用途：由宝塔等计划任务（Cron）定期 HTTP 访问此文件，驱动 drama_tasks
 *       队列（拉起 CLI worker；exec 不可用时内联执行一个任务）。
 *       同时为 drama.php 页面"任务监控 & 自动驱动"面板提供
 *       status（查询状态）/ run（立即处理）/ cancel（取消全部）操作。
 *
 * 鉴权（Token 模式，与 daemon_write.php 同源的 fail-closed 设计）：
 *   - get_token 动作：仅接受已登录会话（登录后前端获取令牌；绝不接受
 *     token 自举，防止匿名方枚举）。令牌存储在 system_settings 表，
 *     key = drama_cron_token，首次获取时生成。
 *   - status / run / cancel 动作：仅接受 X-Drama-Cron-Token 或
 *     Authorization: Bearer 请求头；计划任务使用 Shell/curl，禁止 URL 令牌。
 *   - 令牌比较使用 hash_equals（常量时间），空令牌 fail-closed（503）。
 *   - rotate_token 动作（审计 Low-3）：与 get_token 同等鉴权（登录会话 +
 *     POST + CSRF），random_bytes 生成新值覆盖旧值并刷新签发时间
 *     drama_cron_token_issued_at，旧令牌立即失效
 *   - 可选 TTL（审计 Low-3）：drama_cron_token_ttl_days 默认 0=不过期；
 *     >0 且已过期时鉴权返回 401 + token_expired（升级前令牌永不过期）
 *
 * 工作逻辑：
 *   - status  ：drama_tasks 各状态计数、可认领数、最近活跃任务、exec 可用性
 *   - run     ：有可认领任务时拉起 bin/drama_worker.php；exec 不可用则
 *              内联同步执行一个任务（视频任务由 DramaShotRunner requeue，
 *              下次驱动继续轮询）
 *   - cancel  ：将全部 pending/running 任务置为 canceled（运行中的任务
 *              其 worker 完成后 markDone/requeue 因 status≠running 被丢弃）
 * ================================================================
 */

header('Cache-Control: no-cache, no-store');
header('Content-Type: application/json; charset=utf-8');

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardHttp();
require_once dirname(__DIR__) . '/includes/RequestBody.php';
if (readRequestBodyBounded(10 * 1024 * 1024) === null) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'msg' => '请求体过大'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/drama/DramaTaskRunner.php';
require_once dirname(__DIR__) . '/includes/drama/DramaWorkerLauncher.php';
require_once dirname(__DIR__) . '/includes/RequestSecurity.php';
require_once dirname(__DIR__) . '/includes/daemon_auth.php';

ob_end_clean();

/** 读取漫剧 cron 令牌（不创建、不暴露文件兜底）。 */
function dramaCronGetToken(): string
{
    $row = DB::fetch("SELECT setting_value FROM system_settings WHERE setting_key='drama_cron_token'");
    return trim((string)($row['setting_value'] ?? ''));
}

/** 为已认证操作者初始化令牌（首次生成，之后幂等返回既有值）。 */
function dramaCronGetOrCreateToken(): string
{
    $token = dramaCronGetToken();
    if ($token !== '') {
        return $token;
    }

    $generated = bin2hex(random_bytes(24));
    DB::query(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('drama_cron_token', ?)
         ON DUPLICATE KEY UPDATE setting_value = setting_value",
        [$generated]
    );
    // 审计 Low-3：首次生成记录签发时间（TTL 门与轮换共用同一时间戳）。
    writeCronSecretIssuedAt('drama_cron_token_issued_at');
    if (function_exists('clearSystemSettingsCache')) clearSystemSettingsCache();

    $token = dramaCronGetToken();
    return $token !== '' ? $token : $generated;
}

/** 审计 Low-3：轮换令牌——random_bytes 新值覆盖旧值并刷新签发时间，旧令牌立即失效。 */
function dramaCronRotateToken(): string
{
    return rotateCronSecretToken('drama_cron_token', 'drama_cron_token_issued_at');
}

function dramaCronOut(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================
// 1. 鉴权
// ================================================================

if (array_key_exists('token', $_GET)) {
    dramaCronOut(['ok' => false, 'msg' => 'URL tokens are disabled; use an authorization header'], 400);
}
$action = trim((string)($_GET['action'] ?? 'run'));
if (!in_array($action, ['status', 'run', 'cancel', 'get_token', 'rotate_token'], true)) {
    $action = 'run';
}

// get_token / rotate_token：仅登录会话可用（L-1 修复模式：仅这两个动作
// 启动 session，cron 路径不无条件 session_start，避免每分钟新建空 session
// 文件）。rotate_token 与 get_token 同等鉴权（登录 + POST + CSRF），
// 轮换后旧令牌立即失效，返回新令牌（审计 Low-3）。
if ($action === 'get_token' || $action === 'rotate_token') {
    require_once dirname(__DIR__) . '/includes/auth.php';
    requireHttpMethod('POST');
    requireLoginApi();
    if ($action === 'rotate_token') {
        dramaCronOut(['ok' => true, 'token' => dramaCronRotateToken(), 'rotated' => true]);
    }
    dramaCronOut(['ok' => true, 'token' => dramaCronGetOrCreateToken()]);
}

// 其余动作：仅请求头鉴权。
$hdrs = function_exists('getallheaders') ? array_change_key_case((array)getallheaders(), CASE_LOWER) : [];
$requestHeaders = $_SERVER;
$requestHeaders['HTTP_X_DRAMA_CRON_TOKEN'] = $hdrs['x-drama-cron-token'] ?? $_SERVER['HTTP_X_DRAMA_CRON_TOKEN'] ?? '';
$requestHeaders['HTTP_AUTHORIZATION'] = $hdrs['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$inputToken = RequestSecurity::bearerToken($requestHeaders, 'HTTP_X_DRAMA_CRON_TOKEN');

if ($inputToken === '') {
    dramaCronOut(['ok' => false, 'msg' => 'Token required'], 401);
}
$cronToken = dramaCronGetToken();
if ($cronToken === '') {
    dramaCronOut(['ok' => false, 'msg' => 'Drama cron token is not initialized'], 503);
}
if (!hash_equals($cronToken, $inputToken)) {
    dramaCronOut(['ok' => false, 'msg' => 'Token 错误'], 403);
}

// 审计 Low-3（2026-10-31）：可选 TTL（drama_cron_token_ttl_days，默认 0=不过期）。
// 先验令牌再验期龄：未持有正确令牌的调用方统一 403，不泄露期龄信息；
// 升级前签发的令牌没有 issued_at，fail-safe 永不过期。
if (cronSecretTtlExpired('drama_cron_token_ttl_days', 'drama_cron_token_issued_at')) {
    dramaCronOut(['ok' => false, 'msg' => 'Token 已过期，请轮换令牌并更新 Cron 配置', 'error' => 'token_expired'], 401);
}

// ================================================================
// 2. 动作分发
// ================================================================

try {
    switch ($action) {
        // ---- 查询状态 ----
        case 'status': {
            $now = gmdate('Y-m-d H:i:s');
            $stats = [];
            foreach (DB::fetchAll("SELECT status, COUNT(*) AS c FROM drama_tasks GROUP BY status") as $row) {
                $stats[(string)$row['status']] = (int)$row['c'];
            }
            $claimable = DB::count(
                'drama_tasks',
                "((status='pending' AND run_after <= ?) OR (status='running' AND lease_expires_at IS NOT NULL AND lease_expires_at < ?))",
                [$now, $now]
            );
            $tasks = DB::fetchAll(
                "SELECT id, project_id, episode_id, type, status, progress, attempts, error, updated_at
                 FROM drama_tasks WHERE status IN ('pending','running','failed')
                 ORDER BY id DESC LIMIT 20"
            );
            foreach ($tasks as &$t) {
                $t['error'] = mb_substr((string)$t['error'], 0, 200);
            }
            unset($t);

            $pending = (int)($stats['pending'] ?? 0);
            $running = (int)($stats['running'] ?? 0);
            dramaCronOut([
                'ok'            => true,
                'msg'           => sprintf('待处理 %d / 执行中 %d / 可认领 %d', $pending, $running, $claimable),
                'stats'         => $stats,
                'claimable'     => $claimable,
                'exec_available' => DramaWorkerLauncher::execAvailable(),
                'tasks'         => $tasks,
            ]);
        }

        // ---- 立即处理：拉起 worker 或内联执行 ----
        case 'run': {
            $now = gmdate('Y-m-d H:i:s');
            $claimable = DB::count(
                'drama_tasks',
                "((status='pending' AND run_after <= ?) OR (status='running' AND lease_expires_at IS NOT NULL AND lease_expires_at < ?))",
                [$now, $now]
            );
            if ($claimable <= 0) {
                dramaCronOut(['ok' => true, 'msg' => '队列空闲，无需处理', 'processed_count' => 0]);
            }

            if (DramaWorkerLauncher::launch(50)) {
                dramaCronOut(['ok' => true, 'msg' => '已拉起后台处理进程', 'processed_count' => 0, 'launched' => true]);
            }

            // exec 不可用（共享主机）：内联同步执行一个任务。
            // 视频任务提交/查询为快速 HTTP 调用并 requeue，由下次驱动继续。
            @set_time_limit(600);
            $result = DramaTaskRunner::runNext('drama-cron:' . (gethostname() ?: 'localhost') . ':' . getmypid());
            dramaCronOut([
                'ok'             => true,
                'msg'            => $result === null ? '没有可认领的任务' : '已处理 1 个任务',
                'processed_count' => $result === null ? 0 : 1,
                'launched'       => false,
                'result'         => $result,
            ]);
        }

        // ---- 取消全部 pending/running 任务 ----
        case 'cancel': {
            $n = DB::execute(
                "UPDATE drama_tasks
                 SET status='canceled', lease_owner=NULL, lease_expires_at=NULL
                 WHERE status IN ('pending','running')"
            );
            dramaCronOut(['ok' => true, 'msg' => '已取消 ' . $n . ' 个任务', 'canceled_count' => $n]);
        }

        default:
            dramaCronOut(['ok' => false, 'msg' => '无效的操作'], 400);
    }
} catch (Throwable $e) {
    error_log('drama_cron error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    dramaCronOut(['ok' => false, 'msg' => '操作失败，请稍后重试'], 500);
}
