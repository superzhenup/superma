<?php
/**
 * 章节大纲补写 API（流式 SSE）
 * 
 * 功能：检测目标章节范围内缺失的大纲，自动补写
 * POST JSON: { novel_id }
 * 
 * 逻辑：
 * 1. 查询小说的目标章节数
 * 2. 查询已有大纲的章节号
 * 3. 找出缺失的章节号（status='pending' 或不存在记录）
 * 4. 将缺失章节按连续段分组，逐段调用 AI 补写
 * 
 * 优化：
 * - 强制禁用输出缓冲，确保 SSE 实时传输
 */

// 强制禁用输出缓冲（必须在任何输出之前）
ini_set('output_buffering', 'Off');
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
session_write_close(); // 释放 Session 锁，防止补写期间其他页面被阻塞

ob_end_clean();
set_time_limit(CFG_TIME_LONG);
ignore_user_abort(true);

// 关闭所有输出缓冲，确保 SSE 实时推送
while (ob_get_level()) ob_end_clean();

// 全局异常捕获，确保发生错误时正常结束SSE连接
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
    }
    echo "event: error\n";
    echo 'data: ' . json_encode([
        'msg' => 'Fatal Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
});

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
        }
        echo "event: error\n";
        echo 'data: ' . json_encode([
            'msg' => 'Fatal Shutdown Error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
});

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Content-Encoding: none');
header('Connection: keep-alive');

$lastHeartbeat = time();
$GLOBALS['sendHeartbeat'] = function() use (&$lastHeartbeat) {
    $now = time();
    if ($now - $lastHeartbeat >= 3) {
        echo "event: heartbeat\n";
        echo "data: " . json_encode(['time' => $now, 'msg' => 'keep-alive']) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        $lastHeartbeat = $now;
    }
};

// ---- 解析入参 ----
$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

// 预检：至少要有一个模型
try { getModelFallbackList($novel['model_id'] ?: null); }
catch (RuntimeException $e) { sse('error', ['msg' => $e->getMessage()]); sseDone(); exit; }

// 初始化记忆引擎
require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
$engine = new MemoryEngine($novelId);

$targetChapters = (int)$novel['target_chapters'];

// ---- 检测缺失章节 ----
$existingRows = DB::fetchAll(
    'SELECT chapter_number, status FROM chapters 
     WHERE novel_id=? AND chapter_number>=1 AND chapter_number<=?
     ORDER BY chapter_number ASC',
    [$novelId, $targetChapters]
);

// 构建已有大纲的章节号集合（status 不是 pending）
$outlinedSet = [];
foreach ($existingRows as $row) {
    if ($row['status'] !== 'pending') {
        $outlinedSet[(int)$row['chapter_number']] = true;
    }
}

// 找出所有缺失的章节号
$missingChapters = [];
for ($i = 1; $i <= $targetChapters; $i++) {
    if (!isset($outlinedSet[$i])) {
        $missingChapters[] = $i;
    }
}

if (empty($missingChapters)) {
    sse('complete', [
        'msg'         => '所有章节大纲已完整，无需补写。',
        'supplemented' => 0,
        'total_missing' => 0,
    ]);
    sseDone();
    exit;
}

// ---- 将缺失章节按连续段分组 ----
// 例：[3,4,5,8,9,12] → [[3,4,5],[8,9],[12]]
$segments = [];
$segStart = $missingChapters[0];
$segEnd   = $missingChapters[0];
for ($i = 1; $i < count($missingChapters); $i++) {
    if ($missingChapters[$i] === $segEnd + 1) {
        $segEnd = $missingChapters[$i];
    } else {
        $segments[] = ['start' => $segStart, 'end' => $segEnd];
        $segStart = $missingChapters[$i];
        $segEnd   = $missingChapters[$i];
    }
}
$segments[] = ['start' => $segStart, 'end' => $segEnd];

$totalMissing = count($missingChapters);
sse('scan_result', [
    'msg'           => "检测到 {$totalMissing} 个章节缺失大纲，将分 " . count($segments) . " 段补写。",
    'missing_count'  => $totalMissing,
    'segment_count'  => count($segments),
    'missing_list'   => $missingChapters,
]);

