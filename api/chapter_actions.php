<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

requireLoginApi();
session_write_close();

$input = read_chapter_action_input();
$action = (string)($input['action'] ?? '');
$chapterId = (int)($input['chapter_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($chapterId > 0) {
    checkChapterOwnership($chapterId, $userId);
}

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

            ChapterMutationService::mutateChapter((int)$chapter['id'], (int)$chapter['novel_id'], [
                'outline' => $outline,
            ], [
                'backup_version' => false,
                'prevent_writing' => true,
                'force_outline_invalidation' => true,
                'reason' => 'chapter_modal_outline_edit',
            ]);
            addLog((int)$chapter['novel_id'], 'chapter', "第{$chapter['chapter_number']}章大纲已更新", (int)$chapter['id']);
            jsonResponse(true, ['outline' => $outline], '大纲已更新');
            break;

        case 'clear_content':
            $chapter = require_chapter((int)($input['chapter_id'] ?? 0));

            // 检查章节状态，防止清空正在写作的章节
            if (($chapter['status'] ?? '') === 'writing') {
                throw new RuntimeException('章节正在写作中，无法清空');
            }

            ChapterMutationService::mutateChapter((int)$chapter['id'], (int)$chapter['novel_id'], [
                'content' => '',
                'words' => 0,
                'status' => 'outlined',
            ], [
                'backup_version' => true,
                'prevent_writing' => true,
                'force_content_invalidation' => true,
                'reason' => 'clear_content',
            ]);
            updateNovelStats((int)$chapter['novel_id']);
            addLog((int)$chapter['novel_id'], 'chapter', "第{$chapter['chapter_number']}章内容已清空", (int)$chapter['id']);
            jsonResponse(true, null, '章节内容已清空');
            break;

        case 'regenerate':
            // 审计修复（2026-07-19 M6-9）：此前固定抛异常，详情弹窗"重新生成"不可用。
            // 现实现为：清空正文并退回 outlined → 内部转发到 write_chapter.php，
            // 复用其并发守卫、模型 fallback 与 SSE 流式写作链路。
            $chapter = require_chapter($chapterId);
            if (($chapter['status'] ?? '') === 'writing') {
                throw new RuntimeException('章节正在写作中，请稍后重试');
            }
            if (trim((string)($chapter['outline'] ?? '')) === '') {
                throw new RuntimeException('请先为本章填写大纲，再重新生成正文');
            }

            ChapterMutationService::mutateChapter((int)$chapter['id'], (int)$chapter['novel_id'], [
                'content' => '',
                'words'   => 0,
                'status'  => 'outlined',
            ], [
                'backup_version' => true,
                'prevent_writing' => true,
                'force_content_invalidation' => true,
                'reason' => 'regenerate',
            ]);

            $GLOBALS['write_chapter_input'] = [
                'novel_id'   => (int)$chapter['novel_id'],
                'chapter_id' => (int)$chapter['id'],
            ];
            require __DIR__ . '/write_chapter.php';
            exit;

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
        $rid = error_trace_id();
        error_log(sprintf('[%s] chapter_actions: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
        // 审计修复（2026-07-19 H-中15）：原实现 catch-all 把业务校验文案
        // （如"标题不能为空"）统一替换为"操作失败"。改为透传业务异常消息。
        $isBusinessError = $e instanceof RuntimeException
            && strpos($e->getMessage(), '请') !== false
            && stripos($e->getMessage(), 'SQL') === false;
        $userMsg = $isBusinessError ? $e->getMessage() : '操作失败，请稍后重试';
        echo json_encode([
            'ok'         => false,
            'data'       => null,
            'msg'        => $userMsg,
            'error'      => $userMsg,
            'code'       => $isBusinessError ? 'business_error' : 'internal_error',
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

// 正文清空/替换统一由 ChapterMutationService 负责版本备份与派生数据失效。
