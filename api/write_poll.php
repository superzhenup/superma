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

defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';  // 修复（2026-06-18）：taskIdSignature() 定义在此文件
require_once dirname(__DIR__) . '/includes/tasks/WritingTaskRepository.php';
require_once dirname(__DIR__) . '/includes/harness/events/LegacyWritingTaskEventCursor.php';
require_once dirname(__DIR__) . '/includes/harness/events/RunPollEventReplayer.php';
require_once dirname(__DIR__) . '/includes/harness/events/LegacyProgressFileAdapter.php';
requireLoginApi();  // 启用 CSRF 保护（前端已自动携带 X-CSRF-Token）

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

/**
 * Optional progress only: never infer completion or write readiness from subtasks.
 * Both explicit and recovered Job IDs must match this Task and the current Canon.
 */
function writePollPostprocessProgress(array $task, array $payload): array
{
    $payload['postprocess_subtasks'] = [];
    if (!in_array($payload['status'] ?? '', ['saved', 'postprocessing', 'done', 'completed', 'error'], true)
        || (int)($task['novel_id'] ?? 0) <= 0 || (int)($task['chapter_id'] ?? 0) <= 0) {
        return $payload;
    }

    try {
        $jobId = (int)($payload['postprocess_job_id'] ?? 0);
        $parameters = [(int)$task['novel_id'], (int)$task['chapter_id']];
        $jobFilter = '';
        if ($jobId > 0) {
            $jobFilter = ' AND pj.id=?';
            $parameters[] = $jobId;
        }
        // The Task's accepted Candidate prevents an old Task from displaying a
        // later A -> B -> A generation that happens to have the same body hash.
        if (array_key_exists('accepted_candidate_uid', $task)) {
            $jobFilter .= ' AND pj.accepted_candidate_uid <=> ?';
            $parameters[] = $task['accepted_candidate_uid'];
        }
        if (!empty($task['chapter_revision_hash'])) {
            $jobFilter .= ' AND pj.revision_hash=?';
            $parameters[] = $task['chapter_revision_hash'];
        }
        $job = DB::fetch(
            'SELECT pj.id FROM postprocess_jobs pj
             INNER JOIN chapters c ON c.id=pj.chapter_id AND c.novel_id=pj.novel_id
             WHERE pj.novel_id=? AND pj.chapter_id=? AND pj.stage="full"
               AND c.canonical_state IN ("legacy","canonical")
               AND c.content IS NOT NULL AND c.content<>""
               AND pj.revision_hash=c.content_revision_hash
               AND SHA2(c.content,256)=pj.revision_hash
               AND pj.accepted_candidate_uid <=> c.canonical_candidate_uid'
            . $jobFilter . ' ORDER BY pj.id DESC LIMIT 1',
            $parameters
        );
        if ($job === null || (int)($job['id'] ?? 0) <= 0) {
            // A stale file's Job ID must not survive authentication failure.
            $payload['postprocess_job_id'] = 0;
            return $payload;
        }
        $payload['postprocess_job_id'] = (int)$job['id'];
        if (!class_exists('PostprocessSubtaskRepository', false)) {
            require_once dirname(__DIR__) . '/includes/tasks/PostprocessSubtaskRepository.php';
        }
        $steps = PostprocessSubtaskRepository::listProgress((int)$job['id']);
        foreach ($steps as $step) {
            if (!is_array($step)) continue;
            // Keep results, provider payloads and lease credentials server-side.
            $payload['postprocess_subtasks'][] = [
                'task_type' => (string)($step['task_type'] ?? ''),
                'state' => (string)($step['state'] ?? ''),
                'duration_ms' => max(0, (int)($step['duration_ms'] ?? 0)),
                'attempt' => max(0, (int)($step['attempt'] ?? 0)),
                'last_error' => sanitizeAiErrorMessage((string)($step['last_error'] ?? '')),
                'applied_at' => $step['applied_at'] ?? null,
            ];
        }
    } catch (Throwable $progressError) {
        // Mixed-version deployment / absent new table must not break writing.
        error_log('[write_poll] optional postprocess progress unavailable: ' . $progressError::class);
        $payload['postprocess_subtasks'] = [];
    }
    return $payload;
}

