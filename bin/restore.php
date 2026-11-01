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
    'from:', 'confirm:', 'mysql:', 'mysqldump:', 'replace-files', 'no-safety-backup', 'skip-drain-check', 'json',
]);
$source = (string)($options['from'] ?? '');
if ($source === '' || (string)($options['confirm'] ?? '') !== 'RESTORE') {
    fwrite(STDERR, "Usage: php bin/restore.php --from=/outside/backup --confirm=RESTORE [--replace-files]\n"
        . "  default: merge backed-up storage files into storage/ (nothing is deleted)\n"
        . "  --replace-files: make storage/covers, storage/author_works and storage/drama match the backup exactly,\n"
        . "    keeping each root's deployed .htaccess; refused for backups made before 2026-10-31 and for roots\n"
        . "    that contain links or junctions\n"
        . "  --skip-drain-check: explicitly allow unavailable drain queries after external checks; observed active work still refuses\n");
    exit(2);
}

try {
    $result = BackupManager::restore($source, [
        'mysql_bin' => $options['mysql'] ?? null,
        'mysqldump_bin' => $options['mysqldump'] ?? null,
        'replace_files' => isset($options['replace-files']),
        'no_safety_backup' => isset($options['no-safety-backup']),
        'skip_drain_check' => isset($options['skip-drain-check']),
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
    require_once dirname(__DIR__) . '/includes/maintenance.php';
    $maintenance = maintenanceStatus();
    $failure = ['ok' => false, 'error' => $e->getMessage(), 'maintenance' => $maintenance,
        'skip_drain_check_requested' => isset($options['skip-drain-check'])];
    if (isset($options['json'])) echo json_encode($failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . PHP_EOL);
    if ($maintenance['active']) {
        $marker = is_array($maintenance['marker']) ? $maintenance['marker'] : [];
        fwrite(STDERR, "MAINTENANCE ACTIVE. Keep gateway writes and workers stopped.\n"
            . 'Safety backup: ' . ($marker['safety_backup'] ?? '(none recorded)') . PHP_EOL
            . "Check the recovery runbook: schema/status, dump/file hashes, ledger/reconciliation and worker counts.\n"
            . "Inspect: php bin/maintenance.php --status --json\n"
            . "Only after checks: php bin/maintenance.php --clear --confirm=CLEAR\n");
    }
    exit(1);
}
