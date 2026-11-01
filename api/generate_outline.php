<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 生成章节大纲：HTTP 短请求启动后台 CLI，CLI 复用本文件的批量生成逻辑。
 * POST JSON: { novel_id, start_chapter, end_chapter }
 *
 * v5 重构：
 * - 每次请求只生成 1 批（默认 5 章），前端循环调用
 * - HTTP 只完成鉴权、范围校验与排队；模型调用和保存由持锁的 CLI 执行
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

if (!defined('APP_LOADED')) define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cache.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/OutlineQualityGuard.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/ChapterOutlineTrace.php';
require_once dirname(__DIR__) . '/includes/ChapterOutlineRequestStore.php';
require_once dirname(__DIR__) . '/includes/ChapterOutlineWorkerContext.php';
require_once dirname(__DIR__) . '/includes/ChapterOutlineWorkerLauncher.php';
require_once dirname(__DIR__) . '/includes/ChapterOutlinePersistence.php';
require_once dirname(__DIR__) . '/includes/harness/HarnessControlPlane.php';
require_once dirname(__DIR__) . '/includes/harness/outline/bootstrap.php';
$isOutlineWorker = PHP_SAPI === 'cli'
    && isset($chapterOutlineWorkerContext)
    && $chapterOutlineWorkerContext instanceof ChapterOutlineWorkerContext;
if (!$isOutlineWorker) requireLoginApi();
session_write_close();

ob_end_clean();

// Register before the SSE fatal handler so a PHP shutdown records the last phase
// even if another shutdown handler exits. A killed FPM process may log no shutdown.
$outlineTrace = $isOutlineWorker ? $chapterOutlineWorkerContext->trace() : new ChapterOutlineTrace(error_trace_id());
$outlineRequest = $isOutlineWorker ? $chapterOutlineWorkerContext->store() : null;
$outlineTrace->registerShutdown();
$outlineTrace->phase('init');

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

require_once dirname(__DIR__) . '/includes/sse_error_handler.php';
registerSseErrorHandlers();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Content-Encoding: none');
header('Connection: keep-alive');

// 初始确认
echo "event: init\n";
echo 'data: ' . json_encode(['msg' => 'SSE 连接已建立', 'request_id' => $outlineTrace->requestId()]) . "\n\n";
if (ob_get_level()) ob_flush();
flush();

// 记忆上下文也可能等待 embedding 网络请求，必须从准备阶段开始保活，
// 不能等到 chatStream 前才注册。闭包读取更新后的普通/1M 静默超时。
$lastHeartbeat = time();
$lastRequestTouch = 0;
$requestTouchWarningLogged = false;
$GLOBALS['sendHeartbeat'] = function() use (&$lastHeartbeat, &$silenceTimeout, &$outlineRequest, &$lastRequestTouch, &$requestTouchWarningLogged) {
    $now = time();
    if ($outlineRequest !== null && $now - $lastRequestTouch >= 5) {
        $lastRequestTouch = $now;
        try {
            $outlineRequest->record([]);
        } catch (Throwable $error) {
            if (!$requestTouchWarningLogged) {
                $requestTouchWarningLogged = true;
                error_log('[' . error_trace_id() . '] chapter_outline request status write failed');
            }
        }
    }
    if ($now - $lastHeartbeat >= 3) {
        resetDynamicTimeout($silenceTimeout, $GLOBALS['maxExecutionTime'] ?? 7200);
        sse('heartbeat', ['time' => $now, 'msg' => 'keep-alive']);
        $lastHeartbeat = $now;
    }
};

// ---- 解析入参 ----
$input   = $isOutlineWorker ? $chapterOutlineWorkerContext->input()
    : (json_decode(file_get_contents('php://input'), true) ?? []);
$novelId = (int)($input['novel_id']      ?? 0);
$startCh = (int)($input['start_chapter'] ?? 1);
$endCh   = (int)($input['end_chapter']   ?? $startCh + 4);

// 审计 P0（2026-06-12）：归属校验
$userId = $isOutlineWorker ? $chapterOutlineWorkerContext->userId() : currentUserId();
if (!$isOutlineWorker && $novelId > 0) checkNovelOwnership($novelId, $userId);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { $outlineTrace->phase('rejected'); sse('error', ['msg' => '小说不存在']); sseDone(); exit; }
// The CLI identity comes only from a successfully claimed server-side queue
// record. Recheck current ownership; never claim an unowned novel in a worker.
if ($isOutlineWorker && ($userId < 1 || (int)($novel['user_id'] ?? 0) !== $userId)) {
    $outlineTrace->phase('rejected');
    sse('error', ['msg' => '小说归属已变化，已停止后台细纲生成']);
    sseDone();
    exit;
}
if ($startCh < 1 || $endCh < $startCh) {
    $outlineTrace->phase('rejected');
    sse('error', ['msg' => '章节范围无效，请刷新页面后重试']);
    sseDone();
    exit;
}
$outlineTrace->authorize($novelId, $startCh, $endCh);
$outlineTrace->phase('authorized');

