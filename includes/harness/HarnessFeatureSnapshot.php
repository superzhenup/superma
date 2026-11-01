<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessControlPlane.php';
require_once __DIR__ . '/persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/persistence/HarnessSensitiveDataGuard.php';

/** Immutable, content-addressed feature admission captured at a Run boundary. */
final readonly class HarnessFeatureSnapshot
{
    public const SCHEMA = 'harness.features.v1';

    private function __construct(
        public string $configuredMode,
        public string $effectiveMode,
        public bool $fencingEnforced,
        public int $rolloutBps,
        public ?int $rolloutBucket,
        public bool $pipelineV2,
        public bool $contextV2,
        public bool $toolLoop,
        public bool $parallelReview,
        public bool $branchCandidates,
        public bool $candidateUi,
        public bool $trace,
        public string $eventSource,
        public int $artifactRetentionDays,
        public string $checksum
    ) {
    }

    public static function captureForNovel(int $novelId): self
    {
        if ($novelId <= 0) {
            throw new InvalidArgumentException('Feature capture requires a positive novel ID.');
        }
        $control = HarnessControlPlane::captureForNovel($novelId);
        $effective = (string)$control['harness_mode'];
        $authority = HarnessControlPlane::usesAuthority($effective);
        $enabled = $effective !== HarnessControlPlane::MODE_OFF;
        $pipelineV2 = $authority
            && self::readBool($novelId, 'ws_harness_pipeline_v2_enabled');
        $payload = [
            'schema' => self::SCHEMA,
            'configured_mode' => (string)$control['configured_mode'],
            'effective_mode' => $effective,
            'fencing_enforced' => (bool)$control['fencing_enforced'],
            'rollout_bps' => (int)$control['rollout_bps'],
            'rollout_bucket' => $control['rollout_bucket'],
            'pipeline_v2' => $pipelineV2,
            'context_v2' => $enabled && self::readBool($novelId, 'ws_harness_context_v2_enabled'),
            'tool_loop' => $pipelineV2 && self::readBool($novelId, 'ws_harness_tool_loop_enabled'),
            'parallel_review' => $pipelineV2 && self::readBool($novelId, 'ws_harness_parallel_review_enabled'),
            'branch_candidates' => $pipelineV2 && self::readBool($novelId, 'ws_harness_branch_candidates_enabled'),
            'candidate_ui' => $enabled && self::readBool($novelId, 'ws_harness_candidate_ui_enabled'),
            'trace' => $enabled && self::readBool($novelId, 'ws_harness_trace_enabled'),
            'event_source' => self::readEventSource(),
            'artifact_retention_days' => self::readRetentionDays(),
        ];
        return self::fromPayload($payload);
    }

    public static function disabled(int $novelId): self
    {
        $bucket = $novelId > 0 ? HarnessControlPlane::stableRolloutBucket($novelId) : null;
        return self::fromPayload([
            'schema' => self::SCHEMA,
            'configured_mode' => HarnessControlPlane::MODE_OFF,
            'effective_mode' => HarnessControlPlane::MODE_OFF,
            'fencing_enforced' => false,
            'rollout_bps' => 0,
            'rollout_bucket' => $bucket,
            'pipeline_v2' => false,
            'context_v2' => false,
            'tool_loop' => false,
            'parallel_review' => false,
            'branch_candidates' => false,
            'candidate_ui' => false,
            'trace' => false,
            'event_source' => 'legacy',
            'artifact_retention_days' => 90,
        ]);
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $checksum = $value['checksum'] ?? null;
        if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new InvalidArgumentException('Feature snapshot checksum is invalid.');
        }
        unset($value['checksum']);
        $snapshot = self::fromPayload($value);
        if (!hash_equals($snapshot->checksum, $checksum)) {
            throw new InvalidArgumentException('Feature snapshot checksum mismatch.');
        }
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload() + ['checksum' => $this->checksum];
    }

    public function enabled(string $feature): bool
    {
        return match ($feature) {
            'pipeline_v2' => $this->pipelineV2,
            'context_v2' => $this->contextV2,
            'tool_loop' => $this->toolLoop,
            'parallel_review' => $this->parallelReview,
            'branch_candidates' => $this->branchCandidates,
            'candidate_ui' => $this->candidateUi,
            'trace' => $this->trace,
            default => throw new InvalidArgumentException('Unknown Harness feature.'),
        };
    }

    /** @param array<string,mixed> $payload */
    private static function fromPayload(array $payload): self
    {
        $expected = [
            'schema', 'configured_mode', 'effective_mode', 'fencing_enforced',
            'rollout_bps', 'rollout_bucket', 'pipeline_v2', 'context_v2',
            'tool_loop', 'parallel_review', 'branch_candidates', 'candidate_ui',
            'trace', 'event_source', 'artifact_retention_days',
        ];
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected || ($payload['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Feature snapshot fields are invalid.');
        }
        foreach (['configured_mode', 'effective_mode'] as $field) {
            if (HarnessControlPlane::normalizeMode($payload[$field] ?? null) !== $payload[$field]) {
                throw new InvalidArgumentException("Feature snapshot {$field} is invalid.");
            }
        }
        foreach ([
            'fencing_enforced', 'pipeline_v2', 'context_v2', 'tool_loop',
            'parallel_review', 'branch_candidates', 'candidate_ui', 'trace',
        ] as $field) {
            if (!is_bool($payload[$field] ?? null)) {
                throw new InvalidArgumentException("Feature snapshot {$field} must be boolean.");
            }
        }
        $bps = HarnessControlPlane::normalizeRolloutBps($payload['rollout_bps'] ?? null);
        if ($bps !== $payload['rollout_bps']) {
            throw new InvalidArgumentException('Feature snapshot rollout_bps is invalid.');
        }
        $bucket = $payload['rollout_bucket'];
        if ($bucket !== null && (!is_int($bucket) || $bucket < 0 || $bucket > 9999)) {
            throw new InvalidArgumentException('Feature snapshot rollout_bucket is invalid.');
        }
        if (!in_array($payload['event_source'] ?? null, ['legacy', 'dual', 'events'], true)) {
            throw new InvalidArgumentException('Feature snapshot event source is invalid.');
        }
        $days = $payload['artifact_retention_days'] ?? null;
        if (!is_int($days) || $days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Feature snapshot Retention days is invalid.');
        }
        if (!HarnessControlPlane::usesAuthority($payload['effective_mode'])) {
            foreach (['pipeline_v2', 'tool_loop', 'parallel_review', 'branch_candidates'] as $field) {
                if ($payload[$field]) {
                    throw new InvalidArgumentException(
                        "Feature {$field} requires an authority cohort."
                    );
                }
            }
        }
        if (!$payload['pipeline_v2']) {
            foreach (['tool_loop', 'parallel_review', 'branch_candidates'] as $field) {
                if ($payload[$field]) {
                    throw new InvalidArgumentException(
                        "Feature {$field} requires the frozen pipeline_v2 feature."
                    );
                }
            }
        }
        HarnessControlPlane::assertAuthorityIsFenced(
            $payload['effective_mode'],
            $payload['fencing_enforced']
        );
        $checksum = hash('sha256', HarnessPersistenceValue::canonicalJson($payload));
        return new self(
            $payload['configured_mode'],
            $payload['effective_mode'],
            $payload['fencing_enforced'],
            $bps,
            $bucket,
            $payload['pipeline_v2'],
            $payload['context_v2'],
            $payload['tool_loop'],
            $payload['parallel_review'],
            $payload['branch_candidates'],
            $payload['candidate_ui'],
            $payload['trace'],
            $payload['event_source'],
            $days,
            $checksum
        );
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'schema' => self::SCHEMA,
            'configured_mode' => $this->configuredMode,
            'effective_mode' => $this->effectiveMode,
            'fencing_enforced' => $this->fencingEnforced,
            'rollout_bps' => $this->rolloutBps,
            'rollout_bucket' => $this->rolloutBucket,
            'pipeline_v2' => $this->pipelineV2,
            'context_v2' => $this->contextV2,
            'tool_loop' => $this->toolLoop,
            'parallel_review' => $this->parallelReview,
            'branch_candidates' => $this->branchCandidates,
            'candidate_ui' => $this->candidateUi,
            'trace' => $this->trace,
            'event_source' => $this->eventSource,
            'artifact_retention_days' => $this->artifactRetentionDays,
        ];
    }

    private static function readBool(int $novelId, string $key): bool
    {
        if (!class_exists('ConfigCenter', false)) return false;
        try {
            return HarnessControlPlane::normalizeBoolean(
                ConfigCenter::getNovel($novelId, $key, false, 'bool'),
                false
            );
        } catch (Throwable) {
            return false;
        }
    }

    private static function readEventSource(): string
    {
        if (!class_exists('ConfigCenter', false)) return 'legacy';
        try {
            $value = ConfigCenter::get('ws_harness_event_source', 'legacy', 'string');
            return is_string($value) && in_array($value, ['legacy', 'dual', 'events'], true)
                ? $value
                : 'legacy';
        } catch (Throwable) {
            return 'legacy';
        }
    }

    private static function readRetentionDays(): int
    {
        if (!class_exists('ConfigCenter', false)) return 90;
        try {
            $value = ConfigCenter::get('ws_harness_artifact_retention_days', 90, 'int');
            return is_int($value) && $value >= 1 && $value <= 3650 ? $value : 90;
        } catch (Throwable) {
            return 90;
        }
    }
}
