<?php
if (!defined('API_VIA_ROUTER')) {
    http_response_code(403);
    exit;
}

ob_start();
ini_set('display_errors', '0');

defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__)
    . '/includes/harness/runtime/WritingTaskCancellationService.php';
requireLoginApi();

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $novelId = (int)($input['novel_id'] ?? 0);
    $chapterId = (int)($input['chapter_id'] ?? 0);
    $actionValue = (string)($input['action'] ?? 'cancel');
    $action = WritingTaskCancellationAction::tryFrom($actionValue);
    if ($action === null) {
        throw new InvalidArgumentException('无效操作类型：' . $actionValue);
    }
    if ($novelId <= 0) {
        throw new InvalidArgumentException('缺少小说 ID');
    }
    if ($action === WritingTaskCancellationAction::RESET_CHAPTER && $chapterId <= 0) {
        throw new InvalidArgumentException('重置单个章节需要提供 chapter_id');
    }

    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    checkNovelOwnership($novelId, $userId);
    if ($action === WritingTaskCancellationAction::RESET_CHAPTER) {
        checkChapterOwnership($chapterId, $userId);
    }

    $outcome = (new WritingTaskCancellationService())->execute(
        new WritingTaskCancellationRequest(
            $userId,
            $novelId,
            $action,
            $chapterId > 0 ? $chapterId : null
        )
    );
    echo json_encode([
        'ok' => true,
        'msg' => $outcome->message,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    echo json_encode(
        safe_api_error_payload($error, '取消写作失败，请稍后重试'),
        JSON_UNESCAPED_UNICODE
    );
}
