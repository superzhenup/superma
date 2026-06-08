<?php
/**
 * AJAX chapter-list renderer.
 *
 * GET: novel_id, page
 * Returns only the HTML needed to replace #tab-chapters.
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
require_once dirname(__DIR__) . '/includes/data.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

requireLoginApi();

try {
    $id = (int)($_GET['novel_id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('缺少小说ID');
    }

    $novel = getNovel($id);
    if (!$novel) {
        throw new RuntimeException('小说不存在');
    }

    $totalChapterCount = getNovelChapterCount($id);
    $perPage = 50;
    $totalPages = max(1, (int)ceil($totalChapterCount / $perPage));
    $currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $chapters = getNovelChapters($id, $currentPage, $perPage);
    $allChapters = $totalChapterCount > 0 ? array_fill(0, $totalChapterCount, true) : [];

    try {
        $synopsisRows = DB::fetchAll(
            'SELECT chapter_number, synopsis FROM chapter_synopses WHERE novel_id=?',
            [$id]
        );
    } catch (Throwable $e) {
        error_log('chapter_list.php: 查询章节概要失败 — ' . $e->getMessage());
        $synopsisRows = [];
    }
    $synopsisMap = array_column($synopsisRows, 'synopsis', 'chapter_number');

    ob_start();
    include dirname(__DIR__) . '/includes/chapter_list_partial.php';
    $html = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html,
        'page' => $currentPage,
        'total_pages' => $totalPages,
        'total_chapters' => $totalChapterCount,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] chapter_list: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode([
        'ok'         => false,
        'msg'        => '章节列表加载失败，请稍后重试',
        'error'      => '章节列表加载失败，请稍后重试',
        'code'       => 'internal_error',
        'request_id' => $rid,
    ], JSON_UNESCAPED_UNICODE);
}
