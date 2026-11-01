<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ReviewContracts.php';

interface ReviewExecutor
{
    /** @param list<PureReviewer> $reviewers @return list<ReviewReport> */
    public function execute(array $reviewers, ReviewInput $input): array;
}

/** Immutable evidence of how one Reviewer batch was actually executed. */
final readonly class ReviewExecutionTrace
{
    public const MODE_SERIAL = 'serial';
    public const MODE_PARALLEL = 'parallel';
    public const MODE_SERIAL_FALLBACK = 'serial_fallback';

    public function __construct(
        public string $mode,
        public int $reviewerCount,
        public ?string $fallbackReason = null,
        public ?string $failureFingerprint = null
    ) {
        if (!in_array($mode, [
            self::MODE_SERIAL,
            self::MODE_PARALLEL,
            self::MODE_SERIAL_FALLBACK,
        ], true)) {
            throw new InvalidArgumentException('Review execution mode is invalid.');
        }
        HarnessPersistenceValue::positiveInt(
            $reviewerCount,
            'review_execution.reviewer_count'
        );
        if (($mode === self::MODE_SERIAL_FALLBACK) !== ($fallbackReason !== null)) {
            throw new InvalidArgumentException(
                'Review fallback execution must carry an explicit reason.'
            );
        }
        if ($fallbackReason !== null) {
            HarnessPersistenceValue::identifier(
                $fallbackReason,
                'review_execution.fallback_reason',
                64
            );
        }
        if ($failureFingerprint !== null) {
            HarnessPersistenceValue::hash(
                $failureFingerprint,
                'review_execution.failure_fingerprint'
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'reviewer_count' => $this->reviewerCount,
            'fallback_reason' => $this->fallbackReason,
            'failure_fingerprint' => $this->failureFingerprint,
        ];
    }
}

/** Optional diagnostics contract consumed by the production Review bridge. */
interface ReviewExecutionTraceProvider
{
    public function lastExecutionTrace(): ?ReviewExecutionTrace;
}

final class SerialReviewExecutor implements ReviewExecutor, ReviewExecutionTraceProvider
{
    private ?ReviewExecutionTrace $lastTrace = null;

    public function execute(array $reviewers, ReviewInput $input): array
    {
        $reports = array_map(
            static fn (PureReviewer $reviewer): ReviewReport => $reviewer->analyze($input),
            $reviewers
        );
        $this->lastTrace = new ReviewExecutionTrace(
            ReviewExecutionTrace::MODE_SERIAL,
            count($reviewers)
        );
        return $reports;
    }

    public function lastExecutionTrace(): ?ReviewExecutionTrace
    {
        return $this->lastTrace;
    }
}

/**
 * Optional cross-platform parallel seam. If process/thread execution is not
 * available or fails before returning complete reports, pure Reviewers are
 * safely rerun using the deterministic serial baseline.
 */
final class AdaptiveReviewExecutor implements ReviewExecutor, ReviewExecutionTraceProvider
{
    private ?Closure $parallelMap;
    private ?ReviewExecutionTrace $lastTrace = null;

    /** @param callable(list<PureReviewer>,ReviewInput):list<ReviewReport>|null $parallelMap */
    public function __construct(
        ?callable $parallelMap = null,
        private readonly ReviewExecutor $fallback = new SerialReviewExecutor()
    ) {
        $this->parallelMap = $parallelMap === null
            ? null
            : Closure::fromCallable($parallelMap);
    }

    public function execute(array $reviewers, ReviewInput $input): array
    {
        if (count($reviewers) < 2) {
            $reports = $this->fallback->execute($reviewers, $input);
            $this->lastTrace = new ReviewExecutionTrace(
                ReviewExecutionTrace::MODE_SERIAL,
                count($reviewers)
            );
            return $reports;
        }
        if ($this->parallelMap === null) {
            $reports = $this->fallback->execute($reviewers, $input);
            $this->lastTrace = new ReviewExecutionTrace(
                ReviewExecutionTrace::MODE_SERIAL_FALLBACK,
                count($reviewers),
                'parallel_executor_unavailable'
            );
            return $reports;
        }
        try {
            $reports = ($this->parallelMap)($reviewers, $input);
            if (!is_array($reports) || count($reports) !== count($reviewers)) {
                throw new RuntimeException('Parallel Reviewer executor returned incomplete results.');
            }
            foreach ($reports as $report) {
                if (!$report instanceof ReviewReport
                    || !hash_equals($report->draftHash, $input->draftHash)) {
                    throw new RuntimeException('Parallel Reviewer result is malformed.');
                }
            }
            self::assertExactCoordinates($reviewers, $reports);
            $this->lastTrace = new ReviewExecutionTrace(
                ReviewExecutionTrace::MODE_PARALLEL,
                count($reviewers)
            );
            return array_values($reports);
        } catch (Throwable $exception) {
            $reports = $this->fallback->execute($reviewers, $input);
            // Never persist exception text. The fingerprint makes repeated
            // fallback causes correlatable without leaking provider output.
            $this->lastTrace = new ReviewExecutionTrace(
                ReviewExecutionTrace::MODE_SERIAL_FALLBACK,
                count($reviewers),
                'parallel_execution_failed',
                hash('sha256', $exception::class . "\0" . (
                    $exception instanceof ReviewParallelExecutionException
                        ? $exception->reason
                        : 'uncategorized_parallel_failure'
                ))
            );
            return $reports;
        }
    }

    public function lastExecutionTrace(): ?ReviewExecutionTrace
    {
        return $this->lastTrace;
    }

    /** @param list<PureReviewer> $reviewers @param list<ReviewReport> $reports */
    private static function assertExactCoordinates(array $reviewers, array $reports): void
    {
        $expected = [];
        foreach ($reviewers as $reviewer) {
            $expected[$reviewer->name() . '@' . $reviewer->version()] = true;
        }
        $actual = [];
        foreach ($reports as $report) {
            $coordinate = $report->reviewer . '@' . $report->version;
            if (isset($actual[$coordinate])) {
                throw new RuntimeException('Parallel Reviewer returned a duplicate coordinate.');
            }
            $actual[$coordinate] = true;
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        if (array_keys($expected) !== array_keys($actual)) {
            throw new RuntimeException('Parallel Reviewer coordinate set is incomplete.');
        }
    }
}

final class ReviewCoordinator
{
    /** @var list<PureReviewer> */
    private array $reviewers;

    /** @param list<PureReviewer> $reviewers */
    public function __construct(
        array $reviewers,
        private readonly ReviewExecutor $executor,
        private readonly ReviewReportStore $store
    ) {
        if ($reviewers === []) {
            throw new InvalidArgumentException('Review Coordinator requires Reviewers.');
        }
        $names = [];
        foreach ($reviewers as $reviewer) {
            if (!$reviewer instanceof PureReviewer) {
                throw new InvalidArgumentException('Review Coordinator inputs must be pure Reviewers.');
            }
            $coordinate = $reviewer->name() . '@' . $reviewer->version();
            if (isset($names[$coordinate])) {
                throw new InvalidArgumentException("Duplicate Reviewer {$coordinate}.");
            }
            $names[$coordinate] = true;
        }
        usort($reviewers, static fn (PureReviewer $a, PureReviewer $b): int =>
            strcmp($a->name(), $b->name()) ?: strcmp($a->version(), $b->version())
        );
        $this->reviewers = array_values($reviewers);
    }

    /** @return list<ReviewReport> */
    public function review(ReviewInput $input): array
    {
        $reports = $this->executor->execute($this->reviewers, $input);
        if (count($reports) !== count($this->reviewers)) {
            throw new RuntimeException('Review execution did not return every configured report.');
        }
        $expected = [];
        foreach ($this->reviewers as $reviewer) {
            $expected[$reviewer->name() . '@' . $reviewer->version()] = true;
        }
        $seen = [];
        $stored = [];
        foreach ($reports as $report) {
            if (!$report instanceof ReviewReport
                || !hash_equals($report->draftHash, $input->draftHash)) {
                throw new RuntimeException('Reviewers must read one immutable draft hash.');
            }
            $coordinate = $report->reviewer . '@' . $report->version;
            if (!isset($expected[$coordinate]) || isset($seen[$coordinate])) {
                throw new RuntimeException(
                    'Review execution returned an unexpected or duplicate Reviewer coordinate.'
                );
            }
            $seen[$coordinate] = true;
            $stored[] = $this->store->storeIdempotent($report);
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Review execution omitted a configured Reviewer coordinate.');
        }
        usort($stored, static fn (ReviewReport $a, ReviewReport $b): int =>
            strcmp($a->reviewer, $b->reviewer) ?: strcmp($a->version, $b->version)
        );
        return $stored;
    }

    public function lastExecutionTrace(): ?ReviewExecutionTrace
    {
        return $this->executor instanceof ReviewExecutionTraceProvider
            ? $this->executor->lastExecutionTrace()
            : null;
    }
}
