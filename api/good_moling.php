<?php
define('APP_LOADED', true);

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/data.php';
require_once dirname(__DIR__) . '/includes/GoodMolingPromptBuilder.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
if (in_array($action, ['chat', 'clear'], true)) {
    requireHttpMethod('POST');
}

if ($method === 'POST' && in_array($action, ['chat', 'clear'])) {
    requireLoginApi(true);
} else {
    requireLoginApi(false);
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($userId <= 0) jsonResponse(false, null, '未登录');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($action) {
        case 'list_models':
            handleListModels();
            break;
        case 'chat':
            handleChat($userId);
            break;
        case 'clear':
            handleClear($userId);
            break;
        default:
            jsonResponse(false, null, '未知操作: ' . $action);
    }
} catch (Throwable $e) {
    error_log('good_moling.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $safeMsg = in_array($e->getMessage(), [
        '章节数据未找到', '请提供消息内容', 'AI 返回内容为空', '未知操作', '消息为空'
    ], true) ? $e->getMessage() : '操作失败，请稍后重试';
    jsonResponse(false, null, $safeMsg);
}

function handleListModels(): void
{
    $rows = DB::fetchAll(
        "SELECT id, name, model_name, channel, token_multiplier, is_default
         FROM ai_models
         ORDER BY is_default DESC, id ASC"
    );
    $models = [];
    foreach ($rows as $r) {
        $models[] = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'model_name' => $r['model_name'] ?? '',
            'channel'    => $r['channel'] ?? 'admin',
            'multiplier' => (float)($r['token_multiplier'] ?? 1.0),
            'is_default' => (int)($r['is_default'] ?? 0),
        ];
    }
    jsonResponse(true, ['models' => $models]);
}

function loadChapterOrFail(int $chapterId, int $userId): array
{
    if ($chapterId <= 0) jsonResponse(false, null, '缺少章节ID');

    $chapter = getChapter($chapterId);
    if (!$chapter) jsonResponse(false, null, '章节不存在');

    $novel = getNovel((int)$chapter['novel_id']);
    if (!$novel) jsonResponse(false, null, '小说不存在');

    return ['chapter' => $chapter, 'novel' => $novel];
}

function handleClear(int $userId): void
{
    global $input;
    $chapterId = (int)($input['chapter_id'] ?? 0);
    if ($chapterId > 0) {
        loadChapterOrFail($chapterId, $userId);
    }

    $historyFile = dirname(__DIR__) . '/storage/good_moling';
    if (!is_dir($historyFile)) @mkdir($historyFile, 0755, true);
    $file = $historyFile . '/' . $userId . '_' . ($chapterId > 0 ? $chapterId : 'general') . '.json';
    if (file_exists($file)) {
        file_put_contents($file, '[]', LOCK_EX);
    }

    jsonResponse(true, null);
}

function getHistory(int $userId, int $chapterId = 0): array
{
    $historyFile = dirname(__DIR__) . '/storage/good_moling';
    if (!is_dir($historyFile)) @mkdir($historyFile, 0755, true);
    $file = $historyFile . '/' . $userId . '_' . ($chapterId > 0 ? $chapterId : 'general') . '.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    return [];
}

function saveHistory(int $userId, int $chapterId, array $history): void
{
    $historyFile = dirname(__DIR__) . '/storage/good_moling';
    if (!is_dir($historyFile)) @mkdir($historyFile, 0755, true);
    $file = $historyFile . '/' . $userId . '_' . ($chapterId > 0 ? $chapterId : 'general') . '.json';

    if (count($history) > 30) {
        $history = array_slice($history, -30);
    }

    file_put_contents($file, json_encode($history, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function handleChat(int $userId): void
{
    global $input;

    $chapterId  = (int)($input['chapter_id'] ?? 0);
    $message    = trim((string)($input['message'] ?? ''));
    $modelId    = (int)($input['model_id'] ?? 0) ?: null;
    $actionType = (string)($input['action_type'] ?? 'general_chat');
    $selection  = trim((string)($input['selection'] ?? ''));

    if ($message === '' && $actionType === 'general_chat') {
        jsonResponse(false, null, '消息为空');
    }

    $chapter = null;
    $novel = null;
    $genre = '';

    if ($chapterId > 0) {
        $loaded = loadChapterOrFail($chapterId, $userId);
        $chapter = $loaded['chapter'];
        $novel = $loaded['novel'];
        $genre = $novel['genre'] ?? '';
    }

    $ctx = $input['context'] ?? ['content' => true, 'outline' => true];
    if (!is_array($ctx)) $ctx = ['content' => true, 'outline' => true];

    $systemPrompt = GoodMolingPromptBuilder::buildSystemPrompt($actionType, $genre);

    $contextText = '';
    if ($chapter && $novel) {
        $contextText = GoodMolingPromptBuilder::buildContextSnapshot($chapter, $novel, $ctx, $selection);
    }

    $history = getHistory($userId, $chapterId);

    $userTurn = $message !== '' ? $message : GoodMolingPromptBuilder::presetUserPrompt($actionType, $selection);
    if ($userTurn === '') $userTurn = '请帮助我。';

    $messages = GoodMolingPromptBuilder::buildMessages($systemPrompt, $contextText, $history, $userTurn);

    $history[] = ['role' => 'user', 'content' => $userTurn];
    saveHistory($userId, $chapterId, $history);

    set_time_limit(600);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) ob_end_flush();
    ob_implicit_flush(true);

    try {
        $ai = getAIClient($modelId);
    } catch (Throwable $e) {
        error_log('good_moling.php 模式预检: ' . $e->getMessage());
        echo "data: " . json_encode(safe_sse_error_payload($e, '模型不可用，请稍后重试'), JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        exit;
    }

    $accumulated = '';
    $lastHeartbeat = time();

    try {
        $ai->chatStream($messages, function ($chunk) use (&$accumulated, &$lastHeartbeat) {
            if ($chunk === '' || $chunk === '[DONE]') return;
            $accumulated .= $chunk;

            $now = time();
            if ($now - $lastHeartbeat >= 8) {
                echo ": heartbeat\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
                $lastHeartbeat = $now;
            }

            echo "data: " . json_encode(['text' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 'creative');
    } catch (Throwable $e) {
        error_log('good_moling stream failed: ' . $e->getMessage());
        echo "data: " . json_encode(safe_sse_error_payload($e, 'AI 响应失败，请稍后重试'), JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    $accumulated = trim($accumulated);

    if ($accumulated !== '') {
        $history[] = ['role' => 'assistant', 'content' => $accumulated];
        saveHistory($userId, $chapterId, $history);
    }

    $hasPatch = str_contains($accumulated, '<<<REWRITE>>>');

    echo "data: " . json_encode([
        'done'      => true,
        'has_patch' => $hasPatch,
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
    exit;
}
