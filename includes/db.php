<?php
defined('APP_LOADED') or die('Direct access denied.');

class DB {
    private static ?PDO $pdo = null;

    /**
     * 允许的表名白名单（防止表名注入）
     *
     * v1.5.2: 改为从 Schema::whitelist() 派生为单一真理源。
     * 保留 ALLOWED_TABLES 常量作为兜底——当 Schema 类不可用时（极少见）
     * 仍能保护数据库。新增表时只需在 Schema::tables() 一处添加。
     */
    private const ALLOWED_TABLES = [
        'novels', 'chapters', 'chapter_versions', 'chapter_synopses',
        'ai_models', 'system_settings', 'novel_characters', 'novel_worldbuilding',
        'novel_embeddings', 'character_cards', 'character_card_history',
        'foreshadowing_items', 'novel_state', 'memory_atoms',
        'arc_summaries', 'story_outlines', 'volume_outlines',
        'consistency_logs', 'writing_logs', 'novel_plots', 'novel_style',
        // v1.4: Agent 体系表 + 书籍分析表
        'agent_decision_logs', 'agent_action_logs',
        'agent_directives', 'book_analyses',
        // v1.5: Agent 反馈闭环
        'agent_directive_outcomes', 'agent_performance_stats',
        // v1.3.5: 约束框架
        'constraint_state', 'constraint_logs',
        // v1.7: 作者画像系统表
        'author_profiles', 'author_writing_habits', 'author_narrative_styles',
        'author_sentiment_analysis', 'author_creative_identity', 'author_uploaded_works',
        // v1.1: 迭代改进设置表
        'iterative_settings',
        // v1.10.3: PID控制器状态表
        'pid_states',
        // v1.10.3: 金句表
        'novel_catchphrases',
        // v1.10.3: 场景模板使用记录表
        'novel_scene_templates',
        // v1.11.1: 使用统计表（远程上报数据源）
        'usage_stats',
        // v1.11.2: 角色情绪历史表（情绪连续性）
        'character_emotion_history',
        // v1.11.5: 伏笔提及日志表（支持重写回滚）
        'foreshadowing_mention_log',
        // v1.11.5: 金句回调日志表（支持重写回滚）
        'catchphrase_callback_log',
    ];

    /**
     * v1.5.2: 获取允许的表名（优先用 Schema::whitelist 派生，回退到 ALLOWED_TABLES 常量）
     * @return string[]
     */
    private static function getAllowedTables(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        // 优先用 Schema 单一真理源
        $schemaFile = __DIR__ . '/schema.php';
        if (is_readable($schemaFile)) {
            require_once $schemaFile;
            if (class_exists('Schema') && method_exists('Schema', 'whitelist')) {
                try {
                    $cache = Schema::whitelist();
                    return $cache;
                } catch (\Throwable $e) {
                    error_log("Schema::whitelist failed, fallback to ALLOWED_TABLES: " . $e->getMessage());
                }
            }
        }

        // 回退到硬编码常量
        $cache = self::ALLOWED_TABLES;
        return $cache;
    }

