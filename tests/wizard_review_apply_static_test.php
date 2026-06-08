<?php
/**
 * 体检「采纳」功能静态测试。
 *
 * 守护 review_patch / apply_review 的关键安全契约：
 *   - 两个 action 存在且有处理函数；
 *   - 存在 novels 字段白名单（reviewNovelFieldSpec），且 apply_review 会按白名单复校验；
 *   - apply_review **不删除**任何角色或卷（只增/改）——防 AI 误删用户设定；
 *   - 写库前必经预览：launch.php 有「采纳」按钮、预览弹窗、确认应用入口。
 */

$root = dirname(__DIR__);

function wra_read(string $rel): string
{
    global $root;
    $p = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($p)) throw new RuntimeException("Missing source file: {$rel}");
    return file_get_contents($p);
}
function wra_assert(bool $c, string $m): void { if (!$c) throw new RuntimeException($m); }
function wra_has(string $needle, string $hay, string $m): void { wra_assert(strpos($hay, $needle) !== false, $m); }

$w = wra_read('api/wizard.php');

// ---- action 路由与处理函数 ----
wra_has("case 'review_patch':", $w, 'wizard.php must route review_patch.');
wra_has("case 'apply_review':", $w, 'wizard.php must route apply_review.');
wra_has('function handleReviewPatch', $w, 'handleReviewPatch must exist.');
wra_has('function handleApplyReview', $w, 'handleApplyReview must exist.');

// ---- 字段白名单存在且含关键字段 ----
wra_has('function reviewNovelFieldSpec', $w, 'A novels field whitelist (reviewNovelFieldSpec) must exist.');
foreach (['core_conflicts', 'protagonist_traits', 'custom_settings', 'extra_settings'] as $f) {
    wra_has("'{$f}'", $w, "Whitelist should cover {$f}.");
}

// ---- apply_review 必须按白名单复校验，且不删除角色/卷 ----
$start = strpos($w, 'function handleApplyReview');
wra_assert($start !== false, 'handleApplyReview not found for body inspection.');
$end = strpos($w, 'function handleSaveExtra', $start);
wra_assert($end !== false && $end > $start, 'Could not isolate handleApplyReview body.');
$applyBody = substr($w, $start, $end - $start);

wra_has('reviewNovelFieldSpec()', $applyBody, 'apply_review must re-load the whitelist (do not trust client).');
wra_has('isset($spec[$key])', $applyBody, 'apply_review must re-validate novel keys against the whitelist.');
wra_assert(strpos($applyBody, 'DELETE FROM') === false, 'apply_review must NOT delete characters or volumes (only add/update).');

// ---- 前端：采纳按钮 + 预览弹窗 + 应用入口 ----
$l = wra_read('includes/wizard/launch.php');
wra_has('id="btn-adopt"', $l, 'launch.php must have the 采纳 button.');
wra_has('id="adopt-overlay"', $l, 'launch.php must have the preview modal overlay.');
wra_has('action=review_patch', $l, 'launch.php must call review_patch.');
wra_has('action=apply_review', $l, 'launch.php must call apply_review.');
wra_has('id="adopt-apply"', $l, 'launch.php must have a confirm-apply button.');

// ---- review_patch 必须【流式】调用 AI（修复 524：同步 chat() 在慢/推理模型上会被上游代理超时）----
$rpStart = strpos($w, 'function handleReviewPatch');
$rpEnd   = $rpStart !== false ? strpos($w, 'function handleApplyReview', $rpStart) : false;
wra_assert($rpStart !== false && $rpEnd !== false && $rpEnd > $rpStart, 'Could not isolate handleReviewPatch body.');
$rpBody = substr($w, $rpStart, $rpEnd - $rpStart);
wra_has('chatStream(', $rpBody, 'review_patch must STREAM the AI call (blocking chat() 524s on slow/reasoning models behind a proxy).');
wra_has('getModelFallbackList(', $rpBody, 'review_patch must iterate configured models (fallback) for the AI call.');

echo "wizard_review_apply_static_test passed\n";
