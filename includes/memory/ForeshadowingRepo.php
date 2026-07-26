<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/helpers.php';

/**
 * ================================================================
 * ForeshadowingRepo — 伏笔仓储
 *
 * 替代 novels.pending_foreshadowing JSON + 旧 foreshadowing_log 表。
 * 统一到一张 foreshadowing_items 表:未回收的 resolved_chapter IS NULL。
 *
 * 回收匹配策略:
 *   1) 精确文本 LIKE(兼容历史行为)
 *   2) 若 embedding 存在 → 额外走语义召回(MemoryEngine 里做,这里只提供接口)
 * ================================================================
 */
final class ForeshadowingRepo
{
    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 埋一个新伏笔
     *
     * @param string $description         一句话描述
     * @param int    $plantedChapter      埋设章节
     * @param int|null $deadlineChapter   建议回收截止章节
     * @param string $priority            优先级: critical/major/minor (默认 minor)
     * @return int  item_id
     */
    public function plant(string $description, int $plantedChapter, ?int $deadlineChapter = null, string $priority = 'minor'): int
    {
        $desc = trim($description);
        if ($desc === '') {
            throw new \InvalidArgumentException('foreshadowing description is empty');
        }
        $validPriorities = ['critical', 'major', 'minor'];
        if (!in_array($priority, $validPriorities, true)) {
            $priority = 'minor';
        }
        return (int)DB::insert('foreshadowing_items', [
            'novel_id'         => $this->novelId,
            'description'      => $desc,
            'priority'         => $priority,
            'planted_chapter'  => $plantedChapter,
            'deadline_chapter' => $deadlineChapter,
        ]);
    }

    /**
     * 尝试把"已回收描述"匹配到具体的未回收伏笔并标记为已回收。
     *
     * 匹配策略（v1.1改进版）:
     *   0) 先尝试关键词重叠匹配（最宽松，AI改写后也能匹配）
     *   1) 再用 LIKE 获取多条候选（最多 5 条）
     *   2) 若候选有 embedding → 计算余弦相似度，选最相似的（阈值 0.8）
     *   3) 若无 embedding → 使用更严格的文本匹配（完整描述或更长前缀 30 字符）
     *
     * @param string $resolvedDesc    回收描述
     * @param int    $resolvedChapter 回收章节
     * @return int 成功返回 item_id，失败返回 0
     */
    public function tryResolve(string $resolvedDesc, int $resolvedChapter): int
    {
        $desc = trim($resolvedDesc);
        if ($desc === '') return 0;

        // v1.1: 优先使用关键词重叠匹配（AI改写后也能匹配）
        $candidates = $this->keywordMatchCandidates($desc);

        // 第二步：用前 30 字符获取候选列表（作为补充）
        if (empty($candidates)) {
            $kw = mb_substr($desc, 0, 30);
            // 审计修复 P3（2026-07-12）：转义 LIKE 通配符
            $candidates = DB::fetchAll(
                "SELECT id, description, embedding, embedding_model
                 FROM foreshadowing_items
                 WHERE novel_id=? AND resolved_chapter IS NULL
                   AND description LIKE ? ESCAPE '\\\\'
                 ORDER BY planted_chapter ASC LIMIT 5",
                [$this->novelId, '%' . escapeLikeWildcards($kw) . '%']
            );
        }

        // 第三步：降级——精确完整匹配
        if (empty($candidates)) {
            $candidates = DB::fetchAll(
                'SELECT id, description, embedding, embedding_model
                 FROM foreshadowing_items
                 WHERE novel_id=? AND resolved_chapter IS NULL
                   AND description = ?
                 ORDER BY planted_chapter ASC LIMIT 1',
                [$this->novelId, $desc]
            );
        }

        if (empty($candidates)) {
            // v1.11.8: 记录匹配失败
            addLog($this->novelId, 'debug', sprintf(
                '伏笔回收匹配失败：AI返回「%s」，但数据库无匹配',
                mb_substr($desc, 0, 50)
            ));
            return 0;
        }

        // 单条候选直接返回
        if (count($candidates) === 1) {
            $this->markResolved((int)$candidates[0]['id'], $resolvedChapter);
            addLog($this->novelId, 'debug', sprintf(
                '伏笔回收成功(ID:%d)：「%s」',
                $candidates[0]['id'],
                mb_substr($candidates[0]['description'], 0, 40)
            ));
            return (int)$candidates[0]['id'];
        }

        // 多条候选：优先使用 embedding 语义匹配
        $bestId = $this->selectBestCandidate($desc, $candidates);
        if ($bestId > 0) {
            $this->markResolved($bestId, $resolvedChapter);
            addLog($this->novelId, 'debug', sprintf(
                '伏笔回收成功(embedding匹配 ID:%d)',
                $bestId
            ));
            return $bestId;
        }

        // 多条候选无 embedding：用关键词重叠选最佳
        $bestId = $this->selectByKeywordOverlap($desc, $candidates);
        if ($bestId > 0) {
            $this->markResolved($bestId, $resolvedChapter);
            addLog($this->novelId, 'debug', sprintf(
                '伏笔回收成功(关键词匹配 ID:%d)',
                $bestId
            ));
            return $bestId;
        }

        // 审计修复（2026-07-19 H-中2）：原实现"最终降级：选第一条（最老的）"会强行回收
        // 一个不相关的伏笔 -> 伏笔被误回收永久丢失。改为记日志返回 0（不强行回收）。
        addLog($this->novelId, 'warn', sprintf(
            '伏笔回收失败：%d 条候选均未通过 embedding/关键词匹配，保留未回收状态',
            count($candidates)
        ));
        return 0;
    }

