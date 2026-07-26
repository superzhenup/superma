<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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
require_once dirname(__DIR__) . '/includes/helpers.php';  // 修复（2026-06-18）：taskIdSignature() 定义在此文件
require_once dirname(__DIR__) . '/includes/tasks/WritingTaskRepository.php';
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
    if (!is_file($progressFile)) {
        // v53：兼容文件丢失/清理后，仍可从持久任务表恢复终态与错误原因。
        $durableTask = WritingTaskRepository::findByTaskId($taskId);
        if (!$durableTask) throw new Exception('任务不存在或已结束');
        $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
        if ((int)$durableTask['user_id'] !== $currentUserId) {
            throw new Exception('无权访问此任务');
        }
        checkNovelOwnership((int)$durableTask['novel_id'], $currentUserId);
        echo json_encode(WritingTaskRepository::toPollPayload($durableTask), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 审计修复 H-4（2026-06-17）：读进度前先校验 task_id 归属
    // 旧实现任意已登录用户可读任意 task_id 的进度（含未发布章节正文）
    $fp = fopen($progressFile, 'r');
    if ($fp && flock($fp, LOCK_SH)) {
        $data = json_decode(stream_get_contents($fp), true) ?: [];
        flock($fp, LOCK_UN);
        fclose($fp);
        $ownerUserId = (int)($data['user_id'] ?? 0);
        $novelIdIn   = (int)($data['novel_id'] ?? 0);
        if ($ownerUserId <= 0) {
            // 旧版（无 user_id 字段）的进度文件 —— 不做 HMAC 校验但仅暴露状态
            // 此为兼容性窗口，新文件都含 user_id；管理员后续可清理旧文件
            $ownerUserId = 0;
        } else {
            $currentUserId = (int)($_SESSION['user_id'] ?? 0);
            if ($currentUserId !== $ownerUserId) {
                throw new Exception('无权访问此任务');
            }
            // 进一步用 HMAC 校验（防 task_id 伪造或文件被篡改）
            $sig = (string)($data['task_sig'] ?? '');
            $expectedSig = taskIdSignature($taskId, $currentUserId, $novelIdIn);
            if ($sig === '' || !hash_equals($expectedSig, $sig)) {
                throw new Exception('任务签名校验失败');
            }
        }
    }

    if (!file_exists($progressFile)) {
        throw new Exception('任务不存在或已过期');
    }

    // APCu 内存缓存层：高频轮询下避免反复磁盘 IO。
    // 缓存键包含文件 mtime（秒级），文件一更新自动失效；无 TTL 或很短（5秒）
    $apcuAvailable = function_exists('apcu_enabled') && apcu_enabled();
    $fileMtime = @filemtime($progressFile);
    $cacheKey = 'write_poll:' . $taskId . ':' . $fileMtime;
    $progress = null;

    if ($apcuAvailable) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false && is_array($cached)) {
            $progress = $cached;
        }
    }

    if ($progress === null) {
        // 读取进度文件（加锁防写冲突）
        $fp = fopen($progressFile, 'r');
        if (!$fp) throw new Exception('无法读取进度');
        flock($fp, LOCK_SH);
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $progress = json_decode($data, true);
        if (!$progress) throw new Exception('进度数据异常');

        if ($apcuAvailable) {
            apcu_store($cacheKey, $progress, 5);
        }
    }

    // 审计修复 H1（2026-06-12）：轮询同样需要小说归属校验，
    // 否则任意已登录用户可枚举 task_id 窥探/复用他人的写作进度。
    $pollNovelId = (int)($progress['novel_id'] ?? 0);
    if ($pollNovelId > 0) {
        $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
        checkNovelOwnership($pollNovelId, $userId);
    }

    $finalStatus = $progress['status'] ?? 'unknown';

    // 任务已终态（done/error）：延迟删除进度文件
    // 不再首次读取即删——若客户端网络丢包将永久丢失结果
    // 改为：终态文件保留至下次 write_start 的僵死检测清理，或超时后自动清理
    // 仅在终态超过 60 秒后才删除，确保客户端有足够时间轮询获取结果
    // Task 2：completed/saved/postprocessing 不再视为任务终态——后处理进行中绝不能删
    // 进度文件，否则前端在 postProcess 完成前就丢失轮询目标。
    if (in_array($finalStatus, ['done', 'error'])) {
        $updatedAt = (int)($progress['updated_at'] ?? 0);
        $startedAt = (int)($progress['started_at'] ?? 0);
        $refTime = $updatedAt > 0 ? $updatedAt : $startedAt;
        // 终态超过 60 秒才删除
        if ($refTime > 0 && (time() - $refTime) > 60) {
            @unlink($progressFile);
            @unlink($progressFile . '.content');  // W-17 拆分内容文件一并清理（worker 中途崩溃时残留）
        }
    } else {
        // 非终态：降低清理频率，每 30 秒才执行一次全目录扫描
        // 避免高频轮询（每秒1次）时反复 glob + 读文件造成不必要的磁盘 IO
        $now = time();
        $lastCleanupKey = 'write_poll_cleanup:' . $taskId;
        $lastCleanup = $apcuAvailable ? apcu_fetch($lastCleanupKey) : false;

        if (!$lastCleanup || ($now - (int)$lastCleanup) > 30) {
            // 记录本次清理时间
            if ($apcuAvailable) {
                apcu_store($lastCleanupKey, $now, 120);
            }

            // 审计修复 A05（2026-06-16）：cleanup 优化
            // 原实现对所有 w*.json 文件都做 flock+解析，高频轮询下 IO 突发。
            // 现先用 filemtime 预筛，跳过活跃任务（mtime 在 CFG_PROGRESS_STALE 内的文件），
            // 只对"可能僵死"或"已终态"的旧文件做 flock 读取，减少 90%+ 的 IO。
            foreach (glob($progressDir . '/w*.json') as $oldFile) {
                if ($oldFile === $progressFile) continue;  // 不删自己

                $mtime = @filemtime($oldFile);
                if ($mtime === false) continue;  // 文件已不存在

                $age = $now - $mtime;
                // 活跃文件（近期有更新）跳过，避免干扰正在写作的任务
                if ($age < CFG_PROGRESS_STALE) continue;

                // 旧文件：尝试读取状态判断是否终态
                $fp2 = @fopen($oldFile, 'r');
                if (!$fp2) {
                    @unlink($oldFile);
                    @unlink(preg_replace('/\.json$/', '.sh', $oldFile));
                    @unlink($oldFile . '.content');  // W-17 拆分内容文件一并清理
                    continue;
                }
                flock($fp2, LOCK_SH);
                $d2 = stream_get_contents($fp2);
                flock($fp2, LOCK_UN);
                fclose($fp2);
                $p2 = json_decode($d2, true);
                $s2 = $p2['status'] ?? '';
                // 终态文件或超时僵死文件，直接清理
                if (in_array($s2, ['done', 'error']) || $age > CFG_PROGRESS_STALE) {
                    @unlink($oldFile);
                    @unlink(preg_replace('/\.json$/', '.sh', $oldFile));
                    @unlink($oldFile . '.content');  // W-17 拆分内容文件一并清理
                }
            }
        }
    }
    
    echo json_encode([
        'ok'       => true,
        'status'   => $finalStatus,
        'progress' => $progress['progress'] ?? 0,
        // W-17 修复：内容拆分到 .content 文件以优化长章节流式 I/O。
        // 优先读独立内容文件，回退到 JSON 内嵌的 content 字段（向后兼容）。
        'content'  => (!empty($progress['_content_split']) && file_exists($progressFile . '.content'))
                        ? @file_get_contents($progressFile . '.content')
                        : ($progress['content'] ?? ''),
        'thinking_content' => $progress['thinking_content'] ?? '',
        'messages' => $progress['messages'] ?? [],
        'model_used' => $progress['model_used'] ?? null,
        'words'    => $progress['words'] ?? 0,
        'chapter_id' => $progress['chapter_id'] ?? null,
        'error'    => $progress['error'] ?? null,
        'content_saved' => !empty($progress['content_saved']),
        'content_revision' => (int)($progress['content_revision'] ?? 0),
        'postprocess_failed' => !empty($progress['postprocess_failed']),
        'postprocess_pending' => !empty($progress['postprocess_pending']),
        'postprocess_job_id' => (int)($progress['postprocess_job_id'] ?? 0),
        'updated_at' => $progress['updated_at'] ?? 0,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] write_poll: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(safe_api_error_payload($e, '进度查询失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
}
