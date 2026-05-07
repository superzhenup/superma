<?php
/**
 * Super-Ma 章节质量检测流水线（五关自动检测）
 *
 * API: POST api/validate_consistency.php
 * 参数: { novel_id, chapter_id }
 * 返回: { passes, total_score, gates: [{name,status,score,issues}], summary }
 *
 * 五关：
 *   第1关 🏗 结构检查 — 字数/黄金三行/结尾钩子
 *   第2关 👥 角色检查 — 主要角色出场率
 *   第3关 📝 描写检查 — 对话密度/段落长度
 *   第4关 💥 爽点检查 — 爽点信号关键词检测
 *   第5关 🔗 连贯性检查 — 前章衔接/伏笔回收
 */

defined('APP_LOADED') || define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
require_once dirname(__DIR__) . '/includes/auth.php';
if (!defined('CLI_MODE')) registerApiErrorHandlers();
if (!defined('CLI_MODE')) requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';

// CLI 模式下只加载函数定义，不执行 API 逻辑
if (!defined('CLI_MODE')) {
    header('Content-Type: application/json; charset=utf-8');

    // ---- 解析参数 ----
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $chapterId = (int)($input['chapter_id'] ?? 0);
    $novelId   = (int)($input['novel_id'] ?? 0);

    if (!$chapterId || !$novelId) {
        echo json_encode(['error' => '缺少 chapter_id 或 novel_id']);
        exit;
    }

    // ---- 查询章节+小说信息 ----
    $chapter = DB::fetch(
        'SELECT c.*, n.genre, n.chapter_words, n.writing_style 
         FROM chapters c JOIN novels n ON c.novel_id = n.id 
         WHERE c.id = ? AND c.novel_id = ?',
        [$chapterId, $novelId]
    );

    if (!$chapter) {
        echo json_encode(['error' => '章节不存在']);
        exit;
    }

    $content = $chapter['content'] ?? '';
    if (empty(trim($content))) {
        echo json_encode(['error' => '章节内容为空，无法检测', 'gates' => [], 'total_score' => 0]);
        exit;
    }

    // ========== 五关检测流水线 ==========

    $results = [];

    // ---- 第1关：结构检查 ----
    $results[] = checkGate1_Structure($chapter, $content);

    // ---- 第2关：角色检查 ----
    $results[] = checkGate2_Characters($novelId, $content);

    // ---- 第3关：描写检查 ----
    $results[] = checkGate3_Description($chapter['genre'], $content);

    // ---- 第4关：爽点检查 ----
    $results[] = checkGate4_CoolPoint($content, $chapter['outline']);

    // ---- 第5关：连贯性检查 ----
    $results[] = checkGate5_Consistency($chapterId, $novelId, $content);

    // v11: 质量检查开关 — 如果用户在设置中关闭了质量检查，直接返回通过
    $qualityEnabled = (bool)getSystemSetting('ws_quality_check_enabled', true, 'bool');
    if (!$qualityEnabled) {
        $response = [
            'passes'        => true,
            'total_score'   => 100,
            'gates'         => [],
            'summary'       => '质量检查已关闭',
            'quality_score' => 100,
            'gate_results'  => [],
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ---- 汇总结果 ----
    $allPass = !array_filter($results, fn($r) => !$r['status']);
    $scores  = array_column($results, 'score');
    $avgScore = count($scores) > 0
        ? round(array_sum($scores) / count($scores), 1)
        : 0;

    $response = [
        'passes'      => $allPass,
        'total_score' => $avgScore,
        'gates'       => $results,
        'summary'     => generateSummary($results),
        // 回写字段（供 write_chapter.php 使用）
        'quality_score' => $avgScore,
        'gate_results' => $results,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ==================== 各关检测函数实现 ====================

/**
 * 第1关：🏗 结构检查
 */
function checkGate1_Structure(array $ch, string $content): array
{
    $issues = [];
    $score  = 100;

    // v11: 从系统设置读取质量最低分阈值（百分制，如60表示60分）
    $minScoreThreshold = (float)getSystemSetting('ws_quality_min_score', 60.0, 'float');

    // 1.1 字数检查：容差优先读 ws_chapter_word_tolerance，兜底用 3% 动态容差（最小80字）
    $len             = mb_strlen($content);
    $target          = (int)$ch['chapter_words'];
    $configTolerance = (int)getSystemSetting('ws_chapter_word_tolerance', 0, 'int');
    $tolerance       = ($target > 0 && $configTolerance > 0) ? $configTolerance : ($target > 0 ? max(CFG_TOLERANCE_MIN, (int)($target * CFG_TOLERANCE_RATIO)) : 0);
    if ($target > 0 && ($len < $target - $tolerance || $len > $target + $tolerance)) {
        $pct = $tolerance > 0 ? round($tolerance / $target * 100) : 0;
        $issues[] = "字数{$len}字超出目标范围（{$target}±{$tolerance}字/±{$pct}%）";
        $score -= 30;
    }

// v1.5.3 增强：黄金三行检测 — 取正文前200字符≈4行，使用短语模式匹配
    $cleanContent = str_replace(["\r\n", "\n", "\r", "\t", ' '], '', $content);
    $firstLines   = mb_substr($cleanContent, 0, 200);

    // 动作类：动词短语模式
    $hasAction = preg_match('/(动手|冲出|冲向|大喊|吼道|突然|猛地|一把|瞬间|立刻|拔出|挥动|闪身|跃起|扑向|一拳|一脚|拔腿|转身|扑倒)/u', $firstLines);
    // 感官类：五感描写模式
    $hasSensory = preg_match('/(看到|听见|闻到|感觉到|触到|瞥见|隐约|依稀|猛然|赫然|竟|赫然发现)/u', $firstLines);
    // 对话类：引号包裹的对话
    $hasDialogue = preg_match('/[「『""].*[」』""]/u', $firstLines);
    // 异常类：异常/悬念关键词
    $hasAbnormal = preg_match('/(奇怪|异常|惊恐|震惊|不敢相信|居然|竟然|不料|没想到|怎么回事|为何|难道|谁|怎么)/u', $firstLines);
    // 危机类：紧迫/危险信号
    $hasCrisis = preg_match('/(危险|快跑|小心|救命|不好|糟了|完蛋|死定了|来不及|命悬一线)/u', $firstLines);

    if (!$hasAction && !$hasSensory && !$hasDialogue && !$hasAbnormal && !$hasCrisis) {
        $issues[] = "⚠️ 黄金三行未达标：前三行缺乏动作/感官/对话/异常/危机要素";
        $score -= 15;
    }

    // 1.3 结尾钩子检测 — 最后一段不能是平静句
    $lastPara = getLastParagraph($content);
    $calmPatterns = [
        '/^(大家|众人|众人皆|夜深了|天亮了|一切都|从此|就这样|后来)/u',
        '/^(这一|那个|很快|不久|日子)/u',
    ];
    foreach ($calmPatterns as $p) {
        if (preg_match($p, trim($lastPara))) {
            $issues[] = "❌ 结尾过于平淡，疑似平静句收尾";
            $score -= 25;
            break; // 只报一次
        }
    }

    return [
        'name'   => '🏗 结构检查',
        'status' => $score >= $minScoreThreshold,
        'score'  => max(0, $score),
        'issues' => $issues,
    ];
}


/**
 * 第2关：👥 角色检查
 */
function checkGate2_Characters(int $novelId, string $content): array
{
    $issues = [];
    $score  = 100;

    // 获取本书主要角色
    $characters = DB::fetchAll(
        'SELECT name FROM novel_characters WHERE novel_id=? AND role_type IN ("protagonist","major")',
        [$novelId]
    );

    if (!empty($characters)) {
        $mentioned = 0;
        $names     = [];
        foreach ($characters as $char) {
            $name = trim($char['name']);
            if ($name && mb_strpos($content, $name) !== false) {
                $mentioned++;
                $names[] = $name;
            }
        }

        $ratio = $mentioned / count($characters);
        if ($ratio < 0.3 && count($characters) > 2) {
            $missing = count($characters) - $mentioned;
            $issues[] = "主要角色出场率偏低({$mentioned}/" . count($characters) . ")，{$missing}个角色未出现";
            $score -= 15;
        } elseif ($ratio === 0 && count($characters) >= 1) {
            $issues[] = "所有主要角色均未在本章中出现";
            $score -= 10;
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


/**
 * 第3关：📝 描写检查
 */
function checkGate3_Description(?string $genre, string $content): array
{
    $issues = [];
    $score  = 100;

    // 3.1 对话密度估算
    preg_match_all('/[「『""].*?[」』""][\s]*[，。！？、]/su', $content, $matches);
    $dialogueCount = count($matches[0]);
    $charCount     = mb_strlen($content);
    $density       = $charCount > 0 ? round($dialogueCount * 1000 / $charCount, 1) : 0;

    if ($density < 20 && $charCount > 500) {
        $issues[] = "对话密度过低（约{$density}句/千字，建议≥25）";
        $score -= 15;
    } else {
        $issues[] = "✓ 对话密度约{$density}句/千字";
    }

    // 3.2 连续非对话段落检测
    $paragraphs = preg_split('/\n\s*\n/u', $content);
    $maxNonDialogLen = 0;
    foreach ($paragraphs as $para) {
        $cleanPara = trim($para);
        if (empty($cleanPara)) continue;
        $hasDialog = preg_match('/[「『""].*?[」』""]/u', $cleanPara);
        if (!$hasDialog) {
            $plen = mb_strlen($cleanPara);
            $maxNonDialogLen = max($maxNonDialogLen, $plen);
        }
    }

    if ($maxNonDialogLen > 350) {
        $issues[] = "存在超长非对话段落({$maxNonDialogLen}字)，建议≤300";
        $score -= 15;
    } elseif ($maxNonDialogLen > 280) {
        $issues[] = "⚠️ 最长非对话段落接近上限({$maxNonDialogLen}字)";
        $score -= 5;
    }

    // 3.3 平均段落长度
    $nonEmptyParas = array_filter($paragraphs, fn($p) => trim($p) !== '');
    if (!empty($nonEmptyParas)) {
        $avgParaLen = round(array_sum(array_map('mb_strlen', $nonEmptyParas)) / count($nonEmptyParas));
        if ($avgParaLen > 400) {
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


/**
 * 第4关：💥 爽点检查（基于关键词信号的启发式检测）
 */
function checkGate4_CoolPoint(string $content, ?string $outline): array
{
    $issues = [];
    $score  = 80; // 这关较难自动化，给基础分

    // 爽点信号关键词库（按类型分组）
    $coolSignals = [
        'underdog_win' => ['击败|战胜|打败|完胜|碾压|越级.*战胜|以弱胜强', '越级胜利信号'],
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
        $bonus = min(12, count($foundSignals) * 4); // 加分上限12
        $score += $bonus;
        $issues[] = "✓ 检测到 " . implode(' / ', $foundSignals);
    }

    return [
        'name'   => '💥 爽点检查',
        'status' => $score >= 60, // 这关门槛略低
        'score'  => min(100, max(0, $score)),
        'issues' => $issues,
    ];
}


/**
 * 第5关：🔗 连贯性检查
 */
function checkGate5_Consistency(int $chapterId, int $novelId, string $content): array
{
    $issues = [];
    $score  = 100;

    // 查询章节号
    $chNum = (int)(DB::fetchColumn('SELECT chapter_number FROM chapters WHERE id=?', [$chapterId]) ?? 0);

    if ($chNum <= 1) {
        return [
            'name'   => '🔗 连贯性检查',
            'status' => true,
            'score'  => 100,
            'issues' => ['第一章，跳过连贯性检测'],
        ];
    }

    // 5.1 与前章衔接检查
    $prevTail = DB::fetchColumn(
        'SELECT content FROM chapters WHERE novel_id=? AND chapter_number=? AND status="completed"',
        [$novelId, $chNum - 1]
    );

    if ($prevTail) {
        $tailEnd   = mb_substr($prevTail, -120); // 前章末尾120字
        $currStart = mb_substr($content, 0, 120); // 本章开头120字

        // 检查时间/场景过渡词
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
            // 不一定有问题——很多网文直接接着写，仅提醒
            $issues[] = "ℹ️ 开头未检测到明确的时间/场景过渡词（请确认是否需要）";
            $score -= 3;
        } else {
            $issues[] = "✓ 检测到过渡词";
        }
    } else {
        $issues[] = "ℹ️ 前章无内容或状态非completed，跳过衔接检查";
    }

    // 5.2 伏笔回收提醒
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


// ========== 辅助函数 ==========

/**
 * 获取最后一段文本（用于结尾钩子检测）
 */
function getLastParagraph(string $text): string
{
    $paras = preg_split('/\n\s*\n/u', $text);
    $paras = array_filter($paras, fn($p) => trim($p) !== '');
    return empty($paras) ? '' : trim(end($paras));
}

/**
 * 生成汇总文案
 */
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
