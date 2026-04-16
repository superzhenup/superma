<?php
/**
 * 优化大纲逻辑 API（流式 SSE）
 * 读取全书故事大纲 + 小说设定 + 所有章节大纲
 * 让 AI 逐批审查并重写逻辑混乱、重复、矛盾的章节大纲
 * POST JSON: { novel_id }
 */
ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
session_write_close();

ob_end_clean();
set_time_limit(600);
while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

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

// ---- 构建全书设定摘要 ----
$truncate = fn(string $t, int $l) => mb_strlen($t) > $l ? mb_substr($t, 0, $l) . '…' : $t;
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

// ---- 分批优化，每批10章 ----
$batchSize   = 10;
$updatedTotal = 0;

for ($i = 0; $i < $totalChapters; $i += $batchSize) {
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

    // 前批大纲作为上下文（最多前2批）
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

    $messages = [
        ['role' => 'system', 'content' => <<<EOT
你是一位资深小说编辑，专门负责审查和优化章节大纲的逻辑性与连贯性。

【优化原则】
1. 严格遵守全书故事大纲的主线走向、幕划分和重大转折点，不得改变整体方向
2. 消除情节重复：如果相邻章节概要过于相似，重新设计使每章有独特推进
3. 修复逻辑断裂：确保相邻章节之间有清晰的因果关系，前章钩子与后章开头衔接
4. 强化故事张力：在符合主线的前提下，增加冲突、悬念、人物反差
5. 禁止改变章节数量，必须输出与输入完全相同数量的章节

【输出规则——严格遵守】
1. 只输出纯 JSON 数组，不得有任何前缀、后缀或 markdown 代码块
2. 数组长度必须与输入完全一致，chapter_number 不变
3. summary 控制在 80 字以内，key_points 每条 15 字以内，hook 20 字以内
4. 如果某章无需修改，原样返回即可；如需修改，提供改进版本
EOT
        ],
        ['role' => 'user', 'content' => <<<EOT
【小说设定】
{$settingsSummary}

【全书故事主线】
{$storyArcText}

【幕划分与关键事件】
{$actText}
【重大转折点】
{$turningText}
{$prevContext}【待优化章节（第{$batchFrom}至第{$batchTo}章）】
{$batchText}

请审查以上章节大纲，找出并修复：
- 情节重复（相邻章节做了同样的事）
- 逻辑断裂（前章钩子与后章脱节）
- 与全书主线矛盾
- 张力不足（平淡推进无冲突）

输出格式（严格 JSON 数组，共 {$batchSize} 个或实际章节数个元素）：
[{"chapter_number":整数,"title":"标题","summary":"优化后概要","key_points":["点1","点2"],"hook":"钩子","changed":true或false}]

直接输出 JSON，从 [ 开始：
EOT
        ],
    ];

    $rawResponse = '';
    try {
        withModelFallback(
            $novel['model_id'] ?: null,
            function (AIClient $ai) use ($messages, &$rawResponse) {
                $rawResponse = '';
                $ai->chatStream($messages, function (string $token) use (&$rawResponse) {
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

    sse('batch_done', [
        'msg'     => "第{$batchFrom}～{$batchTo}章优化完成，修改了 {$changedCount} 章",
        'from'    => $batchFrom,
        'to'      => $batchTo,
        'changed' => $changedCount,
    ]);
}

// 优化完成后重新生成弧段摘要（因为大纲内容变了）
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

sse('complete', [
    'msg'     => "大纲逻辑优化完成！共修改 {$updatedTotal} 章，故事线摘要已同步更新。",
    'updated' => $updatedTotal,
]);
sseDone();
