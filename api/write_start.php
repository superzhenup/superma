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

defined('APP_LOADED') or define('APP_LOADED', true);
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/NovelProjectionFence.php';
require_once dirname(__DIR__) . '/includes/tasks/WritingTaskRepository.php';
require_once dirname(__DIR__) . '/includes/quality/WritePolicySnapshotFactory.php';
require_once dirname(__DIR__) . '/includes/harness/HarnessControlPlane.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

requireLoginApi();  // 启用 CSRF 保护（前端已自动携带 X-CSRF-Token）

// ============================================================
// 检测 exec() / proc_open() 是否可用（实测，不依赖 disable_functions 配置）
// 宝塔等面板的 disable_functions 配置可能不准确，直接执行测试命令最可靠
// ============================================================
$execOk = false;
if (function_exists('exec')) {
    $testOut = [];
    $testCmd = PHP_OS_FAMILY === 'Windows' ? 'echo 1' : 'echo 1 2>/dev/null';
    @exec($testCmd, $testOut, $testCode);
    $execOk = ($testCode === 0);
}

$popenOk = false;
if (function_exists('popen') && function_exists('pclose')) {
    $testCmd = PHP_OS_FAMILY === 'Windows' ? 'echo 1' : 'echo 1 2>/dev/null';
    $p = @popen($testCmd, 'r');
    if ($p) { pclose($p); $popenOk = true; }
}

