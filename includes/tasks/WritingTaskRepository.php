<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';

final class WritingTaskRepository
{
    private const ACTIVE_STATES = [
        'queued', 'preparing', 'generating', 'validating', 'content_saved', 'postprocessing',
    ];

    public static function createQueued(
        string $taskId,
        int $userId,
        int $novelId,
        ?int $chapterId = null,
        int $leaseSeconds = 120
    ): void {
        self::assertTaskId($taskId);
        if ($userId <= 0 || $novelId <= 0) {
            throw new InvalidArgumentException('Invalid writing task owner.');
        }

        DB::insert('writing_tasks', [
            'task_id'          => $taskId,
            'user_id'          => $userId,
            'novel_id'         => $novelId,
            'chapter_id'       => $chapterId ?: null,
            'state'            => 'queued',
            'progress'         => 0,
            'lease_expires_at' => date('Y-m-d H:i:s', time() + max(30, $leaseSeconds)),
        ]);
    }

    public static function findByTaskId(string $taskId): ?array
    {
        self::assertTaskId($taskId);
        $row = DB::fetch('SELECT * FROM writing_tasks WHERE task_id=? LIMIT 1', [$taskId]);
        return is_array($row) ? $row : null;
    }

    public static function findActiveForNovel(int $novelId): ?array
    {
        if ($novelId <= 0) return null;

        // 审计修复（2026-07-19 M1-4）：租约过期判定改用与写入端一致的 PHP date() 时间源，
        // 避免与 MySQL NOW() 时区不一致时租约窗口偏移（健康任务被误杀或僵死任务永不超时）。
        $now = date('Y-m-d H:i:s');
        DB::execute(
            "UPDATE writing_tasks
             SET state='failed', error_code='lease_expired',
                 error_message='Worker lease expired before reaching a terminal state',
                 completed_at=?, lease_owner=NULL, lease_expires_at=NULL
             WHERE novel_id=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
               AND lease_expires_at IS NOT NULL AND lease_expires_at < ?",
            [$now, $novelId, $now]
        );

        $row = DB::fetch(
            "SELECT * FROM writing_tasks
             WHERE novel_id=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             ORDER BY id DESC LIMIT 1",
            [$novelId]
        );
        return is_array($row) ? $row : null;
    }

