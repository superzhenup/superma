<?php
/**
 * ENUM 扩展迁移：MODIFY COLUMN ... ENUM(...)
 *
 * 从 DB::migrate() 提取（2026-06-17 重构）。
 * 这类迁移幂等性依赖"先查询当前 ENUM 定义，若已包含新值则跳过"。
 * 错误处理：ENUM 扩展失败不阻塞主迁移流程，仅记录 error_log（老库下次重试）。
 * 来源：db.php line 772-801, 968-1063
 */

defined('APP_LOADED') or die('Direct access denied.');

class EnumMigrations
{
    public static function up(PDO $pdo, array &$errors): void
    {
        // [v8] 扩展 memory_atoms.atom_type ENUM：新增 technique（功法）和 world_state（世界切换）
        self::extendEnumIfMissing($pdo, 'memory_atoms', 'atom_type',
            ["'technique'", "'world_state'"],
            "ALTER TABLE `memory_atoms` MODIFY COLUMN `atom_type` ENUM(
                'character_trait','world_setting','plot_detail','style_preference',
                'constraint','technique','world_state'
             ) NOT NULL", 'v8');

        // [v14] 补全 memory_atoms.atom_type ENUM：添加 cool_point（v8迁移遗漏）
        self::extendEnumIfMissing($pdo, 'memory_atoms', 'atom_type',
            ["'cool_point'"],
            "ALTER TABLE `memory_atoms` MODIFY COLUMN `atom_type` ENUM(
                'character_trait','world_setting','plot_detail','style_preference',
                'constraint','technique','world_state','cool_point'
             ) NOT NULL", 'v14');

        // [v14] novel_plots.status ENUM 扩展：添加 planted（已埋设）和 resolving（回收中）
        self::extendEnumIfMissing($pdo, 'novel_plots', 'status',
            ["'planted'", "'resolving'"],
            "ALTER TABLE `novel_plots` MODIFY COLUMN `status` ENUM(
                'planted','active','resolving','resolved','abandoned'
             ) NOT NULL DEFAULT 'active'", 'v14-status');

        // [v14] novel_plots.event_type ENUM 更新：'side' → 'subplot'，添加 'other'
        self::migrateEventTypeEnum($pdo);

        // [v14] novel_style.category 从 VARCHAR(30) 迁移为 ENUM（与代码一致）
        self::convertStyleCategoryToEnum($pdo);
    }

    /**
     * 通用 ENUM 扩展：若当前 ENUM 定义缺少任一目标值，则执行 MODIFY。
     */
    private static function extendEnumIfMissing(PDO $pdo, string $table, string $col, array $requiredValues, string $modifySql, string $label): void
    {
        try {
            // 审计修复 H-3（2026-07-01）：改用预处理语句，消除 SQL 字符串插值风险
            $stmt = $pdo->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?"
            );
            $stmt->execute([$table, $col]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if ($colType === '') {
                return; // 列不存在，跳过（由 ColumnMigrations 负责 ADD）
            }
            $needAlter = false;
            foreach ($requiredValues as $val) {
                if (strpos($colType, $val) === false) {
                    $needAlter = true;
                    break;
                }
            }
            if ($needAlter) {
                $pdo->exec($modifySql);
            }
        } catch (\Throwable $e) {
            error_log("DB Migrate: {$table}.{$col}({$label}) ENUM 扩展失败 — " . $e->getMessage());
        }
    }

    /**
     * [v14] novel_plots.event_type: 'side' → 'subplot' + 添加 'other'
     * 需先 UPDATE 已有数据，再 MODIFY ENUM 定义。
     */
    private static function migrateEventTypeEnum(PDO $pdo): void
    {
        try {
            // 审计修复 H-3（2026-07-01）：改用预处理语句
            $stmt = $pdo->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?"
            );
            $stmt->execute(['novel_plots', 'event_type']);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if ($colType === '') {
                return;
            }
            $needAlter = (strpos($colType, "'subplot'") === false) || (strpos($colType, "'other'") === false);
            if (!$needAlter) {
                return;
            }
            // 先将已有的 'side' 值更新为 'subplot'
            try {
                $pdo->exec("UPDATE `novel_plots` SET `event_type`='subplot' WHERE `event_type`='side'");
            } catch (\Throwable $e) {
                error_log('DB Migrate: 更新 novel_plots.event_type 失败 — ' . $e->getMessage());
            }
            $pdo->exec(
                "ALTER TABLE `novel_plots` MODIFY COLUMN `event_type` ENUM(
                    'main','subplot','foreshadowing','callback','other'
                 ) NOT NULL DEFAULT 'main'"
            );
        } catch (\Throwable $e) {
            error_log('DB Migrate: novel_plots.event_type ENUM 更新失败 — ' . $e->getMessage());
        }
    }

    /**
     * [v14] novel_style.category 从 VARCHAR(30) 迁移为 ENUM
     */
    private static function convertStyleCategoryToEnum(PDO $pdo): void
    {
        try {
            // 审计修复 H-3（2026-07-01）：改用预处理语句
            $stmt = $pdo->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?"
            );
            $stmt->execute(['novel_style', 'category']);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if ($colType === '') {
                return;
            }
            if (strpos($colType, 'enum') === false) {
                $pdo->exec(
                    "ALTER TABLE `novel_style` MODIFY COLUMN `category` ENUM(
                        'narrative','dialogue','description','emotion','other'
                     ) NOT NULL DEFAULT 'other'"
                );
            }
        } catch (\Throwable $e) {
            error_log('DB Migrate: novel_style.category ENUM 转换失败 — ' . $e->getMessage());
        }
    }
}
