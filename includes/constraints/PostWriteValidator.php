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

require_once __DIR__ . '/../quality/WritePolicySnapshot.php';
require_once __DIR__ . '/../quality/DialogueText.php';

final class PostWritePolicyReplayException extends RuntimeException
{
}

class PostWriteValidator
{
    /** @var list<string> */
    private const LEGACY_COMPAT_BANNED_WORDS = ['绝境', '反杀', '真相', '背水', '逆袭'];
    private const LEGACY_COMPAT_MAX_BANNED_WORD_USAGE = 15;
    private const LEGACY_COMPAT_MAX_SAME_CONFLICT = 1;
    private const LEGACY_COMPAT_MAX_COINCIDENCES = 5;

    private int    $novelId;
    private array  $chapter;
    private string $content;
    private int    $targetWords;
    private int    $hardMinWords;
    private int    $minWords;
    private int    $maxWords;
    private bool   $constraintEnabled;
    /** @var list<string> */
    private array  $bannedWords;
    private int    $maxBannedWordUsage;
    private int    $maxSameConflict;
    private int    $maxCoincidences;
    private bool   $frozenPolicy = false;
    /** @var array{level:string,dimension:string,issue_type:string,issue_desc:string,auto_fixed:bool}|null */
    private ?array $policyAdvisory = null;
    private ?ConstraintStateDB $stateDB = null;

    /** @var array 校验结果累积 */
    private array $issues = [];

    public function __construct(
        int $novelId,
        array $chapter,
        string $content,
        int $targetWords,
        ?WritePolicySnapshot $policySnapshot = null
    ) {
        $this->novelId     = $novelId;
        $this->chapter     = $chapter;
        $this->content     = $content;

        if ($policySnapshot === null) {
            $this->configureLegacyLivePolicy($targetWords);
            return;
        }

        $this->configureFrozenPolicy($policySnapshot, $targetWords);
    }

    /**
     * Backward-compatible path for callers not yet wired to a policy snapshot.
     * All live reads are resolved once here; validation methods consume only
     * the resulting properties.
     */
    private function configureLegacyLivePolicy(int $targetWords): void
    {
        $this->targetWords = $targetWords;
        $this->constraintEnabled = ConstraintConfig::isEnabled();
        $this->bannedWords = array_values(ConstraintConfig::bannedWords());
        $this->maxBannedWordUsage = ConstraintConfig::maxBannedWordUsage();
        $this->maxSameConflict = ConstraintConfig::maxSameConflict();
        $this->maxCoincidences = max(0, ConstraintConfig::maxCoincidences());

        $range = calculateDynamicTolerance($targetWords, $this->novelId);
        $this->minWords = (int)$range['min'];
        $this->maxWords = (int)$range['max'];
        $this->hardMinWords = $targetWords > 0
            ? max(100, (int)floor($targetWords * 0.3))
            : 0;
    }

