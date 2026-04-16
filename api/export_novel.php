<?php
/**
 * 小说整本导出 API
 * GET: ?id=小说ID
 * 输出 TXT 文件下载
 */

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLogin();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$novelId = (int)($_GET['id'] ?? 0);
$novel   = getNovel($novelId);

if (!$novel) {
    http_response_code(404);
    die('小说不存在');
}

$chapters = DB::fetchAll(
    'SELECT * FROM chapters WHERE novel_id=? AND status="completed" ORDER BY chapter_number ASC',
    [$novelId]
);

if (empty($chapters)) {
    http_response_code(400);
    die('暂无已完成的章节可供导出');
}

// 构建 TXT 内容
$lines = [];

// 书名
$lines[] = $novel['title'];
$lines[] = str_repeat('＝', mb_strlen($novel['title'], 'UTF-8') * 2);
$lines[] = '';

// 小说信息
$info = [];
if ($novel['genre'])         $info[] = "类型：{$novel['genre']}";
if ($novel['writing_style']) $info[] = "风格：{$novel['writing_style']}";
if ($novel['protagonist_name']) $info[] = "主角：{$novel['protagonist_name']}";
$info[] = "总字数：" . number_format($novel['total_words']);
$info[] = "章节数：" . count($chapters);
$lines[] = implode('　|　', $info);
$lines[] = '';

// 设定概要（如果有）
if ($novel['protagonist_info'] || $novel['world_settings'] || $novel['plot_settings']) {
    $lines[] = '【作品设定】';
    $lines[] = str_repeat('─', 40);
    if ($novel['protagonist_info']) {
        $lines[] = "主角：{$novel['protagonist_info']}";
    }
    if ($novel['world_settings']) {
        $lines[] = "世界观：{$novel['world_settings']}";
    }
    if ($novel['plot_settings']) {
        $lines[] = "情节：{$novel['plot_settings']}";
    }
    if ($novel['extra_settings']) {
        $lines[] = "补充：{$novel['extra_settings']}";
    }
    $lines[] = '';
    $lines[] = str_repeat('═', 60);
    $lines[] = '';
}

// 章节目录
$lines[] = '【目录】';
$lines[] = str_repeat('─', 40);
foreach ($chapters as $ch) {
    $lines[] = "第{$ch['chapter_number']}章　{$ch['title']}";
}
$lines[] = '';
$lines[] = str_repeat('═', 60);
$lines[] = '';

// 正文
foreach ($chapters as $ch) {
    $lines[] = "第{$ch['chapter_number']}章　{$ch['title']}";
    $lines[] = str_repeat('─', 40);
    $lines[] = '';
    $lines[] = $ch['content'] ?: '（本章内容为空）';
    $lines[] = '';
    $lines[] = '';
}

$content = implode("\r\n", $lines);

// 生成安全的文件名
$safeTitle = preg_replace('/[\/\\\:\*\?\"\<\>\|]/', '', $novel['title']);
$filename  = $safeTitle . '.txt';

// BOM 前缀确保 Windows 记事本正确识别 UTF-8
$bom = "\xEF\xBB\xBF";
$output = $bom . $content;

// 输出下载
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . urlencode($filename) . '"');
header('Content-Length: ' . strlen($output));
header('Cache-Control: no-cache, must-revalidate');

echo $output;

addLog($novelId, 'export', "导出小说《{$novel['title']}》共" . count($chapters) . '章');
