<?php
/**
 * AI压缩章节 API（流式 SSE）
 * POST JSON: { chapter_id, target_words }
 * 
 * 将章节内容压缩到目标字数，保持核心情节和人物关系不变。
 * 压缩前自动备份到 chapter_versions。
 */

// 强制禁用输出缓冲
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
session_write_close();

ob_end_clean();
set_time_limit(CFG_TIME_MEDIUM);

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

$ch = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
if (!$ch) { sse('message', ['error' => '章节不存在']); sseDone(); exit; }

if (empty($ch['content'])) {
    sse('message', ['error' => '章节内容为空，无法压缩']); sseDone(); exit;
}

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$ch['novel_id']]);
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
    DB::insert('chapter_versions', [
        'chapter_id' => $chapterId,
        'version'    => $maxVer + 1,
        'content'    => $oldContent,
        'outline'    => $ch['outline'] ?? '',
        'title'      => $ch['title']   ?? '',
        'words'      => $oldWords,
    ]);
    sse('message', ['version_saved' => true, 'version' => $maxVer + 1, 'words' => $oldWords]);

    // 保留最近版本
    DB::execute(
        'DELETE FROM chapter_versions WHERE chapter_id=? AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM chapter_versions WHERE chapter_id=? ORDER BY version DESC LIMIT ' . CFG_VERSIONS_KEEP . '
            ) t
        )',
        [$chapterId, $chapterId]
    );
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
$fullContent = '';
$usedModel   = null;
$novelId     = (int)$novel['id'];

try {
    withModelFallback($novel['model_id'] ?? null, function($ai) use ($compressSystem, $compressUser, &$fullContent, &$usedModel) {
        $usedModel = $ai->modelLabel;
        $maxTok = $ai->getMaxTokens();
        $ai->setMaxTokens(max($maxTok, 8192));

        // chatStream 边收边发，每收到一个 chunk 都实时推送
        $ai->chatStream(
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
    }, function($nextAi, $errMsg) {
        sse('message', ['model_switch' => true, 'to' => $nextAi->modelLabel, 'reason' => $errMsg]);
    });
} catch (Throwable $e) {
    error_log('compress_chapter.php 异常: ' . $e->getMessage());
    sse('message', ['error' => '压缩失败：' . $e->getMessage()]);
    sseDone();
    exit;
}

// ---- 保存压缩结果 ----
if (trim($fullContent) === '') {
    sse('message', ['error' => '压缩失败：LLM 未返回有效内容。']);
    sseDone();
    exit;
}

$words = countWords($fullContent);
DB::update('chapters', [
    'content' => $fullContent,
    'words'   => $words,
], 'id=?', [$chapterId]);
updateNovelStats($novelId);

sse('message', [
    'content' => $fullContent,
    'stats' => "压缩完成：{$currentWords} → {$words} 字（减少 " . ($currentWords - $words) . " 字），模型：{$usedModel}",
]);
sseDone();
