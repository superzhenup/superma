<?php
/**
 * ForeshadowingResolver — 主动伏笔回收规划器
 *
 * 在每章 buildPrompt 之前运行，根据当前章节大纲和全书进度，
 * 从待回收伏笔中挑选"情节成熟"的伏笔，生成回收指令注入 Prompt。
 *
 * 设计目标：将伏笔回收从"被动等待AI自报告"变为"主动规划+指令注入"
 */
defined('APP_LOADED') or die('Direct access denied.');

class ForeshadowingResolver
{
    private int $novelId;
    private int $currentChapter;
    private int $targetChapters;
    private ForeshadowingRepo $repo;

    private const MIN_AGE_TO_RESOLVE = 3;

    public function __construct(int $novelId, int $currentChapter, int $targetChapters)
    {
        $this->novelId = $novelId;
        $this->currentChapter = $currentChapter;
        $this->targetChapters = max(1, $targetChapters);
        $this->repo = new ForeshadowingRepo($novelId);
    }

    /**
     * 规划本章的伏笔回收任务
     *
     * @param string $chapterOutline 本章大纲
     * @return array{should_resolve: bool, items: array, prompt_section: string, stats: array}
     */
    public function planResolution(string $chapterOutline): array
    {
        $pending = $this->repo->listPendingWithDetails();
        $stats = [
            'pending_count' => count($pending),
            'remaining_chapters' => max(1, $this->targetChapters - $this->currentChapter),
        ];

        // 调试日志：记录待回收伏笔数量
        $this->logDebug(sprintf(
            'planResolution: 当前章=%d, 目标章=%d, 待回收伏笔=%d条',
            $this->currentChapter,
            $this->targetChapters,
            count($pending)
        ));

        if (empty($pending)) {
            $this->logDebug('planResolution: 无待回收伏笔，返回空');
            return ['should_resolve' => false, 'items' => [], 'prompt_section' => '', 'stats' => $stats];
        }

        $remaining = $stats['remaining_chapters'];

        // v1.11.8: 优化压力计算
        // 当 target_chapters 设置过小或已接近"终点"时，用更合理的剩余章节数估算
        // 假设每条伏笔至少需要 3 章才能自然回收
        $minRemaining = max(5, (int)ceil(count($pending) * 0.5));
        $effectiveRemaining = max($remaining, $minRemaining);
        $pressure = count($pending) / $effectiveRemaining;
        $stats['pressure'] = round($pressure, 2);
        $stats['effective_remaining'] = $effectiveRemaining;

        $plan = $this->calculateResolutionPlan($pressure);
        $stats['plan'] = $plan;

        // 调试日志：记录回收计划
        $this->logDebug(sprintf(
            'planResolution: pressure=%.2f, phase=%s, max_per_chapter=%d',
            $pressure,
            $plan['phase'],
            $plan['max_per_chapter']
        ));

        $maxToResolve = $plan['max_per_chapter'];
        if ($maxToResolve <= 0) {
            $this->logDebug('planResolution: 当前阶段不回收（max_per_chapter=0）');
            return ['should_resolve' => false, 'items' => [], 'prompt_section' => '', 'stats' => $stats];
        }

        $scored = $this->scoreAll($pending, $chapterOutline);
        $selected = $this->selectTop($scored, $maxToResolve);

        // 调试日志：记录评分结果
        $this->logDebug(sprintf(
            'planResolution: 评分后候选=%d条, 选中=%d条',
            count($scored),
            count($selected)
        ));

        if (empty($selected)) {
            $this->logDebug('planResolution: 选中为空（评分门槛未达标）');
            return ['should_resolve' => false, 'items' => [], 'prompt_section' => '', 'stats' => $stats];
        }

        $promptSection = $this->buildPromptSection($selected, $plan);

        return [
            'should_resolve' => true,
            'items' => $selected,
            'prompt_section' => $promptSection,
            'stats' => $stats,
        ];
    }

