<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/GenerationAttempt.php';
require_once __DIR__ . '/GenerationStep.php';
require_once __DIR__ . '/GenerationTransition.php';

final class GenerationAttemptRepository
{
    private HarnessPersistenceDriver $db;
    private ?Closure $now;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $now = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->now = $now === null ? null : Closure::fromCallable($now);
    }

    public function createIdempotent(
        GenerationStepLease $lease,
        GenerationAttemptDraft $draft
    ): GenerationAttempt
    {
        if ($draft->stepId !== $lease->step->id || $draft->attemptNo !== $lease->attempt) {
            throw new InvalidArgumentException(
                'Generation Attempt identity must match the current Step lease attempt.'
            );
        }

        return $this->db->transactional(function () use ($lease, $draft): GenerationAttempt {
            $currentLease = $this->requireCurrentStepLease($lease);
            if ($currentLease->step->id !== $draft->stepId
                || $currentLease->attempt !== $draft->attemptNo) {
                throw new HarnessLeaseLostException(
                    'Generation Attempt creation observed a different Step attempt.'
                );
            }
            if ($draft->inputArtifactId !== null) {
                $this->assertArtifactOwnership($currentLease, $draft->inputArtifactId, false);
            }

            $this->db->execute(
                "INSERT INTO generation_attempts
                    (step_id, attempt_no, state, provider, model, model_config_json,
                     provider_request_id, input_artifact_id, input_tokens,
                     output_tokens, cache_tokens, cost, latency_ms, started_at)
                 VALUES (?, ?, 'running', ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->stepId,
                    $draft->attemptNo,
                    $draft->provider,
                    $draft->model,
                    $draft->modelConfigJson,
                    $draft->providerRequestId,
                    $draft->inputArtifactId,
                ]
            );
            $row = $this->db->fetchOne(
                'SELECT * FROM generation_attempts WHERE step_id=? AND attempt_no=? FOR UPDATE',
                [$draft->stepId, $draft->attemptNo]
            );
            if ($row === null) {
                throw new HarnessIdempotencyConflictException(
                    'Attempt insert did not resolve its unique identity.'
                );
            }
            $draft->assertIdempotentRow($row);
            return GenerationAttempt::fromRow($row);
        });
    }

    public function createIdempotentWithTransition(
        GenerationStepLease $lease,
        GenerationAttemptDraft $draft
    ): GenerationTransitionResult {
        $attempt = $this->createIdempotent($lease, $draft);
        return new GenerationTransitionResult($attempt, [new GenerationTransitionFact(
            GenerationTransitionSubject::ATTEMPT,
            $attempt->id,
            'created',
            GenerationAttemptState::RUNNING->value,
            'attempt_started',
            GenerationTransitionClassification::LIFECYCLE,
            null,
            ['attempt_no' => $attempt->attemptNo, 'step_id' => $attempt->stepId]
        )]);
    }

    public function finishWithTransition(
        GenerationStepLease $lease,
        GenerationAttemptResult $result
    ): GenerationTransitionResult {
        $attempt = $this->finish($lease, $result);
        return new GenerationTransitionResult($attempt, [new GenerationTransitionFact(
            GenerationTransitionSubject::ATTEMPT,
            $attempt->id,
            GenerationAttemptState::RUNNING->value,
            $attempt->state->value,
            'attempt_finished',
            GenerationTransitionClassification::LIFECYCLE,
            null,
            ['attempt_no' => $attempt->attemptNo, 'step_id' => $attempt->stepId]
        )]);
    }

    public function finish(
        GenerationStepLease $lease,
        GenerationAttemptResult $result
    ): GenerationAttempt
    {
        return $this->db->transactional(function () use ($lease, $result): GenerationAttempt {
            $currentLease = $this->requireCurrentStepLease($lease);
            if ($result->outputArtifactId !== null) {
                $this->assertArtifactOwnership($currentLease, $result->outputArtifactId, true);
            }
            $affected = $this->db->execute(
                "UPDATE generation_attempts
                 SET state=?, output_artifact_id=?, input_tokens=?, output_tokens=?,
                     cache_tokens=?, cost=?, latency_ms=?, finish_reason=?,
                     error_code=?, error_message=?, completed_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND step_id=? AND attempt_no=? AND state='running'",
                [
                    $result->next->value,
                    $result->outputArtifactId,
                    $result->inputTokens,
                    $result->outputTokens,
                    $result->cacheTokens,
                    $result->cost,
                    $result->latencyMs,
                    $result->finishReason,
                    $result->errorCode,
                    $result->errorMessage,
                    $result->attemptId,
                    $lease->step->id,
                    $lease->attempt,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessStateTransitionException(
                    'Generation Attempt completion lost its running-state CAS.'
                );
            }
            $row = $this->db->fetchOne(
                'SELECT * FROM generation_attempts WHERE id=? AND step_id=? AND attempt_no=? FOR UPDATE',
                [$result->attemptId, $lease->step->id, $lease->attempt]
            );
            if ($row === null) {
                throw new HarnessPersistenceException('Completed generation Attempt disappeared.');
            }
            return GenerationAttempt::fromRow($row);
        });
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
                    'Generation Attempt database clock did not return UTC_TIMESTAMP(6).'
                );
            }
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new HarnessPersistenceException('Generation Attempt clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function requireCurrentStepLease(GenerationStepLease $lease): GenerationStepLease
    {
        $nowSql = HarnessPersistenceValue::mysqlDate($this->nowValue());
        $stepRow = $this->db->fetchOne(
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
        if ($stepRow === null) {
            throw new HarnessLeaseLostException('Generation Attempt operation lost its Step lease.');
        }
        $currentLease = GenerationStepLease::fromStep(GenerationStep::fromRow($stepRow));
        if ($currentLease->step->id !== $lease->step->id
            || $currentLease->attempt !== $lease->attempt
            || $currentLease->token !== $lease->token
            || $currentLease->generation !== $lease->generation
            || $currentLease->expiresAt !== $lease->expiresAt) {
            throw new HarnessLeaseLostException(
                'Generation Attempt operation observed a different Step lease.'
            );
        }
        return $currentLease;
    }

    private function assertArtifactOwnership(
        GenerationStepLease $lease,
        int $artifactId,
        bool $requireCurrentStep
    ): void {
        $row = $this->db->fetchOne(
            'SELECT id, run_id, step_id FROM generation_artifacts WHERE id=? FOR UPDATE',
            [$artifactId]
        );
        if ($row === null) {
            throw new HarnessPersistenceException('Generation Attempt Artifact does not exist.');
        }
        HarnessPersistenceValue::requireFields(
            $row,
            ['id', 'run_id', 'step_id'],
            'generation_artifact'
        );
        $stepId = HarnessPersistenceValue::optionalPositiveInt(
            $row['step_id'],
            'artifact_step_id'
        );
        $matches = HarnessPersistenceValue::positiveInt($row['id'], 'artifact_id') === $artifactId
            && HarnessPersistenceValue::positiveInt($row['run_id'], 'artifact_run_id')
                === $lease->step->runId
            && (!$requireCurrentStep || $stepId === $lease->step->id);
        if (!$matches) {
            throw new HarnessPersistenceException(
                $requireCurrentStep
                    ? 'Generation Attempt output Artifact must belong to its leased Step and Run.'
                    : 'Generation Attempt input Artifact must belong to its Run.'
            );
        }
    }
}
