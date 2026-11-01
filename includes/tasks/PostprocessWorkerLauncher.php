<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/story_outline/StoryOutlineWorkerLauncher.php';

/**
 * Best-effort detached wakeup of bin/postprocess_worker.php so queued/retry
 * post-processing jobs are drained without an external cron/supervisor.
 * The database (claim lease + fence) remains the authority; this only spawns.
 */
final class PostprocessWorkerLauncher
{
    private const THROTTLE_SECONDS = 15;
    private const MAX_JOBS_PER_RUN = 20;

    /** $force skips the throttle for explicit user actions (retry/manual save). */
    public static function launch(bool $force = false): bool
    {
        if (defined('CFG_POSTPROCESS_AUTO_LAUNCH') && !CFG_POSTPROCESS_AUTO_LAUNCH) return false;
        $base = dirname(__DIR__, 2);
        $dir = defined('CFG_PROGRESS_DIR') ? CFG_PROGRESS_DIR : $base . '/storage/write_progress';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return false;
        $lock = @fopen($dir . '/postprocess-worker.launch', 'c+');
        if (!$lock) return false;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return false;
            $lastLaunch = (int)stream_get_contents($lock);
            if (!$force && $lastLaunch > time() - self::THROTTLE_SECONDS) return false;
            $command = self::command(StoryOutlineWorkerLauncher::resolvePhpCli(), $base . '/bin/postprocess_worker.php');
            if (!self::detach($command)) return false;
            rewind($lock);
            ftruncate($lock, 0);
            fwrite($lock, (string)time());
            fflush($lock);
            return true;
        } catch (Throwable $e) {
            error_log('[PostprocessWorkerLauncher] launch failed: ' . $e->getMessage());
            return false;
        } finally {
            fclose($lock);
        }
    }

    private static function command(string $php, string $script): string
    {
        $args = '--max=' . self::MAX_JOBS_PER_RUN;
        if (PHP_OS_FAMILY === 'Windows') {
            $quote = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
            $ps = 'Start-Process -WindowStyle Hidden -FilePath ' . $quote($php)
                . ' -ArgumentList ' . $quote('"' . $script . '" ' . $args) . ' -ErrorAction Stop';
            return 'powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand '
                . base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        }
        return escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $args
            . ' </dev/null >/dev/null 2>&1 &';
    }

    private static function detach(string $command): bool
    {
        return StoryOutlineWorkerLauncher::detachCommand($command, null, null, static function (string $command): bool {
            if (!function_exists('exec')) return false;
            $lines = [];
            $code = 1;
            @exec($command, $lines, $code);
            return $code === 0;
        });
    }
}
