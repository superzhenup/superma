<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/bootstrap.php';

enum HarnessJobState: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case RETRY_WAIT = 'retry_wait';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case DEAD_LETTER = 'dead_letter';
    case CANCELED = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::SUCCEEDED,
            self::FAILED,
            self::DEAD_LETTER,
            self::CANCELED,
        ], true);
    }
}

interface HarnessJobEnqueuer
{
    public function enqueue(HarnessJobDraft $draft): HarnessJob;
}

interface HarnessJobQueue extends HarnessJobEnqueuer
{
    public function claim(string $queue, string $owner, int $leaseSeconds = 120): ?HarnessJobLease;

    public function renew(HarnessJobLease $lease, int $leaseSeconds = 120): HarnessJobLease;

    /** @param array<string,mixed> $result */
    public function succeed(
        HarnessJobLease $lease,
        array $result = [],
        bool $allowCanceled = false
    ): HarnessJob;

    public function fail(
        HarnessJobLease $lease,
        string $errorCode,
        string $errorMessage
    ): HarnessJob;

    public function deadLetter(
        HarnessJobLease $lease,
        string $errorCode,
        string $errorMessage
    ): HarnessJob;

    public function cancelLease(HarnessJobLease $lease): HarnessJob;

    public function retry(
        HarnessJobLease $lease,
        DateTimeImmutable $availableAt,
        string $errorCode,
        string $errorMessage
    ): HarnessJob;

    public function retryAfter(
        HarnessJobLease $lease,
        int $delaySeconds,
        string $errorCode,
        string $errorMessage
    ): HarnessJob;

    public function cancellationRequested(HarnessJobLease $lease): bool;
}

final readonly class HarnessJobDraft
{
    public string $queue;
    public string $jobType;
    public string $idempotencyKey;
    public string $payloadJson;
    public ?string $availableAt;
    public ?string $legacyType;

    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $jobUid,
        public ?int $runId,
        public ?int $stepId,
        string $queue,
        string $jobType,
        public int $userId,
        public int $novelId,
        public ?int $chapterId,
        string $idempotencyKey,
        array $payload,
        public int $priority = 0,
        public int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
        ?string $legacyType = null,
        public ?int $legacyId = null
    ) {
        HarnessPersistenceValue::uid($jobUid, 'job_uid');
        if ($runId !== null) {
            HarnessPersistenceValue::positiveInt($runId, 'job.run_id');
        }
        if ($stepId !== null) {
            HarnessPersistenceValue::positiveInt($stepId, 'job.step_id');
            if ($runId === null) {
                throw new InvalidArgumentException('A Job Step requires a Run.');
            }
        }
        HarnessPersistenceValue::positiveInt($userId, 'job.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'job.novel_id');
        if ($chapterId !== null) {
            HarnessPersistenceValue::positiveInt($chapterId, 'job.chapter_id');
        }
        $this->queue = HarnessPersistenceValue::identifier($queue, 'job.queue', 32);
        $this->jobType = HarnessPersistenceValue::identifier($jobType, 'job.job_type', 64);
        $this->idempotencyKey = HarnessPersistenceValue::asciiString(
            $idempotencyKey,
            'job.idempotency_key',
            1,
            191
        );
        HarnessPersistenceValue::boundedInt($priority, 'job.priority', -32768, 32767);
        HarnessPersistenceValue::boundedInt($maxAttempts, 'job.max_attempts', 1, 1000);
        HarnessSensitiveDataGuard::assertSafeStructure($payload, 'job.payload');
        $this->payloadJson = HarnessPersistenceValue::canonicalMysqlJson($payload);
        HarnessSensitiveDataGuard::assertSafeText($this->payloadJson, 'job.payload');
        $this->availableAt = $availableAt === null
            ? null
            : HarnessPersistenceValue::mysqlDate(
                $availableAt->setTimezone(new DateTimeZone('UTC'))
            );
        if (($legacyType === null) !== ($legacyId === null)) {
            throw new InvalidArgumentException(
                'Legacy Job type and ID must be supplied together.'
            );
        }
        $this->legacyType = $legacyType === null
            ? null
            : HarnessPersistenceValue::identifier($legacyType, 'job.legacy_type', 32);
        if ($legacyId !== null) {
            HarnessPersistenceValue::positiveInt($legacyId, 'job.legacy_id');
        }
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return HarnessPersistenceValue::decodeJsonArray($this->payloadJson, 'job.payload_json');
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        $job = HarnessJob::fromRow($row);
        $matches = hash_equals($job->jobUid, $this->jobUid)
            && $job->runId === $this->runId
            && $job->stepId === $this->stepId
            && $job->queue === $this->queue
            && $job->jobType === $this->jobType
            && $job->userId === $this->userId
            && $job->novelId === $this->novelId
            && $job->chapterId === $this->chapterId
            && hash_equals($job->idempotencyKey, $this->idempotencyKey)
            && hash_equals($job->payloadJson, $this->payloadJson)
            && $job->priority === $this->priority
            && $job->maxAttempts === $this->maxAttempts
            && $job->legacyType === $this->legacyType
            && $job->legacyId === $this->legacyId;
        if (!$matches) {
            throw new HarnessIdempotencyConflictException(
                'Job idempotency resolved to different immutable coordinates.'
            );
        }
    }
}

