<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
if (!defined('APP_LOADED')) define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRetryService.php';
requireLoginApi(); // Includes CSRF verification for the required POST.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); jsonResponse(false, null, '请使用 POST 请求', 'method_not_allowed');
}
$input = json_decode(file_get_contents('php://input'), true);
$chapterId = filter_var($input['chapter_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$jobId = filter_var($input['job_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$expectedHash = $input['expected_content_hash'] ?? null;
if ($chapterId === false || $jobId === false || !is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
    http_response_code(400); jsonResponse(false, null, '重建任务参数无效', 'invalid_request');
}
$userId = currentUserId();
checkChapterOwnership($chapterId, $userId);
session_write_close();
try {
    $state = PostprocessRetryService::retry($userId, $chapterId, $jobId, $expectedHash);
    $launched = false;
    if ($state !== 'done') {
        require_once dirname(__DIR__) . '/includes/tasks/PostprocessWorkerLauncher.php';
        $launched = PostprocessWorkerLauncher::launch(true);
    }
    jsonResponse(true, ['state'=>$state, 'worker_launched'=>$launched],
        $state === 'done' ? '该版本已完成重建' : '重建任务已开始在后台执行，完成后即可继续写作');
} catch (PostprocessRetryConflict $error) {
    http_response_code(409); jsonResponse(false, null, $error->getMessage(), 'postprocess_changed');
} catch (Throwable $error) {
    error_log('Postprocess retry failed: ' . $error->getMessage());
    http_response_code(503); jsonResponse(false, null, '暂时无法安排重建，请稍后重试；若持续失败，请检查后台任务配置', 'postprocess_unavailable');
}
