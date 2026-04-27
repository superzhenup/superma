<?php
/**
 * 封面图片 API
 * 支持上传封面图片、AI生成封面图片（基于 gpt-image-2）、删除封面
 */

ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// 兼容三种请求方式：FormData($_POST)、JSON body、GET 参数
$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_POST['action'] ?? $jsonInput['action'] ?? $_GET['action'] ?? '';

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
        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 上传封面图片
 * 接受 JPG/PNG/WebP 格式，自动缩放到 1086x1448
 */
function uploadCover(): void {
    $novelId = (int)($_POST['novel_id'] ?? 0);
    if (!$novelId) throw new RuntimeException('缺少小说ID');

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
        @mkdir($coversDir, 0755, true);
    }

    // 删除旧封面
    if (!empty($novel['cover_image'])) {
        $oldPath = defined('BASE_PATH') ? BASE_PATH . '/' . $novel['cover_image'] : dirname(__DIR__) . '/' . $novel['cover_image'];
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    // 生成文件名
    $ext = match ($mimeType) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default       => 'jpg',
    };
    $filename = 'novel_' . $novelId . '_' . time() . '.' . $ext;
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

        match ($ext) {
            'png'  => imagepng($dstImage, $destPath, 8),
            'webp' => imagewebp($dstImage, $destPath, 85),
            default => imagejpeg($dstImage, $destPath, 90),
        };

        imagedestroy($srcImage);
        imagedestroy($dstImage);
    } else {
        // 没有 GD 库，直接移动文件
        move_uploaded_file($file['tmp_name'], $destPath);
    }

    $relativePath = 'storage/covers/' . $filename;
    DB::update('novels', ['cover_image' => $relativePath], 'id=?', [$novelId]);

    echo json_encode([
        'ok'   => true,
        'msg'  => '封面上传成功',
        'path' => $relativePath,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * AI 生成封面图片
 * 通过 Chat Completions 接口调用 gpt-image-2 等图片生成模型
 * 模型返回的图片以 markdown 格式 ![image](url) 嵌在文本中，需提取 URL 下载到本地
 */
function generateCover(array $input): void {
    // 图片生成耗时较长，延长执行时间限制
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    $novelId = (int)($input['novel_id'] ?? 0);
    $keyword = trim($input['keyword'] ?? '');
    $author  = trim($input['author'] ?? '');

    if (!$novelId) throw new RuntimeException('缺少小说ID');
    if (!$keyword) throw new RuntimeException('请输入封面描述关键词');

    $novel = DB::fetch('SELECT id, title, genre, cover_image FROM novels WHERE id=?', [$novelId]);
    if (!$novel) throw new RuntimeException('小说不存在');

    // 读取图片生成 API 配置
    $apiUrl      = getSystemSetting('image_gen_api_url', '');
    $apiKey      = getSystemSetting('image_gen_api_key', '');
    $modelName   = getSystemSetting('image_gen_model', 'gpt-image-2');
    $promptPrefix = getSystemSetting('image_gen_prompt_prefix', '');

    if (empty($apiUrl) || empty($apiKey)) {
        throw new RuntimeException('请先在模型设置中配置图片生成引擎');
    }

    // 构建请求 — 使用 chat/completions 接口
    $apiUrl = rtrim($apiUrl, '/');
    $endpoint = $apiUrl . '/chat/completions';

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

    $postData = json_encode([
        'model'       => $modelName,
        'messages'    => [
            ['role' => 'user', 'content' => $prompt],
        ],
        'max_tokens'  => 4096,
        'stream'      => true,
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException('API 请求失败：' . $curlErr);
    }
    if ($httpCode !== 200) {
        $errBody = json_decode($response, true);
        $errMsg = $errBody['error']['message'] ?? ($errBody['error'] ?? '未知错误');
        throw new RuntimeException('API 返回错误 (' . $httpCode . ')：' . $errMsg);
    }

    // 从响应中提取图片 URL
    $imageUrl = null;
    $b64Data  = null;

    // 先尝试解析为非流式 JSON 响应
    $result = json_decode($response, true);
    if ($result && is_array($result) && isset($result['choices'][0]['message']['content'])) {
        // 非流式响应：从 message.content 提取 markdown 图片链接
        $content = $result['choices'][0]['message']['content'];
        if (preg_match('/!\[.*?\]\((https?:\/\/[^\s\)]+)\)/', $content, $m)) {
            $imageUrl = $m[1];
        }
        // OpenAI Images API 格式 — data[0].url
        if (!$imageUrl && !empty($result['data'][0]['url'])) {
            $imageUrl = $result['data'][0]['url'];
        }
        // data[0].b64_json
        if (!$imageUrl && !empty($result['data'][0]['b64_json'])) {
            $b64Data = $result['data'][0]['b64_json'];
        }
    } else {
        // 流式响应（SSE）：累积 delta.content 后提取图片链接
        $accumulatedContent = '';
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $jsonStr = trim(substr($line, 6));
                if ($jsonStr === '[DONE]') break;
                $chunk = json_decode($jsonStr, true);
                if ($chunk && isset($chunk['choices'][0]['delta']['content'])) {
                    $accumulatedContent .= $chunk['choices'][0]['delta']['content'];
                }
            }
        }
        if ($accumulatedContent && preg_match('/!\[.*?\]\((https?:\/\/[^\s\)]+)\)/', $accumulatedContent, $m)) {
            $imageUrl = $m[1];
        }
    }

    if (!$imageUrl && !$b64Data) {
        error_log('Cover generate: unexpected response - ' . substr($response, 0, 500));
        throw new RuntimeException('未能从 API 响应中提取图片，请检查模型是否支持图片生成');
    }

    // 确保存储目录存在
    $coversDir = defined('BASE_PATH') ? BASE_PATH . '/storage/covers' : dirname(__DIR__) . '/storage/covers';
    if (!is_dir($coversDir)) {
        if (!@mkdir($coversDir, 0755, true)) {
            // 目录创建失败，直接返回远程 URL
            if ($imageUrl) {
                DB::update('novels', ['cover_image' => $imageUrl], 'id=?', [$novelId]);
                echo json_encode([
                    'ok'   => true,
                    'msg'  => '封面生成成功（远程模式）',
                    'path' => $imageUrl,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
            throw new RuntimeException('无法创建存储目录：' . $coversDir);
        }
    }

    // 删除旧封面（仅删除本地文件）
    if (!empty($novel['cover_image']) && !preg_match('/^https?:\/\//', $novel['cover_image'])) {
        $oldPath = defined('BASE_PATH') ? BASE_PATH . '/' . $novel['cover_image'] : dirname(__DIR__) . '/' . $novel['cover_image'];
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    // 下载并保存图片到本地（必须成功）
    $filename = 'novel_' . $novelId . '_ai_' . time() . '.png';
    $destPath = $coversDir . '/' . $filename;

    if ($b64Data) {
        // base64 格式
        $imageContent = base64_decode($b64Data);
        if (!$imageContent) throw new RuntimeException('base64 解码失败');
        $written = file_put_contents($destPath, $imageContent);
        if ($written === false) {
            throw new RuntimeException('图片保存失败，请检查 storage/covers 目录权限（路径：' . $destPath . '，可写：' . (is_writable($coversDir) ? '是' : '否') . '）');
        }
    } else {
        // URL 格式：下载图片到本地
        $imgContent = false;
        $dlHttpCode = 0;
        $dlErr = '';

        // 优先用 curl 下载
        if (function_exists('curl_init')) {
            $dlCh = curl_init($imageUrl);
            curl_setopt_array($dlCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) CoverDownloader',
            ]);
            $imgContent = curl_exec($dlCh);
            $dlHttpCode = (int)curl_getinfo($dlCh, CURLINFO_HTTP_CODE);
            $dlErr = curl_error($dlCh);
            curl_close($dlCh);
        }

        // curl 失败，尝试 file_get_contents
        if ($dlHttpCode !== 200 || !$imgContent) {
            $ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => true], 'ssl' => ['verify_peer' => false]]);
            $imgContent = @file_get_contents($imageUrl, false, $ctx);
        }

        if (!$imgContent) {
            throw new RuntimeException('下载图片失败（curl: HTTP ' . $dlHttpCode . ', ' . $dlErr . '），远程地址：' . $imageUrl);
        }

        $written = file_put_contents($destPath, $imgContent);
        if ($written === false) {
            throw new RuntimeException('图片保存失败，请检查 storage/covers 目录权限（路径：' . $destPath . '，可写：' . (is_writable($coversDir) ? '是' : '否') . '）');
        }
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
            imagepng($dstImage, $destPath, 8);
            imagedestroy($srcImage);
            imagedestroy($dstImage);
        }
    }


    $relativePath = 'storage/covers/' . $filename;
    DB::update('novels', ['cover_image' => $relativePath], 'id=?', [$novelId]);

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

    $novel = DB::fetch('SELECT id, cover_image FROM novels WHERE id=?', [$novelId]);
    if (!$novel) throw new RuntimeException('小说不存在');

    if (!empty($novel['cover_image'])) {
        $oldPath = defined('BASE_PATH') ? BASE_PATH . '/' . $novel['cover_image'] : dirname(__DIR__) . '/' . $novel['cover_image'];
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    DB::update('novels', ['cover_image' => null], 'id=?', [$novelId]);

    echo json_encode(['ok' => true, 'msg' => '封面已删除'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取图片生成 API 配置
 */
function getImageApiConfig(): void {
    $config = [
        'api_url'       => getSystemSetting('image_gen_api_url', ''),
        'api_key'       => getSystemSetting('image_gen_api_key', ''),
        'model'         => getSystemSetting('image_gen_model', 'gpt-image-2'),
        'size'          => getSystemSetting('image_gen_size', '1024x1536'),
        'prompt_prefix' => getSystemSetting('image_gen_prompt_prefix', ''),
    ];
    // 脱敏 API Key
    if (!empty($config['api_key'])) {
        $config['api_key_masked'] = substr($config['api_key'], 0, 6) . '***' . substr($config['api_key'], -4);
    } else {
        $config['api_key_masked'] = '';
    }
    unset($config['api_key']);

    echo json_encode(['ok' => true, 'data' => $config], JSON_UNESCAPED_UNICODE);
}

/**
 * 保存图片生成 API 配置
 */
function saveImageApiConfig(array $input): void {
    $apiUrl       = trim($input['api_url'] ?? '');
    $apiKey       = trim($input['api_key'] ?? '');
    $model        = trim($input['model'] ?? 'gpt-image-2');
    $size         = trim($input['size'] ?? '1024x1536');
    $promptPrefix = trim($input['prompt_prefix'] ?? '');

    $settings = [
        'image_gen_api_url'        => $apiUrl,
        'image_gen_model'          => $model ?: 'gpt-image-2',
        'image_gen_size'           => $size ?: '1024x1536',
        'image_gen_prompt_prefix'  => $promptPrefix,
    ];

    // 仅在提供了新 key 时更新（避免覆盖为空值）
    if ($apiKey !== '' && $apiKey !== '***') {
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

    echo json_encode(['ok' => true, 'msg' => '图片生成引擎配置已保存'], JSON_UNESCAPED_UNICODE);
}
