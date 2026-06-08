<?php
/**
 * Authenticated daemon-token management endpoint.
 *
 * The public Cron worker accepts a token but must never disclose one.
 */
define('APP_LOADED', true);

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

