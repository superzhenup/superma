<?php
/**
 * 集中化错误处理 — Phase 4
 * 
 * 为 API 文件提供统一的错误处理机制：
 *   - registerApiErrorHandlers() 注册全局异常/错误处理器
 *   - api_error()            标准 JSON 错误响应
 *   - api_error_unless()     断言快捷方式
 * 
 * 使用方式（在 API 文件最顶部，header() 之后）：
 *   require_once __DIR__ . '/../includes/error_handler.php';
 *   registerApiErrorHandlers();
 * 
 * 注意：SSE 流式文件不应使用此模块（它们有自己的 SSE 错误处理协议）。
 */

defined('APP_LOADED') or die('Direct access denied.');

// 请求追踪 ID 辅助函数。规范定义在 includes/helpers.php；此处守护式重复定义，
// 确保未加载 helpers.php 的 API 上下文也能使用（避免重复声明致命错误）。
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
 * 返回标准 JSON 错误并退出。
 * 
 * @param string $message  错误消息
 * @param int    $httpCode HTTP 状态码（默认 400）
 * @param array  $extra    额外字段（如 'skipped' => true）
 */
function api_error(string $message, int $httpCode = 400, array $extra = []): void {
    if (!headers_sent()) {
        http_response_code($httpCode);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }
    $payload = array_merge(['ok' => false, 'error' => $message], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 断言条件为真，否则返回 api_error。
 * 
 * @param bool   $condition 条件表达式
 * @param string $message   失败时的错误消息
 * @param int    $httpCode  HTTP 状态码
 */
function api_error_unless(bool $condition, string $message, int $httpCode = 400): void {
    if (!$condition) {
        api_error($message, $httpCode);
    }
}

/**
 * 在 catch 块中构造一个"安全"的 JSON 错误响应体（不退出当前进程）。
 *
 * 审计 P0（2026-06-04 全量收口）：50 个 API 端点的 catch 块曾直接把
 * `$e->getMessage()` 拼进 `msg` / `error` 字段回传客户端，泄露内部异常信息。
 * 该辅助函数把完整异常细节（类名/消息/文件/行号）只写服务端日志，
 * 并返回一个 {ok:false, msg:友好文案, code, request_id} 的稳定结构。
 *
 * 使用方式（替代 `jsonOut(['ok'=>false, 'msg'=>$e->getMessage()])`）：
 *   } catch (Throwable $e) {
 *       jsonOut(safe_api_error_payload($e, '保存失败，请稍后重试'));
 *       return;
 *   }
 *
 * 任何客户端文案（含操作语义）由调用点传入，库内只兜底默认文案。
 */
function safe_api_error_payload(Throwable $e, string $clientMsg = '操作失败，请稍后重试', string $code = 'internal_error'): array {
    $rid = error_trace_id();
    error_log(sprintf('[%s] %s: %s in %s:%d',
        $rid, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    return [
        'ok'         => false,
        'msg'        => $clientMsg,
        'code'       => $code,
        'request_id' => $rid,
    ];
}

/**
 * 注册 API 全局异常/错误处理器。
 * 
 * - 异常：统一返回 JSON 错误（避免裸 500 HTML 页面）
 * - 致命错误：统一返回 JSON 错误
 * - notice/warning：写入 error_log，不中断（与业务无关的警告不打断流程）
 * 
 * 仅用于 JSON API 文件，SSE 流式文件请勿调用。
 */
function registerApiErrorHandlers(): void {
    // 全局异常处理
    // 审计 P0：完整异常细节（类名/消息/文件/行号/堆栈）只写服务端日志，
    // 客户端仅收到稳定错误码 + 友好文案 + 可追踪请求 ID。
    set_exception_handler(function (Throwable $e) {
        $rid = error_trace_id();
        error_log(sprintf('[%s] Uncaught %s: %s in %s:%d',
            $rid, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        error_log(sprintf('[%s] trace: %s', $rid, $e->getTraceAsString()));
        api_error('服务器内部错误，请稍后重试', 500, [
            'code'       => 'internal_error',
            'request_id' => $rid,
        ]);
    });

    // 全局错误处理
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        // @ 抑制的错误不处理
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // 致命错误 → JSON 返回（同样只把细节写日志，不回传 message/file/line）
        if (in_array($severity, [E_ERROR, E_USER_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            $rid = error_trace_id();
            error_log(sprintf('[%s] PHP fatal: %s in %s:%d', $rid, $message, $file, $line));
            api_error('服务器内部错误，请稍后重试', 500, [
                'code'       => 'internal_error',
                'request_id' => $rid,
            ]);
        }

        // notice/warning → error_log
        error_log("PHP {$severity}: {$message} in {$file}:{$line}");
        return true;
    });
}
