<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

abstract class WorkflowRuntimeException extends RuntimeException
{
    public const ERROR_CODE = 'workflow_runtime_error';

    public function errorCode(): string
    {
        return static::ERROR_CODE;
    }
}

final class RunCanceledException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'run_canceled';

    public function __construct(
        string $message = 'Run cancellation was requested.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

final class DeadlineExceededException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'deadline_exceeded';

    public function __construct(
        string $message = 'The durable Run deadline was exceeded.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

final class LeaseLostException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'lease_lost';

    public function __construct(
        string $message = 'The durable Run or Stage lease was lost.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

final class RetryableModelException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'retryable_model_error';
}

final class RetryableInfrastructureException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'retryable_infrastructure_error';
}

final class ValidationBlockedException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'validation_blocked';
}

final class ApprovalRequiredException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'approval_required';
}

final class StaleRevisionException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'stale_revision';
}

final class PermanentStageException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'permanent_stage_error';
}

final class RunBudgetExceededException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'run_budget_exceeded';
}

final class WorkflowDefinitionException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'workflow_definition_invalid';
}

final class WorkflowCheckpointException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'workflow_checkpoint_invalid';
}

final class RuntimeControlException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'runtime_control_invalid';
}

final class CommitBoundaryException extends WorkflowRuntimeException
{
    public const ERROR_CODE = 'commit_boundary_invalid';
}
