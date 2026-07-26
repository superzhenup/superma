<?php
defined('APP_LOADED') or die('Direct access denied.');

// ================================================================
// memory.php — AI 记忆与摘要层（依赖 AI 调用 + data.php）
// 包含：章节摘要生成、弧段摘要生成、人物冲突检测
// ================================================================

/**
 * 章节完成后调用 AI 生成结构化摘要
 * v1.7: 动态分段策略——不再硬编码 1500/1000/1000 的三段截取，
 * 而是根据章节内容结构（对话密度、动作段落、情节转折点）智能选择保留段落。
 *
 * @return array{
 *   narrative_summary: string,
 *   character_updates: array,
 *   key_event: string,
 *   used_tropes: array,
 *   new_foreshadowing: array,
 *   resolved_foreshadowing: array,
 *   story_momentum: string,
 *   cool_point_type: string,
 *   character_emotions: array
 * }
 */
function generateChapterSummary(array $novel, array $chapter, string $content): array {
    $len = safe_strlen($content);

    if ($len > 3500) {
        // v1.7: 动态分段取代固定三段截取
        // 按段落拆分，计算每段的"信息密度分数"（对话+动作+情绪关键词命中次数）
        $paragraphs = preg_split('/\n\s*\n/', $content);
        $paragraphs = array_values(array_filter($paragraphs, fn($p) => mb_strlen(trim($p)) > 10));

        $minParagraphs = 3;
        if (count($paragraphs) >= $minParagraphs) {
            $scored = [];
            $densityKeywords = [
                '「', '」', '"', '"', '"', '"', '打', '杀', '战', '破', '怒', '惊',
                '突破', '反转', '爆发', '终于', '逆转', '对抗', '决定', '发现',
                '死', '偷袭', '晋级', '获得', '失去',
            ];

            foreach ($paragraphs as $idx => $p) {
                $score = 0;
                $dialogueChars = mb_substr_count($p, '「') + mb_substr_count($p, '」')
                               + mb_substr_count($p, '"') + mb_substr_count($p, '"')
                               + mb_substr_count($p, '"') + mb_substr_count($p, '"');
                $score += $dialogueChars * 2;
                foreach ($densityKeywords as $kw) {
                    if (mb_strpos($p, $kw) !== false) $score++;
                }
                $totalParas = count($paragraphs);
                if ($idx < $totalParas * 0.2 || $idx > $totalParas * 0.8) {
                    $score = (int)($score * 1.5);
                }
                $scored[] = ['idx' => $idx, 'score' => $score, 'text' => $p];
            }

            usort($scored, fn($a, $b) => $b['score'] - $a['score']);
            $keepCount = max($minParagraphs, (int)(count($paragraphs) * 0.6));
            $selected = array_slice($scored, 0, $keepCount);
            usort($selected, fn($a, $b) => $a['idx'] - $b['idx']);

            $truncated = '';
            $prevIdx = -1;
            foreach ($selected as $sel) {
                if ($prevIdx >= 0 && $sel['idx'] > $prevIdx + 1) {
                    $truncated .= "\n……（省略 " . ($sel['idx'] - $prevIdx - 1) . " 段）……\n";
                }
                $truncated .= $sel['text'] . "\n";
                $prevIdx = $sel['idx'];
            }
        } else {
            $truncated = implode("\n\n", $paragraphs);
        }
    } else {
        $truncated = $content;
    }

    $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);
    $protagonistName = $novel['protagonist_name'] ?? '';
    $protagonistConstraint = $protagonistName
        ? "\n重要约束：本小说主角固定为「{$protagonistName}」，角色数据中必须使用此名字，不可使用其他称呼。"
        : '';

    $pendingForeshadowings = '';
    try {
        $pending = DB::fetchAll(
            'SELECT id, description, priority, planted_chapter
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
             ORDER BY priority ASC, planted_chapter ASC
             LIMIT 20',
            [$novel['id']]
        );
        if (!empty($pending)) {
            $lines = [];
            foreach ($pending as $fs) {
                $pLabel = ['critical'=>'🔴','major'=>'🟡','minor'=>'🟢'][$fs['priority']] ?? '🟢';
                $lines[] = "{$pLabel}[ID:{$fs['id']}] 第{$fs['planted_chapter']}章埋：{$fs['description']}";
            }
            $pendingForeshadowings = "\n【当前未回收的伏笔列表（请据此判断本章是否回收了其中某条）】\n"
                . implode("\n", $lines) . "\n"
                . "\n重要：如果本章确实回收了以上某条伏笔，请在 resolved_foreshadowing 中使用该伏笔的精确原始描述文本（直接复制上面的描述），不要改写。"
                . "\n注意回收判定：完整的揭晓算回收，角色获得关键线索/真相的部分揭露也算。如果本章只是角色想起/提到伏笔但未推进，则不算回收。"
                . "\n如果没有明确回收任何伏笔，resolved_foreshadowing 必须输出空数组 []。\n";
        }
    } catch (\Throwable $e) {
        // 查询伏笔列表失败不影响主流程
    }

    $messages = [
        ['role' => 'system', 'content' => '你是一位小说编辑助手，负责分析刚写完的章节并输出摘要。你必须严格按照指定格式输出。'],
        ['role' => 'user',   'content' => <<<EOT
小说《{$novel['title']}》第{$chNum}章《{$chapter['title']}》
{$protagonistConstraint}
章节大纲：{$chapter['outline']}

章节正文（可能节选）：
{$truncated}
{$pendingForeshadowings}

【重要：必须按以下格式输出，不要省略任何部分】

第一部分：摘要段落
用一段200-300字的自然段落总结本章内容，包含情节要点、人物行动与变化、未解伏笔、章末氛围。

第二部分：分隔符
另起一行，输出三个减号：---

第三部分：JSON数据
另起一行，输出以下JSON格式（用```json和```包裹）：

```json
{{
  "character_updates": {{"角色名": {{"title": "身份/职务", "status": "本章末处境/状态(位置、与主角关系等)", "关键变化": "本章变化，无变化写'本章无显著变化'", "境界": "可选，当前境界/等级"}}}},
  "character_traits": [{{"name": "角色名", "trait": "特征描述", "evidence": "体现该特征的情节"}}],
  "key_event": "本章最重要的事，20字以内",
  "current_location": "主角在本章结束时的位置（具体地点名，如：青云宗内门广场）",
  "location_transition": "如本章有地点移动，简述移动方式（如：传送阵、飞行三日），无移动则留空",
  "used_tropes": ["意象1", "意象2"],
  "new_foreshadowing": [{{"desc": "新埋伏笔描述", "suggested_payoff_chapter": 建议回收章节号}}],
  "resolved_foreshadowing": ["已回收伏笔的原始描述"],
  "story_momentum": "当前故事悬念/冲突状态，30字以内",
  "cool_point_type": "爽点类型（从以下选一个：underdog_win/face_slap/treasure_find/breakthrough/power_expand/romance_win/truth_reveal/last_stand/sacrifice）",
  "character_emotions": [{{"name": "角色名", "state": "情绪（happy/angry/sad/tense/neutral/fearful/determined/melancholy/excited/confused/hopeful/desperate/calm/anxious/proud）", "intensity": 80, "cause": "原因", "expected_decay": 3}}]
}}
```

【字段说明】：
- character_updates：【必填，禁止为空对象】记录本章出场的所有具名角色——主角必记，本章出场的重要配角也必记（最多8个）。每个角色给出 title(身份/职务)、status(本章结束时的处境/状态，含所在位置、与主角的关系等)、关键变化(本章相对此前的变化；若本章无显著变化则写"本章无显著变化")，可附加 境界/等级/能力/法宝 等状态键。目的：为每个角色维护一张随剧情持续更新的状态卡片，确保角色身份、境界、关系前后一致。即使角色状态未变也要登记，以确认其仍在场/存活；只有本章完全未出现的角色才省略。
- character_traits：提取角色性格特征（如沉稳、果断、狡诈），最多3条
- key_event：最重要的单一事件
- current_location：主角在本章结束时所在的场景位置，必须是具体地点名（如"落霞村村口广场"而非"村里"）
- location_transition：如果有地点变化，简述如何到达（如"从青石镇步行半日"），便于下章转场
- used_tropes：本章使用的意象/套路，最多5个
- new_foreshadowing：新埋设的伏笔，无则写空数组 []
- resolved_foreshadowing：已回收的伏笔（必须是上面伏笔列表中的原始描述），无则写空数组 []
- cool_point_type：爽点类型，必须从给定的9种中选择一个或留空
- character_emotions：记录主角和重要配角的情绪变化，无则写空数组 []

【输出示例】：
沈清漪率军追击敌军，途中遭遇埋伏。她利用地形优势，引爆灵晶制造混乱，成功活捉敌方将领。战斗中她修为有所突破，达到Lv.4。战斗结束后，她带领部队返回落霞村休整。

---

```json
{{
  "character_updates": {{"沈清漪": {{"title": "落霞军统帅", "status": "落霞村休整，士气高涨", "关键变化": "修为突破至Lv.4，战力提升", "境界": "Lv.4"}}, "敌方将领": {{"title": "敌军先锋", "status": "被沈清漪生擒，押解返回", "关键变化": "本章被俘", "存活": true}}}},
  "character_traits": [{{"name": "沈清漪", "trait": "果断", "evidence": "面对埋伏立即决定引爆灵晶"}}],
  "key_event": "沈清漪活捉敌方将领",
  "current_location": "落霞村村口广场",
  "location_transition": "战斗结束后，率军徒步半日返回",
  "used_tropes": ["伏击", "突破"],
  "new_foreshadowing": [],
  "resolved_foreshadowing": [],
  "story_momentum": "敌方将领被擒，战局扭转",
  "cool_point_type": "underdog_win",
  "character_emotions": [{{"name": "沈清漪", "state": "determined", "intensity": 85, "cause": "战斗胜利", "expected_decay": 2}}]
}}
```

现在请分析上面的章节并按此格式输出：
EOT
        ],
    ];

    try {
        $ai  = getAIClient($novel['model_id'] ?: null);
        $raw = trim($ai->chat($messages, 'structured'));

        $narrativeSummary = '';
        $result = [];

        // v1.11.8: 增强的 JSON 提取逻辑
        // 策略1: 查找 --- 分隔符
        if (str_contains($raw, '---')) {
            [$summaryPart, $jsonPart] = explode('---', $raw, 2);
            $narrativeSummary = trim($summaryPart);
            $jsonPart = trim($jsonPart);
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $jsonPart, $m)) {
                $jsonPart = trim($m[1]);
            }
            $result = json_decode($jsonPart, true) ?? [];
        }
        // 策略2: 查找 JSON 代码块
        elseif (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
            $jsonStr = trim($m[1]);
            $result = json_decode($jsonStr, true) ?? [];
            if (is_array($result)) {
                $narrativeSummary = (string)($result['narrative_summary'] ?? '');
                // 尝试从 raw 中提取摘要文本（JSON 之前的部分）
                if (empty($narrativeSummary)) {
                    $beforeJson = substr($raw, 0, strpos($raw, '```'));
                    if (!empty(trim($beforeJson))) {
                        $narrativeSummary = trim($beforeJson);
                    }
                }
            }
        }
        // 策略3: 尝试直接解析整个响应为 JSON
        else {
            $result = json_decode($raw, true);
            if (is_array($result)) {
                $narrativeSummary = (string)($result['narrative_summary'] ?? '');
            } else {
                // 策略4: 尝试查找 { 字符开始的位置
                $jsonStart = strpos($raw, '{');
                if ($jsonStart !== false) {
                    $jsonStr = substr($raw, $jsonStart);
                    // 找到最后一个 } 的位置
                    $jsonEnd = strrpos($jsonStr, '}');
                    if ($jsonEnd !== false) {
                        $jsonStr = substr($jsonStr, 0, $jsonEnd + 1);
                        $result = json_decode($jsonStr, true) ?? [];
                        if (is_array($result)) {
                            $narrativeSummary = trim(substr($raw, 0, $jsonStart));
                        }
                    }
                }

                // 如果还是没有解析出 JSON，使用纯文本摘要
                if (!is_array($result) || empty($result)) {
                    $narrativeSummary = $raw;
                    $result = [];

                    // v1.11.8: 尝试从纯文本中提取角色信息
                    $extractedChars = extractCharactersFromText($raw, $protagonistName, (int)$novel['id']);
                    if (!empty($extractedChars)) {
                        $result['character_updates'] = $extractedChars;
                    }
                }
            }
        }

        if (empty($narrativeSummary) && !empty($result['narrative_summary'])) {
            $narrativeSummary = (string)$result['narrative_summary'];
        }

        return [
            'narrative_summary'      => $narrativeSummary,
            'character_updates'      => (array)($result['character_updates']      ?? []),
            'character_traits'       => (array)($result['character_traits']       ?? []),
            'key_event'              => (string)($result['key_event']             ?? ''),
            'current_location'       => (string)($result['current_location']      ?? ''),
            'location_transition'    => (string)($result['location_transition']   ?? ''),
            'used_tropes'            => (array)($result['used_tropes']            ?? []),
            'new_foreshadowing'      => (array)($result['new_foreshadowing']      ?? []),
            'resolved_foreshadowing' => (array)($result['resolved_foreshadowing'] ?? []),
            'story_momentum'         => (string)($result['story_momentum']        ?? ''),
            'cool_point_type'        => (string)($result['cool_point_type']       ?? ''),
            'character_emotions'     => (array)($result['character_emotions']     ?? []),
        ];
    } catch (Throwable $e) {
        // 审计修复 M-8（2026-07-01）：记录异常日志，避免完全静默吞噬
        error_log("generateChapterSummary(chapter={$chapterId}) 失败: " . $e->getMessage());
        return [];
    }
}

