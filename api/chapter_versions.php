<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 章节版本历史 API
 * 获取章节的版本列表，支持回滚
 * GET: { chapter_id }
 */
defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$chapterId = (int)($input['chapter_id'] ?? $_GET['chapter_id'] ?? 0);
$action = $input['action'] ?? $_GET['action'] ?? 'list';

if (!$chapterId) {
    jsonResponse(false, null, '缺少章节ID');
}

$userId = currentUserId();
checkChapterOwnership($chapterId, $userId);

// 获取章节信息
$chapter = DB::fetch('SELECT id, novel_id, chapter_number, title, content, words, outline, status FROM chapters WHERE id=?', [$chapterId]);
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
    $version = DB::fetch('SELECT id, version, content, words, title, outline, created_at FROM chapter_versions WHERE id=? AND chapter_id=?', [$versionId, $chapterId]);

    if (!$version) {
        jsonResponse(false, null, '版本不存在');
    }

    jsonResponse(true, [
        'version' => $version['version'],
        'content' => $version['content'],
        'words' => $version['words'],
        'title' => $version['title'],
        'outline' => $version['outline'],
        'created_at' => $version['created_at']
    ]);
} elseif ($action === 'rollback') {
    requireHttpMethod('POST');
    // 回滚到指定版本
    $versionId = (int)($input['version_id'] ?? 0);
    $version = DB::fetch('SELECT id, version, content, words, title, outline FROM chapter_versions WHERE id=? AND chapter_id=?', [$versionId, $chapterId]);

    if (!$version) {
        jsonResponse(false, null, '版本不存在');
    }

    $updates = [
        'content' => $version['content'],
        'words'   => $version['words'],
        'status'  => 'completed',
    ];
    // 旧版本记录可能没有标题/大纲；仅在版本确有值时恢复，避免用 NULL
    // 覆盖用户当前策划。恢复大纲会同时使旧 synopsis 失效。
    if ($version['title'] !== null) {
        $updates['title'] = $version['title'];
    }
    if ($version['outline'] !== null) {
        $updates['outline'] = $version['outline'];
    }

    try {
        ChapterMutationService::mutateChapter(
            $chapterId,
            (int)$chapter['novel_id'],
            $updates,
            [
                'backup_version' => true,
                'prevent_writing' => true,
                'force_content_invalidation' => true,
                'reason' => 'version_rollback',
            ]
        );
    } catch (ChapterMutationConflict $e) {
        jsonResponse(false, null, '章节正在写作中，无法回滚', 'CHAPTER_WRITING');
    }

    // 记录日志
    updateNovelStats((int)$chapter['novel_id']);
    addLog((int)$chapter['novel_id'], 'rollback', "章节{$chapter['chapter_number']}回滚到v{$version['version']}", $chapterId);

    // 审计修复 P1-7（2026-08-08）：回滚后自动重建投影。
    // mutateChapter 已失效旧投影（记忆原子/伏笔/嵌入等），若不重建，
    // 该章摘要/记忆永久缺失（原 projection_runs 幂等记录还会阻断重建——
    // 现已在失效时同步删除）。与三条写作路径一致走 enqueueAndRun：
    // 同步执行失败也会落入持久重试队列。
    try {
        require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';
        $restored = DB::fetch(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" LIMIT 1',
            [$chapterId, (int)$chapter['novel_id']]
        );
        if ($restored && (string)($restored['content'] ?? '') !== '') {
            $ppResult = PostprocessRunner::enqueueAndRun(
                (int)$chapter['novel_id'],
                $restored,
                (string)$restored['content'],
                null,
                'version-rollback:' . (gethostname() ?: 'localhost') . ':' . getmypid()
            );
            if (!in_array((string)($ppResult['state'] ?? ''), ['done', 'stale'], true)) {
                addLog((int)$chapter['novel_id'], 'warn', '回滚后后处理已进入持久重试队列，任务ID：' . (int)($ppResult['job_id'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        // 回滚本身已成功；投影重建失败仅记录，可由 bin/postprocess_worker.php 或手动提取兜底
        error_log('chapter_versions rollback postprocess failed: ' . $e->getMessage());
        addLog((int)$chapter['novel_id'], 'warn', '回滚成功，但记忆重建失败：可稍后通过记忆管理手动提取');
    }

    jsonResponse(true, null, "已回滚到版本 {$version['version']}，原内容已按版本策略备份");
}
