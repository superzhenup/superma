<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * ImprovementEvaluator — 迭代改进效果评估器
 *
 * 核心功能：
 *   - 评估每次迭代的改进效果
 *   - 分析改进趋势和边际收益
 *   - 识别有效和无效的改进模式
 *   - 提供诊断建议和优化反馈
 *
 * 评估维度：
 *   1. 分数改进度 - 各维度分数提升情况
 *   2. 问题解决率 - 识别的问题中有多少被解决
 *   3. 边际收益 - 每轮迭代的效率变化
 *   4. 质量稳定性 - 改进后内容是否稳定
 *   5. 成本效益 - AI 调用次数与收益的比率
 *
 * @package NovelWritingSystem
 * @version 1.1.0
 */
class ImprovementEvaluator
{
    private int $novelId;
    private int $chapterId;

    private array $evaluationHistory = [];
    private array $baselineMetrics = [];

    public function __construct(int $novelId, int $chapterId = 0)
    {
        $this->novelId = $novelId;
        $this->chapterId = $chapterId;
    }

    /**
     * 评估单次迭代的效果
     *
     * @param array $iterationResult 单次迭代的结果
     * @param array $baseline 基准数据（迭代前的状态）
     * @return array 评估报告
     */
    public function evaluateIteration(array $iterationResult, array $baseline): array
    {
        $beforeScore = $iterationResult['before_score'] ?? 0;
        $afterScore = $iterationResult['after_score'] ?? 0;
        $improvement = $iterationResult['improvement'] ?? ($afterScore - $beforeScore);
        $iteration = $iterationResult['iteration'] ?? 1;

        $evaluation = [
            'iteration' => $iteration,
            'improvement' => $improvement,
            'improvement_rate' => $beforeScore > 0 ? round(($improvement / $beforeScore) * 100, 2) : 0,
            'effectiveness' => $this->assessEffectiveness($improvement, $iteration),
            'dimensions' => [],
            'issues_resolved' => 0,
            'issues_remaining' => 0,
            'diagnostics' => [],
        ];

        // 评估各维度改进
        if (isset($iterationResult['evaluation']) && isset($iterationResult['new_evaluation'])) {
            $evaluation['dimensions'] = $this->evaluateDimensions(
                $iterationResult['evaluation'],
                $iterationResult['new_evaluation']
            );
        }

        // 评估问题解决情况
        if (isset($iterationResult['issues_addressed'])) {
            $evaluation['issues_resolved'] = $this->calculateIssuesResolved(
                $baseline,
                $iterationResult['new_evaluation'] ?? []
            );
            $evaluation['issues_remaining'] = max(0, $iterationResult['issues_addressed'] - $evaluation['issues_resolved']);
        }

        // 生成诊断建议
        $evaluation['diagnostics'] = $this->generateDiagnostics($evaluation, $iterationResult);

        return $evaluation;
    }

    /**
     * 评估多次迭代的整体效果
     *
     * @param array $iterationResults 所有迭代结果
     * @return array 整体评估报告
     */
    public function evaluateOverall(array $iterationResults): array
    {
        if (empty($iterationResults)) {
            return [
                'success' => false,
                'message' => '没有迭代数据可供评估',
            ];
        }

        $totalIterations = count($iterationResults);
        $initialScore = $iterationResults[0]['before_score'] ?? 0;
        $finalScore = $iterationResults[$totalIterations - 1]['after_score'] ?? $initialScore;
        $totalImprovement = $finalScore - $initialScore;

        $improvements = array_column($iterationResults, 'improvement');
        $positiveIterations = count(array_filter($improvements, fn($i) => $i > 0));
        $negativeIterations = count(array_filter($improvements, fn($i) => $i < 0));

        $overall = [
            'success' => true,
            'total_iterations' => $totalIterations,
            'initial_score' => $initialScore,
            'final_score' => $finalScore,
            'total_improvement' => $totalImprovement,
            'improvement_rate' => $initialScore > 0 ? round(($totalImprovement / $initialScore) * 100, 2) : 0,
            'positive_iterations' => $positiveIterations,
            'negative_iterations' => $negativeIterations,
            'success_rate' => $totalIterations > 0 ? round(($positiveIterations / $totalIterations) * 100, 2) : 0,
            'avg_improvement_per_iteration' => $totalIterations > 0 ? round($totalImprovement / $totalIterations, 2) : 0,
            'iterations' => [],
        ];

        // 分析改进趋势
        $overall['trend'] = $this->analyzeImprovementTrend($improvements);

        // 找出最佳和最差迭代
        $overall['best_iteration'] = $this->findBestIteration($iterationResults);
        $overall['worst_iteration'] = $this->findWorstIteration($iterationResults);

        // 评估每次迭代
        $baseline = $iterationResults[0];
        foreach ($iterationResults as $iterationResult) {
            $iterationEval = $this->evaluateIteration($iterationResult, $baseline);
            $overall['iterations'][] = $iterationEval;
            $baseline = $iterationResult;
        }

        // 生成总体诊断
        $overall['diagnostics'] = $this->generateOverallDiagnostics($overall);

        // 提取改进模式
        $overall['patterns'] = $this->extractImprovementPatterns($iterationResults);

        return $overall;
    }

