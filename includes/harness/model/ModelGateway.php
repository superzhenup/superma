<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ModelContracts.php';
require_once dirname(__DIR__, 2) . '/ai/AIInvocationAccountingScope.php';

final class ModelRequestBudgetEstimate
{
    public static function usage(ModelRequest $request): ModelUsage
    {
        foreach ($request->messages as $message) {
            if (!is_string($message['content']) && $message['content'] !== null) {
                throw new ModelBudgetEstimateUnavailableException('Budget admission supports text messages only.');
            }
        }
        // UTF-8 bytes are conservative for text tokenizers. Reserve additional
        // framing for provider message/tool envelopes; include input as well as output.
        $input = strlen(HarnessPersistenceValue::canonicalJson([
            'messages' => $request->messages, 'tools' => $request->tools,
        ])) + 4096 + 256 * (count($request->messages) + count($request->tools));
        return new ModelUsage(
            $input, $request->maxOutputTokens, $input + $request->maxOutputTokens,
            0, 'estimated'
        );
    }

    public static function paidFailure(
        AIClient $client,
        string $provider,
        string $model,
        Throwable $exception
    ): Throwable {
        $raw = $client->lastUsage;
        $input = max(0, (int)($raw['prompt_tokens'] ?? 0));
        $output = max(0, (int)($raw['completion_tokens'] ?? 0));
        $total = max($input + $output, (int)($raw['total_tokens'] ?? 0));
        $output = max($output, $total - $input);
        if ($total === 0) return $exception;
        return new ModelPaidInvocationException(new ModelUsage(
            $input, $output, $total, 0,
            ($raw['source'] ?? '') === 'provider' ? 'provider' : 'estimated'
        ), $provider, $model, $exception);
    }
}

final class ModelGateway
{
    /** @var list<ModelAdapter> */
    private array $adapters;

    /** @param list<ModelAdapter> $adapters */
    private Closure $monotonicMs;

