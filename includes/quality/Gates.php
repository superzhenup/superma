<?php
/**
 * 章节质量五关检测函数集
 *
 * 修复（2026-06-16）：原先这些函数定义在 api/validate_consistency.php，
 * includes/write_engine.php::runQualityGates() 通过 require_once API 文件
 * 反向加载——违反了 includes 不应依赖 api 的分层约束（api 文件含
 * `header()` / `requireLoginApi()` 等 HTTP 副作用，CLI worker 加载它需要
 * 用 CLI_MODE 常量做条件守卫，极易出错）。
 *
 * 现把 5 个 Gate 函数与辅助函数下沉到 includes 层，api 与 WriteEngine
 * 都从这里加载，api/validate_consistency.php 仅保留 HTTP 入口逻辑。
 */
defined('APP_LOADED') or die('Direct access denied.');

if (!function_exists('qualityGatePassScore')) {
    /**
     * 展示型质量关通过线（百分制）。
     * Gate1 结构、Gate3 描写用此旋钮。Gate2 角色缺席、Gate5 严重因果、Gate6 跟纲
     * 仍用固定 70，保证 −35/−40 罚分能让 status 失败并驱动硬拦。
     */
    function qualityGatePassScore(): float
    {
        // 审计修复 P2（2026-09-27）：任务启动时由入口（worker/SSE/daemon）经
        // WriteEngine::freezeQualityGateMinScore() 冻结的通过线优先——同任务
        // 的五关判定不得随配置漂移（原实现每次 Gate 判定实时重读，任务运行
        // 期间管理员改配置会使同章质检与生成承诺使用不同标准）。
        // 未冻结场景（手动校验页/独立调用）回退实时配置，行为不变。
        if (class_exists('WriteEngine', false)) {
            $frozen = WriteEngine::frozenQualityGateMinScore();
            if ($frozen !== null) {
                return $frozen;
            }
        }
        $raw = 70.0;
        try {
            if (function_exists('getSystemSetting')) {
                $raw = (float)getSystemSetting('ws_quality_min_score', 6.0, 'float') * 10;
            }
        } catch (\Throwable $e) {
            $raw = 70.0;
        }
        return max(50.0, min(90.0, $raw));
    }
}

