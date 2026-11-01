<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

final class ImmutableEvalReportStore
{
    /** @param array<string,mixed> $report */
    public static function persist(string $directory, array $report): string
    {
        $reportId = HarnessPersistenceValue::uid(
            $report['report_id'] ?? null,
            'eval.report_id'
        );
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Eval reports directory.');
        }
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $reportId . '.json';
        $bytes = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (is_file($path)) {
            self::assertSameBytes($path, $bytes);
            return $path;
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Unable to write complete Eval report bytes.');
            }
            // A same-directory hard link publishes the complete inode in one
            // no-clobber operation. Unlike rename, this is idempotent on Windows
            // and cannot overwrite a competing immutable report.
            if (!@link($temporary, $path)) {
                if (is_file($path)) {
                    self::assertSameBytes($path, $bytes);
                    return $path;
                }
                throw new RuntimeException('Unable to publish Eval report atomically.');
            }
            return $path;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function assertSameBytes(string $path, string $expected): void
    {
        $existing = file_get_contents($path);
        if (!is_string($existing) || !hash_equals(hash('sha256', $expected), hash('sha256', $existing))) {
            throw new HarnessIdempotencyConflictException(
                'Eval report ID already exists with different immutable bytes.'
            );
        }
    }

    private function __construct()
    {
    }
}
