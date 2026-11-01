<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI mode only\n"); }
$options = getopt('', ['help', 'create', 'verify', 'output:', 'snapshot:', 'expected-manifest-sha256:']);
if (isset($options['help'])) {
    echo "Usage: php bin/harness_archive_snapshot.php --create --output=EXISTING-ABSOLUTE-DIRECTORY\n"
        . "       php bin/harness_archive_snapshot.php --verify --snapshot=ABSOLUTE-SNAPSHOT-DIRECTORY [--expected-manifest-sha256=HASH]\n"
        . "Both directories must be outside the Web root and contain no links. Create reads Schema 69, 70, 71 or 72 only\n"
        . "(the code's own schema must be fully migrated; older ones only before migrating); stop all writers first.\n"
        . "Keep external maintenance active through this snapshot and its matching SQL backup. Verify is offline and never restores source paths.\n";
    exit(0);
}
try {
    $create = isset($options['create']);
    if ($create === isset($options['verify'])) throw new InvalidArgumentException('Choose exactly one of --create or --verify.');
    $argument = $create ? ($options['output'] ?? null) : ($options['snapshot'] ?? null);
    if (!is_string($argument) || $argument === '') throw new InvalidArgumentException('An explicit snapshot/output directory is required.');
    if (($create && (isset($options['snapshot']) || isset($options['expected-manifest-sha256'])))
        || (!$create && isset($options['output']))) throw new InvalidArgumentException('Options do not match the selected snapshot mode.');
    define('APP_LOADED', true);
    require_once dirname(__DIR__) . '/includes/backup/HarnessArchiveSnapshot.php';
    if ($create) {
        define('DB_SCHEMA_INSPECTION_MODE', true);
        require_once dirname(__DIR__) . '/config.php';
        require_once dirname(__DIR__) . '/includes/db.php';
        $status = DB::schemaStatus();
        $current = (int)($status['current'] ?? 0);
        // An older supported schema is the pre-migration snapshot; the code's own schema must be ready.
        if (!in_array($current, HarnessArchiveSnapshot::SUPPORTED_SCHEMAS, true)
            || ($current >= (int)($status['expected'] ?? 0) && !($status['ready'] ?? false))) {
            throw new RuntimeException('Archive snapshot requires Schema ' . implode(', ', HarnessArchiveSnapshot::SUPPORTED_SCHEMAS)
                . ', with the code\'s Schema ' . (int)($status['expected'] ?? 0) . ' fully migrated; current is ' . $current . '.');
        }
        $result = (new HarnessArchiveSnapshot(DB::connect(), dirname(__DIR__)))->create($argument);
    } else {
        $hash = $options['expected-manifest-sha256'] ?? null;
        if ($hash !== null && !is_string($hash)) throw new InvalidArgumentException('Expected manifest checksum is invalid.');
        $result = HarnessArchiveSnapshot::verify($argument, dirname(__DIR__), $hash);
    }
    echo json_encode($result + ['mode' => $create ? 'create' : 'verify'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
