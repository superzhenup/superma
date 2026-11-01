<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WorkflowRunner.php';
require_once __DIR__ . '/ChapterRunInputSnapshot.php';
require_once dirname(__DIR__) . '/HarnessControlPlane.php';
require_once dirname(__DIR__) . '/HarnessFeatureSnapshot.php';
require_once dirname(__DIR__) . '/stages/bootstrap.php';
require_once dirname(__DIR__, 2) . '/quality/WritePolicySnapshot.php';

enum ChapterRunEntryPoint: string
{
    case ASYNC_WORKER = 'async_worker';
    case SSE = 'sse';
    case DAEMON = 'daemon';
}

final readonly class ChapterRunInvocation
{
    /** @var array<string,mixed> */
    public array $goal;
    public string $inputHash;
    public string $requestInputHash;

    /**
     * Build the immutable request shared by every production Chapter entry.
     *
     * The entry-specific v1 payload schemas and idempotency prefixes are kept
     * byte-compatible with Runs admitted before this constructor was shared.
     * New callers must not assemble either contract at the transport boundary.
     *
     * @param string $authorityIdentity Writing task id for async Worker;
     *        exact 32-byte hexadecimal Chapter claim for SSE/Daemon.
     * @param array<string,mixed>|null $baseSnapshot Required for SSE/Daemon.
     * @param array<string,mixed> $goal Extra immutable Goal coordinates.
     */
    public static function forEntryPoint(
        ChapterRunEntryPoint $entryPoint,
        int $userId,
        int $novelId,
        int $chapterId,
        string $authorityIdentity,
        WritePolicySnapshot $policySnapshot,
        HarnessFeatureSnapshot $featureSnapshot,
        ChapterRunInputSnapshot $inputSnapshot,
        string $workerId,
        ?array $baseSnapshot = null,
        array $goal = [],
        ?DateTimeImmutable $deadline = null,
        RunBudget $budget = new RunBudget(),
        int $maxAttempts = 3
    ): self {
        HarnessPersistenceValue::positiveInt($userId, 'chapter_run.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'chapter_run.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'chapter_run.chapter_id');

        if ($entryPoint === ChapterRunEntryPoint::ASYNC_WORKER) {
            if (preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $authorityIdentity) !== 1) {
                throw new InvalidArgumentException(
                    'Chapter Run writing task identity is invalid.'
                );
            }
            $taskId = $authorityIdentity;
            $idempotencyKey = 'chapter:v1:writing-task:' . $taskId;
            $inputCoordinates = [
                'schema' => 'chapter_run.worker_input.v1',
                'task_id' => $taskId,
                'user_id' => $userId,
                'novel_id' => $novelId,
                'chapter_id' => $chapterId,
                'policy_checksum' => $policySnapshot->checksum(),
                'feature_checksum' => $featureSnapshot->checksum,
            ];
        } else {
            if (!is_string($authorityIdentity)
                || preg_match('/^[a-f0-9]{32}$/D', $authorityIdentity) !== 1
                || !is_array($baseSnapshot)) {
                throw new InvalidArgumentException(
                    'Chapter Run transport claim identity is incomplete.'
                );
            }
            $baseClaim = $baseSnapshot['claim_token'] ?? null;
            if (!is_string($baseClaim)
                || !hash_equals($authorityIdentity, $baseClaim)) {
                throw new InvalidArgumentException(
                    'Chapter Run base snapshot does not authenticate its transport claim.'
                );
            }
            [$transport, $inputSchema] = $entryPoint === ChapterRunEntryPoint::SSE
                ? ['sse', 'chapter_run.sse_input.v1']
                : ['daemon', 'chapter_run.daemon_input.v1'];
            $idempotencyKey = 'chapter:v1:' . $transport . ':' . $authorityIdentity;
            $inputCoordinates = [
                'schema' => $inputSchema,
                'user_id' => $userId,
                'novel_id' => $novelId,
                'chapter_id' => $chapterId,
                'base_snapshot' => $baseSnapshot,
                'policy_checksum' => $policySnapshot->checksum(),
                'feature_checksum' => $featureSnapshot->checksum,
            ];
        }

        $goal = array_replace($goal, [
            'kind' => 'chapter_generate',
            'chapter_id' => $chapterId,
            'entry_point' => $entryPoint->value,
        ]);

        return new self(
            $entryPoint,
            $userId,
            $novelId,
            $chapterId,
            $idempotencyKey,
            hash(
                'sha256',
                HarnessPersistenceValue::canonicalJson($inputCoordinates)
            ),
            $policySnapshot,
            $featureSnapshot,
            $inputSnapshot,
            $goal,
            $workerId,
            $deadline,
            $budget,
            $maxAttempts
        );
    }

    /**
     * @param array<string,mixed> $goal
     */
    public function __construct(
        public ChapterRunEntryPoint $entryPoint,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public string $idempotencyKey,
        string $inputHash,
        public WritePolicySnapshot $policySnapshot,
        public HarnessFeatureSnapshot $featureSnapshot,
        public ChapterRunInputSnapshot $inputSnapshot,
        array $goal,
        public string $workerId,
        public ?DateTimeImmutable $deadline = null,
        public RunBudget $budget = new RunBudget(),
        public int $maxAttempts = 3
    ) {
        HarnessPersistenceValue::positiveInt($userId, 'chapter_run.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'chapter_run.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'chapter_run.chapter_id');
        HarnessPersistenceValue::asciiString(
            $idempotencyKey,
            'chapter_run.idempotency_key',
            1,
            191
        );
        $this->requestInputHash = HarnessPersistenceValue::hash(
            $inputHash,
            'chapter_run.request_input_hash'
        );
        $this->inputHash = $inputSnapshot->bindInputHash($this->requestInputHash);
        $inputSnapshot->assertScope($novelId, $chapterId);
        HarnessPersistenceValue::identifier($workerId, 'chapter_run.worker_id', 120);
        HarnessPersistenceValue::boundedInt($maxAttempts, 'chapter_run.max_attempts', 1, 100);
        $budget->assertFunded();
        $goal['request_input_hash'] = $this->requestInputHash;
        $goal['input_snapshot_checksum'] = $inputSnapshot->checksum;
        $this->goal = RuntimeData::normalizeArray($goal, 'chapter_run.goal');
    }

    /** @return array<string,mixed> */
    public function flagSnapshot(): array
    {
        return [
            'schema' => 'chapter_run.flags.v1',
            'entry_point' => $this->entryPoint->value,
            'write_policy' => $this->policySnapshot->toArray(),
            'policy_checksum' => $this->policySnapshot->checksum(),
            'features' => $this->featureSnapshot->toArray(),
            'input_snapshot_checksum' => $this->inputSnapshot->checksum,
            'input_snapshot' => $this->inputSnapshot->toArray(),
        ];
    }

    public function runUid(): string
    {
        return substr(hash(
            'sha256',
            implode("\0", [
                'chapter-generate-run-v1',
                (string)$this->userId,
                $this->idempotencyKey,
                $this->inputHash,
                $this->featureSnapshot->checksum,
            ])
        ), 0, 32);
    }
}

