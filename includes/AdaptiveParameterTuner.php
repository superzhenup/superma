<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * AdaptiveParameterTuner — 参数自适应调优器
 *
 * 工程控制论「自适应控制」核心模块：
 *   系统根据反馈自动调整自身参数，而非永远用固定默认值。
 *
 * 调优策略：贝叶斯思路（好结果对应参数被强化，差结果对应参数被弱化）
 *
 * 调优参数：
 *   1. rewrite.max_iterations     — 迭代改进最大轮数 (1-5)
 *   2. rewrite.target_score       — 目标质量分数 (60-100)
 *   3. rewrite.threshold          — 重写触发阈值 (50-100)
 *   4. rewrite.min_gain           — 最低质量提升 (1-30)
 *   5. Agent 触发间隔             — strategy/quality/optimization 周期
 *   6. chapter.word_tolerance     — 字数容差
 *
 * 触发时机：每 10 章执行一次（在 postProcess 中）
 *
 * 用法：
 *   $tuner = new AdaptiveParameterTuner($novelId);
 *   $tuner->tune($currentChapter);
 */

class AdaptiveParameterTuner
{
    private int $novelId;

    /** 需要至少这么多章数据才开始调参 */
    private const MIN_WINDOW = 10;

    /** 分析窗口（最近N章） */
    private const WINDOW = 20;

    /** 效果评级阈值 */
    private const IMPROVEMENT_THRESHOLD = 8.0;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 执行自适应调参
     *
     * @param int $currentChapter 当前章节号
     * @return array{tuned: bool, changes: array, recommendations: array}
     */
    public function tune(int $currentChapter): array
    {
        if ($currentChapter < self::MIN_WINDOW) {
            return ['tuned' => false, 'changes' => [], 'recommendations' => []];
        }

        $changes = [];
        $recommendations = [];

        try {
            $chapters = DB::fetchAll(
                'SELECT id, chapter_number, iterations_used, total_improvement,
                        quality_score, emotion_score, words,
                        critic_scores, calibrated_critic_scores
                 FROM chapters
                 WHERE novel_id = ? AND status = "completed"
                    AND iterations_used > 0
                 ORDER BY chapter_number DESC LIMIT ?',
                [$this->novelId, self::WINDOW]
            );

            if (count($chapters) < 5) {
                return ['tuned' => false, 'changes' => [], 'recommendations' => []];
            }

            // 1. 调优 max_iterations（找最佳迭代轮数）
            $iterChange = $this->tuneMaxIterations($chapters);
            if ($iterChange) $changes['max_iterations'] = $iterChange;

            // 2. 调优 target_score（基于质量分布）
            $targetChange = $this->tuneTargetScore($chapters);
            if ($targetChange) $changes['target_score'] = $targetChange;

            // 3. 调优 min_gain（基于平均提升）
            $minGainChange = $this->tuneMinGain($chapters);
            if ($minGainChange) $changes['min_gain'] = $minGainChange;

            // 4. 调优 threshold（基于触发率）
            $thresholdChange = $this->tuneThreshold($chapters);
            if ($thresholdChange) $changes['threshold'] = $thresholdChange;

            // 5. 生成人类可读建议
            $recommendations = $this->generateRecommendations($chapters, $changes);

            // 6. 更新全局设置（仅当有显著改善时）
            $applied = 0;
            foreach ($changes as $key => $change) {
                if (!empty($change['apply']) && $change['confidence'] >= 0.6) {
                    $this->applyChange($key, $change);
                    $applied++;
                }
            }

            // 7. 记录调参日志
            if ($applied > 0) {
                addLog($this->novelId, 'info', sprintf(
                    '参数自适应调优：应用%d项变更（共分析%d章）',
                    $applied, count($chapters)
                ));
            }

            return [
                'tuned'          => $applied > 0,
                'changes'        => $changes,
                'recommendations'=> $recommendations,
                'applied_count'  => $applied,
                'analyzed_chapters' => count($chapters),
            ];
        } catch (\Throwable $e) {
            error_log('AdaptiveParameterTuner::tune 失败：' . $e->getMessage());
            return ['tuned' => false, 'changes' => [], 'recommendations' => []];
        }
    }

