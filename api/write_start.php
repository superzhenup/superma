<?php
/**
 * 启动写作任务（异步后台执行，绕过 Nginx 长连接超时）
 * 
 * 原理：
 * 1. 生成 task_id，创建进度文件
 * 2. 用 exec() 后台启动 write_chapter_worker.php（PHP CLI 进程）
 * 3. CLI 进程不受 Nginx/FPM 超时限制，写作进度写入进度文件
 * 4. 前端通过 write_poll.php?task_id=xxx 轮询进度
 * 
 * 当 exec() 被禁用时，自动回退到 SSE 直连模式（write_chapter.php），
 * 通知前端切换到 SSE 模式。
 * 
 * POST JSON: { novel_id, chapter_id? }
 * 返回: { ok: true, task_id: "..." } 或 { ok: false, fallback_sse: true }
 */

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

requireLoginApi(false);  // 跳过 CSRF（前端 apiPost 不带 CSRF token）

// ============================================================
// 检测 exec() / proc_open() 是否可用（实测，不依赖 disable_functions 配置）
// 宝塔等面板的 disable_functions 配置可能不准确，直接执行测试命令最可靠
// ============================================================
$execOk = false;
if (function_exists('exec')) {
    $testOut = [];
    @exec('echo 1 2>/dev/null', $testOut, $testCode);
    $execOk = ($testCode === 0);
}

$popenOk = false;
if (function_exists('popen') && function_exists('pclose')) {
    $p = @popen('echo 1 2>/dev/null', 'r');
    if ($p) { pclose($p); $popenOk = true; }
}

