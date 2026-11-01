<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchContracts.php';

final class BranchService
{
    public function __construct(private readonly BranchRepository $repository)
    {
    }

    public function create(StoryBranchRecord $branch): StoryBranchRecord
    {
        if ($branch->isMain && $branch->forkedFromBranchUid !== null) {
            throw new InvalidArgumentException('The main Branch cannot be a fork.');
        }
        if (!$branch->isMain
            && ($branch->forkedFromBranchUid === null
                || $branch->forkedFromRevisionUid === null)) {
            throw new InvalidArgumentException('A fork requires its exact source Revision.');
        }
        return $this->repository->createBranchIdempotent($branch);
    }

    public function append(
        ChapterRevisionRecord $revision,
        BranchHead $expectedHead
    ): ChapterRevisionRecord {
        $branch = $this->repository->findBranch($revision->branchUid);
        if (!$branch instanceof StoryBranchRecord
            || $branch->state !== StoryBranchState::ACTIVE
            || $branch->userId !== $revision->userId
            || $branch->novelId !== $revision->novelId
            || $expectedHead->branchUid !== $branch->branchUid
            || $expectedHead->chapterId !== $revision->chapterId
            || $expectedHead->revisionUid !== $revision->parentRevisionUid) {
            throw new RuntimeException('Immutable Revision does not extend the exact owned Branch head.');
        }
        $stored = $this->repository->appendAndAdvanceHead($revision, $expectedHead);
        if (!$stored instanceof ChapterRevisionRecord) {
            throw new RuntimeException('Branch head changed before Revision promotion.');
        }
        return $stored;
    }
}

final readonly class BranchForkResult
{
    public function __construct(
        public StoryBranchRecord $branch,
        public ChapterRevisionRecord $seedRevision,
        public BranchHead $head
    ) {
        if ($branch->isMain
            || $seedRevision->branchUid !== $branch->branchUid
            || $head->branchUid !== $branch->branchUid
            || $head->chapterId !== $seedRevision->chapterId
            || $head->revisionUid === null
            || $head->headGeneration < 1) {
            throw new InvalidArgumentException('Fork result coordinates are inconsistent.');
        }
    }
}

/**
 * Recoverably creates a non-main Branch and seeds its first immutable Revision
 * with the exact fork bytes. The two repository transactions are deliberately
 * idempotent: a crash after Branch creation but before the seed head is repaired
 * by replay. A later legitimate descendant head is accepted only after its
 * immutable ancestry reaches the exact seed; a rogue head fails closed.
 */
final class BranchForkService
{
    private const MAX_ANCESTRY_DEPTH = 10000;

    public function __construct(private readonly BranchRepository $repository)
    {
    }

    public function createSeededFork(
        StoryBranchRecord $fork,
        ChapterRevisionRecord $sourceRevision
    ): BranchForkResult {
        if ($fork->isMain
            || $fork->forkedFromBranchUid !== $sourceRevision->branchUid
            || $fork->forkedFromRevisionUid !== $sourceRevision->revisionUid
            || $fork->userId !== $sourceRevision->userId
            || $fork->novelId !== $sourceRevision->novelId) {
            throw new InvalidArgumentException(
                'Seeded fork must bind the exact owned source Revision.'
            );
        }
        $storedBranch = (new BranchService($this->repository))->create($fork);
        $revisionUid = substr(hash('sha256', implode("\0", [
            'branch-fork-seed-revision-v1',
            $storedBranch->branchUid,
            $sourceRevision->revisionUid,
            $sourceRevision->contentHash,
        ])), 0, 32);
        $expectedRevision = new ChapterRevisionRecord(
            1,
            $revisionUid,
            $storedBranch->userId,
            $storedBranch->novelId,
            $sourceRevision->chapterId,
            $storedBranch->branchUid,
            $sourceRevision->content,
            $sourceRevision->contentHash,
            null,
            $sourceRevision->revisionUid,
            $sourceRevision->sourceCandidateUid
        );

        $head = $this->repository->head(
            $storedBranch->branchUid,
            $sourceRevision->chapterId
        );
        if ($head->revisionUid === null && $head->headGeneration === 0) {
            $storedRevision = (new BranchService($this->repository))->append(
                $expectedRevision,
                $head
            );
            $head = $this->repository->head(
                $storedBranch->branchUid,
                $sourceRevision->chapterId
            );
        } else {
            $storedRevision = $this->repository->findRevision($revisionUid);
            if (!$storedRevision instanceof ChapterRevisionRecord) {
                throw new RuntimeException(
                    'Seeded fork Revision is unavailable during idempotent replay.'
                );
            }
            self::assertExactSeed($expectedRevision, $storedRevision);
            $this->assertHeadDescendsFromSeed(
                $storedBranch,
                $storedRevision,
                $head
            );
        }

        return new BranchForkResult($storedBranch, $storedRevision, $head);
    }

    private static function assertExactSeed(
        ChapterRevisionRecord $expected,
        ChapterRevisionRecord $stored
    ): void {
        if ($expected->revisionUid !== $stored->revisionUid
            || $expected->userId !== $stored->userId
            || $expected->novelId !== $stored->novelId
            || $expected->chapterId !== $stored->chapterId
            || $expected->branchUid !== $stored->branchUid
            || !hash_equals($expected->contentHash, $stored->contentHash)
            || $expected->content !== $stored->content
            || $stored->parentRevisionUid !== null
            || $expected->mergeSourceRevisionUid !== $stored->mergeSourceRevisionUid
            || $expected->sourceCandidateUid !== $stored->sourceCandidateUid) {
            throw new HarnessIdempotencyConflictException(
                'Fork seed Revision UID resolved to different immutable coordinates.'
            );
        }
    }

    private function assertHeadDescendsFromSeed(
        StoryBranchRecord $branch,
        ChapterRevisionRecord $seed,
        BranchHead $head
    ): void {
        if ($head->revisionUid === null
            || $head->headGeneration < 1
            || $head->headGeneration > self::MAX_ANCESTRY_DEPTH) {
            throw new RuntimeException(
                'Seeded fork head depth is invalid for idempotent replay.'
            );
        }

        $revisionUid = $head->revisionUid;
        for ($distance = 0; $distance < $head->headGeneration; $distance++) {
            $revision = $this->repository->findRevision($revisionUid);
            if (!$revision instanceof ChapterRevisionRecord
                || $revision->userId !== $branch->userId
                || $revision->novelId !== $branch->novelId
                || $revision->chapterId !== $seed->chapterId
                || $revision->branchUid !== $branch->branchUid) {
                throw new RuntimeException(
                    'Seeded fork head ancestry crossed its immutable Branch scope.'
                );
            }
            if ($revision->revisionUid === $seed->revisionUid) {
                if ($distance !== $head->headGeneration - 1) {
                    throw new RuntimeException(
                        'Seeded fork head generation does not match its Revision ancestry.'
                    );
                }
                return;
            }
            if ($revision->parentRevisionUid === null) {
                break;
            }
            $revisionUid = $revision->parentRevisionUid;
        }

        throw new RuntimeException(
            'Seeded fork current head does not descend from the immutable seed Revision.'
        );
    }
}
