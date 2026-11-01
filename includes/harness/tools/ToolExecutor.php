<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ToolExecutionBoundary.php';
require_once dirname(__DIR__) . '/model/ModelContracts.php';
require_once dirname(__DIR__) . '/side_effects/RunSideEffectRegistry.php';

final class ToolExecutor
{
    private Closure $monotonicMs;
    private ToolExecutionBoundary $executionBoundary;

    /** @param callable():int|null $monotonicMs */
    public function __construct(
        private readonly ToolRegistry $registry,
        ?callable $monotonicMs = null,
        ?ToolExecutionBoundary $executionBoundary = null,
        private readonly ?RunSideEffectRegistry $sideEffects = null
    ) {
        $this->monotonicMs = $monotonicMs === null
            ? static fn (): int => (int)floor(hrtime(true) / 1000000)
            : Closure::fromCallable($monotonicMs);

        $this->executionBoundary = $executionBoundary
            ?? ToolExecutionBoundaryFactory::forRuntime();
    }

    /**
     * @param int|null $remainingMs 调用方（AgentLoop）共享 deadline 的剩余毫秒数；
     *   传入时实际执行超时取 descriptor 超时与该剩余毫秒的较小值，保证工具
     *   执行不会越过调用方的 deadline；null 时保持既有 descriptor 超时行为。
     */
    public function execute(
        ModelToolCall $call,
        ToolExecutionContext $context,
        ?int $remainingMs = null
    ): ToolResult {
        $descriptor = $this->registry->descriptor($call->name);
        if ($descriptor->effect === ToolEffect::WRITE
            && $context->actorRole !== 'orchestrator') {
            throw new ToolPolicyViolationException(
                'WRITE tools may only be called by the Orchestrator.'
            );
        }
        $context->assertArgumentScope($call->arguments);
        $encoded = HarnessPersistenceValue::canonicalJson($call->arguments);
        if (strlen($encoded) > $descriptor->maxInputBytes) {
            throw new ToolBudgetExceededException('Tool input exceeds its byte budget.');
        }
        HarnessSensitiveDataGuard::assertSafeStructure(
            $call->arguments,
            'tool.arguments'
        );
        ToolJsonSchemaValidator::assertValid(
            $call->arguments,
            $descriptor->inputSchema
        );

        // 实际执行超时边界：descriptor 超时与会话剩余 deadline 取较小者，
        // 剩余时间不足 1 毫秒时按 1 毫秒执行，交由边界立即中断。
        $timeoutMs = $descriptor->timeoutMs;
        if ($remainingMs !== null) {
            $timeoutMs = min($timeoutMs, max(1, $remainingMs));
        }

        $operation = function () use ($call, $context, $descriptor, $timeoutMs): array {
            $result = $this->invokeValidated($call, $context, $descriptor, $timeoutMs);
            return ['data' => $result->data, 'duration_ms' => $result->durationMs, 'result_hash' => $result->resultHash];
        };
        $result = $this->sideEffects === null ? $operation() : $this->sideEffects->execute(
            new SideEffectOperationRequest(
                'tool:' . $context->actorRole . ':' . $call->id,
                'tool', $descriptor->name,
                ['run_uid' => $context->runUid, 'user_id' => $context->userId,
                    'novel_id' => $context->novelId, 'chapter_id' => $context->chapterId,
                    'actor_role' => $context->actorRole, 'version' => $descriptor->version,
                    'effect' => $descriptor->effect->value, 'arguments' => $call->arguments]
            ), $operation
        );
        return new ToolResult($call->id, $call->name, $descriptor->effect,
            $result['data'], $result['duration_ms'], $result['result_hash']);
    }

    private function invokeValidated(
        ModelToolCall $call, ToolExecutionContext $context, ToolDescriptor $descriptor, int $timeoutMs
    ): ToolResult {
        $started = $this->now();
        $handler = $this->registry->handler($call->name);
        $data = $this->executionBoundary->invoke(
            static fn (): array => $handler->execute($context, $call->arguments),
            $timeoutMs
        );
        $duration = max(0, $this->now() - $started);
        if ($duration > $timeoutMs) {
            throw new ToolBudgetExceededException('Tool execution exceeded its timeout budget.');
        }
        HarnessSensitiveDataGuard::assertSafeStructure($data, 'tool.result');
        // 审计修复（2026-09-27）：工具输出字节上限。原实现只限输入——超大
        // 结果被 AgentLoop 原文追加进 messages 历史后，随轮次累积的单次
        // 模型调用输入费用即可击穿预算（预算为事后检查）。
        $encodedResult = HarnessPersistenceValue::canonicalJson($data);
        if (strlen($encodedResult) > $descriptor->maxOutputBytes) {
            throw new ToolBudgetExceededException('Tool result exceeds its output byte budget.');
        }
        $hash = hash('sha256', $encodedResult);
        return new ToolResult(
            $call->id,
            $call->name,
            $descriptor->effect,
            $data,
            $duration,
            $hash
        );
    }

    private function now(): int
    {
        $value = ($this->monotonicMs)();
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('Tool monotonic clock must return a non-negative integer.');
        }
        return $value;
    }
}
