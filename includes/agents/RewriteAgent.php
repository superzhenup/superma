<?php
defined('APP_LOADED') or die('Direct access denied.');
require_once dirname(__DIR__) . '/quality/ChapterGenerationIntegrity.php';

/**
 * RewriteAgent — 迭代改进重写 Agent
 *
 * 核心功能：
 *   - 基于 IterativeRefinementController 的多轮迭代改进
 *   - 整合五关检测和 CriticAgent 的多视角质量评估
 *   - 智能终止条件判断，避免无效迭代
 *   - 完整的迭代历史记录和效果追踪
 *
 * 工作流程：
 *   章节完成 → 五关检测 → 质量评估
 *       ↓
 *   质量 < 阈值？ → 是 → 启动迭代改进循环
 *       ↓                          ↓
 *   保存结果 ← 效果验证 ← AI 重写 ← 问题识别 ← 生成建议
 *       ↓
 *   提升 > 阈值？ → 是 → 采纳重写
 *       ↓ 否
 *   保留原内容
 *
 * 与旧版 RewriteAgent 的区别：
 *   - 支持多轮迭代（最多 3 轮），而旧版仅支持单次重写
 *   - 整合了 ImprovementEvaluator，提供详细的效果评估
 *   - 智能终止条件，避免无效迭代
 *   - 完整的迭代历史，便于分析和优化
 *
 * @package NovelWritingSystem
 * @version 1.1.0
 */
class RewriteAgent
{
    private int $novelId;
    private int $chapterId;

    private IterativeRefinementController $refinementController;
    private ImprovementEvaluator $evaluator;

    private int $threshold;
    private int $minGain;
    private bool $useIterativeMode;
    private bool $useCriticAgent;

    // H8: 懒缓存小说级字数目标，供 quickQualityCheck 注入 Gate1
    private ?int $novelChapterWords = null;

    public function __construct(int $novelId, int $chapterId = 0, ?int $frozenRewriteLimit = null)
    {
        $this->novelId = $novelId;
        $this->chapterId = $chapterId;

        $this->loadConfiguration();

        require_once __DIR__ . '/IterativeRefinementController.php';
        require_once __DIR__ . '/ImprovementEvaluator.php';

        $this->refinementController = new IterativeRefinementController(
            $novelId,
            $chapterId,
            $frozenRewriteLimit
        );
        $this->evaluator = new ImprovementEvaluator($novelId, $chapterId);
    }

    /**
     * 加载配置参数
     */
    private function loadConfiguration(): void
    {
        $this->threshold = (int)getSetting('rewrite.threshold', 70, $this->novelId);
        $this->minGain = (int)getSetting('rewrite.min_gain', 10, $this->novelId);
        $this->useIterativeMode = (bool)getSetting('rewrite.iterative_mode', true, $this->novelId);
        $this->useCriticAgent = (bool)getSetting('rewrite.use_critic_agent', true, $this->novelId);
    }

    /**
     * 重写入口方法 - 兼容旧版 API
     *
     * @param array $chapter 章节记录
     * @param string $content 章节正文
     * @param array $gateResults 五关检测结果
     * @param float $originalScore 原始质量分
     * @param int|null $modelId 模型ID
     * @return array{
     *   generation_attempted:bool,
     *   rewritten:bool,
     *   new_score:float|null,
     *   content:string|null,
     *   message:string,
     *   iterations_used:int,
     *   diagnostics?:array
     * }
     *
     * `rewritten` is retained as a compatibility alias for
     * `generation_attempted`; it no longer means that this Agent accepted the
     * output.  The caller must freeze `content` as a Candidate and let the
     * canonical quality gate decide whether it is promoted or quarantined.
     */
    public function rewriteIfNeeded(
        array $chapter,
        string $content,
        array $gateResults,
        float $originalScore,
        ?int $modelId
    ): array {
        // 记录原始方法调用
        addLog($this->novelId, 'rewrite', sprintf(
            '调用 rewriteIfNeeded：章节 %d，原始分数 %.1f，阈值 %d',
            $chapter['chapter_number'] ?? 0,
            $originalScore,
            $this->threshold
        ));

        // v2 独立重写模型：自审盲区消除
        // 如果用户配置了重写专用模型，则用不同模型重写，避免同一双眼睛看同一段文字
        $rewriteModelId = (int)getSystemSetting('ws_rewrite_model_id', 0, 'int');
        if ($rewriteModelId > 0 && $rewriteModelId !== (int)($modelId ?? 0)) {
            $rwModel = \DB::fetch('SELECT id, name FROM ai_models WHERE id=?', [$rewriteModelId]);
            if ($rwModel) {
                $origName = $modelId ? (\DB::fetch('SELECT id, name FROM ai_models WHERE id=?', [$modelId])['name'] ?? '默认') : '默认';
                addLog($this->novelId, 'info', sprintf(
                    'RewriteAgent 使用独立重写模型 #%d（%s），正文模型：%s',
                    $rewriteModelId, $rwModel['name'], $origName
                ));
                $modelId = $rewriteModelId;
            } else {
                addLog($this->novelId, 'warn', "配置的重写模型 #{$rewriteModelId} 不存在，回退到正文模型");
            }
        }

        // 检查是否需要重写
        if ($originalScore >= $this->threshold) {
            return [
                'generation_attempted' => false,
                'rewritten' => false,
                'new_score' => null,
                'content' => null,
                'message' => "质量分 {$originalScore} ≥ 阈值 {$this->threshold}，无需重写",
                'iterations_used' => 0,
            ];
        }

        // 提取严重问题
        $criticalIssues = $this->extractCriticalIssues($gateResults);

        if (empty($criticalIssues) && $originalScore >= $this->threshold - 10) {
            return [
                'generation_attempted' => false,
                'rewritten' => false,
                'new_score' => null,
                'content' => null,
                'message' => "质量分 {$originalScore} < {$this->threshold}，但无非60分以下严重问题且接近阈值（≥" . ($this->threshold - 10) . "），跳过重写",
                'iterations_used' => 0,
            ];
        }

        // 使用新版迭代改进模式
        if ($this->useIterativeMode) {
            return $this->performIterativeRefinement($chapter, $content, $gateResults, $originalScore, $modelId);
        }

        // 兼容旧版单次重写模式
        return $this->performSingleRewrite($chapter, $content, $gateResults, $originalScore, $modelId);
    }

