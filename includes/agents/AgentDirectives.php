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
        ?int $expiresInHours = null,
        string $source = 'system'
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

            // 同时记录到 agent_action_logs（用于决策时间线显示）
            self::logAction($novelId, $type, $source, $directive, $applyFrom, $applyTo);

            return true;
        } catch (\Throwable $e) {
            error_log("添加Agent指令失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 记录动作日志到 agent_action_logs
     */
    private static function logAction(
        int $novelId,
        string $type,
        string $source,
        string $directive,
        int $applyFrom,
        int $applyTo
    ): void {
        try {
            $actionMap = [
                'quality' => '质量改进指令',
                'strategy' => '策略调整指令',
                'optimization' => '优化指令',
                'urgent' => '紧急修复指令',
                'global' => '全局指令',
            ];

            DB::insert('agent_action_logs', [
                'novel_id' => $novelId,
                'agent_type' => $source,
                'action' => $actionMap[$type] ?? "{$type}指令",
                'status' => 'success',
                'params' => json_encode([
                    'type' => $type,
                    'apply_from' => $applyFrom,
                    'apply_to' => $applyTo,
                    'directive_preview' => mb_substr($directive, 0, 100),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log("AgentDirectives日志记录失败: " . $e->getMessage());
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
                    SUM(CASE WHEN type = "urgent" THEN 1 ELSE 0 END) as urgent_count,
                    SUM(CASE WHEN type = "quality" THEN 1 ELSE 0 END) as quality_count,
                    SUM(CASE WHEN type = "strategy" THEN 1 ELSE 0 END) as strategy_count,
                    SUM(CASE WHEN type = "optimization" THEN 1 ELSE 0 END) as optimization_count,
                    SUM(CASE WHEN type = "global" THEN 1 ELSE 0 END) as global_count
                 FROM agent_directives 
                 WHERE novel_id = ?',
                [$now, $novelId]
            );
            
            return $stats ?: [
                'total' => 0,
                'active' => 0,
                'urgent_count' => 0,
                'quality_count' => 0,
                'strategy_count' => 0,
                'optimization_count' => 0,
                'global_count' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'total' => 0,
                'active' => 0,
                'urgent_count' => 0,
                'quality_count' => 0,
                'strategy_count' => 0,
                'optimization_count' => 0,
                'global_count' => 0,
            ];
        }
    }
    
    /**
     * 记录指令效果反馈
     * 
     * 在章节完成后评估当前有效的Agent指令对质量的影响。
     * 
     * @param int $novelId 小说ID
     * @param int $chapterNumber 当前章节号
     * @return array{recorded: int, outcomes: array} 记录数及详情
     */
    public static function recordOutcomes(int $novelId, int $chapterNumber): array
    {
        $recorded = 0;
        $outcomes = [];
        try {
            // 1. 获取当前章节的 quality_score / tokens_used / duration_ms
            $chapter = DB::fetch(
                'SELECT quality_score, tokens_used, duration_ms FROM chapters 
                 WHERE novel_id = ? AND chapter_number = ? AND status = "completed"',
                [$novelId, $chapterNumber]
            );
            if (empty($chapter)) {
                return ['recorded' => 0, 'outcomes' => []];
            }
            $currentQuality = $chapter['quality_score'] !== null ? (float)$chapter['quality_score'] : null;
            $tokensUsed = (int)($chapter['tokens_used'] ?? 0);
            $durationMs = (int)($chapter['duration_ms'] ?? 0);
            
            if ($currentQuality === null) {
                return ['recorded' => 0, 'outcomes' => []];
            }
            
            // 2. 计算基线质量（前5章平均 quality_score）
            $baseline = DB::fetch(
                'SELECT AVG(quality_score) as avg_q FROM chapters 
                 WHERE novel_id = ? AND chapter_number < ? AND quality_score IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT 5',
                [$novelId, $chapterNumber]
            );
            $qualityBefore = $baseline && $baseline['avg_q'] !== null ? round((float)$baseline['avg_q'], 1) : null;
            
            // 如果没有前面的章节作为基线，不记录效果反馈（第一章没有对比）
            if ($qualityBefore === null) {
                return ['recorded' => 0, 'outcomes' => []];
            }
            
            // 3. 获取本章有效的指令
            $activeDirectives = self::active($novelId, $chapterNumber);
            
            foreach ($activeDirectives as $d) {
                // 检查是否已评估过（幂等）
                $exists = DB::fetch(
                    'SELECT id FROM agent_directive_outcomes WHERE novel_id = ? AND directive_id = ? AND chapter_number = ?',
                    [$novelId, (int)$d['id'], $chapterNumber]
                );
                if ($exists) continue;
                
                $qualityChange = round($currentQuality - $qualityBefore, 1);
                DB::insert('agent_directive_outcomes', [
                    'novel_id' => $novelId,
                    'directive_id' => (int)$d['id'],
                    'chapter_number' => $chapterNumber,
                    'quality_before' => $qualityBefore,
                    'quality_after' => $currentQuality,
                    'quality_change' => $qualityChange,
                    'tokens_used' => $tokensUsed,
                    'duration_ms' => $durationMs,
                ]);
                $outcomes[] = [
                    'directive_id' => (int)$d['id'],
                    'type' => $d['type'],
                    'quality_change' => $qualityChange,
                ];
                $recorded++;
            }
        } catch (\Throwable $e) {
            error_log("记录Agent指令效果失败: " . $e->getMessage());
        }
        return ['recorded' => $recorded, 'outcomes' => $outcomes];
    }
    
    /**
     * 查询指令效果历史
     * 
     * @param int $novelId 小说ID
     * @param array $options 查询选项: limit, type, directive_id, min_change
     * @return array 效果记录列表
     */
    public static function getOutcomes(int $novelId, array $options = []): array
    {
        try {
            $limit = max(1, min(200, (int)($options['limit'] ?? 50)));
            $where = 'WHERE o.novel_id = ?';
            $params = [$novelId];
            
            if (!empty($options['directive_id'])) {
                $where .= ' AND o.directive_id = ?';
                $params[] = (int)$options['directive_id'];
            }
            if (!empty($options['type'])) {
                $where .= ' AND d.type = ?';
                $params[] = $options['type'];
            }
            if (isset($options['min_change'])) {
                $where .= ' AND o.quality_change >= ?';
                $params[] = (float)$options['min_change'];
            }
            
            return DB::fetchAll(
                "SELECT o.*, d.type as directive_type, d.directive 
                 FROM agent_directive_outcomes o 
                 LEFT JOIN agent_directives d ON o.directive_id = d.id 
                 {$where} ORDER BY o.evaluated_at DESC LIMIT ?",
                array_merge($params, [$limit])
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取指令效果聚合统计
     * 
     * @param int $novelId 小说ID
     * @return array{by_type: array, top_effective: array, top_harmful: array}
     */
    public static function getOutcomeStats(int $novelId): array
    {
        try {
            // 按类型统计
            $byType = DB::fetchAll(
                'SELECT d.type, 
                    COUNT(*) as outcome_count,
                    AVG(o.quality_change) as avg_change,
                    SUM(CASE WHEN o.quality_change > 0 THEN 1 ELSE 0 END) as improved,
                    SUM(CASE WHEN o.quality_change < 0 THEN 1 ELSE 0 END) as declined
                 FROM agent_directive_outcomes o
                 JOIN agent_directives d ON o.directive_id = d.id
                 WHERE o.novel_id = ?
                 GROUP BY d.type',
                [$novelId]
            ) ?: [];
            
            // 最有效的指令（改善最大）
            $topEffective = DB::fetchAll(
                'SELECT o.*, d.type, d.directive 
                 FROM agent_directive_outcomes o
                 JOIN agent_directives d ON o.directive_id = d.id
                 WHERE o.novel_id = ? AND o.quality_change > 0
                 ORDER BY o.quality_change DESC LIMIT 5',
                [$novelId]
            ) ?: [];
            
            // 最有副作用的指令
            $topHarmful = DB::fetchAll(
                'SELECT o.*, d.type, d.directive 
                 FROM agent_directive_outcomes o
                 JOIN agent_directives d ON o.directive_id = d.id
                 WHERE o.novel_id = ? AND o.quality_change < 0
                 ORDER BY o.quality_change ASC LIMIT 5',
                [$novelId]
            ) ?: [];
            
            return [
                'by_type' => $byType,
                'top_effective' => $topEffective,
                'top_harmful' => $topHarmful,
            ];
        } catch (\Throwable $e) {
            return ['by_type' => [], 'top_effective' => [], 'top_harmful' => []];
        }
    }
}