    /**
     * 关键词重叠匹配：当文本模糊匹配失败时，用分词关键词找候选
     *
     * @param string $desc 回收描述
     * @return array 候选列表
     */
    private function keywordMatchCandidates(string $desc): array
    {
        // 提取关键词：2字以上中文词组 + 3字以上英文/数字
        preg_match_all('/[\x{4e00}-\x{9fa5}]{2,}|[a-zA-Z0-9_]{3,}/u', $desc, $m);
        $keywords = $m[0] ?? [];
        if (empty($keywords)) return [];

        // 用任意关键词做 LIKE 匹配
        // 审计修复 P3（2026-07-12）：转义 LIKE 通配符
        $params = [$this->novelId];
        $clauses = [];
        foreach (array_slice($keywords, 0, 5) as $kw) {
            if (mb_strlen($kw) < 2) continue;
            $clauses[] = "description LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . escapeLikeWildcards($kw) . '%';
        }
        if (empty($clauses)) return [];

        $sql = 'SELECT id, description, embedding, embedding_model
                FROM foreshadowing_items
                WHERE novel_id=? AND resolved_chapter IS NULL
                  AND (' . implode(' OR ', $clauses) . ')
                ORDER BY planted_chapter ASC LIMIT 5';

        return DB::fetchAll($sql, $params);
    }

    /**
     * 多条候选时用关键词重叠率选最佳
     *
     * @param string $desc       回收描述
     * @param array  $candidates 候选列表
     * @return int 最佳候选 ID，失败返回 0
     */
    private function selectByKeywordOverlap(string $desc, array $candidates): int
    {
        preg_match_all('/[\x{4e00}-\x{9fa5}]{2,}|[a-zA-Z0-9_]{3,}/u', $desc, $m);
        $queryKeywords = array_unique($m[0] ?? []);
        if (empty($queryKeywords)) return 0;

        $bestId = 0;
        $bestScore = 0;

        foreach ($candidates as $c) {
            preg_match_all('/[\x{4e00}-\x{9fa5}]{2,}|[a-zA-Z0-9_]{3,}/u', $c['description'], $m2);
            $candKeywords = array_unique($m2[0] ?? []);
            if (empty($candKeywords)) continue;

            $intersect = count(array_intersect($queryKeywords, $candKeywords));
            $union = count(array_unique(array_merge($queryKeywords, $candKeywords)));
            $score = $union > 0 ? $intersect / $union : 0;

            if ($score > $bestScore && $score >= 0.3) {
                $bestScore = $score;
                $bestId = (int)$c['id'];
            }
        }

        return $bestId;
    }

