<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
if (isInstalled() && PHP_SAPI !== 'cli') {
    requireLogin();
}
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/quality/Gates.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== 字数统计诊断 ===\n\n";

// 1. 检查 countWords 函数是否存在
echo "1. countWords() 函数: " . (function_exists('countWords') ? "存在 ✓" : "不存在 ✗") . "\n";

// 2. 检查 checkGate1_Structure 函数是否存在
echo "2. checkGate1_Structure() 函数: " . (function_exists('checkGate1_Structure') ? "存在 ✓" : "不存在 ✗") . "\n\n";

// 3. 测试 countWords 实现
$testText = "你好，世界！hello world 123。这是测试text。";
$cw = countWords($testText);
$mb = mb_strlen($testText);
echo "3. 测试文本: \"{$testText}\"\n";
echo "   countWords 结果: {$cw}\n";
echo "   mb_strlen 结果: {$mb}\n";
// 中文8字(你好世界这是测试) + 英文3词(hello/world/text) = 11
if ($cw === 11) {
    echo "   状态: countWords 正确（中文8字 + 英文3词 = 11）✓\n";
} else {
    echo "   状态: countWords 异常 ✗（应为 11）\n";
}
echo "\n";

// 4. 反射检查 checkGate1_Structure 是否使用 countWords
$ref = new ReflectionFunction('checkGate1_Structure');
$file = $ref->getFileName();
$startLine = $ref->getStartLine();
$endLine = $ref->getEndLine();
$source = file($file);
$funcBody = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

echo "4. checkGate1_Structure 源码位置: {$file}:{$startLine}-{$endLine}\n";
if (strpos($funcBody, 'countWords') !== false) {
    echo "   状态: 使用 countWords ✓\n";
} elseif (strpos($funcBody, 'mb_strlen') !== false) {
    echo "   状态: 仍使用 mb_strlen ✗（需要部署 Gates.php 新版本）\n";
} else {
    echo "   状态: 未知字数计算方式\n";
}
echo "\n";

// 5. OPcache 状态
if (function_exists('opcache_get_status')) {
    $opStatus = opcache_get_status(false);
    if ($opStatus && !empty($opStatus['opcache_enabled'])) {
        echo "5. OPcache: 已启用\n";
        echo "   警告: 如果刚部署新代码，需要重启 PHP-FPM 或调用 opcache_reset()\n";
    } else {
        echo "5. OPcache: 未启用\n";
    }
} else {
    echo "5. OPcache: 未安装\n";
}
echo "\n";

// 6. 检查 helpers.php 和 Gates.php 的修改时间
$helpersFile = __DIR__ . '/includes/helpers.php';
$gatesFile = __DIR__ . '/includes/quality/Gates.php';
echo "6. 文件修改时间:\n";
echo "   helpers.php: " . date('Y-m-d H:i:s', filemtime($helpersFile)) . "\n";
echo "   Gates.php: " . date('Y-m-d H:i:s', filemtime($gatesFile)) . "\n";
echo "   当前时间: " . date('Y-m-d H:i:s') . "\n";

echo "\n=== 诊断完成 ===\n";
echo "如果 countWords 结果为 6 且 checkGate1_Structure 使用 countWords，则代码已正确部署。\n";
echo "如果仍显示 mb_strlen，请:\n";
echo "  1. 确认部署了最新的 helpers.php 和 Gates.php\n";
echo "  2. 重启 PHP-FPM: systemctl restart php-fpm\n";
echo "  3. 重新点击「一键检测」\n";
