<?php
/**
 * 系统安装向导 — 一键安装数据库并设置管理员账号
 * v1.2：新增 v7 大纲增强 + 知识库完整建表（角色/世界观/情节/风格向量字段）
 *       + v10 深度思考(Thinking)开关 + chapter_versions + consistency_logs
 */

define('LOCK_FILE', __DIR__ . '/install.lock');

// 安全加固：已安装后访问此页面直接返回 404。
// 原因：install.php 暴露数据库配置格式和管理员账号结构，
// 攻击者可借此探测系统安装状态。安装完成后应彻底隐藏入口。
// 如需重新安装，请先手动删除根目录下的 install.lock 文件。
if (file_exists(LOCK_FILE)) {
    http_response_code(404);
    exit('Not found.');
}

$alreadyInstalled = false;

$host       = 'localhost';
$user       = 'root';
$pass       = '';
$dbname     = 'ai_novel';
$adminUser  = 'admin';
$adminPass  = '';
$adminPass2 = '';
$error      = '';
$success    = '';

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host       = trim($_POST['db_host']     ?? 'localhost');
    $user       = trim($_POST['db_user']     ?? 'root');
    $pass       = $_POST['db_pass']          ?? '';
    $dbname     = trim($_POST['db_name']     ?? 'ai_novel');
    $adminUser  = trim($_POST['admin_user']  ?? 'admin');
    $adminPass  = $_POST['admin_pass']       ?? '';
    $adminPass2 = $_POST['admin_pass2']      ?? '';

    if ($adminUser === '') {
        $error = '管理员用户名不能为空。';
    } elseif (strlen($adminPass) < 6) {
        $error = '管理员密码至少需要 6 位。';
    } elseif ($adminPass !== $adminPass2) {
        $error = '两次输入的密码不一致。';
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbname`");

            // ================================================================
            // 建表 SQL（v3 完整版，含所有优化字段）
            // ================================================================
            $statements = [

                // AI 模型配置表
                "CREATE TABLE IF NOT EXISTS `ai_models` (
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
                    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 小说主表（v3 完整字段）
                "CREATE TABLE IF NOT EXISTS `novels` (
                    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
                    `style_vector`          TEXT COMMENT '四维风格向量(JSON)',
                    `ref_author`            VARCHAR(200) DEFAULT NULL COMMENT '参考作者',
                    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_status`  (`status`),
                    KEY `idx_updated` (`updated_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 章节表（v7 增强：新增 pacing/suspense 字段）
                "CREATE TABLE IF NOT EXISTS `chapters` (
                    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`        INT UNSIGNED NOT NULL,
                    `chapter_number`  INT          NOT NULL COMMENT '章节序号',
                    `title`           VARCHAR(300) NOT NULL DEFAULT '',
                    `outline`         TEXT COMMENT '章节大纲',
                    `key_points`      TEXT COMMENT '关键情节点(JSON)',
                    `hook`            VARCHAR(500) NOT NULL DEFAULT '' COMMENT '结尾钩子',
                    `hook_type`       VARCHAR(30)  DEFAULT NULL COMMENT '钩子六式类型',
                    `cool_point_type` VARCHAR(30)  DEFAULT NULL COMMENT '爽点类型',
                    `opening_type`    VARCHAR(30)  DEFAULT NULL COMMENT '开篇五式类型',
                    `pacing`          VARCHAR(10)  NOT NULL DEFAULT '中' COMMENT '节奏：快/中/慢',
                    `suspense`        VARCHAR(10)  NOT NULL DEFAULT '无' COMMENT '悬念：有/无',
                    `quality_score`   DECIMAL(3,1) DEFAULT NULL COMMENT '质量评分(0-100)',
                    `gate_results`    JSON         DEFAULT NULL COMMENT '五关检测结果',
                    `synopsis_id`     INT UNSIGNED DEFAULT NULL COMMENT '章节简介ID',
                    `content`         LONGTEXT COMMENT '章节正文',
                    `words`           INT  NOT NULL DEFAULT 0,
                    `status`          ENUM('pending','outlined','writing','completed','skipped','failed') NOT NULL DEFAULT 'pending',
                    `chapter_summary` TEXT COMMENT 'AI生成的章节摘要，供续写参考',
                    `used_tropes`     TEXT COMMENT '本章已用意象/场景(JSON)，近5章规避',
                    `retry_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '写作重试次数',
                    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_novel_chapter` (`novel_id`, `chapter_number`),
                    KEY `idx_novel_status`  (`novel_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 写作日志表
                "CREATE TABLE IF NOT EXISTS `writing_logs` (
                    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`   INT UNSIGNED NOT NULL,
                    `chapter_id` INT UNSIGNED DEFAULT NULL,
                    `action`     VARCHAR(100) NOT NULL,
                    `message`    TEXT,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_novel` (`novel_id`),
                    KEY `idx_novel_created` (`novel_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 全书故事大纲表（v2+）
                "CREATE TABLE IF NOT EXISTS `story_outlines` (
                    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`              INT UNSIGNED NOT NULL UNIQUE,
                    `story_arc`             TEXT COMMENT '故事主线发展脉络',
                    `act_division`          JSON COMMENT '三幕划分',
                    `major_turning_points`  JSON COMMENT '重大转折点',
                    `character_arcs`        JSON COMMENT '人物成长轨迹',
                    `world_evolution`       TEXT COMMENT '世界观演变',
                    `recurring_motifs`      JSON COMMENT '全书重复意象',
                    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (`novel_id`) REFERENCES `novels`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 卷大纲表（v7 新增：中层规划层）
                "CREATE TABLE IF NOT EXISTS `volume_outlines` (
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
                    INDEX idx_novel_volume (`novel_id`, `volume_number`),
                    INDEX idx_chapter_range (`start_chapter`, `end_chapter`),
                    FOREIGN KEY (`novel_id`) REFERENCES `novels`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 章节详细简介表（v2+）
                "CREATE TABLE IF NOT EXISTS `chapter_synopses` (
                    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`        INT UNSIGNED NOT NULL,
                    `chapter_number`  INT          NOT NULL,
                    `synopsis`        TEXT COMMENT '章节简介200-300字',
                    `scene_breakdown` JSON COMMENT '场景分解',
                    `dialogue_beats`  JSON COMMENT '对话要点',
                    `sensory_details` JSON COMMENT '感官细节',
                    `pacing`          VARCHAR(20)  COMMENT '节奏：快/中/慢',
                    `cliffhanger`     TEXT COMMENT '结尾悬念',
                    `foreshadowing`   JSON COMMENT '本章埋下的伏笔',
                    `callbacks`       JSON COMMENT '呼应前文的点',
                    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_chapter` (`novel_id`, `chapter_number`),
                    FOREIGN KEY (`novel_id`) REFERENCES `novels`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 弧段摘要表（三层记忆架构第二层，每10章压缩一次）
                "CREATE TABLE IF NOT EXISTS `arc_summaries` (
                    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`     INT UNSIGNED NOT NULL,
                    `arc_index`    INT NOT NULL COMMENT '弧段编号，从1开始',
                    `chapter_from` INT NOT NULL COMMENT '起始章节',
                    `chapter_to`   INT NOT NULL COMMENT '结束章节',
                    `summary`      TEXT COMMENT '200字弧段故事线摘要',
                    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_arc` (`novel_id`, `arc_index`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // ================================================================
                // 智能知识库表（KnowledgeBase 用，v9 完整版）
                // ================================================================

                // 角色库
                "CREATE TABLE IF NOT EXISTS `novel_characters` (
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

                // 世界观库
                "CREATE TABLE IF NOT EXISTS `novel_worldbuilding` (
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

                // 情节库（含伏笔）
                "CREATE TABLE IF NOT EXISTS `novel_plots` (
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

                // 风格库
                "CREATE TABLE IF NOT EXISTS `novel_style` (
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

                // 向量存储表（语义搜索，v9 修复 UNIQUE KEY 含 novel_id，v10 修复 blob 保留字）
                "CREATE TABLE IF NOT EXISTS `novel_embeddings` (
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

                // ================================================================
                // v6 MemoryEngine 核心表（记忆引擎主流程化）
                // ================================================================

                // 人物状态卡片表（取代 novels.character_states JSON）
                "CREATE TABLE IF NOT EXISTS `character_cards` (
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

                // 人物卡片变更历史表
                "CREATE TABLE IF NOT EXISTS `character_card_history` (
                    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `card_id`        INT UNSIGNED NOT NULL,
                    `chapter_number` INT UNSIGNED NOT NULL,
                    `field_name`     VARCHAR(50) NOT NULL,
                    `old_value`      TEXT,
                    `new_value`      TEXT,
                    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_card_chapter` (`card_id`, `chapter_number`),
                    KEY `idx_field` (`card_id`, `field_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='人物卡片变更历史表'",

                // 伏笔独立表（取代 novels.pending_foreshadowing JSON）
                "CREATE TABLE IF NOT EXISTS `foreshadowing_items` (
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
                    KEY `idx_embedding_null`   (`novel_id`, `embedding_updated_at`),
                    FULLTEXT KEY `ft_description` (`description`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='伏笔独立表'",

                // 小说状态表（取代 novels.story_momentum）
                "CREATE TABLE IF NOT EXISTS `novel_state` (
                    `novel_id`              INT UNSIGNED PRIMARY KEY,
                    `story_momentum`        VARCHAR(300) DEFAULT NULL COMMENT '当前故事势能/悬念一句话',
                    `current_arc_summary`   TEXT DEFAULT NULL COMMENT '最近一个活跃弧段的摘要',
                    `last_ingested_chapter` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近成功记忆化的章节号',
                    `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说状态表'",

                // 原子记忆表（长尾知识存储）
                "CREATE TABLE IF NOT EXISTS `memory_atoms` (
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
                    KEY `idx_embedding_null` (`novel_id`, `embedding_updated_at`),
                    FULLTEXT KEY `ft_content` (`content`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='原子记忆表'",

                // 拆书分析表
                "CREATE TABLE IF NOT EXISTS `book_analyses` (
                    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `title`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '书名',
                    `author`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '作者',
                    `genre`       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '类型',
                    `content`     MEDIUMTEXT NOT NULL COMMENT '分析结果(Markdown)',
                    `source_text` MEDIUMTEXT DEFAULT NULL COMMENT '原始章节文本',
                    `created_at`  DATETIME NOT NULL,
                    INDEX `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='拆书分析表'",

                // 章节版本快照表
                "CREATE TABLE IF NOT EXISTS `chapter_versions` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `chapter_id`  INT NOT NULL,
                    `version`     INT NOT NULL DEFAULT 1,
                    `content`     LONGTEXT DEFAULT NULL,
                    `outline`     TEXT DEFAULT NULL,
                    `title`       VARCHAR(255) DEFAULT NULL,
                    `words`       INT DEFAULT 0,
                    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_chapter_version` (`chapter_id`, `version`),
                    KEY `idx_chapter_id` (`chapter_id`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='章节版本快照表'",

                // 一致性检测日志表
                "CREATE TABLE IF NOT EXISTS `consistency_logs` (
                    `id`              INT AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`        INT NOT NULL,
                    `chapter_number`  INT NOT NULL,
                    `check_type`      VARCHAR(50) DEFAULT NULL,
                    `issues`          JSON DEFAULT NULL,
                    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_novel_id` (`novel_id`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='一致性检测日志表'",

                // 系统设置表
                "CREATE TABLE IF NOT EXISTS `system_settings` (
                    `setting_key`   VARCHAR(100) PRIMARY KEY,
                    `setting_value` TEXT,
                    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置表'",

                // 初始化 system_settings：embedding 模型 ID + 写作参数默认值
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
                  ('global_embedding_model_id', ''),
                  ('ws_chapter_words',              '2000'),
                  ('ws_chapter_word_tolerance',     '150'),
                  ('ws_outline_batch',              '20'),
                  ('ws_auto_write_interval',        '2'),
                  ('ws_cool_point_density_target',  '0.88'),
                  ('ws_cool_point_hunger_threshold','0.6'),
                  ('ws_double_coolpoint_gap',       '3'),
                  ('ws_segment_ratio_setup',        '20'),
                  ('ws_segment_ratio_rising',       '30'),
                  ('ws_segment_ratio_climax',       '35'),
                  ('ws_segment_ratio_hook',         '15'),
                  ('ws_foreshadowing_lookback',     '10'),
                  ('ws_memory_lookback',            '5'),
                  ('ws_embedding_top_k',            '5'),
                  ('ws_temperature_outline',        '0.3'),
                  ('ws_temperature_chapter',        '0.8'),
                  ('ws_max_tokens_outline',         '4096'),
                  ('ws_max_tokens_chapter',         '8192'),
                  ('ws_quality_check_enabled',      '1'),
                  ('ws_quality_min_score',          '6.0')",

                // ai_models 扩展字段（老库升级兜底，新安装已包含在 CREATE TABLE 中）
                // "ALTER TABLE `ai_models` ADD COLUMN `can_embed` ...",
                // "ALTER TABLE `ai_models` ADD COLUMN `embedding_model_name` ...",
                // "ALTER TABLE `ai_models` ADD COLUMN `embedding_dim` ...",
                // "ALTER TABLE `ai_models` ADD COLUMN `thinking_enabled` ...",

                // 索引（MySQL 不支持 CREATE INDEX IF NOT EXISTS，改用 ALTER TABLE 忽略重复）
                // 新安装已包含在 CREATE TABLE 中，以下为老库升级兜底
                // "ALTER TABLE `chapters` ADD INDEX `idx_chapter_synopsis` (`novel_id`, `chapter_number`, `synopsis_id`)",
                // "ALTER TABLE `novels`   ADD INDEX `idx_story_outline`    (`id`, `has_story_outline`)",

                // v7: chapters 表扩展 skipped/failed 状态 + retry_count（新安装已包含）
                // "ALTER TABLE `chapters` CHANGE `status` ...",
                // "ALTER TABLE `chapters` ADD COLUMN `retry_count` ...",

                // v8: novel_embeddings blob 列名修复（新安装已包含）
                // "ALTER TABLE `novel_embeddings` CHANGE `blob` `embedding_blob` ...",
                // "ALTER TABLE `novel_embeddings` CHANGE `embedding` `embedding_blob` ...",

                // P3 优化：字数容差可配置（老库升级兜底，新安装已包含在上方 INSERT IGNORE 块中）
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('ws_chapter_word_tolerance', '150')",

                // 卷级目标 + 强制伏笔回收（老库升级兜底，新安装已包含在 CREATE TABLE 中）
                // MySQL 不支持 ADD COLUMN IF NOT EXISTS，重复执行会抛 1060，已在下方 catch 中忽略
                "ALTER TABLE `volume_outlines` ADD COLUMN `volume_goals` JSON COMMENT '本卷写作目标' AFTER `foreshadowing`",
                "ALTER TABLE `volume_outlines` ADD COLUMN `must_resolve_foreshadowing` JSON COMMENT '本卷必须回收的伏笔描述列表' AFTER `volume_goals`",
            ];

            foreach ($statements as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    // 忽略"索引已存在"(1061)、"列已存在"(1060)和"列不存在"(1054)错误，其余错误继续抛出
                    $code = (int)($e->errorInfo[1] ?? 0);
                    if ($code !== 1061 && $code !== 1060 && $code !== 1054) throw $e;
                }
            }

            // 生成密码散列
            $passHash = password_hash($adminPass, PASSWORD_BCRYPT);
            $esc = fn(string $s) => addslashes($s);

            // 写入 config.php
            $configContent = <<<PHP
<?php
// ============================================================
// 数据库配置
// ============================================================
define('DB_HOST',    '{$esc($host)}');
define('DB_NAME',    '{$esc($dbname)}');
define('DB_USER',    '{$esc($user)}');
define('DB_PASS',    '{$esc($pass)}');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 后台账号（由安装向导写入，请勿手动修改密码明文）
// ============================================================
define('ADMIN_USER', '{$esc($adminUser)}');
define('ADMIN_PASS', '{$esc($passHash)}');

// ============================================================
// 站点配置
// ============================================================
define('SITE_NAME', 'AI小说创作系统');
define('BASE_PATH', __DIR__);

// ============================================================
// 默认生成参数
// ============================================================
define('DEFAULT_CHAPTER_WORDS',   2000);
define('DEFAULT_OUTLINE_BATCH',   20);
define('AUTO_WRITE_INTERVAL',     2);

// ---- 禁止直接访问 includes/api 文件（由各入口文件定义） ----
defined('APP_LOADED') or define('APP_LOADED', true);

// ---- 引入集中配置常量 ----
require_once __DIR__ . '/includes/config_constants.php';

// ============================================================
// 系统设置读取辅助函数（写作参数等全局配置）
// 所有参数存储在 system_settings 表中，key 前缀 ws_ = writing settings
// ============================================================
/**
 * 从 system_settings 读取单个设置值，找不到时返回默认值。
 */
function getSystemSetting(string \$key, \$default = null, string \$type = 'string') {
    try {
        if (!class_exists('DB', false)) {
            return \$default;
        }
        \$row = DB::fetch('SELECT setting_value FROM system_settings WHERE setting_key=?', [\$key]);
        if (!\$row) {
            return \$default;
        }
        \$val = \$row['setting_value'];
        return match (\$type) {
            'int'    => (int)\$val,
            'float'  => (float)\$val,
            'bool'   => in_array(strtolower((string)\$val), ['1', 'true', 'yes', 'on']),
            default  => (string)\$val,
        };
    } catch (\Throwable \$e) {
        return \$default;
    }
}

/**
 * 批量读取写作参数，返回 key=>value 数组。
 */
function getWritingSettings(array \$keys): array {
    \$result = [];
    // 从全局唯一默认值中提取
    \$defaults = getWritingDefaults();
    foreach (\$keys as \$key => \$type) {
        \$def = \$defaults[\$key] ?? ['default'=>null, 'type'=>'string'];
        \$result[\$key] = getSystemSetting(\$key, \$def['default'], \$type ?: \$def['type']);
    }
    return \$result;
}

defined('APP_LOADED') or define('APP_LOADED', true);
PHP;

            file_put_contents(__DIR__ . '/config.php', $configContent);
            file_put_contents(LOCK_FILE,
                "Installed at: " . date('Y-m-d H:i:s') . "\n" .
                "DB Host: $host\n" .
                "DB Name: $dbname\n" .
                "Admin: $adminUser\n" .
                "Version: v1.2 (Thinking + KnowledgeBase)\n"
            );

            $success = "安装成功！管理员账号：<strong>" . htmlspecialchars($adminUser) . "</strong>，数据库已就绪。";

        } catch (PDOException $e) {
            $error = '数据库连接失败：' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 - Super Ma  AI小说创作系统</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<script>(function(){ var t=localStorage.getItem('novel-theme')||'dark'; document.documentElement.setAttribute('data-theme',t); })();</script>
<style>
:root {
    --bg-body:  #0f0f1a;
    --bg-card:  #1a1a2e;
    --border:   #2d2d4e;
    --text:     #e0e0f0;
    --muted:    #8888aa;
    --input-bg: #12122a;
}
[data-theme="light"] {
    --bg-body:  #f0f2f5;
    --bg-card:  #ffffff;
    --border:   #d0d0e0;
    --text:     #1a1a2e;
    --muted:    #666688;
    --input-bg: #f8f8ff;
}
body { background: var(--bg-body); color: var(--text); min-height:100vh; display:flex; align-items:center; }
.card-install { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; }
.form-control, .form-select, .input-group-text {
    background: var(--input-bg); border-color: var(--border); color: var(--text);
}
.form-control:focus {
    background: var(--input-bg); border-color: #6366f1; color: var(--text);
    box-shadow: 0 0 0 .2rem rgba(99,102,241,.25);
}
.form-label { color: var(--muted); font-size: .875rem; }
.input-group-text { color: var(--muted); }
.logo { font-size:1.8rem; font-weight:700; background:linear-gradient(135deg,#6366f1,#a78bfa); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
.section-title { font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color:#6366f1; font-weight:600; border-bottom:1px solid var(--border); padding-bottom:.4rem; margin-bottom:1rem; }
.btn-install { background:linear-gradient(135deg,#6366f1,#8b5cf6); border:none; padding:.7rem; font-weight:600; }
.btn-install:hover { opacity:.9; }
.step-badge { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#6366f1; color:#fff; font-size:.7rem; font-weight:700; margin-right:.5rem; flex-shrink:0; }
.already-installed { background:rgba(99,102,241,.1); border:1px solid rgba(99,102,241,.3); border-radius:12px; }
.feature-list { list-style:none; padding:0; margin:0; }
.feature-list li { font-size:.8rem; color:var(--muted); padding:2px 0; }
.feature-list li::before { content:"✓ "; color:#10b981; font-weight:700; }
</style>
</head>
<body>
<div class="container py-4" style="max-width:560px">
  <div class="card-install p-4 p-md-5 shadow-lg">
    <div class="text-center mb-4">
      <div class="logo">✦ Super Ma AI创作系统</div>
      <p class="text-muted mt-1 mb-0" style="font-size:.8rem">安装向导 · v1.2</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="bi bi-check-circle me-1"></i><?= $success ?>
    </div>
      <div class="mb-3 p-3" style="background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.2);border-radius:8px">
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px;font-weight:600;">已创建数据库结构 (v1.2)</div>
      <ul class="feature-list">
        <li>ai_models · novels · chapters · writing_logs</li>
        <li>story_outlines — 全书故事大纲</li>
        <li>volume_outlines — 卷大纲（中层规划）</li>
        <li>chapter_synopses — 章节详细简介</li>
        <li>arc_summaries — 弧段故事线摘要（L2记忆）</li>
        <li><strong style="color:#10b981">novel_characters — 角色库（含功能模板/出场章节）</strong></li>
        <li><strong style="color:#10b981">novel_worldbuilding — 世界观库</strong></li>
        <li><strong style="color:#10b981">novel_plots — 情节库（含伏笔类型/回收章节）</strong></li>
        <li><strong style="color:#10b981">novel_style — 风格库（含四维向量/参考作者）</strong></li>
        <li><strong style="color:#10b981">novel_embeddings — 向量存储（语义搜索）</strong></li>
        <li><strong style="color:#10b981">character_cards — 人物状态卡片（记忆引擎）</strong></li>
        <li><strong style="color:#10b981">character_card_history — 人物变更历史</strong></li>
        <li><strong style="color:#10b981">foreshadowing_items — 伏笔独立表</strong></li>
        <li><strong style="color:#10b981">novel_state — 小说状态表</strong></li>
        <li><strong style="color:#10b981">memory_atoms — 原子记忆表</strong></li>
        <li><strong style="color:#10b981">book_analyses — 拆书分析表</strong></li>
        <li><strong style="color:#10b981">chapter_versions — 章节版本快照表</strong></li>
        <li><strong style="color:#10b981">consistency_logs — 一致性检测日志表</strong></li>
        <li><strong style="color:#10b981">system_settings — 系统设置表（含写作参数默认值）</strong></li>
        <li><strong style="color:#10b981">ai_models 扩展：thinking_enabled / can_embed / embedding_model_name / embedding_dim</strong></li>
      </ul>
    </div>
    <a href="login.php" class="btn btn-primary btn-install w-100">
      <i class="bi bi-box-arrow-in-right me-1"></i>前往登录 →
    </a>

    <?php else: ?>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small">
      <i class="bi bi-exclamation-triangle me-1"></i><?= $error ?>
    </div>
    <?php endif; ?>

    <form method="post" id="installForm">
      <!-- 环境检测 -->
      <?php
      $disabledFns = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
      $cliBinaryOk = false; $cliBinaryPath = '';
      if (!in_array('exec', $disabledFns) && function_exists('exec')) {
          if (PHP_OS_FAMILY === 'Windows') {
              @exec('where php 2>nul', $out, $code);
              $cliBinaryOk = ($code === 0 && !empty($out));
              $cliBinaryPath = $cliBinaryOk ? trim($out[0]) : '';
          } else {
              @exec('which php 2>/dev/null', $out, $code);
              $cliBinaryOk = ($code === 0 && !empty($out));
              $cliBinaryPath = $cliBinaryOk ? trim($out[0]) : '';
              // 兜底：常见路径
              if (!$cliBinaryOk) {
                  foreach (['/usr/bin/php', '/usr/local/bin/php', '/bin/php'] as $candidate) {
                      @exec(escapeshellarg($candidate) . ' -r "echo 1;" 2>/dev/null', $testOut, $testCode);
                      if ($testCode === 0 && !empty($testOut)) {
                          $cliBinaryOk = true; $cliBinaryPath = $candidate; break;
                      }
                  }
              }
          }
      }
      // 进度文件目录可写性
      $tmpWritable = is_writable(sys_get_temp_dir());
      $projectWritable = is_writable(__DIR__);
      $envChecks = [
          ['PHP 版本',   version_compare(PHP_VERSION, '8.0', '>='),                       '需要 PHP 8.0+（当前 ' . PHP_VERSION . '）'],
          ['exec()',     !in_array('exec', $disabledFns) && function_exists('exec'),      '异步写作模式需要（禁用后自动回退到SSE直连模式）'],
          ['popen()',    !in_array('popen', $disabledFns) && function_exists('popen'),    '异步写作模式需要（禁用后自动回退到SSE直连模式）'],
          ['pclose()',   !in_array('pclose', $disabledFns) && function_exists('pclose'),  '异步写作模式需要（禁用后自动回退到SSE直连模式）'],
          ['proc_open',  !in_array('proc_open', $disabledFns) && function_exists('proc_open'), '异步写作 Windows 备选（proc_open 比 popen 更可靠）'],
          ['flock()',    function_exists('flock'),                                          '进度文件并发锁（多进程写作安全）'],
          ['chmod()',    function_exists('chmod'),                                          'Shell wrapper 可执行权限设置'],
          ['curl',       function_exists('curl_init'),                                      'AI接口调用需要'],
          ['pdo_mysql',  extension_loaded('pdo_mysql'),                                     '数据库连接需要'],
          ['json',       function_exists('json_encode'),                                    '数据交互需要'],
          ['mbstring',   extension_loaded('mbstring'),                                      '中文字数统计需要'],
          ['session',    function_exists('session_start'),                                  '登录鉴权需要'],
          ['allow_url_fopen', (bool)ini_get('allow_url_fopen'),                             'HTTP Stream fallback（curl不可用时的备选）'],
          ['PHP CLI',    $cliBinaryOk,                                                      '异步写作核心依赖' . ($cliBinaryOk ? '（' . $cliBinaryPath . '）' : '（未找到，异步写作将不可用）')],
          ['进度目录(/tmp)', $tmpWritable,                                                  '异步写作进度文件写入' . ($tmpWritable ? '（可写）' : '（不可写，将回退到项目目录）')],
          ['项目目录可写', $projectWritable,                                                '配置文件/锁文件写入' . ($projectWritable ? '（可写）' : '（不可写）')],
      ];
      $hasWarning = false;
      foreach ($envChecks as $c) { if (!$c[1]) $hasWarning = true; }

      // ================================================================
      // 异步写作深入诊断（模拟 test_write_diag.php 核心检测）
      // 关键区别：不仅检查 disable_functions，还实测 exec/popen/proc_open
      // ================================================================
      $asyncDiag = [
          'tested'           => false,
          'exec_works'       => false,
          'popen_works'      => false,
          'proc_open_works'  => false,
          'php_binary'       => '',
          'php_binary_raw'   => '',
          'php_binary_fixed' => false,
          'worker_exists'    => false,
          'worker_syntax_ok' => false,
          'worker_syntax_out'=> '',
          'cli_pdo_mysql'    => false,
          'cli_ext_list'     => '',
          'mini_exec_ok'     => false,
          'verdict'          => 'skipped',
          'verdict_msg'      => '',
      ];

      $asyncCanTest = (!in_array('exec', $disabledFns) && function_exists('exec'));

      if ($asyncCanTest) {
          $asyncDiag['tested'] = true;
          $asyncDiag['php_binary_raw'] = PHP_BINARY ?: 'php';
          $phpBin = $asyncDiag['php_binary_raw'];

          // 1. 实测 exec()
          $testOut = []; $testCode = -1;
          if (PHP_OS_FAMILY === 'Windows') {
              @exec('echo 1', $testOut, $testCode);
          } else {
              @exec('echo 1 2>/dev/null', $testOut, $testCode);
          }
          $asyncDiag['exec_works'] = ($testCode === 0);

          // 2. 实测 popen()
          if (function_exists('popen') && function_exists('pclose')) {
              $cmd = (PHP_OS_FAMILY === 'Windows') ? 'echo 1' : 'echo 1 2>/dev/null';
              $p = @popen($cmd, 'r');
              if ($p) { pclose($p); $asyncDiag['popen_works'] = true; }
          }

          // 3. 实测 proc_open()
          if (function_exists('proc_open') && function_exists('proc_close')) {
              $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
              $cmd = (PHP_OS_FAMILY === 'Windows') ? 'echo 1' : 'echo 1 2>/dev/null';
              $proc = @proc_open($cmd, $desc, $pipes);
              if (is_resource($proc)) {
                  foreach ($pipes as $pp) fclose($pp);
                  proc_close($proc);
                  $asyncDiag['proc_open_works'] = true;
              }
          }

          // 4. PHP CLI 二进制修正（php-fpm → php-cli，宝塔关键修复）
          if (PHP_OS_FAMILY !== 'Windows' && preg_match('#/php-fpm\d*$#', $phpBin)) {
              @exec('which php 2>/dev/null', $whichOut, $whichCode);
              if ($whichCode === 0 && !empty($whichOut[0])) {
                  $candidate = trim($whichOut[0]);
                  @exec(escapeshellarg($candidate) . ' -r "echo 1;" 2>/dev/null', $rTest, $rCode);
                  if ($rCode === 0) {
                      $phpBin = $candidate;
                      $asyncDiag['php_binary_fixed'] = true;
                  }
              }
              if (!$asyncDiag['php_binary_fixed']) {
                  $phpBin = str_replace('/sbin/php-fpm', '/bin/php', $phpBin);
                  $asyncDiag['php_binary_fixed'] = true;
              }
          }
          $asyncDiag['php_binary'] = $phpBin;

          // 5. Worker 脚本检查
          $workerScript = __DIR__ . '/api/write_chapter_worker.php';
          $asyncDiag['worker_exists'] = file_exists($workerScript);

          // 6. Worker 语法检查（PHP lint）
          if ($asyncDiag['exec_works'] && $asyncDiag['worker_exists']) {
              $syncCmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($workerScript) . ' 2>&1';
              @exec($syncCmd, $syncOut, $syncCode);
              $asyncDiag['worker_syntax_ok'] = ($syncCode === 0);
              $asyncDiag['worker_syntax_out'] = implode(' ', $syncOut);
          }

          // 7. CLI pdo_mysql 扩展检测（cli 可能加载不同的 php.ini）
          if ($asyncDiag['exec_works']) {
              @exec(escapeshellarg($phpBin) . ' -m 2>&1', $modOut, $modCode);
              $asyncDiag['cli_ext_list'] = implode(', ', array_slice($modOut, 2));
              foreach ($modOut as $line) {
                  if (stripos($line, 'pdo_mysql') !== false || stripos($line, 'PDO') !== false) {
                      $asyncDiag['cli_pdo_mysql'] = true;
                      break;
                  }
              }
          }

          // 8. 最小化后台执行测试（验证进程启动机制整体可用）
          if ($asyncDiag['exec_works']) {
              $miniScript = sys_get_temp_dir() . '/install_test_' . bin2hex(random_bytes(4)) . '.php';
              $miniOut    = sys_get_temp_dir() . '/install_test_' . bin2hex(random_bytes(4)) . '.txt';
              file_put_contents($miniScript, '<?php file_put_contents("' . addslashes($miniOut) . '", "OK_".time()."\n");');
              if (PHP_OS_FAMILY === 'Windows') {
                  $miniCmd = 'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($miniScript) . ' 2>nul';
                  @exec($miniCmd, $miniOutArr, $miniCode);
              } else {
                  $miniCmd = 'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($miniScript) . ' > /dev/null 2>&1 &';
                  @exec($miniCmd, $miniOutArr, $miniCode);
              }
              sleep(2);
              $asyncDiag['mini_exec_ok'] = (file_exists($miniOut) && strpos(@file_get_contents($miniOut) ?? '', 'OK_') !== false);
              @unlink($miniScript);
              @unlink($miniOut);
          }

          // 9. 综合判定
          $anyProcOk = $asyncDiag['exec_works'] || $asyncDiag['popen_works'] || $asyncDiag['proc_open_works'];
          if (!$anyProcOk) {
              $asyncDiag['verdict'] = 'sse_only';
              $asyncDiag['verdict_msg'] = '所有进程启动函数均被禁用或不可用';
          } elseif (PHP_OS_FAMILY !== 'Windows' && preg_match('#/php-fpm\d*$#', $asyncDiag['php_binary_raw']) && !$asyncDiag['php_binary_fixed']) {
              $asyncDiag['verdict'] = 'sse_only';
              $asyncDiag['verdict_msg'] = 'PHP_BINARY 为 php-fpm，无法执行 CLI 脚本，且未能找到 php-cli';
          } elseif (!$asyncDiag['cli_pdo_mysql']) {
              $asyncDiag['verdict'] = 'unstable';
              $asyncDiag['verdict_msg'] = 'PHP CLI 缺少 pdo_mysql 扩展，异步写作可能失败（CLI 可能使用不同的 php.ini）';
          } elseif ($asyncDiag['mini_exec_ok']) {
              $asyncDiag['verdict'] = 'ok';
              $asyncDiag['verdict_msg'] = '异步写作环境正常，后台进程启动验证通过';
          } else {
              $asyncDiag['verdict'] = 'unstable';
              $asyncDiag['verdict_msg'] = '后台进程启动测试失败。异步写作可能不可用，将自动回退到 SSE 直连模式。';
          }
      } else {
          $asyncDiag['verdict'] = 'sse_only';
          $asyncDiag['verdict_msg'] = 'exec() 被禁用，无法检测异步写作环境';
      }
      ?>
      <div class="section-title"><span class="step-badge">0</span>环境检测</div>
      <div class="mb-3 p-3" style="background:<?= $hasWarning ? 'rgba(245,158,11,.06)' : 'rgba(16,185,129,.05)' ?>;border:1px solid <?= $hasWarning ? 'rgba(245,158,11,.2)' : 'rgba(16,185,129,.2)' ?>;border-radius:8px">
        <?php foreach ($envChecks as $check): list($name, $ok, $desc) = $check; ?>
        <div class="d-flex align-items-center gap-2 py-1" style="font-size:.82rem">
          <?php if ($ok): ?>
            <i class="bi bi-check-circle-fill text-success"></i>
            <span><?= $name ?></span>
            <span class="text-muted" style="font-size:.72rem">— <?= $desc ?></span>
          <?php else: ?>
            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
            <span class="fw-semibold"><?= $name ?></span>
            <span class="text-warning" style="font-size:.72rem">— 已禁用，<?= $desc ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($hasWarning): ?>
        <div class="mt-2 pt-2" style="border-top:1px solid var(--border);font-size:.75rem;color:var(--muted)">
          <i class="bi bi-info-circle me-1"></i>警告项不影响安装，但可能影响部分功能。exec/popen/pclose 禁用时写作将自动使用 SSE 直连模式（可能受 Nginx 超时限制）。PHP CLI 未找到时异步写作不可用。
        </div>
        <?php endif; ?>
      </div>

      <!-- ================================================================ -->
      <!-- 异步写作深度诊断结果 -->
      <!-- ================================================================ -->
      <?php
      $v = $asyncDiag['verdict'];
      $isOk      = ($v === 'ok');
      $isUnstable= ($v === 'unstable');
      $isSseOnly = ($v === 'sse_only');
      ?>
      <div class="section-title mt-3"><span class="step-badge">⚡</span>异步写作深度检测</div>
      <div class="mb-3 p-3" style="background:<?= $isOk ? 'rgba(16,185,129,.05)' : 'rgba(245,158,11,.06)' ?>;border:1px solid <?= $isOk ? 'rgba(16,185,129,.2)' : 'rgba(245,158,11,.2)' ?>;border-radius:8px">
        <?php if (!$asyncDiag['tested']): ?>
        <div style="font-size:.82rem;color:var(--muted)">
          <i class="bi bi-info-circle me-1"></i>exec() 被禁用，跳过异步写作深度检测。写作将自动使用 SSE 直连模式。
        </div>
        <?php else: ?>

        <!-- 总判定 -->
        <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border)">
          <div class="fw-semibold" style="font-size:.85rem">
            <?php if ($isOk): ?>
              <i class="bi bi-check-circle-fill text-success me-1"></i>异步写作可用
            <?php elseif ($isUnstable): ?>
              <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>SEE写作不稳定（会偶发自动写作章节断开问题）
            <?php else: ?>
              <i class="bi bi-x-circle-fill text-danger me-1"></i>仅 SSE 直连模式
            <?php endif; ?>
          </div>
          <div style="font-size:.75rem;color:var(--muted);margin-top:2px"><?= htmlspecialchars($asyncDiag['verdict_msg']) ?></div>
        </div>

        <!-- 详细检测项 -->
        <?php
        $diagItems = [
            ['exec() 实测',       $asyncDiag['exec_works'],      '后台进程启动核心函数'],
            ['popen() 实测',      $asyncDiag['popen_works'],     'Linux 备选进程启动方式'],
            ['proc_open() 实测',  $asyncDiag['proc_open_works'], 'Windows 备选进程启动方式'],
        ];
        if ($asyncDiag['worker_exists']) {
            $diagItems[] = ['Worker 语法检查', $asyncDiag['worker_syntax_ok'], $asyncDiag['worker_syntax_ok'] ? '通过' : $asyncDiag['worker_syntax_out']];
        } else {
            $diagItems[] = ['Worker 脚本存在', $asyncDiag['worker_exists'], 'api/write_chapter_worker.php 缺失'];
        }
        $diagItems[] = ['CLI pdo_mysql',   $asyncDiag['cli_pdo_mysql'],   $asyncDiag['cli_pdo_mysql'] ? 'CLI 已加载' : 'CLI 未加载（可能使用不同的php.ini）'];
        $diagItems[] = ['后台进程测试',    $asyncDiag['mini_exec_ok'],    $asyncDiag['mini_exec_ok'] ? '通过（nohup启动成功）' : '未通过（进程未能正常启动）'];
        if ($asyncDiag['php_binary_fixed']) {
            $diagItems[] = ['PHP_BINARY修正', true, $asyncDiag['php_binary_raw'] . ' → ' . $asyncDiag['php_binary']];
        }
        if ($asyncDiag['cli_ext_list']) {
            $diagItems[] = ['CLI 扩展列表', true, mb_strlen($asyncDiag['cli_ext_list']) > 200 ? mb_substr($asyncDiag['cli_ext_list'], 0, 200) . '…' : $asyncDiag['cli_ext_list']];
        }
        ?>
        <?php foreach ($diagItems as $item): list($name, $ok, $desc) = $item; ?>
        <div class="d-flex align-items-center gap-2 py-1" style="font-size:.78rem">
          <?php if ($ok): ?>
            <i class="bi bi-check-circle-fill text-success" style="font-size:.7rem"></i>
            <span><?= $name ?></span>
            <span class="text-muted" style="font-size:.7rem">— <?= $desc ?></span>
          <?php else: ?>
            <i class="bi bi-x-circle-fill text-danger" style="font-size:.7rem"></i>
            <span class="fw-semibold"><?= $name ?></span>
            <span style="color:#dc3545;font-size:.7rem">— <?= $desc ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
      </div>

      <?php if ($isSseOnly || $isUnstable): ?>
      <!-- SSE模式 / 不稳定警告 -->
      <div class="mb-3 p-3" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:8px">
        <div style="font-size:.82rem;color:#ef4444;font-weight:600;margin-bottom:4px">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          <?= $isSseOnly ? '异步写作不可用，仅 SSE 直连模式' : '异步写作可能不稳定' ?>
        </div>
        <div style="font-size:.75rem;color:var(--muted)">
          <?php if ($isSseOnly): ?>
          写作将使用 SSE 直连模式（Server-Sent Events），该模式受 Nginx/Apache 超时限制（通常 60-120 秒），
          长篇章节写作可能被截断。建议在服务器上安装并启用 PHP CLI，确保 <code>exec()</code> 未被禁用。
          <?php else: ?>
          异步写作环境不完全满足要求，写作任务可能启动失败并自动回退到 SSE 直连模式。
          建议检查 PHP CLI 配置，确保 CLI 与 FPM 使用相同的扩展（尤其是 pdo_mysql）。
          <?php endif; ?>
        </div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:4px">
          <i class="bi bi-check2-square me-1"></i>你仍可继续安装，但写作功能可能因超时而中断。
        </div>
      </div>
      <?php endif; ?>

      <div class="section-title"><span class="step-badge">1</span>数据库连接信息</div>

      <div class="row g-2 mb-2">
        <div class="col-8">
          <label class="form-label">数据库主机</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-hdd-network"></i></span>
            <input type="text" name="db_host" class="form-control"
                   value="<?= htmlspecialchars($host) ?>" placeholder="localhost" required>
          </div>
        </div>
        <div class="col-4">
          <label class="form-label">端口（可选）</label>
          <input type="text" class="form-control form-control-sm" placeholder="3306" disabled
                 style="opacity:.5" title="默认3306，如需修改请直接修改config.php">
        </div>
      </div>

      <div class="row g-2 mb-2">
        <div class="col-6">
          <label class="form-label">数据库用户名</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
            <input type="text" name="db_user" class="form-control"
                   value="<?= htmlspecialchars($user) ?>" required>
          </div>
        </div>
        <div class="col-6">
          <label class="form-label">数据库密码</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" name="db_pass" class="form-control" autocomplete="off">
          </div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">数据库名称</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-database"></i></span>
          <input type="text" name="db_name" class="form-control"
                 value="<?= htmlspecialchars($dbname) ?>" required>
        </div>
        <div class="form-text" style="color:var(--muted)">数据库不存在时将自动创建</div>
      </div>

      <div class="section-title mt-3"><span class="step-badge">2</span>设置后台管理员账号</div>

      <div class="mb-2">
        <label class="form-label">管理员用户名</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-person-circle"></i></span>
          <input type="text" name="admin_user" class="form-control"
                 value="<?= htmlspecialchars($adminUser) ?>"
                 placeholder="admin" required autocomplete="off">
        </div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label">密码 <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" name="admin_pass" id="adminPass" class="form-control"
                   placeholder="至少6位" required autocomplete="new-password">
          </div>
        </div>
        <div class="col-6">
          <label class="form-label">确认密码 <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input type="password" name="admin_pass2" id="adminPass2" class="form-control"
                   placeholder="再次输入" required autocomplete="new-password">
          </div>
        </div>
      </div>
      <div id="passError" class="text-danger small mb-2" style="display:none">
        <i class="bi bi-exclamation-circle me-1"></i>两次密码不一致
      </div>

      <!-- 将创建的数据库结构预览 -->
      <div class="mb-3 p-3" style="background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.15);border-radius:8px">
        <div style="font-size:.75rem;color:#6366f1;font-weight:600;margin-bottom:6px;">安装后将创建以下数据库结构 (v1.2)</div>
        <ul class="feature-list">
          <li>ai_models / novels / chapters / writing_logs（基础表）</li>
          <li>story_outlines — 全书故事大纲表</li>
          <li>volume_outlines — 卷大纲表（中层规划）</li>
          <li>chapter_synopses — 章节详细简介表</li>
          <li>arc_summaries — 弧段故事线摘要表（L2记忆）</li>
          <li><strong>novel_characters — 角色库（含功能模板/出场章节）</strong></li>
          <li><strong>novel_worldbuilding — 世界观库</strong></li>
          <li><strong>novel_plots — 情节库（含伏笔类型/回收章节）</strong></li>
          <li><strong>novel_style — 风格库（含四维向量/参考作者/高频词）</strong></li>
          <li><strong>novel_embeddings — 向量存储表（语义搜索）</strong></li>
          <li>character_cards — 人物状态卡片表（记忆引擎）</li>
          <li>character_card_history — 人物变更历史表</li>
          <li>foreshadowing_items — 伏笔独立表</li>
          <li>novel_state — 小说状态表</li>
          <li>memory_atoms — 原子记忆表</li>
          <li>book_analyses — 拆书分析表</li>
          <li><strong>chapter_versions — 章节版本快照表</strong></li>
          <li><strong>consistency_logs — 一致性检测日志表</strong></li>
          <li>system_settings — 系统设置表（含写作参数默认值）</li>
          <li>ai_models 扩展：thinking_enabled / can_embed / embedding_model_name / embedding_dim</li>
        </ul>
      </div>

      <button type="submit" class="btn btn-primary btn-install w-100 mt-1">
        <i class="bi bi-lightning-charge me-1"></i>一键安装
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
    var p1 = document.getElementById('adminPass');
    var p2 = document.getElementById('adminPass2');
    var err = document.getElementById('passError');
    if (!p1) return;
    function check(){ if(p2.value && p1.value !== p2.value){ err.style.display=''; } else { err.style.display='none'; } }
    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
    document.getElementById('installForm').addEventListener('submit', function(e){
        if (p1.value !== p2.value) { e.preventDefault(); err.style.display=''; }
    });
})();
</script>
</body>
</html>