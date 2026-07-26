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

    // v2 实例级缓存：同一请求内 getPromptContext/getFullPromptContext 共享 buildBatch 结果
    // 在 auto-write 循环中每章是一个新请求，但请求内多次调用不再重复查 DB
    private array $batchCache = [];
    private array $contextCache = [];

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
        // v2 写入后清空缓存：下一次 getPromptContext 会重建
        $this->batchCache = [];
        $this->contextCache = [];

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
                                } catch (\Throwable $e) { error_log('MemoryEngine card upsert failed: ' . $e->getMessage()); }
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
        // [P1 修复] 预加载本小说所有 character_trait atoms 到内存，避免循环内逐条查 DB
        $charTraits = $summary['character_traits'] ?? [];
        if (is_array($charTraits) && !empty($charTraits)) {
            // 一次性加载现有特征索引：[character_name][trait_key] => atom_id
            $existingTraitIndex = [];
            try {
                $existing = DB::fetchAll(
                    "SELECT id, metadata FROM memory_atoms
                     WHERE novel_id=? AND atom_type='character_trait'",
                    [$this->novelId]
                );
                foreach ($existing as $row) {
                    $meta = is_string($row['metadata']) ? json_decode($row['metadata'], true) : ($row['metadata'] ?? []);
                    $cName = $meta['character_name'] ?? '';
                    $tKey  = $meta['trait_key'] ?? '';
                    if ($cName !== '' && $tKey !== '') {
                        $existingTraitIndex[$cName][$tKey] = (int)$row['id'];
                    }
                }
            } catch (\Throwable $e) {
                // 加载失败时回退至原始逐条逻辑（容错）
                $existingTraitIndex = null;
            }

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

                    // 去重（P1 优化）：优先使用预加载的索引，避免循环内查 DB
                    $dup = null;
                    if ($existingTraitIndex !== null) {
                        if (isset($existingTraitIndex[$trait['name']][$traitKey])) {
                            $dup = ['id' => $existingTraitIndex[$trait['name']][$traitKey]];
                        }
                    } else {
                        // 索引加载失败时的兜底（保持原行为）
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
                            $dup = null;
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
                    // P1 优化：同步更新本地索引，防止同次循环内重复插入
                    if ($existingTraitIndex !== null) {
                        $existingTraitIndex[$trait['name']][$traitKey] = $atomId;
                    }
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
                $emotionTag = $this->detectEmotionTag($keyEvent . ' ' . ($summary['narrative_summary'] ?? ''));
                $atomId = $this->atoms->add('plot_detail', $keyEvent, $chapterNumber, 1.0, [
                    'is_key_event' => 1,
                    'emotion_tag'  => $emotionTag,
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

        // 6) 故事势能 + 场景位置 → novel_state
        // v1.12: 新增场景位置追踪，解决"主角在村里突然看到市区街边"的场景跳跃问题
        $momentum = trim((string)($summary['story_momentum'] ?? ''));
        $currentLocation = trim((string)($summary['current_location'] ?? ''));
        $locationTransition = trim((string)($summary['location_transition'] ?? ''));

        $stateUpdates = ['last_ingested_chapter' => $chapterNumber];

        if ($momentum !== '') {
            $stateUpdates['story_momentum'] = $momentum;
        }

        // 位置更新：有新位置时才更新，否则保留旧位置（主角可能多章在同一地点）
        if ($currentLocation !== '') {
            $stateUpdates['current_location'] = $currentLocation;
            $stateUpdates['location_chapter'] = $chapterNumber;
            if ($locationTransition !== '') {
                $stateUpdates['location_transition'] = $locationTransition;
            }
            $report['location_updated'] = $currentLocation;
        }

        try {
            $this->upsertNovelState($stateUpdates);
            if ($momentum !== '') {
                $report['momentum_updated'] = true;
            }
        } catch (\Throwable $e) {
            $report['errors'][] = 'novel_state: ' . $e->getMessage();
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
        // v2 实例缓存：同一请求内多次调用（如 getPromptContext + getFullPromptContext 回退）复用结果
        $cacheKey = "batch:{$currentChapter}:{$keyEventLimit}";
        if (isset($this->batchCache[$cacheKey])) {
            return $this->batchCache[$cacheKey];
        }

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
             ORDER BY chapter_to DESC LIMIT ' . max(1, (int)getSystemSetting('ws_arc_summary_limit', 50, 'int')),
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
            // 上一章不存在或未完成（乱序写作/重写/章节空洞）时向前回溯最近的已完成章，
            // 与 data.php::getPreviousTail() 行为对齐，避免衔接尾文为空。
            if (!$prev || empty($prev['content'])) {
                $prev = DB::fetch(
                    "SELECT content FROM chapters
                     WHERE novel_id=? AND chapter_number < ? AND status = 'completed'
                     ORDER BY chapter_number DESC LIMIT 1",
                    [$this->novelId, $currentChapter]
                );
            }
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

        // 当前章节大纲 + 久未登场重要角色候选。必须精确读取当前大纲，
        // 不能使用可能混入前章尾文的语义 queryText 判断角色是否出场。
        $currentOutline = '';
        $returningCharacterRows = [];
        if (getSystemSetting('ws_returning_character_enabled', true, 'bool')) {
            try {
                $currentChapterRow = DB::fetch(
                    'SELECT outline FROM chapters WHERE novel_id=? AND chapter_number=? LIMIT 1',
                    [$this->novelId, $currentChapter]
                );
                $currentOutline = trim((string)($currentChapterRow['outline'] ?? ''));
                if ($currentOutline !== '') {
                    $returningCharacterRows = DB::fetchAll(
                        "SELECT cc.name, cc.title, cc.status, cc.alive, cc.last_updated_chapter,
                                nc.role_type, nc.background
                         FROM character_cards cc
                         INNER JOIN novel_characters nc
                           ON nc.novel_id=cc.novel_id AND nc.name=cc.name
                         WHERE cc.novel_id=? AND cc.alive=1
                           AND cc.last_updated_chapter IS NOT NULL
                           AND cc.last_updated_chapter < ?
                           AND nc.role_type IN ('protagonist','major')",
                        [$this->novelId, $currentChapter]
                    );
                }
            } catch (\Throwable $e) {
                error_log('MemoryEngine returning character prefetch failed: ' . $e->getMessage());
                $currentOutline = '';
                $returningCharacterRows = [];
            }
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

        $result = compact(
            'novel', 'novelState', 'arcSummaries',
            'recentChapters', 'previousTail', 'hookTypeRows',
            'cards',
            'currentOutline', 'returningCharacterRows',
            'allUnresolvedFs', 'fsDueSoon', 'fsOverdue', 'fsOther',
            'keyEventRows', 'coolPointRows'
        );
        // 存入实例缓存（同一请求内多次调用命中）
        if (!isset($this->batchCache[$cacheKey])) {
            $this->batchCache[$cacheKey] = $result;
        }
        return $result;
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
            'returning_characters'  => [],
            'key_events'            => [],
            'pending_foreshadowing' => [],
            'story_momentum'        => $b['novelState']['story_momentum'] ?? '',
            'current_location'      => $b['novelState']['current_location'] ?? '',
            'location_chapter'      => $b['novelState']['location_chapter'] ?? null,
            'location_transition'   => $b['novelState']['location_transition'] ?? '',
            'current_arc_summary'   => $b['novelState']['current_arc_summary'] ?? '',
            'arc_summaries'         => [],
            'semantic_hits'         => [],
            'cool_point_history'    => [],
            'recent_hook_types'     => [],
            'debug'                 => [
                'budget_used'  => 0,
                'budget_total' => $tokenBudget,
                'dropped'      => [],
                'batch_queries'=> 9, // 启用重登场提醒时最多增加当前大纲和角色候选两次查询
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

        // ── 久未登场重要角色（只基于当前章节精确大纲）────────────────
        if (getSystemSetting('ws_returning_character_enabled', true, 'bool')) {
            $gapThreshold = max(1, (int)getSystemSetting('ws_returning_character_gap', 15, 'int'));
            $limit = max(1, min(3, (int)getSystemSetting('ws_returning_character_limit', 3, 'int')));
            $ctx['returning_characters'] = $this->selectReturningCharacters(
                $b['returningCharacterRows'] ?? [],
                $b['currentOutline'] ?? '',
                $currentChapter,
                $gapThreshold,
                $limit
            );
        }
        $ctx['debug']['returning_characters'] = [
            'matched' => count($ctx['returning_characters']),
            'emitted' => count($ctx['returning_characters']),
        ];

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
        $urgencyEnabled = (bool)getSystemSetting('ws_foreshadow_urgency_enabled', true, 'bool');
        $ctx['pending_foreshadowing'] = $this->buildPrioritizedForeshadowing(
            $pending,
            $currentChapter,
            $urgencyEnabled
        );
        $urgencyLevels = array_count_values(array_column($ctx['pending_foreshadowing'], 'urgency_level'));
        $ctx['debug']['foreshadow_urgency'] = [
            'total'         => count($ctx['pending_foreshadowing']),
            'critical'      => (int)($urgencyLevels['critical'] ?? 0),
            'high'          => (int)($urgencyLevels['high'] ?? 0),
            'normal'        => (int)($urgencyLevels['normal'] ?? 0),
            'critical_kept' => 0,
            'dropped'       => 0,
        ];

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

        // ── 语义召回（混合检索：图谱 + 向量双路）─────────────────────
        if ($queryText) {
            try {
                // 提取活动实体（轻量方案：角色名匹配大纲）
                $activeEntities = $this->extractActiveEntities($queryText);
                $hits = $this->hybridSearch($queryText, $activeEntities, $currentChapter, $semanticTopK);

                $currentEmotion = $this->detectEmotionTag($queryText);
                if ($currentEmotion !== 'neutral' && !empty($hits)) {
                    foreach ($hits as &$hit) {
                        if (($hit['source'] ?? '') === 'atom') {
                            $meta = null;
                            try {
                                $row = DB::fetch('SELECT metadata FROM memory_atoms WHERE id=?', [$hit['id'] ?? 0]);
                                $meta = json_decode($row['metadata'] ?? '{}', true) ?: [];
                            } catch (\Throwable $e) { error_log('MemoryEngine metadata fetch failed: ' . $e->getMessage()); }
                            $hitEmotion = $meta['emotion_tag'] ?? '';
                            if ($hitEmotion !== '' && $hitEmotion === $currentEmotion) {
                                $hit['score'] = ($hit['score'] ?? 0.5) * 1.3;
                            }
                        }
                    }
                    unset($hit);
                    usort($hits, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
                }

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

    /**
     * 将待回收伏笔转换为统一 prompt context 契约，并按 deadline 紧急度排序。
     */
    private function buildPrioritizedForeshadowing(array $pending, int $currentChapter, bool $enabled): array
    {
        $result = [];
        foreach ($pending as $p) {
            $deadline = isset($p['deadline_chapter']) && (int)$p['deadline_chapter'] > 0
                ? (int)$p['deadline_chapter']
                : null;
            $item = [
                'id'       => (int)($p['id'] ?? 0),
                'chapter'  => (int)($p['planted_chapter'] ?? $p['chapter'] ?? 0),
                'desc'     => (string)($p['description'] ?? $p['desc'] ?? ''),
                'deadline' => $deadline,
            ];
            if ($enabled) {
                $remaining = $deadline === null ? null : $deadline - $currentChapter;
                if ($remaining !== null && $remaining < 0) {
                    $item['urgency_level'] = 'critical';
                    $item['urgency_score'] = 120 + min(30, abs($remaining));
                    $item['is_overdue'] = true;
                } elseif ($remaining !== null && $remaining <= 2) {
                    $item['urgency_level'] = 'critical';
                    $item['urgency_score'] = 100 - ($remaining * 5);
                    $item['is_overdue'] = false;
                } elseif ($remaining !== null && $remaining <= 5) {
                    $item['urgency_level'] = 'high';
                    $item['urgency_score'] = 70 - $remaining;
                    $item['is_overdue'] = false;
                } else {
                    $item['urgency_level'] = 'normal';
                    $item['urgency_score'] = 20;
                    $item['is_overdue'] = false;
                }
            }
            $result[] = $item;
        }

        if (!$enabled) return $result;

        usort($result, static function(array $a, array $b): int {
            $scoreCmp = ($b['urgency_score'] ?? 0) <=> ($a['urgency_score'] ?? 0);
            if ($scoreCmp !== 0) return $scoreCmp;
            $deadlineCmp = ($a['deadline'] ?? PHP_INT_MAX) <=> ($b['deadline'] ?? PHP_INT_MAX);
            if ($deadlineCmp !== 0) return $deadlineCmp;
            $chapterCmp = ($a['chapter'] ?? 0) <=> ($b['chapter'] ?? 0);
            if ($chapterCmp !== 0) return $chapterCmp;
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
        return $result;
    }

    /**
     * 从精确当前大纲中筛出久未登场的重要角色。
     */
    private function selectReturningCharacters(
        array $rows,
        string $outline,
        int $currentChapter,
        int $gapThreshold,
        int $limit
    ): array {
        if (trim($outline) === '' || $limit <= 0) return [];
        $limit = min(3, $limit);

        $candidates = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $roleType = (string)($row['role_type'] ?? '');
            $lastChapter = (int)($row['last_updated_chapter'] ?? 0);
            $gap = $currentChapter - $lastChapter;
            if ($name === '' || (int)($row['alive'] ?? 0) !== 1) continue;
            if (!in_array($roleType, ['protagonist', 'major'], true)) continue;
            if ($lastChapter <= 0 || $gap < $gapThreshold) continue;
            if (mb_strpos($outline, $name) === false) continue;

            $identity = trim((string)($row['background'] ?? ''));
            if ($identity === '') $identity = trim((string)($row['title'] ?? ''));
            if ($identity === '') $identity = trim((string)($row['status'] ?? ''));
            $identity = mb_substr($identity, 0, 80);

            $candidates[] = [
                'name'         => $name,
                'role_type'    => $roleType,
                'title'        => trim((string)($row['title'] ?? '')),
                'status'       => trim((string)($row['status'] ?? '')),
                'identity'     => $identity,
                'last_chapter' => $lastChapter,
                'gap'          => $gap,
            ];
        }

        usort($candidates, static function(array $a, array $b): int {
            $roleRank = ['protagonist' => 0, 'major' => 1];
            $roleCmp = ($roleRank[$a['role_type']] ?? 9) <=> ($roleRank[$b['role_type']] ?? 9);
            if ($roleCmp !== 0) return $roleCmp;
            $gapCmp = ($b['gap'] ?? 0) <=> ($a['gap'] ?? 0);
            if ($gapCmp !== 0) return $gapCmp;
            return strcmp((string)$a['name'], (string)$b['name']);
        });

        return array_slice($candidates, 0, $limit);
    }

    // =================================================================
    // 1M 上下文模式：按显式模型能力构建大容量历史上下文
    // =================================================================

    /**
     * 1M 上下文模式专用：在硬预算内尽可能注入完整历史。
     *
     * 仅供 ai_models.capabilities 包含 context_1m 的模型使用。
     * 特点：
     * - 在硬预算内优先注入最近正文，并尽可能覆盖历史大纲/摘要
     * - 按优先级注入未回收伏笔和角色历史轨迹
     * - 保留语义 RAG，并为最终 prompt 预留输出与系统指令空间
     * - 达到预算时优先保留最近章节全文，较早章节降级为摘要
     *
     * @param int $currentChapter 当前章节号
     * @param int $maxChapters 最大回溯章节数；0 表示不按章数限制
     * @param string|null $queryText 语义检索查询，不能因完整模式而关闭 RAG
     * @param int $tokenBudget 本次记忆上下文 token 硬预算
     * @return array 完整上下文数据
     */
    public function getFullPromptContext(
        int $currentChapter,
        int $maxChapters = 0,
        ?string $queryText = null,
        int $tokenBudget = 800000
    ): array
    {
        $tokenBudget = max(100000, min(900000, $tokenBudget));
        $baseBudget = min(100000, max(20000, intdiv($tokenBudget, 8)));
        $ctx = $this->getPromptContext($currentChapter, $queryText, $baseBudget, 50, 20);

        $ctx['debug']['mode'] = 'full_1m';
        $ctx['debug']['budget_used'] = 0;
        $ctx['debug']['budget_total'] = $tokenBudget;
        // 中文文本按 1 字≈1 token 保守估算，避免不同 tokenizer 下越过模型上限。
        $promptCharBudget = (int)floor($tokenBudget * 0.9);
        $ctx['debug']['prompt_char_budget'] = $promptCharBudget;
        $ctx['debug']['budget_unit'] = 'conservative_unicode_chars';
        $ctx['debug']['dropped'] = [];

        // 统一使用保守的 Unicode 字符预算；不能一边声明 1 字≈1 token，
        // 一边又除以 2.2 低估中文上下文。
        $usedChars = mb_strlen(json_encode($ctx, JSON_UNESCAPED_UNICODE));
        $remainingChars = max(0, $promptCharBudget - $usedChars);
        $historyFloor = $maxChapters > 0
            ? max(1, $currentChapter - $maxChapters)
            : 1;

        $ctx['full_outlines'] = [];
        // 按实际序列化长度装入大纲，而不是假定每条只有 100 token。
        // 从最近章节倒序选择，预算不足时优先保留与当前章最相关的大纲。
        $outlineBudget = (int)floor($remainingChars * 0.20);
        $availableOutlineCount = max(0, $currentChapter - $historyFloor);
        $allOutlines = $availableOutlineCount > 0 ? DB::fetchAll(
            "SELECT chapter_number, title, outline, hook, key_points, opening_type, emotion_score
             FROM chapters
             WHERE novel_id=? AND chapter_number >= ? AND chapter_number < ? AND status IN ('outlined','writing','completed')
             ORDER BY chapter_number DESC
             LIMIT ?",
            [$this->novelId, $historyFloor, $currentChapter, $availableOutlineCount]
        ) : [];
        $outlineUsed = 0;
        foreach ($allOutlines as $ch) {
            $outlineText = ($ch['outline'] ?? '');
            if (mb_strlen($outlineText) > 1500) {
                $outlineText = mb_substr($outlineText, 0, 1500) . '…';
            }
            $outlineEntry = [
                'chapter'  => (int)$ch['chapter_number'],
                'title'    => mb_substr($ch['title'] ?? '', 0, 50),
                'outline'  => $outlineText,
                'hook'     => mb_substr($ch['hook'] ?? '', 0, 300),
                'key_points' => array_slice(json_decode($ch['key_points'] ?? '[]', true) ?? [], 0, 10),
            ];
            $entryCost = mb_strlen(json_encode($outlineEntry, JSON_UNESCAPED_UNICODE)) + 8;
            if ($outlineUsed + $entryCost > $outlineBudget) continue;
            $outlineUsed += $entryCost;
            $ctx['full_outlines'][] = $outlineEntry;
        }
        $ctx['full_outlines'] = array_reverse($ctx['full_outlines']);

        $remainingChars = max(0, $remainingChars - $outlineUsed);
        // 最多把余量的 75% 给正文，给人物/伏笔/事件和最终提示词包装保留空间。
        $contentBudget = (int)floor($remainingChars * 0.72);
        $historyContents = DB::fetchAll(
            "SELECT chapter_number, title, content, chapter_summary
             FROM chapters
             WHERE novel_id=? AND chapter_number >= ? AND chapter_number < ? AND status='completed'
             ORDER BY chapter_number DESC
             LIMIT ?",
            [$this->novelId, $historyFloor, $currentChapter, max(1, $currentChapter - $historyFloor)]
        );

        $ctx['full_contents'] = [];
        $ctx['older_summaries'] = [];
        $selectedContentChapters = [];
        $contentUsed = 0;
        foreach ($historyContents as $ch) {
            $content = trim((string)($ch['content'] ?? ''));
            if ($content === '') continue;
            $entryCost = mb_strlen($content) + mb_strlen((string)($ch['title'] ?? '')) + 80;
            if ($contentUsed + $entryCost > $contentBudget) continue;

            $selectedContentChapters[(int)$ch['chapter_number']] = true;
            $contentUsed += $entryCost;
            $ctx['full_contents'][] = [
                'chapter'  => (int)$ch['chapter_number'],
                'title'    => mb_substr($ch['title'] ?? '', 0, 50),
                'content'  => $content,
                'summary'  => mb_substr($ch['chapter_summary'] ?? '', 0, 500),
            ];
        }
        // 保持最近章节优先；PromptBuilder 也会按章号倒序做最终防护。

        // 没有进入全文区的较早章节，用摘要补齐时间线，仍受剩余预算约束。
        $remainingChars = max(0, $remainingChars - $contentUsed);
        $summaryBudget = max(0, (int)floor($remainingChars * 0.30));
        $summaryUsed = 0;
        foreach (array_reverse($historyContents) as $ch) {
            $chapterNo = (int)$ch['chapter_number'];
            if (isset($selectedContentChapters[$chapterNo])) continue;
            $summary = trim((string)($ch['chapter_summary'] ?? ''));
            if ($summary !== '') {
                $summary = mb_substr($summary, 0, 800);
                $cost = mb_strlen($summary) + 80;
                if ($summaryUsed + $cost > $summaryBudget) break;
                $summaryUsed += $cost;
                $ctx['older_summaries'][] = [
                    'chapter' => $chapterNo,
                    'title'   => mb_substr($ch['title'] ?? '', 0, 50),
                    'summary' => $summary,
                ];
            }
        }

        $ctx['all_foreshadowing'] = [];
        $foreshadowLimit = 30;
        $allForeshadowing = DB::fetchAll(
            "SELECT id, description, priority, planted_chapter, deadline_chapter,
                    last_mentioned_chapter, mention_count
             FROM foreshadowing_items
             WHERE novel_id=? AND planted_chapter < ?
               AND (resolved_chapter IS NULL OR resolved_chapter >= ?)
             ORDER BY
                CASE priority WHEN 'critical' THEN 1 WHEN 'major' THEN 2 ELSE 3 END,
                planted_chapter ASC
             LIMIT ?",
            [$this->novelId, $currentChapter, $currentChapter, $foreshadowLimit]
        );
        foreach ($allForeshadowing as $f) {
            $ctx['all_foreshadowing'][] = [
                'id'          => (int)$f['id'],
                'description' => mb_substr($f['description'], 0, 200),
                'priority'    => $f['priority'],
                'planted_at'  => (int)$f['planted_chapter'],
                'deadline'    => $f['deadline_chapter'] ? (int)$f['deadline_chapter'] : null,
                'last_mention'=> $f['last_mentioned_chapter'] ? (int)$f['last_mentioned_chapter'] : null,
                'mention_count' => (int)$f['mention_count'],
            ];
        }

        $ctx['characters_full'] = [];
        $charLimit = 15;
        $cardsWithHistory = DB::fetchAll(
            "SELECT cc.id, cc.name, cc.title, cc.status, cc.alive, cc.attributes, cc.last_updated_chapter,
                    (SELECT JSON_ARRAYAGG(JSON_OBJECT('chapter', cch.chapter_number, 'field', cch.field_name, 'old', cch.old_value, 'new', cch.new_value))
                     FROM character_card_history cch WHERE cch.card_id = cc.id ORDER BY cch.chapter_number ASC) as history
             FROM character_cards cc
             WHERE cc.novel_id=?
             ORDER BY cc.last_updated_chapter DESC
             LIMIT ?",
            [$this->novelId, $charLimit]
        );

        foreach ($cardsWithHistory as $card) {
            $attrs = is_string($card['attributes']) ? json_decode($card['attributes'], true) : $card['attributes'];
            $history = is_string($card['history']) ? json_decode($card['history'], true) : $card['history'];
            if (is_array($history) && count($history) > 10) {
                $history = array_slice($history, -10);
            }
            $ctx['characters_full'][] = [
                'name'      => $card['name'],
                'title'     => $card['title'],
                'status'    => $card['status'],
                'alive'     => (bool)$card['alive'],
                'attributes' => is_array($attrs) ? array_slice($attrs, 0, 10, true) : [],
                'last_chapter' => (int)$card['last_updated_chapter'],
                'history'   => $history ?? [],
            ];
        }

        $ctx['all_key_events'] = [];
        $eventsLimit = 50;
        $allKeyEvents = DB::fetchAll(
            "SELECT source_chapter, content, metadata
             FROM memory_atoms
             WHERE novel_id=? AND atom_type='plot_detail' AND source_chapter < ?
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.is_key_event')) IN ('1','true')
             ORDER BY source_chapter DESC
             LIMIT ?",
            [$this->novelId, $currentChapter, $eventsLimit]
        );

        // 查询优先取最近事件，再恢复为时间正序交给提示词。
        foreach (array_reverse($allKeyEvents) as $e) {
            $ctx['all_key_events'][] = [
                'chapter' => (int)$e['source_chapter'],
                'event'   => mb_substr($e['content'], 0, 150),
            ];
        }

        // ── 6. 最终硬预算 ──
        // 单条人物历史等字段可能远大于平均值，因此分配预算后仍以完整序列化
        // 长度复核；按低优先级依次移除，不能只依赖 PromptBuilder 事后撕段。
        $estimatedChars = mb_strlen(json_encode($ctx, JSON_UNESCAPED_UNICODE));
        $hardContextBudget = max(10000, $promptCharBudget - 4096); // 预留统计与包装
        $trimmed = [];
        $trimForBudget = static function (
            array &$context,
            string $key,
            bool $fromFront
        ) use (&$estimatedChars, $hardContextBudget, &$trimmed): void {
            while ($estimatedChars > $hardContextBudget && !empty($context[$key])) {
                $removed = $fromFront ? array_shift($context[$key]) : array_pop($context[$key]);
                $estimatedChars -= max(
                    1,
                    mb_strlen(json_encode($removed, JSON_UNESCAPED_UNICODE)) + 1
                );
                $trimmed[$key] = ($trimmed[$key] ?? 0) + 1;
            }
            // Re-sync after each priority tier so JSON container overhead cannot
            // accumulate into an optimistic estimate.
            $estimatedChars = mb_strlen(json_encode($context, JSON_UNESCAPED_UNICODE));
        };
        foreach ([
            ['older_summaries', true],       // oldest summaries first
            ['full_outlines', true],         // oldest outlines first
            ['all_key_events', true],        // oldest key events first
            ['characters_full', false],      // least-recently updated cards last
            ['all_foreshadowing', false],    // lowest-priority items sorted last
            ['full_contents', false],        // contents are recent-first; drop oldest
        ] as [$key, $fromFront]) {
            $trimForBudget($ctx, $key, $fromFront);
            if ($estimatedChars <= $hardContextBudget) break;
        }
        if ($trimmed !== []) {
            $ctx['debug']['dropped'][] = ['reason' => 'hard_budget_trim', 'counts' => $trimmed];
        }

        // ── 7. 统计信息 ──
        $ctx['full_context_stats'] = [
            'total_outlines'    => count($ctx['full_outlines']),
            'full_content_chapters' => count($ctx['full_contents']),
            'older_summaries'   => count($ctx['older_summaries']),
            'foreshadowing_count' => count($ctx['all_foreshadowing']),
            'character_count'   => count($ctx['characters_full']),
            'key_events_count'  => count($ctx['all_key_events']),
            'history_floor'     => $historyFloor,
            'content_chars'     => $contentUsed,
            'dropped_full_content_chapters' => max(0, count($historyContents) - count($ctx['full_contents'])),
        ];

        $actualContextChars = mb_strlen(json_encode($ctx, JSON_UNESCAPED_UNICODE));
        $ctx['debug']['budget_used'] = $actualContextChars;
        if ($actualContextChars > $promptCharBudget) {
            $ctx['debug']['dropped'][] = [
                'reason' => 'serialized_context_exceeds_char_budget',
                'over_by' => $actualContextChars - $promptCharBudget,
            ];
        }

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
             ORDER BY chapter_to DESC LIMIT ' . max(1, (int)getSystemSetting('ws_arc_summary_limit', 50, 'int')),
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
            // 审计修复（2026-07-19 H-07 残留）：用查询向量所属模型过滤候选集，
            // 避免模型切换后旧模型向量（同维度不同语义空间）污染召回结果。
            $qModel = $qEmb['model'] ?? '';
            // atoms 向量
            $atomCandidates = [];
            $atomPool = max(50, (int)getSystemSetting('ws_atom_pool_size', 500, 'int'));
            foreach ($longTailTypes as $t) {
                $atomCandidates = array_merge(
                    $atomCandidates,
                    $this->atoms->listWithEmbedding($t, $beforeChapter, $atomPool, $qModel)
                );
            }
            if (!empty($atomCandidates)) {
                $embHits = Vector::topK($qEmb['vec'], $atomCandidates, $topK, CFG_VECTOR_SIM_THRESHOLD);
            }

            // KB 向量(novel_embeddings 表,字段不一样要改造为 Vector::topK 的输入格式)
            // [v41] 1000章优化：加 recency 上限，避免长篇下全表暴力扫余弦拖慢每章写作。
            // 默认 1500 仅作运行时护栏，普通小说不会触及；超长篇下世界观长记忆由「全书圣经」(缓存前缀) 兜底。
            // 审计修复 B11（2026-06-16）：硬编码 1500/300/0.3 抽取为 CFG_KB_SCAN_LIMIT / CFG_FORESHADOW_SCAN_LIMIT / CFG_VECTOR_SIM_THRESHOLD
            if ($includeKB) {
                $kbScanLimit = max(200, (int)getSystemSetting('ws_kb_scan_limit', CFG_KB_SCAN_LIMIT, 'int'));
                $kbSql = "SELECT source_id AS id, source_type, content, embedding_blob AS `blob`
                     FROM novel_embeddings
                     WHERE novel_id=? AND source_type IN ('character','worldbuilding','plot','style')";
                $kbParams = [$this->novelId];
                if ($qModel !== '') {
                    $kbSql .= ' AND embedding_model=?';
                    $kbParams[] = $qModel;
                }
                $kbSql .= ' ORDER BY novel_embeddings.id DESC LIMIT ' . (int)$kbScanLimit;
                $kbCandidates = DB::fetchAll($kbSql, $kbParams);
                if (!empty($kbCandidates)) {
                    $kbHits = Vector::topK($qEmb['vec'], $kbCandidates, $topK, CFG_VECTOR_SIM_THRESHOLD);
                }
            }

            // foreshadowing_items 向量——未回收伏笔是长程线索，优先级高者优先保留
            // [v41] 加上限护栏：按 priority(critical>major>minor) 再按埋设章倒序，截断尾部低优先级
            if ($includeForeshadowing) {
                $fsScanLimit = max(100, (int)getSystemSetting('ws_foreshadow_scan_limit', CFG_FORESHADOW_SCAN_LIMIT, 'int'));
                $fsSql = "SELECT id, description AS content, embedding AS `blob`, planted_chapter
                     FROM foreshadowing_items
                     WHERE novel_id=? AND embedding IS NOT NULL AND resolved_chapter IS NULL";
                $fsParams = [$this->novelId];
                if ($qModel !== '') {
                    $fsSql .= ' AND embedding_model=?';
                    $fsParams[] = $qModel;
                }
                $fsSql .= " ORDER BY FIELD(priority,'critical','major','minor'), planted_chapter DESC LIMIT " . (int)$fsScanLimit;
                $fsCandidates = DB::fetchAll($fsSql, $fsParams);
                if (!empty($fsCandidates)) {
                    $fsHits = Vector::topK($qEmb['vec'], $fsCandidates, $topK, CFG_VECTOR_SIM_THRESHOLD);
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
    // 2.5 混合检索 — 图谱 + 向量双路召回
    // =================================================================

    /**
     * 混合检索入口：图谱关联召回 + 传统向量/关键词检索 双路合并
     *
     * @param string $query           查询文本（本章大纲）
     * @param array  $activeEntities  当前章涉及的活动实体名（角色/物品/组织）
     * @param int    $currentChapter  当前章节号
     * @param int    $topK            最大返回条数
     * @return array 合并后的召回结果
     */
    public function hybridSearch(
        string $query,
        array $activeEntities,
        int $currentChapter,
        int $topK = 15
    ): array {
        // 1. 获取小说的图谱起始章节
        $state = $this->getNovelState();
        $graphStartChapter = isset($state['graph_start_chapter']) ? (int)$state['graph_start_chapter'] : 0;
        $graphEnabled = getSystemSetting('ws_graph_search_enabled', true, 'bool');

        $graphHits  = [];
        $vectorHits = [];

        // 2. 图谱关联召回（仅对 graphStartChapter 之后的章节有效）
        if ($graphEnabled && $graphStartChapter > 0 && $currentChapter >= $graphStartChapter && !empty($activeEntities)) {
            try {
                $graphHits = $this->retrieveAssociativeGraph($activeEntities, $currentChapter);
            } catch (\Throwable $e) {
                error_log('MemoryEngine::hybridSearch graph failed: ' . $e->getMessage());
            }
        }

        // 3. 传统向量+关键词检索（对图谱未覆盖的早期章节也生效）
        try {
            $vectorHits = $this->semanticSearch($query, $topK, $currentChapter, true, true);
        } catch (\Throwable $e) {
            error_log('MemoryEngine::hybridSearch vector failed: ' . $e->getMessage());
        }

        // 4. 合并去重
        return $this->mergeAndDedupHits($graphHits, $vectorHits, $topK);
    }

    /**
     * 两步图谱拓扑检索 (2-Hop Graph Retrieval)
     * 从 story_relations 表中检索与活动实体直接关联和二次关联的关系三元组
     *
     * @param array $entities       活动实体名列表
     * @param int   $currentChapter 当前章节号（只检索之前的关系）
     * @return array 图谱命中结果
     */
    private function retrieveAssociativeGraph(array $entities, int $currentChapter): array
    {
        $hits = [];
        // 过滤掉空字符串/过短/重复的实体名，防止 IN 子句异常或匹配过宽
        $entities = array_values(array_unique(array_filter(array_map(
            fn($e) => trim((string)$e),
            $entities
        ), fn($e) => $e !== '' && mb_strlen($e) >= 2)));
        if (empty($entities)) return $hits;

        $hop1Limit = max(10, (int)getSystemSetting('ws_graph_hop1_limit', 30, 'int'));
        $hop2Limit = max(5,  (int)getSystemSetting('ws_graph_hop2_limit', 20, 'int'));
        $maxHops   = max(1,  (int)getSystemSetting('ws_graph_max_hops', 2, 'int'));

        // 构建 SQL IN 占位符
        $placeholders = implode(',', array_fill(0, count($entities), '?'));
        // 参数数组：novel_id + entities(source) + entities(target) + currentChapter
        $params = array_merge([$this->novelId], $entities, $entities, [$currentChapter]);

        // 第一步：检索直接关联关系 (1-Hop)
        // LIMIT 内嵌 SQL 是安全的（已 max() 强转 int），但仍用 sprintf 显式标注意图
        try {
            $relations = DB::fetchAll(
                "SELECT source_entity, relation_type, target_entity, description, source_chapter
                 FROM story_relations
                 WHERE novel_id = ? AND (source_entity IN ($placeholders) OR target_entity IN ($placeholders))
                   AND source_chapter < ?
                 ORDER BY source_chapter DESC LIMIT " . (int)$hop1Limit,
                $params
            );
        } catch (\Throwable $e) {
            // story_relations 表可能尚未建立（首次部署），静默返回
            error_log('MemoryEngine::retrieveAssociativeGraph hop1 failed: ' . $e->getMessage());
            return [];
        }

        // 第二步：收集直接关联实体，寻找二次关联 (2-Hop)
        if ($maxHops >= 2 && !empty($relations)) {
            $secondHopEntities = [];
            foreach ($relations as $rel) {
                $secondHopEntities[] = $rel['source_entity'];
                $secondHopEntities[] = $rel['target_entity'];
            }
            $secondHopEntities = array_values(array_unique(array_diff($secondHopEntities, $entities)));
            // 过滤空字符串避免 IN ('') 误匹配
            $secondHopEntities = array_filter($secondHopEntities, fn($e) => is_string($e) && trim($e) !== '');

            if (!empty($secondHopEntities)) {
                $s2Placeholders = implode(',', array_fill(0, count($secondHopEntities), '?'));
                $s2Params = array_merge([$this->novelId], $secondHopEntities, $secondHopEntities, [$currentChapter]);

                try {
                    $secondRelations = DB::fetchAll(
                        "SELECT source_entity, relation_type, target_entity, description, source_chapter
                         FROM story_relations
                         WHERE novel_id = ? AND (source_entity IN ($s2Placeholders) OR target_entity IN ($s2Placeholders))
                           AND source_chapter < ?
                         ORDER BY source_chapter DESC LIMIT " . (int)$hop2Limit,
                        $s2Params
                    );
                    $relations = array_merge($relations, $secondRelations);
                } catch (\Throwable $e) {
                    error_log('MemoryEngine::retrieveAssociativeGraph hop2 failed: ' . $e->getMessage());
                }
            }
        }

        // 格式化为 Context 文本段
        foreach ($relations as $r) {
            $desc = trim((string)$r['description']);
            $content = $desc !== ''
                ? "[图谱关联] {$desc} (第{$r['source_chapter']}章)"
                : "[图谱关联] {$r['source_entity']} → {$r['relation_type']} → {$r['target_entity']} (第{$r['source_chapter']}章)";

            $hits[] = [
                'id'       => 0,
                'source'   => 'graph',
                'type'     => 'relationship',
                'content'  => $content,
                'chapter'  => (int)$r['source_chapter'],
                'score'    => 1.0, // 图谱匹配直接赋予高优先级
                'via'      => 'graph',
                'category' => 'plot_nodes',
            ];
        }

        return $hits;
    }

    /**
     * 合并图谱命中和向量命中，去重后按 score 降序排列
     *
     * @param array $graphHits  图谱召回结果
     * @param array $vectorHits 向量/关键词召回结果
     * @param int   $topK       最大返回条数
     * @return array 合并后的结果
     */
    private function mergeAndDedupHits(array $graphHits, array $vectorHits, int $topK): array
    {
        $merged = [];
        $seenContent = [];

        // 图谱命中优先（score 高）
        foreach ($graphHits as $hit) {
            $contentKey = mb_substr(trim($hit['content']), 0, 80);
            if (isset($seenContent[$contentKey])) continue;
            $seenContent[$contentKey] = true;
            $merged[] = $hit;
        }

        // 向量命中补充
        foreach ($vectorHits as $hit) {
            $contentKey = mb_substr(trim($hit['content']), 0, 80);
            if (isset($seenContent[$contentKey])) continue;
            $seenContent[$contentKey] = true;
            $merged[] = $hit;
        }

        // 按 score 降序
        usort($merged, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($merged, 0, $topK);
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
            'graph_start_chapter'   => null,
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
        // 审计修复（2026-07-19 M2-5）：原 stats() 传 PHP_INT_MAX 给 status()，
        // 使所有带 deadline 的未回收伏笔全部计入 overdue（deadline_chapter < PHP_INT_MAX-3 恒真），
        // 管理面板逾期数长期虚高。改为取全书最大章节号作为"当前章"，逾期统计回归真实含义。
        $currentChapter = (int)DB::fetchColumn(
            'SELECT COALESCE(MAX(chapter_number), 0) FROM chapters WHERE novel_id=?',
            [$this->novelId]
        );
        return [
            'cards'             => count($this->cards->listAll()),
            'atoms_by_type'     => $this->atoms->countByType(),
            'foreshadowing'     => $this->foreshadowing->status($currentChapter),
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

        // 使用 PowerSystem 获取动态境界链（替代硬编码）
        try {
            require_once __DIR__ . '/../PowerSystem.php';
            $ps = new PowerSystem($this->novelId);
            $realmOrder = $ps->getRealmOrder();
        } catch (\Throwable $e) {
            // PowerSystem 完全不可用时，用修仙体系作为最终兜底（不再使用混合链）
            $realmOrder = ['炼气', '筑基', '金丹', '元婴', '化神', '炼虚', '合体', '大乘', '渡劫'];
        }

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
            } catch (\Throwable $e) { error_log('MemoryEngine realm_skip addLog failed: ' . $e->getMessage()); }
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

        // 使用 PowerSystem 获取动态境界链（替代硬编码）
        try {
            require_once __DIR__ . '/../PowerSystem.php';
            $ps = new PowerSystem($this->novelId);
            $realmOrder = $ps->getRealmOrder();
            // 如果 worldbuilding 有自定义境界链，使用它；否则用内置默认
        } catch (\Throwable $e) {
            // PowerSystem 完全不可用时，用修仙体系作为最终兜底（不再使用混合链）
            $realmOrder = ['炼气', '筑基', '金丹', '元婴', '化神', '炼虚', '合体', '大乘', '渡劫'];
        }

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
        $pendingBeforeCount = count($ctx['pending_foreshadowing']);
        $urgencyEnabled = (bool)getSystemSetting('ws_foreshadow_urgency_enabled', true, 'bool');
        $criticalKeepLimit = max(1, min(3, (int)getSystemSetting('ws_foreshadow_critical_keep', 3, 'int')));
        $criticalBefore = count(array_filter(
            $ctx['pending_foreshadowing'],
            static fn($item) => ($item['urgency_level'] ?? '') === 'critical'
        ));
        $minForeshadowKeep = $urgencyEnabled && $criticalBefore > 0
            ? min($criticalKeepLimit, $criticalBefore)
            : 3;

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
            // [修复] 主角必须钉住保留：buildCharacterSection 依赖 states[主角] 生成
            //        「主角境界 HEAD 强约束」。若主角某章未登场、recency 落后于多个活跃配角，
            //        原逻辑会把主角裁掉，导致境界锚点失效、前后境界不一致。
            $protagonist = trim((string)($ctx['L1_global_settings']['protagonist_name'] ?? ''));
            // character_states 键为 name,主角优先,其余按 last_chapter 降序保留
            $items = [];
            foreach ($ctx['character_states'] as $name => $state) {
                $items[] = ['name' => $name, 'state' => $state, 'last' => (int)($state['last_chapter'] ?? 0)];
            }
            usort($items, function ($a, $b) use ($protagonist) {
                if ($protagonist !== '') {
                    if ($a['name'] === $protagonist) return -1;
                    if ($b['name'] === $protagonist) return 1;
                }
                return $b['last'] <=> $a['last'];
            });
            $kept = [];
            $used = 0;
            foreach ($items as $it) {
                $rowLen = mb_strlen($it['name']) + $this->approxLen($it['state']);
                // 主角不受预算上限约束，必定保留；其余角色超预算即停。
                if ($it['name'] !== $protagonist && $used + $rowLen > $csCap && !empty($kept)) break;
                $kept[$it['name']] = $it['state'];
                $used += $rowLen;
            }
            $ctx['character_states'] = $kept;
            $ctx['debug']['dropped'][] = 'character_states capped by last_chapter (protagonist pinned)';
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
        // pending_foreshadowing 已按紧急度排序；从尾部开始砍，只保证有限数量 critical。
        while ($sumUsed() > $budget && count($ctx['pending_foreshadowing']) > $minForeshadowKeep) {
            array_pop($ctx['pending_foreshadowing']);
        }
        // 极端情况:P1 还超预算,砍到 L3 剩 2 章、L2 剩 0。
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
        $criticalKept = count(array_filter(
            $ctx['pending_foreshadowing'],
            static fn($item) => ($item['urgency_level'] ?? '') === 'critical'
        ));
        $ctx['debug']['foreshadow_urgency'] = array_merge(
            $ctx['debug']['foreshadow_urgency'] ?? [],
            [
                'critical_kept' => $criticalKept,
                'dropped' => max(0, $pendingBeforeCount - count($ctx['pending_foreshadowing'])),
            ]
        );
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

    private const EMOTION_KEYWORDS = [
        'tense'   => ['追杀','围困','对峙','危机','阴谋','伏击','陷阱','生死','决战','血战','杀机','围杀','暗算','绝境'],
        'tragic'  => ['牺牲','陨落','离别','死亡','覆灭','背叛','绝望','惨烈','代价','永别','痛失','殒命','诀别','崩溃'],
        'triumph' => ['突破','逆袭','反杀','觉醒','翻盘','大胜','碾压','顿悟','渡劫','成功','凯旋','击败','斩杀','灭杀','臣服'],
        'warm'    => ['重逢','守护','陪伴','温馨','日常','休整','归家','团圆','治愈','安慰','关怀','微笑','拥抱','温馨'],
        'eerie'   => ['诡异','阴暗','古墓','深渊','幽灵','诅咒','封印','禁地','血月','黑暗','迷雾','鬼火','阴森','未知'],
        'epic'    => ['天劫','浩劫','毁灭','降世','远古','传承','禁忌','天命','万古','纪元','天地','混沌','洪荒','苍穹'],
        'romantic'=> ['心动','暧昧','亲吻','告白','羞涩','脸红','拥抱','温柔','依偎','相拥','深情','倾心','暗恋','相思'],
    ];

    /**
     * 轻量实体提取：从查询文本中提取活动实体名
     * 基于角色库、世界观库和圣经节点中的实体名做字符串扫描
     *
     * @param string $text 查询文本（通常是本章大纲+标题）
     * @return array 匹配到的实体名列表
     */
    private function extractActiveEntities(string $text): array
    {
        if (trim($text) === '') return [];

        $allNames = [];
        $matched = [];
        $entityCandidateLimit = function_exists('getSystemSetting')
            ? max(50, min(2000, (int)getSystemSetting('ws_entity_candidate_limit', 500, 'int')))
            : 500;

        $entityQueries = [
            "SELECT name FROM character_cards WHERE novel_id=? AND alive=1
             ORDER BY last_updated_chapter DESC, id DESC LIMIT " . (int)$entityCandidateLimit,
            "SELECT name FROM novel_characters WHERE novel_id=? AND role_type IN ('protagonist','major')
             ORDER BY role_type ASC, id DESC LIMIT " . (int)$entityCandidateLimit,
            "SELECT name FROM novel_worldbuilding WHERE novel_id=?
             ORDER BY importance DESC, id DESC LIMIT " . (int)$entityCandidateLimit,
            "SELECT node_title AS name FROM bible_nodes WHERE novel_id=?
             ORDER BY is_locked DESC, last_updated_chapter DESC, id DESC LIMIT " . (int)$entityCandidateLimit,
        ];

        foreach ($entityQueries as $sql) {
            try {
                $rows = DB::fetchAll($sql, [$this->novelId]);
                foreach ($rows as $row) {
                    $name = trim((string)($row['name'] ?? ''));
                    if ($name !== '') $allNames[] = $name;
                }
            } catch (\Throwable $e) {
                error_log('MemoryEngine::extractActiveEntities source failed: ' . $e->getMessage());
            }
        }

        foreach (array_unique($allNames) as $name) {
            if (mb_strlen($name) < 2) continue;
            if (mb_strpos($text, $name) !== false) {
                $matched[] = $name;
            }
        }

        return array_values(array_unique($matched));
    }

    private function detectEmotionTag(string $text): string
    {
        if (trim($text) === '') return 'neutral';
        $scores = [];
        foreach (self::EMOTION_KEYWORDS as $emotion => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                if (mb_strpos($text, $kw) !== false) $score++;
            }
            if ($score > 0) $scores[$emotion] = $score;
        }
        if (empty($scores)) return 'neutral';
        arsort($scores);
        return array_key_first($scores);
    }
}
