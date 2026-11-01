<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/ChapterCommitLedgerEntry.php';

final class ChapterCommitLedgerRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    /**
     * Record an already-authorized Canon commit.
     *
     * When called inside the caller's Canon transaction, the PDO adapter does
     * not open or commit a nested transaction, so chapter update, enqueue and
     * ledger insertion remain one atomic unit.
     */
    public function recordIdempotent(ChapterCommitDraft $draft): ChapterCommitLedgerEntry
    {
        return $this->db->transactional(function () use ($draft): ChapterCommitLedgerEntry {
            $this->assertOwnedReferences($draft);

            $this->db->execute(
                "INSERT INTO chapter_commit_ledger
                    (run_id, chapter_id, candidate_id, draft_hash, previous_revision_hash,
                     committed_revision_hash, postprocess_job_id, committed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->runId,
                    $draft->chapterId,
                    $draft->candidateId,
                    $draft->draftHash,
                    $draft->previousRevisionHash,
                    $draft->committedRevisionHash,
                    $draft->postprocessJobId,
                ]
            );

            $row = $this->db->fetchOne(
                'SELECT * FROM chapter_commit_ledger WHERE run_id=? AND draft_hash=? FOR UPDATE',
                [$draft->runId, $draft->draftHash]
            );
            if ($row === null) {
                if ($draft->candidateId !== null) {
                    $row = $this->db->fetchOne(
                        'SELECT * FROM chapter_commit_ledger WHERE candidate_id=? FOR UPDATE',
                        [$draft->candidateId]
                    );
                } else {
                    // Only pre-M63 commits use revision bytes as identity.
                    // Candidate A -> B -> A must never resolve to the first A.
                    $row = $this->db->fetchOne(
                        "SELECT * FROM chapter_commit_ledger
                         WHERE candidate_id IS NULL
                           AND chapter_id=? AND committed_revision_hash=? FOR UPDATE",
                        [$draft->chapterId, $draft->committedRevisionHash]
                    );
                }
            }
            if ($row === null) {
                throw new HarnessIdempotencyConflictException(
                    'Commit ledger insert collided without resolving either unique identity.'
                );
            }
            $draft->assertIdempotentRow($row);
            return ChapterCommitLedgerEntry::fromRow($row);
        });
    }

    public function findByRunAndDraft(int $runId, string $draftHash): ?ChapterCommitLedgerEntry
    {
        $runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $draftHash = HarnessPersistenceValue::hash($draftHash, 'draft_hash');
        $row = $this->db->fetchOne(
            'SELECT * FROM chapter_commit_ledger WHERE run_id=? AND draft_hash=?',
            [$runId, $draftHash]
        );
        return $row === null ? null : ChapterCommitLedgerEntry::fromRow($row);
    }

    public function findByCandidate(int $candidateId): ?ChapterCommitLedgerEntry
    {
        $candidateId = HarnessPersistenceValue::positiveInt($candidateId, 'candidate_id');
        $row = $this->db->fetchOne(
            'SELECT * FROM chapter_commit_ledger WHERE candidate_id=?',
            [$candidateId]
        );
        return $row === null ? null : ChapterCommitLedgerEntry::fromRow($row);
    }

    private function assertOwnedReferences(ChapterCommitDraft $draft): void
    {
        $ownership = $this->db->fetchOne(
            "SELECT r.id AS run_id,
                    r.user_id AS run_user_id,
                    r.novel_id AS run_novel_id,
                    r.chapter_id AS run_chapter_id,
                    c.id AS chapter_id,
                    c.novel_id AS chapter_novel_id,
                    n.id AS novel_id,
                    n.user_id AS novel_user_id,
                    cc.id AS candidate_id,
                    cc.user_id AS candidate_user_id,
                    cc.novel_id AS candidate_novel_id,
                    cc.chapter_id AS candidate_chapter_id,
                    cc.run_id AS candidate_run_id,
                    cc.content_hash AS candidate_content_hash,
                    cc.lifecycle AS candidate_lifecycle,
                    cc.draft_artifact_id AS candidate_draft_artifact_id
             FROM generation_runs r
             INNER JOIN chapters c ON c.id=?
             INNER JOIN novels n ON n.id=r.novel_id
             LEFT JOIN chapter_candidates cc ON cc.id=?
             WHERE r.id=? FOR UPDATE",
            [$draft->chapterId, $draft->candidateId, $draft->runId]
        );
        if ($ownership === null) {
            throw new HarnessPersistenceException(
                'Canon commit Run, Chapter, or Novel ownership record is missing.'
            );
        }

        $runId = HarnessPersistenceValue::positiveInt(
            $ownership['run_id'] ?? null,
            'commit.run_id'
        );
        $runUserId = HarnessPersistenceValue::positiveInt(
            $ownership['run_user_id'] ?? null,
            'commit.run_user_id'
        );
        $runNovelId = HarnessPersistenceValue::positiveInt(
            $ownership['run_novel_id'] ?? null,
            'commit.run_novel_id'
        );
        $runChapterId = HarnessPersistenceValue::optionalPositiveInt(
            $ownership['run_chapter_id'] ?? null,
            'commit.run_chapter_id'
        );
        $chapterId = HarnessPersistenceValue::positiveInt(
            $ownership['chapter_id'] ?? null,
            'commit.chapter_id'
        );
        $chapterNovelId = HarnessPersistenceValue::positiveInt(
            $ownership['chapter_novel_id'] ?? null,
            'commit.chapter_novel_id'
        );
        $novelId = HarnessPersistenceValue::positiveInt(
            $ownership['novel_id'] ?? null,
            'commit.novel_id'
        );
        $novelUserId = HarnessPersistenceValue::optionalPositiveInt(
            $ownership['novel_user_id'] ?? null,
            'commit.novel_user_id'
        );

        if ($runId !== $draft->runId
            || $chapterId !== $draft->chapterId
            || $runChapterId !== $draft->chapterId
            || $chapterNovelId !== $runNovelId
            || $novelId !== $runNovelId
            || $novelUserId === null
            || $novelUserId !== $runUserId) {
            throw new HarnessPersistenceException(
                'Canon commit Run/User/Novel/Chapter ownership does not match.'
            );
        }

        if ($draft->candidateId !== null) {
            $candidateId = HarnessPersistenceValue::positiveInt(
                $ownership['candidate_id'] ?? null,
                'commit.candidate_id'
            );
            $candidateUserId = HarnessPersistenceValue::positiveInt(
                $ownership['candidate_user_id'] ?? null,
                'commit.candidate_user_id'
            );
            $candidateNovelId = HarnessPersistenceValue::positiveInt(
                $ownership['candidate_novel_id'] ?? null,
                'commit.candidate_novel_id'
            );
            $candidateChapterId = HarnessPersistenceValue::positiveInt(
                $ownership['candidate_chapter_id'] ?? null,
                'commit.candidate_chapter_id'
            );
            $candidateRunId = HarnessPersistenceValue::positiveInt(
                $ownership['candidate_run_id'] ?? null,
                'commit.candidate_run_id'
            );
            $candidateHash = HarnessPersistenceValue::hash(
                $ownership['candidate_content_hash'] ?? null,
                'commit.candidate_content_hash'
            );
            $candidateLifecycle = HarnessPersistenceValue::asciiString(
                $ownership['candidate_lifecycle'] ?? null,
                'commit.candidate_lifecycle',
                1,
                24
            );
            HarnessPersistenceValue::positiveInt(
                $ownership['candidate_draft_artifact_id'] ?? null,
                'commit.candidate_draft_artifact_id'
            );
            if ($candidateId !== $draft->candidateId
                || $candidateUserId !== $runUserId
                || $candidateNovelId !== $runNovelId
                || $candidateChapterId !== $draft->chapterId
                || $candidateRunId !== $draft->runId
                || !hash_equals($candidateHash, $draft->draftHash)
                || !hash_equals($candidateHash, $draft->committedRevisionHash)
                || !in_array($candidateLifecycle, ['approved', 'committed'], true)) {
                throw new HarnessPersistenceException(
                    'Commit Candidate must be approved and belong to the same Run/User/Novel/Chapter.'
                );
            }
        } elseif (($ownership['candidate_id'] ?? null) !== null) {
            throw new HarnessPersistenceException(
                'Legacy commit ownership unexpectedly resolved a Candidate.'
            );
        }

        if ($draft->postprocessJobId !== null) {
            $job = $this->db->fetchOne(
                'SELECT id, novel_id, chapter_id FROM postprocess_jobs WHERE id=? FOR UPDATE',
                [$draft->postprocessJobId]
            );
            if ($job === null
                || HarnessPersistenceValue::positiveInt(
                    $job['id'] ?? null,
                    'postprocess_job_id'
                ) !== $draft->postprocessJobId
                || HarnessPersistenceValue::positiveInt(
                    $job['novel_id'] ?? null,
                    'postprocess_job.novel_id'
                ) !== $runNovelId
                || HarnessPersistenceValue::positiveInt(
                    $job['chapter_id'] ?? null,
                    'postprocess_job.chapter_id'
                ) !== $draft->chapterId) {
                throw new HarnessPersistenceException(
                    'Commit postprocess Job must belong to the same Novel and Chapter.'
                );
            }
        }
    }
}
