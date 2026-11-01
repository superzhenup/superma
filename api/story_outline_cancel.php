<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/story_outline/api.php';

storyOutlineApiRespond(static function (): array {
    storyOutlineRequireMethod('POST');
    $input = storyOutlineRequestBody();
    $novelId = storyOutlineNovelId($input);
    $taskId = (string)($input['task_id'] ?? '');
    $service = new StoryOutlineTaskService(DB::getPdo());
    return ['success' => true, 'task' => $service->cancel(currentUserId(), $novelId, $taskId)];
});
