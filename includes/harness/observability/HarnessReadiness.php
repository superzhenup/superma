<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Absolute local release thresholds.
 *
 * They are intentionally explicit and serialised into every report. Relative
 * regression policies still belong in the rollout system where a trustworthy
 * baseline and a sustained interval are available.
 */
final readonly class HarnessReadinessThresholds
{
    public function __construct(
        public int $minTerminalRuns = 20,
        public float $maxRunFailureRate = 0.05,
        public float $maxRunRetryRate = 0.10,
        public float $maxJobFailureRate = 0.05,
        public float $maxJobRetryRate = 0.10,
        public float $maxCandidateQuarantineRate = 0.10,
        public float $maxStaleApprovalRate = 0.05,
        public int $maxRunP95Ms = 900000,
        public int $maxJobP95Ms = 300000,
        public int $maxBacklogSeconds = 1800,
        public float $maxEventsPerRun = 120.0,
        public float $maxEventsPerRunSecond = 2.0,
        public int $maxExpiredActiveLeases = 0,
        public int $maxExpiredOpenApprovals = 0
    ) {
        if ($minTerminalRuns < 1 || $minTerminalRuns > 1000000) {
            throw new InvalidArgumentException('min_terminal_runs must be between 1 and 1000000.');
        }
        foreach ([
            'max_run_failure_rate' => $maxRunFailureRate,
            'max_run_retry_rate' => $maxRunRetryRate,
            'max_job_failure_rate' => $maxJobFailureRate,
            'max_job_retry_rate' => $maxJobRetryRate,
            'max_candidate_quarantine_rate' => $maxCandidateQuarantineRate,
            'max_stale_approval_rate' => $maxStaleApprovalRate,
        ] as $name => $value) {
            if (!is_finite($value) || $value < 0 || $value > 1) {
                throw new InvalidArgumentException("{$name} must be between 0 and 1.");
            }
        }
        foreach ([
            'max_run_p95_ms' => $maxRunP95Ms,
            'max_job_p95_ms' => $maxJobP95Ms,
            'max_backlog_seconds' => $maxBacklogSeconds,
            'max_expired_active_leases' => $maxExpiredActiveLeases,
            'max_expired_open_approvals' => $maxExpiredOpenApprovals,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("{$name} must be non-negative.");
            }
        }
        if (!is_finite($maxEventsPerRun) || $maxEventsPerRun < 0) {
            throw new InvalidArgumentException('max_events_per_run must be non-negative.');
        }
        if (!is_finite($maxEventsPerRunSecond) || $maxEventsPerRunSecond < 0) {
            throw new InvalidArgumentException('max_events_per_run_second must be non-negative.');
        }
    }

    /** @return array<string,int|float> */
    public function toArray(): array
    {
        return [
            'min_terminal_runs' => $this->minTerminalRuns,
            'max_run_failure_rate' => $this->maxRunFailureRate,
            'max_run_retry_rate' => $this->maxRunRetryRate,
            'max_job_failure_rate' => $this->maxJobFailureRate,
            'max_job_retry_rate' => $this->maxJobRetryRate,
            'max_candidate_quarantine_rate' => $this->maxCandidateQuarantineRate,
            'max_stale_approval_rate' => $this->maxStaleApprovalRate,
            'max_run_p95_ms' => $this->maxRunP95Ms,
            'max_job_p95_ms' => $this->maxJobP95Ms,
            'max_backlog_seconds' => $this->maxBacklogSeconds,
            'max_events_per_run' => $this->maxEventsPerRun,
            'max_events_per_run_second' => $this->maxEventsPerRunSecond,
            'max_expired_active_leases' => $this->maxExpiredActiveLeases,
            'max_expired_open_approvals' => $this->maxExpiredOpenApprovals,
            'max_duplicate_commit_rows' => 0,
            'max_commit_conflict_groups' => 0,
            'max_quarantined_canon_references' => 0,
            'max_critical_integrity_signals' => 0,
        ];
    }
}