    /**
     * 调优 max_iterations — 找最佳迭代轮数
     */
    private function tuneMaxIterations(array $chapters): ?array
    {
        $byIterations = [];
        foreach ($chapters as $ch) {
            $iters = (int)($ch['iterations_used'] ?? 0);
            if ($iters <= 0) continue;
            $improvement = (float)($ch['total_improvement'] ?? 0);
            $byIterations[$iters][] = $improvement;
        }

        if (count($byIterations) < 2) return null;

        $avgByIter = [];
        foreach ($byIterations as $iter => $imps) {
            $avgByIter[$iter] = count($imps) > 0
                ? array_sum($imps) / count($imps)
                : 0;
        }

        // 找收益最高的迭代次数
        arsort($avgByIter);
        $bestIter = array_key_first($avgByIter);
        $bestAvg = $avgByIter[$bestIter];

        // 当前全局设置
        $currentIter = (int)getSystemSetting('ir_max_iterations', 3, 'int');

        if ($bestIter !== $currentIter && $bestAvg > self::IMPROVEMENT_THRESHOLD) {
            return [
                'from'       => $currentIter,
                'to'         => $bestIter,
                'reason'     => "{$bestIter}轮迭代平均提升{$bestAvg}分，优于当前{$currentIter}轮",
                'confidence' => min(0.9, count($byIterations[$bestIter]) / 5),
                'apply'      => true,
            ];
        }

        return null;
    }

    /**
     * 调优 target_score — 基于质量分布
     */
    private function tuneTargetScore(array $chapters): ?array
    {
        $scores = array_map(fn($ch) => (float)($ch['quality_score'] ?? 0), $chapters);
        $scores = array_filter($scores, fn($s) => $s > 0);

        if (count($scores) < 5) return null;

        sort($scores);
        $p75 = $scores[(int)(count($scores) * 0.75)] ?? end($scores);
        $mean = array_sum($scores) / count($scores);

        $currentTarget = (float)getSystemSetting('ir_target_score', 80, 'float');

        // 如果75分位 < 当前target - 5，说明目标过高
        if ($p75 < $currentTarget - 5 && $currentTarget > 70) {
            $newTarget = max(70, round($mean + 2));
            return [
                'from'       => $currentTarget,
                'to'         => $newTarget,
                'reason'     => "75%章节达不到{$currentTarget}分目标，建议降至{$newTarget}分",
                'confidence' => 0.7,
                'apply'      => abs($currentTarget - $newTarget) >= 3,
            ];
        }

        // 如果均值 > 当前target + 3，可以适当提高目标
        if ($mean > $currentTarget + 3 && $currentTarget < 90) {
            $newTarget = min(90, round($mean));
            return [
                'from'       => $currentTarget,
                'to'         => $newTarget,
                'reason'     => "章节均值{$mean}分超当前目标{$currentTarget}分，可提高标准",
                'confidence' => 0.65,
                'apply'      => true,
            ];
        }

        return null;
    }

    /**
     * 调优 min_gain — 基于平均提升
     */
    private function tuneMinGain(array $chapters): ?array
    {
        $improvements = array_map(fn($ch) => (float)($ch['total_improvement'] ?? 0), $chapters);
        $improvements = array_filter($improvements, fn($i) => $i > 0);

        if (count($improvements) < 5) return null;

        $avgImp = array_sum($improvements) / count($improvements);
        $currentMinGain = (float)getSystemSetting('ir_min_improvement', 5.0, 'float');

        // 平均提升 < 当前 min_gain → 可能大量有效重写被拒绝
        if ($avgImp < $currentMinGain && $currentMinGain > 3) {
            $newMinGain = max(3, round($avgImp - 1, 1));
            return [
                'from'       => $currentMinGain,
                'to'         => $newMinGain,
                'reason'     => "平均提升{$avgImp}分低于最低增益{$currentMinGain}，可能有效重写被拒绝",
                'confidence' => 0.75,
                'apply'      => true,
            ];
        }

        // 平均提升远大于 min_gain，可以提高标准
        if ($avgImp > $currentMinGain * 2 && $currentMinGain < 15) {
            $newMinGain = min(15, round($avgImp * 0.6, 1));
            return [
                'from'       => $currentMinGain,
                'to'         => $newMinGain,
                'reason'     => "平均提升{$avgImp}分远超最低增益{$currentMinGain}，可适当提高标准减少无效重写",
                'confidence' => 0.6,
                'apply'      => abs($currentMinGain - $newMinGain) >= 3,
            ];
        }

        return null;
    }

    /**
     * 调优 threshold — 基于触发率
     */
    private function tuneThreshold(array $chapters): ?array
    {
        $currentThreshold = (float)getSystemSetting('ws_rewrite_threshold', 70, 'float');

        $allScores = DB::fetchAll(
            'SELECT quality_score FROM chapters
             WHERE novel_id = ? AND status = "completed" AND quality_score IS NOT NULL
             ORDER BY chapter_number DESC LIMIT 50',
            [$this->novelId]
        );

        if (count($allScores) < 10) return null;

        $belowThreshold = count(array_filter($allScores, fn($ch) =>
            (float)($ch['quality_score'] ?? 100) < $currentThreshold
        ));
        $triggerRate = $belowThreshold / count($allScores);

        // 触发率 > 50% → 阈值可能过高
        if ($triggerRate > 0.5 && $currentThreshold > 60) {
            $newThreshold = max(60, round($currentThreshold - 5));
            return [
                'from'       => $currentThreshold,
                'to'         => $newThreshold,
                'reason'     => "重写触发率" . round($triggerRate * 100) . "%过高，降低阈值减少无效重写",
                'confidence' => 0.7,
                'apply'      => true,
            ];
        }

        // 触发率 < 10% → 阈值可能过低
        if ($triggerRate < 0.1 && $currentThreshold < 80) {
            $newThreshold = min(80, round($currentThreshold + 5));
            return [
                'from'       => $currentThreshold,
                'to'         => $newThreshold,
                'reason'     => "重写触发率仅" . round($triggerRate * 100) . "%，可能漏掉需要改进的章节",
                'confidence' => 0.55,
                'apply'      => false, // 保守：只建议不自动应用
            ];
        }

        return null;
    }

