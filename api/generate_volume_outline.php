<?php
/**
 * 卷大纲生成 API（流式 SSE）
 * POST JSON: { novel_id }
 * 
 * v7 新增：在章节大纲生成之前，先创建卷级大纲作为中层规划
 * 每卷 30-50 章，全书分为若干卷，每卷有独立主题、核心冲突、关键事件
 */

// 强制禁用输出缓冲
// 注意：output_buffering 是 PHP_INI_PERDIR 级别，ini_set() 无法修改
// 改用 ob_end_clean() 在运行时清除缓冲区
while (ob_get_level()) ob_end_clean();
ini_set('implicit_flush', 'On');
ini_set('zlib.output_compression', 'Off');

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
set_time_limit(CFG_TIME_LONG);

while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// ---- 解析入参 ----
$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

$targetChapters = (int)($novel['target_chapters'] ?? 100);
if ($targetChapters < 30) {
    sse('error', ['msg' => '目标章节数少于30章，无需生成卷大纲']); 
    sseDone(); exit;
}

// 检查是否已有卷大纲
$existingVolumes = DB::fetchAll('SELECT id FROM volume_outlines WHERE novel_id=?', [$novelId]);
if (!empty($existingVolumes)) {
    sse('error', ['msg' => '卷大纲已存在，如需重新生成请先删除']); 
    sseDone(); exit;
}

// 计算卷划分（每卷 30-50 章）
$volumeSize = 40; // 默认每卷 40 章
$numVolumes = ceil($targetChapters / $volumeSize);

// 确保卷数合理（最少3卷，最多15卷）
if ($numVolumes < 3) $numVolumes = 3;
if ($numVolumes > 15) $numVolumes = 15;

// 重新计算每卷章节数
$volumeSize = ceil($targetChapters / $numVolumes);

sse('progress', ['msg' => "正在计算卷划分（{$numVolumes}卷，每卷约{$volumeSize}章）..."]);

// 获取故事大纲
$storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id=?', [$novelId]);
if (!$storyOutline) {
    sse('error', ['msg' => '请先生成全书故事大纲']); 
    sseDone(); exit;
}

// 解析 act_division
$actDivision = json_decode($storyOutline['act_division'] ?? '{}', true);

sse('progress', ['msg' => "正在生成{$numVolumes}卷大纲..."]);

// 构建卷大纲生成 Prompt
$volumePrompt = buildVolumeOutlinePrompt($novel, $targetChapters, $numVolumes, $volumeSize, $actDivision, $storyOutline);

$rawResponse = '';
$usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];

