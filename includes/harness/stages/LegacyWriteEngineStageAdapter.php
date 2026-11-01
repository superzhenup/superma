<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/LegacyChapterStageAdapter.php';
require_once dirname(__DIR__) . '/persistence/bootstrap.php';
require_once dirname(__DIR__) . '/runtime/ChapterRunInputSnapshot.php';
require_once dirname(__DIR__) . '/tools/bootstrap.php';
require_once dirname(__DIR__) . '/review/bootstrap.php';
require_once dirname(__DIR__) . '/branches/ProductionCandidateTournament.php';
require_once dirname(__DIR__, 2) . '/quality/WritePolicySnapshotFactory.php';
require_once dirname(__DIR__, 2) . '/tasks/PostprocessRunner.php';
require_once dirname(__DIR__, 2) . '/write_engine.php';
require_once dirname(__DIR__) . '/model/ChapterRunAIInvocationAccounting.php';

enum LegacyCanonCommitFaultPoint: string
{
    case BEFORE_CANON_COMMIT = 'legacy_canon.before_commit';
    case AFTER_CANON_COMMIT_BEFORE_ARTIFACT =
        'legacy_canon.after_commit_before_artifact';
}

interface LegacyChapterTransport
{
    public function waiting(string $message): void;

    public function chunk(string $chunk): void;

    /** @param array<string,mixed> $payload */
    public function message(array $payload): void;

    public function heartbeat(): void;

    public function thinking(string $chunk): void;

    /** @param array<string,mixed> $novel @param array<string,mixed> $chapter */
    public function acquired(array $novel, array $chapter): void;

    /** @param array<string,mixed> $saveResult */
    public function saved(array $saveResult): void;

    /** @param array<string,mixed> $postprocessResult */
    public function postprocessed(array $postprocessResult): void;
}

final class CallbackLegacyChapterTransport implements LegacyChapterTransport
{
    /** @var array<string,Closure> */
    private array $callbacks;

    /**
     * @param array{
     *   waiting?:callable,chunk?:callable,message?:callable,heartbeat?:callable,
     *   thinking?:callable,acquired?:callable,saved?:callable,
     *   postprocessed?:callable
     * } $callbacks
     */
    public function __construct(array $callbacks = [])
    {
        $allowed = [
            'waiting', 'chunk', 'message', 'heartbeat', 'thinking',
            'acquired', 'saved', 'postprocessed',
        ];
        $unknown = array_diff(array_keys($callbacks), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Legacy Chapter transport received an unknown callback.'
            );
        }
        $this->callbacks = [];
        foreach ($callbacks as $name => $callback) {
            if (!is_callable($callback)) {
                throw new InvalidArgumentException(
                    'Legacy Chapter transport callbacks must be callable.'
                );
            }
            $this->callbacks[$name] = Closure::fromCallable($callback);
        }
    }

    public function waiting(string $message): void { $this->call('waiting', $message); }
    public function chunk(string $chunk): void { $this->call('chunk', $chunk); }
    public function message(array $payload): void { $this->call('message', $payload); }
    public function heartbeat(): void { $this->call('heartbeat'); }
    public function thinking(string $chunk): void { $this->call('thinking', $chunk); }
    public function acquired(array $novel, array $chapter): void
    {
        $this->call('acquired', $novel, $chapter);
    }
    public function saved(array $saveResult): void { $this->call('saved', $saveResult); }
    public function postprocessed(array $postprocessResult): void
    {
        $this->call('postprocessed', $postprocessResult);
    }

    private function call(string $name, mixed ...$arguments): void
    {
        if (isset($this->callbacks[$name])) {
            ($this->callbacks[$name])(...$arguments);
        }
    }
}

