<?php
/**
 * 漫剧工作流 — 项目/剧集/资产/分镜 CRUD API
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
require_once dirname(__DIR__) . '/includes/drama/DramaShotRunner.php';
require_once dirname(__DIR__) . '/includes/drama/DramaImageService.php';
require_once dirname(__DIR__) . '/includes/drama/DramaComposer.php';
require_once dirname(__DIR__) . '/includes/drama/DramaWorkerLauncher.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_POST['action'] ?? $jsonInput['action'] ?? $_GET['action'] ?? '';
$readOnlyActions = ['get_project', 'list_assets', 'list_shots'];
if (!in_array($action, $readOnlyActions, true)) {
    requireHttpMethod('POST');
}

function dramaUserId(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
}

/** 客户端可读的错误文案白名单（前缀匹配）。 */
function dramaSafeErrorMessage(Throwable $e): string {
    $message = $e->getMessage();
    foreach ([
        '小说不存在', '漫剧项目不存在', '剧集不存在', '资产', '分镜', '章节范围',
        '所选章节范围', '尺寸', '无效的', '缺少', '请先', '服务器未检测到 FFmpeg',
        '没有已生成', '没有可导出', 'PHP 未启用 zip', '需要管理员权限',
    ] as $prefix) {
        if (str_starts_with($message, $prefix)) return mb_substr($message, 0, 240);
    }
    return '操作失败，请稍后重试';
}

