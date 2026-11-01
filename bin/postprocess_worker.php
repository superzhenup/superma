<?php
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
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';
require_once dirname(__DIR__) . '/includes/harness/support/HarnessRuntime.php';

$options = getopt('', ['once', 'loop', 'max:', 'sleep:']);
$loop = isset($options['loop']);
$maxJobs = max(1, min(1000, (int)($options['max'] ?? ($loop ? 1000 : 1))));
$sleepSeconds = max(1, min(30, (int)($options['sleep'] ?? 5)));
$workerId = 'postprocess-cli:' . (gethostname() ?: 'localhost') . ':' . getmypid();
$processed = 0;

// 审计修复（2026-08-30 M-2）：--loop 长驻模式的基础设施级异常自愈。
// 原实现 claimNext（DB::connect 层）抛出的连接异常直接冒到外层 catch → exit(1)，
// 一次 DB 抖动即杀死整个循环进程，且无监督者重启。单任务错误已被
// runClaimed 兜住（markRetryOrFailed），这里只处理基础设施级故障：
// 退避重试（5s→10s→…→60s 封顶），连续失败超过上限才放弃退出。
$MAX_BACKOFF_SECONDS = 60;
$MAX_CONSECUTIVE_FAILURES = 60; // 约 1 小时仍不可用则放弃
$consecutiveFailures = 0;

while ($processed < $maxJobs) {
    maintenanceGuardWorker();
    // 审计修复（2026-08-16 #12）：长驻进程的进程级缓存在任务边界失效——
    // --loop 模式跨小时运行时，settings.php 修改的配置（_systemSettingsAll）
    // 与 EmbeddingProvider 配置（::resetConfig 由 B 组修复提供）对运行中
    // worker 不可见，属脏读；每处理完一个任务失效一次，兼顾新鲜度与查询开销。
    if (function_exists('clearSystemSettingsCache')) {
        clearSystemSettingsCache();
    }
    if (class_exists('EmbeddingProvider', false) && method_exists('EmbeddingProvider', 'resetConfig')) {
        EmbeddingProvider::resetConfig();
    }

    try {
        $result = PostprocessRunner::runNext($workerId);
    } catch (Throwable $e) {
        if (!$loop) {
            fwrite(STDERR, 'Post-processing worker failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        $consecutiveFailures++;
        $backoff = (int)min($MAX_BACKOFF_SECONDS, 5 * $consecutiveFailures);
        fwrite(STDERR, sprintf(
            "Post-processing loop error #%d (backoff %ds): %s\n",
            $consecutiveFailures,
            $backoff,
            $e->getMessage()
        ));
        if ($consecutiveFailures >= $MAX_CONSECUTIVE_FAILURES) {
            fwrite(STDERR, 'Post-processing loop giving up after persistent failures.' . PHP_EOL);
            exit(1);
        }
        HarnessRuntime::sleepSeconds($backoff);
        continue;
    }

    // 成功推进（无论是否处理到任务）即重置失败计数。
    $consecutiveFailures = 0;

    if ($result === null) {
        if (!$loop) break;
        HarnessRuntime::sleepSeconds($sleepSeconds);
        continue;
    }

    $processed++;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (!$loop && isset($options['once'])) break;
}
echo "Processed {$processed} post-processing job(s)." . PHP_EOL;
exit(0);
