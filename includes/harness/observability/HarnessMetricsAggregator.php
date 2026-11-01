<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessMetricsWindow.php';

/**
 * Read-only aggregate view of the durable Harness control plane.
 *
 * The class deliberately exposes only SELECTs through HarnessPersistenceDriver:
 * transaction ownership belongs to the caller, so the CLI can put all reads in
 * one REPEATABLE READ, READ ONLY snapshot and close it before an epoch bump.
 */
final class HarnessMetricsAggregator
{
    /** @var list<int> */
    private const LATENCY_BUCKETS_MS = [
        30000,
        60000,
        120000,
        300000,
        600000,
        900000,
        1800000,
        3600000,
    ];

    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    /** @return array<string,mixed> */
    public function collect(
        HarnessMetricsWindow $window,
        ?DateTimeImmutable $collectedAt = null
    ): array {
        $datasets = [];
        $errors = [];

        $this->readDataset(
            'runs',
            $this->runSql($window),
            $this->params($window),
            [
                'total_runs', 'terminal_runs', 'outcome_runs', 'successful_runs',
                'failed_runs', 'canceled_runs', 'retried_runs', 'quarantined_runs',
                'waiting_approval_runs', 'input_tokens', 'output_tokens',
                'latency_samples', 'lat_le_30000', 'lat_le_60000',
                'lat_le_120000', 'lat_le_300000', 'lat_le_600000',
                'lat_le_900000', 'lat_le_1800000', 'lat_le_3600000',
                'critical_error_runs',
            ],
            ['latency_avg_ms', 'latency_max_ms'],
            $datasets,
            $errors
        );
        $this->readDataset(
            'events',
            $this->eventSql($window),
            $this->params($window),
            [
                'total_events', 'retry_events', 'quarantine_events',
                'error_events', 'critical_integrity_events',
            ],
            [],
            $datasets,
            $errors
        );
        $this->readDataset(
            'artifacts',
            $this->artifactSql($window),
            $this->params($window),
            ['total_artifacts', 'total_bytes', 'inline_artifacts', 'external_artifacts'],
            [],
            $datasets,
            $errors
        );
        $this->readDataset(
            'jobs',
            $this->jobSql($window),
            $this->jobParams($window),
            [
                'total_jobs', 'terminal_jobs', 'outcome_jobs', 'successful_jobs',
                'failed_jobs', 'dead_letter_jobs', 'canceled_jobs', 'retried_jobs',
                'active_backlog', 'due_backlog', 'expired_active_leases',
                'oldest_backlog_seconds', 'latency_samples', 'lat_le_30000',
                'lat_le_60000', 'lat_le_120000', 'lat_le_300000',
                'lat_le_600000', 'lat_le_900000', 'lat_le_1800000',
                'lat_le_3600000',
            ],
            ['latency_avg_ms', 'latency_max_ms'],
            $datasets,
            $errors
        );
        $this->readDataset(
            'postprocess',
            $this->postprocessSql($window),
            $this->params($window),
            [
                'total_jobs', 'terminal_jobs', 'outcome_jobs', 'successful_jobs',
                'failed_jobs', 'dead_letter_jobs', 'canceled_jobs', 'retried_jobs',
                'active_backlog', 'due_backlog', 'expired_active_leases',
                'oldest_backlog_seconds', 'latency_samples', 'lat_le_30000',
                'lat_le_60000', 'lat_le_120000', 'lat_le_300000',
                'lat_le_600000', 'lat_le_900000', 'lat_le_1800000',
                'lat_le_3600000', 'mapped_shadow_jobs', 'unmapped_shadow_jobs',
            ],
            ['latency_avg_ms', 'latency_max_ms'],
            $datasets,
            $errors
        );
        $this->readDataset(
            'candidates',
            $this->candidateSql($window),
            $this->params($window),
            [
                'total_candidates', 'quarantined_candidates', 'approved_candidates',
                'committed_candidates', 'rejected_candidates', 'stale_candidates',
                'quarantined_canon_references',
            ],
            [],
            $datasets,
            $errors
        );
        $this->readDataset(
            'approvals',
            $this->approvalSql($window),
            $this->approvalParams($window),
            [
                'total_approvals', 'open_approvals', 'approved_approvals',
                'rejected_approvals', 'stale_approvals', 'canceled_approvals',
                'expired_open_approvals', 'decision_samples', 'decision_le_60000',
                'decision_le_300000', 'decision_le_900000', 'decision_le_3600000',
            ],
            ['decision_avg_ms', 'decision_max_ms'],
            $datasets,
            $errors
        );
        $this->readDataset(
            'commits',
            $this->commitSql($window),
            $this->params($window),
            ['total_commits'],
            [],
            $datasets,
            $errors
        );
        $this->readDataset(
            'commit_duplicates',
            $this->duplicateCommitSql($window),
            $this->params($window),
            ['duplicate_commit_groups', 'duplicate_commit_rows'],
            [],
            $datasets,
            $errors
        );
        $this->readDataset(
            'commit_conflicts',
            $this->conflictingCommitSql($window),
            $this->params($window),
            ['commit_conflict_groups'],
            [],
            $datasets,
            $errors
        );
        $this->readDataset(
            'integrity',
            $this->integritySql($window),
            $this->integrityParams($window),
            [
                'canonical_revision_mismatches',
                'canonical_projection_mismatches',
                'quarantined_memory_writes',
                'writing_fence_violations',
                'postprocess_fence_violations',
                'shadow_projection_mismatches',
            ],
            [],
            $datasets,
            $errors
        );

        $reliable = $errors === [] && count($datasets) === 11;
        $now = ($collectedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        return [
            'schema' => 'harness.metrics.snapshot.v1',
            'collected_at' => $now->format('Y-m-d\TH:i:s.u\Z'),
            'window' => $window->toArray(),
            'metrics_reliable' => $reliable,
            'dataset_count' => count($datasets),
            'datasets' => $datasets,
            'errors' => $errors,
            'metrics' => $this->deriveMetrics($datasets, $window),
        ];
    }

    /**
     * @param list<int|string> $params
     * @param list<string> $integerFields
     * @param list<string> $numberFields
     * @param array<string,mixed> $datasets
     * @param list<array<string,string>> $errors
     */
    private function readDataset(
        string $name,
        string $sql,
        array $params,
        array $integerFields,
        array $numberFields,
        array &$datasets,
        array &$errors
    ): void {
        try {
            if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)?SELECT\b/is', $sql) !== 1) {
                throw new LogicException('Observability query is not read-only.');
            }
            $row = $this->db->fetchOne($sql, $params);
            if (!is_array($row)) {
                throw new RuntimeException('Aggregate query returned no row.');
            }
            $metrics = [];
            foreach ($integerFields as $field) {
                $metrics[$field] = self::nonNegativeInteger($row[$field] ?? null, $field);
            }
            foreach ($numberFields as $field) {
                $metrics[$field] = self::nullableNonNegativeNumber(
                    $row[$field] ?? null,
                    $field
                );
            }
            $datasets[$name] = [
                'available' => true,
                'metrics' => $metrics,
            ];
        } catch (Throwable $exception) {
            $datasets[$name] = [
                'available' => false,
                'metrics' => null,
            ];
            $errors[] = [
                'dataset' => $name,
                'error' => $exception::class,
                'message' => self::safeMessage($exception->getMessage()),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function deriveMetrics(array $datasets, HarnessMetricsWindow $window): array
    {
        $runs = $this->datasetMetrics($datasets, 'runs');
        $events = $this->datasetMetrics($datasets, 'events');
        $artifacts = $this->datasetMetrics($datasets, 'artifacts');
        $jobs = $this->datasetMetrics($datasets, 'jobs');
        $postprocess = $this->datasetMetrics($datasets, 'postprocess');
        $candidates = $this->datasetMetrics($datasets, 'candidates');
        $approvals = $this->datasetMetrics($datasets, 'approvals');
        $commits = $this->datasetMetrics($datasets, 'commits');
        $duplicates = $this->datasetMetrics($datasets, 'commit_duplicates');
        $conflicts = $this->datasetMetrics($datasets, 'commit_conflicts');
        $integrity = $this->datasetMetrics($datasets, 'integrity');

        return [
            'runs' => $runs === null ? null : [
                ...$runs,
                'success_rate' => self::ratio($runs['successful_runs'], $runs['outcome_runs']),
                'failure_rate' => self::ratio($runs['failed_runs'], $runs['outcome_runs']),
                'retry_rate' => self::ratio($runs['retried_runs'], $runs['total_runs']),
                'quarantine_rate' => self::ratio(
                    $runs['quarantined_runs'],
                    $runs['total_runs']
                ),
                'latency_p50' => self::quantileFromCumulativeBuckets($runs, 0.50),
                'latency_p95' => self::quantileFromCumulativeBuckets($runs, 0.95),
            ],
            'events' => $events === null ? null : [
                ...$events,
                'events_per_run' => $runs === null
                    ? null
                    : self::ratio($events['total_events'], $runs['total_runs']),
                'events_per_run_second' => $runs === null
                    ? null
                    : self::ratio(
                        $events['total_events'],
                        $runs['total_runs'] * $window->seconds()
                    ),
            ],
            'artifacts' => $artifacts === null ? null : [
                ...$artifacts,
                'avg_bytes' => self::ratio(
                    $artifacts['total_bytes'],
                    $artifacts['total_artifacts']
                ),
            ],
            'jobs' => $jobs === null ? null : [
                ...$jobs,
                'success_rate' => self::ratio($jobs['successful_jobs'], $jobs['outcome_jobs']),
                'failure_rate' => self::ratio(
                    $jobs['failed_jobs'] + $jobs['dead_letter_jobs'],
                    $jobs['outcome_jobs']
                ),
                'retry_rate' => self::ratio($jobs['retried_jobs'], $jobs['total_jobs']),
                'latency_p50' => self::quantileFromCumulativeBuckets($jobs, 0.50),
                'latency_p95' => self::quantileFromCumulativeBuckets($jobs, 0.95),
            ],
            // postprocess_jobs remains the business authority during the M64
            // shadow bridge. Release decisions must never depend on the
            // eventually-consistent jobs mirror being present or current.
            'postprocess' => $postprocess === null ? null : [
                ...$postprocess,
                'success_rate' => self::ratio(
                    $postprocess['successful_jobs'],
                    $postprocess['outcome_jobs']
                ),
                'failure_rate' => self::ratio(
                    $postprocess['failed_jobs'],
                    $postprocess['outcome_jobs']
                ),
                'retry_rate' => self::ratio(
                    $postprocess['retried_jobs'],
                    $postprocess['total_jobs']
                ),
                'latency_p50' => self::quantileFromCumulativeBuckets(
                    $postprocess,
                    0.50
                ),
                'latency_p95' => self::quantileFromCumulativeBuckets(
                    $postprocess,
                    0.95
                ),
            ],
            'candidates' => $candidates === null ? null : [
                ...$candidates,
                'quarantine_rate' => self::ratio(
                    $candidates['quarantined_candidates'],
                    $candidates['total_candidates']
                ),
            ],
            'approvals' => $approvals === null ? null : [
                ...$approvals,
                'stale_rate' => self::ratio(
                    $approvals['stale_approvals'],
                    $approvals['total_approvals']
                ),
                'decision_p95' => self::approvalP95($approvals),
            ],
            'commits' => $commits === null || $duplicates === null || $conflicts === null
                ? null
                : [
                    ...$commits,
                    ...$duplicates,
                    ...$conflicts,
                ],
            'integrity' => $integrity,
        ];
    }

    /** @return array<string,int|float|null>|null */
    private function datasetMetrics(array $datasets, string $name): ?array
    {
        $dataset = $datasets[$name] ?? null;
        if (!is_array($dataset)
            || ($dataset['available'] ?? false) !== true
            || !is_array($dataset['metrics'] ?? null)) {
            return null;
        }
        return $dataset['metrics'];
    }

    /** @return array{sample_count:int,upper_ms:?int,over_largest_bucket:bool} */
    private static function quantileFromCumulativeBuckets(array $metrics, float $quantile): array
    {
        $samples = (int)$metrics['latency_samples'];
        if ($samples === 0) {
            return ['sample_count' => 0, 'upper_ms' => null, 'over_largest_bucket' => false];
        }
        $target = (int)ceil($samples * $quantile);
        foreach (self::LATENCY_BUCKETS_MS as $upper) {
            if ((int)$metrics['lat_le_' . $upper] >= $target) {
                return [
                    'sample_count' => $samples,
                    'upper_ms' => $upper,
                    'over_largest_bucket' => false,
                ];
            }
        }
        return ['sample_count' => $samples, 'upper_ms' => null, 'over_largest_bucket' => true];
    }

    /** @return array{sample_count:int,upper_ms:?int,over_largest_bucket:bool} */
    private static function approvalP95(array $metrics): array
    {
        $samples = (int)$metrics['decision_samples'];
        if ($samples === 0) {
            return ['sample_count' => 0, 'upper_ms' => null, 'over_largest_bucket' => false];
        }
        $target = (int)ceil($samples * 0.95);
        foreach ([60000, 300000, 900000, 3600000] as $upper) {
            if ((int)$metrics['decision_le_' . $upper] >= $target) {
                return [
                    'sample_count' => $samples,
                    'upper_ms' => $upper,
                    'over_largest_bucket' => false,
                ];
            }
        }
        return ['sample_count' => $samples, 'upper_ms' => null, 'over_largest_bucket' => true];
    }

    private static function ratio(int|float $numerator, int|float $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }
        return $numerator / $denominator;
    }

    private static function nonNegativeInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
            ]);
        } else {
            $parsed = false;
        }
        if (!is_int($parsed) || $parsed < 0) {
            throw new RuntimeException("{$field} is not a non-negative aggregate integer.");
        }
        return $parsed;
    }

    private static function nullableNonNegativeNumber(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new RuntimeException("{$field} is not a numeric aggregate.");
        }
        if (!is_numeric($value)) {
            throw new RuntimeException("{$field} is not a numeric aggregate.");
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            throw new RuntimeException("{$field} is outside the supported aggregate range.");
        }
        return $number;
    }

    private static function safeMessage(string $message): string
    {
        $message = preg_replace('/\s+/u', ' ', trim($message));
        if (!is_string($message) || $message === '') {
            return 'metrics dataset is unavailable';
        }
        return strlen($message) <= 240 ? $message : substr($message, 0, 237) . '...';
    }

    /** @return list<int|string> */
    private function params(HarnessMetricsWindow $window): array
    {
        $params = [$window->fromSql(), $window->toSql()];
        if ($window->novelId !== null) {
            $params[] = $window->novelId;
        }
        if ($window->workflowName !== null) {
            $params[] = $window->workflowName;
        }
        if ($window->workflowVersion !== null) {
            $params[] = $window->workflowVersion;
        }
        return $params;
    }

    /** @return list<int|string> */
    private function jobParams(HarnessMetricsWindow $window): array
    {
        return [
            $window->toSql(),
            $window->toSql(),
            $window->toSql(),
            ...$this->params($window),
        ];
    }

    /** @return list<int|string> */
    private function approvalParams(HarnessMetricsWindow $window): array
    {
        return [$window->toSql(), ...$this->params($window)];
    }

    /** @return list<int|string> */
    private function integrityParams(HarnessMetricsWindow $window): array
    {
        $params = [];
        for ($index = 0; $index < 6; ++$index) {
            array_push($params, ...$this->params($window));
        }
        return $params;
    }

    private function predicates(
        HarnessMetricsWindow $window,
        string $timestampExpression,
        string $novelExpression,
        string $runAlias = 'r'
    ): string {
        $parts = [
            "{$timestampExpression} >= ?",
            "{$timestampExpression} < ?",
        ];
        if ($window->novelId !== null) {
            $parts[] = "{$novelExpression} = ?";
        }
        if ($window->workflowName !== null) {
            $parts[] = "{$runAlias}.workflow_name = ?";
        }
        if ($window->workflowVersion !== null) {
            $parts[] = "{$runAlias}.workflow_version = ?";
        }
        return implode(' AND ', $parts);
    }

    private function runSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'r.created_at', 'r.novel_id');
        return "/* harness_observability:runs */
