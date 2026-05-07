<?php
/**
 * PostWriteValidator — 章节后置约束校验器
 *
 * Phase 1 核心：在 saveChapter() 中对已生成的章节正文执行约束校验。
 *
 * 校验维度（Phase 1）：
 *   1. 结构约束：字数是否在容忍范围内、标题是否含禁用词
 *   2. 语言约束：连续重复句式检测、直接陈述情感检测
 *   3. 情节约束：基础冲突标记识别
 *
 * 所有校验均为轻量级——纯正则+字符串统计，无 AI 调用。
 * 性能影响 ~30ms/章。
 *
 * @package ConstraintFramework
 */

defined('APP_LOADED') or die('Direct access denied.');

class PostWriteValidator
{
    private int    $novelId;
    private array  $chapter;
    private string $content;
    private int    $targetWords;
    private ?ConstraintStateDB $stateDB = null;

    /** @var array 校验结果累积 */
    private array $issues = [];

    public function __construct(int $novelId, array $chapter, string $content, int $targetWords)
    {
        $this->novelId     = $novelId;
        $this->chapter     = $chapter;
        $this->content     = $content;
        $this->targetWords = $targetWords;
    }

    /**
     * 执行全部校验
     * @param bool $forceRun 强制执行（用于人工审核场景，忽略框架启用状态）
     * @return array{has_p0: bool, has_p1: bool, p0_issues: array, p1_issues: array, p2_issues: array, all_issues: array}
     */
    public function run(bool $forceRun = false): array
    {
        $isEnabled = ConstraintConfig::isEnabled();

        if (!$isEnabled && !$forceRun) {
            return $this->emptyResult();
        }

        try {
            $this->stateDB = new ConstraintStateDB($this->novelId);
        } catch (\Throwable $e) {
            if (!$forceRun) {
                return $this->emptyResult();
            }
            $this->stateDB = null;
        }

        $this->checkStructure();

        if (!$isEnabled) {
            return $this->buildResult();
        }

        $this->checkLanguage();
        $this->checkPlotBasics();
        $this->checkBreathingRhythm();

        return $this->buildResult();
    }

    // ============================================================
    //  结构约束
    // ============================================================

    private function checkStructure(): void
    {
        $this->checkWordCount();
        $this->checkTitleBlacklist();
    }

    /**
     * P1: 字数是否严重偏离目标
     * 与 saveChapter() 的容差计算保持一致：minOk = target - tolerance, maxOk = target + tolerance
     * tolerance = max(80, target * 0.03)
     */
    private function checkWordCount(): void
    {
        $words     = countWords($this->content);
        $tolResult = calculateDynamicTolerance($this->targetWords);
        $minOk     = $tolResult['min'];
        $maxOk     = $tolResult['max'];

        if ($words < $minOk) {
            $deviation = $this->targetWords > 0
                ? round((($this->targetWords - $words) / $this->targetWords) * 100, 1)
                : 0;
            $severity = $words < $minOk * 0.6 ? 'P1' : 'P2';
            $type     = $words < $minOk * 0.6 ? 'word_count_too_low' : 'word_count_low';
            $prefix   = $words < $minOk * 0.6 ? '字数严重不足' : '字数略低';
            $this->addIssue(
                $severity, 'structure', $type,
                "{$prefix}：{$words}字（目标{$this->targetWords}字，下限{$minOk}字，偏差{$deviation}%）"
            );
        } elseif ($words > $maxOk) {
            $deviation = $this->targetWords > 0
                ? round(($words - $this->targetWords) / $this->targetWords * 100, 1)
                : 0;
            $severity = $words > $maxOk * 1.5 ? 'P1' : 'P2';
            $type     = $words > $maxOk * 1.5 ? 'word_count_too_high' : 'word_count_high';
            $prefix   = $words > $maxOk * 1.5 ? '字数严重超标' : '字数略高';
            $this->addIssue(
                $severity, 'structure', $type,
                "{$prefix}：{$words}字（目标{$this->targetWords}字，上限{$maxOk}字，偏差{$deviation}%）"
            );
        }
    }

