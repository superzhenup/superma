<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Small, content-free diagnostics for a single chapter-outline HTTP request. */
final class ChapterOutlineTrace
{
    private const STAGES = [
        'init', 'authorized', 'model_check', 'context_prepare', 'memory_lookup', 'memory_ready',
        'model_request', 'model_response', 'parse_result', 'persist', 'base_saved',
        'enhancement_queue', 'save_receipt', 'response_finished', 'postprocess',
        'finished', 'rejected', 'failed',
    ];
    private const METRICS = ['attempt', 'parsed', 'saved', 'expected', 'actual_end'];
    private Closure $logger;
    private Closure $clock;
    private ?Closure $observer = null;
    private float $startedAt;
    private string $requestId;
    private string $stage = 'init';
    private string $lastWorkStage = 'init';
    private array $scope = [];
    private bool $firstOutputSeen = false;
    private bool $responseFinished = false;
    private bool $shutdownRegistered = false;
    private bool $shutdownRecorded = false;

    public function __construct(string $requestId, ?callable $logger = null, ?callable $clock = null)
    {
        $this->requestId = preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $requestId) ? $requestId : 'unavailable';
        $this->logger = Closure::fromCallable($logger ?? static function (string $line): void { @error_log($line); });
        $this->clock = Closure::fromCallable($clock ?? static fn(): float => microtime(true));
        $this->startedAt = $this->now();
    }

    public function requestId(): string { return $this->requestId; }

    /** Observe the same content-free record without changing or replacing error logging. */
    public function setObserver(callable $observer): void
    {
        $this->observer = Closure::fromCallable($observer);
    }

    /** Call only after successful ownership checking; never accept raw request fields. */
    public function authorize(int $novelId, int $start, int $end): void
    {
        if ($novelId > 0 && $start > 0 && $end >= $start) {
            $this->scope = ['novel_id' => $novelId, 'start_chapter' => $start, 'end_chapter' => $end];
        }
    }

    public function phase(string $stage, array $metrics = []): void
    {
        if (!in_array($stage, self::STAGES, true)) return;
        $this->stage = $stage;
        if (!in_array($stage, ['finished', 'rejected', 'failed'], true)) $this->lastWorkStage = $stage;
        if ($stage === 'response_finished') $this->responseFinished = true;
        $safe = [];
        foreach (self::METRICS as $key) {
            if (isset($metrics[$key]) && is_int($metrics[$key]) && $metrics[$key] >= 0) {
                $safe[$key] = $metrics[$key];
            }
        }
        if (isset($metrics['receipt_complete']) && is_bool($metrics['receipt_complete'])) {
            $safe['receipt_complete'] = $metrics['receipt_complete'];
        }
        $this->record('stage', $safe);
    }

    /** One record for the entire request, including retries; never receives token text. */
    public function firstModelOutput(string $kind): void
    {
        if ($this->firstOutputSeen || !in_array($kind, ['thinking', 'content'], true)) return;
        $this->firstOutputSeen = true;
        $this->record('first_model_output', ['output_kind' => $kind]);
    }

    public function registerShutdown(): void
    {
        if ($this->shutdownRegistered) return;
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->shutdown(error_get_last(), connection_status());
        });
    }

    /** Log only: never emits SSE or labels a normal early exit as a PHP fatal error. */
    public function shutdown(?array $lastError, int $connectionStatus): void
    {
        if ($this->shutdownRecorded) return;
        $this->shutdownRecorded = true;
        $fatalType = $lastError['type'] ?? null;
        $isFatal = in_array($fatalType, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        $this->record('shutdown', [
            'end_reason' => $isFatal ? 'php_fatal' : (in_array($this->stage, ['finished', 'rejected', 'failed'], true) ? $this->stage : 'request_exit'),
            'fatal_type' => $isFatal ? $fatalType : null,
            'connection_status' => max(0, min(3, $connectionStatus)),
            'response_finished' => $this->responseFinished,
            'first_output_seen' => $this->firstOutputSeen,
            'last_work_stage' => $this->lastWorkStage,
        ]);
    }

    private function now(): float
    {
        try {
            $now = (float)($this->clock)();
            if (is_finite($now)) return $now;
        } catch (Throwable) {
            // Diagnostics cannot break generation, including injected test clocks.
        }
        return microtime(true);
    }

    private function record(string $event, array $fields): void
    {
        try {
            $record = array_merge([
                'request_id' => $this->requestId,
                'event' => $event,
                'stage' => $this->stage,
                'elapsed_seconds' => round(max(0, $this->now() - $this->startedAt), 3),
            ], $this->scope, $fields);
            $json = json_encode($record, JSON_UNESCAPED_SLASHES);
            try {
                if (is_string($json)) ($this->logger)('[chapter_outline] ' . $json);
            } catch (Throwable) {
                // An unavailable logger must not suppress the independent status observer.
            }
            try {
                if ($this->observer !== null) ($this->observer)($record);
            } catch (Throwable) {
                // Status visibility cannot interrupt generation or fatal-error reporting.
            }
        } catch (Throwable) {
            // A full log disk or a failing error handler must not interrupt generation.
        }
    }
}
