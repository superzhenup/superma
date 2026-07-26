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
        'coolpoint_density' => [
            'template' => '本章每{interval}字必须包含至少{min_count}个爽点元素，建议类型：{examples}',
            'default_params' => [
                'interval' => 1000,
                'min_count' => 3,
                'examples' => '打脸、越级战斗、获得宝物/传承/能力之一',
            ],
        ],
        'emotion_intensity' => [
            'template' => '本章情绪词密度不低于{ratio}，每{interval}字至少{count}个情绪词',
            'default_params' => [
                'ratio' => 0.02,
                'interval' => 1000,
                'count' => 20,
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
        $recentChapters = (isset($context['recent_chapters'])
            && is_array($context['recent_chapters'])
            && !empty($context['recent_chapters']))
            ? $context['recent_chapters']
            : $this->getRecentChapters(10);
        
        return [
            'overall_quality' => $this->calculateOverallQuality($recentChapters),
            'structure_score' => $this->calculateStructureScore($recentChapters),
            'character_consistency' => $this->calculateCharacterConsistency($recentChapters),
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
    private function identifyQualityIssues(array $metrics): array
    {
        $issues = [];
        
        // 问题1: 整体质量下降
        if ($metrics['overall_quality'] < $this->qualityThresholds['acceptable']) {
            $issues[] = [
                'type' => 'quality_decline',
                'severity' => 'high',
                'description' => "整体质量评分{$metrics['overall_quality']}分,低于可接受阈值",
                'metric' => $metrics['overall_quality'],
                'threshold' => $this->qualityThresholds['acceptable'],
            ];
        }
        
        // 问题2: 结构问题
        if ($metrics['structure_score'] < $this->qualityThresholds['good']) {
            $issues[] = [
                'type' => 'structure_issue',
                'severity' => 'medium',
                'description' => "章节结构评分{$metrics['structure_score']}分,需要优化",
                'metric' => $metrics['structure_score'],
                'threshold' => $this->qualityThresholds['good'],
            ];
        }
        
        // 问题3: 角色一致性下降
        if ($metrics['character_consistency'] < 0.8) {
            $issues[] = [
                'type' => 'character_inconsistency',
                'severity' => 'high',
                'description' => "角色一致性{$metrics['character_consistency']},存在OOC风险",
                'metric' => $metrics['character_consistency'],
                'threshold' => 0.8,
            ];
        }
        
        // 问题4: 描写贫乏
        if ($metrics['description_richness'] < 0.6) {
            $issues[] = [
                'type' => 'poor_description',
                'severity' => 'medium',
                'description' => "描写丰富度{$metrics['description_richness']},建议增强",
                'metric' => $metrics['description_richness'],
                'threshold' => 0.6,
            ];
        }
        
        // 问题5: 剧情不连贯
        if ($metrics['plot_coherence'] < 0.75) {
            $issues[] = [
                'type' => 'plot_incoherence',
                'severity' => 'high',
                'description' => "剧情连贯性{$metrics['plot_coherence']},存在逻辑问题",
                'metric' => $metrics['plot_coherence'],
                'threshold' => 0.75,
            ];
        }
        
        // 问题6: 爽点效果不佳
        if ($metrics['coolpoint_effectiveness'] < 0.7) {
            $issues[] = [
                'type' => 'weak_coolpoint',
                'severity' => 'medium',
                'description' => "爽点有效性{$metrics['coolpoint_effectiveness']},需要调整",
                'metric' => $metrics['coolpoint_effectiveness'],
                'threshold' => 0.7,
            ];
        }
        
        // 问题7: 字数控制不佳
        if ($metrics['word_count_accuracy'] < 0.8) {
            $issues[] = [
                'type' => 'word_count_issue',
                'severity' => 'medium',
                'description' => "字数准确率{$metrics['word_count_accuracy']},控制不稳定",
                'metric' => $metrics['word_count_accuracy'],
                'threshold' => 0.8,
            ];
        }
        
        // 问题8: 伏笔利用率低
        if ($metrics['foreshadowing_usage'] < 0.5) {
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

        if ($type === 'coolpoint_density') {
            $params['min_count'] = $severity < 40 ? 4 : ($severity < 60 ? 3 : 2);
        } elseif ($type === 'emotion_intensity') {
            $params['count'] = $severity < 40 ? 25 : ($severity < 60 ? 20 : 15);
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

    private function executeRecommendation(array $rec): array
    {
        try {
            switch ($rec['action']) {
                case 'enhance_quality_check':
                    // 审计修复 P2-12（2026-07-12）：移除 ConfigCenter::set('ws_quality_strictness', ...) 等
                    // 全局写入——这些 setting 没有任何 reader，且会污染其他小说。
                    // 改由 writeDirective()（已 novel-scoped）传递本章指令。
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章启用严格质量检查。触发原因：整体质量评分{$rec['issue']['metric']}分，低于可接受阈值{$rec['issue']['threshold']}分。重点检查：结构完整性、角色一致性、剧情连贯性。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已启用严格质量检查'];

                case 'strengthen_character_tracking':
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章加强角色一致性检查。触发原因：角色一致性{$rec['issue']['metric']}，存在OOC风险。重点检查：角色对话风格、行为逻辑、性格特征是否与设定一致。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已加强角色追踪'];

                case 'adjust_coolpoint_strategy':
                    // 审计修复 P2-12：cool_point_intensity 全局 setting 无 reader，移除全局写入；
                    // 本章指令通过 writeDirective 下发，不污染其他小说。
                    $currentIntensity = 1.0;
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章增加爽点强度，从{$currentIntensity}提升至" . ($currentIntensity + 0.2) . "。触发原因：爽点有效性{$rec['issue']['metric']}，低于0.7阈值。重点：增强冲突张力、加快剧情节奏、强化情感冲击。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已调整爽点强度'];

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
                    $currentTolerance = 0.1;
                    $newTolerance = max(0.03, $currentTolerance - 0.05);
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $quantitative = $this->generateQuantifiableDirective($rec['action'], $rec);
                    $directive = $quantitative ?? "本章收紧字数容差，从{$currentTolerance}降至{$newTolerance}。触发原因：字数偏差{$rec['issue']['metric']}，超出可接受范围。重点：控制正文长度在目标字数±" . round($newTolerance * 100) . "%以内。";
                    $this->writeDirective('quality', $directive);
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已收紧字数容差'];

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
                'SELECT id, novel_id, chapter_number, title, status, words, quality_score, critic_scores, ai_pattern_issues
                 FROM chapters
                 WHERE novel_id = ? AND status = "completed"
                 ORDER BY chapter_number DESC LIMIT ?',
                [$this->novelId, $limit]
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
    
    private function calculateOverallQuality(array $chapters): float
    {
        if (empty($chapters)) return 100.0;
        
        $total = 0;
        foreach ($chapters as $ch) {
            $total += (float)($ch['quality_score'] ?? 75);
        }
        
        return $total / count($chapters);
    }
    
    private function calculateStructureScore(array $chapters): float
    {
        // 简化的结构评分
        if (empty($chapters)) return 75.0;

        $contents = $this->fetchChapterContents($chapters);
        $total = 0;
        foreach ($chapters as $ch) {
            // 检查章节是否有合理的开头和结尾
            $content = $contents[(int)($ch['id'] ?? 0)] ?? '';
            $hasOpening = mb_strlen($content, 'UTF-8') > 100;
            $hasEnding = preg_match('/[。！？][\s]*$/u', $content);

            $score = ($hasOpening ? 50 : 0) + ($hasEnding ? 50 : 0);
            $total += $score;
        }

        return count($chapters) > 0 ? $total / count($chapters) : 75.0;
    }
    
    private function calculateCharacterConsistency(array $chapters): float
    {
        try {
            if (empty($chapters)) return 1.0;
            
            // 获取最近章节的章节号范围
            $chapterNumbers = array_column($chapters, 'chapter_number');
            $minChapter = min($chapterNumbers);
            $maxChapter = max($chapterNumbers);
            
            // 查询角色卡片变更历史
            $history = DB::fetchAll(
                'SELECT card_id, chapter_number, field_name 
                 FROM character_card_history 
                 WHERE novel_id = ? AND chapter_number BETWEEN ? AND ?
                 ORDER BY chapter_number ASC',
                [$this->novelId, $minChapter, $maxChapter]
            );
            
            if (empty($history)) {
                // 没有变更记录，可能是新小说或角色状态稳定
                return 1.0;
            }
            
            // 统计每个角色的变更次数
            $cardChanges = [];
            foreach ($history as $record) {
                $cardId = $record['card_id'];
                if (!isset($cardChanges[$cardId])) {
                    $cardChanges[$cardId] = 0;
                }
                $cardChanges[$cardId]++;
            }
            
            // 计算变更频率得分
            // 理想情况：每个角色在10章内变更1-2次（状态自然变化）
            // 过于频繁的变更（每章都变）可能表示一致性有问题
            $totalCards = count($cardChanges);
            if ($totalCards === 0) return 1.0;
            
            $chapterCount = count($chapters);
            $frequentChanges = 0;
            
            foreach ($cardChanges as $changeCount) {
                // 如果变更次数超过章节数的一半，认为过于频繁
                if ($changeCount > $chapterCount / 2) {
                    $frequentChanges++;
                }
            }
            
            // 计算一致性得分：频繁变更的角色比例越低，得分越高
            $consistency = 1.0 - ($frequentChanges / $totalCards);
            
            // 确保得分在合理范围内
            return max(0.0, min(1.0, $consistency));
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'character_card_history')
                || str_contains($e->getMessage(), "doesn't exist")
                || str_contains($e->getMessage(), 'Unknown column')) {
                error_log("character_card_history 表不存在或字段缺失，使用备用一致性检查: " . $e->getMessage());
                return $this->fallbackCharacterConsistencyCheck($chapters);
            }
            error_log('Character consistency check failed: ' . $e->getMessage());
            return 0.8;
        }
    }

    private function fallbackCharacterConsistencyCheck(array $chapters): float
    {
        if (empty($chapters) || count($chapters) < 2) {
            return 1.0;
        }

        try {
            $engine = new MemoryEngine($this->novelId);
            $cards = $engine->cards()->listAll();

            if (empty($cards)) {
                return 1.0;
            }

            $cardNames = array_column($cards, 'name');
            $chapterCount = count($chapters);
            $contents = $this->fetchChapterContents($chapters);

            $presenceCount = 0;
            foreach ($chapters as $ch) {
                $content = $contents[(int)($ch['id'] ?? 0)] ?? '';
                if (empty($content)) continue;

                foreach ($cardNames as $name) {
                    if (mb_strpos($content, $name) !== false) {
                        $presenceCount++;
                        break;
                    }
                }
            }

            // 角色出场是正面信号：出场率越高，一致性越好
            $consistency = min($presenceCount, $chapterCount) / $chapterCount;
            return max(0.5, min(1.0, $consistency));
        } catch (\Throwable $e) {
            error_log('Fallback character consistency check failed: ' . $e->getMessage());
            return 0.8;
        }
    }
    
    private function calculateDescriptionRichness(array $chapters): float
    {
        if (empty($chapters)) return 0.65;

        $contents = $this->fetchChapterContents($chapters);
        $totalScore = 0;
        foreach ($chapters as $ch) {
            $content = $contents[(int)($ch['id'] ?? 0)] ?? '';
            if (empty($content)) continue;
            
            // 统计描写性词汇比例
            $wordCount = mb_strlen(preg_replace('/\s+/', '', $content), 'UTF-8');
            if ($wordCount === 0) continue;
            
            // 扩展的描写性词汇列表
            // 审计修复（2026-07-19 H-中7）：移除虚词「着/了/过」，中文每百字出现数次
            // 导致描写密度饱和失去区分度。改为仅保留有实际描写语义的模式。
            $descPatterns = [
                // 形容词
                '美丽|漂亮|英俊|丑陋|高大|矮小|强壮|虚弱|年轻|年老',
                '明亮|黑暗|温暖|寒冷|炎热|凉爽|干燥|潮湿',
                '安静|喧闹|热闹|冷清|繁华|荒凉|拥挤|空旷',
                // 副词
                '极其|非常|十分|格外|特别|尤其|相当|比较|稍微|略微',
                '迅速|缓慢|轻轻|重重|悄悄|默默|静静|慢慢',
                // 感官词汇
                '看见|听到|闻到|尝到|摸到|感觉|察觉|发现',
                '红色|蓝色|绿色|黄色|白色|黑色|金色|银色',
                '声音|光线|气味|味道|触感|温度|湿度',
                // 状态词汇（去掉虚词 着/了/过/正在/已经/曾经/将会/可能/似乎/好像）
                '微笑|大笑|哭泣|愤怒|悲伤|高兴|惊讶|恐惧',
            ];
            
            $descWords = 0;
            foreach ($descPatterns as $pattern) {
                $descWords += preg_match_all('/(' . $pattern . ')/u', $content);
            }
            
            // 计算描写密度：每100字有多少描写词汇
            $density = $descWords / ($wordCount / 100);
            
            // 将密度映射到0-1分数
            // 理想密度：每100字5-15个描写词汇
            $richness = min(1.0, max(0.0, ($density - 2) / 13));
            
            $totalScore += $richness;
        }
        
        return count($chapters) > 0 ? $totalScore / count($chapters) : 0.65;
    }
    
    private function calculatePlotCoherence(array $chapters): float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total, 
                        SUM(CASE WHEN resolved_chapter IS NOT NULL THEN 1 ELSE 0 END) as good 
                 FROM foreshadowing_items
                 WHERE novel_id = ?',
                [$this->novelId]
            );
            
            return $stats['total'] > 0 ? (int)$stats['good'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateCoolPointEffectiveness(array $chapters): float
    {
        try {
            $coolPoints = DB::fetchAll(
                'SELECT * FROM memory_atoms
                 WHERE novel_id = ? AND atom_type = "cool_point"
                 ORDER BY created_at DESC LIMIT 20',
                [$this->novelId]
            );
            
            if (empty($coolPoints)) return 1.0;
            
            $effective = 0;
            foreach ($coolPoints as $cp) {
                $metadata = json_decode($cp['metadata'] ?? '{}', true);
                // 检查是否有有效的爽点类型
                if (!empty($metadata['cool_type']) && isset(\COOL_POINT_TYPES[$metadata['cool_type']])) {
                    $effective++;
                }
            }
            
            return count($coolPoints) > 0 ? $effective / count($coolPoints) : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateWordCountAccuracy(array $chapters): float
    {
        if (empty($chapters)) return 1.0;
        
        try {
            $targetWords = DB::fetch(
                'SELECT chapter_words FROM novels WHERE id = ?',
                [$this->novelId]
            );
            
            $target = (int)($targetWords['chapter_words'] ?? 3000);
            $tolerance = $target * 0.1;
            
            $accurate = 0;
            foreach ($chapters as $ch) {
                $actual = (int)($ch['words'] ?? 0);
                if (abs($actual - $target) <= $tolerance) {
                    $accurate++;
                }
            }
            
            return count($chapters) > 0 ? $accurate / count($chapters) : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateForeshadowingUsage(): float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total, 
                        SUM(CASE WHEN resolved_chapter IS NOT NULL THEN 1 ELSE 0 END) as resolved 
                 FROM foreshadowing_items
                 WHERE novel_id = ?',
                [$this->novelId]
            );
            
            return $stats['total'] > 0 ? (int)$stats['resolved'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
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
