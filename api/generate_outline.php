<?php
/**
 * 生成章节大纲 API（流式 SSE）
 * POST JSON: { novel_id, start_chapter, end_chapter }
 *
 * v5 重构：
 * - 每次请求只生成 1 批（默认 5 章），前端循环调用
 * - SSE 连接时间短（30-60秒），不会触发 Nginx/PHP 超时
 * - 保留心跳机制、模型降级、记忆上下文
 */

// 强制禁用输出缓冲（必须在任何输出之前）
ini_set('output_buffering', 'Off');
ini_set('implicit_flush', 'On');
ini_set('zlib.output_compression', 'Off');

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
session_write_close();

ob_end_clean();
set_time_limit(CFG_TIME_LONG); // 增加到600秒，防止思考型模型超时
ignore_user_abort(true); // 前端断开连接后继续执行完成入库操作

while (ob_get_level()) ob_end_clean();

// 全局异常捕获
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
    }
    echo "event: fatal_error\n";
    echo 'data: ' . json_encode([
        'type'    => get_class($e),
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
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

// 初始确认
echo "event: init\n";
echo 'data: ' . json_encode(['msg' => 'SSE 连接已建立']) . "\n\n";
if (ob_get_level()) ob_flush();
flush();

// ---- 解析入参 ----
$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id']      ?? 0);
$startCh = (int)($input['start_chapter'] ?? 1);
$endCh   = (int)($input['end_chapter']   ?? $startCh + 4);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

try { getModelFallbackList($novel['model_id'] ?: null); }
catch (RuntimeException $e) { sse('error', ['msg' => $e->getMessage()]); sseDone(); exit; }

// 限制每批最多 10 章，防止单次请求过大
$batchSize = max(3, min(10, (int)getSystemSetting('ws_outline_batch', 5, 'int')));
if ($endCh - $startCh + 1 > $batchSize) {
    $endCh = $startCh + $batchSize - 1;
}

try {
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    require_once dirname(__DIR__) . '/includes/prompt.php';
    $engine = new MemoryEngine($novelId);

    // 获取卷大纲上下文
    $currentVolume = null;
    $volumeOutlines = DB::fetchAll(
        'SELECT * FROM volume_outlines WHERE novel_id=? AND start_chapter <= ? AND end_chapter >= ?',
        [$novelId, $startCh, $startCh]
    );
    if (!empty($volumeOutlines)) {
        $currentVolume = $volumeOutlines[0];
    }

    // 获取近章大纲上下文
    $memoryLookback = max(3, min(20, (int)getSystemSetting('ws_memory_lookback', 8, 'int')));
    $recentOutlines = DB::fetchAll(
        'SELECT chapter_number, title, outline, hook, pacing, suspense FROM chapters
         WHERE novel_id=? AND chapter_number < ? AND outline IS NOT NULL AND outline != ""
         ORDER BY chapter_number DESC LIMIT ?',
        [$novelId, $startCh, $memoryLookback]
    );
    $recentOutlines = array_reverse($recentOutlines);

    // 取上一章 hook
    $prevHook = '';
    if (!empty($recentOutlines)) {
        $lastOutline = end($recentOutlines);
        $prevHook    = trim($lastOutline['hook'] ?? '');
    }

    sse('progress', [
        'msg'   => "正在生成第 {$startCh}～{$endCh} 章大纲...",
        'start' => $startCh,
        'end'   => $endCh,
    ]);

    // 获取记忆上下文
    $queryText = trim(($novel['genre'] ?? '') . '：' . ($novel['plot_settings'] ?? ''));
    $semanticTopK = max(1, min(20, (int)getSystemSetting('ws_embedding_top_k', 6, 'int')));
    try {
        $memoryCtx = $engine->getPromptContext(
            $startCh,
            $queryText !== '：' ? $queryText : null,
            5000,
            20,
            $semanticTopK
        );
    } catch (Throwable $e) {
        $memoryCtx = null;
        sse('progress', ['msg' => '记忆上下文获取失败，继续生成：' . $e->getMessage()]);
    }

    // 爽点调度
    $coolPointSchedule = '';
    try {
        $coolPointHistory = ($memoryCtx['cool_point_history'] ?? []);
        $count = $endCh - $startCh + 1;
        $coolPointSchedule = calculateCoolPointSchedule($startCh, $count, $coolPointHistory);
    } catch (Throwable $e) {
        $coolPointSchedule = '';
    }

    // 构建 prompt
    $messages    = buildOutlinePrompt($novel, $startCh, $endCh, $recentOutlines, $prevHook, $memoryCtx, $currentVolume);
    $rawResponse = '';
    $usage       = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    $lastHeartbeat = time();

    // 注册全局心跳
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

    try {
        withModelFallback(
            $novel['model_id'] ?: null,
            function (AIClient $ai) use ($messages, &$rawResponse, &$usage, &$lastHeartbeat) {
                $rawResponse = '';
                $usage = $ai->chatStream($messages, function (string $token) use (&$rawResponse, &$lastHeartbeat) {
                    // 心跳
                    $now = time();
                    if ($now - $lastHeartbeat >= 3) {
                        echo "event: heartbeat\n";
                        echo "data: " . json_encode(['time' => $now, 'msg' => 'keep-alive']) . "\n\n";
                        if (ob_get_level()) ob_flush();
                        flush();
                        $lastHeartbeat = $now;
                    }

                    if ($token === '[DONE]') return;
                    $rawResponse .= $token;
                    echo "event: chunk\n";
                    echo 'data: ' . json_encode(['t' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                }, 'structured');
            },
            function (AIClient $nextAi, string $errMsg) use ($startCh, $endCh) {
                sse('model_switch', [
                    'msg'        => "模型请求失败，自动切换到「{$nextAi->modelLabel}」重试",
                    'next_model' => $nextAi->modelLabel,
                    'error'      => $errMsg,
                ]);
            }
        );
    } catch (RuntimeException $e) {
        sse('error', ['msg' => "第{$startCh}～{$endCh}章：所有模型均失败 — " . $e->getMessage()]);
        sseDone();
        exit;
    }

    unset($GLOBALS['sendHeartbeat']);

    // 解析
    $outlines = extractOutlineObjects($rawResponse);
    $expected = $endCh - $startCh + 1;
    $parseNote = '';

    if (empty($outlines)) {
        sse('error', [
            'msg' => "第{$startCh}～{$endCh}章大纲解析失败（共0条），"
                   . "原始片段：" . safe_substr($rawResponse, 0, 120) . '…',
        ]);
        sseDone();
        exit;
    }

    if (count($outlines) < $expected) {
        $parseNote = "（仅解析到 " . count($outlines) . "/{$expected} 章，可能被截断）";
    }

    // 入库
    $saved = 0;
    foreach ($outlines as $item) {
        $chNum   = (int)($item['chapter_number'] ?? 0);
        $title   = trim($item['title']           ?? '');
        $summary = trim($item['summary']         ?? $item['outline'] ?? '');
        $kpts    = $item['key_points']            ?? [];
        $hook    = trim($item['hook']             ?? '');
        $pacing  = trim($item['pacing']           ?? '中');
        $suspense = trim($item['suspense']        ?? '无');
        $hookType     = trim($item['hook_type']      ?? '');
        $coolPtType   = trim($item['cool_point_type'] ?? '');
        if (!in_array($pacing, ['快', '中', '慢'])) $pacing = '中';
        if (!in_array($suspense, ['有', '无'])) $suspense = '无';
        $validHookTypes = array_keys(\HOOK_TYPES);
        if ($hookType && !in_array($hookType, $validHookTypes)) $hookType = '';
        $validCoolTypes = array_keys(\COOL_POINT_TYPES);
        if ($coolPtType && !in_array($coolPtType, $validCoolTypes)) $coolPtType = '';

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
            'pacing'     => $pacing,
            'suspense'   => $suspense,
            'status'     => 'outlined',
        ];
        if ($hookType !== '') $row['hook_type'] = $hookType;
        if ($coolPtType !== '') $row['cool_point_type'] = $coolPtType;
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

    addLog($novelId, 'outline', "生成第{$startCh}-{$endCh}章大纲，共{$saved}章{$parseNote}");

    // 弧段摘要压缩（每 10 章）
    $shouldCompress = ($endCh % 10 === 0) || (($endCh - $startCh + 1) >= 5 && count($outlines) >= 3);
    if ($shouldCompress) {
        $arcFrom = (int)(floor(($endCh - 1) / 10) * 10) + 1;
        $arcTo   = $endCh;
        try {
            $compressed = generateAndSaveArcSummary($novel, $arcFrom, $arcTo);
            if ($compressed) {
                sse('arc_saved', ['msg' => "第{$arcFrom}-{$arcTo}章故事线摘要已保存", 'arc_from' => $arcFrom, 'arc_to' => $arcTo]);
            }
        } catch (Throwable $e) {
            // 静默失败
        }
    }

    // 更新小说状态
    $totalOutlined = DB::count('chapters', 'novel_id=? AND status != "pending"', [$novelId]);
    if ($totalOutlined >= (int)$novel['target_chapters']) {
        DB::update('novels', ['status' => 'draft'], 'id=?', [$novelId]);
    }

    sse('batch_done', [
        'msg'               => "第 {$startCh}～{$endCh} 章大纲已保存（{$saved} 章）{$parseNote}",
        'start'             => $startCh,
        'end'               => $endCh,
        'saved'             => $saved,
        'expected'          => $expected,
        'truncated'         => $saved < $expected,
        'prompt_tokens'     => $usage['prompt_tokens'],
        'completion_tokens' => $usage['completion_tokens'],
        'total_tokens'      => $usage['prompt_tokens'] + $usage['completion_tokens'],
    ]);

    sseDone();

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(200);
    }
    echo "event: fatal_error\n";
    echo 'data: ' . json_encode([
        'type'    => get_class($e),
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine()
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    // 确保 [DONE] 被发送，让前端知道流已正常结束
    echo "data: [DONE]\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}
