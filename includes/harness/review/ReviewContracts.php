<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

enum ReviewSeverity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::CRITICAL => 4,
            self::HIGH => 3,
            self::MEDIUM => 2,
            self::LOW => 1,
        };
    }
}

final readonly class ReviewIssue
{
    public string $issueUid;

    public function __construct(
        public string $code,
        public ReviewSeverity $severity,
        public float $confidence,
        public string $evidence,
        public string $suggestion,
        public bool $canonImpact
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Review issue code must be canonical uppercase.');
        }
        if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Review issue confidence must be between 0 and 1.');
        }
        HarnessPersistenceValue::string($evidence, 'review_issue.evidence', 1, 8000);
        HarnessPersistenceValue::string($suggestion, 'review_issue.suggestion', 1, 8000);
        $this->issueUid = substr(hash('sha256', implode("\0", [
            'review-issue-v1',
            $code,
            self::normalize($evidence),
            self::normalize($suggestion),
        ])), 0, 32);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'issue_uid' => $this->issueUid,
            'code' => $this->code,
            'severity' => $this->severity->value,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'suggestion' => $this->suggestion,
            'canon_impact' => $this->canonImpact,
        ];
    }

    private static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}

final readonly class ReviewReport
{
    /** @var list<ReviewIssue> */
    public array $issues;
    public string $reportUid;

    /** @param list<ReviewIssue> $issues */
    public function __construct(
        public string $reviewer,
        public string $version,
        public string $draftHash,
        array $issues,
        public int $score
    ) {
        HarnessPersistenceValue::identifier($reviewer, 'review_report.reviewer', 64);
        HarnessPersistenceValue::identifier($version, 'review_report.version', 32);
        HarnessPersistenceValue::hash($draftHash, 'review_report.draft_hash');
        HarnessPersistenceValue::boundedInt($score, 'review_report.score', 0, 100);
        $byUid = [];
        foreach ($issues as $issue) {
            if (!$issue instanceof ReviewIssue) {
                throw new InvalidArgumentException('Review report issues must be typed values.');
            }
            if (isset($byUid[$issue->issueUid])) {
                throw new InvalidArgumentException(
                    'Review report contains a duplicate immutable issue UID.'
                );
            }
            $byUid[$issue->issueUid] = $issue;
        }
        ksort($byUid, SORT_STRING);
        $this->issues = array_values($byUid);
        $this->reportUid = substr(hash('sha256', HarnessPersistenceValue::canonicalJson([
            'schema_version' => 1,
            'reviewer' => $reviewer,
            'version' => $version,
            'draft_hash' => $draftHash,
            'issues' => array_map(
                static fn (ReviewIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'score' => $score,
        ])), 0, 32);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'report_uid' => $this->reportUid,
            'reviewer' => $this->reviewer,
            'version' => $this->version,
            'draft_hash' => $this->draftHash,
            'issues' => array_map(
                static fn (ReviewIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'score' => $this->score,
        ];
    }
}

final readonly class ReviewInput
{
    /** @var array<string,mixed> */
    public array $contextManifest;

    /** @param array<string,mixed> $contextManifest */
    public function __construct(
        public string $draft,
        public string $draftHash,
        array $contextManifest
    ) {
        HarnessPersistenceValue::hash($draftHash, 'review_input.draft_hash');
        if (!hash_equals(hash('sha256', $draft), $draftHash)) {
            throw new InvalidArgumentException('Review draft hash does not match its bytes.');
        }
        HarnessSensitiveDataGuard::assertSafeStructure(
            $contextManifest,
            'review_input.context_manifest'
        );
        $this->contextManifest = $contextManifest;
    }
}

/** Pure analyzer: implementations receive only immutable review input. */
interface PureReviewer
{
    public function name(): string;
    public function version(): string;
    public function analyze(ReviewInput $input): ReviewReport;
}

final class CallablePureReviewer implements PureReviewer
{
    private Closure $analyzer;

    /** @param callable(ReviewInput):ReviewReport $analyzer */
    public function __construct(
        private readonly string $reviewerName,
        private readonly string $reviewerVersion,
        callable $analyzer
    ) {
        HarnessPersistenceValue::identifier($reviewerName, 'reviewer.name', 64);
        HarnessPersistenceValue::identifier($reviewerVersion, 'reviewer.version', 32);
        $this->analyzer = Closure::fromCallable($analyzer);
    }

    public function name(): string { return $this->reviewerName; }
    public function version(): string { return $this->reviewerVersion; }

    public function analyze(ReviewInput $input): ReviewReport
    {
        $report = ($this->analyzer)($input);
        if (!$report instanceof ReviewReport
            || $report->reviewer !== $this->reviewerName
            || $report->version !== $this->reviewerVersion
            || !hash_equals($report->draftHash, $input->draftHash)) {
            throw new RuntimeException(
                'Pure Reviewer returned a report for different immutable coordinates.'
            );
        }
        return $report;
    }
}

interface ReviewReportStore
{
    public function storeIdempotent(ReviewReport $report): ReviewReport;
}

final class InMemoryReviewReportStore implements ReviewReportStore
{
    /** @var array<string,ReviewReport> */
    private array $reports = [];

    public function storeIdempotent(ReviewReport $report): ReviewReport
    {
        $key = $report->draftHash . ':' . $report->reviewer . ':' . $report->version;
        $existing = $this->reports[$key] ?? null;
        if ($existing !== null && $existing->toArray() !== $report->toArray()) {
            throw new HarnessIdempotencyConflictException(
                'Reviewer coordinate resolved to a different immutable report.'
            );
        }
        $this->reports[$key] = $report;
        return $report;
    }
}
