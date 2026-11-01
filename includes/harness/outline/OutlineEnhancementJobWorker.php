<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/OutlineEnhancementJobMapper.php';
require_once __DIR__ . '/OutlineEnhancementRunner.php';

final class OutlineEnhancementJobWorker
{
    public function __construct(
        private readonly HarnessJobQueue $jobs,
        private readonly OutlineEnhancementMappedRunGateway $runs,
        private readonly OutlineEnhancementLeaseExecutor $runner,
        private readonly ?OutlineEnhancementJobMapper $mapper = null
    ) {
    }

    /** @return array<string,mixed>|null */
    public function runNext(
        string $owner,
        int $leaseSeconds = OutlineEnhancementRunner::DEFAULT_LEASE_SECONDS
    ): ?array {
        $owner = HarnessPersistenceValue::identifier($owner, 'outline_job_owner', 120);
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'outline_job_lease_seconds',
            15,
            3600
        );
        // Repair at most one legacy durable row before claiming. Duplicate
        // workers converge through the Job queue/idempotency uniqueness guard.
        $this->mapper?->backfillNext();
        $jobLease = $this->jobs->claim(
            OutlineEnhancementRunFactory::QUEUE,
            $owner,
            $leaseSeconds
        );
        if ($jobLease === null) {
            return null;
        }

