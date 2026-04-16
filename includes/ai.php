<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * ================================================================
 * AIClient — 支持按任务类型动态调整 temperature
 * 优化点：结构化任务(大纲/摘要/简介)使用低temperature，正文写作保持高temperature
 * ================================================================
 */
class AIClient {
    public readonly string $modelName;
    public readonly string $modelId;
    public readonly string $modelLabel;

    private string $apiUrl;
    private string $apiKey;
    private int    $maxTokens;
    private float  $temperature;

    /**
     * 按任务类型映射 temperature
     * - creative  : 正文写作，保持用户配置，创意优先
     * - structured: JSON大纲/摘要提取，低随机性，减少幻觉
     * - synopsis  : 章节简介，适中
     */
    private const TASK_TEMPERATURES = [
        'creative'   => null,  // null = 使用用户配置值
        'structured' => 0.3,
        'synopsis'   => 0.5,
    ];

    public function __construct(array $cfg) {
        $this->apiUrl      = rtrim($cfg['api_url'], '/');
        $this->apiKey      = $cfg['api_key'];
        $this->modelName   = $cfg['model_name'];
        $this->modelId     = (string)$cfg['id'];
        $this->modelLabel  = $cfg['name'];
        $this->maxTokens   = (int)($cfg['max_tokens']   ?? 4096);
        $this->temperature = (float)($cfg['temperature'] ?? 0.8);
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
            $msg = $data['error']['message'] ?? mb_substr($resp, 0, 200);
            throw new RuntimeException("API Error ($code): $msg");
        }
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
            if (str_contains($e->getMessage(), '400') ||
                str_contains($e->getMessage(), 'stream_options') ||
                str_contains($e->getMessage(), 'unknown field') ||
                str_contains($e->getMessage(), 'Extra inputs')) {
                return $this->doStream($messages, $onChunk, false, $taskType);
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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$httpCode) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
                    $httpCode = (int)$m[1];
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (
                &$buffer, &$usage, &$httpCode, &$rawBody, &$streamErr, $onChunk
            ) {
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
                    $text = $decoded['choices'][0]['delta']['content'] ?? null;
                    if ($text !== null) $onChunk($text);
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) throw new RuntimeException("CURL Error: $curlErr");

        if ($httpCode && $httpCode !== 200) {
            $errData = json_decode($rawBody, true);
            $errMsg  = $errData['error']['message'] ?? mb_substr($rawBody, 0, 300);
            throw new RuntimeException("API Error ($httpCode): $errMsg");
        }
        if ($streamErr) throw new RuntimeException("API Stream Error: $streamErr");

        return $usage;
    }

    private function buildPayload(array $messages, bool $stream, bool $includeUsage = false, string $taskType = 'creative'): array {
        $temp = self::TASK_TEMPERATURES[$taskType] ?? null;
        if ($temp === null) {
            $temp = $this->temperature;
        }

        $p = [
            'model'       => $this->modelName,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => $temp,
            'stream'      => $stream,
        ];
        if ($stream && $includeUsage) {
            $p['stream_options'] = ['include_usage' => true];
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
            CURLOPT_TIMEOUT        => 120,
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
