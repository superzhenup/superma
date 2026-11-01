<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/LegacyChapterStageAdapter.php';
require_once __DIR__ . '/LegacyAcquireStage.php';
require_once __DIR__ . '/LegacyContextStage.php';
require_once __DIR__ . '/LegacyPromptStage.php';
require_once __DIR__ . '/LegacyGenerateStage.php';
require_once __DIR__ . '/LegacyCommitStage.php';
require_once __DIR__ . '/LegacyPostprocessStage.php';