try {
    // 支持 GET 和 POST 两种方式
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestInput = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $requestInput = $_GET;
    }
    $taskId = preg_replace('/[^a-zA-Z0-9_]/', '', $requestInput['task_id'] ?? '');
    if (!$taskId) throw new Exception('缺少任务ID');

    // Optional P1 event cursor. Its absence preserves the exact legacy payload
    // and avoids any generation_runs/events query for old clients.
    $eventCursor = null;
    $eventLimit = 50;
    $cursorKeys = array_values(array_filter(
        ['event_cursor', 'after_event_id'],
        static fn(string $key): bool => array_key_exists($key, $requestInput)
    ));
    if ($cursorKeys !== []) {
        $parseCursor = static function (mixed $rawCursor): ?int {
            if (is_int($rawCursor) && $rawCursor >= 0) {
                return $rawCursor;
            }
            if (is_string($rawCursor)
                && preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawCursor) === 1) {
                $parsedCursor = filter_var($rawCursor, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0],
                ]);
                return is_int($parsedCursor) ? $parsedCursor : null;
            }
            return null;
        };
        $eventCursor = $parseCursor($requestInput[$cursorKeys[0]]);
        if (count($cursorKeys) === 2
            && $eventCursor !== $parseCursor($requestInput[$cursorKeys[1]])) {
            throw new Exception('Conflicting event cursors.');
        }
        if ($eventCursor === null) {
            throw new Exception('事件游标无效');
        }

        $rawLimit = $requestInput['event_limit'] ?? 50;
        if (is_string($rawLimit) && preg_match('/^[1-9][0-9]*$/D', $rawLimit) === 1) {
            $rawLimit = filter_var($rawLimit, FILTER_VALIDATE_INT);
        }
        if (!is_int($rawLimit) || $rawLimit < 1 || $rawLimit > 100) {
            throw new Exception('事件分页大小无效');
        }
        $eventLimit = $rawLimit;
    }

    $progressDir = CFG_PROGRESS_DIR;
    $progressFile = $progressDir . '/' . $taskId . '.json';
    // task 行仅用于兼容身份与 ownership 校验。authority cohort 的生命周期以
    // generation_runs/Events 为权威；legacy/shadow 任务仍沿用旧状态路径。
    $durableTask = WritingTaskRepository::findByTaskId($taskId);
    if (!$durableTask) throw new Exception('任务不存在或已结束');
    $currentUserId = currentUserId();
    if ((int)$durableTask['user_id'] !== $currentUserId) {
        throw new Exception('无权访问此任务');
    }
    checkNovelOwnership((int)$durableTask['novel_id'], $currentUserId);

    $eventSource = HarnessEventSourcePolicy::readApplication();
    $eventReader = new EventAuthoritativePollReader();
    $authoritySnapshot = $eventReader->readAuthority($durableTask);
    $eventSnapshot = $authoritySnapshot;
    if ($authoritySnapshot === null && $eventSource !== HarnessEventSourcePolicy::LEGACY) {
        try {
            $eventSnapshot = $eventReader->read(
                $durableTask,
                $eventSource === HarnessEventSourcePolicy::EVENTS
            );
            if ($eventSource === HarnessEventSourcePolicy::EVENTS
                && $eventSnapshot === null) {
                throw new HarnessEventProjectionMismatchException(
                    'Authority Event snapshot is unavailable for this task.'
                );
            }
        } catch (Throwable $eventSnapshotError) {
            if ($eventSource === HarnessEventSourcePolicy::EVENTS) {
                throw $eventSnapshotError;
            }
            error_log(sprintf(
                '[write_poll] dual Event snapshot unavailable task=%s exception=%s',
                $taskId,
                $eventSnapshotError::class
            ));
            $eventSnapshot = null;
        }
    }

    $respond = static function (array $payload) use (
        $eventCursor,
        $eventLimit,
        $durableTask,
        $taskId,
        $eventSource,
        $eventSnapshot,
        $authoritySnapshot
    ): void {
        if ($authoritySnapshot === null && $eventSource === HarnessEventSourcePolicy::DUAL) {
            $payload['source'] = HarnessEventSourcePolicy::DUAL;
            if (is_array($eventSnapshot)) {
                $diff = HarnessPollProjectionComparator::diff($payload, $eventSnapshot);
                $payload['projection_consistent'] = $diff === [];
                if ($diff !== []) {
                    error_log(sprintf(
                        '[write_poll] legacy/event projection mismatch task=%s fields=%s',
                        $taskId,
                        implode(',', array_keys($diff))
                    ));
                }
            } else {
                $payload['projection_consistent'] = false;
            }
        }
        if ($eventCursor !== null) {
            try {
                $cursorReader = $authoritySnapshot !== null
                    || $eventSource === HarnessEventSourcePolicy::EVENTS
                    ? new AuthorityWritingTaskEventCursor()
                    : new LegacyWritingTaskEventCursor();
                $payload['event_stream'] = $cursorReader->read(
                    $durableTask,
                    $eventCursor,
                    $eventLimit
                )->toArray();
            } catch (Throwable $eventError) {
                error_log(sprintf(
                    '[write_poll] Harness event cursor unavailable task=%s: %s',
                    $taskId,
                    $eventError->getMessage()
                ));
                $payload['event_stream'] = [
                    'status' => 'unavailable',
                    'next_cursor' => $eventCursor,
                    'events' => [],
                    'run_uid' => null,
                    'error' => 'trace_unavailable',
                ];
            }
            // Preserve the nested compatibility object while also exposing
            // the original P1 top-level cursor contract.
            $payload['events'] = $payload['event_stream']['events'];
            $payload['next_event_id'] = $payload['event_stream']['next_cursor'];
            $payload['source'] ??= $eventSource;
        }
        echo json_encode(writePollPostprocessProgress($durableTask, $payload), JSON_UNESCAPED_UNICODE);
    };

    if ($authoritySnapshot !== null) {
        $respond($authoritySnapshot);
        exit;
    }
    if ($eventSource === HarnessEventSourcePolicy::EVENTS) {
        $respond($eventSnapshot);
        exit;
    }

    $missingProgressPayload = LegacyProgressFileAdapter::recoverMissingPollProjection(
        $progressFile,
        $durableTask
    );
    if ($missingProgressPayload !== null) {
        // v53/P1：legacy/shadow 兼容文件丢失/清理后，仍从持久 task 行恢复状态、
        // 终态与错误原因；绝不把文件缺失误报为任务不存在。
        // 审计修复（2026-08-30 H-1）：文件已不存在的活跃任务同样需要收口——
        // worker 硬杀后进度文件可能已被清理，此时 durable 行仍停留
        // generating 等活跃态，toPollPayload 映射为 'writing' → 前端无限轮询。
        // Saved/postprocessing are intermediate states too. The repository
        // owns expiry and live postprocess-job exceptions; do not bypass it.
        if (!in_array($missingProgressPayload['status'] ?? '', ['done', 'error'], true)) {
            try {
                if (WritingTaskRepository::reapExpiredLeaseForTask($taskId)) {
                    $durableTask = WritingTaskRepository::findByTaskId($taskId) ?? $durableTask;
                    $missingProgressPayload = LegacyProgressFileAdapter::recoverMissingPollProjection(
                        $progressFile,
                        $durableTask
                    ) ?? $missingProgressPayload;
                }
            } catch (Throwable $reapError) {
                error_log(sprintf(
                    '[write_poll] reapExpiredLeaseForTask (missing file) failed task=%s: %s',
                    $taskId,
                    $reapError->getMessage()
                ));
            }
        }
        $respond($missingProgressPayload);
        exit;
    }

    // 审计修复 H-4（2026-06-17）：读进度前先校验 task_id 归属
    // 旧实现任意已登录用户可读任意 task_id 的进度（含未发布章节正文）
    // 审计修复 R1（2026-08-10）：原代码 fopen/flock 失败时 if 块被整体跳过，
    // 权限/HMAC 校验被绕过，仅剩下方 checkNovelOwnership 兜底。fopen 失败
    // 必须显式抛错——文件存在性已由 L39 is_file 校验通过，fopen 失败属
    // IO/权限故障，不能静默放行。
    $fp = fopen($progressFile, 'r');
    if ($fp === false) {
        throw new Exception('无法读取进度文件');
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        throw new Exception('无法锁定进度文件');
    }
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
        if ($currentUserId !== $ownerUserId) {
            throw new Exception('无权访问此任务');
        }
        if ($novelIdIn !== (int)$durableTask['novel_id']) {
            throw new Exception('任务归属不一致');
        }
        // 进一步用 HMAC 校验（防 task_id 伪造或文件被篡改）
        $sig = (string)($data['task_sig'] ?? '');
        $expectedSig = taskIdSignature($taskId, $currentUserId, $novelIdIn);
        if ($sig === '' || !hash_equals($expectedSig, $sig)) {
            throw new Exception('任务签名校验失败');
        }
    }

    if (!file_exists($progressFile)) {
        throw new Exception('任务不存在或已过期');
    }

    // APCu 内存缓存层：高频轮询下避免反复磁盘 IO。
    // 缓存键包含文件 mtime（秒级），文件一更新自动失效；无 TTL 或很短（5秒）
    // 审计修复 R5（2026-08-10）：缓存键加入 user_id 防御性编程，
    // 避免 filemtime 失败返回 false 时不同用户共享同一退化键的极端场景。
    $apcuAvailable = function_exists('apcu_enabled') && apcu_enabled();
    $fileMtime = @filemtime($progressFile);
    $currentUserIdForCache = currentUserId();
    $cacheKey = 'write_poll:' . $taskId . ':' . $currentUserIdForCache . ':' . $fileMtime;
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

    // 文件只是流式兼容视图。活动文件必须携带当前 exact lease 的四元组；
    // 终态文件则必须与 durable 终态和 generation 一致。任何不匹配都按
    // lease_lost/stale generation 处理，绝不返回其中的正文或错误信息。
    $progressMatchesDurableFence = static function(
        array $fileProgress,
        array $task,
        string $expectedTaskId
    ): bool {
        $fileStatus = (string)($fileProgress['status'] ?? 'unknown');
        $durableState = (string)($task['state'] ?? 'unknown');

        if ($fileStatus === 'starting') {
            return $durableState === 'queued'
                && (int)($task['cancel_requested'] ?? 0) === 0
                && empty($task['lease_owner'])
                && empty($task['lease_token']);
        }

        $fileTaskId = $fileProgress['_task_id'] ?? null;
        $fileOwner = $fileProgress['_lease_owner'] ?? null;
        $fileToken = $fileProgress['_lease_token'] ?? null;
        $fileGeneration = filter_var(
            $fileProgress['_fence_generation'] ?? null,
            FILTER_VALIDATE_INT
        );
        $durableGeneration = filter_var(
            $task['fence_generation'] ?? null,
            FILTER_VALIDATE_INT
        );
        if (!is_string($fileTaskId) || !hash_equals($expectedTaskId, $fileTaskId)
            || $fileGeneration === false || $durableGeneration === false
            || (int)$fileGeneration <= 0
            || (int)$fileGeneration !== (int)$durableGeneration) {
            return false;
        }

        if ($fileStatus === 'done') {
            return $durableState === 'done';
        }
        if ($fileStatus === 'error') {
            return in_array($durableState, ['failed', 'canceled'], true);
        }

        if (!in_array($durableState, [
            'queued', 'preparing', 'generating', 'validating', 'content_saved', 'postprocessing',
        ], true)
            || (int)($task['cancel_requested'] ?? 0) !== 0
            || !is_string($fileOwner) || !is_string($fileToken)
            || !is_string($task['lease_owner'] ?? null)
            || !is_string($task['lease_token'] ?? null)
            || !hash_equals((string)$task['lease_owner'], $fileOwner)
            || !hash_equals((string)$task['lease_token'], $fileToken)) {
            return false;
        }
        $leaseExpirySql = (string)($task['lease_expires_at'] ?? '');
        $leaseExpiresAt = $leaseExpirySql === '' ? false : strtotime($leaseExpirySql . ' UTC');
        if ($leaseExpiresAt === false || $leaseExpiresAt < time()) {
            return false;
        }
        if ($fileStatus === 'saved' && $durableState !== 'content_saved') {
            return false;
        }
        if ($fileStatus === 'postprocessing' && $durableState !== 'postprocessing') {
            return false;
        }
        return true;
    };

    $progressIdentityMatches = (int)($progress['user_id'] ?? 0) === (int)$durableTask['user_id']
        && (int)($progress['novel_id'] ?? 0) === (int)$durableTask['novel_id'];
    if (!$progressIdentityMatches
        || !$progressMatchesDurableFence($progress, $durableTask, $taskId)) {
        // 审计修复（2026-08-30 H-1）：durable fence 校验失败的最常见原因是租约已过期
        // （worker 被 SIGKILL，无人续租）。此处是轮询端唯一能触达的收口点——
        // 原实现直接降级 toPollPayload，但该函数把 generating/preparing/validating
        // 一律映射为 status:'writing'，前端因此无限轮询"写作中"。
        // 先尝试按 task_id 收口过期死任务；若发生了状态转移则重新读行再降级，
        // 本次响应即可如实返回 error 终态，前端停止轮询。
        $reaped = false;
        try {
            $reaped = WritingTaskRepository::reapExpiredLeaseForTask($taskId);
        } catch (Throwable $reapError) {
            // 收口失败不阻断轮询响应（如瞬态 DB 抖动），下次轮询会再尝试。
            error_log(sprintf(
                '[write_poll] reapExpiredLeaseForTask failed task=%s: %s',
                $taskId,
                $reapError->getMessage()
            ));
        }
        if ($reaped) {
            $durableTask = WritingTaskRepository::findByTaskId($taskId) ?? $durableTask;
        }
        $respond(WritingTaskRepository::toPollPayload($durableTask));
        exit;
    }

    // 审计修复 H1（2026-06-12）：轮询同样需要小说归属校验，
    // 否则任意已登录用户可枚举 task_id 窥探/复用他人的写作进度。
    $pollNovelId = (int)($progress['novel_id'] ?? 0);
    if ($pollNovelId > 0) {
        $userId = currentUserId();
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
                // 审计修复（2026-08-16 S-1②）：saved/postprocessing 是后处理中间态，
                // 该阶段进度文件 mtime 不再随流式 chunk 刷新（PostprocessRunner 只续
                // DB 租约），超过 CFG_PROGRESS_STALE 属正常现象，绝不能按普通僵死清理
                // ——否则活跃任务文件被误删。此处放行，交由两道兜底：
                //   1) PostprocessRunner 租约心跳顺带 touch 进度文件（存活 worker 的 mtime 保持新鲜）；
                //   2) 仅超长龄（CFG_PROGRESS_STALE×7 ≈ 35 分钟 > 1800s 租约上限+缓冲）
                //      才视为孤儿清理，与 writing_tasks 租约过期清扫口径对齐。
                if (in_array($s2, ['saved', 'postprocessing'], true)
                    && $age <= CFG_PROGRESS_STALE * 7) {
                    continue;
                }
                // 终态文件或超时僵死文件，直接清理
                if (in_array($s2, ['done', 'error']) || $age > CFG_PROGRESS_STALE) {
                    @unlink($oldFile);
                    @unlink(preg_replace('/\.json$/', '.sh', $oldFile));
                    @unlink($oldFile . '.content');  // W-17 拆分内容文件一并清理
                }
            }

            // 审计修复（2026-08-16 #13）：清理断连遗嘱残留标记 reconnect_*.marker。
            // 该标记由 write_chapter.php 的 shutdown 钩子按 novelId 固定名覆盖写，
            // 本身不随章节数累积，但无任何读取/清理方；超长龄（>1 小时）视为陈旧清理。
            foreach (glob($progressDir . '/reconnect_*.marker') ?: [] as $oldMarker) {
                $markerMtime = @filemtime($oldMarker);
                if ($markerMtime !== false && ($now - $markerMtime) > 3600) {
                    @unlink($oldMarker);
                }
            }
        }
    }
    
    // W-17 修复：内容拆分到 .content 文件以优化长章节流式 I/O。
    // 优先读独立内容文件，回退到 JSON 内嵌的 content 字段（向后兼容）。
    // 审计修复（2026-08-13）：worker 以 LOCK_EX 追加写 .content，原 file_get_contents
    // 裸读可能读到半截 chunk。改为 LOCK_SH 共享锁读取，与 worker 的排他锁互斥，
    // 保证读到完整一致的内容快照。
    $pollContent = '';
    if (!empty($progress['_content_split']) && file_exists($progressFile . '.content')) {
        $cf = @fopen($progressFile . '.content', 'r');
        if ($cf) {
            flock($cf, LOCK_SH);
            $pollContent = (string)stream_get_contents($cf);
            flock($cf, LOCK_UN);
            fclose($cf);
        }
    } else {
        $pollContent = (string)($progress['content'] ?? '');
    }

    $respond([
        'ok'       => true,
        'status'   => $finalStatus,
        'progress' => $progress['progress'] ?? 0,
        'content'  => $pollContent,
        'thinking_content' => $progress['thinking_content'] ?? '',
        'messages' => $progress['messages'] ?? [],
        'model_used' => $progress['model_used'] ?? null,
        'words'    => $progress['words'] ?? 0,
        'chapter_id' => $progress['chapter_id'] ?? null,
        'error'    => $progress['error'] ?? null,
        'content_saved' => !empty($progress['content_saved']),
        // 审计修复（2026-08-17 写作逻辑 D2）：取消与落盘竞态——正文已提交时如实
        // 透传 canceled_but_saved，前端提示「本章已保存，自动写已停」而非纯取消。
        'canceled_but_saved' => !empty($progress['canceled_but_saved']),
        'content_revision' => (int)($progress['content_revision'] ?? 0),
        'postprocess_failed' => !empty($progress['postprocess_failed']),
        'postprocess_pending' => !empty($progress['postprocess_pending']),
        'quality_warning' => !empty($progress['quality_warning']),
        'quality_warning_reason' => (string)($progress['quality_warning_reason'] ?? ''),
        'postprocess_job_id' => (int)($progress['postprocess_job_id'] ?? 0),
        'updated_at' => $progress['updated_at'] ?? 0,
    ]);
    
} catch (Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] write_poll: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(safe_api_error_payload($e, '进度查询失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
}
