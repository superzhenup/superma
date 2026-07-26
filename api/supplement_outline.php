<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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
require_once dirname(__DIR__) . '/includes/cache.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
requireLoginApi();
session_write_close(); // 释放 Session 锁，防止补写期间其他页面被阻塞

ob_end_clean();
set_time_limit(CFG_TIME_LONG);
ignore_user_abort(true);

// 关闭所有输出缓冲，确保 SSE 实时推送
while (ob_get_level()) ob_end_clean();

require_once dirname(__DIR__) . '/includes/sse_error_handler.php';
registerSseErrorHandlers();

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

// 审计 P0（2026-06-12）：归属校验
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($novelId > 0) checkNovelOwnership($novelId, $userId);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

// 预检：至少要有一个模型
try { getModelFallbackList($novel['model_id'] ?: null, 'structured'); }
catch (RuntimeException $e) { sse('error', safe_sse_error_payload($e, '模型检查失败，请稍后重试')); sseDone(); exit; }

// 初始化记忆引擎
require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
$engine = new MemoryEngine($novelId);

$targetChapters = (int)$novel['target_chapters'];

// ---- 检测缺失章节 ----
// 检测两种缺失：
// 1. 缺失章节大纲（chapters.status = 'pending' 或章节记录不存在）
// 2. 缺失章节概要（chapter_synopses 不存在或 synopsis 为空）

// 1. 获取已有章节大纲的章节号
$outlinedRows = DB::fetchAll(
    'SELECT chapter_number FROM chapters
     WHERE novel_id=? AND chapter_number>=1 AND chapter_number<=? AND status != "pending"
     ORDER BY chapter_number ASC',
    [$novelId, $targetChapters]
);
$outlinedSet = [];
foreach ($outlinedRows as $row) {
    $outlinedSet[(int)$row['chapter_number']] = true;
}

// 2. 获取已有章节概要的章节号
$hasSynopsisRows = DB::fetchAll(
    'SELECT chapter_number FROM chapter_synopses
     WHERE novel_id=? AND synopsis IS NOT NULL AND synopsis != ""
     ORDER BY chapter_number ASC',
    [$novelId]
);
$hasSynopsisSet = [];
foreach ($hasSynopsisRows as $row) {
    $hasSynopsisSet[(int)$row['chapter_number']] = true;
}

// 3. 找出缺失的章节号
// - 缺失大纲：status = 'pending' 或记录不存在
// - 缺失概要：有大纲但无概要
$missingOutline = [];  // 缺失大纲的章节
$missingSynopsis = []; // 缺失概要的章节

for ($i = 1; $i <= $targetChapters; $i++) {
    if (!isset($outlinedSet[$i])) {
        // 无大纲
        $missingOutline[] = $i;
    } elseif (!isset($hasSynopsisSet[$i])) {
        // 有大纲但无概要
        $missingSynopsis[] = $i;
    }
}

// 优先补写缺失的大纲（大纲是概要的前提）
$missingChapters = !empty($missingOutline) ? $missingOutline : $missingSynopsis;
$isSupplementingSynopsis = empty($missingOutline) && !empty($missingSynopsis);

