<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardWorker();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

/** @return never */
function outlineHarnessWorkerUsage(int $code): void
{
    $stream = $code === 0 ? STDOUT : STDERR;
    fwrite(
        $stream,
        "Usage: php bin/harness_worker.php --queue=outline|legacy-shadow|story-outline [--once] "
            . "[--sleep=5] [--lease=3600] [--max=1000] [--task=story-task-id]\n"
    );
    exit($code);
}

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true)) {
    outlineHarnessWorkerUsage(0);
}
foreach ($arguments as $argument) {
    if ($argument === '--once'
        || preg_match('/^--(?:queue|sleep|lease|max|task)=.+$/D', $argument) === 1) {
        continue;
    }
    outlineHarnessWorkerUsage(2);
}

$options = getopt('', ['queue:', 'once', 'sleep:', 'lease:', 'max:', 'task:']);
$queue = $options['queue'] ?? null;
if (!is_string($queue) || !in_array($queue, ['outline', 'legacy-shadow', 'story-outline'], true)) {
    outlineHarnessWorkerUsage(2);
}
$once = isset($options['once']);
$storyTaskId = $options['task'] ?? null;
if ($storyTaskId !== null && ($queue !== 'story-outline' || !is_string($storyTaskId)
    || preg_match('/^[a-f0-9]{32}$/D', $storyTaskId) !== 1)) {
    outlineHarnessWorkerUsage(2);
}
$sleepSeconds = max(1, min(30, (int)($options['sleep'] ?? 5)));
$leaseSeconds = max(15, min(3600, (int)($options['lease'] ?? 3600)));
$maxJobs = $once ? 1 : max(1, min(100000, (int)($options['max'] ?? 1000)));

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/OutlineQualityGuard.php';
require_once dirname(__DIR__) . '/includes/harness/outline/bootstrap.php';
require_once dirname(__DIR__) . '/includes/harness/jobs/LegacyJobShadow.php';

$host = preg_replace('/[^a-zA-Z0-9._-]/', '-', gethostname() ?: 'localhost');
$workerId = substr('outline-worker:' . $host . ':' . getmypid(), 0, 120);
$pdo = DB::getPdo();
$targetedStoryWorker = null;
$gateway = new GenerationRunOutlineGateway(new GenerationRunRepository($pdo));
$jobs = new HarnessJobRepository($pdo);
if ($queue === 'outline') {
    $mapper = new OutlineEnhancementJobMapper($jobs, $pdo);
    $runner = new OutlineEnhancementRunner(
        $gateway,
        new DbOutlineEnhancementStore($pdo),
        new LegacyOutlineEnhancer()
    );
    $jobWorker = new OutlineEnhancementJobWorker($jobs, $gateway, $runner, $mapper);
} elseif ($queue === 'story-outline') {
    // CLI execution is bounded by persisted task/stage deadlines and model
    // timeouts, rather than the web SAPI's configured execution-time counter.
    set_time_limit(0);
    require_once dirname(__DIR__) . '/includes/story_outline/StoryOutlineTaskService.php';
    require_once dirname(__DIR__) . '/includes/story_outline/StoryOutlineStageGenerator.php';
    require_once dirname(__DIR__) . '/includes/story_outline/StoryOutlinePostPublish.php';
    if ($storyTaskId !== null && !$once) {
        require_once dirname(__DIR__) . '/includes/story_outline/StoryOutlineTargetedWorker.php';
        $targetedStoryWorker = new StoryOutlineTargetedWorker($pdo, $storyTaskId);
    }
    // The story workflow uses Run/Step checkpoints directly; chapter candidate
    // approval and chapter-only Job adapters do not apply to a whole-book outline.
    $jobWorker = new class(new StoryOutlineTaskService($pdo), new StoryOutlineStageGenerator(), $storyTaskId) {
        public function __construct(
            private StoryOutlineTaskService $service,
            private StoryOutlineStageGenerator $generator,
            private ?string $taskId
        ) {}
        public function runNext(string $owner, int $leaseSeconds): ?array
        {
            StoryOutlinePostPublish::recoverPending(DB::getPdo());
            $result = $this->service->runNext([$this->generator, 'generate'], $this->taskId);
            if ($result && !empty($result['result_ready']) && !empty($result['task_id'])) {
                StoryOutlinePostPublish::tryApply(DB::getPdo(), (string)$result['task_id']);
            }
            return $result;
        }
    };
} else {
    $mapper = new LegacyJobShadowMapper($jobs, $pdo);
    $jobWorker = new LegacyJobShadowWorker($jobs, $mapper, $mapper);
}
$processed = 0;
$stopRequested = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$stopRequested): void {
        $stopRequested = true;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

try {
    while (!$stopRequested && $processed < $maxJobs) {
        maintenanceGuardWorker();
        if (function_exists('clearSystemSettingsCache')) {
            clearSystemSettingsCache();
        }
        $result = $jobWorker->runNext($workerId, $leaseSeconds);
        $targetFinished = $targetedStoryWorker !== null && !$targetedStoryWorker->shouldContinue();
        if ($result === null) {
            if ($once || $targetFinished) {
                break;
            }
            for ($waited = 0; $waited < $sleepSeconds && !$stopRequested; $waited++) {
                sleep(1);
            }
            continue;
        }
        $processed++;
        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if ($targetFinished) break;
    }
    fwrite(STDOUT, "Processed {$processed} {$queue} Job(s).\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Outline Harness worker failed (' . get_class($exception) . '): '
            . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