    /**
     * 评估改进效果等级
     */
    private function assessEffectiveness(float $improvement, int $iteration): string
    {
        $absImprovement = abs($improvement);

        if ($absImprovement < 2) {
            return 'minimal';
        } elseif ($absImprovement < 5) {
            return $improvement > 0 ? 'moderate' : 'slight_decline';
        } elseif ($absImprovement < 10) {
            return $improvement > 0 ? 'good' : 'noticeable_decline';
        } else {
            return $improvement > 0 ? 'excellent' : 'significant_decline';
        }
    }

    /**
     * 评估各维度改进情况
     */
    private function evaluateDimensions(array $beforeEval, array $afterEval): array
    {
        $dimensions = [];

        $beforeGates = $beforeEval['gate_results'] ?? [];
        $afterGates = $afterEval['gate_results'] ?? [];

        foreach ($beforeGates as $index => $gate) {
            $gateName = $gate['name'] ?? "Gate_{$index}";
            $beforeScore = $gate['score'] ?? 0;
            $afterScore = $afterGates[$index]['score'] ?? $beforeScore;

            $dimensions[$gateName] = [
                'before' => $beforeScore,
                'after' => $afterScore,
                'improvement' => $afterScore - $beforeScore,
                'improved' => $afterScore > $beforeScore,
                'degraded' => $afterScore < $beforeScore,
            ];
        }

        return $dimensions;
    }

    /**
     * 计算问题解决数量
     */
    private function calculateIssuesResolved(array $baseline, array $afterEval): int
    {
        $resolvedCount = 0;

        $baselineGates = $baseline['gate_results'] ?? [];
        $afterGates = $afterEval['gate_results'] ?? [];

        foreach ($baselineGates as $index => $gate) {
            $baselineScore = $gate['score'] ?? 0;
            $afterScore = $afterGates[$index]['score'] ?? $baselineScore;

            // 原本低于 60 分，现在达到或超过 60 分，认为解决了
            if ($baselineScore < 60 && $afterScore >= 60) {
                $resolvedCount++;
            }
            // 或者分数提升了 10 分以上
            elseif ($afterScore - $baselineScore >= 10) {
                $resolvedCount++;
            }
        }

        return $resolvedCount;
    }

