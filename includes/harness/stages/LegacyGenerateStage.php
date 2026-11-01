<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/LegacyChapterStageAdapter.php';

final class LegacyGenerateStage extends LegacyChapterStage
{
    public function __construct(LegacyChapterStageAdapter $adapter)
    {
        parent::__construct($adapter, self::retryGeneration());
    }

    public function name(): string
    {
        return 'generate';
    }

    public function execute(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->adapter->generateDraft($context, $cancellation, $deadline, $heartbeat);
    }
}
