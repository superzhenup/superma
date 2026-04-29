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
    public function buildSystemPrompt(): string
    {
        $blocks = array_map('trim', [
            $this->systemRole(),
            $this->ironRules(),
        ]);

        // v1.5.2: 用 isEducationChapter() 统一判断，含 20 章兜底降级
        // 解决无 volume_outlines 数据时所有非首章永远精简的隐患
        if ($this->isEducationChapter()) {
            $blocks[] = trim($this->goldenThreeLines());
            $blocks[] = trim($this->emotionVocabulary());
            $blocks[] = trim($this->dialogueStyle());
        } else {
            // 非教育章仅一行提醒，节省 ~500 字 prompt token
            $blocks[] = '【风格延续】保持卷首章已建立的黄金三行/四段式/情绪密度/对话风格。';
        }

        $blocks[] = trim($this->fourSegmentRhythm());   // 动态比例，每章不同
        $blocks[] = trim($this->hookGuidance());         // 钩子类型随章变化
        $blocks[] = trim($this->densityStandards());     // 题材依赖，每章保留

        return implode("\n\n", $blocks);
    }

    /**
     * v1.5.2: 判断当前章是否为"教育章"——需要注入完整教学型规则
     *
     * 触发条件（任一满足）：
     * 1. 第 1 章（无条件）
     * 2. 卷首章（volume_outlines 中某卷的 start_chapter）
     * 3. 兜底降级：若小说没有任何 volume_outlines 数据，每 20 章重申一次教学
     *    （否则非首章永远走精简路径，AI 会逐渐偏离教学规则）
     *
     * @return bool 是否需要注入完整教学
     */
    private function isEducationChapter(): bool
    {
        if ($this->chNum === 1) return true;

        // 优先：volume_outlines 卷首
        if ($this->isVolumeStartChapter()) return true;

        // 兜底：无卷数据时，每 20 章重申一次（章 21、41、61...）
        try {
            $hasVolumes = \DB::fetch(
                'SELECT 1 FROM volume_outlines WHERE novel_id=? LIMIT 1',
                [(int)$this->novel['id']]
            );
            if (!$hasVolumes && $this->chNum > 1 && (($this->chNum - 1) % 20 === 0)) {
                return true;
            }
        } catch (\Throwable $e) {
            // 查询失败时保守返回 false（精简模式）
        }

        return false;
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
        return <<<EOT
【写作铁律，必须遵守优先级最高】
1. 字数硬性限制（最高优先级）：正文必须严格控制在 {$minWords} ~ {$maxWords} 字之间，绝对不可超过 {$maxWords} 字。
2. 字数预警系统（写作时心中估算字数进度）：
{$warnings}
3. 字数控制技巧：
   - 开头快速入戏，黄金三行直接抓人
   - 中间情节紧凑，对话与动作交替推进
   - 写到约 {$earlyFinish} 字时必须进入钩子收尾
   - 严禁超过 {$maxWords} 字，字数到达即停笔
4. 人物一致性：所有人物的职务、身份、生死状态必须与【人物当前状态】完全一致，不得擅自改变
5. 情节不重复：【全书已发生事件】中出现的任何事件，严禁以任何形式重演或变体重复
6. 逻辑自洽：本章发生的事件必须是前情的自然延伸，因果链条清晰，不得出现无因之果
7. 直接开始：从"第{$this->chNum}章 {$this->chapter['title']}"这一行直接开始输出正文，不要有任何前言、后记、解释或"好的，我来写"等废话
8. 风格统一：保持与前文一致的叙事视角、语气和文风，不得中途切换人称
EOT;
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
                return [
                    'setup'  => (int)($rhythm['segment_ratios']['setup']  ?? 20),
                    'rising' => (int)($rhythm['segment_ratios']['rising'] ?? 30),
                    'climax' => (int)($rhythm['segment_ratios']['climax'] ?? 35),
                    'hook'   => (int)($rhythm['segment_ratios']['hook']   ?? 15),
                ];
            }
        } catch (\Throwable $e) {
            // 回退到静态配置
        }

        return [
            'setup'  => (int)getSystemSetting('ws_segment_ratio_setup',  20, 'int'),
            'rising' => (int)getSystemSetting('ws_segment_ratio_rising', 30, 'int'),
            'climax' => (int)getSystemSetting('ws_segment_ratio_climax', 35, 'int'),
            'hook'   => (int)getSystemSetting('ws_segment_ratio_hook',   15, 'int'),
        ];
    }

    public function hookGuidance(): string
    {
        $hook   = suggestHookType($this->chapter);
        $cType  = $hook['type'];
        $cDesc  = (HOOK_TYPES[$cType]['name'] ?? '') . '：' . ($hook['reason'] ?? '默认轮换');
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
        return <<<EOT
【😊 情绪词汇要求——基于1590本小说分析】
· 愤怒类：15-20次/万字（愤怒、怒火、暴怒、咬牙切齿、火冒三丈）
· 喜悦类：20-30次/万字（喜悦、高兴、兴奋、狂喜、心花怒放）
· 惊讶类：10-15次/万字（惊讶、震惊、不可思议、目瞪口呆）
· 恐惧类：5-10次/万字（恐惧、害怕、战栗、毛骨悚然）
· 悲伤类：5-10次/万字（悲伤、悲痛、心碎、黯然神伤）
原则：1.自然融入不堆砌 2.有铺垫和释放 3.有起伏变化 4.高潮加大密度 5.类型的情绪特色可微调
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

        // ── 头部：强约束 + 关键信息 ──
        $head = implode('', array_filter([
            $this->buildQualityFeedbackSection(),  // v1.5 新增：近章质量短板
            $this->buildCharacterSection(),         // 人物状态（防 OOC）
            $this->buildForeshadowSection(),        // 待回收伏笔
            $this->buildOutlineSection(),           // 本章大纲
            $this->buildSynopsisSection(),          // 章节简介（详细蓝图）
        ]));

        // ── 中部：弱信息 / 上下文 ──
        $middle = implode('', array_filter([
            $this->buildArcChapterSection(),        // 全书故事线
            $this->buildPrevSection(),              // 前情提要
            $this->buildRecentChapterSection(),     // 近章大纲
            $this->buildTailSection(),              // 前章尾文
            $this->buildMomentumSection(),          // 故事势能 + 弧段摘要
            $this->buildEventsSection(),            // 关键事件
            $this->buildSemanticSection(),          // 语义召回
            $this->buildHookHistorySection(),       // 近章钩子类型历史
            $this->buildCoolPointHistorySection(),  // 爽点类型历史
            $this->buildRecurringMotifsSection(),   // 全书重复意象
            $this->buildTropesSection(),            // 已用桥段
            $this->buildVolumeGoalSection(),
            $this->buildProgressSection(),
            $this->buildEndingContextSection(),
        ]));

        // ── 尾部：节奏 + 收尾 + 强约束规则 ──
        $tail = implode('', array_filter([
            $this->buildNovelInfo(),                // 小说信息
            $this->buildRhythmSection(),            // 节奏阶段（含 segment_ratios，已修双源）
            $this->buildEndingSection(),            // 收尾期强制（如适用）
            $this->buildUserDensitySection(),       // 描写密度
            $this->buildUserHookSection(),          // 钩子类型
            $this->buildUserRulesSection(),         // 写作铁律重申
        ]));

        // 注意：Agent 指令必须在"请开始写作"之前——LLM 读到启动指令后会忽略其后内容
        return $head . $middle . $tail
            . $this->buildAgentSection()
            . "\n请开始写作：\n";
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
        $lines = [];
        foreach ($recents as $rc) {
            $rn = (int)($rc['chapter_number'] ?? $rc['chapter'] ?? 0);
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
        }
        return "【近章大纲（章节结构参考，保持连贯）】\n" . implode("\n", $lines) . "\n\n";
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
                    if (!empty($attrs[$key])) $p[] = "{$label}：{$attrs[$key]}";
                }
                foreach ($attrs as $key => $val) {
                    if (isset($attrLabels[$key]) || $key === 'recent_change') continue;
                    if (is_scalar($val) && !empty($val)) $p[] = "{$key}：{$val}";
                }
            }
            if ($p) $lines[] = "· {$name}——" . implode('，', $p);
        }
        return $lines ? "【人物当前状态（必须严格遵守，不得与此矛盾）】\n" . implode("\n", $lines) . "\n\n" : '';
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
        $tropes = getPreviousUsedTropes((int)$this->novel['id'], $this->chNum);
        return $tropes ? "【本章禁止重复使用的意象/场景（近期已用，需要新鲜感）】：" . implode('、', $tropes) . "\n\n" : '';
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

    /** 全书重复意象——提醒 AI 在行文中自然融入 */
    private function buildRecurringMotifsSection(): string
    {
        $prog = $this->getProgress();
        $motifs = $prog['recurring_motifs'] ?? [];
        if (empty($motifs)) return '';
        return "【全书重复意象（本章可自然融入的意象符号）】\n" . implode('、', $motifs) . "\n\n";
    }

    private function buildForeshadowSection(): string
    {
        $pending = $this->memoryCtx['pending_foreshadowing'] ?? [];
        $due = array_filter($pending, fn($f) => !empty($f['deadline']) && $this->chNum >= (int)$f['deadline'] - 3);
        if (empty($due)) return '';
        $lines = [];
        foreach ($due as $f) {
            $dl = (int)$f['deadline'];
            $lines[] = ($this->chNum >= $dl - 2 && $this->chNum <= $dl + 2)
                ? "⚠️【紧急】第{$f['chapter']}章埋：{$f['desc']}（应{$dl}章前回收）"
                : "第{$f['chapter']}章埋：{$f['desc']}（建议{$dl}章前回收）";
        }
        return "【本章应考虑回收的伏笔（已到期或临近，请自然安排回收）】\n" . implode("\n", $lines) . "\n\n";
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
                'SELECT chapter_number, cool_point_type FROM chapters WHERE novel_id=? AND chapter_number<? AND cool_point_type IS NOT NULL ORDER BY chapter_number DESC LIMIT 20',
                [(int)$this->novel['id'], $this->chNum]
            );
            foreach ($rcs as $rc) {
                if (!empty($rc['cool_point_type'])) $history[] = ['chapter'=>(int)$rc['chapter_number'],'type'=>$rc['cool_point_type']];
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
                if ($cnt >= 2) {  // 连续 2+ 章短板才提醒
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

            if (empty($lines)) return '';

            return "【⚠️ 近期写作短板（本章必须修正）】\n" . implode("\n", $lines) . "\n\n";
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function buildAgentSection(): string
    {
        try {
            require_once __DIR__ . '/agents/AgentDirectives.php';
            $dirs = AgentDirectives::active((int)$this->novel['id'], $this->chNum);
            if (empty($dirs)) return '';
            
            // 指令优先级：quality > strategy > optimization
            $priority = ['quality' => 1, 'strategy' => 2, 'optimization' => 3];
            usort($dirs, function($a, $b) use ($priority) {
                $pa = $priority[$a['type']] ?? 99;
                $pb = $priority[$b['type']] ?? 99;
                return $pa <=> $pb;
            });
            
            $lines = [];
            foreach ($dirs as $d) {
                $label = match($d['type']){'quality'=>'质量监控','strategy'=>'写作策略','optimization'=>'优化建议',default=>'Agent指令'};
                $lines[] = "· [{$label}] {$d['directive']}";
            }
            return "\n\n【🤖 Agent 指令（本章写作必须遵循）】\n" . implode("\n", $lines) . "\n";
        } catch (\Throwable) { return ''; }
    }

    // ── User prompt 固定信息 ─────────────────────────────────────

    private function buildNovelInfo(): string
    {
        $parts = [];
        $parts[] = "书名：{$this->novel['title']}  |  类型：{$this->genre}  |  风格：{$this->novel['writing_style']}";
        $parts[] = "主角：{$this->novel['protagonist_info']}";
        $parts[] = "世界观：{$this->novel['world_settings']}";
        
        // extra_settings（额外设定）
        $extraSettings = $this->memoryCtx['L1_global_settings']['extra_settings'] ?? $this->novel['extra_settings'] ?? '';
        if ($extraSettings) $parts[] = "额外设定：{$extraSettings}";
        
        // style_vector（四维风格向量）
        $styleVector = $this->memoryCtx['L1_global_settings']['style_vector'] ?? '';
        if ($styleVector) {
            $sv = is_string($styleVector) ? json_decode($styleVector, true) : $styleVector;
            if ($sv) {
                $svParts = [];
                if (!empty($sv['vec_pacing']))    $svParts[] = "节奏：{$sv['vec_pacing']}";
                if (!empty($sv['vec_emotion']))   $svParts[] = "情感：{$sv['vec_emotion']}";
                if (!empty($sv['vec_intellect'])) $svParts[] = "智慧：{$sv['vec_intellect']}";
                if ($svParts) $parts[] = "风格向量：" . implode('，', $svParts);
            }
        }
        
        // ref_author（参考作者）
        $refAuthor = $this->memoryCtx['L1_global_settings']['ref_author'] ?? '';
        if ($refAuthor) $parts[] = "参考作者风格：{$refAuthor}";
        
        return "【小说信息】\n" . implode("\n", $parts) . "\n\n";
    }

    private function buildOutlineSection(): string
    {
        $kp = '';
        if (!empty($this->chapter['key_points'])) {
            $pts = json_decode($this->chapter['key_points'], true) ?? [];
            if ($pts) $kp = "\n关键情节点：\n- " . implode("\n- ", $pts);
        }
        $hl = !empty($this->chapter['hook']) ? "\n结尾钩子（本章结尾要体现）：{$this->chapter['hook']}" : '';
        return "【本章大纲】\n第{$this->chNum}章：{$this->chapter['title']}\n概要：{$this->chapter['outline']}{$kp}{$hl}\n\n";
    }

    private function buildUserDensitySection(): string
    {
        $d = getDensityGuidelines($this->genre);
        return "【📏 本章描写密度要求（题材：{$this->genre}）】\n{$d}\n请严格按此比例分配各元素篇幅。\n\n";
    }

    private function buildUserHookSection(): string
    {
        $hook  = suggestHookType($this->chapter);
        $cType = $hook['type'];
        $cDesc = (HOOK_TYPES[$cType]['name'] ?? '') . '：' . ($hook['reason'] ?? '默认轮换');
        return "【🎣 本章章末钩子类型】\n指定类型：{$cType}（{$cDesc}）\n结尾必须用该类型制造强烈悬念，绝对禁止平静收尾！\n\n";
    }

    private function buildUserRulesSection(): string
    {
        // v1.5: 复用 getSegmentRatios() 单一来源，消除与 fourSegmentRhythm 的双源冲突
        $r = $this->getSegmentRatios();
        $segSetup  = $r['setup'];
        $segRising = $r['rising'];
        $segClimax = $r['climax'];
        $segHook   = $r['hook'];
        return <<<EOT
【写作铁律——逐条遵守】
1. 【黄金三行】本章前三行必须是动作/对话/悬念/异常之一，禁止纯环境描写开头
2. 【四段节奏】铺垫(~{$segSetup}%)→发展/对话密集区(~{$segRising}%)→高潮/爽点释放(~{$segClimax}%)→钩子收尾(~{$segHook}%)
3. 【情绪密度】必须满足情绪词汇密度标准（愤怒15-20次/万字、喜悦20-30次/万字、惊讶10-15次/万字），高潮段落要加大情绪词密度
4. 对话自然生动，有个性，符合各自性格；对话密度每千字40-80句
5. 连续非对话文字不超过300字，超长段落必须插入对话或动作打断
6. 情节紧凑，张弛有度，不拖沓
7. 结尾使用指定钩子类型制造强烈悬念，引发读者急切想知道下文
8. 与前文衔接自然，保持语气和叙事风格一致
9. 人物职务/身份必须与上方【人物当前状态】完全一致
10. 如有【章节简介】，必须严格遵循其中的场景分解和对话要点

EOT;
    }

    // ── 辅助 ──────────────────────────────────────────────────────

    /** 懒加载 progress context，优先复用 memoryCtx */
    private function getProgress(): array
    {
        if (isset($this->memoryCtx['progress_context'])) {
            return $this->memoryCtx['progress_context'];
        }
        $engine = new MemoryEngine((int)$this->novel['id']);
        return $engine->getProgressContext($this->chNum);
    }
}
