<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchService.php';

final readonly class BranchContinuationResult
{
    public function __construct(
        public string $candidateUid,
        public ChapterRevisionRecord $revision,
        public BranchHead $head,
        public bool $replayed = false
    ) {
        HarnessPersistenceValue::uid($candidateUid, 'branch_continuation.candidate_uid');
        if ($revision->revisionUid !== $head->revisionUid
            || $revision->branchUid !== $head->branchUid
            || $revision->chapterId !== $head->chapterId) {
            throw new InvalidArgumentException(
                'Branch continuation result coordinates are inconsistent.'
            );
        }
    }
}

/**
 * Persists a user-authored non-main continuation as an immutable Candidate and
 * Revision, then advances only that Branch head with a generation/revision CAS.
 * It never writes the canonical Chapter row or the main Branch head.
 */
final class BranchContinuationService
{
    private const MAX_REPLAY_ANCESTRY_DEPTH = 10000;

    private HarnessPersistenceDriver $db;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        private readonly BranchRepository $branches
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function continue(
        StoryBranchRecord $branch,
        ChapterRevisionRecord $base,
        int $expectedHeadGeneration,
        string $content,
        string $idempotencyKey
    ): BranchContinuationResult {
        if ($branch->isMain
            || $branch->state !== StoryBranchState::ACTIVE
            || $base->branchUid !== $branch->branchUid
            || $base->userId !== $branch->userId
            || $base->novelId !== $branch->novelId) {
            throw new InvalidArgumentException(
                'Continuation must extend an active, exact owned non-main Branch.'
            );
        }
        HarnessPersistenceValue::positiveInt(
            $expectedHeadGeneration,
            'branch_continuation.expected_head_generation'
        );
        if ($expectedHeadGeneration === PHP_INT_MAX) {
            throw new InvalidArgumentException(
                'Branch continuation head generation cannot advance safely.'
            );
        }
        if (preg_match('//u', $content) !== 1
            || trim($content) === ''
            || strlen($content) > 4 * 1024 * 1024) {
            throw new InvalidArgumentException(
                'Branch continuation content must be UTF-8, non-empty, and at most 4 MiB.'
            );
        }
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException(
                'Branch continuation idempotency key is invalid.'
            );
        }

        $contentHash = hash('sha256', $content);
        $candidateUid = substr(hash('sha256', implode("\0", [
            'branch-continuation-candidate-v1',
            (string)$branch->userId,
            (string)$branch->novelId,
            $branch->branchUid,
            $base->revisionUid,
            $idempotencyKey,
        ])), 0, 32);
        $revisionUid = substr(hash('sha256', implode("\0", [
            'branch-continuation-revision-v1',
            $candidateUid,
            $contentHash,
        ])), 0, 32);

