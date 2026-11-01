<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/LegacyWritingTaskRunIdentity.php';

/**
 * A deliberately small, credential-free description of one legacy
 * writing_tasks transition. The durable adapter enriches it only with
 * whitelisted scalar state read back from the committed legacy row.
 */
final readonly class ShadowTrace
{
    public const QUEUED = 'queued';
    public const CLAIMED = 'claimed';
    public const HEARTBEAT = 'heartbeat';
    public const TERMINAL = 'terminal';
    public const CANCEL_REQUESTED = 'cancel_requested';

    /** @var list<string> */
    private const TRANSITIONS = [
        self::QUEUED,
        self::CLAIMED,
        self::HEARTBEAT,
        self::TERMINAL,
        self::CANCEL_REQUESTED,
    ];

    /** @var list<string> */
    private const LEGACY_STATES = [
        'queued',
        'preparing',
        'generating',
        'validating',
        'content_saved',
        'postprocessing',
        'done',
        'failed',
        'canceled',
    ];

    private function __construct(
        public string $taskId,
        public string $transition,
        public ?string $requestedState,
        public ?int $requestedProgress,
        public ?int $fenceGeneration
    ) {
        LegacyWritingTaskRunIdentity::assertTaskId($taskId);
        if (!in_array($transition, self::TRANSITIONS, true)) {
            throw new InvalidArgumentException('Shadow Trace transition is unknown.');
        }
        if ($requestedState !== null
            && !in_array($requestedState, self::LEGACY_STATES, true)) {
            throw new InvalidArgumentException('Shadow Trace legacy state is unknown.');
        }
        if ($requestedProgress !== null
            && ($requestedProgress < 0 || $requestedProgress > 100)) {
            throw new InvalidArgumentException('Shadow Trace progress must be between 0 and 100.');
        }
        if ($fenceGeneration !== null && $fenceGeneration < 1) {
            throw new InvalidArgumentException('Shadow Trace fence generation must be positive.');
        }
        if ($transition === self::TERMINAL
            && !in_array($requestedState, ['done', 'failed', 'canceled'], true)) {
            throw new InvalidArgumentException('Terminal Shadow Trace requires a terminal state.');
        }
    }

    public static function queued(string $taskId): self
    {
        return new self($taskId, self::QUEUED, 'queued', 0, null);
    }

    public static function claimed(string $taskId, int $fenceGeneration): self
    {
        return new self($taskId, self::CLAIMED, 'preparing', null, $fenceGeneration);
    }

    public static function heartbeat(
        string $taskId,
        ?string $state,
        ?int $progress,
        int $fenceGeneration
    ): self {
        return new self(
            $taskId,
            self::HEARTBEAT,
            $state,
            $progress === null ? null : max(0, min(100, $progress)),
            $fenceGeneration
        );
    }

    public static function terminal(
        string $taskId,
        string $state,
        int $fenceGeneration
    ): self {
        return new self($taskId, self::TERMINAL, $state, 100, $fenceGeneration);
    }

    public static function cancelRequested(string $taskId): self
    {
        return new self($taskId, self::CANCEL_REQUESTED, null, null, null);
    }

    /** The legacy task id is the exact durable Run idempotency coordinate. */
    public function runIdempotencyKey(): string
    {
        return LegacyWritingTaskRunIdentity::idempotencyKey($this->taskId);
    }

    public function runUid(): string
    {
        return LegacyWritingTaskRunIdentity::runUid($this->taskId);
    }

    public function inputHash(): string
    {
        return LegacyWritingTaskRunIdentity::inputHash($this->taskId);
    }

    public function eventType(): string
    {
        return 'legacy.writing_task.' . $this->transition;
    }

    /**
     * @return array{
     *   source:string,
     *   legacy_task_id:string,
     *   transition:string,
     *   legacy_state:string,
     *   progress:int,
     *   fence_generation:int,
     *   cancel_requested:bool,
     *   content_saved:bool
     * }
     */
    public function payload(
        string $legacyState,
        int $progress,
        int $observedFenceGeneration,
        bool $cancelRequested,
        bool $contentSaved
    ): array {
        if (!in_array($legacyState, self::LEGACY_STATES, true)) {
            throw new InvalidArgumentException('Observed legacy state is unknown.');
        }
        if ($progress < 0 || $progress > 100 || $observedFenceGeneration < 0) {
            throw new InvalidArgumentException('Observed Shadow Trace coordinates are invalid.');
        }

        return [
            'source' => 'writing_tasks',
            'legacy_task_id' => $this->taskId,
            'transition' => $this->transition,
            'legacy_state' => $legacyState,
            'progress' => $progress,
            'fence_generation' => $observedFenceGeneration,
            'cancel_requested' => $cancelRequested,
            'content_saved' => $contentSaved,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function eventKey(array $payload): string
    {
        if (!$this->hasStableLifecycleIdentity()) {
            return $this->legacyEventKey($payload);
        }
        $fence = $payload['fence_generation'] ?? null;
        if (($payload['source'] ?? null) !== 'writing_tasks'
            || ($payload['legacy_task_id'] ?? null) !== $this->taskId
            || ($payload['transition'] ?? null) !== $this->transition
            || !is_int($fence) || $fence < 0
            || ($this->fenceGeneration !== null && $fence !== $this->fenceGeneration)) {
            throw new InvalidArgumentException('Shadow lifecycle Event identity is inconsistent.');
        }
        return 'legacy-writing:' . $this->transition . ':' . $this->taskId . ':' . $fence;
    }

    public function hasStableLifecycleIdentity(): bool
    {
        // Heartbeats represent distinct samples, even within one lease fence.
        return $this->transition !== self::HEARTBEAT;
    }

    /** Pre-cutover keys remain readable; never rewrite committed Event identities.
     * @param array<string,mixed> $payload
     */
    public function legacyEventKey(array $payload): string
    {
        $canonical = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return 'legacy-writing:' . $this->transition . ':'
            . substr(hash('sha256', $canonical), 0, 40);
    }
}
