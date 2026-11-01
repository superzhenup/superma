<?php
/**
 * Authenticated daemon-token management endpoint.
 *
 * The public Cron worker accepts a token but must never disclose one.
 * 审计 Low-3（2026-10-31）：
 *   - action=get_or_create（默认，原行为）：首次生成或幂等返回既有令牌；
 *   - action=rotate：以 random_bytes 轮换令牌，旧值立即失效，返回新令牌。
 *     两者均为仅管理员、仅 POST + CSRF；未知 action fail-closed（400）。
 *   - 可选 TTL（daemon_token_ttl_days，默认 0=不过期）由 daemon_write.php
 *     鉴权段强制：>0 且已过期时返回 401 + token_expired。
 */
defined('APP_LOADED') or define('APP_LOADED', true);
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi(true);
requireHttpMethod('POST');
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/daemon_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// 审计修复 SEC-M2（2026-07-01）：daemon_token 是 Cron 工作密钥，
// 属系统级凭证，仅管理员可读取/轮换，防止任意登录会话拉取后外泄。
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'msg' => '需要管理员权限',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 审计 Low-3（2026-10-31）：轮换是显式动作，未知 action fail-closed。
// 复核修复：前端 apiPost() 发送 JSON 请求体（$_POST 为空），必须依次从
// $_POST、JSON 请求体（php://input）、$_GET 解析动作（与 api/drama_actions.php
// 同款顺序），否则 action=rotate 会静默退回 get_or_create、返回旧令牌。
$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim((string)($_POST['action'] ?? $jsonInput['action'] ?? $_GET['action'] ?? 'get_or_create'));
if ($action === '') {
    $action = 'get_or_create';
}
if (!in_array($action, ['get_or_create', 'rotate'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => '无效的操作',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($action === 'rotate') {
        echo json_encode([
            'ok'      => true,
            'token'   => rotateDaemonToken(),
            'rotated' => true,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok'    => true,
            'token' => getOrCreateDaemonToken(),
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'  => false,
        'msg' => $action === 'rotate'
            ? 'Unable to rotate daemon token'
            : 'Unable to initialize daemon token',
    ], JSON_UNESCAPED_UNICODE);
}

