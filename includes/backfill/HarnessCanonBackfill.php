<?php

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Strict command-line options for the historical Harness Canon backfill.
 */
final class HarnessCanonBackfillOptions
{
    public const DEFAULT_BATCH_SIZE = 100;
    public const MAX_BATCH_SIZE = 1000;

    public function __construct(
        public readonly bool $apply,
        public readonly int $batchSize,
        public readonly int $resumeCursor,
        public readonly bool $json,
        public readonly bool $help = false
    ) {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                'batch must be between 1 and ' . self::MAX_BATCH_SIZE . '.'
            );
        }
        if ($resumeCursor < 0) {
            throw new InvalidArgumentException('resume must be a non-negative chapter id.');
        }
    }

    /**
     * Parse argv without getopt's platform-dependent unknown-option behavior.
     *
     * @param list<string> $argv
     */
    public static function fromArgv(array $argv): self
    {
        $apply = false;
        $dryRun = false;
        $json = false;
        $help = false;
        $batch = self::DEFAULT_BATCH_SIZE;
        $resume = 0;
        $seen = [];

        for ($index = 1, $count = count($argv); $index < $count; $index++) {
            $argument = $argv[$index];
            if (!is_string($argument) || $argument === '') {
                throw new InvalidArgumentException('Empty command-line argument is not allowed.');
            }

            if (in_array($argument, ['--apply', '--dry-run', '--json', '--help'], true)) {
                $name = substr($argument, 2);
                self::assertNotRepeated($seen, $name);
                $seen[$name] = true;
                if ($argument === '--apply') {
                    $apply = true;
                } elseif ($argument === '--dry-run') {
                    $dryRun = true;
                } elseif ($argument === '--json') {
                    $json = true;
                } else {
                    $help = true;
                }
                continue;
            }

            $matched = false;
            foreach (['batch', 'resume'] as $valueOption) {
                $prefix = '--' . $valueOption . '=';
                if (str_starts_with($argument, $prefix)) {
                    self::assertNotRepeated($seen, $valueOption);
                    $seen[$valueOption] = true;
                    $rawValue = substr($argument, strlen($prefix));
                    if ($rawValue === '') {
                        throw new InvalidArgumentException("{$valueOption} requires a value.");
                    }
                    if ($valueOption === 'batch') {
                        $batch = self::parseUnsignedDecimal($rawValue, 'batch');
                    } else {
                        $resume = self::parseUnsignedDecimal($rawValue, 'resume');
                    }
                    $matched = true;
                    break;
                }
                if ($argument === '--' . $valueOption) {
                    self::assertNotRepeated($seen, $valueOption);
                    $seen[$valueOption] = true;
                    $index++;
                    if ($index >= $count || str_starts_with((string)$argv[$index], '--')) {
                        throw new InvalidArgumentException("{$valueOption} requires a value.");
                    }
                    $rawValue = (string)$argv[$index];
                    if ($valueOption === 'batch') {
                        $batch = self::parseUnsignedDecimal($rawValue, 'batch');
                    } else {
                        $resume = self::parseUnsignedDecimal($rawValue, 'resume');
                    }
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            throw new InvalidArgumentException("Unknown argument: {$argument}");
        }

        if ($apply && $dryRun) {
            throw new InvalidArgumentException('--apply and --dry-run are mutually exclusive.');
        }
        if ($batch < 1 || $batch > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                'batch must be between 1 and ' . self::MAX_BATCH_SIZE . '.'
            );
        }

        return new self($apply, $batch, $resume, $json, $help);
    }

    /** @param array<string,bool> $seen */
    private static function assertNotRepeated(array $seen, string $name): void
    {
        if (isset($seen[$name])) {
            throw new InvalidArgumentException("Option --{$name} may only be specified once.");
        }
    }

    private static function parseUnsignedDecimal(string $raw, string $name): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1) {
            throw new InvalidArgumentException("{$name} must be an unsigned decimal integer.");
        }

        $maximum = (string)PHP_INT_MAX;
        $normalized = ltrim($raw, '0');
        if ($normalized === '') {
            $normalized = '0';
        }
        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            throw new InvalidArgumentException("{$name} exceeds the supported integer range.");
        }

        return (int)$normalized;
    }
}

/**
 * Persistence boundary kept injectable so the migration algorithm is testable
 * without production credentials or a live database.
 */
interface HarnessCanonBackfillStore
{
    public function acquireAdvisoryLock(string $name, int $timeoutSeconds): bool;

    public function releaseAdvisoryLock(string $name): void;

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchRowsAfter(int $resumeCursor, int $limit): array;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Atomically promote the exact row observed by fetchRowsAfter() to legacy.
     *
     * @param array<string,mixed> $expectedRow
     */
    public function compareAndSetLegacy(array $expectedRow, string $contentHash): bool;
}

/**
 * One bounded, resumable historical backfill batch.
 *
 * Historical content is deliberately marked legacy, never canonical: there is
 * no authentic Candidate or frozen write policy to attach retroactively.
 */
final class HarnessCanonBackfill
{
    public const ADVISORY_LOCK = 'harness_canon_backfill_v1';