    /**
     * 从多条候选中选择最匹配的一条（使用 embedding 余弦相似度）
     *
     * @param string $desc       回收描述
     * @param array  $candidates 候选列表
     * @return int 最佳候选 ID，失败返回 0
     */
    private function selectBestCandidate(string $desc, array $candidates): int
    {
        // 检查是否有 embedding 配置
        require_once __DIR__ . '/EmbeddingProvider.php';
        $cfg = EmbeddingProvider::getConfig();
        if (!$cfg) return 0;  // 无 embedding 能力

        // 为回收描述生成 embedding
        $embedResult = EmbeddingProvider::embed($desc);
        if (!$embedResult || empty($embedResult['vec'])) return 0;

        $queryVec = $embedResult['vec'];
        $bestId = 0;
        $bestScore = 0.0;
        $threshold = 0.8;  // 余弦相似度阈值

        foreach ($candidates as $c) {
            // 候选必须有 embedding 且模型一致
            if (empty($c['embedding']) || empty($c['embedding_model'])) continue;
            if ($c['embedding_model'] !== $embedResult['model']) continue;

            // 解析 BLOB 中的向量
            $candidateVec = $this->parseEmbeddingBlob($c['embedding']);
            if (empty($candidateVec)) continue;

            // 计算余弦相似度
            $score = $this->cosineSimilarity($queryVec, $candidateVec);
            if ($score > $bestScore && $score >= $threshold) {
                $bestScore = $score;
                $bestId = (int)$c['id'];
            }
        }

        return $bestId;
    }

    /**
     * 解析 BLOB 存储的 embedding 向量
     *
     * @param string $blob BLOB 数据
     * @return array|null 向量数组，失败返回 null
     */
    private function parseEmbeddingBlob(string $blob): ?array
    {
        // 审计修复（2026-07-19 H-中3）：原实现按"长度是否 4 的倍数"判断 JSON vs blob，
        // 但 JSON 字符串长度恰为 4 倍数时会被误判为 blob -> 解出垃圾浮点向量。
        // 改为按首字符嗅探：'[' 为 JSON，否则尝试 blob。
        if ($blob === '') return null;
        $first = $blob[0];

        // JSON 数组优先（避免 JSON 字符串长度恰为 4 倍数时被误判为 blob）
        if ($first === '[') {
            $decoded = json_decode($blob, true);
            if (is_array($decoded) && !empty($decoded)) {
                return array_map('floatval', $decoded);
            }
        }

        // 二进制 float32 小端序（当前 Vector::pack 实际存储格式）
        $len = strlen($blob);
        if ($len > 0 && $len % 4 === 0) {
            try {
                require_once __DIR__ . '/Vector.php';
                $vec = Vector::unpack($blob);
                if (!empty($vec) && is_array($vec)) {
                    return $vec;
                }
            } catch (\Throwable $e) {
                // 二进制解析失败，继续尝试其他格式
            }
        }

        // 兼容旧格式：serialize（首字符 'a'）
        if ($first === 'a') {
            $unserialized = @unserialize($blob, ['allowed_classes' => false]);
            if (is_array($unserialized) && !empty($unserialized)) {
                return array_map('floatval', $unserialized);
            }
        }

        return null;
    }

