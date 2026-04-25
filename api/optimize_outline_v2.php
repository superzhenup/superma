<?php
/**
 * 优化大纲 API - 改进版
 * 
 * 核心改进：
 * 1. 在 curl 执行期间使用 register_tick_function 发送心跳
 * 2. 更频繁的心跳（每 3 秒）
 * 3. 明确的状态提示
 */
ob_start();
ini_set('display_errors', '0');

// ============================================================
// SSE 心跳机制 - 改进版
// ============================================================
$lastHeartbeat = time();
$heartbeatInterval = 3; // 每3秒发送心跳（缩短间隔）

function sendHeartbeatOptimize(): void {
    global $lastHeartbeat, $heartbeatInterval;
    $now = time();
    if ($now - $lastHeartbeat >= $heartbeatInterval) {
        echo "event: heartbeat\n";
        echo "data: " . json_encode(['time' => $now, 'msg' => 'keep-alive']) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        $lastHeartbeat = $now;
        
        // 记录日志（调试用）
        error_log("[Heartbeat] Sent at " . date('H:i:s'));
    }
}

// 注册全局心跳函数
$GLOBALS['sendHeartbeat'] = 'sendHeartbeatOptimize';

// 使用 declare(ticks=1) 来定期检查心跳
declare(ticks=1) {
    // 注册 tick 函数，每执行一定数量的语句就检查心跳
    register_tick_function('sendHeartbeatOptimize');
}

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
session_write_close();

ob_end_clean();
set_time_limit(CFG_TIME_LONG);

while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);
$startFrom = (int)($input['start_from'] ?? 0);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

// 获取已优化进度
$optimizedChapter = (int)($novel['optimized_chapter'] ?? 0);
if ($startFrom > 0) {
    $startChapter = $startFrom;
} elseif ($optimizedChapter > 0) {
    $startChapter = $optimizedChapter + 1;
} else {
    $startChapter = 1;
    DB::update('novels', ['optimized_chapter' => 0], 'id=?', [$novelId]);
}

// 必须有全书故事大纲
$storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id=?', [$novelId]);
if (!$storyOutline) {
    sse('error', ['msg' => '请先生成全书故事大纲，再进行大纲逻辑优化']);
    sseDone(); exit;
}

// 取所有已大纲的章节
$chapters = DB::fetchAll(
    'SELECT chapter_number, title, outline, hook, key_points FROM chapters
     WHERE novel_id=? AND outline IS NOT NULL AND outline != ""
     ORDER BY chapter_number ASC',
    [$novelId]
);

if (empty($chapters)) {
    sse('error', ['msg' => '暂无已生成的章节大纲，请先生成大纲']);
    sseDone(); exit;
}

try { getModelFallbackList($novel['model_id'] ?: null); }
catch (RuntimeException $e) { sse('error', ['msg' => $e->getMessage()]); sseDone(); exit; }

$totalChapters = count($chapters);
sse('progress', ['msg' => "开始优化 {$totalChapters} 章大纲逻辑...", 'total' => $totalChapters]);

// 构建全书设定摘要
$truncate = fn(string $t, int $l) => safe_strlen($t) > $l ? safe_substr($t, 0, $l) . '…' : $t;
$settingsSummary = implode("\n", array_filter([
    "书名：{$novel['title']}  类型：{$novel['genre']}  风格：{$novel['writing_style']}",
    $novel['protagonist_info'] ? "主角：" . $truncate($novel['protagonist_info'], 200) : '',
    $novel['plot_settings']    ? "情节：" . $truncate($novel['plot_settings'], 200)    : '',
    $novel['world_settings']   ? "世界观：" . $truncate($novel['world_settings'], 200)  : '',
    $novel['extra_settings']   ? "其他：" . $truncate($novel['extra_settings'], 150)    : '',
]));

$storyArcText     = $truncate($storyOutline['story_arc'] ?? '', 400);
$actDivision      = is_string($storyOutline['act_division']) 
    ? json_decode($storyOutline['act_division'], true) 
    : ($storyOutline['act_division'] ?? []);
$turningPoints    = is_string($storyOutline['major_turning_points'])
    ? json_decode($storyOutline['major_turning_points'], true)
    : ($storyOutline['major_turning_points'] ?? []);

