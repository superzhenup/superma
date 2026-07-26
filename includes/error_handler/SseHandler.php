<?php
/**
 * SSE 流式 API 错误处理器
 *
 * 审计优化 P3-8（2026-06-16）：继承 BaseErrorHandler，实现 SSE 输出格式。
 * 替代原 sse_error_handler.php 中的 registerSseErrorHandlers() 函数式实现。
 *
 * 兼容策略：保留原 sse_error_handler.php 的函数式 API（registerSseErrorHandlers、
 * sseFatalError、safe_sse_error_payload 等），内部委托给本类，确保 SSE 端点无需改动。
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseHandler.php';

class SseErrorHandler extends BaseErrorHandler
{
    protected static function channelLabel(): string
    {
        return 'SSE';
    }

    /**
     * SSE 错误事件字段形状。
     * 前端兼容：assets/js/app.js 读取 fatal_error 事件的 d.message / d.msg，
     * 故两个键都填入相同的友好文案。
     */
    protected function payloadShape(string $clientMsg, string $rid): array
    {
        return [
            'error'   => $clientMsg,
            'msg'     => $clientMsg,
            'message' => $clientMsg,
        ];
    }

    protected function outputFatal(string $message, string $file, int $line): void
    {
        $rid = error_trace_id();

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
        }

        $clientMsg = '服务器内部错误，请稍后重试（追踪号 ' . $rid . '）';
        echo "event: fatal_error\n";
        echo 'data: ' . json_encode([
            'code'       => 'internal_error',
            'error'      => $clientMsg,
            'message'    => $clientMsg,
            'msg'        => $clientMsg,
            'request_id' => $rid,
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";

        if (ob_get_level()) ob_flush();
        flush();
        exit;
    }
}
