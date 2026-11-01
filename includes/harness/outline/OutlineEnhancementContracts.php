<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/bootstrap.php';

final class OutlineEnhancementPermanentException extends RuntimeException
{
}

final class OutlineEnhancementRetryableException extends RuntimeException
{
}

final class OutlineEnhancementCanceledException extends RuntimeException
{
}

final class OutlineEnhancementDeadlineException extends RuntimeException
{
}

enum OutlineEnhancementCommitOutcome: string
{
    case APPLIED = 'applied';
    case SHADOW = 'shadow';
    case BASE_CHANGED = 'base_changed';
    case LEASE_LOST = 'lease_lost';
    case CANCELED = 'canceled';
    case EMERGENCY_STOPPED = 'emergency_stopped';
    case DEADLINE_EXCEEDED = 'deadline_exceeded';
}

final readonly class OutlineEnhancementSnapshot
{
    /** @var list<int> */
    public array $chapterNumbers;
    /** @var list<array<string,mixed>> */
    public array $outlines;
    public string $canonicalHash;
    public string $revisionHash;

    /**
     * @param list<int> $chapterNumbers
     * @param list<array<string,mixed>> $outlines
     */
    private function __construct(
        public int $userId,
        public int $novelId,
        array $chapterNumbers,
        array $outlines,
        string $canonicalHash,
        string $revisionHash
    ) {
        $this->chapterNumbers = $chapterNumbers;
        $this->outlines = $outlines;
        $this->canonicalHash = HarnessPersistenceValue::hash(
            $canonicalHash,
            'outline_canonical_hash'
        );
        $this->revisionHash = HarnessPersistenceValue::hash(
            $revisionHash,
            'outline_revision_hash'
        );
    }

    /**
     * @param list<int> $expectedChapterNumbers
     * @param list<array<string,mixed>> $rows
     */
    public static function fromRows(
        int $userId,
        int $novelId,
        array $expectedChapterNumbers,
        array $rows
    ): self {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'outline.user_id');
        $novelId = HarnessPersistenceValue::positiveInt($novelId, 'outline.novel_id');
        $chapterNumbers = self::normalizeChapterNumbers($expectedChapterNumbers);
        if ($chapterNumbers === []) {
            throw new OutlineEnhancementPermanentException(
                'Outline enhancement requires at least one persisted chapter.'
            );
        }

        $contentRows = [];
        $revisionRows = [];
        $outlinesByChapter = [];
        foreach ($rows as $row) {
            HarnessPersistenceValue::requireFields($row, [
                'id', 'chapter_number', 'title', 'outline', 'key_points', 'hook',
                'pacing', 'suspense', 'hook_type', 'cool_point_type', 'status',
                'canonical_state', 'updated_at',
            ], 'outline_chapter');
            $chapterNumber = HarnessPersistenceValue::positiveInt(
                $row['chapter_number'],
                'outline.chapter_number'
            );
            if (isset($outlinesByChapter[$chapterNumber])) {
                throw new OutlineEnhancementPermanentException(
                    'Outline snapshot contains a duplicate chapter number.'
                );
            }
            $keyPoints = ($row['key_points'] ?? null) === null || $row['key_points'] === ''
                ? []
                : HarnessPersistenceValue::decodeJsonArray(
                    $row['key_points'],
                    'outline.key_points'
                );
            $updatedAt = HarnessPersistenceValue::optionalMysqlDate(
                $row['updated_at'],
                'outline.updated_at'
            );
            if ($updatedAt === null) {
                throw new OutlineEnhancementPermanentException(
                    'Outline snapshot is missing its row revision timestamp.'
                );
            }

            $outline = [
                'chapter_number' => $chapterNumber,
                'title' => (string)($row['title'] ?? ''),
                'summary' => (string)($row['outline'] ?? ''),
                'outline' => (string)($row['outline'] ?? ''),
                'key_points' => $keyPoints,
                'hook' => (string)($row['hook'] ?? ''),
                'pacing' => (string)($row['pacing'] ?? '中'),
                'suspense' => (string)($row['suspense'] ?? '无'),
                'hook_type' => (string)($row['hook_type'] ?? ''),
                'cool_point_type' => (string)($row['cool_point_type'] ?? ''),
            ];
            $outlinesByChapter[$chapterNumber] = $outline;
            $contentRows[] = array_merge($outline, [
                'status' => (string)$row['status'],
                'canonical_state' => (string)$row['canonical_state'],
            ]);
            $revisionRows[] = [
                'id' => HarnessPersistenceValue::positiveInt($row['id'], 'outline.chapter_id'),
                'chapter_number' => $chapterNumber,
                'updated_at' => $updatedAt,
                // Include a detailed blueprint even if its parent chapter timestamp
                // did not move (editing an existing synopsis only updates its row).
                'synopsis_id' => isset($row['synopsis_id']) ? (int)$row['synopsis_id'] : null,
                'synopsis_revision' => $row['synopsis_revision'] ?? null,
            ];
        }

        ksort($outlinesByChapter, SORT_NUMERIC);
        usort(
            $contentRows,
            static fn (array $a, array $b): int => $a['chapter_number'] <=> $b['chapter_number']
        );
        usort(
            $revisionRows,
            static fn (array $a, array $b): int => $a['chapter_number'] <=> $b['chapter_number']
        );
        if (array_keys($outlinesByChapter) !== $chapterNumbers) {
            throw new OutlineEnhancementPermanentException(
                'Outline snapshot no longer contains the exact persisted chapter set.'
            );
        }

        $identity = ['user_id' => $userId, 'novel_id' => $novelId];
        return new self(
            $userId,
            $novelId,
            $chapterNumbers,
            array_values($outlinesByChapter),
            hash('sha256', HarnessPersistenceValue::canonicalJson([
                'identity' => $identity,
                'chapters' => $contentRows,
            ])),
            hash('sha256', HarnessPersistenceValue::canonicalJson([
                'identity' => $identity,
                'revisions' => $revisionRows,
            ]))
        );
    }

    /** @param list<int> $chapterNumbers @return list<int> */
    public static function normalizeChapterNumbers(array $chapterNumbers): array
    {
        $normalized = [];
        foreach ($chapterNumbers as $chapterNumber) {
            $value = HarnessPersistenceValue::positiveInt(
                $chapterNumber,
                'outline.chapter_number'
            );
            $normalized[$value] = $value;
        }
        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }
}

