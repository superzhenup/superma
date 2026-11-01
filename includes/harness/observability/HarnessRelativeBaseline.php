<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Policy for the four relative rollout stops in execution plan section 16.2.
 * Candidate evidence is deliberately sampled in fixed five-minute windows:
 * an aggregate fifteen-minute p95 cannot prove a continuous breach inside it.
 */
final readonly class HarnessRelativeBaselinePolicy
{
    public function __construct(
        public float $failureRateDelta = 0.02,
        public float $p95Multiplier = 2.0,
        public int $p95SustainSeconds = 900,
        public int $p95ConsecutiveWindows = 3,
        public int $backlogConsecutiveWindows = 2,
        public int $backlogAgeSeconds = 1800,
        public int $seriesWindowSeconds = 300,
        public int $minFailureSamples = 20,
        public int $minLatencySamples = 10,
        public int $maxSeriesWindows = 288,
        public int $maxCollectionLagSeconds = 5
    ) {
        foreach ([
            'failure_rate_delta' => $failureRateDelta,
            'p95_multiplier' => $p95Multiplier,
        ] as $name => $value) {
            if (!is_finite($value) || $value <= 0) {
                throw new InvalidArgumentException("{$name} must be finite and positive.");
            }
        }
        if ($failureRateDelta > 1) {
            throw new InvalidArgumentException('failure_rate_delta cannot exceed 1.');
        }
        if ($p95Multiplier <= 1) {
            throw new InvalidArgumentException('Relative multipliers must be greater than 1.');
        }
        foreach ([
            'p95_sustain_seconds' => $p95SustainSeconds,
            'p95_consecutive_windows' => $p95ConsecutiveWindows,
            'backlog_consecutive_windows' => $backlogConsecutiveWindows,
            'backlog_age_seconds' => $backlogAgeSeconds,
            'series_window_seconds' => $seriesWindowSeconds,
            'min_failure_samples' => $minFailureSamples,
            'min_latency_samples' => $minLatencySamples,
            'max_series_windows' => $maxSeriesWindows,
            'max_collection_lag_seconds' => $maxCollectionLagSeconds,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("{$name} must be positive.");
            }
        }
        if ($seriesWindowSeconds < 300 || $seriesWindowSeconds > 604800) {
            throw new InvalidArgumentException(
                'series_window_seconds must fit the Harness metrics window bounds.'
            );
        }
        if ($p95SustainSeconds
            < $p95ConsecutiveWindows * $seriesWindowSeconds) {
            throw new InvalidArgumentException(
                'p95 sustain duration must cover all required consecutive windows.'
            );
        }
        $requiredP95Windows = max(
            $p95ConsecutiveWindows,
            (int)ceil($p95SustainSeconds / $seriesWindowSeconds)
        );
        if ($maxSeriesWindows < max(
            $requiredP95Windows,
            $backlogConsecutiveWindows
        )) {
            throw new InvalidArgumentException(
                'max_series_windows is smaller than a required proof interval.'
            );
        }
    }

    /** @return array<string,int|float> */
    public function toArray(): array
    {
        return [
            'failure_rate_delta' => $this->failureRateDelta,
            'p95_multiplier' => $this->p95Multiplier,
            'p95_sustain_seconds' => $this->p95SustainSeconds,
            'p95_consecutive_windows' => $this->p95ConsecutiveWindows,
            'backlog_consecutive_windows' => $this->backlogConsecutiveWindows,
            'backlog_age_seconds' => $this->backlogAgeSeconds,
            'series_window_seconds' => $this->seriesWindowSeconds,
            'min_failure_samples' => $this->minFailureSamples,
            'min_latency_samples' => $this->minLatencySamples,
            'max_series_windows' => $this->maxSeriesWindows,
            'max_collection_lag_seconds' => $this->maxCollectionLagSeconds,
        ];
    }
}

/**
 * Pure, read-only relative baseline evaluator. It never writes settings,
 * changes rollout flags, or increments the emergency epoch.
 */
final class HarnessRelativeBaselineEvaluator
{
    /** @var list<string> */
    private const REQUIRED_OPERATIONAL_METRICS = [
        'run_failure_rate',
        'whole_chapter_latency_p95',
        'first_token_latency_p95',
        'cache_hit_rate',
        'model_attempts_per_run',
        'model_retry_attempt_rate',
        'event_write_failure_rate',
    ];

