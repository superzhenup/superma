<?php
/**
 * Agent机制自检脚本
 * 
 * 功能:
 * 1. 检查ConfigCenter类是否可用
 * 2. 验证Agent表是否存在
 * 3. 检查Agent配置是否正确
 * 4. 测试Agent决策流程
 * 
 * 运行方式: php verify_agents.php
 */

// 加载系统
define('APP_LOADED', true);
define('CLI_MODE', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// 确保ConfigCenter类已加载
if (!class_exists('ConfigCenter')) {
    require_once __DIR__ . '/includes/config_center.php';
}

echo "========================================\n";
echo "Agent机制自检脚本\n";
echo "========================================\n\n";

$checks = [];
$allPassed = true;

// ==================== 检查-1: SCHEMA_VERSION 与 db.php 注释版本一致性 ====================
// 防止"加新 ALTER 忘记升 SCHEMA_VERSION"的反复犯错（v1.2-v1.8 共发生 7 次）
// 工作原理：扫 db.php 注释里所有 [vN] 标记，对比 SCHEMA_VERSION 常量。
// 注释版本超过常量 → 升级用户的 ALTER 不会被执行 → 字段缺失类 bug。
echo "【检查-1】SCHEMA_VERSION 与 db.php 注释版本一致性\n";
echo "----------------------------------------\n";

try {
    $ref = new ReflectionClass('DB');
    $schemaVer = (int)$ref->getConstant('SCHEMA_VERSION');

    $dbCode = file_get_contents(__DIR__ . '/includes/db.php');
    preg_match_all('/\[v(\d+)\]/', $dbCode, $m);
    $maxVer = $m[1] ? max(array_map('intval', $m[1])) : 0;

    if ($maxVer > $schemaVer) {
        echo "✗ db.php 注释里有 [v{$maxVer}] 但 SCHEMA_VERSION = {$schemaVer}\n";
        echo "  请把 SCHEMA_VERSION 升到 {$maxVer}，否则升级用户的 ALTER 不会执行！\n";
        echo "  受影响：从更老版本升级（schema_version_migrated < {$maxVer}）的所有用户\n";
        $checks['schema_version'] = false;
        $allPassed = false;
    } else {
        echo "✓ SCHEMA_VERSION ({$schemaVer}) 与注释最高版本 ({$maxVer}) 一致\n";
        $checks['schema_version'] = true;
    }
} catch (Throwable $e) {
    echo "✗ SCHEMA_VERSION 检查失败: {$e->getMessage()}\n";
    $checks['schema_version'] = false;
    $allPassed = false;
}

echo "\n";

// ==================== 检查0: DB白名单与实际表名一致性 ====================
echo "【检查0】DB白名单与实际表名一致性\n";
echo "----------------------------------------\n";

try {
    // 反射读取 ALLOWED_TABLES 常量
    $ref = new ReflectionClass('DB');
    $whitelist = $ref->getConstant('ALLOWED_TABLES') ?? [];

    // 获取数据库实际存在的所有表
    $schemaTables = DB::fetchAll("SHOW TABLES");
    $schemaTableNames = array_map(fn($r) => array_values($r)[0], $schemaTables);

    // 对账：实际存在但不在白名单的表
    $missing = array_diff($schemaTableNames, $whitelist);
    if (empty($missing)) {
        echo "✓ 白名单完整\n";
        $checks['whitelist'] = true;
    } else {
        foreach ($missing as $t) {
            echo "✗ 表 {$t} 存在但不在白名单（DB::insert 会失败）\n";
        }
        echo "\n⚠ 请在 includes/db.php 的 ALLOWED_TABLES 常量中补充以上表名！\n";
        $checks['whitelist'] = false;
        $allPassed = false;
    }
} catch (Throwable $e) {
    echo "✗ 白名单检查失败: {$e->getMessage()}\n";
    $checks['whitelist'] = false;
    $allPassed = false;
}

echo "\n";

// ==================== 检查1: ConfigCenter类 ====================
echo "【检查1】ConfigCenter类\n";
echo "----------------------------------------\n";

if (class_exists('ConfigCenter')) {
    echo "✓ ConfigCenter类已加载\n";
    
    // 测试get方法
    $testValue = ConfigCenter::get('agent.enabled', true, 'bool');
    echo "✓ ConfigCenter::get() 工作正常 (agent.enabled = " . ($testValue ? 'true' : 'false') . ")\n";
    
    // 测试set方法（只测试，不实际修改）
    $testKey = 'test.verify_agents.' . time();
    $setResult = ConfigCenter::set($testKey, 'test_value', 'string');
    if ($setResult) {
        echo "✓ ConfigCenter::set() 工作正常\n";
        
        // 验证设置的值
        $getValue = ConfigCenter::get($testKey, '', 'string');
        if ($getValue === 'test_value') {
            echo "✓ ConfigCenter::get() 读取设置的值正确\n";
        } else {
            echo "✗ ConfigCenter::get() 读取设置的值不正确\n";
            $allPassed = false;
        }
        
        // 清理测试数据
        ConfigCenter::delete($testKey);
    } else {
        echo "✗ ConfigCenter::set() 失败\n";
        $allPassed = false;
    }
    
    $checks['config_center'] = true;
} else {
    echo "✗ ConfigCenter类未加载\n";
    $checks['config_center'] = false;
    $allPassed = false;
}

echo "\n";

// ==================== 检查2: Agent表 ====================
echo "【检查2】Agent表\n";
echo "----------------------------------------\n";

$tables = ['agent_decision_logs', 'agent_action_logs'];

foreach ($tables as $table) {
    try {
        $result = DB::fetch("SHOW TABLES LIKE '$table'");
        if ($result) {
            echo "✓ {$table} 表存在\n";
            $checks['tables'][$table] = true;
        } else {
            echo "✗ {$table} 表不存在\n";
            $checks['tables'][$table] = false;
            $allPassed = false;
        }
    } catch (Throwable $e) {
        echo "✗ {$table} 检查失败: {$e->getMessage()}\n";
        $checks['tables'][$table] = false;
        $allPassed = false;
    }
}

echo "\n";

// ==================== 检查3: Agent配置 ====================
echo "【检查3】Agent配置\n";
echo "----------------------------------------\n";

$configKeys = [
    'agent.enabled' => ['type' => 'bool', 'default' => '1'],
    'agent.strategy_agent.enabled' => ['type' => 'bool', 'default' => '1'],
    'agent.strategy_agent.decision_interval' => ['type' => 'int', 'default' => '10'],
    'agent.quality_agent.enabled' => ['type' => 'bool', 'default' => '1'],
    'agent.quality_agent.check_interval' => ['type' => 'int', 'default' => '5'],
    'agent.quality_agent.auto_fix' => ['type' => 'bool', 'default' => '1'],
    'agent.optimization_agent.enabled' => ['type' => 'bool', 'default' => '1'],
    'agent.optimization_agent.optimization_interval' => ['type' => 'int', 'default' => '20'],
];

$configMissing = false;
foreach ($configKeys as $key => $config) {
    try {
        $value = ConfigCenter::get($key, null, $config['type']);
        if ($value !== null) {
            echo "✓ {$key} = {$value}\n";
            $checks['config'][$key] = true;
        } else {
            echo "⚠ {$key} 未配置，将使用默认值\n";
            $checks['config'][$key] = false;
            $configMissing = true;
        }
    } catch (Throwable $e) {
        echo "✗ {$key} 检查失败: {$e->getMessage()}\n";
        $checks['config'][$key] = false;
        $configMissing = true;
    }
}

// 如果配置缺失，尝试自动修复
if ($configMissing) {
    echo "\n正在自动修复缺失的Agent配置...\n";
    foreach ($configKeys as $key => $config) {
        if (!($checks['config'][$key] ?? false)) {
            try {
                $result = ConfigCenter::set($key, $config['default'], $config['type']);
                if ($result) {
                    echo "✓ 已设置 {$key} = {$config['default']}\n";
                    $checks['config'][$key] = true;
                } else {
                    echo "✗ 设置 {$key} 失败\n";
                }
            } catch (Throwable $e) {
                echo "✗ 设置 {$key} 失败: {$e->getMessage()}\n";
            }
        }
    }
}

echo "\n";

// ==================== 检查4: Agent文件 ====================
echo "【检查4】Agent文件\n";
echo "----------------------------------------\n";

$files = [
    'includes/agents/BaseAgent.php',
    'includes/agents/WritingStrategyAgent.php',
    'includes/agents/QualityMonitorAgent.php',
    'includes/agents/OptimizationAgent.php',
    'includes/agents/AgentCoordinator.php',
];

foreach ($files as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        $size = filesize($fullPath);
        echo "✓ {$file} ({$size} bytes)\n";
        $checks['files'][$file] = true;
    } else {
        echo "✗ {$file} 不存在\n";
        $checks['files'][$file] = false;
        $allPassed = false;
    }
}

