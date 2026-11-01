<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WorkflowDefinition.php';
require_once dirname(__DIR__) . '/persistence/bootstrap.php';

enum WorkflowStageAvailability: string
{
    case READY = 'ready';
    case SUCCEEDED = 'succeeded';
    case RETRY_WAIT = 'retry_wait';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case SKIPPED = 'skipped';
}

final readonly class WorkflowStageAcquisition
{
    public ?string $retryAt;
    public ?int $checkpointArtifactId;
    public ?string $checkpointHash;

    public function __construct(
        public string $stageName,
        public int $stepId,
        public int $ordinal,
        public string $inputHash,
        public WorkflowStageAvailability $availability,
        public int $attempt,
        public int $maxAttempts,
        ?string $retryAt = null,
        ?int $checkpointArtifactId = null,
        ?string $checkpointHash = null,
        public bool $recoveryOnly = false
    ) {
        HarnessPersistenceValue::identifier($stageName, 'runtime.stage_name', 64);
        HarnessPersistenceValue::positiveInt($stepId, 'runtime.step_id');
        HarnessPersistenceValue::boundedInt($ordinal, 'runtime.stage_ordinal', 0, 65535);
        HarnessPersistenceValue::hash($inputHash, 'runtime.stage_input_hash');
        HarnessPersistenceValue::nonNegativeInt($attempt, 'runtime.stage_attempt');
        HarnessPersistenceValue::boundedInt(
            $maxAttempts,
            'runtime.stage_max_attempts',
            1,
            100
        );
        $this->retryAt = HarnessPersistenceValue::optionalMysqlDate(
            $retryAt,
            'runtime.stage_retry_at'
        );
        $this->checkpointArtifactId = HarnessPersistenceValue::optionalPositiveInt(
            $checkpointArtifactId,
            'runtime.checkpoint_artifact_id'
        );
        $this->checkpointHash = HarnessPersistenceValue::optionalHash(
            $checkpointHash,
            'runtime.checkpoint_hash'
        );
        if ($availability === WorkflowStageAvailability::READY && $attempt < 1) {
            throw new InvalidArgumentException('A ready Stage requires a durable attempt number.');
        }
        if ($availability === WorkflowStageAvailability::RETRY_WAIT && $this->retryAt === null) {
            throw new InvalidArgumentException('A retry_wait Stage requires retry_at.');
        }
        if ($availability !== WorkflowStageAvailability::RETRY_WAIT && $this->retryAt !== null) {
            throw new InvalidArgumentException('Only retry_wait Stage acquisition may retain retry_at.');
        }
        if (($this->checkpointArtifactId === null) !== ($this->checkpointHash === null)) {
            throw new InvalidArgumentException(
                'Checkpoint Artifact ID and hash must be supplied together.'
            );
        }
        if ($availability === WorkflowStageAvailability::SUCCEEDED
            && $this->checkpointArtifactId === null) {
            throw new InvalidArgumentException(
                'A succeeded Stage must reference its checkpoint Artifact.'
            );
        }
    }
}

enum WorkflowAuthorityOutcomeType: string
{
    case COMPLETED = 'completed';
    case RETRY = 'retry';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case WAITING_APPROVAL = 'waiting_approval';
    case QUARANTINED = 'quarantined';
    case ABORTED_AFTER_COMMIT = 'aborted_after_commit';
}

final readonly class WorkflowAuthorityOutcome
{
    /** @param array<string,mixed> $result */
    private function __construct(
        public WorkflowAuthorityOutcomeType $type,
        public GenerationRunState $state,
        public int $progress,
        public ?DateTimeImmutable $availableAt = null,
        public array $result = [],
        public ?string $errorCode = null
    ) {
        HarnessPersistenceValue::boundedInt($progress, 'runtime.outcome_progress', 0, 100);
        RuntimeData::normalizeArray($result, 'runtime.outcome_result');
        if ($errorCode !== null) {
            HarnessPersistenceValue::identifier($errorCode, 'runtime.outcome_error_code', 64);
        }
        if (($type === WorkflowAuthorityOutcomeType::RETRY) !== ($availableAt !== null)) {
            throw new InvalidArgumentException('Only retry outcome requires available_at.');
        }
        $expectedState = match ($type) {
            WorkflowAuthorityOutcomeType::COMPLETED => GenerationRunState::SUCCEEDED,
            WorkflowAuthorityOutcomeType::RETRY => GenerationRunState::RETRY_WAIT,
            WorkflowAuthorityOutcomeType::FAILED => GenerationRunState::FAILED,
            WorkflowAuthorityOutcomeType::CANCELED => GenerationRunState::CANCELED,
            WorkflowAuthorityOutcomeType::WAITING_APPROVAL => GenerationRunState::WAITING_APPROVAL,
            WorkflowAuthorityOutcomeType::QUARANTINED => GenerationRunState::QUARANTINED,
            WorkflowAuthorityOutcomeType::ABORTED_AFTER_COMMIT => GenerationRunState::ABORTED_AFTER_COMMIT,
        };
        if ($state !== $expectedState) {
            throw new InvalidArgumentException('Authority outcome type and Run state disagree.');
        }
    }

    /** @param array<string,mixed> $result */
    public static function completed(int $progress = 100, array $result = []): self
    {
        return new self(WorkflowAuthorityOutcomeType::COMPLETED, GenerationRunState::SUCCEEDED, $progress, result: $result);
    }

    public static function retry(DateTimeImmutable $availableAt, int $progress, string $errorCode): self
    {
        return new self(WorkflowAuthorityOutcomeType::RETRY, GenerationRunState::RETRY_WAIT, $progress, $availableAt, errorCode: $errorCode);
    }

    public static function failed(int $progress, string $errorCode): self
    {
        return new self(WorkflowAuthorityOutcomeType::FAILED, GenerationRunState::FAILED, $progress, errorCode: $errorCode);
    }

    /** @param array<string,mixed> $result */
    public static function canceled(
        int $progress,
        string $errorCode = RunCanceledException::ERROR_CODE,
        array $result = []
    ): self {
        return new self(WorkflowAuthorityOutcomeType::CANCELED, GenerationRunState::CANCELED, $progress, result: $result, errorCode: $errorCode);
    }

    /** @param array<string,mixed> $result */
    public static function waitingApproval(int $progress, array $result = []): self
    {
        return new self(WorkflowAuthorityOutcomeType::WAITING_APPROVAL, GenerationRunState::WAITING_APPROVAL, $progress, result: $result);
    }

    /** @param array<string,mixed> $result */
    public static function quarantined(int $progress, array $result = []): self
    {
        return new self(WorkflowAuthorityOutcomeType::QUARANTINED, GenerationRunState::QUARANTINED, $progress, result: $result);
    }

    /** @param array<string,mixed> $result */
    public static function abortedAfterCommit(int $progress, array $result = [], ?string $errorCode = null): self
    {
        return new self(WorkflowAuthorityOutcomeType::ABORTED_AFTER_COMMIT, GenerationRunState::ABORTED_AFTER_COMMIT, $progress, result: $result, errorCode: $errorCode);
    }
}

