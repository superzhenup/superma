<?php
/**
 * AI 调用诊断（临时排查工具）—— 定位异步 worker 调 AI 失败的真实原因。
 * 上传到站点根目录，登录后访问  /ai_call_diag.php
 * 排查完请删除本文件。
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
header('Content-Type: text/plain; charset=utf-8');

function L(string $s = ''): void { echo $s . "\n"; }
function mask(string $k): string {
    $k = trim($k);
    if ($k === '') return '(空)';
    $n = mb_strlen($k);
    return $n <= 10 ? str_repeat('*', $n) : mb_substr($k, 0, 6) . '...' . mb_substr($k, -4) . " (len={$n})";
}
function runCap(string $cmd, int $t = 25): string {
    if (!function_exists('proc_open')) return '(proc_open 不可用)';
    $p = @proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pi);
    if ($p === false) return '(proc_open 启动失败)';
    @fclose($pi[0]); @stream_set_blocking($pi[1],false); @stream_set_blocking($pi[2],false);
    $o=''; $s=time();
    while (true) {
        $o .= (string)@stream_get_contents($pi[1]); $o .= (string)@stream_get_contents($pi[2]);
        if (@feof($pi[1]) && @feof($pi[2])) break;
        if (time()-$s > $t) { @proc_terminate($p); $o .= "\n(超时已终止)"; break; }
        usleep(120000);
    }
    @fclose($pi[1]); @fclose($pi[2]); @proc_close($p);
    return $o;
}

// php.exe 解析（与 write_start 一致）
$phpBin = PHP_BINARY ?: 'php';
if (PHP_OS_FAMILY === 'Windows' && preg_match('#php-cgi\.exe$#i', $phpBin)) {
    $phpBin = preg_replace('#php-cgi\.exe$#i', 'php.exe', $phpBin);
}
$phpQ = (PHP_OS_FAMILY === 'Windows') ? '"' . $phpBin . '"' : escapeshellarg($phpBin);

// ============================================================
// 1) AI 模型配置（看 api_url 是 http 还是 https）
// ============================================================
L('=== 1. AI 模型配置（ai_models）===');
$models = [];
try {
    $models = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC') ?: [];
} catch (\Throwable $e) { L('查询 ai_models 失败：' . $e->getMessage()); }
if (!$models) {
    L('(没有配置 AI 模型)');
} else {
    foreach ($models as $m) {
        L('#' . ($m['id'] ?? '?') . '  ' . ($m['name'] ?? '')
            . (empty($m['is_default']) ? '' : '  [默认]')
            . (empty($m['embedding_enabled']) ? '' : '  [embedding]'));
        L('    api_url    : ' . ($m['api_url'] ?? '(空)')
            . '   ← ' . (stripos((string)($m['api_url'] ?? ''), 'https://') === 0 ? 'HTTPS' :
                        (stripos((string)($m['api_url'] ?? ''), 'http://') === 0 ? 'HTTP（SSL 修复对它无效）' : '协议未知')));
        L('    model_name : ' . ($m['model_name'] ?? '(空)'));
        L('    api_key    : ' . mask((string)($m['api_key'] ?? '')));
        L('    thinking   : ' . (empty($m['thinking_enabled']) ? 'off' : 'on')
            . '   max_tokens=' . ($m['max_tokens'] ?? '?'));
    }
}
L('');

// ============================================================
// 2) 最近进度文件里的真实报错（messages 里的「API错误（…）」）
// ============================================================
L('=== 2. 最近写作任务的真实报错（progress messages）===');
$progressDir = CFG_PROGRESS_DIR;
$jsons = glob($progressDir . '/w*.json') ?: [];
usort($jsons, fn($a,$b)=>filemtime($b) <=> filemtime($a));
if (!$jsons) {
    L('(进度目录暂无 w*.json——先去点一次「自动写作」、让它卡住 ~30 秒，再访问本页)');
} else {
    foreach (array_slice($jsons, 0, 2) as $jf) {
        $d = json_decode((string)@file_get_contents($jf), true) ?: [];
        L('--- ' . basename($jf) . '  status=' . ($d['status'] ?? '?')
            . '  novel=' . ($d['novel_id'] ?? '?') . '  chapter=' . ($d['chapter_id'] ?? '?')
            . '  content长度=' . mb_strlen((string)($d['content'] ?? '')) . ' ---');
        $msgs = $d['messages'] ?? [];
        if (is_array($msgs) && $msgs) {
            foreach (array_slice($msgs, -20) as $mm) {
                L('  ' . mb_substr(json_encode($mm, JSON_UNESCAPED_UNICODE), 0, 500));
            }
        } else {
            L('  (无 messages)');
        }
        if (isset($d['error'])) L('  error=' . mb_substr((string)$d['error'], 0, 500));
        L('');
    }
}

// ============================================================
// 3) CLI 直连 AI 端点测试（worker 就是在 CLI 里发请求）
// ============================================================
L('=== 3. CLI 能否连到 AI 端点（不带 key，只测可达性）===');
$urls = [];
foreach ($models as $m) {
    $u = trim((string)($m['api_url'] ?? ''));
    if ($u !== '') $urls[parse_url($u, PHP_URL_SCHEME) . '://' . parse_url($u, PHP_URL_HOST) . (parse_url($u, PHP_URL_PORT) ? ':' . parse_url($u, PHP_URL_PORT) : '')] = $u;
}
if (!$urls) {
    L('(无可测端点)');
} else {
    $testScript = $progressDir . '/_aicall_test.php';
    @file_put_contents($testScript,
        "<?php\n" .
        "\$u = \$argv[1] ?? '';\n" .
        "\$https = stripos(\$u, 'https://') === 0;\n" .
        "\$c = curl_init(\$u);\n" .
        "curl_setopt_array(\$c, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_NOBODY=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>\$https, CURLOPT_SSL_VERIFYHOST=>\$https?2:0]);\n" .
        "curl_exec(\$c);\n" .
        "echo 'cainfo=' . (ini_get('curl.cainfo') ?: '(空)') . ' | errno=' . curl_errno(\$c) . ' | error=' . curl_error(\$c) . ' | http=' . curl_getinfo(\$c, CURLINFO_HTTP_CODE) . PHP_EOL;\n"
    );
    foreach (array_values($urls) as $u) {
        L('测试: ' . $u);
        L('  ' . trim(runCap($phpQ . ' "' . $testScript . '" "' . $u . '" 2>&1', 20)));
    }
    @unlink($testScript);
    L('');
    L('解读：errno=0（http 任意码，含 401/404）→ CLI 能连到端点，问题在请求体/鉴权（看第 2 节真实报错）；');
    L('      errno=7  → 连不上（端口未开/被防火墙挡/服务没起）；errno=6 → DNS 解析失败；');
    L('      errno=60 → 仅 HTTPS：证书验证失败（CA 包问题，应已被 includes/cacert.pem 修复）。');
}
L('');
L('=== 结束（整页复制发我）===');
