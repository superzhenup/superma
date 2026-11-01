<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RunContext.php';
require_once __DIR__ . '/RetryPolicy.php';
require_once __DIR__ . '/CancellationToken.php';
require_once __DIR__ . '/Deadline.php';
require_once __DIR__ . '/LeaseHeartbeat.php';

final readonly class CommitBoundary
{
    public function __construct(
        public int $chapterId,
        public string $draftHash
    ) {
        HarnessPersistenceValue::positiveInt($chapterId, 'commit_boundary.chapter_id');
        HarnessPersistenceValue::hash($draftHash, 'commit_boundary.draft_hash');
    }
}

/**
 * Business services must be constructor-injected into each Stage implementation.
 * They are intentionally absent from RunContext and therefore cannot leak into
 * a durable checkpoint.
 */
interface RunStage
{
    public function name(): string;

    public function type(): string;

    public function inputHash(RunContext $context): string;

    public function retryPolicy(): RetryPolicy;

    public function commitBoundary(RunContext $context): ?CommitBoundary;

    public function execute(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult;
}
