<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PairwiseProtocol.php';

/**
 * Applies the release policy to validated Pairwise v2 and human evidence.
 *
 * This class never manufactures calibration values: absent human annotations
 * remain explicit nulls and make the release decision fail closed.
 */
final class PairwiseReleaseGate
{
    public const MIN_NET_WIN_RATE = 0.10;
    public const MAX_P95_LATENCY_INCREASE = 0.20;
    public const MIN_WEIGHTED_KAPPA = 0.60;
    public const MIN_JUDGE_HUMAN_AGREEMENT = 0.70;

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed>|null $annotations
     * @return array<string,mixed>
     */
    public static function summarize(
        PairwiseEvidenceBundle $pairwise,
        int $seed,
        ?array $annotations
    ): array {
        HarnessPersistenceValue::nonNegativeInt($seed, 'pairwise.release_seed');
        $overall = PairwiseEvalMetrics::summarize($pairwise->outcomes(), $seed);
        $operations = $pairwise->operationalMetrics();
        foreach (['chapter_p95_latency_increase'] as $metric) {
            if (!is_float($operations[$metric]) && !is_int($operations[$metric])) {
                throw new InvalidArgumentException("Pairwise {$metric} is not numeric.");
            }
            if (!is_finite((float)$operations[$metric])) {
                throw new InvalidArgumentException(
                    "Pairwise {$metric} cannot be measured from a zero baseline."
                );
            }
        }

        $byCategory = [];
        $categoryByCase = [];
        foreach ($pairwise->trials as $trial) {
            $category = (string)$trial['category'];
            $caseId = (string)$trial['case_id'];
            if (isset($categoryByCase[$caseId]) && $categoryByCase[$caseId] !== $category) {
                throw new InvalidArgumentException(
                    "Pairwise case {$caseId} cannot span multiple categories."
                );
            }
            $categoryByCase[$caseId] = $category;
            $byCategory[$category][] = (string)$trial['outcome'];
        }
        ksort($byCategory, SORT_STRING);
        $categories = [];
        $significantRegressions = [];
        foreach ($byCategory as $category => $outcomes) {
            // Derive a stable category seed without depending on input order.
            $categorySeed = self::categorySeed($seed, $category);
            $categorySummary = PairwiseEvalMetrics::summarize($outcomes, $categorySeed);
            $categorySummary['significant_regression'] =
                $categorySummary['ci95']['upper'] < 0.0;
            if ($categorySummary['significant_regression']) {
                $significantRegressions[] = $category;
            }
            $categories[$category] = $categorySummary;
        }

        if ($annotations === null) {
            $calibration = [
                'status' => 'missing',
                'annotated_pairs' => 0,
                'minimum_pairs' => max(30, (int)ceil(count($pairwise->trials) * 0.20)),
                'weighted_kappa' => null,
                'judge_human_agreement' => null,
            ];
        } else {
            $calibration = ['status' => 'verified']
                + HumanAnnotationEvidence::summarize($annotations, $pairwise);
        }

        $gates = [
            'net_win_rate' => $overall['net_win_rate'] >= self::MIN_NET_WIN_RATE,
            'overall_ci95' => $overall['ci95']['lower'] >= 0.0,
            'category_regression' => $significantRegressions === [],
            'chapter_p95_latency' =>
                $operations['chapter_p95_latency_increase'] <= self::MAX_P95_LATENCY_INCREASE,
            'human_calibration_present' => $annotations !== null,
            'weighted_kappa' => is_float($calibration['weighted_kappa'])
                && $calibration['weighted_kappa'] >= self::MIN_WEIGHTED_KAPPA,
            'judge_human_agreement' => is_float($calibration['judge_human_agreement'])
                && $calibration['judge_human_agreement'] >= self::MIN_JUDGE_HUMAN_AGREEMENT,
        ];

        return $overall + $operations + [
            'categories' => $categories,
            'significant_regressions' => $significantRegressions,
            'human_calibration' => $calibration,
            'weighted_kappa' => $calibration['weighted_kappa'],
            'judge_human_agreement' => $calibration['judge_human_agreement'],
            'thresholds' => [
                'min_net_win_rate' => self::MIN_NET_WIN_RATE,
                'min_overall_ci95_lower' => 0.0,
                'max_chapter_p95_latency_increase' => self::MAX_P95_LATENCY_INCREASE,
                'min_weighted_kappa' => self::MIN_WEIGHTED_KAPPA,
                'min_judge_human_agreement' => self::MIN_JUDGE_HUMAN_AGREEMENT,
            ],
            'gates' => $gates,
            'release_pass' => !in_array(false, $gates, true),
        ];
    }

    private static function categorySeed(int $seed, string $category): int
    {
        $digest = hash('sha256', $seed . "\0" . $category);
        return (int)(hexdec(substr($digest, 0, 7)) & 0x7fffffff);
    }
}
