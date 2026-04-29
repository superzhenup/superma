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
     * @return array{has_p0: bool, has_p1: bool, p0_issues: array, p1_issues: array, p2_issues: array, all_issues: array}
     */
    public function run(): array
    {
        if (!ConstraintConfig::isEnabled()) {
            return $this->emptyResult();
        }

        try {
            $this->stateDB = new ConstraintStateDB($this->novelId);
        } catch (\Throwable $e) {
            return $this->emptyResult();
        }

        // 执行各维度校验（按 P0→P1→P2 顺序，P0 先短路）
        $this->checkStructure();
        $this->checkLanguage();
        $this->checkPlotBasics();

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
     */
    private function checkWordCount(): void
    {
        $words   = countWords($this->content);
        $tolerance = max(CFG_TOLERANCE_MIN, (int)($this->targetWords * CFG_TOLERANCE_RATIO));
        $minOk   = $this->targetWords - $tolerance;
        $maxOk   = $this->targetWords + $tolerance;

        if ($words < $minOk * 0.6) {
            // 严重不足（<60%目标）
            $this->addIssue(
                'P1', 'structure', 'word_count_too_low',
                "字数严重不足：{$words}字（目标{$this->targetWords}字，下限{$minOk}字）"
            );
        } elseif ($words > $maxOk * 1.5) {
            // 严重超标（>150%上限）
            $this->addIssue(
                'P1', 'structure', 'word_count_too_high',
                "字数严重超标：{$words}字（目标{$this->targetWords}字，上限{$maxOk}字）"
            );
        } elseif ($words < $minOk) {
            $this->addIssue(
                'P2', 'structure', 'word_count_low',
                "字数略低：{$words}字（目标{$this->targetWords}字）"
            );
        } elseif ($words > $maxOk) {
            $this->addIssue(
                'P2', 'structure', 'word_count_high',
                "字数略高：{$words}字（目标{$this->targetWords}字）"
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