    private function configureFrozenPolicy(WritePolicySnapshot $policy, int $legacyTargetWords): void
    {
        $this->frozenPolicy = true;
        $this->targetWords = $policy->targetWords();
        $this->hardMinWords = $policy->hardMinWords();
        $this->minWords = $policy->minWords();
        $this->maxWords = $policy->maxWords();

        if ($legacyTargetWords !== $this->targetWords) {
            throw new InvalidArgumentException(
                'PostWriteValidator targetWords must match the frozen policy target_words.'
            );
        }

        if ($policy->version() === WritePolicySnapshot::SCHEMA_V1) {
            if ($policy->canonPolicy() === WritePolicySnapshot::CANON_POLICY_STRICT) {
                throw new PostWritePolicyReplayException(
                    'A strict write-policy-v1 snapshot has no frozen constraint profile and cannot be replayed.'
                );
            }
            $this->applyLegacyCompatibilityProfile();
            $this->policyAdvisory = self::advisoryIssue(
                'legacy_policy_snapshot_compat',
                'Constraint validation used the fixed write-policy-v1 compatibility profile; live settings were ignored.'
            );
            return;
        }

        if ($policy->constraintRuleVersion() !== WritePolicySnapshot::CONSTRAINT_RULE_POST_WRITE_V1) {
            throw new PostWritePolicyReplayException(
                'The frozen constraint rule version is unsupported by PostWriteValidator.'
            );
        }

        $enabled = $policy->constraintEnabled();
        $bannedWords = $policy->constraintBannedWords();
        $maxBannedWordUsage = $policy->constraintMaxBannedWordUsage();
        $maxSameConflict = $policy->constraintMaxSameConflict();
        $maxCoincidences = $policy->constraintMaxCoincidences();
        if ($enabled === null
            || $bannedWords === null
            || $maxBannedWordUsage === null
            || $maxSameConflict === null
            || $maxCoincidences === null) {
            throw new PostWritePolicyReplayException(
                'The write-policy-v2 constraint profile is structurally incomplete.'
            );
        }
        if (!$policy->constraintProfileComplete()) {
            if ($policy->canonPolicy() === WritePolicySnapshot::CANON_POLICY_STRICT) {
                throw new PostWritePolicyReplayException(
                    'A strict write-policy-v2 snapshot has an incomplete constraint profile and cannot be replayed.'
                );
            }
            $this->policyAdvisory = self::advisoryIssue(
                'constraint_profile_incomplete',
                'Constraint validation replayed frozen fallback values from an incomplete profile; live settings were ignored.'
            );
        }

        $this->constraintEnabled = $enabled;
        $this->bannedWords = $bannedWords;
        $this->maxBannedWordUsage = $maxBannedWordUsage;
        $this->maxSameConflict = $maxSameConflict;
        $this->maxCoincidences = $maxCoincidences;
    }

    private function applyLegacyCompatibilityProfile(): void
    {
        $this->constraintEnabled = true;
        $this->bannedWords = self::LEGACY_COMPAT_BANNED_WORDS;
        $this->maxBannedWordUsage = self::LEGACY_COMPAT_MAX_BANNED_WORD_USAGE;
        $this->maxSameConflict = self::LEGACY_COMPAT_MAX_SAME_CONFLICT;
        $this->maxCoincidences = self::LEGACY_COMPAT_MAX_COINCIDENCES;
    }

    /** @return array{level:string,dimension:string,issue_type:string,issue_desc:string,auto_fixed:bool} */
    private static function advisoryIssue(string $type, string $description): array
    {
        return [
            'level' => 'P2',
            'dimension' => 'structure',
            'issue_type' => $type,
            'issue_desc' => $description,
            'auto_fixed' => false,
        ];
    }

