<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/harness/support/HarnessRuntime.php';
require_once __DIR__ . '/ai/AIInvocationAccountingScope.php';
require_once __DIR__ . '/ai/ProviderIdempotencyPolicy.php';

/** Explicit transport cancellation; callers must never infer this from text. */
class AIRequestCanceledException extends RuntimeException {}

/** Explicit bounded-request deadline exhaustion. */
class AIRequestTimeoutException extends RuntimeException {}

/**
 * ================================================================
 * AIClient — 支持按任务类型动态调整 temperature
 * 优化点：结构化任务(大纲/摘要/简介)使用低temperature，正文写作保持高temperature
 * ================================================================
 */
class AIClient {
    public string $modelName;
    public string $modelId;
    public string $modelLabel;

    private string $apiUrl;
    private string $apiKey;
    private int    $maxTokens;
    private float  $temperature;
    private bool   $thinkingEnabled;
    private bool   $is1MContext = false;  // 是否支持 1M 上下文
    private ?string $apiProvider = null;

    private $heartbeatCallback = null;

    private $waitingCallback = null;

    private $cancelCheckCallback = null;

    /**
     * 跨实例的致命中止探测（审计修复 P0，2026-09-27）。
     * CLI worker 的心跳函数（sendHeartbeatWrite）可能运行在 cURL
     * WRITE/PROGRESS 回调内，检测到租约丢失/全局超时等致命条件时绝不能
     * 在回调内 exit/throw（跨越 libcurl 内部 C 帧触发 bailout，Windows CLI
     * 下有进程级闪退风险，且跳过全部 shutdown 清理）。worker 只登记全局
     * 致命标志，AIClient 在每次回调边界通过本探测发现标志后以
     * lastFinishReason='canceled' 安全中止传输，异常在 curl_exec 之外抛出，
     * worker 在 cURL 之外的统一安全点执行清理与退出。
     * @var Closure|null
     */
    private static $fatalAbortProbe = null;

    public static function setFatalAbortProbe(?callable $probe): void {
        self::$fatalAbortProbe = $probe === null ? null : Closure::fromCallable($probe);
    }

    private function fatalAbortRequested(): bool {
        if (self::$fatalAbortProbe === null) return false;
        try {
            return (bool)(self::$fatalAbortProbe)();
        } catch (\Throwable $e) {
            // 探测器自身故障按致命处理：宁可在 curl 外安全中止，也不冒险继续。
            error_log('AIClient fatal abort probe failed: ' . $e->getMessage());
            return true;
        }
    }

    /** Opt-in per-client stream limits; legacy callers keep their existing limits. */
    private ?array $streamTimeoutPolicy = null;
    private ?float $streamRequestDeadline = null;
    private ?array $durableRequestPolicy = null;
    private ?int $hardMaxTokens = null;
    private bool $singleProviderAttempt = false;
    private array $accountingModelConfig = [];
    private ?ProviderIdempotencyPolicy $providerIdempotencyPolicy = null;
    private ?string $requestOperationKey = null;
    private bool $operationScopeActive = false;
    private bool $operationRequestStarted = false;
    private ?string $operationRequestFingerprint = null;

    /**
     * Bind one public chat/chatWithTools/chatStream invocation, including its
     * identical-payload retries. Never leave a sticky key on a reusable client.
     */
    public function withOperationKey(?string $operationKey, callable $request): mixed {
        ProviderIdempotencyPolicy::assertOperationKey($operationKey);
        if ($this->operationScopeActive) {
            throw new LogicException('Provider operation scopes cannot be nested on the same client.');
        }
        $this->operationScopeActive = true;
        $this->requestOperationKey = $operationKey;
        try {
            return $request();
        } finally {
            $this->requestOperationKey = null;
            $this->operationRequestStarted = false;
            $this->operationRequestFingerprint = null;
            $this->operationScopeActive = false;
        }
    }

    private function beginOperationRequest(): void {
        if (!$this->operationScopeActive) return;
        if ($this->operationRequestStarted) {
            throw new LogicException('A provider operation scope allows only one public request.');
        }
        $this->operationRequestStarted = true;
    }

    private function providerIdempotencyHeaders(): array {
        return $this->providerIdempotencyPolicy?->headers(
            $this->requestEndpoint(), $this->modelName, $this->requestOperationKey
        ) ?? [];
    }

    private function assertOperationRequestBody(array $body): void {
        if ($this->providerIdempotencyHeaders() === []) return;
        $fingerprint = hash('sha256', $this->requestEndpoint() . "\0"
            . json_encode($body, JSON_THROW_ON_ERROR));
        if ($this->operationRequestFingerprint !== null
            && !hash_equals($this->operationRequestFingerprint, $fingerprint)) {
            throw new LogicException('An idempotent provider retry must preserve the original request payload.');
        }
        $this->operationRequestFingerprint = $fingerprint;
    }

    public function setHardMaxTokens(int $maxTokens): void {
        if ($maxTokens < 1) throw new InvalidArgumentException('Output token hard limit must be positive.');
        $this->hardMaxTokens = $maxTokens;
        // Optional reasoning budgets are not uniformly included in max_tokens.
        $this->thinkingEnabled = false;
    }

    public function setSingleProviderAttempt(): void {
        $this->singleProviderAttempt = true;
    }

    private function allowsAutomaticRetries(): bool {
        return !$this->singleProviderAttempt && $this->durableRequestPolicy === null
            && !AIInvocationAccountingScope::isActive();
    }

    /** One durable attempt must issue at most one provider request. */
    public function setDurableRequestPolicy(int $maxTokens, float $temperature): void {
        if ($maxTokens < 1 || !is_finite($temperature) || $temperature < 0 || $temperature > 2) {
            throw new InvalidArgumentException('Invalid durable request limits.');
        }
        $this->durableRequestPolicy = ['max_tokens'=>$maxTokens,'temperature'=>$temperature];
        $this->thinkingEnabled = false;
    }

    /**
     * 审计修复 M1（2026-08-16）：SSRF 请求侧防护。
     * 首次请求前对 apiUrl 做公网校验（A+AAAA 全量、解析失败 fail-closed），
     * 并以 CURLOPT_RESOLVE 固定解析结果，封堵"保存时校验通过、请求时 DNS
     * 改向内网"的 rebinding TOCTOU。结果按 host:port 进程内缓存，
     * 同一端点的重复请求不再重复 DNS 查询。回环端点（Ollama）跳过。
     */
    private static array $ssrfResolveCache = [];

    private function ssrfGuardResolve(): array {
        $cacheKey = $this->apiUrl;
        if (array_key_exists($cacheKey, self::$ssrfResolveCache)) {
            return self::$ssrfResolveCache[$cacheKey];
        }
        if (!function_exists('assertPublicApiEndpointResolve')) {
            require_once __DIR__ . '/helpers.php';
        }
        // 解析失败/内网地址 → RuntimeException，由 withModelFallback 捕获后
        // 自动切换下一候选模型（与模型不可用的处理路径一致）。
        $resolve = assertPublicApiEndpointResolve($this->apiUrl);
        self::$ssrfResolveCache[$cacheKey] = $resolve;
        return $resolve;
    }

    /**
     * 最近一次收到 AI chunk 的 Unix 时间戳（秒）
     * 用于 write_chapter.php 检测流式是否卡住
     */
    public int $lastChunkTime = 0;

    /**
     * 最近一次请求的结束原因（由流式回调填充）
     * - 'stop'   : 模型自然收尾
     * - 'length' : 触发 max_tokens 上限被截断 ← 字数不够的罪魁
     * - 'content_filter' : 内容安全拦截
     * - null     : 未知或非流式请求
     */
    public ?string $lastFinishReason = null;

    /**
     * Normalized usage for the most recent synchronous request. Provider
     * counters are preferred; a conservative local estimate is recorded when
     * a compatible gateway omits usage.
     *
     * @var array{prompt_tokens:int,completion_tokens:int,total_tokens:int,source:string}
     */
    public array $lastUsage = [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
        'source' => 'unknown',
    ];

    /**
     * 审计修复 P1-6（2026-08-08）：本次流是否已向业务回调投递过正文内容。
     * 参数错误（400）自动重试仅在「尚未产出任何内容」时才安全——
     * 流中途的错误消息若恰好含 "400"/参数名，重试会把内容向 onChunk 推两遍，
     * 调用方的累积缓冲出现「前半截+全量」重复正文。
     */
    private bool $streamHadOutput = false;

    /** Protocol completion is independent of a clean HTTP connection close. */
    private bool $streamSawTerminalEvent = false;

    /**
     * 按任务类型映射 temperature
     * - creative  : 正文写作，保持用户配置，创意优先
     * - structured: JSON大纲/摘要提取，低随机性，减少幻觉
     * - synopsis  : 章节简介，适中
     *
     * v11: 从系统设置读取 temperature，覆盖硬编码默认值
     */
    private const TASK_TEMPERATURES = [
        'creative'   => null,  // null = 使用用户配置值
        'structured' => null,  // null = 从系统设置读取
        'synopsis'   => null,  // null = 从系统设置读取
    ];