/**
 * v1.11.8: 从纯文本摘要中提取角色状态变化
 * 当 AI 未返回 JSON 格式时的降级方案
 */
function extractCharactersFromText(string $text, string $protagonistName = '', int $novelId = 0): array
{
    $updates = [];

    // 从 character_cards 获取已知角色名
    $knownChars = [];
    if ($novelId > 0) {
        try {
            $cards = DB::fetchAll(
                'SELECT name FROM character_cards WHERE novel_id=? LIMIT 20',
                [$novelId]
            );
            $knownChars = array_column($cards, 'name');
        } catch (\Throwable $e) {
            error_log('extractCharactersFromText: failed to load character_cards for novel_id=' . $novelId . ': ' . $e->getMessage());
        }
    }

    // 如果主角名存在且出现在文本中，记录其出场
    if (!empty($protagonistName) && mb_strpos($text, $protagonistName) !== false) {
        // 尝试提取状态变化关键词
        $statusKeywords = ['突破', '升级', '晋级', '获得', '失去', '战胜', '击败', '受伤', '死亡', '恢复'];
        $foundStatus = null;
        foreach ($statusKeywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                // 提取包含关键词的句子片段
                $pos = mb_strpos($text, $kw);
                $start = max(0, $pos - 20);
                $end = min(mb_strlen($text), $pos + 30);
                $foundStatus = mb_substr($text, $start, $end - $start);
                break;
            }
        }

        $updates[$protagonistName] = [
            '关键变化' => $foundStatus ?: '本章出场',
        ];
    }

    // 检查其他已知角色是否出场
    foreach ($knownChars as $name) {
        if ($name === $protagonistName) continue;
        if (mb_strpos($text, $name) !== false) {
            $updates[$name] = [
                '关键变化' => '本章出场',
            ];
        }
    }

    return $updates;
}

