<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';

final readonly class BranchMergeCommitDraft
{
    public function __construct(
        public int $approvalId,
        public int $candidateId,
        public int $chapterId,
        public string $branchUid,
        public ?string $baseRevisionUid,
        public string $committedRevisionUid,
        public string $committedRevisionHash,
        public int $postprocessJobId,
        public string $approvalDecisionPayloadHash
    ) {
        HarnessPersistenceValue::positiveInt($approvalId, 'merge_ledger.approval_id');
        HarnessPersistenceValue::positiveInt($candidateId, 'merge_ledger.candidate_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'merge_ledger.chapter_id');
        HarnessPersistenceValue::uid($branchUid, 'merge_ledger.branch_uid');
        if ($baseRevisionUid !== null) {
            HarnessPersistenceValue::uid($baseRevisionUid, 'merge_ledger.base_revision_uid');
        }
        HarnessPersistenceValue::uid(
            $committedRevisionUid,
            'merge_ledger.committed_revision_uid'
        );
        HarnessPersistenceValue::hash(
            $committedRevisionHash,
            'merge_ledger.committed_revision_hash'
        );
        HarnessPersistenceValue::positiveInt(
            $postprocessJobId,
            'merge_ledger.postprocess_job_id'
        );
        HarnessPersistenceValue::hash(
            $approvalDecisionPayloadHash,
            'merge_ledger.approval_decision_payload_hash'
        );
    }
}

final readonly class BranchMergeCommitRecord
{
    public function __construct(
        public int $id,
        public int $approvalId,
        public int $candidateId,
        public int $chapterId,
        public string $branchUid,
        public ?string $baseRevisionUid,
        public string $committedRevisionUid,
        public string $committedRevisionHash,
        public int $postprocessJobId,
        public string $approvalDecisionPayloadHash,
        public string $committedAt
    ) {
    }
}

/** Exactly-once ledger for an approved, Run-less M65 Branch merge. */
final class BranchMergeCommitLedgerRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function recordIdempotent(BranchMergeCommitDraft $draft): BranchMergeCommitRecord
    {
        return $this->db->transactional(function () use ($draft): BranchMergeCommitRecord {
            $this->db->execute(
                "INSERT INTO branch_merge_commit_ledger
                    (approval_id,candidate_id,chapter_id,branch_uid,base_revision_uid,
                     committed_revision_uid,committed_revision_hash,postprocess_job_id,
                     approval_decision_payload_hash)
                 VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->approvalId,
                    $draft->candidateId,
                    $draft->chapterId,
                    $draft->branchUid,
                    $draft->baseRevisionUid,
                    $draft->committedRevisionUid,
                    $draft->committedRevisionHash,
                    $draft->postprocessJobId,
                    $draft->approvalDecisionPayloadHash,
                ]
            );
            $row = $this->db->fetchOne(
                'SELECT * FROM branch_merge_commit_ledger '
                    . 'WHERE approval_id=? OR candidate_id=? FOR UPDATE',
                [$draft->approvalId, $draft->candidateId]
            );
            if ($row === null) {
                throw new HarnessPersistenceException(
                    'Branch merge ledger insert could not be resolved.'
                );
            }
            $record = self::fromRow($row);
            if ($record->approvalId !== $draft->approvalId
                || $record->candidateId !== $draft->candidateId
                || $record->chapterId !== $draft->chapterId
                || $record->branchUid !== $draft->branchUid
                || $record->baseRevisionUid !== $draft->baseRevisionUid
                || $record->committedRevisionUid !== $draft->committedRevisionUid
                || !hash_equals(
                    $record->committedRevisionHash,
                    $draft->committedRevisionHash
                )
                || $record->postprocessJobId !== $draft->postprocessJobId
                || !hash_equals(
                    $record->approvalDecisionPayloadHash,
                    $draft->approvalDecisionPayloadHash
                )) {
                throw new HarnessIdempotencyConflictException(
                    'Branch merge ledger identity resolved to different immutable coordinates.'
                );
            }
            return $record;
        });
    }

    public function findByApproval(int $approvalId, bool $forUpdate = false): ?BranchMergeCommitRecord
    {
        $approvalId = HarnessPersistenceValue::positiveInt(
            $approvalId,
            'merge_ledger.approval_id'
        );
        $row = $this->db->fetchOne(
            'SELECT * FROM branch_merge_commit_ledger WHERE approval_id=?'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            [$approvalId]
        );
        return $row === null ? null : self::fromRow($row);
    }

    /** @param array<string,mixed> $row */
    private static function fromRow(array $row): BranchMergeCommitRecord
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'approval_id', 'candidate_id', 'chapter_id', 'branch_uid',
            'base_revision_uid', 'committed_revision_uid', 'committed_revision_hash',
            'postprocess_job_id', 'approval_decision_payload_hash', 'committed_at',
        ], 'branch_merge_commit_ledger');
        return new BranchMergeCommitRecord(
            HarnessPersistenceValue::positiveInt($row['id'], 'merge_ledger.id'),
            HarnessPersistenceValue::positiveInt(
                $row['approval_id'],
                'merge_ledger.approval_id'
            ),
            HarnessPersistenceValue::positiveInt(
                $row['candidate_id'],
                'merge_ledger.candidate_id'
            ),
            HarnessPersistenceValue::positiveInt(
                $row['chapter_id'],
                'merge_ledger.chapter_id'
            ),
            HarnessPersistenceValue::uid($row['branch_uid'], 'merge_ledger.branch_uid'),
            ($row['base_revision_uid'] ?? null) === null
                ? null
                : HarnessPersistenceValue::uid(
                    $row['base_revision_uid'],
                    'merge_ledger.base_revision_uid'
                ),
            HarnessPersistenceValue::uid(
                $row['committed_revision_uid'],
                'merge_ledger.committed_revision_uid'
            ),
            HarnessPersistenceValue::hash(
                $row['committed_revision_hash'],
                'merge_ledger.committed_revision_hash'
            ),
            HarnessPersistenceValue::positiveInt(
                $row['postprocess_job_id'],
                'merge_ledger.postprocess_job_id'
            ),
            HarnessPersistenceValue::hash(
                $row['approval_decision_payload_hash'],
                'merge_ledger.approval_decision_payload_hash'
            ),
            HarnessPersistenceValue::optionalMysqlDate(
                $row['committed_at'],
                'merge_ledger.committed_at'
            ) ?? throw new HarnessPersistenceException('Merge ledger timestamp is missing.')
        );
    }
}
