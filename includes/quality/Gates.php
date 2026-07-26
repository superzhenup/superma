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

if (!function_exists('checkGate1_Structure')) {
    /**
     * 第1关：🏗 结构检查
     */
    function checkGate1_Structure(array $ch, string $content): array
    {
        $issues = [];
        $score  = 100;

        $minScoreThreshold = (float)getSystemSetting('ws_quality_min_score', 6.0, 'float') * 10;

        // 修复（2026-06-18）：字数统计与前端 wc-current 保持一致
        // 原用 mb_strlen 包含标点/空格/换行/数字，与前端 countChapterWords（仅中文+英文词）差异大
        // 现改用 countWords()，与 includes/helpers.php::countWords 一致
        $len             = countWords($content);
        $target          = (int)$ch['chapter_words'];
        // W-9 修复：novel.chapter_words 为 0/null 时回退到全局 ws_chapter_words 设置
        if ($target <= 0) {
            $target = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
        }
        $configTolerance = (int)getSystemSetting('ws_chapter_word_tolerance', 0, 'int');
        $tolerance       = ($target > 0 && $configTolerance > 0)
            ? $configTolerance
            : ($target > 0 ? max(CFG_TOLERANCE_MIN, (int)($target * CFG_TOLERANCE_RATIO)) : 0);
        if ($target > 0 && ($len < $target - $tolerance || $len > $target + $tolerance)) {
            $pct = $tolerance > 0 ? round($tolerance / $target * 100) : 0;
            $issues[] = "字数{$len}字超出目标范围（{$target}±{$tolerance}字/±{$pct}%）";
            $score -= 30;
        }

        // 审计优化（2026-07-24）：与 prompt「黄金三行≈前90字」对齐，不再用前200字放水
        $firstLines = getOpeningProbe($content, 90);

        $hasAction   = preg_match('/(动手|冲出|冲向|大喊|吼道|突然|猛地|一把|瞬间|立刻|拔出|挥动|闪身|跃起|扑向|一拳|一脚|拔腿|转身|扑倒|劈|斩|踢|撞)/u', $firstLines);
        $hasSensory  = preg_match('/(看到|听见|闻到|感觉到|触到|瞥见|隐约|依稀|猛然|赫然|竟|赫然发现|眼前|耳边|鼻尖|手心)/u', $firstLines);
        $hasDialogue = preg_match('/[「『""].*[」』""]/u', $firstLines);
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
            'SELECT name FROM novel_characters WHERE novel_id=? AND role_type IN ("protagonist","major")',
            [$novelId]
        );

        if (!empty($characters)) {
            $livingCharacters = [];
            foreach ($characters as $char) {
                $name = trim($char['name']);
                if ($name === '') continue;
                $card = DB::fetch('SELECT alive FROM character_cards WHERE novel_id=? AND name=? LIMIT 1', [$novelId, $name]);
                if ($card && (int)$card['alive'] === 0) {
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
            if ($mentioned === 0 && count($livingCharacters) >= 1) {
                $issues[] = "所有主要角色均未在本章中出现";
                $score -= 10;
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
        preg_match_all('/[「『""].*?[」』""][\s]*[，。！？、]/su', $content, $matches);
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
            $hasDialog = preg_match('/[「『""].*?[」』""]/u', $cleanPara);
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
            'status' => $score >= 70,
            'score'  => max(0, $score),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('checkGate4_CoolPoint')) {
    function checkGate4_CoolPoint(string $content, ?string $outline): array
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

        if (empty($foundSignals)) {
            $issues[] = "ℹ️ 未检测到明显爽点信号（可能是过渡/铺垫章）";
            $score -= 10;
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

        return [
            'name'   => '🔗 连贯性检查',
            'status' => $score >= 70,
            'score'  => max(0, $score),
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
