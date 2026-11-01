<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/OutlineEnhancementContracts.php';
require_once __DIR__ . '/OutlineEmergencyEpoch.php';
require_once __DIR__ . '/OutlineEnhancementRunFactory.php';

final class OutlineEnhancementRunner implements OutlineEnhancementLeaseExecutor
{
    public const DEFAULT_LEASE_SECONDS = 3600;
    public const RETRY_BASE_SECONDS = 15;
    public const RETRY_MAX_SECONDS = 300;

    private Closure $now;
    private Closure $emergencyEpoch;

    public function __construct(
        private readonly OutlineEnhancementLeaseQueue $queue,
        private readonly OutlineEnhancementStore $store,
        private readonly OutlineEnhancer $enhancer,
        ?callable $now = null,
        ?callable $emergencyEpoch = null
    ) {
        $this->now = $now === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            )
            : Closure::fromCallable($now);
        $this->emergencyEpoch = $emergencyEpoch === null
            ? static fn (): int => OutlineEmergencyEpoch::current()
            : Closure::fromCallable($emergencyEpoch);
    }

    /**
     * Claim and execute at most one durable outline enhancement Run.
     *
     * @return array<string,mixed>|null
     */
    public function runNext(
        string $owner,
        int $leaseSeconds = self::DEFAULT_LEASE_SECONDS
    ): ?array {
        $owner = HarnessPersistenceValue::identifier($owner, 'outline_worker_owner', 120);
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'outline_lease_seconds',
            15,
            3600
        );
        $lease = $this->queue->claim($owner, $leaseSeconds);
        if ($lease === null) {
            return null;
        }

        return $this->runLease($lease, $leaseSeconds);
    }

    /**
     * Execute an already-fenced Run lease. The optional outer checkpoint lets
     * the M64 Job envelope renew and cancel alongside the legacy Run without
     * moving model work into a database transaction.
     *
     * @param callable():void|null $outerCheckpoint
     * @return array<string,mixed>
     */
    public function runLease(
        GenerationRunLease $lease,
        int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        ?callable $outerCheckpoint = null
    ): array {
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'outline_lease_seconds',
            15,
            3600
        );
        $outerCheckpoint = $outerCheckpoint === null
            ? static function (): void {}
            : Closure::fromCallable($outerCheckpoint);

        try {
            $goal = $this->validateRun($lease->run);
            $this->assertEmergencyEpoch($goal['emergency_epoch']);
            $deadline = $this->deadline($lease->run);
            $checkpoint = function () use (
                &$lease,
                $leaseSeconds,
                $deadline,
                $outerCheckpoint,
                $goal
            ): void {
                $outerCheckpoint();
                $this->assertEmergencyEpoch($goal['emergency_epoch']);
                if ($this->queue->cancellationRequested($lease)) {
                    throw new OutlineEnhancementCanceledException(
                        'Outline enhancement cancellation was requested.'
                    );
                }
                if ($this->nowValue() >= $deadline) {
                    throw new OutlineEnhancementDeadlineException(
                        'Outline enhancement reached its durable deadline.'
                    );
                }
                $lease = $this->queue->renew($lease, $leaseSeconds);
            };

            $checkpoint();
            $base = $this->store->capture(
                $lease->run->userId,
                $lease->run->novelId,
                $goal['chapter_numbers']
            );
            if (!hash_equals($goal['base_hash'], $base->canonicalHash)
                || !hash_equals($goal['base_revision'], $base->revisionHash)) {
                return $this->releaseFailure(
                    $lease,
                    'outline_base_changed',
                    'The saved base outline changed before enhancement started.'
                );
            }

            $checkpoint();
            $novel = $this->store->loadNovel($base->userId, $base->novelId);
            $recent = $this->store->loadRecentOutlines(
                $base->novelId,
                $goal['start_chapter'],
                $goal['lookback']
            );
            $enhanced = $this->enhancer->enhance($base, $novel, $recent, $checkpoint);
            $checkpoint();

            $authority = $lease->run->authorityMode === 'authority';
            $result = [
                'outcome' => $authority ? 'applied' : 'shadow',
                'mode' => $lease->run->authorityMode,
                'chapter_count' => count($base->chapterNumbers),
                'base_hash' => $base->canonicalHash,
                'base_revision' => $base->revisionHash,
                'enhancement_hash' => hash(
                    'sha256',
                    HarnessPersistenceValue::canonicalJson($enhanced)
                ),
            ];
            $released = null;
            $outcome = $this->store->commit(
                $lease,
                $base,
                $enhanced,
                $authority,
                $goal['emergency_epoch'],
                function () use (&$released, &$lease, $result): GenerationRun {
                    $released = $this->queue->release(
                        $lease,
                        GenerationRunState::SUCCEEDED,
                        100,
                        null,
                        $result
                    );
                    return $released;
                }
            );

            if (in_array($outcome, [
                OutlineEnhancementCommitOutcome::APPLIED,
                OutlineEnhancementCommitOutcome::SHADOW,
            ], true)) {
                if (!$released instanceof GenerationRun
                    || $released->state !== GenerationRunState::SUCCEEDED) {
                    throw new HarnessPersistenceException(
                        'Outline commit returned without an atomic successful Run release.'
                    );
                }
                if ($outcome === OutlineEnhancementCommitOutcome::APPLIED) {
                    try {
                        $this->store->afterCommit(
                            $base->novelId,
                            $goal['start_chapter'],
                            $goal['end_chapter'],
                            $enhanced
                        );
                    } catch (Throwable $exception) {
                        $this->safeLog(
                            $base->novelId,
                            'warn',
                            'Durable outline enhancement post-commit maintenance failed ('
                                . get_class($exception) . ').'
                        );
                    }
                }
                return $this->result($released, 'succeeded', $outcome->value);
            }

            return match ($outcome) {
                OutlineEnhancementCommitOutcome::BASE_CHANGED => $this->releaseFailure(
                    $lease,
                    'outline_base_changed',
                    'The saved base outline changed before the enhanced CAS write.'
                ),
                OutlineEnhancementCommitOutcome::CANCELED => $this->releaseCanceled($lease),
                OutlineEnhancementCommitOutcome::EMERGENCY_STOPPED =>
                    $this->releaseEmergencyStopped($lease),
                OutlineEnhancementCommitOutcome::DEADLINE_EXCEEDED => $this->releaseFailure(
                    $lease,
                    'deadline_exceeded',
                    'The outline enhancement deadline elapsed before its CAS write.'
                ),
                OutlineEnhancementCommitOutcome::LEASE_LOST => $this->leaseLostOrCanceled($lease),
                default => throw new LogicException('Unknown outline enhancement commit outcome.'),
            };
        } catch (OutlineEnhancementEmergencyStopException) {
            return $this->releaseEmergencyStopped($lease);
        } catch (OutlineEnhancementCanceledException) {
            return $this->releaseCanceled($lease);
        } catch (OutlineEnhancementDeadlineException) {
            return $this->releaseFailure(
                $lease,
                'deadline_exceeded',
                'The outline enhancement deadline elapsed.'
            );
        } catch (OutlineEnhancementPermanentException|InvalidArgumentException $exception) {
            $this->safeLog(
                $lease->run->novelId,
                'warn',
                'Durable outline enhancement rejected permanent input ('
                    . get_class($exception) . ').'
            );
            return $this->releaseFailure(
                $lease,
                'invalid_outline_run',
                'The durable outline enhancement request is invalid.'
            );
        } catch (HarnessLeaseLostException) {
            return $this->leaseLostOrCanceled($lease);
        } catch (Throwable $exception) {
            $this->safeLog(
                $lease->run->novelId,
                'warn',
                'Durable outline enhancement attempt failed ('
                    . get_class($exception) . ').'
            );
            try {
                $goal = $this->validateRun($lease->run);
                $this->assertEmergencyEpoch($goal['emergency_epoch']);
            } catch (OutlineEnhancementEmergencyStopException) {
                return $this->releaseEmergencyStopped($lease);
            }
            return $this->retryOrFail($lease);
        }
    }

    /**
     * @return array{
     *   user_id:int,novel_id:int,start_chapter:int,end_chapter:int,lookback:int,
     *   chapter_numbers:list<int>,base_hash:string,base_revision:string,emergency_epoch:int
     * }
     */
    private function validateRun(GenerationRun $run): array
    {
        if ($run->queue !== OutlineEnhancementRunFactory::QUEUE
            || $run->runType !== OutlineEnhancementRunFactory::RUN_TYPE
            || $run->workflowName !== OutlineEnhancementRunFactory::WORKFLOW
            || $run->workflowVersion !== OutlineEnhancementRunFactory::WORKFLOW_VERSION
            || !in_array($run->authorityMode, ['shadow', 'authority'], true)) {
            throw new OutlineEnhancementPermanentException(
                'Claimed Run does not belong to the outline enhancement workflow.'
            );
        }
        $goal = HarnessPersistenceValue::decodeJsonArray($run->goalJson, 'outline_goal_json');
        HarnessPersistenceValue::requireFields($goal, [
            'kind', 'user_id', 'novel_id', 'start_chapter', 'end_chapter',
            'lookback', 'chapter_numbers', 'base_hash', 'base_revision',
            'emergency_epoch',
        ], 'outline_goal');
        if (($goal['kind'] ?? null) !== OutlineEnhancementRunFactory::RUN_TYPE
            || !is_array($goal['chapter_numbers'])) {
            throw new OutlineEnhancementPermanentException('Outline Run goal is malformed.');
        }
        $userId = HarnessPersistenceValue::positiveInt($goal['user_id'], 'outline_goal.user_id');
        $novelId = HarnessPersistenceValue::positiveInt($goal['novel_id'], 'outline_goal.novel_id');
        $start = HarnessPersistenceValue::positiveInt(
            $goal['start_chapter'],
            'outline_goal.start_chapter'
        );
        $end = HarnessPersistenceValue::positiveInt(
            $goal['end_chapter'],
            'outline_goal.end_chapter'
        );
        $lookback = HarnessPersistenceValue::boundedInt(
            $goal['lookback'],
            'outline_goal.lookback',
            1,
            100
        );
        $chapters = OutlineEnhancementSnapshot::normalizeChapterNumbers(
            array_values($goal['chapter_numbers'])
        );
        $baseHash = HarnessPersistenceValue::hash($goal['base_hash'], 'outline_goal.base_hash');
        $baseRevision = HarnessPersistenceValue::hash(
            $goal['base_revision'],
            'outline_goal.base_revision'
        );
        $emergencyEpoch = OutlineEmergencyEpoch::normalize($goal['emergency_epoch']);
        $expectedInputHash = hash('sha256', HarnessPersistenceValue::canonicalJson([
            'schema' => 'harness.outline_frozen_input.v2',
            'base_hash' => $baseHash,
            'base_revision' => $baseRevision,
            'emergency_epoch' => $emergencyEpoch,
        ]));
        if ($userId !== $run->userId
            || $novelId !== $run->novelId
            || $end < $start
            || $chapters === []
            || !hash_equals($run->inputHash, $expectedInputHash)
            || $run->contextSnapshotHash === null
            || !hash_equals($run->contextSnapshotHash, $baseRevision)) {
            throw new OutlineEnhancementPermanentException(
                'Outline Run goal does not match its immutable durable coordinates.'
            );
        }
        foreach ($chapters as $chapter) {
            if ($chapter < $start || $chapter > $end) {
                throw new OutlineEnhancementPermanentException(
                    'Outline Run chapter set escapes its durable range.'
                );
            }
        }

        return [
            'user_id' => $userId,
            'novel_id' => $novelId,
            'start_chapter' => $start,
            'end_chapter' => $end,
            'lookback' => $lookback,
            'chapter_numbers' => $chapters,
            'base_hash' => $baseHash,
            'base_revision' => $baseRevision,
            'emergency_epoch' => $emergencyEpoch,
        ];
    }

    private function deadline(GenerationRun $run): DateTimeImmutable
    {
        if ($run->deadlineAt === null) {
            throw new OutlineEnhancementPermanentException(
                'Outline enhancement Run requires a durable deadline.'
            );
        }
        try {
            return (new DateTimeImmutable(
                $run->deadlineAt,
                new DateTimeZone('UTC')
            ))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new OutlineEnhancementPermanentException(
                'Outline enhancement Run deadline is malformed.',
                0,
                $exception
            );
        }
    }

    /** @return array<string,mixed> */
    private function retryOrFail(GenerationRunLease $lease): array
    {
        $now = $this->nowValue();
        $deadline = $this->deadline($lease->run);
        $delay = min(
            self::RETRY_MAX_SECONDS,
            self::RETRY_BASE_SECONDS * (2 ** max(0, $lease->run->attempt - 1))
        );
        $retryAt = $now->modify('+' . $delay . ' seconds');
        if ($lease->run->attempt < $lease->run->maxAttempts && $retryAt < $deadline) {
            try {
                $run = $this->queue->release(
                    $lease,
                    GenerationRunState::RETRY_WAIT,
                    min(95, max(1, $lease->run->progress)),
                    $retryAt,
                    null,
                    'outline_attempt_failed',
                    'The outline enhancement attempt failed and will retry.'
                );
                return $this->result($run, 'retry_wait', 'retry_scheduled');
            } catch (HarnessLeaseLostException) {
                return $this->leaseLostOrCanceled($lease);
            }
        }
        return $this->releaseFailure(
            $lease,
            'outline_attempts_exhausted',
            'The outline enhancement exhausted its finite attempt budget.'
        );
    }

    /** @return array<string,mixed> */
    private function releaseFailure(
        GenerationRunLease $lease,
        string $code,
        string $message
    ): array {
        try {
            $run = $this->queue->release(
                $lease,
                GenerationRunState::FAILED,
                min(99, max(0, $lease->run->progress)),
                null,
                null,
                $code,
                $message
            );
            return $this->result($run, 'failed', $code);
        } catch (HarnessLeaseLostException) {
            return $this->leaseLostOrCanceled($lease);
        }
    }

    /** @return array<string,mixed> */
    private function releaseCanceled(GenerationRunLease $lease): array
    {
        try {
            $run = $this->queue->release(
                $lease,
                GenerationRunState::CANCELED,
                min(99, max(0, $lease->run->progress))
            );
            return $this->result($run, 'canceled', 'canceled');
        } catch (HarnessLeaseLostException) {
            return $this->result($lease->run, 'lease_lost', 'lease_lost');
        }
    }

    /** @return array<string,mixed> */
    private function releaseEmergencyStopped(GenerationRunLease $lease): array
    {
        try {
            $run = $this->queue->release(
                $lease,
                GenerationRunState::CANCELED,
                min(99, max(0, $lease->run->progress)),
                null,
                null,
                'emergency_stop_epoch_changed',
                'The Harness emergency epoch changed; outline execution stopped.'
            );
            return $this->result($run, 'canceled', 'emergency_stop_epoch_changed');
        } catch (HarnessLeaseLostException) {
            return $this->result($lease->run, 'lease_lost', 'lease_lost');
        }
    }

    private function assertEmergencyEpoch(int $captured): void
    {
        try {
            OutlineEmergencyEpoch::assertCurrent(
                $captured,
                OutlineEmergencyEpoch::normalize(($this->emergencyEpoch)())
            );
        } catch (OutlineEnhancementEmergencyStopException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutlineEnhancementEmergencyStopException(
                'Outline emergency checkpoint failed closed.',
                0,
                $exception
            );
        }
    }

    /** @return array<string,mixed> */
    private function leaseLostOrCanceled(GenerationRunLease $lease): array
    {
        try {
            if ($this->queue->cancellationRequested($lease)) {
                return $this->releaseCanceled($lease);
            }
        } catch (Throwable) {
            // Ownership is already uncertain; leave the durable reaper to
            // resolve the row instead of making an unfenced transition.
        }
        return $this->result($lease->run, 'lease_lost', 'lease_lost');
    }

    /** @return array<string,mixed> */
    private function result(GenerationRun $run, string $status, string $outcome): array
    {
        return [
            'run_id' => $run->runUid,
            'status' => $status,
            'outcome' => $outcome,
            'attempt' => $run->attempt,
        ];
    }

    private function safeLog(int $novelId, string $level, string $message): void
    {
        try {
            $this->store->log($novelId, $level, $message);
        } catch (Throwable) {
            // Diagnostics must never change the durable Run transition.
        }
    }

    private function nowValue(): DateTimeImmutable
    {
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('Outline enhancement clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }
}
