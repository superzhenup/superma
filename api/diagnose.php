<?php
/**
 * 完整诊断脚本
 * 访问方式: http://你的域名/api/diagnose.php
 */

// 清除所有输出缓冲
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

$result = [
    'step' => [],
    'errors' => [],
    'success' => true
];

function addStep($name, $status, $message = '') {
    global $result;
    $result['step'][] = [
        'name' => $name,
        'status' => $status,
        'message' => $message
    ];
    if ($status === 'error') {
        $result['success'] = false;
    }
}

// 步骤 1: 检查 PHP 环境
addStep('php_version', 'ok', PHP_VERSION);

// 步骤 2: 检查必需文件
$requiredFiles = [
    '../config.php',
    '../includes/db.php',
    '../includes/ai.php',
    '../includes/functions.php',
    'actions.php'
];

foreach ($requiredFiles as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        addStep("file_$file", 'ok', "存在");
    } else {
        addStep("file_$file", 'error', "文件不存在: $fullPath");
    }
}

// 步骤 3: 检查数据库连接
try {
    define('APP_LOADED', true);
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';
    
    $pdo = DB::connect();
    addStep('database_connection', 'ok', '连接成功');
    
    // 检查表
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    addStep('database_tables', 'ok', implode(', ', $tables));
    
    // 检查模型数据
    $models = DB::fetchAll('SELECT id, name, model_name, api_url, is_default FROM ai_models');
    addStep('ai_models_count', 'ok', count($models) . ' 个模型');
    
    if (empty($models)) {
        addStep('ai_models_data', 'warning', '没有配置任何 AI 模型');
    } else {
        $modelInfo = [];
        foreach ($models as $m) {
            $modelInfo[] = "{$m['name']} (ID:{$m['id']}, {$m['model_name']})";
        }
        addStep('ai_models_data', 'ok', implode('; ', $modelInfo));
    }
    
} catch (Exception $e) {
    addStep('database_connection', 'error', $e->getMessage());
}

// 步骤 4: 测试 API actions.php
try {
    // 模拟测试请求
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    addStep('api_test', 'info', '请手动测试: curl -X POST -H "Content-Type: application/json" -d \'{"action":"test_model","model_id":1}\' http://你的域名/api/actions.php');
    
} catch (Exception $e) {
    addStep('api_test', 'error', $e->getMessage());
}

// 输出结果
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
