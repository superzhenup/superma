<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/** Shared identity contract for every legacy writing_task shadow consumer. */
final class LegacyWritingTaskRunIdentity
{
    public const IDEMPOTENCY_PREFIX = 'legacy:writing_task:';
    public const QUEUE = 'writing_shadow';
    public const RUN_TYPE = 'chapter.generate';
    public const WORKFLOW_NAME = 'legacy.chapter_write';
    public const WORKFLOW_VERSION = 'v1';
    public const AUTHORITY_MODE = 'shadow';

    public static function assertTaskId(string $taskId): void
    {
        if (preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $taskId) !== 1) {
            throw new InvalidArgumentException('Legacy writing task_id is invalid.');
        }
    }

    public static function idempotencyKey(string $taskId): string
    {
        self::assertTaskId($taskId);
        return self::IDEMPOTENCY_PREFIX . $taskId;
    }

    public static function runUid(string $taskId): string
    {
        self::assertTaskId($taskId);
        return substr(hash('sha256', 'writing-task-run:' . $taskId), 0, 32);
    }

    public static function inputHash(string $taskId): string
    {
        self::assertTaskId($taskId);
        return hash('sha256', 'writing-task-input:' . $taskId);
    }

    private function __construct()
    {
    }
}
