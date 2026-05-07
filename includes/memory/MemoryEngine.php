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

    private const MEMORY_ARC_SUMMARY_LIMIT = 5;

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
            'cards_inserted'      => 0,  // v1.11.2: 区分新增和更新
            'cards_updated'       => 0,  // v1.11.2: 区分新增和更新
            'traits_added'        => 0,
            'events_added'        => 0,
            'new_atom_ids'        => [], // v1.11.2: 新增的 atom IDs，供 CognitiveLoadMonitor 精确查询
            'foreshadowing_added' => 0,
            'foreshadowing_resolved' => 0,
            'momentum_updated'    => false,
            'errors'              => [],
            'warnings'            => [],
        ];

        // 0) 主角名锚定：读取 novels.protagonist_name，防止 AI 在摘要中使用变体名
        $canonicalProtagonist = '';
        try {
            $novelRow = DB::fetch('SELECT protagonist_name FROM novels WHERE id=?', [$this->novelId]);
            $canonicalProtagonist = trim($novelRow['protagonist_name'] ?? '');
        } catch (\Throwable) {}

        // 1) 人物状态 → character_cards
        $charUpdates = $summary['character_updates'] ?? [];
        if (is_array($charUpdates)) {
            $charUpdates = $this->normalizeProtagonistKeys($charUpdates, $canonicalProtagonist);
            foreach ($charUpdates as $name => $update) {
                if (!is_string($name) || !is_array($update)) continue;
                try {
                    // 旧 summary 格式用中文 key('职务'/'处境'/'关键变化'),映射到新 schema
                    $mapped = $this->mapLegacyCharacterUpdate($update);
                    if (!empty($mapped)) {
                        $oldCard = $this->cards->getByName($name);
                        $isNewCard = ($oldCard === null);  // v1.11.2: 区分新增和更新
                        $this->cards->upsert($name, $mapped, $chapterNumber);
                        $report['cards_upserted']++;
                        if ($isNewCard) {
                            $report['cards_inserted']++;  // v1.11.2 Bug #4 修复
                        } else {
                            $report['cards_updated']++;   // v1.11.2 Bug #4 修复
                        }

                        // 境界跳级检测
                        $realmWarning = $this->detectRealmSkip($name, $oldCard, $mapped, $chapterNumber);
                        if ($realmWarning) {
                            $report['warnings'][] = $realmWarning;

                            // 将跳级修复指引写入人物卡片，供下章 Prompt 自动过渡
                            $bridgeSuggestion = $this->buildRealmBridgeSuggestion($name, $oldCard, $mapped, $chapterNumber);
                            if ($bridgeSuggestion) {
                                try {
                                    $this->cards->upsert($name, ['attributes' => $bridgeSuggestion], $chapterNumber);
                                } catch (\Throwable) {}
                            }

                            // 自动更新下一章的 outline，注入过渡章标记
                            $this->injectBridgeOutlineToNextChapter($name, $chapterNumber, $bridgeSuggestion);
                        }
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
                if ($canonicalProtagonist && $trait['name'] !== $canonicalProtagonist) {
                    if (mb_strpos($trait['name'], $canonicalProtagonist) !== false
                        || mb_strpos($canonicalProtagonist, $trait['name']) !== false) {
                        $trait['name'] = $canonicalProtagonist;
                    }
                }
                try {
                    $traitKey = trim((string)$trait['trait']);
                    // 组合内容：角色名 + 特征 + 证据
                    $content = "{$trait['name']}：{$traitKey}";
                    if (!empty($trait['evidence'])) {
                        $content .= "（{$trait['evidence']}）";
                    }

                    // 去重：同一小说里，相同 character_name + 相同 trait 只保留最新一条。
                    // 证据（evidence）允许不同，只要 trait 关键字一致就合并。
                    $dup = null;
                    try {
                        $dup = DB::fetch(
                            "SELECT id FROM memory_atoms
                             WHERE novel_id=? AND atom_type='character_trait'
                               AND JSON_VALID(metadata) = 1
                               AND JSON_EXTRACT(metadata, '$.character_name') = ?
                               AND JSON_EXTRACT(metadata, '$.trait_key')      = ?
                             LIMIT 1",
                            [$this->novelId, $trait['name'], $traitKey]
                        );
                    } catch (\Throwable $e) {
                        $allTraits = DB::fetchAll(
                            "SELECT id, metadata FROM memory_atoms
                             WHERE novel_id=? AND atom_type='character_trait'",
                            [$this->novelId]
                        );
                        foreach ($allTraits as $t) {
                            $meta = is_string($t['metadata']) ? json_decode($t['metadata'], true) : ($t['metadata'] ?? []);
                            if (($meta['character_name'] ?? '') === $trait['name']
                                && ($meta['trait_key'] ?? '') === $traitKey) {
                                $dup = ['id' => $t['id']];
                                break;
                            }
                        }
                    }

                    if ($dup) {
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

                    $atomId = $this->atoms->add('character_trait', $content, $chapterNumber, 0.8, $metadata);
                    $report['traits_added']++;
                    $report['new_atom_ids'][] = $atomId;  // v1.11.2 Bug #5 修复
                } catch (\Throwable $e) {
                    $report['errors'][] = "trait[{$trait['name']}]: " . $e->getMessage();
                }
            }
        }

        // 3) 关键事件 → memory_atoms(plot_detail, metadata.is_key_event=1)
        $keyEvent = trim((string)($summary['key_event'] ?? ''));
        if ($keyEvent !== '') {
            try {
                $atomId = $this->atoms->add('plot_detail', $keyEvent, $chapterNumber, 1.0, [
                    'is_key_event' => 1,
                ]);
                $report['events_added'] = 1;
                $report['new_atom_ids'][] = $atomId;  // v1.11.2 Bug #5 修复
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
        $resolvedList = [];
        foreach ((array)($summary['resolved_foreshadowing'] ?? []) as $resolved) {
            if (!is_string($resolved) || trim($resolved) === '') continue;
            try {
                $id = $this->foreshadowing->tryResolve($resolved, $chapterNumber);
                if ($id > 0) {
                    $report['foreshadowing_resolved']++;
                    $resolvedList[] = ['id' => $id, 'desc' => mb_substr($resolved, 0, 50)];
                }
            } catch (\Throwable $e) {
                $report['errors'][] = 'foreshadowing.resolve: ' . $e->getMessage();
            }
        }
        // v1.11.8: 记录回收详情
        $report['resolved_details'] = $resolvedList;

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

        // 8) 角色情绪状态 → character_emotion_history
        // v1.11.2 新增：记录角色跨章节情绪状态，确保情绪连续性
        // v1.11.5 修复：先删后插，防止重写后同章重复记录
        // 始终先清理本章旧记录（即使新 summary 无情绪数据，也需清除重写前的残留）
        try {
            require_once __DIR__ . '/CharacterEmotionRepo.php';
            $emotionRepo = new CharacterEmotionRepo($this->novelId);
            $emotionRepo->deleteByChapter($chapterNumber);
        } catch (\Throwable $e) { }

        $characterEmotions = $summary['character_emotions'] ?? [];
        if (is_array($characterEmotions) && !empty($characterEmotions)) {
            // v1.11.2 Bug #9 修复：规范化主角变体名
            $characterEmotions = $this->normalizeProtagonistInEmotions($characterEmotions, $canonicalProtagonist);
            try {
                require_once __DIR__ . '/CharacterEmotionRepo.php';
                $emotionRepo = new CharacterEmotionRepo($this->novelId);
                $emotionCount = $emotionRepo->insertBatch($chapterNumber, $characterEmotions);
                if ($emotionCount > 0) {
                    $report['emotions_added'] = $emotionCount;
                }
            } catch (\Throwable $e) {
                $report['errors'][] = 'character_emotions: ' . $e->getMessage();
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
    /**
     * v1.4 批量预取入口：一次性拉取 getPromptContext 需要的所有关系型数据，
     * 将 ~12 个散布在各私有方法的 SQL 调用收敛到 ~7 个查询，
     * 减少连接开销和重复往返，同时让数据流显式可见。
     *
     * @return array 结构化预取数据，key 语义与 ctx 字段一一对应
     */
    private function buildBatch(int $currentChapter, int $keyEventLimit): array
    {
        // ── Q1: novels 全局设定 ──────────────────────────────────────
        $novel = DB::fetch(
            'SELECT protagonist_name, protagonist_info, world_settings, plot_settings, writing_style, genre,
                    extra_settings, style_vector, ref_author
             FROM novels WHERE id=?',
            [$this->novelId]
        );

        // ── Q2: novel_state 故事势能 ─────────────────────────────────
        $novelState = DB::fetch(
            'SELECT * FROM novel_state WHERE novel_id=?',
            [$this->novelId]
        );

        // ── Q3: arc_summaries 弧段摘要 ──────────────────────────────
        $arcSummaries = DB::fetchAll(
            'SELECT arc_index, chapter_from, chapter_to, summary
             FROM arc_summaries
             WHERE novel_id=? AND chapter_to < ?
             ORDER BY chapter_to DESC LIMIT ' . self::MEMORY_ARC_SUMMARY_LIMIT,
            [$this->novelId, $currentChapter]
        );

        // ── Q4: chapters 三合一（近章大纲 + 前章尾文 + 钩子类型）──
        // 近章大纲（同一表，不同列）
        $recentChapters = DB::fetchAll(
            "SELECT chapter_number, title, outline, hook, key_points, opening_type, emotion_score, emotion_density
             FROM chapters
             WHERE novel_id=? AND chapter_number < ? AND status = 'completed'
             ORDER BY chapter_number DESC LIMIT 8",
            [$this->novelId, $currentChapter]
        );

        // 前章尾文
        $previousTail = '';
        if ($currentChapter > 1) {
            $prev = DB::fetch(
                "SELECT content FROM chapters
                 WHERE novel_id=? AND chapter_number = ? AND status = 'completed' LIMIT 1",
                [$this->novelId, $currentChapter - 1]
            );
            if ($prev && !empty($prev['content'])) {
                $content = $prev['content'];
                $len = mb_strlen($content);
                $tailLength = min(800, max(400, (int)($len * 0.15)));
                $tailLength = min($tailLength, $len);
                $previousTail = mb_substr($content, -$tailLength);
            }
        }

        // 近章钩子类型
        $hookTypeRows = [];
        try {
            $hookTypeRows = DB::fetchAll(
                "SELECT chapter_number, hook_type FROM chapters
                 WHERE novel_id=? AND chapter_number < ?
                   AND status IN ('completed','outlined') AND hook_type IS NOT NULL AND hook_type != ''
                 ORDER BY chapter_number DESC LIMIT 10",
                [$this->novelId, $currentChapter]
            );
        } catch (\Throwable $e) {
            // hook_type 字段可能不存在，兼容旧库
        }

        // ── Q5: character_cards 人物状态 ────────────────────────────
        $cards = DB::fetchAll(
            'SELECT * FROM character_cards WHERE novel_id=? AND alive=1 ORDER BY name ASC',
            [$this->novelId]
        );

        // ── Q6: foreshadowing_items 三合一 ──────────────────────────
        // 一次查询拉取所有未回收伏笔，在 PHP 侧按 deadline 分类，
        // 消除 listDueSoon + listOverdue + listPending 三次独立查询。
        $allUnresolvedFs = DB::fetchAll(
            'SELECT id, description, planted_chapter, deadline_chapter
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
             ORDER BY planted_chapter ASC',
            [$this->novelId]
        );

        // 在 PHP 侧按 deadline 分类（替代 3 次 DB 查询）
        $fsDueSoon = [];
        $fsOverdue = [];
        $fsOther   = [];
        foreach ($allUnresolvedFs as $f) {
            $dl = $f['deadline_chapter'];
            if ($dl !== null) {
                if ($dl < $currentChapter - 3) {
                    $fsOverdue[] = $f;   // deadline 已过缓冲期
                } elseif ($dl <= $currentChapter + 5) {
                    $fsDueSoon[] = $f;   // deadline 临近
                } else {
                    $fsOther[] = $f;
                }
            } else {
                $fsOther[] = $f;
            }
        }

        // ── Q7: memory_atoms 双合一（关键事件 + 爽点历史）─────────
        // 用一次 UNION 查询拉取两种 atom_type，在 PHP 侧拆分
        $atomRows = DB::fetchAll(
            "SELECT atom_type, content, source_chapter, metadata FROM memory_atoms
             WHERE novel_id=? AND atom_type IN ('plot_detail','cool_point')
               AND source_chapter IS NOT NULL AND source_chapter < ?
             ORDER BY source_chapter DESC LIMIT ?",
            [$this->novelId, $currentChapter, max($keyEventLimit, 20) + 20]
        );

        // 拆分 UNION 结果
        $keyEventRows = [];
        $coolPointRows = [];
        foreach ($atomRows as $r) {
            if ($r['atom_type'] === 'plot_detail') {
                $meta = json_decode($r['metadata'] ?? '{}', true) ?: [];
                if (!empty($meta['is_key_event'])) {
                    if (count($keyEventRows) < $keyEventLimit) {
                        $keyEventRows[] = $r;
                    }
                }
            } elseif ($r['atom_type'] === 'cool_point') {
                $coolPointRows[] = $r;
            }
        }
        // 对 cool_point 单独补足（UNION 可能被 keyEvent 截断）
        if (count($coolPointRows) < 20) {
            try {
                $extraCoolPoints = DB::fetchAll(
                    "SELECT source_chapter, content, metadata FROM memory_atoms
                     WHERE novel_id=? AND atom_type='cool_point'
                       AND source_chapter IS NOT NULL AND source_chapter < ?
                     ORDER BY source_chapter DESC LIMIT 20",
                    [$this->novelId, $currentChapter]
                );
                $coolPointRows = $extraCoolPoints; // 补足查询更精确
            } catch (\Throwable $e) {
                // 静默降级
            }
        }

        return compact(
            'novel', 'novelState', 'arcSummaries',
            'recentChapters', 'previousTail', 'hookTypeRows',
            'cards',
            'allUnresolvedFs', 'fsDueSoon', 'fsOverdue', 'fsOther',
            'keyEventRows', 'coolPointRows'
        );
    }

    public function getPromptContext(
        int $currentChapter,
        ?string $queryText = null,     // 用来做语义召回的查询文本(通常是本章大纲+前文尾)
        int $tokenBudget = 6000,        // 整个记忆段的字数预算(粗估,中文字符近似 token)
        int $keyEventLimit = 20,
        int $semanticTopK = 8
    ): array {
        // ── v1.4 批量预取：一次性拉取所有关系型数据 ─────────────────
        $b = $this->buildBatch($currentChapter, $keyEventLimit);

        $ctx = [
            'L1_global_settings'    => [],
            'L2_arc_summaries'      => [],
            'L3_recent_chapters'    => [],
            'L4_previous_tail'      => $b['previousTail'],
            'character_states'      => [],
            'key_events'            => [],
            'pending_foreshadowing' => [],
            'story_momentum'        => $b['novelState']['story_momentum'] ?? '',
            'current_arc_summary'   => $b['novelState']['current_arc_summary'] ?? '',
            'arc_summaries'         => [],
            'semantic_hits'         => [],
            'cool_point_history'    => [],
            'recent_hook_types'     => [],
            'debug'                 => [
                'budget_used'  => 0,
                'budget_total' => $tokenBudget,
                'dropped'      => [],
                'batch_queries'=> 7, // 文档化批量查询数量
            ],
        ];

        // ── L1 全局设定 ──────────────────────────────────────────────
        if ($b['novel']) {
            $ctx['L1_global_settings'] = [
                'protagonist_name' => $b['novel']['protagonist_name'] ?? '',
                'protagonist_info' => $b['novel']['protagonist_info'] ?? '',
                'world_settings'   => $b['novel']['world_settings']   ?? '',
                'plot_settings'    => $b['novel']['plot_settings']    ?? '',
                'writing_style'    => $b['novel']['writing_style']    ?? '',
                'genre'            => $b['novel']['genre']            ?? '',
                'extra_settings'   => $b['novel']['extra_settings']   ?? '',
                'style_vector'     => $b['novel']['style_vector']     ?? '',
                'ref_author'       => $b['novel']['ref_author']       ?? '',
            ];
        }

        // ── L2 弧段摘要 ──────────────────────────────────────────────
        $ctx['L2_arc_summaries'] = array_reverse($b['arcSummaries']);
        $ctx['arc_summaries'] = $ctx['L2_arc_summaries'];

        // ── L3 近章大纲 ──────────────────────────────────────────────
        $recentChapters = array_reverse($b['recentChapters']);
        foreach ($recentChapters as $ch) {
            $ctx['L3_recent_chapters'][] = [
                'chapter_number'  => (int)$ch['chapter_number'],
                'chapter'         => (int)$ch['chapter_number'],
                'title'           => $ch['title']       ?? '',
                'outline'         => $ch['outline']     ?? '',
                'hook'            => $ch['hook']        ?? '',
                'key_points'      => json_decode($ch['key_points'] ?? '[]', true),
                'opening_type'    => $ch['opening_type'] ?? '',
                'emotion_score'   => $ch['emotion_score'] ?? null,
                'emotion_density' => $ch['emotion_density'] ?? null,
            ];
        }

        // ── 人物状态（使用批量预取数据，跳过 Repo hydrate 但语义一致）──
        foreach ($b['cards'] as $c) {
            $attrs = null;
            if (!empty($c['attributes'])) {
                $attrs = is_string($c['attributes'])
                    ? json_decode($c['attributes'], true)
                    : $c['attributes'];
            }
            $ctx['character_states'][$c['name']] = [
                'title'         => $c['title'],
                'status'        => $c['status'],
                'alive'         => (int)$c['alive'] === 1,
                'last_chapter'  => $c['last_updated_chapter'],
                'attributes'    => $attrs ?: null,
            ];
        }

        // ── 待回收伏笔（使用批量预取分类数据）────────────────────────
        $pending = array_merge($b['fsOverdue'], $b['fsDueSoon']);
        $lookback = (int)getSystemSetting('ws_foreshadowing_lookback', 10, 'int');
        $otherInWindow = array_filter($b['fsOther'], function($p) use ($currentChapter, $lookback) {
            return $p['planted_chapter'] >= $currentChapter - $lookback;
        });
        $seenIds = array_flip(array_column($pending, 'id'));
        foreach ($otherInWindow as $p) {
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

        // ── 关键事件 ─────────────────────────────────────────────────
        foreach (array_reverse($b['keyEventRows']) as $e) {
            $ctx['key_events'][] = [
                'chapter' => (int)$e['source_chapter'],
                'event'   => $e['content'],
            ];
        }

        // ── 爽点历史 ─────────────────────────────────────────────────
        foreach ($b['coolPointRows'] as $cp) {
            $meta = json_decode($cp['metadata'] ?? '{}', true) ?: [];
            $ctx['cool_point_history'][] = [
                'chapter' => (int)$cp['source_chapter'],
                'type'    => $meta['cool_type'] ?? '',
                'name'    => $meta['type_name']  ?? '',
            ];
        }
        $ctx['cool_point_history'] = array_reverse($ctx['cool_point_history']);

        // ── 近章钩子类型 ─────────────────────────────────────────────
        $ctx['recent_hook_types'] = array_map(fn($r) => [
            'chapter'   => (int)$r['chapter_number'],
            'hook_type' => $r['hook_type'],
        ], array_reverse($b['hookTypeRows']));

        // ── 语义召回 ─────────────────────────────────────────────────
        if ($queryText && EmbeddingProvider::getConfig()) {
            try {
                $hits = $this->semanticSearch($queryText, $semanticTopK, $currentChapter, true, true);
                $ctx['semantic_hits'] = $hits;
            } catch (\Throwable $e) {
                $ctx['debug']['semantic_error'] = $e->getMessage();
            }
        }

        // ── 全书进度上下文（注入后 ChapterPromptBuilder::getProgress() 可直接命中）──
        try {
            $ctx['progress_context'] = $this->getProgressContext($currentChapter);
        } catch (\Throwable $e) {
            $ctx['progress_context'] = null;
        }

        // ── token budget 裁剪 ────────────────────────────────────────
        $this->applyBudget($ctx, $tokenBudget);

        return $ctx;
    }

    // =================================================================
    // 1M 上下文模式：完整上下文构建（无压缩）
    // =================================================================

    /**
     * 1M 上下文模式专用：构建完整上下文，不进行 token 裁剪
     *
     * 适用于 DeepSeek V4 [1m] 等支持超长上下文的模型
     * 特点：
     * - 注入所有已写章节的大纲和正文（而非仅最近5章摘要）
     * - 注入所有未回收伏笔的完整信息
     * - 注入角色完整历史轨迹
     * - 不进行 token budget 裁剪
     *
     * @param int $currentChapter 当前章节号
     * @param int $maxChapters 最大回溯章节数（防止极端情况，默认100）
     * @return array 完整上下文数据
     */
    public function getFullPromptContext(int $currentChapter, int $maxChapters = 100): array
    {
        // 先获取压缩模式的上下文作为基础
        $ctx = $this->getPromptContext($currentChapter, null, 1000000, 50, 20);

        // 移除 budget 裁剪的影响
        $ctx['debug']['mode'] = 'full_1m';
        $ctx['debug']['budget_used'] = 0;
        $ctx['debug']['budget_total'] = 1000000;
        $ctx['debug']['dropped'] = [];

        // ── 1. 完整章节大纲历史（而非仅最近5章）──
        $allOutlines = DB::fetchAll(
            "SELECT chapter_number, title, outline, hook, key_points, opening_type, emotion_score
             FROM chapters
             WHERE novel_id=? AND chapter_number < ? AND status IN ('outlined','writing','completed')
             ORDER BY chapter_number ASC
             LIMIT ?",
            [$this->novelId, $currentChapter, $maxChapters]
        );

        $ctx['full_outlines'] = [];
        foreach ($allOutlines as $ch) {
            $ctx['full_outlines'][] = [
                'chapter'  => (int)$ch['chapter_number'],
                'title'    => $ch['title'] ?? '',
                'outline'  => $ch['outline'] ?? '',
                'hook'     => $ch['hook'] ?? '',
                'key_points' => json_decode($ch['key_points'] ?? '[]', true),
            ];
        }

        // ── 2. 完整章节正文（而非仅前章尾部）──
        // 注：为避免 token 过多，只取最近 N 章的完整正文，更早的取摘要
        $fullContentChapters = min(20, (int)($currentChapter * 0.3));  // 最近 20 章或 30%
        $fullContentChapters = max(5, $fullContentChapters);

        $recentContents = DB::fetchAll(
            "SELECT chapter_number, title, content, chapter_summary
             FROM chapters
             WHERE novel_id=? AND chapter_number < ? AND status='completed'
             ORDER BY chapter_number DESC
             LIMIT ?",
            [$this->novelId, $currentChapter, $fullContentChapters]
        );

        $ctx['full_contents'] = [];
        foreach (array_reverse($recentContents) as $ch) {
            $ctx['full_contents'][] = [
                'chapter'  => (int)$ch['chapter_number'],
                'title'    => $ch['title'] ?? '',
                'content'  => $ch['content'] ?? '',
                'summary'  => $ch['chapter_summary'] ?? '',
            ];
        }

        // 更早章节只取摘要
        $olderSummaries = DB::fetchAll(
            "SELECT chapter_number, title, chapter_summary
             FROM chapters
             WHERE novel_id=? AND chapter_number < ? AND chapter_number >= ? AND status='completed'
             ORDER BY chapter_number ASC",
            [$this->novelId, $currentChapter - $fullContentChapters, max(1, $currentChapter - $maxChapters)]
        );

        $ctx['older_summaries'] = [];
        foreach ($olderSummaries as $ch) {
            if (!empty($ch['chapter_summary'])) {
                $ctx['older_summaries'][] = [
                    'chapter' => (int)$ch['chapter_number'],
                    'title'   => $ch['title'] ?? '',
                    'summary' => $ch['chapter_summary'],
                ];
            }
        }

        // ── 3. 所有未回收伏笔（完整信息，不截断）──
        $allForeshadowing = DB::fetchAll(
            "SELECT id, description, priority, planted_chapter, deadline_chapter,
                    last_mentioned_chapter, mention_count
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
             ORDER BY
                CASE priority WHEN 'critical' THEN 1 WHEN 'major' THEN 2 ELSE 3 END,
                planted_chapter ASC",
            [$this->novelId]
        );

        $ctx['all_foreshadowing'] = [];
        foreach ($allForeshadowing as $f) {
            $ctx['all_foreshadowing'][] = [
                'id'          => (int)$f['id'],
                'description' => $f['description'],
                'priority'    => $f['priority'],
                'planted_at'  => (int)$f['planted_chapter'],
                'deadline'    => $f['deadline_chapter'] ? (int)$f['deadline_chapter'] : null,
                'last_mention'=> $f['last_mentioned_chapter'] ? (int)$f['last_mentioned_chapter'] : null,
                'mention_count' => (int)$f['mention_count'],
            ];
        }

        // ── 4. 角色完整历史轨迹 ──
        $cardsWithHistory = DB::fetchAll(
            "SELECT cc.id, cc.name, cc.title, cc.status, cc.alive, cc.attributes, cc.last_updated_chapter,
                    (SELECT JSON_ARRAYAGG(JSON_OBJECT('chapter', cch.chapter_number, 'field', cch.field_name, 'old', cch.old_value, 'new', cch.new_value))
                     FROM character_card_history cch WHERE cch.card_id = cc.id ORDER BY cch.chapter_number ASC) as history
             FROM character_cards cc
             WHERE cc.novel_id=?
             ORDER BY cc.last_updated_chapter DESC",
            [$this->novelId]
        );

        $ctx['characters_full'] = [];
        foreach ($cardsWithHistory as $card) {
            $attrs = is_string($card['attributes']) ? json_decode($card['attributes'], true) : $card['attributes'];
            $history = is_string($card['history']) ? json_decode($card['history'], true) : $card['history'];
            $ctx['characters_full'][] = [
                'name'      => $card['name'],
                'title'     => $card['title'],
                'status'    => $card['status'],
                'alive'     => (bool)$card['alive'],
                'attributes' => $attrs ?? [],
                'last_chapter' => (int)$card['last_updated_chapter'],
                'history'   => $history ?? [],
            ];
        }

        // ── 5. 所有关键事件（按章节排列）──
        $allKeyEvents = DB::fetchAll(
            "SELECT source_chapter, content, atom_type
             FROM memory_atoms
             WHERE novel_id=? AND atom_type='plot_detail' AND source_chapter < ?
             ORDER BY source_chapter ASC
             LIMIT 200",
            [$this->novelId, $currentChapter]
        );

        $ctx['all_key_events'] = [];
        foreach ($allKeyEvents as $e) {
            $ctx['all_key_events'][] = [
                'chapter' => (int)$e['source_chapter'],
                'event'   => $e['content'],
            ];
        }

        // ── 6. 统计信息 ──
        $ctx['full_context_stats'] = [
            'total_outlines'    => count($ctx['full_outlines']),
            'full_content_chapters' => count($ctx['full_contents']),
            'older_summaries'   => count($ctx['older_summaries']),
            'foreshadowing_count' => count($ctx['all_foreshadowing']),
            'character_count'   => count($ctx['characters_full']),
            'key_events_count'  => count($ctx['all_key_events']),
        ];

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
        // 只取当前弧段的前 N 段,避免膨胀
        $summaries = DB::fetchAll(
            'SELECT arc_index, chapter_from, chapter_to, summary
             FROM arc_summaries
             WHERE novel_id=? AND chapter_to < ?
             ORDER BY chapter_to DESC LIMIT ' . self::MEMORY_ARC_SUMMARY_LIMIT,
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
     *   C. 语义路(embedding 余弦) - memory_atoms + 可选 novel_embeddings (KB) + 可选 foreshadowing_items
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
     * @param bool   $includeForeshadowing 是否把 foreshadowing_items 一并召回
     */
    public function semanticSearch(
        string $query,
        int $topK = 8,
        ?int $beforeChapter = null,
        bool $includeKB = false,
        bool $includeForeshadowing = true
    ): array {
        $excludeTypes = ['plot_detail']; // 避免关键事件被重复召回
        $longTailTypes = array_values(array_diff(AtomRepo::VALID_TYPES, $excludeTypes));

        // 关键词路(每种类型各取 2 条) - 仅 memory_atoms
        // [修复] 传入 $beforeChapter，与语义路一致，防止未来章节 atom 从关键词路漏进 prompt
        $kwHits = [];
        foreach ($longTailTypes as $t) {
            $kwHits = array_merge($kwHits, $this->atoms->search($query, $t, 2, $beforeChapter));
        }

        // 语义路 - 先给 query 做一次 embedding,然后分别召 atoms、KB 和 foreshadowing
        $embHits = [];
        $kbHits  = [];
        $fsHits  = [];
        $qEmb = EmbeddingProvider::embed($query);
        if ($qEmb && !empty($qEmb['vec'])) {
            // atoms 向量
            $atomCandidates = [];
            foreach ($longTailTypes as $t) {
                $atomCandidates = array_merge(
                    $atomCandidates,
                    $this->atoms->listWithEmbedding($t, $beforeChapter, 100)
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

            // foreshadowing_items 向量
            if ($includeForeshadowing) {
                $fsCandidates = DB::fetchAll(
                    "SELECT id, description AS content, embedding AS `blob`, planted_chapter
                     FROM foreshadowing_items
                     WHERE novel_id=? AND embedding IS NOT NULL AND resolved_chapter IS NULL",
                    [$this->novelId]
                );
                if (!empty($fsCandidates)) {
                    $fsHits = Vector::topK($qEmb['vec'], $fsCandidates, $topK, 0.3);
                }
            }
        }

        // 合并:先建索引避免去重时看不到 atom 和 KB 重名
        $merged = [];

        // 辅助函数：根据 source 和 type 确定 category
        $getCategory = function(string $source, string $type): string {
            if ($source === 'atom') {
                if ($type === 'character_trait') return 'character_moments';
                if ($type === 'plot_detail') return 'plot_nodes';
                return 'misc';
            } elseif ($source === 'kb') {
                if ($type === 'character') return 'character_moments';
                if ($type === 'plot') return 'plot_nodes';
                return 'misc';
            } elseif ($source === 'foreshadowing') {
                return 'foreshadow_origins';
            }
            return 'misc';
        };

        // 关键词路(只有 atoms) -> 用 "atom:{id}" 做 key 避免和 KB 的 id 冲突
        foreach ($kwHits as $r) {
            $key = 'atom:' . $r['id'];
            $merged[$key] = [
                'id'       => (int)$r['id'],
                'source'   => 'atom',
                'type'     => $r['atom_type'],
                'content'  => $r['content'],
                'chapter'  => $r['source_chapter'] ? (int)$r['source_chapter'] : null,
                'score'    => (float)($r['_rel'] ?? 0.5),
                'via'      => 'keyword',
                'category' => $getCategory('atom', $r['atom_type']),
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
                    'id'       => (int)$r['id'],
                    'source'   => 'atom',
                    'type'     => $r['atom_type'],
                    'content'  => $r['content'],
                    'chapter'  => $r['source_chapter'] ? (int)$r['source_chapter'] : null,
                    'score'    => (float)$r['_score'],
                    'via'      => 'embedding',
                    'category' => $getCategory('atom', $r['atom_type']),
                ];
            }
        }

        // KB 的语义路
        foreach ($kbHits as $r) {
            $key = 'kb:' . $r['source_type'] . ':' . $r['id'];
            $merged[$key] = [
                'id'       => (int)$r['id'],
                'source'   => 'kb',
                'type'     => $r['source_type'], // character / worldbuilding / plot / style
                'content'  => $r['content'],
                'chapter'  => null,
                'score'    => (float)$r['_score'],
                'via'      => 'embedding',
                'category' => $getCategory('kb', $r['source_type']),
            ];
        }

        // foreshadowing 的语义路
        foreach ($fsHits as $r) {
            $key = 'foreshadowing:' . $r['id'];
            $merged[$key] = [
                'id'       => (int)$r['id'],
                'source'   => 'foreshadowing',
                'type'     => 'foreshadowing',
                'content'  => $r['content'],
                'chapter'  => $r['planted_chapter'] ? (int)$r['planted_chapter'] : null,
                'score'    => (float)$r['_score'],
                'via'      => 'embedding',
                'category' => 'foreshadow_origins',
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
            'recurring_motifs'            => [],
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
                'SELECT major_turning_points, character_arcs, recurring_motifs FROM story_outlines WHERE novel_id=?',
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

                // 全书重复意象
                $ctx['recurring_motifs'] = json_decode($storyOutline['recurring_motifs'] ?? '[]', true) ?: [];
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
                case '关键变化':
                    $attrs['recent_change'] = $v; break;
                case '境界': case 'realm':
                    $attrs['realm'] = $v; break;
                case '等级': case 'level':
                    $attrs['level'] = $v; break;
                case '战力': case 'power':
                    $attrs['power'] = $v; break;
                case '技能': case 'skills':
                    $attrs['skills'] = is_array($v) ? $v : [$v]; break;
                case '装备': case 'equipment':
                    $attrs['equipment'] = is_array($v) ? $v : [$v]; break;
                case '血脉': case 'bloodline':
                    $attrs['bloodline'] = $v; break;
                case '法宝': case 'treasure':
                    $attrs['treasure'] = is_array($v) ? $v : [$v]; break;
                case '感悟': case 'insight':
                    $attrs['insight'] = $v; break;
                default:
                    $attrs[$k] = $v;
            }
        }
        if (!empty($attrs)) {
            $mapped['attributes'] = $attrs;
        }
        return $mapped;
    }

    /**
     * 检测境界跳级（如筑基→元婴跳过金丹）
     * 基于常见修真/玄幻境界体系的关键词匹配
     *
     * @return string|null 警告消息，无跳级返回 null
     */
    private function detectRealmSkip(string $name, ?array $oldCard, array $mapped, int $chapterNumber): ?string
    {
        $newAttrs = $mapped['attributes'] ?? [];
        $newRealm = $newAttrs['realm'] ?? null;
        if (!$newRealm) return null;

        $oldAttrs = null;
        if ($oldCard && !empty($oldCard['attributes'])) {
            $oldAttrs = is_string($oldCard['attributes'])
                ? json_decode($oldCard['attributes'], true)
                : $oldCard['attributes'];
        }
        $oldRealm = $oldAttrs['realm'] ?? null;
        if (!$oldRealm || $oldRealm === $newRealm) return null;

        $realmOrder = ['炼气', '筑基', '金丹', '元婴', '化神', '炼虚', '合体', '大乘', '渡劫',
            '凡人', '武者', '武师', '武王', '武皇', '武宗', '武尊', '武圣', '武帝',
            '见习', '初级', '中级', '高级', '特级', 'S级', 'SS级', 'SSS级',
            '一阶', '二阶', '三阶', '四阶', '五阶', '六阶', '七阶', '八阶', '九阶',
            '斗者', '斗师', '大斗师', '斗灵', '斗王', '斗皇', '斗宗', '斗尊', '斗圣', '斗帝',
        ];

        $oldIdx = -1;
        $newIdx = -1;
        foreach ($realmOrder as $i => $label) {
            if (mb_strpos($oldRealm, $label) !== false) $oldIdx = $i;
            if (mb_strpos($newRealm, $label) !== false) $newIdx = $i;
        }

        if ($oldIdx >= 0 && $newIdx >= 0 && $newIdx > $oldIdx + 1) {
            $skipped = [];
            for ($i = $oldIdx + 1; $i < $newIdx; $i++) {
                $skipped[] = $realmOrder[$i];
            }
            $warning = "⚠️ 境界跳级警告：{$name} 由「{$oldRealm}」直接晋升「{$newRealm}」，跳过了 " . implode('→', $skipped) . "（第{$chapterNumber}章）";
            try {
                addLog($this->novelId, 'realm_skip', $warning);
            } catch (\Throwable) {}
            return $warning;
        }

        return null;
    }

    /**
     * 境界跳级后，生成修复指引存入人物卡片
     * 为每个跳过的境界生成过渡事件，引导 AI 在下章中完整过渡
     */
    private function buildRealmBridgeSuggestion(string $name, ?array $oldCard, array $mapped, int $chapterNumber): array
    {
        $newAttrs = $mapped['attributes'] ?? [];
        $newRealm = $newAttrs['realm'] ?? '';
        if (!$newRealm) return [];

        $oldAttrs = null;
        if ($oldCard && !empty($oldCard['attributes'])) {
            $oldAttrs = is_string($oldCard['attributes'])
                ? json_decode($oldCard['attributes'], true)
                : $oldCard['attributes'];
        }
        $oldRealm = $oldAttrs['realm'] ?? '';
        if (!$oldRealm || $oldRealm === $newRealm) return [];

        $realmOrder = ['炼气', '筑基', '金丹', '元婴', '化神', '炼虚', '合体', '大乘', '渡劫',
            '凡人', '武者', '武师', '武王', '武皇', '武宗', '武尊', '武圣', '武帝',
            '一阶', '二阶', '三阶', '四阶', '五阶', '六阶', '七阶', '八阶', '九阶',
            '斗者', '斗师', '大斗师', '斗灵', '斗王', '斗皇', '斗宗', '斗尊', '斗圣', '斗帝',
        ];

        $oldIdx = -1;
        $newIdx = -1;
        foreach ($realmOrder as $i => $label) {
            if (mb_strpos($oldRealm, $label) !== false) $oldIdx = $i;
            if (mb_strpos($newRealm, $label) !== false) $newIdx = $i;
        }

        $skipped = [];
        $bridgeRealm = '';
        for ($i = $oldIdx + 1; $i < $newIdx; $i++) {
            $skipped[] = $realmOrder[$i];
        }
        if (!empty($skipped)) {
            $bridgeRealm = implode('→', $skipped);
        }

        if ($oldIdx < 0 || $newIdx < 0 || $newIdx <= $oldIdx + 1) return [];

        // 为每个跳过的境界生成过渡事件
        $bridgeEvents = [];
        $eventTemplates = [
            "{$name}在修炼中领悟了%s境界的核心奥义，修为稳步提升",
            "一次意外遭遇中，{$name}被迫以%s级的实力应战，在生死间摸到了%s的门槛",
            "{$name}闭关三日，将之前积累的战斗经验转化为%s境界的突破",
            "借助某件机缘/丹药，{$name}快速跨越了%s阶段，根基却并不稳固",
            "{$name}在探索秘境时，发现了一处蕴含%s力量的遗迹，由此突破了%s的瓶颈",
        ];

        foreach ($skipped as $i => $sRealm) {
            $nextRealm = ($i + 1 < count($skipped)) ? $skipped[$i + 1] : $newRealm;
            $tpl = $eventTemplates[$i % count($eventTemplates)];
            $event = sprintf($tpl, $sRealm, $nextRealm);
            $bridgeEvents[] = "· {$sRealm}期：{$event}";
        }

        $eventList = implode("\n", $bridgeEvents);
        $skippedCount = count($skipped);
        $chapterLabel = $skippedCount === 1 ? "跳过了一个境界" : "跳过了{$skippedCount}个境界";

        // 生成完整过渡章指令
        $bridgeChapter = <<<EOT
【过渡章指令 — 必须在本章中完整执行】
问题：{$name}在第{$chapterNumber}章从「{$oldRealm}」直接跃升至「{$newRealm}」，{$chapterLabel}「{$bridgeRealm}」。
本章需要作为过渡章，通过倒叙/回忆/修炼回溯的方式，将上述被跳过的境界发展过程完整补上。

具体写法：
1. 本章开头或中段，{$name}进入修炼/冥想/回忆状态
2. 通过一段连续叙事（500-800字），描述{$name}依次经历了以下阶段的修炼：

{$eventList}

3. 每个阶段用1-2个段落概括，包含关键事件、瓶颈突破、获得的感悟
4. 过渡完成后，{$name}的境界保持在当前的「{$newRealm}」不变
5. 过渡章节结束后正常衔接本章剩余情节

注意：
- 不要改成纯修炼章节，过渡部分控制在800字以内
- 用回忆/闪回/内心独白等方式自然过渡，不要让角色突然停下来"回忆"
- 过渡段要有事件和冲突，不要写成枯燥的"XX修炼突破到XX"
- 过渡完成后，{$name}的境界仍为「{$newRealm}」
EOT;

        return [
            'realm_skip_warning' => "⚠️ {$name}在第{$chapterNumber}章从「{$oldRealm}」跳至「{$newRealm}」，跳过了「{$bridgeRealm}」",
            'realm_skip_bridge' => $bridgeChapter,
            'realm_skip_skipped' => $bridgeRealm,
            'realm_skip_chapter' => $chapterNumber,
            'realm_skip_old' => $oldRealm,
            'realm_skip_new' => $newRealm,
            'realm_skip_events' => $eventList,
        ];
    }

    /**
     * 将境界跳级修复指引写入下一章的 outline
     * 确保下一章 Prompt 生成时，AI 能看到过渡章标记
     */
    private function injectBridgeOutlineToNextChapter(string $name, int $chapterNumber, array $bridgeSuggestion): void
    {
        if (empty($bridgeSuggestion)) return;
        try {
            $nextChapter = DB::fetch(
                'SELECT id, chapter_number, outline FROM chapters
                 WHERE novel_id=? AND chapter_number=? AND status IN ("outlined","pending")
                 ORDER BY chapter_number ASC LIMIT 1',
                [$this->novelId, $chapterNumber + 1]
            );
            if (!$nextChapter) return;

            $outline = $nextChapter['outline'] ?? '';
            $oldR = $bridgeSuggestion['realm_skip_old'] ?? '';
            $newR = $bridgeSuggestion['realm_skip_new'] ?? '';
            $skipped = $bridgeSuggestion['realm_skip_skipped'] ?? '';
            $events  = $bridgeSuggestion['realm_skip_events'] ?? '';

            $tag = "\n\n【过渡章·境界回溯】上章{$name}境界从「{$oldR}」跳至「{$newR}」跳过了「{$skipped}」。本章需用500-800字回忆/闪回补上中间历程：\n{$events}";

            $newOutline = $outline . $tag;
            DB::update('chapters', ['outline' => $newOutline], 'id=?', [$nextChapter['id']]);
            addLog($this->novelId, 'bridge_outline', "第{$nextChapter['chapter_number']}章大纲已注入境界过渡标记（{$name}：{$oldR}→{$skipped}→{$newR}）");
        } catch (\Throwable $e) {
            error_log('injectBridgeOutlineToNextChapter failed: ' . $e->getMessage());
        }
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
        // L2 弧段摘要:逐步裁剪,至少保留 3 段（覆盖近 30 章历史）
        while ($sumUsed() > $budget && count($ctx['L2_arc_summaries']) > 3) {
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
        while ($sumUsed() > $budget && count($ctx['L2_arc_summaries']) > 2) {
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

    /**
     * 主角名归一化：当 AI 返回的 character_updates 中使用了主角的变体名
     * （如少字、多字、别名），将其合并到 canonical name 下。
     */
    private function normalizeProtagonistKeys(array $updates, string $canonical): array
    {
        if ($canonical === '') return $updates;

        $keys = array_keys($updates);

        // 如果 canonical name 已经存在，只做变体合并
        // 如果 canonical name 不存在，检查是否有变体需要替换
        $hasCanonical = array_key_exists($canonical, $updates);
        $merged = null;
        $remove = [];

        foreach ($keys as $k) {
            if ($k === $canonical) {
                $merged = $k;
                continue;
            }
            // 子串匹配收紧：仅当一方是另一方的前缀或后缀时才视为变体
            // 避免单字如"林"误匹配"林冲"、"林黛玉"等不同角色
            $isVariant = false;
            if (mb_strlen($k) >= 2 && mb_strlen($canonical) >= 2) {
                // k 以 canonical 开头或结尾，或 canonical 以 k 开头或结尾
                $isVariant = (mb_strpos($k, $canonical) === 0 || mb_strpos($canonical, $k) === 0)
                          || (mb_substr($k, -mb_strlen($canonical)) === $canonical)
                          || (mb_substr($canonical, -mb_strlen($k)) === $k);
            }
            if (!$isVariant) continue;

            if ($merged === null) {
                $merged = $canonical;
            }
            if ($k !== $merged) {
                if (isset($updates[$merged])) {
                    $updates[$merged] = array_merge($updates[$merged], $updates[$k]);
                } else {
                    $updates[$merged] = $updates[$k];
                }
                $remove[] = $k;
            }
        }

        foreach ($remove as $k) {
            unset($updates[$k]);
        }

        return $updates;
    }

    /**
     * v1.11.2 Bug #9 修复：规范化情绪记录中的主角变体名
     *
     * 当 AI 返回的 character_emotions 中使用了主角的变体名
     * （如少字、多字、别名），将其替换为 canonical name。
     */
    private function normalizeProtagonistInEmotions(array $emotions, string $canonical): array
    {
        if ($canonical === '' || empty($emotions)) {
            return $emotions;
        }

        foreach ($emotions as &$emo) {
            if (!isset($emo['name']) || !is_string($emo['name'])) {
                continue;
            }
            $name = $emo['name'];
            if ($name === $canonical) {
                continue;
            }
            // 子串匹配：仅当一方是另一方的前缀或后缀时才视为变体
            if (mb_strlen($name) >= 2 && mb_strlen($canonical) >= 2) {
                $isVariant = (mb_strpos($name, $canonical) === 0 || mb_strpos($canonical, $name) === 0)
                          || (mb_substr($name, -mb_strlen($canonical)) === $canonical)
                          || (mb_substr($canonical, -mb_strlen($name)) === $name);
                if ($isVariant) {
                    $emo['name'] = $canonical;
                }
            }
        }

        return $emotions;
    }
}
