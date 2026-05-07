<?php
defined('APP_LOADED') or die('Direct access denied.');

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

    public function __construct(int $novelId, int $chapterId = 0)
    {
        $this->novelId = $novelId;
        $this->chapterId = $chapterId;

        $this->loadConfiguration();

        require_once __DIR__ . '/IterativeRefinementController.php';
        require_once __DIR__ . '/ImprovementEvaluator.php';

        $this->refinementController = new IterativeRefinementController($novelId, $chapterId);
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
     * @return array{rewritten: bool, new_score: float|null, content: string|null, message: string, iterations_used: int}
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

        // 检查是否需要重写
        if ($originalScore >= $this->threshold) {
            return [
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
            $context = [
                'model_id' => $modelId,
                'use_critic_agent' => $this->useCriticAgent,
            ];

            // 获取章节基本信息
            $chapterInfo = $this->prepareChapterInfo($chapter);

            // 合并五关检测结果到评估中
            $baselineEvaluation = $this->prepareBaselineEvaluation($gateResults);

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

            // 检查是否采纳重写
            $finalScore = $result['final_score'];
            $gain = round($finalScore - $originalScore, 1);

            if ($gain < $this->minGain) {
                addLog($this->novelId, 'rewrite', sprintf(
                    '第 %d 章迭代改进效果不佳：%.1f → %.1f（+%.1f 分），低于最低增益 %d 分，不采纳',
                    $chNum,
                    $originalScore,
                    $finalScore,
                    $gain,
                    $this->minGain
                ));

                return [
                    'rewritten' => false,
                    'new_score' => $finalScore,
                    'content' => null,
                    'message' => "迭代改进后得分 {$finalScore}（+{$gain} 分），低于最低增益 {$this->minGain} 分，不采纳",
                    'iterations_used' => $result['iterations_used'],
                    'iteration_history' => $result['history'],
                    'improvement_report' => $evaluation ?? null,
                ];
            }

            // 采纳重写
            $this->applyRewrittenContent($chapter, $result['final_content'], $finalScore);

            addLog($this->novelId, 'rewrite', sprintf(
                '第 %d 章迭代重写采纳：%.1f → %.1f（+%.1f 分），用时 %.0fms',
                $chNum,
                $originalScore,
                $finalScore,
                $gain,
                $result['execution_time_ms']
            ));

            return [
                'rewritten' => true,
                'new_score' => $finalScore,
                'content' => $result['final_content'],
                'message' => "迭代重写成功：{$originalScore} → {$finalScore}（+{$gain} 分）",
                'iterations_used' => $result['iterations_used'],
                'iteration_history' => $result['history'],
                'improvement_report' => $evaluation ?? null,
                'execution_time_ms' => $result['execution_time_ms'],
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

【五关检测发现的问题（必须修正）】
{$issueText}

【原始章节正文】
{$content}

请输出重写后的完整章节正文：
EOT;

        try {
            $ai = getAIClient($modelId);
            $rewritten = trim($ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ], 'creative'));

            if (empty($rewritten) || mb_strlen($rewritten) < 200) {
                return [
                    'rewritten' => false,
                    'new_score' => null,
                    'content' => null,
                    'message' => '重写失败：AI返回内容过短（<200字）',
                    'iterations_used' => 0,
                ];
            }

            // 快速五关检测重写后的内容
            $newScore = $this->quickQualityCheck($chapter, $rewritten);
            $gain = round($newScore - $originalScore, 1);

            if ($gain < $this->minGain) {
                return [
                    'rewritten' => false,
                    'new_score' => $newScore,
                    'content' => null,
                    'message' => "重写后得分 {$newScore}（提升 {$gain} 分），低于最低增益 {$this->minGain} 分，不采纳",
                    'iterations_used' => 1,
                ];
            }

            // 采纳重写
            $this->applyRewrittenContent($chapter, $rewritten, $newScore);

            addLog($this->novelId, 'rewrite', sprintf(
                '第 %d 章单次重写采纳：%.1f → %.1f（+%.1f 分）',
                $chNum,
                $originalScore,
                $newScore,
                $gain
            ));

            return [
                'rewritten' => true,
                'new_score' => $newScore,
                'content' => $rewritten,
                'message' => "重写成功：{$originalScore} → {$newScore}（+{$gain} 分）",
                'iterations_used' => 1,
            ];
        } catch (\Throwable $e) {
            error_log('RewriteAgent::performSingleRewrite 失败：' . $e->getMessage());
            return [
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
     */
    private function prepareChapterInfo(array $chapter): array
    {
        // 获取小说基本信息
        $novelInfo = DB::fetch(
            'SELECT title, genre, protagonist_name FROM novels WHERE id = ?',
            [$this->novelId]
        );

        return [
            'id' => $chapter['id'] ?? 0,
            'chapter_number' => $chapter['chapter_number'] ?? 0,
            'title' => $chapter['title'] ?? '',
            'outline' => $chapter['outline'] ?? '',
            'novel_title' => $novelInfo['title'] ?? '',
            'genre' => $novelInfo['genre'] ?? '都市',
            'protagonist_name' => $novelInfo['protagonist_name'] ?? '主角',
        ];
    }

    /**
     * 准备基准评估数据
     */
    private function prepareBaselineEvaluation(array $gateResults): array
    {
        $baseline = [
            'overall_score' => 0,
            'gate_results' => [],
            'weak_gates' => [],
        ];

        $scores = [];
        foreach ($gateResults as $gate) {
            $score = (float)($gate['score'] ?? 0);
            $scores[] = $score;
            $baseline['gate_results'][] = $gate;

            if ($score < 60) {
                $baseline['weak_gates'][] = [
                    'name' => $gate['name'] ?? '未知',
                    'score' => $score,
                    'issues' => $gate['issues'] ?? [],
                ];
            }
        }

        $baseline['overall_score'] = count($scores) > 0
            ? round(array_sum($scores) / count($scores), 1)
            : 0;

        return $baseline;
    }

    /**
     * 应用重写后的内容
     * v1.11.2 Bug #1 修复：重写后重新跑数据抽取流程
     */
    private function applyRewrittenContent(array $chapter, string $content, float $newScore): void
    {
        try {
            DB::update('chapters', [
                'content' => $content,
                'words' => countWords($content),
                'quality_score' => $newScore,
                'rewritten' => 1,
                'rewrite_time' => date('Y-m-d H:i:s'),
            ], 'id=?', [$chapter['id']]);

            // v1.11.2 Bug #1 修复：重写后重新提取记忆数据
            $this->reExtractMemoryData($chapter, $content);

        } catch (\Throwable $e) {
            error_log('RewriteAgent::applyRewrittenContent 失败：' . $e->getMessage());
        }
    }

    /**
     * v1.11.2 Bug #1 修复：重写后重新提取记忆数据
     *
     * 重写后的章节内容可能包含不同的人物、事件、伏笔等，
     * 需要重新调用 MemoryEngine 进行数据抽取
     */
    private function reExtractMemoryData(array $chapter, string $content): void
    {
        $chNum = $chapter['chapter_number'] ?? 0;
        if ($chNum < 1 || empty($content)) {
            return;
        }

        try {
            require_once __DIR__ . '/../memory/MemoryEngine.php';
            require_once __DIR__ . '/../../api/generate_summary.php';

            $engine = new MemoryEngine($this->novelId);

            // 获取小说信息
            $novelData = DB::fetch(
                'SELECT id, title, protagonist_name, protagonist_info, model_id FROM novels WHERE id=?',
                [$this->novelId]
            );
            if (!$novelData) {
                return;
            }

            // 生成新的摘要
            $summaryData = generateChapterSummary($novelData, $chapter, $content);
            if (empty($summaryData)) {
                // 降级：使用基本摘要
                $summaryData = [
                    'narrative_summary' => mb_substr($content, 0, 500),
                    'key_events' => [],
                    'character_updates' => [],
                    'foreshadowing' => [],
                ];
            }

            // 更新章节摘要
            if (!empty($summaryData['narrative_summary'])) {
                DB::update('chapters', [
                    'chapter_summary' => $summaryData['narrative_summary'],
                ], 'id=?', [$chapter['id']]);
            }

            // 重新注入记忆
            $ingest = $engine->ingestChapter((int)$chNum, $summaryData);

            addLog($this->novelId, 'rewrite', sprintf(
                '第 %d 章重写后记忆重新提取：人物%d / 事件%d / 伏笔+%d / 回收%d',
                $chNum,
                $ingest['cards_upserted'] ?? 0,
                $ingest['events_added'] ?? 0,
                $ingest['foreshadowing_added'] ?? 0,
                $ingest['foreshadowing_resolved'] ?? 0
            ));

        } catch (\Throwable $e) {
            error_log('RewriteAgent::reExtractMemoryData 失败：' . $e->getMessage());
            addLog($this->novelId, 'rewrite', '重写后记忆提取失败：' . $e->getMessage());
        }
    }

    /**
     * 快速质量检测 — 只做五关中的纯PHP关
     */
    private function quickQualityCheck(array $chapter, string $content): float
    {
        try {
            require_once __DIR__ . '/../../api/validate_consistency.php';

            $results = [];
            $results[] = checkGate1_Structure($chapter, $content);
            $results[] = checkGate2_Characters($this->novelId, $content);
            $results[] = checkGate3_Description(null, $content);
            $results[] = checkGate4_CoolPoint($content, $chapter['outline'] ?? null);
            $results[] = checkGate5_Consistency($chapter['id'] ?? 0, $this->novelId, $content);

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
            $ai = getAIClient($modelId);
            $rewritten = trim($ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ], 'creative'));

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
