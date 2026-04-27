<?php
defined('APP_LOADED') or die('Direct access denied.');

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
     * @return int  item_id
     */
    public function plant(string $description, int $plantedChapter, ?int $deadlineChapter = null): int
    {
        $desc = trim($description);
        if ($desc === '') {
            throw new \InvalidArgumentException('foreshadowing description is empty');
        }
        return (int)DB::insert('foreshadowing_items', [
            'novel_id'         => $this->novelId,
            'description'      => $desc,
            'planted_chapter'  => $plantedChapter,
            'deadline_chapter' => $deadlineChapter,
        ]);
    }

    /**
     * 尝试把"已回收描述"匹配到具体的未回收伏笔并标记为已回收。
     *
     * 匹配策略（改进版）:
     *   1) 先用 LIKE 获取多条候选（最多 5 条）
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

        // 第一步：用前 30 字符（而非 15）获取候选列表
        $kw = mb_substr($desc, 0, 30);
        $candidates = DB::fetchAll(
            'SELECT id, description, embedding, embedding_model
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
               AND description LIKE ?
             ORDER BY planted_chapter ASC LIMIT 5',
            [$this->novelId, '%' . $kw . '%']
        );

        if (empty($candidates)) {
            // 降级：尝试完整描述匹配
            $candidates = DB::fetchAll(
                'SELECT id, description, embedding, embedding_model
                 FROM foreshadowing_items
                 WHERE novel_id=? AND resolved_chapter IS NULL
                   AND description = ?
                 ORDER BY planted_chapter ASC LIMIT 1',
                [$this->novelId, $desc]
            );
        }

        if (empty($candidates)) return 0;

        // 单条候选直接返回
        if (count($candidates) === 1) {
            $this->markResolved((int)$candidates[0]['id'], $resolvedChapter);
            return (int)$candidates[0]['id'];
        }

        // 多条候选：优先使用 embedding 语义匹配
        $bestId = $this->selectBestCandidate($desc, $candidates);
        if ($bestId > 0) {
            $this->markResolved($bestId, $resolvedChapter);
            return $bestId;
        }

        // 无 embedding 或语义匹配失败：选第一条（最老的）
        $this->markResolved((int)$candidates[0]['id'], $resolvedChapter);
        return (int)$candidates[0]['id'];
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
        // 尝试 JSON 解析（如果是 JSON 格式存储）
        $decoded = json_decode($blob, true);
        if (is_array($decoded) && !empty($decoded)) {
            return array_map('floatval', $decoded);
        }

        // 尝试二进制解析（如果是 serialize 格式）
        $unserialized = @unserialize($blob);
        if (is_array($unserialized) && !empty($unserialized)) {
            return array_map('floatval', $unserialized);
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
        $n = min(count($vec1), count($vec2));
        if ($n === 0) return 0.0;

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
        DB::update('foreshadowing_items', [
            'resolved_chapter' => $resolvedChapter,
            'resolved_at'      => date('Y-m-d H:i:s'),
        ], 'id=?', [$itemId]);
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
}
