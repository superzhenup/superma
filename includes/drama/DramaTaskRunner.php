<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/DramaService.php';
require_once __DIR__ . '/DramaTaskRepository.php';
require_once __DIR__ . '/DramaScriptParser.php';
require_once __DIR__ . '/DramaShotRunner.php';
require_once __DIR__ . '/DramaComposer.php';

/**
 * 漫剧任务调度器：claim → 按类型分发 → 落结果/重试。
 * 被 bin/drama_worker.php（CLI）与 drama_poll.php（视频轮询内联驱动）共用。
 */
final class DramaTaskRunner
{
    /** @return array|null 执行结果摘要；队列空返回 null */
    public static function runNext(string $workerId = '', ?string $typeFilter = null): ?array
    {
        $workerId = $workerId !== '' ? $workerId : ('drama:' . (gethostname() ?: 'localhost') . ':' . getmypid());
        $task = DramaTaskRepository::claimNext($workerId, 1800, $typeFilter);
        if (!$task) return null;

        $taskId = (int)$task['id'];
        try {
            $result = self::dispatch($task);
            // Each dispatch atomically publishes its business result and terminal/requeue state.
            $state = !empty($result['polling']) || !empty($result['submitted']) ? 'pending'
                : (!empty($result['failed']) ? 'failed' : 'done');
            return ['task_id' => $taskId, 'type' => $task['type'], 'state' => $state, 'result' => $result];
        } catch (DramaLeaseLostException $e) {
            return ['task_id' => $taskId, 'type' => $task['type'], 'state' => 'lease_lost'];
        } catch (Throwable $e) {
            try {
                $state = DramaTaskRepository::markRetryOrFailed($task, $e->getMessage());
            } catch (DramaLeaseLostException $lost) {
                return ['task_id' => $taskId, 'type' => $task['type'], 'state' => 'lease_lost'];
            }
            error_log(sprintf('[drama_task:%d] %s: %s', $taskId, $state, $e->getMessage()));
            return ['task_id' => $taskId, 'type' => $task['type'], 'state' => $state, 'error' => $e->getMessage()];
        }
    }

    private static function dispatch(array $task): array
    {
        $project = DramaService::getProject((int)$task['project_id']);
        if (!$project) throw new RuntimeException('漫剧项目不存在');
        $episode = !empty($task['episode_id']) ? DramaService::getEpisode((int)$task['episode_id']) : null;

        return match ((string)$task['type']) {
            'parse_script'    => self::runParse($project, $episode, $task),
            'gen_storyboard'  => self::runStoryboard($project, $episode, $task),
            'gen_asset'       => DramaShotRunner::runAssetImageTask($task),
            'gen_shot_image'  => DramaShotRunner::runShotImageTask($task),
            'gen_shot_video'  => DramaShotRunner::runShotVideoTask($task),
            'compose_episode' => self::runCompose($project, $episode, $task),
            default           => throw new RuntimeException('未知任务类型'),
        };
    }

    private static function runParse(array $project, ?array $episode, array $task): array
    {
        if (!$episode) throw new RuntimeException('剧本解析任务缺少剧集');
        return DramaScriptParser::parseEpisode($project, $episode, $task);
    }

    private static function runStoryboard(array $project, ?array $episode, array $task): array
    {
        if (!$episode) throw new RuntimeException('分镜生成任务缺少剧集');
        $payload = json_decode((string)($task['payload'] ?? ''), true) ?: [];
        $targetShots = max(4, min(40, (int)($payload['target_shots'] ?? 12)));
        $count = DramaScriptParser::generateStoryboard($project, $episode, $targetShots, $task);
        return ['episode_id' => (int)$episode['id'], 'shots' => $count];
    }

    private static function runCompose(array $project, ?array $episode, array $task): array
    {
        if (!$episode) throw new RuntimeException('合成任务缺少剧集');
        $payloadNote = ['episode_id' => (int)$episode['id']];
        $rel = DramaComposer::compose($project, $episode, $task);
        return $payloadNote + ['final_video_path' => $rel];
    }
}
