<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

/** @return never */
function harnessReconcileUsage(int $code = 0, bool $json = false): void
{
    $stream = $code === 0 ? STDOUT : STDERR;
    $usage = "Usage: php bin/reconcile_harness.php [--scope=all] [--limit=20] [--json]\n"
        . "Scopes: all, chapters, candidates, jobs, leases, orphans, runs, "
        . "novel:<id>, chapter:<id>\n"
        . "--limit controls the maximum number of redacted samples per issue type (1..1000).";
    if ($json) {
        $payload = [
            'ok' => $code === 0,
            'error' => $code === 0 ? null : 'invalid_arguments',
            'usage' => $usage,
        ];
        fwrite(
            $stream,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    } else {
        fwrite($stream, $usage . PHP_EOL);
    }
    exit($code);
}

$scope = 'all';
$limit = 20;
$arguments = array_slice($argv, 1);
// Pre-scan so an invalid option preceding --json still receives a JSON error.
$json = in_array('--json', $arguments, true);
$seen = [];
foreach ($arguments as $argument) {
    if ($argument === '--help') {
        harnessReconcileUsage(0, $json);
    }
    if ($argument === '--json') {
        if (isset($seen['json'])) {
            harnessReconcileUsage(2, $json);
        }
        $seen['json'] = true;
        continue;
    }
    if (str_starts_with($argument, '--scope=')) {
        if (isset($seen['scope'])) {
            harnessReconcileUsage(2, $json);
        }
        $seen['scope'] = true;
        $scope = substr($argument, strlen('--scope='));
        continue;
    }
    if (str_starts_with($argument, '--limit=')) {
        if (isset($seen['limit'])) {
            harnessReconcileUsage(2, $json);
        }
        $seen['limit'] = true;
        $rawLimit = substr($argument, strlen('--limit='));
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawLimit) !== 1) {
            harnessReconcileUsage(2, $json);
        }
        $parsedLimit = filter_var($rawLimit, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if (!is_int($parsedLimit)) {
            harnessReconcileUsage(2, $json);
        }
        $limit = $parsedLimit;
        continue;
    }
    harnessReconcileUsage(2, $json);
}

define('DB_SCHEMA_INSPECTION_MODE', true);
define('APP_LOADED', true);

require_once dirname(__DIR__) . '/includes/quality/HarnessReconciler.php';

try {
    HarnessReconciler::parseScope($scope);
} catch (InvalidArgumentException $exception) {
    $error = ['ok' => false, 'error' => 'invalid_arguments', 'message' => $exception->getMessage()];
    if ($json) {
        fwrite(STDERR, json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } else {
        fwrite(STDERR, 'Harness reconciliation argument error: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(2);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

try {
    // Inspection mode guarantees connect() cannot auto-apply DDL. A stale
    // schema is an operational error, not something this read-only command
    // attempts to repair.
    $schema = DB::schemaStatus();
    if (!($schema['ready'] ?? false)) {
        throw new RuntimeException(sprintf(
            'database schema is not ready (current=%d, expected=%d)',
            (int)($schema['current'] ?? 0),
            (int)($schema['expected'] ?? 0)
        ));
    }

    DB::query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    if (!DB::beginTransaction()) {
        throw new RuntimeException('could not start read-only reconciliation snapshot');
    }
    try {
        $report = (new HarnessReconciler())->reconcile($scope, $limit);
    } finally {
        if (DB::inTransaction() && !DB::rollBack()) {
            throw new RuntimeException('could not close read-only reconciliation snapshot');
        }
    }
    if ($json) {
        $encoded = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if (!is_string($encoded)) {
            throw new RuntimeException('could not encode reconciliation report');
        }
        echo $encoded . PHP_EOL;
    } else {
        printf(
            "Harness reconciliation: %s\nscope=%s checked_at=%s datasets=%d rows=%d violations=%d\n",
            $report['ok'] ? 'OK' : 'FAILED',
            $report['scope'],
            $report['checked_at'],
            $report['summary']['datasets'],
            $report['summary']['rows'],
            $report['summary']['violations']
        );
        foreach ($report['counts'] as $type => $count) {
            printf("%-36s %d\n", $type . ':', $count);
            foreach ($report['samples'][$type] as $sample) {
                $encoded = json_encode(
                    $sample,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                echo '  - ' . (is_string($encoded) ? $encoded : '{"redacted":true}') . PHP_EOL;
            }
        }
    }
    exit($report['ok'] ? 0 : 3);
} catch (InvalidArgumentException $exception) {
    $error = ['ok' => false, 'error' => 'invalid_arguments', 'message' => $exception->getMessage()];
    if ($json) {
        fwrite(STDERR, json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } else {
        fwrite(STDERR, 'Harness reconciliation argument error: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(2);
} catch (Throwable $exception) {
    $error = ['ok' => false, 'error' => 'database_or_runtime_error', 'message' => $exception->getMessage()];
    if ($json) {
        fwrite(STDERR, json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } else {
        fwrite(STDERR, 'Harness reconciliation failed: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}
