<?php
/**
 * 认知负荷监控器
 *
 * 功能：检测一章引入的新元素数量，防止读者认知过载
 * 触发：章节完成后 MemoryEngine::ingestChapter 末尾调用
 * 输出：认知负荷分析报告 + Agent 指令（当新元素过多时）
 *
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

class CognitiveLoadMonitor
{
    /** @var int 小说ID */
    private int $novelId;

    /** @var int 单章最多引入的核心新元素数量 */
    private const MAX_NEW_ELEMENTS_PER_CHAPTER = 3;

    /** @var int 近 N 章累计最多引入的新元素数量 */
    private const MAX_NEW_ELEMENTS_5_CHAPTERS = 12;

    /** @var int 检测窗口 */
    private const WINDOW = 5;

    /**
     * 构造函数
     */
    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 分析章节的认知负荷
     *
     * @param int   $chapterNumber 章节号
     * @param array $ingestReport  MemoryEngine::ingestChapter 的返回值
     * @return array 分析结果
     */
    public function analyze(int $chapterNumber, array $ingestReport): array
    {
        $result = [
            'severity'       => 'ok',
            'total_new'      => 0,
            'new_characters' => [],
            'new_locations'  => [],
            'new_concepts'   => [],
            'recent_5_sum'   => 0,
            'message'        => '',
            'directive'      => null,
        ];

        try {
            // 从 ingestReport 中提取新增元素
            $result['new_characters'] = $this->extractNewCharacters($ingestReport);
            $result['new_locations']  = $this->extractNewLocations($ingestReport);
            $result['new_concepts']   = $this->extractNewConcepts($ingestReport);

            $result['total_new'] = count($result['new_characters'])
                                 + count($result['new_locations'])
                                 + count($result['new_concepts']);

            // 检查 1：单章新元素数量
            if ($result['total_new'] > self::MAX_NEW_ELEMENTS_PER_CHAPTER) {
                $result['severity'] = 'high';
                $result['message']  = sprintf(
                    '本章引入 %d 个新元素，超出建议上限 %d 个',
                    $result['total_new'],
                    self::MAX_NEW_ELEMENTS_PER_CHAPTER
                );
                $result['directive'] = $this->buildDirective($result);
                return $result;
            }

            // 检查 2：近 5 章累计新元素数量
            $result['recent_5_sum'] = $this->getRecent5ChaptersSum($chapterNumber, $result['total_new']);

            if ($result['recent_5_sum'] > self::MAX_NEW_ELEMENTS_5_CHAPTERS) {
                $result['severity'] = 'medium';
                $result['message']  = sprintf(
                    '近 5 章累计引入 %d 个新元素，超出建议上限 %d 个',
                    $result['recent_5_sum'],
                    self::MAX_NEW_ELEMENTS_5_CHAPTERS
                );
                $result['directive'] = $this->buildDirective($result);
                return $result;
            }

            // 正常情况
            if ($result['total_new'] > 0) {
                $result['message'] = sprintf(
                    '本章引入 %d 个新元素，在合理范围内',
                    $result['total_new']
                );
            }

        } catch (\Throwable $e) {
            error_log('CognitiveLoadMonitor::analyze failed: ' . $e->getMessage());
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 将分析结果持久化到 chapters 表
     */
    public function persistMetrics(int $chapterId, array $analysis): void
    {
        try {
            DB::update('chapters', [
                'cognitive_load' => json_encode([
                    'severity'       => $analysis['severity'],
                    'total_new'      => $analysis['total_new'],
                    'new_characters' => $analysis['new_characters'],
                    'new_locations'  => $analysis['new_locations'],
                    'new_concepts'   => $analysis['new_concepts'],
                    'recent_5_sum'   => $analysis['recent_5_sum'],
                ], JSON_UNESCAPED_UNICODE),
            ], 'id=?', [$chapterId]);
        } catch (\Throwable $e) {
            error_log('CognitiveLoadMonitor::persistMetrics failed: ' . $e->getMessage());
        }
    }

    /**
     * 生成 Agent 指令
     */
    public function buildDirective(array $analysis): string
    {
        $newElements = array_merge(
            $analysis['new_characters'],
            $analysis['new_locations'],
            $analysis['new_concepts']
        );

        $elementsList = implode('、', array_slice($newElements, 0, 5));
        if (count($newElements) > 5) {
            $elementsList .= '等';
        }

        $directive = "【认知负荷警告】上章引入了 {$analysis['total_new']} 个新元素：{$elementsList}。\n";
        $directive .= "本章请务必：\n";
        $directive .= "1. 不再引入新角色/新地点/新概念\n";
        $directive .= "2. 让上章引入的新元素之间发生交互，建立读者印象\n";
        $directive .= "3. 优先用已存在的角色推进情节\n";
        $directive .= "避免读者产生「人名地名太多记不住」的疲劳感。";

        return $directive;
    }

    /**
     * 从 ingestReport 中提取新人物
     * v1.11.2 Bug #4 修复：使用 cards_inserted 而非 cards_upserted
     */
    private function extractNewCharacters(array $report): array
    {
        $characters = [];

        // v1.11.2 修复：只用 cards_inserted（真正新增的角色）
        $insertedCount = $report['cards_inserted'] ?? 0;
        if ($insertedCount > 0) {
            // 获取最近新增的人物卡片（通过 created_at 时间判断）
            try {
                $newCards = DB::fetchAll(
                    "SELECT name FROM character_cards
                     WHERE novel_id = ?
                       AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                     ORDER BY id DESC
                     LIMIT ?",
                    [$this->novelId, $insertedCount]
                );
                foreach ($newCards as $card) {
                    $characters[] = $card['name'];
                }
            } catch (\Throwable $e) {
                // 降级：尝试用 last_updated_chapter 查询
                try {
                    $newCards = DB::fetchAll(
                        "SELECT name FROM character_cards
                         WHERE novel_id = ?
                           AND last_updated_chapter = (
                               SELECT MAX(last_updated_chapter) FROM character_cards WHERE novel_id = ?
                           )
                         ORDER BY id DESC
                         LIMIT ?",
                        [$this->novelId, $this->novelId, $insertedCount]
                    );
                    foreach ($newCards as $card) {
                        $characters[] = $card['name'];
                    }
                } catch (\Throwable $e2) {
                    // 静默忽略
                }
            }
        }

        return array_unique(array_filter($characters));
    }

    /**
     * 从 ingestReport 中提取新地点
     * v1.11.2 Bug #5 修复：使用 new_atom_ids 精确查询，回退到 5 分钟时间窗口
     */
    private function extractNewLocations(array $report): array
    {
        $locations = [];

        // v1.11.2: 优先使用 new_atom_ids 精确查询
        $atomIds = $report['new_atom_ids'] ?? [];
        if (!empty($atomIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($atomIds), '?'));
                $newSettings = DB::fetchAll(
                    "SELECT content FROM memory_atoms
                     WHERE novel_id = ?
                       AND atom_type = 'world_setting'
                       AND id IN ({$placeholders})
                     ORDER BY id DESC
                     LIMIT 5",
                    array_merge([$this->novelId], $atomIds)
                );
                foreach ($newSettings as $s) {
                    if (preg_match('/^([^:：]+)/u', $s['content'] ?? '', $m)) {
                        $location = trim($m[1]);
                        if ($location && mb_strlen($location) <= 20) {
                            $locations[] = $location;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 回退到时间查询
            }
        }

        // 回退：从 memory_atoms 中查找 world_setting 类型的新增（扩大时间窗口到 5 分钟）
        if (empty($locations)) {
            try {
                $newSettings = DB::fetchAll(
                    "SELECT content FROM memory_atoms
                     WHERE novel_id = ?
                       AND atom_type = 'world_setting'
                       AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                     ORDER BY id DESC
                     LIMIT 5",
                    [$this->novelId]
                );
                foreach ($newSettings as $s) {
                    if (preg_match('/^([^:：]+)/u', $s['content'] ?? '', $m)) {
                        $location = trim($m[1]);
                        if ($location && mb_strlen($location) <= 20) {
                            $locations[] = $location;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 静默忽略
            }
        }

        return array_unique(array_filter($locations));
    }

    /**
     * 从 ingestReport 中提取新概念/术语
     * v1.11.2 Bug #5 修复：使用 new_atom_ids 精确查询，回退到 5 分钟时间窗口
     */
    private function extractNewConcepts(array $report): array
    {
        $concepts = [];

        // v1.11.2: 优先使用 new_atom_ids 精确查询
        $atomIds = $report['new_atom_ids'] ?? [];
        if (!empty($atomIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($atomIds), '?'));
                $newAtoms = DB::fetchAll(
                    "SELECT atom_type, content FROM memory_atoms
                     WHERE novel_id = ?
                       AND atom_type IN ('constraint', 'technique', 'world_state')
                       AND id IN ({$placeholders})
                     ORDER BY id DESC
                     LIMIT 5",
                    array_merge([$this->novelId], $atomIds)
                );
                foreach ($newAtoms as $atom) {
                    $content = $atom['content'] ?? '';
                    if (preg_match('/^([^:：]{2,20})/u', $content, $m)) {
                        $concept = trim($m[1]);
                        if ($concept && mb_strlen($concept) >= 2) {
                            $concepts[] = $concept;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 回退到时间查询
            }
        }

        // 回退：从 memory_atoms 中查找新增的概念性内容（扩大时间窗口到 5 分钟）
        if (empty($concepts)) {
            try {
                $newAtoms = DB::fetchAll(
                    "SELECT atom_type, content FROM memory_atoms
                     WHERE novel_id = ?
                       AND atom_type IN ('constraint', 'technique', 'world_state')
                       AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                     ORDER BY id DESC
                     LIMIT 5",
                    [$this->novelId]
                );
                foreach ($newAtoms as $atom) {
                    $content = $atom['content'] ?? '';
                    if (preg_match('/^([^:：]{2,20})/u', $content, $m)) {
                        $concept = trim($m[1]);
                        if ($concept && mb_strlen($concept) >= 2) {
                            $concepts[] = $concept;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 静默忽略
            }
        }

        return array_unique(array_filter($concepts));
    }

    /**
     * 获取近 5 章累计新元素数量
     */
    private function getRecent5ChaptersSum(int $currentChapter, int $currentNew): int
    {
        $sum = $currentNew;

        try {
            $recent = DB::fetchAll(
                "SELECT chapter_number, cognitive_load
                 FROM chapters
                 WHERE novel_id = ?
                   AND chapter_number < ?
                   AND chapter_number >= ?
                   AND status = 'completed'
                   AND cognitive_load IS NOT NULL
                 ORDER BY chapter_number DESC
                 LIMIT ?",
                [
                    $this->novelId,
                    $currentChapter,
                    max(1, $currentChapter - self::WINDOW + 1),
                    self::WINDOW - 1
                ]
            );

            foreach ($recent as $ch) {
                $load = json_decode($ch['cognitive_load'] ?? '{}', true);
                if (is_array($load) && isset($load['total_new'])) {
                    $sum += (int)$load['total_new'];
                }
            }
        } catch (\Throwable $e) {
            // 静默忽略
        }

        return $sum;
    }

    /**
     * 获取近 N 章的认知负荷趋势（用于 UI 展示）
     */
    public function getTrend(int $currentChapter, int $window = 30): array
    {
        $trend = [];

        try {
            $chapters = DB::fetchAll(
                "SELECT chapter_number, cognitive_load
                 FROM chapters
                 WHERE novel_id = ?
                   AND chapter_number <= ?
                   AND status = 'completed'
                   AND cognitive_load IS NOT NULL
                 ORDER BY chapter_number ASC
                 LIMIT ?",
                [$this->novelId, $currentChapter, $window]
            );

            foreach ($chapters as $ch) {
                $load = json_decode($ch['cognitive_load'] ?? '{}', true);
                if (is_array($load)) {
                    $trend[] = [
                        'chapter'   => (int)$ch['chapter_number'],
                        'total_new' => (int)($load['total_new'] ?? 0),
                        'severity'  => $load['severity'] ?? 'ok',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // 返回空数组
        }

        return $trend;
    }

    /**
     * 获取当前认知负荷状态摘要
     */
    public function getStatus(int $currentChapter): array
    {
        $status = [
            'current_chapter'       => $currentChapter,
            'recent_5_new_elements' => 0,
            'trend'                 => 'stable',
            'recommendation'        => '',
        ];

        try {
            // 近 5 章累计
            $status['recent_5_new_elements'] = $this->getRecent5ChaptersSum($currentChapter, 0);

            // 趋势判断
            $trend = $this->getTrend($currentChapter, 10);
            if (count($trend) >= 5) {
                $recent5 = array_slice($trend, -5);
                $prev5   = array_slice($trend, -10, 5);

                $recentAvg = array_sum(array_column($recent5, 'total_new')) / count($recent5);
                $prevAvg   = array_sum(array_column($prev5, 'total_new')) / count($prev5);

                if ($recentAvg > $prevAvg * 1.3) {
                    $status['trend'] = 'increasing';
                    $status['recommendation'] = '近期新元素密度上升，建议放缓引入新角色的节奏';
                } elseif ($recentAvg < $prevAvg * 0.7) {
                    $status['trend'] = 'decreasing';
                    $status['recommendation'] = '近期新元素密度下降，可以适度引入新角色/设定';
                }
            }

            // 超标警告
            if ($status['recent_5_new_elements'] > self::MAX_NEW_ELEMENTS_5_CHAPTERS) {
                $status['recommendation'] = '近5章新元素过多，建议本章专注已有元素的互动';
            }
        } catch (\Throwable $e) {
            // 返回默认值
        }

        return $status;
    }
}
