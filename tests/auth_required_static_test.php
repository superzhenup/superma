<?php
/**
 * API 鉴权静态回归测试
 *
 * 守护目标：每个 api/*.php 端点必须具备下列其中一种鉴权机制：
 *   1) 调用 requireLoginApi() / requireLoginApi(true) / requireLoginApi(false)
 *      等会话级登录校验（绝大多数用户面端点）；
 *   2) 非会话级 Token 鉴权（hash_equals 比较请求头 / Authorization Bearer）；
 *   3) CLI 模式硬校验（PHP_SAPI !== 'cli' 拒绝 HTTP 访问），仅供后台 worker 调用；
 *   4) 显式声明"对外推送"豁免（X-Ingest-Key 类推送密钥校验）。
 *
 * 任何 api/*.php 文件如果连上述任何一种机制都不存在，立即报错。
 *
 * 扫描范围：api/ 目录下所有 .php 文件。
 */

$root = dirname(__DIR__);
$apiDir = $root . DIRECTORY_SEPARATOR . 'api';

function auth_read(string $abs): string
{
    if (!is_file($abs)) {
        throw new RuntimeException("Missing source file: {$abs}");
    }
    return file_get_contents($abs);
}

function auth_collect_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $abs = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_file($abs) && substr($f, -4) === '.php') {
            $out[] = $abs;
        }
    }
    sort($out);
    return $out;
}

function auth_classify(string $hay): array
{
    $flags = [
        'session' => false,
        'token'   => false,
        'cli'     => false,
        'ingest'  => false,
    ];

    // 1) 会话登录校验
    if (preg_match('/\brequireLoginApi\s*\(/', $hay)) {
        $flags['session'] = true;
    }

    // 2) Token 鉴权（hash_equals / Bearer / X-*-Token / X-*-Key）
    if (preg_match('/\bhash_equals\s*\(/', $hay)
        || preg_match('/Bearer\s+/i', $hay)
        || preg_match('/HTTP_X_(DAEMON_TOKEN|INGEST_KEY|API_KEY)/i', $hay)
        || preg_match('/x-daemon-token|x-ingest-key|x-api-key/i', $hay)
    ) {
        $flags['token'] = true;
    }

    // 3) CLI-only 守卫
    if (preg_match("/PHP_SAPI\\s*[!=]==?\\s*['\"]cli['\"]/", $hay)
        || preg_match("/PHP_SAPI\\s*[!=]==?\\s*['\"]cli-server['\"]/", $hay)
    ) {
        $flags['cli'] = true;
    }

    // 4) 推送密钥（X-Ingest-Key 等推送类）
    if (preg_match('/ingest[-_]?key|ingest_key/i', $hay)) {
        $flags['ingest'] = true;
    }

    return $flags;
}

$files = auth_collect_files($apiDir);
$totalScanned = 0;
$report = [];

foreach ($files as $f) {
    $totalScanned++;
    $hay = auth_read($f);
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $f);

    $flags = auth_classify($hay);
    $hasAny = $flags['session'] || $flags['token'] || $flags['cli'] || $flags['ingest'];

    if (!$hasAny) {
        $report[] = "---- {$rel} ----";
        $report[] = "  [NO_AUTH] 缺少任何鉴权机制（无 requireLoginApi / hash_equals / CLI 守卫 / 推送密钥）";
    }
}

if (empty($report)) {
    echo "auth_required_static_test passed ({$totalScanned} API endpoints scanned, all have at least one auth mechanism)\n";
    exit(0);
}

echo "auth_required_static_test FAILED: " . count(array_filter($report, fn($l) => strpos($l, '[NO_AUTH]') !== false)) . " endpoint(s) missing auth\n";
foreach ($report as $line) {
    echo $line . "\n";
}
exit(1);
