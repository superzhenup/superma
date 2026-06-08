<?php
/**
 * ChapterPromptBuilder — 章节正文写作 Prompt 构建器
 *
 * 将原 buildChapterPrompt() 497 行巨型函数拆分为独立可测试的段落方法。
 * 每个 build*() 方法对应 prompt 中的一个语义段落。
 *
 * @see audit: Super-Ma 全面审计与v1.4路线图.md §2.3 #7
 */
defined('APP_LOADED') or die('Direct access denied.');

class ChapterPromptBuilder
{
    private int    $chNum;
    private string $genre;
    private ?array $resolverResult = null;
    private ?array $progressCache = null;

    public function __construct(
        private array  $novel,
        private array  $chapter,
        private string $previousSummary,
        private string $previousTail = '',
        private ?array $memoryCtx = null,
    ) {
        $this->chNum = (int)($this->chapter['chapter_number'] ?? $this->chapter['chapter'] ?? 0);
        $this->genre = $this->novel['genre'] ?? '';
    }

    public function setResolverResult(array $result): void
    {
        $this->resolverResult = $result;
    }

    /** @return array{0: array{role:string,content:string}, 1: array{role:string,content:string}} */
    public function build(): array
    {
        return [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user',   'content' => $this->buildUserPrompt()],
        ];
    }

    // ==============================
    //  SYSTEM PROMPT
    // ==============================

    // v1.6 P1#8: prompt token 优化——教育型段落仅在卷首注入
    // goldenThreeLines/emotionVocabulary/dialogueStyle 是"教学"内容，AI 已内化
    // fourSegmentRhythm 含动态阶段比例，保留在所有章
    // v1.11.8: 新增中段重申（midpoint），每卷中段注入简化教学
    public function buildSystemPrompt(): string
    {
        $blocks = array_map('trim', [
            $this->systemRole(),
            $this->ironRules(),
        ]);

        // v1.11.8: 用 getEducationType() 区分完整教学和中段重申
        $eduType = $this->getEducationType();
        if ($eduType === 'full') {
            // 完整教学：卷首章或第1章
            $blocks[] = trim($this->goldenThreeLines());
            $blocks[] = trim($this->emotionVocabulary());
            $blocks[] = trim($this->dialogueStyle());
        } elseif ($eduType === 'midpoint') {
            // v1.11.8: 中段重申（约200字简化教学）
            $blocks[] = trim($this->buildMidpointReminder());
        } else {
            // 非教育章仅一行提醒，节省 ~500 字 prompt token
            $blocks[] = '【风格延续】保持卷首章已建立的黄金三行/四段式/情绪密度/对话风格。';
        }

        // v2: System Prompt 按章节位置渐进减量
        // v41: fourSegmentRhythm 含「每章不同的动态比例」，已移至用户消息尾部，
        // 否则它会让 system prompt 每章都变、击穿提示词前缀缓存。
        $tier = $this->getSystemPromptTier();

        if ($tier === 'full' || $tier === 'compact') {
            $blocks[] = trim($this->hookGuidance());      // 完整钩子指南
            $blocks[] = trim($this->densityStandards());  // 完整描写密度指南
        } else {
            // minimal: 第21章+非教育章，极度精简（~80 token）
            $blocks[] = '【钩子】章末必须有钩子（悬念/反转/信息爆炸），禁止连续重复前三章的钩子类型。';
            $blocks[] = '【密度】保持适当描写密度，禁止AI高频词（深邃、凝视、缓缓、蓦然、骤然）。';
        }

        return implode("\n\n", $blocks);
    }

    /**
     * v1.5.2: 判断当前章是否为"教育章"——需要注入完整教学型规则
     * v1.11.8: 新增中段重申类型，每卷中段（第25章左右）注入简化教学
     *
     * 触发条件（任一满足）：
     * 1. 第 1 章（无条件）-> 返回 'full'
     * 2. 卷首章（volume_outlines 中某卷的 start_chapter）-> 返回 'full'
     * 3. 卷中段章（每卷大约中间位置）-> 返回 'midpoint'
     * 4. 兜底降级：若小说没有任何 volume_outlines 数据，每 20 章重申一次教学
     *    （否则非首章永远走精简路径，AI 会逐渐偏离教学规则）
     *
     * @return string|null 'full'=完整教学, 'midpoint'=中段重申, null=无需教学
     */
    private function getEducationType(): ?string
    {
        if ($this->chNum === 1) return 'full';

        // 优先：volume_outlines 卷首
        if ($this->isVolumeStartChapter()) return 'full';

        // v1.11.8: 检测是否为卷中段章
        if ($this->isVolumeMidpointChapter()) return 'midpoint';

        // 兜底：无卷数据时，每 20 章重申一次（章 21、41、61...）
        try {
            $hasVolumes = \DB::fetch(
                'SELECT 1 FROM volume_outlines WHERE novel_id=? LIMIT 1',
                [(int)$this->novel['id']]
            );
            if (!$hasVolumes && $this->chNum > 1 && (($this->chNum - 1) % 20 === 0)) {
                return 'full';
            }
        } catch (\Throwable $e) {
            // 查询失败时保守返回 null（精简模式）
        }

        return null;
    }

    /**
     * v2: System Prompt 渐进减量层级
     *
     * full    (ch 1-5)  : 完整教学型 system prompt
     * compact (ch 6-20) : 保留钩子指南+密度标准，裁撤教学段落
     * minimal (ch 21+)  : 仅铁律+节奏+一行钩子/密度提醒
     */
    private function getSystemPromptTier(): string
    {
        if ($this->chNum <= 5) return 'full';
        if ($this->chNum <= 20) return 'compact';
        return 'minimal';
    }

    /**
     * v1.11.8: 兼容旧调用，返回 bool
     */
    private function isEducationChapter(): bool
    {
        return $this->getEducationType() !== null;
    }