final readonly class ChapterRunSession
{
    public function __construct(
        public RunRequest $request,
        public WorkflowRuntimeStore $store,
        public ChapterRunAuthorityFence $authority
    ) {
    }
}

interface ChapterRunCoordinator
{
    public function open(ChapterRunInvocation $invocation): ChapterRunSession;
}

/** Production M62 Run creator/claimer used by all three entry adapters. */
final class M62ChapterRunCoordinator implements ChapterRunCoordinator
{
    private HarnessPersistenceDriver $db;
    private GenerationRunRepository $runs;
    private ?Closure $clock;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $clock = null,
        ?callable $runTokenFactory = null,
        private readonly int $leaseSeconds = 120,
        ?HarnessRunAdmissionGuard $admissionGuard = null
    ) {
        $productionAdmission = $admissionGuard
            ?? ($connection instanceof PDO ? new DurableHarnessRunAdmissionGuard() : null);
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->clock = $clock === null ? null : Closure::fromCallable($clock);
        $this->runs = new GenerationRunRepository(
            $this->db,
            $clock,
            $runTokenFactory,
            $productionAdmission
        );
        HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'chapter_run.lease_seconds',
            15,
            3600
        );
    }

    public function open(ChapterRunInvocation $invocation): ChapterRunSession
    {
        ChapterRunService::assertAuthoritySnapshot(
            $invocation->policySnapshot,
            $invocation->featureSnapshot
        );
        $now = $this->now();
        $run = $this->runs->findByIdempotency(
            $invocation->userId,
            $invocation->idempotencyKey
        );
        $deadline = $invocation->deadline;
        if ($deadline === null && $run !== null && $run->deadlineAt !== null) {
            $deadline = new DateTimeImmutable(
                $run->deadlineAt,
                new DateTimeZone('UTC')
            );
        }
        $deadline ??= $now->modify('+3600 seconds');
        if ($deadline <= $now) {
            throw new DeadlineExceededException();
        }

        $draft = new GenerationRunDraft(
            $invocation->runUid(),
            $invocation->userId,
            $invocation->novelId,
            $invocation->chapterId,
            ChapterRunService::QUEUE,
            ChapterRunService::RUN_TYPE,
            ChapterRunService::WORKFLOW_NAME,
            ChapterRunService::WORKFLOW_VERSION,
            'authority',
            $invocation->idempotencyKey,
            ChapterRunService::ACTIVE_LOCK_KEY,
            $invocation->inputHash,
            $invocation->flagSnapshot(),
            $invocation->goal,
            $now,
            $invocation->maxAttempts,
            promptManifestHash: $invocation->inputSnapshot->promptManifestHash(),
            deadlineAt: $deadline,
            tokenBudget: $invocation->budget->tokenLimit,
            costBudget: $invocation->budget->costLimit
        );
        if ($run === null) {
            try {
                $run = $this->runs->createIdempotent($draft);
            } catch (HarnessIdempotencyConflictException $exception) {
                $run = $this->runs->findByIdempotency(
                    $invocation->userId,
                    $invocation->idempotencyKey
                );
                if ($run === null) {
                    throw $exception;
                }
            }
        }
        $this->assertExistingRun($run, $invocation, $deadline);
        $lease = $this->runs->claimByUidWithEvents(
            $run->runUid,
            $invocation->workerId,
            $this->leaseSeconds,
            ChapterRunService::ACTIVE_LOCK_KEY
        );
        if (!$lease instanceof GenerationRunLease) {
            throw new LeaseLostException(
                'Chapter Run is not claimable by this entry adapter.'
            );
        }

        $request = new RunRequest(
            $lease->run->id,
            $lease->run->runUid,
            $invocation->userId,
            $invocation->novelId,
            $invocation->chapterId,
            ChapterRunService::QUEUE,
            ChapterRunService::RUN_TYPE,
            ChapterRunService::WORKFLOW_NAME,
            ChapterRunService::WORKFLOW_VERSION,
            'authority',
            $invocation->idempotencyKey,
            $invocation->inputHash,
            $invocation->flagSnapshot(),
            $invocation->goal,
            HarnessPersistenceValue::mysqlDate($deadline),
            $invocation->budget
        );

        return new ChapterRunSession(
            $request,
            new M62WorkflowRuntimeStore(
                $this->db,
                $lease,
                $this->clock
            ),
            new ChapterRunAuthorityFence(
                $lease->run->id,
                $lease->run->runUid,
                $lease->owner,
                $lease->token,
                $lease->generation
            )
        );
    }

    private function assertExistingRun(
        GenerationRun $run,
        ChapterRunInvocation $invocation,
        DateTimeImmutable $deadline
    ): void {
        $goal = HarnessPersistenceValue::canonicalMysqlJson($invocation->goal);
        $flags = HarnessPersistenceValue::canonicalMysqlJson($invocation->flagSnapshot());
        $stored = $this->db->fetchOne(
            'SELECT flag_snapshot_json, token_budget, cost_budget FROM generation_runs WHERE id=?',
            [$run->id]
        );
        $storedFlags = $stored === null
            ? null
            : HarnessPersistenceValue::canonicalMysqlJson(
                HarnessPersistenceValue::decodeJsonArray(
                    $stored['flag_snapshot_json'] ?? null,
                    'chapter_run.stored_flag_snapshot'
                )
            );
        $matches = $run->runUid === $invocation->runUid()
            && $run->userId === $invocation->userId
            && $run->novelId === $invocation->novelId
            && $run->chapterId === $invocation->chapterId
            && $run->queue === ChapterRunService::QUEUE
            && $run->runType === ChapterRunService::RUN_TYPE
            && $run->workflowName === ChapterRunService::WORKFLOW_NAME
            && $run->workflowVersion === ChapterRunService::WORKFLOW_VERSION
            && $run->authorityMode === 'authority'
            && $run->idempotencyKey === $invocation->idempotencyKey
            && hash_equals($run->inputHash, $invocation->inputHash)
            && $run->deadlineAt === HarnessPersistenceValue::mysqlDate($deadline)
            && (int)($stored['token_budget'] ?? -1) === $invocation->budget->tokenLimit
            && hash_equals($run->goalJson, $goal)
            && is_string($storedFlags)
            && hash_equals($storedFlags, $flags);
        if (!$matches) {
            throw new HarnessIdempotencyConflictException(
                'Chapter Run idempotency resolved to a different immutable request.'
            );
        }
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            $now = ($this->clock)();
            if (!$now instanceof DateTimeImmutable) {
                throw new RuntimeControlException(
                    'Chapter Run clock must return DateTimeImmutable.'
                );
            }
            return $now->setTimezone(new DateTimeZone('UTC'));
        }

        $row = $this->db->fetchOne('SELECT UTC_TIMESTAMP(6) AS now_utc');
        $value = HarnessPersistenceValue::optionalMysqlDate(
            $row['now_utc'] ?? null,
            'chapter_run.database_now_utc'
        );
        if ($value === null) {
            throw new HarnessPersistenceException(
                'Chapter Run database clock did not return UTC_TIMESTAMP(6).'
            );
        }
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

