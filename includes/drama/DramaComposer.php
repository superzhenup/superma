<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/DramaService.php';
require_once __DIR__ . '/DramaImageService.php';
require_once __DIR__ . '/DramaTaskRepository.php';

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
        $available = self::ffmpegBinary() !== null;
        return $available;
    }

    /**
     * 拼接剧集全部分镜；执行前与发布时均核验完整集合和媒体版本。
     * @return string 成片相对路径
     */
    public static function compose(array $project, array $episode, array $task): string
    {
        DramaTaskRepository::assertOwned($task);
        $snapshot = self::compositionSnapshot($project, $episode);
        if (!self::ffmpegAvailable()) {
            throw new RuntimeException('服务器未检测到 FFmpeg，无法在线合成；请安装 FFmpeg 或改用导出素材包');
        }
        $segments = array_column($snapshot, 'absolute_path');

        DramaService::ensureProjectDirs((int)$project['id']);
        $finalDir = DramaService::projectStorageDir((int)$project['id']) . '/final';
        $artifactId = bin2hex(random_bytes(16));
        $listFile = $finalDir . '/concat_' . (int)$episode['id'] . '_' . $artifactId . '.txt';
        $outFile = $finalDir . '/episode_' . (int)$episode['episode_no'] . '_' . $artifactId . '.mp4';

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
        $ffmpeg = escapeshellarg((string)self::ffmpegBinary());

        // 先试流复制（同 provider 同参数片段通常可直接拼），失败再重编码统一规格
        $copyCmd = $ffmpeg . ' -y -f concat -safe 0 -i ' . escapeshellarg($listFile)
            . ' -c copy -an ' . escapeshellarg($outFile) . ' 2>&1';
        $ok = self::runFfmpeg($copyCmd, $outFile);

        if (!$ok) {
            @unlink($outFile);
            $filter = sprintf(
                'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1',
                $w, $h, $w, $h
            );
            $reCmd = $ffmpeg . ' -y -f concat -safe 0 -i ' . escapeshellarg($listFile)
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
        DramaTaskRepository::commitResult($task, static function () use ($project, $episode, $snapshot, $rel): array {
            $freshEpisode = DB::fetch('SELECT * FROM drama_episodes WHERE id=? FOR UPDATE', [(int)$episode['id']]);
            if (!$freshEpisode || (int)$freshEpisode['project_id'] !== (int)$project['id']) {
                throw new RuntimeException('剧集不存在或已变更');
            }
            if (self::compositionSnapshot($project, $episode, true) !== $snapshot) {
                throw new RuntimeException('分镜集合或视频已变更，请重新合成');
            }
            DB::update('drama_episodes', [
                'final_video_path' => $rel,
                'status'           => 'completed',
            ], 'id=?', [(int)$episode['id']]);
            // Outputs are immutable; stale/ambiguous publishers never delete shared media.
            return ['episode_id' => (int)$episode['id'], 'final_video_path' => $rel];
        });
        return $rel;
    }

    /** Complete, ordered input manifest. Used by the API and again by the worker. */
    public static function compositionSnapshot(array $project, array $episode, bool $lock = false): array
    {
        $shots = DB::fetchAll(
            'SELECT * FROM drama_shots WHERE episode_id=? ORDER BY shot_no ASC' . ($lock ? ' FOR UPDATE' : ''),
            [(int)$episode['id']]
        );
        if (!$shots) throw new RuntimeException('没有可合成的分镜');
        $root = realpath(DramaService::projectStorageDir((int)$project['id']));
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $snapshot = [];
        foreach ($shots as $index => $shot) {
            if ((int)$shot['shot_no'] !== $index + 1 || (string)$shot['status'] !== 'video_done'
                || (string)($shot['video_path'] ?? '') === '') {
                throw new RuntimeException('分镜视频尚未全部就绪，不能合成不完整剧集');
            }
            $path = str_replace('\\', '/', (string)$shot['video_path']);
            $absolute = realpath($base . '/' . ltrim($path, '/'));
            if ($root === false || $absolute === false || !is_file($absolute) || filesize($absolute) <= 0
                || !str_starts_with(str_replace('\\', '/', $absolute), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
                throw new RuntimeException('分镜 ' . (int)$shot['shot_no'] . ' 的视频文件缺失或不在项目目录');
            }
            $hash = hash_file('sha256', $absolute);
            if ($hash === false) throw new RuntimeException('分镜视频读取失败');
            $snapshot[] = [
                'id' => (int)$shot['id'], 'shot_no' => (int)$shot['shot_no'],
                'video_path' => $path, 'image_path' => (string)($shot['image_path'] ?? ''),
                'video_prompt' => (string)($shot['video_prompt'] ?? ''),
                'duration' => (int)$shot['duration'], 'sha256' => $hash, 'absolute_path' => $absolute,
            ];
        }
        return $snapshot;
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
        $code = self::runCommand($cmd, $out);
        if ($code !== 0) {
            error_log('DramaComposer ffmpeg failed (code ' . $code . '): ' . mb_substr(implode("\n", $out), 0, 800));
        }
        return $code === 0 && is_file($outFile) && filesize($outFile) > 0;
    }

    /**
     * 返回可执行的 FFmpeg 路径。除 PATH 外兼容 Windows 常见安装位置，
     * 并允许通过 FFMPEG_BINARY 环境变量或 CFG_FFMPEG_BINARY 常量显式指定。
     */
    private static function ffmpegBinary(): ?string
    {
        static $resolved = false;
        static $binary = null;
        if ($resolved) return $binary;
        $resolved = true;

        $candidates = [];
        if (defined('CFG_FFMPEG_BINARY') && trim((string)CFG_FFMPEG_BINARY) !== '') {
            $candidates[] = trim((string)CFG_FFMPEG_BINARY);
        }
        $fromEnv = trim((string)getenv('FFMPEG_BINARY'));
        if ($fromEnv !== '') $candidates[] = $fromEnv;
        $candidates[] = 'ffmpeg';
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe';
            $candidates[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
        } else {
            $candidates[] = '/usr/bin/ffmpeg';
            $candidates[] = '/usr/local/bin/ffmpeg';
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate !== 'ffmpeg' && !is_file($candidate)) continue;
            $out = [];
            if (self::runCommand(escapeshellarg($candidate) . ' -version 2>&1', $out) === 0) {
                return $binary = $candidate;
            }
        }
        return null;
    }

    /** @param string[] $output */
    private static function runCommand(string $command, array &$output): int
    {
        $output = [];
        if (function_exists('exec')) {
            $code = 1;
            @exec($command, $output, $code);
            return (int)$code;
        }
        if (!function_exists('proc_open')) return 127;

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
        if (!is_resource($process)) return 127;

        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $captured = '';
        $exitCode = -1;
        do {
            foreach ($pipes as $pipe) {
                $chunk = stream_get_contents($pipe);
                if ($chunk !== false && $chunk !== '') $captured .= $chunk;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                break;
            }
            usleep(10000);
        } while (true);

        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);
            if ($chunk !== false && $chunk !== '') $captured .= $chunk;
            fclose($pipe);
        }
        $closeCode = proc_close($process);
        if ($closeCode >= 0) $exitCode = $closeCode;
        $output = preg_split('/\r?\n/', trim($captured)) ?: [];
        return $exitCode >= 0 ? $exitCode : 1;
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
