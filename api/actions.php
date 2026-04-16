<?php
/**
 * 通用 AJAX 操作接口
 * POST JSON: { action, ...params }
 */

// 输出缓冲：拦截所有 PHP 警告/Notice 的 HTML 输出，防止污染 JSON
ob_start();
ini_set('display_errors', '0');   // 不把错误直接输出到响应

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

ob_end_clean();   // 清掉 require 阶段产生的任何输出
header('Content-Type: application/json; charset=utf-8');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

try {
    switch ($action) {

        // -----------------------------------------------------------
        case 'save_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $title     = trim($input['title']   ?? '');
            $content   = trim($input['content'] ?? '');
            $ch        = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            $words = countWords($content);
            DB::update('chapters', [
                'title'   => $title,
                'content' => $content,
                'words'   => $words,
                'status'  => $content ? 'completed' : $ch['status'],
            ], 'id=?', [$chapterId]);
            updateNovelStats($ch['novel_id']);
            jsonResponse(true, ['words' => $words], '保存成功');
            break;

        // -----------------------------------------------------------
        case 'delete_novel':
            $novelId = (int)($input['novel_id'] ?? 0);
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');
            DB::delete('chapters',     'novel_id=?', [$novelId]);
            DB::delete('writing_logs', 'novel_id=?', [$novelId]);
            DB::delete('novels',       'id=?',       [$novelId]);
            jsonResponse(true, null, '删除成功');
            break;

        // -----------------------------------------------------------
        case 'update_novel_model':
            $novelId = (int)($input['novel_id'] ?? 0);
            $modelId = $input['model_id'] ? (int)$input['model_id'] : null;
            DB::update('novels', ['model_id' => $modelId], 'id=?', [$novelId]);
            jsonResponse(true, null, '模型已更新');
            break;

        // -----------------------------------------------------------
        case 'update_novel_status':
            $novelId = (int)($input['novel_id'] ?? 0);
            $status  = $input['status'] ?? '';
            if (!in_array($status, ['draft','writing','paused','completed'])) {
                throw new RuntimeException('无效状态');
            }
            DB::update('novels', ['status' => $status], 'id=?', [$novelId]);
            jsonResponse(true, ['status' => $status]);
            break;

        // -----------------------------------------------------------
        case 'get_novel_status':
            $novelId = (int)($input['novel_id'] ?? 0);
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');
            $nextChapter = DB::fetch(
                'SELECT id, chapter_number, title FROM chapters
                 WHERE novel_id=? AND status="outlined" ORDER BY chapter_number ASC LIMIT 1',
                [$novelId]
            );
            $completedCount = DB::count('chapters', 'novel_id=? AND status="completed"', [$novelId]);
            $outlinedCount  = DB::count('chapters', 'novel_id=? AND status IN ("outlined","writing","completed")', [$novelId]);
            jsonResponse(true, [
                'status'          => $novel['status'],
                'current_chapter' => $novel['current_chapter'],
                'total_words'     => $novel['total_words'],
                'completed_count' => $completedCount,
                'outlined_count'  => $outlinedCount,
                'next_chapter'    => $nextChapter,
                'all_done'        => !$nextChapter,
            ]);
            break;

        // -----------------------------------------------------------
        case 'reset_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            DB::update('chapters', [
                'content' => '',
                'words'   => 0,
                'status'  => 'outlined',
            ], 'id=?', [$chapterId]);
            updateNovelStats($ch['novel_id']);
            jsonResponse(true, null, '章节已重置');
            break;

        // -----------------------------------------------------------
        case 'test_model':
            $modelId = (int)($input['model_id'] ?? 0);
            $model   = DB::fetch('SELECT * FROM ai_models WHERE id=?', [$modelId]);
            if (!$model) throw new RuntimeException('模型不存在');
            set_time_limit(60);
            $testCfg              = $model;
            $testCfg['max_tokens']  = 64;    // 够短但不会被 API 拒绝
            $testCfg['temperature'] = 0.1;
            $ai    = new AIClient($testCfg);
            $reply = $ai->chat([
                ['role' => 'user', 'content' => '请回复"连接成功"四个字。'],
            ]);
            jsonResponse(true, trim((string)$reply));
            break;

        // -----------------------------------------------------------
        case 'delete_chapter_content':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            DB::update('chapters', ['content'=>'','words'=>0,'status'=>'outlined'], 'id=?', [$chapterId]);
            updateNovelStats($ch['novel_id']);
            jsonResponse(true, null, '已清除正文');
            break;

        // -----------------------------------------------------------
        case 'get_outline_progress':
            $novelId = (int)($input['novel_id'] ?? 0);
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');
            $outlinedCount = DB::count('chapters', 'novel_id=? AND status != "pending"', [$novelId]);
            // 查询最大已大纲章节号，用于断线续接
            $lastRow = DB::fetch(
                'SELECT MAX(chapter_number) AS max_ch FROM chapters WHERE novel_id=? AND status != "pending"',
                [$novelId]
            );
            $lastOutlined = (int)($lastRow['max_ch'] ?? 0);
            jsonResponse(true, [
                'outlined'     => $outlinedCount,
                'total'        => (int)$novel['target_chapters'],
                'last_outlined' => $lastOutlined,
            ]);
            break;

        // -----------------------------------------------------------
        default:
            throw new RuntimeException("未知操作：$action");
    }
} catch (RuntimeException $e) {
    jsonResponse(false, null, $e->getMessage());
} catch (Throwable $e) {
    jsonResponse(false, null, '服务器错误：' . $e->getMessage());
}
