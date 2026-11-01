<?php

declare(strict_types=1);

defined('API_VIA_ROUTER') or die('Direct access denied.');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateUiPolicy.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateApiBoundary.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/bootstrap.php';

requireLoginApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $input = CandidateApiBoundary::readPostObject(
        'candidate_retry',
        ['candidate_uid']
    );
    $candidateUid = CandidateApiBoundary::requiredString(
        $input,
        'candidate_uid',
        32,
        '/^[a-f0-9]{32}$/D'
    );
    $userId = currentUserId();
    $pdo = DB::getPdo();
    if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
        throw new RuntimeException('无法启动候选重试事务');
    }
    try {
        $row = DB::fetch(
            'SELECT cc.*, c.status AS chapter_status, c.content_revision_hash AS chapter_revision_hash, '
            . 'n.user_id AS novel_user_id '
            . 'FROM chapter_candidates cc '
            . 'INNER JOIN chapters c ON c.id=cc.chapter_id AND c.novel_id=cc.novel_id '
            . 'INNER JOIN novels n ON n.id=cc.novel_id '
            . 'WHERE cc.candidate_uid=? LIMIT 1 FOR UPDATE',
            [$candidateUid]
        );
        if (!is_array($row) || (int)$row['user_id'] !== $userId
            || (int)$row['novel_user_id'] !== $userId) {
            throw new CandidateApiScopeException(
                'Candidate is outside the owned retry scope.'
            );
        }
        CandidateUiPolicy::assertEnabledForNovel((int)$row['novel_id']);
        $lifecycle = (string)$row['lifecycle'];
        if ($lifecycle === 'superseded') {
            $pdo->commit();
            echo json_encode([
                'ok' => true,
                'already_scheduled' => true,
                'novel_id' => (int)$row['novel_id'],
                'chapter_id' => (int)$row['chapter_id'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($lifecycle !== 'quarantined' || (string)$row['source'] === 'branch_merge') {
            throw new CandidateApiConflictException(
                'Only a non-Branch quarantined Candidate may be retried.'
            );
        }
        $baseRevision = $row['base_revision_hash'];
        $chapterRevision = $row['chapter_revision_hash'];
        if (($baseRevision === null) !== ($chapterRevision === null)
            || (is_string($baseRevision) && !hash_equals($baseRevision, (string)$chapterRevision))) {
            throw new CandidateApiConflictException(
                'Candidate base Revision no longer matches Canon.'
            );
        }
        if ((string)$row['chapter_status'] === 'writing') {
            throw new CandidateApiConflictException(
                'Chapter already has an active writer.'
            );
        }

        // A retry retires the entire pending human-decision surface. Resolve
        // every still-open Approval inside this same outer transaction before
        // superseding the Candidate, so no approved/open authorization can be
        // left pointing at hidden content.
        $openApprovals = DB::fetchAll(
            "SELECT approval_uid FROM approval_requests
             WHERE user_id=? AND novel_id=? AND chapter_id=? AND candidate_id=?
               AND state='open' FOR UPDATE",
            [
                $userId,
                (int)$row['novel_id'],
                (int)$row['chapter_id'],
                (int)$row['id'],
            ]
        ) ?: [];
        $approvalRepository = new ApprovalRequestRepository($pdo);
        $decisionTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($openApprovals as $openApproval) {
            $openApprovalUid = (string)($openApproval['approval_uid'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/D', $openApprovalUid) !== 1) {
                throw new CandidateApiConflictException(
                    'Open Approval has invalid durable coordinates.'
                );
            }
            $approvalRepository->resolve(
                $userId,
                $openApprovalUid,
                new ApprovalDecision(
                    ApprovalRequestState::CANCELED,
                    'candidate-retry:' . $candidateUid . ':' . $openApprovalUid,
                    'user-' . $userId,
                    null,
                    $decisionTime
                )
            );
        }
        $changed = DB::execute(
            "UPDATE chapter_candidates SET lifecycle='superseded', updated_at=UTC_TIMESTAMP(6) "
            . 'WHERE id=? AND lifecycle=?',
            [(int)$row['id'], $lifecycle]
        );
        if ($changed !== 1) {
            throw new CandidateApiConflictException(
                'Candidate lifecycle changed concurrently.'
            );
        }
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'already_scheduled' => false,
            'novel_id' => (int)$row['novel_id'],
            'chapter_id' => (int)$row['chapter_id'],
            'message' => '旧候选稿已封存；请仅重写当前章节',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
} catch (Throwable $exception) {
    CandidateApiBoundary::emitError($exception);
}
