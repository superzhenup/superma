<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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

defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/wizard/schema.php';
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

// 审计 P0（2026-06-12）：归属校验
$userId = currentUserId();
if ($novelId > 0) checkNovelOwnership($novelId, $userId);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

$targetChapters = (int)($novel['target_chapters'] ?? 100);
if ($targetChapters < 30) {
    sse('error', ['msg' => '目标章节数少于30章，无需生成卷大纲']); 
    sseDone(); exit;
}

$force = !empty($input['force']);

// 审计修复（2026-08-17 P3）：force 覆盖流程数据丢失防护（之一：校验前置）。
// 原实现 force=1 时先 DELETE 旧卷大纲，再校验故事大纲、调 AI、解析 JSON，
// 任一失败 exit 后旧数据已删不可恢复。现改为：故事大纲校验前置到卷检查与删除之前；
// DELETE 延后到 AI 生成并解析成功之后，与批量 INSERT 同事务执行（见入库段），失败可回滚。
$storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id=?', [$novelId]);
if (!$storyOutline) {
    sse('error', ['msg' => '请先生成全书故事大纲']);
    sseDone(); exit;
}

// 检查是否已有卷大纲
$existingVolumes = DB::fetchAll('SELECT id FROM volume_outlines WHERE novel_id=?', [$novelId]);
if (!empty($existingVolumes)) {
    if (!$force) {
        sse('error', ['msg' => '卷大纲已存在，如需重新生成请先确认覆盖']);
        sseDone(); exit;
    }
    // force 覆盖的 DELETE 已移至入库段事务内（AI 成功后才删），此处不再提前删除
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

// 解析 act_division（storyOutline 已在 2026-08-17 P3 修复中前置到卷检查之前获取）
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
    sse('error', safe_sse_error_payload($e, '卷大纲生成失败，请稍后重试'));
    sseDone(); exit;
}

// ---- 解析JSON ----
$volumes = extractVolumeOutlines($rawResponse, $numVolumes);

if (empty($volumes)) {
    sse('error', ['msg' => '卷大纲解析失败']);
    sseDone(); exit;
}

// ---- 入库 ----
// 审计修复（2026-08-16 P0）：卷大纲双列同写。
// 此前只写旧列 volume_number/start_chapter/end_chapter，而向导（api/wizard.php、
// includes/wizard/content.php）按新列 volume_index/chapter_from/chapter_to 排序与定位。
// 迁移已执行过的库中，经本入口生成的卷新列保持 DDL 默认值（volume_index=1, 1~50），
// 向导保存时会按 volume_index=1 把多卷 update 到同一行，造成数据错乱。
// 新列由统一迁移 v71 保证存在（ColumnMigrations::convergeVolumeOutlines）。
$volCols = wizardVolumeOutlinesColumns();

