<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

$options = getopt('', [
    'apply', 'dry-run', 'archive-dir:', 'days::', 'limit::', 'json', 'help',
]);
if (isset($options['help'])) {
    echo "Usage: php bin/harness_retention.php --dry-run|--apply "
        . "[--archive-dir=<absolute-outside-web-root>] [--days=90] [--limit=100] [--json]\n";
    exit(0);
}
$apply = isset($options['apply']);
$dryRun = isset($options['dry-run']);
$json = isset($options['json']);
if ($apply === $dryRun) {
    fwrite(STDERR, "Choose exactly one of --dry-run or --apply.\n");
    exit(2);
}
if ($apply && !is_string($options['archive-dir'] ?? null)) {
    fwrite(STDERR, "--apply requires --archive-dir.\n");
    exit(2);
}

define('DB_SCHEMA_INSPECTION_MODE', true);
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/harness/retention/bootstrap.php';

// An explicit CLI value is an operator override.  Otherwise the same governed
// setting captured by HarnessFeatureSnapshot is the single production default.
// ConfigCenter already fails safely to the supplied default if the settings
// table is temporarily unavailable.
$daysRaw = array_key_exists('days', $options)
    ? $options['days']
    : (string)ConfigCenter::get('ws_harness_artifact_retention_days', 90, 'int');
$limitRaw = $options['limit'] ?? '100';
foreach (['days' => $daysRaw, 'limit' => $limitRaw] as $name => $raw) {
    if (!is_string($raw) || preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
        fwrite(STDERR, "Invalid --{$name}.\n");
        exit(2);
    }
}
$days = (int)$daysRaw;
$limit = (int)$limitRaw;
if ($days < 1 || $days > 3650 || $limit < 1 || $limit > 1000) {
    fwrite(STDERR, "Retention days/limit are outside safe bounds.\n");
    exit(2);
}

try {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $cutoff = $now->modify("-{$days} days");
    $repository = new M65RunRetentionRepository(DB::connect());
    if ($dryRun) {
        $ids = $repository->eligibleRunIds($cutoff, $limit);
        $result = [
            'ok' => true,
            'mode' => 'dry-run',
            'cutoff' => $cutoff->format('Y-m-d H:i:s.u'),
            'eligible_count' => count($ids),
            'eligible_run_ids' => $ids,
        ];
    } else {
        $service = new RetentionArchiveService(
            $repository,
            new FilesystemRetentionArchiveSink(
                $options['archive-dir'],
                dirname(__DIR__)
            )
        );
        $batch = $service->archiveEligible($now, $days, $limit);
        $result = [
            'ok' => true,
            'mode' => 'apply',
            'scanned' => $batch->scanned,
            'archived' => $batch->archived,
            'archive_uids' => $batch->archiveUids,
            'stale_run_ids' => $batch->staleRunIds,
        ];
    }
    echo $json
        ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL
        : sprintf(
            "Harness retention %s: %d eligible/scanned, %d archived\n",
            $result['mode'],
            $result['eligible_count'] ?? $result['scanned'],
            $result['archived'] ?? 0
        );
    exit(0);
} catch (Throwable $exception) {
    $error = [
        'ok' => false,
        'error' => 'retention_failed',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ];
    fwrite(
        STDERR,
        $json
            ? json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL
            : 'Harness retention failed: ' . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
