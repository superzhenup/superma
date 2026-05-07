<?php
/**
 * 选中文字优化 API
 *
 * 用户从编辑器中选中一段文字，可选地输入优化指令，
 * 由 AI 对该段落进行优化重写后返回。
 *
 * POST JSON: { chapter_id, selected_text, instruction?, context_before?, context_after? }
 * 返回 JSON: { success, optimized_text, model }
 */

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$chapterId = (int)($input['chapter_id'] ?? 0);
$selectedText = trim($input['selected_text'] ?? '');
$instruction = trim($input['instruction'] ?? '');
$contextBefore = trim($input['context_before'] ?? '');
$contextAfter = trim($input['context_after'] ?? '');

if (empty($selectedText)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '未选中任何文字']);
    exit;
}

if (mb_strlen($selectedText) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '选中的文字太短（至少10个字），建议手动修改']);
    exit;
}

if (mb_strlen($selectedText) > 5000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '选中的文字太长（最多5000字），请缩小选择范围']);
    exit;
}

$ch = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
if (!$ch) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => '章节不存在']);
    exit;
}

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$ch['novel_id']]);
if (!$novel) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => '小说不存在']);
    exit;
}

// ---- 构建优化 Prompt ----
$instructionSection = '';
if (!empty($instruction)) {
    $instructionSection = <<<INST
【用户指令】
{$instruction}

请严格按照以上指令优化。注意只优化选中的部分，不要重写整个章节。
INST;
} else {
    $instructionSection = <<<INST
【默认优化要求】
对选中的段落进行文笔润色和表达优化：
1. 让表达更流畅自然，提升文字质量
2. 适度增加感官描写和细节，但不要过度
3. 去除AI写作痕迹（重复用词、空洞感叹、过度排比等）
4. 保持原文的情节、信息和情感基调不变
5. 优化后字数与原文字数基本一致（±15%）
INST;
}

$systemPrompt = '你是一位专业的网络小说编辑，擅长在保持原文风格和情节的基础上优化段落文字表达。你只输出优化后的段落，不添加任何解释、前言或后记。';

$userPrompt = <<<PROMPT
【小说风格】{$novel['writing_style']}
【章节】第{$ch['chapter_number']}章 {$ch['title']}

{$instructionSection}

【上文】（仅供参考，不要修改上文）
{$contextBefore}

【需要优化选中的段落】
{$selectedText}

【下文】（仅供参考，不要修改下文）
{$contextAfter}

请直接输出优化后的段落文字，不要包含任何解释、"优化后："等前缀或后缀。
PROMPT;

// ---- 调用 AI ----
try {
    $modelId = $novel['model_id'] ?? null;
    $ai = getAIClient($modelId);

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];

    $result = $ai->chat($messages, 'creative');

    $optimizedText = trim($result ?? '');

    if (empty($optimizedText)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'AI 未返回有效内容，请重试']);
        exit;
    }

    $optimizedText = preg_replace('/^(优化后[：:]?\s*|润色后[：:]?\s*|改写后[：:]?\s*)/iu', '', $optimizedText);

    echo json_encode([
        'success' => true,
        'optimized_text' => $optimizedText,
        'original_length' => mb_strlen($selectedText),
        'optimized_length' => mb_strlen($optimizedText),
        'model' => $ai->modelLabel ?? 'unknown',
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('polish_selection 失败：' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'AI 调用失败：' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
