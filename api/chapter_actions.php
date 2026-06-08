<?php
/**
 * Chapter detail modal actions.
 *
 * This endpoint is used by the chapter list "查看" modal on novel.php.
 */

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

requireLoginApi();
session_write_close();

$input = read_chapter_action_input();
$action = (string)($input['action'] ?? '');

try {
    switch ($action) {
        case 'get_detail':
            $chapter = require_chapter((int)($input['chapter_id'] ?? 0));
            $synopsis = fetch_chapter_synopsis($chapter);

            jsonResponse(true, [
                'id' => (int)$chapter['id'],
                'novel_id' => (int)$chapter['novel_id'],
                'chapter_number' => (int)$chapter['chapter_number'],
                'title' => (string)($chapter['title'] ?? ''),
                'outline' => (string)($chapter['outline'] ?? ''),
                'synopsis' => $synopsis,
                'content' => (string)($chapter['content'] ?? ''),
                'word_count' => (int)($chapter['words'] ?? 0),
                'status' => (string)($chapter['status'] ?? ''),
            ]);
            break;

        case 'update_title':
            $chapter = require_chapter((int)($input['chapter_id'] ?? 0));
            $title = trim((string)($input['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('标题不能为空');
            }

            updateChapter((int)$chapter['id'], (int)$chapter['novel_id'], [
                'title' => $title,
            ]);
            addLog((int)$chapter['novel_id'], 'chapter', "第{$chapter['chapter_number']}章标题已更新", (int)$chapter['id']);
            jsonResponse(true, ['title' => $title], '标题已更新');
            break;

        case 'update_outline':
            $chapter = require_chapter((int)($input['chapter_id'] ?? 0));
            $outline = trim((string)($input['outline'] ?? ''));

            updateChapter((int)$chapter['id'], (int)$chapter['novel_id'], [
                'outline' => $outline,
            ]);
            addLog((int)$chapter['novel_id'], 'chapter', "第{$chapter['chapter_number']}章大纲已更新", (int)$chapter['id']);
            jsonResponse(true, ['outline' => $outline], '大纲已更新');
            break;

        case 'clear_content':
            $chapter = require_chapter((int)($input['chapter_id'] ?? 0));
            backup_chapter_detail_version($chapter);

            updateChapter((int)$chapter['id'], (int)$chapter['novel_id'], [
                'content' => '',
                'words' => 0,
                'status' => 'outlined',
            ]);
            updateNovelStats((int)$chapter['novel_id']);
            addLog((int)$chapter['novel_id'], 'chapter', "第{$chapter['chapter_number']}章内容已清空", (int)$chapter['id']);
            jsonResponse(true, null, '章节内容已清空');
            break;

        case 'regenerate':
            throw new RuntimeException('请进入章节编辑页使用重新生成功能');

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
        $rid = error_trace_id();
        error_log(sprintf('[%s] chapter_actions: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
        echo json_encode([
            'ok'         => false,
            'data'       => null,
            'msg'        => '操作失败，请稍后重试',
            'error'      => '操作失败，请稍后重试',
            'code'       => 'internal_error',
            'request_id' => $rid,
        ], JSON_UNESCAPED_UNICODE);
    }

function read_chapter_action_input(): array
{
    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : null;
    if (is_array($input)) {
        return $input;
    }
    return $_POST ?: [];
}

function require_chapter(int $chapterId): array
{
    if ($chapterId <= 0) {
        throw new RuntimeException('缺少章节ID');
    }

    $chapter = getChapter($chapterId);
    if (!$chapter) {
        throw new RuntimeException('章节不存在');
    }

    return $chapter;
}

function fetch_chapter_synopsis(array $chapter): string
{
    if (!empty($chapter['synopsis_id'])) {
        $row = DB::fetch(
            'SELECT synopsis FROM chapter_synopses WHERE id=? LIMIT 1',
            [(int)$chapter['synopsis_id']]
        );
        if ($row && trim((string)($row['synopsis'] ?? '')) !== '') {
            return trim((string)$row['synopsis']);
        }
    }

    $row = DB::fetch(
        'SELECT synopsis FROM chapter_synopses WHERE novel_id=? AND chapter_number=? ORDER BY id DESC LIMIT 1',
        [(int)$chapter['novel_id'], (int)$chapter['chapter_number']]
    );

    return trim((string)($row['synopsis'] ?? ''));
}

function backup_chapter_detail_version(array $chapter): void
{
    $content = (string)($chapter['content'] ?? '');
    $words = (int)($chapter['words'] ?? 0);
    if ($content === '' || $words <= 100) {
        return;
    }

    $chapterId = (int)$chapter['id'];
    $maxVersion = (int)(DB::fetch(
        'SELECT COALESCE(MAX(version), 0) AS version FROM chapter_versions WHERE chapter_id=?',
        [$chapterId]
    )['version'] ?? 0);

    DB::insert('chapter_versions', [
        'chapter_id' => $chapterId,
        'version' => $maxVersion + 1,
        'content' => $content,
        'outline' => $chapter['outline'] ?? '',
        'title' => $chapter['title'] ?? '',
        'words' => $words,
    ]);
}