    /**
     * 生成推荐报告
     */
    private function generateRecommendations(array $chapters, array $changes): array
    {
        $recs = [];

        $scores = array_column($chapters, 'quality_score');
        $scores = array_filter($scores, fn($s) => $s !== null);
        $avgScore = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;

        if ($avgScore >= 85) {
            $recs[] = [
                'type'    => 'success',
                'message' => "近章质量优秀（均分{$avgScore}），当前参数配置效果良好",
            ];
        } elseif ($avgScore < 65) {
            $recs[] = [
                'type'    => 'warning',
                'message' => "近章质量偏低（均分{$avgScore}），建议检查写作基础配置或模型选择",
            ];
        }

        // 检查迭代效率
        $iterChapters = array_filter($chapters, fn($c) => ($c['iterations_used'] ?? 0) > 0);
        if (count($iterChapters) >= 3) {
            $avgIters = array_sum(array_column($iterChapters, 'iterations_used')) / count($iterChapters);
            if ($avgIters > 3) {
                $recs[] = [
                    'type'    => 'info',
                    'message' => "平均迭代{$avgIters}轮，建议检查每轮改进是否真正产出增量价值",
                ];
            }
        }

        // 总结变更
        foreach ($changes as $param => $change) {
            if (!empty($change['apply'])) {
                $recs[] = [
                    'type'    => 'info',
                    'message' => "已自动调整{$param}：{$change['from']}→{$change['to']}（{$change['reason']}）",
                ];
            }
        }

        return $recs;
    }

    /**
     * 应用参数变更到数据库
     */
    private function applyChange(string $key, array $change): void
    {
        try {
            $settingMap = [
                'max_iterations' => ['ir_max_iterations', 'int'],
                'target_score'   => ['ir_target_score', 'float'],
                'min_gain'       => ['ir_min_improvement', 'float'],
                'threshold'      => ['ws_rewrite_threshold', 'int'],
            ];

            if (!isset($settingMap[$key])) return;

            [$settingKey, $type] = $settingMap[$key];
            $value = $change['to'];

            $exists = DB::fetch(
                'SELECT setting_key FROM system_settings WHERE setting_key = ?',
                [$settingKey]
            );
            if ($exists) {
                DB::update('system_settings', [
                    'setting_value' => (string)$value,
                ], 'setting_key = ?', [$settingKey]);
            } else {
                DB::insert('system_settings', [
                    'setting_key'   => $settingKey,
                    'setting_value' => (string)$value,
                ]);
            }
        } catch (\Throwable $e) {
            error_log("AdaptiveParameterTuner::applyChange({$key}) 失败：" . $e->getMessage());
        }
    }

    /**
     * 获取当前最优参数推荐（供UI展示）
     */
    public function getOptimalRecommendation(): array
    {
        try {
            $chapters = DB::fetchAll(
                'SELECT id, chapter_number, iterations_used, total_improvement,
                        quality_score, emotion_score, words,
                        critic_scores, calibrated_critic_scores
                 FROM chapters
                 WHERE novel_id = ? AND status = "completed"
                    AND iterations_used > 0
                 ORDER BY chapter_number DESC LIMIT ?',
                [$this->novelId, self::WINDOW]
            );

            if (count($chapters) < 5) {
                return ['has_data' => false, 'message' => '数据不足，至少需要5章有迭代记录'];
            }

            $changes = [];
            $iterChange = $this->tuneMaxIterations($chapters);
            if ($iterChange) $changes['max_iterations'] = $iterChange;
            $targetChange = $this->tuneTargetScore($chapters);
            if ($targetChange) $changes['target_score'] = $targetChange;
            $minGainChange = $this->tuneMinGain($chapters);
            if ($minGainChange) $changes['min_gain'] = $minGainChange;
            $thresholdChange = $this->tuneThreshold($chapters);
            if ($thresholdChange) $changes['threshold'] = $thresholdChange;

            // 不调用 applyChange，仅返回推荐
            return [
                'has_data' => true,
                'message'  => '基于历史数据的参数推荐已生成（仅预览，未实际应用）',
                'changes'  => $changes,
            ];
        } catch (\Throwable $e) {
            return ['has_data' => false, 'message' => $e->getMessage()];
        }
    }
}
