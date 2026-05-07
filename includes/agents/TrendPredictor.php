<?php
/**
 * 趋势预测器
 *
 * 基于历史数据的趋势分析，预测潜在问题
 *
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

class TrendPredictor
{
    private int $novelId;

    /** @var array 指标目标值 */
    private const TARGETS = [
        'quality_score' => 80.0,
        'emotion_score' => 75.0,
        'cool_point_density' => 0.29,
        'word_count_accuracy' => 0.85,
    ];

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 全面趋势分析
     */
    public function analyze(): array
    {
        return [
            'quality' => $this->analyzeMetric('quality_score', self::TARGETS['quality_score']),
            'emotion' => $this->analyzeMetric('emotion_score', self::TARGETS['emotion_score']),
            'coolpoint' => $this->analyzeCoolPoint(),
            'foreshadowing' => $this->analyzeForeshadowing(),
            'word_count' => $this->analyzeWordCount(),
            'overall_health' => $this->calculateOverallHealth(),
        ];
    }

    /**
     * 单指标趋势分析
     */
    private function analyzeMetric(string $metric, float $target): array
    {
        $history = $this->getRecentValues($metric, 15);

        if (count($history) < 5) {
            return [
                'status' => 'insufficient_data',
                'trend' => 'unknown',
                'confidence' => 0,
            ];
        }

        $values = array_column($history, 'value');
        $slope = $this->calculateSlope($values);
        $recent5Avg = array_sum(array_slice($values, -5)) / 5;

        // 判断状态
        $status = $this->determineStatus(end($values), $target, $slope);

        // 预测下一章
        $predicted = $this->predictNext($values, $slope);

        // 计算置信度
        $confidence = $this->calculateConfidence($values);

        return [
            'status' => $status,
            'trend' => $slope > 0.3 ? 'improving' : ($slope < -0.3 ? 'declining' : 'stable'),
            'slope' => round($slope, 3),
            'current' => round(end($values), 1),
            'target' => $target,
            'gap' => round($target - end($values), 1),
            'recent_avg' => round($recent5Avg, 1),
            'predicted_next' => round($predicted, 1),
            'confidence' => round($confidence, 2),
            'recommendation' => $this->generateRecommendation($status, $metric, $slope, $predicted, $target),
        ];
    }

    /**
     * 爽点密度分析
     */
    private function analyzeCoolPoint(): array
    {
        try {
            $stats = DB::fetch(
                "SELECT
                    COUNT(*) as total_chapters,
                    SUM(CASE WHEN cool_point_type IS NOT NULL AND cool_point_type != '' THEN 1 ELSE 0 END) as chapters_with_coolpoint
                 FROM chapters
                 WHERE novel_id = ? AND status = 'completed'",
                [$this->novelId]
            );

            $total = (int)($stats['total_chapters'] ?? 0);
            $withCoolpoint = (int)($stats['chapters_with_coolpoint'] ?? 0);

            $density = $total > 0 ? $withCoolpoint / $total : 0;
            $targetDensity = self::TARGETS['cool_point_density'];

            // 分析最近10章的爽点类型分布
            $typeDistribution = $this->getCoolPointTypeDistribution();

            // 检测是否有重复趋势
            $repetitionWarning = $this->detectCoolPointRepetition();

            $status = $density >= $targetDensity ? 'healthy' : 'below_target';

            return [
                'status' => $status,
                'current_density' => round($density, 3),
                'target_density' => $targetDensity,
                'gap' => round($targetDensity - $density, 3),
                'total_chapters' => $total,
                'chapters_with_coolpoint' => $withCoolpoint,
                'type_distribution' => $typeDistribution,
                'repetition_warning' => $repetitionWarning,
                'recommendation' => $density < $targetDensity
                    ? '爽点密度不足，建议近期章节增加爽点安排'
                    : ($repetitionWarning ? $repetitionWarning : '爽点密度正常'),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'trend' => 'unknown'];
        }
    }

    /**
     * 伏笔风险分析
     */
    private function analyzeForeshadowing(): array
    {
        try {
            $currentChapter = DB::fetch(
                "SELECT COALESCE(MAX(chapter_number), 0) as max_ch FROM chapters WHERE novel_id = ?",
                [$this->novelId]
            );
            $maxCh = (int)($currentChapter['max_ch'] ?? 0);

            // 统计伏笔状态
            $stats = DB::fetch(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN resolved_chapter IS NULL THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN resolved_chapter IS NULL AND deadline_chapter IS NOT NULL AND deadline_chapter < ? THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN priority = 'critical' AND resolved_chapter IS NULL THEN 1 ELSE 0 END) as critical_pending
                 FROM foreshadowing_items
                 WHERE novel_id = ?",
                [$maxCh, $this->novelId]
            );

            $total = (int)($stats['total'] ?? 0);
            $pending = (int)($stats['pending'] ?? 0);
            $overdue = (int)($stats['overdue'] ?? 0);
            $criticalPending = (int)($stats['critical_pending'] ?? 0);

            $status = 'healthy';
            if ($overdue > 3) {
                $status = 'critical';
            } elseif ($overdue > 0 || $pending > 10) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'total' => $total,
                'pending' => $pending,
                'overdue' => $overdue,
                'critical_pending' => $criticalPending,
                'recommendation' => match($status) {
                    'critical' => "有{$overdue}个伏笔已超期，必须尽快回收",
                    'warning' => "待回收伏笔{$pending}个，建议规划回收节奏",
                    default => '伏笔状态正常',
                },
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error'];
        }
    }

    /**
     * 字数控制分析
     */
    private function analyzeWordCount(): array
    {
        try {
            $chapters = DB::fetchAll(
                "SELECT c.words, n.chapter_words as target
                 FROM chapters c
                 JOIN novels n ON c.novel_id = n.id
                 WHERE c.novel_id = ? AND c.status = 'completed'
                 ORDER BY c.chapter_number DESC LIMIT 20",
                [$this->novelId]
            );

            if (empty($chapters)) {
                return ['status' => 'insufficient_data'];
            }

            $accurate = 0;
            $overCount = 0;
            $underCount = 0;
            $totalDeviation = 0;

            foreach ($chapters as $ch) {
                $target = (int)$ch['target'];
                $actual = (int)$ch['words'];
                $deviation = abs($actual - $target);
                $totalDeviation += $deviation;

                if ($deviation <= $target * 0.1) {
                    $accurate++;
                } elseif ($actual > $target) {
                    $overCount++;
                } else {
                    $underCount++;
                }
            }

            $accuracy = count($chapters) > 0 ? $accurate / count($chapters) : 0;
            $avgDeviation = count($chapters) > 0 ? $totalDeviation / count($chapters) : 0;

            $status = $accuracy >= 0.8 ? 'healthy' : ($accuracy >= 0.6 ? 'warning' : 'poor');

            return [
                'status' => $status,
                'accuracy' => round($accuracy, 2),
                'target_accuracy' => self::TARGETS['word_count_accuracy'],
                'avg_deviation' => round($avgDeviation),
                'over_count' => $overCount,
                'under_count' => $underCount,
                'trend' => $overCount > $underCount ? 'tendency_over' : ($underCount > $overCount ? 'tendency_under' : 'balanced'),
                'recommendation' => match($status) {
                    'poor' => '字数控制严重偏差，建议检查大纲规划或调整容差',
                    'warning' => '字数控制有偏差，建议加强章节规划',
                    default => '字数控制正常',
                },
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error'];
        }
    }

    /**
     * 计算整体健康度
     */
    private function calculateOverallHealth(): array
    {
        $quality = $this->analyzeMetric('quality_score', self::TARGETS['quality_score']);
        $emotion = $this->analyzeMetric('emotion_score', self::TARGETS['emotion_score']);
        $coolpoint = $this->analyzeCoolPoint();
        $foreshadowing = $this->analyzeForeshadowing();

        $scores = [];

        // 质量分 (0-100)
        $scores['quality'] = min(100, ($quality['current'] ?? 70));

        // 情绪分 (0-100)
        $scores['emotion'] = min(100, ($emotion['current'] ?? 70));

        // 爽点密度分 (0-100)
        $density = $coolpoint['current_density'] ?? 0;
        $scores['coolpoint'] = min(100, $density / self::TARGETS['cool_point_density'] * 100);

        // 伏笔分 (0-100)
        $pending = $foreshadowing['pending'] ?? 0;
        $overdue = $foreshadowing['overdue'] ?? 0;
        $scores['foreshadowing'] = max(0, 100 - $pending * 2 - $overdue * 10);

        // 综合健康分
        $overallScore = array_sum($scores) / count($scores);

        return [
            'score' => round($overallScore, 1),
            'breakdown' => $scores,
            'grade' => match(true) {
                $overallScore >= 90 => 'A',
                $overallScore >= 80 => 'B',
                $overallScore >= 70 => 'C',
                $overallScore >= 60 => 'D',
                default => 'F',
            },
            'weakest_area' => array_keys($scores, min($scores))[0] ?? null,
        ];
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    private function getRecentValues(string $metric, int $limit): array
    {
        try {
            return DB::fetchAll(
                "SELECT chapter_number, {$metric} as value
                 FROM chapters
                 WHERE novel_id = ? AND status = 'completed' AND {$metric} IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT ?",
                [$this->novelId, $limit]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function calculateSlope(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0;

        $sumX = $sumY = $sumXY = $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1;
            $y = $values[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = $n * $sumX2 - $sumX * $sumX;
        return abs($denominator) < 0.0001 ? 0 : ($n * $sumXY - $sumX * $sumY) / $denominator;
    }

    private function predictNext(array $values, float $slope): float
    {
        $last = end($values);
        return $last + $slope;
    }

    private function calculateConfidence(array $values): float
    {
        $n = count($values);
        if ($n < 3) return 0;

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / $n;

        // 变异系数越小，置信度越高
        $cv = $mean > 0 ? sqrt($variance) / $mean : 1;
        return max(0, min(1, 1 - $cv));
    }

    private function determineStatus(float $current, float $target, float $slope): string
    {
        if ($current >= $target) {
            return $slope < -0.5 ? 'declining_from_good' : 'healthy';
        }

        if ($slope > 0.3) {
            return 'improving';
        } elseif ($slope < -0.3) {
            return 'declining';
        }

        return $current >= $target * 0.9 ? 'slightly_below' : 'below_target';
    }

    private function generateRecommendation(string $status, string $metric, float $slope, float $predicted, float $target): string
    {
        $metricNames = [
            'quality_score' => '质量分',
            'emotion_score' => '情绪密度',
        ];
        $name = $metricNames[$metric] ?? $metric;

        return match($status) {
            'healthy' => "{$name}状态良好，继续保持",
            'declining_from_good' => "{$name}虽达标但呈下降趋势，需警惕",
            'improving' => "{$name}正在改善，预测下章可达" . round($predicted, 1),
            'declining' => "{$name}持续下降，建议立即干预",
            'slightly_below' => "{$name}略低于目标，趋势平稳",
            'below_target' => "{$name}明显低于目标({$target})，需要加强",
            default => "{$name}状态未知",
        };
    }

    private function getCoolPointTypeDistribution(): array
    {
        try {
            $types = DB::fetchAll(
                "SELECT cool_point_type, COUNT(*) as count
                 FROM chapters
                 WHERE novel_id = ? AND status = 'completed'
                   AND cool_point_type IS NOT NULL AND cool_point_type != ''
                 GROUP BY cool_point_type
                 ORDER BY count DESC",
                [$this->novelId]
            );
            return $types ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function detectCoolPointRepetition(): ?string
    {
        try {
            $recent = DB::fetchAll(
                "SELECT chapter_number, cool_point_type
                 FROM chapters
                 WHERE novel_id = ? AND status = 'completed'
                   AND cool_point_type IS NOT NULL AND cool_point_type != ''
                 ORDER BY chapter_number DESC LIMIT 5",
                [$this->novelId]
            );

            if (count($recent) < 3) return null;

            $types = array_column($recent, 'cool_point_type');
            $counts = array_count_values($types);

            foreach ($counts as $type => $count) {
                if ($count >= 3) {
                    return "爽点类型「{$type}」连续出现{$count}次，建议换类型";
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
