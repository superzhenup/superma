<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/data.php';
require_once __DIR__ . '/ChapterCanonTransition.php';
require_once dirname(__DIR__) . '/harness/branches/M65CanonicalProjection.php';
require_once dirname(__DIR__) . '/harness/candidates/ApprovalRequest.php';

/**
 * The sole application entry point for promoting an AI-generated Candidate
 * into chapter Canon.
 *
 * ChapterCanonTransition remains the low-level transaction implementation so
 * its persistence seam can be tested in isolation. Production callers must
 * enter through this service; consequently every accepted path shares the
 * same lock ordering, base-revision CAS and strict-quarantine behaviour.
 */
final class CanonicalCommitService
{
    public function __construct(
        private readonly ChapterCanonTransition $transition = new ChapterCanonTransition(),
        private readonly M65CanonicalProjection $m65Projection = new M65CanonicalProjection()
    ) {
    }

    /**
     * @param callable(array<string,mixed>):void|null $beforePromotion
     * @return array{
     *   committed:bool,
     *   quarantined:bool,
     *   accepted_content:string,
     *   revision_hash:string,
     *   candidate_uid:string,
     *   decision:QualityGateDecision
     * }
     */
    public function commitCandidate(
        int $userId,
        int $novelId,
        int $chapterId,
        string $candidateUid,
        string $claimToken,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision,
        ?callable $beforePromotion = null
    ): array {
        return $this->transition->applyDecision(
            $userId,
            $novelId,
            $chapterId,
            $candidateUid,
            $claimToken,
            $policySnapshot,
            $decision,
            function (array $context) use ($decision, $beforePromotion): void {
                // Branch-aware Candidates must move their immutable Revision,
                // Branch head, Canonical head, and legacy chapters projection
                // in this one transaction. Legacy Candidates have no target
                // Branch and this call is deliberately a no-op for them.
                $this->m65Projection->project($context, $decision, false);
                if ($beforePromotion !== null) {
                    $beforePromotion($context);
                }
            }
        );
    }

