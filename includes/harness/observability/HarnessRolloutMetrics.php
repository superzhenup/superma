<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessMetricsAggregator.php';
require_once __DIR__ . '/HarnessMetricsWindow.php';

/**
 * Evidence-backed rollout metrics that can be computed from the released M62+
 * schema without inventing telemetry. The result set is intentionally bounded
 * and never emits raw model names, custom genres, prompts, or user content.
 */
final class HarnessRolloutMetricsAggregator
{
    public const DIMENSION_GROUP_LIMIT = 32;
    public const MIN_DIMENSION_GROUP_RUNS = 3;
    public const EXACT_P95_SAMPLE_LIMIT = 100000;

    /** @var list<int> */
    private const LATENCY_BUCKETS_MS = [
        30000, 60000, 120000, 300000, 600000, 900000, 1800000, 3600000,
    ];

    /** @var list<string> */
    private const GENRE_KEYS = [
        'xuanhuan', 'qihuan', 'dushi', 'yanqing', 'wuxia', 'kehuan',
        'xuanyi', 'lishi', 'youxi', 'tiyu', 'junshi', 'lingyi',
        'qingxiaoshuo', 'erciyuan', 'other', 'unknown',
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
        $base = (new HarnessMetricsAggregator($this->db))->collect(
            $window,
            $collectedAt
        );
        $datasets = [];
        $errors = [];

        $datasets['attempts'] = $this->readAggregate(
            'rollout_attempts',
            $this->attemptSql($window),
            $this->params($window),
            [
                'eligible_run_count', 'covered_run_count',
                'total_attempts', 'terminal_attempts', 'incomplete_attempts',
                'completed_attempts',
                'failed_attempts', 'canceled_attempts', 'retry_attempts',
                'known_cache_token_attempts', 'input_tokens',
                'output_tokens', 'cache_tokens', 'latency_samples',
            ],
            ['latency_avg_ms', 'latency_max_ms'],
            $errors
        );
        $datasets['chapter_outcomes'] = $this->readAggregate(
            'rollout_chapters',
            $this->chapterOutcomeSql($window),
            $this->params($window),
            [
                'total_chapter_runs', 'outcome_chapter_runs',
                'successful_chapter_runs', 'failed_chapter_runs',
                'whole_chapter_latency_samples', 'accepted_runs',
                'accepted_evidenced_runs', 'accepted_missing_evidence_runs',
                'successful_without_commit_runs', 'accepted_words',
            ],
            [],
            $errors
        );

        $chapterMetrics = self::availableDatasetMetrics($datasets['chapter_outcomes']);
        $latencySamples = is_array($chapterMetrics)
            ? (int)$chapterMetrics['whole_chapter_latency_samples']
            : 0;
        $datasets['chapter_latency_p95'] = $this->readChapterP95(
            $window,
            $latencySamples,
            $errors
        );
        $datasets['dimension_groups'] = $this->readDimensionGroups(
            $window,
            $datasets['chapter_outcomes'],
            $errors
        );

        $now = ($collectedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $operational = $this->deriveOperationalMetrics($datasets);
        $missingEvidence = [];
        foreach ($operational as $metric => $evidence) {
            if (is_array($evidence) && ($evidence['status'] ?? null) === 'unavailable') {
                $missingEvidence[] = [
                    'metric' => $metric,
                    'reason_code' => (string)($evidence['reason_code'] ?? 'unavailable'),
                    'reason' => (string)($evidence['reason'] ?? 'Metric evidence is unavailable.'),
                    'source' => (string)($evidence['source'] ?? 'unknown'),
                ];
            }
        }
        $dimension = $datasets['dimension_groups'];
        if (($dimension['available'] ?? false) !== true) {
            $missingEvidence[] = [
                'metric' => 'dimension_groups',
                'reason_code' => 'dimension_query_unavailable',
                'reason' => 'The bounded rollout dimension query did not complete.',
                'source' => 'generation_runs+generation_attempts+novels',
            ];
        } elseif (($dimension['identity_complete'] ?? false) !== true) {
            $missingEvidence[] = [
                'metric' => 'dimension_identity_coverage',
                'reason_code' => 'dimension_identity_incomplete',
                'reason' => 'Some Run identities were suppressed, omitted, or missing durable dimensions.',
                'source' => 'dimension_groups',
            ];
        }

        $allErrors = array_merge(
            is_array($base['errors'] ?? null) ? $base['errors'] : [],
            $errors
        );
        $metricsReliable = ($base['metrics_reliable'] ?? false) === true
            && $errors === [];

        return [
            'schema' => 'harness.rollout_observability.v1',
            'collected_at' => $now->format('Y-m-d\TH:i:s.u\Z'),
            'window' => $window->toArray(),
            'metrics_reliable' => $metricsReliable,
            'release_evidence_complete' => $metricsReliable && $missingEvidence === [],
            'operational_metrics' => $operational,
            'dimension_detail' => $dimension,
            'missing_evidence' => $missingEvidence,
            'datasets' => $datasets,
            'errors' => $allErrors,
            'base_snapshot' => $base,
        ];
    }

    /** @param list<array<string,string>> $errors @return array<string,mixed> */
    private function readAggregate(
        string $marker,
        string $sql,
        array $params,
        array $integerFields,
        array $numberFields,
        array &$errors
    ): array {
        try {
            self::assertReadOnlySql($sql, $marker);
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
            return ['available' => true, 'metrics' => $metrics];
        } catch (Throwable $exception) {
            $errors[] = self::error($marker, $exception);
            return ['available' => false, 'metrics' => null];
        }
    }

    /** @param list<array<string,string>> $errors @return array<string,mixed> */
    private function readChapterP95(
        HarnessMetricsWindow $window,
        int $sampleCount,
        array &$errors
    ): array {
        if ($sampleCount < 1) {
            return [
                'available' => true,
                'metrics' => ['sample_count' => 0, 'p95_ms' => null],
            ];
        }
        if ($sampleCount > self::EXACT_P95_SAMPLE_LIMIT) {
            return [
                'available' => true,
                'metrics' => [
                    'sample_count' => $sampleCount,
                    'p95_ms' => null,
                    'reason_code' => 'exact_p95_sample_limit_exceeded',
                ],
            ];
        }
        $offset = (int)ceil($sampleCount * 0.95) - 1;
        try {
            $sql = $this->chapterP95Sql($window, $offset);
            self::assertReadOnlySql($sql, 'rollout_chapter_p95');
            $row = $this->db->fetchOne($sql, $this->params($window));
            if (!is_array($row)) {
                throw new RuntimeException('Chapter p95 query returned no row.');
            }
            return [
                'available' => true,
                'metrics' => [
                    'sample_count' => $sampleCount,
                    'p95_ms' => self::nonNegativeInteger(
                        $row['latency_ms'] ?? null,
                        'whole_chapter_p95_ms'
                    ),
                ],
            ];
        } catch (Throwable $exception) {
            $errors[] = self::error('rollout_chapter_p95', $exception);
            return ['available' => false, 'metrics' => null];
        }
    }

    /** @param list<array<string,string>> $errors @return array<string,mixed> */
    private function readDimensionGroups(
        HarnessMetricsWindow $window,
        array $chapterOutcomes,
        array &$errors
    ): array {
        try {
            $sql = $this->dimensionSql($window);
            self::assertReadOnlySql($sql, 'rollout_dimensions');
            $rows = $this->db->fetchAll($sql, $this->dimensionParams($window));
            if (count($rows) > self::DIMENSION_GROUP_LIMIT + 1) {
                throw new RuntimeException('Dimension query exceeded its hard row limit.');
            }
            $truncated = count($rows) > self::DIMENSION_GROUP_LIMIT;
            $rows = array_slice($rows, 0, self::DIMENSION_GROUP_LIMIT);
            $groups = [];
            $displayedRuns = 0;
            $invalidIdentityRuns = 0;
            foreach ($rows as $row) {
                $group = $this->normalizeDimensionGroup($row);
                $groups[] = $group;
                $runs = (int)$group['metrics']['total_runs'];
                $displayedRuns += $runs;
                if (($group['identity_complete'] ?? false) !== true) {
                    $invalidIdentityRuns += $runs;
                }
            }
            $chapterMetrics = self::availableDatasetMetrics($chapterOutcomes);
            $chapterTotal = is_array($chapterMetrics)
                ? (int)$chapterMetrics['total_chapter_runs']
                : null;
            $suppressedOrOmitted = $chapterTotal === null
                ? null
                : max(0, $chapterTotal - $displayedRuns);
            $identityComplete = !$truncated
                && $suppressedOrOmitted === 0
                && $invalidIdentityRuns === 0;

            return [
                'available' => true,
                'grouping' => [
                    'workflow_version', 'prompt_manifest_hash', 'model',
                    'genre', 'rollout_cohort',
                ],
                'population' => [
                    'run_type' => 'chapter.generate',
                    'authority_mode' => 'authority',
                    'control_population' => 'requires_separate_legacy_baseline_evidence',
                ],
                'privacy' => [
                    'max_groups' => self::DIMENSION_GROUP_LIMIT,
                    'minimum_group_runs' => self::MIN_DIMENSION_GROUP_RUNS,
                    'model_values' => 'sha256_fingerprint_only',
                    'prompt_manifest_values' => 'sha256_prefix_only',
                    'genre_values' => 'fixed_allowlist_other_bucket',
                    'raw_user_values_emitted' => false,
                ],
                'returned_groups' => count($groups),
                'displayed_runs' => $displayedRuns,
                'suppressed_or_omitted_runs' => $suppressedOrOmitted,
                'invalid_identity_runs' => $invalidIdentityRuns,
                'truncated' => $truncated,
                'identity_complete' => $identityComplete,
                'groups' => $groups,
            ];
        } catch (Throwable $exception) {
            $errors[] = self::error('rollout_dimensions', $exception);
            return [
                'available' => false,
                'returned_groups' => 0,
                'truncated' => false,
                'identity_complete' => false,
                'groups' => [],
            ];
        }
    }

    /** @return array<string,mixed> */
    private function normalizeDimensionGroup(array $row): array
    {
        $workflow = self::dimensionString(
            $row['workflow_version_key'] ?? null,
            '/^(?:[A-Za-z0-9][A-Za-z0-9_.-]{0,31}|__invalid__)$/D',
            'workflow_version'
        );
        $prompt = self::dimensionString(
            $row['prompt_manifest_key'] ?? null,
            '/^(?:sha256:[a-f0-9]{16}|__missing_or_invalid__)$/D',
            'prompt_manifest_hash'
        );
        $model = self::dimensionString(
            $row['model_key'] ?? null,
            '/^(?:sha256:[a-f0-9]{16}|__none__|__unknown__|__multiple__)$/D',
            'model'
        );
        $genre = self::dimensionString(
            $row['genre_key'] ?? null,
            '/^[a-z][a-z0-9_]{0,31}$/D',
            'genre'
        );
        if (!in_array($genre, self::GENRE_KEYS, true)) {
            throw new RuntimeException('genre is outside the fixed observability allowlist.');
        }
        $cohort = self::dimensionString(
            $row['rollout_cohort'] ?? null,
            '/^(?:off|shadow|canary_control|canary_treatment|on|inconsistent|unknown)$/D',
            'rollout_cohort'
        );
        $integerFields = [
            'total_runs', 'outcome_runs', 'successful_runs', 'failed_runs',
            'attempt_eligible_runs', 'attempt_covered_runs',
            'attempts', 'terminal_attempts', 'retry_attempts',
            'known_cache_token_attempts',
            'latency_samples', 'lat_le_30000',
            'lat_le_60000', 'lat_le_120000', 'lat_le_300000',
            'lat_le_600000', 'lat_le_900000', 'lat_le_1800000',
            'lat_le_3600000', 'accepted_runs',
            'accepted_missing_evidence_runs', 'successful_without_commit_runs',
            'accepted_words',
        ];
        $metrics = [];
        foreach ($integerFields as $field) {
            $metrics[$field] = self::nonNegativeInteger($row[$field] ?? null, $field);
        }
        $metrics['failure_rate'] = self::ratio(
            $metrics['failed_runs'],
            $metrics['outcome_runs']
        );
        $attemptCoverageComplete = $metrics['attempt_eligible_runs'] > 0
            && $metrics['attempt_covered_runs'] === $metrics['attempt_eligible_runs']
            && $metrics['terminal_attempts'] === $metrics['attempts'];
        $metrics['attempts_per_run'] = $attemptCoverageComplete
            ? self::ratio($metrics['attempts'], $metrics['attempt_eligible_runs'])
            : null;
        $metrics['retry_attempt_rate'] = $attemptCoverageComplete
            ? self::ratio($metrics['retry_attempts'], $metrics['attempts'])
            : null;
        $metrics['cache_hit_rate'] = null;
        $metrics['whole_chapter_latency_p95'] = self::bucketQuantile($metrics, 0.95);

        return [
            'dimensions' => [
                'workflow_version' => $workflow,
                'prompt_manifest_hash' => $prompt,
                'model' => $model,
                'genre' => $genre,
                'rollout_cohort' => $cohort,
            ],
            'identity_complete' => $workflow !== '__invalid__'
                && $prompt !== '__missing_or_invalid__'
                && !in_array($model, ['__none__', '__unknown__', '__multiple__'], true)
                && !in_array($cohort, ['unknown', 'inconsistent'], true),
            'metrics' => $metrics,
            'unavailable_metrics' => [
                'first_token_latency' => 'no_durable_first_token_timestamp',
                'cache_hit_rate' => 'no_durable_cache_observation_marker',
                'event_write_failures' => 'no_durable_event_write_attempt_ledger',
                ...($attemptCoverageComplete ? [] : [
                    'attempts_per_run' => 'attempt_run_coverage_incomplete',
                    'retry_attempt_rate' => 'attempt_run_coverage_incomplete',
                ]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function deriveOperationalMetrics(array $datasets): array
    {
        $attempts = self::availableDatasetMetrics($datasets['attempts'] ?? null);
        $chapters = self::availableDatasetMetrics($datasets['chapter_outcomes'] ?? null);
        $p95 = self::availableDatasetMetrics($datasets['chapter_latency_p95'] ?? null);

        $wholeLatency = !is_array($p95) || !is_array($chapters)
            ? self::unavailableMetric(
                'ms',
                'chapter_latency_query_unavailable',
                'Whole-chapter latency SQL evidence is unavailable.',
                'generation_runs'
            )
            : ((int)$p95['sample_count'] < 1
                ? self::unavailableMetric(
                    'ms',
                    'no_whole_chapter_latency_samples',
                    'No successful Chapter Run has a complete started/completed timestamp pair.',
                    'generation_runs',
                    0
                )
                : (!is_int($p95['p95_ms'] ?? null)
                    ? self::unavailableMetric(
                        'ms',
                        (string)($p95['reason_code'] ?? 'exact_p95_unavailable'),
                        'The exact p95 query was not executed within its bounded sample policy.',
                        'generation_runs',
                        (int)$p95['sample_count']
                    )
                    : self::availableMetric(
                        $p95['p95_ms'],
                        'ms',
                        'generation_runs.started_at+completed_at',
                        (int)$p95['sample_count']
                    )));

        // cache_tokens is NOT NULL DEFAULT 0 and has no companion
        // "provider reported cache usage" bit. Zero therefore cannot be
        // distinguished from missing provider telemetry.
        $cacheHit = self::unavailableMetric(
            'ratio',
            'no_durable_cache_observation_marker',
            'cache_tokens=0 is ambiguous because persistence has no provider-observed marker.',
            'missing_schema_evidence'
        );

        $totalRuns = is_array($attempts)
            ? (int)$attempts['eligible_run_count']
            : 0;
        $attemptCoverageComplete = is_array($attempts)
            && (int)$attempts['eligible_run_count'] > 0
            && (int)$attempts['covered_run_count']
                === (int)$attempts['eligible_run_count']
            && (int)$attempts['incomplete_attempts'] === 0
            && (int)$attempts['terminal_attempts'] === (int)$attempts['total_attempts'];
        $attemptsPerRun = !$attemptCoverageComplete || $totalRuns < 1
            ? self::unavailableMetric(
                'attempts_per_run',
                'attempt_run_coverage_incomplete',
                'Not every terminal authority Chapter Run has a durable model Attempt.',
                'generation_runs+generation_attempts',
                is_array($attempts) ? (int)$attempts['covered_run_count'] : 0,
                is_array($attempts) ? [
                    'eligible_run_count' => (int)$attempts['eligible_run_count'],
                    'covered_run_count' => (int)$attempts['covered_run_count'],
                    'incomplete_attempts' => (int)$attempts['incomplete_attempts'],
                ] : []
            )
            : self::unavailableMetric(
                'attempts_per_run',
                'no_complete_model_call_attempt_ledger',
                'The authority adapter records only the selected successful model call; failed fallback calls have no complete durable ledger.',
                'generation_attempts',
                $totalRuns,
                [
                    'recorded_attempts' => (int)$attempts['total_attempts'],
                    'recorded_attempts_per_run' =>
                        self::ratio($attempts['total_attempts'], $totalRuns),
                ]
            );
        $retryRate = !$attemptCoverageComplete || (int)$attempts['total_attempts'] < 1
            ? self::unavailableMetric(
                'ratio',
                'attempt_run_coverage_incomplete',
                'Retry rate is unavailable until every terminal authority Chapter Run has Attempt evidence.',
                'generation_attempts.attempt_no',
                is_array($attempts) ? (int)$attempts['covered_run_count'] : 0
            )
            : self::unavailableMetric(
                'ratio',
                'no_complete_model_call_attempt_ledger',
                'Selected-call Attempt rows cannot measure failed fallback/retry calls without a complete durable call ledger.',
                'generation_attempts.attempt_no',
                (int)$attempts['total_attempts'],
                [
                    'recorded_retry_attempts' => (int)$attempts['retry_attempts'],
                    'recorded_retry_rate' => self::ratio(
                        $attempts['retry_attempts'],
                        $attempts['total_attempts']
                    ),
                ]
            );

        return [
            'run_failure_rate' => is_array($chapters)
                && (int)$chapters['outcome_chapter_runs'] > 0
                ? self::availableMetric(
                    (float)self::ratio(
                        $chapters['failed_chapter_runs'],
                        $chapters['outcome_chapter_runs']
                    ),
                    'ratio',
                    'generation_runs.state',
                    (int)$chapters['outcome_chapter_runs']
                )
                : self::unavailableMetric(
                    'ratio',
                    'no_terminal_run_outcomes',
                    'No non-canceled terminal Run outcomes support a failure-rate denominator.',
                    'generation_runs.state',
                    0
                ),
            'whole_chapter_latency_p95' => $wholeLatency,
            'first_token_latency_p95' => self::unavailableMetric(
                'ms',
                'no_durable_first_token_timestamp',
                'Neither Run, Attempt, Event nor Chapter persistence records a first-token timestamp.',
                'missing_schema_evidence'
            ),
            'cache_hit_rate' => $cacheHit,
            'model_attempts_per_run' => $attemptsPerRun,
            'model_retry_attempt_rate' => $retryRate,
            'event_write_failure_rate' => self::unavailableMetric(
                'ratio',
                'no_durable_event_write_attempt_ledger',
                'A failed event transaction leaves no durable attempt/failure row, so generation_events cannot measure write failures.',
                'missing_schema_evidence'
            ),
        ];
    }

    /** @return array<string,mixed> */
    private static function availableMetric(
        int|float $value,
        string $unit,
        string $source,
        int $sampleCount,
        array $details = []
    ): array {
        return [
            'status' => 'available',
            'value' => $value,
            'unit' => $unit,
            'sample_count' => $sampleCount,
            'source' => $source,
            'details' => $details,
        ];
    }

    /** @return array<string,mixed> */
    private static function unavailableMetric(
        string $unit,
        string $reasonCode,
        string $reason,
        string $source,
        int $sampleCount = 0,
        array $details = []
    ): array {
        return [
            'status' => 'unavailable',
            'value' => null,
            'unit' => $unit,
            'sample_count' => $sampleCount,
            'source' => $source,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'details' => $details,
        ];
    }

    /** @return array<string,int|float|null>|null */
    private static function availableDatasetMetrics(mixed $dataset): ?array
    {
        return is_array($dataset)
            && ($dataset['available'] ?? false) === true
            && is_array($dataset['metrics'] ?? null)
                ? $dataset['metrics']
                : null;
    }

    /** @return array{sample_count:int,upper_ms:?int,over_largest_bucket:bool} */
    private static function bucketQuantile(array $metrics, float $quantile): array
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

    private static function ratio(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    private static function dimensionString(
        mixed $value,
        string $pattern,
        string $field
    ): string {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new RuntimeException("{$field} is not a safe observability dimension.");
        }
        return $value;
    }

    private static function nonNegativeInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
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
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !is_numeric($value)) {
            throw new RuntimeException("{$field} is not a numeric aggregate.");
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            throw new RuntimeException("{$field} is outside the supported aggregate range.");
        }
        return $number;
    }

    private static function assertReadOnlySql(string $sql, string $marker): void
    {
        if (preg_match(
            '/^\s*\/\* harness_observability:' . preg_quote($marker, '/')
                . ' \*\/\s*SELECT\b/is',
            $sql
        ) !== 1) {
            throw new LogicException('Rollout observability query is not a marked SELECT.');
        }
    }

    /** @return array{dataset:string,error:string,message:string} */
    private static function error(string $dataset, Throwable $exception): array
    {
        $message = preg_replace('/\s+/u', ' ', trim($exception->getMessage()));
        if (!is_string($message) || $message === '') {
            $message = 'rollout metrics dataset is unavailable';
        }
        if (strlen($message) > 240) {
            $message = substr($message, 0, 237) . '...';
        }
        return [
            'dataset' => $dataset,
            'error' => $exception::class,
            'message' => $message,
        ];
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
    private function dimensionParams(HarnessMetricsWindow $window): array
    {
        $params = $this->params($window);
        return array_merge($params, $params, $params);
    }

    private function predicates(
        HarnessMetricsWindow $window,
        string $timestamp,
        string $novel,
        string $runAlias = 'r'
    ): string {
        $parts = ["{$timestamp} >= ?", "{$timestamp} < ?"];
        if ($window->novelId !== null) {
            $parts[] = "{$novel} = ?";
        }
        if ($window->workflowName !== null) {
            $parts[] = "{$runAlias}.workflow_name = ?";
        }
        if ($window->workflowVersion !== null) {
            $parts[] = "{$runAlias}.workflow_version = ?";
        }
        return implode(' AND ', $parts);
    }

    private function attemptSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'r.created_at', 'r.novel_id');
        return "/* harness_observability:rollout_attempts */
SELECT COUNT(DISTINCT r.id) AS eligible_run_count,
       COUNT(DISTINCT CASE WHEN ga.id IS NOT NULL THEN r.id END) AS covered_run_count,
       COUNT(ga.id) AS total_attempts,
       COALESCE(SUM(ga.state IN ('completed','failed','canceled')
                    AND ga.completed_at IS NOT NULL),0) AS terminal_attempts,
       COUNT(ga.id)-COALESCE(SUM(ga.state IN ('completed','failed','canceled')
                    AND ga.completed_at IS NOT NULL),0) AS incomplete_attempts,
       COALESCE(SUM(ga.state='completed' AND ga.completed_at IS NOT NULL),0) AS completed_attempts,
       COALESCE(SUM(ga.state='failed' AND ga.completed_at IS NOT NULL),0) AS failed_attempts,
       COALESCE(SUM(ga.state='canceled' AND ga.completed_at IS NOT NULL),0) AS canceled_attempts,
       COALESCE(SUM(ga.attempt_no>1),0) AS retry_attempts,
       COALESCE(SUM(ga.state IN ('completed','failed','canceled')
                    AND ga.completed_at IS NOT NULL AND ga.cache_tokens>0),0)
           AS known_cache_token_attempts,
       COALESCE(SUM(ga.input_tokens),0) AS input_tokens,
       COALESCE(SUM(ga.output_tokens),0) AS output_tokens,
       COALESCE(SUM(ga.cache_tokens),0) AS cache_tokens,
       COALESCE(SUM(ga.latency_ms IS NOT NULL AND ga.completed_at IS NOT NULL),0) AS latency_samples,
       AVG(CASE WHEN ga.latency_ms IS NOT NULL AND ga.completed_at IS NOT NULL
                THEN ga.latency_ms END) AS latency_avg_ms,
       MAX(CASE WHEN ga.latency_ms IS NOT NULL AND ga.completed_at IS NOT NULL
                THEN ga.latency_ms END) AS latency_max_ms
FROM generation_runs r
LEFT JOIN generation_steps gs ON gs.run_id=r.id
LEFT JOIN generation_attempts ga ON ga.step_id=gs.id
WHERE r.run_type='chapter.generate'
  AND r.authority_mode='authority'
  AND r.state IN ('succeeded','aborted_after_commit','failed','canceled')
  AND {$where}";
    }

    private function chapterOutcomeSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'r.created_at', 'r.novel_id');
        return "/* harness_observability:rollout_chapters */
SELECT COUNT(*) AS total_chapter_runs,
       COALESCE(SUM(cr.run_state IN ('succeeded','aborted_after_commit','failed')),0)
           AS outcome_chapter_runs,
       COALESCE(SUM(cr.run_state IN ('succeeded','aborted_after_commit')),0) AS successful_chapter_runs,
       COALESCE(SUM(cr.run_state='failed'),0) AS failed_chapter_runs,
       COALESCE(SUM(cr.run_state IN ('succeeded','aborted_after_commit')
                    AND cr.started_at IS NOT NULL AND cr.completed_at>=cr.started_at),0)
           AS whole_chapter_latency_samples,
       COALESCE(SUM(cr.run_state IN ('succeeded','aborted_after_commit')
                    AND cr.commit_count>0),0) AS accepted_runs,
       COALESCE(SUM(cr.run_state IN ('succeeded','aborted_after_commit')
                    AND cr.commit_count>0
                    AND cr.commit_count=cr.evidenced_commit_count
                    AND cr.accepted_words>0),0) AS accepted_evidenced_runs,
       COALESCE(SUM(cr.run_state IN ('succeeded','aborted_after_commit')
                    AND cr.commit_count>0
                    AND (cr.commit_count<>cr.evidenced_commit_count
                         OR cr.accepted_words<=0)),0) AS accepted_missing_evidence_runs,
       COALESCE(SUM(cr.run_state IN ('succeeded','aborted_after_commit')
                    AND cr.commit_count=0),0) AS successful_without_commit_runs,
       COALESCE(SUM(CASE WHEN cr.run_state IN ('succeeded','aborted_after_commit')
                              AND cr.commit_count>0
                              AND cr.commit_count=cr.evidenced_commit_count
                         THEN cr.accepted_words ELSE 0 END),0) AS accepted_words
FROM (
    SELECT r.id AS run_id,
           MAX(r.state) AS run_state,
           MAX(r.started_at) AS started_at,
           MAX(r.completed_at) AS completed_at,
           COUNT(l.id) AS commit_count,
           COALESCE(SUM(CASE WHEN l.candidate_id IS NOT NULL
                                  AND cc.id=l.candidate_id
                                  AND cc.run_id=l.run_id
                                  AND cc.chapter_id=l.chapter_id
                                  AND cc.novel_id=r.novel_id
                                  AND BINARY cc.content_hash=BINARY l.draft_hash
                                  AND cc.lifecycle='committed'
                                  AND cc.gate_state='passed'
                                  AND cc.source<>'branch_edit'
                                  AND cc.words>0
                             THEN 1 ELSE 0 END),0) AS evidenced_commit_count,
           COALESCE(SUM(CASE WHEN l.candidate_id IS NOT NULL
                                  AND cc.id=l.candidate_id
                                  AND cc.run_id=l.run_id
                                  AND cc.chapter_id=l.chapter_id
                                  AND cc.novel_id=r.novel_id
                                  AND BINARY cc.content_hash=BINARY l.draft_hash
                                  AND cc.lifecycle='committed'
                                  AND cc.gate_state='passed'
                                  AND cc.source<>'branch_edit'
                                  AND cc.words>0
                             THEN cc.words ELSE 0 END),0) AS accepted_words
    FROM generation_runs r
    LEFT JOIN chapter_commit_ledger l ON l.run_id=r.id
    LEFT JOIN chapter_candidates cc ON cc.id=l.candidate_id
    WHERE r.run_type='chapter.generate'
      AND r.authority_mode='authority'
      AND {$where}
    GROUP BY r.id
) cr";
    }

