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
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/stats_tracker.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'report';

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
        $words = (int)($_POST['words'] ?? $_GET['words'] ?? 0);
        $chapters = (int)($_POST['chapters'] ?? $_GET['chapters'] ?? 1);

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

    case 'debug':
        $diag = [];
        $diag['table_exists'] = false;
        try {
            $check = DB::fetch("SHOW TABLES LIKE 'usage_stats'");
            $diag['table_exists'] = !empty($check);
        } catch (\Throwable $e) {
            $diag['table_exists_error'] = $e->getMessage();
        }

        if ($diag['table_exists']) {
            try {
                $diag['all_rows'] = DB::fetchAll("SELECT * FROM usage_stats ORDER BY id DESC LIMIT 5");
            } catch (\Throwable $e) {
                $diag['all_rows_error'] = $e->getMessage();
            }
        }

        try {
            $diag['insert_test'] = null;
            DB::insert('usage_stats', [
                'stat_date' => date('Y-m-d'),
                'words_added' => 0,
                'chapters_added' => 0,
                'novels_active' => 0,
            ]);
            $diag['insert_test'] = 'ok';
        } catch (\Throwable $e) {
            $diag['insert_test'] = 'failed: ' . $e->getMessage();
        }

        echo json_encode(['success' => true, 'diag' => $diag], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
