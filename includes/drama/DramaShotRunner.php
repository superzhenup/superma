<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/VideoGenerationProtocol.php';
require_once dirname(__DIR__) . '/managed_files.php';
require_once __DIR__ . '/DramaService.php';
require_once __DIR__ . '/DramaPromptBuilder.php';
require_once __DIR__ . '/DramaImageService.php';
require_once __DIR__ . '/DramaMediaClient.php';
require_once __DIR__ . '/DramaTaskRepository.php';

/**
 * 分镜图抽卡与视频片段生成执行器（被 DramaTaskRunner 调度）。
 */
final class DramaShotRunner
{
    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    // ------------------------------------------------------- 资产定妆照

    public static function runAssetImageTask(array $task): array
    {
        $asset = DramaService::getAsset((int)$task['ref_id']);
        if (!$asset || (int)$asset['project_id'] !== (int)$task['project_id']) {
            throw new RuntimeException('资产不存在或已变更');
        }
        $project = DramaService::getProject((int)$task['project_id']);
        if (!$project) throw new RuntimeException('漫剧项目不存在');

        $prompt = DramaPromptBuilder::buildAssetPrompt($project, $asset);
        DramaService::ensureProjectDirs((int)$project['id']);
        $dir = DramaService::projectStorageDir((int)$project['id']) . '/assets';

        // 定妆照用方图，利于全身立绘与后续参考
        $rel = DramaImageService::generate(
            $prompt,
            '1024x1024',
            $dir,
            'asset_' . (int)$asset['id'] . '_' . time() . '_' . bin2hex(random_bytes(3))
        );

        // 切换引用后清理旧文件
        $old = (string)($asset['ref_image_path'] ?? '');
        DB::update('drama_assets', ['ref_image_path' => $rel], 'id=?', [(int)$asset['id']]);
        self::deleteManagedDramaFile($old);

        return ['asset_id' => (int)$asset['id'], 'ref_image_path' => $rel];
    }

    // ------------------------------------------------------- 分镜图抽卡

    public static function runShotImageTask(array $task): array
    {
        $shot = DramaService::getShot((int)$task['ref_id']);
        if (!$shot) throw new RuntimeException('分镜不存在');
        $episode = DramaService::getEpisode((int)$shot['episode_id']);
        if (!$episode || (int)$episode['project_id'] !== (int)$task['project_id']) {
            throw new RuntimeException('剧集不存在或已变更');
        }
        $project = DramaService::getProject((int)$task['project_id']);
        if (!$project) throw new RuntimeException('漫剧项目不存在');

        $payload = json_decode((string)($task['payload'] ?? ''), true) ?: [];
        $batch = max(1, min(4, (int)($payload['batch'] ?? 2)));

        // 出场资产（角色 + 全场景资产中名称出现在画面描述里的场景）
        $characterIds = json_decode((string)($shot['characters'] ?? ''), true) ?: [];
        $assets = DramaService::findAssetsByIds($characterIds);
        foreach (DramaService::listAssets((int)$project['id'], 'scene') as $scene) {
            if ($scene['name'] !== '' && mb_strpos((string)$shot['scene_desc'] . (string)$shot['image_prompt'], (string)$scene['name']) !== false) {
                $assets[] = $scene;
            }
        }

        $prompt = DramaPromptBuilder::buildImagePrompt($project, $shot, $assets);
        $neg = DramaPromptBuilder::negativePrompt($project);
        $fullPrompt = $neg !== '' ? ($prompt . '。避免：' . $neg) : $prompt;

        DramaService::ensureProjectDirs((int)$project['id']);
        $dir = DramaService::projectStorageDir((int)$project['id']) . '/shots/' . (int)$episode['id'];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建分镜图目录');
        }

        $candidates = json_decode((string)($shot['image_candidates'] ?? ''), true) ?: [];
        $newPaths = [];
        for ($i = 1; $i <= $batch; $i++) {
            $rel = DramaImageService::generate(
                $fullPrompt,
                (string)$project['image_size'],
                $dir,
                'shot_' . (int)$shot['id'] . '_b' . $i . '_' . time() . '_' . bin2hex(random_bytes(3))
            );
            $newPaths[] = $rel;
            $candidates[] = $rel;
            DramaTaskRepository::updateProgress((int)$task['id'], (int)round($i * 100 / $batch));
        }

