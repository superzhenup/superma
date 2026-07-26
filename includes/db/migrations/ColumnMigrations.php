<?php
/**
 * 列补齐迁移：ADD COLUMN / MODIFY COLUMN
 *
 * 从 DB::migrate() 提取（2026-06-17 重构）。
 * 复用 SchemaMigrator::columnExists 替代重复的 information_schema 查询。
 * 来源：db.php line 260-438（$columns）, 552-570（ai_models）, 803-870（$alterColumns）, 1065-1126（v14 字段对齐）
 */

require_once __DIR__ . '/../SchemaMigrator.php';

defined('APP_LOADED') or die('Direct access denied.');

class ColumnMigrations
{
    public static function up(PDO $pdo, array &$errors): void
    {
        // ① 主列补齐数组（原 db.php line 261-438）
        $columns = [
            // novels 表
            ['novels', 'user_id',
             "ALTER TABLE `novels` ADD COLUMN `user_id` INT UNSIGNED DEFAULT NULL COMMENT '关联用户ID' AFTER `id`"],
            ['novels', 'cancel_flag',
             "ALTER TABLE `novels` ADD COLUMN `cancel_flag` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_words`"],
            ['novels', 'has_story_outline',
             "ALTER TABLE `novels` ADD COLUMN `has_story_outline` TINYINT(1) DEFAULT 0 COMMENT '是否已生成全书故事大纲' AFTER `model_id`"],
            // [v5] 大纲优化进度跟踪
            ['novels', 'optimized_chapter',
             "ALTER TABLE `novels` ADD COLUMN `optimized_chapter` INT DEFAULT 0 COMMENT '大纲优化进度（最后优化的章节号）' AFTER `has_story_outline`"],
            // chapters 表
            ['chapters', 'chapter_summary',
             "ALTER TABLE `chapters` ADD COLUMN `chapter_summary` TEXT COMMENT 'AI生成的章节摘要' AFTER `content`"],
            ['chapters', 'used_tropes',
             "ALTER TABLE `chapters` ADD COLUMN `used_tropes` TEXT COMMENT '本章已使用的意象(JSON数组)' AFTER `chapter_summary`"],
            ['chapters', 'synopsis_id',
             "ALTER TABLE `chapters` ADD COLUMN `synopsis_id` INT DEFAULT NULL COMMENT '章节简介ID' AFTER `hook`"],
            // [v12] 挂机写作守护进程控制字段
            ['novels', 'daemon_write',
             "ALTER TABLE `novels` ADD COLUMN `daemon_write` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用挂机写作' AFTER `cancel_flag`"],
            // [v15] chapters 表补全缺失字段
            ['chapters', 'pacing',
             "ALTER TABLE `chapters` ADD COLUMN `pacing` VARCHAR(10) NOT NULL DEFAULT '中' COMMENT '节奏：快/中/慢' AFTER `hook`"],
            ['chapters', 'suspense',
             "ALTER TABLE `chapters` ADD COLUMN `suspense` VARCHAR(10) NOT NULL DEFAULT '无' COMMENT '悬念：有/无' AFTER `pacing`"],
            ['chapters', 'hook_type',
             "ALTER TABLE `chapters` ADD COLUMN `hook_type` VARCHAR(30) DEFAULT NULL COMMENT '钩子六式类型' AFTER `hook`"],
            ['chapters', 'cool_point_type',
             "ALTER TABLE `chapters` ADD COLUMN `cool_point_type` VARCHAR(30) DEFAULT NULL COMMENT '爽点类型' AFTER `hook_type`"],
            ['chapters', 'opening_type',
             "ALTER TABLE `chapters` ADD COLUMN `opening_type` VARCHAR(30) DEFAULT NULL COMMENT '开篇五式类型' AFTER `cool_point_type`"],
            ['chapters', 'quality_score',
             "ALTER TABLE `chapters` ADD COLUMN `quality_score` DECIMAL(3,1) DEFAULT NULL COMMENT '质量评分(0-100)' AFTER `suspense`"],
            ['chapters', 'gate_results',
             "ALTER TABLE `chapters` ADD COLUMN `gate_results` JSON DEFAULT NULL COMMENT '五关检测结果' AFTER `quality_score`"],
            // [v18] OptimizationAgent 数据基础
            ['chapters', 'tokens_used',
             "ALTER TABLE `chapters` ADD COLUMN `tokens_used` INT NOT NULL DEFAULT 0 COMMENT 'AI生成本章消耗的token总数' AFTER `gate_results`"],
            ['chapters', 'duration_ms',
             "ALTER TABLE `chapters` ADD COLUMN `duration_ms` INT NOT NULL DEFAULT 0 COMMENT '本章生成耗时(毫秒)' AFTER `tokens_used`"],
            // [v20] 写作算法反馈闭环：情绪密度统计
            ['chapters', 'emotion_density',
             "ALTER TABLE `chapters` ADD COLUMN `emotion_density` JSON DEFAULT NULL COMMENT '情绪词频统计(各类别次/万字)' AFTER `duration_ms`"],
            ['chapters', 'emotion_score',
             "ALTER TABLE `chapters` ADD COLUMN `emotion_score` DECIMAL(4,1) DEFAULT NULL COMMENT '情绪密度评分(0-100)' AFTER `emotion_density`"],
            // [v1.6] 写作算法反馈闭环：爽点实际类型识别
            ['chapters', 'actual_cool_point_types',
             "ALTER TABLE `chapters` ADD COLUMN `actual_cool_point_types` JSON DEFAULT NULL COMMENT '实际检测到的爽点类型(关键词匹配)' AFTER `emotion_score`"],
            // [v15] novels 表补全缺失字段
            ['novels', 'style_vector',
             "ALTER TABLE `novels` ADD COLUMN `style_vector` TEXT DEFAULT NULL COMMENT '四维风格向量(JSON)' AFTER `cover_color`"],
            ['novels', 'ref_author',
             "ALTER TABLE `novels` ADD COLUMN `ref_author` VARCHAR(200) DEFAULT NULL COMMENT '参考作者' AFTER `style_vector`"],
            // [v16] 封面图片字段
            ['novels', 'cover_image',
             "ALTER TABLE `novels` ADD COLUMN `cover_image` VARCHAR(500) DEFAULT NULL COMMENT '封面图片路径' AFTER `cover_color`"],
            // [v22] agent_decision_logs 补全缺失的 novel_id 列
            ['agent_decision_logs', 'novel_id',
             "ALTER TABLE `agent_decision_logs` ADD COLUMN `novel_id` INT NOT NULL DEFAULT 0 COMMENT '小说ID' FIRST, ADD INDEX `idx_novel_id` (`novel_id`)"],
            // [v24] story_outlines 新增人物弧线终点字段
            ['story_outlines', 'character_endpoints',
             "ALTER TABLE `story_outlines` ADD COLUMN `character_endpoints` TEXT COMMENT '人物弧线终点' AFTER `character_arcs`"],
            // [v25] foreshadowing_items 新增 priority 字段
            ['foreshadowing_items', 'priority',
             "ALTER TABLE `foreshadowing_items` ADD COLUMN `priority` ENUM('critical','major','minor') NOT NULL DEFAULT 'minor' COMMENT '伏笔优先级' AFTER `description`"],
            // [v26] chapters 新增 actual_opening_type 字段
            ['chapters', 'actual_opening_type',
             "ALTER TABLE `chapters` ADD COLUMN `actual_opening_type` VARCHAR(30) DEFAULT NULL COMMENT '实际检测到的开篇类型' AFTER `opening_type`"],
            // [v28] novels 表新增 author_profile_id 字段
            ['novels', 'author_profile_id',
             "ALTER TABLE `novels` ADD COLUMN `author_profile_id` INT UNSIGNED DEFAULT NULL COMMENT '绑定的作者画像ID' AFTER `ref_author`"],
            // [v29] author_profiles 新增4个风格提示词字段
            ['author_profiles', 'writing_habits_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `writing_habits_prompt` TEXT DEFAULT NULL COMMENT '写作习惯提示词' AFTER `influences`"],
            ['author_profiles', 'narrative_style_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `narrative_style_prompt` TEXT DEFAULT NULL COMMENT '叙事手法提示词' AFTER `writing_habits_prompt`"],
            ['author_profiles', 'sentiment_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `sentiment_prompt` TEXT DEFAULT NULL COMMENT '思想情感提示词' AFTER `narrative_style_prompt`"],
            ['author_profiles', 'creative_identity_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `creative_identity_prompt` TEXT DEFAULT NULL COMMENT '创作个性提示词' AFTER `sentiment_prompt`"],
            // [v30] story_outlines 表新增 character_progression 字段
            ['story_outlines', 'character_progression',
             "ALTER TABLE `story_outlines` ADD COLUMN `character_progression` JSON DEFAULT NULL COMMENT '角色等级/境界发展轨迹' AFTER `character_endpoints`"],
            // [v31] chapters 表新增 v1.9 盲点修复字段
            ['chapters', 'rewritten',
             "ALTER TABLE `chapters` ADD COLUMN `rewritten` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被RewriteAgent重写过' AFTER `quality_score`"],
            ['chapters', 'critic_scores',
             "ALTER TABLE `chapters` ADD COLUMN `critic_scores` JSON DEFAULT NULL COMMENT 'CriticAgent读者视角评分' AFTER `rewritten`"],
            ['chapters', 'ai_pattern_issues',
             "ALTER TABLE `chapters` ADD COLUMN `ai_pattern_issues` JSON DEFAULT NULL COMMENT 'StyleGuard AI痕迹检测结果' AFTER `critic_scores`"],
            // [v32] chapters 表新增 v1.10 迭代精炼系统字段
            ['chapters', 'iterations_used',
             "ALTER TABLE `chapters` ADD COLUMN `iterations_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '迭代改进轮数' AFTER `ai_pattern_issues`"],
            ['chapters', 'total_improvement',
             "ALTER TABLE `chapters` ADD COLUMN `total_improvement` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '总质量提升分数' AFTER `iterations_used`"],
            ['chapters', 'iterative_history',
             "ALTER TABLE `chapters` ADD COLUMN `iterative_history` JSON DEFAULT NULL COMMENT '迭代历史详情' AFTER `total_improvement`"],
            ['chapters', 'iteration_evaluation',
             "ALTER TABLE `chapters` ADD COLUMN `iteration_evaluation` JSON DEFAULT NULL COMMENT '迭代效果评估' AFTER `iterative_history`"],
            ['chapters', 'rewrite_time',
             "ALTER TABLE `chapters` ADD COLUMN `rewrite_time` DATETIME DEFAULT NULL COMMENT '最后一次重写时间' AFTER `iteration_evaluation`"],
            // [v34] chapters 表新增认知负荷字段
            ['chapters', 'cognitive_load',
             "ALTER TABLE `chapters` ADD COLUMN `cognitive_load` JSON DEFAULT NULL COMMENT '认知负荷分析：新元素数量、累计趋势' AFTER `rewrite_time`"],
            // [v34.1] StyleGuard 风格漂移检测报告
            ['chapters', 'style_drift_report',
             "ALTER TABLE `chapters` ADD COLUMN `style_drift_report` JSON DEFAULT NULL COMMENT 'StyleGuard风格漂移检测结果' AFTER `cognitive_load`"],
            // [v36] novel_state 表新增场景位置追踪字段
            ['novel_state', 'current_location',
             "ALTER TABLE `novel_state` ADD COLUMN `current_location` VARCHAR(200) DEFAULT NULL COMMENT '主角当前位置/场景' AFTER `story_momentum`"],
            ['novel_state', 'location_chapter',
             "ALTER TABLE `novel_state` ADD COLUMN `location_chapter` INT UNSIGNED DEFAULT NULL COMMENT '位置所在章节号' AFTER `current_location`"],
            ['novel_state', 'location_transition',
             "ALTER TABLE `novel_state` ADD COLUMN `location_transition` VARCHAR(300) DEFAULT NULL COMMENT '到达当前位置的方式描写' AFTER `location_chapter`"],
            // [v37] 高阶写作向导：novels 表新增向导模式字段
            ['novels', 'narrative_structure',
             "ALTER TABLE `novels` ADD COLUMN `narrative_structure` VARCHAR(50) DEFAULT NULL COMMENT '叙事结构' AFTER `target_reader`"],
            ['novels', 'narrative_method',
             "ALTER TABLE `novels` ADD COLUMN `narrative_method` VARCHAR(50) DEFAULT NULL COMMENT '叙事方法' AFTER `narrative_structure`"],
            ['novels', 'narrative_pov',
             "ALTER TABLE `novels` ADD COLUMN `narrative_pov` VARCHAR(50) DEFAULT NULL COMMENT '叙事视角' AFTER `narrative_method`"],
            ['novels', 'literary_genre',
             "ALTER TABLE `novels` ADD COLUMN `literary_genre` VARCHAR(100) DEFAULT NULL COMMENT '文学流派' AFTER `narrative_pov`"],
            ['novels', 'world_setting_era',
             "ALTER TABLE `novels` ADD COLUMN `world_setting_era` VARCHAR(100) DEFAULT NULL COMMENT '世界设定（时代）' AFTER `literary_genre`"],
            ['novels', 'novel_types',
             "ALTER TABLE `novels` ADD COLUMN `novel_types` JSON DEFAULT NULL COMMENT '小说类型多选' AFTER `world_setting_era`"],
            ['novels', 'writing_tone',
             "ALTER TABLE `novels` ADD COLUMN `writing_tone` JSON DEFAULT NULL COMMENT '文风多选' AFTER `novel_types`"],
            ['novels', 'protagonist_traits',
             "ALTER TABLE `novels` ADD COLUMN `protagonist_traits` JSON DEFAULT NULL COMMENT '主角设定多选' AFTER `writing_tone`"],
            ['novels', 'core_conflicts',
             "ALTER TABLE `novels` ADD COLUMN `core_conflicts` JSON DEFAULT NULL COMMENT '核心冲突多选' AFTER `protagonist_traits`"],
            ['novels', 'appeal_points',
             "ALTER TABLE `novels` ADD COLUMN `appeal_points` JSON DEFAULT NULL COMMENT '爽点多选' AFTER `core_conflicts`"],
            ['novels', 'taboos',
             "ALTER TABLE `novels` ADD COLUMN `taboos` JSON DEFAULT NULL COMMENT '禁忌多选' AFTER `appeal_points`"],
            ['novels', 'opening_type',
             "ALTER TABLE `novels` ADD COLUMN `opening_type` VARCHAR(50) DEFAULT NULL COMMENT '开篇类型' AFTER `taboos`"],
            ['novels', 'protagonist_entrance',
             "ALTER TABLE `novels` ADD COLUMN `protagonist_entrance` VARCHAR(50) DEFAULT NULL COMMENT '主角出场' AFTER `opening_type`"],
            ['novels', 'custom_settings',
             "ALTER TABLE `novels` ADD COLUMN `custom_settings` TEXT DEFAULT NULL COMMENT '自定义设定' AFTER `protagonist_entrance`"],
            ['novels', 'chapter_word_target',
             "ALTER TABLE `novels` ADD COLUMN `chapter_word_target` INT DEFAULT NULL COMMENT '单章字数目标' AFTER `custom_settings`"],
            // [v40] 短篇分章节写作支持
            ['short_stories', 'chapter_count',
             "ALTER TABLE `short_stories` ADD COLUMN `chapter_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '章节数量,1=单篇模式' AFTER `structure_type`"],
            ['short_stories', 'chapters_json',
             "ALTER TABLE `short_stories` ADD COLUMN `chapters_json` JSON DEFAULT NULL COMMENT '章节数组' AFTER `beats_json`"],
            ['short_story_versions', 'chapters_json',
             "ALTER TABLE `short_story_versions` ADD COLUMN `chapters_json` JSON DEFAULT NULL AFTER `beats_json`"],
            // [v41] 提示词缓存命中埋点
            ['chapters', 'cache_hit_tokens',
             "ALTER TABLE `chapters` ADD COLUMN `cache_hit_tokens` INT NOT NULL DEFAULT 0 COMMENT '本章命中的提示词缓存token数' AFTER `tokens_used`"],
            // [v43] 钩子回收闭环
            ['chapters', 'hook_resolved',
             "ALTER TABLE `chapters` ADD COLUMN `hook_resolved` TINYINT(1) DEFAULT NULL COMMENT '本章是否回收上章钩子(1是/0悬挂/NULL未检测)' AFTER `hook_type`"],
            // [v45] 1.7 PRO 图谱关系起始章节
            ['novel_state', 'graph_start_chapter',
             "ALTER TABLE `novel_state` ADD COLUMN `graph_start_chapter` INT UNSIGNED DEFAULT NULL COMMENT '图谱关系起始构建章节号(NULL=未启用)' AFTER `last_ingested_chapter`"],
        ];

        // ② v9 知识库扩展字段（原 db.php line 804-851）
        $alterColumns = [
            // novel_characters: 新增 role_template, first_chapter, climax_chapter
            ['novel_characters', 'role_template',
             "ALTER TABLE `novel_characters` ADD COLUMN `role_template` VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT '功能模板:mentor/opponent/romantic/brother/protagonist/other' AFTER `role_type`"],
            ['novel_characters', 'first_chapter',
             "ALTER TABLE `novel_characters` ADD COLUMN `first_chapter` INT DEFAULT NULL COMMENT '首次出场章节' AFTER `role_template`"],
            ['novel_characters', 'climax_chapter',
             "ALTER TABLE `novel_characters` ADD COLUMN `climax_chapter` INT DEFAULT NULL COMMENT '预期高潮/退场章节' AFTER `first_chapter`"],
            // novel_style: 新增四维向量 + 参考作者 + 高频词
            ['novel_style', 'vec_style',
             "ALTER TABLE `novel_style` ADD COLUMN `vec_style` VARCHAR(20) DEFAULT NULL COMMENT '文风:concise/ornate/humorous' AFTER `content`"],
            ['novel_style', 'vec_pacing',
             "ALTER TABLE `novel_style` ADD COLUMN `vec_pacing` VARCHAR(20) DEFAULT NULL COMMENT '节奏:fast/slow/alternating' AFTER `vec_style`"],
            ['novel_style', 'vec_emotion',
             "ALTER TABLE `novel_style` ADD COLUMN `vec_emotion` VARCHAR(20) DEFAULT NULL COMMENT '情感:passionate/warm/dark' AFTER `vec_pacing`"],
            ['novel_style', 'vec_intellect',
             "ALTER TABLE `novel_style` ADD COLUMN `vec_intellect` VARCHAR(20) DEFAULT NULL COMMENT '智慧:strategy/power/balanced' AFTER `vec_emotion`"],
            ['novel_style', 'ref_author',
             "ALTER TABLE `novel_style` ADD COLUMN `ref_author` VARCHAR(50) DEFAULT NULL COMMENT '参考作者' AFTER `vec_intellect`"],
            ['novel_style', 'keywords',
             "ALTER TABLE `novel_style` ADD COLUMN `keywords` TEXT DEFAULT NULL COMMENT '逗号分隔高频词' AFTER `ref_author`"],
            // novel_plots: 新增伏笔专用字段
            ['novel_plots', 'foreshadow_type',
             "ALTER TABLE `novel_plots` ADD COLUMN `foreshadow_type` VARCHAR(20) DEFAULT NULL COMMENT '伏笔类型:character/item/speech/faction/realm/identity' AFTER `event_type`"],
            ['novel_plots', 'expected_payoff',
             "ALTER TABLE `novel_plots` ADD COLUMN `expected_payoff` VARCHAR(200) DEFAULT NULL COMMENT '预期回收方式' AFTER `foreshadow_type`"],
            ['novel_plots', 'deadline_chapter',
             "ALTER TABLE `novel_plots` ADD COLUMN `deadline_chapter` INT UNSIGNED DEFAULT NULL COMMENT '建议回收章节' AFTER `expected_payoff`"],
            // novel_embeddings: 新增 embedding_updated_at
            ['novel_embeddings', 'embedding_updated_at',
             "ALTER TABLE `novel_embeddings` ADD COLUMN `embedding_updated_at` TIMESTAMP NULL DEFAULT NULL COMMENT '向量更新时间' AFTER `embedding_model`"],
            // [v1.10.3] character_cards: 新增 voice_profile
            ['character_cards', 'voice_profile',
             "ALTER TABLE `character_cards` ADD COLUMN `voice_profile` JSON DEFAULT NULL COMMENT '角色语音指纹JSON' AFTER `attributes`"],
            // [v1.10.3] foreshadowing_items: 新增伏笔生命周期字段
            ['foreshadowing_items', 'last_mentioned_chapter',
             "ALTER TABLE `foreshadowing_items` ADD COLUMN `last_mentioned_chapter` INT DEFAULT NULL COMMENT '最近一次被提及的章节'"],
            ['foreshadowing_items', 'mention_count',
             "ALTER TABLE `foreshadowing_items` ADD COLUMN `mention_count` INT NOT NULL DEFAULT 0 COMMENT '被提及次数'"],
            // [v1.10.3] novels: 新增 target_reader
            ['novels', 'target_reader',
             "ALTER TABLE `novels` ADD COLUMN `target_reader` VARCHAR(30) NOT NULL DEFAULT 'general' COMMENT '目标读者画像'"],
            // [v1.10.3] chapters: 人工评分 + 校准后Critic评分
            ['chapters', 'human_critic_scores',
             "ALTER TABLE `chapters` ADD COLUMN `human_critic_scores` JSON DEFAULT NULL COMMENT '人工读者视角评分(5维)'"],
            ['chapters', 'calibrated_critic_scores',
             "ALTER TABLE `chapters` ADD COLUMN `calibrated_critic_scores` JSON DEFAULT NULL COMMENT '校准后的Critic评分'"],
        ];

        // 合并两批，统一执行（复用 SchemaMigrator::columnExists）
        self::applyAddColumns($pdo, array_merge($columns, $alterColumns), $errors);

        // ③ [v6] ai_models 表添加 embedding_enabled 字段（原独立检查）
        self::addColumnIfMissing($pdo, 'ai_models', 'embedding_enabled',
            "ALTER TABLE `ai_models` ADD COLUMN `embedding_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用Embedding模型' AFTER `is_default`",
            $errors);

        // 老版本 ai_models 已存在时，Schema::applyAll(CREATE TABLE IF NOT EXISTS)
        // 不会补这些后增列。模型设置与显式 1M 能力依赖它们，必须逐列迁移。
        self::addColumnIfMissing($pdo, 'ai_models', 'thinking_enabled',
            "ALTER TABLE `ai_models` ADD COLUMN `thinking_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用深度思考(Thinking)'",
            $errors);
        self::addColumnIfMissing($pdo, 'ai_models', 'can_embed',
            "ALTER TABLE `ai_models` ADD COLUMN `can_embed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '此API端点是否可调embedding'",
            $errors);
        self::addColumnIfMissing($pdo, 'ai_models', 'embedding_model_name',
            "ALTER TABLE `ai_models` ADD COLUMN `embedding_model_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'embedding模型名'",
            $errors);
        self::addColumnIfMissing($pdo, 'ai_models', 'embedding_dim',
            "ALTER TABLE `ai_models` ADD COLUMN `embedding_dim` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'embedding向量维度'",
            $errors);
        self::addColumnIfMissing($pdo, 'ai_models', 'capabilities',
            "ALTER TABLE `ai_models` ADD COLUMN `capabilities` JSON DEFAULT NULL COMMENT '模型能力标签(JSON数组)'",
            $errors);

        // ④ [v14] 字段对齐线上：MODIFY 类型/长度（原 db.php line 1065-1126）
        self::modifyColumnIfType($pdo, 'novel_characters', 'alias', 'varchar(200)',
            "ALTER TABLE `novel_characters` MODIFY COLUMN `alias` VARCHAR(100) DEFAULT NULL COMMENT '别名/绰号'", $errors);
        self::modifyColumnIfType($pdo, 'novel_characters', 'gender', 'varchar(20)',
            "ALTER TABLE `novel_characters` MODIFY COLUMN `gender` VARCHAR(10) DEFAULT NULL COMMENT '性别'", $errors);
        self::modifyColumnIfType($pdo, 'novel_worldbuilding', 'name', 'varchar(100)',
            "ALTER TABLE `novel_worldbuilding` MODIFY COLUMN `name` VARCHAR(200) NOT NULL COMMENT '名称'", $errors);

        // 审计修复 M4：book_analyses.created_at 补默认值
        self::ensureColumnDefault($pdo, 'book_analyses', 'created_at',
            "ALTER TABLE `book_analyses` MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP", $errors);
    }

    /**
     * 批量 ADD COLUMN：复用 SchemaMigrator::columnExists 替代重复的 information_schema 查询。
     */
    private static function applyAddColumns(PDO $pdo, array $columns, array &$errors): void
    {
        foreach ($columns as [$table, $col, $sql]) {
            if (SchemaMigrator::columnExists($pdo, $table, $col)) {
                continue;
            }
            self::execAlter($pdo, "columns:{$table}.{$col}", $sql, $errors);
        }
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $col, string $sql, array &$errors): void
    {
        if (SchemaMigrator::columnExists($pdo, $table, $col)) {
            return;
        }
        self::execAlter($pdo, "columns:{$table}.{$col}", $sql, $errors);
    }

    /**
     * 仅当列类型匹配旧类型时执行 MODIFY（幂等：已升级则跳过）。
     */
    private static function modifyColumnIfType(PDO $pdo, string $table, string $col, string $oldTypeFragment, string $sql, array &$errors): void
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $col]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            $colType = is_array($row) ? strtolower((string)($row['COLUMN_TYPE'] ?? '')) : '';
            if ($colType !== '' && str_contains($colType, $oldTypeFragment)) {
                self::execAlter($pdo, "modify:{$table}.{$col}", $sql, $errors);
            }
        } catch (\Throwable $e) {
            error_log("ColumnMigrations: {$table}.{$col} 类型检查失败 — " . $e->getMessage());
        }
    }

    /**
     * 仅当列缺少默认值时执行 MODIFY（幂等：已有默认值则跳过）。
     */
    private static function ensureColumnDefault(PDO $pdo, string $table, string $col, string $sql, array &$errors): void
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $col]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            $hasDefault = is_array($row) && ($row['COLUMN_DEFAULT'] !== null
                || stripos((string)($row['EXTRA'] ?? ''), 'DEFAULT_GENERATED') !== false);
            if (is_array($row) && !$hasDefault) {
                self::execAlter($pdo, "modify:{$table}.{$col}", $sql, $errors);
            }
        } catch (\Throwable $e) {
            error_log("ColumnMigrations: {$table}.{$col} 默认值检查失败 — " . $e->getMessage());
        }
    }

    /**
     * 执行单条 ALTER，可忽略错误直接跳过，不可忽略错误收集到 $errors。
     */
    private static function execAlter(PDO $pdo, string $label, string $sql, array &$errors): void
    {
        try {
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            $message = $label . ' - ' . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }
}
