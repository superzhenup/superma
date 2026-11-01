<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__, 2) . '/includes/harness/stages/bootstrap.php';

/**
 * @param LegacyChapterStageAdapter $adapter Entry-specific legacy phase bridge.
 */
return static function (LegacyChapterStageAdapter $adapter): WorkflowDefinition {
    return new WorkflowDefinition(
        ChapterRunService::WORKFLOW_NAME,
        ChapterRunService::WORKFLOW_VERSION,
        [
            new LegacyAcquireStage($adapter),
            new LegacyContextStage($adapter),
            new LegacyPromptStage($adapter),
            new LegacyGenerateStage($adapter),
            new LegacyCommitStage($adapter),
            new LegacyPostprocessStage($adapter),
        ]
    );
};