    private function calculateResolutionPlan(float $pressure): array
    {
        $progress = $this->targetChapters > 0
            ? $this->currentChapter / $this->targetChapters
            : 0;

        // v1.11.8: 进一步降低建置期门槛，与前5章保护机制协调
        // 前 5% 仍为建置期，但允许回收临近 deadline 的伏笔
        if ($progress < 0.05) {
            // 即使在建置期，如果有伏笔已逾期或临近 deadline，也允许回收
            if ($pressure > 0.5) {
                return ['max_per_chapter' => 1, 'phase' => 'setup_emergency', 'description' => '建置期紧急回收'];
            }
            return ['max_per_chapter' => 0, 'phase' => 'setup', 'description' => '建置期，只埋不收'];
        }

        if ($pressure > 0.8) {
            return ['max_per_chapter' => 2, 'phase' => 'emergency', 'description' => '紧急回收压力'];
        }
        if ($pressure > 0.5) {
            return ['max_per_chapter' => 2, 'phase' => 'high', 'description' => '高回收压力'];
        }

        if ($progress >= 0.8) {
            return ['max_per_chapter' => 2, 'phase' => 'ending', 'description' => '收尾冲刺'];
        }
        if ($progress >= 0.6) {
            return ['max_per_chapter' => 1, 'phase' => 'accelerating', 'description' => '加速回收'];
        }

        // v1.1: 从每5章改为每3章触发
        if ($this->currentChapter % 3 === 0) {
            return ['max_per_chapter' => 1, 'phase' => 'steady', 'description' => '稳态回收（每3章）'];
        }

        if ($pressure > 0.3) {
            return ['max_per_chapter' => 1, 'phase' => 'moderate', 'description' => '中等回收压力'];
        }

        return ['max_per_chapter' => 0, 'phase' => 'natural', 'description' => '自然回收，暂不主动'];
    }

