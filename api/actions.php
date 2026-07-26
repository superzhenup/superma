<?php
/**
 * 通用 AJAX 操作接口
 * POST JSON: { action, ...params }
 */

// 输出缓冲：拦截所有 PHP 警告/Notice 的 HTML 输出，防止污染 JSON
ob_start();
ini_set('display_errors', '0');   // 不把错误直接输出到响应

define('APP_LOADED', true);
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

ob_end_clean();   // 清掉 require 阶段产生的任何输出
header('Content-Type: application/json; charset=utf-8');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// 多用户越权加固：在入口处统一校验小说和章节所有权
// 审计 P0（2026-06-12）：原入口逻辑仅在请求顶层带 novel_id/chapter_id 时校验，
// 但 save_chapter / save_chapter_outline / reset_chapter / delete_chapter 仅传 chapter_id，
// 入口分支会被跳过，case 内只用 getChapter + DB::update 直接放行任意用户的章节。
// 现强制：只要顶层任一字段非零即校验；后续 case 不得再覆盖。
$userId    = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
$novelId   = (int)($input['novel_id'] ?? 0);
$chapterId = (int)($input['chapter_id'] ?? 0);

if ($userId <= 0) {
    jsonResponse(false, null, '会话异常，请重新登录', 'forbidden');
}
if ($novelId > 0) {
    checkNovelOwnership($novelId, $userId);
}
if ($chapterId > 0) {
    checkChapterOwnership($chapterId, $userId);
}

