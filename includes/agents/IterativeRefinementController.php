<?php
defined('APP_LOADED') or die('Direct access denied.');

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
    private float $minImprovementThreshold;
    private float $targetScore;
    private float $qualityDeclineThreshold;

    private array $iterationHistory = [];
    private array $improvementTrend = [];
    private array $evaluationCache = [];
    private array $performanceMetrics = [
        'total_api_calls' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'evaluation_time_ms' => 0,
        'rewrite_time_ms' => 0,
    ];

    public function __construct(int $novelId, int $chapterId = 0)
    {
        $this->novelId = $novelId;
        $this->chapterId = $chapterId;

        $this->loadConfiguration();
    }

    /**
     * 加载配置参数
     */
    private function loadConfiguration(): void
    {
        $this->maxIterations = (int)getSetting('iterative_refinement.max_iterations', 3, $this->novelId);
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
     * @return array{success: bool, final_content: string, iterations_used: int, total_improvement: float, history: array}
     */
    public function refine(string $initialContent, array $chapterInfo, array $context = []): array
    {
        $startTime = microtime(true);
        $currentContent = $initialContent;
        $currentScore = 0;
        $bestContent = $currentContent;
        $bestScore = 0;

        $this->iterationHistory = [];
        $this->improvementTrend = [];

        $iterationResults = [];

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $iterationStartTime = microtime(true);

            addLog($this->novelId, 'iterative_refine', sprintf(
                '第 %d/%d 轮迭代开始，当前进度：%.1f → 目标：%.1f',
                $iteration, $this->maxIterations, $currentScore, $this->targetScore
            ));

            // Step 1: 质量评估
            $evaluation = $this->evaluateQuality($currentContent, $chapterInfo, $context, $iteration);
            $currentScore = $evaluation['overall_score'];

            // 记录最佳内容
            if ($currentScore > $bestScore) {
                $bestScore = $currentScore;
                $bestContent = $currentContent;
            }

            // Step 2: 检查是否达到目标
            if ($currentScore >= $this->targetScore) {
                $iterationResults[] = [
                    'iteration' => $iteration,
                    'action' => 'target_reached',
                    'score' => $currentScore,
                    'stop_reason' => '已达到目标分数',
                ];
                addLog($this->novelId, 'iterative_refine', sprintf(
                    '第 %d 轮迭代完成，质量分数 %.1f 达到目标 %.1f，停止迭代',
                    $iteration, $currentScore, $this->targetScore
                ));
                break;
            }

            // Step 3: 问题识别
            $issues = $this->identifyIssues($currentContent, $chapterInfo, $evaluation);

            // Step 4: 生成改进建议
            $suggestions = $this->generateSuggestions($issues, $currentContent, $chapterInfo, $iteration);

            // Step 5: 应用改进
            $improvedContent = $this->applyImprovements($currentContent, $suggestions, $chapterInfo, $context);

            // Step 6: 验证改进效果
            $newEvaluation = $this->evaluateQuality($improvedContent, $chapterInfo, $context, $iteration, true);
            $newScore = $newEvaluation['overall_score'];
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
                $bestIdx = array_search($maxAfterScore, $afterScores);
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
            }
        }

        if ($bestScore > $finalScore) {
            $finalContent = $bestContent;
            $finalScore = $bestScore;
        }

        if (empty($finalContent) && !empty($initialContent)) {
            $finalContent = $initialContent;
            $finalScore = $currentScore;
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $totalImprovement = $bestScore - ($iterationResults[0]['before_score'] ?? 0);

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
        ];
    }

    /**
     * 质量评估 - 整合五关检测和 CriticAgent（带缓存优化）
     */
    private function evaluateQuality(string $content, array $chapterInfo, array $context, int $iteration, bool $isPostCheck = false): array
    {
        // 生成缓存键（基于内容哈希和检测模式）
        $cacheKey = md5($content . ($isPostCheck ? '_quick' : '_full'));

        // 检查缓存
        if (isset($this->evaluationCache[$cacheKey])) {
            $this->performanceMetrics['cache_hits']++;
            return $this->evaluationCache[$cacheKey];
        }

        $this->performanceMetrics['cache_misses']++;
        $evalStartTime = microtime(true);

        $results = [];

        // 五关检测
        if (!$isPostCheck) {
            $results = $this->runFiveGateCheck($chapterInfo, $content);
        } else {
            $results = $this->runQuickFiveGateCheck($chapterInfo, $content);
        }

        // 计算综合分数
        $scores = array_column($results, 'score');
        $gateScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

        // 读者视角评分（可选，降低频率）
        $criticScore = 0;
        if ($iteration == 1 || ($iteration == $this->maxIterations && !$isPostCheck)) {
            $this->performanceMetrics['total_api_calls']++;
            $criticScore = $this->getCriticScore($content, $chapterInfo, $context);
        }

        // 综合评分
        $overallScore = $criticScore > 0
            ? round($gateScore * 0.7 + $criticScore * 10 * 0.3, 1)
            : $gateScore;

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
            require_once __DIR__ . '/../../api/validate_consistency.php';

            $results = [];
            $results[] = checkGate1_Structure($chapterInfo, $content);
            $results[] = checkGate2_Characters($this->novelId, $content);
            $results[] = checkGate3_Description(null, $content);
            $results[] = checkGate4_CoolPoint($content, $chapterInfo['outline'] ?? null);
            $results[] = checkGate5_Consistency($chapterInfo['id'] ?? 0, $this->novelId, $content);

            return $results;
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::runFiveGateCheck 失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 运行快速五关检测（优化性能，跳过最耗时的一致性检测）
     */
    private function runQuickFiveGateCheck(array $chapterInfo, string $content): array
    {
        try {
            require_once __DIR__ . '/../../api/validate_consistency.php';

            $results = [];
            // 只运行纯PHP检测，跳过需要数据库查询的一致性检测
            $results[] = checkGate1_Structure($chapterInfo, $content);
            $results[] = checkGate2_Characters($this->novelId, $content);
            $results[] = checkGate3_Description(null, $content);
            $results[] = checkGate4_CoolPoint($content, $chapterInfo['outline'] ?? null);
            // 跳过 checkGate5_Consistency（最耗时，需要数据库查询）

            return $results;
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::runQuickFiveGateCheck 失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取读者视角评分
     */
    private function getCriticScore(string $content, array $chapterInfo, array $context): float
    {
        try {
            require_once __DIR__ . '/CriticAgent.php';
            $criticAgent = new CriticAgent($this->novelId);
            $reviewContext = array_merge([
                'title' => $chapterInfo['title'] ?? '未知书名',
                'genre' => $chapterInfo['genre'] ?? '都市',
                'protagonist_name' => $chapterInfo['protagonist_name'] ?? '主角',
                'chapter_title' => $chapterInfo['title'] ?? '',
                'outline' => $chapterInfo['outline'] ?? '',
            ], $context);

            $review = $criticAgent->review($content, $reviewContext);
            return $review['avg'] ?? 0;
        } catch (\Throwable $e) {
            error_log('IterativeRefinementController::getCriticScore 失败：' . $e->getMessage());
            return 0;
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
        if (!empty($evaluation['critic_score']) && isset($evaluation['evaluation']['weak_dims'])) {
            foreach ($evaluation['evaluation']['weak_dims'] as $dim) {
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
    private function applyImprovements(string $content, array $suggestions, array $chapterInfo, array $context): string
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

        $iteration = count($this->iterationHistory) + 1;
        $chNum = $chapterInfo['chapter_number'] ?? 0;
        $chapterTitle = $chapterInfo['title'] ?? '';
        $outline = $chapterInfo['outline'] ?? '';

        // v1.11.2 Bug #8 修复：拉取长篇关键上下文，避免重写时破坏全局一致性
        $contextSection = $this->buildContextSection((int)$chNum);

        $system = <<<EOT
你是一位资深网文编辑，擅长将初稿改写成高质量章节。
按以下规则重写：
1. 重点修正指出的问题，但不要改动已经合格的段落
2. 保持原有的人物性格、情节走向、对话风格
3. 保持原有字数范围（±10%）
4. 严格遵循下文提供的人物状态/情绪状态/POV/前情摘要等上下文
5. 直接输出重写后的完整章节正文，不要加任何前缀或解释
6. 这是第 {$iteration} 轮迭代改进，要有针对性但不要过度修改
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
            $ai = getAIClient($modelId);
            $rewritten = trim($ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ], 'creative'));

            if (empty($rewritten) || mb_strlen($rewritten) < 200) {
                addLog($this->novelId, 'iterative_refine', '警告：AI 返回内容过短，使用原始内容');
                return $content;
            }

            $origWords = countWords($content);
            $rewrittenWords = countWords($rewritten);

            // 统一使用动态容忍度计算（与 WriteEngine 保持一致）
            require_once dirname(__DIR__) . '/../prompt.php';
            $tol = calculateDynamicTolerance($origWords);
            $maxAllowed = $tol['max'];

            if ($rewrittenWords > $maxAllowed) {
                require_once dirname(__DIR__) . '/helpers.php';
                $rewritten = truncateToWordLimit($rewritten, $maxAllowed);
                $afterTrim = countWords($rewritten);
                addLog($this->novelId, 'iterative_refine', sprintf(
                    '重写后超字（%d字 → %d字，原始%d字，容忍度±%d），已自动压缩至 %d 字',
                    $rewrittenWords, $maxAllowed, $origWords, $tol['tolerance'], $afterTrim
                ));
            }

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
                $card = DB::fetch(
                    "SELECT realm, role, status, attributes FROM character_cards
                     WHERE novel_id = ? AND name = ? LIMIT 1",
                    [$this->novelId, $protagonist]
                );
                if ($card) {
                    $parts = [];
                    if (!empty($card['realm']))  $parts[] = "境界：{$card['realm']}";
                    if (!empty($card['role']))   $parts[] = "身份：{$card['role']}";
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

        // 检查边际收益是否递减（优化：改为OR逻辑，任一条件满足即终止）
        if (count($this->improvementTrend) >= 2) {
            $recentImprovement = end($this->improvementTrend);
            $prevImprovement = $this->improvementTrend[count($this->improvementTrend) - 2];

            // 改进幅度降至前轮的30%以下，或低于阈值的一半，任一满足即终止
            if ($recentImprovement < $prevImprovement * 0.3 || $recentImprovement < $this->minImprovementThreshold * 0.5) {
                $this->terminationReason = sprintf(
                    '边际收益递减（当前+%.1f，前轮+%.1f），停止迭代',
                    $recentImprovement,
                    $prevImprovement
                );
                return true;
            }
        }

        // 检查是否连续两轮改进都很小（修复：使用min而非max）
        if (count($this->improvementTrend) >= 2) {
            $lastTwoImprovements = array_slice($this->improvementTrend, -2);
            if (min($lastTwoImprovements) < $this->minImprovementThreshold * 0.5) {
                $this->terminationReason = sprintf(
                    '连续两轮改进均很小（%.1f和%.1f），停止迭代',
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
            $initialScore = $results[0]['before_score'] ?? 0;
            $finalScore = $results[count($results) - 1]['after_score'] ?? $initialScore;
            $iterationsUsed = count($results);
            $stopReason = $results[count($results) - 1]['stop_reason'] ?? '完成';

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
            if (isset($newConfig['max_iterations'])) {
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
     * 统一错误处理辅助方法
     *
     * @param callable $fn 要执行的函数
     * @param string $context 错误上下文描述
     * @param mixed $default 失败时的默认返回值
     * @return mixed 函数执行结果或默认值
     */
    private function safeExecute(callable $fn, string $context, $default = null)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $errorMsg = sprintf('%s 失败：%s', $context, $e->getMessage());
            error_log($errorMsg);
            addLog($this->novelId, 'error', $errorMsg);
            return $default;
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