    /**
     * 执行迭代改进（新模式）
     */
    private function performIterativeRefinement(
        array $chapter,
        string $content,
        array $gateResults,
        float $originalScore,
        ?int $modelId
    ): array {
        $chNum = $chapter['chapter_number'] ?? 0;

        addLog($this->novelId, 'rewrite', sprintf(
            '第 %d 章启动迭代改进模式，原始分数 %.1f',
            $chNum,
            $originalScore
        ));

        try {
            // 准备上下文
            // 批次二（2026-08-16）：把采纳门槛（min_gain）传给迭代控制器，
            // 供其"已达采纳增益即提前收割"联动判定（见 refine() 内 adoption_gain）
            $context = [
                'model_id' => $modelId,
                'use_critic_agent' => $this->useCriticAgent,
                'adoption_gain' => $this->minGain,
            ];

            // 获取章节基本信息
            $chapterInfo = $this->prepareChapterInfo($chapter);

            // 调用迭代控制器
            $result = $this->refinementController->refine($content, $chapterInfo, $context);

            // 评估改进效果
            if (!empty($result['history'])) {
                $evaluation = $this->evaluator->evaluateOverall($result['history']);
                $this->evaluator->saveEvaluation($evaluation, count($result['history']));

                // 生成改进报告
                $report = $this->evaluator->generateImprovementReport($result['history']);
                addLog($this->novelId, 'rewrite', sprintf(
                    '第 %d 章迭代改进评估：总提升 %.1f 分，成功率 %.1f%%',
                    $chNum,
                    $report['summary']['total_improvement'],
                    $report['summary']['success_rate']
                ));
            }

            // IterativeRefinementController keeps its historical best/fallback
            // result for existing callers, but also exposes the last literal
            // model output.
            // 审计修复 P1（2026-09-27）：冻结候选从"末轮原始输出"改为控制器的
            // final_content（含历史最优回溯）。原设计刻意把 raw 末轮稿交统一
            // 质量门，但末轮可能是空/截断/低分尝试：门过则比中间轮更差的
            // 退化稿晋升 Canon，门不过则更优的中间稿被白白丢弃——历史最优
            // 稿从未获得门的评审机会。final_content 由控制器保证 best 回溯
            // 语义（空稿回退原稿时与原文同 hash，由调用方跳过提交）；
            // raw 保留为诊断字段。
            $generationAttempted = !empty($result['generation_attempted']);
            $rawContent = $generationAttempted && is_string($result['raw_content'] ?? null)
                ? $result['raw_content']
                : ($generationAttempted ? '' : null);
            $candidateContent = $generationAttempted
                ? (string)($result['final_content'] ?? $rawContent)
                : null;
            $integrity = (array)($result['generation_integrity'] ?? []);
            if ($generationAttempted && $candidateContent !== $rawContent) {
                // 候选来自历史最优回溯：末轮的证据不能描述候选。
                // 审计修复 F07（2026-11-01）：原实现只清 finish_reason、保留末轮
                // generation_error，健康的历史稿仍被统一门判为 content.truncated。
                // 改用控制器按正文 hash 绑定的、候选自己那一轮的完成证据；拿不到
                // 证据时保留末轮证据（失败关闭），绝不笼统清空错误。
                $candidateIntegrity = $result['final_content_integrity'] ?? null;
                if (is_array($candidateIntegrity)) {
                    $integrity = [
                        'finish_reason' => $candidateIntegrity['finish_reason'] ?? null,
                        'generation_error' => $candidateIntegrity['generation_error'] ?? null,
                    ];
                }
                $integrity['candidate_source'] = 'iteration_best';
            }
            $candidateScore = null;
            if ($generationAttempted) {
                $normalizedCandidate = trim((string)$candidateContent);
                foreach (array_reverse((array)($result['history'] ?? [])) as $iterationResult) {
                    if (!is_array($iterationResult)
                        || !array_key_exists('improved_content', $iterationResult)
                        || !is_string($iterationResult['improved_content'])
                        || !hash_equals($normalizedCandidate, $iterationResult['improved_content'])) {
                        continue;
                    }
                    if (is_numeric($iterationResult['after_score'] ?? null)) {
                        $candidateScore = (float)$iterationResult['after_score'];
                    }
                    break;
                }
            }
            $gain = $candidateScore !== null
                ? round($candidateScore - $originalScore, 1)
                : null;
            $legacyGainMet = $gain !== null ? $gain >= $this->minGain : null;

            if (!$generationAttempted) {
                return [
                    'generation_attempted' => false,
                    'rewritten' => false,
                    'new_score' => null,
                    'content' => null,
                    'message' => '迭代检查完成，但未调用模型生成重写候选',
                    'iterations_used' => (int)($result['iterations_used'] ?? 0),
                    'iteration_history' => (array)($result['history'] ?? []),
                    'improvement_report' => $evaluation ?? null,
                    'diagnostics' => [
                        'controller_final_score' => is_numeric($result['final_score'] ?? null)
                            ? (float)$result['final_score']
                            : null,
                        'legacy_min_gain' => $this->minGain,
                        'legacy_gain_met' => null,
                    ],
                ];
            }

            addLog($this->novelId, 'rewrite', sprintf(
                '第 %d 章迭代重写候选已生成：%d 字符（%s），旧增益门槛诊断=%s，用时 %.0fms',
                $chNum,
                mb_strlen((string)$candidateContent),
                $candidateContent !== $rawContent ? '历史最优稿' : '末轮稿',
                $legacyGainMet === null ? '未评分' : ($legacyGainMet ? '达到' : '未达到'),
                (float)($result['execution_time_ms'] ?? 0)
            ));

            return [
                'generation_attempted' => true,
                // Deprecated compatibility alias: generated, not accepted.
                'rewritten' => true,
                'new_score' => $candidateScore,
                'content' => (string)$candidateContent,
                'generation_integrity' => $integrity,
                'message' => '迭代重写候选已生成，等待统一质量门判定',
                'iterations_used' => (int)($result['iterations_used'] ?? 0),
                'iteration_history' => (array)($result['history'] ?? []),
                'improvement_report' => $evaluation ?? null,
                'execution_time_ms' => (float)($result['execution_time_ms'] ?? 0),
                'diagnostics' => [
                    'controller_final_score' => is_numeric($result['final_score'] ?? null)
                        ? (float)$result['final_score']
                        : null,
                    'candidate_score' => $candidateScore,
                    'gain' => $gain,
                    'legacy_min_gain' => $this->minGain,
                    'legacy_gain_met' => $legacyGainMet,
                    'output_characters' => mb_strlen((string)$candidateContent),
                    'short_output' => mb_strlen(trim((string)$candidateContent)) < 200,
                    'candidate_source' => $candidateContent !== $rawContent ? 'iteration_best' : 'last_round',
                ],
            ];
        } catch (\Throwable $e) {
            error_log('RewriteAgent::performIterativeRefinement 失败：' . $e->getMessage());
            addLog($this->novelId, 'rewrite', '迭代改进异常：' . $e->getMessage());

            // 降级到单次重写
            return $this->performSingleRewrite($chapter, $content, $gateResults, $originalScore, $modelId);
        }
    }

