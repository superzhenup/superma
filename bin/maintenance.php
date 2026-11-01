<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI mode only\n"); }
require_once dirname(__DIR__) . '/includes/maintenance.php';
$options = getopt('', ['status', 'json', 'clear', 'confirm:']);
if (isset($options['status']) === isset($options['clear'])) {
    fwrite(STDERR, "Usage: php bin/maintenance.php --status [--json] | --clear --confirm=CLEAR\n");
    exit(2);
}
if (isset($options['status'])) {
    $status = maintenanceStatus();
    echo isset($options['json']) ? json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : ($status['active'] ? 'Maintenance active' : 'Maintenance inactive') . PHP_EOL
            . json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
// Share the restore lock: never clear a live restore's marker.
$lock = @fopen(dirname(maintenanceFilePath()) . '/.restore.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Restore is active or the restore lock cannot be acquired; marker was not cleared.\n");
    exit(1);
}
try {
    $status = maintenanceStatus();
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "Complete the recovery runbook checks (schema, hashes, ledger/reconciliation, files, workers=0) before clearing.\n";
    if (($options['confirm'] ?? '') !== 'CLEAR') {
        fwrite(STDERR, "Refused: pass --confirm=CLEAR after completing those checks.\n");
        exit(2);
    }
    if ($status['active'] && !unlink(maintenanceFilePath())) throw new RuntimeException('Unable to clear maintenance marker.');
    echo "Maintenance marker cleared; gateway and worker controls must be restored separately.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally { flock($lock, LOCK_UN); fclose($lock); }
