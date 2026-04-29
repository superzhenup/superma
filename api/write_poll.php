<?php
/**
 * 轮询写作进度
 * 
 * GET 或 POST: task_id
 * 
 * 返回: { ok, status, progress, content, messages, words, ... }
 */
ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
requireLoginApi();  // 启用 CSRF 保护（前端已自动携带 X-CSRF-Token）

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

try {
    // 支持 GET 和 POST 两种方式
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $taskId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['task_id'] ?? '');
    } else {
        $taskId = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['task_id'] ?? '');
    }
    if (!$taskId) throw new Exception('缺少任务ID');
    
    $progressDir = CFG_PROGRESS_DIR;
    $progressFile = $progressDir . '/' . $taskId . '.json';
    
    if (!file_exists($progressFile)) {
        throw new Exception('任务不存在或已过期');
    }
    
    // 读取进度文件（加锁防写冲突）
    $fp = fopen($progressFile, 'r');
    if (!$fp) throw new Exception('无法读取进度');
    flock($fp, LOCK_SH);
    $data = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    $progress = json_decode($data, true);
    if (!$progress) throw new Exception('进度数据异常');

    $finalStatus = $progress['status'] ?? 'unknown';

    // 任务已终态（done/completed/error）：返回结果后立即删除进度文件
    // 防止残留文件在下次 write_start 时被误判为"正在运行"
    if (in_array($finalStatus, ['done', 'completed', 'error'])) {
        @unlink($progressFile);
    } else {
        // 非终态：清理超过 5 分钟的过期进度文件（原30分钟太长）
        $now = time();
        foreach (glob($progressDir . '/w*.json') as $oldFile) {
            if ($oldFile === $progressFile) continue;  // 不删自己
            $fp2 = @fopen($oldFile, 'r');
            if (!$fp2) { @unlink($oldFile); @unlink(preg_replace('/\.json$/', '.sh', $oldFile)); continue; }
            flock($fp2, LOCK_SH);
            $d2 = stream_get_contents($fp2);
            flock($fp2, LOCK_UN);
            fclose($fp2);
            $p2 = json_decode($d2, true);
            $s2 = $p2['status'] ?? '';
            // 终态文件直接删，同时清理 wrapper.sh
            if (in_array($s2, ['done', 'completed', 'error'])) {
                @unlink($oldFile);
                @unlink(preg_replace('/\.json$/', '.sh', $oldFile));
            } elseif ($now - filemtime($oldFile) > CFG_PROGRESS_STALE) {
                // 超时无更新的非终态文件，视为僵死
                @unlink($oldFile);
                @unlink(preg_replace('/\.json$/', '.sh', $oldFile));
            }
        }
    }
    
    echo json_encode([
        'ok'       => true,
        'status'   => $finalStatus,
        'progress' => $progress['progress'] ?? 0,
        'content'  => $progress['content'] ?? '',
        'thinking_content' => $progress['thinking_content'] ?? '',
        'messages' => $progress['messages'] ?? [],
        'model_used' => $progress['model_used'] ?? null,
        'words'    => $progress['words'] ?? 0,
        'chapter_id' => $progress['chapter_id'] ?? null,
        'error'    => $progress['error'] ?? null,
        'updated_at' => $progress['updated_at'] ?? 0,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'ok'  => false,
        'msg' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
