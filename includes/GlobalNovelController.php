<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * GlobalNovelController — 全书级控制器
 *
 * 工程控制论「层级控制」核心模块：
 *   补全当前系统的最高控制层——全书级（100章尺度）。
 *
 * 触发：每 20 章执行一次（可配置）
 *
 * 检测维度（5项全书级检查）：
 *   1. 主线对齐度 — 近 10 章内容是否偏离故事主线（LLM评估）
 *   2. 情绪曲线   — 全书情绪节奏是否健康
 *   3. 伏笔密度   — 待回收伏笔是否健康
 *   4. 角色平衡   — 是否有角色被长期边缘化
 *   5. 爽点节奏   — 爽点分布是否合理
 *
 * 所有检测结果写入 Agent 指令，影响后续章节写作。
 */

class GlobalNovelController
{
    /** @var int 全书级控制器触发间隔（章） */
    private const TRIGGER_INTERVAL = 20;

    /** @var int 主线对齐度 LLM 评估的回顾窗口（章） */
    private const MAINLINE_WINDOW = 10;

    /** @var int 情绪曲线检测窗口（章） */
    private const EMOTION_WINDOW = 20;

    /** @var int 角色边缘化阈值（章） */
    private const CHARACTER_MARGIN_THRESHOLD = 8;

    /** @var int 伏笔健康度告警阈值（章） */
    private const FORESHADOW_AGE_WARN = 25;

    /**
     * 判断是否应该触发全书级控制
     */
    public static function shouldTrigger(int $currentChapter): bool
    {
        return $currentChapter > 0 && $currentChapter % self::TRIGGER_INTERVAL === 0;
    }

    /**
     * 执行全书级调控
     *
     * @param int   $novelId        小说 ID
     * @param int   $currentChapter 当前章节号
     * @param int|null $modelId     首选模型 ID（用于 LLM 评估）
     * @return array{triggered: bool, checks: array, directives: int}
     */
    public static function regulate(int $novelId, int $currentChapter, ?int $modelId = null): array
    {
        if (!self::shouldTrigger($currentChapter)) {
            return ['triggered' => false, 'checks' => [], 'directives' => 0];
        }

        require_once __DIR__ . '/agents/AgentDirectives.php';

        $globalIssues = [];
        $checks = [];

        // 1. 主线对齐度（LLM评估，最重）
        $mainlineResult = self::checkMainlineAlignment($novelId, $currentChapter, $modelId);
        $checks['mainline_alignment'] = $mainlineResult;
        if (!empty($mainlineResult['issue'])) {
            $globalIssues[] = $mainlineResult['issue'];
        }

        // 2. 情绪曲线健康
        $emotionResult = self::checkEmotionCurveHealth($novelId, $currentChapter);
        $checks['emotion_curve'] = $emotionResult;
        if (!empty($emotionResult['issue'])) {
            $globalIssues[] = $emotionResult['issue'];
        }

        // 3. 伏笔密度健康
        $foreshadowResult = self::checkForeshadowingDensity($novelId, $currentChapter);
        $checks['foreshadowing'] = $foreshadowResult;
        if (!empty($foreshadowResult['issue'])) {
            $globalIssues[] = $foreshadowResult['issue'];
        }

        // 4. 角色平衡
        $characterResult = self::checkCharacterBalance($novelId, $currentChapter);
        $checks['character_balance'] = $characterResult;
        if (!empty($characterResult['issue'])) {
            $globalIssues[] = $characterResult['issue'];
        }

        // 5. 爽点节奏
        $coolPointResult = self::checkCoolPointRhythm($novelId, $currentChapter);
        $checks['cool_point_rhythm'] = $coolPointResult;
        if (!empty($coolPointResult['issue'])) {
            $globalIssues[] = $coolPointResult['issue'];
        }

        // 写入全书级 Agent 指令
        $directivesWritten = 0;
        if (!empty($globalIssues)) {
            $directiveText = "【全书级调控 · 第{$currentChapter}章触发】\n"
                . implode("\n", array_map(fn($i) => "· {$i}", $globalIssues));

            AgentDirectives::add(
                $novelId,
                $currentChapter + 1,
                'global',
                $directiveText,
                5,    // 影响下 5 章
                168   // 7 天过期
            );
            $directivesWritten = 1;

            addLog($novelId, 'info', sprintf(
                '全书级控制器触发：%d项问题，已写入全局指令',
                count($globalIssues)
            ));
        }

        return [
            'triggered'  => true,
            'checks'     => $checks,
            'directives' => $directivesWritten,
        ];
    }

