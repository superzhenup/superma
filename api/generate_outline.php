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
// 注意：output_buffering 是 PHP_INI_PERDIR 级别，ini_set() 无法修改
// 改用 ob_end_clean() 在运行时清除缓冲区
while (ob_get_level()) ob_end_clean();
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

// v1.6: 动态超时机制
// 初始设置较长的基础超时，后续每次有输出时重置静默超时
// 这样只要有输出就不会超时，只有连续静默才会触发
$baseTimeLimit       = defined('CFG_TIME_LONG') ? CFG_TIME_LONG : 600;
$silenceTimeout      = defined('CFG_TIME_SILENCE_TIMEOUT') ? CFG_TIME_SILENCE_TIMEOUT : 120;
$lastOutputTime      = time();
$maxExecutionTime    = 7200; // 绝对最大执行时间（2小时），防止无限运行

// 存入 $GLOBALS 供回调函数访问
$GLOBALS['silenceTimeout']   = $silenceTimeout;
$GLOBALS['maxExecutionTime'] = $maxExecutionTime;

set_time_limit($baseTimeLimit);
ignore_user_abort(true); // 前端断开连接后继续执行完成入库操作

/**
 * v1.6: 动态重置超时 — 只要有输出就重置计时器
 * 只有连续静默超过阈值才真正超时
 * 优化：限流 5 秒内不重复调用 set_time_limit，避免高频 token 下的系统调用开销
 *
 * @param int $silenceTimeout 静默超时时间（秒）
 * @param int $maxTotal       绝对最大执行时间（秒）
 */
function resetDynamicTimeout(int $silenceTimeout, int $maxTotal = 7200): void
{
    static $startTime = null;
    static $lastReset = 0;
    if ($startTime === null) {
        $startTime = time();
        $lastReset = time();
    }

    $now = time();

    // 检查是否超过绝对最大时间
    if ($now - $startTime > $maxTotal) {
        return;
    }

    // 限流：5秒内不重复调用 set_time_limit
    if ($now - $lastReset < 5) {
        return;
    }

    set_time_limit($silenceTimeout);
    $lastReset = $now;
}

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

try { getModelFallbackList($novel['model_id'] ?: null, 'structured'); }
catch (RuntimeException $e) { sse('error', ['msg' => $e->getMessage()]); sseDone(); exit; }

// 检测模型是否支持 1M 上下文，决定批量大小和超时
$is1MModel = false;
$silenceTimeout = defined('CFG_TIME_SILENCE_TIMEOUT') ? CFG_TIME_SILENCE_TIMEOUT : 180; // 默认静默超时
try {
    $aiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
    $is1MModel = $aiClient->is1MContext();
    if ($is1MModel) {
        // 1M 模式使用更长的静默超时
        $silenceTimeout = defined('CFG_TIME_SILENCE_1M') ? CFG_TIME_SILENCE_1M : 600;
        set_time_limit($silenceTimeout);
    }
} catch (Throwable $e) {
    // 忽略，使用默认配置
}

// v1.11.5: 长思考超时——深度思考模型推理过程可能很长，使用独立超时
$thinkingTimeout = defined('CFG_OUTLINE_THINKING_TIMEOUT') ? CFG_OUTLINE_THINKING_TIMEOUT : 600;
// 长思考超时不应短于普通静默超时
if ($thinkingTimeout < $silenceTimeout) $thinkingTimeout = $silenceTimeout;

// 从系统设置读取批量数，1M模型使用更大的批量
if ($is1MModel) {
    $batchSize = max(10, min(100, (int)getSystemSetting('ws_outline_batch_1m', 30, 'int')));
} else {
    $batchSize = max(3, min(50, (int)getSystemSetting('ws_outline_batch', 5, 'int')));
}

if ($endCh - $startCh + 1 > $batchSize) {
    $endCh = $startCh + $batchSize - 1;
}