    public function __construct(
        private readonly HarnessRelativeBaselinePolicy $policy =
            new HarnessRelativeBaselinePolicy()
    ) {
    }

    /**
     * @param array<string,mixed>|null $baseline
     * @param list<array<string,mixed>> $candidateSnapshots oldest to newest
     * @return array<string,mixed>
     */
    public function evaluate(?array $baseline, array $candidateSnapshots): array
    {
        $issues = [];
        $baselineWindow = $this->window($baseline, 'baseline', $issues);
        $candidateWindows = [];
        $seriesShapeValid = array_is_list($candidateSnapshots)
            && count($candidateSnapshots) >= 1
            && count($candidateSnapshots) <= $this->policy->maxSeriesWindows;
        if (!$seriesShapeValid) {
            $issues[] = $this->issue(
                'candidate_series_invalid',
                'Candidate snapshots must be a non-empty bounded chronological list.',
                'candidate_series'
            );
        }

        $boundedCandidates = array_slice(
            $candidateSnapshots,
            0,
            $this->policy->maxSeriesWindows
        );
        $evaluationSeries = $seriesShapeValid ? $boundedCandidates : [];

        foreach ($boundedCandidates as $index => $snapshot) {
            if (!is_array($snapshot)) {
                $issues[] = $this->issue(
                    'candidate_snapshot_malformed',
                    "Candidate snapshot {$index} is not an object.",
                    "candidate[{$index}]"
                );
                $candidateWindows[$index] = null;
                continue;
            }
            $candidateWindows[$index] = $this->window(
                $snapshot,
                "candidate[{$index}]",
                $issues
            );
        }

        $scopeComplete = $this->scopeAndSeriesEvidence(
            $baselineWindow,
            $candidateWindows,
            $issues
        );
        $snapshotEvidenceComplete = $this->snapshotEvidence(
            $baseline,
            'baseline',
            $issues
        );
        foreach ($boundedCandidates as $index => $snapshot) {
            if (!$this->snapshotEvidence(
                is_array($snapshot) ? $snapshot : null,
                "candidate[{$index}]",
                $issues
            )) {
                $snapshotEvidenceComplete = false;
            }
        }
        $stopFoundationComplete = $seriesShapeValid
            && $scopeComplete
            && $this->stopSnapshotEvidence($baseline);
        foreach ($boundedCandidates as $snapshot) {
            if (!$this->stopSnapshotEvidence(is_array($snapshot) ? $snapshot : null)) {
                $stopFoundationComplete = false;
            }
        }
        $globalEvidenceComplete = $seriesShapeValid
            && $scopeComplete
            && $snapshotEvidenceComplete
            && $issues === [];

        $checks = [
            $this->failureRateCheck($baseline, $evaluationSeries),
            $this->p95Check($baseline, $evaluationSeries, $scopeComplete),
            $this->backlogCheck($evaluationSeries, $scopeComplete),
        ];
        $checksEvidenceComplete = true;
        foreach ($checks as $check) {
            if (($check['evidence_complete'] ?? false) !== true
                || !in_array($check['status'] ?? null, ['pass', 'fail'], true)) {
                $checksEvidenceComplete = false;
                break;
            }
        }
        $releaseEvidenceComplete = $globalEvidenceComplete && $checksEvidenceComplete;
        foreach ($checks as &$check) {
            $check['automatic_stop_candidate'] = true;
            $check['automatic_stop_eligible'] = $stopFoundationComplete
                && ($check['evidence_complete'] ?? false) === true
                && ($check['status'] ?? null) === 'fail';
        }
        unset($check);

        $failed = [];
        $unavailable = [];
        $automatic = [];
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $failed[] = $check['id'];
                if ($check['automatic_stop_eligible'] === true) {
                    $automatic[] = $check['id'];
                }
            } elseif ($check['status'] === 'unavailable') {
                $unavailable[] = $check['id'];
            }
        }
        if (!$releaseEvidenceComplete) {
            $unavailable[] = 'release_evidence_complete';
        }
        $unavailable = array_values(array_unique($unavailable));
        $promotionAllowed = $failed === [] && $unavailable === [];

        return [
            'schema' => 'harness.relative_baseline_gate.v1',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'scope' => $baselineWindow['scope'] ?? null,
            'window' => is_array($candidateWindows[array_key_last($candidateWindows)] ?? null)
                ? [
                    'scope' => $candidateWindows[array_key_last($candidateWindows)]['scope'],
                ]
                : ['scope' => $baselineWindow['scope'] ?? null],
            'baseline_window' => $this->publicWindow($baselineWindow),
            'candidate_windows' => array_values(array_map(
                fn (mixed $window): ?array => $this->publicWindow($window),
                $candidateWindows
            )),
            'metrics_reliable' => $stopFoundationComplete,
            'release_evidence_complete' => $releaseEvidenceComplete,
            'promotion_allowed' => $promotionAllowed,
            'rollout_decision' => $failed !== []
                ? 'stop'
                : ($unavailable !== [] ? 'hold' : 'promote'),
            'expansion_stop_required' => $failed !== [],
            'automatic_stop_required' => $automatic !== [],
            'automatic_stop_breaches' => $automatic,
            'failed_checks' => $failed,
            'unavailable_checks' => $unavailable,
            'missing_evidence' => $issues,
            'policy' => $this->policy->toArray(),
            'checks' => $checks,
        ];
    }

    /** @return array<string,mixed> */
    private function failureRateCheck(?array $baseline, array $series): array
    {
        $candidate = $this->lastSnapshot($series);
        $baseMetric = $this->metric($baseline, 'run_failure_rate');
        $candidateMetric = $this->metric($candidate, 'run_failure_rate');
        if (!$this->sampled($baseMetric, $this->policy->minFailureSamples)
            || !$this->sampled($candidateMetric, $this->policy->minFailureSamples)) {
            return $this->unavailableCheck(
                'failure_rate_relative',
                'failure_rate_samples_incomplete',
                'Baseline and current failure rates require complete minimum samples.'
            );
        }
        $baselineValue = (float)$baseMetric['value'];
        $candidateValue = (float)$candidateMetric['value'];
        if (!$this->ratio($baselineValue) || !$this->ratio($candidateValue)) {
            return $this->unavailableCheck(
                'failure_rate_relative',
                'failure_rate_value_invalid',
                'Failure-rate evidence is outside [0,1].'
            );
        }
        $threshold = $baselineValue + $this->policy->failureRateDelta;
        return $this->resolvedCheck(
            'failure_rate_relative',
            $candidateValue > $threshold,
            [
                'baseline' => $baselineValue,
                'candidate' => $candidateValue,
                'baseline_samples' => (int)$baseMetric['sample_count'],
                'candidate_samples' => (int)$candidateMetric['sample_count'],
            ],
            '>',
            $threshold,
            'ratio'
        );
    }

    /** @return array<string,mixed> */
    private function p95Check(?array $baseline, array $series, bool $seriesValid): array
    {
        $baseMetric = $this->metric($baseline, 'whole_chapter_latency_p95');
        if (!$this->sampled($baseMetric, $this->policy->minLatencySamples)
            || !is_numeric($baseMetric['value'] ?? null)
            || (float)$baseMetric['value'] <= 0) {
            return $this->unavailableCheck(
                'whole_chapter_p95_relative_sustained',
                'baseline_p95_samples_incomplete',
                'A positive exact baseline p95 with complete minimum samples is required.'
            );
        }
        $threshold = (float)$baseMetric['value'] * $this->policy->p95Multiplier;
        $latest = $this->metric($this->lastSnapshot($series), 'whole_chapter_latency_p95');
        if (!$this->sampled($latest, $this->policy->minLatencySamples)) {
            return $this->unavailableCheck(
                'whole_chapter_p95_relative_sustained',
                'candidate_p95_samples_incomplete',
                'The current exact p95 sample is incomplete.'
            );
        }
        if ((float)$latest['value'] <= $threshold) {
            return $this->resolvedCheck(
                'whole_chapter_p95_relative_sustained',
                false,
                ['latest_p95_ms' => (float)$latest['value'], 'sustained_seconds' => 0],
                '>',
                $threshold,
                'ms'
            );
        }
        if (!$seriesValid) {
            return $this->unavailableCheck(
                'whole_chapter_p95_relative_sustained',
                'p95_series_not_contiguous',
                'A fixed, contiguous candidate series is required to prove duration.'
            );
        }

        $seconds = 0;
        $windows = 0;
        for ($index = count($series) - 1; $index >= 0; $index--) {
            $metric = $this->metric(
                is_array($series[$index] ?? null) ? $series[$index] : null,
                'whole_chapter_latency_p95'
            );
            if (!$this->sampled($metric, $this->policy->minLatencySamples)) {
                return $this->unavailableCheck(
                    'whole_chapter_p95_relative_sustained',
                    'p95_series_samples_incomplete',
                    'Every window in the claimed sustained interval needs complete p95 samples.'
                );
            }
            if ((float)$metric['value'] <= $threshold) {
                return $this->resolvedCheck(
                    'whole_chapter_p95_relative_sustained',
                    false,
                    ['latest_p95_ms' => (float)$latest['value'], 'sustained_seconds' => $seconds],
                    '>',
                    $threshold,
                    'ms'
                );
            }
            $seconds += $this->policy->seriesWindowSeconds;
            $windows++;
            if ($seconds >= $this->policy->p95SustainSeconds
                && $windows >= $this->policy->p95ConsecutiveWindows) {
                return $this->resolvedCheck(
                    'whole_chapter_p95_relative_sustained',
                    true,
                    [
                        'baseline_p95_ms' => (float)$baseMetric['value'],
                        'latest_p95_ms' => (float)$latest['value'],
                        'baseline_samples' => (int)$baseMetric['sample_count'],
                        'candidate_samples' => (int)$latest['sample_count'],
                        'sustained_seconds' => $seconds,
                        'consecutive_windows' => $windows,
                    ],
                    '>',
                    $threshold,
                    'ms'
                );
            }
        }
        return $this->unavailableCheck(
            'whole_chapter_p95_relative_sustained',
            'p95_history_too_short',
            'The visible breach does not have enough preceding windows to prove or disprove 15 minutes.'
        );
    }

    /** @return array<string,mixed> */
    private function backlogCheck(array $series, bool $seriesValid): array
    {
        $latest = $this->backlog($this->lastSnapshot($series));
        if ($latest === null) {
            return $this->unavailableCheck(
                'postprocess_oldest_backlog_sustained_growth',
                'postprocess_backlog_evidence_unavailable',
                'The authoritative postprocess backlog sample is unavailable.'
            );
        }
        if ($latest['due_backlog'] === 0
            || $latest['oldest_backlog_seconds'] <= $this->policy->backlogAgeSeconds) {
            return $this->resolvedCheck(
                'postprocess_oldest_backlog_sustained_growth',
                false,
                $latest,
                '>',
                $this->policy->backlogAgeSeconds,
                'seconds'
            );
        }
        if (!$seriesValid || count($series) < $this->policy->backlogConsecutiveWindows) {
            return $this->unavailableCheck(
                'postprocess_oldest_backlog_sustained_growth',
                'backlog_history_too_short',
                'Consecutive fixed windows are required to prove sustained backlog growth.'
            );
        }
        $tail = array_slice($series, -$this->policy->backlogConsecutiveWindows);
        $previousAge = null;
        foreach ($tail as $snapshot) {
            $sample = $this->backlog(is_array($snapshot) ? $snapshot : null);
            if ($sample === null) {
                return $this->unavailableCheck(
                    'postprocess_oldest_backlog_sustained_growth',
                    'backlog_series_samples_incomplete',
                    'Every backlog window in the proof interval needs authoritative evidence.'
                );
            }
            if ($sample['due_backlog'] === 0
                || $sample['oldest_backlog_seconds'] <= $this->policy->backlogAgeSeconds
                || ($previousAge !== null
                    && $sample['oldest_backlog_seconds'] <= $previousAge)) {
                return $this->resolvedCheck(
                    'postprocess_oldest_backlog_sustained_growth',
                    false,
                    $latest + ['consecutive_windows' => 0],
                    '>',
                    $this->policy->backlogAgeSeconds,
                    'seconds'
                );
            }
            $previousAge = $sample['oldest_backlog_seconds'];
        }
        return $this->resolvedCheck(
            'postprocess_oldest_backlog_sustained_growth',
            true,
            $latest + [
                'consecutive_windows' => $this->policy->backlogConsecutiveWindows,
                'strictly_growing' => true,
            ],
            '>',
            $this->policy->backlogAgeSeconds,
            'seconds'
        );
    }

    /** @param list<array<string,mixed>> $issues */
    private function snapshotEvidence(?array $snapshot, string $label, array &$issues): bool
    {
        if (!is_array($snapshot)
            || ($snapshot['schema'] ?? null) !== 'harness.rollout_observability.v1') {
            $issues[] = $this->issue(
                'rollout_snapshot_missing_or_invalid',
                "{$label} is not a supported rollout observability snapshot.",
                $label
            );
            return false;
        }
        $complete = true;
        if (($snapshot['metrics_reliable'] ?? false) !== true
            || ($snapshot['release_evidence_complete'] ?? false) !== true
            || !is_array($snapshot['errors'] ?? null)
            || $snapshot['errors'] !== []
            || !is_array($snapshot['missing_evidence'] ?? null)
            || !array_is_list($snapshot['missing_evidence'])
            || $snapshot['missing_evidence'] !== []) {
            $issues[] = $this->issue(
                'snapshot_metrics_incomplete',
                "{$label} is partial or reports metric errors.",
                $label
            );
            $complete = false;
        }
        $base = $snapshot['base_snapshot'] ?? null;
        if (!is_array($base)
            || ($base['schema'] ?? null) !== 'harness.metrics.snapshot.v1'
            || ($base['metrics_reliable'] ?? false) !== true
            || !is_array($base['errors'] ?? null)
            || $base['errors'] !== []) {
            $issues[] = $this->issue(
                'base_snapshot_metrics_incomplete',
                "{$label} does not contain a complete base metrics snapshot.",
                "{$label}.base_snapshot"
            );
            $complete = false;
        }
        $dimension = $snapshot['dimension_detail'] ?? null;
        if (!is_array($dimension)
            || ($dimension['available'] ?? false) !== true
            || ($dimension['identity_complete'] ?? false) !== true
            || ($dimension['truncated'] ?? null) !== false
            || ($dimension['suppressed_or_omitted_runs'] ?? null) !== 0
            || ($dimension['invalid_identity_runs'] ?? null) !== 0) {
            $issues[] = $this->issue(
                'dimension_identity_incomplete',
                "{$label} does not account for every Run in bounded rollout dimensions.",
                "{$label}.dimension_detail"
            );
            $complete = false;
        }
        foreach (self::REQUIRED_OPERATIONAL_METRICS as $name) {
            $evidence = $this->metric($snapshot, $name);
            if ($evidence === null || $evidence['sample_count'] < 1) {
                $metric = $snapshot['operational_metrics'][$name] ?? null;
                $reason = is_array($metric) && is_string($metric['reason_code'] ?? null)
                    ? $metric['reason_code']
                    : 'metric_unavailable';
                $issues[] = $this->issue(
                    $reason,
                    "{$label} lacks durable {$name} evidence.",
                    "{$label}.operational_metrics.{$name}"
                );
                $complete = false;
            }
        }
        return $complete;
    }

    /**
     * Structural/query evidence shared by each automatic-stop check. Missing
     * unrelated operational telemetry is intentionally not included here: it
     * blocks promotion through snapshotEvidence(), but cannot erase a fully
     * proved failure/latency/backlog regression.
     */
    private function stopSnapshotEvidence(?array $snapshot): bool
    {
        if (!is_array($snapshot)
            || ($snapshot['schema'] ?? null) !== 'harness.rollout_observability.v1'
            || !is_array($snapshot['errors'] ?? null)
            || !is_array($snapshot['missing_evidence'] ?? null)
            || !array_is_list($snapshot['missing_evidence'])
            || !is_array($snapshot['operational_metrics'] ?? null)) {
            return false;
        }
        $base = $snapshot['base_snapshot'] ?? null;
        return is_array($base)
            && ($base['schema'] ?? null) === 'harness.metrics.snapshot.v1';
    }

    /**
     * @param array<string,mixed>|null $baselineWindow
     * @param array<int,array<string,mixed>|null> $candidateWindows
     * @param list<array<string,string>> $issues
     */
    private function scopeAndSeriesEvidence(
        ?array $baselineWindow,
        array $candidateWindows,
        array &$issues
    ): bool {
        if ($baselineWindow === null || $candidateWindows === []) {
            return false;
        }
        $complete = true;
        $previous = null;
        foreach ($candidateWindows as $index => $window) {
            if (!is_array($window)) {
                $complete = false;
                continue;
            }
            if ($window['scope'] !== $baselineWindow['scope']) {
                $issues[] = $this->issue(
                    'rollout_scope_mismatch',
                    "candidate[{$index}] does not match the baseline scope.",
                    "candidate[{$index}].window.scope"
                );
                $complete = false;
            }
            if ($window['seconds'] !== $this->policy->seriesWindowSeconds) {
                $issues[] = $this->issue(
                    'candidate_window_granularity_invalid',
                    "candidate[{$index}] is not a fixed {$this->policy->seriesWindowSeconds}-second window.",
                    "candidate[{$index}].window"
                );
                $complete = false;
            }
            if ($previous !== null && $window['from_utc'] !== $previous['to_utc']) {
                $issues[] = $this->issue(
                    'candidate_windows_not_contiguous',
                    "candidate[{$index}] does not start at the preceding half-open upper bound.",
                    "candidate[{$index}].window"
                );
                $complete = false;
            }
            $previous = $window;
        }
        $first = $candidateWindows[0] ?? null;
        if (is_array($first) && $baselineWindow['to_epoch'] > $first['from_epoch']) {
            $issues[] = $this->issue(
                'baseline_overlaps_candidate',
                'The historical baseline overlaps the candidate observation series.',
                'baseline.window'
            );
            $complete = false;
        }
        return $complete;
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @param list<array<string,string>> $issues
     * @return array<string,mixed>|null
     */
    private function window(?array $snapshot, string $label, array &$issues): ?array
    {
        $window = $snapshot['window'] ?? null;
        if (!is_array($window)
            || !is_string($window['from_utc'] ?? null)
            || !is_string($window['to_utc'] ?? null)
            || !is_int($window['seconds'] ?? null)
            || !is_array($window['scope'] ?? null)) {
            $issues[] = $this->issue(
                'window_evidence_missing',
                "{$label} has no complete immutable observation window.",
                "{$label}.window"
            );
            return null;
        }
        $from = $this->utc($window['from_utc']);
        $to = $this->utc($window['to_utc']);
        $collectedAt = is_string($snapshot['collected_at'] ?? null)
            ? $this->utc($snapshot['collected_at'])
            : null;
        $scope = $this->scope($window['scope']);
        if ($from === null || $to === null || $scope === null
            || $to->getTimestamp() <= $from->getTimestamp()
            || $window['seconds'] !== $to->getTimestamp() - $from->getTimestamp()
            || $window['seconds'] < 300
            || $window['seconds'] > 604800) {
            $issues[] = $this->issue(
                'window_evidence_invalid',
                "{$label} has an invalid UTC interval or scope.",
                "{$label}.window"
            );
            return null;
        }
        if ($collectedAt === null
            || $collectedAt->getTimestamp() < $to->getTimestamp()
            || $collectedAt->getTimestamp()
                > $to->getTimestamp() + $this->policy->maxCollectionLagSeconds) {
            $issues[] = $this->issue(
                'collection_time_invalid',
                "{$label} was not collected at its immutable window upper bound.",
                "{$label}.collected_at"
            );
            return null;
        }
        return [
            'from_utc' => $window['from_utc'],
            'to_utc' => $window['to_utc'],
            'from_epoch' => $from->getTimestamp(),
            'to_epoch' => $to->getTimestamp(),
            'seconds' => $window['seconds'],
            'scope' => $scope,
        ];
    }

    /** @return array{novel_id:?int,workflow_name:?string,workflow_version:?string}|null */
    private function scope(array $scope): ?array
    {
        if (array_keys($scope) !== ['novel_id', 'workflow_name', 'workflow_version']) {
            return null;
        }
        $novel = $scope['novel_id'];
        if ($novel !== null && (!is_int($novel) || $novel < 1)) {
            return null;
        }
        foreach (['workflow_name' => 64, 'workflow_version' => 32] as $key => $max) {
            $value = $scope[$key];
            if ($value !== null
                && (!is_string($value)
                    || strlen($value) > $max
                    || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1)) {
                return null;
            }
        }
        return [
            'novel_id' => $novel,
            'workflow_name' => $scope['workflow_name'],
            'workflow_version' => $scope['workflow_version'],
        ];
    }

    private function utc(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        return $date instanceof DateTimeImmutable
            && ($errors === false
                || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:s.u\Z') === $value
                ? $date
                : null;
    }

    /** @return array{status:string,value:int|float,sample_count:int}|null */
    private function metric(?array $snapshot, string $name): ?array
    {
        $metric = $snapshot['operational_metrics'][$name] ?? null;
        if (!is_array($metric)
            || ($metric['status'] ?? null) !== 'available'
            || !is_numeric($metric['value'] ?? null)
            || !is_finite((float)$metric['value'])
            || (float)$metric['value'] < 0
            || !is_int($metric['sample_count'] ?? null)
            || $metric['sample_count'] < 0) {
            return null;
        }
        return [
            'status' => 'available',
            'value' => is_int($metric['value'])
                ? $metric['value']
                : (float)$metric['value'],
            'sample_count' => $metric['sample_count'],
        ];
    }

    /** @return array{due_backlog:int,oldest_backlog_seconds:int}|null */
    private function backlog(?array $snapshot): ?array
    {
        $base = $snapshot['base_snapshot'] ?? null;
        $dataset = $base['datasets']['postprocess'] ?? null;
        $metrics = $base['metrics']['postprocess'] ?? null;
        if (!is_array($base)
            || ($base['schema'] ?? null) !== 'harness.metrics.snapshot.v1'
            || !is_array($dataset)
            || ($dataset['available'] ?? false) !== true
            || !is_array($metrics)
            || !is_int($metrics['due_backlog'] ?? null)
            || $metrics['due_backlog'] < 0
            || !is_int($metrics['oldest_backlog_seconds'] ?? null)
            || $metrics['oldest_backlog_seconds'] < 0) {
            return null;
        }
        return [
            'due_backlog' => $metrics['due_backlog'],
            'oldest_backlog_seconds' => $metrics['oldest_backlog_seconds'],
        ];
    }

    private function sampled(?array $metric, int $minimum): bool
    {
        return is_array($metric) && $metric['sample_count'] >= $minimum;
    }

    private function ratio(float $value): bool
    {
        return is_finite($value) && $value >= 0 && $value <= 1;
    }

    /** @param list<array<string,mixed>> $series @return array<string,mixed>|null */
    private function lastSnapshot(array $series): ?array
    {
        $last = $series === [] ? null : $series[array_key_last($series)];
        return is_array($last) ? $last : null;
    }

    /** @return array<string,mixed> */
    private function resolvedCheck(
        string $id,
        bool $breached,
        array $observed,
        string $operator,
        int|float $threshold,
        string $unit
    ): array {
        return [
            'id' => $id,
            'status' => $breached ? 'fail' : 'pass',
            'observed' => $observed,
            'operator' => $operator,
            'threshold' => $threshold,
            'unit' => $unit,
            'evidence_complete' => true,
            'reason_code' => $breached ? 'relative_threshold_breached' : null,
        ];
    }

    /** @return array<string,mixed> */
    private function unavailableCheck(string $id, string $reasonCode, string $reason): array
    {
        return [
            'id' => $id,
            'status' => 'unavailable',
            'observed' => null,
            'operator' => null,
            'threshold' => null,
            'unit' => null,
            'evidence_complete' => false,
            'reason_code' => $reasonCode,
            'reason' => $reason,
        ];
    }

    /** @return array{reason_code:string,reason:string,source:string} */
    private function issue(string $code, string $reason, string $source): array
    {
        return ['reason_code' => $code, 'reason' => $reason, 'source' => $source];
    }

    /** @return array<string,mixed>|null */
    private function publicWindow(mixed $window): ?array
    {
        if (!is_array($window)) {
            return null;
        }
        return [
            'from_utc' => $window['from_utc'],
            'to_utc' => $window['to_utc'],
            'seconds' => $window['seconds'],
        ];
    }
}
