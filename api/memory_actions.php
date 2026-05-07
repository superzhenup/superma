<?php
/**
 * v6: 记忆引擎 API 接口
 * 完全重写，对接新的 MemoryEngine (includes/memory/MemoryEngine.php)
 */

// 最先设置 header，防止任何错误输出导致 JSON 解析失败
header('Content-Type: application/json; charset=utf-8');

define('APP_LOADED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
registerApiErrorHandlers();
require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();
csrf_verify_api();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/memory/MemoryEngine.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';
$novelId = (int)($input['novel_id'] ?? $_GET['novel_id'] ?? 0);

// embedding_status 是全局检测，不需要 novel_id
if (!$novelId && $action !== 'embedding_status') {
    echo json_encode(['ok' => false, 'msg' => '缺少小说ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 初始化 MemoryEngine（embedding_status 不需要）
$engine = $novelId ? new MemoryEngine($novelId) : null;

try {
    switch ($action) {
        // ================================================================
        // 人物卡片操作 (CharacterCardRepo)
        // ================================================================

        case 'get_cards':
            // only_alive=true 只返回存活角色（默认全返回）
            // only_dead=true 只返回死亡/离场角色（前端也可自行过滤，这里双保险）
            $onlyAlive = !empty($input['only_alive']);
            $onlyDead  = !empty($input['only_dead']);
            if ($onlyDead) {
                // CharacterCardRepo 没有 only_dead 参数，全取后过滤
                $cards = array_values(array_filter(
                    $engine->cards()->listAll(false),
                    fn($c) => !$c['alive']
                ));
            } else {
                $cards = $engine->cards()->listAll($onlyAlive);
            }
            echo json_encode(['ok' => true, 'data' => $cards], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_card_history':
            $cardId = (int)($input['card_id'] ?? 0);
            if (!$cardId) {
                echo json_encode(['ok' => false, 'msg' => '缺少 card_id'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $history = $engine->cards()->getHistoryById($cardId, 100);
            echo json_encode(['ok' => true, 'data' => $history], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_card':
            $cardId = (int)($input['card_id'] ?? 0);
            $card = $engine->cards()->getById($cardId);
            echo json_encode(['ok' => !!$card, 'data' => $card], JSON_UNESCAPED_UNICODE);
            break;

        case 'upsert_card':
            $name = trim((string)($input['name'] ?? ''));
            $data = is_array($input['data'] ?? null) ? $input['data'] : [];
            // chapter 必填为 int（CharacterCardRepo::upsert 签名要求）
            $chapter = (int)($input['chapter_number'] ?? 0);
            if (!$name) {
                echo json_encode(['ok' => false, 'msg' => '缺少角色名称'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (empty($data)) {
                echo json_encode(['ok' => false, 'msg' => '缺少要更新的字段 data'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $id = $engine->cards()->upsert($name, $data, $chapter);
            echo json_encode(['ok' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_card':
            $cardId = (int)($input['card_id'] ?? 0);
            if (!$cardId) {
                echo json_encode(['ok' => false, 'msg' => '缺少卡片ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = $engine->cards()->delete($cardId);
            echo json_encode(['ok' => $result, 'msg' => $result ? '删除成功' : '删除失败'], JSON_UNESCAPED_UNICODE);
            break;

        // ================================================================
        // 伏笔操作 (ForeshadowingRepo)
        // ================================================================

        case 'get_pending_foreshadowing':
            $pending = $engine->foreshadowing()->listPending();
            echo json_encode(['ok' => true, 'data' => $pending], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_all_foreshadowing':
            // 获取所有伏笔（含已回收和未回收）
            $pending = $engine->foreshadowing()->listPending();
            $resolved = DB::fetchAll(
                'SELECT id, description, planted_chapter, deadline_chapter, resolved_chapter, resolved_at, created_at
                 FROM foreshadowing_items
                 WHERE novel_id=? AND resolved_chapter IS NOT NULL
                 ORDER BY planted_chapter ASC',
                [$novelId]
            );
            $all = array_merge($pending, $resolved);
            echo json_encode(['ok' => true, 'data' => $all], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_due_foreshadowing':
            $currentChapter = (int)($input['current_chapter'] ?? 1);
            $dueSoon = $engine->foreshadowing()->listDueSoon($currentChapter, 5);
            $overdue = $engine->foreshadowing()->listOverdue($currentChapter, 3);
            echo json_encode(['ok' => true, 'data' => [
                'overdue' => $overdue,
                'due_soon' => $dueSoon,
            ]], JSON_UNESCAPED_UNICODE);
            break;

        case 'plant_foreshadowing':
            $desc = $input['description'] ?? '';
            $chapter = (int)($input['planted_chapter'] ?? 0);
            $deadline = isset($input['deadline_chapter']) ? (int)$input['deadline_chapter'] : null;
            if (!$desc || !$chapter) {
                echo json_encode(['ok' => false, 'msg' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $id = $engine->foreshadowing()->plant($desc, $chapter, $deadline);
            echo json_encode(['ok' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
            break;

        case 'resolve_foreshadowing':
            $id = (int)($input['foreshadowing_id'] ?? 0);
            $chapter = (int)($input['resolved_chapter'] ?? 0);
            if (!$id) {
                echo json_encode(['ok' => false, 'msg' => '缺少伏笔ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 如果前端没给回收章节，用"当前最大已完成章节号"兜底
            if ($chapter <= 0) {
                $row = DB::fetch(
                    'SELECT COALESCE(MAX(chapter_number),0) AS c FROM chapters
                     WHERE novel_id=? AND status="completed"',
                    [$novelId]
                );
                $chapter = (int)($row['c'] ?? 0);
                if ($chapter <= 0) {
                    echo json_encode(['ok' => false, 'msg' => '无法推断回收章节号，请在请求中显式提供 resolved_chapter'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
            $result = $engine->foreshadowing()->resolveById($id, $chapter);
            echo json_encode(['ok' => $result, 'msg' => $result ? '已回收' : '回收失败（可能已被回收）'], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_foreshadowing':
            $id = (int)($input['foreshadowing_id'] ?? 0);
            if (!$id) {
                echo json_encode(['ok' => false, 'msg' => '缺少伏笔ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = $engine->foreshadowing()->delete($id);
            echo json_encode(['ok' => $result, 'msg' => $result ? '删除成功' : '删除失败'], JSON_UNESCAPED_UNICODE);
            break;

        // ================================================================
        // 原子记忆操作 (AtomRepo)
        // ================================================================

        case 'get_atoms':
            $type = !empty($input['atom_type']) ? (string)$input['atom_type'] : null;
            $chapter = isset($input['source_chapter']) && $input['source_chapter'] !== ''
                ? (int)$input['source_chapter']
                : null;
            $limit  = min((int)($input['limit']  ?? 51), 200);
            $offset = (int)($input['offset'] ?? 0);
            $atoms = $engine->atoms()->listAll($type, $chapter, $limit, $offset);
            echo json_encode(['ok' => true, 'data' => $atoms], JSON_UNESCAPED_UNICODE);
            break;

        case 'add_atom':
            $type = $input['atom_type'] ?? '';
            $content = $input['content'] ?? '';
            $chapter = isset($input['source_chapter']) && $input['source_chapter'] !== ''
                ? (int)$input['source_chapter']
                : null;
            $confidence = (float)($input['confidence'] ?? 0.8);
            // metadata 接受对象；字符串形式的 JSON 尝试解一次
            $metadata = $input['metadata'] ?? null;
            if (is_string($metadata) && $metadata !== '') {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : null;
            } elseif (!is_array($metadata)) {
                $metadata = null;
            }

            if (!$content) {
                echo json_encode(['ok' => false, 'msg' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 书名号《》自动归类到 technique (功法/技艺/招式)
            // 仅在用户未显式选择 atom_type 时生效
            if (!$type && preg_match('/《[^》]+》/u', $content)) {
                $type = 'technique';
            }

            if (!$type) {
                echo json_encode(['ok' => false, 'msg' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($type, AtomRepo::VALID_TYPES, true)) {
                echo json_encode(['ok' => false, 'msg' => '无效的原子类型'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $id = $engine->atoms()->add($type, $content, $chapter, $confidence, $metadata);
            echo json_encode(['ok' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_atom':
            $atomId = (int)($input['atom_id'] ?? 0);
            if (!$atomId) {
                echo json_encode(['ok' => false, 'msg' => '缺少原子记忆ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = $engine->atoms()->delete($atomId);
            echo json_encode(['ok' => $result, 'msg' => $result ? '删除成功' : '删除失败'], JSON_UNESCAPED_UNICODE);
            break;

        case 'update_atom':
            $atomId = (int)($input['atom_id'] ?? 0);
            $content = $input['content'] ?? '';
            $atomType = $input['atom_type'] ?? null;
            if (!$atomId || !$content) {
                echo json_encode(['ok' => false, 'msg' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = $engine->atoms()->updateContent($atomId, $content, $atomType);
            echo json_encode(['ok' => $result, 'msg' => $result ? '更新成功' : '更新失败'], JSON_UNESCAPED_UNICODE);
            break;

        case 'search_atoms':
            $keyword = $input['keyword'] ?? '';
            $type = $input['atom_type'] ?? null;
            $limit = (int)($input['limit'] ?? 10);

            if (!$keyword) {
                echo json_encode(['ok' => false, 'msg' => '缺少搜索关键词'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $atoms = $engine->atoms()->search($keyword, $type, $limit);
            echo json_encode(['ok' => true, 'data' => $atoms], JSON_UNESCAPED_UNICODE);
            break;

        // ================================================================
        // 综合操作
        // ================================================================

        case 'get_context':
            // 为写作获取完整记忆上下文
            $currentChapter = (int)($input['current_chapter'] ?? 1);
            $queryText = $input['query_text'] ?? null;
            $ctx = $engine->getPromptContext($currentChapter, $queryText);
            // 移除 debug 信息中可能过大的字段
            unset($ctx['debug']);
            echo json_encode(['ok' => true, 'data' => $ctx], JSON_UNESCAPED_UNICODE);
            break;

        case 'ensure_embeddings':
            // 手动触发 embedding 补齐
            $maxBatch = (int)($input['max_batch'] ?? 50);
            $report = $engine->ensureEmbeddings($maxBatch);
            echo json_encode(['ok' => true, 'data' => $report], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_stats':
            // v6 修复：返回前端期望的字段名
            $chRow = DB::fetch(
                'SELECT COUNT(*) AS cnt FROM chapters
                 WHERE novel_id=? AND status="completed"
                   AND chapter_summary IS NOT NULL AND chapter_summary != ""',
                [$novelId]
            );
            // atoms 总数走 SQL 聚合（避免 listAll 默认 limit=50 被截断）
            $atomsByType = $engine->atoms()->countByType();
            // 人物卡片数
            $cardsCount = count($engine->cards()->listAll());
            // 待回收伏笔数
            $foreshadowingStatus = $engine->foreshadowing()->status(PHP_INT_MAX);
            $stats = [
                'atoms'               => array_sum($atomsByType),
                'by_type'             => $atomsByType,
                'cards'               => $cardsCount,
                'foreshadowing_pending' => $foreshadowingStatus['total_pending'],
                'foreshadowing_overdue' => $foreshadowingStatus['overdue_count'],
                'chapters'            => (int)($chRow['cnt'] ?? 0),
            ];
            echo json_encode(['ok' => true, 'data' => $stats], JSON_UNESCAPED_UNICODE);
            break;

        case 'check_enabled':
            // 检查是否配置了 embedding
            require_once __DIR__ . '/../includes/memory/EmbeddingProvider.php';
            $cfg = EmbeddingProvider::getConfig();
            echo json_encode(['ok' => true, 'data' => ['configured' => !!$cfg]], JSON_UNESCAPED_UNICODE);
            break;

        case 'embedding_status':
            // 全面检测语义召回（记忆增强-Embedding）是否生效
            // 支持 novel_id=0（全局配置检测，跳过小说级统计）
            require_once __DIR__ . '/../includes/memory/EmbeddingProvider.php';
            $result = [
                'configured'     => false,   // 配置是否就绪
                'self_test_ok'   => false,   // API调用是否成功
                'atoms_total'    => 0,       // 记忆原子总数
                'atoms_with_vec' => 0,       // 已有向量的记忆原子数
                'kb_total'       => 0,       // 知识库条目总数
                'kb_with_vec'    => 0,       // 已有向量的知识库条目数
                'model_info'     => '',      // 模型信息
                'error'          => '',      // 错误信息
            ];

            // 第1步：检查 EmbeddingProvider 配置
            $cfg = EmbeddingProvider::getConfig();
            if (!$cfg) {
                $settingRow = DB::fetch("SELECT setting_value FROM system_settings WHERE setting_key='global_embedding_model_id'");
                $result['error'] = '未配置 embedding 模型'
                    . ($settingRow ? "（global_embedding_model_id={$settingRow['setting_value']}，但对应 ai_models.can_embed!=1）" : '（global_embedding_model_id 为空）');
                echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result['configured'] = true;
            $result['model_info'] = ($cfg['name'] ?? '') . ' / ' . ($cfg['embedding_model_name'] ?: $cfg['model_name']) . ' / dim=' . ($cfg['embedding_dim'] ?: '未知');

            // 第2步：selfTest — 实际调用方舟 API
            $test = EmbeddingProvider::selfTest();
            $result['self_test_ok'] = $test['ok'];
            if (!$test['ok']) {
                $result['error'] = 'API 调用失败：' . ($test['msg'] ?? '未知错误');
            } else {
                $result['model_info'] .= " (实测dim={$test['dim']})";
            }

            // 第3步：统计向量覆盖（仅在有 novel_id 时）
            if ($novelId > 0) {
                $atomStats = DB::fetch(
                    'SELECT COUNT(*) AS total, SUM(CASE WHEN embedding IS NOT NULL THEN 1 ELSE 0 END) AS with_vec FROM memory_atoms WHERE novel_id=?',
                    [$novelId]
                );
                $result['atoms_total']    = (int)($atomStats['total'] ?? 0);
                $result['atoms_with_vec'] = (int)($atomStats['with_vec'] ?? 0);

                $kbStats = DB::fetch(
                    'SELECT COUNT(*) AS total, SUM(CASE WHEN embedding_blob IS NOT NULL THEN 1 ELSE 0 END) AS with_vec FROM novel_embeddings WHERE novel_id=?',
                    [$novelId]
                );
                $result['kb_total']    = (int)($kbStats['total'] ?? 0);
                $result['kb_with_vec'] = (int)($kbStats['with_vec'] ?? 0);
            }

            echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
            break;

        // ================================================================
        // 快捷检索操作 (v6 兼容层)
        // ================================================================

        case 'get_character_memory':
            // 获取角色相关记忆 (character_trait 类型)
            $atoms = $engine->atoms()->listAll('character_trait', null, 50);
            echo json_encode(['ok' => true, 'data' => $atoms], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_plot_memory':
            // 获取情节相关记忆 (plot_detail 类型)
            $atoms = $engine->atoms()->listAll('plot_detail', null, 50);
            echo json_encode(['ok' => true, 'data' => $atoms], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_world_memory':
            // 获取世界观相关记忆 (world_setting 类型)
            $atoms = $engine->atoms()->listAll('world_setting', null, 50);
            echo json_encode(['ok' => true, 'data' => $atoms], JSON_UNESCAPED_UNICODE);
            break;

        // ================================================================
        // 场景聚类操作 (v6 兼容层 - 已整合到 atoms)
        // ================================================================

        case 'get_clusters':
            // v6: 场景聚类功能已整合到 atoms，返回空数组
            echo json_encode(['ok' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_cluster':
            // v6: 场景聚类功能已移除，返回成功但不执行任何操作
            echo json_encode(['ok' => true, 'msg' => '场景聚类功能已整合到原子记忆'], JSON_UNESCAPED_UNICODE);
            break;

        // ================================================================
        // 小说画像操作 (v6 兼容层)
        // ================================================================

        case 'get_persona':
            // v6: 画像功能已整合到 atoms
            // 从 atoms 中提取风格偏好和约束条件
            $stylePrefs = $engine->atoms()->listAll('style_preference', null, 10);
            $constraints = $engine->atoms()->listAll('constraint', null, 10);
            
            $persona = [
                'writing_style' => '',
                'narrative_techniques' => '',
                'theme_preferences' => '',
                'character_archetypes' => '',
                'world_building_patterns' => '',
                'tone_consistency' => '',
            ];
            
            // 从 style_preference atoms 中提取风格信息
            if (!empty($stylePrefs)) {
                $persona['writing_style'] = implode("\n", array_column($stylePrefs, 'content'));
            }
            
            // 从 constraint atoms 中提取约束信息
            if (!empty($constraints)) {
                $persona['tone_consistency'] = implode("\n", array_column($constraints, 'content'));
            }
            
            echo json_encode(['ok' => true, 'data' => $persona], JSON_UNESCAPED_UNICODE);
            break;

        case 'extract_chapter':
            // 从已完成章节中提取记忆原子（前端章节写完后调用）
            $chapterId = (int)($input['chapter_id'] ?? 0);
            if (!$chapterId) {
                echo json_encode(['ok' => false, 'msg' => '缺少章节ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 查章节内容
            $chapter = DB::fetch(
                'SELECT id, chapter_number, content, chapter_summary FROM chapters WHERE id=? AND novel_id=?',
                [$chapterId, $novelId]
            );
            if (!$chapter) {
                echo json_encode(['ok' => false, 'msg' => '章节不存在'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 尝试从 chapter_summary 重建记忆（老版本可能是JSON，新版本是纯文本）
            if (!empty($chapter['chapter_summary'])) {
                $summary = json_decode($chapter['chapter_summary'], true);
                if (is_array($summary)) {
                    $report = $engine->ingestChapter((int)$chapter['chapter_number'], $summary);
                    echo json_encode(['ok' => true, 'msg' => '记忆提取完成', 'data' => $report], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            // chapter_summary 是纯文本（新版格式），或无 summary：作为情节记忆写入
            $content = trim((string)($chapter['chapter_summary'] ?: $chapter['content'] ?? ''));
            if ($content === '') {
                echo json_encode(['ok' => false, 'msg' => '章节暂无内容，无法提取'], JSON_UNESCAPED_UNICODE);
                break;
            }
            // 截取前300字作为情节记忆
            $snippet = mb_substr($content, 0, 300);
            $atomId = $engine->atoms()->add('plot_detail', "第{$chapter['chapter_number']}章：{$snippet}", (int)$chapter['chapter_number'], 0.7);
            echo json_encode(['ok' => true, 'msg' => '已提取情节记忆', 'data' => ['atom_id' => $atomId]], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['ok' => false, 'msg' => '未知操作: ' . $action], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}