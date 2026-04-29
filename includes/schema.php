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
                `type` VARCHAR(30) NOT NULL COMMENT '指令类型: quality/strategy/optimization',
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

            // ========== 书籍分析表 ==========
            'book_analyses' => "CREATE TABLE IF NOT EXISTS `book_analyses` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `novel_id` INT NOT NULL COMMENT '小说ID',
                `analysis_type` VARCHAR(50) NOT NULL COMMENT '分析类型',
                `analysis_data` JSON COMMENT '分析数据',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_novel_id` (`novel_id`),
                INDEX `idx_type` (`analysis_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='书籍分析结果表'",

            // ========== 核心业务表 ==========
            'arc_summaries' => "CREATE TABLE IF NOT EXISTS `arc_summaries` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
                `arc_index` INT NOT NULL COMMENT '弧段编号，从1开始',
                `chapter_from` INT NOT NULL COMMENT '起始章节',
                `chapter_to` INT NOT NULL COMMENT '结束章节',
                `summary` TEXT COMMENT '200字弧段故事线摘要',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_arc` (`novel_id`, `arc_index`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'story_outlines' => "CREATE TABLE IF NOT EXISTS `story_outlines` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL UNIQUE,
                `story_arc` TEXT,
                `act_division` JSON,
                `major_turning_points` JSON,
                `character_arcs` JSON,
                `character_endpoints` TEXT COMMENT '人物弧线终点',
                `world_evolution` TEXT,
                `recurring_motifs` JSON,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'chapter_synopses' => "CREATE TABLE IF NOT EXISTS `chapter_synopses` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
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
                `chapter_id` INT NOT NULL,
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
                `planted_chapter`       INT UNSIGNED NOT NULL COMMENT '埋设章节',
                `deadline_chapter`      INT UNSIGNED DEFAULT NULL COMMENT '建议回收章节,NULL=无期限',
                `resolved_chapter`      INT UNSIGNED DEFAULT NULL COMMENT 'NULL=未回收',
                `resolved_at`           TIMESTAMP NULL DEFAULT NULL,
                `embedding`             BLOB DEFAULT NULL COMMENT '向量(用于语义匹配回收)',
                `embedding_model`       VARCHAR(100) DEFAULT NULL,
                `embedding_updated_at`  TIMESTAMP NULL DEFAULT NULL,
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_novel_unresolved` (`novel_id`, `resolved_chapter`),
                KEY `idx_deadline`         (`novel_id`, `deadline_chapter`),
                KEY `idx_embedding_null`   (`novel_id`, `embedding_updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='伏笔独立表'",

            'novel_state' => "CREATE TABLE IF NOT EXISTS `novel_state` (
                `novel_id`              INT UNSIGNED PRIMARY KEY,
                `story_momentum`        VARCHAR(300) DEFAULT NULL COMMENT '当前故事势能/悬念一句话',
                `current_arc_summary`   TEXT DEFAULT NULL COMMENT '最近一个活跃弧段的摘要',
                `last_ingested_chapter` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近成功记忆化的章节号',
                `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说状态表'",

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
                KEY `idx_chapter`        (`source_chapter`),
                KEY `idx_embedding_null` (`novel_id`, `embedding_updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='原子记忆表'",

            'novel_characters' => "CREATE TABLE IF NOT EXISTS `novel_characters` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
                `name` VARCHAR(100) NOT NULL COMMENT '角色名称',
                `alias` VARCHAR(100) DEFAULT NULL COMMENT '别名/绰号',
                `role_type` ENUM('protagonist','major','minor','background') DEFAULT 'minor' COMMENT '角色类型',
                `gender` VARCHAR(10) DEFAULT NULL COMMENT '性别',
                `appearance` TEXT COMMENT '外貌描写',
                `personality` TEXT COMMENT '性格特点',
                `background` TEXT COMMENT '背景故事',
                `abilities` TEXT COMMENT '能力/技能',
                `relationships` JSON COMMENT '人物关系',
                `first_appear` INT DEFAULT NULL COMMENT '首次出场章节',
                `last_appear` INT DEFAULT NULL COMMENT '最近出场章节',
                `appear_count` INT DEFAULT 0 COMMENT '出场次数',
                `notes` TEXT COMMENT '备注',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel_id` (`novel_id`),
                KEY `idx_role_type` (`role_type`),
                UNIQUE KEY `uk_novel_name` (`novel_id`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'novel_worldbuilding' => "CREATE TABLE IF NOT EXISTS `novel_worldbuilding` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
                `category` ENUM('location','faction','rule','item','other') NOT NULL COMMENT '类型',
                `name` VARCHAR(200) NOT NULL COMMENT '名称',
                `description` TEXT COMMENT '详细描述',
                `attributes` JSON COMMENT '属性（如地点坐标、势力等级等）',
                `related_chapters` JSON COMMENT '相关章节',
                `importance` TINYINT DEFAULT 3 COMMENT '重要程度 1-5',
                `notes` TEXT COMMENT '备注',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel_id` (`novel_id`),
                KEY `idx_category` (`category`),
                UNIQUE KEY `uk_novel_name_cat` (`novel_id`, `name`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'novel_plots' => "CREATE TABLE IF NOT EXISTS `novel_plots` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
                `chapter_from` INT NOT NULL COMMENT '起始章节',
                `chapter_to` INT DEFAULT NULL COMMENT '结束章节（伏笔回收章节）',
                `event_type` ENUM('main','subplot','foreshadowing','callback','other') NOT NULL DEFAULT 'main',
                `title` VARCHAR(200) NOT NULL COMMENT '事件标题',
                `description` TEXT COMMENT '事件描述',
                `characters` JSON COMMENT '涉及角色',
                `status` ENUM('planted','active','resolving','resolved','abandoned') NOT NULL DEFAULT 'active',
                `importance` TINYINT DEFAULT 3 COMMENT '重要程度 1-5',
                `notes` TEXT COMMENT '备注',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel_id` (`novel_id`),
                KEY `idx_chapter_from` (`chapter_from`),
                KEY `idx_event_type` (`event_type`),
                KEY `idx_status` (`status`),
                UNIQUE KEY `uk_novel_title_type` (`novel_id`, `title`, `event_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'novel_style' => "CREATE TABLE IF NOT EXISTS `novel_style` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
                `category` ENUM('narrative','dialogue','description','emotion','other') NOT NULL DEFAULT 'other',
                `name` VARCHAR(100) NOT NULL COMMENT '名称',
                `content` TEXT COMMENT '内容/示例',
                `examples` JSON COMMENT '示例列表',
                `usage_count` INT DEFAULT 0 COMMENT '使用次数',
                `notes` TEXT COMMENT '备注',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_novel_id` (`novel_id`),
                KEY `idx_category` (`category`),
                UNIQUE KEY `uk_novel_name_cat` (`novel_id`, `name`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'novel_embeddings' => "CREATE TABLE IF NOT EXISTS `novel_embeddings` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `novel_id` INT NOT NULL,
                `source_type` ENUM('character','worldbuilding','plot','style','chapter','other') NOT NULL COMMENT '来源类型',
                `source_id` INT NOT NULL COMMENT '来源ID',
                `content` TEXT NOT NULL COMMENT '原始文本',
                `embedding_blob` LONGBLOB COMMENT '向量数据（float32 二进制存储）',
                `embedding_model` VARCHAR(100) DEFAULT '' COMMENT '使用的Embedding模型',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_source` (`novel_id`, `source_type`, `source_id`),
                KEY `idx_novel_id` (`novel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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

            // 没有 SQL 定义的表（由 install.php 等外部脚本管理）仅在此处占位
            // novels, chapters, ai_models, writing_logs, volume_outlines
            // 这些表在 install.php 中已有完整定义
        ];
    }

    /**
     * 返回 ALLOWED_TABLES 白名单（自动从 tables() 的键派生）
     * 同时补充 install.php 管理的表（novels, chapters, ai_models 等）。
     */
    public static function whitelist(): array
    {
        $schemaTables = array_keys(self::tables());

        // 补充 install.php 中定义但不在 Schema 中的表
        $installManaged = [
            'novels', 'chapters', 'ai_models',
            'writing_logs', 'volume_outlines',
        ];

        return array_unique(array_merge($schemaTables, $installManaged));
    }

    /**
     * 全量建表（幂等：CREATE TABLE IF NOT EXISTS）
     * install.php 和 db.php migrate() 统一调用此方法。
     */
    public static function applyAll(PDO $pdo): void
    {
        foreach (self::tables() as $name => $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // 忽略 表已存在 / 字段已存在 / 列缺失 等错误
                $code = $e->getCode();
                $msg  = $e->getMessage();
                if (
                    $code === '42S01' ||  // Table already exists
                    str_contains($msg, 'already exists') ||
                    str_contains($msg, 'Duplicate column') ||
                    str_contains($msg, 'Duplicate key')
                ) {
                    continue;
                }
                error_log("Schema::applyAll failed for table {$name}: " . $e->getMessage());
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
