<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/LegacyChapterStageAdapter.php';

final class LegacyCommitStage extends LegacyChapterStage
{
    public function __construct(LegacyChapterStageAdapter $adapter)
    {
        parent::__construct($adapter, self::retryInfrastructure());
    }

    public function name(): string
    {
        return 'commit';
    }

    public function commitBoundary(RunContext $context): ?CommitBoundary
    {
        $draftHash = $context->attributes['draft_hash'] ?? null;
        if (!is_string($draftHash)) {
            throw new CommitBoundaryException(
                'Legacy commit Stage requires the generated draft hash checkpoint.'
            );
        }
        return new CommitBoundary($context->request->chapterId
            ?? throw new CommitBoundaryException('Chapter Run has no Chapter id.'), $draftHash);
    }

    public function execute(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->adapter->commit($context, $cancellation, $deadline, $heartbeat);
    }
}
