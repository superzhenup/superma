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
 *   story_momentum: string
 * }
 */
function generateChapterSummary(array $novel, array $chapter, string $content): array {
    $len = safe_strlen($content);

    if ($len > 3500) {
        // v1.7: 动态分段取代固定三段截取
        // 按段落拆分，计算每段的"信息密度分数"（对话+动作+情绪关键词命中次数）
        $paragraphs = preg_split('/\n\s*\n/', $content);
        $paragraphs = array_values(array_filter($paragraphs, fn($p) => mb_strlen(trim($p)) > 10));

        if (count($paragraphs) >= 5) {
            $scored = [];
            // 信息密度关键词：对话标记 + 动作词 + 情绪关键词
            $densityKeywords = [
                '[「」""\'\'""]', '打', '杀', '战', '破', '怒', '惊', '突破',
                '反转', '爆发', '终于', '逆转', '对抗', '决定', '发现',
                '死', '偷袭', '突破', '晋级', '获得', '失去',
            ];

            foreach ($paragraphs as $idx => $p) {
                $score = 0;
                // 对话密度加权
                $dialogueChars = mb_substr_count($p, '「') + mb_substr_count($p, '」')
                               + mb_substr_count($p, '"') + mb_substr_count($p, '"')
                               + mb_substr_count($p, '"') + mb_substr_count($p, '"');
                $score += $dialogueChars * 2;
                // 关键词命中
                foreach ($densityKeywords as $kw) {
                    if (mb_strpos($p, $kw) !== false) $score++;
                }
                // 位置加权：首 20% 和末 20% 段落加权 1.5x（开篇和收尾最重要）
                $totalParas = count($paragraphs);
                if ($idx < $totalParas * 0.2 || $idx > $totalParas * 0.8) {
                    $score = (int)($score * 1.5);
                }
                $scored[] = ['idx' => $idx, 'score' => $score, 'text' => $p];
            }

            // 按分数降序排列，取前 60% 的段落（高密度优先）
            usort($scored, fn($a, $b) => $b['score'] - $a['score']);
            $keepCount = max(3, (int)(count($paragraphs) * 0.6));
            $selected = array_slice($scored, 0, $keepCount);

            // 恢复原始顺序
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
            // 段落太少，直接用旧策略
            $head      = safe_substr($content, 0, 1500);
            $mid       = safe_substr($content, (int)($len / 2) - 500, 1000);
            $tail      = safe_substr($content, $len - 1000);
            $truncated = $head . "\n……（中间段节选）……\n" . $mid . "\n……（后段节选）……\n" . $tail;
        }
    } else {
        $truncated = $content;
    }

    $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);
    $messages = [
        ['role' => 'system', 'content' => '你是一位小说编辑助手。分析刚写完的章节，只输出纯JSON，不要有任何解释、前缀或markdown代码块。'],
        ['role' => 'user',   'content' => <<<EOT
小说《{$novel['title']}》第{$chNum}章《{$chapter['title']}》

章节大纲：{$chapter['outline']}

章节正文（可能节选）：
{$truncated}

请输出以下格式的JSON（只输出JSON，不要有其他文字）：
{
  "narrative_summary": "200-300字的叙事摘要，包含情节要点、未解伏笔、章末氛围",
  "character_updates": {
    "人物名": {"职务": "当前职务", "处境": "当前处境简述", "关键变化": "本章发生的最重要变化"}
  },
  "key_event": "本章最重要的一件事，20字以内一句话概括",
  "used_tropes": ["意象或场景关键词1", "意象或场景关键词2"],
  "new_foreshadowing": [{"desc": "新埋伏笔描述", "suggested_payoff_chapter": 预计回收章节号}],
  "resolved_foreshadowing": ["已回收的伏笔描述（与pending_foreshadowing中的desc匹配）"],
  "story_momentum": "当前故事的悬念/冲突状态简述，30字以内，供后续章节保持张力",
  "cool_point_type": "本章核心爽点类型（六选一）：underdog_win / face_slap / treasure_find / breakthrough / power_expand / romance_win。若无明确爽点则留空"
}

used_tropes 只提取本章实际出现的意象，最多8个。
new_foreshadowing 和 resolved_foreshadowing 若无则输出空数组[]。
cool_point_type 判断规则：
- underdog_win = 主角以弱胜强，击败比自己强的对手
- face_slap = 打脸反转，之前轻视主角的人被打脸
- treasure_find = 获得宝物/奇遇/天材地宝
- breakthrough = 修为突破/晋级/境界提升
- power_expand = 势力扩张/收服手下/获得地盘
- romance_win = 红颜倾心/情感突破/关系进展
EOT
        ],
    ];

    try {
        $ai  = getAIClient($novel['model_id'] ?: null);
        $raw = trim($ai->chat($messages, 'structured'));

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $result = json_decode($raw, true);
        if (!is_array($result)) return [];

        return [
            'narrative_summary'      => (string)($result['narrative_summary']      ?? ''),
            'character_updates'      => (array)($result['character_updates']       ?? []),
            'key_event'              => (string)($result['key_event']              ?? ''),
            'used_tropes'            => (array)($result['used_tropes']             ?? []),
            'new_foreshadowing'      => (array)($result['new_foreshadowing']       ?? []),
            'resolved_foreshadowing' => (array)($result['resolved_foreshadowing'] ?? []),
            'story_momentum'         => (string)($result['story_momentum']         ?? ''),
            'cool_point_type'        => (string)($result['cool_point_type']        ?? ''),
        ];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 生成并保存一个弧段摘要（每批章节生成完后调用）
 * 让 AI 把这批章节的大纲压缩为 180-220 字故事线摘要，
 * 供后续大纲/正文生成时注入全局历史记忆。
 *
 * 使用 chapter_from+chapter_to 作为唯一键，避免5章批次时 arcIndex 碰撞覆盖问题。
 */
function generateAndSaveArcSummary(array $novel, int $chapterFrom, int $chapterTo): bool {
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
        return false;
    }
}

