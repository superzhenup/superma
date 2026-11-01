<?php

declare(strict_types=1);

defined('API_VIA_ROUTER') or die('Direct access denied.');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/bootstrap.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateApiBoundary.php';
require_once dirname(__DIR__) . '/includes/quality/CanonicalCommitService.php';

// requireLoginApi enforces both authenticated ownership context and CSRF for POST.
requireLoginApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $input = CandidateApiBoundary::readPostObject(
        'approval_commit',
        ['approval_uid']
    );
    $approvalUid = CandidateApiBoundary::requiredString(
        $input,
        'approval_uid',
        32,
        '/^[a-f0-9]{32}$/D'
    );

    $userId = currentUserId();
    $repository = new ApprovalRequestRepository(DB::getPdo());
    $approval = $repository->findByUid($userId, $approvalUid);
    if (!$approval instanceof ApprovalRequest) {
        throw new CandidateApiScopeException('Approval is outside the owned Candidate scope.');
    }
    CandidateUiPolicy::assertEnabledForNovel($approval->novelId);
    ApprovalCommitReplayGuard::assertAllowed($userId, $approvalUid, $approval);

    // This is intentionally commit-only. Do not call ApprovalRequestRepository::resolve():
    // the terminal decision and its idempotency/provenance remain byte-for-byte frozen.
    $service = new CanonicalCommitService();
    $result = match ($approval->actionType) {
        ApprovalActionType::FORCE_COMMIT => $service->commitApprovedApproval(
            $userId,
            $approvalUid
        ),
        ApprovalActionType::BRANCH_MERGE => $service->commitApprovedBranchMerge(
            $userId,
            $approvalUid
        ),
        default => throw new LogicException('Replay guard admitted an unsupported Approval action.'),
    };

    echo json_encode([
        'ok' => true,
        'approval' => [
            'approval_uid' => $approval->approvalUid,
            'candidate_id' => $approval->candidateId,
            'novel_id' => $approval->novelId,
            'chapter_id' => $approval->chapterId,
            'state' => $approval->state->value,
        ],
        'commit' => [
            'committed' => (bool)($result['committed'] ?? false),
            'candidate_uid' => (string)($result['candidate_uid'] ?? ''),
            'revision_hash' => (string)($result['revision_hash'] ?? ''),
            'branch_uid' => (string)($result['branch_uid'] ?? ''),
            'revision_uid' => (string)($result['revision_uid'] ?? ''),
            'postprocess_job_id' => (int)($result['postprocess_job_id'] ?? 0),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    CandidateApiBoundary::emitError($exception);
}
