<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

enum ToolEffect: string
{
    case READ = 'read';
    case PROPOSE = 'propose';
    case WRITE = 'write';
}

final class ToolNotRegisteredException extends RuntimeException
{
}

final class ToolSchemaViolationException extends RuntimeException
{
}

final class ToolPolicyViolationException extends RuntimeException
{
}

class ToolBudgetExceededException extends RuntimeException
{
}

final class ToolExecutionTimedOutException extends ToolBudgetExceededException
{
}

final class ToolTimeoutUnavailableException extends ToolBudgetExceededException
{
}

final readonly class ToolDescriptor
{
    /** @var array<string,mixed> */
    public array $inputSchema;

    /** @param array<string,mixed> $inputSchema */
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        array $inputSchema,
        public ToolEffect $effect,
        public int $timeoutMs = 5000,
        public int $maxInputBytes = 16384,
        // 审计修复（2026-09-27）：工具输出字节上限。原实现只限输入
        // （maxInputBytes），输出无界——超大结果被 AgentLoop 原文追加进
        // messages 历史，随轮次累积后单次模型调用的输入费用即可击穿
        // 预算（预算还是事后检查）。默认 64KB 与 READ 工具的最大返回
        // （上章结尾/近章大纲）量级匹配。
        public int $maxOutputBytes = 65536
    ) {
        HarnessPersistenceValue::identifier($name, 'tool.name', 96);
        HarnessPersistenceValue::identifier($version, 'tool.version', 32);
        HarnessPersistenceValue::string($description, 'tool.description', 1, 500);
        HarnessPersistenceValue::boundedInt($timeoutMs, 'tool.timeout_ms', 1, 120000);
        HarnessPersistenceValue::boundedInt(
            $maxInputBytes,
            'tool.max_input_bytes',
            2,
            1048576
        );
        HarnessPersistenceValue::boundedInt(
            $maxOutputBytes,
            'tool.max_output_bytes',
            2,
            1048576
        );
        if (($inputSchema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('Tool input schema root must be an object.');
        }
        $this->inputSchema = $inputSchema;
    }

    /** @return array<string,mixed> */
    public function modelDescriptor(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
            'effect' => $this->effect->value,
            'version' => $this->version,
        ];
    }
}

final readonly class ToolExecutionContext
{
    public function __construct(
        public int $userId,
        public int $novelId,
        public ?int $chapterId,
        public string $runUid,
        public string $actorRole
    ) {
        HarnessPersistenceValue::positiveInt($userId, 'tool_context.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'tool_context.novel_id');
        HarnessPersistenceValue::optionalPositiveInt($chapterId, 'tool_context.chapter_id');
        HarnessPersistenceValue::uid($runUid, 'tool_context.run_uid');
        HarnessPersistenceValue::identifier($actorRole, 'tool_context.actor_role', 64);
    }

    /** @param array<string,mixed> $arguments */
    public function assertArgumentScope(array $arguments): void
    {
        foreach ([
            'user_id' => $this->userId,
            'novel_id' => $this->novelId,
            'chapter_id' => $this->chapterId,
        ] as $field => $expected) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            if ($expected === null || (int)$arguments[$field] !== $expected) {
                throw new ToolPolicyViolationException(
                    "Tool argument {$field} escapes the authenticated execution scope."
                );
            }
        }
    }
}

final readonly class ToolResult
{
    /** @var array<string,mixed> */
    public array $data;

    /** @param array<string,mixed> $data */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public ToolEffect $effect,
        array $data,
        public int $durationMs,
        public string $resultHash
    ) {
        HarnessPersistenceValue::identifier($toolCallId, 'tool_result.call_id', 96);
        HarnessPersistenceValue::identifier($toolName, 'tool_result.name', 96);
        HarnessPersistenceValue::nonNegativeInt($durationMs, 'tool_result.duration_ms');
        HarnessPersistenceValue::hash($resultHash, 'tool_result.result_hash');
        HarnessSensitiveDataGuard::assertSafeStructure($data, 'tool_result.data');
        $canonical = HarnessPersistenceValue::canonicalJson($data);
        if (!hash_equals(hash('sha256', $canonical), $resultHash)) {
            throw new InvalidArgumentException('Tool result hash does not match canonical data.');
        }
        $this->data = $data;
    }
}

interface ToolHandler
{
    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function execute(ToolExecutionContext $context, array $arguments): array;
}