    /**
     * P1: 章节标题是否命中禁用词
     */
    private function checkTitleBlacklist(): void
    {
        $title     = $this->chapter['title'] ?? '';
        $banned    = ConstraintConfig::bannedWords();
        $maxUsage  = ConstraintConfig::maxBannedWordUsage();

        foreach ($banned as $word) {
            if (mb_strpos($title, $word) !== false) {
                // 检查全书累计使用次数
                $usage = $this->stateDB ? $this->stateDB->getBannedWordUsage() : [];
                $count = ($usage[$word] ?? 0) + 1;

                if ($count >= $maxUsage) {
                    $this->addIssue(
                        'P0', 'structure', 'banned_word_exceeded',
                        "标题含禁用词「{$word}」（全书已用{$count}/{$maxUsage}次）",
                        false
                    );
                } else {
                    $this->addIssue(
                        'P1', 'structure', 'banned_word_in_title',
                        "标题含禁用词「{$word}」（全书累计{$count}/{$maxUsage}次）",
                        false
                    );
                }
            }
        }
    }

    // ============================================================
    //  语言约束
    // ============================================================

    private function checkLanguage(): void
    {
        $this->checkRepeatedPatterns();
        $this->checkDirectEmotion();
    }

    /**
     * P2: 检测连续重复句式（>3句结构相似）
     * 通过检测连续段落中相同句尾词/助词模式
     */
    private function checkRepeatedPatterns(): void
    {
        // 按句号/感叹号/问号分句
        $sentences = preg_split('/[。！？]/u', $this->content, -1, PREG_SPLIT_NO_EMPTY);
        if (count($sentences) < 4) return;

        // 取每句最后 4 个中文字符作为"句尾指纹"
        $fingerprints = [];
        foreach ($sentences as $s) {
            $s = trim($s);
            if (mb_strlen($s) < 3) continue;
            $fp = mb_substr($s, -4);
            $fingerprints[] = $fp;
        }

        // 检测连续相同指纹
        $streak      = 1;
        $streakStart = 0;
        $maxStreak   = 1;
        $maxFp       = '';

        for ($i = 1, $n = count($fingerprints); $i < $n; $i++) {
            if ($fingerprints[$i] === $fingerprints[$i - 1]) {
                $streak++;
                if ($streak > $maxStreak) {
                    $maxStreak   = $streak;
                    $maxFp       = $fingerprints[$i];
                    $streakStart = $i - $streak + 1;
                }
            } else {
                $streak = 1;
            }
        }

        if ($maxStreak >= 4) {
            $this->addIssue(
                'P2', 'language', 'repeated_sentence_pattern',
                "连续{$maxStreak}句以「{$maxFp}」结尾，句式重复（第" . ($streakStart + 1) . "句起）"
            );
        }
    }

