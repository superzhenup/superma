<?php
/**
 * 黄金三章排期组件（审计功能 2026-08-16 P3 · 写作能力缺口 C2）
 *
 * 网文"前三章留住读者"能力的单一来源实现：milestone 模板、核验词表、
 * 写后检测词表、排期倾斜规则全部集中本类，挂载点（prompt.php /
 * OutlineQualityGuard / ChapterPromptBuilder / write_engine）只做薄接入，
 * 禁止在挂载文件中出现词表字面量副本。
 *
 * 三层数据流（详见 docs/superpowers/specs/2026-08-16-golden-three-chapters-design.md）：
 *   一层·大纲生成：buildOutlinePromptSection 软性要求 + scheduleBias 排期倾斜；
 *   二层·确定性核验：verifyOutlineBatch 纯函数核验 + buildRepairMessages 定向返修消息；
 *   三层·写作与写后：buildWritingDirective 写作指令 + verifyWrittenChapter 写后检测。
 *
 * 设计约束：纯静态类，零 DB 依赖、零 require——可独立加载做行为测试。
 * DB 取数一律由调用方完成后以数组传入。
 */
defined('APP_LOADED') or die('Direct access denied.');

class GoldenThreeChapters
{
    /** 金手指线索挖掘词表（命中 extra_settings/plot_settings 即视为有设定线索） */
    private const GOLDEN_FINGER_KEYWORDS = [
        '金手指', '系统', '重生', '穿越', '神器', '异能', '传承',
        '血脉', '至宝', '老爷爷', '随身', '面板', '外挂', '天赋', '机缘',
    ];

    /** 爽点类型中文名（仅展示用；与 rhythm_adjuster typeNames 同口径的子集） */
    private const COOL_TYPE_LABELS = [
        'underdog_win' => '越级战斗胜利',
        'face_slap'    => '打脸反转',
        'treasure_find' => '宝物/奇遇',
        'breakthrough' => '修为突破',
        'power_expand' => '势力扩张',
        'romance_win'  => '红颜倾心',
        'truth_reveal' => '真相揭露',
    ];

    /** 开篇类型 → 第 1 章开篇指引（选项枚举对齐 includes/wizard/schema.php:201-208） */
    private const OPENING_GUIDANCE = [
        '穿越/重生' => '开篇即完成身份转换，用信息差制造第一个爽感预期',
        '意外事件'   => '开篇直接抛出意外事件，并即时改变主角处境',
        '日常切入'   => '从日常场景切入，但前300字内必须出现打破日常的异常信号',
        '悬念开头'   => '开篇第一段即抛出未解之谜，让读者带着问题进入故事',
        '倒叙开头'   => '从高潮/结果场面倒叙切入，再回溯起因',
        '对话开头'   => '以携带冲突信息的对话开场，禁止寒暄式空对话',
        '场景描写'   => '场景描写开篇但必须附着悬念或危机信息，禁止纯风景空镜',
    ];

    /** 主角出场方式 → 第 1 章亮相指引（选项枚举对齐 schema.php:205-208） */
    private const ENTRANCE_GUIDANCE = [
        '弱小起步' => '主角以弱势登场，困境与渴望要具体可见',
        '天赋异禀' => '天赋初次显露要留悬念，不可一次亮尽',
        '隐藏身份' => '身份反差要在本章埋下可感知的线索',
        '被迫卷入' => '被动处境与个人意志的冲突要立刻成立',
        '主动出击' => '主角的主动性与目标感开篇即立住',
        '神秘来历' => '来历之谜作为长线钩子点到即止',
    ];

    /** 第 1-3 章禁止落盘的伪爽点类型（与 generate_outline R11-2 的豁免类型对应） */
    private const FORBIDDEN_COOL_TYPES = ['breather', 'development'];

    /** 通用缺失提示（各章共享的核验项） */
    private const COMMON_MISSING_HINTS = [
        'hook'       => '章末钩子（hook 字段）不能为空',
        'cool_point' => 'cool_point_type 必须为真实爽点类型（不得为 breather/development）',
    ];