    /**
     * 执行全部校验
     * @param bool $forceRun 强制执行（用于人工审核场景，忽略框架启用状态）
     * @param ?bool $recordViolations null preserves legacy logging for old
     *     callers and suppresses logging for frozen/replay evaluation.
     * @return array{has_p0: bool, has_p1: bool, p0_issues: array, p1_issues: array, p2_issues: array, all_issues: array}
     */
    public function run(bool $forceRun = false, ?bool $recordViolations = null): array
    {
        $this->issues = [];
        $this->stateDB = null;
        if ($this->policyAdvisory !== null) {
            $this->issues[] = $this->policyAdvisory;
        }
        $recordViolations ??= !$this->frozenPolicy;
        $isEnabled = $this->constraintEnabled;

        if (!$isEnabled && !$forceRun) {
            return $this->issues === []
                ? $this->emptyResult()
                : $this->buildResult($recordViolations);
        }

        try {
            $this->stateDB = new ConstraintStateDB($this->novelId);
        } catch (\Throwable $e) {
            if (!$forceRun) {
                return $this->issues === []
                    ? $this->emptyResult()
                    : $this->buildResult($recordViolations);
            }
            $this->stateDB = null;
        }

        $this->checkStructure();

        if (!$isEnabled) {
            return $this->buildResult($recordViolations);
        }

        $this->checkLanguage();
        $this->checkPlotBasics();
        $this->checkBreathingRhythm();

        return $this->buildResult($recordViolations);
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
        $words = countWords($this->content);
        $minOk = $this->minWords;
        $maxOk = $this->maxWords;

        if ($words < $minOk) {
            $deviation = $this->targetWords > 0
                ? round((($this->targetWords - $words) / $this->targetWords) * 100, 1)
                : 0;
            // 严格模式以前永远收不到 P0。低于目标 30% 通常是断流/截断稿，
            // 属于不可作为完整章节落盘的客观失败，而不是风格偏好。
            $criticalFloor = $this->hardMinWords;
            if ($criticalFloor > 0 && $words < $criticalFloor) {
                $severity = 'P0';
                $type = 'chapter_incomplete';
                $prefix = '章节疑似截断';
            } elseif ($words < $minOk * 0.6) {
                $severity = 'P1';
                $type = 'word_count_too_low';
                $prefix = '字数严重不足';
            } else {
                $severity = 'P2';
                $type = 'word_count_low';
                $prefix = '字数略低';
            }
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
        $title  = $this->chapter['title'] ?? '';
        $banned = $this->bannedWords;

        // 正常落盘路径下，TitleSanitizer 已在校验前自动改写标题，此处不会再命中。
        // 残留命中（自定义禁用词无法安全改写 / forceRun 人工审核旧章）一律记 P1 提示——
        // 标题措辞绝不升级为可阻断落盘的 P0，避免严格模式下整本书被一个标题卡死。
        //
        // 关键修复：此判定只看「标题本身是否含禁用词」，不再借用正文词频
        // （banned_word_usage）。否则「真相」等正文高频词会凭空耗尽标题预算
        // （如 18/15），把首个用到该词的标题误判为 P0、阻断落盘并形成死锁。
        foreach ($banned as $word) {
            if (mb_strpos($title, $word) !== false) {
                $this->addIssue(
                    'P1', 'structure', 'banned_word_in_title',
                    "标题含禁用词「{$word}」（建议改写为非套路化表述）",
                    false
                );
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
        $this->checkBannedWordBody();
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

        // v1.11.9: 过滤语气词与特殊标点，提取纯净的句尾实词指纹，降低误报
        $fingerprints = [];
        $stopWordsPattern = '/[了呢吧啊吗呀嘛哈哒的啦]|[。！？，…“”‘’"\']/u';
        foreach ($sentences as $s) {
            $s = trim($s);
            if (mb_strlen($s) < 3) continue;
            // 移除尾部无实际语义的虚词和标点，仅拿倒数 3 个字作为句尾指纹
            $cleaned = preg_replace($stopWordsPattern, '', $s);
            if (mb_strlen($cleaned) < 3) continue;
            $fp = mb_substr($cleaned, -3);
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
            // 审计修复（2026-07-19 H-中5）：原模式3「感到...」与模式1「他感到...」重叠，
            // 同一表达被计 2 次。改为排除"他/她/它"前缀的独立"感到"模式，
            // 仅匹配"感到"独立出现（非"他感到"）。
            '/(?<![他她它我你])(感到)(一阵|一股|一种)?(愤怒|悲伤|恐惧|害怕|兴奋|激动|紧张|焦虑|失落|绝望|开心|高兴|快乐|喜悦|幸福|痛苦|难受|委屈|尴尬|惭愧|内疚|后悔|嫉妒|羡慕|骄傲|自豪)/u',
        ];

        // v1.11.9: 对命中情感词的部分执行"否定前缀校验"，排除"并不感到愤怒"等否定结构
        $count = 0;
        // 审计修复（2026-07-19 H-中5）：记录已匹配的 offset 去重，
        // 避免不同模式对同一位置重复计数。
        $matchedOffsets = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $this->content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $offset = $match[1];
                    if (isset($matchedOffsets[$offset])) continue; // 已计过
                    // 往前探测约 12 字节 (3-4 个中文字符) 判断是否有"不/没/非/并无/毫不"等词
                    $prefixStart = max(0, $offset - 12);
                    // P2-1 修复（2026-08-09）：PREG_OFFSET_CAPTURE 返回的是字节偏移，
                    // substr 也按字节截取，二者维度一致。原代码误用 mb_substr（按字符偏移），
                    // 在多字节 UTF-8 内容上会切到错误位置，使否定前缀豁免失效；
                    // 另有 `$prefix = mb_substr($this->content, 0, 0);` 死代码一并移除。
                    $prefix = substr($this->content, $prefixStart, $offset - $prefixStart);
                    if (preg_match('/(不|没|非|未|并无|毫不|绝不)/u', $prefix)) {
                        $matchedOffsets[$offset] = true; // 否定，标记已处理
                        continue; // 否定表达，不计入违规计数
                    }
                    $matchedOffsets[$offset] = true;
                    $count++;
                }
            }
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

    /**
     * P1: 单章禁用词出现次数超过 cf_max_banned_word_usage
     */
    private function checkBannedWordBody(): void
    {
        $banned = $this->bannedWords;
        if (empty($banned)) return;
        $max = $this->maxBannedWordUsage;
        $total = 0;
        $hits = [];
        foreach ($banned as $word) {
            $cnt = mb_substr_count($this->content, $word);
            if ($cnt > 0) {
                $total += $cnt;
                $hits[] = "{$word}×{$cnt}";
            }
        }
        if ($total > $max) {
            $this->addIssue(
                'P1', 'language', 'banned_word_overuse',
                "本章禁用词出现{$total}次（上限{$max}）：" . implode('、', array_slice($hits, 0, 5))
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
        $this->checkRepeatedConflictType();
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
            $maxCoincidences = $this->maxCoincidences;
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

    /**
     * P2: 连续多章同一冲突类型超过 cf_max_same_conflict
     */
    private function checkRepeatedConflictType(): void
    {
        if (!$this->stateDB) return;
        $limit = $this->maxSameConflict;
        $history = $this->stateDB->getConflictHistory($limit + 1);
        if (count($history) < $limit + 1) return;

        $types = [];
        foreach ($history as $h) {
            $t = is_array($h) ? trim((string)($h['type'] ?? '')) : trim((string)$h);
            if ($t !== '' && $t !== 'mixed') $types[] = $t;
        }
        if (count($types) < $limit + 1) return;
        $first = $types[0];
        for ($i = 1; $i <= $limit; $i++) {
            if (($types[$i] ?? '') !== $first) return;
        }
        $this->addIssue(
            'P2', 'plot', 'repeated_conflict_type',
            "近" . ($limit + 1) . "章冲突类型均为「{$first}」（连续上限{$limit}章）"
        );
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
     * 3. 对话密度 > 70% → 缺描写
     * 4. 对话密度 < 10% → 缺互动
     * 引号、括号和短段只能说明排版，不能证明场景切换。
     */
    private function checkBreathingRhythm(): void
    {
        $paragraphs = preg_split('/\n\s*\n|\n/', $this->content, -1, PREG_SPLIT_NO_EMPTY);
        if (count($paragraphs) < 3) return;

        $totalParagraphs = count($paragraphs);
        $shortCount = 0;
        $longCount = 0;
        $dialogueChars = 0;
        $totalChars = DialogueText::visibleLength($this->content);

        foreach ($paragraphs as $para) {
            $para = preg_replace('/^\s+|\s+$/u', '', $para) ?? $para;
            $len = mb_strlen($para);

            if ($len < 50) $shortCount++;
            if ($len > 500) $longCount++;

            foreach (DialogueText::passages($para) as $d) {
                $dialogueChars += DialogueText::visibleLength($d);
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

    private function buildResult(bool $recordViolations): array
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
        if ($recordViolations && $this->stateDB && !empty($this->issues)) {
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
