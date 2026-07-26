<?php
defined('APP_LOADED') or die('Direct access denied.');

class ShortStoryPromptBuilder
{
    public static function buildBriefMessages(array $story): array
    {
        $genre = $story['genre'] ?? '不限';
        $theme = $story['theme'] ?? '待定';
        $premise = $story['premise'] ?? '';
        $protagonist = $story['protagonist'] ?? '';
        $conflict = $story['conflict'] ?? '';
        $ending = $story['ending_direction'] ?? '';
        $style = $story['style'] ?? '';

        $userParts = [];
        if ($premise) $userParts[] = "核心前提：{$premise}";
        if ($protagonist) $userParts[] = "主角设定：{$protagonist}";
        if ($conflict) $userParts[] = "核心冲突：{$conflict}";
        if ($ending) $userParts[] = "结局方向：{$ending}";
        if ($style) $userParts[] = "文风偏好：{$style}";

        $userContent = implode("\n", $userParts) ?: '请根据类型和主题自由发挥。';

        return [
            [
                'role' => 'system',
                'content' => "你是一位资深短篇小说策划师。根据用户提供的小说设定，生成一份简洁的故事概要（Brief）。\n\n"
                    . "请严格以 JSON 格式输出，包含以下字段：\n"
                    . "- logline: 一句话故事（20字以内）\n"
                    . "- theme: 主题表达（一句话概括核心思想）\n"
                    . "- protagonist_goal: 主角的核心欲望/目标\n"
                    . "- obstacle: 核心阻碍（阻止主角达成目标的障碍）\n"
                    . "- turning_point: 关键转折（改变故事走向的事件）\n"
                    . "- ending_echo: 结尾回响（与开头呼应的意象或情感）\n"
                    . "- tone: 文风气质（如：冷峻/温暖/荒诞/诗意）\n\n"
                    . "只输出 JSON，不要输出任何其他内容。",
            ],
            [
                'role' => 'user',
                'content' => "类型：{$genre}\n主题：{$theme}\n\n{$userContent}",
            ],
        ];
    }

    public static function buildBeatsMessages(array $story): array
    {
        $brief = $story['brief_json'] ?? [];
        $structure = $story['structure_type'] ?? 'eight_beat';
        $targetWords = (int)($story['target_words'] ?? 3000);

        $beatCount = match ($structure) {
            'six_beat' => 6,
            'three_act' => 3,
            default => 8,
        };

        $briefText = $brief ? json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '暂无';

        return [
            [
                'role' => 'system',
                'content' => "你是一位短篇小说结构专家。根据故事概要和结构类型，生成 {$beatCount} 个叙事节拍（Beat）。\n\n"
                    . "结构类型：{$structure}（共{$beatCount}个节拍）\n"
                    . "目标字数：{$targetWords}字\n\n"
                    . "请严格以 JSON 数组格式输出，每个元素包含：\n"
                    . "- order: 序号（从1开始）\n"
                    . "- name: 节拍名称（如：开场、转折、高潮等）\n"
                    . "- purpose: 本节拍在故事中的作用\n"
                    . "- event: 具体发生的事件\n"
                    . "- emotion_shift: 情绪变化方向（如：平静→紧张）\n"
                    . "- conflict: 本节拍中的冲突\n"
                    . "- image: 画面感描述（一个核心视觉意象）\n"
                    . "- word_budget: 建议字数分配\n\n"
                    . "字数分配总和应约等于{$targetWords}字。\n"
                    . "只输出 JSON 数组，不要输出任何其他内容。",
            ],
            [
                'role' => 'user',
                'content' => "故事概要：\n{$briefText}",
            ],
        ];
    }

