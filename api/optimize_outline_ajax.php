<?php
/**
 * 优化大纲逻辑 API（AJAX 版本）
 * 
 * 功能：处理一批章节的优化，将进度写入数据库
 * POST JSON: { novel_id, batch_index, start_from }
 * 返回: JSON { success, message, progress, batch_result }
 */

// 禁用错误输出到页面
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

try {
    requireLoginApi();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

session_write_close();
set_time_limit(CFG_TIME_LONG);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);
$batchIndex = (int)($input['batch_index'] ?? 0);
$startFrom = (int)($input['start_from'] ?? 0);

if (!$novelId) {
    echo json_encode(['success' => false, 'message' => '缺少 novel_id']);
    exit;
}

// 获取小说信息
$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) {
    echo json_encode(['success' => false, 'message' => '小说不存在']);
    exit;
}

// 必须有全书故事大纲
$storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id=?', [$novelId]);
if (!$storyOutline) {
    echo json_encode(['success' => false, 'message' => '请先生成全书故事大纲']);
    exit;
}

// 取所有已大纲的章节
$chapters = DB::fetchAll(
    'SELECT chapter_number, title, outline, hook, key_points FROM chapters
     WHERE novel_id=? AND outline IS NOT NULL AND outline != ""
     ORDER BY chapter_number ASC',
    [$novelId]
);

if (empty($chapters)) {
    echo json_encode(['success' => false, 'message' => '暂无已生成的章节大纲']);
    exit;
}

