<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/ApprovalRequest.php';

final class ApprovalRequestRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function requestIdempotent(ApprovalRequestDraft $draft): ApprovalRequest
    {
        return $this->db->transactional(function () use ($draft): ApprovalRequest {
            $this->assertRequestOwnership($draft);

            $this->db->execute(
                "INSERT INTO approval_requests
                    (approval_uid, user_id, novel_id, chapter_id, run_id, candidate_id,
                     idempotency_key, action_type, risk_level, state,
                     base_revision_hash, policy_snapshot_json, policy_snapshot_hash,
                     summary_artifact_id, requested_by, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?,
                         UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->approvalUid,
                    $draft->userId,
                    $draft->novelId,
                    $draft->chapterId,
                    $draft->runId,
                    $draft->candidateId,
                    $draft->idempotencyKey,
                    $draft->actionType->value,
                    $draft->riskLevel->value,
                    $draft->baseRevisionHash,
                    $draft->policySnapshotJson,
                    $draft->policySnapshotHash,
                    $draft->summaryArtifactId,
                    $draft->requestedBy,
                    $draft->expiresAt === null
                        ? null
                        : HarnessPersistenceValue::mysqlDate($draft->expiresAt),
                ]
            );

            $row = $this->db->fetchOne(
                'SELECT * FROM approval_requests WHERE user_id=? AND idempotency_key=? FOR UPDATE',
                [$draft->userId, $draft->idempotencyKey]
            );
            if ($row === null) {
                $row = $this->db->fetchOne(
                    'SELECT * FROM approval_requests WHERE approval_uid=? FOR UPDATE',
                    [$draft->approvalUid]
                );
            }
            if ($row === null) {
                $row = $this->db->fetchOne(
                    "SELECT * FROM approval_requests
                     WHERE candidate_id=? AND action_type=? AND state='open' FOR UPDATE",
                    [$draft->candidateId, $draft->actionType->value]
                );
            }
            if ($row === null) {
                throw new HarnessIdempotencyConflictException(
                    'Approval insert collided without resolving a managed identity.'
                );
            }
            $draft->assertIdempotentRow($row);
            return ApprovalRequest::fromRow($row);
        });
    }

    public function findByUid(int $userId, string $approvalUid): ?ApprovalRequest
    {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $approvalUid = HarnessPersistenceValue::uid($approvalUid, 'approval_uid');
        $row = $this->db->fetchOne(
            'SELECT * FROM approval_requests WHERE user_id=? AND approval_uid=?',
            [$userId, $approvalUid]
        );
        return $row === null ? null : ApprovalRequest::fromRow($row);
    }

    public function findOpenForCandidate(
        int $userId,
        int $candidateId,
        ApprovalActionType $actionType
    ): ?ApprovalRequest {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $candidateId = HarnessPersistenceValue::positiveInt($candidateId, 'candidate_id');
        $row = $this->db->fetchOne(
            "SELECT * FROM approval_requests
             WHERE user_id=? AND candidate_id=? AND action_type=? AND state='open'",
            [$userId, $candidateId, $actionType->value]
        );
        return $row === null ? null : ApprovalRequest::fromRow($row);
    }

    /**
     * Resolve exactly once. A nominal approval is converted to stale when its
     * frozen chapter base, expiry, Run, or Candidate no longer authenticates.
     */
    public function resolve(
        int $userId,
        string $approvalUid,
        ApprovalDecision $decision
    ): ApprovalRequest {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $approvalUid = HarnessPersistenceValue::uid($approvalUid, 'approval_uid');

        return $this->db->transactional(function () use (
            $userId,
            $approvalUid,
            $decision
        ): ApprovalRequest {
            $ownership = $this->fetchDecisionOwnership($userId, $approvalUid);
            if ($ownership === null) {
                throw new HarnessPersistenceException('Approval request does not exist for this user.');
            }
            $request = ApprovalRequest::fromRow($ownership);
            if ($request->state->isTerminal()) {
                $this->assertDecisionReplay($request, $decision);
                return $request;
            }

            $this->assertDecisionOwnership($request, $ownership);
            $effectiveState = $this->effectiveDecisionState($request, $ownership, $decision);
            $currentCandidateState = HarnessPersistenceValue::asciiString(
                $ownership['candidate_lifecycle'] ?? null,
                'candidate.lifecycle',
                1,
                24
            );
            $candidateTarget = match ($effectiveState) {
                ApprovalRequestState::APPROVED => 'approved',
                ApprovalRequestState::REJECTED => 'rejected',
                ApprovalRequestState::STALE => 'stale',
                ApprovalRequestState::CANCELED => null,
                ApprovalRequestState::OPEN => throw new LogicException('Open is not a decision.'),
            };
            if ($candidateTarget !== null && $candidateTarget !== $currentCandidateState) {
                $timestampColumn = match ($candidateTarget) {
                    'approved' => 'approved_at',
                    'rejected' => 'rejected_at',
                    'stale' => 'stale_at',
                    default => throw new LogicException('Unknown candidate target.'),
                };
                $changed = $this->db->execute(
                    "UPDATE chapter_candidates
                     SET lifecycle=?, `{$timestampColumn}`=?, updated_at=?
                     WHERE id=? AND lifecycle=?",
                    [
                        $candidateTarget,
                        HarnessPersistenceValue::mysqlDate($decision->decidedAt),
                        HarnessPersistenceValue::mysqlDate($decision->decidedAt),
                        $request->candidateId,
                        $currentCandidateState,
                    ]
                );
                if ($changed !== 1) {
                    throw new HarnessStateTransitionException(
                        'Candidate changed concurrently while approval was being resolved.'
                    );
                }
            }

            $changed = $this->db->execute(
                "UPDATE approval_requests
                 SET state=?, decided_by=?, decision_reason=?,
                     decision_idempotency_key=?, decision_payload_hash=?,
                     decided_at=?, updated_at=?
                 WHERE id=? AND state='open'",
                [
                    $effectiveState->value,
                    $decision->decidedBy,
                    $decision->reason,
                    $decision->idempotencyKey,
                    $decision->payloadHash,
                    HarnessPersistenceValue::mysqlDate($decision->decidedAt),
                    HarnessPersistenceValue::mysqlDate($decision->decidedAt),
                    $request->id,
                ]
            );
            if ($changed !== 1) {
                $row = $this->db->fetchOne(
                    'SELECT * FROM approval_requests WHERE id=? FOR UPDATE',
                    [$request->id]
                );
                if ($row === null) {
                    throw new HarnessStateTransitionException(
                        'Approval disappeared during its decision CAS.'
                    );
                }
                $resolved = ApprovalRequest::fromRow($row);
                $this->assertDecisionReplay($resolved, $decision);
                return $resolved;
            }

            $row = $this->db->fetchOne(
                'SELECT * FROM approval_requests WHERE id=? FOR UPDATE',
                [$request->id]
            );
            if ($row === null) {
                throw new HarnessPersistenceException('Resolved approval could not be reloaded.');
            }
            $resolved = ApprovalRequest::fromRow($row);
            if ($resolved->state !== $effectiveState
                || $resolved->decisionIdempotencyKey !== $decision->idempotencyKey
                || $resolved->decisionPayloadHash === null
                || !hash_equals($resolved->decisionPayloadHash, $decision->payloadHash)) {
                throw new HarnessStateTransitionException(
                    'Approval decision did not persist its exact CAS result.'
                );
            }
            return $resolved;
        });
    }

    private function assertRequestOwnership(ApprovalRequestDraft $draft): void
    {
        $row = $this->db->fetchOne(
            "SELECT r.id AS run_id, r.run_uid AS run_uid,
                    r.user_id AS run_user_id, r.novel_id AS run_novel_id,
                    r.chapter_id AS run_chapter_id,
                    n.id AS novel_id, n.user_id AS novel_user_id,
                    ch.id AS chapter_id, ch.novel_id AS chapter_novel_id,
                    ch.content_revision_hash AS chapter_revision_hash,
                    cc.id AS candidate_id, cc.user_id AS candidate_user_id,
                    cc.novel_id AS candidate_novel_id, cc.chapter_id AS candidate_chapter_id,
                    cc.run_id AS candidate_run_id, cc.lifecycle AS candidate_lifecycle,
                    cc.base_revision_hash AS candidate_base_revision_hash,
                    cc.content_hash AS candidate_content_hash,
                    cc.policy_snapshot_hash AS candidate_policy_hash,
                    cc.draft_artifact_id AS candidate_draft_artifact_id,
                    cc.validation_artifact_id AS candidate_validation_artifact_id,
                    da.id AS draft_artifact_id, da.run_id AS draft_artifact_run_id,
                    da.content_hash AS draft_artifact_hash,
                    va.id AS validation_artifact_id, va.run_id AS validation_artifact_run_id,
                    sa.id AS summary_artifact_id, sa.run_id AS summary_artifact_run_id
             FROM generation_runs r
             INNER JOIN novels n ON n.id=r.novel_id
             INNER JOIN chapters ch ON ch.id=?
             INNER JOIN chapter_candidates cc ON cc.id=?
             LEFT JOIN generation_artifacts da ON da.id=cc.draft_artifact_id
             LEFT JOIN generation_artifacts va ON va.id=cc.validation_artifact_id
             LEFT JOIN generation_artifacts sa ON sa.id=?
             WHERE r.id=? FOR UPDATE",
            [$draft->chapterId, $draft->candidateId, $draft->summaryArtifactId, $draft->runId]
        );
        if ($row === null) {
            throw new HarnessPersistenceException(
                'Approval Run, Novel, Chapter, Candidate, or Artifact is missing.'
            );
        }

        $equals = static function (array $row, string $key, int $expected): bool {
            return HarnessPersistenceValue::positiveInt($row[$key] ?? null, $key) === $expected;
        };
        if (!$equals($row, 'run_id', $draft->runId)
            || !$equals($row, 'run_user_id', $draft->userId)
            || !$equals($row, 'run_novel_id', $draft->novelId)
            || !$equals($row, 'run_chapter_id', $draft->chapterId)
            || !$equals($row, 'novel_id', $draft->novelId)
            || !$equals($row, 'novel_user_id', $draft->userId)
            || !$equals($row, 'chapter_id', $draft->chapterId)
            || !$equals($row, 'chapter_novel_id', $draft->novelId)
            || !$equals($row, 'candidate_id', $draft->candidateId)
            || !$equals($row, 'candidate_user_id', $draft->userId)
            || !$equals($row, 'candidate_novel_id', $draft->novelId)
            || !$equals($row, 'candidate_chapter_id', $draft->chapterId)
            || !$equals($row, 'candidate_run_id', $draft->runId)) {
            throw new HarnessPersistenceException(
                'Approval User/Novel/Chapter/Run/Candidate ownership does not match.'
            );
        }

        $candidateState = HarnessPersistenceValue::asciiString(
            $row['candidate_lifecycle'] ?? null,
            'candidate.lifecycle',
            1,
            24
        );
        if (!in_array($candidateState, ['quarantined', 'approved'], true)) {
            throw new HarnessStateTransitionException(
                'Only a validated quarantined or approved Candidate may request approval.'
            );
        }
        $candidateBase = HarnessPersistenceValue::optionalHash(
            $row['candidate_base_revision_hash'] ?? null,
            'candidate.base_revision_hash'
        );
        $chapterBase = HarnessPersistenceValue::optionalHash(
            $row['chapter_revision_hash'] ?? null,
            'chapter.content_revision_hash'
        );
        if ($candidateBase !== $draft->baseRevisionHash || $chapterBase !== $draft->baseRevisionHash) {
            throw new HarnessStateTransitionException(
                'Approval base revision is already stale.'
            );
        }
        $candidatePolicyHash = HarnessPersistenceValue::hash(
            $row['candidate_policy_hash'] ?? null,
            'candidate.policy_snapshot_hash'
        );
        if (!hash_equals($candidatePolicyHash, $draft->policySnapshotHash)) {
            throw new HarnessPersistenceException(
                'Approval policy snapshot is not the Candidate frozen policy.'
            );
        }

        $draftArtifactId = HarnessPersistenceValue::positiveInt(
            $row['candidate_draft_artifact_id'] ?? null,
            'candidate.draft_artifact_id'
        );
        $validationArtifactId = HarnessPersistenceValue::positiveInt(
            $row['candidate_validation_artifact_id'] ?? null,
            'candidate.validation_artifact_id'
        );
        if (!$equals($row, 'draft_artifact_id', $draftArtifactId)
            || !$equals($row, 'draft_artifact_run_id', $draft->runId)
            || !$equals($row, 'validation_artifact_id', $validationArtifactId)
            || !$equals($row, 'validation_artifact_run_id', $draft->runId)) {
            throw new HarnessPersistenceException(
                'Approval Candidate artifacts must belong to the same Run.'
            );
        }
        $candidateContentHash = HarnessPersistenceValue::hash(
            $row['candidate_content_hash'] ?? null,
            'candidate.content_hash'
        );
        $draftArtifactHash = HarnessPersistenceValue::hash(
            $row['draft_artifact_hash'] ?? null,
            'draft_artifact.content_hash'
        );
        if (!hash_equals($candidateContentHash, $draftArtifactHash)) {
            throw new HarnessPersistenceException(
                'Approval Candidate content is not its Draft Artifact content.'
            );
        }
        if ($draft->summaryArtifactId !== null
            && (!$equals($row, 'summary_artifact_id', $draft->summaryArtifactId)
                || !$equals($row, 'summary_artifact_run_id', $draft->runId))) {
            throw new HarnessPersistenceException(
                'Approval summary Artifact must belong to the same Run.'
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchDecisionOwnership(int $userId, string $approvalUid): ?array
    {
        return $this->db->fetchOne(
            "SELECT ar.*,
                    r.user_id AS run_user_id, r.novel_id AS run_novel_id,
                    r.chapter_id AS run_chapter_id,
                    n.user_id AS novel_user_id,
                    ch.novel_id AS chapter_novel_id,
                    ch.content_revision_hash AS chapter_revision_hash,
                    cc.user_id AS candidate_user_id,
                    cc.novel_id AS candidate_novel_id,
                    cc.chapter_id AS candidate_chapter_id,
                    cc.run_id AS candidate_run_id,
                    cc.lifecycle AS candidate_lifecycle,
                    cc.content_hash AS candidate_content_hash,
                    cc.draft_artifact_id AS candidate_draft_artifact_id,
                    cc.validation_artifact_id AS candidate_validation_artifact_id,
                    da.id AS decision_draft_artifact_id,
                    da.run_id AS decision_draft_artifact_run_id,
                    da.content_hash AS decision_draft_artifact_hash,
                    va.id AS decision_validation_artifact_id,
                    va.run_id AS decision_validation_artifact_run_id,
                    sa.id AS decision_summary_artifact_id,
                    sa.run_id AS decision_summary_artifact_run_id
             FROM approval_requests ar
             LEFT JOIN generation_runs r ON r.id=ar.run_id
             INNER JOIN novels n ON n.id=ar.novel_id
             INNER JOIN chapters ch ON ch.id=ar.chapter_id
             INNER JOIN chapter_candidates cc ON cc.id=ar.candidate_id
             LEFT JOIN generation_artifacts da ON da.id=cc.draft_artifact_id
             LEFT JOIN generation_artifacts va ON va.id=cc.validation_artifact_id
             LEFT JOIN generation_artifacts sa ON sa.id=ar.summary_artifact_id
             WHERE ar.user_id=? AND ar.approval_uid=? FOR UPDATE",
            [$userId, $approvalUid]
        );
    }

    /** @param array<string,mixed> $row */
    private function assertDecisionOwnership(ApprovalRequest $request, array $row): void
    {
        if ($request->actionType === ApprovalActionType::BRANCH_MERGE) {
            $this->assertBranchMergeDecisionOwnership($request, $row);
            return;
        }
        $runId = HarnessPersistenceValue::optionalPositiveInt($request->runId, 'run_id');
        $draftArtifactId = HarnessPersistenceValue::positiveInt(
            $row['candidate_draft_artifact_id'] ?? null,
            'candidate_draft_artifact_id'
        );
        $validationArtifactId = HarnessPersistenceValue::positiveInt(
            $row['candidate_validation_artifact_id'] ?? null,
            'candidate_validation_artifact_id'
        );
        if ($runId === null
            || HarnessPersistenceValue::positiveInt($row['run_user_id'] ?? null, 'run_user_id') !== $request->userId
            || HarnessPersistenceValue::positiveInt($row['run_novel_id'] ?? null, 'run_novel_id') !== $request->novelId
            || HarnessPersistenceValue::positiveInt($row['run_chapter_id'] ?? null, 'run_chapter_id') !== $request->chapterId
            || HarnessPersistenceValue::positiveInt($row['novel_user_id'] ?? null, 'novel_user_id') !== $request->userId
            || HarnessPersistenceValue::positiveInt($row['chapter_novel_id'] ?? null, 'chapter_novel_id') !== $request->novelId
            || HarnessPersistenceValue::positiveInt($row['candidate_user_id'] ?? null, 'candidate_user_id') !== $request->userId
            || HarnessPersistenceValue::positiveInt($row['candidate_novel_id'] ?? null, 'candidate_novel_id') !== $request->novelId
            || HarnessPersistenceValue::positiveInt($row['candidate_chapter_id'] ?? null, 'candidate_chapter_id') !== $request->chapterId
            || HarnessPersistenceValue::positiveInt($row['candidate_run_id'] ?? null, 'candidate_run_id') !== $runId
            || HarnessPersistenceValue::positiveInt($row['decision_draft_artifact_id'] ?? null, 'decision_draft_artifact_id') !== $draftArtifactId
            || HarnessPersistenceValue::positiveInt($row['decision_draft_artifact_run_id'] ?? null, 'decision_draft_artifact_run_id') !== $runId
            || HarnessPersistenceValue::positiveInt($row['decision_validation_artifact_id'] ?? null, 'decision_validation_artifact_id') !== $validationArtifactId
            || HarnessPersistenceValue::positiveInt($row['decision_validation_artifact_run_id'] ?? null, 'decision_validation_artifact_run_id') !== $runId) {
            throw new HarnessPersistenceException(
                'Approval decision ownership or Artifact provenance no longer authenticates.'
            );
        }
        $candidateHash = HarnessPersistenceValue::hash(
            $row['candidate_content_hash'] ?? null,
            'candidate_content_hash'
        );
        $draftArtifactHash = HarnessPersistenceValue::hash(
            $row['decision_draft_artifact_hash'] ?? null,
            'decision_draft_artifact_hash'
        );
        if (!hash_equals($candidateHash, $draftArtifactHash)) {
            throw new HarnessPersistenceException(
                'Approval decision Draft Artifact content no longer authenticates.'
            );
        }
        if ($request->summaryArtifactId !== null
            && (HarnessPersistenceValue::positiveInt(
                $row['decision_summary_artifact_id'] ?? null,
                'decision_summary_artifact_id'
            ) !== $request->summaryArtifactId
                || HarnessPersistenceValue::positiveInt(
                    $row['decision_summary_artifact_run_id'] ?? null,
                    'decision_summary_artifact_run_id'
                ) !== $runId)) {
            throw new HarnessPersistenceException(
                'Approval decision Summary Artifact no longer belongs to its Run.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private function effectiveDecisionState(
        ApprovalRequest $request,
        array $row,
        ApprovalDecision $decision
    ): ApprovalRequestState {
        if ($decision->state !== ApprovalRequestState::APPROVED) {
            return $decision->state;
        }

        $expired = false;
        if ($request->expiresAt !== null) {
            $expiry = new DateTimeImmutable($request->expiresAt, new DateTimeZone('UTC'));
            $expired = $expiry <= $decision->decidedAt->setTimezone(new DateTimeZone('UTC'));
        }
        if ($request->actionType === ApprovalActionType::BRANCH_MERGE) {
            return $expired || !$this->branchMergeBaseIsCurrent($request)
                ? ApprovalRequestState::STALE
                : ApprovalRequestState::APPROVED;
        }

        $chapterRevision = HarnessPersistenceValue::optionalHash(
            $row['chapter_revision_hash'] ?? null,
            'chapter_revision_hash'
        );
        $candidateState = HarnessPersistenceValue::asciiString(
            $row['candidate_lifecycle'] ?? null,
            'candidate_lifecycle',
            1,
            24
        );
        if ($expired
            || $chapterRevision !== $request->baseRevisionHash
            || !in_array($candidateState, ['draft', 'quarantined', 'approved'], true)) {
            return ApprovalRequestState::STALE;
        }
        return ApprovalRequestState::APPROVED;
    }

    /**
     * M65 merge Approvals deliberately have no Run or Artifact provenance.
     * Their authority is instead the immutable Candidate bytes, frozen hard
     * gate, exact source/target Revision lineage and an owned active main
     * Branch. This is a separate contract and must never weaken the P3
     * force-commit Run/Artifact checks above.
     *
     * @param array<string,mixed> $decisionRow
     */
    private function assertBranchMergeDecisionOwnership(
        ApprovalRequest $request,
        array $decisionRow
    ): void {
        if ($request->runId !== null
            || ($decisionRow['candidate_run_id'] ?? null) !== null
            || ($decisionRow['candidate_draft_artifact_id'] ?? null) !== null
            || ($decisionRow['candidate_validation_artifact_id'] ?? null) !== null) {
            throw new HarnessPersistenceException(
                'Branch merge Approval must not fabricate Run or Artifact provenance.'
            );
        }
        $row = $this->db->fetchOne(
            "SELECT cc.*, sb.user_id AS branch_user_id,
                    sb.novel_id AS branch_novel_id, sb.is_main, sb.state AS branch_state,
                    br.user_id AS base_user_id, br.novel_id AS base_novel_id,
                    br.chapter_id AS base_chapter_id, br.branch_uid AS base_branch_uid,
                    br.content_hash AS base_content_hash,
                    ms.user_id AS source_user_id, ms.novel_id AS source_novel_id,
                    ms.chapter_id AS source_chapter_id, ms.branch_uid AS source_branch_uid
             FROM chapter_candidates cc
             INNER JOIN story_branches sb ON sb.branch_uid=cc.target_branch_uid
             LEFT JOIN chapter_revisions br ON br.revision_uid=cc.base_revision_uid
             INNER JOIN chapter_revisions ms ON ms.revision_uid=cc.merge_source_revision_uid
             WHERE cc.id=? LIMIT 1 FOR UPDATE",
            [$request->candidateId]
        );
        if (!is_array($row)
            || (int)($row['user_id'] ?? 0) !== $request->userId
            || (int)($row['novel_id'] ?? 0) !== $request->novelId
            || (int)($row['chapter_id'] ?? 0) !== $request->chapterId
            || (int)($row['branch_user_id'] ?? 0) !== $request->userId
            || (int)($row['branch_novel_id'] ?? 0) !== $request->novelId
            || (int)($row['is_main'] ?? 0) !== 1
            || ($row['branch_state'] ?? null) !== 'active'
            || ($row['source'] ?? null) !== 'branch_merge'
            || ($row['gate_state'] ?? null) !== 'passed'
            || !in_array(($row['lifecycle'] ?? null), ['draft', 'approved'], true)) {
            throw new HarnessPersistenceException(
                'Branch merge Approval Candidate or target Branch is outside scope.'
            );
        }

        $targetBranchUid = HarnessPersistenceValue::uid(
            $row['target_branch_uid'] ?? null,
            'merge.target_branch_uid'
        );
        $baseRevisionUid = ($row['base_revision_uid'] ?? null) === null
            ? null
            : HarnessPersistenceValue::uid($row['base_revision_uid'], 'merge.base_revision_uid');
        $mergeSourceRevisionUid = HarnessPersistenceValue::uid(
            $row['merge_source_revision_uid'] ?? null,
            'merge.source_revision_uid'
        );
        if ((int)($row['source_user_id'] ?? 0) !== $request->userId
            || (int)($row['source_novel_id'] ?? 0) !== $request->novelId
            || (int)($row['source_chapter_id'] ?? 0) !== $request->chapterId) {
            throw new HarnessPersistenceException(
                'Branch merge source Revision crosses ownership or chapter scope.'
            );
        }

        $baseHash = HarnessPersistenceValue::optionalHash(
            $row['base_revision_hash'] ?? null,
            'merge.base_revision_hash'
        );
        if ($baseRevisionUid === null) {
            if (($row['base_content_hash'] ?? null) !== null || $baseHash !== null) {
                throw new HarnessPersistenceException('Empty merge base carries Revision state.');
            }
        } elseif (($row['base_revision_uid'] ?? null) !== $baseRevisionUid
            || (int)($row['base_user_id'] ?? 0) !== $request->userId
            || (int)($row['base_novel_id'] ?? 0) !== $request->novelId
            || (int)($row['base_chapter_id'] ?? 0) !== $request->chapterId
            || ($row['base_branch_uid'] ?? null) !== $targetBranchUid
            || !is_string($row['base_content_hash'] ?? null)
            || !hash_equals($baseHash ?? '', (string)$row['base_content_hash'])) {
            throw new HarnessPersistenceException(
                'Branch merge base Revision bytes or ownership are invalid.'
            );
        }
        $candidatePolicyJson = HarnessPersistenceValue::canonicalMysqlJson(
            HarnessPersistenceValue::decodeJsonArray(
                $row['policy_snapshot_json'] ?? null,
                'merge.candidate_policy_snapshot_json'
            )
        );
        if ($request->baseRevisionHash !== $baseHash
            || !hash_equals(
                $request->policySnapshotHash,
                HarnessPersistenceValue::hash(
                    $row['policy_snapshot_hash'] ?? null,
                    'merge.policy_snapshot_hash'
                )
            )
            || !hash_equals(
                $request->policySnapshotJson,
                $candidatePolicyJson
            )) {
            throw new HarnessPersistenceException(
                'Branch merge Approval does not authenticate Candidate base/policy.'
            );
        }

        $content = $row['inline_content'] ?? null;
        $contentHash = HarnessPersistenceValue::hash(
            $row['content_hash'] ?? null,
            'merge.content_hash'
        );
        if (!is_string($content) || !hash_equals(hash('sha256', $content), $contentHash)) {
            throw new HarnessPersistenceException('Branch merge Candidate bytes are invalid.');
        }
        $baseSnapshot = HarnessPersistenceValue::decodeJsonArray(
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
            HarnessPersistenceValue::canonicalJson($baseSnapshot)
        )) {
            throw new HarnessPersistenceException(
                'Branch merge Candidate base snapshot is not exact.'
            );
        }
        $validation = HarnessPersistenceValue::decodeJsonArray(
            $row['validation_json'] ?? null,
            'merge.validation_json'
        );
        if (array_keys($validation) !== ['findings', 'passed', 'schema']
            || ($validation['schema'] ?? null) !== 'branch-merge-gate-v1'
            || ($validation['passed'] ?? null) !== true
            || !is_array($validation['findings'] ?? null)) {
            throw new HarnessPersistenceException(
                'Branch merge Candidate lacks a passed frozen hard gate.'
            );
        }
    }

    private function branchMergeBaseIsCurrent(ApprovalRequest $request): bool
    {
        $row = $this->db->fetchOne(
            "SELECT cc.lifecycle,cc.target_branch_uid,cc.base_revision_uid,
                    cc.merge_source_revision_uid,cc.base_revision_hash,
                    sb.state AS branch_state,sb.is_main,
                    th.revision_uid AS target_head_revision_uid,
                    sh.revision_uid AS source_head_revision_uid,
                    br.content_hash AS base_content_hash,
                    ch.content_revision_hash AS chapter_revision_hash
             FROM chapter_candidates cc
             INNER JOIN chapters ch ON ch.id=cc.chapter_id AND ch.novel_id=cc.novel_id
             INNER JOIN story_branches sb ON sb.branch_uid=cc.target_branch_uid
                AND sb.user_id=cc.user_id AND sb.novel_id=cc.novel_id
             LEFT JOIN chapter_branch_heads th
                ON th.branch_uid=cc.target_branch_uid AND th.chapter_id=cc.chapter_id
             LEFT JOIN chapter_revisions br ON br.revision_uid=cc.base_revision_uid
             INNER JOIN chapter_revisions ms ON ms.revision_uid=cc.merge_source_revision_uid
             LEFT JOIN chapter_branch_heads sh
                ON sh.branch_uid=ms.branch_uid AND sh.chapter_id=cc.chapter_id
             WHERE cc.id=? AND cc.user_id=? AND cc.novel_id=? AND cc.chapter_id=?
             LIMIT 1 FOR UPDATE",
            [$request->candidateId, $request->userId, $request->novelId, $request->chapterId]
        );
        if (!is_array($row)
            || ($row['branch_state'] ?? null) !== 'active'
            || (int)($row['is_main'] ?? 0) !== 1
            || !in_array(($row['lifecycle'] ?? null), ['draft', 'approved'], true)
            || ($row['source_head_revision_uid'] ?? null) !== ($row['merge_source_revision_uid'] ?? null)) {
            return false;
        }
        $baseUid = $row['base_revision_uid'] ?? null;
        $headUid = $row['target_head_revision_uid'] ?? null;
        $baseHash = $row['base_revision_hash'] ?? null;
        return $headUid === $baseUid
            && ($row['base_content_hash'] ?? null) === $baseHash
            && ($row['chapter_revision_hash'] ?? null) === $request->baseRevisionHash
            && $baseHash === $request->baseRevisionHash;
    }

    private function assertDecisionReplay(
        ApprovalRequest $request,
        ApprovalDecision $decision
    ): void {
        if ($request->decisionIdempotencyKey !== $decision->idempotencyKey
            || $request->decisionPayloadHash === null
            || !hash_equals($request->decisionPayloadHash, $decision->payloadHash)) {
            throw new HarnessIdempotencyConflictException(
                'Approval was already resolved by a different decision.'
            );
        }
    }
}