echo "\n";

// ==================== 检查5: 决策日志统计 ====================
echo "【检查5】决策日志统计\n";
echo "----------------------------------------\n";

try {
    $stats = DB::fetch(
        'SELECT 
            agent_type,
            COUNT(*) as total,
            MIN(created_at) as first_decision,
            MAX(created_at) as last_decision
         FROM agent_decision_logs
         GROUP BY agent_type'
    );
    
    if ($stats) {
        $statsList = is_array($stats[0] ?? null) ? $stats : [$stats];
        
        foreach ($statsList as $stat) {
            echo "✓ {$stat['agent_type']}: {$stat['total']}次决策\n";
            echo "  首次: {$stat['first_decision']}\n";
            echo "  最近: {$stat['last_decision']}\n";
        }
        
        $checks['logs']['decision'] = true;
    } else {
        echo "⚠ 暂无决策日志(可能尚未运行Agent)\n";
        $checks['logs']['decision'] = null;
    }
} catch (Throwable $e) {
    echo "✗ 决策日志查询失败: {$e->getMessage()}\n";
    $checks['logs']['decision'] = false;
}

echo "\n";

// ==================== 检查6: 动作日志统计 ====================
echo "【检查6】动作日志统计\n";
echo "----------------------------------------\n";

try {
    $stats = DB::fetch(
        'SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = "skipped" THEN 1 ELSE 0 END) as skipped
         FROM agent_action_logs'
    );
    
    if ($stats && $stats['total'] > 0) {
        $successRate = round($stats['success'] / $stats['total'] * 100, 1);
        echo "总动作数: {$stats['total']}\n";
        echo "成功: {$stats['success']} ({$successRate}%)\n";
        echo "失败: {$stats['failed']}\n";
        echo "跳过: {$stats['skipped']}\n";
        
        $checks['logs']['action'] = $successRate >= 90;
    } else {
        echo "⚠ 暂无动作日志\n";
        $checks['logs']['action'] = null;
    }
} catch (Throwable $e) {
    echo "✗ 动作日志查询失败: {$e->getMessage()}\n";
    $checks['logs']['action'] = false;
}

