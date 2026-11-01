<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WorkflowRuntimeStore.php';
require_once __DIR__ . '/WorkflowFaultPoints.php';
require_once dirname(__DIR__) . '/support/HarnessFaultInjector.php';

final readonly class WorkflowExecutionResult
{
    public ?string $retryAt;
    public ?string $errorCode;

    public function __construct(
        public GenerationRunState $state,
        public RunContext $context,
        public ?string $stageName = null,
        ?string $retryAt = null,
        ?string $errorCode = null,
        public bool $commitRecovered = false
    ) {
        $this->retryAt = HarnessPersistenceValue::optionalMysqlDate(
            $retryAt,
            'workflow_result.retry_at'
        );
        $this->errorCode = HarnessPersistenceValue::optionalString(
            $errorCode,
            'workflow_result.error_code',
            64
        );
        if ($this->errorCode !== null) {
            HarnessPersistenceValue::identifier(
                $this->errorCode,
                'workflow_result.error_code',
                64
            );
        }
        if ($stageName !== null) {
            HarnessPersistenceValue::identifier(
                $stageName,
                'workflow_result.stage_name',
                64
            );
        }
        if ($state === GenerationRunState::RETRY_WAIT && $this->retryAt === null) {
            throw new InvalidArgumentException('retry_wait Workflow result requires retry_at.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'stage_name' => $this->stageName,
            'retry_at' => $this->retryAt,
            'error_code' => $this->errorCode,
            'commit_recovered' => $this->commitRecovered,
            'context_hash' => $this->context->hash(),
        ];
    }
}

final class WorkflowRunner
{
    private const DEFAULT_LEASE_SECONDS = 120;

    public function __construct(
        private readonly WorkflowDefinition $workflow,
        private readonly WorkflowRuntimeStore $store,
        private readonly ?CancellationToken $cancellation = null,
        private readonly ?Deadline $deadline = null,
        private readonly ?LeaseHeartbeat $heartbeat = null,
        private readonly ?HarnessFaultInjector $faultInjector = null,
        private readonly int $leaseSeconds = self::DEFAULT_LEASE_SECONDS
    ) {
        HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'runtime.lease_seconds',
            15,
            3600
        );
    }

    public function run(
        RunRequest $request,
        ?RunContext $baseContext = null
    ): WorkflowExecutionResult {
        $this->workflow->assertRequest($request);
        $this->store->assertRun($request);

        $context = $baseContext ?? RunContext::initial($request);
        $context->assertForRequest($request);
        if ($context->completedStages !== []) {
            throw new WorkflowCheckpointException(
                'WorkflowRunner requires the pre-Stage base context and restores checkpoints itself.'
            );
        }

        $cancellation = $this->cancellation ?? new CancellationToken(
            fn (): bool => $this->store->cancellationRequested()
        );
        $deadline = $this->deadline ?? Deadline::fromRequest(
            $request,
            fn (): DateTimeImmutable => $this->store->now()
        );
        $heartbeat = $this->heartbeat ?? new LeaseHeartbeat(
            function (): void {
                $this->store->heartbeat($this->leaseSeconds);
            },
            max(1, intdiv($this->leaseSeconds, 3)),
            fn (): DateTimeImmutable => $this->store->now()
        );

        $preflight = $this->preflightWithoutStage(
            $request,
            $context,
            $cancellation,
            $deadline,
            $heartbeat
        );
        if ($preflight !== null) {
            return $preflight;
        }

        $stages = $this->workflow->stages();
        $stageCount = count($stages);
        $recoveredAnyCommit = false;

        foreach ($stages as $ordinal => $stage) {
            $stageName = HarnessPersistenceValue::identifier(
                $stage->name(),
                'runtime.stage_name',
                64
            );
            $inputContextHash = $context->hash();
            $inputHash = HarnessPersistenceValue::hash(
                $stage->inputHash($context),
                'runtime.stage_input_hash'
            );
            $boundary = $stage->commitBoundary($context);
            $acquisition = $this->store->acquireStage(
                $request,
                $stage,
                $ordinal,
                $inputHash,
                $boundary
            );

            if ($acquisition->stageName !== $stageName
                || $acquisition->ordinal !== $ordinal
                || !hash_equals($acquisition->inputHash, $inputHash)) {
                throw new WorkflowCheckpointException(
                    'Stage acquisition does not match the deterministic Workflow definition.'
                );
            }

            $existing = $this->handleExistingStage(
                $request,
                $context,
                $acquisition,
                $inputContextHash,
                $ordinal,
                $stageCount,
                $recoveredAnyCommit
            );
            if ($existing instanceof WorkflowExecutionResult) {
                return $existing;
            }
            if ($existing instanceof RunContext) {
                $context = $existing;
                continue;
            }

            $checkpoint = $this->store->restoreCheckpoint(
                $request,
                $acquisition,
                $inputContextHash
            );
            if ($checkpoint instanceof RunContext) {
                $runState = $ordinal === $stageCount - 1
                    ? GenerationRunState::SUCCEEDED
                    : null;
                $this->store->commitOutcome(
                    $request,
                    $runState === null ? null : WorkflowAuthorityOutcome::completed(
                        result: $this->runSummary($checkpoint, $stageName, true)
                    ),
                    $acquisition,
                    $inputContextHash,
                    $checkpoint
                );
                $context = $checkpoint;
                $this->fault(WorkflowFaultPoints::AFTER_STAGE_CHECKPOINT, $request, $acquisition);
                if ($runState !== null) {
                    return new WorkflowExecutionResult(
                        $runState,
                        $context,
                        $stageName,
                        commitRecovered: $recoveredAnyCommit
                    );
                }
                continue;
            }

            if ($boundary !== null) {
                $receipt = $this->store->findCommit($request, $boundary);
                if ($receipt !== null) {
                    $context = $this->recoverCommittedContext(
                        $context,
                        $stageName,
                        $ordinal,
                        $inputHash,
                        $receipt
                    );
                    $recoveredAnyCommit = true;
                    $runState = $ordinal === $stageCount - 1
                        ? GenerationRunState::SUCCEEDED
                        : null;
                    $this->store->commitOutcome(
                        $request,
                        $runState === null ? null : WorkflowAuthorityOutcome::completed(
                            result: $this->runSummary($context, $stageName, true)
                        ),
                        $acquisition,
                        $inputContextHash,
                        $context
                    );
                    $this->fault(
                        WorkflowFaultPoints::AFTER_STAGE_CHECKPOINT,
                        $request,
                        $acquisition
                    );
                    if ($runState !== null) {
                        return new WorkflowExecutionResult(
                            $runState,
                            $context,
                            $stageName,
                            commitRecovered: true
                        );
                    }
                    continue;
                }
            }

            // 恢复租约仅允许凭已验证提交账本执行真实后处理，禁止重新生成或提交。
            if ($acquisition->recoveryOnly
                && !$this->canRecoverPostprocess($request, $context, $stage, $boundary)) {
                return $this->failActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount,
                    'run_recovery_checkpoint_missing'
                );
            }

            if ($acquisition->attempt > $stage->retryPolicy()->maxAttempts) {
                return $this->failActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount,
                    // 审计修复（2026-09-27）：原报 commit_recovery_receipt_missing
                    // ——该码是 commit 恢复路径专用的"账本缺失"语义，此处实为
                    // 恢复租约的 attempt 超限（对非 commit Stage 本不可达），
                    // 改为准确的 attempts 语义，不再误导排障。
                    'stage_attempts_exhausted'
                );
            }

            try {
                $cancellation->throwIfCancellationRequested();
                $deadline->throwIfExceeded();
                $heartbeat->force();
            } catch (RunCanceledException) {
                return $this->cancelActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount
                );
            } catch (DeadlineExceededException) {
                return $this->failActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount,
                    DeadlineExceededException::ERROR_CODE
                );
            }

            $this->fault(WorkflowFaultPoints::BEFORE_STAGE_EXECUTE, $request, $acquisition);

            try {
                $result = $stage->execute($context, $cancellation, $deadline, $heartbeat);
            } catch (Throwable $exception) {
                if ($exception instanceof LeaseLostException) {
                    throw $exception;
                }
                $receipt = $boundary === null
                    ? null
                    : $this->store->findCommit($request, $boundary);
                if ($receipt !== null) {
                    $context = $this->recoverCommittedContext(
                        $context,
                        $stageName,
                        $ordinal,
                        $inputHash,
                        $receipt
                    );
                    $recoveredAnyCommit = true;
                    $runState = $exception instanceof DeadlineExceededException
                        ? GenerationRunState::ABORTED_AFTER_COMMIT
                        : ($exception instanceof RunCanceledException
                            ? GenerationRunState::CANCELED
                            : ($ordinal === $stageCount - 1
                                ? GenerationRunState::SUCCEEDED
                                : null));
                    $this->store->commitOutcome(
                        $request,
                        $runState === null ? null : $this->checkpointOutcome(
                            $runState,
                            $this->runSummary($context, $stageName, true)
                        ),
                        $acquisition,
                        $inputContextHash,
                        $context
                    );
                    $this->fault(
                        WorkflowFaultPoints::AFTER_STAGE_CHECKPOINT,
                        $request,
                        $acquisition
                    );
                    if ($runState !== null) {
                        return new WorkflowExecutionResult(
                            $runState,
                            $context,
                            $stageName,
                            errorCode: $exception instanceof WorkflowRuntimeException
                                ? $exception->errorCode()
                                : null,
                            commitRecovered: true
                        );
                    }
                    continue;
                }

                return $this->handleStageException(
                    $request,
                    $context,
                    $stage,
                    $acquisition,
                    $ordinal,
                    $stageCount,
                    $exception,
                    $deadline
                );
            }

            $this->fault(WorkflowFaultPoints::AFTER_STAGE_EXECUTE, $request, $acquisition);

            $receipt = $boundary === null
                ? null
                : $this->store->findCommit($request, $boundary);
            if ($boundary !== null && $receipt === null) {
                return $this->failActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount,
                    'commit_ledger_missing'
                );
            }

            if ($receipt !== null) {
                // A durable commit ledger always wins over a Stage disposition
                // that could otherwise cause the Canon write to be repeated.
                if (!$result->disposition->checkpointsOutput()) {
                    $result = StageResult::continue(metadata: ['commit_observed' => true]);
                }
                $recoveredAnyCommit = true;
            }

            try {
                $cancellation->throwIfCancellationRequested();
                $deadline->throwIfExceeded();
                $heartbeat->force();
            } catch (RunCanceledException) {
                if ($receipt !== null) {
                    $context = $context->withStageResult(
                        $stageName,
                        $ordinal,
                        $inputHash,
                        $result,
                        true
                    )->withCommitReceipt($stageName, $receipt->toArray());
                    $this->store->commitOutcome(
                        $request,
                        WorkflowAuthorityOutcome::canceled(
                            99,
                            RunCanceledException::ERROR_CODE,
                            $this->runSummary($context, $stageName, true)
                        ),
                        $acquisition,
                        $inputContextHash,
                        $context
                    );
                    return new WorkflowExecutionResult(
                        GenerationRunState::CANCELED,
                        $context,
                        $stageName,
                        errorCode: RunCanceledException::ERROR_CODE,
                        commitRecovered: true
                    );
                }
                return $this->cancelActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount
                );
            } catch (DeadlineExceededException) {
                if ($receipt !== null) {
                    $context = $context->withStageResult(
                        $stageName,
                        $ordinal,
                        $inputHash,
                        $result,
                        true
                    )->withCommitReceipt($stageName, $receipt->toArray());
                    $this->store->commitOutcome(
                        $request,
                        WorkflowAuthorityOutcome::abortedAfterCommit(
                            99,
                            $this->runSummary($context, $stageName, true),
                            DeadlineExceededException::ERROR_CODE
                        ),
                        $acquisition,
                        $inputContextHash,
                        $context
                    );
                    return new WorkflowExecutionResult(
                        GenerationRunState::ABORTED_AFTER_COMMIT,
                        $context,
                        $stageName,
                        errorCode: DeadlineExceededException::ERROR_CODE,
                        commitRecovered: true
                    );
                }
                return $this->failActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount,
                    DeadlineExceededException::ERROR_CODE
                );
            }

            if ($result->disposition->checkpointsOutput()) {
                try {
                    $nextContext = $context->withStageResult(
                        $stageName,
                        $ordinal,
                        $inputHash,
                        $result,
                        $receipt !== null
                    );
                } catch (RunBudgetExceededException
                    |HarnessSensitiveArtifactException
                    |InvalidArgumentException $exception
                ) {
                    // 审计修复 P0（2026-09-27）：commit Stage execute 已成功且
                    // receipt 存在时，checkpoint 应用失败（预算/敏感数据/校验
                    // 异常）绝不意味着 Canon 未写入。原实现直接 failActiveStage
                    // 会把 Run 落为 FAILED 终态：用户看到失败、章节却已提交，
                    // 且 FAILED 不可再 claim，后续 postprocess 阶段永久丢失。
                    // 与 execute 异常路径（上方 receipt 分支）同样先恢复已提交
                    // 上下文再进入下一阶段。
                    if ($receipt !== null) {
                        $context = $this->recoverCommittedContext(
                            $context,
                            $stageName,
                            $ordinal,
                            $inputHash,
                            $receipt
                        );
                        $recoveredAnyCommit = true;
                        $runState = $ordinal === $stageCount - 1
                            ? GenerationRunState::SUCCEEDED
                            : null;
                        $this->store->commitOutcome(
                            $request,
                            $runState === null ? null : WorkflowAuthorityOutcome::completed(
                                result: $this->runSummary($context, $stageName, true)
                            ),
                            $acquisition,
                            $inputContextHash,
                            $context
                        );
                        if ($runState !== null) {
                            return new WorkflowExecutionResult(
                                $runState,
                                $context,
                                $stageName,
                                errorCode: $exception instanceof WorkflowRuntimeException
                                    ? $exception->errorCode()
                                    : null,
                                commitRecovered: true
                            );
                        }
                        continue;
                    }
                    $errorCode = match (true) {
                        $exception instanceof RunBudgetExceededException => $exception->errorCode(),
                        $exception instanceof HarnessSensitiveArtifactException => 'sensitive_runtime_data',
                        default => 'invalid_stage_result',
                    };
                    return $this->failActiveStage(
                        $request,
                        $context,
                        $acquisition,
                        $ordinal,
                        $stageCount,
                        $errorCode
                    );
                }
                if ($receipt !== null) {
                    $nextContext = $nextContext->withCommitReceipt(
                        $stageName,
                        $receipt->toArray()
                    );
                }

                $runState = match ($result->disposition) {
                    StageDisposition::WAIT_APPROVAL => GenerationRunState::WAITING_APPROVAL,
                    StageDisposition::QUARANTINE => GenerationRunState::QUARANTINED,
                    StageDisposition::COMPLETE => GenerationRunState::SUCCEEDED,
                    StageDisposition::CONTINUE => $ordinal === $stageCount - 1
                        ? GenerationRunState::SUCCEEDED
                        : null,
                    default => throw new LogicException('Unknown checkpoint Stage disposition.'),
                };
                $this->store->commitOutcome(
                    $request,
                    $runState === null ? null : $this->checkpointOutcome(
                        $runState,
                        $this->runSummary($nextContext, $stageName, $receipt !== null)
                    ),
                    $acquisition,
                    $inputContextHash,
                    $nextContext
                );
                $context = $nextContext;
                $this->fault(
                    WorkflowFaultPoints::AFTER_STAGE_CHECKPOINT,
                    $request,
                    $acquisition
                );
                if ($runState !== null) {
                    return new WorkflowExecutionResult(
                        $runState,
                        $context,
                        $stageName,
                        errorCode: $result->errorCode,
                        commitRecovered: $recoveredAnyCommit
                    );
                }
                continue;
            }

            if ($result->disposition === StageDisposition::RETRY) {
                return $this->retryActiveStage(
                    $request,
                    $context,
                    $stage,
                    $acquisition,
                    $ordinal,
                    $stageCount,
                    $result->errorCode ?? 'stage_retry_requested',
                    $deadline
                );
            }
            if ($result->disposition === StageDisposition::CANCELED) {
                return $this->cancelActiveStage(
                    $request,
                    $context,
                    $acquisition,
                    $ordinal,
                    $stageCount
                );
            }
            return $this->failActiveStage(
                $request,
                $context,
                $acquisition,
                $ordinal,
                $stageCount,
                $result->errorCode ?? 'stage_failed'
            );
        }

        // This path covers recovery from a final already-succeeded Step whose
        // Run release was interrupted outside the composite M62 adapter.
        $this->store->commitOutcome(
            $request,
            WorkflowAuthorityOutcome::completed(
                result: $this->runSummary($context, null, $recoveredAnyCommit)
            )
        );
        return new WorkflowExecutionResult(
            GenerationRunState::SUCCEEDED,
            $context,
            commitRecovered: $recoveredAnyCommit
        );
    }

    private function canRecoverPostprocess(
        RunRequest $request,
        RunContext $context,
        RunStage $stage,
        ?CommitBoundary $boundary
    ): bool {
        if (!$stage instanceof LegacyPostprocessStage || $boundary !== null
            || !isset($context->completedStages['commit'])) {
            return false;
        }
        $receipt = $context->commitReceipts['commit'] ?? null;
        if (!is_array($receipt)
            || ($receipt['run_id'] ?? null) !== $request->runId
            || !is_int($receipt['chapter_id'] ?? null)
            || !is_string($receipt['draft_hash'] ?? null)) {
            return false;
        }
        $committed = $this->store->findCommit(
            $request,
            new CommitBoundary($receipt['chapter_id'], $receipt['draft_hash'])
        );
        return $committed !== null
            && HarnessPersistenceValue::canonicalJson($committed->toArray())
                === HarnessPersistenceValue::canonicalJson($receipt);
    }

    private function preflightWithoutStage(
        RunRequest $request,
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): ?WorkflowExecutionResult {
        try {
            $cancellation->throwIfCancellationRequested();
            $deadline->throwIfExceeded();
            $heartbeat->force();
            return null;
        } catch (RunCanceledException) {
            $this->store->commitOutcome(
                $request,
                WorkflowAuthorityOutcome::canceled(0)
            );
            return new WorkflowExecutionResult(
                GenerationRunState::CANCELED,
                $context,
                errorCode: RunCanceledException::ERROR_CODE
            );
        } catch (DeadlineExceededException) {
            $this->store->commitOutcome(
                $request,
                WorkflowAuthorityOutcome::failed(0, DeadlineExceededException::ERROR_CODE)
            );
            return new WorkflowExecutionResult(
                GenerationRunState::FAILED,
                $context,
                errorCode: DeadlineExceededException::ERROR_CODE
            );
        }
    }

    private function handleExistingStage(
        RunRequest $request,
        RunContext $context,
        WorkflowStageAcquisition $stage,
        string $inputContextHash,
        int $ordinal,
        int $stageCount,
        bool $recoveredCommit
    ): RunContext|WorkflowExecutionResult|null {
        return match ($stage->availability) {
            WorkflowStageAvailability::READY => null,
            WorkflowStageAvailability::SUCCEEDED => $this->requireCheckpoint(
                $request,
                $stage,
                $inputContextHash
            ),
            WorkflowStageAvailability::SKIPPED => $context,
            WorkflowStageAvailability::RETRY_WAIT => $this->releaseExistingRetryWait(
                $request,
                $context,
                $stage,
                $this->progressBefore($ordinal, $stageCount)
            ),
            WorkflowStageAvailability::FAILED => $this->releaseExistingTerminal(
                $request,
                $context,
                $stage,
                GenerationRunState::FAILED,
                $this->progressBefore($ordinal, $stageCount),
                'stage_already_failed',
                $recoveredCommit
            ),
            WorkflowStageAvailability::CANCELED => $this->releaseExistingTerminal(
                $request,
                $context,
                $stage,
                GenerationRunState::CANCELED,
                $this->progressBefore($ordinal, $stageCount),
                RunCanceledException::ERROR_CODE,
                $recoveredCommit
            ),
        };
    }

    private function requireCheckpoint(
        RunRequest $request,
        WorkflowStageAcquisition $stage,
        string $inputContextHash
    ): RunContext {
        $checkpoint = $this->store->restoreCheckpoint(
            $request,
            $stage,
            $inputContextHash
        );
        if (!$checkpoint instanceof RunContext) {
            throw new WorkflowCheckpointException(
                'Succeeded Stage is missing its durable checkpoint context.'
            );
        }
        return $checkpoint;
    }

    private function releaseExistingRetryWait(
        RunRequest $request,
        RunContext $context,
        WorkflowStageAcquisition $stage,
        int $progress
    ): WorkflowExecutionResult {
        if ($stage->retryAt === null) {
            throw new WorkflowCheckpointException('retry_wait Stage is missing retry_at.');
        }
        $retryAt = new DateTimeImmutable($stage->retryAt, new DateTimeZone('UTC'));
        $this->store->commitOutcome(
            $request,
            WorkflowAuthorityOutcome::retry($retryAt, $progress, 'stage_retry_wait')
        );
        return new WorkflowExecutionResult(
            GenerationRunState::RETRY_WAIT,
            $context,
            $stage->stageName,
            $stage->retryAt,
            'stage_retry_wait'
        );
    }

    private function releaseExistingTerminal(
        RunRequest $request,
        RunContext $context,
        WorkflowStageAcquisition $stage,
        GenerationRunState $state,
        int $progress,
        string $errorCode,
        bool $recoveredCommit
    ): WorkflowExecutionResult {
        $this->store->commitOutcome(
            $request,
            $state === GenerationRunState::FAILED
                ? WorkflowAuthorityOutcome::failed($progress, $errorCode)
                : WorkflowAuthorityOutcome::canceled($progress, $errorCode)
        );
        return new WorkflowExecutionResult(
            $state,
            $context,
            $stage->stageName,
            errorCode: $errorCode,
            commitRecovered: $recoveredCommit
        );
    }

    private function handleStageException(
        RunRequest $request,
        RunContext $context,
        RunStage $stage,
        WorkflowStageAcquisition $acquisition,
        int $ordinal,
        int $stageCount,
        Throwable $exception,
        Deadline $deadline
    ): WorkflowExecutionResult {
        if ($exception instanceof RunCanceledException) {
            return $this->cancelActiveStage(
                $request,
                $context,
                $acquisition,
                $ordinal,
                $stageCount
            );
        }
        if ($exception instanceof DeadlineExceededException) {
            return $this->failActiveStage(
                $request,
                $context,
                $acquisition,
                $ordinal,
                $stageCount,
                $exception->errorCode()
            );
        }
        if ($exception instanceof ApprovalRequiredException) {
            return $this->checkpointExceptionalDisposition(
                $request,
                $context,
                $acquisition,
                $ordinal,
                StageResult::waitApproval(),
                GenerationRunState::WAITING_APPROVAL
            );
        }
        if ($exception instanceof ValidationBlockedException) {
            return $this->checkpointExceptionalDisposition(
                $request,
                $context,
                $acquisition,
                $ordinal,
                StageResult::quarantine(),
                GenerationRunState::QUARANTINED
            );
        }
        if ($stage->retryPolicy()->isRetryable($exception)) {
            $errorCode = $exception instanceof WorkflowRuntimeException
                ? $exception->errorCode()
                : 'retryable_stage_error';
            return $this->retryActiveStage(
                $request,
                $context,
                $stage,
                $acquisition,
                $ordinal,
                $stageCount,
                $errorCode,
                $deadline
            );
        }

        $errorCode = match (true) {
            $exception instanceof WorkflowRuntimeException => $exception->errorCode(),
            $exception instanceof HarnessSensitiveArtifactException => 'sensitive_runtime_data',
            $exception instanceof InvalidArgumentException => 'invalid_stage_result',
            default => 'unhandled_stage_exception',
        };
        return $this->failActiveStage(
            $request,
            $context,
            $acquisition,
            $ordinal,
            $stageCount,
            $errorCode
        );
    }

    private function retryActiveStage(
        RunRequest $request,
        RunContext $context,
        RunStage $stage,
        WorkflowStageAcquisition $acquisition,
        int $ordinal,
        int $stageCount,
        string $errorCode,
        Deadline $deadline
    ): WorkflowExecutionResult {
        $retryAt = $stage->retryPolicy()->retryAt(
            $acquisition->attempt,
            $this->store->now(),
            $deadline
        );
        if ($retryAt === null || !$this->store->canRetryRun()) {
            return $this->failActiveStage(
                $request,
                $context,
                $acquisition,
                $ordinal,
                $stageCount,
                // 审计修复（2026-09-27）：区分 retryAt 为 null 的两种原因
                // （attempt 耗尽 / 退避将越过 deadline），排障不再同码。
                $acquisition->attempt >= $stage->retryPolicy()->maxAttempts
                    || !$this->store->canRetryRun()
                    ? 'stage_attempts_exhausted'
                    : 'stage_retry_exceeds_deadline'
            );
        }
        $progress = $this->progressBefore($ordinal, $stageCount);
        $this->store->commitOutcome(
            $request,
            WorkflowAuthorityOutcome::retry($retryAt, $progress, $errorCode),
            $acquisition
        );
        $retryAtString = HarnessPersistenceValue::mysqlDate($retryAt);
        return new WorkflowExecutionResult(
            GenerationRunState::RETRY_WAIT,
            $context,
            $acquisition->stageName,
            $retryAtString,
            $errorCode
        );
    }

    private function failActiveStage(
        RunRequest $request,
        RunContext $context,
        WorkflowStageAcquisition $stage,
        int $ordinal,
        int $stageCount,
        string $errorCode
    ): WorkflowExecutionResult {
        $progress = $this->progressBefore($ordinal, $stageCount);
        $this->store->commitOutcome(
            $request,
            WorkflowAuthorityOutcome::failed($progress, $errorCode),
            $stage
        );
        return new WorkflowExecutionResult(
            GenerationRunState::FAILED,
            $context,
            $stage->stageName,
            errorCode: $errorCode
        );
    }

    private function cancelActiveStage(
        RunRequest $request,
        RunContext $context,
        WorkflowStageAcquisition $stage,
        int $ordinal,
        int $stageCount
    ): WorkflowExecutionResult {
        $progress = $this->progressBefore($ordinal, $stageCount);
        $this->store->commitOutcome(
            $request,
            WorkflowAuthorityOutcome::canceled($progress),
            $stage
        );
        return new WorkflowExecutionResult(
            GenerationRunState::CANCELED,
            $context,
            $stage->stageName,
            errorCode: RunCanceledException::ERROR_CODE
        );
    }

    private function checkpointExceptionalDisposition(
        RunRequest $request,
        RunContext $context,
        WorkflowStageAcquisition $stage,
        int $ordinal,
        StageResult $result,
        GenerationRunState $runState
    ): WorkflowExecutionResult {
        $inputContextHash = $context->hash();
        $next = $context->withStageResult(
            $stage->stageName,
            $ordinal,
            $stage->inputHash,
            $result
        );
        $this->store->commitOutcome(
            $request,
            $this->checkpointOutcome(
                $runState,
                $this->runSummary($next, $stage->stageName, false)
            ),
            $stage,
            $inputContextHash,
            $next
        );
        return new WorkflowExecutionResult(
            $runState,
            $next,
            $stage->stageName,
            errorCode: $result->errorCode
        );
    }

    /** @param array<string,mixed> $result */
    private function checkpointOutcome(
        GenerationRunState $state,
        array $result
    ): WorkflowAuthorityOutcome {
        return match ($state) {
            GenerationRunState::SUCCEEDED => WorkflowAuthorityOutcome::completed(result: $result),
            GenerationRunState::CANCELED => WorkflowAuthorityOutcome::canceled(99, result: $result),
            GenerationRunState::WAITING_APPROVAL => WorkflowAuthorityOutcome::waitingApproval(99, $result),
            GenerationRunState::QUARANTINED => WorkflowAuthorityOutcome::quarantined(99, $result),
            GenerationRunState::ABORTED_AFTER_COMMIT => WorkflowAuthorityOutcome::abortedAfterCommit(99, $result),
            default => throw new LogicException('Run state is not a checkpoint outcome.'),
        };
    }

    private function recoverCommittedContext(
        RunContext $context,
        string $stageName,
        int $ordinal,
        string $inputHash,
        WorkflowCommitReceipt $receipt
    ): RunContext {
        return $context->withStageResult(
            $stageName,
            $ordinal,
            $inputHash,
            StageResult::continue(metadata: ['commit_recovered' => true]),
            true
        )->withCommitReceipt($stageName, $receipt->toArray());
    }

    private function progressBefore(int $ordinal, int $stageCount): int
    {
        if ($stageCount < 1) {
            return 0;
        }
        return min(99, max(0, (int)floor(($ordinal / $stageCount) * 100)));
    }

    /** @return array<string,mixed> */
    private function runSummary(
        RunContext $context,
        ?string $stageName,
        bool $commitRecovered
    ): array {
        return [
            'workflow_name' => $this->workflow->name,
            'workflow_version' => $this->workflow->version,
            'definition_hash' => $this->workflow->definitionHash,
            'context_hash' => $context->hash(),
            'last_stage_name' => $stageName,
            'commit_recovered' => $commitRecovered,
            'input_tokens' => $context->budget->inputTokens,
            'output_tokens' => $context->budget->outputTokens,
            'cost' => $context->budget->cost,
        ];
    }

    private function fault(
        string $point,
        RunRequest $request,
        WorkflowStageAcquisition $stage
    ): void {
        $this->faultInjector?->hit($point, [
            'run_id' => $request->runId,
            'step_id' => $stage->stepId,
            'stage_name' => $stage->stageName,
            'attempt' => $stage->attempt,
        ]);
    }
}