/**
 * Single business entry for async Worker, direct SSE and Daemon transports.
 * The frozen snapshot is the only rollout decision: off/shadow callers must
 * remain on their legacy path and no authority Run is created for them.
 */
final class ChapterRunService
{
    public const QUEUE = 'chapter';
    public const RUN_TYPE = 'chapter.generate';
    public const WORKFLOW_NAME = 'chapter_generate';
    public const WORKFLOW_VERSION = 'v1';
    public const WORKFLOW_ID = 'chapter_generate_v1';
    public const ACTIVE_LOCK_KEY = 'canonical:main';

    private Closure $workflowFactory;
    private ?Closure $attemptExecutor;

    /**
     * @param null|callable(LegacyChapterStageAdapter):WorkflowDefinition $workflowFactory
     * @param null|callable(ChapterRunInvocation,LegacyChapterStageAdapter):WorkflowExecutionResult $attemptExecutor
     */
    public function __construct(
        private readonly ChapterRunCoordinator $coordinator,
        ?callable $workflowFactory = null,
        private readonly int $leaseSeconds = 120,
        ?callable $attemptExecutor = null
    ) {
        $this->workflowFactory = $workflowFactory === null
            ? static function (LegacyChapterStageAdapter $adapter): WorkflowDefinition {
                $factory = require dirname(__DIR__, 3)
                    . '/config/workflows/chapter_generate_v1.php';
                if (!is_callable($factory)) {
                    throw new WorkflowDefinitionException(
                        'chapter_generate_v1 config must return a Workflow factory.'
                    );
                }
                $workflow = $factory($adapter);
                if (!$workflow instanceof WorkflowDefinition) {
                    throw new WorkflowDefinitionException(
                        'chapter_generate_v1 factory returned an invalid definition.'
                    );
                }
                return $workflow;
            }
            : Closure::fromCallable($workflowFactory);
        $this->attemptExecutor = $attemptExecutor === null
            ? null
            : Closure::fromCallable($attemptExecutor);
        HarnessPersistenceValue::boundedInt(
            $leaseSeconds,
            'chapter_run.lease_seconds',
            15,
            3600
        );
    }