echo "\n";

// ==================== 检查7: WriteEngine集成 ====================
echo "【检查7】WriteEngine集成\n";
echo "----------------------------------------\n";

$writeEngineFile = __DIR__ . '/includes/write_engine.php';
if (file_exists($writeEngineFile)) {
    $content = file_get_contents($writeEngineFile);
    
    if (strpos($content, 'runPreWriteAgents') !== false) {
        echo "✓ WriteEngine已集成Agent决策\n";
        $checks['integration'] = true;
    } else {
        echo "✗ WriteEngine未集成Agent决策\n";
        $checks['integration'] = false;
        $allPassed = false;
    }
} else {
    echo "✗ write_engine.php 文件不存在\n";
    $checks['integration'] = false;
    $allPassed = false;
}

echo "\n";

// ==================== 检查8: 间隔触发逻辑 ====================
echo "【检查8】间隔触发逻辑\n";
echo "----------------------------------------\n";

$agentCoordinatorFile = __DIR__ . '/includes/agents/AgentCoordinator.php';
if (file_exists($agentCoordinatorFile)) {
    $content = file_get_contents($agentCoordinatorFile);
    
    if (strpos($content, '$chNum % $stratInterval') !== false && 
        strpos($content, '$chNum % $qualInterval') !== false) {
        echo "✓ AgentCoordinator已实现间隔触发逻辑\n";
        $checks['interval'] = true;
    } else {
        echo "✗ AgentCoordinator未实现间隔触发逻辑\n";
        $checks['interval'] = false;
        $allPassed = false;
    }
} else {
    echo "✗ AgentCoordinator.php 文件不存在\n";
    $checks['interval'] = false;
    $allPassed = false;
}

echo "\n";

// ==================== 检查9: Agent决策 → 写作流程消费链路 ====================
echo "【检查9】Agent决策 → 写作流程消费链路\n";
echo "----------------------------------------\n";