$procOpenOk = false;
if (function_exists('proc_open') && function_exists('proc_close')) {
    $testCmd = PHP_OS_FAMILY === 'Windows' ? 'echo 1' : 'echo 1 2>/dev/null';
    $p = @proc_open($testCmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    // PHP 8.1+ proc_open 返回对象而非 resource，is_resource() 不再适用
    if ($p !== false) {
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

$taskId = null;
$progressFile = null;
$taskCommitted = false;
$projectionFence = null;
$harnessEventCursorForTask = static function (array $task): bool {
    try {
        $snapshot = WritingTaskRepository::optionalPolicySnapshotFromRow($task);
        return $snapshot !== null
            && HarnessControlPlane::allowsNewHarnessRun($snapshot->harnessMode());
    } catch (Throwable $exception) {
        error_log('write_start could not authenticate Harness cursor policy: ' . $exception->getMessage());
        return false;
    }
};
$releaseProjectionFence = static function () use (&$projectionFence): void {
    if ($projectionFence === null) {
        return;
    }
    $token = $projectionFence;
    $projectionFence = null;
    try {
        NovelProjectionFence::release($token);
    } catch (Throwable $releaseError) {
        error_log('write_start projection fence release failed: ' . $releaseError->getMessage());
    }
};

try {
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $novelId   = (int)($input['novel_id']   ?? 0);
    $chapterId = (int)($input['chapter_id'] ?? 0);
    
    if (!$novelId) throw new Exception('缺少小说ID');

    // 审计修复 H1（2026-06-12）：写作端点必须先校验小说归属，
    // 否则任意已登录用户可对他人的 novel_id 发起后台 worker。
    $userId = currentUserId();
    checkNovelOwnership($novelId, $userId);
    if ($chapterId > 0) {
        checkChapterOwnership($chapterId, $userId);
    }

    // 在获取 novel 行锁前，先通过共享的 Canon-aware 恢复器回收真正过期的 claim。
    // 该恢复器会排除仍有有效 writing/postprocess lease 的章节，且不会把 Canon
    // rewrite claim 降级为 outlined。进入启动事务后只做冲突检测，不再盲重置。
    WriteEngine::recoverStaleWritingChapters($novelId);

    // 审计修复（2026-08-30 M-5）：stale pp_pending flag 必须在事务外清理。
    // findActiveTask 的清理在持锁事务内会被主动跳过（不得改 DB/补跑后处理），
    // 纯 Web 用户的 write_start 路径将永远无法清理 stale flag → 下一章
    // waitForPostProcess 每次硬等 180 秒后失败，形成小说级死锁。
    // 此处在获取行锁前显式清理，打破死循环。
    WriteEngine::cleanStalePostProcessFlags($novelId);

    // Pre-Phase 0: projection fence 必须先于事务/行锁；随后统一按
    // chapters -> novels -> writing_tasks 加锁，避免启动与取消形成反序等待。
    // 两个同时到达的请求也不能同时通过"无运行中任务"检查。
    $pdo = DB::connect();
    $projectionFence = NovelProjectionFence::acquire($novelId, 30);
    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('无法启动写作任务事务');
    }
    try {
        DB::fetchAll(
            'SELECT id FROM chapters WHERE novel_id=? ORDER BY id FOR UPDATE',
            [$novelId]
        );
        $locked = DB::fetch('SELECT id, status, chapter_words FROM novels WHERE id=? FOR UPDATE', [$novelId]);
        if (!$locked) throw new Exception('小说不存在');
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Throwable $e2) { error_log('write_start rollback failed: ' . $e2->getMessage()); }
        throw $e;
    }
    // 事务保持打开直到进度文件创建完成，确保并发安全

    $novel = $locked;

    // 检查该小说是否已有写作任务在运行
    // 扫描逻辑（完成文件清理 + 僵死判定复位）已提取到 WriteEngine::findActiveTask，
    // 与 write_chapter.php（SSE 直连入口）共用同一套并发守卫
    $progressDir = CFG_PROGRESS_DIR;
    if (!is_dir($progressDir)) @mkdir($progressDir, 0755, true);

    // v53：数据库任务是并发所有权的真理源；进度文件仅保留流式内容兼容。
    $activeTask = null;
    $durableTask = WritingTaskRepository::findActiveForNovel($novelId);
    if ($durableTask !== null) {
        $durableState = (string)($durableTask['state'] ?? '');
        $durableCanceled = (int)($durableTask['cancel_requested'] ?? 0) === 1;
        // 生成中的任务被用户取消后不得再恢复轮询，否则前端会接上一条正在停写的旧任务。
        if ($durableCanceled && in_array($durableState, ['queued', 'preparing', 'generating', 'validating'], true)) {
            $durableTask = null;
        }
    }
    if ($durableTask !== null) {
        $durableTaskId = (string)$durableTask['task_id'];
        $durableProgressFile = $progressDir . '/' . $durableTaskId . '.json';
        if (is_file($durableProgressFile)) {
            $durableProgress = json_decode((string)@file_get_contents($durableProgressFile), true);
            $activeTask = array_merge($durableTask, is_array($durableProgress) ? $durableProgress : [], [
                'task_file' => $durableProgressFile,
            ]);
        } else {
            $activeTask = array_merge($durableTask, ['_db_only' => true]);
        }
    }
    if ($activeTask === null) {
        $activeTask = WriteEngine::findActiveTask($novelId);
    }
    if ($activeTask !== null) {
        // 修复（2026-06-18）：已有任务在运行时，不抛异常，而是返回现有 task_id
        // 让前端可以恢复轮询现有任务，避免"前端报错重试→write_start 拒绝→再次报错"
        // 的死循环。worker 在后台继续跑，前端应继续轮询而非重启。
        $activeTaskId = '';
        if (!empty($activeTask['task_file'])) {
            $activeTaskId = preg_replace('/\.json$/', '', basename($activeTask['task_file']));
        }
        // 校验 task_id 归属（H-4 安全：只返回属于当前用户的任务）
        $activeOwnerUserId = (int)($activeTask['user_id'] ?? 0);
        if ($activeOwnerUserId > 0 && $activeOwnerUserId !== $userId) {
            throw new Exception('该小说已有写作任务在运行中，请等待完成');
        }
        // 审计修复 R2（2026-08-10）：任务恢复时校验 chapterId 一致性，
        // 避免前端请求写章节 A 但章节 B 的任务在跑时被误导恢复到错误章节。
        $activeChapterId = (int)($activeTask['chapter_id'] ?? 0);
        if ($chapterId > 0 && $activeChapterId > 0 && $chapterId !== $activeChapterId) {
            $pdo->commit();
            $releaseProjectionFence();
            echo json_encode([
                'ok'                   => false,
                'conflict'             => true,
                'msg'                  => '已有其他章节的写作任务在运行中，请等待完成或取消后再试',
                'active_chapter_id'    => $activeChapterId,
                'requested_chapter_id' => $chapterId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->commit();
        $releaseProjectionFence();
        if (!empty($activeTask['_db_only']) || $activeTaskId === '') {
            echo json_encode([
                'ok'             => false,
                'active_db_only' => true,
                'msg'            => '已有写作任务在运行，但进度文件不可恢复。请先点取消，再重新写作。',
                'chapter_id'     => $activeTask['chapter_id'] ?? $chapterId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'ok'             => true,
            'task_id'        => $activeTaskId,
            'resumed'        => true,
            'harness_event_cursor' => $harnessEventCursorForTask($activeTask),
            'msg'            => '已有写作任务在运行，已恢复进度跟踪',
            'chapter_id'     => $activeTask['chapter_id'] ?? $chapterId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 恢复后仍为 writing 的行属于活跃/受保护 claim。启动端没有该 claim 的
    // fencing token，因此只能报告冲突，绝不能按 novel_id 批量回退章节。
    $writingChapter = DB::fetch(
        'SELECT id FROM chapters WHERE novel_id=? AND status="writing" ORDER BY chapter_number ASC LIMIT 1',
        [$novelId]
    );
    if ($writingChapter) {
        $pdo->commit();
        $releaseProjectionFence();
        echo json_encode([
            'ok' => false,
            'active_db_only' => true,
            'msg' => '检测到仍受保护的写作或重写任务，请等待其完成或先执行取消。',
            'chapter_id' => (int)$writingChapter['id'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // M60: the queue boundary owns policy capture. Every later async phase
    // restores this exact authenticated snapshot instead of rereading mutable
    // controls. chapter_words is read from the already locked novel row.
    $policyTargetWords = (int)($locked['chapter_words'] ?? 0);
    if ($policyTargetWords < 500) {
        $policyTargetWords = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
    }
    $policyTargetWords = max(500, $policyTargetWords);
    $writePolicySnapshot = WritePolicySnapshotFactory::captureForChapter(
        $novelId,
        $policyTargetWords
    );
    
    // 生成任务 ID
    // 审计修复 H-4（2026-06-17）：task_id 绑定 user_id + novel_id 的 HMAC
    // 防止通过猜/泄露 task_id 读取他人进行中的写作进度与内容。
    // 旧 task_id 是 16 hex 字符纯随机数，被猜中可读 w{taskid}.json 内容。
    // 新格式：HMAC-SHA256(user_id|novel_id|nonce, secret)[:16hex] 截断 + 16hex nonce
    $taskId = generateBoundTaskId($userId, $novelId);
    $progressFile = $progressDir . '/' . $taskId . '.json';

    $progressJson = json_encode([
        'status'     => 'starting',
        'progress'   => 0,
        'chapter_id' => $chapterId,
        'novel_id'   => $novelId,
        'user_id'    => $userId,         // 审计修复 H-4：记录归属
        'task_sig'   => taskIdSignature($taskId, $userId, $novelId),  // 审计修复 H-4：HMAC 存根
        'content'    => '',
        'messages'   => [],
        'model_used' => null,
        'words'      => 0,
        'started_at' => time(),
        'updated_at' => time(),
    ], JSON_UNESCAPED_UNICODE);
    if ($progressJson === false || file_put_contents($progressFile, $progressJson, LOCK_EX) === false) {
        throw new RuntimeException('无法创建写作进度文件');
    }
    WritingTaskRepository::createQueued(
        $taskId,
        $userId,
        $novelId,
        $chapterId ?: null,
        120,
        $writePolicySnapshot
    );

    // 进度文件已安全创建，提交 DB 事务释放排他锁
    // 此时其他并发请求会发现进度文件中已有运行中任务而被拒绝
    if (!$pdo->commit()) {
        throw new RuntimeException('写作任务事务提交失败');
    }
    $taskCommitted = true;
    $releaseProjectionFence();

    // 启动 CLI worker 后台进程
    $phpBin = PHP_BINARY ?: 'php';
    // Windows：Web 请求下 PHP_BINARY 多为 php-cgi.exe（nginx/FastCGI 的 SAPI），它**不能**当 CLI 跑 worker：
    //   - PHP_SAPI=cgi-fcgi，会被 write_chapter_worker.php 的「非 cli 即 403」硬校验立刻拒绝；
    //   - $argv 为 null，novel_id/task_id 全部丢失。
    // 必须替换为同目录的 php.exe（CLI）。路径模式可靠，open_basedir 不影响纯字符串替换。
    if (PHP_OS_FAMILY === 'Windows' && preg_match('#php-cgi\.exe$#i', $phpBin)) {
        $phpBin = preg_replace('#php-cgi\.exe$#i', 'php.exe', $phpBin);
    }
    // PHP-FPM 不能执行 CLI 脚本，需替换为 php CLI 二进制
    // 宝塔路径示例：/www/server/php/82/sbin/php-fpm → /www/server/php/82/bin/php
    // 注意：宝塔 open_basedir 限制会阻止 file_exists() 检查非项目路径，因此用 exec 绕过
    if (PHP_OS_FAMILY !== 'Windows' && preg_match('#/php-fpm\d*$#', $phpBin)) {
        $found = false;
        // exec 不受 open_basedir 限制，优先用 which 查找；但宝塔默认禁用 exec，
        // 调用被禁用函数会致命错误，故须 function_exists('exec') 守卫，不可用时直接走路径盲替换。
        if (function_exists('exec')) {
            @exec('which php 2>/dev/null', $whichOut, $whichCode);
            if (($whichCode ?? 1) === 0 && !empty($whichOut[0])) {
                $candidate = trim($whichOut[0]);
                // 确认候选路径真的可以执行 PHP 脚本
                @exec(escapeshellarg($candidate) . ' -r "echo 1;" 2>/dev/null', $rTest, $rCode);
                if (($rCode ?? 1) === 0) {
                    $phpBin = $candidate;
                    $found = true;
                }
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
        // 关键修复：Windows 后台进程必须用 start /B 启动“分离”进程。
        // 若直接 proc_open("php worker ...") 后 proc_close()，proc_close 会**阻塞到 worker
        // 写完整章**（数分钟），异步设计完全失效、请求挂死直至 Web 服务器超时。
        // start /B 让 worker 脱离父进程独立运行，外层命令瞬间返回，proc_close()/pclose() 不再阻塞。
        // 宝塔默认 disable_functions 禁用 exec/popen、仅保留 proc_open，故优先 proc_open。
        $winCmd = 'start /B "" "' . $phpBin . '" ' . $workerScript
                . ' ' . escapeshellarg((string)$novelId) . ' ' . escapeshellarg((string)$chapterId) . ' ' . escapeshellarg($taskId)
                . ' >> ' . escapeshellarg($logFilePath) . ' 2>&1';
        if ($procOpenOk) {
            $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open($winCmd, $descriptorspec, $pipes);
            if ($process !== false) {
                foreach ($pipes as $pp) { if (is_resource($pp)) fclose($pp); }
                proc_close($process);  // start 已返回，此处不阻塞
            }
        } elseif ($popenOk) {
            pclose(popen($winCmd, 'r'));
        } elseif ($execOk) {
            @exec($winCmd);  // start /B 立即返回，exec 不阻塞
        }
    } else {
        // Linux: Shell wrapper 双壳隔离
        // 直接 exec("php ... &") 在宝塔 FPM 下 worker 会被杀死
        // 通过 wrapper.sh 中间层：FPM→sh→exec php，中间层吸收信号
        $workerSh = $progressDir . '/' . $taskId . '.sh';
        file_put_contents($workerSh,
            "#!/bin/sh\n" .
            "cd " . escapeshellarg(dirname(__DIR__)) . "\n" .
            "exec " . escapeshellarg($phpBin) . " {$workerScript} " . escapeshellarg((string)$novelId) . ' ' . escapeshellarg((string)$chapterId) . ' ' . escapeshellarg($taskId) .
            " >> " . escapeshellarg($logFilePath) . " 2>&1\n"
        );
        @chmod($workerSh, 0755);
        $launchCmd = escapeshellarg($workerSh) . " > /dev/null 2>&1 &";
        if ($execOk) {
            exec($launchCmd);
        } elseif ($procOpenOk) {
            // exec 被宝塔禁用时的兜底：用 proc_open 后台启动 sh。命令以 & 结尾，
            // 外层 sh -c 立即返回、wrapper.sh 被重定向到 init 独立运行，proc_close 不阻塞。
            $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open('/bin/sh ' . $launchCmd, $descriptorspec, $pipes);
            if ($process !== false) {
                foreach ($pipes as $pp) { if (is_resource($pp)) fclose($pp); }
                proc_close($process);
            }
        }
        // exec 与 proc_open 都不可用时：下方轮询检测不到进度，自动返回 fallback_sse
        // wrapper.sh 在 worker 完成后随进度文件一起清理（僵死检测）
    }
    
    // 轮询确认进程已启动并写入有效状态
    // CLI worker 启动涉及 PHP 加载、DB 连接、配置读取等，冷启动可能需数秒
    $started = false;
    $progress = null;
    $workerError = null;  // worker 启动后若自报 error，记录其原因供诊断/回退
    $maxWait = 40;        // 最多轮询 40 次（从30增加到40）
    $waitInterval = 500000; // 每次 0.5 秒，总计 20 秒
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
            // worker 启动后立即报错（Windows/BaoTa 下多为 CLI 环境问题：php 路径、缺扩展、
            // SSL/CA、open_basedir 等）。不再硬失败抛 500 → 重置章节后回退 SSE 直连：
            // SSE 在 FPM 上下文执行，往往能避开 CLI 专属问题，让写作仍可进行。
            // 真实错误进入下方 !$started 的诊断日志，便于定位根因。
            $workerError = (string)($progress['error'] ?? '后台进程启动后立即失败');
            break;  // $started 仍为 false → 落到下方 !$started 的重置 + fallback_sse 处理
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
            'worker_error'        => $workerError,  // worker 自报错误（如有），定位 CLI 环境问题
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
        
        // Close only a still-unclaimed queued row. A worker may win the claim
        // immediately after the last progress-file poll; if so, do not delete
        // its progress file or reset its chapter/novel state.
        $launchClosed = WritingTaskRepository::markLaunchFailed(
            $taskId,
            $workerError ?: 'Worker process did not acquire its startup lease',
            'worker_start_failed'
        );
        if (!$launchClosed) {
            $lateTask = WritingTaskRepository::findByTaskId($taskId);
            $lateState = (string)($lateTask['state'] ?? '');
            $lateLeaseToken = (string)($lateTask['lease_token'] ?? '');
            $lateLeaseExpires = (string)($lateTask['lease_expires_at'] ?? '');
            if (in_array($lateState, ['preparing','generating','validating','content_saved','postprocessing'], true)
                && preg_match('/^[a-f0-9]{32}$/D', $lateLeaseToken) === 1
                && $lateLeaseExpires >= gmdate('Y-m-d H:i:s')) {
                echo json_encode([
                    'ok' => true,
                    'task_id' => $taskId,
                    'resumed' => true,
                    'harness_event_cursor' => $harnessEventCursorForTask($lateTask),
                    'msg' => 'Worker acquired its durable lease during startup confirmation.',
                    'chapter_id' => $lateTask['chapter_id'] ?? $chapterId,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        @unlink($progressFile);
        // 清理 wrapper.sh
        if (PHP_OS_FAMILY !== 'Windows') @unlink($workerSh);
        // worker 自己持有写入 fencing token，异常/退出恢复也只能由 worker 的精确
        // claim 路径完成。启动监督器不得批量修改章节或小说状态。
        // 返回 fallback_sse 标志，让前端自动回退到 SSE 直连模式。
        // 审计 P3-C（2026-06-11）：诊断信息（服务器路径/worker 日志）始终写服务端日志，
        // 仅 APP_DEBUG 开启时随响应附带，与 import_chapter_synopses 的 debug 门控约定一致。
        $rid = error_trace_id();
        error_log(sprintf('[%s] write_start launch failed diag: %s', $rid, json_encode($diag, JSON_UNESCAPED_UNICODE)));
        $resp = [
            'ok'           => false,
            'fallback_sse' => true,
            'msg'          => '后台进程启动失败，已自动切换到 SSE 直连模式',
            'request_id'   => $rid,
        ];
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $resp['debug_info'] = $diag;
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'ok'      => true,
        'task_id' => $taskId,
        'harness_event_cursor' => HarnessControlPlane::allowsNewHarnessRun(
            $writePolicySnapshot->harnessMode()
        ),
        'msg'     => '写作任务已启动',
    ], JSON_UNESCAPED_UNICODE);
    
} catch (\Throwable $e) {
    // [修复] 捕获 Throwable 而非仅 Exception：BaoTa Windows 下被禁用函数的致命 Error、
    // TypeError 等不是 Exception，原 catch(Exception) 漏接会变成裸 500（Internal Server Error）。
    // 释放可能持有的排他锁（无论异常发生在事务内还是事务外；$pdo 可能尚未定义）
    try { if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack(); }
    catch (\Throwable $e2) { error_log('write_start rollback(catch) failed: ' . $e2->getMessage()); }
    $releaseProjectionFence();
    if (!$taskCommitted && is_string($progressFile) && $progressFile !== '') {
        @unlink($progressFile);
        @unlink($progressFile . '.content');
    }
    echo json_encode(safe_api_error_payload($e, '写作启动失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
}