try {
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    require_once dirname(__DIR__) . '/includes/prompt.php';
    $engine = new MemoryEngine($novelId);

    // 获取卷大纲上下文（跨卷时同时注入两卷上下文）
    $currentVolume = null;
    $volumeOutlines = DB::fetchAll(
        'SELECT * FROM volume_outlines WHERE novel_id=? AND start_chapter <= ? AND end_chapter >= ?',
        [$novelId, $endCh, $startCh]
    );
    if (!empty($volumeOutlines)) {
        $currentVolume = $volumeOutlines[0];
        if (count($volumeOutlines) > 1) {
            $nextVol = $volumeOutlines[1];
            $extraEvents = json_decode($nextVol['key_events'] ?? '[]', true) ?: [];
            $currentVolume['_next_volume_title'] = $nextVol['title'] ?? '';
            $currentVolume['_next_volume_theme'] = $nextVol['theme'] ?? '';
            $currentVolume['_next_volume_conflict'] = $nextVol['conflict'] ?? '';
            $currentVolume['_next_volume_key_events'] = implode('、', array_slice($extraEvents, 0, 3));
        }
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
        sse('progress', ['msg' => '记忆上下文获取失败，使用降级上下文：' . $e->getMessage()]);
    }

    // 降级回退：MemoryEngine 失败时，手动构建最小记忆上下文，防止 AI 裸奔
    if ($memoryCtx === null) {
        $memoryCtx = [];
        try {
            // 人物状态降级
            $cards = DB::fetchAll(
                'SELECT name, title, status, alive FROM character_cards WHERE novel_id=? AND alive=1',
                [$novelId]
            );
            $charStates = [];
            foreach ($cards as $c) {
                $charStates[$c['name']] = ['title' => $c['title'] ?? '', 'status' => $c['status'] ?? '', 'alive' => true];
            }
            $memoryCtx['character_states'] = $charStates;

            // 关键事件 + 爽点历史合并查询（原2次查询合并为1次）
            $allAtoms = DB::fetchAll(
                'SELECT source_chapter, atom_type, content, metadata FROM memory_atoms
                 WHERE novel_id=? AND atom_type IN ("plot_detail","cool_point") AND source_chapter IS NOT NULL
                 ORDER BY source_chapter DESC LIMIT 30',
                [$novelId]
            );
            $keyEvents = [];
            $coolHistory = [];
            foreach (array_reverse($allAtoms) as $atom) {
                if ($atom['atom_type'] === 'plot_detail') {
                    $meta = json_decode($atom['metadata'] ?? '{}', true) ?: [];
                    if (!empty($meta['is_key_event'])) {
                        $keyEvents[] = ['chapter' => (int)$atom['source_chapter'], 'event' => $atom['content']];
                    }
                } elseif ($atom['atom_type'] === 'cool_point') {
                    $meta = json_decode($atom['metadata'] ?? '{}', true) ?: [];
                    $coolHistory[] = ['chapter' => (int)$atom['source_chapter'], 'type' => $meta['cool_type'] ?? '', 'name' => $meta['type_name'] ?? ''];
                }
            }
            $memoryCtx['key_events'] = $keyEvents;
            $memoryCtx['cool_point_history'] = $coolHistory;

            // 伏笔降级
            $foreshadows = DB::fetchAll(
                'SELECT planted_chapter AS chapter, description AS desc, deadline_chapter AS deadline
                 FROM foreshadowing_items WHERE novel_id=? AND resolved_chapter IS NULL
                 ORDER BY planted_chapter ASC LIMIT 10',
                [$novelId]
            );
            $memoryCtx['pending_foreshadowing'] = $foreshadows;

            // 故事势能降级
            $ns = DB::fetch('SELECT story_momentum FROM novel_state WHERE novel_id=?', [$novelId]);
            $memoryCtx['story_momentum'] = $ns['story_momentum'] ?? '';
        } catch (Throwable $e2) {
            // 降级也失败，保持 $memoryCtx 为空数组（非 null），prompt 层会正确处理
        }
    }

    // 预取全书故事大纲全字段注入 $novel，避免 buildOutlinePrompt 内重复查询
    $storyOutline = null;
    try {
        $storyOutline = DB::fetch(
            'SELECT story_arc, act_division, character_arcs, character_progression, world_evolution, major_turning_points, recurring_motifs FROM story_outlines WHERE novel_id=?',
            [$novelId]
        );
    } catch (Throwable) {
        try {
            $storyOutline = DB::fetch(
                'SELECT story_arc, act_division, character_arcs, world_evolution, major_turning_points, recurring_motifs FROM story_outlines WHERE novel_id=?',
                [$novelId]
            );
        } catch (Throwable) {
            $storyOutline = null;
        }
    }
    if ($storyOutline) {
        $novel['_story_outline'] = $storyOutline;
    }

    // 查询全书已有章节标题（防重复）
    $existingTitleRows = DB::fetchAll(
        'SELECT chapter_number, title FROM chapters WHERE novel_id=? AND title IS NOT NULL AND title != "" ORDER BY chapter_number ASC',
        [$novelId]
    );
    $existingTitles = array_column($existingTitleRows, 'title', 'chapter_number');

    // 构建 prompt
    $messages    = buildOutlinePrompt($novel, $startCh, $endCh, $recentOutlines, $prevHook, $memoryCtx, $currentVolume, $existingTitles);
    $rawResponse = '';
    $usage       = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    $lastHeartbeat = time();

    // 注册全局心跳
    $GLOBALS['sendHeartbeat'] = function() use (&$lastHeartbeat, $silenceTimeout) {
        $now = time();
        if ($now - $lastHeartbeat >= 3) {
            // v1.6: 心跳时也重置动态超时
            resetDynamicTimeout($silenceTimeout, $GLOBALS['maxExecutionTime'] ?? 7200);

            echo "event: heartbeat\n";
            echo "data: " . json_encode(['time' => $now, 'msg' => 'keep-alive']) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            $lastHeartbeat = $now;
        }
    };

    $outlines = [];
    $maxParseRetries = 2;
    for ($parseAttempt = 1; $parseAttempt <= $maxParseRetries; $parseAttempt++) {
        $rawResponse = '';
        $usage       = ['prompt_tokens' => 0, 'completion_tokens' => 0];

        try {
            withModelFallback(
                $novel['model_id'] ?: null,
                function (AIClient $ai) use ($messages, &$rawResponse, &$usage, &$lastHeartbeat, $silenceTimeout, $thinkingTimeout) {
                    $rawResponse = '';
                    // v1.11.5: 思考过程回调——CFG_SHOW_OUTLINE_THINKING=1时发送thinking事件
                    // 使用长思考超时确保深度推理不超时
                    $effectiveTimeout = $thinkingTimeout;
                    $onThinking = (defined('CFG_SHOW_OUTLINE_THINKING') && CFG_SHOW_OUTLINE_THINKING)
                        ? function (string $reasoning) use (&$lastHeartbeat, $effectiveTimeout) {
                            resetDynamicTimeout($effectiveTimeout, $GLOBALS['maxExecutionTime'] ?? 7200);
                            echo "event: thinking\n";
                            echo 'data: ' . json_encode(['thinking' => $reasoning], JSON_UNESCAPED_UNICODE) . "\n\n";
                            if (ob_get_level()) ob_flush();
                            flush();
                            $lastHeartbeat = time();
                          }
                        : null;
                    // v1.11.5: 内容输出也使用长超时——模型在输出正文时也可能间歇停顿，
                    // 若用短静默超时（120s）会在长输出间歇被PHP kill，导致整批重来
                    $usage = $ai->chatStream($messages, function (string $token) use (&$rawResponse, &$lastHeartbeat, $effectiveTimeout) {
                        resetDynamicTimeout($effectiveTimeout, $GLOBALS['maxExecutionTime'] ?? 7200);
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
                    }, 'structured', $onThinking);
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

        $outlines = extractOutlineObjects($rawResponse);
        if (!empty($outlines)) break;

        if ($parseAttempt < $maxParseRetries) {
            sse('progress', ['msg' => "第{$startCh}～{$endCh}章大纲解析失败，自动重试（{$parseAttempt}/{$maxParseRetries}）..."]);
        }
    }
    unset($GLOBALS['sendHeartbeat']);

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

    // 入库（批量查询已有章节，消除 N+1）
    $saved = 0;
    $savedChNums = [];
    $allChNums = [];
    foreach ($outlines as $item) {
        $cn = (int)($item['chapter_number'] ?? 0);
        if ($cn > 0) $allChNums[] = $cn;
    }
    $existMap = [];
    if (!empty($allChNums)) {
        $ph = implode(',', array_fill(0, count($allChNums), '?'));
        $existingRows = DB::fetchAll(
            "SELECT id, chapter_number FROM chapters WHERE novel_id=? AND chapter_number IN ({$ph})",
            array_merge([$novelId], $allChNums)
        );
        $existMap = array_column($existingRows, 'id', 'chapter_number');
    }

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

        // 标题去重：与已有章节（非本批）的标题精确匹配时，自动追加序号后缀
        if ($title !== '' && isset($existingTitles) && is_array($existingTitles)) {
            $otherTitles = array_filter($existingTitles, fn($k) => $k != $chNum, ARRAY_FILTER_USE_KEY);
            if (in_array($title, $otherTitles, true)) {
                $suffix = 2;
                $baseTitle = $title;
                do {
                    $title = $baseTitle . "（{$suffix}）";
                    $suffix++;
                } while (in_array($title, $otherTitles, true));
                sse('progress', ['msg' => "第{$chNum}章标题「{$baseTitle}」与已有章节重复，已自动调整为「{$title}」"]);
            }
        }

        // 将本章节标题加入已用列表，防同批内后续重复
        if ($title !== '') {
            $existingTitles[$chNum] = $title;
        }

        $existingId = $existMap[$chNum] ?? null;
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
        if ($existingId) {
            DB::update('chapters', $row, 'id=?', [$existingId]);
        } else {
            DB::insert('chapters', array_merge($row, [
                'novel_id'       => $novelId,
                'chapter_number' => $chNum,
            ]));
        }
        $saved++;
        $savedChNums[] = $chNum;
    }

    addLog($novelId, 'outline', "生成第{$startCh}-{$endCh}章大纲，共{$saved}章{$parseNote}");

    $actualEnd = !empty($savedChNums) ? max($savedChNums) : $startCh - 1;
    $isComplete = $saved >= $expected;

    // 检测截断缺口，供前端/SFE 信息参考
    $gaps = [];
    if (!$isComplete && !empty($savedChNums)) {
        $savedSet = array_flip($savedChNums);
        for ($g = $startCh; $g <= $endCh; $g++) {
            if (!isset($savedSet[$g])) $gaps[] = $g;
        }
    }

    // 先发送 batch_done，让前端立即推进，弧段压缩和状态更新后置
    sse('batch_done', [
        'msg'               => "第 {$startCh}～{$endCh} 章大纲已保存（{$saved} 章）{$parseNote}",
        'start'             => $startCh,
        'end'               => $endCh,
        'saved'             => $saved,
        'expected'          => $expected,
        'actual_end'        => $actualEnd,
        'is_complete'       => $isComplete,
        'truncated'         => $saved < $expected,
        'gaps'              => $gaps,
        'prompt_tokens'     => $usage['prompt_tokens'],
        'completion_tokens' => $usage['completion_tokens'],
        'total_tokens'      => $usage['prompt_tokens'] + $usage['completion_tokens'],
    ]);

    // 弧段摘要压缩（每 10 章）— 后置执行，不阻塞前端进度
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
