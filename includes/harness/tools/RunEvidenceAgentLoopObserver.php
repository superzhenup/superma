<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/AgentLoop.php';
require_once dirname(__DIR__) . '/persistence/bootstrap.php';

interface AgentLoopEvidenceSink
{
    /** @param array<string,mixed> $payload */
    public function appendEvent(
        string $eventType,
        string $eventKey,
        string $actorRole,
        array $payload
    ): void;

    /** @param array<string,mixed> $payload @param array<string,mixed> $metadata */
    public function storeJsonArtifact(
        string $logicalKey,
        string $artifactType,
        int $revisionNo,
        array $payload,
        array $metadata
    ): void;
}

final class RepositoryAgentLoopEvidenceSink implements AgentLoopEvidenceSink
{
    private readonly HarnessPersistenceDriver $db;
    private readonly RunEventStore $events;

    public function __construct(
        private readonly int $runId,
        private readonly string $runUid,
        private readonly ?int $stepId,
        PDO|HarnessPersistenceDriver $connection,
        private readonly RunArtifactRepository $artifacts,
        private readonly ?RunBudget $budget = null,
        private readonly bool $usageAlreadyRecorded = false
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->events = new RunEventStore($this->db);
        HarnessPersistenceValue::positiveInt($runId, 'agent_evidence.run_id');
        HarnessPersistenceValue::uid($runUid, 'agent_evidence.run_uid');
        HarnessPersistenceValue::optionalPositiveInt($stepId, 'agent_evidence.step_id');
    }

    /**
     * 计算 DB 累计用量中需要补入 RunBudget 校验的增量（去重边界）。
     *
     * checkpoint 恢复的 RunBudget 可能已经包含此前 Stage 消费过、且已通过
     * RunEventStore 入账 DB 的同一笔用量（durableUsage 语义下，Tool-loop Stage
     * 的结果用量直接取自 DB 累计值，随后经 checkpoint 恢复回流进预算）。因此
     * 预算校验只消费 DB 累计用量超出预算已记基线的增量：
     *  - 已入账且已进入预算的部分不重复计量，避免 checkpoint 恢复后误判超预算；
     *  - 预算未涵盖的持久化支出（例如失败尝试已入账的用量）仍全额计量，
     *    保持按 DB 持久账本兜底的预算语义。
     *
     * @return array{int,int,string} [input_tokens, output_tokens, cost decimal]
     */
    public static function durableIncrement(RunBudget $budget, ModelUsage $spent): array
    {
        return [
            max(0, $spent->inputTokens - $budget->inputTokens),
            max(0, $spent->outputTokens - $budget->outputTokens),
            '0',
        ];
    }

    public function appendEvent(
        string $eventType,
        string $eventKey,
        string $actorRole,
        array $payload
    ): void {
        $this->db->transactional(function () use ($eventType, $eventKey, $actorRole, $payload): void {
            // Serialize legacy-key adoption with append on the same connection.
            // Deployments must drain old workers before enabling this writer:
            // old code cannot recognize a v2 key written by a new worker.
            $run = $this->db->fetchOne(
                'SELECT id, event_stream_version FROM generation_runs WHERE id=? FOR UPDATE',
                [$this->runId]
            );
            if ($run === null || (int)$run['id'] !== $this->runId) {
                throw new HarnessPersistenceException('Agent evidence Run is unavailable.');
            }
            $eventKey = $this->resolveEventKey($eventType, $eventKey, $payload, (int)($run['event_stream_version'] ?? 1) > 1);
            $this->events->append(new GenerationEventDraft(
                $this->runId,
                $this->stepId,
                null,
                $eventType,
                1,
                GenerationEventSeverity::INFO,
                $eventKey,
                'agent',
                $actorRole,
                null,
                false,
                $payload
            ), !$this->usageAlreadyRecorded
                && in_array($eventType, ['agent.model.completed', 'agent.model.failed'], true)
                && isset($payload['usage']) ? new ModelUsage(
                (int)$payload['usage']['input_tokens'],
                (int)$payload['usage']['output_tokens'],
                (int)$payload['usage']['total_tokens'],
                0
            ) : null);
        });
        if ($eventType === 'agent.model.completed' && $this->budget !== null) {
            $usage = $this->events->modelUsage($this->runId);
            // 只把 DB 累计用量超出预算已记基线的增量计入预算校验，
            // 避免 checkpoint 恢复的已消费量被同一笔 DB 用量重复扣减。
            [$input, $output, $cost] = self::durableIncrement($this->budget, $usage);
            $this->budget->consume($input, $output, $cost);
        }
    }

