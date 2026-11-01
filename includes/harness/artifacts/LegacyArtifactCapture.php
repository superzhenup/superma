<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/events/LegacyWritingTaskRunIdentity.php';
require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';
require_once dirname(__DIR__) . '/persistence/GenerationArtifact.php';
require_once dirname(__DIR__) . '/persistence/GenerationStateMachine.php';
require_once dirname(__DIR__) . '/persistence/RunArtifactRepository.php';
require_once dirname(__DIR__, 2) . '/quality/WritePolicySnapshot.php';
require_once dirname(__DIR__, 2) . '/quality/QualityGateDecision.php';
require_once dirname(__DIR__, 2) . '/db.php';

/** Exact legacy task generation allowed to emit shadow Artifacts. */
final readonly class LegacyArtifactCaptureScope
{
    public function __construct(
        public string $taskId,
        public string $leaseOwner,
        public string $leaseToken,
        public int $fenceGeneration,
        public int $novelId,
        public int $chapterId,
        public WritePolicySnapshot $policySnapshot
    ) {
        LegacyWritingTaskRunIdentity::assertTaskId($taskId);
        HarnessPersistenceValue::string($leaseOwner, 'legacy_artifact.lease_owner', 1, 120);
        HarnessPersistenceValue::uid($leaseToken, 'legacy_artifact.lease_token');
        HarnessPersistenceValue::positiveInt(
            $fenceGeneration,
            'legacy_artifact.fence_generation'
        );
        if ($fenceGeneration > 4294967295) {
            throw new InvalidArgumentException(
                'Legacy Artifact fence generation exceeds Artifact revision capacity.'
            );
        }
        HarnessPersistenceValue::positiveInt($novelId, 'legacy_artifact.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'legacy_artifact.chapter_id');
    }
}

/** Authenticated shadow Run selected for one exact writing-task lease. */
final readonly class LegacyArtifactTarget
{
    public function __construct(
        public int $runId,
        public string $runUid,
        public string $taskId,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public int $revisionNo,
        public string $policyChecksum
    ) {
        HarnessPersistenceValue::positiveInt($runId, 'legacy_artifact.run_id');
        HarnessPersistenceValue::uid($runUid, 'legacy_artifact.run_uid');
        LegacyWritingTaskRunIdentity::assertTaskId($taskId);
        HarnessPersistenceValue::positiveInt($userId, 'legacy_artifact.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'legacy_artifact.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'legacy_artifact.chapter_id');
        HarnessPersistenceValue::positiveInt($revisionNo, 'legacy_artifact.revision_no');
        HarnessPersistenceValue::hash($policyChecksum, 'legacy_artifact.policy_checksum');
    }
}

interface LegacyArtifactTargetResolver
{
    public function resolve(LegacyArtifactCaptureScope $scope): ?LegacyArtifactTarget;
}

interface LegacyArtifactSink
{
    public function store(GenerationArtifactDraft $draft): GenerationArtifact;
}

/** Production sink; the M62 Repository remains the sole Artifact writer. */
final class RunArtifactRepositorySink implements LegacyArtifactSink
{
    public function __construct(private readonly RunArtifactRepository $repository)
    {
    }

    public function store(GenerationArtifactDraft $draft): GenerationArtifact
    {
        return $this->repository->storeIdempotent($draft);
    }
}

/**
 * Resolve only an exact, live writing-task lease and its immutable P1-02
 * shadow Run. Missing rows are a safe no-op; duplicate or malformed identity
 * is an integrity failure which the outer fail-open service logs.
 */
final class DatabaseLegacyArtifactTargetResolver implements LegacyArtifactTargetResolver
{
    private Closure $fetchRows;

    /** @param callable(string,array<int,mixed>):array|null $fetchRows */
    public function __construct(?callable $fetchRows = null)
    {
        $this->fetchRows = $fetchRows === null
            ? static fn (string $sql, array $params): array => DB::fetchAll($sql, $params)
            : Closure::fromCallable($fetchRows);
    }

    public function resolve(LegacyArtifactCaptureScope $scope): ?LegacyArtifactTarget
    {
        $idempotencyKey = LegacyWritingTaskRunIdentity::idempotencyKey($scope->taskId);
        $rows = ($this->fetchRows)(
            "SELECT wt.task_id,
                    wt.user_id AS task_user_id,
                    wt.novel_id AS task_novel_id,
                    wt.chapter_id AS task_chapter_id,
                    wt.fence_generation AS task_fence_generation,
                    wt.policy_snapshot_hash,
                    n.user_id AS novel_user_id,
                    gr.id AS run_id,
                    gr.run_uid,
                    gr.user_id AS run_user_id,
                    gr.novel_id AS run_novel_id,
                    gr.chapter_id AS run_chapter_id,
                    gr.queue,
                    gr.run_type,
                    gr.workflow_name,
                    gr.workflow_version,
                    gr.authority_mode,
                    gr.state AS run_state,
                    gr.idempotency_key,
                    gr.input_hash,
                    gr.fence_generation AS run_fence_generation,
                    gr.flag_snapshot_json,
                    gr.goal_json
             FROM writing_tasks wt
             INNER JOIN novels n
                     ON n.id=wt.novel_id AND n.user_id=wt.user_id
             INNER JOIN generation_runs gr
                     ON gr.user_id=wt.user_id
                    AND gr.novel_id=wt.novel_id
                    AND gr.chapter_id=wt.chapter_id
             WHERE wt.task_id=?
               AND wt.novel_id=? AND wt.chapter_id=?
               AND wt.lease_owner=? AND wt.lease_token=?
               AND wt.fence_generation=?
               AND wt.lease_expires_at IS NOT NULL
               AND wt.lease_expires_at>=UTC_TIMESTAMP(6)
               AND wt.cancel_requested=0
               AND wt.state IN ('preparing','generating','validating','content_saved','postprocessing')
               AND wt.policy_snapshot_hash=?
               AND gr.idempotency_key=?
               AND gr.run_uid=?
               AND gr.queue=? AND gr.run_type=?
               AND gr.workflow_name=? AND gr.workflow_version=?
               AND gr.authority_mode=? AND gr.state='running'
             LIMIT 2",
            [
                $scope->taskId,
                $scope->novelId,
                $scope->chapterId,
                $scope->leaseOwner,
                $scope->leaseToken,
                $scope->fenceGeneration,
                $scope->policySnapshot->checksum(),
                $idempotencyKey,
                LegacyWritingTaskRunIdentity::runUid($scope->taskId),
                LegacyWritingTaskRunIdentity::QUEUE,
                LegacyWritingTaskRunIdentity::RUN_TYPE,
                LegacyWritingTaskRunIdentity::WORKFLOW_NAME,
                LegacyWritingTaskRunIdentity::WORKFLOW_VERSION,
                LegacyWritingTaskRunIdentity::AUTHORITY_MODE,
            ]
        );
        if (!is_array($rows)) {
            throw new HarnessPersistenceException(
                'Legacy Artifact resolver did not return an array result.'
            );
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new HarnessPersistenceException(
                'Legacy writing task resolved to an ambiguous shadow Run.'
            );
        }

        $row = $rows[0];
        $target = $this->validateIdentity($scope, $row, $idempotencyKey);
        $this->validateFrozenPayloads($scope, $row);
        return $target;
    }

    /** @param array<string,mixed> $row */
    private function validateIdentity(
        LegacyArtifactCaptureScope $scope,
        array $row,
        string $idempotencyKey
    ): LegacyArtifactTarget {
        HarnessPersistenceValue::requireFields($row, [
            'task_id', 'task_user_id', 'task_novel_id', 'task_chapter_id',
            'task_fence_generation', 'policy_snapshot_hash', 'novel_user_id',
            'run_id', 'run_uid', 'run_user_id', 'run_novel_id', 'run_chapter_id',
            'queue', 'run_type', 'workflow_name', 'workflow_version',
            'authority_mode', 'run_state', 'idempotency_key', 'input_hash',
            'run_fence_generation', 'flag_snapshot_json', 'goal_json',
        ], 'legacy_artifact_shadow_target');

        $taskUserId = HarnessPersistenceValue::positiveInt(
            $row['task_user_id'],
            'legacy_artifact.task_user_id'
        );
        $runId = HarnessPersistenceValue::positiveInt(
            $row['run_id'],
            'legacy_artifact.run_id'
        );
        $runUid = HarnessPersistenceValue::uid(
            $row['run_uid'],
            'legacy_artifact.run_uid'
        );
        $taskFence = HarnessPersistenceValue::positiveInt(
            $row['task_fence_generation'],
            'legacy_artifact.task_fence_generation'
        );
        $runFence = HarnessPersistenceValue::positiveInt(
            $row['run_fence_generation'],
            'legacy_artifact.run_fence_generation'
        );
        $policyChecksum = HarnessPersistenceValue::hash(
            $row['policy_snapshot_hash'],
            'legacy_artifact.policy_snapshot_hash'
        );

        $matches = is_string($row['task_id'])
            && hash_equals($scope->taskId, $row['task_id'])
            && $taskUserId === HarnessPersistenceValue::positiveInt(
                $row['novel_user_id'],
                'legacy_artifact.novel_user_id'
            )
            && $taskUserId === HarnessPersistenceValue::positiveInt(
                $row['run_user_id'],
                'legacy_artifact.run_user_id'
            )
            && HarnessPersistenceValue::positiveInt(
                $row['task_novel_id'],
                'legacy_artifact.task_novel_id'
            ) === $scope->novelId
            && HarnessPersistenceValue::positiveInt(
                $row['run_novel_id'],
                'legacy_artifact.run_novel_id'
            ) === $scope->novelId
            && HarnessPersistenceValue::positiveInt(
                $row['task_chapter_id'],
                'legacy_artifact.task_chapter_id'
            ) === $scope->chapterId
            && HarnessPersistenceValue::positiveInt(
                $row['run_chapter_id'],
                'legacy_artifact.run_chapter_id'
            ) === $scope->chapterId
            && $taskFence === $scope->fenceGeneration
            && $runFence === $scope->fenceGeneration
            && hash_equals($policyChecksum, $scope->policySnapshot->checksum())
            && hash_equals($runUid, LegacyWritingTaskRunIdentity::runUid($scope->taskId))
            && (string)$row['queue'] === LegacyWritingTaskRunIdentity::QUEUE
            && (string)$row['run_type'] === LegacyWritingTaskRunIdentity::RUN_TYPE
            && (string)$row['workflow_name'] === LegacyWritingTaskRunIdentity::WORKFLOW_NAME
            && (string)$row['workflow_version'] === LegacyWritingTaskRunIdentity::WORKFLOW_VERSION
            && (string)$row['authority_mode'] === LegacyWritingTaskRunIdentity::AUTHORITY_MODE
            && (string)$row['run_state'] === GenerationRunState::RUNNING->value
            && (string)$row['idempotency_key'] === $idempotencyKey
            && (string)$row['input_hash']
                === LegacyWritingTaskRunIdentity::inputHash($scope->taskId);
        if (!$matches) {
            throw new HarnessPersistenceException(
                'Legacy writing task and shadow Run identity do not match.'
            );
        }

        return new LegacyArtifactTarget(
            $runId,
            $runUid,
            $scope->taskId,
            $taskUserId,
            $scope->novelId,
            $scope->chapterId,
            $scope->fenceGeneration,
            $policyChecksum
        );
    }

    /** @param array<string,mixed> $row */
    private function validateFrozenPayloads(
        LegacyArtifactCaptureScope $scope,
        array $row
    ): void {
        $flags = HarnessPersistenceValue::decodeJsonArray(
            $row['flag_snapshot_json'],
            'legacy_artifact.flag_snapshot_json'
        );
        $goal = HarnessPersistenceValue::decodeJsonArray(
            $row['goal_json'],
            'legacy_artifact.goal_json'
        );
        HarnessSensitiveDataGuard::assertSafeStructure($flags, 'legacy_artifact.flags');
        HarnessSensitiveDataGuard::assertSafeStructure($goal, 'legacy_artifact.goal');

        if (($flags['source'] ?? null) !== 'writing_tasks'
            || ($flags['projection'] ?? null) !== 'shadow'
            || ($flags['frozen_harness_mode'] ?? null) !== $scope->policySnapshot->harnessMode()
            || !is_string($flags['policy_checksum'] ?? null)
            || !hash_equals(
                $scope->policySnapshot->checksum(),
                (string)$flags['policy_checksum']
            )
            || ($goal['kind'] ?? null) !== 'chapter_write'
            || !is_string($goal['legacy_task_id'] ?? null)
            || !hash_equals($scope->taskId, (string)$goal['legacy_task_id'])
            || (int)($goal['chapter_id'] ?? 0) !== $scope->chapterId) {
            throw new HarnessPersistenceException(
                'Shadow Run frozen flags/goal do not identify this writing task generation.'
            );
        }
    }
}

/**
 * Fail-open capture of legacy WriteEngine evidence into M62 Artifacts.
 *
 * The frozen snapshot is the only enablement input. Off mode returns before
 * resolver, serialization, hashing, or database work. All enabled writes first
 * resolve an exact live writing-task fence and its P1-02 shadow Run.
 */
final class LegacyArtifactCapture
{
    private Closure $logger;

    public function __construct(
        private readonly LegacyArtifactTargetResolver $resolver,
        private readonly LegacyArtifactSink $sink,
        ?callable $logger = null
    ) {
        $this->logger = $logger === null
            ? static function (string $message): void {
                error_log($message);
            }
            : Closure::fromCallable($logger);
    }

    public static function production(): self
    {
        return new self(
            new DatabaseLegacyArtifactTargetResolver(),
            new RunArtifactRepositorySink(new RunArtifactRepository(DB::getPdo()))
        );
    }

    /** @param list<array<string,mixed>> $messages */
    public function capturePromptBundle(
        LegacyArtifactCaptureScope $scope,
        array $messages,
        ?array $memoryContext
    ): int {
        if (!$this->isEnabled($scope)) {
            return 0;
        }
        $target = $this->resolveFailOpen($scope, 'prompt_bundle');
        if ($target === null) {
            return 0;
        }

        $captured = 0;
        $captured += (int)$this->storeJson(
            $target,
            'prompt.messages',
            'legacy.prompt.messages',
            ['schema_version' => 1, 'messages' => $messages],
            GenerationArtifactSensitivity::RESTRICTED,
            'build_prompt'
        );

        try {
            $manifest = self::contextManifest($memoryContext);
            $captured += (int)$this->storeJson(
                $target,
                'context.manifest',
                'legacy.context.manifest',
                $manifest,
                GenerationArtifactSensitivity::INTERNAL,
                'build_prompt'
            );
        } catch (Throwable $exception) {
            $this->report($scope->taskId, 'context_manifest', $exception);
        }
        return $captured;
    }

    /** @param array<string,mixed> $manifest */
    public function capturePromptManifest(
        LegacyArtifactCaptureScope $scope,
        array $manifest
    ): int {
        if (!$this->isEnabled($scope)) {
            return 0;
        }
        $target = $this->resolveFailOpen($scope, 'prompt_manifest');
        if ($target === null) {
            return 0;
        }
        return (int)$this->storeJson(
            $target,
            'prompt.manifest',
            'legacy.prompt.manifest',
            ['schema_version' => 1, 'manifest' => $manifest],
            GenerationArtifactSensitivity::INTERNAL,
            'compile_prompt'
        );
    }

    /** @param array<string,mixed> $diff */
    public function capturePromptShadowDiff(
        LegacyArtifactCaptureScope $scope,
        array $diff
    ): int {
        if (!$this->isEnabled($scope)) {
            return 0;
        }
        $target = $this->resolveFailOpen($scope, 'prompt_shadow_diff');
        if ($target === null) {
            return 0;
        }
        return (int)$this->storeJson(
            $target,
            'prompt.shadow_diff',
            'legacy.prompt.shadow_diff',
            ['schema_version' => 1, 'diff' => $diff],
            GenerationArtifactSensitivity::INTERNAL,
            'compile_prompt'
        );
    }

    public function captureDraft(
        LegacyArtifactCaptureScope $scope,
        string $cleanDraft
    ): int {
        if (!$this->isEnabled($scope)) {
            return 0;
        }
        $target = $this->resolveFailOpen($scope, 'draft');
        if ($target === null) {
            return 0;
        }
        return (int)$this->storeText(
            $target,
            'draft.full',
            'legacy.draft.full',
            $cleanDraft,
            GenerationArtifactSensitivity::RESTRICTED,
            'save_clean_draft'
        );
    }

    /** @param array<string,mixed> $result */
    public function captureQuality(
        LegacyArtifactCaptureScope $scope,
        QualityGateDecision $decision,
        array $result
    ): int {
        if (!$this->isEnabled($scope)) {
            return 0;
        }
        $target = $this->resolveFailOpen($scope, 'quality');
        if ($target === null) {
            return 0;
        }

        $captured = 0;
        $captured += (int)$this->storeJson(
            $target,
            'quality.decision',
            'legacy.quality.decision',
            ['schema_version' => 1, 'decision' => $decision->toArray()],
            GenerationArtifactSensitivity::INTERNAL,
            'quality_gate'
        );
        $captured += (int)$this->storeJson(
            $target,
            'quality.result',
            'legacy.quality.result',
            ['schema_version' => 1, 'result' => $result],
            GenerationArtifactSensitivity::INTERNAL,
            'quality_gate'
        );
        return $captured;
    }

    private function isEnabled(LegacyArtifactCaptureScope $scope): bool
    {
        return $scope->policySnapshot->harnessMode() !== HarnessControlPlane::MODE_OFF;
    }

    private function resolveFailOpen(
        LegacyArtifactCaptureScope $scope,
        string $boundary
    ): ?LegacyArtifactTarget {
        try {
            return $this->resolver->resolve($scope);
        } catch (Throwable $exception) {
            $this->report($scope->taskId, $boundary . '_resolve', $exception);
            return null;
        }
    }

    /** @param array<mixed> $payload */
    private function storeJson(
        LegacyArtifactTarget $target,
        string $artifactType,
        string $logicalKey,
        array $payload,
        GenerationArtifactSensitivity $sensitivity,
        string $boundary
    ): bool {
        try {
            HarnessSensitiveDataGuard::assertSafeStructure($payload, $logicalKey);
            $canonical = HarnessPersistenceValue::canonicalMysqlJson($payload);
            HarnessSensitiveDataGuard::assertSafeText($canonical, $logicalKey);
            $contentHash = hash('sha256', $canonical);
            $draft = GenerationArtifactDraft::inlineJson(
                $this->artifactUid($target, $logicalKey, $contentHash),
                $target->runId,
                null,
                $artifactType,
                $logicalKey,
                $target->revisionNo,
                $payload,
                $sensitivity,
                $this->metadata($target, $boundary)
            );
            $this->sink->store($draft);
            return true;
        } catch (Throwable $exception) {
            $this->report($target->taskId, $logicalKey, $exception);
            return false;
        }
    }

    private function storeText(
        LegacyArtifactTarget $target,
        string $artifactType,
        string $logicalKey,
        string $content,
        GenerationArtifactSensitivity $sensitivity,
        string $boundary
    ): bool {
        try {
            HarnessSensitiveDataGuard::assertSafeText($content, $logicalKey);
            $contentHash = hash('sha256', $content);
            $draft = GenerationArtifactDraft::inlineText(
                $this->artifactUid($target, $logicalKey, $contentHash),
                $target->runId,
                null,
                $artifactType,
                $logicalKey,
                $target->revisionNo,
                $content,
                'text/plain',
                'utf-8',
                $sensitivity,
                $this->metadata($target, $boundary)
            );
            $this->sink->store($draft);
            return true;
        } catch (Throwable $exception) {
            $this->report($target->taskId, $logicalKey, $exception);
            return false;
        }
    }

    private function artifactUid(
        LegacyArtifactTarget $target,
        string $logicalKey,
        string $contentHash
    ): string {
        return substr(hash(
            'sha256',
            implode("\0", [
                'legacy-artifact-v1',
                $target->runUid,
                $logicalKey,
                (string)$target->revisionNo,
                $contentHash,
            ])
        ), 0, 32);
    }

    /** @return array<string,mixed> */
    private function metadata(LegacyArtifactTarget $target, string $boundary): array
    {
        return [
            'capture_schema' => 'legacy_artifact_capture_v1',
            'boundary' => $boundary,
            'legacy_task_id' => $target->taskId,
            'run_uid' => $target->runUid,
            'policy_checksum' => $target->policyChecksum,
            'fence_generation' => $target->revisionNo,
        ];
    }

    /** @return array<string,mixed> */
    private static function contextManifest(?array $memoryContext): array
    {
        if ($memoryContext === null) {
            return [
                'schema_version' => 1,
                'present' => false,
                'context_hash' => hash('sha256', 'null'),
                'section_count' => 0,
                'sections' => [],
            ];
        }

        HarnessSensitiveDataGuard::assertSafeStructure($memoryContext, 'legacy.context');
        $normalized = self::normalizeForHash($memoryContext);
        if (!is_array($normalized)) {
            throw new HarnessPersistenceException('Normalized memory context must remain an array.');
        }
        $contextJson = HarnessPersistenceValue::canonicalJson($normalized);

        $sections = [];
        $sectionNames = array_keys($memoryContext);
        sort($sectionNames, SORT_STRING);
        foreach ($sectionNames as $name) {
            if (!is_string($name)) {
                throw new HarnessPersistenceException(
                    'Memory context top-level keys must be named sections.'
                );
            }
            HarnessSensitiveDataGuard::assertSafeLabel($name, 'context_section');
            $value = self::normalizeForHash($memoryContext[$name]);
            $json = HarnessPersistenceValue::canonicalJson(['value' => $value]);
            $sections[] = [
                'name' => $name,
                'value_type' => get_debug_type($memoryContext[$name]),
                'item_count' => is_array($memoryContext[$name])
                    ? count($memoryContext[$name])
                    : null,
                'size_bytes' => strlen($json),
                'content_hash' => hash('sha256', $json),
            ];
        }

        return [
            'schema_version' => 1,
            'present' => true,
            'context_hash' => hash('sha256', $contextJson),
            'section_count' => count($sections),
            'sections' => $sections,
        ];
    }

    private static function normalizeForHash(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new HarnessPersistenceException('Memory context nesting exceeds 32 levels.');
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    static fn (mixed $item): mixed => self::normalizeForHash($item, $depth + 1),
                    $value
                );
            }
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            $normalized = [];
            foreach ($keys as $key) {
                $normalized[(string)$key] = self::normalizeForHash(
                    $value[$key],
                    $depth + 1
                );
            }
            return $normalized;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return $value;
        }
        if (is_object($value)) {
            return ['object_type' => $value::class];
        }
        return ['value_type' => get_debug_type($value)];
    }

    private function report(string $taskId, string $boundary, Throwable $exception): void
    {
        $safeTaskId = preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $taskId) === 1
            ? $taskId
            : 'unknown_task';
        $safeBoundary = preg_replace('/[^a-z0-9_.-]/i', '_', $boundary) ?: 'unknown';
        $message = sprintf(
            '[HarnessArtifact][ERROR] capture_failed task_id=%s boundary=%s exception=%s code=%d',
            $safeTaskId,
            substr($safeBoundary, 0, 96),
            $exception::class,
            (int)$exception->getCode()
        );
        try {
            ($this->logger)($message);
        } catch (Throwable) {
            try {
                error_log($message);
            } catch (Throwable) {
            }
        }
    }
}
