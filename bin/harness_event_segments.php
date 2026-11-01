<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}
$options = getopt('', ['action:', 'run-id:', 'archive-dir:', 'apply', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/harness_event_segments.php --action=enable|archive-tail|checkpoint|restore-events --run-id=N --dry-run|--apply [--archive-dir=absolute-outside-web-root]\n";
    exit(0);
}
$action = $options['action'] ?? '';
$id = $options['run-id'] ?? '';
$apply = isset($options['apply']);
if (!in_array($action, ['enable','archive-tail','checkpoint','restore-events'], true)
    || !is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id)
    || $apply === isset($options['dry-run'])
    || ($action === 'archive-tail' && !is_string($options['archive-dir'] ?? null))) {
    fwrite(STDERR, "Invalid arguments; run --help. Explicit --apply or --dry-run is required.\n");
    exit(2);
}
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/harness/retention/bootstrap.php';
require_once dirname(__DIR__) . '/includes/harness/events/RunPollEventReplayer.php';
try {
    $connection = DB::connect();
    $service = new EventSegmentService($connection);
    $result = match ($action) {
        'enable' => $service->enableContinuation((int)$id, $apply),
        'archive-tail' => $service->archiveTail((int)$id,
            new FilesystemRetentionArchiveSink($options['archive-dir'], dirname(__DIR__)), $apply),
        'checkpoint' => (new EventProjectionCheckpointStore($connection))->create((int)$id, $apply),
        'restore-events' => $service->restoreEvents((int)$id, $apply),
    };
    echo json_encode(['ok' => true, 'action' => $action, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'action' => $action, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
