<?php
/**
 * 统计上报 API
 *
 * 触发方式：
 * 1. 定时任务（推荐）：每天凌晨自动调用
 * 2. 手动触发：访问此接口
 * 3. 页面加载时检查：在任意页面加载时检查是否需要上报
 */

ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
requireHttpMethod('POST');
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/stats_tracker.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? 'report';

switch ($action) {
    case 'report':
        // 执行上报
        if (!StatsTracker::shouldReport()) {
            echo json_encode([
                'success' => true,
                'message' => 'No pending stats to report',
            ]);
            exit;
        }

        $result = StatsTracker::report();
        echo json_encode($result);
        break;

    case 'record':
        $words = (int)($input['words'] ?? $_POST['words'] ?? 0);
        $chapters = (int)($input['chapters'] ?? $_POST['chapters'] ?? 1);

        if ($words <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid words count']);
            exit;
        }

        StatsTracker::record($words, $chapters);
        $verify = StatsTracker::getSummary();
        echo json_encode([
            'success' => true,
            'message' => 'Recorded',
            'verify_today' => $verify['today'] ?? null,
        ]);
        break;

    case 'summary':
        // 获取统计摘要
        $summary = StatsTracker::getSummary();
        echo json_encode([
            'success' => true,
            'data' => $summary,
        ]);
        break;

    case 'cleanup':
        // 清理历史数据
        $count = StatsTracker::cleanup();
        echo json_encode([
            'success' => true,
            'message' => "Cleaned up {$count} old records",
        ]);
        break;

    case 'check':
        // 检查上报状态
        $shouldReport = StatsTracker::shouldReport();
        $pending = StatsTracker::getPendingStats();
        $enabled = StatsTracker::isEnabled();

        echo json_encode([
            'success' => true,
            'enabled' => $enabled,
            'should_report' => $shouldReport,
            'pending_stats' => $pending,
            'site_id' => StatsTracker::getSiteId(),
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
