<?php

defined('APP_LOADED') or die('Direct access denied.');

/**
 * 视频生成供应商协议适配器（漫剧工作流 v1.8）。
 *
 * 统一「异步任务型」视频 API 生命周期：
 *   submit  → 提交 i2v 任务，返回 provider 侧 task_id
 *   query   → 轮询任务状态，返回 {status, video_url?}
 *
 * v1 内置 provider：volcengine_seedance / kling / vidu / wanx。
 * 扩展点（v2 本地 ComfyUI）：在 PROVIDERS 注册新 provider 并实现
 * buildSubmitPayload / extractTaskId / extractQueryResult 三个分支即可，
 * 上层（DramaShotRunner）无感知。
 */
final class VideoGenerationProtocol
{
    public const PROVIDERS = [
        'volcengine_seedance' => [
            'label'        => '即梦 Seedance（火山方舟）',
            'default_base' => 'https://ark.cn-beijing.volces.com/api/v3',
        ],
        'kling' => [
            'label'        => '可灵 Kling',
            'default_base' => 'https://api.klingai.com',
        ],
        'vidu' => [
            'label'        => 'Vidu',
            'default_base' => 'https://api.vidu.cn',
        ],
        'wanx' => [
            'label'        => '通义万相（DashScope）',
            'default_base' => 'https://dashscope.aliyuncs.com',
        ],
    ];

