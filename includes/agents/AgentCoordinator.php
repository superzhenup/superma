<?php
/**
 * Agent协调器
 * 
 * 职责: 协调三种Agent的决策和执行
 * 
 * 功能:
 * - 统一决策入口
 * - 协调执行顺序
 * - 处理决策冲突
 * - 生成综合报告
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseAgent.php';
require_once __DIR__ . '/WritingStrategyAgent.php';
require_once __DIR__ . '/QualityMonitorAgent.php';
require_once __DIR__ . '/OptimizationAgent.php';

class AgentCoordinator
{
    /** @var int 小说ID */
    private $novelId;
    
    /** @var WritingStrategyAgent */
    private $strategyAgent;
    
    /** @var QualityMonitorAgent */
    private $qualityAgent;
    
    /** @var OptimizationAgent */
    private $optimizationAgent;
    
    /** @var bool 是否启用Agent */
    private $enabled = true;
    
    /**
     * 构造函数
     * 
     * @param int $novelId 小说ID
     */
    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
        
        // 检查是否启用Agent
        $this->enabled = ConfigCenter::get('agent.enabled', true);
        
        if ($this->enabled) {
            $this->strategyAgent = new WritingStrategyAgent($novelId);
            $this->qualityAgent = new QualityMonitorAgent($novelId);
            $this->optimizationAgent = new OptimizationAgent($novelId);
        }
    }
    
    /**
     * 执行完整的Agent决策流程
     * 
     * @param array $context 决策上下文
     * @return array 决策结果
     */
    public function runDecisionCycle(array $context): array
    {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'message' => 'Agent决策机制已禁用',
            ];
        }
        
        $chNum = (int)($context['current_chapter_number'] ?? 0);
        $results = [
            'enabled' => true,
            'strategy' => null,
            'quality' => null,
            'optimization' => null,
            'final_actions' => [],
            'execution_summary' => [],
        ];
        
        try {
            // 1. 写作策略决策：每10章触发一次
            $stratInterval = (int)ConfigCenter::get('agent.strategy_agent.decision_interval', 10);
            if (ConfigCenter::get('agent.strategy_agent.enabled', true) && 
                $chNum > 0 && $chNum % $stratInterval === 0) {
                $results['strategy'] = $this->strategyAgent->decide($context);
            }
            
            // 2. 质量监控决策：每5章触发一次
            $qualInterval = (int)ConfigCenter::get('agent.quality_agent.check_interval', 5);
            if (ConfigCenter::get('agent.quality_agent.enabled', true) && 
                $chNum > 0 && $chNum % $qualInterval === 0) {
                $results['quality'] = $this->qualityAgent->decide($context);
            }
            
            // 3. 优化决策：每20章触发一次
            $optInterval = (int)ConfigCenter::get('agent.optimization_agent.optimization_interval', 20);
            if (ConfigCenter::get('agent.optimization_agent.enabled', true) && 
                $chNum > 0 && $chNum % $optInterval === 0) {
                $optimizationContext = array_merge($context, [
                    'strategy_decision' => $results['strategy'],
                    'quality_decision' => $results['quality'],
                ]);
                $results['optimization'] = $this->optimizationAgent->decide($optimizationContext);
            }
            
            // 4. 协调执行
            $results['final_actions'] = $this->coordinateExecution($results);
            
            // 5. 生成执行摘要
            $results['execution_summary'] = $this->generateExecutionSummary($results);
            
        } catch (\Throwable $e) {
            $results['error'] = $e->getMessage();
            error_log("Agent决策流程失败: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * 协调执行决策
     * 
     * @param array $results 决策结果
     * @return array 执行动作列表
     */
    private function coordinateExecution(array $results): array
    {
        $actions = [];
        
        // 优先执行质量改进(高优先级)
        if (!empty($results['quality']['recommendations'])) {
            $highPriority = array_filter($results['quality']['recommendations'], function($r) {
                return $r['priority'] >= 8;
            });
            
            if (!empty($highPriority)) {
                $execResults = $this->qualityAgent->execute($highPriority);
                $actions = array_merge($actions, $execResults);
            }
        }
        
        // 执行策略调整
        if (!empty($results['strategy']['decisions'])) {
            $strategyDecisions = $results['strategy']['decisions'];
            
            if (!empty($strategyDecisions['word_count_adjustment']) || 
                !empty($strategyDecisions['coolpoint_density_adjustment']) ||
                !empty($strategyDecisions['pacing_strategy'])) {
                
                $execResults = $this->strategyAgent->execute($strategyDecisions);
                $actions = array_merge($actions, $execResults);
            }
        }
        
        // 执行优化方案
        if (!empty($results['optimization']['selected_optimizations'])) {
            $execResults = $this->optimizationAgent->execute($results['optimization']['selected_optimizations']);
            $actions = array_merge($actions, $execResults);
        }
        
        return $actions;
    }
    
    /**
     * 生成执行摘要
     * 
     * @param array $results 决策结果
     * @return array 执行摘要
     */
    private function generateExecutionSummary(array $results): array
    {
        $summary = [
            'total_decisions' => 0,
            'total_actions' => 0,
            'successful_actions' => 0,
            'failed_actions' => 0,
            'quality_issues_found' => 0,
            'optimization_opportunities' => 0,
        ];
        
        // 统计决策数
        if (!empty($results['strategy'])) $summary['total_decisions']++;
        if (!empty($results['quality'])) $summary['total_decisions']++;
        if (!empty($results['optimization'])) $summary['total_decisions']++;
        
        // 统计动作执行
        if (!empty($results['final_actions'])) {
            $summary['total_actions'] = count($results['final_actions']);
            
            foreach ($results['final_actions'] as $action) {
                if ($action['status'] === 'success') {
                    $summary['successful_actions']++;
                } elseif ($action['status'] === 'failed') {
                    $summary['failed_actions']++;
                }
            }
        }
        
        // 统计质量问题
        if (!empty($results['quality']['issues'])) {
            $summary['quality_issues_found'] = count($results['quality']['issues']);
        }
        
        // 统计优化机会
        if (!empty($results['optimization']['opportunities'])) {
            $summary['optimization_opportunities'] = count($results['optimization']['opportunities']);
        }
        
        return $summary;
    }
    
    /**
     * 获取Agent状态报告
     * 
     * @return array 状态报告
     */
    public function getStatusReport(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false];
        }
        
        return [
            'enabled' => true,
            'strategy_agent' => [
                'recent_decisions' => $this->strategyAgent->getDecisionHistory(5),
                'statistics' => $this->strategyAgent->getStatistics(24),
            ],
            'quality_agent' => [
                'recent_decisions' => $this->qualityAgent->getDecisionHistory(5),
                'statistics' => $this->qualityAgent->getStatistics(24),
            ],
            'optimization_agent' => [
                'recent_decisions' => $this->optimizationAgent->getDecisionHistory(5),
                'statistics' => $this->optimizationAgent->getStatistics(24),
            ],
        ];
    }
    
    /**
     * 启用Agent
     * 
     * @return void
     */
    public function enable(): void
    {
        $this->enabled = true;
        ConfigCenter::set('agent.enabled', true, 'bool');
    }
    
    /**
     * 禁用Agent
     * 
     * @return void
     */
    public function disable(): void
    {
        $this->enabled = false;
        ConfigCenter::set('agent.enabled', false, 'bool');
    }
    
    /**
     * 检查是否启用
     * 
     * @return bool 是否启用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * 获取单个Agent
     * 
     * @param string $agentType Agent类型
     * @return BaseAgent|null
     */
    public function getAgent(string $agentType): ?BaseAgent
    {
        switch ($agentType) {
            case 'strategy':
                return $this->strategyAgent;
            case 'quality':
                return $this->qualityAgent;
            case 'optimization':
                return $this->optimizationAgent;
            default:
                return null;
        }
    }
}
