<?php
/**
 * 生成章节概要 API（流式 SSE）
 * POST JSON: { novel_id, chapter_ids? }
 * 如果不传chapter_ids，则生成所有已大纲但未生成概要的章节
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
$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId    = (int)($input['novel_id'] ?? 0);
$chapterIds = $input['chapter_ids'] ?? null;

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

// 预检：至少要有一个模型
try { getModelFallbackList($novel['model_id'] ?: null, 'synopsis'); }
catch (RuntimeException $e) { sse('error', ['msg' => $e->getMessage()]); sseDone(); exit; }

// 获取全书故事大纲
$storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id=?', [$novelId]);
if (!$storyOutline) {
    sse('error', ['msg' => '请先生成全书故事大纲']); 
    sseDone(); 
    exit;
}

// 获取待生成概要的章节
if ($chapterIds === null) {
    // 获取所有已大纲但未生成概要的章节
    $chapters = DB::fetchAll(
        'SELECT * FROM chapters
         WHERE novel_id=? AND status IN ("outlined","writing","completed") AND synopsis_id IS NULL
         ORDER BY chapter_number ASC',
        [$novelId]
    );
} else {
    $chapters = DB::fetchAll(
        'SELECT * FROM chapters WHERE novel_id=? AND id IN (' . implode(',', array_map('intval', $chapterIds)) . ')
         ORDER BY chapter_number ASC',
        [$novelId]
    );
}

if (empty($chapters)) {
    sse('error', ['msg' => '没有待生成概要的章节']);
    sseDone();
    exit;
}

$totalChapters = count($chapters);
$current = 0;
$totalPrompt = 0;
$totalCompletion = 0;

foreach ($chapters as $ch) {
    $current++;
    
    sse('progress', [
        'msg'    => "正在生成第{$ch['chapter_number']}章概要... ({$current}/{$totalChapters})",
        'chapter' => $ch['chapter_number'],
        'current' => $current,
        'total'   => $totalChapters,
    ]);
    
    $messages = buildChapterSynopsisPrompt($novel, $ch, $storyOutline);
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
        sse('error', ['msg' => "第{$ch['chapter_number']}章概要生成失败：" . $e->getMessage()]);
        continue;
    }

    $totalPrompt += $usage['prompt_tokens'];
    $totalCompletion += $usage['completion_tokens'];

    // 解析JSON
    $synopsis = extractChapterSynopsis($rawResponse);

    if (empty($synopsis)) {
        sse('error', [
            'msg' => "第{$ch['chapter_number']}章概要解析失败，跳过",
            'raw' => safe_substr($rawResponse, 0, 100),
        ]);
        continue;
    }
    
    // 保存到数据库
    $synopsisId = DB::insert('chapter_synopses', [
        'novel_id'         => $novelId,
        'chapter_number'   => $ch['chapter_number'],
        'synopsis'         => $synopsis['synopsis'] ?? '',
        'scene_breakdown'  => json_encode($synopsis['scene_breakdown'] ?? [], JSON_UNESCAPED_UNICODE),
        'dialogue_beats'   => json_encode($synopsis['dialogue_beats'] ?? [], JSON_UNESCAPED_UNICODE),
        'sensory_details'  => json_encode($synopsis['sensory_details'] ?? [], JSON_UNESCAPED_UNICODE),
        'pacing'           => $synopsis['pacing'] ?? '中',
        'cliffhanger'      => $synopsis['cliffhanger'] ?? '',
        'foreshadowing'    => json_encode($synopsis['foreshadowing'] ?? [], JSON_UNESCAPED_UNICODE),
        'callbacks'        => json_encode($synopsis['callbacks'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);
    
    // 更新章节关联
    DB::update('chapters', ['synopsis_id' => $synopsisId], 'id=?', [$ch['id']]);

    sse('chapter_done', [
        'msg'               => "第{$ch['chapter_number']}章概要已保存",
        'chapter'           => $ch['chapter_number'],
        'synopsis_preview'  => safe_substr($synopsis['synopsis'] ?? '', 0, 60) . '...',
    ]);
}

addLog($novelId, 'synopsis', "批量生成章节概要，共{$current}章");

sse('complete', [
    'msg'               => "章节概要生成完成！共{$current}章",
    'total_chapters'    => $current,
    'prompt_tokens'     => $totalPrompt,
    'completion_tokens' => $totalCompletion,
    'total_tokens'      => $totalPrompt + $totalCompletion,
]);
sseDone();
