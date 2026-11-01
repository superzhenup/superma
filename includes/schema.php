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
                `user_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属用户ID（0=待认领的旧记录）',
                `title`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '书名',
                `author`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '作者',
                `genre`       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '类型',
                `content`     MEDIUMTEXT NOT NULL COMMENT '分析结果(Markdown)',
                `source_text` MEDIUMTEXT DEFAULT NULL COMMENT '原始章节文本',
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_created` (`created_at`),
                INDEX `idx_user` (`user_id`)
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

            // 审计功能（2026-08-16 P3-卷级摘要）：L2 远期再压缩层存储。
            // 唯一键 (novel_id, volume_number) + 写入按 volume_number/chapter_from+to
            // 双口径 DELETE→INSERT（吸取 arc_summaries 唯一键与查重口径不一致的教训）。
            'volume_summaries' => "CREATE TABLE IF NOT EXISTS `volume_summaries` (
                `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`       INT UNSIGNED NOT NULL,
                `volume_number`  INT UNSIGNED NOT NULL COMMENT '卷号从1起；无卷书为50章回退分组号',
                `chapter_from`   INT UNSIGNED NOT NULL COMMENT '起始章节',
                `chapter_to`     INT UNSIGNED NOT NULL COMMENT '结束章节',
                `summary`        TEXT COMMENT '350-450字卷级故事线摘要',
                `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel_volume` (`novel_id`, `volume_number`),
                KEY `idx_novel_chto` (`novel_id`, `chapter_to`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='卷级摘要表'",

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
                FULLTEXT KEY `ft_description` (`description`) WITH PARSER ngram
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
                FULLTEXT KEY `ft_content` (`content`) WITH PARSER ngram
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
                `category`            ENUM('location','faction','rule','item','golden_finger','other') NOT NULL DEFAULT 'other' COMMENT '类别',
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
                `claim_token`     VARCHAR(40) NOT NULL DEFAULT '' COMMENT '章节认领令牌(审计P0:防取消→重启竞态覆盖正文)',
                `canonical_state` VARCHAR(16) NOT NULL DEFAULT 'none' COMMENT 'none|legacy|canonical',
                `content_revision_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `canonicalized_at` DATETIME(6) DEFAULT NULL,
                `write_policy_snapshot_json` JSON DEFAULT NULL,
                `canonical_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_novel_chapter` (`novel_id`, `chapter_number`),
                UNIQUE KEY `uk_chapters_canonical_candidate` (`canonical_candidate_uid`),
                KEY `idx_novel_status`  (`novel_id`, `status`),
                KEY `idx_novel_status_chapter` (`novel_id`, `status`, `chapter_number`),
                KEY `idx_chapters_canonical_status` (`novel_id`, `canonical_state`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'chapter_candidates' => "CREATE TABLE IF NOT EXISTS `chapter_candidates` (
                `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `candidate_uid`        CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `user_id`              INT UNSIGNED NOT NULL,
                `novel_id`             INT UNSIGNED NOT NULL,
                `chapter_id`           INT UNSIGNED NOT NULL,
                `run_id`               BIGINT UNSIGNED DEFAULT NULL,
                `source`               VARCHAR(16) NOT NULL COMMENT 'initial|rewrite',
                `source_run_uid`       CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `source_task_id`       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `parent_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `target_branch_uid`    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `base_revision_uid`    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `merge_source_revision_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `base_revision_hash`   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `base_snapshot_json`   JSON DEFAULT NULL,
                `draft_artifact_id`    BIGINT UNSIGNED DEFAULT NULL,
                `inline_content`       LONGTEXT NOT NULL,
                `content_hash`         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `words`                INT UNSIGNED NOT NULL DEFAULT 0,
                `lifecycle`            VARCHAR(24) NOT NULL DEFAULT 'draft' COMMENT 'M63: draft|quarantined|approved|committed|rejected|stale; legacy: evaluated|superseded',
                `gate_state`           VARCHAR(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|passed|quarantined|error',
                `validation_artifact_id` BIGINT UNSIGNED DEFAULT NULL,
                `validation_json`      JSON DEFAULT NULL,
                `quarantine_reason`    TEXT NULL,
                `policy_snapshot_json` JSON DEFAULT NULL,
                `policy_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `evaluated_at`         DATETIME(6) DEFAULT NULL,
                `quarantined_at`       DATETIME(6) DEFAULT NULL,
                `approved_at`          DATETIME(6) DEFAULT NULL,
                `rejected_at`          DATETIME(6) DEFAULT NULL,
                `stale_at`             DATETIME(6) DEFAULT NULL,
                `committed_at`         DATETIME(6) DEFAULT NULL,
                `created_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_candidate_uid` (`candidate_uid`),
                UNIQUE KEY `uk_candidate_run_content` (`run_id`, `content_hash`),
                KEY `idx_candidate_chapter_content` (`chapter_id`, `content_hash`),
                KEY `idx_candidate_chapter_lifecycle` (`chapter_id`, `lifecycle`, `created_at`),
                KEY `idx_candidate_novel_lifecycle` (`novel_id`, `lifecycle`, `created_at`),
                KEY `idx_candidate_source_task` (`source_task_id`),
                KEY `idx_candidate_source_run` (`source_run_uid`),
                KEY `idx_candidate_parent` (`parent_candidate_uid`),
                KEY `idx_candidate_target_branch` (`target_branch_uid`, `chapter_id`, `created_at`),
                KEY `idx_candidate_merge_source` (`merge_source_revision_uid`),
                KEY `idx_candidate_draft_artifact` (`draft_artifact_id`),
                KEY `idx_candidate_validation_artifact` (`validation_artifact_id`),
                CONSTRAINT `fk_chapter_candidates_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_candidates_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_candidates_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_chapter_candidates_draft_artifact`
                    FOREIGN KEY (`draft_artifact_id`) REFERENCES `generation_artifacts` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_chapter_candidates_validation_artifact`
                    FOREIGN KEY (`validation_artifact_id`) REFERENCES `generation_artifacts` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pre-canon chapter candidates'",

            'approval_requests' => "CREATE TABLE IF NOT EXISTS `approval_requests` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `approval_uid`        CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `user_id`             INT UNSIGNED NOT NULL,
                `novel_id`            INT UNSIGNED NOT NULL,
                `chapter_id`          INT UNSIGNED NOT NULL,
                `run_id`              BIGINT UNSIGNED DEFAULT NULL,
                `candidate_id`        BIGINT UNSIGNED NOT NULL,
                `idempotency_key`     VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `action_type`         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `risk_level`          VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `state`               VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open' COMMENT 'open|approved|rejected|stale|canceled',
                `base_revision_hash`  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `policy_snapshot_json` JSON NOT NULL,
                `policy_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `summary_artifact_id` BIGINT UNSIGNED DEFAULT NULL,
                `requested_by`        VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `decided_by`          VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `decision_reason`     VARCHAR(500) DEFAULT NULL,
                `decision_idempotency_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `decision_payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `expires_at`          DATETIME(6) DEFAULT NULL,
                `created_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `decided_at`          DATETIME(6) DEFAULT NULL,
                `updated_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                `open_slot`           TINYINT(1) GENERATED ALWAYS AS (IF(`state`='open', 1, NULL)) STORED,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_approval_uid` (`approval_uid`),
                UNIQUE KEY `uk_approval_user_idempotency` (`user_id`, `idempotency_key`),
                UNIQUE KEY `uk_approval_user_decision_idempotency` (`user_id`, `decision_idempotency_key`),
                UNIQUE KEY `uk_approval_candidate_open` (`candidate_id`, `action_type`, `open_slot`),
                KEY `idx_approval_candidate_state` (`candidate_id`, `state`, `created_at`),
                KEY `idx_approval_run_state` (`run_id`, `state`, `created_at`),
                KEY `idx_approval_expires` (`state`, `expires_at`),
                KEY `idx_approval_summary_artifact` (`summary_artifact_id`),
                CONSTRAINT `fk_approval_requests_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_approval_requests_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_approval_requests_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_approval_requests_candidate`
                    FOREIGN KEY (`candidate_id`) REFERENCES `chapter_candidates` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_approval_requests_summary_artifact`
                    FOREIGN KEY (`summary_artifact_id`) REFERENCES `generation_artifacts` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Durable candidate approval decisions'",

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
                `lease_token`           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `fence_generation`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `cancel_requested`      TINYINT(1) NOT NULL DEFAULT 0,
                `content_saved`         TINYINT(1) NOT NULL DEFAULT 0,
                `chapter_revision_hash` CHAR(64) DEFAULT NULL,
                `policy_snapshot_json`  JSON DEFAULT NULL,
                `base_chapter_snapshot_json` JSON DEFAULT NULL,
                `policy_snapshot_hash`  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `accepted_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
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
                KEY `idx_writing_task_fence` (`task_id`, `lease_token`, `fence_generation`),
                CONSTRAINT `fk_writing_tasks_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_writing_tasks_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='持久写作任务状态'",

            'postprocess_jobs' => "CREATE TABLE IF NOT EXISTS `postprocess_jobs` (
                `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `job_uid`          CHAR(32) CHARACTER SET ascii COLLATE ascii_bin
                    GENERATED ALWAYS AS (LOWER(LEFT(SHA2(CONCAT(
                        'postprocess_job_v2:',
                        IF(`accepted_candidate_uid` IS NULL,
                           CONCAT('legacy:', `chapter_id`, ':', `revision_hash`),
                           CONCAT('candidate:', `accepted_candidate_uid`)),
                        ':', `stage`
                    ), 256), 32))) STORED COMMENT 'postprocess_job_v2',
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
                `lease_token`      CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `fence_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `policy_snapshot_json` JSON DEFAULT NULL,
                `policy_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `accepted_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `rewrite_allowed`  TINYINT(1) NOT NULL DEFAULT 0,
                `last_error`       TEXT DEFAULT NULL,
                `started_at`       DATETIME NULL,
                `completed_at`     DATETIME NULL,
                `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_postprocess_job_uid` (`job_uid`),
                KEY `idx_postprocess_revision` (`chapter_id`, `revision_hash`, `stage`),
                KEY `idx_postprocess_claim` (`state`, `available_at`, `lease_expires_at`),
                KEY `idx_postprocess_novel` (`novel_id`, `state`),
                KEY `idx_postprocess_fence` (`id`, `lease_token`, `fence_generation`),
                CONSTRAINT `fk_postprocess_jobs_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_postprocess_jobs_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='可重试章节后处理队列'",

            'postprocess_subtasks' => "CREATE TABLE IF NOT EXISTS `postprocess_subtasks` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `task_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `parent_job_id` BIGINT UNSIGNED NOT NULL,
                `parent_job_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `novel_id` INT UNSIGNED NOT NULL,
                `chapter_id` INT UNSIGNED NOT NULL,
                `revision_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `accepted_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `parent_lease_owner` VARCHAR(120) NOT NULL,
                `parent_lease_token` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `parent_fence_generation` BIGINT UNSIGNED NOT NULL,
                `task_type` VARCHAR(32) NOT NULL,
                `task_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `input_json` JSON NOT NULL,
                `input_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `state` VARCHAR(24) NOT NULL DEFAULT 'queued',
                `attempt` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `lease_owner` VARCHAR(120) DEFAULT NULL,
                `lease_token` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `fence_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `lease_expires_at` DATETIME NULL,
                `result_json` JSON DEFAULT NULL,
                `result_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `last_error` TEXT DEFAULT NULL,
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `started_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                `applied_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_postprocess_subtask_uid` (`task_uid`),
                KEY `idx_postprocess_subtask_parent` (`parent_job_id`,`task_type`),
                KEY `idx_postprocess_subtask_claim` (`state`,`available_at`,`lease_expires_at`),
                KEY `idx_postprocess_subtask_recent` (`updated_at`,`state`),
                CONSTRAINT `fk_postprocess_subtask_parent`
                    FOREIGN KEY (`parent_job_id`) REFERENCES `postprocess_jobs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frozen pure postprocessing computations'",

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

            // ========== Harness M62：Run / Step / Attempt / Event / Artifact ==========
            'generation_runs' => "CREATE TABLE IF NOT EXISTS `generation_runs` (
                `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `run_uid`               CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `user_id`               INT UNSIGNED NOT NULL,
                `novel_id`              INT UNSIGNED NOT NULL,
                `chapter_id`            INT UNSIGNED DEFAULT NULL,
                `queue`                 VARCHAR(32) NOT NULL DEFAULT 'writing',
                `run_type`              VARCHAR(64) NOT NULL,
                `workflow_name`         VARCHAR(64) NOT NULL,
                `workflow_version`      VARCHAR(32) NOT NULL,
                `authority_mode`        VARCHAR(16) NOT NULL DEFAULT 'shadow',
                `state`                 VARCHAR(24) NOT NULL DEFAULT 'queued',
                `current_step_key`      VARCHAR(64) DEFAULT NULL,
                `progress`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `parent_run_id`         BIGINT UNSIGNED DEFAULT NULL,
                `root_run_id`           BIGINT UNSIGNED DEFAULT NULL,
                `idempotency_key`       VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `active_lock_key`       VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `attempt`               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts`          SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                `available_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `next_event_no`         BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `event_stream_version`  INT UNSIGNED NOT NULL DEFAULT 1,
                `input_hash`            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `prompt_manifest_hash`  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `context_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `cancel_requested`      TINYINT(1) NOT NULL DEFAULT 0,
                `deadline_at`           DATETIME(6) DEFAULT NULL,
                `lease_owner`           VARCHAR(120) DEFAULT NULL,
                `lease_token`           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `fence_generation`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `lease_expires_at`      DATETIME(6) DEFAULT NULL,
                `token_budget`          BIGINT UNSIGNED DEFAULT NULL,
                `cost_budget`           DECIMAL(14,6) DEFAULT NULL,
                `input_tokens`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `output_tokens`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `cost_total`            DECIMAL(14,6) NOT NULL DEFAULT 0,
                `flag_snapshot_json`    JSON DEFAULT NULL,
                `goal_json`             JSON DEFAULT NULL,
                `result_json`           JSON DEFAULT NULL,
                `error_code`            VARCHAR(64) DEFAULT NULL,
                `error_message`         VARCHAR(500) DEFAULT NULL,
                `started_at`            DATETIME(6) DEFAULT NULL,
                `completed_at`          DATETIME(6) DEFAULT NULL,
                `created_at`            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY `uk_generation_run_uid` (`run_uid`),
                UNIQUE KEY `uk_generation_run_idempotency` (`user_id`, `idempotency_key`),
                UNIQUE KEY `uk_generation_run_active` (`novel_id`, `active_lock_key`),
                KEY `idx_generation_run_claim` (`queue`, `state`, `available_at`, `lease_expires_at`),
                KEY `idx_generation_run_chapter` (`chapter_id`, `created_at`),
                KEY `idx_generation_run_parent` (`parent_run_id`),
                KEY `idx_generation_run_root` (`root_run_id`),
                KEY `idx_generation_run_fence` (`id`, `lease_token`, `fence_generation`),
                CONSTRAINT `fk_generation_runs_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_generation_runs_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_generation_runs_parent`
                    FOREIGN KEY (`parent_run_id`) REFERENCES `generation_runs` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_generation_runs_root`
                    FOREIGN KEY (`root_run_id`) REFERENCES `generation_runs` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Harness durable generation runs'",

            'generation_steps' => "CREATE TABLE IF NOT EXISTS `generation_steps` (
                `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `run_id`             BIGINT UNSIGNED NOT NULL,
                `step_key`           VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `iteration_no`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `ordinal`            SMALLINT UNSIGNED NOT NULL,
                `step_type`          VARCHAR(64) NOT NULL,
                `state`              VARCHAR(24) NOT NULL DEFAULT 'pending',
                `progress`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `input_hash`         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `output_hash`        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `output_artifact_id` BIGINT UNSIGNED DEFAULT NULL,
                `attempt`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts`       SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                `retry_at`           DATETIME(6) DEFAULT NULL,
                `lease_token`        CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `fence_generation`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `lease_expires_at`   DATETIME(6) DEFAULT NULL,
                `policy_json`        JSON DEFAULT NULL,
                `error_code`         VARCHAR(64) DEFAULT NULL,
                `error_message`      VARCHAR(500) DEFAULT NULL,
                `started_at`         DATETIME(6) DEFAULT NULL,
                `completed_at`       DATETIME(6) DEFAULT NULL,
                `created_at`         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY `uk_generation_step_identity` (`run_id`, `step_key`, `iteration_no`),
                KEY `idx_generation_step_state` (`run_id`, `state`, `ordinal`),
                KEY `idx_generation_step_output_artifact` (`output_artifact_id`),
                KEY `idx_generation_step_fence` (`id`, `lease_token`, `fence_generation`),
                CONSTRAINT `fk_generation_steps_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Harness workflow steps'",

            'generation_attempts' => "CREATE TABLE IF NOT EXISTS `generation_attempts` (
                `id`                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `step_id`             BIGINT UNSIGNED NOT NULL,
                `attempt_no`          SMALLINT UNSIGNED NOT NULL,
                `state`               VARCHAR(24) NOT NULL DEFAULT 'running',
                `provider`            VARCHAR(64) DEFAULT NULL,
                `model`               VARCHAR(191) DEFAULT NULL,
                `model_config_json`   JSON DEFAULT NULL,
                `provider_request_id` VARCHAR(191) DEFAULT NULL,
                `input_artifact_id`   BIGINT UNSIGNED DEFAULT NULL,
                `output_artifact_id`  BIGINT UNSIGNED DEFAULT NULL,
                `input_tokens`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `output_tokens`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `cache_tokens`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `cost`                DECIMAL(14,6) NOT NULL DEFAULT 0,
                `latency_ms`          BIGINT UNSIGNED DEFAULT NULL,
                `finish_reason`       VARCHAR(64) DEFAULT NULL,
                `error_code`          VARCHAR(64) DEFAULT NULL,
                `error_message`       VARCHAR(500) DEFAULT NULL,
                `started_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `completed_at`        DATETIME(6) DEFAULT NULL,
                UNIQUE KEY `uk_generation_attempt_identity` (`step_id`, `attempt_no`),
                KEY `idx_generation_attempt_input_artifact` (`input_artifact_id`),
                KEY `idx_generation_attempt_output_artifact` (`output_artifact_id`),
                CONSTRAINT `fk_generation_attempts_step`
                    FOREIGN KEY (`step_id`) REFERENCES `generation_steps` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Harness model attempts'",

            'generation_events' => "CREATE TABLE IF NOT EXISTS `generation_events` (
                `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `run_id`             BIGINT UNSIGNED NOT NULL,
                `step_id`            BIGINT UNSIGNED DEFAULT NULL,
                `attempt_id`         BIGINT UNSIGNED DEFAULT NULL,
                `event_no`           BIGINT UNSIGNED NOT NULL,
                `event_type`         VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `event_version`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `severity`           VARCHAR(16) NOT NULL DEFAULT 'info',
                `event_key`          VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `actor_type`         VARCHAR(32) DEFAULT NULL,
                `actor_id`           VARCHAR(191) DEFAULT NULL,
                `causation_event_id` BIGINT UNSIGNED DEFAULT NULL,
                `client_visible`     TINYINT(1) NOT NULL DEFAULT 1,
                `payload_json`       JSON DEFAULT NULL,
                `payload_hash`       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at`         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY `uk_generation_event_sequence` (`run_id`, `event_no`),
                UNIQUE KEY `uk_generation_event_key` (`run_id`, `event_key`),
                KEY `idx_generation_event_cursor` (`run_id`, `id`),
                KEY `idx_generation_event_step` (`step_id`, `id`),
                KEY `idx_generation_event_attempt` (`attempt_id`, `id`),
                KEY `idx_generation_event_causation` (`causation_event_id`),
                CONSTRAINT `fk_generation_events_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_generation_events_step`
                    FOREIGN KEY (`step_id`) REFERENCES `generation_steps` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_generation_events_attempt`
                    FOREIGN KEY (`attempt_id`) REFERENCES `generation_attempts` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only Harness semantic events'",

            'generation_event_id_allocator' => "CREATE TABLE IF NOT EXISTS `generation_event_id_allocator` (
                `allocator_id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                `next_id` BIGINT UNSIGNED NOT NULL,
                `updated_at` DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'generation_event_segments' => "CREATE TABLE IF NOT EXISTS `generation_event_segments` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `run_id` BIGINT UNSIGNED NOT NULL,
                `segment_no` INT UNSIGNED NOT NULL,
                `first_event_no` BIGINT UNSIGNED NOT NULL,
                `last_event_no` BIGINT UNSIGNED NOT NULL,
                `event_count` INT UNSIGNED NOT NULL,
                `storage_uri` VARCHAR(1000) NOT NULL,
                `content_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `size_bytes` BIGINT UNSIGNED NOT NULL,
                `format_version` INT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` DATETIME(6) NOT NULL,
                UNIQUE KEY `uk_generation_event_segment_number` (`run_id`, `segment_no`),
                UNIQUE KEY `uk_generation_event_segment_first` (`run_id`, `first_event_no`),
                CONSTRAINT `fk_generation_event_segments_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'generation_event_checkpoints' => "CREATE TABLE IF NOT EXISTS `generation_event_checkpoints` (
                `run_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                `projector_version` INT UNSIGNED NOT NULL,
                `contract_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `event_cursor` BIGINT UNSIGNED NOT NULL,
                `stream_version` INT UNSIGNED NOT NULL,
                `stream_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `state_json` JSON NOT NULL,
                `state_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at` DATETIME(6) NOT NULL,
                CONSTRAINT `fk_generation_event_checkpoints_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'generation_poll_watermarks' => "CREATE TABLE IF NOT EXISTS `generation_poll_watermarks` (
                `run_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                `fence_generation` BIGINT UNSIGNED NOT NULL,
                `artifact_revision` INT UNSIGNED NOT NULL DEFAULT 0,
                `event_cursor` BIGINT UNSIGNED NOT NULL,
                `progress` TINYINT UNSIGNED NOT NULL,
                `content_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `updated_at` DATETIME(6) NOT NULL,
                CONSTRAINT `fk_generation_poll_watermarks_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'generation_side_effect_operations' => "CREATE TABLE IF NOT EXISTS `generation_side_effect_operations` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `operation_uid` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `run_id` BIGINT UNSIGNED NOT NULL,
                `operation_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `kind` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `target` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `fence_generation` BIGINT UNSIGNED NOT NULL,
                `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `result_json` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `result_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `resolution_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_side_effect_uid` (`operation_uid`),
                UNIQUE KEY `uk_side_effect_operation` (`run_id`, `operation_key`),
                KEY `idx_side_effect_pending` (`state`, `updated_at`, `run_id`),
                CONSTRAINT `fk_side_effect_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'generation_artifacts' => "CREATE TABLE IF NOT EXISTS `generation_artifacts` (
                `id`                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `artifact_uid`           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `run_id`                 BIGINT UNSIGNED NOT NULL,
                `step_id`                BIGINT UNSIGNED DEFAULT NULL,
                `artifact_type`          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `logical_key`            VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `revision_no`            INT UNSIGNED NOT NULL DEFAULT 1,
                `content_hash`           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `mime_type`              VARCHAR(127) NOT NULL,
                `encoding`               VARCHAR(32) NOT NULL DEFAULT 'utf-8',
                `storage_kind`           VARCHAR(16) NOT NULL DEFAULT 'inline',
                `inline_text`            LONGTEXT DEFAULT NULL,
                `inline_json`            JSON DEFAULT NULL,
                `storage_uri`            VARCHAR(1000) DEFAULT NULL,
                `size_bytes`             BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `sensitivity`            VARCHAR(24) NOT NULL DEFAULT 'internal',
                `metadata_json`          JSON DEFAULT NULL,
                `supersedes_artifact_id` BIGINT UNSIGNED DEFAULT NULL,
                `retention_until`        DATETIME(6) DEFAULT NULL,
                `created_at`             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY `uk_generation_artifact_uid` (`artifact_uid`),
                UNIQUE KEY `uk_generation_artifact_revision` (`run_id`, `logical_key`, `revision_no`),
                KEY `idx_generation_artifact_hash` (`content_hash`),
                KEY `idx_generation_artifact_step` (`step_id`, `created_at`),
                KEY `idx_generation_artifact_retention` (`retention_until`),
                KEY `idx_generation_artifact_supersedes` (`supersedes_artifact_id`),
                CONSTRAINT `fk_generation_artifacts_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_generation_artifacts_step`
                    FOREIGN KEY (`step_id`) REFERENCES `generation_steps` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_generation_artifacts_supersedes`
                    FOREIGN KEY (`supersedes_artifact_id`) REFERENCES `generation_artifacts` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Content-addressed Harness artifacts'",

            'chapter_commit_ledger' => "CREATE TABLE IF NOT EXISTS `chapter_commit_ledger` (
                `id`                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `run_id`                  BIGINT UNSIGNED NOT NULL,
                `chapter_id`              INT UNSIGNED NOT NULL,
                `candidate_id`            BIGINT UNSIGNED DEFAULT NULL,
                `draft_hash`              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `previous_revision_hash`  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `committed_revision_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `legacy_revision_hash`    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin
                    GENERATED ALWAYS AS (IF(`candidate_id` IS NULL, `committed_revision_hash`, NULL)) STORED
                    COMMENT 'm63_candidate_legacy_revision_v1',
                `postprocess_job_id`       BIGINT UNSIGNED DEFAULT NULL,
                `committed_at`             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY `uk_chapter_commit_run_draft` (`run_id`, `draft_hash`),
                UNIQUE KEY `uk_chapter_commit_candidate` (`candidate_id`),
                UNIQUE KEY `uk_chapter_commit_legacy_revision` (`chapter_id`, `legacy_revision_hash`),
                KEY `idx_chapter_commit_revision` (`chapter_id`, `committed_revision_hash`),
                KEY `idx_chapter_commit_job` (`postprocess_job_id`),
                CONSTRAINT `fk_chapter_commit_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_commit_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_commit_candidate`
                    FOREIGN KEY (`candidate_id`) REFERENCES `chapter_candidates` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_chapter_commit_job`
                    FOREIGN KEY (`postprocess_job_id`) REFERENCES `postprocess_jobs` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Exactly-once Canon commit ledger'",

            // ========== Harness M64：unified durable Job Kernel ==========
            'jobs' => "CREATE TABLE IF NOT EXISTS `jobs` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `job_uid`           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `run_id`            BIGINT UNSIGNED DEFAULT NULL,
                `step_id`           BIGINT UNSIGNED DEFAULT NULL,
                `queue`             VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `job_type`          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `user_id`           INT UNSIGNED NOT NULL,
                `novel_id`          INT UNSIGNED NOT NULL,
                `chapter_id`        INT UNSIGNED DEFAULT NULL,
                `idempotency_key`   VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `state`             VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
                `priority`          SMALLINT NOT NULL DEFAULT 0,
                `progress`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `payload_json`      JSON NOT NULL,
                `result_json`       JSON DEFAULT NULL,
                `attempt`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                `available_at`      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `lease_owner`       VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `lease_token`       CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `fence_generation`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `lease_expires_at`  DATETIME(6) DEFAULT NULL,
                `cancel_requested`  TINYINT(1) NOT NULL DEFAULT 0,
                `next_event_no`     BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `error_code`        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `error_message`     VARCHAR(500) DEFAULT NULL,
                `legacy_type`       VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `legacy_id`         BIGINT UNSIGNED DEFAULT NULL,
                `started_at`        DATETIME(6) DEFAULT NULL,
                `completed_at`      DATETIME(6) DEFAULT NULL,
                `created_at`        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_job_uid` (`job_uid`),
                UNIQUE KEY `uk_job_queue_idempotency` (`queue`, `idempotency_key`),
                UNIQUE KEY `uk_job_legacy` (`legacy_type`, `legacy_id`),
                KEY `idx_job_claim` (`queue`, `state`, `available_at`, `priority`, `id`),
                KEY `idx_job_run` (`run_id`, `id`),
                KEY `idx_job_step` (`step_id`, `id`),
                KEY `idx_job_novel` (`novel_id`, `state`, `created_at`),
                KEY `idx_job_chapter` (`chapter_id`, `created_at`),
                KEY `idx_job_fence` (`id`, `lease_token`, `fence_generation`),
                CONSTRAINT `fk_jobs_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_jobs_step`
                    FOREIGN KEY (`step_id`) REFERENCES `generation_steps` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_jobs_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jobs_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M64 unified durable Job Kernel'",

            'job_events' => "CREATE TABLE IF NOT EXISTS `job_events` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `job_id`        BIGINT UNSIGNED NOT NULL,
                `event_no`      BIGINT UNSIGNED NOT NULL,
                `event_type`    VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `event_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `event_key`     VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `actor_type`    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `actor_id`      VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `payload_json`  JSON NOT NULL,
                `payload_hash`  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at`    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_job_event_sequence` (`job_id`, `event_no`),
                UNIQUE KEY `uk_job_event_key` (`job_id`, `event_key`),
                KEY `idx_job_event_cursor` (`job_id`, `id`),
                CONSTRAINT `fk_job_events_job`
                    FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M64 append-only Job lifecycle evidence'",

            // ========== Harness M65：immutable Branch / Revision heads ==========
            'story_branches' => "CREATE TABLE IF NOT EXISTS `story_branches` (
                `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `branch_uid`               CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `user_id`                  INT UNSIGNED NOT NULL,
                `novel_id`                 INT UNSIGNED NOT NULL,
                `name`                     VARCHAR(100) NOT NULL,
                `is_main`                  TINYINT(1) NOT NULL DEFAULT 0,
                `state`                    VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
                `forked_from_branch_uid`   CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `forked_from_revision_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `main_slot` TINYINT(1) GENERATED ALWAYS AS (IF(`is_main`=1,1,NULL)) STORED,
                `created_at`               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_story_branch_uid` (`branch_uid`),
                UNIQUE KEY `uk_story_branch_name` (`user_id`, `novel_id`, `name`),
                UNIQUE KEY `uk_story_branch_main` (`user_id`, `novel_id`, `main_slot`),
                UNIQUE KEY `uk_story_branch_scope` (`user_id`, `novel_id`, `branch_uid`),
                KEY `idx_story_branch_novel_state` (`novel_id`, `state`, `created_at`),
                KEY `idx_story_branch_fork` (`forked_from_branch_uid`, `forked_from_revision_uid`),
                CONSTRAINT `fk_story_branches_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M65 owned story branches'",

            // M66 keeps non-main Branch memory outside every legacy/main
            // MemoryEngine table. The composite ownership FK is installed by
            // the guarded M66 migration after older story_branches tables gain
            // uk_story_branch_scope; until that succeeds schema v67 is never
            // stamped and application admission remains fail-closed.
            'branch_memory_records' => "CREATE TABLE IF NOT EXISTS `branch_memory_records` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `memory_uid`          CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `user_id`             INT UNSIGNED NOT NULL,
                `novel_id`            INT UNSIGNED NOT NULL,
                `branch_uid`          CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `memory_key`          VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `record_type`         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `payload_json`        JSON NOT NULL,
                `payload_hash`        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `source_revision_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `record_version`      BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `created_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_branch_memory_uid` (`memory_uid`),
                UNIQUE KEY `uk_branch_memory_scope_key` (`user_id`, `novel_id`, `branch_uid`, `memory_key`),
                KEY `idx_branch_memory_type` (`user_id`, `novel_id`, `branch_uid`, `record_type`, `updated_at`),
                KEY `idx_branch_memory_revision` (`source_revision_uid`),
                CONSTRAINT `fk_branch_memory_revision`
                    FOREIGN KEY (`source_revision_uid`) REFERENCES `chapter_revisions` (`revision_uid`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M66 strictly scoped non-main Branch memory'",

            'chapter_revisions' => "CREATE TABLE IF NOT EXISTS `chapter_revisions` (
                `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `revision_uid`              CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `user_id`                   INT UNSIGNED NOT NULL,
                `novel_id`                  INT UNSIGNED NOT NULL,
                `chapter_id`                INT UNSIGNED NOT NULL,
                `branch_uid`                CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `content`                   LONGTEXT NOT NULL,
                `content_hash`              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `parent_revision_uid`       CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `merge_source_revision_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `source_candidate_uid`      CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at`                DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_chapter_revision_uid` (`revision_uid`),
                KEY `idx_chapter_revision_branch` (`branch_uid`, `chapter_id`, `id`),
                KEY `idx_chapter_revision_content` (`chapter_id`, `content_hash`),
                KEY `idx_chapter_revision_parent` (`parent_revision_uid`),
                KEY `idx_chapter_revision_merge` (`merge_source_revision_uid`),
                KEY `idx_chapter_revision_candidate` (`source_candidate_uid`),
                CONSTRAINT `fk_chapter_revisions_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_revisions_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_revisions_branch`
                    FOREIGN KEY (`branch_uid`) REFERENCES `story_branches` (`branch_uid`) ON DELETE RESTRICT,
                CONSTRAINT `fk_chapter_revisions_candidate`
                    FOREIGN KEY (`source_candidate_uid`) REFERENCES `chapter_candidates` (`candidate_uid`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M65 immutable chapter revision bytes and lineage'",

            'chapter_branch_heads' => "CREATE TABLE IF NOT EXISTS `chapter_branch_heads` (
                `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `branch_uid`      CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `chapter_id`      INT UNSIGNED NOT NULL,
                `revision_uid`    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `head_generation` BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `created_at`      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_chapter_branch_head` (`branch_uid`, `chapter_id`),
                KEY `idx_chapter_branch_revision` (`revision_uid`),
                KEY `idx_chapter_branch_chapter` (`chapter_id`, `updated_at`),
                CONSTRAINT `fk_chapter_branch_heads_branch`
                    FOREIGN KEY (`branch_uid`) REFERENCES `story_branches` (`branch_uid`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_branch_heads_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M65 CAS heads; revision pointer validated transactionally'",

            'chapter_canonical_heads' => "CREATE TABLE IF NOT EXISTS `chapter_canonical_heads` (
                `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`              INT UNSIGNED NOT NULL,
                `novel_id`             INT UNSIGNED NOT NULL,
                `chapter_id`           INT UNSIGNED NOT NULL,
                `branch_uid`           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `revision_uid`         CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `revision_hash`        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `source_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `head_generation`      BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `created_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_chapter_canonical_head` (`chapter_id`),
                KEY `idx_chapter_canonical_novel` (`novel_id`, `updated_at`),
                KEY `idx_chapter_canonical_revision` (`revision_uid`),
                KEY `idx_chapter_canonical_candidate` (`source_candidate_uid`),
                CONSTRAINT `fk_chapter_canonical_heads_novel`
                    FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_canonical_heads_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_chapter_canonical_heads_branch`
                    FOREIGN KEY (`branch_uid`) REFERENCES `story_branches` (`branch_uid`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M65 canonical Revision compatibility head'",

            'branch_merge_commit_ledger' => "CREATE TABLE IF NOT EXISTS `branch_merge_commit_ledger` (
                `id`                             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `approval_id`                    BIGINT UNSIGNED NOT NULL,
                `candidate_id`                   BIGINT UNSIGNED NOT NULL,
                `chapter_id`                     INT UNSIGNED NOT NULL,
                `branch_uid`                     CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `base_revision_uid`              CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `committed_revision_uid`         CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `committed_revision_hash`        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `postprocess_job_id`              BIGINT UNSIGNED DEFAULT NULL,
                `approval_decision_payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `committed_at`                   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_branch_merge_approval` (`approval_id`),
                UNIQUE KEY `uk_branch_merge_candidate` (`candidate_id`),
                UNIQUE KEY `uk_branch_merge_revision` (`committed_revision_uid`),
                KEY `idx_branch_merge_history` (`branch_uid`, `chapter_id`, `committed_at`),
                KEY `idx_branch_merge_base_revision` (`base_revision_uid`),
                KEY `idx_branch_merge_postprocess` (`postprocess_job_id`),
                CONSTRAINT `fk_branch_merge_approval`
                    FOREIGN KEY (`approval_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_branch_merge_candidate`
                    FOREIGN KEY (`candidate_id`) REFERENCES `chapter_candidates` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_branch_merge_chapter`
                    FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_branch_merge_branch`
                    FOREIGN KEY (`branch_uid`) REFERENCES `story_branches` (`branch_uid`) ON DELETE RESTRICT,
                CONSTRAINT `fk_branch_merge_base_revision`
                    FOREIGN KEY (`base_revision_uid`) REFERENCES `chapter_revisions` (`revision_uid`) ON DELETE RESTRICT,
                CONSTRAINT `fk_branch_merge_committed_revision`
                    FOREIGN KEY (`committed_revision_uid`) REFERENCES `chapter_revisions` (`revision_uid`) ON DELETE RESTRICT,
                CONSTRAINT `fk_branch_merge_postprocess`
                    FOREIGN KEY (`postprocess_job_id`) REFERENCES `postprocess_jobs` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M65 exactly-once approved branch merge commits'",

            // P7 retention ledger is intentionally retained after Event payload
            // tiering; archive bytes must verify before this row is inserted.
            'harness_retention_archives' => "CREATE TABLE IF NOT EXISTS `harness_retention_archives` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `archive_uid`      CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `run_id`           BIGINT UNSIGNED NOT NULL,
                `snapshot_hash`    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `content_hash`     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `storage_uri`      VARCHAR(1000) NOT NULL,
                `size_bytes`       BIGINT UNSIGNED NOT NULL,
                `event_count`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `artifact_count`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `first_event_no`   BIGINT UNSIGNED DEFAULT NULL,
                `last_event_no`    BIGINT UNSIGNED DEFAULT NULL,
                `completed_at`     DATETIME(6) NOT NULL,
                `created_at`       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_harness_retention_archive_uid` (`archive_uid`),
                UNIQUE KEY `uk_harness_retention_run` (`run_id`),
                KEY `idx_harness_retention_completed` (`completed_at`, `id`),
                CONSTRAINT `fk_harness_retention_run`
                    FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Verified external Run/Event/Artifact archive ledger'",

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
                `volume_number`   INT DEFAULT NULL COMMENT '卷号，从1开始（与 volume_index 同值双写）',
                `volume_index`    INT UNSIGNED DEFAULT NULL COMMENT '向导卷序号（与 volume_number 同值双写；NULL 时读方回退旧列）',
                `title`           VARCHAR(200) NOT NULL COMMENT '卷标题',
                `summary`         TEXT COMMENT '卷概要（300-500字）',
                `theme`           TEXT DEFAULT NULL COMMENT '本卷主题',
                `start_chapter`   INT DEFAULT NULL COMMENT '起始章节号（与 chapter_from 同值双写）',
                `end_chapter`     INT DEFAULT NULL COMMENT '结束章节号（与 chapter_to 同值双写）',
                `chapter_from`    INT UNSIGNED DEFAULT NULL COMMENT '向导起始章（与 start_chapter 同值双写）',
                `chapter_to`      INT UNSIGNED DEFAULT NULL COMMENT '向导结束章（与 end_chapter 同值双写）',
                `key_events`      JSON COMMENT '本卷关键事件列表',
                `character_focus` JSON COMMENT '本卷重点描写的人物',
                `conflict`        VARCHAR(500) DEFAULT '' COMMENT '本卷核心冲突',
                `resolution`      VARCHAR(500) DEFAULT '' COMMENT '本卷解决方式',
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

            // ========== 登录限速表 ==========
            // 审计修复（2026-08-02 H1-DB）：登录限速从文件锁迁移到数据库。
            // Windows 下 flock() 在某些 PHP/NTFS 组合上不可靠（非阻塞锁
            // 永远返回 false → 走无锁回退路径丢失计数），且并发请求易写冲突。
            // DB 方案用 InnoDB 行锁 + 事务保证原子性，跨平台一致。
            'login_throttle' => "CREATE TABLE IF NOT EXISTS `login_throttle` (
                `ip_hash`       VARCHAR(64) NOT NULL COMMENT 'sha1(client_ip) — 不存原始 IP',
                `attempts`      INT UNSIGNED NOT NULL DEFAULT 0,
                `last_attempt`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近一次失败时间戳',
                `lockout_until` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '锁定到期时间戳(0=未锁)',
                `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`ip_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录限速（DB 持久化，替代文件锁）'",
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
                // MySQL permits an inline self-referencing foreign key when
                // the table is created. It is not an ordering dependency.
                if ($dependency === $name) {
                    continue;
                }
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