    /**
     * 执行单次重写（兼容旧版）
     */
    private function performSingleRewrite(
        array $chapter,
        string $content,
        array $gateResults,
        float $originalScore,
        ?int $modelId
    ): array {
        $chNum = $chapter['chapter_number'] ?? 0;

        addLog($this->novelId, 'rewrite', sprintf(
            '第 %d 章使用单次重写模式',
            $chNum
        ));

        // 提取严重问题
        $criticalIssues = $this->extractCriticalIssues($gateResults);

        if (empty($criticalIssues)) {
            return [
                'generation_attempted' => false,
                'rewritten' => false,
                'new_score' => null,
                'content' => null,
                'message' => "没有找到严重问题，跳过重写",
                'iterations_used' => 0,
            ];
        }

        $issueText = implode("\n", $criticalIssues);
        $chapterTitle = $chapter['title'] ?? '';
        $outline = $chapter['outline'] ?? '';

        // 审计修复（2026-08-09 R11-3）：注入精简铁律，避免重写剥掉钩子/禁词
        $isFinal = false;
        try {
            $tc = (int)(DB::fetch('SELECT target_chapters FROM novels WHERE id=?', [$this->novelId])['target_chapters'] ?? 0);
            $isFinal = $tc > 0 && (int)$chNum >= $tc;
        } catch (\Throwable) { /* ignore */ }
        if (!function_exists('rewriteIronRulesSnippet')) {
            require_once dirname(__DIR__) . '/helpers.php';
        }
        $iron = rewriteIronRulesSnippet($isFinal);

        $system = <<<EOT
你是一位资深网文编辑，擅长将初稿改写成高质量章节。
按以下规则重写：
1. 只修正指出的问题，不要改动已经合格的段落
2. 保持原有的人物性格、情节走向、对话风格
3. 保持原有字数范围
4. {$iron}
5. 直接输出重写后的完整章节正文，不要加任何前缀或解释
EOT;

        $user = <<<EOT
请重写小说第{$chNum}章《{$chapterTitle}》。

【原大纲】{$outline}

【五关检测发现的问题（必须修正）】
{$issueText}

【原始章节正文】
{$content}

请输出重写后的完整章节正文：
EOT;

        $generationStarted = false;
        $collected = '';
        try {
            // H-10 修复（2026-07-25）：原用非流式 $ai->chat()，长章节易静默超时。
            // 与 IterativeRefinementController::applyImprovements 统一，改用 chatStream + collector。
            $ai = getAIClient($modelId);
            $generationStarted = true;
            $ai->chatStream([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ], function ($chunk) use (&$collected) {
                $collected .= $chunk;
            }, 'creative');
            ChapterGenerationIntegrity::assertComplete($ai->lastFinishReason ?? null);
            $rewritten = $collected;
            $normalized = trim($rewritten);
            $outputCharacters = mb_strlen($normalized);

            // This legacy score is diagnostic only. Empty/short output is not
            // discarded or locally accepted: WriteEngine persists the literal
            // attempt first and CanonicalQualityGate owns the decision.
            $newScore = $outputCharacters >= 200
                ? $this->quickQualityCheck($chapter, $normalized)
                : null;
            $gain = $newScore !== null
                ? round($newScore - $originalScore, 1)
                : null;
            $legacyGainMet = $gain !== null ? $gain >= $this->minGain : null;

            addLog($this->novelId, 'rewrite', sprintf(
                '第 %d 章单次重写候选已生成：%d 字符，旧增益门槛诊断=%s',
                $chNum,
                $outputCharacters,
                $legacyGainMet === null ? '未评分' : ($legacyGainMet ? '达到' : '未达到')
            ));

            return [
                'generation_attempted' => true,
                // Deprecated compatibility alias: generated, not accepted.
                'rewritten' => true,
                'new_score' => $newScore,
                'content' => $rewritten,
                'generation_integrity' => ['finish_reason' => $ai->lastFinishReason ?? null, 'generation_error' => null],
                'message' => '单次重写候选已生成，等待统一质量门判定',
                'iterations_used' => 1,
                'diagnostics' => [
                    'candidate_score' => $newScore,
                    'gain' => $gain,
                    'legacy_min_gain' => $this->minGain,
                    'legacy_gain_met' => $legacyGainMet,
                    'output_characters' => $outputCharacters,
                    'short_output' => $outputCharacters < 200,
                ],
            ];
        } catch (\Throwable $e) {
            error_log('RewriteAgent::performSingleRewrite 失败：' . $e->getMessage());
            if ($generationStarted) {
                // A thrown stream may still have yielded a partial body. Even
                // zero yielded bytes are a real model attempt and therefore a
                // Candidate for the canonical gate to quarantine explicitly.
                return [
                    'generation_attempted' => true,
                    'rewritten' => true,
                    'new_score' => null,
                    'content' => $collected,
                    'generation_integrity' => ['finish_reason' => $ai->lastFinishReason ?? null, 'generation_error' => $e->getMessage()],
                    'message' => '重写流异常，保留已生成候选：' . $e->getMessage(),
                    'iterations_used' => 1,
                    'diagnostics' => [
                        'generation_error' => $e->getMessage(),
                        'legacy_min_gain' => $this->minGain,
                        'legacy_gain_met' => null,
                        'output_characters' => mb_strlen(trim($collected)),
                        'short_output' => mb_strlen(trim($collected)) < 200,
                    ],
                ];
            }
            return [
                'generation_attempted' => false,
                'rewritten' => false,
                'new_score' => null,
                'content' => null,
                'message' => '重写异常：' . $e->getMessage(),
                'iterations_used' => 0,
            ];
        }
    }

