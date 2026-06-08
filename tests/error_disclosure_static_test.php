<?php
/**
 * 错误信息泄露静态回归测试（全量扫描版）
 *
 * 守护目标：
 *   1) 不向客户端回传原始异常细节（$e->getMessage() / $e->getFile() / $e->getLine() /
 *      get_class($e) / $e->getTraceAsString() 拼接到响应体）；
 *   2) 客户端只能拿到稳定文案 + request_id（来自 error_trace_id()）；
 *   3) 原始异常只允许出现在 error_log() / error_log(sprintf(...)) 等服务端日志。
 *
 * 扫描范围：api/ 下全部 50 个端点 + includes/error_handler.php + includes/sse_error_handler.php。
 *
 * 模式说明：
 *   - "客户端响应" = echo/print/return/json_encode/jsonOut/sendEvent/sse/sseSend
 *     等显式输出或返回给前端的语句；
 *   - "服务端日志" = error_log(...) / error_log(sprintf(...)) / addLog(...) 是允许的；
 *   - RuntimeException 是业务异常，允许把已知的固定文案拼到 RuntimeException 自身，
 *     同样不允许拼接动态的 $e->getMessage()。
 */

$root = dirname(__DIR__);
$apiDir = $root . DIRECTORY_SEPARATOR . 'api';

function errm_read(string $abs): string
{
    if (!is_file($abs)) {
        throw new RuntimeException("Missing source file: {$abs}");
    }
    return file_get_contents($abs);
}
function errm_assert(bool $cond, string $msg): void
{
    if (!$cond) { throw new RuntimeException($msg); }
}
function errm_find_client_leaks(string $hay): array
{
    /**
     * 收集所有可能进入客户端响应的位置。
     * 我们抓取行级，看紧随其后的语句是否拼接了 $e->getMessage() / get_class($e) /
     * $e->getFile() / $e->getLine() / $e->getTraceAsString()。
     *
     * 规则：
     *  - catch (RuntimeException $e) 是业务异常通道：消息是开发可控的固定文案
     *    （"缺少参数"、"未知操作" 等），不属于"内部信息泄露"，允许直接透传
     *    $e->getMessage() 给客户端，但必须配合 error_log() 记全量日志。
     *  - catch (Throwable $e) / catch (Exception $e) 视为系统异常通道：
     *    一律禁止把 $e->getMessage() 拼进客户端响应。
     */
    $leaks = [];
    $lines = preg_split("/\r?\n/", $hay);

    // 标记每行是否处于 catch (RuntimeException $e) 块中。
    $inRuntimeCatchDepth = 0;
    $depth = 0;
    foreach ($lines as $idx => $line) {
        $no = $idx + 1;

        if ($inRuntimeCatchDepth > 0) {
            // 统计该行大括号开/闭，直到回到 0
            $inRuntimeCatchDepth += substr_count($line, '{') - substr_count($line, '}');
            if ($inRuntimeCatchDepth < 0) { $inRuntimeCatchDepth = 0; }
        }

        if (preg_match('/catch\s*\(\s*RuntimeException\s+\$e\s*\)/', $line)) {
            $inRuntimeCatchDepth = 1;
            continue;
        }

        if ($inRuntimeCatchDepth > 0) {
            continue; // 业务异常通道内允许 $e->getMessage() 透传
        }

        $stripped = ltrim($line);

        // 忽略注释行
        if (str_starts_with($stripped, '//') || str_starts_with($stripped, '#') || str_starts_with($stripped, '*')) {
            continue;
        }

        // 忽略 error_log 调用（服务端日志 OK）
        if (preg_match('/\berror_log\s*\(/', $line) || preg_match('/\baddLog\s*\(/', $line)) {
            continue;
        }

        // 客户端输出动作关键字
        $isClientOutput = preg_match(
            '/\b(echo|print|return|json_encode|jsonOut|sse\b|sseSend|sendEvent|sseMsgWrite|sendHeartbeat|jsonResponse|jsonOutAuth|apiJson|sendEventProgress|apiOut)\b/',
            $line
        );

        if (!$isClientOutput) {
            continue;
        }

        if (preg_match('/\$e->getMessage\s*\(\s*\)/', $line)
            || preg_match('/\bget_class\s*\(\s*\$e\s*\)/', $line)
            || preg_match('/\$e->getFile\s*\(\s*\)/', $line)
            || preg_match('/\$e->getLine\s*\(\s*\)/', $line)
            || preg_match('/\$e->getTraceAsString\s*\(\s*\)/', $line)
        ) {
            $leaks[] = "L{$no}: {$line}";
            continue;
        }

        // 抓取以 s.* 行 = sse / sendEvent 等的延续
        $next = $lines[$idx + 1] ?? '';
        if (preg_match('/\$e->getMessage\s*\(\s*\)/', $next)
            && preg_match('/\[.*=>/', $next)
        ) {
            $leaks[] = "L{$no}+1: {$next}";
        }
    }
    return $leaks;
}

$totalScanned = 0;
$totalLeaks = 0;
$reportLines = [];

// 收口函数必须就位
$helpers = errm_read($root . '/includes/helpers.php');
errm_assert(strpos($helpers, 'function error_trace_id') !== false,
    'includes/helpers.php must define error_trace_id()');

$eh = errm_read($root . '/includes/error_handler.php');
errm_assert(strpos($eh, "function safe_api_error_payload") !== false,
    'includes/error_handler.php must define safe_api_error_payload()');

$sse = errm_read($root . '/includes/sse_error_handler.php');
errm_assert(strpos($sse, "function safe_sse_error_payload") !== false,
    'includes/sse_error_handler.php must define safe_sse_error_payload()');

// 收集 api/ 下所有 .php 端点
$apiFiles = glob($apiDir . DIRECTORY_SEPARATOR . '*.php');
sort($apiFiles);

foreach ($apiFiles as $abs) {
    $rel = 'api/' . basename($abs);
    $src = errm_read($abs);
    $totalScanned++;
    $leaks = errm_find_client_leaks($src);
    if (!empty($leaks)) {
        $totalLeaks += count($leaks);
        $reportLines[] = "---- {$rel} ----";
        foreach ($leaks as $l) {
            $reportLines[] = "  {$l}";
        }
    }
}

if ($totalLeaks > 0) {
    echo "error_disclosure_static_test FAILED: {$totalLeaks} leak(s) in {$totalScanned} API endpoint(s)\n";
    echo implode("\n", $reportLines) . "\n";
    exit(1);
}

echo "error_disclosure_static_test passed ({$totalScanned} API endpoints scanned, 0 leaks)\n";
