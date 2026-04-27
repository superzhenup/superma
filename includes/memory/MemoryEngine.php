<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/Vector.php';
require_once __DIR__ . '/EmbeddingProvider.php';
require_once __DIR__ . '/CharacterCardRepo.php';
require_once __DIR__ . '/ForeshadowingRepo.php';
require_once __DIR__ . '/AtomRepo.php';

/**
 * ================================================================
 * MemoryEngine — 记忆引擎门面
 *
 * 主流程只需要和这个类打交道。它聚合了三个仓储和 embedding 提供方,
 * 对外暴露三个核心动作:
 *
 *   ingestChapter()       章节写完后一次性吞入 summary,写三类记忆
 *   getPromptContext()    写下一章前,统一取所有 prompt 需要的记忆段落
 *   ensureEmbeddings()    懒触发器:补齐当前小说里缺失的 embedding
 *
 * 所有的 token budget / 降级 / 容错都集中在这里处理,
 * 避免 prompt.php 和 write_chapter.php 再去关心底层数据结构。
 * ================================================================
 */
final class MemoryEngine
{
    private int $novelId;
    private CharacterCardRepo $cards;
    private ForeshadowingRepo $foreshadowing;
    private AtomRepo $atoms;

    public function __construct(int $novelId)
    {
        $this->novelId       = $novelId;
        $this->cards         = new CharacterCardRepo($novelId);
        $this->foreshadowing = new ForeshadowingRepo($novelId);
        $this->atoms         = new AtomRepo($novelId);
    }

    // 仓储访问器(供 api/memory_actions.php 管理界面直接调用)
    public function cards(): CharacterCardRepo         { return $this->cards; }
    public function foreshadowing(): ForeshadowingRepo { return $this->foreshadowing; }
    public function atoms(): AtomRepo                   { return $this->atoms; }

    // =================================================================
    // 1. 写入路径 — 章节完成后调用
    // =================================================================

