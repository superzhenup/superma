<?php
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

$novelId   = (int)($_POST['novel_id'] ?? $_GET['novel_id'] ?? 0);
$chapterId = (int)($_POST['chapter_id'] ?? $_GET['chapter_id'] ?? 0);
$action    = $_POST['action'] ?? $_GET['action'] ?? 'save';

if ($novelId <= 0 || $chapterId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'missing parameters']);
    exit;
}

if ($action === 'save') {
    $scores = $_POST['scores'] ?? [];
    // 支持两种格式：JSON字符串或PHP数组
    if (is_string($scores)) {
        $scores = json_decode($scores, true) ?? [];
    }
    if (!is_array($scores)) {
        echo json_encode(['ok' => false, 'msg' => 'invalid scores']);
        exit;
    }

    $dims = ['thrill', 'immersion', 'pacing', 'freshness', 'read_next'];
    $clean = [];
    foreach ($dims as $d) {
        if (isset($scores[$d])) {
            $clean[$d] = max(1, min(10, (int)$scores[$d]));
        }
    }
    if (empty($clean)) {
        echo json_encode(['ok' => false, 'msg' => 'no valid scores']);
        exit;
    }

    DB::update('chapters', [
        'human_critic_scores' => json_encode($clean, JSON_UNESCAPED_UNICODE),
    ], 'id=? AND novel_id=?', [$chapterId, $novelId]);

    addLog($novelId, 'info', sprintf(
        '人工评分已保存：第%d章（%d项）',
        (int)(DB::fetch('SELECT chapter_number FROM chapters WHERE id=?', [$chapterId])['chapter_number'] ?? 0),
        count($clean)
    ));

    echo json_encode(['ok' => true]);
} elseif ($action === 'get') {
    $row = DB::fetch(
        'SELECT human_critic_scores, critic_scores, calibrated_critic_scores FROM chapters WHERE id=? AND novel_id=?',
        [$chapterId, $novelId]
    );
    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'chapter not found']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'human'      => json_decode($row['human_critic_scores'] ?? 'null', true),
        'system'     => json_decode($row['critic_scores'] ?? 'null', true),
        'calibrated' => json_decode($row['calibrated_critic_scores'] ?? 'null', true),
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => false, 'msg' => 'unknown action']);
}
