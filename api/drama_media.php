<?php
/**
 * 漫剧工作流 — 媒体上传 API（资产定妆照 / 分镜首帧图手动上传）
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
require_once dirname(__DIR__) . '/includes/drama/DramaMediaClient.php';
require_once dirname(__DIR__) . '/includes/drama/DramaImageService.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
requireHttpMethod('POST');

const DRAMA_UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const DRAMA_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

function dramaMediaUserId(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
}

/** 读取并校验上传文件内容。 */
function dramaReadUpload(): array {
    if (empty($_FILES['media_file']) || $_FILES['media_file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errors = [
            UPLOAD_ERR_INI_SIZE  => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL   => '文件上传不完整',
            UPLOAD_ERR_NO_FILE   => '没有文件被上传',
        ];
        throw new RuntimeException($errors[$code] ?? '上传失败');
    }
    $file = $_FILES['media_file'];
    if ($file['size'] > DRAMA_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('文件超过 10MB 限制');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, DRAMA_IMAGE_MIMES, true)) {
        throw new RuntimeException('仅支持 JPG、PNG、WebP 格式');
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false || $content === '') {
        throw new RuntimeException('上传文件读取失败');
    }
    return [$content, $mime];
}

try {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'upload_asset_image': {
            $assetId = (int)($_POST['asset_id'] ?? 0);
            $asset = DramaService::getAsset($assetId);
            if (!$asset) throw new RuntimeException('资产不存在');
            DramaService::assertProjectAccess((int)$asset['project_id'], dramaMediaUserId());

            [$content] = dramaReadUpload();
            DramaService::ensureProjectDirs((int)$asset['project_id']);
            $dir = DramaService::projectStorageDir((int)$asset['project_id']) . '/assets';
            $absPath = DramaMediaClient::saveCheckedMedia(
                $content,
                $dir . '/asset_' . $assetId . '_up_' . time() . '_' . bin2hex(random_bytes(3)),
                DRAMA_IMAGE_MIMES,
                DRAMA_UPLOAD_MAX_BYTES
            );
            $rel = DramaImageService::toRelativePath($absPath);
            $old = (string)($asset['ref_image_path'] ?? '');
            DB::update('drama_assets', ['ref_image_path' => $rel], 'id=?', [$assetId]);
            DramaShotRunner::deleteManagedDramaFile($old);
            echo json_encode(['ok' => true, 'msg' => '定妆照已上传', 'path' => $rel], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'upload_shot_image': {
            $shotId = (int)($_POST['shot_id'] ?? 0);
            $shot = DramaService::getShot($shotId);
            if (!$shot) throw new RuntimeException('分镜不存在');
            [$episode, $project] = DramaService::assertEpisodeAccess((int)$shot['episode_id'], dramaMediaUserId());

            [$content] = dramaReadUpload();
            DramaService::ensureProjectDirs((int)$project['id']);
            $dir = DramaService::projectStorageDir((int)$project['id']) . '/shots/' . (int)$episode['id'];
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('无法创建分镜图目录');
            }
            $absPath = DramaMediaClient::saveCheckedMedia(
                $content,
                $dir . '/shot_' . $shotId . '_up_' . time() . '_' . bin2hex(random_bytes(3)),
                DRAMA_IMAGE_MIMES,
                DRAMA_UPLOAD_MAX_BYTES
            );
            $rel = DramaImageService::toRelativePath($absPath);

            $candidates = json_decode((string)($shot['image_candidates'] ?? ''), true) ?: [];
            $candidates[] = $rel;
            DB::update('drama_shots', [
                'image_path'       => $rel,
                'image_candidates' => json_encode(array_values($candidates), JSON_UNESCAPED_SLASHES),
                'status'           => 'image_ready',
                'error_msg'        => null,
            ], 'id=?', [$shotId]);
            echo json_encode(['ok' => true, 'msg' => '首帧图已上传并选定', 'path' => $rel], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    error_log('drama_media error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $exact = ['资产不存在', '分镜不存在', '无效的操作', '没有文件被上传', '文件超过 10MB 限制', '仅支持 JPG、PNG、WebP 格式'];
    $msg = in_array($e->getMessage(), $exact, true) ? $e->getMessage() : '操作失败，请稍后重试';
    foreach (['文件超过', '无法创建', '上传'] as $prefix) {
        if (str_starts_with($e->getMessage(), $prefix)) { $msg = $e->getMessage(); break; }
    }
    echo json_encode(safe_api_error_payload($e, $msg), JSON_UNESCAPED_UNICODE);
}
