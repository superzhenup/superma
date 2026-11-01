<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

enum ModelCapability: string
{
    case TEXT = 'text';
    case STREAM = 'stream';
    case NATIVE_TOOLS = 'native_tools';
    case JSON_SCHEMA = 'json_schema';
    case CONTEXT_1M = 'context_1m';
}

enum ModelToolRequirement: string
{
    case NONE = 'none';
    case OPTIONAL = 'optional';
    case REQUIRED = 'required';
}

final class ModelCapabilityUnavailableException extends RuntimeException
{
}

final class ModelInvocationException extends RuntimeException
{
}

final class ModelBudgetEstimateUnavailableException extends RuntimeException
{
}

/** Only throw when the provider invocation certainly did not start or bill. */
final class ModelInvocationNotStartedException extends RuntimeException
{
}

/** A provider response may incur usage even when its local decoding fails. */
final class ModelPaidInvocationException extends RuntimeException
{
    public function __construct(
        public readonly ModelUsage $usage,
        public readonly string $provider,
        public readonly string $model,
        Throwable $previous
    ) {
        parent::__construct('Model response failed local validation after billable usage.', 0, $previous);
    }
}

interface ModelInvocationAccounting
{
    public function reserve(ModelAdapter $adapter, ModelRequest $request): string;
    public function received(string $reservation, ModelResponse $response): void;
    public function failed(string $reservation, Throwable $exception): void;
}

interface TokenBudgetModelAdapter extends ModelAdapter
{
    public function reservationUsage(ModelRequest $request): ModelUsage;
}

final readonly class ModelToolCall
{
    /** @var array<string,mixed> */
    public array $arguments;

    /** @param array<string,mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        array $arguments
    ) {
        HarnessPersistenceValue::identifier($id, 'model_tool_call.id', 96);
        HarnessPersistenceValue::identifier($name, 'model_tool_call.name', 96);
        HarnessSensitiveDataGuard::assertSafeStructure($arguments, 'model_tool_call.arguments');
        $this->arguments = $arguments;
    }
}

final readonly class ModelUsage
{
    /** Inert fields retained for historical Event/checkpoint compatibility. */
    public int $costMicros;
    public ?string $pricingHash;
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
        int $costMicros = 0,
        public string $source = 'provider',
        ?string $pricingHash = null
    ) {
        HarnessPersistenceValue::nonNegativeInt($inputTokens, 'model_usage.input_tokens');
        HarnessPersistenceValue::nonNegativeInt($outputTokens, 'model_usage.output_tokens');
        HarnessPersistenceValue::nonNegativeInt($totalTokens, 'model_usage.total_tokens');
        $this->costMicros = 0;
        $this->pricingHash = null;
        if (!in_array($source, ['provider', 'estimated', 'unknown'], true)) {
            throw new InvalidArgumentException('Model usage source is invalid.');
        }

        if ($totalTokens < $inputTokens + $outputTokens) {
            throw new InvalidArgumentException(
                'Model total token usage cannot be smaller than input plus output.'
            );
        }
    }
}