    /**
     * 对所有待回收伏笔评分
     */
    private function scoreAll(array $pending, string $outline): array
    {
        $outlineKeywords = $this->extractKeywords($outline);
        $scored = [];

        foreach ($pending as $item) {
            $age = $this->currentChapter - (int)$item['planted_chapter'];
            if ($age < self::MIN_AGE_TO_RESOLVE) continue;

            $score = 0;
            $reasons = [];

            $priority = $item['priority'] ?? 'minor';
            if ($priority === 'critical') { $score += 25; $reasons[] = 'critical优先级'; }
            elseif ($priority === 'major') { $score += 12; $reasons[] = 'major优先级'; }

            if ($age > 25) { $score += 15; $reasons[] = "埋藏{$age}章（极久）"; }
            elseif ($age > 15) { $score += 10; $reasons[] = "埋藏{$age}章（较久）"; }
            elseif ($age > 8) { $score += 5; $reasons[] = "埋藏{$age}章"; }

            $deadline = (int)($item['deadline_chapter'] ?? 0);
            if ($deadline > 0) {
                $chaptersToDeadline = $deadline - $this->currentChapter;
                if ($chaptersToDeadline <= 0) { $score += 30; $reasons[] = "已逾期deadline"; }
                elseif ($chaptersToDeadline <= 3) { $score += 20; $reasons[] = "临近deadline({$chaptersToDeadline}章)"; }
                elseif ($chaptersToDeadline <= 7) { $score += 10; $reasons[] = "deadline渐近"; }
            }

            $lastMention = $item['last_mentioned_chapter'] !== null
                ? (int)$item['last_mentioned_chapter']
                : (int)$item['planted_chapter'];
            $sinceLastMention = $this->currentChapter - $lastMention;
            if ($sinceLastMention <= 3 && $sinceLastMention >= 1) {
                $score += 8;
                $reasons[] = "近期被提及（{$sinceLastMention}章前）";
            }

            $foreshadowKeywords = $this->extractKeywords($item['description']);
            $overlap = array_intersect($outlineKeywords, $foreshadowKeywords);
            $overlapCount = count($overlap);
            if ($overlapCount > 0) {
                $score += $overlapCount * 10;
                $reasons[] = "大纲关键词匹配(" . implode('/', array_slice($overlap, 0, 3)) . ")";
            }

            $scored[] = [
                'id' => (int)$item['id'],
                'description' => $item['description'],
                'planted_chapter' => (int)$item['planted_chapter'],
                'deadline_chapter' => $deadline,
                'priority' => $priority,
                'age' => $age,
                'score' => $score,
                'reasons' => $reasons,
                'keywords_overlap' => $overlapCount,
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored;
    }

    private function selectTop(array $scored, int $max): array
    {
        if (empty($scored) || $max <= 0) return [];

        $topScore = $scored[0]['score'] ?? 0;
        // v1.1: 降低门槛从20到10，让更多伏笔有机会回收
        if ($topScore < 10) {
            return [];
        }

        $selected = [];
        foreach ($scored as $item) {
            if (count($selected) >= $max) break;
            // v1.1: 放宽条件：评分>=10 或 关键词重叠>=1
            $minAcceptableScore = 10;
            $minKeywordsOverlap = 1;
            if ($item['score'] < $minAcceptableScore && ($item['keywords_overlap'] ?? 0) < $minKeywordsOverlap) continue;

            $alreadyRelated = false;
            foreach ($selected as $s) {
                $kws1 = $this->extractKeywords($s['description']);
                $kws2 = $this->extractKeywords($item['description']);
                if (count(array_intersect($kws1, $kws2)) >= 2) {
                    $alreadyRelated = true;
                    break;
                }
            }
            if ($alreadyRelated) continue;

            $selected[] = $item;
        }

        return $selected;
    }

    private function buildPromptSection(array $selected, array $plan): string
    {
        $lines = [];

        $lines[] = "【本章伏笔回收任务（{$plan['description']}）】";
        $lines[] = "请在本章自然回收以下伏笔，回收必须融入情节推进，不要变成生硬的解释性段落：";
        $lines[] = '';

        foreach ($selected as $i => $item) {
            $num = $i + 1;
            $dl = $item['deadline_chapter'] > 0 ? "（建议第{$item['deadline_chapter']}章前回收）" : '';
            $planted = $item['planted_chapter'];
            $lines[] = "{$num}. 【第{$planted}章埋】{$item['description']}{$dl}";
            $lines[] = "   评分理由：{$this->formatReasons($item['reasons'])}";
        }

        $lines[] = '';
        $lines[] = "【回收写作要求】";
        $lines[] = "1. 每条伏笔的回收必须有前置铺垫（前文已提及/角色想起/线索串联），不能凭空揭晓";
        $lines[] = "2. 回收时应产生新的信息量或反转，给读者「原来如此」的阅读快感";
        $lines[] = "3. 多条伏笔可以串联回收（一次揭晓解决多个谜团），但不要为了回收而回收";
        $lines[] = "4. 回收后在 resolved_foreshadowing 中使用该伏笔的精确原始描述文本";

        return implode("\n", $lines) . "\n\n";
    }

    private function formatReasons(array $reasons): string
    {
        return implode('、', $reasons);
    }

    /**
     * 写入调试日志
     */
    private function logDebug(string $message): void
    {
        addLog($this->novelId, 'debug', '[ForeshadowingResolver] ' . $message);
    }

    /**
     * 提取中文关键词（2字以上中文词组 + 3字以上英文/数字）
     */
    private function extractKeywords(string $text): array
    {
        if (empty($text)) return [];
        preg_match_all('/[\x{4e00}-\x{9fa5}]{2,}|[a-zA-Z0-9_]{3,}/u', $text, $m);
        $keywords = array_unique($m[0] ?? []);

        $stopwords = ['一个', '这个', '那个', '什么', '没有', '可以', '已经', '但是', '因为', '所以',
            '如果', '虽然', '而且', '或者', '然后', '之后', '之前', '关于', '通过', '其中',
            '开始', '出现', '发现', '知道', '认为', '觉得', '希望', '可能', '应该', '需要'];
        $keywords = array_values(array_diff($keywords, $stopwords));
        return $keywords;
    }
}
