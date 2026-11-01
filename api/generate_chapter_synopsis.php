<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 生成章节概要 API（流式 SSE）
 * POST JSON: { novel_id, chapter_ids? }
 * 如果不传chapter_ids，则生成所有已大纲但未生成概要的章节
 */
ob_start();
ini_set('display_errors', '0');

defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ChapterSynopsisPersistence.php';
requireLoginApi();
session_write_close();

ob_end_clean();
set_time_limit(CFG_TIME_LONG);

// 审计修复（2026-07-19 H-中11）：SSE 端点必须 ignore_user_abort
ignore_user_abort(true);

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
$force      = !empty($input['force']);

// 审计 P0（2026-06-12）：归属校验 + 章节数量上限
$userId = currentUserId();
if ($novelId > 0) checkNovelOwnership($novelId, $userId);
if (is_array($chapterIds) && count($chapterIds) > 200) {
    sse('error', ['msg' => '单次最多 200 个章节']); sseDone(); exit;
}
if ($chapterIds !== null && !is_array($chapterIds)) {
    sse('error', ['msg' => '章节列表格式无效']); sseDone(); exit;
}

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sse('error', ['msg' => '小说不存在']); sseDone(); exit; }

// 预检：至少要有一个模型
try { getModelFallbackList($novel['model_id'] ?: null, 'synopsis'); }
catch (RuntimeException $e) { sse('error', safe_sse_error_payload($e, '模型不可用，请稍后重试')); sseDone(); exit; }

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
         WHERE novel_id=? AND status IN ("outlined","writing","completed")
         AND (synopsis_id IS NULL OR NOT EXISTS (SELECT 1 FROM chapter_synopses s WHERE s.id=chapters.synopsis_id AND TRIM(COALESCE(s.synopsis, "")) <> ""))
         ORDER BY chapter_number ASC',
        [$novelId]
    );
} else {
    // 审计修复（2026-07-19 H-中4）：空数组提前返回，避免 `id IN ()` SQL 语法错误
    if (empty($chapterIds)) {
        sse('error', ['msg' => '未指定任何章节']);
        sseDone();
        exit;
    }
    // 审计修复（2026-07-19 H-04）：非 force 模式仅处理尚无概要的章节，与自动模式语义一致，
    // 空概要属于未完成；重新生成也保留旧概要直到新结果安全提交。
    $filterClause = $force ? '' : ' AND (synopsis_id IS NULL OR NOT EXISTS (SELECT 1 FROM chapter_synopses s WHERE s.id=chapters.synopsis_id AND TRIM(COALESCE(s.synopsis, "")) <> ""))';
    $chapters = DB::fetchAll(
        'SELECT * FROM chapters WHERE novel_id=? AND id IN (' . implode(',', array_map('intval', $chapterIds)) . ')' . $filterClause . '
         ORDER BY chapter_number ASC',
        [$novelId]
    );
}

if (empty($chapters)) {
    sse('error', ['msg' => '没有待生成概要的章节']);
    sseDone();
    exit;
}

