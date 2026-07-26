<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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

// 审计优化 P1-2（2026-06-16）：requireLoginApi 移出 try 块，
// 与项目其他 API 惯例一致；该函数内部会 exit，放 try 内会干扰错误捕获语义。
requireLoginApi();

try {
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
    $userId = (int)($_SESSION['user_id'] ?? 0);
    checkNovelOwnership($novelId, $userId);

    // 检查是否已存在
    $exists = DB::fetch('SELECT id FROM chapter_synopses WHERE novel_id = ? AND chapter_number = ?', [$novelId, $chapterNumber]);

    if ($exists) {
        // 只更新调用方明确提交的可选字段。普通文本编辑不应顺带清空
        // scene_breakdown / dialogue_beats 等已生成的详细概要数据。
        $updateData = ['synopsis' => $synopsis];
        foreach ([
            'scene_breakdown' => $sceneBreakdown,
            'dialogue_beats' => $dialogueBeats,
            'sensory_details' => $sensoryDetails,
            'foreshadowing' => $foreshadowing,
            'callbacks' => $callbacks,
        ] as $field => $value) {
            if (array_key_exists($field, $input)) {
                $updateData[$field] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
            }
        }
        if (array_key_exists('pacing', $input)) $updateData['pacing'] = $pacing;
        if (array_key_exists('cliffhanger', $input)) $updateData['cliffhanger'] = $cliffhanger;

        DB::update('chapter_synopses', $updateData, 'novel_id = ? AND chapter_number = ?', [$novelId, $chapterNumber]);
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
    $rid = error_trace_id();
    error_log(sprintf('[%s] update_chapter_synopsis: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(['success' => false, 'message' => '保存失败，请稍后重试', 'request_id' => $rid]);
}
