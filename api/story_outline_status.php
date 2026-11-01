<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/story_outline/api.php';

storyOutlineApiRespond(static function (): array {
    storyOutlineRequireMethod('GET');
    $novelId = storyOutlineNovelId($_GET);
    $taskId = isset($_GET['task_id']) ? (string)$_GET['task_id'] : null;
    $service = new StoryOutlineTaskService(DB::getPdo());
    return storyOutlineTaskResponse($service->status(currentUserId(), $novelId, $taskId));
});
