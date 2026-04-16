<?php
/**
 * 诊断脚本 - 检查系统配置和数据库连接
 */

// 需要登录才能访问
define('APP_LOADED', true);
require_once __DIR__ . '/includes/auth.php';
requireLogin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>系统诊断</h2>";
echo "<pre>";

// 1. 检查 PHP 版本
echo "PHP 版本: " . PHP_VERSION . "\n\n";

// 2. 检查数据库连接
echo "=== 数据库连接测试 ===\n";
try {
    define('APP_LOADED', true);
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/db.php';
    
    $pdo = DB::connect();
    echo "✓ 数据库连接成功\n";
    
    // 检查表是否存在
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ 数据库表: " . implode(', ', $tables) . "\n";
    
    // 检查模型数据
    $models = DB::fetchAll('SELECT id, name, model_name, api_url, is_default FROM ai_models');
    echo "✓ 已配置模型数量: " . count($models) . "\n";
    
    if (empty($models)) {
        echo "⚠ 警告: 没有配置任何 AI 模型\n";
    } else {
        echo "\n已配置的模型:\n";
        foreach ($models as $m) {
            echo "  - ID: {$m['id']}, 名称: {$m['name']}, 模型: {$m['model_name']}, 默认: " . ($m['is_default'] ? '是' : '否') . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ 数据库错误: " . $e->getMessage() . "\n";
}

// 3. 检查 API 文件
echo "\n=== API 文件检查 ===\n";
$apiFile = __DIR__ . '/api/actions.php';
if (file_exists($apiFile)) {
    echo "✓ API 文件存在: $apiFile\n";
    echo "✓ 文件大小: " . filesize($apiFile) . " 字节\n";
} else {
    echo "✗ API 文件不存在: $apiFile\n";
}

// 4. 测试 API 调用（模拟）
echo "\n=== 模拟 API 调用 ===\n";
if (!empty($models)) {
    $testModelId = $models[0]['id'];
    echo "测试模型 ID: $testModelId\n";
    
    // 模拟 POST 请求
    $postData = json_encode(['action' => 'test_model', 'model_id' => $testModelId]);
    echo "请求数据: $postData\n";
    
    // 使用 curl 测试
    $url = 'http://' . $_SERVER['HTTP_HOST'] . '/api/actions.php';
    echo "请求 URL: $url\n";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP 状态码: $httpCode\n";
    if ($error) {
        echo "CURL 错误: $error\n";
    }
    echo "响应内容: " . ($response ?: '(空)') . "\n";
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data) {
            echo "JSON 解析: 成功\n";
            echo "响应数据: " . print_r($data, true);
        } else {
            echo "JSON 解析: 失败\n";
            echo "JSON 错误: " . json_last_error_msg() . "\n";
        }
    }
} else {
    echo "⚠ 跳过 API 测试（没有配置模型）\n";
}

echo "\n=== 诊断完成 ===\n";
echo "</pre>";