    public static function shouldUseAuthority(
        WritePolicySnapshot $snapshot,
        HarnessFeatureSnapshot $features
    ): bool
    {
        self::assertFrozenControlsAgree($snapshot, $features);
        return $features->pipelineV2
            && HarnessControlPlane::usesAuthority($snapshot->harnessMode());
    }

    public static function assertAuthoritySnapshot(
        WritePolicySnapshot $snapshot,
        HarnessFeatureSnapshot $features
    ): void
    {
        if (!self::shouldUseAuthority($snapshot, $features)) {
            throw new RuntimeControlException(
                'Only a frozen fenced authority snapshot with pipeline_v2 may start a Chapter Run.'
            );
        }
        try {
            HarnessControlPlane::assertAuthorityIsFenced(
                $snapshot->harnessMode(),
                $snapshot->fencingEnforced()
            );
        } catch (HarnessFencingEnforcementException $exception) {
            throw new RuntimeControlException(previous: $exception);
        }
    }

    private static function assertFrozenControlsAgree(
        WritePolicySnapshot $snapshot,
        HarnessFeatureSnapshot $features
    ): void {
        if ($snapshot->harnessMode() !== $features->effectiveMode
            || $snapshot->fencingEnforced() !== $features->fencingEnforced) {
            throw new RuntimeControlException(
                'Frozen write policy and Harness feature admission disagree.'
            );
        }
    }

    public function runAsyncWorker(
        ChapterRunInvocation $invocation,
        LegacyChapterStageAdapter $adapter
    ): WorkflowExecutionResult {
        $this->assertEntry($invocation, ChapterRunEntryPoint::ASYNC_WORKER);
        return $this->run($invocation, $adapter);
    }