if (empty($missingChapters)) {
    sse('complete', [
        'msg'         => '所有章节大纲和细纲已完整，无需补写。',
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
$scanMsg = $isSupplementingSynopsis
    ? "检测到 {$totalMissing} 个章节缺失细纲（概要），将分 " . count($segments) . " 段补写。"
    : "检测到 {$totalMissing} 个章节缺失大纲，将分 " . count($segments) . " 段补写。";
sse('scan_result', [
    'msg'           => $scanMsg,
    'missing_count'  => $totalMissing,
    'segment_count'  => count($segments),
    'missing_list'   => $missingChapters,
    'type'           => $isSupplementingSynopsis ? 'synopsis' : 'outline',
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
    $novelModel = DB::fetch('SELECT model_id FROM novels WHERE id=?', [$novelId]);
    if ($novelModel) {
        $aiClient = getAIClient($novelModel['model_id'] ? (int)$novelModel['model_id'] : null);
        $is1MModel = $aiClient->is1MContext();
    }
} catch (Throwable $e) {
    // 忽略，使用默认配置
}

// 从系统设置读取批量数，1M模型使用更大的批量；实际未超过普通批次上限时
// 不施加 1M fallback 要求，保证普通小批次仍可回退到 128k 模型。
$standardBatchSize = max(3, min(50, (int)getSystemSetting('ws_outline_batch', 5, 'int')));
if ($is1MModel) {
    $batchSize = max(10, min(100, (int)getSystemSetting('ws_outline_batch_1m', 30, 'int')));
} else {
    $batchSize = $standardBatchSize;
}

foreach ($segments as $segIdx => $seg) {
    $segStart = $seg['start'];
    $segEnd   = $seg['end'];

    // 如果段太长，拆成小批次
    $current = $segStart;
    while ($current <= $segEnd) {
        $batchEnd = min($current + $batchSize - 1, $segEnd);

        $typeLabel = $isSupplementingSynopsis ? '细纲' : '大纲';
        sse('progress', [
            'msg'   => "正在补写第 {$current}～{$batchEnd} 章{$typeLabel}...",
            'start' => $current,
            'end'   => $batchEnd,
        ]);

        if ($isSupplementingSynopsis) {
            // ---- 补写细纲（概要） ----
            // 获取该批次的章节信息
            $chaptersToProcess = DB::fetchAll(
                'SELECT id, novel_id, chapter_number, title, outline, key_points, hook, status
                 FROM chapters
                 WHERE novel_id=? AND chapter_number>=? AND chapter_number<=? AND status IN ("outlined","writing","completed")
                 ORDER BY chapter_number ASC',
                [$novelId, $current, $batchEnd]
            );

            if (empty($chaptersToProcess)) {
                sse('error', ['msg' => "第{$current}～{$batchEnd}章无有效章节记录，跳过"]);
                $current = $batchEnd + 1;
                continue;
            }

            $batchSaved = 0;
            foreach ($chaptersToProcess as $ch) {
                $chNum = (int)$ch['chapter_number'];

                // 检查是否已有概要
                $existingSynopsis = DB::fetch(
                    'SELECT id FROM chapter_synopses WHERE novel_id=? AND chapter_number=?',
                    [$novelId, $chNum]
                );
                if ($existingSynopsis) {
                    sse('progress', ['msg' => "第{$chNum}章已有细纲，跳过"]);
                    continue;
                }

                $messages = buildChapterSynopsisPrompt($novel, $ch, $storyOutline ?: []);
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
                        function (AIClient $nextAi, string $errMsg) use ($chNum) {
                            sse('model_switch', [
                                'msg'        => "模型请求失败，自动切换到「{$nextAi->modelLabel}」重试",
                                'next_model' => $nextAi->modelLabel,
                                'error'      => $errMsg,
                            ]);
                        }
                    );
                } catch (RuntimeException $e) {
                    sse('error', safe_sse_error_payload($e, "第{$chNum}章细纲生成失败"));
                    continue;
                }

                $totalPrompt     += $usage['prompt_tokens'];
                $totalCompletion += $usage['completion_tokens'];

                $synopsis = extractChapterSynopsis($rawResponse);

                if (empty($synopsis)) {
                    sse('error', ['msg' => "第{$chNum}章细纲解析失败，跳过"]);
                    continue;
                }

                // 保存到 chapter_synopses 表
                $synopsisId = DB::insert('chapter_synopses', [
                    'novel_id'         => $novelId,
                    'chapter_number'   => $chNum,
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
                if ($synopsisId && $ch['id']) {
                    DB::update('chapters', ['synopsis_id' => $synopsisId], 'id=?', [$ch['id']]);
                }

                $batchSaved++;
                $totalSupplemented++;

                sse('progress', ['msg' => "第{$chNum}章细纲已保存"]);
            }

            addLog($novelId, 'supplement', "补写第{$current}-{$batchEnd}章细纲，共{$batchSaved}章");

            sse('batch_done', [
                'msg'              => "第 {$current}～{$batchEnd} 章细纲补写完成（{$batchSaved} 章）",
                'start'            => $current,
                'end'              => $batchEnd,
                'saved'            => $batchSaved,
                'cum_supplemented' => $totalSupplemented,
            ]);

            $current = $batchEnd + 1;
            continue;
        }

        // ---- 以下为补写大纲的原有逻辑 ----

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
            sse('progress', safe_sse_error_payload($e, '记忆上下文获取失败，使用降级上下文'));
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
            } catch (Throwable $e2) { error_log('supplement_outline memory ctx fetch failed: ' . $e2->getMessage()); }
        }

        $messages = buildOutlinePrompt($novel, $current, $batchEnd, $recentOutlines, $prevHook, $memoryCtx, $currentVolume, $existingTitles);
        $actualBatchChapters = $batchEnd - $current + 1;
        $outlineFallbackRequirements = ($is1MModel && $actualBatchChapters > $standardBatchSize)
            ? [
                'min_context_tokens' => 1000000,
                'required_capabilities' => ['context_1m'],
                'label' => "第{$current}～{$batchEnd}章 1M 大纲补写批次",
              ]
            : null;
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
                    },
                    null,
                    null,
                    $outlineFallbackRequirements
                );
            } catch (ModelFallbackRequirementException $e) {
                error_log('supplement_outline model capability guard: ' . $e->getMessage());
                sse('error', ['msg' => $e->clientMessage(), 'code' => 'model_capability_unavailable']);
                $current = $batchEnd + 1;
                continue 2;
            } catch (RuntimeException $e) {
                sse('error', safe_sse_error_payload($e, "第{$current}～{$batchEnd}章补写失败"));
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

        // v41: 接通大纲质检闭环（与 generate_outline 一致——不合格自动局部返修后再入库）
        try {
            require_once dirname(__DIR__) . '/includes/OutlineQualityGuard.php';
            $qualityResult = repairOutlineBatchWithGuard(
                $novel,
                $outlines,
                $recentOutlines ?? [],
                function (string $event, array $payload): void { sse($event, $payload); }
            );
            if (!empty($qualityResult['outlines'])) {
                $outlines = $qualityResult['outlines'];
            }
        } catch (\Throwable $e) {
            // 质检失败不阻断补写，保留原始解析结果
            error_log('supplement_outline: 细纲质检跳过 — ' . $e->getMessage());
            sse('progress', ['msg' => '细纲质检跳过']);
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
                "SELECT id, chapter_number, status FROM chapters WHERE novel_id=? AND chapter_number IN ({$ph})",
                array_merge([$novelId], $allChNums)
            );
            foreach ($existingRows as $er) {
                $existMap[(int)$er['chapter_number']] = ['id' => (int)$er['id'], 'status' => $er['status']];
            }
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

            $existing = $existMap[$chNum] ?? null;
            // 数据保护：已完成章节不被补写大纲覆盖（保护正文）
            if ($existing && $existing['status'] === 'completed') {
                sse('progress', ['msg' => "第{$chNum}章已写完，跳过大纲覆盖（保护正文）"]);
                continue;
            }
            $row = [
                'title'      => $title,
                'outline'    => $summary,
                'key_points' => json_encode($kpts, JSON_UNESCAPED_UNICODE),
                'hook'       => $hook,
                'status'     => 'outlined',
            ];
            if ($existing) {
                DB::update('chapters', $row, 'id=? AND status<>"completed"', [$existing['id']]);
            } else {
                DB::insert('chapters', array_merge($row, [
                    'novel_id'       => $novelId,
                    'chapter_number' => $chNum,
                ]));
            }
            $saved++;
            $savedChNums[] = $chNum;
        }

        if ($saved > 0) {
            // 统一走 clearNovelCache：同时清理请求级运行时缓存与历史残留的跨请求缓存
            clearNovelCache($novelId);
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

// 审计修复（2026-07-19 H-中3）：补充大纲不应改变小说状态。
    // 原实现将 completed/writing 书强制改为 draft，污染前端展示与挂机判断。
    // 仅在 status 为空或 NULL 时才设为 draft。
    $currentStatus = DB::fetchColumn('SELECT status FROM novels WHERE id=?', [$novelId]);
    if (empty($currentStatus)) {
        DB::update('novels', ['status' => 'draft'], 'id=?', [$novelId]);
    }

$finalTypeLabel = $isSupplementingSynopsis ? '细纲' : '大纲';
sse('complete', [
    'msg'               => "{$finalTypeLabel}补写完成！共补写 {$totalSupplemented} 章（原缺失 {$totalMissing} 章）。",
    'supplemented'      => $totalSupplemented,
    'total_missing'     => $totalMissing,
    'prompt_tokens'     => $totalPrompt,
    'completion_tokens' => $totalCompletion,
    'total_tokens'      => $totalPrompt + $totalCompletion,
    'type'              => $isSupplementingSynopsis ? 'synopsis' : 'outline',
]);
sseDone();
