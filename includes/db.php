<?php
defined('APP_LOADED') or die('Direct access denied.');

class DB {
    private static ?PDO $pdo = null;

    public static function connect(): PDO {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::migrate();
        }
        return self::$pdo;
    }

    /**
     * 自动迁移：补齐数据库缺失的列，兼容旧版本数据库
     * 新增：pending_foreshadowing（待回收伏笔）、story_momentum（故事势能）字段
     *
     * 性能优化：使用版本锁文件，迁移完成后后续每次请求直接跳过全部检查，
     * 避免每次 PHP 请求都执行 9 次 information_schema 查询 + 5 次 CREATE TABLE IF NOT EXISTS。
     * 每次有结构变更时，递增 SCHEMA_VERSION 即可触发重新迁移。
     */
    private const SCHEMA_VERSION = 4;

    private static function migrate(): void {
        // 检查版本锁文件，已迁移则直接跳过所有数据库检查（单次迁移后接近零开销）
        $storageDir = defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__) . '/storage';
        $lockFile   = $storageDir . '/schema_v' . self::SCHEMA_VERSION . '.lock';

        if (file_exists($lockFile)) {
            return;
        }

        // 确保 storage 目录存在（首次运行时创建）
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $pdo = self::$pdo;

        $columns = [
            // novels 表
            ['novels', 'cancel_flag',
             "ALTER TABLE `novels` ADD COLUMN `cancel_flag` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_words`"],
            ['novels', 'character_states',
             "ALTER TABLE `novels` ADD COLUMN `character_states` JSON COMMENT '人物状态卡片' AFTER `cancel_flag`"],
            ['novels', 'key_events',
             "ALTER TABLE `novels` ADD COLUMN `key_events` JSON COMMENT '全书关键事件日志' AFTER `character_states`"],
            ['novels', 'has_story_outline',
             "ALTER TABLE `novels` ADD COLUMN `has_story_outline` TINYINT(1) DEFAULT 0 COMMENT '是否已生成全书故事大纲' AFTER `model_id`"],
            // [新增] 待回收伏笔追踪
            ['novels', 'pending_foreshadowing',
             "ALTER TABLE `novels` ADD COLUMN `pending_foreshadowing` JSON COMMENT '待回收伏笔列表' AFTER `key_events`"],
            // [新增] 故事势能（当前悬念/冲突状态摘要）
            ['novels', 'story_momentum',
             "ALTER TABLE `novels` ADD COLUMN `story_momentum` VARCHAR(200) DEFAULT '' COMMENT '当前故事势能/悬念状态' AFTER `pending_foreshadowing`"],
            // chapters 表
            ['chapters', 'chapter_summary',
             "ALTER TABLE `chapters` ADD COLUMN `chapter_summary` TEXT COMMENT 'AI生成的章节摘要' AFTER `content`"],
            ['chapters', 'used_tropes',
             "ALTER TABLE `chapters` ADD COLUMN `used_tropes` TEXT COMMENT '本章已使用的意象(JSON数组)' AFTER `chapter_summary`"],
            ['chapters', 'synopsis_id',
             "ALTER TABLE `chapters` ADD COLUMN `synopsis_id` INT DEFAULT NULL COMMENT '章节简介ID' AFTER `hook`"],
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
            if (!$has) {
                try { $pdo->exec($sql); } catch (\Throwable $e) { /* 忽略迁移失败，不中断服务 */ }
            }
        }

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

        // [v4] 确保 foreshadowing_log 表存在（伏笔回收记录）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `foreshadowing_log` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `novel_id` INT NOT NULL,
            `chapter_id` INT NOT NULL,
            `chapter_number` INT NOT NULL,
            `foreshadowing_desc` TEXT,
            `is_resolved` TINYINT(1) DEFAULT 0,
            `resolved_chapter` INT DEFAULT NULL,
            `resolved_at` TIMESTAMP NULL,
            `deadline_chapter` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_novel_id` (`novel_id`),
            KEY `idx_is_resolved` (`is_resolved`)
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

        // 性能优化：为高频查询字段补充缺失索引
        try { $pdo->exec("ALTER TABLE `novels` ADD INDEX `idx_status` (`status`)"); }
        catch (\Throwable $e) { /* 已存在则忽略 */ }
        try { $pdo->exec("ALTER TABLE `novels` ADD INDEX `idx_updated` (`updated_at`)"); }
        catch (\Throwable $e) { /* 已存在则忽略 */ }
        try { $pdo->exec("ALTER TABLE `writing_logs` ADD INDEX `idx_novel_created` (`novel_id`, `created_at`)"); }
        catch (\Throwable $e) { /* 已存在则忽略 */ }

        // 迁移全部完成，写入版本锁文件，后续所有请求直接跳过 migrate()
        @file_put_contents($lockFile, 'schema_v' . self::SCHEMA_VERSION . ' migrated at ' . date('Y-m-d H:i:s') . PHP_EOL);
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function execute(string $sql, array $params = []): int {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetch(string $sql, array $params = []): array|false {
        return self::query($sql, $params)->fetch();
    }

    public static function insert(string $table, array $data): string {
        $cols  = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
        $holes = implode(',', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($holes)", array_values($data));
        return self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set  = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
        $stmt = self::query(
            "UPDATE `$table` SET $set WHERE $where",
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        return self::query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }

    public static function count(string $table, string $where = '1', array $params = []): int {
        $row = self::fetch("SELECT COUNT(*) AS n FROM `$table` WHERE $where", $params);
        return (int)($row['n'] ?? 0);
    }

    public static function lastId(): string {
        return self::connect()->lastInsertId();
    }

    public static function getPdo(): PDO {
        return self::connect();
    }
}
