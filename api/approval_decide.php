<?php

declare(strict_types=1);

defined('API_VIA_ROUTER') or die('Direct access denied.');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/bootstrap.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateApiBoundary.php';
require_once dirname(__DIR__) . '/includes/quality/CanonicalCommitService.php';

requireLoginApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $input = CandidateApiBoundary::readPostObject('approval_decide', [
        'approval_uid',
        'decision',
        'idempotency_key',
        'reason',
    ]);
    $approvalUid = CandidateApiBoundary::requiredString(
        $input,
        'approval_uid',
        32,
        '/^[a-f0-9]{32}$/D'
    );
    $decisionValue = CandidateApiBoundary::requiredString(
        $input,
        'decision',
        16,
        '/^(?:approved|rejected|canceled)$/D'
    );
    $idempotencyKey = CandidateApiBoundary::requiredString(
        $input,
        'idempotency_key',
        191,
        '/^[A-Za-z0-9._:-]{8,191}$/D'
    );
    $reason = CandidateApiBoundary::optionalText($input, 'reason', 500);
    $state = match ($decisionValue) {
        'approved' => ApprovalRequestState::APPROVED,
        'rejected' => ApprovalRequestState::REJECTED,
        'canceled' => ApprovalRequestState::CANCELED,
        default => throw new CandidateApiRequestException('decision 格式无效'),
    };

    $userId = currentUserId();
    $approvalOwner = DB::fetch(
        'SELECT novel_id FROM approval_requests WHERE user_id=? AND approval_uid=? LIMIT 1',
        [$userId, $approvalUid]
    );
    if (!is_array($approvalOwner)) {
        throw new CandidateApiScopeException('Approval is outside the owned Candidate scope.');
    }
    CandidateUiPolicy::assertEnabledForNovel((int)$approvalOwner['novel_id']);
    $repository = new ApprovalRequestRepository(DB::getPdo());
    $resolved = $repository->resolve(
        $userId,
        $approvalUid,
        new ApprovalDecision(
            $state,
            $idempotencyKey,
            'user-' . $userId,
            $reason,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        )
    );
    $commit = null;
    if ($resolved->state === ApprovalRequestState::APPROVED) {
        $service = new CanonicalCommitService();
        $commitResult = match ($resolved->actionType) {
            ApprovalActionType::FORCE_COMMIT => $service->commitApprovedApproval(
                $userId,
                $approvalUid
            ),
            ApprovalActionType::BRANCH_MERGE => $service->commitApprovedBranchMerge(
                $userId,
                $approvalUid
            ),
            default => null,
        };
        if (is_array($commitResult)) {
            $commit = [
                'committed' => (bool)($commitResult['committed'] ?? false),
                'candidate_uid' => (string)($commitResult['candidate_uid'] ?? ''),
                'revision_hash' => (string)($commitResult['revision_hash'] ?? ''),
                'branch_uid' => (string)($commitResult['branch_uid'] ?? ''),
                'revision_uid' => (string)($commitResult['revision_uid'] ?? ''),
                'postprocess_job_id' => (int)($commitResult['postprocess_job_id'] ?? 0),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'approval' => [
            'approval_uid' => $resolved->approvalUid,
            'candidate_id' => $resolved->candidateId,
            'novel_id' => $resolved->novelId,
            'chapter_id' => $resolved->chapterId,
            'state' => $resolved->state->value,
            'decision_reason' => $resolved->decisionReason,
            'decided_at' => $resolved->decidedAt,
        ],
        'stale' => $resolved->state === ApprovalRequestState::STALE,
        'commit' => $commit,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    CandidateApiBoundary::emitError($exception);
}