$persistence = new ChapterSynopsisPersistence(DB::connect(), $userId, $novelId);
$totalChapters = count($chapters);
$current = 0;
$succeeded = 0;
$failedChapters = [];
$conflictedChapters = [];
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
    
    try {
        $snapshot = $persistence->capture((int)$ch['chapter_number'], $force);
        $ch = $snapshot['chapter'];
        $novel = array_replace($novel, $snapshot['novel']);
        $messages = buildChapterSynopsisPrompt($novel, $ch, $snapshot['story']);
    } catch (ChapterSynopsisSourceChangedException $sourceError) {
        $conflictedChapters[] = (int)$ch['chapter_number'];
        sse('conflict', ['chapter'=>(int)$ch['chapter_number'], 'msg'=>"第{$ch['chapter_number']}章来源已变化或无法读取，未替换现有概要"]);
        continue;
    } catch (Throwable $sourceError) {
        $failedChapters[] = (int)$ch['chapter_number'];
        error_log('generate_chapter_synopsis source read failed: ' . $sourceError->getMessage());
        sse('error', ['chapter'=>(int)$ch['chapter_number'], 'msg'=>"第{$ch['chapter_number']}章来源读取失败，已保留原概要"]);
        continue;
    }
    $rawResponse = '';
    $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    $lastSynopsisHeartbeat = time();

    $GLOBALS['sendHeartbeat'] = function() use (&$lastSynopsisHeartbeat) {
        $now = time();
        if ($now - $lastSynopsisHeartbeat >= 5) {
            echo ": heartbeat\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            $lastSynopsisHeartbeat = $now;
        }
    };

    try {
        withModelFallback(
            $novel['model_id'] ?: null,
            function (AIClient $ai) use ($messages, &$rawResponse, &$usage, &$lastSynopsisHeartbeat) {
                $rawResponse = '';
                $ai->setCallbacks(
                    $GLOBALS['sendHeartbeat'],
                    null
                );
                $usage = $ai->chatStream($messages, function (string $token) use (&$rawResponse, &$lastSynopsisHeartbeat) {
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
    } catch (Throwable $e) {
        $failedChapters[] = (int)$ch['chapter_number'];
        sse('error', safe_sse_error_payload($e, "第{$ch['chapter_number']}章概要生成失败"));
        continue;
    }

    $totalPrompt += $usage['prompt_tokens'];
    $totalCompletion += $usage['completion_tokens'];

    try {
        $synData = ChapterSynopsisOutput::parse($rawResponse, (int)$ch['chapter_number']);
    } catch (Throwable $validationError) {
        $failedChapters[] = (int)$ch['chapter_number'];
        sse('error', [
            'msg' => "第{$ch['chapter_number']}章概要内容或结构无效，已保留原概要",
        ]);
        continue;
    }
    
    // 发布前在同一短事务中复核来源；模型请求期间不持有锁也不清除旧稿。
    try {
        if (!$persistence->saveGenerated((int)$ch['chapter_number'], $synData)) {
            $conflictedChapters[] = (int)$ch['chapter_number'];
            sse('conflict', ['chapter'=>(int)$ch['chapter_number'], 'msg'=>"第{$ch['chapter_number']}章大纲或概要已被修改，已保留当前版本"]);
            continue;
        }
    } catch (Throwable $dbErr) {
        $failedChapters[] = (int)$ch['chapter_number'];
        error_log('generate_chapter_synopsis 保存失败: chapter=' . $ch['chapter_number'] . ' - ' . $dbErr->getMessage());
        sse('error', [
            'msg' => "第{$ch['chapter_number']}章概要保存失败，跳过",
        ]);
        continue;
    }

    $succeeded++;
    sse('chapter_done', [
        'msg'               => "第{$ch['chapter_number']}章概要已保存",
        'chapter'           => $ch['chapter_number'],
        'synopsis_preview'  => safe_substr($synData['synopsis'], 0, 60) . '...',
    ]);
}

$failed = count($failedChapters);
$conflicted = count($conflictedChapters);
$resultStatus = $failed + $conflicted === 0 ? 'completed' : ($succeeded > 0 ? 'partial' : 'failed');
$resultMessage = "章节概要处理结束：成功{$succeeded}章，失败{$failed}章，冲突{$conflicted}章";
addLog($novelId, 'synopsis', $resultMessage);

sse('complete', [
    'msg'               => $resultMessage,
    'status'            => $resultStatus,
    'total_chapters'    => $succeeded,
    'attempted_chapters'=> $current,
    'succeeded'         => $succeeded,
    'failed'            => $failed,
    'conflicted'        => $conflicted,
    'failed_chapters'   => $failedChapters,
    'conflicted_chapters'=> $conflictedChapters,
    'prompt_tokens'     => $totalPrompt,
    'completion_tokens' => $totalCompletion,
    'total_tokens'      => $totalPrompt + $totalCompletion,
]);
sseDone();
