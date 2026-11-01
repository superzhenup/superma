<?php
defined('API_VIA_ROUTER') or die('Direct access denied.');
require_once __DIR__ . '/StoryOutlineTaskService.php';
require_once __DIR__ . '/StoryOutlineStageGenerator.php';
require_once __DIR__ . '/StoryOutlineWorkerLauncher.php';

function storyOutlineRequireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        http_response_code(405);
        header('Allow: ' . $method);
        throw new InvalidArgumentException('请求方式不正确');
    }
}

function storyOutlineRequestBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new InvalidArgumentException('请求内容不是有效的 JSON');
    return $body;
}

function storyOutlineNovelId(array $input): int
{
    $id = filter_var($input['novel_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) throw new InvalidArgumentException('缺少有效的小说 ID');
    return $id;
}

/** POST may wake an already-admitted task; the durable queue still owns execution. */
function storyOutlineDispatchTask(array $task, callable $dispatch, callable $refresh): array
{
    $launch = null;
    if (!empty($task['worker_needed']) && !empty($task['task_id'])
        && in_array($task['state'] ?? '', ['queued', 'retry_wait', 'running'], true)) {
        $taskId = (string)$task['task_id'];
        $launch = $dispatch($taskId);
        // A fast worker may claim, finish or fail before the POST returns.
        // Never equate shell acceptance with a database lease/receipt.
        $current = $refresh($taskId);
        if (!is_array($current) || ($current['task_id'] ?? null) !== $taskId) {
            throw new RuntimeException('Story outline handoff lost its exact task identity.');
        }
        $task = $current;
    }
    return storyOutlineTaskResponse($task, $launch);
}

function storyOutlineTaskResponse(?array $task, ?array $launch = null): array
{
    // GET status remains read-only: inspect the last launch record, never spawn.
    if ($task !== null && !empty($task['task_id'])) {
        $launch ??= StoryOutlineWorkerLauncher::readDispatchStatus((string)$task['task_id']);
        $task['worker_launch'] = $launch;
    }
    return ['success' => true, 'task' => $task,
        'worker_started' => ($task['state'] ?? null) === 'running' && empty($task['worker_needed']),
        'worker_dispatched' => !empty($launch['dispatched']), 'worker_launch' => $launch];
}

function storyOutlineApiRespond(callable $action): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // Router already enforced login and CSRF; don't hold a session during queue I/O.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        $response = $action();
    } catch (InvalidArgumentException $error) {
        if (http_response_code() < 400) http_response_code(400);
        $response = ['success' => false, 'message' => $error->getMessage()];
    } catch (Throwable $error) {
        $id = error_trace_id();
        error_log(sprintf('[%s] story_outline: %s in %s:%d',
            $id, $error->getMessage(), $error->getFile(), $error->getLine()));
        http_response_code(500);
        $response = ['success' => false,
            'message' => '故事大纲任务暂时不可用，请稍后重试；已保存的进度会保留',
            'request_id' => $id];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
