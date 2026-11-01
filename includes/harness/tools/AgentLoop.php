<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/StoryToolCatalog.php';
require_once dirname(__DIR__) . '/model/bootstrap.php';

enum AgentLoopRole: string
{
    case PLANNER = 'planner';
    case MEMORY_RESEARCHER = 'memory_researcher';
    case REVIEWER = 'reviewer';
}

final readonly class AgentLoopBudget
{
    public function __construct(
        public int $maxRounds = 4,
        public int $maxToolCalls = 12,
        public int $maxTokens = 30000,
        public int $maxCostMicros = 0,
        public int $timeoutSeconds = 120
    ) {
        HarnessPersistenceValue::boundedInt($maxRounds, 'agent_loop.max_rounds', 1, 6);
        HarnessPersistenceValue::boundedInt($maxToolCalls, 'agent_loop.max_tool_calls', 0, 100);
        HarnessPersistenceValue::positiveInt($maxTokens, 'agent_loop.max_tokens');
        // maxCostMicros is retained only for legacy callers and never limits a loop.
        HarnessPersistenceValue::boundedInt(
            $timeoutSeconds,
            'agent_loop.timeout_seconds',
            1,
            3600
        );
    }
}

interface AgentLoopObserver
{
    /** @param array<string,mixed> $evidence */
    public function modelFailed(array $evidence): void;
    /** @param array<string,mixed> $evidence */
    public function modelCompleted(array $evidence): void;
    /** @param array<string,mixed> $evidence */
    public function toolRequested(array $evidence): void;
    /** @param array<string,mixed> $evidence */
    public function toolCompleted(array $evidence): void;
    /** @param array<string,mixed> $evidence */
    public function toolFailed(array $evidence): void;
    /** @param array<string,mixed> $evidence */
    public function loopCompleted(array $evidence): void;
    /** @param array<string,mixed> $evidence */
    public function loopFailed(array $evidence): void;
}

final class NullAgentLoopObserver implements AgentLoopObserver
{
    public function modelFailed(array $evidence): void { unset($evidence); }
    public function modelCompleted(array $evidence): void { unset($evidence); }
    public function toolRequested(array $evidence): void { unset($evidence); }
    public function toolCompleted(array $evidence): void { unset($evidence); }
    public function toolFailed(array $evidence): void { unset($evidence); }
    public function loopCompleted(array $evidence): void { unset($evidence); }
    public function loopFailed(array $evidence): void { unset($evidence); }
}

final class CallbackAgentLoopObserver implements AgentLoopObserver
{
    private Closure $callback;

    /** @param callable(string,array<string,mixed>):void $callback */
    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function modelCompleted(array $evidence): void { $this->emit('model.completed', $evidence); }
    public function modelFailed(array $evidence): void { $this->emit('model.failed', $evidence); }
    public function toolRequested(array $evidence): void { $this->emit('tool.requested', $evidence); }
    public function toolCompleted(array $evidence): void { $this->emit('tool.completed', $evidence); }
    public function toolFailed(array $evidence): void { $this->emit('tool.failed', $evidence); }
    public function loopCompleted(array $evidence): void { $this->emit('loop.completed', $evidence); }
    public function loopFailed(array $evidence): void { $this->emit('loop.failed', $evidence); }

    /** @param array<string,mixed> $evidence */
    private function emit(string $event, array $evidence): void
    {
        HarnessSensitiveDataGuard::assertSafeStructure($evidence, 'agent_loop.evidence');
        ($this->callback)($event, $evidence);
    }
}

/**
 * Multicast decorator that renews the Run lease (and honors cancellation)
 * between Agent-loop model calls and Tool executions.
 *
 * 审计修复 P0（2026-09-27）：Chapter Tool-loop 的三段模型循环最长 360s，此前
 * 全程无任何心跳，租约（120~180s）必然过期 → 另一 worker 抢占后本 worker 的
 * 三段模型费用已入账但 checkpoint 作废，新 worker 重新执行重复扣费。
 * AgentLoop 已在每个模型响应/工具调用边界触发 observer 事件，此处借该
 * 钩子在每轮之间 beatIfDue() 续租并响应取消。
 */
final class HeartbeatAgentLoopObserver implements AgentLoopObserver
{
    public function __construct(
        private readonly AgentLoopObserver $inner,
        private readonly ?LeaseHeartbeat $heartbeat,
        private readonly ?CancellationToken $cancellation
    ) {
    }

    public function modelFailed(array $evidence): void
    {
        $this->inner->modelFailed($evidence);
        $this->tickle();
    }

    public function modelCompleted(array $evidence): void
    {
        $this->inner->modelCompleted($evidence);
        $this->tickle();
    }

    public function toolRequested(array $evidence): void
    {
        $this->inner->toolRequested($evidence);
        $this->tickle();
    }

    public function toolCompleted(array $evidence): void
    {
        $this->inner->toolCompleted($evidence);
        $this->tickle();
    }

