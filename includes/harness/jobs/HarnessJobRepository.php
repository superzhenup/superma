<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessJob.php';

final class HarnessJobRepository implements HarnessJobQueue
{
    private HarnessPersistenceDriver $db;
    private Closure $tokenFactory;
    private ?Closure $now;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $tokenFactory = null,
        ?callable $now = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->tokenFactory = $tokenFactory === null
            ? static fn (): string => bin2hex(random_bytes(16))
            : Closure::fromCallable($tokenFactory);
        $this->now = $now === null ? null : Closure::fromCallable($now);
    }

    public function enqueue(HarnessJobDraft $draft): HarnessJob
    {
        return $this->db->transactional(function () use ($draft): HarnessJob {
            $this->assertOwnedReferences($draft);
            $this->db->execute(
                "INSERT INTO jobs
                    (job_uid, run_id, step_id, queue, job_type, user_id,
                     novel_id, chapter_id, idempotency_key, state, priority,
                     progress, payload_json, attempt, max_attempts, available_at,
                     fence_generation, cancel_requested, next_event_no,
                     legacy_type, legacy_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?, 0, ?, 0, ?, COALESCE(?, UTC_TIMESTAMP(6)),
                         0, 0, 1, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->jobUid,
                    $draft->runId,
                    $draft->stepId,
                    $draft->queue,
                    $draft->jobType,
                    $draft->userId,
                    $draft->novelId,
                    $draft->chapterId,
                    $draft->idempotencyKey,
                    $draft->priority,
                    $draft->payloadJson,
                    $draft->maxAttempts,
                    $draft->availableAt,
                    $draft->legacyType,
                    $draft->legacyId,
                ]
            );
            $row = $this->db->fetchOne(
                'SELECT * FROM jobs WHERE queue=? AND idempotency_key=? FOR UPDATE',
                [$draft->queue, $draft->idempotencyKey]
            );
            if ($row === null) {
                throw new HarnessPersistenceException('Enqueued Job disappeared.');
            }
            $draft->assertIdempotentRow($row);
            $job = HarnessJob::fromRow($row);
            $this->appendEventLocked(
                $job->id,
                'job.enqueued',
                'job.enqueued',
                ['queue' => $job->queue, 'job_type' => $job->jobType],
                'system',
                'job-kernel'
            );
            return $this->requireJob($job->id, true);
        });
    }

    public function findByUid(string $jobUid): ?HarnessJob
    {
        $uid = HarnessPersistenceValue::uid($jobUid, 'job_uid');
        $row = $this->db->fetchOne('SELECT * FROM jobs WHERE job_uid=?', [$uid]);
        return $row === null ? null : HarnessJob::fromRow($row);
    }

    public function findByRunId(int $runId): ?HarnessJob
    {
        $runId = HarnessPersistenceValue::positiveInt($runId, 'job.run_id');
        $row = $this->db->fetchOne(
            'SELECT * FROM jobs WHERE run_id=? ORDER BY id LIMIT 1',
            [$runId]
        );
        return $row === null ? null : HarnessJob::fromRow($row);
    }

    public function findByLegacy(string $legacyType, int $legacyId): ?HarnessJob
    {
        $legacyType = HarnessPersistenceValue::identifier(
            $legacyType,
            'job.legacy_type',
            32
        );
        $legacyId = HarnessPersistenceValue::positiveInt($legacyId, 'job.legacy_id');
        $row = $this->db->fetchOne(
            'SELECT * FROM jobs WHERE legacy_type=? AND legacy_id=?',
            [$legacyType, $legacyId]
        );
        return $row === null ? null : HarnessJob::fromRow($row);
    }

    public function claim(
        string $queue,
        string $owner,
        int $leaseSeconds = 120
    ): ?HarnessJobLease {
        $queue = HarnessPersistenceValue::identifier($queue, 'job.queue', 32);
        $owner = HarnessPersistenceValue::identifier($owner, 'job.lease_owner', 120);
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'job.lease_seconds',
            15,
            3600
        );

        return $this->db->transactional(function () use (
            $queue,
            $owner,
            $leaseSeconds
        ): ?HarnessJobLease {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $row = $this->db->fetchOne(
                "SELECT * FROM jobs
                 WHERE queue=?
                   AND ((state IN ('queued','retry_wait') AND available_at<=?)
                     OR (state='running' AND lease_expires_at IS NOT NULL
                         AND lease_expires_at<?))
                 ORDER BY priority DESC, available_at, id
                 LIMIT 1 FOR UPDATE",
                [$queue, $nowSql, $nowSql]
            );
            if ($row === null) {
                return null;
            }
            $job = HarnessJob::fromRow($row);
            if ($job->cancelRequested) {
                $this->finalizeUnclaimed(
                    $job,
                    HarnessJobState::CANCELED,
                    $nowSql,
                    'job.canceled',
                    null,
                    null
                );
                return null;
            }
            if ($job->attempt >= $job->maxAttempts) {
                $this->finalizeUnclaimed(
                    $job,
                    HarnessJobState::DEAD_LETTER,
                    $nowSql,
                    'job.dead_lettered',
                    'max_attempts_exhausted',
                    'Job exhausted its durable attempt budget.'
                );
                return null;
            }

            $token = HarnessPersistenceValue::uid(
                ($this->tokenFactory)(),
                'job.lease_token'
            );
            $expiresAt = HarnessPersistenceValue::mysqlDate(
                $now->modify("+{$leaseSeconds} seconds")
            );
            $priorState = $job->state;
            $affected = $this->db->execute(
                "UPDATE jobs
                 SET state='running', attempt=attempt+1,
                     fence_generation=fence_generation+1,
                     lease_owner=?, lease_token=?, lease_expires_at=?,
                     started_at=COALESCE(started_at, ?),
                     error_code=NULL, error_message=NULL, updated_at=?
                 WHERE id=? AND state=? AND fence_generation=?",
                [
                    $owner,
                    $token,
                    $expiresAt,
                    $nowSql,
                    $nowSql,
                    $job->id,
                    $priorState->value,
                    $job->fenceGeneration,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Job claim lost its fenced CAS.');
            }
            $claimed = $this->requireJob($job->id, true);
            $this->appendEventLocked(
                $claimed->id,
                'job.claimed',
                'job.claimed:' . $claimed->fenceGeneration,
                [
                    'attempt' => $claimed->attempt,
                    'fence_generation' => $claimed->fenceGeneration,
                    'owner' => $owner,
                    'reclaimed' => $priorState === HarnessJobState::RUNNING,
                ],
                'worker',
                $owner
            );
            return HarnessJobLease::fromJob($this->requireJob($job->id, true));
        });
    }

    public function renew(HarnessJobLease $lease, int $leaseSeconds = 120): HarnessJobLease
    {
        $leaseSeconds = HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'job.lease_seconds',
            15,
            3600
        );
        return $this->db->transactional(function () use ($lease, $leaseSeconds): HarnessJobLease {
            $now = $this->nowValue();
            $nowSql = HarnessPersistenceValue::mysqlDate($now);
            $expiresAt = HarnessPersistenceValue::mysqlDate(
                $now->modify("+{$leaseSeconds} seconds")
            );
            $affected = $this->db->execute(
                "UPDATE jobs SET lease_expires_at=?, updated_at=?
                 WHERE id=? AND state='running' AND cancel_requested=0
                   AND lease_owner=? AND lease_token=? AND fence_generation=?
                   AND lease_expires_at=? AND lease_expires_at>=?",
                [
                    $expiresAt,
                    $nowSql,
                    $lease->job->id,
                    $lease->owner,
                    $lease->token,
                    $lease->generation,
                    $lease->expiresAt,
                    $nowSql,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Job lease renewal lost ownership.');
            }
            return HarnessJobLease::fromJob($this->requireJob($lease->job->id, true));
        });
    }

    /** @param array<string,mixed> $result */
    public function succeed(
        HarnessJobLease $lease,
        array $result = [],
        bool $allowCanceled = false
    ): HarnessJob
    {
        return $this->releaseTerminal(
            $lease,
            HarnessJobState::SUCCEEDED,
            100,
            $result,
            null,
            null,
            'job.succeeded',
            $allowCanceled
        );
    }

    public function fail(
        HarnessJobLease $lease,
        string $errorCode,
        string $errorMessage
    ): HarnessJob {
        return $this->releaseTerminal(
            $lease,
            HarnessJobState::FAILED,
            $lease->job->progress,
            [],
            $errorCode,
            $errorMessage,
            'job.failed'
        );
    }

    public function deadLetter(
        HarnessJobLease $lease,
        string $errorCode,
        string $errorMessage
    ): HarnessJob {
        return $this->releaseTerminal(
            $lease,
            HarnessJobState::DEAD_LETTER,
            $lease->job->progress,
            [],
            $errorCode,
            $errorMessage,
            'job.dead_lettered'
        );
    }

    public function cancelLease(HarnessJobLease $lease): HarnessJob
    {
        return $this->releaseTerminal(
            $lease,
            HarnessJobState::CANCELED,
            $lease->job->progress,
            [],
            null,
            null,
            'job.canceled',
            true
        );
    }

    public function retry(
        HarnessJobLease $lease,
        DateTimeImmutable $availableAt,
        string $errorCode,
        string $errorMessage
    ): HarnessJob {
        $errorCode = HarnessPersistenceValue::identifier($errorCode, 'job.error_code', 64);
        $errorMessage = HarnessPersistenceValue::string(
            $errorMessage,
            'job.error_message',
            1,
            500
        );
        $availableAt = $availableAt->setTimezone(new DateTimeZone('UTC'));

        return $this->db->transactional(function () use (
            $lease,
            $availableAt,
            $errorCode,
            $errorMessage
        ): HarnessJob {
            $current = $this->requireOwnedLease($lease);
            $nowSql = HarnessPersistenceValue::mysqlDate($this->nowValue());
            if ($current->cancelRequested) {
                return $this->releaseTerminalLocked(
                    $lease,
                    HarnessJobState::CANCELED,
                    $current->progress,
                    null,
                    null,
                    null,
                    'job.canceled',
                    $nowSql,
                    true
                );
            }
            if ($current->attempt >= $current->maxAttempts) {
                return $this->releaseTerminalLocked(
                    $lease,
                    HarnessJobState::DEAD_LETTER,
                    $current->progress,
                    null,
                    $errorCode,
                    $errorMessage,
                    'job.dead_lettered',
                    $nowSql
                );
            }
            $availableSql = HarnessPersistenceValue::mysqlDate($availableAt);
            if ($availableSql < $nowSql) {
                // A linked legacy Run may become claimable between its read and
                // this fenced release. Clamp to the authoritative DB clock;
                // never derive a second local-time schedule.
                $availableSql = $nowSql;
            }
            $affected = $this->db->execute(
                "UPDATE jobs
                 SET state='retry_wait', available_at=?,
                     lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                     error_code=?, error_message=?, completed_at=NULL, updated_at=?
                 WHERE id=? AND state='running' AND cancel_requested=0
                   AND lease_owner=? AND lease_token=? AND fence_generation=?
                   AND lease_expires_at=? AND lease_expires_at>=?",
                [
                    $availableSql,
                    $errorCode,
                    $errorMessage,
                    $nowSql,
                    $current->id,
                    $lease->owner,
                    $lease->token,
                    $lease->generation,
                    $lease->expiresAt,
                    $nowSql,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Job retry release lost ownership.');
            }
            $this->appendEventLocked(
                $current->id,
                'job.retry_scheduled',
                'job.retry_scheduled:' . $lease->generation,
                [
                    'attempt' => $current->attempt,
                    'available_at' => $availableSql,
                    'error_code' => $errorCode,
                ],
                'worker',
                $lease->owner
            );
            return $this->requireJob($current->id, true);
        });
    }

    public function retryAfter(
        HarnessJobLease $lease,
        int $delaySeconds,
        string $errorCode,
        string $errorMessage
    ): HarnessJob {
        $delaySeconds = HarnessPersistenceValue::boundedInt(
            $delaySeconds,
            'job.retry_delay_seconds',
            1,
            86400
        );
        return $this->retry(
            $lease,
            $this->nowValue()->modify('+' . $delaySeconds . ' seconds'),
            $errorCode,
            $errorMessage
        );
    }

    public function cancellationRequested(HarnessJobLease $lease): bool
    {
        $row = $this->db->fetchOne(
            'SELECT state, cancel_requested FROM jobs WHERE id=?',
            [$lease->job->id]
        );
        if ($row === null) {
            return true;
        }
        return HarnessPersistenceValue::bool(
            $row['cancel_requested'] ?? null,
            'job.cancel_requested'
        ) || ($row['state'] ?? null) === HarnessJobState::CANCELED->value;
    }

    public function requestCancel(
        string $jobUid,
        string $actor = 'user'
    ): bool {
        $jobUid = HarnessPersistenceValue::uid($jobUid, 'job_uid');
        $actor = HarnessPersistenceValue::identifier($actor, 'job.cancel_actor', 120);
        return $this->db->transactional(function () use ($jobUid, $actor): bool {
            $row = $this->db->fetchOne(
                'SELECT * FROM jobs WHERE job_uid=? FOR UPDATE',
                [$jobUid]
            );
            if ($row === null) {
                return false;
            }
            $job = HarnessJob::fromRow($row);
            if ($job->state->isTerminal()) {
                return $job->state === HarnessJobState::CANCELED;
            }
            if ($job->cancelRequested) {
                return true;
            }
            $nowSql = HarnessPersistenceValue::mysqlDate($this->nowValue());
            if ($job->state === HarnessJobState::RUNNING) {
                $affected = $this->db->execute(
                    "UPDATE jobs SET cancel_requested=1, updated_at=?
                     WHERE id=? AND state='running' AND cancel_requested=0
                       AND fence_generation=?",
                    [$nowSql, $job->id, $job->fenceGeneration]
                );
            } else {
                $affected = $this->db->execute(
                    "UPDATE jobs
                     SET state='canceled', cancel_requested=1,
                         lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                         completed_at=?, updated_at=?
                     WHERE id=? AND state IN ('queued','retry_wait')
                       AND fence_generation=?",
                    [$nowSql, $nowSql, $job->id, $job->fenceGeneration]
                );
            }
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Job cancellation lost its fenced CAS.');
            }
            $this->appendEventLocked(
                $job->id,
                'job.cancel_requested',
                'job.cancel_requested',
                ['actor' => $actor],
                'user',
                $actor
            );
            return true;
        });
    }

    public function retryFailed(
        string $jobUid,
        string $requestKey,
        string $actor,
        int $additionalAttempts = 1
    ): HarnessJob {
        $jobUid = HarnessPersistenceValue::uid($jobUid, 'job_uid');
        $requestKey = HarnessPersistenceValue::asciiString(
            $requestKey,
            'job.retry_request_key',
            1,
            191
        );
        $actor = HarnessPersistenceValue::identifier($actor, 'job.retry_actor', 120);
        $additionalAttempts = HarnessPersistenceValue::boundedInt(
            $additionalAttempts,
            'job.additional_attempts',
            1,
            100
        );
        $eventKey = 'job.manual_retry:' . substr(hash('sha256', $requestKey), 0, 32);

        return $this->db->transactional(function () use (
            $jobUid,
            $requestKey,
            $actor,
            $additionalAttempts,
            $eventKey
        ): HarnessJob {
            $row = $this->db->fetchOne(
                'SELECT * FROM jobs WHERE job_uid=? FOR UPDATE',
                [$jobUid]
            );
            if ($row === null) {
                throw new HarnessPersistenceException('Job does not exist.');
            }
            $job = HarnessJob::fromRow($row);
            $existing = $this->db->fetchOne(
                'SELECT event_type,event_version,actor_type,actor_id,payload_json,payload_hash
                 FROM job_events WHERE job_id=? AND event_key=?',
                [$job->id, $eventKey]
            );
            if ($existing !== null) {
                $payload = HarnessPersistenceValue::decodeJsonArray(
                    $existing['payload_json'] ?? null,
                    'job.manual_retry_event_payload'
                );
                $payloadJson = HarnessPersistenceValue::canonicalMysqlJson($payload);
                $payloadHash = HarnessPersistenceValue::hash(
                    $existing['payload_hash'] ?? null,
                    'job.manual_retry_event_payload_hash'
                );
                if (!hash_equals(hash('sha256', $payloadJson), $payloadHash)) {
                    throw new HarnessPersistenceException(
                        'Manual retry Event payload failed its canonical hash check.'
                    );
                }
                if (($existing['event_type'] ?? null) !== 'job.manual_retry'
                    || (int)($existing['event_version'] ?? 0) !== 1
                    || ($existing['actor_type'] ?? null) !== 'user'
                    || ($existing['actor_id'] ?? null) !== $actor
                    || ($payload['request_key_hash'] ?? null) !== hash('sha256', $requestKey)
                    || ($payload['additional_attempts'] ?? null) !== $additionalAttempts) {
                    throw new HarnessIdempotencyConflictException(
                        'Manual retry request key resolved to different evidence.'
                    );
                }
                return $job;
            }
            if (!in_array($job->state, [
                HarnessJobState::FAILED,
                HarnessJobState::DEAD_LETTER,
            ], true)) {
                throw new HarnessStateTransitionException(
                    'Only failed or dead-letter Jobs accept manual retry.'
                );
            }
            $maxAttempts = max(
                $job->maxAttempts,
                $job->attempt + $additionalAttempts
            );
            $nowSql = HarnessPersistenceValue::mysqlDate($this->nowValue());
            $affected = $this->db->execute(
                "UPDATE jobs
                 SET state='queued', max_attempts=?, available_at=?,
                     cancel_requested=0, error_code=NULL, error_message=NULL,
                     completed_at=NULL, updated_at=?
                 WHERE id=? AND state=? AND fence_generation=?",
                [
                    $maxAttempts,
                    $nowSql,
                    $nowSql,
                    $job->id,
                    $job->state->value,
                    $job->fenceGeneration,
                ]
            );
            if ($affected !== 1) {
                throw new HarnessLeaseLostException('Manual Job retry lost its fenced CAS.');
            }
            $this->appendEventLocked(
                $job->id,
                'job.manual_retry',
                $eventKey,
                [
                    'request_key_hash' => hash('sha256', $requestKey),
                    'prior_state' => $job->state->value,
                    'attempt' => $job->attempt,
                    'additional_attempts' => $additionalAttempts,
                    'max_attempts' => $maxAttempts,
                ],
                'user',
                $actor
            );
            return $this->requireJob($job->id, true);
        });
    }

    /** @return list<array<string,mixed>> */
    public function readEvents(string $jobUid, int $after = 0, int $limit = 100): array
    {
        $job = $this->findByUid($jobUid);
        if ($job === null) {
            return [];
        }
        $after = HarnessPersistenceValue::nonNegativeInt($after, 'job.event_after');
        $limit = HarnessPersistenceValue::boundedInt($limit, 'job.event_limit', 1, 1000);
        $events = $this->db->fetchAll(
            "SELECT * FROM job_events
             WHERE job_id=? AND event_no>? ORDER BY event_no LIMIT {$limit}",
            [$job->id, $after]
        );
        foreach ($events as &$event) {
            $payloadJson = HarnessPersistenceValue::canonicalMysqlJson(
                HarnessPersistenceValue::decodeJsonArray(
                    $event['payload_json'] ?? null,
                    'job.event_payload_json'
                )
            );
            $payloadHash = HarnessPersistenceValue::hash(
                $event['payload_hash'] ?? null,
                'job.event_payload_hash'
            );
            if (!hash_equals(hash('sha256', $payloadJson), $payloadHash)) {
                throw new HarnessPersistenceException(
                    'Job event payload failed its canonical hash check.'
                );
            }
            $event['payload_json'] = $payloadJson;
            $event['payload_hash'] = $payloadHash;
        }
        unset($event);
        return $events;
    }

    /** @param array<string,mixed> $result */
    private function releaseTerminal(
        HarnessJobLease $lease,
        HarnessJobState $state,
        int $progress,
        array $result,
        ?string $errorCode,
        ?string $errorMessage,
        string $eventType,
        bool $allowCanceled = false
    ): HarnessJob {
        if (!$state->isTerminal()) {
            throw new InvalidArgumentException('Job terminal release requires a terminal state.');
        }
        $progress = HarnessPersistenceValue::boundedInt($progress, 'job.progress', 0, 100);
        if ($state === HarnessJobState::SUCCEEDED && $progress !== 100) {
            throw new InvalidArgumentException('Succeeded Job requires 100 progress.');
        }
        HarnessSensitiveDataGuard::assertSafeStructure($result, 'job.result');
        $resultJson = $result === []
            ? null
            : HarnessPersistenceValue::canonicalMysqlJson($result);
        $errorCode = HarnessPersistenceValue::optionalString($errorCode, 'job.error_code', 64);
        $errorMessage = HarnessPersistenceValue::optionalString(
            $errorMessage,
            'job.error_message',
            500
        );
        return $this->db->transactional(function () use (
            $lease,
            $state,
            $progress,
            $resultJson,
            $errorCode,
            $errorMessage,
            $eventType,
            $allowCanceled
        ): HarnessJob {
            $this->requireOwnedLease($lease);
            return $this->releaseTerminalLocked(
                $lease,
                $state,
                $progress,
                $resultJson,
                $errorCode,
                $errorMessage,
                $eventType,
                HarnessPersistenceValue::mysqlDate($this->nowValue()),
                $allowCanceled
            );
        });
    }

    private function releaseTerminalLocked(
        HarnessJobLease $lease,
        HarnessJobState $state,
        int $progress,
        ?string $resultJson,
        ?string $errorCode,
        ?string $errorMessage,
        string $eventType,
        string $nowSql,
        bool $allowCanceled = false
    ): HarnessJob {
        $cancelPredicate = $allowCanceled ? '1=1' : 'cancel_requested=0';
        $affected = $this->db->execute(
            "UPDATE jobs
             SET state=?, progress=?, result_json=?,
                 lease_owner=NULL, lease_token=NULL, lease_expires_at=NULL,
                 error_code=?, error_message=?, completed_at=?, updated_at=?
             WHERE id=? AND state='running' AND {$cancelPredicate}
               AND lease_owner=? AND lease_token=? AND fence_generation=?
               AND lease_expires_at=? AND lease_expires_at>=?",
            [
                $state->value,
                $progress,
                $resultJson,
                $errorCode,
                $errorMessage,
                $nowSql,
                $nowSql,
                $lease->job->id,
                $lease->owner,
                $lease->token,
                $lease->generation,
                $lease->expiresAt,
                $nowSql,
            ]
        );
        if ($affected !== 1) {
            throw new HarnessLeaseLostException('Job terminal release lost ownership.');
        }
        $this->appendEventLocked(
            $lease->job->id,
            $eventType,
            $eventType . ':' . $lease->generation,
            [
                'attempt' => $lease->job->attempt,
                'state' => $state->value,
                'error_code' => $errorCode,
            ],
            'worker',
            $lease->owner
        );
        return $this->requireJob($lease->job->id, true);
    }

    private function requireOwnedLease(HarnessJobLease $lease): HarnessJob
    {
        $job = $this->requireJob($lease->job->id, true);
        if ($job->state !== HarnessJobState::RUNNING
            || $job->leaseOwner !== $lease->owner
            || $job->leaseToken !== $lease->token
            || $job->fenceGeneration !== $lease->generation
            || $job->leaseExpiresAt !== $lease->expiresAt) {
            throw new HarnessLeaseLostException('Job lease no longer owns the row.');
        }
        return $job;
    }

    private function finalizeUnclaimed(
        HarnessJob $job,
        HarnessJobState $state,
        string $nowSql,
        string $eventType,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        $affected = $this->db->execute(
            "UPDATE jobs SET state=?, lease_owner=NULL, lease_token=NULL,
                 lease_expires_at=NULL, error_code=?, error_message=?,
                 completed_at=?, updated_at=?
             WHERE id=? AND state=? AND fence_generation=?",
            [
                $state->value,
                $errorCode,
                $errorMessage,
                $nowSql,
                $nowSql,
                $job->id,
                $job->state->value,
                $job->fenceGeneration,
            ]
        );
        if ($affected !== 1) {
            throw new HarnessLeaseLostException('Unclaimed Job finalization lost its fence.');
        }
        $this->appendEventLocked(
            $job->id,
            $eventType,
            $eventType . ':' . $job->fenceGeneration,
            ['state' => $state->value, 'error_code' => $errorCode],
            'system',
            'job-kernel'
        );
    }

    private function appendEventLocked(
        int $jobId,
        string $eventType,
        string $eventKey,
        array $payload,
        string $actorType,
        string $actorId
    ): void {
        $eventType = HarnessPersistenceValue::identifier($eventType, 'job.event_type', 96);
        $eventKey = HarnessPersistenceValue::identifier($eventKey, 'job.event_key', 191);
        $actorType = HarnessPersistenceValue::identifier($actorType, 'job.actor_type', 32);
        $actorId = HarnessPersistenceValue::identifier($actorId, 'job.actor_id', 191);
        HarnessSensitiveDataGuard::assertSafeStructure($payload, 'job.event_payload');
        $payloadJson = HarnessPersistenceValue::canonicalMysqlJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $existing = $this->db->fetchOne(
            'SELECT event_type, payload_hash FROM job_events WHERE job_id=? AND event_key=?',
            [$jobId, $eventKey]
        );
        if ($existing !== null) {
            if (($existing['event_type'] ?? null) !== $eventType
                || !is_string($existing['payload_hash'] ?? null)
                || !hash_equals($existing['payload_hash'], $payloadHash)) {
                throw new HarnessIdempotencyConflictException(
                    'Job event key resolved to different evidence.'
                );
            }
            return;
        }
        $job = $this->requireJob($jobId, true);
        $eventNo = $job->nextEventNo;
        if ($eventNo === PHP_INT_MAX) {
            throw new HarnessPersistenceException('Job event sequence is exhausted.');
        }
        if ($this->db->execute(
            'UPDATE jobs SET next_event_no=? WHERE id=? AND next_event_no=?',
            [$eventNo + 1, $jobId, $eventNo]
        ) !== 1) {
            throw new HarnessPersistenceException('Job event sequencer lost its locked CAS.');
        }
        if ($this->db->execute(
            "INSERT INTO job_events
                (job_id, event_no, event_type, event_version, event_key,
                 actor_type, actor_id, payload_json, payload_hash, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))",
            [
                $jobId,
                $eventNo,
                $eventType,
                $eventKey,
                $actorType,
                $actorId,
                $payloadJson,
                $payloadHash,
            ]
        ) !== 1) {
            throw new HarnessPersistenceException('Job event insert failed.');
        }
    }

    private function assertOwnedReferences(HarnessJobDraft $draft): void
    {
        if ($draft->runId !== null) {
            $run = $this->db->fetchOne(
                'SELECT id, user_id, novel_id, chapter_id FROM generation_runs WHERE id=? FOR UPDATE',
                [$draft->runId]
            );
            if ($run === null
                || HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'job.run.id')
                    !== $draft->runId
                || HarnessPersistenceValue::positiveInt(
                    $run['user_id'] ?? null,
                    'job.run.user_id'
                ) !== $draft->userId
                || HarnessPersistenceValue::positiveInt(
                    $run['novel_id'] ?? null,
                    'job.run.novel_id'
                ) !== $draft->novelId
                || HarnessPersistenceValue::optionalPositiveInt(
                    $run['chapter_id'] ?? null,
                    'job.run.chapter_id'
                ) !== $draft->chapterId) {
                throw new HarnessPersistenceException(
                    'Job Run must match its immutable ownership coordinates.'
                );
            }
        }
        if ($draft->stepId !== null) {
            $step = $this->db->fetchOne(
                'SELECT id, run_id FROM generation_steps WHERE id=? FOR UPDATE',
                [$draft->stepId]
            );
            if ($step === null
                || HarnessPersistenceValue::positiveInt($step['id'] ?? null, 'job.step.id')
                    !== $draft->stepId
                || HarnessPersistenceValue::positiveInt(
                    $step['run_id'] ?? null,
                    'job.step.run_id'
                ) !== $draft->runId) {
                throw new HarnessPersistenceException(
                    'Job Step must belong to the identified Run.'
                );
            }
        }
    }

    private function requireJob(int $id, bool $forUpdate): HarnessJob
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM jobs WHERE id=?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [HarnessPersistenceValue::positiveInt($id, 'job.id')]
        );
        if ($row === null) {
            throw new HarnessPersistenceException('Job does not exist.');
        }
        return HarnessJob::fromRow($row);
    }

    private function nowValue(): DateTimeImmutable
    {
        if ($this->now === null) {
            $row = $this->db->fetchOne('SELECT UTC_TIMESTAMP(6) AS now_utc');
            $value = HarnessPersistenceValue::optionalMysqlDate(
                $row['now_utc'] ?? null,
                'job.database_now_utc'
            );
            if ($value === null) {
                throw new HarnessPersistenceException(
                    'Job database clock did not return UTC_TIMESTAMP(6).'
                );
            }
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new HarnessPersistenceException('Job clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }
}