final readonly class HarnessJob
{
    public function __construct(
        public int $id,
        public string $jobUid,
        public ?int $runId,
        public ?int $stepId,
        public string $queue,
        public string $jobType,
        public int $userId,
        public int $novelId,
        public ?int $chapterId,
        public string $idempotencyKey,
        public HarnessJobState $state,
        public int $priority,
        public int $progress,
        public string $payloadJson,
        public ?string $resultJson,
        public int $attempt,
        public int $maxAttempts,
        public string $availableAt,
        public ?string $leaseOwner,
        public ?string $leaseToken,
        public int $fenceGeneration,
        public ?string $leaseExpiresAt,
        public bool $cancelRequested,
        public int $nextEventNo,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $legacyType,
        public ?int $legacyId,
        public ?string $startedAt,
        public ?string $completedAt,
        public string $createdAt,
        public string $updatedAt
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'job_uid', 'run_id', 'step_id', 'queue', 'job_type',
            'user_id', 'novel_id', 'chapter_id', 'idempotency_key', 'state',
            'priority', 'progress', 'payload_json', 'result_json', 'attempt',
            'max_attempts', 'available_at', 'lease_owner', 'lease_token',
            'fence_generation', 'lease_expires_at', 'cancel_requested',
            'next_event_no', 'error_code', 'error_message', 'legacy_type',
            'legacy_id', 'started_at', 'completed_at', 'created_at', 'updated_at',
        ], 'job');
        $state = HarnessJobState::tryFrom((string)$row['state'])
            ?? throw new InvalidArgumentException('Job state is invalid.');
        $payloadJson = HarnessPersistenceValue::canonicalMysqlJson(
            HarnessPersistenceValue::decodeJsonArray($row['payload_json'], 'job.payload_json')
        );
        $resultJson = ($row['result_json'] ?? null) === null
            ? null
            : HarnessPersistenceValue::canonicalMysqlJson(
                HarnessPersistenceValue::decodeJsonArray($row['result_json'], 'job.result_json')
            );
        $leaseOwner = HarnessPersistenceValue::optionalString(
            $row['lease_owner'],
            'job.lease_owner',
            120
        );
        $leaseToken = ($row['lease_token'] ?? null) === null
            ? null
            : HarnessPersistenceValue::uid($row['lease_token'], 'job.lease_token');
        $leaseExpiresAt = HarnessPersistenceValue::optionalMysqlDate(
            $row['lease_expires_at'],
            'job.lease_expires_at'
        );
        if ($state === HarnessJobState::RUNNING) {
            if ($leaseOwner === null || $leaseToken === null || $leaseExpiresAt === null) {
                throw new InvalidArgumentException('A running Job requires a complete lease fence.');
            }
        } elseif ($leaseOwner !== null || $leaseToken !== null || $leaseExpiresAt !== null) {
            throw new InvalidArgumentException('A non-running Job cannot retain a lease fence.');
        }

        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'job.id'),
            HarnessPersistenceValue::uid($row['job_uid'], 'job.job_uid'),
            HarnessPersistenceValue::optionalPositiveInt($row['run_id'], 'job.run_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['step_id'], 'job.step_id'),
            HarnessPersistenceValue::identifier($row['queue'], 'job.queue', 32),
            HarnessPersistenceValue::identifier($row['job_type'], 'job.job_type', 64),
            HarnessPersistenceValue::positiveInt($row['user_id'], 'job.user_id'),
            HarnessPersistenceValue::positiveInt($row['novel_id'], 'job.novel_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['chapter_id'], 'job.chapter_id'),
            HarnessPersistenceValue::asciiString(
                $row['idempotency_key'],
                'job.idempotency_key',
                1,
                191
            ),
            $state,
            HarnessPersistenceValue::boundedInt($row['priority'], 'job.priority', -32768, 32767),
            HarnessPersistenceValue::boundedInt($row['progress'], 'job.progress', 0, 100),
            $payloadJson,
            $resultJson,
            HarnessPersistenceValue::nonNegativeInt($row['attempt'], 'job.attempt'),
            HarnessPersistenceValue::boundedInt($row['max_attempts'], 'job.max_attempts', 1, 1000),
            self::requiredDate($row['available_at'], 'job.available_at'),
            $leaseOwner,
            $leaseToken,
            HarnessPersistenceValue::nonNegativeInt(
                $row['fence_generation'],
                'job.fence_generation'
            ),
            $leaseExpiresAt,
            HarnessPersistenceValue::bool($row['cancel_requested'], 'job.cancel_requested'),
            HarnessPersistenceValue::positiveInt($row['next_event_no'], 'job.next_event_no'),
            HarnessPersistenceValue::optionalString($row['error_code'], 'job.error_code', 64),
            HarnessPersistenceValue::optionalString(
                $row['error_message'],
                'job.error_message',
                500
            ),
            HarnessPersistenceValue::optionalString($row['legacy_type'], 'job.legacy_type', 32),
            HarnessPersistenceValue::optionalPositiveInt($row['legacy_id'], 'job.legacy_id'),
            HarnessPersistenceValue::optionalMysqlDate($row['started_at'], 'job.started_at'),
            HarnessPersistenceValue::optionalMysqlDate($row['completed_at'], 'job.completed_at'),
            self::requiredDate($row['created_at'], 'job.created_at'),
            self::requiredDate($row['updated_at'], 'job.updated_at')
        );
    }

    private static function requiredDate(mixed $value, string $field): string
    {
        return HarnessPersistenceValue::optionalMysqlDate($value, $field)
            ?? throw new InvalidArgumentException("{$field} is required.");
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return HarnessPersistenceValue::decodeJsonArray($this->payloadJson, 'job.payload_json');
    }

    /** @return array<string,mixed>|null */
    public function result(): ?array
    {
        return $this->resultJson === null
            ? null
            : HarnessPersistenceValue::decodeJsonArray($this->resultJson, 'job.result_json');
    }
}

final readonly class HarnessJobLease
{
    private function __construct(
        public HarnessJob $job,
        public string $owner,
        public string $token,
        public int $generation,
        public string $expiresAt
    ) {
    }

    public static function fromJob(HarnessJob $job): self
    {
        if ($job->state !== HarnessJobState::RUNNING
            || $job->leaseOwner === null
            || $job->leaseToken === null
            || $job->leaseExpiresAt === null) {
            throw new InvalidArgumentException('Job has no active lease.');
        }
        return new self(
            $job,
            $job->leaseOwner,
            $job->leaseToken,
            $job->fenceGeneration,
            $job->leaseExpiresAt
        );
    }
}