interface OutlineEnhancementRunStore
{
    public function findByIdempotency(int $userId, string $idempotencyKey): ?GenerationRun;

    public function createIdempotent(GenerationRunDraft $draft): GenerationRun;
}

interface OutlineEnhancementLeaseQueue
{
    public function claim(string $owner, int $leaseSeconds): ?GenerationRunLease;

    public function renew(GenerationRunLease $lease, int $leaseSeconds): GenerationRunLease;

    /** @param array<string,mixed>|null $result */
    public function release(
        GenerationRunLease $lease,
        GenerationRunState $next,
        int $progress,
        ?DateTimeImmutable $availableAt = null,
        ?array $result = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): GenerationRun;

    public function cancellationRequested(GenerationRunLease $lease): bool;
}

interface OutlineEnhancementMappedRunGateway extends OutlineEnhancementLeaseQueue
{
    public function claimByUid(
        string $runUid,
        string $owner,
        int $leaseSeconds
    ): ?GenerationRunLease;

    public function findByUid(string $runUid): ?GenerationRun;

    public function requestCancel(string $runUid): bool;
}

interface OutlineEnhancementLeaseExecutor
{
    /** @param callable():void|null $outerCheckpoint @return array<string,mixed> */
    public function runLease(
        GenerationRunLease $lease,
        int $leaseSeconds,
        ?callable $outerCheckpoint = null
    ): array;
}

interface OutlineEnhancementStore
{
    /** @param list<int> $chapterNumbers */
    public function capture(
        int $userId,
        int $novelId,
        array $chapterNumbers
    ): OutlineEnhancementSnapshot;

    /** @return array<string,mixed> */
    public function loadNovel(int $userId, int $novelId): array;

    /** @return list<array<string,mixed>> */
    public function loadRecentOutlines(int $novelId, int $beforeChapter, int $limit): array;

    /**
     * @param list<array<string,mixed>> $enhanced
     * @param callable():GenerationRun $releaseSucceeded
     */
    public function commit(
        GenerationRunLease $lease,
        OutlineEnhancementSnapshot $base,
        array $enhanced,
        bool $authority,
        int $emergencyEpoch,
        callable $releaseSucceeded
    ): OutlineEnhancementCommitOutcome;

    /** @param list<array<string,mixed>> $outlines */
    public function afterCommit(
        int $novelId,
        int $startChapter,
        int $endChapter,
        array $outlines
    ): void;

    public function log(int $novelId, string $level, string $message): void;
}

interface OutlineEnhancer
{
    /**
     * @param array<string,mixed> $novel
     * @param list<array<string,mixed>> $recentOutlines
     * @param callable():void $checkpoint
     * @return list<array<string,mixed>>
     */
    public function enhance(
        OutlineEnhancementSnapshot $base,
        array $novel,
        array $recentOutlines,
        callable $checkpoint
    ): array;
}
