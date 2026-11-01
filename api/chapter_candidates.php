<?php

declare(strict_types=1);

defined('API_VIA_ROUTER') or die('Direct access denied.');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateUiPolicy.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateApiBoundary.php';

requireLoginApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    CandidateApiBoundary::assertGetQuery('chapter_candidates', ['chapter_id']);
    $chapterId = CandidateApiBoundary::positiveInt(
        $_GET['chapter_id'] ?? null,
        'chapter_id'
    );
    $userId = currentUserId();
    // GET is CSRF-exempt and therefore must never call checkChapterOwnership(),
    // whose legacy NULL-owner compatibility path performs an UPDATE.
    $chapter = DB::fetch(
        'SELECT c.id, c.novel_id, c.chapter_number, c.title, c.status, '
        . 'c.canonical_state, c.content_revision_hash '
        . 'FROM chapters c INNER JOIN novels n ON n.id=c.novel_id '
        . 'WHERE c.id=? AND n.user_id=? LIMIT 1',
        [$chapterId, $userId]
    );
    if (!is_array($chapter)) {
        throw new CandidateApiScopeException('Chapter is outside the owned Candidate scope.');
    }
    CandidateUiPolicy::assertEnabledForNovel((int)$chapter['novel_id']);

    $eligibleSql = "(cc.lifecycle IN ('quarantined','approved') "
        . "OR (cc.source='branch_merge' AND cc.lifecycle='draft' "
        . "AND cc.gate_state='passed' AND EXISTS ("
        . 'SELECT 1 FROM approval_requests open_ar '
        . 'WHERE open_ar.candidate_id=cc.id '
        . "AND open_ar.action_type='branch_merge' AND open_ar.state='open')))";
    $budget = DB::fetch(
        'SELECT COUNT(*) AS candidate_count, '
        . 'COALESCE(SUM(OCTET_LENGTH(COALESCE(cc.inline_content,\'\')) '
        . '+ OCTET_LENGTH(COALESCE(cc.validation_json,\'\')) '
        . '+ OCTET_LENGTH(COALESCE(cc.quarantine_reason,\'\'))),0) AS payload_bytes '
        . 'FROM chapter_candidates cc WHERE cc.user_id=? AND cc.novel_id=? '
        . "AND cc.chapter_id=? AND {$eligibleSql}",
        [$userId, (int)$chapter['novel_id'], $chapterId]
    );
    $candidateCount = (int)($budget['candidate_count'] ?? 0);
    $payloadBytes = (int)($budget['payload_bytes'] ?? 0);
    if ($candidateCount > CandidateApiBoundary::MAX_PREVIEW_CANDIDATES
        || $payloadBytes > CandidateApiBoundary::MAX_PREVIEW_BYTES) {
        throw new CandidateApiResponseBudgetException(
            'Candidate preview exceeds the bounded response budget.'
        );
    }

    $rows = DB::fetchAll(
        'SELECT cc.id, cc.candidate_uid, cc.run_id, cc.source, '
        . 'cc.parent_candidate_uid, cc.target_branch_uid, cc.base_revision_uid, '
        . 'cc.merge_source_revision_uid, cc.base_revision_hash, cc.inline_content, '
        . 'cc.content_hash, cc.words, cc.lifecycle, cc.gate_state, '
        . 'cc.quarantine_reason, cc.validation_json, cc.created_at, cc.updated_at '
        . 'FROM chapter_candidates cc '
        . 'WHERE cc.user_id=? AND cc.novel_id=? AND cc.chapter_id=? '
        . "AND {$eligibleSql} "
        . 'ORDER BY cc.created_at DESC, cc.id DESC LIMIT '
        . CandidateApiBoundary::MAX_PREVIEW_CANDIDATES,
        [$userId, (int)$chapter['novel_id'], $chapterId]
    ) ?: [];
    $approvals = DB::fetchAll(
        'SELECT ar.approval_uid, ar.candidate_id, ar.action_type, ar.risk_level, '
        . 'ar.state, ar.decision_reason, ar.expires_at, ar.created_at, ar.decided_at '
        . 'FROM approval_requests ar INNER JOIN chapter_candidates cc ON cc.id=ar.candidate_id '
        . 'WHERE ar.user_id=? AND ar.novel_id=? AND ar.chapter_id=? '
        . "AND ar.state IN ('open','approved') AND {$eligibleSql} "
        . 'ORDER BY ar.created_at DESC, ar.id DESC LIMIT '
        . (CandidateApiBoundary::MAX_APPROVAL_ROWS + 1),
        [$userId, (int)$chapter['novel_id'], $chapterId]
    ) ?: [];
    if (count($approvals) > CandidateApiBoundary::MAX_APPROVAL_ROWS) {
        throw new CandidateApiResponseBudgetException(
            'Candidate approvals exceed the bounded response row budget.'
        );
    }
    $approvalMap = [];
    foreach ($approvals as $approval) {
        $approvalMap[(int)$approval['candidate_id']][] = [
            'approval_uid' => (string)$approval['approval_uid'],
            'action_type' => (string)$approval['action_type'],
            'risk_level' => (string)$approval['risk_level'],
            'state' => (string)$approval['state'],
            'decision_reason' => $approval['decision_reason'],
            'expires_at' => $approval['expires_at'],
            'created_at' => (string)$approval['created_at'],
            'decided_at' => $approval['decided_at'],
        ];
    }

    $candidates = array_map(static function (array $row) use ($approvalMap): array {
        $validation = null;
        if (is_string($row['validation_json'] ?? null) && $row['validation_json'] !== '') {
            $decoded = json_decode($row['validation_json'], true);
            $validation = is_array($decoded) ? $decoded : null;
        }
        return [
            'id' => (int)$row['id'],
            'candidate_uid' => (string)$row['candidate_uid'],
            'run_id' => $row['run_id'] === null ? null : (int)$row['run_id'],
            'source' => (string)$row['source'],
            'parent_candidate_uid' => $row['parent_candidate_uid'],
            'target_branch_uid' => $row['target_branch_uid'] ?? null,
            'base_revision_uid' => $row['base_revision_uid'] ?? null,
            'merge_source_revision_uid' => $row['merge_source_revision_uid'] ?? null,
            'base_revision_hash' => $row['base_revision_hash'],
            'content' => (string)$row['inline_content'],
            'content_hash' => (string)$row['content_hash'],
            'words' => (int)$row['words'],
            'lifecycle' => (string)$row['lifecycle'],
            'gate_state' => (string)$row['gate_state'],
            'quarantine_reason' => $row['quarantine_reason'],
            'validation' => $validation,
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
            'approvals' => $approvalMap[(int)$row['id']] ?? [],
        ];
    }, $rows);

    echo CandidateApiBoundary::encodePreviewResponse([
        'ok' => true,
        'chapter' => [
            'id' => (int)$chapter['id'],
            'novel_id' => (int)$chapter['novel_id'],
            'chapter_number' => (int)$chapter['chapter_number'],
            'title' => (string)$chapter['title'],
            'status' => (string)$chapter['status'],
            'canonical_state' => (string)$chapter['canonical_state'],
            'content_revision_hash' => $chapter['content_revision_hash'],
        ],
        'candidates' => $candidates,
    ]);
} catch (Throwable $exception) {
    CandidateApiBoundary::emitError($exception);
}
