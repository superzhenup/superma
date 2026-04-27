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
    set_exception_handler(function (Throwable $e) {
        api_error('服务器错误: ' . $e->getMessage(), 500);
    });

    // 全局错误处理
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        // @ 抑制的错误不处理
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // 致命错误 → JSON 返回
        if (in_array($severity, [E_ERROR, E_USER_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => "PHP 严重错误: $message",
                'file'  => basename($file),
                'line'  => $line,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // notice/warning → error_log
        error_log("PHP {$severity}: {$message} in {$file}:{$line}");
        return true;
    });
}
