<?php
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

$novel = DB::fetch('SELECT optimized_chapter FROM novels WHERE id=?', [$novelId]);
if (!$novel) {
    echo json_encode(['error' => '小说不存在']);
    exit;
}

// 获取已生成大纲的总章节数
$totalChapters = DB::count('chapters', 'novel_id=? AND outline IS NOT NULL AND outline != ""', [$novelId]);

$optimizedChapter = (int)($novel['optimized_chapter'] ?? 0);
$progressPercent = $totalChapters > 0 ? round(($optimizedChapter / $totalChapters) * 100, 1) : 0;

echo json_encode([
    'optimized_chapter' => $optimizedChapter,
    'total_chapters' => $totalChapters,
    'progress_percent' => $progressPercent,
    'has_progress' => $optimizedChapter > 0,
]);
