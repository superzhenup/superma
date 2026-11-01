<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__, 2) . '/tasks/WritingTaskRepository.php';

/**
 * Compatibility boundary for the legacy streaming progress file.
 *
 * On the legacy/shadow path, writing_tasks is the durable lifecycle source.
 * Losing the cache/file projection must therefore degrade to a database payload
 * instead of turning an authenticated task into "not found" or fabricating
 * streamed content. Authority-cohort lifecycle comes from generation_runs/Events
 * before this adapter is reached. Existing files are deliberately left to
 * write_poll.php's lock, HMAC and lease-fence checks.
 */
final class LegacyProgressFileAdapter
{
    /**
     * @param array<string,mixed> $durableTask
     * @return array<string,mixed>|null null means the file projection exists
     *                                      and still requires full validation.
     */
    public static function recoverMissingPollProjection(
        string $progressFile,
        array $durableTask
    ): ?array {
        if ($progressFile === '') {
            throw new InvalidArgumentException('Progress file path cannot be empty.');
        }
        if (is_file($progressFile)) {
            return null;
        }

        $payload = WritingTaskRepository::toPollPayload($durableTask);
        $payload['progress_source'] = 'writing_tasks';
        $payload['progress_file_available'] = false;
        return $payload;
    }
}
