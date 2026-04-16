<?php
defined('APP_LOADED') or die('Direct access denied.');

// ================================================================
// prompt.php — Prompt 构建层（依赖 data.php / helpers.php，不含 AI 调用）
// 包含：大纲/正文/故事大纲/章节简介 的 messages 数组构建
// ================================================================

/**
 * 构建大纲生成 Prompt（三层记忆：弧段摘要 + 近章大纲 + 上批钩子）
 */
function buildOutlinePrompt(array $novel, int $startChapter, int $endChapter, array $recentOutlines = [], string $prevHook = ''): array {
    $count = $endChapter - $startChapter + 1;

    $truncate = fn(string $text, int $limit = 300): string =>
        mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…（略）' : $text;

    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 300);
    $plotSettings    = $truncate($novel['plot_settings']    ?? '', 300);
    $worldSettings   = $truncate($novel['world_settings']   ?? '', 300);
    $extraSettings   = $truncate($novel['extra_settings']   ?? '', 200);

    // 第二层：弧段摘要（全局历史记忆，防止 AI 对早期情节失忆）
    $arcSection   = '';
    $arcSummaries = getArcSummaries((int)$novel['id']);
    if (!empty($arcSummaries)) {
        $arcLines = [];
        foreach ($arcSummaries as $arc) {
            if ($arc['chapter_to'] < $startChapter) {
                $arcLines[] = "【第{$arc['chapter_from']}-{$arc['chapter_to']}章故事线】{$arc['summary']}";
            }
        }
        if (!empty($arcLines)) {
            $arcSection = "\n【全书故事线回顾（按时序，必须与此保持一致）】\n"
                        . implode("\n\n", $arcLines) . "\n";
        }
    }

    // 第三层：近 8 章局部大纲（微观衔接）
    $contextSection = '';
    if (!empty($recentOutlines)) {
        $lines = [];
        foreach (array_slice($recentOutlines, -8) as $oc) {
            $summary = mb_substr(trim($oc['outline'] ?? ''), 0, 80);
            $hookTip = !empty($oc['hook']) ? "  →钩子：{$oc['hook']}" : '';
            $lines[] = "第{$oc['chapter_number']}章《{$oc['title']}》：{$summary}{$hookTip}";
        }
        $contextSection = "\n【前情参考（保持剧情连贯，注意每章钩子的承接）】\n"
                        . implode("\n", $lines) . "\n";
    }

    // 上批结尾钩子（本批第一章必须接续）
    $prevHookSection = $prevHook !== ''
        ? "\n【上一章结尾钩子（本批第{$startChapter}章开头必须直接承接这个悬念）】\n{$prevHook}\n"
        : '';

    // 全书关键事件日志（防止情节重复）
    $keyEventsSection = '';
    $keyEvents = getKeyEvents((int)$novel['id']);
    if (!empty($keyEvents)) {
        $lines = [];
        foreach ($keyEvents as $e) {
            $lines[] = "第{$e['chapter']}章：{$e['event']}";
        }
        $keyEventsSection = "\n【全书已发生关键事件（严禁重复这些事件）】\n" . implode("\n", $lines) . "\n";
    }

    // 人物当前状态（防止职务混乱）
    $characterSection = '';
    $characterStates  = getCharacterStates((int)$novel['id']);
    if (!empty($characterStates)) {
        $lines = [];
        foreach ($characterStates as $name => $state) {
            $parts = [];
            if (!empty($state['职务'])) $parts[] = "职务：{$state['职务']}";
            if (!empty($state['处境'])) $parts[] = "处境：{$state['处境']}";
            $lines[] = "{$name}——" . implode('，', $parts);
        }
        $characterSection = "\n【人物当前状态（大纲中必须与此一致）】\n" . implode("\n", $lines) . "\n";
    }

    // 待回收伏笔（$startChapter 判断 deadline，修复原来 $chapter 未定义 BUG）
    $foreshadowSection = '';
    $pendingForeshadow = getPendingForeshadowing((int)$novel['id']);
    $chNum = $startChapter;
    if (!empty($pendingForeshadow)) {
        $lines = [];
        foreach ($pendingForeshadow as $f) {
            $deadline = '';
            if (!empty($f['deadline'])) {
                $deadline = $chNum >= (int)$f['deadline'] - 5
                    ? "⚠️【紧急】建议{$f['deadline']}章前回收（已临近）"
                    : "（建议{$f['deadline']}章前回收）";
            }
            $lines[] = "第{$f['chapter']}章埋：{$f['desc']}{$deadline}";
        }
        $foreshadowSection = "\n【待回收伏笔（大纲中应适时安排回收，切勿遗忘）】\n"
                           . implode("\n", $lines) . "\n";
    }

    // 故事势能摘要
    $storyMomentum   = getStoryMomentum((int)$novel['id']);
    $momentumSection = $storyMomentum
        ? "\n【当前故事势能（续写时保持张力）】\n{$storyMomentum}\n"
        : '';

    $system = <<<EOT
