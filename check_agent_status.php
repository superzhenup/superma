<?php
/**
 * Agent决策机制检查脚本
 * 
 * 功能:
 * 1. 检查Agent是否正常工作
 * 2. 验证数据库表是否存在
 * 3. 检查配置是否正确
 * 4. 统计Agent运行状态
 * 
 * 运行方式: php check_agent_status.php
 */

// 加载系统
define('APP_LOADED', true);
define('CLI_MODE', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "========================================\n";
echo "Agent决策机制状态检查\n";
echo "========================================\n\n";

$checks = [];
$allPassed = true;

// ==================== 检查1: 数据库表 ====================
echo "【检查1】数据库表\n";
echo "----------------------------------------\n";

$tables = ['agent_decision_logs', 'agent_action_logs', 'agent_performance_stats'];

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
    } catch (Exception $e) {
        echo "✗ {$table} 检查失败: {$e->getMessage()}\n";
        $checks['tables'][$table] = false;
        $allPassed = false;
    }
}

echo "\n";

// ==================== 检查2: 配置项 ====================
echo "【检查2】配置项\n";
echo "----------------------------------------\n";

$configKeys = [
    'agent.enabled' => 'bool',
    'agent.strategy_agent.enabled' => 'bool',
    'agent.strategy_agent.decision_interval' => 'int',
    'agent.quality_agent.enabled' => 'bool',
    'agent.quality_agent.check_interval' => 'int',
    'agent.quality_agent.auto_fix' => 'bool',
    'agent.optimization_agent.enabled' => 'bool',
    'agent.optimization_agent.optimization_interval' => 'int',
];

foreach ($configKeys as $key => $type) {
    try {
        $result = DB::fetch(
            'SELECT setting_value FROM system_settings WHERE setting_key = ?',
            [$key]
        );
        
        if ($result) {
            $value = $result['setting_value'];
            echo "✓ {$key} = {$value}\n";
            $checks['config'][$key] = true;
        } else {
            echo "✗ {$key} 未配置\n";
            $checks['config'][$key] = false;
            $allPassed = false;
        }
    } catch (Exception $e) {
        echo "✗ {$key} 检查失败\n";
        $checks['config'][$key] = false;
        $allPassed = false;
    }
}

echo "\n";

// ==================== 检查3: Agent文件 ====================
echo "【检查3】Agent文件\n";
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

// ==================== 检查4: 决策日志统计 ====================
echo "【检查4】决策日志统计\n";
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
} catch (Exception $e) {
    echo "✗ 决策日志查询失败: {$e->getMessage()}\n";
    $checks['logs']['decision'] = false;
}

echo "\n";

// ==================== 检查5: 动作日志统计 ====================
echo "【检查5】动作日志统计\n";
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
} catch (Exception $e) {
    echo "✗ 动作日志查询失败: {$e->getMessage()}\n";
    $checks['logs']['action'] = false;
}

echo "\n";

// ==================== 检查6: 最近决策详情 ====================
echo "【检查6】最近决策详情\n";
echo "----------------------------------------\n";

try {
    $recentDecisions = DB::fetchAll(
        'SELECT agent_type, decision_data, created_at 
         FROM agent_decision_logs 
         ORDER BY created_at DESC 
         LIMIT 3'
    );
    
    if ($recentDecisions) {
        foreach ($recentDecisions as $decision) {
            echo "Agent: {$decision['agent_type']}\n";
            echo "时间: {$decision['created_at']}\n";
            
            $data = json_decode($decision['decision_data'], true);
            if (isset($data['execution_time_ms'])) {
                echo "耗时: " . round($data['execution_time_ms'], 2) . "ms\n";
            }
            if (isset($data['decisions'])) {
                $decisionCount = count(array_filter($data['decisions']));
                echo "决策数: {$decisionCount}\n";
            }
            echo "\n";
        }
        $checks['recent'] = true;
    } else {
        echo "⚠ 暂无决策记录\n";
        $checks['recent'] = null;
    }
} catch (Exception $e) {
    echo "✗ 最近决策查询失败: {$e->getMessage()}\n";
    $checks['recent'] = false;
}

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

// ==================== 检查8: ConfigCenter ====================
echo "【检查8】ConfigCenter依赖\n";
echo "----------------------------------------\n";

$configCenterFile = __DIR__ . '/includes/config_center.php';
if (file_exists($configCenterFile)) {
    echo "✓ ConfigCenter文件存在\n";
    
    // 检查类是否存在
    require_once $configCenterFile;
    if (class_exists('ConfigCenter')) {
        echo "✓ ConfigCenter类可用\n";
        $checks['config_center'] = true;
    } else {
        echo "✗ ConfigCenter类不存在\n";
        $checks['config_center'] = false;
        $allPassed = false;
    }
} else {
    echo "⚠ ConfigCenter文件不存在(将使用降级方案)\n";
    $checks['config_center'] = null;
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
    foreach ($items as $key => $value) {
        $totalChecks++;
        if ($value === true) $passedChecks++;
        elseif ($value === false) $failedChecks++;
        else $warningChecks++;
    }
}

echo "总检查项: {$totalChecks}\n";
echo "通过: {$passedChecks}\n";
echo "失败: {$failedChecks}\n";
echo "警告: {$warningChecks}\n\n";

if ($allPassed) {
    echo "✅ Agent决策机制状态良好!\n";
} else {
    echo "❌ Agent决策机制存在问题,请检查上述失败项\n";
}

echo "\n========================================\n";
echo "建议操作\n";
echo "========================================\n\n";

if (!$checks['tables']['agent_decision_logs'] ?? false) {
    echo "1. 运行数据库迁移:\n";
    echo "   mysql -u ai_novel -p ai_novel < migrations/002_agent_tables.sql\n\n";
}

if (!$checks['config']['agent.enabled'] ?? false) {
    echo "2. 插入Agent配置:\n";
    echo "   INSERT INTO system_settings (setting_key, setting_value, setting_type) VALUES\n";
    echo "   ('agent.enabled', '1', 'bool');\n\n";
}

if (!$checks['integration'] ?? false) {
    echo "3. 更新WriteEngine文件,集成Agent决策\n\n";
}

if ($allPassed && $warningChecks == 0) {
    echo "✓ 所有检查通过,Agent机制运行正常!\n";
    echo "\n下一步:\n";
    echo "- 运行测试: php test_agents.php\n";
    echo "- 开始写作,观察Agent决策效果\n";
    echo "- 查看日志: SELECT * FROM agent_decision_logs ORDER BY created_at DESC LIMIT 10;\n";
}

echo "\n";
