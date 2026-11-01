<?php
defined('APP_LOADED') or die('Direct access denied.');
require_once dirname(__DIR__) . '/quality/ChapterGenerationIntegrity.php';

/**
 * IterativeRefinementController — 迭代改进控制器
 *
 * 核心功能：
 *   - 管理多轮迭代改进流程
 *   - 控制迭代次数和提前终止条件
 *   - 协调各 Agent 的改进工作
 *   - 追踪迭代历史和效果评估
 *
 * 迭代流程：
 *   初始内容 → 质量评估 → 问题识别 → 生成建议 → 应用改进 → 效果验证
 *       ↑                                                                      ↓
 *       ←←←←←←←←←←←←←←←←←←←← 迭代循环 ←←←←←←←←←←←←←←←←←←←←←←←←←←←←←←←←
 *
 * 终止条件：
 *   - 达到最大迭代次数
 *   - 质量提升低于阈值（边际收益递减）
 *   - 质量分数达到目标值
 *   - 出现严重错误
 *
 * @package NovelWritingSystem
 * @version 1.1.0
 */
class IterativeRefinementController
{
    private int $novelId;
    private int $chapterId;

    private int $maxIterations;
    private ?int $frozenMaxIterations = null;
    private float $minImprovementThreshold;
    private float $targetScore;
    private float $qualityDeclineThreshold;

    private array $iterationHistory = [];
    private array $improvementTrend = [];
    private array $evaluationCache = [];
    // Keep the last literal model output separate from the controller's
    // historical "best/fallback content" result.  RewriteAgent uses this
    // diagnostic channel to freeze every actual generation attempt as a
    // Candidate before the canonical quality gate decides its disposition.
    private bool $generationAttempted = false;
    private ?string $rawGeneratedContent = null;
    private ?string $rawFinishReason = null;
    private ?string $rawGenerationError = null;
    /**
     * 审计修复 F07（2026-11-01）：每个被采用的改写稿按正文 sha256 绑定它自己那一次
     * 生成的完成证据。raw* 字段只描述末次尝试，不能拿来证明回选的历史最优稿。
     * @var array<string,array{finish_reason:?string,generation_error:?string}>
     */
    private array $candidateEvidence = [];
    private array $performanceMetrics = [
        'total_api_calls' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'evaluation_time_ms' => 0,
        'rewrite_time_ms' => 0,
    ];

    public function __construct(int $novelId, int $chapterId = 0, ?int $frozenMaxIterations = null)
    {
        $this->novelId = $novelId;
        $this->chapterId = $chapterId;

        $this->loadConfiguration($frozenMaxIterations);
    }

    /**
     * 加载配置参数
     */
    private function loadConfiguration(?int $frozenMaxIterations = null): void
    {
        // 审计修复（2026-08-17 P3）修复6：max_iterations 加下限 clamp。
        // 配置为 0/负数时 refine() 的 for 循环体一次不执行、返回 success=false，
        // 迭代改进静默失效。max(1, ...) 保证至少跑一轮。
        if ($frozenMaxIterations === null) {
            $this->maxIterations = max(1, (int)getSetting('iterative_refinement.max_iterations', 3, $this->novelId));
        } else {
            if ($frozenMaxIterations < 1 || $frozenMaxIterations > 5) {
                throw new InvalidArgumentException('Frozen rewrite limit must be between 1 and 5.');
            }
            $this->frozenMaxIterations = $frozenMaxIterations;
            $this->maxIterations = $frozenMaxIterations;
        }
        $this->minImprovementThreshold = (float)getSetting('iterative_refinement.min_improvement', 5.0, $this->novelId);
        $this->targetScore = (float)getSetting('iterative_refinement.target_score', 80.0, $this->novelId);
        $this->qualityDeclineThreshold = (float)getSetting('iterative_refinement.quality_decline_threshold', 3.0, $this->novelId);
    }

