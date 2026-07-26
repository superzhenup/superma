<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/ai.php';

/**
 * 漫剧模块的安全 HTTP 客户端（SSRF 防护与 cover_actions.php 同款策略）：
 * 仅 HTTPS、禁内网/凭据、DNS 解析结果固定（CURLOPT_RESOLVE 防重绑定）、
 * 响应/下载大小上限。图片与视频 API 调用、媒体下载统一走这里。
 */
final class DramaMediaClient
{
    public const MAX_API_RESPONSE_BYTES = 12 * 1024 * 1024;
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    public const MAX_VIDEO_BYTES = 100 * 1024 * 1024;

    private static function isPrivateIpAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || $ip === '::1'
                || str_starts_with(strtolower($ip), 'fc')
                || str_starts_with(strtolower($ip), 'fd')
                || str_starts_with(strtolower($ip), 'fe80:');
        }
        return true;
    }

    /** @return string[] 公网 IP 列表 */
    public static function assertPublicHost(string $host): array
    {
        $hostLower = strtolower(rtrim($host, '.'));
        if ($hostLower === 'localhost' || str_ends_with($hostLower, '.localhost')) {
            throw new RuntimeException('不允许访问本地地址');
        }
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!$records) {
            $ip = gethostbyname($host);
            $records = $ip && $ip !== $host ? [['ip' => $ip]] : [];
        }
        if (!$records) {
            throw new RuntimeException('无法解析媒体服务地址');
        }
        $publicIps = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if ($ip === '' || self::isPrivateIpAddress($ip)) {
                throw new RuntimeException('不允许访问内网地址');
            }
            $publicIps[] = $ip;
        }
        return array_values(array_unique($publicIps));
    }

    /**
     * 校验 HTTPS 公网端点，返回 CURLOPT_RESOLVE 条目固定解析结果。
     * @return array{host:string,port:int,resolve:string[]}
     */
    public static function assertSafeEndpoint(string $url): array
    {
        $parsed = parse_url($url);
        if (
            !$parsed
            || empty($parsed['host'])
            || strtolower((string)($parsed['scheme'] ?? '')) !== 'https'
            || isset($parsed['user'])
            || isset($parsed['pass'])
        ) {
            throw new RuntimeException('媒体服务 URL 必须为 HTTPS 协议且不能包含凭据');
        }
        $host = (string)$parsed['host'];
        $port = (int)($parsed['port'] ?? 443);
        if ($port < 1 || $port > 65535) throw new RuntimeException('媒体服务端口无效');
        $ips = self::assertPublicHost($host);
        $resolve = [];
        foreach ($ips as $ip) {
            $curlIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $resolve[] = "{$host}:{$port}:{$curlIp}";
        }
        return ['host' => $host, 'port' => $port, 'resolve' => $resolve];
    }

    /**
     * JSON API 请求（payload 为 null 时 GET）。
     * @return array{body:string,status:int}
     */
    public static function requestJson(string $endpoint, array $headers, ?array $payload, int $timeout = 180, int $maxBytes = self::MAX_API_RESPONSE_BYTES): array
    {
        $security = self::assertSafeEndpoint($endpoint);
        $response = '';
        $bytes = 0;
        $tooLarge = false;

        $options = [
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => min(30, $timeout),
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE         => $security['resolve'],
            CURLOPT_WRITEFUNCTION   => function ($ch, string $chunk) use (&$response, &$bytes, &$tooLarge, $maxBytes) {
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($caBundle = AIClient::caBundle()) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) throw new RuntimeException('媒体服务响应超过安全大小限制');
        if ($ok === false || $error !== '') throw new RuntimeException('媒体服务连接失败');
        return ['body' => $response, 'status' => $status];
    }

    /**
     * 下载远程 HTTPS 媒体到本地（校验 MIME 白名单，原子落盘）。
     * @param string $destBasePath 无扩展名的目标路径
     * @return string 实际落盘路径（含扩展名）
     */
    public static function downloadMedia(string $url, string $destBasePath, array $allowedMimes, int $maxBytes): string
    {
        $security = self::assertSafeEndpoint($url);
        $content = false;
        $bytes = 0;

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'DramaMediaClient/1.0',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => $security['resolve'],
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$content, &$bytes, $maxBytes) {
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) return 0;
                $content = ($content === false ? '' : $content) . $chunk;
                return strlen($chunk);
            },
        ];
        if ($caBundle = AIClient::caBundle()) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $content === '' || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('下载媒体文件失败');
        }
        return self::saveCheckedMedia($content, $destBasePath, $allowedMimes, $maxBytes);
    }

    /**
     * 校验字节内容 MIME 并原子落盘。
     * @return string 实际落盘路径（含扩展名）
     */
    public static function saveCheckedMedia(string $content, string $destBasePath, array $allowedMimes, int $maxBytes): string
    {
        if ($content === '' || strlen($content) > $maxBytes) {
            throw new RuntimeException('媒体文件超过大小限制');
        }
        $tmpPath = $destBasePath . '.tmp';
        $written = @file_put_contents($tmpPath, $content, LOCK_EX);
        if ($written === false || $written !== strlen($content)) {
            @unlink($tmpPath);
            throw new RuntimeException('媒体文件保存失败，请检查 storage 目录权限');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
        if (!in_array($mime, $allowedMimes, true)) {
            @unlink($tmpPath);
            throw new RuntimeException('媒体文件类型不被允许：' . $mime);
        }
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default      => 'bin',
        };
        if ($extension === 'bin') {
            @unlink($tmpPath);
            throw new RuntimeException('无法识别的媒体文件类型');
        }
        $destPath = $destBasePath . '.' . $extension;
        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            throw new RuntimeException('媒体文件落盘失败');
        }
        return $destPath;
    }
}