    /**
     * 校验表名是否在白名单中，防止表名 SQL 注入
     */
    private static function validateTable(string $table): void {
        if (!in_array($table, self::getAllowedTables(), true)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    /**
     * 校验列名格式，防止列名 SQL 注入
     * 仅允许字母、数字、下划线组成，且以字母或下划线开头
     */
    private static function validateColumns(array $data): void {
        foreach (array_keys($data) as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
        }
    }

    public static function connect(): PDO {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES     => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            // MySQL 5.7+ 版本检测（5.7 对 JSON/utf8mb4 支持完整，5.6 及以下有缺陷）
            $version = self::$pdo->query('SELECT VERSION()')->fetchColumn();
            if ($version && preg_match('/(\d+)\.(\d+)/', $version, $m)) {
                $major = (int)$m[1];
                $minor = (int)$m[2];
                if ($major < 5 || ($major === 5 && $minor < 7)) {
                    error_log("AI小说系统警告：MySQL 版本 {$version} 过低，建议升级到 5.7+（当前可能缺少 JSON 类型支持）");
                }
                // MySQL 5.7 严格模式兼容：关闭 ONLY_FULL_GROUP_BY（避免复杂聚合查询报错）
                if ($major === 5 && $minor === 7) {
                    try {
                        self::$pdo->exec("SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''), 'STRICT_TRANS_TABLES', ''), 'NO_ZERO_DATE', '')");
                    } catch (\Throwable $e) {
                        error_log('DB: sql_mode 设置失败 — ' . $e->getMessage());
                    }
                }
            }

            self::migrate();
        }
        return self::$pdo;
    }

    /**
     * 自动迁移：补齐数据库缺失的列，兼容旧版本数据库
     * 新增：pending_foreshadowing（待回收伏笔）、story_momentum（故事势能）字段
     *
     * 性能优化：使用版本锁文件 + DB advisory lock 双保险，避免并发迁移。
     * 迁移完成后后续每次请求直接跳过全部检查，
     * 避免每次 PHP 请求都执行 9 次 information_schema 查询 + 5 次 CREATE TABLE IF NOT EXISTS。
     * 每次有结构变更时，递增 SCHEMA_VERSION 即可触发重新迁移。
     */
    private const SCHEMA_VERSION = 35;

    private static function migrate(): void {
        // 优先使用数据库记录迁移状态，避免文件权限问题
        $pdo = self::$pdo;
        
        // 检查 system_settings 表是否存在
        $tableExistsStmt = $pdo->query("SHOW TABLES LIKE 'system_settings'");
        $tableExists = $tableExistsStmt->fetch();
        $tableExistsStmt->closeCursor(); // 必须关闭游标
        if ($tableExists) {
            // 检查迁移状态
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute(['schema_version_migrated']);
            $migratedVersion = $stmt->fetchColumn();
            if ($migratedVersion !== false && (int)$migratedVersion >= self::SCHEMA_VERSION) {
                return; // 已迁移，直接跳过
            }
        }
        
        // 回退到文件锁检查（兼容旧版本）
        $storageDir = defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__) . '/storage';
        $lockFile   = $storageDir . '/schema_v' . self::SCHEMA_VERSION . '.lock';

        if (file_exists($lockFile)) {
            // 如果锁文件存在，也在数据库中记录状态（如果表存在）
            if ($tableExists) {
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute(['schema_version_migrated', (string)self::SCHEMA_VERSION, (string)self::SCHEMA_VERSION]);
            }
            return;
        }

        // ========== 数据库级 advisory lock（防并发迁移） ==========
        $locked = false;
        try {
            $lockStmt = $pdo->query("SELECT GET_LOCK('db_migrate_v" . self::SCHEMA_VERSION . "', 10)");
            $lockResult = $lockStmt->fetchColumn();
            $lockStmt->closeCursor(); // 必须关闭游标
            $locked = ($lockResult == 1);
            if (!$locked) {
                error_log('DB Migrate: 未能获取迁移锁，另一进程可能正在迁移');
                return;
            }
        } catch (\Throwable $e) {
            error_log('DB Migrate: GET_LOCK 失败 — ' . $e->getMessage());
        }

        if (!$locked) {
            return;
        }

        try {
            // ========== 所有迁移操作在锁保护下执行 ==========

        $columns = [
            // novels 表
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
            // v6: 以下 4 个字段已由 MemoryEngine 专用表取代（character_cards /
            // memory_atoms / foreshadowing_items / novel_state），不再自动迁移添加。
            // 保留此注释作为历史记录，避免回滚时误重新加入。

            // [v12] 挂机写作守护进程控制字段
            ['novels', 'daemon_write',
             "ALTER TABLE `novels` ADD COLUMN `daemon_write` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用挂机写作' AFTER `cancel_flag`"],
            // [v15] chapters 表补全缺失字段（pacing/suspense/hook_type 等，旧库升级遗漏）
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
            // [v18] OptimizationAgent 数据基础：章节 token 用量 + 生成耗时
            ['chapters', 'tokens_used',
             "ALTER TABLE `chapters` ADD COLUMN `tokens_used` INT NOT NULL DEFAULT 0 COMMENT 'AI生成本章消耗的token总数' AFTER `gate_results`"],
            ['chapters', 'duration_ms',
             "ALTER TABLE `chapters` ADD COLUMN `duration_ms` INT NOT NULL DEFAULT 0 COMMENT '本章生成耗时(毫秒)' AFTER `tokens_used`"],
            // [v20] 写作算法反馈闭环：情绪密度统计（激活 EmotionDict）
            ['chapters', 'emotion_density',
             "ALTER TABLE `chapters` ADD COLUMN `emotion_density` JSON DEFAULT NULL COMMENT '情绪词频统计(各类别次/万字)' AFTER `duration_ms`"],
            ['chapters', 'emotion_score',
             "ALTER TABLE `chapters` ADD COLUMN `emotion_score` DECIMAL(4,1) DEFAULT NULL COMMENT '情绪密度评分(0-100)' AFTER `emotion_density`"],
            // [v1.6] 写作算法反馈闭环：爽点实际类型识别（P1#7）
            ['chapters', 'actual_cool_point_types',
             "ALTER TABLE `chapters` ADD COLUMN `actual_cool_point_types` JSON DEFAULT NULL COMMENT '实际检测到的爽点类型(关键词匹配)' AFTER `emotion_score`"],
            // [v15] novels 表补全缺失字段（style_vector/ref_author，旧库升级遗漏）
            ['novels', 'style_vector',
             "ALTER TABLE `novels` ADD COLUMN `style_vector` TEXT DEFAULT NULL COMMENT '四维风格向量(JSON)' AFTER `cover_color`"],
            ['novels', 'ref_author',
             "ALTER TABLE `novels` ADD COLUMN `ref_author` VARCHAR(200) DEFAULT NULL COMMENT '参考作者' AFTER `style_vector`"],
            // [v16] 封面图片字段
            ['novels', 'cover_image',
             "ALTER TABLE `novels` ADD COLUMN `cover_image` VARCHAR(500) DEFAULT NULL COMMENT '封面图片路径' AFTER `cover_color`"],
            // [v22] agent_decision_logs 补全缺失的 novel_id 列（修复删除小说时报 1054 错误）
            // 使用 DEFAULT 0 兼容 strict mode 下已有行的 NOT NULL 约束
            ['agent_decision_logs', 'novel_id',
             "ALTER TABLE `agent_decision_logs` ADD COLUMN `novel_id` INT NOT NULL DEFAULT 0 COMMENT '小说ID' FIRST, ADD INDEX `idx_novel_id` (`novel_id`)"],
            // [v24] story_outlines 新增人物弧线终点字段
            ['story_outlines', 'character_endpoints',
             "ALTER TABLE `story_outlines` ADD COLUMN `character_endpoints` TEXT COMMENT '人物弧线终点' AFTER `character_arcs`"],
            // [v25] foreshadowing_items 新增 priority 字段（伏笔优先级）
            ['foreshadowing_items', 'priority',
             "ALTER TABLE `foreshadowing_items` ADD COLUMN `priority` ENUM('critical','major','minor') NOT NULL DEFAULT 'minor' COMMENT '伏笔优先级' AFTER `description`"],
            // [v26] chapters 新增 actual_opening_type 字段（实际开篇类型检测）
            ['chapters', 'actual_opening_type',
             "ALTER TABLE `chapters` ADD COLUMN `actual_opening_type` VARCHAR(30) DEFAULT NULL COMMENT '实际检测到的开篇类型' AFTER `opening_type`"],
            // [v27] 作者画像系统6张新表（Schema::applyAll 会自动创建，此处记录变更历史）
            // [v28] novels 表新增 author_profile_id 字段（绑定作者画像）
            ['novels', 'author_profile_id',
             "ALTER TABLE `novels` ADD COLUMN `author_profile_id` INT UNSIGNED DEFAULT NULL COMMENT '绑定的作者画像ID' AFTER `ref_author`"],
            // [v29] author_profiles 新增4个风格提示词字段（写作习惯/叙事手法/思想情感/创作个性）
            ['author_profiles', 'writing_habits_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `writing_habits_prompt` TEXT DEFAULT NULL COMMENT '写作习惯提示词' AFTER `influences`"],
            ['author_profiles', 'narrative_style_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `narrative_style_prompt` TEXT DEFAULT NULL COMMENT '叙事手法提示词' AFTER `writing_habits_prompt`"],
            ['author_profiles', 'sentiment_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `sentiment_prompt` TEXT DEFAULT NULL COMMENT '思想情感提示词' AFTER `narrative_style_prompt`"],
            ['author_profiles', 'creative_identity_prompt',
             "ALTER TABLE `author_profiles` ADD COLUMN `creative_identity_prompt` TEXT DEFAULT NULL COMMENT '创作个性提示词' AFTER `sentiment_prompt`"],

            // [v30] story_outlines 表新增 character_progression 字段（角色等级发展轨迹）
            ['story_outlines', 'character_progression',
             "ALTER TABLE `story_outlines` ADD COLUMN `character_progression` JSON DEFAULT NULL COMMENT '角色等级/境界发展轨迹' AFTER `character_endpoints`"],

            // [v31] chapters 表新增 v1.9 盲点修复字段
            ['chapters', 'rewritten',
             "ALTER TABLE `chapters` ADD COLUMN `rewritten` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被RewriteAgent重写过' AFTER `quality_score`"],
            ['chapters', 'critic_scores',
             "ALTER TABLE `chapters` ADD COLUMN `critic_scores` JSON DEFAULT NULL COMMENT 'CriticAgent读者视角评分' AFTER `rewritten`"],
            ['chapters', 'ai_pattern_issues',
             "ALTER TABLE `chapters` ADD COLUMN `ai_pattern_issues` JSON DEFAULT NULL COMMENT 'StyleGuard AI痕迹检测结果' AFTER `critic_scores`"],

            // [v32] chapters 表新增 v1.10 迭代精炼系统字段（之前在 update_iterative_refinement.php 独立脚本，
            // 现纳入主迁移流程。修复 IterativeRefinementController 写库时字段不存在导致全链路死代码的问题）
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
            // [v34] chapters 表新增认知负荷字段（信息密度管理）
            ['chapters', 'cognitive_load',
             "ALTER TABLE `chapters` ADD COLUMN `cognitive_load` JSON DEFAULT NULL COMMENT '认知负荷分析：新元素数量、累计趋势' AFTER `rewrite_time`"],
            // [v35] v1.11.5 重写日志子表（foreshadowing_mention_log + catchphrase_callback_log）
            // 此处仅升级 SCHEMA_VERSION 触发 Schema::applyAll() 自动建表，无字段 ALTER 需求
        ];

        foreach ($columns as [$table, $col, $sql]) {
            // 代码质量修复：改用参数化查询，消除字符串插值（虽然当前值为硬编码，
            // 参数化写法可防止未来扩展时引入注入风险，且意图更清晰）
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?"
            );
            $stmt->execute([$table, $col]);
            $has = (int)$stmt->fetchColumn();
            $stmt->closeCursor(); // 必须关闭游标才能执行后续 exec/prepare（native prepared statement 限制）
            if (!$has) {
                try { $pdo->exec($sql); } catch (\Throwable $e) { error_log('DB Migrate: 字段迁移失败 [' . $table . '.' . $col . '] — ' . $e->getMessage()); }
            }
        }

        // [v25] foreshadowing_items.priority 索引（加速按优先级查询未回收伏笔）
        try { $pdo->exec("ALTER TABLE `foreshadowing_items` ADD INDEX `idx_priority` (`novel_id`, `priority`)"); }
        catch (\Throwable $e) { error_log('DB Migrate: foreshadowing_items.idx_priority 索引创建失败 — ' . $e->getMessage()); }

        // 确保 arc_summaries 表存在（弧段摘要，每10章压缩一次）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `arc_summaries` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `arc_index` INT NOT NULL COMMENT '弧段编号，从1开始',
            `chapter_from` INT NOT NULL COMMENT '起始章节',
            `chapter_to` INT NOT NULL COMMENT '结束章节',
            `summary` TEXT COMMENT '200字弧段故事线摘要',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_arc` (`novel_id`, `arc_index`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 确保 story_outlines 表存在
        $pdo->exec("CREATE TABLE IF NOT EXISTS `story_outlines` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 确保 chapter_synopses 表存在
        $pdo->exec("CREATE TABLE IF NOT EXISTS `chapter_synopses` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // [v4] 确保 chapter_versions 表存在（版本快照）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `chapter_versions` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // [v4] 确保 consistency_logs 表存在（一致性检查日志）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `consistency_logs` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `chapter_number` INT NOT NULL,
            `check_type` VARCHAR(50),
            `issues` JSON,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_novel_id` (`novel_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // [v6] ai_models 表添加 embedding_enabled 字段
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'ai_models'
               AND COLUMN_NAME  = 'embedding_enabled'"
        );
        $stmt->execute();
        $has = (int)$stmt->fetchColumn();
        $stmt->closeCursor(); // 必须关闭游标才能执行后续 exec（native prepared statement 限制）
        if (!$has) {
            try {
                $pdo->exec("ALTER TABLE `ai_models` ADD COLUMN `embedding_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用Embedding模型' AFTER `is_default`");
            } catch (\Throwable $e) { error_log('DB Migrate: ai_models.embedding_enabled 迁移失败 — ' . $e->getMessage()); }
        }

        // [v7] MemoryEngine 核心表（原子记忆、人物卡片、伏笔）
        // 人物卡片表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `character_cards` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='人物状态卡片表'");

        // 人物卡片变更历史表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `character_card_history` (
            `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `card_id`               INT UNSIGNED NOT NULL,
            `chapter_number`        INT UNSIGNED NOT NULL COMMENT '变更发生的章节',
            `field_name`            VARCHAR(50) NOT NULL COMMENT '变更的字段名',
            `old_value`             TEXT COMMENT '旧值',
            `new_value`             TEXT COMMENT '新值',
            `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_card_chapter` (`card_id`, `chapter_number`),
            KEY `idx_field` (`card_id`, `field_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='人物卡片变更历史表'");

        // 伏笔独立表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `foreshadowing_items` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='伏笔独立表'");

        // 小说状态表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `novel_state` (
            `novel_id`              INT UNSIGNED PRIMARY KEY,
            `story_momentum`        VARCHAR(300) DEFAULT NULL COMMENT '当前故事势能/悬念一句话',
            `current_arc_summary`   TEXT DEFAULT NULL COMMENT '最近一个活跃弧段的摘要',
            `last_ingested_chapter` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近成功记忆化的章节号',
            `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说状态表'");

        // 原子记忆表（长尾知识存储）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `memory_atoms` (
            `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `novel_id`              INT UNSIGNED NOT NULL,
            `atom_type`             ENUM(
                                      'character_trait',
                                      'world_setting',
                                      'plot_detail',
                                      'style_preference',
                                      'constraint',
                                      'technique',
                                      'world_state'
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='原子记忆表'");

        // [v6] 智能知识库表
        // 角色库
        $pdo->exec("CREATE TABLE IF NOT EXISTS `novel_characters` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `name` VARCHAR(100) NOT NULL COMMENT '角色名称',
            `alias` VARCHAR(200) DEFAULT '' COMMENT '别名/昵称',
            `role_type` ENUM('protagonist','major','minor','background') DEFAULT 'minor' COMMENT '角色类型',
            `gender` VARCHAR(20) DEFAULT '' COMMENT '性别',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 世界观库（地点、势力、规则、物品）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `novel_worldbuilding` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `category` ENUM('location','faction','rule','item','other') NOT NULL COMMENT '类型',
            `name` VARCHAR(100) NOT NULL COMMENT '名称',
            `description` TEXT COMMENT '详细描述',
            `attributes` JSON COMMENT '属性（如地点坐标、势力等级等）',
            `related_chapters` JSON COMMENT '相关章节',
            `importance` TINYINT DEFAULT 1 COMMENT '重要程度 1-5',
            `notes` TEXT COMMENT '备注',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_novel_id` (`novel_id`),
            KEY `idx_category` (`category`),
            UNIQUE KEY `uk_novel_name_cat` (`novel_id`, `name`, `category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 情节库（关键事件、伏笔）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `novel_plots` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `chapter_from` INT NOT NULL COMMENT '起始章节',
            `chapter_to` INT DEFAULT NULL COMMENT '结束章节（伏笔回收章节）',
            `event_type` ENUM('main','subplot','foreshadowing','callback','other') DEFAULT 'main' COMMENT '事件类型',
            `title` VARCHAR(200) NOT NULL COMMENT '事件标题',
            `description` TEXT COMMENT '事件描述',
            `characters` JSON COMMENT '涉及角色',
            `status` ENUM('active','resolved','abandoned') DEFAULT 'active' COMMENT '状态',
            `importance` TINYINT DEFAULT 3 COMMENT '重要程度 1-5',
            `notes` TEXT COMMENT '备注',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_novel_id` (`novel_id`),
            KEY `idx_chapter_from` (`chapter_from`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_status` (`status`),
            UNIQUE KEY `uk_novel_title_type` (`novel_id`, `title`, `event_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 风格库（写作偏好、常用表达）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `novel_style` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `category` ENUM('narrative','dialogue','description','emotion','other') DEFAULT 'other' COMMENT '类型',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 向量存储表（统一存储所有知识的向量，v10 修复 blob 保留字）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `novel_embeddings` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `source_type` ENUM('character','worldbuilding','plot','style','chapter','other') NOT NULL COMMENT '来源类型',
            `source_id` INT NOT NULL COMMENT '来源ID',
            `content` TEXT NOT NULL COMMENT '原始文本',
            `embedding_blob` LONGBLOB COMMENT '向量数据（float32 二进制存储）',
            `embedding_model` VARCHAR(100) DEFAULT '' COMMENT '使用的Embedding模型',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_source` (`source_type`, `source_id`),
            KEY `idx_novel_id` (`novel_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 性能优化：为高频查询字段补充缺失索引
        try { $pdo->exec("ALTER TABLE `novels` ADD INDEX `idx_status` (`status`)"); }
        catch (\Throwable $e) { error_log('DB Migrate: novels.idx_status 索引创建失败 — ' . $e->getMessage()); }
        try { $pdo->exec("ALTER TABLE `novels` ADD INDEX `idx_updated` (`updated_at`)"); }
        catch (\Throwable $e) { error_log('DB Migrate: novels.idx_updated 索引创建失败 — ' . $e->getMessage()); }
        try { $pdo->exec("ALTER TABLE `writing_logs` ADD INDEX `idx_novel_created` (`novel_id`, `created_at`)"); }
        catch (\Throwable $e) { error_log('DB Migrate: writing_logs.idx_novel_created 索引创建失败 — ' . $e->getMessage()); }

        // [v8] 扩展 memory_atoms.atom_type ENUM：新增 technique（功法）和 world_state（世界切换）
        // 对老库做幂等 ALTER：若 ENUM 已包含新值则 MySQL 自身不会报错（仍会重写元数据但无副作用）；
        // 为避免不必要的元数据重写，先查询一次当前 ENUM 定义。
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'memory_atoms'
                   AND COLUMN_NAME  = 'atom_type'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            $needAlter = $colType !== '' &&
                (strpos($colType, "'technique'") === false
                 || strpos($colType, "'world_state'") === false);
            if ($needAlter) {
                $pdo->exec(
                    "ALTER TABLE `memory_atoms` MODIFY COLUMN `atom_type` ENUM(
                        'character_trait',
                        'world_setting',
                        'plot_detail',
                        'style_preference',
                        'constraint',
                        'technique',
                        'world_state'
                     ) NOT NULL"
                );
            }
        } catch (\Throwable $e) { error_log('DB Migrate: memory_atoms.atom_type(v8) ENUM 扩展失败 — ' . $e->getMessage()); }

        // [v9] 知识库扩展字段：角色功能模板 / 风格四维向量 / 伏笔类型
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
            // novel_embeddings: 新增 embedding_updated_at（用于懒触发器）
            ['novel_embeddings', 'embedding_updated_at',
             "ALTER TABLE `novel_embeddings` ADD COLUMN `embedding_updated_at` TIMESTAMP NULL DEFAULT NULL COMMENT '向量更新时间' AFTER `embedding_model`"],
            // [v1.10.3] character_cards: 新增 voice_profile（角色语音指纹）
            ['character_cards', 'voice_profile',
             "ALTER TABLE `character_cards` ADD COLUMN `voice_profile` JSON DEFAULT NULL COMMENT '角色语音指纹JSON' AFTER `attributes`"],
            // [v1.10.3] foreshadowing_items: 新增伏笔生命周期字段
            ['foreshadowing_items', 'last_mentioned_chapter',
             "ALTER TABLE `foreshadowing_items` ADD COLUMN `last_mentioned_chapter` INT DEFAULT NULL COMMENT '最近一次被提及的章节'"],
            ['foreshadowing_items', 'mention_count',
             "ALTER TABLE `foreshadowing_items` ADD COLUMN `mention_count` INT NOT NULL DEFAULT 0 COMMENT '被提及次数'"],
            // [v1.10.3] novels: 新增 target_reader（读者画像）
            ['novels', 'target_reader',
             "ALTER TABLE `novels` ADD COLUMN `target_reader` VARCHAR(30) NOT NULL DEFAULT 'general' COMMENT '目标读者画像'"],
            // [v1.10.3] chapters: 人工评分 + 校准后Critic评分
            ['chapters', 'human_critic_scores',
             "ALTER TABLE `chapters` ADD COLUMN `human_critic_scores` JSON DEFAULT NULL COMMENT '人工读者视角评分(5维)'"],
            ['chapters', 'calibrated_critic_scores',
             "ALTER TABLE `chapters` ADD COLUMN `calibrated_critic_scores` JSON DEFAULT NULL COMMENT '校准后的Critic评分'"],
        ];

        foreach ($alterColumns as [$table, $col, $sql]) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?"
            );
            $stmt->execute([$table, $col]);
            $has = (int)$stmt->fetchColumn();
            $stmt->closeCursor(); // 必须关闭游标才能执行后续 exec（native prepared statement 限制）
            if (!$has) {
                try { $pdo->exec($sql); } catch (\Throwable $e) { error_log('DB Migrate: 列迁移失败 [' . $table . '.' . $col . '] — ' . $e->getMessage()); }
            }
        }

        // novel_embeddings: 修复 UNIQUE KEY（当前是 source_type+source_id，不含 novel_id）
        try {
            $pdo->exec("ALTER TABLE `novel_embeddings` DROP INDEX `unique_source`");
        } catch (\Throwable $e) { error_log('DB Migrate: novel_embeddings DROP INDEX unique_source 失败 — ' . $e->getMessage()); }
        try {
            $pdo->exec("ALTER TABLE `novel_embeddings` ADD UNIQUE KEY `uk_source` (`novel_id`, `source_type`, `source_id`)");
        } catch (\Throwable $e) { error_log('DB Migrate: novel_embeddings ADD UNIQUE KEY uk_source 失败 — ' . $e->getMessage()); }

        // [v10] novel_embeddings: blob/embedding 列名改为 embedding_blob（避免 MySQL 保留字冲突）
        // 线上可能是 blob（早期版本触发报错的根源）或 embedding（v9 版本），两条都试
        try {
            $pdo->exec("ALTER TABLE `novel_embeddings` CHANGE `blob` `embedding_blob` LONGBLOB DEFAULT NULL COMMENT '向量数据（float32 二进制存储）'");
        } catch (\Throwable $e) { error_log('DB Migrate: novel_embeddings CHANGE blob 失败 — ' . $e->getMessage()); }
        try {
            $pdo->exec("ALTER TABLE `novel_embeddings` CHANGE `embedding` `embedding_blob` LONGBLOB DEFAULT NULL COMMENT '向量数据（float32 二进制存储）'");
        } catch (\Throwable $e) { error_log('DB Migrate: novel_embeddings CHANGE embedding 失败 — ' . $e->getMessage()); }

        // [v11] 写作参数全局设置：初始化 system_settings 中的 ws_ 前缀参数
        $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key`   VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT,
            `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // [v11] 写作参数全局设置：从集中配置获取默认值
        $wsDefaults = getWritingDefaults();
        $stmt = $pdo->prepare("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
        foreach ($wsDefaults as $key => $def) {
            $stmt->execute([$key, (string)$def['default']]);
        }

        // [v22] 约束框架默认配置：从集中配置获取默认值
        $cfDefaults = getConstraintDefaults();
        foreach ($cfDefaults as $key => $def) {
            $stmt->execute([$key, (string)$def['default']]);
        }

        // [v16] 图片生成 API 默认配置
        $imgGenDefaults = [
            'image_gen_api_url'        => '',
            'image_gen_api_key'        => '',
            'image_gen_model'          => 'gpt-image-2',
            'image_gen_size'           => '1024x1536',
            'image_gen_prompt_prefix'  => '',
        ];
        $stmtImg = $pdo->prepare("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
        foreach ($imgGenDefaults as $key => $val) {
            $stmtImg->execute([$key, $val]);
        }

        // [v14] 补全 memory_atoms.atom_type ENUM：添加 cool_point（v8迁移遗漏）
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'memory_atoms'
                   AND COLUMN_NAME  = 'atom_type'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if (strpos($colType, "'cool_point'") === false) {
                $pdo->exec(
                    "ALTER TABLE `memory_atoms` MODIFY COLUMN `atom_type` ENUM(
                        'character_trait',
                        'world_setting',
                        'plot_detail',
                        'style_preference',
                        'constraint',
                        'technique',
                        'world_state',
                        'cool_point'
                     ) NOT NULL"
                );
            }
        } catch (\Throwable $e) { error_log('DB Migrate: memory_atoms.atom_type(v14) ENUM 扩展失败 — ' . $e->getMessage()); }

        // [v14] novel_plots.status ENUM 扩展：添加 planted（已埋设）和 resolving（回收中）
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'novel_plots'
                   AND COLUMN_NAME  = 'status'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            $needAlter = $colType !== '' &&
                (strpos($colType, "'planted'") === false
                 || strpos($colType, "'resolving'") === false);
            if ($needAlter) {
                $pdo->exec(
                    "ALTER TABLE `novel_plots` MODIFY COLUMN `status` ENUM(
                        'planted','active','resolving','resolved','abandoned'
                     ) NOT NULL DEFAULT 'active'"
                );
            }
        } catch (\Throwable $e) { error_log('DB Migrate: novel_plots.status ENUM 扩展失败 — ' . $e->getMessage()); }

        // [v14] novel_plots.event_type ENUM 更新：'side' → 'subplot'，添加 'other'
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'novel_plots'
                   AND COLUMN_NAME  = 'event_type'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            $needAlter = $colType !== '' &&
                (strpos($colType, "'subplot'") === false
                 || strpos($colType, "'other'") === false);
            if ($needAlter) {
                // 先将已有的 'side' 值更新为 'subplot'
                try { $pdo->exec("UPDATE `novel_plots` SET `event_type`='subplot' WHERE `event_type`='side'"); } catch (\Throwable $e) {
                    error_log('DB Migrate: 更新 novel_plots.event_type 失败 — ' . $e->getMessage());
                }
                $pdo->exec(
                    "ALTER TABLE `novel_plots` MODIFY COLUMN `event_type` ENUM(
                        'main','subplot','foreshadowing','callback','other'
                     ) NOT NULL DEFAULT 'main'"
                );
            }
        } catch (\Throwable $e) { error_log('DB Migrate: novel_plots.event_type ENUM 更新失败 — ' . $e->getMessage()); }

        // [v14] novel_style.category 从 VARCHAR(30) 迁移为 ENUM（与代码一致）
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'novel_style'
                   AND COLUMN_NAME  = 'category'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if (strpos($colType, 'enum') === false) {
                $pdo->exec(
                    "ALTER TABLE `novel_style` MODIFY COLUMN `category` ENUM(
                        'narrative','dialogue','description','emotion','other'
                     ) NOT NULL DEFAULT 'other'"
                );
            }
        } catch (\Throwable $e) { error_log('DB Migrate: novel_style.category ENUM 转换失败 — ' . $e->getMessage()); }

        // [v14] novel_characters 字段对齐线上：alias VARCHAR(100) DEFAULT NULL, gender VARCHAR(10) DEFAULT NULL
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'novel_characters'
                   AND COLUMN_NAME  = 'alias'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if (strpos($colType, 'varchar(200)') !== false) {
                $pdo->exec("ALTER TABLE `novel_characters` MODIFY COLUMN `alias` VARCHAR(100) DEFAULT NULL COMMENT '别名/绰号'");
            }
        } catch (\Throwable $e) { error_log('DB Migrate: novel_characters.alias 字段对齐失败 — ' . $e->getMessage()); }
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'novel_characters'
                   AND COLUMN_NAME  = 'gender'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if (strpos($colType, 'varchar(20)') !== false) {
                $pdo->exec("ALTER TABLE `novel_characters` MODIFY COLUMN `gender` VARCHAR(10) DEFAULT NULL COMMENT '性别'");
            }
        } catch (\Throwable $e) { error_log('DB Migrate: novel_characters.gender 字段对齐失败 — ' . $e->getMessage()); }

        // [v14] novel_worldbuilding 字段对齐线上：name VARCHAR(200), importance DEFAULT 3
        try {
            $enumStmt = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'novel_worldbuilding'
                   AND COLUMN_NAME  = 'name'"
            );
            $row = $enumStmt->fetch();
            $enumStmt->closeCursor(); // 必须关闭游标
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if (strpos($colType, 'varchar(100)') !== false) {
                $pdo->exec("ALTER TABLE `novel_worldbuilding` MODIFY COLUMN `name` VARCHAR(200) NOT NULL COMMENT '名称'");
            }
        } catch (\Throwable $e) { error_log('DB Migrate: novel_worldbuilding.name 字段对齐失败 — ' . $e->getMessage()); }

        // 创建Agent决策机制表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `agent_decision_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `novel_id` INT NOT NULL COMMENT '小说ID',
            `agent_type` VARCHAR(50) NOT NULL COMMENT 'Agent类型: writing_strategy, quality_monitor, optimization',
            `decision_data` TEXT COMMENT '决策数据JSON',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            INDEX `idx_novel_id` (`novel_id`),
            INDEX `idx_agent_type` (`agent_type`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent决策日志表'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `agent_action_logs` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent动作日志表'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `agent_performance_stats` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent性能统计表'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `agent_directives` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent自然语言指令表'");

        // [v19] Agent指令效果反馈表（决策闭环核心）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `agent_directive_outcomes` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent指令效果反馈表'");

        // [v21] Schema 单一真理源兜底——确保 Schema::tables() 中所有表都存在
        // 即使上面 CREATE TABLE 列表漏了某张表，Schema::applyAll 也会补上。
        // 这是对"加表忘记同步 db.php"类问题的根本性防线。
        try {
            require_once __DIR__ . '/schema.php';
            if (class_exists('Schema')) {
                Schema::applyAll($pdo);
            }
        } catch (\Throwable $e) {
            error_log('DB Migrate: Schema::applyAll 兜底失败 — ' . $e->getMessage());
        }

        // 在数据库中记录迁移状态（避免文件权限问题）
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute(['schema_version_migrated', (string)self::SCHEMA_VERSION, (string)self::SCHEMA_VERSION]);
        } catch (\Throwable $e) {
            error_log('DB Migrate: schema_version_migrated 写入失败 — ' . $e->getMessage());
        }
        
        // 尝试写入版本锁文件（兼容旧版本），但忽略权限错误
        try {
            @file_put_contents($lockFile, 'schema_v' . self::SCHEMA_VERSION . ' migrated at ' . date('Y-m-d H:i:s') . PHP_EOL);
        } catch (\Throwable $e) {
            error_log('DB Migrate: 锁文件写入失败 — ' . $e->getMessage());
        }

        } finally {
            // 释放数据库迁移锁
            try {
                $pdo->exec("SELECT RELEASE_LOCK('db_migrate_v" . self::SCHEMA_VERSION . "')");
            } catch (\Throwable $e) {
                // 锁自动随连接释放
            }
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function execute(string $sql, array $params = []): int {
        $stmt = self::query($sql, $params);
        $count = $stmt->rowCount();
        $stmt->closeCursor();
        return $count;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetchAll();
        $stmt->closeCursor();
        return $result;
    }

    public static function fetch(string $sql, array $params = []) {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        $stmt->closeCursor();  // v1.11.8: 关闭游标，避免"unbuffered queries"错误
        return $result;
    }

    /**
     * 取出单一标量值（结果集第一行第一列），常用于 COUNT(*) 等聚合查询。
     * 找不到行时返回 false，与 PDOStatement::fetchColumn() 行为一致。
     */
    public static function fetchColumn(string $sql, array $params = []) {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetchColumn();
        $stmt->closeCursor();  // v1.11.8: 关闭游标，避免"unbuffered queries"错误
        return $result;
    }

    public static function insert(string $table, array $data): string {
        self::validateTable($table);
        self::validateColumns($data);
        $cols  = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
        $holes = implode(',', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($holes)", array_values($data));
        return self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        self::validateTable($table);
        self::validateColumns($data);
        $set  = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
        $stmt = self::query(
            "UPDATE `$table` SET $set WHERE $where",
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        self::validateTable($table);
        return self::query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }

    public static function count(string $table, string $where = '1', array $params = []): int {
        self::validateTable($table);
        $row = self::fetch("SELECT COUNT(*) AS n FROM `$table` WHERE $where", $params);
        return (int)($row['n'] ?? 0);
    }

    public static function lastId(): string {
        return self::connect()->lastInsertId();
    }

    public static function getPdo(): PDO {
        return self::connect();
    }

    public static function beginTransaction(): bool {
        return self::connect()->beginTransaction();
    }

    public static function commit(): bool {
        return self::connect()->commit();
    }

    public static function rollBack(): bool {
        return self::connect()->rollBack();
    }
}