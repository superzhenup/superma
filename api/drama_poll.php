<?php
/**
 * 漫剧工作流 — 状态轮询 API
 *
 * 附加职责：当服务器无 CLI worker（exec 不可用）时，每次轮询内联驱动一个
 * 视频任务周期（submit/query 均为快速 HTTP 调用），保证共享主机也能推进视频任务。
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
require_once dirname(__DIR__) . '/includes/drama/DramaWorkerLauncher.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $jsonInput['action'] ?? $_GET['action'] ?? '';

function dramaPollUserId(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
}

/** 无 worker 环境：内联驱动一个视频轮询周期。 */
function dramaDriveVideoInline(): void {
    static $drove = false;
    if ($drove || DramaWorkerLauncher::execAvailable()) return;
    $drove = true;
    try {
        @set_time_limit(90);
        DramaTaskRunner::runNext('drama-poll:' . (gethostname() ?: 'localhost') . ':' . getmypid(), 'gen_shot_video');
    } catch (Throwable $e) {
        error_log('drama_poll inline drive: ' . $e->getMessage());
    }
}

try {
    switch ($action) {
        case 'poll': {
            $projectId = (int)($jsonInput['project_id'] ?? $_GET['project_id'] ?? 0);
            $project = DramaService::assertProjectAccess($projectId, dramaPollUserId());
            dramaDriveVideoInline();

            $episodes = DramaService::listEpisodes($projectId);
            foreach ($episodes as &$ep) {
                $ep['stats'] = DB::fetch(
                    "SELECT COUNT(*) AS total,
                            SUM(status='image_ready') AS image_ready,
                            SUM(status='video_running') AS video_running,
                            SUM(status='video_done') AS video_done,
                            SUM(status='failed') AS failed
                     FROM drama_shots WHERE episode_id=?",
                    [(int)$ep['id']]
                );
            }
            unset($ep);

            $tasks = DramaTaskRepository::listActiveByProject($projectId);
            $busy = DB::count('drama_tasks', "project_id=? AND status IN ('pending','running')", [$projectId]);
            echo json_encode(['ok' => true, 'data' => [
                'project'  => $project,
                'episodes' => $episodes,
                'tasks'    => $tasks,
                'busy'     => $busy,
            ]], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'poll_shots': {
            $episodeId = (int)($jsonInput['episode_id'] ?? $_GET['episode_id'] ?? 0);
            [$episode] = DramaService::assertEpisodeAccess($episodeId, dramaPollUserId());
            dramaDriveVideoInline();

            $shots = DramaService::listShots($episodeId);
            $tasks = DB::fetchAll(
                "SELECT id, type, ref_id, status, progress, error, updated_at
                 FROM drama_tasks WHERE episode_id=? AND status IN ('pending','running','failed')
                 ORDER BY id DESC LIMIT 50",
                [$episodeId]
            );
            echo json_encode(['ok' => true, 'data' => [
                'episode' => $episode,
                'shots'   => $shots,
                'tasks'   => $tasks,
            ]], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    error_log('drama_poll error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $msg = in_array($e->getMessage(), ['漫剧项目不存在', '剧集不存在', '无效的操作'], true)
        ? $e->getMessage() : '操作失败，请稍后重试';
    echo json_encode(safe_api_error_payload($e, $msg), JSON_UNESCAPED_UNICODE);
}
