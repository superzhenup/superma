<?php
/**
 * 更新章节简介
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireLoginApi();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $novelId = intval($input['novel_id'] ?? 0);
    $chapterNumber = intval($input['chapter_number'] ?? 0);
    $synopsis = trim($input['synopsis'] ?? '');
    $sceneBreakdown = $input['scene_breakdown'] ?? null;
    $dialogueBeats = $input['dialogue_beats'] ?? null;
    $sensoryDetails = $input['sensory_details'] ?? null;
    $pacing = trim($input['pacing'] ?? '');
    $cliffhanger = trim($input['cliffhanger'] ?? '');
    $foreshadowing = $input['foreshadowing'] ?? null;
    $callbacks = $input['callbacks'] ?? null;

    if (!$novelId || !$chapterNumber || !$synopsis) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        exit;
    }

    // 检查是否已存在
    $exists = DB::fetch('SELECT id FROM chapter_synopses WHERE novel_id = ? AND chapter_number = ?', [$novelId, $chapterNumber]);

    if ($exists) {
        // 更新
        DB::update(
            'chapter_synopses',
            [
                'synopsis' => $synopsis,
                'scene_breakdown' => $sceneBreakdown ? json_encode($sceneBreakdown, JSON_UNESCAPED_UNICODE) : null,
                'dialogue_beats' => $dialogueBeats ? json_encode($dialogueBeats, JSON_UNESCAPED_UNICODE) : null,
                'sensory_details' => $sensoryDetails ? json_encode($sensoryDetails, JSON_UNESCAPED_UNICODE) : null,
                'pacing' => $pacing,
                'cliffhanger' => $cliffhanger,
                'foreshadowing' => $foreshadowing ? json_encode($foreshadowing, JSON_UNESCAPED_UNICODE) : null,
                'callbacks' => $callbacks ? json_encode($callbacks, JSON_UNESCAPED_UNICODE) : null
            ],
            'novel_id = ? AND chapter_number = ?',
            [$novelId, $chapterNumber]
        );
    } else {
        // 插入
        $synopsisId = DB::insert('chapter_synopses', [
            'novel_id' => $novelId,
            'chapter_number' => $chapterNumber,
            'synopsis' => $synopsis,
            'scene_breakdown' => $sceneBreakdown ? json_encode($sceneBreakdown, JSON_UNESCAPED_UNICODE) : null,
            'dialogue_beats' => $dialogueBeats ? json_encode($dialogueBeats, JSON_UNESCAPED_UNICODE) : null,
            'sensory_details' => $sensoryDetails ? json_encode($sensoryDetails, JSON_UNESCAPED_UNICODE) : null,
            'pacing' => $pacing,
            'cliffhanger' => $cliffhanger,
            'foreshadowing' => $foreshadowing ? json_encode($foreshadowing, JSON_UNESCAPED_UNICODE) : null,
            'callbacks' => $callbacks ? json_encode($callbacks, JSON_UNESCAPED_UNICODE) : null
        ]);

        // 更新chapters表的synopsis_id
        DB::update('chapters', ['synopsis_id' => $synopsisId], 'novel_id = ? AND chapter_number = ?', [$novelId, $chapterNumber]);
    }

    echo json_encode(['success' => true, 'message' => '章节简介已保存']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
