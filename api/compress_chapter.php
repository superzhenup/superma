<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * AI压缩章节 API（流式 SSE）
 * POST JSON: { chapter_id, target_words }
 * 
 * 将章节内容压缩到目标字数，保持核心情节和人物关系不变。
 * 压缩前自动备份到 chapter_versions。
 */

// 强制禁用输出缓冲
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
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';
require_once dirname(__DIR__) . '/includes/ChapterMemoryFinalizer.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
session_write_close();

ob_end_clean();
set_time_limit(CFG_TIME_MEDIUM);

// 审计修复（2026-07-19 H-中11）：SSE 端点必须 ignore_user_abort
ignore_user_abort(true);

while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// SSE 辅助函数（helpers.php 有 sse() 和 sseDone()，这里补充 sseChunk）
function sseChunk(string $chunk): void {
    echo 'data: ' . json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ---- 解析入参 ----
$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$chapterId   = (int)($input['chapter_id'] ?? 0);
$targetWords = (int)($input['target_words'] ?? 2000);

// 审计 P0（2026-06-12）：章节归属校验
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($chapterId > 0) checkChapterOwnership($chapterId, $userId);

$ch = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
if (!$ch) { sse('message', ['error' => '章节不存在']); sseDone(); exit; }

if (empty($ch['content'])) {
    sse('message', ['error' => '章节内容为空，无法压缩']); sseDone(); exit;
}

$novel = DB::fetch('SELECT id, chapter_words, writing_style, model_id FROM novels WHERE id=?', [$ch['novel_id']]);
if (!$novel) { sse('message', ['error' => '小说不存在']); sseDone(); exit; }

// 如果未传 target_words，使用小说设定值
if ($targetWords <= 0) {
    $targetWords = (int)($novel['chapter_words'] ?? 2000);
}

$currentWords = (int)($ch['words'] ?? countWords($ch['content']));

// 如果当前字数没有明显超标，提示用户
if ($currentWords <= $targetWords + 150) {
    sse('message', ['error' => "当前字数 {$currentWords} 字，与目标 {$targetWords} 字相差不大（≤150字），无需压缩"]); sseDone(); exit;
}

// ---- 备份原内容到版本历史 ----
$oldContent = $ch['content'] ?? '';
$oldWords   = $currentWords;
if (!empty($oldContent) && $oldWords > 100) {
    $maxVer = (int)(DB::fetch(
        'SELECT COALESCE(MAX(version), 0) AS v FROM chapter_versions WHERE chapter_id=?',
        [$chapterId]
    )['v'] ?? 0);
    $newVer = $maxVer + 1;
    DB::insert('chapter_versions', [
        'chapter_id' => $chapterId,
        'version'    => $newVer,
        'content'    => $oldContent,
        'outline'    => $ch['outline'] ?? '',
        'title'      => $ch['title']   ?? '',
        'words'      => $oldWords,
    ]);
    sse('message', ['version_saved' => true, 'version' => $newVer, 'words' => $oldWords]);

    // 保留最近 CFG_VERSIONS_KEEP 个版本（审计修复 PERF-H1：用 version 范围删除替代 NOT IN 子查询）
    if ($newVer > CFG_VERSIONS_KEEP) {
        DB::execute(
            'DELETE FROM chapter_versions WHERE chapter_id=? AND version <= ?',
            [$chapterId, $newVer - CFG_VERSIONS_KEEP]
        );
    }
}

// ---- 构建压缩 Prompt ----
$compressSystem = '你是一位专业的小说编辑，擅长在不损失核心情节和人物关系的前提下精简压缩文章内容。';

$compressUser = <<<PROMPT
请将以下章节内容压缩到约 {$targetWords} 字（允许 ±150 字的浮动，绝不可超过 {$targetWords} + 150 字）。

压缩原则：
1. **保留核心情节**：所有关键事件、转折点和重要对话必须保留
2. **保留人物关系**：人物互动、性格表现和关系推进不能丢失
3. **精简冗余**：删减重复描写、过长的环境渲染、不影响剧情的过渡段落
4. **保留文风**：保持原文的叙事风格和语气，不要改变表达方式
5. **保留章节标题**：从"第{$ch['chapter_number']}章 {$ch['title']}"这一行开始
6. **去除AIA味 尽量少使用“*”、“——”等AI特征标点符号

【小说风格】{$novel['writing_style']}
【当前字数】{$currentWords} 字
【目标字数】{$targetWords} 字

以下是原文：
---
{$ch['content']}
---

请直接输出压缩后的完整正文，不要有任何前言、后记、解释或"好的，我来压缩"等废话。
PROMPT;

sse('message', ['status' => 'compressing', 'from_words' => $currentWords, 'target_words' => $targetWords]);

// ---- 流式压缩（边收边发，用户实时看到内容）----
$fullContent  = '';
$usedModel    = null;
$novelId      = (int)$novel['id'];
$modelTried   = [];
$lastModelErr = '';

try {
    withModelFallback($novel['model_id'] ?? null, function($ai) use ($compressSystem, $compressUser, &$fullContent, &$usedModel, &$modelTried) {
        // 回退模型必须从空缓冲重新开始，避免多个模型的部分输出被串接保存。
        $fullContent = '';
        $usedModel = $ai->modelLabel;
        $modelTried[] = $usedModel;
        $maxTok = $ai->getMaxTokens();
        $ai->setMaxTokens(max($maxTok, 8192));

        // chatStream 边收边发，每收到一个 chunk 都实时推送
        $usage = $ai->chatStream(
            [
                ['role' => 'system', 'content' => $compressSystem],
                ['role' => 'user',   'content' => $compressUser],
            ],
            function(string $chunk) use (&$fullContent) {
                $fullContent .= $chunk;
                sseChunk($chunk);
            },
            'creative'
        );

        // 如果 chatStream 返回了但 content 为空，打印 usage 信息用于调试
        if (trim($fullContent) === '' && !empty($usage)) {
            error_log("compress_chapter.php: 模型 {$usedModel} 返回了 usage 但 content 为空. tokens: " . json_encode($usage));
        }
    }, function($nextAi, $errMsg) use (&$modelTried, &$lastModelErr) {
        $lastModelErr = $errMsg;
        $modelTried[] = '(尝试切换: ' . $nextAi->modelLabel . ')';
        sse('message', ['model_switch' => true, 'to' => $nextAi->modelLabel, 'reason' => $errMsg]);
    });
} catch (Throwable $e) {
    error_log('compress_chapter.php 异常: ' . $e->getMessage() . ' | 已尝试模型: ' . implode(', ', $modelTried));
    sse('message', safe_sse_error_payload($e, '压缩服务暂时不可用，请稍后重试'));
    sseDone();
    exit;
}

// ---- 保存压缩结果 ----
if (trim($fullContent) === '') {
    $detail = $modelTried ? '（已尝试模型: ' . implode(', ', $modelTried) . '）' : '';
    $extra  = $lastModelErr ? ' 最后错误: ' . $lastModelErr : '';
    sse('message', ['error' => '压缩失败：所有模型均未返回有效内容。' . $detail . $extra . ' 请检查模型 API 配置或尝试切换模型。']);
    sseDone();
    exit;
}

$words = countWords($fullContent);
$minCompressedWords = min(max(1, $targetWords), max(100, (int)ceil(max(1, $targetWords) * 0.50)));
if ($words < $minCompressedWords) {
    sse('message', ['error' => "压缩结果疑似截断（{$words}字 < 安全下限{$minCompressedWords}字），原文未被覆盖"]);
    sseDone();
    exit;
}
try {
    ChapterMutationService::mutateChapter($chapterId, $novelId, [
        'content' => $fullContent,
        'words'   => $words,
    ], [
        // 本接口上方已备份并向 SSE 返回版本号，禁止服务再次重复备份。
        'backup_version' => false,
        'prevent_writing' => true,
        'expected_content_hash' => hash('sha256', (string)$oldContent),
        'reason' => 'compress_chapter',
    ]);
} catch (ChapterMutationConflict $e) {
    sse('message', ['error' => '章节状态已变更（可能正在写作中），压缩结果未保存']);
    sseDone();
    exit;
} catch (Throwable $e) {
    error_log('compress_chapter save failed: ' . $e->getMessage());
    sse('message', ['error' => '压缩结果保存失败，请稍后重试']);
    sseDone();
    exit;
}
updateNovelStats($novelId);

// 只让压缩后的最终正文成为记忆来源；失败时旧记忆已由变更服务清除。
sse('message', ['status' => 'refreshing_memory']);
try {
    $memoryResult = ChapterMemoryFinalizer::finalize($novelId, $chapterId, $fullContent);
    if (empty($memoryResult['ok'])) {
        $reason = !empty($memoryResult['historical_deferred'])
            ? '这是历史章节，累计记忆需按章节顺序重放'
            : (!empty($memoryResult['stale']) ? '正文已再次变化' : '记忆服务未完成');
        sse('message', ['warning' => "压缩已保存，但记忆刷新跳过：{$reason}"]);
    }
} catch (Throwable $e) {
    error_log('compress_chapter memory refresh failed: ' . $e->getMessage());
    sse('message', ['warning' => '压缩已保存，但记忆刷新失败；旧记忆已失效，不会污染后续章节']);
}

sse('message', [
    'content' => $fullContent,
    'stats' => "压缩完成：{$currentWords} → {$words} 字（减少 " . ($currentWords - $words) . " 字），模型：{$usedModel}",
]);
sseDone();