    /**
     * 吞入一章的 summary 数据(generateChapterSummary() 的产物),
     * 分发到三个仓储。本方法幂等失败容忍:单项失败不影响其他项。
     *
     * @param int   $chapterNumber  本章章节号
     * @param array $summary        generateChapterSummary 返回的结构,含:
     *   - character_updates      [name => ['职务'=>.., '处境'=>.., '关键变化'=>..]]
     *   - character_traits       [['name'=>.., 'trait'=>.., 'evidence'=>..], ...]
     *   - key_event              string 本章关键事件
     *   - new_foreshadowing      [['desc'=>.., 'suggested_payoff_chapter'=>..], ...]
     *   - resolved_foreshadowing [string, ...]
     *   - story_momentum         string 当前势能
     *   - used_tropes            [string, ...] (暂不入 atoms,继续存 chapters.used_tropes)
     *   - narrative_summary      string(这是章节摘要本身,存 chapters.chapter_summary,不归 MemoryEngine)
     *
     * @return array  ingestion 报告 (供日志 / 诊断用)
     */
    public function ingestChapter(int $chapterNumber, array $summary): array
    {
        $report = [
            'cards_upserted'      => 0,
            'traits_added'        => 0,
            'events_added'        => 0,
            'foreshadowing_added' => 0,
            'foreshadowing_resolved' => 0,
            'momentum_updated'    => false,
            'errors'              => [],
        ];

        // 1) 人物状态 → character_cards
        $charUpdates = $summary['character_updates'] ?? [];
        if (is_array($charUpdates)) {
            foreach ($charUpdates as $name => $update) {
                if (!is_string($name) || !is_array($update)) continue;
                try {
                    // 旧 summary 格式用中文 key('职务'/'处境'/'关键变化'),映射到新 schema
                    $mapped = $this->mapLegacyCharacterUpdate($update);
                    if (!empty($mapped)) {
                        $this->cards->upsert($name, $mapped, $chapterNumber);
                        $report['cards_upserted']++;
                    }
                } catch (\Throwable $e) {
                    $report['errors'][] = "card[$name]: " . $e->getMessage();
                }
            }
        }

        // 2) 角色特征 → memory_atoms(character_trait)
        // [修复] 增加去重：相同人物 + 相同特征 key 已存在时跳过，防止写到 50 章后
        //        "李明：沉稳" 这种特征累积几十条，挤占 semantic_hits 和 prompt 预算。
        $charTraits = $summary['character_traits'] ?? [];
        if (is_array($charTraits)) {
            foreach ($charTraits as $trait) {
                if (empty($trait['name']) || empty($trait['trait'])) continue;
                try {
                    $traitKey = trim((string)$trait['trait']);
                    // 组合内容：角色名 + 特征 + 证据
                    $content = "{$trait['name']}：{$traitKey}";
                    if (!empty($trait['evidence'])) {
                        $content .= "（{$trait['evidence']}）";
                    }

                    // 去重：同一小说里，相同 character_name + 相同 trait 只保留最新一条。
                    // 证据（evidence）允许不同，只要 trait 关键字一致就合并。
                    $dup = DB::fetch(
                        "SELECT id FROM memory_atoms
                         WHERE novel_id=? AND atom_type='character_trait'
                           AND JSON_EXTRACT(metadata, '$.character_name') = ?
                           AND JSON_EXTRACT(metadata, '$.trait_key')      = ?
                         LIMIT 1",
                        [$this->novelId, $trait['name'], $traitKey]
                    );
                    if ($dup) {
                        // 旧条目已存在：用新内容覆盖（保留最新证据），并清 embedding 触发重建
                        DB::update('memory_atoms', [
                            'content'              => $content,
                            'source_chapter'       => $chapterNumber,
                            'embedding'            => null,
                            'embedding_model'      => null,
                            'embedding_updated_at' => null,
                        ], 'id=? AND novel_id=?', [$dup['id'], $this->novelId]);
                        continue;
                    }

                    $metadata = [
                        'character_name' => $trait['name'],
                        'trait_key'      => $traitKey,
                    ];
                    if (!empty($trait['evidence'])) {
                        $metadata['evidence'] = $trait['evidence'];
                    }

                    $this->atoms->add('character_trait', $content, $chapterNumber, 0.8, $metadata);
                    $report['traits_added']++;
                } catch (\Throwable $e) {
                    $report['errors'][] = "trait[{$trait['name']}]: " . $e->getMessage();
                }
            }
        }

        // 3) 关键事件 → memory_atoms(plot_detail, metadata.is_key_event=1)
        $keyEvent = trim((string)($summary['key_event'] ?? ''));
        if ($keyEvent !== '') {
            try {
                $this->atoms->add('plot_detail', $keyEvent, $chapterNumber, 1.0, [
                    'is_key_event' => 1,
                ]);
                $report['events_added'] = 1;
            } catch (\Throwable $e) {
                $report['errors'][] = 'key_event: ' . $e->getMessage();
            }
        }

        // 4) 新伏笔 → foreshadowing_items
        foreach ((array)($summary['new_foreshadowing'] ?? []) as $f) {
            if (empty($f['desc'])) continue;
            try {
                $this->foreshadowing->plant(
                    (string)$f['desc'],
                    $chapterNumber,
                    !empty($f['suggested_payoff_chapter']) ? (int)$f['suggested_payoff_chapter'] : null
                );
                $report['foreshadowing_added']++;
            } catch (\Throwable $e) {
                $report['errors'][] = 'foreshadowing.plant: ' . $e->getMessage();
            }
        }

        // 5) 已回收伏笔
        foreach ((array)($summary['resolved_foreshadowing'] ?? []) as $resolved) {
            if (!is_string($resolved) || trim($resolved) === '') continue;
            try {
                $id = $this->foreshadowing->tryResolve($resolved, $chapterNumber);
                if ($id > 0) $report['foreshadowing_resolved']++;
            } catch (\Throwable $e) {
                $report['errors'][] = 'foreshadowing.resolve: ' . $e->getMessage();
            }
        }

        // 6) 故事势能 → novel_state
        $momentum = trim((string)($summary['story_momentum'] ?? ''));
        if ($momentum !== '') {
            try {
                $this->upsertNovelState([
                    'story_momentum'        => $momentum,
                    'last_ingested_chapter' => $chapterNumber,
                ]);
                $report['momentum_updated'] = true;
            } catch (\Throwable $e) {
                $report['errors'][] = 'momentum: ' . $e->getMessage();
            }
        } else {
            // 即使没 momentum,也更新 last_ingested_chapter
            try {
                $this->upsertNovelState(['last_ingested_chapter' => $chapterNumber]);
            } catch (\Throwable $e) { /* ignore */ }
        }

        // 7) 爽点类型标记 → memory_atoms(cool_point)
        // Phase 2 新增：自动记录每章的爽点类型，供后续调度算法使用
        $coolPointType = trim((string)($summary['cool_point_type'] ?? ''));
        if ($coolPointType !== '' && isset(\COOL_POINT_TYPES[$coolPointType])) {
            try {
                $cpName = \COOL_POINT_TYPES[$coolPointType]['name'] ?? $coolPointType;
                $this->atoms->add('cool_point',
                    "{$coolPointType}:第{$chapterNumber}章",
                    $chapterNumber,
                    0.9,
                    ['cool_type' => $coolPointType, 'type_name' => $cpName]
                );
                $report['cool_points_added'] = ($report['cool_points_added'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $report['errors'][] = "cool_point: " . $e->getMessage();
            }
        }

        return $report;
    }

    // =================================================================
    // 2. 读取路径 — 写下一章前调用
    // =================================================================

    /**
     * 为写下一章的 prompt 组装所有需要的记忆段落。
     * 带 token budget 控制:超预算时按优先级丢低优先级段。
     *
     * 返回结构(prompt.php 直接按键取用):
     *   - L1_global_settings  全局设定（主角、世界观、情节、风格）
     *   - L2_arc_summaries    弧段摘要（每10章压缩）
     *   - L3_recent_chapters  近章大纲（最近8章）
     *   - L4_previous_tail    前章尾文（最后500-1000字）
     *   - character_states    [name => ['title'=>..,'status'=>..,'alive'=>..]]
     *   - key_events          [['chapter'=>..,'event'=>..], ...]
     *   - pending_foreshadowing  [['chapter'=>..,'desc'=>..,'deadline'=>..], ...]
     *   - story_momentum      string
     *   - semantic_hits       [['content'=>..,'type'=>..,'score'=>..], ...] 语义召回的长尾 atoms
     *   - debug               ['budget_used'=>..,'budget_total'=>..,'dropped'=>[...]]
     */
    public function getPromptContext(
        int $currentChapter,
        ?string $queryText = null,     // 用来做语义召回的查询文本(通常是本章大纲+前文尾)
        int $tokenBudget = 6000,        // 整个记忆段的字数预算(粗估,中文字符近似 token)
        int $keyEventLimit = 20,
        int $semanticTopK = 8
    ): array {
        $ctx = [
            // 四层记忆架构
            'L1_global_settings'    => [],
            'L2_arc_summaries'      => [],
            'L3_recent_chapters'    => [],
            'L4_previous_tail'      => '',
            // 核心记忆数据
            'character_states'      => [],
            'key_events'            => [],
            'pending_foreshadowing' => [],
            'story_momentum'        => '',
            'arc_summaries'         => [],  // 保留旧字段兼容性
            'semantic_hits'         => [],
            // Phase 2 新增：爽点调度历史 + 钩子类型建议
            'cool_point_history'    => [],  // 近N章的爽点记录，供调度算法使用
            'recent_hook_types'     => [],  // 近N章已用钩子类型，防重复
            'debug'                 => ['budget_used' => 0, 'budget_total' => $tokenBudget, 'dropped' => []],
        ];

        // ============ 四层记忆架构（按优先级从高到低）============
        
        // L1 全局设定（P0 - 最高优先级）
        $ctx['L1_global_settings'] = $this->getGlobalSettings();
        
        // L2 弧段摘要（P0 - 全局历史记忆）
        $ctx['L2_arc_summaries'] = $this->getArcSummaries($currentChapter);
        $ctx['arc_summaries'] = $ctx['L2_arc_summaries'];  // 兼容旧字段
        
        // L3 近章大纲（P1 - 最近8章）
        $ctx['L3_recent_chapters'] = $this->getRecentChapters($currentChapter, 8);
        
        // L4 前章尾文（P0 - 直接衔接上下文）
        $ctx['L4_previous_tail'] = $this->getPreviousTail($currentChapter);

        // ============ 核心记忆数据 ============
        
        // 人物状态 — 最关键,防职务穿越
        $cards = $this->cards->listAll(true);
        foreach ($cards as $c) {
            $ctx['character_states'][$c['name']] = [
                'title'         => $c['title'],
                'status'        => $c['status'],
                'alive'         => $c['alive'],
                'last_chapter'  => $c['last_updated_chapter'],
            ];
        }

        // 故事势能
        $state = DB::fetch('SELECT * FROM novel_state WHERE novel_id=?', [$this->novelId]);
        $ctx['story_momentum'] = $state['story_momentum'] ?? '';

        // 待回收伏笔 — 优先临近 deadline 的
        $dueSoon = $this->foreshadowing->listDueSoon($currentChapter, 5);
        $overdue = $this->foreshadowing->listOverdue($currentChapter, 3);
        // overdue 排前面,紧急度高
        $pending = array_merge($overdue, $dueSoon);
        // 再补充一些远期伏笔(最多 3 条)让 AI 全局视野，限制在回溯窗口内
        $lookback = (int)getSystemSetting('ws_foreshadowing_lookback', 10, 'int');
        $allPending = array_filter($this->foreshadowing->listPending(), function($p) use ($currentChapter, $lookback) {
            return $p['planted_chapter'] >= $currentChapter - $lookback;
        });
        $seenIds = array_flip(array_column($pending, 'id'));
        foreach ($allPending as $p) {
            if (isset($seenIds[$p['id']])) continue;
            $pending[] = $p;
            if (count($pending) >= 8) break;
        }
        foreach ($pending as $p) {
            $ctx['pending_foreshadowing'][] = [
                'id'       => (int)$p['id'],
                'chapter'  => (int)$p['planted_chapter'],
                'desc'     => $p['description'],
                'deadline' => $p['deadline_chapter'] ? (int)$p['deadline_chapter'] : null,
            ];
        }

        // 关键事件(从 plot_detail 型 atom 里取 is_key_event=1)
        // 注:这里用 metadata JSON 查询,对 MySQL 5.7+ 有效
        $keyEventRows = DB::fetchAll(
            "SELECT content, source_chapter FROM memory_atoms
             WHERE novel_id=? AND atom_type='plot_detail'
               AND source_chapter IS NOT NULL AND source_chapter < ?
               AND JSON_EXTRACT(metadata, '$.is_key_event') = 1
             ORDER BY source_chapter DESC LIMIT ?",
            [$this->novelId, $currentChapter, $keyEventLimit]
        );
        // 反转成正序
        foreach (array_reverse($keyEventRows) as $e) {
            $ctx['key_events'][] = [
                'chapter' => (int)$e['source_chapter'],
                'event'   => $e['content'],
            ];
        }

        // Phase 2 新增：爽点历史记录（从 cool_point 型 atom 取最近20条）
        // 供 calculateCoolPointSchedule() 调度算法使用
        try {
            $coolPointRows = DB::fetchAll(
                "SELECT source_chapter, content, metadata FROM memory_atoms
                 WHERE novel_id=? AND atom_type='cool_point'
                   AND source_chapter IS NOT NULL AND source_chapter < ?
                 ORDER BY source_chapter DESC LIMIT 20",
                [$this->novelId, $currentChapter]
            );
            foreach ($coolPointRows as $cp) {
                $meta = json_decode($cp['metadata'] ?? '{}', true) ?: [];
                $ctx['cool_point_history'][] = [
                    'chapter' => (int)$cp['source_chapter'],
                    'type'    => $meta['cool_type']    ?? '',
                    'name'    => $meta['type_name']     ?? '',
                ];
            }
            // 反转为正序
            $ctx['cool_point_history'] = array_reverse($ctx['cool_point_history']);
        } catch (\Throwable $e) {
            $ctx['debug']['coolpoint_error'] = $e->getMessage();
        }

        // Phase 2 新增：近章已用钩子类型（从 chapters 表取 hook_type 字段）
        // 用于 prompt 层 suggestHookType 的防重复逻辑
        try {
            $hookTypeRows = DB::fetchAll(
                "SELECT chapter_number, hook_type FROM chapters
                 WHERE novel_id=? AND chapter_number < ?
                   AND status IN ('completed','outlined') AND hook_type IS NOT NULL AND hook_type != ''
                 ORDER BY chapter_number DESC LIMIT 10",
                [$this->novelId, $currentChapter]
            );
            $ctx['recent_hook_types'] = array_map(fn($r) => [
                'chapter'   => (int)$r['chapter_number'],
                'hook_type' => $r['hook_type'],
            ], array_reverse($hookTypeRows));
        } catch (\Throwable $e) {
            // hook_type 字段可能还不存在（旧版本兼容），静默忽略
            $ctx['debug']['hooktype_error'] = $e->getMessage();
        }

        // 语义召回 — 长尾 atoms + KnowledgeBase (角色/世界观/情节/风格)
        // includeKB=true 打开对 novel_embeddings 的召回,让 KB 手动维护的知识
        // 也进入 prompt 的 semantic_hits 里,无需前端单独调 kb->getWritingContext
        if ($queryText && EmbeddingProvider::getConfig()) {
            try {
                $hits = $this->semanticSearch($queryText, $semanticTopK, $currentChapter, true);
                $ctx['semantic_hits'] = $hits;
            } catch (\Throwable $e) {
                // 语义召回失败静默降级,不影响主流程
                $ctx['debug']['semantic_error'] = $e->getMessage();
            }
        }

        // ============ token budget 裁剪 ============
        $this->applyBudget($ctx, $tokenBudget);

        return $ctx;
    }
    
    // =================================================================
    // 四层记忆架构获取方法
    // =================================================================
    
    /**
     * L1 全局设定
     * 从 novels 表读取主角信息、世界观、情节设定、写作风格等全局设定
     */
    private function getGlobalSettings(): array
    {
        $novel = DB::fetch(
            'SELECT protagonist_name, protagonist_info, world_settings, plot_settings, writing_style, genre
             FROM novels WHERE id=?',
            [$this->novelId]
        );
        
        if (!$novel) {
            return [];
        }
        
        return [
            'protagonist_name' => $novel['protagonist_name'] ?? '',
            'protagonist_info' => $novel['protagonist_info'] ?? '',
            'world_settings'   => $novel['world_settings'] ?? '',
            'plot_settings'    => $novel['plot_settings'] ?? '',
            'writing_style'    => $novel['writing_style'] ?? '',
            'genre'            => $novel['genre'] ?? '',
        ];
    }
    
    /**
     * L2 弧段摘要
     * 从 arc_summaries 表获取当前章节之前的所有弧段摘要
     * 提供全局历史记忆，防止 AI 对早期情节失忆
     */
    private function getArcSummaries(int $currentChapter): array
    {
        // 只取当前弧段的前 2 段,避免膨胀
        $summaries = DB::fetchAll(
            'SELECT arc_index, chapter_from, chapter_to, summary
             FROM arc_summaries
             WHERE novel_id=? AND chapter_to < ?
             ORDER BY chapter_to DESC LIMIT 2',
            [$this->novelId, $currentChapter]
        );
        
        // 恢复为正序
        return array_reverse($summaries);
    }
    
    /**
     * L3 近章大纲
     * 从 chapters 表获取最近8章的大纲、标题、钩子
     * 确保新章节与近期情节无缝衔接
     */
    private function getRecentChapters(int $currentChapter, int $limit = 8): array
    {
        $chapters = DB::fetchAll(
            'SELECT chapter_number, title, outline, hook, key_points
             FROM chapters
             WHERE novel_id=? AND chapter_number < ? AND status = "completed"
             ORDER BY chapter_number DESC
             LIMIT ?',
            [$this->novelId, $currentChapter, $limit]
        );
        
        // 恢复为正序
        $chapters = array_reverse($chapters);
        
        // 格式化输出
        $result = [];
        foreach ($chapters as $ch) {
            $result[] = [
                'chapter_number' => (int)$ch['chapter_number'], // keep compatibility
                'chapter'        => (int)$ch['chapter_number'],
                'title'          => $ch['title'] ?? '',
                'outline'        => $ch['outline'] ?? '',
                'hook'           => $ch['hook'] ?? '',
                'key_points'     => json_decode($ch['key_points'] ?? '[]', true),
            ];
        }
        
        return $result;
    }
    
    /**
     * L4 前章尾文
     * 从前一章正文中截取最后500-1000字
     * 作为直接衔接的上下文，保证场景和对话的连贯性
     */
    private function getPreviousTail(int $currentChapter): string
    {
        if ($currentChapter <= 1) {
            return '';
        }
        
        $prevChapter = DB::fetch(
            'SELECT content FROM chapters
             WHERE novel_id=? AND chapter_number = ? AND status = "completed"
             LIMIT 1',
            [$this->novelId, $currentChapter - 1]
        );
        
        if (!$prevChapter || empty($prevChapter['content'])) {
            return '';
        }
        
        $content = $prevChapter['content'];
        $len = mb_strlen($content);

        // 截取比例：15%（原30%过高，对4000字章节会占用1200字token）
        // 上限800字足以提供衔接语感，下限400字保证短章节也有足够上下文
        $tailLength = min(800, max(400, (int)($len * 0.15)));
        $tailLength = min($tailLength, $len);

        return mb_substr($content, -$tailLength);
    }

    /**
     * 三路召回 + 合并:
     *   A. 精确路(character_cards 已在 getPromptContext 里,这里不重复)
     *   B. 关键词路(FULLTEXT / LIKE) - 只扫 memory_atoms
     *   C. 语义路(embedding 余弦) - memory_atoms + 可选 novel_embeddings (KB)
     * 最后去重合并,按 score 降序。
     *
     * 只从"长尾 atoms"(character_trait/world_setting/style_preference/constraint)中召回,
     * plot_detail 因为会和 key_events 重复,排除。
     *
     * @param string $query            查询文本
     * @param int    $topK             最多返回多少条
     * @param ?int   $beforeChapter    只召回 chapter < 此值的 atoms(节流避免召回未来的)
     * @param bool   $includeKB        是否把 KnowledgeBase 的 novel_embeddings 一并召回
     *                                 (character/worldbuilding/plot/style 四类)
     */
    public function semanticSearch(
        string $query,
        int $topK = 8,
        ?int $beforeChapter = null,
        bool $includeKB = false
    ): array {
        $excludeTypes = ['plot_detail']; // 避免关键事件被重复召回
        $longTailTypes = array_values(array_diff(AtomRepo::VALID_TYPES, $excludeTypes));

        // 关键词路(每种类型各取 2 条) - 仅 memory_atoms
        // [修复] 传入 $beforeChapter，与语义路一致，防止未来章节 atom 从关键词路漏进 prompt
        $kwHits = [];
        foreach ($longTailTypes as $t) {
            $kwHits = array_merge($kwHits, $this->atoms->search($query, $t, 2, $beforeChapter));
        }

        // 语义路 - 先给 query 做一次 embedding,然后分别召 atoms 和 KB
        $embHits = [];
        $kbHits  = [];
        $qEmb = EmbeddingProvider::embed($query);
        if ($qEmb && !empty($qEmb['vec'])) {
            // atoms 向量
            $atomCandidates = [];
            foreach ($longTailTypes as $t) {
                $atomCandidates = array_merge(
                    $atomCandidates,
                    $this->atoms->listWithEmbedding($t, $beforeChapter, 200)
                );
            }
            if (!empty($atomCandidates)) {
                $embHits = Vector::topK($qEmb['vec'], $atomCandidates, $topK, 0.3);
            }

            // KB 向量(novel_embeddings 表,字段不一样要改造为 Vector::topK 的输入格式)
            if ($includeKB) {
                $kbCandidates = DB::fetchAll(
                    "SELECT source_id AS id, source_type, content, embedding_blob AS `blob`
                     FROM novel_embeddings
                     WHERE novel_id=? AND source_type IN ('character','worldbuilding','plot','style')",
                    [$this->novelId]
                );
                if (!empty($kbCandidates)) {
                    $kbHits = Vector::topK($qEmb['vec'], $kbCandidates, $topK, 0.3);
                }
            }
        }

        // 合并:先建索引避免去重时看不到 atom 和 KB 重名
        $merged = [];

        // 关键词路(只有 atoms) -> 用 "atom:{id}" 做 key 避免和 KB 的 id 冲突
        foreach ($kwHits as $r) {
            $key = 'atom:' . $r['id'];
            $merged[$key] = [
                'id'      => (int)$r['id'],
                'source'  => 'atom',
                'type'    => $r['atom_type'],
                'content' => $r['content'],
                'chapter' => $r['source_chapter'] ? (int)$r['source_chapter'] : null,
                'score'   => (float)($r['_rel'] ?? 0.5),
                'via'     => 'keyword',
            ];
        }

        // atoms 的语义路
        foreach ($embHits as $r) {
            $key = 'atom:' . $r['id'];
            if (isset($merged[$key])) {
                $merged[$key]['score'] = max($merged[$key]['score'], (float)$r['_score']);
                $merged[$key]['via']   = 'both';
            } else {
                $merged[$key] = [
                    'id'      => (int)$r['id'],
                    'source'  => 'atom',
                    'type'    => $r['atom_type'],
                    'content' => $r['content'],
                    'chapter' => $r['source_chapter'] ? (int)$r['source_chapter'] : null,
                    'score'   => (float)$r['_score'],
                    'via'     => 'embedding',
                ];
            }
        }

        // KB 的语义路
        foreach ($kbHits as $r) {
            $key = 'kb:' . $r['source_type'] . ':' . $r['id'];
            $merged[$key] = [
                'id'      => (int)$r['id'],
                'source'  => 'kb',
                'type'    => $r['source_type'], // character / worldbuilding / plot / style
                'content' => $r['content'],
                'chapter' => null,
                'score'   => (float)$r['_score'],
                'via'     => 'embedding',
            ];
        }

        // 排序取 topK
        $all = array_values($merged);
        usort($all, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($all, 0, $topK);
    }

    // =================================================================
    // 3. 懒触发器 — 写作入口处调用
    // =================================================================

    /**
     * 补齐当前小说里缺失 embedding 的 atoms 和 foreshadowing_items。
     * 在 write_chapter.php 开始写作前调用,非关键路径,失败静默。
     *
     * @param int $maxBatch  本次最多处理多少条
     * @return array 报告
     */
    public function ensureEmbeddings(int $maxBatch = 50): array
    {
        $report = ['atoms' => 0, 'foreshadowing' => 0, 'skipped' => 0, 'errors' => []];

        $cfg = EmbeddingProvider::getConfig();
        if (!$cfg) {
            $report['skipped'] = 1;
            $report['msg'] = '未配置全局 embedding 模型';
            return $report;
        }

        // --- atoms ---
        // [修复] 只要拿到 embeddings 就尽量按 index 回填，不再要求"长度完全相等"。
        //       有的 provider 偶尔对某条内容返回空或拒绝(审核策略)，原来整批作废
        //       会让懒触发器一直在跟同一批数据死磕，永远补不上。
        $pending = $this->atoms->listPendingEmbedding($maxBatch);
        if (!empty($pending)) {
            $texts = array_column($pending, 'content');
            $embs  = EmbeddingProvider::embedBatch($texts);
            if (!is_array($embs) || empty($embs)) {
                $report['errors'][] = 'atom embed batch failed (provider returned nothing)';
            } else {
                foreach ($pending as $i => $p) {
                    $emb = $embs[$i] ?? null;
                    if (!$emb || empty($emb['vec'])) continue;
                    try {
                        $blob = Vector::pack($emb['vec']);
                        $this->atoms->updateEmbedding((int)$p['id'], $blob, $emb['model']);
                        $report['atoms']++;
                    } catch (\Throwable $e) {
                        $report['errors'][] = "atom#{$p['id']}: " . $e->getMessage();
                    }
                }
            }
        }

        // --- foreshadowing ---
        $pendingFs = DB::fetchAll(
            'SELECT id, description FROM foreshadowing_items
             WHERE novel_id=? AND embedding_updated_at IS NULL
             ORDER BY id ASC LIMIT ' . (int)$maxBatch,
            [$this->novelId]
        );
        if (!empty($pendingFs)) {
            $texts = array_column($pendingFs, 'description');
            $embs  = EmbeddingProvider::embedBatch($texts);
            if (!is_array($embs) || empty($embs)) {
                $report['errors'][] = 'foreshadowing embed batch failed (provider returned nothing)';
            } else {
                foreach ($pendingFs as $i => $p) {
                    $emb = $embs[$i] ?? null;
                    if (!$emb || empty($emb['vec'])) continue;
                    try {
                        $blob = Vector::pack($emb['vec']);
                        DB::update('foreshadowing_items', [
                            'embedding'            => $blob,
                            'embedding_model'      => $emb['model'],
                            'embedding_updated_at' => date('Y-m-d H:i:s'),
                        ], 'id=? AND novel_id=?', [$p['id'], $this->novelId]);
                        $report['foreshadowing']++;
                    } catch (\Throwable $e) {
                        $report['errors'][] = "fs#{$p['id']}: " . $e->getMessage();
                    }
                }
            }
        }

        return $report;
    }

    // =================================================================
    // 小工具
    // =================================================================

    public function getNovelState(): array
    {
        $row = DB::fetch('SELECT * FROM novel_state WHERE novel_id=?', [$this->novelId]);
        return $row ?: [
            'novel_id'              => $this->novelId,
            'story_momentum'        => '',
            'current_arc_summary'   => '',
            'last_ingested_chapter' => 0,
        ];
    }

    public function upsertNovelState(array $updates): void
    {
        $existing = DB::fetch('SELECT novel_id FROM novel_state WHERE novel_id=?', [$this->novelId]);
        if ($existing) {
            DB::update('novel_state', $updates, 'novel_id=?', [$this->novelId]);
        } else {
            $updates['novel_id'] = $this->novelId;
            DB::insert('novel_state', $updates);
        }
    }

    public function stats(): array
    {
        return [
            'cards'             => count($this->cards->listAll()),
            'atoms_by_type'     => $this->atoms->countByType(),
            'foreshadowing'     => $this->foreshadowing->status(PHP_INT_MAX),
            'state'             => $this->getNovelState(),
            'embedding_ready'   => EmbeddingProvider::getConfig() !== null,
        ];
    }

    /**
     * 全书进度感知快照
     * 供 buildOutlinePrompt / buildChapterPrompt 注入，让 AI 知道当前写到哪、还差多少
     *
     * @param int $currentChapter 当前章节号
     * @return array {
     *   completed_chapters, target_chapters, progress_pct,
     *   pending_foreshadowing_count, overdue_foreshadowing_count,
     *   pending_foreshadowing_list,   // 前5条待回收伏笔
     *   overdue_foreshadowing_list,   // 所有逾期伏笔
     *   major_turning_points,         // 全书转折点 + 是否已过
     *   character_arcs,               // 主角成长轨迹
     *   volume_progress,              // 当前卷 / 总卷数
     *   remaining_chapters,
     *   act_phase,                    // 当前处于三幕的哪一幕
     * }
     */
    public function getProgressContext(int $currentChapter): array
    {
        $ctx = [
            'completed_chapters'          => 0,
            'target_chapters'             => 0,
            'progress_pct'                => 0,
            'remaining_chapters'          => 0,
            'act_phase'                   => '',
            'pending_foreshadowing_count' => 0,
            'overdue_foreshadowing_count' => 0,
            'pending_foreshadowing_list'  => [],
            'overdue_foreshadowing_list'  => [],
            'major_turning_points'        => [],
            'character_arcs'              => [],
            'volume_progress'             => '',
        ];

        try {
            // ── 基础进度 ──────────────────────────────────────────────
            $novel = DB::fetch(
                'SELECT target_chapters FROM novels WHERE id=?',
                [$this->novelId]
            );
            $targetChapters  = (int)($novel['target_chapters'] ?? 0);
            $completedChapters = (int)(DB::fetch(
                'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id=? AND status="completed"',
                [$this->novelId]
            )['cnt'] ?? 0);

            $ctx['target_chapters']    = $targetChapters;
            $ctx['completed_chapters'] = $completedChapters;
            $ctx['remaining_chapters'] = max(0, $targetChapters - $completedChapters);
            $ctx['progress_pct']       = $targetChapters > 0
                ? (int)round($completedChapters / $targetChapters * 100)
                : 0;

            // ── 三幕定位 ─────────────────────────────────────────────
            if ($targetChapters > 0) {
                $pct = $completedChapters / $targetChapters;
                if ($pct <= 0.2) {
                    $ctx['act_phase'] = '第一幕（开局建立期）';
                } elseif ($pct <= 0.8) {
                    $ctx['act_phase'] = '第二幕（发展对抗期）';
                } else {
                    $ctx['act_phase'] = '第三幕（高潮收束期）';
                }
            }

            // ── 伏笔统计 ─────────────────────────────────────────────
            $allPending = $this->foreshadowing->listPending();
            $overdueItems = $this->foreshadowing->listOverdue($currentChapter, 0);

            $ctx['pending_foreshadowing_count'] = count($allPending);
            $ctx['overdue_foreshadowing_count'] = count($overdueItems);

            // 前5条待回收（按 deadline 排序，无 deadline 排后）
            usort($allPending, function($a, $b) {
                $da = $a['deadline_chapter'] ?? 99999;
                $db = $b['deadline_chapter'] ?? 99999;
                return $da <=> $db;
            });
            foreach (array_slice($allPending, 0, 5) as $p) {
                $deadline = $p['deadline_chapter'] ? "（应第{$p['deadline_chapter']}章前回收）" : '';
                $ctx['pending_foreshadowing_list'][] =
                    "第{$p['planted_chapter']}章埋：{$p['description']}{$deadline}";
            }

            // 所有逾期伏笔
            foreach ($overdueItems as $ov) {
                $ctx['overdue_foreshadowing_list'][] =
                    "第{$ov['planted_chapter']}章埋、应{$ov['deadline_chapter']}章前回收：{$ov['description']}";
            }

            // ── 全书转折点（标注是否已过）────────────────────────────
            $storyOutline = DB::fetch(
                'SELECT major_turning_points, character_arcs FROM story_outlines WHERE novel_id=?',
                [$this->novelId]
            );
            if ($storyOutline) {
                $turningPoints = json_decode($storyOutline['major_turning_points'] ?? '[]', true) ?: [];
                foreach ($turningPoints as $tp) {
                    $tpChapter = (int)($tp['chapter'] ?? 0);
                    $passed    = $tpChapter > 0 && $tpChapter <= $currentChapter;
                    $ctx['major_turning_points'][] = [
                        'chapter' => $tpChapter,
                        'event'   => $tp['event'] ?? '',
                        'passed'  => $passed,
                    ];
                }

                // 主角成长轨迹
                $charArcs = json_decode($storyOutline['character_arcs'] ?? '{}', true) ?: [];
                $ctx['character_arcs'] = $charArcs;
            }

            // ── 卷进度 ───────────────────────────────────────────────
            $totalVolumes = (int)(DB::fetch(
                'SELECT COUNT(*) as cnt FROM volume_outlines WHERE novel_id=?',
                [$this->novelId]
            )['cnt'] ?? 0);

            if ($totalVolumes > 0) {
                $currentVol = DB::fetch(
                    'SELECT volume_number, title FROM volume_outlines
                     WHERE novel_id=? AND start_chapter <= ? AND end_chapter >= ?
                     LIMIT 1',
                    [$this->novelId, $currentChapter, $currentChapter]
                );
                if ($currentVol) {
                    $ctx['volume_progress'] =
                        "第{$currentVol['volume_number']}卷《{$currentVol['title']}》/ 共{$totalVolumes}卷";
                }
            }

        } catch (\Throwable $e) {
            $ctx['error'] = $e->getMessage();
        }

        return $ctx;
    }

    // ---------- 内部辅助 ----------

    /**
     * 把 generateChapterSummary 返回的 character_updates(中文 key)
     * 映射到 character_cards 的英文 schema。
     *
     * 旧 key:职务 / 处境 / 关键变化 / 存活(偶有)
     */
    private function mapLegacyCharacterUpdate(array $update): array
    {
        $mapped = [];
        $attrs  = [];

        foreach ($update as $k => $v) {
            if ($v === null || (is_string($v) && trim($v) === '')) continue;
            switch ($k) {
                case '职务': case 'title':
                    $mapped['title'] = $v; break;
                case '处境': case 'status':
                    $mapped['status'] = $v; break;
                case '存活': case 'alive':
                    $mapped['alive'] = (bool)$v; break;
                case '关键变化':  // 旧的"关键变化"没有独立字段,丢进 attributes
                    $attrs['recent_change'] = $v; break;
                default:
                    // 未知 key 一律塞进 attributes,保证零信息丢失
                    $attrs[$k] = $v;
            }
        }
        if (!empty($attrs)) {
            $mapped['attributes'] = $attrs;
        }
        return $mapped;
    }

    /**
     * token budget 粗估 + 裁剪
     * 估算:中文 1 字 ≈ 1 token (粗估偏高,留余量)
     *
     * 优先级:
     *   P0 (绝不丢弃): L1 全局设定、L4 前章尾文、人物状态、故事势能
     *   P1 (可适度裁剪): L2 弧段摘要、L3 近章大纲、待回收伏笔
     *   P2 (优先裁剪): 关键事件、语义召回
     *
     * [修复] 原版的三大问题:
     *   1) 裁剪 P1 后没重新计算 $remain,L3/L2 条数本身 ≤ 阈值时根本没裁
     *   2) P0 无硬上限,character_states / L4_tail 本身就能爆预算
     *   3) debug.budget_used 用的是裁剪前的数字,看不出真实占用
     * 本版改为:每裁一块立刻重算占用,并给 P0 做硬上限兜底。
     */
    private function applyBudget(array &$ctx, int $budget): void
    {
        // ---- 1) 先给 P0 做硬上限兜底,防止人物卡或前章尾文本身就撑爆预算 ----
        // L4 tail:最多允许 30% budget
        $l4Cap = (int)max(400, $budget * 0.3);
        if (mb_strlen($ctx['L4_previous_tail']) > $l4Cap) {
            // 从末尾截取(保留衔接作用最强的最末段)
            $ctx['L4_previous_tail'] = mb_substr($ctx['L4_previous_tail'], -$l4Cap);
            $ctx['debug']['dropped'][] = "L4_previous_tail capped to {$l4Cap} chars";
        }
        // character_states:最多允许 20% budget。超了就把死去的、最久未更新的丢掉
        $csCap = (int)max(400, $budget * 0.2);
        if ($this->approxLen($ctx['character_states']) > $csCap) {
            // character_states 键为 name,按 last_chapter 降序保留
            $items = [];
            foreach ($ctx['character_states'] as $name => $state) {
                $items[] = ['name' => $name, 'state' => $state, 'last' => (int)($state['last_chapter'] ?? 0)];
            }
            usort($items, fn($a, $b) => $b['last'] <=> $a['last']);
            $kept = [];
            $used = 0;
            foreach ($items as $it) {
                $rowLen = mb_strlen($it['name']) + $this->approxLen($it['state']);
                if ($used + $rowLen > $csCap && !empty($kept)) break;
                $kept[$it['name']] = $it['state'];
                $used += $rowLen;
            }
            $ctx['character_states'] = $kept;
            $ctx['debug']['dropped'][] = 'character_states capped by last_chapter';
        }
        // story_momentum:最多 200 字
        if (mb_strlen($ctx['story_momentum']) > 200) {
            $ctx['story_momentum'] = mb_substr($ctx['story_momentum'], 0, 200);
            $ctx['debug']['dropped'][] = 'story_momentum truncated to 200';
        }

        // ---- 2) 小工具:实时算分段长度 ----
        $lenOf = function (string $key) use (&$ctx): int {
            if ($key === 'L4_previous_tail' || $key === 'story_momentum') {
                return mb_strlen((string)$ctx[$key]);
            }
            return $this->approxLen($ctx[$key]);
        };
        $sumUsed = function () use (&$ctx, $lenOf): int {
            return $lenOf('L1_global_settings')
                 + $lenOf('L4_previous_tail')
                 + $lenOf('character_states')
                 + $lenOf('story_momentum')
                 + $lenOf('L2_arc_summaries')
                 + $lenOf('L3_recent_chapters')
                 + $lenOf('pending_foreshadowing')
                 + $lenOf('key_events')
                 + $lenOf('semantic_hits');
        };

        // ---- 3) P2 裁剪(语义召回 → 关键事件,从末尾/最旧丢) ----
        // 首先丢掉语义召回得分最低的(semantic_hits 已按 score 降序,array_pop)
        // 但至少保留 top 3 条，避免 budget 紧张时语义召回完全失效
        $minSemanticKeep = 3;
        while ($sumUsed() > $budget && count($ctx['semantic_hits']) > $minSemanticKeep) {
            array_pop($ctx['semantic_hits']);
        }
        if (count($ctx['semantic_hits']) <= $minSemanticKeep && $sumUsed() > $budget) {
            $ctx['debug']['dropped'][] = 'semantic_hits kept top ' . count($ctx['semantic_hits']) . ' (budget tight)';
        }
        // 再裁关键事件,从最旧(数组开头)丢
        while ($sumUsed() > $budget && !empty($ctx['key_events'])) {
            array_shift($ctx['key_events']);
        }
        if (empty($ctx['key_events']) && $sumUsed() > $budget) {
            $ctx['debug']['dropped'][] = 'key_events fully dropped';
        }

        // ---- 4) P1 裁剪 ----
        // 先砍 L3 近章大纲:从 8 章逐步减到 4 章,每次丢最早一章
        while ($sumUsed() > $budget && count($ctx['L3_recent_chapters']) > 4) {
            array_shift($ctx['L3_recent_chapters']);
        }
        // L2 弧段摘要:只保留最近 1 段
        while ($sumUsed() > $budget && count($ctx['L2_arc_summaries']) > 1) {
            array_shift($ctx['L2_arc_summaries']);
        }
        $ctx['arc_summaries'] = $ctx['L2_arc_summaries']; // 同步兼容字段
        // pending_foreshadowing:从"远期"开始砍,保留 overdue + due_soon
        // (数组已按 overdue/due_soon/远期 的顺序构造,array_pop 就是丢远期)
        while ($sumUsed() > $budget && count($ctx['pending_foreshadowing']) > 3) {
            array_pop($ctx['pending_foreshadowing']);
        }
        // 极端情况:P1 还超预算,砍到 L3 剩 2 章、L2 剩 0、foreshadowing 剩 3
        while ($sumUsed() > $budget && count($ctx['L3_recent_chapters']) > 2) {
            array_shift($ctx['L3_recent_chapters']);
        }
        while ($sumUsed() > $budget && !empty($ctx['L2_arc_summaries'])) {
            array_shift($ctx['L2_arc_summaries']);
        }
        $ctx['arc_summaries'] = $ctx['L2_arc_summaries'];

        // ---- 5) debug 记录(用真实裁剪后数字) ----
        $ctx['debug']['sections_len'] = [
            'L1_global_settings'    => $lenOf('L1_global_settings'),
            'L4_previous_tail'      => $lenOf('L4_previous_tail'),
            'character_states'      => $lenOf('character_states'),
            'story_momentum'        => $lenOf('story_momentum'),
            'L2_arc_summaries'      => $lenOf('L2_arc_summaries'),
            'L3_recent_chapters'    => $lenOf('L3_recent_chapters'),
            'pending_foreshadowing' => $lenOf('pending_foreshadowing'),
            'key_events'            => $lenOf('key_events'),
            'semantic_hits'         => $lenOf('semantic_hits'),
        ];
        $ctx['debug']['budget_used'] = $sumUsed();
        $ctx['debug']['priority_breakdown'] = [
            'P0' => $lenOf('L1_global_settings') + $lenOf('L4_previous_tail')
                  + $lenOf('character_states')   + $lenOf('story_momentum'),
            'P1' => $lenOf('L2_arc_summaries') + $lenOf('L3_recent_chapters')
                  + $lenOf('pending_foreshadowing'),
            'P2' => $lenOf('key_events') + $lenOf('semantic_hits'),
        ];
    }

    private function approxLen($data): int
    {
        if (empty($data)) return 0;
        if (is_string($data)) return mb_strlen($data);
        return mb_strlen(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '');
    }
}
