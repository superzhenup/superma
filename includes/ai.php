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
    }

    /**
     * 读取当前 max_tokens 值（供按章节字数动态估算时作下界参考）
     */
    public function getMaxTokens(): int {
        return $this->maxTokens;
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
     * 普通同步请求
     * @param string $taskType creative | structured | synopsis
     */
    public function chat(array $messages, string $taskType = 'creative'): string {
        $body = $this->buildPayload($messages, false, false, $taskType);
        [$code, $resp] = $this->doRequest($body);
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
    public function chatStream(array $messages, callable $onChunk, string $taskType = 'creative'): array {
        try {
            return $this->doStream($messages, $onChunk, true, $taskType);
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
                    return $this->doStream($messages, $onChunk, false, $taskType);
                } finally {
                    $this->thinkingEnabled = $origThinking;
                }
            }
            throw $e;
        }
    }

    private function doStream(array $messages, callable $onChunk, bool $includeUsage, string $taskType = 'creative'): array {
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
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_TIMEOUT        => CFG_CURL_TIMEOUT_STREAM,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$httpCode) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
                    $httpCode = (int)$m[1];
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (
                &$buffer, &$usage, &$httpCode, &$rawBody, &$streamErr, &$finishReason, $onChunk, &$lastHeartbeat, $heartbeatInterval, &$lastAiChunk, $that
            ) {
                // 在收到数据时检查并发送心跳
                $now = time();
                if ($now - $lastHeartbeat >= $heartbeatInterval) {
                    // 调用全局心跳函数（如果存在）
                    if (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
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
                    if (!str_starts_with($line, 'data: ')) continue;
                    $payload = substr($line, 6);
                    if ($payload === '[DONE]') { $onChunk('[DONE]'); continue; }
                    $decoded = json_decode($payload, true);
                    if (!$decoded) continue;
                    if (!empty($decoded['usage'])) {
                        $usage = [
                            'prompt_tokens'     => (int)($decoded['usage']['prompt_tokens']     ?? 0),
                            'completion_tokens' => (int)($decoded['usage']['completion_tokens'] ?? 0),
                            'total_tokens'      => (int)($decoded['usage']['total_tokens']      ?? 0),
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
                        // 不调用 $onChunk，思考过程不输出到正文
                    }
                }
                return strlen($data);
            },
            // 添加进度回调，在 curl 执行期间定期发送心跳和静默检测
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastHeartbeat, $heartbeatInterval, &$lastAiChunk, $silenceThreshold, &$lastWaitingSent, $that) {
                $now = time();
                // 每5秒发送一次心跳
                if ($now - $lastHeartbeat >= $heartbeatInterval) {
                    if (isset($GLOBALS['sendHeartbeat']) && is_callable($GLOBALS['sendHeartbeat'])) {
                        call_user_func($GLOBALS['sendHeartbeat']);
                    }
                    $lastHeartbeat = $now;
                }
                // 静默检测：超过阈值无 AI 文字输出时，发送等待状态
                if ($now - $lastAiChunk >= $silenceThreshold && $now - $lastWaitingSent >= $silenceThreshold) {
                    $elapsed = $now - $lastAiChunk;
                    if (isset($GLOBALS['sendWaiting']) && is_callable($GLOBALS['sendWaiting'])) {
                        call_user_func($GLOBALS['sendWaiting'], $elapsed);
                    }
                    $lastWaitingSent = $now;
                }
                return 0; // 返回0继续传输
            },
        ]);

        curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) throw new RuntimeException("CURL Error: $curlErr");

        if ($httpCode && $httpCode !== 200) {
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
        static $cache = null;
        if ($cache !== null) return $cache;

        $host = strtolower(parse_url($this->apiUrl, PHP_URL_HOST) ?: '');

        // 按优先级匹配（更具体的域名优先）
        if (str_contains($host, 'ark.cn-beijing.volces.com') || str_contains($host, 'volces.com')) {
            $cache = 'volces';
        } elseif (str_contains($host, 'dashscope') || str_contains($host, 'aliyuncs.com')) {
            $cache = 'aliyun';
        } elseif (str_contains($host, 'deepseek.com')) {
            $cache = 'deepseek';
        } elseif (str_contains($host, 'bigmodel.cn') || str_contains($host, 'zhipu')) {
            $cache = 'zhipu';
        } elseif (str_contains($host, 'openai.com') || str_contains($host, 'openai.ai')) {
            $cache = 'openai';
        } elseif (str_contains($host, 'siliconflow')) {
            $cache = 'siliconflow';
        } elseif (str_contains($host, 'moonshot')) {
            $cache = 'moonshot';
        } elseif (str_contains($host, 'minimax')) {
            $cache = 'minimax';
        } else {
            $cache = 'other';
        }
        return $cache;
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
            if ($taskType === 'structured') {
                $temp = (float)getSystemSetting('ws_temperature_outline', 0.3, 'float');
                $mt = max($mt, (int)getSystemSetting('ws_max_tokens_outline', 4096, 'int'));
            } elseif ($taskType === 'synopsis') {
                // 简介取大纲和正文之间的中间值
                $tOutline = (float)getSystemSetting('ws_temperature_outline', 0.3, 'float');
                $tChapter = (float)getSystemSetting('ws_temperature_chapter', 0.8, 'float');
                $temp = round(($tOutline + $tChapter) / 2, 2);
                $mt = max($mt, (int)getSystemSetting('ws_max_tokens_outline', 4096, 'int'));
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

    private function doRequest(array $body): array {
        $ch = curl_init($this->apiUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_TIMEOUT        => CFG_CURL_TIMEOUT_SYNC,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("CURL Error: $err");
        return [$code, $resp];
    }
}

// ================================================================
// 获取单个模型客户端
// ================================================================
function getAIClient(?int $modelId = null): AIClient {
    $model = $modelId
        ? DB::fetch('SELECT * FROM ai_models WHERE id=?', [$modelId])
        : (DB::fetch('SELECT * FROM ai_models WHERE is_default=1 LIMIT 1')
           ?: DB::fetch('SELECT * FROM ai_models ORDER BY id LIMIT 1'));
    if (!$model) throw new RuntimeException('请先在【模型设置】中添加至少一个AI模型。');
    return new AIClient($model);
}

// ================================================================
// 获取 fallback 模型列表
// ================================================================
function getModelFallbackList(?int $preferredModelId = null): array {
    $all = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');
    if (empty($all)) {
        throw new RuntimeException('请先在【模型设置】中添加至少一个AI模型。');
    }
    if (!$preferredModelId) return $all;

    usort($all, function ($a, $b) use ($preferredModelId) {
        if ((int)$a['id'] === $preferredModelId) return -1;
        if ((int)$b['id'] === $preferredModelId) return 1;
        return (int)$b['is_default'] - (int)$a['is_default'];
    });
    return $all;
}

// ================================================================
// 通用 fallback 执行器
// ================================================================
function withModelFallback(
    ?int     $preferredModelId,
    callable $callback,
    ?callable $onSwitch = null
): mixed {
    $models    = getModelFallbackList($preferredModelId);
    $lastError = null;

    foreach ($models as $idx => $modelCfg) {
        $ai = new AIClient($modelCfg);
        try {
            return $callback($ai);
        } catch (RuntimeException $e) {
            $lastError = $e;
            if ($idx + 1 < count($models) && $onSwitch !== null) {
                $nextAi = new AIClient($models[$idx + 1]);
                $onSwitch($nextAi, $e->getMessage());
            }
        }
    }

    throw $lastError;
}