<?php
/**
 * SSE 流式 API 统一错误处理
 *
 * 为 SSE 流式 API 文件提供统一的异常/错误/致命错误处理器：
 *   - registerSseErrorHandlers()  注册全局处理器
 *   - sseFatalError()             统一 SSE 致命错误输出格式
 *
 * 使用方式（在 SSE API 文件中，header 设置之后、业务逻辑之前）：
 *   require_once __DIR__ . '/sse_error_handler.php';
 *   registerSseErrorHandlers();
 */

defined('APP_LOADED') or die('Direct access denied.');

// 请求追踪 ID 辅助函数。规范定义在 includes/helpers.php；此处守护式重复定义，
// 确保未加载 helpers.php 的 SSE 上下文也能使用（避免重复声明致命错误）。
if (!function_exists('error_trace_id')) {
    function error_trace_id(): string {
        static $rid = null;
        if ($rid === null) {
            try {
                $rid = bin2hex(random_bytes(6));
            } catch (\Throwable $e) {
                $rid = substr(md5(uniqid('', true)), 0, 12);
            }
        }
        return $rid;
    }
}

/**
 * 输出统一的 SSE 致命错误事件。
 *
 * 审计 P0：原实现把异常类型/消息/文件/行号直接经 SSE 回传，泄露内部结构与路径。
 * 现在完整细节只写服务端日志，客户端仅收到稳定文案 + 可追踪请求 ID。
 * 入参 $type/$message/$file/$line 视为服务端诊断信息，不再进入响应体。
 *
 * 前端兼容：assets/js/app.js 读取 fatal_error 事件的 d.message / d.msg，
 * 故两个键都填入相同的友好文案（内含追踪号），不读取 file/line。
 */
/**
 * 在 catch 块中构造一个"安全"的 SSE 错误事件体（不发送、不退出）。
 *
 * 审计 P0（2026-06-04 全量收口）：多个 SSE 端点曾把 `$e->getMessage()`
 * 直接拼进 `$sseSend(['error' => $e->getMessage()])` / `sse('error', ['msg' => ...])`
 * 回传客户端，泄露内部异常信息。
 *
 * 该辅助函数把完整异常细节（类名/消息/文件/行号）只写服务端日志，
 * 并返回一段可直接经 SSE 通道推送的 data payload（含稳定文案 + request_id）。
 *
 * 使用方式（替代 `$sseSend(['error' => $e->getMessage()])`）：
 *   } catch (Throwable $e) {
 *       $sseSend(safe_sse_error_payload($e, '对话失败，请稍后重试'));
 *   }
 */
function safe_sse_error_payload(Throwable $e, string $clientMsg = '操作失败，请稍后重试', string $code = 'internal_error'): array {
    $rid = error_trace_id();
    error_log(sprintf('[%s] SSE %s: %s in %s:%d',
        $rid, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    return [
        'code'       => $code,
        'error'      => $clientMsg,
        'msg'        => $clientMsg,
        'request_id' => $rid,
    ];
}

function sseFatalError(string $type, string $message, string $file, int $line): void {
    $rid = error_trace_id();
    error_log(sprintf('[%s] SSE %s: %s in %s:%d', $rid, $type, $message, $file, $line));

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
}

function registerSseErrorHandlers(): void {
    set_exception_handler(function (Throwable $e) {
        sseFatalError('Exception', $e->getMessage(), basename($e->getFile()), $e->getLine());
    });

    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        if (in_array($severity, [E_ERROR, E_USER_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            sseFatalError('PHP Error', $message, basename($file), $line);
        }

        error_log("PHP {$severity}: {$message} in {$file}:{$line}");
        return true;
    });

    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            sseFatalError('Shutdown', $error['message'], basename($error['file']), $error['line']);
        }
    });
}
