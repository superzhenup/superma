<?php
/**
 * 自动写作「每章刷新章节列表」静态回归测试
 *
 * 背景（用户报告）：连续「自动写作」时，每写完一章章节列表不自动刷新——
 *   startAutoWrite() 原先只在**整轮循环结束后**（停止/写完全书）调用一次
 *   refreshChapterList()，循环体内不刷新，导致逐章完成时新章节迟迟不出现。
 *
 * 守护目标：
 *   1) assets/js/app.js 定义了 refreshChapterList。
 *   2) startAutoWrite 的 while 循环**体内**调用了 refreshChapterList（逐章刷新），
 *      而不仅是循环外收尾时刷新一次。
 */

$app = @file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
if ($app === false) {
    echo "autowrite_refresh_static_test FAILED: 无法读取 assets/js/app.js\n";
    exit(1);
}
function awr_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "autowrite_refresh_static_test FAILED: {$msg}\n";
        exit(1);
    }
}

awr_assert(strpos($app, 'function refreshChapterList') !== false,
    'app.js 未定义 refreshChapterList()');

$loopStart = strpos($app, 'while (!autoWriteStop');
awr_assert($loopStart !== false, '未找到 startAutoWrite 的 while 循环');

// 循环外收尾锚点（紧跟在循环之后）：if (myGen !== autoWriteGen) return;
$loopEnd = strpos($app, 'if (myGen !== autoWriteGen) return;', $loopStart);
awr_assert($loopEnd !== false && $loopEnd > $loopStart, '未找到自动写作循环的收尾锚点');

$loopBody = substr($app, $loopStart, $loopEnd - $loopStart);
awr_assert(strpos($loopBody, 'refreshChapterList') !== false,
    '自动写作 while 循环体内未调用 refreshChapterList（每章写完不会实时刷新章节列表）');

// 循环外收尾的整轮刷新仍应保留（停止/写完全书时再刷新一次）
$afterLoop = substr($app, $loopEnd);
awr_assert(strpos($afterLoop, 'refreshChapterList') !== false,
    'startAutoWrite 收尾处应保留一次 refreshChapterList');

echo "autowrite_refresh_static_test passed (自动写作循环体内逐章刷新章节列表)\n";
