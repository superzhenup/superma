<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 获取优化大纲配置 API
 * GET 请求，返回 JSON 配置
 */

header('Content-Type: application/json; charset=utf-8');

defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

// 获取配置：直接读取 includes/config_constants.php 的 CFG_* 常量（config.php 已加载）。
// 审计 T5（2026-10-31）：原 config_optimize.php 只是把这些常量再 define 一遍、外加
// 3 个零调用函数，已删除；返回字段、取值来源与缺省值均保持不变。
$mode = defined('CFG_OPTIMIZE_MODE') ? CFG_OPTIMIZE_MODE : 'ajax';
$batchSize = defined('CFG_OPTIMIZE_BATCH') ? CFG_OPTIMIZE_BATCH : 10;
$ajaxDelay = defined('CFG_OPTIMIZE_AJAX_DELAY') ? CFG_OPTIMIZE_AJAX_DELAY : 500;
$heartbeatInterval = defined('CFG_SSE_HEARTBEAT') ? CFG_SSE_HEARTBEAT : 10;
$heartbeatTimeout = defined('CFG_PROGRESS_STALE') ? CFG_PROGRESS_STALE : 300;

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