// 审计修复（2026-07-19 H-中12）：多卷插入改为事务，避免中途失败留半套卷且无法重试
// 审计修复（2026-08-17 P3）：force 覆盖的 DELETE 移入本事务（AI 生成并解析成功后才删旧卷），
// 并补齐 try/catch/rollBack：任一写失败整体回滚，旧卷大纲不丢、也不留半套新卷。
// 审计修复（2026-10-31 B5）：DB::beginTransaction() 嵌套时静默返回 false，旧实现
// 忽略返回值继续执行，其后的 commit/rollBack 会误提交/回滚外层事务
//（config_center.php 注释所描述、并已用 SAVEPOINT 修复的失败模式）。fail closed。
if (!DB::beginTransaction()) {
    throw new RuntimeException('卷大纲入库事务开启失败（已存在活动事务）');
}
try {
    if (!empty($existingVolumes)) {
        DB::execute('DELETE FROM volume_outlines WHERE novel_id=?', [$novelId]);
    }
    $saved = 0;
    foreach ($volumes as $vol) {
        $volNumber = (int)($vol['volume_number'] ?? 0);
        if (!$volNumber) continue;

        // 审计修复（2026-07-19 H-中12）：原实现 `?? 默认值` 在字段存在但为 0/空时永不生效，
        // 导致 start_chapter=0、end_chapter=0 入库 -> 卷级约束静默失效。
        // 改为 empty() 判断后再用默认值。
        $startChapter = !empty($vol['start_chapter']) ? (int)$vol['start_chapter'] : (($volNumber - 1) * $volumeSize + 1);
        $endChapter   = !empty($vol['end_chapter'])   ? (int)$vol['end_chapter']   : min($volNumber * $volumeSize, $targetChapters);

        $row = [
            'novel_id'                    => $novelId,
            // 新列：向导读写主键位（与 api/wizard.php save_volumes 保持一致）
            'volume_index'                => $volNumber,
            'chapter_from'                => $startChapter,
            'chapter_to'                  => $endChapter,
            // 旧列：install.php 老 schema 兼容
            'volume_number'               => $volNumber,
            'title'                       => trim($vol['title'] ?? "第{$volNumber}卷"),
            'summary'                     => trim($vol['summary'] ?? ''),
            'theme'                       => trim($vol['theme'] ?? ''),
            'start_chapter'               => $startChapter,
            'end_chapter'                 => $endChapter,
            'key_events'                  => json_encode($vol['key_events'] ?? [], JSON_UNESCAPED_UNICODE),
            'character_focus'             => json_encode($vol['character_focus'] ?? [], JSON_UNESCAPED_UNICODE),
            'conflict'                    => trim($vol['conflict'] ?? ''),
            'resolution'                  => trim($vol['resolution'] ?? ''),
            'foreshadowing'               => json_encode($vol['foreshadowing'] ?? [], JSON_UNESCAPED_UNICODE),
            'volume_goals'                => json_encode($vol['volume_goals'] ?? [], JSON_UNESCAPED_UNICODE),
            'must_resolve_foreshadowing'  => json_encode($vol['must_resolve_foreshadowing'] ?? [], JSON_UNESCAPED_UNICODE),
            'status'                      => 'generated',
        ];
        // 防御：过滤掉当前表中不存在的列（未迁移的老库）
        $row = array_intersect_key($row, array_flip($volCols));
        DB::insert('volume_outlines', $row);
        $saved++;
    }
    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    sse('error', safe_sse_error_payload($e, '卷大纲入库失败，请稍后重试'));
    sseDone(); exit;
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

    // 审计修复（2026-08-17 P3）：act_division 二级键（chapters/theme/key_events）可能缺失
    // 或不完整，原实现直接在 heredoc 中插值 {$act1['chapters']} 等会产生
    // Undefined array key warning 且 prompt 残缺。先统一规范化兜底，再代入模板。
    $a1c = (int)($act1['chapters'] ?? 0);
    $a1t = (string)($act1['theme'] ?? '');
    $a1e = array_slice((array)($act1['key_events'] ?? []), 0, 3);
    $a1eText = implode('、', array_map('strval', $a1e) ?: ['（待补充）']);
    $a2c = (int)($act2['chapters'] ?? 0);
    $a2t = (string)($act2['theme'] ?? '');
    $a2e = array_slice((array)($act2['key_events'] ?? []), 0, 3);
    $a2eText = implode('、', array_map('strval', $a2e) ?: ['（待补充）']);
    $a3c = (int)($act3['chapters'] ?? 0);
    $a3t = (string)($act3['theme'] ?? '');
    $a3e = array_slice((array)($act3['key_events'] ?? []), 0, 3);
    $a3eText = implode('、', array_map('strval', $a3e) ?: ['（待补充）']);

    // PHP 字符串插值 {$...} 内不支持 ?? 运算符，必须先算成普通变量
    $storyArcText        = $storyOutline['story_arc'] ?? '（无）';
    $protagonistInfoText = $novel['protagonist_info'] ?? '（无）';

    $user = <<<EOT
为小说《{$novel['title']}》设计卷级大纲。

【基本信息】
书名：{$novel['title']}
类型：{$novel['genre']}
目标总章节数：{$targetChapters}章
卷数：{$numVolumes}卷（每卷约{$volumeSize}章）

【全书故事主线】
{$storyArcText}

【三幕结构】
- 第一幕（1-{$a1c}）：{$a1t}，关键事件：{$a1eText}
- 第二幕（{$a2c}）：{$a2t}，关键事件：{$a2eText}
- 第三幕（{$a3c}）：{$a3t}，关键事件：{$a3eText}

【主角设定】
{$protagonistInfoText}

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