// ---- 全局 token 累计 ----
$totalPrompt     = 0;
$totalCompletion = 0;
$totalSupplemented = 0;

// ---- 逐段补写 ----
// v11: 从系统设置读取大纲批量数
$batchSize = max(3, min(10, (int)getSystemSetting('ws_outline_batch', 5, 'int')));

foreach ($segments as $segIdx => $seg) {
    $segStart = $seg['start'];
    $segEnd   = $seg['end'];
    
    // 如果段太长，拆成小批次
    $current = $segStart;
    while ($current <= $segEnd) {
        $batchEnd = min($current + $batchSize - 1, $segEnd);
        
        sse('progress', [
            'msg'   => "正在补写第 {$current}～{$batchEnd} 章大纲...",
            'start' => $current,
            'end'   => $batchEnd,
        ]);

        // 获取前几章大纲作为上下文（保持连贯性）
        $recentOutlines = DB::fetchAll(
            'SELECT chapter_number, title, outline FROM chapters 
             WHERE novel_id=? AND chapter_number<? AND status IN ("outlined","writing","completed")
             ORDER BY chapter_number DESC LIMIT 5',
            [$novelId, $current]
        );
        $recentOutlines = array_reverse($recentOutlines);

        // 取记忆上下文
        $queryText = trim(($novel['genre'] ?? '') . '：' . ($novel['plot_settings'] ?? ''));
        try {
            $memoryCtx = $engine->getPromptContext(
                $current,
                $queryText !== '：' ? $queryText : null,
                5000,
                20,
                6
            );
        } catch (Throwable $e) {
            $memoryCtx = null;
        }

        $messages    = buildOutlinePrompt($novel, $current, $batchEnd, $recentOutlines, '', $memoryCtx);
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
                    });
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
            sse('error', ['msg' => "第{$current}～{$batchEnd}章补写失败 — " . $e->getMessage()]);
            $current = $batchEnd + 1;
            continue;
        }

        $totalPrompt     += $usage['prompt_tokens'];
        $totalCompletion += $usage['completion_tokens'];

        // ---- 鲁棒解析 ----
        $outlines  = extractOutlineObjects($rawResponse);
        $expected  = $batchEnd - $current + 1;

        if (empty($outlines)) {
            sse('error', [
                'msg' => "第{$current}～{$batchEnd}章大纲解析失败，原始片段：" 
                       . safe_substr($rawResponse, 0, 120) . '…',
            ]);
            $current = $batchEnd + 1;
            continue;
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
                'SELECT id, status FROM chapters WHERE novel_id=? AND chapter_number=?',
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
                // 如果是 pending 状态，更新为 outlined
                DB::update('chapters', $row, 'id=?', [$existing['id']]);
            } else {
                DB::insert('chapters', array_merge($row, [
                    'novel_id'       => $novelId,
                    'chapter_number' => $chNum,
                ]));
            }
            $saved++;
        }

        $totalSupplemented += $saved;
        $parseNote = $saved < $expected ? "（仅解析到 {$saved}/{$expected} 章）" : '';

        addLog($novelId, 'supplement', "补写第{$current}-{$batchEnd}章大纲，共{$saved}章{$parseNote}");

        sse('batch_done', [
            'msg'               => "第 {$current}～{$batchEnd} 章补写完成（{$saved} 章）{$parseNote}",
            'start'             => $current,
            'end'               => $batchEnd,
            'saved'             => $saved,
            'expected'          => $expected,
            'prompt_tokens'     => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'total_tokens'      => $usage['prompt_tokens'] + $usage['completion_tokens'],
            'cum_supplemented'  => $totalSupplemented,
        ]);

        $current = $batchEnd + 1;
    }
}

DB::update('novels', ['status' => 'draft'], 'id=?', [$novelId]);

sse('complete', [
    'msg'               => "大纲补写完成！共补写 {$totalSupplemented} 章（原缺失 {$totalMissing} 章）。",
    'supplemented'      => $totalSupplemented,
    'total_missing'     => $totalMissing,
    'prompt_tokens'     => $totalPrompt,
    'completion_tokens' => $totalCompletion,
    'total_tokens'      => $totalPrompt + $totalCompletion,
]);
sseDone();
