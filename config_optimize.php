<?php
/**
 * 优化大纲配置文件
 * 
 * 用于快速切换 SSE 和 AJAX 两种优化方案
 */

// 优化大纲方案选择
// 'sse'  - SSE 流式方案（需要服务器配置支持）
// 'ajax' - AJAX 轮询方案（无需服务器配置，更稳定）
define('OPTIMIZE_OUTLINE_MODE', CFG_OPTIMIZE_MODE);

// 批次大小（两种方案通用）
define('OPTIMIZE_BATCH_SIZE', CFG_OPTIMIZE_BATCH);

// AJAX 轮询配置
define('AJAX_BATCH_DELAY', CFG_OPTIMIZE_AJAX_DELAY);  // 批次间隔（毫秒）

// SSE 配置
define('SSE_HEARTBEAT_INTERVAL', CFG_SSE_HEARTBEAT);  // 心跳间隔（秒）
define('SSE_HEARTBEAT_TIMEOUT', CFG_PROGRESS_STALE);  // 心跳超时（秒）

/**
 * 获取当前优化方案
 */
function getOptimizeMode() {
    return defined('OPTIMIZE_OUTLINE_MODE') ? OPTIMIZE_OUTLINE_MODE : 'ajax';
}

/**
 * 是否使用 AJAX 方案
 */
function isAjaxMode() {
    return getOptimizeMode() === 'ajax';
}

/**
 * 是否使用 SSE 方案
 */
function isSseMode() {
    return getOptimizeMode() === 'sse';
}