    /**
     * 提取严重问题
     */
    private function extractCriticalIssues(array $gateResults): array
    {
        $criticalIssues = [];

        foreach ($gateResults as $gate) {
            $score = (float)($gate['score'] ?? 100);
            $name = $gate['name'] ?? '未知';

            if ($score < 60 && !empty($gate['issues'])) {
                $issues = is_array($gate['issues']) ? implode('；', array_slice($gate['issues'], 0, 3)) : (string)$gate['issues'];
                $criticalIssues[] = "【{$name}：{$score}分】{$issues}";
            }
        }

        return $criticalIssues;
    }

    /**
     * 准备章节基本信息
     *
     * 审计修复（2026-08-02 H8）：补入 chapter_words。
     * 原查询不含此列 → Gates::checkGate1_Structure 的 $ch['chapter_words'] 为未定义键
     * → 回退全局 ws_chapter_words（默认 2000）→ 重写评分基于错误的字数目标，
     * 与小说实际目标（可能 3000/4000）不符，导致合理字数的正文被误判扣分。
     */
    private function prepareChapterInfo(array $chapter): array
    {
        // 获取小说基本信息
        $novelInfo = DB::fetch(
            'SELECT title, genre, protagonist_name, chapter_words FROM novels WHERE id = ?',
            [$this->novelId]
        );

        // H8: 缓存小说级字数目标，供单次重写路径 quickQualityCheck 复用，避免重复查询
        $this->novelChapterWords = (int)($novelInfo['chapter_words'] ?? 0);

        return [
            'id' => $chapter['id'] ?? 0,
            'chapter_number' => $chapter['chapter_number'] ?? 0,
            'title' => $chapter['title'] ?? '',
            'outline' => $chapter['outline'] ?? '',
            'novel_title' => $novelInfo['title'] ?? '',
            'genre' => $novelInfo['genre'] ?? '都市',
            'protagonist_name' => $novelInfo['protagonist_name'] ?? '主角',
            // H8: 传递小说级字数目标给 Gate1；0/缺失时 Gate1 自身回退到全局 ws_chapter_words
            'chapter_words' => $this->novelChapterWords,
        ];
    }