        try {
            $runUid = $this->validateMapping($jobLease->job);
            if ($this->jobs->cancellationRequested($jobLease)) {
                $this->runs->requestCancel($runUid);
                return $this->jobResult(
                    $this->jobs->cancelLease($jobLease),
                    'canceled',
                    'canceled'
                );
            }

            $run = $this->runs->findByUid($runUid);
            $this->assertRunOwnership($jobLease->job, $run);
            if ($run->state->isTerminal()
                || $run->state === GenerationRunState::RETRY_WAIT) {
                return $this->mirrorRun($jobLease, $run);
            }

            $runLease = $this->runs->claimByUid($runUid, $owner, $leaseSeconds);
            if ($runLease === null) {
                $run = $this->runs->findByUid($runUid);
                $this->assertRunOwnership($jobLease->job, $run);
                return $this->mirrorRun($jobLease, $run);
            }

            $outerCheckpoint = function () use (&$jobLease, $leaseSeconds, $runUid): void {
                if ($this->jobs->cancellationRequested($jobLease)) {
                    $this->runs->requestCancel($runUid);
                    throw new OutlineEnhancementCanceledException(
                        'Outline Job cancellation was requested.'
                    );
                }
                $jobLease = $this->jobs->renew($jobLease, $leaseSeconds);
            };
            $execution = $this->runner->runLease(
                $runLease,
                $leaseSeconds,
                $outerCheckpoint
            );
            $run = $this->runs->findByUid($runUid);
            $this->assertRunOwnership($jobLease->job, $run);
            return $this->mirrorRun($jobLease, $run, $execution);
        } catch (HarnessLeaseLostException $exception) {
            // Never manufacture a state transition after either fence is lost.
            throw $exception;
        } catch (OutlineEnhancementCanceledException) {
            return $this->jobResult(
                $this->jobs->cancelLease($jobLease),
                'canceled',
                'canceled'
            );
        } catch (InvalidArgumentException|HarnessIdempotencyConflictException $exception) {
            return $this->jobResult(
                $this->jobs->fail(
                    $jobLease,
                    'invalid_outline_job',
                    'The outline Job mapping is invalid.'
                ),
                'failed',
                'invalid_outline_job'
            );
        } catch (Throwable $exception) {
            $delay = min(300, 15 * (2 ** max(0, $jobLease->job->attempt - 1)));
            $job = $this->jobs->retryAfter(
                $jobLease,
                $delay,
                'outline_dispatch_failed',
                'The outline Job dispatch attempt failed and will retry.'
            );
            return $this->jobResult(
                $job,
                $job->state->value,
                $job->state === HarnessJobState::DEAD_LETTER
                    ? 'outline_dispatch_exhausted'
                    : 'retry_scheduled'
            );
        }
    }

    private function validateMapping(HarnessJob $job): string
    {
        if ($job->queue !== OutlineEnhancementRunFactory::QUEUE
            || $job->jobType !== OutlineEnhancementJobMapper::JOB_TYPE
            || $job->runId === null
            || $job->legacyType !== OutlineEnhancementJobMapper::LEGACY_TYPE
            || $job->legacyId !== $job->runId) {
            throw new InvalidArgumentException('Claimed Job is not an outline Run mapping.');
        }
        $payload = $job->payload();
        HarnessPersistenceValue::requireFields($payload, [
            'schema', 'run_uid', 'run_id', 'workflow', 'workflow_version',
            'authority_mode', 'input_hash', 'context_snapshot_hash',
            'emergency_epoch',
        ], 'outline_job.payload');
        if (($payload['schema'] ?? null) !== 'harness.outline_job.v2'
            || (int)($payload['run_id'] ?? 0) !== $job->runId) {
            throw new InvalidArgumentException('Outline Job payload identity changed.');
        }
        OutlineEmergencyEpoch::normalize($payload['emergency_epoch']);
        return HarnessPersistenceValue::uid($payload['run_uid'] ?? null, 'outline_job.run_uid');
    }

    private function assertRunOwnership(HarnessJob $job, ?GenerationRun $run): void
    {
        if ($run === null
            || $job->runId !== $run->id
            || $job->userId !== $run->userId
            || $job->novelId !== $run->novelId
            || $job->chapterId !== $run->chapterId
            || $run->queue !== OutlineEnhancementRunFactory::QUEUE
            || $run->runType !== OutlineEnhancementRunFactory::RUN_TYPE) {
            throw new HarnessIdempotencyConflictException(
                'Outline Job does not own its linked Run.'
            );
        }
    }

    /** @param array<string,mixed> $execution */
    private function mirrorRun(
        HarnessJobLease $jobLease,
        GenerationRun $run,
        array $execution = []
    ): array {
        return match ($run->state) {
            GenerationRunState::SUCCEEDED,
            GenerationRunState::ABORTED_AFTER_COMMIT => $this->jobResult(
                $this->jobs->succeed(
                    $jobLease,
                    [
                        'schema' => 'harness.outline_job_result.v1',
                        'run_uid' => $run->runUid,
                        'run_state' => $run->state->value,
                        'execution' => $execution,
                    ],
                    // A cancellation arriving after the atomic Run/outline
                    // commit cannot erase accepted state.
                    true
                ),
                'succeeded',
                'run_succeeded'
            ),
            GenerationRunState::CANCELED => $this->jobResult(
                $this->jobs->cancelLease($jobLease),
                'canceled',
                'run_canceled'
            ),
            GenerationRunState::FAILED,
            GenerationRunState::QUARANTINED => $this->jobResult(
                ($run->errorCode === 'outline_attempts_exhausted'
                    ? $this->jobs->deadLetter(
                        $jobLease,
                        $run->errorCode,
                        $run->errorMessage ?? 'Outline Run exhausted its attempt budget.'
                    )
                    : $this->jobs->fail(
                        $jobLease,
                        $run->errorCode ?? 'outline_run_failed',
                        $run->errorMessage ?? 'The linked outline Run failed.'
                    )),
                'failed',
                $run->errorCode ?? 'outline_run_failed'
            ),
            GenerationRunState::RETRY_WAIT => $this->jobResult(
                $this->deferForLinkedRun($jobLease, $run),
                'retry_wait',
                'run_retry_wait'
            ),
            GenerationRunState::QUEUED,
            GenerationRunState::RUNNING,
            GenerationRunState::WAITING_APPROVAL => $this->jobResult(
                $this->deferForLinkedRun($jobLease, $run),
                'retry_wait',
                'run_not_claimable'
            ),
        };
    }

    private function deferForLinkedRun(
        HarnessJobLease $jobLease,
        GenerationRun $run
    ): HarnessJob {
        $scheduledAt = $run->state === GenerationRunState::RUNNING
            ? $run->leaseExpiresAt
            : $run->availableAt;
        if ($run->state === GenerationRunState::WAITING_APPROVAL
            || $scheduledAt === null) {
            return $this->jobs->retryAfter(
                $jobLease,
                300,
                'outline_run_not_claimable',
                'The linked outline Run is not currently claimable.'
            );
        }
        return $this->jobs->retry(
            $jobLease,
            new DateTimeImmutable($scheduledAt, new DateTimeZone('UTC')),
            $run->errorCode ?? 'outline_run_not_claimable',
            $run->errorMessage ?? 'The linked outline Run is waiting to become claimable.'
        );
    }

    /** @return array<string,mixed> */
    private function jobResult(HarnessJob $job, string $status, string $outcome): array
    {
        return [
            'job_id' => $job->jobUid,
            'run_id' => $job->payload()['run_uid'] ?? null,
            'status' => $status,
            'outcome' => $outcome,
            'attempt' => $job->attempt,
        ];
    }
}
