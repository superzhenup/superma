<?php
/**
 * Agent指令管理类
 * 
 * 职责: 管理Agent生成的自然语言指令，实现Agent决策到写作流程的直接注入
 * 
 * 核心功能:
 * - 添加Agent指令
 * - 获取当前章节有效的指令
 * - 清理过期指令
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

class AgentDirectives
{
    /**
     * 添加Agent指令
     * 
     * @param int $novelId 小说ID
     * @param int $applyFrom 起始章节号
     * @param string $type 指令类型: quality/strategy/optimization
     * @param string $directive 自然语言指令内容
     * @param int $applyRange 生效范围（从applyFrom开始往后多少章）
     * @param int|null $expiresInHours 过期小时数（可选）
     * @return bool 是否添加成功
     */
    public static function add(
        int $novelId,
        int $applyFrom,
        string $type,
        string $directive,
        int $applyRange = 3,
        ?int $expiresInHours = null
    ): bool {
        try {
            $applyTo = $applyFrom + $applyRange - 1;
            $expiresAt = $expiresInHours 
                ? date('Y-m-d H:i:s', time() + $expiresInHours * 3600)
                : null;
            
            DB::insert('agent_directives', [
                'novel_id' => $novelId,
                'apply_from' => $applyFrom,
                'apply_to' => $applyTo,
                'type' => $type,
                'directive' => $directive,
                'expires_at' => $expiresAt,
                'is_active' => 1,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            error_log("添加Agent指令失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取当前章节有效的指令
     * 
     * @param int $novelId 小说ID
     * @param int $chapterNumber 当前章节号
     * @return array 有效指令列表
     */
    public static function active(int $novelId, int $chapterNumber): array
    {
        try {
            $now = date('Y-m-d H:i:s');
            
            $directives = DB::fetchAll(
                'SELECT id, type, directive, created_at 
                 FROM agent_directives 
                 WHERE novel_id = ? 
                   AND apply_from <= ? 
                   AND apply_to >= ?
                   AND is_active = 1
                   AND (expires_at IS NULL OR expires_at > ?)
                 ORDER BY created_at DESC',
                [$novelId, $chapterNumber, $chapterNumber, $now]
            );
            
            return $directives ?: [];
        } catch (\Throwable $e) {
            error_log("获取Agent指令失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取所有活跃指令（用于调试）
     * 
     * @param int $novelId 小说ID
     * @return array 所有活跃指令
     */
    public static function allActive(int $novelId): array
    {
        try {
            $now = date('Y-m-d H:i:s');
            
            return DB::fetchAll(
                'SELECT * FROM agent_directives 
                 WHERE novel_id = ? 
                   AND is_active = 1
                   AND (expires_at IS NULL OR expires_at > ?)
                 ORDER BY apply_from, created_at DESC',
                [$novelId, $now]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 停用指令
     * 
     * @param int $directiveId 指令ID
     * @return bool 是否停用成功
     */
    public static function deactivate(int $directiveId): bool
    {
        try {
            return DB::update(
                'agent_directives',
                ['is_active' => 0],
                'id = ?',
                [$directiveId]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清理过期指令
     * 
     * @param int $novelId 小说ID
     * @param int $olderThanDays 清理多少天前的指令
     * @return int 清理的数量
     */
    public static function cleanup(int $novelId, int $olderThanDays = 30): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - $olderThanDays * 86400);
            
            $deleted = DB::delete(
                'agent_directives',
                'novel_id = ? AND (expires_at < ? OR (created_at < ? AND is_active = 0))',
                [$novelId, $cutoff, $cutoff]
            );
            
            return $deleted;
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取指令统计信息
     * 
     * @param int $novelId 小说ID
     * @return array 统计信息
     */
    public static function getStats(int $novelId): array
    {
        try {
            $now = date('Y-m-d H:i:s');
            
            $stats = DB::fetch(
                'SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN type = "quality" THEN 1 ELSE 0 END) as quality_count,
                    SUM(CASE WHEN type = "strategy" THEN 1 ELSE 0 END) as strategy_count,
                    SUM(CASE WHEN type = "optimization" THEN 1 ELSE 0 END) as optimization_count
                 FROM agent_directives 
                 WHERE novel_id = ?',
                [$now, $novelId]
            );
            
            return $stats ?: [
                'total' => 0,
                'active' => 0,
                'quality_count' => 0,
                'strategy_count' => 0,
                'optimization_count' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'total' => 0,
                'active' => 0,
                'quality_count' => 0,
                'strategy_count' => 0,
                'optimization_count' => 0,
            ];
        }
    }
}