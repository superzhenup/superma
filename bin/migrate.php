<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

$allowed = ['--apply', '--status', '--json', '--help'];
foreach (array_slice($argv, 1) as $argument) {
    if (!in_array($argument, $allowed, true)) {
        fwrite(STDERR, "Unknown option {$argument}. Use --help.\n");
        exit(2);
    }
}
$options = getopt('', ['apply', 'status', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/migrate.php [--status|--apply] [--json]\n"
        . "  --status  Verify the current schema without applying DDL (default).\n"
        . "  --apply   Apply forward migrations, then verify the complete schema.\n"
        . "  --json    Emit a machine-readable status result.\n";
    exit(0);
}
if (isset($options['apply'], $options['status'])) {
    fwrite(STDERR, "Choose at most one of --status or --apply.\n");
    exit(2);
}
$apply = isset($options['apply']);
// --status is the safe default; --apply is the only path allowed to execute DDL.
if ($apply) {
    define('DB_MIGRATION_MODE', true);
} else {
    define('DB_SCHEMA_INSPECTION_MODE', true);
}

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/schema.php';

try {
    if ($apply) {
        DB::applyMigrations();
    }
    $pdo = DB::connect();
    $status = DB::schemaStatus();
    $verification = $status['ready'] ? Schema::verify($pdo) : ['ok' => false, 'missing' => []];
    $result = [
        'ok' => $status['ready'] && $verification['ok'],
        'mode' => $apply ? 'apply' : 'status',
        'schema' => $status,
        'missing_tables' => $verification['missing'],
    ];

    if (isset($options['json'])) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        printf(
            "Database schema: current=%d expected=%d ready=%s\n",
            $status['current'],
            $status['expected'],
            $result['ok'] ? 'yes' : 'no'
        );
        if ($verification['missing'] !== []) {
            echo 'Missing tables: ' . implode(', ', $verification['missing']) . PHP_EOL;
        }
    }
    exit($result['ok'] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration ' . ($apply ? 'apply' : 'status') . ' failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
