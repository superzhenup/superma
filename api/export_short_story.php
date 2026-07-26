<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
define('APP_LOADED', true);

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ShortStoryService.php';

requireLoginApi();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '缺少id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$service = new ShortStoryService();
$story = $service->getStory($id);
if (!$story) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '短篇小说不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 审计 P0（2026-06-12）：归属校验
$sessionUserId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($sessionUserId > 0 && ($story['user_id'] ?? 0) > 0 && (int)$story['user_id'] !== $sessionUserId) {
    http_response_code(403);
    die('无权访问此短篇');
}

$title = $story['title'] ?: '未命名短篇';
$genre = $story['genre'] ?: '未分类';
$theme = $story['theme'] ?: '';
$targetWords = $story['target_words'] ?? 0;
$content = $story['content'] ?? '';

$lines = [];
$lines[] = $title;
$lines[] = '';
$lines[] = '类型：' . $genre;
if ($theme) $lines[] = '主题：' . $theme;
$lines[] = '目标字数：' . $targetWords;
$lines[] = '';
$lines[] = $content;

$txt = implode("\n", $lines);

$filename = preg_replace('/[^\w\x{4e00}-\x{9fff}]+/u', '_', $title);
$filename = trim($filename, '_') ?: 'short_story';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
header('Content-Length: ' . mb_strlen($txt, '8bit'));
echo $txt;
exit;