if (!function_exists('checkGate1_Structure')) {
    /**
     * 第1关：🏗 结构检查
     */
    function checkGate1_Structure(array $ch, string $content): array
    {
        $issues = [];
        $score  = 100;

        $minScoreThreshold = qualityGatePassScore();

        // 修复（2026-06-18）：字数统计与前端 wc-current 保持一致
        // 原用 mb_strlen 包含标点/空格/换行/数字，与前端 countChapterWords（仅中文+英文词）差异大
        // 现改用 countWords()，与 includes/helpers.php::countWords 一致
        $len             = countWords($content);
        $target          = (int)$ch['chapter_words'];
        // W-9 修复：novel.chapter_words 为 0/null 时回退到全局 ws_chapter_words 设置
        if ($target <= 0) {
            $target = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
        }
        // 审计修复（2026-08-17）：与 Prompt / 流式完成检查共用 calculateDynamicTolerance
        // （默认 ±10%，夹紧 100–500）。此前 Gate1 走固定 ws_chapter_word_tolerance=150
        // 或 CFG_TOLERANCE_RATIO=3%，质检口径与写作承诺不一致。
        $tolerance = 0;
        if ($target > 0) {
            $novelId = (int)($ch['novel_id'] ?? 0);
            if (function_exists('calculateDynamicTolerance')) {
                $tol = calculateDynamicTolerance($target, $novelId > 0 ? $novelId : null);
                $tolerance = (int)($tol['tolerance'] ?? 0);
            } else {
                $ratioPct = (int)getSystemSetting('ws_dynamic_tolerance_ratio', 10, 'int');
                if ($novelId > 0 && class_exists('ConfigCenter', false)) {
                    $ratioPct = (int)ConfigCenter::getNovel($novelId, 'ws_dynamic_tolerance_ratio', $ratioPct, 'int');
                }
                $ratio = max(5, min(25, $ratioPct)) / 100;
                $minTol = (int)getSystemSetting('ws_min_tolerance', 100, 'int');
                $maxTol = (int)getSystemSetting('ws_max_tolerance', 500, 'int');
                $tolerance = max($minTol, min($maxTol, (int)($target * $ratio)));
            }
        }
        if ($target > 0 && ($len < $target - $tolerance || $len > $target + $tolerance)) {
            $pct = $tolerance > 0 ? round($tolerance / $target * 100) : 0;
            $issues[] = "字数{$len}字超出目标范围（{$target}±{$tolerance}字/±{$pct}%）";
            $score -= 30;
        }

        // 审计优化（2026-07-24）：与 prompt「黄金三行≈前90字」对齐，不再用前200字放水
        $firstLines = getOpeningProbe($content, 90);

        $hasAction   = preg_match('/(动手|冲出|冲向|大喊|吼道|突然|猛地|一把|瞬间|立刻|拔出|挥动|闪身|跃起|扑向|一拳|一脚|拔腿|转身|扑倒|劈|斩|踢|撞)/u', $firstLines);
        $hasSensory  = preg_match('/(看到|听见|闻到|感觉到|触到|瞥见|隐约|依稀|猛然|赫然|竟|赫然发现|眼前|耳边|鼻尖|手心)/u', $firstLines);
        $hasDialogue = preg_match('/[「『“"].*[」』”"]/u', $firstLines);
        $hasAbnormal = preg_match('/(奇怪|异常|惊恐|震惊|不敢相信|居然|竟然|不料|没想到|怎么回事|为何|难道|谁|怎么|不对劲|诡异)/u', $firstLines);
        $hasCrisis   = preg_match('/(危险|快跑|小心|救命|不好|糟了|完蛋|死定了|来不及|命悬一线|杀气|追杀)/u', $firstLines);
        $pureEnv     = (bool)preg_match('/^(阳光|月光|晨光|朝阳|夕阳|夜色|星空|乌云|细雨|大雨|雪花|微风|春风|秋风|街道安静|房间里|大厅中|天空中|远山|云层)/u', $firstLines);

        if ((!$hasAction && !$hasSensory && !$hasDialogue && !$hasAbnormal && !$hasCrisis) || ($pureEnv && !$hasAction && !$hasDialogue && !$hasCrisis)) {
            $issues[] = "⚠️ 黄金三行未达标：开篇前三行（约90字）缺乏动作/感官/对话/异常/危机，或偏纯环境空镜";
            $score -= 15;
        }

        $lastPara = getLastParagraph($content);
        // 最终章/收束落幕允许平静有分量的结尾
        $isResolutionEnding = (bool)preg_match('/(完结|落幕|终章|从此以后|故事到此|画上句号|余韵|尘埃落定|画上了句号)/u', $lastPara);
        $calmPatterns = [
            '/^(大家|众人|众人皆|夜深了|天亮了|一切都|从此|就这样|后来)/u',
            '/^(这一|那个|很快|不久|日子)/u',
        ];
        if (!$isResolutionEnding) {
            foreach ($calmPatterns as $p) {
                if (preg_match($p, trim($lastPara))) {
                    $issues[] = "❌ 结尾过于平淡，疑似平静句收尾";
                    $score -= 25;
                    break;
                }
            }
        }

        return [
            'name'   => '🏗 结构检查',
            'status' => $score >= $minScoreThreshold,
            'score'  => max(0, $score),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('getOpeningProbe')) {
    /**
     * 取开篇探针文本：优先前三行非空行，压缩空白后截到 $maxChars（默认90，对齐黄金三行）。
     */
    function getOpeningProbe(string $content, int $maxChars = 90): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $picked = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            $picked[] = $t;
            if (count($picked) >= 3) break;
        }
        $joined = implode('', $picked);
        if (mb_strlen($joined) < 12) {
            $joined = preg_replace('/\s+/u', '', $content) ?? $content;
        } else {
            $joined = preg_replace('/\s+/u', '', $joined) ?? $joined;
        }
        return mb_substr($joined, 0, max(20, $maxChars));
    }
}