    public function toolFailed(array $evidence): void
    {
        $this->inner->toolFailed($evidence);
        $this->tickle();
    }

    public function loopCompleted(array $evidence): void
    {
        $this->inner->loopCompleted($evidence);
        $this->tickle();
    }

    public function loopFailed(array $evidence): void
    {
        $this->inner->loopFailed($evidence);
        $this->tickle();
    }

    /** Evidence is persisted first so a cancel/lease failure never loses billing records. */
    private function tickle(): void
    {
        $this->cancellation?->throwIfCancellationRequested();
        $this->heartbeat?->beatIfDue();
    }
}

final readonly class AgentLoopResult
{
    /** @var list<ModelResponse> */
    public array $modelResponses;
    /** @var list<ToolResult> */
    public array $toolResults;

    /** @param list<ModelResponse> $modelResponses @param list<ToolResult> $toolResults */
    public function __construct(
        public string $content,
        array $modelResponses,
        array $toolResults,
        public int $rounds,
        public int $totalTokens,
        public int $totalCostMicros,
        public string $finishReason
    ) {
        $this->modelResponses = $modelResponses;
        $this->toolResults = $toolResults;
    }
}

final class AgentLoop
{
    private Closure $monotonicMs;

    /** @param callable():int|null $monotonicMs */
    public function __construct(
        private readonly ModelGateway $models,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $tools,
        private readonly AgentLoopObserver $observer = new NullAgentLoopObserver(),
        ?callable $monotonicMs = null
    ) {
        $this->monotonicMs = $monotonicMs === null
            ? static fn (): int => (int)floor(hrtime(true) / 1000000)
            : Closure::fromCallable($monotonicMs);
    }

    /** @param list<array<string,mixed>> $messages */
    public function run(
        AgentLoopRole $role,
        ToolExecutionContext $context,
        array $messages,
        AgentLoopBudget $budget
    ): AgentLoopResult {
        try {
            return $this->runBounded($role, $context, $messages, $budget);
        } catch (Throwable $exception) {
            $this->observer->loopFailed([
                'role' => $role->value,
                'exception_class' => $exception::class,
                'error_code' => (int)$exception->getCode(),
            ]);
            throw $exception;
        }
    }

