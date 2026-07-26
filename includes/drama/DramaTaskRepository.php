<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';

/**
 * 漫剧异步任务队列仓储（仿 PostprocessJobRepository 的租约模式）。
 *
 * 状态机：pending → running → done / failed / canceled
 * 视频轮询通过 requeue() 把任务退回 pending 并延迟 run_after，
 * 每次 claim 都会消耗一次 attempts，因此视频任务 max_attempts 设大（默认 40）。
 */
final class DramaTaskRepository
{
    public const TYPES = ['parse_script', 'gen_storyboard', 'gen_asset', 'gen_shot_image', 'gen_shot_video', 'compose_episode'];

    public static function enqueue(
        int $projectId,
        ?int $episodeId,
        string $type,
        int $refId = 0,
        array $payload = [],
        int $delaySeconds = 0,
        int $maxAttempts = 3
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('未知的漫剧任务类型');
        }
        DB::insert('drama_tasks', [
            'project_id'   => $projectId,
            'episode_id'   => $episodeId,
            'type'         => $type,
            'ref_id'       => $refId,
            'payload'      => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'status'       => 'pending',
            'progress'     => 0,
            'attempts'     => 0,
            'max_attempts' => max(1, min(60, $maxAttempts)),
            'run_after'    => date('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
        ]);
        return (int)DB::lastId();
    }

    public static function findById(int $taskId): ?array
    {
        if ($taskId <= 0) return null;
        $row = DB::fetch('SELECT * FROM drama_tasks WHERE id=? LIMIT 1', [$taskId]);
        return is_array($row) ? $row : null;
    }

    /** 认领下一个可执行任务（事务 + FOR UPDATE，支持过期租约回收）。 */
    public static function claimNext(string $workerId, int $leaseSeconds = 1800, ?string $typeFilter = null): ?array
    {
        $pdo = DB::connect();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Drama task claims require an independent transaction.');
        }
        $now = date('Y-m-d H:i:s');
        $typeSql = '';
        $params = [$now, $now];
        if ($typeFilter !== null) {
            if (!in_array($typeFilter, self::TYPES, true)) {
                throw new InvalidArgumentException('未知的漫剧任务类型');
            }
            $typeSql = ' AND type = ?';
            $params[] = $typeFilter;
        }
        $pdo->beginTransaction();
        try {
            $row = DB::fetch(
                "SELECT * FROM drama_tasks
                 WHERE attempts < max_attempts
                   AND ((status = 'pending' AND run_after <= ?)
                     OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < ?))
                   {$typeSql}
                 ORDER BY run_after, id LIMIT 1 FOR UPDATE",
                $params
            );
            if (!$row) {
                $pdo->commit();
                return null;
            }
            DB::execute(
                "UPDATE drama_tasks
                 SET status='running', attempts=attempts+1, lease_owner=?, lease_expires_at=?, error=NULL
                 WHERE id=?",
                [self::cleanWorkerId($workerId), date('Y-m-d H:i:s', time() + max(60, $leaseSeconds)), (int)$row['id']]
            );
            $pdo->commit();
            return self::findById((int)$row['id']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function markDone(int $taskId, array $result = []): void
    {
        DB::execute(
            "UPDATE drama_tasks
             SET status='done', progress=100, result=?, lease_owner=NULL, lease_expires_at=NULL, error=NULL
             WHERE id=? AND status='running'",
            [$result ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $taskId]
        );
    }

    /** 视频轮询未就绪：退回 pending，延迟下次执行，保留 result 中的 provider task_id。 */
    public static function requeue(int $taskId, int $delaySeconds, array $result): void
    {
        DB::execute(
            "UPDATE drama_tasks
             SET status='pending', run_after=?, result=?, lease_owner=NULL, lease_expires_at=NULL
             WHERE id=? AND status='running'",
            [
                date('Y-m-d H:i:s', time() + max(5, $delaySeconds)),
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $taskId,
            ]
        );
    }

    /** @return 'retry'|'failed' */
    public static function markRetryOrFailed(int $taskId, string $error, int $baseDelaySeconds = 20): string
    {
        $row = self::findById($taskId);
        if (!$row) return 'failed';
        $attempt = (int)$row['attempts'];
        $maxAttempts = max(1, (int)$row['max_attempts']);
        $failed = $attempt >= $maxAttempts;
        $delay = min(1800, max(5, $baseDelaySeconds) * (2 ** max(0, $attempt - 1)));
        DB::execute(
            "UPDATE drama_tasks
             SET status=?, run_after=?, error=?, lease_owner=NULL, lease_expires_at=NULL
             WHERE id=? AND status='running'",
            [
                $failed ? 'failed' : 'pending',
                date('Y-m-d H:i:s', time() + $delay),
                mb_substr($error, 0, 4000),
                $taskId,
            ]
        );
        return $failed ? 'failed' : 'retry';
    }

    public static function updateProgress(int $taskId, int $progress, ?array $result = null): void
    {
        $sql = "UPDATE drama_tasks SET progress=?";
        $params = [max(0, min(100, $progress))];
        if ($result !== null) {
            $sql .= ", result=?";
            $params[] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $sql .= " WHERE id=? AND status='running'";
        $params[] = $taskId;
        DB::execute($sql, $params);
    }

    /** 前端轮询：项目/剧集维度任务摘要。 */
    public static function listActiveByProject(int $projectId, int $limit = 30): array
    {
        return DB::fetchAll(
            "SELECT id, episode_id, type, ref_id, status, progress, result, error, attempts, updated_at
             FROM drama_tasks WHERE project_id=? AND status IN ('pending','running','failed')
             ORDER BY id DESC LIMIT ?",
            [$projectId, max(1, min(100, $limit))]
        );
    }

    public static function cancelPendingByEpisode(int $episodeId, ?string $type = null): int
    {
        if ($type !== null) {
            return DB::execute(
                "UPDATE drama_tasks SET status='canceled' WHERE episode_id=? AND status='pending' AND type=?",
                [$episodeId, $type]
            );
        }
        return DB::execute(
            "UPDATE drama_tasks SET status='canceled' WHERE episode_id=? AND status='pending'",
            [$episodeId]
        );
    }

    private static function cleanWorkerId(string $workerId): string
    {
        $workerId = preg_replace('/[^a-zA-Z0-9_.:@-]/', '_', $workerId) ?? '';
        return mb_substr($workerId !== '' ? $workerId : 'unknown-worker', 0, 120);
    }
}
