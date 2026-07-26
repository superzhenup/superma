<?php
/**
 * 数据库 Schema 迁移辅助工具
 *
 * 审计优化 P3-6（2026-06-16）：从 DB 类提取迁移相关的辅助方法到此独立类。
 * migrate() 方法体因体量较大（1000+ 行 DDL）保留在 DB 类原位，
 * 仅提取可独立复用的辅助检测方法，降低 DB 类耦合度。
 *
 * DB::migrate() 内部通过 SchemaMigrator::xxx() 调用本类方法。
 */

defined('APP_LOADED') or die('Direct access denied.');

class SchemaMigrator
{
    /**
     * 检查指定表的列是否存在
     */
    public static function columnExists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        try {
            return (int)$stmt->fetchColumn() > 0;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * 检查指定表的索引是否存在
     */
    public static function indexExists(PDO $pdo, string $table, string $index): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        $stmt->execute([$table, $index]);
        try {
            return (int)$stmt->fetchColumn() > 0;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * 判断迁移错误是否可忽略（列/索引/表已存在等幂等错误）
     *
     * 双重判定策略：
     *   1. 错误码匹配（最可靠，不受 MySQL 语言/版本影响）
     *      - 1050: Table already exists
     *      - 1060: Duplicate column name
     *      - 1061: Duplicate key name
     *      - SQLSTATE 42S01: Table already exists
     *   2. 字符串匹配（兜底，覆盖非 PDOException 或 errorInfo 缺失场景）
     */
    public static function isIgnorableError(\Throwable $e): bool {
        // 1. 错误码匹配（最可靠，不受 MySQL 语言/版本影响）
        if ($e instanceof \PDOException) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, [1050, 1060, 1061], true)) {
                return true;
            }
            $sqlstate = $e->errorInfo[0] ?? '';
            if ($sqlstate === '42S01') {
                return true;
            }
        }

        // 2. 字符串匹配（兜底）
        $message = strtolower($e->getMessage());
        foreach (['already exists', 'duplicate column name', 'duplicate key name'] as $fingerprint) {
            if (str_contains($message, $fingerprint)) return true;
        }
        return false;
    }
}