    public static function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        return isset(self::PROVIDERS[$provider]) ? $provider : 'volcengine_seedance';
    }

    public static function providerLabel(string $provider): string
    {
        $provider = self::normalizeProvider($provider);
        return self::PROVIDERS[$provider]['label'];
    }

    public static function defaultBaseUrl(string $provider): string
    {
        $provider = self::normalizeProvider($provider);
        return self::PROVIDERS[$provider]['default_base'];
    }

    public static function normalizeBaseUrl(string $apiUrl): string
    {
        return rtrim(trim($apiUrl), '/');
    }

    public static function normalizeRatio(string $ratio): string
    {
        $ratio = trim($ratio);
        return in_array($ratio, ['16:9', '9:16', '1:1'], true) ? $ratio : '16:9';
    }

    public static function normalizeDuration(int $seconds): int
    {
        // 各 provider 常见档位 5/10 秒，收敛到 3~12 区间
        return max(3, min(12, $seconds));
    }

    // ---------------------------------------------------------------- submit

    public static function submitEndpoint(string $provider, string $baseUrl): string
    {
        $base = self::normalizeBaseUrl($baseUrl);
        return match (self::normalizeProvider($provider)) {
            'volcengine_seedance' => $base . '/contents/generations/tasks',
            'kling'               => $base . '/v1/videos/image2video',
            'vidu'                => $base . '/ent/v2/img2video',
            'wanx'                => $base . '/api/v1/services/aigc/video-generation/video-synthesis',
        };
    }

    /**
     * @param string $imageB64 首帧图 base64（无 data: 前缀）
     */
    public static function buildSubmitPayload(
        string $provider,
        string $model,
        string $prompt,
        string $imageB64,
        string $imageMime,
        int $duration,
        string $ratio
    ): array {
        $provider = self::normalizeProvider($provider);
        $duration = self::normalizeDuration($duration);
        $ratio = self::normalizeRatio($ratio);
        $mime = preg_match('#^image/(jpeg|png|webp)$#i', $imageMime) ? strtolower($imageMime) : 'image/png';
        $dataUri = 'data:' . $mime . ';base64,' . $imageB64;

        return match ($provider) {
            'volcengine_seedance' => [
                'model' => $model,
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                ],
                'duration' => $duration,
                'ratio' => $ratio,
                'generate_audio' => false,
            ],
            'kling' => [
                'model_name' => $model,
                'image' => $imageB64,
                'prompt' => $prompt,
                'duration' => (string)$duration,
                'aspect_ratio' => $ratio,
            ],
            'vidu' => [
                'model' => $model,
                'images' => [$dataUri],
                'prompt' => $prompt,
                'duration' => $duration,
                'aspect_ratio' => $ratio,
            ],
            'wanx' => [
                'model' => $model,
                'input' => [
                    'prompt' => $prompt,
                    'img_url' => $dataUri,
                ],
                'parameters' => [
                    'duration' => $duration,
                    'prompt_extend' => true,
                ],
            ],
        };
    }

    /** @return string[] */
    public static function submitHeaders(string $provider, string $apiKey): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        if (self::normalizeProvider($provider) === 'wanx') {
            $headers[] = 'X-DashScope-Async: enable';
        }
        return $headers;
    }

    /** 从 submit 响应提取 provider 侧 task_id。 */
    public static function extractTaskId(string $provider, string $responseBody): string
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('视频服务响应不是有效 JSON');
        }
        $taskId = match (self::normalizeProvider($provider)) {
            'volcengine_seedance' => (string)($decoded['id'] ?? ''),
            'kling'               => (string)($decoded['data']['task_id'] ?? ''),
            'vidu'                => (string)($decoded['task_id'] ?? ''),
            'wanx'                => (string)($decoded['output']['task_id'] ?? ''),
        };
        if ($taskId === '') {
            throw new RuntimeException('未能从视频服务响应中提取 task_id');
        }
        return $taskId;
    }

    // ----------------------------------------------------------------- query

    public static function queryEndpoint(string $provider, string $baseUrl, string $taskId): string
    {
        $base = self::normalizeBaseUrl($baseUrl);
        $taskId = rawurlencode($taskId);
        return match (self::normalizeProvider($provider)) {
            'volcengine_seedance' => $base . '/contents/generations/tasks/' . $taskId,
            'kling'               => $base . '/v1/videos/image2video/' . $taskId,
            'vidu'                => $base . '/ent/v2/tasks/' . $taskId . '/creations',
            'wanx'                => $base . '/api/v1/tasks/' . $taskId,
        };
    }

    /** 轮询一律 GET，无请求体。 */
    public static function queryHeaders(string $provider, string $apiKey): array
    {
        return ['Authorization: Bearer ' . $apiKey];
    }

    /**
     * @return array{status:string,video_url:?string,error:?string}
     *         status ∈ pending / running / done / failed
     */
    public static function extractQueryResult(string $provider, string $responseBody): array
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('视频轮询响应不是有效 JSON');
        }
        $out = ['status' => 'pending', 'video_url' => null, 'error' => null];

        switch (self::normalizeProvider($provider)) {
            case 'volcengine_seedance':
                $raw = strtolower((string)($decoded['status'] ?? ''));
                if ($raw === 'succeeded') {
                    $out['status'] = 'done';
                    $out['video_url'] = $decoded['content']['video_url'] ?? null;
                } elseif (in_array($raw, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
                    $out['status'] = 'failed';
                    $out['error'] = (string)($decoded['error']['message'] ?? 'provider failed');
                } else {
                    $out['status'] = 'running';
                }
                break;

            case 'kling':
                $raw = strtolower((string)($decoded['data']['task_status'] ?? ''));
                if ($raw === 'succeed') {
                    $out['status'] = 'done';
                    $out['video_url'] = $decoded['data']['task_result']['videos'][0]['url'] ?? null;
                } elseif (in_array($raw, ['failed'], true)) {
                    $out['status'] = 'failed';
                    $out['error'] = (string)($decoded['data']['task_status_msg'] ?? 'provider failed');
                } else {
                    $out['status'] = 'running';
                }
                break;

            case 'vidu':
                $raw = strtolower((string)($decoded['state'] ?? ''));
                if ($raw === 'success') {
                    $out['status'] = 'done';
                    $out['video_url'] = $decoded['creations'][0]['url'] ?? null;
                } elseif (in_array($raw, ['failed'], true)) {
                    $out['status'] = 'failed';
                    $out['error'] = (string)($decoded['err_msg'] ?? $decoded['err_code'] ?? 'provider failed');
                } else {
                    $out['status'] = 'running';
                }
                break;

            case 'wanx':
                $raw = strtoupper((string)($decoded['output']['task_status'] ?? ''));
                if ($raw === 'SUCCEEDED') {
                    $out['status'] = 'done';
                    $out['video_url'] = $decoded['output']['video_url'] ?? null;
                } elseif (in_array($raw, ['FAILED', 'CANCELED', 'UNKNOWN'], true)) {
                    $out['status'] = 'failed';
                    $out['error'] = (string)($decoded['output']['message'] ?? 'provider failed');
                } else {
                    $out['status'] = 'running';
                }
                break;
        }

        if ($out['status'] === 'done' && (!$out['video_url'] || !preg_match('#^https://#i', (string)$out['video_url']))) {
            $out['status'] = 'failed';
            $out['error'] = '视频服务未返回有效 HTTPS 地址';
            $out['video_url'] = null;
        }
        return $out;
    }
}
