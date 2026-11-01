<?php
/**
 * 质量监控Agent
 * 
 * 职责: 实时监控写作质量,发现问题并生成改进建议
 * 
 * 监控指标:
 * - 整体质量评分
 * - 角色一致性
 * - 剧情连贯性
 * - 爽点有效性
 * - 字数准确率
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseAgent.php';
require_once __DIR__ . '/../quality/DialogueText.php';

class QualityMonitorAgent extends BaseAgent
{
    /** @var array 质量阈值配置 */
    private $qualityThresholds = [
        'excellent' => 85,
        'good' => 70,
        'acceptable' => 60,
        'poor' => 50,
    ];

    /** @var array<string, array<int,string>> 按 chapters ID 集合 hash 缓存的 content 映射 */
    private array $chapterContentsCache = [];

    private static $QUANTIFIABLE_DIRECTIVES = [
        // 审计修复（2026-08-09 R8-4 / R10-6）：章级 1 个核心爽点；全书目标用「有爽点章占比」量纲。
        'coolpoint_density' => [
            'template' => '本章须安排至少{min_count}个核心爽点（全书目标约{density_target}有爽点章占比，禁止灌爽点），建议类型：{examples}；铺垫不超过30%，高潮段释放',
            'default_params' => [
                'min_count' => 1,
                'density_target' => '0.88',
                'examples' => '打脸、越级战斗、获得宝物/传承/能力之一',
            ],
        ],
        // 审计修复（2026-08-09 R8）：改为可见反应次数，避免「加大情绪词」与 Show-don't-tell 矛盾
        'emotion_intensity' => [
            'template' => '本章至少增加{count}处可见情绪反应（微表情/肢体/生理反应），禁止堆砌「很愤怒/很高兴」等直述情绪词',
            'default_params' => [
                'count' => 5,
            ],
        ],
        'pacing_accelerate' => [
            'template' => '节奏加速：本章场景切换不超过{scene_count}次，每段不超过{para_words}字',
            'default_params' => [
                'scene_count' => 5,
                'para_words' => 150,
            ],
        ],
        'quality_strict' => [
            'template' => '严格质量检查：禁止OOC，每段对话必须符合角色性格设定',
            'default_params' => [],
        ],
        'character_consistency' => [
            'template' => '角色一致性检查：{char_reminder}',
            'default_params' => [
                'char_reminder' => '主角对话风格必须与其成长阶段一致，禁止出现超越当前境界的认知',
            ],
        ],
        'description_richness' => [
            'template' => '感官描写要求：每{interval}字至少{count}处感官细节（视觉/听觉/触觉/嗅觉/味觉）',
            'default_params' => [
                'interval' => 500,
                'count' => 2,
            ],
        ],
    ];
    
    /**
     * 构造函数
     * 
     * @param int $novelId 小说ID
     */
    public function __construct(int $novelId)
    {
        parent::__construct('quality_monitor', $novelId);
    }
    
    /**
     * 决策: 监控质量并发现问题
     * 
     * @param array $context 决策上下文
     * @return array 决策结果
     */
    public function decide(array $context): array
    {
        $startTime = microtime(true);
        
        // 1. 收集质量指标
        $metrics = $this->collectQualityMetrics($context);
        
        // 2. 识别质量问题
        $issues = $this->identifyQualityIssues($metrics);
        
        // 3. 评估风险等级
        $risks = $this->assessRisks($issues);
        
        // 4. 生成改进建议
        $recommendations = $this->generateRecommendations($issues, $risks);
        
        // 5. 记录决策日志
        $decisionData = [
            'metrics' => $metrics,
            'issues' => $issues,
            'risks' => $risks,
            'recommendations' => $recommendations,
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ];
        
        $this->logDecision($decisionData);
        
        return [
            'success' => true,
            'metrics' => $metrics,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }
    
    /**
     * 收集质量指标
     * 
     * @param array $context 上下文
     * @return array 质量指标
     */
    private function collectQualityMetrics(array $context): array
    {
        // 修复：优先复用调用方 context 传入的 recent_chapters（2026-07-22），避免重复查询。
        // write_engine.php runPreWriteAgents 已传入 5 章全列（含 content，可直接用于需正文
        // 的指标方法）；daemon 路径仅 6-7 列无 content，由 fetchChapterContents 对缺 content
        // 的行按需补查；context 未提供时维持原有 DB 查询路径（10 章）不变。
        // 注：复用 context 时章节窗口由 10 章变 5 章，指标统计口径随之缩小，差异可接受。
        if ($this->currentChapterNumber > 0 && isset($context['recent_chapters'])) {
            $context['recent_chapters'] = array_values(array_filter($context['recent_chapters'],
                fn($row) => (int)($row['chapter_number'] ?? 0) > 0
                    && (int)$row['chapter_number'] < $this->currentChapterNumber
                    && ($row['status'] ?? 'completed') === 'completed'));
        }
        $recentChapters = (isset($context['recent_chapters'])
            && is_array($context['recent_chapters'])
            && !empty($context['recent_chapters']))
            ? $context['recent_chapters']
            : $this->getRecentChapters(10);
        
        return [
            'overall_quality' => $this->calculateOverallQuality($recentChapters),
            'structure_score' => $this->calculateStructureScore($recentChapters),
            // Field changes describe plot state, not proof of out-of-character behaviour.
            'character_consistency' => null,
            'character_change_frequency' => $this->calculateCharacterChangeFrequency($recentChapters),
            'character_change_frequency_basis' => '平均每个有变更记录角色的变更章占比；不评价人物性格一致性',
            'description_richness' => $this->calculateDescriptionRichness($recentChapters),
            'plot_coherence' => $this->calculatePlotCoherence($recentChapters),
            'coolpoint_effectiveness' => $this->calculateCoolPointEffectiveness($recentChapters),
            'word_count_accuracy' => $this->calculateWordCountAccuracy($recentChapters),
            'foreshadowing_usage' => $this->calculateForeshadowingUsage(),
        ];
    }
    
    /**
     * 识别质量问题
     * 
     * @param array $metrics 质量指标
     * @return array 问题列表
     */
    /**
     * 识别质量问题。缺数据指标为 null，不得按 0 误报，也不得当满分跳过。
     *
     * @param array<string, mixed> $metrics
     * @return array
     */
    private function identifyQualityIssues(array $metrics): array
    {
        $issues = [];

        if ($this->metricBelow($metrics['overall_quality'] ?? null, $this->qualityThresholds['acceptable'])) {
            $issues[] = [
                'type' => 'quality_decline',
                'severity' => 'high',
                'description' => "整体质量评分{$metrics['overall_quality']}分,低于可接受阈值",
                'metric' => $metrics['overall_quality'],
                'threshold' => $this->qualityThresholds['acceptable'],
            ];
        }

        if ($this->metricBelow($metrics['structure_score'] ?? null, $this->qualityThresholds['good'])) {
            $issues[] = [
                'type' => 'structure_issue',
                'severity' => 'medium',
                'description' => "章节结构评分{$metrics['structure_score']}分,需要优化",
                'metric' => $metrics['structure_score'],
                'threshold' => $this->qualityThresholds['good'],
            ];
        }

        if ($this->metricBelow($metrics['character_consistency'] ?? null, 0.8)) {
            $issues[] = [
                'type' => 'character_inconsistency',
                'severity' => 'high',
                'description' => "角色一致性{$metrics['character_consistency']},存在OOC风险",
                'metric' => $metrics['character_consistency'],
                'threshold' => 0.8,
            ];
        }

        if ($this->metricBelow($metrics['description_richness'] ?? null, 0.6)) {
            $issues[] = [
                'type' => 'poor_description',
                'severity' => 'medium',
                'description' => "描写丰富度{$metrics['description_richness']},建议增强",
                'metric' => $metrics['description_richness'],
                'threshold' => 0.6,
            ];
        }

        if ($this->metricBelow($metrics['plot_coherence'] ?? null, 0.75)) {
            $issues[] = [
                'type' => 'plot_incoherence',
                'severity' => 'high',
                'description' => "剧情连贯性{$metrics['plot_coherence']},存在逻辑问题",
                'metric' => $metrics['plot_coherence'],
                'threshold' => 0.75,
            ];
        }

        if ($this->metricBelow($metrics['coolpoint_effectiveness'] ?? null, 0.7)) {
            $issues[] = [
                'type' => 'weak_coolpoint',
                'severity' => 'medium',
                'description' => "爽点有效性{$metrics['coolpoint_effectiveness']},需要调整",
                'metric' => $metrics['coolpoint_effectiveness'],
                'threshold' => 0.7,
            ];
        }

        if ($this->metricBelow($metrics['word_count_accuracy'] ?? null, 0.8)) {
            $issues[] = [
                'type' => 'word_count_issue',
                'severity' => 'medium',
                'description' => "字数准确率{$metrics['word_count_accuracy']},控制不稳定",
                'metric' => $metrics['word_count_accuracy'],
                'threshold' => 0.8,
            ];
        }

        if ($this->metricBelow($metrics['foreshadowing_usage'] ?? null, 0.5)) {
            $issues[] = [
                'type' => 'unused_foreshadowing',
                'severity' => 'low',
                'description' => "伏笔利用率{$metrics['foreshadowing_usage']},存在未回收伏笔",
                'metric' => $metrics['foreshadowing_usage'],
                'threshold' => 0.5,
            ];
        }

        return $issues;
    }

    /** 缺测指标（null）既不告警也不当满分。 */
    private function metricBelow(mixed $value, float $threshold): bool
    {
        return is_numeric($value) && (float)$value < $threshold;
    }
    
    /**
     * 评估风险等级
     * 
     * @param array $issues 问题列表
     * @return array 风险评估
     */
    private function assessRisks(array $issues): array
    {
        $risks = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];
        
        foreach ($issues as $issue) {
            $severity = $issue['severity'];
            
            // 计算风险得分
            $riskScore = $this->calculateRiskScore($issue);
            
            $risks[$severity][] = [
                'issue_type' => $issue['type'],
                'risk_score' => $riskScore,
                'impact' => $this->assessImpact($issue),
                'urgency' => $this->assessUrgency($issue),
            ];
        }
        
        return $risks;
    }
    
    /**
     * 生成改进建议
     * 
     * @param array $issues 问题列表
     * @param array $risks 风险评估
     * @return array 改进建议
     */
    private function generateRecommendations(array $issues, array $risks): array
    {
        $recommendations = [];
        
        $recommendationMap = [
            'quality_decline' => [
                'action' => 'enhance_quality_check',
                'description' => '启用严格质量检查模式',
                'priority' => 10,
                'params' => ['strictness' => 'high'],
            ],
            'structure_issue' => [
                'action' => 'fix_structure',
                'description' => '修复章节结构问题',
                'priority' => 8,
                'params' => ['structure_check' => true],
            ],
            'character_inconsistency' => [
                'action' => 'strengthen_character_tracking',
                'description' => '加强角色一致性检查',
                'priority' => 9,
                'params' => ['check_frequency' => 'every_paragraph'],
            ],
            'plot_incoherence' => [
                'action' => 'enhance_plot_analysis',
                'description' => '启用深度剧情分析',
                'priority' => 9,
                'params' => ['depth' => 'deep'],
            ],
            'weak_coolpoint' => [
                'action' => 'adjust_coolpoint_strategy',
                'description' => '调整爽点策略,增强强度',
                'priority' => 7,
                'params' => ['intensity_boost' => 0.2],
            ],
            'poor_description' => [
                'action' => 'enrich_description',
                'description' => '增加感官描写细节',
                'priority' => 6,
                'params' => ['sensory_details' => true],
            ],
            'word_count_issue' => [
                'action' => 'refine_word_control',
                'description' => '优化字数控制算法',
                'priority' => 5,
                'params' => ['tolerance_reduction' => 0.05],
            ],
            'unused_foreshadowing' => [
                'action' => 'schedule_foreshadowing',
                'description' => '规划伏笔回收计划',
                'priority' => 4,
                'params' => ['look_ahead_chapters' => 5],
            ],
        ];
        
        foreach ($issues as $issue) {
            if (isset($recommendationMap[$issue['type']])) {
                $recommendations[] = array_merge(
                    $recommendationMap[$issue['type']],
                    // P0 修复：补顶层 type 键——AgentCoordinator::collectAllDirectives
                    // 按 $rec['type'] 取类型做冲突去重，缺失时全部为 'unknown'，
                    // 导致每轮只保留 1 条 quality 建议、其余被静默丢弃
                    ['type' => $issue['type'], 'issue' => $issue]
                );
            }
        }
        
        // 按优先级排序
        usort($recommendations, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        return $recommendations;
    }
    
    /**
     * 执行质量改进
     * 
     * @param array $recommendations 改进建议
     * @return array 执行结果
     */
    public function execute(array $recommendations): array
    {
        $results = [];

        // 审计修复（2026-08-09 R9-6）：接线 agent.quality_agent.auto_fix
        if (!ConfigCenter::get('agent.quality_agent.auto_fix', true, 'bool')) {
            foreach ($recommendations as $rec) {
                $results[] = [
                    'action' => $rec['action'] ?? 'unknown',
                    'status' => 'skipped',
                    'message' => 'auto_fix 已关闭，跳过质量整改指令',
                ];
            }
            return $results;
        }

        foreach ($recommendations as $rec) {
            $result = $this->executeRecommendation($rec);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * 执行单个建议
     * 
     * @param array $rec 建议
     * @return array 执行结果
     */
    private function classifyIssueType(string $action): string
    {
        $typeMap = [
            'enhance_quality_check' => 'quality_strict',
            'strengthen_character_tracking' => 'character_consistency',
            'adjust_coolpoint_strategy' => 'coolpoint_density',
            'enrich_description' => 'description_richness',
        ];
        return $typeMap[$action] ?? 'generic';
    }

    private function generateQuantifiableDirective(string $action, array $rec): ?string
    {
        $type = $this->classifyIssueType($action);

        if (!isset(self::$QUANTIFIABLE_DIRECTIVES[$type])) {
            return null;
        }

        $template = self::$QUANTIFIABLE_DIRECTIVES[$type];
        $params = $template['default_params'];
        $issueMetric = $rec['issue']['metric'] ?? 0;
        $severity = is_numeric($issueMetric) ? (float)$issueMetric : 50;
        // 量纲修复：quality_strict 的 metric 是 0-100 分制（overall_quality/structure_score），
        // 其余类型（coolpoint_density/description_richness/emotion_intensity/character_consistency）
        // 均为 0-1 小数。原代码统一用 < 40 / < 60 比较，导致 0-1 指标恒走最严档
        // （如 coolpoint_effectiveness=0.5 → severity=0.5 < 40 → 恒取 min_count=4）。
        // 此处将 0-1 量纲归一化到 0-100 后再与阈值比较。
        if ($type !== 'quality_strict' && $severity <= 1.0) {
            $severity *= 100;
        }

        // 审计修复（2026-08-09 R8-4）：coolpoint 保持章级 1 个核心爽点，不再按严重度灌到 2–4；
        // emotion 改为可见反应次数（5–8），不再要求每千字 15–25 个情绪词。
        if ($type === 'coolpoint_density') {
            $params['min_count'] = 1;
            // 审计修复（2026-08-09 R11）：插值小说级密度目标，禁止硬编码 0.88
            $densityTarget = 0.88;
            if (class_exists('ConfigCenter', false)) {
                $densityTarget = (float)ConfigCenter::getNovel(
                    $this->novelId,
                    'ws_cool_point_density_target',
                    0.88,
                    'float'
                );
            }
            $params['density_target'] = (string)round(max(0.3, min(1.0, $densityTarget)), 2);
        } elseif ($type === 'emotion_intensity') {
            $params['count'] = $severity < 40 ? 8 : ($severity < 60 ? 6 : 5);
        } elseif ($type === 'description_richness') {
            $params['count'] = $severity < 40 ? 3 : 2;
        }

        $directive = str_replace(
            array_map(fn($k) => '{' . $k . '}', array_keys($params)),
            array_values($params),
            $template['template']
        );

        return $directive;
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 C1）：收紧小说级动态容差并真正写库。
     *
     * 与策略 Agent 的调整路径共用 ConfigCenter::setNovel('ws_dynamic_tolerance_ratio')，
     * 步长按 tolerance_reduction 比例换算（默认 5 个百分点），结果夹紧 [5, 20]；
     * 同一写作周期内已有容差写入（toleranceWriteAlreadyDone 互斥守卫）则跳过。
     * 成功写入后补记一条 action=adjust_word_tolerance 日志，供策略 Agent 侧守卫识别。
     *
     * @return array{current_pct:int,new_pct:int,skipped:bool,reason:string}
     */
    private function tightenWordTolerance(float $reductionRatio): array
    {
        $currentPct = (int)ConfigCenter::getNovel($this->novelId, 'ws_dynamic_tolerance_ratio', 10, 'int');
        $result = [
            'current_pct' => $currentPct,
            'new_pct'     => $currentPct,
            'skipped'     => true,
            'reason'      => '',
        ];
        if ($this->toleranceWriteAlreadyDone($this->novelId)) {
            $result['reason'] = '本写作周期已有容差写入（策略/质量互斥），仅下发本章指令';
            return $result;
        }
        $stepPct = max(1, (int)round(abs($reductionRatio) * 100));
        $newPct = max(5, min(20, $currentPct - $stepPct));
        $result['new_pct'] = $newPct;
        if (!ConfigCenter::setNovel($this->novelId, 'ws_dynamic_tolerance_ratio', $newPct, 'int')) {
            $result['reason'] = '小说级容差配置保存失败，仅下发本章指令';
            return $result;
        }
        $result['skipped'] = false;
        // 互斥守卫的锚点日志：与策略 Agent 的 adjust_word_tolerance 同名同查。
        $this->logAction($this->novelId, 'adjust_word_tolerance', 'success', [
            'direction'  => 'tighten',
            'old_ratio_pct' => $currentPct,
            'ratio_pct'  => $newPct,
            'writer'     => 'quality_agent',
        ]);
        return $result;
    }

    private function executeRecommendation(array $rec): array
    {
        try {
            switch ($rec['action']) {
                case 'enhance_quality_check':
                    // 审计修复 P2-12（2026-07-12）：移除 ConfigCenter::set('ws_quality_strictness', ...) 等
                    // 全局写入——这些 setting 没有任何 reader，且会污染其他小说。
                    // 改由 writeDirective()（已 novel-scoped）传递本章指令。
                    // 审计修复（2026-08-17 写作逻辑 C1）：诚实口径——本动作只写本章
                    // 指令，不修改任何全局/小说级质检开关，消息不再宣称"已启用"全局。
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params'] + ['directive_only' => true]);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章启用严格质量检查。触发原因：整体质量评分{$rec['issue']['metric']}分，低于可接受阈值{$rec['issue']['threshold']}分。重点检查：结构完整性、角色一致性、剧情连贯性。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已注入严格质量检查指令（仅本章，不改全局设置）'];

                case 'strengthen_character_tracking':
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章加强角色一致性检查。触发原因：角色一致性{$rec['issue']['metric']}，存在OOC风险。重点检查：角色对话风格、行为逻辑、性格特征是否与设定一致。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已加强角色追踪'];

                case 'adjust_coolpoint_strategy':
                    // 审计修复 P2-12：cool_point_intensity 全局 setting 无 reader，移除全局写入；
                    // 本章指令通过 writeDirective 下发，不污染其他小说。
                    // 审计修复（2026-08-17 写作逻辑 C1）：诚实口径——爽点「强度」没有
                    // 小说级 reader（ws_cool_point_density_target 是密度占比，量纲不同，
                    // 不冒用），保持指令直发并标 directive_only，日志不再含糊。
                    $currentIntensity = 1.0;
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params'] + ['directive_only' => true]);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章增加爽点强度，从{$currentIntensity}提升至" . ($currentIntensity + 0.2) . "。触发原因：爽点有效性{$rec['issue']['metric']}，低于0.7阈值。重点：增强冲突张力、加快剧情节奏、强化情感冲击。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已注入爽点强度指令（仅本章，不改全局设置）'];

                case 'enrich_description':
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章启用感官描写。触发原因：描写丰富度{$rec['issue']['metric']}，低于0.6阈值。重点：增加视觉、听觉、触觉、嗅觉、味觉描写，丰富场景细节和氛围营造。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已启用感官描写'];

                case 'enhance_plot_analysis':
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章启用深度剧情分析。触发原因：剧情连贯性{$rec['issue']['metric']}，存在断裂风险。重点：检查因果链条、伏笔回收、情节转折的合理性。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已启用深度剧情分析'];

                case 'refine_word_control':
                    // 审计修复 P2-12：word_count_tolerance 全局 setting 无 reader，移除全局写入。
                    // 审计修复（2026-08-17 写作逻辑 C1）：此前「收紧字数容差」只写本章指令、
                    // 实际不动库，宣称与效果不符。现与策略 Agent 共用同一 setter 写小说级
                    // ws_dynamic_tolerance_ratio（方向：收紧，下限 5%）；同一写作周期已有
                    // 容差写入（策略/质量互斥守卫）则跳过并打日志，指令仍照发。
                    $tolResult = $this->tightenWordTolerance(
                        (float)($rec['params']['tolerance_reduction'] ?? 0.05)
                    );
                    $this->logAction(
                        $this->novelId,
                        $rec['action'],
                        empty($tolResult['skipped']) ? 'success' : 'skipped',
                        ['tolerance_result' => $tolResult] + (array)($rec['params'] ?? [])
                    );
                    $currentPct = (int)$tolResult['current_pct'];
                    $newPct = (int)$tolResult['new_pct'];
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章收紧字数容差，从±{$currentPct}%降至±{$newPct}%。触发原因：字数偏差{$rec['issue']['metric']}，超出可接受范围。重点：控制正文长度在目标字数±{$newPct}%以内。";
                    $this->writeDirective('quality', $directive);
                    if (!empty($tolResult['skipped'])) {
                        return [
                            'action'  => $rec['action'],
                            'status'  => 'skipped',
                            'message' => '容差写入跳过：' . ($tolResult['reason'] ?? '本周期已有写入'),
                        ];
                    }
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => "已收紧字数容差至±{$newPct}%（写库生效）"];

                case 'schedule_foreshadowing':
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $lookAhead = $rec['params']['look_ahead_chapters'] ?? 5;
                    $directive = $quantitative ?? "本章规划伏笔回收。触发原因：未回收伏笔{$rec['issue']['metric']}个，存在遗忘风险。重点：在未来{$lookAhead}章内安排至少1个伏笔回收，优先回收逾期项。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已规划伏笔回收'];

                case 'fix_structure':
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章修复结构问题。触发原因：结构完整性{$rec['issue']['metric']}，低于阈值。重点：确保开头-发展-高潮-结尾四段式结构完整，场景转换自然，章节有明确的叙事目标。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已启用结构修复'];

                default:
                    return ['action' => $rec['action'], 'status' => 'skipped', 'message' => '未实现的操作'];
            }
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, $rec['action'], 'failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'action' => $rec['action'],
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    // ==================== 辅助计算方法 ====================
    
    private function getRecentChapters(int $limit): array
    {
        try {
            // 审计修复 PERF-C2（2026-07-01）：列裁剪，质量监控只需指标字段不需 LONGTEXT content
            return DB::fetchAll(
                'SELECT id, novel_id, chapter_number, title, status, words, quality_score, critic_scores, ai_pattern_issues, gate_results
                 FROM chapters
                 WHERE novel_id = ? AND status = "completed" AND chapter_number < ?
                 ORDER BY chapter_number DESC LIMIT ?',
                [$this->novelId, $this->currentChapterNumber ?: PHP_INT_MAX, $limit]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 按章节 ID 批量获取正文内容
     * 审计修复 P0-2（2026-07-12）：PERF-C2 列裁剪后 content 不在 getRecentChapters 结果中，
     * 需要正文的方法（结构评分/角色一致性/描写丰富度）按需单独查询，避免回归。
     *
     * 修复（2026-07-22）：章节行自带 content 键时直接复用（调用方 context 传入的
     * recent_chapters 已含全列 content），仅对缺 content 键的行按需补查，避免二次
     * 拉取 10 章 LONGTEXT 大字段。daemon 路径 recent_chapters 无 content 列，
     * 会完整走补查分支，行为与原有一致。
     *
     * @param array $chapters getRecentChapters 或 context['recent_chapters'] 返回的章节数组
     * @return array<int,string> chapter_id => content
     */
    private function fetchChapterContents(array $chapters): array
    {
        if (empty($chapters)) return [];

        // 修复：一次 decide 内 fetchChapterContents 被 calculateStructureScore、
        // calculateCharacterConsistency、calculateDescriptionRichness 各调一次，
        // 每次对同一批章节发起大字段（LONGTEXT content）IN 查询。
        // 以 ID 集合 hash 为键缓存，避免重复查询。
        $ids = [];
        foreach ($chapters as $ch) {
            $id = (int)($ch['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        $cacheKey = md5(implode(',', $ids));
        if (isset($this->chapterContentsCache[$cacheKey])) {
            return $this->chapterContentsCache[$cacheKey];
        }

        $map = [];
        $missingIds = [];
        foreach ($chapters as $ch) {
            if (array_key_exists('content', $ch)) {
                $map[(int)($ch['id'] ?? 0)] = (string)($ch['content'] ?? '');
            } elseif (isset($ch['id'])) {
                $missingIds[] = $ch['id'];
            }
        }
        if (empty($missingIds)) {
            $this->chapterContentsCache[$cacheKey] = $map;
            return $map;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $rows = DB::fetchAll(
                "SELECT id, content FROM chapters WHERE id IN ($placeholders)",
                $missingIds
            ) ?: [];
            foreach ($rows as $r) {
                $map[(int)$r['id']] = $r['content'] ?? '';
            }
            $this->chapterContentsCache[$cacheKey] = $map;
            return $map;
        } catch (\Throwable $e) {
            return $map;
        }
    }
    
    private function calculateOverallQuality(array $chapters): ?float
    {
        if (empty($chapters)) return null;

        $scores = [];
        foreach ($chapters as $ch) {
            if (!array_key_exists('quality_score', $ch) || $ch['quality_score'] === null || $ch['quality_score'] === '') {
                continue;
            }
            if (!is_numeric($ch['quality_score'])) continue;
            $scores[] = (float)$ch['quality_score'];
        }

        return $scores === [] ? null : array_sum($scores) / count($scores);
    }

    private function calculateStructureScore(array $chapters): ?float
    {
        if (empty($chapters)) return null;

        $contents = $this->fetchChapterContents($chapters);
        $total = 0;
        $n = 0;
        foreach ($chapters as $ch) {
            $content = trim($contents[(int)($ch['id'] ?? 0)] ?? '');
            if ($content === '') continue;
            $n++;
            $len = mb_strlen($content, 'UTF-8');
            $paras = preg_split('/\n\s*\n/u', $content) ?: [];
            $paras = array_values(array_filter($paras, static fn($p) => trim((string)$p) !== ''));
            $hasOpening = $len > 100;
            $hasEnding = DialogueText::endsSentence($content);
            $hasParas = count($paras) >= 3;
            $hasDialogue = DialogueText::passages($content) !== [];
            $total += ($hasOpening ? 30 : 0) + ($hasEnding ? 25 : 0) + ($hasParas ? 25 : 0) + ($hasDialogue ? 20 : 0);
        }

        return $n > 0 ? $total / $n : null;
    }
    
    private function calculateCharacterChangeFrequency(array $chapters): ?float
    {
        try {
            if (empty($chapters)) return null;
            
            // 获取最近章节的章节号范围
            $chapterNumbers = array_values(array_unique(array_filter(array_map('intval', array_column($chapters, 'chapter_number')), static fn($n) => $n > 0)));
            if ($chapterNumbers === []) return null;
            $minChapter = min($chapterNumbers);
            $maxChapter = max($chapterNumbers);
            
            // 查询角色卡片变更历史
            // character_card_history 没有 novel_id 列，须经 character_cards 归属到小说；
            // 卡片修订标记 _card_revision_v1 不是字段变更，与其它历史读取口径一致地排除。
            $history = DB::fetchAll(
                "SELECT h.card_id, h.chapter_number, h.field_name
                 FROM character_card_history h
                 INNER JOIN character_cards c ON c.id = h.card_id
                 WHERE c.novel_id = ? AND h.chapter_number BETWEEN ? AND ?
                   AND h.field_name <> '_card_revision_v1'
                 ORDER BY h.chapter_number ASC",
                [$this->novelId, $minChapter, $maxChapter]
            );
            
            if (empty($history)) {
                // 无变更记录 ≠ 角色稳定，只说明没有可观测数据
                return null;
            }
            
            // One chapter is one observed update, regardless of how many fields changed.
            $allowedChapters = array_fill_keys($chapterNumbers, true);
            $cardChanges = [];
            foreach ($history as $record) {
                $cardId = $record['card_id'];
                $changedChapter = (int)$record['chapter_number'];
                if (isset($allowedChapters[$changedChapter])) $cardChanges[$cardId][$changedChapter] = true;
            }
            
            $totalCards = count($cardChanges);
            if ($totalCards === 0) return null;
            
            $changedChapterCount = array_sum(array_map('count', $cardChanges));
            return $changedChapterCount / ($totalCards * count($chapterNumbers));
        } catch (\Throwable $e) {
            error_log('Character change frequency unavailable: ' . $e->getMessage());
            return null;
        }
    }

    private function calculateDescriptionRichness(array $chapters): ?float
    {
        if (empty($chapters)) return null;

        $contents = $this->fetchChapterContents($chapters);
        $totalScore = 0;
        $n = 0;
        foreach ($chapters as $ch) {
            $content = $contents[(int)($ch['id'] ?? 0)] ?? '';
            if (empty($content)) continue;
            
            $wordCount = mb_strlen(preg_replace('/\s+/', '', $content), 'UTF-8');
            if ($wordCount === 0) continue;
            
            $descPatterns = [
                '美丽|漂亮|英俊|丑陋|高大|矮小|强壮|虚弱|年轻|年老',
                '明亮|黑暗|温暖|寒冷|炎热|凉爽|干燥|潮湿',
                '安静|喧闹|热闹|冷清|繁华|荒凉|拥挤|空旷',
                '极其|非常|十分|格外|特别|尤其|相当|比较|稍微|略微',
                '迅速|缓慢|轻轻|重重|悄悄|默默|静静|慢慢',
                '看见|听到|闻到|尝到|摸到|感觉|察觉|发现',
                '红色|蓝色|绿色|黄色|白色|黑色|金色|银色',
                '声音|光线|气味|味道|触感|温度|湿度',
                '微笑|大笑|哭泣|愤怒|悲伤|高兴|惊讶|恐惧',
            ];
            
            $descWords = 0;
            foreach ($descPatterns as $pattern) {
                $descWords += preg_match_all('/(' . $pattern . ')/u', $content);
            }
            
            $density = $descWords / ($wordCount / 100);
            $richness = min(1.0, max(0.0, ($density - 2) / 13));
            
            $totalScore += $richness;
            $n++;
        }
        
        return $n > 0 ? $totalScore / $n : null;
    }

    /**
     * 剧情连贯性：取近章 Gate5（连贯性检查）均分，不再复用伏笔回收率。
     */
    private function calculatePlotCoherence(array $chapters): ?float
    {
        if (empty($chapters) || count($chapters) < 2) {
            return null;
        }

        $scores = [];
        foreach ($chapters as $ch) {
            $raw = $ch['gate_results'] ?? null;
            $decoded = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : null);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $gate) {
                if (!is_array($gate)) continue;
                $name = (string)($gate['name'] ?? '');
                if ($name === '' || !str_contains($name, '连贯')) continue;
                if (!isset($gate['score']) || !is_numeric($gate['score'])) continue;
                $scores[] = (float)$gate['score'];
                break;
            }
        }

        return $scores === [] ? null : (array_sum($scores) / count($scores)) / 100.0;
    }
    
    private function calculateCoolPointEffectiveness(array $chapters): ?float
    {
        try {
            // memory_atoms 无 chapter_number 列，章节归属列是 source_chapter；
            // 边界写法与 AtomRepo 一致：NULL 视为无章节归属的历史原子一并纳入。
            $coolPoints = DB::fetchAll(
                'SELECT * FROM memory_atoms
                 WHERE novel_id = ? AND atom_type = "cool_point"
                   AND (source_chapter IS NULL OR source_chapter < ?)
                 ORDER BY COALESCE(source_chapter, 0) DESC, id DESC LIMIT 20',
                [$this->novelId, $this->currentChapterNumber ?: PHP_INT_MAX]
            );
            
            if (empty($coolPoints)) return null;
            
            $effective = 0;
            foreach ($coolPoints as $cp) {
                $metadata = json_decode($cp['metadata'] ?? '{}', true);
                if (!empty($metadata['cool_type']) && isset(\COOL_POINT_TYPES[$metadata['cool_type']])) {
                    $effective++;
                }
            }
            
            return count($coolPoints) > 0 ? $effective / count($coolPoints) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function calculateWordCountAccuracy(array $chapters): ?float
    {
        if (empty($chapters)) return null;
        
        try {
            $targetWords = DB::fetch(
                'SELECT chapter_words FROM novels WHERE id = ?',
                [$this->novelId]
            );
            
            $target = (int)($targetWords['chapter_words'] ?? 0);
            if ($target <= 0) return null;

            if (!function_exists('calculateDynamicTolerance')) {
                require_once dirname(__DIR__) . '/prompt.php';
            }
            $tol = calculateDynamicTolerance($target, $this->novelId);
            $tolerance = (int)($tol['tolerance'] ?? 0);
            if ($tolerance <= 0) {
                $tolerance = (int)max(100, min(500, (int)($target * 0.1)));
            }
            
            $accurate = 0;
            $counted = 0;
            foreach ($chapters as $ch) {
                $actual = (int)($ch['words'] ?? 0);
                if ($actual <= 0) continue;
                $counted++;
                if (abs($actual - $target) <= $tolerance) {
                    $accurate++;
                }
            }
            
            return $counted > 0 ? $accurate / $counted : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function calculateForeshadowingUsage(): ?float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total, 
                        SUM(CASE WHEN resolved_chapter < ? THEN 1 ELSE 0 END) as resolved
                 FROM foreshadowing_items
                 WHERE novel_id = ? AND planted_chapter < ?',
                [$this->currentChapterNumber ?: PHP_INT_MAX, $this->novelId, $this->currentChapterNumber ?: PHP_INT_MAX]
            );
            
            $total = (int)($stats['total'] ?? 0);
            if ($total <= 0) return null;
            return (int)($stats['resolved'] ?? 0) / $total;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function calculateRiskScore(array $issue): float
    {
        $severityWeights = [
            'critical' => 1.0,
            'high' => 0.8,
            'medium' => 0.5,
            'low' => 0.2,
        ];
        
        $weight = $severityWeights[$issue['severity']] ?? 0.5;
        $deviation = abs($issue['metric'] - $issue['threshold']) / max(1, $issue['threshold']);
        
        return min(1.0, $weight * (1 + $deviation));
    }
    
    private function assessImpact(array $issue): string
    {
        $impacts = [
            'quality_decline' => '读者流失风险',
            'character_inconsistency' => '角色崩坏风险',
            'plot_incoherence' => '剧情混乱风险',
            'weak_coolpoint' => '读者体验下降',
            'poor_description' => '阅读体验下降',
            'word_count_issue' => '成本控制问题',
            'unused_foreshadowing' => '剧情完整性问题',
        ];
        
        return $impacts[$issue['type']] ?? '未知影响';
    }
    
    private function assessUrgency(array $issue): string
    {
        if ($issue['severity'] === 'critical' || $issue['severity'] === 'high') {
            return 'immediate';
        } elseif ($issue['severity'] === 'medium') {
            return 'within_24h';
        } else {
            return 'within_week';
        }
    }
}
