<?php
/**
 * ================================================================
 * daemon_write.php — 挂机写作 Worker
 * ================================================================
 * 用途：由宝塔计划任务（Cron）定期 HTTP 访问此文件，实现无人值守写作。
 *
 * 宝塔 Cron 配置示例：
 *   执行周期：每 1 分钟
 *   脚本类型：Shell 脚本
 *   脚本内容：curl -s "http://你的域名/api/daemon_write.php?token=你的TOKEN"
 *
 * 安全机制：
 *   - 通过 URL 参数 ?token=xxx 做密钥鉴权，跳过 Session 登录检查
 *   - Token 存储在 system_settings 表中，key = daemon_token
 *   - 首次访问（未设置 token）会自动生成并返回 token
 *
 * 工作逻辑：
 *   1. 全局只允许一个小说开启挂机（enable 时自动关闭其他）
 *   2. 检查当前是否有其他进程正在写作（lock file 机制，防并发）
 *   3. 直接在进程内执行写作逻辑（不走 HTTP/Session/CSRF），调用 AIClient 同步写作
 *   4. 写完后更新统计、记录日志
 *   5. 如果所有章节完成，自动关闭挂机
 * ================================================================
 *
 * ==============================
 * 【可配置项】失败重试策略
 * ==============================
 *
 *   DAEMON_RETRY_MODE
 *     'skip'    — 重试 DAEMON_MAX_RETRIES 次后跳过该章节，继续写下一章（默认）
 *     'forever' — 一直重试，永不跳过（直到写成功为止）
 *
 *   DAEMON_MAX_RETRIES
 *     重试次数上限，仅在 DAEMON_RETRY_MODE = 'skip' 时生效。
 *     默认 3，即失败 3 次后标记为 skipped 跳过。
 *     设为 0 表示失败立即跳过（不重试）。
 * ================================================================
 */

// ================================================================
// 【配置区】修改这里调整重试策略
// ================================================================

/**
 * 失败重试模式：
 *   'skip'    — 超过最大重试次数后跳过该章节
 *   'forever' — 永远重试，直到写成功
 */
define('DAEMON_RETRY_MODE',    'skip');

/**
 * 最大重试次数（仅 DAEMON_RETRY_MODE = 'skip' 时有效）
 * 设为 0 表示失败后立即跳过，不重试
 */
define('DAEMON_MAX_RETRIES',   3);

header('Cache-Control: no-cache, no-store');
header('Content-Type: application/json; charset=utf-8');

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/memory.php';
require_once dirname(__DIR__) . '/includes/prompt.php';

ob_end_clean();

// ================================================================
// 1. 鉴权：Token 验证
// ================================================================

$inputToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$novelId    = (int)($_GET['novel_id'] ?? $_POST['novel_id'] ?? 0);
$action     = trim($_GET['action'] ?? $_POST['action'] ?? 'run');

function getDaemonToken(): string {
    try {
        $row = DB::fetch("SELECT setting_value FROM system_settings WHERE setting_key='daemon_token'");
        if ($row && !empty($row['setting_value'])) {
            return $row['setting_value'];
        }
        $token = bin2hex(random_bytes(24));
        DB::insert('system_settings', [
            'setting_key'   => 'daemon_token',
            'setting_value' => $token,
        ]);
        return $token;
    } catch (\Throwable $e) {
        $file = BASE_PATH . '/daemon.token';
        if (file_exists($file)) return trim(file_get_contents($file));
        $token = bin2hex(random_bytes(24));
        file_put_contents($file, $token, LOCK_EX);
        return $token;
    }
}

$daemonToken = getDaemonToken();

