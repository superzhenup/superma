<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Immutable, bounded UTC observation scope.
 *
 * Every metrics query uses the same half-open [from, to) interval. Keeping the
 * upper bound fixed is important: a release report is evidence, not a moving
 * dashboard result assembled from different points in time.
 */
final readonly class HarnessMetricsWindow
{
    public const MIN_SECONDS = 300;
    public const MAX_SECONDS = 604800;

    public DateTimeImmutable $fromUtc;
    public DateTimeImmutable $toUtc;
    public ?int $novelId;
    public ?string $workflowName;
    public ?string $workflowVersion;

    public function __construct(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $novelId = null,
        ?string $workflowName = null,
        ?string $workflowVersion = null
    ) {
        $utc = new DateTimeZone('UTC');
        $fromUtc = $from->setTimezone($utc);
        $toUtc = $to->setTimezone($utc);
        $seconds = $toUtc->getTimestamp() - $fromUtc->getTimestamp();
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'Metrics window must be between %d and %d seconds.',
                self::MIN_SECONDS,
                self::MAX_SECONDS
            ));
        }
        if ($novelId !== null && $novelId < 1) {
            throw new InvalidArgumentException('novel_id must be a positive integer.');
        }

        $this->fromUtc = $fromUtc;
        $this->toUtc = $toUtc;
        $this->novelId = $novelId;
        $this->workflowName = self::optionalIdentifier(
            $workflowName,
            'workflow_name',
            64
        );
        $this->workflowVersion = self::optionalIdentifier(
            $workflowVersion,
            'workflow_version',
            32
        );
    }

    public function seconds(): int
    {
        return $this->toUtc->getTimestamp() - $this->fromUtc->getTimestamp();
    }

    public function fromSql(): string
    {
        return $this->fromUtc->format('Y-m-d H:i:s.u');
    }

    public function toSql(): string
    {
        return $this->toUtc->format('Y-m-d H:i:s.u');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'from_utc' => $this->fromUtc->format('Y-m-d\TH:i:s.u\Z'),
            'to_utc' => $this->toUtc->format('Y-m-d\TH:i:s.u\Z'),
            'seconds' => $this->seconds(),
            'scope' => [
                'novel_id' => $this->novelId,
                'workflow_name' => $this->workflowName,
                'workflow_version' => $this->workflowVersion,
            ],
        ];
    }

    private static function optionalIdentifier(
        ?string $value,
        string $field,
        int $maxLength
    ): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                "{$field} must be a non-empty ASCII identifier of at most {$maxLength} bytes."
            );
        }
        return $value;
    }
}
