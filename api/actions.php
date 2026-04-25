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
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

ob_end_clean();   // 清掉 require 阶段产生的任何输出
header('Content-Type: application/json; charset=utf-8');

/**
 * 将章节当前内容备份到 chapter_versions 表
 * 仅在原文 >100 字时才备份（避免空白/短小内容产生无意义版本）
 * @param array $ch 章节数组（需含 id/content/words/outline/title）
 */
function backupChapterVersion(array $ch): void {
    $oldContent = $ch['content'] ?? '';
    $oldWords   = (int)($ch['words'] ?? 0);
    if (empty($oldContent) || $oldWords <= 100) return;

    $chapterId = (int)($ch['id'] ?? 0);
    if (!$chapterId) return;

    $maxVer = (int)(DB::fetch(
        'SELECT COALESCE(MAX(version), 0) AS v FROM chapter_versions WHERE chapter_id=?',
        [$chapterId]
    )['v'] ?? 0);
    DB::insert('chapter_versions', [
        'chapter_id' => $chapterId,
        'version'    => $maxVer + 1,
        'content'    => $oldContent,
        'outline'    => $ch['outline'] ?? '',
        'title'      => $ch['title']   ?? '',
        'words'      => $oldWords,
    ]);
    // 保留最近版本（CFG_VERSIONS_KEEP 在 config_constants.php 中定义，默认 10）
    $keep = defined('CFG_VERSIONS_KEEP') ? CFG_VERSIONS_KEEP : 10;
    DB::execute(
        'DELETE FROM chapter_versions WHERE chapter_id=? AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM chapter_versions WHERE chapter_id=? ORDER BY version DESC LIMIT ' . (int)$keep . '
            ) t
        )',
        [$chapterId, $chapterId]
    );
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