final readonly class WorkflowCommitReceipt
{
    public function __construct(
        public int $runId,
        public int $chapterId,
        public string $draftHash,
        public ?string $previousRevisionHash,
        public string $committedRevisionHash,
        public ?int $postprocessJobId,
        public string $committedAt
    ) {
        HarnessPersistenceValue::positiveInt($runId, 'runtime.commit_run_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'runtime.commit_chapter_id');
        HarnessPersistenceValue::hash($draftHash, 'runtime.commit_draft_hash');
        HarnessPersistenceValue::optionalHash(
            $previousRevisionHash,
            'runtime.commit_previous_revision_hash'
        );
        HarnessPersistenceValue::hash(
            $committedRevisionHash,
            'runtime.commit_revision_hash'
        );
        HarnessPersistenceValue::optionalPositiveInt(
            $postprocessJobId,
            'runtime.commit_postprocess_job_id'
        );
        HarnessPersistenceValue::optionalMysqlDate($committedAt, 'runtime.committed_at')
            ?? throw new InvalidArgumentException('Commit receipt requires committed_at.');
    }

    public static function fromLedger(ChapterCommitLedgerEntry $entry): self
    {
        return new self(
            $entry->runId,
            $entry->chapterId,
            $entry->draftHash,
            $entry->previousRevisionHash,
            $entry->committedRevisionHash,
            $entry->postprocessJobId,
            $entry->committedAt
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'chapter_id' => $this->chapterId,
            'draft_hash' => $this->draftHash,
            'previous_revision_hash' => $this->previousRevisionHash,
            'committed_revision_hash' => $this->committedRevisionHash,
            'postprocess_job_id' => $this->postprocessJobId,
            'committed_at' => $this->committedAt,
        ];
    }
}

/**
 * Consumer-owned persistence port. Production uses the M62 adapter; tests use
 * an in-memory implementation. No Stage receives this service directly.
 */
interface WorkflowRuntimeStore
{
    public function now(): DateTimeImmutable;

    public function assertRun(RunRequest $request): void;

    public function cancellationRequested(): bool;

    public function canRetryRun(): bool;

    public function heartbeat(int $leaseSeconds): void;

    public function acquireStage(
        RunRequest $request,
        RunStage $stage,
        int $ordinal,
        string $inputHash,
        ?CommitBoundary $commitBoundary = null
    ): WorkflowStageAcquisition;

    public function restoreCheckpoint(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        string $inputContextHash
    ): ?RunContext;

    public function findCommit(
        RunRequest $request,
        CommitBoundary $boundary
    ): ?WorkflowCommitReceipt;

    public function commitOutcome(
        RunRequest $request,
        ?WorkflowAuthorityOutcome $outcome,
        ?WorkflowStageAcquisition $stage = null,
        ?string $inputContextHash = null,
        ?RunContext $context = null
    ): void;

    /**
     * Persist the checkpoint Artifact and Step success atomically. If $runState
     * is supplied, release the Run and append its semantic Event in the same
     * outer transaction.
     *
     * @param array<string,mixed> $runResult
     */
    public function completeStage(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        string $inputContextHash,
        RunContext $context,
        ?GenerationRunState $runState = null,
        array $runResult = []
    ): void;

    public function retryStageAndRun(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        DateTimeImmutable $retryAt,
        int $progress,
        string $errorCode
    ): void;

    public function failStageAndRun(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        int $progress,
        string $errorCode
    ): void;

    public function cancelStageAndRun(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        int $progress
    ): void;

    /** @param array<string,mixed> $result */
    public function releaseRun(
        RunRequest $request,
        GenerationRunState $state,
        int $progress,
        ?DateTimeImmutable $availableAt = null,
        array $result = [],
        ?string $errorCode = null
    ): void;
}
