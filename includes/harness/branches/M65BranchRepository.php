<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchContracts.php';

final class M65BranchRepository implements BranchRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function createBranchIdempotent(StoryBranchRecord $branch): StoryBranchRecord
    {
        return $this->db->transactional(function () use ($branch): StoryBranchRecord {
            $this->assertNovelOwnership($branch->userId, $branch->novelId);
            if ($branch->forkedFromBranchUid !== null) {
                $source = $this->findBranchForUpdate($branch->forkedFromBranchUid);
                if (!$source instanceof StoryBranchRecord
                    || $source->userId !== $branch->userId
                    || $source->novelId !== $branch->novelId
                    || $source->state !== StoryBranchState::ACTIVE) {
                    throw new HarnessPersistenceException('Fork source Branch is outside scope.');
                }
            }
            if ($branch->forkedFromRevisionUid !== null) {
                $revision = $this->findRevisionForUpdate($branch->forkedFromRevisionUid);
                if (!$revision instanceof ChapterRevisionRecord
                    || $revision->userId !== $branch->userId
                    || $revision->novelId !== $branch->novelId
                    || $revision->branchUid !== $branch->forkedFromBranchUid) {
                    throw new HarnessPersistenceException('Fork source Revision is outside scope.');
                }
            }
            $this->db->execute(
                "INSERT INTO story_branches
                    (branch_uid,user_id,novel_id,name,is_main,state,
                     forked_from_branch_uid,forked_from_revision_uid)
                 VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id",
                [
                    $branch->branchUid,
                    $branch->userId,
                    $branch->novelId,
                    $branch->name,
                    $branch->isMain ? 1 : 0,
                    $branch->state->value,
                    $branch->forkedFromBranchUid,
                    $branch->forkedFromRevisionUid,
                ]
            );
            $stored = $this->findBranch($branch->branchUid);
            if (!$stored instanceof StoryBranchRecord
                || !$this->sameBranch($branch, $stored)) {
                throw new HarnessIdempotencyConflictException(
                    'Branch UID resolved to different immutable coordinates.'
                );
            }
            return $stored;
        });
    }

    public function findBranch(string $branchUid): ?StoryBranchRecord
    {
        $branchUid = HarnessPersistenceValue::uid($branchUid, 'branch_uid');
        $row = $this->db->fetchOne(
            'SELECT * FROM story_branches WHERE branch_uid=?',
            [$branchUid]
        );
        return $row === null ? null : $this->branchFromRow($row);
    }

    public function findRevision(string $revisionUid): ?ChapterRevisionRecord
    {
        $revisionUid = HarnessPersistenceValue::uid($revisionUid, 'revision_uid');
        $row = $this->db->fetchOne(
            'SELECT * FROM chapter_revisions WHERE revision_uid=?',
            [$revisionUid]
        );
        return $row === null ? null : $this->revisionFromRow($row);
    }

    public function head(string $branchUid, int $chapterId): BranchHead
    {
        $branchUid = HarnessPersistenceValue::uid($branchUid, 'head.branch_uid');
        $chapterId = HarnessPersistenceValue::positiveInt($chapterId, 'head.chapter_id');
        $branch = $this->findBranch($branchUid);
        if (!$branch instanceof StoryBranchRecord) {
            throw new HarnessPersistenceException('Branch head owner does not exist.');
        }
        $row = $this->db->fetchOne(
            'SELECT branch_uid,chapter_id,revision_uid,head_generation '
                . 'FROM chapter_branch_heads WHERE branch_uid=? AND chapter_id=?',
            [$branchUid, $chapterId]
        );
        return $row === null
            ? new BranchHead($branchUid, $chapterId, null, 0)
            : new BranchHead(
                HarnessPersistenceValue::uid($row['branch_uid'] ?? null, 'head.branch_uid'),
                HarnessPersistenceValue::positiveInt($row['chapter_id'] ?? null, 'head.chapter_id'),
                $row['revision_uid'] === null
                    ? null
                    : HarnessPersistenceValue::uid($row['revision_uid'], 'head.revision_uid'),
                HarnessPersistenceValue::nonNegativeInt(
                    $row['head_generation'] ?? null,
                    'head.generation'
                )
            );
    }

    public function appendRevisionIdempotent(
        ChapterRevisionRecord $revision
    ): ChapterRevisionRecord {
        return $this->db->transactional(function () use ($revision): ChapterRevisionRecord {
            $this->assertNovelOwnership($revision->userId, $revision->novelId);
            // Close the archive/append TOCTOU inside the transaction. A
            // Revision may never be appended to a Branch that became inactive
            // after the service performed its early scope check.
            $branch = $this->findBranchForUpdate($revision->branchUid);
            if (!$branch instanceof StoryBranchRecord
                || $branch->userId !== $revision->userId
                || $branch->novelId !== $revision->novelId
                || $branch->state !== StoryBranchState::ACTIVE) {
                throw new HarnessPersistenceException('Revision Branch is outside scope.');
            }
            $chapter = $this->db->fetchOne(
                'SELECT id FROM chapters WHERE id=? AND novel_id=?',
                [$revision->chapterId, $revision->novelId]
            );
            if ($chapter === null) {
                throw new HarnessPersistenceException('Revision Chapter is outside scope.');
            }
            foreach ([$revision->parentRevisionUid, $revision->mergeSourceRevisionUid] as $uid) {
                if ($uid === null) continue;
                $source = $this->findRevision($uid);
                if (!$source instanceof ChapterRevisionRecord
                    || $source->userId !== $revision->userId
                    || $source->novelId !== $revision->novelId
                    || $source->chapterId !== $revision->chapterId) {
                    throw new HarnessPersistenceException(
                        'Revision lineage crosses user, novel, or chapter scope.'
                    );
                }
            }
            $candidate = $this->db->fetchOne(
                'SELECT candidate_uid FROM chapter_candidates '
                    . 'WHERE candidate_uid=? AND user_id=? AND novel_id=? AND chapter_id=?',
                [
                    $revision->sourceCandidateUid,
                    $revision->userId,
                    $revision->novelId,
                    $revision->chapterId,
                ]
            );
            if ($candidate === null) {
                throw new HarnessPersistenceException('Revision Candidate is outside scope.');
            }
            $this->db->execute(
                "INSERT INTO chapter_revisions
                    (revision_uid,user_id,novel_id,chapter_id,branch_uid,content,
                     content_hash,parent_revision_uid,merge_source_revision_uid,
                     source_candidate_uid)
                 VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id",
                [
                    $revision->revisionUid,
                    $revision->userId,
                    $revision->novelId,
                    $revision->chapterId,
                    $revision->branchUid,
                    $revision->content,
                    $revision->contentHash,
                    $revision->parentRevisionUid,
                    $revision->mergeSourceRevisionUid,
                    $revision->sourceCandidateUid,
                ]
            );
            $stored = $this->findRevision($revision->revisionUid);
            if (!$stored instanceof ChapterRevisionRecord
                || !$this->sameRevision($revision, $stored)) {
                throw new HarnessIdempotencyConflictException(
                    'Revision UID resolved to different immutable bytes or lineage.'
                );
            }
            return $stored;
        });
    }

    public function advanceHead(BranchHead $expected, string $revisionUid): bool
    {
        $revisionUid = HarnessPersistenceValue::uid($revisionUid, 'head.new_revision_uid');
        return $this->db->transactional(function () use ($expected, $revisionUid): bool {
            $revision = $this->findRevision($revisionUid);
            if (!$revision instanceof ChapterRevisionRecord
                || $revision->branchUid !== $expected->branchUid
                || $revision->chapterId !== $expected->chapterId
                || $revision->parentRevisionUid !== $expected->revisionUid) {
                throw new HarnessPersistenceException(
                    'New Revision does not extend the expected Branch head.'
                );
            }
            if ($expected->headGeneration === 0 && $expected->revisionUid === null) {
                $inserted = $this->db->execute(
                    "INSERT INTO chapter_branch_heads
                        (branch_uid,chapter_id,revision_uid,head_generation)
                     VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE id=id",
                    [$expected->branchUid, $expected->chapterId, $revisionUid]
                );
                unset($inserted);
            } else {
                $advanced = $this->db->execute(
                    "UPDATE chapter_branch_heads
                     SET revision_uid=?,head_generation=head_generation+1,
                         updated_at=UTC_TIMESTAMP(6)
                     WHERE branch_uid=? AND chapter_id=? AND revision_uid=?
                       AND head_generation=?",
                    [
                        $revisionUid,
                        $expected->branchUid,
                        $expected->chapterId,
                        $expected->revisionUid,
                        $expected->headGeneration,
                    ]
                );
                if ($advanced !== 1) return false;
            }
            $current = $this->head($expected->branchUid, $expected->chapterId);
            return $current->revisionUid === $revisionUid
                && $current->headGeneration === $expected->headGeneration + 1;
        });
    }

    public function appendAndAdvanceHead(
        ChapterRevisionRecord $revision,
        BranchHead $expected
    ): ?ChapterRevisionRecord {
        try {
            return $this->db->transactional(function () use ($revision, $expected): ChapterRevisionRecord {
                if ($revision->branchUid !== $expected->branchUid
                    || $revision->chapterId !== $expected->chapterId
                    || $revision->parentRevisionUid !== $expected->revisionUid) {
                    throw new HarnessPersistenceException(
                        'Revision does not extend the supplied immutable Branch head.'
                    );
                }
                $stored = $this->appendRevisionIdempotent($revision);
                if ($this->advanceHead($expected, $stored->revisionUid)) {
                    return $stored;
                }
                // A retry after an already-successful identical promotion is
                // idempotent. Any other head is a true CAS miss and the outer
                // transaction rolls the newly inserted Revision back.
                $current = $this->head($expected->branchUid, $expected->chapterId);
                if ($current->revisionUid === $stored->revisionUid
                    && $current->headGeneration === $expected->headGeneration + 1) {
                    return $stored;
                }
                throw new BranchHeadCasMissException('Branch head CAS missed.');
            });
        } catch (BranchHeadCasMissException) {
            return null;
        }
    }

    private function assertNovelOwnership(int $userId, int $novelId): void
    {
        if ($this->db->fetchOne(
            'SELECT id FROM novels WHERE id=? AND user_id=?',
            [$novelId, $userId]
        ) === null) {
            throw new HarnessPersistenceException('Branch Novel is outside user scope.');
        }
    }

    private function findBranchForUpdate(string $branchUid): ?StoryBranchRecord
    {
        $branchUid = HarnessPersistenceValue::uid($branchUid, 'branch_uid');
        $row = $this->db->fetchOne(
            'SELECT * FROM story_branches WHERE branch_uid=? FOR UPDATE',
            [$branchUid]
        );
        return $row === null ? null : $this->branchFromRow($row);
    }

    private function findRevisionForUpdate(string $revisionUid): ?ChapterRevisionRecord
    {
        $revisionUid = HarnessPersistenceValue::uid($revisionUid, 'revision_uid');
        $row = $this->db->fetchOne(
            'SELECT * FROM chapter_revisions WHERE revision_uid=? FOR UPDATE',
            [$revisionUid]
        );
        return $row === null ? null : $this->revisionFromRow($row);
    }

    /** @param array<string,mixed> $row */
    private function branchFromRow(array $row): StoryBranchRecord
    {
        $state = is_string($row['state'] ?? null)
            ? StoryBranchState::tryFrom($row['state'])
            : null;
        if (!$state instanceof StoryBranchState) {
            throw new HarnessPersistenceException('Stored Branch state is invalid.');
        }
        return new StoryBranchRecord(
            HarnessPersistenceValue::positiveInt($row['id'] ?? null, 'branch.id'),
            HarnessPersistenceValue::uid($row['branch_uid'] ?? null, 'branch.uid'),
            HarnessPersistenceValue::positiveInt($row['user_id'] ?? null, 'branch.user_id'),
            HarnessPersistenceValue::positiveInt($row['novel_id'] ?? null, 'branch.novel_id'),
            HarnessPersistenceValue::string($row['name'] ?? null, 'branch.name', 1, 100),
            HarnessPersistenceValue::bool($row['is_main'] ?? null, 'branch.is_main'),
            $state,
            $row['forked_from_branch_uid'] === null
                ? null : HarnessPersistenceValue::uid($row['forked_from_branch_uid'], 'branch.fork'),
            $row['forked_from_revision_uid'] === null
                ? null : HarnessPersistenceValue::uid($row['forked_from_revision_uid'], 'branch.revision')
        );
    }

    /** @param array<string,mixed> $row */
    private function revisionFromRow(array $row): ChapterRevisionRecord
    {
        return new ChapterRevisionRecord(
            HarnessPersistenceValue::positiveInt($row['id'] ?? null, 'revision.id'),
            HarnessPersistenceValue::uid($row['revision_uid'] ?? null, 'revision.uid'),
            HarnessPersistenceValue::positiveInt($row['user_id'] ?? null, 'revision.user_id'),
            HarnessPersistenceValue::positiveInt($row['novel_id'] ?? null, 'revision.novel_id'),
            HarnessPersistenceValue::positiveInt($row['chapter_id'] ?? null, 'revision.chapter_id'),
            HarnessPersistenceValue::uid($row['branch_uid'] ?? null, 'revision.branch_uid'),
            HarnessPersistenceValue::string($row['content'] ?? null, 'revision.content', 0),
            HarnessPersistenceValue::hash($row['content_hash'] ?? null, 'revision.content_hash'),
            $row['parent_revision_uid'] === null
                ? null : HarnessPersistenceValue::uid($row['parent_revision_uid'], 'revision.parent'),
            $row['merge_source_revision_uid'] === null
                ? null : HarnessPersistenceValue::uid($row['merge_source_revision_uid'], 'revision.merge'),
            HarnessPersistenceValue::uid($row['source_candidate_uid'] ?? null, 'revision.candidate')
        );
    }

    private function sameBranch(StoryBranchRecord $a, StoryBranchRecord $b): bool
    {
        return $a->branchUid === $b->branchUid && $a->userId === $b->userId
            && $a->novelId === $b->novelId && $a->name === $b->name
            && $a->isMain === $b->isMain && $a->state === $b->state
            && $a->forkedFromBranchUid === $b->forkedFromBranchUid
            && $a->forkedFromRevisionUid === $b->forkedFromRevisionUid;
    }

    private function sameRevision(ChapterRevisionRecord $a, ChapterRevisionRecord $b): bool
    {
        return $a->revisionUid === $b->revisionUid && $a->userId === $b->userId
            && $a->novelId === $b->novelId && $a->chapterId === $b->chapterId
            && $a->branchUid === $b->branchUid && $a->content === $b->content
            && hash_equals($a->contentHash, $b->contentHash)
            && $a->parentRevisionUid === $b->parentRevisionUid
            && $a->mergeSourceRevisionUid === $b->mergeSourceRevisionUid
            && $a->sourceCandidateUid === $b->sourceCandidateUid;
    }
}
