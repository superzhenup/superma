<?php
defined('APP_LOADED') or die('Direct access denied.');

// ================================================================
// memory.php — AI 记忆与摘要层（依赖 AI 调用 + data.php）
// 包含：章节摘要生成、弧段摘要生成、人物冲突检测
// ================================================================

/**
 * 章节完成后调用 AI 生成结构化摘要
 * 分段策略：首1500字 + 中间1000字 + 末1000字，避免截断丢失关键情节
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
    $len = mb_strlen($content);

    if ($len > 3500) {
        $head      = mb_substr($content, 0, 1500);
        $mid       = mb_substr($content, (int)($len / 2) - 500, 1000);
        $tail      = mb_substr($content, $len - 1000);
        $truncated = $head . "\n……（中间段节选）……\n" . $mid . "\n……（后段节选）……\n" . $tail;
    } else {
        $truncated = $content;
    }

    $messages = [
        ['role' => 'system', 'content' => '你是一位小说编辑助手。分析刚写完的章节，只输出纯JSON，不要有任何解释、前缀或markdown代码块。'],
        ['role' => 'user',   'content' => <<<EOT
小说《{$novel['title']}》第{$chapter['chapter_number']}章《{$chapter['title']}》

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
  "story_momentum": "当前故事的悬念/冲突状态简述，30字以内，供后续章节保持张力"
}

used_tropes 只提取本章实际出现的意象，最多8个。
new_foreshadowing 和 resolved_foreshadowing 若无则输出空数组[]。
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
        $lines[] = "第{$ch['chapter_number']}章《{$ch['title']}》：{$ch['outline']}{$hookTip}";
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
 * AI 辅助人物状态冲突检测
 * 对比最新章节摘要与当前人物状态卡片，找出矛盾
 *
 * @return array{character: string, issue: string, severity: string}[]
 */
function detectCharacterConflictsWithAI(int $novelId, array $novel): array {
    $characterStates = getCharacterStates($novelId);
    if (empty($characterStates)) return [];

    $latestChapter = DB::fetch(
        'SELECT chapter_summary FROM chapters
         WHERE novel_id=? AND status="completed"
         ORDER BY chapter_number DESC LIMIT 1',
        [$novelId]
    );
    if (!$latestChapter || empty($latestChapter['chapter_summary'])) return [];

    try {
        $ai                  = getAIClient($novel['model_id'] ?: null);
        $characterStatesJson = json_encode($characterStates, JSON_UNESCAPED_UNICODE);

        $prompt = <<<EOT
小说当前人物状态如下：
{$characterStatesJson}

最新章节摘要：
{$latestChapter['chapter_summary']}

请仔细分析最新章节内容是否与上述人物状态存在矛盾。例如：
- 某人物已被设定为"死亡"，但最新章节中以正常状态出现
- 某人物职务发生变化，但最新章节中仍使用旧职务
- 某人物已离开故事，但最新章节中又出现

请只输出JSON数组格式，每项包含：
{"character": "人物名", "issue": "矛盾描述", "severity": "high/medium/low"}

如果无矛盾，输出空数组 []。不要输出任何其他文字。
EOT;

        $result = $ai->chat([
            ['role' => 'system', 'content' => '你是一个小说一致性检测专家，擅长发现人物设定冲突'],
            ['role' => 'user',   'content' => $prompt],
        ], 'structured');

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $result, $m)) {
            $result = $m[1];
        }
        $result = trim($result);
        $start  = strpos($result, '[');
        if ($start !== false) {
            $result = substr($result, $start);
        }

        $conflicts = json_decode($result, true);
        return is_array($conflicts) ? $conflicts : [];
    } catch (Throwable $e) {
        return [];
    }
}
