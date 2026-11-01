<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

/** @return never */
function harnessReadinessUsage(int $code = 0, ?string $message = null): void
{
    $usage = 'Usage: php bin/harness_readiness.php [--dry-run|--apply] '
        . '[--window-minutes=60] [--novel-id=N] '
        . '[--workflow-name=NAME] [--workflow-version=VERSION] '
        . '[--rollout-baseline-file=PATH] [--rollout-series-file=PATH] '
        . '[threshold overrides]';
    $thresholdFlags = [
        '--min-terminal-runs=N', '--max-run-failure-rate=R',
        '--max-run-retry-rate=R', '--max-job-failure-rate=R',
        '--max-job-retry-rate=R', '--max-candidate-quarantine-rate=R',
        '--max-stale-approval-rate=R', '--max-run-p95-ms=N',
        '--max-job-p95-ms=N', '--max-backlog-seconds=N',
        '--max-events-per-run=R',
        '--max-events-per-run-second=R',
        '--max-expired-active-leases=N', '--max-expired-open-approvals=N',
    ];
    $payload = [
        'ok' => $code === 0,
        'error' => $code === 0 ? null : 'invalid_arguments',
        'message' => $message,
        'usage' => $usage,
        'threshold_overrides' => $thresholdFlags,
        'notes' => [
            'Output is always JSON.',
            'Omitting --apply is a dry run and can never change the emergency epoch.',
            '--window-minutes controls absolute SLOs; relative rollout proofs always collect a separate fixed five-minute snapshot.',
            'The series file contains prior candidate snapshots and the current five-minute snapshot is appended.',
        ],
    ];
    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    fwrite($code === 0 ? STDOUT : STDERR, (is_string($encoded) ? $encoded : '{}') . PHP_EOL);
    exit($code);
}

/** @return int */
function harnessReadinessInteger(string $raw, string $name, int $min, int $max): int
{
    if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1) {
        harnessReadinessUsage(2, "{$name} must be an integer.");
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max],
    ]);
    if (!is_int($value)) {
        harnessReadinessUsage(2, "{$name} is outside the supported range.");
    }
    return $value;
}

/** @return float */
function harnessReadinessNumber(string $raw, string $name): float
{
    if ($raw === '' || !is_numeric($raw)) {
        harnessReadinessUsage(2, "{$name} must be numeric.");
    }
    $value = (float)$raw;
    if (!is_finite($value) || $value < 0) {
        harnessReadinessUsage(2, "{$name} must be a finite non-negative number.");
    }
    return $value;
}

/** @return array<string,mixed> */
function harnessReadinessRolloutSnapshot(mixed $value, string $label): array
{
    if (is_array($value)
        && ($value['schema'] ?? null) === 'harness.rollout_observability.v1') {
        return $value;
    }
    if (is_array($value)
        && is_array($value['rollout_observability'] ?? null)
        && ($value['rollout_observability']['schema'] ?? null)
            === 'harness.rollout_observability.v1') {
        return $value['rollout_observability'];
    }
    harnessReadinessUsage(2, "{$label} does not contain a rollout observability snapshot.");
}

function harnessReadinessJsonFile(string $path, string $label): mixed
{
    if (strlen($path) > 4096) {
        harnessReadinessUsage(2, "{$label} path is too long.");
    }
    $resolved = realpath($path);
    if (!is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
        harnessReadinessUsage(2, "{$label} is not a readable regular file.");
    }
    $size = filesize($resolved);
    if (!is_int($size) || $size < 1 || $size > 4 * 1024 * 1024) {
        harnessReadinessUsage(2, "{$label} must be between 1 byte and 4 MiB.");
    }
    $json = file_get_contents($resolved);
    if (!is_string($json) || strlen($json) !== $size) {
        harnessReadinessUsage(2, "{$label} could not be read completely.");
    }
    try {
        return json_decode($json, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        harnessReadinessUsage(2, "{$label} is not valid JSON: {$exception->getMessage()}");
    }
}

/** @return list<array<string,mixed>> */
function harnessReadinessRolloutSeries(?string $path): array
{
    if ($path === null) {
        return [];
    }
    $decoded = harnessReadinessJsonFile($path, '--rollout-series-file');
    if (is_array($decoded) && !array_is_list($decoded)) {
        $decoded = $decoded['candidate_snapshots'] ?? $decoded['snapshots'] ?? null;
    }
    if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) > 287) {
        harnessReadinessUsage(
            2,
            '--rollout-series-file must contain at most 287 prior candidate snapshots.'
        );
    }
    $snapshots = [];
    foreach ($decoded as $index => $value) {
        $snapshots[] = harnessReadinessRolloutSnapshot(
            $value,
            "--rollout-series-file[{$index}]"
        );
    }
    return $snapshots;
}