SELECT COUNT(*) AS total_runs,
       COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit','failed','canceled')),0) AS terminal_runs,
       COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit','failed')),0) AS outcome_runs,
       COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit')),0) AS successful_runs,
       COALESCE(SUM(r.state='failed'),0) AS failed_runs,
       COALESCE(SUM(r.state='canceled'),0) AS canceled_runs,
       COALESCE(SUM(r.attempt>1 OR r.state='retry_wait'),0) AS retried_runs,
       COALESCE(SUM(r.state='quarantined'),0) AS quarantined_runs,
       COALESCE(SUM(r.state='waiting_approval'),0) AS waiting_approval_runs,
       COALESCE(SUM(r.input_tokens),0) AS input_tokens,
       COALESCE(SUM(r.output_tokens),0) AS output_tokens,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at IS NOT NULL AND r.completed_at>=r.started_at),0) AS latency_samples,
       AVG(CASE WHEN r.started_at IS NOT NULL AND r.completed_at>=r.started_at THEN TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000 END) AS latency_avg_ms,
       MAX(CASE WHEN r.started_at IS NOT NULL AND r.completed_at>=r.started_at THEN TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000 END) AS latency_max_ms,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=30000),0) AS lat_le_30000,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=60000),0) AS lat_le_60000,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=120000),0) AS lat_le_120000,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=300000),0) AS lat_le_300000,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=600000),0) AS lat_le_600000,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=900000),0) AS lat_le_900000,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=1800000),0) AS lat_le_1800000,
       COALESCE(SUM(r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=3600000),0) AS lat_le_3600000,
       COALESCE(SUM(r.error_code IN (
           'canonical_commit_conflict','commit_idempotency_conflict',
           'canonical_revision_mismatch','projection_revision_mismatch',
           'worker_fence_bypass','quarantined_memory_write','shadow_event_mismatch'
       )),0) AS critical_error_runs
