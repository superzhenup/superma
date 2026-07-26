<?php
/**
 * WriteEngine — 章节写作核心引擎
 * 提取 write_chapter.php 和 write_chapter_worker.php 的共享逻辑，
 * 通过回调注入实现 SSE / 进度文件 两种 I/O 模式的解耦。
 *
 * 6 个阶段：
 *   1. resolveChapter() — 解析待写章节 + 僵死任务清理
 *   2. initMemory()     — 初始化记忆引擎 + 语义召回
 *   3. buildPrompt()    — 组装 AI 写作 prompt
 *   4. streamWrite()    — 带模型回退的流式写作
 *   5. saveChapter()    — 落盘正文 + 版本备份
 *   6. postProcess()    — 摘要/记忆/知识库/质检
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/data.php';

class WriteEngineValidationException extends RuntimeException {}
class WriteEnginePersistenceException extends RuntimeException {}

class WriteEngine
{
    /**
     * 取消写作标志文件路径
     * 修复：以前写到 BASE_PATH/storage/ 根目录，与进度文件分散在两个目录，
     * 部署一致性差且根目录 .htaccess 无独立保护规则。
     * 现统一到 CFG_PROGRESS_DIR（已含 storage/write_progress/.htaccess deny all）。
     */
    public static function cancelFlagPath(int $novelId): string
    {
        return CFG_PROGRESS_DIR . "/write_cancel_{$novelId}.flag";
    }

    /** 后处理「进行中」标志文件路径（防止下一章在记忆/指令落库前就开写） */
    private static function postProcessPendingFlag(int $novelId, int $chNum): string
    {
        return CFG_PROGRESS_DIR . "/pp_pending_{$novelId}_{$chNum}.flag";
    }

    /**
     * 清除章节后处理占位。worker/daemon 的异常与 shutdown 路径也必须能调用，
     * 否则正文已保存但后处理崩溃时，下一章会一直等待到 stale timeout。
     */
    public static function clearPostProcessPending(int $novelId, int $chNum): void
    {
        if ($novelId > 0 && $chNum > 0) {
            @unlink(self::postProcessPendingFlag($novelId, $chNum));
        }
    }

    /**
     * 等待指定章节的后处理完成（摘要/记忆入库/Agent指令落库）。
     * 仅当存在「进行中」标志时才等待，避免对历史章节空等。
     * 超时后放行并记日志（防止后台进程意外退出导致死等）。
     * 上限可由 ws_pp_wait_sec 配置（默认 300 秒）。
     * 修复：原默认 120 秒在「重写 + 摘要重跑 + 同步 AI 调用」串联时
     * 极易触顶强放行，导致下一章读到不一致的记忆/约束状态。
     * 提高到 300 秒并补充计时埋点（实际等待秒数 + 是否超时强放行）。
     */
    private static function waitForPostProcess(int $novelId, int $chNum, ?int $maxWaitSec = null): void
    {
        if ($chNum < 1) return;
        if ($maxWaitSec === null) {
            $maxWaitSec = max(60, (int)getSystemSetting('ws_pp_wait_sec', 300, 'int'));
        }
        $flag = self::postProcessPendingFlag($novelId, $chNum);
        if (!file_exists($flag)) return; // 未在后处理中（已完成或为历史章节）

        $startedAt = time();
        $deadline = $startedAt + $maxWaitSec;
        $lastHeartbeat = 0;
        while (time() < $deadline) {
            usleep(400000); // 0.4s
            // 审计修复（2026-07-19 H-中2）：等待期间每 30 秒续租当前章任务租约，
            // 避免 300 秒等待超过 180 秒租约 -> 健康任务被 watchdog 误判 failed。
            $now = time();
            if ($now - $lastHeartbeat >= 30) {
                $lastHeartbeat = $now;
                try {
                    $activeTask = \WritingTaskRepository::findActiveTaskForNovel($novelId);
                    if ($activeTask && !empty($activeTask['lease_owner'])) {
                        \WritingTaskRepository::heartbeat(
                            (int)$activeTask['id'],
                            $activeTask['lease_owner'],
                            max(180, (int)($activeTask['lease_seconds'] ?? 180))
                        );
                    }
                } catch (\Throwable $e) {
                    error_log('waitForPostProcess heartbeat failed: ' . $e->getMessage());
                }
            }
            if (!file_exists($flag)) {
                $waited = time() - $startedAt;
                addLog($novelId, 'info', "已等待第{$chNum}章后处理完成（{$waited}秒），开始写下一章");
                return;
            }
        }
        // 超时兜底：清除标志并放行，避免死等
        // M-15 修复（2026-07-25）：原 @unlink($flag) 直接删除"后处理进行中"标志，
        // 若后处理只是慢（仍在跑），下一章会同时启动后处理，并发写记忆/伏笔表导致数据不一致。
        // 改为 rename 到 .stale（与僵尸任务清理一致），保留超时证据供诊断；
        // 后处理进程完成后应清理同名 .stale 文件。注意：此修复不能完全防止并发（需 DB 层锁），
        // 但至少保留了痕迹，配合下方降级标记可降低风险。
        $staleFlag = $flag . '.stale.' . getmypid();
        if (!@rename($flag, $staleFlag)) {
            @unlink($flag); // rename 失败则回退到直接删除
        }
        // P1-7 优化：写入降级标记文件，供下一章 buildPrompt 检测
        // 下一章检测到此标记时，在 prompt 中注入"前章记忆可能不完整"提示，
        // 让 AI 自主补全衔接，避免读到不一致的记忆/约束状态时盲目推进情节
        $degradedFlag = CFG_PROGRESS_DIR . "/pp_degraded_n{$novelId}_c{$chNum}.marker";
        @file_put_contents($degradedFlag, json_encode([
            'novel_id'      => $novelId,
            'chapter'       => $chNum,
            'degraded_at'   => time(),
            'wait_seconds'  => $maxWaitSec,
        ]));
        addLog($novelId, 'warn', "等待第{$chNum}章后处理超时（{$maxWaitSec}秒），强制放行（记忆/约束可能滞后，已写入降级标记）");
    }

    /**
     * P1-7 优化：检测并消费后处理降级标记
     *
     * 由 buildPrompt 调用。若检测到前一章的降级标记，返回提示文本注入 prompt，
     * 并清除标记（一次性消费）。返回空字符串表示无降级。
     *
     * @param int $novelId      小说ID
     * @param int $chapterNum   当前章节号（检查前一章的标记）
     * @return string 降级提示文本（空字符串表示无降级）
     */
    private static function consumePostProcessDegradedFlag(int $novelId, int $chapterNum): string
    {
        $prevChNum = $chapterNum - 1;
        if ($prevChNum < 1) return '';

        $degradedFlag = CFG_PROGRESS_DIR . "/pp_degraded_n{$novelId}_c{$prevChNum}.marker";
        if (!file_exists($degradedFlag)) return '';

        $content = @file_get_contents($degradedFlag);
        @unlink($degradedFlag); // 一次性消费

        $data = json_decode($content ?: '', true);
        $waitSec = is_array($data) ? (int)($data['wait_seconds'] ?? 0) : 0;

        addLog($novelId, 'info', "检测到第{$prevChNum}章后处理降级标记，本章将注入衔接保护提示");
        return "【衔接保护】前章（第{$prevChNum}章）的后处理（摘要/记忆/约束状态更新）未完全完成"
            . ($waitSec > 0 ? "（等待{$waitSec}秒超时）" : '')
            . "。本章写作时请注意：\n"
            . "1. 前章结尾衔接请以正文实际内容为准，不要依赖摘要\n"
            . "2. 若发现角色状态/伏笔线索与前文有出入，以前文正文为准\n"
            . "3. 避免引用可能未更新的记忆数据\n";
    }

    /**
     * 扫描进度目录，返回指定小说当前活跃（非僵死）的写作任务。
     * 顺带清理已完成/僵死的进度文件并复位相关 DB 状态。
     *
     * write_start.php（异步启动）与 write_chapter.php（SSE 直连）共用，
     * 保证两个写作入口受同一套并发守卫约束——此前 SSE 直连没有任何检查，
     * 前端回退到该入口会把正在写作的后台任务章节强制重置，造成双写。
     *
     * @return ?array 活跃任务的进度数据（含 task_file），无活跃任务返回 null
     */
    public static function findActiveTask(int $novelId): ?array
    {
        $progressDir = CFG_PROGRESS_DIR;
        if (!is_dir($progressDir)) return null;

        $staleTimeout = CFG_ZOMBIE_PROGRESS;

        foreach (glob($progressDir . '/w*.json') as $existingFile) {
            $fp = fopen($existingFile, 'r');
            if (!$fp) continue;
            flock($fp, LOCK_SH);
            $data = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            $p = json_decode($data, true);
            if (!is_array($p)) { @unlink($existingFile); continue; }

            $fileNovelId = (int)($p['novel_id'] ?? 0);
            $fileStatus  = $p['status'] ?? '';

            // ① 已完成/报错的进度文件直接清理，不阻塞新任务
            if (in_array($fileStatus, ['done', 'error', 'completed'])) {
                @unlink($existingFile);
                @unlink(preg_replace('/\.json$/', '.sh', $existingFile)); // 清理对应 wrapper.sh
                continue;
            }

            // ② 不属于当前小说的进度文件，跳过（不干预其他小说）
            if ($fileNovelId !== $novelId) continue;

            // ③ 僵死检测：优先相信可验证的进程存活状态。
            // 预处理、Embedding 和 Prompt 构建都可能长时间没有正文 chunk；仅凭
            // updated_at 超时清理，会删除仍在工作的任务并启动第二个生成进程。
            $updatedAt = (int)($p['updated_at'] ?? 0);
            $startedAt = (int)($p['started_at'] ?? 0);
            $refTime   = $updatedAt > 0 ? $updatedAt : $startedAt;
            $isStale   = ($refTime > 0 && (time() - $refTime) > $staleTimeout);

            $pidChecked = false;
            $pidAlive = false;
            if (!empty($p['pid'])) {
                $pid = (int)$p['pid'];
                if ($pid > 0) {
                    if (PHP_OS_FAMILY === 'Windows') {
                        if (function_exists('exec')) {
                            // H-11 修复（2026-07-25）：原代码无论 exec 是否成功都设 $pidChecked=true，
                            // 若 exec 被 disable_functions 禁用或命令执行失败（$out 为空），
                            // $pidAlive=false 会把活跃进程误判为僵尸并清理，触发双写。
                            // 改为：通过 exit code 和输出判断 exec 是否真正执行成功，
                            // 仅在确认命令执行成功时才信任结果，否则回退到时间戳判断。
                            $out = [];
                            $exitCode = 1;
                            @exec("tasklist /FI \"PID eq {$pid}\" /NH 2>nul", $out, $exitCode);
                            // tasklist 成功时总有输出（PID 行或 "No tasks" 提示）；
                            // 输出为空表示 exec 未真正执行（被禁用/命令不存在）
                            $execRan = ($exitCode === 0) || !empty($out);
                            if ($execRan) {
                                $pidChecked = true;
                                $pidAlive = !empty($out) && preg_match('/\b' . $pid . '\b/', implode('', $out)) === 1;
                            }
                            // execRan=false 时 $pidChecked 保持 false，回退到时间戳判断
                        }
                    } elseif (is_dir('/proc')) {
                        $pidChecked = true;
                        $pidAlive = file_exists("/proc/{$pid}");
                    } elseif (function_exists('posix_kill')) {
                        $pidChecked = true;
                        $pidAlive = @posix_kill($pid, 0);
                    }
                }
            }

            if ($pidChecked) {
                $taskAge = $startedAt > 0 ? time() - $startedAt : 0;
                $hardStaleTimeout = max(1800, $staleTimeout * 4);
                // 已退出进程立即回收；仍存活进程只有在同时超过硬上限且心跳陈旧时
                // 才视为挂死，避免无限保留真正卡住的进程。
                $isStale = !$pidAlive || ($isStale && $taskAge > $hardStaleTimeout);
            }

            if ($isStale) {
                // W-24 修复：先 rename 到 .stale 后台再删除——避免 Windows 下若另一进程刚开始写入该文件
                // 时被并发删除（advisory lock 在 Windows 下不完全可靠）。rename 是原子操作。
                $stalePath = $existingFile . '.stale.' . getmypid();
                if (@rename($existingFile, $stalePath)) {
                    @unlink($stalePath);
                } else {
                    @unlink($existingFile);  // rename 失败则直接删（旧行为）
                }
                @unlink(preg_replace('/\.json$/', '.sh', $existingFile));
                @unlink($existingFile . '.content');  // W-17 拆分内容文件一并清理
                if ($fileNovelId > 0) {
                    DB::update('novels', ['status' => 'draft'], 'id=? AND status=?', [$fileNovelId, 'writing']);
                    $staleChapterId = (int)($p['chapter_id'] ?? 0);
                    if ($staleChapterId > 0) {
                        DB::update('chapters', ['status' => 'outlined'], 'id=? AND status=?', [$staleChapterId, 'writing']);
                    } else {
                        DB::query('UPDATE chapters SET status="outlined" WHERE novel_id=? AND status="writing"', [$fileNovelId]);
                    }
                    addLog($fileNovelId, 'warn', "清理僵死写作任务（超时{$staleTimeout}秒无响应）");
                }
                continue;
            }

            // ④ 当前小说确实有任务在进行中（且非僵死）
            if (in_array($fileStatus, ['starting', 'writing', 'waiting'])) {
                $p['task_file'] = $existingFile;
                return $p;
            }
        }

        // W-12 修复：扫描孤立的 pp_pending flag——基于文件年龄清理（超过等待上限即视为
        // 僵死残留），避免 worker 进程崩溃后留下的 stale flag 让下一章空等 300s 超时。
        // 文件名格式必须与 postProcessPendingFlag() 一致：pp_pending_{novelId}_{chNum}.flag。
        // （历史 bug：曾用 pp_pending_n{id}_c{ch} 格式 glob，与生成器不符 → 永不匹配 = 死代码。）
        $ppFlags = glob($progressDir . '/pp_pending_' . $novelId . '_*.flag') ?: [];
        $ppWaitSec = function_exists('getSystemSetting')
            ? max(60, (int)getSystemSetting('ws_pp_wait_sec', 300, 'int'))
            : 300;
        $ppStaleAfter = $ppWaitSec + 30;
        foreach ($ppFlags as $flagFile) {
            if (preg_match('/pp_pending_\d+_(\d+)\.flag$/', basename($flagFile), $m)) {
                $ppChNum = (int)$m[1];
                $mtime = @filemtime($flagFile);
                if ($mtime !== false && (time() - $mtime) > $ppStaleAfter) {
                    @unlink($flagFile);
                    addLog($novelId, 'info', "Cleaned stale pp_pending flag for chapter {$ppChNum} after {$ppStaleAfter}s");
                }
            }
        }

        // W-11 修复：进度文件未找到时，查 DB 兜底——避免进度文件意外丢失时 zombie 检测失效。
        // 若 DB 中存在 status='writing' 且 updated_at 在 zombie 阈值内的章节，视为活跃任务。
        try {
            $staleSeconds = defined('CFG_ZOMBIE_DB') ? CFG_ZOMBIE_DB : 300;
            $dbActive = DB::fetch(
                'SELECT id, chapter_number FROM chapters
                 WHERE novel_id=? AND status="writing"
                   AND updated_at IS NOT NULL
                   AND updated_at >= (NOW() - INTERVAL ? SECOND)
                 ORDER BY chapter_number ASC LIMIT 1',
                [$novelId, $staleSeconds]
            );
            if ($dbActive) {
                return [
                    'status'     => 'writing',
                    'novel_id'   => $novelId,
                    'chapter_id' => (int)$dbActive['id'],
                    'task_file'  => null,
                    '_db_only'   => true,  // 标记为 DB 兜底信号（无进度文件）
                ];
            }
        } catch (\Throwable $e) {
            // DB 不可用时静默降级
        }

        return null;
    }

    /**
     * 原子回收没有心跳、已经超过 DB 僵死阈值的 writing 章节。
     *
     * 该方法不会触碰阈值窗口内的活跃章节。调用方应先用 findActiveTask()
     * 排除仍有有效进度文件的前台任务，再在启动新任务前执行本恢复步骤。
     *
     * @return array<int, array{id:int, chapter_number:int}> 已恢复的章节
     */
    public static function recoverStaleWritingChapters(int $novelId): array
    {
        if ($novelId <= 0) return [];

        $staleSeconds = defined('CFG_ZOMBIE_DB') ? CFG_ZOMBIE_DB : 300;
        $pdo = DB::connect();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx && !$pdo->beginTransaction()) {
            throw new RuntimeException('无法开启僵死写作恢复事务');
        }

        try {
            $stuck = DB::fetchAll(
                'SELECT id, chapter_number FROM chapters
                 WHERE novel_id=? AND status="writing"
                   AND (updated_at IS NULL OR updated_at < (NOW() - INTERVAL ? SECOND))
                 FOR UPDATE',
                [$novelId, $staleSeconds]
            );
            $recovered = [];
            foreach ($stuck as $row) {
                $chapterId = (int)$row['id'];
                $affected = DB::update(
                    'chapters',
                    ['status' => 'outlined'],
                    'id=? AND status="writing"',
                    [$chapterId]
                );
                if ($affected > 0) {
                    $recovered[] = [
                        'id' => $chapterId,
                        'chapter_number' => (int)$row['chapter_number'],
                    ];
                }
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        if ($recovered) {
            // 同一本书可能还有另一个带有效心跳的 writing 章节；仅在全部 writing
            // 都已清空时恢复小说状态，避免把真实活跃任务显示成 draft。
            $activeWriting = DB::count('chapters', 'novel_id=? AND status="writing"', [$novelId]);
            if ($activeWriting === 0) {
                DB::update('novels', ['status' => 'draft'], 'id=? AND status="writing"', [$novelId]);
            }
            foreach ($recovered as $row) {
                addLog($novelId, 'info', "第{$row['chapter_number']}章重置为 outlined（僵死清理）");
            }
        }

        return $recovered;
    }

    /**
     * 计划 Task 1：章节可写状态白名单（单一事实来源）。
     * 仅 outlined / skipped 可进入写作。chapters.status 枚举现已包含 'failed'
     * （新装 schema 定义 pending/outlined/writing/completed/skipped/failed），
     * 但 'failed' 是需人工介入状态——挂机写作可把累计失败过多的章节标为 failed，
     * 之后不自动选它写作，但完成判定仍把它计为“未完成”，避免坏章被误报为全书完成。
     * 自动选章还会额外应用 automaticChapterFrontier()，只沿章节号向前推进。
     */
    private const WRITABLE_CHAPTER_STATUSES = ['outlined', 'skipped'];

    /** 可写状态集合的 SQL 片段（与 WRITABLE_CHAPTER_STATUSES 保持一致）。 */
    private static function writableChapterStatusSql(): string
    {
        return '"outlined","skipped"';
    }

    private static function isWritableChapterStatus(string $status): bool
    {
        return in_array($status, self::WRITABLE_CHAPTER_STATUSES, true);
    }

    /**
     * 自动写作进度线：当前已完成章节中的最大编号。
     *
     * 自动任务绝不能选择该进度线之前的 outlined/skipped 旧章，否则 prompt 会读取
     * 更晚章节形成的记忆与人物状态，产生“时间旅行”正文。人工显式传 chapter_id
     * 仍走 WRITABLE_CHAPTER_STATUSES，可在用户确认后处理历史缺口。
     */
    public static function automaticChapterFrontier(int $novelId): int
    {
        return (int)(DB::fetchColumn(
            'SELECT COALESCE(MAX(chapter_number), 0) FROM chapters '
            . 'WHERE novel_id=? AND status="completed"',
            [$novelId]
        ) ?: 0);
    }

    /** 返回下一章可安全自动写作的章节 ID；显式人工选章不使用本方法。 */
    public static function findNextAutomaticChapterId(int $novelId, bool $forUpdate = false): ?int
    {
        $frontier = self::automaticChapterFrontier($novelId);
        $sql = 'SELECT id FROM chapters '
            . 'WHERE novel_id=? AND status IN ("outlined","skipped") AND chapter_number>? '
            . 'ORDER BY chapter_number ASC LIMIT 1';
        if ($forUpdate) $sql .= ' FOR UPDATE';

        $id = DB::fetchColumn($sql, [$novelId, $frontier]);
        return $id === false || $id === null ? null : (int)$id;
    }

    /** 自动任务真正可认领的章节数（不含完成进度线之前、只能人工处理的历史缺口）。 */
    public static function countAutomaticWritableChapters(int $novelId): int
    {
        $frontier = self::automaticChapterFrontier($novelId);
        return DB::count(
            'chapters',
            'novel_id=? AND status IN ("outlined","skipped") AND chapter_number>?',
            [$novelId, $frontier]
        );
    }

    /** 已落后于完成进度线、需人工显式处理的可写历史章节数。 */
    public static function countManualBackfillChapters(int $novelId): int
    {
        $frontier = self::automaticChapterFrontier($novelId);
        if ($frontier <= 0) return 0;
        return DB::count(
            'chapters',
            'novel_id=? AND status IN ("outlined","skipped") AND chapter_number<=?',
            [$novelId, $frontier]
        );
    }

    public static function waitForPreviousPostProcess(int $novelId, int $chapterNumber): void
    {
        if ($chapterNumber <= 1) {
            return;
        }
        self::waitForPostProcess($novelId, $chapterNumber - 1);
    }

    /**
     * 显式传入 chapter_id 写作时，章节可能是 completed/writing 等不可写状态。
     * 此守护在进入写作事务前快速失败，避免覆盖已完成章节、造成统计漂移。
     */
    private static function assertChapterWritable(array $chapter): void
    {
        $status = (string)($chapter['status'] ?? '');
        if (!self::isWritableChapterStatus($status)) {
            $chapterNumber = (int)($chapter['chapter_number'] ?? 0);
            throw new RuntimeException("第{$chapterNumber}章当前状态不可写作：{$status}");
        }
    }

    /**
     * Phase 1: 解析待写章节（含僵死 writing 状态清理 + Agent决策）
     * @return array{n: array, ch: array}
     * @throws RuntimeException
     */
    public static function resolveChapter(int $novelId, ?int $chapterId = null, bool $chapterAlreadyClaimed = false): array
    {
        $novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
        if (!$novel) throw new RuntimeException('小说不存在');

        // PERF-C2：列裁剪避免拉取 LONGTEXT content
        $cc = 'id, novel_id, chapter_number, title, outline, key_points, hook, pacing, status, content, words, quality_score, critic_scores, ai_pattern_issues, created_at, updated_at';
        $ch = $chapterId
            ? DB::fetch("SELECT {$cc} FROM chapters WHERE id=? AND novel_id=?", [$chapterId, $novelId])
            : null;

        // 僵死 writing → outlined（指定章节ID时也需要清理该小说所有 writing 状态）
        // 修复：增加 updated_at 静态阈值守卫——只有超过 CFG_ZOMBIE_DB 秒
        // 没有任何更新的 writing 章节，才能视为真正的僵尸状态被重置；
        // 否则后台 worker 正在写的章节会被这里强行抢成 outlined，触发双写。
        // 心跳路径会持续刷新 chapters.updated_at（见 write_chapter_worker 的
        // sendHeartbeatWrite 与 WriteSseChannel::heartbeat），活跃任务的
        // updated_at 始终在阈值窗口内。
        // M-19 修复：僵尸清理用事务 + FOR UPDATE 防并发抢占，避免两个进程同时重置同一章节。
        // SSE 自己预占的 writing 是新鲜状态，绝不会被本恢复步骤误回收。
        $stuck = [];
        try {
            $stuck = self::recoverStaleWritingChapters($novelId);
        } catch (\Throwable $e) {
            error_log('resolveChapter zombie cleanup failed: ' . $e->getMessage());
        }
        if (!empty($stuck)) {
            // 如果指定的章节被重置，刷新 $ch 的状态
            if ($ch && in_array((int)$ch['id'], array_column($stuck, 'id'), true)) {
                $ch['status'] = 'outlined';
            }
        }

        // 未指定章节时只走自动选章契约：完成进度线之前的历史缺口必须由用户显式指定。
        if (!$chapterId) {
            $autoChapterId = self::findNextAutomaticChapterId($novelId);
            if ($autoChapterId) {
                $ch = DB::fetch(
                    "SELECT {$cc} FROM chapters WHERE id=? AND novel_id=?",
                    [$autoChapterId, $novelId]
                );
            }
        }

        if (!$ch) {
            if (!$chapterId && self::countManualBackfillChapters($novelId) > 0) {
                throw new RuntimeException('没有可安全自动写作的章节；完成进度线之前的历史缺口请人工显式选择。');
            }
            throw new RuntimeException('没有待写章节，请先生成大纲。');
        }

        // Task 1：进入写作事务前先快速校验可写状态。显式 chapter_id 路径取章节时
        // 没有状态过滤，僵死清理后若仍是 completed/active-writing 等状态，必须拒绝，
        // 否则下方会把已完成章节强制改回 writing 并在写完后覆盖正文/重复计入统计。
        if ($chapterAlreadyClaimed) {
            if (!$chapterId || (int)$ch['id'] !== $chapterId || (string)$ch['status'] !== 'writing') {
                throw new RuntimeException('预占章节状态已变化，写作已停止。');
            }
        } else {
            self::assertChapterWritable($ch);
        }

        // P0-3 优化：Agent决策移到僵死清理之后、章节置 writing 之前
        // 原实现将 runPreWriteAgents 放在 resolveChapter 最开头，此时僵死清理尚未执行，
        // getCurrentChapterNumber 返回的"下一个待写章节"可能与实际写作章节不一致
        // （僵死章节被重置为 outlined 后会改变选章结果）。
        // 现在基于已确定的 $ch['chapter_number'] 调用，确保决策上下文与实际写作章节对齐。
        self::runPreWriteAgents($novelId, (int)($ch['chapter_number'] ?? 0));

        // 事务包裹：取消标志清零 + 章节置 writing + 小说置 writing 必须原子执行。
        // 修复：write_chapter.php SSE 直连入口在并发守卫阶段已经 beginTransaction()
        // 持有 novels 行级锁，PDO 不支持嵌套事务，再次 begin 会抛
        // "There is already an active transaction" 异常导致整条 SSE 路径不可用。
        // 因此仅当外层无活跃事务时才自行包裹；外层有事务时复用其上下文。
        $flagFile = self::cancelFlagPath($novelId);

        $pdo = DB::connect();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            DB::update('novels',   ['cancel_flag' => 0], 'id=?', [$novelId]);
            // Task 1：原子守护——把"可写状态"放进 WHERE 谓词，与并发请求/僵死清理后的
            // 状态变化构成 TOCTOU 防护。若 0 行受影响，说明章节已被其它进程抢占或不可写，
            // 立即中止（事务回滚），避免覆盖。
            if (!$chapterAlreadyClaimed) {
                $affectedChapter = DB::update(
                    'chapters',
                    ['status' => 'writing'],
                    'id=? AND status IN (' . self::writableChapterStatusSql() . ')',
                    [$ch['id']]
                );
                if ($affectedChapter === 0) {
                    throw new RuntimeException('章节当前状态不可写作，写作已停止。');
                }
            } else {
                // 预占必须仍由本请求持有；只读校验，绝不把其它状态强改回 writing。
                $claimedStatus = DB::fetchColumn(
                    'SELECT status FROM chapters WHERE id=? AND novel_id=?',
                    [$ch['id'], $novelId]
                );
                if ($claimedStatus !== 'writing') {
                    throw new RuntimeException('预占章节已被释放，写作已停止。');
                }
            }
            DB::update('novels',   ['status' => 'writing'], 'id=?', [$novelId]);
            if ($ownTx) {
                $pdo->commit();
            }
            // 事务成功后再清除取消标志文件，确保状态一致性
            if (file_exists($flagFile)) {
                @unlink($flagFile);
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // v2: 记忆竞态修复——若上一章后处理（摘要/记忆/指令）仍在后台进行，
        // 先等它完成再构建本章 prompt，否则本章会读到滞后的记忆与漏掉的 Agent 指令。
        self::waitForPostProcess($novelId, (int)$ch['chapter_number'] - 1);

        return ['n' => $novel, 'ch' => $ch];
    }

    /**
     * Phase 2: 初始化记忆引擎 + 语义召回
     * @param int $novelId 小说ID
     * @param array $chapter 章节数据
     * @param ?AIClient $aiClient AI客户端（用于检测1M上下文支持）
     * @return array{engine: MemoryEngine, memoryCtx: ?array}
     */
    public static function initMemory(int $novelId, array $chapter, ?AIClient $aiClient = null): array
    {
        require_once __DIR__ . '/memory/MemoryEngine.php';
        $engine = new MemoryEngine($novelId);

        try { $engine->ensureEmbeddings(30); }
        catch (Throwable $e) { addLog($novelId, 'warn', 'ensureEmbeddings 失败：' . $e->getMessage()); }

        $queryText = trim(($chapter['title'] ?? '') . '：' . ($chapter['outline'] ?? ''));
        $semanticTopK = max(1, min(40, (int)getSystemSetting('ws_embedding_top_k', 16, 'int')));

        $contextMode = getSystemSetting('ws_context_mode', 'auto', 'string');
        $is1MSupported = $aiClient ? $aiClient->is1MContext() : false;
        $useFullContext = false;
        $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);

        if ($contextMode === 'full' && $is1MSupported) {
            $useFullContext = true;
        } elseif ($contextMode === 'auto' && $is1MSupported) {
            $fullContextThreshold = (int)getSystemSetting('ws_full_context_threshold', 10, 'int');
            if ($chNum >= $fullContextThreshold) {
                $useFullContext = true;
            }
        }

        try {
            if ($useFullContext) {
                // 1M 上下文模式仍保留硬预算：预留输出、系统指令和厂商 tokenizer 差异空间。
                $contextLimit = $aiClient?->getContextLimit() ?? 1000000;
                $reservedTokens = max(50000, ($aiClient?->getMaxTokens() ?? 8192) * 2);
                $fullContextBudget = max(100000, min(900000, $contextLimit - $reservedTokens));
                $maxHistoryChapters = max(0, (int)getSystemSetting('ws_1m_history_chapters', 0, 'int'));
                $memoryCtx = $engine->getFullPromptContext(
                    (int)$chapter['chapter_number'],
                    $maxHistoryChapters,
                    $queryText,
                    $fullContextBudget
                );
                addLog($novelId, 'info', sprintf(
                    '使用1M完整上下文模式：大纲%d章 / 正文%d章 / 伏笔%d条 / 角色%d个',
                    $memoryCtx['full_context_stats']['total_outlines'] ?? 0,
                    $memoryCtx['full_context_stats']['full_content_chapters'] ?? 0,
                    $memoryCtx['full_context_stats']['foreshadowing_count'] ?? 0,
                    $memoryCtx['full_context_stats']['character_count'] ?? 0
                ));
            } else {
                // 标准压缩模式
                // v41: token 预算配置化（缓存让大上下文便宜，默认从 6000 提到 20000）
                $memBudget = max(2000, (int)getSystemSetting('ws_memory_token_budget', 20000, 'int'));
                $memoryCtx = $engine->getPromptContext((int)$chapter['chapter_number'], $queryText, $memBudget, 20, $semanticTopK);
            }
        } catch (Throwable $e) {
            addLog($novelId, 'error', 'MemoryEngine 上下文构建失败：' . $e->getMessage());
            $memoryCtx = null;
        }

        $hitCount = is_array($memoryCtx['semantic_hits'] ?? null) ? count($memoryCtx['semantic_hits']) : 0;
        if ($hitCount > 0) {
            addLog($novelId, 'info', "语义召回生效：命中{$hitCount}条相关线索");
        } elseif (isset($memoryCtx['debug']['semantic_error'])) {
            addLog($novelId, 'warn', '语义召回失败：' . $memoryCtx['debug']['semantic_error']);
        }

        return ['engine' => $engine, 'memoryCtx' => $memoryCtx];
    }

    /**
     * Phase 3: 组装 AI 写作 prompt
     * @return array AI messages 数组
     */
    public static function buildPrompt(array $novel, array $chapter, ?array $memoryCtx): array
    {
        $previousSummary = getPreviousSummary($novel['id'], (int)$chapter['chapter_number']);
        // L4_previous_tail 总是字符串（拿不到时为 ''，非 null），用 ?? 会短路掉带回溯的
        // getPreviousTail() 兜底。空串时显式回退，确保上一章未 completed（乱序/重写/章节空洞）
        // 时仍能取到最近已完成章的结尾，保证上下章衔接。
        $previousTail    = !empty($memoryCtx['L4_previous_tail'])
            ? $memoryCtx['L4_previous_tail']
            : getPreviousTail($novel['id'], (int)$chapter['chapter_number']);

        // === v1.7 PRO Blueprint 代理：细化大纲为写作蓝图 ===
        if (getSystemSetting('ws_blueprint_enabled', false, 'bool')) {
            try {
                $enrichedOutline = self::runBlueprint($novel, $chapter, $previousTail, $memoryCtx);
                if ($enrichedOutline !== null && mb_strlen($enrichedOutline) > mb_strlen($chapter['outline'] ?? '')) {
                    $chapter['outline'] = $enrichedOutline;
                    addLog((int)$novel['id'], 'info', 'Blueprint 代理：大纲已细化为写作蓝图（' . mb_strlen($enrichedOutline) . '字）');
                }
            } catch (\Throwable $e) {
                addLog((int)$novel['id'], 'warn', 'Blueprint 代理跳过：' . $e->getMessage());
            }
        }

        // v1.4: 使用 ChapterPromptBuilder 替代 497 行函数，段落独立可测试
        require_once __DIR__ . '/ChapterPromptBuilder.php';
        $builder = new ChapterPromptBuilder($novel, $chapter, $previousSummary, $previousTail, $memoryCtx);

        $resolverResult = null;
        try {
            $resolverResult = self::runForeshadowingResolver($novel, $chapter);
        } catch (\Throwable $e) {
            addLog((int)$novel['id'], 'warn', 'ForeshadowingResolver 失败：' . $e->getMessage());
        }
        if ($resolverResult) {
            $builder->setResolverResult($resolverResult);
        }

        // 审计优化（2026-07-20）：降级提示放 user tail 强注意区，不再拼进 outline 被稀释
        $degradedHint = self::consumePostProcessDegradedFlag((int)$novel['id'], (int)$chapter['chapter_number']);
        if ($degradedHint !== '') {
            $builder->setDegradedHint($degradedHint);
        }

        return $builder->build();
    }

    /**
     * Phase 2.5: 主动伏笔回收规划
     *
     * 根据当前章节大纲和全书进度，从待回收伏笔中挑选最合适的伏笔，
     * 生成具体的回收指令，注入到 Prompt 中引导 AI 回收。
     */
    private static function runForeshadowingResolver(array $novel, array $chapter): ?array
    {
        $novelId = (int)$novel['id'];
        $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);
        $targetChapters = (int)($novel['target_chapters'] ?? 0);

        // 调试日志：检查前置条件
        addLog($novelId, 'debug', sprintf(
            '伏笔回收检查：章节=%d, target_chapters=%d',
            $chNum,
            $targetChapters
        ));

        if ($targetChapters <= 0) {
            addLog($novelId, 'warn', '伏笔回收跳过：target_chapters 未设置或为0');
            return null;
        }

        require_once __DIR__ . '/memory/ForeshadowingResolver.php';
        $resolver = new ForeshadowingResolver($novelId, $chNum, $targetChapters);
        $outline = trim((string)($chapter['outline'] ?? ''));
        $result = $resolver->planResolution($outline);

        // 调试日志：输出规划结果统计
        addLog($novelId, 'debug', sprintf(
            '伏笔回收规划结果：should_resolve=%s, pending=%d, phase=%s, pressure=%.2f',
            $result['should_resolve'] ? 'true' : 'false',
            $result['stats']['pending_count'] ?? 0,
            $result['stats']['plan']['phase'] ?? 'unknown',
            $result['stats']['pressure'] ?? 0
        ));

        if (!empty($result['should_resolve'])) {
            $itemDescs = array_map(
                fn($it) => "第{$it['planted_chapter']}章:{$it['description']}",
                $result['items']
            );
            addLog($novelId, 'info', sprintf(
                '伏笔回收规划：选中%d条 → %s',
                count($result['items']),
                implode(' | ', $itemDescs)
            ));
        }

        return $result;
    }

    /** 大纲强度关键词 → 温度偏移评分（computeTemperatureDelta 专用） */
    private const INTENSITY_KEYWORDS = [
        'high' => ['决战','反杀','爆发','突破','觉醒','大战','终极','生死','逆袭','翻盘','对决','屠杀','毁灭','渡劫','天劫','围攻','血战','绝杀','拼死'],
        'mid'  => ['对峙','阴谋','追杀','围困','抉择','考验','危机','伏击','暗算','冲突','对抗','谈判','布局','陷阱','试探'],
        'low'  => ['休整','修炼','日常','回忆','感悟','疗伤','整顿','休憩','闲聊','游历','探索','冥想','闭关','温习'],
    ];

    /**
     * 按全书进度/卷末位置/大纲强度/节奏标记/爽点计划计算温度偏移。
     * 结果与具体模型无关——streamWrite 在进入模型重试循环前调用一次，
     * 不再每次重试都重查 volume_outlines/chapters 并重扫关键词。
     */
    private static function computeTemperatureDelta(int $novelId, int $chapterNumber, int $targetChapters): float
    {
        if ($chapterNumber <= 0 || $targetChapters <= 0) return 0.0;

        $progressRatio = $chapterNumber / $targetChapters;
        $tempDelta = 0.0;

        $isVolumeEnd = false;
        try {
            // 向导新建的表只有 chapter_to（无 end_chapter），优先查新列
            $volEnd = DB::fetch(
                'SELECT 1 FROM volume_outlines WHERE novel_id=? AND chapter_to=?',
                [$novelId, $chapterNumber]
            );
            $isVolumeEnd = !empty($volEnd);
        } catch (Throwable $e) {
            // 审计修复 C-5（2026-06-17）：外层 catch 不再静默吞异常
            // 旧 schema 只有 end_chapter 列时，chapter_to 查询失败是预期的，
            // 但若为其它原因（连接失败/字段改名）须排查。
            if (stripos($e->getMessage(), 'end_chapter') === false
                && stripos($e->getMessage(), 'chapter_to') === false
                && stripos($e->getMessage(), 'Unknown column') === false) {
                error_log('write_engine: 异常无法识别（旧 schema 兼容?）— ' . $e->getMessage());
            }
            try {
                $volEnd = DB::fetch(
                    'SELECT 1 FROM volume_outlines WHERE novel_id=? AND end_chapter=?',
                    [$novelId, $chapterNumber]
                );
                $isVolumeEnd = !empty($volEnd);
            } catch (Throwable $e2) { error_log('write_engine volume end check failed: ' . $e2->getMessage()); }
        }

        $chMeta = null;
        try {
            $chMeta = DB::fetch(
                'SELECT outline, pacing, cool_point_type FROM chapters WHERE novel_id=? AND chapter_number=?',
                [$novelId, $chapterNumber]
            );
        } catch (Throwable $e) { error_log('write_engine chapter meta fetch failed: ' . $e->getMessage()); }

        $outline = trim((string)($chMeta['outline'] ?? ''));
        $pacing  = $chMeta['pacing'] ?? '';
        $cpType  = $chMeta['cool_point_type'] ?? '';

        $highHits = 0; $midHits = 0; $lowHits = 0;
        if ($outline !== '') {
            foreach (self::INTENSITY_KEYWORDS['high'] as $kw) { if (mb_strpos($outline, $kw) !== false) $highHits++; }
            foreach (self::INTENSITY_KEYWORDS['mid']  as $kw) { if (mb_strpos($outline, $kw) !== false) $midHits++; }
            foreach (self::INTENSITY_KEYWORDS['low']  as $kw) { if (mb_strpos($outline, $kw) !== false) $lowHits++; }
        }

        $outlineScore = min(0.15, $highHits * 0.05)
                      + min(0.06, $midHits * 0.02)
                      - min(0.12, $lowHits * 0.04);

        $pacingDelta = match($pacing) {
            '快' => 0.05,
            'slow', '慢' => -0.05,
            default => 0.0,
        };

        $cpDelta = 0.0;
        if ($cpType !== '' && $cpType !== 'none') {
            $highCp = ['underdog_win','face_slap','last_stand','truth_reveal'];
            $cpDelta = in_array($cpType, $highCp, true) ? 0.06 : 0.03;
        }

        if ($isVolumeEnd) {
            $tempDelta += 0.12;
        } elseif ($progressRatio > 0.85) {
            $tempDelta += 0.10;
        } elseif ($progressRatio < 0.1) {
            $tempDelta -= 0.08;
        }

        $tempDelta += $outlineScore + $pacingDelta + $cpDelta;
        return max(-0.2, min(0.25, $tempDelta));
    }

    /**
     * 正文可被视为完整章节的绝对安全下限。
     *
     * 正常章节至少达到目标字数的 30%，且不低于 200 字；对于本身小于
     * 200 字的特殊短章，不把下限抬高到目标值以上。
     */
    private static function hardMinimumWords(int $targetWords): int
    {
        if ($targetWords <= 0) {
            return 200;
        }

        return min($targetWords, max(200, (int)ceil($targetWords * 0.30)));
    }

    /**
     * Phase 4: 带模型回退的流式写作
     * @param callable $onChunk      fn(string $token): void
     * @param callable $onMsg        fn(array $payload): void
     * @param callable $onHeartbeat  fn(): void
     * @param callable|null $onThinking fn(string $reasoningChunk): void  深度思考过程回调
     * @return array{content: string, model: ?AIClient}
     * @throws Exception 取消或全部模型失败
     */
    public static function streamWrite(
        array $messages,
        int $targetWords,
        int $novelId,
        callable $onChunk,
        callable $onMsg,
        callable $onHeartbeat,
        ?callable $onThinking = null,
        ?int $preferredModelId = null,
        int $chapterNumber = 0,
        int $targetChapters = 0,
        ?callable $onWaiting = null
    ): array {
        $modelList   = getModelFallbackList($preferredModelId);
        $modelErrors = [];
        $fullContent = '';
        $usedModel   = null;
        $estTokens   = (int)($targetWords * CFG_TOKEN_RATIO) + CFG_TOKEN_BUFFER;
        $usage       = null;
        $durationMs  = null;
        $hardMinWords = self::hardMinimumWords($targetWords);
        $lengthTolerance = calculateDynamicTolerance($targetWords);
        $silenceMinWords = max($hardMinWords, (int)($lengthTolerance['min'] ?? $hardMinWords));
        // 不同模型 tokenizer 差异很大；按 1 个 Unicode 字符≈1 token 保守估算输入。
        $promptChars = 0;
        foreach ($messages as $message) {
            $promptChars += mb_strlen((string)($message['content'] ?? ''));
        }
        // 温度偏移与模型无关，进入重试循环前只算一次
        $tempDelta   = self::computeTemperatureDelta($novelId, $chapterNumber, $targetChapters);
        $resetPartialContent = static function (string $reason) use (&$fullContent, $onMsg): void {
            if ($fullContent === '') {
                return;
            }
            $discardedWords = countWords($fullContent);
            $fullContent = '';
            $onMsg([
                'reset_content'  => true,
                'discarded_words' => $discardedWords,
                'reason'         => $reason,
            ]);
        };

        foreach ($modelList as $modelCfg) {
            $modelId    = (int)($modelCfg['id'] ?? 0);
            $modelLabel = $modelCfg['name'] ?? "模型{$modelId}";
            $isThinking = !empty($modelCfg['thinking_enabled']);
            $timeoutSec = $isThinking ? RT_THINKING_TIMEOUT : RT_NONTHINKING_TIMEOUT;

            if (($modelErrors[$modelId] ?? 0) >= RT_MODEL_ERR_MAX) {
                $onMsg(['info' => "模型 {$modelLabel} 错误次数过多，跳过"]);
                continue;
            }

            $sameModelRetries = 0;
            while ($sameModelRetries < RT_SAME_MODEL_MAX) {
                if ($sameModelRetries > 0) {
                    $retryDelay = RT_RETRY_DELAY * $sameModelRetries;
                    $onMsg(['waiting' => true, 'msg' => "等待 {$retryDelay} 秒后重试..."]);
                    for ($w = 0; $w < $retryDelay; $w += RT_POLL_INTERVAL) {
                        sleep(min(RT_POLL_INTERVAL, $retryDelay - $w));
                        $onHeartbeat();
                        // v1.4 文件系统检查替代 DB 查询，file_exists() 比 PDO prepare+execute 快 100+ 倍
                        if (file_exists(self::cancelFlagPath($novelId))) {
                            throw new Exception('用户取消了写作');
                        }
                    }
                }

                $streamStart = time();
                $fullContent = '';
                $ai = new AIClient($modelCfg);
                $usedModel = $ai;
                // 审计优化 P2-1（2026-06-16）：显式传入 $onWaiting，替代 $GLOBALS['sendWaiting']
                $waitingCb = $onWaiting;
                if ($waitingCb === null && isset($GLOBALS['sendWaiting']) && is_callable($GLOBALS['sendWaiting'])) {
                    $waitingCb = $GLOBALS['sendWaiting'];
                }
                $ai->setCallbacks(
                    $onHeartbeat,
                    $waitingCb,
                    function() use ($novelId) {
                        return file_exists(self::cancelFlagPath($novelId));
                    }
                );

                $desired = max($ai->getMaxTokens(), $estTokens);
                if ($desired > $ai->getMaxTokens()) {
                    $ai->setMaxTokens($desired);
                    $onMsg(['info' => "📊 max_tokens 调至 {$desired}"]);
                }

                // 完整上下文是按首选模型构建的。回退到上下文较小的模型时，绝不能
                // 原样发送一个超窗 prompt；宁可跳过该模型并给出明确诊断。
                $contextLimit = $ai->getContextLimit();
                $safeInputLimit = (int)floor($contextLimit * 0.90);
                if ($promptChars + $desired > $safeInputLimit) {
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $onMsg([
                        'info' => "跳过模型 {$modelLabel}：上下文容量不足（估算输入{$promptChars} token，模型上限{$contextLimit}）",
                        'context_too_large' => true,
                    ]);
                    continue 2;
                }

                $onMsg([
                    'model' => $modelLabel, 'attempt' => $sameModelRetries + 1,
                    'timeout' => $timeoutSec, 'thinking' => $isThinking,
                ]);

                if (abs($tempDelta) >= 0.02) {
                    $ai->setTemperature($ai->getTemperature() + $tempDelta);
                }

                $canceled = false; $cancelCount = 0;
                $cancelCheckInterval = 10;
                try {
                    $usage = $ai->chatStream($messages, function(string $token) use (&$fullContent, $novelId, &$canceled, &$cancelCount, $cancelCheckInterval, $onChunk) {
                        if (!$canceled && ++$cancelCount % $cancelCheckInterval === 0) {
                            if (file_exists(self::cancelFlagPath($novelId))) $canceled = true;
                        }
                        // v1.11.10: 在 cURL 内部 throw 异常会跨 C 边界，Windows CLI/Web FastCGI 易发进程级闪退。
                        // 改为 return 让本回调安全退出；由 AIClient 的 progress 钩子 cancelCheckCallback 在 cURL 外部抛 RuntimeException('用户取消了写作')。
                        if ($canceled) return;
                        if ($token === '[DONE]') return;
                        $fullContent .= $token;
                        $onChunk($token);
                    }, 'creative', $onThinking);
                } catch (Exception $e) {
                    $errMsg = $e->getMessage();
                    if ($errMsg === '用户取消了写作') throw $e;
                    // chatStream 可能已经推送过若干 chunk 才抛错；失败尝试的半截
                    // 缓冲绝不能在最后一个模型耗尽后被当成成功结果返回。
                    $resetPartialContent('模型调用失败，已清除本次未完成正文');
                    error_log("WriteEngine streamWrite model#{$modelId} failed: {$errMsg}");
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $sameModelRetries++;

                    // W-15 修复：429 速率限制单独识别并指数退避（3s/6s/12s），与 withModelFallback 一致。
                    // 之前所有异常一视同仁，限流被当成普通失败立即重试，浪费重试预算。
                    $is429 = stripos($errMsg, '429') !== false || stripos($errMsg, 'rate limit') !== false || stripos($errMsg, 'too many') !== false;
                    if ($is429 && $sameModelRetries < RT_SAME_MODEL_MAX) {
                        $rateLimitDelay = 3 * (1 << min($sameModelRetries - 1, 2)); // 3s, 6s, 12s
                        $onMsg([
                            'waiting' => true,
                            'reason' => "API 限流(429)，{$rateLimitDelay}秒后重试",
                            'retry' => "第{$sameModelRetries}次 / " . RT_SAME_MODEL_MAX,
                        ]);
                        // P0-1 优化：429 退避期间响应取消标志，避免用户取消后仍等待完整退避时长
                        for ($s = 0; $s < $rateLimitDelay; $s++) {
                            if (file_exists(self::cancelFlagPath($novelId))) {
                                throw new Exception('用户取消了写作');
                            }
                            sleep(1);
                            $onHeartbeat();
                        }
                        continue;
                    }

                    $onMsg([
                        'waiting' => true,
                        'reason' => '模型调用失败，正在重试（已耗时' . (time() - $streamStart) . '秒）',
                        'retry' => "第{$sameModelRetries}次 / " . RT_SAME_MODEL_MAX,
                    ]);
                    // 同模型内重试：未达上限则留在当前 while 循环，达上限则跳到下一模型
                    if ($sameModelRetries >= RT_SAME_MODEL_MAX) continue 2;
                    continue; // 重试当前模型
                }

                // v1.4: 采集 token 用量和实际耗时，为 OptimizationAgent 提供真实数据基础
                $durationMs = (time() - $streamStart) * 1000;

                if ($ai->lastFinishReason === 'silence_timeout') {
                    $partialWords = countWords($fullContent);
                    if ($partialWords >= $silenceMinWords) {
                        $onMsg(['warning' => "⚠️ AI 静默超时，但正文已达到正常长度下限（{$partialWords}字）"]);
                        break 2;
                    }
                    $resetPartialContent('AI 静默超时且正文不足，已清除本次未完成正文');
                    $sameModelRetries++;
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $onMsg([
                        'waiting' => true,
                        'reason' => "静默超时且正文未达到正常长度下限（{$partialWords}字 < {$silenceMinWords}字），重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX,
                    ]);
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                    continue 2;
                }

                $sinceLast = time() - ($ai->lastChunkTime ?: $streamStart);
                if ($sinceLast >= $timeoutSec) {
                    $timedOutWords = countWords($fullContent);
                    $resetPartialContent('AI 输出超时，已清除本次未完成正文');
                    $sameModelRetries++;
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $onMsg([
                        'waiting' => true,
                        'reason' => "超时（{$sinceLast}秒无有效输出，丢弃{$timedOutWords}字部分正文），重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX,
                    ]);
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                    continue 2;
                }

                // v1.5.4: 所有完成状态都检查字数超限（不仅限 finish_reason=length）
                // AI 模型常忽略字数指令正常完成(finish_reason=stop)，导致 1000→2000 的偏差
                $actualWords = countWords($fullContent);
                if ($actualWords < $hardMinWords) {
                    $resetPartialContent('正文低于安全字数下限，已清除本次未完成正文');
                    $sameModelRetries++;
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $onMsg([
                        'waiting' => true,
                        'reason' => "模型返回正文疑似截断（{$actualWords}字 < 安全下限{$hardMinWords}字），重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX,
                    ]);
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                    continue 2;
                }

                $modelErrors[$modelId] = 0;
                $lenMax = $lengthTolerance['max'];
                if ($actualWords > $lenMax) {
                    // 审计优化（2026-07-20）：保钩子截断，避免章末悬念被硬切
                    $fullContent = truncateToWordLimitPreservingHook($fullContent, $lenMax);
                    $reason = $ai->lastFinishReason === 'length' ? 'max_tokens截断后' : 'AI正常完成但';
                    $onMsg(['warning' => "⚠️ {$reason}超字（{$actualWords}字），已保钩子修剪至 " . countWords($fullContent) . " 字"]);
                } elseif ($ai->lastFinishReason === 'length') {
                    $onMsg(['info' => "📋 触发max_tokens上限（{$actualWords}字），内容在允许范围内"]);
                }
                break 2;
            }
        }

        if (empty($fullContent)) {
            $errorSummary = [];
            foreach ($modelErrors as $mid => $cnt) {
                $errorSummary[] = "模型#{$mid}失败{$cnt}次";
            }
            throw new RuntimeException('所有模型均未产出内容：' . implode('；', $errorSummary ?: ['无可用模型']));
        }

        return ['content' => $fullContent, 'model' => $usedModel, 'usage' => $usage, 'duration_ms' => $durationMs];
    }

    /**
     * Phase 5: 落盘正文 + 版本备份 + 取消检测
     * @param ?array $usage     chatStream() 返回的 usage 数组 ['prompt_tokens','completion_tokens','total_tokens']
     * @param ?int   $durationMs 本章生成耗时（毫秒）
     * @return array{words: int, chapter: array}
     * @throws RuntimeException
     */
    public static function saveChapter(int $chapterId, int $novelId, string $fullContent, int $targetWords, ?AIClient $usedModel, array $chapter, ?array $usage = null, ?int $durationMs = null): array
    {
        $ch = $chapter;
        $chId = $chapterId;

        // 落盘前取消检测（v1.4 文件系统加速）——置于版本备份之前，
        // 用户已取消时不再白写一条 chapter_versions
        if (file_exists(self::cancelFlagPath($novelId))) {
            throw new RuntimeException('canceled');
        }

        // 过滤AI误生成的段落标记
        $fullContent = stripSegmentMarkers($fullContent);
        // 过滤AI误把章节坐标写进正文（伏笔回收穿帮）
        $fullContent = stripMetaLeaks($fullContent);

        // 保存层独立兜底：无论上游来自流式写作、worker 还是其他调用方，
        // 明显截断的正文都不能进入 completed 状态。该规则不受“严格模式”开关影响。
        $words = countWords($fullContent);
        $hardMinWords = self::hardMinimumWords($targetWords);
        if ($words < $hardMinWords) {
            addLog(
                $novelId,
                'error',
                "第{$ch['chapter_number']}章疑似截断：{$words}字 < 安全下限{$hardMinWords}字，拒绝落盘",
                $chId
            );
            throw new WriteEngineValidationException(
                "章节内容疑似截断（{$words}字，至少需要{$hardMinWords}字），未标记为完成"
            );
        }

        // 旧版本备份将在最终数据库事务内执行，确保备份、正文、统计和后处理
        // 失效标记共享同一个提交边界。

        // === 标题禁用词「落盘前自动改写」（根治：标题P0阻断落盘→挂机死锁）===
        // 必须在后置校验之前执行，使校验看到的是已清洗的标题，从根上避免 P0 阻断。
        $titleAutoFixed = false;
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/TitleSanitizer.php';
            if (ConstraintConfig::isEnabled()) {
                $san = TitleSanitizer::sanitize((string)($ch['title'] ?? ''), (int)($ch['chapter_number'] ?? 0));
                if ($san['changed']) {
                    $oldTitle       = (string)$ch['title'];
                    $ch['title']    = $san['title'];
                    $titleAutoFixed = true;
                    addLog($novelId, 'info', sprintf(
                        '第%d章标题含禁用词「%s」，已自动改写：《%s》→《%s》（不再阻断落盘）',
                        (int)$ch['chapter_number'],
                        implode('、', $san['hit_words']),
                        $oldTitle,
                        $ch['title']
                    ), $chId);
                }
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '标题自动改写跳过：' . $e->getMessage());
        }

        // === 约束框架后置校验 ===
        // P0 判定在 try 外部执行：即使校验过程发生异常，已检测到的 P0 也不会被静默放行
        $validationResult = null;
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/ConstraintStateDB.php';
            require_once __DIR__ . '/constraints/PostWriteValidator.php';
            $validator = new PostWriteValidator($novelId, $ch, $fullContent, $targetWords);
            $validationResult = $validator->run();
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '约束后置校验跳过：' . $e->getMessage());
            $strictMode = false;
            try {
                $strictMode = class_exists('ConstraintConfig')
                    ? ConstraintConfig::isStrictMode()
                    : (bool)getSystemSetting('cf_strict_mode', false, 'bool');
            } catch (\Throwable $strictReadError) {
                // 严格模式配置本身不可读时保持普通模式兼容；原始校验错误已记录。
            }
            if ($strictMode) {
                throw new WriteEngineValidationException(
                    '严格模式约束校验异常，已阻止正文落盘：' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $p0StrictBlock = false;

        if ($validationResult && $validationResult['has_p0']) {
            // === 紧急响应通道（工程控制论：缩短响应延迟）===
            // P0 严重问题不管是否严格模式，都立即写紧急指令到下一章
            $urgentIssues = [];
            foreach ($validationResult['p0_issues'] as $p0) {
                $urgentIssues[] = $p0['issue_desc'];
            }
            $urgentMsg = "【紧急修复】上章发生严重问题：" . implode('；', $urgentIssues) .
                "。本章必须立即修正，避免问题延续。";
            try {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                AgentDirectives::add(
                    $novelId,
                    (int)$ch['chapter_number'] + 1,
                    'urgent',
                    $urgentMsg,
                    1,    // 只影响下一章
                    24    // 24小时过期
                );
                addLog($novelId, 'warn', sprintf(
                    '紧急通道触发：第%d章P0问题已写紧急指令',
                    (int)$ch['chapter_number']
                ));
            } catch (\Throwable $e) {
                addLog($novelId, 'warn', '紧急指令写入失败：' . $e->getMessage());
            }

            if (ConstraintConfig::isStrictMode()) {
                $issue = $validationResult['p0_issues'][0]['issue_desc'];
                $p0StrictBlock = "严格模式：第{$ch['chapter_number']}章 P0 违规阻止落盘 — {$issue}";
            }
        } elseif ($validationResult && $validationResult['has_p1']) {
            $p1Count = count($validationResult['p1_issues']);
            addLog($novelId, 'warn', "第{$ch['chapter_number']}章触发{$p1Count}项P1约束");
        }

        if ($p0StrictBlock !== false) {
            throw new WriteEngineValidationException($p0StrictBlock);
        }

        $updates = [
            'content' => $fullContent, 'words' => $words, 'status' => 'completed',
            'retry_count' => 0,
        ];
        // 标题被自动改写时，连同新标题一起落盘（否则恢复后仍是旧标题，死锁复现）
        if ($titleAutoFixed) {
            $updates['title'] = (string)$ch['title'];
        }
        // v1.4: 落盘 token 用量和耗时数据，为 OptimizationAgent 提供真实数据基础
        if ($usage !== null && isset($usage['total_tokens'])) {
            $updates['tokens_used'] = (int)$usage['total_tokens'];
        }
        // v41: 落盘提示词缓存命中 token（1000章优化埋点）
        if ($usage !== null && isset($usage['cache_hit_tokens'])) {
            $updates['cache_hit_tokens'] = (int)$usage['cache_hit_tokens'];
            $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
            if ($promptTokens > 0 && $usage['cache_hit_tokens'] > 0) {
                $hitPct = round($usage['cache_hit_tokens'] / $promptTokens * 100, 1);
                addLog($novelId, 'info', "提示词缓存命中：{$usage['cache_hit_tokens']}/{$promptTokens} tokens（{$hitPct}%）", $chId);
            }
        }
        if ($durationMs !== null) {
            $updates['duration_ms'] = $durationMs;
        }
        // v1.5: 落盘 hook_type，激活 suggestHookType 的"防连续重复"机制
        // 之前该字段从未被写入，导致防重复逻辑形同虚设
        // 审计修复 A02（2026-06-16）：移除防御性 try/catch
        // suggestHookType 内部仅做正则与数组操作，不会抛 RuntimeException
        $hookSuggestion = suggestHookType($ch);
        if (!empty($hookSuggestion['type'])) {
            $updates['hook_type'] = $hookSuggestion['type'];
        }
        // v1.7: 落盘 opening_type，与 hook_type 同模式
        $openingSuggestion = suggestOpeningType($ch);
        if (!empty($openingSuggestion['type'])) {
            $updates['opening_type'] = $openingSuggestion['type'];
        }
        $pdo = DB::getPdo();
        $ownTx = !$pdo->inTransaction();
        static $savepointSequence = 0;
        $savepoint = null;
        $pendingCount = 0;
        $nextCh = null;

        try {
            if ($ownTx) {
                if (!$pdo->beginTransaction()) {
                    throw new WriteEnginePersistenceException('无法开启正文保存事务');
                }
            } else {
                $savepoint = 'sp_write_chapter_' . $chId . '_' . (++$savepointSequence);
                $pdo->exec("SAVEPOINT `{$savepoint}`");
            }

            // 通过完整性与约束校验后才备份旧正文；backupChapterVersion 会在当前
            // 事务内使用嵌套 savepoint，不会提前提交外层事务。
            backupChapterVersion($chId);

            $affected = DB::update('chapters', $updates, 'id=? AND status="writing"', [$chId]);
            if ($affected === 0) {
                throw new WriteEnginePersistenceException('写作已被中断（章节状态已变更）');
            }

            // 新正文必须重新走记忆最终化。正文、统计与失效标记必须原子提交。
            DB::delete(
                'writing_logs',
                'novel_id=? AND chapter_id=? AND action="memory_finalized"',
                [$novelId, $chId]
            );
            updateNovelStats($novelId, 1, (int)$words);

            $pendingCount = DB::count('chapters', 'novel_id=? AND status != "completed"', [$novelId]);
            if ($pendingCount === 0) {
                DB::update('novels', ['status' => 'completed'], 'id=?', [$novelId]);
            }

            $nextAutoChapterId = self::findNextAutomaticChapterId($novelId);
            $nextCh = $nextAutoChapterId
                ? DB::fetch(
                    'SELECT id, chapter_number FROM chapters WHERE id=? AND novel_id=?',
                    [$nextAutoChapterId, $novelId]
                )
                : null;

            if ($ownTx) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            }
        } catch (\Throwable $e) {
            try {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                } elseif (!$ownTx && $savepoint !== null && $pdo->inTransaction()) {
                    $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
                    $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
                }
            } catch (\Throwable $rollbackError) {
                error_log('saveChapter rollback failed: ' . $rollbackError->getMessage());
            }

            if ($e instanceof WriteEnginePersistenceException) {
                throw $e;
            }
            throw new WriteEnginePersistenceException(
                '正文事务落盘失败：' . $e->getMessage(),
                0,
                $e
            );
        }

        try {
            clearChapterCache($chId, $novelId);
        } catch (\Throwable $cacheError) {
            error_log('saveChapter cache clear failed: ' . $cacheError->getMessage());
        }

        // v2: 标记本章「后处理进行中」——下一章 resolveChapter 会等待此标志清除，
        // 确保摘要/记忆/Agent指令落库后再构建下一章 prompt（消除记忆竞态）。
        try {
            @file_put_contents(
                self::postProcessPendingFlag($novelId, (int)$ch['chapter_number']),
                (string)time()
            );
        } catch (\Throwable $e) { /* 标志写入失败不影响落盘 */ }

        $modelInfo = $usedModel ? "（{$usedModel->modelLabel}）" : '';
        $wordDiff = $words - $targetWords;
        $wordDiffPct = $targetWords > 0 ? round(abs($wordDiff) / $targetWords * 100, 1) : 0;
        $diffMark = $wordDiff > 0 ? "+{$wordDiff}" : "{$wordDiff}";
        try {
            addLog($novelId, 'write',
                "完成第{$ch['chapter_number']}章《{$ch['title']}》，共{$words}字（目标{$targetWords}字，偏差{$diffMark}字/{$wordDiffPct}%）{$modelInfo}",
                $chId
            );
        } catch (\Throwable $logError) {
            error_log('saveChapter success log failed: ' . $logError->getMessage());
        }

        return [
            'words'            => $words,
            'chapter'          => $ch,
            'all_done'         => $pendingCount === 0,
            'model_info'       => $modelInfo,
            'next_chapter_id'  => $nextCh['id'] ?? null,
            'next_chapter_num' => $nextCh['chapter_number'] ?? null,
        ];
    }

    /**
     * 安全执行后处理任务，统一捕获异常并记录日志
     *
     * 审计修复 B02（2026-06-16）：替代 postProcess 中 20+ 个重复的
     * try { ... } catch (Throwable $e) { addLog(..., 'warn', '...跳过：' . $e->getMessage()); }
     * 模板，减少约 200 行冗余代码。
     *
     * @param int      $novelId 小说ID
     * @param string   $label   任务标签（用于日志，如"伏笔提及追踪"）
     * @param callable $fn      任务回调 fn(): void
     * @param string   $level   日志级别（默认 warn）
     */
    private static function safeRun(int $novelId, string $label, callable $fn, string $level = 'warn'): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            addLog($novelId, $level, $label . '跳过：' . $e->getMessage());
        }
    }

    public static function postProcess(
        int $novelId,
        array $chapter,
        string $fullContent,
        MemoryEngine $engine,
        bool $resumeAfterFinalization = false
    ): void
    {
        $chId = $chapter['id'];
        // 审计修复 B07（2026-06-16）：$chNum 统一定义一次，替代方法内 20+ 次重复
        $chNum = (int)($chapter['chapter_number'] ?? 0);

        try {

        // --- 最终正文版本守卫 + 单次记忆最终化 ---
        // 初稿、重写中间稿都不得写入长期记忆。只有仍与数据库正文完全一致的
        // 最终版本，才允许生成摘要并执行一次 MemoryEngine::ingestChapter()。
        require_once __DIR__ . '/ChapterMemoryFinalizer.php';

        $summaryData = [];
        $ingest = [];
        $novelData = DB::fetch(
            'SELECT id, title, genre, writing_style, protagonist_name, protagonist_info, model_id, chapter_words
             FROM novels WHERE id=?',
            [$novelId]
        ) ?: ['id' => $novelId];

        $storedChapter = DB::fetch(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" LIMIT 1',
            [$chId, $novelId]
        );
        // saveChapter 会在落盘前清洗段落标记/章节坐标；调用方仍可能持有清洗前
        // 的流式缓冲，因此版本比较必须使用同一规范化规则。
        $requestedContent = stripMetaLeaks(stripSegmentMarkers($fullContent));
        $requestedHash = hash('sha256', $requestedContent);
        if (!$storedChapter
            || !hash_equals($requestedHash, hash('sha256', (string)($storedChapter['content'] ?? '')))) {
            addLog($novelId, 'warn', "第{$chNum}章后处理已过期：正文版本已变化，丢弃旧任务", $chId);
            return;
        }

        // 后续所有质量检测、约束、知识库和记忆均使用数据库确认过的同一正文。
        $chapter = array_merge($chapter, $storedChapter);
        $fullContent = (string)$storedChapter['content'];
        $memoryAlreadyFinalized = ChapterMemoryFinalizer::hasFinalizedRevision($novelId, $chId, $requestedHash);
        if ($memoryAlreadyFinalized && !$resumeAfterFinalization) {
            addLog($novelId, 'info', "第{$chNum}章相同正文版本已完成后处理，跳过重复任务", $chId);
            return;
        }
        if ($memoryAlreadyFinalized) {
            $decodedSummary = json_decode((string)($storedChapter['chapter_summary'] ?? ''), true);
            $summaryData = is_array($decodedSummary) ? $decodedSummary : [];
            addLog($novelId, 'info', "第{$chNum}章记忆已最终化，继续恢复后续投影", $chId);
        }

        // --- 质量检测 + 纯函数式重写决策 ---
        // RewriteAgent 仅返回候选正文；任何正文/记忆写入都由本方法统一提交。
        $rewriteEnabled = (bool)getSystemSetting('ws_rewrite_enabled', false, 'bool');
        $gatesResult = ['gates' => [], 'score' => 100.0];
        $wasRewritten = false;
        if ($rewriteEnabled && !$memoryAlreadyFinalized) {
            $gatesResult = self::runQualityGates($novelId, $chId, $fullContent);

            try {
                require_once __DIR__ . '/agents/RewriteAgent.php';
                // chapterId=0 禁止迭代控制器在候选被采纳前提前写入章节元数据。
                $rewriter = new RewriteAgent($novelId, 0);
                $rewriteResult = $rewriter->rewriteIfNeeded(
                    $chapter,
                    $fullContent,
                    $gatesResult['gates'] ?? [],
                    (float)($gatesResult['score'] ?? 100.0),
                    isset($novelData['model_id']) ? (int)$novelData['model_id'] : null
                );

                if (!empty($rewriteResult['rewritten']) && is_string($rewriteResult['content'] ?? null)) {
                    $candidate = stripMetaLeaks(stripSegmentMarkers(trim($rewriteResult['content'])));
                    $rwTarget = max(1, (int)($novelData['chapter_words'] ?? countWords($fullContent)));
                    $rwTolerance = calculateDynamicTolerance($rwTarget);
                    $candidateWords = countWords($candidate);
                    $hardMinimum = self::hardMinimumWords($rwTarget);

                    if ($candidateWords < $hardMinimum) {
                        addLog(
                            $novelId,
                            'warn',
                            "RewriteAgent候选疑似截断（{$candidateWords}字 < 安全下限{$hardMinimum}字），保留原正文",
                            $chId
                        );
                    } else {
                        if ($candidateWords > (int)$rwTolerance['max']) {
                            $candidate = truncateToWordLimitPreservingHook($candidate, (int)$rwTolerance['max']);
                            $candidateWords = countWords($candidate);
                            addLog(
                                $novelId,
                                'info',
                                "RewriteAgent候选超字，已压缩至{$candidateWords}字",
                                $chId
                            );
                        }

                        $originalContent = $fullContent;
                        $originalWords = countWords($originalContent);
                        if (!hash_equals(hash('sha256', $originalContent), hash('sha256', $candidate))) {
                            $history = $rewriteResult['iteration_history'] ?? [];
                            $evaluation = $rewriteResult['improvement_report'] ?? null;
                            $newScore = isset($rewriteResult['new_score'])
                                ? (float)$rewriteResult['new_score']
                                : (float)($gatesResult['score'] ?? 0);
                            $rewriteUpdates = [
                                'content' => $candidate,
                                'words' => $candidateWords,
                                // 初稿五关结果不能继续挂在重写后的正文上；完整五关会在
                                // 后续重新计算，期间仅保留 RewriteAgent 的候选评分。
                                'quality_score' => $newScore > 0 ? $newScore : null,
                                'gate_results' => null,
                                'rewritten' => 1,
                                'iterations_used' => max(0, (int)($rewriteResult['iterations_used'] ?? 0)),
                                'total_improvement' => max(
                                    0.0,
                                    $newScore - (float)($gatesResult['score'] ?? 0)
                                ),
                                'iterative_history' => $history !== []
                                    ? json_encode($history, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                                    : null,
                                'iteration_evaluation' => $evaluation !== null
                                    ? json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                                    : null,
                                'rewrite_time' => date('Y-m-d H:i:s'),
                            ];

                            // CAS：用户在重写 AI 调用期间若已编辑正文，旧任务绝不能覆盖新版本。
                            $affected = DB::update(
                                'chapters',
                                $rewriteUpdates,
                                'id=? AND novel_id=? AND status="completed" AND content=?',
                                [$chId, $novelId, $originalContent]
                            );
                            if ($affected === 0) {
                                addLog($novelId, 'warn', "第{$chNum}章重写提交冲突：正文已变化，丢弃候选", $chId);
                                return;
                            }

                            $fullContent = $candidate;
                            $chapter = array_merge($chapter, $rewriteUpdates);
                            $chapter['words'] = $candidateWords;
                            $wasRewritten = true;
                            clearChapterCache($chId, $novelId);
                            updateNovelStats($novelId, null, $candidateWords - $originalWords);
                            addLog(
                                $novelId,
                                'info',
                                "RewriteAgent重写已原子落盘：{$originalWords}字 → {$candidateWords}字",
                                $chId
                            );
                        }
                    }
                }
            } catch (Throwable $e) {
                // 重写属于可选优化；失败时保留已校验的原正文继续最终化。
                addLog($novelId, 'warn', 'RewriteAgent跳过：' . $e->getMessage(), $chId);
            }
        }

        // 摘要、MemoryEngine、伏笔提及、金句回调和角色出场在同一事务边界内
        // 只针对最终正文执行一次；失败则整批回滚，不留下半套投影。
        if ($memoryAlreadyFinalized) {
            $finalized = ['ok' => true, 'summary' => $summaryData, 'ingest' => []];
        } else {
            try {
                $finalized = ChapterMemoryFinalizer::finalize(
                    $novelId,
                    $chId,
                    $fullContent,
                    $engine
                );
            } catch (Throwable $memoryError) {
            // 重写正文已 CAS 落盘、但最终化事务失败时，宁可让本章暂时没有
            // 记忆，也不能保留可能来自旧正文的投影。锁竞争由另一任务负责，
            // 不在这里越过其 advisory lock 清理。
            if ($wasRewritten && !str_contains($memoryError->getMessage(), '正在由另一任务处理')) {
                try {
                    ChapterMutationService::resetGeneratedProjectionsForReingest(
                        $novelId,
                        $chNum,
                        $chId
                    );
                } catch (Throwable $cleanupError) {
                    addLog($novelId, 'error', '重写后失效旧记忆失败：' . $cleanupError->getMessage(), $chId);
                }
            }
                throw $memoryError;
            }
        }
        if (!empty($finalized['stale'])) {
            addLog($novelId, 'warn', "第{$chNum}章记忆最终化已过期，丢弃旧任务", $chId);
            return;
        }
        if (!empty($finalized['historical_deferred'])) {
            addLog(
                $novelId,
                'warn',
                "第{$chNum}章属于历史补写/修改：正文已保存，但累计记忆需按章节顺序重放，已禁止直接覆盖较新状态",
                $chId
            );
            return;
        }
        if (!empty($finalized['duplicate'])) {
            addLog($novelId, 'info', "第{$chNum}章记忆已由并发任务最终化，跳过重复后处理", $chId);
            return;
        }
        if (empty($finalized['ok'])) {
            throw new RuntimeException("第{$chNum}章记忆最终化失败");
        }

        $summaryData = (array)($finalized['summary'] ?? []);
        $ingest = (array)($finalized['ingest'] ?? []);
        $resolvedDetails = (array)($ingest['resolved_details'] ?? []);
        $resolvedLog = $resolvedDetails === []
            ? ''
            : ' → ' . implode(' | ', array_map(
                fn($row) => 'ID:' . ($row['id'] ?? '?') . '「' . ($row['desc'] ?? '') . '」',
                $resolvedDetails
            ));
        addLog($novelId, 'info', sprintf(
            '最终正文记忆入库：人物%d / 特征%d / 事件%d / 伏笔+%d / 回收%d%s',
            $ingest['cards_upserted'] ?? 0,
            $ingest['traits_added'] ?? 0,
            $ingest['events_added'] ?? 0,
            $ingest['foreshadowing_added'] ?? 0,
            $ingest['foreshadowing_resolved'] ?? 0,
            $resolvedLog
        ), $chId);

        // 最终化已完成提及追踪；这里只做伏笔健康告警，避免二次计数。
        self::safeRun($novelId, '伏笔健康检测', function() use ($novelId, $chNum) {
            require_once __DIR__ . '/memory/ForeshadowingRepo.php';
            $alerts = (new ForeshadowingRepo($novelId))->checkHealth($chNum);
            if ($alerts === []) {
                return;
            }
            $highAlerts = array_values(array_filter(
                $alerts,
                fn($alert) => ($alert['severity'] ?? '') === 'high'
            ));
            if ($highAlerts !== []) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $message = implode('；', array_map(
                    fn($alert) => (string)($alert['message'] ?? ''),
                    array_slice($highAlerts, 0, 2)
                ));
                $suggestion = implode('；', array_map(
                    fn($alert) => (string)($alert['suggestion'] ?? ''),
                    array_slice($highAlerts, 0, 2)
                ));
                AgentDirectives::addCapped(
                    $novelId,
                    $chNum + 1,
                    'quality',
                    "伏笔健康警告：{$message}。{$suggestion}",
                    2,
                    48,
                    2
                );
            }
            addLog(
                $novelId,
                'info',
                sprintf('伏笔健康检测：%d条告警（高危%d）', count($alerts), count($highAlerts))
            );
        });



        // === 约束框架状态更新（Phase 1）===
        // 下一章 prompt 要读约束状态，且须基于重写后的最终正文 → 留在标志窗口内
        self::safeRun($novelId, '约束状态更新', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/ConstraintStateDB.php';
            require_once __DIR__ . '/constraints/ConstraintStateUpdater.php';
            $stateUpdater = new ConstraintStateUpdater($novelId, $chapter, $fullContent);
            $stateUpdater->updateAll();
        });

        // 图谱关系提取依赖一次同步 AI 调用，移出 pp_pending 窗口外执行——
        // 它只影响后续章节的语义召回，迟到一章可接受；放在窗口内会与摘要/重写
        // 一起串联同步 AI 调用，极易把下一章的 waitForPostProcess 顶到超时
        // 强放行（导致下一章读到不一致的记忆状态）。
        // v2: 硬依赖（摘要/记忆/本地追踪/重写/约束状态）已全部落库，
        // 立即清除「后处理进行中」标志放行下一章。以下环节均为咨询型/统计型，
        // 写出的指令面向后续章节，迟到一章只损失一次提示，不值得阻塞写作管线
        // （原先标志直到全书圣经之前才清除，重活全压在 waitForPostProcess 的等待窗口里）。
        @unlink(self::postProcessPendingFlag($novelId, (int)$chapter['chapter_number']));

        // 图谱提取（移出 pp_pending 窗口外，与 KB 同档执行）
        self::safeRun($novelId, '图谱关系提取', function() use ($novelId, $chNum, $fullContent, $novelData) {
            if ($chNum > 0 && getSystemSetting('ws_graph_search_enabled', true, 'bool')) {
                self::extractAndStoreGraphRelations($novelId, $chNum, $fullContent, $novelData['model_id'] ?? null);
            }
        });

        // --- v1.11.2 认知负荷检测（信息密度管理）---
        self::safeRun($novelId, '认知负荷检测', function() use ($novelId, $chNum, $chId, $ingest) {
            require_once __DIR__ . '/CognitiveLoadMonitor.php';
            $loadMonitor = new CognitiveLoadMonitor($novelId);
            $loadResult = $loadMonitor->analyze($chNum, $ingest ?? []);
            $loadMonitor->persistMetrics($chId, $loadResult);

            // 认知负荷超标时写 Agent 指令
            if (isset($loadResult['severity']) && in_array($loadResult['severity'], ['high', 'medium'])) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $existingCL = DB::fetch(
                    "SELECT id FROM agent_directives
                     WHERE novel_id=? AND type='strategy' AND is_active=1
                       AND apply_from <= ? AND apply_to >= ?
                       AND directive LIKE '%认知负荷%'
                     LIMIT 1",
                    [$novelId, $chNum + 1, $chNum + 1]
                );
                if (!$existingCL) {
                    AgentDirectives::add(
                        $novelId,
                        $chNum + 1,
                        'strategy',
                        $loadResult['directive'],
                        2,
                        48
                    );
                }
                addLog($novelId, 'warn', sprintf(
                    '认知负荷警告：本章引入 %d 个新元素（近5章累计 %d 个）',
                    $loadResult['total_new'] ?? 0,
                    $loadResult['recent_5_sum'] ?? 0
                ));
            }
        });

        // --- v41 钩子回收验证（闭环：本章是否承接了上章章末钩子）---
        self::safeRun($novelId, '钩子回收验证', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/HookPayoffChecker.php';
            HookPayoffChecker::run($novelId, $chapter, $fullContent);
        });

        // --- 知识库提取（重排后基于重写后的最终正文提取；原先在重写前跑，存在内容不一致）---
        self::safeRun($novelId, '知识库提取', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/embedding.php';
            $kb = new KnowledgeBase($novelId);
            $kbStats = $kb->extractFromChapter((int)$chapter['chapter_number'], $fullContent, (int)($chapter['id'] ?? 0));
            if (!empty(array_filter($kbStats))) {
                addLog($novelId, 'info', '知识库提取完成：角色' . ($kbStats['characters']??0) . '个，世界观' . ($kbStats['worldbuilding']??0) . '个，情节' . ($kbStats['plots']??0) . '个');
            }
        }, 'error');

        // --- 质量检测 ---
        // 未启用重写：正常跑；启用重写但未触发重写：重写前已跑，跳过；
        // 启用重写且触发了重写：最终正文必须完整重跑五关，不能复用初稿的
        // 人物、爽点和一致性结果，否则质量元数据仍会对应旧版本。
        if (!$rewriteEnabled) {
            self::runQualityGates($novelId, $chId, $fullContent);
        } elseif ($wasRewritten) {
            self::runQualityGates($novelId, $chId, $fullContent);
        }

        // --- v1.9 CriticAgent + StyleGuard — 统一纳入 AgentCoordinator ---
        self::safeRun($novelId, '后置Agent协调器', function() use ($novelId, $chapter, $fullContent, $novelData) {
            require_once __DIR__ . '/agents/AgentCoordinator.php';
            AgentCoordinator::postWriteAgents($novelId, $chapter, $fullContent, [
                'title'            => $novelData['title'] ?? '',
                'genre'            => $novelData['genre'] ?? '',
                'protagonist_name' => $novelData['protagonist_name'] ?? '',
                'model_id'         => $novelData['model_id'] ?? null,
            ]);
        });

        // --- v1.5 情绪密度检测（激活 EmotionDictionary）---
        // 之前 EmotionDictionary 模块完全是死代码，prompt 里教 AI 满足情绪密度
        // 但写完后从未验证。本节将统计落盘，并在偏低时让 Agent 写指令影响下一章
        self::safeRun($novelId, '情绪密度检测', function() use ($novelId, $chapter, $chId, $fullContent) {
            require_once __DIR__ . '/emotion_dict.php';
            $emoDensity = EmotionDictionary::countEmotionDensity($fullContent);
            $emoEval    = EmotionDictionary::evaluateDensity($emoDensity);

            DB::update('chapters', [
                'emotion_density' => json_encode($emoDensity, JSON_UNESCAPED_UNICODE),
                'emotion_score'   => (float)$emoEval['overall_score'],
            ], 'id=?', [$chId]);

            addLog($novelId, 'info', sprintf(
                '情绪密度：得分 %.1f/100（%d 项问题）',
                $emoEval['overall_score'],
                count($emoEval['issues'] ?? [])
            ));

            // 偏低时写一条 Agent 指令影响下章
            if ($emoEval['overall_score'] < 60 && !empty($emoEval['issues'])) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $issuesText = implode('；', array_slice($emoEval['issues'], 0, 2));
                AgentDirectives::addCapped(
                    $novelId,
                    (int)$chapter['chapter_number'] + 1,
                    'quality',
                    "前章情绪密度偏低（得分{$emoEval['overall_score']}）。问题：{$issuesText}。本章须加大相应情绪锚点（动作/微表情），勿堆砌情绪词。",
                    3,  // 持续 3 章
                    24, // 24 小时过期
                    2
                );
            }
        });

        // --- v1.10.3 情绪曲线异常检测（每10章触发）---
        self::safeRun($novelId, '情绪曲线异常检测', function() use ($novelId, $chNum) {
            if ($chNum > 0 && $chNum % 10 === 0) {
                $emotionAnomaly = detectEmotionCurveAnomaly($novelId);
                if ($emotionAnomaly) {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    $msg = match ($emotionAnomaly['type']) {
                        'low_emotion_streak' => "近10章情绪均值仅" . round($emotionAnomaly['avg']) . "分，持续低位（建议<50分需干预）。请在本章安排高强度情绪事件（冲突/反转/危机），打破低潮。",
                        'flat_emotion_curve' => "近10章情绪方差仅" . round($emotionAnomaly['variance']) . "，起伏极小，读者疲劳。本章必须有明显的情绪高低峰落差。",
                        default => '情绪曲线异常，请注意情绪节奏。',
                    };
                    AgentDirectives::addCapped(
                        $novelId, $chNum + 1, 'quality',
                        "情绪曲线告警：{$msg}",
                        3, 48, 2
                    );
                    addLog($novelId, 'info', sprintf(
                        '情绪曲线异常检测：%s（均值%.1f，方差%.1f）',
                        $emotionAnomaly['type'], $emotionAnomaly['avg'], $emotionAnomaly['variance']
                    ));
                }
            }
        });

        // --- v1.11.5 角色情绪异常跳变检测（事后检测）---
        self::safeRun($novelId, '情绪异常跳变检测', function() use ($novelId, $chNum) {
            require_once __DIR__ . '/memory/CharacterEmotionRepo.php';
            $emotionRepo = new CharacterEmotionRepo($novelId);
            $emotionAnomalies = $emotionRepo->detectEmotionAnomalies($chNum);
            if (!empty($emotionAnomalies)) {
                $highAnomalies = array_filter($emotionAnomalies, fn($a) => $a['severity'] === 'high');
                if (!empty($highAnomalies)) {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    $msg = implode('；', array_map(fn($a) => $a['message'], array_slice($highAnomalies, 0, 2)));
                    AgentDirectives::addCapped(
                        $novelId, $chNum + 1, 'quality',
                        "情绪断裂告警：{$msg}。下章请安排合理的情绪过渡或回调。",
                        2, 24, 2
                    );
                }
                addLog($novelId, 'info', sprintf(
                    '情绪异常跳变检测：%d项异常（高危%d）',
                    count($emotionAnomalies),
                    count($highAnomalies)
                ));
            }
        });

        // --- v1.6 爽点实际类型检测（P1#7: 反馈闭环）---
        // 之前 calculateCoolPointSchedule 的 lastUsed 记录的是"计划排期"
        // 而非 AI 实际写到的类型。本节用关键词匹配检测正文中实际出现的爽点类型
        // v1.5.2: 关键词检测无命中时回退到 LLM summary 给出的类型
        self::safeRun($novelId, '爽点检测', function() use ($novelId, $chId, $fullContent, $summaryData) {
            $llmJudgedType = (isset($summaryData) && is_array($summaryData))
                ? ($summaryData['cool_point_type'] ?? null)
                : null;
            $actualCoolTypes = detectCoolPointTypes($fullContent, $llmJudgedType);
            DB::update('chapters', [
                'actual_cool_point_types' => !empty($actualCoolTypes)
                    ? json_encode($actualCoolTypes, JSON_UNESCAPED_UNICODE)
                    : null,
            ], 'id=?', [$chId]);

            if (!empty($actualCoolTypes)) {
                $typeNames = array_map(fn($t) => COOL_POINT_TYPES[$t]['name'] ?? $t, $actualCoolTypes);
                addLog($novelId, 'info', sprintf(
                    '爽点检测：识别到 %d 种类型 —— %s',
                    count($actualCoolTypes),
                    implode('、', $typeNames)
                ));
            }
        });

        // --- v1.6 开篇类型实际检测（P1#7: 反馈闭环）---
        // 检测正文实际使用的开篇类型，与 suggestOpeningType 建议的 opening_type 对比
        // 可发现 AI 写作时是否偏离了推荐的开篇策略
        self::safeRun($novelId, '开篇检测', function() use ($novelId, $chId, $fullContent) {
            $actualOpening = detectOpeningType($fullContent);
            if (!empty($actualOpening['type'])) {
                DB::update('chapters', [
                    'actual_opening_type' => $actualOpening['type']
                ], 'id=?', [$chId]);
                addLog($novelId, 'info', sprintf(
                    '开篇检测：识别为 %s（%s）',
                    $actualOpening['type'],
                    OPENING_TYPES[$actualOpening['type']]['name'] ?? $actualOpening['type']
                ));
            }
        });

        // --- v1.11 场景模板检测（语义级防套路化）---
        self::safeRun($novelId, '场景模板检测', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/memory/SceneTemplateRepo.php';
            $sceneTemplates = detectSceneTemplates($fullContent);
            if (!empty($sceneTemplates)) {
                $stRepo = new SceneTemplateRepo($novelId);
                $saved = $stRepo->batchAdd($sceneTemplates, (int)$chapter['chapter_number']);
                $tplNames = array_map(fn($t) => SCENE_TEMPLATES[$t]['name'] ?? $t, $sceneTemplates);
                addLog($novelId, 'info', sprintf(
                    '场景模板检测：识别到 %d 种 —— %s（入库%d条）',
                    count($sceneTemplates),
                    implode('、', $tplNames),
                    $saved
                ));
            }
        });

        // --- v1.5 收尾期合规检查（激活 EndingEnforcer.checkEndingCompliance）---
        // 之前该方法是死代码，收尾期 AI 可能继续埋新伏笔/写新支线，系统不会发现
        self::safeRun($novelId, '收尾合规检查', function() use ($novelId, $chapter, $fullContent) {
            require_once __DIR__ . '/ending_enforcer.php';
            $enforcer = new EndingEnforcer($novelId, (int)$chapter['chapter_number']);
            if ($enforcer->needsEndingEnforcement()) {
                $compliance = $enforcer->checkEndingCompliance($fullContent);

                if (!empty($compliance['issues'])) {
                    $stage = $enforcer->getEndingStage();
                    $issues = implode('；', array_slice($compliance['issues'], 0, 3));
                    addLog($novelId, 'warn', sprintf(
                        '收尾合规警告（%s阶段）：%s',
                        $stage, $issues
                    ));

                    // 让下一章 prompt 注意修正
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    AgentDirectives::addCapped(
                        $novelId,
                        (int)$chapter['chapter_number'] + 1,
                        'quality',
                        "前章收尾合规警告（{$stage}阶段）：{$issues}。本章必须按收尾阶段规则写作，回收旧伏笔，禁止引入新支线。",
                        2,
                        24,
                        2
                    );
                }
            }
        });

        // --- Agent 指令效果反馈闭环（v1.5） ---
        self::safeRun($novelId, 'Agent反馈闭环', function() use ($novelId, $chapter) {
            require_once __DIR__ . '/agents/AgentDirectives.php';
            $outcomeResult = AgentDirectives::recordOutcomes($novelId, (int)$chapter['chapter_number']);
            if ($outcomeResult['recorded'] > 0) {
                $improved = count(array_filter($outcomeResult['outcomes'], fn($o) => $o['quality_change'] > 0));
                addLog($novelId, 'info', sprintf(
                    'Agent反馈闭环：评估%d条指令效果，%d条正向改善',
                    $outcomeResult['recorded'], $improved
                ));
            }
        });

        // === v41 角色语音指纹生成（每 N 章为缺指纹的主要角色补差异化语音规则）===
        self::safeRun($novelId, '角色语音指纹生成', function() use ($novelId, $chNum, $novelData) {
            $vpInterval = max(3, (int)getSystemSetting('ws_voice_profile_interval', 12, 'int'));
            // 半周期错峰（默认 6,18,30…）：避开全书圣经/控制器/体检的触发章，减少同章重型任务叠加
            if ($chNum > 0 && getSystemSetting('ws_voice_profile_enabled', true, 'bool')
                && $chNum % $vpInterval === intdiv($vpInterval, 2)) {
                require_once __DIR__ . '/VoiceProfileGenerator.php';
                VoiceProfileGenerator::generateMissing($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        });

        // === PID控制器（工程控制论：P/I/D整定）===
        // 每章写完后对4个核心控制变量做PID评估，产生智能调控建议
        self::safeRun($novelId, 'PID控制器', function() use ($novelId, $chNum, $chapter) {
            require_once __DIR__ . '/PIDController.php';
            $pid = new PIDController($novelId);

            $recentScores = DB::fetchAll(
                'SELECT quality_score, emotion_score FROM chapters
                 WHERE novel_id=? AND chapter_number <= ? AND (quality_score IS NOT NULL OR emotion_score IS NOT NULL)
                 ORDER BY chapter_number DESC LIMIT 1',
                [$novelId, $chNum]
            );
            $currentQuality = $recentScores[0]['quality_score'] ?? null;
            $currentEmotion = $recentScores[0]['emotion_score'] ?? null;

            $targetWords = (int)($chapter['words'] ?? 0) > 0
                ? (int)(DB::fetch('SELECT chapter_words FROM novels WHERE id=?', [$novelId])['chapter_words'] ?? 2000)
                : 0;
            $actualWords = (int)($chapter['words'] ?? 0);
            $wordAccuracy = ($targetWords > 0 && $actualWords > 0)
                ? round(min(1.0, $actualWords / $targetWords), 3)
                : null;

            $recentCool = DB::fetchAll(
                'SELECT cool_point_type FROM chapters
                 WHERE novel_id=? AND chapter_number BETWEEN ? AND ? AND status="completed"
                 ORDER BY chapter_number DESC LIMIT 10',
                [$novelId, max(1, $chNum - 9), $chNum]
            );
            $coolCount = 0;
            foreach ($recentCool as $rc) {
                if (!empty($rc['cool_point_type'])) $coolCount++;
            }
            $coolDensity = count($recentCool) > 0
                ? round($coolCount / count($recentCool), 3)
                : null;

            $pidResults = $pid->evaluateAll([
                'quality_score'  => $currentQuality,
                'emotion_score'  => $currentEmotion,
                'word_count_accuracy' => $wordAccuracy,
                'cool_point_density'  => $coolDensity,
            ]);

            $criticalIssues = array_filter($pidResults, fn($r) => $r['severity'] === 'critical');
            if (!empty($criticalIssues)) {
                $vars = array_keys($criticalIssues);
                $msgs = array_map(fn($v, $r) => $r['recommendation'], $vars, $criticalIssues);
                require_once __DIR__ . '/agents/AgentDirectives.php';
                AgentDirectives::addCapped(
                    $novelId, $chNum + 1, 'quality',
                    implode('；', $msgs),
                    2, 24, 2
                );
                addLog($novelId, 'info', sprintf(
                    'PID控制器：%d项严重偏差，已写入指令',
                    count($criticalIssues)
                ));
            }
        });

        // === 参数自适应调优（工程控制论：自适应控制）===
        // 每 10 章分析历史数据，自动调整迭代参数
        if ($chNum > 0 && $chNum % 10 === 0) {
            self::safeRun($novelId, '参数自适应调优', function() use ($novelId, $chNum) {
                require_once __DIR__ . '/AdaptiveParameterTuner.php';
                $tuner = new AdaptiveParameterTuner($novelId);
                $tuner->tune($chNum);
            });
        }

        // === 全书级控制器（工程控制论：层级控制）===
        // 每 20 章触发一次，做全书级5项检查
        self::safeRun($novelId, '全书级控制器', function() use ($novelId, $chNum, $novelData) {
            require_once __DIR__ . '/GlobalNovelController.php';
            $globalResult = GlobalNovelController::regulate(
                $novelId,
                $chNum,
                $novelData['model_id'] ?? null
            );
            if ($globalResult['triggered']) {
                $directivesWritten = $globalResult['directives'];
                $checkSummary = [];
                foreach ($globalResult['checks'] as $name => $check) {
                    if (!empty($check['issue'])) {
                        $checkSummary[] = $name . ':有问题';
                    } elseif ($check['checked'] ?? false) {
                        $checkSummary[] = $name . ':正常';
                    }
                }
                addLog($novelId, 'info', sprintf(
                    '全书级控制器执行：%d项检查，%d条全局指令（%s）',
                    count($globalResult['checks']),
                    $directivesWritten,
                    implode(', ', $checkSummary)
                ));
            }
        });

        // === 系统健康监控（工程控制论：鲁棒性）===
        // 每 20 章执行一次系统体检
        if ($chNum > 0 && $chNum % 20 === 0) {
            self::safeRun($novelId, '系统健康监控', function() use ($novelId) {
                require_once __DIR__ . '/SystemHealthMonitor.php';
                $healthMonitor = new SystemHealthMonitor($novelId);
                $healthResult = $healthMonitor->check();
                if ($healthResult['score'] < 70) {
                    foreach ($healthResult['alerts'] as $alert) {
                        addLog($novelId, 'warn', sprintf(
                            '系统健康告警[%s]：%s',
                            $alert['level'] ?? 'warning',
                            $alert['message']
                        ));
                    }
                }
                if (!empty($healthResult['alerts'])) {
                    addLog($novelId, 'info', sprintf(
                        '系统健康分：%d/100，%d条告警',
                        $healthResult['score'], count($healthResult['alerts'])
                    ));
                }
            });
        }

        // （pp_pending 标志已在约束状态更新后提前清除，见上方「后处理重排」说明）

        // === v41 全书圣经（每 N 章增量压缩，根治中段遗忘）===
        self::safeRun($novelId, '全书圣经更新', function() use ($novelId, $chNum, $novelData) {
            $bibleInterval = max(5, (int)getSystemSetting('ws_bible_interval', 20, 'int'));
            if ($chNum > 0 && getSystemSetting('ws_story_bible_enabled', true, 'bool')
                && $chNum % $bibleInterval === 0) {
                require_once __DIR__ . '/memory/StoryBible.php';
                StoryBible::regenerate($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        });

        // === v41 全书一致性体检（每 N 章一次的宏观审计，存报告供作者查看）===
        self::safeRun($novelId, '全书体检', function() use ($novelId, $chNum, $novelData) {
            $auditInterval = max(10, (int)getSystemSetting('ws_fullbook_audit_interval', 50, 'int'));
            // 半周期错峰（默认 25,75…）：与全书圣经(%20===0)/控制器(%20===10)在默认值下两两不撞
            if ($chNum > 0 && getSystemSetting('ws_fullbook_audit_enabled', true, 'bool')
                && $chNum % $auditInterval === intdiv($auditInterval, 2)) {
                require_once __DIR__ . '/FullBookAudit.php';
                FullBookAudit::run($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        });

        // W-7 修复：Arc 摘要从 daemon_write 迁移到 postProcess()，让所有写作路径（SSE/async/daemon）一致积累 L2 记忆
        self::safeRun($novelId, '弧段摘要生成', function() use ($novelId, $chapter) {
            $chNumForArc = (int)$chapter['chapter_number'];
            if ($chNumForArc % 10 === 0 && $chNumForArc > 0) {
                $arcFrom = max(1, $chNumForArc - 9);
                $novelForArc = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
                if ($novelForArc) {
                    require_once __DIR__ . '/memory.php';
                    if (function_exists('generateAndSaveArcSummary')) {
                        generateAndSaveArcSummary($novelForArc, $arcFrom, $chNumForArc);
                        addLog($novelId, 'info', "弧段摘要已生成：第{$arcFrom}-{$chNumForArc}章");
                    }
                }
            }
        }, 'error');

        addLog($novelId, 'info', "第{$chapter['chapter_number']}章后处理完成（摘要/记忆/知识库/质检）");
        } finally {
            // 任意未捕获异常（含硬依赖、咨询任务或日志落盘异常）都不得遗留 pending flag。
            self::clearPostProcessPending($novelId, $chNum);
        }
    }

    /**
     * 质量五门检测：计算并落盘 quality_score / gate_results。
     * 启用重写时在 RewriteAgent 之前跑（它依赖这两个字段）；
     * 未启用时移到 pp_pending 窗口外跑，不阻塞下一章。
     *
     * W-19 修复：返回结构化结果 ['gates' => array, 'score' => float]，
     * 调用方（RewriteAgent 路径）可直接复用，避免两次 SELECT 重查。
     */
    private static function runQualityGates(int $novelId, int $chId, string $fullContent): array
    {
        $output = ['gates' => [], 'score' => 100.0];
        try {
            // 修复：从 includes 层加载，不再反向依赖 api/validate_consistency.php
            // （api 文件含 HTTP 副作用，CLI worker 加载需要 CLI_MODE 守卫，分层不清）
            // 修复（2026-06-18）：确保 countWords() 可用（Gates.php 结构检查依赖此函数）
            if (!function_exists('countWords')) {
                require_once __DIR__ . '/helpers.php';
            }
            require_once __DIR__ . '/quality/Gates.php';

            $vChapter = DB::fetch(
                'SELECT c.*, n.genre, n.chapter_words, n.writing_style '
                . 'FROM chapters c JOIN novels n ON c.novel_id = n.id '
                . 'WHERE c.id = ? AND c.novel_id = ?',
                [$chId, $novelId]
            );
            $vContent = $vChapter['content'] ?? $fullContent;

            if ($vChapter && !empty(trim($vContent))) {
                $results = [];
                $results[] = checkGate1_Structure($vChapter, $vContent);
                $results[] = checkGate2_Characters($novelId, $vContent);
                $results[] = checkGate3_Description($vChapter['genre'] ?? null, $vContent);
                $results[] = checkGate4_CoolPoint($vContent, $vChapter['outline'] ?? null);
                $results[] = checkGate5_Consistency($chId, $novelId, $vContent);

                $scores = array_column($results, 'score');
                $avgScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

                $qUpdates = [];
                if ($avgScore > 0) { $qUpdates['quality_score'] = (float)$avgScore; }
                $qUpdates['gate_results'] = json_encode($results, JSON_UNESCAPED_UNICODE);
                if (!empty($qUpdates)) {
                    DB::update('chapters', $qUpdates, 'id=?', [$chId]);
                }
                addLog($novelId, 'info', sprintf('质量检测：总分 %.1f/100', $avgScore));

                $output['gates'] = $results;
                $output['score'] = (float)$avgScore;
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '质量检测跳过：' . $e->getMessage());
        }
        return $output;
    }

    /**
     * P1-6 优化：部分质量门重跑（重写后场景）
     *
     * 重写主要改文风，Gate2（人物）/Gate4（爽点）/Gate5（一致性）基于情节，
     * 可复用重写前结果。只有 Gate1（结构）和 Gate3（描写）受文风影响需重跑。
     *
     * @param int   $novelId     小说ID
     * @param int   $chId        章节ID
     * @param string $fullContent 重写后正文
     * @param array $prevGates   重写前的五门结果（runQualityGates 返回的 gates 数组）
     * @return array{gates: array, score: float}
     */
    private static function runQualityGatesPartial(int $novelId, int $chId, string $fullContent, array $prevGates): array
    {
        $output = ['gates' => [], 'score' => 100.0];
        try {
            if (!function_exists('countWords')) {
                require_once __DIR__ . '/helpers.php';
            }
            require_once __DIR__ . '/quality/Gates.php';

            $vChapter = DB::fetch(
                'SELECT c.*, n.genre, n.chapter_words, n.writing_style '
                . 'FROM chapters c JOIN novels n ON c.novel_id = n.id '
                . 'WHERE c.id = ? AND c.novel_id = ?',
                [$chId, $novelId]
            );
            $vContent = $vChapter['content'] ?? $fullContent;
            if (!$vChapter || empty(trim($vContent))) return $output;

            // 按 gate 编号索引旧结果
            $prevByGate = [];
            foreach ($prevGates as $g) {
                $gateId = $g['gate'] ?? $g['id'] ?? '';
                if ($gateId) $prevByGate[$gateId] = $g;
            }

            // 只重跑 Gate1（结构）和 Gate3（描写）
            $results = [];
            $results[] = checkGate1_Structure($vChapter, $vContent);
            // 复用 Gate2
            $results[] = $prevByGate['gate2'] ?? $prevByGate[2] ?? checkGate2_Characters($novelId, $vContent);
            $results[] = checkGate3_Description($vChapter['genre'] ?? null, $vContent);
            // 复用 Gate4
            $results[] = $prevByGate['gate4'] ?? $prevByGate[4] ?? checkGate4_CoolPoint($vContent, $vChapter['outline'] ?? null);
            // 复用 Gate5
            $results[] = $prevByGate['gate5'] ?? $prevByGate[5] ?? checkGate5_Consistency($chId, $novelId, $vContent);

            $scores = array_column($results, 'score');
            $avgScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

            $qUpdates = [];
            if ($avgScore > 0) { $qUpdates['quality_score'] = (float)$avgScore; }
            $qUpdates['gate_results'] = json_encode($results, JSON_UNESCAPED_UNICODE);
            if (!empty($qUpdates)) {
                DB::update('chapters', $qUpdates, 'id=?', [$chId]);
            }
            addLog($novelId, 'info', sprintf('质量检测（部分复用）：总分 %.1f/100', $avgScore));

            $output['gates'] = $results;
            $output['score'] = (float)$avgScore;
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '质量检测（部分复用）跳过：' . $e->getMessage());
        }
        return $output;
    }

    /**
     * Agent决策：在写作前运行Agent决策流程
     *
     * 审计修复 M-3（2026-06-18）：加文件锁防止并发重复决策。
     * 原实现在 resolveChapter 最开头调用，此时章节尚未置 writing，
     * 两个并发请求可能同时进入 runPreWriteAgents，导致 agent_decision_logs
     * 重复写入（BaseAgent::logDecision 直接 INSERT 无去重）。
     * 现用基于章节号的文件锁串行化，第二个请求检测到锁存在则跳过（决策已由
     * 先到者执行），不影响写作主流程。
     *
     * P0-3 优化（2026-06-18）：接受 $chNum 参数，避免内部 getCurrentChapterNumber
     * 重复查询且与实际写作章节错位。调用方在僵死清理后传入已确定的章节号。
     *
     * @param int $novelId 小说ID
     * @param int $chNum   已确定的待写章节号（由 resolveChapter 传入）
     * @return void
     */
    private static function runPreWriteAgents(int $novelId, int $chNum = 0): void
    {
        try {
            if (!ConfigCenter::get('agent.enabled', true)) {
                return;
            }

            // P0-3：优先使用传入的章节号；未传入时回退到查询（保持向后兼容）
            if ($chNum <= 0) {
                $chNum = self::getCurrentChapterNumber($novelId);
            }

            // 调度只保留一层：AgentCoordinator 内部按 3/4/5/8 等动态间隔决定
            // 本章需要运行哪些 Agent。外层再用 5/10/20 过滤会让内部计划永远失效。

            // M-3 修复：基于章节号的非阻塞文件锁，防止并发重复决策
            // 锁文件名含章节号，确保每个章节的决策周期独立加锁
            // 锁在请求结束时自动释放（fclose 或 PHP shutdown）
            $lockFile = CFG_PROGRESS_DIR . "/agent_lock_n{$novelId}_c{$chNum}.lock";
            $lockFp = @fopen($lockFile, 'c');
            if (!$lockFp) {
                addLog($novelId, 'debug', "Agent决策锁文件无法创建，跳过：{$lockFile}");
                return;
            }
            if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
                // 已有其他进程在跑此章节的 Agent 决策，跳过
                fclose($lockFp);
                addLog($novelId, 'debug', "第{$chNum}章 Agent 决策已由其他进程执行，跳过");
                return;
            }

            try {
                require_once __DIR__ . '/agents/AgentCoordinator.php';

                $coordinator = new AgentCoordinator($novelId);

                $context = [
                    'pending_foreshadowing_count' => self::countPendingForeshadowings($novelId),
                    'recent_chapters' => self::getRecentChapters($novelId, 5),
                    'current_progress' => self::getCurrentProgress($novelId),
                    'current_chapter_number' => $chNum,
                ];

                $decisionResult = $coordinator->runDecisionCycle($context);

                if (!empty($decisionResult['execution_summary'])) {
                    $summary = $decisionResult['execution_summary'];
                    addLog($novelId, 'info', sprintf(
                        'Agent决策完成：决策%d次，执行%d个动作，成功%d个',
                        $summary['total_decisions'],
                        $summary['total_actions'],
                        $summary['successful_actions']
                    ));
                }
            } finally {
                // 释放锁并清理锁文件
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                @unlink($lockFile);
            }

        } catch (Throwable $e) {
            addLog($novelId, 'warn', 'Agent决策失败：' . $e->getMessage());
        }
    }
    
    /**
     * 统计待回收伏笔数量
     */
    private static function countPendingForeshadowings(int $novelId): int
    {
        try {
            $result = DB::fetch(
                'SELECT COUNT(*) as cnt FROM foreshadowing_items WHERE novel_id = ? AND resolved_chapter IS NULL',
                [$novelId]
            );
            return (int)($result['cnt'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取最近的章节
     */
    private static function getRecentChapters(int $novelId, int $limit): array
    {
        try {
            // 审计修复 PERF-C2（2026-07-01）：列裁剪，近章上下文只需元数据 + 尾文
            // 补 updated_at 列（2026-07-22）：AgentCoordinator::estimateWritingSpeed()
            // 依赖 recent_chapters[*]['updated_at']，缺列时挂机路径全部 fallback 到 time()，
            // 与 daemon_write.php 的 recent_chapters 查询保持列集一致。
            return DB::fetchAll(
                'SELECT id, novel_id, chapter_number, title, outline, key_points, hook, pacing, status, content, words, quality_score, updated_at FROM chapters WHERE novel_id = ? AND status = "completed" ORDER BY chapter_number DESC LIMIT ?',
                [$novelId, $limit]
            );
        } catch (Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取当前进度
     */
    private static function getCurrentProgress(int $novelId): float
    {
        try {
            $novel = DB::fetch('SELECT target_chapters FROM novels WHERE id = ?', [$novelId]);
            $target = (int)($novel['target_chapters'] ?? 0);
            
            if ($target <= 0) return 0;
            
            $completed = DB::fetch(
                'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id = ? AND status = "completed"',
                [$novelId]
            );
            
            return (int)($completed['cnt'] ?? 0) / $target;
        } catch (Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取当前章节号（下一个待写章节，与 resolveChapter 的选章逻辑一致）
     *
     * 注意不能用 MAX(chapter_number)+1：大纲全量生成后 chapters 表已有全部章节行，
     * MAX+1 恒等于"目标章数+1"且不随写作进度变化，导致 runPreWriteAgents 的
     * %5/10/20 触发条件恒真或恒假——Agent 决策周期实际从未按进度执行。
     */
    private static function getCurrentChapterNumber(int $novelId): int
    {
        try {
            $nextId = self::findNextAutomaticChapterId($novelId);
            $next = $nextId
                ? DB::fetch(
                    'SELECT chapter_number FROM chapters WHERE id=? AND novel_id=?',
                    [$nextId, $novelId]
                )
                : null;
            if ($next) return (int)$next['chapter_number'];

            // 全部写完：退回 MAX+1（保持原兜底语义）
            $chapter = DB::fetch(
                'SELECT COALESCE(MAX(chapter_number), 0) + 1 as next_chapter FROM chapters WHERE novel_id = ?',
                [$novelId]
            );
            return (int)($chapter['next_chapter'] ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    /**
     * 降级摘要：AI摘要失败时，从大纲和正文提取最基本的记忆数据
     *
     * 保证 ingestChapter 至少拿到 key_event + narrative_summary + story_momentum，
     * 避免整条记忆链断裂。人物更新/伏笔/爽点等深度分析字段留空。
     */
    private static function buildFallbackSummary(array $chapter, string $fullContent): array
    {
        $outline = trim((string)($chapter['outline'] ?? ''));
        $title = trim((string)($chapter['title'] ?? ''));
        $chNum = (int)($chapter['chapter_number'] ?? 0);

        $narrativeSummary = $outline ?: safe_substr(trim($fullContent), 0, 200) . '…';

        $keyEvent = $outline ?: $title;
        if (mb_strlen($keyEvent) > 20) {
            $keyEvent = safe_substr($keyEvent, 0, 20);
        }

        $momentum = '';
        if (!empty($outline)) {
            $momentum = safe_substr($outline, 0, 30);
        }

        return [
            'narrative_summary'      => $narrativeSummary,
            'character_updates'      => [],
            'character_traits'       => [],
            'key_event'              => $keyEvent,
            'used_tropes'            => [],
            'new_foreshadowing'      => [],
            'resolved_foreshadowing' => [],
            'story_momentum'         => $momentum,
            'cool_point_type'        => '',
            'character_emotions'     => [],
        ];
    }

    // =================================================================
    // v1.7 PRO: 图谱关系增量同步
    // =================================================================

    /**
     * 从章节正文中提取实体关系三元组并存入 story_relations
     * 同时标记 graph_start_chapter（首次触发时）
     *
     * @param int    $novelId    小说ID
     * @param int    $chNum      章节号
     * @param string $content    章节正文
     * @param int|null $modelId  AI模型ID
     */
    private static function extractAndStoreGraphRelations(int $novelId, int $chNum, string $content, ?int $modelId = null): void
    {
        // 标记 graph_start_chapter（首次启用图谱功能时）
        try {
            $state = DB::fetch("SELECT graph_start_chapter FROM novel_state WHERE novel_id=?", [$novelId]);
            if (!$state || !isset($state['graph_start_chapter']) || $state['graph_start_chapter'] === null) {
                $existing = DB::fetch("SELECT novel_id FROM novel_state WHERE novel_id=?", [$novelId]);
                if ($existing) {
                    DB::update('novel_state', ['graph_start_chapter' => $chNum], 'novel_id=?', [$novelId]);
                } else {
                    DB::insert('novel_state', ['novel_id' => $novelId, 'graph_start_chapter' => $chNum, 'last_ingested_chapter' => 0]);
                }
                addLog($novelId, 'info', "图谱关系构建起始章节已标记为第{$chNum}章");
            }
        } catch (\Throwable $e) {
            // 标记失败不阻塞关系提取
        }

        // 提取章节正文的前 4000 字（避免 token 过长）
        $truncated = mb_substr($content, 0, 4000);
        if (mb_strlen($truncated) < 100) return;

        // 获取已知角色名列表（供 AI 参考）
        $charNames = [];
        try {
            $names = DB::fetchAll("SELECT name FROM character_cards WHERE novel_id=?", [$novelId]);
            $charNames = array_column($names, 'name');
        } catch (\Throwable $e) { error_log('write_engine charNames fetch failed: ' . $e->getMessage()); }
        $charList = !empty($charNames) ? '已知角色: ' . implode(', ', array_slice($charNames, 0, 20)) : '';

        $novel = DB::fetch("SELECT title, protagonist_name FROM novels WHERE id=?", [$novelId]);
        $title = sanitizeForPrompt((string)($novel['title'] ?? ''));
        $protagonist = sanitizeForPrompt((string)($novel['protagonist_name'] ?? ''));
        $charList    = sanitizeForPrompt($charList);

        $sys = "你是小说《{$title}》的实体关系提取器。主角为「{$protagonist}」。"
            . "从章节正文中提取角色、物品、组织之间的关键关系，输出三元组。"
            . "只提取对后续写作有长期价值的关系（拥有/击杀/结盟/师徒/敌对/爱情/隶属等）。"
            . "严禁编造未出现在正文中的关系。{$charList}";

        $user = "【第{$chNum}章正文片段】\n{$truncated}\n\n"
            . "请提取本章中的实体关系三元组。要求：\n"
            . "1. source 和 target 为实体名（角色/物品/组织/地点）\n"
            . "2. relation 为关系谓词（英文：owns/killed/allied_with/mentors/enemies/loves/belongs_to/located_at/member_of 等）\n"
            . "3. desc 为一句话描述（中文）\n"
            . "4. 只输出重要的关系，不要提取琐碎的（如对话中的称呼）\n"
            . "5. 无关系则输出空数组 []\n"
            . "6. 严格输出 JSON 数组：\n"
            . "   [{\"source\":\"林枫\",\"relation\":\"owns\",\"target\":\"无双剑\",\"desc\":\"林枫意外获得了上古神兵无双剑\"}, ...]";

        try {
            $ai = getAIClient($modelId);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            // 容错解析 JSON 数组
            $relations = self::parseGraphJson($raw);
            if ($relations === null || !is_array($relations)) return;

            $validRelations = [];
            $seen = [];
            foreach ($relations as $rel) {
                if (!is_array($rel)) continue;
                $source = trim((string)($rel['source'] ?? ''));
                $relation = trim((string)($rel['relation'] ?? ''));
                $target = trim((string)($rel['target'] ?? ''));
                $desc = trim((string)($rel['desc'] ?? ''));

                if ($source === '' || $relation === '' || $target === '') continue;

                $key = $source . "\0" . $relation . "\0" . $target;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $validRelations[] = [$source, $relation, $target, $desc];
            }

            if (!empty($relations) && empty($validRelations)) return;

            if (!DB::beginTransaction()) {
                throw new \RuntimeException('无法开启图谱关系替换事务');
            }
            try {
                DB::execute(
                    "DELETE FROM story_relations WHERE novel_id=? AND source_chapter=?",
                    [$novelId, $chNum]
                );
                foreach ($validRelations as [$source, $relation, $target, $desc]) {
                    DB::execute(
                        "INSERT INTO story_relations (novel_id, source_entity, relation_type, target_entity, source_chapter, description)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$novelId, $source, $relation, $target, $chNum, $desc]
                    );
                }
                if (!DB::commit()) {
                    throw new \RuntimeException('图谱关系替换事务提交失败');
                }
            } catch (\Throwable $writeError) {
                try {
                    DB::rollBack();
                } catch (\Throwable) {}
                throw $writeError;
            }

            $inserted = count($validRelations);
            addLog($novelId, 'info', "图谱关系同步：第{$chNum}章替换为{$inserted}条关系三元组");
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '图谱关系AI提取失败：' . $e->getMessage());
        }
    }

    // =================================================================
    // v1.7 PRO: Blueprint 代理（行文蓝图化）
    // =================================================================

    /**
     * Blueprint 代理：将粗略大纲细化为包含感官细节、对话要点、转折节点的写作蓝图
     *
     * @param array  $novel        小说信息
     * @param array  $chapter      章节信息（含大纲）
     * @param string $previousTail 前章尾文
     * @param ?array $memoryCtx    记忆上下文
     * @return string|null 细化后的蓝图，失败返回 null
     */
    private static function runBlueprint(array $novel, array $chapter, string $previousTail, ?array $memoryCtx): ?string
    {
        $novelId = (int)$novel['id'];
        $chNum   = (int)$chapter['chapter_number'];
        $outline = trim((string)($chapter['outline'] ?? ''));
        $title   = trim((string)($chapter['title'] ?? ''));

        if ($outline === '' || mb_strlen($outline) > 2000) return null; // 大纲为空或已足够详细则跳过

        $genre       = $novel['genre'] ?? '都市';
        $protagonist = $novel['protagonist_name'] ?? '主角';
        $style       = $novel['writing_style'] ?? '';

        // 前章尾文截取（给 Blueprint 代理参考衔接）
        $tailSnippet = mb_substr($previousTail, 0, 500);

        // 图谱关联上下文（如果有）
        $graphContext = '';
        if (!empty($memoryCtx['semantic_hits'])) {
            $graphHits = array_filter($memoryCtx['semantic_hits'], fn($h) => ($h['source'] ?? '') === 'graph');
            if (!empty($graphHits)) {
                $graphLines = [];
                foreach (array_slice($graphHits, 0, 5) as $hit) {
                    $graphLines[] = $hit['content'];
                }
                $graphContext = "【相关图谱关系】\n" . implode("\n", $graphLines) . "\n";
            }
        }

        $sys = "你是一位资深网文策划师，擅长将粗略大纲细化为可执行的写作蓝图。"
            . "小说类型：{$genre}，主角：{$protagonist}。"
            . ($style !== '' ? "写作风格参考：{$style}。" : "")
            . "输出的蓝图将被直接用于指导AI写手撰写正文。";

        $user = "请将以下粗略大纲细化为详细的写作蓝图。\n\n"
            . "【第{$chNum}章标题】{$title}\n"
            . "【粗略大纲】{$outline}\n\n"
            . ($tailSnippet !== '' ? "【前章结尾（衔接参考）】{$tailSnippet}\n\n" : "")
            . $graphContext
            . "请输出细化后的写作蓝图，要求：\n"
            . "1. **场景规划**：本章涉及几个场景，每个场景的时间/地点/氛围\n"
            . "2. **核心转折**：本章的关键转折点或冲突升级点\n"
            . "3. **对话要点**：列出 2-3 段关键对话的参与者和要点（不需要写完整对话）\n"
            . "4. **感官细节**：每个场景需要突出的视觉/听觉/触觉/嗅觉细节\n"
            . "5. **情绪走向**：本章的情绪曲线（从什么情绪开始→经过什么变化→以什么情绪结束）\n"
            . "6. **字数分配建议**：各场景/段落的大致字数占比\n"
            . "控制在 600 字以内，精炼不啰嗦。直接输出蓝图内容，不要加前缀说明。";

        try {
            $ai = getAIClient($novel['model_id'] ?? null);
            $blueprint = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            if (mb_strlen($blueprint) < 100) return null;
            return $blueprint;
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', 'Blueprint AI 调用失败：' . $e->getMessage());
            return null;
        }
    }

    /** 容错解析图谱 JSON（数组或 {"relations": [...]} 格式） */
    private static function parseGraphJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        // 去 markdown 代码块
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            // 如果是数组直接返回
            if (empty($data) || isset($data[0])) return $data;
            // 如果是对象且有 relations 字段
            if (isset($data['relations']) && is_array($data['relations'])) return $data['relations'];
            return null;
        }
        // 截取首个 [ 到末个 ]
        $s = strpos($raw, '[');
        $e = strrpos($raw, ']');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
