<?php
/**
 * ================================================================
 * daemon_write.php — 挂机写作 Worker
 * ================================================================
 * 用途：由宝塔计划任务（Cron）定期 HTTP 访问此文件，实现无人值守写作。
 *
 * 宝塔 Cron 配置示例（推荐用请求头传令牌——请求头不会进访问日志/代理日志/浏览器历史）：
 *   执行周期：每 1 分钟
 *   脚本类型：Shell 脚本
 *   脚本内容：curl -s -H "X-Daemon-Token: 你的TOKEN" "http://你的域名/api/daemon_write.php"
 *
 * 安全机制（审计 P1：令牌仅从请求头读取，彻底移除 URL/POST 兜底）：
 *   - 仅通过请求头 X-Daemon-Token（或 Authorization: Bearer 你的TOKEN）鉴权，跳过 Session 登录检查
 *   - 不再接受 ?token=xxx / POST token（URL 令牌会进访问日志/代理日志，存在泄露风险）
 *   - Token 存储在 system_settings 表中，key = daemon_token
 *   - Token 仅由登录态管理端点初始化；本接口绝不返回 token
 *   - 可轮换（审计 Low-3）：管理员 POST action=rotate 到 daemon_token 路由即换新值，
 *     旧令牌立即失效；签发时间记录在 daemon_token_issued_at
 *   - 可选 TTL（审计 Low-3）：daemon_token_ttl_days 默认 0=不过期；>0 且已过期时
 *     本接口返回 401 + token_expired（升级前的令牌无签发时间，永不过期）
 *
 * 工作逻辑：
 *   1. 全局只允许一个小说开启挂机（enable 时自动关闭其他）
 *   2. 检查当前是否有其他进程正在写作（lock file 机制，防并发）
 *   3. 直接在进程内执行写作逻辑（不走 HTTP/Session/CSRF），调用 AIClient 同步写作
 *   4. 写完后更新统计、记录日志
 *   5. 如果所有章节完成，自动关闭挂机
 * ================================================================
 *
 * ==============================
 * 【可配置项】失败重试策略
 * ==============================
 *
 *   DAEMON_RETRY_MODE
 *     'skip'    — 重试 DAEMON_MAX_RETRIES 次后跳过该章节，继续写下一章（默认）
 *     'forever' — 一直重试，永不跳过（直到写成功为止）
 *
 *   DAEMON_MAX_RETRIES
 *     重试次数上限，仅在 DAEMON_RETRY_MODE = 'skip' 时生效。
 *     默认 3：失败后重试 3 次（第 1~3 次失败都会回到 outlined 重写），
 *     第 4 次失败时（retry_count > 3）才标记为 skipped 跳过。
 *     设为 0 表示失败立即跳过（不重试）。
 *     （审计修复 2026-08-16 #15b：原文档"失败 3 次后跳过"与实现的
 *      $retryCount > DAEMON_MAX_RETRIES 判定存在 off-by-one，此处按实现订正。）
 * ================================================================
 */

// ================================================================
// 【配置区】修改这里调整重试策略
// ================================================================

/**
 * 失败重试模式：
 *   'skip'    — 超过最大重试次数后跳过该章节
 *   'forever' — 永远重试，直到写成功
 */
define('DAEMON_RETRY_MODE',    'skip');

/**
 * 最大重试次数（仅 DAEMON_RETRY_MODE = 'skip' 时有效）
 * 设为 0 表示失败后立即跳过，不重试
 */
define('DAEMON_MAX_RETRIES',   3);

header('Cache-Control: no-cache, no-store');
header('Content-Type: application/json; charset=utf-8');

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardHttp();
require_once dirname(__DIR__) . '/includes/RequestBody.php';
if (readRequestBodyBounded(10 * 1024 * 1024) === null) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'msg' => '请求体过大'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/daemon_auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/memory.php';
require_once dirname(__DIR__) . '/includes/prompt.php';
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/NovelProjectionFence.php';
require_once dirname(__DIR__) . '/includes/quality/WritePolicySnapshotFactory.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessRunner.php';
require_once dirname(__DIR__) . '/includes/daemon/DaemonLockManager.php';
require_once dirname(__DIR__) . '/includes/daemon/DaemonSchedule.php';

/**
 * The caller must retain its daemon lock through this finalization. Manual
 * writers do not take that lock, so also serialize with their novel fence and
 * row lock and refuse to overwrite a newly queued task or chapter claim.
 *
 * @return array{action:string,total:int,unfinished:int,writable:int,manual:int}
 */
function daemonFinalizeNovelState(int $novelId, bool $writeSucceeded): array
{
    if (DB::inTransaction()) {
        throw new RuntimeException('Daemon finalization cannot borrow a transaction.');
    }
    $fence = NovelProjectionFence::acquire($novelId, 30);
    $transactionOpen = false;
    try {
        if (!DB::beginTransaction()) {
            throw new RuntimeException('Cannot start daemon finalization.');
        }
        $transactionOpen = true;
        // Global chapters -> novels -> writing_tasks order (see write_start).
        // The fence-free claim heartbeat locks chapter -> task, so taking the
        // task row before the chapter here would form a 1213 deadlock cycle.
        $writingChapter = DB::fetch(
            "SELECT id FROM chapters WHERE novel_id=? AND status='writing'
             ORDER BY id LIMIT 1 FOR UPDATE",
            [$novelId]
        );
        $novel = DB::fetch(
            'SELECT status, daemon_write, cancel_flag FROM novels WHERE id=? FOR UPDATE',
            [$novelId]
        );
        $activeTask = DB::fetch(
            "SELECT task_id FROM writing_tasks WHERE novel_id=?
             AND state IN ('queued','preparing','generating','validating','content_saved','postprocessing')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [$novelId]
        );
        $result =['action' => 'superseded', 'total' => 0, 'unfinished' => 0, 'writable' => 0, 'manual' => 0];
        if (is_array($novel)
            && (int)($novel['daemon_write'] ?? 0) === 1
            && (int)($novel['cancel_flag'] ?? 0) === 0
            && !is_array($activeTask) && !is_array($writingChapter)) {
            $result['total'] = DB::count('chapters', 'novel_id=?', [$novelId]);
            $result['unfinished'] = DB::count('chapters', 'novel_id=? AND status<>"completed"', [$novelId]);
            $result['writable'] = WriteEngine::countAutomaticWritableChapters($novelId);
            $result['manual'] = WriteEngine::countManualBackfillChapters($novelId);
            if ($result['total'] > 0 && $result['unfinished'] === 0 && $writeSucceeded) {
                $result['action'] = 'completed';
                $updates = ['daemon_write' => 0, 'status' => 'completed'];
            } elseif ($result['writable'] === 0 && ($result['unfinished'] > 0 || $result['total'] === 0)) {
                $result['action'] = 'blocked';
                $updates = ['daemon_write' => 0, 'status' => 'paused'];
            } else {
                $result['action'] = 'paused';
                $updates = ['status' => 'paused'];
            }
            DB::update('novels', $updates, 'id=?', [$novelId]);
        }
        if (!DB::commit()) {
            throw new RuntimeException('Cannot commit daemon finalization.');
        }
        $transactionOpen = false;
        return $result;
    } finally {
        if ($transactionOpen && DB::inTransaction()) {
            DB::rollBack();
        }
        NovelProjectionFence::release($fence);
    }
}
require_once dirname(__DIR__) . '/includes/harness/runtime/bootstrap.php';
require_once dirname(__DIR__) . '/includes/harness/stages/LegacyWriteEngineStageAdapter.php';

ob_end_clean();

/**
 * Recover the narrow case where saveChapter() committed this daemon attempt's
 * Canon but threw before its authoritative readback/return completed.
 *
 * This is intentionally an authentication path, not a generic "chapter is
 * completed" shortcut.  The fresh chapter, its canonical Candidate, the
 * cleaned generated content, the frozen policy and the pre-claim base snapshot
 * must all describe the same daemon claim.  Any malformed/missing DB state
 * fails closed and lets the caller use the ordinary failure path.
 *
 * @return array{chapter:array,content:string,words:int,postprocess_result:?array}|null
 */
