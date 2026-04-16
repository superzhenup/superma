<?php
/**
 * 生成章节大纲 API（流式 SSE）
 * POST JSON: { novel_id, start_chapter, end_chapter }
 *
 * v3 修复：
 * 1. 每批从10章降到5章，减少单次虚构压力
 * 2. 每批调用前从数据库查出已生成的最近8章大纲，真正传入 recentOutlines
 * 3. 批次之间传递"上批最后一章的hook"，确保剧情衔接
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

// ---- 解析入参 ----
$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id']      ?? 0);
$startCh = (int)($input['start_chapter'] ?? 1);
$endCh   = (int)($input['end_chapter']   ?? 20);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

try { getModelFallbackList($novel['model_id'] ?: null); }
catch (RuntimeException $e) { sse('error', ['msg' => $e->getMessage()]); sseDone(); exit; }

$totalPrompt     = 0;
$totalCompletion = 0;

// ---- 核心修复1：每批5章，减少单次虚构压力 ----
$batchSize = 5;
$current   = $startCh;

while ($current <= $endCh) {
    $batchEnd = min($current + $batchSize - 1, $endCh);

    // ---- 核心修复2：每批前从数据库取真实的前8章大纲 ----
    // 包含：① 本次生成前已存在的章节 ② 上一批刚生成并入库的章节
    $recentOutlines = DB::fetchAll(
        'SELECT chapter_number, title, outline, hook FROM chapters
         WHERE novel_id=? AND chapter_number < ? AND outline IS NOT NULL AND outline != ""
         ORDER BY chapter_number DESC LIMIT 8',
        [$novelId, $current]
    );
    // 倒序取出后再正序排列，让 buildOutlinePrompt 看到时间顺序
    $recentOutlines = array_reverse($recentOutlines);

    // ---- 核心修复3：取上一批最后一章的hook，作为本批开头的"接力棒" ----
    $prevHook = '';
    if (!empty($recentOutlines)) {
        $lastOutline = end($recentOutlines);
        $prevHook    = trim($lastOutline['hook'] ?? '');
    }

    sse('progress', [
        'msg'   => "正在生成第 {$current}～{$batchEnd} 章大纲...",
        'start' => $current,
        'end'   => $batchEnd,
    ]);

    $messages    = buildOutlinePrompt($novel, $current, $batchEnd, $recentOutlines, $prevHook);
    $rawResponse = '';
    $usage       = ['prompt_tokens' => 0, 'completion_tokens' => 0];

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
                }, 'structured');
            },
            function (AIClient $nextAi, string $errMsg) use ($current, $batchEnd) {
                sse('model_switch', [
                    'msg'        => "模型请求失败，自动切换到「{$nextAi->modelLabel}」重试",
                    'next_model' => $nextAi->modelLabel,
                    'error'      => $errMsg,
                ]);
            }
        );
    } catch (RuntimeException $e) {
        sse('error', ['msg' => "第{$current}～{$batchEnd}章：所有模型均失败 — " . $e->getMessage()]);
        $current = $batchEnd + 1;
        continue;
    }

    $totalPrompt     += $usage['prompt_tokens'];
    $totalCompletion += $usage['completion_tokens'];

    // ---- 鲁棒解析 ----
    $outlines = extractOutlineObjects($rawResponse);
    $expected = $batchEnd - $current + 1;
    $parseNote = '';

    if (empty($outlines)) {
        sse('error', [
            'msg' => "第{$current}～{$batchEnd}章大纲解析失败（共0条），"
                   . "原始片段：" . mb_substr($rawResponse, 0, 120) . '…',
        ]);
        $current = $batchEnd + 1;
        continue;
    }

    if (count($outlines) < $expected) {
        $parseNote = "（仅解析到 " . count($outlines) . "/{$expected} 章，可能被截断）";
    }

    // ---- 入库 ----
    $saved = 0;
    foreach ($outlines as $item) {
        $chNum   = (int)($item['chapter_number'] ?? 0);
        $title   = trim($item['title']           ?? '');
        $summary = trim($item['summary']         ?? $item['outline'] ?? '');
        $kpts    = $item['key_points']            ?? [];
        $hook    = trim($item['hook']             ?? '');
        if (!$chNum) continue;

        $existing = DB::fetch(
            'SELECT id FROM chapters WHERE novel_id=? AND chapter_number=?',
            [$novelId, $chNum]
        );
        $row = [
            'title'      => $title,
            'outline'    => $summary,
            'key_points' => json_encode($kpts, JSON_UNESCAPED_UNICODE),
            'hook'       => $hook,
            'status'     => 'outlined',
        ];
        if ($existing) {
            DB::update('chapters', $row, 'id=?', [$existing['id']]);
        } else {
            DB::insert('chapters', array_merge($row, [
                'novel_id'       => $novelId,
                'chapter_number' => $chNum,
            ]));
        }
        $saved++;
    }

    addLog($novelId, 'outline', "生成第{$current}-{$batchEnd}章大纲，共{$saved}章{$parseNote}");

    // ---- 三层记忆：每满10章触发一次弧段摘要压缩 ----
    // 条件：本批结束章节是10的倍数，或本批是当前最后一批且至少有5章
    $shouldCompress = ($batchEnd % 10 === 0) ||
                      ($batchEnd >= $endCh && ($batchEnd - $current + 1) >= 5);
    if ($shouldCompress) {
        // 计算本弧段的起始章节（从上一个10的倍数+1开始）
        $arcFrom = (int)(floor(($batchEnd - 1) / 10) * 10) + 1;
        $arcTo   = $batchEnd;
        sse('progress', ['msg' => "正在压缩第{$arcFrom}-{$arcTo}章故事线摘要..."]);
        $compressed = generateAndSaveArcSummary($novel, $arcFrom, $arcTo);
        if ($compressed) {
            sse('arc_saved', ['msg' => "第{$arcFrom}-{$arcTo}章故事线摘要已保存", 'arc_from' => $arcFrom, 'arc_to' => $arcTo]);
        }
    }

    sse('batch_done', [
        'msg'               => "第 {$current}～{$batchEnd} 章大纲已保存（{$saved} 章）{$parseNote}",
        'start'             => $current,
        'end'               => $batchEnd,
        'saved'             => $saved,
        'expected'          => $expected,
        'truncated'         => $saved < $expected,
        'prompt_tokens'     => $usage['prompt_tokens'],
        'completion_tokens' => $usage['completion_tokens'],
        'total_tokens'      => $usage['prompt_tokens'] + $usage['completion_tokens'],
        'cum_prompt'        => $totalPrompt,
        'cum_completion'    => $totalCompletion,
        'cum_total'         => $totalPrompt + $totalCompletion,
    ]);

    $lastSaved = max(array_column($outlines, 'chapter_number'));
    $current   = max($batchEnd + 1, $lastSaved + 1);
}

DB::update('novels', ['status' => 'draft'], 'id=?', [$novelId]);

sse('complete', [
    'msg'               => "大纲生成完成！共 " . ($endCh - $startCh + 1) . " 章。",
    'total_chapters'    => $endCh - $startCh + 1,
    'prompt_tokens'     => $totalPrompt,
    'completion_tokens' => $totalCompletion,
    'total_tokens'      => $totalPrompt + $totalCompletion,
]);
sseDone();
