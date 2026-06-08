<?php
/**
 * 挂机写作缓存失效静态回归测试
 *
 * 背景（用户报告的 bug）：
 *   `getNovel()`（includes/data.php）把整行小说缓存到 `novel:{id}`，TTL 300s。
 *   `api/daemon_write.php` 的「停用挂机」(action=disable) 直接 `DB::update('novels', daemon_write=0)`，
 *   但**未失效该缓存** → 刷新后 novel.php 仍读到缓存里的 daemon_write=1，
 *   「挂机写作进行中」面板再次出现（正常应隐藏）。
 *
 * 守护目标：
 *   1) data.php 中 getNovel() 读取的缓存键 与 clearNovelCache() 删除的缓存键一致（都是 `novel:`）。
 *   2) daemon_write.php 中每一处改动 `novels.daemon_write` 的 `DB::update('novels', …)`，
 *      其后都跟有 `clearNovelCache(`（否则面板/状态会因 300s 缓存而不同步）。
 *   3) 启用/停用块（action=enable/disable 的核心写库）紧随 `clearNovelCache(`。
 */

$root = dirname(__DIR__);

function dci_read(string $rel): string
{
    $abs = dirname(__DIR__) . '/' . $rel;
    if (!is_file($abs)) {
        echo "daemon_cache_invalidation_static_test FAILED: 缺少文件 {$rel}\n";
        exit(1);
    }
    return file_get_contents($abs);
}
function dci_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "daemon_cache_invalidation_static_test FAILED: {$msg}\n";
        exit(1);
    }
}

// ---- 1) 缓存键一致性（reader getNovel 与 invalidator clearNovelCache 必须同键）----
$data = dci_read('includes/data.php');
dci_assert(preg_match('/function getNovel\b/', $data) === 1, 'data.php 未定义 getNovel()');
dci_assert(preg_match('/function clearNovelCache\b/', $data) === 1, 'data.php 未定义 clearNovelCache()');
// getNovel 用 "novel:{$id}" 读缓存；clearNovelCache 删 "novel:{$novelId}"。两者前缀必须一致。
dci_assert(preg_match('/\$cacheKey\s*=\s*"novel:\{\$id\}"/', $data) === 1,
    'data.php getNovel() 的缓存键不是 "novel:{$id}"（与 clearNovelCache 可能不一致）');
dci_assert(preg_match('/Cache::delete\(\s*"novel:\{\$novelId\}"\s*\)/', $data) === 1,
    'data.php clearNovelCache() 未删除 "novel:{$novelId}" 缓存键');

// ---- 2) daemon_write.php：每处改 daemon_write 的 novels 更新后须有 clearNovelCache ----
$dw    = dci_read('api/daemon_write.php');
$lines = preg_split('/\r?\n/', $dw);

$updateLines = [];   // 改动 daemon_write 的 DB::update('novels', …) 行号
foreach ($lines as $i => $line) {
    $t = ltrim($line);
    if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*')) continue;
    if (str_contains($line, "DB::update('novels'") && str_contains($line, 'daemon_write')) {
        $updateLines[] = $i;
    }
}
dci_assert(count($updateLines) >= 3,
    'daemon_write.php 改动 daemon_write 的 DB::update(\'novels\') 处数异常（疑似文件被改动），实测 ' . count($updateLines));

// 每处更新后 8 行内必须出现 clearNovelCache(
$missing = [];
foreach ($updateLines as $ln) {
    $found = false;
    for ($k = $ln; $k <= min($ln + 8, count($lines) - 1); $k++) {
        if (str_contains($lines[$k], 'clearNovelCache(')) { $found = true; break; }
    }
    if (!$found) $missing[] = $ln + 1; // 1-based 行号
}
dci_assert(empty($missing),
    'daemon_write.php 以下改 daemon_write 的更新后缺少 clearNovelCache()：行 ' . implode(',', $missing));

// 计数兜底：clearNovelCache 调用数应 >= daemon_write 更新数
$clearCount = substr_count($dw, 'clearNovelCache(');
dci_assert($clearCount >= count($updateLines),
    "daemon_write.php clearNovelCache 调用数({$clearCount}) 少于 daemon_write 更新数(" . count($updateLines) . ')');

// ---- 3) 启用/停用核心写库后必须紧随 clearNovelCache($novelId)（按行核验，避免被中文注释字节数干扰）----
$disableLn = null;
foreach ($lines as $i => $line) {
    if (str_contains($line, "DB::update('novels', ['daemon_write' => \$val]")) { $disableLn = $i; break; }
}
dci_assert($disableLn !== null, 'daemon_write.php 未找到 enable/disable 的核心写库语句');
$foundDisableClear = false;
for ($k = $disableLn; $k <= min($disableLn + 8, count($lines) - 1); $k++) {
    if (str_contains($lines[$k], 'clearNovelCache($novelId)')) { $foundDisableClear = true; break; }
}
dci_assert($foundDisableClear,
    'daemon_write.php enable/disable 写库后未紧随 clearNovelCache($novelId)');

echo "daemon_cache_invalidation_static_test passed (缓存键一致；daemon_write " . count($updateLines) . " 处更新均失效缓存)\n";
