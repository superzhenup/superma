<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * CriticAgent — 读者视角章节质量评估
 *
 * 对比现有五关检测（作者规则视角）：
 *   五关问: 字数够吗？句子重复吗？爽点关键词有吗？
 *   Critic问: 读起来爽吗？想追读吗？有代入感吗？
 *
 * 生效论证：
 *   - 每次评分后写入 chapters.critic_scores JSON 字段
 *   - 任一项 < 6 时通过 AgentDirectives 写入下章改进指令
 *   - 可做 dashboard 展示评分曲线验证效果
 */
class CriticAgent
{
    private int $novelId;

    /** P1-2: 静态缓存，避免同章重复 AI 调用 */
    private static array $scoreCache = [];

    /** 评分维度 */
    private const DIMENSIONS = [
        'thrill'       => '爽感强度',
        'immersion'    => '代入感',
        'pacing'       => '节奏感',
        'freshness'    => '新鲜度',
        'read_next'    => '追读欲望',
    ];

    /** v1.11.8: 自动锚定配置 */
    private const ANCHOR_CONFIG = [
        'consecutive_high_threshold' => 5,    // 连续N章高分触发锚定
        'high_score_threshold'       => 8.5,  // 高分阈值（8.5/10）
        'anchor_adjustment'          => -1.0, // 锚定调整幅度
        'low_score_threshold'        => 5.5,  // 低分阈值
        'low_adjustment'             => 0.5,  // 低分调整幅度（防止过度惩罚）
    ];

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * v1.11.8: 检测历史分数分布异常
     *
     * 如果连续N章都是高分（如90+），说明LLM自评偏乐观，需要锚定。
     * 如果连续N章都是低分，可能是模型过于严格，需要微调。
     *
     * @return array{needs_anchor: bool, adjustment: float, reason: string}
     */
    public function detectScoreAnomaly(): array
    {
        try {
            // 获取最近N章的校准后分数
            $recentScores = \DB::fetchAll(
                'SELECT chapter_number, critic_scores, calibrated_critic_scores
                 FROM chapters
                 WHERE novel_id = ? AND status = "completed" AND critic_scores IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT ?',
                [$this->novelId, self::ANCHOR_CONFIG['consecutive_high_threshold']]
            );

            if (count($recentScores) < self::ANCHOR_CONFIG['consecutive_high_threshold']) {
                return ['needs_anchor' => false, 'adjustment' => 0, 'reason' => 'insufficient_data'];
            }

            // 提取平均分
            $avgs = [];
            foreach ($recentScores as $row) {
                $calibrated = json_decode($row['calibrated_critic_scores'] ?? '', true);
                if (isset($calibrated['avg'])) {
                    $avgs[] = (float)$calibrated['avg'];
                } else {
                    $scores = json_decode($row['critic_scores'] ?? '', true);
                    $avgs[] = (float)($scores['avg'] ?? 0);
                }
            }

            if (count($avgs) < self::ANCHOR_CONFIG['consecutive_high_threshold']) {
                return ['needs_anchor' => false, 'adjustment' => 0, 'reason' => 'insufficient_valid_scores'];
            }

            // 检测连续高分
            $allHigh = true;
            $allLow = true;
            foreach ($avgs as $avg) {
                if ($avg < self::ANCHOR_CONFIG['high_score_threshold']) $allHigh = false;
                if ($avg > self::ANCHOR_CONFIG['low_score_threshold']) $allLow = false;
            }

            if ($allHigh) {
                return [
                    'needs_anchor' => true,
                    'adjustment' => self::ANCHOR_CONFIG['anchor_adjustment'],
                    'reason' => 'consecutive_high_scores',
                    'detail' => sprintf('连续%d章平均分>%.1f，触发锚定', count($avgs), self::ANCHOR_CONFIG['high_score_threshold']),
                ];
            }

            if ($allLow) {
                return [
                    'needs_anchor' => true,
                    'adjustment' => self::ANCHOR_CONFIG['low_adjustment'],
                    'reason' => 'consecutive_low_scores',
                    'detail' => sprintf('连续%d章平均分<%.1f，可能模型过严', count($avgs), self::ANCHOR_CONFIG['low_score_threshold']),
                ];
            }

            return ['needs_anchor' => false, 'adjustment' => 0, 'reason' => 'normal_distribution'];

        } catch (\Throwable $e) {
            error_log('CriticAgent::detectScoreAnomaly 失败: ' . $e->getMessage());
            return ['needs_anchor' => false, 'adjustment' => 0, 'reason' => 'error'];
        }
    }

