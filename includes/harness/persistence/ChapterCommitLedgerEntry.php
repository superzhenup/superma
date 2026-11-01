<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';

final readonly class ChapterCommitDraft
{
    public int $runId;
    public int $chapterId;
    public string $draftHash;
    public ?string $previousRevisionHash;
    public string $committedRevisionHash;
    public ?int $postprocessJobId;
    public ?int $candidateId;

    public function __construct(
        int $runId,
        int $chapterId,
        string $draftHash,
        ?string $previousRevisionHash,
        string $committedRevisionHash,
        ?int $postprocessJobId,
        ?int $candidateId = null
    ) {
        $this->runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $this->chapterId = HarnessPersistenceValue::positiveInt($chapterId, 'chapter_id');
        $this->draftHash = HarnessPersistenceValue::hash($draftHash, 'draft_hash');
        $this->previousRevisionHash = HarnessPersistenceValue::optionalHash(
            $previousRevisionHash,
            'previous_revision_hash'
        );
        $this->committedRevisionHash = HarnessPersistenceValue::hash(
            $committedRevisionHash,
            'committed_revision_hash'
        );
        $this->postprocessJobId = HarnessPersistenceValue::optionalPositiveInt(
            $postprocessJobId,
            'postprocess_job_id'
        );
        $this->candidateId = HarnessPersistenceValue::optionalPositiveInt(
            $candidateId,
            'candidate_id'
        );
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        $entry = ChapterCommitLedgerEntry::fromRow($row);
        if ($entry->runId !== $this->runId
            || $entry->chapterId !== $this->chapterId
            || !hash_equals($entry->draftHash, $this->draftHash)
            || $entry->previousRevisionHash !== $this->previousRevisionHash
            || !hash_equals($entry->committedRevisionHash, $this->committedRevisionHash)
            || $entry->postprocessJobId !== $this->postprocessJobId
            || $entry->candidateId !== $this->candidateId) {
            throw new HarnessIdempotencyConflictException(
                'Commit ledger uniqueness resolved to a different canonical commit.'
            );
        }
    }
}

final readonly class ChapterCommitLedgerEntry
{
    public function __construct(
        public int $id,
        public int $runId,
        public int $chapterId,
        public string $draftHash,
        public ?string $previousRevisionHash,
        public string $committedRevisionHash,
        public ?int $postprocessJobId,
        public string $committedAt,
        public ?int $candidateId = null
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'run_id', 'chapter_id', 'candidate_id', 'draft_hash', 'previous_revision_hash',
            'committed_revision_hash', 'postprocess_job_id', 'committed_at',
        ], 'chapter_commit_ledger');
        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            HarnessPersistenceValue::positiveInt($row['run_id'], 'run_id'),
            HarnessPersistenceValue::positiveInt($row['chapter_id'], 'chapter_id'),
            HarnessPersistenceValue::hash($row['draft_hash'], 'draft_hash'),
            HarnessPersistenceValue::optionalHash(
                $row['previous_revision_hash'],
                'previous_revision_hash'
            ),
            HarnessPersistenceValue::hash(
                $row['committed_revision_hash'],
                'committed_revision_hash'
            ),
            HarnessPersistenceValue::optionalPositiveInt(
                $row['postprocess_job_id'],
                'postprocess_job_id'
            ),
            HarnessPersistenceValue::optionalMysqlDate($row['committed_at'], 'committed_at')
                ?? throw new InvalidArgumentException('chapter_commit_ledger.committed_at is required.'),
            HarnessPersistenceValue::optionalPositiveInt($row['candidate_id'], 'candidate_id')
        );
    }
}