FROM generation_runs r
WHERE {$where}";
    }

    private function eventSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'e.created_at', 'r.novel_id');
        return "/* harness_observability:events */
SELECT COUNT(*) AS total_events,
       COALESCE(SUM(e.event_type='run.retry_scheduled'),0) AS retry_events,
       COALESCE(SUM(e.event_type='canonical.quarantined'),0) AS quarantine_events,
       COALESCE(SUM(e.severity IN ('error','critical')),0) AS error_events,
       COALESCE(SUM(e.event_type IN (
           'canonical.commit_conflict','canonical.revision_mismatch',
           'canonical.projection_mismatch','canonical.fence_violation',
           'memory.quarantine_violation','worker.fence_bypass',
           'shadow.content_hash_mismatch','shadow.event_mismatch'
       )),0) AS critical_integrity_events
FROM generation_events e
INNER JOIN generation_runs r ON r.id=e.run_id
WHERE {$where}";
    }

    private function artifactSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'a.created_at', 'r.novel_id');
        return "/* harness_observability:artifacts */
SELECT COUNT(*) AS total_artifacts,
       COALESCE(SUM(a.size_bytes),0) AS total_bytes,
       COALESCE(SUM(a.storage_kind='inline'),0) AS inline_artifacts,
       COALESCE(SUM(a.storage_kind<>'inline'),0) AS external_artifacts