    /**
     * 读者视角评估一章（v1.10.3: 增加校准）
     *
     * 校准策略：相对评分 + 人工偏差修正
     * 1. 用 LLM 做相对评分（对比前一章 better/worse/same）
     * 2. 如果有人工评分，用偏差修正绝对分
     * 3. 校准后的分数写入 calibrated_critic_scores
     *
     * @return array{scores: array, avg: float, weak_dims: array, calibrated: array|null, relative: array|null}
     */
    public function review(string $content, array $context): array
    {
        // P1-2: 静态缓存，避免同章重复 AI 调用
        $cacheKey = 'review:' . md5($content . '|' . (string)($context['model_id'] ?? ''));
        if (isset(self::$scoreCache[$cacheKey])) {
            return self::$scoreCache[$cacheKey];
        }

        $truncated = mb_strlen($content) > 4000
            ? mb_substr($content, 0, 2000) . "\n……（中间省略）……\n" . mb_substr($content, -2000)
            : $content;

        $system = <<<EOT
你是一位资深网文读者，擅长从读者视角评估章节质量。
请阅读以下章节后给出分数（1-10分），每项给一句话原因。
只输出纯JSON，不要有任何前缀或解释。
EOT;

        $user = <<<EOT
书名：{$context['title']}
类型：{$context['genre']}
主角：{$context['protagonist_name']}
本章标题：{$context['chapter_title']}
本章大纲：{$context['outline']}

请从以下5个维度评分（1-10分）：

1. **爽感强度（thrill）** — 读完后有多爽？是否有"看爽了"的感觉
2. **代入感（immersion）** — 是否把自己代入主角视角？世界感强不强
3. **节奏感（pacing）** — 读起来流畅/拖沓/仓促？
4. **新鲜度（freshness）** — 是否觉得套路太熟悉？有无新意
5. **追读欲望（read_next）** — 读完想立刻追下一章吗？

章节正文：
{$truncated}

请输出严格JSON，格式如下：
{{"thrill":分数,"thrill_reason":"原因","immersion":分数,"immersion_reason":"原因","pacing":分数,"pacing_reason":"原因","freshness":分数,"freshness_reason":"原因","read_next":分数,"read_next_reason":"原因"}}
EOT;

        try {
            $ai       = getAIClient($context['model_id'] ?: null);
            $raw      = trim($ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            // 解析JSON
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
                $raw = $m[1];
            }
            $start = strpos($raw, '{');
            if ($start !== false) $raw = substr($raw, $start);

            $result = json_decode($raw, true);
            if (!is_array($result)) {
                error_log("CriticAgent::review JSON解析失败: " . json_last_error_msg() . " | raw: " . substr($raw, 0, 300));
                return $this->emptyResult();
            }

            $scores = [];
            $reasons = [];
            $missingDims = [];
            foreach (self::DIMENSIONS as $key => $label) {
                // 修复：原代码缺失维度时 (int)0 → max(1,...) = 1 分，把"缺失"当"极差"，
                // 拉低 avg 并制造虚假 weak_dims，触发不必要的重写与下章指令。
                // 改为：缺失时按中性 5 分处理（不低于 6 分阈值，不触发 weak_dims）。
                if (!isset($result[$key]) || !is_numeric($result[$key])) {
                    $scores[$key] = 5;
                    $reasons[$key] = 'AI未返回该维度评分，按中性处理';
                    $missingDims[] = $key;
                    continue;
                }
                $score = (int)$result[$key];
                $scores[$key] = max(1, min(10, $score));
                $reasons[$key] = (string)($result["{$key}_reason"] ?? '');
            }
            if (!empty($missingDims)) {
                error_log("CriticAgent::review 缺失维度评分: " . implode(',', $missingDims));
            }

            $avg = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

            // 识别弱项（< 6分）
            $weakDims = [];
            foreach ($scores as $key => $score) {
                if ($score < 6) {
                    $weakDims[] = [
                        'dim' => $key,
                        'label' => self::DIMENSIONS[$key],
                        'score' => $score,
                        'reason' => $reasons[$key] ?? '',
                    ];
                }
            }

            $result = [
                'scores'    => $scores,
                'reasons'   => $reasons,
                'avg'       => $avg,
                'weak_dims' => $weakDims,
            ];
            self::$scoreCache[$cacheKey] = $result; // P1-2: 缓存成功结果
            return $result;
        } catch (\Throwable $e) {
            error_log('CriticAgent::review 失败：' . $e->getMessage());
            return $this->emptyResult();
        }
    }