    /**
     * 快速质量检测 — 只做五关中的纯PHP关
     */
    private function quickQualityCheck(array $chapter, string $content): float
    {
        try {
            // W-5 修复补全：与 IterativeRefinementController::runQuickFiveGateCheck 一致，
            // 改用 includes/quality/Gates.php，避免从 includes 上下文 require api/*.php——
            // 该文件顶层在 CLI_MODE 未定义时会执行 requireLoginApi() 并 exit()，
            // daemon/SSE 场景下会导致进程被杀死、postProcess 后续步骤全部跳过。
            require_once __DIR__ . '/../quality/Gates.php';

            // H8 修复：单次重写路径的 $chapter 来自外部调用方，通常不含 chapter_words。
            // Gate1 在 $ch['chapter_words'] 缺失时会回退到全局 ws_chapter_words（默认 2000），
            // 导致小说实际目标为 3000/4000 字的合理正文被误判"字数不足"扣 30 分。
            // 此处注入小说级字数目标：优先复用 prepareChapterInfo 的缓存，未缓存则懒加载。
            if (!isset($chapter['chapter_words']) || (int)$chapter['chapter_words'] <= 0) {
                if ($this->novelChapterWords === null) {
                    $novelInfo = DB::fetch(
                        'SELECT chapter_words FROM novels WHERE id = ?',
                        [$this->novelId]
                    );
                    $this->novelChapterWords = (int)($novelInfo['chapter_words'] ?? 0);
                }
                $chapter['chapter_words'] = $this->novelChapterWords;
            }

            $results = [];
            $results[] = checkGate1_Structure($chapter, $content);
            $results[] = checkGate2_Characters($this->novelId, $content);
            $results[] = checkGate3_Description(null, $content);
            $results[] = checkGate4_CoolPoint($content, $chapter['outline'] ?? null);
            $results[] = checkGate5_Consistency($chapter['id'] ?? 0, $this->novelId, $content);
            $results[] = checkGate6_OutlineAdherence($chapter, $content);

            $scores = array_column($results, 'score');
            return count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;
        } catch (\Throwable $e) {
            error_log("RewriteAgent::getConsistencyScore failed: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 直接执行单次重写（不经过阈值检查）
     *
     * @param array $chapter 章节信息
     * @param string $content 章节内容
     * @param array $issues 需要修正的问题列表
     * @param int|null $modelId 模型ID
     * @return array{success: bool, content: string|null, message: string}
     */
    public function forceRewrite(array $chapter, string $content, array $issues, ?int $modelId): array
    {
        if (empty($issues)) {
            return [
                'success' => false,
                'content' => null,
                'message' => '没有提供需要修正的问题',
            ];
        }

        $issueText = implode("\n", $issues);
        $chNum = $chapter['chapter_number'] ?? 0;
        $chapterTitle = $chapter['title'] ?? '';
        $outline = $chapter['outline'] ?? '';

        $system = <<<EOT
你是一位资深网文编辑，擅长将初稿改写成高质量章节。
按以下规则重写：
1. 只修正指出的问题，不要改动已经合格的段落
2. 保持原有的人物性格、情节走向、对话风格
3. 保持原有字数范围
4. 直接输出重写后的完整章节正文，不要加任何前缀或解释
EOT;

        $user = <<<EOT
请重写小说第{$chNum}章《{$chapterTitle}》。

【原大纲】{$outline}

【需要修正的问题】
{$issueText}

【原始章节正文】
{$content}

请输出重写后的完整章节正文：
EOT;

        try {
            // 审计修复（2026-08-17 P3）修复9：原用非流式 $ai->chat()，全章正文进 prompt
            // 有静默超时风险。照搬 performSingleRewrite 的 H-10 模式改为 chatStream +
            // collector，保留原 'creative' 温度档与返回值 trim 处理。
            $ai = getAIClient($modelId);
            $collected = '';
            $ai->chatStream([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ], function ($chunk) use (&$collected) {
                $collected .= $chunk;
            }, 'creative');
            ChapterGenerationIntegrity::assertComplete($ai->lastFinishReason ?? null);
            $rewritten = trim($collected);

            if (empty($rewritten) || mb_strlen($rewritten) < 200) {
                return [
                    'success' => false,
                    'content' => null,
                    'message' => '重写失败：AI返回内容过短（<200字）',
                ];
            }

            return [
                'success' => true,
                'content' => $rewritten,
                'message' => '重写成功',
            ];
        } catch (\Throwable $e) {
            error_log('RewriteAgent::forceRewrite 失败：' . $e->getMessage());
            return [
                'success' => false,
                'content' => null,
                'message' => '重写异常：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 获取配置参数
     */
    public function getConfiguration(): array
    {
        return [
            'threshold' => $this->threshold,
            'min_gain' => $this->minGain,
            'use_iterative_mode' => $this->useIterativeMode,
            'use_critic_agent' => $this->useCriticAgent,
            'iterative_config' => $this->refinementController->getConfiguration(),
        ];
    }

    /**
     * 动态调整配置
     */
    public function adjustConfiguration(array $newConfig): bool
    {
        try {
            if (isset($newConfig['threshold'])) {
                $this->threshold = max(50, min(100, (int)$newConfig['threshold']));
            }
            if (isset($newConfig['min_gain'])) {
                $this->minGain = max(1, min(30, (int)$newConfig['min_gain']));
            }
            if (isset($newConfig['use_iterative_mode'])) {
                $this->useIterativeMode = (bool)$newConfig['use_iterative_mode'];
            }
            if (isset($newConfig['use_critic_agent'])) {
                $this->useCriticAgent = (bool)$newConfig['use_critic_agent'];
            }

            if (isset($newConfig['iterative_config'])) {
                $this->refinementController->adjustConfiguration($newConfig['iterative_config']);
            }

            return true;
        } catch (\Throwable $e) {
            error_log('RewriteAgent::adjustConfiguration 失败：' . $e->getMessage());
            return false;
        }
    }

    /**
     * v1.7 PRO: 差分定位重写（Editor 诊断 → Polisher 局部重写）
     *
     * 与全章重写不同，此方法只重写有问题的段落，保留合格段落不变。
     * 更高效，且避免全章重写导致的风格漂移和信息丢失。
     *
     * @param array  $chapter     章节信息
     * @param string $content     章节正文
     * @param array  $gateResults 五关检测结果
     * @param int|null $modelId   AI模型ID
     * @return array{success:bool, content:?string, rewritten_paragraphs:int, message:string}
     */
    public function differentialRewrite(
        array $chapter,
        string $content,
        array $gateResults,
        ?int $modelId
    ): array {
        $chNum = $chapter['chapter_number'] ?? 0;

        // 第一步：Editor 诊断 — 找出有问题的段落编号
        $issues = $this->extractCriticalIssues($gateResults);
        if (empty($issues)) {
            return ['success' => false, 'content' => null, 'rewritten_paragraphs' => 0, 'message' => '无严重问题'];
        }

        $issueText = implode("\n", $issues);
        $chapterTitle = $chapter['title'] ?? '';
        $outline = $chapter['outline'] ?? '';

        // 将正文按段落切分
        $paragraphs = preg_split('/\n\s*\n/', $content);
        $paragraphs = array_values(array_filter($paragraphs, fn($p) => trim($p) !== ''));
        if (count($paragraphs) < 3) {
            // 段落太少，差分重写无意义
            return ['success' => false, 'content' => null, 'rewritten_paragraphs' => 0, 'message' => '段落过少，不适合差分重写'];
        }

        // 编号段落供 Editor 定位
        $numberedContent = '';
        foreach ($paragraphs as $i => $p) {
            $preview = mb_substr(trim($p), 0, 80);
            $numberedContent .= "[段落{$i}] {$preview}...\n";
        }

        $editorSys = "你是一位严格的网文编辑。请诊断以下章节中哪些段落存在问题（AI腔/逻辑矛盾/描写空洞/重复句式/对话失真）。\n"
            . "只标注确实有问题的段落编号，合格段落不要标注。";

        $editorUser = "【第{$chNum}章《{$chapterTitle}》】\n"
            . "【大纲】{$outline}\n\n"
            . "【五关检测发现的问题】\n{$issueText}\n\n"
            . "【段落列表】\n{$numberedContent}\n\n"
            . "请输出有问题的段落编号和具体问题。严格 JSON 数组格式：\n"
            . "[{\"paragraph\":2,\"issue\":\"AI腔严重，'不禁'出现3次\"},{\"paragraph\":5,\"issue\":\"逻辑矛盾，前文说主角受伤这里却轻松跳跃\"}]";

        try {
            $ai = getAIClient($modelId);
            $editorRaw = trim($ai->chat([
                ['role' => 'system', 'content' => $editorSys],
                ['role' => 'user',   'content' => $editorUser],
            ], 'structured'));

            // 解析 Editor 输出
            $diagnoses = $this->parseDiagnosesJson($editorRaw);
            if (empty($diagnoses)) {
                return ['success' => true, 'content' => null, 'rewritten_paragraphs' => 0, 'message' => 'Editor 诊断无需修改的段落'];
            }

            // 收集需要重写的段落编号
            $toRewrite = [];
            foreach ($diagnoses as $d) {
                $pIdx = (int)($d['paragraph'] ?? -1);
                if ($pIdx >= 0 && $pIdx < count($paragraphs)) {
                    $toRewrite[$pIdx] = $d['issue'] ?? '质量不达标';
                }
            }

            if (empty($toRewrite)) {
                return ['success' => true, 'content' => null, 'rewritten_paragraphs' => 0, 'message' => '无需修改的段落'];
            }

            // 第二步：Polisher 逐段重写
            // P1-6：循环前查一次 novels 表，避免 polishParagraph 每段重复查询
            $protagonist = '';
            try {
                $novel = DB::fetch('SELECT protagonist_name, writing_style FROM novels WHERE id=?', [$this->novelId]);
                $protagonist = $novel['protagonist_name'] ?? '';
            } catch (\Throwable $e) { error_log('RewriteAgent differentialRewrite novel fetch failed: ' . $e->getMessage()); }
            $rewrittenCount = 0;
            // 审计修复（2026-08-17 P3）修复10：待重写段数原无上限——Editor 可能标注
            // 大部分段落，逐段 AI 调用（每段 1 次）成本失控。上限 = max(5, 段落总数/3)，
            // 超出取前 N 段（保键），截断信息记日志并附在返回 message 中。
            $maxRewrite = max(5, intdiv(count($paragraphs), 3));
            $truncated = 0;
            if (count($toRewrite) > $maxRewrite) {
                $truncated = count($toRewrite) - $maxRewrite;
                addLog($this->novelId, 'rewrite', sprintf(
                    '第%d章差分重写：Editor 标注 %d 段超上限 %d，仅重写前 %d 段（跳过 %d 段）',
                    $chNum, count($toRewrite), $maxRewrite, $maxRewrite, $truncated
                ));
                $toRewrite = array_slice($toRewrite, 0, $maxRewrite, true);
            }
            foreach ($toRewrite as $pIdx => $issue) {
                // 修复：段落循环内检测取消标志，避免用户取消后仍继续烧 API（每段 1 次 AI 调用）。
                // refine() 主体在每轮开头检查，但差分重写逐段循环（可能 5-10 段）期间无法中断。
                if (class_exists('WriteEngine') && file_exists(WriteEngine::cancelFlagPath($this->novelId))) {
                    addLog($this->novelId, 'rewrite', '差分重写检测到取消标志，提前终止段落循环');
                    break;
                }
                $original = $paragraphs[$pIdx];
                $rewritten = $this->polishParagraph($original, $issue, $chapter, $modelId, $protagonist);
                if ($rewritten !== null && mb_strlen($rewritten) > 20) {
                    $paragraphs[$pIdx] = $rewritten;
                    $rewrittenCount++;
                }
            }

            if ($rewrittenCount === 0) {
                return ['success' => true, 'content' => null, 'rewritten_paragraphs' => 0, 'message' => 'Polisher 未能改善任何段落'];
            }

            // 重新拼接正文
            $newContent = implode("\n\n", $paragraphs);

            // 修复：差分重写后做质量回验，防止改坏的段落无声进入正文。
            // performSingleRewrite 有 quickQualityCheck 增益检查，差分重写原无此保护。
            $originalScore = $this->quickQualityCheck($chapter, $content);
            $newScore = $this->quickQualityCheck($chapter, $newContent);
            if ($newScore < $originalScore - 5) {
                addLog($this->novelId, 'rewrite', sprintf(
                    '第%d章差分重写后质量下降（%.1f → %.1f），放弃差分结果',
                    $chNum, $originalScore, $newScore
                ));
                return ['success' => false, 'content' => null, 'rewritten_paragraphs' => 0, 'message' => '差分重写后质量下降，已回退'];
            }

            addLog($this->novelId, 'rewrite', sprintf(
                '第%d章差分重写：修改%d/%d个段落',
                $chNum, $rewrittenCount, count($toRewrite)
            ));

            // 修复10：截断信息附在 message 中，供调用方感知未全量处理
            $truncationNote = $truncated > 0 ? "（另有{$truncated}段超单次上限未重写）" : '';
            return [
                'success'              => true,
                'content'              => $newContent,
                'rewritten_paragraphs' => $rewrittenCount,
                'message'              => "差分重写成功：修改{$rewrittenCount}个段落{$truncationNote}",
            ];
        } catch (\Throwable $e) {
            error_log('RewriteAgent::differentialRewrite 失败：' . $e->getMessage());
            return ['success' => false, 'content' => null, 'rewritten_paragraphs' => 0, 'message' => '差分重写异常：' . $e->getMessage()];
        }
    }

    /**
     * Polisher：重写单个段落
     */
    private function polishParagraph(string $paragraph, string $issue, array $chapter, ?int $modelId, string $protagonist = ''): ?string
    {
        $chNum = $chapter['chapter_number'] ?? 0;

        $sys = "你是网文精修编辑。请只修改以下段落中存在的问题，保持上下文风格一致。"
            . ($protagonist !== '' ? "主角：{$protagonist}。" : "")
            . "直接输出修改后的段落，不要加任何说明。";

        $user = "【问题诊断】{$issue}\n\n"
            . "【原段落】\n{$paragraph}\n\n"
            . "请修改上述段落，消除问题，保持与原文一致的叙事风格：";

        try {
            $ai = getAIClient($modelId);
            $rewritten = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user],
            ], 'creative'));

            if (mb_strlen($rewritten) < 20) return null;
            return $rewritten;
        } catch (\Throwable $e) {
            error_log('RewriteAgent::polishParagraph 失败：' . $e->getMessage());
            return null;
        }
    }

    /** 容错解析 Editor 诊断 JSON */
    private function parseDiagnosesJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            if (empty($data) || isset($data[0])) return $data;
            if (isset($data['issues']) && is_array($data['issues'])) return $data['issues'];
            if (isset($data['paragraphs']) && is_array($data['paragraphs'])) return $data['paragraphs'];
            return null;
        }
        $s = strpos($raw, '[');
        $e = strrpos($raw, ']');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }

