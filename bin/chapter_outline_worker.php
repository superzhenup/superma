<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardWorker();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

if (count($argv) !== 3 || preg_match('/^[1-9][0-9]*$/D', $argv[1]) !== 1
    || filter_var($argv[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
    || preg_match('/^[a-f0-9]{12}$/D', $argv[2]) !== 1) {
    fwrite(STDERR, "Usage: php bin/chapter_outline_worker.php <novelId> <requestId>\n");
    exit(2);
}

if (!defined('APP_LOADED')) define('APP_LOADED', true);
$chapterOutlineWorkerContext = null;
try {
    require_once dirname(__DIR__) . '/includes/ChapterOutlineWorkerContext.php';
    // Claim before loading config, sessions or DB. CLI arguments cannot supply
    // an owner or chapter range, and an absent/stale claim performs no model work.
    $chapterOutlineWorkerContext = ChapterOutlineWorkerContext::claim((int)$argv[1], $argv[2]);
    if ($chapterOutlineWorkerContext === null) exit(0);

    define('CHAPTER_OUTLINE_WORKER_REQUEST_ID', $chapterOutlineWorkerContext->requestId());
    if (!function_exists('error_trace_id')) {
        function error_trace_id(): string { return CHAPTER_OUTLINE_WORKER_REQUEST_ID; }
    } elseif (error_trace_id() !== $chapterOutlineWorkerContext->requestId()) {
        throw new RuntimeException('Worker request trace identity does not match its claim.');
    }
    if (!defined('API_VIA_ROUTER')) define('API_VIA_ROUTER', true);
    require dirname(__DIR__) . '/api/generate_outline.php';

    $snapshot = $chapterOutlineWorkerContext->store()->snapshot(
        $chapterOutlineWorkerContext->userId(), (int)$argv[1], $chapterOutlineWorkerContext->requestId()
    );
    if (($snapshot['state'] ?? null) !== 'saved') {
        $chapterOutlineWorkerContext->trace()->phase('failed');
        exit(1);
    }
} catch (Throwable $error) {
    if ($chapterOutlineWorkerContext instanceof ChapterOutlineWorkerContext) {
        $chapterOutlineWorkerContext->trace()->phase('failed');
    }
    // Exception bodies may contain config, credentials or model text.
    error_log('[' . $argv[2] . '] chapter_outline worker failed (' . get_class($error) . ')');
    fwrite(STDERR, 'Chapter outline worker failed; request ' . $argv[2] . ".\n");
    exit(1);
}
