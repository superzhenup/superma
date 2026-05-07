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
    
    /** @var array 本周期已写入的指令类型摘要，用于防冲突 */
    private array $writtenDirectives = [];
    
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
            'predictions' => [],      // 新增：预测结果
            'preventive_actions' => [], // 新增：预防性指令
            'final_actions' => [],
            'execution_summary' => [],
        ];

        try {
            $dynamicIntervals = $this->calculateDynamicIntervals($context);

            // ===== 新增：趋势预测 =====
            if ($chNum > 5) {
                $results['predictions'] = $this->runPredictions($context);
                $results['preventive_actions'] = $this->handlePredictions($results['predictions']);
            }

            // 前5章只收集数据，第6章开始决策（确保有足够历史数据）
            if (ConfigCenter::get('agent.strategy_agent.enabled', true) &&
                $chNum > 5 && $chNum % $dynamicIntervals['strategy'] === 0) {
                // 注入预测结果到上下文
                $contextWithPredictions = array_merge($context, [
                    'predictions' => $results['predictions'],
                ]);
                $results['strategy'] = $this->strategyAgent->decide($contextWithPredictions);
            }

            if (ConfigCenter::get('agent.quality_agent.enabled', true) &&
                $chNum > 5 && $chNum % $dynamicIntervals['quality'] === 0) {
                $results['quality'] = $this->qualityAgent->decide($context);
            }

            if (ConfigCenter::get('agent.optimization_agent.enabled', true) &&
                $chNum > 5 && $chNum % $dynamicIntervals['optimization'] === 0) {
                $optimizationContext = array_merge($context, [
                    'strategy_decision' => $results['strategy'],
                    'quality_decision' => $results['quality'],
                ]);
                $results['optimization'] = $this->optimizationAgent->decide($optimizationContext);
            }

            // ===== v1.11.8: 前5章特殊保护 =====
            // 前5章是决定弃书率的关键，需要额外的 Agent 保护
            if ($chNum >= 1 && $chNum <= 5) {
                $results['early_chapter_protection'] = $this->runEarlyChapterProtection($chNum, $context);
            }

            // ===== 新增：情节模式重复检测 =====
            // 每 5 章触发，第 5 章后立即触发一次（前5章刚写完）
            if ($chNum >= 5 && ($chNum === 5 || ($chNum >= 10 && $chNum % 5 === 0))) {
                $results['plot_pattern'] = $this->detectPlotPattern($chNum);
            }

            // ===== 新增：认知负荷检测 =====
            // 每 3 章触发，第 3 章提前触发一次（防开篇信息过载）
            if ($chNum >= 3 && ($chNum === 3 || ($chNum >= 5 && $chNum % 3 === 0))) {
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
            $predictor = new TrendPredictor($this->novelId);
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
            $nextChapter = $this->getNextChapterNumber();

            $directive = sprintf(
                '【趋势预警】质量分连续下降，当前%.1f分，目标80分。' .
                '建议：1.增加冲突密度 2.缩短铺垫段 3.确保每章有情绪爆发点。',
                $qualityTrend['current'] ?? 70
            );

            AgentDirectives::add($this->novelId, $nextChapter, 'quality', $directive, 2, 48);
            $actions[] = ['type' => 'quality_prevention', 'status' => 'written'];
        }

        // 处理伏笔风险
        $foreshadowing = $predictions['foreshadowing'] ?? [];
        if (($foreshadowing['status'] ?? '') === 'critical') {
            require_once __DIR__ . '/AgentDirectives.php';
            $nextChapter = $this->getNextChapterNumber();

            $overdue = $foreshadowing['overdue'] ?? 0;
            $directive = sprintf(
                '【伏笔预警】%d个伏笔已超期未回收。' .
                '建议：本章或下章必须安排至少1个伏笔回收，优先回收critical级别的伏笔。',
                $overdue
            );

            AgentDirectives::add($this->novelId, $nextChapter, 'strategy', $directive, 3, 72);
            $actions[] = ['type' => 'foreshadowing_prevention', 'status' => 'written'];
        }

        // 处理爽点密度不足
        $coolpoint = $predictions['coolpoint'] ?? [];
        if (($coolpoint['status'] ?? '') === 'below_target') {
            $gap = $coolpoint['gap'] ?? 0;
            if ($gap > 0.1) {
                require_once __DIR__ . '/AgentDirectives.php';
                $nextChapter = $this->getNextChapterNumber();

                $directive = sprintf(
                    '【爽点预警】爽点密度%.0f%%，低于目标%.0f%%。' .
                    '建议：本章必须安排爽点，优先类型：%s。',
                    $coolpoint['current_density'] * 100,
                    $coolpoint['target_density'] * 100,
                    $this->getRecommendedCoolPointType($predictions)
                );

                AgentDirectives::add($this->novelId, $nextChapter, 'strategy', $directive, 2, 48);
                $actions[] = ['type' => 'coolpoint_prevention', 'status' => 'written'];
            }
        }

        return $actions;
    }

    /**
     * 获取下一章节号
     */
    private function getNextChapterNumber(): int
    {
        try {
            $result = DB::fetch(
                'SELECT COALESCE(MAX(chapter_number), 0) + 1 as next_ch FROM chapters WHERE novel_id = ?',
                [$this->novelId]
            );
            return (int)($result['next_ch'] ?? 1);
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
            $types = [
                '打脸' => 0, '逆袭' => 0, '获宝' => 0, '扮猪吃虎' => 0, '智斗' => 0,
            ];
            foreach ($distribution as $row) {
                $type = $row['cool_point_type'] ?? '';
                if (isset($types[$type])) {
                    $types[$type] = (int)$row['count'];
                }
            }
            asort($types);
            return array_key_first($types) ?: '打脸';
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
            $this->writtenDirectives[$source] = true;

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
                if (!empty($value)) {
                    $directives[] = [
                        'source' => 'strategy',
                        'type' => 'strategy_' . $key,
                        'priority' => $this->getStrategyPriority($key),
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
            $resolved[$source][] = $directive['data'];
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
     * @return array{critic: array|null, style_guard: array|null, deduped: int}
     */
    public static function postWriteAgents(int $novelId, array $chapter, string $content, array $context): array
    {
        $result = ['critic' => null, 'style_guard' => null, 'deduped' => 0];

        // 检查 Agent 总开关
        if (!ConfigCenter::get('agent.enabled', true)) {
            return $result;
        }

        // --- CriticAgent ---
        $criticEnabled = (bool)getSystemSetting('ws_critic_enabled', true, 'bool');
        if ($criticEnabled) {
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
                $criticResult = $critic->review($content, $criticContext);
                $result['critic'] = $criticResult;

                if (!empty($criticResult['scores'])) {
                    // v1.10.3: 校准 — 相对评分 + 人工偏差修正
                    $calibrated = null;
                    try {
                        // 获取前一章内容用于相对评分
                        $prevChapter = DB::fetch(
                            'SELECT content, human_critic_scores FROM chapters WHERE novel_id=? AND chapter_number=? AND status="completed"',
                            [$novelId, (int)$chapter['chapter_number'] - 1]
                        );
                        $prevContent = $prevChapter['content'] ?? '';

                        // 相对评分
                        $relative = $critic->reviewRelative($content, $prevContent, $criticContext);

                        // 人工评分（当前章节如有）
                        $humanScores = json_decode($chapter['human_critic_scores'] ?? 'null', true);
                        // 如果当前章没有，尝试取前一章的人工评分作为偏差参考
                        if (!$humanScores && !empty($prevChapter['human_critic_scores'])) {
                            $humanScores = json_decode($prevChapter['human_critic_scores'], true);
                        }

                        if ($humanScores || $relative) {
                            $calibrated = $critic->calibrate($criticResult['scores'], $humanScores, $relative);
                            $calibratedAvg = count($calibrated) > 0
                                ? round(array_sum($calibrated) / count($calibrated), 1) : 0;

                            DB::update('chapters', [
                                'calibrated_critic_scores' => json_encode([
                                    'scores'   => $calibrated,
                                    'avg'      => $calibratedAvg,
                                    'human'    => $humanScores,
                                    'relative' => $relative,
                                ], JSON_UNESCAPED_UNICODE),
                            ], 'id=?', [(int)$chapter['id']]);
                        }
                    } catch (\Throwable $e) {
                        error_log('CriticAgent calibration failed: ' . $e->getMessage());
                    }

                    DB::update('chapters', [
                        'critic_scores' => json_encode($criticResult, JSON_UNESCAPED_UNICODE),
                    ], 'id=?', [(int)$chapter['id']]);
                    $displayAvg = $calibrated
                        ? round(array_sum($calibrated) / count($calibrated), 1)
                        : $criticResult['avg'];
                    addLog($novelId, 'info', sprintf(
                        '读者评分：%.1f/10（爽感%d/代入%d/节奏%d/新鲜%d/追读%d）%s',
                        $displayAvg,
                        $calibrated['thrill'] ?? $criticResult['scores']['thrill'] ?? 0,
                        $calibrated['immersion'] ?? $criticResult['scores']['immersion'] ?? 0,
                        $calibrated['pacing'] ?? $criticResult['scores']['pacing'] ?? 0,
                        $calibrated['freshness'] ?? $criticResult['scores']['freshness'] ?? 0,
                        $calibrated['read_next'] ?? $criticResult['scores']['read_next'] ?? 0,
                        $calibrated ? '（已校准）' : ''
                    ));
                }

                if (!empty($criticResult['weak_dims'])) {
                    $existingWeak = self::getRecentWeakDimDirectives($novelId, (int)$chapter['chapter_number']);
                    foreach ($criticResult['weak_dims'] as $wd) {
                        $dimKey = $wd['dim'] ?? '';
                        if (isset($existingWeak[$dimKey]) && $existingWeak[$dimKey]['score'] >= $wd['score']) {
                            $boost = min(3, $existingWeak[$dimKey]['consecutive'] + 1);
                            $directive = self::buildAdaptiveCriticDirective($wd, $existingWeak[$dimKey]['consecutive']);
                            AgentDirectives::add(
                                $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                                $directive, 2, 24
                            );
                        } elseif (empty($existingWeak[$dimKey])) {
                            $dimLabel = $wd['label'] ?? $wd['dim'];
                            $dimScore = $wd['score'] ?? 0;
                            AgentDirectives::add(
                                $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                                "前章读者视角「{$dimLabel}」偏低（{$dimScore}/10），本章请重点改善此维度。",
                                2, 24
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                addLog($novelId, 'warn', 'CriticAgent跳过：' . $e->getMessage());
            }
        }

        // --- StyleGuard ---
        $styleGuardEnabled = (bool)getSystemSetting('ws_style_guard_enabled', true, 'bool');
        if ($styleGuardEnabled) {
            try {
                require_once __DIR__ . '/StyleGuard.php';
                $guard = new StyleGuard($novelId);

                $aiCheckEnabled = (bool)getSystemSetting('ws_ai_patterns_check_enabled', true, 'bool');
                if ($aiCheckEnabled) {
                    $patternResult = $guard->detectAIPatterns($content);
                    $result['style_guard'] = $patternResult;
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
                            AgentDirectives::add(
                                $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                                "前章AI痕迹偏重：{$sugText}。本章请用更自然的人类写作风格。",
                                1, 24
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
                    AgentDirectives::add(
                        $novelId, $chNum + 1, 'quality',
                        "检测到风格漂移：{$driftText}。本章请回调至开篇风格基准，保持全书风格一致。",
                        3, 48
                    );
                }

                $sensoryResult = $guard->checkSensoryBalance($content);
                $result['sensory_balance'] = $sensoryResult;
                if (!$sensoryResult['balanced'] && $sensoryResult['issue']) {
                    $issue = $sensoryResult['issue'];
                    addLog($novelId, 'info', sprintf(
                        '五感平衡检测：%s',
                        $issue['message']
                    ));
                    if ($issue['severity'] !== 'low' || ($sensoryResult['counts']['visual'] ?? 0) > 15) {
                        AgentDirectives::add(
                            $novelId, $chNum + 1, 'quality',
                            "五感平衡问题：{$issue['message']}。{$issue['suggestion']}",
                            1, 24
                        );
                    }
                }
            } catch (\Throwable $e) {
                addLog($novelId, 'warn', 'StyleGuard跳过：' . $e->getMessage());
            }
        }

        // --- 对话差异化检测 (v1.10.3) ---
        try {
            require_once __DIR__ . '/DialogueVoiceChecker.php';
            require_once __DIR__ . '/../memory/CharacterCardRepo.php';
            $voiceRepo = new \CharacterCardRepo($novelId);
            $voiceMap = $voiceRepo->listWithVoiceProfile();
            if (!empty($voiceMap)) {
                $voiceChecker = new DialogueVoiceChecker($novelId);
                $voiceResult = $voiceChecker->check($content, $voiceMap);
                $result['dialogue_voice'] = $voiceResult;
                if (!empty($voiceResult['issues'])) {
                    $highIssues = array_filter($voiceResult['issues'], fn($i) => ($i['severity'] ?? '') === 'high');
                    $mediumIssues = array_filter($voiceResult['issues'], fn($i) => ($i['severity'] ?? '') === 'medium');
                    if ($highIssues) {
                        $msg = implode('；', array_map(fn($i) => $i['message'], array_slice($highIssues, 0, 2)));
                        AgentDirectives::add(
                            $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                            "对话差异化问题（严重）：{$msg}。请严格按角色语音规则修正。",
                            1, 24
                        );
                    } elseif ($mediumIssues) {
                        $msg = implode('；', array_map(fn($i) => $i['suggestion'] ?? $i['message'], array_slice($mediumIssues, 0, 2)));
                        AgentDirectives::add(
                            $novelId, (int)$chapter['chapter_number'] + 1, 'quality',
                            "对话差异化提醒：{$msg}",
                            1, 24
                        );
                    }
                    addLog($novelId, 'info', sprintf(
                        '对话差异化检测：%d项问题',
                        count($voiceResult['issues'])
                    ));
                }
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '对话差异化检测跳过：' . $e->getMessage());
        }

        // --- 去重 ---
        $result['deduped'] = self::deduplicateDirectives($novelId);

        return $result;
    }

    /**
     * 去重：同类型、同章节范围的指令只保留内容最详细的一条
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

            $keep = [];
            $toDeactivate = [];
            foreach ($all as $d) {
                $key = $d['type'] . '|' . $d['apply_from'];
                if (!isset($keep[$key])) {
                    $keep[$key] = $d;
                } elseif (mb_strlen($d['directive'] ?? '') > mb_strlen($keep[$key]['directive'] ?? '')) {
                    $toDeactivate[] = (int)$keep[$key]['id'];
                    $keep[$key] = $d;
                } else {
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
                   AND apply_from >= ? AND directive LIKE ?',
                [$novelId, max(1, $currentChapter - 5), '%读者视角%']
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
    private static function buildAdaptiveCriticDirective(array $weakDim, int $consecutive): string
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
            'thrill' => '确保至少有一个"读者看到这里会叫好"的高潮时刻，冲突必须以出人意料的方式解决。',
            'immersion' => '增加主角的感官描写（看到/听到/感觉到），减少旁白式叙述，让读者跟着主角的视角走。',
            'pacing' => '检查是否有连续超过300字无对话/无事件的段落，如有必须打断。节奏要像心跳一样有快有慢。',
            'freshness' => '至少引入一个读者意料之外的元素：角色的隐藏面、反常识的信息、反套路的剧情走向。',
            'read_next' => '章末必须设置"无法放下的悬念"：要么是即将到来的巨大危机，要么是颠覆认知的反转。',
            default => "重点改善「{$label}」维度。",
        };

        return "前章读者视角「{$label}」偏低（{$score}/10）{$escalation}原因：{$reason}。{$dimAdvice}";
    }

    private function calculateDynamicIntervals(array $context): array
    {
        $progress = $context['current_progress'] ?? 0;
        $recentChapters = $context['recent_chapters'] ?? [];

        $stageMultiplier = match(true) {
            $progress < 0.1 => 2.0,
            $progress < 0.2 => 1.5,
            $progress > 0.85 => 0.5,
            $progress > 0.7 => 0.75,
            default => 1.0,
        };

        $writingSpeed = $this->estimateWritingSpeed($recentChapters);
        $speedAdjustment = match(true) {
            $writingSpeed > 10 => 0.5,
            $writingSpeed > 5 => 0.75,
            $writingSpeed < 1 => 1.5,
            $writingSpeed < 2 => 1.25,
            default => 1.0,
        };

        $baseIntervals = [
            'strategy' => 10,
            'quality' => 5,
            'optimization' => 20,
        ];

        $finalIntervals = [];
        foreach ($baseIntervals as $type => $base) {
            $adjusted = (int)round($base * $stageMultiplier * $speedAdjustment);
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
            $result = $detector->detect($currentChapter);

            if ($result && in_array($result['severity'], ['high', 'medium'])) {
                // 写入 Agent 指令
                $directiveType = match ($result['type']) {
                    'pattern_repetition' => 'strategy',
                    'consecutive_repetition' => 'strategy',
                    'element_overuse' => 'quality',
                    default => 'strategy',
                };

                AgentDirectives::add(
                    $this->novelId,
                    $currentChapter + 1,
                    $directiveType,
                    $result['directive'],
                    applyRange: 5,
                    expiresInHours: 48,
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
                $prevChapter = DB::fetch(
                    'SELECT hook_type, actual_hook_type FROM chapters WHERE novel_id=? AND chapter_number=?',
                    [$this->novelId, $chNum - 1]
                );
                if (empty($prevChapter['actual_hook_type']) && empty($prevChapter['hook_type'])) {
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
            $status = $monitor->getStatus($currentChapter);

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

            $nextChapter = $currentChapter + 1;
            $existing = DB::fetch(
                "SELECT id FROM agent_directives 
                 WHERE novel_id=? AND type='strategy' AND is_active=1
                   AND apply_from <= ? AND apply_to >= ?
                   AND directive LIKE '%认知负荷%'
                 LIMIT 1",
                [$this->novelId, $nextChapter, $nextChapter]
            );
            if ($existing) return null;

            AgentDirectives::add(
                $this->novelId,
                $nextChapter,
                'strategy',
                $directive,
                applyRange: 2,
                expiresInHours: 48,
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