/** @return array<string,mixed> */
function harnessReadinessNoopAction(bool $apply, string $reason): array
{
    return [
        'schema' => 'harness.automatic_stop.action.v1',
        'mode' => $apply ? 'apply' : 'dry_run',
        'applied' => false,
        'would_apply' => false,
        'reason' => $reason,
        'verified_breaches' => [],
        'epoch' => null,
        'incident_scope_fingerprint' => null,
        'deduplicated' => false,
        'disposition' => null,
        'source' => 'release_gate',
    ];
}

$values = [
    'window_minutes' => 60,
    'novel_id' => null,
    'workflow_name' => null,
    'workflow_version' => null,
    'rollout_baseline_file' => null,
    'rollout_series_file' => null,
    'min_terminal_runs' => 20,
    'max_run_failure_rate' => 0.05,
    'max_run_retry_rate' => 0.10,
    'max_job_failure_rate' => 0.05,
    'max_job_retry_rate' => 0.10,
    'max_candidate_quarantine_rate' => 0.10,
    'max_stale_approval_rate' => 0.05,
    'max_run_p95_ms' => 900000,
    'max_job_p95_ms' => 300000,
    'max_backlog_seconds' => 1800,
    'max_events_per_run' => 120.0,
    'max_events_per_run_second' => 2.0,
    'max_expired_active_leases' => 0,
    'max_expired_open_approvals' => 0,
];
$integerOptions = [
    'window-minutes' => ['window_minutes', 5, 10080],
    'novel-id' => ['novel_id', 1, PHP_INT_MAX],
    'min-terminal-runs' => ['min_terminal_runs', 1, 1000000],
    'max-run-p95-ms' => ['max_run_p95_ms', 0, PHP_INT_MAX],
    'max-job-p95-ms' => ['max_job_p95_ms', 0, PHP_INT_MAX],
    'max-backlog-seconds' => ['max_backlog_seconds', 0, 604800],
    'max-expired-active-leases' => ['max_expired_active_leases', 0, 1000000],
    'max-expired-open-approvals' => ['max_expired_open_approvals', 0, 1000000],
];
$numberOptions = [
    'max-run-failure-rate' => 'max_run_failure_rate',
    'max-run-retry-rate' => 'max_run_retry_rate',
    'max-job-failure-rate' => 'max_job_failure_rate',
    'max-job-retry-rate' => 'max_job_retry_rate',
    'max-candidate-quarantine-rate' => 'max_candidate_quarantine_rate',
    'max-stale-approval-rate' => 'max_stale_approval_rate',
    'max-events-per-run' => 'max_events_per_run',
    'max-events-per-run-second' => 'max_events_per_run_second',
];
$stringOptions = [
    'workflow-name' => 'workflow_name',
    'workflow-version' => 'workflow_version',
    'rollout-baseline-file' => 'rollout_baseline_file',
    'rollout-series-file' => 'rollout_series_file',
];
$seen = [];
$apply = false;
$modeSeen = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        harnessReadinessUsage();
    }
    if ($argument === '--json') {
        // Kept as an idempotent compatibility flag; JSON is always emitted.
        if (isset($seen['json'])) {
            harnessReadinessUsage(2, '--json may only be supplied once.');
        }
        $seen['json'] = true;
        continue;
    }
    if ($argument === '--apply' || $argument === '--dry-run') {
        if ($modeSeen !== null) {
            harnessReadinessUsage(2, '--apply and --dry-run are mutually exclusive.');
        }
        $modeSeen = $argument;
        $apply = $argument === '--apply';
        continue;
    }
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/D', $argument, $matches) !== 1) {
        harnessReadinessUsage(2, "Unknown option: {$argument}");
    }
    $name = $matches[1];
    $raw = $matches[2];
    if (isset($seen[$name])) {
        harnessReadinessUsage(2, "--{$name} may only be supplied once.");
    }
    $seen[$name] = true;
    if (isset($integerOptions[$name])) {
        [$key, $min, $max] = $integerOptions[$name];
        $values[$key] = harnessReadinessInteger($raw, "--{$name}", $min, $max);
        continue;
    }
    if (isset($numberOptions[$name])) {
        $values[$numberOptions[$name]] = harnessReadinessNumber($raw, "--{$name}");
        continue;
    }
    if (isset($stringOptions[$name])) {
        if ($raw === '') {
            harnessReadinessUsage(2, "--{$name} cannot be empty.");
        }
        $values[$stringOptions[$name]] = $raw;
        continue;
    }
    harnessReadinessUsage(2, "Unknown option: --{$name}");
}

