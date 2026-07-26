<?php
/**
 * Authenticated daemon-token management endpoint.
 *
 * The public Cron worker accepts a token but must never disclose one.
 */
define('APP_LOADED', true);
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
// 属系统级凭证，仅管理员可读取，防止任意登录会话拉取后外泄。
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'msg' => '需要管理员权限',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    echo json_encode([
        'ok' => true,
        'token' => getOrCreateDaemonToken(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Unable to initialize daemon token',
    ], JSON_UNESCAPED_UNICODE);
}