    /**
     * P2: 检测"直接陈述情感"的违规表达
     * 如："他感到愤怒"、"心中充满喜悦"——应用展示而非告诉
     */
    private function checkDirectEmotion(): void
    {
        $patterns = [
            '/他感到(非常|十分|很|极其|无比|异常|更加|颇为|有些|有点|越来越|太|特别|格外|相当)?(愤怒|悲伤|恐惧|害怕|兴奋|激动|紧张|焦虑|失落|绝望|开心|高兴|快乐|喜悦|幸福|痛苦|难受|委屈|尴尬|惭愧|内疚|后悔|嫉妒|羡慕|骄傲|自豪)/u',
            '/心中(充满|涌起|泛起|升起|满是)(了|一股|一阵|一种)?(愤怒|悲伤|恐惧|害怕|兴奋|激动|紧张|焦虑|失落|绝望|开心|高兴|快乐|喜悦|幸福|痛苦|难受|委屈|尴尬|惭愧|内疚|后悔|嫉妒|羡慕|骄傲|自豪)/u',
            '/感到(一阵|一股|一种)?(愤怒|悲伤|恐惧|害怕|兴奋|激动|紧张|焦虑|失落|绝望|开心|高兴|快乐|喜悦|幸福|痛苦|难受|委屈|尴尬|惭愧|内疚|后悔|嫉妒|羡慕|骄傲|自豪)/u',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            $count += preg_match_all($pattern, $this->content);
        }

        // 中文字数 / 1000 = 千字数，每千字超过 3 次直接陈述才报警
        $cnChars = mb_strlen(preg_replace('/[^\x{4e00}-\x{9fa5}]/u', '', $this->content));
        $perK    = $cnChars > 0 ? ($count / ($cnChars / 1000)) : 0;

        if ($perK > 3) {
            $this->addIssue(
                'P2', 'language', 'excessive_direct_emotion',
                "直接陈述情感{$count}次（" . round($perK, 1) . "次/千字），建议用动作/表情展示情感"
            );
        }
    }

    // ============================================================
    //  情节基础约束（Phase 1 轻量版）
    // ============================================================

    private function checkPlotBasics(): void
    {
        $this->checkCoincidenceKeywords();
        $this->checkExhaustedSceneTemplates();
    }

    /**
     * P2: 检测巧合类关键词
     */
    private function checkCoincidenceKeywords(): void
    {
        $keywords = ['恰好', '正巧', '刚好', '碰巧', '凑巧', '巧合', '恰逢', '不偏不倚', '鬼使神差'];
        $count    = 0;
        foreach ($keywords as $kw) {
            $count += mb_substr_count($this->content, $kw);
        }

        if ($count >= 3) {
            $maxCoincidences = ConstraintConfig::maxCoincidences();
            $this->addIssue(
                'P2', 'plot', 'excessive_coincidence',
                "本章巧合类描写{$count}次（全书上限{$maxCoincidences}次），可能导致情节可信度下降"
            );
        }
    }

    private function checkExhaustedSceneTemplates(): void
    {
        require_once __DIR__ . '/../memory/SceneTemplateRepo.php';
        require_once __DIR__ . '/../prompt.php';

        $detected = detectSceneTemplates($this->content);
        if (empty($detected)) return;

        $repo = new SceneTemplateRepo($this->novelId);
        $exhausted = $repo->getExhaustedTemplates();
        if (empty($exhausted)) return;

        foreach ($detected as $tid) {
            if (isset($exhausted[$tid])) {
                $info = $exhausted[$tid];
                $this->addIssue(
                    'P2', 'plot', 'exhausted_scene_template',
                    "场景模板「{$info['name']}」(第{$info['last_chapter']}章)已使用{$info['use_count']}/{$info['max_uses']}次已达上限，本章仍命中该模板"
                );
            }
        }
    }

    // ============================================================
    //  段落级呼吸节奏检测 (v1.10.3)
    // ============================================================