/**
 * 生成并保存一个弧段摘要（每批章节生成完后调用）
 * 让 AI 把这批章节的大纲压缩为 180-220 字故事线摘要，
 * 供后续大纲/正文生成时注入全局历史记忆。
 *
 * 使用 chapter_from+chapter_to 作为唯一键，避免5章批次时 arcIndex 碰撞覆盖问题。
 */
function generateAndSaveArcSummary(array $novel, int $chapterFrom, int $chapterTo): bool {
    $arcIndex = (int)floor(($chapterFrom - 1) / 10) + 1;
    $chapters = DB::fetchAll(
        'SELECT chapter_number, title, outline, hook FROM chapters
         WHERE novel_id=? AND chapter_number>=? AND chapter_number<=?
           AND outline IS NOT NULL AND outline != ""
         ORDER BY chapter_number ASC',
        [$novel['id'], $chapterFrom, $chapterTo]
    );
    if (empty($chapters)) return false;

    $lines = [];
    foreach ($chapters as $ch) {
        $hookTip = !empty($ch['hook']) ? "→{$ch['hook']}" : '';
        $chNum = $ch['chapter_number'] ?? $ch['chapter'] ?? 0;
        $lines[] = "第{$chNum}章《{$ch['title']}》：{$ch['outline']}{$hookTip}";
    }
    $outlineText = implode("\n", $lines);

    $messages = [
        ['role' => 'system', 'content' => '你是一位小说编辑，负责将章节大纲压缩为故事线摘要。只输出摘要正文，不要有任何前缀或解释。'],
        ['role' => 'user',   'content' => <<<EOT
小说《{$novel['title']}》第{$chapterFrom}至第{$chapterTo}章的大纲如下：

{$outlineText}

请将以上内容压缩为一段180-220字的故事线摘要，要求：
1. 必须包含：主要情节走向、关键冲突、人物重要变化、章末留下的悬念
2. 语言精炼，不要罗列每章细节，而是呈现这批章节的整体故事弧度
3. 最后一句话说明"本段结束时的故事状态"（人物处境、待解矛盾）
4. 直接输出摘要，不要说"本段摘要如下"之类的开场白
EOT
        ],
    ];

    try {
        $ai      = getAIClient($novel['model_id'] ?: null);
        $summary = trim($ai->chat($messages, 'structured'));
        if (empty($summary)) return false;

        // arcIndex：第几个10章弧段（语义用），唯一性由 chapter_from+chapter_to 保证
        $arcIndex = (int)floor(($chapterFrom - 1) / 10) + 1;

        $existing = DB::fetch(
            'SELECT id FROM arc_summaries WHERE novel_id=? AND chapter_from=? AND chapter_to=?',
            [$novel['id'], $chapterFrom, $chapterTo]
        );
        if ($existing) {
            DB::update('arc_summaries', [
                'arc_index' => $arcIndex,
                'summary'   => $summary,
            ], 'id=?', [$existing['id']]);
        } else {
            DB::insert('arc_summaries', [
                'novel_id'     => $novel['id'],
                'arc_index'    => $arcIndex,
                'chapter_from' => $chapterFrom,
                'chapter_to'   => $chapterTo,
                'summary'      => $summary,
            ]);
        }
        return true;
    } catch (Throwable $e) {
        // 审计修复 M-8（2026-07-01）：记录异常日志，避免完全静默吞噬
        error_log("generateAndSaveArcSummary(novel={$novel['id']}, arc={$arcIndex}) 失败: " . $e->getMessage());
        return false;
    }
}
