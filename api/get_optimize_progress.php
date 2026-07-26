<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 获取大纲优化进度 API
 * GET: { novel_id }
 * 返回: { optimized_chapter, total_chapters, progress_percent }
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

header('Content-Type: application/json; charset=utf-8');

$novelId = (int)($_GET['novel_id'] ?? 0);

if (!$novelId) {
    echo json_encode(['error' => '缺少 novel_id 参数']);
    exit;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
checkNovelOwnership($novelId, $userId);

$novel = DB::fetch('SELECT optimized_chapter FROM novels WHERE id=?', [$novelId]);
if (!$novel) {
    echo json_encode(['error' => '小说不存在']);
    exit;
}

// 获取已生成大纲的总章节数
$optimizableWhere = 'novel_id=? AND outline IS NOT NULL AND outline != ""'
    . ' AND status NOT IN ("completed","writing")';
$totalChapters = DB::count('chapters', $optimizableWhere, [$novelId]);

$optimizedChapter = (int)($novel['optimized_chapter'] ?? 0);

// optimized_chapter 存的是“章节号”，而优化批次按“有细纲章节的数组位置”切片。
// 细纲存在空洞时两者不相等，这里换算出位置（号 ≤ optimized_chapter 的有纲章节数）供前端恢复批次。
$optimizedPosition = $optimizedChapter > 0
    ? DB::count('chapters', $optimizableWhere . ' AND chapter_number <= ?', [$novelId, $optimizedChapter])
    : 0;

$progressPercent = $totalChapters > 0 ? round(($optimizedPosition / $totalChapters) * 100, 1) : 0;

echo json_encode([
    'optimized_chapter' => $optimizedChapter,
    'optimized_position' => $optimizedPosition,
    'total_chapters' => $totalChapters,
    'progress_percent' => $progressPercent,
    'has_progress' => $optimizedChapter > 0,
    // 批次大小（与 optimize_outline_ajax.php 同源），前端据此换算恢复批次位置
    'batch_size' => max(1, min(50, (int)(defined('CFG_OPTIMIZE_BATCH') ? CFG_OPTIMIZE_BATCH : 10))),
]);
