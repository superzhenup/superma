<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/context/bootstrap.php';
require_once __DIR__ . '/PromptPackage.php';
require_once __DIR__ . '/AuthorityContextPromptRenderer.php';
require_once __DIR__ . '/LegacyPromptCompiler.php';
require_once __DIR__ . '/SkillPromptDecorator.php';