        $update = ['image_candidates' => json_encode(array_values($candidates), JSON_UNESCAPED_SLASHES)];
        // 尚未选定图时自动选定第一张新图
        if (empty($shot['image_path']) && $newPaths) {
            $update['image_path'] = $newPaths[0];
        }
        $update['status'] = 'image_ready';
        $update['error_msg'] = null;
        DB::update('drama_shots', $update, 'id=?', [(int)$shot['id']]);

        return ['shot_id' => (int)$shot['id'], 'new_images' => $newPaths];
    }

    // ------------------------------------------------------- 视频片段

    public static function videoConfigured(): bool
    {
        return getSystemSetting('video_gen_api_url', '') !== ''
            && getSystemSetting('video_gen_api_key', '') !== '';
    }

    public static function videoRatioFromSize(string $size): string
    {
        if (preg_match('/^(\d{2,4})x(\d{2,4})$/', $size, $m)) {
            $w = (int)$m[1];
            $h = (int)$m[2];
            if ($w > $h) return '16:9';
            if ($h > $w) return '9:16';
        }
        return '1:1';
    }

    /**
     * 视频任务一个执行周期：无 provider task_id → submit；有 → query。
     * 未就绪时 requeue 延迟下轮（由 worker/poll 驱动）。
     */
    public static function runShotVideoTask(array $task): array
    {
        if (!self::videoConfigured()) {
            throw new RuntimeException('请先在漫剧页面配置视频生成引擎');
        }
        $shot = DramaService::getShot((int)$task['ref_id']);
        if (!$shot) throw new RuntimeException('分镜不存在');
        $episode = DramaService::getEpisode((int)$shot['episode_id']);
        if (!$episode || (int)$episode['project_id'] !== (int)$task['project_id']) {
            throw new RuntimeException('剧集不存在或已变更');
        }
        $project = DramaService::getProject((int)$task['project_id']);
        if (!$project) throw new RuntimeException('漫剧项目不存在');

        $imagePath = (string)($shot['image_path'] ?? '');
        if ($imagePath === '') throw new RuntimeException('分镜尚未选定首帧图');

        $provider = VideoGenerationProtocol::normalizeProvider((string)getSystemSetting('video_gen_provider', 'volcengine_seedance'));
        $baseUrl = VideoGenerationProtocol::normalizeBaseUrl((string)getSystemSetting('video_gen_api_url', ''));
        $apiKey = (string)getSystemSetting('video_gen_api_key', '');
        $model = (string)getSystemSetting('video_gen_model', '');
        $result = json_decode((string)($task['result'] ?? ''), true) ?: [];
        $providerTaskId = (string)($result['provider_task_id'] ?? '');

        if ($providerTaskId === '') {
            $providerTaskId = self::submitVideoTask($provider, $baseUrl, $apiKey, $model, $project, $shot, $imagePath);
            DB::update('drama_shots', ['status' => 'video_running'], 'id=?', [(int)$shot['id']]);
            DramaTaskRepository::requeue((int)$task['id'], 30, ['provider_task_id' => $providerTaskId]);
            return ['shot_id' => (int)$shot['id'], 'submitted' => true, 'provider_task_id' => $providerTaskId];
        }

        $endpoint = VideoGenerationProtocol::queryEndpoint($provider, $baseUrl, $providerTaskId);
        $resp = DramaMediaClient::requestJson(
            $endpoint,
            VideoGenerationProtocol::queryHeaders($provider, $apiKey),
            null,
            60
        );
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new RuntimeException("视频轮询返回 HTTP {$resp['status']}");
        }
        $qr = VideoGenerationProtocol::extractQueryResult($provider, $resp['body']);

        if ($qr['status'] === 'running' || $qr['status'] === 'pending') {
            DramaTaskRepository::requeue((int)$task['id'], 30, ['provider_task_id' => $providerTaskId]);
            return ['shot_id' => (int)$shot['id'], 'polling' => true];
        }
        if ($qr['status'] === 'failed') {
            DB::update('drama_shots', ['status' => 'failed', 'error_msg' => mb_substr((string)($qr['error'] ?? '视频生成失败'), 0, 480)], 'id=?', [(int)$shot['id']]);
            // provider 确定性失败：标记任务完成（记录失败原因），不再重试
            DramaTaskRepository::markDone((int)$task['id'], [
                'shot_id' => (int)$shot['id'],
                'failed'  => true,
                'error'   => mb_substr((string)($qr['error'] ?? 'provider 返回失败'), 0, 480),
            ]);
            return ['shot_id' => (int)$shot['id'], 'failed' => true, 'error' => $qr['error'] ?? 'provider 返回失败'];
        }

        // done：下载片段落盘
        DramaService::ensureProjectDirs((int)$project['id']);
        $dir = DramaService::projectStorageDir((int)$project['id']) . '/videos/' . (int)$episode['id'];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建视频目录');
        }
        $absPath = DramaMediaClient::downloadMedia(
            (string)$qr['video_url'],
            $dir . '/shot_' . (int)$shot['id'] . '_' . time(),
            self::VIDEO_MIMES,
            DramaMediaClient::MAX_VIDEO_BYTES
        );
        $rel = DramaImageService::toRelativePath($absPath);

        $old = (string)($shot['video_path'] ?? '');
        DB::update('drama_shots', [
            'video_path' => $rel,
            'status'     => 'video_done',
            'error_msg'  => null,
        ], 'id=?', [(int)$shot['id']]);
        self::deleteManagedDramaFile($old);

        return ['shot_id' => (int)$shot['id'], 'video_path' => $rel];
    }

    private static function submitVideoTask(
        string $provider,
        string $baseUrl,
        string $apiKey,
        string $model,
        array $project,
        array $shot,
        string $imagePath
    ): string {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $absImage = $base . '/' . ltrim($imagePath, '/');
        if (!is_file($absImage)) throw new RuntimeException('分镜首帧图文件缺失');

        $raw = @file_get_contents($absImage);
        if ($raw === false || strlen($raw) > DramaMediaClient::MAX_IMAGE_BYTES) {
            throw new RuntimeException('分镜首帧图读取失败或超过大小限制');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($absImage);
        if (!preg_match('#^image/(jpeg|png|webp)$#i', $mime)) {
            throw new RuntimeException('分镜首帧图格式不支持（需 JPG/PNG/WebP）');
        }

        $prompt = DramaPromptBuilder::buildVideoPrompt($project, $shot);
        $ratio = self::videoRatioFromSize((string)$project['image_size']);
        $duration = VideoGenerationProtocol::normalizeDuration((int)($shot['duration'] ?? 5));
        $payload = VideoGenerationProtocol::buildSubmitPayload(
            $provider,
            $model !== '' ? $model : 'doubao-seedance-1-0-pro-250528',
            $prompt,
            base64_encode($raw),
            $mime,
            $duration,
            $ratio
        );

        $endpoint = VideoGenerationProtocol::submitEndpoint($provider, $baseUrl);
        $resp = DramaMediaClient::requestJson(
            $endpoint,
            VideoGenerationProtocol::submitHeaders($provider, $apiKey),
            $payload,
            120
        );
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            error_log("Drama video submit rejected: HTTP {$resp['status']}, provider={$provider}");
            throw new RuntimeException("视频任务提交返回 HTTP {$resp['status']}");
        }
        return VideoGenerationProtocol::extractTaskId($provider, $resp['body']);
    }

    // ------------------------------------------------------------- 工具

    public static function deleteManagedDramaFile(?string $storedPath): void
    {
        // 漫剧媒体路径含嵌套子目录（storage/drama/{pid}/shots/{eid}/...），
        // deleteManagedRelativeFile 的文件名白名单不允许 '/'，此处直接走
        // deleteManagedAbsoluteFile 的 realpath 包含校验（等效防护）。
        if ($storedPath === null || $storedPath === '' || preg_match('#^https?://#i', $storedPath)) {
            return;
        }
        if (!defined('BASE_PATH')) return;
        $normalized = str_replace('\\', '/', $storedPath);
        if (!str_starts_with($normalized, 'storage/drama/') || str_contains($normalized, '..')) {
            return;
        }
        deleteManagedAbsoluteFile(BASE_PATH . '/' . $normalized, BASE_PATH . '/storage/drama');
    }
}
