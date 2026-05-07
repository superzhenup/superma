<?php
/**
 * 优化决策Agent
 * 
 * 职责: 根据历史数据优化系统参数
 * 
 * 优化领域:
 * - 性能优化(写作速度、API调用效率)
 * - 成本优化(Token使用、资源分配)
 * - 质量优化(评分提升、问题减少)
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseAgent.php';

class OptimizationAgent extends BaseAgent
{
    /**
     * 构造函数
     * 
     * @param int $novelId 小说ID
     */
    public function __construct(int $novelId)
    {
        parent::__construct('optimization', $novelId);
    }
    
    /**
     * 决策: 优化系统参数
     * 
     * @param array $context 决策上下文
     * @return array 决策结果
     */
    public function decide(array $context): array
    {
        $startTime = microtime(true);
        
        // 1. 分析历史数据
        $historicalAnalysis = $this->analyzeHistoricalData();
        
        // 2. 识别优化机会
        $opportunities = $this->identifyOptimizationOpportunities($historicalAnalysis);
        
        // 3. 评估优化方案
        $proposals = $this->evaluateOptimizationProposals($opportunities);
        
        // 4. 选择最佳方案
        $selected = $this->selectBestProposals($proposals);
        
        // 5. 记录决策日志
        $decisionData = [
            'historical_analysis' => $historicalAnalysis,
            'opportunities' => $opportunities,
            'proposals' => $proposals,
            'selected_optimizations' => $selected,
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ];
        
        $this->logDecision($decisionData);
        
        return [
            'success' => true,
            'historical_analysis' => $historicalAnalysis,
            'opportunities' => $opportunities,
            'selected_optimizations' => $selected,
        ];
    }
    
    /**
     * 分析历史数据
     * 
     * @return array 历史数据分析
     */
    private function analyzeHistoricalData(): array
    {
        return [
            'performance_trends' => $this->analyzePerformanceTrends(),
            'quality_trends' => $this->analyzeQualityTrends(),
            'cost_efficiency' => $this->analyzeCostEfficiency(),
            'directive_effectiveness' => $this->analyzeDirectiveEffectiveness(),
        ];
    }
    
    /**
     * 识别优化机会
     * 
     * @param array $analysis 历史分析
     * @return array 优化机会
     */
    private function identifyOptimizationOpportunities(array $analysis): array
    {
        $opportunities = [];
        
        // 性能优化机会
        if ($analysis['performance_trends']['avg_write_time'] > 30) {
            $opportunities[] = [
                'type' => 'performance',
                'area' => 'write_speed',
                'current' => $analysis['performance_trends']['avg_write_time'],
                'target' => 20,
                'potential_gain' => '33%速度提升',
            ];
        }
        
        // 成本优化机会
        if ($analysis['cost_efficiency']['token_efficiency'] < 0.7) {
            $opportunities[] = [
                'type' => 'cost',
                'area' => 'token_usage',
                'current' => $analysis['cost_efficiency']['token_efficiency'],
                'target' => 0.85,
                'potential_gain' => '20%成本节省',
            ];
        }
        
        // 质量优化机会
        if ($analysis['quality_trends']['improvement_rate'] < 0) {
            $opportunities[] = [
                'type' => 'quality',
                'area' => 'overall_quality',
                'current' => $analysis['quality_trends']['current_score'],
                'target' => $analysis['quality_trends']['previous_score'],
                'potential_gain' => '质量回升',
            ];
        }
        
        return $opportunities;
    }
    
    /**
     * 评估优化方案
     * 
     * @param array $opportunities 优化机会
     * @return array 优化方案
     */
    private function evaluateOptimizationProposals(array $opportunities): array
    {
        $proposals = [];
        
        foreach ($opportunities as $opp) {
            $proposal = $this->generateProposal($opp);
            if ($proposal) {
                $proposal['roi'] = $this->calculateROI($proposal);
                $proposal['risk'] = $this->assessRisk($proposal);
                $proposal['feasibility'] = $this->assessFeasibility($proposal);
                $proposals[] = $proposal;
            }
        }
        
        return $proposals;
    }
    
    /**
     * 生成优化方案
     * 
     * @param array $opportunity 优化机会
     * @return array|null 优化方案
     */
    private function generateProposal(array $opportunity): ?array
    {
        $templates = [
            'write_speed' => [
                'actions' => [
                    ['name' => 'smart_truncation', 'improvement' => 0.15, 'cost' => 'low'],
                ],
            ],
            'token_usage' => [
                'actions' => [
                    ['name' => 'compress_context', 'improvement' => 0.30, 'cost' => 'medium'],
                    ['name' => 'smart_truncation', 'improvement' => 0.20, 'cost' => 'low'],
                ],
            ],
            'overall_quality' => [
                'actions' => [
                    ['name' => 'enhance_prompt', 'improvement' => 0.15, 'cost' => 'low'],
                    ['name' => 'strengthen_check', 'improvement' => 0.20, 'cost' => 'medium'],
                ],
            ],
        ];
        
        if (!isset($templates[$opportunity['area']])) {
            return null;
        }
        
        return [
            'type' => $opportunity['type'],
            'area' => $opportunity['area'],
            'current' => $opportunity['current'],
            'target' => $opportunity['target'],
            'actions' => $templates[$opportunity['area']]['actions'],
            'estimated_improvement' => $templates[$opportunity['area']]['actions'][0]['improvement'],
            'potential_gain' => $opportunity['potential_gain'],
        ];
    }
    
    /**
     * 选择最佳方案
     * 
     * @param array $proposals 优化方案
     * @return array 选中的方案
     */
    private function selectBestProposals(array $proposals): array
    {
        // 按ROI排序
        usort($proposals, function($a, $b) {
            return $b['roi'] <=> $a['roi'];
        });
        
        // 选择ROI最高且风险可接受的方案
        return array_filter($proposals, function($p) {
            return $p['roi'] > 0.1 && $p['risk'] < 0.5;
        });
    }
    
    /**
     * 执行优化
     * 
     * @param array $selectedOptimizations 选中的优化方案
     * @return array 执行结果
     */
    public function execute(array $selectedOptimizations): array
    {
        $results = [];
        
        foreach ($selectedOptimizations as $opt) {
            foreach ($opt['actions'] as $action) {
                $result = $this->executeAction($action);
                $results[] = $result;
            }
        }
        
        return $results;
    }
    
    /**
     * 执行单个优化动作
     * 
     * @param array $action 动作配置
     * @return array 执行结果
     */
    private function executeAction(array $action): array
    {
        try {
            switch ($action['name']) {
                case 'compress_context':
                    $this->logAction($this->novelId, $action['name'], 'success');
                    $this->writeDirective('optimization', '本章上下文精简：前情提要控制在300字以内，去除重复的角色描述和世界观说明，只保留与本章情节直接相关的核心信息。如果上下文接近超限，优先裁剪"近章大纲"和"故事线回顾"的长度。');
                    return ['action' => $action['name'], 'status' => 'success', 'message' => '已注入上下文精简指令'];

                case 'smart_truncation':
                    $this->logAction($this->novelId, $action['name'], 'success');
                    $this->writeDirective('optimization', '本章要求精简表达：删除重复的形容词堆砌、不推动剧情的过渡段落、过多的环境描写。用简洁有力的短句代替长句铺陈，保持叙事紧凑感。');
                    return ['action' => $action['name'], 'status' => 'success', 'message' => '已注入精简表达指令'];

                case 'enhance_prompt':
                    $this->logAction($this->novelId, $action['name'], 'success');
                    $this->writeDirective('optimization', '本章要求提升文笔质量：对话必须有言外之意或潜台词，动作描写使用具体动词代替笼统表述，心理描写用身体反应代替直接陈述。每段对话后至少附带一个微表情或肢体动作描写。');
                    return ['action' => $action['name'], 'status' => 'success', 'message' => '已注入文笔提升指令'];

                case 'strengthen_check':
                    $this->logAction($this->novelId, $action['name'], 'success');
                    $this->writeDirective('quality', '本章执行严格逻辑检查：每个事件必须有明确的因果动机，角色做出重要决定前必须有心理铺垫，不得出现"突然就""莫名其妙"式的剧情转折。所有伏笔变化必须与前文伏笔描写一致。');
                    return ['action' => $action['name'], 'status' => 'success', 'message' => '已注入逻辑检查指令'];
                
                default:
                    return ['action' => $action['name'], 'status' => 'skipped', 'message' => '未实现的操作'];
            }
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, $action['name'], 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => $action['name'],
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    // ==================== 辅助分析方法 ====================
    
    /**
     * 分析性能趋势（基于chapters表的真实duration_ms数据）
     */
    private function analyzePerformanceTrends(): array
    {
        try {
            // v1.5: 使用 chapters.duration_ms（真实写入数据），替代不存在的 performance_logs
            $stats = DB::fetch(
                'SELECT AVG(duration_ms) as avg_time, 
                        MAX(duration_ms) as max_time,
                        MIN(duration_ms) as min_time,
                        COUNT(*) as sample_count
                 FROM chapters 
                 WHERE novel_id = ? AND status = "completed" 
                   AND duration_ms > 0
                   AND chapter_number >= GREATEST(1, (SELECT MAX(chapter_number) - 20 FROM chapters WHERE novel_id = ? AND status = "completed"))',
                [$this->novelId, $this->novelId]
            );
            
            $avgTime = (float)($stats['avg_time'] ?? 0);
            return [
                'avg_write_time' => $avgTime > 0 ? $avgTime / 1000 : 25.0,
                'max_write_time' => (float)($stats['max_time'] ?? 0) / 1000,
                'min_write_time' => (float)($stats['min_time'] ?? 0) / 1000,
                'sample_count' => (int)($stats['sample_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['avg_write_time' => 25.0, 'sample_count' => 0];
        }
    }
    
    /**
     * 分析质量趋势
     */
    private function analyzeQualityTrends(): array
    {
        try {
            $recent = DB::fetchAll(
                'SELECT quality_score FROM chapters 
                 WHERE novel_id = ? AND quality_score IS NOT NULL 
                 ORDER BY chapter_number DESC LIMIT 10',
                [$this->novelId]
            );
            
            if (count($recent) < 2) {
                return ['improvement_rate' => 0, 'current_score' => 75, 'previous_score' => 75];
            }
            
            $first5 = array_slice($recent, 0, 5);
            $last5 = array_slice($recent, 5, 5);
            
            $avgFirst = array_sum(array_column($first5, 'quality_score')) / count($first5);
            $avgLast = count($last5) > 0 ? array_sum(array_column($last5, 'quality_score')) / count($last5) : $avgFirst;
            
            return [
                'current_score' => $avgFirst,
                'previous_score' => $avgLast,
                'improvement_rate' => $avgLast > 0 ? ($avgFirst - $avgLast) / $avgLast : 0,
            ];
        } catch (\Throwable $e) {
            return ['improvement_rate' => 0, 'current_score' => 75, 'previous_score' => 75];
        }
    }
    
    /**
     * 分析成本效率
     */
    private function analyzeCostEfficiency(): array
    {
        try {
            $stats = DB::fetch(
                'SELECT SUM(tokens_used) as total_tokens, COUNT(*) as chapter_count 
                 FROM chapters 
                 WHERE novel_id = ? AND status = "completed"',
                [$this->novelId]
            );
            
            $totalTokens = (int)($stats['total_tokens'] ?? 0);
            $chapterCount = (int)($stats['chapter_count'] ?? 1);
            
            // 假设理想Token使用量为每章8000
            $idealTokens = $chapterCount * 8000;
            
            return [
                'token_efficiency' => $idealTokens > 0 ? min(1.0, $idealTokens / max(1, $totalTokens)) : 1.0,
                'total_tokens' => $totalTokens,
                'chapter_count' => $chapterCount,
            ];
        } catch (\Throwable $e) {
            return ['token_efficiency' => 1.0];
        }
    }
    
    /**
     * 计算ROI（融合指令效果反馈，v1.5升级）
     */
    private function calculateROI(array $proposal): float
    {
        $improvement = $proposal['estimated_improvement'] ?? 0;
        $risk = $this->assessRisk($proposal);
        $baseRoi = $improvement * (1 - $risk);
        
        // v1.5: 读取同类型指令的历史效果，调整ROI
        $effectivenessBonus = $this->getDirectiveEffectivenessBonus($proposal['type']);
        
        return $baseRoi * (1 + $effectivenessBonus);
    }
    
    /**
     * 评估风险
     */
    private function assessRisk(array $proposal): float
    {
        $riskFactors = [
            'write_speed' => 0.3,
            'token_usage' => 0.2,
            'overall_quality' => 0.4,
        ];
        
        return $riskFactors[$proposal['area']] ?? 0.5;
    }
    
    /**
     * 评估可行性
     */
    private function assessFeasibility(array $proposal): string
    {
        $cost = $proposal['actions'][0]['cost'] ?? 'medium';
        
        $feasibilityMap = [
            'low' => 'high',
            'medium' => 'medium',
            'high' => 'low',
        ];
        
        return $feasibilityMap[$cost] ?? 'medium';
    }
    
    /**
     * 分析指令效果（基于 agent_directive_outcomes 反馈数据）
     * 
     * @return array{by_type: array, overall_effectiveness: float}
     */
    private function analyzeDirectiveEffectiveness(): array
    {
        try {
            require_once __DIR__ . '/AgentDirectives.php';
            $stats = AgentDirectives::getOutcomeStats($this->novelId);
            
            $byType = [];
            foreach ($stats['by_type'] as $row) {
                $byType[$row['type']] = [
                    'avg_change' => round((float)$row['avg_change'], 2),
                    'improved' => (int)$row['improved'],
                    'declined' => (int)$row['declined'],
                    'total' => (int)$row['outcome_count'],
                    'effectiveness' => (int)$row['outcome_count'] > 0
                        ? round((int)$row['improved'] / (int)$row['outcome_count'], 2)
                        : 0,
                ];
            }
            
            // 整体有效性 = 所有改善次数 / 总评估次数
            $totalOutcomes = array_sum(array_column($stats['by_type'], 'outcome_count'));
            $totalImproved = array_sum(array_column($stats['by_type'], 'improved'));
            $overallEffectiveness = $totalOutcomes > 0 ? round($totalImproved / $totalOutcomes, 2) : 0;
            
            return [
                'by_type' => $byType,
                'overall_effectiveness' => $overallEffectiveness,
            ];
        } catch (\Throwable $e) {
            return ['by_type' => [], 'overall_effectiveness' => 0];
        }
    }
    
    /**
     * 获取特定类型指令的历史效果奖金系数
     * 
     * @param string $directiveType 指令类型: performance/cost/quality
     * @return float 奖金系数 [-0.3, +0.3]
     */
    private function getDirectiveEffectivenessBonus(string $directiveType): float
    {
        try {
            $typeMap = ['performance' => 'optimization', 'cost' => 'optimization', 'quality' => 'quality'];
            $dbType = $typeMap[$directiveType] ?? $directiveType;
            
            $stats = DB::fetch(
                'SELECT AVG(quality_change) as avg_change, 
                        SUM(CASE WHEN quality_change > 0 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) as improvement_rate
                 FROM agent_directive_outcomes o
                 JOIN agent_directives d ON o.directive_id = d.id
                 WHERE o.novel_id = ? AND d.type = ?
                 ORDER BY o.evaluated_at DESC LIMIT 20',
                [$this->novelId, $dbType]
            );
            
            if (empty($stats) || !$stats['avg_change']) return 0;
            
            $avgChange = (float)$stats['avg_change'];
            $improvementRate = (float)($stats['improvement_rate'] ?? 0);
            
            // 质量改善越多，奖金越高（最大±0.3）
            return round(max(-0.3, min(0.3, ($avgChange / 10) * $improvementRate)), 2);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
