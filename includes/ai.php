<?php
defined('APP_LOADED') or die('Direct access denied.');

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
        $this->apiUrl          = rtrim($cfg['api_url'], '/');
        $this->apiKey          = $cfg['api_key'];
        $this->modelName       = $cfg['model_name'];
        $this->modelId         = (string)$cfg['id'];
        $this->modelLabel      = $cfg['name'];
        $this->maxTokens       = (int)($cfg['max_tokens']   ?? 4096);
        $this->temperature     = (float)($cfg['temperature'] ?? 0.8);
        $this->thinkingEnabled = !empty($cfg['thinking_enabled']);
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
    public function chat(array $messages, string $taskType = 'creative'): string {
        // 审计优化 P2-7（2026-06-16）：慢 AI 调用监控
        $slowThreshold = defined('CFG_SLOW_AI_SEC') ? max(0, (int)CFG_SLOW_AI_SEC) : 30;
        $start = $slowThreshold > 0 ? microtime(true) : 0;

        try {
            return $this->doChat($messages, $taskType);
        } finally {
            if ($slowThreshold > 0) {
                $elapsed = microtime(true) - $start;
                if ($elapsed > $slowThreshold) {
                    error_log(sprintf('[SLOW_AI] %.1fs (threshold=%ds) model=%s task=%s',
                        $elapsed, $slowThreshold, $this->modelName ?? '?', $taskType));
                }
            }
        }
    }

    private function doChat(array $messages, string $taskType = 'creative'): string {
        $body = $this->buildPayload($messages, false, false, $taskType);
        $maxRetries = 2;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            [$code, $resp] = $this->doRequest($body);
            // 429 Too Many Requests / 503 Service Unavailable — 退避重试
            if (($code === 429 || $code === 503) && $attempt < $maxRetries) {
                $delay = 3 * pow(2, $attempt); // 3s, 6s
                error_log("AIClient::chat 429/503 retry attempt " . ($attempt + 1) . "/$maxRetries, waiting {$delay}s");
                sleep($delay);
                continue;
            }
            break;
        }
        $data = json_decode($resp, true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? safe_substr($resp, 0, 200);
            throw new RuntimeException("API Error ($code): $msg");
        }
        // 深度思考模型可能返回 reasoning_content，只取 content
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * 流式请求
     * @param string $taskType creative | structured | synopsis
     */
    public function chatStream(array $messages, callable $onChunk, string $taskType = 'creative', ?callable $onThinking = null): array {
        try {
            return $this->doStream($messages, $onChunk, true, $taskType, $onThinking);
        } catch (RuntimeException $e) {
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
    }

    private function doStream(array $messages, callable $onChunk, bool $includeUsage, string $taskType = 'creative', ?callable $onThinking = null): array {
        $body     = $this->buildPayload($messages, true, $includeUsage, $taskType);
        $url      = $this->apiUrl . '/chat/completions';
        $buffer   = '';
        $httpCode = 0;
        $rawBody  = '';
        $usage    = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $streamErr = null;
        $finishReason = null;  // 捕获流中最后一个 finish_reason
        $this->lastFinishReason = null;

        // 心跳 & 静默检测：记录最后一次收到 AI chunk 的时间
        $lastHeartbeat = time();
        $lastAiChunk   = time();  // 最后收到 AI 文字 token 的时间
        $heartbeatInterval = CFG_SSE_AI_CHECK;  // AI chunk 心跳间隔
        $silenceThreshold  = CFG_SSE_SILENCE;    // 静默检测阈值
        $lastWaitingSent  = 0;    // 上次发送等待状态的时间

        $that = $this;  // 闭包内访问 $this 的别名（兼容 PHP 7/8）
        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($body),
            CURLOPT_HTTPHEADER      => $this->headers(),
            CURLOPT_TIMEOUT         => CFG_CURL_TIMEOUT_STREAM,
            CURLOPT_CONNECTTIMEOUT  => 30,          // 连接超时 30 秒
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
                $now = time();
                if ($now - $lastHeartbeat >= $heartbeatInterval) {
                    // v1.4: 优先使用显式注入的回调，回退到 $GLOBALS 兼容模式
                    if ($that->heartbeatCallback) {
                        ($that->heartbeatCallback)();
                    } elseif (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
                        call_user_func($GLOBALS['sendHeartbeat']);
                    }
                    $lastHeartbeat = $now;
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
                    if ($payload === '[DONE]') { $onChunk('[DONE]'); continue; }
                    $decoded = json_decode($payload, true);
                    if (!$decoded) continue;
                    if (!empty($decoded['usage'])) {
                        $u = $decoded['usage'];
                        // 命中的提示词缓存 token——各厂商字段名不同：
                        //   DeepSeek:  prompt_cache_hit_tokens
                        //   OpenAI/通义/Kimi: prompt_tokens_details.cached_tokens
                        //   智谱/其他: cached_tokens
                        $cacheHit = (int)($u['prompt_cache_hit_tokens']
                            ?? $u['prompt_tokens_details']['cached_tokens']
                            ?? $u['cached_tokens']
                            ?? 0);
                        $usage = [
                            'prompt_tokens'     => (int)($u['prompt_tokens']     ?? 0),
                            'completion_tokens' => (int)($u['completion_tokens'] ?? 0),
                            'total_tokens'      => (int)($u['total_tokens']      ?? 0),
                            'cache_hit_tokens'  => $cacheHit,
                        ];
                    }
                    if (!empty($decoded['error'])) {
                        $streamErr = $decoded['error']['message'] ?? json_encode($decoded['error']);
                        return strlen($data);
                    }
                    // 捕获 finish_reason（通常出现在最后几个 chunk 中）
                    // 可能值：stop / length / content_filter / tool_calls / function_call
                    $fr = $decoded['choices'][0]['finish_reason'] ?? null;
                    if ($fr !== null && $fr !== '') {
                        $finishReason = $fr;
                    }
                    $text = $decoded['choices'][0]['delta']['content'] ?? null;
                    if ($text !== null) {
                        $lastAiChunk = time();  // 收到 AI 文字，更新静默时间
                        $that->lastChunkTime = $lastAiChunk;  // 暴露给外层检测
                        $onChunk($text);
                    }
                    // 深度思考：reasoning_content 是模型的思考过程文本
                    // 不混入正文输出，但更新静默时间（模型仍在活跃工作）
                    $reasoning = $decoded['choices'][0]['delta']['reasoning_content'] ?? null;
                    if ($reasoning !== null) {
                        $lastAiChunk = time();
                        $that->lastChunkTime = $lastAiChunk;  // 思考过程也算活跃
                        // 通过可选回调将思考过程传递给上层（用于前端可视化）
                        if ($onThinking) {
                            $onThinking($reasoning);
                        }
                    }
                }
                return strlen($data);
            });
        // 添加进度回调，在 curl 执行期间定期发送心跳和静默检测
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastHeartbeat, $heartbeatInterval, &$lastAiChunk, $silenceThreshold, &$lastWaitingSent, $that) {
                $now = time();
                if ($now - $lastHeartbeat >= $heartbeatInterval) {
                    if ($that->heartbeatCallback) {
                        ($that->heartbeatCallback)();
                    } elseif (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
                        call_user_func($GLOBALS['sendHeartbeat']);
                    }
                    $lastHeartbeat = $now;
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
                if ($now - $lastAiChunk >= $silenceThreshold * 3) {
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

        if ($this->lastFinishReason === 'canceled') {
            throw new RuntimeException('用户取消了写作');
        }

        if ($this->lastFinishReason === 'silence_timeout') {
            // v1.11.10: 静默超时改为抛异常，让 withModelFallback 触发故障转移重试，避免半截内容落库
            throw new RuntimeException('API Stream Silence Timeout: 模型超过阈值未返回数据');
        }

        if ($curlErr) throw new RuntimeException("CURL Error: $curlErr");

        if ($httpCode !== 0 && $httpCode !== 200) {
            $errData = json_decode($rawBody, true);
            $errMsg  = $errData['error']['message'] ?? safe_substr($rawBody, 0, 300);
            throw new RuntimeException("API Error ($httpCode): $errMsg");
        }
        if ($streamErr) throw new RuntimeException("API Stream Error: $streamErr");

        // 把本次流的 finish_reason 暴露给调用方（供上层判断是否被 max_tokens 截断）
        $this->lastFinishReason = $finishReason;

        return $usage;
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
     *   - siliconflow           → api.siliconflow.cn         （硅基流动）
     *   - moonshot              → api.moonshot.cn            （月之暗面Kimi）
     *   - minimax               → api.minimax.chat           （MiniMax）
     *   - 其他                  → 默认使用 DeepSeek 兼容格式（thinking 对象）
     *
     * @return string 厂商标识: deepseek | volces | aliyun | zhipu | openai | siliconflow | moonshot | minimax | other
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
     * 根据厂商返回对应的 thinking 参数名称
     * 用于 fallback 重试时识别错误信息中是否包含 thinking 相关字段名
     */
    private function getThinkingParamNames(): array {
        return match ($this->detectApiProvider()) {
            'deepseek', 'volces', 'siliconflow', 'other' => ['thinking'],
            'aliyun', 'zhipu', 'moonshot', 'minimax'     => ['enable_thinking', 'thinking_budget'],
            'openai'                                       => ['reasoning_effort'],
        };
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
        if ($this->thinkingEnabled) {
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
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
    }

    /**
     * cURL 传输策略：公网模型只允许 HTTPS；明文 HTTP 仅允许显式 loopback，
     * 使设置页内置的 Ollama 预设可用，同时不扩大远程 SSRF/降级面。
     */
    private function transportOptions(): array {
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
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];
    }

    private function doRequest(array $body): array {
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

        $maxRetries = 3;
        $lastErr    = '';

        // 阻塞式同步请求期间的心跳：质量返修 / 转折点 / 弧段摘要等后处理会调用本方法，
        // 若整段静默会被 nginx fastcgi_read_timeout（默认 60s）掐断 SSE，
        // 客户端报 ERR_INCOMPLETE_CHUNKED_ENCODING 且本批未落库。
        $that   = $this;
        $lastHb = time();

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 1) {
                // 指数退避：2s, 4s, 8s（最多 10 秒）
                $delay = pow(2, $attempt - 1);  // 2, 4, 8
                usleep(min($delay * 1000000, 10000000));
            }

            $ch = curl_init($this->apiUrl . '/chat/completions');
            $curlOptions = [
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => json_encode($body),
                CURLOPT_HTTPHEADER      => $this->headers(),
                CURLOPT_TIMEOUT         => CFG_CURL_TIMEOUT_SYNC,
                CURLOPT_CONNECTTIMEOUT  => 30,          // 连接超时 30 秒
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                // 强制 HTTP/1.1，避免 HTTP/2 在某些代理下导致连接重置
                CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
                // 定期心跳保活，避免 SSE 在阻塞等待响应期间长时间静默被代理掐断。
                // 仅在注册了心跳回调（SSE 场景）时才输出；普通 AJAX 调用无回调 → 静默无副作用。
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastHb, $that) {
                    $now = time();
                    if ($now - $lastHb >= 5) {
                        if ($that->heartbeatCallback) {
                            ($that->heartbeatCallback)();
                        } elseif (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
                            call_user_func($GLOBALS['sendHeartbeat']);
                        }
                        $lastHb = $now;
                    }
                    return 0;
                },
            ];
            $curlOptions = array_replace($curlOptions, $this->transportOptions());
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
            curl_setopt_array($ch, $curlOptions);

            // CLI（异步 worker）下 php.ini 未配 curl.cainfo 时补上 CA 证书包（同流式请求）
            if ($caBundle = self::caBundle()) curl_setopt($ch, CURLOPT_CAINFO, $caBundle);

            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if (!$err) {
                return [$code, $resp];
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
}

// 审计优化 P3-5（2026-06-16）：类外独立函数提取至 includes/ai/ModelFallback.php
// 包含 getAIClient()、getModelFallbackList()、withModelFallback()
require_once __DIR__ . '/ai/ModelFallback.php';