FROM generation_artifacts a
INNER JOIN generation_runs r ON r.id=a.run_id
WHERE {$where}";
    }

    private function jobSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'j.created_at', 'j.novel_id');
        return "/* harness_observability:jobs */
SELECT COUNT(*) AS total_jobs,
       COALESCE(SUM(j.state IN ('succeeded','failed','dead_letter','canceled')),0) AS terminal_jobs,
       COALESCE(SUM(j.state IN ('succeeded','failed','dead_letter')),0) AS outcome_jobs,
       COALESCE(SUM(j.state='succeeded'),0) AS successful_jobs,
       COALESCE(SUM(j.state='failed'),0) AS failed_jobs,
       COALESCE(SUM(j.state='dead_letter'),0) AS dead_letter_jobs,
       COALESCE(SUM(j.state='canceled'),0) AS canceled_jobs,
       COALESCE(SUM(j.attempt>1 OR j.state='retry_wait'),0) AS retried_jobs,
       COALESCE(SUM(j.state IN ('queued','running','retry_wait')),0) AS active_backlog,
       COALESCE(SUM(j.state IN ('queued','retry_wait') AND j.available_at<?),0) AS due_backlog,
       COALESCE(SUM(j.state='running' AND j.lease_expires_at<?),0) AS expired_active_leases,
       COALESCE(TIMESTAMPDIFF(SECOND,
           MIN(CASE WHEN j.state IN ('queued','running','retry_wait') THEN j.created_at END), ?),0) AS oldest_backlog_seconds,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at IS NOT NULL AND j.completed_at>=j.started_at),0) AS latency_samples,
       AVG(CASE WHEN j.started_at IS NOT NULL AND j.completed_at>=j.started_at THEN TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000 END) AS latency_avg_ms,
       MAX(CASE WHEN j.started_at IS NOT NULL AND j.completed_at>=j.started_at THEN TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000 END) AS latency_max_ms,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=30000),0) AS lat_le_30000,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=60000),0) AS lat_le_60000,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=120000),0) AS lat_le_120000,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=300000),0) AS lat_le_300000,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=600000),0) AS lat_le_600000,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=900000),0) AS lat_le_900000,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=1800000),0) AS lat_le_1800000,
       COALESCE(SUM(j.started_at IS NOT NULL AND j.completed_at>=j.started_at AND TIMESTAMPDIFF(MICROSECOND,j.started_at,j.completed_at)/1000<=3600000),0) AS lat_le_3600000
