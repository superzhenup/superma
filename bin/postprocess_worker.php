<?php
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

$options = getopt('', ['once', 'loop', 'max:', 'sleep:']);
$loop = isset($options['loop']);
$maxJobs = max(1, min(1000, (int)($options['max'] ?? ($loop ? 1000 : 1))));
$sleepSeconds = max(1, min(30, (int)($options['sleep'] ?? 5)));
$workerId = 'postprocess-cli:' . (gethostname() ?: 'localhost') . ':' . getmypid();
$processed = 0;

try {
    while ($processed < $maxJobs) {
        $result = PostprocessRunner::runNext($workerId);
        if ($result === null) {
            if (!$loop) break;
            sleep($sleepSeconds);
            continue;
        }

        $processed++;
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (!$loop && isset($options['once'])) break;
    }
    echo "Processed {$processed} post-processing job(s)." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Post-processing worker failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
