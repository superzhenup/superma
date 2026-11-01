<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ReviewContracts.php';

final readonly class AggregatedReview
{
    /** @var list<ReviewIssue> */
    public array $issues;
    /** @var list<string> */
    public array $reportUids;

    /** @param list<ReviewIssue> $issues @param list<string> $reportUids */
    public function __construct(
        public string $draftHash,
        array $issues,
        public int $score,
        array $reportUids
    ) {
        $this->issues = $issues;
        $this->reportUids = $reportUids;
    }
}

final class ReviewAggregator
{
    /** @param list<ReviewReport> $reports */
    public function aggregate(array $reports): AggregatedReview
    {
        if ($reports === []) {
            throw new InvalidArgumentException('Review aggregation requires reports.');
        }
        $draftHash = $reports[0]->draftHash;
        $issues = [];
        $reportUids = [];
        $scores = [];
        foreach ($reports as $report) {
            if (!$report instanceof ReviewReport || !hash_equals($draftHash, $report->draftHash)) {
                throw new InvalidArgumentException('Review reports must share one immutable draft hash.');
            }
            $reportUids[$report->reportUid] = $report->reportUid;
            $scores[] = $report->score;
            foreach ($report->issues as $issue) {
                $existing = $issues[$issue->issueUid] ?? null;
                if ($existing === null || self::compare($issue, $existing) < 0) {
                    $issues[$issue->issueUid] = $issue;
                }
            }
        }
        $issues = array_values($issues);
        usort($issues, [self::class, 'compare']);
        ksort($reportUids, SORT_STRING);
        // The weakest dimension controls the aggregate; arrival order cannot.
        return new AggregatedReview(
            $draftHash,
            $issues,
            min($scores),
            array_values($reportUids)
        );
    }

    private static function compare(ReviewIssue $a, ReviewIssue $b): int
    {
        return (($b->canonImpact ? 1 : 0) <=> ($a->canonImpact ? 1 : 0))
            ?: ($b->severity->rank() <=> $a->severity->rank())
            ?: ($b->confidence <=> $a->confidence)
            ?: strcmp($a->code, $b->code)
            ?: strcmp($a->issueUid, $b->issueUid);
    }
}