final readonly class ModelRequest
{
    /** @var list<array<string,mixed>> */
    public array $messages;
    /** @var list<array<string,mixed>> */
    public array $tools;
    /** @var list<ModelCapability> */
    public array $requiredCapabilities;

    /**
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>> $tools
     * @param list<ModelCapability> $requiredCapabilities
     */
    public function __construct(
        array $messages,
        public string $taskType,
        array $tools = [],
        public ModelToolRequirement $toolRequirement = ModelToolRequirement::NONE,
        array $requiredCapabilities = [ModelCapability::TEXT],
        public int $maxOutputTokens = 4096,
        public int $timeoutSeconds = 120,
        public ?string $responseSchemaHash = null,
        public ?string $operationKey = null,
        public ?string $providerOperationKey = null
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('Model request requires at least one message.');
        }
        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['role']) || !array_key_exists('content', $message)) {
                throw new InvalidArgumentException('Model request messages are malformed.');
            }
        }
        HarnessPersistenceValue::identifier($taskType, 'model_request.task_type', 64);
        if ($operationKey !== null) {
            // The durable logical operation identity for budget accounting:
            // the orchestration layer derives it from persistent coordinates
            // (run/lease/role/round or a per-fence call ordinal), never from
            // volatile payloads, so a retried business action rebuilds it.
            HarnessPersistenceValue::asciiString(
                $operationKey,
                'model_request.operation_key',
                1,
                191
            );
        }
        if ($providerOperationKey !== null) {
            // Distinguishes durable paid attempts of the same logical operation.
            HarnessPersistenceValue::asciiString(
                $providerOperationKey, 'model_request.provider_operation_key', 1, 191
            );
        }
        HarnessPersistenceValue::boundedInt(
            $maxOutputTokens,
            'model_request.max_output_tokens',
            1,
            1000000
        );
        HarnessPersistenceValue::boundedInt(
            $timeoutSeconds,
            'model_request.timeout_seconds',
            1,
            3600
        );
        if ($toolRequirement === ModelToolRequirement::NONE && $tools !== []) {
            throw new InvalidArgumentException('A tool-free Model request cannot register tools.');
        }
        if ($toolRequirement !== ModelToolRequirement::NONE && $tools === []) {
            throw new InvalidArgumentException('A tool-enabled Model request requires descriptors.');
        }
        foreach ($tools as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                throw new InvalidArgumentException('Model tool descriptors are malformed.');
            }
        }
        $caps = [];
        foreach ($requiredCapabilities as $capability) {
            if (!$capability instanceof ModelCapability) {
                throw new InvalidArgumentException('Required capabilities must be typed values.');
            }
            $caps[$capability->value] = $capability;
        }
        ksort($caps, SORT_STRING);
        if ($toolRequirement === ModelToolRequirement::REQUIRED
            && !isset($caps[ModelCapability::NATIVE_TOOLS->value])) {
            throw new InvalidArgumentException(
                'A required-tool Model request must require native_tools capability.'
            );
        }
        if ($responseSchemaHash !== null) {
            HarnessPersistenceValue::hash($responseSchemaHash, 'model_request.response_schema_hash');
        }
        $this->messages = array_values($messages);
        $this->tools = array_values($tools);
        $this->requiredCapabilities = array_values($caps);
    }

    public function withoutTools(): self
    {
        return new self(
            $this->messages,
            $this->taskType,
            [],
            ModelToolRequirement::NONE,
            array_values(array_filter(
                $this->requiredCapabilities,
                static fn (ModelCapability $capability): bool =>
                    $capability !== ModelCapability::NATIVE_TOOLS
            )),
            $this->maxOutputTokens,
            $this->timeoutSeconds,
            $this->responseSchemaHash,
            $this->operationKey,
            $this->providerOperationKey
        );
    }

    public function withProviderReservation(string $reservation): self
    {
        if ($reservation === '') throw new InvalidArgumentException('Provider reservation identity is empty.');
        return new self(
            $this->messages, $this->taskType, $this->tools, $this->toolRequirement,
            $this->requiredCapabilities, $this->maxOutputTokens, $this->timeoutSeconds,
            $this->responseSchemaHash, $this->operationKey,
            'model-reservation:v1:' . hash('sha256', $reservation)
        );
    }
}

final readonly class ModelResponse
{
    /** @var list<ModelToolCall> */
    public array $toolCalls;
    /** @var list<ModelCapability> */
    public array $actualCapabilities;
    /** @var list<array<string,mixed>> */
    public array $fallbackTrace;

    /**
     * @param list<ModelToolCall> $toolCalls
     * @param list<ModelCapability> $actualCapabilities
     * @param list<array<string,mixed>> $fallbackTrace
     */
    public function __construct(
        public string $content,
        array $toolCalls,
        public string $finishReason,
        public ModelUsage $usage,
        public string $provider,
        public string $model,
        array $actualCapabilities,
        public string $capabilityMode = 'native',
        array $fallbackTrace = []
    ) {
        HarnessPersistenceValue::identifier($finishReason, 'model_response.finish_reason', 64);
        HarnessPersistenceValue::identifier($provider, 'model_response.provider', 64);
        HarnessPersistenceValue::string($model, 'model_response.model', 1, 191);
        HarnessPersistenceValue::identifier($capabilityMode, 'model_response.capability_mode', 64);
        foreach ($toolCalls as $toolCall) {
            if (!$toolCall instanceof ModelToolCall) {
                throw new InvalidArgumentException('Model tool calls must be typed values.');
            }
        }
        $caps = [];
        foreach ($actualCapabilities as $capability) {
            if (!$capability instanceof ModelCapability) {
                throw new InvalidArgumentException('Actual Model capabilities must be typed values.');
            }
            $caps[$capability->value] = $capability;
        }
        ksort($caps, SORT_STRING);
        HarnessSensitiveDataGuard::assertSafeStructure($fallbackTrace, 'model.fallback_trace');
        $this->toolCalls = array_values($toolCalls);
        $this->actualCapabilities = array_values($caps);
        $this->fallbackTrace = array_values($fallbackTrace);
    }

    /** @param list<array<string,mixed>> $trace */
    public function withFallbackTrace(array $trace): self
    {
        return new self(
            $this->content,
            $this->toolCalls,
            $this->finishReason,
            $this->usage,
            $this->provider,
            $this->model,
            $this->actualCapabilities,
            $this->capabilityMode,
            $trace
        );
    }
}

interface ModelAdapter
{
    public function provider(): string;
    public function model(): string;
    /** @return list<ModelCapability> */
    public function capabilities(): array;
    public function invoke(ModelRequest $request): ModelResponse;
}
