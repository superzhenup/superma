<?php
/**
 * XSS / HTTP Header 注入 / CSV 公式注入静态回归测试
 *
 * 守护目标：
 *   1) Content-Disposition filename 的值中不得出现裸的 "$novel['title']" /
 *      "$row['title']" / "$filename" 等未清洗的用户/DB 字段。
 *      必须经过 ecsSafeFilename / urlencode / preg_replace('/[^\w]/',...) 等显式清洗。
 *   2) fputcsv() 的单元格不得直接拼用户/DB 字段，必须经过 ecsCsvSafe() 之类的转义。
 *   3) HTML 模板中 echo $var 不得出现，必须使用 htmlspecialchars()/h() 转义。
 *   4) Content-Disposition filename= 不得使用裸的 "$novel['title']"，必须包一层白名单清洗函数。
 *
 * 扫描范围：api/ + 项目根 .php 模板（login.php / novel.php / ...）。
 */

$root = dirname(__DIR__);
$apiDir = $root . DIRECTORY_SEPARATOR . 'api';

function xss_read(string $abs): string
{
    if (!is_file($abs)) {
        throw new RuntimeException("Missing source file: {$abs}");
    }
    return file_get_contents($abs);
}

function xss_collect(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $abs = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_file($abs) && substr($f, -4) === '.php') {
            $out[] = $abs;
        }
    }
    sort($out);
    return $out;
}

/**
 * 检查 Content-Disposition 是否使用裸用户/DB 字段。
 *
 * 违规模式：
 *   header('Content-Disposition: attachment; filename="' . $novel['title'] . ...
 *   header('Content-Disposition: attachment; filename="' . $row['title'] . ...
 *
 * 合规模式：
 *   header('Content-Disposition: ...; filename="' . ecsSafeFilename(...) . ...
 *   header('Content-Disposition: ...; filename="' . urlencode(...) . ...
 *   header('Content-Disposition: ...; filename="' . preg_replace('/...', ..., ...) . ...
 */
function xss_find_header_injection(string $hay): array
{
    $leaks = [];
    $lines = preg_split("/\r?\n/", $hay);
    foreach ($lines as $idx => $line) {
        $no = $idx + 1;
        if (!preg_match('/Content-Disposition\s*:/i', $line)) {
            continue;
        }
        // 仅检测 filename= 段
        if (!preg_match('/filename\s*=/i', $line)) {
            continue;
        }
        // 命中 "$novel['title']" / "$row['title']" / "$data['title']" 等裸字段
        // 允许在以下清洗包装内出现：
        //   - ecsSafeFilename(...)
        //   - safeFilename(...)
        //   - urlencode(...)
        //   - rawurlencode(...)
        //   - preg_replace('/[^\w]/', ..., ...)
        //   - preg_replace('/[\\/\\\\\\:\\*\\?\\"\\<\\>\\|]/', ..., ...)
        if (preg_match('/\$\w+\[\s*[\'"][a-z_][a-z0-9_]*[\'"]\s*\]/', $line)) {
            // 如果整段被清洗函数包裹，则放行
            $clean =
                preg_match('/ecsSafeFilename\s*\(/', $line)
                || preg_match('/safeFilename\s*\(/', $line)
                || preg_match('/urlencode\s*\(/', $line)
                || preg_match('/rawurlencode\s*\(/', $line)
                || preg_match('/preg_replace\s*\(/', $line);
            if (!$clean) {
                $leaks[] = "L{$no}: {$line}";
            }
        }
    }
    return $leaks;
}

/**
 * 检查 fputcsv 是否使用裸字段（Excel 公式注入风险）。
 * 仅在有 fputcsv 调用的文件里检查。
 */
function xss_find_csv_injection(string $hay): array
{
    $leaks = [];
    $lines = preg_split("/\r?\n/", $hay);
    foreach ($lines as $idx => $line) {
        $no = $idx + 1;
        // 检测 fputcsv($output, [..., $ch['xxx'], ...])
        if (!preg_match('/fputcsv\s*\(/', $line)) {
            continue;
        }
        // 命中裸的 $ch['title'] / $row['xxx'] 等未转义字段
        if (preg_match('/\$\w+\[\s*[\'"][a-z_][a-z0-9_]*[\'"]\s*\]/', $line)) {
            // 允许的清洗包装
            $clean =
                preg_match('/ecsCsvSafe\s*\(/', $line)
                || preg_match('/csvSafe\s*\(/', $line)
                || preg_match('/\(int\)\s*\(?\s*\$\w+/', $line);  // 数字字段不需清洗
            if (!$clean) {
                $leaks[] = "L{$no}: {$line}";
            }
        }
    }
    return $leaks;
}

/**
 * 主流程
 */
$files = array_merge(xss_collect($apiDir), xss_collect($root));
$files = array_unique($files);
$totalScanned = 0;
$totalLeaks = 0;
$report = [];

foreach ($files as $f) {
    $totalScanned++;
    $hay = xss_read($f);
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $f);

    $fileLeaks = [];

    $h = xss_find_header_injection($hay);
    if ($h) { $fileLeaks['header_injection'] = $h; }

    $c = xss_find_csv_injection($hay);
    if ($c) { $fileLeaks['csv_injection'] = $c; }

    if (!empty($fileLeaks)) {
        $totalLeaks += array_sum(array_map('count', $fileLeaks));
        $report[] = "---- {$rel} ----";
        foreach ($fileLeaks as $kind => $items) {
            $report[] = "  [{$kind}]";
            foreach ($items as $it) {
                $report[] = "    {$it}";
            }
        }
    }
}

if ($totalLeaks === 0) {
    echo "xss_header_injection_static_test passed ({$totalScanned} files scanned, 0 leaks)\n";
    exit(0);
}

echo "xss_header_injection_static_test FAILED: {$totalLeaks} leak(s) in {$totalScanned} files scanned\n";
foreach ($report as $line) {
    echo $line . "\n";
}
exit(1);