    /**
     * 三章里程碑模板（固定通用，全题材）。
     * 约定：第 1 章 requirements 含占位符 {opening} / {entrance} / {goldenfinger}，
     * 由 resolveRequirements() 在构建时替换；第 2/3 章为静态文案。
     */
    private const MILESTONES = [
        1 => [
            'label' => '入坑钩',
            'requirements' => [
                '{opening}',
                '主角身份与困境快速立住（目标、阻碍、资源匮乏）{entrance}',
                '{goldenfinger}',
                '章末强钩子（危机升级/反转/重大悬念）',
            ],
            'recommended'  => ['treasure_find', 'truth_reveal', 'underdog_win'],
            // 大纲核验词组（无金手指设定时的通用亮相组）
            'outline_words' => ['觉醒', '获得', '意外', '发现', '传承', '系统', '机缘', '异变', '开启', '激活'],
            'written_groups' => [
                ['label' => '机遇显现', 'words' => ['觉醒', '获得', '意外', '发现', '传承', '系统', '机缘', '异变', '开启', '激活']],
                ['label' => '困境建立', 'words' => ['被欺', '被迫', '危机', '困境', '绝境', '威胁', '打压']],
            ],
            'missing_hints' => [
                'debut' => '细纲须体现金手指亮相或核心机遇显现（如：觉醒/获得/发现/传承/机缘/开启）',
            ],
        ],
        2 => [
            'label' => '首次兑现',
            'requirements' => [
                '金手指/核心优势首次使用或验证（能力边界初显）',
                '首个小爽点落地（初步打脸/小胜/他人震惊反应）',
                '冲突升级：引出更强的对手或更大的麻烦',
            ],
            'recommended'  => ['face_slap', 'underdog_win', 'power_expand'],
            'outline_words' => ['使用', '试用', '初试', '出手', '施展', '显威', '验证', '第一次', '尝试'],
            'written_groups' => [
                ['label' => '首次使用', 'words' => ['使用', '试用', '初试', '出手', '施展', '显威', '验证', '第一次', '尝试']],
                ['label' => '爽点反应', 'words' => ['震惊', '愣住', '倒吸', '哗然', '瞪大', '不敢置信', '变色']],
            ],
            'missing_hints' => [
                'first_use' => '细纲须体现金手指/核心优势的首次使用或验证（如：使用/初试/出手/施展/显威/第一次）',
            ],
        ],
        3 => [
            'label' => '追读锚点',
            'requirements' => [
                '完整爽点爆发（压抑-释放全流程，非一笔带过）',
                '核心冲突具象化：反派/长期目标首次清晰亮相',
                '主角成长路径可见（读者能预期「接下来会更强」）',
                '章末钩子指向中期主线',
            ],
            'recommended'  => ['underdog_win', 'face_slap', 'breakthrough'],
            'outline_words' => ['对手', '敌人', '仇人', '势力', '宗门', '家族', '目标', '威胁', '宣战', '立誓', '背后', '幕后'],
            'written_groups' => [
                ['label' => '冲突具象化', 'words' => ['对手', '敌人', '仇人', '势力', '宗门', '家族', '目标', '威胁', '宣战', '立誓', '背后', '幕后']],
                ['label' => '爽点爆发', 'words' => ['反击', '反杀', '碾压', '打脸', '逆转', '翻盘', '爆发', '一击']],
            ],
            'missing_hints' => [
                'conflict' => '细纲须体现核心冲突具象化（如：对手/敌人/势力/目标/威胁/宣战/立誓/幕后）',
            ],
        ],
    ];

    // ================================================================
    // 公开接口
    // ================================================================

    /** 总开关（ws_golden_three_enabled，默认开）。独立加载（无 getSystemSetting）时视为开，保持纯类可测。 */
    public static function enabled(): bool
    {
        if (!function_exists('getSystemSetting')) return true;
        try {
            return (bool)getSystemSetting('ws_golden_three_enabled', true, 'bool');
        } catch (\Throwable) {
            return true;
        }
    }

    /** 批次 [fromCh, toCh] 是否与第 1-3 章相交 */
    public static function appliesToBatch(int $fromCh, int $toCh): bool
    {
        return $toCh >= 1 && $fromCh <= 3 && $fromCh <= $toCh;
    }

