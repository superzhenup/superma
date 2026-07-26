<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';

final class PostprocessJobRepository
{
    public static function enqueue(
        int $novelId,
        int $chapterId,
        string $revisionHash,
        string $stage = 'full',
        int $maxAttempts = 3
    ): int {
        self::assertIdentity($novelId, $chapterId, $revisionHash, $stage);
        DB::execute(
            "INSERT INTO postprocess_jobs
                (novel_id, chapter_id, revision_hash, stage, state, attempt, max_attempts, available_at)
             VALUES (?, ?, ?, ?, 'queued', 0, ?, NOW())
             ON DUPLICATE KEY UPDATE
                id=LAST_INSERT_ID(id), max_attempts=GREATEST(max_attempts, VALUES(max_attempts))",
            [$novelId, $chapterId, $revisionHash, $stage, max(1, min(20, $maxAttempts))]
        );
        return (int)DB::lastId();
    }

    public static function findById(int $jobId): ?array
    {
        if ($jobId <= 0) return null;
        $row = DB::fetch('SELECT * FROM postprocess_jobs WHERE id=? LIMIT 1', [$jobId]);
        return is_array($row) ? $row : null;
    }

    public static function claimNext(string $workerId, int $leaseSeconds = 1800): ?array
    {
        return self::claim(null, $workerId, $leaseSeconds);
    }

    public static function claimById(int $jobId, string $workerId, int $leaseSeconds = 1800): ?array
    {
        if ($jobId <= 0) return null;
        return self::claim($jobId, $workerId, $leaseSeconds);
    }

    public static function markDone(int $jobId): bool
    {
        return DB::execute(
            "UPDATE postprocess_jobs
             SET state='done', completed_at=NOW(), lease_owner=NULL, lease_expires_at=NULL, last_error=NULL
             WHERE id=? AND state='running'",
            [$jobId]
        ) > 0;
    }

    /** @return 'retry'|'failed' */
    public static function markRetryOrFailed(int $jobId, string $error, int $baseDelaySeconds = 30): string
    {
        $row = self::findById($jobId);
        if (!$row) return 'failed';

        $attempt = (int)($row['attempt'] ?? 0);
        $maxAttempts = max(1, (int)($row['max_attempts'] ?? 3));
        $failed = $attempt >= $maxAttempts;
        $delay = min(3600, max(5, $baseDelaySeconds) * (2 ** max(0, $attempt - 1)));
        $availableAt = date('Y-m-d H:i:s', time() + $delay);
        DB::execute(
            'UPDATE postprocess_jobs
             SET state=?, available_at=?, last_error=?, lease_owner=NULL, lease_expires_at=NULL,
                 completed_at=IF(?="failed", NOW(), NULL)
             WHERE id=? AND state="running"',
            [
                $failed ? 'failed' : 'retry',
                $availableAt,
                self::limit($error, 4000),
                $failed ? 'failed' : 'retry',
                $jobId,
            ]
        );
        return $failed ? 'failed' : 'retry';
    }

    public static function extendLease(int $jobId, string $workerId, int $leaseSeconds = 1800): bool
    {
        return DB::execute(
            "UPDATE postprocess_jobs SET lease_expires_at=?
             WHERE id=? AND state='running' AND lease_owner=?",
            [date('Y-m-d H:i:s', time() + max(60, $leaseSeconds)), $jobId, self::cleanWorkerId($workerId)]
        ) > 0;
    }

    private static function claim(?int $jobId, string $workerId, int $leaseSeconds): ?array
    {
        $pdo = DB::connect();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Post-processing claims require an independent transaction.');
        }

        $pdo->beginTransaction();
        try {
            $params = [];
            $idFilter = '';
            if ($jobId !== null) {
                $idFilter = 'id=? AND ';
                $params[] = $jobId;
            }
            $row = DB::fetch(
                "SELECT * FROM postprocess_jobs
                 WHERE {$idFilter}attempt < max_attempts
                   AND ((state IN ('queued','retry') AND available_at <= ?)
                     OR (state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at < ?))
                 ORDER BY available_at, id LIMIT 1 FOR UPDATE",
                // 审计修复（2026-07-19 M1-4）：过期判定用 PHP date() 时间源，与 available_at/lease_expires_at
                // 写入端（markRetryOrFailed/extendLease/claim 均用 date()）一致，避免 NOW() 时区偏移。
                array_merge($params, [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')])
            );
            if (!$row) {
                $pdo->commit();
                return null;
            }

            $workerId = self::cleanWorkerId($workerId);
            DB::execute(
                "UPDATE postprocess_jobs
                 SET state='running', attempt=attempt+1, lease_owner=?, lease_expires_at=?,
                     started_at=COALESCE(started_at, NOW()), last_error=NULL
                 WHERE id=?",
                [$workerId, date('Y-m-d H:i:s', time() + max(60, $leaseSeconds)), (int)$row['id']]
            );
            $pdo->commit();
            return self::findById((int)$row['id']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function assertIdentity(int $novelId, int $chapterId, string $hash, string $stage): void
    {
        if ($novelId <= 0 || $chapterId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new InvalidArgumentException('Invalid post-processing job identity.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $stage)) {
            throw new InvalidArgumentException('Invalid post-processing stage.');
        }
    }

    private static function cleanWorkerId(string $workerId): string
    {
        $workerId = preg_replace('/[^a-zA-Z0-9_.:@-]/', '_', $workerId) ?? '';
        return self::limit($workerId !== '' ? $workerId : 'unknown-worker', 120);
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