    public function __construct(array $cfg) {
        // Model identity only. Never pass credentials to an invocation ledger.
        $this->accountingModelConfig = array_intersect_key($cfg,array_flip([
            'id','name','model_name','api_url','max_tokens','temperature','thinking_enabled','capabilities',
            'is_default','embedding_enabled','can_embed','embedding_model_name','embedding_dim',
        ]));
        $this->apiUrl          = rtrim($cfg['api_url'], '/');
        $this->apiKey          = $cfg['api_key'];
        $this->modelName       = $cfg['model_name'];
        $this->modelId         = (string)$cfg['id'];
        $this->modelLabel      = $cfg['name'];
        $this->maxTokens       = (int)($cfg['max_tokens']   ?? 4096);
        $this->temperature     = (float)($cfg['temperature'] ?? 0.8);
        $this->thinkingEnabled = !empty($cfg['thinking_enabled']);
        // Default off. Configure only endpoints whose operator has confirmed
        // this header's semantics; provider names imply no support. An explicit
        // client setting (including []) overrides the deployment allowlist.
        $idempotencyEndpoints = array_key_exists('provider_idempotency_endpoints', $cfg)
            ? $cfg['provider_idempotency_endpoints']
            : (defined('CFG_AI_IDEMPOTENCY_ENDPOINTS') ? CFG_AI_IDEMPOTENCY_ENDPOINTS : []);
        if (!is_array($idempotencyEndpoints)) {
            throw new InvalidArgumentException('Provider idempotency endpoints must be an exact URL/header map.');
        }
        $this->providerIdempotencyPolicy = new ProviderIdempotencyPolicy($idempotencyEndpoints);
        // 1M 上下文必须由模型设置中的显式能力标记声明，不能再依赖名称约定。
        $capabilities = $cfg['capabilities'] ?? [];
        if (is_string($capabilities)) {
            $decoded = json_decode($capabilities, true);
            $capabilities = is_array($decoded) ? $decoded : [];
        }
        $this->is1MContext = is_array($capabilities)
            && in_array('context_1m', $capabilities, true);
    }

    /**
     * v1.4: 设置回调函数（替代 $GLOBALS 全局变量注入）
     *
     * @param callable|null $heartbeat 心跳回调 fn(): void
     * @param callable|null $waiting   等待回调 fn(int $elapsedSeconds): void
     */
    public function setCallbacks(?callable $heartbeat, ?callable $waiting, ?callable $cancelCheck = null): void {
        $this->heartbeatCallback = $heartbeat;
        $this->waitingCallback   = $waiting;
        $this->cancelCheckCallback = $cancelCheck;
    }

    /** A worker can bound one stream (including parameter retries) by its persisted deadline. */
    public function setStreamTimeoutPolicy(?array $policy): void {
        if ($policy === null) {
            $this->streamTimeoutPolicy = null;
            return;
        }
        $requestSeconds = (int)($policy['request_timeout_seconds'] ?? 600);
        $idleSeconds = (int)($policy['idle_timeout_seconds'] ?? 180);
        $deadline = isset($policy['deadline_unix']) ? (float)$policy['deadline_unix'] : null;
        if ($requestSeconds < 1 || $idleSeconds < 1 || ($deadline !== null && (!is_finite($deadline) || $deadline <= 0))) {
            throw new InvalidArgumentException('Invalid stream timeout policy.');
        }
        $this->streamTimeoutPolicy = [
            'request_timeout_seconds' => $requestSeconds,
            'idle_timeout_seconds' => $idleSeconds,
            'deadline_unix' => $deadline,
        ];
    }

    public function getStreamTimeoutPolicy(): ?array {
        return $this->streamTimeoutPolicy;
    }

    private function assertStreamDeadline(): void {
        if ($this->streamRequestDeadline !== null && HarnessRuntime::preciseTime() >= $this->streamRequestDeadline) {
            $this->lastFinishReason = 'deadline_timeout';
            throw new AIRequestTimeoutException('API stream request deadline exceeded.');
        }
    }

    /**
     * 读取当前 max_tokens 值（供按章节字数动态估算时作下界参考）
     */
    public function getMaxTokens(): int {
        return $this->maxTokens;
    }

    /**
     * 检测模型是否支持 1M 上下文
     * 通过 ai_models.capabilities 中的 context_1m 显式标记识别
     */
    public function is1MContext(): bool {
        return $this->is1MContext;
    }

    /**
     * 获取模型上下文上限（估算）
     * @return int 上下文 token 上限
     */
    public function getContextLimit(): int {
        return $this->is1MContext ? 1000000 : 128000;
    }

    /**
     * 临时调高 max_tokens（仅本次请求生效，不影响数据库配置）
     * 用于：章节字数较高时，自动请求更大的输出预算，避免输出被 API 截断。
     *
     * @param int $tokens 期望的 max_tokens；小于等于 0 时忽略
     */
    public function setMaxTokens(int $tokens): void {
        if ($tokens > 0) {
            $this->maxTokens = $tokens;
        }
    }

    /**
     * v2: 动态调整 temperature（高潮章节提高创意度，过渡章节降低随机性）
     */
    public function setTemperature(float $temp): void {
        $this->temperature = max(0.1, min(2.0, $temp));
    }

    public function getTemperature(): float {
        return $this->temperature;
    }

    /**
     * 解析可用的 CA 证书包路径，供 CLI（异步写作 worker）下 php.ini 未配 curl.cainfo 时使用。
     * 场景：Windows 宝塔 FastCGI php.ini 配了 curl.cainfo（网页端 HTTPS 正常），但 CLI php.ini 没配，
     * 导致 worker 调 AI(HTTPS) 时 CURLOPT_SSL_VERIFYPEER 验证失败、整章写不出（无限重试、content=0）。
     * 返回 null = php.ini 已有有效 CA，交给 curl 默认（不改变网页端既有行为）。
     */
    public static function caBundle(): ?string {
        static $resolved = false, $path = null;
        if ($resolved) return $path;
        $resolved = true;
        // 1) php.ini 已有有效 CA 配置 → 不覆盖（保持网页端行为不变）
        foreach (['curl.cainfo', 'openssl.cafile'] as $k) {
            $v = ini_get($k);
            if ($v && @is_file($v)) return $path = null;
        }
        // 2) PHP 目录附近的 cacert.pem（宝塔常自带）
        $cands = [];
        $bin = (defined('PHP_BINARY') && PHP_BINARY) ? dirname(PHP_BINARY) : '';
        if ($bin !== '') {
            $cands[] = $bin . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
            $cands[] = $bin . DIRECTORY_SEPARATOR . 'cacert.pem';
            $cands[] = $bin . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'cacert.pem';
        }
        // 3) 应用自带兜底（includes/cacert.pem，随仓库分发，保证一定有可用 CA）
        $cands[] = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
        foreach ($cands as $c) {
            if (@is_file($c)) return $path = $c;
        }
        return $path = null;
    }

    /**
     * 普通同步请求
     * M-12 修复：增加 429/503 重试（最多 2 次，指数退避 3s/6s），与 withModelFallback 协同。
     * @param string $taskType creative | structured | synopsis
     */
    public function chat(array $messages, string $taskType = 'creative', ?int $timeoutSeconds = null): string {
        $this->beginOperationRequest();
        // 审计优化 P2-7（2026-06-16）：慢 AI 调用监控
        $slowThreshold = defined('CFG_SLOW_AI_SEC') ? max(0, (int)CFG_SLOW_AI_SEC) : 30;
        $start = $slowThreshold > 0 ? HarnessRuntime::preciseTime() : 0;

        try {
            return $this->doChat($messages, $taskType, $timeoutSeconds);
        } catch (Throwable $error) {
            AIInvocationAccountingScope::latch($error);
            throw $error;
        } finally {
            if ($slowThreshold > 0) {
                $elapsed = HarnessRuntime::preciseTime() - $start;
                if ($elapsed > $slowThreshold) {
                    error_log(sprintf('[SLOW_AI] %.1fs (threshold=%ds) model=%s task=%s',
                        $elapsed, $slowThreshold, $this->modelName ?? '?', $taskType));
                }
            }
        }
    }

    /**
     * Non-streaming native Tool call transport used by the bounded Harness
     * AgentLoop. The method returns only observable response data; provider
     * reasoning fields and credentials are never exposed to the caller.
     *
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>> $tools
     * @return array{
     *   content:string,
     *   tool_calls:list<array{id:string,name:string,arguments:array<string,mixed>}>,
     *   finish_reason:string,
     *   usage:array{prompt_tokens:int,completion_tokens:int,total_tokens:int,source:string}
     * }
     */
    public function chatWithTools(
        array $messages,
        array $tools,
        string $taskType = 'structured',
        int $timeoutSeconds = 120
    ): array {
        $this->beginOperationRequest();
        try {
            return $this->doChatWithTools($messages,$tools,$taskType,$timeoutSeconds);
        } catch (Throwable $error) {
            AIInvocationAccountingScope::latch($error);
            throw $error;
        }
    }

