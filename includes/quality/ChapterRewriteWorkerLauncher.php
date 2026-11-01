<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/story_outline/StoryOutlineWorkerLauncher.php';

/** Detached generation and post-adoption memory work, never a long HTTP fallback. */
final class ChapterRewriteWorkerLauncher
{
    public static function launch(int $chapterId, string $taskId, string $phase = 'generate'): bool
    {
        if ($chapterId < 1 || preg_match('/^[a-f0-9]{32}$/D', $taskId) !== 1
            || !in_array($phase, ['generate', 'finalize'], true)) {
            throw new InvalidArgumentException('Invalid chapter rewrite worker identity.');
        }
        $php = StoryOutlineWorkerLauncher::resolvePhpCli();
        $script = dirname(__DIR__, 2) . '/bin/chapter_rewrite_worker.php';
        if (trim($php) === '' || preg_match('/[\x00-\x1f\x7f]/', $php . $script)) return false;
        if (PHP_OS_FAMILY === 'Windows') {
            $literal = static fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
            // The application path is a real filename (Windows excludes literal
            // quotes); double trailing backslashes for the child argv parser.
            $arg = '"' . preg_replace('/(\\\\+)$/', '$1$1', $script) . '" ' . $chapterId . ' ' . $taskId . ' ' . $phase;
            $ps = "\$ProgressPreference = 'SilentlyContinue'; Start-Process -WindowStyle Hidden -FilePath "
                . $literal($php) . ' -ArgumentList ' . $literal($arg) . ' -ErrorAction Stop';
            $command = 'powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand '
                . base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        } else {
            $command = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' '
                . escapeshellarg((string)$chapterId) . ' ' . escapeshellarg($taskId) . ' ' . escapeshellarg($phase)
                . ' </dev/null >/dev/null 2>&1 &';
        }
        try {
            return StoryOutlineWorkerLauncher::detachCommand($command, null, null, static function (string $command): bool {
                if (!function_exists('exec')) return false;
                $output = []; $code = 1;
                @exec($command . (PHP_OS_FAMILY === 'Windows' ? ' 2>&1' : ''), $output, $code);
                return $code === 0;
            });
        } catch (Throwable) { return false; }
    }
}