你是一位专业的网络小说作家，擅长创作情节紧凑、引人入胜的小说大纲。

【输出铁律（违反任何一条视为无效输出）】
1. 只输出纯 JSON 数组，不得有任何前缀、后缀、说明文字或 markdown 代码块
2. 所有字段值中不得出现未转义的双引号，如需引用书名请用【】代替引号
3. summary 控制在80字以内，key_points 每条15字以内，hook 20字以内
4. 必须输出完整的 {$count} 个对象，不得截断
5. 每章情节必须推进故事，严禁重复【全书已发生关键事件】中的任何事件
6. 所有人物的职务、身份、处境必须与【人物当前状态】完全一致
7. 如有【待回收伏笔】，应在合适章节安排自然回收，不得无限期悬置
8. 如有【上一章结尾钩子】，本批第一章的summary开头必须直接承接该悬念，不得跳过
9. 每章hook必须为下一章埋下具体可衔接的悬念，不得使用"且听下回分解"等空洞表述
10. 相邻两章之间必须有明确的因果关系，前一章的hook就是下一章开头的触发事件
11. 如有【全书故事线回顾】，本批情节必须在其基础上自然演进，不得与已发生的故事线矛盾
12. 如有【全书故事线回顾】，注意其末尾"当前故事状态"，本批第一章须从该状态继续推进
EOT;

    $user = <<<EOT
为小说《{$novel['title']}》生成第{$startChapter}章到第{$endChapter}章的大纲（共{$count}章）。

书名：{$novel['title']}  类型：{$novel['genre']}  风格：{$novel['writing_style']}
主角：{$protagonistInfo}
情节：{$plotSettings}
世界观：{$worldSettings}
其他：{$extraSettings}
{$arcSection}{$contextSection}{$prevHookSection}{$momentumSection}{$keyEventsSection}{$characterSection}{$foreshadowSection}
输出格式（严格JSON数组，共{$count}个元素）：
[{"chapter_number":整数,"title":"标题","summary":"概要","key_points":["点1","点2"],"hook":"钩子"},...]