    /**
     * 执行迭代改进主流程
     *
     * @param string $initialContent 初始章节内容
     * @param array $chapterInfo 章节基本信息
     * @param array $context 完整上下文
     * @return array{
     *   success:bool,
     *   final_content:string,
     *   final_score:float,
     *   iterations_used:int,
     *   total_improvement:float,
     *   history:array,
     *   generation_attempted:bool,
     *   raw_content:?string
     * }
     */
    public function refine(string $initialContent, array $chapterInfo, array $context = []): array
    {
        $startTime = microtime(true);
        // 批次二（2026-08-16）：预算可配置化。原 300s 硬编码，慢模型对长章节做
        // 3 轮流式全章重写极易超时，中途放弃已完成轮次（best 回溯保内容但轮次浪费）。
        $timeBudget = max(60, (int)getSetting('iterative_refinement.time_budget', 300, $this->novelId));
        $currentContent = $initialContent;
        $bestContent = $currentContent;
        $bestScore = 0;

        $this->iterationHistory = [];
        $this->improvementTrend = [];
        $this->generationAttempted = false;
        $this->rawGeneratedContent = null;
        $this->rawFinishReason = null;
        $this->rawGenerationError = null;
        $this->candidateEvidence = [];

        $iterationResults = [];

        // C-2 修复（2026-07-25）：原代码 $currentScore 初始化为 0，循环内首次评估后才赋值。
        // 当首轮即达标时，$firstBefore 回退到 $bestScore（=首评估分），使 totalImprovement=0。
        // 改为：循环前显式评估初始内容，作为真实基线。
        $initialEvaluation = $this->evaluateQuality($initialContent, $chapterInfo, $context, 1);
        $initialScore = $initialEvaluation['overall_score'];
        $currentScore = $initialScore;
        $bestScore = $initialScore;

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $iterationStartTime = microtime(true);

            // W-10 修复：每轮迭代前检测取消标志，避免用户取消后仍继续烧 API
            if (file_exists(WriteEngine::cancelFlagPath($this->novelId))) {
                addLog($this->novelId, 'iterative_refine', '检测到取消标志，提前终止迭代');
                break;
            }

            if (microtime(true) - $startTime > $timeBudget) {
                addLog($this->novelId, 'iterative_refine', sprintf(
                    '迭代超时（%.0fs > %ds），提前终止',
                    microtime(true) - $startTime, $timeBudget
                ));
                break;
            }

            addLog($this->novelId, 'iterative_refine', sprintf(
                '第 %d/%d 轮迭代开始，当前进度：%.1f → 目标：%.1f',
                $iteration, $this->maxIterations, $currentScore, $this->targetScore
            ));

            // P2-2 修复（2026-08-09）：在重新评估前保存上一轮结束时的分数，
            // 用于 target_reached 条目计算 improvement。原代码无 $prevScore 追踪，
            // target_reached 条目缺 after_score/improvement 字段，
            // saveIterationHistory 的 M-6 回退逻辑无法准确反映首尾分数。
            $prevScore = $currentScore;

            // Step 1: 质量评估
            // C-2 修复：首轮复用循环前的初始评估，避免重复 AI 调用
            if ($iteration === 1) {
                $evaluation = $initialEvaluation;
            } else {
                $evaluation = $this->evaluateQuality($currentContent, $chapterInfo, $context, $iteration);
            }
            $currentScore = $evaluation['overall_score'];

            // H-7 修复：评估失败（五关异常）时跳过本轮改进趋势计算，避免假"巨大改进"
            $evalFailed = !empty($evaluation['eval_failed']);

            // 记录最佳内容
            if (!$evalFailed && $currentScore > $bestScore) {
                $bestScore = $currentScore;
                $bestContent = $currentContent;
            }

            // Step 2: 检查是否达到目标
            if (!$evalFailed && $currentScore >= $this->targetScore) {
                $iterationResults[] = [
                    'iteration' => $iteration,
                    'action' => 'target_reached',
                    'before_score' => $initialScore,
                    'score' => $currentScore,
                    // P2-2 修复：补齐 after_score / improvement，与其他条目结构一致。
                    // target_reached 不再应用改进，故 after_score = 当前分；
                    // improvement = 当前分相对上一轮结束分的增量（首轮即达标时为 0）。
                    'after_score' => $currentScore,
                    'improvement' => $currentScore - $prevScore,
                    'stop_reason' => '已达到目标分数',
                ];
                addLog($this->novelId, 'iterative_refine', sprintf(
                    '第 %d 轮迭代完成，质量分数 %.1f 达到目标 %.1f，停止迭代',
                    $iteration, $currentScore, $this->targetScore
                ));
                break;
            }

            // 评估失败时不进行改进，直接结束本轮
            if ($evalFailed) {
                $iterationResults[] = [
                    'iteration' => $iteration,
                    'action' => 'eval_failed_skip',
                    'before_score' => $currentScore,
                    'after_score' => $currentScore,
                    'improvement' => 0.0,
                    'stop_reason' => '评估失败，跳过改进',
                ];
                addLog($this->novelId, 'iterative_refine', '评估失败（五关检测异常），跳过本轮改进');
                break;
            }

            // Step 3: 问题识别
            $issues = $this->identifyIssues($currentContent, $chapterInfo, $evaluation);

            // Step 4: 生成改进建议
            $suggestions = $this->generateSuggestions($issues, $currentContent, $chapterInfo, $iteration);

            // Step 5: 应用改进（审计修复 H-中12：传入 $iteration 实参）
            $improvedContent = $this->applyImprovements($currentContent, $suggestions, $chapterInfo, $context, $iteration);

            // Step 6: 验证改进效果
            $newEvaluation = $this->evaluateQuality($improvedContent, $chapterInfo, $context, $iteration, true);
            $newScore = $newEvaluation['overall_score'];
            // H-7 修复 + 审计修复 M1（2026-09-27 对称化）：改进后评估失败（五关
            // 检测异常）时，与 pre-check 失败（上方 eval_failed_skip 分支）对称
            // 处理——本轮改进稿未经验证，不得把 currentContent/currentScore
            // 切到它。原实现仍切换（newScore=0 成为当前分），下一轮被
            // shouldTerminateEarly 误判"质量下降超过阈值"提前终止，且
            // after_score=0 污染迭代历史、误导统计分析。现在：按
            // "改进未验证"记录本轮（不保存未验证稿），内容与分数保持上轮，
            // 立即终止。
            $newEvalFailed = !empty($newEvaluation['eval_failed']);
            if ($newEvalFailed) {
                $iterationTime = (microtime(true) - $iterationStartTime) * 1000;
                $iterationResults[] = [
                    'iteration' => $iteration,
                    'action' => 'improvement_unverified_discarded',
                    'before_score' => $currentScore,
                    'after_score' => $currentScore,
                    'improvement' => 0.0,
                    'issues_addressed' => count($issues),
                    'suggestions_count' => count($suggestions),
                    'execution_time_ms' => $iterationTime,
                    'stop_reason' => '改进后评估失败，本轮改进未验证被丢弃',
                ];
                addLog($this->novelId, 'iterative_refine', '改进后评估失败（五关检测异常），丢弃未验证改进稿并终止本轮迭代');
                break;
            }
            $improvement = $newScore - $currentScore;

            // 更新最佳内容
            if ($newScore > $bestScore) {
                $bestScore = $newScore;
                $bestContent = $improvedContent;
            }

            $iterationTime = (microtime(true) - $iterationStartTime) * 1000;
            $iterationResults[] = [
                'iteration' => $iteration,
                'action' => 'improvement_applied',
                'before_score' => $currentScore,
                'after_score' => $newScore,
                'improvement' => $improvement,
                'issues_addressed' => count($issues),
                'suggestions_count' => count($suggestions),
                'execution_time_ms' => $iterationTime,
                'improved_content' => $improvedContent,
                'evaluation' => $evaluation,
                'new_evaluation' => $newEvaluation,
            ];

            $this->improvementTrend[] = $improvement;

            addLog($this->novelId, 'iterative_refine', sprintf(
                '第 %d 轮迭代完成：%.1f → %.1f（%+.1f），耗时 %.0fms',
                $iteration, $currentScore, $newScore, $improvement, $iterationTime
            ));

            // 批次二（2026-08-16）：采纳联动提前收割。
            // RewriteAgent 的采纳门槛是 min_gain（默认10分）：bestScore 相对初始分
            // 已赚到 ≥ 门槛后，继续迭代不会改变"被采纳"的结论，仅当本轮边际收益
            // 仍高（还可能冲更高分）时才值得继续。已达门槛 + 边际收益低于一轮改进
            // 阈值 → 立即收割，省一轮全章重写 AI 调用。
            $adoptionGain = (float)($context['adoption_gain'] ?? 0);
            if ($adoptionGain > 0
                && ($bestScore - $initialScore) >= $adoptionGain
                && $improvement < $this->minImprovementThreshold) {
                $iterationResults[count($iterationResults) - 1]['stop_reason'] = sprintf(
                    '已达采纳增益（%.1f≥%d）且边际收益低（%+.1f），提前收割',
                    $bestScore - $initialScore,
                    (int)$adoptionGain,
                    $improvement
                );
                addLog($this->novelId, 'iterative_refine', '已达采纳增益门槛，提前停止迭代');
                break;
            }

            // 检查是否应该提前终止
            if ($this->shouldTerminateEarly($iteration, $improvement, $newScore, $iterationResults)) {
                $iterationResults[count($iterationResults) - 1]['stop_reason'] = $this->getTerminationReason();
                break;
            }

            $currentContent = $improvedContent;
            $currentScore = $newScore;
        }

