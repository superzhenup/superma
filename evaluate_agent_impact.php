<?php
/**
 * Agent决策机制效果评估脚本
 * 
 * 功能:
 * 1. 对比Agent启用前后的质量变化
 * 2. 分析Agent决策效果
 * 3. 生成效果评估报告
 * 
 * 运行方式: php evaluate_agent_impact.php [novel_id]
 */

// 加载系统
define('APP_LOADED', true);
define('CLI_MODE', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "========================================\n";
echo "Agent决策机制效果评估\n";
echo "========================================\n\n";

// 获取小说ID
$novelId = isset($argv[1]) ? (int)$argv[1] : 1;

// 检查小说是否存在
$novel = DB::fetch('SELECT id, title, genre, created_at FROM novels WHERE id = ?', [$novelId]);
if (!$novel) {
    echo "错误: 小说ID {$novelId} 不存在\n";
    exit(1);
}

echo "评估小说: {$novel['title']} (ID: {$novelId})\n";
echo "创建时间: {$novel['created_at']}\n\n";

// 获取Agent启用时间(首次决策时间)
$agentStartTime = DB::fetch(
    'SELECT MIN(created_at) as first_decision FROM agent_decision_logs WHERE novel_id = ?',
    [$novelId]
);

$agentEnabledDate = $agentStartTime['first_decision'] ?? null;

if (!$agentEnabledDate) {
    echo "⚠ 该小说尚未运行Agent决策\n";
    echo "建议: 先运行几次写作,让Agent收集数据\n";
    exit(0);
}

echo "Agent启用时间: {$agentEnabledDate}\n\n";

// ==================== 评估1: 质量变化 ====================
echo "【评估1】质量变化\n";
echo "----------------------------------------\n";

$qualityBefore = DB::fetch(
    'SELECT 
        AVG(quality_score) as avg_score,
        COUNT(*) as chapter_count
     FROM chapters 
     WHERE novel_id = ? AND created_at < ? AND quality_score IS NOT NULL',
    [$novelId, $agentEnabledDate]
);

$qualityAfter = DB::fetch(
    'SELECT 
        AVG(quality_score) as avg_score,
        COUNT(*) as chapter_count
     FROM chapters 
     WHERE novel_id = ? AND created_at >= ? AND quality_score IS NOT NULL',
    [$novelId, $agentEnabledDate]
);

if ($qualityBefore['chapter_count'] > 0 && $qualityAfter['chapter_count'] > 0) {
    $beforeScore = round($qualityBefore['avg_score'], 1);
    $afterScore = round($qualityAfter['avg_score'], 1);
    $improvement = round(($afterScore - $beforeScore) / $beforeScore * 100, 1);
    
    echo "Agent启用前 ({$qualityBefore['chapter_count']}章): {$beforeScore}分\n";
    echo "Agent启用后 ({$qualityAfter['chapter_count']}章): {$afterScore}分\n";
    echo "变化: " . ($improvement >= 0 ? "+" : "") . "{$improvement}%\n";
    
    if ($improvement > 5) {
        echo "✅ 质量显著提升!\n";
    } elseif ($improvement > 0) {
        echo "✓ 质量有所提升\n";
    } elseif ($improvement > -5) {
        echo "⚠ 质量基本持平\n";
    } else {
        echo "✗ 质量有所下降\n";
    }
} else {
    echo "⚠ 数据不足,无法对比\n";
}

echo "\n";

// ==================== 评估2: 字数准确率 ====================
echo "【评估2】字数准确率\n";
echo "----------------------------------------\n";

$targetWords = (int)DB::fetch(
    'SELECT chapter_words FROM novels WHERE id = ?',
    [$novelId]
)['chapter_words'] ?? 3000;

$wordAccuracyBefore = DB::fetch(
    'SELECT words FROM chapters 
     WHERE novel_id = ? AND created_at < ? AND status = "completed"
     ORDER BY chapter_number DESC LIMIT 10',
    [$novelId, $agentEnabledDate]
);

$wordAccuracyAfter = DB::fetch(
    'SELECT words FROM chapters 
     WHERE novel_id = ? AND created_at >= ? AND status = "completed"
     ORDER BY chapter_number DESC LIMIT 10',
    [$novelId, $agentEnabledDate]
);

// 计算准确率
function calculateWordAccuracy($chapters, $target, $tolerance = 0.1) {
    if (empty($chapters)) return null;
    
    $chapters = is_array($chapters[0] ?? null) ? $chapters : [$chapters];
    $accurate = 0;
    
    foreach ($chapters as $ch) {
        $actual = (int)($ch['words'] ?? 0);
        if (abs($actual - $target) <= $target * $tolerance) {
            $accurate++;
        }
    }
    
    return count($chapters) > 0 ? round($accurate / count($chapters) * 100, 1) : null;
}

$accuracyBefore = calculateWordAccuracy($wordAccuracyBefore, $targetWords);
$accuracyAfter = calculateWordAccuracy($wordAccuracyAfter, $targetWords);

if ($accuracyBefore !== null && $accuracyAfter !== null) {
    $improvement = round($accuracyAfter - $accuracyBefore, 1);
    
    echo "Agent启用前: {$accuracyBefore}%\n";
    echo "Agent启用后: {$accuracyAfter}%\n";
    echo "变化: " . ($improvement >= 0 ? "+" : "") . "{$improvement}%\n";
    
    if ($improvement > 10) {
        echo "✅ 字数控制显著改善!\n";
    } elseif ($improvement > 0) {
        echo "✓ 字数控制有所改善\n";
    } else {
        echo "⚠ 字数控制无改善\n";
    }
} else {
    echo "⚠ 数据不足,无法对比\n";
}

echo "\n";

// ==================== 评估3: Agent决策统计 ====================
echo "【评估3】Agent决策统计\n";
echo "----------------------------------------\n";

$decisionStats = DB::fetchAll(
    'SELECT 
        agent_type,
        COUNT(*) as decision_count,
        AVG(JSON_LENGTH(decision_data)) as avg_decision_size
     FROM agent_decision_logs
     WHERE novel_id = ? OR novel_id IS NULL
     GROUP BY agent_type',
    [$novelId]
);

if ($decisionStats) {
    foreach ($decisionStats as $stat) {
        echo "{$stat['agent_type']}:\n";
        echo "  决策次数: {$stat['decision_count']}\n";
        echo "  平均决策复杂度: " . round($stat['avg_decision_size'], 1) . "\n";
    }
} else {
    echo "暂无决策统计\n";
}

echo "\n";

// ==================== 评估4: Agent动作效果 ====================
echo "【评估4】Agent动作效果\n";
echo "----------------------------------------\n";

$actionStats = DB::fetch(
    'SELECT 
        COUNT(*) as total_actions,
        SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success,
        SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
     FROM agent_action_logs
     WHERE novel_id = ?',
    [$novelId]
);

if ($actionStats && $actionStats['total_actions'] > 0) {
    $successRate = round($actionStats['success'] / $actionStats['total_actions'] * 100, 1);
    
    echo "总动作数: {$actionStats['total_actions']}\n";
    echo "成功: {$actionStats['success']} ({$successRate}%)\n";
    echo "失败: {$actionStats['failed']}\n";
    
    if ($successRate >= 95) {
        echo "✅ 动作执行成功率优秀!\n";
    } elseif ($successRate >= 80) {
        echo "✓ 动作执行成功率良好\n";
    } else {
        echo "⚠ 动作执行成功率偏低\n";
    }
} else {
    echo "暂无动作记录\n";
}

echo "\n";

// ==================== 评估5: 性能影响 ====================
echo "【评估5】性能影响\n";
echo "----------------------------------------\n";

$perfBefore = DB::fetch(
    'SELECT AVG(duration_ms) as avg_time 
     FROM chapters 
     WHERE novel_id = ? AND status = "completed" AND created_at < ?
     AND duration_ms IS NOT NULL',
    [$novelId, $agentEnabledDate]
);

$perfAfter = DB::fetch(
    'SELECT AVG(duration_ms) as avg_time 
     FROM chapters 
     WHERE novel_id = ? AND status = "completed" AND created_at >= ?
     AND duration_ms IS NOT NULL',
    [$novelId, $agentEnabledDate]
);

if ($perfBefore['avg_time'] && $perfAfter['avg_time']) {
    $beforeTime = round($perfBefore['avg_time'] / 1000, 1);
    $afterTime = round($perfAfter['avg_time'] / 1000, 1);
    $change = round(($afterTime - $beforeTime) / $beforeTime * 100, 1);
    
    echo "Agent启用前平均写作时间: {$beforeTime}秒\n";
    echo "Agent启用后平均写作时间: {$afterTime}秒\n";
    echo "变化: " . ($change >= 0 ? "+" : "") . "{$change}%\n";
    
    if (abs($change) <= 10) {
        echo "✓ 性能影响可接受(±10%)\n";
    } else {
        echo "⚠ 性能影响较大\n";
    }
} else {
    echo "⚠ 性能数据不足\n";
}

echo "\n";

// ==================== 评估6: 问题发现与修复 ====================
echo "【评估6】问题发现与修复\n";
echo "----------------------------------------\n";

$issuesFound = DB::fetch(
    'SELECT COUNT(*) as total FROM agent_decision_logs
     WHERE agent_type = "quality_monitor" AND novel_id = ?
     AND JSON_EXTRACT(decision_data, "$.issues") IS NOT NULL',
    [$novelId]
);

$issuesFixed = DB::fetch(
    'SELECT COUNT(*) as total FROM agent_action_logs
     WHERE agent_type = "quality_monitor" AND novel_id = ? AND status = "success"',
    [$novelId]
);

if ($issuesFound['total'] > 0) {
    $foundCount = $issuesFound['total'];
    $fixedCount = $issuesFixed['total'];
    $fixRate = round($fixedCount / $foundCount * 100, 1);
    
    echo "发现问题: {$foundCount}次\n";
    echo "修复动作: {$fixedCount}次\n";
    echo "修复率: {$fixRate}%\n";
    
    if ($fixRate >= 80) {
        echo "✅ 问题修复率高!\n";
    } else {
        echo "⚠ 部分问题未修复\n";
    }
} else {
    echo "暂未发现质量问题\n";
}

echo "\n";

// ==================== 综合评分 ====================
echo "========================================\n";
echo "综合评估\n";
echo "========================================\n\n";

$scores = [];

// 质量评分
if (isset($improvement) && $improvement > 5) {
    $scores['quality'] = 90;
} elseif (isset($improvement) && $improvement > 0) {
    $scores['quality'] = 70;
} else {
    $scores['quality'] = 50;
}

// 字数准确率评分
if (isset($accuracyAfter) && $accuracyAfter >= 90) {
    $scores['word_accuracy'] = 90;
} elseif (isset($accuracyAfter) && $accuracyAfter >= 80) {
    $scores['word_accuracy'] = 70;
} else {
    $scores['word_accuracy'] = 50;
}

// 动作成功率评分
if (isset($successRate) && $successRate >= 95) {
    $scores['action_success'] = 90;
} elseif (isset($successRate) && $successRate >= 80) {
    $scores['action_success'] = 70;
} else {
    $scores['action_success'] = 50;
}

// 性能评分
if (isset($change) && abs($change) <= 5) {
    $scores['performance'] = 90;
} elseif (isset($change) && abs($change) <= 10) {
    $scores['performance'] = 70;
} else {
    $scores['performance'] = 50;
}

// 计算总分
$totalScore = round(array_sum($scores) / count($scores), 1);

echo "评估维度:\n";
foreach ($scores as $dimension => $score) {
    echo "  {$dimension}: {$score}分\n";
}
echo "\n综合得分: {$totalScore}/100\n\n";

if ($totalScore >= 80) {
    echo "✅ Agent效果优秀!建议继续使用并优化参数。\n";
} elseif ($totalScore >= 60) {
    echo "✓ Agent效果良好,可继续使用。\n";
} else {
    echo "⚠ Agent效果一般,建议调整参数或检查配置。\n";
}

echo "\n========================================\n";
echo "建议\n";
echo "========================================\n\n";

if ($totalScore < 80) {
    echo "优化建议:\n";
    
    if ($scores['quality'] < 70) {
        echo "- 提高quality_agent.check_interval频率\n";
        echo "- 启用agent.quality_agent.auto_fix\n";
    }
    
    if ($scores['word_accuracy'] < 70) {
        echo "- 调整ws_chapter_word_tolerance_ratio参数\n";
        echo "- 检查WritingStrategyAgent决策\n";
    }
    
    if ($scores['action_success'] < 70) {
        echo "- 检查ConfigCenter实现\n";
        echo "- 查看失败动作日志\n";
    }
    
    if ($scores['performance'] < 70) {
        echo "- 增加agent.*.decision_interval间隔\n";
        echo "- 禁用部分Agent\n";
    }
} else {
    echo "✓ 当前配置良好,建议:\n";
    echo "- 继续监控Agent效果\n";
    echo "- 定期清理历史日志\n";
    echo "- 根据数据微调参数\n";
}

echo "\n";
