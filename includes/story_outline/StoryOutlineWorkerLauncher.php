<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Detached wakeup with durable diagnostics; only a DB claim proves execution. */
final class StoryOutlineWorkerLauncher
{
    public static function launch(string $taskId): bool
    {
        return self::dispatch($taskId)['dispatched'];
    }

    /** Shared device-neutral spawn for the related writing worker launchers. */
    public static function detachCommand(string $command, ?string $cwd = null, ?string $diagnosticLog = null,
        ?callable $fallback = null): bool
    {
        return self::detach($command, $cwd, $diagnosticLog, $fallback);
    }

    /** @return array{attempted:bool,dispatched:bool,code:string,message:string,time:int,log_file:?string,throttled:bool} */
    public static function dispatch(string $taskId): array
    {
        self::assertTaskId($taskId);
        $base = dirname(__DIR__, 2);
        $dir = self::progressDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return self::result('progress_unwritable');
        $lock = @fopen($dir . '/story-outline-' . $taskId . '.launch', 'c+');
        if (!$lock) return self::result('progress_unwritable');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return self::result('launch_in_progress');
            $previous = json_decode((string)stream_get_contents($lock), true);
            if (is_array($previous) && (int)($previous['time'] ?? 0) > time() - 15) {
                $status = self::safeStatus($previous, $taskId) ?? self::result('launch_in_progress');
                $status['attempted'] = false;
                $status['throttled'] = true;
                return self::inspectWorkerLogs($status, $dir);
            }
            $result = self::result('process_unavailable');
            if (defined('CFG_STORY_OUTLINE_AUTO_LAUNCH') && !CFG_STORY_OUTLINE_AUTO_LAUNCH) {
                $result = self::result('auto_launch_disabled');
            } elseif (function_exists('proc_open') && function_exists('proc_close')) {
                $result = self::startProcess($base, $dir, $taskId);
            }
            rewind($lock);
            if (!ftruncate($lock, 0) || fwrite($lock, json_encode($result, JSON_UNESCAPED_UNICODE)) === false
                || !fflush($lock)) return self::result('progress_unwritable');
            return $result;
        } finally {
            fclose($lock);
        }
    }

    /** Read-only: polling never creates a lock/file or launches a process. */
    public static function readDispatchStatus(string $taskId): ?array
    {
        self::assertTaskId($taskId);
        $fp = @fopen(self::progressDirectory() . '/story-outline-' . $taskId . '.launch', 'rb');
        if ($fp === false) return null;
        try {
            if (!flock($fp, LOCK_SH | LOCK_NB)) return null;
            $stored = json_decode((string)stream_get_contents($fp, 16384), true);
            $status = is_array($stored) ? self::safeStatus($stored, $taskId) : null;
            return $status === null ? null : self::inspectWorkerLogs($status, self::progressDirectory());
        } finally { fclose($fp); }
    }

    private static function progressDirectory(): string
    {
        return rtrim(defined('CFG_PROGRESS_DIR') ? (string)CFG_PROGRESS_DIR
            : dirname(__DIR__, 2) . '/storage/write_progress', '/\\');
    }

    private static function assertTaskId(string $taskId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $taskId) !== 1) throw new InvalidArgumentException('无效的任务 ID');
    }

    private static function result(string $code, bool $attempted = false, ?string $log = null): array
    {
        $messages = [
            'dispatched' => '已尝试启动后台进程，正在等待任务认领。',
            'auto_launch_disabled' => '自动启动后台进程已关闭，需要服务器 CLI 计划任务接手。',
            'process_unavailable' => 'PHP 的 proc_open/proc_close 不可用，无法自动启动后台进程。',
            'progress_unwritable' => '后台进程诊断目录不可写，请检查写作进度目录权限。',
            'launch_in_progress' => '另一个请求正在唤醒此任务，请稍后查看进度。',
            'cli_unavailable' => 'PHP CLI 探测未能启动或执行失败，请检查后台启动日志、CFG_PHP_CLI 与进程权限。',
            'cli_incompatible' => '后台解释器不是 PHP 8.2+ CLI，请检查 CFG_PHP_CLI。',
            'cli_missing_extensions' => 'PHP CLI 缺少 pdo_mysql、curl、mbstring 或 json 扩展，请检查 CLI 的 php.ini。',
            'spawn_failed' => '后台进程启动失败，请检查后台日志和服务器进程权限。',
            'worker_bootstrap_failed' => '后台进程发生致命错误，请检查所列后台日志中的 PHP 配置或启动错误。',
        ];
        if (!isset($messages[$code])) throw new LogicException('Unknown worker dispatch status.');
        return ['attempted' => $attempted, 'dispatched' => $code === 'dispatched',
            'code' => $code, 'message' => $messages[$code], 'time' => time(),
            'log_file' => $log, 'throttled' => false];
    }

    private static function safeStatus(array $stored, string $taskId): ?array
    {
        try { $result = self::result((string)($stored['code'] ?? '')); }
        catch (LogicException) { return null; }
        $result['attempted'] = (bool)($stored['attempted'] ?? false);
        $result['time'] = max(0, (int)($stored['time'] ?? 0));
        $log = $stored['log_file'] ?? null;
        if (is_string($log) && preg_match('/^story-outline-' . $taskId . '-[a-f0-9]{12}\.(?:stderr|stdout|launcher)\.log$/D', $log)) {
            $result['log_file'] = $log;
        }
        return $result;
    }

    private static function inspectWorkerLogs(array $status, string $dir): array
    {
        if ($status['code'] !== 'dispatched' || $status['log_file'] === null) return $status;
        $prefix = preg_replace('/\.(?:stderr|stdout)\.log$/D', '', $status['log_file']);
        foreach (['stderr', 'stdout'] as $stream) {
            $name = $prefix . '.' . $stream . '.log';
            $fp = @fopen($dir . '/' . $name, 'rb');
            if ($fp === false) continue;
            try {
                $size = fstat($fp)['size'] ?? 0;
                if ($size > 16384) fseek($fp, $size - 16384);
                $tail = (string)fread($fp, 16384);
            } finally { fclose($fp); }
            if (preg_match('/(?:^|\n)(?:\[[^\r\n]{0,100}\]\s*)?(?:PHP\s+)?(?:Fatal error:|Parse error:)|(?:^|\n)Outline Harness worker failed \(/i', $tail)) {
                $status['code'] = 'worker_bootstrap_failed';
                $status['message'] = self::result('worker_bootstrap_failed')['message'];
                $status['log_file'] = $name;
                // Preserve the historical dispatch fact; a shell did run even
                // though PHP then failed. A DB claim is reported separately.
                return $status;
            }
        }
        return $status;
    }

    private static function startProcess(string $base, string $dir, string $taskId): array
    {
        $prefix = 'story-outline-' . $taskId . '-' . bin2hex(random_bytes(6));
        $logs = $dir . '/' . $prefix;
        foreach (['stderr', 'stdout', 'launcher'] as $stream) {
            $fp = @fopen($logs . '.' . $stream . '.log', 'xb');
            if ($fp === false) return self::result('progress_unwritable');
            fclose($fp);
            if (function_exists('chmod')) @chmod($logs . '.' . $stream . '.log', 0640);
        }
        try {
            $php = self::resolvePhpCli();
            $probe = self::probeCli($php, $logs);
            if ($probe['code'] !== null) {
                file_put_contents($logs . '.launcher.log', $probe['diagnostic']);
                return self::result($probe['code'], true, $prefix . '.launcher.log');
            }
            $command = self::command($php, $base . '/bin/harness_worker.php', $taskId, $logs);
            $started = self::detach($command, $base, $logs . '.launcher.log');
            return self::result($started ? 'dispatched' : 'spawn_failed', true,
                $prefix . ($started ? '.stderr.log' : '.launcher.log'));
        } catch (Throwable $error) {
            file_put_contents($logs . '.launcher.log', get_class($error) . ': ' . $error->getMessage());
            return self::result('spawn_failed', true, $prefix . '.launcher.log');
        }
    }

    /** Probe before detaching, since a shell's successful background fork does not prove PHP exists. */
    private static function probeCli(string $php, string $logs): array
    {
        if (trim($php) === '' || preg_match('/[\x00-\x1f\x7f]/', $php)) {
            return ['code' => 'cli_unavailable', 'diagnostic' => 'Invalid CLI executable setting.'];
        }
        $code = 'echo "STORY_CLI_PROBE:" . json_encode(["sapi"=>PHP_SAPI,"version"=>PHP_VERSION_ID,"extensions"=>array_map("extension_loaded",["pdo_mysql","curl","mbstring","json"])]);';
        // Windows PHP 8.2 cannot reliably make anonymous pipes nonblocking.
        // Startup warnings can fill stderr before stdout closes, deadlocking a
        // sequential pipe reader. Files keep both streams bounded at read time
        // and allow the execution deadline to be observed independently.
        $stdoutFile = $logs . '.probe.stdout.log';
        $stderrFile = $logs . '.probe.stderr.log';
        // PHP opens descriptor files through its stream wrapper. A site-only
        // open_basedir can reject /dev/null (or NUL) before PHP CLI even starts.
        // Closing the parent's pipe immediately gives the child a safe EOF.
        $pipes = []; $warning = '';
        $process = self::openProcess([$php, '-d', 'display_errors=stderr', '-r', $code], [0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, null, ['bypass_shell' => true], $warning);
        foreach ($pipes as $pipe) fclose($pipe);
        $context = 'CLI: ' . $php . "\nWeb SAPI: " . PHP_SAPI
            . "\nopen_basedir: " . (ini_get('open_basedir') ?: '(none)')
            . "\nproc_open: " . ($warning !== '' ? $warning : '(no warning)') . "\n";
        if ($process === false) return ['code' => 'cli_unavailable',
            'diagnostic' => "Cannot start PHP CLI probe.\n" . $context];
        $exit = -1; $deadline = microtime(true) + 3;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($status['running']) proc_terminate($process);
        $closed = proc_close($process);
        $stdout = (string)file_get_contents($stdoutFile, false, null, 0, 32768);
        $stderr = (string)file_get_contents($stderrFile, false, null, 0, 32768);
        if ($exit < 0) $exit = $closed;
        $info = self::decodeProbeOutput($stdout);
        $diagnostic = mb_substr($context . 'Exit: ' . $exit . "\nTimed out: "
            . ($status['running'] ? 'yes' : 'no') . "\n" . $stderr . "\n" . $stdout, 0, 16000);
        if ($exit !== 0 || !is_array($info)) return ['code' => 'cli_unavailable', 'diagnostic' => $diagnostic];
        if (($info['sapi'] ?? '') !== 'cli' || (int)($info['version'] ?? 0) < 80200) {
            return ['code' => 'cli_incompatible', 'diagnostic' => $diagnostic];
        }
        if (($info['extensions'] ?? []) !== [true, true, true, true]) {
            return ['code' => 'cli_missing_extensions', 'diagnostic' => $diagnostic];
        }
        return ['code' => null, 'diagnostic' => ''];
    }

    private static function decodeProbeOutput(string $stdout): ?array
    {
        $decoded = preg_match('/STORY_CLI_PROBE:(\{[^\r\n]*\})/', $stdout, $match)
            ? json_decode($match[1], true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    public static function resolvePhpCli(): string
    {
        if (defined('CFG_PHP_CLI') && trim((string)CFG_PHP_CLI) !== '') {
            return (string)CFG_PHP_CLI;
        }
        return self::cliForBinary(PHP_BINARY, PHP_OS_FAMILY);
    }

    public static function cliForBinary(string $binary, string $os): string
    {
        if ($os === 'Windows') {
            return preg_replace('/php(?:-cgi|-win)?\.exe$/i', 'php.exe', $binary) ?: 'php.exe';
        }
        // Prefer the same PHP version as FPM. open_basedir may prevent is_file
        // on a system executable, so derive standard FPM paths without probing.
        if (preg_match('~/sbin/php-fpm(?:[0-9.]*)$~', $binary)) {
            return preg_replace('~/sbin/php-fpm([0-9.]*)$~', '/bin/php$1', $binary);
        }
        if (preg_match('~/php-fpm([0-9.]*)$~', $binary)) {
            return preg_replace('~/php-fpm([0-9.]*)$~', '/php$1', $binary);
        }
        if (preg_match('~/php-cgi([0-9.]*)$~', $binary)) {
            return preg_replace('~/php-cgi([0-9.]*)$~', '/php$1', $binary);
        }
        return $binary !== '' ? $binary : 'php';
    }

    private static function command(string $php, string $script, string $taskId, ?string $logs = null): string
    {
        self::assertTaskId($taskId);
        if (preg_match('/[\x00-\x1f\x7f]/', $php . $script . ($logs ?? ''))) throw new InvalidArgumentException('Invalid CLI path.');
        if (PHP_OS_FAMILY === 'Windows') {
            $quote = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
            // Encoded PowerShell avoids cmd expansion of workspace characters.
            // Start-Process detaches, hides the window, and returns immediately.
            $arguments = '"' . $script . '" --queue=story-outline --task=' . $taskId;
            $ps = 'Start-Process -WindowStyle Hidden -FilePath ' . $quote($php)
                . ' -ArgumentList ' . $quote($arguments) . ' -ErrorAction Stop';
            if ($logs !== null) $ps .= ' -RedirectStandardOutput ' . $quote($logs . '.stdout.log')
                . ' -RedirectStandardError ' . $quote($logs . '.stderr.log');
            return 'powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand '
                . base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        }
        return escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --queue=story-outline --task=' . escapeshellarg($taskId)
            . ' </dev/null >' . escapeshellarg($logs === null ? '/dev/null' : $logs . '.stdout.log')
            . ' 2>' . escapeshellarg($logs === null ? '/dev/null' : $logs . '.stderr.log') . ' &';
    }

    private static function detach(string $command, ?string $cwd = null, ?string $diagnosticLog = null,
        ?callable $fallback = null): bool
    {
        if (function_exists('proc_open') && function_exists('proc_close')) {
            // Keep PHP-level descriptor paths inside the permitted progress
            // directory. The shell command redirects the worker's own streams.
            $output = $diagnosticLog !== null ? @fopen($diagnosticLog, 'ab') : @tmpfile();
            if ($output === false) return $fallback !== null ? $fallback($command) : false;
            try {
                $pipes = []; $warning = '';
                $process = self::openProcess($command, [0 => ['pipe', 'r'], 1 => $output, 2 => $output],
                    $pipes, $cwd, [], $warning);
                foreach ($pipes as $pipe) fclose($pipe);
                if ($warning !== '') fwrite($output, "proc_open: " . $warning . "\n");
                if ($process !== false) return proc_close($process) === 0;
            } finally { fclose($output); }
        }
        // Related launchers retain their pre-existing optional exec fallback.
        // Story dispatch passes none and still needs only proc_open/proc_close.
        return $fallback !== null ? $fallback($command) : false;
    }

    /** Capture process-creation errors privately without leaking PHP warnings to HTTP. */
    private static function openProcess(array|string $command, array $descriptors, array &$pipes,
        ?string $cwd, array $options, string &$warning)
    {
        $errors = [];
        set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
            $errors[] = $message;
            return true;
        });
        try { return proc_open($command, $descriptors, $pipes, $cwd, null, $options); }
        finally {
            restore_error_handler();
            $warning = mb_substr(implode("\n", $errors), 0, 8000);
        }
    }
}
