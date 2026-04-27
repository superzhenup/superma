<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * ================================================================
 * EmbeddingProvider — 全局单例 embedding 客户端
 *
 * 设计要点:
 * - 全系统只配一个 embedding 模型(system_settings.global_embedding_model_id)
 * - 指向 ai_models 表里 can_embed=1 的一条记录
 * - 失败时返回 null,让调用方决定是降级还是跳过
 * - 首次调用后把真实维度回填到 ai_models.embedding_dim
 * ================================================================
 */
final class EmbeddingProvider
{
    private static ?array $cfg = null;  // 缓存当前请求内的 provider 配置

    /**
     * 获取当前全局 embedding 模型配置,未配置则返回 null。
     * 调用方需要处理 null 的情况(等同于"此小说暂无语义检索能力")。
     */
    public static function getConfig(): ?array
    {
        if (self::$cfg !== null) return self::$cfg ?: null;

        $row = DB::fetch(
            'SELECT setting_value FROM system_settings WHERE setting_key=?',
            ['global_embedding_model_id']
        );
        $modelId = $row ? (int)$row['setting_value'] : 0;

        // --- 自动修复：global_embedding_model_id 为空，但存在方舟模型 ---
        // 场景：编辑模型时 disabled checkbox 导致 embedding_enabled 丢失，
        // 或手动清空了 global_embedding_model_id。自动查找方舟模型并修复。
        if ($modelId <= 0) {
            // 优先匹配方舟 API
            $ark = DB::fetch(
                "SELECT * FROM ai_models WHERE api_url LIKE '%ark.cn-beijing.volces.com%' OR api_url LIKE '%volces.com%' LIMIT 1"
            );
            // fallback: 任何有 api_key 且 api_url 包含 embedding 关键字的模型
            if (!$ark) {
                $ark = DB::fetch(
                    "SELECT * FROM ai_models WHERE api_key != '' AND (can_embed=1 OR embedding_enabled=1) LIMIT 1"
                );
            }
            if ($ark && !empty($ark['api_key'])) {
                // 自动修复：回写 can_embed=1 和 global_embedding_model_id
                $embModelName = !empty($ark['embedding_model_name']) ? $ark['embedding_model_name'] : 'doubao-embedding-vision';
                DB::update('ai_models', [
                    'can_embed'            => 1,
                    'embedding_enabled'    => 1,
                    'embedding_model_name' => $embModelName,
                ], 'id=?', [$ark['id']]);
                DB::query(
                    "INSERT INTO system_settings (setting_key, setting_value) VALUES ('global_embedding_model_id', ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?",
                    [(string)$ark['id'], (string)$ark['id']]
                );
                $modelId = (int)$ark['id'];
                error_log("EmbeddingProvider auto-fix: set global_embedding_model_id={$ark['id']} for model '{$ark['name']}' (api_url={$ark['api_url']})");
            }
        }

        if ($modelId <= 0) {
            self::$cfg = [];  // 标记已查过,未配置
            return null;
        }

        $model = DB::fetch(
            'SELECT * FROM ai_models WHERE id=? AND can_embed=1',
            [$modelId]
        );

        // --- 自动修复：global_embedding_model_id 指向的模型 can_embed=0 ---
        // 场景：编辑该模型时因 BUG 导致 can_embed 被误清
        if (!$model) {
            $candidate = DB::fetch('SELECT * FROM ai_models WHERE id=?', [$modelId]);
            $isArk = $candidate && (
                stripos($candidate['api_url'], 'ark.cn-beijing.volces.com') !== false
                || stripos($candidate['api_url'], 'volces.com') !== false
            );
            // 修复条件：方舟模型 或 任何有 api_key 的模型
            if ($candidate && !empty($candidate['api_key']) && ($isArk || !empty($candidate['embedding_enabled']))) {
                $embModelName = !empty($candidate['embedding_model_name']) ? $candidate['embedding_model_name'] : 'doubao-embedding-vision';
                DB::update('ai_models', [
                    'can_embed'            => 1,
                    'embedding_enabled'    => 1,
                    'embedding_model_name' => $embModelName,
                ], 'id=?', [$candidate['id']]);
                $model = $candidate;
                $model['can_embed'] = 1;
                $model['embedding_enabled'] = 1;
                $model['embedding_model_name'] = $embModelName;
                error_log("EmbeddingProvider auto-fix: restored can_embed=1 for model '{$candidate['name']}' (id={$candidate['id']}, api_url={$candidate['api_url']})");
            }
        }

        if (!$model) {
            self::$cfg = [];
            return null;
        }
        self::$cfg = $model;
        return $model;
    }

