<?php
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
if ($id <= 0) die('缺少id');

$service = new ShortStoryService();
$story = $service->getStory($id);
if (!$story) die('短篇小说不存在');

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