FROM jobs j
LEFT JOIN generation_runs r ON r.id=j.run_id
WHERE {$where}";
    }

    private function postprocessSql(HarnessMetricsWindow $window): string
    {
        $scope = [];
        if ($window->novelId !== null) {
            $scope[] = 'pj.novel_id = ?';
        }
        if ($window->workflowName !== null) {
            $scope[] = "EXISTS (
                SELECT 1 FROM chapter_commit_ledger pps_l
                INNER JOIN generation_runs pps_r ON pps_r.id=pps_l.run_id
                WHERE pps_l.postprocess_job_id=pj.id
                  AND pps_r.workflow_name = ?
            )";
        }
        if ($window->workflowVersion !== null) {
            $scope[] = "EXISTS (
                SELECT 1 FROM chapter_commit_ledger pps_l
                INNER JOIN generation_runs pps_r ON pps_r.id=pps_l.run_id
                WHERE pps_l.postprocess_job_id=pj.id
                  AND pps_r.workflow_version = ?
            )";
        }
        $scopeSql = $scope === [] ? '' : ' AND ' . implode(' AND ', $scope);

        return "/* harness_observability:postprocess */
SELECT COALESCE(SUM(pj.created_at>=bounds.window_from),0) AS total_jobs,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.state IN ('done','failed')),0) AS terminal_jobs,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.state IN ('done','failed')),0) AS outcome_jobs,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.state='done'),0) AS successful_jobs,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.state='failed'),0) AS failed_jobs,
       0 AS dead_letter_jobs,
       0 AS canceled_jobs,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.attempt>1),0) AS retried_jobs,
       COALESCE(SUM(pj.state IN ('queued','retry','running')),0) AS active_backlog,
       COALESCE(SUM(pj.state IN ('queued','retry') AND pj.available_at<bounds.window_to),0) AS due_backlog,
       COALESCE(SUM(pj.state='running' AND pj.lease_expires_at<bounds.window_to),0) AS expired_active_leases,
       COALESCE(TIMESTAMPDIFF(SECOND,
           MIN(CASE WHEN pj.state IN ('queued','retry','running') THEN pj.created_at END),
           MAX(bounds.window_to)),0) AS oldest_backlog_seconds,
       COALESCE(SUM(pj.created_at>=bounds.window_from
                    AND pj.started_at IS NOT NULL AND pj.completed_at IS NOT NULL
                    AND pj.completed_at>=pj.started_at),0) AS latency_samples,
       AVG(CASE WHEN pj.created_at>=bounds.window_from
                     AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at
                THEN TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000 END) AS latency_avg_ms,
       MAX(CASE WHEN pj.created_at>=bounds.window_from
                     AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at
                THEN TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000 END) AS latency_max_ms,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=30000),0) AS lat_le_30000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=60000),0) AS lat_le_60000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=120000),0) AS lat_le_120000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=300000),0) AS lat_le_300000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=600000),0) AS lat_le_600000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=900000),0) AS lat_le_900000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=1800000),0) AS lat_le_1800000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND pj.started_at IS NOT NULL AND pj.completed_at>=pj.started_at AND TIMESTAMPDIFF(MICROSECOND,pj.started_at,pj.completed_at)/1000<=3600000),0) AS lat_le_3600000,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND EXISTS (
           SELECT 1 FROM jobs pps_j
           WHERE pps_j.legacy_type='postprocess_job' AND pps_j.legacy_id=pj.id
       )),0) AS mapped_shadow_jobs,
       COALESCE(SUM(pj.created_at>=bounds.window_from AND NOT EXISTS (
           SELECT 1 FROM jobs pps_j
           WHERE pps_j.legacy_type='postprocess_job' AND pps_j.legacy_id=pj.id
       )),0) AS unmapped_shadow_jobs
