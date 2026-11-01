<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/GenerationStep.php';
require_once __DIR__ . '/GenerationTransition.php';

final class GenerationStepRepository
{
    private HarnessPersistenceDriver $db;
    private ?Closure $now;
    private Closure $tokenFactory;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $now = null,
        ?callable $tokenFactory = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->now = $now === null ? null : Closure::fromCallable($now);
        $this->tokenFactory = $tokenFactory === null
            ? static fn (): string => bin2hex(random_bytes(16))
            : Closure::fromCallable($tokenFactory);
    }

    public function createIdempotent(GenerationStepDraft $draft): GenerationStep
    {
        return $this->db->transactional(function () use ($draft): GenerationStep {
            $this->db->execute(
                "INSERT INTO generation_steps
                    (run_id, step_key, iteration_no, ordinal, step_type, state,
                     progress, input_hash, attempt, max_attempts,
                     fence_generation, policy_json)
                 VALUES (?, ?, ?, ?, ?, 'pending', 0, ?, 0, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->runId,
                    $draft->stepKey,
                    $draft->iterationNo,
                    $draft->ordinal,
                    $draft->stepType,
                    $draft->inputHash,
                    $draft->maxAttempts,
                    $draft->policyJson,
                ]
            );
            $row = $this->db->fetchOne(
                'SELECT * FROM generation_steps WHERE run_id=? AND step_key=? AND iteration_no=? FOR UPDATE',
                [$draft->runId, $draft->stepKey, $draft->iterationNo]
            );
            if ($row === null) {
                throw new HarnessIdempotencyConflictException(
                    'Step insert collided without resolving its unique identity.'
                );
            }
            $draft->assertIdempotentRow($row);
            return GenerationStep::fromRow($row);
        });
    }

    public function find(int $stepId, bool $lockForUpdate = false): ?GenerationStep
    {
        $id = HarnessPersistenceValue::positiveInt($stepId, 'step_id');
        $row = $this->db->fetchOne(
            'SELECT * FROM generation_steps WHERE id=?' . ($lockForUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
        return $row === null ? null : GenerationStep::fromRow($row);
    }

    public function claim(int $stepId, int $leaseSeconds = 120): ?GenerationStepLease
    {
        return $this->claimWithEvents($stepId, $leaseSeconds);
    }

    private function claimMutation(int $stepId, int $leaseSeconds = 120): GenerationTransitionResult
    {
        $id = HarnessPersistenceValue::positiveInt($stepId, 'step_id');
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'lease_seconds',
            15,
            3600
        );

        return $this->db->transactional(function () use ($id, $leaseSeconds): GenerationTransitionResult {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $row = $this->db->fetchOne(
                'SELECT * FROM generation_steps WHERE id=? FOR UPDATE',
                [$id]
            );
            if ($row === null) {
                return new GenerationTransitionResult(null, []);
            }
            $prior = GenerationStep::fromRow($row);
            if (!$this->isClaimableAt($prior, $nowSql)) {
                return new GenerationTransitionResult(null, []);
            }

            [$eligibilitySql, $eligibilityParams] = $this->claimEligibility($prior, $nowSql);
            $identityParams = [
                $prior->id,
                $prior->state->value,
                $prior->attempt,
                $prior->fenceGeneration,
                $prior->leaseToken,
                $prior->leaseExpiresAt,
                $prior->retryAt,
            ];
            $facts = [];

            if ($prior->state === GenerationStepState::RUNNING) {
                $expiredAttempts = $this->db->execute(
                    "UPDATE generation_attempts
                     SET state='failed', finish_reason='lease_expired',
                         error_code='step_lease_expired',
                         error_message='Step lease expired before the Attempt completed.',
                         completed_at=COALESCE(completed_at, ?)
                     WHERE step_id=? AND attempt_no=? AND state='running'",
                    [$nowSql, $prior->id, $prior->attempt]
                );
                if ($expiredAttempts === 1) {
                    $attemptRow = $this->db->fetchOne(
                        'SELECT id FROM generation_attempts WHERE step_id=? AND attempt_no=?',
                        [$prior->id, $prior->attempt]
                    );
                    if ($attemptRow === null) {
                        throw new HarnessPersistenceException(
                            'Expired generation Step Attempt disappeared before its Event fact.'
                        );
                    }
                    $facts[] = $this->expiredAttemptFact(
                        $prior,
                        HarnessPersistenceValue::positiveInt($attemptRow['id'] ?? null, 'attempt_id')
                    );
                }
            }

            if ($prior->attempt >= $prior->maxAttempts) {
                $affected = $this->db->execute(
                    "UPDATE generation_steps
                     SET state='failed', retry_at=NULL,
                         lease_token=NULL, lease_expires_at=NULL,
                         error_code='max_attempts_exhausted',
                         error_message='Step exhausted its durable attempt budget.',
                         completed_at=?, updated_at=?
                     WHERE id=? AND state=? AND attempt=? AND fence_generation=?
                       AND lease_token <=> ? AND lease_expires_at <=> ? AND retry_at <=> ?
                       AND attempt>=max_attempts AND {$eligibilitySql}",
                    array_merge([$nowSql, $nowSql], $identityParams, $eligibilityParams)
                );
                if ($affected !== 1) {
                    throw new HarnessLeaseLostException(
                        'Generation Step max-attempt finalization lost its fenced CAS.'
                    );
                }
                $facts[] = $this->exhaustedStepFact($prior);
                return new GenerationTransitionResult(null, $facts);
            }

            $token = HarnessPersistenceValue::uid(($this->tokenFactory)(), 'lease_token');
            if ($prior->leaseToken !== null && hash_equals($prior->leaseToken, $token)) {
                throw new HarnessPersistenceException(
                    'Generation Step claim token factory reused the expired lease token.'
                );
            }
            $expiresSql = HarnessPersistenceValue::mysqlDate(
                $now->modify("+{$leaseSeconds} seconds")
            );
            $affected = $this->db->execute(
                "UPDATE generation_steps
                 SET state='running', attempt=attempt+1,
                     fence_generation=fence_generation+1,
                     retry_at=NULL, lease_token=?, lease_expires_at=?,
                     started_at=COALESCE(started_at, ?), completed_at=NULL,
                     error_code=NULL, error_message=NULL, updated_at=?
                 WHERE id=? AND state=? AND attempt=? AND fence_generation=?
                   AND lease_token <=> ? AND lease_expires_at <=> ? AND retry_at <=> ?
                   AND attempt<max_attempts AND {$eligibilitySql}",
                array_merge(
                    [$token, $expiresSql, $nowSql, $nowSql],
                    $identityParams,
                    $eligibilityParams
                )
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Generation Step claim lost its fenced CAS.');
            }

            $lease = GenerationStepLease::fromStep($this->requireStep($prior->id, true));
            if ($lease->token !== $token
                || $lease->generation !== $prior->fenceGeneration + 1
                || $lease->attempt !== $prior->attempt + 1
                || $lease->expiresAt !== $expiresSql) {
                throw new HarnessPersistenceException(
                    'Claimed generation Step does not match the acquired lease fence.'
                );
            }
            $facts[] = $this->claimedStepFact($prior, $lease);
            return new GenerationTransitionResult($lease, $facts);
        });
    }

    private function expiredAttemptFact(
        GenerationStep $step,
        int $attemptId
    ): GenerationTransitionFact {
        return new GenerationTransitionFact(
            GenerationTransitionSubject::ATTEMPT,
            $attemptId,
            'running',
            'failed',
            'step_lease_expired',
            GenerationTransitionClassification::OBSERVATION,
            new GenerationEventDraft(
                $step->runId,
                $step->id,
                $attemptId,
                'attempt.failed',
                1,
                GenerationEventSeverity::WARNING,
                // The Event key unique scope is (run_id, event_key); a Step
                // coordinate is mandatory or two Steps claiming at the same
                // attempt number deterministically collide and fail closed.
                'attempt.failed:step:' . $step->id . ':lease_expired:' . $step->attempt,
                'system',
                null,
                null,
                false,
                ['state' => 'failed', 'attempt_no' => $step->attempt]
            ),
            [
                'run_id' => $step->runId,
                'step_id' => $step->id,
                'attempt_no' => $step->attempt,
            ]
        );
    }

    private function exhaustedStepFact(GenerationStep $step): GenerationTransitionFact
    {
        return new GenerationTransitionFact(
            GenerationTransitionSubject::STEP,
            $step->id,
            $step->state->value,
            GenerationStepState::FAILED->value,
            'max_attempts_exhausted',
            GenerationTransitionClassification::LIFECYCLE,
            new GenerationEventDraft(
                $step->runId,
                $step->id,
                null,
                'step.failed',
                1,
                GenerationEventSeverity::ERROR,
                'step.failed:step:' . $step->id . ':max_attempts:' . $step->attempt,
                'system',
                null,
                null,
                true,
                [
                    'stage_name' => $step->stepKey,
                    'ordinal' => $step->ordinal,
                    'attempt' => $step->attempt,
                    'state' => 'failed',
                ]
            ),
            [
                'run_id' => $step->runId,
                'attempt' => $step->attempt,
                'fence_generation' => $step->fenceGeneration,
            ]
        );
    }

    private function claimedStepFact(
        GenerationStep $before,
        GenerationStepLease $lease
    ): GenerationTransitionFact {
        return new GenerationTransitionFact(
            GenerationTransitionSubject::STEP,
            $lease->step->id,
            $before->state->value,
            GenerationStepState::RUNNING->value,
            'step_claimed',
            GenerationTransitionClassification::OWNERSHIP,
            new GenerationEventDraft(
                $lease->step->runId,
                $lease->step->id,
                null,
                'step.claimed',
                1,
                GenerationEventSeverity::INFO,
                'step.claimed:step:' . $lease->step->id . ':' . $lease->generation,
                'worker',
                null,
                null,
                false,
                [
                    'attempt' => $lease->attempt,
                    'fence_generation' => $lease->generation,
                    'reclaimed' => $before->state === GenerationStepState::RUNNING,
                ]
            ),
            [
                'run_id' => $lease->step->runId,
                'attempt' => $lease->attempt,
                'fence_generation' => $lease->generation,
            ]
        );
    }

    public function claimWithTransitions(
        int $stepId,
        int $leaseSeconds = 120
    ): GenerationTransitionResult {
        return $this->claimMutation($stepId, $leaseSeconds);
    }

    public function claimWithEvents(int $stepId, int $leaseSeconds = 120): ?GenerationStepLease
    {
        $result = (new GenerationTransitionCoordinator($this->db))->execute(
            fn (): GenerationTransitionResult => $this->claimMutation($stepId, $leaseSeconds)
        );
        return $result->value;
    }

    public function renew(GenerationStepLease $lease, int $leaseSeconds = 120): GenerationStepLease
    {
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'lease_seconds',
            15,
            3600
        );

        return $this->db->transactional(function () use ($lease, $leaseSeconds): GenerationStepLease {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $expiresSql = HarnessPersistenceValue::mysqlDate(
                $now->modify("+{$leaseSeconds} seconds")
            );
            $affected = $this->db->execute(
                "UPDATE generation_steps
                 SET lease_expires_at=?, updated_at=?
                 WHERE id=? AND state='running' AND attempt=?
                   AND lease_token=? AND fence_generation=?
                   AND lease_expires_at=? AND lease_expires_at>=?",
                [
                    $expiresSql,
                    $nowSql,
                    $lease->step->id,
                    $lease->attempt,
                    $lease->token,
                    $lease->generation,
                    $lease->expiresAt,
                    $nowSql,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Generation Step lease renewal lost ownership.');
            }

            $renewed = GenerationStepLease::fromStep($this->requireStep($lease->step->id, true));
            if ($renewed->token !== $lease->token
                || $renewed->generation !== $lease->generation
                || $renewed->attempt !== $lease->attempt
                || $renewed->expiresAt !== $expiresSql) {
                throw new HarnessPersistenceException(
                    'Renewed generation Step does not match the expected lease fence.'
                );
            }
            return $renewed;
        });
    }

    public function release(
        GenerationStepLease $lease,
        GenerationStepTransition $transition
    ): GenerationStep {
        if ($transition->stepId !== $lease->step->id
            || $transition->expected !== GenerationStepState::RUNNING) {
            throw new InvalidArgumentException(
                'Generation Step release must target the leased running Step.'
            );
        }

        return $this->db->transactional(function () use ($lease, $transition): GenerationStep {
            $nowSql = HarnessPersistenceValue::mysqlDate($this->nowValue());
            $retryAt = $transition->retryAt === null
                ? null
                : HarnessPersistenceValue::mysqlDate($transition->retryAt);
            if ($transition->next === GenerationStepState::RETRY_WAIT) {
                if ($lease->attempt >= $lease->step->maxAttempts) {
                    throw new HarnessStateTransitionException(
                        'Generation Step cannot retry after exhausting max_attempts.'
                    );
                }
                if ($retryAt === null || $retryAt <= $nowSql) {
                    throw new InvalidArgumentException(
                        'retry_wait Step release requires retry_at later than the release time.'
                    );
                }
            }
            $this->requireCurrentLease($lease, $nowSql);
            if ($transition->next === GenerationStepState::SUCCEEDED) {
                $this->assertSucceededArtifact($lease, $transition);
            }

            $terminal = $transition->next->isTerminal() ? 1 : 0;
            $affected = $this->db->execute(
                "UPDATE generation_steps
                 SET state=?, progress=?,
                     output_hash=IF(? IS NULL, output_hash, ?),
                     output_artifact_id=IF(? IS NULL, output_artifact_id, ?),
                     retry_at=?, lease_token=NULL, lease_expires_at=NULL,
                     error_code=?, error_message=?,
                     completed_at=IF(?=1, ?, NULL), updated_at=?
                 WHERE id=? AND state='running' AND attempt=?
                   AND lease_token=? AND fence_generation=?
                   AND lease_expires_at=? AND lease_expires_at>=?
                   AND (?<>'retry_wait' OR attempt<max_attempts)",
                [
                    $transition->next->value,
                    $transition->progress,
                    $transition->outputHash,
                    $transition->outputHash,
                    $transition->outputArtifactId,
                    $transition->outputArtifactId,
                    $retryAt,
                    $transition->errorCode,
                    $transition->errorMessage,
                    $terminal,
                    $nowSql,
                    $nowSql,
                    $transition->stepId,
                    $lease->attempt,
                    $lease->token,
                    $lease->generation,
                    $lease->expiresAt,
                    $nowSql,
                    $transition->next->value,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Generation Step release lost ownership.');
            }
            return $this->requireStep($transition->stepId, true);
        });
    }

    public function releaseWithTransition(
        GenerationStepLease $lease,
        GenerationStepTransition $transition
    ): GenerationTransitionResult {
        $step = $this->release($lease, $transition);
        return new GenerationTransitionResult($step, [new GenerationTransitionFact(
            GenerationTransitionSubject::STEP,
            $step->id,
            GenerationStepState::RUNNING->value,
            $step->state->value,
            'step_released',
            GenerationTransitionClassification::LIFECYCLE,
            null,
            ['attempt' => $step->attempt, 'fence_generation' => $step->fenceGeneration]
        )]);
    }

    public function transitionWithFact(
        GenerationStepTransition $transition
    ): GenerationTransitionResult {
        $step = $this->transition($transition);
        return new GenerationTransitionResult($step, [new GenerationTransitionFact(
            GenerationTransitionSubject::STEP,
            $step->id,
            $transition->expected->value,
            $step->state->value,
            'step_transitioned',
            GenerationTransitionClassification::LIFECYCLE,
            null,
            ['attempt' => $step->attempt, 'fence_generation' => $step->fenceGeneration]
        )]);
    }

    public function transition(GenerationStepTransition $transition): GenerationStep
    {
        if ($transition->expected === GenerationStepState::RUNNING
            || $transition->next === GenerationStepState::RUNNING) {
            throw new HarnessStateTransitionException(
                'Running Generation Step transitions require claim/release lease APIs.'
            );
        }

        return $this->db->transactional(function () use ($transition): GenerationStep {
            $retryAt = $transition->retryAt === null
                ? null
                : HarnessPersistenceValue::mysqlDate($transition->retryAt);
            $terminal = $transition->next->isTerminal() ? 1 : 0;
            $snapshotSql = $transition->expectedAttempt === null
                ? ''
                : ' AND attempt=? AND fence_generation=?';
            $params = [
                $transition->next->value,
                $transition->progress,
                $transition->outputHash,
                $transition->outputHash,
                $transition->outputArtifactId,
                $transition->outputArtifactId,
                $retryAt,
                $transition->errorCode,
                $transition->errorMessage,
                $terminal,
                $transition->stepId,
                $transition->expected->value,
            ];
            if ($transition->expectedAttempt !== null) {
                $params[] = $transition->expectedAttempt;
                $params[] = $transition->expectedFenceGeneration;
            }
            $affected = $this->db->execute(
                "UPDATE generation_steps
                 SET state=?, progress=?,
                     output_hash=IF(? IS NULL, output_hash, ?),
                     output_artifact_id=IF(? IS NULL, output_artifact_id, ?),
                     retry_at=?, error_code=?, error_message=?,
                     completed_at=IF(?=1, UTC_TIMESTAMP(6), NULL),
                     updated_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND state=?
                   AND lease_token IS NULL AND lease_expires_at IS NULL{$snapshotSql}",
                $params
            );
            if ($affected !== 1) {
                throw new HarnessStateTransitionException(
                    'Generation Step transition lost its expected-state CAS.'
                );
            }
            return $this->requireStep($transition->stepId, true);
        });
    }

    private function requireStep(int $id, bool $lockForUpdate): GenerationStep
    {
        $step = $this->find($id, $lockForUpdate);
        if ($step === null) {
            throw new HarnessPersistenceException('Generation Step does not exist.');
        }
        return $step;
    }

    private function nowValue(): DateTimeImmutable
    {
        if ($this->now === null) {
            $row = $this->db->fetchOne('SELECT UTC_TIMESTAMP(6) AS now_utc');
            $value = HarnessPersistenceValue::optionalMysqlDate(
                $row['now_utc'] ?? null,
                'database_now_utc'
            );
            if ($value === null) {
                throw new HarnessPersistenceException(
                    'Generation Step database clock did not return UTC_TIMESTAMP(6).'
                );
            }
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new HarnessPersistenceException('Generation Step clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function requireCurrentLease(
        GenerationStepLease $lease,
        string $nowSql
    ): GenerationStepLease {
        $row = $this->db->fetchOne(
            "SELECT * FROM generation_steps
             WHERE id=? AND state='running' AND attempt=?
               AND lease_token=? AND fence_generation=?
               AND lease_expires_at=? AND lease_expires_at>=?
             FOR UPDATE",
            [
                $lease->step->id,
                $lease->attempt,
                $lease->token,
                $lease->generation,
                $lease->expiresAt,
                $nowSql,
            ]
        );
        if ($row === null) {
            throw new HarnessLeaseLostException('Generation Step operation lost its lease.');
        }
        $current = GenerationStepLease::fromStep(GenerationStep::fromRow($row));
        if ($current->step->id !== $lease->step->id
            || $current->attempt !== $lease->attempt
            || $current->token !== $lease->token
            || $current->generation !== $lease->generation
            || $current->expiresAt !== $lease->expiresAt) {
            throw new HarnessLeaseLostException(
                'Generation Step operation observed a different lease generation.'
            );
        }
        return $current;
    }

    private function assertSucceededArtifact(
        GenerationStepLease $lease,
        GenerationStepTransition $transition
    ): void {
        if ($transition->outputArtifactId === null || $transition->outputHash === null) {
            throw new HarnessPersistenceException(
                'Succeeded generation Step is missing its output Artifact identity.'
            );
        }
        $row = $this->db->fetchOne(
            'SELECT id, run_id, step_id, content_hash FROM generation_artifacts WHERE id=? FOR UPDATE',
            [$transition->outputArtifactId]
        );
        if ($row === null) {
            throw new HarnessPersistenceException('Succeeded generation Step output Artifact is missing.');
        }
        HarnessPersistenceValue::requireFields(
            $row,
            ['id', 'run_id', 'step_id', 'content_hash'],
            'generation_artifact'
        );
        $matches = HarnessPersistenceValue::positiveInt($row['id'], 'artifact_id')
                === $transition->outputArtifactId
            && HarnessPersistenceValue::positiveInt($row['run_id'], 'artifact_run_id')
                === $lease->step->runId
            && HarnessPersistenceValue::optionalPositiveInt($row['step_id'], 'artifact_step_id')
                === $lease->step->id
            && hash_equals(
                HarnessPersistenceValue::hash($row['content_hash'], 'artifact_content_hash'),
                $transition->outputHash
            );
        if (!$matches) {
            throw new HarnessPersistenceException(
                'Succeeded generation Step output Artifact does not belong to this Step/hash.'
            );
        }
    }

    private function isClaimableAt(GenerationStep $step, string $nowSql): bool
    {
        return match ($step->state) {
            GenerationStepState::PENDING => true,
            GenerationStepState::RETRY_WAIT => $step->retryAt !== null
                && $step->retryAt <= $nowSql,
            GenerationStepState::RUNNING => $step->leaseExpiresAt !== null
                && $step->leaseExpiresAt < $nowSql,
            default => false,
        };
    }

    /** @return array{0:string,1:list<string>} */
    private function claimEligibility(GenerationStep $step, string $nowSql): array
    {
        return match ($step->state) {
            GenerationStepState::PENDING => ["state='pending'", []],
            GenerationStepState::RETRY_WAIT => ["state='retry_wait' AND retry_at<=?", [$nowSql]],
            GenerationStepState::RUNNING => [
                "state='running' AND lease_expires_at IS NOT NULL AND lease_expires_at<?",
                [$nowSql],
            ],
            default => throw new HarnessPersistenceException(
                'Non-claimable generation Step reached claim CAS construction.'
            ),
        };
    }
}
