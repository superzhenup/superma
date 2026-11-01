<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RunContext.php';
require_once __DIR__ . '/WorkflowRuntimeStore.php';
require_once dirname(__DIR__) . '/persistence/ChapterCommitLedgerRepository.php';

/**
 * Minimal Artifact port used by Canon recovery. The production implementation
 * is LegacyChapterArtifactStore; keeping the port here prevents runtime code
 * from depending on the legacy Stage adapter implementation.
 */
interface CommittedCanonArtifactStore
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function storeCommittedCanonReceipt(RunContext $context, array $payload): array;
}

final readonly class CommittedCanonRecoveryResult
{
    /** @var array<string,mixed> */
    public array $chapter;
    /** @var array<string,mixed> */
    public array $attributePatch;
    /** @var array<string,array<string,mixed>> */
    public array $artifactPatch;

    /**
     * @param array<string,mixed> $chapter
     * @param array<string,mixed> $attributePatch
     * @param array<string,array<string,mixed>> $artifactPatch
     */
    public function __construct(
        public RunContext $context,
        array $chapter,
        array $attributePatch,
        array $artifactPatch,
        public string $candidateUid,
        public int $postprocessJobId
    ) {
        $this->chapter = $chapter;
        $this->attributePatch = $attributePatch;
        $this->artifactPatch = $artifactPatch;
    }
}

/**
 * Reconstruct the post-Canon Run context from the atomic commit receipt.
 *
 * The receipt under commitReceipts['commit'] is the sole recovery authority.
 * Chapter, Candidate, ledger and post-process rows are treated only as proof
 * that the receipt still names the same committed transaction. Missing
 * canonical.receipt output is then recreated through an idempotent Artifact
 * write before post-processing resumes.
 */