FROM postprocess_jobs pj
CROSS JOIN (
    SELECT CAST(? AS DATETIME) AS window_from,
           CAST(? AS DATETIME) AS window_to
) bounds
WHERE pj.created_at<bounds.window_to{$scopeSql}";
    }

    private function candidateSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'c.created_at', 'c.novel_id');
        return "/* harness_observability:candidates */
SELECT COUNT(*) AS total_candidates,
       COALESCE(SUM(c.lifecycle='quarantined' OR c.gate_state='quarantined'),0) AS quarantined_candidates,
       COALESCE(SUM(c.lifecycle='approved'),0) AS approved_candidates,
       COALESCE(SUM(c.lifecycle='committed'),0) AS committed_candidates,
       COALESCE(SUM(c.lifecycle='rejected'),0) AS rejected_candidates,
       COALESCE(SUM(c.lifecycle='stale'),0) AS stale_candidates,
       COALESCE(SUM(
           (c.lifecycle='quarantined' OR c.gate_state='quarantined')
           AND ch.canonical_state='canonical'
           AND BINARY ch.canonical_candidate_uid=BINARY c.candidate_uid
       ),0) AS quarantined_canon_references
FROM chapter_candidates c
LEFT JOIN generation_runs r ON r.id=c.run_id
LEFT JOIN chapters ch ON ch.id=c.chapter_id
WHERE {$where}
  AND c.source<>'branch_edit'";
    }

    private function approvalSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'a.created_at', 'a.novel_id');
        return "/* harness_observability:approvals */
SELECT COUNT(*) AS total_approvals,
       COALESCE(SUM(a.state='open'),0) AS open_approvals,
       COALESCE(SUM(a.state='approved'),0) AS approved_approvals,
       COALESCE(SUM(a.state='rejected'),0) AS rejected_approvals,
       COALESCE(SUM(a.state='stale'),0) AS stale_approvals,
       COALESCE(SUM(a.state='canceled'),0) AS canceled_approvals,
       COALESCE(SUM(a.state='open' AND a.expires_at IS NOT NULL AND a.expires_at<?),0) AS expired_open_approvals,
       COALESCE(SUM(a.decided_at IS NOT NULL AND a.decided_at>=a.created_at),0) AS decision_samples,
       AVG(CASE WHEN a.decided_at>=a.created_at THEN TIMESTAMPDIFF(MICROSECOND,a.created_at,a.decided_at)/1000 END) AS decision_avg_ms,
       MAX(CASE WHEN a.decided_at>=a.created_at THEN TIMESTAMPDIFF(MICROSECOND,a.created_at,a.decided_at)/1000 END) AS decision_max_ms,
       COALESCE(SUM(a.decided_at>=a.created_at AND TIMESTAMPDIFF(MICROSECOND,a.created_at,a.decided_at)/1000<=60000),0) AS decision_le_60000,
       COALESCE(SUM(a.decided_at>=a.created_at AND TIMESTAMPDIFF(MICROSECOND,a.created_at,a.decided_at)/1000<=300000),0) AS decision_le_300000,
       COALESCE(SUM(a.decided_at>=a.created_at AND TIMESTAMPDIFF(MICROSECOND,a.created_at,a.decided_at)/1000<=900000),0) AS decision_le_900000,
       COALESCE(SUM(a.decided_at>=a.created_at AND TIMESTAMPDIFF(MICROSECOND,a.created_at,a.decided_at)/1000<=3600000),0) AS decision_le_3600000