        $finalContent = $currentContent;
        $finalScore = $currentScore;

        if (count($iterationResults) > 1) {
            $afterScores = array_column($iterationResults, 'after_score');
            $maxAfterScore = !empty($afterScores) ? max($afterScores) : $finalScore;
            if ($maxAfterScore > $finalScore) {
                // H-6 修复（2026-07-25）：array_search 未命中返回 false，PHP 会把
                // $iterationResults[false] 当作 $iterationResults[0]，错误地把首轮结果当作最佳。
                // 改为严格 === false 检查，未命中时跳过回溯。
                $bestIdx = array_search($maxAfterScore, $afterScores);
                if ($bestIdx !== false) {
                    $bestResult = $iterationResults[$bestIdx] ?? null;
                    if ($bestResult && isset($bestResult['improved_content'])) {
                        $finalContent = $bestResult['improved_content'];
                        $finalScore = $maxAfterScore;
                        addLog($this->novelId, 'iterative_refine', sprintf(
                            '优化：回溯第%d轮迭代结果作为最终版本（%.1f > %.1f）',
                            ($bestIdx + 1), $maxAfterScore, $currentScore
                        ));
                    } elseif ($bestScore > $currentScore) {
                        $finalContent = $bestContent;
                        $finalScore = $bestScore;
                    }
                } elseif ($bestScore > $currentScore) {
                    $finalContent = $bestContent;
                    $finalScore = $bestScore;
                }
            }
        }

        if ($bestScore > $finalScore) {
            $finalContent = $bestContent;
            $finalScore = $bestScore;
        }

        if (empty($finalContent) && !empty($initialContent)) {
            $finalContent = $initialContent;
            // C-2 修复：回退到初始内容时，分数应为初始评估分而非最后一轮分
            $finalScore = $initialScore;
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        // C-2 修复（2026-07-25）：原 totalImprovement 在首轮达标时回退到 bestScore 自身，
        // 报告为 0；但实际从初始（0）到 bestScore 是真实提升。改为使用循环前评估的
        // $initialScore 作为基线，准确反映总改进量。
        $totalImprovement = $bestScore - $initialScore;

        $this->saveIterationHistory($chapterInfo, $iterationResults, $totalImprovement, $totalTime);

        return [
            'success' => count($iterationResults) > 0,
            'final_content' => $finalContent,
            'final_score' => $bestScore,
            'iterations_used' => count($iterationResults),
            'total_improvement' => $totalImprovement,
            'execution_time_ms' => $totalTime,
            'history' => $iterationResults,
            'reached_target' => $bestScore >= $this->targetScore,
            'performance_metrics' => $this->performanceMetrics,
            // final_content deliberately keeps the legacy best/fallback
            // semantics. raw_content is the last literal AI rewrite output,
            // including an empty/short/low-scoring attempt.
            'generation_attempted' => $this->generationAttempted,
            'raw_content' => $this->generationAttempted
                ? ($this->rawGeneratedContent ?? '')
                : null,
            'generation_integrity' => [
                'finish_reason' => $this->rawFinishReason,
                'generation_error' => $this->rawGenerationError,
            ],
            // Evidence of the generation that produced final_content itself (null = unknown).
            'final_content_integrity' => $this->finalContentIntegrity($finalContent, $initialContent),
        ];
    }

    /**
     * 审计修复 F07：final_content 的完成证据取自生成它的那一轮。
     * - 某轮采用的改写稿：返回该轮绑定的 finish_reason / generation_error；
     * - 未被改写的原稿：不是模型输出，没有生成完整性问题（调用方按同 hash 跳过提交）；
     * - 找不到来源（不应发生）：返回 null，调用方保留末次尝试的证据，失败关闭。
     *
     * @return array{finish_reason:?string,generation_error:?string}|null
     */
    private function finalContentIntegrity(string $finalContent, string $initialContent): ?array
    {
        $evidence = $this->candidateEvidence[hash('sha256', $finalContent)] ?? null;
        if ($evidence !== null) {
            return $evidence;
        }
        if (hash_equals(hash('sha256', $initialContent), hash('sha256', $finalContent))) {
            return ['finish_reason' => null, 'generation_error' => null];
        }
        return null;
    }