    /**
     * 分析改进趋势
     */
    private function analyzeImprovementTrend(array $improvements): array
    {
        if (count($improvements) < 2) {
            return [
                'trend' => 'insufficient_data',
                'description' => '数据点不足，无法判断趋势',
                'consistency' => 'unknown',
            ];
        }

        $trend = 'stable';
        $description = '';
        $consistency = 'unknown';

        // 计算改进的变化率
        $positiveCount = count(array_filter($improvements, fn($i) => $i > 0));
        $negativeCount = count(array_filter($improvements, fn($i) => $i < 0));

        if ($positiveCount == count($improvements)) {
            $trend = 'improving';
            $description = '所有迭代都有正向改进';
            $consistency = 'high';
        } elseif ($negativeCount > 0 && $positiveCount == 0) {
            $trend = 'declining';
            $description = '所有迭代改进都为负，可能存在系统性问题';
            $consistency = 'high';
        } elseif ($positiveCount >= count($improvements) * 0.7) {
            $trend = 'mostly_improving';
            $description = '大部分迭代有正向改进，存在一定波动';
            $consistency = 'medium';
        } elseif ($negativeCount >= count($improvements) * 0.7) {
            $trend = 'mostly_declining';
            $description = '大部分迭代改进为负，建议检查改进策略';
            $consistency = 'medium';
        } else {
            $trend = 'fluctuating';
            $description = '改进效果波动较大，可能受内容本身特性影响';
            $consistency = 'low';
        }

        // 检查是否存在递减趋势
        $decreasingTrend = false;
        if (count($improvements) >= 3) {
            $recentThree = array_slice($improvements, -3);
            if ($recentThree[0] > $recentThree[1] && $recentThree[1] > $recentThree[2]) {
                $decreasingTrend = true;
                $description .= '（警告：近3轮改进呈现递减趋势）';
            }
        }

        return [
            'trend' => $trend,
            'description' => $description,
            'consistency' => $consistency,
            'decreasing_trend' => $decreasingTrend,
            'avg_improvement' => round(array_sum($improvements) / count($improvements), 2),
            'max_improvement' => max($improvements),
            'min_improvement' => min($improvements),
        ];
    }

    /**
     * 找出最佳迭代
     */
    private function findBestIteration(array $iterationResults): array
    {
        $best = ['iteration' => 0, 'improvement' => -PHP_INT_MAX];

        foreach ($iterationResults as $result) {
            $improvement = $result['improvement'] ?? 0;
            if ($improvement > $best['improvement']) {
                $best = [
                    'iteration' => $result['iteration'] ?? 0,
                    'improvement' => $improvement,
                    'score_change' => "{$result['before_score']} → {$result['after_score']}",
                ];
            }
        }

        return $best;
    }

    /**
     * 找出最差迭代
     */
    private function findWorstIteration(array $iterationResults): array
    {
        $worst = ['iteration' => 0, 'improvement' => PHP_INT_MAX];

        foreach ($iterationResults as $result) {
            $improvement = $result['improvement'] ?? 0;
            if ($improvement < $worst['improvement']) {
                $worst = [
                    'iteration' => $result['iteration'] ?? 0,
                    'improvement' => $improvement,
                    'score_change' => "{$result['before_score']} → {$result['after_score']}",
                ];
            }
        }

        return $worst;
    }

    /**
     * 生成单次迭代的诊断建议
     */
    private function generateDiagnostics(array $evaluation, array $iterationResult): array
    {
        $diagnostics = [];

        // 检查改进效果
        if ($evaluation['improvement'] < 0) {
            $diagnostics[] = [
                'type' => 'warning',
                'message' => "本轮迭代质量下降 {$evaluation['improvement']} 分，可能过度修改导致原有优质内容被改动",
                'suggestion' => '建议缩小修改范围，或检查修改指令是否合理',
            ];
        } elseif ($evaluation['improvement'] > 0 && $evaluation['improvement'] < 3) {
            $diagnostics[] = [
                'type' => 'info',
                'message' => "本轮改进较小 ({$evaluation['improvement']} 分)，边际收益较低",
                'suggestion' => '可以考虑提前终止迭代，或调整改进策略',
            ];
        }

        // 检查问题解决情况
        if ($evaluation['issues_resolved'] > 0 && $evaluation['issues_remaining'] > 0) {
            $diagnostics[] = [
                'type' => 'info',
                'message' => "本轮解决了 {$evaluation['issues_resolved']} 个问题，仍有 {$evaluation['issues_remaining']} 个问题待解决",
                'suggestion' => '建议在后续迭代中继续关注未解决的问题',
            ];
        } elseif ($evaluation['issues_remaining'] == 0 && $evaluation['issues_resolved'] > 0) {
            $diagnostics[] = [
                'type' => 'success',
                'message' => "本轮所有识别的问题都已解决",
                'suggestion' => '效果显著，可以继续迭代或接受当前结果',
            ];
        }

        // 检查维度退化
        if (!empty($evaluation['dimensions'])) {
            $degradedDimensions = array_filter($evaluation['dimensions'], fn($d) => $d['degraded']);
            if (!empty($degradedDimensions)) {
                $degradedNames = implode(', ', array_keys($degradedDimensions));
                $diagnostics[] = [
                    'type' => 'warning',
                    'message' => "以下维度出现退化：{$degradedNames}",
                    'suggestion' => '修改时需要更加谨慎，保护已合格的维度',
                ];
            }
        }

        return $diagnostics;
    }

