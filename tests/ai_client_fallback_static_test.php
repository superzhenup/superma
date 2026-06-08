<?php
/**
 * getAIClient() 回退行为静态回归测试。
 *
 * Bug：小说 novels.model_id 绑定了一个【已删除】的模型 id 时，getAIClient($id)
 * 原先直接抛“请先在【模型设置】中添加至少一个AI模型。”，即使库里还有其它模型。
 * 表现为「标题优化」等直接传 $novel['model_id'] 的功能误报缺少模型。
 *
 * 修复契约：指定模型查不到时，必须先回退到默认/任意模型，再决定是否抛错。
 * 即在 getAIClient 函数体内，针对缺失模型的回退（is_default / 任意）必须出现在
 * 抛错之前，且回退块（if (!$model)）位于 is_default 查询之前。
 */

$root = dirname(__DIR__);
$src  = file_get_contents($root . '/includes/ai.php');
if ($src === false) { throw new RuntimeException('Cannot read includes/ai.php'); }

$start = strpos($src, 'function getAIClient');
if ($start === false) { throw new RuntimeException('getAIClient() not found'); }
$end = strpos($src, "\nfunction ", $start + 1);
$body = $end !== false ? substr($src, $start, $end - $start) : substr($src, $start);

function aifb_assert(bool $c, string $m): void { if (!$c) throw new RuntimeException($m); }

$posIdLookup    = strpos($body, 'WHERE id=?');
$posRecovery    = strpos($body, 'if (!$model)');
$posDefault     = strpos($body, 'is_default=1');
$posThrow       = strpos($body, '请先在【模型设置】');

aifb_assert($posIdLookup !== false, 'getAIClient must still look up the requested model by id.');
aifb_assert($posDefault  !== false, 'getAIClient must have a default/any-model fallback.');
aifb_assert($posThrow    !== false, 'getAIClient must still throw when truly no model exists.');
aifb_assert($posRecovery !== false, 'getAIClient must have a recovery branch (if (!$model)).');

// 关键：回退分支必须出现在默认查询之前 —— 这是“查不到指定模型时仍会兜底”的指纹。
// 旧（有 bug）版本里 is_default 只存在于三元表达式的 else 分支，回退块(if!model)反而在其后接抛错。
aifb_assert(
    $posRecovery < $posDefault,
    'Regression: getAIClient must fall back to default/any model when the requested id is missing (recovery must precede the default lookup), not throw immediately.'
);
aifb_assert(
    $posDefault < $posThrow,
    'Regression: the default/any-model fallback must run before throwing the “no model” error.'
);

// ---- 标题优化 524 / 空结果 / 空响应 修复：同步 + 关思考 + 模型 fallback + 致命兜底 ----
$oct = file_get_contents($root . '/api/optimize_chapter_title.php');
if ($oct === false) { throw new RuntimeException('Cannot read api/optimize_chapter_title.php'); }
aifb_assert(strpos($src, "\$taskType === 'title'") !== false,
    "ai.php must define the lightweight 'title' task profile (small max_tokens so each attempt returns before the proxy timeout).");
aifb_assert(strpos($oct, 'getModelFallbackList(') !== false,
    'optimize_chapter_title must iterate configured models (fallback), not a single fixed model (fixes wrong/slow model).');
aifb_assert(strpos($oct, "\$cfg['thinking_enabled'] = 0") !== false,
    'optimize_chapter_title must disable thinking for the short title task (avoids the long-reasoning 524 / empty content).');
aifb_assert(strpos($oct, "chat(\$messages, 'title')") !== false,
    "optimize_chapter_title must use the lightweight 'title' profile (bounded time → returns before the proxy times out).");
aifb_assert(strpos($oct, 'register_shutdown_function') !== false,
    'optimize_chapter_title must register a shutdown handler that always emits JSON (defence in depth for PHP-internal fatals).');

echo "ai_client_fallback_static_test passed\n";
