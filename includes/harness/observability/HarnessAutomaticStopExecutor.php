<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/HarnessEmergencyStop.php';
require_once __DIR__ . '/HarnessRelativeBaseline.php';

/**
 * The sole bridge from a release-readiness report to an emergency epoch bump.
 *
 * It re-verifies the zero-tolerance checks instead of trusting summary flags.
 * Missing/partial metrics and dry runs are deliberately incapable of touching
 * the epoch store, including when a partial report contains a visible breach.
 */
final class HarnessAutomaticStopExecutor
{
    /** @var list<string> */
    private const ELIGIBLE_CHECKS = [
        'duplicate_canonical_commits',
        'canonical_commit_conflicts',
        'quarantined_candidate_is_canon',
        'critical_integrity_signals',
    ];

    /** @var list<string> */
    private const RELATIVE_ELIGIBLE_CHECKS = [
        'failure_rate_relative',
        'whole_chapter_p95_relative_sustained',
        'postprocess_oldest_backlog_sustained_growth',
    ];

    private readonly ?HarnessEmergencyEpochStore $epochStore;
    private readonly ?HarnessEmergencyIncidentStore $incidentStore;

    public function __construct(
        ?HarnessEmergencyEpochStore $epochStore = null,
        ?HarnessEmergencyIncidentStore $incidentStore = null
    ) {
        if ($epochStore !== null && $incidentStore !== null) {
            throw new InvalidArgumentException(
                'Automatic stop accepts either a legacy epoch store or an incident store, not both.'
            );
        }
        $this->epochStore = $epochStore;
        $this->incidentStore = $incidentStore
            ?? ($epochStore === null ? new DbHarnessEmergencyEpochStore() : null);
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    public function execute(array $report, bool $apply): array
    {
        $schema = $report['schema'] ?? null;
        $relative = $schema === 'harness.relative_baseline_gate.v1';
        $reliable = ($schema === 'harness.release_readiness.v1' || $relative)
            && ($report['metrics_reliable'] ?? false) === true;
        // Relative reports authorize only a proved stop. Re-arming always uses
        // the fully verified absolute readiness report after the CLI's
        // composite release gate passes.
        $canClearIncident = !$relative;
        $breaches = [];
        $scopeFingerprint = null;
        if ($reliable) {
            try {
                // A healthy report is authority to re-arm an incident, so its
                // zero-tolerance section must be just as strongly verified as
                // a breach report.  Partial or internally inconsistent checks
                // fail closed and can neither bump nor clear the latch.
                $breaches = $relative
                    ? $this->verifiedRelativeBreaches($report)
                    : $this->verifiedBreaches($report);
                $scopeFingerprint = self::scopeFingerprint($report);
            } catch (Throwable) {
                $reliable = false;
                $breaches = [];
            }
        }

        $result = [
            'schema' => 'harness.automatic_stop.action.v1',
            'mode' => $apply ? 'apply' : 'dry_run',
            'applied' => false,
            'would_apply' => $reliable && $breaches !== [],
            'reason' => null,
            'verified_breaches' => $breaches,
            'epoch' => null,
            'incident_scope_fingerprint' => $scopeFingerprint,
            'deduplicated' => false,
            'disposition' => null,
        ];

        if (!$reliable) {
            $result['reason'] = 'metrics_unreliable';
            return $result;
        }
        if ($breaches === []) {
            if ($apply && $canClearIncident && $this->incidentStore !== null
                && is_string($scopeFingerprint)) {
                $result['disposition'] = HarnessEmergencyStop::clearIncident(
                    $scopeFingerprint,
                    $this->incidentStore
                );
            }
            $result['reason'] = $canClearIncident
                ? 'no_verified_stop_breach'
                : 'relative_report_cannot_rearm';
            return $result;
        }
        if (!$apply) {
            $result['reason'] = 'dry_run_would_stop';
            return $result;
        }

        if ($this->incidentStore !== null && is_string($scopeFingerprint)) {
            $disposition = HarnessEmergencyStop::bumpOnce(
                $scopeFingerprint,
                $breaches,
                $this->incidentStore
            );
            $result['epoch'] = [
                'previous_epoch' => $disposition['previous_epoch'],
                'current_epoch' => $disposition['current_epoch'],
            ];
            $result['applied'] = $disposition['applied'];
            $result['deduplicated'] = $disposition['deduplicated'];
            $result['would_apply'] = !$disposition['deduplicated'];
            $result['disposition'] = $disposition;
            $result['reason'] = $disposition['deduplicated']
                ? 'active_incident_already_stopped'
                : ($relative
                    ? 'verified_relative_breach'
                    : 'verified_integrity_breach');
            return $result;
        }

        $result['epoch'] = HarnessEmergencyStop::bump($this->epochStore);
        $result['applied'] = true;
        $result['reason'] = $relative
            ? 'verified_relative_breach'
            : 'verified_integrity_breach';
        return $result;
    }

    /** @param array<string,mixed> $report @return list<string> */
    private function verifiedRelativeBreaches(array $report): array
    {
        foreach (['missing_evidence', 'unavailable_checks'] as $field) {
            if (!is_array($report[$field] ?? null)
                || !array_is_list($report[$field])) {
                throw new InvalidArgumentException(
                    'Relative automatic stop evidence lists are malformed.'
                );
            }
        }
        $policy = $this->relativePolicy($report['policy'] ?? null);
        $checks = $report['checks'] ?? null;
        if (!is_array($checks) || !array_is_list($checks)) {
            throw new InvalidArgumentException(
                'Relative automatic stop checks must be a complete list.'
            );
        }

        /** @var array<string,string> $states */
        $states = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                throw new InvalidArgumentException(
                    'Relative automatic stop report contains a malformed check.'
                );
            }
            $id = $check['id'] ?? null;
            if (!is_string($id)
                || !in_array($id, self::RELATIVE_ELIGIBLE_CHECKS, true)) {
                throw new InvalidArgumentException(
                    'Relative automatic stop report contains an unknown check.'
                );
            }
            $status = $check['status'] ?? null;
            if (isset($states[$id])
                || !in_array($status, ['pass', 'fail', 'unavailable'], true)
                || ($check['automatic_stop_candidate'] ?? null) !== true) {
                throw new InvalidArgumentException(
                    'Relative automatic stop report contains an invalid eligible check.'
                );
            }
            if ($status === 'unavailable') {
                if (($check['evidence_complete'] ?? null) !== false
                    || ($check['automatic_stop_eligible'] ?? null) !== false
                    || ($check['observed'] ?? null) !== null
                    || ($check['operator'] ?? null) !== null
                    || ($check['threshold'] ?? null) !== null) {
                    throw new InvalidArgumentException(
                        'Relative unavailable check contains contradictory evidence.'
                    );
                }
            } elseif (($check['evidence_complete'] ?? null) !== true
                || ($check['automatic_stop_eligible'] ?? null) !== ($status === 'fail')
                || ($check['operator'] ?? null) !== '>'
                || !is_numeric($check['threshold'] ?? null)
                || !is_finite((float)$check['threshold'])
                || (float)$check['threshold'] < 0
                || !is_array($check['observed'] ?? null)) {
                throw new InvalidArgumentException(
                    'Relative automatic stop report contains an invalid resolved check.'
                );
            }
            if ($status === 'fail') {
                $this->verifyRelativeProof($id, $check, $policy);
            }
            $states[$id] = $status;
        }

