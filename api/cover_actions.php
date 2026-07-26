<?php
/**
 * 封面图片 API
 * 支持上传封面图片、AI生成封面图片（基于 gpt-image-2）、删除封面
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
require_once dirname(__DIR__) . '/includes/managed_files.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/ImageGenerationProtocol.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

const COVER_MAX_BYTES = 5 * 1024 * 1024;
const COVER_MAX_API_RESPONSE_BYTES = 12 * 1024 * 1024;

function isPrivateIpAddress(string $ip): bool {
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

function assertPublicImageHost(string $host): array {
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
        throw new RuntimeException('无法解析图片地址');
    }
    $publicIps = [];
    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? '';
        if ($ip === '' || isPrivateIpAddress($ip)) {
            throw new RuntimeException('不允许访问内网地址');
        }
        $publicIps[] = $ip;
    }
    return array_values(array_unique($publicIps));
}

/**
 * 校验 HTTPS 公网端点，并返回 CURLOPT_RESOLVE 条目以固定本次解析结果，
 * 防止校验后到连接前发生 DNS 重绑定。
 */
function assertSafeImageApiEndpoint(string $endpoint): array {
    $parsed = parse_url($endpoint);
    if (
        !$parsed
        || empty($parsed['host'])
        || strtolower((string)($parsed['scheme'] ?? '')) !== 'https'
        || isset($parsed['user'])
        || isset($parsed['pass'])
        || isset($parsed['query'])
        || isset($parsed['fragment'])
    ) {
        throw new RuntimeException('图片生成 API URL 必须为 HTTPS 协议且不能包含凭据、查询串或片段');
    }

    $host = (string)$parsed['host'];
    $port = (int)($parsed['port'] ?? 443);
    if ($port < 1 || $port > 65535) throw new RuntimeException('图片生成 API 端口无效');
    $ips = assertPublicImageHost($host);
    $resolve = [];
    foreach ($ips as $ip) {
        $curlIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $resolve[] = "{$host}:{$port}:{$curlIp}";
    }
    return ['host' => $host, 'port' => $port, 'resolve' => $resolve];
}

function normalizeImageGenerationSize(string $size): string {
    $size = strtolower(trim($size));
    if ($size === 'auto') return $size;
    if (!preg_match('/^(\d{2,4})x(\d{2,4})$/', $size, $m)) {
        throw new RuntimeException('图片生成尺寸格式无效');
    }
    $width = (int)$m[1];
    $height = (int)$m[2];
    if ($width < 256 || $height < 256 || $width > 4096 || $height > 4096) {
        throw new RuntimeException('图片生成尺寸必须在 256×256 到 4096×4096 之间');
    }
    return $size;
}

function getConfiguredImageApiMode(string $apiUrl): string {
    $stored = (string)getSystemSetting('image_gen_api_mode', '');
    return ImageGenerationProtocol::normalizeMode($stored, $stored === '' && $apiUrl !== '');
}

