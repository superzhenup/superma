<?php

declare(strict_types=1);

defined('API_VIA_ROUTER') or die('Direct access denied.');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateUiPolicy.php';

requireLoginApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ================================================================
// 错误分类契约（审计 2026-10-31 T2）：
//   参数错误（仅本文件受控文案）→ 400 + 原文；
//   任务不存在 → 404；无权访问此任务 → 403；
//   候选稿 UI 未开放（CandidateUiDisabledException，业务条件）→
//     403 + ok:false + code:candidate_ui_disabled + 固定文案
//     （与 CandidateApiBoundary::emitError / api/branch_actions.php 约定一致）；
//   其余任何 Throwable（含可能带 SQL 片段/内部路径的 PDOException）→
//   500 + 友好文案 + error_trace_id() 追踪号，详细信息只写 error_log
//   （与 api/import_chapter_synopses.php 的审计 P0 约定一致）。
// 分类只认固定的异常类型，不做任何消息内容匹配；
// checkNovelOwnership / checkChapterOwnership 走 jsonResponse 输出并 exit，
// 不经本 catch，行为保持不变。
// ================================================================

/** 参数错误（400 原文）：仅本文件以受控文案抛出。 */
final class RunStatusBadRequestException extends InvalidArgumentException
{
}

/** 任务不存在（404）：消息固定。 */
final class RunStatusNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('任务不存在');
    }
}

/** 无权访问此任务（403）：消息固定。 */
final class RunStatusForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('无权访问此任务');
    }
}

try {
    $coordinate = trim((string)($_GET['run_id'] ?? ''));
    if ($coordinate === '') {
        throw new RunStatusBadRequestException('缺少 run_id');
    }

    if (preg_match('/^[1-9][0-9]*$/D', $coordinate) === 1) {
        $run = DB::fetch('SELECT * FROM generation_runs WHERE id=? LIMIT 1', [(int)$coordinate]);
    } elseif (preg_match('/^[a-f0-9]{32}$/D', $coordinate) === 1) {
        $run = DB::fetch('SELECT * FROM generation_runs WHERE run_uid=? LIMIT 1', [$coordinate]);
    } else {
        throw new RunStatusBadRequestException('run_id 格式无效');
    }
    if (!is_array($run)) {
        throw new RunStatusNotFoundException();
    }

    $userId = currentUserId();
    if ((int)($run['user_id'] ?? 0) !== $userId) {
        throw new RunStatusForbiddenException();
    }
    checkNovelOwnership((int)$run['novel_id'], $userId);
    CandidateUiPolicy::assertEnabledForNovel((int)$run['novel_id']);
    if (!empty($run['chapter_id'])) {
        checkChapterOwnership((int)$run['chapter_id'], $userId);
    }

    $candidateCounts = DB::fetchAll(
        'SELECT lifecycle, COUNT(*) AS total FROM chapter_candidates '
        . 'WHERE run_id=? AND user_id=? GROUP BY lifecycle',
        [(int)$run['id'], $userId]
    ) ?: [];
    $counts = [];
    foreach ($candidateCounts as $row) {
        $counts[(string)$row['lifecycle']] = (int)$row['total'];
    }
    $openApprovals = (int)(DB::fetchColumn(
        "SELECT COUNT(*) FROM approval_requests WHERE run_id=? AND user_id=? AND state='open'",
        [(int)$run['id'], $userId]
    ) ?: 0);

    echo json_encode([
        'ok' => true,
        'run' => [
            'id' => (int)$run['id'],
            'run_uid' => (string)$run['run_uid'],
            'novel_id' => (int)$run['novel_id'],
            'chapter_id' => $run['chapter_id'] === null ? null : (int)$run['chapter_id'],
            'run_type' => (string)$run['run_type'],
            'workflow_name' => (string)$run['workflow_name'],
            'workflow_version' => (string)$run['workflow_version'],
            'authority_mode' => (string)$run['authority_mode'],
            'state' => (string)$run['state'],
            'progress' => (int)$run['progress'],
            'attempt' => (int)$run['attempt'],
            'max_attempts' => (int)$run['max_attempts'],
            'cancel_requested' => (bool)$run['cancel_requested'],
            'created_at' => (string)$run['created_at'],
            'updated_at' => (string)$run['updated_at'],
        ],
        'candidate_counts' => $counts,
        'open_approval_count' => $openApprovals,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RunStatusBadRequestException $exception) {
    // 参数错误 → 400 + 受控原文
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RunStatusNotFoundException $exception) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RunStatusForbiddenException $exception) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (CandidateUiDisabledException $exception) {
    // 审计 T2 复核：发布闸门是业务条件（功能未开放），不是内部错误，不得落 500 兜底。
    // 403 + 固定 code/msg 与 CandidateApiBoundary::emitError / api/branch_actions.php 约定一致。
    http_response_code(403);
    echo json_encode([
        'ok'   => false,
        'code' => 'candidate_ui_disabled',
        'msg'  => '候选稿审批功能尚未对这部小说开放',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    // 审计 P0：客户端只看到友好文案 + 追踪号；完整异常细节（类型/消息/文件/行号/堆栈）
    // 只写 error_log，绝不外显——原样回传可能泄露 SQL 片段与内部路径。
    $rid = error_trace_id();
    http_response_code(500);
    error_log(sprintf(
        '[%s] run_status %s: %s in %s:%d',
        $rid,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    error_log('[' . $rid . '] 堆栈跟踪: ' . $exception->getTraceAsString());
    echo json_encode([
        'ok'         => false,
        'msg'        => '查询任务状态失败，请稍后重试（追踪号 ' . $rid . '）',
        'request_id' => $rid,
    ], JSON_UNESCAPED_UNICODE);
}
