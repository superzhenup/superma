<?php
/**
 * 统计系统诊断脚本
 * 访问方式：浏览器打开 debug_stats.php
 */

define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/stats_tracker.php';

echo "<h3>统计系统诊断</h3>";
echo "<pre>";

// 1. 检查配置
echo "=== 1. 配置检查 ===\n";
echo "STATS_REPORT_ENABLED: " . (defined('STATS_REPORT_ENABLED') ? (STATS_REPORT_ENABLED ? 'true' : 'false') : '未定义') . "\n";
echo "STATS_SERVER_URL: " . (defined('STATS_SERVER_URL') ? STATS_SERVER_URL : '未定义') . "\n";
echo "STATS_SITE_ID: " . (defined('STATS_SITE_ID') && STATS_SITE_ID ? STATS_SITE_ID : '(自动生成) ' . StatsTracker::getSiteId()) . "\n";

// 2. 检查本地数据库表
echo "\n=== 2. 本地表检查 ===\n";
$exists = DB::fetch("SHOW TABLES LIKE 'usage_stats'");
echo "usage_stats 表: " . ($exists ? '✓ 存在' : '✗ 不存在') . "\n";

if ($exists) {
    $localStats = DB::fetch("SELECT * FROM usage_stats ORDER BY id DESC LIMIT 5");
    if (empty($localStats)) {
        echo "本地数据: 空（还没有记录任何统计数据）\n";
    } else {
        echo "本地数据（最近 5 条）:\n";
        foreach ($localStats as $s) {
            $reported = $s['reported_at'] ? "已上报 {$s['reported_at']}" : '待上报';
            echo "  - {$s['stat_date']}: {$s['words_added']} 字, {$s['chapters_added']} 章, {$reported}\n";
        }
    }
}

// 3. 检查小说总字数
echo "\n=== 3. 小说数据 ===\n";
$totalWords = DB::fetchColumn("SELECT SUM(total_words) FROM novels");
$totalChapters = DB::fetchColumn("SELECT SUM((SELECT COUNT(*) FROM chapters WHERE novel_id = novels.id AND status = 'completed')) FROM novels");
echo "总字数: " . number_format($totalWords ?: 0) . "\n";
echo "已完成章节: " . ($totalChapters ?: 0) . "\n";

// 4. 测试上报
echo "\n=== 4. 测试上报 ===\n";

if (!StatsTracker::isEnabled()) {
    echo "⚠️ 统计上报已禁用\n";
} else {
    // 检查是否有待上报数据
    $shouldReport = StatsTracker::shouldReport();
    echo "shouldReport(): " . ($shouldReport ? 'true' : 'false') . "\n";

    $pending = StatsTracker::getPendingStats();
    echo "待上报数据: " . (empty($pending) ? '无' : json_encode($pending, JSON_UNESCAPED_UNICODE)) . "\n";

    // 尝试上报
    if ($shouldReport) {
        echo "\n正在尝试上报...\n";
        $result = StatsTracker::report();
        echo "上报结果: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    } else if (empty($pending)) {
        echo "\n模拟上报测试（无待上报数据）...\n";

        // 模拟一条数据进行测试
        $testPayload = [
            'site_id' => StatsTracker::getSiteId(),
            'date' => date('Y-m-d'),
            'words_added' => 1000,
            'chapters_added' => 1,
            'novels_active' => 1,
            'version' => APP_VERSION . '-test',
        ];

        $serverUrl = StatsTracker::getServerUrl();
        echo "请求地址: {$serverUrl}\n";
        echo "请求载荷: " . json_encode($testPayload, JSON_UNESCAPED_UNICODE) . "\n\n";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $serverUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($testPayload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Super-Ma-Novel-System/' . APP_VERSION . '-test',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        echo "HTTP 状态码: {$httpCode}\n";
        if ($error) {
            echo "cURL 错误: {$error}\n";
        }
        echo "响应内容: {$response}\n";
    }
}

// 5. 测试接收端连接
echo "\n=== 5. 接收端连接测试 ===\n";
$serverUrl = StatsTracker::getServerUrl();
if ($serverUrl) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $serverUrl,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Super-Ma-Novel-System/' . APP_VERSION . '-test',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    echo "GET 请求状态码: {$httpCode}\n";
    if ($error) {
        echo "cURL 错误: {$error}\n";
    }
    echo "响应内容: " . mb_substr($response, 0, 500) . "\n";
} else {
    echo "⚠️ STATS_SERVER_URL 未配置\n";
}

echo "\n=== 诊断完成 ===\n";
echo "</pre>";
