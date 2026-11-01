<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/story_outline/StoryOutlineWorkerLauncher.php';

/** Starts a detached CLI worker; the request store remains the execution authority. */
final class ChapterOutlineWorkerLauncher
{
    public static function launch(int $novelId, string $requestId): bool
    {
        self::assertIdentity($novelId, $requestId);
        try {
            return self::detach(self::command(
                self::resolvePhpCli(),
                dirname(__DIR__) . '/bin/chapter_outline_worker.php',
                $novelId,
                $requestId
            ));
        } catch (Throwable $e) {
            // Unavailable process APIs or an invalid CLI setting must never run
            // generation inside the HTTP request as a fallback.
            return false;
        }
    }

    public static function resolvePhpCli(): string
    {
        return StoryOutlineWorkerLauncher::resolvePhpCli();
    }

    private static function assertIdentity(int $novelId, string $requestId): void
    {
        if ($novelId < 1 || !preg_match('/^[a-f0-9]{12}$/D', $requestId)) {
            throw new InvalidArgumentException('无效的小说或细纲请求 ID');
        }
    }

    private static function command(
        string $php,
        string $script,
        int $novelId,
        string $requestId,
        ?string $os = null
    ): string {
        self::assertIdentity($novelId, $requestId);
        foreach ([$php, $script] as $path) {
            if (trim($path) === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) {
                throw new InvalidArgumentException('无效的 CLI 路径');
            }
        }
        if (($os ?? PHP_OS_FAMILY) === 'Windows') {
            $quote = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
            // Encoded PowerShell keeps path characters out of cmd expansion.
            // ArgumentList is one Windows command line, so quote for the child
            // argv parser as well as for the surrounding PowerShell literal.
            $arguments = self::quoteWindowsArgument($script) . ' ' . $novelId . ' ' . $requestId;
            $ps = "\$ProgressPreference = 'SilentlyContinue'; Start-Process -WindowStyle Hidden -FilePath " . $quote($php)
                . ' -ArgumentList ' . $quote($arguments) . ' -ErrorAction Stop';
            return 'powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand '
                . base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        }
        return escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg((string)$novelId) . ' ' . escapeshellarg($requestId)
            . ' </dev/null >/dev/null 2>&1 &';
    }

    /** Windows argv quoting, including quotes and backslashes before quotes/end. */
    private static function quoteWindowsArgument(string $value): string
    {
        $quoted = '"';
        $slashes = 0;
        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            $character = $value[$index];
            if ($character === '\\') {
                ++$slashes;
                continue;
            }
            $quoted .= str_repeat('\\', $character === '"' ? $slashes * 2 + 1 : $slashes) . $character;
            $slashes = 0;
        }
        return $quoted . str_repeat('\\', $slashes * 2) . '"';
    }

    private static function detach(string $command): bool
    {
        return StoryOutlineWorkerLauncher::detachCommand($command, null, null, static function (string $command): bool {
            if (!function_exists('exec')) return false;
            $output = [];
            $exitCode = 1;
            @exec($command . (PHP_OS_FAMILY === 'Windows' ? ' 2>&1' : ''), $output, $exitCode);
            return $exitCode === 0;
        });
    }
}