    /**
     * 生成整体诊断
     */
    private function generateOverallDiagnostics(array $overall): array
    {
        $diagnostics = [];

        // 检查总体改进效果
        if ($overall['total_improvement'] < 5) {
            $diagnostics[] = [
                'type' => 'warning',
                'message' => "总改进较小 ({$overall['total_improvement']} 分)，迭代策略可能需要优化",
                'suggestion' => '建议检查问题识别的准确性，或调整改进指令的针对性',
            ];
        } elseif ($overall['total_improvement'] >= 15) {
            $diagnostics[] = [
                'type' => 'success',
                'message' => "总改进显著 ({$overall['total_improvement']} 分)，迭代策略效果良好",
                'suggestion' => '可以考虑将成功的策略应用到其他章节',
            ];
        }

        // 检查成功率
        if ($overall['success_rate'] < 50) {
            $diagnostics[] = [
                'type' => 'error',
                'message' => "迭代成功率较低 ({$overall['success_rate']}%)，{$overall['negative_iterations']} 轮出现质量下降",
                'suggestion' => '建议大幅调整迭代策略，可能存在系统性问题',
            ];
        }

        // 检查趋势
        if (isset($overall['trend']['decreasing_trend']) && $overall['trend']['decreasing_trend']) {
            $diagnostics[] = [
                'type' => 'warning',
                'message' => '检测到改进递减趋势，后续迭代收益可能继续下降',
                'suggestion' => '建议在递减趋势明显时提前终止迭代',
            ];
        }

        // 检查波动性
        if ($overall['trend']['consistency'] === 'low') {
            $diagnostics[] = [
                'type' => 'info',
                'message' => '迭代效果波动较大，结果可能受内容特性影响较大',
                'suggestion' => '建议记录不同内容类型的迭代效果，积累经验',
            ];
        }

        return $diagnostics;
    }

    /**
     * 提取改进模式
     */
    private function extractImprovementPatterns(array $iterationResults): array
    {
        $patterns = [];

        // 分析每次迭代关注的问题类型
        foreach ($iterationResults as $result) {
            if (isset($result['evaluation']['weak_gates'])) {
                $weakGates = $result['evaluation']['weak_gates'];
                foreach ($weakGates as $gate) {
                    $gateName = $gate['name'] ?? '';
                    if (!isset($patterns[$gateName])) {
                        $patterns[$gateName] = [
                            'total_occurrences' => 0,
                            'total_improvement' => 0,
                            'avg_improvement' => 0,
                        ];
                    }
                    $patterns[$gateName]['total_occurrences']++;
                    $patterns[$gateName]['total_improvement'] += $result['improvement'] ?? 0;
                }
            }
        }

        // 计算平均改进
        foreach ($patterns as $gateName => &$pattern) {
            if ($pattern['total_occurrences'] > 0) {
                $pattern['avg_improvement'] = round($pattern['total_improvement'] / $pattern['total_occurrences'], 2);
            }
        }

        // 找出最容易改进的维度
        $sortedPatterns = $patterns;
        usort($sortedPatterns, fn($a, $b) => $b['avg_improvement'] <=> $a['avg_improvement']);

        return [
            'by_dimension' => $patterns,
            'easiest_to_improve' => $sortedPatterns[0] ?? null,
            'hardest_to_improve' => end($sortedPatterns) ?: null,
        ];
    }