define('DB_SCHEMA_INSPECTION_MODE', true);
define('APP_LOADED', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/harness/observability/bootstrap.php';

$rolloutBaseline = $values['rollout_baseline_file'] === null
    ? null
    : harnessReadinessRolloutSnapshot(
        harnessReadinessJsonFile(
            $values['rollout_baseline_file'],
            '--rollout-baseline-file'
        ),
        '--rollout-baseline-file'
    );
$rolloutSeries = harnessReadinessRolloutSeries($values['rollout_series_file']);

try {
    $thresholds = new HarnessReadinessThresholds(
        minTerminalRuns: $values['min_terminal_runs'],
        maxRunFailureRate: $values['max_run_failure_rate'],
        maxRunRetryRate: $values['max_run_retry_rate'],
        maxJobFailureRate: $values['max_job_failure_rate'],
        maxJobRetryRate: $values['max_job_retry_rate'],
        maxCandidateQuarantineRate: $values['max_candidate_quarantine_rate'],
        maxStaleApprovalRate: $values['max_stale_approval_rate'],
        maxRunP95Ms: $values['max_run_p95_ms'],
        maxJobP95Ms: $values['max_job_p95_ms'],
        maxBacklogSeconds: $values['max_backlog_seconds'],
        maxEventsPerRun: $values['max_events_per_run'],
        maxEventsPerRunSecond: $values['max_events_per_run_second'],
        maxExpiredActiveLeases: $values['max_expired_active_leases'],
        maxExpiredOpenApprovals: $values['max_expired_open_approvals']
    );
    $to = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $from = $to->sub(new DateInterval('PT' . $values['window_minutes'] . 'M'));
    $window = new HarnessMetricsWindow(
        $from,
        $to,
        $values['novel_id'],
        $values['workflow_name'],
        $values['workflow_version']
    );
    $rolloutBoundary = intdiv($to->getTimestamp(), 300) * 300;
    $rolloutTo = (new DateTimeImmutable('@' . $rolloutBoundary))
        ->setTimezone(new DateTimeZone('UTC'));
    $rolloutWindow = new HarnessMetricsWindow(
        $rolloutTo->sub(new DateInterval('PT5M')),
        $rolloutTo,
        $values['novel_id'],
        $values['workflow_name'],
        $values['workflow_version']
    );
} catch (InvalidArgumentException $exception) {
    harnessReadinessUsage(2, $exception->getMessage());
}

try {
    $schema = DB::schemaStatus();
    if (!($schema['ready'] ?? false)) {
        throw new RuntimeException(sprintf(
            'database schema is not ready (current=%d, expected=%d)',
            (int)($schema['current'] ?? 0),
            (int)($schema['expected'] ?? 0)
        ));
    }

    DB::query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    if (!DB::beginTransaction()) {
        throw new RuntimeException('could not start read-only Harness metrics snapshot');
    }
    try {
        $snapshot = (new HarnessMetricsAggregator(DB::connect()))
            ->collect($window, $to);
        $rolloutSnapshot = (new HarnessRolloutMetricsAggregator(DB::connect()))
            ->collect($rolloutWindow, $to);
    } finally {
        if (DB::inTransaction() && !DB::rollBack()) {
            throw new RuntimeException('could not close read-only Harness metrics snapshot');
        }
    }

    $absoluteReport = (new HarnessReadinessEvaluator($thresholds))->evaluate($snapshot);
    $rolloutSeries[] = $rolloutSnapshot;
    $relativeReport = (new HarnessRelativeBaselineEvaluator())->evaluate(
        $rolloutBaseline,
        $rolloutSeries
    );
    $report = $absoluteReport;
    $report['absolute_readiness_passed'] =
        ($absoluteReport['readiness_passed'] ?? false) === true;
    $report['rollout_observability'] = $rolloutSnapshot;
    $report['relative_baseline'] = $relativeReport;
    $report['release_evidence_complete'] =
        ($rolloutSnapshot['release_evidence_complete'] ?? false) === true
        && ($relativeReport['release_evidence_complete'] ?? false) === true;
    $report['readiness_passed'] = $report['absolute_readiness_passed']
        && $report['release_evidence_complete']
        && ($relativeReport['promotion_allowed'] ?? false) === true;
    $report['release_gate'] = [
        'absolute_readiness_passed' => $report['absolute_readiness_passed'],
        'rollout_evidence_complete' =>
            ($rolloutSnapshot['release_evidence_complete'] ?? false) === true,
        'relative_promotion_allowed' =>
            ($relativeReport['promotion_allowed'] ?? false) === true,
        'passed' => $report['readiness_passed'],
    ];

    $stopReport = null;
    $stopSource = 'release_gate';
    if (($absoluteReport['stop_required'] ?? false) === true) {
        // Existing zero-tolerance integrity breaches retain first priority and
        // do not depend on rollout telemetry being available.
        $stopReport = $absoluteReport;
        $stopSource = 'absolute_integrity';
    } elseif (($relativeReport['automatic_stop_required'] ?? false) === true) {
        $stopReport = $relativeReport;
        $stopSource = 'relative_baseline';
    } elseif ($report['readiness_passed'] === true) {
        // Only a fully passing composite gate may re-arm the incident latch.
        $stopReport = $absoluteReport;
        $stopSource = 'composite_release';
    }
    try {
        // This is the only mutation-capable call in the command. The executor
        // independently requires --apply, complete metrics and a verified
        // zero-tolerance breach before delegating to the atomic epoch service.
        $report['automatic_stop'] = $stopReport === null
            ? harnessReadinessNoopAction($apply, 'release_gate_hold')
            : (new HarnessAutomaticStopExecutor())->execute($stopReport, $apply);
        $report['automatic_stop']['source'] = $stopSource;
    } catch (Throwable $stopFailure) {
        $report['automatic_stop'] = [
            'schema' => 'harness.automatic_stop.action.v1',
            'mode' => $apply ? 'apply' : 'dry_run',
            'applied' => false,
            'would_apply' => $stopReport !== null,
            'reason' => 'epoch_bump_failed',
            'verified_breaches' => $stopSource === 'relative_baseline'
                ? ($relativeReport['automatic_stop_breaches'] ?? [])
                : ($absoluteReport['verified_stop_breaches'] ?? []),
            'epoch' => null,
            'source' => $stopSource,
            'error' => $stopFailure->getMessage(),
        ];
        $report['readiness_passed'] = false;
        $encoded = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        fwrite(STDERR, (is_string($encoded) ? $encoded : '{}') . PHP_EOL);
        exit(1);
    }

    $encoded = json_encode(
        $report,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if (!is_string($encoded)) {
        throw new RuntimeException('could not encode Harness readiness report');
    }
    echo $encoded . PHP_EOL;
    exit(($report['readiness_passed'] ?? false) === true ? 0 : 3);
} catch (Throwable $exception) {
    try {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
    } catch (Throwable) {
        // Preserve the original database/runtime failure in the JSON result;
        // a failed initial connection has no transaction to roll back.
    }
    $error = [
        'schema' => 'harness.release_readiness.error.v1',
        'ok' => false,
        'error' => 'database_or_runtime_error',
        'message' => $exception->getMessage(),
        'automatic_stop' => [
            'mode' => $apply ? 'apply' : 'dry_run',
            'applied' => false,
            'reason' => 'no_reliable_report',
        ],
    ];
    $encoded = json_encode(
        $error,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    fwrite(STDERR, (is_string($encoded) ? $encoded : '{}') . PHP_EOL);
    exit(1);
}
