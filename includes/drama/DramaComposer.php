<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/DramaService.php';
require_once __DIR__ . '/DramaImageService.php';

/**
 * 剧集合成器：FFmpeg 拼接分镜视频片段为成片（v1 无音轨）。
 * 无 FFmpeg 时降级导出素材 zip。
 */
final class DramaComposer
{
    public static function ffmpegAvailable(): bool
    {
        static $available = null;
        if ($available !== null) return $available;
        $available = false;
        if (function_exists('exec')) {
            $out = [];
            $code = 1;
            @exec('ffmpeg -version 2>&1', $out, $code);
            $available = ($code === 0);
        }
        return $available;
    }

    /**
     * 拼接剧集全部 video_done 分镜。
     * @return string 成片相对路径
     */
    public static function compose(array $project, array $episode): string
    {
        if (!self::ffmpegAvailable()) {
            throw new RuntimeException('服务器未检测到 FFmpeg，无法在线合成；请安装 FFmpeg 或改用导出素材包');
        }
        $shots = DB::fetchAll(
            "SELECT * FROM drama_shots WHERE episode_id=? AND status='video_done' AND video_path IS NOT NULL AND video_path != '' ORDER BY shot_no ASC",
            [(int)$episode['id']]
        );
        if (count($shots) < 1) {
            throw new RuntimeException('没有已生成的分镜视频片段');
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $segments = [];
        foreach ($shots as $shot) {
            $abs = $base . '/' . ltrim(str_replace('\\', '/', (string)$shot['video_path']), '/');
            if (!is_file($abs)) {
                throw new RuntimeException('分镜 ' . (int)$shot['shot_no'] . ' 的视频文件缺失');
            }
            $segments[] = $abs;
        }

        DramaService::ensureProjectDirs((int)$project['id']);
        $finalDir = DramaService::projectStorageDir((int)$project['id']) . '/final';
        $listFile = $finalDir . '/concat_' . (int)$episode['id'] . '_' . time() . '.txt';
        $outFile = $finalDir . '/episode_' . (int)$episode['episode_no'] . '_' . date('Ymd_His') . '.mp4';

        $listContent = '';
        foreach ($segments as $abs) {
            // concat demuxer 路径统一为正斜杠并转义单引号
            $path = str_replace('\\', '/', $abs);
            $path = str_replace("'", "'\\''", $path);
            $listContent .= "file '" . $path . "'\n";
        }
        if (@file_put_contents($listFile, $listContent, LOCK_EX) === false) {
            throw new RuntimeException('无法写入合成清单文件');
        }

        [$w, $h] = self::parseSize((string)$project['image_size']);

        // 先试流复制（同 provider 同参数片段通常可直接拼），失败再重编码统一规格
        $copyCmd = 'ffmpeg -y -f concat -safe 0 -i ' . escapeshellarg($listFile)
            . ' -c copy -an ' . escapeshellarg($outFile) . ' 2>&1';
        $ok = self::runFfmpeg($copyCmd, $outFile);

        if (!$ok) {
            @unlink($outFile);
            $filter = sprintf(
                'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1',
                $w, $h, $w, $h
            );
            $reCmd = 'ffmpeg -y -f concat -safe 0 -i ' . escapeshellarg($listFile)
                . ' -vf ' . escapeshellarg($filter)
                . ' -c:v libx264 -preset medium -crf 20 -pix_fmt yuv420p -an '
                . escapeshellarg($outFile) . ' 2>&1';
            $ok = self::runFfmpeg($reCmd, $outFile);
        }
        @unlink($listFile);

        if (!$ok) {
            throw new RuntimeException('FFmpeg 合成失败，请检查服务器 FFmpeg 安装');
        }

        $rel = DramaImageService::toRelativePath($outFile);
        $old = (string)($episode['final_video_path'] ?? '');
        DB::update('drama_episodes', [
            'final_video_path' => $rel,
            'status'           => 'completed',
        ], 'id=?', [(int)$episode['id']]);
        if ($old !== '' && $old !== $rel) {
            DramaShotRunner::deleteManagedDramaFile($old);
        }
        return $rel;
    }

    /**
     * 导出素材包（分镜图 + 视频片段）zip，供无 FFmpeg 环境使用。
     * @return string zip 相对路径
     */
    public static function exportZip(array $project, array $episode): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP 未启用 zip 扩展，无法导出素材包');
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $shots = DramaService::listShots((int)$episode['id']);

        DramaService::ensureProjectDirs((int)$project['id']);
        $zipPath = DramaService::projectStorageDir((int)$project['id']) . '/final/episode_'
            . (int)$episode['episode_no'] . '_assets_' . date('Ymd_His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建素材包文件');
        }
        $added = 0;
        foreach ($shots as $shot) {
            foreach (['image_path' => 'images', 'video_path' => 'videos'] as $field => $sub) {
                $rel = (string)($shot[$field] ?? '');
                if ($rel === '') continue;
                $abs = $base . '/' . ltrim(str_replace('\\', '/', $rel), '/');
                if (!is_file($abs)) continue;
                $zip->addFile($abs, $sub . '/shot_' . str_pad((string)$shot['shot_no'], 3, '0', STR_PAD_LEFT) . '_' . basename($abs));
                $added++;
            }
        }
        $zip->close();
        if ($added === 0) {
            @unlink($zipPath);
            throw new RuntimeException('没有可导出的素材文件');
        }
        return DramaImageService::toRelativePath($zipPath);
    }

    private static function runFfmpeg(string $cmd, string $outFile): bool
    {
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            error_log('DramaComposer ffmpeg failed (code ' . $code . '): ' . mb_substr(implode("\n", $out), 0, 800));
        }
        return $code === 0 && is_file($outFile) && filesize($outFile) > 0;
    }

    /** @return array{0:int,1:int} */
    private static function parseSize(string $size): array
    {
        if (preg_match('/^(\d{2,4})x(\d{2,4})$/', $size, $m)) {
            // 保证偶数（libx264 要求）
            return [(int)$m[1] & ~1, (int)$m[2] & ~1];
        }
        return [1280, 720];
    }
}