final class LegacyChapterArtifactStore implements
    BranchTournamentArtifactGateway,
    CommittedCanonArtifactStore
{
    public function __construct(
        private readonly RunArtifactRepository $artifacts,
        private readonly ?HarnessPersistenceDriver $transactionDriver = null
    ) {
    }

    public function storeReviewBundle(RunContext $context, array $bundle, array $definitions): array
    {
        foreach ($definitions as $bundleKey => $_definition) {
            if (!is_array($bundle[$bundleKey] ?? null)) {
                throw new PermanentStageException(
                    'Post-generation Review omitted a required evidence payload.'
                );
            }
        }
        $driver = $this->transactionDriver ?? new PdoHarnessPersistenceDriver(DB::connect());
        return $driver->transactional(function () use ($context, $bundle, $definitions): array {
            $references = [];
            foreach ($definitions as $bundleKey => [$logicalKey, $type, $sensitivity]) {
                $references[$logicalKey] = $this->storeJson(
                    $context, $logicalKey, $type, $bundle[$bundleKey], $sensitivity
                );
            }
            return $references;
        });
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function storeJson(
        RunContext $context,
        string $logicalKey,
        string $artifactType,
        array $payload,
        GenerationArtifactSensitivity $sensitivity
    ): array {
        $artifact = $this->persistArtifact(fn (): GenerationArtifact =>
            $this->artifacts->storeIdempotent(
                GenerationArtifactDraft::inlineJson(
                    self::uid($context->request->runUid, $logicalKey),
                    $context->request->runId,
                    null,
                    $artifactType,
                    $logicalKey,
                    1,
                    $payload,
                    $sensitivity,
                    self::metadata($context, $logicalKey)
                )
            )
        );
        return self::reference($artifact);
    }

    public function storePostprocessReceipt(RunContext $context, array $result): array
    {
        return $this->storeJson(
            $context,
            'postprocess.receipt',
            'projection.receipt',
            [
                'schema' => 'legacy.postprocess.receipt.v2',
                'job_id' => (int)$result['job_id'],
                'revision_hash' => (string)$result['revision_hash'],
            ],
            GenerationArtifactSensitivity::INTERNAL
        );
    }

    public function storeCommittedCanonReceipt(
        RunContext $context,
        array $payload
    ): array {
        return $this->storeJson(
            $context,
            'canonical.receipt',
            'canonical.receipt',
            $payload,
            GenerationArtifactSensitivity::INTERNAL
        );
    }

    /** @return array<string,mixed> */
    public function storeText(
        RunContext $context,
        string $logicalKey,
        string $artifactType,
        string $payload,
        GenerationArtifactSensitivity $sensitivity
    ): array {
        $artifact = $this->persistArtifact(fn (): GenerationArtifact =>
            $this->artifacts->storeIdempotent(
                GenerationArtifactDraft::inlineText(
                    self::uid($context->request->runUid, $logicalKey),
                    $context->request->runId,
                    null,
                    $artifactType,
                    $logicalKey,
                    1,
                    $payload,
                    'text/plain',
                    'utf-8',
                    $sensitivity,
                    self::metadata($context, $logicalKey)
                )
            )
        );
        return self::reference($artifact);
    }

    /**
     * Recover an Artifact that was committed immediately before a worker died
     * but whose Stage checkpoint transaction did not yet run.
     *
     * @return array{reference:array<string,mixed>,payload:string}|null
     */
    public function findText(
        RunContext $context,
        string $logicalKey,
        string $artifactType
    ): ?array {
        $artifact = $this->findExisting($context, $logicalKey, $artifactType);
        if ($artifact === null) {
            return null;
        }
        if (!is_string($artifact->inlineText)) {
            throw new WorkflowCheckpointException(
                'Recovered text Artifact has no inline text payload.'
            );
        }
        return [
            'reference' => self::reference($artifact),
            'payload' => $artifact->inlineText,
        ];
    }

    /** @return array{reference:array<string,mixed>,payload:array<string,mixed>}|null */
    public function findJson(
        RunContext $context,
        string $logicalKey,
        string $artifactType
    ): ?array {
        $artifact = $this->findExisting($context, $logicalKey, $artifactType);
        if ($artifact === null) {
            return null;
        }
        if (!is_string($artifact->inlineJson)) {
            throw new WorkflowCheckpointException(
                'Recovered JSON Artifact has no inline JSON payload.'
            );
        }
        return [
            'reference' => self::reference($artifact),
            'payload' => HarnessPersistenceValue::decodeJsonArray(
                $artifact->inlineJson,
                'legacy_stage.recovered_artifact'
            ),
        ];
    }

    /** @param array<string,mixed> $reference @return array<string,mixed> */
    public function loadJson(RunContext $context, array $reference, string $logicalKey): array
    {
        $artifact = $this->requireArtifact($context, $reference, $logicalKey);
        if ($artifact->inlineJson === null || $artifact->inlineText !== null) {
            throw new WorkflowCheckpointException('Expected an inline JSON Artifact.');
        }
        return HarnessPersistenceValue::decodeJsonArray(
            $artifact->inlineJson,
            'chapter_artifact.inline_json'
        );
    }

    /** @param array<string,mixed> $reference */
    public function loadText(RunContext $context, array $reference, string $logicalKey): string
    {
        $artifact = $this->requireArtifact($context, $reference, $logicalKey);
        if ($artifact->inlineText === null || $artifact->inlineJson !== null) {
            throw new WorkflowCheckpointException('Expected an inline text Artifact.');
        }
        return $artifact->inlineText;
    }

    /** @param array<string,mixed> $reference */
    private function requireArtifact(
        RunContext $context,
        array $reference,
        string $logicalKey
    ): GenerationArtifact {
        $uid = $reference['artifact_uid'] ?? null;
        $hash = $reference['content_hash'] ?? null;
        if (!is_string($uid) || !is_string($hash)
            || ($reference['logical_key'] ?? null) !== $logicalKey) {
            throw new WorkflowCheckpointException('Artifact reference is malformed.');
        }
        $artifact = $this->loadArtifact($uid);
        if ($artifact === null
            || $artifact->runId !== $context->request->runId
            || $artifact->logicalKey !== $logicalKey
            || !hash_equals($artifact->contentHash, $hash)) {
            throw new WorkflowCheckpointException(
                'Artifact reference does not resolve within the active Run.'
            );
        }
        return $artifact;
    }

    private function findExisting(
        RunContext $context,
        string $logicalKey,
        string $artifactType
    ): ?GenerationArtifact {
        $artifact = $this->loadArtifact(
            self::uid($context->request->runUid, $logicalKey)
        );
        if ($artifact === null) {
            return null;
        }
        if ($artifact->runId !== $context->request->runId
            || $artifact->logicalKey !== $logicalKey
            || $artifact->artifactType !== $artifactType
            || $artifact->revisionNo !== 1) {
            throw new WorkflowCheckpointException(
                'Recovered Artifact does not belong to the active Stage Run.'
            );
        }
        return $artifact;
    }

    /** @param Closure():GenerationArtifact $operation */
    private function persistArtifact(Closure $operation): GenerationArtifact
    {
        try {
            return $operation();
        } catch (HarnessSensitiveArtifactException|HarnessIdempotencyConflictException $exception) {
            throw new PermanentStageException(
                'Legacy Stage Artifact violates the durable output contract.',
                0,
                $exception
            );
        } catch (InvalidArgumentException $exception) {
            throw new PermanentStageException(
                'Legacy Stage Artifact payload is malformed.',
                0,
                $exception
            );
        } catch (PDOException|HarnessPersistenceException $exception) {
            throw new RetryableInfrastructureException(
                'Legacy Stage Artifact persistence is temporarily unavailable.',
                0,
                $exception
            );
        }
    }

    private function loadArtifact(string $artifactUid): ?GenerationArtifact
    {
        try {
            return $this->artifacts->findByUid($artifactUid);
        } catch (InvalidArgumentException $exception) {
            throw new WorkflowCheckpointException(
                'Legacy Stage Artifact row is malformed.',
                0,
                $exception
            );
        } catch (PDOException|HarnessPersistenceException $exception) {
            throw new RetryableInfrastructureException(
                'Legacy Stage Artifact lookup is temporarily unavailable.',
                0,
                $exception
            );
        }
    }

    private static function uid(string $runUid, string $logicalKey): string
    {
        return substr(hash('sha256', "chapter-artifact-v1\0{$runUid}\0{$logicalKey}"), 0, 32);
    }

    /** @return array<string,mixed> */
    private static function metadata(RunContext $context, string $logicalKey): array
    {
        return [
            'schema' => 'chapter_run_artifact.v1',
            'run_uid' => $context->request->runUid,
            'workflow' => ChapterRunService::WORKFLOW_ID,
            'logical_key' => $logicalKey,
        ];
    }

    /** @return array<string,mixed> */
    private static function reference(GenerationArtifact $artifact): array
    {
        return [
            'artifact_uid' => $artifact->artifactUid,
            'logical_key' => $artifact->logicalKey,
            'artifact_type' => $artifact->artifactType,
            'content_hash' => $artifact->contentHash,
            'size_bytes' => $artifact->sizeBytes,
        ];
    }
}

/** Run-scoped durable Artifact ledger for post-Canon Review effects. */
final class LegacyChapterReviewEffectLedger implements ReviewEffectLedger
{
    public function __construct(
        private readonly LegacyChapterArtifactStore $artifacts,
        private readonly RunContext $context
    ) {
    }

    public function find(
        string $logicalKey,
        string $artifactType
    ): ?ReviewEffectLedgerRecord {
        $found = $this->artifacts->findJson(
            $this->context,
            $logicalKey,
            $artifactType
        );
        if ($found === null) {
            return null;
        }
        $reference = $found['reference'];
        $payload = $found['payload'];
        $contentHash = $reference['content_hash'] ?? null;
        if (!is_array($reference)
            || !is_array($payload)
            || !is_string($contentHash)) {
            throw new WorkflowCheckpointException(
                'Recovered Review effect Artifact is malformed.'
            );
        }
        return new ReviewEffectLedgerRecord($payload, $contentHash, $reference);
    }

    public function storeIdempotent(
        string $logicalKey,
        string $artifactType,
        array $payload
    ): ReviewEffectLedgerRecord {
        $reference = $this->artifacts->storeJson(
            $this->context,
            $logicalKey,
            $artifactType,
            $payload,
            GenerationArtifactSensitivity::INTERNAL
        );
        $contentHash = $reference['content_hash'] ?? null;
        if (!is_string($contentHash)) {
            throw new WorkflowCheckpointException(
                'Stored Review effect Artifact has no content hash.'
            );
        }
        return new ReviewEffectLedgerRecord($payload, $contentHash, $reference);
    }
}

/**
 * Shared public-phase implementation. Entry files inject claim/restore and I/O
 * callbacks; all business phase ordering and durable Artifact boundaries live
 * here once.
 */
final class LegacyWriteEngineStageAdapter implements
    LegacyChapterStageAdapter,
    ChapterRunAuthorityAwareAdapter
{
    private Closure $acquireChapter;
    private Closure $restoreChapter;
    private Closure $legacyCancellationProbe;
    private Closure $inputSnapshotProbe;
    private Closure $emergencyEpochProbe;
    private ?Closure $waitingCallback;
    private ?ChapterRunAuthorityFence $authority = null;
    /** @var array<string,mixed>|null */
    private ?array $novel = null;
    /** @var array<string,mixed>|null */
    private ?array $chapter = null;
    /** @var array<string,mixed>|null */
    private ?array $memoryContext = null;
    private bool $memoryContextFrozen = false;
    /** @var list<array<string,mixed>>|null */
    private ?array $messages = null;
    private ?MemoryEngine $memoryEngine = null;
    private ?AIClient $usedModel = null;
    private ?string $usedModelLabel = null;
    private ?string $draft = null;
    /** @var array<string,mixed>|null */
    private ?array $usage = null;
    private ?int $durationMs = null;
    /** @var array<string,mixed>|null */
    private ?array $generationAttemptObservation = null;
    private ?ChapterToolLoopRunner $toolLoopRunner;
    private ?Closure $reviewBridgeFactory;
    private ?ReviewEffectApplier $reviewEffectApplier;
    private ?Closure $canonCommitFault;
    private ?Closure $memoryInitializer;
    private ?Closure $branchCandidateGenerator;
    /** @var array<string,mixed>|null */
    private ?array $toolDirective = null;
    /** @var array<string,mixed>|null */
    private ?array $saveResult = null;

    /**
     * @param callable(RunContext):array{n:array<string,mixed>,ch:array<string,mixed>} $acquireChapter
     * @param callable(RunContext):array{n:array<string,mixed>,ch:array<string,mixed>} $restoreChapter
     * @param array<string,mixed>|null $writingTaskLease
     * @param callable(WritePolicySnapshot,PostGenerationReviewEvidenceStore):PostGenerationReviewBridge|null $reviewBridgeFactory
     * @param callable(WritePolicySnapshot):mixed|null $emergencyEpochProbe
     * @param callable(array<string,mixed>,array<string,mixed>,ChapterRunInputSnapshot):array{engine:MemoryEngine,memoryCtx:?array}|null $memoryInitializer
     * @param callable(int,ProductionBranchTournamentScope):array{content:string,model:AIClient,usage?:array,duration_ms?:int}|null $branchCandidateGenerator
     */
    public function __construct(
        private readonly WritePolicySnapshot $policySnapshot,
        private readonly HarnessFeatureSnapshot $featureSnapshot,
        private readonly ChapterRunInputSnapshot $inputSnapshot,
        private readonly LegacyChapterArtifactStore $artifactStore,
        private readonly LegacyChapterTransport $transport,
        private readonly CommittedCanonRecoveryService $committedCanonRecovery,
        callable $acquireChapter,
        callable $restoreChapter,
        private readonly string $postprocessWorkerId,
        private readonly ?string $writingTaskId = null,
        private readonly ?array $writingTaskLease = null,
        ?callable $legacyCancellationProbe = null,
        ?callable $waitingCallback = null,
        ?ChapterToolLoopRunner $toolLoopRunner = null,
        ?callable $reviewBridgeFactory = null,
        ?callable $inputSnapshotProbe = null,
        ?ReviewEffectApplier $reviewEffectApplier = null,
        ?callable $canonCommitFault = null,
        ?callable $emergencyEpochProbe = null,
        ?callable $memoryInitializer = null,
        ?callable $branchCandidateGenerator = null,
        private readonly PDO|HarnessPersistenceDriver|null $modelAccountingConnection = null
    ) {
        $this->acquireChapter = Closure::fromCallable($acquireChapter);
        $this->restoreChapter = Closure::fromCallable($restoreChapter);
        $this->legacyCancellationProbe = $legacyCancellationProbe === null
            ? static fn (): bool => false
            : Closure::fromCallable($legacyCancellationProbe);
        $this->waitingCallback = $waitingCallback === null
            ? null
            : Closure::fromCallable($waitingCallback);
        $this->toolLoopRunner = $toolLoopRunner;
        $this->reviewBridgeFactory = $reviewBridgeFactory === null
            ? null
            : Closure::fromCallable($reviewBridgeFactory);
        $this->reviewEffectApplier = $reviewEffectApplier;
        $this->canonCommitFault = $canonCommitFault === null
            ? null
            : Closure::fromCallable($canonCommitFault);
        $this->emergencyEpochProbe = $emergencyEpochProbe === null
            ? static fn (WritePolicySnapshot $snapshot): mixed =>
                WritePolicySnapshotFactory::assertEmergencyEpochCurrent($snapshot)
            : Closure::fromCallable($emergencyEpochProbe);
        $this->memoryInitializer = $memoryInitializer === null
            ? null
            : Closure::fromCallable($memoryInitializer);
        $this->branchCandidateGenerator = $branchCandidateGenerator === null
            ? null
            : Closure::fromCallable($branchCandidateGenerator);
        $this->inputSnapshotProbe = $inputSnapshotProbe === null
            ? static fn (ChapterRunInputSnapshot $snapshot): mixed =>
                ChapterRunInputSnapshotFactory::assertCurrent($snapshot)
            : Closure::fromCallable($inputSnapshotProbe);
        HarnessPersistenceValue::identifier(
            $postprocessWorkerId,
            'legacy_stage.postprocess_worker_id',
            120
        );
        if (($writingTaskId === null) !== ($writingTaskLease === null)) {
            throw new InvalidArgumentException(
                'Legacy writing task id and lease must be supplied together.'
            );
        }
    }

    public function version(): string
    {
        return 'legacy_write_engine_v1';
    }

    public function bindAuthority(ChapterRunAuthorityFence $authority): void
    {
        if ($this->authority !== null
            && ($this->authority->runId !== $authority->runId
                || !hash_equals($this->authority->leaseToken, $authority->leaseToken))) {
            throw new RuntimeControlException(
                'Legacy Stage adapter cannot be rebound to another authority Run.'
            );
        }
        $this->authority = $authority;
    }

    public function acquire(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->accountedStage($context, fn (): StageResult =>
            $this->acquireWithinAccounting($context, $cancellation, $deadline, $heartbeat));
    }

    private function acquireWithinAccounting(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        $this->control($cancellation, $deadline, $heartbeat, true);
        $this->assertFrozenInput($context);
        $this->transport->waiting('正在解析章节状态...');
        $resolved = ($this->acquireChapter)($context);
        $this->setResolved($context, $resolved);
        $this->novel['chapter_words'] = $this->policySnapshot->targetWords();
        $this->transport->acquired($this->novel, $this->chapter);
        $this->control($cancellation, $deadline, $heartbeat, true);

        $base = $this->chapter['_base_snapshot'] ?? null;
        if (!is_array($base)) {
            throw new PermanentStageException(
                'Legacy acquire phase omitted the frozen Chapter base snapshot.'
            );
        }
        return StageResult::continue(
            attributes: [
                'chapter_id' => (int)$this->chapter['id'],
                'chapter_number' => (int)$this->chapter['chapter_number'],
                'target_words' => $this->policySnapshot->targetWords(),
                'base_revision_hash' => is_string($base['content_revision_hash'] ?? null)
                    ? $base['content_revision_hash']
                    : null,
                'policy_checksum' => $this->policySnapshot->checksum(),
            ],
            metadata: ['phase' => 'resolveChapter']
        );
    }

    public function buildContext(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->accountedStage($context, fn (): StageResult =>
            $this->buildContextWithinAccounting($context, $cancellation, $deadline, $heartbeat));
    }

    private function buildContextWithinAccounting(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        $this->control($cancellation, $deadline, $heartbeat);
        $this->assertFrozenInput($context);
        $this->ensureResolved($context);
        $this->transport->waiting('正在加载记忆引擎...');
        $this->ensureMemory($context, true);
        $manifest = self::contextManifest($this->memoryContext);
        $reference = $this->artifactStore->storeJson(
            $context,
            'context.manifest',
            'context.manifest',
            $manifest,
            GenerationArtifactSensitivity::INTERNAL
        );
        $artifacts = ['context.manifest' => $reference];
        $contextReference = $this->artifactStore->storeJson(
            $context,
            'context.snapshot',
            'context.snapshot',
            [
                'schema' => 'legacy.context.snapshot.v1',
                'context' => $this->memoryContext,
                'context_hash' => $manifest['context_hash'],
            ],
            GenerationArtifactSensitivity::RESTRICTED
        );
        $artifacts['context.snapshot'] = $contextReference;
        $this->memoryContextFrozen = true;
        $attributes = ['context_hash' => $manifest['context_hash']];
        $inputTokens = 0;
        $outputTokens = 0;
        $cost = '0';
        if ($this->featureSnapshot->toolLoop) {
            $toolPayload = $this->artifactStore->findJson(
                $context,
                'agent.directive',
                'agent.directive'
            );
            if ($toolPayload === null) {
                $runner = $this->toolLoopRunner
                    ??= new ProductionChapterToolLoopRunner(authority: $this->authority
                        ?? throw new LeaseLostException('Chapter Tool loop has no bound Run authority fence.'),
                        heartbeat: $heartbeat,
                        cancellation: $cancellation);
                try {
                    $output = ChapterRunFrozenConfigScope::run(
                        $this->inputSnapshot,
                        fn (): ChapterToolLoopOutput => $runner->prepare(
                            $context,
                            $this->novel,
                            $this->chapter
                        )
                    );
                } catch (ToolBudgetExceededException|ToolNotRegisteredException
                    |ToolPolicyViolationException|ToolSchemaViolationException
                    |InvalidArgumentException $exception) {
                    throw new PermanentStageException(
                        'Chapter Tool-loop contract is unavailable or invalid.',
                        0,
                        $exception
                    );
                } catch (RunCanceledException|RuntimeControlException|WorkflowCheckpointException|LeaseLostException $exception) {
                    // 取消/租约丢失由 Tool-loop 心跳观察者在段间与轮间抛出，必须
                    // 原样上抛给 WorkflowRunner 落 CANCELED/lease_lost 终态，
                    // 不得被下面的 Throwable 兜底误包装为可重试基础设施错误。
                    throw $exception;
                } catch (Throwable $exception) {
                    throw new RetryableInfrastructureException(
                        'Chapter Tool-loop execution failed.',
                        0,
                        $exception
                    );
                }
                $payload = [
                    'schema' => ChapterToolLoopOutput::ARTIFACT_SCHEMA,
                    'directive' => $output->directive,
                    'input_tokens' => $output->inputTokens,
                    'output_tokens' => $output->outputTokens,
                    'cost_micros' => $output->costMicros,
                ];
                $toolReference = $this->artifactStore->storeJson(
                    $context,
                    'agent.directive',
                    'agent.directive',
                    $payload,
                    GenerationArtifactSensitivity::RESTRICTED
                );
            } else {
                $payload = $toolPayload['payload'];
                $toolReference = $toolPayload['reference'];
            }
            $this->toolDirective = self::loadToolDirectivePayload($payload);
            $inputTokens = (int)$payload['input_tokens'];
            $outputTokens = (int)$payload['output_tokens'];
            $cost = '0';
            $artifacts['agent.directive'] = $toolReference;
            $attributes['tool_directive_hash'] = hash(
                'sha256',
                HarnessPersistenceValue::canonicalJson($this->toolDirective)
            );
        }
        return StageResult::continue(
            attributes: $attributes,
            artifacts: $artifacts,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $cost,
            metadata: ['phase' => 'initMemory']
        );
    }

    public function compilePrompt(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->accountedStage($context, fn (): StageResult =>
            $this->compilePromptWithinAccounting($context, $cancellation, $deadline, $heartbeat));
    }

    private function compilePromptWithinAccounting(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        $this->control($cancellation, $deadline, $heartbeat);
        $this->assertFrozenInput($context);
        $this->ensureResolved($context);
        $this->ensureMemory($context);
        $this->ensureToolDirective($context);
        $this->transport->waiting('正在构思中...');
        try {
            $harnessEvidence = null;
            $messages = ChapterRunFrozenConfigScope::run(
                $this->inputSnapshot,
                function () use (&$harnessEvidence): array {
                    return WriteEngine::buildPrompt(
                        $this->novel,
                        $this->chapter,
                        $this->memoryContext,
                        $this->writingTaskId,
                        $this->policySnapshot,
                        $this->writingTaskLease,
                        $this->featureSnapshot,
                        $harnessEvidence,
                        $this->toolDirective
                    );
                }
            );
        } catch (Throwable $exception) {
            throw new RetryableInfrastructureException(
                'Legacy prompt compilation failed.',
                0,
                $exception
            );
        }
        if (!is_array($messages) || !array_is_list($messages)) {
            throw new PermanentStageException('Legacy prompt compiler returned no message list.');
        }
        $this->messages = $messages;
        $reference = $this->artifactStore->storeJson(
            $context,
            'prompt.messages',
            'prompt.messages',
            ['schema' => 'legacy.prompt.messages.v1', 'messages' => $messages],
            GenerationArtifactSensitivity::RESTRICTED
        );
        $artifacts = ['prompt.messages' => $reference];
        $manifestHash = null;
        if (is_array($harnessEvidence)) {
            $manifest = $harnessEvidence['package_manifest'] ?? null;
            $diff = $harnessEvidence['shadow_diff'] ?? null;
            if (!is_array($manifest) || !is_array($diff)) {
                throw new PermanentStageException(
                    'Harness Prompt evidence is missing its manifest or shadow diff.'
                );
            }
            $manifestReference = $this->artifactStore->storeJson(
                $context,
                'prompt.package.manifest',
                'prompt.manifest',
                $manifest,
                GenerationArtifactSensitivity::INTERNAL
            );
            $diffReference = $this->artifactStore->storeJson(
                $context,
                'prompt.shadow_diff',
                'prompt.diff',
                $diff,
                GenerationArtifactSensitivity::INTERNAL
            );
            $artifacts['prompt.package.manifest'] = $manifestReference;
            $artifacts['prompt.shadow_diff'] = $diffReference;
            $manifestHash = is_string($manifest['manifest_hash'] ?? null)
                ? $manifest['manifest_hash']
                : null;
        } elseif ($this->featureSnapshot->contextV2) {
            throw new PermanentStageException(
                'Frozen context_v2 Run produced no required Prompt package.'
            );
        }
        return StageResult::continue(
            attributes: [
                'prompt_hash' => hash(
                    'sha256',
                    HarnessPersistenceValue::canonicalJson($messages)
                ),
                'prompt_message_count' => count($messages),
                'prompt_manifest_hash' => $manifestHash,
                'context_v2' => $this->featureSnapshot->contextV2,
            ],
            artifacts: $artifacts,
            metadata: ['phase' => 'buildPrompt']
        );
    }

    public function generateDraft(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->accountedStage($context, fn (): StageResult =>
            $this->generateDraftWithinAccounting($context, $cancellation, $deadline, $heartbeat));
    }

    private function generateDraftWithinAccounting(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        $this->control($cancellation, $deadline, $heartbeat);
        $this->assertFrozenInput($context);
        $this->ensureResolved($context);
        $this->ensurePrompt($context);
        if ($this->featureSnapshot->branchCandidates) {
            return $this->generateBranchTournament(
                $context,
                $cancellation,
                $deadline,
                $heartbeat
            );
        }
        $draftLogicalKey = $this->featureSnapshot->parallelReview
            ? 'draft.raw'
            : 'draft.full';
        $draftArtifactType = $this->featureSnapshot->parallelReview
            ? 'draft.raw'
            : 'draft.full';
        $recoveredDraft = $this->artifactStore->findText(
            $context,
            $draftLogicalKey,
            $draftArtifactType
        );
        if ($recoveredDraft !== null) {
            $recoveredGeneration = $this->artifactStore->findJson(
                $context,
                'draft.generation',
                'model.result'
            );
            $generationPayload = $recoveredGeneration['payload'] ?? [
                'schema' => 'legacy.model.result.v1',
                'usage' => null,
                'duration_ms' => null,
                'model_label' => null,
            ];
            $this->usage = is_array($generationPayload['usage'] ?? null)
                ? $generationPayload['usage']
                : null;
            $this->durationMs = is_int($generationPayload['duration_ms'] ?? null)
                ? $generationPayload['duration_ms']
                : null;
            $label = $generationPayload['model_label'] ?? null;
            $this->usedModelLabel = is_string($label) && $label !== '' ? $label : null;
            $this->generationAttemptObservation = $this->recoveredAttemptObservation(
                $generationPayload
            );
            $generationReference = $recoveredGeneration['reference']
                ?? $this->artifactStore->storeJson(
                    $context,
                    'draft.generation',
                    'model.result',
                    $generationPayload,
                    GenerationArtifactSensitivity::INTERNAL
                );
            $this->transport->waiting('已恢复完整草稿，继续落盘...');
            if ($this->featureSnapshot->parallelReview) {
                return $this->reviewDraft(
                    $context,
                    $recoveredDraft['payload'],
                    $recoveredDraft['reference'],
                    $generationReference,
                    true
                );
            }
            $this->draft = $recoveredDraft['payload'];
            return $this->draftResult(
                $this->draft,
                $recoveredDraft['reference'],
                $generationReference,
                true
            );
        }
        try {
            $result = ChapterRunFrozenConfigScope::run(
                $this->inputSnapshot,
                function () use ($context, $cancellation, $deadline, $heartbeat): array {
                    return WriteEngine::streamWrite(
                        $this->messages,
                        $this->policySnapshot->targetWords(),
                        $context->request->novelId,
                        function (string $chunk) use ($cancellation, $deadline, $heartbeat): void {
                            $this->transport->chunk($chunk);
                            $this->control($cancellation, $deadline, $heartbeat);
                        },
                        function (array $payload): void {
                            $this->transport->message($payload);
                        },
                        function () use ($cancellation, $deadline, $heartbeat): void {
                            $this->transport->heartbeat();
                            $this->control($cancellation, $deadline, $heartbeat);
                        },
                        function (string $chunk): void {
                            $this->transport->thinking($chunk);
                        },
                        !empty($this->novel['model_id'])
                            ? (int)$this->novel['model_id']
                            : null,
                        (int)$this->chapter['chapter_number'],
                        (int)($this->novel['target_chapters'] ?? 0),
                        $this->waitingCallback,
                        function () use ($cancellation, $deadline, $heartbeat): void {
                            $this->control($cancellation, $deadline, $heartbeat);
                        },
                        function (array $rows): void {
                            $this->inputSnapshot->assertModelRowsMatch($rows);
                        }
                    );
                }
            );
        } catch (WritePolicyEmergencyStopException $exception) {
            throw new RunCanceledException(
                'Harness emergency stop interrupted model generation.',
                0,
                $exception
            );
        } catch (RunCanceledException|DeadlineExceededException|LeaseLostException $exception) {
            throw $exception;
        } catch (RuntimeControlException|WorkflowCheckpointException $exception) {
            throw $exception;
        } catch (WriteEngineCanceledException $exception) {
            throw new RunCanceledException(previous: $exception);
        } catch (WriteEngineGenerationExhaustedException $exception) {
            // 审计修复 S-2（2026-09-27）：streamWrite 内部已做"每模型 3 次 ×
            // 全部 fallback 模型 + 429 退避 + bestAttempt 兜底"的有界重试，
            // 耗尽后抛专类型。此处不得再包 RetryableModelException 令 workflow
            // 整阶段重试（最坏放大为 模型数×3×3 次 API 调用、每次从零重写整章），
            // 落 PermanentStageException 终态——与 legacy 直连 worker 对同一
            // 失败立即终止的语义对齐。
            throw new PermanentStageException(
                'Legacy model generation exhausted every bounded retry.',
                0,
                $exception
            );
        } catch (Throwable $exception) {
            if ($this->legacyCancellationRequested()) {
                throw new RunCanceledException(previous: $exception);
            }
            throw new RetryableModelException(
                'Legacy model generation failed.',
                0,
                $exception
            );
        }
        $content = $result['content'] ?? null;
        if (!is_string($content)) {
            throw new PermanentStageException('Legacy model generation returned no draft.');
        }
        // Match saveChapter() normalization exactly so the Generate checkpoint
        // hash is the same idempotency identity recorded by the commit ledger.
        $content = stripMetaLeaks(stripSegmentMarkers($content));
        $this->draft = $content;
        $this->usedModel = ($result['model'] ?? null) instanceof AIClient
            ? $result['model']
            : null;
        $this->usedModelLabel = $this->usedModel?->modelLabel;
        $this->usage = is_array($result['usage'] ?? null) ? $result['usage'] : null;
        $this->durationMs = is_int($result['duration_ms'] ?? null)
            ? $result['duration_ms']
            : null;
        $this->generationAttemptObservation = $this->attemptObservation(
            $this->usedModel?->providerName(),
            $this->usedModel?->modelName,
            $this->usedModel?->modelId,
            $this->usage,
            $this->durationMs
        );
        $draftRef = $this->artifactStore->storeText(
            $context,
            $draftLogicalKey,
            $draftArtifactType,
            $content,
            GenerationArtifactSensitivity::RESTRICTED
        );
        $generationRef = $this->artifactStore->storeJson(
            $context,
            'draft.generation',
            'model.result',
            [
                'schema' => 'legacy.model.result.v1',
                'usage' => $this->usage,
                'duration_ms' => $this->durationMs,
                'model_label' => $this->usedModelLabel,
                'generation_attempt_observation' =>
                    $this->generationAttemptObservation,
            ],
            GenerationArtifactSensitivity::INTERNAL
        );
        if ($this->featureSnapshot->parallelReview) {
            return $this->reviewDraft(
                $context,
                $content,
                $draftRef,
                $generationRef,
                false
            );
        }
        return $this->draftResult($content, $draftRef, $generationRef, false);
    }

    public function commit(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->accountedStage($context, fn (): StageResult =>
            $this->commitWithinAccounting($context, $cancellation, $deadline, $heartbeat));
    }

    private function commitWithinAccounting(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        $this->control($cancellation, $deadline, $heartbeat, true);
        $this->assertFrozenInput($context);
        $this->ensureResolved($context);
        $this->ensureDraft($context);
        $reviewDecision = null;
        if ($this->featureSnapshot->parallelReview) {
            $decisionReference = $context->artifacts['review.decision'] ?? null;
            if (!is_array($decisionReference)) {
                throw new ValidationBlockedException(
                    'Canon commit requires the frozen post-generation Review decision.'
                );
            }
            $decision = $this->artifactStore->loadJson(
                $context,
                $decisionReference,
                'review.decision'
            );
            $reviewDisposition = $decision['disposition'] ?? null;
            $reviewCommitAllowed = $decision['commit_allowed'] ?? null;
            $reviewDecisionValid = is_string($reviewDisposition)
                && in_array($reviewDisposition, [
                    'accepted', 'waiting_approval', 'quarantined',
                ], true)
                && is_bool($reviewCommitAllowed)
                && $reviewCommitAllowed === ($reviewDisposition === 'accepted');
            if (($decision['schema'] ?? null) !== 'harness.review.decision.v1'
                || !$reviewDecisionValid
                || !is_string($decision['selected_draft_hash'] ?? null)
                || !hash_equals(
                    (string)$decision['selected_draft_hash'],
                    hash('sha256', $this->draft)
                )) {
                throw new ValidationBlockedException(
                    'Canon commit refused a missing or stale Review decision.'
                );
            }
            $reviewDecision = $decision;
        }
        if ($this->featureSnapshot->branchCandidates) {
            $this->assertBranchTournamentCommitEvidence($context);
        }
        $authority = $this->authority
            ?? throw new RuntimeControlException('Legacy commit Stage has no authority fence.');

        $this->chapter['_run_id'] = $authority->runId;
        $this->chapter['_run_uid'] = $authority->runUid;
        $this->chapter['_run_lease_owner'] = $authority->leaseOwner;
        $this->chapter['_run_lease_token'] = $authority->leaseToken;
        $this->chapter['_run_fence_generation'] = $authority->fenceGeneration;
        if ($this->writingTaskId !== null && $this->writingTaskLease !== null) {
            $this->chapter['_task_id'] = $this->writingTaskId;
            $this->chapter['_task_lease_owner'] = $this->writingTaskLease['lease_owner'] ?? null;
            $this->chapter['_task_lease_token'] = $this->writingTaskLease['lease_token'] ?? null;
            $this->chapter['_task_fence_generation'] =
                $this->writingTaskLease['fence_generation'] ?? null;
        }
        $this->canonCommitFault(
            LegacyCanonCommitFaultPoint::BEFORE_CANON_COMMIT,
            $context
        );
        try {
            $saveResult = ChapterRunFrozenConfigScope::run(
                $this->inputSnapshot,
                fn (): array => WriteEngine::saveChapter(
                    (int)$this->chapter['id'],
                    $context->request->novelId,
                    $this->draft,
                    $this->policySnapshot->targetWords(),
                    $this->usedModel,
                    $this->chapter,
                    $this->usage,
                    $this->durationMs,
                    $this->policySnapshot,
                    $reviewDecision
                )
            );
        } catch (WriteEngineCanceledException $exception) {
            throw new RunCanceledException(previous: $exception);
        } catch (WriteEngineValidationException $exception) {
            throw new ValidationBlockedException(previous: $exception);
        } catch (Throwable $exception) {
            if ($this->legacyCancellationRequested()) {
                throw new RunCanceledException(previous: $exception);
            }
            throw new RetryableInfrastructureException(
                'Legacy Canon commit failed.',
                0,
                $exception
            );
        }
        $this->canonCommitFault(
            LegacyCanonCommitFaultPoint::AFTER_CANON_COMMIT_BEFORE_ARTIFACT,
            $context
        );
        $this->saveResult = $saveResult;
        $this->chapter = $saveResult['chapter'];
        $this->draft = (string)$saveResult['accepted_content'];
        $saveResult['_model_label'] = $this->usedModelLabel;
        $this->transport->saved($saveResult);
        $reference = $this->artifactStore->storeCommittedCanonReceipt(
            $context,
            [
                'schema' => 'legacy.canonical.receipt.v1',
                'chapter_id' => (int)$this->chapter['id'],
                'revision_hash' => (string)$saveResult['revision_hash'],
                'candidate_uid' => (string)$saveResult['candidate_uid'],
                'postprocess_job_id' => (int)$saveResult['postprocess_job_id'],
                'words' => (int)$saveResult['words'],
            ]
        );
        return StageResult::continue(
            attributes: [
                'committed_revision_hash' => (string)$saveResult['revision_hash'],
                'candidate_uid' => (string)$saveResult['candidate_uid'],
                'postprocess_job_id' => (int)$saveResult['postprocess_job_id'],
                'words' => (int)$saveResult['words'],
                'all_done' => (bool)$saveResult['all_done'],
            ],
            artifacts: ['canonical.receipt' => $reference],
            metadata: ['phase' => 'saveChapter']
        );
    }

    public function postprocess(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        return $this->accountedStage($context, fn (): StageResult =>
            $this->postprocessWithinAccounting($context, $cancellation, $deadline, $heartbeat));
    }

    private function postprocessWithinAccounting(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        // The atomic commit receipt remains authoritative even when a cancel,
        // deadline, or emergency epoch arrives after Canon. Authenticate it
        // and idempotently restore the immutable Canon Artifact first; only
        // then honor control signals before any Review/projection side effect.
        $this->assertFrozenInput($context);
        $canonRecovery = $this->committedCanonRecovery->recover($context);
        $context = $canonRecovery->context;
        $this->control($cancellation, $deadline, $heartbeat, true);
        $this->ensureResolved($context);
        $this->ensureMemory($context, true);
        $content = is_string($this->chapter['content'] ?? null)
            ? $this->chapter['content']
            : $this->ensureDraft($context);
        $reviewEffects = $this->applyCommittedReviewEffects($context, $content);
        try {
            $result = ChapterRunFrozenConfigScope::run(
                $this->inputSnapshot,
                fn (): array => PostprocessRunner::enqueueAndRun(
                    $context->request->novelId,
                    $this->chapter,
                    $content,
                    $this->memoryEngine,
                    $this->postprocessWorkerId,
                    $this->policySnapshot,
                    $this->writingTaskLease,
                    static fn (): bool => $heartbeat->beatIfDue()
                )
            );
        } catch (Throwable $exception) {
            throw new RetryableInfrastructureException(
                'Legacy post-processing failed.',
                0,
                $exception
            );
        }
        if (($result['state'] ?? null) === 'lease_lost') {
            throw new LeaseLostException('Legacy post-processing lease was lost.');
        }
        if (!in_array($result['state'] ?? null, ['done', 'stale', 'skipped'], true)) {
            throw new RetryableInfrastructureException('Chapter post-processing has not completed its durable job.');
        }
        $resultJobId = (int)($result['job_id'] ?? 0);
        if ($resultJobId !== $canonRecovery->postprocessJobId) {
            throw new WorkflowCheckpointException(
                'Post-processing resumed a different job than the Canon commit receipt.'
            );
        }
        $this->transport->postprocessed($result);
        $reference = $this->artifactStore->storePostprocessReceipt($context, $result);
        return StageResult::complete(
            attributes: array_replace($canonRecovery->attributePatch, [
                'postprocess_state' => (string)($result['state'] ?? 'pending'),
                'postprocess_job_id' => $resultJobId,
            ]),
            artifacts: array_replace(
                $canonRecovery->artifactPatch,
                $reviewEffects['artifacts'],
                [
                'postprocess.receipt' => $reference,
                ]
            ),
            metadata: [
                'phase' => 'PostprocessRunner::enqueueAndRun',
                'review_effect_uid' => $reviewEffects['effect_uid'],
                'review_effect_receipt_recovered' => $reviewEffects['recovered'],
            ]
        );
    }

    /**
     * Apply enabled P6 Review effects only after the Canon Stage checkpoint is
     * durable and before projection work. Frozen-off runs return before any
     * Review Artifact lookup or write, preserving the legacy path exactly.
     *
     * @return array{
     *   artifacts:array<string,array<string,mixed>>,
     *   effect_uid:?string,recovered:bool
     * }
     */
    private function accountedStage(RunContext $context, callable $operation): StageResult
    {
        $accounting = new ChapterRunAIInvocationAccounting($this->inputSnapshot,
            $context->request->runId, $context->request->runUid, $context->budget,
            $this->authority, connection: $this->modelAccountingConnection);
        $result = AIInvocationAccountingScope::run($accounting,
            fn (): StageResult => ChapterRunFrozenConfigScope::run($this->inputSnapshot, $operation));
        return $accounting->accountStageResult($result, $context->budget);
    }

    private function applyCommittedReviewEffects(
        RunContext $context,
        string $committedContent
    ): array {
        if (!$this->featureSnapshot->parallelReview) {
            return ['artifacts' => [], 'effect_uid' => null, 'recovered' => false];
        }

        $canonicalReference = $context->artifacts['canonical.receipt'] ?? null;
        $reportsReference = $context->artifacts['review.reports'] ?? null;
        $decisionReference = $context->artifacts['review.decision'] ?? null;
        if (!is_array($canonicalReference)
            || !is_array($reportsReference)
            || !is_array($decisionReference)) {
            throw new WorkflowCheckpointException(
                'Post-Canon Review effects require Canon, decision, and report Artifacts.'
            );
        }

        $canonical = $this->artifactStore->loadJson(
            $context,
            $canonicalReference,
            'canonical.receipt'
        );
        $reports = $this->artifactStore->loadJson(
            $context,
            $reportsReference,
            'review.reports'
        );
        $decision = $this->artifactStore->loadJson(
            $context,
            $decisionReference,
            'review.decision'
        );
        $committedRevisionHash = hash('sha256', $committedContent);
        $checkpointRevisionHash = $context->attributes['committed_revision_hash'] ?? null;
        if (($canonical['schema'] ?? null) !== 'legacy.canonical.receipt.v1'
            || ($canonical['chapter_id'] ?? null) !== (int)$this->chapter['id']
            || ($canonical['revision_hash'] ?? null) !== $committedRevisionHash
            || !is_string($checkpointRevisionHash)
            || !hash_equals($checkpointRevisionHash, $committedRevisionHash)) {
            throw new WorkflowCheckpointException(
                'Post-Canon Review effects refused a stale committed revision.'
            );
        }
        $reportsHash = $reportsReference['content_hash'] ?? null;
        $decisionHash = $decisionReference['content_hash'] ?? null;
        if (!is_string($reportsHash) || !is_string($decisionHash)) {
            throw new WorkflowCheckpointException(
                'Post-Canon Review evidence references have no immutable hashes.'
            );
        }

        try {
            $effects = new CommittedReviewEffects(
                novelId: $context->request->novelId,
                chapterId: (int)$this->chapter['id'],
                committedRevisionHash: $committedRevisionHash,
                reportsArtifact: $reports,
                reportsArtifactHash: $reportsHash,
                decisionArtifact: $decision,
                decisionArtifactHash: $decisionHash,
                metadata: [
                    'run_uid' => $context->request->runUid,
                    'candidate_uid' => $canonical['candidate_uid'] ?? null,
                    'postprocess_job_id' => $canonical['postprocess_job_id'] ?? null,
                    'feature_snapshot_checksum' => $this->featureSnapshot->checksum,
                ]
            );
        } catch (InvalidArgumentException $exception) {
            throw new WorkflowCheckpointException(
                'Post-Canon Review effects rejected inconsistent evidence.',
                0,
                $exception
            );
        }

        $ledger = new LegacyChapterReviewEffectLedger($this->artifactStore, $context);
        $applier = $this->reviewEffectApplier
            ?? new ArtifactReviewEffectApplier($ledger);
        try {
            $dispatch = (new DurableReviewEffectDispatcher($applier))
                ->dispatchAfterCommit($effects, $ledger);
        } catch (HarnessIdempotencyConflictException|InvalidArgumentException $exception) {
            throw new WorkflowCheckpointException(
                'Post-Canon Review effect ledger conflicts with committed evidence.',
                0,
                $exception
            );
        } catch (WorkflowCheckpointException|PermanentStageException
            |RetryableInfrastructureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RetryableInfrastructureException(
                'Post-Canon Review effect application failed.',
                0,
                $exception
            );
        }
        if (!$dispatch instanceof ReviewEffectDispatchResult) {
            throw new WorkflowCheckpointException(
                'Enabled Review effect dispatch returned no durable receipt.'
            );
        }

        $artifacts = [
            'review.effects.command' => $dispatch->command->reference,
            'review.effects.receipt' => $dispatch->receipt->reference,
        ];
        $applied = $ledger->find(
            'review.effects.applied',
            'review.effects.applied'
        );
        if ($applied !== null) {
            $artifacts['review.effects.applied'] = $applied->reference;
        }
        return [
            'artifacts' => $artifacts,
            'effect_uid' => $dispatch->effectUid,
            'recovered' => $dispatch->recoveredReceipt,
        ];
    }

    private function control(
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat,
        bool $forceHeartbeat = false
    ): void {
        $cancellation->throwIfCancellationRequested();
        if ($this->legacyCancellationRequested()) {
            throw new RunCanceledException();
        }
        $deadline->throwIfExceeded();
        try {
            ($this->emergencyEpochProbe)($this->policySnapshot);
        } catch (WritePolicyEmergencyStopException|RuntimeControlException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WritePolicyEmergencyStopException(
                'Live emergency stop epoch could not be checked; write is stopped fail-closed.',
                0,
                $exception
            );
        }
        if ($forceHeartbeat) {
            $heartbeat->force();
        } else {
            $heartbeat->beatIfDue();
        }
    }

    private function legacyCancellationRequested(): bool
    {
        $requested = ($this->legacyCancellationProbe)();
        if (!is_bool($requested)) {
            throw new RuntimeControlException(
                'Legacy cancellation probe must return a boolean value.'
            );
        }
        return $requested;
    }

    private function canonCommitFault(
        LegacyCanonCommitFaultPoint $point,
        RunContext $context
    ): void {
        if ($this->canonCommitFault !== null) {
            ($this->canonCommitFault)($point, $context);
        }
    }

    private function ensureResolved(RunContext $context): void
    {
        if ($this->novel !== null && $this->chapter !== null) {
            return;
        }
        $this->setResolved($context, ($this->restoreChapter)($context));
        $this->novel['chapter_words'] = $this->policySnapshot->targetWords();
    }

    /** @param array{n:array<string,mixed>,ch:array<string,mixed>} $resolved */
    private function setResolved(RunContext $context, array $resolved): void
    {
        $novel = $resolved['n'] ?? null;
        $chapter = $resolved['ch'] ?? null;
        if (!is_array($novel) || !is_array($chapter)
            || (int)($novel['id'] ?? 0) !== $context->request->novelId
            || (int)($chapter['id'] ?? 0) !== $context->request->chapterId
            || (int)($chapter['novel_id'] ?? 0) !== $context->request->novelId) {
            throw new StaleRevisionException(
                'Legacy Chapter claim does not match the immutable Run request.'
            );
        }
        $this->inputSnapshot->assertScope(
            $context->request->novelId,
            $context->request->chapterId
                ?? throw new StaleRevisionException('Chapter Run has no Chapter scope.')
        );
        $this->novel = $this->inputSnapshot->applyNovel($novel);
        $this->chapter = $this->inputSnapshot->applyChapter($chapter);
    }

    private function ensureMemory(RunContext $context, bool $requireEngine = false): void
    {
        if (!$this->memoryContextFrozen) {
            $reference = $context->artifacts['context.snapshot'] ?? null;
            if (is_array($reference)) {
                $payload = $this->artifactStore->loadJson(
                    $context,
                    $reference,
                    'context.snapshot'
                );
                $memoryContext = $payload['context'] ?? null;
                if (($payload['schema'] ?? null) !== 'legacy.context.snapshot.v1'
                    || ($memoryContext !== null && !is_array($memoryContext))) {
                    throw new WorkflowCheckpointException(
                        'Context snapshot Artifact is malformed.'
                    );
                }
                $manifest = self::contextManifest(
                    is_array($memoryContext) ? $memoryContext : null
                );
                if (!is_string($payload['context_hash'] ?? null)
                    || !hash_equals($manifest['context_hash'], $payload['context_hash'])) {
                    throw new WorkflowCheckpointException(
                        'Context snapshot Artifact hash is stale.'
                    );
                }
                $this->memoryContext = is_array($memoryContext) ? $memoryContext : null;
                $this->memoryContextFrozen = true;
            }
        }
        if ($this->memoryContextFrozen && (!$requireEngine || $this->memoryEngine !== null)) {
            return;
        }
        if ($this->memoryInitializer !== null) {
            try {
                $result = ($this->memoryInitializer)(
                    $this->novel,
                    $this->chapter,
                    $this->inputSnapshot
                );
                $engine = $result['engine'] ?? null;
                $memoryContext = $result['memoryCtx'] ?? null;
                if (!$engine instanceof MemoryEngine
                    || ($memoryContext !== null && !is_array($memoryContext))) {
                    throw new RuntimeException(
                        'Injected Legacy memory initializer returned an invalid result.'
                    );
                }
                $this->memoryEngine = $engine;
                if (!$this->memoryContextFrozen) {
                    $this->memoryContext = $memoryContext;
                }
                return;
            } catch (RuntimeControlException|WorkflowCheckpointException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new RetryableInfrastructureException(
                    'Legacy memory initialization failed.',
                    0,
                    $exception
                );
            }
        }
        $preAiClient = null;
        try {
            $rows = getModelFallbackList(
                !empty($this->novel['model_id']) ? (int)$this->novel['model_id'] : null
            );
            $this->inputSnapshot->assertModelRowsMatch($rows);
            $preAiClient = new AIClient($rows[0]);
        } catch (RuntimeControlException|WorkflowCheckpointException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RetryableInfrastructureException(
                'Frozen Chapter model configuration could not initialize Memory.',
                0,
                $exception
            );
        }
        try {
            $result = ChapterRunFrozenConfigScope::run(
                $this->inputSnapshot,
                fn (): array => WriteEngine::initMemory(
                    (int)$this->novel['id'],
                    $this->chapter,
                    $preAiClient
                )
            );
            $this->memoryEngine = $result['engine'];
            if (!$this->memoryContextFrozen) {
                $this->memoryContext = is_array($result['memoryCtx'] ?? null)
                    ? $result['memoryCtx']
                    : null;
            }
        } catch (RuntimeControlException|WorkflowCheckpointException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RetryableInfrastructureException(
                'Legacy memory initialization failed.',
                0,
                $exception
            );
        }
    }

    private function assertFrozenInput(RunContext $context): void
    {
        $restored = ChapterRunInputSnapshot::fromRunRequest($context->request);
        if (!hash_equals($this->inputSnapshot->checksum, $restored->checksum)) {
            throw new WorkflowCheckpointException(
                'Stage adapter input snapshot differs from the durable Run request.'
            );
        }
        $expectedInputHash = $this->inputSnapshot->bindInputHash(
            HarnessPersistenceValue::hash(
                $context->request->goal['request_input_hash'] ?? null,
                'chapter_run.goal.request_input_hash'
            )
        );
        if (!hash_equals($context->request->inputHash, $expectedInputHash)) {
            throw new WorkflowCheckpointException(
                'Chapter Run input hash is not bound to its frozen input snapshot.'
            );
        }
        try {
            ($this->inputSnapshotProbe)($this->inputSnapshot);
        } catch (RuntimeControlException|WorkflowCheckpointException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RetryableInfrastructureException(
                'Chapter Run input configuration could not be revalidated.',
                0,
                $exception
            );
        }
    }

    private function ensureToolDirective(RunContext $context): void
    {
        if (!$this->featureSnapshot->toolLoop) {
            if ($this->toolDirective !== null) {
                throw new WorkflowCheckpointException(
                    'Disabled Tool-loop Run retained an unexpected directive.'
                );
            }
            return;
        }
        if ($this->toolDirective !== null) {
            return;
        }
        $reference = $context->artifacts['agent.directive'] ?? null;
        if (!is_array($reference)) {
            throw new WorkflowCheckpointException(
                'Prompt Stage cannot restore the required Tool-loop directive.'
            );
        }
        $payload = $this->artifactStore->loadJson(
            $context,
            $reference,
            'agent.directive'
        );
        $this->toolDirective = self::loadToolDirectivePayload($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function loadToolDirectivePayload(array $payload): array
    {
        HarnessPersistenceValue::requireFields(
            $payload,
            ['schema', 'directive', 'input_tokens', 'output_tokens', 'cost_micros'],
            'chapter_tool_loop'
        );
        $expectedKeys = [
            'schema', 'directive', 'input_tokens', 'output_tokens', 'cost_micros',
        ];
        $actualKeys = array_keys($payload);
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys
            || $payload['schema'] !== ChapterToolLoopOutput::ARTIFACT_SCHEMA
            || !is_array($payload['directive'])) {
            throw new WorkflowCheckpointException(
                'Tool-loop directive Artifact payload is malformed.'
            );
        }
        HarnessPersistenceValue::nonNegativeInt(
            $payload['input_tokens'],
            'chapter_tool_loop.input_tokens'
        );
        HarnessPersistenceValue::nonNegativeInt(
            $payload['output_tokens'],
            'chapter_tool_loop.output_tokens'
        );
        HarnessPersistenceValue::nonNegativeInt(
            $payload['cost_micros'],
            'chapter_tool_loop.cost_micros'
        );
        try {
            ToolLoopPromptDirective::assertValid($payload['directive']);
        } catch (InvalidArgumentException $exception) {
            throw new WorkflowCheckpointException(
                'Tool-loop directive Artifact failed validation.',
                0,
                $exception
            );
        }
        return $payload['directive'];
    }


    private function ensurePrompt(RunContext $context): void
    {
        if ($this->messages !== null) {
            return;
        }
        $reference = $context->artifacts['prompt.messages'] ?? null;
        if (!is_array($reference)) {
            throw new WorkflowCheckpointException(
                'Generate Stage cannot restore the Prompt Artifact.'
            );
        }
        $payload = $this->artifactStore->loadJson(
            $context,
            $reference,
            'prompt.messages'
        );
        $messages = $payload['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages)) {
            throw new WorkflowCheckpointException('Prompt Artifact has no message list.');
        }
        $this->messages = $messages;
    }

    private function ensureDraft(RunContext $context): string
    {
        if ($this->draft !== null) {
            return $this->draft;
        }
        $reference = $context->artifacts['draft.full'] ?? null;
        if (!is_array($reference)) {
            throw new WorkflowCheckpointException(
                'Commit Stage cannot restore the Draft Artifact.'
            );
        }
        $this->draft = $this->artifactStore->loadText(
            $context,
            $reference,
            'draft.full'
        );
        $generation = $context->artifacts['draft.generation'] ?? null;
        if (is_array($generation)) {
            $payload = $this->artifactStore->loadJson(
                $context,
                $generation,
                'draft.generation'
            );
            $this->usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : null;
            $this->durationMs = is_int($payload['duration_ms'] ?? null)
                ? $payload['duration_ms']
                : null;
            $label = $payload['model_label'] ?? null;
            $this->usedModelLabel = is_string($label) && $label !== '' ? $label : null;
            $this->generationAttemptObservation = $this->recoveredAttemptObservation(
                $payload
            );
        }
        return $this->draft;
    }

    /**
     * Frozen branch_candidates production path.  Model streams remain private
     * until the pure tournament has selected one winner; loser chunks,
     * messages, and reasoning never reach the entry transport.
     */
    private function generateBranchTournament(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult {
        if (!$this->featureSnapshot->branchCandidates) {
            throw new LogicException('Branch tournament was invoked while frozen off.');
        }
        $scope = $this->branchTournamentScope($context);
        /** @var array<int,AIClient> $modelClients */
        $modelClients = [];
        $runner = new ProductionBranchCandidateTournamentRunner(
            $this->artifactStore,
            function (int $ordinal) use (
                $scope,
                $cancellation,
                $deadline,
                $heartbeat,
                &$modelClients
            ): ProductionBranchCandidateGeneration {
                $generated = $this->streamBranchCandidate(
                    $ordinal,
                    $scope,
                    $cancellation,
                    $deadline,
                    $heartbeat
                );
                $modelClients[$ordinal] = $generated['model'];
                return $generated['generation'];
            }
        );
        $this->transport->waiting(
            'Generating two isolated candidates from the frozen Prompt...'
        );
        try {
            $selection = $runner->run($context, $scope);
        } catch (RunCanceledException|DeadlineExceededException|LeaseLostException
            |RunBudgetExceededException|WorkflowCheckpointException
            |PermanentStageException|RetryableInfrastructureException
            |RuntimeControlException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->legacyCancellationRequested()) {
                throw new RunCanceledException(previous: $exception);
            }
            if ($exception instanceof WriteEngineGenerationExhaustedException) {
                // 审计修复 S-2（2026-09-27）：同主路径——分支候选的 streamWrite
                // 同样内部有界重试，耗尽后不再令 tournament 阶级重试放大。
                throw new PermanentStageException(
                    'Branch candidate tournament exhausted every bounded retry.',
                    0,
                    $exception
                );
            }
            throw new RetryableModelException(
                'Branch candidate tournament failed before a recoverable winner.',
                0,
                $exception
            );
        }

        $this->draft = $selection->content;
        $this->usedModel = $modelClients[$selection->winnerOrdinal] ?? null;
        $this->usedModelLabel = $selection->winnerModelLabel;
        $this->usage = $selection->usage;
        $this->durationMs = $selection->durationMs;
        $this->generationAttemptObservation = $this->attemptObservation(
            $selection->winnerProvider,
            $selection->winnerModel,
            $selection->winnerModelId,
            $selection->usage,
            $selection->durationMs
        );

        $draftLogicalKey = $this->featureSnapshot->parallelReview
            ? 'draft.raw'
            : 'draft.full';
        $draftArtifactType = $this->featureSnapshot->parallelReview
            ? 'draft.raw'
            : 'draft.full';
        $draftReference = $this->artifactStore->storeText(
            $context,
            $draftLogicalKey,
            $draftArtifactType,
            $selection->content,
            GenerationArtifactSensitivity::RESTRICTED
        );
        $resultReference = $selection->artifacts['branch.tournament.result'];
        $generationReference = $this->artifactStore->storeJson(
            $context,
            'draft.generation',
            'model.result',
            [
                'schema' => 'harness.branch_tournament.model_result.v1',
                'candidate_count' => 2,
                'winner_uid' => $selection->winnerUid,
                'winner_ordinal' => $selection->winnerOrdinal,
                'provider' => $selection->winnerProvider,
                'model' => $selection->winnerModel,
                'model_id' => $selection->winnerModelId,
                'model_label' => $selection->winnerModelLabel,
                'usage' => $selection->usage,
                'duration_ms' => $selection->durationMs,
                'tournament_result_artifact_hash' =>
                    $resultReference['content_hash'],
                'prompt_hash' => $scope->promptHash,
                'outline_hash' => $scope->outlineHash,
                'base_coordinate_hash' => $scope->baseCoordinateHash,
            ],
            GenerationArtifactSensitivity::INTERNAL
        );

        // Only the selected draft is ever delivered.  A recovered result is
        // already durably visible through Run artifacts/checkpoints, matching
        // the legacy recovery path which does not replay streamed chunks.
        if (!$selection->recoveredResult) {
            foreach (mb_str_split($selection->content, 512, 'UTF-8') as $chunk) {
                $this->transport->chunk($chunk);
                $this->control($cancellation, $deadline, $heartbeat);
            }
        }

        $attributes = [
            'branch_tournament_scope_hash' => $scope->scopeHash,
            'branch_tournament_branch_uid' => $scope->branchUid,
            'branch_tournament_winner_uid' => $selection->winnerUid,
            'branch_tournament_winner_ordinal' => $selection->winnerOrdinal,
            'branch_tournament_candidate_count' => 2,
        ];
        $recovered = $selection->recoveredCandidateCount === 2
            && $selection->recoveredResult;
        if ($this->featureSnapshot->parallelReview) {
            return $this->reviewDraft(
                $context,
                $selection->content,
                $draftReference,
                $generationReference,
                $recovered,
                $selection->artifacts,
                $attributes
            );
        }
        return $this->draftResult(
            $selection->content,
            $draftReference,
            $generationReference,
            $recovered,
            $selection->artifacts,
            $attributes,
            [
                'phase' => 'branchCandidateTournament',
                'recovered_candidate_count' =>
                    $selection->recoveredCandidateCount,
                'tournament_result_recovered' => $selection->recoveredResult,
            ]
        );
    }

    /**
     * @return array{generation:ProductionBranchCandidateGeneration,model:AIClient}
     */
    private function streamBranchCandidate(
        int $ordinal,
        ProductionBranchTournamentScope $scope,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): array {
        $this->control($cancellation, $deadline, $heartbeat);
        try {
            $result = $this->branchCandidateGenerator === null
                ? ChapterRunFrozenConfigScope::run(
                    $this->inputSnapshot,
                    function () use ($scope, $cancellation, $deadline, $heartbeat): array {
                        return WriteEngine::streamWrite(
                        $this->messages,
                        $this->policySnapshot->targetWords(),
                        $scope->novelId,
                        function (string $_chunk) use (
                            $cancellation,
                            $deadline,
                            $heartbeat
                        ): void {
                            $this->control($cancellation, $deadline, $heartbeat);
                        },
                        static function (array $_payload): void {
                            // Candidate-local status and reset events are not public SSE.
                        },
                        function () use ($cancellation, $deadline, $heartbeat): void {
                            $this->transport->heartbeat();
                            $this->control($cancellation, $deadline, $heartbeat);
                        },
                        function (string $_thinking) use (
                            $cancellation,
                            $deadline,
                            $heartbeat
                        ): void {
                            $this->control($cancellation, $deadline, $heartbeat);
                        },
                        !empty($this->novel['model_id'])
                            ? (int)$this->novel['model_id']
                            : null,
                        (int)$this->chapter['chapter_number'],
                        (int)($this->novel['target_chapters'] ?? 0),
                        static function (mixed ...$_arguments): void {
                            // Prevent fallback to the global SSE waiting callback.
                        },
                        function () use ($cancellation, $deadline, $heartbeat): void {
                            $this->control($cancellation, $deadline, $heartbeat);
                        },
                        function (array $rows): void {
                            $this->inputSnapshot->assertModelRowsMatch($rows);
                        }
                        );
                    }
                )
                : ($this->branchCandidateGenerator)($ordinal, $scope);
        } catch (WritePolicyEmergencyStopException $exception) {
            throw new RunCanceledException(
                'Harness emergency stop interrupted Branch candidate generation.',
                0,
                $exception
            );
        } catch (RunCanceledException|DeadlineExceededException|LeaseLostException $exception) {
            throw $exception;
        } catch (RuntimeControlException|WorkflowCheckpointException $exception) {
            throw $exception;
        } catch (WriteEngineCanceledException $exception) {
            throw new RunCanceledException(previous: $exception);
        } catch (WriteEngineGenerationExhaustedException $exception) {
            // 审计修复 S-2（2026-09-27）：同主路径——候选级 streamWrite 内部
            // 有界重试耗尽后落终态，不再被包装为可重试。
            throw new PermanentStageException(
                "Branch candidate {$ordinal} exhausted every bounded retry.",
                0,
                $exception
            );
        } catch (Throwable $exception) {
            if ($this->legacyCancellationRequested()) {
                throw new RunCanceledException(previous: $exception);
            }
            throw new RetryableModelException(
                "Branch candidate {$ordinal} model generation failed.",
                0,
                $exception
            );
        }
        $content = $result['content'] ?? null;
        $model = $result['model'] ?? null;
        if (!is_string($content) || trim($content) === ''
            || !$model instanceof AIClient) {
            throw new PermanentStageException(
                "Branch candidate {$ordinal} omitted content or model provenance."
            );
        }
        $content = stripMetaLeaks(stripSegmentMarkers($content));
        if (trim($content) === '') {
            throw new PermanentStageException(
                "Branch candidate {$ordinal} became empty after normalization."
            );
        }
        return [
            'generation' => new ProductionBranchCandidateGeneration(
                $content,
                $model->providerName(),
                $model->modelName,
                $model->modelId,
                $model->modelLabel,
                is_array($result['usage'] ?? null) ? $result['usage'] : null,
                is_int($result['duration_ms'] ?? null)
                    ? $result['duration_ms']
                    : null
            ),
            'model' => $model,
        ];
    }

    private function branchTournamentScope(
        RunContext $context
    ): ProductionBranchTournamentScope {
        $base = $this->chapter['_base_snapshot'] ?? null;
        if (!is_array($base)) {
            throw new WorkflowCheckpointException(
                'Branch tournament requires the frozen Chapter base snapshot.'
            );
        }
        $canonRevisionHash = self::optionalHash(
            $base['content_revision_hash'] ?? null
        );
        $baseContentHash = self::optionalHash($base['content_hash'] ?? null);
        $baseCoordinateHash = $canonRevisionHash ?? hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson([
                'schema' => 'harness.run_tournament.empty_base.v1',
                'chapter_id' => (int)$this->chapter['id'],
                'canonical_state' => (string)($base['canonical_state'] ?? 'none'),
                'content_hash' => $baseContentHash,
            ])
        );
        $outlineSnapshot = self::normalizeForHash([
            'chapter_id' => (int)$this->chapter['id'],
            'chapter_number' => (int)$this->chapter['chapter_number'],
            'title' => (string)($this->chapter['title'] ?? ''),
            'outline' => (string)($this->chapter['outline'] ?? ''),
            'key_points' => (string)($this->chapter['key_points'] ?? ''),
            'hook' => (string)($this->chapter['hook'] ?? ''),
            'pacing' => (string)($this->chapter['pacing'] ?? ''),
        ]);
        $promptHash = self::optionalHash($context->attributes['prompt_hash'] ?? null);
        if ($promptHash === null) {
            throw new WorkflowCheckpointException(
                'Branch tournament requires the frozen Prompt hash.'
            );
        }
        return new ProductionBranchTournamentScope(
            $context->request->runUid,
            $context->request->userId,
            $context->request->novelId,
            (int)$this->chapter['id'],
            $baseCoordinateHash,
            $canonRevisionHash,
            hash(
                'sha256',
                HarnessPersistenceValue::canonicalJson($outlineSnapshot)
            ),
            $promptHash,
            $this->featureSnapshot->checksum
        );
    }

    /** Refuse Canon if tournament lineage was lost or substituted. */
    private function assertBranchTournamentCommitEvidence(RunContext $context): void
    {
        $scopeReference = $context->artifacts['branch.tournament.scope'] ?? null;
        $resultReference = $context->artifacts['branch.tournament.result'] ?? null;
        if (!is_array($scopeReference) || !is_array($resultReference)) {
            throw new ValidationBlockedException(
                'Canon commit requires complete Branch tournament evidence.'
            );
        }
        $scopePayload = $this->artifactStore->loadJson(
            $context,
            $scopeReference,
            'branch.tournament.scope'
        );
        $expectedScope = $this->branchTournamentScope($context);
        if (HarnessPersistenceValue::canonicalMysqlJson($scopePayload)
            !== HarnessPersistenceValue::canonicalMysqlJson($expectedScope->toArray())) {
            throw new ValidationBlockedException(
                'Canon commit refused stale Branch tournament scope.'
            );
        }
        $result = $this->artifactStore->loadJson(
            $context,
            $resultReference,
            'branch.tournament.result'
        );
        $resultHash = $result['result_hash'] ?? null;
        $hashPayload = $result;
        unset($hashPayload['result_hash']);
        $provenance = $result['provenance'] ?? null;
        $winner = $result['winner'] ?? null;
        $winnerOrdinal = is_array($winner)
            ? ($winner['candidate_ordinal'] ?? null)
            : null;
        if (($result['schema'] ?? null) !== 'harness.branch_tournament.result.v1'
            || ($result['scope_hash'] ?? null) !== $expectedScope->scopeHash
            || !is_string($resultHash)
            || !hash_equals(
                hash('sha256', HarnessPersistenceValue::canonicalMysqlJson($hashPayload)),
                $resultHash
            )
            || !is_array($provenance)
            || ($provenance['deterministic'] ?? null) !== true
            || ($provenance['pure_reviewer'] ?? null) !== true
            || ($provenance['branch_persisted'] ?? null) !== false
            || ($provenance['branch_head_writes'] ?? null) !== false
            || ($provenance['canon_writes'] ?? null) !== false
            || ($provenance['memory_writes'] ?? null) !== false
            || !is_int($winnerOrdinal)
            || $winnerOrdinal < 1 || $winnerOrdinal > 2) {
            throw new ValidationBlockedException(
                'Canon commit refused malformed Branch tournament provenance.'
            );
        }
        $candidateLogicalKey = "branch.tournament.candidate.{$winnerOrdinal}";
        $candidateReference = $context->artifacts[$candidateLogicalKey] ?? null;
        if (!is_array($candidateReference)) {
            throw new ValidationBlockedException(
                'Canon commit cannot resolve the Branch tournament winner.'
            );
        }
        $candidatePayload = $this->artifactStore->loadJson(
            $context,
            $candidateReference,
            $candidateLogicalKey
        );
        try {
            $candidate = ProductionBranchCandidateGeneration::fromArtifact(
                $candidatePayload,
                $expectedScope,
                $winnerOrdinal
            );
        } catch (Throwable $exception) {
            throw new ValidationBlockedException(
                'Canon commit refused stale Branch candidate evidence.',
                0,
                $exception
            );
        }
        $winnerHash = hash('sha256', $candidate->content);
        if (($winner['candidate_uid'] ?? null)
                !== $expectedScope->candidateUid($winnerOrdinal)
            || ($winner['content_hash'] ?? null) !== $winnerHash) {
            throw new ValidationBlockedException(
                'Canon commit refused a substituted tournament winner.'
            );
        }
        if ($this->featureSnapshot->parallelReview) {
            $decisionReference = $context->artifacts['review.decision'] ?? null;
            $decision = is_array($decisionReference)
                ? $this->artifactStore->loadJson(
                    $context,
                    $decisionReference,
                    'review.decision'
                )
                : null;
            if (!is_array($decision)
                || ($decision['raw_draft_hash'] ?? null) !== $winnerHash) {
                throw new ValidationBlockedException(
                    'Post-generation Review is not chained to the tournament winner.'
                );
            }
        } elseif (!hash_equals($winnerHash, hash('sha256', $this->draft))) {
            throw new ValidationBlockedException(
                'Canon commit content is not the tournament winner.'
            );
        }
    }

    /**
     * Run the enabled post-generation Review or recover its complete,
     * content-addressed Artifact bundle after a worker crash.
     *
     * @param array<string,mixed> $rawDraftReference
     * @param array<string,mixed> $generationReference
     * @param array<string,array<string,mixed>> $upstreamArtifacts
     * @param array<string,mixed> $upstreamAttributes
     */
    private function reviewDraft(
        RunContext $context,
        string $rawDraft,
        array $rawDraftReference,
        array $generationReference,
        bool $recoveredGeneration,
        array $upstreamArtifacts = [],
        array $upstreamAttributes = []
    ): StageResult {
        if (!$this->featureSnapshot->parallelReview) {
            throw new LogicException('Post-generation Review was invoked while frozen off.');
        }
        if ($rawDraft === '') {
            throw new PermanentStageException(
                'Post-generation Review cannot process an empty raw Draft.'
            );
        }

        $stableContext = new ReviewStableContext([
            'schema' => 'harness.review.chapter_context.v1',
            'run_uid' => $context->request->runUid,
            'novel_id' => $context->request->novelId,
            'chapter_id' => (int)$this->chapter['id'],
            'chapter_number' => (int)$this->chapter['chapter_number'],
            'base_revision_hash' => self::optionalHash(
                $context->attributes['base_revision_hash'] ?? null
            ),
            'context_hash' => self::optionalHash(
                $context->attributes['context_hash'] ?? null
            ),
            'prompt_hash' => self::optionalHash(
                $context->attributes['prompt_hash'] ?? null
            ),
            'policy_checksum' => $this->policySnapshot->checksum(),
            'feature_snapshot_checksum' => $this->featureSnapshot->checksum,
        ]);
        $stableReference = $this->artifactStore->storeJson(
            $context,
            'review.stable_context',
            'review.stable_context',
            $stableContext->toArray(),
            GenerationArtifactSensitivity::INTERNAL
        );

        $outcome = $this->recoverReviewOutcome(
            $context,
            $rawDraft,
            $stableContext
        );
        $reviewArtifacts = $outcome['artifacts'] ?? [];
        $recoveredReview = $outcome !== null;

        if ($outcome === null) {
            $reviewArtifacts = [];
            $evidenceStore = new CallablePostGenerationReviewEvidenceStore(
                function (PostGenerationReviewResult $result) use (
                    $context,
                    &$reviewArtifacts
                ): void {
                    $definitions = [
                        'reviewer_reports' => [
                            'review.reports',
                            'review.reports',
                            GenerationArtifactSensitivity::RESTRICTED,
                        ],
                        'decision' => [
                            'review.decision',
                            'review.decision',
                            GenerationArtifactSensitivity::INTERNAL,
                        ],
                        'edited_draft' => [
                            'review.edited_draft',
                            'review.edited_draft',
                            GenerationArtifactSensitivity::RESTRICTED,
                        ],
                        'provenance' => [
                            'review.provenance',
                            'review.provenance',
                            GenerationArtifactSensitivity::INTERNAL,
                        ],
                    ];
                    $reviewArtifacts = $this->artifactStore->storeReviewBundle(
                        $context,
                        $result->artifactBundle(),
                        $definitions
                    );
                }
            );
            try {
                $bridge = $this->reviewBridgeFactory === null
                    ? ProductionPostGenerationReviewFactory::create(
                        $this->policySnapshot,
                        $evidenceStore
                    )
                    : ($this->reviewBridgeFactory)(
                        $this->policySnapshot,
                        $evidenceStore
                    );
                if (!$bridge instanceof PostGenerationReviewBridge) {
                    throw new PermanentStageException(
                        'Post-generation Review factory returned no typed bridge.'
                    );
                }
                // No generic approval is exposed for this pre-Canon Artifact;
                // a blocked result is therefore quarantined, never auto-committed.
                $result = $bridge->review(
                    $rawDraft,
                    $this->featureSnapshot,
                    $stableContext,
                    false
                );
            } catch (PostGenerationReviewFailureException $exception) {
                $this->rethrowReviewFailure($exception);
            }
            $outcome = [
                'result_uid' => $result->resultUid,
                'content' => $result->content,
                'content_hash' => $result->contentHash,
                'raw_hash' => $result->rawDraftHash,
                'stable_context_hash' => $stableContext->manifestHash,
                'disposition' => $result->disposition,
                'commit_allowed' => $result->commitAllowed(),
                'round_count' => count($result->rounds),
                'artifacts' => $reviewArtifacts,
            ];
        }

        foreach ([
            'review.reports', 'review.decision',
            'review.edited_draft', 'review.provenance',
        ] as $logicalKey) {
            if (!is_array($reviewArtifacts[$logicalKey] ?? null)) {
                throw new WorkflowCheckpointException(
                    "Post-generation Review Artifact {$logicalKey} is missing."
                );
            }
        }

        $content = $outcome['content'];
        if (!is_string($content) || $content === '') {
            throw new WorkflowCheckpointException(
                'Post-generation Review selected an empty Draft.'
            );
        }
        $this->draft = $content;
        $selectedReference = $this->artifactStore->storeText(
            $context,
            'draft.full',
            'draft.full',
            $content,
            GenerationArtifactSensitivity::RESTRICTED
        );

        $artifacts = $upstreamArtifacts + [
            'draft.raw' => $rawDraftReference,
            'draft.generation' => $generationReference,
            'review.stable_context' => $stableReference,
        ] + $reviewArtifacts + ['draft.full' => $selectedReference];

        return $this->reviewedDraftResult(
            $outcome,
            $artifacts,
            $recoveredGeneration,
            $recoveredReview,
            $upstreamAttributes
        );
    }

    /**
     * @return array{
     *   result_uid:string,content:string,content_hash:string,raw_hash:string,
     *   stable_context_hash:string,
     *   disposition:PostGenerationReviewDisposition,commit_allowed:bool,
     *   round_count:int,artifacts:array<string,array<string,mixed>>
     * }|null
     */
    private function recoverReviewOutcome(
        RunContext $context,
        string $rawDraft,
        ReviewStableContext $stableContext
    ): ?array {
        $definitions = [
            'review.reports' => 'review.reports',
            'review.decision' => 'review.decision',
            'review.edited_draft' => 'review.edited_draft',
            'review.provenance' => 'review.provenance',
        ];
        $found = [];
        foreach ($definitions as $logicalKey => $type) {
            $artifact = $this->artifactStore->findJson($context, $logicalKey, $type);
            if ($artifact === null) {
                return null;
            }
            $found[$logicalKey] = $artifact;
        }

        $reports = $found['review.reports']['payload'];
        $decision = $found['review.decision']['payload'];
        $edited = $found['review.edited_draft']['payload'];
        $provenance = $found['review.provenance']['payload'];
        $resultUid = $decision['result_uid'] ?? null;
        $rawHash = hash('sha256', $rawDraft);
        $content = $edited['content'] ?? null;
        $contentHash = $edited['content_hash'] ?? null;
        $disposition = is_string($decision['disposition'] ?? null)
            ? PostGenerationReviewDisposition::tryFrom($decision['disposition'])
            : null;
        $commitAllowed = $decision['commit_allowed'] ?? null;
        $roundCount = $decision['round_count'] ?? null;
        $validDisposition = $disposition !== null
            && $disposition !== PostGenerationReviewDisposition::BYPASSED;
        $expectedCommit = $disposition === PostGenerationReviewDisposition::ACCEPTED;
        if (($reports['schema'] ?? null) !== 'harness.review.reports.v1'
            || ($decision['schema'] ?? null) !== 'harness.review.decision.v1'
            || ($edited['schema'] ?? null) !== 'harness.review.edited_draft.v1'
            || ($provenance['schema'] ?? null) !== 'harness.review.provenance.v1'
            || !is_string($resultUid)
            || preg_match('/^[a-f0-9]{32}$/D', $resultUid) !== 1
            || ($reports['result_uid'] ?? null) !== $resultUid
            || ($edited['result_uid'] ?? null) !== $resultUid
            || ($provenance['result_uid'] ?? null) !== $resultUid
            || ($reports['raw_draft_hash'] ?? null) !== $rawHash
            || ($decision['raw_draft_hash'] ?? null) !== $rawHash
            || ($edited['raw_draft_hash'] ?? null) !== $rawHash
            || ($provenance['raw_draft_hash'] ?? null) !== $rawHash
            || !is_string($content) || $content === ''
            || !is_string($contentHash)
            || !hash_equals(hash('sha256', $content), $contentHash)
            || ($decision['selected_draft_hash'] ?? null) !== $contentHash
            || ($provenance['selected_draft_hash'] ?? null) !== $contentHash
            || ($provenance['stable_context_hash'] ?? null) !== $stableContext->manifestHash
            || ($provenance['feature_snapshot_checksum'] ?? null)
                !== $this->featureSnapshot->checksum
            || !$validDisposition
            || !is_bool($commitAllowed)
            || $commitAllowed !== $expectedCommit
            || !is_int($roundCount) || $roundCount < 1 || $roundCount > 2) {
            throw new WorkflowCheckpointException(
                'Recovered post-generation Review evidence is incomplete or inconsistent.'
            );
        }

        $references = [];
        foreach ($found as $logicalKey => $artifact) {
            $references[$logicalKey] = $artifact['reference'];
        }
        return [
            'result_uid' => $resultUid,
            'content' => $content,
            'content_hash' => $contentHash,
            'raw_hash' => $rawHash,
            'stable_context_hash' => $stableContext->manifestHash,
            'disposition' => $disposition,
            'commit_allowed' => $commitAllowed,
            'round_count' => $roundCount,
            'artifacts' => $references,
        ];
    }

    /**
     * @param array<string,mixed> $outcome
     * @param array<string,array<string,mixed>> $artifacts
     */
    private function reviewedDraftResult(
        array $outcome,
        array $artifacts,
        bool $recoveredGeneration,
        bool $recoveredReview,
        array $extraAttributes = []
    ): StageResult {
        $disposition = $outcome['disposition'] ?? null;
        if (!$disposition instanceof PostGenerationReviewDisposition) {
            throw new WorkflowCheckpointException('Review outcome disposition is invalid.');
        }
        if ($disposition === PostGenerationReviewDisposition::BYPASSED) {
            throw new WorkflowCheckpointException(
                'Enabled Review cannot checkpoint a bypassed result.'
            );
        }
        // Review never writes Canon and never short-circuits Candidate-first
        // persistence. A blocked decision advances to Commit, where
        // saveChapter() creates the owned Candidate + Approval and the unified
        // Canon gate quarantines it without mutating Chapter/Memory state.
        $stageDisposition = StageDisposition::CONTINUE;
        $content = $outcome['content'] ?? null;
        if (!is_string($content)) {
            throw new WorkflowCheckpointException('Review outcome has no selected Draft.');
        }
        return new StageResult(
            $stageDisposition,
            $this->withAttemptObservation($extraAttributes + [
                'raw_draft_hash' => (string)$outcome['raw_hash'],
                'draft_hash' => hash('sha256', $content),
                'generated_size_bytes' => strlen($content),
                'model_label' => $this->usedModelLabel,
                'review_result_uid' => (string)$outcome['result_uid'],
                'review_stable_context_hash' =>
                    (string)$outcome['stable_context_hash'],
                'review_disposition' => $disposition->value,
                'review_commit_allowed' => (bool)$outcome['commit_allowed'],
                'review_round_count' => (int)$outcome['round_count'],
            ]),
            $artifacts,
            max(0, (int)($this->usage['prompt_tokens'] ?? 0)),
            max(0, (int)($this->usage['completion_tokens'] ?? 0)),
            '0',
            null,
            [
                'phase' => 'postGenerationReview',
                'generation_artifact_recovered' => $recoveredGeneration,
                'review_artifacts_recovered' => $recoveredReview,
            ]
        );
    }

    private function rethrowReviewFailure(
        PostGenerationReviewFailureException $exception
    ): never {
        $cursor = $exception;
        do {
            if ($cursor instanceof RetryableInfrastructureException) {
                throw $cursor;
            }
            if ($cursor instanceof WorkflowCheckpointException) {
                throw $cursor;
            }
            if ($cursor instanceof PermanentStageException) {
                throw $cursor;
            }
            $cursor = $cursor->getPrevious();
        } while ($cursor instanceof Throwable);
        throw new PermanentStageException(
            'Post-generation Review failed closed.',
            0,
            $exception
        );
    }

    private static function optionalHash(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new WorkflowCheckpointException(
                'Review stable context received an invalid checkpoint hash.'
            );
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $draftReference
     * @param array<string,mixed> $generationReference
     * @param array<string,array<string,mixed>> $extraArtifacts
     * @param array<string,mixed> $extraAttributes
     * @param array<string,mixed> $extraMetadata
     */
    private function draftResult(
        string $content,
        array $draftReference,
        array $generationReference,
        bool $recovered,
        array $extraArtifacts = [],
        array $extraAttributes = [],
        array $extraMetadata = []
    ): StageResult {
        $inputTokens = max(0, (int)($this->usage['prompt_tokens'] ?? 0));
        $outputTokens = max(0, (int)($this->usage['completion_tokens'] ?? 0));
        return StageResult::continue(
            attributes: $this->withAttemptObservation($extraAttributes + [
                'draft_hash' => hash('sha256', $content),
                'generated_size_bytes' => strlen($content),
                'model_label' => $this->usedModelLabel,
            ]),
            artifacts: $extraArtifacts + [
                'draft.full' => $draftReference,
                'draft.generation' => $generationReference,
            ],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            metadata: $extraMetadata + [
                'phase' => 'streamWrite',
                'artifact_recovered' => $recovered,
            ]
        );
    }

    /**
     * Durable, credential-free coordinates for the one selected generation
     * call represented by the Generate Step attempt. Cost stays outside this
     * observation because the legacy provider path has no complete billing
     * marker; the Stage summary therefore remains the honest source of cost.
     *
     * @param array<string,mixed>|null $usage
     * @return array<string,mixed>|null
     */
    private function attemptObservation(
        ?string $provider,
        ?string $model,
        ?string $modelId,
        ?array $usage,
        ?int $durationMs
    ): ?array {
        if ($provider === null || $provider === ''
            || $model === null || $model === ''
            || $modelId === null || $modelId === ''
            || $durationMs === null) {
            return null;
        }
        HarnessPersistenceValue::identifier(
            $provider,
            'legacy_stage.attempt.provider',
            64
        );
        HarnessPersistenceValue::string(
            $model,
            'legacy_stage.attempt.model',
            1,
            191
        );
        $durationMs = HarnessPersistenceValue::nonNegativeInt(
            $durationMs,
            'legacy_stage.attempt.latency_ms'
        );
        $modelConfig = $this->frozenModelConfig($modelId, $model);
        $cacheTokens = max(0, (int)($usage['cache_hit_tokens'] ?? 0));

        return RuntimeData::normalizeArray([
            'schema' => 'harness.generation_attempt.observation.v1',
            'provider' => $provider,
            'model' => $model,
            'model_config' => $modelConfig,
            'cache_tokens' => $cacheTokens,
            'latency_ms' => $durationMs,
        ], 'legacy_stage.generation_attempt_observation');
    }

    /** @return array<string,mixed> */
    private function frozenModelConfig(
        string $modelId,
        string $model
    ): array {
        $snapshot = $this->inputSnapshot->toArray();
        foreach ($snapshot['models'] as $candidate) {
            if (!is_array($candidate) || (string)($candidate['id'] ?? '') !== $modelId) {
                continue;
            }
            if (($candidate['model_name'] ?? null) !== $model) {
                throw new WorkflowCheckpointException(
                    'Selected generation model coordinates differ from the frozen input snapshot.'
                );
            }
            return RuntimeData::normalizeArray([
                'model_id' => HarnessPersistenceValue::positiveInt(
                    $candidate['id'] ?? null,
                    'legacy_stage.attempt.model_id'
                ),
                'configured_max_tokens' => HarnessPersistenceValue::nonNegativeInt(
                    $candidate['max_tokens'] ?? null,
                    'legacy_stage.attempt.configured_max_tokens'
                ),
                'configured_temperature' => $candidate['temperature'] ?? null,
                'thinking_enabled' => HarnessPersistenceValue::bool(
                    $candidate['thinking_enabled'] ?? null,
                    'legacy_stage.attempt.thinking_enabled'
                ),
            ], 'legacy_stage.generation_attempt_model_config');
        }
        throw new WorkflowCheckpointException(
            'Selected generation model is absent from the frozen input snapshot.'
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    private function recoveredAttemptObservation(array $payload): ?array
    {
        if (!array_key_exists('generation_attempt_observation', $payload)) {
            // Artifacts produced before this evidence existed remain recoverable;
            // their missing Attempt is surfaced by observability as unavailable.
            return null;
        }
        $observation = $payload['generation_attempt_observation'];
        if ($observation === null) {
            return null;
        }
        if (!is_array($observation)) {
            throw new WorkflowCheckpointException(
                'Recovered generation Attempt observation is malformed.'
            );
        }
        return RuntimeData::normalizeArray(
            $observation,
            'legacy_stage.recovered_generation_attempt_observation'
        );
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function withAttemptObservation(array $attributes): array
    {
        if ($this->generationAttemptObservation !== null) {
            $attributes['generation_attempt_observation'] =
                $this->generationAttemptObservation;
        }
        return $attributes;
    }

    /** @param array<string,mixed>|null $memoryContext @return array<string,mixed> */
    private static function contextManifest(?array $memoryContext): array
    {
        $normalized = self::normalizeForHash($memoryContext ?? []);
        if (!is_array($normalized)) {
            throw new WorkflowCheckpointException(
                'Normalized legacy memory context must remain an array.'
            );
        }
        $json = HarnessPersistenceValue::canonicalJson(['context' => $normalized]);
        $sectionNames = array_values(array_map('strval', array_keys($normalized)));
        sort($sectionNames, SORT_STRING);
        return [
            'schema' => 'legacy.context.manifest.v1',
            'present' => $memoryContext !== null,
            'context_hash' => hash('sha256', $json),
            'section_count' => count($normalized),
            'section_names' => $sectionNames,
        ];
    }

    private static function normalizeForHash(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new WorkflowCheckpointException(
                'Legacy memory context exceeds the supported nesting depth.'
            );
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    static fn (mixed $item): mixed => self::normalizeForHash(
                        $item,
                        $depth + 1
                    ),
                    $value
                );
            }
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            $result = [];
            foreach ($keys as $key) {
                $result[(string)$key] = self::normalizeForHash(
                    $value[$key],
                    $depth + 1
                );
            }
            return $result;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return $value;
        }
        return ['value_type' => is_object($value) ? $value::class : get_debug_type($value)];
    }
}
