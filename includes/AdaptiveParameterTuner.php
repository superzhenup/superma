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
     * 审计修复（2026-07-19 H-11）：调参读写必须与执行组件同一存储。
     * 执行组件（IterativeRefinementController/RewriteAgent）通过 getSetting()
     * 读取 iterative_settings 表，调参器原先写入 novel_settings 的 ir_* 键
     * 无任何消费方，导致调参结果永不生效（开环）。
     */
    private function getExecSetting(string $dotKey, $default)
    {
        if (!function_exists('getSetting')) {
            require_once dirname(__FILE__) . '/data.php';
        }
        return function_exists('getSetting') ? getSetting($dotKey, $default, $this->novelId) : $default;
    }

    /** 单字段 JSON 合并 upsert 到 iterative_settings（保留同 setting_key 的其他子键） */
    private function setExecSetting(string $settingKey, string $subKey, $value): bool
    {
        $row = DB::fetch(
            'SELECT id, setting_value FROM iterative_settings WHERE novel_id = ? AND setting_key = ?',
            [$this->novelId, $settingKey]
        );
        $values = [];
        if ($row) {
            $decoded = json_decode((string)$row['setting_value'], true);
            if (is_array($decoded)) $values = $decoded;
        }
        $values[$subKey] = $value;
        $json = json_encode($values, JSON_UNESCAPED_UNICODE);

        if ($row) {
            DB::update('iterative_settings', [
                'setting_value' => $json,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id=?', [$row['id']]);
        } else {
            DB::insert('iterative_settings', [
                'novel_id'      => $this->novelId,
                'setting_key'   => $settingKey,
                'setting_value' => $json,
                'description'   => $settingKey === 'rewrite' ? '章节重写配置' : '迭代改进配置',
                'is_system'     => 1,
            ]);
        }

        // 修复：写入 iterative_settings 后失效 getSetting 请求级缓存（2026-07-22），
        // 避免同请求内执行组件继续读到调参前的旧值（data.php 按需加载，故做守卫）。
        if (function_exists('clearGetSettingCache')) {
            clearGetSettingCache();
        }
        return true;
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

            // 5. 更新小说级设置（仅当有显著改善时）。写入结果必须回填，
            // 不能在数据库拒绝写入时仍向日志/UI宣称“已应用”。
            $applied = 0;
            foreach ($changes as $key => $change) {
                if (!empty($change['apply']) && $change['confidence'] >= 0.6) {
                    $changes[$key]['applied'] = $this->applyChange($key, $change);
                    if ($changes[$key]['applied']) {
                        $applied++;
                    }
                }
            }

            // 6. 生成与真实持久化结果一致的人类可读建议
            $recommendations = $this->generateRecommendations($chapters, $changes);

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

        // 审计修复（2026-07-19 H-11）：读取执行组件实际使用的 iterative_settings 存储
        $currentIter = (int)$this->getExecSetting('iterative_refinement.max_iterations', 3);

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

        // 审计修复（2026-07-19 H-11）：读取执行组件实际使用的 iterative_settings 存储
        $currentTarget = (float)$this->getExecSetting('iterative_refinement.target_score', 80);

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
        // 审计修复（2026-07-19 H-11）：读取执行组件实际使用的 iterative_settings 存储
        $currentMinGain = (float)$this->getExecSetting('iterative_refinement.min_improvement', 5.0);

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
        // 审计修复（2026-07-19 H-11）：读取执行组件实际使用的 iterative_settings 存储
        $currentThreshold = (float)$this->getExecSetting('rewrite.threshold', 70);

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
            if (!empty($change['applied'])) {
                $recs[] = [
                    'type'    => 'info',
                    'message' => "已自动调整{$param}：{$change['from']}→{$change['to']}（{$change['reason']}）",
                ];
            } elseif (!empty($change['apply']) && ($change['confidence'] ?? 0) >= 0.6) {
                $recs[] = [
                    'type'    => 'warning',
                    'message' => "{$param} 建议未能保存：{$change['from']}→{$change['to']}，本次继续使用原配置",
                ];
            }
        }

        return $recs;
    }

    /**
     * 应用参数变更到数据库
     */
    private function applyChange(string $key, array $change): bool
    {
        try {
            // 审计修复（2026-07-19 H-11）：写入执行组件实际读取的 iterative_settings 表
            // （键空间与 getSetting('iterative_refinement.*'/'rewrite.*') 对齐），
            // 仍为小说级行，不污染其他小说
            $settingMap = [
                'max_iterations' => ['iterative_refinement', 'max_iterations', 'int'],
                'target_score'   => ['iterative_refinement', 'target_score', 'float'],
                'min_gain'       => ['iterative_refinement', 'min_improvement', 'float'],
                'threshold'      => ['rewrite', 'threshold', 'int'],
            ];

            if (!isset($settingMap[$key])) return false;

            [$settingKey, $subKey, $type] = $settingMap[$key];
            $value = $type === 'int' ? (int)$change['to'] : (float)$change['to'];

            if (!$this->setExecSetting($settingKey, $subKey, $value)) {
                error_log("AdaptiveParameterTuner::applyChange({$key}) 写入小说级配置失败");
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log("AdaptiveParameterTuner::applyChange({$key}) 失败：" . $e->getMessage());
            return false;
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