    public function __construct(private readonly HarnessCanonBackfillStore $store)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(HarnessCanonBackfillOptions $options): array
    {
        $result = [
            'ok' => true,
            'needs_review' => false,
            'mode' => $options->apply ? 'apply' : 'dry-run',
            'batch_size' => $options->batchSize,
            'input_cursor' => $options->resumeCursor,
            // resume_cursor is safe to feed only into a subsequent APPLY.
            // Dry-runs expose their pagination separately so a preview cursor
            // cannot accidentally skip rows that were never persisted.
            'resume_cursor' => $options->resumeCursor,
            'next_scan_cursor' => $options->resumeCursor,
            'scanned' => 0,
            'eligible' => 0,
            'would_update' => 0,
            'updated' => 0,
            'already_current' => 0,
            'skipped' => [
                'not_completed' => 0,
                'empty_content' => 0,
                'unsafe_metadata' => 0,
            ],
            'conflicts' => 0,
            'rolled_back' => 0,
            'has_more' => false,
        ];

        if (!$this->store->acquireAdvisoryLock(self::ADVISORY_LOCK, 0)) {
            throw new RuntimeException('Another Harness Canon backfill process holds the advisory lock.');
        }

        try {
            // One extra row is a bounded, deterministic has_more probe. It is
            // not processed or included in this batch's resume cursor.
            $rows = $this->store->fetchRowsAfter(
                $options->resumeCursor,
                $options->batchSize + 1
            );
            $result['has_more'] = count($rows) > $options->batchSize;
            $rows = array_slice($rows, 0, $options->batchSize);

            $updates = [];
            $lastId = $options->resumeCursor;
            foreach ($rows as $row) {
                $id = self::requireAscendingId($row['id'] ?? null, $lastId);
                $lastId = $id;
                $result['scanned']++;

                $status = $row['status'] ?? null;
                if ($status !== 'completed') {
                    $result['skipped']['not_completed']++;
                    continue;
                }

                $content = $row['content'] ?? null;
                if (!is_string($content) || $content === '') {
                    $result['skipped']['empty_content']++;
                    continue;
                }

                $canonicalState = $row['canonical_state'] ?? null;
                if (!in_array($canonicalState, ['none', 'legacy'], true)) {
                    throw new RuntimeException("Backfill query returned unsafe canonical state for chapter {$id}.");
                }

                if (
                    ($row['canonical_candidate_uid'] ?? null) !== null
                    || ($row['write_policy_snapshot_json'] ?? null) !== null
                    || ($row['canonicalized_at'] ?? null) !== null
                ) {
                    // A partial/real Canon identity must be investigated. The
                    // historical backfill never erases or fabricates it.
                    $result['skipped']['unsafe_metadata']++;
                    $result['needs_review'] = true;
                    $result['ok'] = false;
                    continue;
                }

                $result['eligible']++;
                $contentHash = hash('sha256', $content);
                $storedHash = $row['content_revision_hash'] ?? null;
                if (
                    $canonicalState === 'legacy'
                    && is_string($storedHash)
                    && hash_equals($contentHash, $storedHash)
                ) {
                    $result['already_current']++;
                    continue;
                }

                $updates[] = [$row, $contentHash];
                $result['would_update']++;
            }

            $result['next_scan_cursor'] = $lastId;
            if (!$options->apply) {
                return $result;
            }
            if ($result['needs_review']) {
                // Never partially apply a batch containing ambiguous Canon
                // provenance, and never advance past the row requiring review.
                $result['has_more'] = true;
                return $result;
            }
            if ($updates === []) {
                $result['resume_cursor'] = $lastId;
                return $result;
            }

            $this->store->beginTransaction();
            $transactionOpen = true;
            try {
                foreach ($updates as [$row, $contentHash]) {
                    if (!$this->store->compareAndSetLegacy($row, $contentHash)) {
                        $result['conflicts']++;
                        $result['rolled_back'] = $result['updated'];
                        $this->store->rollBack();
                        $transactionOpen = false;
                        $result['updated'] = 0;
                        $result['ok'] = false;
                        // The caller can safely retry the entire batch. Never
                        // advance a cursor past a chapter whose CAS was lost.
                        $result['resume_cursor'] = $options->resumeCursor;
                        $result['has_more'] = true;
                        return $result;
                    }
                    $result['updated']++;
                }
                $this->store->commit();
                $transactionOpen = false;
                $result['resume_cursor'] = $lastId;
            } catch (Throwable $e) {
                if ($transactionOpen) {
                    $this->store->rollBack();
                }
                throw $e;
            }

            return $result;
        } finally {
            $this->store->releaseAdvisoryLock(self::ADVISORY_LOCK);
        }
    }

    private static function requireAscendingId(mixed $value, int $previous): int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $maximum = (string)PHP_INT_MAX;
            if (
                strlen($value) > strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
            ) {
                throw new RuntimeException('Backfill query returned an out-of-range chapter id.');
            }
            $id = (int)$value;
        } else {
            throw new RuntimeException('Backfill query returned an invalid chapter id.');
        }

        if ($id <= $previous) {
            throw new RuntimeException('Backfill query did not return strictly ascending chapter ids.');
        }
        return $id;
    }
}