    public static function markRunning(
        string $taskId,
        ?int $chapterId,
        string $leaseOwner,
        int $leaseSeconds = 180
    ): bool {
        self::assertTaskId($taskId);
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state='preparing', chapter_id=COALESCE(?, chapter_id),
                 attempt=attempt+1, lease_owner=?, lease_expires_at=?,
                 started_at=COALESCE(started_at, NOW()), error_code=NULL, error_message=NULL
             WHERE task_id=? AND state IN ('queued','preparing') AND cancel_requested=0",
            [
                $chapterId ?: null,
                self::cleanLeaseOwner($leaseOwner),
                date('Y-m-d H:i:s', time() + max(30, $leaseSeconds)),
                $taskId,
            ]
        );
        return $affected > 0;
    }

    public static function heartbeat(
        string $taskId,
        ?string $state = null,
        ?int $progress = null,
        ?string $message = null,
        int $leaseSeconds = 180
    ): bool {
        self::assertTaskId($taskId);
        if ($state !== null && !in_array($state, self::ACTIVE_STATES, true)) {
            throw new InvalidArgumentException('Invalid active writing task state.');
        }

        $sets = ['lease_expires_at=?'];
        $params = [date('Y-m-d H:i:s', time() + max(30, $leaseSeconds))];
        if ($state !== null) {
            $sets[] = 'state=?';
            $params[] = $state;
        }
        if ($progress !== null) {
            $sets[] = 'progress=?';
            $params[] = max(0, min(100, $progress));
        }
        if ($message !== null) {
            $sets[] = 'status_message=?';
            $params[] = self::limit($message, 500);
        }
        $params[] = $taskId;

        $affected = DB::execute(
            'UPDATE writing_tasks SET ' . implode(', ', $sets)
            . " WHERE task_id=? AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
            $params
        );
        return $affected > 0;
    }

    public static function requestCancel(int $novelId, ?string $taskId = null): int
    {
        if ($novelId <= 0) return 0;
        if ($taskId !== null && $taskId !== '') {
            self::assertTaskId($taskId);
            return DB::execute(
                "UPDATE writing_tasks SET cancel_requested=1, status_message='Cancellation requested'
                 WHERE novel_id=? AND task_id=?
                   AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
                [$novelId, $taskId]
            );
        }
        return DB::execute(
            "UPDATE writing_tasks SET cancel_requested=1, status_message='Cancellation requested'
             WHERE novel_id=?
               AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
            [$novelId]
        );
    }

    public static function isCancelRequested(string $taskId): bool
    {
        self::assertTaskId($taskId);
        return (int)DB::fetchColumn(
            'SELECT cancel_requested FROM writing_tasks WHERE task_id=? LIMIT 1',
            [$taskId]
        ) === 1;
    }

    public static function markDone(
        string $taskId,
        array $result = [],
        bool $contentSaved = true,
        ?string $revisionHash = null
    ): bool {
        self::assertTaskId($taskId);
        $json = $result === [] ? null : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state='done', progress=100, content_saved=?, chapter_revision_hash=?,
                 result_json=?, completed_at=NOW(), lease_owner=NULL, lease_expires_at=NULL,
                 error_code=NULL, error_message=NULL
             WHERE task_id=? AND state NOT IN ('done','failed','canceled')",
            [$contentSaved ? 1 : 0, self::validRevisionHash($revisionHash), $json, $taskId]
        );
        return $affected > 0;
    }

    public static function markFailed(
        string $taskId,
        string $errorMessage,
        bool $contentSaved = false,
        bool $canceled = false,
        string $errorCode = 'worker_failed'
    ): bool {
        self::assertTaskId($taskId);
        $affected = DB::execute(
            "UPDATE writing_tasks
             SET state=?, content_saved=?, error_code=?, error_message=?,
                 completed_at=NOW(), lease_owner=NULL, lease_expires_at=NULL
             WHERE task_id=? AND state NOT IN ('done','failed','canceled')",
            [
                $canceled ? 'canceled' : 'failed',
                $contentSaved ? 1 : 0,
                self::limit($errorCode, 64),
                self::limit($errorMessage, 500),
                $taskId,
            ]
        );
        return $affected > 0;
    }

    public static function toPollPayload(array $row): array
    {
        $state = (string)($row['state'] ?? 'unknown');
        $status = match ($state) {
            'done' => 'done',
            'failed', 'canceled' => 'error',
            'content_saved' => 'saved',
            'postprocessing' => 'postprocessing',
            default => 'writing',
        };
        $result = json_decode((string)($row['result_json'] ?? ''), true);
        if (!is_array($result)) $result = [];

        return array_merge($result, [
            'ok' => true,
            'status' => $status,
            'progress' => (int)($row['progress'] ?? 0),
            'chapter_id' => isset($row['chapter_id']) ? (int)$row['chapter_id'] : null,
            'error' => $state === 'canceled'
                ? '用户已取消写作'
                : ($row['error_message'] ?? null),
            'canceled' => $state === 'canceled',
            'content_saved' => !empty($row['content_saved']),
            'durable_fallback' => true,
        ]);
    }

    private static function assertTaskId(string $taskId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]{8,64}$/', $taskId)) {
            throw new InvalidArgumentException('Invalid task id.');
        }
    }

    private static function cleanLeaseOwner(string $owner): string
    {
        $owner = preg_replace('/[^a-zA-Z0-9_.:@-]/', '_', $owner) ?? '';
        return self::limit($owner !== '' ? $owner : 'unknown-worker', 120);
    }

    private static function validRevisionHash(?string $hash): ?string
    {
        if ($hash === null || $hash === '') return null;
        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : null;
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
