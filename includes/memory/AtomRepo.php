<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/helpers.php';

/**
 * ================================================================
 * AtomRepo — 长尾原子记忆仓储 (memory_atoms)
 *
 * 只存"无法放进 character_cards / foreshadowing_items 的"长尾知识:
 *   - character_trait   角色长尾特征 (爱好、口头禅等)
 *   - world_setting     世界观细节
 *   - plot_detail       情节细节
 *   - style_preference  风格偏好
 *   - constraint        硬约束
 *   - technique         功法/技艺/招式 (v8 新增，书名号《》自动归类到此)
 *   - world_state       世界切换/场景切换事件 (v8 新增)
 *
 * embedding 由懒触发器补齐(embedding_updated_at IS NULL 表示待补)。
 * ================================================================
 */
final class AtomRepo
{
    public const VALID_TYPES = [
        'character_trait', 'world_setting', 'plot_detail',
        'style_preference', 'constraint',
        'technique', 'world_state', 'cool_point',
    ];

    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 插入一条 atom(embedding 置空,等懒触发器补齐)
     */
    public function add(
        string $type, string $content,
        ?int $sourceChapter = null, float $confidence = 0.8,
        ?array $metadata = null
    ): int {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid atom type: $type");
        }
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('atom content is empty');
        }
        return (int)DB::insert('memory_atoms', [
            'novel_id'       => $this->novelId,
            'atom_type'      => $type,
            'content'        => $content,
            'source_chapter' => $sourceChapter,
            'confidence'     => $confidence,
            'metadata'       => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * 批量插入
     *
     * @param array $atoms  [['type'=>..., 'content'=>..., 'chapter'=>..., 'confidence'=>..., 'metadata'=>...], ...]
     */
    public function addBatch(array $atoms): int
    {
        $n = 0;
        foreach ($atoms as $a) {
            $type = $a['type'] ?? '';
            $content = $a['content'] ?? '';
            if (!in_array($type, self::VALID_TYPES, true)) continue;
            if (trim((string)$content) === '') continue;
            try {
                $this->add(
                    $type, (string)$content,
                    $a['chapter'] ?? null,
                    (float)($a['confidence'] ?? 0.8),
                    $a['metadata'] ?? null
                );
                $n++;
            } catch (\Throwable $e) {
                // 单条失败不中断批量
            }
        }
        return $n;
    }

    /**
     * 列出所有 atoms（支持按类型和章节过滤）
     * 这是 memory_actions.php 需要的方法
     *
     * @param string|null $type       原子类型过滤
     * @param int|null    $chapter    来源章节过滤
     * @param int         $limit      返回数量限制
     * @param int         $offset     偏移量（分页）
     * @return array
     */
    public function listAll(?string $type = null, ?int $chapter = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT id, atom_type, content, source_chapter, confidence, metadata, created_at
                FROM memory_atoms
                WHERE novel_id=?';
        $params = [$this->novelId];

        if ($type !== null) {
            $sql .= ' AND atom_type=?';
            $params[] = $type;
        }

        if ($chapter !== null) {
            $sql .= ' AND source_chapter=?';
            $params[] = $chapter;
        }

        $sql .= ' ORDER BY COALESCE(source_chapter, 0) DESC, id DESC LIMIT ' . (int)$limit
              . ' OFFSET ' . (int)$offset;

        return array_map([self::class, 'hydrate'], DB::fetchAll($sql, $params));
    }

    /**
     * 取最近 N 条某类型 atom(按 source_chapter 倒序)
     */
    public function latestByType(string $type, int $limit = 20, ?int $beforeChapter = null): array
    {
        $sql = 'SELECT id, atom_type, content, source_chapter, confidence, metadata, created_at
                FROM memory_atoms
                WHERE novel_id=? AND atom_type=?';
        $params = [$this->novelId, $type];
        if ($beforeChapter !== null) {
            $sql .= ' AND source_chapter < ?';
            $params[] = $beforeChapter;
        }
        $sql .= ' ORDER BY COALESCE(source_chapter, 0) DESC, id DESC LIMIT ' . (int)$limit;
        return array_map([self::class, 'hydrate'], DB::fetchAll($sql, $params));
    }

    /**
     * 关键词搜索 (MySQL FULLTEXT,fallback LIKE)
     *
     * @param string   $keyword        搜索关键词
     * @param ?string  $type           类型过滤
     * @param int      $limit          最多返回多少条
     * @param ?int     $beforeChapter  只返回 source_chapter IS NULL 或 < 此章节的 atom
     *                                 (避免语义召回把未来章节的剧透漏给写作 prompt)
     */
    public function search(string $keyword, ?string $type = null, int $limit = 10, ?int $beforeChapter = null): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') return [];

        // 审计修复（2026-07-19 H-中4）：上游 MemoryEngine 传入数百字整段 queryText
        // 作为一个 LIKE 模式必不命中 -> 关键词路名存实亡。
        // 改为提取关键词后逐词搜索，合并去重。
        $keywords = $this->extractKeywords($keyword);
        if (empty($keywords)) {
            // 兜底：原文 FULLTEXT + LIKE
            $rows = $this->searchFulltext($keyword, $type, $limit, $beforeChapter);
            if (empty($rows)) {
                $rows = $this->searchLike($keyword, $type, $limit, $beforeChapter);
            }
            return array_map([self::class, 'hydrate'], $rows);
        }

        $merged = [];
        $seen = [];
        foreach ($keywords as $kw) {
            $rows = $this->searchFulltext($kw, $type, $limit, $beforeChapter);
            if (empty($rows)) {
                $rows = $this->searchLike($kw, $type, $limit, $beforeChapter);
            }
            foreach ($rows as $r) {
                $id = $r['id'] ?? 0;
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                $merged[] = $r;
                if (count($merged) >= $limit) break 2;
            }
        }
        return array_map([self::class, 'hydrate'], $merged);
    }

    /**
     * 审计修复（2026-07-19 H-中4）：从长文本中提取关键词
     * 2字以上中文词组 + 3字以上英文/数字，最多 5 个
     */
    private function extractKeywords(string $text): array
    {
        preg_match_all('/[\x{4e00}-\x{9fa5}]{2,}|[a-zA-Z0-9_]{3,}/u', $text, $m);
        $keywords = $m[0] ?? [];
        // 去重并保留前 5 个
        $keywords = array_values(array_unique($keywords));
        return array_slice($keywords, 0, 5);
    }

    /**
     * 取 embedding 为空的 atoms(懒触发器批量补齐用)
     */
    public function listPendingEmbedding(int $limit = 50): array
    {
        return DB::fetchAll(
            'SELECT id, content FROM memory_atoms
             WHERE novel_id=? AND embedding_updated_at IS NULL
             ORDER BY id ASC LIMIT ' . (int)$limit,
            [$this->novelId]
        );
    }

    /**
     * 回填 embedding
     */
    public function updateEmbedding(int $id, string $blob, string $modelName): bool
    {
        $n = DB::update('memory_atoms', [
            'embedding'            => $blob,
            'embedding_model'      => $modelName,
            'embedding_updated_at' => date('Y-m-d H:i:s'),
        ], 'id=? AND novel_id=?', [$id, $this->novelId]);
        return $n > 0;
    }

    /**
     * 取带 embedding 的 atoms 候选集(给向量检索用)
     * 按 type 和章节范围过滤,减少候选量。
     *
     * 审计修复（2026-07-19 H-07 残留）：增加 $model 过滤，避免 embedding 模型切换后
     * 旧模型向量仍进入候选集（同维度不同语义空间）导致召回静默劣化。
     */
    public function listWithEmbedding(?string $type = null, ?int $beforeChapter = null, int $limit = 500, ?string $model = null): array
    {
        $sql = 'SELECT id, atom_type, content, source_chapter, confidence, embedding
                FROM memory_atoms
                WHERE novel_id=? AND embedding IS NOT NULL';
        $params = [$this->novelId];
        if ($type) {
            $sql .= ' AND atom_type=?';
            $params[] = $type;
        }
        if ($beforeChapter !== null) {
            $sql .= ' AND (source_chapter IS NULL OR source_chapter < ?)';
            $params[] = $beforeChapter;
        }
        if ($model !== null && $model !== '') {
            $sql .= ' AND embedding_model=?';
            $params[] = $model;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
        $rows = DB::fetchAll($sql, $params);

        // 把 embedding 字段重命名为 blob,方便 Vector::topK 使用
        foreach ($rows as &$r) {
            $r['blob'] = $r['embedding'];
            unset($r['embedding']);
        }
        unset($r);
        return $rows;
    }

    /**
     * 删除单条 atom
     */
    public function delete(int $atomId): bool
    {
        return DB::delete('memory_atoms', 'id=? AND novel_id=?', [$atomId, $this->novelId]) > 0;
    }

    /**
     * 更新 atom 内容
     */
    public function updateContent(int $atomId, string $content, ?string $atomType = null): bool
    {
        $data = ['content' => trim($content)];
        if ($atomType && in_array($atomType, self::VALID_TYPES, true)) {
            $data['atom_type'] = $atomType;
        }
        // 更新时清空 embedding，触发重新向量化
        $data['embedding_updated_at'] = null;
        $data['embedding'] = null;
        $data['embedding_model'] = null;
        return DB::update('memory_atoms', $data, 'id=? AND novel_id=?', [$atomId, $this->novelId]) > 0;
    }

    /**
     * 删除小说下所有 atoms(删除小说时级联)
     */
    public function deleteAllForNovel(): int
    {
        return DB::delete('memory_atoms', 'novel_id=?', [$this->novelId]);
    }

    /**
     * 删除指定章节的某类 atom（重写回滚用）
     *
     * 审计修复 M-2（2026-06-18）：ingestChapter 对 plot_detail(key_event) 和
     * cool_point 类型直接 INSERT 无去重，重写路径二次 ingest 会产生重复行。
     * 此方法在重写前清理本章的非幂等 atom。
     *
     * 注意：character_trait 类型已自带去重（按 character_name+trait_key UPDATE），
     * 不应通过此方法清理，否则会丢失历史特征累积。
     *
     * @param int    $chapterNumber 章节号
     * @param string $type          atom 类型（仅允许 plot_detail / cool_point）
     * @return int 删除行数
     */
    public function deleteByChapterAndType(int $chapterNumber, string $type): int
    {
        // 仅允许非幂等类型清理，防止误删 character_trait
        if (!in_array($type, ['plot_detail', 'cool_point'], true)) {
            return 0;
        }
        try {
            return DB::delete(
                'memory_atoms',
                'novel_id=? AND source_chapter=? AND atom_type=?',
                [$this->novelId, $chapterNumber, $type]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 统计:各类型有多少条
     */
    public function countByType(): array
    {
        $rows = DB::fetchAll(
            'SELECT atom_type, COUNT(*) AS cnt FROM memory_atoms
             WHERE novel_id=? GROUP BY atom_type',
            [$this->novelId]
        );
        $out = [];
        foreach ($rows as $r) $out[$r['atom_type']] = (int)$r['cnt'];
        return $out;
    }

    // ---------- 内部辅助 ----------

    private function searchFulltext(string $keyword, ?string $type, int $limit, ?int $beforeChapter = null): array
    {
        try {
            $sql = 'SELECT id, atom_type, content, source_chapter, confidence, metadata, created_at,
                           MATCH(content) AGAINST (? IN NATURAL LANGUAGE MODE) AS _rel
                    FROM memory_atoms
                    WHERE novel_id=? AND MATCH(content) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params = [$keyword, $this->novelId, $keyword];
            if ($type) { $sql .= ' AND atom_type=?'; $params[] = $type; }
            if ($beforeChapter !== null) {
                $sql .= ' AND (source_chapter IS NULL OR source_chapter < ?)';
                $params[] = $beforeChapter;
            }
            $sql .= ' ORDER BY _rel DESC LIMIT ' . (int)$limit;
            return DB::fetchAll($sql, $params);
        } catch (\Throwable $e) {
            // FULLTEXT 索引未建或不支持(如 MySQL < 5.7),fallback
            return [];
        }
    }

    private function searchLike(string $keyword, ?string $type, int $limit, ?int $beforeChapter = null): array
    {
        // 审计修复 P3（2026-07-12）：转义 LIKE 通配符
        $sql = "SELECT id, atom_type, content, source_chapter, confidence, metadata, created_at
                FROM memory_atoms
                WHERE novel_id=? AND content LIKE ? ESCAPE '\\\\'";
        $params = [$this->novelId, '%' . escapeLikeWildcards($keyword) . '%'];
        if ($type) { $sql .= ' AND atom_type=?'; $params[] = $type; }
        if ($beforeChapter !== null) {
            $sql .= ' AND (source_chapter IS NULL OR source_chapter < ?)';
            $params[] = $beforeChapter;
        }
        $sql .= ' ORDER BY confidence DESC, id DESC LIMIT ' . (int)$limit;
        return DB::fetchAll($sql, $params);
    }

    private static function hydrate(array $row): array
    {
        if (!empty($row['metadata'])) {
            $row['metadata'] = json_decode($row['metadata'], true) ?: [];
        } else {
            $row['metadata'] = [];
        }
        return $row;
    }
}