        $failed = [];
        $unavailable = [];
        $verified = [];
        foreach (self::RELATIVE_ELIGIBLE_CHECKS as $id) {
            if (!isset($states[$id])) {
                throw new InvalidArgumentException(
                    'Relative automatic stop report is missing an eligible check.'
                );
            }
            if ($states[$id] === 'fail') {
                $failed[] = $id;
                $verified[] = $id;
            } elseif ($states[$id] === 'unavailable') {
                $unavailable[] = $id;
            }
        }

        if (!is_array($report['automatic_stop_breaches'] ?? null)
            || !array_is_list($report['automatic_stop_breaches'])
            || $report['automatic_stop_breaches'] !== $verified
            || !is_array($report['failed_checks'] ?? null)
            || !array_is_list($report['failed_checks'])
            || $report['failed_checks'] !== $failed) {
            throw new InvalidArgumentException(
                'Relative automatic stop summary contradicts its verified checks.'
            );
        }
        $reportedUnavailable = $report['unavailable_checks'];
        foreach ($unavailable as $id) {
            if (!in_array($id, $reportedUnavailable, true)) {
                throw new InvalidArgumentException(
                    'Relative automatic stop unavailable summary is incomplete.'
                );
            }
        }
        $releaseComplete = ($report['release_evidence_complete'] ?? null) === true;
        $expectedDecision = $failed !== []
            ? 'stop'
            : (($unavailable !== [] || !$releaseComplete) ? 'hold' : 'promote');
        if (($report['automatic_stop_required'] ?? null) !== ($verified !== [])
            || ($report['expansion_stop_required'] ?? null) !== ($failed !== [])
            || ($report['promotion_allowed'] ?? null)
                !== ($failed === [] && $unavailable === [] && $releaseComplete)
            || ($report['rollout_decision'] ?? null) !== $expectedDecision) {
            throw new InvalidArgumentException(
                'Relative automatic stop decision contradicts its verified checks.'
            );
        }
        return $verified;
    }

    /**
     * @param array<string,mixed> $check
     * @param array<string,int|float> $policy
     */
    private function verifyRelativeProof(string $id, array $check, array $policy): void
    {
        $observed = $check['observed'];
        $threshold = (float)$check['threshold'];
        $requireNumber = static function (array $values, string $field): float {
            $value = $values[$field] ?? null;
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
                throw new InvalidArgumentException(
                    "Relative automatic stop proof field {$field} is invalid."
                );
            }
            return (float)$value;
        };
        $requireCount = static function (array $values, string $field): int {
            $value = $values[$field] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException(
                    "Relative automatic stop proof field {$field} is invalid."
                );
            }
            return $value;
        };
        $same = static fn (float $left, float $right): bool =>
            abs($left - $right) <= max(1.0, abs($right)) * 1.0e-12;

        if ($id === 'failure_rate_relative') {
            $baseline = $requireNumber($observed, 'baseline');
            $candidate = $requireNumber($observed, 'candidate');
            $expected = $baseline + (float)$policy['failure_rate_delta'];
            if ($baseline > 1 || $candidate > 1 || !$same($threshold, $expected)
                || $candidate <= $threshold
                || $requireCount($observed, 'baseline_samples')
                    < (int)$policy['min_failure_samples']
                || $requireCount($observed, 'candidate_samples')
                    < (int)$policy['min_failure_samples']) {
                throw new InvalidArgumentException('Failure-rate relative proof is incomplete.');
            }
            return;
        }
        if ($id === 'whole_chapter_p95_relative_sustained') {
            $baseline = $requireNumber($observed, 'baseline_p95_ms');
            $latest = $requireNumber($observed, 'latest_p95_ms');
            $expected = $baseline * (float)$policy['p95_multiplier'];
            $requiredWindows = max(
                (int)$policy['p95_consecutive_windows'],
                (int)ceil(
                    (int)$policy['p95_sustain_seconds']
                        / (int)$policy['series_window_seconds']
                )
            );
            if ($baseline <= 0 || !$same($threshold, $expected) || $latest <= $threshold
                || $requireCount($observed, 'baseline_samples')
                    < (int)$policy['min_latency_samples']
                || $requireCount($observed, 'candidate_samples')
                    < (int)$policy['min_latency_samples']
                || $requireCount($observed, 'sustained_seconds')
                    < (int)$policy['p95_sustain_seconds']
                || $requireCount($observed, 'consecutive_windows') < $requiredWindows) {
                throw new InvalidArgumentException('Sustained p95 relative proof is incomplete.');
            }
            return;
        }
        if ($id === 'postprocess_oldest_backlog_sustained_growth') {
            $age = $requireCount($observed, 'oldest_backlog_seconds');
            if (!$same($threshold, (float)$policy['backlog_age_seconds'])
                || $age <= $threshold
                || $requireCount($observed, 'due_backlog') < 1
                || ($observed['strictly_growing'] ?? null) !== true
                || $requireCount($observed, 'consecutive_windows')
                    < (int)$policy['backlog_consecutive_windows']) {
                throw new InvalidArgumentException('Sustained backlog proof is incomplete.');
            }
            return;
        }
        throw new InvalidArgumentException('Relative automatic stop proof is unknown.');
    }

    /** @return array<string,int|float> */
    private function relativePolicy(mixed $policy): array
    {
        $defaults = (new HarnessRelativeBaselinePolicy())->toArray();
        if (!is_array($policy) || array_keys($policy) !== array_keys($defaults)) {
            throw new InvalidArgumentException('Relative automatic stop policy is malformed.');
        }
        foreach ($defaults as $field => $unused) {
            $value = $policy[$field] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Relative automatic stop policy is malformed.');
            }
        }
        $validated = new HarnessRelativeBaselinePolicy(
            failureRateDelta: (float)$policy['failure_rate_delta'],
            p95Multiplier: (float)$policy['p95_multiplier'],
            p95SustainSeconds: (int)$policy['p95_sustain_seconds'],
            p95ConsecutiveWindows: (int)$policy['p95_consecutive_windows'],
            backlogConsecutiveWindows: (int)$policy['backlog_consecutive_windows'],
            backlogAgeSeconds: (int)$policy['backlog_age_seconds'],
            seriesWindowSeconds: (int)$policy['series_window_seconds'],
            minFailureSamples: (int)$policy['min_failure_samples'],
            minLatencySamples: (int)$policy['min_latency_samples'],
            maxSeriesWindows: (int)$policy['max_series_windows'],
            maxCollectionLagSeconds: (int)$policy['max_collection_lag_seconds']
        );
        return $validated->toArray();
    }

    /** @param array<string,mixed> $report @return list<string> */
    private function verifiedBreaches(array $report): array
    {
        $checks = $report['checks'] ?? null;
        if (!is_array($checks) || !array_is_list($checks)) {
            throw new InvalidArgumentException(
                'Automatic stop report checks must be a complete list.'
            );
        }
        /** @var array<string,string> $states */
        $states = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                throw new InvalidArgumentException(
                    'Automatic stop report contains a malformed check.'
                );
            }
            $id = $check['id'] ?? null;
            if (!is_string($id) || !in_array($id, self::ELIGIBLE_CHECKS, true)) {
                continue;
            }
            if (isset($states[$id])
                || ($check['automatic_stop_eligible'] ?? null) !== true
                || ($check['operator'] ?? null) !== '<='
                || !is_numeric($check['observed'] ?? null)
                || !is_numeric($check['threshold'] ?? null)
                || !is_finite((float)$check['observed'])
                || !is_finite((float)$check['threshold'])
                || (float)$check['threshold'] !== 0.0
                || (float)$check['observed'] < 0.0) {
                throw new InvalidArgumentException(
                    'Automatic stop report contains an invalid eligible check.'
                );
            }
            $expectedStatus = (float)$check['observed'] > 0.0 ? 'fail' : 'pass';
            if (($check['status'] ?? null) !== $expectedStatus) {
                throw new InvalidArgumentException(
                    'Automatic stop report check status contradicts its observed value.'
                );
            }
            $states[$id] = $expectedStatus;
        }

        $verified = [];
        foreach (self::ELIGIBLE_CHECKS as $id) {
            if (!isset($states[$id])) {
                throw new InvalidArgumentException(
                    'Automatic stop report is missing an eligible integrity check.'
                );
            }
            if ($states[$id] === 'fail') {
                $verified[] = $id;
            }
        }

        $reported = $report['verified_stop_breaches'] ?? null;
        if (!is_array($reported)
            || !array_is_list($reported)
            || $reported !== $verified
            || ($report['stop_required'] ?? null) !== ($verified !== [])) {
            throw new InvalidArgumentException(
                'Automatic stop report summary contradicts its verified checks.'
            );
        }
        return $verified;
    }

    /** @param array<string,mixed> $report */
    private static function scopeFingerprint(array $report): string
    {
        $scope = ($report['schema'] ?? null) === 'harness.relative_baseline_gate.v1'
            ? ($report['scope'] ?? null)
            : ($report['window']['scope'] ?? null);
        if (!is_array($scope)
            || array_keys($scope) !== [
                'novel_id', 'workflow_name', 'workflow_version',
            ]) {
            throw new InvalidArgumentException(
                'Automatic stop report scope is incomplete.'
            );
        }
        $novelId = $scope['novel_id'];
        $workflowName = $scope['workflow_name'];
        $workflowVersion = $scope['workflow_version'];
        if (($novelId !== null && (!is_int($novelId) || $novelId <= 0))
            || ($workflowName !== null && !is_string($workflowName))
            || ($workflowVersion !== null && !is_string($workflowVersion))) {
            throw new InvalidArgumentException(
                'Automatic stop report scope has invalid coordinates.'
            );
        }
        return HarnessEmergencyStop::scopeFingerprint(
            $novelId,
            $workflowName,
            $workflowVersion
        );
    }
}