try {
    switch ($action) {

        // -----------------------------------------------------------
        case 'save_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $title     = trim($input['title']   ?? '');
            $content   = trim($input['content'] ?? '');
            $ch        = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            if ($ch['status'] === 'writing') {
                jsonResponse(false, null, '章节正在写作中，无法保存', 'CHAPTER_WRITING');
            }

            $pdo = DB::connect();
            $pdo->beginTransaction();
            try {
                $words = countWords($content);
                ChapterMutationService::mutateChapter($chapterId, (int)$ch['novel_id'], [
                    'title'   => $title,
                    'content' => $content,
                    'words'   => $words,
                    // Emptying a chapter is a real state transition: it must not remain
                    // "completed" with no body, otherwise the daemon and progress
                    // counters will treat a blank chapter as finished.
                    'status'  => $content !== '' ? 'completed' : 'outlined',
                ], [
                    'backup_version' => true,
                    'prevent_writing' => true,
                    'reason' => 'manual_save',
                ]);
                updateNovelStats($ch['novel_id']);
                $pdo->commit();
                jsonResponse(true, ['words' => $words], '保存成功');
            } catch (ChapterMutationConflict $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, null, '章节正在写作中，无法保存', 'CHAPTER_WRITING');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
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

            ChapterMutationService::mutateChapter($chapterId, (int)$ch['novel_id'], [
                'outline'    => $outline,
                'hook'       => $hook,
                'key_points' => $keyPoints ? json_encode($keyPoints, JSON_UNESCAPED_UNICODE) : null,
            ], [
                'backup_version' => false,
                'prevent_writing' => true,
                'force_outline_invalidation' => true,
                'reason' => 'outline_edit',
            ]);

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

                // Import history belongs to the user/session and may be useful after
                // deleting the generated novel, so detach it instead of deleting it.
                DB::update('novel_import_sessions', ['novel_id' => null], 'novel_id=?', [$novelId]);

                // 3. 批量删除所有含 novel_id 的关联表。派生表必须一并清理，
                // 否则重复使用同一小说 ID 的缓存/记忆会读到幽灵数据。
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
                    'foreshadowing_mention_log',
                    'foreshadowing_items',
                    'novel_state',
                    'novel_scene_templates',
                    'memory_atoms',
                    'character_emotion_history',
                    'consistency_logs',
                    'agent_decision_logs',
                    'agent_action_logs',
                    'agent_directives',
                    'agent_directive_outcomes',
                    'constraint_state',
                    'constraint_logs',
                    'pid_states',
                    'iterative_settings',
                    'catchphrase_callback_log',
                    'novel_catchphrases',
                    'novel_wizard_chats',
                    'novel_wizard_progress',
                    'story_relations',
                    'bible_nodes',
                    'novel_bible',
                    'novel_audits',
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
            if (!getNovel($novelId)) throw new RuntimeException('小说不存在');
            $modelId = $input['model_id'] ? (int)$input['model_id'] : null;
            // 审计修复 L-10（2026-07-01）：验证 model_id 确实存在于 ai_models 表
            if ($modelId !== null) {
                $modelExists = DB::fetch('SELECT 1 FROM ai_models WHERE id=?', [$modelId]);
                if (!$modelExists) throw new RuntimeException('指定的模型不存在');
            }
            DB::update('novels', ['model_id' => $modelId], 'id=?', [$novelId]);
            jsonResponse(true, null, '模型已更新');
            break;

        // -----------------------------------------------------------
        case 'update_novel_status':
            $novelId = (int)($input['novel_id'] ?? 0);
            if (!getNovel($novelId)) throw new RuntimeException('小说不存在');
            $status  = $input['status'] ?? '';
            if (!in_array($status, ['draft','writing','paused','completed'])) {
                throw new RuntimeException('无效状态');
            }
            DB::update('novels', ['status' => $status], 'id=?', [$novelId]);
            jsonResponse(true, ['status' => $status]);
            break;

        // -----------------------------------------------------------
        // v1.11.8: 更新小说设置（target_chapters 等）
        case 'update_novel_settings':
            $novelId = (int)($input['novel_id'] ?? 0);
            if (!$novelId) throw new RuntimeException('缺少小说ID');

            $updates = [];

            // 目标章节数
            if (isset($input['target_chapters'])) {
                $targetChapters = (int)$input['target_chapters'];
                if ($targetChapters < 1 || $targetChapters > 10000) {
                    throw new RuntimeException('目标章节数必须在 1-10000 之间');
                }
                $updates['target_chapters'] = $targetChapters;
            }

            // 每章字数
            if (isset($input['chapter_words'])) {
                $chapterWords = (int)$input['chapter_words'];
                if ($chapterWords < 500 || $chapterWords > 20000) {
                    throw new RuntimeException('每章字数必须在 500-20000 之间');
                }
                $updates['chapter_words'] = $chapterWords;
            }

            if (empty($updates)) {
                throw new RuntimeException('没有需要更新的字段');
            }

            DB::update('novels', $updates, 'id=?', [$novelId]);

            // 返回更新后的数据
            $novel = getNovel($novelId);
            jsonResponse(true, [
                'target_chapters' => $novel['target_chapters'],
                'chapter_words'   => $novel['chapter_words'],
            ], '设置已更新');
            break;

        // -----------------------------------------------------------
        case 'get_novel_status':
            $novelId = (int)($input['novel_id'] ?? 0);
            $mode    = $input['mode'] ?? 'normal'; // normal=安全自动前进，catchup=显式查看 skipped
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');

            // 根据模式选择待查状态（白名单校验，防注入）
            $allowedModes = ['normal' => 'outlined', 'catchup' => 'skipped'];
            $statusValue  = $allowedModes[$mode] ?? 'outlined';

            if ($mode === 'catchup') {
                // catchup 是显式人工查询入口：允许用户查看并选择历史 skipped，
                // 但 normal 自动写作绝不能静默使用这些旧章。
                $nextChapter = DB::fetch(
                    "SELECT id, chapter_number, title, status FROM chapters
                     WHERE novel_id=? AND status=? ORDER BY chapter_number ASC LIMIT 1",
                    [$novelId, $statusValue]
                );
            } else {
                $nextAutoChapterId = WriteEngine::findNextAutomaticChapterId($novelId);
                $nextChapter = $nextAutoChapterId
                    ? DB::fetch(
                        'SELECT id, chapter_number, title, status FROM chapters WHERE id=? AND novel_id=?',
                        [$nextAutoChapterId, $novelId]
                    )
                    : null;
            }
            $completedCount = DB::count('chapters', 'novel_id=? AND status="completed"', [$novelId]);
            $outlinedCount  = DB::count('chapters', 'novel_id=? AND status IN ("outlined","writing","completed","skipped")', [$novelId]);
            $skippedCount   = DB::count('chapters', 'novel_id=? AND status="skipped"', [$novelId]);
            $failedCount    = DB::count('chapters', 'novel_id=? AND status="failed"', [$novelId]);
            $unfinishedCount = DB::count('chapters', 'novel_id=? AND status<>"completed"', [$novelId]);
            $autoWritableCount = WriteEngine::countAutomaticWritableChapters($novelId);
            $manualBackfillCount = WriteEngine::countManualBackfillChapters($novelId);
            jsonResponse(true, [
                'status'          => $novel['status'],
                'current_chapter' => $novel['current_chapter'],
                'total_words'     => $novel['total_words'],
                'completed_count' => $completedCount,
                'outlined_count'  => $outlinedCount,
                'skipped_count'   => $skippedCount,
                'failed_count'    => $failedCount,
                'unfinished_count' => $unfinishedCount,
                'auto_writable_count' => $autoWritableCount,
                'manual_backfill_count' => $manualBackfillCount,
                'next_chapter'    => $nextChapter,
                'all_done'        => $unfinishedCount === 0,
                'auto_blocked'    => $mode === 'normal' && !$nextChapter && $unfinishedCount > 0,
                'manual_mode'     => $mode === 'catchup',
            ]);
            break;

        case 'get_fullbook_audits':
            // v41: 取全书一致性体检报告（最近 N 条）
            $novelId = (int)($input['novel_id'] ?? 0);
            if (!getNovel($novelId)) throw new RuntimeException('小说不存在');
            require_once dirname(__DIR__) . '/includes/FullBookAudit.php';
            $limit = max(1, min(20, (int)($input['limit'] ?? 10)));
            $audits = FullBookAudit::recent($novelId, $limit);
            foreach ($audits as &$a) {
                $a['issues'] = $a['issues'] ? (json_decode($a['issues'], true) ?: []) : [];
            }
            unset($a);
            jsonResponse(true, ['audits' => $audits]);
            break;

        // -----------------------------------------------------------
        case 'reset_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            if ($ch['status'] === 'writing') {
                jsonResponse(false, null, '章节正在写作中，无法重置', 'CHAPTER_WRITING');
            }

            ChapterMutationService::mutateChapter($chapterId, (int)$ch['novel_id'], [
                'content' => '',
                'words'   => 0,
                'status'  => 'outlined',
            ], [
                'backup_version' => true,
                'prevent_writing' => true,
                'force_content_invalidation' => true,
                'reason' => 'reset_chapter',
            ]);
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
        // 删除单个章节（整行删除：正文/细纲/概要/历史版本一并清除；
        // 章节号留下空洞，可用「补写缺失细纲」重新生成）
        case 'delete_chapter':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            if ($ch['status'] === 'writing') {
                throw new RuntimeException('该章节正在写作中，请先取消写作再删除');
            }

            $chNovelId = (int)$ch['novel_id'];
            $chNum     = (int)$ch['chapter_number'];

            ChapterMutationService::deleteChapter($chapterId, $chNovelId);
            updateNovelStats($chNovelId);
            addLog($chNovelId, 'info', "已删除第{$chNum}章《" . ($ch['title'] ?: '未命名') . "》（含历史版本与概要）");
            jsonResponse(true, null, "第{$chNum}章已删除");
            break;

        // -----------------------------------------------------------
        case 'get_outline_progress':
            $novelId = (int)($input['novel_id'] ?? 0);
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');

            // 审计修复 P-2（2026-06-22）：短时缓存（5 秒），减少断线重连时的 DB 压力。
            // 前端 generateOutline 在网络抖动时会频繁重连，每次都查询 chapters 表两次。
            // 5 秒缓存足够覆盖重连间隔，且不会让用户感知到进度延迟。
            static $progressCache = [];
            $cacheKey = $novelId;
            if (isset($progressCache[$cacheKey]) && (time() - $progressCache[$cacheKey]['ts']) < 5) {
                jsonResponse(true, $progressCache[$cacheKey]['data']);
                break;
            }

            $outlinedCount = DB::count('chapters', 'novel_id=? AND status != "pending"', [$novelId]);
            // 查询最大已大纲章节号，用于断线续接
            $lastRow = DB::fetch(
                'SELECT MAX(chapter_number) AS max_ch FROM chapters WHERE novel_id=? AND status != "pending"',
                [$novelId]
            );
            $lastOutlined = (int)($lastRow['max_ch'] ?? 0);

            // 检测当前使用的模型是否支持 1M 上下文
            $is1MModel = false;
            $modelName = '';
            try {
                $aiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
                $is1MModel = $aiClient->is1MContext();
                $modelName = $aiClient->modelLabel;
            } catch (Throwable $e) {
                // 忽略
            }

            $progressData = [
                'outlined'     => $outlinedCount,
                'total'        => (int)$novel['target_chapters'],
                'last_outlined' => $lastOutlined,
                'is_1m_model'  => $is1MModel,
                'model_name'   => $modelName,
            ];
            $progressCache[$cacheKey] = ['ts' => time(), 'data' => $progressData];
            jsonResponse(true, $progressData);
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
        // 重新生成并重置（原子操作）：保存大纲后立即重置章节为待写状态
        case 'regenerate_and_reset':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');

            $novel = getNovel($ch['novel_id']);
            if (!$novel) throw new RuntimeException('小说不存在');
            if (($ch['status'] ?? '') === 'writing') {
                jsonResponse(false, null, '章节正在写作中，无法重新生成', 'CHAPTER_WRITING');
            }

            $outline   = trim($input['outline']   ?? $ch['outline'] ?? '');
            $hook      = trim($input['hook']      ?? $ch['hook']    ?? '');
            $keyPoints = $input['key_points']     ?? (json_decode($ch['key_points'] ?? '[]', true) ?? []);

            if (empty($outline) && empty($keyPoints)) {
                throw new RuntimeException('请先填写大纲概要或关键情节点');
            }
            if (!is_array($keyPoints)) $keyPoints = [];
            $keyPoints = array_values(array_filter(
                array_map(fn($p) => trim((string)$p), $keyPoints),
                fn($p) => $p !== ''
            ));

            // 事务包裹：保存大纲 + 备份版本 + 重置状态，三步原子执行
            $pdo = DB::getPdo();
            $pdo->beginTransaction();
            try {
                ChapterMutationService::mutateChapter($chapterId, (int)$ch['novel_id'], [
                    'outline'    => $outline,
                    'hook'       => $hook,
                    'key_points' => $keyPoints ? json_encode($keyPoints, JSON_UNESCAPED_UNICODE) : null,
                    'content' => '',
                    'words'   => 0,
                    'status'  => 'outlined',
                ], [
                    'backup_version' => true,
                    'prevent_writing' => true,
                    'force_content_invalidation' => true,
                    'force_outline_invalidation' => true,
                    'reason' => 'regenerate_and_reset',
                ]);

                $pdo->commit();
                addLog($ch['novel_id'], 'regenerate', "第{$ch['chapter_number']}章大纲更新并重置（原子操作）");
                jsonResponse(true, [
                    'chapter_id'   => $chapterId,
                    'novel_id'     => $ch['novel_id'],
                    'should_write' => true,
                ], '大纲已保存，章节已重置');
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // -----------------------------------------------------------
        case 'clear_all_chapters':
            $novelId = (int)($input['novel_id'] ?? 0);
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');

            $pdo = DB::getPdo();
            $pdo->beginTransaction();
            try {
                // 统一清理所有可按章节定位的正文派生数据；人物设定、卷/全书大纲等
                // 用户策划数据仍由下方显式列表决定是否清除。
                ChapterMutationService::invalidateNovelFromChapter($novelId, 1, 'clear_all_chapters');

                // 1. 删除 chapter_versions（子表，通过 chapter_id 关联）
                $chapterIds = DB::fetchAll('SELECT id FROM chapters WHERE novel_id=?', [$novelId]);
                if ($chapterIds) {
                    $ids = array_column($chapterIds, 'id');
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    DB::execute("DELETE FROM chapter_versions WHERE chapter_id IN ($ph)", $ids);
                }

                // 2. 删除 character_card_history（子表，通过 card_id 关联）
                $cardIds = DB::fetchAll('SELECT id FROM character_cards WHERE novel_id=?', [$novelId]);
                if ($cardIds) {
                    $ids = array_column($cardIds, 'id');
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    DB::execute("DELETE FROM character_card_history WHERE card_id IN ($ph)", $ids);
                }

                // 作者锁定的圣经节点属于人工设定，清空章节时保留；动态节点
                // 会随正文重建。写作向导记录、迭代偏好和导入会话同样保留。
                DB::delete('bible_nodes', 'novel_id=? AND is_locked=0', [$novelId]);

                // 3. 批量删除正文、规划及所有由正文产生的投影数据。
                $novelTables = [
                    'chapters',
                    'chapter_synopses',
                    'writing_logs',
                    'volume_outlines',
                    'arc_summaries',
                    'novel_characters',
                    'novel_worldbuilding',
                    'novel_plots',
                    'novel_style',
                    'novel_embeddings',
                    'character_cards',
                    'foreshadowing_mention_log',
                    'foreshadowing_items',
                    'novel_state',
                    'novel_scene_templates',
                    'memory_atoms',
                    'character_emotion_history',
                    'consistency_logs',
                    'agent_decision_logs',
                    'agent_action_logs',
                    'agent_directives',
                    'agent_directive_outcomes',
                    'constraint_state',
                    'constraint_logs',
                    'pid_states',
                    'catchphrase_callback_log',
                    'novel_catchphrases',
                    'story_relations',
                    'novel_bible',
                    'novel_audits',
                ];
                foreach ($novelTables as $table) {
                    DB::delete($table, 'novel_id=?', [$novelId]);
                }

                // 4. 重置小说状态
                DB::update('novels', [
                    'status'            => 'draft',
                    'current_chapter'   => 0,
                    'total_words'       => 0,
                    'optimized_chapter' => 0,
                ], 'id=?', [$novelId]);

                $pdo->commit();

                // 清除章节列表 / 章节数 / 小说信息缓存，否则页面 reload 后
                // getNovelChapters() 仍读取 5 分钟内的旧缓存，章节列表不会清空
                clearNovelCache($novelId);

                jsonResponse(true, ['novel_id' => $novelId], '已清空所有章节');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('actions clearChapters: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                throw new RuntimeException('清空失败，请稍后重试');
            }
            break;

        // -----------------------------------------------------------
        case 'save_announcement_url':
            // 审计修复 M1（2026-06-12）：全局公告地址属于系统级配置，
            // 必须限制为管理员（约定 user_id=1 为首注册管理员）才能修改，
            // 否则多用户场景下任何已登录用户都能改写全站公告。
            if ($userId !== 1) {
                jsonResponse(false, null, '仅管理员可修改系统公告', 'forbidden');
                break;
            }
            $url = trim($input['url'] ?? '');
            // 基本校验
            if ($url && !preg_match('#^https?://#i', $url)) {
                jsonResponse(false, null, '请输入有效的 http/https 地址');
                break;
            }
            // 如果 URL 为空则删除配置
            if ($url === '') {
                DB::query("DELETE FROM system_settings WHERE setting_key='announcement_url'");
            } else {
                $pdo = DB::connect();
                $stmt = $pdo->prepare(
                    "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?"
                );
                $stmt->execute(['announcement_url', $url, $url]);
            }
            clearSystemSettingsCache();
            jsonResponse(true, ['url' => $url], '公告地址已保存');
            break;

        // -----------------------------------------------------------
        case 'add_chapters':
            $novelId = (int)($input['novel_id'] ?? 0);
            $count   = (int)($input['count'] ?? 0);
            $mode    = trim($input['mode'] ?? 'empty');
            $novel   = getNovel($novelId);
            if (!$novel) throw new RuntimeException('小说不存在');
            if ($count < 1 || $count > 200) throw new RuntimeException('章节数量需在 1-200 之间');

            $maxCh = (int)(DB::fetch(
                'SELECT COALESCE(MAX(chapter_number), 0) AS m FROM chapters WHERE novel_id=?',
                [$novelId]
            )['m'] ?? 0);

            if ($mode === 'empty') {
                $pdo = DB::getPdo();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO chapters (novel_id, chapter_number, title, status) VALUES (?, ?, ?, ?)'
                    );
                    $startNum = $maxCh + 1;
                    for ($i = 0; $i < $count; $i++) {
                        $stmt->execute([$novelId, $startNum + $i, '', 'outlined']);
                    }
                    $pdo->commit();
                    updateNovelStats($novelId);
                    $endNum = $startNum + $count - 1;
                    jsonResponse(true, [
                        'added'         => $count,
                        'start_chapter' => $startNum,
                        'end_chapter'   => $endNum,
                    ], "已添加 {$count} 个空章节（第{$startNum}-{$endNum}章）");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('actions addChapters: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                    throw new RuntimeException('添加失败，请稍后重试');
                }
            } else {
                jsonResponse(true, [
                    'added'       => 0,
                    'mode'        => 'outline',
                    'start_chapter' => $maxCh + 1,
                    'end_chapter'   => $maxCh + $count,
                    'novel_id'      => $novelId,
                ], 'outline');
            }
            break;

        // -----------------------------------------------------------
        case 'generate_chapter_title':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            if (!$chapterId) throw new RuntimeException('缺少章节ID');
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            $novel = getNovel($ch['novel_id']);
            if (!$novel) throw new RuntimeException('小说不存在');

            $chNum   = (int)($ch['chapter_number'] ?? 0);
            $outline = trim($ch['outline'] ?? '');
            $synopsis = '';
            if (!empty($ch['synopsis_id'])) {
                $syn = DB::fetch('SELECT synopsis FROM chapter_synopses WHERE id=?', [$ch['synopsis_id']]);
                $synopsis = trim($syn['synopsis'] ?? '');
            }
            if (empty($outline) && empty($synopsis)) {
                throw new RuntimeException('该章节没有大纲或概要，无法生成标题');
            }

            $prevChapters = DB::fetchAll(
                'SELECT chapter_number, title FROM chapters
                 WHERE novel_id=? AND chapter_number<? AND title IS NOT NULL AND title != ""
                 ORDER BY chapter_number DESC LIMIT 5',
                [$ch['novel_id'], $chNum]
            );
            $prevChapters = array_reverse($prevChapters);

            $prevTitles = '';
            if (!empty($prevChapters)) {
                $prevTitles = "前几章标题：\n";
                foreach ($prevChapters as $pc) {
                    $prevTitles .= "第{$pc['chapter_number']}章《{$pc['title']}》\n";
                }
            }

            $contextText = $synopsis ?: $outline;

            $system = '你是一位小说起名专家，擅长根据章节内容创作简洁有力的章节标题。';
            $user = <<<EOT
为小说《{$novel['title']}》第{$chNum}章生成一个章节标题。

【章节概要/大纲】
{$contextText}

{$prevTitles}
要求：
1. 标题要简洁有力，一般不超过10个字
2. 与前几章标题风格一致，不要重复
3. 能概括本章核心内容或制造悬念
4. 只输出标题文本，不要加书名号，不要有任何其他文字
EOT;

            $messages = [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ];

            $title = '';
            $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];

            try {
                withModelFallback(
                    $novel['model_id'] ?: null,
                    function (AIClient $ai) use ($messages, &$title, &$usage) {
                        $title = '';
                        $usage = $ai->chatStream($messages, function (string $token) use (&$title) {
                            if ($token === '[DONE]') return;
                            $title .= $token;
                        });
                    }
                );
            } catch (RuntimeException $e) {
                error_log('actions generateTitle failed: ' . $e->getMessage());
                throw new RuntimeException('标题生成失败，请稍后重试');
            }

            $title = trim($title, " \t\n\r\0\x0B\"'《》");
            if (empty($title)) throw new RuntimeException('标题生成结果为空');

            DB::update('chapters', ['title' => $title], 'id=?', [$chapterId]);
            clearChapterCache($chapterId, (int)$ch['novel_id']);
            addLog($ch['novel_id'], 'title', "第{$chNum}章标题已生成：{$title}");
            jsonResponse(true, ['title' => $title, 'chapter_id' => $chapterId], '标题已生成');
            break;

        // -----------------------------------------------------------
        // 单章「重新生成大纲」：AI 重新生成本章的大纲概要 / 关键情节点 / 结尾钩子。
        // 仅返回结果供前端填入文本框，不直接落库——由用户检查后点「保存大纲」。
        case 'regenerate_chapter_outline':
            $chapterId = (int)($input['chapter_id'] ?? 0);
            if (!$chapterId) throw new RuntimeException('缺少章节ID');
            $ch = getChapter($chapterId);
            if (!$ch) throw new RuntimeException('章节不存在');
            $novel = getNovel($ch['novel_id']);
            if (!$novel) throw new RuntimeException('小说不存在');

            $chNum = (int)($ch['chapter_number'] ?? 0);

            // 宏观上下文：全书故事主线
            $storyArc = '';
            try {
                $so = DB::fetch('SELECT story_arc FROM story_outlines WHERE novel_id=?', [$ch['novel_id']]);
                $storyArc = trim($so['story_arc'] ?? '');
            } catch (\Throwable $e) {
                // 审计修复 P2-4（2026-07-01）：故事主线读取失败会导致生成大纲偏离主线
                error_log('regenerate_chapter_outline story_arc fetch failed: ' . $e->getMessage());
            }

            // 衔接上下文：前 2 章 + 后 1 章的概要
            $prevChs = DB::fetchAll(
                'SELECT chapter_number, title, outline FROM chapters
                 WHERE novel_id=? AND chapter_number<? ORDER BY chapter_number DESC LIMIT 2',
                [$ch['novel_id'], $chNum]
            );
            $prevChs = array_reverse($prevChs);
            $nextCh = DB::fetch(
                'SELECT chapter_number, title, outline FROM chapters
                 WHERE novel_id=? AND chapter_number>? ORDER BY chapter_number ASC LIMIT 1',
                [$ch['novel_id'], $chNum]
            );

            $ctx = '';
            foreach ($prevChs as $pc) {
                $po = trim($pc['outline'] ?? '');
                $ctx .= "· 第{$pc['chapter_number']}章《{$pc['title']}》" . ($po !== '' ? "：{$po}" : '') . "\n";
            }
            $nextHint = '';
            if ($nextCh) {
                $no = trim($nextCh['outline'] ?? '');
                $nextHint = "【后一章（需自然衔接到此）】\n第{$nextCh['chapter_number']}章《{$nextCh['title']}》" . ($no !== '' ? "：{$no}" : '') . "\n";
            }

            $curTitle = trim($ch['title'] ?? '');
            $genre    = trim($novel['genre'] ?? '');
            $style    = trim($novel['writing_style'] ?? '');

            $system = '你是一位资深网文大纲师，擅长为单个章节设计紧凑、可写、能承上启下的章节细纲。'
                . '严格只输出 JSON，不要任何解释或多余文字。';
            $user = "为小说《{$novel['title']}》" . ($genre !== '' ? "（题材：{$genre}）" : '')
                . "第{$chNum}章" . ($curTitle !== '' ? "《{$curTitle}》" : '') . "重新设计章节细纲。\n\n"
                . ($storyArc !== '' ? "【全书主线（必须对齐，禁止偏离）】\n{$storyArc}\n\n" : '')
                . ($ctx !== '' ? "【前文概要】\n{$ctx}\n" : '')
                . $nextHint
                . ($style !== '' ? "\n【行文风格】{$style}\n" : '')
                . "\n要求：\n"
                . "1. summary（大纲概要）：本章剧情走向，≤150字，承接前文、为后一章铺垫；\n"
                . "2. key_points（关键情节点）：3-6 条，每条≤30字，按发生顺序排列；\n"
                . "3. hook（结尾钩子）：本章末尾制造悬念/期待的一句话，≤40字；\n"
                . "4. 不得照抄前后章内容，需聚焦本章。\n\n"
                . '严格输出 JSON：{"summary":"...","key_points":["...","..."],"hook":"..."}';

            $messages = [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ];

            $raw = '';
            try {
                withModelFallback(
                    $novel['model_id'] ?: null,
                    function (AIClient $ai) use ($messages, &$raw) {
                        $raw = '';
                        $ai->chatStream($messages, function (string $token) use (&$raw) {
                            if ($token === '[DONE]') return;
                            $raw .= $token;
                        });
                    }
                );
            } catch (RuntimeException $e) {
                error_log('actions regenerate_chapter_outline failed: ' . $e->getMessage());
                throw new RuntimeException('大纲生成失败，请稍后重试');
            }

            // 健壮解析 JSON（剥离代码围栏，从首个 { 截取）——与 extractChapterSynopsis 同风格
            $jsonRaw = $raw;
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $jsonRaw, $mm)) $jsonRaw = $mm[1];
            $jsonRaw = trim($jsonRaw);
            $st = strpos($jsonRaw, '{');
            if ($st !== false) $jsonRaw = substr($jsonRaw, $st);
            $parsed = json_decode($jsonRaw, true);
            if (!is_array($parsed)) throw new RuntimeException('大纲生成结果解析失败，请重试');

            $outlineText = trim((string)($parsed['summary'] ?? $parsed['outline'] ?? ''));
            $hookText    = trim((string)($parsed['hook'] ?? ''));
            $kp          = $parsed['key_points'] ?? [];
            if (!is_array($kp)) $kp = [];
            $kp = array_values(array_filter(
                array_map(fn($p) => trim((string)$p), $kp),
                fn($p) => $p !== ''
            ));

            if ($outlineText === '' && empty($kp)) {
                throw new RuntimeException('大纲生成结果为空，请重试');
            }

            addLog($ch['novel_id'], 'outline', "第{$chNum}章大纲已重新生成");
            jsonResponse(true, [
                'chapter_id' => $chapterId,
                'outline'    => $outlineText,
                'key_points' => $kp,
                'hook'       => $hookText,
            ], '大纲已重新生成');
            break;

        // -----------------------------------------------------------
        default:
            throw new RuntimeException("未知操作：$action");
    }
} catch (\PDOException $e) {
    // 审计修复 P3-2（2026-07-01）：PDOException 继承 RuntimeException，
    // 若不单独捕获会落入下方业务异常分支，导致 SQL 错误细节回显给客户端
    $rid = error_trace_id();
    error_log(sprintf('[%s] actions PDOException: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    jsonResponse(false, null, '操作失败，请稍后重试', 'internal_error:' . $rid);
} catch (RuntimeException $e) {
    // 业务异常：消息通常是已知业务错误（缺少参数、无效操作等），可直接展示；
    // 但仍统一带 request_id 以便关联服务端日志。
    $rid = error_trace_id();
    error_log(sprintf('[%s] actions RuntimeException: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    // 审计修复 P2-17（2026-07-12）：过滤可能的敏感信息
    // actions.php 内部显式 throw 的 RuntimeException 消息都是简短中文业务文案，
    // 但深层库代码抛出的 RuntimeException 可能包含文件路径/SQL/堆栈等敏感信息。
    // 仅在消息符合"短业务文案"特征时透出，否则回退到通用错误。
    $rawMsg = $e->getMessage();
    $safeMsg = _actionsSafeBusinessMessage($rawMsg);
    jsonResponse(false, null, $safeMsg, 'business_error:' . $rid);
} catch (Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] actions Throwable: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    jsonResponse(false, null, '操作失败，请稍后重试', 'internal_error:' . $rid);
}

/**
 * 过滤 RuntimeException 消息，仅暴露"短业务文案"特征的消息
 * 审计修复 P2-17（2026-07-12）
 *
 * 判定规则：
 *   - 长度 ≤ 200 字符
 *   - 不含路径分隔符（/ \）、SQL 关键字、堆栈关键字、文件扩展名
 *   - 仅含中文/字母/数字/常用标点
 *
 * @param string $msg 原始异常消息
 * @return string 可安全暴露的消息，不符合特征时回退到通用提示
 */
function _actionsSafeBusinessMessage(string $msg): string
{
    if ($msg === '') return '操作失败，请稍后重试';
    if (mb_strlen($msg) > 200) return '操作失败，请稍后重试';

    // 命中任一敏感模式即视为不安全
    $sensitivePatterns = [
        '/\//i',              // Unix 路径
        '/\\\\/u',            // Windows 路径或转义
        '/\b(SQL|SQLSTATE|PDO|mysql|mysqli|sqlite|ORA-\d+)\b/i',
        '/\b(Stack trace|thrown in|on line \d+)\b/i',
        '/\b(SELECT|INSERT|UPDATE|DELETE|CREATE TABLE|ALTER TABLE|General error)\b/i',
        '/\[[A-Z]{2}\d{3,5}\]/',  // SQLSTATE 码，如 [HY000]
        '/\.(php|sql|log|conf|ini|env|json)\b/i',
        '/\b(host|port|user|pass|password|database|dbname)=/i',
        '/0x[0-9a-fA-F]{8,}/i', // 长十六进制串（可能是密钥/哈希）
    ];
    foreach ($sensitivePatterns as $pattern) {
        if (preg_match($pattern, $msg)) {
            return '操作失败，请稍后重试';
        }
    }

    // 仅允许：中文、字母、数字、空格、常用中文/英文标点、下划线
    if (preg_match('/^[\p{Han}\p{L}\p{N}\s\.,;:!?，。；：！？、（）()\[\]【】_\-—~·…""\'"]+$/u', $msg)) {
        return $msg;
    }
    return '操作失败，请稍后重试';
}
