<?php
/**
 * 漫剧任务驱动 + 监控脚本(宝塔定时任务专用)
 *
 * 用途:
 *   1. 宝塔定时任务每分钟访问本脚本,同步处理 1-3 个任务(替代持续 worker)
 *   2. 提供取消任务接口(取消所有 pending/running)
 *   3. 提供状态查询接口(只读)
 *
 * 访问方式:
 *   - 处理任务: /tmp_drama_cron.php?token=drama-cron-2026-07-26
 *   - 仅查状态: /tmp_drama_cron.php?token=drama-cron-2026-07-26&action=status
 *   - 取消任务: /tmp_drama_cron.php?token=drama-cron-2026-07-26&action=cancel
 *   - 取消某项目: /tmp_drama_cron.php?token=drama-cron-2026-07-26&action=cancel&project_id=1
 *   - 自定义每次处理数: /tmp_drama_cron.php?token=drama-cron-2026-07-26&batch=3
 *
 * 宝塔定时任务配置:
 *   - 类型: 访问 URL
 *   - URL: http://120.48.9.89/tmp_drama_cron.php?token=drama-cron-2026-07-26
 *   - 频率: 每 1 分钟(N分钟)
 *
 * 用完(不再需要时)请删除本脚本。
 */
define('APP_LOADED', true);

// ===== Token 验证 =====
$expectedToken = 'drama-cron-2026-07-26';
$gotToken = $_GET['token'] ?? '';
if (!hash_equals($expectedToken, $gotToken)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'token 无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/drama/DramaTaskRunner.php';
require_once __DIR__ . '/includes/drama/DramaTaskRepository.php';

header('Content-Type: application/json; charset=utf-8');

// 提升 CLI 模式下无限制,Web 模式下给 240 秒(单任务通常 30-60s,处理 3 个够用)
@set_time_limit(240);
@ini_set('display_errors', '0');

$action = $_GET['action'] ?? 'run';     // run / status / cancel
$batchSize = max(1, min(5, (int)($_GET['batch'] ?? 3)));  // 每次最多处理 5 个
$projectId = (int)($_GET['project_id'] ?? 0);

try {
    $pdo = DB::getPdo();

    // ===== action=status: 仅查状态 =====
    if ($action === 'status') {
        $stats = $pdo->query("SELECT status, COUNT(*) AS cnt FROM drama_tasks GROUP BY status")
            ->fetchAll(PDO::FETCH_ASSOC);
        $statusCount = [];
        foreach ($stats as $s) $statusCount[$s['status']] = (int)$s['cnt'];

        $active = $pdo->query("SELECT id, project_id, episode_id, type, status, progress, attempts, max_attempts,
            lease_expires_at, run_after, updated_at, error
            FROM drama_tasks
            WHERE status IN ('pending','running','failed')
            ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($active as &$t) {
            $t['age_seconds'] = $now - strtotime($t['updated_at']);
            $t['run_after_passed'] = strtotime($t['run_after']) <= $now;
            if (!empty($t['lease_expires_at'])) {
                $t['lease_expired'] = strtotime($t['lease_expires_at']) < $now;
            }
            unset($t['lease_expires_at']);
        }
        unset($t);

        echo json_encode([
            'ok' => true,
            'action' => 'status',
            'stats' => $statusCount,
            'active_tasks' => $active,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ===== action=cancel: 取消任务 =====
    if ($action === 'cancel') {
        // 先取消 running(租约过期或强制取消)再取消 pending
        $projectFilter = $projectId > 0 ? " AND project_id={$projectId}" : '';

        // 取消 pending
        $cancelPending = $pdo->exec("UPDATE drama_tasks
            SET status='canceled', lease_owner=NULL, lease_expires_at=NULL, error='手动取消'
            WHERE status='pending'{$projectFilter}");

        // 强制取消 running(谨慎:若 worker 仍在跑可能产生孤立结果,但本项目无持续 worker,安全)
        $cancelRunning = $pdo->exec("UPDATE drama_tasks
            SET status='canceled', lease_owner=NULL, lease_expires_at=NULL, error='手动取消(running 强制)'
            WHERE status='running'{$projectFilter}");

        echo json_encode([
            'ok' => true,
            'action' => 'cancel',
            'canceled_pending' => $cancelPending,
            'canceled_running' => $cancelRunning,
            'msg' => "已取消 {$cancelPending} 个 pending + {$cancelRunning} 个 running 任务",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== action=run(默认): 同步处理任务 =====
    $processed = [];
    $errors = [];
    $workerId = 'drama-cron:' . (gethostname() ?: 'localhost') . ':' . getmypid();
    $startTime = microtime(true);

    for ($i = 0; $i < $batchSize; $i++) {
        // 单任务超时保护:剩余时间不足 30s 时停止领取新任务
        $elapsed = microtime(true) - $startTime;
        if ($elapsed > 210) break;  // 预留 30s 给当前任务收尾

        try {
            $result = DramaTaskRunner::runNext($workerId);
            if ($result === null) {
                // 队列空了,提前结束
                break;
            }
            $processed[] = [
                'task_id' => $result['task_id'] ?? null,
                'type' => $result['type'] ?? null,
                'state' => $result['state'] ?? null,
                'error' => $result['error'] ?? null,
            ];
            if (($result['state'] ?? '') === 'failed') {
                $errors[] = "Task #{$result['task_id']}: " . ($result['error'] ?? '未知错误');
            }
        } catch (Throwable $e) {
            $errors[] = "迭代 {$i} 异常: " . $e->getMessage();
            // 单任务异常不中断整批
        }
    }

    // 返回剩余活动任务数
    $remaining = $pdo->query("SELECT COUNT(*) FROM drama_tasks WHERE status IN ('pending','running')")
        ->fetchColumn();

    echo json_encode([
        'ok' => true,
        'action' => 'run',
        'processed_count' => count($processed),
        'processed' => $processed,
        'errors' => $errors,
        'remaining_active' => (int)$remaining,
        'elapsed_ms' => round((microtime(true) - $startTime) * 1000),
        'server_time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    error_log('tmp_drama_cron error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => '内部错误: ' . $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