    /**
     * 计算两个向量的余弦相似度
     *
     * @param array $vec1 向量1
     * @param array $vec2 向量2
     * @return float 相似度 [0, 1]
     */
    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        // 审计修复（2026-07-19 H-07 NEW-B）：维度不一致时返回 0，而非按较短前缀计算。
        // 原实现 min() 截断会在同模型名但维度变化时产生无意义相似度，可能误召回伏笔。
        $n = count($vec1);
        if ($n === 0 || $n !== count($vec2)) return 0.0;

        $dot = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dot += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        $denom = sqrt($norm1) * sqrt($norm2);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * 标记伏笔为已回收
     *
     * @param int $itemId         伏笔 ID
     * @param int $resolvedChapter 回收章节
     */
    private function markResolved(int $itemId, int $resolvedChapter): void
    {
        // 加固：与 resolveById 一致限定 novel_id + resolved_chapter IS NULL，
        // 防止未来调用方传入非本 novel 的 id 时跨novel误写或重复回收覆盖 resolved_at。
        DB::update('foreshadowing_items', [
            'resolved_chapter' => $resolvedChapter,
            'resolved_at'      => date('Y-m-d H:i:s'),
        ], 'id=? AND novel_id=? AND resolved_chapter IS NULL', [$itemId, $this->novelId]);
    }

    /**
     * 通过明确的 item_id 回收一条(供语义匹配后调用)
     */
    public function resolveById(int $itemId, int $resolvedChapter): bool
    {
        $n = DB::update('foreshadowing_items', [
            'resolved_chapter' => $resolvedChapter,
            'resolved_at'      => date('Y-m-d H:i:s'),
        ], 'id=? AND novel_id=? AND resolved_chapter IS NULL', [$itemId, $this->novelId]);
        return $n > 0;
    }

    /**
     * 删除一条伏笔(管理面板误植 / 过时废弃时用)。
     * 校验归属，避免跨 novel 误删。
     */
    public function delete(int $itemId): bool
    {
        $affected = DB::execute(
            'DELETE FROM foreshadowing_items WHERE id=? AND novel_id=?',
            [$itemId, $this->novelId]
        );
        return $affected > 0;
    }

    /**
     * 所有未回收的伏笔,按埋设时间升序
     */
    public function listPending(): array
    {
        return DB::fetchAll(
            'SELECT id, description, planted_chapter, deadline_chapter, created_at
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
             ORDER BY planted_chapter ASC',
            [$this->novelId]
        );
    }

    /**
     * 所有未回收的伏笔（含完整字段，供 ForeshadowingResolver 评分使用）
     */
    public function listPendingWithDetails(): array
    {
        return DB::fetchAll(
            'SELECT id, description, priority, planted_chapter, deadline_chapter,
                    last_mentioned_chapter, mention_count, created_at
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
             ORDER BY planted_chapter ASC',
            [$this->novelId]
        );
    }

    /**
     * 已逾期(过了 deadline 还没回收)的伏笔
     */
    public function listOverdue(int $currentChapter, int $buffer = 3): array
    {
        return DB::fetchAll(
            'SELECT id, description, planted_chapter, deadline_chapter
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
               AND deadline_chapter IS NOT NULL
               AND deadline_chapter < ?
             ORDER BY deadline_chapter ASC',
            [$this->novelId, $currentChapter - $buffer]
        );
    }

    /**
     * 临近 deadline(提前 $ahead 章内应考虑回收)的伏笔
     */
    public function listDueSoon(int $currentChapter, int $ahead = 5): array
    {
        return DB::fetchAll(
            'SELECT id, description, planted_chapter, deadline_chapter
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
               AND deadline_chapter IS NOT NULL
               AND deadline_chapter BETWEEN ? AND ?
             ORDER BY deadline_chapter ASC',
            [$this->novelId, $currentChapter, $currentChapter + $ahead]
        );
    }