    /** @param array<string,mixed> $payload */
    private function resolveEventKey(
        string $eventType,
        string $eventKey,
        array $payload,
        bool $segmented = false
    ): string {
        $expectedKey = RunEvidenceAgentLoopObserver::deriveEventKey($eventType, $payload);
        if (!hash_equals($expectedKey, $eventKey)) {
            throw new HarnessPersistenceException('Agent evidence action key does not match its coordinates.');
        }
        $coordinate = RunEvidenceAgentLoopObserver::actionCoordinate($eventType, $payload);
        $rows = $segmented ? $this->events->iterateIncludingArchives($this->runId) : $this->db->fetchAll(
            "SELECT * FROM generation_events
             WHERE run_id=? AND event_type=?
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.invocation_uid'))=?
             ORDER BY event_no FOR UPDATE",
            [$this->runId, $eventType, $payload['invocation_uid']]
        );
        $matchedKey = null;
        foreach ($rows as $row) {
            $event = $row instanceof GenerationEvent ? $row : GenerationEvent::fromRow($row);
            if ($event->eventType !== $eventType) continue;
            $existingPayload = HarnessPersistenceValue::decodeJsonArray($event->payloadJson, 'payload_json');
            if (($existingPayload['invocation_uid'] ?? null) !== $payload['invocation_uid']) continue;
            if (RunEvidenceAgentLoopObserver::actionCoordinate($eventType, $existingPayload) !== $coordinate) {
                continue;
            }
            if ($matchedKey !== null) {
                throw new HarnessIdempotencyConflictException('Agent evidence action already has multiple Event identities.');
            }
            // Preserve the identity of a pre-v2 action across deployment. The
            // existing immutable comparator still rejects any payload drift;
            // neither usage nor an exception string can authorize another row.
            // MySQL can normalize 1.0 to 1 inside stored JSON, so the old
            // content-addressed hash cannot always be reconstructed. Its
            // persisted identity and payload hash are authoritative instead.
            if ($event->eventKey !== $expectedKey
                && preg_match('/^agent-loop:[a-f0-9]{32}$/D', $event->eventKey) !== 1) {
                throw new HarnessIdempotencyConflictException('Agent evidence action has an unknown Event identity.');
            }
            $matchedKey = $event->eventKey;
        }
        return $matchedKey ?? $eventKey;
    }

    public function storeJsonArtifact(
        string $logicalKey,
        string $artifactType,
        int $revisionNo,
        array $payload,
        array $metadata
    ): void {
        $canonical = HarnessPersistenceValue::canonicalMysqlJson($payload);
        $contentHash = hash('sha256', $canonical);
        $uid = substr(hash('sha256', implode("\0", [
            'agent-loop-evidence-v1',
            $this->runUid,
            $logicalKey,
            (string)$revisionNo,
            $contentHash,
        ])), 0, 32);
        $this->artifacts->storeIdempotent(GenerationArtifactDraft::inlineJson(
            $uid,
            $this->runId,
            $this->stepId,
            $artifactType,
            $logicalKey,
            $revisionNo,
            $payload,
            GenerationArtifactSensitivity::RESTRICTED,
            array_merge($metadata, [
                'evidence_schema' => 'agent_loop_v1',
                'run_uid' => $this->runUid,
            ])
        ));
    }

}

final class RunEvidenceAgentLoopObserver implements AgentLoopObserver
{
    private string $invocationUid;

    public function __construct(
        private readonly AgentLoopEvidenceSink $sink,
        private readonly string $runUid,
        private readonly string $actorRole,
        int $fenceGeneration,
        private readonly int $artifactRevision
    ) {
        $this->invocationUid = self::deriveInvocationUid(
            $runUid,
            $actorRole,
            $fenceGeneration,
            $artifactRevision
        );
    }

    public static function deriveInvocationUid(
        string $runUid,
        string $actorRole,
        int $fenceGeneration,
        int $artifactRevision
    ): string {
        HarnessPersistenceValue::uid($runUid, 'agent_observer.run_uid');
        HarnessPersistenceValue::identifier($actorRole, 'agent_observer.actor_role', 64);
        HarnessPersistenceValue::positiveInt(
            $fenceGeneration,
            'agent_observer.fence_generation'
        );
        HarnessPersistenceValue::positiveInt(
            $artifactRevision,
            'agent_observer.artifact_revision'
        );
        return substr(hash('sha256', implode("\0", [
            'agent-loop-invocation-v1',
            $runUid,
            $actorRole,
            (string)$fenceGeneration,
            (string)$artifactRevision,
        ])), 0, 32);
    }

    public function modelCompleted(array $evidence): void
    {
        $this->event('agent.model.completed', $evidence);
    }

    public function modelFailed(array $evidence): void
    {
        $this->event('agent.model.failed', $evidence);
    }

    public function toolRequested(array $evidence): void
    {
        $arguments = $evidence['arguments'] ?? null;
        if (!is_array($arguments)) {
            throw new HarnessPersistenceException(
                'Tool requested evidence is missing typed arguments.'
            );
        }
        $callId = HarnessPersistenceValue::identifier(
            $evidence['tool_call_id'] ?? null,
            'agent_observer.tool_call_id',
            96
        );
        $toolName = HarnessPersistenceValue::identifier(
            $evidence['tool_name'] ?? null,
            'agent_observer.tool_name',
            96
        );
        $this->sink->storeJsonArtifact(
            $this->toolLogicalKey($callId, 'arguments'),
            'tool.call.arguments',
            $this->artifactRevision,
            ['arguments' => $arguments],
            [
                'tool_call_id' => $callId,
                'tool_name' => $toolName,
                'actor_role' => $this->actorRole,
            ]
        );
        $event = $evidence;
        unset($event['arguments']);
        $this->event('agent.tool.requested', $event);
    }

