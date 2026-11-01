<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI mode only\n"); }
$options = getopt('', ['help', 'dry-run', 'apply', 'run-id:', 'operation-uid:',
    'request-hash:', 'evidence-file:', 'result-file:', 'grace-hours:', 'limit:']);
if (isset($options['help'])) {
    echo "Usage: php bin/harness_reconcile_operations.php --run-id=N --dry-run [--limit=100]\n"
        . "       php bin/harness_reconcile_operations.php --run-id=N --apply --operation-uid=HASH\n"
        . "         --request-hash=HASH --evidence-file=FILE [--result-file=FILE] [--grace-hours=24]\n"
        . "Evidence JSON: {\"outcome\":\"applied|not_applied\",\"reference\":\"receipt reference\",\"evidence_hash\":\"SHA256\"}\n"
        . "applied requires result JSON; not_applied forbids it. Unknown is never automatically released.\n"
        . "Apply requires a terminal Run, expired lease and elapsed grace period; drain writers first.\n";
    exit(0);
}
try {
    $apply = isset($options['apply']);
    if ($apply === isset($options['dry-run'])) throw new InvalidArgumentException('Choose exactly one of --dry-run or --apply.');
    $integer = static function (string $key, ?string $default = null) use ($options): int {
        $value = $options[$key] ?? $default;
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,8}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid --' . $key . '.');
        }
        return (int)$value;
    };
    $runId = $integer('run-id');
    $graceHours = $integer('grace-hours', '24');
    $limit = $integer('limit', '100');
    if ($graceHours > 720 || $limit > 1000) throw new InvalidArgumentException('Grace hours/limit exceed safe bounds.');
    $readJson = static function (mixed $path): array {
        if (!is_string($path) || !is_file($path) || filesize($path) > 1048576) {
            throw new InvalidArgumentException('JSON input must be a readable file of at most 1 MiB.');
        }
        $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) throw new InvalidArgumentException('JSON input must be an object.');
        return $data;
    };
    $evidence = $result = null;
    if ($apply) {
        $evidence = $readJson($options['evidence-file'] ?? null);
        if (isset($options['result-file'])) $result = $readJson($options['result-file']);
        foreach (['operation-uid','request-hash'] as $field) {
            if (!is_string($options[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $options[$field]) !== 1) {
                throw new InvalidArgumentException('Missing or invalid --' . $field . '.');
            }
        }
    }
    define('DB_SCHEMA_INSPECTION_MODE', true);
    define('APP_LOADED', true);
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/harness/side_effects/RunSideEffectRegistry.php';
    if (!(DB::schemaStatus()['ready'] ?? false)) throw new RuntimeException('Database schema is not ready.');
    $pdo = DB::connect();
    $statement = $pdo->prepare('SELECT run_uid FROM generation_runs WHERE id=?');
    $statement->execute([$runId]);
    $runUid = $statement->fetchColumn();
    if (!is_string($runUid)) throw new RuntimeException('Run does not exist.');
    $registry = new RunSideEffectRegistry($pdo, $runId, $runUid);
    if ($apply) {
        $registry->reconcile($options['operation-uid'], $options['request-hash'], $evidence, $result, $graceHours * 3600);
    }
    echo json_encode(['ok' => true, 'mode' => $apply ? 'apply' : 'dry-run',
        'run_id' => $runId, 'pending' => $registry->pending($limit)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'exception' => $error::class,
        'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