// 整理幕信息
$actText = '';
if (!empty($actDivision)) {
    foreach ($actDivision as $act) {
        $actText .= "第{$act['chapters']}章（{$act['theme']}）：" . implode('、', $act['key_events'] ?? []) . "\n";
    }
}
$turningText = '';
if (!empty($turningPoints)) {
    foreach ($turningPoints as $tp) {
        $turningText .= "第{$tp['chapter']}章：{$tp['event']}\n";
    }
}

// 分批优化，每批10章
$batchSize   = 10;
$updatedTotal = 0;

// 计算起始批次索引
$startBatchIndex = 0;
if ($startChapter > 1) {
    foreach ($chapters as $idx => $ch) {
        if ($ch['chapter_number'] >= $startChapter) {
            $startBatchIndex = floor($idx / $batchSize) * $batchSize;
            break;
        }
    }
    sse('progress', ['msg' => "从第 {$startChapter} 章继续优化...", 'resuming' => true, 'start_from' => $startChapter]);
}

for ($i = $startBatchIndex; $i < $totalChapters; $i += $batchSize) {
    // 发送心跳
    sendHeartbeatOptimize();

    $batch     = array_slice($chapters, $i, $batchSize);
    $batchFrom = $batch[0]['chapter_number'];
    $batchTo   = end($batch)['chapter_number'];

    // 构建本批大纲文本
    $batchText = '';
    foreach ($batch as $ch) {
        $kpts = json_decode($ch['key_points'] ?? '[]', true) ?: [];
        $batchText .= "第{$ch['chapter_number']}章《{$ch['title']}》\n";
        $batchText .= "概要：{$ch['outline']}\n";
        if ($kpts) $batchText .= "情节点：" . implode('、', $kpts) . "\n";
        if ($ch['hook']) $batchText .= "钩子：{$ch['hook']}\n";
        $batchText .= "\n";
    }

    // 前批大纲作为上下文
    $prevContext = '';
    if ($i > 0) {
        $prevStart = max(0, $i - $batchSize);
        $prevBatch = array_slice($chapters, $prevStart, $batchSize);
        $prevLines = [];
        foreach ($prevBatch as $ch) {
            $prevLines[] = "第{$ch['chapter_number']}章《{$ch['title']}》：{$ch['outline']}";
        }
        $prevContext = "【前批章节参考】\n" . implode("\n", $prevLines) . "\n\n";
    }

    sse('progress', [
        'msg'   => "正在优化第 {$batchFrom}～{$batchTo} 章大纲逻辑...",
        'from'  => $batchFrom,
        'to'    => $batchTo,
    ]);

    // 在调用 AI 前发送心跳和状态
    sendHeartbeatOptimize();
    sse('progress', ['msg' => "正在调用 AI 服务，预计需要 30-90 秒，请耐心等待..."]);

    $messages = [
        ['role' => 'system', 'content' => "你是一位资深小说编辑，专门负责审查和优化章节大纲的逻辑性与连贯性。

【优化原则】
1. 严格遵守全书故事大纲的主线走向、幕划分和重大转折点，不得改变整体方向
2. 消除情节重复：如果相邻章节概要过于相似，重新设计使每章有独特推进
3. 修复逻辑断裂：确保相邻章节之间有清晰的因果关系，前章钩子与后章开头衔接
4. 强化故事张力：在符合主线的前提下，增加冲突、悬念、人物反差
5. 禁止改变章节数量，必须输出与输入完全相同数量的章节

【输出规则——严格遵守】
1. 只输出纯 JSON 数组，不得有任何前缀、后缀或 markdown 代码块
2. 数组长度必须与输入完全一致，chapter_number 不变
3. 每个元素包含：chapter_number, title, summary（优化后概要）, key_points, hook, changed（布尔值）

【全书设定】
{$settingsSummary}

【故事主线】
{$storyArcText}

【幕划分】
{$actText}

【重大转折点】
{$turningText}

【前批章节参考】
{$prevContext}

【本批待优化章节】
{$batchText}

请检查以上章节大纲，修复以下问题：
- 情节重复（相邻章节概要过于相似）
- 逻辑断裂（前章钩子与后章脱节）
- 与全书主线矛盾
- 张力不足（平淡推进无冲突）

输出格式（严格 JSON 数组）：
[{\"chapter_number\":整数,\"title\":\"标题\",\"summary\":\"优化后概要\",\"key_points\":[\"点1\",\"点2\"],\"hook\":\"钩子\",\"changed\":true或false}]

直接输出 JSON，从 [ 开始："],
    ];

    $rawResponse = '';
    try {
        // 在 AI API 调用前后发送心跳
        sendHeartbeatOptimize();
        
        withModelFallback(
            $novel['model_id'] ?: null,
            function (AIClient $ai) use ($messages, &$rawResponse) {
                global $lastHeartbeat;
                $rawResponse = '';
                $ai->chatStream($messages, function (string $token) use (&$rawResponse, &$lastHeartbeat) {
                    // 在流式输出中发送心跳
                    sendHeartbeatOptimize();

                    if ($token === '[DONE]') return;
                    $rawResponse .= $token;
                    echo "event: chunk\n";
                    echo 'data: ' . json_encode(['t' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                }, 'structured');
            },
            function (AIClient $nextAi, string $errMsg) {
                sse('model_switch', ['msg' => "切换到「{$nextAi->modelLabel}」重试", 'error' => $errMsg]);
            }
        );
        
        // AI API 调用完成后立即发送心跳
        sendHeartbeatOptimize();
    } catch (RuntimeException $e) {
        sse('error', ['msg' => "第{$batchFrom}～{$batchTo}章优化失败：" . $e->getMessage()]);
        continue;
    }

    // 解析并入库
    $optimized = extractOutlineObjects($rawResponse);
    if (empty($optimized)) {
        sse('error', ['msg' => "第{$batchFrom}～{$batchTo}章：AI返回解析失败，跳过"]);
        continue;
    }

    $changedCount = 0;
    foreach ($optimized as $item) {
        $chNum   = (int)($item['chapter_number'] ?? 0);
        $title   = trim($item['title']   ?? '');
        $summary = trim($item['summary'] ?? '');
        $kpts    = $item['key_points']   ?? [];
        $hook    = trim($item['hook']    ?? '');
        $changed = (bool)($item['changed'] ?? true);

        if (!$chNum || !$summary) continue;

        $existing = DB::fetch(
            'SELECT id FROM chapters WHERE novel_id=? AND chapter_number=?',
            [$novelId, $chNum]
        );
        if (!$existing) continue;

        DB::update('chapters', [
            'title'      => $title ?: null,
            'outline'    => $summary,
            'key_points' => json_encode($kpts, JSON_UNESCAPED_UNICODE),
            'hook'       => $hook,
        ], 'id=?', [$existing['id']]);

        if ($changed) $changedCount++;
    }

    $updatedTotal += $changedCount;

    // 更新优化进度到数据库
    DB::update('novels', ['optimized_chapter' => $batchTo], 'id=?', [$novelId]);

    sse('batch_done', [
        'msg'     => "第{$batchFrom}～{$batchTo}章优化完成，修改了 {$changedCount} 章",
        'from'    => $batchFrom,
        'to'      => $batchTo,
        'changed' => $changedCount,
    ]);
}

// 优化完成后重新生成弧段摘要
sse('progress', ['msg' => '正在更新故事线摘要...']);
$allChapters = DB::fetchAll(
    'SELECT chapter_number FROM chapters WHERE novel_id=? AND outline IS NOT NULL ORDER BY chapter_number ASC',
    [$novelId]
);
if (!empty($allChapters)) {
    $maxChapter = (int)end($allChapters)['chapter_number'];
    for ($arc = 1; $arc <= ceil($maxChapter / 10); $arc++) {
        $arcFrom = ($arc - 1) * 10 + 1;
        $arcTo   = min($arc * 10, $maxChapter);
        generateAndSaveArcSummary($novel, $arcFrom, $arcTo);
    }
}

addLog($novelId, 'optimize_outline', "大纲逻辑优化完成，共修改 {$updatedTotal} 章");

// 取消注册 tick 函数
unregister_tick_function('sendHeartbeatOptimize');

sse('complete', [
    'msg'     => "大纲逻辑优化完成！共修改 {$updatedTotal} 章，故事线摘要已同步更新。",
    'updated' => $updatedTotal,
]);
sseDone();