if (!function_exists('checkGate2_Characters')) {
    function checkGate2_Characters(int $novelId, string $content): array
    {
        $issues = [];
        $score  = 100;

        $characters = DB::fetchAll(
            'SELECT c.name, card.alive FROM novel_characters c
             LEFT JOIN character_cards card ON card.novel_id=c.novel_id AND card.name=TRIM(c.name)
             WHERE c.novel_id=? AND c.role_type IN ("protagonist","major")',
            [$novelId]
        );

        if (!empty($characters)) {
            $livingCharacters = [];
            foreach ($characters as $char) {
                $name = trim($char['name']);
                if ($name === '') continue;
                if ($char['alive'] !== null && (int)$char['alive'] === 0) {
                    continue;
                }
                $livingCharacters[] = $name;
            }

            $mentioned = 0;
            foreach ($livingCharacters as $name) {
                if (mb_strpos($content, $name) !== false) {
                    $mentioned++;
                }
            }

            $ratio = count($livingCharacters) > 0 ? ($mentioned / count($livingCharacters)) : 1;
            // 审计修复（2026-07-19 H-15）：$ratio 恒为 float，`$ratio === 0` 恒 false 是死分支；
            // 且全员缺席判定必须前置，否则小角色阵容（1-2人）全员缺席时两分支都不命中、零扣分通过
            // 审计修复（2026-08-13）：原 -10 罚分使 score=90 仍 ≥70 阈值，角色门对全员缺席
            // 永不 fail、形同虚设。提至 -35 使 score=65<70，让零角色出场成为可阻断的质量问题。
            if ($mentioned === 0 && count($livingCharacters) >= 1) {
                $issues[] = "所有主要角色均未在本章中出现";
                $score -= 35;
            } elseif ($ratio < 0.3 && count($livingCharacters) > 2) {
                $missing = count($livingCharacters) - $mentioned;
                $issues[] = "主要角色出场率偏低({$mentioned}/" . count($livingCharacters) . ")，{$missing}个角色未出现";
                $score -= 15;
            }
        } else {
            $issues[] = "ℹ️ 未设置角色库，跳过检测";
        }

        return [
            'name'   => '👥 角色检查',
            'status' => $score >= 70,
            'score'  => max(0, $score),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('checkGate3_Description')) {
    function checkGate3_Description(?string $genre, string $content): array
    {
        $issues = [];
        $score  = 100;

        // 审计优化（2026-07-24）：分母用 countWords 与写作统计一致；阈值与 prompt 对齐（≥25句/千字，连续非对话≤300）
        // 审计修复（2026-08-09 R1）：旧正则要求闭合引号后跟标点且带 /s，中文「句号在引号内」的标准对话
        // （如「你好。」）实测 0 命中，导致正常章被误判密度过低。改为不跨引号的封闭匹配，与 PostWriteValidator 对齐。
        preg_match_all('/[「『“][^「」『』“”]*[」』”]/u', $content, $matches);
        $dialogueCount = count($matches[0]);
        $wordCount     = countWords($content);
        $density       = $wordCount > 0 ? round($dialogueCount * 1000 / $wordCount, 1) : 0;

        if ($density < 25 && $wordCount > 500) {
            $issues[] = "对话密度过低（约{$density}句/千字，要求≥25）";
            $score -= 15;
        } else {
            $issues[] = "✓ 对话密度约{$density}句/千字";
        }

        $paragraphs = preg_split('/\n\s*\n/u', $content);
        $maxNonDialogLen = 0;
        foreach ($paragraphs as $para) {
            $cleanPara = trim($para);
            if (empty($cleanPara)) continue;
            $hasDialog = preg_match('/[「『“][^「」『』“”]*[」』”]/u', $cleanPara);
            if (!$hasDialog) {
                $plen = countWords($cleanPara);
                $maxNonDialogLen = max($maxNonDialogLen, $plen);
            }
        }

        if ($maxNonDialogLen > 300) {
            $issues[] = "存在超长非对话段落({$maxNonDialogLen}字)，要求≤300";
            $score -= 15;
        } elseif ($maxNonDialogLen > 260) {
            $issues[] = "⚠️ 最长非对话段落接近上限({$maxNonDialogLen}字)";
            $score -= 5;
        }

        $nonEmptyParas = array_filter($paragraphs, fn($p) => trim($p) !== '');
        if (!empty($nonEmptyParas)) {
            $avgParaLen = round(array_sum(array_map('countWords', $nonEmptyParas)) / count($nonEmptyParas));
            if ($avgParaLen > 350) {
                $issues[] = "平均段落偏长({$avgParaLen}字)，建议150-300";
                $score -= 10;
            }
        }

        return [
            'name'   => '📝 描写检查',
            'status' => $score >= qualityGatePassScore(),
            'score'  => max(0, $score),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('checkGate4_CoolPoint')) {
    /**
     * @param array|null $chapter 可选章节行（cool_point_type 等），用于呼吸章豁免
     */
    function checkGate4_CoolPoint(string $content, ?string $outline, ?array $chapter = null): array
    {
        $issues = [];
        $score  = 80;

        $coolSignals = [
            'underdog_win'  => ['击败|战胜|打败|完胜|碾压|越级.*战胜|以弱胜强', '越级胜利信号'],
            'face_slap'     => ['震惊|不敢相信|呆住|愣住|脸色惨变|后悔莫及|全场寂静|鸦雀无声|难以置信|目瞪口呆', '打脸反转信号'],
            'treasure_find' => ['获得|得到|发现.*宝|捡到|天材地宝|意外收获|寻得|夺得', '奇遇获得信号'],
            'breakthrough'  => ['突破|晋级|晋升|提升|境界.*突破|气息暴涨|修为暴涨|实力大涨|连升', '突破升级信号'],
            'power_expand'  => ['势力扩大|收服|归顺|加入|一统|称霸|掌控|吞并|兼并', '势力扩张信号'],
            'romance_win'   => ['倾心|芳心|心动|表白|相拥|深情|眷恋|情意|爱慕', '情感升温信号'],
        ];

        $foundSignals = [];
        foreach ($coolSignals as $sig) {
            if (preg_match('/' . $sig[0] . '/u', $content)) {
                $foundSignals[] = $sig[1];
            }
        }

        // 审计修复（2026-08-09 R10-8）：呼吸/过渡章软通过；其余缺信号应可 fail
        $coolType = trim((string)($chapter['cool_point_type'] ?? ''));
        $isBreather = in_array($coolType, ['breather', 'development'], true)
            || (bool)preg_match('/呼吸章|蓄力章|过渡章|日常章|铺垫章/u', (string)$outline);

        if (empty($foundSignals)) {
            if ($isBreather) {
                $issues[] = "ℹ️ 过渡/呼吸章，未强制要求爽点信号";
                $score -= 5;
            } else {
                $issues[] = "未检测到明显爽点信号（非呼吸章应至少有一类爽点兑现）";
                $score -= 25; // 80-25=55 → status fail
            }
        } else {
            $bonus = min(12, count($foundSignals) * 4);
            $score += $bonus;
            $issues[] = "✓ 检测到 " . implode(' / ', $foundSignals);
        }

        return [
            'name'   => '💥 爽点检查',
            'status' => $score >= 60,
            'score'  => min(100, max(0, $score)),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('checkGate5_Consistency')) {
    function checkGate5_Consistency(int $chapterId, int $novelId, string $content): array
    {
        $issues = [];
        $score  = 100;

        $chNum = (int)(DB::fetchColumn('SELECT chapter_number FROM chapters WHERE id=?', [$chapterId]) ?? 0);

        if ($chNum <= 1) {
            return [
                'name'   => '🔗 连贯性检查',
                'status' => true,
                'score'  => 100,
                'issues' => ['第一章，跳过连贯性检测'],
            ];
        }

        $prevTail = DB::fetchColumn(
            'SELECT content FROM chapters WHERE novel_id=? AND chapter_number=? AND status="completed"',
            [$novelId, $chNum - 1]
        );

        if ($prevTail) {
            $currStart = mb_substr($content, 0, 120);

            $transitionWords = [
                '随后', '接着', '此时', '与此同时', '次日',
                '当天', '片刻后', '不久', '正当', '就在这时',
                '话说', '且说', '另一边', '而',
            ];
            $hasTransition = false;
            foreach ($transitionWords as $tw) {
                if (mb_strpos($currStart, $tw) !== false) {
                    $hasTransition = true;
                    break;
                }
            }

            if (!$hasTransition) {
                $issues[] = "ℹ️ 开头未检测到明确的时间/场景过渡词（请确认是否需要）";
                $score -= 3;
            } else {
                $issues[] = "✓ 检测到过渡词";
            }
        } else {
            $issues[] = "ℹ️ 前章无内容或状态非completed，跳过衔接检查";
        }

        try {
            $pendingFs = DB::fetchAll(
                'SELECT description, deadline_chapter FROM foreshadowing_items 
                 WHERE novel_id=? AND resolved_at IS NULL 
                 ORDER BY deadline_chapter ASC LIMIT 5',
                [$novelId]
            );

            if (!empty($pendingFs)) {
                $nearbyFs = array_filter($pendingFs, function ($f) use ($chNum) {
                    $dl = (int)($f['deadline_chapter'] ?? 0);
                    return $dl > 0 && abs($dl - $chNum) <= 3;
                });
                if (!empty($nearbyFs)) {
                    $count = count($nearbyFs);
                    $issues[] = "⚠️ 有 {$count} 个伏笔临近回收截止章（±3章内），请注意安排回收";
                    $score -= 5;
                } else {
                    $totalPending = count($pendingFs);
                    if ($totalPending > 0) {
                        $issues[] = "ℹ️ 当前有 {$totalPending} 个待回收伏笔";
                    }
                }
            }
        } catch (Throwable $e) {
            // 伏笔表可能不存在或结构不同，静默忽略
        }

        try {
            require_once dirname(__DIR__) . '/CausalChainGuard.php';
            $guard = new CausalChainGuard($novelId, $chNum, '');
            foreach ($guard->validateWrittenContent($content) as $conflict) {
                $sev = (string)($conflict['severity'] ?? '');
                $desc = (string)($conflict['desc'] ?? '');
                if ($desc === '') continue;
                $issues[] = ($sev === '严重' ? '❌ ' : '⚠️ ') . $desc;
                $score -= ($sev === '严重') ? 40 : 12;
            }
        } catch (Throwable $e) {
            // The caller owns strict/warn policy. Returning 100 here would
            // misrepresent an unavailable dependency as a successful check.
            throw $e;
        }

        return [
            'name'   => '🔗 连贯性检查',
            'status' => $score >= 70,
            'score'  => max(0, $score),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('outlineAdherenceTokens')) {
    /**
     * 从大纲/情节点抽出可在正文中核对的短词（人名、地点、动作块）。
     *
     * @return string[]
     */
    function outlineAdherenceTokens(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];
        $stop = [
            '然后', '开始', '继续', '进行', '出现', '来到', '离开', '之后', '之前',
            '本章', '主角', '他们', '我们', '一个', '这个', '那个', '以及', '并且',
            '但是', '因为', '所以', '如果', '已经', '可以', '不是', '没有', '就是',
            '还是', '自己', '什么', '怎么', '为了', '时候', '现在', '突然', '终于',
            '于是', '只是', '还要', '需要', '必须', '应当', '可能', '或者', '而且',
            '不过', '虽然', '尽管', '同时', '随后', '接着', '此时', '当天', '次日',
            '正文', '描写', '情节', '冲突', '高潮', '铺垫', '结尾', '开篇',
        ];
        $tokens = [];
        if (preg_match_all('/[「『“"《](.{1,12})[」』”"》]/u', $text, $quoted)) {
            foreach ($quoted[1] as $q) {
                $q = trim((string)$q);
                if (mb_strlen($q) >= 2) $tokens[] = $q;
            }
        }
        if (preg_match_all('/[\x{4e00}-\x{9fff}]{2,6}/u', $text, $words)) {
            foreach ($words[0] as $w) {
                if (in_array($w, $stop, true)) continue;
                $tokens[] = $w;
            }
        }
        $tokens = array_values(array_unique($tokens));
        usort($tokens, static fn($a, $b) => mb_strlen((string)$b) <=> mb_strlen((string)$a));
        return array_slice($tokens, 0, 6);
    }
}

if (!function_exists('checkGate6_OutlineAdherence')) {
    /**
     * 第6关：跟纲检查——关键情节点是否在正文中兑现（启发式，不要求逐字）。
     */
    function checkGate6_OutlineAdherence(array $ch, string $content): array
    {
        $issues = [];
        $score  = 100;
        $outline = trim((string)($ch['outline'] ?? ''));
        $keyPoints = $ch['key_points'] ?? [];
        if (is_string($keyPoints)) {
            $decoded = json_decode($keyPoints, true);
            $keyPoints = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($keyPoints)) {
            $keyPoints = [];
        }
        $keyPoints = array_values(array_filter(
            array_map(static fn($p) => trim((string)$p), $keyPoints),
            static fn($p) => $p !== ''
        ));

        if ($outline === '' && $keyPoints === []) {
            return [
                'name'   => '跟纲检查',
                'status' => true,
                'score'  => 100,
                'issues' => ['无大纲/情节点，跳过'],
            ];
        }

        $checked = 0;
        $hit = 0;
        $missed = [];
        $points = $keyPoints;
        if ($points === [] && $outline !== '') {
            $points = [$outline];
        }

        foreach ($points as $point) {
            $tokens = outlineAdherenceTokens((string)$point);
            if ($tokens === []) continue;
            $checked++;
            $matched = 0;
            foreach ($tokens as $tok) {
                if ($tok !== '' && mb_strpos($content, $tok) !== false) {
                    $matched++;
                }
            }
            $need = count($tokens) <= 2 ? 1 : (int)ceil(count($tokens) * 0.4);
            if ($matched >= $need) {
                $hit++;
            } else {
                $missed[] = mb_substr((string)$point, 0, 24);
            }
        }

        if ($checked > 0) {
            $ratio = $hit / $checked;
            if ($ratio < 0.34) {
                $score -= 35;
                $issues[] = '正文与大纲关键情节点覆盖偏低（' . $hit . '/' . $checked . '）：'
                    . implode('；', array_slice($missed, 0, 3));
            } elseif ($ratio < 0.6) {
                $score -= 15;
                $issues[] = '部分情节点未在正文兑现：' . implode('；', array_slice($missed, 0, 3));
            } else {
                $issues[] = '✓ 大纲情节点覆盖 ' . $hit . '/' . $checked;
            }
        }

        return [
            'name'   => '跟纲检查',
            'status' => $score >= 70,
            'score'  => max(0, min(100, $score)),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('getLastParagraph')) {
    /** 获取最后一段文本（用于结尾钩子检测） */
    function getLastParagraph(string $text): string
    {
        $paras = preg_split('/\n\s*\n/u', $text);
        $paras = array_filter($paras, fn($p) => trim($p) !== '');
        return empty($paras) ? '' : trim(end($paras));
    }
}

if (!function_exists('generateSummary')) {
    /** 生成汇总文案 */
    function generateSummary(array $results): string
    {
        $passed = count(array_filter($results, fn($r) => $r['status']));
        $total  = count($results);

        if ($passed === $total) {
            $avgScore = round(array_sum(array_column($results, 'score')) / $total, 0);
            return "✅ 全部通过！本章质量评分 {$avgScore}/100。";
        }

        $failNames = array_map(fn($r) => $r['name'], array_filter($results, fn($r) => !$r['status']));
        return "⚠️ {$passed}/{$total} 通过。需关注：" . implode('、', $failNames);
    }
}

if (!function_exists('checkGate7_HardQuality')) {
    /**
     * 第7关：落盘硬质检。
     *
     * 与 WriteEngine 的落盘判定保持同一语义：跟纲严重不足、因果链严重问题，
     * 或正文低于小说承诺字数下限，均作为需要明确修复的硬警告。硬质检只给
     * 出警告与改写依据，不负责改变章节状态。
     *
     * @param array<int,array<string,mixed>> $softResults
     */
    function checkGate7_HardQuality(
        int $novelId,
        array $ch,
        string $content,
        array $softResults = []
    ): array {
        $issues = [];

        $outlineGate = null;
        $consistencyGate = null;
        foreach ($softResults as $gate) {
            $name = (string)($gate['name'] ?? '');
            if (str_contains($name, '跟纲')) {
                $outlineGate = $gate;
            } elseif (str_contains($name, '连贯') || str_contains($name, '一致')) {
                $consistencyGate = $gate;
            }
        }
        $outlineGate ??= checkGate6_OutlineAdherence($ch, $content);
        $chapterId = (int)($ch['id'] ?? 0);
        if ($consistencyGate === null && $chapterId > 0) {
            $consistencyGate = checkGate5_Consistency($chapterId, $novelId, $content);
        }

        if (empty($outlineGate['status'])) {
            $detail = implode('；', array_slice((array)($outlineGate['issues'] ?? []), 0, 2));
            $issues[] = '跟纲不足' . ($detail !== '' ? "：{$detail}" : '');
        }

        if (is_array($consistencyGate) && empty($consistencyGate['status'])) {
            $severeIssues = array_values(array_filter(
                (array)($consistencyGate['issues'] ?? []),
                static fn($issue): bool => str_contains((string)$issue, '❌')
            ));
            if ($severeIssues !== []) {
                $issues[] = '因果链严重问题：' . implode('；', array_slice($severeIssues, 0, 2));
            }
        }

        $targetWords = (int)($ch['chapter_words'] ?? 0);
        $blockShort = !class_exists('ConfigCenter', false)
            || ConfigCenter::getNovel($novelId, 'ws_block_short_chapter', true, 'bool');
        if ($blockShort && $targetWords > 0) {
            if (!function_exists('calculateDynamicTolerance')) {
                require_once dirname(__DIR__) . '/prompt.php';
            }
            $tolerance = calculateDynamicTolerance($targetWords, $novelId);
            $minWords = (int)($tolerance['min'] ?? 0);
            $words = countWords($content);
            if ($minWords > 0 && $words < $minWords) {
                $issues[] = sprintf(
                    '未达承诺字数：正文%d字低于承诺区间下限%d字（目标%d字）',
                    $words,
                    $minWords,
                    $targetWords
                );
            }
        }

        $reason = implode('；', $issues);
        $result = [
            'name'   => '落盘硬质检',
            'status' => $issues === [],
            'score'  => $issues === [] ? 100 : 0,
            'issues' => $issues === [] ? ['✓ 未发现落盘级硬伤'] : $issues,
        ];
        if ($reason !== '') {
            $result['hard_fail_reason'] = $reason;
        }
        return $result;
    }
}

if (!function_exists('runChapterQualityChecks')) {
    /**
     * 运行章节完整质量流水线：结构、角色、描写、爽点、连贯、跟纲、落盘硬质检。
     *
     * @return array{passes:bool,total_score:float,gates:array,summary:string}
     */
    function runChapterQualityChecks(array $chapter, int $novelId, string $content): array
    {
        $results = [
            checkGate1_Structure($chapter, $content),
            checkGate2_Characters($novelId, $content),
            checkGate3_Description($chapter['genre'] ?? null, $content),
            checkGate4_CoolPoint($content, $chapter['outline'] ?? null, $chapter),
            checkGate5_Consistency((int)($chapter['id'] ?? 0), $novelId, $content),
            checkGate6_OutlineAdherence($chapter, $content),
        ];
        $results[] = checkGate7_HardQuality($novelId, $chapter, $content, $results);

        $scores = array_map(static fn($gate): float => (float)($gate['score'] ?? 0), $results);
        return [
            'passes'      => !array_filter($results, static fn($gate): bool => empty($gate['status'])),
            'total_score' => $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1),
            'gates'       => $results,
            'summary'     => generateSummary($results),
        ];
    }
}

if (!function_exists('buildQualityRewriteFeedback')) {
    /** 将所有可操作的质量反馈整理为定向改写清单。 */
    function buildQualityRewriteFeedback(array $gates): string
    {
        $lines = [];
        foreach ($gates as $gate) {
            $name = trim((string)($gate['name'] ?? '质量检查'));
            $score = (float)($gate['score'] ?? 0);
            $passed = !empty($gate['status']);
            $actionable = [];
            foreach ((array)($gate['issues'] ?? []) as $issue) {
                $issue = trim((string)$issue);
                if ($issue === '' || str_starts_with($issue, '✓') || str_contains($issue, '跳过')) {
                    continue;
                }
                $actionable[] = $issue;
            }
            if (!$passed && $actionable === []) {
                $actionable[] = '该项未通过，请按章节大纲和既有设定进行针对性修复';
            }
            foreach ($actionable as $issue) {
                $lines[] = sprintf('- [%s %.0f分] %s', $name, $score, $issue);
            }
        }
        return implode("\n", array_values(array_unique($lines)));
    }
}
