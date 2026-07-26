<?php
/**
 * 漫剧工作流 — 视频生成引擎配置 API
 * 模式与 cover_actions.php 的图片引擎配置三件套一致：
 * get（密钥不回填）/ save（管理员，掩码拒绝）/ test（只读探针，不花钱生成）。
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
require_once dirname(__DIR__) . '/includes/VideoGenerationProtocol.php';
require_once dirname(__DIR__) . '/includes/drama/DramaMediaClient.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_POST['action'] ?? $jsonInput['action'] ?? $_GET['action'] ?? '';
if ($action !== 'get_video_api_config') {
    requireHttpMethod('POST');
}

function videoCfgSafeErrorMessage(Throwable $e): string {
    $message = $e->getMessage();
    foreach ([
        '媒体服务 URL', '媒体服务端口', '不允许访问', '无法解析', '视频生成',
        '请先填写或保存', 'API Key 不可提交掩码占位符', '需要管理员权限', '无效的操作',
    ] as $prefix) {
        if (str_starts_with($message, $prefix)) return mb_substr($message, 0, 240);
    }
    return '操作失败，请稍后重试';
}

try {
    switch ($action) {
        case 'get_video_api_config': {
            $provider = VideoGenerationProtocol::normalizeProvider((string)getSystemSetting('video_gen_provider', 'volcengine_seedance'));
            $apiKey = (string)getSystemSetting('video_gen_api_key', '');
            $apiUrl = (string)getSystemSetting('video_gen_api_url', '');
            $providers = [];
            foreach (VideoGenerationProtocol::PROVIDERS as $key => $meta) {
                $providers[] = ['key' => $key, 'label' => $meta['label'], 'default_base' => $meta['default_base']];
            }
            echo json_encode(['ok' => true, 'data' => [
                'provider'    => $provider,
                'api_url'     => $apiUrl !== '' ? $apiUrl : VideoGenerationProtocol::defaultBaseUrl($provider),
                'model'       => getSystemSetting('video_gen_model', ''),
                'has_api_key' => $apiKey !== '',
                'api_key_hint' => $apiKey !== '' ? '末四位 ' . substr($apiKey, -4) : '',
                'providers'   => $providers,
            ]], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'save_video_api_config': {
            if (!isAdmin()) throw new RuntimeException('需要管理员权限');

            $provider = VideoGenerationProtocol::normalizeProvider((string)($jsonInput['provider'] ?? 'volcengine_seedance'));
            $apiUrl = VideoGenerationProtocol::normalizeBaseUrl((string)($jsonInput['api_url'] ?? ''));
            $apiKey = trim((string)($jsonInput['api_key'] ?? ''));
            $model = trim((string)($jsonInput['model'] ?? ''));
            if ($apiUrl === '') $apiUrl = VideoGenerationProtocol::defaultBaseUrl($provider);

            DramaMediaClient::assertSafeEndpoint(VideoGenerationProtocol::submitEndpoint($provider, $apiUrl));

            $settings = [
                'video_gen_provider' => $provider,
                'video_gen_api_url'  => $apiUrl,
                'video_gen_model'    => $model,
            ];
            if ($apiKey !== '' && str_starts_with($apiKey, '***')) {
                throw new RuntimeException('API Key 不可提交掩码占位符；如不修改密钥请留空');
            }
            if ($apiKey !== '') {
                $settings['video_gen_api_key'] = $apiKey;
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
            echo json_encode(['ok' => true, 'msg' => '视频生成引擎配置已保存'], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'test_video_api_config': {
            if (!isAdmin()) throw new RuntimeException('需要管理员权限');

            $provider = VideoGenerationProtocol::normalizeProvider((string)($jsonInput['provider'] ?? getSystemSetting('video_gen_provider', 'volcengine_seedance')));
            $apiUrl = VideoGenerationProtocol::normalizeBaseUrl((string)($jsonInput['api_url'] ?? ''));
            $apiKey = trim((string)($jsonInput['api_key'] ?? ''));
            if ($apiUrl === '') $apiUrl = (string)getSystemSetting('video_gen_api_url', '');
            if ($apiUrl === '') $apiUrl = VideoGenerationProtocol::defaultBaseUrl($provider);
            if ($apiKey === '' || str_starts_with($apiKey, '***')) {
                $apiKey = (string)getSystemSetting('video_gen_api_key', '');
            }
            if ($apiKey === '') throw new RuntimeException('请先填写或保存 API 密钥');

            // 只读探针：有公开模型列表的 provider 验证鉴权；其余验证端点可达性。
            // 绝不提交真实生成任务（避免测试产生费用）。
            $probeEndpoint = match ($provider) {
                'volcengine_seedance' => $apiUrl . '/models',
                'wanx'                => $apiUrl . '/api/v1/models',
                default               => null,
            };
            if ($probeEndpoint !== null) {
                $resp = DramaMediaClient::requestJson($probeEndpoint, ['Authorization: Bearer ' . $apiKey], null, 30);
                if ($resp['status'] < 200 || $resp['status'] >= 300) {
                    error_log("Video API auth probe failed: HTTP {$resp['status']}, provider={$provider}");
                    throw new RuntimeException("视频生成服务鉴权探针返回 HTTP {$resp['status']}");
                }
                echo json_encode(['ok' => true, 'msg' => '连接与鉴权成功（本次测试未生成视频）'], JSON_UNESCAPED_UNICODE);
            } else {
                // kling / vidu 无公开只读端点：验证 submit 端点可达（任何 HTTP 响应即 TLS+路由正常）
                $endpoint = VideoGenerationProtocol::submitEndpoint($provider, $apiUrl);
                DramaMediaClient::assertSafeEndpoint($endpoint);
                try {
                    DramaMediaClient::requestJson($endpoint, ['Authorization: Bearer ' . $apiKey], null, 30, 64 * 1024);
                } catch (RuntimeException $e) {
                    if (str_contains($e->getMessage(), '连接失败')) {
                        throw new RuntimeException('视频生成服务连接失败');
                    }
                }
                echo json_encode(['ok' => true, 'msg' => '端点连接正常（该服务商无只读探针，未验证鉴权，未生成视频）'], JSON_UNESCAPED_UNICODE);
            }
            break;
        }

        default:
            throw new RuntimeException('无效的操作');
    }
} catch (Throwable $e) {
    error_log('drama_video_config error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(safe_api_error_payload($e, videoCfgSafeErrorMessage($e)), JSON_UNESCAPED_UNICODE);
}
