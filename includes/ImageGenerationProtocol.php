<?php

defined('APP_LOADED') or die('Direct access denied.');

/**
 * 图片生成供应商协议适配器。
 *
 * - images：OpenAI-compatible POST /images/generations
 * - chat_image：历史供应商通过 Chat Completions 文本/SSE 返回图片 URL
 */
final class ImageGenerationProtocol
{
    public const MODE_IMAGES = 'images';
    public const MODE_CHAT_IMAGE = 'chat_image';

    public static function normalizeMode(string $mode, bool $legacyConfigured = false): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, [self::MODE_IMAGES, self::MODE_CHAT_IMAGE], true)) {
            return $mode;
        }
        // 已存在但没有 mode 字段的旧配置继续走历史 Chat 协议；全新配置默认 Images API。
        return $legacyConfigured ? self::MODE_CHAT_IMAGE : self::MODE_IMAGES;
    }

    public static function normalizeBaseUrl(string $apiUrl): string
    {
        $apiUrl = rtrim(trim($apiUrl), '/');
        return (string)preg_replace(
            '#/(?:images/generations|chat/completions|models)$#i',
            '',
            $apiUrl
        );
    }

    public static function endpoint(string $apiUrl, string $mode): string
    {
        $base = self::normalizeBaseUrl($apiUrl);
        $mode = self::normalizeMode($mode);
        return $base . ($mode === self::MODE_CHAT_IMAGE ? '/chat/completions' : '/images/generations');
    }

    public static function modelsEndpoint(string $apiUrl): string
    {
        return self::normalizeBaseUrl($apiUrl) . '/models';
    }

    public static function buildPayload(string $mode, string $model, string $prompt, string $size): array
    {
        $mode = self::normalizeMode($mode);
        if ($mode === self::MODE_CHAT_IMAGE) {
            return [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 4096,
                // 保持旧供应商的 SSE 行为，响应解析器同时兼容非流式 JSON。
                'stream' => true,
            ];
        }

        // GPT Image 默认返回 b64_json；DALL-E 可能返回 URL。不要强制 response_format，
        // 因为该参数并非所有 GPT Image-compatible 服务都接受。
        return [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'n' => 1,
        ];
    }

    /** @return array{url:?string,b64_json:?string} */
    public static function extractResult(string $response): array
    {
        $found = ['url' => null, 'b64_json' => null];
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            self::extractFromDecoded($decoded, $found);
            if ($found['url'] !== null || $found['b64_json'] !== null) return $found;
        }

        // 历史 Chat 图片服务可能返回 SSE；逐块提取 URL/base64，并累积文本内容。
        $text = '';
        foreach (preg_split('/\r?\n/', $response) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) continue;
            $raw = trim(substr($line, 5));
            if ($raw === '' || $raw === '[DONE]') continue;
            $chunk = json_decode($raw, true);
            if (!is_array($chunk)) continue;
            self::extractFromDecoded($chunk, $found);
            $delta = $chunk['choices'][0]['delta']['content'] ?? '';
            if (is_string($delta)) $text .= $delta;
        }
        if ($found['url'] === null && $found['b64_json'] === null && $text !== '') {
            self::extractFromContent($text, $found);
        }
        return $found;
    }

    /** @param array{url:?string,b64_json:?string} $found */
    private static function extractFromDecoded(array $data, array &$found): void
    {
        // OpenAI Images API：顶层 data[0].b64_json / data[0].url。
        $image = $data['data'][0] ?? null;
        if (is_array($image)) self::extractFromContent($image, $found);

        $message = $data['choices'][0]['message'] ?? null;
        if (is_array($message)) {
            self::extractFromContent($message['content'] ?? null, $found);
            self::extractFromContent($message['images'] ?? null, $found);
        }

        $delta = $data['choices'][0]['delta'] ?? null;
        if (is_array($delta)) {
            self::extractFromContent($delta['content'] ?? null, $found);
            self::extractFromContent($delta['images'] ?? null, $found);
        }
    }

    /** @param array{url:?string,b64_json:?string} $found */
    private static function extractFromContent(mixed $content, array &$found): void
    {
        if ($content === null || $found['b64_json'] !== null) return;

        if (is_array($content)) {
            if (isset($content['b64_json']) && is_string($content['b64_json']) && $content['b64_json'] !== '') {
                $found['b64_json'] = $content['b64_json'];
                return;
            }
            $url = $content['url'] ?? null;
            if (isset($content['image_url'])) {
                $url = is_array($content['image_url'])
                    ? ($content['image_url']['url'] ?? $url)
                    : $content['image_url'];
            }
            if (is_string($url) && $url !== '') {
                self::extractFromContent($url, $found);
                if ($found['url'] !== null || $found['b64_json'] !== null) return;
            }
            foreach ($content as $item) {
                if (is_array($item) || is_string($item)) {
                    self::extractFromContent($item, $found);
                    if ($found['url'] !== null || $found['b64_json'] !== null) return;
                }
            }
            return;
        }

        if (!is_string($content) || $content === '') return;
        if (preg_match('#data:image/[a-zA-Z0-9.+-]+;base64,([a-zA-Z0-9+/=\r\n]+)#', $content, $m)) {
            $found['b64_json'] = preg_replace('/\s+/', '', $m[1]);
            return;
        }
        if (preg_match('/!\[[^\]]*\]\((https:\/\/[^\s\)]+)\)/i', $content, $m)) {
            $found['url'] = $m[1];
            return;
        }
        if (preg_match('#^https://\S+$#i', trim($content))) {
            $found['url'] = trim($content);
        }
    }
}

