<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/Vector.php';

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
     * 审计修复（SSRF 对齐，2026-08-16）：对齐 AIClient::ssrfGuardResolve 的请求侧防护。
     * 首次请求前对端点 URL 做公网校验（A+AAAA 全量、解析失败 fail-closed），
     * 并以 CURLOPT_RESOLVE 固定解析结果，封堵"保存时校验通过、请求时 DNS 改向内网"
     * 的 rebinding TOCTOU。结果按 URL 进程内缓存，同一端点的重复请求不再重复 DNS 查询。
     */
    private static array $ssrfResolveCache = [];

    private static function ssrfGuardResolve(string $url): array
    {
        if (array_key_exists($url, self::$ssrfResolveCache)) {
            return self::$ssrfResolveCache[$url];
        }
        if (!function_exists('assertPublicApiEndpointResolve')) {
            require_once __DIR__ . '/../helpers.php';
        }
        return self::$ssrfResolveCache[$url] = assertPublicApiEndpointResolve($url);
    }

    /**
     * 获取当前全局 embedding 模型配置,未配置则返回 null。
     * 调用方需要处理 null 的情况(等同于"此小说暂无语义检索能力")。
     */
    public static function getConfig(): ?array
    {
        $frozen = false;
        $frozenModelId = class_exists('ChapterRunFrozenConfigScope', false)
            ? ChapterRunFrozenConfigScope::readSystem(
                'global_embedding_model_id',
                0,
                'int',
                $frozen
            )
            : 0;
        if (!$frozen && self::$cfg !== null) return self::$cfg ?: null;
        if ($frozen && self::$cfg !== null
            && (int)(self::$cfg['id'] ?? 0) === (int)$frozenModelId) {
            return self::$cfg ?: null;
        }

        if ($frozen) {
            $modelId = (int)$frozenModelId;
        } else {
            $row = DB::fetch(
                'SELECT setting_value FROM system_settings WHERE setting_key=?',
                ['global_embedding_model_id']
            );
            $modelId = $row ? (int)$row['setting_value'] : 0;
        }

        // --- 自动修复：global_embedding_model_id 为空，但存在方舟模型 ---
        // 场景：编辑模型时 disabled checkbox 导致 embedding_enabled 丢失，
        // 或手动清空了 global_embedding_model_id。
        // 审计修复（2026-08-16 自愈收紧）：原实现按 api_url LIKE 宽匹配（'%volces.com%'）
        // 任意模型即回写，属于读路径隐藏写副作用——拼凑该子串的任意 URL 都可能被提升为
        // 全局 embedding 模型。现仅当存在 api_url 精确等于官方方舟端点的模型才回写，
        // 且回写前 error_log 留痕；其余情况保持未配置（null），交由用户在设置页显式选择。
        if ($modelId <= 0 && !$frozen) {
            $ark = DB::fetch(
                "SELECT * FROM ai_models WHERE api_url = 'https://ark.cn-beijing.volces.com/api/v3' AND api_key != '' LIMIT 1"
            );
            if ($ark) {
                error_log("EmbeddingProvider auto-fix: exact ark endpoint match (model id={$ark['id']}, name='{$ark['name']}'), will restore can_embed=1 and global_embedding_model_id");
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
                if (function_exists('clearSystemSettingsCache')) clearSystemSettingsCache();
                $modelId = (int)$ark['id'];
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
        if ($model && $frozen) {
            ChapterRunFrozenConfigScope::assertModelRow($model);
        }

        // --- 自动修复：global_embedding_model_id 指向的模型 can_embed=0 ---
        // 场景：编辑该模型时因 BUG 导致 can_embed 被误清
        if (!$model && !$frozen) {
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

        // 审计修复（SSRF 对齐，2026-08-16）：请求前校验端点 DNS 解析并固定结果
        // （同 AIClient M1）。解析失败/命中内网地址 → fail-closed 整批返回 null，
        // 与本类"失败返回 null 由调用方决定降级"的既有错误处理风格一致。
        try {
            $ssrfResolve = self::ssrfGuardResolve($url);
        } catch (\Throwable $e) {
            error_log('EmbeddingProvider::embedBatch blocked by SSRF guard (url=' . $url . '): ' . $e->getMessage());
            return null;
        }

        // 分批处理：方舟 API 限制单次最多 10 条 input
        $batchSize = 10;
        // 预分配结果槽位，按 provider 返回的 index 落位（而非顺序 append），
        // 漏返/拒答的条目保持 null，消费方已有 `$embs[$i] ?? null` 跳过逻辑
        $allResults = array_fill(0, count($cleanTexts), null);
        $baseOffset = 0;

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
                CURLOPT_CONNECTTIMEOUT => min(15, max(1, (int)CFG_CURL_TIMEOUT_EMBED)),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,   // L-1 修复：显式启用 SSL 验证
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            // 细纲准备阶段的语义检索同样处于 SSE 请求内。网络等待时调用
            // 已注册的保活回调；普通 JSON/CLI 调用不自行产生任何输出。
            $heartbeat = $GLOBALS['sendHeartbeat'] ?? null;
            if (is_callable($heartbeat)) {
                $lastHeartbeatAt = microtime(true);
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function () use ($heartbeat, &$lastHeartbeatAt): int {
                    $now = microtime(true);
                    if ($now - $lastHeartbeatAt >= 1.0) {
                        $heartbeat();
                        $lastHeartbeatAt = $now;
                    }
                    return 0;
                });
            }
            // CLI（异步 worker）下 php.ini 未配 curl.cainfo 时补 CA 包，否则 embedding 的 HTTPS 调用也会失败
            if (class_exists('AIClient') && ($caBundle = AIClient::caBundle())) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }
            // 审计修复（SSRF 对齐）：固定 DNS 解析结果，封堵 rebinding（详见 ssrfGuardResolve）
            if ($ssrfResolve) {
                curl_setopt($ch, CURLOPT_RESOLVE, $ssrfResolve);
            }
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            // 审计修复 R3（2026-08-10）：chunk 失败时不再立即 return null，
            // 否则已成功 chunk 写入 $allResults 的部分结果会被丢弃，与下方
            // L211-L216 "至少 1 条有效就不算失败"的设计意图矛盾。改为记录
            // 日志并跳过本 chunk，继续处理后续 chunk，最后统一过滤 null 槽位。
            // 注意：continue 前必须递增 $baseOffset，否则下一 chunk 的 slotIdx 错位。
            if ($err || $code !== 200) {
                error_log("EmbeddingProvider chunk failed (offset={$baseOffset}, size=" . count($chunk) . "): http=$code err=$err body=" . mb_substr((string)$resp, 0, 200));
                $baseOffset += count($chunk);
                continue;
            }

            $data = json_decode($resp, true);
            if (!isset($data['data']) || !is_array($data['data'])) {
                // 审计修复 R3（2026-08-10）：与 chunk curl 失败一致的容错策略，
                // 跳过本 chunk 而非整批失败，保留已成功 chunk 的结果。
                error_log("EmbeddingProvider unexpected response shape (offset={$baseOffset}, size=" . count($chunk) . ")");
                $baseOffset += count($chunk);
                continue;
            }

            // OpenAI 兼容格式:[{index, embedding:[...]}]
            // 审计修复（2026-07-19 H-09）：按 index 落位到预分配槽位（而非顺序 append），
            // 否则 provider 对某条输入拒答/去重/漏返时，该 chunk 之后所有结果序号整体前移一位，
            // atom/伏笔被写入别的文本的向量，语义检索被静默污染且不会被重补。
            $rows = $data['data'];
            $dim = 0;
            foreach ($rows as $r) {
                $vec = $r['embedding'] ?? null;
                if (!is_array($vec) || empty($vec)) {
                    continue; // 单条跳过，不拖垮整批
                }
                if ($dim === 0) $dim = count($vec);
                $vec = Vector::normalize(array_map('floatval', $vec));
                $slotIdx = $baseOffset + (int)($r['index'] ?? 0);
                if ($slotIdx >= 0 && $slotIdx < count($allResults)) {
                    $allResults[$slotIdx] = [
                        'vec'   => $vec,
                        'model' => $modelName,
                        'dim'   => $dim,
                    ];
                }
            }
            $baseOffset += count($chunk);
        }

        // 过滤 null 槽位：至少有 1 条有效结果即不算失败
        $allResults = array_filter($allResults, fn($r) => $r !== null);
        if (empty($allResults)) {
            error_log('EmbeddingProvider::embedBatch: batch completely failed (all slots null)');
            return null;
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

    /**
     * 供长驻 worker 每任务后失效配置缓存：
     * 避免同一进程跨任务读到上一任务用户的模型配置。
     */
    public static function resetConfig(): void
    {
        self::$cfg = null;
    }
}
