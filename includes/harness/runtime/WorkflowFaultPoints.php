<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

final class WorkflowFaultPoints
{
    public const BEFORE_STAGE_EXECUTE = 'workflow.stage.before_execute';
    public const AFTER_STAGE_EXECUTE = 'workflow.stage.after_execute';
    public const AFTER_STAGE_CHECKPOINT = 'workflow.stage.after_checkpoint';
    public const OUTCOME_AFTER_ARTIFACT = 'workflow.outcome.after_artifact';
    public const OUTCOME_AFTER_STEP = 'workflow.outcome.after_step';
    public const OUTCOME_AFTER_EVENT = 'workflow.outcome.after_event';
    public const BEFORE_RUN_RELEASE = 'workflow.run.before_release';

    private function __construct()
    {
    }
}
