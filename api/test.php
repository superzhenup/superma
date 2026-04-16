<?php
/**
 * 简单测试端点 - 不依赖数据库
 */

// 清除所有输出缓冲
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'message' => 'API 连接正常',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION
], JSON_UNESCAPED_UNICODE);