function daemonRecoverCommittedCanon(
    int $novelId,
    array $claimedChapter,
    string $generatedContent,
    string $daemonClaimToken,
    ?WritePolicySnapshot $policySnapshot,
    ?MemoryEngine $engine = null
): ?array {
    $chapterId = (int)($claimedChapter['id'] ?? 0);
    if ($chapterId <= 0
        || $generatedContent === ''
        || preg_match('/^[a-f0-9]{32}$/D', $daemonClaimToken) !== 1
        || !$policySnapshot instanceof WritePolicySnapshot) {
        return null;
    }

    try {
        // Keep this normalization in exactly the same order as saveChapter().
        $cleanedContent = stripMetaLeaks(stripSegmentMarkers($generatedContent));
        if ($cleanedContent === '') {
            return null;
        }
        $expectedRevisionHash = hash('sha256', $cleanedContent);

        $freshChapter = DB::fetch(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
            [$chapterId, $novelId]
        );
        if (!is_array($freshChapter)
            || (string)($freshChapter['status'] ?? '') !== 'completed'
            || (string)($freshChapter['canonical_state'] ?? '') !== 'canonical'
            || (string)($freshChapter['claim_token'] ?? '') !== '') {
            return null;
        }

        $storedContent = $freshChapter['content'] ?? null;
        $storedRevisionHash = $freshChapter['content_revision_hash'] ?? null;
        $candidateUid = $freshChapter['canonical_candidate_uid'] ?? null;
        if (!is_string($storedContent)
            || !hash_equals($cleanedContent, $storedContent)
            || !is_string($storedRevisionHash)
            || preg_match('/^[a-f0-9]{64}$/D', $storedRevisionHash) !== 1
            || !hash_equals($expectedRevisionHash, $storedRevisionHash)
            || !hash_equals($storedRevisionHash, hash('sha256', $storedContent))
            || !is_string($candidateUid)
            || preg_match('/^[a-f0-9]{32}$/D', $candidateUid) !== 1) {
            return null;
        }

        $candidate = DB::fetch(
            'SELECT * FROM chapter_candidates'
                . ' WHERE candidate_uid=? AND chapter_id=? AND novel_id=? LIMIT 1',
            [$candidateUid, $chapterId, $novelId]
        );
        if (!is_array($candidate)
            || (string)($candidate['source'] ?? '') !== ChapterCandidateRepository::SOURCE_INITIAL
            || (string)($candidate['lifecycle'] ?? '') !== ChapterCandidateRepository::LIFECYCLE_COMMITTED
            || (string)($candidate['gate_state'] ?? '') !== ChapterCandidateRepository::GATE_PASSED
            || ($candidate['source_run_uid'] ?? null) !== null
            || ($candidate['source_task_id'] ?? null) !== null
            || ($candidate['parent_candidate_uid'] ?? null) !== null
            || !is_string($candidate['committed_at'] ?? null)
            || (string)$candidate['committed_at'] === '') {
            return null;
        }

        $candidateContent = $candidate['inline_content'] ?? null;
        $candidateContentHash = $candidate['content_hash'] ?? null;
        $candidateDecisionJson = $candidate['validation_json'] ?? null;
        $expectedWords = countWords($cleanedContent);
        if (!is_string($candidateContent)
            || !hash_equals($cleanedContent, $candidateContent)
            || !is_string($candidateContentHash)
            || !hash_equals($expectedRevisionHash, $candidateContentHash)
            || !hash_equals($candidateContentHash, hash('sha256', $candidateContent))
            || !is_string($candidateDecisionJson)
            || $candidateDecisionJson === ''
            || (int)($candidate['words'] ?? -1) !== $expectedWords
            || (int)($freshChapter['words'] ?? -1) !== $expectedWords) {
            return null;
        }
        $candidateDecision = QualityGateDecision::fromJson($candidateDecisionJson);
        if ($candidateDecision->shouldQuarantine()
            || !hash_equals($expectedRevisionHash, $candidateDecision->contentHash())) {
            return null;
        }

        $expectedBase = WriteEngine::validateChapterBaseSnapshot(
            $claimedChapter['_base_snapshot'] ?? [],
            $daemonClaimToken
        );
        $candidateBaseJson = $candidate['base_snapshot_json'] ?? null;
        if (!is_string($candidateBaseJson) || $candidateBaseJson === '') {
            return null;
        }
        $candidateBaseValue = json_decode(
            $candidateBaseJson,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($candidateBaseValue) || array_is_list($candidateBaseValue)) {
            return null;
        }
        $candidateBase = WriteEngine::validateChapterBaseSnapshot(
            $candidateBaseValue,
            $daemonClaimToken
        );
        foreach ([
            'status',
            'canonical_state',
            'content_revision_hash',
            'content_hash',
            'claim_token',
        ] as $baseField) {
            if ($candidateBase[$baseField] !== $expectedBase[$baseField]) {
                return null;
            }
        }
        $candidateBaseRevision = $candidate['base_revision_hash'] ?? null;
        if ($candidateBaseRevision !== $expectedBase['content_revision_hash']) {
            return null;
        }

        $expectedPolicyJson = $policySnapshot->toJson();
        $expectedPolicyHash = $policySnapshot->checksum();
        $candidatePolicyJson = $candidate['policy_snapshot_json'] ?? null;
        $candidatePolicyHash = $candidate['policy_snapshot_hash'] ?? null;
        $chapterPolicyJson = $freshChapter['write_policy_snapshot_json'] ?? null;
        if (!is_string($candidatePolicyJson)
            || !is_string($candidatePolicyHash)
            || !is_string($chapterPolicyJson)
            || preg_match('/^[a-f0-9]{64}$/D', $candidatePolicyHash) !== 1
            || !hash_equals($expectedPolicyHash, $candidatePolicyHash)) {
            return null;
        }
        $candidatePolicy = WritePolicySnapshot::fromJson($candidatePolicyJson);
        $chapterPolicy = WritePolicySnapshot::fromJson($chapterPolicyJson);
        if (!hash_equals($expectedPolicyHash, $candidatePolicy->checksum())
            || !hash_equals($expectedPolicyHash, $chapterPolicy->checksum())
            || !hash_equals($expectedPolicyJson, $candidatePolicy->toJson())
            || !hash_equals($expectedPolicyJson, $chapterPolicy->toJson())
            || !hash_equals($policySnapshot->canonPolicy(), $candidateDecision->policyMode())
            || !hash_equals($policySnapshot->qualityRuleVersion(), $candidateDecision->policyVersion())) {
            return null;
        }
    } catch (\Throwable $authenticationError) {
        // A DB/read/parse failure can never be promoted to a recovered success.
        error_log('daemon Canon recovery authentication failed: ' . $authenticationError->getMessage());
        return null;
    }

    $acceptedChapter = array_merge($claimedChapter, $freshChapter);
    $postprocessResult = null;
    try {
        // enqueueAndRun() is idempotent by Canon revision.  If the original
        // path already enqueued the job, this resumes/observes that same job.
        $postprocessResult = PostprocessRunner::enqueueAndRun(
            $novelId,
            $acceptedChapter,
            $cleanedContent,
            $engine,
            'daemon-recovery:' . (gethostname() ?: 'localhost') . ':' . getmypid(),
            $policySnapshot
        );
    } catch (\Throwable $postprocessError) {
        // Canon is already durable and authenticated.  A projection enqueue
        // problem must not rewrite it or consume a content-generation retry.
        error_log('daemon Canon recovery postprocess enqueue failed: ' . $postprocessError->getMessage());
        try {
            addLog(
                $novelId,
                'warn',
                'Daemon 已恢复已提交正文，但后处理入队失败；将由后续对账补偿',
                $chapterId
            );
        } catch (\Throwable $logError) {
            error_log('daemon Canon recovery warning log failed: ' . $logError->getMessage());
        }
    }

    return [
        'chapter' => $acceptedChapter,
        'content' => $cleanedContent,
        'words' => $expectedWords,
        'postprocess_result' => $postprocessResult,
    ];
}

/**
 * Execute a daemon claim through the durable chapter_generate_v1 Workflow.
 *
 * The daemon remains responsible only for its HTTP/lock transport contract;
 * phase ordering, checkpoints, retry typing and the Canon authority fence are
 * shared with the SSE and asynchronous-worker entry points.
 *
 * @param array<string,mixed> $novel
 * @param array<string,mixed> $claimedChapter
 * @return array{
 *   novel:array<string,mixed>,chapter:array<string,mixed>,content:string,
 *   words:int,model_info:string,model_label:?string,result:WorkflowExecutionResult
 * }
 */
