<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostprocessJobRepository.php';

/** Durable, pure-computation results. Only the parent may apply their effects. */
final class PostprocessSubtaskRepository
{
    public const TYPES = ['knowledge', 'critic'];
    private const VERSION = 1;
    private const LEASE_SECONDS = 1800;

    public static function enqueue(array $parentJob, string $type, array $input): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported post-processing computation type.');
        }
        $identity = self::parentIdentity($parentJob);
        $inputJson = self::encode($input);
        $inputHash = hash('sha256', $inputJson);
        $uid = substr(hash('sha256', implode(':', ['postprocess-subtask-v1', $identity['parent_job_uid'], $type, $inputHash])), 0, 32);
        return self::transaction(static function () use ($identity, $type, $inputJson, $inputHash, $uid): array {
            self::requireParent($identity, true);
            // An incremental projection (for example entity appearance counts)
            // is applied once per parent Candidate/type, even if settings change
            // before the parent retries with a different computation fingerprint.
            $row = DB::fetch('SELECT * FROM postprocess_subtasks WHERE parent_job_id=? AND task_type=?
                AND applied_at IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE', [$identity['parent_job_id'],$type]);
            if (!is_array($row)) {
                DB::execute(
                'INSERT INTO postprocess_subtasks
                    (task_uid,parent_job_id,parent_job_uid,novel_id,chapter_id,revision_hash,accepted_candidate_uid,
                     parent_lease_owner,parent_lease_token,parent_fence_generation,task_type,task_version,input_json,input_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
                [$uid,$identity['parent_job_id'],$identity['parent_job_uid'],$identity['novel_id'],$identity['chapter_id'],
                 $identity['revision_hash'],$identity['accepted_candidate_uid'],$identity['parent_lease_owner'],
                 $identity['parent_lease_token'],$identity['parent_fence_generation'],$type,self::VERSION,$inputJson,$inputHash]
                );
                $row = DB::fetch('SELECT * FROM postprocess_subtasks WHERE task_uid=? FOR UPDATE', [$uid]);
            }
            if (!is_array($row) || (int)$row['parent_job_id'] !== $identity['parent_job_id']
                || ($row['applied_at'] === null && ($row['input_hash'] !== $inputHash || (int)$row['task_version'] !== self::VERSION))
                || $row['task_type'] !== $type || $row['parent_job_uid'] !== $identity['parent_job_uid']
                || $row['revision_hash'] !== $identity['revision_hash'] || $row['accepted_candidate_uid'] !== $identity['accepted_candidate_uid']
                || (int)$row['novel_id'] !== $identity['novel_id'] || (int)$row['chapter_id'] !== $identity['chapter_id']) {
                throw new RuntimeException('Post-processing computation identity conflict.');
            }
            // A reclaimed parent invalidates in-flight children, but durable success
            // remains reusable for the same immutable input/Candidate identity.
            if (!self::sameParentLease($row, $identity)) {
                $done = $row['state'] === 'done';
                DB::execute(
                    'UPDATE postprocess_subtasks SET parent_lease_owner=?,parent_lease_token=?,parent_fence_generation=?,
                         state=?,attempt=IF(?=1,attempt,0),available_at=?,lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,
                         fence_generation=fence_generation+1,last_error=NULL,
                         started_at=IF(?=1,started_at,NULL),completed_at=IF(?=1,completed_at,NULL)
                     WHERE id=?',
                    [$identity['parent_lease_owner'],$identity['parent_lease_token'],$identity['parent_fence_generation'],
                     $done ? 'done' : 'queued',$done ? 1 : 0,HarnessRuntime::sqlNow(),$done ? 1 : 0,$done ? 1 : 0,(int)$row['id']]
                );
                $row = self::findById((int)$row['id']);
            }
            return $row;
        });
    }

    public static function findById(int $id): ?array
    {
        if ($id < 1) return null;
        $row = DB::fetch('SELECT * FROM postprocess_subtasks WHERE id=?', [$id]);
        return is_array($row) ? $row : null;
    }

    public static function hasTasks(int $parentId): bool
    {
        if ($parentId < 1) return false;
        return (bool)DB::fetchColumn('SELECT 1 FROM postprocess_subtasks WHERE parent_job_id=? LIMIT 1',[$parentId]);
    }

    /** Restore admitted work even after the feature flag changes. Never recapture settings. */
    public static function resumeTasks(array $parent): array
    {
        $identity = self::parentIdentity($parent);
        self::requireParent($identity, false);
        $rows = DB::fetchAll('SELECT * FROM postprocess_subtasks WHERE parent_job_id=?
            ORDER BY (applied_at IS NOT NULL) DESC,id DESC',[$identity['parent_job_id']]);
        $tasks = [];
        foreach ($rows as $row) {
            $type = (string)$row['task_type'];
            if (isset($tasks[$type])) continue;
            $tasks[$type] = self::enqueue($parent,$type,self::decodeInput($row));
        }
        return $tasks;
    }

    /** Brief provider-pressure signal for admitting NEW parallel batches only. */
    public static function hasRecentRateLimit(): bool
    {
        return (bool)DB::fetchColumn('SELECT 1 FROM postprocess_subtasks
            WHERE updated_at>=? AND state IN ("retry","failed")
              AND (last_error REGEXP "(^|[^0-9])429([^0-9]|$)"
                   OR LOWER(last_error) REGEXP "rate.?limit|too many requests") LIMIT 1',
            [HarnessRuntime::sqlAfterSeconds(-120)]);
    }

    public static function claimById(int $id, string $worker): ?array
    {
        if ($id < 1) return null;
        if (preg_match('/^[a-zA-Z0-9_.:@-]{1,120}$/D', $worker) !== 1) {
            throw new InvalidArgumentException('Invalid computation worker identity.');
        }
        $candidate = self::findById($id);
        if ($candidate === null) return null;
        return self::transaction(static function () use ($id, $worker, $candidate): ?array {
            self::requireParent($candidate, true);
            $row = DB::fetch('SELECT * FROM postprocess_subtasks WHERE id=? FOR UPDATE', [$id]);
            if (!is_array($row) || !self::sameParentLease($row, $candidate)) return null;
            $now = HarnessRuntime::sqlNow();
            $expired = $row['state'] === 'running' && is_string($row['lease_expires_at']) && $row['lease_expires_at'] < $now;
            if ((int)$row['attempt'] >= (int)$row['max_attempts']) {
                if ($expired) DB::execute(
                    'UPDATE postprocess_subtasks SET state="failed",last_error="Lease expired after final attempt",
                         completed_at=?,lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL WHERE id=?', [$now,$id]
                );
                return null;
            }
            if (!$expired && (!in_array($row['state'], ['queued','retry'], true) || $row['available_at'] > $now)) return null;
            DB::execute(
                'UPDATE postprocess_subtasks SET state="running",attempt=attempt+1,lease_owner=?,lease_token=?,
                     fence_generation=fence_generation+1,lease_expires_at=?,started_at=?,last_error=NULL WHERE id=?',
                [$worker,HarnessRuntime::randomHex(16),HarnessRuntime::sqlAfterSeconds(self::LEASE_SECONDS),$now,$id]
            );
            return self::findById($id);
        });
    }

    public static function complete(array $task, array $result): bool
    {
        $json = self::encode($result);
        return self::transaction(static function () use ($task, $json): bool {
            self::requireParent($task, true);
            self::requireTaskLease($task);
            return DB::execute(
                'UPDATE postprocess_subtasks SET state="done",result_json=?,result_hash=?,completed_at=?,
                     duration_ms=LEAST(4294967295,GREATEST(0,TIMESTAMPDIFF(MICROSECOND,started_at,NOW()) DIV 1000)),
                     lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,last_error=NULL
                 WHERE id=? AND state="running" AND lease_owner=? AND lease_token=? AND fence_generation=?',
                [$json,hash('sha256',$json),HarnessRuntime::sqlNow(),(int)$task['id'],$task['lease_owner'],$task['lease_token'],(int)$task['fence_generation']]
            ) === 1;
        });
    }

    public static function fail(array $task, Throwable $error): void
    {
        self::transaction(static function () use ($task, $error): void {
            self::requireParent($task, true);
            $row = self::requireTaskLease($task);
            $failed = (int)$row['attempt'] >= (int)$row['max_attempts'];
            $delay = min(3600,30 * (2 ** max(0,(int)$row['attempt'] - 1)));
            DB::execute(
                'UPDATE postprocess_subtasks SET state=?,available_at=?,last_error=?,completed_at=?,
                     duration_ms=LEAST(4294967295,GREATEST(0,TIMESTAMPDIFF(MICROSECOND,started_at,NOW()) DIV 1000)),
                     lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL WHERE id=?',
                [$failed ? 'failed' : 'retry',HarnessRuntime::sqlAfterSeconds($delay),mb_substr($error->getMessage(),0,4000),
                 $failed ? HarnessRuntime::sqlNow() : null,(int)$task['id']]
            );
        });
    }

    public static function heartbeat(array $task): void
    {
        self::transaction(static function () use ($task): void {
            self::requireParent($task, true);
            self::requireTaskLease($task);
            DB::execute('UPDATE postprocess_subtasks SET lease_expires_at=GREATEST(?,DATE_ADD(lease_expires_at,INTERVAL 1 SECOND)) WHERE id=?',
                [HarnessRuntime::sqlAfterSeconds(self::LEASE_SECONDS),(int)$task['id']]);
        });
    }

    public static function assertParentLive(array $task): void
    {
        self::requireParent($task, DB::getPdo()->inTransaction());
    }

    /** Caller must have confirmed its own child process exited via proc_get_status(). */
    public static function recoverExitedWorker(array $parentJob, int $workerPid): void
    {
        if ($workerPid < 1) throw new InvalidArgumentException('Exited computation worker requires a positive PID.');
        $identity = self::parentIdentity($parentJob);
        self::transaction(static function () use ($identity, $workerPid): void {
            self::requireParent($identity, true);
            $rows = DB::fetchAll('SELECT * FROM postprocess_subtasks WHERE parent_job_id=? AND state="running"
                AND lease_owner=? AND parent_lease_owner=? AND parent_lease_token=? AND parent_fence_generation=? FOR UPDATE',
                [$identity['parent_job_id'],'postprocess-compute:'.$workerPid,$identity['parent_lease_owner'],
                 $identity['parent_lease_token'],$identity['parent_fence_generation']]);
            foreach ($rows as $row) {
                $failed = (int)$row['attempt'] >= (int)$row['max_attempts'];
                // Unknown paid invocations stay charged in the shared Run ledger.
                // Only this dead process's computation lease is invalidated.
                DB::execute('UPDATE postprocess_subtasks SET state=?,available_at=?,completed_at=?,
                    last_error="Computation worker exited before returning a durable result",
                    lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,fence_generation=fence_generation+1
                    WHERE id=? AND state="running" AND lease_owner=? AND lease_token=? AND fence_generation=?',
                    [$failed ? 'failed' : 'retry',HarnessRuntime::sqlNow(),$failed ? HarnessRuntime::sqlNow() : null,
                     (int)$row['id'],$row['lease_owner'],$row['lease_token'],(int)$row['fence_generation']]);
            }
        });
    }

    /** Call before applying effects in the SAME transaction. False means already applied. */
    public static function markApplied(array $task): bool
    {
        if (!DB::getPdo()->inTransaction()) throw new LogicException('Applying a computation requires the caller transaction.');
        self::requireParent($task, true);
        $row = DB::fetch('SELECT * FROM postprocess_subtasks WHERE id=? FOR UPDATE', [(int)($task['id'] ?? 0)]);
        if (!is_array($row) || $row['state'] !== 'done' || !self::sameParentLease($row,$task)
            || !hash_equals((string)$row['input_hash'],(string)($task['input_hash'] ?? ''))
            || !hash_equals((string)$row['result_hash'],(string)($task['result_hash'] ?? ''))) {
            throw new WriteEnginePostprocessLeaseLostException('Computation result identity changed before application.');
        }
        $published = DB::fetch('SELECT id FROM postprocess_subtasks WHERE parent_job_id=? AND task_type=?
            AND applied_at IS NOT NULL LIMIT 1 FOR UPDATE', [(int)$row['parent_job_id'],$row['task_type']]);
        if (is_array($published)) return false;
        return DB::execute('UPDATE postprocess_subtasks SET applied_at=? WHERE id=? AND applied_at IS NULL',
            [HarnessRuntime::sqlNow(),(int)$task['id']]) === 1;
    }

    /** Public status only: never expose frozen prose, model results or lease tokens. */
    public static function listProgress(int $jobId): array
    {
        if ($jobId < 1) return [];
        $rows = DB::fetchAll('SELECT task_type,state,duration_ms,attempt,last_error,applied_at
            FROM postprocess_subtasks WHERE parent_job_id=? ORDER BY (applied_at IS NOT NULL) DESC,id DESC', [$jobId]);
        $latest = [];
        foreach ($rows as $row) if (!isset($latest[$row['task_type']])) $latest[$row['task_type']] = $row;
        return array_values($latest);
    }

    public static function decodeInput(array $task): array
    {
        return self::decodeChecked($task,'input');
    }

    public static function decodeResult(array $task): array
    {
        if (($task['state'] ?? '') !== 'done') throw new RuntimeException('Computation result is not ready.');
        return self::decodeChecked($task,'result');
    }

    /** Pure identity validation also used by the budget authority. */
    public static function assertBinding(array $task, array $parent): void
    {
        $identity = self::parentIdentity($parent);
        foreach (['parent_job_id','novel_id','chapter_id','parent_fence_generation'] as $field) {
            if ((string)($task[$field] ?? '') !== (string)$identity[$field]) throw new WriteEnginePostprocessLeaseLostException('Computation parent coordinates changed.');
        }
        foreach (['parent_job_uid','revision_hash','parent_lease_owner','parent_lease_token'] as $field) {
            if (!is_string($task[$field] ?? null) || !hash_equals($identity[$field],$task[$field])) throw new WriteEnginePostprocessLeaseLostException('Computation parent identity changed.');
        }
        if (($task['accepted_candidate_uid'] ?? null) !== $identity['accepted_candidate_uid']) {
            throw new WriteEnginePostprocessLeaseLostException('Computation Candidate identity changed.');
        }
    }

    private static function requireParent(array $task, bool $lock): array
    {
        $id = (int)($task['parent_job_id'] ?? 0);
        $row = DB::fetch('SELECT *, (lease_expires_at IS NOT NULL AND lease_expires_at>=?) AS subtask_parent_live
            FROM postprocess_jobs WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [HarnessRuntime::sqlNow(),$id]);
        if (!is_array($row) || ($row['state'] ?? '') !== 'running' || (int)$row['subtask_parent_live'] !== 1) {
            throw new WriteEnginePostprocessLeaseLostException('Computation parent is no longer live.');
        }
        self::assertBinding($task,$row);
        return $row;
    }

    private static function parentIdentity(array $parent): array
    {
        $lease = PostprocessJobRepository::leaseIdentityFromRow($parent);
        foreach (['job_uid' => 32,'revision_hash' => 64] as $field => $length) {
            if (!is_string($parent[$field] ?? null) || preg_match('/^[a-f0-9]{'.$length.'}$/D',$parent[$field]) !== 1) {
                throw new InvalidArgumentException('Invalid computation parent '.$field.'.');
            }
        }
        foreach (['novel_id','chapter_id'] as $field) if ((int)($parent[$field] ?? 0) < 1) throw new InvalidArgumentException('Invalid computation parent coordinates.');
        $candidate = PostprocessJobRepository::acceptedCandidateFromRow($parent);
        return ['parent_job_id'=>(int)$parent['id'],'parent_job_uid'=>$parent['job_uid'],
            'novel_id'=>(int)$parent['novel_id'],'chapter_id'=>(int)$parent['chapter_id'],'revision_hash'=>$parent['revision_hash'],
            'accepted_candidate_uid'=>$candidate,'parent_lease_owner'=>$lease['owner'],
            'parent_lease_token'=>$lease['token'],'parent_fence_generation'=>$lease['generation']];
    }

    private static function sameParentLease(array $left, array $right): bool
    {
        foreach (['parent_lease_owner','parent_lease_token','parent_fence_generation'] as $field) {
            if ((string)($left[$field] ?? '') !== (string)($right[$field] ?? '')) return false;
        }
        return true;
    }

    private static function requireTaskLease(array $task): array
    {
        PostprocessJobRepository::leaseIdentityFromRow($task);
        $row = DB::fetch('SELECT * FROM postprocess_subtasks WHERE id=? FOR UPDATE', [(int)$task['id']]);
        if (!is_array($row) || $row['state'] !== 'running' || !self::sameParentLease($row,$task)
            || ($row['lease_owner'] ?? null) !== $task['lease_owner'] || ($row['lease_token'] ?? null) !== $task['lease_token']
            || (int)$row['fence_generation'] !== (int)$task['fence_generation']
            || !is_string($row['lease_expires_at']) || $row['lease_expires_at'] < HarnessRuntime::sqlNow()) {
            throw new WriteEnginePostprocessLeaseLostException('Computation no longer owns its exact live lease.');
        }
        return $row;
    }

    private static function transaction(callable $operation): mixed
    {
        $pdo = DB::getPdo();
        if ($pdo->inTransaction()) throw new LogicException('Computation queue mutations require an independent transaction.');
        if (!$pdo->beginTransaction()) throw new RuntimeException('Could not begin computation transaction.');
        try {
            $result = $operation();
            if (!$pdo->commit()) throw new RuntimeException('Could not commit computation transaction.');
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function encode(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (is_array($item)) {
                if (!array_is_list($item)) ksort($item,SORT_STRING);
                foreach ($item as $key => $child) $item[$key] = $normalize($child);
            } elseif (is_object($item) || is_resource($item)) {
                throw new InvalidArgumentException('Computation snapshots accept JSON values only.');
            }
            return $item;
        };
        return json_encode($normalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private static function decodeChecked(array $task, string $prefix): array
    {
        $json = $task[$prefix.'_json'] ?? null;
        $hash = $task[$prefix.'_hash'] ?? null;
        // MySQL JSON normalizes whitespace/key order; hash the canonical decoded
        // value, never the storage engine's rendering of the JSON column.
        if (!is_string($json) || !is_string($hash)) throw new RuntimeException('Computation payload is missing.');
        $value = json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($value) || !hash_equals($hash,hash('sha256',self::encode($value)))) throw new RuntimeException('Computation payload checksum mismatch.');
        return $value;
    }
}