// 审计功能（2026-08-16 P3-金手指设计模板）：结构化设定并入 $novel，
// 供黄金三章实体核验/点名与细纲层金手指档位表消费；无设定回退文本挖掘。失败静默。
try {
    require_once dirname(__DIR__) . '/includes/GoldenFinger.php';
    $novel['golden_finger'] = (new GoldenFinger($novelId))->get();
} catch (\Throwable $e) {
    // 读取失败不阻断大纲生成
}

// 检测模型是否支持 1M 上下文，决定批量大小和超时
$outlineTrace->phase('model_check');
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

// 从系统设置读取批量数，1M模型使用更大的批量。
// 只有实际批次超过普通模型批量上限时才声明 1M 要求；小批次即使首选模型支持
// 1M，也继续允许普通 128k 模型 fallback，保持原有可用性。
$standardBatchSize = max(3, min(50, (int)getSystemSetting('ws_outline_batch', 5, 'int')));
if ($is1MModel) {
    $batchSize = max(10, min(100, (int)getSystemSetting('ws_outline_batch_1m', 30, 'int')));
} else {
    $batchSize = $standardBatchSize;
}

if ($endCh - $startCh + 1 > $batchSize) {
    if ($isOutlineWorker) {
        $outlineTrace->phase('rejected');
        sse('error', ['msg' => '模型或批量设置已变化，请重新启动本批细纲']);
        sseDone();
        exit;
    }
    $endCh = $startCh + $batchSize - 1;
}
$outlineTrace->authorize($novelId, $startCh, $endCh);

// CHAPTER_OUTLINE_REQUEST_ADMISSION_BEGIN
// Queue publication and CLI claiming share the stable novel lock. The short
// HTTP request never calls the provider; a claimed worker holds the lock through
// its final legacy enhancement write, including after its base save receipt.
try {
    if (!($isOutlineWorker ?? false)) {
        $outlineRequest = new ChapterOutlineRequestStore();
        if (!$outlineRequest->queue($userId, $novelId, $outlineTrace->requestId(), $startCh, $endCh)) {
            sse('request_active', [
                'msg' => '该小说已有章节细纲任务，正在核对原任务',
                'request' => $outlineRequest->snapshot($userId, $novelId),
            ]);
            $outlineTrace->phase('rejected');
            sseDone();
            exit;
        }
        if (!ChapterOutlineWorkerLauncher::launch($novelId, $outlineTrace->requestId())) {
            $outlineRequest->failQueued($userId, $novelId, $outlineTrace->requestId());
            throw new RuntimeException('Chapter outline CLI launch failed.');
        }
        sse('request_queued', [
            'msg' => '后台细纲任务已提交，正在等待处理结果',
            'request_id' => $outlineTrace->requestId(),
            'request' => $outlineRequest->snapshot($userId, $novelId, $outlineTrace->requestId()),
        ]);
        $outlineTrace->phase('response_finished');
        sseDone();
        exit;
    }
    // This object already owns the exact queued request; acquiring a new lock
    // here would overwrite its identity or conflict with the worker itself.
    $outlineRequest = $chapterOutlineWorkerContext->store();
    // Keep the Context observer: it also authenticates the save receipt and
    // records an abnormal early exit before the stable lock is released.
    $outlineTrace->phase('authorized');
} catch (Throwable $e) {
    $outlineTrace->phase('failed');
    sse('error', safe_sse_error_payload($e, '后台细纲任务未能启动，请检查 PHP CLI 路径、进程启动权限和存储目录权限'));
    sseDone();
    exit;
}
// CHAPTER_OUTLINE_REQUEST_ADMISSION_END

$actualBatchChapters = $endCh - $startCh + 1;
$outlineFallbackRequirements = ($is1MModel && $actualBatchChapters > $standardBatchSize)
    ? [
        'min_context_tokens' => 1000000,
        'required_capabilities' => ['context_1m'],
        'label' => "第{$startCh}～{$endCh}章 1M 大纲批次",
      ]
    : null;

try {
    getModelFallbackList($novel['model_id'] ?: null, 'structured', $outlineFallbackRequirements);
} catch (ModelFallbackRequirementException $e) {
    $outlineTrace->phase('rejected');
    error_log('generate_outline model capability guard: ' . $e->getMessage());
    sse('error', ['msg' => $e->clientMessage(), 'code' => 'model_capability_unavailable']);
    sseDone();
    exit;
} catch (RuntimeException $e) {
    $outlineTrace->phase('rejected');
    sse('error', safe_sse_error_payload($e, '模型检查失败，请稍后重试'));
    sseDone();
    exit;
}

