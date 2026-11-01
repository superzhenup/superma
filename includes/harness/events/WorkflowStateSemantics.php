<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/bootstrap.php';

final class WorkflowStateSemantics
{
    public static function runFromLegacy(string $state): GenerationRunState
    {
        return match ($state) {
            'queued' => GenerationRunState::QUEUED,
            'preparing', 'generating', 'validating', 'content_saved', 'postprocessing'
                => GenerationRunState::RUNNING,
            'done' => GenerationRunState::SUCCEEDED,
            'failed' => GenerationRunState::FAILED,
            'canceled' => GenerationRunState::CANCELED,
            default => throw new InvalidArgumentException('Unknown legacy writing task state.'),
        };
    }

    public static function pollStatus(GenerationRunState $state, bool $contentSaved): string
    {
        return match ($state) {
            GenerationRunState::SUCCEEDED,
            GenerationRunState::ABORTED_AFTER_COMMIT => 'done',
            GenerationRunState::FAILED,
            GenerationRunState::CANCELED => 'error',
            GenerationRunState::WAITING_APPROVAL => 'waiting_approval',
            GenerationRunState::QUARANTINED => 'quarantined',
            default => $contentSaved ? 'saved' : 'writing',
        };
    }

    public static function isLifecycleTerminal(GenerationRunState $state): bool
    {
        return $state->isTerminal();
    }

    public static function isCommitted(GenerationRunState $state): bool
    {
        return in_array($state, [
            GenerationRunState::SUCCEEDED,
            GenerationRunState::ABORTED_AFTER_COMMIT,
        ], true);
    }

    public static function isExecutionSettled(GenerationRunState $state): bool
    {
        return self::isLifecycleTerminal($state)
            || in_array($state, [
                GenerationRunState::WAITING_APPROVAL,
                GenerationRunState::QUARANTINED,
            ], true);
    }

    public static function isPollTerminal(string $status): bool
    {
        return in_array($status, [
            'done', 'error', 'waiting_approval', 'quarantined',
        ], true);
    }

    public static function isTransportSettled(GenerationRunState $state): bool
    {
        return self::isExecutionSettled($state);
    }

    private function __construct()
    {
    }
}
