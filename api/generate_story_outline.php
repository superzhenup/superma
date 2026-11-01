<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/story_outline/api.php';

storyOutlineApiRespond(static function (): array {
    storyOutlineRequireMethod('POST');
    $input = storyOutlineRequestBody();
    $novelId = storyOutlineNovelId($input);
    $requestKey = (string)($input['request_key'] ?? '');
    $mode = (string)($input['mode'] ?? 'resume');
    $service = new StoryOutlineTaskService(DB::getPdo());
    $task = $service->start(currentUserId(), $novelId, $requestKey, $mode,
        static function (array $novel, ?array $frozenContext = null): array {
            // Freeze the exact prompt once. Recovery uses the same context.
            require_once dirname(__DIR__) . '/includes/ai.php';
            require_once dirname(__DIR__) . '/includes/prompt.php';
            $preferred = empty($novel['model_id']) ? null : (int)$novel['model_id'];
            $models = $frozenContext['models'] ?? StoryOutlineBudget::freezeModels(getModelFallbackList($preferred, 'structured'));
            $source = StoryOutlineSource::capture(DB::getPdo(), (int)$novel['id']);
            $chapters = $source['chapters'];
            return [
                'base_messages' => $chapters
                    ? buildStoryOutlineFromChaptersPrompt($novel, $chapters, $source['progress'], $source['volume_summaries'])
                    : buildStoryOutlinePrompt($novel),
                'preferred_model' => $frozenContext['preferred_model'] ?? $preferred,
                'target_chapters' => max(1,(int)($novel['target_chapters'] ?? 100)),
                'models' => $models,
            ];
        }
    );
    return storyOutlineDispatchTask($task,
        static fn(string $taskId): array => StoryOutlineWorkerLauncher::dispatch($taskId),
        static fn(string $taskId): ?array => $service->status(currentUserId(), $novelId, $taskId)
    );
});