    /**
     * 状态概览:返回 [total_pending, overdue_count, overdue_list]
     */
    public function status(int $currentChapter): array
    {
        $total = DB::count(
            'foreshadowing_items',
            'novel_id=? AND resolved_chapter IS NULL',
            [$this->novelId]
        );
        $overdue = $this->listOverdue($currentChapter);
        return [
            'total_pending' => $total,
            'overdue_count' => count($overdue),
            'overdue'       => $overdue,
        ];
    }

    /**
     * 迁移脚本用:整批导入
     *
     * @param array $items  [['desc'=>..., 'chapter'=>..., 'deadline'=>...], ...]
     */
    public function bulkImport(array $items): int
    {
        $n = 0;
        foreach ($items as $it) {
            $desc = trim((string)($it['desc'] ?? ''));
            if ($desc === '') continue;
            $this->plant(
                $desc,
                (int)($it['chapter'] ?? 0),
                !empty($it['deadline']) ? (int)$it['deadline'] : null
            );
            $n++;
        }
        return $n;
    }

    // ============================================================
    //  伏笔生命周期监控 (v1.10.3)
    // ============================================================

    /**
     * 记录某伏笔在当前章节被提及
     */
    public function recordMention(int $itemId, int $chapterNum): void
    {
        DB::execute(
            'UPDATE foreshadowing_items SET last_mentioned_chapter=?, mention_count=mention_count+1 WHERE id=? AND novel_id=?',
            [$chapterNum, $itemId, $this->novelId]
        );
        try {
            DB::insert('foreshadowing_mention_log', [
                'foreshadowing_id' => $itemId,
                'novel_id'         => $this->novelId,
                'chapter_number'   => $chapterNum,
            ]);
        } catch (\Throwable $e) { error_log('ForeshadowingRepo mention log insert failed: ' . $e->getMessage()); }
    }

    /**
     * 扫描章节内容，自动更新伏笔的提及记录
     * v1.11.8: 改进匹配策略，提取关键词匹配而非完整描述
     */
    public function trackMentionsInContent(string $content, int $chapterNum): int
    {
        $pending = DB::fetchAll(
            'SELECT id, description FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL',
            [$this->novelId]
        );
        if (empty($pending)) return 0;

        $matched = 0;
        foreach ($pending as $item) {
            $desc = $item['description'];

            // 策略1: 前15字符精确匹配
            $kw1 = mb_substr($desc, 0, 15);
            if (mb_strlen($kw1) >= 4 && mb_strpos($content, $kw1) !== false) {
                $this->recordMention((int)$item['id'], $chapterNum);
                $matched++;
                continue;
            }

            // 策略2: 提取关键词（2字以上中文词组）匹配
            preg_match_all('/[\x{4e00}-\x{9fa5}]{2,}/u', $desc, $m);
            $keywords = array_unique($m[0] ?? []);
            $hitCount = 0;
            foreach (array_slice($keywords, 0, 5) as $kw) {
                if (mb_strlen($kw) >= 3 && mb_strpos($content, $kw) !== false) {
                    $hitCount++;
                }
            }
            // 3个以上关键词命中视为提及
            if ($hitCount >= 3) {
                $this->recordMention((int)$item['id'], $chapterNum);
                $matched++;
            }
        }
        return $matched;
    }

