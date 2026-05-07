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

// 检测是否有已完成的章节——有则从章节反向推导
$completedChapters = [];
try {
    $completedChapters = DB::fetchAll(
        'SELECT chapter_number, title, outline, chapter_summary, content
         FROM chapters WHERE novel_id=? AND status="completed" AND (outline IS NOT NULL OR chapter_summary IS NOT NULL)
         ORDER BY chapter_number ASC LIMIT 200',
        [$novelId]
    );
} catch (\Throwable $e) { /* 降级：查询失败则当作无章节处理 */ }

if (!empty($completedChapters)) {
    sse('progress', ['msg' => '检测到 ' . count($completedChapters) . ' 章已有内容，将基于现有章节反向推导故事大纲...']);
    $messages = buildStoryOutlineFromChaptersPrompt($novel, $completedChapters);
} else {
    $messages = buildStoryOutlinePrompt($novel);
}
$rawResponse = '';
$usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];

try {
    withModelFallback(
        $novel['model_id'] ?: null,
        function (AIClient $ai) use ($messages, &$rawResponse, &$usage) {
            $rawResponse = '';
            // v1.11.5: 思考过程回调——CFG_SHOW_OUTLINE_THINKING=1时发送thinking事件
            // 长思考期间每收到推理token就延长超时，防止PHP/FPM超时
            $thinkingTimeout = defined('CFG_OUTLINE_THINKING_TIMEOUT') ? CFG_OUTLINE_THINKING_TIMEOUT : 600;
            $onThinking = (defined('CFG_SHOW_OUTLINE_THINKING') && CFG_SHOW_OUTLINE_THINKING)
                ? function (string $reasoning) use ($thinkingTimeout) {
                    static $lastReset = 0;
                    $now = time();
                    if ($now - $lastReset >= 10) {
                        set_time_limit($thinkingTimeout);
                        $lastReset = $now;
                    }
                    echo "event: thinking\n";
                    echo 'data: ' . json_encode(['thinking' => $reasoning], JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                  }
                : null;
            $usage = $ai->chatStream($messages, function (string $token) use (&$rawResponse, $thinkingTimeout) {
                // v1.11.5: 内容输出期间也保持长超时，防止间歇停顿被PHP kill
                static $lastContentReset = 0;
                $now = time();
                if ($now - $lastContentReset >= 30) {
                    set_time_limit($thinkingTimeout);
                    $lastContentReset = $now;
                }
                if ($token === '[DONE]') return;
                $rawResponse .= $token;
                echo "event: chunk\n";
                echo 'data: ' . json_encode(['t' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            }, 'creative', $onThinking);
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

// ---- 核对 character_progression 列是否存在（兜底迁移）----
try {
    $hasCol = DB::fetch("SHOW COLUMNS FROM story_outlines LIKE 'character_progression'");
    if (!$hasCol) {
        DB::query("ALTER TABLE `story_outlines` ADD COLUMN `character_progression` JSON DEFAULT NULL COMMENT '角色等级/境界发展轨迹' AFTER `character_endpoints`");
    }
} catch (\Throwable $e) { /* 非致命，老数据库尽力而为 */ }

// ---- 保存到数据库 ----
try {
    DB::insert('story_outlines', [
        'novel_id'             => $novelId,
        'story_arc'            => $storyOutline['story_arc'] ?? '',
        'act_division'         => json_encode($storyOutline['act_division'] ?? [], JSON_UNESCAPED_UNICODE),
        'major_turning_points' => json_encode($storyOutline['major_turning_points'] ?? [], JSON_UNESCAPED_UNICODE),
        'character_arcs'       => json_encode($storyOutline['character_arcs'] ?? [], JSON_UNESCAPED_UNICODE),
        'character_endpoints'  => $characterEndpoints ?: null,
        'character_progression' => json_encode($storyOutline['character_progression'] ?? [], JSON_UNESCAPED_UNICODE),
        'world_evolution'      => $storyOutline['world_evolution'] ?? '',
        'recurring_motifs'     => json_encode($storyOutline['recurring_motifs'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);

    // 更新小说状态
    DB::update('novels', ['has_story_outline' => 1], 'id=?', [$novelId]);

    // 将故事大纲的转折点回写到对应卷
    $presetVolumes = DB::fetchAll(
        'SELECT id, volume_number, start_chapter, end_chapter FROM volume_outlines WHERE novel_id=? ORDER BY volume_number ASC',
        [$novelId]
    );
    if (!empty($presetVolumes)) {
        $turningPoints = $storyOutline['major_turning_points'] ?? [];
        foreach ($presetVolumes as $pv) {
            $volTurnings = array_values(array_filter($turningPoints, function($tp) use ($pv) {
                $ch = (int)($tp['chapter'] ?? 0);
                return $ch >= $pv['start_chapter'] && $ch <= $pv['end_chapter'];
            }));
            $updateData = ['status' => 'generated'];
            if (!empty($volTurnings)) {
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

    addLog($novelId, 'story_outline', "生成全书故事大纲");

    sse('complete', [
        'msg'               => '全书故事大纲生成完成！',
        'story_arc'         => $storyOutline['story_arc'] ?? '',
        'prompt_tokens'     => $usage['prompt_tokens'],
        'completion_tokens' => $usage['completion_tokens'],
        'total_tokens'      => $usage['prompt_tokens'] + $usage['completion_tokens'],
    ]);
} catch (\Throwable $e) {
    sse('error', [
        'msg' => '保存故事大纲失败：' . $e->getMessage(),
    ]);
}

sseDone();
exit;
