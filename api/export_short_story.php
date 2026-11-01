<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
defined('APP_LOADED') or define('APP_LOADED', true);

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
$sessionUserId = currentUserId();
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

// 审计 T3（2026-10-31）：下载文件名统一经 contentDispositionAttachment()
// （RFC 6266/5987 双文件名），文件名内容保持原意（短篇标题 + .txt）。
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: ' . contentDispositionAttachment($title . '.txt'));
header('Content-Length: ' . mb_strlen($txt, '8bit'));
echo $txt;
exit;