    /**
     * 伏笔健康度检测 — 每5章触发
     * 检查长期未提及的伏笔，返回告警列表
     *
     * @return array 告警列表，每个元素包含 foreshadow/age/since_last_mention/severity/message/suggestion
     */
    public function checkHealth(int $currentChapter): array
    {
        if ($currentChapter % 5 !== 0 || $currentChapter < 10) return [];

        $items = DB::fetchAll(
            'SELECT id, description, priority, planted_chapter, deadline_chapter,
                    last_mentioned_chapter, mention_count
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL',
            [$this->novelId]
        );
        if (empty($items)) return [];

        $alerts = [];
        foreach ($items as $item) {
            $planted = (int)$item['planted_chapter'];
            $age = $currentChapter - $planted;
            $lastMention = $item['last_mentioned_chapter'] !== null
                ? (int)$item['last_mentioned_chapter']
                : $planted;
            $sinceLastMention = $currentChapter - $lastMention;

            $severity = null;
            $message = '';
            $suggestion = '';

            if ($age > 20 && $sinceLastMention > 15) {
                $severity = 'high';
                $message = "伏笔「{$item['description']}」已埋{$age}章，{$sinceLastMention}章未触动，读者将遗忘";
                $suggestion = "本章可让角色偶然提及/想起该伏笔，1-2句话即可唤醒读者记忆";
            } elseif ($age > 10 && $sinceLastMention > 10 && ($item['priority'] === 'critical' || $item['priority'] === 'major')) {
                $severity = 'medium';
                $message = "重要伏笔「{$item['description']}」已埋{$age}章，{$sinceLastMention}章未提及";
                $suggestion = "适当安排一次轻提醒，保持伏笔热度";
            }

            if ($severity) {
                $alerts[] = [
                    'foreshadow_id'      => (int)$item['id'],
                    'foreshadow'         => $item['description'],
                    'priority'           => $item['priority'],
                    'age'                => $age,
                    'since_last_mention' => $sinceLastMention,
                    'mention_count'      => (int)($item['mention_count'] ?? 0),
                    'severity'           => $severity,
                    'message'            => $message,
                    'suggestion'         => $suggestion,
                ];
            }
        }

        return $alerts;
    }

    /**
     * 回滚指定章节的伏笔提及记录（重写后数据清理用）
     *
     * 1. 从 foreshadowing_mention_log 查找该章节的提及记录
     * 2. 对每个受影响的伏笔：mention_count-1，last_mentioned_chapter 回退到上一条日志
     * 3. 删除该章节的 mention_log 记录
     */
    public function revertMentionsForChapter(int $chapterNumber): int
    {
        try {
            $logs = DB::fetchAll(
                'SELECT foreshadowing_id FROM foreshadowing_mention_log WHERE novel_id=? AND chapter_number=?',
                [$this->novelId, $chapterNumber]
            );
            if (empty($logs)) return 0;

            $affected = 0;
            foreach ($logs as $log) {
                $fid = (int)$log['foreshadowing_id'];

                $prevLog = DB::fetch(
                    'SELECT chapter_number FROM foreshadowing_mention_log WHERE foreshadowing_id=? AND novel_id=? AND chapter_number<? ORDER BY chapter_number DESC LIMIT 1',
                    [$fid, $this->novelId, $chapterNumber]
                );

                $newLastMention = $prevLog ? (int)$prevLog['chapter_number'] : null;

                DB::execute(
                    'UPDATE foreshadowing_items SET mention_count=GREATEST(mention_count-1, 0), last_mentioned_chapter=? WHERE id=? AND novel_id=?',
                    [$newLastMention, $fid, $this->novelId]
                );
                $affected++;
            }

            DB::delete('foreshadowing_mention_log', 'novel_id=? AND chapter_number=?', [$this->novelId, $chapterNumber]);

            return $affected;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 删除指定章节埋设的伏笔（重写回滚用）
     *
     * 审计修复 M-2（2026-06-18）：plant() 直接 INSERT 无去重，重写路径二次
     * ingestChapter 会重复埋设相同伏笔。此方法在重写前清理本章 plant 的伏笔。
     *
     * 仅删除本章新埋设且未回收的伏笔；已回收的伏笔保留（避免破坏回收链）。
     *
     * @param int $chapterNumber 章节号
     * @return int 删除行数
     */
    public function revertPlantsForChapter(int $chapterNumber): int
    {
        try {
            // 仅删除本章埋设且尚未回收的伏笔；已回收的保留以维持回收历史完整
            return DB::delete(
                'foreshadowing_items',
                'novel_id=? AND planted_chapter=? AND resolved_chapter IS NULL',
                [$this->novelId, $chapterNumber]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
