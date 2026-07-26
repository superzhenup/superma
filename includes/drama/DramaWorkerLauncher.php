<?php

defined('APP_LOADED') or die('Direct access denied.');

/**
 * 漫剧 CLI worker 启动器（复用 write_start.php 的 Windows 后台启动模式）。
 */
final class DramaWorkerLauncher
{
    /** 实测 exec/popen/proc_open 可用性（不看 disable_functions）。 */
    public static function execAvailable(): bool
    {
        static $result = null;
        if ($result !== null) return $result;
        $result = false;
        foreach (['exec', 'popen', 'proc_open'] as $fn) {
            if (!function_exists($fn)) continue;
            if ($fn === 'exec') {
                $out = [];
                $code = 1;
                @exec(PHP_OS_FAMILY === 'Windows' ? 'echo 1' : 'echo 1 2>/dev/null', $out, $code);
                if ($code === 0) { $result = true; break; }
            } else {
                $result = true;
                break;
            }
        }
        return $result;
    }

    /** 后台启动 bin/drama_worker.php；成功返回 true。 */
    public static function launch(int $maxTasks = 50): bool
    {
        if (!self::execAvailable()) return false;

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $phpBin = self::resolvePhpCli();
        if ($phpBin === null) return false;

        $script = $base . '/bin/drama_worker.php';
        if (!is_file($script)) return false;

        $logFile = $base . '/storage/drama_worker.log';
        $maxTasks = max(1, min(200, $maxTasks));

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B "" "' . $phpBin . '" ' . escapeshellarg($script)
                . ' --max=' . $maxTasks
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
        } else {
            $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
                . ' --max=' . $maxTasks
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        }

        if (function_exists('proc_open')) {
            $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($proc)) {
                foreach ($pipes as $p) @fclose($p);
                @proc_close($proc);
                return true;
            }
        }
        if (function_exists('popen')) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                return true;
            }
        }
        if (function_exists('exec')) {
            @exec($cmd);
            return true;
        }
        return false;
    }

    /**
     * 解析可用的 PHP CLI 二进制路径。
     *
     * 修复（2026-07-26）：原代码直接用 PHP_BINARY，在 PHP-FPM 环境下返回 php-fpm
     * 二进制（如 /www/server/php/84/sbin/php-fpm），php-fpm 不接受脚本参数，
     * 导致 worker 启动时输出 usage 信息到日志后退出，任务永远卡在 pending。
     *
     * 实现对齐 write_start.php 的健壮版本（已验证可工作）：
     *   - which php 优先,用 exec 实际执行验证候选可运行(不受 open_basedir 限制)
     *   - 路径模式盲替换兜底(/sbin/php-fpm → /bin/php),不依赖 file_exists
     *   - 宝塔 open_basedir 会阻止 file_exists 检查非项目路径,故避免依赖它
     *
     * Windows 下保持原有替换逻辑（php-cgi.exe/php-win.exe → php.exe）。
     */
    private static function resolvePhpCli(): ?string
    {
        $phpBin = PHP_BINARY ?: 'php';

        // Windows：原有逻辑
        if (PHP_OS_FAMILY === 'Windows') {
            if (preg_match('#php(-cgi|-win)?\.exe$#i', $phpBin)) {
                $candidate = preg_replace('#php(-cgi|-win)?\.exe$#i', 'php.exe', $phpBin);
                if ($candidate && is_file($candidate)) return $candidate;
            }
            return $phpBin;
        }

        // Linux：若 PHP_BINARY 已经是 php CLI(非 php-fpm/php-cgi),直接用
        $basename = basename($phpBin);
        if ($basename === 'php' || preg_match('#^php\d+(\.\d+)?$#', $basename)) {
            return $phpBin;
        }

        // PHP-FPM 不能执行 CLI 脚本,需替换为 php CLI 二进制
        // 宝塔路径示例：/www/server/php/84/sbin/php-fpm → /www/server/php/84/bin/php
        // 注意：宝塔 open_basedir 限制会阻止 file_exists() 检查非项目路径,因此用 exec 绕过
        if (preg_match('#/php-fpm\d*$#', $phpBin)) {
            $found = false;
            // exec 不受 open_basedir 限制,优先用 which 查找；但宝塔默认禁用 exec,
            // 调用被禁用函数会致命错误,故须 function_exists('exec') 守卫。
            if (function_exists('exec')) {
                $whichOut = [];
                $whichCode = 1;
                @exec('which php 2>/dev/null', $whichOut, $whichCode);
                if ($whichCode === 0 && !empty($whichOut[0])) {
                    $candidate = trim($whichOut[0]);
                    // 确认候选路径真的可以执行 PHP 脚本(不被 open_basedir 影响)
                    @exec(escapeshellarg($candidate) . ' -r "echo 1;" 2>/dev/null', $rTest, $rCode);
                    if ($rCode === 0) {
                        return $candidate;
                    }
                }
            }
            // 兜底:宝塔路径模式盲替换(路径模式非常可靠,不能因 open_basedir 而卡死)
            // 对齐 write_start.php 第 244 行的实现
            return str_replace('/sbin/php-fpm', '/bin/php', $phpBin);
        }

        // 其他情况(php-cgi 等):尝试 which php
        if (function_exists('exec')) {
            $whichOut = [];
            $whichCode = 1;
            @exec('which php 2>/dev/null', $whichOut, $whichCode);
            if ($whichCode === 0 && !empty($whichOut[0])) {
                return trim($whichOut[0]);
            }
        }

        return $phpBin; // 最后兜底返回原值,由调用方决定是否使用
    }
}