try {
    getModelFallbackList($novel['model_id'] ?: null, 'structured');
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

$totalChapters = count($chapters);
$batchSize = 10;

// 计算批次范围
$batchStart = $batchIndex * $batchSize;
if ($batchStart >= $totalChapters) {
    // 所有批次已完成
    DB::update('novels', ['optimized_chapter' => $totalChapters], 'id=?', [$novelId]);
    echo json_encode([
        'success' => true,
        'message' => '所有章节优化完成',
        'progress' => [
            'current' => $totalChapters,
            'total' => $totalChapters,
            'percent' => 100
        ],
        'completed' => true
    ]);
    exit;
}

$batchEnd = min($batchStart + $batchSize, $totalChapters);
$batch = array_slice($chapters, $batchStart, $batchSize);
$batchFrom = $batch[0]['chapter_number'];
$batchTo = end($batch)['chapter_number'];

// 构建设定摘要
$truncate = fn(string $t, int $l) => safe_strlen($t) > $l ? safe_substr($t, 0, $l) . '…' : $t;
$settingsSummary = implode("\n", array_filter([
    "书名：{$novel['title']}  类型：{$novel['genre']}  风格：{$novel['writing_style']}",
    $novel['protagonist_info'] ? "主角：" . $truncate($novel['protagonist_info'], 200) : '',
    $novel['plot_settings']    ? "情节：" . $truncate($novel['plot_settings'], 200)    : '',
    $novel['world_settings']   ? "世界观：" . $truncate($novel['world_settings'], 200)  : '',
    $novel['extra_settings']   ? "其他：" . $truncate($novel['extra_settings'], 150)    : '',
]));

$storyArcText = $truncate($storyOutline['story_arc'] ?? '', 400);
$actDivision = is_string($storyOutline['act_division']) 
    ? json_decode($storyOutline['act_division'], true) 
    : ($storyOutline['act_division'] ?? []);
$turningPoints = is_string($storyOutline['major_turning_points'])
    ? json_decode($storyOutline['major_turning_points'], true)
    : ($storyOutline['major_turning_points'] ?? []);

// 整理幕信息
$actText = '';
if (!empty($actDivision)) {
    foreach ($actDivision as $act) {
        $keyEvents = is_array($act['key_events'] ?? null)
            ? $act['key_events']
            : (json_decode($act['key_events'] ?? '[]', true) ?: []);
        $actText .= "第{$act['chapters']}章（{$act['theme']}）：" . implode('、', $keyEvents) . "\n";
    }
}
$turningText = '';
if (!empty($turningPoints)) {
    foreach ($turningPoints as $tp) {
        $turningText .= "第{$tp['chapter']}章：{$tp['event']}\n";
    }
}

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
if ($batchStart > 0) {
    $prevStart = max(0, $batchStart - $batchSize);
    $prevBatch = array_slice($chapters, $prevStart, $batchSize);
    $prevLines = [];
    foreach ($prevBatch as $ch) {
        $prevLines[] = "第{$ch['chapter_number']}章《{$ch['title']}》：{$ch['outline']}";
    }
    $prevContext = "【前批章节参考】\n" . implode("\n", $prevLines) . "\n\n";
}

// 调用 AI
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
            // AJAX 版本使用同步调用，不需要流式输出
            $rawResponse = $ai->chat($messages, 'structured');
        }
    );
} catch (Exception $e) {
    $errMsg = $e->getMessage();
    // 判断是否为可重试的网络错误（非 API 业务错误）
    $isNetworkError = false;
    $networkPatterns = [
        'Connection reset by peer',
        'Connection refused',
        'Connection timed out',
        'Timeout was reached',
        'Recv failure',
        'Send failure',
        'Failed to connect',
        'Empty reply from server',
        'transfer closed',
        'OpenSSL SSL_read',
        'SSL connection timeout',
    ];
    foreach ($networkPatterns as $pattern) {
        if (stripos($errMsg, $pattern) !== false) {
            $isNetworkError = true;
            break;
        }
    }

    echo json_encode([
        'success'         => false,
        'message'         => 'AI 调用失败: ' . $errMsg,
        'retryable'       => $isNetworkError,
        'is_network_error'=> $isNetworkError,
        'batch_index'     => $batchIndex,
        'batch_from'      => $batchFrom,
        'batch_to'        => $batchTo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析 AI 响应
$cleanJson = preg_replace('/^```(?:json)?\s*/m', '', $rawResponse);
$cleanJson = preg_replace('/\s*```\s*$/m', '', $cleanJson);
$cleanJson = trim($cleanJson);

$batchResult = json_decode($cleanJson, true);
if (!is_array($batchResult)) {
    echo json_encode([
        'success' => false,
        'message' => 'AI 返回格式错误',
        'raw' => mb_substr($rawResponse, 0, 200)
    ]);
    exit;
}

// 更新数据库
$updatedCount = 0;
foreach ($batchResult as $item) {
    $chapterNum = (int)($item['chapter_number'] ?? 0);
    if (!$chapterNum) continue;
    
    $updateData = [
        'outline' => $item['summary'] ?? '',
        'key_points' => json_encode($item['key_points'] ?? [], JSON_UNESCAPED_UNICODE),
        'hook' => $item['hook'] ?? '',
    ];
    
    $affected = DB::update(
        'chapters',
        $updateData,
        'novel_id=? AND chapter_number=?',
        [$novelId, $chapterNum]
    );
    
    if ($affected > 0) {
        $updatedCount++;
    }
}

// 更新进度
DB::update('novels', ['optimized_chapter' => $batchTo], 'id=?', [$novelId]);

// 返回结果
echo json_encode([
    'success' => true,
    'message' => "第 {$batchFrom}～{$batchTo} 章优化完成，更新了 {$updatedCount} 章",
    'progress' => [
        'current' => $batchTo,
        'total' => $totalChapters,
        'percent' => round(($batchTo / $totalChapters) * 100, 1)
    ],
    'batch_result' => [
        'from' => $batchFrom,
        'to' => $batchTo,
        'updated' => $updatedCount,
        'changed' => array_filter($batchResult, fn($item) => !empty($item['changed']))
    ],
    'next_batch' => $batchIndex + 1,
    'has_more' => ($batchEnd < $totalChapters)
], JSON_UNESCAPED_UNICODE);