try {
    withModelFallback(
        $novel['model_id'] ?: null,
        function (AIClient $ai) use ($volumePrompt, &$rawResponse, &$usage) {
            $rawResponse = '';
            $usage = $ai->chatStream($volumePrompt, function (string $token) use (&$rawResponse) {
                if ($token === '[DONE]') return;
                $rawResponse .= $token;
                echo "event: chunk\n";
                echo 'data: ' . json_encode(['t' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            });
        },
        function (AIClient $nextAi, string $errMsg) {
            sse('model_switch', [
                'msg'        => "模型请求失败，自动切换到「{$nextAi->modelLabel}」重试",
                'next_model' => $nextAi->modelLabel,
                'error'      => $errMsg,
            ]);
        }
    );
} catch (RuntimeException $e) {
    sse('error', ['msg' => '卷大纲生成失败 — ' . $e->getMessage()]);
    sseDone(); exit;
}

// ---- 解析JSON ----
$volumes = extractVolumeOutlines($rawResponse, $numVolumes);

if (empty($volumes)) {
    sse('error', ['msg' => '卷大纲解析失败']);
    sseDone(); exit;
}

// ---- 入库 ----
$saved = 0;
foreach ($volumes as $vol) {
    $volNumber = (int)($vol['volume_number'] ?? 0);
    if (!$volNumber) continue;
    
    DB::insert('volume_outlines', [
        'novel_id'                    => $novelId,
        'volume_number'               => $volNumber,
        'title'                       => trim($vol['title'] ?? "第{$volNumber}卷"),
        'summary'                     => trim($vol['summary'] ?? ''),
        'theme'                       => trim($vol['theme'] ?? ''),
        'start_chapter'               => (int)($vol['start_chapter'] ?? (($volNumber - 1) * $volumeSize + 1)),
        'end_chapter'                 => (int)($vol['end_chapter'] ?? min($volNumber * $volumeSize, $targetChapters)),
        'key_events'                  => json_encode($vol['key_events'] ?? [], JSON_UNESCAPED_UNICODE),
        'character_focus'             => json_encode($vol['character_focus'] ?? [], JSON_UNESCAPED_UNICODE),
        'conflict'                    => trim($vol['conflict'] ?? ''),
        'resolution'                  => trim($vol['resolution'] ?? ''),
        'foreshadowing'               => json_encode($vol['foreshadowing'] ?? [], JSON_UNESCAPED_UNICODE),
        'volume_goals'                => json_encode($vol['volume_goals'] ?? [], JSON_UNESCAPED_UNICODE),
        'must_resolve_foreshadowing'  => json_encode($vol['must_resolve_foreshadowing'] ?? [], JSON_UNESCAPED_UNICODE),
        'status'                      => 'generated',
    ]);
    $saved++;
}

// 更新小说状态
DB::update('novels', ['has_story_outline' => 2], 'id=?', [$novelId]); // 2 表示已有卷大纲

addLog($novelId, 'volume_outline', "生成{$saved}卷大纲");

sse('complete', [
    'msg'            => "卷大纲生成完成！共 {$saved} 卷",
    'total_volumes'  => $saved,
    'prompt_tokens'  => $usage['prompt_tokens'],
    'completion_tokens' => $usage['completion_tokens'],
    'total_tokens'   => $usage['prompt_tokens'] + $usage['completion_tokens'],
]);
sseDone();

/**
 * 构建卷大纲生成 Prompt
 */
function buildVolumeOutlinePrompt(array $novel, int $targetChapters, int $numVolumes, int $volumeSize, array $actDivision, array $storyOutline): array {
    $system = <<<EOT
你是一位资深的小说策划师，擅长构建多卷本长篇小说的中层故事结构。
输出规则（必须严格遵守）：
1. 只输出纯JSON数组，不要有任何前缀、后缀或markdown代码块
2. 每卷必须有独立的主题、核心冲突、起承转合
3. 卷与卷之间必须有逻辑承接，形成完整的叙事链
4. 所有字段值中不得出现未转义的双引号，如需引用书名请用【】代替引号
5. 每卷的关键事件要具体、可执行，能指导后续章节大纲生成
6. volume_goals 是本卷写作必须完成的硬性目标，章节大纲生成时将严格约束
7. must_resolve_foreshadowing 是从前几卷继承的逾期伏笔，本卷内必须回收
EOT;

    $act1 = $actDivision['act1'] ?? [];
    $act2 = $actDivision['act2'] ?? [];
    $act3 = $actDivision['act3'] ?? [];

    $user = <<<EOT
为小说《{$novel['title']}》设计卷级大纲。

【基本信息】
书名：{$novel['title']}
类型：{$novel['genre']}
目标总章节数：{$targetChapters}章
卷数：{$numVolumes}卷（每卷约{$volumeSize}章）

【全书故事主线】
{$storyOutline['story_arc'] ?? '（无）'}

【三幕结构】
- 第一幕（1-{$act1['chapters']}）：{$act1['theme']}，关键事件：{$act1['key_events'][0]}、{$act1['key_events'][1]}、{$act1['key_events'][2]}
- 第二幕（{$act2['chapters']}）：{$act2['theme']}，关键事件：{$act2['key_events'][0]}、{$act2['key_events'][1]}、{$act2['key_events'][2]}
- 第三幕（{$act3['chapters']}）：{$act3['theme']}，关键事件：{$act3['key_events'][0]}、{$act3['key_events'][1]}、{$act3['key_events'][2]}

【主角设定】
{$novel['protagonist_info'] ?? '（无）'}

请输出以下格式的JSON数组（共{$numVolumes}个元素）：
[
  {
    "volume_number": 1,
    "title": "卷标题（如：春耕夏耘）",
    "summary": "卷概要（300-500字，描述本卷情节走向）",
    "theme": "本卷主题（如：困境求生）",
    "start_chapter": 1,
    "end_chapter": {$volumeSize},
    "key_events": ["关键事件1", "关键事件2", "关键事件3", "关键事件4", "关键事件5"],
    "character_focus": ["重点人物1", "重点人物2"],
    "conflict": "本卷核心冲突（50字）",
    "resolution": "本卷解决方式（50字）",
    "foreshadowing": ["本卷新埋的伏笔1", "伏笔2"],
    "volume_goals": [
      "主矛盾目标：本卷必须解决的核心冲突（具体描述）",
      "人物弧目标：主角在本卷必须完成的成长节点",
      "势力目标：本卷末尾主角的势力/实力变化结果",
      "必完成事项：本卷内必须发生的具体情节（可多条）"
    ],
    "must_resolve_foreshadowing": ["上一卷或更早埋下、本卷内必须回收的伏笔描述（无则空数组）"]
  },
  ...
]

直接输出JSON，从 [ 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 解析卷大纲 JSON
 */
function extractVolumeOutlines(string $raw, int $expectedCount): array {
    // 尝试提取 JSON 数组
    $jsonStart = strpos($raw, '[');
    $jsonEnd = strrpos($raw, ']');
    
    if ($jsonStart === false || $jsonEnd === false) {
        return [];
    }
    
    $jsonStr = substr($raw, $jsonStart, $jsonEnd - $jsonStart + 1);
    $volumes = json_decode($jsonStr, true);
    
    if (!is_array($volumes)) {
        return [];
    }
    
    // 过滤和规范化
    $result = [];
    foreach ($volumes as $vol) {
        if (empty($vol['volume_number'])) continue;
        
        $result[] = [
            'volume_number'              => (int)$vol['volume_number'],
            'title'                      => $vol['title'] ?? '',
            'summary'                    => $vol['summary'] ?? '',
            'theme'                      => $vol['theme'] ?? '',
            'start_chapter'              => (int)($vol['start_chapter'] ?? 0),
            'end_chapter'                => (int)($vol['end_chapter'] ?? 0),
            'key_events'                 => is_array($vol['key_events']) ? $vol['key_events'] : [],
            'character_focus'            => is_array($vol['character_focus']) ? $vol['character_focus'] : [],
            'conflict'                   => $vol['conflict'] ?? '',
            'resolution'                 => $vol['resolution'] ?? '',
            'foreshadowing'              => is_array($vol['foreshadowing']) ? $vol['foreshadowing'] : [],
            'volume_goals'               => is_array($vol['volume_goals']) ? $vol['volume_goals'] : [],
            'must_resolve_foreshadowing' => is_array($vol['must_resolve_foreshadowing']) ? $vol['must_resolve_foreshadowing'] : [],
        ];
    }
    
    return $result;
}