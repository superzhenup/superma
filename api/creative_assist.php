<?php
/**
 * 智能创作辅助系统 API
 *
 * action=context        获取本章目标、上下文和风险
 * action=save_directive 保存本章临时写作指令
 * action=quality        运行章节质量检测报告
 */

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/CreativeAssistService.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

requireLoginApi();

$service = new CreativeAssistService();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'context':
            $novelId = (int)($_GET['novel_id'] ?? 0);
            $chapterId = (int)($_GET['chapter_id'] ?? 0) ?: null;
            if (!$novelId) throw new RuntimeException('缺少小说ID');

            jsonResponse(true, $service->buildContext($novelId, $chapterId));
            break;

        case 'save_directive':
            $input = read_json_input();
            $novelId = (int)($input['novel_id'] ?? 0);
            $chapterNumber = (int)($input['chapter_number'] ?? 0);
            $directive = trim((string)($input['directive'] ?? ''));

            jsonResponse(true, $service->saveTemporaryDirective($novelId, $chapterNumber, $directive));
            break;

        case 'quality':
            $input = read_json_input();
            $novelId = (int)($input['novel_id'] ?? ($_GET['novel_id'] ?? 0));
            $chapterId = (int)($input['chapter_id'] ?? ($_GET['chapter_id'] ?? 0)) ?: null;
            if (!$novelId) throw new RuntimeException('缺少小说ID');

            jsonResponse(true, $service->buildQualityReport($novelId, $chapterId));
            break;

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    error_log('creative_assist: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(false, null, '操作失败，请稍后重试');
}

function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : null;
    if (is_array($input)) return $input;
    return $_POST ?: [];
}
