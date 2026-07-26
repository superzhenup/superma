<?php
/**
 * 错误处理基类 — Phase 4
 *
 * 审计优化 P3-8（2026-06-16）：提取 SSE/JSON 错误处理的公共逻辑，
 * 消除 error_handler.php 与 sse_error_handler.php 的重复代码。
 *
 * 子类只需实现 output() 方法区分响应格式（JSON / SSE）。
 * 日志记录、追踪 ID 生成、错误分级等公共逻辑统一在此。
 *
 * 使用方式：
 *   $handler = new JsonErrorHandler();   // 或 SseErrorHandler()
 *   $handler->register();
 */

defined('APP_LOADED') or die('Direct access denied.');

// 请求追踪 ID 辅助函数。规范定义在 includes/helpers.php；此处守护式重复定义，
// 确保未加载 helpers.php 的上下文也能使用（避免重复声明致命错误）。
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

abstract class BaseErrorHandler
{
    /**
     * 注册全局异常/错误/关闭处理器。
     */
    public function register(): void
    {
        set_exception_handler(function (Throwable $e): void {
            $this->logException($e);
            $this->outputFatal($e->getMessage(), basename($e->getFile()), $e->getLine());
        });

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            if (in_array($severity, [E_ERROR, E_USER_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
                $this->logError('PHP fatal', $severity, $message, $file, $line);
                $this->outputFatal($message, basename($file), $line);
            }

            $this->logError('PHP', $severity, $message, $file, $line);
            return true;
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->logError('Shutdown', $error['type'], $error['message'], basename($error['file']), $error['line']);
                $this->outputFatal($error['message'], basename($error['file']), $error['line']);
            }
        });
    }

    /**
     * 在 catch 块中构造一个"安全"的错误响应体（不发送、不退出）。
     * 完整异常细节只写服务端日志，返回稳定结构供调用方推送。
     */
    public function safePayload(Throwable $e, string $clientMsg = '操作失败，请稍后重试', string $code = 'internal_error'): array
    {
        $rid = error_trace_id();
        error_log(sprintf('[%s] %s %s: %s in %s:%d',
            $rid, static::channelLabel(), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        return array_merge(
            ['code' => $code, 'request_id' => $rid],
            $this->payloadShape($clientMsg, $rid)
        );
    }

    protected function logException(Throwable $e): void
    {
        $rid = error_trace_id();
        error_log(sprintf('[%s] %s Uncaught %s: %s in %s:%d',
            $rid, static::channelLabel(), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        error_log(sprintf('[%s] trace: %s', $rid, $e->getTraceAsString()));
    }

    protected function logError(string $prefix, int $severity, string $message, string $file, int $line): void
    {
        $rid = error_trace_id();
        error_log(sprintf('[%s] %s %s: %s in %s:%d',
            $rid, static::channelLabel(), $prefix, $message, $file, $line));
    }

    /**
     * 通道标签，用于日志区分（JSON / SSE）。
     */
    abstract protected static function channelLabel(): string;

    /**
     * 构造响应体的字段形状（子类按协议差异实现）。
     * 返回的数组会与 ['code', 'request_id'] 合并。
     */
    abstract protected function payloadShape(string $clientMsg, string $rid): array;

    /**
     * 输出致命错误响应并退出。
     */
    abstract protected function outputFatal(string $message, string $file, int $line): void;
}