    /**
     * Atomically adopt a human-approved quarantined Candidate. The temporary
     * chapter claim is acquired and consumed inside ChapterCanonTransition's
     * transaction; the approval waiting period therefore holds no worker,
     * Run lease, or Chapter claim.
     *
     * @return array<string,mixed>
     */
    public function commitApprovedApproval(int $userId, string $approvalUid): array
    {
        if ($userId <= 0 || preg_match('/^[a-f0-9]{32}$/D', $approvalUid) !== 1) {
            throw new InvalidArgumentException('Approved Canon coordinates are invalid.');
        }
        require_once dirname(__DIR__) . '/db.php';
        require_once dirname(__DIR__) . '/tasks/PostprocessJobRepository.php';
        require_once dirname(__DIR__) . '/harness/persistence/ChapterCommitLedgerRepository.php';

        $owned = DB::fetch(
            "SELECT ar.id AS approval_id, ar.state AS approval_state,
                    ar.action_type AS approval_action_type,
                    ar.user_id, ar.novel_id, ar.chapter_id, ar.run_id, ar.candidate_id,
                    ar.base_revision_hash AS approval_base_revision_hash,
                    cc.candidate_uid, cc.lifecycle, cc.gate_state,
                    cc.inline_content, cc.content_hash, cc.words,
                    cc.base_revision_hash, cc.base_snapshot_json,
                    cc.policy_snapshot_json, cc.policy_snapshot_hash,
                    cc.validation_json, cc.draft_artifact_id, cc.validation_artifact_id,
                    n.user_id AS novel_user_id
             FROM approval_requests ar
             INNER JOIN chapter_candidates cc ON cc.id=ar.candidate_id
             INNER JOIN novels n ON n.id=ar.novel_id
             WHERE ar.user_id=? AND ar.approval_uid=? LIMIT 1",
            [$userId, $approvalUid]
        );
        if (!is_array($owned)
            || (int)$owned['novel_user_id'] !== $userId
            || (string)$owned['approval_state'] !== 'approved'
            || (string)$owned['approval_action_type'] !== ApprovalActionType::FORCE_COMMIT->value
            || !in_array((string)$owned['lifecycle'], ['approved', 'committed'], true)
            || (string)$owned['gate_state'] !== 'quarantined') {
            throw new ChapterCanonTransitionException(
                'Approval is not an owned, resolved force-commit authorization.'
            );
        }

        $policy = WritePolicySnapshot::fromJson((string)$owned['policy_snapshot_json']);
        if (!hash_equals($policy->checksum(), (string)$owned['policy_snapshot_hash'])) {
            throw new ChapterCanonTransitionException('Approved Candidate policy is invalid.');
        }
        $decision = QualityGateDecision::fromJson((string)$owned['validation_json']);
        $base = json_decode((string)$owned['base_snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($base)
            || !is_string($base['claim_token'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $base['claim_token']) !== 1) {
            throw new ChapterCanonTransitionException('Approved Candidate base claim is invalid.');
        }

        $candidateId = (int)$owned['candidate_id'];
        $chapterId = (int)$owned['chapter_id'];
        $novelId = (int)$owned['novel_id'];
        $runId = (int)$owned['run_id'];
        $candidateUid = (string)$owned['candidate_uid'];
        $contentHash = (string)$owned['content_hash'];
        $existingLedger = (new ChapterCommitLedgerRepository(DB::getPdo()))
            ->findByCandidate($candidateId);
        if ($existingLedger !== null) {
            $chapter = DB::fetch(
                'SELECT content, content_revision_hash, canonical_candidate_uid '
                . 'FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                [$chapterId, $novelId]
            );
            if (!is_array($chapter)
                || !hash_equals((string)$chapter['content_revision_hash'], $contentHash)
                || !hash_equals((string)$chapter['canonical_candidate_uid'], $candidateUid)
                || !hash_equals(hash('sha256', (string)$chapter['content']), $contentHash)) {
                throw new ChapterCanonTransitionException(
                    'Approved commit ledger disagrees with current Canon.'
                );
            }
            self::clearCommittedCaches($chapterId, $novelId);
            return [
                'committed' => true,
                'quarantined' => false,
                'accepted_content' => (string)$chapter['content'],
                'revision_hash' => $contentHash,
                'candidate_uid' => $candidateUid,
                'decision' => $decision,
                'postprocess_job_id' => $existingLedger->postprocessJobId,
            ];
        }
        if ((string)$owned['lifecycle'] !== 'approved') {
            throw new ChapterCanonTransitionException(
                'Committed approved Candidate is missing its idempotency ledger.'
            );
        }

        $postprocessJobId = 0;
        $result = $this->transition->applyDecision(
            $userId,
            $novelId,
            $chapterId,
            $candidateUid,
            (string)$base['claim_token'],
            $policy,
            $decision,
            function (array $context) use (
                $userId,
                $approvalUid,
                $candidateId,
                $chapterId,
                $novelId,
                $runId,
                $candidateUid,
                $contentHash,
                $policy,
                $decision,
                &$postprocessJobId
            ): void {
                $authorization = DB::fetch(
                    "SELECT ar.state, ar.action_type, ar.user_id, ar.novel_id, ar.chapter_id,
                            ar.run_id, ar.candidate_id,
                            r.user_id AS run_user_id, r.novel_id AS run_novel_id,
                            r.chapter_id AS run_chapter_id,
                            da.run_id AS draft_run_id, da.content_hash AS draft_hash,
                            va.run_id AS validation_run_id
                     FROM approval_requests ar
                     INNER JOIN generation_runs r ON r.id=ar.run_id
                     INNER JOIN chapter_candidates cc ON cc.id=ar.candidate_id
                     INNER JOIN generation_artifacts da ON da.id=cc.draft_artifact_id
                     INNER JOIN generation_artifacts va ON va.id=cc.validation_artifact_id
                     WHERE ar.approval_uid=? AND ar.user_id=? LIMIT 1 FOR UPDATE",
                    [$approvalUid, $userId]
                );
                if (!is_array($authorization)
                    || (string)$authorization['state'] !== 'approved'
                    || (string)$authorization['action_type']
                        !== ApprovalActionType::FORCE_COMMIT->value
                    || (int)$authorization['novel_id'] !== $novelId
                    || (int)$authorization['chapter_id'] !== $chapterId
                    || (int)$authorization['run_id'] !== $runId
                    || (int)$authorization['candidate_id'] !== $candidateId
                    || (int)$authorization['run_user_id'] !== $userId
                    || (int)$authorization['run_novel_id'] !== $novelId
                    || (int)$authorization['run_chapter_id'] !== $chapterId
                    || (int)$authorization['draft_run_id'] !== $runId
                    || (int)$authorization['validation_run_id'] !== $runId
                    || !hash_equals((string)$authorization['draft_hash'], $contentHash)
                    || (int)($context['candidate']['id'] ?? 0) !== $candidateId
                    || (string)($context['candidate']['lifecycle'] ?? '') !== 'approved') {
                    throw new ChapterCanonTransitionException(
                        'Approved Candidate authorization changed before Canon promotion.'
                    );
                }

                // This runs only after the Approval row and its Run/Artifact
                // lineage have been locked and re-authenticated. Any M65 stale
                // head or immutable-byte conflict now aborts this entire Canon
                // transaction, including the legacy chapter projection below.
                $this->m65Projection->project($context, $decision, true);

                backupChapterVersion($chapterId, true);
                $oldWords = (int)($context['chapter']['words'] ?? 0);
                $newWords = countWords((string)$context['result']['accepted_content']);
                $baseState = (array)($context['base_snapshot'] ?? []);
                $chapterDelta = ($baseState['canonical_state'] ?? 'none') === 'none' ? 1 : 0;
                $statsChanged = DB::execute(
                    'UPDATE novels SET current_chapter=GREATEST(0,current_chapter+?), '
                    . 'total_words=GREATEST(0,total_words+?) WHERE id=? AND user_id=?',
                    [$chapterDelta, $newWords - $oldWords, $novelId, $userId]
                );
                if ($statsChanged !== 1) {
                    throw new ChapterCanonTransitionException(
                        'Approved Candidate could not update novel statistics.'
                    );
                }

                $postprocessJobId = PostprocessJobRepository::enqueue(
                    $novelId,
                    $chapterId,
                    $contentHash,
                    'full',
                    3,
                    $policy,
                    $candidateUid,
                    true
                );
                if ($postprocessJobId <= 0) {
                    throw new ChapterCanonTransitionException(
                        'Approved Candidate could not enqueue post-processing.'
                    );
                }
                (new ChapterCommitLedgerRepository(DB::getPdo()))->recordIdempotent(
                    new ChapterCommitDraft(
                        $runId,
                        $chapterId,
                        $contentHash,
                        is_string($context['base_snapshot']['content_revision_hash'] ?? null)
                            ? $context['base_snapshot']['content_revision_hash']
                            : null,
                        $contentHash,
                        $postprocessJobId,
                        $candidateId
                    )
                );
            },
            true
        );
        $result['postprocess_job_id'] = $postprocessJobId;
        self::clearCommittedCaches($chapterId, $novelId);
        return $result;
    }

    /**
     * Commit a Run-less M65 Branch merge after its dedicated Approval has
     * resolved. This contract is intentionally independent from force_commit:
     * it authenticates immutable Revision lineage instead of pretending that
     * a Generation Run or Artifact exists.
     *
     * @return array<string,mixed>
     */
    public function commitApprovedBranchMerge(int $userId, string $approvalUid): array
    {
        if ($userId <= 0 || preg_match('/^[a-f0-9]{32}$/D', $approvalUid) !== 1) {
            throw new InvalidArgumentException('Approved Branch merge coordinates are invalid.');
        }
        require_once dirname(__DIR__) . '/tasks/PostprocessJobRepository.php';
        require_once dirname(__DIR__)
            . '/harness/branches/BranchMergeCommitLedgerRepository.php';

        $preflight = DB::fetch(
            'SELECT novel_id FROM approval_requests '
                . 'WHERE user_id=? AND approval_uid=? AND action_type=? LIMIT 1',
            [$userId, $approvalUid, ApprovalActionType::BRANCH_MERGE->value]
        );
        if (!is_array($preflight) || (int)($preflight['novel_id'] ?? 0) <= 0) {
            throw new ChapterCanonTransitionException(
                'Owned Branch merge Approval does not exist.'
            );
        }
        $novelId = (int)$preflight['novel_id'];
        $projectionFence = NovelProjectionFence::acquire($novelId, 30);
        try {
            if (DB::inTransaction() || !DB::beginTransaction() || !DB::inTransaction()) {
                throw new ChapterCanonTransitionException(
                    'Unable to own the approved Branch merge transaction.'
                );
            }
            try {
                $row = DB::fetch(
                    "SELECT ar.id AS approval_id,ar.state AS approval_state,
                            ar.action_type AS approval_action_type,
                            ar.user_id AS approval_user_id,
                            ar.novel_id AS approval_novel_id,
                            ar.chapter_id AS approval_chapter_id,
                            ar.run_id AS approval_run_id,
                            ar.candidate_id AS approval_candidate_id,
                            ar.base_revision_hash AS approval_base_revision_hash,
                            ar.policy_snapshot_json AS approval_policy_json,
                            ar.policy_snapshot_hash AS approval_policy_hash,
                            ar.decision_payload_hash AS approval_decision_hash,
                            ar.decision_idempotency_key AS approval_decision_key,
                            ar.decided_by AS approval_decided_by,
                            ar.decided_at AS approval_decided_at,
                            cc.*,n.user_id AS novel_user_id,
                            ch.id AS locked_chapter_id,ch.status AS chapter_status,
                            ch.canonical_state AS chapter_canonical_state,
                            ch.content AS chapter_content,ch.words AS chapter_words,
                            ch.content_revision_hash AS chapter_revision_hash,
                            ch.canonical_candidate_uid AS chapter_candidate_uid,
                            ch.claim_token AS chapter_claim_token
                     FROM approval_requests ar
                     INNER JOIN chapter_candidates cc ON cc.id=ar.candidate_id
                     INNER JOIN novels n ON n.id=ar.novel_id
                     INNER JOIN chapters ch ON ch.id=ar.chapter_id AND ch.novel_id=ar.novel_id
                     WHERE ar.user_id=? AND ar.approval_uid=? LIMIT 1 FOR UPDATE",
                    [$userId, $approvalUid]
                );
                if (!is_array($row)) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge disappeared while locking.'
                    );
                }
                $approvalId = self::positiveDatabaseId(
                    $row['approval_id'] ?? null,
                    'merge.approval_id'
                );
                $candidateId = self::positiveDatabaseId(
                    $row['approval_candidate_id'] ?? null,
                    'merge.candidate_id'
                );
                $chapterId = self::positiveDatabaseId(
                    $row['approval_chapter_id'] ?? null,
                    'merge.chapter_id'
                );
                if (($row['approval_state'] ?? null) !== ApprovalRequestState::APPROVED->value
                    || ($row['approval_action_type'] ?? null)
                        !== ApprovalActionType::BRANCH_MERGE->value
                    || (int)($row['approval_user_id'] ?? 0) !== $userId
                    || (int)($row['approval_novel_id'] ?? 0) !== $novelId
                    || (int)($row['novel_user_id'] ?? 0) !== $userId
                    || (int)($row['locked_chapter_id'] ?? 0) !== $chapterId
                    || (int)($row['approval_candidate_id'] ?? 0) !== (int)($row['id'] ?? 0)
                    || ($row['approval_run_id'] ?? null) !== null
                    || ($row['run_id'] ?? null) !== null
                    || ($row['draft_artifact_id'] ?? null) !== null
                    || ($row['validation_artifact_id'] ?? null) !== null
                    || !is_string($row['approval_decision_hash'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $row['approval_decision_hash']) !== 1
                    || !is_string($row['approval_decision_key'] ?? null)
                    || $row['approval_decision_key'] === ''
                    || !is_string($row['approval_decided_by'] ?? null)
                    || $row['approval_decided_by'] === ''
                    || ($row['approval_decided_at'] ?? null) === null) {
                    throw new ChapterCanonTransitionException(
                        'Branch merge Approval decision/provenance is not authentic.'
                    );
                }

                $candidateUid = self::uidValue(
                    $row['candidate_uid'] ?? null,
                    'merge.candidate_uid'
                );
                $targetBranchUid = self::uidValue(
                    $row['target_branch_uid'] ?? null,
                    'merge.target_branch_uid'
                );
                $baseRevisionUid = self::optionalUidValue(
                    $row['base_revision_uid'] ?? null,
                    'merge.base_revision_uid'
                );
                $mergeSourceRevisionUid = self::uidValue(
                    $row['merge_source_revision_uid'] ?? null,
                    'merge.source_revision_uid'
                );
                $content = $row['inline_content'] ?? null;
                $contentHash = self::shaValue(
                    $row['content_hash'] ?? null,
                    'merge.content_hash'
                );
                if (($row['source'] ?? null) !== 'branch_merge'
                    || ($row['gate_state'] ?? null) !== 'passed'
                    || !is_string($content)
                    || $content === ''
                    || !hash_equals(hash('sha256', $content), $contentHash)
                    || (int)($row['user_id'] ?? 0) !== $userId
                    || (int)($row['novel_id'] ?? 0) !== $novelId
                    || (int)($row['chapter_id'] ?? 0) !== $chapterId) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge Candidate bytes/scope are invalid.'
                    );
                }
                $policy = WritePolicySnapshot::fromJson(
                    (string)($row['policy_snapshot_json'] ?? '')
                );
                $approvalPolicy = WritePolicySnapshot::fromJson(
                    (string)($row['approval_policy_json'] ?? '')
                );
                if (!hash_equals(
                    $policy->checksum(),
                    self::shaValue(
                        $row['policy_snapshot_hash'] ?? null,
                        'merge.policy_snapshot_hash'
                    )
                ) || !hash_equals(
                    $policy->toJson(),
                    $approvalPolicy->toJson()
                ) || !hash_equals(
                    $approvalPolicy->checksum(),
                    self::shaValue(
                        $row['approval_policy_hash'] ?? null,
                        'merge.approval_policy_hash'
                    )
                )) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge frozen policy is invalid.'
                    );
                }
                $baseHash = self::optionalShaValue(
                    $row['base_revision_hash'] ?? null,
                    'merge.base_revision_hash'
                );
                $approvalBaseHash = self::optionalShaValue(
                    $row['approval_base_revision_hash'] ?? null,
                    'merge.approval_base_revision_hash'
                );
                if ($approvalBaseHash !== $baseHash) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge base hash changed after decision.'
                    );
                }
                self::assertBranchMergeSnapshots(
                    $row,
                    $targetBranchUid,
                    $baseRevisionUid,
                    $baseHash,
                    $mergeSourceRevisionUid
                );

                $ledgerRepository = new BranchMergeCommitLedgerRepository(DB::getPdo());
                if (($row['lifecycle'] ?? null) === 'committed') {
                    $ledger = $ledgerRepository->findByApproval($approvalId, true);
                    self::assertCommittedBranchMerge(
                        $ledger,
                        $row,
                        $candidateId,
                        $chapterId,
                        $targetBranchUid,
                        $baseRevisionUid,
                        $contentHash,
                        (string)$row['approval_decision_hash']
                    );
                    if (!DB::commit()) {
                        throw new ChapterCanonTransitionException(
                            'Committed Branch merge replay could not release its transaction.'
                        );
                    }
                    self::clearCommittedCaches($chapterId, $novelId);
                    return [
                        'committed' => true,
                        'quarantined' => false,
                        'accepted_content' => $content,
                        'revision_hash' => $contentHash,
                        'candidate_uid' => $candidateUid,
                        'branch_uid' => $targetBranchUid,
                        'revision_uid' => $ledger->committedRevisionUid,
                        'postprocess_job_id' => $ledger->postprocessJobId,
                    ];
                }
                if (($row['lifecycle'] ?? null) !== 'approved') {
                    throw new ChapterCanonTransitionException(
                        'Branch merge Candidate is not approved for promotion.'
                    );
                }

                // Hold the same row lock used by HarnessEmergencyStop::bump()
                // through every M65 projection, legacy Canon write, ledger
                // insert and the final commit. This closes the last-check /
                // commit race left by live reads alone.
                WritePolicySnapshotFactory::lockEmergencyEpochForCommit($policy);

                $heads = DB::fetch(
                    "SELECT sb.user_id AS branch_user_id,sb.novel_id AS branch_novel_id,
                            sb.is_main,sb.state AS branch_state,
                            th.revision_uid AS target_head_revision_uid,
                            br.content_hash AS base_content_hash,
                            br.source_candidate_uid AS base_candidate_uid,
                            ms.user_id AS source_user_id,ms.novel_id AS source_novel_id,
                            ms.chapter_id AS source_chapter_id,ms.branch_uid AS source_branch_uid,
                            sh.revision_uid AS source_head_revision_uid
                     FROM story_branches sb
                     LEFT JOIN chapter_branch_heads th
                        ON th.branch_uid=sb.branch_uid AND th.chapter_id=?
                     LEFT JOIN chapter_revisions br ON br.revision_uid=?
                     INNER JOIN chapter_revisions ms ON ms.revision_uid=?
                     LEFT JOIN chapter_branch_heads sh
                        ON sh.branch_uid=ms.branch_uid AND sh.chapter_id=?
                     WHERE sb.branch_uid=? LIMIT 1 FOR UPDATE",
                    [
                        $chapterId,
                        $baseRevisionUid,
                        $mergeSourceRevisionUid,
                        $chapterId,
                        $targetBranchUid,
                    ]
                );
                if (!is_array($heads)
                    || (int)($heads['branch_user_id'] ?? 0) !== $userId
                    || (int)($heads['branch_novel_id'] ?? 0) !== $novelId
                    || (int)($heads['is_main'] ?? 0) !== 1
                    || ($heads['branch_state'] ?? null) !== 'active'
                    || ($heads['target_head_revision_uid'] ?? null) !== $baseRevisionUid
                    || ($heads['source_head_revision_uid'] ?? null) !== $mergeSourceRevisionUid
                    || (int)($heads['source_user_id'] ?? 0) !== $userId
                    || (int)($heads['source_novel_id'] ?? 0) !== $novelId
                    || (int)($heads['source_chapter_id'] ?? 0) !== $chapterId
                    || ($baseRevisionUid === null
                        ? ($heads['base_content_hash'] ?? null) !== null
                        : ($heads['base_content_hash'] ?? null) !== $baseHash)) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge source or target head is stale.'
                    );
                }
                self::assertBranchMergeChapterBase($row, $baseHash, $heads);

                WritePolicySnapshotFactory::assertEmergencyEpochCurrent($policy);
                $decision = QualityGateDecision::decide(
                    $policy->canonPolicy(),
                    $policy->qualityRuleVersion(),
                    $contentHash,
                    evaluatedAt: gmdate('Y-m-d\TH:i:s\Z')
                );
                $projection = $this->m65Projection->project([
                    'candidate' => $row,
                    'result' => [
                        'committed' => true,
                        'quarantined' => false,
                        'accepted_content' => $content,
                        'revision_hash' => $contentHash,
                        'candidate_uid' => $candidateUid,
                        'decision' => $decision,
                    ],
                ], $decision, true);
                if (!is_array($projection)) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge did not produce an M65 Revision.'
                    );
                }
                $revisionUid = (string)$projection['revision_uid'];

                if ($baseHash !== null) {
                    backupChapterVersion($chapterId, true);
                }
                $oldWords = (int)($row['chapter_words'] ?? 0);
                $newWords = countWords($content);
                $chapterDelta = $baseHash === null ? 1 : 0;
                if (DB::execute(
                    'UPDATE novels SET current_chapter=GREATEST(0,current_chapter+?), '
                        . 'total_words=GREATEST(0,total_words+?) WHERE id=? AND user_id=?',
                    [$chapterDelta, $newWords - $oldWords, $novelId, $userId]
                ) !== 1) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge could not update novel statistics.'
                    );
                }
                $postprocessJobId = PostprocessJobRepository::enqueue(
                    $novelId,
                    $chapterId,
                    $contentHash,
                    'full',
                    3,
                    $policy,
                    $candidateUid,
                    true
                );
                if ($postprocessJobId <= 0) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge could not enqueue post-processing.'
                    );
                }

                WritePolicySnapshotFactory::assertEmergencyEpochCurrent($policy);
                $chapterChanged = $baseHash === null
                    ? DB::execute(
                        "UPDATE chapters SET content=?,words=?,status='completed',
                                canonical_state='canonical',content_revision_hash=?,
                                canonical_candidate_uid=?,write_policy_snapshot_json=?,
                                canonicalized_at=UTC_TIMESTAMP(6),claim_token=''
                         WHERE id=? AND novel_id=? AND status IN ('pending','outlined')
                           AND canonical_state='none' AND content_revision_hash IS NULL
                           AND COALESCE(content,'')='' AND COALESCE(claim_token,'')=''",
                        [
                            $content,
                            $newWords,
                            $contentHash,
                            $candidateUid,
                            $policy->toJson(),
                            $chapterId,
                            $novelId,
                        ]
                    )
                    : DB::execute(
                        "UPDATE chapters SET content=?,words=?,status='completed',
                                canonical_state='canonical',content_revision_hash=?,
                                canonical_candidate_uid=?,write_policy_snapshot_json=?,
                                canonicalized_at=UTC_TIMESTAMP(6),claim_token=''
                         WHERE id=? AND novel_id=? AND status='completed'
                           AND canonical_state='canonical' AND content_revision_hash=?
                           AND SHA2(content,256)=? AND COALESCE(claim_token,'')=''",
                        [
                            $content,
                            $newWords,
                            $contentHash,
                            $candidateUid,
                            $policy->toJson(),
                            $chapterId,
                            $novelId,
                            $baseHash,
                            $baseHash,
                        ]
                    );
                if ($chapterChanged !== 1) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge legacy Canon projection CAS missed.'
                    );
                }
                if (DB::execute(
                    "UPDATE chapter_candidates
                     SET lifecycle='committed',committed_at=UTC_TIMESTAMP(6),
                         updated_at=UTC_TIMESTAMP(6)
                     WHERE id=? AND candidate_uid=? AND lifecycle='approved'
                       AND source='branch_merge' AND gate_state='passed'
                       AND target_branch_uid=? AND base_revision_uid <=> ?
                       AND merge_source_revision_uid=? AND content_hash=?",
                    [
                        $candidateId,
                        $candidateUid,
                        $targetBranchUid,
                        $baseRevisionUid,
                        $mergeSourceRevisionUid,
                        $contentHash,
                    ]
                ) !== 1) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge Candidate commit CAS missed.'
                    );
                }
                $ledger = $ledgerRepository->recordIdempotent(new BranchMergeCommitDraft(
                    $approvalId,
                    $candidateId,
                    $chapterId,
                    $targetBranchUid,
                    $baseRevisionUid,
                    $revisionUid,
                    $contentHash,
                    $postprocessJobId,
                    (string)$row['approval_decision_hash']
                ));
                if (!DB::commit()) {
                    throw new ChapterCanonTransitionException(
                        'Approved Branch merge transaction commit failed.'
                    );
                }
                self::clearCommittedCaches($chapterId, $novelId);
                return [
                    'committed' => true,
                    'quarantined' => false,
                    'accepted_content' => $content,
                    'revision_hash' => $contentHash,
                    'candidate_uid' => $candidateUid,
                    'branch_uid' => $targetBranchUid,
                    'revision_uid' => $ledger->committedRevisionUid,
                    'postprocess_job_id' => $postprocessJobId,
                ];
            } catch (Throwable $exception) {
                if (DB::inTransaction()) {
                    try {
                        DB::rollBack();
                    } catch (Throwable) {
                    }
                }
                throw $exception;
            }
        } finally {
            NovelProjectionFence::release($projectionFence);
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertBranchMergeSnapshots(
        array $row,
        string $targetBranchUid,
        ?string $baseRevisionUid,
        ?string $baseHash,
        string $mergeSourceRevisionUid
    ): void {
        $base = HarnessPersistenceValue::decodeJsonArray(
            $row['base_snapshot_json'] ?? null,
            'merge.base_snapshot_json'
        );
        $expectedBase = [
            'schema' => 'branch-merge-candidate-v1',
            'target_branch_uid' => $targetBranchUid,
            'base_revision_uid' => $baseRevisionUid,
            'base_revision_hash' => $baseHash,
            'merge_source_revision_uid' => $mergeSourceRevisionUid,
            'requires_approval' => true,
        ];
        if (!hash_equals(
            HarnessPersistenceValue::canonicalJson($expectedBase),
            HarnessPersistenceValue::canonicalJson($base)
        )) {
            throw new ChapterCanonTransitionException(
                'Approved Branch merge base snapshot is not exact.'
            );
        }
        $gate = HarnessPersistenceValue::decodeJsonArray(
            $row['validation_json'] ?? null,
            'merge.validation_json'
        );
        if (array_keys($gate) !== ['findings', 'passed', 'schema']
            || ($gate['schema'] ?? null) !== 'branch-merge-gate-v1'
            || ($gate['passed'] ?? null) !== true
            || !is_array($gate['findings'] ?? null)) {
            throw new ChapterCanonTransitionException(
                'Approved Branch merge hard gate did not pass.'
            );
        }
        HarnessSensitiveDataGuard::assertSafeStructure($gate['findings'], 'merge.findings');
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $heads */
    private static function assertBranchMergeChapterBase(
        array $row,
        ?string $baseHash,
        array $heads
    ): void {
        $claimToken = $row['chapter_claim_token'] ?? null;
        if ($claimToken !== null && $claimToken !== '') {
            throw new ChapterCanonTransitionException(
                'Approved Branch merge cannot take over an active Chapter claim.'
            );
        }
        if ($baseHash === null) {
            if (!in_array(($row['chapter_status'] ?? null), ['pending', 'outlined'], true)
                || ($row['chapter_canonical_state'] ?? null) !== 'none'
                || ($row['chapter_revision_hash'] ?? null) !== null
                || !in_array(($row['chapter_content'] ?? null), [null, ''], true)) {
                throw new ChapterCanonTransitionException(
                    'Approved Branch merge empty Chapter base is stale.'
                );
            }
            return;
        }
        if (($row['chapter_status'] ?? null) !== 'completed'
            || ($row['chapter_canonical_state'] ?? null) !== 'canonical'
            || !is_string($row['chapter_revision_hash'] ?? null)
            || !hash_equals($baseHash, (string)$row['chapter_revision_hash'])
            || !is_string($row['chapter_content'] ?? null)
            || !hash_equals($baseHash, hash('sha256', (string)$row['chapter_content']))
            || !is_string($heads['base_candidate_uid'] ?? null)
            || !hash_equals(
                (string)$heads['base_candidate_uid'],
                (string)($row['chapter_candidate_uid'] ?? '')
            )) {
            throw new ChapterCanonTransitionException(
                'Approved Branch merge legacy Canon base is stale.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertCommittedBranchMerge(
        ?BranchMergeCommitRecord $ledger,
        array $row,
        int $candidateId,
        int $chapterId,
        string $branchUid,
        ?string $baseRevisionUid,
        string $contentHash,
        string $decisionHash
    ): void {
        if (!$ledger instanceof BranchMergeCommitRecord
            || $ledger->candidateId !== $candidateId
            || $ledger->chapterId !== $chapterId
            || $ledger->branchUid !== $branchUid
            || $ledger->baseRevisionUid !== $baseRevisionUid
            || !hash_equals($ledger->committedRevisionHash, $contentHash)
            || !hash_equals($ledger->approvalDecisionPayloadHash, $decisionHash)) {
            throw new ChapterCanonTransitionException(
                'Committed Branch merge is missing its exact idempotency ledger.'
            );
        }
        $authority = DB::fetch(
            "SELECT ch.content,ch.content_revision_hash,
                    ch.canonical_candidate_uid AS chapter_candidate_uid,
                    bh.revision_uid AS branch_revision_uid,
                    h.branch_uid AS canonical_branch_uid,
                    h.revision_uid AS canonical_revision_uid,
                    h.revision_hash AS canonical_revision_hash,
                    h.source_candidate_uid AS head_candidate_uid
             FROM chapters ch
             INNER JOIN chapter_branch_heads bh ON bh.chapter_id=ch.id AND bh.branch_uid=?
             INNER JOIN chapter_canonical_heads h ON h.chapter_id=ch.id
             WHERE ch.id=? LIMIT 1 FOR UPDATE",
            [$branchUid, $chapterId]
        );
        $candidateUid = (string)($row['candidate_uid'] ?? '');
        if (!is_array($authority)
            || !is_string($authority['content'] ?? null)
            || !hash_equals(hash('sha256', (string)$authority['content']), $contentHash)
            || !hash_equals((string)($authority['content_revision_hash'] ?? ''), $contentHash)
            || !hash_equals((string)($authority['chapter_candidate_uid'] ?? ''), $candidateUid)
            || ($authority['branch_revision_uid'] ?? null) !== $ledger->committedRevisionUid
            || ($authority['canonical_branch_uid'] ?? null) !== $branchUid
            || ($authority['canonical_revision_uid'] ?? null) !== $ledger->committedRevisionUid
            || !hash_equals(
                (string)($authority['canonical_revision_hash'] ?? ''),
                $contentHash
            )
            || ($authority['head_candidate_uid'] ?? null) !== $candidateUid) {
            throw new ChapterCanonTransitionException(
                'Committed Branch merge ledger disagrees with Canon authority.'
            );
        }
    }

    private static function positiveDatabaseId(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
            return (int)$value;
        }
        throw new ChapterCanonTransitionException("{$field} must be a positive integer.");
    }

    private static function uidValue(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new ChapterCanonTransitionException("{$field} must be a lowercase UID.");
        }
        return $value;
    }

    private static function optionalUidValue(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::uidValue($value, $field);
    }

    private static function shaValue(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ChapterCanonTransitionException("{$field} must be a lowercase SHA-256 hash.");
        }
        return $value;
    }

    private static function optionalShaValue(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::shaValue($value, $field);
    }

    private static function clearCommittedCaches(int $chapterId, int $novelId): void
    {
        try {
            clearChapterCache($chapterId, $novelId);
            clearNovelCache($novelId);
        } catch (Throwable $exception) {
            error_log('CanonicalCommitService cache clear failed: ' . $exception->getMessage());
        }
    }
}
