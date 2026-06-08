<?php
/**
 * 异步写作环境诊断（临时排查工具）
 *
 * 用法：上传到站点根目录，登录后浏览器访问  /async_diag.php
 *   - 普通访问：检测异步 worker 能否在“你的服务器环境”里被拉起，并打印 worker 真实日志。
 *   - /async_diag.php?reset=1 ：清理卡死的进度文件 + 把卡在 writing 的小说/章节复位（解决“写不了了”）。
 *
 * 诊断完成后请删除本文件。
 */

define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin(); // 必须登录后访问（会暴露服务器路径/配置）

header('Content-Type: text/plain; charset=utf-8');

function line(string $s = ''): void { echo $s . "\n"; }
function yn(bool $b): string { return $b ? 'YES' : 'NO'; }

/** 同步执行命令并捕获 stdout+stderr（不依赖被禁用的 proc_get_status）。 */
function runCapture(string $cmd, int $timeoutSec = 40): array {
    if (!function_exists('proc_open')) return ['out' => '(proc_open 不可用)', 'code' => -1];
    $p = @proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if ($p === false) return ['out' => '(proc_open 启动失败)', 'code' => -1];
    @fclose($pipes[0]);
    @stream_set_blocking($pipes[1], false);
    @stream_set_blocking($pipes[2], false);
    $out = ''; $start = time();
    while (true) {
        $out .= (string)@stream_get_contents($pipes[1]);
        $out .= (string)@stream_get_contents($pipes[2]);
        if (@feof($pipes[1]) && @feof($pipes[2])) break;
        if (time() - $start > $timeoutSec) { @proc_terminate($p); $out .= "\n(超时 {$timeoutSec}s 已终止——说明卡住了)"; break; }
        usleep(150000);
    }
    @fclose($pipes[1]); @fclose($pipes[2]);
    return ['out' => $out, 'code' => @proc_close($p)];
}

$progressDir = CFG_PROGRESS_DIR;
@is_dir($progressDir) or @mkdir($progressDir, 0755, true);

// ============================================================
// ?reset=1 ：恢复“写不了了”——清卡死进度 + 复位 writing 状态
// ============================================================
if (($_GET['reset'] ?? '') === '1') {
    line('=== RESET：清理卡死状态 ===');
    $removed = 0;
    foreach (array_merge(
        glob($progressDir . '/w*.json') ?: [],
        glob($progressDir . '/w*.log')  ?: [],
        glob($progressDir . '/w*.sh')   ?: []
    ) as $f) { if (@unlink($f)) $removed++; }
    line("已删除进度/日志文件：{$removed} 个");

    try {
        $chRows = DB::execute('UPDATE chapters SET status="outlined" WHERE status="writing"');
        $nvRows = DB::execute('UPDATE novels SET status="draft" WHERE status="writing"');
        line("章节 writing→outlined：{$chRows} 行");
        line("小说 writing→draft：{$nvRows} 行");
    } catch (\Throwable $e) {
        line('复位 DB 状态失败：' . $e->getMessage());
    }
    line('');
    line('已复位。现在回小说页重新点“自动写作”试试。问题仍在的话，去掉 ?reset=1 再访问本页看诊断。');
    exit;
}

// ============================================================
// 1) 基础环境
// ============================================================
line('=== 1. 基础环境 ===');
line('PHP_VERSION    : ' . PHP_VERSION);
line('PHP_SAPI       : ' . PHP_SAPI . '   (本页跑在 Web SAPI，与 write_start 相同)');
line('PHP_OS_FAMILY  : ' . PHP_OS_FAMILY);
line('PHP_BINARY     : ' . (PHP_BINARY ?: '(空)'));
line('disable_functions: ' . (ini_get('disable_functions') ?: '(空)'));
line('');