    /**
     * P1-4 优化：合并 review + reviewRelative 为单次 AI 调用
     *
     * 原 review 和 reviewRelative 分别发起 AI 调用，且都需要当前章节正文作为输入，
     * 存在重复传输与双倍延迟。本方法在单次 prompt 中同时请求绝对评分和相对评分，
     * 输出结构化 JSON 一次性返回两类结果。
     *
     * 兼容性：返回结构包含原 review 的所有字段（scores/reasons/avg/weak_dims）
     * 加上 relative 字段（better/worse/same）。调用方可按需取用。
     *
     * @param string $content      当前章节正文
     * @param string $prevContent  前一章正文（为空则不请求相对评分）
     * @param array  $context      上下文（title/genre/protagonist_name/chapter_title/outline/model_id）
     * @return array{scores:array,reasons:array,avg:float,weak_dims:array,relative:?array}
     */
    public function reviewCombined(string $content, string $prevContent, array $context): array
    {
        // P1-2: 静态缓存，避免同章重复 AI 调用
        $cacheKey = 'reviewCombined:' . md5($content . '|' . $prevContent . '|' . (string)($context['model_id'] ?? ''));
        if (isset(self::$scoreCache[$cacheKey])) {
            return self::$scoreCache[$cacheKey];
        }

        $truncated = mb_strlen($content) > 4000
            ? mb_substr($content, 0, 2000) . "\n……（中间省略）……\n" . mb_substr($content, -2000)
            : $content;

        $hasPrev = !empty($prevContent);
        $truncPrev = '';
        if ($hasPrev) {
            $truncPrev = mb_strlen($prevContent) > 2000
                ? mb_substr($prevContent, 0, 1000) . "\n...\n" . mb_substr($prevContent, -1000)
                : $prevContent;
        }

        $system = "你是一位资深网文读者，擅长从读者视角评估章节质量。"
            . "请阅读章节后给出分数（1-10分），每项给一句话原因。"
            . ($hasPrev ? "同时对比前一章，给出相对评价。" : '')
            . "只输出纯JSON，不要有任何前缀或解释。";

        $relativeInstr = $hasPrev
            ? "\n\n请同时对比本章与前一章，每个维度回答 better/worse/same。\n"
                . "前一章正文（摘要）：\n{$truncPrev}\n"
            : '';

        $user = "书名：{$context['title']}\n"
            . "类型：{$context['genre']}\n"
            . "主角：{$context['protagonist_name']}\n"
            . "本章标题：{$context['chapter_title']}\n"
            . "本章大纲：{$context['outline']}\n\n"
            . "请从以下5个维度评分（1-10分）：\n"
            . "1. **爽感强度（thrill）** — 读完后有多爽？是否有\"看爽了\"的感觉\n"
            . "2. **代入感（immersion）** — 是否把自己代入主角视角？世界感强不强\n"
            . "3. **节奏感（pacing）** — 读起来流畅/拖沓/仓促？\n"
            . "4. **新鲜度（freshness）** — 是否觉得套路太熟悉？有无新意\n"
            . "5. **追读欲望（read_next）** — 读完想立刻追下一章吗？\n\n"
            . "章节正文：\n{$truncated}\n"
            . $relativeInstr
            . "\n请输出严格JSON，格式如下：\n"
            . '{"thrill":分数,"thrill_reason":"原因","immersion":分数,"immersion_reason":"原因",'
            . '"pacing":分数,"pacing_reason":"原因","freshness":分数,"freshness_reason":"原因",'
            . '"read_next":分数,"read_next_reason":"原因"'
            . ($hasPrev
                ? ',"relative":{"thrill":"better/worse/same","immersion":"better/worse/same",'
                    . '"pacing":"better/worse/same","freshness":"better/worse/same","read_next":"better/worse/same"}'
                : '')
            . "}";

        try {
            $ai  = getAIClient($context['model_id'] ?: null);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
                $raw = $m[1];
            }
            $start = strpos($raw, '{');
            if ($start !== false) $raw = substr($raw, $start);

            $result = json_decode($raw, true);
            if (!is_array($result)) {
                error_log("CriticAgent::reviewCombined JSON解析失败: " . json_last_error_msg() . " | raw: " . substr($raw, 0, 300));
                return $this->emptyResult();
            }

            $scores = [];
            $reasons = [];
            $missingDims = [];
            foreach (self::DIMENSIONS as $key => $label) {
                // 修复：原代码缺失维度时 (int)0 → max(1,...) = 1 分，把"缺失"当"极差"，
                // 拉低 avg 并制造虚假 weak_dims，触发不必要的重写与下章指令。
                // 改为：缺失时按中性 5 分处理（不低于 6 分阈值，不触发 weak_dims）。
                if (!isset($result[$key]) || !is_numeric($result[$key])) {
                    $scores[$key] = 5;
                    $reasons[$key] = 'AI未返回该维度评分，按中性处理';
                    $missingDims[] = $key;
                    continue;
                }
                $score = (int)$result[$key];
                $scores[$key] = max(1, min(10, $score));
                $reasons[$key] = (string)($result["{$key}_reason"] ?? '');
            }
            if (!empty($missingDims)) {
                error_log("CriticAgent::review 缺失维度评分: " . implode(',', $missingDims));
            }

            $avg = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

            $weakDims = [];
            foreach ($scores as $key => $score) {
                if ($score < 6) {
                    $weakDims[] = [
                        'dim' => $key,
                        'label' => self::DIMENSIONS[$key],
                        'score' => $score,
                        'reason' => $reasons[$key] ?? '',
                    ];
                }
            }

            // 解析相对评分
            $relative = null;
            if ($hasPrev && isset($result['relative']) && is_array($result['relative'])) {
                $relative = [];
                foreach (self::DIMENSIONS as $key => $label) {
                    $v = strtolower(trim((string)($result['relative'][$key] ?? 'same')));
                    if (!in_array($v, ['better', 'worse', 'same'], true)) $v = 'same';
                    $relative[$key] = $v;
                }
            }

            $result = [
                'scores'    => $scores,
                'reasons'   => $reasons,
                'avg'       => $avg,
                'weak_dims' => $weakDims,
                'relative'  => $relative,
            ];
            self::$scoreCache[$cacheKey] = $result; // P1-2: 缓存成功结果
            return $result;
        } catch (\Throwable $e) {
            error_log('CriticAgent::reviewCombined 失败：' . $e->getMessage());
            return $this->emptyResult();
        }
    }

    /**
     * P1-2: 查询 reviewCombined 缓存（供调用方预检，命中则跳过 AI 调用）
     */
    public static function getCachedCombined(string $content, string $prevContent, array $context): ?array
    {
        $cacheKey = 'reviewCombined:' . md5($content . '|' . $prevContent . '|' . (string)($context['model_id'] ?? ''));
        return self::$scoreCache[$cacheKey] ?? null;
    }

    /**
     * v1.10.3: 校准绝对评分
     * v1.11.8: 增加自动锚定（分数分布异常检测）
     *
     * 三种校准叠加：
     * 1. 自动锚定：检测历史分数分布异常（连续高分/低分），自动调整
     * 2. 人工偏差修正：对比历史人工评分与系统评分的平均偏差
     * 3. 相对评分修正：如果相对评分显示某维度本章更好/更差，微调
     *
     * @param array $rawScores 原始 CriticAgent 绝对分
     * @param array|null $humanScores 人工评分（如有）
     * @param array|null $relative 相对评分 better/worse/same（如有）
     * @return array 校准后的分数组
     */
    public function calibrate(array $rawScores, ?array $humanScores = null, ?array $relative = null): array
    {
        $calibrated = $rawScores;

        // Step 0: v1.11.8 自动锚定（分数分布异常检测）
        $anomaly = $this->detectScoreAnomaly();
        $anchorAdjustment = 0;
        if ($anomaly['needs_anchor']) {
            $anchorAdjustment = $anomaly['adjustment'];
            error_log(sprintf(
                'CriticAgent: 自动锚定触发 - %s, adjustment=%.1f',
                $anomaly['reason'],
                $anchorAdjustment
            ));
        }

        // Step 1: 人工偏差修正
        if ($humanScores) {
            foreach (self::DIMENSIONS as $key => $label) {
                $sysScore = (float)($rawScores[$key] ?? 5);
                $humanScore = (float)($humanScores[$key] ?? $sysScore);
                $bias = $sysScore - $humanScore;
                $calibrated[$key] = max(1, min(10, round($sysScore - $bias * 0.5 + $anchorAdjustment, 1)));
            }
        } elseif ($anchorAdjustment !== 0) {
            // 无人工评分时，仅应用锚定调整
            foreach (self::DIMENSIONS as $key => $label) {
                $calibrated[$key] = max(1, min(10, round(($rawScores[$key] ?? 5) + $anchorAdjustment, 1)));
            }
        }

        // Step 2: 相对评分微调（幅度较小）
        if ($relative) {
            $delta = 0.5; // 每次 better/worse 的调整幅度
            foreach (self::DIMENSIONS as $key => $label) {
                $adj = match ($relative[$key] ?? 'same') {
                    'better' => $delta,
                    'worse'  => -$delta,
                    default  => 0,
                };
                if ($adj !== 0) {
                    $calibrated[$key] = max(1, min(10, round(($calibrated[$key] ?? 5) + $adj, 1)));
                }
            }
        }

        return $calibrated;
    }

    private function emptyResult(): array
    {
        return [
            'scores'    => [],
            'reasons'   => [],
            'avg'       => 0,
            'weak_dims' => [],
        ];
    }
}
