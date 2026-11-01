<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

enum StoryBranchState: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}

final readonly class StoryBranchRecord
{
    public function __construct(
        public int $id,
        public string $branchUid,
        public int $userId,
        public int $novelId,
        public string $name,
        public bool $isMain,
        public StoryBranchState $state,
        public ?string $forkedFromBranchUid,
        public ?string $forkedFromRevisionUid
    ) {
        HarnessPersistenceValue::positiveInt($id, 'branch.id');
        HarnessPersistenceValue::uid($branchUid, 'branch.uid');
        HarnessPersistenceValue::positiveInt($userId, 'branch.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'branch.novel_id');
        HarnessPersistenceValue::string($name, 'branch.name', 1, 100);
        if ($forkedFromBranchUid !== null) {
            HarnessPersistenceValue::uid($forkedFromBranchUid, 'branch.forked_from_branch_uid');
        }
        if ($forkedFromRevisionUid !== null) {
            HarnessPersistenceValue::uid($forkedFromRevisionUid, 'branch.forked_from_revision_uid');
        }
    }
}

final readonly class ChapterRevisionRecord
{
    public function __construct(
        public int $id,
        public string $revisionUid,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public string $branchUid,
        public string $content,
        public string $contentHash,
        public ?string $parentRevisionUid,
        public ?string $mergeSourceRevisionUid,
        public string $sourceCandidateUid
    ) {
        HarnessPersistenceValue::positiveInt($id, 'revision.id');
        HarnessPersistenceValue::uid($revisionUid, 'revision.uid');
        HarnessPersistenceValue::positiveInt($userId, 'revision.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'revision.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'revision.chapter_id');
        HarnessPersistenceValue::uid($branchUid, 'revision.branch_uid');
        HarnessPersistenceValue::uid($sourceCandidateUid, 'revision.candidate_uid');
        HarnessPersistenceValue::hash($contentHash, 'revision.content_hash');
        if (!hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException('Immutable Revision content hash is invalid.');
        }
        foreach ([
            'parent_revision_uid' => $parentRevisionUid,
            'merge_source_revision_uid' => $mergeSourceRevisionUid,
        ] as $field => $uid) {
            if ($uid !== null) {
                HarnessPersistenceValue::uid($uid, "revision.{$field}");
            }
        }
    }
}

final readonly class BranchHead
{
    public function __construct(
        public string $branchUid,
        public int $chapterId,
        public ?string $revisionUid,
        public int $headGeneration
    ) {
        HarnessPersistenceValue::uid($branchUid, 'branch_head.branch_uid');
        HarnessPersistenceValue::positiveInt($chapterId, 'branch_head.chapter_id');
        if ($revisionUid !== null) {
            HarnessPersistenceValue::uid($revisionUid, 'branch_head.revision_uid');
        }
        HarnessPersistenceValue::nonNegativeInt($headGeneration, 'branch_head.generation');
    }
}

interface BranchRepository
{
    public function createBranchIdempotent(StoryBranchRecord $branch): StoryBranchRecord;
    public function findBranch(string $branchUid): ?StoryBranchRecord;
    public function findRevision(string $revisionUid): ?ChapterRevisionRecord;
    public function head(string $branchUid, int $chapterId): BranchHead;
    public function appendRevisionIdempotent(ChapterRevisionRecord $revision): ChapterRevisionRecord;

    /**
     * Atomically append an immutable Revision and CAS its Branch head. A stale
     * head returns null and must leave no orphan Revision behind.
     */
    public function appendAndAdvanceHead(
        ChapterRevisionRecord $revision,
        BranchHead $expected
    ): ?ChapterRevisionRecord;

    /** Compare-and-swap; stale head generations must return false. */
    public function advanceHead(
        BranchHead $expected,
        string $revisionUid
    ): bool;
}

final class BranchHeadCasMissException extends RuntimeException
{
}

final class BranchMemoryNamespace
{
    private function __construct()
    {
    }

    public static function forBranch(int $userId, int $novelId, string $branchUid): string
    {
        HarnessPersistenceValue::positiveInt($userId, 'memory_namespace.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'memory_namespace.novel_id');
        HarnessPersistenceValue::uid($branchUid, 'memory_namespace.branch_uid');
        return sprintf('u%d:n%d:b%s', $userId, $novelId, $branchUid);
    }

    public static function assertExact(
        string $namespace,
        int $userId,
        int $novelId,
        string $branchUid
    ): void {
        $expected = self::forBranch($userId, $novelId, $branchUid);
        if (!hash_equals($expected, $namespace)) {
            throw new RuntimeException('Branch memory namespace crossed its ownership scope.');
        }
    }
}