        return $this->db->transactional(function () use (
            $branch,
            $base,
            $expectedHeadGeneration,
            $content,
            $contentHash,
            $candidateUid,
            $revisionUid
        ): BranchContinuationResult {
            $lockedBranch = $this->db->fetchOne(
                'SELECT branch_uid,user_id,novel_id,is_main,state '
                    . 'FROM story_branches WHERE branch_uid=? FOR UPDATE',
                [$branch->branchUid]
            );
            if (!is_array($lockedBranch)
                || (int)($lockedBranch['user_id'] ?? 0) !== $branch->userId
                || (int)($lockedBranch['novel_id'] ?? 0) !== $branch->novelId
                || (int)($lockedBranch['is_main'] ?? 1) !== 0
                || ($lockedBranch['state'] ?? null) !== StoryBranchState::ACTIVE->value) {
                throw new HarnessPersistenceException(
                    'Continuation Branch became stale or crossed ownership scope.'
                );
            }
            // Do not trust a caller-supplied immutable object merely because
            // its UID and owner coordinates look plausible.  Reload its bytes
            // inside the same transaction before deriving Candidate lineage.
            $storedBase = $this->branches->findRevision($base->revisionUid);
            if (!$storedBase instanceof ChapterRevisionRecord
                || !self::sameRevision($base, $storedBase)) {
                throw new HarnessPersistenceException(
                    'Continuation base Revision is stale, forged, or outside scope.'
                );
            }

            $validationJson = HarnessPersistenceValue::canonicalMysqlJson([
                'schema' => 'harness.branch_continuation.validation.v1',
                'expected_head_generation' => $expectedHeadGeneration,
                'canon_written' => false,
                'main_head_written' => false,
                'merge_requires_approval' => true,
            ]);
            $this->db->execute(
                "INSERT INTO chapter_candidates
                    (candidate_uid,user_id,novel_id,chapter_id,source,
                     target_branch_uid,base_revision_uid,base_revision_hash,
                     inline_content,content_hash,words,lifecycle,gate_state,
                     validation_json,evaluated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?, 'evaluated','passed',?,UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $candidateUid,
                    $branch->userId,
                    $branch->novelId,
                    $base->chapterId,
                    'branch_edit',
                    $branch->branchUid,
                    $base->revisionUid,
                    $base->contentHash,
                    $content,
                    $contentHash,
                    self::wordCount($content),
                    $validationJson,
                ]
            );
            $candidate = $this->db->fetchOne(
                'SELECT candidate_uid,user_id,novel_id,chapter_id,source,'
                    . 'target_branch_uid,base_revision_uid,base_revision_hash,'
                    . 'inline_content,content_hash,lifecycle,gate_state,'
                    . 'validation_json,evaluated_at,committed_at '
                    . 'FROM chapter_candidates WHERE candidate_uid=? FOR UPDATE',
                [$candidateUid]
            );
            $storedValidation = is_string($candidate['validation_json'] ?? null)
                ? json_decode(
                    $candidate['validation_json'],
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                )
                : null;
            if (!is_array($candidate)
                || ($candidate['candidate_uid'] ?? null) !== $candidateUid
                || (int)($candidate['user_id'] ?? 0) !== $branch->userId
                || (int)($candidate['novel_id'] ?? 0) !== $branch->novelId
                || (int)($candidate['chapter_id'] ?? 0) !== $base->chapterId
                || ($candidate['source'] ?? null) !== 'branch_edit'
                || ($candidate['target_branch_uid'] ?? null) !== $branch->branchUid
                || ($candidate['base_revision_uid'] ?? null) !== $base->revisionUid
                || !hash_equals(
                    $base->contentHash,
                    (string)($candidate['base_revision_hash'] ?? '')
                )
                || ($candidate['inline_content'] ?? null) !== $content
                || !hash_equals($contentHash, (string)($candidate['content_hash'] ?? ''))
                || !in_array(
                    $candidate['lifecycle'] ?? null,
                    ['evaluated', 'committed'],
                    true
                )
                || ($candidate['gate_state'] ?? null) !== 'passed'
                || !is_string($candidate['evaluated_at'] ?? null)
                || trim($candidate['evaluated_at']) === ''
                || !is_array($storedValidation)
                || HarnessPersistenceValue::canonicalMysqlJson($storedValidation)
                    !== $validationJson) {
                throw new HarnessIdempotencyConflictException(
                    'Branch continuation idempotency key resolved to different bytes or scope.'
                );
            }

            $revision = new ChapterRevisionRecord(
                1,
                $revisionUid,
                $branch->userId,
                $branch->novelId,
                $base->chapterId,
                $branch->branchUid,
                $content,
                $contentHash,
                $base->revisionUid,
                null,
                $candidateUid
            );
            $storedRevision = $this->branches->findRevision($revisionUid);
            if ($storedRevision instanceof ChapterRevisionRecord) {
                if (!self::sameRevision($revision, $storedRevision)
                    || ($candidate['lifecycle'] ?? null) !== 'committed'
                    || !is_string($candidate['committed_at'] ?? null)
                    || trim($candidate['committed_at']) === '') {
                    throw new HarnessIdempotencyConflictException(
                        'Replayed continuation is missing its exact committed Candidate and Revision.'
                    );
                }
                $liveHead = $this->branches->head(
                    $branch->branchUid,
                    $base->chapterId
                );
                $this->assertHeadDescendsFromReceipt(
                    $branch,
                    $storedRevision,
                    $expectedHeadGeneration + 1,
                    $liveHead
                );
                // Return the immutable promotion receipt, not the possibly
                // newer live head. This makes the same key permanently
                // replayable after legitimate descendants have been appended.
                return new BranchContinuationResult(
                    $candidateUid,
                    $storedRevision,
                    new BranchHead(
                        $branch->branchUid,
                        $base->chapterId,
                        $storedRevision->revisionUid,
                        $expectedHeadGeneration + 1
                    ),
                    true
                );
            }
            if (($candidate['lifecycle'] ?? null) !== 'evaluated'
                || ($candidate['committed_at'] ?? null) !== null) {
                throw new HarnessIdempotencyConflictException(
                    'Continuation Candidate lifecycle has no matching immutable Revision.'
                );
            }

            $head = $this->branches->head($branch->branchUid, $base->chapterId);
            if ($head->revisionUid !== $base->revisionUid
                || $head->headGeneration !== $expectedHeadGeneration) {
                throw new BranchHeadCasMissException(
                    'Continuation base is no longer the current Branch head.'
                );
            }
            $stored = $this->branches->appendAndAdvanceHead($revision, $head);
            if (!$stored instanceof ChapterRevisionRecord) {
                throw new BranchHeadCasMissException(
                    'Branch head changed before continuation promotion.'
                );
            }
            $advanced = $this->branches->head($branch->branchUid, $base->chapterId);
            if ($advanced->revisionUid !== $stored->revisionUid
                || $advanced->headGeneration !== $expectedHeadGeneration + 1) {
                throw new HarnessPersistenceException(
                    'Branch continuation did not produce the exact next head generation.'
                );
            }
            $committed = $this->db->execute(
                "UPDATE chapter_candidates
                 SET lifecycle='committed',committed_at=UTC_TIMESTAMP(6),
                     updated_at=UTC_TIMESTAMP(6)
                 WHERE candidate_uid=? AND user_id=? AND novel_id=? AND chapter_id=?
                   AND target_branch_uid=? AND base_revision_uid=?
                   AND base_revision_hash=? AND content_hash=?
                   AND lifecycle='evaluated' AND gate_state='passed'
                   AND committed_at IS NULL",
                [
                    $candidateUid,
                    $branch->userId,
                    $branch->novelId,
                    $base->chapterId,
                    $branch->branchUid,
                    $base->revisionUid,
                    $base->contentHash,
                    $contentHash,
                ]
            );
            if ($committed !== 1) {
                throw new HarnessPersistenceException(
                    'Branch continuation Candidate commit transition lost its CAS.'
                );
            }
            return new BranchContinuationResult($candidateUid, $stored, $advanced, false);
        });
    }

    private static function wordCount(string $content): int
    {
        if (function_exists('countWords')) {
            return max(0, (int)countWords($content));
        }
        return function_exists('mb_strlen')
            ? mb_strlen(preg_replace('/\s+/u', '', $content) ?? $content, 'UTF-8')
            : strlen(preg_replace('/\s+/', '', $content) ?? $content);
    }

    private function assertHeadDescendsFromReceipt(
        StoryBranchRecord $branch,
        ChapterRevisionRecord $receipt,
        int $receiptGeneration,
        BranchHead $head
    ): void {
        if ($head->revisionUid === null
            || $head->headGeneration < $receiptGeneration) {
            throw new HarnessPersistenceException(
                'Continuation replay head predates its immutable promotion receipt.'
            );
        }
        $distance = $head->headGeneration - $receiptGeneration;
        if ($distance > self::MAX_REPLAY_ANCESTRY_DEPTH) {
            throw new HarnessPersistenceException(
                'Continuation replay ancestry exceeds the bounded verification budget.'
            );
        }

        $revisionUid = $head->revisionUid;
        for ($step = 0; $step <= $distance; $step++) {
            $revision = $this->branches->findRevision($revisionUid);
            if (!$revision instanceof ChapterRevisionRecord
                || $revision->userId !== $branch->userId
                || $revision->novelId !== $branch->novelId
                || $revision->chapterId !== $receipt->chapterId
                || $revision->branchUid !== $branch->branchUid) {
                throw new HarnessPersistenceException(
                    'Continuation replay ancestry crossed its immutable Branch scope.'
                );
            }
            if ($step === $distance) {
                if ($revision->revisionUid !== $receipt->revisionUid) {
                    throw new HarnessPersistenceException(
                        'Continuation live head does not descend from its promotion receipt.'
                    );
                }
                return;
            }
            if ($revision->revisionUid === $receipt->revisionUid
                || $revision->parentRevisionUid === null) {
                throw new HarnessPersistenceException(
                    'Continuation replay generation contradicts its immutable ancestry.'
                );
            }
            $revisionUid = $revision->parentRevisionUid;
        }

        throw new HarnessPersistenceException(
            'Continuation replay ancestry could not authenticate its promotion receipt.'
        );
    }

    private static function sameRevision(
        ChapterRevisionRecord $expected,
        ChapterRevisionRecord $stored
    ): bool {
        return $expected->revisionUid === $stored->revisionUid
            && $expected->userId === $stored->userId
            && $expected->novelId === $stored->novelId
            && $expected->chapterId === $stored->chapterId
            && $expected->branchUid === $stored->branchUid
            && $expected->content === $stored->content
            && hash_equals($expected->contentHash, $stored->contentHash)
            && $expected->parentRevisionUid === $stored->parentRevisionUid
            && $expected->mergeSourceRevisionUid === $stored->mergeSourceRevisionUid
            && $expected->sourceCandidateUid === $stored->sourceCandidateUid;
    }
}
