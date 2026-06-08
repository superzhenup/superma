<?php
/**
 * 跨平台 / disable_functions 兼容性静态回归测试
 *
 * 背景：宝塔面板（Windows 与 Linux 同样）默认 disable_functions 会禁用
 *   exec / shell_exec / system / passthru / popen，仅保留 proc_open。
 *   在 PHP 中“被禁用的函数”等同“未定义函数”——调用它会抛**致命 Error**，
 *   且 `@` 抑制符**无法**抑制这种致命错误。因此任何对上述函数的“裸调用”
 *   都必须先经 `function_exists()`（或等价的 $execOk/$popenOk/$procOpenOk 门控）守卫。
 *
 * 同时守护异步写作后台启动的“非阻塞”要求：
 *   Windows 下若直接 proc_open("php worker ...") 再 proc_close()，proc_close
 *   会阻塞到 worker 写完整章（数分钟），异步设计失效、请求挂死。必须用
 *   `start /B` 启动分离进程，使外层命令瞬间返回。
 *
 * 守护目标：
 *   1) api/write_start.php Windows 启动使用 start /B（非阻塞），且 proc_open 调用
 *      使用含 start /B 的命令变量，而非旧的直接 worker 命令（阻塞写法）。
 *   2) api/write_start.php 在 exec 被禁用时仍有 proc_open 后台启动兜底（Windows 与 Linux）。
 *   3) Windows tasklist 进程探测处于 function_exists('exec') 守卫内。
 *   4) 目标文件中每个对 exec/shell_exec/system/passthru/popen 的裸调用，
 *      其所在文件都存在对应的 function_exists('<fn>') 守卫。
 */

$root = dirname(__DIR__);

function wct_read(string $abs): string
{
    if (!is_file($abs)) {
        throw new RuntimeException("Missing source file: {$abs}");
    }
    return file_get_contents($abs);
}
function wct_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "windows_compat_static_test FAILED: {$msg}\n";
        exit(1);
    }
}

/**
 * 统计某文件中对指定函数的“裸调用”行号（排除注释行、PDO 方法 ->exec()/::exec()、
 * 字符串字面量内的同名词、function_exists('fn') 自身）。
 *
 * @return int[] 命中行号（1-based）
 */
function wct_bareword_calls(string $src, string $fn): array
{
    $hits  = [];
    $lines = preg_split('/\r?\n/', $src);
    // 不可前置：单词字符 / -> / :: / 引号；避免匹配 ->exec( 、::exec( 、'exec' 、"exec"
    $re = '/(?<![\w>:\'"])' . preg_quote($fn, '/') . '\s*\(/';
    foreach ($lines as $i => $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*')
            || str_starts_with($t, '#') || str_starts_with($t, '/*')) {
            continue; // 跳过注释
        }
        // 去掉本行的 function_exists('fn') 片段，避免把守卫误判成调用
        $scan = preg_replace("/function_exists\\(\\s*'" . preg_quote($fn, '/') . "'\\s*\\)/", '', $line);
        if (preg_match($re, $scan)) {
            $hits[] = $i + 1;
        }
    }
    return $hits;
}

$riskyFns = ['exec', 'shell_exec', 'system', 'passthru', 'popen'];

// ---- 目标 1/2/3：api/write_start.php ----
$wsPath = $root . '/api/write_start.php';
$ws = wct_read($wsPath);

wct_assert(str_contains($ws, 'start /B'),
    'write_start.php 缺少 start /B 非阻塞后台启动（proc_close 会阻塞到整章写完）');

// Windows Web 请求下 PHP_BINARY 多为 php-cgi.exe（FastCGI SAPI），不能跑 CLI worker
// （PHP_SAPI=cgi-fcgi 被 403 硬校验拒绝、$argv 为 null）。必须转成同目录 php.exe。
wct_assert(preg_match("/PHP_OS_FAMILY === 'Windows'\s*&&\s*preg_match\('#php-cgi/", $ws) === 1,
    'write_start.php 缺少 Windows php-cgi.exe → php.exe（CLI）转换');

wct_assert(str_contains($ws, 'proc_open($winCmd'),
    'write_start.php Windows 分支的 proc_open 应使用含 start /B 的 $winCmd 命令');

// 旧的阻塞写法特征：proc_open 调用同一语句里直接拼接 $phpBin（"\"{$phpBin}\" {$workerScript}..."）。
// 修复后 proc_open 只接收 $winCmd / '/bin/sh '.$launchCmd，命令里不再直接出现 $phpBin。
wct_assert(!preg_match('/proc_open\s*\([^;\n]*\$phpBin/', $ws),
    'write_start.php 仍存在“直接 proc_open 启动 worker”的阻塞旧写法（命令含 $phpBin）');

