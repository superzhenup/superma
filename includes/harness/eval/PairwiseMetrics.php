<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/EvalContracts.php';

final class PairwiseEvalMetrics
{
    /**
     * @param list<string> $outcomes candidate|baseline|tie
     * @return array<string,mixed>
     */
    public static function summarize(array $outcomes, int $seed = 1, int $samples = 2000): array
    {
        if ($outcomes === []) {
            throw new InvalidArgumentException('Pairwise outcomes cannot be empty.');
        }
        foreach ($outcomes as $outcome) {
            if (!in_array($outcome, ['candidate', 'baseline', 'tie'], true)) {
                throw new InvalidArgumentException('Pairwise outcome is invalid.');
            }
        }
        HarnessPersistenceValue::boundedInt($samples, 'pairwise.bootstrap_samples', 100, 10000);
        $wins = count(array_filter($outcomes, static fn (string $v): bool => $v === 'candidate'));
        $losses = count(array_filter($outcomes, static fn (string $v): bool => $v === 'baseline'));
        $ties = count($outcomes) - $wins - $losses;
        $net = ($wins - $losses) / count($outcomes);
        $bootstrap = [];
        $state = $seed & 0x7fffffff;
        for ($sample = 0; $sample < $samples; $sample++) {
            $score = 0;
            for ($i = 0; $i < count($outcomes); $i++) {
                $state = (int)(($state * 1103515245 + 12345) & 0x7fffffff);
                $picked = $outcomes[$state % count($outcomes)];
                $score += $picked === 'candidate' ? 1 : ($picked === 'baseline' ? -1 : 0);
            }
            $bootstrap[] = $score / count($outcomes);
        }
        sort($bootstrap, SORT_NUMERIC);
        return [
            'wins' => $wins,
            'ties' => $ties,
            'losses' => $losses,
            'net_win_rate' => $net,
            'ci95' => [
                'lower' => $bootstrap[(int)floor(($samples - 1) * 0.025)],
                'upper' => $bootstrap[(int)ceil(($samples - 1) * 0.975)],
            ],
        ];
    }
}

final class AnnotationAgreementMetrics
{
    /** @param list<string> $a @param list<string> $b */
    public static function weightedKappa(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            throw new InvalidArgumentException('Annotation vectors must be non-empty and aligned.');
        }
        $labels = ['baseline', 'tie', 'candidate'];
        $index = array_flip($labels);
        $matrix = array_fill(0, 3, array_fill(0, 3, 0));
        foreach ($a as $i => $left) {
            $right = $b[$i] ?? null;
            if (!isset($index[$left]) || !is_string($right) || !isset($index[$right])) {
                throw new InvalidArgumentException('Annotation label is invalid.');
            }
            $matrix[$index[$left]][$index[$right]]++;
        }
        $n = count($a);
        $row = $col = array_fill(0, 3, 0);
        $observed = 0.0;
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $weight = abs($i - $j) / 2;
                $observed += $weight * $matrix[$i][$j] / $n;
                $row[$i] += $matrix[$i][$j];
                $col[$j] += $matrix[$i][$j];
            }
        }
        $expected = 0.0;
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $expected += (abs($i - $j) / 2) * ($row[$i] * $col[$j]) / ($n * $n);
            }
        }
        return $expected === 0.0 ? 1.0 : 1.0 - ($observed / $expected);
    }

    /** @param list<string> $judge @param list<string> $human */
    public static function exactAgreement(array $judge, array $human): float
    {
        if ($judge === [] || count($judge) !== count($human)) {
            throw new InvalidArgumentException('Agreement vectors must be aligned.');
        }
        $matches = 0;
        foreach ($judge as $i => $value) {
            if ($value === ($human[$i] ?? null)) $matches++;
        }
        return $matches / count($judge);
    }
}