function daemonRunAuthorityChapter(
    int $novelId,
    array $novel,
    array $claimedChapter,
    string $claimToken,
    WritePolicySnapshot $policySnapshot,
    HarnessFeatureSnapshot $featureSnapshot,
    DaemonLockManager $lock
): array {
    $userId = (int)($novel['user_id'] ?? 0);
    $chapterId = (int)($claimedChapter['id'] ?? 0);
    $baseSnapshot = $claimedChapter['_base_snapshot'] ?? null;
    if ($userId <= 0
        || $chapterId <= 0
        || preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1
        || !is_array($baseSnapshot)) {
        throw new PermanentStageException(
            'Daemon authority path has no authenticated owner or Chapter claim.'
        );
    }

    $workerId = substr(
        'daemon:' . (gethostname() ?: 'localhost') . ':' . getmypid()
            . ':' . HarnessRuntime::randomHex(8),
        0,
        120
    );
    $chapterInputSnapshot = ChapterRunInputSnapshotFactory::capture(
        $novel,
        $claimedChapter
    );
    // 专项一（2026-09-28）：authority 编排骨架（invocation 组装、执行工厂、
    // 恢复闭包、adapter 工厂、runToTerminal 调用与终态映射）收敛进
    // ChapterWriteOrchestrator；daemon 保留锁文件/日志传输回调与类型化异常转译。
    $activeNovel = $novel;
    $activeChapter = $claimedChapter;
    $acceptedContent = '';
    $acceptedWords = 0;
    $modelInfo = '';
    $modelLabel = null;
    $daemonHeartbeatAt = 0;
    $lockFile = $lock->lockFilePath();
    $initialResolved = ['n' => $novel, 'ch' => $claimedChapter];

    $transport = new CallbackLegacyChapterTransport([
        'chunk' => static function (string $chunk) use ($lockFile): void {
            @touch($lockFile);
        },
        'message' => static function (array $payload) use ($novelId): void {
            if (!empty($payload['model'])) {
                addLog(
                    $novelId,
                    'daemon_model',
                    (string)$payload['model'] . ' 第'
                        . (int)($payload['attempt'] ?? 1) . '次尝试'
                );
            } elseif (!empty($payload['model_switch'])) {
                addLog(
                    $novelId,
                    'daemon_model_switch',
                    '切换模型：' . (string)($payload['next_model'] ?? 'unknown')
                );
            } elseif (!empty($payload['warning'])) {
                addLog($novelId, 'warn', (string)$payload['warning']);
            } elseif (!empty($payload['reason'])) {
                addLog($novelId, 'daemon_retry_model', (string)$payload['reason']);
            } elseif (!empty($payload['info'])) {
                addLog($novelId, 'info', (string)$payload['info']);
            }
        },
        'heartbeat' => static function () use (
            &$daemonHeartbeatAt,
            $lockFile,
            $novelId,
            $claimToken,
            &$activeChapter
        ): void {
            $now = time();
            @touch($lockFile);
            if ($now - $daemonHeartbeatAt < 10) {
                return;
            }
            $daemonHeartbeatAt = $now;
            try {
                DB::query(
                    'UPDATE chapters SET updated_at=GREATEST('
                        . 'NOW(), DATE_ADD(updated_at, INTERVAL 1 SECOND))'
                        . ' WHERE id=? AND novel_id=? AND status="writing"'
                        . ' AND claim_token=?',
                    [(int)$activeChapter['id'], $novelId, $claimToken]
                );
            } catch (Throwable $exception) {
                error_log(
                    'daemon_write authority heartbeat update failed: '
                        . $exception->getMessage()
                );
            }
        },
        'acquired' => static function (
            array $resolvedNovel,
            array $resolvedChapter
        ) use (&$activeNovel, &$activeChapter): void {
            $activeNovel = $resolvedNovel;
            $activeChapter = $resolvedChapter;
        },
        'saved' => static function (array $saveResult) use (
            &$activeChapter,
            &$acceptedContent,
            &$acceptedWords,
            &$modelInfo,
            &$modelLabel
        ): void {
            $activeChapter = $saveResult['chapter'];
            $acceptedContent = (string)$saveResult['accepted_content'];
            $acceptedWords = (int)$saveResult['words'];
            $modelInfo = (string)($saveResult['model_info'] ?? '');
            $label = $saveResult['_model_label'] ?? null;
            $modelLabel = is_string($label) && $label !== '' ? $label : null;
        },
        'postprocessed' => static function (array $result) use ($novelId): void {
            if (!in_array(
                (string)($result['state'] ?? ''),
                ['done', 'stale', 'skipped'],
                true
            )) {
                addLog(
                    $novelId,
                    'warn',
                    'Daemon 后处理已进入持久重试队列，任务ID：'
                        . (int)($result['job_id'] ?? 0)
                );
            }
        },
    ]);
    $daemonRestoreVerifier = static function (
        RunContext $context,
        array $restoredChapter,
        array $authenticatedBase
    ) use ($claimToken): void {
        if (!hash_equals(
            $claimToken,
            (string)($restoredChapter['claim_token'] ?? '')
        )) {
            throw new StaleRevisionException(
                'Daemon Chapter claim was replaced.'
            );
        }
    };

    // 终态转译：QUARANTINED/CANCELED/FAILED 抛类型化异常交由外层统一处理
    // （Canon 恢复 / 取消 / 重试决策）；SUCCEEDED 无回调即继续本函数。
    $daemonTermination = new CallbackWriteTermination([
        'quarantined' => static function (
            WorkflowExecutionResult $result,
            bool $contentSaved
        ): void {
            throw new WriteEngineValidationException(
                'Durable Chapter Run was quarantined by the frozen quality policy.'
            );
        },
        'canceled' => static function (
            WorkflowExecutionResult $result,
            bool $contentSaved
        ): void {
            throw new RunCanceledException();
        },
        'failed' => static function (
            WorkflowExecutionResult $result,
            bool $contentSaved
        ): void {
            throw new PermanentStageException(
                'Durable Chapter Run ended in state ' . $result->state->value . '.'
            );
        },
    ]);

    $runRequest = new ChapterWriteRunRequest(
        entryPoint: ChapterRunEntryPoint::DAEMON,
        userId: $userId,
        novelId: $novelId,
        chapterId: $chapterId,
        authorityIdentity: $claimToken,
        policySnapshot: $policySnapshot,
        featureSnapshot: $featureSnapshot,
        inputSnapshot: $chapterInputSnapshot,
        workerId: $workerId,
        transport: $transport,
        acquireChapter: static function (Closure $restore, RunContext $context) use (
            $initialResolved,
            $novelId
        ): array {
            WriteEngine::runPreWriteAgents(
                $novelId,
                (int)$initialResolved['ch']['chapter_number']
            );
            return $initialResolved;
        },
        restoreBase: static fn (RunContext $context, array $chapter): array => $baseSnapshot,
        restoreMissingMessage: 'Daemon recovery source disappeared.',
        restoreInactiveMessage: 'Daemon Chapter claim is no longer active.',
        postprocessWorkerId: $workerId,
        termination: $daemonTermination,
        baseSnapshot: $baseSnapshot,
        restoreVerifier: $daemonRestoreVerifier,
        legacyCancellationProbe: static fn (): bool =>
            file_exists(WriteEngine::cancelFlagPath($novelId)),
        retryTick: static function (
            string $runUid,
            WorkflowExecutionResult $retryResult
        ) use ($lockFile, $novelId): ?WorkflowExecutionResult {
            @touch($lockFile);
            if (file_exists(WriteEngine::cancelFlagPath($novelId))) {
                (new GenerationRunRepository(DB::getPdo()))->requestCancelWithEvents($runUid);
                throw new RunCanceledException();
            }
            return null;
        }
    );

    $result = (new ChapterWriteOrchestrator(DB::getPdo()))->run($runRequest);
    // 仅 SUCCEEDED 到达此处（其余终态回调均抛类型化异常）。
    if ($acceptedContent === '') {
        $acceptedContent = (string)($activeChapter['content'] ?? '');
        $acceptedWords = countWords($acceptedContent);
    }

    return [
        'novel' => $activeNovel,
        'chapter' => $activeChapter,
        'content' => $acceptedContent,
        'words' => $acceptedWords,
        'model_info' => $modelInfo,
        'model_label' => $modelLabel,
        'result' => $result,
    ];
}

// ================================================================
// 1. 鉴权：Token 验证
// ================================================================

