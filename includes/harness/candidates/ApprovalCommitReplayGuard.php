<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ApprovalRequest.php';

/**
 * Authorizes an idempotent commit-only replay after a human decision is
 * already durable. It never resolves or mutates the Approval itself.
 */
final class ApprovalCommitReplayGuard
{
    public static function assertAllowed(
        int $authenticatedUserId,
        string $approvalUid,
        ApprovalRequest $approval
    ): void {
        $authenticatedUserId = HarnessPersistenceValue::positiveInt(
            $authenticatedUserId,
            'authenticated_user_id'
        );
        $approvalUid = HarnessPersistenceValue::uid($approvalUid, 'approval_uid');

        if ($approval->userId !== $authenticatedUserId
            || !hash_equals($approval->approvalUid, $approvalUid)) {
            throw new HarnessStateTransitionException(
                'Commit replay is outside the owned Approval scope.'
            );
        }
        if ($approval->state !== ApprovalRequestState::APPROVED
            || !in_array($approval->actionType, [
                ApprovalActionType::FORCE_COMMIT,
                ApprovalActionType::BRANCH_MERGE,
            ], true)) {
            throw new HarnessStateTransitionException(
                'Only an approved Canon-changing Approval may replay commit.'
            );
        }
        if ($approval->decidedBy === null
            || $approval->decisionIdempotencyKey === null
            || $approval->decisionPayloadHash === null
            || $approval->decidedAt === null) {
            throw new HarnessStateTransitionException(
                'Commit replay lacks authentic frozen decision provenance.'
            );
        }
        if ($approval->actionType === ApprovalActionType::FORCE_COMMIT
            && $approval->runId === null) {
            throw new HarnessStateTransitionException(
                'Force-commit replay requires its originating Run lineage.'
            );
        }
        if ($approval->actionType === ApprovalActionType::BRANCH_MERGE
            && $approval->runId !== null) {
            throw new HarnessStateTransitionException(
                'Branch-merge replay must retain its Run-less revision lineage.'
            );
        }
    }
}
