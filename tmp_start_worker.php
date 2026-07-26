<?php
/**
 * 临时脚本：一键启动 drama_worker 处理积压任务
 * 访问方式：/tmp_start_worker.php?token=start-2026-07-26
 * 用完即删。
 */
define('APP_LOADED', true);

$expectedToken = 'start-2026-07-26';
$gotToken = $_GET['token'] ?? '';
if (!hash_equals($expectedToken, $gotToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "访问被拒绝。请带上 ?token={$expectedToken} 参数访问。\n";
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/drama/DramaWorkerLauncher.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== 一键启动 drama_worker ===\n\n";

// 1. 显示 PHP CLI 路径解析结果
$phpBin = (new ReflectionClass(DramaWorkerLauncher::class))->getMethod('resolvePhpCli');
$phpBin->setAccessible(true);
$resolved = $phpBin->invoke(null);
echo "PHP_BINARY 常量: " . PHP_BINARY . "\n";
echo "resolvePhpCli() 解析结果: " . $resolved . "\n\n";

// 2. 检查 exec 可用性
$execAvail = DramaWorkerLauncher::execAvailable();
echo "execAvailable(): " . ($execAvail ? 'true' : 'false') . "\n\n";

// 3. 尝试通过 DramaWorkerLauncher 启动(后台单次执行 max=50)
echo ">>> 尝试通过 DramaWorkerLauncher::launch() 启动后台 worker...\n";
$launched = DramaWorkerLauncher::launch(50);
echo "launch() 返回: " . ($launched ? 'true (已派发)' : 'false (失败)') . "\n\n";

// 4. 直接同步执行一个任务(立即处理一个,验证 worker 逻辑可用)
echo ">>> 同步执行一个任务(立即处理,验证代码可用)...\n";
require_once __DIR__ . '/includes/drama/DramaTaskRunner.php';
try {
    @set_time_limit(120);
    $result = DramaTaskRunner::runNext('drama-web-start:' . getmypid());
    if ($result === null) {
        echo "  队列为空(无任务可认领)\n";
    } else {
        echo "  处理结果: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) {
    echo "  执行异常: " . $e->getMessage() . "\n";
    echo "  文件: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== 后续步骤 ===\n";
echo "1. 后台 worker 已派发(若 launch=true),会异步处理剩余任务\n";
echo "2. 上面的同步执行结果展示了第一个任务的处理情况\n";
echo "3. 刷新 drama_studio.php 页面,观察'进行中任务'是否下降\n";
echo "4. 若后台 worker 仍不工作,请用 SSH/宝塔终端执行:\n";
echo "   {$resolved} " . __DIR__ . "/bin/drama_worker.php --loop --sleep=3 --max=200\n";
echo "5. 处理完成后,删除本脚本和 tmp_drama_diag.php(若存在)\n";
