<?php
/**
 * 导出向量数据API
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();

$novelId = (int)($_GET['novel_id'] ?? 0);
api_error_unless($novelId > 0, '缺少小说ID');

$novel = DB::fetch('SELECT title FROM novels WHERE id = ?', [$novelId]);
api_error_unless((bool)$novel, '小说不存在', 404);

$embeddings = DB::fetchAll(
    'SELECT id, source_type, source_id, content, embedding_model, created_at FROM novel_embeddings WHERE novel_id = ? ORDER BY source_type, id',
    [$novelId]
);

$filename = preg_replace('/[^\w\-]/', '_', $novel['title']) . '_embeddings_' . date('Ymd_His') . '.json';

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo json_encode([
    'novel_id' => $novelId,
    'novel_title' => $novel['title'],
    'export_time' => date('Y-m-d H:i:s'),
    'total_count' => count($embeddings),
    'embeddings' => $embeddings
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