    public function runSse(
        ChapterRunInvocation $invocation,
        LegacyChapterStageAdapter $adapter
    ): WorkflowExecutionResult {
        $this->assertEntry($invocation, ChapterRunEntryPoint::SSE);
        return $this->run($invocation, $adapter);
    }

    public function runDaemon(
        ChapterRunInvocation $invocation,
        LegacyChapterStageAdapter $adapter
    ): WorkflowExecutionResult {
        $this->assertEntry($invocation, ChapterRunEntryPoint::DAEMON);
        return $this->run($invocation, $adapter);
    }

    /** Dispatch one durable attempt according to its frozen entry point. */
    public function dispatch(
        ChapterRunInvocation $invocation,
        LegacyChapterStageAdapter $adapter
    ): WorkflowExecutionResult {
        return match ($invocation->entryPoint) {
            ChapterRunEntryPoint::ASYNC_WORKER => $this->runAsyncWorker(
                $invocation,
                $adapter
            ),
            ChapterRunEntryPoint::SSE => $this->runSse($invocation, $adapter),
            ChapterRunEntryPoint::DAEMON => $this->runDaemon($invocation, $adapter),
        };
    }

    /**
     * Run durable attempts until this transport receives a non-retry outcome.
     * Waiting, heartbeat and cancel I/O remain in the entry-owned callback.
     * Returning a non-retry result from that callback short-circuits the loop
     * (used by the async Worker when cancellation is observed while waiting).
     *
     * @param callable():LegacyChapterStageAdapter $adapterFactory
     * @param callable(DateTimeImmutable,WorkflowExecutionResult):(?WorkflowExecutionResult) $onRetryWait
     */
    public function runToTerminal(
        ChapterRunInvocation $invocation,
        callable $adapterFactory,
        callable $onRetryWait
    ): WorkflowExecutionResult {
        $makeAdapter = Closure::fromCallable($adapterFactory);
        $waitForRetry = Closure::fromCallable($onRetryWait);

        do {
            $adapter = $makeAdapter();
            if (!$adapter instanceof LegacyChapterStageAdapter) {
                throw new InvalidArgumentException(
                    'Chapter Run adapter factory returned an invalid adapter.'
                );
            }
            $result = $this->dispatch($invocation, $adapter);
            if ($result->state !== GenerationRunState::RETRY_WAIT) {
                return $result;
            }
            if ($result->retryAt === null) {
                throw new WorkflowCheckpointException(
                    'Chapter Run retry result omitted retry_at.'
                );
            }
            $retryAt = new DateTimeImmutable(
                $result->retryAt,
                new DateTimeZone('UTC')
            );
            $transportResult = $waitForRetry($retryAt, $result);
            if ($transportResult !== null) {
                if (!$transportResult instanceof WorkflowExecutionResult
                    || $transportResult->state === GenerationRunState::RETRY_WAIT) {
                    throw new InvalidArgumentException(
                        'Chapter Run retry callback returned an invalid terminal result.'
                    );
                }
                return $transportResult;
            }
        } while (true);
    }

    private function run(
        ChapterRunInvocation $invocation,
        LegacyChapterStageAdapter $adapter
    ): WorkflowExecutionResult {
        self::assertAuthoritySnapshot(
            $invocation->policySnapshot,
            $invocation->featureSnapshot
        );
        if ($this->attemptExecutor !== null) {
            $result = ($this->attemptExecutor)($invocation, $adapter);
            if (!$result instanceof WorkflowExecutionResult) {
                throw new WorkflowCheckpointException(
                    'Chapter Run attempt executor returned an invalid result.'
                );
            }
            return $result;
        }
        $session = $this->coordinator->open($invocation);
        if ($adapter instanceof ChapterRunAuthorityAwareAdapter) {
            $adapter->bindAuthority($session->authority);
        }
        $workflow = ($this->workflowFactory)($adapter);
        if (!$workflow instanceof WorkflowDefinition) {
            throw new WorkflowDefinitionException(
                'Chapter Run Workflow factory returned an invalid definition.'
            );
        }
        return (new WorkflowRunner(
            $workflow,
            $session->store,
            leaseSeconds: $this->leaseSeconds
        ))->run($session->request);
    }

    private function assertEntry(
        ChapterRunInvocation $invocation,
        ChapterRunEntryPoint $expected
    ): void {
        if ($invocation->entryPoint !== $expected) {
            throw new InvalidArgumentException(
                'Chapter Run invocation does not match its transport adapter.'
            );
        }
    }
}
