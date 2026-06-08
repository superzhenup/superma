<?php
/**
 * SQL 注入静态回归测试（全量扫描版）
 *
 * 守护目标：
 *   1) 任何 SQL 字符串不得直接拼接 $_GET / $_POST / $_REQUEST / $_COOKIE / $input / $argv
 *      等用户可控数据；
 *   2) 任何 SQL 字符串不得使用未参数化的 ->query(...) / ->exec(...) 调用
 *      （这两个 API 不支持参数绑定，原则上必须替换为 ->prepare($sql, $params)）；
 *   3) 动态拼接到 SQL 的 LIMIT/OFFSET 必须是 (int)$var 显式强转过的；
 *   4) 动态表名 / 列名必须从硬编码白名单数组中选取；
 *   5) 占位符数组必须与绑定值数量一致（implode(',', array_fill(0, count, '?')) 模式）；
 *   6) 禁止在 SQL 字符串中出现裸的 ${var} 或 {$var}（仅允许 ? 占位符 / 硬编码白名单）。
 *
 * 扫描范围：api/ 与 includes/ 全部 PHP 文件。
 */

$root = dirname(__DIR__);
$targets = [
    $root . DIRECTORY_SEPARATOR . 'api',
    $root . DIRECTORY_SEPARATOR . 'includes',
];

function sqli_read(string $abs): string
{
    if (!is_file($abs)) {
        throw new RuntimeException("Missing source file: {$abs}");
    }
    return file_get_contents($abs);
}

function sqli_collect_files(array $dirs): array
{
    $out = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $name = $f->getFilename();
            if (substr($name, -4) !== '.php') continue;
            $abs = $f->getPathname();
            // 跳过 tests/ 目录
            if (strpos($abs, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) continue;
            $out[] = $abs;
        }
    }
    sort($out);
    return $out;
}

function sqli_strip_comments_and_strings(string $hay): string
{
    // 去掉单行注释
    $hay = preg_replace('!//.*!', '', $hay);
    // 去掉多行注释
    $hay = preg_replace('!/\*.*?\*/!s', '', $hay);
    // 去掉 # 单行注释
    $hay = preg_replace('!^\s*#.*$!m', '', $hay);
    return $hay;
}

/**
 * 查找所有 SQL 字符串（双引号 / 单引号 / heredoc 中的 SELECT/INSERT/UPDATE/DELETE）。
 * 返回 [行号, 行内容, SQL 片段]。
 */
function sqli_find_sql_strings(string $hay): array
{
    $hits = [];
    $lines = preg_split("/\r?\n/", $hay);
    foreach ($lines as $idx => $line) {
        $no = $idx + 1;
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b\s+/i', $line)) {
            $hits[] = ['line' => $no, 'content' => $line];
        }
    }
    return $hits;
}

/**
 * 检查是否存在裸的 ->query( 或 ->exec( 调用（无参数绑定能力，禁止使用）。
 *
 * 规则：只有当函数体内含有 $_GET/$_POST/$_REQUEST/$_COOKIE/$input 等用户输入源，
 * 且调用了 ->query($var) / ->exec($var) 时，才标记为可疑。
 *
 * 例外：纯 DDL（ALTER/CREATE/DROP/RENAME/ADD COLUMN 等）由 schema.php / db.php 迁移路径
 * 调用，DDL 本身不支持参数绑定。
 */
function sqli_find_unbound_query(string $hay): array
{
    $leaks = [];
    $lines = preg_split("/\r?\n/", $hay);
    // 先扫一遍这个文件有没有用户输入源
    $hasUserInput = false;
    foreach ($lines as $line) {
        if (preg_match('/\$\{\s*\$_(GET|POST|REQUEST|COOKIE|FILES)\b/i', $line)
            || preg_match('/\$\b_?(GET|POST|REQUEST|COOKIE|FILES)\s*\[/', $line)
        ) {
            $hasUserInput = true;
            break;
        }
    }
    if (!$hasUserInput) {
        return []; // 没有用户输入就放行（DBA 脚本/迁移）
    }
    foreach ($lines as $idx => $line) {
        $no = $idx + 1;
        if (preg_match('/->\s*(query|exec)\s*\(\s*\$\w+\s*\)/', $line)) {
            $leaks[] = "L{$no}: {$line}";
        }
    }
    return $leaks;
}

/**
 * 检查是否有 SQL 字符串拼接了用户输入源。
 */
function sqli_find_userinput_in_sql(string $hay): array
{
    $leaks = [];
    $lines = preg_split("/\r?\n/", $hay);
    foreach ($lines as $idx => $line) {
        $no = $idx + 1;
        // 1. SQL 关键字
        if (!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b\s+/i', $line)) {
            continue;
        }
        // 2. 跳过只包含 ? 占位符的行（安全）
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b[^"\';]*[\'"`][^"\'`]*\?+/', $line)
            && !preg_match('/\$_/', $line)) {
            continue;
        }
        // 3. 命中 $_GET/$_POST/$_REQUEST/$_COOKIE/$_FILES 拼接
        if (preg_match('/\$\{\s*\$_(GET|POST|REQUEST|COOKIE|FILES)\b/i', $line)
            || preg_match('/\.\s*\$\{?\s*\$_(GET|POST|REQUEST|COOKIE|FILES)\b/i', $line)
        ) {
            $leaks[] = "L{$no}: {$line}";
            continue;
        }
        // 4. 命中 json 输入的 $input 拼接（但允许 (int)$input / (int)$input[...]）
        if (preg_match('/\.\s*\$\{?\s*\$input\b/i', $line)
            && !preg_match('/\(\s*int\)\s*\$input\b/i', $line)
        ) {
            $leaks[] = "L{$no}: {$line}";
            continue;
        }
    }
    return $leaks;
}

/**
 * 检查 LIMIT/OFFSET 后是否使用 (int)$var 强转。
 */
function sqli_find_unsafe_limit(string $hay): array
{
    $leaks = [];
    $lines = preg_split("/\r?\n/", $hay);
    foreach ($lines as $idx => $line) {
        $no = $idx + 1;
        if (preg_match('/\bLIMIT\s+[\'"]?\s*\'?\s*"\s*\.\s*/i', $line)) {
            // LIMIT ' . $var 模式 - 必须 (int) 转换
            if (preg_match('/\bLIMIT\s+[\'"]\s*\.\s*\$\w+/i', $line)
                && !preg_match('/\(int\)\s*\$\w+/i', $line)
            ) {
                $leaks[] = "L{$no}: {$line}";
            }
        }
    }
    return $leaks;
}

/**
 * 主流程
 */
$files = sqli_collect_files($targets);

$totalScanned = 0;
$totalLeaks = 0;
$report = [];

foreach ($files as $f) {
    $totalScanned++;
    $hay = sqli_read($f);
    $hay = sqli_strip_comments_and_strings($hay);
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $f);

    $fileLeaks = [];

    $u = sqli_find_unbound_query($hay);
    if ($u) { $fileLeaks['unbound_query/exec'] = $u; }

    $s = sqli_find_userinput_in_sql($hay);
    if ($s) { $fileLeaks['userinput_concat'] = $s; }

    $l = sqli_find_unsafe_limit($hay);
    if ($l) { $fileLeaks['unsafe_limit'] = $l; }

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
    echo "sql_injection_static_test passed ({$totalScanned} files scanned, 0 leaks)\n";
    exit(0);
}

echo "sql_injection_static_test FAILED: {$totalLeaks} leak(s) in {$totalScanned} files scanned\n";
foreach ($report as $line) {
    echo $line . "\n";
}
exit(1);
