<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/DeterministicOfflineHardGate.php';
require_once dirname(__DIR__) . '/review/ProductionReviewFindingDetector.php';
require_once dirname(__DIR__, 2) . '/quality/CanonicalQualityGate.php';
require_once dirname(__DIR__, 2) . '/quality/WritePolicySnapshotFactory.php';

/**
 * Deterministic semantic fixtures routed through the production Canon gate.
 * Detection remains offline and synthetic; the final block/allow decision is
 * made by the same CanonicalQualityGate used at the production commit boundary.
 */
final class ProductionBoundOfflineHardGate implements OfflineHarnessHardGate
{
    private readonly DeterministicOfflineHardGate $detector;
    private readonly WritePolicySnapshot $policy;

    public function __construct()
    {
        $this->detector = new DeterministicOfflineHardGate();
        // Use the current pure production factory path. No mutable config or
        // database read is allowed in offline Eval, but the exact v3 policy
        // schema/rule versions must match newly admitted production Runs.
        $this->policy = WritePolicySnapshotFactory::fromResolved([
            'harness_mode' => HarnessControlPlane::MODE_ON,
            'fencing_enforced' => true,
            'canon_policy' => WritePolicySnapshot::CANON_POLICY_STRICT,
            'constraint_strict' => true,
            'constraint_profile_complete' => true,
            'constraint_enabled' => true,
            'constraint_banned_words' => [],
            'constraint_max_banned_word_usage' => 15,
            'constraint_max_same_conflict' => 1,
            'constraint_max_coincidences' => 5,
            'quality_check_enabled' => true,
            'block_short_chapter' => true,
            'rewrite_enabled' => false,
            'rewrite_limit' => 0,
            'target_words' => 2000,
            'min_words' => 1800,
            'max_words' => 2200,
            'emergency_epoch' => 0,
        ], new DateTimeImmutable('2026-08-23T00:00:00.000000Z'));
    }

    public function ruleVersion(): string
    {
        return 'hard-rules-1.0.0+'
            . ProductionReviewFindingDetector::VERSION . '+'
            . $this->policy->version() . '+'
            . $this->policy->qualityRuleVersion();
    }

    /**
     * Describe the exact offline/production boundary without implying that
     * synthetic Eval inputs exercised every production finding detector.
     *
     * @return array<string,mixed>
     */
    public function bindingProvenance(): array
    {
        $detectorHash = hash_file('sha256', __DIR__ . '/DeterministicOfflineHardGate.php');
        $productionDetectorHash = hash_file(
            'sha256',
            dirname(__DIR__) . '/review/ProductionReviewFindingDetector.php'
        );
        if (!is_string($detectorHash) || !is_string($productionDetectorHash)) {
            throw new RuntimeException('Offline finding detector source cannot be hashed.');
        }
        return [
            'finding_source' => 'synthetic_deterministic_offline_fixture_detector',
            'finding_detector_class' => DeterministicOfflineHardGate::class,
            'finding_detector_source_hash' => $detectorHash,
            'production_finding_detectors_executed' => true,
            'production_finding_detector_class' => ProductionReviewFindingDetector::class,
            'production_finding_detector_version' => ProductionReviewFindingDetector::VERSION,
            'production_finding_detector_source_hash' => $productionDetectorHash,
            'production_finding_detector_scope' => [
                'completion_marker_v1',
                'paired_delimiter_balance_v1',
            ],
            'production_post_write_validator_executed' => false,
            'production_database_quality_detectors_executed' => false,
            'production_detector_sources_manifest_bound' => true,
            'production_decision_gate_executed' => true,
            'production_policy_schema' => $this->policy->version(),
            'production_quality_rule_version' => $this->policy->qualityRuleVersion(),
            'production_policy_hash' => $this->policy->checksum(),
        ];
    }

    public function evaluate(HarnessEvalCase $case): HarnessHardDecision
    {
        $detected = $this->detector->evaluate($case);
        $productionContent = $case->input['production_content']
            ?? $case->input['content']
            ?? '';
        if (!is_string($productionContent)) {
            throw new InvalidArgumentException(
                'Eval production_content must be a string when provided.'
            );
        }
        $productionFindings = array_map(
            static fn (array $finding): HarnessHardFinding => new HarnessHardFinding(
                match ($finding['rule_id']) {
                    'content.truncated' => 'PRODUCTION_CONTENT_TRUNCATED',
                    'continuity.severe' => 'PRODUCTION_DELIMITER_IMBALANCE',
                    default => throw new RuntimeException(
                        'Production Review detector emitted an unmapped hard rule.'
                    ),
                },
                [
                    'production_rule_id' => $finding['rule_id'],
                    'production_evidence_hash' => hash(
                        'sha256',
                        HarnessPersistenceValue::canonicalJson($finding['evidence'])
                    ),
                ]
            ),
            ProductionReviewFindingDetector::detect($productionContent)
        );
        $combined = new HarnessHardDecision(array_merge(
            $detected->findings,
            $productionFindings
        ));
        if (!$combined->blocked()) {
            return $combined;
        }
        $hardFindings = array_map(
            static fn (HarnessHardFinding $finding): array => [
                'rule_id' => self::productionRule($finding->code),
                'message' => 'Offline fixture detected ' . $finding->code . '.',
                'evidence' => [
                    'offline_code' => $finding->code,
                    'offline_evidence_hash' => hash(
                        'sha256',
                        HarnessPersistenceValue::canonicalJson($finding->evidence)
                    ),
                ],
            ],
            $combined->findings
        );
        $content = str_repeat('正', 2000);
        $decision = CanonicalQualityGate::evaluate(
            $this->policy,
            $content,
            2000,
            $hardFindings,
            evaluatedAt: '2026-08-23T00:00:00Z'
        );
        return $decision->shouldQuarantine()
            ? $combined
            : new HarnessHardDecision([]);
    }

    private static function productionRule(string $offlineCode): string
    {
        return match ($offlineCode) {
            'OUTLINE_REQUIRED_POINT_MISSING' => 'outline.severe_drift',
            'REQUIRED_CONTEXT_MISSING' => 'continuity.severe',
            'TRUNCATED_DRAFT', 'INCOMPLETE_MODEL_STREAM' => 'content.truncated',
            'CANON_FACT_CONFLICT', 'POV_CONTRACT_CONFLICT' => 'constraint.p0',
            'PRODUCTION_CONTENT_TRUNCATED' => 'content.truncated',
            'PRODUCTION_DELIMITER_IMBALANCE' => 'continuity.severe',
            default => throw new InvalidArgumentException(
                "Offline hard code {$offlineCode} has no production Canon rule binding."
            ),
        };
    }
}
