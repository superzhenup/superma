<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/backfill/HarnessCanonBackfill.php';

$usage = static function (): string {
    return implode(PHP_EOL, [
        'Usage: php bin/backfill_harness.php [--dry-run|--apply] [--batch=N] [--resume=ID] [--json]',
        '  --dry-run   Inspect one bounded batch without writing (default).',
        '  --apply     Backfill one bounded batch in a transaction.',
        '  --batch=N   Scan 1..1000 historical rows (default 100).',
        '  --resume=ID Continue strictly after this chapter id (default 0).',
        '  --json      Emit machine-readable output.',
    ]) . PHP_EOL;
};

$arguments = array_slice($argv, 1);
// Pre-scan machine-readable mode so an invalid argument that appears before
// --json is still reported as JSON and can be handled reliably by automation.
$jsonRequested = in_array('--json', $arguments, true);

try {
    $options = HarnessCanonBackfillOptions::fromArgv($argv);
} catch (InvalidArgumentException $e) {
    if ($jsonRequested) {
        fwrite(STDERR, json_encode([
            'ok' => false,
            'error' => 'invalid_arguments',
            'message' => $e->getMessage(),
            'usage' => rtrim($usage()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDERR, 'Invalid arguments: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $usage());
    }
    exit(2);
}

if ($options->help) {
    if ($options->json) {
        echo json_encode([
            'ok' => true,
            'mode' => 'help',
            'usage' => rtrim($usage()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo $usage();
    }
    exit(0);
}

// A data backfill must never execute DDL implicitly. Operators run the
// explicit migration command first; this command checks the version read-only.
define('DB_SCHEMA_INSPECTION_MODE', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/backfill/HarnessCanonBackfillPdoStore.php';

try {
    $schema = DB::schemaStatus();
    if (!$schema['ready']) {
        throw new RuntimeException(sprintf(
            'Database schema is not ready (current=%d, expected=%d). Run: php bin/migrate.php --apply',
            $schema['current'],
            $schema['expected']
        ));
    }

    $backfill = new HarnessCanonBackfill(new HarnessCanonBackfillPdoStore(DB::connect()));
    $result = $backfill->run($options);
    $result['schema'] = $schema;

    if ($options->json) {
        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        printf(
            "Harness Canon backfill: mode=%s scanned=%d eligible=%d would_update=%d updated=%d conflicts=%d\n",
            $result['mode'],
            $result['scanned'],
            $result['eligible'],
            $result['would_update'],
            $result['updated'],
            $result['conflicts']
        );
        if ($options->apply) {
            printf(
                "Apply resume cursor: %d; has_more=%s\n",
                $result['resume_cursor'],
                $result['has_more'] ? 'yes' : 'no'
            );
        } else {
            printf(
                "Dry-run next scan cursor: %d; apply must restart at input cursor %d; has_more=%s\n",
                $result['next_scan_cursor'],
                $result['input_cursor'],
                $result['has_more'] ? 'yes' : 'no'
            );
        }
        $skippedTotal = array_sum($result['skipped']);
        printf(
            "Classification: already_current=%d skipped=%d not_completed=%d empty_content=%d "
                . "unsafe_metadata=%d rolled_back=%d needs_review=%s\n",
            $result['already_current'],
            $skippedTotal,
            $result['skipped']['not_completed'],
            $result['skipped']['empty_content'],
            $result['skipped']['unsafe_metadata'],
            $result['rolled_back'],
            $result['needs_review'] ? 'yes' : 'no'
        );
        if ($result['needs_review']) {
            echo "WARNING: unsafe Harness metadata requires manual review; no provenance was modified.\n";
        }
        if (!$options->apply && $result['would_update'] > 0) {
            echo "Dry-run only; rerun with --apply to persist this batch.\n";
        }
    }

    exit($result['ok'] ? 0 : 3);
} catch (Throwable $e) {
    if ($options->json) {
        fwrite(STDERR, json_encode([
            'ok' => false,
            'mode' => $options->apply ? 'apply' : 'dry-run',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDERR, 'Harness Canon backfill failed: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
