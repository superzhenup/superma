<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/OutlineEnhancementContracts.php';
require_once __DIR__ . '/OutlineEnhancementRunFactory.php';

final class GenerationRunOutlineGateway implements
    OutlineEnhancementRunStore,
    OutlineEnhancementMappedRunGateway
{
    public function __construct(private readonly GenerationRunRepository $runs)
    {
    }

    public function findByIdempotency(int $userId, string $idempotencyKey): ?GenerationRun
    {
        return $this->runs->findByIdempotency($userId, $idempotencyKey);
    }

    public function createIdempotent(GenerationRunDraft $draft): GenerationRun
    {
        return $this->runs->createIdempotent($draft);
    }

    public function claim(string $owner, int $leaseSeconds): ?GenerationRunLease
    {
        return $this->runs->claimNextWithEvents(
            OutlineEnhancementRunFactory::QUEUE,
            $owner,
            $leaseSeconds
        );
    }

    public function claimByUid(
        string $runUid,
        string $owner,
        int $leaseSeconds
    ): ?GenerationRunLease {
        return $this->runs->claimByUidWithEvents($runUid, $owner, $leaseSeconds);
    }

    public function findByUid(string $runUid): ?GenerationRun
    {
        return $this->runs->findByUid($runUid);
    }

    public function requestCancel(string $runUid): bool
    {
        return $this->runs->requestCancelWithEvents($runUid);
    }

    public function renew(GenerationRunLease $lease, int $leaseSeconds): GenerationRunLease
    {
        return $this->runs->renew($lease, $leaseSeconds);
    }

    public function release(
        GenerationRunLease $lease,
        GenerationRunState $next,
        int $progress,
        ?DateTimeImmutable $availableAt = null,
        ?array $result = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): GenerationRun {
        return $this->runs->releaseWithEvents(
            $lease,
            $next,
            $progress,
            $availableAt,
            $result,
            $errorCode,
            $errorMessage
        );
    }

    public function cancellationRequested(GenerationRunLease $lease): bool
    {
        $current = $this->runs->findByUid($lease->run->runUid);
        return $current === null
            || $current->cancelRequested
            || $current->state === GenerationRunState::CANCELED;
    }
}
