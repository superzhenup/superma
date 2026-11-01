<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
if (!defined('APP_LOADED')) define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/quality/ChapterRewriteTaskStore.php';
require_once dirname(__DIR__) . '/includes/quality/ChapterRewriteAdoptionService.php';
require_once dirname(__DIR__) . '/includes/quality/ChapterRewriteWorkerLauncher.php';
requireLoginApi();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); jsonResponse(false, null, '请使用 POST 请求', 'method_not_allowed');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400); jsonResponse(false, null, '改写任务参数无效', 'invalid_request');
}
$chapterId = filter_var($input['chapter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $input['action'] ?? 'status';
$taskId = $input['task_id'] ?? null;
if ($chapterId === false || !is_string($action) || !in_array($action, ['start', 'status', 'adopt'], true)
    || ($taskId !== null && (!is_string($taskId) || preg_match('/^[a-f0-9]{32}$/D', $taskId) !== 1))
    || ($action !== 'status' && $taskId === null)) {
    http_response_code(400); jsonResponse(false, null, '改写任务参数无效', 'invalid_request');
}
$userId = currentUserId();
checkChapterOwnership($chapterId, $userId);
$chapter = DB::fetch('SELECT c.*, n.user_id AS owner_id FROM chapters c INNER JOIN novels n ON n.id=c.novel_id'
    . ' WHERE c.id=? AND n.user_id=?', [$chapterId, $userId]);
if (!$chapter) { http_response_code(404); jsonResponse(false, null, '章节不存在或无权访问', 'not_found'); }
$store = new ChapterRewriteTaskStore();
$adoption = new ChapterRewriteAdoptionService();
try {
    if ($action === 'status') {
        jsonResponse(true, ['task' => $adoption->status($store, $userId, $chapterId, $taskId)]);
    }
    if ($action === 'adopt') {
        $task = $adoption->adopt($store, $userId, $chapterId, $taskId);
        // Memory rebuild may call a model. It runs after the committed adoption
        // in a separate CLI, with its own existing hash/idempotency protection.
        if (!ChapterRewriteWorkerLauncher::launch($chapterId, $taskId, 'finalize')) {
            $task['message'] = '改写稿已采纳；后台记忆刷新未启动，可稍后从章节记忆操作重试';
        }
        jsonResponse(true, ['task' => $task], '改写稿已采纳');
    }
    // Starting a task is idempotent under the browser's random task ID. A lost
    // start reply never authorizes a new provider call for that same ID.
    $expectedHash = $input['expected_content_hash'] ?? '';
    if (!is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1
        || !hash_equals($expectedHash, hash('sha256', (string)$chapter['content']))) {
        throw new ChapterRewriteConflict('正文已变化，请先保存编辑并刷新页面，再重新改写');
    }
    if (trim((string)$chapter['content']) === '' || ($chapter['status'] ?? '') === 'writing') {
        throw new ChapterRewriteConflict('章节为空或正在写作，暂时无法生成改写稿');
    }
    $recoveredTaskId = $adoption->reconcileBeforeStart($store, $userId, $chapterId);
    if ($recoveredTaskId !== null) ChapterRewriteWorkerLauncher::launch($chapterId, $recoveredTaskId, 'finalize');
    if (!$store->enqueue($userId, (int)$chapter['novel_id'], $chapterId, $taskId, $expectedHash)) {
        $existing = $adoption->status($store, $userId, $chapterId);
        if ($existing === null) throw new ChapterRewriteConflict('该任务编号已使用，请重新打开改写对话框后重试');
        jsonResponse(true, ['task' => $existing], '正在核对现有改写任务');
    }
    if (!ChapterRewriteWorkerLauncher::launch($chapterId, $taskId)) {
        $store->failQueued($userId, $chapterId, $taskId);
        http_response_code(503);
        jsonResponse(false, ['task' => $store->snapshot($userId, $chapterId, $taskId)],
            '后台改写未启动，请检查 PHP CLI 路径、进程启动权限和存储目录权限', 'launch_failed');
    }
    jsonResponse(true, ['task' => $adoption->status($store, $userId, $chapterId, $taskId)], '后台改写任务已提交');
} catch (ChapterRewriteConflict|ChapterMutationConflict $error) {
    http_response_code(409);
    jsonResponse(false, ['task' => $adoption->status($store, $userId, $chapterId, $taskId)], $error->getMessage(), 'chapter_changed');
} catch (Throwable $error) {
    error_log('[' . ($taskId ?? error_trace_id()) . '] chapter rewrite request failed (' . get_class($error) . ')');
    http_response_code(500);
    jsonResponse(false, null, '改写任务暂时无法处理，请查询任务状态后重试', 'rewrite_unavailable');
}