final class HarnessReadinessEvaluator
{
    public function __construct(
        private readonly HarnessReadinessThresholds $thresholds = new HarnessReadinessThresholds()
    ) {
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function evaluate(array $snapshot): array
    {
        if (($snapshot['schema'] ?? null) !== 'harness.metrics.snapshot.v1') {
            throw new InvalidArgumentException('Unsupported Harness metrics snapshot schema.');
        }

        $metrics = is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [];
        $runs = $this->group($metrics, 'runs');
        $events = $this->group($metrics, 'events');
        $postprocess = $this->group($metrics, 'postprocess');
        $candidates = $this->group($metrics, 'candidates');
        $approvals = $this->group($metrics, 'approvals');
        $commits = $this->group($metrics, 'commits');
        $integrity = $this->group($metrics, 'integrity');
        $checks = [];

        $this->check(
            $checks,
            'metrics_reliable',
            ($snapshot['metrics_reliable'] ?? false) === true,
            '==',
            true,
            'boolean',
            false,
            'all'
        );
        $this->check($checks, 'terminal_run_sample', $runs['terminal_runs'] ?? null, '>=',
            $this->thresholds->minTerminalRuns, 'runs', false, 'runs');
        $this->check($checks, 'run_failure_rate', $runs['failure_rate'] ?? null, '<=',
            $this->thresholds->maxRunFailureRate, 'ratio', false, 'runs');
        $this->check($checks, 'run_retry_rate', $runs['retry_rate'] ?? null, '<=',
            $this->thresholds->maxRunRetryRate, 'ratio', false, 'runs');
        $this->check($checks, 'run_p95_latency', self::quantileObserved($runs['latency_p95'] ?? null), '<=',
            $this->thresholds->maxRunP95Ms, 'ms_bucket_upper', false, 'runs');
        $this->check($checks, 'events_per_run', self::zeroIfEmpty($events['events_per_run'] ?? null, $runs['total_runs'] ?? null), '<=',
            $this->thresholds->maxEventsPerRun, 'events_per_run', false, 'events');
        $this->check($checks, 'events_per_run_second', self::zeroIfEmpty(
            $events['events_per_run_second'] ?? null,
            $runs['total_runs'] ?? null
        ), '<=', $this->thresholds->maxEventsPerRunSecond, 'events_per_run_second', false, 'events');

        // The M64 jobs table is still a shadow mirror. Until an explicit
        // authority cutover, every release/SLO decision must use the legacy
        // postprocess_jobs queue and separately require complete shadow
        // coverage rather than treating the mirror as source of truth.
        $jobTotal = $postprocess['total_jobs'] ?? null;
        $this->check($checks, 'job_failure_rate', self::zeroIfEmpty(
            $postprocess['failure_rate'] ?? null,
            $jobTotal
        ), '<=', $this->thresholds->maxJobFailureRate, 'ratio', false, 'postprocess');
        $this->check($checks, 'job_retry_rate', self::zeroIfEmpty(
            $postprocess['retry_rate'] ?? null,
            $jobTotal
        ), '<=', $this->thresholds->maxJobRetryRate, 'ratio', false, 'postprocess');
        $this->check($checks, 'job_p95_latency', self::quantileOrZeroWhenEmpty(
            $postprocess['latency_p95'] ?? null,
            $jobTotal
        ), '<=', $this->thresholds->maxJobP95Ms, 'ms_bucket_upper', false, 'postprocess');
        $this->check($checks, 'job_backlog_age',
            $postprocess['oldest_backlog_seconds'] ?? null, '<=',
            $this->thresholds->maxBacklogSeconds, 'seconds', false, 'postprocess');
        $this->check($checks, 'expired_active_leases',
            $postprocess['expired_active_leases'] ?? null, '<=',
            $this->thresholds->maxExpiredActiveLeases, 'jobs', false, 'postprocess');
        $this->check($checks, 'postprocess_shadow_unmapped',
            $postprocess['unmapped_shadow_jobs'] ?? null, '<=',
            0, 'jobs', false, 'postprocess');

        $this->check($checks, 'candidate_quarantine_rate', self::zeroIfEmpty(
            $candidates['quarantine_rate'] ?? null,
            $candidates['total_candidates'] ?? null
        ), '<=', $this->thresholds->maxCandidateQuarantineRate, 'ratio', false, 'candidates');
        $this->check($checks, 'stale_approval_rate', self::zeroIfEmpty(
            $approvals['stale_rate'] ?? null,
            $approvals['total_approvals'] ?? null
        ), '<=', $this->thresholds->maxStaleApprovalRate, 'ratio', false, 'approvals');
        $this->check($checks, 'expired_open_approvals', $approvals['expired_open_approvals'] ?? null, '<=',
            $this->thresholds->maxExpiredOpenApprovals, 'approvals', false, 'approvals');

        // Only these exact, zero-tolerance integrity checks may authorize an
        // automatic epoch bump. Capacity/SLO regressions remain release gates.
        $this->check($checks, 'duplicate_canonical_commits', $commits['duplicate_commit_rows'] ?? null, '<=',
            0, 'rows', true, 'commit_duplicates');
        $this->check($checks, 'canonical_commit_conflicts', $commits['commit_conflict_groups'] ?? null, '<=',
            0, 'groups', true, 'commit_conflicts');
        $this->check($checks, 'quarantined_candidate_is_canon', $candidates['quarantined_canon_references'] ?? null, '<=',
            0, 'references', true, 'candidates');
        $integritySignals = self::sumOrNull(
            $events['critical_integrity_events'] ?? null,
            $runs['critical_error_runs'] ?? null,
            $integrity['canonical_revision_mismatches'] ?? null,
            $integrity['canonical_projection_mismatches'] ?? null,
            $integrity['quarantined_memory_writes'] ?? null,
            $integrity['writing_fence_violations'] ?? null,
            $integrity['postprocess_fence_violations'] ?? null,
            $integrity['shadow_projection_mismatches'] ?? null
        );
        $this->check($checks, 'critical_integrity_signals', $integritySignals, '<=',
            0, 'signals', true, 'runs+events+direct_integrity');

        $failed = [];
        $unavailable = [];
        $verifiedStopBreaches = [];
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $failed[] = $check['id'];
                if ($check['automatic_stop_eligible'] === true) {
                    $verifiedStopBreaches[] = $check['id'];
                }
            } elseif ($check['status'] === 'unavailable') {
                $unavailable[] = $check['id'];
            }
        }
        $metricsReliable = ($snapshot['metrics_reliable'] ?? false) === true;
        if (!$metricsReliable) {
            // A partial snapshot can help diagnosis, but it is not authority to
            // mutate the global emergency epoch.
            $verifiedStopBreaches = [];
        }