$procOpenOk = false;
if (function_exists('proc_open') && function_exists('proc_close')) {
    $p = @proc_open('echo 1 2>/dev/null', [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (is_resource($p)) {
        foreach ($pipes as $pp) fclose($pp);
        proc_close($p);
        $procOpenOk = true;
    }
}

if (!$execOk && !$popenOk && !$procOpenOk) {
    // 所有后台进程启动方式都不可用，回退到 SSE 直连模式
    echo json_encode([
        'ok'           => false,
        'fallback_sse' => true,
        'msg'          => '服务器进程启动受限，已自动切换到 SSE 直连模式',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $novelId   = (int)($input['novel_id']   ?? 0);
    $chapterId = (int)($input['chapter_id'] ?? 0);
    
    if (!$novelId) throw new Exception('缺少小说ID');
    
    $novel = DB::fetch('SELECT id, status FROM novels WHERE id=?', [$novelId]);
    if (!$novel) throw new Exception('小说不存在');
    
    // 检查该小说是否已有写作任务在运行
    $progressDir = CFG_PROGRESS_DIR;
    if (!is_dir($progressDir)) @mkdir($progressDir, 0755, true);

    $staleTimeout = CFG_ZOMBIE_PROGRESS;  // 僵死进度文件阈值

    foreach (glob($progressDir . '/w*.json') as $existingFile) {
        $fp = fopen($existingFile, 'r');
        if (!$fp) continue;
        flock($fp, LOCK_SH);
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $p = json_decode($data, true);
        if (!is_array($p)) { @unlink($existingFile); continue; }

        $fileNovelId = (int)($p['novel_id'] ?? 0);
        $fileStatus  = $p['status'] ?? '';

        // ① 已完成/报错的进度文件直接清理，不阻塞新任务
        if (in_array($fileStatus, ['done', 'error', 'completed'])) {
            @unlink($existingFile);
            // 清理对应的 wrapper.sh
            @unlink(preg_replace('/\.json$/', '.sh', $existingFile));
            continue;
        }

        // ② 不属于当前小说的进度文件，跳过（不干预其他小说）
        if ($fileNovelId !== $novelId) continue;

        // ③ 僵死检测：updated_at 超时 OR 进程已死
        $updatedAt  = (int)($p['updated_at'] ?? 0);
        $startedAt  = (int)($p['started_at'] ?? 0);
        $refTime    = $updatedAt > 0 ? $updatedAt : $startedAt;
        $isStale    = ($refTime > 0 && (time() - $refTime) > $staleTimeout);

        // 额外检测：有 PID 且进程已死（exec 可用时才检测）
        if (!$isStale && !empty($p['pid'])) {
            $pid = (int)$p['pid'];
            if ($pid > 0) {
                $isDead = PHP_OS_FAMILY === 'Windows'
                    ? (function() use ($pid) { $out = []; @exec("tasklist /FI \"PID eq {$pid}\" /NH 2>nul", $out); return empty($out) || !preg_match('/\b' . $pid . '\b/', implode('', $out)); })()
                    : !file_exists("/proc/{$pid}");
                if ($isDead) $isStale = true;
            }
        }

        if ($isStale) {
            @unlink($existingFile);
            // 清理对应的 wrapper.sh
            @unlink(preg_replace('/\.json$/', '.sh', $existingFile));
            if ($fileNovelId > 0) {
                DB::update('novels', ['status' => 'draft'], 'id=? AND status=?', [$fileNovelId, 'writing']);
                $staleChapterId = (int)($p['chapter_id'] ?? 0);
                if ($staleChapterId > 0) {
                    DB::update('chapters', ['status' => 'outlined'], 'id=? AND status=?', [$staleChapterId, 'writing']);
                } else {
                    DB::query('UPDATE chapters SET status="outlined" WHERE novel_id=? AND status="writing"', [$fileNovelId]);
                }
                addLog($fileNovelId, 'warn', "清理僵死写作任务（超时{$staleTimeout}秒无响应）");
            }
            continue;
        }

        // ④ 当前小说确实有任务在进行中（且非僵死）
        if (in_array($fileStatus, ['starting', 'writing', 'waiting'])) {
            throw new Exception('该小说已有写作任务在运行中，请等待完成');
        }
    }
    
    // 兜底：重置该小说下所有卡在 writing 状态的章节
    $writingChapters = DB::fetchAll(
        'SELECT id FROM chapters WHERE novel_id=? AND status="writing"', [$novelId]
    );
    if (!empty($writingChapters)) {
        DB::query('UPDATE chapters SET status="outlined" WHERE novel_id=? AND status="writing"', [$novelId]);
        DB::update('novels', ['status' => 'draft'], 'id=? AND status=?', [$novelId, 'writing']);
    }
    
    // 生成任务 ID
    $taskId = 'w' . bin2hex(random_bytes(8));
    $progressFile = $progressDir . '/' . $taskId . '.json';
    
    file_put_contents($progressFile, json_encode([
        'status'     => 'starting',
        'progress'   => 0,
        'chapter_id' => $chapterId,
        'novel_id'   => $novelId,
        'content'    => '',
        'messages'   => [],
        'model_used' => null,
        'words'      => 0,
        'started_at' => time(),
        'updated_at' => time(),
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    // 启动 CLI worker 后台进程
    $phpBin = PHP_BINARY ?: 'php';
    // PHP-FPM 不能执行 CLI 脚本，需替换为 php CLI 二进制
    // 宝塔路径示例：/www/server/php/82/sbin/php-fpm → /www/server/php/82/bin/php
    // 注意：宝塔 open_basedir 限制会阻止 file_exists() 检查非项目路径，因此用 exec 绕过
    if (PHP_OS_FAMILY !== 'Windows' && preg_match('#/php-fpm\d*$#', $phpBin)) {
        $found = false;
        // exec 不受 open_basedir 限制，优先用 which 查找
        @exec('which php 2>/dev/null', $whichOut, $whichCode);
        if ($whichCode === 0 && !empty($whichOut[0])) {
            $candidate = trim($whichOut[0]);
            // 确认候选路径真的可以执行 PHP 脚本
            @exec(escapeshellarg($candidate) . ' -r "echo 1;" 2>/dev/null', $rTest, $rCode);
            if ($rCode === 0) {
                $phpBin = $candidate;
                $found = true;
            }
        }
        // 兜底：宝塔路径模式盲替换（路径模式非常可靠，不能因 open_basedir 而卡死）
        if (!$found) {
            $phpBin = str_replace('/sbin/php-fpm', '/bin/php', $phpBin);
        }
    }
    $workerScript = escapeshellarg(dirname(__DIR__) . '/api/write_chapter_worker.php');
    $logFilePath = $progressDir . '/' . $taskId . '.log';
    
    if (PHP_OS_FAMILY === 'Windows') {
        if ($procOpenOk) {
            // 使用 proc_open，比 start /B + popen 更可靠
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['file', $logFilePath, 'a'],
                2 => ['file', $logFilePath, 'a'],
            ];
            $process = @proc_open(
                "\"{$phpBin}\" {$workerScript} {$novelId} {$chapterId} {$taskId}",
                $descriptorspec,
                $pipes
            );
            if (is_resource($process)) {
                fclose($pipes[0]);
                proc_close($process);
            }
        } else {
            // 兜底：start /B + popen（部分环境可用）
            $logFile = escapeshellarg($logFilePath);
            $cmd = "start /B \"\" \"{$phpBin}\" {$workerScript} {$novelId} {$chapterId} {$taskId} >> {$logFile} 2>&1";
            pclose(popen($cmd, 'r'));
        }
    } else {
        // Linux: Shell wrapper 双壳隔离
        // 直接 exec("php ... &") 在宝塔 FPM 下 worker 会被杀死
        // 通过 wrapper.sh 中间层：FPM→sh→exec php，中间层吸收信号
        $workerSh = $progressDir . '/' . $taskId . '.sh';
        file_put_contents($workerSh,
            "#!/bin/sh\n" .
            "cd " . escapeshellarg(dirname(__DIR__)) . "\n" .
            "exec " . escapeshellarg($phpBin) . " {$workerScript} {$novelId} {$chapterId} {$taskId}" .
            " >> " . escapeshellarg($logFilePath) . " 2>&1\n"
        );
        @chmod($workerSh, 0755);
        exec(escapeshellarg($workerSh) . " > /dev/null 2>&1 &");
        // wrapper.sh 在 worker 完成后随进度文件一起清理（僵死检测）
    }
    
    // 轮询确认进程已启动并写入有效状态
    // CLI worker 启动涉及 PHP 加载、DB 连接、配置读取等，冷启动可能需数秒
    $started = false;
    $progress = null;
    $maxWait = 30;        // 最多轮询 30 次
    $waitInterval = 500000; // 每次 0.5 秒，总计 15 秒
    for ($i = 0; $i < $maxWait; $i++) {
        usleep($waitInterval);
        if (!file_exists($progressFile)) continue;
        $fp = fopen($progressFile, 'r');
        if (!$fp) continue;
        flock($fp, LOCK_SH);
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $progress = json_decode($data, true);
        if (!$progress) continue;
        if (($progress['status'] ?? '') === 'error') {
            @unlink($progressFile);
            throw new Exception($progress['error'] ?? '后台进程启动后立即失败');
        }
        if ($progress['status'] !== 'starting') {
            $started = true;
            break;
        }
    }
    // 修复：即使文件存在但 worker 状态仍为 starting（进程未启动），也视为失败
    if (!$started) {
        // ---- 收集诊断信息，帮助定位根因 ----
        $diag = [
            'php_binary_original' => PHP_BINARY ?: 'php',
            'php_binary_resolved' => $phpBin,
            'php_os_family'       => PHP_OS_FAMILY,
            'worker_script'       => dirname(__DIR__) . '/api/write_chapter_worker.php',
            'worker_exists'       => file_exists(dirname(__DIR__) . '/api/write_chapter_worker.php'),
            'progress_dir'        => $progressDir,
            'progress_dir_writable'=> is_writable($progressDir),
        ];
        
        // 检查 wrapper.sh 是否创建（Linux）
        if (PHP_OS_FAMILY !== 'Windows') {
            $diag['wrapper_sh_exists'] = file_exists($workerSh);
            $diag['wrapper_sh_executable'] = @is_executable($workerSh);
        }
        
        // 检查进度文件最终状态
        if (file_exists($progressFile)) {
            $fp2 = fopen($progressFile, 'r');
            if ($fp2) {
                flock($fp2, LOCK_SH);
                $pfData = stream_get_contents($fp2);
                flock($fp2, LOCK_UN);
                fclose($fp2);
                $pfJson = json_decode($pfData, true);
                $diag['progress_file_status'] = $pfJson['status'] ?? 'unknown';
                $diag['progress_file_content'] = substr($pfData, 0, 500);
            }
        } else {
            $diag['progress_file_exists'] = false;
        }
        
        // 检查 worker 日志文件
        if (file_exists($logFilePath)) {
            $logContent = @file_get_contents($logFilePath);
            $diag['log_file_exists'] = true;
            $diag['log_file_size']   = strlen($logContent ?: '');
            if (trim((string)$logContent) !== '') {
                // 只取前 2000 字符，避免响应过大
                $diag['log_file_preview'] = substr($logContent, 0, 2000);
            } else {
                $diag['log_file_preview'] = '(空)';
            }
        } else {
            $diag['log_file_exists'] = false;
        }
        
        @unlink($progressFile);
        // 清理 wrapper.sh
        if (PHP_OS_FAMILY !== 'Windows') @unlink($workerSh);
        // 同时重置章节/小说状态，避免 DB 中残留 writing 状态
        DB::update('novels', ['status' => 'draft'], 'id=? AND status=?', [$novelId, 'writing']);
        DB::query('UPDATE chapters SET status="outlined" WHERE novel_id=? AND status="writing"', [$novelId]);
        // 返回 fallback_sse 标志 + 诊断信息，让前端自动回退到 SSE 直连模式
        echo json_encode([
            'ok'           => false,
            'fallback_sse' => true,
            'msg'          => '后台进程启动失败，已自动切换到 SSE 直连模式',
            'debug_info'   => $diag,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'ok'      => true,
        'task_id' => $taskId,
        'msg'     => '写作任务已启动',
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'ok'  => false,
        'msg' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
