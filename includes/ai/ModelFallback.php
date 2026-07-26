<?php
/**
 * 模型 fallback 与客户端工厂函数
 *
 * 审计优化 P3-5（2026-06-16）：从 includes/ai.php 提取类外独立函数到此文件。
 * ai.php 作为聚合入口 require 本文件，保证所有调用方零改动。
 *
 * 依赖：AIClient 类（定义在 ai.php）、DB 类
 */

defined('APP_LOADED') or die('Direct access denied.');

/** 容量/能力不足属于可安全向客户端说明的配置错误。 */
class ModelFallbackRequirementException extends RuntimeException {
    /** 仅本异常由内部固定模板构造，允许端点安全展示。 */
    public function clientMessage(): string {
        return parent::getMessage();
    }
}

/** @return array{min_context_tokens:int, required_capabilities:array<int,string>, label:string} */
function normalizeModelFallbackRequirements(?array $requirements): array {
    $requirements ??= [];
    $caps = $requirements['required_capabilities'] ?? [];
    if (is_string($caps)) $caps = [$caps];
    if (!is_array($caps)) $caps = [];
    $caps = array_values(array_unique(array_filter(array_map(
        static fn($cap) => trim((string)$cap),
        $caps
    ))));

    return [
        'min_context_tokens' => max(0, (int)($requirements['min_context_tokens'] ?? 0)),
        'required_capabilities' => $caps,
        'label' => trim((string)($requirements['label'] ?? '本次请求')) ?: '本次请求',
    ];
}

/** @return array<int,string> */
function modelFallbackCapabilities(array $modelCfg): array {
    $caps = $modelCfg['capabilities'] ?? [];
    if (is_string($caps)) {
        $decoded = json_decode($caps, true);
        $caps = is_array($decoded) ? $decoded : [];
    }
    return is_array($caps)
        ? array_values(array_unique(array_map('strval', $caps)))
        : [];
}

/** @return array<int,string> 不满足项；空数组表示可用。 */
function modelFallbackRequirementMismatches(array $modelCfg, ?array $requirements): array {
    $required = normalizeModelFallbackRequirements($requirements);
    $mismatches = [];
    $caps = modelFallbackCapabilities($modelCfg);

    foreach ($required['required_capabilities'] as $capability) {
        if (!in_array($capability, $caps, true)) {
            $mismatches[] = 'capability:' . $capability;
        }
    }

    if ($required['min_context_tokens'] > 0) {
        // 与 AIClient 使用同一个上下文上限事实源，避免 fallback 另维护一套模型名称规则。
        $contextLimit = (new AIClient($modelCfg))->getContextLimit();
        if ($contextLimit < $required['min_context_tokens']) {
            $mismatches[] = 'context:' . $contextLimit;
        }
    }

    return $mismatches;
}

/**
 * @return array{eligible:array<int,array>, skipped:array<int,array{model:array,reasons:array<int,string>}>}
 */
function selectModelFallbackCandidates(array $models, ?array $requirements): array {
    $required = normalizeModelFallbackRequirements($requirements);
    if ($required['min_context_tokens'] === 0 && $required['required_capabilities'] === []) {
        return ['eligible' => array_values($models), 'skipped' => []];
    }

    $eligible = [];
    $skipped = [];
    foreach ($models as $modelCfg) {
        $reasons = modelFallbackRequirementMismatches($modelCfg, $required);
        if ($reasons === []) {
            $eligible[] = $modelCfg;
        } else {
            $skipped[] = ['model' => $modelCfg, 'reasons' => $reasons];
        }
    }
    return ['eligible' => $eligible, 'skipped' => $skipped];
}

function describeModelFallbackRequirements(?array $requirements): string {
    $required = normalizeModelFallbackRequirements($requirements);
    $parts = [];
    if ($required['min_context_tokens'] > 0) {
        $parts[] = '上下文至少 ' . number_format($required['min_context_tokens']) . ' tokens';
    }
    if ($required['required_capabilities'] !== []) {
        $parts[] = '能力 ' . implode(', ', $required['required_capabilities']);
    }
    return $required['label'] . ($parts ? '需要' . implode('、', $parts) : '');
}