try {
    switch ($action) {

        // -----------------------------------------------------------
        case 'get_chapter_detail':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            jsonResponse(true, ['chapter' => $ch]);
            break;

        // -----------------------------------------------------------
        case 'save_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $title     = trim($input['title']   ?? '');
            $content   = trim($input['content'] ?? '');
            $ch        = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');

            // 版本备份：保存前将当前内容存入版本历史
            backupChapterVersion($ch);

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
        // 保存章节大纲、关键情节点、结尾钩子
        case 'save_chapter_outline':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $outline   = trim($input['outline'] ?? '');
            $hook      = trim($input['hook']    ?? '');
            $keyPoints = $input['key_points']   ?? [];
            $ch        = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');

            // key_points 规范化为字符串数组，过滤空项
            if (!is_array($keyPoints)) $keyPoints = [];
            $keyPoints = array_values(array_filter(
                array_map(fn($p) => trim((string)$p), $keyPoints),
                fn($p) => $p !== ''
            ));

            DB::update('chapters', [
                'outline'    => $outline,
                'hook'       => $hook,
                'key_points' => $keyPoints ? json_encode($keyPoints, JSON_UNESCAPED_UNICODE) : null,
            ], 'id=?', [$chapterId]);

            jsonResponse(true, ['count' => count($keyPoints)], '大纲已保存');
            break;

        // -----------------------------------------------------------
        case 'delete_novel':
            $novelId = (int)($input['novel_id'] ?? 0);
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');

            $pdo = DB::getPdo();
            $pdo->beginTransaction();
            try {
                // 1. 先删子表：通过 card_id 关联 character_card_history
                $cardIds = DB::fetchAll('SELECT id FROM character_cards WHERE novel_id=?', [$novelId]);
                if ($cardIds) {
                    $ids = array_column($cardIds, 'id');
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    DB::execute("DELETE FROM character_card_history WHERE card_id IN ($ph)", $ids);
                }

                // 2. 先删子表：通过 chapter_id 关联 chapter_versions
                $chapterIds = DB::fetchAll('SELECT id FROM chapters WHERE novel_id=?', [$novelId]);
                if ($chapterIds) {
                    $ids = array_column($chapterIds, 'id');
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    DB::execute("DELETE FROM chapter_versions WHERE chapter_id IN ($ph)", $ids);
                }

                // 3. 批量删除所有含 novel_id 的关联表
                $novelTables = [
                    'chapters',
                    'writing_logs',
                    'story_outlines',
                    'volume_outlines',
                    'chapter_synopses',
                    'arc_summaries',
                    'novel_characters',
                    'novel_worldbuilding',
                    'novel_plots',
                    'novel_style',
                    'novel_embeddings',
                    'character_cards',
                    'foreshadowing_items',
                    'novel_state',
                    'memory_atoms',
                    'consistency_logs',
                ];
                foreach ($novelTables as $table) {
                    DB::delete($table, 'novel_id=?', [$novelId]);
                }

                // 4. 最后删小说主表
                DB::delete('novels', 'id=?', [$novelId]);

                $pdo->commit();
                jsonResponse(true, null, '删除成功');
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
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
        case 'get_chapter_status':
            // 查询单个章节的状态（用于前端超时检测时确认后端是否已落盘）
            $chapterId = (int)($input['chapter_id'] ?? 0);
            if (!$chapterId) throw new RuntimeException('缺少章节ID');
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            jsonResponse(true, [
                'status'      => $ch['status'],
                'retry_count' => (int)($ch['retry_count'] ?? 0),
                'words'       => (int)($ch['words'] ?? 0),
            ]);
            break;

        // -----------------------------------------------------------
        case 'get_novel_status':
            $novelId = (int)($input['novel_id'] ?? 0);
            $mode    = $input['mode'] ?? 'normal'; // normal=只查outlined, catchup=只查skipped
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');

            // 根据模式选择待查状态（白名单校验，防注入）
            $allowedModes = ['normal' => 'outlined', 'catchup' => 'skipped'];
            $statusValue  = $allowedModes[$mode] ?? 'outlined';

            $nextChapter = DB::fetch(
                "SELECT id, chapter_number, title, status FROM chapters
                 WHERE novel_id=? AND status=? ORDER BY chapter_number ASC LIMIT 1",
                [$novelId, $statusValue]
            );
            $completedCount = DB::count('chapters', 'novel_id=? AND status="completed"', [$novelId]);
            $outlinedCount  = DB::count('chapters', 'novel_id=? AND status IN ("outlined","writing","completed","skipped")', [$novelId]);
            $skippedCount   = DB::count('chapters', 'novel_id=? AND status="skipped"', [$novelId]);
            $failedCount    = DB::count('chapters', 'novel_id=? AND status="failed"', [$novelId]);
            jsonResponse(true, [
                'status'          => $novel['status'],
                'current_chapter' => $novel['current_chapter'],
                'total_words'     => $novel['total_words'],
                'completed_count' => $completedCount,
                'outlined_count'  => $outlinedCount,
                'skipped_count'   => $skippedCount,
                'failed_count'    => $failedCount,
                'next_chapter'    => $nextChapter,
                'all_done'        => !$nextChapter,
            ]);
            break;

        case 'reset_writing_chapter':
            // SSE 连接中断时重置章节状态：writing → outlined
            // 同时清理僵死的进度文件，确保异步 worker 能找到待写章节
            $rNovelId   = (int)($input['novel_id'] ?? 0);
            $rChapterId = (int)($input['chapter_id'] ?? 0);
            if (!$rNovelId) throw new RuntimeException('缺少小说ID');

            // 清理该小说的僵死进度文件
            $progressDir = CFG_PROGRESS_DIR;
            $cleanedFiles = 0;
            if (is_dir($progressDir)) {
                foreach (glob($progressDir . '/w*.json') as $pf) {
                    $fp = fopen($pf, 'r');
                    if (!$fp) continue;
                    flock($fp, LOCK_SH);
                    $pdata = stream_get_contents($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    $p = json_decode($pdata, true);
                    if (($p['novel_id'] ?? 0) === $rNovelId) {
                        @unlink($pf);
                        $cleanedFiles++;
                    }
                }
            }

            if ($rChapterId > 0) {
                // 指定章节：重置该章节
                $ch = DB::fetch('SELECT id, chapter_number, status FROM chapters WHERE id=? AND novel_id=?', [$rChapterId, $rNovelId]);
                if ($ch && $ch['status'] === 'writing') {
                    DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
                    addLog($rNovelId, 'info', "第{$ch['chapter_number']}章 SSE 断连，状态重置为 outlined");
                }
            } else {
                // 未指定章节：重置该小说下所有 writing 状态的章节
                $writing = DB::fetchAll('SELECT id, chapter_number FROM chapters WHERE novel_id=? AND status=?', [$rNovelId, 'writing']);
                foreach ($writing as $ch) {
                    DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
                    addLog($rNovelId, 'info', "第{$ch['chapter_number']}章 SSE 断连，状态重置为 outlined");
                }
            }
            // 同时重置小说状态
            DB::update('novels', ['status' => 'draft'], 'id=? AND status=?', [$rNovelId, 'writing']);
            jsonResponse(true, ['reset' => true, 'cleaned_progress_files' => $cleanedFiles]);
            break;

        case 'mark_skipped':
            // 标记章节为 skipped（写作失败，暂时跳过等待补写）
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $retryLimit = (int)($input['retry_limit'] ?? 2);
            if (!$chapterId) throw new RuntimeException('缺少章节ID');
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');

            // 关键防护：如果章节已经是 completed 状态，说明后端实际已成功落盘，
            // 前端因超时/SSE断连误判为失败，此时绝不能把 completed 改回 outlined/skipped
            if ($ch['status'] === 'completed') {
                addLog($ch['novel_id'], 'info', "第{$ch['chapter_number']}章已是完成状态，跳过 mark_skipped（前端可能因超时误判）");
                jsonResponse(true, ['status' => 'completed', 'retry_count' => (int)($ch['retry_count'] ?? 0), 'note' => '章节已完成，无需标记跳过']);
                break;
            }

            // writing / outlined / skipped 状态的章节都允许标记（补写失败时状态为 skipped）
            if (!in_array($ch['status'], ['writing', 'outlined', 'skipped'])) {
                jsonResponse(true, ['status' => $ch['status'], 'retry_count' => (int)($ch['retry_count'] ?? 0), 'note' => '章节状态不允许标记跳过']);
                break;
            }

            $retryCount = (int)($ch['retry_count'] ?? 0) + 1;
            if ($retryCount >= $retryLimit) {
                // 超过重试上限 → skipped（但不清零 retry_count，保留历史记录）
                DB::update('chapters', [
                    'status'      => 'skipped',
                    'retry_count' => $retryCount,
                ], 'id=? AND status IN ("writing","outlined","skipped")', [$chapterId]);
                addLog($ch['novel_id'], 'skip', "第{$ch['chapter_number']}章写作失败，标记跳过（已重试{$retryCount}次）");
                jsonResponse(true, ['status' => 'skipped', 'retry_count' => $retryCount]);
            } else {
                // 还可以重试 → outlined（保持原状让循环自动重试）
                DB::update('chapters', [
                    'status'      => 'outlined',
                    'retry_count' => $retryCount,
                ], 'id=? AND status IN ("writing","outlined","skipped")', [$chapterId]);
                jsonResponse(true, ['status' => 'outlined', 'retry_count' => $retryCount]);
            }
            break;

        case 'mark_failed':
            // 标记章节为 failed（补写也失败，需要用户手动处理）
            $chapterId = (int)($input['chapter_id'] ?? 0);
            if (!$chapterId) throw new RuntimeException('缺少章节ID');
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');

            // 防护：不覆盖已完成状态
            if ($ch['status'] === 'completed') {
                addLog($ch['novel_id'], 'info', "第{$ch['chapter_number']}章已是完成状态，跳过 mark_failed");
                jsonResponse(true, ['status' => 'completed', 'note' => '章节已完成']);
                break;
            }

            DB::update('chapters', [
                'status' => 'failed',
            ], 'id=? AND status != "completed"', [$chapterId]);
            addLog($ch['novel_id'], 'fail', "第{$ch['chapter_number']}章补写失败，标记为失败");
            jsonResponse(true, null, '已标记为失败');
            break;

        // -----------------------------------------------------------
        case 'reset_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');

            // 版本备份：重置前将当前内容存入版本历史
            backupChapterVersion($ch);

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

            // 版本备份：删除正文前将当前内容存入版本历史
            backupChapterVersion($ch);

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
        // 一键润色：对已有章节内容进行 AI 润色
        case 'polish_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            if (empty($ch['content'])) throw new RuntimeException('章节内容为空，无法润色');
            // 流式润色由 api/polish_chapter.php 处理，此处仅做前置校验
            jsonResponse(true, ['chapter_id' => $chapterId], '校验通过');
            break;

        // -----------------------------------------------------------
        // 重新生成章节：结合大纲概要、关键情节点、结尾钩子重新生成
        case 'regenerate_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');

            $novel = getNovel($ch['novel_id']);
            if (!$novel) throw new RuntimeException('小说不存在');

            // 读取当前大纲概要、关键情节点、结尾钩子
            $outline   = trim($input['outline']   ?? $ch['outline'] ?? '');
            $hook      = trim($input['hook']      ?? $ch['hook']    ?? '');
            $keyPoints = $input['key_points']     ?? (json_decode($ch['key_points'] ?? '[]', true) ?? []);

            if (empty($outline) && empty($keyPoints)) {
                throw new RuntimeException('请先填写大纲概要或关键情节点');
            }

            // 先保存大纲（用户可能在重新生成前修改了大纲）
            if (!is_array($keyPoints)) $keyPoints = [];
            $keyPoints = array_values(array_filter(
                array_map(fn($p) => trim((string)$p), $keyPoints),
                fn($p) => $p !== ''
            ));
            DB::update('chapters', [
                'outline'    => $outline,
                'hook'       => $hook,
                'key_points' => $keyPoints ? json_encode($keyPoints, JSON_UNESCAPED_UNICODE) : null,
            ], 'id=?', [$chapterId]);

            // 返回标记，告知前端应该调用 write_chapter.php 进行流式生成
            jsonResponse(true, [
                'chapter_id'   => $chapterId,
                'novel_id'     => $ch['novel_id'],
                'should_write' => true,
            ], '大纲已保存，准备重新生成');
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
