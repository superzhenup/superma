<?php
/**
 * 漫剧任务 CLI worker。
 *
 * 用法：php bin/drama_worker.php [--max=N] [--loop] [--sleep=N]
 * 由 includes/drama/DramaWorkerLauncher.php 后台拉起，或计划任务手动执行。
 */
require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardWorker();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/CliContext.php';
CliContext::activate();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/drama/DramaTaskRunner.php';

$options = getopt('', ['once', 'loop', 'max:', 'sleep:']);
$loop = isset($options['loop']);
$maxJobs = max(1, min(200, (int)($options['max'] ?? ($loop ? 200 : 50))));
$sleepSeconds = max(1, min(30, (int)($options['sleep'] ?? 5)));
$workerId = 'drama-cli:' . (gethostname() ?: 'localhost') . ':' . getmypid();
$processed = 0;

// 审计修复（2026-08-30 M-2）：同 bin/postprocess_worker.php——--loop 长驻模式的
// 基础设施级异常（claimNext 的 DB 连接错误等）退避自愈，一次 DB 抖动不再杀死
// 整个循环进程。单任务错误已被 DramaTaskRunner 的 markRetryOrFailed 兜住。
$MAX_BACKOFF_SECONDS = 60;
$MAX_CONSECUTIVE_FAILURES = 60; // 约 1 小时仍不可用则放弃
$consecutiveFailures = 0;

while ($processed < $maxJobs) {
    maintenanceGuardWorker();
    // 审计修复（2026-08-16 #12）：同 bin/postprocess_worker.php——长驻进程的
    // 进程级缓存在任务边界失效，防止跨小时运行时读到过期配置（脏读）。
    if (function_exists('clearSystemSettingsCache')) {
        clearSystemSettingsCache();
    }

    try {
        $result = DramaTaskRunner::runNext($workerId);
    } catch (Throwable $e) {
        if (!$loop) {
            fwrite(STDERR, 'Drama worker failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        $consecutiveFailures++;
        $backoff = (int)min($MAX_BACKOFF_SECONDS, 5 * $consecutiveFailures);
        fwrite(STDERR, sprintf(
            "Drama loop error #%d (backoff %ds): %s\n",
            $consecutiveFailures,
            $backoff,
            $e->getMessage()
        ));
        if ($consecutiveFailures >= $MAX_CONSECUTIVE_FAILURES) {
            fwrite(STDERR, 'Drama loop giving up after persistent failures.' . PHP_EOL);
            exit(1);
        }
        sleep($backoff);
        continue;
    }

    // 成功推进（无论是否处理到任务）即重置失败计数。
    $consecutiveFailures = 0;

    if ($result === null) {
        if (!$loop) break;
        sleep($sleepSeconds);
        continue;
    }
    $processed++;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (!$loop && isset($options['once'])) break;
}
echo "Processed {$processed} drama task(s)." . PHP_EOL;
exit(0);
