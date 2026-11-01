<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Single-node draft admission. No method writes Chapter Canon or authenticates HTTP users. */
final class ChapterRewriteTaskStore
{
    public const MAX_CONTENT_BYTES = 1048576;
    public const MAX_JSON_BYTES = 2097152;
    private const STAGES = ['queued', 'authorized', 'context_prepare', 'quality_before', 'model_request',
        'model_output', 'model_response', 'quality_check', 'quality_recheck', 'quality_after', 'persist', 'ready',
        'finished', 'failed', 'adopting', 'adopted', 'postprocess'];
    private const STATES = ['queued', 'running', 'ready', 'failed', 'adopting', 'adopted'];
    private string $directory;
    private Closure $clock;
    /** @var resource|null */
    private $lock = null;
    private ?array $owned = null;

    public function __construct(?string $directory = null, ?callable $clock = null)
    {
        $this->directory = rtrim($directory ?? ((defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2))
            . '/storage/cache/chapter_rewrite_tasks'), '/\\');
        if ($this->directory === '') throw new InvalidArgumentException('Rewrite task directory is empty.');
        $this->clock = Closure::fromCallable($clock ?? static fn(): float => microtime(true));
    }

    public function enqueue(int $userId, int $novelId, int $chapterId, string $taskId, string $sourceContentHash): bool
    {
        self::identity($userId, $chapterId, $taskId);
        if ($novelId < 1 || !self::isHash($sourceContentHash)) throw new InvalidArgumentException('Invalid rewrite source identity.');
        if (!$this->takeLock($chapterId, true)) return false;
        try {
            $now = $this->now();
            $previous = $this->readState($chapterId);
            if ($this->liveQueue($previous, $now) || ($previous['task_id'] ?? null) === $taskId
                || ($previous['state'] ?? null) === 'adopting') return false;
            // A retired ID must not become a new job after a later task replaced
            // the latest snapshot. Exclusive creation also binds IDs across chapters.
            $identityPath = $this->directory . '/task-' . $taskId . '.claim';
            $marker = @fopen($identityPath, 'x+b');
            if ($marker === false) {
                if (file_exists($identityPath)) return false;
                throw new RuntimeException('Cannot reserve rewrite task identity.');
            }
            fclose($marker);
            $this->persist([
                'user_id' => $userId, 'novel_id' => $novelId, 'chapter_id' => $chapterId,
                'task_id' => $taskId, 'source_hash' => $sourceContentHash,
                'state' => 'queued', 'stage' => 'queued', 'execution' => 'cli',
                'started_at' => $now, 'updated_at' => (int)floor($now), 'elapsed_seconds' => 0.0,
                'launch_deadline' => (int)floor($now) + 30,
                'words' => 0, 'chunks' => 0, 'first_output_seen' => false,
            ]);
            return true;
        } finally { $this->close(); }
    }

    /** Exact queued task only; a successful claimant owns the lock through close/exit. */
    public function claim(int $chapterId, string $taskId): ?array
    {
        self::identity(1, $chapterId, $taskId);
        $deadline = hrtime(true) + 500_000_000;
        while (!$this->takeLock($chapterId, false)) {
            $pending = $this->readState($chapterId);
            $remaining = $deadline - hrtime(true);
            if (($pending['task_id'] ?? null) !== $taskId || !$this->liveQueue($pending, $this->now())
                || $remaining <= 0) return null;
            usleep(max(1, min(20_000, (int)($remaining / 1000))));
        }
        try {
            $pending = $this->readState($chapterId);
            if (($pending['task_id'] ?? null) !== $taskId || !$this->liveQueue($pending, $this->now())) {
                $this->close();
                return null;
            }
            $pending['state'] = 'running'; $pending['stage'] = 'authorized';
            $this->persist($this->touch($pending));
            return $this->owned;
        } catch (Throwable $error) { $this->close(); throw $error; }
    }

    /** Only predeclared progress fields are persisted; model text belongs in complete(). */
    public function record(string $stage, array $safeFields = []): void
    {
        $next = $this->requireOwned();
        if (!in_array($stage, self::STAGES, true) || in_array($stage, ['queued', 'ready', 'adopting', 'adopted', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid rewrite progress stage.');
        }
        foreach (['user_id', 'novel_id', 'chapter_id', 'task_id', 'source_hash'] as $key) {
            if (array_key_exists($key, $safeFields) && $safeFields[$key] !== $next[$key]) {
                throw new InvalidArgumentException('Progress does not belong to this rewrite task.');
            }
        }
        if (!in_array($next['state'], ['running', 'ready', 'adopting', 'adopted'], true)) throw new LogicException('Rewrite task has no live draft work.');
        $next['stage'] = $stage;
        foreach (['words', 'chunks'] as $key) {
            if (array_key_exists($key, $safeFields)) {
                if (!is_int($safeFields[$key]) || $safeFields[$key] < 0 || $safeFields[$key] > self::MAX_CONTENT_BYTES) {
                    throw new InvalidArgumentException('Invalid rewrite progress count.');
                }
                if ($next['state'] === 'running') $next[$key] = $safeFields[$key];
            }
        }
        if (array_key_exists('attempt', $safeFields)) {
            if (!is_int($safeFields['attempt']) || $safeFields['attempt'] < 0 || $safeFields['attempt'] > 1000) {
                throw new InvalidArgumentException('Invalid rewrite attempt count.');
            }
            $next['attempt'] = $safeFields['attempt'];
        }
        foreach (['model_switch', 'recheck_failed'] as $key) {
            if (array_key_exists($key, $safeFields)) {
                if (!is_bool($safeFields[$key])) throw new InvalidArgumentException('Invalid rewrite progress flag.');
                $next[$key] = $safeFields[$key];
            }
        }
        if (($safeFields['first_output_seen'] ?? false) === true) $next['first_output_seen'] = true;
        if (isset($safeFields['output_kind'])) {
            if (!in_array($safeFields['output_kind'], ['content', 'thinking'], true)) throw new InvalidArgumentException('Invalid output kind.');
            $next['output_kind'] ??= $safeFields['output_kind'];
            $next['first_output_seen'] = true;
        }
        $this->persist($this->touch($next));
    }

    public function complete(string $content, int $words, ?array $qualityBefore = null, ?array $qualityAfter = null): void
    {
        $next = $this->requireOwned();
        if ($next['state'] === 'adopting') {
            // The HTTP boundary may recover a failed pre-commit adoption after
            // independently proving Canon still equals source_hash. It can
            // restore only these exact durable candidate bytes, never a new draft.
            if (!hash_equals($next['candidate_hash'], hash('sha256', $content)) || $words !== $next['words']) {
                throw new LogicException('Adoption recovery cannot replace the candidate.');
            }
            $next['state'] = 'ready'; $next['stage'] = 'ready';
            $this->persist($this->touch($next));
            return;
        }
        if ($next['state'] !== 'running') throw new LogicException('Only a running task can publish a draft.');
        if (trim($content) === '' || strlen($content) > self::MAX_CONTENT_BYTES || $words < 1 || $words > self::MAX_CONTENT_BYTES) {
            throw new InvalidArgumentException('Rewrite candidate is empty or exceeds its size limit.');
        }
        $next['content'] = $content; $next['candidate_hash'] = hash('sha256', $content); $next['words'] = $words;
        $next['quality_before'] = self::quality($qualityBefore); $next['quality_after'] = self::quality($qualityAfter);
        $next['state'] = 'ready'; $next['stage'] = 'ready';
        $this->persist($this->touch($next));
    }

    /** A late worker error must never erase a published draft or an adoption receipt. */
    public function fail(string $code, string $safeMessage): void
    {
        $next = $this->requireOwned();
        if (in_array($next['state'], ['ready', 'adopting', 'adopted'], true)) return;
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1 || strlen($safeMessage) > 1000
            || preg_match('//u', $safeMessage) !== 1) throw new InvalidArgumentException('Invalid safe rewrite failure.');
        $next['state'] = 'failed'; $next['stage'] = 'failed';
        $next['error_code'] = $code; $next['message'] = $safeMessage;
        $this->persist($this->touch($next));
    }

    public function failQueued(int $userId, int $chapterId, string $taskId): bool
    {
        self::identity($userId, $chapterId, $taskId);
        if (!$this->takeLock($chapterId, false)) return false;
        try {
            $pending = $this->readState($chapterId);
            if (($pending['user_id'] ?? null) !== $userId || ($pending['task_id'] ?? null) !== $taskId
                || ($pending['state'] ?? null) !== 'queued') return false;
            $this->owned = $pending;
            $this->fail('launch_failed', '后台改写进程未能启动，请重新生成。');
            return true;
        } finally { $this->close(); }
    }

    /** Locks a candidate for the caller's DB hash-CAS; this store does not perform that CAS. */
    public function lockReady(int $userId, int $chapterId, string $taskId): ?array
    {
        self::identity($userId, $chapterId, $taskId);
        if (!$this->takeLock($chapterId, false)) return null;
        $pending = $this->readState($chapterId);
        if (($pending['user_id'] ?? null) !== $userId || ($pending['task_id'] ?? null) !== $taskId
            || !in_array($pending['state'] ?? null, ['ready', 'adopting', 'adopted'], true)) {
            $this->close();
            return null;
        }
        $this->owned = $pending;
        return $pending;
    }

    public function markAdopting(): void
    {
        $next = $this->requireOwned();
        if (!in_array($next['state'], ['ready', 'adopting'], true)) throw new LogicException('Candidate is not ready for adoption.');
        $next['state'] = 'adopting'; $next['stage'] = 'adopting';
        $this->persist($this->touch($next));
    }

    /** Call only after the DB commit, including a verified same-hash recovery. */
    public function markAdopted(): void
    {
        $next = $this->requireOwned();
        if (!in_array($next['state'], ['adopting', 'adopted'], true)) throw new LogicException('Candidate adoption was not started.');
        // An independently launched memory worker must retain exact authority
        // after the next generation replaces the per-chapter latest snapshot.
        // Publish the small receipt first. A failure leaves the previous state
        // recoverable; only a caller which proved the DB commit may invoke this.
        $receipt = $this->writeAdoptionReceipt($next);
        if ($next['state'] === 'adopted') return;
        $next['state'] = 'adopted'; $next['stage'] = 'adopted';
        $next['adopted_at'] = $receipt['adopted_at'];
        $this->persist($this->touch($next));
    }

    /** Read-only durable authorization coordinates; no candidate prose is duplicated here. */
    public function adoptionReceipt(int $userId, int $novelId, int $chapterId, string $taskId): ?array
    {
        self::identity($userId, $chapterId, $taskId);
        if ($novelId < 1) throw new InvalidArgumentException('Invalid receipt novel identity.');
        $data = $this->readAdoptionReceipt($taskId);
        if ($data === null || $data['user_id'] !== $userId || $data['novel_id'] !== $novelId
            || $data['chapter_id'] !== $chapterId) return null;
        return $data;
    }

    public function snapshot(int $userId, int $chapterId, ?string $taskId = null, bool $includeContent = false): ?array
    {
        self::identity($userId, $chapterId, $taskId);
        if (!file_exists($this->lockPath($chapterId)) && !file_exists($this->statePath($chapterId))) return null;
        $handle = @fopen($this->lockPath($chapterId), 'rb');
        if ($handle === false) return $this->publicSnapshot($this->readState($chapterId), $userId, $taskId, null, $includeContent);
        $held = false;
        try {
            $wouldBlock = 0;
            $held = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
            if ($held) return $this->publicSnapshot($this->readState($chapterId), $userId, $taskId, false, $includeContent);
            // Recheck lock and JSON together after a worker may have exited.
            $wouldBlock = 0;
            $held = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
            return $this->publicSnapshot($this->readState($chapterId), $userId, $taskId,
                $held ? false : ($wouldBlock === 1 ? true : null), $includeContent);
        } finally { if ($held) @flock($handle, LOCK_UN); fclose($handle); }
    }

    public function close(): void
    {
        if (is_resource($this->lock)) { @flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null; $this->owned = null;
        // Never unlink the stable per-chapter lock: that would permit two owners.
    }

    public function __destruct() { $this->close(); }

    private function takeLock(int $chapterId, bool $create): bool
    {
        if ($this->lock !== null) throw new LogicException('This task store already owns a lock.');
        if ($create && !is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create rewrite task storage.');
        }
        if (!$create && !file_exists($this->lockPath($chapterId))) return false;
        $handle = @fopen($this->lockPath($chapterId), $create ? 'c+b' : 'r+b');
        if ($handle === false) throw new RuntimeException('Cannot open rewrite task lock.');
        $wouldBlock = 0;
        if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            if ($wouldBlock === 1) return false;
            throw new RuntimeException('Rewrite task locking is unavailable.');
        }
        $this->lock = $handle;
        return true;
    }

    private function requireOwned(): array
    {
        if ($this->lock === null || $this->owned === null) throw new LogicException('No rewrite task is owned.');
        return $this->owned;
    }

    private function persist(array $next): void
    {
        if ($this->lock === null) throw new LogicException('Cannot persist without the chapter lock.');
        $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 32);
        if (strlen($json) > self::MAX_JSON_BYTES) throw new InvalidArgumentException('Rewrite task JSON exceeds its size limit.');
        $this->persistJsonAtomically($this->statePath($next['chapter_id']), $json, 'Cannot persist rewrite task status');
        $this->owned = $next;
    }

    private function readState(int $chapterId): ?array
    {
        $handle = @fopen($this->statePath($chapterId), 'rb');
        if ($handle === false) return null;
        try { $raw = @stream_get_contents($handle, self::MAX_JSON_BYTES + 1); } finally { fclose($handle); }
        if (!is_string($raw) || strlen($raw) > self::MAX_JSON_BYTES) return null;
        $data = json_decode($raw, true, 32);
        if (!is_array($data) || ($data['chapter_id'] ?? null) !== $chapterId
            || !is_int($data['user_id'] ?? null) || $data['user_id'] < 1
            || !is_int($data['novel_id'] ?? null) || $data['novel_id'] < 1
            || !is_string($data['task_id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $data['task_id']) !== 1
            || !self::isHash($data['source_hash'] ?? null) || ($data['execution'] ?? null) !== 'cli'
            || !in_array($data['state'] ?? null, self::STATES, true) || !in_array($data['stage'] ?? null, self::STAGES, true)
            || !is_numeric($data['started_at'] ?? null) || !is_finite((float)$data['started_at']) || $data['started_at'] < 0
            || !is_int($data['updated_at'] ?? null) || $data['updated_at'] < 0
            || !is_numeric($data['elapsed_seconds'] ?? null) || !is_finite((float)$data['elapsed_seconds']) || $data['elapsed_seconds'] < 0
            || !is_int($data['launch_deadline'] ?? null) || $data['launch_deadline'] !== (int)floor((float)$data['started_at']) + 30
            || !is_bool($data['first_output_seen'] ?? null)) return null;
        foreach (['words', 'chunks'] as $key) {
            if (!is_int($data[$key] ?? null) || $data[$key] < 0 || $data[$key] > self::MAX_CONTENT_BYTES) return null;
        }
        if (isset($data['output_kind']) && !in_array($data['output_kind'], ['content', 'thinking'], true)) return null;
        if (isset($data['attempt']) && (!is_int($data['attempt']) || $data['attempt'] < 0 || $data['attempt'] > 1000)) return null;
        foreach (['model_switch', 'recheck_failed'] as $key) {
            if (isset($data[$key]) && !is_bool($data[$key])) return null;
        }
        if (in_array($data['state'], ['ready', 'adopting', 'adopted'], true)) {
            if (!is_string($data['content'] ?? null) || trim($data['content']) === '' || strlen($data['content']) > self::MAX_CONTENT_BYTES
                || $data['words'] < 1 || !self::isHash($data['candidate_hash'] ?? null)
                || !hash_equals($data['candidate_hash'], hash('sha256', $data['content']))) return null;
            foreach (['quality_before', 'quality_after'] as $key) {
                if (!array_key_exists($key, $data) || ($data[$key] !== null && !is_array($data[$key]))) return null;
            }
        } elseif (isset($data['content']) || isset($data['candidate_hash'])) return null;
        if ($data['state'] === 'adopted' && (!is_int($data['adopted_at'] ?? null) || $data['adopted_at'] < 0)) return null;
        if ($data['state'] === 'failed' && (!is_string($data['error_code'] ?? null)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $data['error_code']) !== 1
            || !is_string($data['message'] ?? null) || strlen($data['message']) > 1000)) return null;
        return $data;
    }

    private function writeAdoptionReceipt(array $task): array
    {
        $receipt = array_intersect_key($task, array_flip(['user_id', 'novel_id', 'chapter_id', 'task_id',
            'source_hash', 'candidate_hash', 'words']));
        $receipt['adopted_at'] = $task['adopted_at'] ?? (int)floor($this->now());
        $path = $this->receiptPath($task['task_id']);
        if (file_exists($path)) {
            $existing = $this->readAdoptionReceipt($task['task_id']);
            if ($existing === null || array_diff_assoc($receipt, array_replace($existing, ['adopted_at' => $receipt['adopted_at']])) !== []) {
                throw new RuntimeException('Existing adoption receipt does not match this candidate.');
            }
            return $existing;
        }
        $json = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->persistJsonAtomically($path, $json, 'Cannot persist adopted rewrite receipt');
        return $receipt;
    }

    /** Preserve old complete JSON/receipts while Windows briefly denies rename. */
    private function persistJsonAtomically(string $path, string $json, string $failure): void
    {
        $temp = $path . '.tmp.' . bin2hex(random_bytes(8));
        try {
            error_clear_last();
            if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException($failure . ': '
                    . (string)(error_get_last()['message'] ?? 'incomplete temporary JSON write'));
            }
            for ($attempt = 0; ; $attempt++) {
                $detail = null;
                set_error_handler(static function (int $severity, string $message) use (&$detail): bool {
                    $detail = $message;
                    return true;
                });
                try { $renamed = @rename($temp, $path); }
                finally { restore_error_handler(); }
                if ($renamed) return;
                $detail ??= 'JSON file rename failed without an OS error';
                // Both replacing status and publishing a first adoption receipt
                // can race a Windows scanner's temporary file-sharing handle.
                // Permanent path/permission failures and other OSes fail at once.
                $transient = PHP_OS_FAMILY === 'Windows'
                    && preg_match('/\(code:\s*(?:5|32|33)\)/i', $detail) === 1
                    && is_file($temp) && is_writable($temp) && is_writable(dirname($path))
                    && (!file_exists($path) || (is_file($path) && is_writable($path)));
                if (!$transient || $attempt >= 6) {
                    throw new RuntimeException($failure . ': ' . $detail);
                }
                usleep(min(100_000, 10_000 * (2 ** $attempt)));
            }
        } finally { if (is_file($temp)) @unlink($temp); }
    }

    private function readAdoptionReceipt(string $taskId): ?array
    {
        $handle = @fopen($this->receiptPath($taskId), 'rb');
        if ($handle === false) return null;
        try { $raw = @stream_get_contents($handle, 4097); } finally { fclose($handle); }
        if (!is_string($raw) || strlen($raw) > 4096) return null;
        $data = json_decode($raw, true, 4);
        if (!is_array($data) || ($data['task_id'] ?? null) !== $taskId
            || !self::isHash($data['source_hash'] ?? null) || !self::isHash($data['candidate_hash'] ?? null)
            || array_diff(array_keys($data), ['user_id', 'novel_id', 'chapter_id', 'task_id', 'source_hash', 'candidate_hash', 'words', 'adopted_at']) !== []) return null;
        foreach (['user_id', 'novel_id', 'chapter_id', 'words', 'adopted_at'] as $key) {
            if (!is_int($data[$key] ?? null) || $data[$key] < ($key === 'adopted_at' ? 0 : 1)) return null;
        }
        if ($data['words'] > self::MAX_CONTENT_BYTES) return null;
        return $data;
    }

    private function publicSnapshot(?array $data, int $userId, ?string $taskId, ?bool $active, bool $includeContent): ?array
    {
        if ($data === null) return ['task_id' => null, 'state' => 'unknown', 'stage' => 'unknown', 'active' => $active];
        if ($data['user_id'] !== $userId || ($taskId !== null && $data['task_id'] !== $taskId)) return null;
        $result = array_intersect_key($data, array_flip(['task_id', 'source_hash', 'state', 'stage', 'execution',
            'updated_at', 'elapsed_seconds', 'launch_deadline', 'words', 'chunks', 'first_output_seen',
            'output_kind', 'attempt', 'model_switch', 'recheck_failed', 'candidate_hash', 'error_code', 'message', 'adopted_at']));
        if ($data['state'] === 'running') {
            if ($active === false) $result['state'] = 'interrupted';
            elseif ($active === null) $result['state'] = 'unknown';
        } elseif ($data['state'] === 'queued') {
            if ($active === false && $this->liveQueue($data, $this->now())) $active = true;
            elseif ($active === false) {
                $result['state'] = 'failed'; $result['stage'] = 'failed';
                $result['error_code'] = 'launch_timeout'; $result['message'] = '后台改写任务启动超时，请重新生成。';
            } elseif ($active === null) $result['state'] = 'unknown';
        }
        if ($includeContent && in_array($data['state'], ['ready', 'adopting', 'adopted'], true)) {
            $result['content'] = $data['content'];
            $result['quality_before'] = self::quality($data['quality_before']);
            $result['quality_after'] = self::quality($data['quality_after']);
        }
        $result['active'] = $active;
        return $result;
    }

    private static function quality(?array $quality): ?array
    {
        if ($quality === null) return null;
        return array_intersect_key($quality, array_flip(['total_score', 'passes', 'gates', 'summary']));
    }
    private function liveQueue(?array $data, float $now): bool { return ($data['state'] ?? null) === 'queued' && $now < $data['launch_deadline']; }
    private function lockPath(int $chapterId): string { return $this->directory . '/chapter-' . $chapterId . '.lock'; }
    private function statePath(int $chapterId): string { return $this->directory . '/chapter-' . $chapterId . '.json'; }
    private function receiptPath(string $taskId): string { return $this->directory . '/adopted-' . $taskId . '.json'; }
    private function now(): float
    {
        $now = (float)($this->clock)();
        if (!is_finite($now) || $now < 0) throw new RuntimeException('Invalid rewrite task clock.');
        return $now;
    }
    private function touch(array $next): array
    {
        $now = $this->now(); $next['updated_at'] = (int)floor($now);
        $next['elapsed_seconds'] = round(max(0.0, $now - $next['started_at']), 3);
        return $next;
    }
    private static function isHash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private static function identity(int $userId, int $chapterId, ?string $taskId): void
    {
        if ($userId < 1 || $chapterId < 1 || ($taskId !== null && preg_match('/^[a-f0-9]{32}$/D', $taskId) !== 1)) {
            throw new InvalidArgumentException('Invalid rewrite task identity.');
        }
    }
}