// Linux exec 被禁用时的 proc_open 后台兜底
wct_assert(str_contains($ws, "proc_open('/bin/sh '"),
    'write_start.php 缺少 Linux 下 exec 被禁用时的 proc_open 后台启动兜底');

// tasklist 探测必须在 function_exists('exec') 守卫内（取其前 300 字符上下文核验）
$tpos = strpos($ws, 'tasklist');
if ($tpos !== false) {
    $before = substr($ws, max(0, $tpos - 300), 300);
    wct_assert(str_contains($before, "function_exists('exec')"),
        'write_start.php 的 tasklist 进程探测未置于 function_exists(\'exec\') 守卫内');
}

// ---- 目标 4：裸调用守卫（write_start.php 与 WorkParser.php）----
$targets = [
    'api/write_start.php'             => $ws,
    'includes/author/WorkParser.php'  => wct_read($root . '/includes/author/WorkParser.php'),
];

$problems = [];
foreach ($targets as $rel => $src) {
    foreach ($riskyFns as $fn) {
        $calls = wct_bareword_calls($src, $fn);
        if (empty($calls)) {
            continue;
        }
        if (!str_contains($src, "function_exists('{$fn}')")) {
            $problems[] = "{$rel}: 调用 {$fn}()（行 " . implode(',', $calls)
                        . "）但文件内无 function_exists('{$fn}') 守卫";
        }
    }
}

// WorkParser.php 的 shell_exec 必须有 function_exists 守卫（显式断言，便于定位）
$wp = $targets['includes/author/WorkParser.php'];
wct_assert(str_contains($wp, "function_exists('shell_exec')"),
    'WorkParser.php 的 shell_exec 未做 function_exists(\'shell_exec\') 守卫');

if (!empty($problems)) {
    echo "windows_compat_static_test FAILED:\n";
    foreach ($problems as $p) {
        echo "  - {$p}\n";
    }
    exit(1);
}

echo "windows_compat_static_test passed (write_start.php 非阻塞分离启动 + exec/popen/shell_exec 全部 function_exists 守卫)\n";

// ============================================================
// 新增：跨平台兼容性修复回归测试
// ============================================================

// ---- P0 #2: Cache::set() rename 兜底含 copy + unlink ----
$cache = wct_read($root . '/includes/cache.php');
wct_assert(str_contains($cache, '@copy($tmp, $file)'),
    'cache.php 缺少 Windows NTFS rename 失败时的 copy 兜底');
wct_assert(preg_match('/@copy\(\$tmp, \$file\).*@unlink\(\$tmp\)/s', $cache),
    'cache.php copy 兜底后未清理临时文件');

// ---- P0 #4: CFG_PROGRESS_DIR 使用项目内路径而非 sys_get_temp_dir ----
$cc = wct_read($root . '/includes/config_constants.php');
wct_assert(!str_contains($cc, "sys_get_temp_dir() . '/novel_write_progress'"),
    'config_constants.php 仍使用 sys_get_temp_dir 临时路径（Windows 下可能含中文/空格）');
wct_assert(str_contains($cc, "storage/write_progress"),
    'config_constants.php 缺少项目内 storage/write_progress 路径定义');

// ---- P1 #6: posix_getpwuid 回退使用 USERNAME 环境变量 ----
$inst = wct_read($root . '/install.php');
wct_assert(str_contains($inst, "getenv('USERNAME')"),
    'install.php posix_getpwuid 回退未尝试 Windows USERNAME 环境变量');

// ---- P1 #8: CURLOPT_TCP_KEEPALIVE 使用 defined() 守卫 ----
$ai = wct_read($root . '/includes/ai.php');
wct_assert(str_contains($ai, "defined('CURLOPT_TCP_KEEPALIVE')"),
    'ai.php CURLOPT_TCP_KEEPALIVE 未做 defined() 守卫（PHP < 8.2 无此常量）');

// ---- P1 #5: install.php 权限提示区分 Windows/Linux ----
wct_assert(str_contains($inst, "PHP_OS_FAMILY === 'Windows'") && str_contains($inst, '右键目录'),
    'install.php 权限错误提示未区分 Windows（应提示右键目录→属性→安全）');

// ---- P3 #14: WorkParser.php 使用 PHP_OS_FAMILY 替代 PHP_OS ----
wct_assert(str_contains($wp, "PHP_OS_FAMILY !== 'Windows'"),
    'WorkParser.php 应使用 PHP_OS_FAMILY 替代 stripos(PHP_OS, "WIN")');

echo "windows_compat_static_test passed (all cross-platform regression checks)\n";
