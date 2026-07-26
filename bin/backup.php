<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

define('APP_LOADED', true);
// Upgrades must be able to back up an older schema before --apply is executed.
define('DB_SCHEMA_INSPECTION_MODE', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/backup/BackupManager.php';

$options = getopt('', ['output:', 'mysqldump:', 'json']);
try {
    $result = BackupManager::create((string)($options['output'] ?? ''), [
        'mysqldump_bin' => $options['mysqldump'] ?? null,
    ]);
    if (isset($options['json'])) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo 'Verified backup created: ' . $result['path'] . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
