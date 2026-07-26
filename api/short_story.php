<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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
require_once dirname(__DIR__) . '/includes/ShortStoryService.php';
require_once dirname(__DIR__) . '/includes/ShortStoryPromptBuilder.php';
require_once dirname(__DIR__) . '/includes/ShortStoryQualityGuard.php';

requireLoginApi(true);

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($userId <= 0) jsonResponse(false, null, '未登录');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

$service = new ShortStoryService();

switch ($action) {
    case 'list':
        $stories = $service->listStories($userId);
        jsonOut(['ok' => true, 'data' => $stories]);
        break;

    case 'get':
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        $story = getStoryOwned($service, $id, $userId);
        jsonOut(['ok' => true, 'data' => $story]);
        break;

    case 'create':
        $id = $service->createStory($input, $userId);
        $story = $service->getStory($id);
        jsonOut(['ok' => true, 'data' => $story]);
        break;

    case 'save_meta':
        $id = (int)($input['id'] ?? 0);
        ensureStoryOwned($service, $id, $userId);
        $result = $service->updateMeta($id, $input);
        jsonOut(normalizeResult($result));
        break;

    case 'generate_brief':
        $id = (int)($input['id'] ?? 0);
        $story = getStoryOwned($service, $id, $userId);

        try {
            $modelId = !empty($input['model_id']) ? (int)$input['model_id'] : ($story['model_id'] ?? null);
            $ai = getAIClient($modelId);
            $messages = ShortStoryPromptBuilder::buildBriefMessages($story);
            $raw = $ai->chat($messages, 'structured');
            $brief = extractJson($raw);
            if (!$brief) {
                // 审计修复 H-3（2026-06-17）：AI 原始响应不再回传客户端
                error_log('short_story brief parse failed: ' . mb_substr((string)$raw, 0, 200));
                jsonOut(['ok' => false, 'msg' => 'AI返回格式异常，请重试']);
            }

            $result = $service->saveBrief($id, $brief);
            jsonOut(normalizeResult($result));
        } catch (Throwable $e) {
            error_log('short_story catch: ' . $e->getMessage());
            jsonOut(safe_api_error_payload($e, '生成失败，请稍后重试'));
        }
        break;

    case 'generate_beats':
        $id = (int)($input['id'] ?? 0);
        $story = getStoryOwned($service, $id, $userId);
        if (empty($story['brief_json'])) jsonOut(['ok' => false, 'msg' => '请先生成故事概要']);

        try {
            $modelId = !empty($input['model_id']) ? (int)$input['model_id'] : ($story['model_id'] ?? null);
            $ai = getAIClient($modelId);
            $messages = ShortStoryPromptBuilder::buildBeatsMessages($story);
            $raw = $ai->chat($messages, 'structured');
            $beats = extractJson($raw);
            if (!$beats) {
                error_log('short_story beats parse failed: ' . mb_substr((string)$raw, 0, 200));
                jsonOut(['ok' => false, 'msg' => 'AI返回格式异常，请重试']);
            }

            $result = $service->saveBeats($id, $beats);
            jsonOut(normalizeResult($result));
        } catch (Throwable $e) {
            error_log('short_story catch: ' . $e->getMessage());
            jsonOut(safe_api_error_payload($e, '生成失败，请稍后重试'));
        }
        break;

    case 'save_brief':
        $id = (int)($input['id'] ?? 0);
        ensureStoryOwned($service, $id, $userId);
        $brief = $input['brief'] ?? [];
        if (empty($brief)) jsonOut(['ok' => false, 'msg' => '概要内容不能为空']);
        $result = $service->saveBrief($id, $brief);
        jsonOut(normalizeResult($result));
        break;

    case 'save_beats':
        $id = (int)($input['id'] ?? 0);
        ensureStoryOwned($service, $id, $userId);
        $beats = $input['beats'] ?? [];
        if (empty($beats)) jsonOut(['ok' => false, 'msg' => '节拍内容不能为空']);
        $result = $service->saveBeats($id, $beats);
        jsonOut(normalizeResult($result));
        break;

    case 'write_draft':
        $id = (int)($input['id'] ?? 0);
        $story = getStoryOwned($service, $id, $userId);

        set_time_limit(600);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) ob_end_flush();
        ob_implicit_flush(true);

        try {
            $modelId = !empty($input['model_id']) ? (int)$input['model_id'] : ($story['model_id'] ?? null);
            $ai = getAIClient($modelId);
            $messages = ShortStoryPromptBuilder::buildDraftMessages($story);
            $fullContent = '';
            $targetWords = (int)($story['target_words'] ?? 3000);
            $lastHeartbeat = time();

            $ai->chatStream($messages, function($chunk) use (&$fullContent, &$lastHeartbeat, $targetWords) {
                if ($chunk === '[DONE]') return;
                $fullContent .= $chunk;

                $now = time();
                if ($now - $lastHeartbeat >= 8) {
                    echo ": heartbeat\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                    $lastHeartbeat = $now;
                }

                $wordCount = mb_strlen(preg_replace('/\s+/', '', $fullContent));
                $progress = min(100, (int)($wordCount / $targetWords * 100));
                echo "data: " . json_encode([
                    'content'  => $chunk,
                    'wordCount' => $wordCount,
                    'progress' => $progress,
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 'creative');

            if (mb_strlen(trim($fullContent)) < 100) {
                echo "data: " . json_encode(['error' => '生成内容过短，请重试'], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
                exit;
            }

            $result = $service->saveGeneratedDraft($id, $fullContent);
            $saved = !empty($result['ok']);
            echo "data: " . json_encode([
                'done'     => true,
                'saved'    => $saved,
                'wordCount' => mb_strlen(preg_replace('/\s+/', '', $fullContent)),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();

        } catch (Throwable $e) {
            error_log('short_story stream 失败: ' . $e->getMessage());
            echo "data: " . json_encode(safe_sse_error_payload($e, '生成失败，请稍后重试'), JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
        exit;
        break;

    case 'generate_chapters':
        $id = (int)($input['id'] ?? 0);
        $story = getStoryOwned($service, $id, $userId);
        if (empty($story['beats_json'])) jsonOut(['ok' => false, 'msg' => '请先生成叙事节拍']);
        if ((int)($story['chapter_count'] ?? 1) < 2) jsonOut(['ok' => false, 'msg' => '当前为单篇模式,无需生成章节大纲']);

        try {
            $modelId = !empty($input['model_id']) ? (int)$input['model_id'] : ($story['model_id'] ?? null);
            $ai = getAIClient($modelId);
            $messages = ShortStoryPromptBuilder::buildChaptersMessages($story);
            $raw = $ai->chat($messages, 'structured');
            $chapters = extractJson($raw);
            if (!$chapters || !is_array($chapters)) {
                error_log('short_story chapters parse failed: ' . mb_substr((string)$raw, 0, 200));
                jsonOut(['ok' => false, 'msg' => 'AI返回格式异常,请重试']);
            }

            $result = $service->saveChapters($id, $chapters);
            jsonOut(normalizeResult($result));
        } catch (Throwable $e) {
            error_log('short_story 大纲生成（json）失败: ' . $e->getMessage());
            jsonOut(safe_api_error_payload($e, '生成失败，请稍后重试'));
        }
        break;

    case 'save_chapters':
        $id = (int)($input['id'] ?? 0);
        ensureStoryOwned($service, $id, $userId);
        $chapters = $input['chapters'] ?? [];
        if (!is_array($chapters) || empty($chapters)) jsonOut(['ok' => false, 'msg' => '章节内容不能为空']);
        $result = $service->saveChapters($id, $chapters);
        jsonOut(normalizeResult($result));
        break;

    case 'write_chapter':
        $id = (int)($input['id'] ?? 0);
        $chapterIndex = (int)($input['chapter_index'] ?? -1);
        $story = getStoryOwned($service, $id, $userId);
        $allChapters = $story['chapters_json'] ?? [];
        if (empty($allChapters) || !isset($allChapters[$chapterIndex])) {
            jsonOut(['ok' => false, 'msg' => '章节不存在,请先生成章节大纲']);
        }
        $chapter = $allChapters[$chapterIndex];

        set_time_limit(600);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) ob_end_flush();
        ob_implicit_flush(true);

        try {
            $modelId = !empty($input['model_id']) ? (int)$input['model_id'] : ($story['model_id'] ?? null);
            $ai = getAIClient($modelId);
            $prevTails = $service->getChapterContext($story, $chapterIndex);
            $messages = ShortStoryPromptBuilder::buildChapterDraftMessages($story, $chapter, $allChapters, $prevTails);

            $fullContent = '';
            $targetWords = max(500, (int)($chapter['word_budget'] ?? 3000));
            $lastHeartbeat = time();

            $ai->chatStream($messages, function($chunk) use (&$fullContent, &$lastHeartbeat, $targetWords) {
                if ($chunk === '[DONE]') return;
                $fullContent .= $chunk;

                $now = time();
                if ($now - $lastHeartbeat >= 8) {
                    echo ": heartbeat\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                    $lastHeartbeat = $now;
                }

                $wordCount = mb_strlen(preg_replace('/\s+/', '', $fullContent));
                $progress = min(100, (int)($wordCount / $targetWords * 100));
                echo "data: " . json_encode([
                    'content'   => $chunk,
                    'wordCount' => $wordCount,
                    'progress'  => $progress,
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 'creative');

            if (mb_strlen(trim($fullContent)) < 50) {
                echo "data: " . json_encode(['error' => '生成内容过短,请重试'], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
                exit;
            }

            $result = $service->updateChapterContent($id, $chapterIndex, $fullContent);
            $saved = !empty($result['ok']);
            echo "data: " . json_encode([
                'done'      => true,
                'saved'     => $saved,
                'wordCount' => mb_strlen(preg_replace('/\s+/', '', $fullContent)),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();

        } catch (Throwable $e) {
            error_log('short_story stream（json）失败: ' . $e->getMessage());
            echo "data: " . json_encode(safe_sse_error_payload($e, '生成失败，请稍后重试'), JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
        exit;
        break;

    case 'save_chapter_content':
        $id = (int)($input['id'] ?? 0);
        $chapterIndex = (int)($input['chapter_index'] ?? -1);
        $content = (string)($input['content'] ?? '');
        if ($chapterIndex < 0) jsonOut(['ok' => false, 'msg' => '缺少参数']);
        ensureStoryOwned($service, $id, $userId);
        $result = $service->updateChapterContent($id, $chapterIndex, $content);
        jsonOut(normalizeResult($result));
        break;

    case 'polish':
        $id = (int)($input['id'] ?? 0);
        $mode = $input['mode'] ?? 'full';
        $selection = $input['selection'] ?? '';
        $story = getStoryOwned($service, $id, $userId);
        if (empty($story['content'])) jsonOut(['ok' => false, 'msg' => '没有可润色的内容']);

        try {
            $modelId = !empty($input['model_id']) ? (int)$input['model_id'] : ($story['model_id'] ?? null);
            $ai = getAIClient($modelId);
            $messages = ShortStoryPromptBuilder::buildPolishMessages($story, $mode, $selection);
            $content = $ai->chat($messages, 'creative');

            $result = $service->saveContent($id, $content, 'AI润色（' . $mode . '）');
            jsonOut(normalizeResult($result));
        } catch (Throwable $e) {
            error_log('short_story 润色失败: ' . $e->getMessage());
            jsonOut(safe_api_error_payload($e, '润色失败，请稍后重试'));
        }
        break;

    case 'optimize_title':
        $id = (int)($input['id'] ?? 0);
        $story = getStoryOwned($service, $id, $userId);
        if (empty($story['content'])) jsonOut(['ok' => false, 'msg' => '没有内容无法生成标题']);

        try {
            $modelId = !empty($input['model_id']) ? (int)$input['model_id'] : ($story['model_id'] ?? null);
            $ai = getAIClient($modelId);
            $messages = ShortStoryPromptBuilder::buildTitleMessages($story);
            $raw = $ai->chat($messages, 'structured');
            $titles = extractJson($raw);
            if (!$titles || !is_array($titles)) {
                // 审计修复 H-3（2026-06-17）：AI 原始响应不再回传客户端（可能含 API key 等敏感信息）
                // 仅服务端 error_log 留痕。
                error_log('short_story titles parse failed: ' . mb_substr((string)$raw, 0, 200));
                jsonOut(['ok' => false, 'msg' => 'AI返回格式异常，请重试']);
            }

            jsonOut(['ok' => true, 'data' => ['titles' => $titles]]);
        } catch (Throwable $e) {
            error_log('short_story 标题生成失败: ' . $e->getMessage());
            jsonOut(safe_api_error_payload($e, '生成标题失败，请稍后重试'));
        }
        break;

    case 'quality_check':
        $id = (int)($input['id'] ?? 0);
        $story = getStoryOwned($service, $id, $userId);

        $guard = new ShortStoryQualityGuard();
        $report = $guard->evaluate($story);
        $service->saveQualityReport($id, $report);
        jsonOut(['ok' => true, 'data' => $report]);
        break;

    case 'save_content':
        $id = (int)($input['id'] ?? 0);
        $content = $input['content'] ?? '';
        $note = $input['note'] ?? '手动保存';
        ensureStoryOwned($service, $id, $userId);
        $result = $service->saveContent($id, $content, $note);
        jsonOut(normalizeResult($result));
        break;

    case 'versions':
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        ensureStoryOwned($service, $id, $userId);
        $versions = $service->listVersions($id);
        jsonOut(['ok' => true, 'data' => $versions]);
        break;

    case 'rollback':
        $id = (int)($input['id'] ?? 0);
        $versionId = (int)($input['version_id'] ?? 0);
        if ($versionId <= 0) jsonOut(['ok' => false, 'msg' => '缺少参数']);
        ensureStoryOwned($service, $id, $userId);
        $result = $service->rollbackVersion($id, $versionId);
        jsonOut(normalizeResult($result));
        break;

    case 'delete':
        $id = (int)($input['id'] ?? 0);
        ensureStoryOwned($service, $id, $userId);
        $result = $service->deleteStory($id);
        jsonOut($result);
        break;

    default:
        jsonOut(['ok' => false, 'msg' => '未知操作：' . $action]);
}

function normalizeResult(array $result): array
{
    if (isset($result['story'])) {
        $result['data'] = $result['story'];
        unset($result['story']);
    }
    if (isset($result['error']) && !isset($result['msg'])) {
        $result['msg'] = $result['error'];
        unset($result['error']);
    }
    return $result;
}

/**
 * 审计修复 P2-7（2026-06-12）：短篇小说归属校验。
 *
 * 旧代码在 `api/short_story.php` 的所有读写入口都直接用 $id 取 story，
 * 完全不做所有权校验，导致任意已登录用户可以读/改/删他人的短篇小说。
 * 这里复用 ShortStoryService::getStory() + 与 checkNovelOwnership() 一致
 * 的"NULL 自动认领 / 不匹配即 403"策略，并统一以 JSON 响应拒绝。
 *
 * @return array 校验通过时返回 story；失败时已通过 jsonResponse exit。
 */
function getStoryOwned(ShortStoryService $svc, int $storyId, int $userId): array
{
    if ($storyId <= 0) {
        jsonOut(['ok' => false, 'msg' => '缺少id']);
    }
    $story = $svc->getStory($storyId);
    if (!$story) {
        jsonOut(['ok' => false, 'msg' => '短篇小说不存在']);
    }
    if ($userId <= 0) {
        jsonResponse(false, null, '会话异常，请重新登录', 'forbidden');
    }
    $ownerId = $story['user_id'] ?? null;
    if ($ownerId === null || (int)$ownerId === 0) {
        // 历史数据：user_id 为 NULL/0 时自动认领给当前用户
        try {
            DB::update(
                'short_stories',
                ['user_id' => $userId],
                'id=? AND (user_id IS NULL OR user_id=0)',
                [$storyId]
            );
        } catch (Throwable $e) {
            error_log('ShortStory ownership auto-claim failed: ' . $e->getMessage());
        }
    } elseif ((int)$ownerId !== $userId) {
        jsonResponse(false, null, '无权操作此短篇小说', 'forbidden');
    }
    return $story;
}

/**
 * P2-7 配套：对不取 story 但仍需归属校验的写操作（save_meta/save_brief/
 * save_beats/save_chapters/save_content/rollback/delete/versions）使用的
 * 轻量检查。
 */
function ensureStoryOwned(ShortStoryService $svc, int $storyId, int $userId): void
{
    getStoryOwned($svc, $storyId, $userId);
}

function jsonOut(array $payload): void
{
    $ok = !empty($payload['ok']);
    $data = $payload['data'] ?? $payload['msg'] ?? null;
    $msg = $payload['msg'] ?? '';
    if ($ok) {
        jsonResponse(true, $data, $msg);
    } else {
        jsonResponse(false, $data, $msg);
    }
}

function extractJson(string $raw): mixed
{
    $raw = trim($raw);
    $decoded = json_decode($raw, true);
    if ($decoded !== null) return $decoded;

    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $raw, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if ($decoded !== null) return $decoded;
    }

    if (preg_match('/\[[\s\S]*\]/', $raw, $m)) {
        $decoded = json_decode($m[0], true);
        if ($decoded !== null) return $decoded;
    }
    if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
        $decoded = json_decode($m[0], true);
        if ($decoded !== null) return $decoded;
    }

    return null;
}