/** @return array<int,array> */
function enforceModelFallbackRequirements(array $models, ?array $requirements): array {
    $selection = selectModelFallbackCandidates($models, $requirements);
    if ($selection['eligible'] === []) {
        throw new ModelFallbackRequirementException(
            describeModelFallbackRequirements($requirements)
            . '，当前没有满足要求的候选模型。请配置具备相应上下文能力的模型后重试。'
        );
    }
    return $selection['eligible'];
}

// ================================================================
// 获取单个模型客户端
// ================================================================
function getAIClient(?int $modelId = null): AIClient {
    // 先按指定模型查找（如小说绑定的 model_id）。
    $model = $modelId ? DB::fetch('SELECT * FROM ai_models WHERE id=?', [$modelId]) : null;

    // 修复：指定的模型不存在（典型场景——小说绑定了一个【已被删除/重建】的 model_id）时，
    // 必须回退到默认/任意可用模型，而不是误报“请先添加模型”。
    // 否则像“标题优化”这类直接 getAIClient($novel['model_id']) 的功能会在库里明明有模型时报错，
    // 而走 getModelFallbackList 的写作流程却正常（后者对缺失的首选 id 本就会兜底）。
    if (!$model) {
        $model = DB::fetch('SELECT * FROM ai_models WHERE is_default=1 LIMIT 1')
              ?: DB::fetch('SELECT * FROM ai_models ORDER BY id LIMIT 1');
    }

    if (!$model) throw new RuntimeException('请先在【模型设置】中添加至少一个AI模型。');
    return new AIClient($model);
}

// ================================================================
// 获取 fallback 模型列表（支持按任务类型智能排序）
// ================================================================
/**
 * 获取模型列表，支持按任务类型智能排序
 *
 * @param int|null $preferredModelId 首选模型ID（可选）
 * @param string|null $taskType 任务类型: creative|structured|synopsis（可选）
 * @param array|null $requirements 可选守卫：min_context_tokens / required_capabilities / label
 * @return array 排序并按要求过滤后的模型列表
 */
function getModelFallbackList(?int $preferredModelId = null, ?string $taskType = null, ?array $requirements = null): array {
    $all = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');
    if (empty($all)) {
        throw new RuntimeException('请先在【模型设置】中添加至少一个AI模型。');
    }

    // 如果没有指定任务类型，使用原有逻辑
    if (!$taskType) {
        if (!$preferredModelId) return enforceModelFallbackRequirements($all, $requirements);

        usort($all, function ($a, $b) use ($preferredModelId) {
            if ((int)$a['id'] === $preferredModelId) return -1;
            if ((int)$b['id'] === $preferredModelId) return 1;
            return (int)$b['is_default'] - (int)$a['is_default'];
        });
        return enforceModelFallbackRequirements($all, $requirements);
    }

    // 按任务类型智能排序
    usort($all, function ($a, $b) use ($preferredModelId, $taskType) {
        // 1. 首选模型优先级最高
        if ($preferredModelId) {
            if ((int)$a['id'] === $preferredModelId) return -1;
            if ((int)$b['id'] === $preferredModelId) return 1;
        }

        // 2. 检查模型能力标签
        $aCaps = json_decode($a['capabilities'] ?? '[]', true) ?: [];
        $bCaps = json_decode($b['capabilities'] ?? '[]', true) ?: [];

        $aHasTask = in_array($taskType, $aCaps, true);
        $bHasTask = in_array($taskType, $bCaps, true);

        // 有能力标签的优先
        if ($aHasTask && !$bHasTask) return -1;
        if (!$aHasTask && $bHasTask) return 1;

        // 3. 都有或都没有能力标签，按默认模型优先
        if ((int)$a['is_default'] !== (int)$b['is_default']) {
            return (int)$b['is_default'] - (int)$a['is_default'];
        }

        // 4. 最后按ID排序
        return (int)$a['id'] - (int)$b['id'];
    });

    return enforceModelFallbackRequirements($all, $requirements);
}

// ================================================================
// 通用 fallback 执行器（支持按任务类型智能选择）
// ================================================================
/**
 * 通用 fallback 执行器
 *
 * @param int|null $preferredModelId 首选模型ID
 * @param callable $callback 回调函数
 * @param callable|null $onSwitch 模型切换回调
 * @param string|null $taskType 任务类型: creative|structured|synopsis
 * @param callable|null $onWaiting 等待心跳回调 fn(int $elapsedSeconds): void
 * @param array|null $requirements 可选容量/能力守卫；不传时保持原 fallback 行为
 * @return mixed
 */