    // ============================================================
    //  检查 1：主线对齐度（LLM评估）
    // ============================================================

    /**
     * 评估近 10 章内容与全书 story_arc 的对齐度
     * 偏离时返回拉回建议
     */
    private static function checkMainlineAlignment(int $novelId, int $currentChapter, ?int $modelId): array
    {
        try {
            $story = DB::fetch(
                'SELECT story_arc, act_division FROM story_outlines WHERE novel_id=?',
                [$novelId]
            );
            if (!$story || empty($story['story_arc'])) {
                return ['checked' => false, 'reason' => 'no_story_outline'];
            }

            $fromChapter = max(1, $currentChapter - self::MAINLINE_WINDOW + 1);
            $recentChapters = DB::fetchAll(
                'SELECT chapter_number, title, outline, chapter_summary
                 FROM chapters
                 WHERE novel_id=? AND chapter_number BETWEEN ? AND ? AND status="completed"
                 ORDER BY chapter_number ASC',
                [$novelId, $fromChapter, $currentChapter]
            );

            if (count($recentChapters) < 3) {
                return ['checked' => false, 'reason' => 'insufficient_chapters'];
            }

            $recentSummary = '';
            foreach ($recentChapters as $ch) {
                $summary = $ch['chapter_summary'] ?: $ch['outline'] ?: $ch['title'];
                $recentSummary .= "第{$ch['chapter_number']}章：{$summary}\n";
            }

            $storyArc = $story['story_arc'];
            $actDiv = json_decode($story['act_division'] ?? '{}', true);

            $actContext = '';
            $totalChapters = (int)(DB::fetch(
                'SELECT target_chapters FROM novels WHERE id=?', [$novelId]
            )['target_chapters'] ?? 100);
            $progress = $currentChapter / max(1, $totalChapters);

            if ($progress <= 0.25 && !empty($actDiv['act1'])) {
                $actContext = "当前应位于第一幕（建置期）：{$actDiv['act1']['theme']}";
            } elseif ($progress <= 0.75 && !empty($actDiv['act2'])) {
                $actContext = "当前应位于第二幕（对抗期）：{$actDiv['act2']['theme']}";
            } elseif ($progress > 0.75 && !empty($actDiv['act3'])) {
                $actContext = "当前应位于第三幕（收束期）：{$actDiv['act3']['theme']}";
            }

            require_once __DIR__ . '/ai.php';

            $score = null;
            $reason = '';
            $suggestion = '';

            withModelFallback(
                $modelId,
                function (AIClient $ai) use (
                    $storyArc, $actContext, $recentSummary, &$score, &$reason, &$suggestion
                ) {
                    $prompt = <<<PROMPT
你是一位严格的网文编辑。评估以下近章内容与全书主线的对齐度。

【全书主线】
{$storyArc}

{$actContext}

【近章内容摘要】
{$recentSummary}

请评估：以上近章内容与全书主线的对齐度（0-100分）。
评分标准：
- 90-100：完全对齐主线，每章都在推进核心故事
- 70-89：基本对齐，有些章节偏题但整体方向正确
- 50-69：明显偏离主线，开始写无关内容
- 0-49：严重偏离，核心故事被遗忘

输出格式（严格JSON）：
{"score":数值,"reason":"偏离原因（如果对齐则写'基本对齐'）","suggestion":"拉回建议（如果对齐则写空字符串）"}

只输出JSON：
PROMPT;

                    $raw = $ai->chat([
                        ['role' => 'user', 'content' => $prompt],
                    ], 'structured');

                    $data = json_decode($raw, true);
                    if ($data && isset($data['score'])) {
                        $score = (int)$data['score'];
                        $reason = $data['reason'] ?? '';
                        $suggestion = $data['suggestion'] ?? '';
                    }
                },
                null,
                'structured'
            );

            if ($score === null) {
                return ['checked' => false, 'reason' => 'llm_failed'];
            }

            $issue = null;
            if ($score < 50) {
                $issue = "【严重】主线对齐度仅{$score}分，核心故事已大幅偏离。{$reason}。{$suggestion}";
            } elseif ($score < 70) {
                $issue = "【警告】主线对齐度{$score}分，有偏离趋势。{$reason}。{$suggestion}";
            }

            return [
                'checked' => true,
                'score'   => $score,
                'reason'  => $reason,
                'issue'   => $issue,
            ];
        } catch (\Throwable $e) {
            error_log('GlobalNovelController::checkMainlineAlignment 失败：' . $e->getMessage());
            return ['checked' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    //  检查 2：情绪曲线健康
    // ============================================================

    private static function checkEmotionCurveHealth(int $novelId, int $currentChapter): array
    {
        try {
            $emotionColumnExists = false;
            try {
                $col = DB::fetch("SHOW COLUMNS FROM chapters LIKE 'emotion_score'");
                $emotionColumnExists = !empty($col);
            } catch (\Throwable $e) {
                // 列不存在时降级
            }

            if (!$emotionColumnExists) {
                // 用 quality_score 替代
                $scores = DB::fetchAll(
                    'SELECT chapter_number, quality_score AS emotion_score
                     FROM chapters WHERE novel_id=? AND quality_score IS NOT NULL
                     ORDER BY chapter_number DESC LIMIT ?',
                    [$novelId, self::EMOTION_WINDOW]
                );
            } else {
                $scores = DB::fetchAll(
                    'SELECT chapter_number, emotion_score, quality_score
                     FROM chapters WHERE novel_id=? AND (emotion_score IS NOT NULL OR quality_score IS NOT NULL)
                     ORDER BY chapter_number DESC LIMIT ?',
                    [$novelId, self::EMOTION_WINDOW]
                );
                // 对于 emotion_score 为空的，使用 quality_score
                foreach ($scores as &$s) {
                    if (!isset($s['emotion_score']) || $s['emotion_score'] === null) {
                        $s['emotion_score'] = $s['quality_score'] ?? null;
                    }
                }
                unset($s);
            }

            if (count($scores) < 5) {
                return ['checked' => false, 'reason' => 'insufficient_data'];
            }

            // 只用有分数的
            $validScores = array_filter($scores, fn($s) => isset($s['emotion_score']) && $s['emotion_score'] !== null);
            $validScores = array_values($validScores);

            if (count($validScores) < 5) {
                return ['checked' => false, 'reason' => 'insufficient_valid_scores'];
            }

            $avgEmotion = array_sum(array_column($validScores, 'emotion_score')) / count($validScores);
            $emotionValues = array_column($validScores, 'emotion_score');
            $variance = 0;
            foreach ($emotionValues as $v) {
                $variance += pow($v - $avgEmotion, 2);
            }
            $variance /= count($emotionValues);

            $issue = null;

            // 异常1：连续低位（最近5章平均 < 50）
            $recent5 = array_slice($validScores, 0, min(5, count($validScores)));
            $recentAvg = array_sum(array_column($recent5, 'emotion_score')) / count($recent5);
            $recentMax = max(array_column($recent5, 'emotion_score'));

            if ($recentAvg < 50 && $recentMax < 60) {
                $issue = "【情绪低谷】近5章情绪分均值{$recentAvg}，持续低位无起伏。" .
                    "建议下章引入危机/反转/爽点事件拉升情绪。";
            }

            // 异常2：方差过低（持平无起伏）
            if (!$issue && $variance < 100 && count($validScores) >= 8) {
                $issue = "【情绪持平】近" . count($validScores) . "章情绪方差仅" . round($variance, 1) .
                    "，缺乏起伏。建议增加情绪波动：铺垫→危机→释放的节奏。";
            }

            return [
                'checked'       => true,
                'avg_emotion'   => round($avgEmotion, 1),
                'variance'      => round($variance, 1),
                'recent_avg'    => round($recentAvg, 1),
                'sample_count'  => count($validScores),
                'issue'         => $issue,
            ];
        } catch (\Throwable $e) {
            error_log('GlobalNovelController::checkEmotionCurveHealth 失败：' . $e->getMessage());
            return ['checked' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    //  检查 3：伏笔密度健康
    // ============================================================

    private static function checkForeshadowingDensity(int $novelId, int $currentChapter): array
    {
        try {
            $pending = DB::fetchAll(
                'SELECT id, description, planted_chapter, last_mentioned_chapter, deadline_chapter
                 FROM foreshadowing_items
                 WHERE novel_id=? AND resolved_chapter IS NULL
                 ORDER BY planted_chapter ASC',
                [$novelId]
            );

            if (empty($pending)) {
                return ['checked' => true, 'pending_count' => 0, 'issue' => null];
            }

            $agedCount = 0;
            $forgottenCount = 0;
            $agedItems = [];
            $forgottenItems = [];

            foreach ($pending as $item) {
                $age = $currentChapter - (int)$item['planted_chapter'];
                $sinceLastMention = $currentChapter - max(
                    (int)($item['last_mentioned_chapter'] ?? $item['planted_chapter']),
                    (int)$item['planted_chapter']
                );

                if ($age > self::FORESHADOW_AGE_WARN) {
                    $agedCount++;
                    if ($age > 40) {
                        $agedItems[] = "「{$item['description']}」(埋{$age}章)";
                    }
                }

                if ($sinceLastMention > 15 && $age > 20) {
                    $forgottenCount++;
                    if ($sinceLastMention > 25) {
                        $forgottenItems[] = "「{$item['description']}」({$sinceLastMention}章未触动)";
                    }
                }
            }

            $issue = null;

            if (!empty($forgottenItems)) {
                $samples = array_slice($forgottenItems, 0, 3);
                $issue = "【伏笔遗忘风险】" . count($forgottenItems) . "条伏笔长期未触动：" .
                    implode('、', $samples) . "。" .
                    "建议本章用1-2句轻提醒其中1条伏笔，避免读者遗忘。";
            } elseif (!empty($agedItems) && count($pending) >= 5) {
                $samples = array_slice($agedItems, 0, 2);
                $issue = "【伏笔积压】{$agedCount}条伏笔埋藏超" . self::FORESHADOW_AGE_WARN . "章未回收：" .
                    implode('、', $samples) . "。" .
                    "建议考虑在近期章节开始回收部分伏笔。";
            }

            return [
                'checked'         => true,
                'pending_count'   => count($pending),
                'aged_count'      => $agedCount,
                'forgotten_count' => $forgottenCount,
                'issue'           => $issue,
            ];
        } catch (\Throwable $e) {
            error_log('GlobalNovelController::checkForeshadowingDensity 失败：' . $e->getMessage());
            return ['checked' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    //  检查 4：角色平衡
    // ============================================================

    private static function checkCharacterBalance(int $novelId, int $currentChapter): array
    {
        try {
            $characters = DB::fetchAll(
                'SELECT cc.id, cc.name, cc.last_updated_chapter,
                        COALESCE(nc.role_type, "minor") AS importance
                 FROM character_cards cc
                 LEFT JOIN novel_characters nc
                   ON cc.novel_id = nc.novel_id AND cc.name = nc.name
                 WHERE cc.novel_id=? AND cc.name IS NOT NULL
                 ORDER BY FIELD(COALESCE(nc.role_type,"minor"),"protagonist","major","minor","background"),
                          cc.last_updated_chapter ASC',
                [$novelId]
            );

            if (empty($characters)) {
                return ['checked' => false, 'reason' => 'no_characters'];
            }

            $marginalized = [];
            foreach ($characters as $char) {
                $lastAppearance = (int)($char['last_updated_chapter'] ?? 0);
                if ($lastAppearance <= 0) continue;

                $gap = $currentChapter - $lastAppearance;
                $importance = $char['importance'] ?? 'minor';

                if ($importance === 'protagonist' && $gap > self::CHARACTER_MARGIN_THRESHOLD * 0.5) {
                    $marginalized[] = [
                        'name' => $char['name'],
                        'gap' => $gap,
                        'importance' => '主角',
                    ];
                } elseif ($importance === 'major' && $gap > self::CHARACTER_MARGIN_THRESHOLD) {
                    $marginalized[] = [
                        'name' => $char['name'],
                        'gap' => $gap,
                        'importance' => '主要',
                    ];
                } elseif ($importance === 'minor' && $gap > self::CHARACTER_MARGIN_THRESHOLD * 1.5) {
                    $marginalized[] = [
                        'name' => $char['name'],
                        'gap' => $gap,
                        'importance' => '次要',
                    ];
                }
            }

            $issue = null;
            if (!empty($marginalized)) {
                $samples = array_slice($marginalized, 0, 3);
                $desc = implode('、', array_map(fn($m) =>
                    "{$m['name']}({$m['importance']}角色，{$m['gap']}章未出场)", $samples));

                if (count($marginalized) > 3) {
                    $desc .= "等" . count($marginalized) . "个角色";
                }

                $issue = "【角色边缘化】{$desc}。" .
                    "建议在近期章节安排至少1位角色出场，保持角色存在感。";
            }

            return [
                'checked'           => true,
                'total_characters'  => count($characters),
                'marginalized_count'=> count($marginalized),
                'marginalized'      => array_slice($marginalized, 0, 5),
                'issue'             => $issue,
            ];
        } catch (\Throwable $e) {
            error_log('GlobalNovelController::checkCharacterBalance 失败：' . $e->getMessage());
            return ['checked' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    //  检查 5：爽点节奏
    // ============================================================

    private static function checkCoolPointRhythm(int $novelId, int $currentChapter): array
    {
        try {
            $recentCoolPoints = DB::fetchAll(
                'SELECT chapter_number, cool_point_type
                 FROM chapters
                 WHERE novel_id=? AND cool_point_type IS NOT NULL AND cool_point_type != ""
                   AND chapter_number > ?
                 ORDER BY chapter_number DESC',
                [$novelId, max(0, $currentChapter - self::EMOTION_WINDOW)]
            );

            $allChaptersInWindow = DB::fetchAll(
                'SELECT chapter_number FROM chapters
                 WHERE novel_id=? AND chapter_number > ? AND status="completed"
                 ORDER BY chapter_number ASC',
                [$novelId, max(0, $currentChapter - self::EMOTION_WINDOW)]
            );

            $totalChapters = count($allChaptersInWindow);
            $coolCount = count($recentCoolPoints);

            if ($totalChapters < 5) {
                return ['checked' => false, 'reason' => 'insufficient_data'];
            }

            $density = $totalChapters > 0 ? $coolCount / $totalChapters : 0;
            $chaptersPerCool = $coolCount > 0 ? round($totalChapters / $coolCount, 1) : $totalChapters;

            $issue = null;

            // 间隔过大
            if ($chaptersPerCool > 5 && $totalChapters >= 8) {
                $issue = "【爽点稀薄】近{$totalChapters}章仅{$coolCount}个爽点，" .
                    "平均{$chaptersPerCool}章/爽点（健康范围3-5章/爽点）。" .
                    "建议下章加入打脸/突破/反转类爽点事件。";
            }

            // 检查连续无爽点
            if (!$issue && $coolCount > 0) {
                $coolChapters = array_column($recentCoolPoints, 'chapter_number');
                rsort($coolChapters);
                $maxGap = 0;
                $prevChapter = $currentChapter + 1;
                foreach ($coolChapters as $cp) {
                    $gap = $prevChapter - (int)$cp;
                    if ($gap > $maxGap) $maxGap = $gap;
                    $prevChapter = (int)$cp;
                }
                // 也检查最后一章到当前
                $lastGap = $currentChapter - end($coolChapters);
                if ($lastGap > $maxGap) $maxGap = $lastGap;

                if ($maxGap >= 6) {
                    $issue = "【爽点断层】最长{$maxGap}章无爽点，" .
                        "读者可能感到乏味。建议近期安排爽点事件。";
                }
            } elseif ($coolCount === 0 && $totalChapters >= 6) {
                $issue = "【爽点缺失】近{$totalChapters}章零爽点，" .
                    "建议立即加入至少1个打脸/突破/反转类爽点事件。";
            }

            return [
                'checked'           => true,
                'total_chapters'    => $totalChapters,
                'cool_count'        => $coolCount,
                'chapters_per_cool' => $chaptersPerCool,
                'issue'             => $issue,
            ];
        } catch (\Throwable $e) {
            error_log('GlobalNovelController::checkCoolPointRhythm 失败：' . $e->getMessage());
            return ['checked' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }
}
