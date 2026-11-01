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
 * - 集成 WritingExpertise 实现专家知识注入
 * （TrendPredictor 由 AgentCoordinator / 独立调用路径使用，本 Agent 不再空挂实例）
 *
 * @package NovelWritingSystem
 * @version 1.1.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseAgent.php';
require_once __DIR__ . '/../config_center.php';

class WritingStrategyAgent extends BaseAgent
{
    /** @var string 小说题材 */
    private $genre;

    /** @var int 当前已写章节数 */
    private $currentChapter;

    /** @var int 目标章节数 */
    private $targetChapters;

    /** @var AdaptiveDecisionEngine|null 自适应决策引擎 */
    private ?AdaptiveDecisionEngine $adaptiveEngine = null;

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

    public function setCurrentChapterNumber(int $chapterNumber): void
    {
        parent::setCurrentChapterNumber($chapterNumber);
        if ($chapterNumber > 0) {
            $this->currentChapter = $chapterNumber;
            $this->adaptiveEngine = new AdaptiveDecisionEngine($this->novelId, $chapterNumber);
        }
    }

    /**
     * 初始化智能引擎
     */
    private function initEngines(): void
    {
        try {
            require_once __DIR__ . '/AdaptiveDecisionEngine.php';
            $this->adaptiveEngine = new AdaptiveDecisionEngine($this->novelId);
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
            $missingFields = implode(', ', $validation['missing']);
            // 审计修复 M8（2026-09-27）：校验失败原先静默返回——结果缺少
            // decisions 键、不抛异常、无任何日志，collectAllDirectives 静默
            // 跳过，整条策略决策链无声失效且无迹可查。主路径 write_engine
            // 传入全部必需字段；任何其他调用方漏传即整链失效。至少留下
            // 排查线索（仅记录上下文键名，不含内容）。
            error_log(sprintf(
                'WritingStrategyAgent decide skipped: missing context fields [%s]; provided keys=%s',
                $missingFields,
                json_encode(array_keys($context), JSON_UNESCAPED_UNICODE)
            ));
            return [
                'success' => false,
                'error' => '缺少必需字段: ' . $missingFields,
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

        // 6.5 专家知识注入（审计修复 High-7）：
        // 原实现 AdaptiveDecisionEngine::generateExpertAdvice 零调用方，
        // WritingExpertise 约 200 行 KNOWLEDGE_BASE 永不生效（空架子）。
        // 此处对每条预测调用 generateExpertAdvice，把诊断/解决方案汇成一条
        // strategy 指令（expert_advice），经 execute() → writeDirective →
        // AgentDirectives → ChapterPromptBuilder 进入下章写作 prompt，让专家
        // 知识真正影响创作。失败静默，不影响主决策流程。
        $decisions['expert_advice'] = $this->buildExpertAdviceDirective($predictions, $factors);

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
     * 构建专家知识指令（审计修复 High-7）
     *
     * 对每条趋势预测调用 AdaptiveDecisionEngine::generateExpertAdvice，
     * 把 WritingExpertise::KNOWLEDGE_BASE 中的诊断/解决方案汇总成一条
     * 可写入 AgentDirectives 的 strategy 指令。
     *
     * @param array $predictions predictIssues() 返回的预测列表
     * @param array $factors 决策因素（含 genre 等，作为 expert advice 上下文）
     * @return array|null 决策条目 {action, value, reason, directive_text}；无预测/无命中时返回 null
     */
    private function buildExpertAdviceDirective(array $predictions, array $factors): ?array
    {
        if (empty($predictions) || !$this->adaptiveEngine) {
            return null;
        }

        $baseContext = ['genre' => $this->genre];
        $segments = [];
        $hitCount = 0;
        $genreAdvice = null;

        foreach ($predictions as $pred) {
            $type = $pred['type'] ?? '';
            if ($type === '') continue;

            // 审计修复（2026-08-16 P1，C2）：透传预测器产出的症状短语（symptoms），
            // 让 WritingExpertise::matchSubtype 对症命中子类型。此前 context 只有
            // genre，matched_subtype 恒 null，恒走「所有子类型打平取前 3 条」，
            // 5 个问题域的专家知识实际只有第一个子类型的建议被使用。
            $context = $baseContext;
            if (!empty($pred['symptoms']) && is_array($pred['symptoms'])) {
                $context['symptoms'] = $pred['symptoms'];
            }

            try {
                $advice = $this->adaptiveEngine->generateExpertAdvice($type, $context);
            } catch (\Throwable $e) {
                error_log("buildExpertAdviceDirective[{$type}] failed: " . $e->getMessage());
                continue;
            }

            if (empty($advice['found'])) continue;
            $hitCount++;

            // P1：收集题材建议（getAdvice 已返回 genre_advice 但此前无消费方）
            if ($genreAdvice === null && !empty($advice['genre_advice']) && is_array($advice['genre_advice'])) {
                $genreAdvice = $advice['genre_advice'];
            }

            // 优先采用 matched_solutions（基于症状匹配的子类型方案），否则取所有子类型方案的前几条
            $solutions = !empty($advice['matched_solutions'])
                ? $advice['matched_solutions']
                : (function () use ($advice): array {
                    $all = [];
                    foreach ($advice['solutions'] ?? [] as $list) {
                        foreach ($list as $s) { $all[] = $s; }
                    }
                    return $all;
                })();

            // 每类问题最多取 3 条方案，避免指令过长撑爆 prompt
            $solutions = array_slice($solutions, 0, 3);
            if (empty($solutions)) continue;

            // P1：对症命中子类型时，用其诊断短语作标签（比原始 issue_type 更可读）
            $label = $advice['issue_type'] ?? $type;
            if (!empty($advice['matched_subtype'])) {
                foreach ($advice['diagnoses'] ?? [] as $d) {
                    if (($d['type'] ?? '') === $advice['matched_subtype']) {
                        $label = $d['diagnosis'] ?? $label;
                        break;
                    }
                }
            }

            $segments[] = sprintf(
                "【%s】%s：%s",
                $pred['prediction'] ?? $type,
                $label,
                implode('；', $solutions)
            );
        }

        if (empty($segments)) {
            return null;
        }

        // 审计修复（2026-08-16 P1，C2）：题材建议一并注入。
        // GENRE_ADVICE 由 getAdvice 返回但此前无任何消费方（注入链路断裂），
        // focus/pitfalls 沦为死数据；取前 3 项避免指令过长。
        if (is_array($genreAdvice)) {
            $focus = implode('、', array_slice($genreAdvice['focus'] ?? [], 0, 3));
            $pitfalls = implode('、', array_slice($genreAdvice['common_pitfalls'] ?? [], 0, 3));
            if ($focus !== '' || $pitfalls !== '') {
                $segments[] = "【题材要点】重点打磨：{$focus}；避坑：{$pitfalls}";
            }
        }

        $directiveText = "专家写作建议（基于趋势预测，请在本章创作中重点参照）：" . implode(' ', $segments);

        return [
            'action' => 'inject_expert_advice',
            'value' => $hitCount,
            'reason' => sprintf('基于 %d 条趋势预测注入专家知识', $hitCount),
            'directive_text' => $directiveText,
        ];
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
            $stats = AgentDirectives::getOutcomeStats($this->novelId, $this->currentChapter);

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
            'expert_advice' => null, // High-7: 由 decide() 注入，execute() 落库
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
        } elseif (($factors['recent_word_accuracy'] ?? 0) >= 0.9) {
            // 审计修复（2026-08-17 写作逻辑 C2）：容差可回调，禁止单边爬升。
            // 近章字数稳定落在承诺区间（准确率≥90%）且当前比例高于下限时逐级
            // 收紧（−3～5 个百分点，下限 5%），避免从 10% 放宽到 20% 后永不回落。
            $currentPct = (int)ConfigCenter::getNovel($this->novelId, 'ws_dynamic_tolerance_ratio', 10, 'int');
            if ($currentPct > 5) {
                $stepPct = $currentPct >= 12 ? 5 : 3;
                $decisions['word_count_adjustment'] = [
                    'action' => 'decrease_tolerance',
                    // 增量语义：executeWordCountAdjustment 按 decrease 方向取负
                    'value' => round($stepPct / 100, 3),
                    'reason' => '近章字数稳定落在承诺区间，逐级回调容差（下限5%）',
                ];
                $decisions['reasoning'][] = "字数准确率≥90%，容差从{$currentPct}%收紧{$stepPct}个百分点";
            }
        }

        foreach ($analysis['opportunities'] as $opp) {
            if (strpos($opp, '爽点密度可提升') !== false) {
                $targetDensity = $this->getTargetCoolPointDensity();
                // 审计修复 M6（2026-09-27）：消除密度目标单向棘轮。原公式两个
                // 分支都在上调（历史效果差时 densityFactor=0.8 仍得 ×1.16），
                // ws_cool_point_density_target 只会单调爬升至 1.0，并同步把呼吸
                // 章间隔（maxBreatherRate = 1 - target）拉到最大，"去同构"的
                // 呼吸章设计被架空。历史爽点指令有效率 <40% 时继续发提高指令
                // 无效——本次保持目标不变，待指令效果恢复后再调。
                $effectivenessRate = (float)($directiveEffectiveness['by_type']['quality']['effectiveness_rate'] ?? 0.5);
                if ($effectivenessRate < 0.4) {
                    $decisions['reasoning'][] = sprintf(
                        '当前爽点密度不足，但历史爽点指令有效率仅%.0f%%（<40%%），本次保持密度目标%.2f不变，避免无效上调架空呼吸章设计',
                        $effectivenessRate * 100,
                        $targetDensity
                    );
                    break;
                }
                $decisions['coolpoint_density_adjustment'] = [
                    'action' => 'increase_density',
                    // 占比口径上限 1.0；与 ws_cool_point_density_target / PID 对齐
                    'value' => round(min(1.0, $targetDensity * 1.24), 2),
                    'reason' => '爽点密度低于目标,建议提升20%',
                ];
                $decisions['reasoning'][] = '当前爽点密度不足，需要增强';
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

        // High-7: 专家知识指令落地为 strategy directive，供 ChapterPromptBuilder 读取
        if (!empty($decisions['expert_advice'])) {
            $results['expert_advice'] = $this->executeExpertAdvice($decisions['expert_advice']);
        }

        return $results;
    }
    
    /**
     * 执行字数调整
     */
    private function executeWordCountAdjustment(array $decision): array
    {
        try {
            // 审计修复（2026-08-17 写作逻辑 C2）：与质量 Agent 共用容差旋钮的互斥守卫。
            // 同一写作周期内已有容差写入（含质量 Agent 的收紧）则跳过并打日志，
            // 避免两个 Agent 同章互相覆盖。
            if ($this->toleranceWriteAlreadyDone($this->novelId)) {
                $this->logAction($this->novelId, 'adjust_word_tolerance', 'skipped', [
                    'reason' => '本写作周期已有容差写入（策略/质量互斥），跳过',
                    'decision' => $decision,
                ]);
                return [
                    'action'  => 'adjust_word_tolerance',
                    'status'  => 'skipped',
                    'message' => '本写作周期已有容差写入（策略/质量互斥），跳过',
                ];
            }
            // 审计修复（2026-08-17 P3）修复3：increase_tolerance 方向相反。原实现把
            // value×100 当最终容差绝对值写入 ws_dynamic_tolerance_ratio：factor=0.5 时
            // value=0.075 → 写 8%，比默认 10% 更紧且走"收紧"文案——与决策语义相反。
            // 改为增量语义：新容差 = 当前生效容差 + value×100，再 clamp 到 [5, 20]。
            // 当前生效容差默认 10，与 prompt.php / quality/Gates.php 的
            // getSystemSetting('ws_dynamic_tolerance_ratio', 10) 同源。
            // 审计修复（2026-08-17 写作逻辑 C2）：decrease_tolerance 取负增量（回调方向）。
            $rawRatio = (float)($decision['value'] ?? 0.10);
            $isDecrease = ($decision['action'] ?? '') === 'decrease_tolerance';
            $incrementPct = (int)round($rawRatio * 100);
            if ($incrementPct <= 0) {
                $incrementPct = $isDecrease ? 3 : (($decision['action'] ?? '') === 'increase_tolerance' ? 15 : 8);
            }
            $incrementPct = $isDecrease ? -$incrementPct : $incrementPct;
            $currentPct = (int)ConfigCenter::getNovel($this->novelId, 'ws_dynamic_tolerance_ratio', 10, 'int');
            $ratioPct = max(5, min(20, $currentPct + $incrementPct));

            if (!ConfigCenter::setNovel(
                $this->novelId,
                'ws_dynamic_tolerance_ratio',
                $ratioPct,
                'int'
            )) {
                throw new RuntimeException('小说级字数容差配置保存失败');
            }

            $this->logAction($this->novelId, 'adjust_word_tolerance', 'success', [
                'new_value' => $decision['value'],
                'old_ratio_pct' => $currentPct,
                'ratio_pct' => $ratioPct,
            ]);

            // 文案按新旧容差相对方向选择：ratioPct > currentPct 为放宽；
            // 相等（增量被 clamp 吃掉）时用放宽文案，更小为收紧
            $directive = $ratioPct > $currentPct
                ? "本章字数要求放宽：允许±{$ratioPct}%的字数波动，内容完整性优先于精确字数。宁可多写保证高潮段完整性，也不要为了凑字数硬删内容。"
                : ($ratioPct < $currentPct
                    ? "本章字数要求收紧：严格控制在目标字数±{$ratioPct}%以内。开头快速入戏，中间不拖沓，80%字数时必须进入收尾。宁可精简描写也要控制总字数。"
                    : "本章字数要求放宽：允许±{$ratioPct}%的字数波动，内容完整性优先于精确字数。宁可多写保证高潮段完整性，也不要为了凑字数硬删内容。");

            $this->writeDirective('strategy', $directive);

            return [
                'action' => 'adjust_word_tolerance',
                'status' => 'success',
                'message' => "字数策略已写入小说配置（±{$ratioPct}%）并注入指令",
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
            // 审计修复 P2-12（2026-07-12）：改为小说级配置，避免污染其他小说
            // 旧实现：ConfigCenter::get/set 全局 system_settings，影响所有小说
            // 审计修复（2026-08-09 R7-4）：默认与 schedule/rhythm/config_constants 统一为 0.88（有爽点章占比）
            $currentDensity = ConfigCenter::getNovel($this->novelId, 'ws_cool_point_density_target', 0.88, 'float');
            $newDensity = $decision['value'];

            if (!ConfigCenter::setNovel(
                $this->novelId,
                'ws_cool_point_density_target',
                $newDensity,
                'float'
            )) {
                throw new RuntimeException('小说级爽点密度配置保存失败');
            }
            
            $this->logAction($this->novelId, 'adjust_coolpoint_density', 'success', [
                'old_value' => $currentDensity,
                'new_value' => $newDensity,
            ]);

            $direction = $newDensity > $currentDensity ? '提升' : '降低';
            $pctChange = $currentDensity > 0 ? round(($newDensity - $currentDensity) / $currentDensity * 100) : 0;
            $this->writeDirective('strategy',
                "爽点密度调整：目标从{$currentDensity}调整至{$newDensity}（{$direction}{$pctChange}%）。" .
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
                'steady' => '本章保持稳定节奏：按用户消息中的四段式比例推进（勿自拟固定百分比）。对话与动作交替，张弛有度。',
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
                'SELECT COALESCE(MAX(CASE WHEN status = "completed" THEN chapter_number END), 0) + 1 as next_chapter
                 FROM chapters WHERE novel_id = ?',
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
            // 修复：AVG 在 LIMIT 之前聚合导致统计全历史平均分，
            // 改为子查询先取近 10 章再聚合（与 AgentDirectives::recordOutcomes 同模式）（2026-07-22）
            $score = DB::fetch(
                'SELECT AVG(t.q) as avg FROM (
                     SELECT quality_score as q FROM chapters
                     WHERE novel_id = ? AND quality_score IS NOT NULL AND status = "completed" AND chapter_number < ?
                     ORDER BY chapter_number DESC LIMIT 10
                 ) t',
                [$this->novelId, $this->currentChapter]
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
                 WHERE c.novel_id = ? AND c.status = "completed" AND c.chapter_number < ?
                 ORDER BY c.chapter_number DESC LIMIT 10',
                [$this->novelId, $this->currentChapter]
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
     * 获取近期爽点密度（有爽点章占比，优先 actual_cool_point_types）
     * 审计修复（2026-08-09 R8-2）：与 PID/TrendPredictor/ws_cool_point_density_target 同量纲。
     */
    private function getRecentCoolPointDensity(): float
    {
        try {
            $from = max(1, $this->currentChapter - 20);
            $rows = DB::fetchAll(
                'SELECT cool_point_type, actual_cool_point_types FROM chapters
                 WHERE novel_id = ? AND status = "completed"
                   AND chapter_number BETWEEN ? AND ?
                 ORDER BY chapter_number DESC LIMIT 20',
                [$this->novelId, $from, max(0, $this->currentChapter - 1)]
            );
            if (empty($rows)) return 0.0;
            $with = 0;
            foreach ($rows as $r) {
                $actual = json_decode((string)($r['actual_cool_point_types'] ?? ''), true);
                if (is_array($actual) && !empty($actual)) {
                    $with++;
                } elseif (!empty($r['cool_point_type'])) {
                    $with++;
                }
            }
            return $with / count($rows);
        } catch (\Throwable $e) {
            return 0.5;
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
                   AND chapter_number >= ? AND chapter_number < ?',
                [$this->novelId, max(1, $this->currentChapter - 20), $this->currentChapter]
            );
            
            return $stats['total'] > 0 ? (int)$stats['completed'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    /**
     * 获取目标爽点密度（有爽点章占比）
     * 审计修复（2026-08-09 R8-2）：读小说级配置，不再用 genreConfigs 1.2/1.0 分裂量纲。
     */
    private function getTargetCoolPointDensity(): float
    {
        $target = (float)ConfigCenter::getNovel($this->novelId, 'ws_cool_point_density_target', 0.88, 'float');
        return max(0.3, min(1.0, $target));
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

    /**
     * 执行专家知识注入（审计修复 High-7）
     *
     * 把 buildExpertAdviceDirective 生成的 directive_text 写入 AgentDirectives
     * (type='strategy')，使 ChapterPromptBuilder 在下章 prompt 中读到专家建议。
     * applyRange=4（作用 4 章）、expiresInHours=48：专家建议偏具体操作，
     * 作用范围比通用策略短，避免过时建议长期生效。
     */
    private function executeExpertAdvice(array $decision): array
    {
        try {
            $directiveText = $decision['directive_text'] ?? '';
            $reason = $decision['reason'] ?? '';
            $value = $decision['value'] ?? 0;

            if ($directiveText === '') {
                return [
                    'action' => 'inject_expert_advice',
                    'status' => 'skipped',
                    'message' => '专家知识指令为空，跳过',
                ];
            }

            $this->logAction($this->novelId, 'expert_advice', 'success', [
                'reason' => $reason,
                'value' => $value,
                'directive_length' => mb_strlen($directiveText),
            ]);

            $this->writeDirective('strategy', $directiveText, 4, 48);

            return [
                'action' => 'inject_expert_advice',
                'status' => 'success',
                'message' => "专家知识指令已写入: {$reason}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'expert_advice', 'failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'action' => 'inject_expert_advice',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
}