    /**
     * v1.11.8: 判断当前章是否为卷中段章
     * 卷中段 = chapter_number 在某卷的 start_chapter 和 end_chapter 之间，且接近中间位置
     */
    private function isVolumeMidpointChapter(): bool
    {
        try {
            $volume = \DB::fetch(
                'SELECT start_chapter, end_chapter FROM volume_outlines WHERE novel_id=? AND start_chapter <= ? AND end_chapter >= ? LIMIT 1',
                [(int)$this->novel['id'], $this->chNum, $this->chNum]
            );
            if (!$volume) return false;

            $start = (int)$volume['start_chapter'];
            $end = (int)$volume['end_chapter'];
            $midpoint = (int)(($start + $end) / 2);

            // 在中点前后 2 章范围内触发（如卷为1-50章，中点为25章，则23-27章都会触发）
            return abs($this->chNum - $midpoint) <= 2;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * v1.11.8: 生成中段简化教学段落
     * 约 200 字摘要，提醒 AI 保持风格一致性
     */
    private function buildMidpointReminder(): string
    {
        return <<<EOT
【风格中段重申】
本卷已写过数章，请确认以下核心要求：
1. 黄金三行：开篇 50 字内必须出现冲突/钩子/反差，禁止纯环境描写开头
2. 对话风格：每个角色的称呼/语气词/句式需有明显区分（参考人物语音规则）
3. 情绪密度：每 1000 字至少 3 个情绪锚点，高潮段落密度翻倍
4. 章末钩子：必须设置悬念/反转/信息爆炸，让读者无法放下
5. 四段式结构：铺垫→发展→高潮→钩子，比例按节奏指令执行
⚠️ 禁止使用AI高频词（深邃、凝视、缓缓、蓦然、骤然、指节泛白等）
EOT;
    }

    /**
     * v1.6 P1#8: 判断当前章是否为卷首章
     * 卷首章 = chapter_number 等于某卷的 start_chapter
     */
    private function isVolumeStartChapter(): bool
    {
        try {
            $exists = \DB::fetch(
                'SELECT 1 FROM volume_outlines WHERE novel_id=? AND start_chapter=? LIMIT 1',
                [(int)$this->novel['id'], $this->chNum]
            );
            return !empty($exists);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function systemRole(): string
    {
        return "你是一位专业的网络小说作家，正在创作小说《{$this->novel['title']}》。";
    }

    public function ironRules(): string
    {
        $targetWords = (int)$this->novel['chapter_words'];
        $tol          = calculateDynamicTolerance($targetWords);
        $minWords     = $tol['min'];
        $maxWords     = $tol['max'];
        $earlyFinish  = $tol['early_finish'];
        $warnings     = generateWordCountWarnings($targetWords);
        $protagonistName = $this->novel['protagonist_name'] ?? '';
        $protagonistRule = $protagonistName
            ? "\n9. 主角名锚定：本小说主角固定为「{$protagonistName}」，绝对不可更改、替换、省略或使用其他称呼作为主角，所有涉及主角的内容必须使用此名字"
            : '';

        // v41: 场景连续性约束已移至用户消息动态区（buildUserPrompt），
        // 使 system prompt 在同一 tier 内逐字节稳定 → 命中提示词前缀缓存。

        return <<<EOT
【写作铁律 · 三级优先级——P0必须100%遵守，P1重点执行，P2参考执行】

── P0 核心铁律（违反即废稿）──
① 字数：目标 {$targetWords} 字，弹性区间 {$minWords}~{$maxWords} 字。情节自然完整优先，禁止凑字/硬切/强行收尾。到达 {$maxWords} 字必须停笔。
② 人物锚定：所有角色的职务、身份、生死、境界必须与【人物当前状态】完全一致，禁止擅自改变。{$protagonistRule}
③ 直接输出：从本章标题行直接开始正文，禁止任何前言/后记/解释/"好的我来写"等废话。

── P1 强化约束（重点执行）──
④ 逻辑自洽：本章事件必须是前情的自然延伸，因果链清晰，不得出现无因之果。
⑤ 情节不重复：【全书已发生事件】中的任何事件，严禁以任何形式重演或变体重复。
⑥ 场景连续：开场场景必须承接上章结尾，场景切换必须有转场描写，禁止无过渡跳跃。
⑦ 风格统一：保持与前文一致的叙事视角、语气和文风，不得中途切换人称。

── P2 参考指引（选择性遵守）──
⑧ 字数节奏参考：写到约 {$earlyFinish} 字时进入钩子收尾；开头黄金三行直接抓人；中间对话与动作交替推进。
⑨ 字数预警（心中估算，不必精确）：
{$warnings}
EOT;
    }

    /**
     * v41: 场景连续性约束的用户消息包装（带段落间距）。
     * 从 ironRules 移出后，作为动态区独立段落注入，保证 system prompt 可缓存。
     */
    private function buildLocationSection(): string
    {
        $r = $this->buildLocationRule();
        return $r === '' ? '' : ltrim($r) . "\n\n";
    }

    /**
     * v41 钩子回收强化：注入上一章的章末钩子，强约束本章开头正面回应它。
     * 此前钩子是"开环"的（只埋不验证），这里把上章悬念明确点出，要求本章前 1/3 回收。
     */
    private function buildPrevHookSection(): string
    {
        if (!getSystemSetting('ws_hook_payoff_enabled', true, 'bool')) return '';
        if ($this->chNum <= 1) return '';
        try {
            require_once __DIR__ . '/prompt.php';
            $prev = \DB::fetch(
                "SELECT hook, hook_type FROM chapters
                 WHERE novel_id=? AND chapter_number=? AND status='completed' LIMIT 1",
                [(int)$this->novel['id'], $this->chNum - 1]
            );
        } catch (\Throwable $e) { return ''; }
        $hook = trim((string)($prev['hook'] ?? ''));
        if ($hook === '') return '';
        $typeName = '';
        $ht = trim((string)($prev['hook_type'] ?? ''));
        if ($ht !== '' && defined('HOOK_TYPES') && isset(HOOK_TYPES[$ht]['name'])) {
            $typeName = '（' . HOOK_TYPES[$ht]['name'] . '）';
        }
        $prevNum = $this->chNum - 1;
        return "【⚠️ 上章钩子待回收{$typeName}】\n"
            . "上一章（第{$prevNum}章）结尾抛出的悬念：「{$hook}」\n"
            . "本章必须在前 1/3 内正面回应/推进它——给出后续、揭示真相、或将其升级为新冲突；"
            . "禁止无视、跳开另起或拖到很久以后。\n\n";
    }

    /**
     * v1.12: 构建场景连续性规则
     * 解决"主角在村里突然看到市区街边"的场景跳跃问题
     */
    private function buildLocationRule(): string
    {
        $currentLocation = $this->memoryCtx['current_location'] ?? '';
        $locationTransition = $this->memoryCtx['location_transition'] ?? '';
        $locationChapter = $this->memoryCtx['location_chapter'] ?? null;

        if ($currentLocation === '') {
            return '';
        }

        $lines = [];
        $lines[] = "\n\n【📍 场景连续性约束】";
        $lines[] = "主角当前位置：{$currentLocation}";
        $lines[] = "位置来源：第{$locationChapter}章";

        if ($locationTransition !== '') {
            $lines[] = "到达方式：{$locationTransition}";
        }

        $lines[] = "";
        $lines[] = "⚠️ 场景切换规则：";
        $lines[] = "  1. 本章开场必须从「{$currentLocation}」开始（或明确延续该场景）";
        $lines[] = "  2. 如需切换场景，必须写出转场过程（行走、传送、飞行等）";
        $lines[] = "  3. 禁止场景跳跃：上一段在A地，下一段突然在B地";
        $lines[] = "  4. 转场模板：动作触发 → 离开当前地点 → 路途描写 → 到达新地点";

        return implode("\n", $lines);
    }

    public function goldenThreeLines(): string
    {
        return <<<EOT
【🔥 黄金三行——本章前三行必须满足以下至少一条】
A. 悬念引导型：反常现象/危机爆发 → 主角处境 → 读者追问"接下来怎么办"
B. 场景代入型：强画面感场景 → 主角感官体验 → 即将发生的变故
C. 动作切入型：战斗/对抗正在进行 → 主角的险境 → 翻盘契机
D. 对话切入型：关键对话 → 冲突暴露 → 行动决定
⚠️ 禁忌：前三行内不得出现超过半行的纯环境/天气/风景描写！
EOT;
    }

    public function fourSegmentRhythm(): string
    {
        $r = $this->getSegmentRatios();
        return <<<EOT
【📊 四段式节奏结构】
- 铺垫段(~{$r['setup']}%)：承接上文、建立场景、引入新信息（≤200字纯环境描写）
- 发展段(~{$r['rising']}%)：推进冲突、角色互动、信息揭露（对话密集区）
- 高潮段(~{$r['climax']}%)：爽点释放、情绪顶点、反转或冲突升级
- 钩子段(~{$r['hook']}%)：使用指定钩子类型收尾，制造强烈悬念
⚠️ 以上四段结构仅为内部节奏参考，绝对禁止在正文中输出"铺垫段""发展段""高潮段""钩子段"等段落标题或标记！正文需连续叙述，段落间自然过渡，不得出现任何结构化标注。
EOT;
    }

    /**
     * v1.5: segment_ratios 单一来源
     * 优先使用 RhythmAdjuster 根据章节进度计算的动态比例（climax 期 15/30/40/15 等），
     * RhythmAdjuster 不可用时回退到全局静态配置 ws_segment_ratio_*
     * 解决双源冲突：之前 fourSegmentRhythm 读静态、buildRhythmSection 输出动态，AI 看到矛盾。
     *
     * @return array{setup:int,rising:int,climax:int,hook:int}
     */
    private function getSegmentRatios(): array
    {
        // 优先：RhythmAdjuster 动态比例（按章节进度阶段计算）
        try {
            require_once __DIR__ . '/rhythm_adjuster.php';
            $adj = new RhythmAdjuster((int)$this->novel['id']);
            $rhythm = $adj->calculateRhythm($this->chNum, []);
            if (!empty($rhythm['segment_ratios'])) {
                $base = [
                    'setup'  => (int)($rhythm['segment_ratios']['setup']  ?? 20),
                    'rising' => (int)($rhythm['segment_ratios']['rising'] ?? 30),
                    'climax' => (int)($rhythm['segment_ratios']['climax'] ?? 35),
                    'hook'   => (int)($rhythm['segment_ratios']['hook']   ?? 15),
                ];
                try {
                    require_once __DIR__ . '/ChapterTypeDetector.php';
                    return ChapterTypeDetector::adjustRatios($base, trim((string)($this->chapter['outline'] ?? '')));
                } catch (\Throwable) {}
                return $base;
            }
        } catch (\Throwable $e) {
            // 回退到静态配置
        }

        $fallback = [
            'setup'  => (int)getSystemSetting('ws_segment_ratio_setup',  20, 'int'),
            'rising' => (int)getSystemSetting('ws_segment_ratio_rising', 30, 'int'),
            'climax' => (int)getSystemSetting('ws_segment_ratio_climax', 35, 'int'),
            'hook'   => (int)getSystemSetting('ws_segment_ratio_hook',   15, 'int'),
        ];

        try {
            require_once __DIR__ . '/ChapterTypeDetector.php';
            return ChapterTypeDetector::adjustRatios($fallback, trim((string)($this->chapter['outline'] ?? '')));
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function hookGuidance(): string
    {
        require_once __DIR__ . '/prompt.php';
        $outlineHookType = trim($this->chapter['hook_type'] ?? '');
        if ($outlineHookType !== '' && isset(HOOK_TYPES[$outlineHookType])) {
            $cType = $outlineHookType;
            $cDesc = HOOK_TYPES[$cType]['name'] . '：' . HOOK_TYPES[$cType]['desc'] . '（大纲已规划）';
        } else {
            $hook  = suggestHookType($this->chapter);
            $cType = $hook['type'];
            $cDesc = (HOOK_TYPES[$cType]['name'] ?? '') . '：' . ($hook['reason'] ?? '默认轮换');
        }
        return <<<EOT
【🎣 章末钩子——必须使用以下指定类型】
本章节尾钩子类型：{$cType}
类型说明：{$cDesc}
⚠️ 绝对禁止以平静句结尾！（如"大家都睡了""夜深了""一切归于平静"等）
EOT;
    }

    public function densityStandards(): string
    {
        $d = getDensityGuidelines($this->genre);
        return "【📏 描写密度标准——题材：{$this->genre}】\n{$d}";
    }

    public function emotionVocabulary(): string
    {
        $pacing = trim($this->chapter['pacing'] ?? '中');
        $multiplier = match($pacing) {
            '快' => 1.3,
            '慢' => 0.7,
            default => 1.0,
        };

        $angry  = (int)(15 * $multiplier) . '-' . (int)(20 * $multiplier);
        $joy    = (int)(20 * $multiplier) . '-' . (int)(30 * $multiplier);
        $surp   = (int)(10 * $multiplier) . '-' . (int)(15 * $multiplier);
        $fear   = (int)(5 * $multiplier) . '-' . (int)(10 * $multiplier);
        $sad    = (int)(5 * $multiplier) . '-' . (int)(10 * $multiplier);

        $phaseNote = $pacing === '快'
            ? '本章为快节奏章，情绪词频需提高，高潮段密度翻倍'
            : ($pacing === '慢'
                ? '本章为慢节奏/铺垫章，情绪词频适当降低，以细腻描写和氛围营造为主'
                : '正常节奏，情绪词频按标准执行');

        return <<<EOT
【😊 情绪词汇要求——基于1590本小说分析】
· 愤怒类：{$angry}次/万字（愤怒、怒火、暴怒、咬牙切齿、火冒三丈）
· 喜悦类：{$joy}次/万字（喜悦、高兴、兴奋、狂喜、心花怒放）
· 惊讶类：{$surp}次/万字（惊讶、震惊、不可思议、目瞪口呆）
· 恐惧类：{$fear}次/万字（恐惧、害怕、战栗、毛骨悚然）
· 悲伤类：{$sad}次/万字（悲伤、悲痛、心碎、黯然神伤）
调整说明：{$phaseNote}
原则：1.自然融入不堆砌 2.有铺垫和释放 3.有起伏变化 4.高潮加大密度 5.类型的情绪特色可微调

【🎭 表情/神态展示——"Show, Don't Tell"】
角色情绪必须通过外在表现来呈现，禁止直接陈述：
· 微表情：瞳孔骤缩、嘴角微扬、眉梢轻挑、眼底闪过一丝寒意、嘴角抽了抽
· 神态：脸色煞白、双目赤红、面如死灰、神情恍惚、一脸茫然
· 肢体：指节泛白（紧握）、浑身发颤、踉跄后退、双拳攥紧、脊背绷直
· 生理反应：喉结滚动、冷汗涔涔、呼吸急促、心跳如鼓、眼眶泛红
· 禁止写法："他很愤怒""她感到高兴""他心中恐惧"（直接陈述情感）
· 正确写法："他额角青筋暴起，拳头捏得咯咯作响""她眼睛亮了一下，随即垂下睫毛掩住笑意"
EOT;
    }

    public function dialogueStyle(): string
    {
        return <<<EOT
【💬 对话与文风】
- 对话密度目标：每千字40-80句对话（都市类可更高至60-100）
- 连续非对话文字不得超过300字（含描写+心理+叙述），超过时必须插入对话或动作打断
- 平均段落150-300字，平均句长20-40字
- 多用短句推进情节，少用长句堆砌描写
EOT;
    }

    // ==============================
    //  USER PROMPT
    // ==============================

    public function buildUserPrompt(): string
    {
        // v1.5 段落重排：按 LLM U 型注意力规律
        // - 强约束放头尾（开头记得最牢、结尾刚读过最影响）
        // - 弱信息放中间（被压缩的位置）
        // - Agent 指令和"请开始写作"放最末（动态、最高优先级）

        // v41: 缓存友好布局开关——把静态背景段前置成「稳定前缀」，使提示词前缀缓存
        //      从 system message 延伸进 user message；动态内容仍按 U 型排在后面。
        $cacheLayout = (bool)getSystemSetting('ws_cache_prompt_layout', true, 'bool');

        if ($cacheLayout) {
            // ── 稳定前缀（整本基本不变 / 每~10章才变 → 命中缓存）──
            $prefix = implode('', array_filter([
                $this->buildNovelInfo(),                // 小说信息（静态）
                $this->buildStoryOutlineSection(),      // 全书三幕结构（静态）
                $this->buildBibleSection(),             // v41: 全书圣经（每N章变，命中缓存）
                $this->buildAuthorProfileSection(),     // 作者画像（静态）
                $this->buildReaderProfileSection(),     // 目标读者画像（静态）
                $this->buildArcChapterSection(),        // 全书故事线（每10章变）
                $this->buildVoiceProfileSection(),      // 角色语音规则（半静态）
                $this->buildRecurringMotifsSection(),   // 全书重复意象（半静态）
                $this->buildStyleExemplarSection(),     // v41: 文风范本（本书高分片段，show not tell）
                $this->buildUserDensitySection(),       // 描写密度（静态规则）
                $this->buildPOVSection(),               // POV 视角约束（静态）
            ]));
            // ── 动态头：本章关键信息（强注意）──
            $dynHead = implode('', array_filter([
                $this->buildQualityFeedbackSection(),
                $this->buildCharacterSection(),
                $this->buildLocationSection(),
                $this->buildPrevHookSection(),          // v41: 上章钩子待回收（强约束本章开头承接）
                $this->buildForeshadowSection(),
                $this->buildOutlineSection(),           // 本章大纲
                $this->buildSceneAtmosphereSection(),   // 场景意境指引
                $this->buildChapterTypeSection(),       // 章节类型识别与节奏差异
                $this->buildCausalChainSection(),       // 因果链预警
                $this->buildPreWriteChecklist(),
                $this->buildSynopsisSection(),
            ]));
            // ── 动态中部（弱注意区，可被裁剪）──
            $middle = implode('', array_filter([
                $this->buildPrevSection(),
                $this->buildRecentChapterSection(),
                $this->buildTailSection(),
                $this->buildMomentumSection(),
                $this->buildCognitiveLoadSection(),
                $this->buildEmotionStateSection(),
                $this->buildEventsSection(),
                $this->buildSemanticSection(),
                $this->buildKBContextSection(),
                $this->buildCatchphraseSection(),
                $this->buildHookHistorySection(),
                $this->buildCoolPointHistorySection(),
                $this->buildTropesSection(),
                $this->buildVolumeGoalSection(),
                $this->buildProgressSection(),
                $this->buildEndingContextSection(),
            ]));
            $head = $prefix . $dynHead;
            // ── 动态尾（强 recency）──
            $tail = implode('', array_filter([
                $this->fourSegmentRhythm() . "\n\n",
                $this->buildRhythmSection(),
                $this->buildUserHookSection(),
                $this->buildEndingSection(),
                $this->buildUserRulesSection(),         // 字数/主角名锚定（保留在尾部吃 recency）
            ]));
        } else {
            // ── 旧 U 型布局（回退用，ws_cache_prompt_layout=0）──
            $head = implode('', array_filter([
                $this->buildQualityFeedbackSection(),
                $this->buildAuthorProfileSection(),
                $this->buildReaderProfileSection(),
                $this->buildCharacterSection(),
                $this->buildLocationSection(),
                $this->buildPrevHookSection(),          // v41: 上章钩子待回收
                $this->buildVoiceProfileSection(),
                $this->buildForeshadowSection(),
                $this->buildOutlineSection(),
                $this->buildSceneAtmosphereSection(),   // 场景意境指引
                $this->buildChapterTypeSection(),       // 章节类型识别与节奏差异
                $this->buildCausalChainSection(),       // 因果链预警
                $this->buildPreWriteChecklist(),
                $this->buildSynopsisSection(),
            ]));
            $middle = implode('', array_filter([
                $this->buildNovelInfo(),
                $this->buildArcChapterSection(),
                $this->buildStoryOutlineSection(),
                $this->buildPrevSection(),
                $this->buildRecentChapterSection(),
                $this->buildTailSection(),
                $this->buildMomentumSection(),
                $this->buildStyleExemplarSection(),     // v41: 文风范本
                $this->buildUserDensitySection(),
                $this->buildPOVSection(),
                $this->buildCognitiveLoadSection(),
                $this->buildEmotionStateSection(),
                $this->buildEventsSection(),
                $this->buildSemanticSection(),
                $this->buildKBContextSection(),
                $this->buildCatchphraseSection(),
                $this->buildHookHistorySection(),
                $this->buildCoolPointHistorySection(),
                $this->buildRecurringMotifsSection(),
                $this->buildTropesSection(),
                $this->buildVolumeGoalSection(),
                $this->buildProgressSection(),
                $this->buildEndingContextSection(),
            ]));
            $tail = implode('', array_filter([
                $this->fourSegmentRhythm() . "\n\n",
                $this->buildRhythmSection(),
                $this->buildUserHookSection(),
                $this->buildEndingSection(),
                $this->buildUserRulesSection(),
            ]));
        }

        // 注意：Agent 指令必须在"请开始写作"之前——LLM 读到启动指令后会忽略其后内容
        // tailPart = 尾部强约束 + Agent 指令 + 启动指令，必须完整保留（U 型注意力尾权重最高）
        $tailPart = $tail . $this->buildAgentSection() . "\n请开始写作：\n";

        $maxPromptLen = max(8000, (int)getSystemSetting('ws_max_prompt_len', 40000, 'int'));
        // 超长时只裁剪"中部"（弱注意区），永不动 head 与 tailPart，
        // 确保大纲、节奏/钩子/铁律、"请开始写作" 不被切掉。
        $fixedLen = mb_strlen($head) + mb_strlen($tailPart);
        if ($fixedLen + mb_strlen($middle) > $maxPromptLen) {
            $middleBudget = max(0, $maxPromptLen - $fixedLen);
            $origMiddleLen = mb_strlen($middle);
            if ($origMiddleLen > $middleBudget) {
                $marker = "\n【上下文过长，已省略部分参考信息】\n";
                $keep = max(0, $middleBudget - mb_strlen($marker));
                $cut = $origMiddleLen - $keep;
                $middle = $keep > 0 ? (mb_substr($middle, 0, $keep) . $marker) : '';
                if ($fixedLen > $maxPromptLen) {
                    addLog((int)$this->novel['id'], 'warn', "prompt 过长：head+尾部强约束已达 {$fixedLen} 字（超 {$maxPromptLen}），中部已全部省略，请精简上下文配置");
                } else {
                    addLog((int)$this->novel['id'], 'warn', "prompt 过长：中部已裁剪 {$cut} 字（head/大纲/尾部指令完整保留）");
                }
            }
        } elseif ($fixedLen + mb_strlen($middle) > 15000) {
            addLog((int)$this->novel['id'], 'warn', "prompt 偏长：" . ($fixedLen + mb_strlen($middle)) . " 字，建议检查上下文配置");
        }

        $userPrompt = $head . $middle . $tailPart;

        return $userPrompt;
    }

    // ── Memory 上下文段落 ────────────────────────────────────────

    private function buildArcChapterSection(): string
    {
        $arcSums = $this->memoryCtx['L2_arc_summaries'] ?? $this->memoryCtx['arc_summaries'] ?? [];
        if (empty($arcSums)) return '';
        $lines = [];
        foreach ($arcSums as $arc) {
            if ((int)$arc['chapter_to'] < $this->chNum) {
                $lines[] = "【第{$arc['chapter_from']}-{$arc['chapter_to']}章故事线】{$arc['summary']}";
            }
        }
        return $lines ? "【全书故事线回顾（必须与此保持一致，不得产生矛盾）】\n" . implode("\n\n", $lines) . "\n\n" : '';
    }

    private function buildPrevSection(): string
    {
        return $this->previousSummary
            ? "【前情提要（前几章摘要）】\n{$this->previousSummary}\n\n"
            : "【说明】本章为小说第一章，请从头开始。\n\n";
    }

    private function buildRecentChapterSection(): string
    {
        $recents = $this->memoryCtx['L3_recent_chapters'] ?? [];
        if (empty($recents)) return '';

        // v1.11.8: 明确只展示前5章（不包含本章），避免与 OutlineSection 重叠
        $lines = [];
        $count = 0;
        foreach ($recents as $rc) {
            $rn = (int)($rc['chapter_number'] ?? $rc['chapter'] ?? 0);
            // 跳过本章（如果 memoryCtx 中包含本章）
            if ($rn >= $this->chNum) continue;

            $t  = $rc['title'] ?? '';
            $o  = safe_substr(trim($rc['outline'] ?? ''), 0, 100);
            $h  = !empty($rc['hook']) ? "  →钩子：{$rc['hook']}" : '';
            // 开篇五式（如有）
            $op = !empty($rc['opening_type']) ? "  [{$rc['opening_type']}式开篇]" : '';
            // 情绪分（如有，低分标红）
            $emo = '';
            if (isset($rc['emotion_score']) && $rc['emotion_score'] !== null) {
                $es = (float)$rc['emotion_score'];
                $emo = $es < 60 ? "  ⚠️情绪{$es}分" : '';
            }
            $lines[] = "第{$rn}章《{$t}》：{$o}{$h}{$op}{$emo}";
            $count++;
            if ($count >= 5) break; // 最多5章
        }

        if (empty($lines)) return '';
        return "【近章大纲（前5章结构参考，保持连贯）】\n" . implode("\n", $lines) . "\n\n";
    }

    private function buildTailSection(): string
    {
        return $this->previousTail
            ? "【前文衔接（上一章结尾原文，请自然衔接，不要重复这段文字）】\n……{$this->previousTail}\n\n"
            : '';
    }

    private function buildCharacterSection(): string
    {
        $states = $this->memoryCtx['character_states'] ?? [];
        if (empty($states)) return '';

        $canonicalProtagonist = $this->novel['protagonist_name'] ?? '';

        $lines = [];
        foreach ($states as $name => $st) {
            if (isset($st['alive']) && !$st['alive']) continue;
            $p = [];
            if (!empty($st['title']))  $p[] = "职务：{$st['title']}";
            if (!empty($st['status'])) $p[] = "处境：{$st['status']}";
            // 扩展属性（境界/等级/能力等）
            $attrs = $st['attributes'] ?? [];
            if (is_array($attrs)) {
                if (!empty($attrs['recent_change'])) $p[] = "近况：{$attrs['recent_change']}";
                $attrLabels = [
                    'realm' => '境界', 'level' => '等级', 'power' => '战力',
                    'ability' => '能力', 'bloodline' => '血脉', 'treasure' => '法宝',
                ];
                foreach ($attrLabels as $key => $label) {
                    if (!empty($attrs[$key])) {
                        $val = is_array($attrs[$key]) ? implode('、', $attrs[$key]) : $attrs[$key];
                        $p[] = "{$label}：{$val}";
                    }
                }
                // 技能（数组，拼接展示）
                if (!empty($attrs['skills']) && is_array($attrs['skills'])) {
                    $p[] = '技能：' . implode('、', $attrs['skills']);
                }
                // 装备（数组）
                if (!empty($attrs['equipment']) && is_array($attrs['equipment'])) {
                    $p[] = '装备：' . implode('、', $attrs['equipment']);
                }
                // 感悟
                if (!empty($attrs['insight'])) {
                    $p[] = "感悟：{$attrs['insight']}";
                }
                foreach ($attrs as $key => $val) {
                    if (isset($attrLabels[$key]) || $key === 'recent_change' 
                        || $key === 'skills' || $key === 'equipment' || $key === 'insight'
                        || str_starts_with($key, 'realm_skip_')) continue;
                    if (is_scalar($val) && !empty($val)) $p[] = "{$key}：{$val}";
                }
            }
            if ($p) $lines[] = "· {$name}——" . implode('，', $p);
        }

        $section = '';

        // 主角境界锚定 — 从 character_states 读取主角境界，插入 HEAD 强约束
        if ($canonicalProtagonist && isset($states[$canonicalProtagonist])) {
            $pAttrs = $states[$canonicalProtagonist]['attributes'] ?? [];
            if (is_array($pAttrs) && (!empty($pAttrs['realm']) || !empty($pAttrs['level']))) {
                $realmAnchor = !empty($pAttrs['realm']) ? $pAttrs['realm'] : ($pAttrs['level'] ?? '');
                $powerText = !empty($pAttrs['power']) ? "（战力：{$pAttrs['power']}）" : '';
                $section .= "【主角境界锚定】{$canonicalProtagonist} 当前为 {$realmAnchor}{$powerText}。后续写作中主角境界变化必须符合修炼体系逻辑，不得无故跳级或退化。如本章有境界突破，突破后的境界必须合理递进。\n\n";
                
                // 注入反派实力对标规则
                try {
                    require_once __DIR__ . '/PowerSystem.php';
                    $ps = new PowerSystem((int)($this->novel['id'] ?? 0));
                    $constraint = $ps->buildAntagonistConstraint($canonicalProtagonist, $realmAnchor);
                    if (!empty($constraint)) {
                        $section .= "{$constraint}\n\n";
                    }
                } catch (\Throwable $e) {
                    // PowerSystem 不可用则跳过
                }
            }
        }

        // 境界跳级修复指引 — 检测到上章跳级后，注入圆回指令
        $bridgeSection = $this->buildRealmBridgeSection($states);
        if ($bridgeSection) {
            $section = $bridgeSection . $section; // 修复指引放在最前面，优先级最高
        }

        if ($lines) {
            $section .= "【人物当前状态（必须严格遵守，不得与此矛盾）】\n" . implode("\n", $lines) . "\n\n";
        }

        // 已死亡/离场角色黑名单 — 防止 AI 在后期章节复活已死角色
        // MemoryEngine 仅查 alive=1，此处单独查询死亡角色
        try {
            $deadCards = \DB::fetchAll(
                'SELECT name FROM character_cards WHERE novel_id=? AND alive=0 ORDER BY name ASC',
                [(int)$this->novel['id']]
            );
            if ($deadCards) {
                $deadNames = array_column($deadCards, 'name');
                $section .= "【⚠️ 已死亡/离场角色——本章绝对不可出场】\n"
                    . implode('、', $deadNames) . "\n"
                    . "以上角色已死亡或永久离开故事，禁止在正文中以任何形式出现。\n\n";
            }
        } catch (\Throwable) {}

        return $section;
    }

    /**
     * 从人物状态中检测境界跳级标记，生成完整过渡章指引
     */
    private function buildRealmBridgeSection(array $states): string
    {
        foreach ($states as $name => $st) {
            $attrs = $st['attributes'] ?? [];
            if (!is_array($attrs) || empty($attrs['realm_skip_bridge'])) continue;

            $skipCh  = (int)($attrs['realm_skip_chapter'] ?? 0);
            // 只显示最近5章内的跳级，超过则视为已处理
            if ($skipCh > 0 && $this->chNum - $skipCh > 5) continue;

            $warning = $attrs['realm_skip_warning'] ?? '';
            $bridge  = $attrs['realm_skip_bridge']  ?? '';
            $skipped = $attrs['realm_skip_skipped'] ?? '';
            $events  = $attrs['realm_skip_events']  ?? '';
            $oldR    = $attrs['realm_skip_old']     ?? '';
            $newR    = $attrs['realm_skip_new']     ?? '';

            $section  = "【⚠️ 境界跳级过渡章 — 本章最高优先级】\n";
            $section .= "上章检测到 {$name} 的境界从「{$oldR}」直接跃升至「{$newR}」，跳过了「{$skipped}」。\n";
            $section .= "\n";
            $section .= "本章即为过渡章。请将上述被跳过的境界，作为{$name}的成长经历，在本章中以回忆/倒叙方式自然补上。\n";
            $section .= "\n";
            $section .= "{$bridge}\n";
            $section .= "\n";
            $section .= "待过渡的阶段事件：\n{$events}\n\n";

            // 修改本章大纲 — 注入过渡标记
            $outline = $this->chapter['outline'] ?? '';
            if (mb_strpos($outline, '【过渡章】') === false) {
                $bridgeTag = "\n\n【过渡章】本章包含 {$name} 从「{$oldR}」→「{$skipped}」→「{$newR}」的境界回溯过渡，请用500-800字回忆/闪回式叙事补上中间过程。";
                $this->chapter['outline'] = $outline . $bridgeTag;
            }

            return $section;
        }
        return '';
    }

    /**
     * v1.10.3: 角色语音规则段落 — 从 character_cards.voice_profile 读取
     * 仅在角色有 voice_profile 数据时注入
     */
    private function buildVoiceProfileSection(): string
    {
        try {
            require_once __DIR__ . '/memory/CharacterCardRepo.php';
            $repo = new CharacterCardRepo((int)$this->novel['id']);
            $voiceMap = $repo->listWithVoiceProfile();
            if (empty($voiceMap)) return '';

            require_once __DIR__ . '/agents/DialogueVoiceChecker.php';
            return DialogueVoiceChecker::buildVoiceSection($voiceMap);
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildChapterTypeSection(): string
    {
        try {
            require_once __DIR__ . '/ChapterTypeDetector.php';
            return ChapterTypeDetector::buildGuidanceSection(
                trim((string)($this->chapter['outline'] ?? ''))
            );
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildCausalChainSection(): string
    {
        try {
            require_once __DIR__ . '/CausalChainGuard.php';
            $guard = new CausalChainGuard(
                (int)$this->novel['id'],
                $this->chNum,
                trim((string)($this->chapter['outline'] ?? ''))
            );
            return $guard->buildPromptSection();
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildSceneAtmosphereSection(): string
    {
        try {
            require_once __DIR__ . '/SceneAtmosphereBuilder.php';
            $builder = new SceneAtmosphereBuilder(
                (int)$this->novel['id'],
                $this->genre,
                trim((string)($this->chapter['outline'] ?? '')),
                trim((string)($this->chapter['pacing'] ?? '')),
                trim((string)($this->memoryCtx['current_location'] ?? '')),
                $this->chNum
            );
            return $builder->build();
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildMomentumSection(): string
    {
        $m = $this->memoryCtx['story_momentum'] ?? '';
        $arc = $this->memoryCtx['current_arc_summary'] ?? '';
        if ($m === '' && $arc === '') return '';
        $s = '';
        if ($m !== '') $s .= "【当前故事势能（本章需延续或推进此张力）】\n{$m}\n\n";
        if ($arc !== '') $s .= "【当前弧段摘要】\n{$arc}\n\n";
        return $s;
    }

    private function buildEventsSection(): string
    {
        $evts = $this->memoryCtx['key_events'] ?? [];
        if (empty($evts)) return '';
        $lines = array_map(fn($e) => "第{$e['chapter']}章：{$e['event']}", $evts);
        return "【全书已发生事件（严禁重写或矛盾）】\n" . implode("\n", $lines) . "\n\n";
    }

    private function buildSemanticSection(): string
    {
        $hits = $this->memoryCtx['semantic_hits'] ?? [];
        if (empty($hits)) return '';
        $kbLabels = ['character'=>'角色资料','worldbuilding'=>'世界观设定','plot'=>'情节线索','style'=>'风格参考'];
        $lines = [];
        foreach ($hits as $h) {
            if (($h['source'] ?? 'atom') === 'kb') {
                $lines[] = "· [{$kbLabels[$h['type']]}] {$h['content']}";
            } else {
                $t = !empty($h['chapter']) ? "[第{$h['chapter']}章] " : '';
                $lines[] = "· {$t}{$h['content']}";
            }
        }
        return "【相关历史线索（语义关联，可作背景参考）】\n" . implode("\n", $lines) . "\n\n";
    }

    private function buildTropesSection(): string
    {
        $novelId = (int)$this->novel['id'];
        $lines = [];

        $tropes = getPreviousUsedTropes($novelId, $this->chNum);
        if ($tropes) {
            $lines[] = "【近期已用意象/场景（禁止重复，需要新鲜感）】：" . implode('、', $tropes);
        }

        try {
            require_once __DIR__ . '/memory/SceneTemplateRepo.php';
            $repo = new SceneTemplateRepo($novelId);
            $exhausted = $repo->getExhaustedTemplates();

            if (!empty($exhausted)) {
                $exhaustedLines = [];
                foreach (array_slice($exhausted, 0, 10, true) as $tid => $info) {
                    $exhaustedLines[] = "{$info['name']}({$tid})：已用{$info['use_count']}/{$info['max_uses']}次，第{$info['last_chapter']}章最后使用——已达上限";
                }
                $lines[] = "\n【🚫 全书已耗尽的场景模板（严禁再用）】\n" . implode("\n", $exhaustedLines);
            }

            $recent = $repo->getRecentlyUsedTemplates($this->chNum, 15);
            if (!empty($recent)) {
                $recentLines = [];
                foreach ($recent as $r) {
                    $tpl = SCENE_TEMPLATES[$r['template_id']] ?? null;
                    if (!$tpl) continue;
                    $gap = $this->chNum - $r['chapter_number'];
                    $cooldown = $tpl['cooldown'] ?? 0;
                    if ($gap < $cooldown) {
                        $recentLines[] = "{$tpl['name']}({$r['template_id']})：第{$r['chapter_number']}章使用，冷却中（间隔{$gap}章，需{$cooldown}章）";
                    }
                }
                if (!empty($recentLines)) {
                    $lines[] = "\n【⏳ 冷却中的场景模板（不建议使用）】\n" . implode("\n", $recentLines);
                }
            }

            $cp = $this->memoryCtx['cool_point_history'] ?? [];
            if (!empty($cp)) {
                $lastCoolType = end($cp)['type'] ?? '';
                if ($lastCoolType) {
                    $alternatives = $repo->getAlternativeTemplates($lastCoolType, $this->chNum);
                    if (!empty($alternatives)) {
                        $altLines = [];
                        foreach (array_slice($alternatives, 0, 5) as $alt) {
                            $status = $alt['use_count'] > 0 ? "已用{$alt['use_count']}次" : '未使用';
                            $altLines[] = "  ④ {$alt['name']}({$alt['template_id']})——{$status}，距上次{$alt['gap']}章";
                        }
                        if (!empty($altLines)) {
                            $lines[] = "\n【✅ 推荐替代模板（如需「{$lastCoolType}」类型，请优先选用）】\n" . implode("\n", $altLines);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('buildTropesSection scene template error: ' . $e->getMessage());
        }

        return $lines ? implode("\n", $lines) . "\n\n" : '';
    }

    /** 近章钩子类型历史——给 AI 感知最近用了哪些钩子，辅助多样性 */
    private function buildHookHistorySection(): string
    {
        $hooks = $this->memoryCtx['recent_hook_types'] ?? [];
        if (empty($hooks)) return '';
        $lines = array_map(fn($h) => "第{$h['chapter']}章：{$h['hook_type']}", $hooks);
        return "【近章钩子类型记录（避免连续重复）】\n" . implode("\n", $lines) . "\n\n";
    }

    /** 爽点类型历史——给 AI 感知近期爽点分布，辅助调度多样性 */
    private function buildCoolPointHistorySection(): string
    {
        $cp = $this->memoryCtx['cool_point_history'] ?? [];
        if (empty($cp)) return '';
        $lines = array_map(fn($c) => "第{$c['chapter']}章：{$c['name']}", $cp);
        return "【近期爽点类型记录（避免连续重复类型）】\n" . implode("\n", $lines) . "\n\n";
    }

    /**
     * 获取全书进度上下文（带缓存）
     *
     * 供 buildProgressSection / buildEndingContextSection / buildRecurringMotifsSection 使用。
     * 优先从 memoryCtx 获取（MemoryEngine 已计算），降级直接调用 MemoryEngine。
     */
    private function getProgress(): array
    {
        if ($this->progressCache !== null) {
            return $this->progressCache;
        }

        // 优先：从 memoryCtx 中获取（如果 WriteEngine::initMemory 已计算并注入）
        if (!empty($this->memoryCtx['progress_context'])) {
            $this->progressCache = $this->memoryCtx['progress_context'];
            return $this->progressCache;
        }

        // 降级：直接调用 MemoryEngine::getProgressContext
        try {
            require_once __DIR__ . '/memory/MemoryEngine.php';
            $engine = new MemoryEngine((int)$this->novel['id']);
            $this->progressCache = $engine->getProgressContext($this->chNum);
            return $this->progressCache;
        } catch (\Throwable $e) {
            // 最终降级：返回空结构，各调用方用 ?? 兜底
            $this->progressCache = [
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
            return $this->progressCache;
        }
    }

    /** 全书重复意象——提醒 AI 在行文中自然融入 */
    private function buildRecurringMotifsSection(): string
    {
        $prog = $this->getProgress();
        $motifs = $prog['recurring_motifs'] ?? [];
        if (empty($motifs)) return '';
        return "【全书重复意象（本章可自然融入的意象符号）】\n" . implode('、', $motifs) . "\n\n";
    }

    /**
     * v1.10.3: 金句/梗回调段落 — 从 novel_catchphrases 读取可 callback 的金句
     */
    private function buildCatchphraseSection(): string
    {
        try {
            require_once __DIR__ . '/memory/CatchphraseRepo.php';
            $repo = new CatchphraseRepo((int)$this->novel['id']);
            return $repo->buildCallbackSection($this->chNum);
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildForeshadowSection(): string
    {
        $parts = [];

        // 1) ForeshadowingResolver 主动回收任务（优先级最高）
        if (!empty($this->resolverResult['should_resolve']) && !empty($this->resolverResult['prompt_section'])) {
            $parts[] = trim($this->resolverResult['prompt_section']);
        }

        // 2) 伏笔倒计时压力：逾期越久措辞越强
        $pending = $this->memoryCtx['pending_foreshadowing'] ?? [];
        $resolvedIds = array_column($this->resolverResult['items'] ?? [], 'id');
        $agedLines = [];
        foreach ($pending as $f) {
            $fId = (int)($f['id'] ?? 0);
            if ($fId > 0 && in_array($fId, $resolvedIds)) continue;
            $planted = (int)($f['chapter'] ?? $f['planted_chapter'] ?? 0);
            if ($planted <= 0) continue;
            $age = $this->chNum - $planted;
            if ($age > 30) {
                $icon = '🔴【紧急回收】';
            } elseif ($age > 20) {
                $icon = '🟡【优先回收】';
            } elseif ($age > 10) {
                $icon = '📌【建议回收】';
            } else {
                continue;  // 埋设不到10章，不施加压力
            }
            $agedLines[] = "{$icon}{$f['desc']}（已埋{$age}章，宜尽快回收）";
        }
        if (!empty($agedLines)) {
            $parts[] = "【伏笔倒计时——逾期越久必须越早回收】\n" . implode("\n", $agedLines);
        }

        // 3) 到期伏笔紧急提醒（deadline 临近，优先级高于倒计时）
        $due = array_filter($pending, fn($f) => !empty($f['deadline']) && $this->chNum >= (int)$f['deadline'] - 3);
        if (!empty($due)) {
            $dueLines = [];
            foreach ($due as $f) {
                $fId = (int)($f['id'] ?? 0);
                if ($fId > 0 && in_array($fId, $resolvedIds)) continue;
                $dl = (int)$f['deadline'];
                $dueLines[] = ($this->chNum >= $dl - 2 && $this->chNum <= $dl + 2)
                    ? "⚠️【紧急·临近回收期限】{$f['desc']}"
                    : "{$f['desc']}（临近回收期限）";
            }
            if (!empty($dueLines)) {
                $parts[] = "【到期伏笔提醒】\n" . implode("\n", $dueLines);
            }
        }

        // 3) 伏笔生命周期中期提醒（每5章触发）
        if ($this->chNum > 0 && $this->chNum % 5 === 0) {
            try {
                require_once __DIR__ . '/memory/ForeshadowingRepo.php';
                $repo = new ForeshadowingRepo((int)$this->novel['id']);
                $healthAlerts = $repo->checkHealth($this->chNum);
                $resolvedIds = array_column($this->resolverResult['items'] ?? [], 'id');
                $healthLines = [];
                foreach ($healthAlerts as $alert) {
                    if (in_array($alert['foreshadow_id'], $resolvedIds)) continue;
                    if ($alert['severity'] === 'high') {
                        $healthLines[] = "💤 提醒：{$alert['foreshadow']}（已埋{$alert['age']}章，{$alert['since_last_mention']}章未触动）";
                    }
                }
                if (!empty($healthLines)) {
                    $parts[] = "【伏笔遗忘提醒】\n" . implode("\n", $healthLines);
                }
            } catch (\Throwable) {}
        }

        if (empty($parts)) return '';
        // 护栏：禁止把章节号/编号等创作元数据写进正文，避免穿帮
        $parts[] = "【硬性规则】以上编号与提示仅供创作参考，严禁出现在正文。"
                 . "角色不知道自己身处第几章；正文不得出现「第N章」「根据第N章」"
                 . "「记录/档案显示」等指向章节号或卷册编号的表述。伏笔回收须通过"
                 . "角色的记忆、对话、线索自然呈现。";
        return implode("\n\n", $parts) . "\n\n";
    }

    private function buildVolumeGoalSection(): string
    {
        try {
            $vol = DB::fetch(
                'SELECT volume_goals, must_resolve_foreshadowing, volume_number, title
                 FROM volume_outlines WHERE novel_id=? AND start_chapter<=? AND end_chapter>=? LIMIT 1',
                [(int)$this->novel['id'], $this->chNum, $this->chNum]
            );
            if (!$vol) return '';
            $goals  = json_decode($vol['volume_goals'] ?? '[]', true) ?: [];
            $musts  = json_decode($vol['must_resolve_foreshadowing'] ?? '[]', true) ?: [];
            $s = '';
            if ($goals)  $s .= "【第{$vol['volume_number']}卷《{$vol['title']}》写作目标（本章需推进）】\n" . implode("\n", array_map(fn($g) => "· {$g}", $goals)) . "\n\n";
            if ($musts)  $s .= "【本卷必须回收的伏笔（若本章是回收时机，请自然融入情节）】\n" . implode("\n", array_map(fn($f) => "· {$f}", $musts)) . "\n\n";
            return $s;
        } catch (\Throwable) { return ''; }
    }

    private function buildProgressSection(): string
    {
        try {
            $prog = $this->getProgress();
            if (($prog['target_chapters'] ?? 0) <= 0) return '';
            $p = [];
            $p[] = "当前第{$this->chNum}章/全书{$prog['target_chapters']}章（{$prog['progress_pct']}%，剩余{$prog['remaining_chapters']}章）";
            if ($prog['act_phase'])       $p[] = "叙事阶段：{$prog['act_phase']}";
            if ($prog['volume_progress']) $p[] = "所在卷：{$prog['volume_progress']}";
            $pc = $prog['pending_foreshadowing_count'];
            $oc = $prog['overdue_foreshadowing_count'];
            if ($pc > 0) $p[] = "未回收伏笔：{$pc}条" . ($oc>0 ? "，{$oc}条已逾期" : '');
            $next = array_values(array_filter($prog['major_turning_points'], fn($t) => !$t['passed'] && $t['chapter'] > $this->chNum));
            if ($next) $p[] = "下一个转折点：第{$next[0]['chapter']}章——{$next[0]['event']}";
            return "【📊 全书进度】" . implode("  ·  ", $p) . "\n\n";
        } catch (\Throwable) { return ''; }
    }

    private function buildEndingContextSection(): string
    {
        try {
            return buildEndingContext($this->getProgress(), $this->chNum);
        } catch (\Throwable) { return ''; }
    }

    private function buildRhythmSection(): string
    {
        try {
            require_once __DIR__ . '/rhythm_adjuster.php';
            $adj = new RhythmAdjuster((int)$this->novel['id']);
            $history = [];
            $rcs = DB::fetchAll(
                'SELECT chapter_number, cool_point_type, actual_cool_point_types FROM chapters WHERE novel_id=? AND chapter_number<? AND (cool_point_type IS NOT NULL OR actual_cool_point_types IS NOT NULL) ORDER BY chapter_number DESC LIMIT 20',
                [(int)$this->novel['id'], $this->chNum]
            );
            foreach ($rcs as $rc) {
                $actual = json_decode($rc['actual_cool_point_types'] ?? '[]', true);
                if (!empty($actual) && is_array($actual)) {
                    foreach ($actual as $t) {
                        if (is_string($t) && !empty($t)) $history[] = ['chapter'=>(int)$rc['chapter_number'],'type'=>$t];
                    }
                } elseif (!empty($rc['cool_point_type'])) {
                    $history[] = ['chapter'=>(int)$rc['chapter_number'],'type'=>$rc['cool_point_type']];
                }
            }
            return $adj->generateRhythmInstructions($adj->calculateRhythm($this->chNum, $history));
        } catch (\Throwable) { return ''; }
    }

    private function buildEndingSection(): string
    {
        try {
            require_once __DIR__ . '/ending_enforcer.php';
            $enf = new EndingEnforcer((int)$this->novel['id'], $this->chNum);
            if (!$enf->needsEndingEnforcement()) return '';
            $s = $enf->generateEndingInstructions();
            $fa = $enf->generateForeshadowResolutionAdvice();
            return $fa ? "{$s}\n\n{$fa}" : $s;
        } catch (\Throwable) { return ''; }
    }

    private function buildSynopsisSection(): string
    {
        if (empty($this->chapter['synopsis_id'])) return '';
        $syn = DB::fetch('SELECT * FROM chapter_synopses WHERE id=?', [$this->chapter['synopsis_id']]);
        if (!$syn) return '';
        $s = "【章节简介（详细写作蓝图，必须遵循）】\n简介：{$syn['synopsis']}\n\n";
        $scenes = json_decode($syn['scene_breakdown'] ?? '[]', true);
        if ($scenes) {
            $s .= "场景分解：\n";
            foreach ($scenes as $sc) $s .= "场景{$sc['scene']}：{$sc['location']}，人物：" . implode('、',$sc['characters']??[]) . "，{$sc['action']}（{$sc['emotion']}）\n";
            $s .= "\n";
        }
        $db = json_decode($syn['dialogue_beats'] ?? '[]', true);
        if ($db) $s .= "对话要点：" . implode('；', $db) . "\n\n";
        $sd = json_decode($syn['sensory_details'] ?? '{}', true);
        if ($sd) {
            $parts = [];
            if (!empty($sd['visual']))     $parts[] = "视觉-{$sd['visual']}";
            if (!empty($sd['auditory']))   $parts[] = "听觉-{$sd['auditory']}";
            if (!empty($sd['atmosphere'])) $parts[] = "氛围-{$sd['atmosphere']}";
            $s .= "感官细节：" . implode(' ', $parts) . "\n\n";
        }
        return $s . "节奏：{$syn['pacing']}  |  结尾悬念：{$syn['cliffhanger']}\n\n";
    }

    /**
     * v1.5：质量反馈段——把五关检测分数反向喂回 prompt
     *
     * 之前 quality_score 写完就完，下章 prompt 不知道前章短板。
     * 本段读最近 3 章五关结果，找出连续短板（≥2 章评分 <70）显式提醒 AI。
     */
    private function buildQualityFeedbackSection(): string
    {
        try {
            $recent = DB::fetchAll(
                'SELECT chapter_number, quality_score, gate_results, emotion_score, emotion_density
                 FROM chapters
                 WHERE novel_id = ? AND chapter_number < ?
                   AND status = "completed" AND quality_score IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT 3',
                [(int)$this->novel['id'], $this->chNum]
            );
            if (empty($recent)) return '';

            // 累计各五关短板
            $weakGates = [];
            $weakSugs  = [];  // 每个短板的修正建议（取最近一条）
            foreach ($recent as $rc) {
                $gates = json_decode($rc['gate_results'] ?? '[]', true) ?? [];
                foreach ($gates as $g) {
                    $score = (float)($g['score'] ?? 100);
                    $name  = $g['name'] ?? '';
                    if ($score < 70 && $name) {
                        $weakGates[$name] = ($weakGates[$name] ?? 0) + 1;
                        if (!empty($g['issues']) && empty($weakSugs[$name])) {
                            $issues = is_array($g['issues']) ? implode('；', array_slice($g['issues'], 0, 2)) : (string)$g['issues'];
                            $weakSugs[$name] = $issues;
                        }
                    }
                }
            }

            // 情绪密度短板
            $emoLowCount = 0;
            $emoDetailLines = [];
            foreach ($recent as $rc) {
                $es = $rc['emotion_score'] !== null ? (float)$rc['emotion_score'] : null;
                if ($es !== null && $es < 60) {
                    $emoLowCount++;
                }
                // 情绪密度 JSON 解析（如有）
                $ed = $rc['emotion_density'] ?? null;
                if ($ed) {
                    $density = is_string($ed) ? json_decode($ed, true) : $ed;
                    if ($density && is_array($density)) {
                        $cn = (int)$rc['chapter_number'];
                        $details = [];
                        foreach ($density as $cat => $freq) {
                            if (is_array($freq)) $freq = array_sum($freq);
                            if ((float)$freq > 0) $details[] = "{$cat}={$freq}次/万字";
                        }
                        if ($details) $emoDetailLines[] = "第{$cn}章：" . implode('，', $details);
                    }
                }
            }

            $lines = [];
            foreach ($weakGates as $name => $cnt) {
                if ($cnt >= 2) {
                    $sug = $weakSugs[$name] ?? '请重点改善';
                    $lines[] = "· 【{$name}】近 {$cnt} 章评分偏低：{$sug}";
                }
            }
            if ($emoLowCount >= 2) {
                $lines[] = "· 【情绪密度】近 {$emoLowCount} 章偏低，本章必须加大情绪词使用频率";
                if ($emoDetailLines) {
                    $lines[] = "  上章情绪词频：" . implode('；', $emoDetailLines);
                }
            }

            $wordTarget = (int)($this->novel['chapter_words'] ?? 0);
            if ($wordTarget > 0) {
                $wordOvers = 0;
                $wordUnders = 0;
                $wordTrend = [];
                foreach ($recent as $rc) {
                    $actual = (int)($rc['words'] ?? 0);
                    if ($actual <= 0) continue;
                    $tol = calculateDynamicTolerance($wordTarget);
                    if ($actual > $tol['max']) $wordOvers++;
                    elseif ($actual < $tol['min']) $wordUnders++;
                    $wordTrend[] = "第{$rc['chapter_number']}章{$actual}字";
                }
                if ($wordOvers >= 2) {
                    $lines[] = "· 【字数超标】近 {$wordOvers} 章超出上限，本章必须严格控制字数在目标范围内";
                } elseif ($wordUnders >= 2) {
                    $lines[] = "· 【字数不足】近 {$wordUnders} 章未达下限，本章必须写够字数";
                }
                if (!empty($wordTrend)) {
                    $lines[] = "  近章字数：" . implode(' → ', array_reverse($wordTrend)) . "（目标{$wordTarget}字）";
                }
            }

            $qScores = array_filter(array_map(fn($r) => (float)($r['quality_score'] ?? 0), $recent), fn($s) => $s > 0);
            if (count($qScores) >= 2) {
                $qArr = array_values($qScores);
                if ($qArr[0] < 70 && $qArr[0] < $qArr[count($qArr) - 1]) {
                    $lines[] = "· 【质量趋势】质量分持续下降（" . implode('→', array_reverse($qArr)) . "），本章须重点改善";
                }
            }

            if (empty($lines)) return '';

            return "【⚠️ 近期写作短板（本章必须修正）】\n" . implode("\n", $lines) . "\n\n";
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 本章大纲段
     * v1.11.8: 如果有 Synopsis（详细蓝图），则简化大纲段，避免信息重复
     */
    private function buildOutlineSection(): string
    {
        try {
            require_once __DIR__ . '/prompt.php';
            $outline = trim($this->chapter['outline'] ?? '');
            if ($outline === '') return '';
            $title = $this->chapter['title'] ?? '';
            $header = $title ? "第{$this->chNum}章《{$title}》" : "第{$this->chNum}章";

            // v1.11.8: 检查是否有 Synopsis（详细蓝图），有则简化大纲段
            $hasSynopsis = !empty($this->chapter['synopsis_id']);

            if ($hasSynopsis) {
                // 有详细蓝图时，大纲段仅保留核心信息，避免与 Synopsis 重复
                $s = "\n【本章核心大纲】\n{$header}\n大纲：{$outline}\n";

                // 关键情节点仍需保留（Synopsis 可能不包含）
                $keyPoints = json_decode($this->chapter['key_points'] ?? '[]', true);
                if (!empty($keyPoints) && is_array($keyPoints)) {
                    $s .= "关键情节点：" . implode('；', array_slice($keyPoints, 0, 3)) . "\n";
                }

                return $s . "\n";
            }

            // 无 Synopsis 时，完整展示大纲
            $s = "\n【本章大纲——必须严格遵循】\n{$header}\n大纲：{$outline}\n";

            $keyPoints = json_decode($this->chapter['key_points'] ?? '[]', true);
            if (!empty($keyPoints) && is_array($keyPoints)) {
                $s .= "关键情节点（每点必须写到）：\n";
                foreach ($keyPoints as $idx => $kp) {
                    $s .= "  " . ($idx + 1) . ". {$kp}\n";
                }
            }

            $hook = trim($this->chapter['hook'] ?? '');
            if ($hook !== '') {
                $s .= "章末钩子：{$hook}\n";
            }

            $pacing = trim($this->chapter['pacing'] ?? '');
            if ($pacing !== '') {
                $pacingLabel = ['快' => '快节奏——动作密集、冲突紧凑', '中' => '中等节奏——推进与过渡平衡', '慢' => '慢节奏——铺垫、描写、情感深化'];
                $s .= "节奏要求：" . ($pacingLabel[$pacing] ?? $pacing) . "\n";
            }

            $suspense = trim($this->chapter['suspense'] ?? '');
            if ($suspense === '有') {
                $s .= "悬念标记：本章必须有悬念\n";
            }

            $coolType = trim($this->chapter['cool_point_type'] ?? '');
            if ($coolType !== '' && isset(COOL_POINT_TYPES[$coolType])) {
                $s .= "爽点类型：" . COOL_POINT_TYPES[$coolType]['name'] . "\n";
            }

            return $s . "\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * 章节控制卡：在正式写作前强制 LLM 自我问答
     *
     * 利用 chain-of-thought 锚定效应——LLM 在脑中回答这些问题时
     * 会激活对应方向的 token 分布，提升伏笔回收率和情节一致性。
     */
    private function buildPreWriteChecklist(): string
    {
        $protagonist = trim($this->novel['protagonist_name'] ?? '');
        $protagonist = $protagonist ?: '主角';

        // 从 memoryCtx 读取伏笔压力
        $pendingFs = $this->memoryCtx['pending_foreshadowing'] ?? [];
        $fsCount   = count($pendingFs);
        if ($fsCount > 0) {
            // 列出最高优先级的待回收伏笔（最多2条）
            $fsSamples = array_slice($pendingFs, 0, 2);
            $fsDescs   = array_map(fn($f) => $f['description'] ?? '', $fsSamples);
            $fsDescs   = array_filter($fsDescs);
            $fsHint    = !empty($fsDescs)
                ? "你有 {$fsCount} 条待回收伏笔，本章至少推进或回收其中 1 条。例如：「" . implode('」、「', $fsDescs) . "」"
                : "你有 {$fsCount} 条待回收伏笔，本章至少推进或回收其中 1 条";
        } else {
            $fsHint = "暂无待回收伏笔。本章可埋设新伏笔，但需确保在接下来 10 章内回收";
        }

        // 从章节数据读取已有钩子类型，防止重复
        $hookType = trim($this->chapter['hook_type'] ?? '');
        $hookHint = $hookType
            ? "本章指定钩子类型为「{$hookType}」，章末 3 段必须兑现"
            : "章末必须制造悬念/反转/信息爆炸，让读者无法放下";

        // 收尾期检测（后 15% 章节）
        $targetChapters = (int)($this->novel['target_chapters'] ?? 0);
        $inEnding = $targetChapters > 0 && $this->chNum >= (int)($targetChapters * 0.85);
        $endingHint = $inEnding
            ? "⚠️ 本书已进入收尾期（第{$this->chNum}/{$targetChapters}章），禁止新开支线和新埋未回收的伏笔！本章应以回收主线伏笔为主，推动故事走向结局"
            : '';

        $lines = [
            "【本章写作控制卡——请在脑中明确答案后再动笔】",
            "1. 本章结束后，{$protagonist}身上改变了什么？（状态/关系/能力/认知——至少选一项明确改变）",
            "2. 本章推动了哪条主线/支线？进度前进了多少？（描述具体的推进幅度）",
            "3. 伏笔处理：{$fsHint}",
            "4. 核心冲突场景——本章最高张力的对抗/抉择/危机是什么？发生在谁和谁之间？",
            "5. {$hookHint}",
        ];

        if ($endingHint) {
            $lines[] = "6. {$endingHint}";
        }

        $lines[] = "（以上问题无需在正文中输出，仅用于在你脑中设定写作方向）";

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * 作者画像风格指导段
     * 从 novels.ref_author / writing_style 提取，引导 AI 模仿指定作者风格
     */
    private function buildAuthorProfileSection(): string
    {
        try {
            $refAuthor = trim($this->novel['ref_author'] ?? '');
            $style     = trim($this->novel['writing_style'] ?? '');
            if ($refAuthor === '' && $style === '') return '';

            $lines = ["【作者画像风格指导】"];
            if ($refAuthor !== '') {
                $lines[] = "模仿作者风格：{$refAuthor}";
            }
            if ($style !== '') {
                $lines[] = "写作风格：{$style}";
            }
            $lines[] = "请在写作中严格贯彻以上风格特征，用词、句式、节奏均需对齐。";
            return implode("\n", $lines) . "\n\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * 目标读者画像段（v1.10.3）
     * 从 novels.extra_settings 中提取 reader_profile
     */
    private function buildReaderProfileSection(): string
    {
        try {
            $extra = $this->novel['extra_settings'] ?? '';
            if (empty($extra)) return '';
            $data = is_string($extra) ? json_decode($extra, true) : $extra;
            if (!$data) return '';

            $profile = trim($data['reader_profile'] ?? $data['target_reader'] ?? '');
            if ($profile === '') return '';

            return "【目标读者画像】\n{$profile}\n写作时请针对此读者群体优化内容：语言风格、节奏、爽点类型均需匹配目标读者偏好。\n\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * 小说基本信息段
     * 包含书名、类型、目标字数等基础信息
     */
    private function buildNovelInfo(): string
    {
        try {
            $title   = $this->novel['title'] ?? '';
            $genre   = $this->genre;
            $style   = trim($this->novel['writing_style'] ?? '');
            $target  = (int)($this->novel['chapter_words'] ?? 0);
            $protName = trim($this->novel['protagonist_name'] ?? '');

            $lines = ["【小说信息】"];
            $lines[] = "书名：《{$title}》  类型：{$genre}";
            if ($style)   $lines[] = "风格：{$style}";
            if ($protName) $lines[] = "主角：{$protName}";
            if ($target > 0) $lines[] = "本章目标字数：{$target}字";

            return implode("\n", $lines) . "\n\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * 描写密度段
     * 委托给 prompt.php 的 getDensityGuidelines()
     */
    private function buildUserDensitySection(): string
    {
        try {
            require_once __DIR__ . '/prompt.php';
            $guide = getDensityGuidelines($this->genre);
            if (empty($guide)) return '';
            return "【描写密度指南】\n{$guide}\n\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * 钩子类型段
     * 委托给 prompt.php 的 suggestHookType()
     */
    private function buildUserHookSection(): string
    {
        try {
            require_once __DIR__ . '/prompt.php';

            $outlineHookType = trim($this->chapter['hook_type'] ?? '');
            if ($outlineHookType !== '' && isset(HOOK_TYPES[$outlineHookType])) {
                $type = $outlineHookType;
                $typeName = HOOK_TYPES[$type]['name'];
                $reason = '大纲已规划此钩子类型';
            } else {
                $suggestion = suggestHookType($this->chapter);
                $type = $suggestion['type'] ?? '';
                $reason = $suggestion['reason'] ?? '';
                if (empty($type)) return '';
                $typeNames = [
                    'crisis_interrupt' => '危机中断型',
                    'info_bomb'        => '信息爆炸型',
                    'plot_twist'       => '情节反转型',
                    'new_goal'         => '新目标型',
                    'emotional_impact' => '情感冲击型',
                    'upgrade_omen'     => '升级预兆型',
                    'truth_reveal'     => '真相揭露型',
                    'last_stand'       => '背水一战型',
                    'sacrifice'        => '牺牲感动型',
                ];
                $typeName = $typeNames[$type] ?? HOOK_TYPES[$type]['name'] ?? $type;
            }

            $s = "【章末钩子类型】\n推荐类型：{$typeName}（{$type}）";
            if ($reason) $s .= "\n推荐理由：{$reason}";
            return $s . "\n\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * 写作铁律重申段
     * 在尾部再次强调关键约束，利用 LLM U 型注意力的尾部高权重
     */
    private function buildUserRulesSection(): string
    {
        try {
            $targetWords = (int)$this->novel['chapter_words'];
            $tol = calculateDynamicTolerance($targetWords);
            $maxWords = $tol['max'];
            $protName = trim($this->novel['protagonist_name'] ?? '');

            $lines = ["【写作铁律重申——本章必须遵守】"];
            $lines[] = "1. 字数不可超过 {$maxWords} 字";
            $lines[] = "2. 四段式结构：铺垫→发展→高潮→钩子，比例按节奏指令";
            $lines[] = "3. 章末必须有钩子（悬念/反转/信息爆炸），让读者无法放下";
            $lines[] = "4. 禁止使用AI高频词（深邃、凝视、缓缓、蓦然、骤然、指节泛白等）";
            $lines[] = "5. 对话占比 25-40%，禁止连续超过300字无对话/无事件";
            if ($protName) {
                $lines[] = "6. 主角名固定为「{$protName}」，不可更改";
            }

            return implode("\n", $lines) . "\n\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * 全书三幕结构段（v1.6 算法 E）
     * 从 story_outlines 读取三幕定位，帮助 AI 在全局框架下写作
     */
    private function buildStoryOutlineSection(): string
    {
        try {
            $storyOutline = DB::fetch(
                'SELECT story_arc, act_division FROM story_outlines WHERE novel_id=?',
                [(int)$this->novel['id']]
            );
            if (!$storyOutline) return '';

            $lines = ["【全书故事主线——本章写作必须对齐此框架】"];

            $storyArc = trim($storyOutline['story_arc'] ?? '');
            if ($storyArc) {
                $lines[] = "故事主线：{$storyArc}";
            }

            $actDiv = json_decode($storyOutline['act_division'] ?? '{}', true);
            if ($actDiv) {
                $targetChapters = (int)($this->novel['target_chapters'] ?? 0);
                $completedChapters = (int)(DB::fetch(
                    'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id=? AND status="completed"',
                    [(int)$this->novel['id']]
                )['cnt'] ?? 0);
                $pct = $targetChapters > 0 ? $completedChapters / $targetChapters : 0;

                // 标注当前幕
                foreach (['act1', 'act2', 'act3'] as $actKey) {
                    if (empty($actDiv[$actKey])) continue;
                    $a = $actDiv[$actKey];
                    $isCurrent = false;
                    if ($actKey === 'act1' && $pct <= 0.2) $isCurrent = true;
                    elseif ($actKey === 'act2' && $pct > 0.2 && $pct <= 0.8) $isCurrent = true;
                    elseif ($actKey === 'act3' && $pct > 0.8) $isCurrent = true;

                    $mark = $isCurrent ? ' ← 当前所在幕' : '';
                    $chapters = $a['chapters'] ?? '';
                    $theme = $a['theme'] ?? '';
                    $growth = $a['character_growth'] ?? '';
                    $lines[] = "{$actKey}（{$chapters}）：{$theme}{$mark}";
                    if ($growth) $lines[] = "  角色成长：{$growth}";
                }
            }

            return implode("\n", $lines) . "\n\n";
        } catch (\Throwable) { return ''; }
    }

    /**
     * KB 语义召回段（v1.6 算法 D）
     * 从 memoryCtx['semantic_hits'] 中提取 KB 类别的命中
     */
    /**
     * v41: 全书圣经（世界规则/人物现状/主线时间线）。
     * 每 N 章才更新一次 → 待在缓存前缀里，给 1000 章全程设定零漂移，几乎免费。
     */
    private function buildBibleSection(): string
    {
        if (!getSystemSetting('ws_story_bible_enabled', true, 'bool')) return '';
        try {
            require_once __DIR__ . '/memory/StoryBible.php';
            $bible = StoryBible::get((int)$this->novel['id']);
        } catch (\Throwable $e) {
            return '';
        }
        if (!$bible) return '';
        $parts = [];
        if (trim($bible['world_md']) !== '')     $parts[] = "## 世界规则\n" . trim($bible['world_md']);
        if (trim($bible['character_md']) !== '') $parts[] = "## 人物现状\n" . trim($bible['character_md']);
        if (trim($bible['timeline_md']) !== '')  $parts[] = "## 主线时间线\n" . trim($bible['timeline_md']);
        if (!$parts) return '';
        return "【全书圣经（权威设定，必须严格遵守，不得与之矛盾）】\n"
            . implode("\n\n", $parts) . "\n\n";
    }

    /**
     * v41 文风范本（few-shot）：取本书评分最高的已写章节片段作风格锚，让模型"照着写"而非"按规则写"。
     * 自举——用本书自己写得最好的段落当标杆，无需外部素材；明确禁止照抄情节。
     */
    private function buildStyleExemplarSection(): string
    {
        if (!getSystemSetting('ws_style_exemplar_enabled', true, 'bool')) return '';
        try {
            $row = DB::fetch(
                "SELECT chapter_number, content, quality_score FROM chapters
                 WHERE novel_id=? AND status='completed' AND quality_score IS NOT NULL
                   AND content IS NOT NULL AND content <> ''
                   AND chapter_number < ? AND chapter_number <> ?
                 ORDER BY quality_score DESC, chapter_number DESC LIMIT 1",
                [(int)$this->novel['id'], $this->chNum, $this->chNum - 1]
            );
            if (!$row) return '';
            // 取高分章节正文中段一段（避开开头的"黄金三行"模板，更能体现常态文风）
            $content = trim((string)$row['content']);
            $start = min((int)(mb_strlen($content) * 0.15), 400);
            $excerpt = trim(mb_substr($content, $start, 460));
            if (mb_strlen($excerpt) < 120) $excerpt = mb_substr($content, 0, 460);
            if (mb_strlen($excerpt) < 120) return '';
            $maxLen = max(200, (int)getSystemSetting('ws_style_exemplar_len', 460, 'int'));
            $excerpt = mb_substr($excerpt, 0, $maxLen);
            return "【✍️ 文风范本（本书第{$row['chapter_number']}章高分片段）】\n"
                . "请学习以下片段的**句式节奏、描写质感、对话语气、叙事密度**，本章保持同样的文笔水准；"
                . "但**严禁照抄**其情节、句子或意象，只借鉴「写法」：\n"
                . "“……{$excerpt}……”\n\n";
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function buildKBContextSection(): string
    {
        try {
            $hits = $this->memoryCtx['semantic_hits'] ?? [];
            if (empty($hits)) return '';

            // 过滤出 KB 类别的命中
            $kbHits = array_filter($hits, function($h) {
                $cat = $h['category'] ?? $h['source_type'] ?? '';
                return $cat === 'KB' || $cat === 'knowledge_base'
                    || (isset($h['source_table']) && $h['source_table'] === 'knowledge_base');
            });

            if (empty($kbHits)) {
                // 如果没有显式 KB 标签，回退：取所有带 knowledge 关键字的命中
                foreach ($hits as $h) {
                    $content = $h['content'] ?? $h['text'] ?? '';
                    $cat = $h['category'] ?? '';
                    if ($cat === 'misc' && !empty($content)) {
                        $kbHits[] = $h;
                    }
                }
                if (empty($kbHits)) return '';
            }

            $lines = ["【知识库语义召回——与本章大纲相关的设定/规则】"];
            $count = 0;
            foreach (array_slice($kbHits, 0, 5) as $h) {
                $content = trim($h['content'] ?? $h['text'] ?? '');
                if ($content) {
                    $lines[] = "· {$content}";
                    $count++;
                }
            }

            if ($count === 0) return '';
            return implode("\n", $lines) . "\n\n";
        } catch (\Throwable) { return ''; }
    }

private function buildAgentSection(): string
    {
        try {
            require_once __DIR__ . '/agents/AgentDirectives.php';
            $dirs = AgentDirectives::active((int)$this->novel['id'], $this->chNum);
            if (empty($dirs)) return '';

            $priority = [
                'urgent' => 0,
                'quality' => 1,
                'emotion_continuity' => 1,
                'strategy' => 2,
                'mainline' => 2,
                'plot_pattern' => 2,
                'cognitive_load' => 2,
                'optimization' => 3,
                'global' => 5,
            ];
            usort($dirs, function($a, $b) use ($priority) {
                $pa = $priority[$a['type']] ?? 99;
                $pb = $priority[$b['type']] ?? 99;
                if ($pa !== $pb) return $pa <=> $pb;
                return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
            });

            $maxPerType = 2;
            $typeCount = [];
            $filtered = [];
            foreach ($dirs as $d) {
                $t = $d['type'] ?? 'unknown';
                $typeCount[$t] = ($typeCount[$t] ?? 0) + 1;
                if ($typeCount[$t] <= $maxPerType) {
                    $filtered[] = $d;
                }
            }

            $maxDirectives = 5;
            if (count($filtered) > $maxDirectives) {
                $filtered = array_slice($filtered, 0, $maxDirectives);
            }

            // v1.5.3: Token控制——单条指令最大200字，总指令最大800字
            $maxDirectiveChars = 200;
            $maxTotalChars = 800;
            $lines = [];
            $totalChars = 0;

            foreach ($filtered as $d) {
                $label = match($d['type']){
                    'urgent'      => '🚨 紧急修复',
                    'quality'     => '质量监控',
                    'strategy'    => '写作策略',
                    'optimization'=> '优化建议',
                    'global'      => '🌐 全书级调控',
                    default       => 'Agent指令'
                };
                $directive = $d['directive'];
                // 单条截断
                if (mb_strlen($directive) > $maxDirectiveChars) {
                    $directive = mb_substr($directive, 0, $maxDirectiveChars - 3) . '...';
                }
                $line = "· [{$label}] {$directive}";
                // 总长度控制
                if ($totalChars + mb_strlen($line) > $maxTotalChars) break;
                $lines[] = $line;
                $totalChars += mb_strlen($line);
            }

            $header = "\n\n【🤖 Agent 指令（本章写作必须遵循）】\n";
            $trailer = (count($dirs) > count($lines))
                ? "\n（共" . count($dirs) . "条指令，按优先级/Token截断至" . count($lines) . "条）\n"
                : "\n";
            return $header . implode("\n", $lines) . $trailer;
        } catch (\Throwable) { return ''; }
    }

    /**
     * v1.11.2: POV 视角约束段
     * 仅在第三人称限知视角 + 卷首章/第1章时注入
     */
    private function buildPOVSection(): string
    {
        try {
            $pov = trim($this->novel['narrative_pov'] ?? '');
            if ($pov !== '' && $pov !== 'third_limited') return '';

            // 默认为第三人称限知视角
            $protagonist = trim($this->novel['protagonist_name'] ?? '');
            if ($protagonist === '') return '';

            // 只在卷首章 + 第 1 章注入（避免每章重复占 token）
            if ($this->chNum > 1 && !$this->isVolumeStartChapter()) return '';

            return "【视角约束（关键）】\n" .
               "本书使用第三人称限知视角，跟随主角【{$protagonist}】。本章必须严格：\n" .
               "1. 只描写【{$protagonist}】看到/听到/感受到的内容\n" .
               "2. 其他角色的内心想法（「X 心想」、「X 暗暗思忖」）仅当 X = {$protagonist} 时允许\n" .
               "3. 切换到{$protagonist}不在场的场景必须有明确转场标记\n\n";
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * v1.11.2: 认知负荷约束段
     * 检测近章新元素密度，超标时提醒 AI 不要再引入新角色/设定
     */
    private function buildCognitiveLoadSection(): string
    {
        try {
            if ($this->chNum < 3) return '';

            require_once __DIR__ . '/CognitiveLoadMonitor.php';
            $monitor = new CognitiveLoadMonitor((int)$this->novel['id']);
            $status = $monitor->getStatus($this->chNum);

            // 只有在需要提醒时才注入
            if ($status['recent_5_new_elements'] <= 8 && $status['trend'] !== 'increasing') {
                return '';
            }

            $lines = ["【认知负荷提醒】"];
            $lines[] = "近5章已引入 {$status['recent_5_new_elements']} 个新元素。";

            if ($status['trend'] === 'increasing') {
                $lines[] = "趋势：新元素密度上升中。";
            }

            $lines[] = "本章请：";
            $lines[] = "1. 不再引入新角色/新地点/新概念";
            $lines[] = "2. 专注已有角色之间的互动";
            $lines[] = "3. 深化已有设定而非新增设定";

            return implode("\n", $lines) . "\n\n";
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * v1.11.2: 角色情绪状态段
     * 从 character_emotion_history 读取上章角色情绪，注入 prompt 确保连续性
     */
    private function buildEmotionStateSection(): string
    {
        try {
            if ($this->chNum < 2) return '';

            require_once __DIR__ . '/memory/CharacterEmotionRepo.php';
            $repo = new CharacterEmotionRepo((int)$this->novel['id']);

            // v1.11.2 Bug #2/#3 修复：情绪状态和连续性提醒不应互斥
            // 异常情况下用户更需要看到具体情绪状态，两个段落都注入
            $emotionSection = $repo->buildEmotionSection($this->chNum);
            $directive = $repo->buildContinuityDirective($this->chNum);

            if ($directive && $emotionSection) {
                return $emotionSection . "\n" . $directive . "\n\n";  // 状态 + 提醒
            }
            return $directive ?: ($emotionSection ?: '');
        } catch (\Throwable) {
            return '';
        }
    }
}