    public function __construct(
        array $adapters,
        ?callable $monotonicMs = null,
        private readonly ?ModelInvocationAccounting $accounting = null
    ) {
        $this->monotonicMs = $monotonicMs === null
            ? static fn (): int => (int)floor(hrtime(true) / 1000000)
            : Closure::fromCallable($monotonicMs);
        if ($adapters === []) {
            throw new InvalidArgumentException('ModelGateway requires at least one adapter.');
        }
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof ModelAdapter) {
                throw new InvalidArgumentException('ModelGateway adapters must implement ModelAdapter.');
            }
        }
        $this->adapters = array_values($adapters);
    }

    public function invoke(ModelRequest $request, ?callable $onResponse = null): ModelResponse
    {
        AIInvocationAccountingScope::assertHealthy();
        try {
            return $this->invokeBounded($request, $onResponse);
        } catch (Throwable $error) {
            AIInvocationAccountingScope::latch($error);
            throw $error;
        }
    }

    private function invokeBounded(ModelRequest $request, ?callable $onResponse): ModelResponse
    {
        $trace = [];
        $deadline = ($this->monotonicMs)() + $request->timeoutSeconds * 1000;
        foreach ($this->adapters as $adapter) {
            $missing = self::missingCapabilities(
                $request->requiredCapabilities,
                $adapter->capabilities()
            );
            $effective = $request;
            $capabilityMode = 'native';
            if ($request->toolRequirement !== ModelToolRequirement::NONE
                && !in_array(ModelCapability::NATIVE_TOOLS, $adapter->capabilities(), true)) {
                if ($request->toolRequirement === ModelToolRequirement::REQUIRED) {
                    $trace[] = self::trace($adapter, 'skipped', 'native_tools_unavailable');
                    continue;
                }
                $effective = $request->withoutTools();
                $capabilityMode = 'text_only_fallback';
                $missing = array_values(array_filter(
                    $missing,
                    static fn (ModelCapability $capability): bool =>
                        $capability !== ModelCapability::NATIVE_TOOLS
                ));
            }
            if ($missing !== []) {
                $trace[] = self::trace(
                    $adapter,
                    'skipped',
                    'missing:' . implode(',', array_map(
                        static fn (ModelCapability $capability): string => $capability->value,
                        $missing
                    ))
                );
                continue;
            }

            $remainingMs = $deadline - ($this->monotonicMs)();
            if ($remainingMs <= 0) {
                throw new ModelInvocationException('Model fallback deadline exhausted.');
            }
            $effective = new ModelRequest(
                $effective->messages, $effective->taskType, $effective->tools,
                $effective->toolRequirement, $effective->requiredCapabilities,
                $effective->maxOutputTokens, (int)ceil($remainingMs / 1000),
                $effective->responseSchemaHash, $effective->operationKey,
                $effective->providerOperationKey
            );
            $reservation = $this->accounting?->reserve($adapter, $effective);
            try {
                // Open reservation reentry keeps its key; a closed attempt's
                // next reservation must not replay the previous paid response.
                if ($reservation !== null) $effective = $effective->withProviderReservation($reservation);
                $response = $this->accounting !== null
                    ? AIInvocationAccountingScope::suspend(static fn (): ModelResponse => $adapter->invoke($effective))
                    : $adapter->invoke($effective);
            } catch (Throwable $exception) {
                if ($reservation !== null) {
                    $this->accounting->failed($reservation, $exception);
                }
                // Transport timeouts and decoding errors may already be billable.
                // Only an explicit pre-invocation failure permits another provider.
                if (!$exception instanceof ModelInvocationNotStartedException) {
                    if ($exception instanceof ModelPaidInvocationException
                        || $exception instanceof ModelBudgetEstimateUnavailableException) {
                        throw $exception;
                    }
                    throw new ModelInvocationException(
                        'Model invocation failed; automatic fallback is not safe.', 0, $exception
                    );
                }
                $trace[] = self::trace($adapter, 'failed', 'exception:' . $exception::class);
                continue;
            }
            // Persist paid usage before any local contract/deadline validation.
            // Accounting or observer failure must stop here, never trigger fallback.
            if ($reservation !== null) {
                $this->accounting->received($reservation, $response);
            }
            $trace[] = self::trace($adapter, 'selected', $capabilityMode);
            $response = new ModelResponse(
                $response->content, $response->toolCalls, $response->finishReason,
                $response->usage, $response->provider, $response->model,
                $response->actualCapabilities, $capabilityMode, $trace
            );
            if ($onResponse !== null) {
                $onResponse($response);
            }
            if (!hash_equals($adapter->provider(), $response->provider)
                || !hash_equals($adapter->model(), $response->model)) {
                throw new ModelInvocationException(
                    'Model adapter returned different provider/model coordinates.'
                );
            }
            $actualMissing = self::missingCapabilities(
                $effective->requiredCapabilities,
                $response->actualCapabilities
            );
            if ($actualMissing !== []) {
                throw new ModelInvocationException(
                    'Model response did not actually provide required capabilities: '
                    . implode(',', array_map(
                        static fn (ModelCapability $capability): string => $capability->value,
                        $actualMissing
                    ))
                );
            }
            if ($response->toolCalls !== []
                && !in_array(ModelCapability::NATIVE_TOOLS, $response->actualCapabilities, true)) {
                throw new ModelInvocationException(
                    'Model response returned Tool calls without actual native_tools capability.'
                );
            }
            if ($capabilityMode === 'text_only_fallback' && $response->toolCalls !== []) {
                throw new ModelInvocationException('Text-only capability fallback returned tool calls.');
            }
            return $response;
        }

        throw new ModelCapabilityUnavailableException(
            'No Model adapter completed the request with the required capabilities.'
        );
    }

    /** @param list<ModelCapability> $required @param list<ModelCapability> $actual @return list<ModelCapability> */
    private static function missingCapabilities(array $required, array $actual): array
    {
        return array_values(array_filter(
            $required,
            static fn (ModelCapability $capability): bool => !in_array($capability, $actual, true)
        ));
    }

    /** @return array<string,string> */
    private static function trace(ModelAdapter $adapter, string $status, string $reason): array
    {
        return [
            'provider' => $adapter->provider(),
            'model' => $adapter->model(),
            'status' => $status,
            'reason' => $reason,
        ];
    }
}

