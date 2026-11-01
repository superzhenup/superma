<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';

enum GenerationRunState: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case RETRY_WAIT = 'retry_wait';
    case WAITING_APPROVAL = 'waiting_approval';
    case QUARANTINED = 'quarantined';
    case SUCCEEDED = 'succeeded';
    case ABORTED_AFTER_COMMIT = 'aborted_after_commit';
    case FAILED = 'failed';
    case CANCELED = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::SUCCEEDED,
            self::ABORTED_AFTER_COMMIT,
            self::FAILED,
            self::CANCELED,
        ], true);
    }
}

enum GenerationStepState: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case RETRY_WAIT = 'retry_wait';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case SKIPPED = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::CANCELED, self::SKIPPED], true);
    }
}

enum GenerationAttemptState: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELED = 'canceled';

    public function isTerminal(): bool
    {
        return $this !== self::RUNNING;
    }
}

final class GenerationStateMachine
{
    /** @var array<string,list<string>> */
    private const RUN_TRANSITIONS = [
        'queued' => ['running', 'failed', 'canceled'],
        'running' => [
            'retry_wait', 'waiting_approval', 'quarantined', 'succeeded',
            'aborted_after_commit', 'failed', 'canceled',
        ],
        'retry_wait' => ['running', 'failed', 'canceled'],
        'waiting_approval' => ['running', 'failed', 'canceled'],
        'quarantined' => ['waiting_approval', 'running', 'failed', 'canceled'],
        'succeeded' => [],
        'aborted_after_commit' => [],
        'failed' => [],
        'canceled' => [],
    ];

    /** @var array<string,list<string>> */
    private const STEP_TRANSITIONS = [
        'pending' => ['running', 'skipped', 'canceled'],
        'running' => ['retry_wait', 'succeeded', 'failed', 'canceled'],
        'retry_wait' => ['running', 'failed', 'canceled'],
        'succeeded' => [],
        'failed' => [],
        'canceled' => [],
        'skipped' => [],
    ];

    public static function assertRunTransition(GenerationRunState $from, GenerationRunState $to): void
    {
        self::assertTransition('Run', self::RUN_TRANSITIONS, $from->value, $to->value);
    }

    public static function assertStepTransition(GenerationStepState $from, GenerationStepState $to): void
    {
        self::assertTransition('Step', self::STEP_TRANSITIONS, $from->value, $to->value);
    }

    public static function assertAttemptTransition(
        GenerationAttemptState $from,
        GenerationAttemptState $to
    ): void {
        $allowed = $from === GenerationAttemptState::RUNNING
            ? [
                GenerationAttemptState::COMPLETED->value,
                GenerationAttemptState::FAILED->value,
                GenerationAttemptState::CANCELED->value,
            ]
            : [];
        if (!in_array($to->value, $allowed, true)) {
            throw new HarnessStateTransitionException(
                "Attempt transition {$from->value} -> {$to->value} is not allowed."
            );
        }
    }

    /** @param array<string,list<string>> $map */
    private static function assertTransition(string $kind, array $map, string $from, string $to): void
    {
        if (!in_array($to, $map[$from] ?? [], true)) {
            throw new HarnessStateTransitionException(
                "{$kind} transition {$from} -> {$to} is not allowed."
            );
        }
    }
}