try {
    switch ($action) {
        case 'get_project': {
            $novelId = (int)($jsonInput['novel_id'] ?? $_GET['novel_id'] ?? 0);
            if ($novelId <= 0) throw new RuntimeException('缺少小说ID');
            checkNovelOwnership($novelId, dramaUserId());
            $project = DramaService::getOrCreateProject($novelId, dramaUserId());
            $episodes = DramaService::listEpisodes((int)$project['id']);
            $episodeStats = [];
            foreach ($episodes as $ep) {
                $episodeStats[] = $ep + ['stats' => dramaEpisodeStats((int)$ep['id'])];
            }
            echo json_encode(['ok' => true, 'data' => [
                'project'          => $project,
                'episodes'         => $episodeStats,
                'assets_count'     => DB::count('drama_assets', 'project_id=?', [(int)$project['id']]),
                'image_configured' => DramaImageService::isConfigured(),
                'video_configured' => DramaShotRunner::videoConfigured(),
                'exec_available'   => DramaWorkerLauncher::execAvailable(),
                'ffmpeg_available' => DramaComposer::ffmpegAvailable(),
            ]], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'save_project': {
            $projectId = (int)($jsonInput['project_id'] ?? 0);
            DramaService::assertProjectAccess($projectId, dramaUserId());
            DramaService::updateProject($projectId, [
                'title'          => mb_substr(trim((string)($jsonInput['title'] ?? '')), 0, 200),
                'style_prompt'   => (string)($jsonInput['style_prompt'] ?? ''),
                'style_negative' => (string)($jsonInput['style_negative'] ?? ''),
                'image_size'     => (string)($jsonInput['image_size'] ?? '1280x720'),
            ]);
            echo json_encode(['ok' => true, 'msg' => '项目设置已保存'], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'create_episode': {
            $projectId = (int)($jsonInput['project_id'] ?? 0);
            DramaService::assertProjectAccess($projectId, dramaUserId());
            $episode = DramaService::createEpisode(
                $projectId,
                (int)($jsonInput['chapter_start'] ?? 0),
                (int)($jsonInput['chapter_end'] ?? 0),
                trim((string)($jsonInput['title'] ?? ''))
            );
            echo json_encode(['ok' => true, 'msg' => '剧集已创建', 'data' => $episode], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'delete_episode': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            DramaService::assertEpisodeAccess($episodeId, dramaUserId());
            DramaService::deleteEpisode($episodeId);
            echo json_encode(['ok' => true, 'msg' => '剧集已删除'], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'list_assets': {
            $projectId = (int)($jsonInput['project_id'] ?? $_GET['project_id'] ?? 0);
            DramaService::assertProjectAccess($projectId, dramaUserId());
            $type = (string)($jsonInput['type'] ?? $_GET['type'] ?? '');
            if ($type !== '' && !in_array($type, ['character', 'scene', 'prop'], true)) $type = '';
            echo json_encode(['ok' => true, 'data' => DramaService::listAssets($projectId, $type)], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'save_asset': {
            $projectId = (int)($jsonInput['project_id'] ?? 0);
            DramaService::assertProjectAccess($projectId, dramaUserId());
            $assetId = (int)($jsonInput['asset_id'] ?? 0);
            $name = trim((string)($jsonInput['name'] ?? ''));
            $description = (string)($jsonInput['description'] ?? '');
            $type = (string)($jsonInput['type'] ?? 'character');
            if ($assetId > 0) {
                $asset = DramaService::getAsset($assetId);
                if (!$asset || (int)$asset['project_id'] !== $projectId) throw new RuntimeException('资产不存在');
                DB::update('drama_assets', [
                    'name'        => mb_substr($name, 0, 100),
                    'description' => $description,
                ], 'id=?', [$assetId]);
            } else {
                $assetId = DramaService::upsertAsset($projectId, $type, $name, $description, 'manual');
            }
            echo json_encode(['ok' => true, 'msg' => '资产已保存', 'data' => ['asset_id' => $assetId]], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'delete_asset': {
            $projectId = (int)($jsonInput['project_id'] ?? 0);
            DramaService::assertProjectAccess($projectId, dramaUserId());
            $asset = DramaService::getAsset((int)($jsonInput['asset_id'] ?? 0));
            if (!$asset || (int)$asset['project_id'] !== $projectId) throw new RuntimeException('资产不存在');
            DB::delete('drama_assets', 'id=?', [(int)$asset['id']]);
            DramaShotRunner::deleteManagedDramaFile($asset['ref_image_path'] ?? null);
            echo json_encode(['ok' => true, 'msg' => '资产已删除'], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'list_shots': {
            $episodeId = (int)($jsonInput['episode_id'] ?? $_GET['episode_id'] ?? 0);
            [, $project] = DramaService::assertEpisodeAccess($episodeId, dramaUserId());
            echo json_encode(['ok' => true, 'data' => [
                'shots'   => DramaService::listShots($episodeId),
                'assets'  => DramaService::listAssets((int)$project['id']),
            ]], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'save_shot': {
            $shotId = (int)($jsonInput['shot_id'] ?? 0);
            $shot = DramaService::getShot($shotId);
            if (!$shot) throw new RuntimeException('分镜不存在');
            DramaService::assertEpisodeAccess((int)$shot['episode_id'], dramaUserId());

            $data = [];
            foreach (['scene_desc', 'dialogue', 'image_prompt', 'video_prompt'] as $f) {
                if (array_key_exists($f, $jsonInput)) $data[$f] = (string)$jsonInput[$f];
            }
            if (isset($jsonInput['shot_type'])) $data['shot_type'] = mb_substr((string)$jsonInput['shot_type'], 0, 20);
            if (isset($jsonInput['camera_movement'])) $data['camera_movement'] = mb_substr((string)$jsonInput['camera_movement'], 0, 20);
            if (isset($jsonInput['duration'])) $data['duration'] = max(3, min(12, (int)$jsonInput['duration']));
            if (isset($jsonInput['characters']) && is_array($jsonInput['characters'])) {
                $data['characters'] = json_encode(array_values(array_map('intval', $jsonInput['characters'])), JSON_UNESCAPED_UNICODE);
            }
            if ($data) DB::update('drama_shots', $data, 'id=?', [$shotId]);
            echo json_encode(['ok' => true, 'msg' => '分镜已保存'], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'select_shot_image': {
            $shotId = (int)($jsonInput['shot_id'] ?? 0);
            $shot = DramaService::getShot($shotId);
            if (!$shot) throw new RuntimeException('分镜不存在');
            DramaService::assertEpisodeAccess((int)$shot['episode_id'], dramaUserId());
            $path = (string)($jsonInput['image_path'] ?? '');
            $candidates = json_decode((string)($shot['image_candidates'] ?? ''), true) ?: [];
            if (!in_array($path, $candidates, true)) throw new RuntimeException('无效的分镜图选择');
            DB::update('drama_shots', ['image_path' => $path, 'status' => 'image_ready'], 'id=?', [$shotId]);
            echo json_encode(['ok' => true, 'msg' => '已选定分镜图'], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'add_shot': {
            $episodeId = (int)($jsonInput['episode_id'] ?? 0);
            DramaService::assertEpisodeAccess($episodeId, dramaUserId());
            $nextNo = (int)DB::fetchColumn('SELECT COALESCE(MAX(shot_no),0)+1 FROM drama_shots WHERE episode_id=?', [$episodeId]);
            DB::insert('drama_shots', ['episode_id' => $episodeId, 'shot_no' => $nextNo]);
            echo json_encode(['ok' => true, 'msg' => '已添加分镜', 'data' => ['shot_no' => $nextNo]], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'delete_shot': {
            $shotId = (int)($jsonInput['shot_id'] ?? 0);
            $shot = DramaService::getShot($shotId);
            if (!$shot) throw new RuntimeException('分镜不存在');
            [$episode] = DramaService::assertEpisodeAccess((int)$shot['episode_id'], dramaUserId());
            DB::beginTransaction();
            try {
                DB::delete('drama_shots', 'id=?', [$shotId]);
                // 后续镜号前移，保持连续
                DB::execute(
                    'UPDATE drama_shots SET shot_no = shot_no - 1 WHERE episode_id=? AND shot_no > ?',
                    [(int)$episode['id'], (int)$shot['shot_no']]
                );
                DB::commit();
            } catch (Throwable $e) {
                if (DB::inTransaction()) DB::rollBack();
                throw $e;
            }
            DramaShotRunner::deleteManagedDramaFile($shot['image_path'] ?? null);
            DramaShotRunner::deleteManagedDramaFile($shot['video_path'] ?? null);
            echo json_encode(['ok' => true, 'msg' => '分镜已删除'], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    error_log('drama_actions error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(safe_api_error_payload($e, dramaSafeErrorMessage($e)), JSON_UNESCAPED_UNICODE);
}

/** 剧集维度统计。 */
function dramaEpisodeStats(int $episodeId): array {
    $rows = DB::fetchAll(
        'SELECT status, COUNT(*) AS c FROM drama_shots WHERE episode_id=? GROUP BY status',
        [$episodeId]
    );
    $stats = ['total' => 0, 'image_ready' => 0, 'video_done' => 0, 'failed' => 0, 'video_running' => 0];
    foreach ($rows as $r) {
        $c = (int)$r['c'];
        $stats['total'] += $c;
        if (isset($stats[$r['status']])) $stats[$r['status']] += $c;
    }
    return $stats;
}
