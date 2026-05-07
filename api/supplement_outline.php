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
try { getModelFallbackList($novel['model_id'] ?: null, 'structured'); }
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

// ---- 逐段补写 ----
// 查询全书已有章节标题（防重复）
$existingTitleRows = DB::fetchAll(
    'SELECT chapter_number, title FROM chapters WHERE novel_id=? AND title IS NOT NULL AND title != "" ORDER BY chapter_number ASC',
    [$novelId]
);
$existingTitles = array_column($existingTitleRows, 'title', 'chapter_number');

// 检测模型是否支持 1M 上下文，决定批量大小
$is1MModel = false;
try {
    $novel = DB::fetch('SELECT model_id FROM novels WHERE id=?', [$novelId]);
    if ($novel) {
        $aiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
        $is1MModel = $aiClient->is1MContext();
    }
} catch (Throwable $e) {
    // 忽略，使用默认配置
}

// 从系统设置读取批量数，1M模型使用更大的批量
if ($is1MModel) {
    $batchSize = max(10, min(100, (int)getSystemSetting('ws_outline_batch_1m', 30, 'int')));
} else {
    $batchSize = max(3, min(50, (int)getSystemSetting('ws_outline_batch', 5, 'int')));
}

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

        // 获取前几章大纲作为上下文（保持连贯性，含 hook/pacing/suspense）
        $recentOutlines = DB::fetchAll(
            'SELECT chapter_number, title, outline, hook, pacing, suspense FROM chapters 
             WHERE novel_id=? AND chapter_number<? AND status IN ("outlined","writing","completed")
             ORDER BY chapter_number DESC LIMIT 5',
            [$novelId, $current]
        );
        $recentOutlines = array_reverse($recentOutlines);

        // 取上一章 hook（与 generate_outline.php 保持一致）
        $prevHook = '';
        if (!empty($recentOutlines)) {
            $lastOutline = end($recentOutlines);
            $prevHook    = trim($lastOutline['hook'] ?? '');
        }

        // 获取卷大纲上下文
        $currentVolume = null;
        $volumeRows = DB::fetchAll(
            'SELECT * FROM volume_outlines WHERE novel_id=? AND start_chapter <= ? AND end_chapter >= ?',
            [$novelId, $batchEnd, $current]
        );
        if (!empty($volumeRows)) {
            $currentVolume = $volumeRows[0];
        }

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
            sse('progress', ['msg' => '记忆上下文获取失败，使用降级上下文：' . $e->getMessage()]);
        }

        // 降级回退：MemoryEngine 失败时，手动构建最小记忆上下文
        if ($memoryCtx === null) {
            $memoryCtx = [];
            try {
                $cards = DB::fetchAll('SELECT name, title, status FROM character_cards WHERE novel_id=? AND alive=1', [$novelId]);
                $charStates = [];
                foreach ($cards as $c) {
                    $charStates[$c['name']] = ['title' => $c['title'] ?? '', 'status' => $c['status'] ?? '', 'alive' => true];
                }
                $memoryCtx['character_states'] = $charStates;

                // 关键事件 + 爽点历史合并查询
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
            } catch (Throwable $e2) { }
        }

        $messages = buildOutlinePrompt($novel, $current, $batchEnd, $recentOutlines, $prevHook, $memoryCtx, $currentVolume, $existingTitles);
        $outlines = [];
        $maxParseRetries = 2;

        for ($parseAttempt = 1; $parseAttempt <= $maxParseRetries; $parseAttempt++) {
            $rawResponse = '';
            $usage       = ['prompt_tokens' => 0, 'completion_tokens' => 0];

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
                continue 2;
            }

            $totalPrompt     += $usage['prompt_tokens'];
            $totalCompletion += $usage['completion_tokens'];

            $outlines = extractOutlineObjects($rawResponse);
            if (!empty($outlines)) break;

            if ($parseAttempt < $maxParseRetries) {
                sse('progress', ['msg' => "第{$current}～{$batchEnd}章大纲解析失败，自动重试（{$parseAttempt}/{$maxParseRetries}）..."]);
            }
        }

        $expected = $batchEnd - $current + 1;

        if (empty($outlines)) {
            sse('error', [
                'msg' => "第{$current}～{$batchEnd}章大纲解析失败，原始片段：" 
                       . safe_substr($rawResponse, 0, 120) . '…',
            ]);
            $current = $batchEnd + 1;
            continue;
        }

        // ---- 入库（批量查询已有章节 + 标题去重） ----
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
                'status'     => 'outlined',
            ];
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

        $totalSupplemented += $saved;
        $parseNote = $saved < $expected ? "（仅解析到 {$saved}/{$expected} 章）" : '';

        // 检测截断缺口
        $gaps = [];
        if ($saved < $expected && !empty($savedChNums)) {
            $savedSet = array_flip($savedChNums);
            for ($g = $current; $g <= $batchEnd; $g++) {
                if (!isset($savedSet[$g])) $gaps[] = $g;
            }
        }

        addLog($novelId, 'supplement', "补写第{$current}-{$batchEnd}章大纲，共{$saved}章{$parseNote}");

        sse('batch_done', [
            'msg'               => "第 {$current}～{$batchEnd} 章补写完成（{$saved} 章）{$parseNote}",
            'start'             => $current,
            'end'               => $batchEnd,
            'saved'             => $saved,
            'expected'          => $expected,
            'gaps'              => $gaps,
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
