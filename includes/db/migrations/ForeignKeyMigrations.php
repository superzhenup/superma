<?php
/**
 * 外键约束迁移：为核心表关系添加 FOREIGN KEY 约束
 *
 * 安全模式：
 *   1. 检查 FK 是否已存在（information_schema.TABLE_CONSTRAINTS）
 *   2. 清理孤儿记录（DELETE where parent doesn't exist）
 *   3. 添加 FK 约束（ON DELETE CASCADE）
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../SchemaMigrator.php';

class ForeignKeyMigrations
{
    public static function up(PDO $pdo, array &$errors = []): void
    {
        // 旧版三张子表使用有符号 INT，而父表主键为 INT UNSIGNED，MySQL 会拒绝
        // 添加外键。先做幂等类型对齐；新安装的 schema 已直接使用 UNSIGNED。
        self::ensureUnsignedInt($pdo, 'chapter_versions', 'chapter_id', $errors);
        self::ensureUnsignedInt($pdo, 'chapter_synopses', 'novel_id', $errors);
        self::ensureUnsignedInt($pdo, 'arc_summaries', 'novel_id', $errors);
        self::ensureUnsignedInt($pdo, 'novel_settings', 'novel_id', $errors);

        // chapters.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'chapters', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_chapters_novel_id', $errors);

        // chapter_versions.chapter_id -> chapters.id
        self::addForeignKeyIfMissing($pdo, 'chapter_versions', 'chapter_id', 'chapters', 'id', 'CASCADE', 'fk_chapter_versions_chapter_id', $errors);

        // character_cards.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'character_cards', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_character_cards_novel_id', $errors);

        // foreshadowing_items.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'foreshadowing_items', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_foreshadowing_items_novel_id', $errors);

        // memory_atoms.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'memory_atoms', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_memory_atoms_novel_id', $errors);

        // novel_state.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'novel_state', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_novel_state_novel_id', $errors);

        // story_outlines.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'story_outlines', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_story_outlines_novel_id', $errors);

        // chapter_synopses.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'chapter_synopses', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_chapter_synopses_novel_id', $errors);

        // arc_summaries.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'arc_summaries', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_arc_summaries_novel_id', $errors);

        // novel_settings.novel_id -> novels.id
        self::addForeignKeyIfMissing($pdo, 'novel_settings', 'novel_id', 'novels', 'id', 'CASCADE', 'fk_novel_settings_novel_id', $errors);
    }

    private static function ensureUnsignedInt(PDO $pdo, string $table, string $column, array &$errors): void
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$row) {
                $errors[] = "foreign-key-type:{$table}.{$column} - column not found";
                return;
            }
            if (stripos((string)$row['COLUMN_TYPE'], 'unsigned') !== false) {
                return;
            }

            $nullable = strtoupper((string)($row['IS_NULLABLE'] ?? 'NO')) === 'YES' ? ' NULL' : ' NOT NULL';
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` INT UNSIGNED{$nullable}");
        } catch (\Throwable $e) {
            $message = "foreign-key-type:{$table}.{$column} - " . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }

    /**
     * 安全添加外键：先检查是否已存在，清理孤儿记录，再添加约束
     */
    private static function addForeignKeyIfMissing(PDO $pdo, string $table, string $column, string $refTable, string $refColumn, string $onDelete, string $constraintName, array &$errors): void
    {
        try {
            // Check if FK already exists
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            );
            $stmt->execute([$table, $constraintName]);
            if ($stmt->fetch()) {
                $stmt->closeCursor();
                return; // FK already exists
            }
            $stmt->closeCursor();

            // Clean up orphan records before adding FK
            // 审计修复（2026-07-19 H-18）：原实现直接 DELETE 孤儿行，无计数、无备份、不可回退，
            // 历史 bug 产生的孤儿章节正文被不可逆静默删除。现改为先 COUNT + 日志，超阈值（100 行）
            // 时备份到 _orphans_bak 快照表再删除，保留可回退痕迹。
            $orphanSql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` NOT IN (SELECT `{$refColumn}` FROM `{$refTable}`)";
            try {
                $countStmt = $pdo->query($orphanSql);
                $orphanCount = $countStmt ? (int)$countStmt->fetchColumn() : 0;
            } catch (\Throwable $cErr) {
                error_log('DB Migrate orphan count failed: ' . $cErr->getMessage());
                $orphanCount = 0;
            }

            if ($orphanCount > 0) {
                error_log("DB Migrate orphan cleanup: {$table}.{$column} → {$refTable}.{$refColumn} 将删除 {$orphanCount} 行孤儿数据");
                if ($orphanCount > 100) {
                    // 显著数量时先备份，避免不可逆批量删除
                    $bakTable = "`{$table}_orphans_bak`";
                    try {
                        $pdo->exec("CREATE TABLE IF NOT EXISTS {$bakTable} LIKE `{$table}`");
                        $pdo->exec("INSERT IGNORE INTO {$bakTable} SELECT * FROM `{$table}` WHERE `{$column}` NOT IN (SELECT `{$refColumn}` FROM `{$refTable}`)");
                        error_log("DB Migrate orphan cleanup: 已备份 {$orphanCount} 行到 {$table}_orphans_bak");
                    } catch (\Throwable $bErr) {
                        // 备份失败则中止本次删除，避免无备份的大批量删除
                        $message = "foreign-key:{$table}.{$column} orphan backup failed, aborting delete: " . $bErr->getMessage();
                        $errors[] = $message;
                        error_log('DB Migrate: ' . $message);
                        return;
                    }
                }
            }

            $pdo->exec("DELETE FROM `{$table}` WHERE `{$column}` NOT IN (SELECT `{$refColumn}` FROM `{$refTable}`)");

            // Add the FK
            $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`{$column}`) REFERENCES `{$refTable}` (`{$refColumn}`) ON DELETE {$onDelete}");
        } catch (\Throwable $e) {
            $message = "foreign-key:{$table}.{$column} → {$refTable}.{$refColumn} - " . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }
}
