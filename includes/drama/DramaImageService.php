<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/ImageGenerationProtocol.php';
require_once __DIR__ . '/DramaMediaClient.php';

/**
 * 漫剧分镜图/资产图生成服务：读取系统 image_gen_* 配置（与封面共用一套引擎），
 * 走 ImageGenerationProtocol 协议层生成图片并落盘到 storage/drama。
 */
final class DramaImageService
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function isConfigured(): bool
    {
        return getSystemSetting('image_gen_api_url', '') !== ''
            && getSystemSetting('image_gen_api_key', '') !== '';
    }

    /**
     * 生成一张图并保存。
     *
     * @param string $destDir      绝对路径目录（需已存在）
     * @param string $filenameBase 无扩展名文件名
     * @return string 相对路径（storage/drama/...）
     */
    public static function generate(string $prompt, string $size, string $destDir, string $filenameBase): string
    {
        @set_time_limit(300);

        $apiUrl = (string)getSystemSetting('image_gen_api_url', '');
        $apiKey = (string)getSystemSetting('image_gen_api_key', '');
        $model  = (string)getSystemSetting('image_gen_model', 'gpt-image-2');
        if ($apiUrl === '' || $apiKey === '') {
            throw new RuntimeException('请先在模型设置中配置图片生成引擎');
        }
        if (!preg_match('/^(\d{2,4})x(\d{2,4})$/', $size)) {
            throw new RuntimeException('图片尺寸格式无效');
        }

        $storedMode = (string)getSystemSetting('image_gen_api_mode', '');
        $mode = ImageGenerationProtocol::normalizeMode($storedMode, $storedMode === '' && $apiUrl !== '');
        $endpoint = ImageGenerationProtocol::endpoint(ImageGenerationProtocol::normalizeBaseUrl($apiUrl), $mode);

        $payload = ImageGenerationProtocol::buildPayload($mode, $model, $prompt, $size);
        $resp = DramaMediaClient::requestJson(
            $endpoint,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            $payload,
            240
        );
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            error_log("Drama image provider rejected: HTTP {$resp['status']}, mode={$mode}");
            throw new RuntimeException("图片生成服务返回 HTTP {$resp['status']}");
        }

        $result = ImageGenerationProtocol::extractResult($resp['body']);
        $destBase = rtrim($destDir, '/\\') . '/' . $filenameBase;

        if ($result['b64_json']) {
            if (strlen($result['b64_json']) > (int)ceil(DramaMediaClient::MAX_IMAGE_BYTES * 4 / 3) + 4096) {
                throw new RuntimeException('base64 图片超过大小限制');
            }
            $content = base64_decode($result['b64_json'], true);
            if ($content === false) throw new RuntimeException('base64 解码失败');
            $absPath = DramaMediaClient::saveCheckedMedia($content, $destBase, self::IMAGE_MIMES, DramaMediaClient::MAX_IMAGE_BYTES);
        } elseif ($result['url']) {
            $absPath = DramaMediaClient::downloadMedia($result['url'], $destBase, self::IMAGE_MIMES, DramaMediaClient::MAX_IMAGE_BYTES);
        } else {
            error_log('Drama image: unexpected response shape, mode=' . $mode . ', bytes=' . strlen($resp['body']));
            throw new RuntimeException('未能从图片生成响应中提取图片');
        }

        return self::toRelativePath($absPath);
    }

    /** 绝对路径 → 项目相对路径（storage/drama/...）。 */
    public static function toRelativePath(string $absPath): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $abs = str_replace('\\', '/', $absPath);
        $prefix = str_replace('\\', '/', $base) . '/';
        if (str_starts_with($abs, $prefix)) {
            return substr($abs, strlen($prefix));
        }
        return basename($absPath);
    }
}