    public static function buildDraftMessages(array $story): array
    {
        $brief = $story['brief_json'] ?? [];
        $beats = $story['beats_json'] ?? [];
        $targetWords = (int)($story['target_words'] ?? 3000);
        $style = $story['style'] ?? '自然流畅';
        $genre = $story['genre'] ?? '';

        $briefText = $brief ? json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';
        $beatsText = $beats ? json_encode($beats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';

        $systemParts = [
            '你是一位才华横溢的短篇小说作家。请根据提供的故事概要和叙事节拍，创作一篇完整的短篇小说。',
            '',
            "目标字数：{$targetWords}字（允许±15%浮动）",
            "文风：{$style}",
        ];
        if ($genre) $systemParts[] = "类型：{$genre}";

        $systemParts[] = '';
        $systemParts[] = '要求：';
        $systemParts[] = '1. 必须有完整的开头、发展、高潮和结尾';
        $systemParts[] = '2. 不要使用章节标题，除非用户明确要求';
        $systemParts[] = '3. 严格按照节拍推进故事，每个节拍的内容和情绪要到位';
        $systemParts[] = '4. 开头要抓人，结尾要有力';
        $systemParts[] = '5. 对话要自然，避免说教';
        $systemParts[] = '6. 场景描写要有画面感';
        $systemParts[] = '7. 直接输出小说正文，不要输出任何说明或注释';

        return [
            [
                'role' => 'system',
                'content' => implode("\n", $systemParts),
            ],
            [
                'role' => 'user',
                'content' => "故事概要：\n{$briefText}\n\n叙事节拍：\n{$beatsText}\n\n请开始创作。",
            ],
        ];
    }

    public static function buildPolishMessages(array $story, string $mode, string $selection = ''): array
    {
        $content = $story['content'] ?? '';
        $style = $story['style'] ?? '自然流畅';
        $targetWords = (int)($story['target_words'] ?? 3000);

        $modeInstruction = match ($mode) {
            'full'      => '请对全文进行润色优化：提升文笔质感、增强画面感、优化节奏、消除冗余表达。',
            'ending'    => '请重写并强化结尾部分：让结尾更有力、更震撼、更有余韵，与开头形成呼应。',
            'dialogue'  => '请优化全文对话：让对话更自然、更有个性、更推动情节，避免说教式对话。',
            'opening'   => '请重写开头部分：让开头更抓人、更有悬念、更有代入感，三行之内必须出现冲突或反差。',
            'selection' => '请润色以下选中的段落：提升文笔、增强表现力、保持上下文连贯。\n\n选中内容：' . $selection,
            default     => '请对全文进行润色优化。',
        };

        return [
            [
                'role' => 'system',
                'content' => "你是一位资深文学编辑。文风偏好：{$style}\n\n"
                    . "{$modeInstruction}\n\n"
                    . "要求：\n"
                    . "1. 保持故事核心不变\n"
                    . "2. 字数控制在{$targetWords}字左右\n"
                    . "3. 直接输出润色后的完整正文，不要输出任何说明\n"
                    . "4. 不要使用章节标题",
            ],
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];
    }

    public static function buildChaptersMessages(array $story): array
    {
        $brief = $story['brief_json'] ?? [];
        $beats = $story['beats_json'] ?? [];
        $chapterCount = max(2, (int)($story['chapter_count'] ?? 2));
        $targetWords = (int)($story['target_words'] ?? 3000);
        $perChapter = (int)round($targetWords / $chapterCount);

        $briefText = $brief ? json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '暂无';
        $beatsText = $beats ? json_encode($beats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '暂无';

        return [
            [
                'role' => 'system',
                'content' => "你是一位短篇小说结构专家。根据故事概要和叙事节拍,把整篇故事切分为 {$chapterCount} 个章节。\n\n"
                    . "目标总字数:{$targetWords}字\n"
                    . "章节数:{$chapterCount}\n"
                    . "每章约:{$perChapter}字\n\n"
                    . "请严格以 JSON 数组格式输出,每个元素包含:\n"
                    . "- order: 章节序号(从1开始)\n"
                    . "- title: 章节标题(4-10字,有画面感,不要写'第X章')\n"
                    . "- synopsis: 本章梗概(80-150字,说明本章发生了什么、情绪走向、关键转折)\n"
                    . "- beat_refs: 本章覆盖的节拍 order 数组,如 [1,2]\n"
                    . "- word_budget: 本章字数预算(总和约等于{$targetWords})\n\n"
                    . "要求:\n"
                    . "1. 章节之间叙事连贯,情节推进合理\n"
                    . "2. 必须完整覆盖所有节拍,不要遗漏\n"
                    . "3. 高潮节拍单独成章或与转折合章\n"
                    . "4. 只输出 JSON 数组,不要输出任何其他内容",
            ],
            [
                'role' => 'user',
                'content' => "故事概要:\n{$briefText}\n\n叙事节拍:\n{$beatsText}",
            ],
        ];
    }

    public static function buildChapterDraftMessages(array $story, array $chapter, array $allChapters, array $prevTails): array
    {
        $brief = $story['brief_json'] ?? [];
        $beats = $story['beats_json'] ?? [];
        $style = $story['style'] ?? '自然流畅';
        $genre = $story['genre'] ?? '';

        $order = (int)($chapter['order'] ?? 1);
        $title = (string)($chapter['title'] ?? '');
        $synopsis = (string)($chapter['synopsis'] ?? '');
        $wordBudget = (int)($chapter['word_budget'] ?? 3000);
        $beatRefs = $chapter['beat_refs'] ?? [];

        $relatedBeats = [];
        if ($beats && $beatRefs) {
            $beatRefsSet = array_flip(array_map('intval', $beatRefs));
            foreach ($beats as $b) {
                if (isset($beatRefsSet[(int)($b['order'] ?? 0)])) {
                    $relatedBeats[] = $b;
                }
            }
        }

        $briefText = $brief ? json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';
        $relatedBeatsText = $relatedBeats ? json_encode($relatedBeats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '无';

        $outlineLines = [];
        foreach ($allChapters as $c) {
            $o = (int)($c['order'] ?? 0);
            $t = (string)($c['title'] ?? '');
            $s = (string)($c['synopsis'] ?? '');
            $outlineLines[] = "第{$o}章 {$t}:{$s}";
        }
        $outlineText = implode("\n", $outlineLines);

        $prevText = '（本章为首章,无前文）';
        if (!empty($prevTails)) {
            $segs = [];
            foreach ($prevTails as $p) {
                $segs[] = "【第{$p['order']}章「{$p['title']}」结尾】\n{$p['tail']}";
            }
            $prevText = implode("\n\n", $segs);
        }

        $systemParts = [
            '你是一位才华横溢的短篇小说作家。请写出短篇小说的指定章节。',
            '',
            "本章目标字数:约{$wordBudget}字(允许±20%浮动)",
            "文风:{$style}",
        ];
        if ($genre) $systemParts[] = "类型:{$genre}";
        $systemParts[] = '';
        $systemParts[] = '要求:';
        $systemParts[] = '1. 只写本章内容,不要预先展开后续章节的情节';
        $systemParts[] = '2. 严格按照本章梗概和应覆盖的节拍推进';
        $systemParts[] = '3. 与前文衔接自然,情绪和场景接得上,不要无端跳跃';
        $systemParts[] = '4. 不要在正文里写"第X章"或重复章节标题';
        $systemParts[] = '5. 直接输出本章正文,不要输出任何说明、标题、注释';
        $systemParts[] = '6. 对话自然,场景有画面感';

        return [
            [
                'role' => 'system',
                'content' => implode("\n", $systemParts),
            ],
            [
                'role' => 'user',
                'content' => "故事概要:\n{$briefText}\n\n"
                    . "整体章节大纲:\n{$outlineText}\n\n"
                    . "前文衔接:\n{$prevText}\n\n"
                    . "—— 本章任务 ——\n"
                    . "第{$order}章「{$title}」\n"
                    . "本章梗概:{$synopsis}\n\n"
                    . "本章应覆盖的节拍:\n{$relatedBeatsText}\n\n"
                    . "请直接开始写本章正文。",
            ],
        ];
    }

    public static function buildTitleMessages(array $story): array
    {
        $content = $story['content'] ?? '';
        $genre = $story['genre'] ?? '';
        $brief = $story['brief_json'] ?? [];

        $briefText = '';
        if ($brief) {
            $briefText = '故事概要：' . ($brief['logline'] ?? '') . "\n";
        }

        return [
            [
                'role' => 'system',
                'content' => "你是一位擅长取标题的文学编辑。根据小说内容，生成5个备选标题。\n\n"
                    . "要求：\n"
                    . "1. 标题要有文学感、有悬念、有画面感\n"
                    . "2. 避免俗套和过度煽情\n"
                    . "3. 适合{$genre}类型的短篇小说\n"
                    . "4. 标题长度2-8个字\n"
                    . "5. 严格以 JSON 数组格式输出，如：[\"标题1\",\"标题2\",\"标题3\",\"标题4\",\"标题5\"]\n"
                    . "6. 只输出 JSON 数组，不要输出任何其他内容",
            ],
            [
                'role' => 'user',
                'content' => "{$briefText}小说正文（节选）：\n" . mb_substr($content, 0, 2000),
            ],
        ];
    }
}
