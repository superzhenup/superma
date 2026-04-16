<?php
/**
 * 写作章节 API（流式 SSE + 模型自动 fallback）
 * 优化：修复摘要生成竞态条件——摘要同步完成后再发送完成信号
 * POST JSON: { novel_id, chapter_id? }
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

function sseChunkWrite(string $chunk): void {
    echo 'data: ' . json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}
function sseMsgWrite(array $payload): void {
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}
function sseDoneWrite(): void {
    echo "data: [DONE]\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ---- 解析入参 ----
$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId   = (int)($input['novel_id']   ?? 0);
$chapterId = (int)($input['chapter_id'] ?? 0);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) { sseMsgWrite(['error' => '小说不存在']); sseDoneWrite(); exit; }

$ch = $chapterId
    ? DB::fetch('SELECT * FROM chapters WHERE id=? AND novel_id=?', [$chapterId, $novelId])
    : DB::fetch('SELECT * FROM chapters WHERE novel_id=? AND status="outlined" ORDER BY chapter_number ASC LIMIT 1', [$novelId]);

if (!$ch) {
    sseMsgWrite(['error' => '没有待写章节，请先生成大纲。']);
    sseDoneWrite(); exit;
}

DB::update('novels', ['cancel_flag' => 0], 'id=?', [$novelId]);
DB::update('chapters', ['status' => 'writing'], 'id=?', [$ch['id']]);
DB::update('novels',   ['status' => 'writing'], 'id=?', [$novelId]);

$previousSummary = getPreviousSummary($novelId, (int)$ch['chapter_number']);
$previousTail    = getPreviousTail($novelId, (int)$ch['chapter_number']);
$messages        = buildChapterPrompt($novel, $ch, $previousSummary, $previousTail);
$fullContent     = '';
$usedModel       = null;
$canceled        = false;
$cancelCheckCounter = 0;

// ---- fallback 写作 ----
try {
    withModelFallback(
        $novel['model_id'] ?: null,
        function (AIClient $ai) use ($messages, &$fullContent, &$usedModel, $novelId, &$canceled, &$cancelCheckCounter) {
            $usedModel   = $ai;
            $fullContent = '';
            // 正文写作使用 creative 任务类型（保持用户配置的高temperature）
            $ai->chatStream($messages, function (string $token) use (&$fullContent, $novelId, &$canceled, &$cancelCheckCounter) {
                if (!$canceled && ++$cancelCheckCounter % 50 === 0) {
                    $novel = DB::fetch('SELECT cancel_flag FROM novels WHERE id = ?', [$novelId]);
                    if ($novel && $novel['cancel_flag']) {
                        $canceled = true;
                    }
                }

                if ($canceled) {
                    throw new Exception('用户取消了写作');
                }

                if ($token === '[DONE]') return;
                $fullContent .= $token;
                sseChunkWrite($token);
            }, 'creative');
        },
        function (AIClient $nextAi, string $errMsg) use (&$fullContent) {
            sseMsgWrite([
                'model_switch' => true,
                'next_model'   => $nextAi->modelLabel,
                'error'        => $errMsg,
            ]);
            $fullContent = '';
        }
    );
} catch (RuntimeException $e) {
    DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    sseMsgWrite(['error' => '所有模型均请求失败：' . $e->getMessage()]);
    sseDoneWrite(); exit;
} catch (Exception $e) {
    DB::update('chapters', ['status' => 'outlined', 'content' => '', 'words' => 0], 'id=?', [$ch['id']]);
    DB::update('novels', ['status' => 'paused'], 'id=?', [$novelId]);
    sseMsgWrite(['error' => $e->getMessage(), 'canceled' => true]);
    sseDoneWrite(); exit;
}

// ---- [BUG修复] 版本快照：删除重复的双重快照逻辑，合并为一段 ----
// 原代码存在两段几乎相同的快照逻辑，导致每次写章节产生两条版本记录且版本号错乱。
// 修复：复用已查出的 $ch 数据（避免多一次 SELECT），仅在有实质性旧内容时创建快照，
// 同时保留最近 10 版，防止磁盘无限膨胀。
$oldContent = $ch['content'] ?? '';
$oldWords   = (int)($ch['words'] ?? 0);

if (!empty($oldContent) && $oldWords > 100) {
    $maxVer = (int)(DB::fetch(
        'SELECT COALESCE(MAX(version), 0) AS v FROM chapter_versions WHERE chapter_id=?',
        [$ch['id']]
    )['v'] ?? 0);
    $newVer = $maxVer + 1;

    DB::insert('chapter_versions', [
        'chapter_id' => $ch['id'],
        'version'    => $newVer,
        'content'    => $oldContent,
        'outline'    => $ch['outline'] ?? '',
        'title'      => $ch['title']   ?? '',
        'words'      => $oldWords,
    ]);
    sseMsgWrite(['version_saved' => true, 'version' => $newVer, 'words' => $oldWords]);

    // 保留最近 10 个版本，自动清理过旧快照
    DB::execute(
        'DELETE FROM chapter_versions WHERE chapter_id=? AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM chapter_versions WHERE chapter_id=? ORDER BY version DESC LIMIT 10
            ) t
        )',
        [$ch['id'], $ch['id']]
    );
}

// ---- 保存正文 ----
$words = countWords($fullContent);
DB::update('chapters', [
    'content' => $fullContent,
    'words'   => $words,
    'status'  => 'completed',
], 'id=?', [$ch['id']]);

updateNovelStats($novelId);

$modelInfo = $usedModel ? "（{$usedModel->modelLabel}）" : '';
addLog($novelId, 'write',
    "完成第{$ch['chapter_number']}章《{$ch['title']}》，共{$words}字{$modelInfo}",
    $ch['id']
);

// ---- [修复竞态] 先生成摘要、更新全局状态，再发送完成信号 ----
// 原来：先sseDoneWrite再生成摘要，导致连续写作时下一章读不到当章状态
// 现在：摘要同步完成后再通知前端，消除竞态条件
sseMsgWrite(['status' => 'summarizing', 'msg' => '正在生成章节摘要…']);

$summaryData = generateChapterSummary($novel, $ch, $fullContent);
if (!empty($summaryData)) {
    $chapterUpdates = [];
    if (!empty($summaryData['narrative_summary'])) {
        $chapterUpdates['chapter_summary'] = $summaryData['narrative_summary'];
    }
    if (!empty($summaryData['used_tropes'])) {
        $chapterUpdates['used_tropes'] = json_encode($summaryData['used_tropes'], JSON_UNESCAPED_UNICODE);
    }
    if (!empty($chapterUpdates)) {
        DB::update('chapters', $chapterUpdates, 'id=?', [$ch['id']]);
    }

    updateNovelMeta(
        $novelId,
        (int)$ch['chapter_number'],
        $summaryData['character_updates']      ?? [],
        $summaryData['key_event']              ?? '',
        $summaryData['new_foreshadowing']      ?? [],
        $summaryData['resolved_foreshadowing'] ?? [],
        $summaryData['story_momentum']         ?? ''
    );

    // [v4] 记录伏笔回收日志到专用表
    if (!empty($summaryData['new_foreshadowing']) || !empty($summaryData['resolved_foreshadowing'])) {
        logForeshadowing(
            $novelId,
            (int)$ch['id'],
            (int)$ch['chapter_number'],
            $summaryData['new_foreshadowing'] ?? [],
            $summaryData['resolved_foreshadowing'] ?? []
        );
    }
}

// ---- 检查是否全部完成 ----
$pendingCount = DB::count('chapters', 'novel_id=? AND status != "completed"', [$novelId]);
if ($pendingCount === 0) {
    DB::update('novels', ['status' => 'completed'], 'id=?', [$novelId]);
}

// ---- 全部就绪后再发完成信号，前端收到时状态已完整入库 ----
sseMsgWrite([
    'stats'      => "第{$ch['chapter_number']}章《{$ch['title']}》完成，共 {$words} 字{$modelInfo}",
    'chapter_id' => $ch['id'],
    'words'      => $words,
    'done'       => $pendingCount === 0,
    'model_used' => $usedModel?->modelLabel,
]);
sseDoneWrite();