    public function toolCompleted(array $evidence): void
    {
        $result = $evidence['result'] ?? null;
        if (!is_array($result)) {
            throw new HarnessPersistenceException(
                'Tool completed evidence is missing typed result data.'
            );
        }
        $callId = HarnessPersistenceValue::identifier(
            $evidence['tool_call_id'] ?? null,
            'agent_observer.tool_call_id',
            96
        );
        $toolName = HarnessPersistenceValue::identifier(
            $evidence['tool_name'] ?? null,
            'agent_observer.tool_name',
            96
        );
        $this->sink->storeJsonArtifact(
            $this->toolLogicalKey($callId, 'result'),
            'tool.call.result',
            $this->artifactRevision,
            ['result' => $result],
            [
                'tool_call_id' => $callId,
                'tool_name' => $toolName,
                'actor_role' => $this->actorRole,
            ]
        );
        $event = $evidence;
        unset($event['result']);
        $this->event('agent.tool.completed', $event);
    }

    public function toolFailed(array $evidence): void
    {
        $callId = HarnessPersistenceValue::identifier(
            $evidence['tool_call_id'] ?? null,
            'agent_observer.tool_call_id',
            96
        );
        $toolName = HarnessPersistenceValue::identifier(
            $evidence['tool_name'] ?? null,
            'agent_observer.tool_name',
            96
        );
        $error = [
            'exception_class' => (string)($evidence['exception_class'] ?? ''),
            'error_code' => (int)($evidence['error_code'] ?? 0),
        ];
        HarnessSensitiveDataGuard::assertSafeStructure(
            $error,
            'agent_observer.tool_error'
        );
        $this->sink->storeJsonArtifact(
            $this->toolLogicalKey($callId, 'error'),
            'tool.call.error',
            $this->artifactRevision,
            $error,
            [
                'tool_call_id' => $callId,
                'tool_name' => $toolName,
                'actor_role' => $this->actorRole,
            ]
        );
        $this->event('agent.tool.failed', $evidence);
    }

    public function loopCompleted(array $evidence): void
    {
        $this->event('agent.loop.completed', $evidence);
    }

    public function loopFailed(array $evidence): void
    {
        $this->event('agent.loop.failed', $evidence);
    }

    /** @param array<string,mixed> $evidence */
    private function event(string $eventType, array $evidence): void
    {
        $evidence['invocation_uid'] = $this->invocationUid;
        HarnessSensitiveDataGuard::assertSafeStructure($evidence, 'agent_observer.event');
        $eventKey = self::deriveEventKey($eventType, $evidence);
        $this->sink->appendEvent(
            $eventType,
            $eventKey,
            $this->actorRole,
            $evidence
        );
    }

    /** @param array<string,mixed> $evidence */
    public static function deriveEventKey(string $eventType, array $evidence): string
    {
        $invocationUid = HarnessPersistenceValue::uid(
            $evidence['invocation_uid'] ?? null,
            'agent_observer.invocation_uid'
        );
        return 'agent-loop:v2:' . substr(hash('sha256', implode("\0", [
            $invocationUid,
            $eventType,
            self::actionCoordinate($eventType, $evidence),
        ])), 0, 32);
    }

    /** @param array<string,mixed> $evidence */
    public static function actionCoordinate(string $eventType, array $evidence): string
    {
        return match ($eventType) {
            'agent.model.completed', 'agent.model.failed' => 'round:' . HarnessPersistenceValue::positiveInt(
                $evidence['round'] ?? null,
                'agent_observer.round'
            ),
            // AgentLoop rejects repeated call IDs across the entire invocation,
            // so retries retain identity even if an observed round drifts.
            'agent.tool.requested', 'agent.tool.completed', 'agent.tool.failed' => 'tool:' . HarnessPersistenceValue::identifier(
                $evidence['tool_call_id'] ?? null,
                'agent_observer.tool_call_id',
                96
            ),
            // A terminal loop observation is unique per invocation and type.
            // Its totals/round count describe the result, never its identity.
            'agent.loop.completed', 'agent.loop.failed' => 'loop',
            default => throw new HarnessPersistenceException('Unknown Agent evidence event type.'),
        };
    }

    private function toolLogicalKey(string $callId, string $kind): string
    {
        HarnessPersistenceValue::identifier(
            $kind,
            'agent_observer.tool_artifact_kind',
            32
        );
        return 'tool.call.' . $this->actorRole . '.' . substr(hash('sha256', $this->invocationUid . ':' . $callId), 0, 32) . '.' . $kind;
    }
}