FROM approval_requests a
LEFT JOIN generation_runs r ON r.id=a.run_id
WHERE {$where}";
    }

    private function commitSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'l.committed_at', 'r.novel_id');
        return "/* harness_observability:commits */
SELECT COUNT(*) AS total_commits
FROM chapter_commit_ledger l
INNER JOIN generation_runs r ON r.id=l.run_id
WHERE {$where}";
    }

    private function duplicateCommitSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'l.committed_at', 'r.novel_id');
        return "/* harness_observability:commit_duplicates */
SELECT COUNT(*) AS duplicate_commit_groups,
       COALESCE(SUM(d.row_count-1),0) AS duplicate_commit_rows
FROM (
    SELECT l.chapter_id, l.previous_revision_hash, l.committed_revision_hash,
           COUNT(*) AS row_count
    FROM chapter_commit_ledger l
    INNER JOIN generation_runs r ON r.id=l.run_id
    WHERE {$where}
    GROUP BY l.chapter_id, l.previous_revision_hash, l.committed_revision_hash
    HAVING COUNT(*)>1
) d";
    }

    private function conflictingCommitSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'l.committed_at', 'r.novel_id');
        return "/* harness_observability:commit_conflicts */
SELECT COUNT(*) AS commit_conflict_groups
FROM (
    SELECT l.chapter_id, l.previous_revision_hash
    FROM chapter_commit_ledger l
    INNER JOIN generation_runs r ON r.id=l.run_id
    WHERE {$where}
    GROUP BY l.chapter_id, l.previous_revision_hash
    HAVING COUNT(DISTINCT l.committed_revision_hash)>1
) c";
    }

    /**
     * Direct integrity probes are deliberately independent from semantic
     * Event names. A broken writer cannot make the stop signal disappear by
     * failing to emit the Event that would have described its own corruption.
     */
    private function integritySql(HarnessMetricsWindow $window): string
    {
        $chapterRevisionWhere = $this->predicates(
            $window,
            'ch.updated_at',
            'ch.novel_id'
        );
        $projectionWhere = $this->predicates(
            $window,
            'ch.updated_at',
            'ch.novel_id'
        );
        $memoryWhere = $this->predicates(
            $window,
            'pj.completed_at',
            'pj.novel_id'
        );
        $writingFenceWhere = $this->predicates(
            $window,
            'wt.updated_at',
            'wt.novel_id'
        );
        $postprocessFenceWhere = $this->predicates(
            $window,
            'pj.updated_at',
            'pj.novel_id'
        );
        $shadowWhere = $this->predicates(
            $window,
            'wt.updated_at',
            'wt.novel_id'
        );

        return "/* harness_observability:integrity */
SELECT
  (SELECT COUNT(*)
     FROM chapters ch
     LEFT JOIN chapter_commit_ledger ledger
       ON ledger.id=(
           SELECT ledger_latest.id FROM chapter_commit_ledger ledger_latest
           WHERE ledger_latest.chapter_id=ch.id
           ORDER BY ledger_latest.committed_at DESC, ledger_latest.id DESC LIMIT 1
       )
     LEFT JOIN generation_runs r ON r.id=ledger.run_id
    WHERE {$chapterRevisionWhere}
      AND ch.canonical_state IN ('legacy','canonical')
      AND (ch.content IS NULL OR ch.content_revision_hash IS NULL
           OR BINARY ch.content_revision_hash<>BINARY SHA2(ch.content,256))
  ) AS canonical_revision_mismatches,
  (SELECT COUNT(*)
     FROM chapters ch
     LEFT JOIN chapter_commit_ledger ledger
       ON ledger.id=(
           SELECT ledger_latest.id FROM chapter_commit_ledger ledger_latest
           WHERE ledger_latest.chapter_id=ch.id
           ORDER BY ledger_latest.committed_at DESC, ledger_latest.id DESC LIMIT 1
       )
     LEFT JOIN generation_runs r ON r.id=ledger.run_id
     LEFT JOIN chapter_candidates cc ON cc.candidate_uid=ch.canonical_candidate_uid
    WHERE {$projectionWhere}
      AND ch.canonical_state='canonical'
      AND (ledger.id IS NULL OR ledger.candidate_id IS NULL OR cc.id IS NULL
           OR ledger.candidate_id<>cc.id
           OR BINARY ledger.committed_revision_hash<>BINARY ch.content_revision_hash
           OR BINARY cc.content_hash<>BINARY ch.content_revision_hash
           OR BINARY cc.content_hash<>BINARY SHA2(cc.inline_content,256)
           OR cc.lifecycle<>'committed' OR cc.gate_state<>'passed')
  ) AS canonical_projection_mismatches,
  (SELECT COUNT(*)
     FROM postprocess_jobs pj
     INNER JOIN chapter_candidates cc
       ON cc.candidate_uid=pj.accepted_candidate_uid
     LEFT JOIN chapter_commit_ledger ledger
       ON ledger.id=(
           SELECT ledger_job.id FROM chapter_commit_ledger ledger_job
           WHERE ledger_job.postprocess_job_id=pj.id
           ORDER BY ledger_job.id LIMIT 1
       )
     LEFT JOIN generation_runs r ON r.id=ledger.run_id
    WHERE {$memoryWhere}
      AND pj.state='done'
      AND (cc.lifecycle='quarantined' OR cc.gate_state='quarantined')
  ) AS quarantined_memory_writes,
  (SELECT COUNT(*)
     FROM writing_tasks wt
     LEFT JOIN generation_runs r
       ON r.user_id=wt.user_id
      AND r.idempotency_key=CONCAT('legacy:writing_task:',wt.task_id)
    WHERE {$writingFenceWhere}
      AND JSON_UNQUOTE(JSON_EXTRACT(wt.policy_snapshot_json,'$.fencing_enforced'))='true'
      AND wt.state IN ('preparing','generating','validating','content_saved','postprocessing')
      AND (wt.lease_owner IS NULL OR wt.lease_owner=''
           OR wt.lease_token IS NULL OR wt.lease_token NOT REGEXP '^[a-f0-9]{32}$'
           OR wt.fence_generation<1 OR wt.lease_expires_at IS NULL)
  ) AS writing_fence_violations,
  (SELECT COUNT(*)
     FROM postprocess_jobs pj
     LEFT JOIN chapter_commit_ledger ledger
       ON ledger.id=(
           SELECT ledger_job.id FROM chapter_commit_ledger ledger_job
           WHERE ledger_job.postprocess_job_id=pj.id
           ORDER BY ledger_job.id LIMIT 1
       )
     LEFT JOIN generation_runs r ON r.id=ledger.run_id
    WHERE {$postprocessFenceWhere}
      AND JSON_UNQUOTE(JSON_EXTRACT(pj.policy_snapshot_json,'$.fencing_enforced'))='true'
      AND pj.state='running'
      AND (pj.lease_owner IS NULL OR pj.lease_owner=''
           OR pj.lease_token IS NULL OR pj.lease_token NOT REGEXP '^[a-f0-9]{32}$'
           OR pj.fence_generation<1 OR pj.lease_expires_at IS NULL)
  ) AS postprocess_fence_violations,
  (SELECT COUNT(*)
     FROM writing_tasks wt
     LEFT JOIN generation_runs r
       ON r.user_id=wt.user_id
      AND r.idempotency_key=CONCAT('legacy:writing_task:',wt.task_id)
     LEFT JOIN chapters ch ON ch.id=wt.chapter_id
     LEFT JOIN generation_artifacts ga
       ON ga.id=(
           SELECT ga_latest.id FROM generation_artifacts ga_latest
           WHERE ga_latest.run_id=r.id AND ga_latest.logical_key='legacy.draft.full'
           ORDER BY ga_latest.revision_no DESC, ga_latest.id DESC LIMIT 1
       )
    WHERE {$shadowWhere}
      AND JSON_UNQUOTE(JSON_EXTRACT(wt.policy_snapshot_json,'$.harness_mode'))
          IN ('shadow','canary','on')
      AND (r.id IS NULL OR r.novel_id<>wt.novel_id
           OR NOT (r.chapter_id<=>wt.chapter_id)
           OR r.authority_mode<>'shadow'
           OR r.progress<>wt.progress
           OR r.state<>CASE wt.state
               WHEN 'queued' THEN 'queued'
               WHEN 'preparing' THEN 'running'
               WHEN 'generating' THEN 'running'
               WHEN 'validating' THEN 'running'
               WHEN 'content_saved' THEN 'running'
               WHEN 'postprocessing' THEN 'running'
               WHEN 'done' THEN 'succeeded'
               WHEN 'failed' THEN 'failed'
               WHEN 'canceled' THEN 'canceled'
               ELSE '__invalid__' END
           OR NOT EXISTS (SELECT 1 FROM generation_events ge WHERE ge.run_id=r.id)
           OR (wt.content_saved=1 AND (
               wt.chapter_revision_hash IS NULL
               OR BINARY wt.chapter_revision_hash<>BINARY ch.content_revision_hash
               OR BINARY wt.chapter_revision_hash<>BINARY ga.content_hash
           )))
  ) AS shadow_projection_mismatches";
    }
}