$keys = [
    'ws_cool_point_density_target' => 'float',
    'ws_pacing_strategy'           => 'string',
    'ws_quality_strictness'        => 'string',
];

$checks['chain'] = true;
foreach ($keys as $k => $t) {
    try {
        $original = ConfigCenter::get($k, null, $t);
        $testVal  = $t === 'float' ? 0.99 : 'TEST_' . time();

        ConfigCenter::set($k, $testVal, $t);

        // 立刻通过写作流程的入口函数再读
        $readBack = getSystemSetting($k, null, $t);

        if ((string)$readBack === (string)$testVal) {
            echo "✓ $k 写入立刻可读\n";
        } else {
            echo "✗ $k 链路断裂（写=$testVal 读=" . ($readBack ?? 'NULL') . "）\n";
            $checks['chain'] = false;
            $allPassed = false;
        }

        // 还原
        if ($original !== null) {
            ConfigCenter::set($k, $original, $t);
        } else {
            ConfigCenter::delete($k);
        }
        
        // 孤岛检测：验证这个key是否真的有写作流程消费者
        $grepCmd = "grep -rln '$k' --include='*.php' includes/ "
                 . "| grep -v agents/ | grep -v config_center | wc -l";
        $usages = (int)shell_exec($grepCmd);
        
        if ($usages > 0) {
            echo "✓ $k 有 $usages 个写作流程消费者\n";
        } else {
            echo "⚠️ $k 是孤岛配置（决策写出去没人读）\n";
            $checks['chain'] = 'warning';
        }
    } catch (Throwable $e) {
        echo "✗ $k 检查失败: {$e->getMessage()}\n";
        $checks['chain'] = false;
        $allPassed = false;
    }
}

echo "\n";

// ==================== 总结 ====================
echo "========================================\n";
echo "检查总结\n";
echo "========================================\n\n";

$totalChecks = 0;
$passedChecks = 0;
$failedChecks = 0;
$warningChecks = 0;

foreach ($checks as $category => $items) {
    if (is_array($items)) {
        foreach ($items as $key => $value) {
            $totalChecks++;
            if ($value === true) $passedChecks++;
            elseif ($value === false) $failedChecks++;
            else $warningChecks++;
        }
    } else {
        $totalChecks++;
        if ($items === true) $passedChecks++;
        elseif ($items === false) $failedChecks++;
        else $warningChecks++;
    }
}

echo "总检查项: {$totalChecks}\n";
echo "通过: {$passedChecks}\n";
echo "失败: {$failedChecks}\n";
echo "警告: {$warningChecks}\n\n";

if ($allPassed) {
    echo "✅ Agent机制自检通过!\n";
} else {
    echo "❌ Agent机制存在问题,请检查上述失败项\n";
}

echo "\n========================================\n";
echo "建议操作\n";
echo "========================================\n\n";

if (!$checks['config_center'] ?? false) {
    echo "1. 确保ConfigCenter类已正确加载:\n";
    echo "   检查 includes/config_center.php 文件是否存在\n";
    echo "   检查 config.php 是否包含 require_once 'includes/config_center.php'\n\n";
}

if (!$checks['tables']['agent_decision_logs'] ?? false) {
    echo "2. 运行数据库迁移:\n";
    echo "   重新安装系统或手动执行 migrations/002_agent_tables.sql\n\n";
}

if (!$checks['config']['agent.enabled'] ?? false) {
    echo "3. 检查Agent配置:\n";
    echo "   确保 system_settings 表中包含Agent相关配置\n\n";
}

if (!$checks['integration'] ?? false) {
    echo "4. 检查WriteEngine集成:\n";
    echo "   确保 write_engine.php 包含 runPreWriteAgents 方法\n\n";
}

if (!$checks['interval'] ?? false) {
    echo "5. 检查间隔触发逻辑:\n";
    echo "   确保 AgentCoordinator.php 已实现间隔触发\n\n";
}

if ($allPassed && $warningChecks == 0) {
    echo "✓ 所有检查通过,Agent机制运行正常!\n";
    echo "\n下一步:\n";
    echo "- 开始写作,观察Agent决策效果\n";
    echo "- 查看日志: SELECT * FROM agent_decision_logs ORDER BY created_at DESC LIMIT 10;\n";
}

echo "\n";