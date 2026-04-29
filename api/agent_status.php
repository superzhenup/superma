<?php
/**
 * Agent 决策状态 API
 * 
 * GET/POST: { novel_id }
 * 返回: { ok, stats, timeline, directives, outcomes }
 * 
 * 为 novel.php 的 Agent 决策面板提供数据。
 */

ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
requireLoginApi();

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

try {
    // 支持 GET 和 POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action  = $input['action'] ?? '';
        $novelId = (int)($input['novel_id'] ?? 0);

        // ── 停用指令 ────────────────────────────────────────
        if ($action === 'deactivate') {
            $directiveId = (int)($input['directive_id'] ?? 0);
            if (!$directiveId) {
                throw new Exception('缺少 directive_id 参数');
            }
            require_once dirname(__DIR__) . '/includes/agents/AgentDirectives.php';
            $ok = AgentDirectives::deactivate($directiveId);
            echo json_encode(['ok' => $ok, 'msg' => $ok ? '指令已停用' : '停用失败']);
            exit;
        }
    } else {
        $novelId = (int)($_GET['novel_id'] ?? 0);
    }

    if (!$novelId) {
        throw new Exception('缺少 novel_id 参数');
    }

    // 验证小说存在
    $novel = DB::fetch('SELECT id, title FROM novels WHERE id = ?', [$novelId]);
    if (!$novel) {
        throw new Exception('小说不存在');
    }

    // 加载 AgentDirectives
    require_once dirname(__DIR__) . '/includes/agents/AgentDirectives.php';

    // ── 1. 汇总统计 ────────────────────────────────────────────
    // 总决策次数：从 agent_action_logs 统计
    $totalDecisions = (int)DB::fetchColumn(
        'SELECT COUNT(*) FROM agent_action_logs WHERE novel_id = ? AND agent_type IS NOT NULL',
        [$novelId]
    );

    // 活跃指令数
    $activeDirectives = AgentDirectives::allActive($novelId);
    $activeCount = count($activeDirectives);

    // 指令有效率：有正向改善的比例
    $effectivenessRow = DB::fetch(
        'SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN o.quality_change > 0 THEN 1 ELSE 0 END) as improved,
            AVG(o.quality_change) as avg_change
         FROM agent_directive_outcomes o
         WHERE o.novel_id = ?',
        [$novelId]
    );
    $totalOutcomes = (int)($effectivenessRow['total'] ?? 0);
    $improvedOutcomes = (int)($effectivenessRow['improved'] ?? 0);
    $successRate = $totalOutcomes > 0 ? round(($improvedOutcomes / $totalOutcomes) * 100, 1) : 0;
    $avgImprovement = $effectivenessRow['avg_change'] !== null
        ? round((float)$effectivenessRow['avg_change'], 1)
        : 0;

    $stats = [
        'total_decisions'    => $totalDecisions,
        'success_rate'       => $successRate,
        'active_directives'  => $activeCount,
        'avg_improvement'    => $avgImprovement,
    ];

    // ── 2. 决策时间线 ──────────────────────────────────────────
    // 合并 agent_action_logs（按 novel_id 筛选）和 agent_decision_logs（全局）
    $actionLogs = DB::fetchAll(
        'SELECT 
            agent_type, 
            action, 
            status, 
            params, 
            created_at,
            "action" as log_type
         FROM agent_action_logs 
         WHERE novel_id = ? 
         ORDER BY created_at DESC 
         LIMIT 30',
        [$novelId]
    ) ?: [];

    $decisionLogs = DB::fetchAll(
        'SELECT 
            agent_type, 
            decision_data, 
            created_at,
            "decision" as log_type
         FROM agent_decision_logs 
         ORDER BY created_at DESC 
         LIMIT 20'
    ) ?: [];

    // 合并后按时间排序
    $timeline = [];
    foreach ($actionLogs as $log) {
        $params = $log['params'] ? json_decode($log['params'], true) : null;
        $timeline[] = [
            'agent_type' => $log['agent_type'],
            'type'       => 'action',
            'action'     => $log['action'],
            'status'     => $log['status'],
            'detail'     => $params,
            'created_at' => $log['created_at'],
            'ts'         => strtotime($log['created_at']),
        ];
    }
    foreach ($decisionLogs as $log) {
        $data = $log['decision_data'] ? json_decode($log['decision_data'], true) : null;
        $timeline[] = [
            'agent_type' => $log['agent_type'],
            'type'       => 'decision',
            'action'     => $data['action'] ?? ($data['type'] ?? '决策'),
            'status'     => 'decided',
            'detail'     => $data,
            'created_at' => $log['created_at'],
            'ts'         => strtotime($log['created_at']),
        ];
    }

    // 按时间倒序
    usort($timeline, fn($a, $b) => $b['ts'] - $a['ts']);
    $timeline = array_slice($timeline, 0, 50);

    // ── 3. 活跃指令 ────────────────────────────────────────────
    $directives = array_map(function ($d) {
        return [
            'id'            => (int)$d['id'],
            'type'          => $d['type'],
            'directive'     => $d['directive'],
            'apply_from'    => (int)$d['apply_from'],
            'apply_to'      => (int)$d['apply_to'],
            'created_at'    => $d['created_at'],
            'expires_at'    => $d['expires_at'],
            'is_active'     => (int)$d['is_active'],
        ];
    }, $activeDirectives);

    // ── 4. 效果分析 ────────────────────────────────────────────
    $outcomeStats = AgentDirectives::getOutcomeStats($novelId);
    $recentOutcomes = AgentDirectives::getOutcomes($novelId, ['limit' => 30]);

    echo json_encode([
        'ok'         => true,
        'stats'      => $stats,
        'timeline'   => $timeline,
        'directives' => $directives,
        'outcomes'   => [
            'by_type'       => $outcomeStats['by_type'] ?: [],
            'top_effective' => $outcomeStats['top_effective'] ?: [],
            'top_harmful'   => $outcomeStats['top_harmful'] ?: [],
            'recent'        => $recentOutcomes ?: [],
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'ok'  => false,
        'msg' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