/** Adapter that keeps the existing multi-provider AIClient payload logic intact. */
final class LegacyAIClientModelAdapter implements TokenBudgetModelAdapter
{
    private Closure $factory;
    /** @var list<ModelCapability> */
    private array $capabilities;

    /** @param callable():AIClient $factory @param list<ModelCapability> $capabilities */
    public function __construct(
        private readonly string $providerName,
        private readonly string $modelName,
        callable $factory,
        array $capabilities = [ModelCapability::TEXT]
    ) {
        HarnessPersistenceValue::identifier($providerName, 'model_adapter.provider', 64);
        HarnessPersistenceValue::string($modelName, 'model_adapter.model', 1, 191);
        foreach ($capabilities as $capability) {
            if (!$capability instanceof ModelCapability) {
                throw new InvalidArgumentException('Adapter capabilities must be typed values.');
            }
        }
        if (in_array(ModelCapability::NATIVE_TOOLS, $capabilities, true)) {
            throw new InvalidArgumentException(
                'Legacy AIClient adapter must not claim native tool capability.'
            );
        }
        $this->factory = Closure::fromCallable($factory);
        $this->capabilities = array_values($capabilities);
    }

    public function provider(): string { return $this->providerName; }
    public function model(): string { return $this->modelName; }
    public function capabilities(): array { return $this->capabilities; }

    public function reservationUsage(ModelRequest $request): ModelUsage
    {
        return ModelRequestBudgetEstimate::usage($request);
    }

    public function invoke(ModelRequest $request): ModelResponse
    {
        if ($request->toolRequirement !== ModelToolRequirement::NONE) {
            throw new ModelCapabilityUnavailableException(
                'Legacy AIClient adapter received a native-tool request.'
            );
        }
        $client = ($this->factory)();
        if (!$client instanceof AIClient) {
            throw new ModelInvocationException('Legacy adapter factory must return AIClient.');
        }
        $client->setHardMaxTokens($request->maxOutputTokens);
        $client->setSingleProviderAttempt();
        try {
            $content = $client->withOperationKey(
                $request->providerOperationKey ?? $request->operationKey,
                static fn (): string => $client->chat($request->messages, $request->taskType, $request->timeoutSeconds)
            );
        } catch (Throwable $exception) {
            throw ModelRequestBudgetEstimate::paidFailure($client, $this->providerName, $this->modelName, $exception);
        }
        $rawUsage = is_array($client->lastUsage ?? null) ? $client->lastUsage : [];
        $inputTokens = max(0, (int)($rawUsage['prompt_tokens'] ?? 0));
        $outputTokens = max(0, (int)($rawUsage['completion_tokens'] ?? 0));
        $totalTokens = max(0, (int)($rawUsage['total_tokens'] ?? 0));
        $usageSource = (string)($rawUsage['source'] ?? 'unknown');
        if ($totalTokens < $inputTokens + $outputTokens) {
            $totalTokens = $inputTokens + $outputTokens;
        }
        $outputTokens = max($outputTokens, $totalTokens - $inputTokens);
        if ($totalTokens === 0) {
            $inputTokens = self::estimateTokens(HarnessPersistenceValue::canonicalJson(
                $request->messages
            ));
            $outputTokens = self::estimateTokens($content);
            $totalTokens = $inputTokens + $outputTokens;
            $usageSource = 'estimated';
        }
        return new ModelResponse(
            $content,
            [],
            $client->lastFinishReason ?? 'stop',
            new ModelUsage(
                $inputTokens,
                $outputTokens,
                $totalTokens,
                0,
                in_array($usageSource, ['provider', 'estimated'], true)
                    ? $usageSource
                    : 'unknown'
            ),
            $this->providerName,
            $this->modelName,
            $this->capabilities
        );
    }

    private static function estimateTokens(string $text): int
    {
        if ($text === '') return 0;
        $characters = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);
        return max(1, (int)ceil($characters * 1.15));
    }
}

/** Native Tool adapter for AIClient's observable chat-completions transport. */
final class NativeAIClientModelAdapter implements TokenBudgetModelAdapter
{
    private Closure $factory;
    /** @var list<ModelCapability> */
    private array $capabilities;

