<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/agents/ImprovementEvaluator.php';
requireLoginApi();

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'dashboard';
$novelId = isset($_GET['novel_id']) ? (int)$_GET['novel_id'] : 0;
$chapterId = isset($_GET['chapter_id']) ? (int)$_GET['chapter_id'] : 0;

try {
    if ($novelId <= 0) {
        throw new Exception('需要提供有效的小说ID');
    }

    switch ($action) {
        case 'dashboard':
            echo json_encode([
                'success' => true,
                'data' => getDashboardData($novelId),
            ]);
            break;

        case 'chapter':
            if ($chapterId <= 0) {
                throw new Exception('需要提供有效的章节ID');
            }
            echo json_encode([
                'success' => true,
                'data' => getChapterIterationDetail($novelId, $chapterId),
            ]);
            break;

        case 'trends':
            $period = $_GET['period'] ?? '30';
            echo json_encode([
                'success' => true,
                'data' => getImprovementTrends($novelId, (int)$period),
            ]);
            break;

        case 'diagnose':
            echo json_encode([
                'success' => true,
                'data' => performDiagnosticAnalysis($novelId),
            ]);
            break;

        case 'patterns':
            echo json_encode([
                'success' => true,
                'data' => analyzeImprovementPatterns($novelId),
            ]);
            break;

        default:
            throw new Exception('未知的操作：' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

/**
 * 获取监控面板数据
 */
function getDashboardData(int $novelId): array
{
    // 获取总体统计
    $overallStats = getOverallStats($novelId);

    // 获取近期改进趋势
    $recentTrends = getRecentImprovementTrends($novelId, 10);

    // 获取问题模式分析
    $patterns = analyzeCommonIssues($novelId);

    // 获取性能指标
    $performance = calculatePerformanceMetrics($novelId);

    // 生成预警信息
    $alerts = generateAlerts($overallStats, $patterns, $performance);

    return [
        'overall' => $overallStats,
        'recent_trends' => $recentTrends,
        'patterns' => $patterns,
        'performance' => $performance,
        'alerts' => $alerts,
        'summary' => generateDashboardSummary($overallStats, $performance),
    ];
}

/**
 * 获取总体统计
 */
function getOverallStats(int $novelId): array
{
    try {
        $chapters = DB::fetchAll(
            'SELECT id, chapter_number, title, quality_score, iterations_used,
                    total_improvement, iterative_history, created_at
             FROM chapters
             WHERE novel_id = ? AND rewritten = 1
             ORDER BY chapter_number DESC',
            [$novelId]
        );

        if (empty($chapters)) {
            return [
                'total_rewrites' => 0,
                'avg_improvement' => 0,
                'avg_iterations' => 0,
                'success_rate' => 0,
                'total_time_saved' => 0,
            ];
        }

        $totalRewrites = count($chapters);
        $totalImprovement = array_sum(array_column($chapters, 'total_improvement'));
        $totalIterations = array_sum(array_column($chapters, 'iterations_used'));
        $avgImprovement = round($totalImprovement / $totalRewrites, 2);
        $avgIterations = round($totalIterations / $totalRewrites, 2);

        // 计算成功率（采纳率）
        $adoptedCount = count(array_filter($chapters, fn($ch) =>
            ($ch['total_improvement'] ?? 0) > 0
        ));
        $successRate = round(($adoptedCount / $totalRewrites) * 100, 1);

        // 估算节省时间（假设每次重写节省10分钟手动修改）
        $estimatedMinutesSaved = $totalRewrites * 10;

        return [
            'total_rewrites' => $totalRewrites,
            'avg_improvement' => $avgImprovement,
            'avg_iterations' => $avgIterations,
            'success_rate' => $successRate,
            'total_improvement' => round($totalImprovement, 2),
            'total_time_saved_minutes' => $estimatedMinutesSaved,
            'best_chapter' => findBestImprovement($chapters),
            'worst_chapter' => findWorstImprovement($chapters),
        ];
    } catch (Exception $e) {
        error_log('获取总体统计失败：' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * 找出最佳改进章节
 */
function findBestImprovement(array $chapters): array
{
    if (empty($chapters)) {
        return [];
    }

    usort($chapters, fn($a, $b) => ($b['total_improvement'] ?? 0) <=> ($a['total_improvement'] ?? 0));

    $best = $chapters[0];
    return [
        'chapter_number' => $best['chapter_number'],
        'title' => $best['title'],
        'improvement' => $best['total_improvement'] ?? 0,
    ];
}

/**
 * 找出最差改进章节
 */
function findWorstImprovement(array $chapters): array
{
    if (empty($chapters)) {
        return [];
    }

    usort($chapters, fn($a, $b) => ($a['total_improvement'] ?? 0) <=> ($b['total_improvement'] ?? 0));

    $worst = $chapters[0];
    return [
        'chapter_number' => $worst['chapter_number'],
        'title' => $worst['title'],
        'improvement' => $worst['total_improvement'] ?? 0,
    ];
}

/**
 * 获取近期改进趋势
 */
function getRecentImprovementTrends(int $novelId, int $limit = 10): array
{
    try {
        $chapters = DB::fetchAll(
            'SELECT chapter_number, title, quality_score, total_improvement,
                    iterations_used, created_at
             FROM chapters
             WHERE novel_id = ? AND rewritten = 1
             ORDER BY chapter_number DESC
             LIMIT ?',
            [$novelId, $limit]
        );

        return array_map(function ($ch) {
            return [
                'chapter_number' => $ch['chapter_number'],
                'title' => $ch['title'],
                'final_score' => $ch['quality_score'],
                'improvement' => $ch['total_improvement'] ?? 0,
                'iterations' => $ch['iterations_used'] ?? 1,
                'date' => date('m-d', strtotime($ch['created_at'])),
            ];
        }, array_reverse($chapters ?: []));
    } catch (Exception $e) {
        error_log('获取近期趋势失败：' . $e->getMessage());
        return [];
    }
}

/**
 * 分析常见问题模式
 */
function analyzeCommonIssues(int $novelId): array
{
    try {
        $chapters = DB::fetchAll(
            'SELECT iterative_history FROM chapters
             WHERE novel_id = ? AND rewritten = 1 AND iterative_history IS NOT NULL',
            [$novelId]
        );

        $issueCounts = [];
        $gateScores = [
            'gate1_structure' => [],
            'gate2_characters' => [],
            'gate3_description' => [],
            'gate4_coolpoint' => [],
            'gate5_consistency' => [],
        ];

        foreach ($chapters as $chapter) {
            $history = json_decode($chapter['iterative_history'], true);
            if (!is_array($history)) continue;

            foreach ($history as $iteration) {
                // 统计问题类型
                if (isset($iteration['evaluation']['weak_gates'])) {
                    foreach ($iteration['evaluation']['weak_gates'] as $gate) {
                        $gateName = $gate['name'] ?? 'unknown';
                        $issueCounts[$gateName] = ($issueCounts[$gateName] ?? 0) + 1;
                    }
                }

                // 收集分数
                if (isset($iteration['evaluation']['gate_results'])) {
                    foreach ($iteration['evaluation']['gate_results'] as $idx => $gate) {
                        $key = 'gate' . ($idx + 1) . '_' . strtolower($gate['name'] ?? 'unknown');
                        $gateScores[$key][] = $gate['score'] ?? 0;
                    }
                }
            }
        }

        // 计算平均分数
        $avgScores = [];
        foreach ($gateScores as $key => $scores) {
            $avgScores[$key] = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;
        }

        // 找出最常见的问题
        arsort($issueCounts);
        $topIssues = array_slice($issueCounts, 0, 5, true);

        return [
            'top_issues' => $topIssues,
            'avg_gate_scores' => $avgScores,
            'weakest_gate' => array_search(min($avgScores), $avgScores) ?: null,
            'strongest_gate' => array_search(max($avgScores), $avgScores) ?: null,
        ];
    } catch (Exception $e) {
        error_log('分析常见问题失败：' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * 计算性能指标
 */
function calculatePerformanceMetrics(int $novelId): array
{
    try {
        $chapters = DB::fetchAll(
            'SELECT iterative_history, iterations_used FROM chapters
             WHERE novel_id = ? AND rewritten = 1',
            [$novelId]
        );

        if (empty($chapters)) {
            return [
                'avg_time_per_iteration' => 0,
                'efficiency_score' => 0,
                'cost_benefit_ratio' => 0,
            ];
        }

        // 计算平均每轮迭代的改进
        $improvements = [];
        $iterations = [];
        $totalImprovement = 0;

        foreach ($chapters as $chapter) {
            $history = json_decode($chapter['iterative_history'], true);
            if (!is_array($history)) continue;

            $chImprovement = 0;
            $chIterations = 0;

            foreach ($history as $iteration) {
                $chImprovement += $iteration['improvement'] ?? 0;
                $chIterations++;
                $iterations[] = $iteration['execution_time_ms'] ?? 0;
            }

            $improvements[] = $chIterations > 0 ? $chImprovement / $chIterations : 0;
            $totalImprovement += $chImprovement;
        }

        $avgTimePerIteration = count($iterations) > 0
            ? round(array_sum($iterations) / count($iterations) / 1000, 1)
            : 0;
        $avgImprovementPerIteration = count($improvements) > 0
            ? round(array_sum($improvements) / count($improvements), 2)
            : 0;

        // 效率分数：平均每分钟改进多少分
        $efficiencyScore = $avgTimePerIteration > 0
            ? round($avgImprovementPerIteration / $avgTimePerIteration, 2)
            : 0;

        // 成本效益比：总改进分数 / 总迭代次数
        $totalIterations = array_sum(array_column($chapters, 'iterations_used'));
        $costBenefitRatio = $totalIterations > 0
            ? round($totalImprovement / $totalIterations, 2)
            : 0;

        return [
            'avg_time_per_iteration_seconds' => $avgTimePerIteration,
            'avg_improvement_per_iteration' => $avgImprovementPerIteration,
            'efficiency_score' => $efficiencyScore,
            'cost_benefit_ratio' => $costBenefitRatio,
            'total_ai_calls' => $totalIterations,
        ];
    } catch (Exception $e) {
        error_log('计算性能指标失败：' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * 生成预警信息
 */
function generateAlerts(array $stats, array $patterns, array $performance): array
{
    $alerts = [];

    // 检查成功率
    if (isset($stats['success_rate']) && $stats['success_rate'] < 50) {
        $alerts[] = [
            'level' => 'error',
            'type' => 'success_rate',
            'message' => "采纳率较低（{$stats['success_rate']}%），大部分重写未达到最低增益要求",
            'suggestion' => '建议降低 min_gain 阈值或优化问题识别策略',
        ];
    }

    // 检查最弱维度
    if (isset($patterns['weakest_gate']) && $patterns['weakest_gate']) {
        $weakGate = $patterns['weakest_gate'];
        $avgScore = $patterns['avg_gate_scores'][$weakGate] ?? 0;

        if ($avgScore < 65) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'weak_dimension',
                'message' => "{$weakGate} 维度平均分数较低（{$avgScore}分）",
                'suggestion' => '建议在该维度投入更多改进精力',
            ];
        }
    }

    // 检查效率
    if (isset($performance['efficiency_score']) && $performance['efficiency_score'] < 2) {
        $alerts[] = [
            'level' => 'info',
            'type' => 'efficiency',
            'message' => "改进效率较低（{$performance['efficiency_score']}分/秒）",
            'suggestion' => '考虑减少迭代次数或优化改进策略',
        ];
    }

    // 检查改进趋势
    if (isset($stats['avg_improvement']) && $stats['avg_improvement'] < 5) {
        $alerts[] = [
            'level' => 'warning',
            'type' => 'improvement',
            'message' => "平均改进分数较低（{$stats['avg_improvement']}分）",
            'suggestion' => '建议检查问题识别的准确性或调整改进指令',
        ];
    }

    return $alerts;
}

/**
 * 生成仪表盘摘要
 */
function generateDashboardSummary(array $stats, array $performance): array
{
    $status = 'normal';
    $message = '迭代改进系统运行正常';

    if (isset($stats['success_rate']) && $stats['success_rate'] < 50) {
        $status = 'warning';
        $message = '采纳率偏低，建议调整参数';
    }

    if (isset($performance['efficiency_score']) && $performance['efficiency_score'] < 1) {
        $status = 'critical';
        $message = '效率严重低下，建议优化策略';
    }

    return [
        'status' => $status,
        'message' => $message,
        'recommendation' => getOverallRecommendation($stats, $performance),
    ];
}

/**
 * 获取整体建议
 */
function getOverallRecommendation(array $stats, array $performance): string
{
    if (($stats['avg_improvement'] ?? 0) >= 10 && ($stats['success_rate'] ?? 0) >= 70) {
        return '迭代策略效果优秀，建议保持当前配置';
    }

    if (($stats['avg_improvement'] ?? 0) >= 5) {
        return '迭代有一定效果，可以考虑微调参数提升效果';
    }

    return '迭代效果不佳，建议大幅调整参数或检查系统问题';
}

/**
 * 获取章节迭代详情
 */
function getChapterIterationDetail(int $novelId, int $chapterId): array
{
    try {
        $chapter = DB::fetch(
            'SELECT * FROM chapters WHERE id = ? AND novel_id = ?',
            [$chapterId, $novelId]
        );

        if (!$chapter) {
            throw new Exception('章节不存在');
        }

        $history = json_decode($chapter['iterative_history'] ?? '[]', true);
        $evaluation = json_decode($chapter['iteration_evaluation'] ?? '[]', true);

        // 使用 ImprovementEvaluator 进行分析
        $evaluator = new ImprovementEvaluator($novelId, $chapterId);
        if (!empty($history)) {
            $detailedAnalysis = $evaluator->evaluateOverall($history);
        } else {
            $detailedAnalysis = null;
        }

        return [
            'chapter' => [
                'id' => $chapter['id'],
                'number' => $chapter['chapter_number'],
                'title' => $chapter['title'],
                'original_score' => $chapter['quality_score'] - ($chapter['total_improvement'] ?? 0),
                'final_score' => $chapter['quality_score'],
            ],
            'summary' => [
                'iterations_used' => $chapter['iterations_used'] ?? 0,
                'total_improvement' => $chapter['total_improvement'] ?? 0,
                'adopted' => ($chapter['total_improvement'] ?? 0) > 0,
                'rewrite_time' => $chapter['rewrite_time'],
            ],
            'iteration_history' => $history,
            'evaluation' => $detailedAnalysis,
        ];
    } catch (Exception $e) {
        error_log('获取章节详情失败：' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * 获取改进趋势
 */
function getImprovementTrends(int $novelId, int $periodDays = 30): array
{
    try {
        $startDate = date('Y-m-d H:i:s', strtotime("-{$periodDays} days"));

        $chapters = DB::fetchAll(
            'SELECT chapter_number, quality_score, total_improvement,
                    iterations_used, created_at
             FROM chapters
             WHERE novel_id = ? AND rewritten = 1 AND created_at >= ?
             ORDER BY chapter_number ASC',
            [$novelId, $startDate]
        );

        if (empty($chapters)) {
            return [
                'period' => $periodDays,
                'data_points' => 0,
                'trend' => 'insufficient_data',
                'chart_data' => [],
            ];
        }

        $chartData = [];
        foreach ($chapters as $ch) {
            $chartData[] = [
                'chapter' => $ch['chapter_number'],
                'score' => $ch['quality_score'],
                'improvement' => $ch['total_improvement'] ?? 0,
                'iterations' => $ch['iterations_used'] ?? 1,
                'date' => $ch['created_at'],
            ];
        }

        // 分析趋势
        $improvements = array_column($chartData, 'improvement');
        $trend = analyzeTrend($improvements);

        return [
            'period' => $periodDays,
            'data_points' => count($chapters),
            'trend' => $trend,
            'chart_data' => $chartData,
            'summary' => [
                'avg_improvement' => round(array_sum($improvements) / count($improvements), 2),
                'total_improvement' => round(array_sum($improvements), 2),
                'max_improvement' => max($improvements),
                'min_improvement' => min($improvements),
            ],
        ];
    } catch (Exception $e) {
        error_log('获取改进趋势失败：' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * 分析趋势
 */
function analyzeTrend(array $values): string
{
    if (count($values) < 3) {
        return 'insufficient_data';
    }

    $recentHalf = array_slice($values, -ceil(count($values) / 2));
    $olderHalf = array_slice($values, 0, floor(count($values) / 2));

    $recentAvg = array_sum($recentHalf) / count($recentHalf);
    $olderAvg = array_sum($olderHalf) / count($olderHalf);

    $change = $recentAvg - $olderAvg;

    if ($change > 2) {
        return 'improving';
    } elseif ($change < -2) {
        return 'declining';
    } else {
        return 'stable';
    }
}

/**
 * 执行诊断分析
 */
function performDiagnosticAnalysis(int $novelId): array
{
    $diagnostics = [];

    // 1. 检查配置合理性
    $diagnostics['configuration'] = diagnoseConfiguration($novelId);

    // 2. 检查迭代效率
    $diagnostics['efficiency'] = diagnoseEfficiency($novelId);

    // 3. 检查问题识别准确性
    $diagnostics['accuracy'] = diagnoseAccuracy($novelId);

    // 4. 生成优化建议
    $diagnostics['recommendations'] = generateOptimizationSuggestions($diagnostics);

    return $diagnostics;
}

/**
 * 诊断配置
 */
function diagnoseConfiguration(int $novelId): array
{
    try {
        $settings = DB::fetchAll(
            'SELECT setting_key, setting_value FROM iterative_settings WHERE novel_id = ?',
            [$novelId]
        );

        $config = [];
        foreach ($settings as $setting) {
            $config[$setting['setting_key']] = json_decode($setting['setting_value'], true);
        }

        $issues = [];

        // 检查各项参数
        $rewrite = $config['rewrite'] ?? [];
        $refinement = $config['iterative_refinement'] ?? [];

        if (($rewrite['threshold'] ?? 70) > 80) {
            $issues[] = '重写阈值过高，可能导致过多无谓的重写';
        }

        if (($rewrite['min_gain'] ?? 10) > 15) {
            $issues[] = '最低增益过高，可能导致有效重写被拒绝';
        }

        if (($refinement['max_iterations'] ?? 3) > 4) {
            $issues[] = '最大迭代次数过多，可能导致效率低下';
        }

        return [
            'status' => empty($issues) ? 'ok' : 'needs_adjustment',
            'issues' => $issues,
            'current_config' => array_merge($rewrite, $refinement),
        ];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * 诊断效率
 */
function diagnoseEfficiency(int $novelId): array
{
    $performance = calculatePerformanceMetrics($novelId);

    $issues = [];

    if (($performance['efficiency_score'] ?? 0) < 1) {
        $issues[] = '效率分数过低，每秒改进不足1分';
    }

    if (($performance['cost_benefit_ratio'] ?? 0) < 3) {
        $issues[] = '成本效益比较低，每次迭代的收益有限';
    }

    return [
        'status' => empty($issues) ? 'ok' : 'needs_optimization',
        'issues' => $issues,
        'metrics' => $performance,
    ];
}

/**
 * 诊断准确性
 */
function diagnoseAccuracy(int $novelId): array
{
    $patterns = analyzeCommonIssues($novelId);

    $issues = [];

    if (isset($patterns['top_issues'])) {
        $topIssue = array_key_first($patterns['top_issues'] ?? []) ?? '';
        if ($topIssue && ($patterns['top_issues'][$topIssue] ?? 0) > 5) {
            $issues[] = "{$topIssue} 问题频繁出现，可能需要针对性优化";
        }
    }

    return [
        'status' => empty($issues) ? 'ok' : 'needs_attention',
        'issues' => $issues,
        'patterns' => $patterns,
    ];
}

/**
 * 生成优化建议
 */
function generateOptimizationSuggestions(array $diagnostics): array
{
    $suggestions = [];

    // 基于配置诊断的建议
    if (($diagnostics['configuration']['status'] ?? '') !== 'ok') {
        foreach ($diagnostics['configuration']['issues'] ?? [] as $issue) {
            $suggestions[] = [
                'priority' => 'high',
                'category' => 'configuration',
                'issue' => $issue,
            ];
        }
    }

    // 基于效率诊断的建议
    if (($diagnostics['efficiency']['status'] ?? '') !== 'ok') {
        foreach ($diagnostics['efficiency']['issues'] ?? [] as $issue) {
            $suggestions[] = [
                'priority' => 'medium',
                'category' => 'efficiency',
                'issue' => $issue,
            ];
        }
    }

    return $suggestions;
}

/**
 * 分析改进模式
 */
function analyzeImprovementPatterns(int $novelId): array
{
    try {
        $chapters = DB::fetchAll(
            'SELECT iterative_history FROM chapters
             WHERE novel_id = ? AND rewritten = 1 AND iterative_history IS NOT NULL',
            [$novelId]
        );

        $patterns = [
            'by_iteration' => [],
            'by_gate' => [],
            'improvement_distribution' => [],
        ];

        $iterationImprovements = [];
        $gateImprovements = [];

        foreach ($chapters as $chapter) {
            $history = json_decode($chapter['iterative_history'], true);
            if (!is_array($history)) continue;

            foreach ($history as $iteration) {
                $iterNum = $iteration['iteration'] ?? 1;
                $improvement = $iteration['improvement'] ?? 0;

                $iterationImprovements[$iterNum] = ($iterationImprovements[$iterNum] ?? 0) + $improvement;

                // 按门统计
                if (isset($iteration['evaluation']['gate_results'])) {
                    foreach ($iteration['evaluation']['gate_results'] as $idx => $gate) {
                        $gateName = 'gate' . ($idx + 1);
                        $gateImprovements[$gateName] = ($gateImprovements[$gateName] ?? 0) + ($gate['score'] ?? 0);
                    }
                }
            }
        }

        // 计算平均值
        $iterCounts = [];
        foreach ($iterationImprovements as $iter => $total) {
            $iterCounts[$iter] = ($iterCounts[$iter] ?? 0) + 1;
        }

        foreach ($iterationImprovements as $iter => $total) {
            $count = $iterCounts[$iter] ?? 1;
            $patterns['by_iteration'][$iter] = round($total / $count, 2);
        }

        // 改进分布
        $allImprovements = [];
        foreach ($chapters as $chapter) {
            $allImprovements[] = $chapter['total_improvement'] ?? 0;
        }

        $patterns['improvement_distribution'] = [
            'avg' => count($allImprovements) > 0 ? round(array_sum($allImprovements) / count($allImprovements), 2) : 0,
            'above_10' => count(array_filter($allImprovements, fn($i) => $i > 10)),
            'below_5' => count(array_filter($allImprovements, fn($i) => $i < 5)),
        ];

        return $patterns;
    } catch (Exception $e) {
        error_log('分析改进模式失败：' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}