    /**
     * 对单条文本生成 embedding。失败返回 null。
     * 返回:['vec' => float[], 'model' => string, 'dim' => int]
     */
    public static function embed(string $text): ?array
    {
        $batch = self::embedBatch([$text]);
        return $batch ? $batch[0] : null;
    }

    /**
     * 批量 embedding。大多数 provider 支持 input 为数组。
     * 返回:与输入同序的 [['vec'=>..., 'model'=>..., 'dim'=>...], ...],
     *       失败整批返回 null。
     *
     * @param string[] $texts
     */
    public static function embedBatch(array $texts): ?array
    {
        if (empty($texts)) return [];
        $cfg = self::getConfig();
        if (!$cfg) return null;

        // 过滤空文本
        $cleanTexts = array_map(fn($t) => trim((string)$t), $texts);
        $cleanTexts = array_map(fn($t) => $t === '' ? ' ' : $t, $cleanTexts);

        $url = rtrim($cfg['api_url'], '/') . '/embeddings';
        $modelName = $cfg['embedding_model_name'] ?: $cfg['model_name'];

        // 分批处理：方舟 API 限制单次最多 10 条 input
        $batchSize = 10;
        $allResults = [];

        foreach (array_chunk($cleanTexts, $batchSize) as $chunk) {
            $payload = [
                'model' => $modelName,
                'input' => $chunk,
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $cfg['api_key'],
                ],
                CURLOPT_TIMEOUT        => CFG_CURL_TIMEOUT_EMBED,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err || $code !== 200) {
                error_log("EmbeddingProvider failed: http=$code err=$err body=" . mb_substr((string)$resp, 0, 200));
                return null;
            }

            $data = json_decode($resp, true);
            if (!isset($data['data']) || !is_array($data['data'])) {
                error_log("EmbeddingProvider: unexpected response shape");
                return null;
            }

            // OpenAI 兼容格式:[{index, embedding:[...]}]
            // 按 index 排序,保证与输入同序
            $rows = $data['data'];
            usort($rows, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            $dim = 0;
            foreach ($rows as $r) {
                $vec = $r['embedding'] ?? null;
                if (!is_array($vec) || empty($vec)) {
                    return null; // 整批失败
                }
                if ($dim === 0) $dim = count($vec);
                $allResults[] = [
                    'vec'   => array_map('floatval', $vec),
                    'model' => $modelName,
                    'dim'   => $dim,
                ];
            }
        }

        // 首次成功时回填真实维度(便于 UI 展示 / 后续一致性校验)
        if (!empty($allResults)) {
            $dim = $allResults[0]['dim'] ?? 0;
            if ($dim > 0 && (int)($cfg['embedding_dim'] ?? 0) !== $dim) {
                DB::update('ai_models',
                    ['embedding_dim' => $dim],
                    'id=?', [$cfg['id']]
                );
                self::$cfg['embedding_dim'] = $dim;
            }
        }

        return $allResults;
    }

    /**
     * 供 UI / settings 页面调用:
     * 快速测试当前配置的 embedding 端点是否工作
     */
    public static function selfTest(): array
    {
        $cfg = self::getConfig();
        if (!$cfg) {
            return ['ok' => false, 'msg' => '未配置全局 embedding 模型'];
        }
        $r = self::embed('测试文本:春江花月夜');
        if (!$r) {
            return ['ok' => false, 'msg' => '调用失败,请检查 API Key / 模型名 / 端点'];
        }
        return [
            'ok'    => true,
            'model' => $r['model'],
            'dim'   => $r['dim'],
            'msg'   => "OK, 维度={$r['dim']}",
        ];
    }

    /**
     * 仅供测试/重置:清空缓存
     */
    public static function resetCache(): void
    {
        self::$cfg = null;
    }
}