// 审计 P1：仅从请求头读取令牌（请求头不进访问日志/代理日志/浏览器历史）。
// 不再接受 URL/POST 参数中的 token（会进日志，存在泄露风险）。
$hdrs = function_exists('getallheaders') ? array_change_key_case((array)getallheaders(), CASE_LOWER) : [];
$inputToken = trim($hdrs['x-daemon-token'] ?? $_SERVER['HTTP_X_DAEMON_TOKEN'] ?? '');
if ($inputToken === '') {
    $authHeader = $hdrs['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $authHeader, $m)) {
        $inputToken = trim($m[1]);
    }
}
$novelId    = (int)($_GET['novel_id'] ?? $_POST['novel_id'] ?? 0);
$action     = trim($_GET['action'] ?? $_POST['action'] ?? 'run');
if (in_array($action, ['enable', 'disable'], true) && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($inputToken === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Token required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$daemonToken = getDaemonToken();
if ($daemonToken === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'Daemon token is not initialized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals($daemonToken, $inputToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token 错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 审计 Low-3（2026-10-31）：可选 TTL（daemon_token_ttl_days，默认 0=不过期）。
// 先验令牌再验期龄：未持有正确令牌的调用方统一 403，不泄露期龄信息；
// 升级前签发的令牌没有 issued_at，fail-safe 永不过期。
if (cronSecretTtlExpired('daemon_token_ttl_days', 'daemon_token_issued_at')) {
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'msg'   => 'Token 已过期，请轮换令牌并更新 Cron 配置',
        'error' => 'token_expired',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// P0-3 修复：检测 session 用户身份，供 enable/disable 操作做所有权校验。
// daemon_write.php 不引入 auth.php（避免自动 session_start 干扰 cron 调用），
// 此处手动启动 session 以读取已登录用户 ID。
// L-1 修复：仅在需要所有权校验的 enable/disable 动作才启动 session。run/status 走
// cron（curl 无 cookie），无条件 session_start 会让每分钟一次的挂机轮询都新建一个空
// session 文件，长期累积孤儿文件直到 gc——cron 路径不需要 session。
$sessionUserId = 0;
if (in_array($action, ['enable', 'disable'], true)) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (!empty($_SESSION['user_id'])) {
        $sessionUserId = (int)$_SESSION['user_id'];
    } elseif (!empty($_SESSION['uid'])) {
        $sessionUserId = (int)$_SESSION['uid'];
    }
}

// ================================================================
// 2. 管理操作（启用/停用/状态查询）
// ================================================================

// Lock 目录（提前定义，供 enable/disable/status/run 共用）
$lockDir = defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__) . '/storage';
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);

if ($action === 'enable' || $action === 'disable') {
    if (!$novelId) {
        echo json_encode(['ok' => false, 'msg' => '缺少 novel_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // P0-3 修复：所有权校验——区分 Web UI 调用与 Cron 调用。
    // Web UI（有 session user_id）：必须校验小说所有权，防止越权操作他人小说。
    // Cron（无 session，仅 daemon token）：daemon token 即为授权，设计上允许操作任意小说。
    if ($sessionUserId > 0) {
        require_once dirname(__DIR__) . '/includes/auth.php';
        checkNovelOwnership($novelId, $sessionUserId);
    }
    // else: daemon token 鉴权已通过，Cron 需要操作任意小说——此为设计意图。
    $val = $action === 'enable' ? 1 : 0;
    try {
        $switchedFrom = null;
        $reopenedCompleted = false;
        if ($val === 1) {
            // 全局唯一：先关闭其他已开启的挂机
            $existing = DB::fetch(
                "SELECT id, title FROM novels WHERE daemon_write=1 AND id!=?",
                [$novelId]
            );
            if ($existing) {
                DB::update('novels', ['daemon_write' => 0], 'id=?', [$existing['id']]);
                clearNovelCache((int)$existing['id']);
                $switchedFrom = ['id' => (int)$existing['id'], 'title' => $existing['title']];
            }
        }
        if ($val === 1) {
            // An explicit enable is a new user-authorized daemon generation.
            // Remove the old acceleration flag before clearing its durable DB
            // counterpart; a later cancel writes a new file and wins normally.
            @unlink(WriteEngine::cancelFlagPath($novelId));
            DB::update(
                'novels',
                ['daemon_write' => 1, 'cancel_flag' => 0],
                'id=?',
                [$novelId]
            );
            // 审计修复（2026-10-18）："已完成"只表示当时全部已建章节写完。之后新增的
            // 大纲/章节不会改写小说状态（生成大纲刻意不改状态），而认领守卫拒绝
            // completed 小说——用户开启挂机后每次 cron 都"canceled"跳过、永远不写。
            // 显式开启挂机且仍有可自动写的章节时重开为 paused（与前端"自动写作"
            // 启动时改状态同理）；认领时再由守卫内的 UPDATE 转为 writing。
            if (WriteEngine::countAutomaticWritableChapters($novelId) > 0
                && DB::execute(
                    'UPDATE novels SET status="paused" WHERE id=? AND status="completed"',
                    [$novelId]
                ) === 1) {
                $reopenedCompleted = true;
            }
        } else {
            DB::update('novels', ['daemon_write' => 0], 'id=?', [$novelId]);
        }
        // 关键：失效 getNovel() 的小说缓存（novel:{id}，TTL 300s）。否则停用挂机后刷新
        // novel.php 仍读到缓存里的 daemon_write=1，「挂机写作进行中」面板会再次出现。
        clearNovelCache($novelId);
        $schedule = DaemonSchedule::fromSettings();
        $enableMessage = "小说#{$novelId} 挂机写作已启用";
        if ($val === 1 && $schedule['enabled'] && !$schedule['allowed']) {
            $enableMessage .= '，当前在运行时段外，将于 ' . $schedule['next_start_at'] . ' 自动开始';
        }
        if ($reopenedCompleted) {
            $enableMessage .= '（检测到新的待写章节，小说已从“已完成”恢复为可继续写作）';
            addLog($novelId, 'daemon_reopen', '开启挂机：检测到已完成小说有新的待写章节，状态恢复为暂停待写');
        }
        $resp = [
            'ok'  => true,
            'msg' => $val ? $enableMessage : "小说#{$novelId} 挂机写作已停用",
            'schedule' => $schedule,
        ];
        if ($switchedFrom) $resp['switched_from'] = $switchedFrom;
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(safe_api_error_payload($e, '操作失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'status') {
    try {
        // 无论是否传 novel_id，优先找当前开启挂机的书
        if (!$novelId) {
            $row = DB::fetch("SELECT id FROM novels WHERE daemon_write=1 LIMIT 1");
            if ($row) $novelId = (int)$row['id'];
        }
        if (!$novelId) {
            echo json_encode(['ok' => true, 'data' => null, 'msg' => '没有开启挂机的小说'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $novel     = DB::fetch("SELECT id, title, status, current_chapter, total_words, daemon_write FROM novels WHERE id=?", [$novelId]);
        $completed = DB::count('chapters', 'novel_id=? AND status="completed"', [$novelId]);
        $outlined  = DB::count('chapters', 'novel_id=? AND status IN ("outlined","writing","completed","skipped")', [$novelId]);
        $skipped   = DB::count('chapters', 'novel_id=? AND status="skipped"', [$novelId]);
        $autoWritable = WriteEngine::countAutomaticWritableChapters($novelId);
        $manualBackfill = WriteEngine::countManualBackfillChapters($novelId);
        $locked    = file_exists($lockDir . "/daemon_lock_{$novelId}.tmp");
        $logs      = DB::fetchAll(
            "SELECT action, message, created_at FROM writing_logs WHERE novel_id=? ORDER BY created_at DESC LIMIT 10",
            [$novelId]
        );
        foreach ($logs as $index => $log) {
            $logs[$index]['created_at_display'] = formatAppTime($log['created_at'] ?? null);
        }
        echo json_encode([
            'ok'   => true,
            'data' => array_merge($novel ?: [], [
                'completed' => $completed,
                'outlined'  => $outlined,
                'skipped'   => $skipped,
                'auto_writable' => $autoWritable,
                'manual_backfill' => $manualBackfill,
                'auto_blocked' => $autoWritable === 0 && $manualBackfill > 0,
                'locked'    => $locked,
                'schedule'  => DaemonSchedule::fromSettings(),
                'logs'      => $logs,
            ]),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(safe_api_error_payload($e, '操作失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// W-3 修复：删除重复的 session 初始化块（已在文件前部完成）。
// 之前此处有第二份 P0-3 修复代码，会导致 run 路径下 session_start 被调用两次，
// cron 调用可能被 session 锁阻塞。

// ================================================================
// 3. 主执行逻辑：run（由 Cron 触发）
// ================================================================

// 未传 novel_id 时自动找当前开启挂机的书（全局唯一）
if (!$novelId) {
    try {
        $row = DB::fetch("SELECT id FROM novels WHERE daemon_write=1 LIMIT 1");
        if ($row) $novelId = (int)$row['id'];
    } catch (\Throwable $e) {
        error_log('daemon_write: 查询挂机小说失败 — ' . $e->getMessage());
    }
}

if (!$novelId) {
    echo json_encode(['ok' => false, 'msg' => '没有启用挂机写作的小说', 'skipped' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 审计修复（2026-07-19 H-06）：与交互式 resolveChapter 一致取全列，
    // buildPrompt/initMemory 需要 title/genre/protagonist_name 等元数据
    $novel = DB::fetch("SELECT * FROM novels WHERE id=?", [$novelId]);
} catch (\Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] daemon_write: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(safe_api_error_payload($e, '查询小说失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$novel) {
    echo json_encode(['ok' => false, 'msg' => "小说#{$novelId} 不存在"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($novel['daemon_write'])) {
    echo json_encode(['ok' => false, 'msg' => "小说#{$novelId} 未启用挂机写作", 'skipped' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// 每日运行窗口只约束本次 Cron 是否启动新章节；已经由上一轮进程启动的章节会在
// 原进程内继续完整收尾。窗口外不请求 AI，也不关闭挂机开关，进入窗口后自动恢复。
$daemonSchedule = DaemonSchedule::fromSettings();
if ($daemonSchedule['enabled'] && !$daemonSchedule['allowed']) {
    echo json_encode([
        'ok' => true,
        'msg' => $daemonSchedule['message'],
        'skipped' => true,
        'reason' => 'outside_schedule',
        'schedule' => $daemonSchedule,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$activeWriteTask = WriteEngine::findActiveTask($novelId);
if ($activeWriteTask !== null) {
    echo json_encode([
        'ok'         => false,
        'msg'        => 'Detected active foreground writing task; skipped daemon write.',
        'skipped'    => true,
        'chapter_id' => $activeWriteTask['chapter_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 启动新任务前显式回收 DB 中已超过心跳阈值的 writing。findActiveTask 已先排除
// 有效进度文件/新鲜 DB 心跳，因此这里不会抢走真实活跃的前台任务。
try {
    $recoveredWriting = WriteEngine::recoverStaleWritingChapters($novelId);
    if ($recoveredWriting) clearNovelCache($novelId);
} catch (\Throwable $e) {
    error_log('daemon_write stale-writing recovery failed: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'msg' => '无法恢复上次中断的写作状态，本次挂机已停止',
        'skipped' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Lock 文件：防止并发 ----
// 审计 2026-08-13 #2：锁获取 / 死锁接管 / 残留检查 / shutdown 恢复
// 已封装到 DaemonLockManager（includes/daemon/DaemonLockManager.php）。
// 主流程通过 $lock->claimedChapterId / stateFinalized 与之交互，
// shutdown 闭包捕获对象引用，属性变化对闭包可见。
$lock = new DaemonLockManager($novelId, $lockDir);

$acquireResult = $lock->acquire();
if ($acquireResult !== null) {
    echo json_encode($acquireResult, JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查残留锁中记录的章节是否已完成（进程意外终止但 Lock 残留）
$lock->inspectResidualChapterLock();

// shutdown 是 finally 的最后一道保险：进程 fatal/被异常终止时，释放本进程认领的
// writing，并把小说从 writing 恢复到 paused。WHERE 限定保证不覆盖已完成正文。
$lock->registerShutdownHandler();

// 查找下一章：与交互式自动写作共用同一个“完成进度线”契约。
// 任何编号不高于已完成最大章节号的 outlined/skipped 都是历史缺口，只能人工显式处理。
$nextAutoChapterId = WriteEngine::findNextAutomaticChapterId($novelId);
// 审计修复（2026-07-19 H-06 / 2026-08-17）：列清单与 WriteEngine::chapterWriteSelectList 对齐（另加 retry_count），
// 否则 buildPrompt 拿不到 outline/爽点/钩子/概要蓝图，挂机章节会脱离本章大纲写作。
$nextChapter = $nextAutoChapterId
    ? DB::fetch(
        'SELECT ' . WriteEngine::chapterWriteSelectList(['retry_count'])
        . ' FROM chapters WHERE id=? AND novel_id=?',
        [$nextAutoChapterId, $novelId]
    )
    : null;
$isSkippedRetry = $nextChapter && (string)$nextChapter['status'] === 'skipped';

if (!$nextChapter) {
    // 没有“可写”章节不等于全书完成：pending/failed/writing 都是未完成状态。
    // 只有所有章节均为 completed 才能把小说标记为 completed。
    try {
        $finalState = daemonFinalizeNovelState($novelId, true);
        $lock->stateFinalized = true;
    } finally {
        $lock->release();
    }
    $totalChapterRows = $finalState['total'];
    $unfinished = $finalState['unfinished'];
    $manualBackfill = $finalState['manual'];
    if ($finalState['action'] !== 'superseded') {
        clearNovelCache($novelId);
    }
    if (in_array($finalState['action'], ['superseded', 'paused'], true)) {
        echo json_encode(['ok' => false, 'skipped' => true, 'msg' => '写作状态已变化，跳过本次挂机收尾'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($finalState['action'] === 'completed') {
        addLog($novelId, 'daemon_done', '挂机写作完成，所有章节已生成，已自动关闭挂机');
        echo json_encode(['ok' => true, 'msg' => '所有章节写作完成，挂机已自动关闭', 'done' => true], JSON_UNESCAPED_UNICODE);
    } else {
        // 审计修复（2026-08-17 写作逻辑 B0）：进度线被质检 failed 章堵住时点名该章，
        // 引导用户去主界面「保留正文重写」，而不是笼统报未完成数量。
        $failedBlocker = null;
        try {
            $frontier = WriteEngine::automaticChapterFrontier($novelId);
            $frontNext = DB::fetch(
                'SELECT chapter_number, status FROM chapters
                 WHERE novel_id=? AND chapter_number>? ORDER BY chapter_number ASC LIMIT 1',
                [$novelId, $frontier]
            );
            if ($frontNext && (string)$frontNext['status'] === 'failed') {
                $failedBlocker = (int)$frontNext['chapter_number'];
            }
        } catch (Throwable $frontierError) {
            error_log('daemon_write blocked_by_failed probe failed: ' . $frontierError->getMessage());
        }
        if ($failedBlocker !== null) {
            $blockedMsg = "第{$failedBlocker}章质检未过，堵住自动写作进度线；请到章节列表该章「保留正文重写」或「清空重写」后再重启挂机";
            addLog($novelId, 'daemon_blocked', $blockedMsg . "（未完成{$unfinished}章）");
            echo json_encode([
                'ok' => false,
                'msg' => $blockedMsg,
                'done' => false,
                'blocked' => true,
                'blocked_by_failed' => ['chapter_number' => $failedBlocker],
                'unfinished' => $unfinished,
                'manual_backfill_count' => $manualBackfill,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        addLog($novelId, 'daemon_blocked', "没有可安全自动写作的章节；章节总数{$totalChapterRows}、未完成{$unfinished}、需人工补写{$manualBackfill}，挂机已暂停");
        echo json_encode([
            'ok' => false,
            'msg' => $totalChapterRows === 0
                ? '尚未生成章节大纲，挂机已暂停'
                : "仍有{$unfinished}章未完成，其中{$manualBackfill}章位于完成进度线之前，需人工显式处理；挂机已暂停",
            'done' => false,
            'blocked' => true,
            'unfinished' => $unfinished,
            'manual_backfill_count' => $manualBackfill,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 写入 Lock 内容（已有 flock，直接写入即可）
$lock->writeClaimContent((int)$nextChapter['id']);

// A stale filesystem flag is only an acceleration artifact. Remove it before
// entering the durable claim transaction. If a concurrent user cancellation
// has already committed, cancel_flag=1 rejects the claim below; if it commits
// later, it observes and releases the writing claim and writes a fresh file.
@unlink(WriteEngine::cancelFlagPath($novelId));

// The cancellation path locks chapter rows before the novel row. Follow the
// same global order and atomically authenticate daemon_write/cancel_flag with
// the exact chapter claim. This closes the former window where cancel could
// commit between resetting cancel_flag and claiming the chapter.
$claimPdo = DB::connect();
$claimTransactionOpen = false;
$claimRejected = null;
$permanentSkip = false;
$claimProjectionFence = null;
try {
    $claimProjectionFence = NovelProjectionFence::acquire($novelId, 30);
    if ($claimPdo->inTransaction() || !$claimPdo->beginTransaction()) {
        throw new RuntimeException('Could not start daemon chapter claim transaction.');
    }
    $claimTransactionOpen = true;

    $lockedChapter = DB::fetch(
        'SELECT ' . WriteEngine::chapterWriteSelectList(['retry_count'])
        . ' FROM chapters WHERE id=? AND novel_id=? FOR UPDATE',
        [(int)$nextChapter['id'], $novelId]
    );
    $lockedNovel = DB::fetch(
        'SELECT status, daemon_write, cancel_flag FROM novels WHERE id=? FOR UPDATE',
        [$novelId]
    );

    if (!is_array($lockedChapter)
        || !in_array((string)($lockedChapter['status'] ?? ''), ['outlined', 'skipped'], true)
        || (string)($lockedChapter['claim_token'] ?? '') !== '') {
        $claimRejected = 'chapter_conflict';
    } elseif (!is_array($lockedNovel)
        || (int)($lockedNovel['daemon_write'] ?? 0) !== 1
        || (int)($lockedNovel['cancel_flag'] ?? 0) !== 0
        || (string)($lockedNovel['status'] ?? '') === 'completed') {
        $claimRejected = 'daemon_canceled';
    }

    if ($claimRejected !== null) {
        $claimPdo->rollBack();
        $claimTransactionOpen = false;
    } else {
        // All subsequent prompt/gate decisions must use the exact locked row,
        // not the pre-transaction frontier snapshot.
        $nextChapter = $lockedChapter;
        $isSkippedRetry = (string)$nextChapter['status'] === 'skipped';
        $currentRetries = (int)($nextChapter['retry_count'] ?? 0);

        if ($isSkippedRetry && $currentRetries >= DAEMON_MAX_RETRIES * 2) {
            $markedPermanent = DB::execute(
                'UPDATE chapters SET status="failed"'
                . ' WHERE id=? AND novel_id=? AND status="skipped"'
                . ' AND claim_token="" AND retry_count=?',
                [(int)$nextChapter['id'], $novelId, $currentRetries]
            );
            if ($markedPermanent !== 1) {
                throw new RuntimeException('Daemon permanent-skip fence was lost.');
            }
            DB::execute(
                'UPDATE novels SET status="paused"'
                . ' WHERE id=? AND daemon_write=1 AND cancel_flag=0 AND status<>"completed"',
                [$novelId]
            );
            $claimPdo->commit();
            $claimTransactionOpen = false;
            $permanentSkip = true;
        } else {
            $daemonClaimToken = WriteEngine::newClaimToken();
            $nextChapter['_base_snapshot'] = WriteEngine::captureChapterBaseSnapshot(
                $nextChapter,
                $daemonClaimToken
            );
            $claimAffected = DB::execute(
                'UPDATE chapters SET status="writing", claim_token=?'
                . ' WHERE id=? AND novel_id=? AND status=? AND claim_token=""'
                . ' AND COALESCE(canonical_state,"none")<>"canonical"',
                [
                    $daemonClaimToken,
                    (int)$nextChapter['id'],
                    $novelId,
                    (string)$nextChapter['status'],
                ]
            );
            // 审计修复（2026-08-24 H1）：小说守卫（daemon_write/cancel_flag/
            // status<>"completed"）已由事务内 FOR UPDATE 行锁 + lockedNovel 校验
            // 保证，此处的状态写入是幂等的。PDO 默认返回 changed rows——当
            // novels.status 已残留 "writing"（worker Phase 5.5 回写失败或进程被
            // SIGKILL 的残留，且无任何 writing 章节）时 UPDATE 值不变、affected=0，
            // 原先据此抛 fence-lost 会让挂机认领永久失败且无自愈路径。章节 CAS
            // 仍严格要求 affected===1：outlined/skipped→writing 是真实状态跃迁，
            // 值必然变化，changed rows 与 matched rows 恒等。
            DB::execute(
                'UPDATE novels SET status="writing"'
                . ' WHERE id=? AND daemon_write=1 AND cancel_flag=0 AND status<>"completed"',
                [$novelId]
            );
            if ($claimAffected !== 1) {
                throw new RuntimeException('Daemon chapter claim fence was lost.');
            }
            $claimPdo->commit();
            $claimTransactionOpen = false;
        }
    }
} catch (Throwable $claimError) {
    if ($claimTransactionOpen && $claimPdo->inTransaction()) {
        $claimPdo->rollBack();
    }
    if ($claimProjectionFence !== null) {
        try {
            NovelProjectionFence::release($claimProjectionFence);
        } catch (Throwable $releaseError) {
            error_log('daemon_write claim fence release failed: ' . $releaseError->getMessage());
        }
        $claimProjectionFence = null;
    }
    $lock->release();
    $lock->stateFinalized = true;
    error_log('daemon_write atomic claim failed: ' . $claimError->getMessage());
    echo json_encode(
        safe_api_error_payload($claimError, '挂机章节认领失败，请稍后重试'),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
if ($claimProjectionFence !== null) {
    NovelProjectionFence::release($claimProjectionFence);
    $claimProjectionFence = null;
}

if ($claimRejected !== null) {
    clearNovelCache($novelId);
    $lock->release();
    $lock->stateFinalized = true;
    echo json_encode([
        'ok' => false,
        'msg' => $claimRejected === 'daemon_canceled'
            ? 'Daemon writing was canceled before the chapter claim.'
            : 'Chapter is already claimed by another writing task.',
        'skipped' => true,
        'canceled' => $claimRejected === 'daemon_canceled',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($permanentSkip) {
    clearNovelCache($novelId);
    $lock->release();
    $lock->stateFinalized = true;
    addLog(
        $novelId,
        'daemon_permanent_skip',
        "第{$nextChapter['chapter_number']}章累计失败{$currentRetries}次，永久跳过"
    );
    echo json_encode([
        'ok' => false,
        'msg' => "第{$nextChapter['chapter_number']}章永久跳过（累计失败过多）",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$lock->claimedChapterId = (int)$nextChapter['id'];
$lock->claimedChapterNumber = (int)$nextChapter['chapter_number'];
$lock->claimedChapterToken = $daemonClaimToken;
$lock->claimedChapterSnapshot = $nextChapter['_base_snapshot'];
// 审计 P0：令牌随章节数组流转（saveChapter CAS / finally 恢复均依赖它）
$nextChapter['claim_token'] = $daemonClaimToken;

try {
    WriteEngine::waitForPreviousPostProcess(
        $novelId,
        (int)$nextChapter['chapter_number'],
        (int)$nextChapter['id'],
        $daemonClaimToken
    );
} catch (Throwable $e) {
    WriteEngine::releaseChapterClaim((int)$nextChapter['id'], $nextChapter);
    // This waiter may already have lost its claim to a newer daemon/manual
    // writer. It has no authority to pause novel.status after that point.
    clearNovelCache($novelId);
    $lock->release();
    $lock->stateFinalized = true;
    addLog($novelId, 'daemon_wait_pp', $e->getMessage());
    echo json_encode([
        'ok'           => false,
        'msg'          => $e->getMessage(),
        'skipped'      => true,
        'retry_later'  => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

addLog($novelId, 'daemon_start',
    ($isSkippedRetry ? '[跳过章重试]' : '') . "挂机开始写作第{$nextChapter['chapter_number']}章《{$nextChapter['title']}》"
);

// ================================================================
// 4. 立即返回 202 响应，后台继续执行写作（避免 nginx 502 超时）
// ================================================================

set_time_limit(CFG_TIME_LONG);
ignore_user_abort(true);

// 先把响应发回给 nginx/curl，断开 HTTP 连接
http_response_code(202);
echo json_encode([
    'ok'             => true,
    'msg'            => "第{$nextChapter['chapter_number']}章开始写作，后台执行中...",
    'novel_id'       => $novelId,
    'chapter_number' => $nextChapter['chapter_number'],
    'is_catchup'     => false,
    'is_skipped_retry' => $isSkippedRetry,
    'timestamp'      => (new DateTimeImmutable('now', appTimezone()))->format('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);

// FPM 环境：关闭连接，PHP 进程继续在后台运行
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // 非 FPM 环境兜底：flush 后脚本继续
    if (ob_get_level()) ob_end_flush();
    flush();
}

$writeResult  = ['ok' => false, 'msg' => '未执行'];
$fullContent  = '';
$usedModel    = null;
$usage        = null;
$streamStart  = time();
$writePolicySnapshot = null;
$ch           = $nextChapter; // 别名，与 write_chapter.php 保持一致

try {

    // ---- Agent决策系统 ----
    // 审计修复（2026-08-16 #7 / D-2）：原此处内联复制了 WriteEngine::runPreWriteAgents
    // 的决策上下文收集 + runDecisionCycle 逻辑，且丢失了 M-3 的章节级文件锁——
    // daemon 与前台 SSE/worker 跨章并发时 agent_decision_logs 存在重复写入窗口。
    // 现直接复用引擎方法（含文件锁与相同的上下文列集），消除双轨漂移。
    // ---- 记忆 / 写作 / 落盘：复用交互式写作同一条引擎链路 ----
    $preAiClient = null;
    try {
        $preAiClient = getAIClient($novel['model_id'] ? (int)$novel['model_id'] : null);
        if ($preAiClient->is1MContext()) {
            addLog($novelId, 'info', '挂机写作检测到1M上下文模型，将使用完整上下文模式');
            if (defined('CFG_TIME_LONG_1M')) {
                set_time_limit(CFG_TIME_LONG_1M);
            }
        }
    } catch (\Throwable $e) {
        // 忽略，后续模型调用会按 WriteEngine 的 fallback 逻辑重试。
    }

    $targetWords = resolveNovelChapterWords($novel);
    $writePolicySnapshot = WritePolicySnapshotFactory::captureForChapter(
        $novelId,
        $targetWords
    );
    WritePolicySnapshotFactory::assertEmergencyEpochCurrent($writePolicySnapshot);
    // 审计修复 P2（2026-09-27）：冻结本任务五关通过线（daemon 为长驻进程，
    // 每个任务开始时以新值覆盖上一任务的冻结值）。
    WriteEngine::freezeQualityGateMinScore(WriteEngine::resolveQualityGateMinScore());
    $chapterFeatureSnapshot = HarnessFeatureSnapshot::captureForNovel($novelId);

    if (ChapterRunService::shouldUseAuthority(
        $writePolicySnapshot,
        $chapterFeatureSnapshot
    )) {
        $authorityWrite = daemonRunAuthorityChapter(
            $novelId,
            $novel,
            $ch,
            $daemonClaimToken,
            $writePolicySnapshot,
            $chapterFeatureSnapshot,
            $lock
        );
        $novel = $authorityWrite['novel'];
        $ch = $authorityWrite['chapter'];
        $fullContent = $authorityWrite['content'];
        $words = $authorityWrite['words'];
        $modelInfo = $authorityWrite['model_info'];
    } else {
        WriteEngine::runPreWriteAgents($novelId, (int)$nextChapter['chapter_number']);

        $memResult = WriteEngine::initMemory($novelId, $ch, $preAiClient);
        $engine    = $memResult['engine'];
        $memoryCtx = $memResult['memoryCtx'];
        $messages  = WriteEngine::buildPrompt(
            $novel,
            $ch,
            $memoryCtx,
            null,
            $writePolicySnapshot,
            null,
            $chapterFeatureSnapshot
        );

        $daemonHeartbeatAt = 0;
        $lockFile = $lock->lockFilePath();
        $result = WriteEngine::streamWrite(
        $messages,
        $targetWords,
        $novelId,
        function(string $token) use ($lockFile) {
            @touch($lockFile);
        },
        function(array $payload) use ($novelId) {
            if (!empty($payload['model'])) {
                addLog($novelId, 'daemon_model', "{$payload['model']} 第" . ($payload['attempt'] ?? 1) . "次尝试");
            } elseif (!empty($payload['model_switch'])) {
                addLog($novelId, 'daemon_model_switch', '切换模型：' . ($payload['next_model'] ?? 'unknown'));
            } elseif (!empty($payload['warning'])) {
                addLog($novelId, 'warn', $payload['warning']);
            } elseif (!empty($payload['reason'])) {
                addLog($novelId, 'daemon_retry_model', $payload['reason']);
            } elseif (!empty($payload['info'])) {
                addLog($novelId, 'info', $payload['info']);
            }
        },
        function() use (&$daemonHeartbeatAt, $lockFile, $ch, $novelId, $daemonClaimToken) {
            $now = time();
            @touch($lockFile);
            if ($now - $daemonHeartbeatAt >= 10) {
                $daemonHeartbeatAt = $now;
                try {
                    DB::query(
                        'UPDATE chapters SET updated_at=GREATEST('
                        . 'NOW(), DATE_ADD(updated_at, INTERVAL 1 SECOND))'
                        . ' WHERE id=? AND novel_id=? AND status="writing" AND claim_token=?',
                        [(int)$ch['id'], $novelId, $daemonClaimToken]
                    );
                } catch (\Throwable $e) { error_log('daemon_write heartbeat update failed: ' . $e->getMessage()); }
            }
        },
        null,
        $novel['model_id'] ? (int)$novel['model_id'] : null,
        (int)$ch['chapter_number'],
        (int)$novel['target_chapters'],
        null,
        static function () use ($writePolicySnapshot): void {
            WritePolicySnapshotFactory::assertEmergencyEpochCurrent($writePolicySnapshot);
        }
        );

        $fullContent      = $result['content'];
        $usedModel        = $result['model'];
        $streamUsage      = $result['usage'] ?? null;
        $streamDurationMs = $result['duration_ms'] ?? null;

        $saveResult = WriteEngine::saveChapter(
        (int)$ch['id'],
        $novelId,
        $fullContent,
        $targetWords,
        $usedModel,
        $ch,
        $streamUsage,
        $streamDurationMs,
        $writePolicySnapshot
        );
        $words     = $saveResult['words'];
        $ch        = $saveResult['chapter'];
        $modelInfo = $saveResult['model_info'];
        $fullContent = (string)$saveResult['accepted_content'];

        $postprocessResult = PostprocessRunner::enqueueAndRun(
        $novelId,
        $ch,
        $fullContent,
        $engine,
        'daemon:' . (gethostname() ?: 'localhost') . ':' . getmypid(),
        $writePolicySnapshot
        );
        if (!in_array((string)($postprocessResult['state'] ?? ''), ['done', 'stale', 'skipped'], true)) {
            addLog($novelId, 'warn', 'Daemon 后处理已进入持久重试队列，任务ID：' . (int)($postprocessResult['job_id'] ?? 0));
        }
    }

    // W-7 修复：Arc 摘要已迁移到 WriteEngine::postProcess() 内部，此处不再单独生成（避免双重生成）。
    // 这样 SSE/async/daemon 三条写作路径都能一致积累 L2 弧段记忆。

    $writeResult = [
        'ok'    => true,
        'msg'   => "第{$ch['chapter_number']}章写作完成（{$words}字）{$modelInfo}",
        'words' => $words,
    ];

} catch (WriteEngineValidationException $e) {
    $errMsg = $e->getMessage();

    // 审计修复（2026-10-31 B3）：本分支原是 daemon_write.php 中唯一的"裸写"——
    // 章节释放虽有 token CAS，但其后的 novels 状态回写不检查 CAS 是否命中、
    // 不加状态守卫、不取 fence。窄窗口内本任务认领被取消/僵尸清理接管后，
    // 陈旧代际的 daemon 仍会把新写者的 novels.status 压成 paused 并关闭挂机开关。
    // 改走与取消分支 / finally 相同的复合收口（WriteEngine::releaseChapterClaim
    // 基于冻结 _base_snapshot 做严格 CAS）：fence + 行锁下释放本任务认领，
    // 仅当命中且小说已无其他 writing 认领时才回写 paused + daemon_write=0；
    // 未命中说明认领已交接给新写者，不触碰小说状态。
    // 注：WriteEngineValidationException 仅从 saveChapter 内部抛出，catch 时
    // $ch 仍为带 _base_snapshot + claim_token 的认领章节数组（saveResult 的
    // 覆盖赋值只发生在成功返回后）。
    $validationReleased = WriteEngine::releaseChapterClaimAndPauseNovel(
        $novelId,
        (int)$ch['id'],
        $ch,
        true
    );
    if ($validationReleased) {
        clearNovelCache($novelId);
        addLog($novelId, 'daemon_validation_blocked',
            "第{$ch['chapter_number']}章 strict P0 阻止落盘，挂机已暂停：" . $errMsg
        );
        $writeResult = [
            'ok'  => false,
            'msg' => "第{$ch['chapter_number']}章 strict P0 阻止落盘，挂机已暂停：" . $errMsg,
        ];
    } else {
        addLog($novelId, 'daemon_validation_blocked',
            "第{$ch['chapter_number']}章 strict P0 阻止落盘（本任务认领已交接，未回写小说状态）：" . $errMsg
        );
        $writeResult = [
            'ok'  => false,
            'msg' => "第{$ch['chapter_number']}章 strict P0 阻止落盘（认领已交接，挂机状态保持）：" . $errMsg,
        ];
    }

} catch (\Throwable $e) {
    // saveChapter 的 Canon 事务可能已经提交，但随后在权威回读、下一章查询或
    // 返回结果前抛错。必须先认证“本次 daemon claim 的 Candidate 已成为 Canon”，
    // 再决定是否累计生成重试；仅凭 status=completed 绝不足以恢复成功。
    $recoveredCanon = daemonRecoverCommittedCanon(
        $novelId,
        $ch,
        $fullContent,
        (string)($daemonClaimToken ?? ''),
        $writePolicySnapshot,
        $engine ?? null
    );
    if ($recoveredCanon !== null) {
        $ch = $recoveredCanon['chapter'];
        $fullContent = $recoveredCanon['content'];
        $words = $recoveredCanon['words'];
        $modelInfo = $usedModel ? "（{$usedModel->modelLabel}）" : '';
        $writeResult = [
            'ok' => true,
            'msg' => "第{$ch['chapter_number']}章正文已提交（{$words}字），已从返回阶段异常中恢复{$modelInfo}",
            'words' => $words,
            'canon_recovered' => true,
        ];
        try {
            addLog(
                $novelId,
                'daemon_canon_recovered',
                "第{$ch['chapter_number']}章 Canon 已提交，daemon 已恢复成功状态并确认后处理任务",
                (int)$ch['id']
            );
        } catch (\Throwable $logError) {
            error_log('daemon Canon recovery success log failed: ' . $logError->getMessage());
        }
    } else {
        // 审计修复（2026-07-19 H-05）：用户取消不是写作失败——
        // 不累计 retry_count、不标 skipped/failed，而是退出挂机并等待用户重新启动。
        // Cancellation is a typed/runtime signal or the shared cancel flag;
        // exception text is presentation only and never drives state changes.
        $isCancel = $e instanceof RunCanceledException
            || $e instanceof WriteEngineCanceledException
            || file_exists(WriteEngine::cancelFlagPath($novelId));

        if ($isCancel) {
            WriteEngine::releaseChapterClaimAndPauseNovel(
                $novelId,
                (int)$ch['id'],
                $ch,
                true
            );
            clearNovelCache($novelId);
            addLog($novelId, 'daemon_cancel', "第{$ch['chapter_number']}章已被用户取消，挂机写作已停止");
            $writeResult = ['ok' => false, 'msg' => '用户取消了写作，挂机已停止', 'canceled' => true];
            // 审计修复（2026-08-02 H2）：原实现取消分支处理后不短路，继续落入下方
            // 通用失败处理 → retry_count+1、超限标 skipped、writeResult 被覆盖，
            // 'canceled' 标志丢失，与 H-05 的修复声明直接矛盾。此处改为跳过失败处理；
            // 落入 finally 释放锁，再由收尾块固化小说状态。
        } else {
            // 写作彻底失败 → 根据重试策略决定重试或跳过
            $retryRow = DB::fetch("SELECT retry_count FROM chapters WHERE id=?", [$ch['id']]);
            $retryCount = (int)($retryRow['retry_count'] ?? 0) + 1;

            $shouldSkip = (DAEMON_RETRY_MODE === 'skip') && ($retryCount > DAEMON_MAX_RETRIES);

            // 审计修复 H-3（2026-06-17）：脱敏 AI 错误后再写入日志/返回结果，
            // 防止 API key、URL、provider 内部 JSON 泄露给日志阅读者或前端。
            $safeErr = sanitizeAiErrorMessage($e->getMessage());

            if ($shouldSkip) {
                DB::update('chapters',
                    ['status' => 'skipped', 'retry_count' => $retryCount, 'claim_token' => ''],
                    'id=? AND status="writing" AND claim_token=?'
                        . ' AND COALESCE(canonical_state,"none")<>"canonical"',
                    [$ch['id'], (string)($ch['claim_token'] ?? '')]
                );
                addLog($novelId, 'daemon_skip',
                    "第{$ch['chapter_number']}章超过最大重试次数（" . DAEMON_MAX_RETRIES . "次），标记跳过：" . $safeErr
                );
                $writeResult = ['ok' => false, 'msg' => "第{$ch['chapter_number']}章已跳过（失败{$retryCount}次）：" . $safeErr];
            } else {
                DB::update('chapters',
                    ['status' => 'outlined', 'retry_count' => $retryCount, 'claim_token' => ''],
                    'id=? AND status="writing" AND claim_token=?'
                        . ' AND COALESCE(canonical_state,"none")<>"canonical"',
                    [$ch['id'], (string)($ch['claim_token'] ?? '')]
                );
                $modeHint = DAEMON_RETRY_MODE === 'forever' ? '永久重试模式' : ('将重试，第' . $retryCount . '/' . DAEMON_MAX_RETRIES . '次');
                addLog($novelId, 'daemon_error',
                    "第{$ch['chapter_number']}章出错（{$modeHint}）：" . $safeErr
                );
                $writeResult = ['ok' => false, 'msg' => "第{$ch['chapter_number']}章出错（{$modeHint}）：" . $safeErr];
            }
        }
    }
} finally {
    // 普通异常路径的确定性恢复。仅把仍处于 writing 的本次认领章节退回 outlined，
    // 已由 saveChapter 完成的正文不会被覆盖；锁在所有路径中只释放一次。
    if ($lock->claimedChapterId > 0) {
        try {
            // 审计 P0：按本任务令牌限定恢复，防误释放他人重新认领的同章
            $restored = WriteEngine::releaseChapterClaimAndPauseNovel(
                $novelId,
                $lock->claimedChapterId,
                $nextChapter
            );
            if ($restored) {
                WriteEngine::clearPostProcessPending($novelId, $lock->claimedChapterNumber);
                clearNovelCache($novelId);
                addLog($novelId, 'daemon_recover', "挂机任务提前结束，已恢复第{$lock->claimedChapterNumber}章为待写状态");
            }
        } catch (\Throwable $recoveryError) {
            error_log('daemon_write finally recovery failed: ' . $recoveryError->getMessage());
        }
    }

    // Keep the daemon lock until all shared-state publication has completed.
    // The novel fence inside finalization also coordinates manual/SSE writers.
    try {
        $finalState = daemonFinalizeNovelState($novelId, !empty($writeResult['ok']));
        $remainingWritable = $finalState['writable'];
        $manualBackfill = $finalState['manual'];
        $unfinished = $finalState['unfinished'];
        $lock->stateFinalized = true;
        if ($finalState['action'] !== 'superseded') {
            clearNovelCache($novelId);
        }
        if ($finalState['action'] === 'completed') {
            addLog($novelId, 'daemon_all_done', '挂机写作：全部章节完成，已自动关闭挂机');
        } elseif ($finalState['action'] === 'blocked') {
            addLog($novelId, 'daemon_blocked', "仍有{$unfinished}章未完成、{$manualBackfill}章需人工补写，没有可安全自动写作章节，挂机已暂停");
        }
    } finally {
        $lock->release();
    }
}

if ($finalState['action'] === 'superseded') {
    // Counts were deliberately not computed from a successor's active state.
    addLog($novelId, 'daemon_result', ($writeResult['msg'] ?? '') . ' | 写作状态已交接，跳过旧任务收尾');
    exit;
}

// 响应已在写作开始前发出，此处只记录最终结果日志
addLog($novelId, 'daemon_result', implode(' | ', array_filter([
    $writeResult['msg'] ?? '',
    isset($writeResult['words']) ? "字数:{$writeResult['words']}" : '',
    "剩余可写:{$remainingWritable}章",
    "需人工补写:{$manualBackfill}章",
    "未完成:{$unfinished}章",
])));
