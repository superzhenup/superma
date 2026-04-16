<?php
/**
 * 优化章节概要 API
 * POST JSON: { novel_id, chapter_number, suggestions }
 * 根据用户提供的优化意见重新生成章节概要
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

header('Content-Type: application/json; charset=utf-8');

// ---- 解析入参 ----
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);
$chapterNumber = (int)($input['chapter_number'] ?? 0);
$suggestions = trim($input['suggestions'] ?? '');

if (!$novelId || !$chapterNumber || !$suggestions) {
    echo json_encode(['success' => false, 'error' => '参数不完整']);
    exit;
}

// 获取小说信息
$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) {
    echo json_encode(['success' => false, 'error' => '小说不存在']);
    exit;
}

// 获取章节信息
$chapter = DB::fetch('SELECT * FROM chapters WHERE novel_id=? AND chapter_number=?', [$novelId, $chapterNumber]);
if (!$chapter) {
    echo json_encode(['success' => false, 'error' => '章节不存在']);
    exit;
}

// 获取当前章节概要
$currentSynopsis = DB::fetch('SELECT * FROM chapter_synopses WHERE novel_id=? AND chapter_number=?', [$novelId, $chapterNumber]);
if (!$currentSynopsis) {
    echo json_encode(['success' => false, 'error' => '章节概要不存在，请先生成概要']);
    exit;
}

// 获取全书故事大纲
$storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id=?', [$novelId]);

// 预检：至少要有一个模型
try {
    $modelList = getModelFallbackList($novel['model_id'] ?: null);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
    
    // 解析AI返回的JSON
    $result = parseSynopsisResponse($rawResponse);
    
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'AI返回格式错误，请重试']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'usage' => $usage
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => '生成失败: ' . $e->getMessage()]);
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
    $prompt .= "风格：{$novel['style']}\n";
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

/**
 * 解析AI返回的章节概要JSON
 */
function parseSynopsisResponse(string $rawResponse): ?array {
    // 尝试提取JSON
    if (preg_match('/\{[\s\S]*\}/m', $rawResponse, $matches)) {
        $json = $matches[0];
        $data = json_decode($json, true);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($data['synopsis'])) {
            return [
                'synopsis' => $data['synopsis'],
                'pacing' => $data['pacing'] ?? '',
                'cliffhanger' => $data['cliffhanger'] ?? ''
            ];
        }
    }
    
    return null;
}
