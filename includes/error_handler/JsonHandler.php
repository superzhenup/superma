<?php
/**
 * JSON API 错误处理器
 *
 * 审计优化 P3-8（2026-06-16）：继承 BaseErrorHandler，实现 JSON 输出格式。
 * 替代原 error_handler.php 中的 registerApiErrorHandlers() 函数式实现。
 *
 * 兼容策略：保留原 error_handler.php 的函数式 API（registerApiErrorHandlers、
 * api_error、safe_api_error_payload 等），内部委托给本类，确保 50+ API 端点无需改动。
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseHandler.php';

class JsonErrorHandler extends BaseErrorHandler
{
    protected static function channelLabel(): string
    {
        return 'JSON';
    }

    protected function payloadShape(string $clientMsg, string $rid): array
    {
        return [
            'ok'  => false,
            'msg' => $clientMsg,
        ];
    }

    protected function outputFatal(string $message, string $file, int $line): void
    {
        $rid = error_trace_id();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'         => false,
            'msg'        => '服务器内部错误，请稍后重试',
            'code'       => 'internal_error',
            'request_id' => $rid,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
