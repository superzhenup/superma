<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Single-node request visibility and CLI admission; this does not resume killed work. */
final class ChapterOutlineRequestStore
{
    private const LAUNCH_WINDOW_SECONDS = 30;
    private const STAGES = [
        'init', 'authorized', 'model_check', 'context_prepare', 'memory_lookup', 'memory_ready',
        'model_request', 'model_response', 'parse_result', 'persist', 'base_saved',
        'enhancement_queue', 'save_receipt', 'response_finished', 'postprocess',
        'finished', 'rejected', 'failed', 'queued',
    ];
    private const STATES = ['queued', 'running', 'saved', 'failed', 'interrupted', 'unknown'];
    private const END_REASONS = ['php_fatal', 'finished', 'rejected', 'failed', 'request_exit', 'launch_failed'];
    private string $directory;
    private Closure $clock;
    /** @var resource|null */
    private $lock = null;
    private ?array $owned = null;

    public function __construct(?string $directory = null, ?callable $clock = null)
    {
        $this->directory = rtrim($directory ?? ((defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__))
            . '/storage/cache/chapter_outline_requests'), '/\\');
        if ($this->directory === '') throw new InvalidArgumentException('Request store directory is empty.');
        $this->clock = Closure::fromCallable($clock ?? static fn(): float => microtime(true));
    }

    /** False means confirmed contention. Unavailable storage/locking throws before any model call. */
    public function acquire(int $userId, int $novelId, string $requestId, int $start, int $end): bool
    {
        self::validateScope($userId, $novelId, $requestId);
        if ($start < 1 || $end < $start) throw new InvalidArgumentException('Invalid outline chapter range.');
        if (!$this->takeLock($novelId, true)) return false;
        try {
            $now = $this->now();
            if ($this->queueIsLive($this->readState($novelId), $now)) {
                $this->close();
                return false;
            }
            $this->owned = $this->initialState($userId, $novelId, $requestId, $start, $end, $now);
            $this->writeOwned();
            return true;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Reserve one CLI launch without keeping the HTTP process or its lock alive. */
    public function queue(int $userId, int $novelId, string $requestId, int $start, int $end): bool
    {
        self::validateScope($userId, $novelId, $requestId);
        if ($start < 1 || $end < $start) throw new InvalidArgumentException('Invalid outline chapter range.');
        if (!$this->takeLock($novelId, true)) return false;
        try {
            $now = $this->now();
            $previous = $this->readState($novelId);
            if ($this->queueIsLive($previous, $now) || ($previous['request_id'] ?? null) === $requestId) return false;
            $this->owned = $this->initialState($userId, $novelId, $requestId, $start, $end, $now);
            $this->owned['stage'] = 'queued';
            $this->owned['state'] = 'queued';
            $this->owned['execution'] = 'cli';
            $this->owned['launch_deadline'] = (int)floor($now) + self::LAUNCH_WINDOW_SECONDS;
            $this->writeOwned();
            return true;
        } finally {
            $this->close();
        }
    }

    /** Exact, unexpired queued identity only. Success owns the lock until close/exit. */
    public function claimQueued(int $novelId, string $requestId): ?array
    {
        self::validateScope(1, $novelId, $requestId);
        // Status reads briefly hold this same lock. Tolerate that narrow race,
        // but never wait for an already-running worker or retry an expired ID.
        // Use monotonic wall time here: an injected/frozen task clock must not
        // turn a bounded startup attempt into an infinite wait.
        $waitUntil = hrtime(true) + 500_000_000;
        while (!$this->takeLock($novelId, false)) {
            $pending = $this->readState($novelId);
            $remaining = $waitUntil - hrtime(true);
            if (($pending['request_id'] ?? null) !== $requestId || !$this->queueIsLive($pending, $this->now())
                || $remaining <= 0) return null;
            usleep(max(1, min(20_000, (int)($remaining / 1000))));
        }
        try {
            $now = $this->now();
            $pending = $this->readState($novelId);
            if (!$this->queueIsLive($pending, $now) || ($pending['request_id'] ?? null) !== $requestId) {
                $this->close();
                return null;
            }
            $this->owned = $pending;
            $this->owned['state'] = 'running';
            $this->owned['stage'] = 'authorized';
            $this->owned['updated_at'] = (int)floor($now);
            $this->owned['elapsed_seconds'] = round(max(0.0, $now - $pending['started_at']), 3);
            $this->writeOwned();
            return array_intersect_key($this->owned, array_flip([
                'user_id', 'novel_id', 'request_id', 'start_chapter', 'end_chapter', 'execution',
            ]));
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** A failed launcher cannot overwrite a worker which has already claimed. */
    public function failQueued(int $userId, int $novelId, string $requestId): bool
    {
        self::validateScope($userId, $novelId, $requestId);
        if (!$this->takeLock($novelId, false)) return false;
        try {
            $pending = $this->readState($novelId);
            if (($pending['state'] ?? null) !== 'queued' || ($pending['user_id'] ?? null) !== $userId
                || ($pending['request_id'] ?? null) !== $requestId) return false;
            $this->owned = $pending;
            $this->owned['state'] = 'failed';
            $this->owned['stage'] = 'failed';
            $this->owned['end_reason'] = 'launch_failed';
            $now = $this->now();
            $this->owned['updated_at'] = (int)floor($now);
            $this->owned['elapsed_seconds'] = round(max(0.0, $now - $pending['started_at']), 3);
            $this->writeOwned();
            return true;
        } finally {
            $this->close();
        }
    }

    private function initialState(int $userId, int $novelId, string $requestId, int $start, int $end, float $now): array
    {
        return [
            'user_id' => $userId, 'novel_id' => $novelId, 'request_id' => $requestId,
            'start_chapter' => $start, 'end_chapter' => $end,
            'stage' => 'authorized', 'state' => 'running', 'saved' => 0,
            'expected' => $end - $start + 1, 'actual_end' => $start - 1,
            'receipt_complete' => false, 'started_at' => $now,
            'updated_at' => (int)floor($now), 'elapsed_seconds' => 0.0,
            'execution' => 'http', 'first_output_seen' => false, 'shutdown_seen' => false,
        ];
    }

    private function queueIsLive(?array $record, float $now): bool
    {
        return ($record['state'] ?? null) === 'queued' && $now < $record['launch_deadline'];
    }

    private function takeLock(int $novelId, bool $create): bool
    {
        if ($this->lock !== null) throw new LogicException('This request store already owns a lock.');
        if ($create && !is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create chapter outline request storage.');
        }
        if (!$create && !file_exists($this->lockPath($novelId))) return false;
        $lock = @fopen($this->lockPath($novelId), $create ? 'c+b' : 'r+b');
        if ($lock === false) throw new RuntimeException('Cannot open chapter outline request lock.');
        $wouldBlock = 0;
        if (!@flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($lock);
            if ($wouldBlock === 1) return false;
            throw new RuntimeException('Chapter outline request locking is unavailable.');
        }
        $this->lock = $lock;
        return true;
    }

    /** Empty records are throttled by the caller and update liveness without changing the phase. */
    public function record(array $safeTraceRecord): void
    {
        if ($this->lock === null || $this->owned === null) {
            throw new LogicException('Cannot update an unowned outline request.');
        }
        foreach (['request_id', 'novel_id', 'user_id'] as $key) {
            if (array_key_exists($key, $safeTraceRecord) && $safeTraceRecord[$key] !== $this->owned[$key]) {
                throw new InvalidArgumentException('Outline trace does not belong to the owned request.');
            }
        }
        $next = $this->owned;
        if (($safeTraceRecord['event'] ?? null) === 'first_model_output'
            && in_array($safeTraceRecord['output_kind'] ?? null, ['thinking', 'content'], true)) {
            if (!($next['first_output_seen'] ?? false)) $next['output_kind'] = $safeTraceRecord['output_kind'];
            $next['first_output_seen'] = true;
        }
        if (($safeTraceRecord['event'] ?? null) === 'shutdown') {
            $next['shutdown_seen'] = true;
            if (($safeTraceRecord['first_output_seen'] ?? null) === true) $next['first_output_seen'] = true;
            if (in_array($safeTraceRecord['end_reason'] ?? null, self::END_REASONS, true)) {
                $next['end_reason'] = $safeTraceRecord['end_reason'];
            }
            if (is_int($safeTraceRecord['fatal_type'] ?? null)
                && in_array($safeTraceRecord['fatal_type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $next['fatal_type'] = $safeTraceRecord['fatal_type'];
            }
        }
        $stage = $safeTraceRecord['stage'] ?? null;
        if (is_string($stage) && $stage !== 'queued' && in_array($stage, self::STAGES, true)) $next['stage'] = $stage;
        if ($next['state'] !== 'saved') {
            foreach (['saved', 'actual_end'] as $key) {
                if (isset($safeTraceRecord[$key]) && is_int($safeTraceRecord[$key])) {
                    $next[$key] = $key === 'saved'
                        ? max(0, min($next['expected'], $safeTraceRecord[$key]))
                        : max($next['start_chapter'] - 1, min($next['end_chapter'], $safeTraceRecord[$key]));
                }
            }
        }
        if ($stage === 'save_receipt' && $next['state'] !== 'saved') {
            $next['state'] = 'saved';
            $next['receipt_complete'] = ($safeTraceRecord['receipt_complete'] ?? false) === true
                && ($safeTraceRecord['saved'] ?? null) === $next['expected']
                && (!array_key_exists('expected', $safeTraceRecord) || $safeTraceRecord['expected'] === $next['expected'])
                && is_int($safeTraceRecord['actual_end'] ?? null)
                && $safeTraceRecord['actual_end'] >= $next['end_chapter'];
        } elseif ($next['state'] !== 'saved') {
            if (in_array($stage, ['failed', 'rejected'], true)
                || (($safeTraceRecord['event'] ?? null) === 'shutdown'
                    && ($safeTraceRecord['end_reason'] ?? null) === 'php_fatal')) {
                $next['state'] = 'failed';
            }
        }
        $now = $this->now();
        $next['updated_at'] = (int)floor($now);
        $next['elapsed_seconds'] = round(max(0.0, $now - $next['started_at']), 3);
        $previous = $this->owned;
        $this->owned = $next;
        try {
            $this->writeOwned();
        } catch (Throwable $error) {
            $this->owned = $previous;
            throw $error;
        }
    }

    /** Read only: does not mutate files or steal a lock; only unclaimed launch intents expire. */
    public function snapshot(int $userId, int $novelId, ?string $requestId = null): ?array
    {
        self::validateScope($userId, $novelId, $requestId);
        $lockPath = $this->lockPath($novelId);
        $statePath = $this->statePath($novelId);
        if (!file_exists($lockPath) && !file_exists($statePath)) return null;
        $lock = @fopen($lockPath, 'rb');
        if ($lock === false) {
            return $this->publicSnapshot($this->readState($novelId), $userId, $requestId, null);
        }
        $held = false;
        try {
            $wouldBlock = 0;
            if (@flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                $held = true;
                // Keep exclusion until the JSON has been read: a successor cannot start between
                // observing an idle lock and deciding that this exact request was interrupted.
                return $this->publicSnapshot($this->readState($novelId), $userId, $requestId, false);
            }
            if ($wouldBlock !== 1) {
                return $this->publicSnapshot($this->readState($novelId), $userId, $requestId, null);
            }
            $before = $this->readState($novelId);
            $wouldBlock = 0;
            if (@flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                $held = true;
                // The writer may have ended and been replaced after our first read. Read again
                // under the lock rather than labeling the earlier request dead from stale data.
                return $this->publicSnapshot($this->readState($novelId), $userId, $requestId, false);
            }
            $after = $this->readState($novelId);
            $active = $wouldBlock === 1 ? true : null;
            if (($before['request_id'] ?? null) !== ($after['request_id'] ?? null)) {
                // The latest identity is authoritative; an exact request lookup will reject it.
                return $this->publicSnapshot($after, $userId, $requestId, $active);
            }
            return $this->publicSnapshot($after, $userId, $requestId, $active);
        } finally {
            if ($held) @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function close(): void
    {
        if (is_resource($this->lock)) {
            @flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
        $this->owned = null;
        // The stable lock file must never be unlinked, including after successful completion.
    }

    public function __destruct() { $this->close(); }

    private static function validateScope(int $userId, int $novelId, ?string $requestId): void
    {
        if ($userId < 1 || $novelId < 1 || ($requestId !== null && preg_match('/^[a-f0-9]{12}$/D', $requestId) !== 1)) {
            throw new InvalidArgumentException('Invalid outline request identity.');
        }
    }

    private function lockPath(int $novelId): string { return $this->directory . '/novel-' . $novelId . '.lock'; }
    private function statePath(int $novelId): string { return $this->directory . '/novel-' . $novelId . '.json'; }
    private function now(): float
    {
        $now = (float)($this->clock)();
        if (!is_finite($now)) throw new RuntimeException('Invalid outline request clock.');
        return $now;
    }

    private function writeOwned(): void
    {
        $path = $this->statePath($this->owned['novel_id']);
        $json = json_encode($this->owned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = $path . '.tmp.' . bin2hex(random_bytes(8));
        try {
            error_clear_last();
            if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
                $detail = (string)(error_get_last()['message'] ?? 'incomplete temporary status write');
                throw new RuntimeException('Cannot persist chapter outline request status: ' . $detail);
            }
            $this->replaceStateFile($temp, $path);
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    /** Readers must always see the old or new complete JSON, never a missing target. */
    private function replaceStateFile(string $temp, string $path): void
    {
        for ($attempt = 0; ; $attempt++) {
            $detail = null;
            // Capture locally: an application error handler can otherwise
            // consume the warning without populating error_get_last().
            set_error_handler(static function (int $severity, string $message) use (&$detail): bool {
                $detail = $message;
                return true;
            });
            try {
                $renamed = @rename($temp, $path);
            } finally {
                restore_error_handler();
            }
            if ($renamed) return;
            $detail ??= 'status file rename failed without an OS error';
            // Windows readers/scanners can briefly deny replacement even after
            // fclose. Retry only recognized replacement sharing/access errors,
            // only with both regular files present, and never delete the target.
            $transient = PHP_OS_FAMILY === 'Windows'
                && preg_match('/\(code:\s*(?:5|32|33)\)/i', $detail) === 1
                && is_file($temp) && is_file($path)
                && is_writable($temp) && is_writable($path) && is_writable(dirname($path));
            if (!$transient || $attempt >= 6) {
                throw new RuntimeException('Cannot persist chapter outline request status: ' . $detail);
            }
            usleep(min(100_000, 10_000 * (2 ** $attempt)));
        }
    }

    private function readState(int $novelId): ?array
    {
        $path = $this->statePath($novelId);
        $handle = @fopen($path, 'rb');
        if ($handle === false) return null;
        try { $raw = @stream_get_contents($handle, 16385); }
        finally { fclose($handle); }
        if (!is_string($raw) || strlen($raw) > 16384) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['novel_id'] ?? null) !== $novelId
            || !is_int($data['user_id'] ?? null) || $data['user_id'] < 1
            || !is_string($data['request_id'] ?? null) || preg_match('/^[a-f0-9]{12}$/D', $data['request_id']) !== 1
            || !in_array($data['stage'] ?? null, self::STAGES, true)
            || !in_array($data['state'] ?? null, self::STATES, true)) return null;
        foreach (['start_chapter', 'end_chapter', 'saved', 'expected', 'actual_end', 'updated_at'] as $key) {
            if (!is_int($data[$key] ?? null)) return null;
        }
        if ($data['start_chapter'] < 1 || $data['end_chapter'] < $data['start_chapter']
            || $data['expected'] !== $data['end_chapter'] - $data['start_chapter'] + 1
            || $data['saved'] < 0 || $data['saved'] > $data['expected']
            || $data['actual_end'] < $data['start_chapter'] - 1 || $data['actual_end'] > $data['end_chapter']
            || !is_bool($data['receipt_complete'] ?? null)
            || !is_numeric($data['started_at'] ?? null) || !is_finite((float)$data['started_at'])
            || !is_numeric($data['elapsed_seconds'] ?? null) || !is_finite((float)$data['elapsed_seconds'])) return null;
        if (isset($data['execution']) && !in_array($data['execution'], ['http', 'cli'], true)) return null;
        if ($data['state'] === 'queued' && (($data['execution'] ?? null) !== 'cli'
            || !is_int($data['launch_deadline'] ?? null)
            || $data['launch_deadline'] <= $data['started_at']
            || $data['launch_deadline'] > floor((float)$data['started_at']) + self::LAUNCH_WINDOW_SECONDS)) return null;
        foreach (['first_output_seen', 'shutdown_seen'] as $flag) {
            if (isset($data[$flag]) && !is_bool($data[$flag])) return null;
        }
        if (isset($data['output_kind']) && !in_array($data['output_kind'], ['thinking', 'content'], true)) return null;
        if (isset($data['end_reason']) && !in_array($data['end_reason'], self::END_REASONS, true)) return null;
        if (isset($data['fatal_type']) && (!is_int($data['fatal_type'])
            || !in_array($data['fatal_type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true))) return null;
        return $data;
    }

    private function publicSnapshot(?array $data, int $userId, ?string $requestId, ?bool $active): ?array
    {
        if ($data === null) {
            return [
                'request_id' => null, 'start_chapter' => null, 'end_chapter' => null,
                'stage' => 'unknown', 'state' => 'unknown', 'active' => $active,
                'saved' => 0, 'expected' => null, 'actual_end' => null, 'receipt_complete' => false,
                'updated_at' => null, 'elapsed_seconds' => null,
            ];
        }
        if ($data['user_id'] !== $userId || ($requestId !== null && $data['request_id'] !== $requestId)) return null;
        $state = $data['state'];
        if ($state === 'running' && $active === false) $state = 'interrupted';
        if ($active === null && $state === 'running') $state = 'unknown';
        if ($state === 'queued' && $active === false) {
            if ($this->queueIsLive($data, $this->now())) $active = true;
            else $state = 'interrupted';
        } elseif ($state === 'queued' && $active === null) {
            $state = 'unknown';
        }
        $result = array_intersect_key($data, array_flip([
            'request_id', 'start_chapter', 'end_chapter', 'stage', 'saved', 'expected',
            'actual_end', 'receipt_complete', 'updated_at', 'elapsed_seconds',
            'execution', 'launch_deadline', 'first_output_seen', 'output_kind',
            'shutdown_seen', 'end_reason', 'fatal_type',
        ]));
        $result['state'] = $state;
        $result['active'] = $active;
        return $result;
    }
}