现在直接输出JSON，从 [ 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 构建章节正文写作 Prompt（强化 System 约束 + 三层记忆注入）
 */
function buildChapterPrompt(array $novel, array $chapter, string $previousSummary, string $previousTail = ''): array {
    $system = <<<EOT
你是一位专业的网络小说作家，正在创作小说《{$novel['title']}》。

【写作铁律——违反任何一条均视为写作失败，必须重写】
1. 人物一致性：所有人物的职务、身份、生死状态必须与【人物当前状态】完全一致，不得擅自改变
2. 情节不重复：【全书已发生事件】中出现的任何事件，严禁以任何形式重演或变体重复
3. 逻辑自洽：本章发生的事件必须是前情的自然延伸，因果链条清晰，不得出现无因之果
4. 字数严格：正文总字数必须在 {$novel['chapter_words']} ±200 字范围内，不得大幅超出或不足
5. 直接开始：从"第{$chapter['chapter_number']}章 {$chapter['title']}"这一行直接开始输出正文，不要有任何前言、后记、解释或"好的，我来写"等废话
6. 风格统一：保持与前文一致的叙事视角、语气和文风，不得中途切换人称
7. 结尾钩子：章节结尾必须自然体现大纲中的"结尾钩子"，制造悬念引发读者好奇
EOT;

    // 关键情节点
    $keyPoints = '';
    if (!empty($chapter['key_points'])) {
        $pts = json_decode($chapter['key_points'], true) ?? [];
        if ($pts) $keyPoints = "\n关键情节点：\n- " . implode("\n- ", $pts);
    }
    $hookLine = !empty($chapter['hook'])
        ? "\n结尾钩子（本章结尾要体现）：{$chapter['hook']}"
        : '';

    // 弧段摘要（第二层记忆，全局历史防失忆）
    $arcChapterSection = '';
    $arcSummaries      = getArcSummaries((int)$novel['id']);
    if (!empty($arcSummaries)) {
        $arcLines = [];
        foreach ($arcSummaries as $arc) {
            if ($arc['chapter_to'] < (int)$chapter['chapter_number']) {
                $arcLines[] = "【第{$arc['chapter_from']}-{$arc['chapter_to']}章故事线】{$arc['summary']}";
            }
        }
        if (!empty($arcLines)) {
            $arcChapterSection = "【全书故事线回顾（必须与此保持一致，不得产生矛盾）】\n"
                               . implode("\n\n", $arcLines) . "\n\n";
        }
    }

    // 前情提要
    $prevSection = $previousSummary
        ? "【前情提要（前几章摘要）】\n{$previousSummary}\n\n"
        : "【说明】本章为小说第一章，请从头开始。\n\n";

    // 前文衔接
    $tailSection = $previousTail
        ? "【前文衔接（上一章结尾原文，请自然衔接，不要重复这段文字）】\n……{$previousTail}\n\n"
        : '';

    // 人物当前状态
    $characterSection = '';
    $characterStates  = getCharacterStates((int)$novel['id']);
    if (!empty($characterStates)) {
        $lines = [];
        foreach ($characterStates as $name => $state) {
            $parts = [];
            if (!empty($state['职务']))     $parts[] = "职务：{$state['职务']}";
            if (!empty($state['处境']))     $parts[] = "处境：{$state['处境']}";
            if (!empty($state['关键变化'])) $parts[] = "近况：{$state['关键变化']}";
            $lines[] = "· {$name}——" . implode('，', $parts);
        }
        $characterSection = "【人物当前状态（必须严格遵守，不得与此矛盾）】\n"
                          . implode("\n", $lines) . "\n\n";
    }

    // 全书关键事件
    $eventsSection = '';
    $keyEvents     = getKeyEvents((int)$novel['id']);
    if (!empty($keyEvents)) {
        $lines = [];
        foreach ($keyEvents as $e) {
            $lines[] = "第{$e['chapter']}章：{$e['event']}";
        }
        $eventsSection = "【全书已发生事件（严禁重写或矛盾）】\n" . implode("\n", $lines) . "\n\n";
    }

    // 近5章意象禁用
    $tropesSection = '';
    $prevTropes    = getPreviousUsedTropes((int)$novel['id'], (int)$chapter['chapter_number']);
    if (!empty($prevTropes)) {
        $tropesSection = "【本章禁止重复使用的意象/场景（近期已用，需要新鲜感）】："
                       . implode('、', $prevTropes) . "\n\n";
    }

    // 临近 deadline 的伏笔提示
    $foreshadowSection = '';
    $pendingForeshadow = getPendingForeshadowing((int)$novel['id']);
    $chNum             = (int)$chapter['chapter_number'];
    $dueForeshadow     = array_filter(
        $pendingForeshadow,
        fn($f) => !empty($f['deadline']) && $chNum >= (int)$f['deadline'] - 3
    );
    if (!empty($dueForeshadow)) {
        $lines = [];
        foreach ($dueForeshadow as $f) {
            $deadline = (int)$f['deadline'];
            $lines[]  = ($chNum >= $deadline - 2 && $chNum <= $deadline + 2)
                ? "⚠️【紧急】第{$f['chapter']}章埋：{$f['desc']}（应{$deadline}章前回收）"
                : "第{$f['chapter']}章埋：{$f['desc']}（建议{$deadline}章前回收）";
        }
        $foreshadowSection = "【本章应考虑回收的伏笔（已到期或临近，请自然安排回收）】\n"
                           . implode("\n", $lines) . "\n\n";
    }

    // 章节简介蓝图（如已生成）
    $synopsisSection = '';
    if (!empty($chapter['synopsis_id'])) {
        $synopsis = DB::fetch('SELECT * FROM chapter_synopses WHERE id=?', [$chapter['synopsis_id']]);
        if ($synopsis) {
            $synopsisSection = "【章节简介（详细写作蓝图，必须遵循）】\n简介：{$synopsis['synopsis']}\n\n";

            $sceneBreakdown = json_decode($synopsis['scene_breakdown'] ?? '[]', true);
            if (!empty($sceneBreakdown)) {
                $synopsisSection .= "场景分解：\n";
                foreach ($sceneBreakdown as $scene) {
                    $chars = implode('、', $scene['characters'] ?? []);
                    $synopsisSection .= "场景{$scene['scene']}：{$scene['location']}，人物：{$chars}，{$scene['action']}（{$scene['emotion']}）\n";
                }
                $synopsisSection .= "\n";
            }

            $dialogueBeats = json_decode($synopsis['dialogue_beats'] ?? '[]', true);
            if (!empty($dialogueBeats)) {
                $synopsisSection .= "对话要点：" . implode('；', $dialogueBeats) . "\n\n";
            }

            $sensoryDetails = json_decode($synopsis['sensory_details'] ?? '{}', true);
            if (!empty($sensoryDetails)) {
                $parts = [];
                if (!empty($sensoryDetails['visual']))     $parts[] = "视觉-{$sensoryDetails['visual']}";
                if (!empty($sensoryDetails['auditory']))   $parts[] = "听觉-{$sensoryDetails['auditory']}";
                if (!empty($sensoryDetails['atmosphere'])) $parts[] = "氛围-{$sensoryDetails['atmosphere']}";
                $synopsisSection .= "感官细节：" . implode(' ', $parts) . "\n\n";
            }

            $synopsisSection .= "节奏：{$synopsis['pacing']}  |  结尾悬念：{$synopsis['cliffhanger']}\n\n";
        }
    }

    $user = <<<EOT
{$arcChapterSection}{$prevSection}{$tailSection}{$characterSection}{$eventsSection}{$tropesSection}{$foreshadowSection}{$synopsisSection}【小说信息】
书名：{$novel['title']}  |  类型：{$novel['genre']}  |  风格：{$novel['writing_style']}
主角：{$novel['protagonist_info']}
世界观：{$novel['world_settings']}

【本章大纲】
第{$chapter['chapter_number']}章：{$chapter['title']}
概要：{$chapter['outline']}{$keyPoints}{$hookLine}

【写作要求】
1. 字数约{$novel['chapter_words']}字（±200字）
2. 人物对话自然生动，有个性，符合各自性格
3. 场景描写细腻，有画面感和代入感
4. 情节紧凑，张弛有度，不拖沓
5. 结尾自然体现大纲中的"结尾钩子"，引发读者好奇
6. 与前文衔接自然，保持语气和叙事风格一致
7. 人物职务/身份必须与上方【人物当前状态】完全一致
8. 如有【章节简介】，必须严格遵循其中的场景分解和对话要点

请开始写作：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 构建全书故事大纲 Prompt（三幕式结构）
 */
function buildStoryOutlinePrompt(array $novel): array {
    $truncate = fn(string $text, int $limit = 300): string =>
        mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…（略）' : $text;

    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 300);
    $plotSettings    = $truncate($novel['plot_settings']    ?? '', 300);
    $worldSettings   = $truncate($novel['world_settings']   ?? '', 300);
    $extraSettings   = $truncate($novel['extra_settings']   ?? '', 200);

    $targetChapters = (int)($novel['target_chapters'] ?? 100);
    $act1End = (int)($targetChapters * 0.2);
    $act2End = (int)($targetChapters * 0.8);

    $system = <<<EOT
你是一位资深的小说策划师,擅长构建完整的故事框架。
输出规则（必须严格遵守）：
1. 只输出纯JSON,不要有任何前缀、后缀或markdown代码块
2. 确保故事有清晰的开端、发展、高潮、结局
3. 每个幕的主题明确,转折点合理
4. 人物成长轨迹清晰可信
5. 所有字段值中不得出现未转义的双引号
EOT;

    $user = <<<EOT
为小说《{$novel['title']}》设计全书故事大纲。

书名：{$novel['title']}  类型：{$novel['genre']}  风格：{$novel['writing_style']}
主角：{$protagonistInfo}
情节：{$plotSettings}
世界观：{$worldSettings}
其他：{$extraSettings}
目标章数：{$targetChapters}章

请输出以下格式的JSON（只输出JSON,不要有其他文字）：
{
  "story_arc": "全书故事主线发展脉络（200字）",
  "act_division": {
    "act1": {"chapters": "1-{$act1End}", "theme": "开篇主题", "key_events": ["事件1","事件2","事件3"], "character_growth": "主角本幕成长"},
    "act2": {"chapters": "{$act1End}-{$act2End}", "theme": "发展主题", "key_events": ["事件1","事件2","事件3"], "character_growth": "主角本幕成长"},
    "act3": {"chapters": "{$act2End}-{$targetChapters}", "theme": "高潮主题", "key_events": ["事件1","事件2","事件3"], "character_growth": "主角本幕成长"}
  },
  "major_turning_points": [
    {"chapter": 章节号, "event": "转折事件描述", "impact": "对故事的影响"},
    {"chapter": 章节号, "event": "转折事件描述", "impact": "对故事的影响"}
  ],
  "character_arcs": {
    "{$novel['protagonist_name']}": {"start": "初始状态", "midpoint": "中期变化", "end": "最终状态"}
  },
  "world_evolution": "世界观如何随故事发展演变（50字）",
  "recurring_motifs": ["重复意象1", "重复意象2", "重复意象3"]
}

直接输出JSON，从 { 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 构建章节详细简介 Prompt（场景分解 + 对话要点 + 感官细节）
 */
function buildChapterSynopsisPrompt(array $novel, array $chapter, array $storyOutline): array {
    $truncate = fn(string $text, int $limit = 200): string =>
        mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;

    $actInfo         = getActInfo($storyOutline, (int)$chapter['chapter_number']);
    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 200);
    $worldSettings   = $truncate($novel['world_settings']   ?? '', 200);

    $keyPoints = '';
    if (!empty($chapter['key_points'])) {
        $pts = json_decode($chapter['key_points'], true) ?? [];
        if ($pts) $keyPoints = "\n关键情节点：\n- " . implode("\n- ", $pts);
    }

    $system = <<<EOT
你是一位小说场景设计师,擅长将章节大纲细化为可执行的写作蓝图。
输出规则（必须严格遵守）：
1. 只输出纯JSON,不要有任何前缀、后缀或markdown代码块
2. 场景分解要具体,有画面感
3. 对话要点要符合人物性格
4. 感官细节要丰富,有代入感
5. 所有字段值中不得出现未转义的双引号
EOT;

    $storyArcExcerpt = $truncate($storyOutline['story_arc'] ?? '', 150);

    $user = <<<EOT
为小说《{$novel['title']}》第{$chapter['chapter_number']}章生成详细简介。

【全书定位】
当前幕：{$actInfo['theme']}
本幕关键事件：{$actInfo['key_events']}
故事主线：{$storyArcExcerpt}

【章节大纲】
标题：{$chapter['title']}
概要：{$chapter['outline']}{$keyPoints}
钩子：{$chapter['hook']}

【小说设定】
主角：{$protagonistInfo}
风格：{$novel['writing_style']}
世界观：{$worldSettings}

请输出以下格式的JSON（只输出JSON,不要有其他文字）：
{
  "chapter_number": {$chapter['chapter_number']},
  "title": "{$chapter['title']}",
  "synopsis": "200-300字详细简介，包含场景转换、人物行动、情感变化",
  "scene_breakdown": [
    {"scene": 1, "location": "具体地点", "characters": ["人物1","人物2"], "action": "主要行动", "emotion": "情感基调", "purpose": "场景作用"}
  ],
  "dialogue_beats": ["关键对话要点1", "关键对话要点2", "关键对话要点3"],
  "sensory_details": {"visual": "视觉细节", "auditory": "听觉细节", "atmosphere": "氛围营造"},
  "pacing": "快/中/慢",
  "cliffhanger": "结尾悬念设计",
  "foreshadowing": ["埋下的伏笔1", "埋下的伏笔2"],
  "callbacks": ["呼应前文的点1", "呼应前文的点2"]
}

直接输出JSON，从 { 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}
