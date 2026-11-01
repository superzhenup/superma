<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/harness/jobs/LegacyJobShadow.php';

final class DramaLeaseLostException extends RuntimeException {}

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
            'run_after'    => gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
        ]);
        $taskId = (int)DB::lastId();
        LegacyJobShadowBridge::tryMap(LegacyJobShadowMapper::DRAMA, $taskId);
        return $taskId;
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
        $now = gmdate('Y-m-d H:i:s');
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
            // A crash on the final attempt must converge to failed instead of
            // remaining forever running/pending outside the claim predicate.
            $exhaustedParams = [$now];
            if ($typeFilter !== null) $exhaustedParams[] = $typeFilter;
            DB::execute(
                "UPDATE drama_tasks SET status='failed', error='任务租约过期或重试次数已耗尽',
                    lease_owner=NULL, lease_expires_at=NULL
                 WHERE attempts >= max_attempts AND (status='pending'
                    OR (status='running' AND lease_expires_at IS NOT NULL AND lease_expires_at <= ?)) {$typeSql}",
                $exhaustedParams
            );
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
                // A fresh token on every claim also fences restarts using the same worker id.
                [mb_substr(self::cleanWorkerId($workerId), 0, 87) . ':' . bin2hex(random_bytes(16)),
                    gmdate('Y-m-d H:i:s', time() + max(60, $leaseSeconds)), (int)$row['id']]
            );
            $claimed = self::findById((int)$row['id']);
            $pdo->commit();
            return $claimed;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Publish business rows and the task transition in one transaction. Network/FFmpeg
     * work must finish before entering this boundary. An expired/replaced/canceled
     * claim cannot publish, including when it expires during the callback.
     */
    public static function commitResult(array $task, callable $publish, string $state = 'done', int $delaySeconds = 30): array
    {
        if (!in_array($state, ['done', 'pending', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid drama task transition.');
        }
        return self::withClaim($task, static function (array $row) use ($task, $publish, $state, $delaySeconds): array {
            $result = $publish();
            if (!is_array($result)) throw new RuntimeException('Drama task result must be an array.');
            $changed = DB::execute(
                "UPDATE drama_tasks SET status=?, progress=?, result=?, error=?, run_after=?,
                    lease_owner=NULL, lease_expires_at=NULL
                 WHERE id=? AND status='running' AND lease_owner=? AND attempts=? AND lease_expires_at > ?",
                [$state, $state === 'done' ? 100 : (int)$row['progress'],
                    json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $state === 'failed' ? mb_substr((string)($result['error'] ?? '任务失败'), 0, 4000) : null,
                    gmdate('Y-m-d H:i:s', time() + max(5, $delaySeconds)),
                    (int)$task['id'], (string)$task['lease_owner'], (int)$task['attempts'], gmdate('Y-m-d H:i:s')]
            );
            if ($changed !== 1) throw new DramaLeaseLostException('漫剧任务租约已失效，结果未发布');
            return $result;
        });
    }

    public static function markDone(array $task, array $result = []): void
    {
        self::commitResult($task, static fn(): array => $result);
    }

    /** 视频轮询未就绪：退回 pending，延迟下次执行，保留 result 中的 provider task_id。 */
    public static function requeue(array $task, int $delaySeconds, array $result): void
    {
        self::commitResult($task, static fn(): array => $result, 'pending', $delaySeconds);
    }

    /** @return 'retry'|'failed' */
    public static function markRetryOrFailed(array $task, string $error, int $baseDelaySeconds = 20): string
    {
        $attempt = (int)$task['attempts'];
        $maxAttempts = max(1, (int)$task['max_attempts']);
        $failed = $attempt >= $maxAttempts;
        $delay = (int)min(1800, max(5, $baseDelaySeconds) * (2 ** min(10, max(0, $attempt - 1))));
        self::withClaim($task, static function (array $row) use ($task, $error, $failed, $delay): void {
            $changed = DB::execute(
                "UPDATE drama_tasks SET status=?, run_after=?, error=?, lease_owner=NULL, lease_expires_at=NULL
                 WHERE id=? AND status='running' AND lease_owner=? AND attempts=? AND lease_expires_at > ?",
                [$failed ? 'failed' : 'pending', gmdate('Y-m-d H:i:s', time() + $delay),
                    mb_substr($error, 0, 4000), (int)$task['id'], (string)$task['lease_owner'],
                    (int)$task['attempts'], gmdate('Y-m-d H:i:s')]
            );
            if ($changed !== 1) throw new DramaLeaseLostException('漫剧任务租约已失效，错误未写入');
        });
        return $failed ? 'failed' : 'retry';
    }

    public static function updateProgress(array $task, int $progress, ?array $result = null): void
    {
        self::withClaim($task, static function (array $row) use ($task, $progress, $result): void {
            $sql = "UPDATE drama_tasks SET progress=?, lease_expires_at=?";
            $params = [max(0, min(100, $progress)), gmdate('Y-m-d H:i:s', time() + 1800)];
            if ($result !== null) {
                $sql .= ", result=?";
                $params[] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $sql .= " WHERE id=? AND status='running' AND lease_owner=? AND attempts=? AND lease_expires_at > ?";
            array_push($params, (int)$task['id'], (string)$task['lease_owner'], (int)$task['attempts'], gmdate('Y-m-d H:i:s'));
            DB::execute($sql, $params);
            self::lockOwnedClaim($task);
        });
    }

    /** Read-only claim check before starting another expensive external operation. */
    public static function assertOwned(array $task): void
    {
        self::withClaim($task, static function (array $row): void {});
    }

    private static function withClaim(array $task, callable $operation): mixed
    {
        $pdo = DB::connect();
        if ($pdo->inTransaction()) throw new RuntimeException('Drama publication requires an independent transaction.');
        $pdo->beginTransaction();
        try {
            $row = self::lockOwnedClaim($task);
            $result = $operation($row);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function lockOwnedClaim(array $task): array
    {
        $row = DB::fetch('SELECT * FROM drama_tasks WHERE id=? FOR UPDATE', [(int)($task['id'] ?? 0)]);
        if (!$row || $row['status'] !== 'running'
            || (string)($task['lease_owner'] ?? '') === ''
            || !hash_equals((string)$row['lease_owner'], (string)$task['lease_owner'])
            || (int)($task['attempts'] ?? 0) < 1 || (int)$row['attempts'] !== (int)$task['attempts']
            || (string)($row['lease_expires_at'] ?? '') <= gmdate('Y-m-d H:i:s')) {
            throw new DramaLeaseLostException('漫剧任务租约已失效，结果未发布');
        }
        return $row;
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
