<?php
/**
 * 一键润色 API（流式 SSE + 质量检测反馈驱动）
 * POST JSON: { chapter_id, quality_feedback? }
 * 
 * - 如传入 quality_feedback，基于质量检测的⚠️问题定向润色
 * - 如未传入，使用通用润色规则（不含定向改进）
 * - 润色前自动备份到 chapter_versions
 *
 * 前端调用流程：
 * 1. 先调 validate_consistency.php 运行质量检测
 * 2. 提取⚠️/❌问题拼成 quality_feedback 字符串
 * 3. 调用本接口进行流式润色
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

// ---- SSE 辅助函数 ----
$lastHeartbeat = time();

function sendHeartbeatPolish(): void {
    global $lastHeartbeat;
    $now = time();
    if ($now - $lastHeartbeat >= 10) {
        echo "event: heartbeat\n";
        echo "data: " . json_encode(['time' => $now, 'msg' => 'keep-alive']) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        $lastHeartbeat = $now;
    }
}

function sseChunkPolish(string $chunk): void {
    sendHeartbeatPolish();
    echo 'data: ' . json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function sseMsgPolish(array $payload): void {
    sendHeartbeatPolish();
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function sseDonePolish(): void {
    echo "data: [DONE]\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ---- 解析入参 ----
$input            = json_decode(file_get_contents('php://input'), true) ?? [];
$chapterId        = (int)($input['chapter_id'] ?? 0);
$qualityFeedback  = trim($input['quality_feedback'] ?? '');

$ch = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
if (!$ch) { sseMsgPolish(['error' => '章节不存在']); sseDonePolish(); exit; }

if (empty($ch['content'])) {
    sseMsgPolish(['error' => '章节内容为空，无法润色']); sseDonePolish(); exit;
}

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$ch['novel_id']]);
if (!$novel) { sseMsgPolish(['error' => '小说不存在']); sseDonePolish(); exit; }

// ---- 备份原内容到版本历史 ----
$oldContent = $ch['content'] ?? '';
$oldWords   = (int)($ch['words'] ?? 0);
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
    sseMsgPolish(['version_saved' => true, 'version' => $maxVer + 1, 'words' => $oldWords]);

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

// ---- 构建润色 Prompt（含质量检测反馈） ----
$polishSystem = '你是一位专业的网络小说润色编辑，擅长在保持原文风格和情节的基础上优化文字表达。';

// 基础润色要求
$baseRules = <<<RULES
1. **文风统一**：保持原文的叙事视角、语气和文风，不改变作者风格
2. **描写增强**：适度增加感官描写（视觉、听觉、触觉等），让场景更立体，但不要堆砌华丽辞藻
3. **对话优化**：让对话更自然、更有个性，去除生硬感，适当添加动作/神态/语气描写
4. **节奏优化**：长段拆分，短句点缀，增强阅读节奏感
5. **去AI痕迹**：去除典型的AI写作痕迹（如"不禁""缓缓""微微"等重复用词，过度排比，空洞的感叹）
6. **保持情节**：绝对不改变任何情节、人物关系和事件走向，只优化表达方式
7. **字数控制**：润色后字数与原文相近，增减不超过10%
RULES;

// 如果有质量检测反馈，增加定向改进指令
$feedbackSection = '';
if (!empty($qualityFeedback)) {
    $feedbackSection = <<<FEEDBACK

【🎯 质量检测反馈——重点改进项】
以下是自动质量检测发现的问题，请在润色时**优先针对这些问题进行改进**：

{$qualityFeedback}

⚠️ 上述反馈中标注⚠️和❌的项目是必须改进的，请在润色时着重处理。如果没有对应问题（如"角色未出场"），则在描写中增加相关角色的提及或互动。
FEEDBACK;
}

$polishUser = <<<PROMPT
请对以下章节正文进行润色优化，要求：

{$baseRules}
{$feedbackSection}

【小说风格】{$novel['writing_style']}
【第{$ch['chapter_number']}章】{$ch['title']}

以下是原文：
---
{$ch['content']}
---

请直接输出润色后的完整正文，从"第{$ch['chapter_number']}章 {$ch['title']}"这一行开始，不要有任何前言、后记、解释或"好的，我来润色"等废话。保留原文的章节标题行。
PROMPT;

sseMsgPolish(['status' => 'polishing', 'has_feedback' => !empty($qualityFeedback)]);

// ---- 流式润色 ----
$fullContent  = '';
$usedModel    = null;
$novelId      = (int)$novel['id'];

try {
    withModelFallback($novel['model_id'] ?? null, function($ai) use ($polishSystem, $polishUser, &$fullContent, &$usedModel) {
        $usedModel = $ai->modelLabel;
        // 按原文字数动态估算 max_tokens：润色后字数与原文相近（±10%），
        // 取原文字数×1.1（上浮空间）×2.1 tokens/字 + 400 缓冲
        $polishMaxTokens = (int)(($oldWords > 100 ? $oldWords : 3000) * 1.1 * 2.1) + 400;
        $ai->setMaxTokens(max($ai->getMaxTokens(), $polishMaxTokens));

        $ai->chatStream(
            [
                ['role' => 'system', 'content' => $polishSystem],
                ['role' => 'user',   'content' => $polishUser],
            ],
            function(string $chunk) use (&$fullContent) {
                $fullContent .= $chunk;
                sseChunkPolish($chunk);
            },
            'creative'
        );

        return $fullContent;
    }, function($nextAi, $errMsg) {
        sseMsgPolish(['model_switch' => true, 'to' => $nextAi->modelLabel, 'reason' => $errMsg]);
    });
} catch (Throwable $e) {
    sseMsgPolish(['error' => '润色失败：' . $e->getMessage()]);
    sseDonePolish();
    exit;
}

// ---- 保存润色结果 ----
$words = countWords($fullContent);
DB::update('chapters', [
    'content' => $fullContent,
    'words'   => $words,
], 'id=?', [$chapterId]);
updateNovelStats($novelId);

sseMsgPolish(['stats' => "润色完成，共 {$words} 字，模型：{$usedModel}"]);
sseDonePolish();