/** @return array{body:string,status:int} */
function requestImageApi(string $endpoint, string $apiKey, ?array $payload, int $timeout): array {
    $security = assertSafeImageApiEndpoint($endpoint);
    $response = '';
    $bytes = 0;
    $tooLarge = false;

    $ch = curl_init($endpoint);
    $options = [
        CURLOPT_RETURNTRANSFER  => false,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_CONNECTTIMEOUT  => min(30, $timeout),
        CURLOPT_HTTPHEADER      => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE         => $security['resolve'],
        CURLOPT_WRITEFUNCTION   => function ($ch, string $chunk) use (&$response, &$bytes, &$tooLarge) {
            $bytes += strlen($chunk);
            if ($bytes > COVER_MAX_API_RESPONSE_BYTES) {
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
        $options[CURLOPT_HTTPHEADER] = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
    }
    if ($caBundle = AIClient::caBundle()) {
        $options[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($tooLarge) throw new RuntimeException('图片生成 API 响应超过安全大小限制');
    if ($ok === false || $error !== '') throw new RuntimeException('图片生成 API 连接失败');
    return ['body' => $response, 'status' => $status];
}

function coverSafeErrorMessage(Throwable $e): string {
    $message = $e->getMessage();
    $exact = ['无效的操作', '缺少小说ID', '小说不存在', '文件超过 10MB 限制', '仅支持 JPG、PNG、WebP 格式'];
    if (in_array($message, $exact, true)) return $message;
    foreach ([
        '图片生成 API URL', '图片生成 API 端口', '图片生成 API 连接失败',
        '图片生成 API 响应', '图片生成尺寸', '图片生成端点模式',
        '图片 API 鉴权探针返回 HTTP', '图片生成服务返回 HTTP',
        '未能从 ', '请先填写或保存 API', '请先在模型设置中配置图片生成引擎',
        'API Key 不可提交掩码占位符',
    ] as $prefix) {
        if (str_starts_with($message, $prefix)) return mb_substr($message, 0, 240);
    }
    return '操作失败，请稍后重试';
}

function deleteManagedCover(?string $storedPath): void {
    deleteManagedRelativeFile($storedPath, 'storage/covers');
}

/** Validate raw image bytes and atomically move them to a MIME-matching path. */
function saveCheckedImageContent(string $content, string $destBasePath): string {
    if ($content === '' || strlen($content) > COVER_MAX_BYTES) {
        throw new RuntimeException('图片超过 5MB 限制');
    }
    $tmpPath = $destBasePath . '.tmp';
    $written = @file_put_contents($tmpPath, $content, LOCK_EX);
    if ($written === false || $written !== strlen($content)) {
        @unlink($tmpPath);
        throw new RuntimeException('图片保存失败，请检查 storage/covers 目录权限');
    }
    $imgCheck = @getimagesize($tmpPath);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!$imgCheck || !in_array($imgCheck['mime'] ?? '', $allowedMimes, true)) {
        @unlink($tmpPath);
        throw new RuntimeException('文件不是有效的图片');
    }
    $extension = match ($imgCheck['mime']) {
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'png',
    };
    $destPath = $destBasePath . '.' . $extension;
    if (!@rename($tmpPath, $destPath)) {
        @unlink($tmpPath);
        throw new RuntimeException('图片临时文件落盘失败，请检查 storage/covers 目录权限');
    }
    return $destPath;
}

// 兼容三种请求方式：FormData($_POST)、JSON body、GET 参数
$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_POST['action'] ?? $jsonInput['action'] ?? $_GET['action'] ?? '';
if ($action !== 'get_image_api_config') {
    requireHttpMethod('POST');
}

try {
    switch ($action) {
        case 'upload':
            uploadCover();
            break;
        case 'generate':
            generateCover($jsonInput);
            break;
        case 'delete':
            deleteCover($jsonInput);
            break;
        case 'get_image_api_config':
            getImageApiConfig();
            break;
        case 'save_image_api_config':
            saveImageApiConfig($jsonInput);
            break;
        case 'test_image_api_config':
            testImageApiConfig($jsonInput);
            break;
        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    error_log('cover_actions error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $safeMsg = coverSafeErrorMessage($e);
    echo json_encode(safe_api_error_payload($e, $safeMsg), JSON_UNESCAPED_UNICODE);
}

/**
 * 上传封面图片
 * 接受 JPG/PNG/WebP 格式，自动缩放到 1086x1448
 */
function uploadCover(): void {
    $novelId = (int)($_POST['novel_id'] ?? 0);
    if (!$novelId) throw new RuntimeException('缺少小说ID');

    // 审计 P0（2026-06-12）：归属校验
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    checkNovelOwnership($novelId, $userId);

    $novel = DB::fetch('SELECT id, cover_image FROM novels WHERE id=?', [$novelId]);
    if (!$novel) throw new RuntimeException('小说不存在');

    if (empty($_FILES['cover_file']) || $_FILES['cover_file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['cover_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errors = [
            UPLOAD_ERR_INI_SIZE  => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL   => '文件上传不完整',
            UPLOAD_ERR_NO_FILE   => '没有文件被上传',
        ];
        throw new RuntimeException($errors[$code] ?? '上传失败');
    }

    $file = $_FILES['cover_file'];
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('文件超过 10MB 限制');
    }

    // 验证文件类型
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedTypes)) {
        throw new RuntimeException('仅支持 JPG、PNG、WebP 格式');
    }

    // 确保存储目录存在
    $coversDir = defined('BASE_PATH') ? BASE_PATH . '/storage/covers' : dirname(__DIR__) . '/storage/covers';
    if (!is_dir($coversDir)) {
        if (!@mkdir($coversDir, 0755, true) && !is_dir($coversDir)) {
            throw new RuntimeException('无法创建封面存储目录');
        }
    }

    // 生成文件名
    $ext = match ($mimeType) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default       => 'jpg',
    };
    $filename = 'novel_' . $novelId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $coversDir . '/' . $filename;

    // 使用 GD 库缩放到 1086x1448
    if (function_exists('gd_info')) {
        $srcImage = match ($mimeType) {
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
            default       => imagecreatefromjpeg($file['tmp_name']),
        };
        if (!$srcImage) throw new RuntimeException('无法读取图片');

        $targetW = 1086;
        $targetH = 1448;
        $dstImage = imagecreatetruecolor($targetW, $targetH);

        // 保持透明通道（PNG/WebP）
        if ($mimeType !== 'image/jpeg') {
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
        }

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $targetW, $targetH, imagesx($srcImage), imagesy($srcImage));

        $saved = match ($ext) {
            'png'  => imagepng($dstImage, $destPath, 8),
            'webp' => imagewebp($dstImage, $destPath, 85),
            default => imagejpeg($dstImage, $destPath, 90),
        };

        imagedestroy($srcImage);
        imagedestroy($dstImage);
        if (!$saved) {
            @unlink($destPath);
            throw new RuntimeException('封面缩放后保存失败');
        }
    } else {
        // 没有 GD 库，直接移动文件
        // 审计修复 M-04（2026-06-12）：无 GD 时添加 MIME 类型校验
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => '仅支持 JPG/PNG/GIF/WebP 图片格式'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('上传文件落盘失败，请检查 storage/covers 目录权限');
        }
    }

    $relativePath = 'storage/covers/' . $filename;
    try {
        DB::update('novels', ['cover_image' => $relativePath], 'id=?', [$novelId]);
    } catch (Throwable $e) {
        @unlink($destPath);
        throw $e;
    }
    deleteManagedCover($novel['cover_image'] ?? null);

    echo json_encode([
        'ok'   => true,
        'msg'  => '封面上传成功',
        'path' => $relativePath,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * AI 生成封面图片
 * 默认使用 OpenAI-compatible Images API；历史 Chat 图片供应商必须显式选择 chat_image 模式。
 */
function generateCover(array $input): void {
    // 图片生成耗时较长，延长执行时间限制
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    $novelId = (int)($input['novel_id'] ?? 0);
    $keyword = trim($input['keyword'] ?? '');
    $author  = trim($input['author'] ?? '');

    // 审计 P0（2026-06-12）：归属校验
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    checkNovelOwnership($novelId, $userId);

    if (!$novelId) throw new RuntimeException('缺少小说ID');
    if (!$keyword) throw new RuntimeException('请输入封面描述关键词');

    $novel = DB::fetch('SELECT id, title, genre, cover_image FROM novels WHERE id=?', [$novelId]);
    if (!$novel) throw new RuntimeException('小说不存在');

    // 读取图片生成 API 配置
    $apiUrl      = getSystemSetting('image_gen_api_url', '');
    $apiKey      = getSystemSetting('image_gen_api_key', '');
    $modelName   = getSystemSetting('image_gen_model', 'gpt-image-2');
    $size        = normalizeImageGenerationSize((string)getSystemSetting('image_gen_size', '1024x1536'));
    $promptPrefix = getSystemSetting('image_gen_prompt_prefix', '');

    if (empty($apiUrl) || empty($apiKey)) {
        throw new RuntimeException('请先在模型设置中配置图片生成引擎');
    }

    $apiMode = getConfiguredImageApiMode((string)$apiUrl);
    $apiUrl = ImageGenerationProtocol::normalizeBaseUrl((string)$apiUrl);
    $endpoint = ImageGenerationProtocol::endpoint($apiUrl, $apiMode);
    try {
        assertSafeImageApiEndpoint($endpoint);
    } catch (RuntimeException $e) {
        throw new RuntimeException('图片生成 API URL 安全校验失败：' . $e->getMessage());
    }

    // 组合 prompt：自定义前缀 + 作者信息 + 用户关键词
    $defaultPrefix = 'A professional book cover illustration for a novel. Style: high quality, detailed, dramatic lighting.';
    $prefix = $promptPrefix ?: $defaultPrefix;
    $prompt = $prefix . ' ' . $keyword;
    if ($author) {
        $prompt .= ' Author: ' . $author;
    }
    // 限制 prompt 长度
    if (mb_strlen($prompt) > 1000) {
        $prompt = mb_substr($prompt, 0, 1000);
    }

    $payload = ImageGenerationProtocol::buildPayload($apiMode, (string)$modelName, $prompt, $size);
    $apiResponse = requestImageApi($endpoint, (string)$apiKey, $payload, 180);
    $response = $apiResponse['body'];
    $httpCode = $apiResponse['status'];
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("Cover image provider rejected request: HTTP {$httpCode}, mode={$apiMode}");
        throw new RuntimeException("图片生成服务返回 HTTP {$httpCode}");
    }

    $imageResult = ImageGenerationProtocol::extractResult($response);
    $imageUrl = $imageResult['url'];
    $b64Data = $imageResult['b64_json'];

    if (!$imageUrl && !$b64Data) {
        // 不记录供应商原始响应；恶意/异常服务可能回显请求头或其他敏感信息。
        error_log('Cover generate: unexpected response shape, mode=' . $apiMode . ', bytes=' . strlen($response));
        throw new RuntimeException("未能从 {$apiMode} 响应中提取图片，请检查端点模式与模型能力");
    }

    // 确保存储目录存在
    $coversDir = defined('BASE_PATH') ? BASE_PATH . '/storage/covers' : dirname(__DIR__) . '/storage/covers';
    if (!is_dir($coversDir)) {
        if (!@mkdir($coversDir, 0755, true)) {
            throw new RuntimeException('无法创建存储目录：' . $coversDir);
        }
    }

    // 下载并保存图片到本地（必须成功）
    $filenameBase = 'novel_' . $novelId . '_ai_' . time() . '_' . bin2hex(random_bytes(4));
    $destBasePath = $coversDir . '/' . $filenameBase;
    $destPath = '';

    if ($b64Data) {
        if (strlen($b64Data) > (int)ceil(COVER_MAX_BYTES * 4 / 3) + 4096) {
            throw new RuntimeException('base64 图片超过 5MB 限制');
        }
        $imageContent = base64_decode($b64Data, true);
        if ($imageContent === false) throw new RuntimeException('base64 解码失败');
        $destPath = saveCheckedImageContent($imageContent, $destBasePath);
    } else {
        if (!preg_match('#^https://#i', $imageUrl)) {
            throw new RuntimeException('仅支持 HTTPS 协议的图片地址');
        }

        $downloadParts = parse_url($imageUrl);
        $dlHost = (string)($downloadParts['host'] ?? '');
        $dlPort = (int)($downloadParts['port'] ?? 443);
        if (
            !$downloadParts || $dlHost === '' || $dlPort < 1 || $dlPort > 65535
            || strtolower((string)($downloadParts['scheme'] ?? '')) !== 'https'
            || isset($downloadParts['user']) || isset($downloadParts['pass'])
        ) {
            throw new RuntimeException('无法解析图片地址');
        }
        $resolvedIps = assertPublicImageHost($dlHost);

        $imgContent = false;
        $dlHttpCode = 0;
        $dlErr = '';

        if (function_exists('curl_init')) {
            $bytes = 0;
            $dlCh = curl_init($imageUrl);
            // 固定 DNS 解析结果，防止 DNS 重绑定攻击
            $resolveEntries = [];
            foreach ($resolvedIps as $ip) {
                $curlIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
                $resolveEntries[] = "{$dlHost}:{$dlPort}:{$curlIp}";
            }
            $downloadOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXFILESIZE    => COVER_MAX_BYTES,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) CoverDownloader',
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => $resolveEntries,
                CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$imgContent, &$bytes) {
                    $bytes += strlen($chunk);
                    if ($bytes > COVER_MAX_BYTES) return 0;
                    $imgContent = ($imgContent === false ? '' : $imgContent) . $chunk;
                    return strlen($chunk);
                },
            ];
            if ($caBundle = AIClient::caBundle()) {
                $downloadOptions[CURLOPT_CAINFO] = $caBundle;
            }
            curl_setopt_array($dlCh, $downloadOptions);
            curl_exec($dlCh);
            $dlHttpCode = (int)curl_getinfo($dlCh, CURLINFO_HTTP_CODE);
            $dlErr = curl_error($dlCh);
            curl_close($dlCh);
        }

        if (!$imgContent || $dlHttpCode < 200 || $dlHttpCode >= 300) {
            error_log("cover download failed: HTTP {$dlHttpCode}, {$dlErr}, URL: " . parse_url($imageUrl, PHP_URL_HOST));
            throw new RuntimeException('下载图片失败，请稍后重试');
        }

        $destPath = saveCheckedImageContent($imgContent, $destBasePath);
    }

    // 缩放到 1086x1448
    if (function_exists('gd_info')) {
        $imgInfo = @getimagesize($destPath);
        $srcImage = null;
        if ($imgInfo) {
            $mime = $imgInfo['mime'] ?? '';
            $srcImage = match ($mime) {
                'image/png'  => imagecreatefrompng($destPath),
                'image/webp' => imagecreatefromwebp($destPath),
                'image/gif'  => imagecreatefromgif($destPath),
                default       => imagecreatefromjpeg($destPath),
            };
        }
        if (!$srcImage) {
            $srcImage = @imagecreatefrompng($destPath);
            if (!$srcImage) $srcImage = @imagecreatefromjpeg($destPath);
        }
        if ($srcImage) {
            $targetW = 1086;
            $targetH = 1448;
            $dstImage = imagecreatetruecolor($targetW, $targetH);
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $targetW, $targetH, imagesx($srcImage), imagesy($srcImage));
            $pngPath = $destBasePath . '.png';
            if (!imagepng($dstImage, $pngPath, 8)) {
                imagedestroy($srcImage);
                imagedestroy($dstImage);
                @unlink($destPath);
                throw new RuntimeException('封面缩放后保存失败');
            }
            imagedestroy($srcImage);
            imagedestroy($dstImage);
            if ($destPath !== $pngPath) @unlink($destPath);
            $destPath = $pngPath;
        }
    }


    $relativePath = 'storage/covers/' . basename($destPath);
    // 先切换数据库引用，再清理旧文件。若数据库更新失败，新文件只是可清理的
    // 孤儿文件，原封面仍完整可用；不能反过来先删旧封面。
    try {
        DB::update('novels', ['cover_image' => $relativePath], 'id=?', [$novelId]);
    } catch (Throwable $e) {
        @unlink($destPath);
        throw $e;
    }
    deleteManagedCover($novel['cover_image'] ?? null);

    echo json_encode([
        'ok'   => true,
        'msg'  => '封面生成成功',
        'path' => $relativePath,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 删除封面图片
 */
function deleteCover(array $input): void {
    $novelId = (int)($input['novel_id'] ?? 0);
    if (!$novelId) throw new RuntimeException('缺少小说ID');

    // 审计 P0（2026-06-12）：归属校验
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    checkNovelOwnership($novelId, $userId);

    $novel = DB::fetch('SELECT id, cover_image FROM novels WHERE id=?', [$novelId]);
    if (!$novel) throw new RuntimeException('小说不存在');

    if (!empty($novel['cover_image'])) {
        deleteManagedCover($novel['cover_image']);
    }

    DB::update('novels', ['cover_image' => null], 'id=?', [$novelId]);

    echo json_encode(['ok' => true, 'msg' => '封面已删除'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取图片生成 API 配置
 */
function getImageApiConfig(): void {
    $apiUrl = (string)getSystemSetting('image_gen_api_url', '');
    $storedMode = (string)getSystemSetting('image_gen_api_mode', '');
    $mode = ImageGenerationProtocol::normalizeMode($storedMode, $storedMode === '' && $apiUrl !== '');
    $config = [
        'api_url'       => ImageGenerationProtocol::normalizeBaseUrl($apiUrl),
        'api_key'       => getSystemSetting('image_gen_api_key', ''),
        'mode'          => $mode,
        'legacy_mode_inferred' => $storedMode === '' && $apiUrl !== '',
        'model'         => getSystemSetting('image_gen_model', 'gpt-image-2'),
        'size'          => getSystemSetting('image_gen_size', '1024x1536'),
        'prompt_prefix' => getSystemSetting('image_gen_prompt_prefix', ''),
    ];
    // 密钥绝不回填到 input。返回“是否已设置”和只读提示即可，避免 ***abcd
    // 被浏览器再次提交并覆盖真实密钥。
    $config['has_api_key'] = !empty($config['api_key']);
    $config['api_key_hint'] = $config['has_api_key'] ? '末四位 ' . substr($config['api_key'], -4) : '';
    unset($config['api_key']);

    echo json_encode(['ok' => true, 'data' => $config], JSON_UNESCAPED_UNICODE);
}

/**
 * 保存图片生成 API 配置
 */
function saveImageApiConfig(array $input): void {
    // 审计修复 SEC-C1（2026-07-01）：原实现将鉴权放在 if ($uid > 0) 分支内，
    // 构造无 session 请求（uid=0）即可跳过整段鉴权，未授权改写系统级配置。
    // requireLoginApi() 已在文件头完成登录校验，此处只需 isAdmin() 强制管理员权限。
    if (!isAdmin()) {
        throw new RuntimeException('需要管理员权限');
    }

    $apiUrl       = trim($input['api_url'] ?? '');
    $apiKey       = trim($input['api_key'] ?? '');
    $rawMode      = trim((string)($input['mode'] ?? ''));
    $model        = trim($input['model'] ?? 'gpt-image-2');
    $size         = normalizeImageGenerationSize((string)($input['size'] ?? '1024x1536'));
    $promptPrefix = trim($input['prompt_prefix'] ?? '');

    if ($rawMode === '') {
        $currentUrl = (string)getSystemSetting('image_gen_api_url', '');
        $currentMode = (string)getSystemSetting('image_gen_api_mode', '');
        $mode = ImageGenerationProtocol::normalizeMode($currentMode, $currentMode === '' && $currentUrl !== '');
    } elseif (!in_array($rawMode, [ImageGenerationProtocol::MODE_IMAGES, ImageGenerationProtocol::MODE_CHAT_IMAGE], true)) {
        throw new RuntimeException('图片生成端点模式无效');
    } else {
        $mode = $rawMode;
    }

    $apiUrl = ImageGenerationProtocol::normalizeBaseUrl($apiUrl);
    if ($apiUrl !== '') {
        assertSafeImageApiEndpoint(ImageGenerationProtocol::endpoint($apiUrl, $mode));
    }

    $settings = [
        'image_gen_api_url'        => $apiUrl,
        'image_gen_api_mode'       => $mode,
        'image_gen_model'          => $model ?: 'gpt-image-2',
        'image_gen_size'           => $size ?: '1024x1536',
        'image_gen_prompt_prefix'  => $promptPrefix,
    ];

    if ($apiKey !== '' && str_starts_with($apiKey, '***')) {
        throw new RuntimeException('API Key 不可提交掩码占位符；如不修改密钥请留空');
    }
    // 仅在提供了新 key 时更新（避免覆盖为空值）
    if ($apiKey !== '') {
        $settings['image_gen_api_key'] = $apiKey;
    }

    foreach ($settings as $key => $value) {
        $existing = DB::fetch('SELECT setting_key FROM system_settings WHERE setting_key=?', [$key]);
        if ($existing) {
            DB::update('system_settings', ['setting_value' => $value], 'setting_key=?', [$key]);
        } else {
            DB::insert('system_settings', ['setting_key' => $key, 'setting_value' => $value]);
        }
    }
    clearSystemSettingsCache();

    echo json_encode(['ok' => true, 'msg' => '图片生成引擎配置已保存'], JSON_UNESCAPED_UNICODE);
}

/**
 * 由服务端测试图片 API，避免浏览器被 CSP/CORS 拦截，也允许留空使用已保存密钥。
 */
function testImageApiConfig(array $input): void {
    if (!isAdmin()) throw new RuntimeException('需要管理员权限');

    $apiUrl = trim((string)($input['api_url'] ?? ''));
    $apiKey = trim((string)($input['api_key'] ?? ''));
    $rawMode = trim((string)($input['mode'] ?? ''));
    if ($apiUrl === '') $apiUrl = (string)getSystemSetting('image_gen_api_url', '');
    if ($apiKey === '' || str_starts_with($apiKey, '***')) {
        $apiKey = (string)getSystemSetting('image_gen_api_key', '');
    }
    if ($apiUrl === '' || $apiKey === '') {
        throw new RuntimeException('请先填写或保存 API 地址和密钥');
    }

    $storedMode = (string)getSystemSetting('image_gen_api_mode', '');
    if ($rawMode !== '' && !in_array($rawMode, [ImageGenerationProtocol::MODE_IMAGES, ImageGenerationProtocol::MODE_CHAT_IMAGE], true)) {
        throw new RuntimeException('图片生成端点模式无效');
    }
    $mode = $rawMode !== ''
        ? $rawMode
        : ImageGenerationProtocol::normalizeMode($storedMode, $storedMode === '' && $apiUrl !== '');
    $apiUrl = ImageGenerationProtocol::normalizeBaseUrl($apiUrl);
    // 同时校验真正的生成端点与只读 /models 鉴权探针。
    $generationEndpoint = ImageGenerationProtocol::endpoint($apiUrl, $mode);
    assertSafeImageApiEndpoint($generationEndpoint);
    $endpoint = ImageGenerationProtocol::modelsEndpoint($apiUrl);
    $result = requestImageApi($endpoint, $apiKey, null, 30);
    $body = $result['body'];
    $code = $result['status'];

    if ($code < 200 || $code >= 300) {
        error_log("Image API auth probe failed: HTTP {$code}, mode={$mode}");
        throw new RuntimeException("图片 API 鉴权探针返回 HTTP {$code}");
    }

    $suffix = $mode === ImageGenerationProtocol::MODE_IMAGES ? '/images/generations' : '/chat/completions';
    echo json_encode([
        'ok' => true,
        'msg' => "连接与鉴权成功；生成时将使用 {$suffix}（本次测试未生成图片）",
        'mode' => $mode,
    ], JSON_UNESCAPED_UNICODE);
}