        return [
            'schema' => 'harness.release_readiness.v1',
            'generated_at' => $snapshot['collected_at'] ?? null,
            'window' => $snapshot['window'] ?? null,
            'metrics_reliable' => $metricsReliable,
            'readiness_passed' => $failed === [] && $unavailable === [],
            'stop_required' => $verifiedStopBreaches !== [],
            'verified_stop_breaches' => $verifiedStopBreaches,
            'summary' => [
                'checks' => count($checks),
                'failed' => count($failed),
                'unavailable' => count($unavailable),
            ],
            'failed_checks' => $failed,
            'unavailable_checks' => $unavailable,
            'thresholds' => $this->thresholds->toArray(),
            'checks' => $checks,
            'metrics' => $snapshot['metrics'] ?? null,
            'datasets' => $snapshot['datasets'] ?? null,
            'metric_errors' => $snapshot['errors'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    private function group(array $metrics, string $name): array
    {
        return is_array($metrics[$name] ?? null) ? $metrics[$name] : [];
    }

    /** @param list<array<string,mixed>> $checks */
    private function check(
        array &$checks,
        string $id,
        mixed $observed,
        string $operator,
        mixed $threshold,
        string $unit,
        bool $automaticStopEligible,
        string $sourceDataset
    ): void {
        $status = 'unavailable';
        if ($observed !== null) {
            $passed = match ($operator) {
                '<=' => is_numeric($observed) && is_numeric($threshold)
                    && (float)$observed <= (float)$threshold,
                '>=' => is_numeric($observed) && is_numeric($threshold)
                    && (float)$observed >= (float)$threshold,
                '==' => $observed === $threshold,
                default => throw new LogicException('Unknown readiness comparison operator.'),
            };
            $status = $passed ? 'pass' : 'fail';
        }
        $checks[] = [
            'id' => $id,
            'status' => $status,
            'observed' => $observed,
            'operator' => $operator,
            'threshold' => $threshold,
            'unit' => $unit,
            'automatic_stop_eligible' => $automaticStopEligible,
            'source_dataset' => $sourceDataset,
        ];
    }

    private static function quantileObserved(mixed $quantile): ?int
    {
        if (!is_array($quantile) || ($quantile['sample_count'] ?? 0) < 1) {
            return null;
        }
        if (($quantile['over_largest_bucket'] ?? false) === true) {
            // It is verified to be strictly beyond the last 3,600,000 ms bucket.
            return 3600001;
        }
        return is_int($quantile['upper_ms'] ?? null) ? $quantile['upper_ms'] : null;
    }

    private static function quantileOrZeroWhenEmpty(mixed $quantile, mixed $total): ?int
    {
        if ($total === 0 || $total === '0') {
            return 0;
        }
        return self::quantileObserved($quantile);
    }

    private static function zeroIfEmpty(mixed $value, mixed $total): mixed
    {
        if (($total === 0 || $total === '0') && $value === null) {
            return 0.0;
        }
        return $value;
    }

    private static function sumOrNull(mixed ...$values): int|float|null
    {
        $sum = 0;
        foreach ($values as $value) {
            if (!is_numeric($value)) {
                return null;
            }
            $sum += $value;
        }
        return $sum;
    }
}
