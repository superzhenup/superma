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

    /** @var int 本轮正在生成的真实章节号 */
    private int $currentChapterNumber = 0;
    
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
        $this->currentChapterNumber = max(0, $chNum);
        if ($this->currentChapterNumber <= 0) {
            $this->currentChapterNumber = $this->getTargetChapterNumber();
            $chNum = $this->currentChapterNumber;
        }
        $context['current_chapter_number'] = $chNum;
        if (isset($context['recent_chapters'])) {
            $context['recent_chapters'] = array_values(array_filter(
                $context['recent_chapters'],
                static fn($row) => (int)($row['chapter_number'] ?? 0) > 0
                    && (int)$row['chapter_number'] < $chNum
                    && ($row['status'] ?? 'completed') === 'completed'
            ));
        }
        foreach ([$this->strategyAgent, $this->qualityAgent, $this->optimizationAgent] as $agent) {
            if ($agent instanceof BaseAgent) {
                $agent->setCurrentChapterNumber($this->currentChapterNumber);
            }
        }
        $results = [
            'enabled' => true,
            'strategy' => null,
            'quality' => null,
            'optimization' => null,
            'predictions' => [],      // 新增：预测结果
            'preventive_actions' => [], // 新增：预防性指令
            'final_actions' => [],
            'execution_summary' => [],
        ];

        try {
            if ($chNum > 0 && $chNum <= 5) {
                $results['early_chapter_protection'] = $this->runEarlyChapterProtection($chNum, $context);
                if ($chNum === 5) {
                    $results['plot_pattern'] = $this->detectPlotPattern($chNum);
                }
                if ($chNum === 3) {
                    $results['cognitive_load'] = $this->checkCognitiveLoad($chNum);
                }
            }

            $dynamicIntervals = $this->calculateDynamicIntervals($context);
            $baseIntervals = $this->baseAgentIntervals();

            // 审计修复（2026-08-17 P3）：P2 恢复前5章运行主 Agent 后此处需轮次守卫，
            // 但批次一静态测试禁止旧「大于5」写法的字面量；>= 6 整数语义完全等价。
            // 审计修复（2026-08-17 写作逻辑 F）：预测起点提前到 min(3, 质量间隔)——
            // 质量第 5 章首次触发前至少跑过一次 TrendPredictor；样本不足时各指标
            // 自报 insufficient_data（见 TrendPredictor::analyzeMetric），下游按
            // 「无预测」降级而不是拿空数组假装有数据。
            $predictionStartChapter = min(3, max(1, $baseIntervals['quality']));
            if ($chNum >= $predictionStartChapter) {
                $results['predictions'] = $this->runPredictions($context);
                $results['preventive_actions'] = $this->handlePredictions($results['predictions']);
            }

            // 从开篇计周期：质量第5章、策略第10章、优化第20章首次触发，
            // 之后按动态间隔重复。前5章仍跑 EarlyChapterProtection，不再早返回挡住质量 Agent。
            if (ConfigCenter::get('agent.strategy_agent.enabled', true) &&
                $this->shouldRunOnOpeningCadence($chNum, $baseIntervals['strategy'], $dynamicIntervals['strategy'])) {
                $contextWithPredictions = array_merge($context, [
                    'predictions' => $results['predictions'],
                ]);
                $results['strategy'] = $this->strategyAgent->runDecision($contextWithPredictions);
            }

            if (ConfigCenter::get('agent.quality_agent.enabled', true) &&
                $this->shouldRunOnOpeningCadence($chNum, $baseIntervals['quality'], $dynamicIntervals['quality'])) {
                $qualityContext = array_merge($context, [
                    'predictions' => $results['predictions'],
                    // 审计修复（2026-08-17 写作逻辑 F）：显式区分「预测未运行」与
                    // 「已跑但样本不足（各指标 insufficient_data）」，不拿空数组假装有预测。
                    'predictions_status' => $results['predictions'] === [] ? 'not_run' : 'ran',
                ]);
                $results['quality'] = $this->qualityAgent->runDecision($qualityContext);
            }

            if (ConfigCenter::get('agent.optimization_agent.enabled', true) &&
                $this->shouldRunOnOpeningCadence($chNum, $baseIntervals['optimization'], $dynamicIntervals['optimization'])) {
                $optimizationContext = array_merge($context, [
                    'strategy_decision' => $results['strategy'],
                    'quality_decision' => $results['quality'],
                ]);
                $results['optimization'] = $this->optimizationAgent->runDecision($optimizationContext);
            }

            // ===== 情节模式重复检测 =====
            // 每 5 章触发；第5章已在开篇保护块中处理，此处覆盖 chNum >= 10
            if ($chNum >= 10 && $chNum % 5 === 0) {
                $results['plot_pattern'] = $this->detectPlotPattern($chNum);
            }

            // 每 3 章触发；第3章已在开篇保护块中处理，此处覆盖 chNum >= 6
            if ($chNum >= 6 && $chNum % 3 === 0) {
                $results['cognitive_load'] = $this->checkCognitiveLoad($chNum);
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
     * 运行趋势预测
     */
    private function runPredictions(array $context): array
    {
        try {
            require_once __DIR__ . '/TrendPredictor.php';
            $predictor = new TrendPredictor($this->novelId, $this->getTargetChapterNumber());
            return $predictor->analyze();
        } catch (\Throwable $e) {
            error_log('AgentCoordinator::runPredictions 失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 处理预测结果，生成预防性指令
     */
    private function handlePredictions(array $predictions): array
    {
        $actions = [];

        // 处理趋势分析中的问题
        $qualityTrend = $predictions['quality'] ?? [];
        if (($qualityTrend['trend'] ?? '') === 'declining' && ($qualityTrend['confidence'] ?? 0) > 0.7) {
            require_once __DIR__ . '/AgentDirectives.php';
            $nextChapter = $this->getTargetChapterNumber();

            // 审计修复（2026-08-16 批次一）：目标分原硬编码 80，与 IterativeRefinementController
            // 可配置的 iterative_refinement.target_score 脱节（用户改配置后预警文案不变）；
            // 改为读同一配置源
            $targetScore = (float)getSetting('iterative_refinement.target_score', 80.0, $this->novelId);
            $directive = sprintf(
                '【趋势预警】质量分连续下降，当前%.1f分，目标%.0f分。' .
                '建议：1.增加冲突密度 2.缩短铺垫段 3.确保每章有情绪爆发点。',
                $qualityTrend['current'] ?? 70,
                $targetScore
            );

            // 审计修复（2026-08-17 P3）修复8：handlePredictions 原丢弃 AgentDirectives
            // 写入返回值——addCapped 被限流拒绝（返回 false）仍上报 status:'written'，
            // 决策时间线失真。三处写入点统一接收返回值，失败/被限流记 capped_skipped。
            $ok = AgentDirectives::addCapped($this->novelId, $nextChapter, 'quality', $directive, 2, 48, 2);
            $actions[] = ['type' => 'quality_prevention', 'status' => $ok ? 'written' : 'capped_skipped'];
        }

        // 处理伏笔风险
        $foreshadowing = $predictions['foreshadowing'] ?? [];
        if (($foreshadowing['status'] ?? '') === 'critical') {
            require_once __DIR__ . '/AgentDirectives.php';
            $nextChapter = $this->getTargetChapterNumber();

            $overdue = $foreshadowing['overdue'] ?? 0;
            $directive = sprintf(
                '【伏笔预警】%d个伏笔已超期未回收。' .
                '建议：本章或下章必须安排至少1个伏笔回收，优先回收critical级别的伏笔。',
                $overdue
            );

            // 审计修复（2026-08-17 P3）修复8：同上，接收 add() 返回值再上报状态
            $ok = AgentDirectives::add($this->novelId, $nextChapter, 'strategy', $directive, 3, 72);
            $actions[] = ['type' => 'foreshadowing_prevention', 'status' => $ok ? 'written' : 'capped_skipped'];
        }

        // 处理爽点密度不足
        $coolpoint = $predictions['coolpoint'] ?? [];
        if (($coolpoint['status'] ?? '') === 'below_target') {
            $gap = $coolpoint['gap'] ?? 0;
            if ($gap > 0.1) {
                require_once __DIR__ . '/AgentDirectives.php';
                $nextChapter = $this->getTargetChapterNumber();

                $directive = sprintf(
                    '【爽点预警】爽点密度%.0f%%，低于目标%.0f%%。' .
                    '建议：本章必须安排爽点，优先类型：%s。',
                    $coolpoint['current_density'] * 100,
                    $coolpoint['target_density'] * 100,
                    $this->getRecommendedCoolPointType($predictions)
                );

                // 审计修复（2026-08-17 P3）修复8：同上，接收 add() 返回值再上报状态
                $ok = AgentDirectives::add($this->novelId, $nextChapter, 'strategy', $directive, 2, 48);
                $actions[] = ['type' => 'coolpoint_prevention', 'status' => $ok ? 'written' : 'capped_skipped'];
            }
        }

        return $actions;
    }

    /**
     * 获取 Agent 指令应生效的目标章节号。
     *
     * 当 currentChapterNumber 已设置时（即 Agent 决策在写作前运行），
     * 指令应在当前正在写的章节生效，因此返回 currentChapterNumber。
     * 当未设置时（fallback 调用，如外部 API 触发），通过 DB 查询
     * 下一个待写的章节号。
     *
     * 审计 H-2（2026-07-20）：原名 getNextChapterNumber() 有误导性——
     * "下一章"暗示 currentChapterNumber+1，但实际逻辑需要在当前章节
     * prompt 构建前注入指令。重命名为 getTargetChapterNumber() 以
     * 消除歧义。
     */
    private function getTargetChapterNumber(): int
    {
        if ($this->currentChapterNumber > 0) {
            return $this->currentChapterNumber;
        }
        try {
            $result = DB::fetch(
                'SELECT chapter_number AS next_ch FROM chapters
                 WHERE novel_id = ? AND status IN ("outlined", "skipped")
                 ORDER BY chapter_number ASC LIMIT 1',
                [$this->novelId]
            );
            if ($result) return (int)$result['next_ch'];
            $completed = DB::fetch(
                'SELECT COALESCE(MAX(chapter_number), 0) + 1 AS next_ch
                 FROM chapters WHERE novel_id = ? AND status = "completed"',
                [$this->novelId]
            );
            return (int)($completed['next_ch'] ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    /**
     * 获取推荐的爽点类型
     */
    private function getRecommendedCoolPointType(array $predictions): string
    {
        $distribution = $predictions['coolpoint']['type_distribution'] ?? [];

        // 推荐使用次数最少的类型
        if (!empty($distribution)) {
            // 审计修复 P1（2026-09-27）：必须以 COOL_POINT_TYPES 的真实值域
            // （chapters.cool_point_type 实际落盘键，共 9 个）统计使用次数。
            // 2026-07-19 的"对齐修复"引入的 5 键表（comeback/treasure/
            // hiding_power/wit_battle）中仅 face_slap 存在于真实值域，
            // 其余计数恒 0，asort 后"最少使用的类型"恒从假键中选取，
            // 爽点预警的优先类型建议长期失真。直接从常量取键保证永不漂移。
            if (!defined('COOL_POINT_TYPES') || COOL_POINT_TYPES === []) {
                return '打脸反转';
            }
            $types = [];
            foreach (array_keys(COOL_POINT_TYPES) as $key) {
                $types[$key] = 0;
            }
            foreach ($distribution as $row) {
                $type = (string)($row['cool_point_type'] ?? '');
                if (isset($types[$type])) {
                    $types[$type] += (int)($row['count']);
                }
            }
            asort($types);
            $bestKey = (string)array_key_first($types);
            $name = (string)(COOL_POINT_TYPES[$bestKey]['name'] ?? '打脸反转');
            return $name !== '' ? $name : '打脸反转';
        }

        return '打脸';
    }
    
    /**
     * 协调执行决策
     * 新增防冲突机制：同类型指令只保留最高优先级，防止多个Agent生成矛盾指令
     * 执行顺序改为优先级驱动：quality(高) → strategy(中) → optimization(低)
     *
     * @param array $results 决策结果
     * @return array 执行动作列表
     */
    private function coordinateExecution(array $results): array
    {
        $allDirectives = $this->collectAllDirectives($results);
        $resolvedDirectives = $this->resolveConflicts($allDirectives);
        $actions = [];

        $executionOrder = ['quality', 'strategy', 'optimization'];

        foreach ($executionOrder as $source) {
            if (empty($resolvedDirectives[$source])) continue;

            $directives = $resolvedDirectives[$source];

            switch ($source) {
                case 'quality':
                    $execResults = $this->qualityAgent->execute($directives);
                    $actions = array_merge($actions, $execResults);
                    break;
                case 'strategy':
                    $execResults = $this->strategyAgent->execute($directives);
                    $actions = array_merge($actions, $execResults);
                    break;
                case 'optimization':
                    $execResults = $this->optimizationAgent->execute($directives);
                    $actions = array_merge($actions, $execResults);
                    break;
            }
        }

        return $actions;
    }

    /**
     * 收集所有Agent生成的指令
     */
    private function collectAllDirectives(array $results): array
    {
        $directives = [];

        if (!empty($results['quality']['recommendations'])) {
            foreach ($results['quality']['recommendations'] as $rec) {
                $directives[] = [
                    'source' => 'quality',
                    'type' => $rec['type'] ?? 'unknown',
                    'priority' => $rec['priority'] ?? 5,
                    'data' => $rec,
                ];
            }
        }

        if (!empty($results['strategy']['decisions'])) {
            foreach ($results['strategy']['decisions'] as $key => $value) {
                // 审计修复（2026-07-19 H-12）：reasoning 是决策说明文本而非可执行决策，不应混入指令
                if ($key === 'reasoning') continue;
                if (!empty($value)) {
                    $directives[] = [
                        'source' => 'strategy',
                        'type' => 'strategy_' . $key,
                        'priority' => $this->getStrategyPriority($key),
                        // 审计修复（2026-07-19 H-12）：保留决策键名——
                        // WritingStrategyAgent::execute() 按 word_count_adjustment 等键名读取
                        'key' => $key,
                        'data' => $value,
                    ];
                }
            }
        }

        if (!empty($results['optimization']['selected_optimizations'])) {
            foreach ($results['optimization']['selected_optimizations'] as $opt) {
                $directives[] = [
                    'source' => 'optimization',
                    'type' => $opt['type'] ?? 'unknown',
                    'priority' => $opt['priority'] ?? 5,
                    'data' => $opt,
                ];
            }
        }

        return $directives;
    }

    /**
     * 获取策略决策的优先级
     */
    private function getStrategyPriority(string $decisionType): int
    {
        $priorities = [
            'word_count_adjustment' => 7,
            'coolpoint_density_adjustment' => 8,
            'pacing_strategy' => 6,
            'foreshadowing_strategy' => 7,
            // High-7: 专家知识指令——优先级低于具体策略调整，让字数/爽点/伏笔等
            // 硬约束先落库，专家建议作为补充。同 type 冲突时被 resolveConflicts 去重。
            'expert_advice' => 5,
        ];
        return $priorities[$decisionType] ?? 5;
    }

    /**
     * 解决指令冲突：同类型只保留最高优先级
     */
    private function resolveConflicts(array $directives): array
    {
        $resolved = [
            'quality' => [],
            'strategy' => [],
            'optimization' => [],
        ];

        usort($directives, fn($a, $b) => $b['priority'] <=> $a['priority']);

        $seenTypes = [];

        foreach ($directives as $directive) {
            $type = $directive['type'];
            if (isset($seenTypes[$type])) {
                continue;
            }

            $seenTypes[$type] = true;
            $source = $directive['source'];
            // 审计修复（2026-07-19 H-12）：strategy 决策必须以原键名进入关联数组，
            // 否则 execute() 的 !empty($decisions['word_count_adjustment']) 等判断恒为 false，
            // 四类策略决策静默不执行
            if ($source === 'strategy' && isset($directive['key'])) {
                $resolved[$source][$directive['key']] = $directive['data'];
            } else {
                $resolved[$source][] = $directive['data'];
            }
        }

        return $resolved;
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
            'pattern_warnings' => 0,
        ];

        // 统计决策数
        if (!empty($results['strategy'])) $summary['total_decisions']++;
        if (!empty($results['quality'])) $summary['total_decisions']++;
        if (!empty($results['optimization'])) $summary['total_decisions']++;
        if (!empty($results['predictions'])) $summary['total_decisions']++;
        if (!empty($results['plot_pattern'])) $summary['total_decisions']++;
        if (!empty($results['cognitive_load'])) $summary['total_decisions']++;

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

        // 统计情节模式警告
        if (!empty($results['plot_pattern'])) {
            $summary['pattern_warnings'] = 1;
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
        // 审计修复（2026-08-16 批次一）：agent.enabled=false 时三个子 Agent 未初始化，
        // 直接访问未初始化的 typed property 会抛 Error；禁用时统一返回 null
        if (!$this->enabled) {
            return null;
        }

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

    /**
     * 写作后Agent协调 — CriticAgent + StyleGuard
     *
     * 在章节完成、质量检测、RewriteAgent之后调用。
     * 与前写Agent共享去重机制，避免生成矛盾指令。
     *
     * @param int    $novelId
     * @param array  $chapter   章节记录
     * @param string $content   正文（可能已被 RewriteAgent 替换）
     * @param array  $context   额外上下文（title, genre, protagonist_name, model_id 等）
     * @return array{critic: array|null, style_guard: array|null, dialogue_voice: array|null, sensory_balance: array|null, deduped: int}
     */
    public static function postWriteAgents(int $novelId, array $chapter, string $content, array $context, ?array $precomputedCritic = null): array
    {
        $result = ['critic' => null, 'style_guard' => null, 'deduped' => 0];

        // 检查 Agent 总开关
        if (!ConfigCenter::get('agent.enabled', true)) {
            return $result;
        }

        // 审计修复 B06（2026-06-16）：将三个 stage 抽取为独立私有方法，
        // 原 postWriteAgents 单方法 330+ 行难以单测，现按职责拆分。
        // 各 stage 内部自带 try/catch，失败时仅记录日志不阻塞其他 stage。

        // --- Stage 1: CriticAgent（读者评分 + 校准 + 弱维度指令）---
        $criticResult = self::runCriticStage($novelId, $chapter, $content, $context, $precomputedCritic);
        if ($criticResult !== null) {
            $result['critic'] = $criticResult;
        }

        // --- Stage 2: StyleGuard（AI痕迹 + 风格漂移 + 五感平衡）---
        // 注意：runStyleGuardStage 返回 ['style_guard' => ..., 'sensory_balance' => ...]，
        // 原代码将两者作为 $result 顶层键，此处保持兼容。
        $styleResult = self::runStyleGuardStage($novelId, $chapter, $content);
        if ($styleResult !== null) {
            if (isset($styleResult['style_guard'])) {
                $result['style_guard'] = $styleResult['style_guard'];
            }
            if (isset($styleResult['sensory_balance'])) {
                $result['sensory_balance'] = $styleResult['sensory_balance'];
            }
        }

        // --- Stage 3: DialogueVoiceChecker（对话差异化检测）---
        $voiceResult = self::runDialogueStage($novelId, $chapter, $content);
        if ($voiceResult !== null) {
            $result['dialogue_voice'] = $voiceResult;
        }

        // --- 去重 ---
        $result['deduped'] = self::deduplicateDirectives($novelId);

        return $result;
    }

    /**
     * Freeze the only database input used by the Critic model before dispatch.
     * Publication still runs in postWriteAgents, with its original ordering.
     */
    public static function freezeCriticInput(int $novelId, array $chapter, string $content, array $context): ?array
    {
        if (!ConfigCenter::get('agent.enabled', true)
            || !getSystemSetting('ws_critic_enabled', true, 'bool')) {
            return null;
        }
        $chapterNumber = (int)($chapter['chapter_number'] ?? 0);
        $interval = max(1, (int)getSystemSetting('ws_critic_interval', 1, 'int'));
        if ($interval > 1 && $chapterNumber > 0 && $chapterNumber % $interval !== 0) {
            return null;
        }
        if ($novelId <= 0 || $chapterNumber <= 0 || trim($content) === '') {
            throw new InvalidArgumentException('Critic computation requires a novel, chapter and content.');
        }
        $previous = DB::fetch(
            'SELECT content FROM chapters WHERE novel_id=? AND chapter_number=? AND status="completed"',
            [$novelId, $chapterNumber - 1]
        );
        return [
            'novel_id' => $novelId,
            'chapter_number' => $chapterNumber,
            'content' => $content,
            'source_hash' => hash('sha256', $content),
            'prev_content' => (string)($previous['content'] ?? ''),
            'context' => [
                'title' => (string)($context['title'] ?? ''),
                'genre' => (string)($context['genre'] ?? ''),
                'protagonist_name' => (string)($context['protagonist_name'] ?? ''),
                'chapter_title' => (string)($chapter['title'] ?? ''),
                'outline' => (string)($chapter['outline'] ?? ''),
                'model_id' => isset($context['model_id']) ? (int)$context['model_id'] : null,
            ],
        ];
    }

    /** No business-table reads or writes; settings/model routing use the caller's frozen scope. */
    public static function computeCritic(array $input): array
    {
        if (!is_int($input['novel_id'] ?? null) || $input['novel_id'] <= 0
            || !is_int($input['chapter_number'] ?? null) || $input['chapter_number'] <= 0
            || !is_string($input['content'] ?? null) || trim($input['content']) === ''
            || !is_string($input['prev_content'] ?? null)
            || !is_string($input['source_hash'] ?? null)
            || !hash_equals(hash('sha256', $input['content']), $input['source_hash'])
            || !is_array($input['context'] ?? null)) {
            throw new InvalidArgumentException('Invalid frozen Critic input.');
        }
        foreach (['title', 'genre', 'protagonist_name', 'chapter_title', 'outline'] as $key) {
            if (!is_string($input['context'][$key] ?? null)) {
                throw new InvalidArgumentException('Incomplete frozen Critic context.');
            }
        }
        if (!array_key_exists('model_id', $input['context'])
            || ($input['context']['model_id'] !== null && !is_int($input['context']['model_id']))) {
            throw new InvalidArgumentException('Invalid frozen Critic model.');
        }
        require_once __DIR__ . '/CriticAgent.php';
        return (new CriticAgent($input['novel_id']))->reviewCombined(
            $input['content'], $input['prev_content'], $input['context'], true
        );
    }

    /**
     * Stage 1: CriticAgent — 读者评分、相对评分校准、弱维度自适应指令
     *
     * 审计修复 B06（2026-06-16）：从 postWriteAgents 抽取，独立可测。
     *
     * @return array|null CriticAgent 结果（scores/weak_dims 等），未启用或失败时返回 null
     */
    private static function runCriticStage(int $novelId, array $chapter, string $content, array $context, ?array $precomputedCritic = null): ?array
    {
        $criticResult = null;
        // --- CriticAgent ---
        $criticEnabled = (bool)getSystemSetting('ws_critic_enabled', true, 'bool');
        // 成本旋钮 ws_critic_interval：每 N 章评一次（默认 1=每章，行为不变）。
        // critic 实际是 1-2 次 AI 调用（review + 相对评分），长篇设 2-5 可显著降本。
        // 跳过的章节没有 critic_scores——所有消费方（趋势查询/健康监控/人工校准页）
        // 均按 IS NOT NULL 过滤或允许空值，存在空洞不影响功能。
        $criticInterval = max(1, (int)getSystemSetting('ws_critic_interval', 1, 'int'));
        $criticChNum = (int)($chapter['chapter_number'] ?? 0);
        if ($criticEnabled && ($criticInterval <= 1 || $criticChNum <= 0 || $criticChNum % $criticInterval === 0)) {
            try {
                require_once __DIR__ . '/CriticAgent.php';
                $critic = new CriticAgent($novelId);
                $criticContext = [
                    'title'            => $context['title'] ?? '',
                    'genre'            => $context['genre'] ?? '',
                    'protagonist_name' => $context['protagonist_name'] ?? '',
                    'chapter_title'    => $chapter['title'] ?? '',
                    'outline'          => $chapter['outline'] ?? '',
                    'model_id'         => $context['model_id'] ?? null,
                ];

                // P1-4 优化：合并 review + reviewRelative 为单次 AI 调用
                // 先取前一章内容，传入 reviewCombined 一次性产出绝对分+相对分
                $prevChapter = null;
                try {
                    $prevChapter = DB::fetch(
                        'SELECT chapter_number, content, critic_scores, human_critic_scores FROM chapters WHERE novel_id=? AND chapter_number=? AND status="completed"',
                        [$novelId, (int)$chapter['chapter_number'] - 1]
                    );
                } catch (\Throwable $e) { error_log('AgentCoordinator prevChapter fetch failed: ' . $e->getMessage()); }

                $prevContent = $prevChapter['content'] ?? '';
                // P1-2: 先查静态缓存，命中则跳过 AI 调用；后续 DB 保存/校准/指令生成照常进行
                $criticResult = $precomputedCritic
                    ?? CriticAgent::getCachedCombined($content, $prevContent, $criticContext)
                    ?? $critic->reviewCombined($content, $prevContent, $criticContext);
                // 从合并结果中取出相对评分，后续校准逻辑复用
                $relative = $criticResult['relative'] ?? null;

                if (!empty($criticResult['scores'])) {
                    // v1.10.3: 校准 — 相对评分 + 人工偏差修正
                    $calibrated = null;
                    try {
                        // 使用同章模型/人工成对样本；历史人工绝对分不是本章评分。
                        $humanScores = json_decode($chapter['human_critic_scores'] ?? 'null', true);
                        $humanScores = is_array($humanScores) ? $humanScores : null;
                        $referenceModelScores = $criticResult['scores'];
                        $humanReference = $humanScores ? [
                            'chapter_number' => (int)$chapter['chapter_number'],
                            'model_scores' => $referenceModelScores,
                            'human_scores' => $humanScores,
                            'source' => 'current_chapter',
                        ] : null;
                        if (!$humanScores && !empty($prevChapter['human_critic_scores']) && !empty($prevChapter['critic_scores'])) {
                            $previousHuman = json_decode($prevChapter['human_critic_scores'], true);
                            $previousReview = json_decode($prevChapter['critic_scores'], true);
                            $previousModel = $previousReview['scores'] ?? null;
                            if (is_array($previousHuman) && is_array($previousModel)) {
                                $pairedHuman = [];
                                $pairedModel = [];
                                foreach ($criticResult['scores'] as $dim => $_) {
                                    if (isset($previousHuman[$dim], $previousModel[$dim])
                                        && is_numeric($previousHuman[$dim]) && is_numeric($previousModel[$dim])
                                        && $previousHuman[$dim] >= 1 && $previousHuman[$dim] <= 10
                                        && $previousModel[$dim] >= 1 && $previousModel[$dim] <= 10) {
                                        $pairedHuman[$dim] = $previousHuman[$dim];
                                        $pairedModel[$dim] = $previousModel[$dim];
                                    }
                                }
                                if ($pairedHuman !== []) {
                                    $humanScores = $pairedHuman;
                                    $referenceModelScores = $pairedModel;
                                    $humanReference = [
                                        'chapter_number' => (int)($prevChapter['chapter_number'] ?? ((int)$chapter['chapter_number'] - 1)),
                                        'model_scores' => $pairedModel,
                                        'human_scores' => $pairedHuman,
                                        'source' => 'previous_chapter_pair',
                                    ];
                                }
                            }
                        }

                        if ($humanScores || $relative) {
                            $calibrated = $critic->calibrate($criticResult['scores'], $humanScores, $relative, $referenceModelScores);
                            $calibratedAvg = count($calibrated) > 0
                                ? round(array_sum($calibrated) / count($calibrated), 1) : 0;

                            DB::update('chapters', [
                                'calibrated_critic_scores' => json_encode([
                                    'scores'   => $calibrated,
                                    'avg'      => $calibratedAvg,
                                    'human'    => ($humanReference['source'] ?? '') === 'current_chapter' ? $humanScores : null,
                                    'human_reference' => $humanReference,
                                    'relative' => $relative,
                                    'complete' => $criticResult['complete'] ?? false,
                                ], JSON_UNESCAPED_UNICODE),
                            ], 'id=?', [(int)$chapter['id']]);
                        } else {
                            DB::update('chapters', ['calibrated_critic_scores' => null], 'id=?', [(int)$chapter['id']]);
                        }
                    } catch (\Throwable $e) {
                        if ($precomputedCritic !== null) throw $e;
                        error_log('CriticAgent calibration failed: ' . $e->getMessage());
                    }

                    DB::update('chapters', [
                        'critic_scores' => json_encode($criticResult, JSON_UNESCAPED_UNICODE),
                    ], 'id=?', [(int)$chapter['id']]);
                    $displayAvg = $calibrated
                        ? round(array_sum($calibrated) / count($calibrated), 1)
                        : $criticResult['avg'];
                    addLog($novelId, 'info', sprintf(
                        '读者评分：%.1f/10（爽感%s/代入%s/节奏%s/新鲜%s/追读%s）%s',
                        $displayAvg,
                        $calibrated['thrill'] ?? $criticResult['scores']['thrill'] ?? '未评分',
                        $calibrated['immersion'] ?? $criticResult['scores']['immersion'] ?? '未评分',
                        $calibrated['pacing'] ?? $criticResult['scores']['pacing'] ?? '未评分',
                        $calibrated['freshness'] ?? $criticResult['scores']['freshness'] ?? '未评分',
                        $calibrated['read_next'] ?? $criticResult['scores']['read_next'] ?? '未评分',
                        ($calibrated ? '（已校准）' : '') . (empty($criticResult['complete']) ? '（部分维度缺测）' : '')
                    ));
                } else {
                    // Do not retain an old successful score after the new evaluation failed.
                    DB::update('chapters', ['critic_scores' => null, 'calibrated_critic_scores' => null], 'id=?', [(int)$chapter['id']]);
                    addLog($novelId, 'warn', '读者评分不可用：未获得有效维度，未生成质量整改指令。');
                }

                if (!empty($criticResult['weak_dims'])) {
                    $existingWeak = self::getRecentWeakDimDirectives($novelId, (int)$chapter['chapter_number']);
                    // 审计优化（2026-07-20）：多弱维合并为 1 条 quality 指令，避免生产端堆 5 条、
                    // 消费端只截 3 条导致「系统以为生效、模型看不到」。
                    $parts = [];
                    $maxConsec = 1;
                    $applyFrom = (int)$chapter['chapter_number'] + 1;
                    // 审计修复（2026-08-09 R11-3）：下一章若为终章，read_next 改收束而非悬念
                    $isFinalNext = false;
                    try {
                        $tc = (int)(DB::fetch(
                            'SELECT target_chapters FROM novels WHERE id=?',
                            [$novelId]
                        )['target_chapters'] ?? 0);
                        $isFinalNext = $tc > 0 && $applyFrom >= $tc;
                    } catch (\Throwable) { /* ignore */ }
                    foreach ($criticResult['weak_dims'] as $wd) {
                        $dimLabel = (string)($wd['label'] ?? $wd['dim'] ?? '');
                        if ($dimLabel === '') continue;
                        $consecutive = (int)(($existingWeak[$dimLabel]['consecutive'] ?? 0) + 1);
                        $maxConsec = max($maxConsec, $consecutive);
                        $parts[] = self::buildAdaptiveCriticDirective($wd, $consecutive, $isFinalNext);
                    }
                    if ($parts) {
                        $merged = count($parts) === 1
                            ? $parts[0]
                            : ('前章读者视角多项偏弱，本章优先改善：' . "\n- " . implode("\n- ", $parts));
                        // 同章 quality 已有 ≥2 条时不再追加，防指令洪水
                        AgentDirectives::addCapped(
                            $novelId, $applyFrom, 'quality',
                            mb_substr($merged, 0, 400),
                            max(2, min(4, $maxConsec + 1)), 24, 2
                        );
                    }
                }
            } catch (\Throwable $e) {
                if ($precomputedCritic !== null || $e instanceof AIRequestCanceledException
                    || $e instanceof WriteEnginePostprocessLeaseLostException) {
                    throw $e;
                }
                addLog($novelId, 'warn', 'CriticAgent跳过：' . $e->getMessage());
            }
        }

        return $criticResult ?? null;
    }

    /**
     * Stage 2: StyleGuard — AI痕迹检测、风格漂移、五感平衡
     *
     * 审计修复 B06（2026-06-16）：从 postWriteAgents 抽取，独立可测。
     *
     * @return array|null StyleGuard 结果（含 style_guard/sensory_balance），未启用或失败时返回 null
     */
    private static function runStyleGuardStage(int $novelId, array $chapter, string $content): ?array
    {
        $stageResult = null;
        // --- StyleGuard ---
        $styleGuardEnabled = (bool)getSystemSetting('ws_style_guard_enabled', true, 'bool');
        if ($styleGuardEnabled) {
            try {
                require_once __DIR__ . '/StyleGuard.php';
                $guard = new StyleGuard($novelId);

                $aiCheckEnabled = (bool)getSystemSetting('ws_ai_patterns_check_enabled', true, 'bool');
                if ($aiCheckEnabled) {
                    $patternResult = $guard->detectAIPatterns($content);
                    $stageResult['style_guard'] = $patternResult;
                    if ($patternResult['total_issues'] > 0) {
                        DB::update('chapters', [
                            'ai_pattern_issues' => json_encode($patternResult['issues'], JSON_UNESCAPED_UNICODE),
                        ], 'id=?', [(int)$chapter['id']]);
                        addLog($novelId, 'info', sprintf(
                            'AI痕迹检测：%d项问题 —— %s',
                            $patternResult['total_issues'],
                            implode('；', array_slice($patternResult['issues'], 0, 2))
                        ));
                        if ($patternResult['total_issues'] >= 2) {
                            $sugText = implode('；', array_slice($patternResult['suggestions'], 0, 2));
                            AgentDirectives::addCapped(
                                $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                                "前章AI痕迹偏重：{$sugText}。本章请用更自然的人类写作风格。",
                                1, 24, 2
                            );
                        }
                    }
                }

                $chNum = (int)($chapter['chapter_number'] ?? 0);
                $drift = $guard->checkStyleDrift($chNum);
                if ($drift) {
                    DB::update('chapters', [
                        'style_drift_report' => json_encode($drift, JSON_UNESCAPED_UNICODE),
                    ], 'id=?', [(int)$chapter['id']]);
                    addLog($novelId, 'warn', sprintf(
                        '风格漂移检测：%d项维度偏移（严重度：%s）',
                        count($drift['drifts']),
                        $drift['severity']
                    ));
                    $driftLines = [];
                    foreach ($drift['drifts'] as $dim => $info) {
                        $driftLines[] = "{$dim} {$info['direction']}{$info['change_pct']}%（基线{$info['baseline']}→近期{$info['recent']}）";
                    }
                    $driftText = implode('；', $driftLines);
                    AgentDirectives::addCapped(
                        $novelId, $chNum + 1, 'quality',
                        "检测到风格漂移：{$driftText}。本章请回调至开篇风格基准，保持全书风格一致。",
                        3, 48, 2
                    );
                }

                $sensoryResult = $guard->checkSensoryBalance($content);
                $stageResult['sensory_balance'] = $sensoryResult;
                if (!$sensoryResult['balanced'] && $sensoryResult['issue']) {
                    $issue = $sensoryResult['issue'];
                    addLog($novelId, 'info', sprintf(
                        '五感平衡检测：%s',
                        $issue['message']
                    ));
                    if ($issue['severity'] !== 'low' || ($sensoryResult['counts']['visual'] ?? 0) > 15) {
                        AgentDirectives::addCapped(
                            $novelId, $chNum + 1, 'quality',
                            "五感平衡问题：{$issue['message']}。{$issue['suggestion']}",
                            1, 24, 2
                        );
                    }
                }
            } catch (\Throwable $e) {
                addLog($novelId, 'warn', 'StyleGuard跳过：' . $e->getMessage());
            }
        }

        return $stageResult;
    }

    /**
     * Stage 3: DialogueVoiceChecker — 对话差异化检测
     *
     * 审计修复 B06（2026-06-16）：从 postWriteAgents 抽取，独立可测。
     *
     * @return array|null 对话差异化检测结果，未启用或失败时返回 null
     */
    private static function runDialogueStage(int $novelId, array $chapter, string $content): ?array
    {
        // --- 对话差异化检测 (v1.10.3) ---
        try {
            require_once __DIR__ . '/DialogueVoiceChecker.php';
            require_once __DIR__ . '/../memory/CharacterCardRepo.php';
            $voiceRepo = new \CharacterCardRepo($novelId);
            $voiceMap = $voiceRepo->listWithVoiceProfile();
            if (!empty($voiceMap)) {
                $voiceChecker = new DialogueVoiceChecker($novelId);
                $voiceResult = $voiceChecker->check($content, $voiceMap);
                if (!empty($voiceResult['issues'])) {
                    $highIssues = array_filter($voiceResult['issues'], fn($i) => ($i['severity'] ?? '') === 'high');
                    $mediumIssues = array_filter($voiceResult['issues'], fn($i) => ($i['severity'] ?? '') === 'medium');
                    if ($highIssues) {
                        $msg = implode('；', array_map(fn($i) => $i['message'], array_slice($highIssues, 0, 2)));
                        AgentDirectives::addCapped(
                            $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                            "对话差异化问题（严重）：{$msg}。请严格按角色语音规则修正。",
                            1, 24, 2
                        );
                    } elseif ($mediumIssues) {
                        $msg = implode('；', array_map(fn($i) => $i['suggestion'] ?? $i['message'], array_slice($mediumIssues, 0, 2)));
                        AgentDirectives::addCapped(
                            $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                            "对话差异化提醒：{$msg}",
                            1, 24, 2
                        );
                    }
                    addLog($novelId, 'info', sprintf(
                        '对话差异化检测：%d项问题',
                        count($voiceResult['issues'])
                    ));
                }
                return $voiceResult;
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '对话差异化检测跳过：' . $e->getMessage());
        }

        return null;
    }

    /**
     * 去重：同类型、同起始章节的指令最多保留 maxActive 条（与 addCapped 默认值对齐）。
     *
     * P2-3 修复（2026-08-09）：原实现按 (type, apply_from) 分组只保留 1 条最新指令，
     * 架空了 addCapped(maxActive=2) 设计——生产端允许写 2 条，去重端却把第二条静默停用，
     * 导致“系统以为已写入第二条、消费端永远看不到”的语义不一致。
     *
     * M-2 修复（2026-07-25）：原按 mb_strlen 保留"最详细"的一条，但冗长的低优先级指令
     * 可能覆盖简洁的高优先级指令。改为保留最新的一条（ORDER BY created_at DESC 已保证
     * 首条为最新），长度仅作为同时间戳的 tiebreaker。
     *
     * 现 P2-3 进一步保留前 maxActive 条最新指令，仅停用超出部分。maxActive 与
     * AgentDirectives::addCapped 的默认值保持一致（=2）。
     */
    public static function deduplicateDirectives(int $novelId): int
    {
        try {
            $now = date('Y-m-d H:i:s');
            $all = DB::fetchAll(
                'SELECT id, type, directive, apply_from, apply_to, created_at
                 FROM agent_directives
                 WHERE novel_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > ?)
                 ORDER BY type, apply_from, created_at DESC',
                [$novelId, $now]
            );
            if (!$all || count($all) < 2) return 0;

            // P2-3：与 AgentDirectives::addCapped 默认 maxActive 对齐。
            // 同一 (type, apply_from) 组内最多保留 maxActive 条最新指令。
            $maxActive = 2;
            $keepCount = [];
            $toDeactivate = [];
            foreach ($all as $d) {
                $key = $d['type'] . '|' . $d['apply_from'];
                if (!isset($keepCount[$key])) {
                    $keepCount[$key] = 0;
                }
                $keepCount[$key]++;
                if ($keepCount[$key] > $maxActive) {
                    // 已保留 maxActive 条最新，超出部分停用（首条为最新因 ORDER BY created_at DESC）
                    $toDeactivate[] = (int)$d['id'];
                }
            }

            foreach ($toDeactivate as $id) {
                AgentDirectives::deactivate($id);
            }
            return count($toDeactivate);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 查询近几章的 CriticAgent 弱项指令，判断是否重复
     */
    private static function getRecentWeakDimDirectives(int $novelId, int $currentChapter): array
    {
        try {
            $recent = DB::fetchAll(
                'SELECT directive FROM agent_directives
                 WHERE novel_id = ? AND type = "quality" AND is_active = 1
                   AND apply_from >= ? AND apply_from <= ? AND directive LIKE ?',
                [$novelId, max(1, $currentChapter - 5), $currentChapter, '%读者视角%']
            );
            $dims = [];
            foreach ($recent as $r) {
                if (preg_match('/「(.+?)」/', $r['directive'], $m)) {
                    $dimLabel = $m[1];
                    $score = 0;
                    if (preg_match('/（(\d+)\/10）/', $r['directive'], $sm)) {
                        $score = (int)$sm[1];
                    }
                    $dims[$dimLabel] = [
                        'score' => $score,
                        'consecutive' => ($dims[$dimLabel]['consecutive'] ?? 0) + 1,
                    ];
                }
            }
            return $dims;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 根据连续弱项次数生成自适应指令
     */
    private static function buildAdaptiveCriticDirective(array $weakDim, int $consecutive, bool $isFinalChapter = false): string
    {
        $label = $weakDim['label'] ?? $weakDim['dim'];
        $score = $weakDim['score'] ?? 0;
        $reason = $weakDim['reason'] ?? '';

        $escalation = match(true) {
            $consecutive >= 3 => '（连续3+章未改善，本章为最高优先级）',
            $consecutive >= 2 => '（连续2章未改善）',
            default => '',
        };

        $dimAdvice = match($weakDim['dim'] ?? '') {
            'thrill' => $isFinalChapter
                ? '终章高潮须兑现主线冲突并给出情感落点，禁止再开新线高潮。'
                : '确保至少有一个"读者看到这里会叫好"的高潮时刻，冲突必须以出人意料的方式解决。',
            'immersion' => '增加主角的感官描写（看到/听到/感觉到），减少旁白式叙述，让读者跟着主角的视角走。',
            'pacing' => '检查是否有连续超过300字无对话/无事件的段落，如有必须打断。节奏要像心跳一样有快有慢。',
            'freshness' => '至少引入一个读者意料之外的元素：角色的隐藏面、反常识的信息、反套路的剧情走向。',
            'read_next' => $isFinalChapter
                ? '最终章须圆满收束与主题回响，不设新悬念钩子；用余韵/代价/新日常收尾。'
                : '章末必须设置"无法放下的悬念"：要么是即将到来的巨大危机，要么是颠覆认知的反转。',
            default => "重点改善「{$label}」维度。",
        };

        return "前章读者视角「{$label}」偏低（{$score}/10）{$escalation}原因：{$reason}。{$dimAdvice}";
    }

    /**
     * README / 配置中的开篇周期：质量 5、策略 10、优化 20。
     */
    private function baseAgentIntervals(): array
    {
        $strategyInterval = (int)ConfigCenter::get('agent.strategy_agent.decision_interval', 0);
        if ($strategyInterval <= 0) {
            $strategyInterval = (int)ConfigCenter::get('agent.strategy_agent.check_interval', 10);
        }
        return [
            'strategy' => max(1, $strategyInterval),
            'quality' => max(1, (int)ConfigCenter::get('agent.quality_agent.check_interval', 5)),
            'optimization' => max(1, (int)ConfigCenter::get('agent.optimization_agent.optimization_interval', 20)),
        ];
    }

    /**
     * 从开篇计：首次在 base 章触发，之后按动态间隔重复。
     */
    private function shouldRunOnOpeningCadence(int $chNum, int $baseInterval, int $dynamicInterval): bool
    {
        if ($chNum < $baseInterval || $baseInterval < 1) {
            return false;
        }
        if ($chNum === $baseInterval) {
            return true;
        }
        return (($chNum - $baseInterval) % max(1, $dynamicInterval)) === 0;
    }

    private function calculateDynamicIntervals(array $context): array
    {
        $progress = $context['current_progress'] ?? 0;
        $recentChapters = $context['recent_chapters'] ?? [];

        // L-2 修复（2026-07-20）：当 progress 未传入（值为 0/null）时，
        // 不应命中 < 0.1 分支导致 2x 乘数，因为无法判断是"真的刚开始"
        // 还是"调用方漏传"。仅在明确大于 0 时应用阶段乘数。
        $stageMultiplier = 1.0;
        if ($progress > 0) {
            $stageMultiplier = match(true) {
                $progress < 0.1 => 2.0,
                $progress < 0.2 => 1.5,
                $progress > 0.85 => 0.5,
                $progress > 0.7 => 0.75,
                default => 1.0,
            };
        }

        $writingSpeed = $this->estimateWritingSpeed($recentChapters);
        $speedAdjustment = match(true) {
            $writingSpeed > 10 => 0.5,
            $writingSpeed > 5 => 0.75,
            $writingSpeed < 1 => 1.5,
            $writingSpeed < 2 => 1.25,
            default => 1.0,
        };

        // 审计修复（2026-08-09 R6）：原为硬编码 10/5/20，后台的间隔配置是假旋钮。
        // 改为读取 ConfigCenter（与 install.php 默认值一致），配置改动即刻生效。
        // 审计修复（2026-08-09 R7-3）：策略键统一为 decision_interval；兼容旧库仅有 check_interval。
        $baseIntervals = $this->baseAgentIntervals();

        $finalIntervals = [];
        foreach ($baseIntervals as $type => $base) {
            // 审计修复（2026-08-17 写作逻辑 F）：进度 <10% 时质量间隔乘数不超过 1.0
            // （开篇更需要盯质量，间隔加倍会直接跳到第 15 章）；策略/优化仍可放宽。
            $typeStageMultiplier = $stageMultiplier;
            if ($type === 'quality' && $progress > 0 && $progress < 0.1 && $typeStageMultiplier > 1.0) {
                $typeStageMultiplier = 1.0;
            }
            $adjusted = (int)round($base * $typeStageMultiplier * $speedAdjustment);
            $finalIntervals[$type] = max(1, min((int)round($base * 2), $adjusted));
        }

        return $finalIntervals;
    }

    /**
     * 检测情节模式重复
     *
     * @param int $currentChapter 当前章节号
     * @return array|null 检测结果
     */
    private function detectPlotPattern(int $currentChapter): ?array
    {
        try {
            require_once __DIR__ . '/PlotPatternDetector.php';
            require_once __DIR__ . '/AgentDirectives.php';

            $detector = new PlotPatternDetector($this->novelId);
            $result = $detector->detect(max(0, $currentChapter - 1));

            if ($result && in_array($result['severity'], ['high', 'medium'])) {
                // 写入 Agent 指令
                $directiveType = match ($result['type']) {
                    'pattern_repetition' => 'strategy',
                    'consecutive_repetition' => 'strategy',
                    'element_overuse' => 'quality',
                    default => 'strategy',
                };

                // 审计修复（2026-08-09 R4）：前写阶段 $currentChapter 即"正在写的章"，
                // 原 +1 偏一章导致本章 prompt 看不到刚检出的套路警告；改 addCapped 与消费端限流对齐。
                AgentDirectives::addCapped(
                    $this->novelId,
                    $currentChapter,
                    $directiveType,
                    $result['directive'],
                    applyRange: 5,
                    expiresInHours: 48,
                    maxActive: 2,
                    source: 'plot_pattern_detector'
                );

                // 记录日志
                DB::insert('agent_action_logs', [
                    'novel_id' => $this->novelId,
                    'agent_type' => 'plot_pattern',
                    'action' => 'pattern_warning',
                    'status' => 'success',
                    'params' => json_encode([
                        'severity' => $result['severity'],
                        'type' => $result['type'],
                        'message' => $result['message'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                return $result;
            }

            return null;

        } catch (\Throwable $e) {
            error_log('AgentCoordinator::detectPlotPattern 失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * v1.11.8: 前5章特殊保护
     *
     * 前5章是决定弃书率的关键时期，需要额外的 Agent 保护：
     * - 每章都跑 CriticAgent 评分（即使没历史对比）
     * - 添加开篇质量专项检查
     * - 第3章触发认知负荷预警（防信息过载）
     * - 第5章后立即检测情节模式
     *
     * @param int $chNum 当前章节号（1-5）
     * @param array $context 上下文
     * @return array 保护措施结果
     */
    private function runEarlyChapterProtection(int $chNum, array $context): array
    {
        $result = [
            'chapter' => $chNum,
            'actions' => [],
        ];

        try {
            require_once __DIR__ . '/AgentDirectives.php';

            // 第1章：提醒黄金开篇要求
            if ($chNum === 1) {
                AgentDirectives::add(
                    $this->novelId,
                    1,
                    'quality',
                    '【开篇章专项】第1章必须：1.黄金三行内出现冲突/悬念/反差 2.主角名在首段出现 3.核心矛盾或钩子在首章结尾明确 4.禁止大量背景设定堆砌',
                    applyRange: 1,
                    expiresInHours: 24,
                    source: 'early_chapter_protection'
                );
                $result['actions'][] = 'opening_quality_reminder';
            }

            // 第2-3章：检查是否有足够钩子
            if ($chNum === 2 || $chNum === 3) {
                // 审计修复（2026-08-09 R10-3）：schema 无 actual_hook_type，读死列会导致整段保护失败
                $prevChapter = DB::fetch(
                    'SELECT hook_type FROM chapters WHERE novel_id=? AND chapter_number=?',
                    [$this->novelId, $chNum - 1]
                );
                if (empty($prevChapter['hook_type'])) {
                    AgentDirectives::add(
                        $this->novelId,
                        $chNum,
                        'quality',
                        '【开篇保护】前章未检测到有效钩子类型。本章必须设置明确的章末钩子（危机中断/信息爆炸/情节反转），让读者产生强烈追读欲望。',
                        applyRange: 1,
                        expiresInHours: 24,
                        source: 'early_chapter_protection'
                    );
                    $result['actions'][] = 'hook_reminder';
                }
            }

            // 第4章：检查开篇节奏
            if ($chNum === 4) {
                // 检查前3章的质量分趋势
                $recentScores = DB::fetchAll(
                    'SELECT chapter_number, quality_score FROM chapters
                     WHERE novel_id=? AND chapter_number <= 3 AND status="completed" AND quality_score IS NOT NULL
                     ORDER BY chapter_number',
                    [$this->novelId]
                );
                if (count($recentScores) >= 2) {
                    $scores = array_column($recentScores, 'quality_score');
                    $trend = $scores[count($scores) - 1] - $scores[0];
                    if ($trend < -5) {
                        AgentDirectives::add(
                            $this->novelId,
                            $chNum,
                            'quality',
                            '【开篇质量预警】前3章质量分呈下降趋势。请检查：1.节奏是否过慢 2.冲突是否足够 3.钩子是否有效。本章必须扭转趋势。',
                            applyRange: 2,
                            expiresInHours: 48,
                            source: 'early_chapter_protection'
                        );
                        $result['actions'][] = 'quality_trend_warning';
                    }
                }
            }

            // 第5章：开篇期总结 + 准备进入正文期
            if ($chNum === 5) {
                AgentDirectives::add(
                    $this->novelId,
                    $chNum,
                    'strategy',
                    '【开篇期收尾】第5章是开篇期结束标志。请确保：1.主角核心目标已明确 2.主要配角已登场 3.世界观核心规则已展示 4.至少埋下2个长线伏笔。正文期将进入情节推进阶段。',
                    applyRange: 1,
                    expiresInHours: 24,
                    source: 'early_chapter_protection'
                );
                $result['actions'][] = 'opening_phase_summary';
            }

        } catch (\Throwable $e) {
            error_log('AgentCoordinator::runEarlyChapterProtection 失败: ' . $e->getMessage());
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 检测认知负荷（信息密度）
     *
     * @param int $currentChapter 当前章节号
     * @return array|null 检测结果
     */
    private function checkCognitiveLoad(int $currentChapter): ?array
    {
        try {
            require_once __DIR__ . '/../CognitiveLoadMonitor.php';
            require_once __DIR__ . '/AgentDirectives.php';

            $monitor = new CognitiveLoadMonitor($this->novelId);
            $history = $monitor->getTrend(max(0, $currentChapter - 1), 10);
            $recent = array_slice($history, -5);
            $previous = array_slice($history, -10, 5);
            $recentSum = array_sum(array_column($recent, 'total_new'));
            $recentAvg = $recentSum / max(1, count($recent));
            $previousAvg = array_sum(array_column($previous, 'total_new')) / max(1, count($previous));
            $status = [
                'recent_5_new_elements' => $recentSum,
                'trend' => count($history) >= 5 && $recentAvg > $previousAvg * 1.3 ? 'increasing' : 'stable',
                'recommendation' => '',
            ];

            // 只有在超标时才生成指令
            if ($status['recent_5_new_elements'] <= 12 && $status['trend'] !== 'increasing') {
                return null;
            }

            // 生成认知负荷警告指令
            $directive = "【认知负荷预警】近5章已引入 {$status['recent_5_new_elements']} 个新元素";
            if ($status['trend'] === 'increasing') {
                $directive .= "，且密度呈上升趋势";
            }
            $directive .= "。\n本章请：\n";
            $directive .= "1. 不再引入新角色/新地点/新概念\n";
            $directive .= "2. 让已有角色之间发生互动\n";
            $directive .= "3. 深化已有设定而非新增设定\n";
            $directive .= "避免读者产生「人名地名太多记不住」的疲劳感。";

            // 审计修复（2026-08-09 R4）：前写阶段 $currentChapter 即"正在写的章"，
            // 原 +1 偏一章导致指令晚一章生效（directive 文案是"本章请…"）；改 addCapped 限流。
            $targetChapter = $currentChapter;
            $existing = DB::fetch(
                "SELECT id FROM agent_directives 
                 WHERE novel_id=? AND type='strategy' AND is_active=1
                   AND apply_from <= ? AND apply_to >= ?
                   AND directive LIKE '%认知负荷%'
                 LIMIT 1",
                [$this->novelId, $targetChapter, $targetChapter]
            );
            if ($existing) return null;

            AgentDirectives::addCapped(
                $this->novelId,
                $targetChapter,
                'strategy',
                $directive,
                applyRange: 2,
                expiresInHours: 48,
                maxActive: 2,
                source: 'cognitive_load_monitor'
            );

            // 记录日志
            DB::insert('agent_action_logs', [
                'novel_id' => $this->novelId,
                'agent_type' => 'cognitive_load',
                'action' => 'density_warning',
                'status' => 'success',
                'params' => json_encode([
                    'recent_5_new_elements' => $status['recent_5_new_elements'],
                    'trend' => $status['trend'],
                    'recommendation' => $status['recommendation'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'severity' => $status['recent_5_new_elements'] > 15 ? 'high' : 'medium',
                'status' => $status,
            ];

        } catch (\Throwable $e) {
            error_log('AgentCoordinator::checkCognitiveLoad 失败: ' . $e->getMessage());
            return null;
        }
    }

    private function estimateWritingSpeed(array $recentChapters): float
    {
        if (count($recentChapters) < 2) return 2.0;

        $speeds = [];
        for ($i = 0; $i < min(count($recentChapters), 5) - 1; $i++) {
            $current = strtotime($recentChapters[$i]['updated_at'] ?? time());
            $prev = strtotime($recentChapters[$i + 1]['updated_at'] ?? time());
            $hours = ($current - $prev) / 3600;
            if ($hours > 0) {
                $speeds[] = 1 / $hours;
            }
        }

        return count($speeds) > 0 ? array_sum($speeds) / count($speeds) : 2.0;
    }
}