    /** @param callable():AIClient $factory @param list<ModelCapability> $capabilities */
    public function __construct(
        private readonly string $providerName,
        private readonly string $modelName,
        callable $factory,
        array $capabilities = [ModelCapability::TEXT, ModelCapability::NATIVE_TOOLS]
    ) {
        HarnessPersistenceValue::identifier($providerName, 'native_adapter.provider', 64);
        HarnessPersistenceValue::string($modelName, 'native_adapter.model', 1, 191);
        foreach ($capabilities as $capability) {
            if (!$capability instanceof ModelCapability) {
                throw new InvalidArgumentException(
                    'Native adapter capabilities must be typed values.'
                );
            }
        }
        if (!in_array(ModelCapability::TEXT, $capabilities, true)
            || !in_array(ModelCapability::NATIVE_TOOLS, $capabilities, true)) {
            throw new InvalidArgumentException(
                'Native AIClient adapter requires text and native_tools capabilities.'
            );
        }
        $this->factory = Closure::fromCallable($factory);
        $this->capabilities = array_values(array_unique($capabilities, SORT_REGULAR));
    }

    public function provider(): string { return $this->providerName; }
    public function model(): string { return $this->modelName; }
    public function capabilities(): array { return $this->capabilities; }

    public function reservationUsage(ModelRequest $request): ModelUsage
    {
        return ModelRequestBudgetEstimate::usage($request);
    }

    public function invoke(ModelRequest $request): ModelResponse
    {
        $client = ($this->factory)();
        if (!$client instanceof AIClient) {
            throw new ModelInvocationException(
                'Native adapter factory must return AIClient.'
            );
        }
        $client->setHardMaxTokens($request->maxOutputTokens);
        $client->setSingleProviderAttempt();
        try {
        if ($request->toolRequirement === ModelToolRequirement::NONE) {
            $content = $client->withOperationKey(
                $request->providerOperationKey ?? $request->operationKey,
                static fn (): string => $client->chat($request->messages, $request->taskType, $request->timeoutSeconds)
            );
            $calls = [];
            $finishReason = $client->lastFinishReason ?? 'stop';
            $rawUsage = $client->lastUsage;
        } else {
            $raw = $client->withOperationKey(
                $request->providerOperationKey ?? $request->operationKey,
                static fn (): array => $client->chatWithTools(
                    $request->messages, $request->tools, $request->taskType, $request->timeoutSeconds
                )
            );
            $content = $raw['content'];
            $finishReason = $raw['finish_reason'];
            $rawUsage = $raw['usage'];
            $calls = array_map(
                static fn (array $call): ModelToolCall => new ModelToolCall(
                    $call['id'],
                    $call['name'],
                    $call['arguments']
                ),
                $raw['tool_calls']
            );
        }

        } catch (Throwable $exception) {
            throw ModelRequestBudgetEstimate::paidFailure($client, $this->providerName, $this->modelName, $exception);
        }

        $inputTokens = max(0, (int)($rawUsage['prompt_tokens'] ?? 0));
        $outputTokens = max(0, (int)($rawUsage['completion_tokens'] ?? 0));
        $totalTokens = max(
            $inputTokens + $outputTokens,
            (int)($rawUsage['total_tokens'] ?? 0)
        );
        $outputTokens = max($outputTokens, $totalTokens - $inputTokens);
        $usageSource = (string)($rawUsage['source'] ?? 'unknown');
        if ($totalTokens === 0) {
            $inputTokens = self::estimateTokens(
                HarnessPersistenceValue::canonicalJson($request->messages)
            );
            $outputTokens = self::estimateTokens($content);
            $totalTokens = $inputTokens + $outputTokens;
            $usageSource = 'estimated';
        }

        return new ModelResponse(
            $content,
            $calls,
            $finishReason,
            new ModelUsage(
                $inputTokens,
                $outputTokens,
                $totalTokens,
                0,
                in_array($usageSource, ['provider', 'estimated'], true)
                    ? $usageSource : 'unknown'
            ),
            $this->providerName,
            $this->modelName,
            $this->capabilities
        );
    }

    private static function estimateTokens(string $text): int
    {
        if ($text === '') return 0;
        $characters = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);
        return max(1, (int)ceil($characters * 1.15));
    }
}
