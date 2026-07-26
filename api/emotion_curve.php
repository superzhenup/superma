<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

header('Content-Type: application/json; charset=utf-8');

$novelId = (int)($_GET['novel_id'] ?? $_POST['novel_id'] ?? 0);

// 审计 P0（2026-06-12）：归属校验
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($novelId > 0) checkNovelOwnership($novelId, $userId);

if ($novelId <= 0) {
    echo json_encode(['success' => false, 'error' => 'missing novel_id']);
    exit;
}

try {
    $rows = DB::fetchAll(
        'SELECT chapter_number, emotion_score, quality_score, words, title
         FROM chapters
         WHERE novel_id=? AND status="completed"
         ORDER BY chapter_number ASC',
        [$novelId]
    );

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'chapter' => (int)$row['chapter_number'],
            'emotion' => $row['emotion_score'] !== null ? round((float)$row['emotion_score'], 1) : null,
            'quality' => $row['quality_score'] !== null ? round((float)$row['quality_score'], 1) : null,
            'words'   => (int)($row['words'] ?? 0),
            'title'   => $row['title'] ?? '',
        ];
    }

    $anomaly = null;
    if (count($data) >= 10) {
        $anomaly = detectEmotionCurveAnomaly($novelId);
    }

    echo json_encode([
        'success'  => true,
        'data'     => $data,
        'anomaly'  => $anomaly,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('emotion_curve: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(safe_api_error_payload($e, '情绪曲线计算失败，请稍后重试'));
}
