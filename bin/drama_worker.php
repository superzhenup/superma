<?php
/**
 * 漫剧任务 CLI worker。
 *
 * 用法：php bin/drama_worker.php [--max=N] [--loop] [--sleep=N]
 * 由 includes/drama/DramaWorkerLauncher.php 后台拉起，或计划任务手动执行。
 */
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

try {
    while ($processed < $maxJobs) {
        $result = DramaTaskRunner::runNext($workerId);
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
} catch (Throwable $e) {
    fwrite(STDERR, 'Drama worker failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
