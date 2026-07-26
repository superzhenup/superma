<?php
/**
 * 索引补齐迁移：ADD INDEX
 *
 * 从 DB::migrate() 提取（2026-06-17 重构）。
 * 复用 SchemaMigrator::indexExists / isIgnorableError 替代重复的 try/catch 模板。
 * 来源：db.php line 463-477, 752-770, 1238-1245
 */

require_once __DIR__ . '/../SchemaMigrator.php';

defined('APP_LOADED') or die('Direct access denied.');

class IndexMigrations
{
    public static function up(PDO $pdo, array &$errors): void
    {
        // [v25] foreshadowing_items.priority 索引（加速按优先级查询未回收伏笔）
        self::addIndexIfMissing($pdo, 'foreshadowing_items', 'idx_priority',
            "ALTER TABLE `foreshadowing_items` ADD INDEX `idx_priority` (`novel_id`, `priority`)", $errors);

        // [v47] novels.user_id 索引（加速按用户查询小说列表 / 归属权校验）
        self::addIndexIfMissing($pdo, 'novels', 'idx_user',
            "ALTER TABLE `novels` ADD INDEX `idx_user` (`user_id`)", $errors);

        // [legacy] novels.status 索引（加速按状态筛选小说）
        self::addIndexIfMissing($pdo, 'novels', 'idx_status',
            "ALTER TABLE `novels` ADD INDEX `idx_status` (`status`)", $errors);

        // [legacy] novels.updated_at 索引（加速按更新时间排序）
        self::addIndexIfMissing($pdo, 'novels', 'idx_updated',
            "ALTER TABLE `novels` ADD INDEX `idx_updated` (`updated_at`)", $errors);

        self::addIndexIfMissing($pdo, 'writing_logs', 'idx_novel_created',
            "ALTER TABLE `writing_logs` ADD INDEX `idx_novel_created` (`novel_id`, `created_at`)", $errors);

        // [v46] 图谱反向召回索引：优化 target_entity 命中和章节范围过滤
        self::addIndexIfMissing($pdo, 'story_relations', 'idx_target_chapter',
            "ALTER TABLE `story_relations` ADD INDEX `idx_target_chapter` (`novel_id`, `target_entity`, `source_chapter`)", $errors);

        // [v49] chapters 复合索引：覆盖高频查询 WHERE novel_id=? AND status='completed' [AND chapter_number < ?] ORDER BY chapter_number
        // 原 idx_novel_status(novel_id,status) 与 idx_novel_chapter(novel_id,chapter_number) 各自只能部分命中，
        // 新索引 (novel_id,status,chapter_number) 使范围扫描 + 排序走单一索引。
        self::addIndexIfMissing($pdo, 'chapters', 'idx_novel_status_chapter',
            "ALTER TABLE `chapters` ADD INDEX `idx_novel_status_chapter` (`novel_id`, `status`, `chapter_number`)", $errors);

        // [v52] 章号是小说内的业务主键。并发生成、导入或恢复不能产生两个同章号记录。
        // 若历史库已经存在重复项，拒绝自动删除用户正文并明确中止迁移，交由管理员
        // 先合并数据；干净数据库与无重复升级库则直接建立唯一约束。
        self::addChapterNumberUniqueIndex($pdo, $errors);

        // [v49] memory_atoms 复合索引：所有查询均为 novel_id + source_chapter 组合过滤，
        // 原单列 idx_chapter(source_chapter) 无法被 novel_id 谓词使用，替换为 (novel_id, source_chapter)。
        self::addIndexIfMissing($pdo, 'memory_atoms', 'idx_novel_chapter',
            "ALTER TABLE `memory_atoms` ADD INDEX `idx_novel_chapter` (`novel_id`, `source_chapter`)", $errors);
        // 旧单列索引在新复合索引就位后移除（仅存在于升级库，新建库 schema.php 已直接用复合索引）
        self::dropIndexIfExists($pdo, 'memory_atoms', 'idx_chapter', $errors);
    }

    /**
     * 删除索引（如存在）— 用于替换旧索引为新复合索引时的清理
     */
    private static function dropIndexIfExists(PDO $pdo, string $table, string $index, array &$errors): void
    {
        try {
            if (SchemaMigrator::indexExists($pdo, $table, $index)) {
                $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
        } catch (\Throwable $e) {
            $message = "drop_index:{$table}.{$index} - " . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }

    private static function addIndexIfMissing(PDO $pdo, string $table, string $index, string $sql, array &$errors): void
    {
        try {
            if (!SchemaMigrator::indexExists($pdo, $table, $index)) {
                $pdo->exec($sql);
            }
        } catch (\Throwable $e) {
            $message = "index:{$table}.{$index} - " . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }

    private static function addChapterNumberUniqueIndex(PDO $pdo, array &$errors): void
    {
        try {
            if (SchemaMigrator::indexExists($pdo, 'chapters', 'uk_novel_chapter')) {
                return;
            }

            $stmt = $pdo->query(
                'SELECT novel_id, chapter_number, COUNT(*) AS duplicate_count
                 FROM chapters
                 GROUP BY novel_id, chapter_number
                 HAVING COUNT(*) > 1
                 LIMIT 1'
            );
            $duplicate = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($stmt) {
                $stmt->closeCursor();
            }
            if ($duplicate) {
                $message = sprintf(
                    'unique:chapters.uk_novel_chapter - duplicate novel_id=%d chapter_number=%d count=%d; merge duplicate chapters before migration',
                    (int)$duplicate['novel_id'],
                    (int)$duplicate['chapter_number'],
                    (int)$duplicate['duplicate_count']
                );
                $errors[] = $message;
                error_log('DB Migrate: ' . $message);
                return;
            }

            $pdo->exec(
                'ALTER TABLE `chapters` ADD UNIQUE INDEX `uk_novel_chapter` (`novel_id`, `chapter_number`)'
            );
        } catch (\Throwable $e) {
            $message = 'unique:chapters.uk_novel_chapter - ' . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }
}
