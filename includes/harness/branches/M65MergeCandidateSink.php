<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchMergeService.php';
require_once dirname(__DIR__) . '/candidates/ApprovalRequest.php';
require_once dirname(__DIR__, 2) . '/quality/WritePolicySnapshotFactory.php';

/**
 * Persists a Branch merge as an ordinary P3 Candidate plus a durable open
 * Approval. It deliberately has no API capable of moving either Branch head.
 */
final class M65MergeCandidateSink implements MergeCandidateSink
{
    private HarnessPersistenceDriver $db;
    /** @var Closure(int,int):WritePolicySnapshot */
    private readonly Closure $policySnapshotProvider;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $policySnapshotProvider = null
    )
    {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->policySnapshotProvider = $policySnapshotProvider === null
            ? static fn(int $novelId, int $targetWords): WritePolicySnapshot =>
                WritePolicySnapshotFactory::captureForChapter($novelId, $targetWords)
            : Closure::fromCallable($policySnapshotProvider);
    }

    public function createIdempotent(MergeCandidateProposal $proposal): MergeCandidateProposal
    {
        return $this->db->transactional(function () use ($proposal): MergeCandidateProposal {
            // The service evaluates the hard gate before entering this
            // transaction. Re-authenticate and lock both heads here so an
            // expensive evaluation cannot persist an already-stale Approval.
            $scope = $this->lockCurrentHeads($proposal);

            $baseHash = null;
            if ($proposal->baseRevisionUid !== null) {
                $base = $this->db->fetchOne(
                    'SELECT content_hash FROM chapter_revisions
                     WHERE revision_uid=? AND branch_uid=? AND user_id=?
                       AND novel_id=? AND chapter_id=? FOR UPDATE',
                    [
                        $proposal->baseRevisionUid,
                        $proposal->targetBranchUid,
                        $proposal->userId,
                        $proposal->novelId,
                        $proposal->chapterId,
                    ]
                );
                if ($base === null) {
                    throw new HarnessPersistenceException('Merge base Revision is stale or outside scope.');
                }
                $baseHash = HarnessPersistenceValue::hash(
                    $base['content_hash'] ?? null,
                    'merge.base_revision_hash'
                );
            }

            $baseSnapshot = [
                'schema' => 'branch-merge-candidate-v1',
                'target_branch_uid' => $proposal->targetBranchUid,
                'base_revision_uid' => $proposal->baseRevisionUid,
                'base_revision_hash' => $baseHash,
                'merge_source_revision_uid' => $proposal->mergeSourceRevisionUid,
                'requires_approval' => true,
            ];
            $baseSnapshotJson = HarnessPersistenceValue::canonicalMysqlJson($baseSnapshot);
            $policy = ($this->policySnapshotProvider)(
                $proposal->novelId,
                HarnessPersistenceValue::positiveInt(
                    $scope['target_words'] ?? null,
                    'merge.target_words'
                )
            );
            if (!$policy instanceof WritePolicySnapshot) {
                throw new HarnessPersistenceException(
                    'Merge Candidate policy provider returned an invalid snapshot.'
                );
            }
            $policyJson = HarnessPersistenceValue::canonicalMysqlJson($policy->toArray());
            $policyHash = $policy->checksum();
            $validation = HarnessPersistenceValue::canonicalMysqlJson([
                'schema' => 'branch-merge-gate-v1',
                'passed' => $proposal->state === MergeCandidateState::WAITING_APPROVAL,
                'findings' => $proposal->findings,
            ]);
            $lifecycle = $proposal->state === MergeCandidateState::QUARANTINED
                ? 'quarantined'
                : 'draft';
            $gateState = $proposal->state === MergeCandidateState::QUARANTINED
                ? 'quarantined'
                : 'passed';
            $this->db->execute(
                "INSERT INTO chapter_candidates
                    (candidate_uid,user_id,novel_id,chapter_id,source,source_task_id,
                     target_branch_uid,base_revision_uid,merge_source_revision_uid,
                     base_revision_hash,base_snapshot_json,inline_content,content_hash,
                     words,lifecycle,gate_state,validation_json,quarantine_reason,
                     policy_snapshot_json,policy_snapshot_hash,evaluated_at,quarantined_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?)
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $proposal->candidateUid,
                    $proposal->userId,
                    $proposal->novelId,
                    $proposal->chapterId,
                    'branch_merge',
                    'merge:' . $proposal->candidateUid,
                    $proposal->targetBranchUid,
                    $proposal->baseRevisionUid,
                    $proposal->mergeSourceRevisionUid,
                    $baseHash,
                    $baseSnapshotJson,
                    $proposal->content,
                    $proposal->contentHash,
                    self::wordCount($proposal->content),
                    $lifecycle,
                    $gateState,
                    $validation,
                    $proposal->state === MergeCandidateState::QUARANTINED
                        ? 'branch_merge_hard_gate'
                        : null,
                    $policyJson,
                    $policyHash,
                    $proposal->state === MergeCandidateState::QUARANTINED
                        ? HarnessPersistenceValue::mysqlDate(new DateTimeImmutable('now', new DateTimeZone('UTC')))
                        : null,
                ]
            );
            $candidate = $this->db->fetchOne(
                'SELECT * FROM chapter_candidates WHERE candidate_uid=? FOR UPDATE',
                [$proposal->candidateUid]
            );
            if ($candidate === null || !$this->sameCandidate(
                $candidate,
                $proposal,
                $baseHash,
                $lifecycle,
                $gateState,
                $policyHash,
                $policyJson,
                $baseSnapshotJson,
                $validation
            )) {
                throw new HarnessIdempotencyConflictException(
                    'Merge Candidate UID resolved to different immutable coordinates.'
                );
            }

            $candidateId = HarnessPersistenceValue::positiveInt(
                $candidate['id'] ?? null,
                'merge.candidate_id'
            );
            $evaluatedAt = HarnessPersistenceValue::optionalMysqlDate(
                $candidate['evaluated_at'] ?? null,
                'merge.candidate_evaluated_at'
            );
            if ($evaluatedAt === null) {
                throw new HarnessPersistenceException(
                    'Merge Candidate is missing its immutable evaluation timestamp.'
                );
            }
            $expiresAt = HarnessPersistenceValue::mysqlDate(
                (new DateTimeImmutable($evaluatedAt, new DateTimeZone('UTC')))
                    ->modify('+7 days')
            );
            // A failed hard gate remains visible as a quarantined Candidate,
            // but no Approval is created: human approval cannot override the
            // M65 merge hard gate. A passed merge uses the single enum value
            // understood by ApprovalRequest and the API dispatcher.
            if ($proposal->state === MergeCandidateState::QUARANTINED) {
                return $proposal;
            }
            $action = ApprovalActionType::BRANCH_MERGE->value;
            $approvalUid = substr(hash(
                'sha256',
                'branch-merge-approval-v1' . "\0" . $proposal->candidateUid . "\0" . $action
            ), 0, 32);
            $idempotencyKey = 'branch-merge:' . $proposal->candidateUid . ':' . $action;
            $this->db->execute(
                "INSERT INTO approval_requests
                    (approval_uid,user_id,novel_id,chapter_id,run_id,candidate_id,
                     idempotency_key,action_type,risk_level,state,base_revision_hash,
                     policy_snapshot_json,policy_snapshot_hash,requested_by,expires_at)
                 VALUES (?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $approvalUid,
                    $proposal->userId,
                    $proposal->novelId,
                    $proposal->chapterId,
                    $candidateId,
                    $idempotencyKey,
                    $action,
                    'high',
                    'open',
                    $baseHash,
                    $policyJson,
                    $policyHash,
                    'harness_branch_merge',
                    $expiresAt,
                ]
            );
            $approval = $this->db->fetchOne(
                'SELECT * FROM approval_requests WHERE approval_uid=? FOR UPDATE',
                [$approvalUid]
            );
            $storedApprovalPolicyJson = $approval === null
                ? null
                : self::canonicalStoredJson(
                    $approval['policy_snapshot_json'] ?? null,
                    'merge.approval_policy_snapshot_json'
                );
            if ($approval === null
                || ($approval['approval_uid'] ?? null) !== $approvalUid
                || (int)($approval['user_id'] ?? 0) !== $proposal->userId
                || (int)($approval['novel_id'] ?? 0) !== $proposal->novelId
                || (int)($approval['chapter_id'] ?? 0) !== $proposal->chapterId
                || ($approval['run_id'] ?? null) !== null
                || ($approval['summary_artifact_id'] ?? null) !== null
                || (int)($approval['candidate_id'] ?? 0) !== $candidateId
                || ($approval['idempotency_key'] ?? null) !== $idempotencyKey
                || ($approval['action_type'] ?? null) !== $action
                || ($approval['risk_level'] ?? null) !== 'high'
                || ($approval['base_revision_hash'] ?? null) !== $baseHash
                || $storedApprovalPolicyJson !== $policyJson
                || !in_array(($approval['state'] ?? null), [
                    'open', 'approved', 'rejected', 'stale', 'canceled',
                ], true)
                || ($approval['policy_snapshot_hash'] ?? null) !== $policyHash
                || ($approval['requested_by'] ?? null) !== 'harness_branch_merge'
                || ($approval['expires_at'] ?? null) !== $expiresAt
                || !self::approvalMatchesCandidateLifecycle(
                    (string)($approval['state'] ?? ''),
                    (string)($candidate['lifecycle'] ?? '')
                )) {
                throw new HarnessIdempotencyConflictException(
                    'Merge Approval UID resolved to different immutable coordinates.'
                );
            }
            return $proposal;
        });
    }

    /** @return array<string,mixed> */
    private function lockCurrentHeads(MergeCandidateProposal $proposal): array
    {
        $row = $this->db->fetchOne(
            "SELECT sb.branch_uid AS target_branch_uid,sb.user_id AS target_user_id,
                    sb.novel_id AS target_novel_id,sb.is_main AS target_is_main,
                    sb.state AS target_state,n.chapter_words AS target_words,
                    th.revision_uid AS target_head_revision_uid,
                    ms.revision_uid AS source_revision_uid,
                    ms.user_id AS source_user_id,ms.novel_id AS source_novel_id,
                    ms.chapter_id AS source_chapter_id,ms.branch_uid AS source_branch_uid,
                    ss.is_main AS source_is_main,ss.state AS source_state,
                    sh.revision_uid AS source_head_revision_uid
             FROM story_branches sb
             INNER JOIN novels n ON n.id=sb.novel_id AND n.user_id=sb.user_id
             INNER JOIN chapter_revisions ms
                ON ms.revision_uid=? AND ms.user_id=? AND ms.novel_id=? AND ms.chapter_id=?
             INNER JOIN story_branches ss
                ON ss.branch_uid=ms.branch_uid AND ss.user_id=ms.user_id
               AND ss.novel_id=ms.novel_id
             LEFT JOIN chapter_branch_heads th
                ON th.branch_uid=sb.branch_uid AND th.chapter_id=?
             LEFT JOIN chapter_branch_heads sh
                ON sh.branch_uid=ms.branch_uid AND sh.chapter_id=?
             WHERE sb.branch_uid=? AND sb.user_id=? AND sb.novel_id=?
             LIMIT 1 FOR UPDATE",
            [
                $proposal->mergeSourceRevisionUid,
                $proposal->userId,
                $proposal->novelId,
                $proposal->chapterId,
                $proposal->chapterId,
                $proposal->chapterId,
                $proposal->targetBranchUid,
                $proposal->userId,
                $proposal->novelId,
            ]
        );
        if ($row === null
            || ($row['target_branch_uid'] ?? null) !== $proposal->targetBranchUid
            || (int)($row['target_user_id'] ?? 0) !== $proposal->userId
            || (int)($row['target_novel_id'] ?? 0) !== $proposal->novelId
            || (int)($row['target_is_main'] ?? 0) !== 1
            || ($row['target_state'] ?? null) !== 'active'
            || ($row['target_head_revision_uid'] ?? null) !== $proposal->baseRevisionUid
            || ($row['source_revision_uid'] ?? null) !== $proposal->mergeSourceRevisionUid
            || (int)($row['source_user_id'] ?? 0) !== $proposal->userId
            || (int)($row['source_novel_id'] ?? 0) !== $proposal->novelId
            || (int)($row['source_chapter_id'] ?? 0) !== $proposal->chapterId
            || ($row['source_branch_uid'] ?? null) === $proposal->targetBranchUid
            || (int)($row['source_is_main'] ?? 1) !== 0
            || ($row['source_state'] ?? null) !== 'active'
            || ($row['source_head_revision_uid'] ?? null)
                !== $proposal->mergeSourceRevisionUid) {
            throw new HarnessPersistenceException(
                'Merge source or target head changed before Candidate persistence.'
            );
        }
        return $row;
    }

    private static function approvalMatchesCandidateLifecycle(
        string $approvalState,
        string $candidateLifecycle
    ): bool {
        return match ($candidateLifecycle) {
            'draft' => in_array($approvalState, ['open', 'canceled'], true),
            'approved', 'committed' => $approvalState === 'approved',
            'rejected' => $approvalState === 'rejected',
            'stale' => $approvalState === 'stale',
            default => false,
        };
    }

    /** @param array<string,mixed> $row */
    private function sameCandidate(
        array $row,
        MergeCandidateProposal $proposal,
        ?string $baseHash,
        string $lifecycle,
        string $gateState,
        string $policyHash,
        string $policyJson,
        string $baseSnapshotJson,
        string $validationJson
    ): bool {
        $quarantined = $proposal->state === MergeCandidateState::QUARANTINED;
        $storedBaseSnapshotJson = self::canonicalStoredJson(
            $row['base_snapshot_json'] ?? null,
            'merge.candidate_base_snapshot_json'
        );
        $storedValidationJson = self::canonicalStoredJson(
            $row['validation_json'] ?? null,
            'merge.candidate_validation_json'
        );
        $storedPolicyJson = self::canonicalStoredJson(
            $row['policy_snapshot_json'] ?? null,
            'merge.candidate_policy_snapshot_json'
        );
        $evaluatedAt = HarnessPersistenceValue::optionalMysqlDate(
            $row['evaluated_at'] ?? null,
            'merge.candidate_evaluated_at'
        );
        $quarantinedAt = HarnessPersistenceValue::optionalMysqlDate(
            $row['quarantined_at'] ?? null,
            'merge.candidate_quarantined_at'
        );
        return ($row['candidate_uid'] ?? null) === $proposal->candidateUid
            && (int)($row['user_id'] ?? 0) === $proposal->userId
            && (int)($row['novel_id'] ?? 0) === $proposal->novelId
            && (int)($row['chapter_id'] ?? 0) === $proposal->chapterId
            && ($row['run_id'] ?? null) === null
            && ($row['source'] ?? null) === 'branch_merge'
            && ($row['source_task_id'] ?? null) === 'merge:' . $proposal->candidateUid
            && ($row['parent_candidate_uid'] ?? null) === null
            && ($row['supersedes_candidate_uid'] ?? null) === null
            && ($row['target_branch_uid'] ?? null) === $proposal->targetBranchUid
            && ($row['base_revision_uid'] ?? null) === $proposal->baseRevisionUid
            && ($row['merge_source_revision_uid'] ?? null) === $proposal->mergeSourceRevisionUid
            && ($row['base_revision_hash'] ?? null) === $baseHash
            && ($row['content_hash'] ?? null) === $proposal->contentHash
            && ($row['inline_content'] ?? null) === $proposal->content
            && (int)($row['words'] ?? -1) === self::wordCount($proposal->content)
            && ($row['draft_artifact_id'] ?? null) === null
            && ($row['validation_artifact_id'] ?? null) === null
            && $storedBaseSnapshotJson === $baseSnapshotJson
            && ($lifecycle === 'draft'
                ? in_array(($row['lifecycle'] ?? null), [
                    'draft', 'approved', 'committed', 'rejected', 'stale',
                ], true)
                : ($row['lifecycle'] ?? null) === $lifecycle)
            && ($row['gate_state'] ?? null) === $gateState
            && $storedValidationJson === $validationJson
            && ($row['policy_snapshot_hash'] ?? null) === $policyHash
            && $storedPolicyJson === $policyJson
            && ($row['quarantine_reason'] ?? null) === ($quarantined
                ? 'branch_merge_hard_gate'
                : null)
            && $evaluatedAt !== null
            && ($quarantined ? $quarantinedAt !== null : $quarantinedAt === null);
    }

    private static function canonicalStoredJson(mixed $value, string $field): string
    {
        return HarnessPersistenceValue::canonicalMysqlJson(
            HarnessPersistenceValue::decodeJsonArray($value, $field)
        );
    }

    private static function wordCount(string $content): int
    {
        if (!function_exists('countWords')) {
            require_once dirname(__DIR__, 2) . '/helpers.php';
        }
        if (!function_exists('countWords')) {
            throw new RuntimeException('Canonical word counter is unavailable.');
        }
        return max(0, (int)countWords($content));
    }
}
