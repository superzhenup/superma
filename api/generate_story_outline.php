<?php
/**
 * 生成全书故事大纲 API（流式 SSE）
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
set_time_limit(CFG_TIME_LONG);

// 关闭所有输出缓冲，确保 SSE 实时推送
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

// 预检：至少要有一个模型
try { getModelFallbackList($novel['model_id'] ?: null, 'structured'); }
catch (RuntimeException $e) { sse('error', ['msg' => $e->getMessage()]); sseDone(); exit; }

// 检查是否已存在故事大纲——重新生成时先删除旧记录
$existing = DB::fetch('SELECT id FROM story_outlines WHERE novel_id=?', [$novelId]);
if ($existing) {
    DB::delete('story_outlines', 'novel_id=?', [$novelId]);
    addLog($novelId, 'story_outline', '重新生成：已删除旧故事大纲');
    sse('progress', ['msg' => '已删除旧故事大纲，正在重新生成...']);
}

sse('progress', ['msg' => '正在生成全书故事大纲...']);

$messages = buildStoryOutlinePrompt($novel);
$rawResponse = '';
$usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];

try {
    withModelFallback(
        $novel['model_id'] ?: null,
        function (AIClient $ai) use ($messages, &$rawResponse, &$usage) {
            $rawResponse = '';
            $usage = $ai->chatStream($messages, function (string $token) use (&$rawResponse) {
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
    sse('error', ['msg' => '所有模型均失败 — ' . $e->getMessage()]);
    sseDone(); exit;
}

// ---- 解析JSON ----
$storyOutline = extractStoryOutline($rawResponse);

if (empty($storyOutline)) {
    sse('error', [
        'msg' => '故事大纲解析失败，原始片段：' . safe_substr($rawResponse, 0, 200) . '…'
    ]);
    sseDone(); exit;
}

// ---- 提取人物弧线终点（AI 直接提供则用；否则从 character_arcs 中提取） ----
require_once __DIR__ . '/../includes/helpers.php';
$characterEndpoints = $storyOutline['character_endpoints'] ?? '';
if (empty($characterEndpoints) && !empty($storyOutline['character_arcs'])) {
    $characterEndpoints = extractCharacterEndpoints($storyOutline['character_arcs']);
}

// ---- 保存到数据库 ----
DB::insert('story_outlines', [
    'novel_id'             => $novelId,
    'story_arc'            => $storyOutline['story_arc'] ?? '',
    'act_division'         => json_encode($storyOutline['act_division'] ?? [], JSON_UNESCAPED_UNICODE),
    'major_turning_points' => json_encode($storyOutline['major_turning_points'] ?? [], JSON_UNESCAPED_UNICODE),
    'character_arcs'       => json_encode($storyOutline['character_arcs'] ?? [], JSON_UNESCAPED_UNICODE),
    'character_endpoints'  => $characterEndpoints ?: null,
    'world_evolution'      => $storyOutline['world_evolution'] ?? '',
    'recurring_motifs'     => json_encode($storyOutline['recurring_motifs'] ?? [], JSON_UNESCAPED_UNICODE),
]);

// 更新小说状态
DB::update('novels', ['has_story_outline' => 1], 'id=?', [$novelId]);

// 将故事大纲的转折点回写到对应卷（丰富卷信息，不覆盖用户已填的标题/范围）
try {
    $presetVolumes = DB::fetchAll(
        'SELECT id, volume_number, start_chapter, end_chapter FROM volume_outlines WHERE novel_id=? ORDER BY volume_number ASC',
        [$novelId]
    );
    if (!empty($presetVolumes)) {
        $turningPoints = $storyOutline['major_turning_points'] ?? [];
        foreach ($presetVolumes as $pv) {
            // 找属于本卷的转折点
            $volTurnings = array_values(array_filter($turningPoints, function($tp) use ($pv) {
                $ch = (int)($tp['chapter'] ?? 0);
                return $ch >= $pv['start_chapter'] && $ch <= $pv['end_chapter'];
            }));
            $updateData = ['status' => 'generated'];
            if (!empty($volTurnings)) {
                // 把转折点作为 key_events 写入（追加，不覆盖已有）
                $existingEvents = json_decode(
                    DB::fetch('SELECT key_events FROM volume_outlines WHERE id=?', [$pv['id']])['key_events'] ?? '[]',
                    true
                ) ?: [];
                $newEvents = array_merge($existingEvents,
                    array_map(fn($t) => "第{$t['chapter']}章：{$t['event']}", $volTurnings)
                );
                $updateData['key_events'] = json_encode(array_values(array_unique($newEvents)), JSON_UNESCAPED_UNICODE);
            }
            DB::update('volume_outlines', $updateData, 'id=?', [$pv['id']]);
        }
        sse('progress', ['msg' => '卷结构已与故事大纲关联完成']);
    }
} catch (\Throwable $e) {
    // 回写失败不影响主流程
}

addLog($novelId, 'story_outline', "生成全书故事大纲");

sse('complete', [
    'msg'               => '全书故事大纲生成完成！',
    'story_arc'         => $storyOutline['story_arc'] ?? '',
    'prompt_tokens'     => $usage['prompt_tokens'],
    'completion_tokens' => $usage['completion_tokens'],
    'total_tokens'      => $usage['prompt_tokens'] + $usage['completion_tokens'],
]);
sseDone();
