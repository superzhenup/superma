<?php
/**
 * Schema — 数据库表定义的单一真理源
 *
 * v1.4 引入：消除 install.php / db.php migrate() / migrations/*.sql 三处重复维护。
 * 新增表只需在这里添加一行，ALLOWED_TABLES 白名单和建表逻辑自动跟进。
 *
 * 使用方式：
 *   install.php  → Schema::applyAll($pdo)
 *   db.php       → Schema::applyAll($pdo) + ALLOWED_TABLES = Schema::whitelist()
 */
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/db/SchemaMigrator.php';

class Schema
{
    /**
     * 全表定义：表名 => CREATE TABLE IF NOT EXISTS SQL
     * 新增表只需在此处添加即可自动接入三层。
     */
    public static function tables(): array
    {
        return [
            // ========== Agent 体系表 ==========
            'agent_decision_logs' => "CREATE TABLE IF NOT EXISTS `agent_decision_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT NOT NULL COMMENT '小说ID',
                `agent_type` VARCHAR(50) NOT NULL COMMENT 'Agent类型: writing_strategy, quality_monitor, optimization',
                `decision_data` TEXT COMMENT '决策数据JSON',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                INDEX `idx_novel_id` (`novel_id`),
                INDEX `idx_agent_type` (`agent_type`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent决策日志表'",

            'agent_action_logs' => "CREATE TABLE IF NOT EXISTS `agent_action_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT NOT NULL COMMENT '小说ID',
                `agent_type` VARCHAR(50) NOT NULL COMMENT 'Agent类型',
                `action` VARCHAR(100) NOT NULL COMMENT '动作名称',
                `status` VARCHAR(20) NOT NULL COMMENT '执行状态: success, failed, skipped',
                `params` TEXT COMMENT '动作参数JSON',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                INDEX `idx_novel_id` (`novel_id`),
                INDEX `idx_agent_type` (`agent_type`),
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent动作日志表'",

            'agent_directives' => "CREATE TABLE IF NOT EXISTS `agent_directives` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT NOT NULL COMMENT '小说ID',
                `apply_from` INT NOT NULL COMMENT '起始章节号（从第几章开始生效）',
                `apply_to` INT NOT NULL COMMENT '失效章节号（到第几章失效）',
                `type` VARCHAR(30) NOT NULL COMMENT '指令类型: urgent/quality/strategy/optimization/global',
                `directive` TEXT NOT NULL COMMENT '自然语言指令内容',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                `expires_at` DATETIME COMMENT '过期时间（可选）',
                `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否激活',
                INDEX `idx_novel_chapter` (`novel_id`, `apply_from`, `apply_to`),
                INDEX `idx_type` (`type`),
                INDEX `idx_active` (`is_active`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent自然语言指令表'",

            'agent_directive_outcomes' => "CREATE TABLE IF NOT EXISTS `agent_directive_outcomes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT NOT NULL COMMENT '小说ID',
                `directive_id` INT NOT NULL COMMENT '关联的指令ID',
                `chapter_number` INT NOT NULL COMMENT '被评估的章节号',
                `quality_before` DECIMAL(4,1) DEFAULT NULL COMMENT '指令生效前质量均值',
                `quality_after` DECIMAL(4,1) DEFAULT NULL COMMENT '本章质量评分',
                `quality_change` DECIMAL(4,1) DEFAULT NULL COMMENT '质量变化(正=改善)',
                `tokens_used` INT NOT NULL DEFAULT 0 COMMENT '本章token用量',
                `duration_ms` INT NOT NULL DEFAULT 0 COMMENT '本章生成耗时(毫秒)',
                `evaluated_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '评估时间',
                INDEX `idx_novel_directive` (`novel_id`, `directive_id`),
                INDEX `idx_evaluated_at` (`evaluated_at`),
                INDEX `idx_quality_change` (`quality_change`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent指令效果反馈表'",

            'agent_performance_stats' => "CREATE TABLE IF NOT EXISTS `agent_performance_stats` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `agent_type` VARCHAR(50) NOT NULL COMMENT 'Agent类型',
                `stat_date` DATE NOT NULL COMMENT '统计日期',
                `decision_count` INT DEFAULT 0 COMMENT '决策次数',
                `action_count` INT DEFAULT 0 COMMENT '动作次数',
                `success_count` INT DEFAULT 0 COMMENT '成功次数',
                `failed_count` INT DEFAULT 0 COMMENT '失败次数',
                `avg_decision_time_ms` FLOAT DEFAULT 0 COMMENT '平均决策时间(毫秒)',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                UNIQUE KEY `uk_agent_date` (`agent_type`, `stat_date`),
                INDEX `idx_stat_date` (`stat_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent性能统计表'",

            // ========== 书籍分析表 ==========
            'book_analyses' => "CREATE TABLE IF NOT EXISTS `book_analyses` (
                `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '书名',
                `author`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '作者',
                `genre`       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '类型',
                `content`     MEDIUMTEXT NOT NULL COMMENT '分析结果(Markdown)',
                `source_text` MEDIUMTEXT DEFAULT NULL COMMENT '原始章节文本',
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='拆书分析表'",

            // ========== 核心业务表 ==========
            'arc_summaries' => "CREATE TABLE IF NOT EXISTS `arc_summaries` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT UNSIGNED NOT NULL,
                `arc_index` INT NOT NULL COMMENT '弧段编号，从1开始',
                `chapter_from` INT NOT NULL COMMENT '起始章节',
                `chapter_to` INT NOT NULL COMMENT '结束章节',
                `summary` TEXT COMMENT '200字弧段故事线摘要',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_arc` (`novel_id`, `arc_index`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'story_outlines' => "CREATE TABLE IF NOT EXISTS `story_outlines` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`              INT UNSIGNED NOT NULL UNIQUE,
                `story_arc`             TEXT COMMENT '故事主线发展脉络',
                `act_division`          JSON COMMENT '三幕划分',
                `major_turning_points`  JSON COMMENT '重大转折点',
                `character_arcs`        JSON COMMENT '人物成长轨迹',
                `character_endpoints`   TEXT COMMENT '人物弧线终点',
                `character_progression` JSON DEFAULT NULL COMMENT '角色等级/境界发展轨迹',
                `world_evolution`       TEXT COMMENT '世界观演变',
                `recurring_motifs`      JSON COMMENT '全书重复意象',
                `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'chapter_synopses' => "CREATE TABLE IF NOT EXISTS `chapter_synopses` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT UNSIGNED NOT NULL,
                `chapter_number` INT NOT NULL,
                `synopsis` TEXT,
                `scene_breakdown` JSON,
                `dialogue_beats` JSON,
                `sensory_details` JSON,
                `pacing` VARCHAR(20),
                `cliffhanger` TEXT,
                `foreshadowing` JSON,
                `callbacks` JSON,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_chapter` (`novel_id`, `chapter_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'chapter_versions' => "CREATE TABLE IF NOT EXISTS `chapter_versions` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `chapter_id` INT UNSIGNED NOT NULL,
                `version` INT NOT NULL DEFAULT 1,
                `content` LONGTEXT,
                `outline` TEXT,
                `title` VARCHAR(255),
                `words` INT DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_chapter_version` (`chapter_id`, `version`),
                KEY `idx_chapter_id` (`chapter_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'consistency_logs' => "CREATE TABLE IF NOT EXISTS `consistency_logs` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
                `chapter_number` INT NOT NULL,
                `check_type` VARCHAR(50),
                `issues` JSON,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_novel_id` (`novel_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'character_cards' => "CREATE TABLE IF NOT EXISTS `character_cards` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`              INT UNSIGNED NOT NULL,
                `name`                  VARCHAR(100) NOT NULL COMMENT '人物名',
                `title`                 VARCHAR(100) DEFAULT NULL COMMENT '当前职务/称号',
                `status`                VARCHAR(200) DEFAULT NULL COMMENT '当前处境一句话',
                `alive`                 TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否存活',
                `attributes`            JSON DEFAULT NULL COMMENT '扩展属性:等级/能力/关系等',
                `voice_profile`         JSON DEFAULT NULL COMMENT '角色语音指纹JSON',
                `last_updated_chapter`  INT UNSIGNED DEFAULT NULL COMMENT '最近一次被哪一章更新',
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel_name` (`novel_id`, `name`),
                KEY `idx_novel` (`novel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='人物状态卡片表'",

            'character_card_history' => "CREATE TABLE IF NOT EXISTS `character_card_history` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `card_id`               INT UNSIGNED NOT NULL,
                `chapter_number`        INT UNSIGNED NOT NULL COMMENT '变更发生的章节',
                `field_name`            VARCHAR(50) NOT NULL COMMENT '变更的字段名',
                `old_value`             TEXT COMMENT '旧值',
                `new_value`             TEXT COMMENT '新值',
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_card_chapter` (`card_id`, `chapter_number`),
                KEY `idx_field` (`card_id`, `field_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='人物卡片变更历史表'",

            'foreshadowing_items' => "CREATE TABLE IF NOT EXISTS `foreshadowing_items` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`              INT UNSIGNED NOT NULL,
                `description`           TEXT NOT NULL COMMENT '伏笔内容',
                `priority`              ENUM('critical','major','minor') NOT NULL DEFAULT 'minor' COMMENT '伏笔优先级',
                `planted_chapter`       INT UNSIGNED NOT NULL COMMENT '埋设章节',
                `deadline_chapter`      INT UNSIGNED DEFAULT NULL COMMENT '建议回收章节,NULL=无期限',
                `resolved_chapter`      INT UNSIGNED DEFAULT NULL COMMENT 'NULL=未回收',
                `resolved_at`           TIMESTAMP NULL DEFAULT NULL,
                `last_mentioned_chapter` INT UNSIGNED DEFAULT NULL COMMENT '最近提及章节',
                `mention_count`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '提及次数',
                `embedding`             BLOB DEFAULT NULL COMMENT '向量(用于语义匹配回收)',
                `embedding_model`       VARCHAR(100) DEFAULT NULL,
                `embedding_updated_at`  TIMESTAMP NULL DEFAULT NULL,
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_novel_unresolved` (`novel_id`, `resolved_chapter`),
                KEY `idx_deadline`         (`novel_id`, `deadline_chapter`),
                KEY `idx_priority`         (`novel_id`, `priority`),
                KEY `idx_embedding_null`   (`novel_id`, `embedding_updated_at`),
                FULLTEXT KEY `ft_description` (`description`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='伏笔独立表'",

            'foreshadowing_mention_log' => "CREATE TABLE IF NOT EXISTS `foreshadowing_mention_log` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `foreshadowing_id` INT UNSIGNED NOT NULL COMMENT '伏笔ID',
                `novel_id` INT UNSIGNED NOT NULL,
                `chapter_number` INT UNSIGNED NOT NULL COMMENT '提及章节',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_foreshadowing` (`foreshadowing_id`),
                KEY `idx_novel_ch` (`novel_id`, `chapter_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='伏笔提及日志表（v1.11.5：支持重写回滚）'",

            'novel_state' => "CREATE TABLE IF NOT EXISTS `novel_state` (
                `novel_id`              INT UNSIGNED PRIMARY KEY,
                `story_momentum`        VARCHAR(300) DEFAULT NULL COMMENT '当前故事势能/悬念一句话',
                `current_location`      VARCHAR(200) DEFAULT NULL COMMENT '主角当前位置/场景',
                `location_chapter`      INT UNSIGNED DEFAULT NULL COMMENT '位置所在章节号',
                `location_transition`   VARCHAR(300) DEFAULT NULL COMMENT '到达当前位置的方式描写',
                `current_arc_summary`   TEXT DEFAULT NULL COMMENT '最近一个活跃弧段的摘要',
                `last_ingested_chapter` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近成功记忆化的章节号',
                `graph_start_chapter`   INT UNSIGNED DEFAULT NULL COMMENT '图谱关系起始构建章节号(NULL=未启用)',
                `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说状态表（含场景位置追踪+图谱起始章）'",

            'novel_scene_templates' => "CREATE TABLE IF NOT EXISTS `novel_scene_templates` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`              INT UNSIGNED NOT NULL,
                `chapter_number`        INT UNSIGNED NOT NULL COMMENT '章节号',
                `template_id`           VARCHAR(60) NOT NULL COMMENT '场景模板ID',
                `cool_point_type`       VARCHAR(30) NOT NULL COMMENT '所属爽点类型',
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_novel`         (`novel_id`),
                KEY `idx_novel_tpl`     (`novel_id`, `template_id`),
                KEY `idx_novel_ch`      (`novel_id`, `chapter_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='场景模板使用记录'",

            'memory_atoms' => "CREATE TABLE IF NOT EXISTS `memory_atoms` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`              INT UNSIGNED NOT NULL,
                `atom_type`             ENUM(
                                          'character_trait',
                                          'world_setting',
                                          'plot_detail',
                                          'style_preference',
                                          'constraint',
                                          'technique',
                                          'world_state',
                                          'cool_point'
                                        ) NOT NULL,
                `content`               TEXT NOT NULL,
                `source_chapter`        INT UNSIGNED DEFAULT NULL,
                `confidence`            FLOAT NOT NULL DEFAULT 0.8,
                `metadata`              JSON DEFAULT NULL,
                `embedding`             BLOB DEFAULT NULL COMMENT '向量,float32 packed',
                `embedding_model`       VARCHAR(100) DEFAULT NULL,
                `embedding_updated_at`  TIMESTAMP NULL DEFAULT NULL,
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_novel_type`     (`novel_id`, `atom_type`),
                KEY `idx_novel_chapter`  (`novel_id`, `source_chapter`),
                KEY `idx_embedding_null` (`novel_id`, `embedding_updated_at`),
                FULLTEXT KEY `ft_content` (`content`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='原子记忆表'",

            'novel_characters' => "CREATE TABLE IF NOT EXISTS `novel_characters` (
                `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`            INT UNSIGNED NOT NULL,
                `name`                VARCHAR(100) NOT NULL COMMENT '角色名',
                `alias`               VARCHAR(100) DEFAULT NULL COMMENT '别名/绰号',
                `role_type`           ENUM('protagonist','major','minor','background') NOT NULL DEFAULT 'minor' COMMENT '角色类型',
                `role_template`       VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT '功能模板:mentor/opponent/romantic/brother/protagonist/other',
                `gender`              VARCHAR(20) DEFAULT '' COMMENT '性别',
                `appearance`          TEXT DEFAULT NULL COMMENT '外貌特征',
                `personality`         TEXT DEFAULT NULL COMMENT '性格特点',
                `background`          TEXT DEFAULT NULL COMMENT '背景故事',
                `abilities`           TEXT DEFAULT NULL COMMENT '能力/特长',
                `relationships`       JSON DEFAULT NULL COMMENT '人物关系',
                `first_appear`       INT UNSIGNED DEFAULT NULL COMMENT '首次出场章节',
                `last_appear`        INT UNSIGNED DEFAULT NULL COMMENT '最后出场章节',
                `appear_count`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '出场次数',
                `first_chapter`       INT DEFAULT NULL COMMENT '首次出场章节（界面字段）',
                `climax_chapter`      INT DEFAULT NULL COMMENT '预计高潮/退场章节',
                `notes`               TEXT DEFAULT NULL COMMENT '备注',
                `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel`       (`novel_id`),
                KEY `idx_role_type`   (`novel_id`, `role_type`),
                KEY `idx_template`    (`novel_id`, `role_template`),
                UNIQUE KEY `uk_novel_name` (`novel_id`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色库'",

            'novel_worldbuilding' => "CREATE TABLE IF NOT EXISTS `novel_worldbuilding` (
                `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`            INT UNSIGNED NOT NULL,
                `category`            ENUM('location','faction','rule','item','other') NOT NULL DEFAULT 'other' COMMENT '类别',
                `name`                VARCHAR(200) NOT NULL COMMENT '名称',
                `description`         TEXT DEFAULT NULL COMMENT '描述',
                `attributes`          JSON DEFAULT NULL COMMENT '扩展属性',
                `related_chapters`    JSON DEFAULT NULL COMMENT '相关章节',
                `importance`          TINYINT NOT NULL DEFAULT 3 COMMENT '重要程度1-5',
                `notes`               TEXT DEFAULT NULL COMMENT '备注',
                `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel`       (`novel_id`),
                KEY `idx_category`    (`novel_id`, `category`),
                KEY `idx_importance`  (`novel_id`, `importance`),
                UNIQUE KEY `uk_novel_name_cat` (`novel_id`, `name`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='世界观库'",

            'novel_plots' => "CREATE TABLE IF NOT EXISTS `novel_plots` (
                `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`            INT UNSIGNED NOT NULL,
                `chapter_from`        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '起始章节',
                `chapter_to`          INT UNSIGNED DEFAULT NULL COMMENT '结束章节',
                `event_type`          ENUM('main','subplot','foreshadowing','callback','other') NOT NULL DEFAULT 'main' COMMENT '事件类型',
                `foreshadow_type`     VARCHAR(20) DEFAULT NULL COMMENT '伏笔类型:character/item/speech/faction/realm/identity',
                `expected_payoff`     VARCHAR(200) DEFAULT NULL COMMENT '预期回收方式',
                `deadline_chapter`    INT UNSIGNED DEFAULT NULL COMMENT '建议回收章节',
                `title`               VARCHAR(200) NOT NULL COMMENT '标题',
                `description`         TEXT DEFAULT NULL COMMENT '描述',
                `characters`          JSON DEFAULT NULL COMMENT '涉及角色',
                `status`              ENUM('planted','active','resolving','resolved','abandoned') NOT NULL DEFAULT 'active' COMMENT '状态',
                `importance`          TINYINT NOT NULL DEFAULT 3 COMMENT '重要程度1-5',
                `notes`               TEXT DEFAULT NULL COMMENT '备注',
                `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel`       (`novel_id`),
                KEY `idx_chapter`     (`novel_id`, `chapter_from`, `chapter_to`),
                KEY `idx_event_type`  (`novel_id`, `event_type`),
                KEY `idx_status`      (`novel_id`, `status`),
                UNIQUE KEY `uk_novel_title_type` (`novel_id`, `title`, `event_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='情节库'",

            'novel_style' => "CREATE TABLE IF NOT EXISTS `novel_style` (
                `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`            INT UNSIGNED NOT NULL,
                `category`            ENUM('narrative','dialogue','description','emotion','other') NOT NULL DEFAULT 'other' COMMENT '类别',
                `name`                VARCHAR(100) NOT NULL COMMENT '名称',
                `content`             TEXT DEFAULT NULL COMMENT '详细风格说明',
                `vec_style`           VARCHAR(20) DEFAULT NULL COMMENT '文风:concise/ornate/humorous',
                `vec_pacing`          VARCHAR(20) DEFAULT NULL COMMENT '节奏:fast/slow/alternating',
                `vec_emotion`         VARCHAR(20) DEFAULT NULL COMMENT '情感:passionate/warm/dark',
                `vec_intellect`       VARCHAR(20) DEFAULT NULL COMMENT '智慧:strategy/power/balanced',
                `ref_author`          VARCHAR(50) DEFAULT NULL COMMENT '参考作者',
                `keywords`            TEXT DEFAULT NULL COMMENT '逗号分隔高频词',
                `examples`            JSON DEFAULT NULL COMMENT '示例片段',
                `usage_count`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '使用次数',
                `notes`               TEXT DEFAULT NULL COMMENT '备注',
                `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel`       (`novel_id`),
                KEY `idx_usage`       (`novel_id`, `usage_count`),
                UNIQUE KEY `uk_novel_name_cat` (`novel_id`, `name`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风格库'",

            'novel_embeddings' => "CREATE TABLE IF NOT EXISTS `novel_embeddings` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`              INT UNSIGNED NOT NULL,
                `source_type`           ENUM('character','worldbuilding','plot','style','chapter','other') NOT NULL COMMENT '来源类型',
                `source_id`             INT UNSIGNED NOT NULL COMMENT '来源ID',
                `content`               TEXT DEFAULT NULL COMMENT '原始文本（用于展示）',
                `embedding_blob`        LONGBLOB DEFAULT NULL COMMENT 'float32 向量二进制',
                `embedding_model`       VARCHAR(100) DEFAULT NULL COMMENT '向量模型名',
                `embedding_updated_at`  TIMESTAMP NULL DEFAULT NULL COMMENT '向量更新时间',
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_source`  (`novel_id`, `source_type`, `source_id`),
                KEY `idx_novel_type`    (`novel_id`, `source_type`),
                KEY `idx_embedding_null`(`novel_id`, `embedding_updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='向量存储表'",

            'system_settings' => "CREATE TABLE IF NOT EXISTS `system_settings` (
                `setting_key`   VARCHAR(100) PRIMARY KEY,
                `setting_value` TEXT,
                `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // ========== 约束框架表 ==========
            'constraint_state' => "CREATE TABLE IF NOT EXISTS `constraint_state` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT NOT NULL COMMENT '小说ID',
                `state_type` VARCHAR(32) NOT NULL COMMENT '状态类型: character/plot/information/pacing/style',
                `state_key` VARCHAR(64) NOT NULL COMMENT '状态键: protagonist_power/conflict_history/active_foreshadowing等',
                `state_value` JSON NOT NULL COMMENT '结构化状态数据',
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel_type_key` (`novel_id`, `state_type`, `state_key`),
                INDEX `idx_novel` (`novel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='全局约束状态库'",

            'constraint_logs' => "CREATE TABLE IF NOT EXISTS `constraint_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT NOT NULL COMMENT '小说ID',
                `chapter_id` INT DEFAULT NULL COMMENT '章节ID',
                `chapter_number` INT COMMENT '章节号',
                `check_phase` VARCHAR(16) DEFAULT 'post_write' COMMENT '检查阶段: pre_write/post_write/agent',
                `dimension` VARCHAR(16) NOT NULL COMMENT '约束维度: structure/character/plot/information/pacing/language/world',
                `level` VARCHAR(8) NOT NULL COMMENT '级别: P0/P1/P2',
                `issue_type` VARCHAR(32) NOT NULL COMMENT '问题类型',
                `issue_desc` TEXT COMMENT '问题描述',
                `auto_fixed` TINYINT DEFAULT 0 COMMENT '是否自动修正',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_novel_chapter` (`novel_id`, `chapter_number`),
                INDEX `idx_level` (`level`),
                INDEX `idx_dimension` (`dimension`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='约束校验日志表'",

            // ================================================================
            // 作者画像系统表（v1.7 新增）
            // ================================================================

            'author_profiles' => "CREATE TABLE IF NOT EXISTS `author_profiles` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED DEFAULT NULL COMMENT '关联用户ID',
                `profile_name` VARCHAR(100) NOT NULL COMMENT '画像名称',
                `avatar_url` VARCHAR(500) DEFAULT NULL COMMENT '头像',
                `gender` ENUM('male','female','other') DEFAULT NULL,
                `age_range` VARCHAR(20) DEFAULT NULL,
                `mbti` VARCHAR(10) DEFAULT NULL,
                `constellation` VARCHAR(20) DEFAULT NULL,
                `occupation` VARCHAR(100) DEFAULT NULL,
                `education_bg` TEXT DEFAULT NULL,
                `writing_experience` TEXT DEFAULT NULL,
                `influences` TEXT DEFAULT NULL,
                `writing_habits_prompt` TEXT DEFAULT NULL COMMENT '写作习惯提示词',
                `narrative_style_prompt` TEXT DEFAULT NULL COMMENT '叙事手法提示词',
                `sentiment_prompt` TEXT DEFAULT NULL COMMENT '思想情感提示词',
                `creative_identity_prompt` TEXT DEFAULT NULL COMMENT '创作个性提示词',
                `analysis_status` ENUM('pending','analyzing','completed','failed') DEFAULT 'pending',
                `source_work_id` INT UNSIGNED DEFAULT NULL,
                `is_default` TINYINT(1) DEFAULT 0,
                `usage_count` INT UNSIGNED DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_user` (`user_id`),
                INDEX `idx_status` (`analysis_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='作者画像主表'",

            'author_writing_habits' => "CREATE TABLE IF NOT EXISTS `author_writing_habits` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `profile_id` INT UNSIGNED NOT NULL,
                `vocabulary_preference` JSON DEFAULT NULL,
                `word_complexity` ENUM('simple','moderate','complex') DEFAULT 'moderate',
                `sentence_length_avg` INT DEFAULT 0,
                `paragraph_length_avg` INT DEFAULT 0,
                `sentence_patterns` JSON DEFAULT NULL,
                `use_passive` DECIMAL(3,2) DEFAULT 0,
                `use_dialogue` DECIMAL(3,2) DEFAULT 0,
                `rhetorical_devices` JSON DEFAULT NULL,
                `metaphor_frequency` ENUM('low','medium','high') DEFAULT 'medium',
                `uniqueness_score` DECIMAL(3,2) DEFAULT 0,
                `confidence` DECIMAL(3,2) DEFAULT 0,
                `source_chapter_count` INT DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`profile_id`) REFERENCES `author_profiles`(`id`) ON DELETE CASCADE,
                INDEX `idx_profile` (`profile_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='写作习惯分析表'",

            'author_narrative_styles' => "CREATE TABLE IF NOT EXISTS `author_narrative_styles` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `profile_id` INT UNSIGNED NOT NULL,
                `narrative_pov` ENUM('first_person','second_person','third_limited','third_omniscient','multiple') DEFAULT 'third_limited',
                `pov_switch_frequency` ENUM('never','rare','occasional','frequent') DEFAULT 'rare',
                `pacing_type` ENUM('fast','medium','slow','variable') DEFAULT 'medium',
                `scene_transition_style` VARCHAR(100) DEFAULT NULL,
                `tension_curve` JSON DEFAULT NULL,
                `chapter_structure` ENUM('linear','parallel','alternating','circular') DEFAULT 'linear',
                `arc_pattern` VARCHAR(100) DEFAULT NULL,
                `cliffhanger_usage` DECIMAL(3,2) DEFAULT 0,
                `interior_monologue` DECIMAL(3,2) DEFAULT 0,
                `description_density` ENUM('sparse','moderate','detailed') DEFAULT 'moderate',
                `confidence` DECIMAL(3,2) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`profile_id`) REFERENCES `author_profiles`(`id`) ON DELETE CASCADE,
                INDEX `idx_profile` (`profile_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='叙事手法分析表'",

            'author_sentiment_analysis' => "CREATE TABLE IF NOT EXISTS `author_sentiment_analysis` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `profile_id` INT UNSIGNED NOT NULL,
                `overall_tone` ENUM('optimistic','pessimistic','neutral','bittersweet','dark','uplifting') DEFAULT 'neutral',
                `emotional_range` JSON DEFAULT NULL,
                `emotion_intensity` ENUM('subtle','moderate','intense') DEFAULT 'moderate',
                `depth_level` ENUM('surface','entertaining','thoughtful','philosophical') DEFAULT 'entertaining',
                `thematic_complexity` DECIMAL(3,2) DEFAULT 0,
                `themes` JSON DEFAULT NULL,
                `aesthetic_style` VARCHAR(100) DEFAULT NULL,
                `beauty_description_focus` JSON DEFAULT NULL,
                `violence_level` ENUM('none','mild','moderate','graphic') DEFAULT 'moderate',
                `moral_framework` VARCHAR(200) DEFAULT NULL,
                `values_tendency` JSON DEFAULT NULL,
                `confidence` DECIMAL(3,2) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`profile_id`) REFERENCES `author_profiles`(`id`) ON DELETE CASCADE,
                INDEX `idx_profile` (`profile_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='思想情感分析表'",

            'author_creative_identity' => "CREATE TABLE IF NOT EXISTS `author_creative_identity` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `profile_id` INT UNSIGNED NOT NULL,
                `signature_phrases` JSON DEFAULT NULL,
                `unique_techniques` JSON DEFAULT NULL,
                `trademark_elements` JSON DEFAULT NULL,
                `genre_preferences` JSON DEFAULT NULL,
                `character_archetype_favorites` JSON DEFAULT NULL,
                `plot_preferences` JSON DEFAULT NULL,
                `style_tags` JSON DEFAULT NULL,
                `influence_sources` JSON DEFAULT NULL,
                `writing_voice` TEXT DEFAULT NULL,
                `writing_schedule` VARCHAR(100) DEFAULT NULL,
                `editing_style` ENUM('minimal','moderate','extensive') DEFAULT 'moderate',
                `planning_style` ENUM('pantser','plotter','hybrid') DEFAULT 'hybrid',
                `confidence` DECIMAL(3,2) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`profile_id`) REFERENCES `author_profiles`(`id`) ON DELETE CASCADE,
                INDEX `idx_profile` (`profile_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='创作个性分析表'",

            'author_uploaded_works' => "CREATE TABLE IF NOT EXISTS `author_uploaded_works` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `profile_id` INT UNSIGNED DEFAULT NULL,
                `file_name` VARCHAR(300) NOT NULL,
                `file_path` VARCHAR(500) NOT NULL,
                `file_size` INT UNSIGNED DEFAULT 0,
                `file_type` VARCHAR(20) NOT NULL,
                `upload_status` ENUM('pending','processing','completed','failed') DEFAULT 'pending',
                `chapter_count` INT UNSIGNED DEFAULT 0,
                `total_characters` INT UNSIGNED DEFAULT 0,
                `error_message` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_profile` (`profile_id`),
                INDEX `idx_status` (`upload_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='上传作品记录表'",

            // ========== PID 状态表（工程控制论：积分/微分记忆）==========
            'pid_states' => "CREATE TABLE IF NOT EXISTS `pid_states` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT UNSIGNED NOT NULL,
                `var_name` VARCHAR(50) NOT NULL COMMENT '控制变量名: emotion_score/cool_point_density/word_count_accuracy/quality_score',
                `state_data` JSON NOT NULL COMMENT 'PID内部状态(error_integral/last_error/last_value/sample_count)',
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel_var` (`novel_id`, `var_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PID控制器状态持久化表'",

            // ========== 迭代改进设置表 ==========
            'iterative_settings' => "CREATE TABLE IF NOT EXISTS `iterative_settings` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT UNSIGNED DEFAULT 0 COMMENT '小说ID，0表示全局设置',
                `setting_key` VARCHAR(100) NOT NULL COMMENT '设置键',
                `setting_value` TEXT COMMENT '设置值（JSON格式）',
                `description` VARCHAR(255) COMMENT '设置描述',
                `is_system` TINYINT(1) DEFAULT 0 COMMENT '是否系统设置',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel_key` (`novel_id`, `setting_key`),
                INDEX `idx_setting_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='迭代改进设置表'",

            // ========== 金句/梗调度表 (v1.10.3) ==========
            'novel_catchphrases' => "CREATE TABLE IF NOT EXISTS `novel_catchphrases` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`              INT UNSIGNED NOT NULL,
                `phrase`                VARCHAR(500) NOT NULL COMMENT '金句内容',
                `speaker`               VARCHAR(100) DEFAULT NULL COMMENT '说话角色',
                `first_chapter`         INT UNSIGNED DEFAULT NULL COMMENT '首次出现章节',
                `callback_count`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回调次数',
                `last_callback_chapter` INT UNSIGNED DEFAULT NULL COMMENT '最近回调章节',
                `importance`            ENUM('iconic','normal','minor') NOT NULL DEFAULT 'normal' COMMENT '重要等级',
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_novel`    (`novel_id`),
                KEY `idx_callback` (`novel_id`, `callback_count`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='金句表'",

            'catchphrase_callback_log' => "CREATE TABLE IF NOT EXISTS `catchphrase_callback_log` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `catchphrase_id` INT UNSIGNED NOT NULL COMMENT '金句ID',
                `novel_id` INT UNSIGNED NOT NULL,
                `chapter_number` INT UNSIGNED NOT NULL COMMENT '回调章节',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_catchphrase` (`catchphrase_id`),
                KEY `idx_novel_ch` (`novel_id`, `chapter_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='金句回调日志表（v1.11.5：支持重写回滚）'",

            // ========== 使用统计表 (v1.5) ==========
            'usage_stats' => "CREATE TABLE IF NOT EXISTS `usage_stats` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `stat_date` DATE NOT NULL COMMENT '统计日期',
                `words_added` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '新增字数',
                `chapters_added` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '新增章节数',
                `novels_active` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '活跃小说数',
                `reported_at` DATETIME DEFAULT NULL COMMENT '上报时间',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_stat_date` (`stat_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='使用统计表'",

            // ========== 角色情绪历史表 (v1.11.2) ==========
            'character_emotion_history' => "CREATE TABLE IF NOT EXISTS `character_emotion_history` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT UNSIGNED NOT NULL,
                `character_name` VARCHAR(100) NOT NULL COMMENT '角色名',
                `chapter_number` INT UNSIGNED NOT NULL COMMENT '章节号',
                `emotion_state` ENUM('happy','angry','sad','tense','neutral','fearful','determined','melancholy','excited','confused','hopeful','desperate','calm','anxious','proud') NOT NULL COMMENT '情绪状态',
                `intensity` TINYINT UNSIGNED NOT NULL COMMENT '强度0-100',
                `cause` TEXT DEFAULT NULL COMMENT '导致此情绪的原因',
                `expected_decay_chapters` TINYINT UNSIGNED DEFAULT 3 COMMENT '预期持续章节数',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_novel_chapter` (`novel_id`, `chapter_number`),
                INDEX `idx_character` (`novel_id`, `character_name`),
                INDEX `idx_character_chapter` (`novel_id`, `character_name`, `chapter_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色情绪状态历史'",

            // 没有 SQL 定义的表（由 install.php 等外部脚本管理）仅在此处占位
            // novels, chapters, ai_models, writing_logs, volume_outlines
            // 这些表在 install.php 中已有完整定义

            // ========== 高阶写作向导表 (v1.8) ==========

            'novel_wizard_progress' => "CREATE TABLE IF NOT EXISTS `novel_wizard_progress` (
                `novel_id` INT UNSIGNED PRIMARY KEY,
                `current_stage` VARCHAR(20) NOT NULL DEFAULT 'topic' COMMENT '当前阶段: topic/blueprint/content/launch',
                `completed_stages` JSON DEFAULT NULL COMMENT '已完成阶段列表',
                `metadata` JSON DEFAULT NULL COMMENT '阶段元数据(策划文档/世界观文档等)',
                `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_active` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`novel_id`) REFERENCES `novels`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='高阶向导进度表'",

            'novel_wizard_chats' => "CREATE TABLE IF NOT EXISTS `novel_wizard_chats` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT UNSIGNED NOT NULL,
                `stage` VARCHAR(20) NOT NULL COMMENT '阶段: topic/blueprint/content/launch',
                `role` ENUM('user','assistant','system') NOT NULL,
                `content` MEDIUMTEXT NOT NULL COMMENT '消息内容',
                `model_id` INT UNSIGNED DEFAULT NULL COMMENT '使用的AI模型ID',
                `tokens` INT UNSIGNED DEFAULT 0 COMMENT '消耗token数',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_novel_stage` (`novel_id`, `stage`, `created_at`),
                FOREIGN KEY (`novel_id`) REFERENCES `novels`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='高阶向导对话表'",

            // ========== 导入续写表 (v1.8) ==========

            'novel_import_sessions' => "CREATE TABLE IF NOT EXISTS `novel_import_sessions` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
                `novel_id` INT UNSIGNED DEFAULT NULL COMMENT '关联小说ID',
                `session_key` VARCHAR(64) NOT NULL COMMENT '会话唯一标识',
                `total_batches` INT NOT NULL DEFAULT 0 COMMENT '总批次数',
                `completed_batches` JSON DEFAULT NULL COMMENT '已完成批次列表',
                `total_chapters` INT NOT NULL DEFAULT 0 COMMENT '总章节数',
                `imported_chapters` INT NOT NULL DEFAULT 0 COMMENT '已导入章节数',
                `total_words` BIGINT NOT NULL DEFAULT 0 COMMENT '总字数',
                `status` ENUM('pending','importing','completed','failed') DEFAULT 'pending' COMMENT '导入状态',
                `novel_meta` JSON DEFAULT NULL COMMENT '小说元数据',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_session_key` (`session_key`),
                INDEX `idx_user` (`user_id`),
                INDEX `idx_novel` (`novel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导入续写会话表'",

            'short_stories' => "CREATE TABLE IF NOT EXISTS `short_stories` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `title` VARCHAR(200) NOT NULL DEFAULT '',
                `genre` VARCHAR(100) NOT NULL DEFAULT '',
                `theme` VARCHAR(200) DEFAULT NULL,
                `premise` TEXT DEFAULT NULL,
                `protagonist` VARCHAR(200) DEFAULT NULL,
                `conflict` TEXT DEFAULT NULL,
                `ending_direction` TEXT DEFAULT NULL,
                `style` VARCHAR(100) DEFAULT NULL,
                `target_words` INT UNSIGNED NOT NULL DEFAULT 3000,
                `structure_type` ENUM('six_beat','eight_beat','three_act') NOT NULL DEFAULT 'eight_beat',
                `chapter_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '章节数量,1=单篇模式',
                `brief_json` JSON DEFAULT NULL,
                `beats_json` JSON DEFAULT NULL,
                `chapters_json` JSON DEFAULT NULL COMMENT '章节数组:order/title/synopsis/beat_refs/word_budget/content/status',
                `content` MEDIUMTEXT DEFAULT NULL,
                `quality_score` DECIMAL(4,1) DEFAULT NULL,
                `quality_report` JSON DEFAULT NULL,
                `status` ENUM('draft','brief_ready','beats_ready','written','polished','completed') NOT NULL DEFAULT 'draft',
                `model_id` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_user` (`user_id`),
                KEY `idx_status` (`status`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短篇小说主表'",

            'short_story_versions' => "CREATE TABLE IF NOT EXISTS `short_story_versions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `story_id` INT UNSIGNED NOT NULL,
                `version` INT UNSIGNED NOT NULL DEFAULT 1,
                `title` VARCHAR(200) DEFAULT NULL,
                `content` MEDIUMTEXT DEFAULT NULL,
                `brief_json` JSON DEFAULT NULL,
                `beats_json` JSON DEFAULT NULL,
                `chapters_json` JSON DEFAULT NULL,
                `quality_score` DECIMAL(4,1) DEFAULT NULL,
                `note` VARCHAR(200) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_story_version` (`story_id`, `version`),
                KEY `idx_story_id` (`story_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短篇小说版本历史'",

            // ==================== 热门选题(Hot Topics) ====================
            'hot_novels' => "CREATE TABLE IF NOT EXISTS `hot_novels` (
                `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `source`               ENUM('qidian','fanqie','zongheng','qimao') NOT NULL,
                `title`                VARCHAR(200) NOT NULL,
                `title_norm`           VARCHAR(200) NOT NULL COMMENT '标准化书名(去标点空格小写),用于去重',
                `author`               VARCHAR(100) NOT NULL DEFAULT '',
                `author_norm`          VARCHAR(100) NOT NULL DEFAULT '' COMMENT '标准化作者',
                `raw_category`         VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '平台原始题材',
                `normalized_category`  VARCHAR(40)  DEFAULT NULL COMMENT '归一化题材',
                `channel`              ENUM('male','female','general','unknown') NOT NULL DEFAULT 'unknown',
                `tags`                 JSON DEFAULT NULL,
                `word_count`           BIGINT UNSIGNED DEFAULT NULL,
                `click_count`          BIGINT UNSIGNED DEFAULT NULL,
                `collect_count`        BIGINT UNSIGNED DEFAULT NULL,
                `recommend_count`      BIGINT UNSIGNED DEFAULT NULL,
                `hotness_score`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `rank_no`              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `rank_type`            VARCHAR(60) NOT NULL DEFAULT '' COMMENT '平台原始榜单名',
                `extra_rank_types`     JSON DEFAULT NULL,
                `intro`                TEXT DEFAULT NULL,
                `cover_url`            VARCHAR(500) DEFAULT NULL,
                `source_url`           VARCHAR(500) DEFAULT NULL COMMENT '官方详情页',
                `collected_at`         DATETIME NOT NULL,
                `confidence_score`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `last_batch_id`        VARCHAR(40) DEFAULT NULL,
                `first_seen_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_seen_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_title_author` (`title_norm`, `author_norm`),
                KEY `idx_source` (`source`),
                KEY `idx_channel_cat` (`channel`, `normalized_category`),
                KEY `idx_rank_type` (`rank_type`),
                KEY `idx_hotness` (`hotness_score`),
                KEY `idx_collected` (`collected_at`),
                KEY `idx_last_seen` (`last_seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='热门选题书籍主表'",

            'hot_novel_analysis' => "CREATE TABLE IF NOT EXISTS `hot_novel_analysis` (
                `novel_id`        INT UNSIGNED PRIMARY KEY,
                `appeals`         TEXT DEFAULT NULL COMMENT '爽点',
                `selling_points`  TEXT DEFAULT NULL COMMENT '卖点',
                `tropes`          TEXT DEFAULT NULL COMMENT '套路',
                `audience`        TEXT DEFAULT NULL COMMENT '受众',
                `risks`           TEXT DEFAULT NULL COMMENT '风险',
                `suggestions`     TEXT DEFAULT NULL COMMENT '选题建议',
                `hooks`           TEXT DEFAULT NULL COMMENT '开篇钩子',
                `benchmarks`      JSON DEFAULT NULL COMMENT '对标作品',
                `evidence`        JSON DEFAULT NULL COMMENT '_evidence',
                `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='热门选题爆款分析'",

            'hot_novel_batches' => "CREATE TABLE IF NOT EXISTS `hot_novel_batches` (
                `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `batch_id`            VARCHAR(60) NOT NULL,
                `source`              VARCHAR(20) NOT NULL,
                `pushed_by`           VARCHAR(60) DEFAULT NULL,
                `prepared_items`      INT UNSIGNED NOT NULL DEFAULT 0,
                `submitted_items`     INT UNSIGNED NOT NULL DEFAULT 0,
                `accepted`            INT UNSIGNED NOT NULL DEFAULT 0,
                `updated`             INT UNSIGNED NOT NULL DEFAULT 0,
                `duplicated`          INT UNSIGNED NOT NULL DEFAULT 0,
                `failed`              INT UNSIGNED NOT NULL DEFAULT 0,
                `failed_reasons`      JSON DEFAULT NULL,
                `summary`             JSON DEFAULT NULL,
                `diversity_metrics`   JSON DEFAULT NULL,
                `fetch_failed`        TINYINT(1) NOT NULL DEFAULT 0,
                `client_ip`           VARCHAR(64) DEFAULT NULL,
                `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_batch_id` (`batch_id`),
                KEY `idx_source_created` (`source`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='热门选题推送批次日志'",

            'hot_novel_nonces' => "CREATE TABLE IF NOT EXISTS `hot_novel_nonces` (
                `nonce`       VARCHAR(64) PRIMARY KEY,
                `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='热门选题Nonce防重放(24h清理)'",

            // ==================== v1.7 PRO 圣经节点化 + 图谱关系 ====================

            'bible_nodes' => "CREATE TABLE IF NOT EXISTS `bible_nodes` (
                `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`             INT UNSIGNED NOT NULL COMMENT '小说ID',
                `category`             VARCHAR(50) NOT NULL COMMENT '节点分类: world_rules, factions, characters, timeline, items, magic_system',
                `node_key`             VARCHAR(100) NOT NULL COMMENT '节点唯一标识(拼音/英文)',
                `node_title`           VARCHAR(255) NOT NULL COMMENT '节点展示标题',
                `content_md`           MEDIUMTEXT COMMENT '设定的详细MD文本',
                `is_locked`            TINYINT NOT NULL DEFAULT 0 COMMENT '1=作者锁死,AI不可修改; 0=动态可变',
                `last_updated_chapter` INT UNSIGNED DEFAULT 0 COMMENT '最后被哪一章更新',
                `updated_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `idx_novel_node` (`novel_id`, `category`, `node_key`),
                INDEX `idx_novel_category` (`novel_id`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='圣经节点表(模块化CRUD替代整篇压缩)'",

            'story_relations' => "CREATE TABLE IF NOT EXISTS `story_relations` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`        INT UNSIGNED NOT NULL COMMENT '小说ID',
                `source_entity`   VARCHAR(100) NOT NULL COMMENT '实体A: 如 林枫',
                `relation_type`   VARCHAR(50) NOT NULL COMMENT '关系谓词: owns/killed/allied_with/mentors/enemies/loves',
                `target_entity`   VARCHAR(100) NOT NULL COMMENT '实体B: 如 无双剑',
                `source_chapter`  INT UNSIGNED NOT NULL COMMENT '发生/发现关系的章节号',
                `description`     TEXT COMMENT '关系描述',
                `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `idx_novel_relation` (`novel_id`, `source_entity`, `relation_type`, `target_entity`, `source_chapter`),
                INDEX `idx_source_target` (`novel_id`, `source_entity`, `target_entity`),
                INDEX `idx_target_chapter` (`novel_id`, `target_entity`, `source_chapter`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧情关联图谱表(增量实体关系网络)'",

            // ========== installManaged 表（v1.7 PRO 收敛自 install.php） ==========
            // 原 install.php 中的 7 张表统一搬移到 Schema 单一真理源
            'ai_models' => "CREATE TABLE IF NOT EXISTS `ai_models` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`                  VARCHAR(100)  NOT NULL COMMENT '模型名称',
                `api_url`               VARCHAR(500)  NOT NULL COMMENT 'API地址',
                `api_key`               VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'API密钥',
                `model_name`            VARCHAR(200)  NOT NULL COMMENT '模型标识符',
                `max_tokens`            INT           NOT NULL DEFAULT 4096,
                `temperature`           FLOAT         NOT NULL DEFAULT 0.8,
                `is_default`            TINYINT(1)    NOT NULL DEFAULT 0,
                `embedding_enabled`     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '是否启用Embedding模型',
                `thinking_enabled`      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '是否启用深度思考(Thinking)',
                `can_embed`             TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '此API端点是否可调embedding',
                `embedding_model_name`  VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'embedding模型名',
                `embedding_dim`         INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'embedding向量维度',
                `capabilities`          JSON          DEFAULT NULL COMMENT '模型能力标签(JSON数组:creative/structured/synopsis等)',
                `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'novels' => "CREATE TABLE IF NOT EXISTS `novels` (
                `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id`               INT UNSIGNED DEFAULT NULL COMMENT '关联用户ID',
                `title`                 VARCHAR(200) NOT NULL COMMENT '书名',
                `genre`                 VARCHAR(100) NOT NULL DEFAULT '' COMMENT '类型',
                `writing_style`         VARCHAR(200) NOT NULL DEFAULT '' COMMENT '写作风格',
                `protagonist_name`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '主角姓名',
                `protagonist_info`      TEXT COMMENT '主角信息',
                `plot_settings`         TEXT COMMENT '情节设定',
                `world_settings`        TEXT COMMENT '世界设定',
                `extra_settings`        TEXT COMMENT '其他设定',
                `target_chapters`       INT  NOT NULL DEFAULT 100 COMMENT '目标总章数',
                `chapter_words`         INT  NOT NULL DEFAULT 2000 COMMENT '每章目标字数',
                `model_id`              INT UNSIGNED DEFAULT NULL COMMENT '使用的模型',
                `has_story_outline`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否已生成全书故事大纲',
                `optimized_chapter`     INT  NOT NULL DEFAULT 0 COMMENT '大纲优化进度（最后优化的章节号）',
                `status`                ENUM('draft','writing','paused','completed') NOT NULL DEFAULT 'draft',
                `current_chapter`       INT  NOT NULL DEFAULT 0 COMMENT '已写章数',
                `total_words`           INT  NOT NULL DEFAULT 0 COMMENT '总字数',
                `cancel_flag`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '写作取消标志',
                `daemon_write`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否启用挂机写作',
                `cover_color`           VARCHAR(7)   NOT NULL DEFAULT '#6366f1',
                `cover_image`           VARCHAR(500) DEFAULT NULL COMMENT '封面图片路径',
                `style_vector`          TEXT COMMENT '四维风格向量(JSON)',
                `ref_author`            VARCHAR(200) DEFAULT NULL COMMENT '参考作者',
                `author_profile_id`     INT UNSIGNED DEFAULT NULL COMMENT '绑定的作者画像ID',
                `target_reader`         VARCHAR(30) NOT NULL DEFAULT 'general' COMMENT '目标读者画像(qidian_male/qidian_female/jjwxc/fanqie/physical_book/general)',
                `narrative_structure`   VARCHAR(50) DEFAULT NULL COMMENT '叙事结构',
                `narrative_method`      VARCHAR(50) DEFAULT NULL COMMENT '叙事方法',
                `narrative_pov`         VARCHAR(50) DEFAULT NULL COMMENT '叙事视角',
                `literary_genre`        VARCHAR(100) DEFAULT NULL COMMENT '文学流派',
                `world_setting_era`     VARCHAR(100) DEFAULT NULL COMMENT '世界设定（时代）',
                `novel_types`           JSON DEFAULT NULL COMMENT '小说类型多选',
                `writing_tone`          JSON DEFAULT NULL COMMENT '文风多选',
                `protagonist_traits`    JSON DEFAULT NULL COMMENT '主角设定多选',
                `core_conflicts`       JSON DEFAULT NULL COMMENT '核心冲突多选',
                `appeal_points`         JSON DEFAULT NULL COMMENT '爽点多选',
                `taboos`                JSON DEFAULT NULL COMMENT '禁忌多选',
                `opening_type`          VARCHAR(50) DEFAULT NULL COMMENT '开篇类型',
                `protagonist_entrance`  VARCHAR(50) DEFAULT NULL COMMENT '主角出场',
                `custom_settings`       TEXT DEFAULT NULL COMMENT '自定义设定',
                `chapter_word_target`   INT DEFAULT NULL COMMENT '单章字数目标',
                `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_status`  (`status`),
                KEY `idx_updated` (`updated_at`),
                KEY `idx_user`    (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'novel_settings' => "CREATE TABLE IF NOT EXISTS `novel_settings` (
                `novel_id`      INT UNSIGNED NOT NULL COMMENT '小说ID',
                `setting_key`   VARCHAR(100) NOT NULL COMMENT '配置键',
                `setting_value` TEXT NOT NULL COMMENT '配置值',
                `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`novel_id`, `setting_key`),
                KEY `idx_novel_settings_key` (`setting_key`),
                CONSTRAINT `fk_novel_settings_novel_id`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说级自适应参数配置'",

            'chapters' => "CREATE TABLE IF NOT EXISTS `chapters` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`        INT UNSIGNED NOT NULL,
                `chapter_number`  INT          NOT NULL COMMENT '章节序号',
                `title`           VARCHAR(300) NOT NULL DEFAULT '',
                `outline`         TEXT COMMENT '章节大纲',
                `key_points`      TEXT COMMENT '关键情节点(JSON)',
                `hook`            VARCHAR(500) NOT NULL DEFAULT '' COMMENT '结尾钩子',
                `hook_type`       VARCHAR(30)  DEFAULT NULL COMMENT '钩子六式类型',
                `hook_resolved`   TINYINT(1)   DEFAULT NULL COMMENT '本章是否回收上章钩子(1是/0悬挂/NULL未检测)',
                `cool_point_type` VARCHAR(30)  DEFAULT NULL COMMENT '爽点类型',
                `opening_type`    VARCHAR(30)  DEFAULT NULL COMMENT '开篇五式类型',
                `actual_opening_type` VARCHAR(30) DEFAULT NULL COMMENT '实际检测到的开篇类型',
                `pacing`          VARCHAR(10)  NOT NULL DEFAULT '中' COMMENT '节奏：快/中/慢',
                `suspense`        VARCHAR(10)  NOT NULL DEFAULT '无' COMMENT '悬念：有/无',
                `quality_score`   DECIMAL(3,1) DEFAULT NULL COMMENT '质量评分(0-100)',
                `rewritten`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被RewriteAgent重写过',
                `critic_scores`  JSON DEFAULT NULL COMMENT 'CriticAgent读者视角评分',
                `human_critic_scores` JSON DEFAULT NULL COMMENT '人工读者视角评分(5维)',
                `calibrated_critic_scores` JSON DEFAULT NULL COMMENT '校准后的Critic评分',
                `ai_pattern_issues` JSON DEFAULT NULL COMMENT 'StyleGuard AI痕迹检测结果',
                `iterations_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '迭代改进轮数',
                `total_improvement` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '总质量提升分数',
                `iterative_history` JSON DEFAULT NULL COMMENT '迭代历史详情',
                `iteration_evaluation` JSON DEFAULT NULL COMMENT '迭代效果评估',
                `rewrite_time` DATETIME DEFAULT NULL COMMENT '最后一次重写时间',
                `cognitive_load` JSON DEFAULT NULL COMMENT '认知负荷分析：新元素数量、累计趋势',
                `style_drift_report` JSON DEFAULT NULL COMMENT 'StyleGuard风格漂移检测结果',
                `gate_results`    JSON         DEFAULT NULL COMMENT '五关检测结果',
                `tokens_used`     INT          NOT NULL DEFAULT 0 COMMENT 'AI生成本章消耗的token总数',
                `cache_hit_tokens` INT         NOT NULL DEFAULT 0 COMMENT '本章命中的提示词缓存token数',
                `duration_ms`     INT          NOT NULL DEFAULT 0 COMMENT '本章生成耗时(毫秒)',
                `emotion_density` JSON         DEFAULT NULL COMMENT '情绪词频统计(各类别次/万字)',
                `emotion_score`   DECIMAL(4,1) DEFAULT NULL COMMENT '情绪密度评分(0-100)',
                `actual_cool_point_types` JSON DEFAULT NULL COMMENT '实际检测到的爽点类型(关键词匹配)',
                `synopsis_id`     INT UNSIGNED DEFAULT NULL COMMENT '章节简介ID',
                `content`         LONGTEXT COMMENT '章节正文',
                `words`           INT  NOT NULL DEFAULT 0,
                `status`          ENUM('pending','outlined','writing','completed','skipped','failed') NOT NULL DEFAULT 'pending',
                `chapter_summary` TEXT COMMENT 'AI生成的章节摘要，供续写参考',
                `used_tropes`     TEXT COMMENT '本章已用意象/场景(JSON)，近5章规避',
                `retry_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '写作重试次数',
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel_chapter` (`novel_id`, `chapter_number`),
                KEY `idx_novel_status`  (`novel_id`, `status`),
                KEY `idx_novel_status_chapter` (`novel_id`, `status`, `chapter_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'writing_tasks' => "CREATE TABLE IF NOT EXISTS `writing_tasks` (
                `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `task_id`               VARCHAR(64) NOT NULL COMMENT '对外任务ID',
                `user_id`               INT UNSIGNED NOT NULL COMMENT '任务所有者',
                `novel_id`              INT UNSIGNED NOT NULL,
                `chapter_id`            INT UNSIGNED DEFAULT NULL,
                `state`                 VARCHAR(32) NOT NULL DEFAULT 'queued',
                `progress`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `status_message`        VARCHAR(500) DEFAULT NULL,
                `attempt`               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `lease_owner`           VARCHAR(120) DEFAULT NULL,
                `lease_expires_at`      DATETIME NULL,
                `cancel_requested`      TINYINT(1) NOT NULL DEFAULT 0,
                `content_saved`         TINYINT(1) NOT NULL DEFAULT 0,
                `chapter_revision_hash` CHAR(64) DEFAULT NULL,
                `error_code`             VARCHAR(64) DEFAULT NULL,
                `error_message`          VARCHAR(500) DEFAULT NULL,
                `result_json`            JSON DEFAULT NULL,
                `started_at`             DATETIME NULL,
                `completed_at`           DATETIME NULL,
                `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_writing_task_id` (`task_id`),
                KEY `idx_writing_task_novel_state` (`novel_id`, `state`),
                KEY `idx_writing_task_lease` (`state`, `lease_expires_at`),
                CONSTRAINT `fk_writing_tasks_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_writing_tasks_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='持久写作任务状态'",

            'postprocess_jobs' => "CREATE TABLE IF NOT EXISTS `postprocess_jobs` (
                `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`         INT UNSIGNED NOT NULL,
                `chapter_id`       INT UNSIGNED NOT NULL,
                `revision_hash`    CHAR(64) NOT NULL,
                `stage`            VARCHAR(32) NOT NULL DEFAULT 'full',
                `state`            VARCHAR(24) NOT NULL DEFAULT 'queued',
                `attempt`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts`     SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                `available_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `lease_owner`      VARCHAR(120) DEFAULT NULL,
                `lease_expires_at` DATETIME NULL,
                `last_error`       TEXT DEFAULT NULL,
                `started_at`       DATETIME NULL,
                `completed_at`     DATETIME NULL,
                `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_postprocess_revision` (`chapter_id`, `revision_hash`, `stage`),
                KEY `idx_postprocess_claim` (`state`, `available_at`, `lease_expires_at`),
                KEY `idx_postprocess_novel` (`novel_id`, `state`),
                CONSTRAINT `fk_postprocess_jobs_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_postprocess_jobs_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='可重试章节后处理队列'",

            'chapter_projection_runs' => "CREATE TABLE IF NOT EXISTS `chapter_projection_runs` (
                `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`      INT UNSIGNED NOT NULL,
                `chapter_id`    INT UNSIGNED NOT NULL,
                `revision_hash` CHAR(64) NOT NULL,
                `stage`         VARCHAR(32) NOT NULL DEFAULT 'full',
                `payload_hash`  CHAR(64) DEFAULT NULL,
                `completed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_projection_revision` (`chapter_id`, `revision_hash`, `stage`),
                KEY `idx_projection_novel_chapter` (`novel_id`, `chapter_id`),
                CONSTRAINT `fk_projection_runs_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_projection_runs_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='章节派生投影幂等记录'",

            'writing_logs' => "CREATE TABLE IF NOT EXISTS `writing_logs` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`   INT UNSIGNED NOT NULL,
                `chapter_id` INT UNSIGNED DEFAULT NULL,
                `action`     VARCHAR(100) NOT NULL,
                `message`    TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_novel` (`novel_id`),
                KEY `idx_novel_created` (`novel_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'volume_outlines' => "CREATE TABLE IF NOT EXISTS `volume_outlines` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`        INT UNSIGNED NOT NULL,
                `volume_number`   INT NOT NULL COMMENT '卷号，从1开始',
                `title`           VARCHAR(200) NOT NULL COMMENT '卷标题',
                `summary`         TEXT COMMENT '卷概要（300-500字）',
                `theme`           VARCHAR(200) NOT NULL COMMENT '本卷主题',
                `start_chapter`   INT NOT NULL COMMENT '起始章节号',
                `end_chapter`     INT NOT NULL COMMENT '结束章节号',
                `key_events`      JSON COMMENT '本卷关键事件列表',
                `character_focus` JSON COMMENT '本卷重点描写的人物',
                `conflict`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '本卷核心冲突',
                `resolution`      VARCHAR(500) NOT NULL DEFAULT '' COMMENT '本卷解决方式',
                `foreshadowing`               JSON COMMENT '本卷埋下的伏笔',
                `volume_goals`                JSON COMMENT '本卷写作目标：主矛盾/人物弧/势力变化/需完成事项',
                `must_resolve_foreshadowing`  JSON COMMENT '本卷必须回收的伏笔描述列表（强制执行）',
                `status`          ENUM('pending','generated','revised') NOT NULL DEFAULT 'pending',
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_volume` (`novel_id`, `volume_number`),
                INDEX `idx_novel_volume` (`novel_id`, `volume_number`),
                INDEX `idx_chapter_range` (`start_chapter`, `end_chapter`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'novel_bible' => "CREATE TABLE IF NOT EXISTS `novel_bible` (
                `novel_id` INT UNSIGNED PRIMARY KEY,
                `world_md` MEDIUMTEXT COMMENT '世界规则',
                `character_md` MEDIUMTEXT COMMENT '人物现状',
                `timeline_md` MEDIUMTEXT COMMENT '主线时间线',
                `updated_chapter` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后更新到的章节',
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='全书圣经'",

            'novel_audits' => "CREATE TABLE IF NOT EXISTS `novel_audits` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT UNSIGNED NOT NULL,
                `chapter_number` INT UNSIGNED NOT NULL COMMENT '体检时的章节号',
                `score` DECIMAL(3,1) DEFAULT NULL COMMENT '总体评分0-10',
                `verdict` VARCHAR(60) DEFAULT NULL COMMENT '结论',
                `report` MEDIUMTEXT COMMENT 'markdown 报告',
                `issues` JSON DEFAULT NULL COMMENT '结构化问题清单',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_novel` (`novel_id`, `chapter_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='全书一致性体检'",

            // ========== 漫剧工作流（Drama Studio v1.8） ==========
            'drama_projects' => "CREATE TABLE IF NOT EXISTS `drama_projects` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`        INT UNSIGNED NOT NULL COMMENT '绑定小说',
                `user_id`         INT UNSIGNED DEFAULT NULL COMMENT '归属用户',
                `title`           VARCHAR(200) NOT NULL DEFAULT '' COMMENT '项目名',
                `style_prompt`    TEXT COMMENT '风格定调正向提示词',
                `style_negative`  TEXT COMMENT '负向提示词',
                `image_size`      VARCHAR(20) NOT NULL DEFAULT '1280x720' COMMENT '分镜图尺寸',
                `status`          ENUM('draft','assets','storyboard','imaging','video','composing','completed') NOT NULL DEFAULT 'draft' COMMENT '阶段状态',
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel` (`novel_id`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='漫剧项目表'",

            'drama_episodes' => "CREATE TABLE IF NOT EXISTS `drama_episodes` (
                `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `project_id`       INT UNSIGNED NOT NULL,
                `episode_no`       INT UNSIGNED NOT NULL COMMENT '集号',
                `chapter_start`    INT UNSIGNED NOT NULL COMMENT '起始章节号',
                `chapter_end`      INT UNSIGNED NOT NULL COMMENT '结束章节号',
                `title`            VARCHAR(200) NOT NULL DEFAULT '' COMMENT '集标题',
                `source_text`      MEDIUMTEXT COMMENT '章节正文快照',
                `script_status`    ENUM('pending','parsed','storyboarded') NOT NULL DEFAULT 'pending',
                `final_video_path` VARCHAR(500) DEFAULT NULL COMMENT '成片路径',
                `status`           ENUM('pending','in_progress','completed','failed') NOT NULL DEFAULT 'pending',
                `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_project_episode` (`project_id`, `episode_no`),
                KEY `idx_project` (`project_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='漫剧剧集表'",

            'drama_assets' => "CREATE TABLE IF NOT EXISTS `drama_assets` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `project_id`      INT UNSIGNED NOT NULL,
                `type`            ENUM('character','scene','prop') NOT NULL COMMENT '资产类型',
                `name`            VARCHAR(100) NOT NULL,
                `description`     TEXT COMMENT '外观/环境描述(prompt profile块)',
                `ref_image_path`  VARCHAR(500) DEFAULT NULL COMMENT '定妆照/参考图',
                `source`          ENUM('llm','manual','character_card') NOT NULL DEFAULT 'llm' COMMENT '来源',
                `extra`           JSON DEFAULT NULL COMMENT '扩展(三视图/音色ID预留)',
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_project_type_name` (`project_id`, `type`, `name`),
                KEY `idx_project` (`project_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='漫剧资产库(一致性锚点)'",

            'drama_shots' => "CREATE TABLE IF NOT EXISTS `drama_shots` (
                `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `episode_id`       INT UNSIGNED NOT NULL,
                `shot_no`          INT UNSIGNED NOT NULL COMMENT '镜号',
                `scene_desc`       TEXT COMMENT '画面描述',
                `shot_type`        VARCHAR(20) NOT NULL DEFAULT '中景' COMMENT '景别',
                `camera_movement`  VARCHAR(20) NOT NULL DEFAULT '固定' COMMENT '运镜',
                `characters`       JSON DEFAULT NULL COMMENT '出场角色asset_id列表',
                `dialogue`         TEXT COMMENT '对白/旁白(v1仅记录,音频二期使用)',
                `image_prompt`     TEXT COMMENT '图prompt',
                `video_prompt`     TEXT COMMENT '视频prompt(运动描述)',
                `duration`         TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '视频时长(秒)',
                `image_path`       VARCHAR(500) DEFAULT NULL COMMENT '选定分镜图',
                `image_candidates` JSON DEFAULT NULL COMMENT '抽卡候选路径列表',
                `video_path`       VARCHAR(500) DEFAULT NULL COMMENT '视频片段路径',
                `status`           ENUM('pending','image_ready','video_running','video_done','failed') NOT NULL DEFAULT 'pending',
                `error_msg`        VARCHAR(500) DEFAULT NULL,
                `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_episode_shot` (`episode_id`, `shot_no`),
                KEY `idx_episode` (`episode_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='漫剧分镜表'",

            'drama_tasks' => "CREATE TABLE IF NOT EXISTS `drama_tasks` (
                `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `project_id`  INT UNSIGNED NOT NULL,
                `episode_id`  INT UNSIGNED DEFAULT NULL,
                `type`        ENUM('parse_script','gen_storyboard','gen_asset','gen_shot_image','gen_shot_video','compose_episode') NOT NULL COMMENT '任务类型',
                `ref_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联实体(shot_id/asset_id)',
                `payload`     JSON DEFAULT NULL COMMENT '任务参数',
                `status`      ENUM('pending','running','done','failed','canceled') NOT NULL DEFAULT 'pending',
                `progress`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `result`      JSON DEFAULT NULL COMMENT '结果(task_id/产出路径)',
                `error`       TEXT COMMENT '错误信息',
                `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
                `run_after`   DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '下次可执行时间',
                `lease_owner` VARCHAR(120) DEFAULT NULL COMMENT 'worker租约持有者',
                `lease_expires_at` DATETIME DEFAULT NULL,
                `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_claim` (`status`, `run_after`),
                KEY `idx_project` (`project_id`),
                KEY `idx_episode` (`episode_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='漫剧异步任务队列'",
        ];
    }

    /**
     * 返回 ALLOWED_TABLES 白名单（自动从 tables() 的键派生）。
     * v1.7 PRO：7 张 installManaged 表已合并入 tables()，无需占位数组。
     */
    public static function whitelist(): array
    {
        return array_keys(self::tables());
    }

    /**
     * 按 CREATE TABLE 中的内联 FOREIGN KEY 依赖稳定排序。
     *
     * Schema::tables() 主要按功能模块组织，不保证父表先出现。例如向导表在
     * novels 之前定义，却内联引用 novels.id；空库安装按数组顺序执行会在
     * MySQL 上报 errno 150。这里仅调整执行顺序，不改变 whitelist 或表定义。
     *
     * @return array<string,string> table => CREATE TABLE SQL
     */
    public static function tablesInDependencyOrder(): array
    {
        $tables = self::tables();
        $state = [];
        $ordered = [];

        $visit = function (string $name) use (&$visit, &$state, &$ordered, $tables): void {
            $status = $state[$name] ?? 0;
            if ($status === 2) {
                return;
            }
            if ($status === 1) {
                throw new \LogicException("Circular schema dependency involving {$name}");
            }

            $state[$name] = 1;
            preg_match_all('/\bREFERENCES\s+`?([a-zA-Z0-9_]+)`?/i', $tables[$name], $matches);
            foreach (array_unique($matches[1] ?? []) as $dependency) {
                if (isset($tables[$dependency])) {
                    $visit($dependency);
                }
            }

            $state[$name] = 2;
            $ordered[$name] = $tables[$name];
        };

        foreach (array_keys($tables) as $name) {
            $visit($name);
        }

        return $ordered;
    }

    /**
     * 全量建表（幂等：CREATE TABLE IF NOT EXISTS）
     * install.php 和 db.php migrate() 统一调用此方法。
     */
    public static function applyAll(PDO $pdo): void
    {
        foreach (self::tablesInDependencyOrder() as $name => $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // 复用 SchemaMigrator::isIgnorableError 统一容错策略
                // 覆盖：表已存在(1050/42S01) / 列已存在(1060) / 索引已存在(1061)
                if (SchemaMigrator::isIgnorableError($e)) {
                    continue;
                }
                throw new \RuntimeException(
                    "Schema migration failed for table {$name}: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * 检查当前数据库 schema 是否完整
     * @return array{ok: bool, missing: string[]}
     */
    public static function verify(PDO $pdo): array
    {
        $schemaTables = self::tables();
        $actualTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $missing = [];
        foreach (array_keys($schemaTables) as $table) {
            if (!in_array($table, $actualTables, true)) {
                $missing[] = $table;
            }
        }

        return ['ok' => empty($missing), 'missing' => $missing];
    }
}