final class CommittedCanonRecoveryService
{
    private HarnessPersistenceDriver $db;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        private readonly CommittedCanonArtifactStore $artifacts
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function recover(RunContext $context): CommittedCanonRecoveryResult
    {
        $receipt = $this->receiptFromContext($context);
        $request = $context->request;
        if ($request->chapterId === null
            || $receipt->runId !== $request->runId
            || $receipt->chapterId !== $request->chapterId) {
            throw new WorkflowCheckpointException(
                'Committed Canon receipt does not belong to the active Chapter Run.'
            );
        }

        $ledger = (new ChapterCommitLedgerRepository($this->db))->findByRunAndDraft(
            $receipt->runId,
            $receipt->draftHash
        );
        if ($ledger === null
            || $ledger->candidateId === null
            || $ledger->runId !== $receipt->runId
            || $ledger->chapterId !== $receipt->chapterId
            || !hash_equals($ledger->draftHash, $receipt->draftHash)
            || $ledger->previousRevisionHash !== $receipt->previousRevisionHash
            || !hash_equals(
                $ledger->committedRevisionHash,
                $receipt->committedRevisionHash
            )
            || $ledger->postprocessJobId !== $receipt->postprocessJobId
            || !hash_equals($ledger->committedAt, $receipt->committedAt)) {
            throw new WorkflowCheckpointException(
                'Committed Canon receipt is not authenticated by its exact ledger row.'
            );
        }
        if ($receipt->postprocessJobId === null) {
            throw new WorkflowCheckpointException(
                'Authority Canon receipt has no atomic post-process job.'
            );
        }

        $run = $this->db->fetchOne(
            "SELECT id,run_uid,user_id,novel_id,chapter_id,queue,run_type,
                    workflow_name,workflow_version,authority_mode
             FROM generation_runs WHERE id=? LIMIT 1",
            [$request->runId]
        );
        if ($run === null
            || HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'canon_recovery.run_id')
                !== $request->runId
            || !hash_equals(
                HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'canon_recovery.run_uid'),
                $request->runUid
            )
            || HarnessPersistenceValue::positiveInt(
                $run['user_id'] ?? null,
                'canon_recovery.run_user_id'
            ) !== $request->userId
            || HarnessPersistenceValue::positiveInt(
                $run['novel_id'] ?? null,
                'canon_recovery.run_novel_id'
            ) !== $request->novelId
            || HarnessPersistenceValue::optionalPositiveInt(
                $run['chapter_id'] ?? null,
                'canon_recovery.run_chapter_id'
            ) !== $request->chapterId
            || ($run['queue'] ?? null) !== $request->queue
            || ($run['run_type'] ?? null) !== $request->runType
            || ($run['workflow_name'] ?? null) !== $request->workflowName
            || ($run['workflow_version'] ?? null) !== $request->workflowVersion
            || ($run['authority_mode'] ?? null) !== 'authority') {
            throw new WorkflowCheckpointException(
                'Committed Canon ledger is not owned by the active authority Run.'
            );
        }

        $chapter = $this->db->fetchOne(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
            [$receipt->chapterId, $request->novelId]
        );
        $content = $chapter['content'] ?? null;
        $revisionHash = $chapter['content_revision_hash'] ?? null;
        $candidateUid = $chapter['canonical_candidate_uid'] ?? null;
        $words = HarnessPersistenceValue::nonNegativeInt(
            $chapter['words'] ?? null,
            'canon_recovery.chapter_words'
        );
        if ($chapter === null
            || ($chapter['status'] ?? null) !== 'completed'
            || ($chapter['canonical_state'] ?? null) !== 'canonical'
            || (string)($chapter['claim_token'] ?? '') !== ''
            || !is_string($content)
            || !is_string($revisionHash)
            || !hash_equals($receipt->committedRevisionHash, $revisionHash)
            || !hash_equals($revisionHash, hash('sha256', $content))
            || !is_string($candidateUid)
            || preg_match('/^[a-f0-9]{32}$/D', $candidateUid) !== 1) {
            throw new WorkflowCheckpointException(
                'Committed Canon receipt disagrees with the current Chapter Canon.'
            );
        }

        $candidate = $this->db->fetchOne(
            'SELECT * FROM chapter_candidates WHERE id=? LIMIT 1',
            [$ledger->candidateId]
        );
        $candidateContent = $candidate['inline_content'] ?? null;
        $candidateHash = $candidate['content_hash'] ?? null;
        if ($candidate === null
            || HarnessPersistenceValue::positiveInt(
                $candidate['id'] ?? null,
                'canon_recovery.candidate_id'
            ) !== $ledger->candidateId
            || HarnessPersistenceValue::positiveInt(
                $candidate['user_id'] ?? null,
                'canon_recovery.candidate_user_id'
            ) !== $request->userId
            || HarnessPersistenceValue::positiveInt(
                $candidate['novel_id'] ?? null,
                'canon_recovery.candidate_novel_id'
            ) !== $request->novelId
            || HarnessPersistenceValue::positiveInt(
                $candidate['chapter_id'] ?? null,
                'canon_recovery.candidate_chapter_id'
            ) !== $receipt->chapterId
            || HarnessPersistenceValue::optionalPositiveInt(
                $candidate['run_id'] ?? null,
                'canon_recovery.candidate_run_id'
            ) !== $request->runId
            || ($candidate['candidate_uid'] ?? null) !== $candidateUid
            || ($candidate['source'] ?? null) !== 'initial'
            || ($candidate['lifecycle'] ?? null) !== 'committed'
            || !in_array(($candidate['gate_state'] ?? null), ['passed', 'quarantined'], true)
            || !is_string($candidate['committed_at'] ?? null)
            || !is_string($candidateContent)
            || !is_string($candidateHash)
            || !hash_equals($receipt->draftHash, $candidateHash)
            || !hash_equals($receipt->committedRevisionHash, $candidateHash)
            || !hash_equals($candidateHash, hash('sha256', $candidateContent))
            || !hash_equals($content, $candidateContent)
            || !is_string($candidate['validation_json'] ?? null)
            || (string)$candidate['validation_json'] === '') {
            throw new WorkflowCheckpointException(
                'Committed Canon receipt disagrees with its immutable Candidate.'
            );
        }

        $job = $this->db->fetchOne(
            'SELECT * FROM postprocess_jobs WHERE id=? LIMIT 1',
            [$receipt->postprocessJobId]
        );
        $expectedJobUid = substr(hash(
            'sha256',
            "postprocess_job_v2:candidate:{$candidateUid}:full"
        ), 0, 32);
        if ($job === null
            || HarnessPersistenceValue::positiveInt(
                $job['novel_id'] ?? null,
                'canon_recovery.job_novel_id'
            ) !== $request->novelId
            || HarnessPersistenceValue::positiveInt(
                $job['chapter_id'] ?? null,
                'canon_recovery.job_chapter_id'
            ) !== $receipt->chapterId
            || ($job['stage'] ?? null) !== 'full'
            || !is_string($job['revision_hash'] ?? null)
            || !hash_equals($receipt->committedRevisionHash, $job['revision_hash'])
            || ($job['accepted_candidate_uid'] ?? null) !== $candidateUid
            || ($job['job_uid'] ?? null) !== $expectedJobUid
            || HarnessPersistenceValue::bool(
                $job['rewrite_allowed'] ?? null,
                'canon_recovery.job_rewrite_allowed'
            ) !== true) {
            throw new WorkflowCheckpointException(
                'Committed Canon receipt disagrees with its atomic post-process job.'
            );
        }

        $novel = $this->db->fetchOne(
            'SELECT id,user_id,status FROM novels WHERE id=? LIMIT 1',
            [$request->novelId]
        );
        if ($novel === null
            || HarnessPersistenceValue::positiveInt(
                $novel['user_id'] ?? null,
                'canon_recovery.novel_user_id'
            ) !== $request->userId) {
            throw new WorkflowCheckpointException(
                'Committed Canon owner Novel is missing or changed.'
            );
        }

        $payload = [
            'schema' => 'legacy.canonical.receipt.v1',
            'chapter_id' => $receipt->chapterId,
            'revision_hash' => $receipt->committedRevisionHash,
            'candidate_uid' => $candidateUid,
            'postprocess_job_id' => $receipt->postprocessJobId,
            'words' => $words,
        ];
        $reference = $this->artifacts->storeCommittedCanonReceipt($context, $payload);
        $attributes = [
            'committed_revision_hash' => $receipt->committedRevisionHash,
            'candidate_uid' => $candidateUid,
            'postprocess_job_id' => $receipt->postprocessJobId,
            'words' => $words,
            'all_done' => array_key_exists('all_done', $context->attributes)
                ? HarnessPersistenceValue::bool(
                    $context->attributes['all_done'],
                    'canon_recovery.context_all_done'
                )
                : ($novel['status'] ?? null) === 'completed',
        ];
        $artifacts = ['canonical.receipt' => $reference];
        $this->assertCompatiblePatch($context, $attributes, $artifacts);

        $recoveredContext = new RunContext(
            $context->request,
            $context->budget,
            array_replace($context->attributes, $attributes),
            array_replace($context->artifacts, $artifacts),
            $context->completedStages,
            $context->commitReceipts
        );

        return new CommittedCanonRecoveryResult(
            $recoveredContext,
            $chapter,
            $attributes,
            $artifacts,
            $candidateUid,
            $receipt->postprocessJobId
        );
    }

    private function receiptFromContext(RunContext $context): WorkflowCommitReceipt
    {
        $value = $context->commitReceipts['commit'] ?? null;
        if (!is_array($value)) {
            throw new WorkflowCheckpointException(
                'Post-Canon recovery requires commitReceipts[commit].'
            );
        }
        $expectedFields = [
            'run_id', 'chapter_id', 'draft_hash', 'previous_revision_hash',
            'committed_revision_hash', 'postprocess_job_id', 'committed_at',
        ];
        $actualFields = array_keys($value);
        sort($expectedFields, SORT_STRING);
        sort($actualFields, SORT_STRING);
        if ($actualFields !== $expectedFields) {
            throw new WorkflowCheckpointException(
                'Committed Canon receipt has an unexpected schema.'
            );
        }
        try {
            return new WorkflowCommitReceipt(
                HarnessPersistenceValue::positiveInt(
                    $value['run_id'],
                    'canon_recovery.receipt_run_id'
                ),
                HarnessPersistenceValue::positiveInt(
                    $value['chapter_id'],
                    'canon_recovery.receipt_chapter_id'
                ),
                HarnessPersistenceValue::hash(
                    $value['draft_hash'],
                    'canon_recovery.receipt_draft_hash'
                ),
                HarnessPersistenceValue::optionalHash(
                    $value['previous_revision_hash'],
                    'canon_recovery.receipt_previous_revision_hash'
                ),
                HarnessPersistenceValue::hash(
                    $value['committed_revision_hash'],
                    'canon_recovery.receipt_committed_revision_hash'
                ),
                HarnessPersistenceValue::optionalPositiveInt(
                    $value['postprocess_job_id'],
                    'canon_recovery.receipt_postprocess_job_id'
                ),
                HarnessPersistenceValue::optionalMysqlDate(
                    $value['committed_at'],
                    'canon_recovery.receipt_committed_at'
                ) ?? throw new WorkflowCheckpointException(
                    'Committed Canon receipt has no committed timestamp.'
                )
            );
        } catch (WorkflowRuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WorkflowCheckpointException(
                'Committed Canon receipt is malformed.',
                0,
                $exception
            );
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,array<string,mixed>> $artifacts
     */
    private function assertCompatiblePatch(
        RunContext $context,
        array $attributes,
        array $artifacts
    ): void {
        foreach ($attributes as $key => $value) {
            if (array_key_exists($key, $context->attributes)
                && $context->attributes[$key] !== $value) {
                throw new WorkflowCheckpointException(
                    "Recovered Canon attribute {$key} conflicts with its checkpoint."
                );
            }
        }
        foreach ($artifacts as $key => $reference) {
            if (array_key_exists($key, $context->artifacts)
                && !hash_equals(
                    RuntimeData::hashArray(
                        $context->artifacts[$key],
                        'canon_recovery.existing_artifact'
                    ),
                    RuntimeData::hashArray(
                        $reference,
                        'canon_recovery.recovered_artifact'
                    )
                )) {
                throw new WorkflowCheckpointException(
                    "Recovered Canon Artifact {$key} conflicts with its checkpoint."
                );
            }
        }
    }
}
