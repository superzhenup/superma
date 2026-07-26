<?php
/**
 * Schema 演进迁移：表结构变更（DROP INDEX / ADD INDEX / 列重命名）
 *
 * 从 DB::migrate() 提取（2026-06-17 重构）。
 * 这类迁移针对已存在的表做结构调整，幂等性依赖 SchemaMigrator::indexExists/columnExists。
 * 来源：db.php line 874-921
 */

require_once __DIR__ . '/../SchemaMigrator.php';

defined('APP_LOADED') or die('Direct access denied.');

class SchemaEvolutionMigrations
{
    public static function up(PDO $pdo, array &$errors): void
    {
        // novel_embeddings: 修复旧版 UNIQUE KEY。新安装已经是 uk_source，
        // 因此只能对真实存在的旧索引执行 DROP，避免幂等迁移误报失败。
        self::dropIndexIfExists($pdo, 'novel_embeddings', 'unique_source', $errors);

        self::addIndexIfMissing($pdo, 'novel_embeddings', 'uk_source',
            "ALTER TABLE `novel_embeddings` ADD UNIQUE KEY `uk_source` (`novel_id`, `source_type`, `source_id`)", $errors);

        // 审计修复 B08（2026-06-16）：MemoryEngine.semanticSearch 的 KB 候选池查询
        // `WHERE novel_id=? AND source_type IN (...) ORDER BY id DESC LIMIT N`
        // 原先仅靠 uk_source 部分匹配，ORDER BY id DESC 需要额外排序。
        // 新增 idx_ne_novel_type_id 复合索引让 WHERE+ORDER BY 走索引，
        // 千章书（KB 表上万行）的语义召回查询从全表扫描+filesort 降为索引范围扫描。
        self::addIndexIfMissing($pdo, 'novel_embeddings', 'idx_ne_novel_type_id',
            "ALTER TABLE `novel_embeddings` ADD INDEX `idx_ne_novel_type_id` (`novel_id`, `source_type`, `id`)", $errors);

        // [v10] 仅在目标列缺失且旧列真实存在时执行重命名。
        self::renameEmbeddingBlobColumn($pdo, $errors);
    }

    private static function dropIndexIfExists(PDO $pdo, string $table, string $index, array &$errors): void
    {
        try {
            if (SchemaMigrator::indexExists($pdo, $table, $index)) {
                $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
        } catch (\Throwable $e) {
            $message = "index:{$table}.drop_{$index} - " . $e->getMessage();
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

    private static function renameEmbeddingBlobColumn(PDO $pdo, array &$errors): void
    {
        try {
            if (!SchemaMigrator::columnExists($pdo, 'novel_embeddings', 'embedding_blob')) {
                if (SchemaMigrator::columnExists($pdo, 'novel_embeddings', 'blob')) {
                    $pdo->exec("ALTER TABLE `novel_embeddings` CHANGE `blob` `embedding_blob` LONGBLOB DEFAULT NULL COMMENT '向量数据（float32 二进制存储）'");
                } elseif (SchemaMigrator::columnExists($pdo, 'novel_embeddings', 'embedding')) {
                    $pdo->exec("ALTER TABLE `novel_embeddings` CHANGE `embedding` `embedding_blob` LONGBLOB DEFAULT NULL COMMENT '向量数据（float32 二进制存储）'");
                }
            }
        } catch (\Throwable $e) {
            $message = 'rename:novel_embeddings.embedding_blob - ' . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }
}