if ($inputToken === '') {
    $hint = "curl \"http://你的域名/api/daemon_write.php?token={$daemonToken}\"";
    echo json_encode([
        'ok'    => false,
        'msg'   => '请在 URL 中携带 token 参数',
        'hint'  => $hint,
        'token' => $daemonToken,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals($daemonToken, $inputToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token 错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================
// 2. 管理操作（启用/停用/状态查询）
// ================================================================

if ($action === 'enable' || $action === 'disable') {
    if (!$novelId) {
        echo json_encode(['ok' => false, 'msg' => '缺少 novel_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $val = $action === 'enable' ? 1 : 0;
    try {
        $switchedFrom = null;
        if ($val === 1) {
            // 全局唯一：先关闭其他已开启的挂机
            $existing = DB::fetch(
                "SELECT id, title FROM novels WHERE daemon_write=1 AND id!=?",
                [$novelId]
            );
            if ($existing) {
                DB::update('novels', ['daemon_write' => 0], 'id=?', [$existing['id']]);
                @unlink(BASE_PATH . "/daemon_lock_{$existing['id']}.tmp");
                $switchedFrom = ['id' => (int)$existing['id'], 'title' => $existing['title']];
            }
        }
        DB::update('novels', ['daemon_write' => $val], 'id=?', [$novelId]);
        if ($val === 0) {
            @unlink(BASE_PATH . "/daemon_lock_{$novelId}.tmp");
        }
        $resp = [
            'ok'  => true,
            'msg' => $val ? "小说#{$novelId} 挂机写作已启用" : "小说#{$novelId} 挂机写作已停用",
        ];
        if ($switchedFrom) $resp['switched_from'] = $switchedFrom;
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => '操作失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'status') {
    try {
        // 无论是否传 novel_id，优先找当前开启挂机的书
        if (!$novelId) {
            $row = DB::fetch("SELECT id FROM novels WHERE daemon_write=1 LIMIT 1");
            if ($row) $novelId = (int)$row['id'];
        }
        if (!$novelId) {
            echo json_encode(['ok' => true, 'data' => null, 'msg' => '没有开启挂机的小说'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $novel     = DB::fetch("SELECT id, title, status, current_chapter, total_words, daemon_write FROM novels WHERE id=?", [$novelId]);
        $completed = DB::count('chapters', 'novel_id=? AND status="completed"', [$novelId]);
        $outlined  = DB::count('chapters', 'novel_id=? AND status IN ("outlined","writing","completed","skipped")', [$novelId]);
        $skipped   = DB::count('chapters', 'novel_id=? AND status="skipped"', [$novelId]);
        $locked    = file_exists(BASE_PATH . "/daemon_lock_{$novelId}.tmp");
        $logs      = DB::fetchAll(
            "SELECT action, message, created_at FROM writing_logs WHERE novel_id=? ORDER BY created_at DESC LIMIT 10",
            [$novelId]
        );
        echo json_encode([
            'ok'   => true,
            'data' => array_merge($novel ?: [], [
                'completed' => $completed,
                'outlined'  => $outlined,
                'skipped'   => $skipped,
                'locked'    => $locked,
                'logs'      => $logs,
            ]),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ================================================================
// 3. 主执行逻辑：run（由 Cron 触发）
// ================================================================

// 未传 novel_id 时自动找当前开启挂机的书（全局唯一）
if (!$novelId) {
    try {
        $row = DB::fetch("SELECT id FROM novels WHERE daemon_write=1 LIMIT 1");
        if ($row) $novelId = (int)$row['id'];
    } catch (\Throwable $e) {
        error_log('daemon_write: 查询挂机小说失败 — ' . $e->getMessage());
    }
}

if (!$novelId) {
    echo json_encode(['ok' => false, 'msg' => '没有启用挂机写作的小说', 'skipped' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $novel = DB::fetch("SELECT * FROM novels WHERE id=?", [$novelId]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => '数据库错误：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$novel) {
    echo json_encode(['ok' => false, 'msg' => "小说#{$novelId} 不存在"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($novel['daemon_write'])) {
    echo json_encode(['ok' => false, 'msg' => "小说#{$novelId} 未启用挂机写作", 'skipped' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Lock 文件：防止并发 ----
$lockFile = BASE_PATH . "/daemon_lock_{$novelId}.tmp";

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);

    // 超过 5 分钟强制清除（fastcgi_finish_request 后进程可能被意外终止导致 Lock 残留）
    if ($lockAge > 300) {
        @unlink($lockFile);
        addLog($novelId, 'daemon_unlock', "检测到残留 Lock（已持续 {$lockAge}s > 300s），强制清除");
    } else {
        // Lock 存在但时间短：再检查一下对应章节是否已完成（可能进程已完成但 Lock 未删）
        $lockedChapterDone = false;
        $lockContent = @file_get_contents($lockFile);
        if (preg_match('/chapter#(\d+)/', $lockContent, $m)) {
            $lockedChId = (int)$m[1];
            $lockedCh   = DB::fetch("SELECT status FROM chapters WHERE id=?", [$lockedChId]);
            if ($lockedCh && $lockedCh['status'] === 'completed') {
                @unlink($lockFile);
                $lockedChapterDone = true;
                addLog($novelId, 'daemon_unlock', "章节#{$lockedChId} 已完成但 Lock 残留，已清除");
            }
        }

        if (!$lockedChapterDone) {
            echo json_encode([
                'ok'       => false,
                'msg'      => "上一章仍在写作中（Lock 已持续 {$lockAge} 秒），跳过本次",
                'skipped'  => true,
                'lock_age' => $lockAge,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// 查找下一章
// 策略：每写完3章 outlined 后补写1章 skipped，避免 skipped 章节长期排不上
$nextChapter = null;
$isCatchup   = false;

// 优先写 outlined，但每3章穿插补写1章 skipped（如果有的话）
$recentCompleted = DB::count('chapters', 'novel_id=? AND status="completed"', [$novelId]);
$skippedCount    = DB::count('chapters', 'novel_id=? AND status="skipped"', [$novelId]);

// 每写完3章 outlined，如果有 skipped 章节，则优先补写1章 skipped
$shouldCatchup = ($skippedCount > 0) && ($recentCompleted > 0) && ($recentCompleted % 3 === 0);

if ($shouldCatchup) {
    $nextChapter = DB::fetch(
        "SELECT * FROM chapters WHERE novel_id=? AND status='skipped' ORDER BY chapter_number ASC LIMIT 1",
        [$novelId]
    );
    if ($nextChapter) {
        $isCatchup = true;
        addLog($novelId, 'daemon_catchup', "穿插补写：已写{$recentCompleted}章，跳过{$skippedCount}章待补");
    }
}

if (!$nextChapter) {
    $nextChapter = DB::fetch(
        "SELECT * FROM chapters WHERE novel_id=? AND status='outlined' ORDER BY chapter_number ASC LIMIT 1",
        [$novelId]
    );
}

if (!$nextChapter) {
    $nextChapter = DB::fetch(
        "SELECT * FROM chapters WHERE novel_id=? AND status='skipped' ORDER BY chapter_number ASC LIMIT 1",
        [$novelId]
    );
    $isCatchup = true;
}

if (!$nextChapter) {
    DB::update('novels', ['daemon_write' => 0, 'status' => 'completed'], 'id=?', [$novelId]);
    addLog($novelId, 'daemon_done', '挂机写作完成，所有章节已生成，已自动关闭挂机');
    echo json_encode(['ok' => true, 'msg' => '所有章节写作完成，挂机已自动关闭', 'done' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// 创建 Lock
file_put_contents($lockFile, date('Y-m-d H:i:s') . " chapter#{$nextChapter['id']}", LOCK_EX);

DB::update('novels',   ['status' => 'writing', 'cancel_flag' => 0], 'id=?', [$novelId]);
// 补写 skipped 章节时重置 retry_count（之前的失败可能是临时问题，补写时给全新机会）
DB::update('chapters', ['status' => 'writing', 'retry_count' => 0], 'id=? AND status IN ("outlined","skipped")', [$nextChapter['id']]);

addLog($novelId, 'daemon_start',
    ($isCatchup ? '[补写]' : '') . "挂机开始写作第{$nextChapter['chapter_number']}章《{$nextChapter['title']}》"
);

// ================================================================
// 4. 立即返回 202 响应，后台继续执行写作（避免 nginx 502 超时）
// ================================================================

set_time_limit(CFG_TIME_LONG);
ignore_user_abort(true);

// 先把响应发回给 nginx/curl，断开 HTTP 连接
http_response_code(202);
echo json_encode([
    'ok'             => true,
    'msg'            => "第{$nextChapter['chapter_number']}章开始写作，后台执行中...",
    'novel_id'       => $novelId,
    'chapter_number' => $nextChapter['chapter_number'],
    'is_catchup'     => $isCatchup,
    'timestamp'      => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);

// FPM 环境：关闭连接，PHP 进程继续在后台运行
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // 非 FPM 环境兜底：flush 后脚本继续
    if (ob_get_level()) ob_end_flush();
    flush();
}

$writeResult  = ['ok' => false, 'msg' => '未执行'];
$fullContent  = '';
$usedModel    = null;
$ch           = $nextChapter; // 别名，与 write_chapter.php 保持一致

try {

    // ---- 记忆引擎 ----
    require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
    $engine = new MemoryEngine($novelId);
    try { $engine->ensureEmbeddings(30); } catch (\Throwable $e) {
        addLog($novelId, 'warn', 'ensureEmbeddings 失败：' . $e->getMessage());
    }

    $queryText    = trim(($ch['title'] ?? '') . '：' . ($ch['outline'] ?? ''));
    $memoryLookback = max(1, min(15, (int)getSystemSetting('ws_memory_lookback', 5, 'int')));
    $semanticTopK   = max(1, min(20, (int)getSystemSetting('ws_embedding_top_k', 5, 'int')));
    try {
        $memoryCtx = $engine->getPromptContext(
            (int)$ch['chapter_number'], $queryText, CFG_MEMORY_TOKEN_BUDGET, 20, $semanticTopK
        );
    } catch (\Throwable $e) {
        addLog($novelId, 'error', 'MemoryEngine.getPromptContext 失败：' . $e->getMessage());
        $memoryCtx = null;
    }

    $previousSummary = getPreviousSummary($novelId, (int)$ch['chapter_number']);
    $previousTail    = $memoryCtx['L4_previous_tail'] ?? getPreviousTail($novelId, (int)$ch['chapter_number']);
    $messages        = buildChapterPrompt($novel, $ch, $previousSummary, $previousTail, $memoryCtx);

    // ---- 模型列表 + 写作 ----
    $targetWords        = (int)$novel['chapter_words'];
    // 与 write_chapter.php 保持一致：按上限字数容差估算
    $daemonTolerance    = max(CFG_TOLERANCE_MIN, (int)($targetWords * CFG_TOLERANCE_RATIO));
    $daemonMaxWords     = $targetWords + $daemonTolerance;
    $estimatedMaxTokens = (int)($daemonMaxWords * CFG_TOKEN_RATIO) + CFG_TOKEN_BUFFER;

    $modelList   = getModelFallbackList($novel['model_id'] ?: null);
    $modelErrors = [];

    foreach ($modelList as $modelCfg) {
        $modelId    = (int)($modelCfg['id'] ?? 0);
        $modelLabel = $modelCfg['name'] ?? "模型{$modelId}";
        $isThinking = !empty($modelCfg['thinking_enabled']);
        $timeoutSec = $isThinking ? RT_THINKING_TIMEOUT : RT_NONTHINKING_TIMEOUT;

        if (($modelErrors[$modelId] ?? 0) >= RT_MODEL_ERR_MAX) continue;

        $sameModelRetries = 0;
        while ($sameModelRetries < RT_SAME_MODEL_MAX) {
            // 重试间隔递增：第1次重试等15s，第2次等30s，第3次等45s
            if ($sameModelRetries > 0) {
                $retryDelay = RT_RETRY_DELAY * $sameModelRetries;
                addLog($novelId, 'daemon_retry_wait', "{$modelLabel} 等待{$retryDelay}秒后重试...");
                sleep($retryDelay);
            }
            $streamStart = time();
            $fullContent = '';
            $ai          = new AIClient($modelCfg);
            $usedModel   = $ai;

            $desired = max($ai->getMaxTokens(), $estimatedMaxTokens);
            if ($desired > $ai->getMaxTokens()) $ai->setMaxTokens($desired);

            addLog($novelId, 'daemon_model',
                "{$modelLabel} 第" . ($sameModelRetries + 1) . "次尝试，超时{$timeoutSec}s"
            );

            try {
                $ai->chatStream($messages, function(string $token) use (&$fullContent) {
                    if ($token === '[DONE]') return;
                    $fullContent .= $token;
                }, 'creative');
            } catch (\Exception $e) {
                $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                $sameModelRetries++;
                addLog($novelId, 'daemon_retry_model',
                    "API错误（{$e->getMessage()}），第{$sameModelRetries}/" . RT_SAME_MODEL_MAX
                );
                if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                continue 2;
            }

            // 超时检测
            $sinceLast = time() - ($ai->lastChunkTime ?: $streamStart);
            if ($sinceLast >= $timeoutSec && empty(trim($fullContent))) {
                $sameModelRetries++;
                addLog($novelId, 'daemon_timeout',
                    "超时（{$sinceLast}s 无输出），重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX
                );
                if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                continue 2;
            }

            $modelErrors[$modelId] = 0;
            break 2;
        }
    }

    if (empty(trim($fullContent))) {
        throw new \RuntimeException('所有模型均未产生有效输出');
    }

    // ---- 超字兜底截断（与 write_chapter.php 逻辑保持一致）----
    $daemonActualWords = countWords($fullContent);
    if ($daemonActualWords > $daemonMaxWords) {
        $fullContent = truncateToWordLimit($fullContent, $daemonMaxWords);
        $daemonTrimmed = countWords($fullContent);
        addLog($novelId, 'warn',
            "第{$ch['chapter_number']}章超字（{$daemonActualWords}字），自动修剪至{$daemonTrimmed}字",
            $ch['id']
        );
    }

    // ---- 落盘 ----
    $words    = countWords($fullContent);
    $affected = DB::update('chapters', [
        'content' => $fullContent,
        'words'   => $words,
        'status'  => 'completed',
    ], 'id=? AND status="writing"', [$ch['id']]);

    if ($affected === 0) {
        // 状态被外部修改（用户手动取消等），放弃
        throw new \RuntimeException('章节状态已被外部修改，放弃落盘');
    }

    updateNovelStats($novelId);
    $modelInfo = $usedModel ? "（{$usedModel->modelLabel}）" : '';
    addLog($novelId, 'write',
        "完成第{$ch['chapter_number']}章《{$ch['title']}》，共{$words}字{$modelInfo}",
        $ch['id']
    );

    // ---- 摘要 + 记忆引擎 ----
    try {
        $summaryData = generateChapterSummary($novel, $ch, $fullContent);
        if (!empty($summaryData)) {
            $chapterUpdates = [];
            if (!empty($summaryData['narrative_summary']))
                $chapterUpdates['chapter_summary'] = $summaryData['narrative_summary'];
            if (!empty($summaryData['used_tropes']))
                $chapterUpdates['used_tropes'] = json_encode($summaryData['used_tropes'], JSON_UNESCAPED_UNICODE);
            if (!empty($chapterUpdates))
                DB::update('chapters', $chapterUpdates, 'id=?', [$ch['id']]);

            $ingestReport = $engine->ingestChapter((int)$ch['chapter_number'], $summaryData);
            if (!empty($ingestReport['errors'])) {
                addLog($novelId, 'warn', 'MemoryEngine.ingestChapter 部分失败：'
                    . implode('; ', $ingestReport['errors']));
            }
        }
    } catch (\Throwable $e) {
        addLog($novelId, 'warn', '摘要/记忆处理失败（正文已保存）：' . $e->getMessage());
    }

    // ---- 知识库提取 ----
    try {
        require_once dirname(__DIR__) . '/includes/embedding.php';
        $kb = new KnowledgeBase($novelId);
        $kb->extractFromChapter((int)$ch['chapter_number'], $fullContent);
    } catch (\Throwable $e) {
        addLog($novelId, 'warn', '知识库提取失败：' . $e->getMessage());
    }

    $writeResult = [
        'ok'    => true,
        'msg'   => "第{$ch['chapter_number']}章写作完成（{$words}字）{$modelInfo}",
        'words' => $words,
    ];

} catch (\Throwable $e) {
    // 写作彻底失败 → 根据重试策略决定重试或跳过
    $retryCount = (int)(DB::fetch(
        "SELECT retry_count FROM chapters WHERE id=?", [$ch['id']]
    )['retry_count'] ?? 0) + 1;

    $shouldSkip = (DAEMON_RETRY_MODE === 'skip') && ($retryCount > DAEMON_MAX_RETRIES);

    if ($shouldSkip) {
        DB::update('chapters',
            ['status' => 'skipped', 'retry_count' => $retryCount],
            'id=? AND status NOT IN ("completed")', [$ch['id']]
        );
        addLog($novelId, 'daemon_skip',
            "第{$ch['chapter_number']}章超过最大重试次数（" . DAEMON_MAX_RETRIES . "次），标记跳过：" . $e->getMessage()
        );
        $writeResult = ['ok' => false, 'msg' => "第{$ch['chapter_number']}章已跳过（失败{$retryCount}次）：" . $e->getMessage()];
    } else {
        DB::update('chapters',
            ['status' => 'outlined', 'retry_count' => $retryCount],
            'id=? AND status NOT IN ("completed")', [$ch['id']]
        );
        $modeHint = DAEMON_RETRY_MODE === 'forever' ? '永久重试模式' : ('将重试，第' . $retryCount . '/' . DAEMON_MAX_RETRIES . '次');
        addLog($novelId, 'daemon_error',
            "第{$ch['chapter_number']}章出错（{$modeHint}）：" . $e->getMessage()
        );
        $writeResult = ['ok' => false, 'msg' => "第{$ch['chapter_number']}章出错（{$modeHint}）：" . $e->getMessage()];
    }
}

// 释放 Lock
@unlink($lockFile);

// 更新小说状态
$remaining = DB::count('chapters', 'novel_id=? AND status IN ("outlined","skipped")', [$novelId]);
if ($remaining === 0 && $writeResult['ok']) {
    DB::update('novels', ['daemon_write' => 0, 'status' => 'completed'], 'id=?', [$novelId]);
    addLog($novelId, 'daemon_all_done', '挂机写作：全部章节完成，已自动关闭挂机');
} else {
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
}

// 响应已在写作开始前发出，此处只记录最终结果日志
addLog($novelId, 'daemon_result', implode(' | ', array_filter([
    $writeResult['msg'] ?? '',
    isset($writeResult['words']) ? "字数:{$writeResult['words']}" : '',
    "剩余:{$remaining}章",
])));