    private function doChatWithTools(array $messages,array $tools,string $taskType,int $timeoutSeconds): array {
        if ($messages === [] || $tools === []) {
            throw new InvalidArgumentException(
                'Native Tool requests require messages and Tool descriptors.'
            );
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 3600) {
            throw new InvalidArgumentException('Native Tool request timeout is invalid.');
        }
        $provider = $this->detectApiProvider();
        $body = $this->buildPayload($messages, false, false, $taskType);
        if ($provider === 'anthropic') {
            [$normalizedMessages, $system] = self::anthropicToolMessages($messages);
            $body['messages'] = $normalizedMessages;
            if ($system === '') {
                unset($body['system']);
            } else {
                $body['system'] = $system;
            }
            $body['tools'] = array_map(
                static fn (array $tool): array => self::anthropicToolDescriptor($tool),
                $tools
            );
            $body['tool_choice'] = ['type' => 'auto'];
        } else {
            $body['messages'] = array_map(
                static fn (array $message): array => self::openAiToolMessage($message),
                $messages
            );
            $body['tools'] = array_map(
                static fn (array $tool): array => self::openAiToolDescriptor($tool),
                $tools
            );
            $body['tool_choice'] = 'auto';
        }

        $this->lastUsage = ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'source'=>'unknown'];
        [$code, $responseBody] = $this->doRequest($body, $timeoutSeconds);
        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Native Tool model returned malformed JSON.');
        }
        // Failure paths preserve provider counters only; absent usage stays unknown.
        $this->captureProviderUsageFromBody($responseBody);
        if ($code !== 200) {
            $message = $data['error']['message'] ?? safe_substr($responseBody, 0, 200);
            throw new RuntimeException("API Error ({$code}): {$message}");
        }

        $content = '';
        $calls = [];
        if ($provider === 'anthropic') {
            foreach (($data['content'] ?? []) as $block) {
                if (!is_array($block)) continue;
                if (($block['type'] ?? null) === 'text') {
                    $content .= (string)($block['text'] ?? '');
                    continue;
                }
                if (($block['type'] ?? null) === 'tool_use') {
                    $arguments = $block['input'] ?? null;
                    if (!is_array($arguments)
                        || ($arguments !== [] && array_is_list($arguments))) {
                        throw new RuntimeException(
                            'Anthropic Tool call arguments must be a JSON object.'
                        );
                    }
                    $calls[] = [
                        'id' => (string)($block['id'] ?? ''),
                        'name' => (string)($block['name'] ?? ''),
                        'arguments' => $arguments,
                    ];
                }
            }
            $finishReason = match ((string)($data['stop_reason'] ?? 'end_turn')) {
                'tool_use' => 'tool_calls',
                'max_tokens' => 'length',
                default => 'stop',
            };
        } else {
            $message = $data['choices'][0]['message'] ?? null;
            if (!is_array($message)) {
                throw new RuntimeException('Tool model response omitted its message.');
            }
            $content = is_string($message['content'] ?? null)
                ? $message['content'] : '';
            foreach (($message['tool_calls'] ?? []) as $call) {
                if (!is_array($call) || !is_array($call['function'] ?? null)) {
                    throw new RuntimeException('Tool model returned a malformed Tool call.');
                }
                $rawArguments = $call['function']['arguments'] ?? null;
                if (is_string($rawArguments)) {
                    try {
                        $arguments = json_decode(
                            $rawArguments,
                            true,
                            64,
                            JSON_THROW_ON_ERROR
                        );
                    } catch (JsonException $exception) {
                        throw new RuntimeException(
                            'Tool model returned invalid JSON arguments.',
                            0,
                            $exception
                        );
                    }
                } else {
                    $arguments = $rawArguments;
                }
                if (!is_array($arguments)
                    || ($arguments !== [] && array_is_list($arguments))) {
                    throw new RuntimeException(
                        'Tool model call arguments must be a JSON object.'
                    );
                }
                $calls[] = [
                    'id' => (string)($call['id'] ?? ''),
                    'name' => (string)($call['function']['name'] ?? ''),
                    'arguments' => $arguments,
                ];
            }
            $finishReason = (string)($data['choices'][0]['finish_reason'] ?? 'stop');
            if ($calls !== []) $finishReason = 'tool_calls';
        }

        $this->captureChatUsage($data, $messages, $content, $provider === 'anthropic');
        $this->lastFinishReason = $finishReason;
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'finish_reason' => $finishReason,
            'usage' => $this->lastUsage,
        ];
    }

    /** @param array<string,mixed> $tool @return array<string,mixed> */
    private static function openAiToolDescriptor(array $tool): array {
        $name = $tool['name'] ?? null;
        $description = $tool['description'] ?? null;
        $schema = $tool['input_schema'] ?? null;
        if (!is_string($name) || !is_string($description) || !is_array($schema)) {
            throw new InvalidArgumentException('Tool descriptor is malformed.');
        }
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $schema,
            ],
        ];
    }

    /** @param array<string,mixed> $tool @return array<string,mixed> */
    private static function anthropicToolDescriptor(array $tool): array {
        $openAi = self::openAiToolDescriptor($tool)['function'];
        return [
            'name' => $openAi['name'],
            'description' => $openAi['description'],
            'input_schema' => $openAi['parameters'],
        ];
    }

    /** @param array<string,mixed> $message @return array<string,mixed> */
    private static function openAiToolMessage(array $message): array {
        $role = $message['role'] ?? null;
        if (!is_string($role) || !array_key_exists('content', $message)) {
            throw new InvalidArgumentException('Tool request message is malformed.');
        }
        $normalized = ['role' => $role, 'content' => $message['content']];
        if ($role === 'assistant' && isset($message['tool_calls'])) {
            if (!is_array($message['tool_calls'])) {
                throw new InvalidArgumentException('Assistant Tool calls are malformed.');
            }
            $normalized['tool_calls'] = array_map(
                static function (array $call): array {
                    if (!is_string($call['id'] ?? null)
                        || !is_string($call['name'] ?? null)
                        || !is_array($call['arguments'] ?? null)) {
                        throw new InvalidArgumentException(
                            'Assistant Tool call coordinates are malformed.'
                        );
                    }
                    return [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $call['name'],
                            'arguments' => json_encode(
                                $call['arguments'],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    | JSON_THROW_ON_ERROR
                            ),
                        ],
                    ];
                },
                $message['tool_calls']
            );
        }
        foreach (['tool_call_id', 'name'] as $field) {
            if (isset($message[$field])) $normalized[$field] = $message[$field];
        }
        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @return array{list<array<string,mixed>>,string}
     */
    private static function anthropicToolMessages(array $messages): array {
        $system = [];
        $normalized = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;
            if (!is_string($role)) {
                throw new InvalidArgumentException('Tool request message role is malformed.');
            }
            if ($role === 'system' || $role === 'developer') {
                if (!is_string($content)) {
                    throw new InvalidArgumentException(
                        'Anthropic system Tool message must be text.'
                    );
                }
                $system[] = $content;
                continue;
            }
            if ($role === 'assistant') {
                $blocks = [];
                if (is_string($content) && $content !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $content];
                }
                foreach (($message['tool_calls'] ?? []) as $call) {
                    if (!is_array($call)
                        || !is_string($call['id'] ?? null)
                        || !is_string($call['name'] ?? null)
                        || !is_array($call['arguments'] ?? null)) {
                        throw new InvalidArgumentException(
                            'Anthropic assistant Tool call is malformed.'
                        );
                    }
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $call['id'],
                        'name' => $call['name'],
                        'input' => $call['arguments'],
                    ];
                }
                if ($blocks === []) $blocks[] = ['type' => 'text', 'text' => ''];
                $normalized[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }
            if ($role === 'tool') {
                $toolCallId = $message['tool_call_id'] ?? null;
                if (!is_string($toolCallId) || $toolCallId === '') {
                    throw new InvalidArgumentException(
                        'Anthropic Tool result is missing tool_call_id.'
                    );
                }
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCallId,
                    'content' => is_string($content)
                        ? $content
                        : json_encode(
                            $content,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                | JSON_THROW_ON_ERROR
                        ),
                ];
                $last = array_key_last($normalized);
                if ($last !== null && $normalized[$last]['role'] === 'user'
                    && is_array($normalized[$last]['content'])) {
                    $normalized[$last]['content'][] = $block;
                } else {
                    $normalized[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }
            if ($role !== 'user') {
                throw new InvalidArgumentException('Anthropic Tool message role is unsupported.');
            }
            $normalized[] = ['role' => 'user', 'content' => $content];
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('Anthropic Tool request has no conversation.');
        }
        return [$normalized, implode("\n\n", $system)];
    }

    private function doChat(array $messages, string $taskType = 'creative', ?int $timeoutSeconds = null): string {
        $this->lastUsage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'source' => 'unknown',
        ];
        $body = $this->buildPayload($messages, false, false, $taskType);
        $maxRetries = $this->allowsAutomaticRetries() ? 2 : 0;
        $deadline = $timeoutSeconds === null ? null : HarnessRuntime::preciseTime() + $timeoutSeconds;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->assertSyncRequestDeadline($deadline);
            [$code, $resp] = $this->doRequest($body, $timeoutSeconds, $deadline);
            $this->assertSyncRequestDeadline($deadline);
            // 429 Too Many Requests / 503 Service Unavailable — 退避重试
            if (($code === 429 || $code === 503) && $attempt < $maxRetries) {
                $delay = 3 * pow(2, $attempt); // 3s, 6s
                error_log("AIClient::chat 429/503 retry attempt " . ($attempt + 1) . "/$maxRetries, waiting {$delay}s");
                if ($deadline !== null && HarnessRuntime::preciseTime() + $delay >= $deadline) {
                    throw new AIRequestTimeoutException('Chat retry exceeds the shared deadline.');
                }
                HarnessRuntime::sleepSeconds((int)$delay);
                continue;
            }
            break;
        }
        $data = json_decode($resp, true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? safe_substr($resp, 0, 200);
            throw new RuntimeException("API Error ($code): $msg");
        }
        if ($this->detectApiProvider() === 'anthropic') {
            $parts = [];
            foreach (($data['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $parts[] = (string)$block['text'];
                }
            }
            $content = implode('', $parts);
            $this->captureChatUsage($data, $messages, $content, true);
            return $content;
        }
        // 深度思考模型可能返回 reasoning_content，只取 content
        $content = (string)($data['choices'][0]['message']['content'] ?? '');
        $this->captureChatUsage($data, $messages, $content, false);
        return $content;
    }

    private function captureChatUsage(
        array $data,
        array $messages,
        string $content,
        bool $anthropic
    ): void {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $input = max(0, (int)($anthropic
            ? ($usage['input_tokens'] ?? 0)
            : ($usage['prompt_tokens'] ?? 0)));
        $output = max(0, (int)($anthropic
            ? ($usage['output_tokens'] ?? 0)
            : ($usage['completion_tokens'] ?? 0)));
        $total = max(0, (int)($usage['total_tokens'] ?? ($input + $output)));
        $source = 'provider';
        if (!array_key_exists($anthropic ? 'input_tokens' : 'prompt_tokens',$usage)
            || !array_key_exists($anthropic ? 'output_tokens' : 'completion_tokens',$usage)) {
            $source = 'provider_partial';
        }
        if ($total === 0) {
            $input = self::estimateUsageTokens((string)json_encode(
                $messages,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            $output = self::estimateUsageTokens($content);
            $total = $input + $output;
            $source = 'estimated';
        }
        $this->lastUsage = [
            'prompt_tokens' => $input,
            'completion_tokens' => $output,
            'total_tokens' => max($total, $input + $output),
            'source' => $source,
        ];
    }

    /** Capture received paid evidence before HTTP, deadline or local validation errors. */
    private function captureProviderUsageFromBody(string $responseBody): void {
        $data = json_decode($responseBody, true);
        if (!is_array($data) || !is_array($data['usage'] ?? null)) return;
        $anthropic = $this->detectApiProvider() === 'anthropic';
        $raw = $data['usage'];
        $input = max(0, (int)($raw[$anthropic ? 'input_tokens' : 'prompt_tokens'] ?? 0));
        $output = max(0, (int)($raw[$anthropic ? 'output_tokens' : 'completion_tokens'] ?? 0));
        $total = max($input + $output, (int)($raw['total_tokens'] ?? 0));
        if ($total <= 0) return;
        $complete = array_key_exists($anthropic ? 'input_tokens' : 'prompt_tokens',$raw)
            && array_key_exists($anthropic ? 'output_tokens' : 'completion_tokens',$raw);
        $this->lastUsage = ['prompt_tokens'=>$input,'completion_tokens'=>max($output,$total-$input),
            'total_tokens'=>$total,'source'=>$complete?'provider':'provider_partial'];
    }

    private static function estimateUsageTokens(string $text): int {
        if ($text === '') return 0;
        $characters = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);
        return max(1, (int)ceil($characters * 1.15));
    }

    /**
     * 流式请求
     * @param string $taskType creative | structured | synopsis
     */
    public function chatStream(array $messages, callable $onChunk, string $taskType = 'creative', ?callable $onThinking = null): array {
        $this->beginOperationRequest();
        $previousDeadline = $this->streamRequestDeadline;
        if ($this->streamTimeoutPolicy !== null) {
            $this->streamRequestDeadline = min(
                HarnessRuntime::preciseTime() + $this->streamTimeoutPolicy['request_timeout_seconds'],
                $this->streamTimeoutPolicy['deadline_unix'] ?? PHP_FLOAT_MAX
            );
        }
        try {
        try {
            return $this->doStream($messages, $onChunk, true, $taskType, $onThinking);
        } catch (RuntimeException $e) {
            if ($e instanceof AIRequestCanceledException || $e instanceof AIRequestTimeoutException) throw $e;
            if (!$this->allowsAutomaticRetries()) throw $e;
            $msg = $e->getMessage();
            // 判断是否为"不支持某参数"导致的 400 错误
            $isParamError = str_contains($msg, '400') ||
                            str_contains($msg, 'unknown field') ||
                            str_contains($msg, 'Extra inputs') ||
                            str_contains($msg, 'stream_options');

            // 检查是否包含 thinking 相关参数名导致的不支持
            $thinkingParams = $this->getThinkingParamNames();
            foreach ($thinkingParams as $paramName) {
                if (stripos($msg, $paramName) !== false) {
                    $isParamError = true;
                    break;
                }
            }

            if ($isParamError) {
                // 审计修复 P1-6（2026-08-08）：已产出过内容的流错误不是参数错误——
                // 真正的参数错误（400 unknown field 等）一定发生在首个 chunk 之前。
                // 中途报错若误判为参数错误并重试，已推送的内容会被重推一遍造成正文重复。
                if ($this->streamHadOutput) {
                    throw $e;
                }
                // 临时关闭 thinking 后重试（不带 stream_options 和 thinking 参数）
                $origThinking = $this->thinkingEnabled;
                $this->thinkingEnabled = false;
                try {
                    return $this->doStream($messages, $onChunk, false, $taskType, $onThinking);
                } finally {
                    $this->thinkingEnabled = $origThinking;
                }
            }
            throw $e;
        }
        } catch (Throwable $error) {
            AIInvocationAccountingScope::latch($error);
            throw $error;
        } finally {
            $this->streamRequestDeadline = $previousDeadline;
        }
    }

    private function doStream(array $messages, callable $onChunk, bool $includeUsage, string $taskType = 'creative', ?callable $onThinking = null): array {
        AIInvocationAccountingScope::assertHealthy();
        $this->assertStreamDeadline();
        $body = $this->buildPayload($messages, true, $includeUsage, $taskType);
        return AIInvocationAccountingScope::performRequest($this->accountingModelConfig,$body,
            fn(): array => $this->doStreamTransport($body,$onChunk,$includeUsage,$taskType,$onThinking),
            fn(): array => $this->lastUsage);
    }

    private function doStreamTransport(array $body,callable $onChunk,bool $includeUsage,string $taskType,?callable $onThinking): array {
        $this->assertOperationRequestBody($body);
        $url      = $this->requestEndpoint();
        $buffer   = '';
        $httpCode = 0;
        $rawBody  = '';
        $usage    = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $this->lastUsage = $usage + ['source'=>'unknown'];
        $streamErr = null;
        $finishReason = null;  // 捕获流中最后一个 finish_reason
        $this->lastFinishReason = null;
        $this->streamHadOutput = false;  // P1-6：每次流开始时重置产出标记
        $this->streamSawTerminalEvent = false;

        $this->assertEndpointPolicy();
        HarnessRuntime::fault(HarnessFaultPoints::AI_STREAM_BEFORE_TRANSPORT, [
            'model' => $this->modelName,
            'task_type' => $taskType,
        ]);
        $fake = HarnessRuntime::aiFake();
        if ($fake !== null) {
            return $this->doFakeStream(
                $fake,
                $body,
                $includeUsage,
                $taskType,
                $onChunk,
                $onThinking
            );
        }

        // 心跳 & 静默检测：记录最后一次收到 AI chunk 的时间
        $lastHeartbeat = HarnessRuntime::unixTime();
        $lastAiChunk   = HarnessRuntime::unixTime();  // 最后收到 AI 文字 token 的时间
        $heartbeatInterval = CFG_SSE_AI_CHECK;  // AI chunk 心跳间隔
        $silenceThreshold  = CFG_SSE_SILENCE;    // 静默检测阈值
        $idleTimeout = $this->streamTimeoutPolicy['idle_timeout_seconds'] ?? ($silenceThreshold * 3);
        $requestTimeout = $this->streamRequestDeadline === null
            ? CFG_CURL_TIMEOUT_STREAM
            : max(1, (int)ceil($this->streamRequestDeadline - HarnessRuntime::preciseTime()));
        $lastWaitingSent  = 0;    // 上次发送等待状态的时间

        $that = $this;  // 闭包内访问 $this 的别名（兼容 PHP 7/8）
        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($body),
            CURLOPT_HTTPHEADER      => $this->headers(),
            CURLOPT_TIMEOUT         => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT  => min(30, $requestTimeout),
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            // 强制 HTTP/1.1，避免 HTTP/2 在某些代理下导致连接重置
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        ];
        $curlOptions = array_replace($curlOptions, $this->transportOptions());
        // TCP Keepalive — 防止防火墙/代理杀掉长时间空闲连接
        // CURLOPT_TCP_KEEPALIVE 在 PHP 8.2+ 才正式定义，低版本需守卫
        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            $curlOptions[CURLOPT_TCP_KEEPALIVE]   = 1;
            $curlOptions[CURLOPT_TCP_KEEPIDLE]    = 60;
            $curlOptions[CURLOPT_TCP_KEEPINTVL]   = 15;
        }
        // 审计修复 M1（2026-08-16）：固定 DNS 解析结果，封堵 rebinding（详见 ssrfGuardResolve）
        $ssrfResolve = $this->ssrfGuardResolve();
        if ($ssrfResolve) {
            $curlOptions[CURLOPT_RESOLVE] = $ssrfResolve;
        }
        curl_setopt_array($ch, $curlOptions);
        // 需要在 curl_setopt_array 之后单独设置回调（它们不能放入数组）
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$httpCode) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
                    $httpCode = (int)$m[1];
                }
                return strlen($header);
            });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (
                &$buffer, &$usage, &$httpCode, &$rawBody, &$streamErr, &$finishReason, $onChunk, $onThinking, &$lastHeartbeat, $heartbeatInterval, &$lastAiChunk, $that
            ) {
                // 在收到数据时检查并发送心跳
                if ($that->streamRequestDeadline !== null && HarnessRuntime::preciseTime() >= $that->streamRequestDeadline) {
                    $that->lastFinishReason = 'deadline_timeout';
                    return 0;
                }
                $now = HarnessRuntime::unixTime();
                if ($now - $lastHeartbeat >= $heartbeatInterval) {
                    // v1.4: 优先使用显式注入的回调，回退到 $GLOBALS 兼容模式
                    if ($that->heartbeatCallback) {
                        ($that->heartbeatCallback)();
                    } elseif (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
                        call_user_func($GLOBALS['sendHeartbeat']);
                    }
                    $lastHeartbeat = $now;
                }
                // 致命中止（审计修复 P0，2026-09-27）：心跳可能刚登记了 worker
                // 级致命标志（租约丢失/全局超时）。返回 0 让 curl 以
                // CURLE_WRITE_ERROR 安全中止，异常在 curl_exec 之外抛出。
                if ($that->fatalAbortRequested()) {
                    $that->lastFinishReason = 'canceled';
                    return 0;
                }

                if ($httpCode && $httpCode !== 200) {
                    $rawBody .= $data;
                    return strlen($data);
                }

                $buffer .= $data;
                $lines   = explode("\n", $buffer);
                $buffer  = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    // 审计修复（2026-07-19 H-低14）：SSE 规范中 'data:' 后空格可选，
                    // 部分兼容网关/自研代理输出 data:{...}（无空格），原严格匹配
                    // str_starts_with('data: ') 会静默丢弃所有 chunk 导致流超时。
                    if (!str_starts_with($line, 'data:')) continue;
                    $payload = ltrim(substr($line, 5), ' ');
                    // 审计修复 P0-1（2026-08-08）：[DONE] 是 SSE 协议哨兵，在源头拦截，
                    // 绝不推给业务回调。此前依赖 28 个调用方逐一过滤，4 处漏过滤
                    // （polish/compress/RewriteAgent/IterativeRefinement）导致 '[DONE]'
                    // 字面量被拼入正文并落库。前端的结束信号由各端点自身的
                    // sseDone()/SseChannel::done() 显式发送，不依赖转发此哨兵。
                    if ($payload === '[DONE]') {
                        $that->streamSawTerminalEvent = true;
                        continue;
                    }
                    $decoded = json_decode($payload, true);
                    if (!$decoded) continue;
                    $that->consumeStreamEvent(
                        $decoded,
                        $usage,
                        $streamErr,
                        $finishReason,
                        $onChunk,
                        $onThinking,
                        $lastAiChunk
                    );
                    HarnessRuntime::fault(HarnessFaultPoints::AI_STREAM_AFTER_EVENT, [
                        'model' => $that->modelName,
                    ]);
                    if ($streamErr !== null) return strlen($data);
                }
                return strlen($data);
            });
        // 添加进度回调，在 curl 执行期间定期发送心跳和静默检测
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastHeartbeat, $heartbeatInterval, &$lastAiChunk, $silenceThreshold, $idleTimeout, &$lastWaitingSent, $that) {
                if ($that->streamRequestDeadline !== null && HarnessRuntime::preciseTime() >= $that->streamRequestDeadline) {
                    $that->lastFinishReason = 'deadline_timeout';
                    return 1;
                }
                $now = HarnessRuntime::unixTime();
                if ($now - $lastHeartbeat >= $heartbeatInterval) {
                    if ($that->heartbeatCallback) {
                        ($that->heartbeatCallback)();
                    } elseif (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
                        call_user_func($GLOBALS['sendHeartbeat']);
                    }
                    $lastHeartbeat = $now;
                }
                // 致命中止（审计修复 P0，2026-09-27）：同 WRITEFUNCTION。
                if ($that->fatalAbortRequested()) {
                    $that->lastFinishReason = 'canceled';
                    return 1;
                }
                if ($now - $lastAiChunk >= $silenceThreshold && $now - $lastWaitingSent >= $silenceThreshold) {
                    $elapsed = $now - $lastAiChunk;
                    if ($that->waitingCallback) {
                        ($that->waitingCallback)($elapsed);
                    } elseif (isset($GLOBALS['sendWaiting']) && is_callable($GLOBALS['sendWaiting'])) {
                        call_user_func($GLOBALS['sendWaiting'], $elapsed);
                    }
                    $lastWaitingSent = $now;
                }
                if ($that->cancelCheckCallback && ($that->cancelCheckCallback)()) {
                    $that->lastFinishReason = 'canceled';
                    return 1;
                }
                if ($now - $lastAiChunk >= $idleTimeout) {
                    $that->lastFinishReason = 'silence_timeout';
                    return 1;
                }
                return 0;
            });

        // CLI（异步 worker）下 php.ini 未配 curl.cainfo 时补上 CA 证书包，否则 HTTPS 证书验证失败、整章写不出
        if ($caBundle = self::caBundle()) curl_setopt($ch, CURLOPT_CAINFO, $caBundle);

        curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($this->lastFinishReason === 'deadline_timeout') {
            throw new AIRequestTimeoutException('API stream request deadline exceeded.');
        }
        $this->assertStreamDeadline();

        // P2-9 修复（2026-08-09）：流结束时冲刷残余 buffer。
        // WRITEFUNCTION 按 "\n" 分割行，最后一行若不以 "\n" 结尾会残留在 $buffer 中。
        // 部分厂商的最终 usage/finish_reason chunk 恰好是无尾换行的最后一行，
        // 不冲刷会导致 usage 统计和 finish_reason 丢失（后者影响 max_tokens 截断判断），
        // 极端情况下最后几个正文字 token 也会丢。此处复刻 WRITEFUNCTION 的行处理逻辑。
        if ($buffer !== '' && $httpCode !== 0 && $httpCode === 200 && $streamErr === null) {
            $line = trim($buffer);
            if (str_starts_with($line, 'data:')) {
                $payload = ltrim(substr($line, 5), ' ');
                if ($payload === '[DONE]') {
                    $this->streamSawTerminalEvent = true;
                } else {
                    $decoded = json_decode($payload, true);
                    if ($decoded) {
                        $that->consumeStreamEvent(
                            $decoded,
                            $usage,
                            $streamErr,
                            $finishReason,
                            $onChunk,
                            $onThinking,
                            $lastAiChunk
                        );
                        HarnessRuntime::fault(HarnessFaultPoints::AI_STREAM_AFTER_EVENT, [
                            'model' => $that->modelName,
                        ]);
                    }
                }
            }
            $buffer = '';
        }

        if ($this->lastFinishReason === 'canceled') {
            throw new AIRequestCanceledException('用户取消了写作');
        }

        if ($this->lastFinishReason === 'silence_timeout') {
            // v1.11.10: 静默超时改为抛异常，让 withModelFallback 触发故障转移重试，避免半截内容落库
            throw new RuntimeException('API Stream Silence Timeout: 模型超过阈值未返回数据');
        }

        if ($curlErr) throw new RuntimeException("CURL Error: $curlErr");

        if ($httpCode !== 0 && $httpCode !== 200) {
            $this->captureProviderUsageFromBody($rawBody);
            $errData = json_decode($rawBody, true);
            $errMsg  = $errData['error']['message'] ?? safe_substr($rawBody, 0, 300);
            throw new RuntimeException("API Error ($httpCode): $errMsg");
        }
        if ($streamErr !== null) throw new RuntimeException("API Stream Error: $streamErr");

        // 把本次流的 finish_reason 暴露给调用方（供上层判断是否被 max_tokens 截断）
        $this->assertStreamCompleted($finishReason);

        return $usage;
    }

    /**
     * Execute a deterministic test stream without DNS, cURL or credentials.
     * The fake supplies decoded provider events, which still pass through the
     * same protocol normalization and callbacks as a real stream.
     */
    private function doFakeStream(
        HarnessAiFake $fake,
        array $body,
        bool $includeUsage,
        string $taskType,
        callable $onChunk,
        ?callable $onThinking
    ): array {
        $result = $fake->stream([
            'endpoint' => $this->requestEndpoint(),
            'idempotency_headers' => $this->providerIdempotencyHeaders(),
            'model' => $this->modelName,
            'provider' => $this->detectApiProvider(),
            'body' => $body,
            'include_usage' => $includeUsage,
            'task_type' => $taskType,
        ]);
        if (!array_key_exists('status_code', $result)
            || !is_int($result['status_code'])
            || !isset($result['events'])
            || !is_array($result['events'])) {
            throw new RuntimeException('Harness AI fake returned an invalid stream response.');
        }
        if ($result['status_code'] !== 200) {
            if (is_string($result['body'] ?? null)) $this->captureProviderUsageFromBody($result['body']);
            $error = isset($result['error']) && is_string($result['error'])
                ? $result['error']
                : 'fake transport failure';
            throw new RuntimeException("API Error ({$result['status_code']}): {$error}");
        }

        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $streamErr = null;
        $finishReason = null;
        $lastAiChunk = HarnessRuntime::unixTime();
        foreach ($result['events'] as $index => $event) {
            $this->assertStreamDeadline();
            if (!is_array($event)) {
                throw new RuntimeException('Harness AI fake stream event must be an array.');
            }
            if ($this->fatalAbortRequested() ||
                ($this->cancelCheckCallback && ($this->cancelCheckCallback)())) {
                $this->lastFinishReason = 'canceled';
                throw new AIRequestCanceledException('用户取消了写作');
            }
            $this->consumeStreamEvent(
                $event,
                $usage,
                $streamErr,
                $finishReason,
                $onChunk,
                $onThinking,
                $lastAiChunk
            );
            HarnessRuntime::fault(HarnessFaultPoints::AI_STREAM_AFTER_EVENT, [
                'model' => $this->modelName,
                'event_index' => $index,
            ]);
            if ($streamErr !== null) {
                throw new RuntimeException("API Stream Error: {$streamErr}");
            }
        }

        $this->assertStreamDeadline();
        $this->assertStreamCompleted($finishReason);
        return $usage;
    }

    private function assertStreamCompleted(?string $finishReason): void {
        $this->lastFinishReason = $finishReason;
        if (!$this->streamSawTerminalEvent) {
            $this->lastFinishReason = 'incomplete';
            throw new RuntimeException('API stream ended without a completion event; partial content must be discarded.');
        }
    }

    /**
     * 根据 API URL 识别厂商，返回厂商标识符。
     * 用于按厂商分派不同的 thinking 参数格式。
     *
     * 识别规则（按域名匹配）：
     *   - deepseek              → api.deepseek.com
     *   - volces / ark          → ark.cn-beijing.volces.com  （火山方舟）
     *   - aliyun / dashscope    → dashscope.aliyuncs.com     （阿里云百炼）
     *   - zhipu / bigmodel      → open.bigmodel.cn          （智谱GLM）
     *   - openai                → api.openai.com / api.openai.ai
     *   - anthropic             → api.anthropic.com
     *   - siliconflow           → api.siliconflow.cn         （硅基流动）
     *   - moonshot              → api.moonshot.cn            （月之暗面Kimi）
     *   - minimax               → api.minimax.chat           （MiniMax）
     *   - 其他                  → 默认使用 DeepSeek 兼容格式（thinking 对象）
     *
     * @return string 厂商标识: deepseek | volces | aliyun | zhipu | openai | anthropic | siliconflow | moonshot | minimax | other
     */
    private function detectApiProvider(): string {
        if ($this->apiProvider !== null) return $this->apiProvider;

        $host = strtolower(parse_url($this->apiUrl, PHP_URL_HOST) ?: '');

        // 按优先级匹配（更具体的域名优先）
        if (str_contains($host, 'ark.cn-beijing.volces.com') || str_contains($host, 'volces.com')) {
            $this->apiProvider = 'volces';
        } elseif (str_contains($host, 'dashscope') || str_contains($host, 'aliyuncs.com')) {
            $this->apiProvider = 'aliyun';
        } elseif (str_contains($host, 'deepseek.com')) {
            $this->apiProvider = 'deepseek';
        } elseif (str_contains($host, 'bigmodel.cn') || str_contains($host, 'zhipu')) {
            $this->apiProvider = 'zhipu';
        } elseif (str_contains($host, 'anthropic.com')) {
            $this->apiProvider = 'anthropic';
        } elseif (str_contains($host, 'openai.com') || str_contains($host, 'openai.ai')) {
            $this->apiProvider = 'openai';
        } elseif (str_contains($host, 'siliconflow')) {
            $this->apiProvider = 'siliconflow';
        } elseif (str_contains($host, 'moonshot')) {
            $this->apiProvider = 'moonshot';
        } elseif (str_contains($host, 'minimax')) {
            $this->apiProvider = 'minimax';
        } else {
            $this->apiProvider = 'other';
        }
        return $this->apiProvider;
    }

    /**
     * Stable, non-secret provider coordinate for Run provenance.
     *
     * This deliberately exposes only the normalized provider label already
     * used by the protocol adapter.  It never returns the configured endpoint
     * or API credential.
     */
    public function providerName(): string {
        return $this->detectApiProvider();
    }

    /**
     * 根据厂商返回对应的 thinking 参数名称
     * 用于 fallback 重试时识别错误信息中是否包含 thinking 相关字段名
     */
    private function getThinkingParamNames(): array {
        return match ($this->detectApiProvider()) {
            'deepseek', 'volces', 'siliconflow', 'other' => ['thinking'],
            'aliyun', 'zhipu', 'moonshot', 'minimax'     => ['enable_thinking', 'thinking_budget'],
            'openai'                                       => ['reasoning_effort'],
            'anthropic'                                    => ['thinking', 'budget_tokens'],
        };
    }

    /**
     * 将 OpenAI 兼容流和 Anthropic Messages 流归一化后投递给业务层。
     */
    private function consumeStreamEvent(
        array $decoded,
        array &$usage,
        ?string &$streamErr,
        ?string &$finishReason,
        callable $onChunk,
        ?callable $onThinking,
        int &$lastAiChunk
    ): void {
        if (!empty($decoded['error'])) {
            // Some gateways attach billable counters to a terminal error event.
            if (is_array($decoded['usage'] ?? null)) {
                $this->captureChatUsage($decoded, [], '', $this->detectApiProvider()==='anthropic');
                if ($this->lastUsage['source']==='provider') {
                    $this->lastUsage['source']='provider_partial';
                    $usage=array_intersect_key($this->lastUsage,array_flip(['prompt_tokens','completion_tokens','total_tokens']));
                }
            }
            $streamErr = $decoded['error']['message'] ?? json_encode($decoded['error']);
            return;
        }

        if ($this->detectApiProvider() === 'anthropic') {
            $eventType = (string)($decoded['type'] ?? '');
            if ($eventType === 'message_stop') $this->streamSawTerminalEvent = true;
            $inputUsage = $decoded['message']['usage'] ?? null;
            $outputUsage = $decoded['usage'] ?? null;
            if (is_array($inputUsage)) {
                $usage['prompt_tokens'] = (int)($inputUsage['input_tokens'] ?? 0);
                $usage['cache_hit_tokens'] = (int)($inputUsage['cache_read_input_tokens'] ?? 0);
            }
            if (is_array($outputUsage) && array_key_exists('output_tokens', $outputUsage)) {
                $usage['completion_tokens'] = (int)$outputUsage['output_tokens'];
            }
            $usage['total_tokens'] = (int)$usage['prompt_tokens'] + (int)$usage['completion_tokens'];

            $stopReason = $decoded['delta']['stop_reason'] ?? null;
            if (is_string($stopReason) && $stopReason !== '') {
                $this->streamSawTerminalEvent = true;
                $finishReason = match ($stopReason) {
                    'max_tokens' => 'length',
                    'tool_use' => 'tool_calls',
                    default => 'stop',
                };
            }

            $deltaType = (string)($decoded['delta']['type'] ?? '');
            $text = null;
            $reasoning = null;
            if ($eventType === 'content_block_start' && ($decoded['content_block']['type'] ?? '') === 'text') {
                $text = (string)($decoded['content_block']['text'] ?? '');
            } elseif ($eventType === 'content_block_delta' && $deltaType === 'text_delta') {
                $text = (string)($decoded['delta']['text'] ?? '');
            } elseif ($eventType === 'content_block_delta' && $deltaType === 'thinking_delta') {
                $reasoning = (string)($decoded['delta']['thinking'] ?? '');
            }
        } else {
            if (!empty($decoded['usage'])) {
                $u = $decoded['usage'];
                $usage = [
                    'prompt_tokens' => (int)($u['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int)($u['completion_tokens'] ?? 0),
                    'total_tokens' => (int)($u['total_tokens'] ?? 0),
                    'cache_hit_tokens' => (int)($u['prompt_cache_hit_tokens']
                        ?? $u['prompt_tokens_details']['cached_tokens']
                        ?? $u['cached_tokens']
                        ?? 0),
                ];
                $usage['completion_tokens']=max($usage['completion_tokens'],$usage['total_tokens']-$usage['prompt_tokens']);
            }
            $fr = $decoded['choices'][0]['finish_reason'] ?? null;
            if (is_string($fr) && trim($fr) !== '') {
                $finishReason = $fr;
                $this->streamSawTerminalEvent = true;
            }
            $text = $decoded['choices'][0]['delta']['content'] ?? null;
            $reasoning = $decoded['choices'][0]['delta']['reasoning_content'] ?? null;
        }

        // Capture provider counters before callbacks, which may cancel or throw.
        if (isset($decoded['usage']) || isset($decoded['message']['usage'])) {
            $complete = $this->detectApiProvider() !== 'anthropic'
                || (isset($decoded['usage']['output_tokens']) && !empty($decoded['delta']['stop_reason']));
            $this->lastUsage = $usage + ['source'=>$complete?'provider':'provider_partial'];
        }
        if ($text !== null) {
            $lastAiChunk = HarnessRuntime::unixTime();
            $this->lastChunkTime = $lastAiChunk;
            if ($text !== '') $this->streamHadOutput = true;
            $onChunk($text);
        }
        if ($reasoning !== null) {
            $lastAiChunk = HarnessRuntime::unixTime();
            $this->lastChunkTime = $lastAiChunk;
            if ($onThinking) $onThinking($reasoning);
        }
    }

    private function buildPayload(array $messages, bool $stream, bool $includeUsage = false, string $taskType = 'creative'): array {
        $temp = self::TASK_TEMPERATURES[$taskType] ?? null;
        $mt = $this->maxTokens;

        if ($temp === null) {
            // v11: 根据任务类型从系统设置读取 temperature 和 max_tokens
            if ($taskType === 'outline') {
                // v41 去套路化：章节细纲需要创意多样性，用比 structured(0.3) 更高的温度。
                // 与摘要/抽取的 structured 档分开，互不影响。JSON 结构靠 prompt 约束保证。
                $temp = (float)getSystemSetting('ws_temperature_outline_gen', 0.75, 'float');
                $mt = max($mt, (int)getSystemSetting('ws_max_tokens_outline', 4096, 'int'));
            } elseif ($taskType === 'structured') {
                $temp = (float)getSystemSetting('ws_temperature_outline', 0.3, 'float');
                $mt = max($mt, (int)getSystemSetting('ws_max_tokens_outline', 4096, 'int'));
            } elseif ($taskType === 'synopsis') {
                // 简介取大纲和正文之间的中间值
                $tOutline = (float)getSystemSetting('ws_temperature_outline', 0.3, 'float');
                $tChapter = (float)getSystemSetting('ws_temperature_chapter', 0.8, 'float');
                $temp = round(($tOutline + $tChapter) / 2, 2);
                $mt = max($mt, (int)getSystemSetting('ws_max_tokens_outline', 4096, 'int'));
            } elseif ($taskType === 'title') {
                // 标题极短：关键约束是「必须在上游代理(nginx/CF)超时前返回」，否则浏览器拿到空响应。
                // 用【小】上限把单次生成压到几秒：普通模型快速出标题；推理模型也快速结束（额度耗尽→空内容，
                // 交由调用方 fallback 换下一个模型）。这里直接赋值（不向 4096/8192 floor 抬高）。
                $temp = (float)getSystemSetting('ws_temperature_outline', 0.3, 'float');
                $mt = max(64, (int)getSystemSetting('ws_max_tokens_title', 512, 'int'));
            } else {
                $temp = (float)getSystemSetting('ws_temperature_chapter', $this->temperature, 'float');
                $mt = max($mt, (int)getSystemSetting('ws_max_tokens_chapter', 8192, 'int'));
            }
        }

        if ($this->durableRequestPolicy !== null) {
            $mt = min($mt, $this->durableRequestPolicy['max_tokens']);
            $temp = $this->durableRequestPolicy['temperature'];
        }
        if ($this->hardMaxTokens !== null) $mt = min($mt, $this->hardMaxTokens);
        if (AIInvocationAccountingScope::isActive()) $mt = min($mt,AIInvocationAccountingScope::MAX_OUTPUT_TOKENS);
        $p = [
            'model'       => $this->modelName,
            'messages'    => $messages,
            'max_tokens'  => $mt,
            'temperature' => $temp,
            'stream'      => $stream,
        ];
        if ($stream && $includeUsage) {
            $p['stream_options'] = ['include_usage' => true];
        }

        if ($this->detectApiProvider() === 'anthropic') {
            // Anthropic Messages API 的 temperature 合法区间是 0..1。
            $p['temperature'] = max(0.0, min(1.0, (float)$p['temperature']));
            $systemParts = [];
            $anthropicMessages = [];
            foreach ($messages as $message) {
                $role = (string)($message['role'] ?? 'user');
                $content = $message['content'] ?? '';
                if ($role === 'system' || $role === 'developer') {
                    if (is_string($content)) $systemParts[] = $content;
                    continue;
                }
                $anthropicMessages[] = [
                    'role' => $role === 'assistant' ? 'assistant' : 'user',
                    'content' => $content,
                ];
            }
            $p['messages'] = $anthropicMessages;
            if ($systemParts !== []) $p['system'] = implode("\n\n", $systemParts);
            unset($p['stream_options']);
        }

        // ---- 深度思考(Thinking)参数 ----
        // 仅当模型开启 thinking_enabled 时添加。
        // 根据不同 API 厂商使用对应的参数格式：
        //
        // | 厂商             | 参数格式                                                    |
        // |------------------|-------------------------------------------------------------|
        // | DeepSeek         | thinking: {type: "enabled"}                                 |
        // | 火山方舟          | thinking: {type: "enabled"}                                 |
        // | 阿里云百炼        | enable_thinking: true, thinking_budget: N                   |
        // | 智谱GLM          | enable_thinking: true                                       |
        // | OpenAI           | reasoning_effort: "high"                                    |
        // | 硅基流动          | thinking: {type: "enabled"}                                 |
        // | 月之暗面Kimi      | enable_thinking: true                                       |
        // | 其他(默认)        | thinking: {type: "enabled"}                                 |
        //
        // 注：
        //   - DeepSeek R1 (deepseek-reasoner) 模型自带思考，无需此参数也能工作
        //   - 思考模式下 temperature/top_p 等参数对 DeepSeek 不生效（API限制）
        //   - reasoning_content 在流式响应的 delta 中返回，不混入正文输出
        if ($this->thinkingEnabled && !AIInvocationAccountingScope::isActive()) {
            $provider = $this->detectApiProvider();

            switch ($provider) {
                case 'deepseek':
                case 'volces':
                case 'siliconflow':
                case 'other':
                    // DeepSeek 兼容格式：thinking 对象
                    // 火山方舟、硅基流动等也兼容此格式
                    $p['thinking'] = ['type' => 'enabled'];
                    break;

                case 'aliyun':
                    // 阿里云百炼（DashScope）格式
                    // enable_thinking: 开关; thinking_budget: 最大推理Token数
                    $p['enable_thinking'] = true;
                    $p['thinking_budget'] = (int)max(1024, $this->maxTokens);
                    break;

                case 'zhipu':
                    // 智谱GLM格式
                    $p['enable_thinking'] = true;
                    break;

                case 'openai':
                    // OpenAI o1/o3 系列：reasoning_effort
                    // 值: "low" | "medium" | "high"
                    $p['reasoning_effort'] = 'high';
                    break;

                case 'anthropic':
                    // Anthropic 扩展思考要求预算小于 max_tokens，且不能同时发送 temperature。
                    if ($mt >= 2048) {
                        $p['thinking'] = [
                            'type' => 'enabled',
                            'budget_tokens' => max(1024, min($mt - 1, intdiv($mt, 2))),
                        ];
                        unset($p['temperature']);
                    }
                    break;

                case 'moonshot':
                    // 月之暗面 Kimi K2 格式
                    $p['enable_thinking'] = true;
                    break;

                case 'minimax':
                    // MiniMax 格式
                    $p['enable_thinking'] = true;
                    break;
            }
        }

        return $p;
    }

    private function headers(): array {
        if ($this->detectApiProvider() === 'anthropic') {
            return array_merge([
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ], $this->providerIdempotencyHeaders());
        }
        return array_merge([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ], $this->providerIdempotencyHeaders());
    }

    private function requestEndpoint(): string {
        $suffix = $this->detectApiProvider() === 'anthropic' ? '/messages' : '/chat/completions';
        return str_ends_with($this->apiUrl, $suffix) ? $this->apiUrl : $this->apiUrl . $suffix;
    }

    /**
     * Pure endpoint policy shared by real and fake transports.
     *
     * This intentionally performs no DNS or network I/O: a Harness fake may
     * skip those side effects, but it must still reject malformed schemes and
     * explicit private/reserved IP targets exactly like production transport.
     */
    private function assertEndpointPolicy(): void {
        if (!function_exists('normalizeApiUrlHost')
            || !function_exists('apiUrlHostIsLocal')
            || !function_exists('ipIsDisallowedForApi')) {
            require_once __DIR__ . '/helpers.php';
        }

        $parts = parse_url($this->apiUrl);
        if (!is_array($parts)) {
            throw new RuntimeException('AI API 地址格式无效。');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = normalizeApiUrlHost((string)($parts['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('AI API 地址缺少有效主机名。');
        }

        $isLoopback = apiUrlHostIsLocal($host);
        if ($scheme === 'http' && $isLoopback) {
            return;
        }
        if ($scheme !== 'https') {
            throw new RuntimeException('AI API 仅允许 HTTPS；本机 localhost/127.0.0.1/::1 可使用 HTTP。');
        }

        if (!$isLoopback
            && @inet_pton($host) !== false
            && ipIsDisallowedForApi($host)) {
            throw new RuntimeException('不允许连接内网或保留地址。');
        }
    }

    /**
     * cURL 传输策略：公网模型只允许 HTTPS；明文 HTTP 仅允许显式 loopback，
     * 使设置页内置的 Ollama 预设可用，同时不扩大远程 SSRF/降级面。
     */
    private function transportOptions(): array {
        $this->assertEndpointPolicy();
        $parts = parse_url($this->apiUrl);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme === 'http' && $isLoopback) {
            return [
                CURLOPT_FOLLOWLOCATION  => false,
                CURLOPT_MAXREDIRS       => 0,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
            ];
        }
        if ($scheme !== 'https') {
            throw new RuntimeException('AI API 仅允许 HTTPS；本机 localhost/127.0.0.1/::1 可使用 HTTP。');
        }

        return [
            // 禁止自动重定向：CURLOPT_RESOLVE 只固定首个主机，自动跟随到第二主机
            // 会绕过已完成的 DNS/私网校验。模型 API 应配置最终 HTTPS 端点。
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_MAXREDIRS       => 0,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];
    }

    private function doRequest(array $body, ?int $timeoutSeconds = null, ?float $deadline = null): array {
        AIInvocationAccountingScope::assertHealthy();
        return AIInvocationAccountingScope::performRequest($this->accountingModelConfig,$body,function () use ($body,$timeoutSeconds,$deadline): array {
            $result = $this->doRequestTransport($body,$timeoutSeconds,$deadline);
            if (AIInvocationAccountingScope::isActive() && $result[0] !== 200) {
                $data = json_decode($result[1],true);
                $message = $data['error']['message'] ?? safe_substr($result[1],0,200);
                throw new RuntimeException('API Error ('.$result[0].'): '.$message);
            }
            return $result;
        },fn(): array => $this->lastUsage);
    }

    private function doRequestTransport(array $body, ?int $timeoutSeconds = null, ?float $deadline = null): array {
        $this->assertOperationRequestBody($body);
        // HTTP status retries and transport retries share the caller's deadline.
        // Direct callers (e.g. native tools) establish it only on their first request.
        if ($deadline === null && $timeoutSeconds !== null) {
            $deadline = HarnessRuntime::preciseTime() + $timeoutSeconds;
        }
        $this->assertEndpointPolicy();
        $this->assertSyncRequestDeadline($deadline);
        HarnessRuntime::fault(HarnessFaultPoints::AI_SYNC_BEFORE_TRANSPORT, [
            'model' => $this->modelName,
        ]);
        $fake = HarnessRuntime::aiFake();
        if ($fake !== null) {
            $result = $fake->complete([
                'endpoint' => $this->requestEndpoint(),
                'idempotency_headers' => $this->providerIdempotencyHeaders(),
                'model' => $this->modelName,
                'provider' => $this->detectApiProvider(),
                'body' => $body,
            ]);
            if (!array_key_exists('status_code', $result)
                || !is_int($result['status_code'])
                || !array_key_exists('body', $result)
                || !is_string($result['body'])) {
                throw new RuntimeException('Harness AI fake returned an invalid completion response.');
            }
            $this->captureProviderUsageFromBody($result['body']);
            $this->assertSyncRequestDeadline($deadline);
            HarnessRuntime::fault(HarnessFaultPoints::AI_SYNC_AFTER_TRANSPORT, [
                'model' => $this->modelName,
                'status_code' => $result['status_code'],
            ]);
            return [$result['status_code'], $result['body']];
        }

        // 可重试的 cURL 错误（网络层 transient 错误）
        $retryableErrors = [
            'Connection reset by peer',
            'Connection refused',
            'Connection timed out',
            'Timeout was reached',
            'Recv failure',
            'Send failure',
            'Failed to connect',
            'SSL connection timeout',
            'SSL read',
            'SSL_write',
            'Empty reply from server',
            'transfer closed',
            'OpenSSL SSL_read',
        ];

        $maxRetries = $this->allowsAutomaticRetries() ? 3 : 1;
        $lastErr    = '';

        // 阻塞式同步请求期间的心跳：质量返修 / 转折点 / 弧段摘要等后处理会调用本方法，
        // 若整段静默会被 nginx fastcgi_read_timeout（默认 60s）掐断 SSE，
        // 客户端报 ERR_INCOMPLETE_CHUNKED_ENCODING 且本批未落库。
        $that   = $this;
        $lastHb = HarnessRuntime::unixTime();

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 1) {
                // 指数退避：2s, 4s, 8s（最多 10 秒）
                $delayMicros = (int)min(
                    pow(2, $attempt - 1) * 1000000,
                    10000000
                );
                $remainingMicros = $this->syncRequestRemainingMicros($deadline);
                if ($remainingMicros !== null && $delayMicros >= $remainingMicros) {
                    throw new AIRequestTimeoutException(
                        'Native Tool request deadline exhausted before retry.'
                    );
                }
                HarnessRuntime::sleepMicroseconds($delayMicros);
            }

            $remainingMillis = $this->syncRequestRemainingMillis($deadline);

            $ch = curl_init($this->requestEndpoint());
            $curlOptions = [
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => json_encode($body),
                CURLOPT_HTTPHEADER      => $this->headers(),
                CURLOPT_TIMEOUT         => $remainingMillis === null
                    ? CFG_CURL_TIMEOUT_SYNC
                    : min(
                        CFG_CURL_TIMEOUT_SYNC,
                        max(1, (int)ceil($remainingMillis / 1000))
                    ),
                CURLOPT_CONNECTTIMEOUT  => $remainingMillis === null
                    ? 30
                    : min(30, max(1, (int)ceil($remainingMillis / 1000))),
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                // 强制 HTTP/1.1，避免 HTTP/2 在某些代理下导致连接重置
                CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
                // 定期心跳保活，避免 SSE 在阻塞等待响应期间长时间静默被代理掐断。
                // 仅在注册了心跳回调（SSE 场景）时才输出；普通 AJAX 调用无回调 → 静默无副作用。
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastHb, $that, $deadline) {
                    if ($deadline !== null
                        && HarnessRuntime::preciseTime() >= $deadline) {
                        return 1;
                    }
                    $now = HarnessRuntime::unixTime();
                    if ($now - $lastHb >= 5) {
                        if ($that->heartbeatCallback) {
                            ($that->heartbeatCallback)();
                        } elseif (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
                            call_user_func($GLOBALS['sendHeartbeat']);
                        }
                        $lastHb = $now;
                    }
                    // 致命中止（审计修复 P0，2026-09-27）：阻塞式同步请求期间
                    // worker 登记致命标志时，同样在 cURL 回调内只返回非 0 安全
                    // 中止，绝不在回调内 exit。
                    if ($that->fatalAbortRequested()) {
                        return 1;
                    }
                    return 0;
                },
            ];
            $curlOptions = array_replace($curlOptions, $this->transportOptions());
            if ($remainingMillis !== null) {
                // Apply the caller's remaining total budget after transport
                // policy options so no endpoint-specific setting can widen it.
                if (defined('CURLOPT_TIMEOUT_MS')) {
                    unset($curlOptions[CURLOPT_TIMEOUT]);
                    $curlOptions[CURLOPT_TIMEOUT_MS] = min(
                        CFG_CURL_TIMEOUT_SYNC * 1000,
                        $remainingMillis
                    );
                }
                if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
                    unset($curlOptions[CURLOPT_CONNECTTIMEOUT]);
                    $curlOptions[CURLOPT_CONNECTTIMEOUT_MS] = min(
                        30000,
                        $remainingMillis
                    );
                }
            }
            // CURLOPT_* 是整数键，不能使用数组展开语法，否则 PHP 会重编号键。
            if (defined('CURLOPT_TCP_KEEPALIVE')) {
                $curlOptions[CURLOPT_TCP_KEEPALIVE] = 1;
            }
            if (defined('CURLOPT_TCP_KEEPIDLE')) {
                $curlOptions[CURLOPT_TCP_KEEPIDLE] = 60;
            }
            if (defined('CURLOPT_TCP_KEEPINTVL')) {
                $curlOptions[CURLOPT_TCP_KEEPINTVL] = 15;
            }
            // 审计修复 M1（2026-08-16）：固定 DNS 解析结果，封堵 rebinding（详见 ssrfGuardResolve）
            $ssrfResolve = $this->ssrfGuardResolve();
            if ($ssrfResolve) {
                $curlOptions[CURLOPT_RESOLVE] = $ssrfResolve;
            }
            curl_setopt_array($ch, $curlOptions);

            // CLI（异步 worker）下 php.ini 未配 curl.cainfo 时补上 CA 证书包（同流式请求）
            if ($caBundle = self::caBundle()) curl_setopt($ch, CURLOPT_CAINFO, $caBundle);

            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if (is_string($resp)) $this->captureProviderUsageFromBody($resp);
            $this->assertSyncRequestDeadline($deadline);

            if (!$err) {
                HarnessRuntime::fault(HarnessFaultPoints::AI_SYNC_AFTER_TRANSPORT, [
                    'model' => $this->modelName,
                    'status_code' => $code,
                ]);
                return [$code, $resp];
            }

            // 致命中止（审计修复 P0，2026-09-27）：progress 回调探测到 worker
            // 致命标志（租约丢失/全局超时）主动中止的传输，绝不能按可重试
            // curl 错误处理——立即失败上抛，由 cURL 之外的安全点统一退出。
            if ($this->fatalAbortRequested()) {
                throw new AIRequestCanceledException('用户取消了写作');
            }

            $lastErr = $err;

            // 检查是否为可重试的错误
            $isRetryable = false;
            foreach ($retryableErrors as $pattern) {
                if (stripos($err, $pattern) !== false) {
                    $isRetryable = true;
                    break;
                }
            }

            if (!$isRetryable || $attempt >= $maxRetries) {
                break;
            }
        }

        throw new RuntimeException("CURL Error: $lastErr");
    }

    private function assertSyncRequestDeadline(?float $deadline): void {
        if ($deadline !== null && HarnessRuntime::preciseTime() >= $deadline) {
            throw new AIRequestTimeoutException(
                'Native Tool request exceeded its total deadline.'
            );
        }
    }

    private function syncRequestRemainingMillis(?float $deadline): ?int {
        $remainingMicros = $this->syncRequestRemainingMicros($deadline);
        return $remainingMicros === null
            ? null
            : max(1, (int)floor($remainingMicros / 1000));
    }

    private function syncRequestRemainingMicros(?float $deadline): ?int {
        if ($deadline === null) return null;
        $remaining = $deadline - HarnessRuntime::preciseTime();
        if ($remaining <= 0.0) {
            throw new AIRequestTimeoutException(
                'Native Tool request exceeded its total deadline.'
            );
        }
        return max(1, (int)floor($remaining * 1000000));
    }
}

// 审计优化 P3-5（2026-06-16）：类外独立函数提取至 includes/ai/ModelFallback.php
// 包含 getAIClient()、getModelFallbackList()、withModelFallback()
require_once __DIR__ . '/ai/ModelFallback.php';
