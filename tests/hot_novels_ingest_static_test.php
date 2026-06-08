<?php
/**
 * hot_novels_ingest 增强回归测试。
 *
 * 覆盖两项对照 ai-book-full 参考文件补齐的能力：
 *   1. 作者名质量校验 HotNovelValidator::validateAuthor（功能测试，纯函数无需 DB）。
 *   2. 内容签名去重：HotNovelService::upsertItem 在内容未变时返回 'duplicated' 并跳过写库
 *      （静态契约：除 last_batch_id/collected_at 外无变化即去重）；端点按 'duplicated' 计数。
 */

define('APP_LOADED', true); // HotNovelValidator 顶部有 APP_LOADED 守卫

$root = dirname(__DIR__);

function hni_assert(bool $c, string $m): void { if (!$c) throw new RuntimeException($m); }
function hni_read(string $rel): string
{
    global $root;
    $p = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($p)) throw new RuntimeException("Missing source file: {$rel}");
    return file_get_contents($p);
}

// ---- 1. validateAuthor 功能测试 ----
require_once $root . '/includes/HotNovelValidator.php';
hni_assert(method_exists('HotNovelValidator', 'validateAuthor'), 'HotNovelValidator::validateAuthor must exist.');

hni_assert(HotNovelValidator::validateAuthor('')['ok'] === true,        '空作者应允许（作者非必填）。');
hni_assert(HotNovelValidator::validateAuthor('  ')['ok'] === true,      '纯空白作者应允许。');
hni_assert(HotNovelValidator::validateAuthor('唐家三少')['ok'] === true, '正常作者应通过。');
hni_assert(HotNovelValidator::validateAuthor('Author Name')['ok'] === true, '英文作者应通过。');

hni_assert(HotNovelValidator::validateAuthor('123456')['ok'] === false, '纯数字作者应被拒。');
hni_assert(HotNovelValidator::validateAuthor('😀作者')['ok'] === false,  'emoji 作者应被拒。');
hni_assert(HotNovelValidator::validateAuthor('★★★')['ok'] === false,    '装饰符作者应被拒。');
hni_assert(HotNovelValidator::validateAuthor('----')['ok'] === false,   '重复符号作者应被拒。');
hni_assert(HotNovelValidator::validateAuthor(str_repeat('a', 61))['ok'] === false, '超长作者应被拒。');

// validateTitle 重构后回归
hni_assert(HotNovelValidator::validateTitle('完美世界')['ok'] === true,  '正常书名应通过。');
hni_assert(HotNovelValidator::validateTitle('😀书')['ok'] === false,     '含 emoji 书名仍应被拒（重构后不破）。');
hni_assert(HotNovelValidator::validateTitle('1')['ok'] === false,        '过短书名应被拒。');

// ---- 2. 内容签名去重：服务层契约 ----
$svc = hni_read('includes/HotNovelService.php');
hni_assert(strpos($svc, "'status' => 'duplicated'") !== false, 'upsertItem must be able to return status=duplicated.');
hni_assert(strpos($svc, "'last_batch_id', 'collected_at'") !== false, 'Dedup must ignore the per-push bookkeeping fields (last_batch_id, collected_at).');
hni_assert(strpos($svc, 'SELECT * FROM hot_novels WHERE title_norm') !== false, 'upsertItem must load the full existing row to compare content.');

// ---- 3. 端点接线 ----
$api = hni_read('api/hot_novels_ingest.php');
hni_assert(strpos($api, 'validateAuthor') !== false, 'Ingest endpoint must validate author quality.');
hni_assert(strpos($api, "=== 'duplicated'") !== false, "Ingest endpoint must count the 'duplicated' status.");

echo "hot_novels_ingest_static_test passed\n";
