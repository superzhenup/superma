<?php
/**
 * 漫剧工作流 — 生成类任务提交 API（解析/分镜/抽卡/视频/合成）
 *
 * 统一语义：写入 drama_tasks → 尝试拉起 CLI worker；
 * exec 不可用时对轻量/LLM/图片任务内联同步执行，视频轮询由 drama_poll 驱动。
 */

ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/drama/DramaService.php';
require_once dirname(__DIR__) . '/includes/drama/DramaTaskRepository.php';
require_once dirname(__DIR__) . '/includes/drama/DramaTaskRunner.php';
require_once dirname(__DIR__) . '/includes/drama/DramaShotRunner.php';
require_once dirname(__DIR__) . '/includes/drama/DramaComposer.php';
require_once dirname(__DIR__) . '/includes/drama/DramaImageService.php';
require_once dirname(__DIR__) . '/includes/drama/DramaWorkerLauncher.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
requireHttpMethod('POST');

$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $jsonInput['action'] ?? $_POST['action'] ?? '';

function dramaGenUserId(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
}

/** 提交任务并尽量驱动执行；返回 {task_id(s), inline}。 */
function dramaEnqueueAndDrive(int $projectId, ?int $episodeId, string $type, int $refId, array $payload, int $maxAttempts = 3): array {
    $taskId = DramaTaskRepository::enqueue($projectId, $episodeId, $type, $refId, $payload, 0, $maxAttempts);
    $inline = false;
    if (DramaWorkerLauncher::launch()) {
        return ['task_ids' => [$taskId], 'inline' => false];
    }
    // exec 不可用：LLM/图片类任务内联同步执行（视频任务交给 drama_poll 驱动轮询）
    if (in_array($type, ['parse_script', 'gen_storyboard', 'gen_asset', 'gen_shot_image'], true)) {
        @set_time_limit(600);
        DramaTaskRunner::runNext('drama-inline:' . (gethostname() ?: 'localhost') . ':' . getmypid(), $type);
        $inline = true;
    }
    return ['task_ids' => [$taskId], 'inline' => $inline];
}

function dramaGenSafeErrorMessage(Throwable $e): string {
    $message = $e->getMessage();
    foreach ([
        '剧集不存在', '漫剧项目不存在', '分镜', '资产', '请先', '没有', '无效的',
        '缺少', '章节', '服务器未检测到 FFmpeg', '图片生成', '视频', '需要管理员权限',
    ] as $prefix) {
        if (str_starts_with($message, $prefix)) return mb_substr($message, 0, 240);
    }
    return '操作失败，请稍后重试';
}