    /**
     * 质量评估 - 整合五关检测和 CriticAgent（带缓存优化）
     */
    private function evaluateQuality(string $content, array $chapterInfo, array $context, int $iteration, bool $isPostCheck = false): array
    {
        // 审计优化（2026-08-16 批次一）：M3-2 已统一 pre/post 评估公式（完整五关、
        // overall 恒等于 gateScore、critic 参与只取决于 $iteration 而非 $isPostCheck），
        // 缓存键不再按 $isPostCheck 分裂——原 _quick/_full 双键导致第 N 轮 post-check
        // 与第 N+1 轮 pre-check 对同一正文重复跑全量五关（末轮还多一次 critic AI 调用）。
        // 统一后同内容直接命中缓存，每轮迭代省一次全量评估。
        $cacheKey = md5($content);

        // 检查缓存
        if (isset($this->evaluationCache[$cacheKey])) {
            $this->performanceMetrics['cache_hits']++;
            $cached = $this->evaluationCache[$cacheKey];
            // 审计修复（2026-08-17 P3）修复5：md5($content) 缓存键不含 critic 维度——
            // 第2轮（无critic）post-check 的缓存会被第3轮（=maxIterations，应含critic）
            // pre-check 命中，critic 弱项丢失。命中后若本次应含 critic（同修复4条件：
            // 首末轮 且 rewrite.use_critic_agent 开关开）而缓存结果中 critic 数据缺失
            // （critic_available 为空；eval_failed 的缓存除外，五关都挂了补 critic 无意义），
            // 仅补跑 getCriticScore 合并进结果并回写缓存，五关不重跑。
            $criticExpected = ($iteration == 1 || $iteration == $this->maxIterations)
                && (bool)($context['use_critic_agent'] ?? true);
            if ($criticExpected && empty($cached['critic_available']) && empty($cached['eval_failed'])) {
                $this->performanceMetrics['total_api_calls']++;
                $criticReview = $this->getCriticScore($content, $chapterInfo, $context);
                $cached['critic_score'] = $criticReview['avg'] ?? 0;
                $cached['critic_weak_dims'] = $criticReview['weak_dims'] ?? [];
                // H-8 同语义：区分"critic 调用失败（score=0）"与"未调用 critic"
                $cached['critic_available'] = isset($criticReview['avg']) && $criticReview['avg'] > 0;
                // 与主路径同公式：overall_score 恒等于 gate 分不变，critic 综合分单独存储
                $cached['overall_with_critic'] = $cached['critic_available']
                    ? round($cached['gate_score'] * 0.7 + $cached['critic_score'] * 10 * 0.3, 1)
                    : null;
                $this->evaluationCache[$cacheKey] = $cached;
            }
            return $cached;
        }

        $this->performanceMetrics['cache_misses']++;
        $evalStartTime = microtime(true);

        $results = [];

        // 五关检测
        // 审计修复（2026-07-19 M3-2）：前后轮统一使用完整五关检测。
        // 原实现 pre 用 5 关、post 用 4 关（跳过 Gate5），导致 improvement 成为两个不同公式的差值，
        // 终止/采纳判断建立在噪声度量上。runFiveGateCheck 已改用 includes/quality/Gates.php，
        // 在 CLI/SSE/daemon 上下文均安全，故统一为完整五关。
        $results = $this->runFiveGateCheck($chapterInfo, $content);

        // H-7 修复（2026-07-25）：五关检测异常时 $results 为空，原代码 gateScore=0、
        // overallScore=0，下一轮若五关恢复会跳回 80+，产生假"巨大改进"信号。
        // 改为：标记 eval_failed，调用方据此跳过改进趋势计算。
        if (empty($results)) {
            $evaluation = [
                'overall_score' => 0,
                'gate_score' => 0,
                'critic_score' => 0,
                'critic_weak_dims' => [],
                'gate_results' => [],
                'weak_gates' => [],
                'eval_failed' => true,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            $this->evaluationCache[$cacheKey] = $evaluation;
            return $evaluation;
        }

        // 计算综合分数
        $scores = array_column($results, 'score');
        $gateScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

        // 读者视角评分（可选，降低频率）
        // 审计修复（2026-07-19 M3-2）：critic 参与规则在 pre/post 间保持一致。
        // 原条件 `... && !$isPostCheck` 使最终轮 pre 含 critic、post 不含 → 同轮两公式不一致。
        // 现第 1 轮与最终轮的 pre/post 均含 critic，中间轮均不含，保证同轮前后公式相同。
        $criticScore = 0;
        $criticWeakDims = [];
        $criticAvailable = false;
        if ($iteration == 1 || $iteration == $this->maxIterations) {
            // 审计修复（2026-08-17 P3）修复4：rewrite.use_critic_agent 开关此前失效——
            // RewriteAgent 把配置放进 $context['use_critic_agent'] 但控制器不消费，
            // critic 调用只看轮次。追加开关判断（缺省 true 保持外部调用兼容）：
            // 轮次条件满足 且 开关开启 才调用 critic。
            if ((bool)($context['use_critic_agent'] ?? true)) {
                $this->performanceMetrics['total_api_calls']++;
                $criticReview = $this->getCriticScore($content, $chapterInfo, $context);
                $criticScore = $criticReview['avg'] ?? 0;
                $criticWeakDims = $criticReview['weak_dims'] ?? [];
                // H-8 修复：区分"critic 调用失败（score=0）"与"未调用 critic"
                $criticAvailable = isset($criticReview['avg']) && $criticReview['avg'] > 0;
            }
        }

        // H-5/H-8 修复（2026-07-25）：原公式在 criticScore>0 时用加权、=0（失败）时用纯 gate，
        // 导致：(1) 中间轮（无 critic）与首尾轮（有 critic）overall 不可比，跨轮改进信号失真；
        // (2) critic 调用失败（0 分）反而比低分（如 5 分）得到更高 overall。
        // 改为：overall_score 始终用 gateScore，保证跨轮可比；critic 信息单独存储用于报告与问题识别。
        $overallScore = $gateScore;

        // 仅在 critic 可用时计算含 critic 的综合分，供报告/日志参考
        $overallWithCritic = $criticAvailable
            ? round($gateScore * 0.7 + $criticScore * 10 * 0.3, 1)
            : null;

        // 识别弱项
        $weakGates = [];
        foreach ($results as $gate) {
            if ($gate['score'] < 60) {
                $weakGates[] = [
                    'name' => $gate['name'],
                    'score' => $gate['score'],
                    'issues' => $gate['issues'] ?? [],
                ];
            }
        }

        $evaluation = [
            'overall_score' => $overallScore,
            'gate_score' => $gateScore,
            'critic_score' => $criticScore,
            'critic_available' => $criticAvailable,
            'overall_with_critic' => $overallWithCritic,
            'critic_weak_dims' => $criticWeakDims,
            'gate_results' => $results,
            'weak_gates' => $weakGates,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // 缓存评估结果
        $this->evaluationCache[$cacheKey] = $evaluation;

        // 记录性能指标
        $evalTime = (microtime(true) - $evalStartTime) * 1000;
        $this->performanceMetrics['evaluation_time_ms'] += $evalTime;

        return $evaluation;
    }

    /**
     * 运行完整五关检测
     */
    private function runFiveGateCheck(array $chapterInfo, string $content): array
    {
        try {
            // W-5 修复：改用 includes/quality/Gates.php，避免从 includes 上下文加载 API 文件
            require_once __DIR__ . '/../quality/Gates.php';

            $results = [];
            $results[] = checkGate1_Structure($chapterInfo, $content);
            $results[] = checkGate2_Characters($this->novelId, $content);
            $results[] = checkGate3_Description(null, $content);
            $results[] = checkGate4_CoolPoint($content, $chapterInfo['outline'] ?? null);
            $results[] = checkGate5_Consistency($chapterInfo['id'] ?? 0, $this->novelId, $content);
            $results[] = checkGate6_OutlineAdherence($chapterInfo, $content);

            return $results;
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::runFiveGateCheck 失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取读者视角评分
     */
    /**
     * @return array{avg: float, weak_dims: array}
     */
    private function getCriticScore(string $content, array $chapterInfo, array $context): array
    {
        try {
            require_once __DIR__ . '/CriticAgent.php';
            $criticAgent = new CriticAgent($this->novelId);
            $reviewContext = array_merge([
                // 修复：title 是书名，应取 novel_title；原代码误用章节标题填书名字段，
                // 导致 CriticAgent prompt 上下文失真（书名显示为章节名）。
                'title' => $chapterInfo['novel_title'] ?? '未知书名',
                'genre' => $chapterInfo['genre'] ?? '都市',
                'protagonist_name' => $chapterInfo['protagonist_name'] ?? '主角',
                'chapter_title' => $chapterInfo['title'] ?? '',
                'outline' => $chapterInfo['outline'] ?? '',
            ], $context);

            $review = $criticAgent->review($content, $reviewContext);
            return ['avg' => $review['avg'] ?? 0, 'weak_dims' => $review['weak_dims'] ?? []];
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::getCriticScore 失败：' . $e->getMessage());
            return ['avg' => 0, 'weak_dims' => []];
        }
    }

    /**
     * 问题识别 - 整合各类检测结果
     */
    private function identifyIssues(string $content, array $chapterInfo, array $evaluation): array
    {
        $issues = [];

        // 从五关检测提取严重问题
        foreach ($evaluation['weak_gates'] as $gate) {
            $gateIssues = $gate['issues'];
            if (is_array($gateIssues) && count($gateIssues) > 0) {
                foreach (array_slice($gateIssues, 0, 3) as $issue) {
                    $issues[] = [
                        'source' => 'gate',
                        'gate_name' => $gate['name'],
                        'description' => is_string($issue) ? $issue : json_encode($issue, JSON_UNESCAPED_UNICODE),
                        'severity' => $gate['score'] < 50 ? 'high' : 'medium',
                        'score' => $gate['score'],
                    ];
                }
            }
        }

        // 从 CriticAgent 提取弱项
        if (!empty($evaluation['critic_score']) && !empty($evaluation['critic_weak_dims'])) {
            foreach ($evaluation['critic_weak_dims'] as $dim) {
                $issues[] = [
                    'source' => 'critic',
                    'dimension' => $dim['dim'],
                    'description' => $dim['reason'] ?? '',
                    'severity' => 'medium',
                    'score' => $dim['score'],
                ];
            }
        }

        // AI 痕迹检测（通过 StyleGuard）
        try {
            require_once __DIR__ . '/StyleGuard.php';
            $styleGuard = new StyleGuard($this->novelId);
            $aiPatterns = $styleGuard->detectAIPatterns($content);
            if (!empty($aiPatterns['issues'])) {
                foreach (array_slice($aiPatterns['issues'], 0, 2) as $issue) {
                    $issues[] = [
                        'source' => 'style',
                        'type' => 'ai_pattern',
                        'description' => $issue,
                        'severity' => 'low',
                        'score' => 70,
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::identifyIssues (StyleGuard) 失败：' . $e->getMessage());
        }

        return $issues;
    }

    /**
     * 生成改进建议
     */
    private function generateSuggestions(array $issues, string $content, array $chapterInfo, int $iteration): array
    {
        if (empty($issues)) {
            return [];
        }

        $suggestions = [];

        // 按严重程度分组
        $highPriority = array_filter($issues, fn($i) => $i['severity'] === 'high');
        $mediumPriority = array_filter($issues, fn($i) => $i['severity'] === 'medium');
        $lowPriority = array_filter($issues, fn($i) => $i['severity'] === 'low');

        // 生成重点改进建议（高优先级）
        foreach (array_slice($highPriority, 0, 3) as $issue) {
            $suggestions[] = $this->createSuggestion($issue, $content, $chapterInfo, 'high');
        }

        // 生成次要改进建议（中优先级）
        foreach (array_slice($mediumPriority, 0, 2) as $issue) {
            $suggestions[] = $this->createSuggestion($issue, $content, $chapterInfo, 'medium');
        }

        // 早期迭代可以考虑低优先级问题
        if ($iteration <= 2 && !empty($lowPriority)) {
            foreach (array_slice($lowPriority, 0, 1) as $issue) {
                $suggestions[] = $this->createSuggestion($issue, $content, $chapterInfo, 'low');
            }
        }

        return $suggestions;
    }

    /**
     * 创建具体改进建议
     */
    private function createSuggestion(array $issue, string $content, array $chapterInfo, string $priority): array
    {
        $suggestion = [
            'priority' => $priority,
            'source' => $issue['source'],
            'description' => $issue['description'],
            'instructions' => [],
        ];

        // 根据问题类型生成具体指令
        switch ($issue['source']) {
            case 'gate':
                $suggestion['instructions'] = $this->generateGateFixInstructions($issue, $content, $chapterInfo);
                break;
            case 'critic':
                $suggestion['instructions'] = $this->generateCriticFixInstructions($issue, $content, $chapterInfo);
                break;
            case 'style':
                $suggestion['instructions'] = $this->generateStyleFixInstructions($issue, $content);
                break;
        }

        return $suggestion;
    }

    /**
     * 生成五关问题修复指令
     */
    private function generateGateFixInstructions(array $issue, string $content, array $chapterInfo): array
    {
        $gateName = $issue['gate_name'] ?? '';

        return [
            "重点修正【{$gateName}】中的问题：{$issue['description']}",
            "保持原有的人物性格和对话风格不变",
            "保持原有的情节走向和大纲要求",
            "保持原有字数范围（±10%）",
        ];
    }

    /**
     * 生成读者视角问题修复指令
     */
    private function generateCriticFixInstructions(array $issue, string $content, array $chapterInfo): array
    {
        $dimension = $issue['dimension'] ?? '';
        $dimensionLabels = [
            'thrill' => '爽感强度',
            'immersion' => '代入感',
            'pacing' => '节奏感',
            'freshness' => '新鲜度',
            'read_next' => '追读欲望',
        ];

        $label = $dimensionLabels[$dimension] ?? $dimension;

        $instructions = [
            "增强【{$label}】：{$issue['description']}",
            "让读者有更强的阅读欲望",
        ];

        return $instructions;
    }

    /**
     * 生成风格修复指令
     */
    private function generateStyleFixInstructions(array $issue, string $content): array
    {
        return [
            "优化写作风格：{$issue['description']}",
            "减少 AI 写作痕迹，增加人性化表达",
        ];
    }

    /**
     * 应用改进 - 调用 AI 执行重写
     */
    private function applyImprovements(string $content, array $suggestions, array $chapterInfo, array $context, int $providedIteration = 0): string
    {
        if (empty($suggestions)) {
            return $content;
        }

        $issueDescriptions = [];
        foreach ($suggestions as $suggestion) {
            $issueDescriptions[] = "- {$suggestion['description']}";
            if (!empty($suggestion['instructions'])) {
                foreach (array_slice($suggestion['instructions'], 0, 2) as $instruction) {
                    $issueDescriptions[] = "  → {$instruction}";
                }
            }
        }
        $issuesText = implode("\n", $issueDescriptions);

        // 审计修复（2026-07-19 H-中12）：$this->iterationHistory 从未被填充，
        // 此前恒为 1 → AI 收到的多轮迭代 prompt 恒为"第 1 轮"。
        // 改用 refine() 循环中传入的 $iteration 实参（调用方已有）。
        // 若 $iteration 未传入则回退到 $this->iterationHistory 计数（兼容外部调用）。
        $iteration = $providedIteration ?? (count($this->iterationHistory) + 1);
        $chNum = $chapterInfo['chapter_number'] ?? 0;
        $chapterTitle = $chapterInfo['title'] ?? '';
        $outline = $chapterInfo['outline'] ?? '';

        // v1.11.2 Bug #8 修复：拉取长篇关键上下文，避免重写时破坏全局一致性
        $contextSection = $this->buildContextSection((int)$chNum);

        // 审计修复（2026-08-09 R11-3）：精炼重写也须带铁律
        $isFinal = false;
        try {
            $tc = (int)(DB::fetch(
                'SELECT target_chapters FROM novels WHERE id=?',
                [$this->novelId]
            )['target_chapters'] ?? 0);
            $isFinal = $tc > 0 && (int)$chNum >= $tc;
        } catch (\Throwable) { /* ignore */ }
        if (!function_exists('rewriteIronRulesSnippet')) {
            require_once dirname(__DIR__) . '/helpers.php';
        }
        $iron = rewriteIronRulesSnippet($isFinal);

        $system = <<<EOT
你是一位资深网文编辑，擅长将初稿改写成高质量章节。
按以下规则重写：
1. 重点修正指出的问题，但不要改动已经合格的段落
2. 保持原有的人物性格、情节走向、对话风格
3. 保持原有字数范围（±10%）
4. 严格遵循下文提供的人物状态/情绪状态/POV/前情摘要等上下文
5. {$iron}
6. 直接输出重写后的完整章节正文，不要加任何前缀或解释
7. 这是第 {$iteration} 轮迭代改进，要有针对性但不要过度修改
EOT;

        $user = <<<EOT
请重写小说第{$chNum}章《{$chapterTitle}》。

【原大纲】{$outline}

{$contextSection}
【本轮需要修正的问题】
{$issuesText}

【原始章节正文】
{$content}

请输出重写后的完整章节正文：
EOT;

        try {
            $modelId = $context['model_id'] ?? null;
            require_once dirname(__DIR__) . '/ai.php';
            // W-4 修复：改用 chatStream() 流式 + collector，避免长章节非流式静默超时
            $rewritten = trim(withModelFallback(
                $modelId,
                function (AIClient $ai) use ($system, $user) {
                    $collected = '';
                    $this->generationAttempted = true;
                    $this->rawFinishReason = null;
                    $this->rawGenerationError = null;
                    try {
                        $ai->chatStream([
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user',   'content' => $user],
                        ], function ($chunk) use (&$collected) {
                            $collected .= $chunk;
                        }, 'creative');
                        ChapterGenerationIntegrity::assertComplete($ai->lastFinishReason);
                    } catch (\Throwable $exception) {
                        $this->rawGenerationError = $exception->getMessage();
                        throw $exception;
                    } finally {
                        // Preserve even an empty or partial stream. A fallback
                        // model invocation overwrites this with its own, newer
                        // attempt, so the field always names the last attempt.
                        $this->rawGeneratedContent = $collected;
                        $this->rawFinishReason = $ai->lastFinishReason;
                    }
                    return $collected;
                }
            ));

            // 修复：applyImprovements 的 chatStream 重写调用是每轮成本大头，
            // 原代码未计入 total_api_calls，导致成本监控数据失真。
            $this->performanceMetrics['total_api_calls']++;

            if (empty($rewritten) || mb_strlen($rewritten) < 200) {
                addLog($this->novelId, 'iterative_refine', '警告：AI 返回内容过短，使用原始内容');
                return $content;
            }

            $origWords = countWords($content);
            $rewrittenWords = countWords($rewritten);

            // M-7 修复（2026-07-25）：原 $minAllowed = origWords * 0.5 允许 50% 缩水，
            // 3000 字可被改成 1500 字仍通过。与 prompt 中"±10%"指令严重不符。
            // 收紧至 0.8（允许 ±20%），与超长截断的容忍度对称。
            $minAllowed = (int)($origWords * 0.8);
            if ($rewrittenWords < $minAllowed) {
                addLog($this->novelId, 'iterative_refine', sprintf(
                    '警告：重写后字数严重缩水（%d字 → %d字，低于下限%d字），使用原始内容',
                    $origWords, $rewrittenWords, $minAllowed
                ));
                return $content;
            }

            // 统一使用动态容忍度计算（与 WriteEngine 保持一致）
            require_once dirname(__DIR__) . '/prompt.php';   // includes/prompt.php（原多了一个 /.. 指向了不存在的根目录 prompt.php）
            $tol = calculateDynamicTolerance($origWords, $this->novelId);
            $maxAllowed = $tol['max'];

            if ($rewrittenWords > $maxAllowed) {
                addLog($this->novelId, 'iterative_refine', sprintf(
                    '重写后超字（%d字，上限%d字），保留完整候选供质量门审阅',
                    $rewrittenWords, $maxAllowed
                ));
            }

            // 返回到这里的稿件来自通过 assertComplete 的那次调用（失败调用会抛出），
            // 此刻的 raw* 证据正描述它；按正文 hash 绑定，供回选历史最优稿时使用。
            $this->candidateEvidence[hash('sha256', $rewritten)] = [
                'finish_reason' => $this->rawFinishReason,
                'generation_error' => $this->rawGenerationError,
            ];
            return $rewritten;
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::applyImprovements 失败：' . $e->getMessage());
            return $content;
        }
    }

    /**
     * v1.11.2 Bug #8 修复：构建长篇上下文段
     *
     * RewriteAgent 重写时容易破坏全局一致性（人物性格漂移、情绪不连续、POV 越界等）。
     * 本方法从数据库拉取关键上下文段，注入 prompt 让 AI 重写时遵循。
     *
     * 包含：
     *   - 主角名 + 当前状态摘要
     *   - 上章末尾核心角色情绪状态
     *   - POV 视角约束
     *   - 上 1-2 章简要摘要（前情）
     *
     * @param int $chNum 当前章节号
     * @return string 上下文段（含尾部空行），无数据时返回空
     */
    private function buildContextSection(int $chNum): string
    {
        if ($chNum < 2 || $this->novelId <= 0) return '';

        $sections = [];

        try {
            $novel = DB::fetch(
                "SELECT protagonist_name, narrative_pov FROM novels WHERE id = ?",
                [$this->novelId]
            );
            if (!$novel) return '';

            $protagonist = trim($novel['protagonist_name'] ?? '');
            $pov = trim($novel['narrative_pov'] ?? '');

            // 1. POV 视角约束（如果是限知视角）
            if ($protagonist && (empty($pov) || $pov === 'third_limited')) {
                $sections[] = "【视角约束】第三人称限知视角，跟随主角【{$protagonist}】。"
                    . "其他角色的内心想法（「X 心想」）仅当 X = {$protagonist} 时允许。";
            }

            // 2. 主角当前状态摘要（最新 character_card）
            if ($protagonist) {
                // character_cards 没有 realm/role 列：身份在 title，境界在 attributes JSON。
                // 原查询恒报 Unknown column，被外层 catch 吞掉后整个上下文段返回空，
                // 迭代重写因此丢失视角约束、情绪状态与前情摘要。
                // 重写旧章时卡片可能已被后续章节更新，此时不注入未来状态。
                $card = DB::fetch(
                    "SELECT title, status, attributes FROM character_cards
                     WHERE novel_id = ? AND name = ?
                       AND (last_updated_chapter IS NULL OR last_updated_chapter <= ?)
                     LIMIT 1",
                    [$this->novelId, $protagonist, $chNum]
                );
                if ($card) {
                    $attrs = is_string($card['attributes'] ?? null)
                        ? json_decode($card['attributes'], true)
                        : ($card['attributes'] ?? null);
                    $realm = '';
                    if (is_array($attrs)) {
                        foreach (['realm', 'level', '境界'] as $realmKey) {
                            if (is_scalar($attrs[$realmKey] ?? null) && trim((string)$attrs[$realmKey]) !== '') {
                                $realm = trim((string)$attrs[$realmKey]);
                                break;
                            }
                        }
                    }
                    $parts = [];
                    if ($realm !== '')            $parts[] = "境界：{$realm}";
                    if (!empty($card['title']))  $parts[] = "身份：{$card['title']}";
                    if (!empty($card['status'])) $parts[] = "处境：{$card['status']}";
                    if ($parts) {
                        $sections[] = "【主角当前状态】" . implode('；', $parts);
                    }
                }
            }

            // 3. 上章末尾核心角色情绪状态
            try {
                require_once __DIR__ . '/../memory/CharacterEmotionRepo.php';
                $emoRepo = new CharacterEmotionRepo($this->novelId);
                $emoSection = $emoRepo->buildEmotionSection($chNum);
                if (!empty(trim($emoSection))) {
                    $sections[] = trim($emoSection);
                }
            } catch (\Throwable $e) {
                // 静默降级
            }

            // 4. 前 1-2 章摘要（防止重写时丢失前情）
            $prevChs = DB::fetchAll(
                "SELECT chapter_number, chapter_summary FROM chapters
                 WHERE novel_id = ? AND chapter_number < ? AND chapter_summary IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT 2",
                [$this->novelId, $chNum]
            );
            if ($prevChs) {
                $prevLines = ['【前情摘要】'];
                foreach (array_reverse($prevChs) as $pc) {
                    $sum = mb_substr(trim($pc['chapter_summary']), 0, 120);
                    $prevLines[] = "第{$pc['chapter_number']}章：{$sum}";
                }
                $sections[] = implode("\n", $prevLines);
            }
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::buildContextSection failed: ' . $e->getMessage());
            return '';
        }

        if (empty($sections)) return '';

        return implode("\n\n", $sections) . "\n\n";
    }

    /**
     * 判断是否应该提前终止迭代
     */
    private function shouldTerminateEarly(int $iteration, float $improvement, float $currentScore, array $results): bool
    {
        // 检查质量是否下降
        if (count($results) >= 2) {
            $prevScore = $results[count($results) - 2]['after_score'] ?? 0;
            if ($currentScore < $prevScore - $this->qualityDeclineThreshold) {
                $this->terminationReason = "质量下降超过阈值（{$this->qualityDeclineThreshold}分），停止迭代";
                return true;
            }
        }

        // 批次二修复（2026-08-16）：原两段判定（"边际收益 OR 逻辑" + "min 近两轮"）
        // 语义重叠且均为"单轮低改进即终止"——默认阈值 5.0×0.5=2.5 分（百分制）过于
        // 敏感，第 2 轮起任何单轮改进 <2.5 分就停止，maxIterations=3 形同虚设，
        // 单轮评估噪声即可扼杀后续迭代（如 +2 后 +8 的回升形态也会被 min 捕杀）。
        // 改为"连续两轮均低于阈值"才判定收益枯竭：单轮低改进可能是噪声，
        // 持续低改进才是真实收敛信号。
        if (count($this->improvementTrend) >= 2) {
            $lastTwoImprovements = array_slice($this->improvementTrend, -2);
            $diminishingThreshold = $this->minImprovementThreshold * 0.5;
            if (max($lastTwoImprovements) < $diminishingThreshold) {
                $this->terminationReason = sprintf(
                    '连续两轮改进均低于%.1f分（%+.1f和%+.1f），收益枯竭，停止迭代',
                    $diminishingThreshold,
                    $lastTwoImprovements[0],
                    $lastTwoImprovements[1]
                );
                return true;
            }
        }

        // 如果已经是最多迭代次数
        if ($iteration >= $this->maxIterations) {
            $this->terminationReason = "已达到最大迭代次数（{$this->maxIterations}）";
            return true;
        }

        return false;
    }

    private string $terminationReason = '';

    private function getTerminationReason(): string
    {
        return $this->terminationReason;
    }

    /**
     * 保存迭代历史到数据库
     */
    private function saveIterationHistory(array $chapterInfo, array $results, float $totalImprovement, float $totalTime): void
    {
        try {
            $chNum = $chapterInfo['chapter_number'] ?? 0;
            // M-6 修复（2026-07-25）：target_reached 条目无 before_score/after_score，
            // 原代码 $initialScore ?? 0、$finalScore ?? $initialScore 导致日志显示 "0.0 → 0.0"。
            // 改为：依次回退 before_score → score（target_reached 的达标分）→ 0；
            //       after_score → score → initialScore，准确反映首尾分数。
            $initialScore = $results[0]['before_score']
                ?? $results[0]['score']
                ?? 0;
            $lastResult = $results[count($results) - 1];
            $finalScore = $lastResult['after_score']
                ?? $lastResult['score']
                ?? $initialScore;
            $iterationsUsed = count($results);
            $stopReason = $lastResult['stop_reason'] ?? '完成';

            $historyData = json_encode([
                'iterations' => $results,
                'improvement_trend' => $this->improvementTrend,
                'total_improvement' => $totalImprovement,
                'execution_time_ms' => $totalTime,
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // 更新章节表中的迭代信息
            if ($this->chapterId > 0) {
                DB::update('chapters', [
                    'iterative_history' => $historyData,
                    'iterations_used' => $iterationsUsed,
                    'total_improvement' => $totalImprovement,
                ], 'id=?', [$this->chapterId]);
            }

            addLog($this->novelId, 'iterative_refine', sprintf(
                '第%d章迭代改进完成：%.1f → %.1f（+%.1f），用时 %.0fms，停止原因：%s',
                $chNum, $initialScore, $finalScore, $totalImprovement, $totalTime, $stopReason
            ));
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::saveIterationHistory 失败：' . $e->getMessage());
        }
    }

    /**
     * 获取配置参数（供外部访问）
     */
    public function getConfiguration(): array
    {
        return [
            'max_iterations' => $this->maxIterations,
            'min_improvement_threshold' => $this->minImprovementThreshold,
            'target_score' => $this->targetScore,
            'quality_decline_threshold' => $this->qualityDeclineThreshold,
        ];
    }

    /**
     * 动态调整配置参数
     */
    public function adjustConfiguration(array $newConfig): bool
    {
        try {
            if ($this->frozenMaxIterations === null && isset($newConfig['max_iterations'])) {
                $this->maxIterations = max(1, min(5, (int)$newConfig['max_iterations']));
            }
            if (isset($newConfig['min_improvement'])) {
                $this->minImprovementThreshold = max(1.0, min(20.0, (float)$newConfig['min_improvement']));
            }
            if (isset($newConfig['target_score'])) {
                $this->targetScore = max(60.0, min(100.0, (float)$newConfig['target_score']));
            }
            if (isset($newConfig['quality_decline_threshold'])) {
                $this->qualityDeclineThreshold = max(1.0, min(10.0, (float)$newConfig['quality_decline_threshold']));
            }
            return true;
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::adjustConfiguration 失败：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取性能监控指标
     *
     * @return array 性能指标数据
     */
    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }
}
