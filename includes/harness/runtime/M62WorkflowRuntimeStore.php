<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WorkflowRuntimeStore.php';
require_once __DIR__ . '/WorkflowFaultPoints.php';
require_once dirname(__DIR__) . '/support/HarnessFaultInjector.php';

/**
 * M62 adapter for the consumer-owned WorkflowRuntimeStore port.
 *
 * Every authority outcome uses one shared HarnessPersistenceDriver and one
 * outer transaction. Repository-local transactions therefore become nested
 * savepoints rather than independent commits.
 */
final class M62WorkflowRuntimeStore implements WorkflowRuntimeStore
{
    private HarnessPersistenceDriver $db;
    private GenerationRunRepository $runs;
    private GenerationStepRepository $steps;
    private GenerationAttemptRepository $attempts;
    private RunArtifactRepository $artifacts;
    private RunEventStore $events;
    private ChapterCommitLedgerRepository $commits;
    private ?GenerationRunLease $runLease;
    private ?GenerationStepLease $stageLease = null;
    private ?WorkflowStageAcquisition $activeStage = null;
    private ?Closure $clock;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        GenerationRunLease $runLease,
        ?callable $clock = null,
        ?callable $stepTokenFactory = null,
        private readonly ?HarnessFaultInjector $faultInjector = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->clock = $clock === null ? null : Closure::fromCallable($clock);
        $this->runs = new GenerationRunRepository($this->db, $clock);
        $this->steps = new GenerationStepRepository(
            $this->db,
            $clock,
            $stepTokenFactory
        );
        $this->attempts = new GenerationAttemptRepository($this->db, $clock);
        $this->artifacts = new RunArtifactRepository($this->db);
        $this->events = new RunEventStore($this->db);
        $this->commits = new ChapterCommitLedgerRepository($this->db);
        $this->runLease = $runLease;
    }

    public function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            $now = ($this->clock)();
            if (!$now instanceof DateTimeImmutable) {
                throw new RuntimeControlException(
                    'M62 Workflow runtime clock must return DateTimeImmutable.'
                );
            }
            return $now->setTimezone(new DateTimeZone('UTC'));
        }

        $row = $this->db->fetchOne('SELECT UTC_TIMESTAMP(6) AS now_utc');
        $value = HarnessPersistenceValue::optionalMysqlDate(
            $row['now_utc'] ?? null,
            'runtime.database_now_utc'
        );
        if ($value === null) {
            throw new HarnessPersistenceException(
                'Workflow runtime database clock did not return UTC_TIMESTAMP(6).'
            );
        }
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public function assertRun(RunRequest $request): void
    {
        $run = $this->requireRunLease()->run;
        $goalJson = HarnessPersistenceValue::canonicalMysqlJson($request->goal);
        if ($run->id !== $request->runId
            || $run->runUid !== $request->runUid
            || $run->userId !== $request->userId
            || $run->novelId !== $request->novelId
            || $run->chapterId !== $request->chapterId
            || $run->queue !== $request->queue
            || $run->runType !== $request->runType
            || $run->workflowName !== $request->workflowName
            || $run->workflowVersion !== $request->workflowVersion
            || $run->authorityMode !== $request->authorityMode
            || $run->idempotencyKey !== $request->idempotencyKey
            || !hash_equals($run->inputHash, $request->inputHash)
            || $run->deadlineAt !== $request->deadlineAt
            || !hash_equals($run->goalJson, $goalJson)) {
            throw new WorkflowCheckpointException(
                'M62 Run lease does not match the immutable Workflow request.'
            );
        }
    }

    public function cancellationRequested(): bool
    {
        $lease = $this->requireRunLease();
        $current = $this->runs->findByUid($lease->run->runUid);
        return $current === null
            || $current->cancelRequested
            || $current->state === GenerationRunState::CANCELED;
    }

    public function canRetryRun(): bool
    {
        $run = $this->requireRunLease()->run;
        return $run->attempt < $run->maxAttempts;
    }

    public function heartbeat(int $leaseSeconds): void
    {
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'runtime.lease_seconds',
            15,
            3600
        );
        $runLease = $this->requireRunLease();
        $stageLease = $this->stageLease;

        try {
            [$renewedRun, $renewedStage] = $this->db->transactional(
                function () use ($runLease, $stageLease, $leaseSeconds): array {
                    $renewedRun = $this->runs->renew($runLease, $leaseSeconds);
                    $renewedStage = $stageLease === null
                        ? null
                        : $this->steps->renew($stageLease, $leaseSeconds);
                    return [$renewedRun, $renewedStage];
                }
            );
        } catch (HarnessLeaseLostException $exception) {
            throw new LeaseLostException(previous: $exception);
        }

        $this->runLease = $renewedRun;
        $this->stageLease = $renewedStage;
    }

    public function acquireStage(
        RunRequest $request,
        RunStage $stage,
        int $ordinal,
        string $inputHash,
        ?CommitBoundary $commitBoundary = null
    ): WorkflowStageAcquisition {
        $this->assertRun($request);
        if ($this->stageLease !== null || $this->activeStage !== null) {
            throw new WorkflowCheckpointException(
                'Workflow runtime attempted to acquire two Stages concurrently.'
            );
        }
        $inputHash = HarnessPersistenceValue::hash($inputHash, 'runtime.stage_input_hash');
        $stageName = HarnessPersistenceValue::identifier(
            $stage->name(),
            'runtime.stage_name',
            64
        );
        $ordinal = HarnessPersistenceValue::boundedInt(
            $ordinal,
            'runtime.stage_ordinal',
            0,
            65535
        );

        try {
            [$acquisition, $lease] = $this->db->transactional(
                function () use (
                    $request,
                    $stage,
                    $stageName,
                    $ordinal,
                    $inputHash,
                    $commitBoundary
                ): array {
                    $executionMaxAttempts = $stage->retryPolicy()->maxAttempts;
                    if ($commitBoundary !== null && $executionMaxAttempts >= 100) {
                        throw new WorkflowDefinitionException(
                            'Commit Stage retry policy must reserve one durable recovery claim.'
                        );
                    }
                    $durableMaxAttempts = $commitBoundary === null
                        ? $executionMaxAttempts
                        : $executionMaxAttempts + 1;
                    $step = $this->steps->createIdempotent(new GenerationStepDraft(
                        $request->runId,
                        $stageName,
                        0,
                        $ordinal,
                        $stage->type(),
                        $inputHash,
                        $durableMaxAttempts,
                        [
                            'schema' => 'harness.runtime.retry_policy.v1',
                            'workflow_name' => $request->workflowName,
                            'workflow_version' => $request->workflowVersion,
                            'execution_max_attempts' => $executionMaxAttempts,
                            'commit_recovery_claim_reserved' => $commitBoundary !== null,
                            'policy' => $stage->retryPolicy()->toArray(),
                        ]
                    ));

                    $terminal = $this->acquisitionFromStep($step, $ordinal);
                    if ($terminal !== null) {
                        return [$terminal, null];
                    }

                    $lease = $this->steps->claimWithEvents($step->id);
                    if ($lease === null) {
                        $current = $this->steps->find($step->id);
                        if ($current === null) {
                            throw new WorkflowCheckpointException(
                                'Generation Step disappeared during acquisition.'
                            );
                        }
                        $waiting = $this->acquisitionFromStep($current, $ordinal);
                        if ($waiting !== null) {
                            return [$waiting, null];
                        }
                        throw new LeaseLostException(
                            'Generation Step is owned by another unexpired lease.'
                        );
                    }

                    $ready = new WorkflowStageAcquisition(
                        $stageName,
                        $lease->step->id,
                        $ordinal,
                        $inputHash,
                        WorkflowStageAvailability::READY,
                        $lease->attempt,
                        $lease->step->maxAttempts,
                        recoveryOnly: $this->requireRunLease()->run->attempt
                            > $this->requireRunLease()->run->maxAttempts
                    );
                    $this->appendStageEvent(
                        $ready,
                        'step.started',
                        'started',
                        GenerationStepState::RUNNING->value,
                        GenerationEventSeverity::INFO
                    );
                    return [$ready, $lease];
                }
            );
        } catch (HarnessLeaseLostException $exception) {
            throw new LeaseLostException(previous: $exception);
        }

        $this->activeStage = $acquisition->availability === WorkflowStageAvailability::READY
            ? $acquisition
            : null;
        $this->stageLease = $lease;
        return $acquisition;
    }

    public function restoreCheckpoint(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        string $inputContextHash
    ): ?RunContext {
        $this->assertRun($request);
        $inputContextHash = HarnessPersistenceValue::hash(
            $inputContextHash,
            'runtime.input_context_hash'
        );
        $artifact = $this->artifacts->findByUid(
            self::checkpointUid($request->runUid, $stage->stageName)
        );
        if ($artifact === null) {
            return null;
        }
        if ($artifact->runId !== $request->runId
            || $artifact->stepId !== $stage->stepId
            || $artifact->artifactType !== 'runtime.checkpoint'
            || $artifact->logicalKey !== self::checkpointLogicalKey($stage->stageName)
            || $artifact->revisionNo !== 1
            || $artifact->storageKind !== GenerationArtifactStorageKind::INLINE
            || $artifact->inlineJson === null) {
            throw new WorkflowCheckpointException(
                'Runtime checkpoint Artifact has incompatible durable coordinates.'
            );
        }
        if ($stage->checkpointArtifactId !== null
            && ($artifact->id !== $stage->checkpointArtifactId
                || $stage->checkpointHash === null
                || !hash_equals($artifact->contentHash, $stage->checkpointHash))) {
            throw new WorkflowCheckpointException(
                'Succeeded Step does not reference its deterministic checkpoint Artifact.'
            );
        }

        $envelope = HarnessPersistenceValue::decodeJsonArray(
            $artifact->inlineJson,
            'runtime.checkpoint_artifact'
        );
        RuntimeData::normalizeArray($envelope, 'runtime.checkpoint_artifact');
        foreach ([
            'schema', 'run_uid', 'workflow_name', 'workflow_version', 'stage_name',
            'step_id', 'ordinal', 'stage_input_hash', 'input_context_hash',
            'output_context_hash', 'context',
        ] as $field) {
            if (!array_key_exists($field, $envelope)) {
                throw new WorkflowCheckpointException(
                    'Runtime checkpoint Artifact is missing a required field.'
                );
            }
        }
        if ($envelope['schema'] !== 'harness.runtime.checkpoint.v1'
            || $envelope['run_uid'] !== $request->runUid
            || $envelope['workflow_name'] !== $request->workflowName
            || $envelope['workflow_version'] !== $request->workflowVersion
            || $envelope['stage_name'] !== $stage->stageName
            || $envelope['step_id'] !== $stage->stepId
            || $envelope['ordinal'] !== $stage->ordinal
            || $envelope['stage_input_hash'] !== $stage->inputHash
            || $envelope['input_context_hash'] !== $inputContextHash
            || !is_array($envelope['context'])) {
            throw new WorkflowCheckpointException(
                'Runtime checkpoint Artifact does not match its Stage input lineage.'
            );
        }

        $context = RunContext::fromArray($envelope['context']);
        $context->assertForRequest($request);
        $outputHash = HarnessPersistenceValue::hash(
            $envelope['output_context_hash'],
            'runtime.output_context_hash'
        );
        if (!hash_equals($context->hash(), $outputHash)
            || !$context->hasCompletedStage($stage->stageName)) {
            throw new WorkflowCheckpointException(
                'Runtime checkpoint context hash or completed Stage marker is invalid.'
            );
        }
        return $context;
    }

    public function findCommit(
        RunRequest $request,
        CommitBoundary $boundary
    ): ?WorkflowCommitReceipt {
        $this->assertRun($request);
        if ($request->chapterId === null
            || $request->chapterId !== $boundary->chapterId) {
            throw new CommitBoundaryException(
                'Commit boundary Chapter does not match the active Run request.'
            );
        }
        $entry = $this->commits->findByRunAndDraft($request->runId, $boundary->draftHash);
        if ($entry === null) {
            return null;
        }
        $receipt = WorkflowCommitReceipt::fromLedger($entry);
        if ($receipt->chapterId !== $boundary->chapterId) {
            throw new CommitBoundaryException(
                'Commit ledger entry belongs to a different Chapter.'
            );
        }
        return $receipt;
    }

    public function commitOutcome(
        RunRequest $request,
        ?WorkflowAuthorityOutcome $outcome,
        ?WorkflowStageAcquisition $stage = null,
        ?string $inputContextHash = null,
        ?RunContext $context = null
    ): void {
        if ($stage !== null && $outcome === null) {
            if ($inputContextHash === null || $context === null) {
                throw new InvalidArgumentException('Checkpoint-only outcome requires context and input hash.');
            }
            $this->completeStage($request, $stage, $inputContextHash, $context);
            return;
        }
        if (!$outcome instanceof WorkflowAuthorityOutcome) {
            throw new InvalidArgumentException('Run outcome is required without a checkpoint-only Stage.');
        }
        if ($stage !== null) {
            if ($outcome->type === WorkflowAuthorityOutcomeType::RETRY) {
                if ($outcome->availableAt === null || $outcome->errorCode === null) {
                    throw new LogicException('Retry outcome is incomplete.');
                }
                $this->retryStageAndRun(
                    $request,
                    $stage,
                    $outcome->availableAt,
                    $outcome->progress,
                    $outcome->errorCode
                );
                return;
            }
            if ($outcome->type === WorkflowAuthorityOutcomeType::FAILED) {
                if ($outcome->errorCode === null) {
                    throw new LogicException('Failed outcome is incomplete.');
                }
                $this->failStageAndRun(
                    $request,
                    $stage,
                    $outcome->progress,
                    $outcome->errorCode
                );
                return;
            }
            if ($outcome->type === WorkflowAuthorityOutcomeType::CANCELED && $context === null) {
                $this->cancelStageAndRun($request, $stage, $outcome->progress);
                return;
            }
            if ($inputContextHash === null || $context === null) {
                throw new InvalidArgumentException('Checkpoint outcome requires context and input hash.');
            }
            $this->completeStage(
                $request,
                $stage,
                $inputContextHash,
                $context,
                $outcome->state,
                $outcome->result
            );
            return;
        }
        if ($inputContextHash !== null || $context !== null) {
            throw new InvalidArgumentException('Run-only outcome cannot include checkpoint context.');
        }
        $this->releaseRun(
            $request,
            $outcome->state,
            $outcome->progress,
            $outcome->availableAt,
            $outcome->result,
            $outcome->errorCode
        );
    }

    public function completeStage(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        string $inputContextHash,
        RunContext $context,
        ?GenerationRunState $runState = null,
        array $runResult = []
    ): void {
        $this->assertRun($request);
        $lease = $this->requireActiveStage($stage);
        $context->assertForRequest($request);
        if (!$context->hasCompletedStage($stage->stageName)) {
            throw new WorkflowCheckpointException(
                'Completed Stage context is missing its durable completion marker.'
            );
        }
        $inputContextHash = HarnessPersistenceValue::hash(
            $inputContextHash,
            'runtime.input_context_hash'
        );
        RuntimeData::normalizeArray($runResult, 'runtime.run_result');
        if ($runState !== null && !in_array($runState, [
            GenerationRunState::SUCCEEDED,
            GenerationRunState::WAITING_APPROVAL,
            GenerationRunState::QUARANTINED,
            GenerationRunState::ABORTED_AFTER_COMMIT,
            GenerationRunState::CANCELED,
        ], true)) {
            throw new InvalidArgumentException(
                'Completed Stage cannot release the Run to the requested state.'
            );
        }

        try {
            $this->db->transactional(function () use (
                $request,
                $stage,
                $lease,
                $inputContextHash,
                $context,
                $runState,
                $runResult
            ): void {
                $artifact = $this->artifacts->storeIdempotent(
                    $this->checkpointDraft(
                        $request,
                        $stage,
                        $inputContextHash,
                        $context
                    )
                );
                $this->persistGenerationAttempt(
                    $request,
                    $stage,
                    $lease,
                    $context,
                    $artifact
                );
                $this->fault(WorkflowFaultPoints::OUTCOME_AFTER_ARTIFACT, $stage);

                $this->steps->release($lease, new GenerationStepTransition(
                    $stage->stepId,
                    GenerationStepState::RUNNING,
                    GenerationStepState::SUCCEEDED,
                    100,
                    $artifact->contentHash,
                    $artifact->id
                ));
                $this->fault(WorkflowFaultPoints::OUTCOME_AFTER_STEP, $stage);
                $this->appendStageEvent(
                    $stage,
                    'step.completed',
                    'completed',
                    GenerationStepState::SUCCEEDED->value,
                    GenerationEventSeverity::INFO,
                    ['checkpoint_hash' => $artifact->contentHash]
                );
                $this->fault(WorkflowFaultPoints::OUTCOME_AFTER_EVENT, $stage);

                if ($runState !== null) {
                    $this->fault(WorkflowFaultPoints::BEFORE_RUN_RELEASE, $stage);
                    $progress = $runState === GenerationRunState::SUCCEEDED ? 100 : 99;
                    $released = $this->runs->release(
                        $this->requireRunLease(),
                        $runState,
                        $progress,
                        null,
                        $runResult
                    );
                    $this->appendRunEvent($released, $runState, $stage->stageName);
                }
            });
        } catch (HarnessLeaseLostException $exception) {
            throw new LeaseLostException(previous: $exception);
        }

        $this->stageLease = null;
        $this->activeStage = null;
        if ($runState !== null) {
            $this->runLease = null;
        }
    }

    public function retryStageAndRun(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        DateTimeImmutable $retryAt,
        int $progress,
        string $errorCode
    ): void {
        $this->assertRun($request);
        $lease = $this->requireActiveStage($stage);
        $errorCode = HarnessPersistenceValue::identifier(
            $errorCode,
            'runtime.error_code',
            64
        );
        $progress = HarnessPersistenceValue::boundedInt($progress, 'runtime.progress', 0, 99);
        $retryAt = $retryAt->setTimezone(new DateTimeZone('UTC'));

        try {
            $this->db->transactional(function () use (
                $stage,
                $lease,
                $retryAt,
                $progress,
                $errorCode
            ): void {
                $this->steps->release($lease, new GenerationStepTransition(
                    $stage->stepId,
                    GenerationStepState::RUNNING,
                    GenerationStepState::RETRY_WAIT,
                    $progress,
                    retryAt: $retryAt,
                    errorCode: $errorCode,
                    errorMessage: 'Stage attempt failed and will retry.'
                ));
                $this->appendStageEvent(
                    $stage,
                    'step.failed',
                    'retry_wait',
                    GenerationStepState::RETRY_WAIT->value,
                    GenerationEventSeverity::WARNING,
                    ['error_code' => $errorCode]
                );
                $this->fault(WorkflowFaultPoints::BEFORE_RUN_RELEASE, $stage);
                $released = $this->runs->release(
                    $this->requireRunLease(),
                    GenerationRunState::RETRY_WAIT,
                    $progress,
                    $retryAt,
                    null,
                    $errorCode,
                    'Workflow Stage failed and will retry.'
                );
                $this->appendRunEvent(
                    $released,
                    GenerationRunState::RETRY_WAIT,
                    $stage->stageName,
                    $errorCode
                );
            });
        } catch (HarnessLeaseLostException $exception) {
            throw new LeaseLostException(previous: $exception);
        }

        $this->stageLease = null;
        $this->activeStage = null;
        $this->runLease = null;
    }

    public function failStageAndRun(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        int $progress,
        string $errorCode
    ): void {
        $this->finishFailedStageAndRun(
            $request,
            $stage,
            GenerationStepState::FAILED,
            GenerationRunState::FAILED,
            $progress,
            $errorCode
        );
    }

    public function cancelStageAndRun(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        int $progress
    ): void {
        $this->finishFailedStageAndRun(
            $request,
            $stage,
            GenerationStepState::CANCELED,
            GenerationRunState::CANCELED,
            $progress,
            null
        );
    }

    public function releaseRun(
        RunRequest $request,
        GenerationRunState $state,
        int $progress,
        ?DateTimeImmutable $availableAt = null,
        array $result = [],
        ?string $errorCode = null
    ): void {
        $this->assertRun($request);
        if ($this->stageLease !== null) {
            throw new WorkflowCheckpointException(
                'Run-only release cannot abandon an active Stage lease.'
            );
        }
        $progress = HarnessPersistenceValue::boundedInt($progress, 'runtime.progress', 0, 100);
        RuntimeData::normalizeArray($result, 'runtime.run_result');
        $errorCode = $errorCode === null
            ? null
            : HarnessPersistenceValue::identifier($errorCode, 'runtime.error_code', 64);

        try {
            $this->db->transactional(function () use (
                $state,
                $progress,
                $availableAt,
                $result,
                $errorCode
            ): void {
                $released = $this->runs->release(
                    $this->requireRunLease(),
                    $state,
                    $progress,
                    $availableAt,
                    $result,
                    $errorCode,
                    self::runErrorMessage($state, $errorCode)
                );
                $this->appendRunEvent($released, $state, null, $errorCode);
            });
        } catch (HarnessLeaseLostException $exception) {
            throw new LeaseLostException(previous: $exception);
        }
        $this->runLease = null;
    }

    private function finishFailedStageAndRun(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        GenerationStepState $stepState,
        GenerationRunState $runState,
        int $progress,
        ?string $errorCode
    ): void {
        $this->assertRun($request);
        $lease = $this->requireActiveStage($stage);
        $progress = HarnessPersistenceValue::boundedInt($progress, 'runtime.progress', 0, 99);
        $errorCode = $errorCode === null
            ? null
            : HarnessPersistenceValue::identifier($errorCode, 'runtime.error_code', 64);

        try {
            $this->db->transactional(function () use (
                $stage,
                $lease,
                $stepState,
                $runState,
                $progress,
                $errorCode
            ): void {
                $this->steps->release($lease, new GenerationStepTransition(
                    $stage->stepId,
                    GenerationStepState::RUNNING,
                    $stepState,
                    $progress,
                    errorCode: $errorCode,
                    errorMessage: $errorCode === null ? null : 'Workflow Stage failed permanently.'
                ));
                $this->appendStageEvent(
                    $stage,
                    $stepState === GenerationStepState::CANCELED
                        ? 'step.canceled'
                        : 'step.failed',
                    $stepState->value,
                    $stepState->value,
                    $stepState === GenerationStepState::CANCELED
                        ? GenerationEventSeverity::INFO
                        : GenerationEventSeverity::ERROR,
                    $errorCode === null ? [] : ['error_code' => $errorCode]
                );
                $this->fault(WorkflowFaultPoints::BEFORE_RUN_RELEASE, $stage);
                $released = $this->runs->release(
                    $this->requireRunLease(),
                    $runState,
                    $progress,
                    null,
                    null,
                    $errorCode,
                    self::runErrorMessage($runState, $errorCode)
                );
                $this->appendRunEvent($released, $runState, $stage->stageName, $errorCode);
            });
        } catch (HarnessLeaseLostException $exception) {
            throw new LeaseLostException(previous: $exception);
        }

        $this->stageLease = null;
        $this->activeStage = null;
        $this->runLease = null;
    }

    private function checkpointDraft(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        string $inputContextHash,
        RunContext $context
    ): GenerationArtifactDraft {
        return GenerationArtifactDraft::inlineJson(
            self::checkpointUid($request->runUid, $stage->stageName),
            $request->runId,
            $stage->stepId,
            'runtime.checkpoint',
            self::checkpointLogicalKey($stage->stageName),
            1,
            [
                'schema' => 'harness.runtime.checkpoint.v1',
                'run_uid' => $request->runUid,
                'workflow_name' => $request->workflowName,
                'workflow_version' => $request->workflowVersion,
                'stage_name' => $stage->stageName,
                'step_id' => $stage->stepId,
                'ordinal' => $stage->ordinal,
                'stage_input_hash' => $stage->inputHash,
                'input_context_hash' => $inputContextHash,
                'output_context_hash' => $context->hash(),
                'context' => $context->toArray(),
            ],
            GenerationArtifactSensitivity::INTERNAL,
            [
                'schema' => 'harness.runtime.checkpoint.v1',
                'workflow_name' => $request->workflowName,
                'workflow_version' => $request->workflowVersion,
                'stage_name' => $stage->stageName,
            ]
        );
    }

    /**
     * Persist the selected Chapter model call in the same transaction as its
     * Generate checkpoint and Step release. Older recovered checkpoints may
     * legitimately lack this evidence; malformed present evidence fails
     * closed, while absence remains observable as incomplete Attempt coverage.
     */
    private function persistGenerationAttempt(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        GenerationStepLease $lease,
        RunContext $context,
        GenerationArtifact $checkpoint
    ): void {
        if ($request->runType !== 'chapter.generate'
            || $request->workflowName !== 'chapter_generate'
            || $stage->stageName !== 'generate') {
            return;
        }
        $observation = $context->attributes['generation_attempt_observation'] ?? null;
        if ($observation === null) {
            return;
        }
        if (!is_array($observation)) {
            throw new WorkflowCheckpointException(
                'Generate checkpoint Attempt observation must be an object.'
            );
        }
        $expectedKeys = [
            'cache_tokens', 'latency_ms', 'model', 'model_config', 'provider', 'schema',
        ];
        $actualKeys = array_keys($observation);
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys
            || ($observation['schema'] ?? null)
                !== 'harness.generation_attempt.observation.v1'
            || !is_array($observation['model_config'] ?? null)) {
            throw new WorkflowCheckpointException(
                'Generate checkpoint Attempt observation is malformed.'
            );
        }

        $completed = $context->completedStages[$stage->stageName] ?? null;
        $summary = is_array($completed) ? ($completed['summary'] ?? null) : null;
        if (!is_array($summary)) {
            throw new WorkflowCheckpointException(
                'Generate checkpoint is missing its completed Stage summary.'
            );
        }
        $inputTokens = HarnessPersistenceValue::nonNegativeInt(
            $summary['input_tokens'] ?? null,
            'runtime.generate_attempt.input_tokens'
        );
        $outputTokens = HarnessPersistenceValue::nonNegativeInt(
            $summary['output_tokens'] ?? null,
            'runtime.generate_attempt.output_tokens'
        );
        $cost = HarnessPersistenceValue::decimal(
            $summary['cost'] ?? null,
            'runtime.generate_attempt.cost'
        );
        $cacheTokens = HarnessPersistenceValue::nonNegativeInt(
            $observation['cache_tokens'] ?? null,
            'runtime.generate_attempt.cache_tokens'
        );
        $latencyMs = HarnessPersistenceValue::nonNegativeInt(
            $observation['latency_ms'] ?? null,
            'runtime.generate_attempt.latency_ms'
        );

        $draft = new GenerationAttemptDraft(
            $stage->stepId,
            $stage->attempt,
            HarnessPersistenceValue::identifier(
                $observation['provider'] ?? null,
                'runtime.generate_attempt.provider',
                64
            ),
            HarnessPersistenceValue::string(
                $observation['model'] ?? null,
                'runtime.generate_attempt.model',
                1,
                191
            ),
            RuntimeData::normalizeArray(
                $observation['model_config'],
                'runtime.generate_attempt.model_config'
            )
        );
        $attempt = $this->attempts->createIdempotent($lease, $draft);
        if ($attempt->state === GenerationAttemptState::RUNNING) {
            $this->attempts->finish($lease, new GenerationAttemptResult(
                $attempt->id,
                GenerationAttemptState::COMPLETED,
                $checkpoint->id,
                $inputTokens,
                $outputTokens,
                $cacheTokens,
                $cost,
                $latencyMs
            ));
            return;
        }
        if ($attempt->state !== GenerationAttemptState::COMPLETED
            || $attempt->outputArtifactId !== $checkpoint->id
            || $attempt->inputTokens !== $inputTokens
            || $attempt->outputTokens !== $outputTokens
            || $attempt->cacheTokens !== $cacheTokens
            || $attempt->cost !== $cost
            || $attempt->latencyMs !== $latencyMs) {
            throw new HarnessIdempotencyConflictException(
                'Generate checkpoint resolved to a different terminal Attempt result.'
            );
        }
    }

    private function acquisitionFromStep(
        GenerationStep $step,
        int $ordinal
    ): ?WorkflowStageAcquisition {
        $availability = match ($step->state) {
            GenerationStepState::SUCCEEDED => WorkflowStageAvailability::SUCCEEDED,
            GenerationStepState::RETRY_WAIT => $step->retryAt !== null
                && $step->retryAt <= HarnessPersistenceValue::mysqlDate($this->now())
                    ? null
                    : WorkflowStageAvailability::RETRY_WAIT,
            GenerationStepState::FAILED => WorkflowStageAvailability::FAILED,
            GenerationStepState::CANCELED => WorkflowStageAvailability::CANCELED,
            GenerationStepState::SKIPPED => WorkflowStageAvailability::SKIPPED,
            default => null,
        };
        if ($availability === null) {
            return null;
        }
        if ($step->inputHash === null) {
            throw new WorkflowCheckpointException('Workflow Step is missing its input hash.');
        }
        return new WorkflowStageAcquisition(
            $step->stepKey,
            $step->id,
            $ordinal,
            $step->inputHash,
            $availability,
            $step->attempt,
            $step->maxAttempts,
            $step->retryAt,
            $step->outputArtifactId,
            $step->outputHash
        );
    }

    private function appendStageEvent(
        WorkflowStageAcquisition $stage,
        string $eventType,
        string $suffix,
        string $state,
        GenerationEventSeverity $severity,
        array $extra = []
    ): void {
        $runLease = $this->requireRunLease();
        $payload = array_merge([
            'stage_name' => $stage->stageName,
            'ordinal' => $stage->ordinal,
            'attempt' => $stage->attempt,
            'state' => $state,
        ], $extra);
        $this->events->append(new GenerationEventDraft(
            $runLease->run->id,
            $stage->stepId,
            null,
            $eventType,
            1,
            $severity,
            'runtime:step:' . $stage->stepId . ':attempt:' . $stage->attempt . ':' . $suffix,
            'workflow_runner',
            $runLease->owner,
            null,
            true,
            $payload
        ));
    }

    private function appendRunEvent(
        GenerationRun $run,
        GenerationRunState $state,
        ?string $stageName,
        ?string $errorCode = null
    ): void {
        $eventType = match ($state) {
            GenerationRunState::SUCCEEDED,
            GenerationRunState::ABORTED_AFTER_COMMIT => 'run.completed',
            GenerationRunState::CANCELED => 'run.canceled',
            GenerationRunState::FAILED => 'run.failed',
            GenerationRunState::WAITING_APPROVAL => 'approval.requested',
            GenerationRunState::QUARANTINED => 'canonical.quarantined',
            GenerationRunState::RETRY_WAIT => 'run.retry_scheduled',
            default => throw new InvalidArgumentException(
                'Run Event state is not an outcome boundary.'
            ),
        };
        $severity = in_array($state, [
            GenerationRunState::FAILED,
            GenerationRunState::QUARANTINED,
        ], true) ? GenerationEventSeverity::ERROR : GenerationEventSeverity::INFO;
        $payload = ['state' => $state->value, 'progress' => $run->progress];
        if ($stageName !== null) {
            $payload['stage_name'] = $stageName;
        }
        if ($errorCode !== null) {
            $payload['error_code'] = $errorCode;
        }
        $this->events->append(new GenerationEventDraft(
            $run->id,
            null,
            null,
            $eventType,
            1,
            $severity,
            'runtime:run:' . $run->attempt . ':' . $state->value,
            'workflow_runner',
            null,
            null,
            true,
            $payload
        ));
    }

    private function requireRunLease(): GenerationRunLease
    {
        if (!$this->runLease instanceof GenerationRunLease) {
            throw new LeaseLostException('Workflow Run lease is no longer active.');
        }
        return $this->runLease;
    }

    private function requireActiveStage(
        WorkflowStageAcquisition $stage
    ): GenerationStepLease {
        if (!$this->stageLease instanceof GenerationStepLease
            || !$this->activeStage instanceof WorkflowStageAcquisition
            || $stage->availability !== WorkflowStageAvailability::READY
            || $this->activeStage->stepId !== $stage->stepId
            || $this->activeStage->attempt !== $stage->attempt
            || $this->activeStage->inputHash !== $stage->inputHash
            || $this->stageLease->step->id !== $stage->stepId
            || $this->stageLease->attempt !== $stage->attempt) {
            throw new LeaseLostException('Workflow Stage lease is no longer active.');
        }
        return $this->stageLease;
    }

    private function fault(string $point, WorkflowStageAcquisition $stage): void
    {
        $this->faultInjector?->hit($point, [
            'run_id' => $this->requireRunLease()->run->id,
            'step_id' => $stage->stepId,
            'stage_name' => $stage->stageName,
            'attempt' => $stage->attempt,
        ]);
    }

    private static function checkpointUid(string $runUid, string $stageName): string
    {
        return substr(hash('sha256', 'runtime-checkpoint-v1:' . $runUid . ':' . $stageName), 0, 32);
    }

    private static function checkpointLogicalKey(string $stageName): string
    {
        return 'runtime.checkpoint.' . $stageName;
    }

    private static function runErrorMessage(
        GenerationRunState $state,
        ?string $errorCode
    ): ?string {
        if ($errorCode === null) {
            return null;
        }
        return match ($state) {
            GenerationRunState::RETRY_WAIT => 'Workflow execution will retry after a controlled failure.',
            GenerationRunState::FAILED => 'Workflow execution failed permanently.',
            default => null,
        };
    }
}