try {
    switch ($action) {
        // 剧本解析（提取角色/场景/道具资产）
        case 'parse_script': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            [$episode] = DramaService::assertEpisodeAccess($episodeId, dramaGenUserId());
            $result = dramaEnqueueAndDrive((int)$episode['project_id'], $episodeId, 'parse_script', $episodeId, [], 5);
            echo json_encode(['ok' => true, 'msg' => $result['inline'] ? '剧本解析已执行' : '剧本解析任务已提交', 'data' => $result], JSON_UNESCAPED_UNICODE);
            break;
        }

        // AI 生成分镜脚本
        case 'generate_storyboard': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            [$episode] = DramaService::assertEpisodeAccess($episodeId, dramaGenUserId());
            $targetShots = max(4, min(40, (int)($jsonInput['target_shots'] ?? 12)));
            $result = dramaEnqueueAndDrive((int)$episode['project_id'], $episodeId, 'gen_storyboard', $episodeId, ['target_shots' => $targetShots], 5);
            echo json_encode(['ok' => true, 'msg' => $result['inline'] ? '分镜生成已执行' : '分镜生成任务已提交', 'data' => $result], JSON_UNESCAPED_UNICODE);
            break;
        }

        // 生成/重抽资产定妆照
        case 'gen_asset_image': {
            $projectId = (int)($jsonInput['project_id'] ?? 0);
            DramaService::assertProjectAccess($projectId, dramaGenUserId());
            $asset = DramaService::getAsset((int)($jsonInput['asset_id'] ?? 0));
            if (!$asset || (int)$asset['project_id'] !== $projectId) throw new RuntimeException('资产不存在');
            if (!DramaImageService::isConfigured()) throw new RuntimeException('请先在模型设置中配置图片生成引擎');
            $result = dramaEnqueueAndDrive($projectId, null, 'gen_asset', (int)$asset['id'], [], 3);
            echo json_encode(['ok' => true, 'msg' => $result['inline'] ? '定妆照生成已执行' : '定妆照生成任务已提交', 'data' => $result], JSON_UNESCAPED_UNICODE);
            break;
        }

        // 分镜图抽卡：shot_ids 缺省时对全部未出图分镜批量
        case 'gen_shot_images': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            [$episode] = DramaService::assertEpisodeAccess($episodeId, dramaGenUserId());
            if (!DramaImageService::isConfigured()) throw new RuntimeException('请先在模型设置中配置图片生成引擎');
            $batch = max(1, min(4, (int)($jsonInput['batch'] ?? 2)));
            $shotIds = array_values(array_filter(array_map('intval', (array)($jsonInput['shot_ids'] ?? []))));
            $shots = DramaService::listShots($episodeId);
            $taskIds = [];
            foreach ($shots as $shot) {
                if ($shotIds && !in_array((int)$shot['id'], $shotIds, true)) continue;
                if (!$shotIds && !empty($shot['image_path'])) continue; // 批量模式只补未出图的
                $r = dramaEnqueueAndDrive((int)$episode['project_id'], $episodeId, 'gen_shot_image', (int)$shot['id'], ['batch' => $batch], 3);
                $taskIds[] = $r['task_ids'][0];
            }
            if (!$taskIds) throw new RuntimeException('没有需要生成分镜图的分镜');
            echo json_encode(['ok' => true, 'msg' => '已提交 ' . count($taskIds) . ' 个分镜图任务', 'data' => ['task_ids' => $taskIds]], JSON_UNESCAPED_UNICODE);
            break;
        }

        // 图生视频：shot_ids 缺省时对全部已定稿分镜图的分镜批量
        case 'gen_shot_videos': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            [$episode] = DramaService::assertEpisodeAccess($episodeId, dramaGenUserId());
            if (!DramaShotRunner::videoConfigured()) throw new RuntimeException('请先在漫剧页面配置视频生成引擎');
            $shotIds = array_values(array_filter(array_map('intval', (array)($jsonInput['shot_ids'] ?? []))));
            $shots = DramaService::listShots($episodeId);
            $taskIds = [];
            foreach ($shots as $shot) {
                if ($shotIds && !in_array((int)$shot['id'], $shotIds, true)) continue;
                if (empty($shot['image_path'])) continue;
                if (!$shotIds && in_array((string)$shot['status'], ['video_done', 'video_running'], true)) continue;
                // 视频轮询每次 requeue 消耗一次 attempt，max_attempts 给足 40 轮
                $r = dramaEnqueueAndDrive((int)$episode['project_id'], $episodeId, 'gen_shot_video', (int)$shot['id'], [], 40);
                $taskIds[] = $r['task_ids'][0];
            }
            if (!$taskIds) throw new RuntimeException('没有可提交视频任务的分镜（需先选定分镜图）');
            echo json_encode(['ok' => true, 'msg' => '已提交 ' . count($taskIds) . ' 个视频任务', 'data' => ['task_ids' => $taskIds]], JSON_UNESCAPED_UNICODE);
            break;
        }

        // FFmpeg 拼接成片
        case 'compose_episode': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            [$episode] = DramaService::assertEpisodeAccess($episodeId, dramaGenUserId());
            if (!DramaComposer::ffmpegAvailable()) throw new RuntimeException('服务器未检测到 FFmpeg，无法在线合成；请改用导出素材包');
            $result = dramaEnqueueAndDrive((int)$episode['project_id'], $episodeId, 'compose_episode', $episodeId, [], 2);
            echo json_encode(['ok' => true, 'msg' => '合成任务已提交', 'data' => $result], JSON_UNESCAPED_UNICODE);
            break;
        }

        // 无 FFmpeg 降级：导出素材 zip（同步执行，文件收集较快）
        case 'export_zip': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            [$episode, $project] = DramaService::assertEpisodeAccess($episodeId, dramaGenUserId());
            $rel = DramaComposer::exportZip($project, $episode);
            echo json_encode(['ok' => true, 'msg' => '素材包已导出', 'data' => ['path' => $rel]], JSON_UNESCAPED_UNICODE);
            break;
        }

        // 取消剧集全部待执行任务
        case 'cancel_episode_tasks': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            DramaService::assertEpisodeAccess($episodeId, dramaGenUserId());
            $n = DramaTaskRepository::cancelPendingByEpisode($episodeId);
            echo json_encode(['ok' => true, 'msg' => '已取消 ' . $n . ' 个待执行任务'], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    error_log('drama_generate error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(safe_api_error_payload($e, dramaGenSafeErrorMessage($e)), JSON_UNESCAPED_UNICODE);
}
