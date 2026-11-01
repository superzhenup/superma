<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchContracts.php';

final readonly class BranchMergeGateDecision
{
    /** @var list<array<string,mixed>> */
    public array $findings;

    /** @param list<array<string,mixed>> $findings */
    public function __construct(public bool $passed, array $findings = [])
    {
        HarnessSensitiveDataGuard::assertSafeStructure($findings, 'branch_merge.findings');
        $this->findings = $findings;
    }
}

interface BranchMergeHardGate
{
    public function evaluate(
        string $content,
        string $contentHash,
        ChapterRevisionRecord $source,
        ?ChapterRevisionRecord $target
    ): BranchMergeGateDecision;
}

enum MergeCandidateState: string
{
    case WAITING_APPROVAL = 'waiting_approval';
    case QUARANTINED = 'quarantined';
}

final readonly class MergeCandidateProposal
{
    /** @var list<array<string,mixed>> */
    public array $findings;

    /** @param list<array<string,mixed>> $findings */
    public function __construct(
        public string $candidateUid,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public string $targetBranchUid,
        public ?string $baseRevisionUid,
        public string $mergeSourceRevisionUid,
        public string $content,
        public string $contentHash,
        public MergeCandidateState $state,
        public bool $requiresApproval,
        array $findings
    ) {
        HarnessPersistenceValue::uid($candidateUid, 'merge.candidate_uid');
        HarnessPersistenceValue::uid($targetBranchUid, 'merge.target_branch_uid');
        HarnessPersistenceValue::uid($mergeSourceRevisionUid, 'merge.source_revision_uid');
        if ($baseRevisionUid !== null) {
            HarnessPersistenceValue::uid($baseRevisionUid, 'merge.base_revision_uid');
        }
        if (!$requiresApproval || !hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException('Merge proposal must remain approval-gated and hash-bound.');
        }
        $this->findings = $findings;
    }
}

interface MergeCandidateSink
{
    public function createIdempotent(MergeCandidateProposal $proposal): MergeCandidateProposal;
}

final class BranchMergeService
{
    public function __construct(
        private readonly BranchRepository $branches,
        private readonly BranchMergeHardGate $hardGate,
        private readonly MergeCandidateSink $sink
    ) {
    }

    public function propose(
        StoryBranchRecord $sourceBranch,
        StoryBranchRecord $targetBranch,
        ChapterRevisionRecord $sourceRevision,
        ?ChapterRevisionRecord $targetRevision,
        string $mergedContent
    ): MergeCandidateProposal {
        if ($sourceBranch->branchUid === $targetBranch->branchUid
            || $sourceBranch->userId !== $targetBranch->userId
            || $sourceBranch->novelId !== $targetBranch->novelId
            || $sourceBranch->state !== StoryBranchState::ACTIVE
            || $targetBranch->state !== StoryBranchState::ACTIVE
            || $sourceRevision->branchUid !== $sourceBranch->branchUid
            || $sourceRevision->userId !== $targetBranch->userId
            || $sourceRevision->novelId !== $targetBranch->novelId
            || ($targetRevision !== null
                && ($targetRevision->branchUid !== $targetBranch->branchUid
                    || $targetRevision->chapterId !== $sourceRevision->chapterId
                    || $targetRevision->userId !== $targetBranch->userId
                    || $targetRevision->novelId !== $targetBranch->novelId))) {
            throw new RuntimeException('Branch merge scope or source head is stale.');
        }
        $sourceHead = $this->branches->head(
            $sourceBranch->branchUid,
            $sourceRevision->chapterId
        );
        $targetHead = $this->branches->head(
            $targetBranch->branchUid,
            $sourceRevision->chapterId
        );
        if ($sourceHead->revisionUid !== $sourceRevision->revisionUid
            || $targetHead->revisionUid !== $targetRevision?->revisionUid) {
            throw new RuntimeException('Branch merge requires exact current source and target heads.');
        }
        $hash = hash('sha256', $mergedContent);
        $gate = $this->hardGate->evaluate(
            $mergedContent,
            $hash,
            $sourceRevision,
            $targetRevision
        );
        $baseUid = $targetRevision?->revisionUid;
        $candidateUid = substr(hash('sha256', implode("\0", [
            'branch-merge-candidate-v1',
            $targetBranch->branchUid,
            $baseUid ?? 'none',
            $sourceRevision->revisionUid,
            $hash,
        ])), 0, 32);
        $proposal = new MergeCandidateProposal(
            $candidateUid,
            $targetBranch->userId,
            $targetBranch->novelId,
            $sourceRevision->chapterId,
            $targetBranch->branchUid,
            $baseUid,
            $sourceRevision->revisionUid,
            $mergedContent,
            $hash,
            $gate->passed
                ? MergeCandidateState::WAITING_APPROVAL
                : MergeCandidateState::QUARANTINED,
            true,
            $gate->findings
        );
        // Deliberately no BranchRepository::advanceHead call here. Only the
        // approval + Canonical Commit path may promote this Candidate.
        return $this->sink->createIdempotent($proposal);
    }
}