try {
    // CHAPTER_OUTLINE_SOURCE_CAPTURE_BEGIN
    // Freeze the full target range before context/model work, including absent
    // rows and existing detailed blueprints. Later saves may change only this base.
    $outlinePersistence = new ChapterOutlinePersistence(DB::getPdo(), $userId, $novelId);
    $outlinePersistence->capture($startCh, $endCh);
    // CHAPTER_OUTLINE_SOURCE_CAPTURE_END
    $outlineTrace->phase('context_prepare');
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    require_once dirname(__DIR__) . '/includes/prompt.php';
    $engine = new MemoryEngine($novelId);

    // 获取卷大纲上下文（跨卷时同时注入两卷上下文）
    $volumeOutlines = fetchVolumeOutlinesOverlapping($novelId, $startCh, $endCh);
    $currentVolume = $volumeOutlines[0] ?? null;
    if ($currentVolume && count($volumeOutlines) > 1) {
        $nextVol = $volumeOutlines[1];
        $extraEvents = json_decode($nextVol['key_events'] ?? '[]', true) ?: [];
        $currentVolume['_next_volume_title'] = $nextVol['title'] ?? '';
        $currentVolume['_next_volume_theme'] = $nextVol['theme'] ?? '';
        $currentVolume['_next_volume_conflict'] = $nextVol['conflict'] ?? '';
        $currentVolume['_next_volume_key_events'] = implode('、', array_slice($extraEvents, 0, 3));
    }

    // 获取近章大纲上下文（v41 去套路化：细纲专用窗口，默认更大，便于跨更多章去重）
    // 向后兼容：优先新键 ws_outline_lookback；用户在 UI 里调过的旧键 ws_memory_lookback 仍生效。
    $memoryLookback = max(3, min(40, (int)getSystemSetting(
        'ws_outline_lookback',
        (int)getSystemSetting('ws_memory_lookback', 16, 'int'),
        'int'
    )));
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
        'msg'   => "正在整理第 {$startCh}～{$endCh} 章的设定和记忆上下文...",
        'start' => $startCh,
        'end'   => $endCh,
    ]);

    // 获取记忆上下文
    $queryText = trim(($novel['genre'] ?? '') . '：' . ($novel['plot_settings'] ?? ''));
    // 审计修复（2026-08-09 R10-9）：默认与写引擎/UI 对齐为 5
    $semanticTopK = max(1, min(40, (int)getSystemSetting('ws_embedding_top_k', 5, 'int')));
    $outlineTrace->phase('memory_lookup');
    try {
        $memoryCtx = $engine->getPromptContext(
            $startCh,
            $queryText !== '：' ? $queryText : null,
            max(2000, (int)getSystemSetting('ws_outline_memory_budget', 8000, 'int')),
            20,
            $semanticTopK
        );
    } catch (Throwable $e) {
        $memoryCtx = null;
        sse('progress', safe_sse_error_payload($e, '记忆上下文获取失败，使用降级上下文'));
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
                // desc 是保留字，别名必须加反引号，否则整条降级查询语法错误。
                'SELECT planted_chapter AS chapter, description AS `desc`, deadline_chapter AS deadline
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

    $outlineTrace->phase('memory_ready');

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
    sse('progress', [
        'msg' => "上下文已就绪，正在请求模型生成第 {$startCh}～{$endCh} 章大纲...",
        'start' => $startCh,
        'end' => $endCh,
    ]);
    $lastWaitingNotice = 0;

    $outlines = [];
    $maxParseRetries = 2;
    for ($parseAttempt = 1; $parseAttempt <= $maxParseRetries; $parseAttempt++) {
        $rawResponse = '';
        $usage       = ['prompt_tokens' => 0, 'completion_tokens' => 0];

        // 强化兜底（2026-06-18）：重试时差异化策略，避免相同输入得到相同输出
        // 第一次：原样请求
        // 第二次：追加格式强化提示，引导 AI 输出标准 JSON
        // （不调温：chatStream 内部按 taskType 从系统设置读 temperature，会覆盖 setTemperature）
        $retryMessages = $messages;
        if ($parseAttempt > 1) {
            $retryMessages[] = [
                'role'    => 'user',
                'content' => '上一次输出无法解析为有效 JSON。请严格输出 JSON 数组格式，'
                           . '不要包含任何 Markdown 代码块标记或说明文字。'
                           . '每个章节对象必须包含 chapter_number（数字）、title、summary 字段。'
            ];
            sse('progress', ['msg' => "第{$startCh}～{$endCh}章大纲解析失败，已强化格式提示重试（{$parseAttempt}/{$maxParseRetries}）..."]);
        }

        try {
            withModelFallback(
                $novel['model_id'] ?: null,
                function (AIClient $ai) use ($retryMessages, &$rawResponse, &$usage, &$lastHeartbeat, $silenceTimeout, $thinkingTimeout, $outlineTrace, $parseAttempt) {
                    $rawResponse = '';
                    $outlineTrace->phase('model_request', ['attempt' => $parseAttempt]);
                    // v1.11.5: 思考过程回调——CFG_SHOW_OUTLINE_THINKING=1时发送thinking事件
                    // 使用长思考超时确保深度推理不超时
                    $effectiveTimeout = $thinkingTimeout;
                    $onThinking = function (string $reasoning) use (&$lastHeartbeat, $effectiveTimeout, $outlineTrace) {
                            if ($reasoning !== '') $outlineTrace->firstModelOutput('thinking');
                            if (!(defined('CFG_SHOW_OUTLINE_THINKING') && CFG_SHOW_OUTLINE_THINKING)) return;
                            resetDynamicTimeout($effectiveTimeout, $GLOBALS['maxExecutionTime'] ?? 7200);
                            echo "event: thinking\n";
                            echo 'data: ' . json_encode(['thinking' => $reasoning], JSON_UNESCAPED_UNICODE) . "\n\n";
                            if (ob_get_level()) ob_flush();
                            flush();
                            $lastHeartbeat = time();
                          };
                    // v1.11.5: 内容输出也使用长超时——模型在输出正文时也可能间歇停顿，
                    // 若用短静默超时（120s）会在长输出间歇被PHP kill，导致整批重来
                    $usage = $ai->chatStream($retryMessages, function (string $token) use (&$rawResponse, &$lastHeartbeat, $effectiveTimeout, $outlineTrace) {
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
                        if ($token !== '') $outlineTrace->firstModelOutput('content');
                        $rawResponse .= $token;
                        echo "event: chunk\n";
                        echo 'data: ' . json_encode(['t' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
                        if (ob_get_level()) ob_flush();
                        flush();
                    }, 'outline', $onThinking);   // v41: 细纲走高温档，去套路化
                    $outlineTrace->phase('model_response', ['attempt' => $parseAttempt]);
                },
                function (AIClient $nextAi, string $errMsg) use ($startCh, $endCh) {
                    sse('model_switch', [
                        'msg'        => "模型请求失败，自动切换到「{$nextAi->modelLabel}」重试",
                        'next_model' => $nextAi->modelLabel,
                        'error'      => $errMsg,
                    ]);
                },
                null,
                function (int $elapsed) use (&$lastWaitingNotice) {
                    // 兼顾 AI 静默等待和 429 逐秒退避，避免重试时刷屏。
                    if (time() - $lastWaitingNotice < 5) return;
                    sse('waiting', [
                        'msg' => '模型仍在处理请求，正在等待输出...',
                        'elapsed' => $elapsed,
                    ]);
                    $lastWaitingNotice = time();
                },
                $outlineFallbackRequirements
            );
        } catch (ModelFallbackRequirementException $e) {
            $outlineTrace->phase('failed');
            error_log('generate_outline compatible fallback exhausted: ' . $e->getMessage());
            sse('error', ['msg' => $e->clientMessage(), 'code' => 'model_capability_unavailable']);
            sseDone();
            exit;
        } catch (RuntimeException $e) {
            $outlineTrace->phase('failed');
            sse('error', safe_sse_error_payload($e, "第{$startCh}～{$endCh}章：所有模型均失败"));
            sseDone();
            exit;
        }

        $outlines = extractOutlineObjects($rawResponse);
        $outlineTrace->phase('parse_result', ['attempt' => $parseAttempt, 'parsed' => count($outlines)]);
        if (!empty($outlines)) break;

        // 仅记录大小和请求号；解析诊断不把模型原文写入服务器日志。
        $rawLen     = strlen($rawResponse);
        error_log(sprintf(
            '[%s] generate_outline: 第%d-%d章解析失败（尝试%d/%d），原始长度%d',
            $outlineTrace->requestId(), $startCh, $endCh, $parseAttempt, $maxParseRetries, $rawLen
        ));

        if ($parseAttempt < $maxParseRetries) {
            // 提示文案已在上方 $parseAttempt > 1 分支发出，此处避免重复
            if ($parseAttempt === 1) {
                sse('progress', ['msg' => "第{$startCh}～{$endCh}章大纲解析失败，自动重试（{$parseAttempt}/{$maxParseRetries}）..."]);
            }
        }
    }
    // 注意：此处不再注销 $GLOBALS['sendHeartbeat']。后续质量返修 / 转折点 / 弧段摘要
    // 都是阻塞式 AI 调用（无流式输出），需保留心跳回调，否则 SSE 静默会被 nginx
    // fastcgi_read_timeout 掐断（ERR_INCOMPLETE_CHUNKED_ENCODING）。注销移到 sseDone() 前。

    $expected = $endCh - $startCh + 1;
    $parseNote = '';

    if (empty($outlines)) {
        $outlineTrace->phase('failed');
        // 强化兜底（2026-06-18）：2次重试 + 正则兜底都失败，提供更明确的错误信息和建议
        $rawLen = strlen($rawResponse);
        $isEmpty = $rawLen < 10;
        $msg = $isEmpty
            ? "第{$startCh}～{$endCh}章大纲生成失败：AI 返回内容为空（可能是模型限流或上下文超限）。"
              . "建议：① 检查模型配额 ② 减少单批量（系统设置 ws_outline_batch） ③ 稍后重试"
            : "第{$startCh}～{$endCh}章大纲解析失败（共0条），2次重试均无法提取有效章节。"
              . "原始片段：" . safe_substr($rawResponse, 0, 120) . '…'
              . '。建议：① 检查模型是否支持 JSON 输出 ② 稍后重试 ③ 手动编写本批大纲';
        sse('error', ['msg' => $msg]);
        addLog($novelId, 'warn', "第{$startCh}-{$endCh}章大纲解析彻底失败（2次重试+正则兜底均无效），原始长度{$rawLen}");
        sseDone();
        exit;
    }

    if (count($outlines) < $expected) {
        $parseNote = "（仅解析到 " . count($outlines) . "/{$expected} 章，可能被截断）";
    }

    // 持久化闭包：把一批 outlines 落库（幂等——已存在 UPDATE、新章 INSERT、completed 跳过）。
    // 抽成闭包是为了「先存原始解析结果、再增强后回写」：即便后续质量返修/转折点/弧段摘要
    // 被任何超时（nginx fastcgi_read_timeout / php-fpm request_terminate_timeout）或异常杀掉，
    // 本批章节也已落库、前端也已据 batch_done 推进 —— 杜绝「生成完却没保存、随后重复整批生成」。
    // 审计修复（2026-08-02 H5）：闭包捕获 startCh/endCh，落库前校验章号必须落在本次批次范围内，
    // 防止 AI 幻觉输出批次外章号（如 999）破坏断点续传推进（actual_end 被推飞、后续章被永久跳过）。
    $persistOutlines = function (array $outlines) use (&$existingTitles, $novelId, $startCh, $endCh, $outlinePersistence): array {
        $saved = 0;
        $savedChNums = [];
        $savedChapterSet = [];

        foreach ($outlines as $item) {
            $chNum   = (int)($item['chapter_number'] ?? 0);
            $title   = trim($item['title']           ?? '');
            // 空 summary 不能挡住有效 outline 回退；无实质文本的对象
            // 不能写成 outlined，否则完成回执会越过尚未生成的细纲。
            $summary = '';
            foreach (['summary', 'outline'] as $outlineField) {
                $candidate = $item[$outlineField] ?? null;
                if (is_string($candidate) && preg_match('/[^\s\p{Z}]/u', $candidate) === 1) {
                    $summary = trim($candidate);
                    break;
                }
            }
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
            // 审计修复（2026-08-09 R11-2）：允许 breather/development 落盘，供 Gate4 豁免
            if ($coolPtType !== '' && !isPersistedCoolPointType($coolPtType)) $coolPtType = '';

            if (!$chNum) continue;
            // 审计修复（2026-08-02 H5）：越界章号（AI 幻觉）直接丢弃并记日志，
            // 防止杂散章节入库 + actual_end 被批次外章号推飞导致断点续传跳章。
            if ($chNum < $startCh || $chNum > $endCh) {
                sse('progress', ['msg' => "AI 返回了批次外的章号（第{$chNum}章），已跳过（本批次范围 {$startCh}~{$endCh}）"]);
                addLog($novelId, 'warn', "大纲生成：AI 返回批次外章号 {$chNum}（范围 {$startCh}-{$endCh}），已丢弃");
                continue;
            }
            // AI 可能重复返回同一章号。首次保存后跳过重复对象，避免新章
            // 再次 INSERT 命中唯一键，让已保存的批次失去 batch_done 回执。
            if (isset($savedChapterSet[$chNum])) continue;
            if ($summary === '') {
                sse('progress', ['msg' => "第{$chNum}章未返回有效细纲内容，保留为待补充章节"]);
                continue;
            }

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
            if (!$outlinePersistence->save($chNum, $row)) {
                sse('progress', ['msg' => "第{$chNum}章大纲、简介或写作状态已变化，已保留当前版本，请核对后重新生成"]);
                continue;
            }
            $saved++;
            $savedChNums[] = $chNum;
            $savedChapterSet[$chNum] = true;
        }

        if ($saved > 0) {
            // 统一走 clearNovelCache：同时清理请求级运行时缓存与历史残留的跨请求缓存
            clearNovelCache($novelId);
        }

        return ['saved' => $saved, 'savedChNums' => $savedChNums];
    };

    // ① 先把原始解析结果落库 + 立即 batch_done（章节安全 + 前端可据 actual_end 推进）。
    //    质量增强放到 batch_done 之后，绝不阻塞「章节先落库」这件最重要的事。
    $outlineTrace->phase('persist');
    $persistResult = $persistOutlines($outlines);
    $savedChNums = array_values(array_unique($persistResult['savedChNums']));
    $saved       = count($savedChNums);
    $outlineTrace->phase('base_saved', ['saved' => $saved, 'expected' => $expected]);
    if ($outlinePersistence->conflicts() !== []) {
        // A partial receipt would make the browser automatically submit a fresh
        // generation for the conflicting chapter, defeating the revision guard.
        throw new \ChapterOutlineSourceChangedException('生成期间第' . implode('、', $outlinePersistence->conflicts())
            . '章已被修改，已保留当前版本并停止本批次，请刷新核对后再生成。');
    }

    // 日志失败不应把已保存的大纲报告为失败，引发整批重复生成。
    try {
        addLog($novelId, 'outline', "生成第{$startCh}-{$endCh}章大纲，共{$saved}章{$parseNote}");
    } catch (Throwable $logError) {
        error_log('generate_outline saved batch log failed: ' . $logError->getMessage());
    }

    // 只能按已保存的连续前缀推进。模型若返回 1、2、4、5 章，MAX=5
    // 会永久跳过第3章；此时权威进度必须停在第2章。
    $savedSet = array_fill_keys($savedChNums, true);
    $actualEnd = $startCh - 1;
    while ($actualEnd < $endCh && isset($savedSet[$actualEnd + 1])) {
        $actualEnd++;
    }
    $isComplete = count($savedSet) >= $expected;

    // Harness 非 off 模式把慢速质量增强交给 durable outline queue。
    // Run 的幂等身份直接绑定刚刚落库的精确章节集合及其内容/修订哈希；
    // 创建失败时保留基础大纲并 fail closed，不回落到无租约的后台写入。
    $enhancementRunId = null;
    $enhancementJobId = null;
    // 未能读取控制配置时跳过增强，不能回落到无租约的 legacy 写入。
    $harnessMode = null;
    $outlineTrace->phase('enhancement_queue');
    try {
        $harnessControls = HarnessControlPlane::captureForNovel($novelId);
        $harnessMode = $harnessControls['harness_mode'];
        if (HarnessControlPlane::allowsNewHarnessRun($harnessMode) && $savedChNums !== []) {
            $pdo = DB::getPdo();
            $outlineStore = new DbOutlineEnhancementStore($pdo);
            $outlineGateway = new GenerationRunOutlineGateway(
                new GenerationRunRepository($pdo)
            );
            $queuedEnhancement = (new OutlineEnhancementRunFactory(
                $outlineGateway,
                $outlineStore
            ))->enqueue(
                $userId,
                $novelId,
                $startCh,
                $endCh,
                $savedChNums,
                $harnessMode,
                $harnessControls['fencing_enforced'],
                $memoryLookback
            );
            $enhancementRunId = $queuedEnhancement->run->runUid;
            $enhancementJob = (new OutlineEnhancementJobMapper(
                new HarnessJobRepository($pdo),
                $pdo
            ))->map($queuedEnhancement->run);
            $enhancementJobId = $enhancementJob->jobUid;
        }
    } catch (Throwable $enhancementQueueError) {
        $traceId = error_trace_id();
        error_log(sprintf(
            '[%s] outline enhancement enqueue failed (%s): %s',
            $traceId,
            get_class($enhancementQueueError),
            $enhancementQueueError->getMessage()
        ));
        try {
            addLog(
                $novelId,
                'warn',
                "第{$startCh}-{$endCh}章基础大纲已保存，但 durable 增强排队失败（追踪号 {$traceId}）"
            );
        } catch (Throwable $logError) {
            error_log('generate_outline enhancement failure log failed: ' . $logError->getMessage());
        }
    }

    // 检测截断缺口，供前端/SFE 信息参考
    $gaps = [];
    if (!$isComplete) {
        for ($g = $startCh; $g <= $endCh; $g++) {
            if (!isset($savedSet[$g])) $gaps[] = $g;
        }
    }

    // 先发送 batch_done，让前端立即推进，质量增强/弧段压缩/状态更新一律后置
    sse('batch_done', [
        'msg'               => "第 {$startCh}～{$endCh} 章大纲已保存（{$saved} 章）{$parseNote}",
        'start'             => $startCh,
        'end'               => $endCh,
        'saved'             => $saved,
        'expected'          => $expected,
        'actual_end'        => $actualEnd,
        'is_complete'       => $isComplete,
        'truncated'         => !$isComplete,
        'gaps'              => $gaps,
        'enhancement_run_id' => $enhancementRunId,
        'enhancement_job_id' => $enhancementJobId,
        'prompt_tokens'     => $usage['prompt_tokens'],
        'completion_tokens' => $usage['completion_tokens'],
        'total_tokens'      => $usage['prompt_tokens'] + $usage['completion_tokens'],
    ]);
    $outlineTrace->phase('save_receipt', [
        'saved' => $saved, 'expected' => $expected, 'actual_end' => $actualEnd,
        'receipt_complete' => $isComplete && $gaps === [],
    ]);

    // batch_done 后注销全局心跳回调，避免终态后仍尝试发送心跳，
    // 同时与 sse_blocking_heartbeat_static_test 回归测试保持一致。
    unset($GLOBALS['sendHeartbeat']);

    // ② 质量返修 + 转折点增强 + 弧段摘要（含阻塞式 AI 调用、较慢）。
    //
    // 超时修复（2026-06-18 v2）：原实现将这些后处理放在 batch_done 之后、sseDone 之前
    // 串行执行，导致两个致命问题：
    //   1) 后处理是非流式 AI 调用（chat() 而非 chatStream()），不触发 token 回调，
    //      也就不发心跳——nginx fastcgi_read_timeout 仍会掐断。
    //   2) 第 6～10 章命中 $endCh % 10 === 0，额外触发 generateAndSaveArcSummary，
    //      后处理总耗时（质量返修+转折点+弧段摘要）轻易超过 nginx/PHP 超时阈值。
    //
    // v1 修复（register_shutdown_function）仍然超时，根因：
    //   PHP-FPM 下 register_shutdown_function 注册的函数执行完毕前，FPM 不会发送
    //   响应给 nginx。后处理耗时数分钟，nginx fastcgi_read_timeout 到期 → 504。
    //
    // v2 修复：sseDone() 后立即调用 fastcgi_finish_request()，FPM 请求结束、
    // 响应发送给 nginx/客户端，nginx 连接关闭不再计时。后处理在 FPM 进程中
    // 继续执行（此时已脱离 nginx 超时窗口）。参考 daemon_write.php:450 的做法。
    // 章节已在 ① 落库，后处理只是"增强"，失败不丢章、不重复生成。
    sseDone();
    $outlineTrace->phase('response_finished');

    // 关键：立即结束 FPM 请求，把 SSE 响应发送给 nginx → 客户端
    // 之后执行的代码不会影响客户端响应，nginx 不再计时
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // 非 FPM 环境（CLI/mod_php）兜底：强制结束输出缓冲
        while (ob_get_level() > 0) { ob_end_flush(); }
        flush();
    }

    // 非 off 模式的增强已由可重领 worker 承担；只有 off 模式继续执行
    // 下方原封不动的 legacy 请求内后台路径。
    if ($harnessMode !== HarnessControlPlane::MODE_OFF) {
        $outlineTrace->phase('finished');
        return;
    }

    // 后台执行：解除超时限制（后处理可能耗时数分钟）
    @set_time_limit(0);
    @ignore_user_abort(true);
    $outlineTrace->phase('postprocess');

    try {
        // 质量返修
        $qualityResult = repairOutlineBatchWithGuard(
            $novel,
            $outlines,
            $recentOutlines,
            null  // 后台无 SSE，不回调
        );
        $outlines = $qualityResult['outlines'];

        // 转折点增强
        try {
            $tpResult = enforceTurningPoints($novel, $startCh, $endCh, $outlines, null);
            if (!empty($tpResult['missing'])) {
                $tpMiss = implode('；', array_map(fn($t) => "第{$t['chapter']}章:{$t['event']}", $tpResult['missing']));
                addLog($novelId, $tpResult['repaired'] ? 'info' : 'warn',
                    ($tpResult['repaired'] ? '转折点已补入细纲：' : '转折点未覆盖（建议人工检查）：') . $tpMiss);
            }
            $outlines = $tpResult['outlines'];
        } catch (\Throwable $e) {
            // 转折点失败不阻断
        }

        // 审计功能（2026-08-16 P3-黄金三章）：开篇里程碑核验与定向返修（失败不阻断）。
        // 仅批次含第1-3章时实际生效；残留问题记日志供人工检查。
        try {
            $g3Result = enforceGoldenThree($novel, $startCh, $endCh, $outlines, null);
            if (!empty($g3Result['issues'])) {
                $g3Miss = implode('；', array_map(fn($i) => $i['hint'], $g3Result['issues']));
                addLog($novelId, $g3Result['repaired'] ? 'info' : 'warn',
                    ($g3Result['repaired'] ? '黄金三章里程碑已返修（残留，建议人工检查）：' : '黄金三章里程碑未达成（建议人工检查）：') . $g3Miss);
            }
            $outlines = $g3Result['outlines'];
        } catch (\Throwable $e) {
            // 黄金三章核验失败不阻断
        }

        // 增强后回写（覆盖已存行；若增强未改动则为幂等更新）
        try {
            $persistOutlines($outlines);
        } catch (\Throwable $e) {
            // 回写失败不阻断：原始版本已在库
        }

        // 弧段摘要压缩（每 10 章）
        $shouldCompress = ($endCh % 10 === 0) || (($endCh - $startCh + 1) >= 5 && count($outlines) >= 3);
        if ($shouldCompress) {
            $arcFrom = (int)(floor(($endCh - 1) / 10) * 10) + 1;
            $arcTo   = $endCh;
            try {
                generateAndSaveArcSummary($novel, $arcFrom, $arcTo);
            } catch (Throwable $e) {
                // 静默失败
            }
        }

        // 审计功能（2026-08-16 P3-卷级摘要）：批末区间卷末逐卷触发。
        // 查 volume_outlines 中 chapter_to 落在本批区间 [startCh, endCh] 的卷
        // 逐卷生成（BETWEEN 语义含 endCh 恰为卷末，批次跨卷时不漏触发）；
        // 无卷行时按 50 章回退分组计算本批跨过的分组边界（50 的倍数 ∈ 区间）。
        // 弧段未齐的卷 generateAndSaveVolumeSummary 返回 false 自动跳过；
        // 逐卷失败静默（下次卷末触发 / 重优化链自动补）。
        try {
            if (getSystemSetting('ws_volume_summary_enabled', true, 'bool')) {
                require_once dirname(__DIR__) . '/includes/memory.php';
                if (function_exists('generateAndSaveVolumeSummary') && function_exists('parseVolumeRows')) {
                    $volRows   = DB::fetchAll('SELECT * FROM volume_outlines WHERE novel_id=? ORDER BY id ASC', [$novelId]);
                    $volBounds = [];
                    foreach (parseVolumeRows($volRows) as $v) {
                        if ($v['chapter_to'] >= $startCh && $v['chapter_to'] <= $endCh) {
                            $volBounds[] = $v;
                        }
                    }
                    if (empty($volRows)) {
                        // 无卷书：50 章分组边界（vol*50）落在本批区间
                        for ($to = (int)ceil($startCh / 50) * 50; $to <= $endCh; $to += 50) {
                            $volBounds[] = [
                                'volume_number' => intdiv($to, 50),
                                'chapter_from'  => $to - 49,
                                'chapter_to'    => $to,
                            ];
                        }
                    }
                    $volDone = 0;
                    foreach ($volBounds as $vb) {
                        if (generateAndSaveVolumeSummary(
                            $novelId,
                            (int)$vb['volume_number'],
                            (int)$vb['chapter_from'],
                            (int)$vb['chapter_to'],
                            $novel['model_id'] ?? null
                        )) {
                            $volDone++;
                        }
                    }
                    if ($volDone > 0) {
                        addLog($novelId, 'outline', "本批卷级摘要已生成 {$volDone} 卷（第{$startCh}-{$endCh}章区间）");
                    }
                }
            }
        } catch (Throwable $e) {
            // 静默失败：卷级摘要不阻断大纲批末链路
        }

        // 更新小说状态
        $totalOutlined = DB::count('chapters', 'novel_id=? AND status != "pending"', [$novelId]);
        if ($totalOutlined >= (int)$novel['target_chapters']) {
            // 审计修复（2026-07-19 H-中3）：生成大纲不应改变小说状态。
    // 原实现将 completed/writing 书强制改为 draft，污染前端展示与挂机判断。
    // 仅在 status 为空或 NULL 时才设为 draft。
    $currentStatus = DB::fetchColumn('SELECT status FROM novels WHERE id=?', [$novelId]);
    if (empty($currentStatus)) {
        DB::update('novels', ['status' => 'draft'], 'id=?', [$novelId]);
    }
        }

        addLog($novelId, 'outline', "第{$startCh}-{$endCh}章后处理（质量返修/转折点/弧段/卷级摘要）完成");
        $outlineTrace->phase('finished');
    } catch (\Throwable $e) {
        $outlineTrace->phase('failed');
        addLog($novelId, 'warn', "第{$startCh}-{$endCh}章后处理失败：{$e->getMessage()}");
    }

} catch (Throwable $e) {
    $outlineTrace->phase('failed');
    // 审计 P0：异常细节（类型/消息/文件/行号）只写服务端日志，
    // 客户端仅收到稳定文案 + 可追踪请求 ID。
    $rid = error_trace_id();
    error_log(sprintf('[%s] generate_outline uncaught %s: %s in %s:%d',
        $rid, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    if (!headers_sent()) {
        http_response_code(200);
    }
    $sourceConflict = $e instanceof ChapterOutlineSourceChangedException;
    $clientMsg = $sourceConflict
        ? '生成期间章节大纲、简介或状态已变化，已保留当前版本并停止本批次，请刷新核对后再生成。'
        : '服务器内部错误，请稍后重试（追踪号 ' . $rid . '）';
    echo "event: fatal_error\n";
    echo 'data: ' . json_encode([
        'code'       => $sourceConflict ? 'outline_source_changed' : 'internal_error',
        'error'      => $clientMsg,
        'message'    => $clientMsg,
        'msg'        => $clientMsg,
        'request_id' => $rid,
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    // 确保 [DONE] 被发送，让前端知道流已正常结束
    echo "data: [DONE]\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}