    /**
     * 保存评估结果到数据库
     */
    public function saveEvaluation(array $evaluation, int $iterationNumber): bool
    {
        try {
            $evaluationData = json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            if ($this->chapterId > 0) {
                DB::update('chapters', [
                    'iteration_evaluation' => $evaluationData,
                ], 'id=?', [$this->chapterId]);
            }

            return true;
        } catch (\Throwable $e) {
            error_log('ImprovementEvaluator::saveEvaluation 失败：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取历史评估数据
     */
    public function getHistoricalEvaluations(int $limit = 20): array
    {
        try {
            $evaluations = DB::fetchAll(
                'SELECT id, chapter_number, title, iterative_history, iteration_evaluation, created_at
                 FROM chapters
                 WHERE novel_id = ? AND iteration_evaluation IS NOT NULL
                 ORDER BY chapter_number DESC
                 LIMIT ?',
                [$this->novelId, $limit]
            );

            return array_map(function ($chapter) {
                return [
                    'chapter_id' => $chapter['id'],
                    'chapter_number' => $chapter['chapter_number'],
                    'title' => $chapter['title'],
                    'evaluation' => json_decode($chapter['iteration_evaluation'] ?? '{}', true),
                    'created_at' => $chapter['created_at'],
                ];
            }, $evaluations ?: []);
        } catch (\Throwable $e) {
            error_log('ImprovementEvaluator::getHistoricalEvaluations 失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 生成改进建议报告
     */
    public function generateImprovementReport(array $iterationResults): array
    {
        $overall = $this->evaluateOverall($iterationResults);

        return [
            'summary' => [
                'total_iterations' => $overall['total_iterations'],
                'total_improvement' => $overall['total_improvement'],
                'improvement_rate' => $overall['improvement_rate'] . '%',
                'success_rate' => $overall['success_rate'] . '%',
                'recommendation' => $this->generateRecommendation($overall),
            ],
            'trend' => $overall['trend'],
            'best_iteration' => $overall['best_iteration'],
            'worst_iteration' => $overall['worst_iteration'],
            'patterns' => $overall['patterns'],
            'diagnostics' => $overall['diagnostics'],
            'suggestions' => $this->generateSuggestions($overall),
        ];
    }

    /**
     * 生成推荐建议
     */
    private function generateRecommendation(array $overall): string
    {
        if ($overall['total_improvement'] >= 15 && $overall['success_rate'] >= 70) {
            return '迭代策略效果优秀，建议保持当前配置并推广应用';
        } elseif ($overall['total_improvement'] >= 10 && $overall['success_rate'] >= 50) {
            return '迭代策略效果良好，可以考虑小幅优化参数';
        } elseif ($overall['total_improvement'] >= 5) {
            return '迭代策略有一定效果，建议分析最差迭代的原因并针对性优化';
        } else {
            return '迭代效果不明显，建议检查问题识别的准确性或调整改进策略';
        }
    }

    /**
     * 生成具体建议
     */
    private function generateSuggestions(array $overall): array
    {
        $suggestions = [];

        // 基于趋势的建议
        if (isset($overall['trend']['decreasing_trend']) && $overall['trend']['decreasing_trend']) {
            $suggestions[] = [
                'priority' => 'high',
                'category' => 'iteration_count',
                'suggestion' => '检测到改进递减趋势，建议将最大迭代次数减少到 2-3 次',
            ];
        }

        // 基于成功率的建议
        if ($overall['success_rate'] < 60) {
            $suggestions[] = [
                'priority' => 'high',
                'category' => 'strategy',
                'suggestion' => '成功率较低，建议使用更保守的改进策略，每次修改幅度减小',
            ];
        }

        // 基于最佳迭代的建议
        if ($overall['best_iteration']['improvement'] > 10) {
            $bestIter = $overall['best_iteration']['iteration'];
            $suggestions[] = [
                'priority' => 'medium',
                'category' => 'timing',
                'suggestion' => "第 {$bestIter} 轮迭代效果最佳，可考虑在该轮次后稳定质量",
            ];
        }

        // 基于模式的建议
        if (!empty($overall['patterns']['easiest_to_improve'])) {
            $easiest = $overall['patterns']['easiest_to_improve'];
            $suggestions[] = [
                'priority' => 'low',
                'category' => 'focus',
                'suggestion' => "{$easiest['name']} 维度最容易改进，可以优先关注",
            ];
        }

        return $suggestions;
    }
}