/**
 * AI 辅助人物状态冲突检测（v1.7: 多模型交叉验证）
 * 对比最新章节摘要与当前人物状态卡片，找出矛盾。
 * 主模型检测后，备用模型做二次验证，减少误报。
 *
 * @return array{character: string, issue: string, severity: string, backup_confirmed: bool}[]
 */
function detectCharacterConflictsWithAI(int $novelId, array $novel): array {
    // 从 character_cards 读取当前人物状态（替代老的 novels.character_states JSON）
    require_once __DIR__ . '/memory/MemoryEngine.php';
    $engine = new MemoryEngine($novelId);
    $cards  = $engine->cards()->listAll();
    if (empty($cards)) return [];

    $latestChapter = DB::fetch(
        'SELECT chapter_summary FROM chapters
         WHERE novel_id=? AND status="completed"
         ORDER BY chapter_number DESC LIMIT 1',
        [$novelId]
    );
    if (!$latestChapter || empty($latestChapter['chapter_summary'])) return [];

    // 把 character_cards 压成 AI 友好的简短 JSON
    $statesForAi = [];
    foreach ($cards as $c) {
        $statesForAi[$c['name']] = [
            'alive'  => $c['alive'] ? 1 : 0,
            'title'  => $c['title']  ?? '',
            'status' => $c['status'] ?? '',
        ];
    }
    $characterStatesJson = json_encode($statesForAi, JSON_UNESCAPED_UNICODE);

    $systemPrompt = '你是一个小说一致性检测专家，擅长发现人物设定冲突';

    $userPrompt = <<<EOT
小说当前人物状态如下：
{$characterStatesJson}

最新章节摘要：
{$latestChapter['chapter_summary']}

请仔细分析最新章节内容是否与上述人物状态存在矛盾。例如：
- 某人物已被设定为"死亡"(alive=0)，但最新章节中以正常状态出现
- 某人物职务发生变化，但最新章节中仍使用旧职务
- 某人物已离开故事，但最新章节中又出现

请只输出JSON数组格式，每项包含：
{"character": "人物名", "issue": "矛盾描述", "severity": "high/medium/low"}

如果无矛盾，输出空数组 []。不要输出任何其他文字。
EOT;

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt],
    ];

    // 封装结果解析逻辑
    $parseResult = function(string $raw): array {
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
            $raw = $m[1];
        }
        $raw = trim($raw);
        $start = strpos($raw, '[');
        if ($start !== false) {
            $raw = substr($raw, $start);
        }
        $conflicts = json_decode($raw, true);
        return is_array($conflicts) ? $conflicts : [];
    };

    try {
        // --- 主模型检测 ---
        $ai = getAIClient($novel['model_id'] ?: null);
        $raw = trim($ai->chat($messages, 'structured'));
        $primaryConflicts = $parseResult($raw);

        // 主模型未检测到冲突，直接返回
        if (empty($primaryConflicts)) return [];

        // --- v1.7: 备用模型交叉验证 ---
        // 主模型检测到冲突时，用备用模型做二次验证
        // 两模型一致的冲突才标记为高置信度（backup_confirmed=true）
        try {
            $allModels = getModelFallbackList($novel['model_id'] ?: null, 'structured');
            // 找第二个模型（跳过主模型自己）
            $backupModel = null;
            foreach ($allModels as $m) {
                if ((int)$m['id'] !== (int)($novel['model_id'] ?? 0)) {
                    $backupModel = $m;
                    break;
                }
            }

            if ($backupModel) {
                $backupAi = new AIClient($backupModel);
                $backupRaw = trim($backupAi->chat($messages, 'structured'));
                $backupConflicts = $parseResult($backupRaw);

                // 比较两模型结果：构建「人物名-问题」键做交集
                $primaryKeys = [];
                foreach ($primaryConflicts as $c) {
                    $primaryKeys[] = $c['character'] . '::' . mb_substr($c['issue'] ?? '', 0, 20);
                }
                $backupKeys = [];
                foreach ($backupConflicts as $c) {
                    $backupKeys[] = $c['character'] . '::' . mb_substr($c['issue'] ?? '', 0, 20);
                }
                $confirmedKeys = array_intersect($primaryKeys, $backupKeys);

                // 标记交叉验证结果
                $verified = [];
                foreach ($primaryConflicts as $c) {
                    $key = $c['character'] . '::' . mb_substr($c['issue'] ?? '', 0, 20);
                    $c['backup_confirmed'] = in_array($key, $confirmedKeys);
                    // 仅备用模型也确认的保留 high；单模型标记降级为 low
                    if (!$c['backup_confirmed'] && $c['severity'] === 'high') {
                        $c['severity'] = 'medium';
                    }
                    $verified[] = $c;
                }

                // 记录交叉验证结果，便于诊断
                $confByBoth = count($confirmedKeys);
                $onlyPrimary = count($primaryConflicts) - $confByBoth;
                if ($onlyPrimary > 0 || count($backupConflicts) - $confByBoth > 0) {
                    $backupLabel = $backupModel['name'] ?? '备用模型';
                    error_log("人物冲突检测交叉验证：双模型共识{$confByBoth}条，仅主模型{$onlyPrimary}条，备用模型{$backupLabel}");
                }

                return $verified;
            }
        } catch (Throwable $e) {
            // 备用模型不可用时，主模型结果降级处理
            error_log('人物冲突备用模型验证失败：' . $e->getMessage());
        }

        // 无备用模型或验证失败 → 返回主模型结果（全部标记未验证）
        foreach ($primaryConflicts as &$c) {
            $c['backup_confirmed'] = false;
        }
        return $primaryConflicts;

    } catch (Throwable $e) {
        return [];
    }
}