// ============================================================
// 2) 进程函数可用性（实测）
// ============================================================
line('=== 2. 进程函数 ===');
foreach (['exec','popen','pclose','proc_open','proc_close','shell_exec'] as $fn) {
    line(str_pad($fn, 12) . ': ' . (function_exists($fn) ? 'AVAILABLE' : 'DISABLED'));
}
$procOpenOk = false;
if (function_exists('proc_open') && function_exists('proc_close')) {
    $p = @proc_open('echo 1', [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if ($p !== false) { foreach ($pipes as $pp) @fclose($pp); proc_close($p); $procOpenOk = true; }
}
line('proc_open 实测  : ' . yn($procOpenOk) . ($procOpenOk ? '' : '   ← 若 NO，异步无法工作，只能走 SSE'));
line('');

// ============================================================
// 3) PHP 二进制解析（php-cgi → php.exe）
// ============================================================
line('=== 3. PHP CLI 二进制 ===');
$phpBin = PHP_BINARY ?: 'php';
$orig = $phpBin;
if (PHP_OS_FAMILY === 'Windows' && preg_match('#php-cgi\.exe$#i', $phpBin)) {
    $phpBin = preg_replace('#php-cgi\.exe$#i', 'php.exe', $phpBin);
}
if (PHP_OS_FAMILY !== 'Windows' && preg_match('#/php-fpm\d*$#', $phpBin)) {
    $phpBin = str_replace('/sbin/php-fpm', '/bin/php', $phpBin);
}
line('原始 PHP_BINARY : ' . $orig);
line('解析为 CLI      : ' . $phpBin);
line('解析后文件存在  : ' . yn(@is_file($phpBin)) . '   ← 若 NO，路径不对，worker 起不来');

// 实测解析后的二进制能否当 CLI 跑
$cliSapi = '(未测)';
if ($procOpenOk) {
    $tmpOut = $progressDir . '/_diag_cli.txt';
    @unlink($tmpOut);
    $cmd = (PHP_OS_FAMILY === 'Windows')
        ? '"' . $phpBin . '" -r "file_put_contents(' . var_export($tmpOut, true) . ', PHP_SAPI);"'
        : escapeshellarg($phpBin) . ' -r ' . escapeshellarg('file_put_contents(' . var_export($tmpOut, true) . ', PHP_SAPI);');
    $pp = @proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if ($pp !== false) { foreach ($pipes as $x) @fclose($x); proc_close($pp); }
    if (@is_file($tmpOut)) { $cliSapi = trim((string)@file_get_contents($tmpOut)); @unlink($tmpOut); }
}
line('解析后 SAPI 实测: ' . $cliSapi . '   ← 应为 cli（若是 cgi-fcgi 则 worker 会被 403 打死）');
line('');

// ============================================================
// 4) worker 脚本 & 进度目录
// ============================================================
line('=== 4. worker / 进度目录 ===');
$workerScript = __DIR__ . '/api/write_chapter_worker.php';
line('worker 脚本存在 : ' . yn(@is_file($workerScript)) . '  (' . $workerScript . ')');
line('sys_get_temp_dir: ' . sys_get_temp_dir());
line('进度目录        : ' . $progressDir);
line('进度目录可写    : ' . yn(@is_writable($progressDir)));
line('');

// ============================================================
// 5) 实战：用与 write_start 相同的方式拉起一个分离 worker
// ============================================================
line('=== 5. 分离启动实测（start /B + proc_open）===');
if (!$procOpenOk) {
    line('proc_open 不可用，跳过。异步无法工作。');
} else {
    $testWorker = $progressDir . '/_diag_worker.php';
    $marker     = $progressDir . '/_diag_marker.txt';
    $logFile    = $progressDir . '/_diag_launch.log';
    @unlink($marker); @unlink($logFile);
    file_put_contents($testWorker,
        "<?php\n" .
        "\$m = \$argv[1] ?? '';\n" .
        "if (\$m) file_put_contents(\$m, 'LAUNCH_OK sapi=' . PHP_SAPI . ' argc=' . \$argc . ' a2=' . (\$argv[2] ?? '') . ' t=' . time());\n"
    );
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start /B "" "' . $phpBin . '" "' . $testWorker . '" "' . $marker . '" HELLO'
             . ' >> "' . $logFile . '" 2>&1';
    } else {
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($testWorker)
             . ' ' . escapeshellarg($marker) . ' HELLO >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    }
    line('启动命令: ' . $cmd);
    $pp = @proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if ($pp === false) {
        line('proc_open 启动失败');
    } else {
        foreach ($pipes as $x) @fclose($x);
        proc_close($pp);
        // 轮询 marker 最多 ~6 秒
        $ok = false;
        for ($i = 0; $i < 30; $i++) {
            usleep(200000);
            if (@is_file($marker)) { $ok = true; break; }
        }
        line('worker 是否独立运行并写出标记: ' . yn($ok));
        if ($ok) {
            line('标记内容: ' . trim((string)@file_get_contents($marker)));
            line('  → 期望 sapi=cli argc=3 a2=HELLO。若 sapi=cli 即异步可用！');
        } else {
            line('  → worker 没起来。启动日志:');
            line('  ' . (trim((string)@file_get_contents($logFile)) ?: '(空)'));
        }
    }
    @unlink($testWorker); @unlink($marker); @unlink($logFile);
}
line('');

$cliPhpQuoted = (PHP_OS_FAMILY === 'Windows') ? '"' . $phpBin . '"' : escapeshellarg($phpBin);

// ============================================================
// 5b) CLI 加载的扩展（CLI 与 FastCGI 的 php.ini 可能不同！）
// ============================================================
line('=== 5b. CLI 已加载扩展（php.exe -m）===');
$mres = runCapture($cliPhpQuoted . ' -m 2>&1', 15);
$mods = strtolower($mres['out']);
foreach (['curl','pdo_mysql','mbstring','openssl','json','fileinfo'] as $need) {
    $has = strpos($mods, strtolower($need)) !== false;
    line(str_pad($need, 12) . ': ' . ($has ? 'OK' : '*** 缺失（很可能就是崩溃原因）***'));
}
line('--- 完整扩展列表 ---');
line(trim($mres['out']) !== '' ? trim($mres['out']) : '(无输出)');
line('');

// ============================================================
// 5c) 实跑真实 worker —— 强制显示错误（syslog 看不到，这里直接抓）
// ============================================================
line('=== 5c. 实跑 worker（强制 display_errors，捕获真实崩溃）===');
$diagTask = 'wdiag' . substr(bin2hex(random_bytes(3)), 0, 6);
$diagPf   = $progressDir . '/' . $diagTask . '.json';
@file_put_contents($diagPf, json_encode([
    'status' => 'starting', 'novel_id' => 999999, 'chapter_id' => 0,
    'started_at' => time(), 'updated_at' => time(),
], JSON_UNESCAPED_UNICODE));
// 用不存在的 novel 999999：require/DB 正常的话会干净地走到“小说不存在”
$wcmd = $cliPhpQuoted
      . ' -d display_errors=1 -d display_startup_errors=1 -d error_reporting=E_ALL '
      . '"' . $workerScript . '" 999999 0 ' . $diagTask . ' 2>&1';
line('命令: ' . $wcmd);
$wres = runCapture($wcmd, 40);
line('退出码: ' . $wres['code']);
line('--- worker 输出 (stdout+stderr) ---');
line(trim($wres['out']) !== '' ? mb_substr($wres['out'], 0, 4000) : '(无输出)');
if (@is_file($diagPf)) {
    $dd = json_decode((string)@file_get_contents($diagPf), true) ?: [];
    line('--- diagtest 进度文件最终状态 ---');
    line('status=' . ($dd['status'] ?? '?') . '  error=' . mb_substr((string)($dd['error'] ?? ''), 0, 400));
    @unlink($diagPf);
}
line('解读：');
line('  · 若输出有 Fatal/Uncaught/未定义函数/类未找到 → 那就是 worker 崩溃的根因');
line('  · 若 status=error 且 error 含“小说不存在” → require/DB 都正常，崩在真实写作(AI)阶段，需用真章节再查');
line('');

// ============================================================
// 6) 最近的真实 worker 日志（最关键：真实失败原因）
// ============================================================
line('=== 6. 最近 worker 日志（真实写作尝试的报错）===');
$logs = glob($progressDir . '/w*.log') ?: [];
usort($logs, fn($a, $b) => filemtime($b) <=> filemtime($a));
if (empty($logs)) {
    line('(没有 w*.log，说明 worker 可能从未被成功拉起，或日志已被清理)');
} else {
    foreach (array_slice($logs, 0, 3) as $lf) {
        line('--- ' . basename($lf) . '  (' . date('Y-m-d H:i:s', filemtime($lf)) . ') ---');
        $c = trim((string)@file_get_contents($lf));
        line($c === '' ? '(空——通常意味着 worker 启动即崩，没来得及输出)' : mb_substr($c, 0, 3000));
        line('');
    }
}

// ============================================================
// 7) 当前进度文件 / 卡死状态
// ============================================================
line('=== 7. 当前进度文件 & 卡死状态 ===');
$stuckNovelIds = [];
$jsons = glob($progressDir . '/w*.json') ?: [];
if (empty($jsons)) {
    line('进度文件：无');
} else {
    foreach ($jsons as $jf) {
        $d = json_decode((string)@file_get_contents($jf), true) ?: [];
        $nid = (int)($d['novel_id'] ?? 0);
        if ($nid > 0) $stuckNovelIds[$nid] = true;
        $msgs = $d['messages'] ?? [];
        $lastMsg = is_array($msgs) && $msgs ? end($msgs) : null;
        line(basename($jf) . ' : status=' . ($d['status'] ?? '?')
            . ' novel=' . ($nid ?: '?')
            . ' chapter=' . ($d['chapter_id'] ?? '?')
            . ' updated=' . (isset($d['updated_at']) ? date('H:i:s', (int)$d['updated_at']) : '?'));
        line('    content 长度=' . mb_strlen((string)($d['content'] ?? ''))
            . '  thinking 长度=' . mb_strlen((string)($d['thinking_content'] ?? ''))
            . '  progress=' . ($d['progress'] ?? '?'));
        if (is_array($msgs) && $msgs) {
            line('    messages（最近 15 条 —— 看 reason 里的「API错误（…）」就是真实报错）:');
            foreach (array_slice($msgs, -15) as $m) {
                line('      ' . mb_substr(json_encode($m, JSON_UNESCAPED_UNICODE), 0, 400));
            }
        }
        if (isset($d['error'])) line('    error=' . mb_substr((string)$d['error'], 0, 300));
    }
}
try {
    $wc = (int)DB::fetchColumn('SELECT COUNT(*) FROM chapters WHERE status="writing"');
    $wn = (int)DB::fetchColumn('SELECT COUNT(*) FROM novels WHERE status="writing"');
    line("DB 中卡在 writing 的章节：{$wc}，小说：{$wn}");
    foreach (DB::fetchAll('SELECT id FROM novels WHERE status="writing"') ?: [] as $r) {
        $stuckNovelIds[(int)$r['id']] = true;
    }
    if ($wc > 0 || $wn > 0) {
        line('  → 有卡死状态会导致“写不了了”。访问  /async_diag.php?reset=1  复位。');
    }
} catch (\Throwable $e) {
    line('查询 writing 状态失败：' . $e->getMessage());
}
line('');

// ============================================================
// 8) 卡住小说的写作日志（最关键：worker 在 AI 阶段到底在干嘛）
// ============================================================
line('=== 8. 卡住小说的最近写作日志（writing_logs）===');
if (empty($stuckNovelIds)) {
    line('(没有卡住的小说；若要排查请先复现一次写作再访问本页)');
} else {
    foreach (array_keys($stuckNovelIds) as $nid) {
        line("--- novel #{$nid} 最近 25 条 ---");
        try {
            $rows = DB::fetchAll(
                'SELECT action, message, created_at FROM writing_logs WHERE novel_id=? ORDER BY id DESC LIMIT 25',
                [$nid]
            ) ?: [];
            if (!$rows) { line('  (无日志)'); }
            foreach (array_reverse($rows) as $r) {
                line('  [' . ($r['created_at'] ?? '') . '] ' . ($r['action'] ?? '')
                    . ' : ' . mb_substr((string)($r['message'] ?? ''), 0, 240));
            }
        } catch (\Throwable $e) {
            line('  查询日志失败：' . $e->getMessage());
        }
        line('');
    }
}

// ============================================================
// 9) PHP error_log 末尾（worker 崩溃/致命错误会写在这里）
// ============================================================
line('=== 9. PHP error_log 末尾 ===');
$elog = ini_get('error_log');
line('error_log 配置: ' . ($elog ?: '(未配置，错误可能写到 FastCGI/站点日志)'));
if ($elog && @is_file($elog) && @is_readable($elog)) {
    $size = filesize($elog);
    $fp = @fopen($elog, 'r');
    if ($fp) {
        if ($size > 8000) fseek($fp, -8000, SEEK_END);
        $tail = stream_get_contents($fp);
        fclose($fp);
        // 只保留含 write_worker / Fatal / Error / Uncaught 的行，外加最后几行
        $keep = [];
        foreach (preg_split('/\r?\n/', (string)$tail) as $ln) {
            if (preg_match('/write_worker|Fatal|Uncaught|PHP (Warning|Error)|stack trace/i', $ln)) $keep[] = $ln;
        }
        $keep = array_slice($keep, -40);
        line($keep ? implode("\n", $keep) : '(末尾无 worker/致命错误相关行；下面是原始末尾 1500 字)');
        if (!$keep) line(mb_substr($tail, -1500));
    }
} else {
    line('(无法读取该文件；可能是权限或路径不同。可在宝塔“网站→日志”或 PHP 错误日志里找 [write_worker] 字样)');
}
line('');

// ============================================================
// 10) CLI HTTPS/SSL 测试（AI 调用走 HTTPS；CLI 若无 CA 包会 SSL 验证失败）
// ============================================================
line('=== 10. CLI HTTPS / SSL 测试（最可能的根因）===');
line('Web(本页) curl.cainfo   : ' . (ini_get('curl.cainfo') ?: '(空)'));
line('Web(本页) openssl.cafile: ' . (ini_get('openssl.cafile') ?: '(空)'));
$sslScript = $progressDir . '/_ssl_test.php';
@file_put_contents($sslScript,
    "<?php\n" .
    "echo 'CLI curl.cainfo   : ' . (ini_get('curl.cainfo') ?: '(空)') . PHP_EOL;\n" .
    "echo 'CLI openssl.cafile: ' . (ini_get('openssl.cafile') ?: '(空)') . PHP_EOL;\n" .
    "\$url = \$argv[1] ?? 'https://api.openai.com';\n" .
    "\$c = curl_init(\$url);\n" .
    "curl_setopt_array(\$c, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_NOBODY=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>15]);\n" .
    "curl_exec(\$c);\n" .
    "echo 'test ' . \$url . ' => errno=' . curl_errno(\$c) . ' | error=' . curl_error(\$c) . ' | http=' . curl_getinfo(\$c, CURLINFO_HTTP_CODE) . PHP_EOL;\n"
);
if (@is_file($sslScript)) {
    foreach (['https://api.openai.com', 'https://www.baidu.com'] as $u) {
        $sres = runCapture($cliPhpQuoted . ' "' . $sslScript . '" "' . $u . '" 2>&1', 25);
        line(trim($sres['out']) !== '' ? trim($sres['out']) : '(无输出)');
        line('');
    }
    @unlink($sslScript);
}
// 帮你定位可用的 CA 证书包（设到 CLI php.ini 的 curl.cainfo）
line('--- 候选 cacert.pem（用于修复）---');
$phpDir = dirname($phpBin);
$found = false;
foreach ([
    $phpDir . '\\extras\\ssl\\cacert.pem',
    $phpDir . '\\cacert.pem',
    $phpDir . '\\extras\\cacert.pem',
    dirname($phpDir) . '\\cacert.pem',
    'C:\\BtSoft\\php\\cacert.pem',
] as $cand) {
    if (@is_file($cand)) { line('  存在: ' . $cand); $found = true; }
}
if (!$found) line('  (未在常见位置找到 cacert.pem；可用 Web 的 curl.cainfo 路径，或从网上下载 cacert.pem)');
line('解读：errno=60 → SSL 证书验证失败（CLI 的 curl.cainfo 为空/无效）= AI 调用失败的根因。');
line('      errno=0 且 http=200/401 → SSL 正常，根因在别处（看第 7 节 messages 的真实 reason）。');
line('');
line('=== 诊断结束（把以上全部内容复制发我）===');