    /**
     * P2: 检测段落级呼吸节奏异常
     *
     * 检测维度：
     * 1. 短段比例 > 60% → 太碎
     * 2. 长段比例 > 30% → 太闷
     * 3. 场景切换 > 8 → 太杂乱
     * 4. 对话密度 > 70% → 缺描写
     * 5. 对话密度 < 10% → 缺互动
     */
    private function checkBreathingRhythm(): void
    {
        $paragraphs = preg_split('/\n\s*\n|\n/', $this->content, -1, PREG_SPLIT_NO_EMPTY);
        if (count($paragraphs) < 3) return;

        $totalParagraphs = count($paragraphs);
        $shortCount = 0;
        $longCount = 0;
        $totalLen = 0;
        $sceneChanges = 0;
        $dialogueChars = 0;
        $totalChars = mb_strlen($this->content);

        foreach ($paragraphs as $i => $para) {
            $len = mb_strlen($para);
            $totalLen += $len;

            if ($len < 50) $shortCount++;
            if ($len > 500) $longCount++;

            if ($i > 0 && mb_strlen($para) > 10) {
                $firstChar = mb_substr($para, 0, 1);
                if (preg_match('/[「『""\'\'""（（]/u', $firstChar)) {
                    // 段落以引号开头可能是场景切换
                }
            }

            preg_match_all('/[「『""\'\'""].*?[」』""\'\'""]/u', $para, $dm);
            foreach ($dm[0] as $d) {
                $dialogueChars += mb_strlen($d);
            }
        }

        $shortRatio = $shortCount / $totalParagraphs;
        $longRatio = $longCount / $totalParagraphs;
        $dialogueDensity = $totalChars > 0 ? $dialogueChars / $totalChars : 0;

        if ($shortRatio > 0.6) {
            $this->addIssue(
                'P2', 'rhythm', 'too_many_short_paragraphs',
                "短段比例" . round($shortRatio * 100) . "%（{$shortCount}/{$totalParagraphs}段），章节读起来碎（建议<50%）"
            );
        }

        if ($longRatio > 0.3) {
            $this->addIssue(
                'P2', 'rhythm', 'too_many_long_paragraphs',
                "长段比例" . round($longRatio * 100) . "%（{$longCount}/{$totalParagraphs}段），节奏偏闷（建议<20%）"
            );
        }

        if ($dialogueDensity > 0.7) {
            $this->addIssue(
                'P2', 'rhythm', 'dialogue_too_dense',
                "对话密度" . round($dialogueDensity * 100) . "%，缺乏描写/心理/动作段落"
            );
        } elseif ($dialogueDensity < 0.1 && $totalChars > 1000) {
            $this->addIssue(
                'P2', 'rhythm', 'dialogue_too_sparse',
                "对话密度仅" . round($dialogueDensity * 100) . "%，缺乏互动和对话推进"
            );
        }
    }

    // ============================================================
    //  辅助方法
    // ============================================================

    private function addIssue(
        string $level,
        string $dimension,
        string $issueType,
        string $issueDesc,
        bool   $autoFixed = false
    ): void {
        $this->issues[] = [
            'level'      => $level,
            'dimension'  => $dimension,
            'issue_type' => $issueType,
            'issue_desc' => $issueDesc,
            'auto_fixed' => $autoFixed,
        ];
    }

    private function buildResult(): array
    {
        $p0 = []; $p1 = []; $p2 = [];
        foreach ($this->issues as $issue) {
            switch ($issue['level']) {
                case 'P0': $p0[] = $issue; break;
                case 'P1': $p1[] = $issue; break;
                case 'P2': $p2[] = $issue; break;
            }
        }

        // 写入 constraint_logs
        if ($this->stateDB && !empty($this->issues)) {
            $chNum = (int)($this->chapter['chapter_number'] ?? 0);
            $chId  = isset($this->chapter['id']) ? (int)$this->chapter['id'] : null;
            foreach ($this->issues as $issue) {
                $this->stateDB->logViolation(
                    $chNum, $chId,
                    $issue['dimension'], $issue['level'],
                    $issue['issue_type'], $issue['issue_desc'],
                    'post_write', $issue['auto_fixed']
                );
            }
        }

        return [
            'has_p0'    => !empty($p0),
            'has_p1'    => !empty($p1),
            'p0_issues' => $p0,
            'p1_issues' => $p1,
            'p2_issues' => $p2,
            'all_issues'=> $this->issues,
        ];
    }

    private function emptyResult(): array
    {
        return [
            'has_p0'    => false,
            'has_p1'    => false,
            'p0_issues' => [],
            'p1_issues' => [],
            'p2_issues' => [],
            'all_issues'=> [],
        ];
    }
}
