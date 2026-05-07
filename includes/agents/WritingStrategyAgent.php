<?php
/**
 * 写作策略Agent
 *
 * 职责: 根据小说特征和历史数据动态调整写作策略
 *
 * 决策内容:
 * - 字数目标调整
 * - 爽点密度调整
 * - 节奏控制策略
 * - Prompt模板选择
 *
 * v1.1 新增：
 * - 集成 AdaptiveDecisionEngine 实现效果驱动自适应
 * - 集成 TrendPredictor 实现趋势预测
 * - 集成 WritingExpertise 实现专家知识注入
 *
 * @package NovelWritingSystem
 * @version 1.1.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseAgent.php';

class WritingStrategyAgent extends BaseAgent
{
    /** @var string 小说题材 */
    private $genre;

    /** @var int 当前已写章节数 */
    private $currentChapter;

    /** @var int 目标章节数 */
    private $targetChapters;

    /** @var array 题材特征配置 */
    private $genreConfigs = [
        '玄幻' => ['coolpoint_density' => 1.2, 'pacing' => 'fast'],
        '都市' => ['coolpoint_density' => 1.0, 'pacing' => 'medium'],
        '言情' => ['coolpoint_density' => 0.8, 'pacing' => 'slow'],
        '科幻' => ['coolpoint_density' => 1.0, 'pacing' => 'medium'],
        '历史' => ['coolpoint_density' => 0.9, 'pacing' => 'slow'],
    ];

    /** @var AdaptiveDecisionEngine|null 自适应决策引擎 */
    private ?AdaptiveDecisionEngine $adaptiveEngine = null;

    /** @var TrendPredictor|null 趋势预测器 */
    private ?TrendPredictor $trendPredictor = null;

    /**
     * 构造函数
     *
     * @param int $novelId 小说ID
     */
    public function __construct(int $novelId)
    {
        parent::__construct('writing_strategy', $novelId);
        $this->loadNovelInfo();
        $this->initEngines();
    }

    /**
     * 初始化智能引擎
     */
    private function initEngines(): void
    {
        try {
            require_once __DIR__ . '/AdaptiveDecisionEngine.php';
            require_once __DIR__ . '/TrendPredictor.php';

            $this->adaptiveEngine = new AdaptiveDecisionEngine($this->novelId);
            $this->trendPredictor = new TrendPredictor($this->novelId);
        } catch (\Throwable $e) {
            error_log('WritingStrategyAgent 初始化引擎失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 决策: 调整写作策略
     *
     * @param array $context 决策上下文
     * @return array 决策结果
     */
    public function decide(array $context): array
    {
        $startTime = microtime(true);

        // 1. 验证上下文
        $validation = $this->validateContext($context, ['pending_foreshadowing_count']);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => '缺少必需字段: ' . implode(', ', $validation['missing']),
            ];
        }

        // 2. 收集决策依据
        $factors = $this->collectDecisionFactors($context);

        // 3. 获取自适应权重（新增）
        $adaptiveWeight = $this->adaptiveEngine ? $this->adaptiveEngine->getAdaptiveWeight('strategy') : 1.0;
        $factors['adaptive_weight'] = $adaptiveWeight;

        // 4. 获取趋势预测（新增）
        $predictions = [];
        if ($this->adaptiveEngine) {
            $predictions = $this->adaptiveEngine->predictIssues();
            $factors['predictions'] = $predictions;
        }

        // 5. 分析当前状态
        $analysis = $this->analyzeCurrentState($factors);

        // 6. 生成决策建议（传入自适应权重）
        $decisions = $this->generateDecisions($analysis, $factors);

        // 7. 应用自适应权重调整决策强度（新增）
        $decisions = $this->applyAdaptiveWeight($decisions, $adaptiveWeight);

        // 8. 记录决策日志
        $decisionData = [
            'factors' => $factors,
            'analysis' => $analysis,
            'decisions' => $decisions,
            'predictions' => $predictions,
            'adaptive_weight' => $adaptiveWeight,
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ];

        $this->logDecision($decisionData);

        return [
            'success' => true,
            'decisions' => $decisions,
            'analysis' => $analysis,
            'predictions' => $predictions,
            'adaptive_weight' => $adaptiveWeight,
        ];
    }

    /**
     * 应用自适应权重调整决策强度
     */
    private function applyAdaptiveWeight(array $decisions, float $weight): array
    {
        // 如果权重低，降低调整幅度
        if ($weight < 0.7 && !empty($decisions['coolpoint_density_adjustment'])) {
            $decisions['coolpoint_density_adjustment']['reason'] .= '（历史指令效果一般，谨慎调整）';
        }

        // 如果权重高，增加决策说明
        if ($weight > 1.2) {
            foreach ($decisions['reasoning'] as &$reason) {
                $reason .= '，历史指令效果良好';
            }
        }

        return $decisions;
    }
    
    /**
     * 收集决策依据
     * 
     * @param array $context 上下文
     * @return array 决策因素
     */
    private function collectDecisionFactors(array $context): array
    {
        $directiveEffectiveness = $this->getDirectiveEffectiveness();

        return [
            'genre' => $this->genre,
            'progress' => $this->targetChapters > 0 ? $this->currentChapter / $this->targetChapters : 0,
            'avg_quality_score' => $this->getAverageQualityScore(),
            'recent_word_accuracy' => $this->getRecentWordAccuracy(),
            'recent_coolpoint_density' => $this->getRecentCoolPointDensity(),
            'api_success_rate' => $this->getAPISuccessRate(),
            'pending_foreshadowing' => $context['pending_foreshadowing_count'] ?? 0,
            'current_chapter' => $this->currentChapter,
            'directive_effectiveness' => $directiveEffectiveness,
        ];
    }

    private function getDirectiveEffectiveness(): array
    {
        try {
            require_once __DIR__ . '/AgentDirectives.php';
            $stats = AgentDirectives::getOutcomeStats($this->novelId);

            $byType = [];
            foreach ($stats['by_type'] as $row) {
                $total = (int)$row['outcome_count'];
                $improved = (int)$row['improved'];
                $byType[$row['type']] = [
                    'avg_change' => (float)$row['avg_change'],
                    'effectiveness_rate' => $total > 0 ? round($improved / $total, 2) : 0.5,
                    'total' => $total,
                ];
            }

            $totalOutcomes = array_sum(array_column($stats['by_type'], 'outcome_count'));
            $totalImproved = array_sum(array_column($stats['by_type'], 'improved'));

            return [
                'by_type' => $byType,
                'overall_effectiveness' => $totalOutcomes > 0 ? round($totalImproved / $totalOutcomes, 2) : 0.5,
            ];
        } catch (\Throwable $e) {
            error_log("WritingStrategyAgent::getHistoricalEffectiveness failed: {$e->getMessage()}");
            return ['by_type' => [], 'overall_effectiveness' => 0.5];
        }
    }
    
    /**
     * 分析当前状态
     * 
     * @param array $factors 决策因素
     * @return array 状态分析
     */
    private function analyzeCurrentState(array $factors): array
    {
        $analysis = [
            'strengths' => [],
            'weaknesses' => [],
            'opportunities' => [],
            'threats' => [],
            'recommendations' => [],
        ];
        
        // 分析质量
        if ($factors['avg_quality_score'] >= 80) {
            $analysis['strengths'][] = '质量评分优秀';
        } elseif ($factors['avg_quality_score'] < 60) {
            $analysis['weaknesses'][] = '质量评分偏低';
            $analysis['recommendations'][] = '建议启用严格质量检查';
        }
        
        // 分析字数准确率
        if ($factors['recent_word_accuracy'] >= 0.9) {
            $analysis['strengths'][] = '字数控制准确';
        } elseif ($factors['recent_word_accuracy'] < 0.7) {
            $analysis['weaknesses'][] = '字数控制不佳';
            $analysis['recommendations'][] = '建议调整字数容差';
        }
        
        // 分析爽点密度
        $targetDensity = $this->getTargetCoolPointDensity();
        if ($factors['recent_coolpoint_density'] < $targetDensity * 0.8) {
            $analysis['opportunities'][] = '爽点密度可提升';
            $analysis['recommendations'][] = '建议增加爽点密度';
        }
        
        // 分析进度
        if ($factors['progress'] > 0.8) {
            $analysis['threats'][] = '接近完结,需回收伏笔';
        } elseif ($factors['progress'] < 0.2) {
            $analysis['opportunities'][] = '开篇阶段,可大胆铺垫';
        }
        
        // 分析伏笔
        if ($factors['pending_foreshadowing'] > 10) {
            $analysis['threats'][] = '伏笔堆积过多';
            $analysis['recommendations'][] = '建议规划伏笔回收';
        }
        
        return $analysis;
    }
    
    /**
     * 生成决策建议
     * 
     * @param array $analysis 状态分析
     * @param array $factors 决策因素
     * @return array 决策建议
     */
    private function generateDecisions(array $analysis, array $factors): array
    {
        $decisions = [
            'word_count_adjustment' => null,
            'coolpoint_density_adjustment' => null,
            'pacing_strategy' => null,
            'foreshadowing_strategy' => null,
            'reasoning' => [],
        ];

        $directiveEffectiveness = $factors['directive_effectiveness'] ?? [];
        $strategyEffectiveness = $directiveEffectiveness['by_type']['strategy']['effectiveness_rate'] ?? 0.5;
        $adjustmentFactor = $strategyEffectiveness < 0.4 ? 0.5 : ($strategyEffectiveness < 0.6 ? 0.75 : 1.0);

        if (in_array('字数控制不佳', $analysis['weaknesses'])) {
            $decisions['word_count_adjustment'] = [
                'action' => 'increase_tolerance',
                'value' => round(0.15 * $adjustmentFactor, 3),
                'reason' => $strategyEffectiveness < 0.4
                    ? '上次字数指令效果差，减少调整幅度'
                    : '字数控制不佳,增加容差范围',
            ];
            $decisions['reasoning'][] = '字数准确率低于70%' . ($strategyEffectiveness < 0.4 ? '，但历史指令效果差，谨慎调整' : '');
        }

        foreach ($analysis['opportunities'] as $opp) {
            if (strpos($opp, '爽点密度可提升') !== false) {
                $targetDensity = $this->getTargetCoolPointDensity();
                $densityFactor = ($directiveEffectiveness['by_type']['quality']['effectiveness_rate'] ?? 0.5) < 0.4 ? 0.8 : 1.2;
                $decisions['coolpoint_density_adjustment'] = [
                    'action' => 'increase_density',
                    'value' => round($targetDensity * 1.2 * $densityFactor / 1.2, 2),
                    'reason' => $densityFactor < 1
                        ? '上次爽点指令效果差，降低提升幅度'
                        : '爽点密度低于目标,建议提升20%',
                ];
                $decisions['reasoning'][] = $densityFactor < 1
                    ? '当前爽点密度不足，但历史指令效果差，谨慎增强'
                    : '当前爽点密度不足，需要增强';
                break;
            }
        }

        if ($factors['progress'] > 0.8) {
            $decisions['pacing_strategy'] = [
                'action' => 'accelerate',
                'reason' => '接近完结,加快节奏',
            ];
            $decisions['reasoning'][] = '进度超过80%,进入收尾阶段';
        } elseif ($factors['progress'] < 0.2) {
            $decisions['pacing_strategy'] = [
                'action' => 'steady',
                'reason' => '开篇阶段,稳步推进',
            ];
        }

        if ($factors['pending_foreshadowing'] > 10) {
            $decisions['foreshadowing_strategy'] = [
                'action' => 'prioritize_resolution',
                'value' => min(3, $factors['pending_foreshadowing'] - 5),
                'reason' => '伏笔堆积,优先回收',
            ];
            $decisions['reasoning'][] = "待回收伏笔{$factors['pending_foreshadowing']}个,需要优先处理";
        }

        return $decisions;
    }
    
    /**
     * 执行决策
     * 
     * @param array $decisions 决策建议
     * @return array 执行结果
     */
    public function execute(array $decisions): array
    {
        $results = [];
        
        if (!empty($decisions['word_count_adjustment'])) {
            $results['word_count_adjustment'] = $this->executeWordCountAdjustment($decisions['word_count_adjustment']);
        }
        
        if (!empty($decisions['coolpoint_density_adjustment'])) {
            $results['coolpoint_density_adjustment'] = $this->executeCoolPointAdjustment($decisions['coolpoint_density_adjustment']);
        }
        
        if (!empty($decisions['pacing_strategy'])) {
            $results['pacing_strategy'] = $this->executePacingStrategy($decisions['pacing_strategy']);
        }
        
        if (!empty($decisions['foreshadowing_strategy'])) {
            $results['foreshadowing_strategy'] = $this->executeForeshadowingStrategy($decisions['foreshadowing_strategy']);
        }
        
        return $results;
    }
    
    /**
     * 执行字数调整
     */
    private function executeWordCountAdjustment(array $decision): array
    {
        try {
            $this->logAction($this->novelId, 'adjust_word_tolerance', 'success', [
                'new_value' => $decision['value'],
            ]);

            $directive = $decision['value'] > 0.12
                ? '本章字数要求放宽：允许±15%的字数波动，内容完整性优先于精确字数。宁可多写200字保证高潮段完整性，也不要为了凑字数硬删内容。'
                : '本章字数要求收紧：严格控制在目标字数±8%以内。开头快速入戏，中间不拖沓，80%字数时必须进入收尾。宁可精简描写也要控制总字数。';

            $this->writeDirective('strategy', $directive);

            return [
                'action' => 'adjust_word_tolerance',
                'status' => 'success',
                'message' => "字数策略已注入指令",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'adjust_word_tolerance', 'failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'action' => 'adjust_word_tolerance',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 执行爽点密度调整
     */
    private function executeCoolPointAdjustment(array $decision): array
    {
        try {
            $currentDensity = ConfigCenter::get('ws_cool_point_density_target', 1.0);
            $newDensity = $decision['value'];
            
            ConfigCenter::set('ws_cool_point_density_target', $newDensity, 'float');
            
            $this->logAction($this->novelId, 'adjust_coolpoint_density', 'success', [
                'old_value' => $currentDensity,
                'new_value' => $newDensity,
            ]);

            $direction = $newDensity > $currentDensity ? '提升' : '降低';
            $pctChange = $currentDensity > 0 ? round(($newDensity - $currentDensity) / $currentDensity * 100) : 0;
            $this->writeDirective('strategy',
                "爽点密度调整：目标从{$currentDensity}提升至{$newDensity}（{$direction}{$pctChange}%）。" .
                "本章必须安排至少1个核心爽点，优先选择「逆袭」「打脸」「获宝」类型。" .
                "爽点要埋在章节中后段，铺垫不超过30%，高潮段要有情绪爆发力。" .
                "如果当前有双爽点条件（两个不同类型候选饥饿度≥0.8），安排双爽点章。");

            return [
                'action' => 'adjust_coolpoint_density',
                'status' => 'success',
                'message' => "爽点密度已从{$currentDensity}调整至{$newDensity}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'adjust_coolpoint_density', 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => 'adjust_coolpoint_density',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 执行节奏策略
     */
    private function executePacingStrategy(array $decision): array
    {
        try {
            $pacing = $decision['action'];
            $reason = $decision['reason'] ?? '';

            $pacingDirectives = [
                'accelerate' => '本章节奏加速：缩短铺垫段（≤15%），加长高潮段（≥40%）。减少环境描写和内心独白，用短句快速推进剧情。对话节奏紧凑，一问一答不超过3轮就要触发事件。收尾钩子必须是强悬念或危机爆发。',
                'decelerate' => '本章节奏放缓：铺垫段可占25%，增加角色内心描写和环境氛围渲染。对话中穿插回忆或情感流露。高潮段可以用慢镜头方式展开，放大情绪张力。但结尾仍需悬念。',
                'steady' => '本章保持稳定节奏：严格按照四段式比例推进，铺垫20%、发展30%、高潮35%、钩子15%。对话与动作交替，张弛有度。',
                'intensify' => '本章增强冲突强度：每个场景都必须包含至少一个冲突或对抗。对话中必须有立场对立或信息不对等。高潮段要达到情绪顶点，制造"不得不继续读"的冲动。',
                'prioritize_resolution' => '本章优先回收伏笔：在发展段和高潮段穿插伏笔回收，每个伏笔回收必须带来新的信息或反转。回收伏笔的同时推进主线，不要变成纯解释性段落。',
            ];

            $directive = $pacingDirectives[$pacing] ?? "本章节奏调整为{$pacing}。{$reason}";

            $this->logAction($this->novelId, 'adjust_pacing', 'success', [
                'pacing' => $pacing,
            ]);

            $this->writeDirective('strategy', $directive);

            return [
                'action' => 'adjust_pacing',
                'status' => 'success',
                'message' => "节奏策略已注入指令: {$pacing}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'adjust_pacing', 'failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'action' => 'adjust_pacing',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    // ==================== 辅助方法 ====================
    
    /**
     * 加载小说信息
     */
    private function loadNovelInfo(): void
    {
        try {
            $novel = DB::fetch(
                'SELECT genre, target_chapters FROM novels WHERE id = ?',
                [$this->novelId]
            );
            
            $this->genre = $novel['genre'] ?? '玄幻';
            $this->targetChapters = (int)($novel['target_chapters'] ?? 100);
            
            $this->currentChapter = (int)(DB::fetch(
                'SELECT COALESCE(MAX(chapter_number), 0) + 1 as next_chapter FROM chapters WHERE novel_id = ?',
                [$this->novelId]
            )['next_chapter'] ?? 1);
        } catch (\Throwable $e) {
            error_log("加载小说信息失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取平均质量评分
     */
    private function getAverageQualityScore(): float
    {
        try {
            $score = DB::fetch(
                'SELECT AVG(quality_score) as avg FROM chapters 
                 WHERE novel_id = ? AND quality_score IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT 10',
                [$this->novelId]
            );
            
            return (float)($score['avg'] ?? 75);
        } catch (\Throwable $e) {
            return 75.0;
        }
    }
    
    /**
     * 获取近期字数准确率
     */
    private function getRecentWordAccuracy(): float
    {
        try {
            $chapters = DB::fetchAll(
                'SELECT c.words, n.chapter_words as target
                 FROM chapters c
                 JOIN novels n ON c.novel_id = n.id
                 WHERE c.novel_id = ? AND c.status = "completed"
                 ORDER BY c.chapter_number DESC LIMIT 10',
                [$this->novelId]
            );
            
            if (empty($chapters)) return 1.0;
            
            $accurate = 0;
            foreach ($chapters as $ch) {
                $target = (int)$ch['target'];
                $actual = (int)$ch['words'];
                $tolerance = $target * 0.1;
                
                if (abs($actual - $target) <= $tolerance) {
                    $accurate++;
                }
            }
            
            return count($chapters) > 0 ? $accurate / count($chapters) : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    /**
     * 获取近期爽点密度
     */
    private function getRecentCoolPointDensity(): float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total FROM memory_atoms
                 WHERE novel_id = ? AND atom_type = "cool_point"
                   AND source_chapter >= ?',
                [$this->novelId, max(1, $this->currentChapter - 20)]
            );
            
            $count = (int)($stats['total'] ?? 0);
            $chapters = min(20, max(1, $this->currentChapter));
            
            return $count / $chapters;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    /**
     * 获取API成功率（基于chapters表，v1.5修复）
     */
    private function getAPISuccessRate(): float
    {
        try {
            // v1.5: 用chapters表替代不存在的performance_logs
            $stats = DB::fetch(
                'SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed
                 FROM chapters
                 WHERE novel_id = ?
                   AND chapter_number >= GREATEST(1, (SELECT MAX(chapter_number) - 20 FROM chapters WHERE novel_id = ?))',
                [$this->novelId, $this->novelId]
            );
            
            return $stats['total'] > 0 ? (int)$stats['completed'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    /**
     * 获取目标爽点密度(基于题材)
     */
    private function getTargetCoolPointDensity(): float
    {
        return $this->genreConfigs[$this->genre]['coolpoint_density'] ?? 1.0;
    }

    /**
     * 执行伏笔策略
     */
    private function executeForeshadowingStrategy(array $decision): array
    {
        try {
            $action = $decision['action'];
            $value = $decision['value'] ?? 1;
            $reason = $decision['reason'] ?? '';
            
            $this->logAction($this->novelId, 'foreshadowing_strategy', 'success', [
                'action' => $action,
                'value' => $value,
                'reason' => $reason,
            ]);

            $foreshadowDirectives = [
                'prioritize_resolution' => "伏笔回收指令：当前堆积{$reason}。本章必须在发展段或高潮段回收至少{$value}个旧伏笔。回收方式：通过剧情推进自然揭示，或角色互动中意外触发。禁止用旁白式「原来如此」硬解释。回收的同时必须推进主线，不能变成纯回忆/解释段落。",
            ];

            $directive = $foreshadowDirectives[$action] ?? "伏笔策略调整：{$reason}。请在本章优先处理待回收的伏笔。";
            $this->writeDirective('strategy', $directive);
            
            return [
                'action' => 'foreshadowing_strategy',
                'status' => 'success',
                'message' => "伏笔策略已执行: {$action}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'foreshadowing_strategy', 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => 'foreshadowing_strategy',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
}