    private function chapterP95Sql(HarnessMetricsWindow $window, int $offset): string
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Chapter p95 offset must be non-negative.');
        }
        $where = $this->predicates($window, 'r.created_at', 'r.novel_id');
        return "/* harness_observability:rollout_chapter_p95 */
SELECT CAST(TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000 AS UNSIGNED)
       AS latency_ms
FROM generation_runs r
WHERE r.run_type='chapter.generate'
  AND r.authority_mode='authority'
  AND r.state IN ('succeeded','aborted_after_commit')
  AND r.started_at IS NOT NULL
  AND r.completed_at IS NOT NULL
  AND r.completed_at>=r.started_at
  AND {$where}
ORDER BY latency_ms ASC, r.id ASC
LIMIT 1 OFFSET {$offset}";
    }

    private function dimensionSql(HarnessMetricsWindow $window): string
    {
        $where = $this->predicates($window, 'r.created_at', 'r.novel_id');
        $limit = self::DIMENSION_GROUP_LIMIT + 1;
        $minimum = self::MIN_DIMENSION_GROUP_RUNS;
        return "/* harness_observability:rollout_dimensions */
SELECT
  CASE WHEN r.workflow_version REGEXP '^[A-Za-z0-9][A-Za-z0-9_.-]{0,31}$'
       THEN r.workflow_version ELSE '__invalid__' END AS workflow_version_key,
  CASE WHEN r.prompt_manifest_hash REGEXP '^[a-f0-9]{64}$'
       THEN CONCAT('sha256:',LEFT(r.prompt_manifest_hash,16))
       ELSE '__missing_or_invalid__' END AS prompt_manifest_key,
  COALESCE(ms.model_key,'__none__') AS model_key,
  CASE
       WHEN JSON_TYPE(r.flag_snapshot_json) IS NULL
         OR JSON_TYPE(r.flag_snapshot_json)<>'OBJECT'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot'))<>'OBJECT'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel'))<>'OBJECT'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.schema'))<>'STRING'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre'))<>'STRING'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.schema')) IS NULL
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.schema'))
            <> 'harness.chapter_run.input_snapshot.v1' THEN 'unknown'
       WHEN TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(
            r.flag_snapshot_json,'$.input_snapshot.novel.genre')),''))='' THEN 'unknown'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%玄幻%' THEN 'xuanhuan'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%奇幻%' THEN 'qihuan'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%都市%' THEN 'dushi'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%言情%' THEN 'yanqing'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%武侠%'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%仙侠%' THEN 'wuxia'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%科幻%' THEN 'kehuan'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%悬疑%'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%推理%' THEN 'xuanyi'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%历史%' THEN 'lishi'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%游戏%' THEN 'youxi'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%体育%'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%竞技%' THEN 'tiyu'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%军事%'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%战争%' THEN 'junshi'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%灵异%' THEN 'lingyi'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%轻小说%' THEN 'qingxiaoshuo'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%二次元%'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.input_snapshot.novel.genre')) LIKE '%同人%' THEN 'erciyuan'
       ELSE 'other' END AS genre_key,
  CASE
       WHEN JSON_TYPE(r.flag_snapshot_json) IS NULL
         OR JSON_TYPE(r.flag_snapshot_json)<>'OBJECT'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.schema'))<>'STRING'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features'))<>'OBJECT'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.schema'))<>'STRING'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.configured_mode'))<>'STRING'
         OR JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.effective_mode'))<>'STRING'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.schema')) IS NULL
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.schema'))
            <> 'chapter_run.flags.v1'
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.schema')) IS NULL
         OR JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.schema'))
            <> 'harness.features.v1' THEN 'unknown'
       WHEN r.authority_mode='authority'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.configured_mode'))='canary'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.effective_mode'))='canary'
            AND JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps'))='INTEGER'
            AND JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket'))='INTEGER'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps'))
                REGEXP '^(0|[1-9][0-9]{0,4})$'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps')) AS UNSIGNED)<=10000
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket'))
                REGEXP '^(0|[1-9][0-9]{0,3})$'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket')) AS UNSIGNED)<10000
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket')) AS UNSIGNED)
                < CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps')) AS UNSIGNED)
            THEN 'canary_treatment'
       WHEN r.authority_mode='shadow'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.configured_mode'))='canary'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.effective_mode'))='shadow'
            AND JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps'))='INTEGER'
            AND JSON_TYPE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket'))='INTEGER'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps'))
                REGEXP '^(0|[1-9][0-9]{0,4})$'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps')) AS UNSIGNED)<=10000
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket'))
                REGEXP '^(0|[1-9][0-9]{0,3})$'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket')) AS UNSIGNED)<10000
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bucket')) AS UNSIGNED)
                >= CAST(JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.rollout_bps')) AS UNSIGNED)
            THEN 'canary_control'
       WHEN r.authority_mode='shadow'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.configured_mode'))='off'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.effective_mode'))='off'
            THEN 'off'
       WHEN r.authority_mode='shadow'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.configured_mode'))='shadow'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.effective_mode'))='shadow'
            THEN 'shadow'
       WHEN JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.configured_mode'))='on'
            AND JSON_UNQUOTE(JSON_EXTRACT(r.flag_snapshot_json,'$.features.effective_mode'))='on'
            THEN 'on'
       ELSE 'inconsistent' END AS rollout_cohort,
  COUNT(*) AS total_runs,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit','failed')),0) AS outcome_runs,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit')),0) AS successful_runs,
  COALESCE(SUM(r.state='failed'),0) AS failed_runs,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit','failed','canceled')),0)
      AS attempt_eligible_runs,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit','failed','canceled')
               AND ms.attempts>0),0) AS attempt_covered_runs,
  COALESCE(SUM(CASE WHEN r.state IN ('succeeded','aborted_after_commit','failed','canceled')
                    THEN ms.attempts ELSE 0 END),0) AS attempts,
  COALESCE(SUM(CASE WHEN r.state IN ('succeeded','aborted_after_commit','failed','canceled')
                    THEN ms.terminal_attempts ELSE 0 END),0) AS terminal_attempts,
  COALESCE(SUM(CASE WHEN r.state IN ('succeeded','aborted_after_commit','failed','canceled')
                    THEN ms.retry_attempts ELSE 0 END),0) AS retry_attempts,
  COALESCE(SUM(CASE WHEN r.state IN ('succeeded','aborted_after_commit','failed','canceled')
                    THEN ms.known_cache_token_attempts ELSE 0 END),0)
      AS known_cache_token_attempts,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit')
               AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at),0)
      AS latency_samples,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=30000),0) AS lat_le_30000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=60000),0) AS lat_le_60000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=120000),0) AS lat_le_120000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=300000),0) AS lat_le_300000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=600000),0) AS lat_le_600000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=900000),0) AS lat_le_900000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=1800000),0) AS lat_le_1800000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit') AND r.started_at IS NOT NULL AND r.completed_at>=r.started_at AND TIMESTAMPDIFF(MICROSECOND,r.started_at,r.completed_at)/1000<=3600000),0) AS lat_le_3600000,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit')
               AND ac.accepted_commits>0),0) AS accepted_runs,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit')
               AND ac.accepted_commits>0
               AND (ac.accepted_commits<>ac.evidenced_commits
                    OR ac.accepted_words<=0)),0) AS accepted_missing_evidence_runs,
  COALESCE(SUM(r.state IN ('succeeded','aborted_after_commit')
               AND (ac.accepted_commits IS NULL OR ac.accepted_commits=0)),0)
      AS successful_without_commit_runs,
  COALESCE(SUM(CASE WHEN r.state IN ('succeeded','aborted_after_commit')
                         AND ac.accepted_commits=ac.evidenced_commits
                         AND ac.accepted_words>0
                    THEN ac.accepted_words ELSE 0 END),0) AS accepted_words
