<?php
/**
 * 获取章节简介
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireLoginApi();
    
    $novelId = intval($_GET['novel_id'] ?? 0);
    $chapterNumber = intval($_GET['chapter_number'] ?? 0);

    if (!$novelId || !$chapterNumber) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        exit;
    }

    $synopsis = DB::fetch('
        SELECT id, chapter_number, synopsis, scene_breakdown, dialogue_beats,
               sensory_details, pacing, cliffhanger, foreshadowing, callbacks,
               created_at, updated_at
        FROM chapter_synopses 
        WHERE novel_id = ? AND chapter_number = ?
    ', [$novelId, $chapterNumber]);

    if (!$synopsis) {
        echo json_encode(['success' => false, 'message' => '暂无章节简介']);
        exit;
    }

    // 解码JSON字段
    $synopsis['scene_breakdown'] = json_decode($synopsis['scene_breakdown'], true);
    $synopsis['dialogue_beats'] = json_decode($synopsis['dialogue_beats'], true);
    $synopsis['sensory_details'] = json_decode($synopsis['sensory_details'], true);
    $synopsis['foreshadowing'] = json_decode($synopsis['foreshadowing'], true);
    $synopsis['callbacks'] = json_decode($synopsis['callbacks'], true);

    echo json_encode(['success' => true, 'data' => $synopsis]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
