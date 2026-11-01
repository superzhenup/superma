<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

$options = getopt('', [
    'archive:', 'verify', 'export', 'output:', 'dry-run', 'apply', 'json', 'help',
]);
if (isset($options['help'])) {
    echo "Usage:\n"
        . "  php bin/harness_retention_restore.php --verify --archive=<absolute-file> [--json]\n"
        . "  php bin/harness_retention_restore.php --export --archive=<absolute-file> "
            . "--output=<absolute-protected-json> [--json]\n"
        . "  php bin/harness_retention_restore.php --dry-run --archive=<absolute-file> [--json]\n"
        . "  php bin/harness_retention_restore.php --apply --archive=<absolute-file> [--json]\n";
    exit(0);
}
$modes = array_values(array_filter([
    'verify' => isset($options['verify']),
    'export' => isset($options['export']),
    'dry-run' => isset($options['dry-run']),
    'apply' => isset($options['apply']),
]));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one of --verify, --export, --dry-run or --apply.\n");
    exit(2);
}
$mode = isset($options['verify']) ? 'verify'
    : (isset($options['export']) ? 'export'
        : (isset($options['dry-run']) ? 'dry-run' : 'apply'));
$archivePath = $options['archive'] ?? null;
if (!is_string($archivePath) || $archivePath === '') {
    fwrite(STDERR, "Every mode requires --archive=<absolute-file>.\n");
    exit(2);
}
if ($mode === 'export' && !is_string($options['output'] ?? null)) {
    fwrite(STDERR, "--export requires --output=<absolute-protected-json>.\n");
    exit(2);
}
if ($mode !== 'export' && array_key_exists('output', $options)) {
    fwrite(STDERR, "--output is valid only with --export.\n");
    exit(2);
}

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/harness/retention/bootstrap.php';

$json = isset($options['json']);
try {
    $service = new RetentionRestoreService(new RetentionArchiveReader());
    $archive = $service->verify($archivePath, dirname(__DIR__));
    if ($mode === 'verify') {
        $result = [
            'ok' => true,
            'mode' => 'verify',
            'archive_uid' => $archive->archiveUid,
            'run_id' => $archive->runId,
            'run_uid' => $archive->runUid,
            'content_hash' => $archive->contentHash,
            'snapshot_hash' => $archive->snapshotHash,
            'size_bytes' => strlen($archive->bytes),
            'event_count' => $archive->eventCount,
            'artifact_count' => $archive->artifactCount,
            'first_event_no' => $archive->firstEventNo,
            'last_event_no' => $archive->lastEventNo,
        ];
    } elseif ($mode === 'export') {
        $export = $service->rehydrateExport($archive);
        $path = (new ImmutableRetentionRehydrateExporter())->persist(
            $options['output'],
            $export,
            dirname(__DIR__)
        );
        $result = [
            'ok' => true,
            'mode' => 'export',
            'archive_uid' => $archive->archiveUid,
            'run_id' => $archive->runId,
            'output_path' => $path,
            'output_hash' => hash('sha256', $export),
            'size_bytes' => strlen($export),
        ];
    } else {
        // Database configuration is intentionally loaded only after the file
        // passes standalone verification. Verify/export never touch a DB.
        define('DB_SCHEMA_INSPECTION_MODE', true);
        require_once dirname(__DIR__) . '/config.php';
        require_once dirname(__DIR__) . '/includes/db.php';
        $result = $service->restore(
            $archive,
            new M65RunRetentionRestoreRepository(DB::connect()),
            $mode === 'apply'
        )->toArray();
    }
    echo $json
        ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            . PHP_EOL
        : sprintf(
            "Harness retention restore %s verified archive %s for Run %d%s\n",
            $mode,
            $archive->archiveUid,
            $archive->runId,
            ($result['idempotent'] ?? false) ? ' (already restored)' : ''
        );
    exit(0);
} catch (Throwable $exception) {
    $error = [
        'ok' => false,
        'error' => 'retention_restore_failed',
        'mode' => $mode,
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ];
    fwrite(
        STDERR,
        $json
            ? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL
            : 'Harness retention restore failed: ' . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
