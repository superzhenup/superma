<?php
/**
 * 角色情绪状态仓库
 *
 * 管理角色跨章节的情绪状态，确保情绪连续性
 *
 * @package NovelWritingSystem\Memory
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

class CharacterEmotionRepo
{
    private int $novelId;

    /** @var string[] 情绪状态枚举 */
    public const EMOTION_STATES = [
        'happy', 'angry', 'sad', 'tense', 'neutral',
        'fearful', 'determined', 'melancholy', 'excited', 'confused',
        'hopeful', 'desperate', 'calm', 'anxious', 'proud',
    ];

    /** @var array 情绪状态中文映射 */
    public const EMOTION_LABELS = [
        'happy'      => '高兴',
        'angry'      => '愤怒',
        'sad'        => '悲伤',
        'tense'      => '紧张',
        'neutral'    => '平静',
        'fearful'    => '恐惧',
        'determined' => '坚定',
        'melancholy' => '忧郁',
        'excited'    => '兴奋',
        'confused'   => '困惑',
        'hopeful'    => '希望',
        'desperate'  => '绝望',
        'calm'       => '镇定',
        'anxious'    => '焦虑',
        'proud'      => '骄傲',
    ];

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 插入角色情绪记录
     *
     * @param string $characterName 角色名
     * @param int    $chapterNumber 章节号
     * @param array  $emotion       情绪数据
     *   - state: string 情绪状态
     *   - intensity: int 0-100 强度
     *   - cause: string 原因
     *   - expected_decay: int 预期持续章节数
     */
    public function insertEmotion(string $characterName, int $chapterNumber, array $emotion): int
    {
        $state = $emotion['state'] ?? 'neutral';
        if (!in_array($state, self::EMOTION_STATES)) {
            $state = 'neutral';
        }

        $intensity = (int)($emotion['intensity'] ?? 50);
        $intensity = max(0, min(100, $intensity));

        $cause = trim($emotion['cause'] ?? '');
        $decay = (int)($emotion['expected_decay'] ?? 3);
        $decay = max(1, min(20, $decay));

        return DB::insert('character_emotion_history', [
            'novel_id'                => $this->novelId,
            'character_name'          => $characterName,
            'chapter_number'          => $chapterNumber,
            'emotion_state'           => $state,
            'intensity'               => $intensity,
            'cause'                   => $cause ?: null,
            'expected_decay_chapters' => $decay,
        ]);
    }

    /**
     * 批量插入角色情绪
     */
    public function insertBatch(int $chapterNumber, array $emotions): int
    {
        $count = 0;
        foreach ($emotions as $e) {
            if (empty($e['name'])) continue;
            try {
                $this->insertEmotion($e['name'], $chapterNumber, $e);
                $count++;
            } catch (\Throwable $ex) {
                error_log("CharacterEmotionRepo::insertBatch failed for {$e['name']}: " . $ex->getMessage());
            }
        }
        return $count;
    }

    /**
     * 获取某角色在指定章节之前的最新情绪状态
     */
    public function getLatestEmotion(string $characterName, int $beforeChapter): ?array
    {
        $row = DB::fetch(
            "SELECT * FROM character_emotion_history
             WHERE novel_id = ? AND character_name = ? AND chapter_number < ?
             ORDER BY chapter_number DESC
             LIMIT 1",
            [$this->novelId, $characterName, $beforeChapter]
        );

        if (!$row) return null;

        return $this->hydrate($row);
    }

    /**
     * 获取某角色在指定章节的情绪状态（精确匹配章节号）
     */
    public function getEmotionForChapter(string $characterName, int $chapterNumber): ?array
    {
        $row = DB::fetch(
            "SELECT * FROM character_emotion_history
             WHERE novel_id = ? AND character_name = ? AND chapter_number = ?
             LIMIT 1",
            [$this->novelId, $characterName, $chapterNumber]
        );

        if (!$row) return null;

        return $this->hydrate($row);
    }

    /**
     * 获取某章节所有角色的情绪状态
     */
    public function getEmotionsForChapter(int $chapterNumber): array
    {
        // 获取该章节记录的情绪，如果没有则获取最近的有效情绪
        $rows = DB::fetchAll(
            "SELECT ceh.* FROM character_emotion_history ceh
             INNER JOIN (
                 SELECT character_name, MAX(chapter_number) as max_ch
                 FROM character_emotion_history
                 WHERE novel_id = ? AND chapter_number <= ?
                 GROUP BY character_name
             ) latest ON ceh.character_name = latest.character_name
                      AND ceh.chapter_number = latest.max_ch
             WHERE ceh.novel_id = ?
             ORDER BY ceh.intensity DESC",
            [$this->novelId, $chapterNumber, $this->novelId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * 获取某角色在指定范围内的情绪历史
     */
    public function getEmotionHistory(string $characterName, int $fromChapter, int $toChapter): array
    {
        $rows = DB::fetchAll(
            "SELECT * FROM character_emotion_history
             WHERE novel_id = ? AND character_name = ?
               AND chapter_number BETWEEN ? AND ?
             ORDER BY chapter_number ASC",
            [$this->novelId, $characterName, $fromChapter, $toChapter]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * 检测情绪连续性问题
     *
     * 返回异常跳变的角色列表
     */
    public function checkContinuity(int $currentChapter): array
    {
        $issues = [];

        try {
            // 获取核心角色的最近情绪
            // v1.11.2 Bug #7 修复：传 currentChapter 启用「近 5 章高频出场」精细化逻辑
            $coreCharacters = $this->getCoreCharacters($currentChapter);

            foreach ($coreCharacters as $charName) {
                $prev = $this->getLatestEmotion($charName, $currentChapter);
                if (!$prev) continue;

                // 检查情绪强度是否有剧烈变化（高强度的突然消失）
                $intensity = $prev['intensity'];
                $decay = $prev['expected_decay_chapters'] ?? 3;
                $chaptersPassed = $currentChapter - $prev['chapter_number'];

                // 如果高强度情绪在预期衰减期内消失，可能是连续性问题
                if ($intensity >= 70 && $chaptersPassed <= $decay) {
                    $issues[] = [
                        'character'       => $charName,
                        'prev_state'      => $prev['emotion_state'],
                        'prev_intensity'  => $intensity,
                        'cause'           => $prev['cause'],
                        'chapters_passed' => $chaptersPassed,
                        'expected_decay'  => $decay,
                        'message'         => sprintf(
                            '%s 在第 %d 章有高强度「%s」情绪（强度%d），预期持续 %d 章，已过 %d 章',
                            $charName, $prev['chapter_number'],
                            self::EMOTION_LABELS[$prev['emotion_state']] ?? $prev['emotion_state'],
                            $intensity, $decay, $chaptersPassed
                        ),
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('CharacterEmotionRepo::checkContinuity failed: ' . $e->getMessage());
        }

        return $issues;
    }

    /**
     * 生成情绪连续性指令
     */
    public function buildContinuityDirective(int $currentChapter): ?string
    {
        $issues = $this->checkContinuity($currentChapter);

        if (empty($issues)) return null;

        // 取最重要的 3 个角色
        $topIssues = array_slice($issues, 0, 3);

        $lines = ["【角色情绪延续提醒】"];

        foreach ($topIssues as $issue) {
            $stateLabel = self::EMOTION_LABELS[$issue['prev_state']] ?? $issue['prev_state'];
            $lines[] = sprintf(
                "· %s：上章处于「%s」状态（强度%d）—— %s",
                $issue['character'],
                $stateLabel,
                $issue['prev_intensity'],
                $issue['cause'] ?: '原因未记录'
            );
        }

        $lines[] = "";
        $lines[] = "本章这些角色出场时，情绪应：";
        $lines[] = "1. 自然延续上章状态（如愤怒者仍带有余怒）";
        $lines[] = "2. 或通过具体事件合理转化（如被安慰后转为平静）";
        $lines[] = "3. 不得无故重置（如愤怒者突然变得开心）";

        return implode("\n", $lines);
    }

    /**
     * 检测情绪异常跳变（事后检测）
     *
     * 对比上章和本章的情绪状态，检测不合理的突变：
     *   1. 上章高强度情绪，本章状态完全相反且无过渡
     *   2. 上章高强度情绪，本章该角色完全消失（无记录）但间隔太短
     *
     * @param int $currentChapter 当前章节号
     * @return array 异常列表
     */
    public function detectEmotionAnomalies(int $currentChapter): array
    {
        $anomalies = [];

        try {
            $coreCharacters = $this->getCoreCharacters($currentChapter);

            $oppositeStates = [
                'angry'      => ['happy', 'calm', 'excited'],
                'sad'        => ['happy', 'excited'],
                'tense'      => ['calm', 'neutral'],
                'fearful'    => ['happy', 'calm', 'proud'],
                'desperate'  => ['happy', 'excited', 'hopeful'],
                'determined' => ['confused', 'fearful'],
                'melancholy' => ['happy', 'excited'],
                'anxious'    => ['calm'],
            ];

            foreach ($coreCharacters as $charName) {
                $prev = $this->getLatestEmotion($charName, $currentChapter);
                $curr = $this->getEmotionForChapter($charName, $currentChapter);

                if (!$prev) continue;

                if ($curr) {
                    $charAnomaly = null;
                    if ($prev['intensity'] >= 70 && isset($oppositeStates[$prev['emotion_state']])) {
                        if (in_array($curr['emotion_state'], $oppositeStates[$prev['emotion_state']])) {
                            $prevLabel = self::EMOTION_LABELS[$prev['emotion_state']] ?? $prev['emotion_state'];
                            $currLabel = self::EMOTION_LABELS[$curr['emotion_state']] ?? $curr['emotion_state'];
                            $charAnomaly = [
                                'character' => $charName,
                                'severity'  => $prev['intensity'] >= 85 ? 'high' : 'medium',
                                'prev_state'    => $prev['emotion_state'],
                                'prev_intensity' => $prev['intensity'],
                                'curr_state'    => $curr['emotion_state'],
                                'curr_intensity' => $curr['intensity'],
                                'message'   => "{$charName} 情绪从「{$prevLabel}」（强度{$prev['intensity']}）突变为「{$currLabel}」（强度{$curr['intensity']}），缺乏过渡",
                                'suggestion' => "请确保有合理的情绪转化事件（如被安慰/突发变故），否则应延续上章情绪余韵",
                            ];
                        }
                    }

                    if (!$charAnomaly) {
                        $intensityDrop = $prev['intensity'] - $curr['intensity'];
                        if ($prev['intensity'] >= 80 && $intensityDrop >= 50) {
                            $prevLabel = self::EMOTION_LABELS[$prev['emotion_state']] ?? $prev['emotion_state'];
                            $charAnomaly = [
                                'character' => $charName,
                                'severity'  => 'medium',
                                'prev_state'    => $prev['emotion_state'],
                                'prev_intensity' => $prev['intensity'],
                                'curr_state'    => $curr['emotion_state'],
                                'curr_intensity' => $curr['intensity'],
                                'message'   => "{$charName} 情绪强度骤降{$intensityDrop}点（{$prevLabel} {$prev['intensity']}→" . (self::EMOTION_LABELS[$curr['emotion_state']] ?? $curr['emotion_state']) . " {$curr['intensity']}）",
                                'suggestion' => "高强度情绪不应瞬间消失，应保留余韵或安排明确化解事件",
                            ];
                        }
                    }

                    if ($charAnomaly) $anomalies[] = $charAnomaly;
                } else {
                    $chaptersSincePrev = $currentChapter - $prev['chapter_number'];
                    if ($prev['intensity'] >= 70 && $chaptersSincePrev <= $prev['expected_decay_chapters']) {
                        $prevLabel = self::EMOTION_LABELS[$prev['emotion_state']] ?? $prev['emotion_state'];
                        $anomalies[] = [
                            'character' => $charName,
                            'severity'  => 'low',
                            'prev_state'    => $prev['emotion_state'],
                            'prev_intensity' => $prev['intensity'],
                            'curr_state'    => null,
                            'curr_intensity' => null,
                            'message'   => "{$charName} 上章有高强度「{$prevLabel}」（强度{$prev['intensity']}），本章无情绪记录，可能情绪被遗忘",
                            'suggestion' => "若该角色出场，应延续上章情绪状态",
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('CharacterEmotionRepo::detectEmotionAnomalies failed: ' . $e->getMessage());
        }

        return $anomalies;
    }

    /**
     * 构建情绪状态段落（用于 prompt 注入）
     */
    public function buildEmotionSection(int $currentChapter): string
    {
        $emotions = $this->getEmotionsForChapter($currentChapter - 1);

        if (empty($emotions)) return '';

        $lines = ["【角色情绪状态（前章末尾）】"];

        foreach ($emotions as $e) {
            $stateLabel = self::EMOTION_LABELS[$e['emotion_state']] ?? $e['emotion_state'];
            $intensityLabel = $e['intensity'] >= 70 ? '高' : ($e['intensity'] >= 40 ? '中' : '低');
            $causeText = $e['cause'] ? " — 因「{$e['cause']}」" : '';

            $lines[] = sprintf(
                "· %s：%s（强度%s）%s",
                $e['character_name'],
                $stateLabel,
                $intensityLabel,
                $causeText
            );
        }

        $lines[] = "";
        $lines[] = "本章这些角色出场时，情绪应自然延续上章状态或通过具体事件合理转化，不得无故重置。";

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * 获取核心角色列表
     */
    /**
     * 获取核心角色列表
     *
     * v1.11.2 Bug #7 修复：从「最近更新前 10」改为「主角 + 近 5 章高频出场」
     * 原方案问题：长篇里"最近更新过"的角色很多是配角，10 个里 7 个是次要角色，
     * 导致 checkContinuity 基于低质量数据（一句话出场角色）报警 → 误报多。
     *
     * 新方案：
     *   - 主角必含
     *   - 加上近 5 章 character_emotion_history 里出场频率 ≥ 2 次的核心角色
     *   - 兜底：如果情绪历史还不够（前几章），降级到原 character_cards 方案
     *
     * @param int|null $currentChapter 当前章节号；null 时降级到原方案
     */
    private function getCoreCharacters(?int $currentChapter = null): array
    {
        $characters = [];
        $protagonist = '';

        try {
            // 始终先把主角加进来
            $novel = DB::fetch(
                "SELECT protagonist_name FROM novels WHERE id = ?",
                [$this->novelId]
            );
            if (!empty($novel['protagonist_name'])) {
                $protagonist = $novel['protagonist_name'];
                $characters[] = $protagonist;
            }

            // 优先方案：近 5 章情绪历史中高频出场的角色
            if ($currentChapter !== null && $currentChapter > 5) {
                $fromChapter = max(1, $currentChapter - 5);
                $highFreq = DB::fetchAll(
                    "SELECT character_name, COUNT(*) as freq
                     FROM character_emotion_history
                     WHERE novel_id = ? AND chapter_number >= ? AND chapter_number <= ?
                     GROUP BY character_name
                     HAVING freq >= 2
                     ORDER BY freq DESC
                     LIMIT 4",
                    [$this->novelId, $fromChapter, $currentChapter]
                );

                foreach ($highFreq as $row) {
                    $name = $row['character_name'];
                    if ($name && !in_array($name, $characters, true)) {
                        $characters[] = $name;
                    }
                }

                // 高频方案如果拿到至少 1 个核心角色（除主角外），就用这个
                if (count($characters) >= 2) {
                    return $characters;
                }
            }

            // 降级方案：早期章节情绪历史不足，用 character_cards 但缩到前 5
            $cards = DB::fetchAll(
                "SELECT DISTINCT name FROM character_cards
                 WHERE novel_id = ? AND alive = 1
                 ORDER BY last_updated_chapter DESC
                 LIMIT 5",
                [$this->novelId]
            );
            foreach ($cards as $c) {
                if (!in_array($c['name'], $characters, true)) {
                    $characters[] = $c['name'];
                }
            }
        } catch (\Throwable $e) {
            // 静默降级，至少保留主角
        }

        return $characters;
    }

    /**
     * 数据行转换为数组
     */
    private function hydrate(array $row): array
    {
        return [
            'id'                      => (int)$row['id'],
            'character_name'          => $row['character_name'],
            'chapter_number'          => (int)$row['chapter_number'],
            'emotion_state'           => $row['emotion_state'],
            'emotion_label'           => self::EMOTION_LABELS[$row['emotion_state']] ?? $row['emotion_state'],
            'intensity'               => (int)$row['intensity'],
            'cause'                   => $row['cause'] ?? null,
            'expected_decay_chapters' => (int)$row['expected_decay_chapters'],
        ];
    }

    /**
     * 获取情绪统计摘要
     */
    public function getStats(int $currentChapter): array
    {
        $stats = [
            'total_records' => 0,
            'characters_tracked' => 0,
            'recent_high_intensity' => [],
        ];

        try {
            $count = DB::fetch(
                "SELECT COUNT(*) as cnt FROM character_emotion_history WHERE novel_id = ?",
                [$this->novelId]
            );
            $stats['total_records'] = (int)($count['cnt'] ?? 0);

            $chars = DB::fetch(
                "SELECT COUNT(DISTINCT character_name) as cnt FROM character_emotion_history WHERE novel_id = ?",
                [$this->novelId]
            );
            $stats['characters_tracked'] = (int)($chars['cnt'] ?? 0);

            // 近期高强度情绪
            $highIntensity = DB::fetchAll(
                "SELECT character_name, emotion_state, intensity, cause, chapter_number
                 FROM character_emotion_history
                 WHERE novel_id = ? AND chapter_number >= ? AND intensity >= 70
                 ORDER BY chapter_number DESC, intensity DESC
                 LIMIT 5",
                [$this->novelId, max(1, $currentChapter - 5)]
            );
            foreach ($highIntensity as $hi) {
                $stats['recent_high_intensity'][] = [
                    'character' => $hi['character_name'],
                    'state'     => self::EMOTION_LABELS[$hi['emotion_state']] ?? $hi['emotion_state'],
                    'intensity' => (int)$hi['intensity'],
                    'cause'     => $hi['cause'],
                    'chapter'   => (int)$hi['chapter_number'],
                ];
            }
        } catch (\Throwable $e) {
            // 返回默认值
        }

        return $stats;
    }

    /**
     * 删除指定章节的所有情绪记录（重写后清理旧数据用）
     */
    public function deleteByChapter(int $chapterNumber): int
    {
        try {
            return DB::delete(
                'character_emotion_history',
                'novel_id=? AND chapter_number=?',
                [$this->novelId, $chapterNumber]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 清理过期的情绪记录（可选，用于减少数据量）
     */
    public function cleanup(int $keepChapters = 50): int
    {
        try {
            return DB::delete(
                "DELETE FROM character_emotion_history
                 WHERE novel_id = ?
                   AND chapter_number < (
                       SELECT COALESCE(MAX(chapter_number), 0) - ?
                       FROM character_emotion_history
                       WHERE novel_id = ?
                   )",
                [$this->novelId, $keepChapters, $this->novelId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
