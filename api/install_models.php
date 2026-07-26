<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/model_presets.php';
require_once dirname(__DIR__) . '/includes/InstallModelOnboarding.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// This install-only endpoint is authenticated by the short-lived, single-use
// session token validated below. Requiring normal login/CSRF here would block
// the installation success page, which has not established a login session.

function installModelJson(bool $ok, mixed $data = null, string $msg = '', int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'data' => $data, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    installModelJson(false, null, 'Method not allowed', 405);
}
if (!isInstalled()) {
    installModelJson(false, null, '系统尚未安装', 403);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    installModelJson(false, null, '请求格式无效', 400);
}

$sessionToken = (string)($_SESSION['install_model_token'] ?? '');
$expiresAt = (int)($_SESSION['install_model_token_expires_at'] ?? 0);
$requestToken = trim((string)($input['token'] ?? ''));
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    installModelJson(false, null, '安装模型配置凭证无效，请登录后在模型设置中配置', 403);
}
if ($expiresAt < time()) {
    unset($_SESSION['install_model_token'], $_SESSION['install_model_token_expires_at']);
    installModelJson(false, null, '安装模型配置凭证已过期，请登录后在模型设置中配置', 403);
}

$apiKey = trim((string)($input['api_key'] ?? ''));
$action = trim((string)($input['action'] ?? ''));
$models = is_array($input['models'] ?? null) ? $input['models'] : [];
$defaultModel = trim((string)($input['default_model'] ?? ''));
$presets = getInstallSkyhostModelPresets();

set_time_limit(300);
$tester = static function(array $cfg): string {
    $ai = new AIClient($cfg);
    return $ai->chat([
        ['role' => 'user', 'content' => '请只回复“连接成功”四个字。'],
    ], 'structured');
};

try {
    $service = new InstallModelOnboarding(new InstallModelDbStore(), $tester, $presets);

    if ($action === 'test') {
        $model = trim((string)($input['model'] ?? ''));
        $result = $service->testModels($apiKey, [$model]);
        $modelResult = $result['results'][$model] ?? ['ok' => false, 'message' => '模型测试失败'];
        if ($modelResult['ok'] === true) {
            $_SESSION['install_model_tested_models'][$model] = [
                'api_key_hash' => hash('sha256', $apiKey),
                'tested_at' => time(),
            ];
        } else {
            unset($_SESSION['install_model_tested_models'][$model]);
        }
        installModelJson(true, ['model' => $model, 'result' => $modelResult], $modelResult['ok'] ? '模型连接成功' : '模型连接失败');
    }

    if ($action === 'save') {
        $keyHash = hash('sha256', $apiKey);
        $testedModels = is_array($_SESSION['install_model_tested_models'] ?? null)
            ? $_SESSION['install_model_tested_models']
            : [];
        $verified = [];
        $unverified = [];
        foreach ($models as $model) {
            $model = trim((string)$model);
            $proof = is_array($testedModels[$model] ?? null) ? $testedModels[$model] : [];
            if ($model !== '' && isset($proof['api_key_hash']) && hash_equals((string)$proof['api_key_hash'], $keyHash)) {
                $verified[] = $model;
            } elseif ($model !== '') {
                $unverified[] = $model;
            }
        }
        if ($unverified !== []) {
            throw new InvalidArgumentException('以下模型尚未使用当前 API Key 测试成功：' . implode(', ', $unverified));
        }

        $result = $service->saveModels($apiKey, $verified, $defaultModel);
        unset(
            $_SESSION['install_model_token'],
            $_SESSION['install_model_token_expires_at'],
            $_SESSION['install_model_tested_models']
        );
        installModelJson(true, $result, '已保存测试成功的模型');
    }

    throw new InvalidArgumentException('不支持的模型安装操作');
} catch (InvalidArgumentException $e) {
    installModelJson(false, null, $e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('install_models.php failed: ' . $e->getMessage());
    installModelJson(false, null, '模型测试或保存失败，请稍后重试', 500);
}
