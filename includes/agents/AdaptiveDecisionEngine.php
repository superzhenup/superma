<?php
/**
 * 自适应决策引擎
 *
 * 核心功能：
 * 1. 效果驱动：根据历史指令效果调整决策权重
 * 2. 趋势预测：基于历史数据预测潜在问题
 * 3. 知识注入：匹配专家知识生成专业建议
 *
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

class AdaptiveDecisionEngine
{
    private int $novelId;
    private ?array $cachedOutcomes = null;
    private ?array $cachedTrends = null;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    // ============================================================
    // 模块1：效果驱动的自适应权重
    // ============================================================

    /**
     * 根据历史效果计算决策权重
     *
     * @param string $decisionType 决策类型：strategy/quality/optimization
     * @return float 权重系数 (0.3 ~ 1.5)
     */
    public function getAdaptiveWeight(string $decisionType): float
    {
        $outcomes = $this->getOutcomeStats();

        $typeStats = $outcomes['by_type'][$decisionType] ?? null;
        if (!$typeStats) {
            return 1.0;
        }

        $avgChange = (float)($typeStats['avg_change'] ?? 0);
        $total = (int)($typeStats['outcome_count'] ?? 0);
        $improvedRate = $total > 0
            ? (int)($typeStats['improved'] ?? 0) / $total
            : 0.5;

        $baseWeight = 1.0;
        $changeFactor = 1 + ($avgChange / 10);
        $rateFactor = 0.5 + $improvedRate;

        $weight = $baseWeight * $changeFactor * $rateFactor;

        return max(0.3, min(1.5, round($weight, 2)));
    }

    /**
     * 判断是否应该执行某类决策
     * 审计修复（2026-07-19 H-中6）：原实现 $threshold / $weight 在 weight<1 时放大阈值，
     * 导致"效果越差的决策类型被调用越频繁"——与"效果驱动自适应"的设计意图相反。
     * 改为乘权重：效果好（weight>1）→阈值上调→更难触发→减少低价值调用。
     */
    public function shouldDecide(string $decisionType, float $currentScore, float $threshold): bool
    {
        $weight = $this->getAdaptiveWeight($decisionType);
        $adjustedThreshold = $threshold * $weight;
        return $currentScore < $adjustedThreshold;
    }

    /**
     * 获取效果统计
     */
    private function getOutcomeStats(): array
    {
        if ($this->cachedOutcomes !== null) {
            return $this->cachedOutcomes;
        }

        try {
            require_once __DIR__ . '/AgentDirectives.php';
            $this->cachedOutcomes = AgentDirectives::getOutcomeStats($this->novelId);
            // P0 修复：AgentDirectives::getOutcomeStats 的 by_type 是 GROUP BY 的
            // 数字索引行列表，而 getAdaptiveWeight() 按字符串键 $by_type[$decisionType]
            // 取值恒为 null，导致权重恒 1.0、自适应机制静默失效。此处重键为 type=>row。
            // 注意：OptimizationAgent/WritingStrategyAgent 按列表遍历 by_type，
            // 故不改 AgentDirectives 原返回，仅在本类消费端转换。
            if (!empty($this->cachedOutcomes['by_type']) && isset($this->cachedOutcomes['by_type'][0])) {
                $this->cachedOutcomes['by_type'] = array_column(
                    $this->cachedOutcomes['by_type'], null, 'type'
                );
            }
            return $this->cachedOutcomes;
        } catch (\Throwable $e) {
            return ['by_type' => []];
        }
    }

    // ============================================================
    // 模块2：趋势预测
    // ============================================================

    /**
     * 预测下一章可能出现的问题
     */
    public function predictIssues(): array
    {
        $predictions = [];

        // 预测1：质量分下降风险
        $qualityTrend = $this->getMetricTrend('quality_score', 10);
        if ($qualityTrend['slope'] < -0.5 && $qualityTrend['confidence'] > 0.6) {
            $predictions[] = [
                'type' => 'quality_decline',
                'severity' => $this->classifySeverity($qualityTrend['slope']),
                'confidence' => $qualityTrend['confidence'],
                'prediction' => sprintf(
                    '质量分呈下降趋势（斜率%.2f），预测下章可能继续下降',
                    $qualityTrend['slope']
                ),
                'preventive_action' => '建议本章增加质量检查，重点关注对话张力和节奏控制',
            ];
        }

        // 预测2：情绪密度持续不足
        $emotionTrend = $this->getMetricTrend('emotion_score', 10);
        if ($emotionTrend['consecutive_below'] >= 3) {
            $predictions[] = [
                'type' => 'emotion_deficit',
                'severity' => $emotionTrend['consecutive_below'] >= 5 ? 'high' : 'medium',
                'confidence' => min(1.0, 0.8 + $emotionTrend['consecutive_below'] * 0.02),
                'prediction' => sprintf(
                    '情绪密度连续%d章低于目标',
                    $emotionTrend['consecutive_below']
                ),
                'preventive_action' => '建议本章增加情绪描写，至少安排一个情绪爆发点',
            ];
        }

        // 预测3：伏笔堆积风险
        $foreshadowingRisk = $this->getForeshadowingRisk();
        if ($foreshadowingRisk['overdue_count'] > 0) {
            $predictions[] = [
                'type' => 'foreshadowing_overdue',
                'severity' => $foreshadowingRisk['overdue_count'] >= 3 ? 'high' : 'medium',
                'confidence' => 0.85,
                'prediction' => sprintf(
                    '%d个伏笔已超期未回收',
                    $foreshadowingRisk['overdue_count']
                ),
                'preventive_action' => '建议近期章节安排伏笔回收',
            ];
        }

        // 预测4（爽点饥饿度）已移除：原 getCoolPointHunger() 查询 cool_point_hunger 表，
        // 但该表在全库从未被 CREATE/INSERT，查询恒抛异常并被 catch 成 max_hunger=0，
        // 该分支永不触发——属静默失效的死代码。如需此预测，须先补建表及其填充数据源。

        return $predictions;
    }

    /**
     * 获取指标趋势
     */
    private function getMetricTrend(string $metric, int $window): array
    {
        try {
            $chapters = DB::fetchAll(
                "SELECT chapter_number, {$metric} as value
                 FROM chapters
                 WHERE novel_id = ? AND status = 'completed' AND {$metric} IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT ?",
                [$this->novelId, $window]
            );

            if (count($chapters) < 3) {
                return ['slope' => 0, 'confidence' => 0, 'consecutive_below' => 0];
            }

            $chapters = array_reverse($chapters);
            $values = array_column($chapters, 'value');

            $slope = $this->linearRegression($values);

            $target = $this->getTarget($metric);
            $consecutiveBelow = 0;
            for ($i = count($values) - 1; $i >= 0; $i--) {
                if ($values[$i] < $target) {
                    $consecutiveBelow++;
                } else {
                    break;
                }
            }

            $confidence = $this->calculateRSquared($values, $slope);

            return [
                'slope' => round($slope, 3),
                'confidence' => round($confidence, 2),
                'consecutive_below' => $consecutiveBelow,
                'recent_avg' => round(array_sum(array_slice($values, -5)) / min(5, count($values)), 1),
            ];
        } catch (\Throwable $e) {
            return ['slope' => 0, 'confidence' => 0, 'consecutive_below' => 0];
        }
    }

    /**
     * 简单线性回归
     */
    private function linearRegression(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0;

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1;
            $y = $values[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = $n * $sumX2 - $sumX * $sumX;
        if (abs($denominator) < 0.0001) return 0;

        return ($n * $sumXY - $sumX * $sumY) / $denominator;
    }

    /**
     * 计算R²作为置信度
     */
    private function calculateRSquared(array $values, float $slope): float
    {
        $n = count($values);
        if ($n < 3) return 0;

        $meanY = array_sum($values) / $n;

        $ssTotal = 0;
        $ssResidual = 0;

        for ($i = 0; $i < $n; $i++) {
            $predicted = $meanY + $slope * ($i + 1 - ($n + 1) / 2);
            $ssTotal += pow($values[$i] - $meanY, 2);
            $ssResidual += pow($values[$i] - $predicted, 2);
        }

        if ($ssTotal < 0.0001) return 1.0;

        return max(0, 1 - $ssResidual / $ssTotal);
    }

    // ============================================================
    // 模块3：专家知识注入
    // ============================================================

    /**
     * 根据问题类型生成专业建议
     */
    public function generateExpertAdvice(string $issueType, array $context = []): array
    {
        require_once __DIR__ . '/WritingExpertise.php';
        // 修复：predictIssues 产出的 type（quality_decline/emotion_deficit/foreshadowing_overdue）
        // 与 WritingExpertise::KNOWLEDGE_BASE 键（quality_low/emotion_low/foreshadowing_issue）
        // 命名不一致，导致 getAdvice 恒返回 found=false。此处做一层映射。
        $issueTypeMap = [
            'quality_decline'        => 'quality_low',
            'emotion_deficit'        => 'emotion_low',
            'foreshadowing_overdue'  => 'foreshadowing_issue',
        ];
        $mappedType = $issueTypeMap[$issueType] ?? $issueType;
        return WritingExpertise::getAdvice($mappedType, $context);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    private function getTarget(string $metric): float
    {
        $targets = [
            'quality_score' => 80.0,
            'emotion_score' => 75.0,
        ];
        return $targets[$metric] ?? 70.0;
    }

    private function classifySeverity(float $slope): string
    {
        return match(true) {
            $slope < -2 => 'critical',
            $slope < -1 => 'high',
            $slope < -0.5 => 'medium',
            default => 'low',
        };
    }

    private function getForeshadowingRisk(): array
    {
        try {
            $currentChapter = DB::fetch(
                "SELECT COALESCE(MAX(chapter_number), 0) as max_ch FROM chapters WHERE novel_id = ? AND status = 'completed'",
                [$this->novelId]
            );
            $maxCh = (int)($currentChapter['max_ch'] ?? 0);

            $stats = DB::fetch(
                "SELECT COUNT(*) as overdue_count
                 FROM foreshadowing_items
                 WHERE novel_id = ?
                   AND resolved_chapter IS NULL
                   AND deadline_chapter IS NOT NULL
                   AND deadline_chapter < ?",
                [$this->novelId, $maxCh]
            );

            return ['overdue_count' => (int)($stats['overdue_count'] ?? 0)];
        } catch (\Throwable $e) {
            return ['overdue_count' => 0];
        }
    }

}
