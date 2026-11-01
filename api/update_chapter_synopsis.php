<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 更新章节简介
 */
defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ChapterSynopsisPersistence.php';

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

    // 普通文本编辑保留未提交的结构化字段。与生成/大纲发布共用章节锁，
    // 概要更新与父章节关联提交在同一事务，避免首次插入绕开来源比较。
    $updateData = ['synopsis' => $synopsis];
    foreach ([
        'scene_breakdown' => $sceneBreakdown,
        'dialogue_beats' => $dialogueBeats,
        'sensory_details' => $sensoryDetails,
        'foreshadowing' => $foreshadowing,
        'callbacks' => $callbacks,
    ] as $field => $value) {
        if (array_key_exists($field, $input)) {
            $updateData[$field] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
        }
    }
    if (array_key_exists('pacing', $input)) $updateData['pacing'] = $pacing;
    if (array_key_exists('cliffhanger', $input)) $updateData['cliffhanger'] = $cliffhanger;
    $persistence = new ChapterSynopsisPersistence(DB::connect(), $userId, $novelId);
    if (!empty($input['optimization']) || array_key_exists('source_revision', $input)) {
        $revision = $input['source_revision'] ?? null;
        if (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1
            || !$persistence->saveGenerated($chapterNumber, $updateData, $revision)) {
            echo json_encode(['success'=>false, 'code'=>'source_conflict', 'message'=>'大纲或概要已被修改，未采用旧优化结果，请刷新后重新生成']);
            exit;
        }
    } else {
        $persistence->saveManual($chapterNumber, $updateData);
    }

    echo json_encode(['success' => true, 'message' => '章节简介已保存']);

} catch (Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] update_chapter_synopsis: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(['success' => false, 'message' => '保存失败，请稍后重试', 'request_id' => $rid]);
}