    /**
     * 获取历史重写记录
     */
    public function getRewriteHistory(int $limit = 20): array
    {
        try {
            $chapters = DB::fetchAll(
                'SELECT id, chapter_number, title, quality_score, rewritten, iterations_used,
                        total_improvement, iterative_history, rewrite_time, created_at
                 FROM chapters
                 WHERE novel_id = ? AND rewritten = 1
                 ORDER BY chapter_number DESC
                 LIMIT ?',
                [$this->novelId, $limit]
            );

            return array_map(function ($chapter) {
                return [
                    'chapter_id' => $chapter['id'],
                    'chapter_number' => $chapter['chapter_number'],
                    'title' => $chapter['title'],
                    'final_score' => $chapter['quality_score'],
                    'iterations_used' => $chapter['iterations_used'] ?? 1,
                    'total_improvement' => $chapter['total_improvement'] ?? 0,
                    'iteration_history' => json_decode($chapter['iterative_history'] ?? '[]', true),
                    'rewrite_time' => $chapter['rewrite_time'],
                    'created_at' => $chapter['created_at'],
                ];
            }, $chapters ?: []);
        } catch (\Throwable $e) {
            error_log('RewriteAgent::getRewriteHistory 失败：' . $e->getMessage());
            return [];
        }
    }
}