    /**
     * 第一层：大纲 prompt 的黄金三章结构化要求段。
     * 关闭/异常返回空串（大纲照常生成）；批次适用性（appliesToBatch）由调用方判断。
     */
    public static function buildOutlinePromptSection(array $novel): string
    {
        if (!self::enabled()) return '';
        try {
            $lines = ['【🏆 黄金三章——读者留存关键窗口，必须达成】'];
            foreach (self::MILESTONES as $n => $m) {
                $lines[] = "■ 第{$n}章（{$m['label']}）：" . implode('；', self::resolveRequirements($n, $novel)) . '。';
                $lines[] = '  本章 cool_point_type 必须从 ' . implode(' / ', $m['recommended']) . ' 中选择。';
            }
            $lines[] = '以上三章的 hook 与 cool_point_type 会被确定性核验，未达成将触发定向返修。';
            return implode("\n", $lines) . "\n";
        } catch (\Throwable $e) {
            error_log('GoldenThreeChapters::buildOutlinePromptSection failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 第一层：排期倾斜。
     * 第 1-3 章返回 {force, recommended, forbid}；其余章或关闭时返回 null（调用方零侵入短路）。
     */
    public static function scheduleBias(int $chNum): ?array
    {
        if ($chNum < 1 || $chNum > 3 || !self::enabled()) return null;
        return [
            'force'       => true,
            'recommended' => self::MILESTONES[$chNum]['recommended'],
            'forbid'      => self::FORBIDDEN_COOL_TYPES,
        ];
    }

    /**
     * 第二层：细纲核验（纯函数，无 DB）。
     * 只核验批次内实际存在的第 1-3 章；无法判定的项跳过不计 issue（不误报）。
     *
     * @return array{passed: bool, issues: array<array{chapter:int, missing:string[], hint:string}>}
     */
    public static function verifyOutlineBatch(array $outlines, array $novel): array
    {
        $issues = [];
        $gfKeywords = self::mineGoldenFingerKeywords($novel);

        foreach ($outlines as $item) {
            if (!is_array($item)) continue;
            $chNum = (int)($item['chapter_number'] ?? 0);
            if ($chNum < 1 || $chNum > 3) continue;

            $m = self::MILESTONES[$chNum];
            $missing = [];

            if (trim((string)($item['hook'] ?? '')) === '') {
                $missing[] = 'hook';
            }

            $cpt = trim((string)($item['cool_point_type'] ?? ''));
            if ($cpt === '' || in_array($cpt, self::FORBIDDEN_COOL_TYPES, true)) {
                $missing[] = 'cool_point';
            }

            $text = self::outlineText($item);
            if ($chNum === 1) {
                // 有金手指设定 → 命中设定实体词；无设定 → 通用亮相组
                $words = !empty($gfKeywords) ? $gfKeywords : $m['outline_words'];
                if (!self::hitAny($text, $words)) $missing[] = 'debut';
            } elseif ($chNum === 2) {
                if (!self::hitAny($text, $m['outline_words'])) $missing[] = 'first_use';
            } else {
                $conflictText = $text . "\n" . trim((string)($item['conflict'] ?? ''));
                if (!self::hitAny($conflictText, $m['outline_words'])) $missing[] = 'conflict';
            }

            if (!empty($missing)) {
                $issues[] = [
                    'chapter' => $chNum,
                    'missing' => $missing,
                    'hint'    => self::missingHint($chNum, $missing),
                ];
            }
        }

        return ['passed' => empty($issues), 'issues' => $issues];
    }

    /**
     * 第二层：定向返修消息（镜像 OutlineQualityGuard::enforceTurningPoints 的消息结构）。
     *
     * @param array $outlines 本批细纲（原样）
     * @param array $issues   verifyOutlineBatch 返回的 issues
     * @return array [ ['role'=>'system',...], ['role'=>'user',...] ]
     */
    public static function buildRepairMessages(array $outlines, array $issues, array $novel): array
    {
        $count = count($outlines);
        $missList = implode("\n", array_map(
            static fn(array $i): string => $i['hint'] ?? ('- 第' . ($i['chapter'] ?? 0) . '章需补里程碑'),
            $issues
        ));
        // 有金手指线索时给返修提供实体信息，避免 AI 编造
        $gfNote = '';
        $hint = self::mineGoldenFingerHint($novel);
        if ($hint !== null) {
            $gfNote = "\n（本书金手指线索：{$hint}——第1章的亮相项应围绕它展开。）";
        }
        $batchJson = json_encode(array_values($outlines), JSON_UNESCAPED_UNICODE);

        return [
            ['role' => 'system', 'content' => '你是网文大纲修订师。只输出纯 JSON 数组，无前缀后缀无 markdown。'],
            ['role' => 'user', 'content' =>
                "以下是已生成的本批章节细纲（JSON 数组，共{$count}条）：\n{$batchJson}\n\n"
                . "但下列【黄金三章开篇里程碑】未在对应章节细纲中体现：\n{$missList}{$gfNote}\n\n"
                . "请只修订对应章节的细纲，把缺失的里程碑自然融入（调整该章的 summary / key_points / hook / conflict / cool_point_type，"
                . "保持其它章节与原有字段结构不变），输出修订后的**完整批次** JSON 数组（仍为{$count}条，chapter_number 不变）。"],
        ];
    }

    /**
     * 第三层：第 1-3 章写作指令段（挂 ChapterPromptBuilder user 区）。
     * chNum>3 或关闭返回空串。
     */
    public static function buildWritingDirective(int $chNum, array $chapter, array $novel): string
    {
        if ($chNum < 1 || $chNum > 3 || !self::enabled()) return '';
        try {
            $m = self::MILESTONES[$chNum];
            $lines = ["【🏆 黄金三章·第{$chNum}章·{$m['label']}——本章必须达成】"];
            foreach (self::resolveRequirements($chNum, $novel) as $req) {
                $lines[] = "- {$req}";
            }

            $cpt = trim((string)($chapter['cool_point_type'] ?? ''));
            if ($cpt !== '') {
                $label = self::COOL_TYPE_LABELS[$cpt] ?? $cpt;
                $lines[] = "- 本章规划爽点类型：{$cpt}（{$label}）——必须完整兑现，禁止弱化为一笔带过。";
            }
            $hook = trim((string)($chapter['hook'] ?? ''));
            if ($hook !== '') {
                $lines[] = "- 本章规划钩子：{$hook}";
            }
            return implode("\n", $lines) . "\n";
        } catch (\Throwable $e) {
            error_log('GoldenThreeChapters::buildWritingDirective failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 第三层：写后检测（纯关键词启发式，每组至少命中 1 词）。
     * 返回 {passed, misses[]}；无法判定（章号越界/正文过短）返回 null——不误报。
     */
    public static function verifyWrittenChapter(int $chNum, string $content, array $chapter): ?array
    {
        if ($chNum < 1 || $chNum > 3) return null;
        $content = trim($content);
        if (mb_strlen($content) < 200) return null;

        $misses = [];
        foreach (self::MILESTONES[$chNum]['written_groups'] as $group) {
            if (!self::hitAny($content, $group['words'])) {
                $misses[] = $group['label'];
            }
        }
        return ['passed' => empty($misses), 'misses' => $misses];
    }

    // ================================================================
    // 私有辅助
    // ================================================================

    /**
     * 金手指线索挖掘：优先读结构化设定（调用方并入 $novel['golden_finger']，
     * 来自 GoldenFinger::get()——write_engine/generate_outline 已注入），
     * 实体词 = 金手指名称；无结构化设定回退 extra_settings/plot_settings 文本挖掘。
     */
    private static function mineGoldenFingerKeywords(array $novel): array
    {
        // P3-金手指（2026-08-16）：结构化优先
        $gf = $novel['golden_finger'] ?? null;
        if (is_array($gf) && !empty($gf['name'])) {
            return [trim((string)$gf['name'])];
        }

        $hits = [];
        foreach (['extra_settings', 'plot_settings'] as $field) {
            $text = (string)($novel[$field] ?? '');
            if ($text === '') continue;
            foreach (self::GOLDEN_FINGER_KEYWORDS as $kw) {
                if (mb_strpos($text, $kw) !== false && !in_array($kw, $hits, true)) {
                    $hits[] = $kw;
                }
            }
        }
        return $hits;
    }

    /**
     * 金手指线索挖掘（点名文案用）：优先结构化设定（名称+获得场景，≤40字），
     * 无结构化设定回退 extra_settings/plot_settings 文本挖掘的上下文片段。
     */
    private static function mineGoldenFingerHint(array $novel): ?string
    {
        // P3-金手指（2026-08-16）：结构化优先——点名用名称，带获得场景更有指向性
        $gf = $novel['golden_finger'] ?? null;
        if (is_array($gf) && !empty($gf['name'])) {
            $name  = trim((string)$gf['name']);
            $scene = trim((string)($gf['acquire_scene'] ?? ''));
            if ($scene !== '') {
                $hint = $name . '：' . $scene;
                return mb_strlen($hint) > 40 ? mb_substr($hint, 0, 40) : $hint;
            }
            return $name;
        }

        foreach (['extra_settings', 'plot_settings'] as $field) {
            $text = (string)($novel[$field] ?? '');
            if ($text === '') continue;
            foreach (self::GOLDEN_FINGER_KEYWORDS as $kw) {
                $pos = mb_strpos($text, $kw);
                if ($pos === false) continue;
                $start = max(0, $pos - 12);
                $frag = trim((string)preg_replace('/[\r\n]+/u', ' ', mb_substr($text, $start, mb_strlen($kw) + 24)));
                if ($frag === '') return $kw;
                return mb_strlen($frag) > 40 ? mb_substr($frag, 0, 40) : $frag;
            }
        }
        return null;
    }

    /** 第 1 章 requirements 占位符替换（第 2/3 章原样返回） */
    private static function resolveRequirements(int $chNum, array $novel): array
    {
        $reqs = self::MILESTONES[$chNum]['requirements'];
        if ($chNum !== 1) return $reqs;

        $opening  = trim((string)($novel['opening_type'] ?? ''));
        $entrance = trim((string)($novel['protagonist_entrance'] ?? ''));

        $openingText = $opening !== ''
            ? "开篇承接「{$opening}」：" . self::openingGuidance($opening)
            : '开篇尽快抛出核心悬念或危机';

        $entranceText = '';
        if ($entrance !== '') {
            $entranceText = '。出场方式「' . $entrance . '」：' . (self::ENTRANCE_GUIDANCE[$entrance] ?? '出场即立住目标与处境');
        }

        $gfHint = self::mineGoldenFingerHint($novel);
        $gfText = $gfHint !== null
            ? "金手指亮相——本章必须让「{$gfHint}」亮相或初次显效"
            : '金手指亮相或核心机遇显现（觉醒、获得、传承、机缘类事件）';

        return [
            $openingText,
            str_replace('{entrance}', $entranceText, $reqs[1]),
            $gfText,
            $reqs[3],
        ];
    }

    /** opening_type → 指引文案（未匹配回退通用） */
    private static function openingGuidance(string $openingType): string
    {
        return self::OPENING_GUIDANCE[$openingType] ?? '开篇尽快抛出核心悬念或危机';
    }

    /** 细纲可核验文本：summary + key_points（key_points 兼容数组/字符串） */
    private static function outlineText(array $item): string
    {
        $kp = $item['key_points'] ?? '';
        if (is_array($kp)) $kp = implode('；', array_map('strval', $kp));
        return trim((string)($item['summary'] ?? '')) . "\n" . trim((string)$kp);
    }

    /** 词组命中：任一命中即 true */
    private static function hitAny(string $text, array $words): bool
    {
        if ($text === '') return false;
        foreach ($words as $w) {
            if (is_string($w) && $w !== '' && mb_strpos($text, $w) !== false) return true;
        }
        return false;
    }

    /** 组装单条 issue 的人类可读提示 */
    private static function missingHint(int $chNum, array $missing): string
    {
        $m = self::MILESTONES[$chNum];
        $parts = [];
        foreach ($missing as $code) {
            $parts[] = self::COMMON_MISSING_HINTS[$code]
                ?? $m['missing_hints'][$code]
                ?? $code;
        }
        return "- 第{$chNum}章（{$m['label']}）需补：" . implode('；', $parts);
    }
}
