<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

define('APP_LOADED', true);
define('DB_SCHEMA_INSPECTION_MODE', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/backup/BackupManager.php';

$options = getopt('', [
    'from:', 'confirm:', 'mysql:', 'mysqldump:', 'replace-files', 'no-safety-backup', 'json',
]);
$source = (string)($options['from'] ?? '');
if ($source === '' || (string)($options['confirm'] ?? '') !== 'RESTORE') {
    fwrite(STDERR, "Usage: php bin/restore.php --from=/outside/backup --confirm=RESTORE [--replace-files]\n");
    exit(2);
}

try {
    $result = BackupManager::restore($source, [
        'mysql_bin' => $options['mysql'] ?? null,
        'mysqldump_bin' => $options['mysqldump'] ?? null,
        'replace_files' => isset($options['replace-files']),
        'no_safety_backup' => isset($options['no-safety-backup']),
    ]);
    if (isset($options['json'])) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "Restore completed and backup hashes were verified.\n";
        if ($result['safety_backup']) echo 'Pre-restore safety backup: ' . $result['safety_backup'] . PHP_EOL;
        if ($result['migration_required']) echo "Run: php bin/migrate.php --apply\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