    /** @param list<array<string,mixed>> $messages */
    private function runBounded(
        AgentLoopRole $role,
        ToolExecutionContext $context,
        array $messages,
        AgentLoopBudget $budget
    ): AgentLoopResult {
        if ($context->actorRole !== $role->value) {
            throw new ToolPolicyViolationException(
                'Agent loop role does not match the authenticated Tool execution role.'
            );
        }
        $started = $this->now();
        $deadline = $started + ($budget->timeoutSeconds * 1000);
        $responses = [];
        $toolResults = [];
        $seenCallIds = [];
        $totalTokens = 0;
        $totalCost = 0;
        $descriptors = $this->registry->modelDescriptors();

        for ($round = 1; $round <= $budget->maxRounds; $round++) {
            $this->assertWithinDeadline($deadline);
            $remainingTokens = $budget->maxTokens - $totalTokens;
            if ($remainingTokens <= 0) {
                throw new ToolBudgetExceededException('Agent loop exhausted its token budget.');
            }
            try {
                $response = $this->models->invoke(new ModelRequest(
                    $messages,
                    'structured',
                    $descriptors,
                    ModelToolRequirement::OPTIONAL,
                    [ModelCapability::TEXT],
                    min(8192, $remainingTokens),
                    max(1, (int)ceil(($deadline - $this->now()) / 1000)),
                    null,
                    // Durable per-round operation identity for budget
                    // accounting; the ledger binds it to the Run lease fence.
                    'agent-loop:v1:' . $role->value . ':r' . $round
                ), function (ModelResponse $response) use (
                    $round, &$totalTokens, &$totalCost
                ): void {
                    $totalTokens += $response->usage->totalTokens;
                    $totalCost = 0;
                    $this->observer->modelCompleted([
                        'round' => $round,
                        'provider' => $response->provider,
                        'model' => $response->model,
                        'finish_reason' => $response->finishReason,
                        'capability_mode' => $response->capabilityMode,
                        'tool_call_count' => count($response->toolCalls),
                        'content_hash' => hash('sha256', $response->content),
                        'usage' => [
                            'input_tokens' => $response->usage->inputTokens,
                            'output_tokens' => $response->usage->outputTokens,
                            'total_tokens' => $response->usage->totalTokens,
                            'cost_micros' => 0,
                            'source' => $response->usage->source,
                            'pricing_hash' => null,
                        ],
                        'fallback_trace' => $response->fallbackTrace,
                    ]);
                });
            } catch (Throwable $exception) {
                $evidence = [
                    'round' => $round,
                    'exception_class' => $exception::class,
                    'error_code' => (int)$exception->getCode(),
                ];
                if ($exception instanceof ModelPaidInvocationException) {
                    $evidence['provider'] = $exception->provider;
                    $evidence['model'] = $exception->model;
                    $evidence['usage'] = [
                        'input_tokens' => $exception->usage->inputTokens,
                        'output_tokens' => $exception->usage->outputTokens,
                        'total_tokens' => $exception->usage->totalTokens,
                        'cost_micros' => 0,
                        'source' => $exception->usage->source,
                        'pricing_hash' => null,
                    ];
                }
                $this->observer->modelFailed($evidence);
                throw $exception;
            }
            $responses[] = $response;
            $this->assertUsageBudget($budget, $totalTokens, $totalCost);
            $this->assertWithinDeadline($deadline);

            if ($response->toolCalls === []) {
                if ($response->finishReason === 'tool_calls') {
                    throw new ModelInvocationException(
                        'Model reported tool_calls without typed Tool calls.'
                    );
                }
                $result = new AgentLoopResult(
                    $response->content,
                    $responses,
                    $toolResults,
                    $round,
                    $totalTokens,
                    $totalCost,
                    $response->finishReason
                );
                $this->observer->loopCompleted([
                    'role' => $role->value,
                    'rounds' => $round,
                    'tool_calls' => count($toolResults),
                    'total_tokens' => $totalTokens,
                    'total_cost_micros' => $totalCost,
                    'finish_reason' => $response->finishReason,
                ]);
                return $result;
            }

            if (count($toolResults) + count($response->toolCalls) > $budget->maxToolCalls) {
                throw new ToolBudgetExceededException(
                    'Agent loop exceeded its total Tool call budget.'
                );
            }
            $assistantMessage = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => array_map(static fn (ModelToolCall $call): array => [
                    'id' => $call->id,
                    'name' => $call->name,
                    'arguments' => $call->arguments,
                ], $response->toolCalls),
            ];
            $messages[] = $assistantMessage;

            // Deterministic serial execution is the cross-platform baseline.
            // READ calls remain side-effect free and can be replaced by a
            // parallel batch executor without changing the loop contract.
            foreach ($response->toolCalls as $call) {
                if (isset($seenCallIds[$call->id])) {
                    throw new ModelInvocationException('Model repeated a Tool call ID.');
                }
                $seenCallIds[$call->id] = true;
                $descriptor = $this->registry->descriptor($call->name);
                $this->observer->toolRequested([
                    'round' => $round,
                    'tool_call_id' => $call->id,
                    'tool_name' => $call->name,
                    'tool_version' => $descriptor->version,
                    'effect' => $descriptor->effect->value,
                    'arguments_hash' => hash(
                        'sha256',
                        HarnessPersistenceValue::canonicalJson($call->arguments)
                    ),
                    'arguments' => $call->arguments,
                ]);
                try {
                    $this->assertWithinDeadline($deadline);
                    // 把 deadline 剩余毫秒传给执行器：工具实际超时不得越过
                    // 循环的共享 deadline，避免 deadline 前一刻启动的工具
                    // 仍按完整的 descriptor 超时运行。
                    $toolResult = $this->tools->execute(
                        $call,
                        $context,
                        $deadline - $this->now()
                    );
                } catch (Throwable $exception) {
                    $this->observer->toolFailed([
                        'round' => $round,
                        'tool_call_id' => $call->id,
                        'tool_name' => $call->name,
                        'tool_version' => $descriptor->version,
                        'effect' => $descriptor->effect->value,
                        'exception_class' => $exception::class,
                        'error_code' => (int)$exception->getCode(),
                    ]);
                    throw $exception;
                }
                $toolResults[] = $toolResult;
                $this->observer->toolCompleted([
                    'round' => $round,
                    'tool_call_id' => $call->id,
                    'tool_name' => $call->name,
                    'effect' => $toolResult->effect->value,
                    'result_hash' => $toolResult->resultHash,
                    'result' => $toolResult->data,
                    'duration_ms' => $toolResult->durationMs,
                ]);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'name' => $call->name,
                    'content' => HarnessPersistenceValue::canonicalJson($toolResult->data),
                ];
                $this->assertWithinDeadline($deadline);
            }
        }

        throw new ToolBudgetExceededException('Agent loop exhausted its bounded round budget.');
    }

    private function assertUsageBudget(
        AgentLoopBudget $budget,
        int $tokens,
        int $costMicros
    ): void {
        if ($tokens > $budget->maxTokens) {
            throw new ToolBudgetExceededException('Agent loop exceeded its token budget.');
        }
    }

    private function assertWithinDeadline(int $deadline): void
    {
        if ($this->now() >= $deadline) {
            throw new ToolBudgetExceededException('Agent loop exceeded its time budget.');
        }
    }

    private function now(): int
    {
        $value = ($this->monotonicMs)();
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('Agent loop monotonic clock is invalid.');
        }
        return $value;
    }
}
