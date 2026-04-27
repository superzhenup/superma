<?php
/**
 * 章节版本历史 API
 * 获取章节的版本列表，支持回滚
 * GET: { chapter_id }
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

$chapterId = (int)($_GET['chapter_id'] ?? 0);
$action = $_GET['action'] ?? 'list';

if (!$chapterId) {
    jsonResponse(false, null, '缺少章节ID');
}

// 获取章节信息
$chapter = DB::fetch('SELECT id, chapter_number, title, content, words, outline FROM chapters WHERE id=?', [$chapterId]);
if (!$chapter) {
    jsonResponse(false, null, '章节不存在');
}

// 获取版本历史
$versions = DB::fetchAll(
    'SELECT id, version, words, created_at FROM chapter_versions WHERE chapter_id=? ORDER BY version DESC',
    [$chapterId]
);

if ($action === 'list') {
    // 返回版本列表
    jsonResponse(true, [
        'chapter' => [
            'id' => $chapter['id'],
            'chapter_number' => $chapter['chapter_number'],
            'title' => $chapter['title'],
            'current_words' => $chapter['words'],
            'current_content' => safe_substr($chapter['content'] ?? '', 0, 500) . (safe_strlen($chapter['content'] ?? '') > 500 ? '...' : '')
        ],
        'versions' => array_map(function($v) {
            return [
                'id' => $v['id'],
                'version' => $v['version'],
                'words' => $v['words'],
                'created_at' => $v['created_at']
            ];
        }, $versions)
    ]);
} elseif ($action === 'preview') {
    // 预览指定版本内容
    $versionId = (int)($_GET['version_id'] ?? 0);
    $version = DB::fetch('SELECT * FROM chapter_versions WHERE id=? AND chapter_id=?', [$versionId, $chapterId]);

    if (!$version) {
        jsonResponse(false, null, '版本不存在');
    }

    jsonResponse(true, [
        'version' => $version['version'],
        'content' => $version['content'],
        'words' => $version['words'],
        'created_at' => $version['created_at']
    ]);
} elseif ($action === 'rollback') {
    // 回滚到指定版本
    $versionId = (int)($_GET['version_id'] ?? 0);
    $version = DB::fetch('SELECT * FROM chapter_versions WHERE id=? AND chapter_id=?', [$versionId, $chapterId]);

    if (!$version) {
        jsonResponse(false, null, '版本不存在');
    }

    // 先为当前内容创建新版本备份
    $currentVersion = DB::fetch(
        'SELECT MAX(version) as max_ver FROM chapter_versions WHERE chapter_id=?',
        [$chapterId]
    );
    $newVer = (int)($currentVersion['max_ver'] ?? 0) + 1;

    if (!empty($chapter['content'])) {
        DB::insert('chapter_versions', [
            'chapter_id' => $chapterId,
            'version' => $newVer,
            'content' => $chapter['content'],
            'outline' => $chapter['outline'] ?? '',
            'title' => $chapter['title'] ?? '',
            'words' => $chapter['words'] ?? 0,
        ]);
    }

    // 执行回滚
    DB::update('chapters', [
        'content' => $version['content'],
        'words' => $version['words'],
        'status' => 'completed'
    ], 'id=?', [$chapterId]);

    // 记录日志
    $novelId = DB::fetch('SELECT novel_id FROM chapters WHERE id=?', [$chapterId]);
    if ($novelId) {
        addLog($novelId['novel_id'], 'rollback', "章节{$chapter['chapter_number']}回滚到v{$version['version']}", $chapterId);
    }

    jsonResponse(true, null, "已回滚到版本 {$version['version']}，原内容已备份为v{$newVer}");
}