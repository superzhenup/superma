<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 优化章节概要 API
 * POST JSON: { novel_id, chapter_number, suggestions, preview_only? }
 * 根据用户提供的优化意见重新生成章节概要
 */
ob_start();
ini_set('display_errors', '0');

defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ChapterSynopsisPersistence.php';
requireLoginApi();
session_write_close();

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// ---- 解析入参 ----
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);
$chapterNumber = (int)($input['chapter_number'] ?? 0);
$suggestions = trim($input['suggestions'] ?? '');
$previewOnly = !empty($input['preview_only']);

// 审计 P0（2026-06-12）：归属校验
$userId = currentUserId();
if ($novelId > 0) checkNovelOwnership($novelId, $userId);

if (!$novelId || !$chapterNumber || !$suggestions) {
    echo json_encode(['success' => false, 'error' => '参数不完整']);
    exit;
}

// Snapshot and prompt source are captured together before the model starts.
$persistence = new ChapterSynopsisPersistence(DB::connect(), $userId, $novelId);
try {
    $snapshot = $persistence->capture($chapterNumber, true, false);
    $novel = $snapshot['novel'];
    $chapter = $snapshot['chapter'];
    $currentSynopsis = $snapshot['synopsis'];
    $storyOutline = $snapshot['story'];
    $sourceRevision = $persistence->sourceRevision($chapterNumber);
    if ($currentSynopsis === null) throw new ChapterSynopsisSourceChangedException('Synopsis missing.');
} catch (ChapterSynopsisSourceChangedException $error) {
    echo json_encode(['success'=>false, 'code'=>'source_conflict', 'error'=>'章节概要来源已变化或不存在，请刷新后重试']);
    exit;
}

// 预检：至少要有一个模型
try {
    $modelList = getModelFallbackList($novel['model_id'] ?: null, 'synopsis');
} catch (RuntimeException $e) {
    // 审计修复 P2-3（2026-07-01）：不向客户端透传模型配置异常细节
    $rid = error_trace_id();
    error_log(sprintf('[%s] optimize_chapter_synopsis: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(['success' => false, 'error' => '模型配置异常，请检查模型设置', 'request_id' => $rid]);
    exit;
}

// ---- 构建优化提示词 ----
$messages = buildOptimizeSynopsisPrompt($novel, $chapter, $currentSynopsis, $storyOutline, $suggestions);

// ---- 调用AI生成优化结果 ----
try {
    $rawResponse = '';
    $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    
    withModelFallback(
        $novel['model_id'] ?: null,
        function (AIClient $ai) use ($messages, &$rawResponse, &$usage) {
            $rawResponse = '';
            $usage = $ai->chatStream($messages, function (string $token) use (&$rawResponse) {
                if ($token === '[DONE]') return;
                $rawResponse .= $token;
            });
        }
    );
    
    $result = ChapterSynopsisOutput::parseOptimization($rawResponse, $chapterNumber);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'AI返回格式错误，请重试']);
        exit;
    }

    $updateData = $result;
    foreach (['scene_breakdown', 'dialogue_beats', 'sensory_details', 'foreshadowing', 'callbacks'] as $field) {
        if (array_key_exists($field, $result)) {
            $updateData[$field] = $result[$field] === [] ? null : json_encode($result[$field], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    }

    // Both direct publication and later preview confirmation compare this exact source.
    if (!$previewOnly && !$persistence->saveGenerated($chapterNumber, $updateData)) {
        echo json_encode(['success'=>false, 'code'=>'source_conflict', 'error'=>'大纲或概要已被修改，已保留当前版本，请刷新后重试']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $result,
        'usage' => $usage,
        'persisted' => !$previewOnly,
        'source_revision' => $sourceRevision
    ]);
    
} catch (Exception $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] optimize_chapter_synopsis 优化失败: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(['success' => false, 'error' => '生成失败，请稍后重试', 'request_id' => $rid]);
    exit;
}

/**
 * 构建优化章节概要的提示词
 */
function buildOptimizeSynopsisPrompt(array $novel, array $chapter, array $currentSynopsis, ?array $storyOutline, string $suggestions): array {
    $prompt = "你是一位专业的小说策划编辑。现在需要根据用户的优化意见，重新优化章节概要。\n\n";

    // 小说基本信息
    $prompt .= "【小说基本信息】\n";
    $prompt .= "标题：{$novel['title']}\n";
    $prompt .= "类型：{$novel['genre']}\n";
    $prompt .= '风格：' . ($novel['writing_style'] ?? $novel['style'] ?? '') . "\n";
    if ($novel['protagonist_info']) {
        $prompt .= "主角信息：{$novel['protagonist_info']}\n";
    }
    $prompt .= "\n";

    // 章节信息
    $prompt .= "【当前章节信息】\n";
    $prompt .= "第{$chapter['chapter_number']}章：{$chapter['title']}\n";
    if ($chapter['outline']) {
        $prompt .= "章节大纲：{$chapter['outline']}\n";
    }
    $prompt .= "\n";

    // 当前章节概要
    $prompt .= "【当前章节概要】\n";
    $prompt .= $currentSynopsis['synopsis'] . "\n\n";
    
    // 全书故事大纲（如果有）
    if ($storyOutline) {
        $prompt .= "【全书故事大纲】\n";
        if ($storyOutline['story_arc']) {
            $prompt .= "故事主线：{$storyOutline['story_arc']}\n";
        }
        if ($storyOutline['character_arcs']) {
            $characterArcs = json_decode($storyOutline['character_arcs'], true);
            if ($characterArcs) {
                $prompt .= "人物成长轨迹：" . implode('；', $characterArcs) . "\n";
            }
        }
        $prompt .= "\n";
    }
    
    // 用户优化意见
    $prompt .= "【用户优化意见】\n";
    $prompt .= $suggestions . "\n\n";
    
    // 输出要求
    $prompt .= "【输出要求】\n";
    $prompt .= "请根据用户的优化意见，重新生成章节概要。输出格式必须为JSON：\n";
    $prompt .= "{\n";
    $prompt .= "  \"synopsis\": \"章节概要（200-300字，描述本章的主要内容、场景、情节发展）\",\n";
    $prompt .= "  \"pacing\": \"节奏（快/中/慢）\",\n";
    $prompt .= "  \"cliffhanger\": \"结尾悬念（可选）\"\n";
    $prompt .= "}\n\n";

    $prompt .= "注意事项：\n";
    $prompt .= "1. 章节概要要具体、可执行，避免空泛的描述\n";
    $prompt .= "2. 要包含具体的场景设定、人物互动、情节转折\n";
    $prompt .= "3. 要与全书故事大纲保持一致\n";
    $prompt .= "4. 要体现用户的优化意见\n";
    $prompt .= "5. 只输出JSON，不要有任何其他文字\n";

    return [
        ['role' => 'user', 'content' => $prompt]
    ];
}