FROM generation_runs r
LEFT JOIN (
    SELECT gs.run_id,
           COUNT(*) AS attempts,
           COALESCE(SUM(ga.state IN ('completed','failed','canceled')
                        AND ga.completed_at IS NOT NULL),0) AS terminal_attempts,
           COALESCE(SUM(ga.attempt_no>1),0) AS retry_attempts,
           COALESCE(SUM(ga.state IN ('completed','failed','canceled')
                        AND ga.completed_at IS NOT NULL AND ga.cache_tokens>0),0)
               AS known_cache_token_attempts,
           CASE
             WHEN SUM(ga.provider IS NULL OR ga.model IS NULL
                      OR ga.model_config_json IS NULL)>0 THEN '__unknown__'
             WHEN COUNT(DISTINCT SHA2(CONCAT(
                    LENGTH(ga.provider),':',ga.provider,
                    LENGTH(ga.model),':',ga.model,
                    LENGTH(CAST(ga.model_config_json AS CHAR)),':',
                    CAST(ga.model_config_json AS CHAR)),256))=1
             THEN CONCAT('sha256:',LEFT(MAX(SHA2(CONCAT(
                    LENGTH(ga.provider),':',ga.provider,
                    LENGTH(ga.model),':',ga.model,
                    LENGTH(CAST(ga.model_config_json AS CHAR)),':',
                    CAST(ga.model_config_json AS CHAR)),256)),16))
             ELSE '__multiple__'
           END AS model_key
    FROM generation_steps gs
    INNER JOIN generation_attempts ga ON ga.step_id=gs.id
    INNER JOIN generation_runs r2 ON r2.id=gs.run_id
    WHERE r2.run_type='chapter.generate'
      AND r2.authority_mode='authority'
      AND " . $this->predicates($window, 'r2.created_at', 'r2.novel_id', 'r2') . "
    GROUP BY gs.run_id
) ms ON ms.run_id=r.id
LEFT JOIN (
    SELECT l.run_id,
           COUNT(*) AS accepted_commits,
           COALESCE(SUM(CASE WHEN l.candidate_id IS NOT NULL
                                  AND cc.id=l.candidate_id
                                  AND cc.run_id=l.run_id
                                  AND cc.chapter_id=l.chapter_id
                                  AND cc.novel_id=r2.novel_id
                                  AND BINARY cc.content_hash=BINARY l.draft_hash
                                  AND cc.lifecycle='committed'
                                  AND cc.gate_state='passed'
                                  AND cc.source<>'branch_edit'
                                  AND cc.words>0
                             THEN 1 ELSE 0 END),0) AS evidenced_commits,
           COALESCE(SUM(CASE WHEN l.candidate_id IS NOT NULL
                                  AND cc.id=l.candidate_id
                                  AND cc.run_id=l.run_id
                                  AND cc.chapter_id=l.chapter_id
                                  AND cc.novel_id=r2.novel_id
                                  AND BINARY cc.content_hash=BINARY l.draft_hash
                                  AND cc.lifecycle='committed'
                                  AND cc.gate_state='passed'
                                  AND cc.source<>'branch_edit'
                                  AND cc.words>0
                             THEN cc.words ELSE 0 END),0) AS accepted_words
    FROM chapter_commit_ledger l
    LEFT JOIN chapter_candidates cc ON cc.id=l.candidate_id
    INNER JOIN generation_runs r2 ON r2.id=l.run_id
    WHERE r2.run_type='chapter.generate'
      AND r2.authority_mode='authority'
      AND " . $this->predicates($window, 'r2.created_at', 'r2.novel_id', 'r2') . "
    GROUP BY l.run_id
) ac ON ac.run_id=r.id
WHERE r.run_type='chapter.generate'
  AND r.authority_mode='authority'
  AND {$where}
GROUP BY workflow_version_key,prompt_manifest_key,model_key,genre_key,rollout_cohort
HAVING COUNT(*)>={$minimum}
ORDER BY total_runs DESC,workflow_version_key,prompt_manifest_key,model_key,genre_key,rollout_cohort
LIMIT {$limit}";
    }
}
