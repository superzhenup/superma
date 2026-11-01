<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

$options = getopt('', [
    'apply', 'dry-run', 'grace-hours::', 'limit::', 'json', 'help',
]);
if (isset($options['help'])) {
    echo "Usage: php bin/harness_reconcile_side_effects.php --dry-run|--apply "
        . "[--grace-hours=24] [--limit=100] [--json]\n"
        . "Closes terminal Runs' blocking side effects after the grace window:\n"
        . "outstanding model-budget reservations (settled retained at their\n"
        . "estimate) and Attempts with unknown settlement (failed at their\n"
        . "reservation estimate). A genuine late settlement always wins the race.\n";
    exit(0);
}
$apply = isset($options['apply']);
$dryRun = isset($options['dry-run']);
$json = isset($options['json']);
if ($apply === $dryRun) {
    fwrite(STDERR, "Choose exactly one of --dry-run or --apply.\n");
    exit(2);
}

define('DB_SCHEMA_INSPECTION_MODE', true);
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/harness/retention/RetentionSideEffectReconciler.php';

$graceRaw = $options['grace-hours'] ?? '24';
$limitRaw = $options['limit'] ?? '100';
foreach (['grace-hours' => $graceRaw, 'limit' => $limitRaw] as $name => $raw) {
    if (!is_string($raw) || preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
        fwrite(STDERR, "Invalid --{$name}.\n");
        exit(2);
    }
}
$graceHours = (int)$graceRaw;
$limit = (int)$limitRaw;
if ($graceHours < 1 || $graceHours > 720 || $limit < 1 || $limit > 1000) {
    fwrite(STDERR, "Grace hours/limit are outside safe bounds.\n");
    exit(2);
}

try {
    // Inspection mode keeps connect() read-only at the schema level; the
    // reconciler owns its own explicit mutation transactions.
    $schema = DB::schemaStatus();
    if (!($schema['ready'] ?? false)) {
        throw new RuntimeException(sprintf(
            'database schema is not ready (current=%d, expected=%d)',
            (int)($schema['current'] ?? 0),
            (int)($schema['expected'] ?? 0)
        ));
    }
    $reconciler = new RetentionSideEffectReconciler(DB::connect());
    if ($dryRun) {
        $result = $reconciler->report($graceHours * 3600, $limit) + ['mode' => 'dry-run'];
    } else {
        $result = $reconciler->apply($graceHours * 3600, $limit) + ['mode' => 'apply'];
    }
    $result['ok'] = true;
    $result['grace_hours'] = $graceHours;
    echo $json
        ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL
        : sprintf(
            "Harness side-effect reconciliation (%s): %d reservations, %d attempts%s\n",
            $result['mode'],
            (int)($result['reservations'] ?? $result['abandoned_reservations'] ?? 0),
            (int)($result['attempts'] ?? $result['abandoned_attempts'] ?? 0),
            isset($result['run_ids']) && $result['run_ids'] !== []
                ? ' on runs ' . implode(',', $result['run_ids'])
                : ''
        );
    exit(0);
} catch (Throwable $exception) {
    $error = [
        'ok' => false,
        'error' => 'reconcile_failed',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ];
    fwrite(
        STDERR,
        $json
            ? json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL
            : 'Harness side-effect reconciliation failed: ' . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
