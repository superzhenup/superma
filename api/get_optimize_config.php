<?php
/**
 * 获取优化大纲配置 API
 * GET 请求，返回 JSON 配置
 */

header('Content-Type: application/json; charset=utf-8');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

// 加载配置文件
$configFile = dirname(__DIR__) . '/config_optimize.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// 获取配置
$mode = defined('OPTIMIZE_OUTLINE_MODE') ? OPTIMIZE_OUTLINE_MODE : 'ajax';
$batchSize = defined('OPTIMIZE_BATCH_SIZE') ? OPTIMIZE_BATCH_SIZE : 10;
$ajaxDelay = defined('AJAX_BATCH_DELAY') ? AJAX_BATCH_DELAY : 500;
$heartbeatInterval = defined('SSE_HEARTBEAT_INTERVAL') ? SSE_HEARTBEAT_INTERVAL : 10;
$heartbeatTimeout = defined('SSE_HEARTBEAT_TIMEOUT') ? SSE_HEARTBEAT_TIMEOUT : 300;

echo json_encode([
    'mode' => $mode,
    'batch_size' => $batchSize,
    'ajax_delay' => $ajaxDelay,
    'sse_heartbeat_interval' => $heartbeatInterval,
    'sse_heartbeat_timeout' => $heartbeatTimeout,
    'description' => $mode === 'ajax' 
        ? 'AJAX 轮询方案（无需服务器配置，更稳定）' 
        : 'SSE 流式方案（需要服务器配置支持）'
], JSON_UNESCAPED_UNICODE);