function withModelFallback(
    ?int     $preferredModelId,
    callable $callback,
    ?callable $onSwitch = null,
    ?string $taskType = null,
    ?callable $onWaiting = null,
    ?array $requirements = null
): mixed {
    $orderedModels   = getModelFallbackList($preferredModelId, $taskType);
    $selection       = selectModelFallbackCandidates($orderedModels, $requirements);
    $models          = $selection['eligible'];
    $skippedModels   = $selection['skipped'];
    if ($models === []) {
        throw new ModelFallbackRequirementException(
            describeModelFallbackRequirements($requirements)
            . '，当前 fallback 列表没有满足要求的候选模型；容量不足的模型不会接收本次请求。'
        );
    }
    $lastError       = null;
    $max429Retries   = 3;   // 429 重试次数
    $base429Delay    = 3;   // 首次退避 3 秒，之后 6s, 12s

    foreach ($models as $idx => $modelCfg) {
        $ai = new AIClient($modelCfg);
        // 审计优化 P2-1（2026-06-16）：显式注入 waiting 回调，替代 $GLOBALS['sendWaiting'] 全局耦合。
        // 若调用方未传入 $onWaiting，回退读取 $GLOBALS['sendWaiting'] 保持向后兼容。
        $waitingCb = $onWaiting;
        if ($waitingCb === null && isset($GLOBALS['sendWaiting']) && is_callable($GLOBALS['sendWaiting'])) {
            $waitingCb = $GLOBALS['sendWaiting'];
        }
        if ($waitingCb !== null) {
            $ai->setCallbacks(null, $waitingCb);
        }

        for ($retry429 = 0; $retry429 <= $max429Retries; $retry429++) {
            if ($retry429 > 0) {
                $delay = $base429Delay * pow(2, $retry429 - 1); // 3s, 6s, 12s
                if ($onSwitch !== null) {
                    $onSwitch($ai, "API 繁忙 (429)，{$delay}秒后重试（{$retry429}/{$max429Retries}）…");
                }
                // v1.11.10: 逐秒休眠并触发心跳回调，防止直连模式下长连接被 Nginx/Cloudflare 因长时间静默掐断
                // 审计修复 M-NEW-2（2026-06-15）：心跳回调用 try-catch 包裹，
                // 防止回调抛异常穿透 fallback 链导致后续模型不被尝试。
                for ($s = 0; $s < $delay; $s++) {
                    sleep(1);
                    try {
                        // 审计优化 P2-1（2026-06-16）：优先使用显式注入的 $waitingCb
                        if ($onSwitch !== null && $waitingCb !== null) {
                            call_user_func($waitingCb, $s + 1);
                        } elseif ($onSwitch !== null && isset($GLOBALS['sendWaiting']) && is_callable($GLOBALS['sendWaiting'])) {
                            call_user_func($GLOBALS['sendWaiting'], $s + 1);
                        }
                    } catch (\Throwable $e) {
                        error_log('withModelFallback 429 heartbeat callback error: ' . $e->getMessage());
                    }
                }
            }

            try {
                return $callback($ai);
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();

                if (strpos($msg, '(429)') !== false && $retry429 < $max429Retries) {
                    $lastError = $e;
                    continue;
                }

                $lastError = $e;
                break;
            }
        }

        if ($lastError && strpos($lastError->getMessage(), '(429)') === false) {
            if ($idx + 1 < count($models) && $onSwitch !== null) {
                $nextAi = new AIClient($models[$idx + 1]);
                $onSwitch($nextAi, $lastError->getMessage());
            }
        }
    }

    if ($lastError instanceof Throwable) {
        $required = normalizeModelFallbackRequirements($requirements);
        $hasRequirements = $required['min_context_tokens'] > 0
            || $required['required_capabilities'] !== [];
        if ($hasRequirements) {
            error_log('withModelFallback compatible candidates failed: ' . $lastError->getMessage());
            $skippedNote = $skippedModels
                ? '；另有' . count($skippedModels) . '个容量或能力不足的候选已跳过'
                : '';
            throw new ModelFallbackRequirementException(
                describeModelFallbackRequirements($requirements)
                . '，所有满足要求的候选模型均请求失败' . $skippedNote
                . '。请检查 1M 模型配置、配额或服务状态。',
                0,
                $lastError
            );
        }
        throw $lastError;
    }

    throw new RuntimeException('没有可用的模型候选。');
}
