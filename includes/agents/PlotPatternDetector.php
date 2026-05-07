<?php
/**
 * 情节模式重复检测器
 *
 * 功能：检测近 N 章的情节签名（opening_type + hook_type + cool_point_type 组合）频率
 * 触发：单一模式出现过多 或 连续多章相同模式
 * 输出：Agent 指令，建议使用反向模式
 *
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../../includes/prompt.php';

class PlotPatternDetector
{
    /** @var int 检测窗口（最近多少章） */
    private const WINDOW = 30;

    /** @var int 单一模式最多出现次数 */
    private const MAX_REPETITION = 4;

    /** @var int 连续重复最大值 */
    private const MAX_STREAK = 3;

    /** @var int 最小章节数才开始检测 */
    private const MIN_CHAPTERS = 5;

    /** @var int 小说ID */
    private int $novelId;

    /** @var array 模式名称映射 */
    private array $typeNames = [];

    /**
     * 构造函数
     */
    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;

        // 加载类型名称
        foreach (HOOK_TYPES as $k => $v) {
            $this->typeNames['hook'][$k] = $v['name'];
        }
        foreach (COOL_POINT_TYPES as $k => $v) {
            $this->typeNames['cool'][$k] = $v['name'];
        }
        foreach (OPENING_TYPES as $k => $v) {
            $this->typeNames['opening'][$k] = $v['name'];
        }
    }

    /**
     * 检测情节模式重复
     *
     * @param int $currentChapter 当前章节号
     * @return array|null 检测结果，null 表示无问题
     */
    public function detect(int $currentChapter): ?array
    {
        try {
            $recent = DB::fetchAll(
                'SELECT chapter_number, opening_type, actual_opening_type, hook_type, cool_point_type, actual_cool_point_types
                 FROM chapters
                 WHERE novel_id = ? AND chapter_number BETWEEN ? AND ?
                 AND status = "completed"
                 ORDER BY chapter_number ASC',
                [
                    $this->novelId,
                    max(1, $currentChapter - self::WINDOW + 1),
                    $currentChapter
                ]
            );

            if (count($recent) < self::MIN_CHAPTERS) {
                return null;
            }

            // 提取每章的"情节签名"
            $signatures = [];
            foreach ($recent as $ch) {
                $sig = $this->buildSignature($ch);
                $signatures[$ch['chapter_number']] = $sig;
            }

            // 检查 1：单一签名出现次数
            $sigCounts = array_count_values($signatures);
            $repetitive = array_filter($sigCounts, fn($c) => $c >= self::MAX_REPETITION);

            if (!empty($repetitive)) {
                $sig = array_key_first($repetitive);
                $count = $repetitive[$sig];
                return [
                    'severity' => 'high',
                    'type' => 'pattern_repetition',
                    'message' => sprintf(
                        '近 %d 章中 %d 章使用相同情节模式',
                        count($recent),
                        $count
                    ),
                    'pattern' => $this->describePattern($sig),
                    'pattern_signature' => $sig,
                    'chapters' => array_keys(array_filter($signatures, fn($s) => $s === $sig)),
                    'directive' => $this->buildAvoidDirective($sig, $sigCounts),
                ];
            }

            // 检查 2：连续重复
            $streakResult = $this->detectMaxStreak($signatures);
            if ($streakResult['max_streak'] >= self::MAX_STREAK) {
                return [
                    'severity' => 'medium',
                    'type' => 'consecutive_repetition',
                    'message' => sprintf(
                        '连续 %d 章使用相同情节模式',
                        $streakResult['max_streak']
                    ),
                    'pattern' => $this->describePattern($streakResult['signature']),
                    'pattern_signature' => $streakResult['signature'],
                    'chapters' => $streakResult['chapters'],
                    'directive' => $this->buildConsecutiveDirective($streakResult['signature']),
                ];
            }

            // 检查 3：单一元素过度使用（不开篇类型/钩子类型/爽点类型）
            $elementOveruse = $this->detectElementOveruse($signatures);
            if ($elementOveruse) {
                return $elementOveruse;
            }

            return null;

        } catch (\Throwable $e) {
            error_log('PlotPatternDetector::detect failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 构建章节的情节签名
     */
    private function buildSignature(array $chapter): string
    {
        // v1.11.2 Bug #6 修复：优先用 actual_opening_type（实测）而非 opening_type（声明）
        $opening = !empty($chapter['actual_opening_type'])
            ? $chapter['actual_opening_type']
            : ($chapter['opening_type'] ?? 'unknown');

        // hook_type 可能是 JSON 或字符串
        $hook = 'none';
        if (!empty($chapter['hook_type'])) {
            $hookData = json_decode($chapter['hook_type'], true);
            if (is_array($hookData) && isset($hookData['type'])) {
                $hook = $hookData['type'];
            } elseif (is_string($chapter['hook_type'])) {
                $hook = $chapter['hook_type'];
            }
        }

        // cool_point_type：优先用 actual_cool_point_types（实际写入的）
        $cool = 'none';
        if (!empty($chapter['actual_cool_point_types'])) {
            $coolTypes = json_decode($chapter['actual_cool_point_types'], true);
            if (is_array($coolTypes) && !empty($coolTypes)) {
                // 取第一个爽点类型
                $cool = $coolTypes[0];
            }
        } elseif (!empty($chapter['cool_point_type'])) {
            $cool = $chapter['cool_point_type'];
        }

        return "{$opening}|{$hook}|{$cool}";
    }

    /**
     * 描述情节模式
     */
    private function describePattern(string $sig): string
    {
        $parts = explode('|', $sig);
        $opening = $this->typeNames['opening'][$parts[0]] ?? $parts[0];
        $hook = $this->typeNames['hook'][$parts[1]] ?? $parts[1];
        $cool = $this->typeNames['cool'][$parts[2]] ?? $parts[2];

        return "开篇【{$opening}】+ 钩子【{$hook}】+ 爽点【{$cool}】";
    }

    /**
     * 检测最大连续重复
     */
    private function detectMaxStreak(array $signatures): array
    {
        $maxStreak = 1;
        $currentStreak = 1;
        $maxStreakSig = '';
        $maxStreakChapters = [];
        $currentStreakChapters = [];

        $prevSig = null;
        foreach ($signatures as $chNum => $sig) {
            if ($sig === $prevSig) {
                $currentStreak++;
                $currentStreakChapters[] = $chNum;
            } else {
                if ($currentStreak > $maxStreak) {
                    $maxStreak = $currentStreak;
                    $maxStreakSig = $prevSig;
                    $maxStreakChapters = $currentStreakChapters;
                }
                $currentStreak = 1;
                $currentStreakChapters = [$chNum];
            }
            $prevSig = $sig;
        }

        // 检查最后一段
        if ($currentStreak > $maxStreak) {
            $maxStreak = $currentStreak;
            $maxStreakSig = $prevSig;
            $maxStreakChapters = $currentStreakChapters;
        }

        return [
            'max_streak' => $maxStreak,
            'signature' => $maxStreakSig,
            'chapters' => $maxStreakChapters,
        ];
    }

    /**
     * 检测单一元素过度使用
     */
    private function detectElementOveruse(array $signatures): ?array
    {
        // 统计各类型出现次数
        $openingCounts = [];
        $hookCounts = [];
        $coolCounts = [];

        foreach ($signatures as $sig) {
            $parts = explode('|', $sig);
            $opening = $parts[0] ?? 'unknown';
            $hook = $parts[1] ?? 'none';
            $cool = $parts[2] ?? 'none';

            $openingCounts[$opening] = ($openingCounts[$opening] ?? 0) + 1;
            $hookCounts[$hook] = ($hookCounts[$hook] ?? 0) + 1;
            $coolCounts[$cool] = ($coolCounts[$cool] ?? 0) + 1;
        }

        $total = count($signatures);
        $threshold = max(6, (int)($total * 0.35)); // 单一类型超过 35% 或 6 次

        // 检查开篇类型
        foreach ($openingCounts as $type => $count) {
            if ($count >= $threshold && $type !== 'unknown') {
                $name = $this->typeNames['opening'][$type] ?? $type;
                return [
                    'severity' => 'medium',
                    'type' => 'element_overuse',
                    'element_type' => 'opening',
                    'message' => "近 {$total} 章中 {$count} 章使用【{$name}】开篇",
                    'directive' => $this->buildElementDirective('opening', $type, $count),
                ];
            }
        }

        // 检查钩子类型
        foreach ($hookCounts as $type => $count) {
            if ($count >= $threshold && $type !== 'none' && $type !== 'unknown') {
                $name = $this->typeNames['hook'][$type] ?? $type;
                return [
                    'severity' => 'medium',
                    'type' => 'element_overuse',
                    'element_type' => 'hook',
                    'message' => "近 {$total} 章中 {$count} 章使用【{$name}】钩子",
                    'directive' => $this->buildElementDirective('hook', $type, $count),
                ];
            }
        }

        // 检查爽点类型
        foreach ($coolCounts as $type => $count) {
            if ($count >= $threshold && $type !== 'none' && $type !== 'unknown') {
                $name = $this->typeNames['cool'][$type] ?? $type;
                return [
                    'severity' => 'medium',
                    'type' => 'element_overuse',
                    'element_type' => 'cool_point',
                    'message' => "近 {$total} 章中 {$count} 章使用【{$name}】爽点",
                    'directive' => $this->buildElementDirective('cool_point', $type, $count),
                ];
            }
        }

        return null;
    }

    /**
     * 构建避免重复的指令
     */
    private function buildAvoidDirective(string $sig, array $allSigs): string
    {
        $parts = explode('|', $sig);
        $opening = $parts[0] ?? 'unknown';
        $hook = $parts[1] ?? 'none';
        $cool = $parts[2] ?? 'none';

        $patternDesc = $this->describePattern($sig);
        $altOpenings = $this->getAlternatives('opening', $opening);
        $altHooks = $this->getAlternatives('hook', $hook);
        $altCools = $this->getAlternatives('cool_point', $cool);

        $directive = "【套路重复警告】近期情节模式过于重复：{$patternDesc}。\n";
        $directive .= "本章请务必尝试新组合：\n";

        if (!empty($altOpenings)) {
            $directive .= "1. 开篇改用：" . implode(' / ', $altOpenings) . "\n";
        }
        if (!empty($altHooks)) {
            $directive .= "2. 钩子改用：" . implode(' / ', $altHooks) . "\n";
        }
        if (!empty($altCools)) {
            $directive .= "3. 爽点改用：" . implode(' / ', $altCools) . "\n";
        }

        $directive .= "避免读者产生「又来这一套」的疲劳感。";

        return $directive;
    }

    /**
     * 构建连续重复指令
     */
    private function buildConsecutiveDirective(string $sig): string
    {
        $patternDesc = $this->describePattern($sig);
        $parts = explode('|', $sig);

        $altOpenings = $this->getAlternatives('opening', $parts[0] ?? 'unknown');
        $altCools = $this->getAlternatives('cool_point', $parts[2] ?? 'none');

        $directive = "【连续模式警告】连续多章使用相同的 {$patternDesc}。\n";
        $directive .= "本章必须换风格，强烈建议：\n";

        if (!empty($altOpenings)) {
            $directive .= "- 开篇风格：{$altOpenings[0]}，打破惯性\n";
        }
        if (!empty($altCools)) {
            $directive .= "- 爽点类型：{$altCools[0]}，给读者新鲜感\n";
        }

        $directive .= "如果必须用相同爽点，至少改变执行方式（如从\"当众打脸\"改为\"无声展示实力\"）。";

        return $directive;
    }

    /**
     * 构建单一元素过度使用指令
     */
    private function buildElementDirective(string $elementType, string $type, int $count): string
    {
        $name = $this->typeNames[$elementType][$type] ?? $type;
        $alternatives = $this->getAlternatives($elementType, $type);

        $typeLabel = match ($elementType) {
            'opening' => '开篇风格',
            'hook' => '钩子类型',
            'cool_point' => '爽点类型',
            default => '元素'
        };

        $directive = "【元素过度使用】{$typeLabel}【{$name}】近期已用 {$count} 次。\n";
        $directive .= "本章请避免使用【{$name}】，改用：\n";

        foreach (array_slice($alternatives, 0, 3) as $alt) {
            $directive .= "- {$alt}\n";
        }

        $directive .= "保持情节新鲜度，避免读者审美疲劳。";

        return $directive;
    }

    /**
     * 获取替代类型
     */
    private function getAlternatives(string $category, string $currentType): array
    {
        static $alternatives = [
            'opening' => [
                'action'   => ['悬念开篇', '场景开篇', '情感开篇'],
                'dialogue' => ['动作开篇', '悬念开篇', '场景开篇'],
                'mystery'  => ['动作开篇', '对话开篇', '场景开篇'],
                'scene'    => ['动作开篇', '悬念开篇', '情感开篇'],
                'emotion'  => ['动作开篇', '悬念开篇', '场景开篇'],
                'unknown'  => ['悬念开篇', '动作开篇', '场景开篇'],
            ],
            'hook' => [
                'crisis_interrupt' => ['信息爆炸型', '反转颠覆型', '新目标型'],
                'info_bomb'        => ['危机打断型', '反转颠覆型', '情感冲击型'],
                'plot_twist'       => ['危机打断型', '新目标型', '升级预示型'],
                'new_goal'         => ['危机打断型', '反转颠覆型', '情感冲击型'],
                'emotional_impact' => ['危机打断型', '信息爆炸型', '升级预示型'],
                'upgrade_omen'     => ['危机打断型', '反转颠覆型', '新目标型'],
                'none'             => ['危机打断型', '反转颠覆型', '信息爆炸型'],
                'unknown'          => ['危机打断型', '反转颠覆型', '信息爆炸型'],
            ],
            'cool_point' => [
                'underdog_win' => ['宝物/奇遇', '修为突破', '真相揭露'],
                'face_slap'    => ['越级战斗胜利', '背水一战', '修为突破'],
                'treasure_find'=> ['越级战斗胜利', '打脸反转', '真相揭露'],
                'breakthrough' => ['打脸反转', '势力扩张', '红颜倾心'],
                'power_expand' => ['修为突破', '真相揭露', '背水一战'],
                'romance_win'  => ['越级战斗胜利', '打脸反转', '势力扩张'],
                'truth_reveal' => ['打脸反转', '修为突破', '宝物/奇遇'],
                'last_stand'   => ['越级战斗胜利', '宝物/奇遇', '真相揭露'],
                'sacrifice'    => ['修为突破', '势力扩张', '红颜倾心'],
                'none'         => ['越级战斗胜利', '打脸反转', '修为突破'],
                'unknown'      => ['越级战斗胜利', '打脸反转', '修为突破'],
            ],
        ];

        return $alternatives[$category][$currentType] ?? $alternatives[$category]['unknown'] ?? [];
    }

    /**
     * 获取统计摘要（用于 UI 展示）
     */
    public function getStats(int $currentChapter): array
    {
        try {
            $recent = DB::fetchAll(
                'SELECT chapter_number, opening_type, actual_opening_type, hook_type, cool_point_type, actual_cool_point_types
                 FROM chapters
                 WHERE novel_id = ? AND chapter_number BETWEEN ? AND ?
                 AND status = "completed"
                 ORDER BY chapter_number ASC',
                [
                    $this->novelId,
                    max(1, $currentChapter - self::WINDOW + 1),
                    $currentChapter
                ]
            );

            if (empty($recent)) {
                return ['total' => 0, 'patterns' => []];
            }

            $signatures = [];
            $openingCounts = [];
            $hookCounts = [];
            $coolCounts = [];

            foreach ($recent as $ch) {
                $sig = $this->buildSignature($ch);
                $signatures[$ch['chapter_number']] = $sig;

                $parts = explode('|', $sig);
                $opening = $parts[0] ?? 'unknown';
                $hook = $parts[1] ?? 'none';
                $cool = $parts[2] ?? 'none';

                $openingCounts[$opening] = ($openingCounts[$opening] ?? 0) + 1;
                $hookCounts[$hook] = ($hookCounts[$hook] ?? 0) + 1;
                $coolCounts[$cool] = ($coolCounts[$cool] ?? 0) + 1;
            }

            // 签名频率
            $sigCounts = array_count_values($signatures);
            arsort($sigCounts);

            $topPatterns = [];
            foreach (array_slice($sigCounts, 0, 5, true) as $sig => $count) {
                $topPatterns[] = [
                    'pattern' => $this->describePattern($sig),
                    'count' => $count,
                    'chapters' => array_keys(array_filter($signatures, fn($s) => $s === $sig)),
                ];
            }

            return [
                'total' => count($recent),
                'unique_patterns' => count($sigCounts),
                'top_patterns' => $topPatterns,
                'opening_distribution' => $openingCounts,
                'hook_distribution' => $hookCounts,
                'cool_point_distribution' => $coolCounts,
            ];

        } catch (\Throwable $e) {
            return ['total' => 0, 'patterns' => [], 'error' => $e->getMessage()];
        }
    }
}
