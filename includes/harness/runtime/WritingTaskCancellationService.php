<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/write_engine.php';
require_once dirname(__DIR__, 2) . '/ChapterMutationService.php';
require_once dirname(__DIR__, 2) . '/NovelProjectionFence.php';
require_once dirname(__DIR__, 2) . '/tasks/WritingTaskRepository.php';
require_once dirname(__DIR__) . '/persistence/GenerationRunRepository.php';

enum WritingTaskCancellationAction: string
{
    case CANCEL = 'cancel';
    case RESET = 'reset';
    case RESET_CHAPTER = 'reset_chapter';
}

enum WritingTaskCancellationFlagMutation: string
{
    case NONE = 'none';
    case WRITE = 'write';
    case UNLINK = 'unlink';
}

final readonly class WritingTaskCancellationRequest
{
    public function __construct(
        public int $userId,
        public int $novelId,
        public WritingTaskCancellationAction $action,
        public ?int $chapterId = null
    ) {
        if ($this->userId <= 0) {
            throw new InvalidArgumentException('缺少有效用户 ID');
        }
        if ($this->novelId <= 0) {
            throw new InvalidArgumentException('缺少小说 ID');
        }
        if ($this->action === WritingTaskCancellationAction::RESET_CHAPTER
            && ($this->chapterId ?? 0) <= 0) {
            throw new InvalidArgumentException('重置单个章节需要提供 chapter_id');
        }
        if ($this->chapterId !== null && $this->chapterId <= 0) {
            throw new InvalidArgumentException('章节 ID 必须为正整数');
        }
    }
}

final readonly class WritingTaskCancellationOutcome
{
    public function __construct(
        public WritingTaskCancellationAction $action,
        public string $message,
        public WritingTaskCancellationFlagMutation $flagMutation
    ) {
        if ($this->message === '') {
            throw new InvalidArgumentException('Cancellation outcome message cannot be empty.');
        }
    }
}

final readonly class WritingTaskCancellationClaim
{
    public function __construct(
        public int $chapterId,
        public string $claimToken,
        public string $gateResultsJson
    ) {
        if ($this->chapterId <= 0) {
            throw new InvalidArgumentException('Cancellation claim requires a positive chapter id.');
        }
    }

    public function hasValidFenceToken(): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $this->claimToken) === 1;
    }

    public function hasDurableHardFailure(): bool
    {
        $gateRows = json_decode($this->gateResultsJson, true);
        if (!is_array($gateRows)) {
            return false;
        }
        foreach ($gateRows as $gateRow) {
            if (is_array($gateRow) && !empty($gateRow['hard_fail_reason'])) {
                return true;
            }
        }
        return false;
    }
}

final readonly class WritingTaskCancellationBaseStatus
{
    private const RESTORABLE = ['outlined', 'skipped', 'failed'];

    private function __construct(
        public bool $taskSnapshotExists,
        public ?string $authenticatedStatus
    ) {
        if (!$this->taskSnapshotExists && $this->authenticatedStatus !== null) {
            throw new InvalidArgumentException('A missing task snapshot cannot have a base status.');
        }
        if ($this->authenticatedStatus !== null
            && !in_array($this->authenticatedStatus, self::RESTORABLE, true)) {
            throw new InvalidArgumentException('Task base status is not restorable.');
        }
    }

    public static function missing(): self
    {
        return new self(false, null);
    }

    public static function taskSnapshot(?string $authenticatedStatus): self
    {
        return new self(true, $authenticatedStatus);
    }
}

interface WritingTaskCancellationStorePort
{
    public function assertNovelOwned(int $novelId, int $userId): void;

    public function beginTransaction(): void;

    public function inTransaction(): bool;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Locks every chapter first and the owned novel second.
     *
     * @return array{id:int,status:string}
     */
    public function lockOwnedNovelScope(int $novelId, int $userId): array;

    public function requestCancel(int $novelId): void;

    /**
     * 权威 DB 取消通道（审计修复 S-1，2026-09-27）：
     * 为该小说全部活跃的 chapter authority Run 设置 generation_runs.cancel_requested。
     * running（租约有效）的 Run 由 M62 取消探针在下一个控制点安全中止；
     * retry_wait/queued 的 Run 被重新拉起后由 WorkflowRunner 的 preflight
     * 落 CANCELED 终态。此前该通道仅 outline 在用，chapter authority 取消
     * 完全依赖文件 flag（写失败被吞时正在执行的 Run 感知不到取消）。
     */
    public function requestAuthorityRunCancel(int $novelId): void;

    public function markNovelCancelRequested(int $novelId, int $userId): void;

    public function normalizeCanonicalClaims(int $novelId): void;

    /** @return list<WritingTaskCancellationClaim> */
    public function nonCanonicalWritingClaims(int $novelId): array;

    public function taskBaseStatus(
        int $novelId,
        int $chapterId,
        string $claimToken
    ): WritingTaskCancellationBaseStatus;

    public function releaseNonCanonicalClaim(
        int $chapterId,
        string $claimToken,
        string $restoreStatus
    ): bool;

    public function pauseNovelAfterCancel(int $novelId, int $userId): void;

    /** @return list<int> */
    public function unfinishedContentChapterIds(int $novelId): array;

    public function resetPartialChapter(int $chapterId, int $novelId): void;

    public function resetAllUnfinishedChapters(int $novelId): void;

    public function pauseNovelAfterReset(int $novelId, int $userId): void;

    /** @return null|array{id:int,status:string} */
    public function lockChapter(int $chapterId, int $novelId): ?array;

    public function pauseAndCancelNovelForChapter(int $novelId, int $userId): void;

    public function resetChapter(int $chapterId, int $novelId): void;

    public function updateNovelStats(int $novelId): void;
}

interface WritingTaskCancellationFencePort
{
    public function acquire(int $novelId, int $timeoutSeconds): string;

    public function release(string $token): void;
}

interface WritingTaskCancellationFlagPort
{
    public function apply(
        WritingTaskCancellationFlagMutation $mutation,
        int $novelId
    ): void;
}

final class DbWritingTaskCancellationStore implements WritingTaskCancellationStorePort
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function assertNovelOwned(int $novelId, int $userId): void
    {
        $novel = DB::fetch(
            'SELECT id FROM novels WHERE id=? AND user_id=?',
            [$novelId, $userId]
        );
        if (!is_array($novel)) {
            throw new RuntimeException('小说不存在');
        }
    }

    public function beginTransaction(): void
    {
        $pdo = $this->connection();
        if ($pdo->inTransaction()) {
            throw new LogicException('Cancellation service cannot join a caller-owned transaction.');
        }
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Unable to begin cancellation transaction.');
        }
    }

    public function inTransaction(): bool
    {
        return $this->connection()->inTransaction();
    }

    public function commit(): void
    {
        if (!$this->connection()->commit()) {
            throw new RuntimeException('Unable to commit cancellation transaction.');
        }
    }

    public function rollBack(): void
    {
        if (!$this->connection()->rollBack()) {
            throw new RuntimeException('Unable to roll back cancellation transaction.');
        }
    }

    public function lockOwnedNovelScope(int $novelId, int $userId): array
    {
        DB::fetchAll(
            'SELECT id FROM chapters WHERE novel_id=? ORDER BY id FOR UPDATE',
            [$novelId]
        );
        $lockedNovel = DB::fetch(
            'SELECT id, status FROM novels WHERE id=? AND user_id=? FOR UPDATE',
            [$novelId, $userId]
        );
        if (!is_array($lockedNovel)) {
            throw new RuntimeException('小说不存在');
        }
        return [
            'id' => (int)$lockedNovel['id'],
            'status' => (string)($lockedNovel['status'] ?? ''),
        ];
    }

    public function requestCancel(int $novelId): void
    {
        WritingTaskRepository::requestCancel($novelId);
    }

    public function requestAuthorityRunCancel(int $novelId): void
    {
        $rows = DB::fetchAll(
            "SELECT run_uid FROM generation_runs
             WHERE novel_id = ?
               AND run_type = 'chapter.generate'
               AND authority_mode = 'authority'
               AND cancel_requested = 0
               AND state IN ('queued','running','retry_wait','waiting_approval','quarantined')
             ORDER BY id
             FOR UPDATE",
            [$novelId]
        );
        $runs = new GenerationRunRepository($this->connection());
        foreach ($rows as $row) {
            $runUid = (string)($row['run_uid'] ?? '');
            if ($runUid === '') {
                throw new RuntimeException('Authority Run is missing its durable UID.');
            }
            $runs->requestCancelWithEvents($runUid);
        }
    }

    public function markNovelCancelRequested(int $novelId, int $userId): void
    {
        DB::query(
            'UPDATE novels SET cancel_flag = 1 WHERE id = ? AND user_id = ?',
            [$novelId, $userId]
        );
    }

    public function normalizeCanonicalClaims(int $novelId): void
    {
        // A rewrite claim wraps an already accepted Canon. Cancellation/reset
        // may clear the transient claim, but must never outline or erase it.
        DB::query(
            'UPDATE chapters SET status = "completed", claim_token = ""
             WHERE novel_id = ? AND status = "writing"
               AND canonical_state = "canonical"',
            [$novelId]
        );
    }

    public function nonCanonicalWritingClaims(int $novelId): array
    {
        $rows = DB::fetchAll(
            'SELECT id, claim_token, gate_results FROM chapters
             WHERE novel_id=? AND status="writing"
               AND COALESCE(canonical_state,"none")<>"canonical"',
            [$novelId]
        );
        $claims = [];
        foreach ($rows as $row) {
            $claims[] = new WritingTaskCancellationClaim(
                (int)$row['id'],
                (string)($row['claim_token'] ?? ''),
                (string)($row['gate_results'] ?? '')
            );
        }
        return $claims;
    }

    public function taskBaseStatus(
        int $novelId,
        int $chapterId,
        string $claimToken
    ): WritingTaskCancellationBaseStatus {
        $taskSnapshotRow = DB::fetch(
            'SELECT base_chapter_snapshot_json FROM writing_tasks
             WHERE novel_id=? AND chapter_id=? AND base_chapter_snapshot_json IS NOT NULL
             ORDER BY id DESC LIMIT 1',
            [$novelId, $chapterId]
        );
        if (!is_array($taskSnapshotRow)) {
            return WritingTaskCancellationBaseStatus::missing();
        }

        try {
            $baseSnapshot = WritingTaskRepository::baseChapterSnapshotFromRow(
                $taskSnapshotRow
            );
            $authenticatedBase = WriteEngine::validateChapterBaseSnapshot(
                $baseSnapshot,
                $claimToken
            );
            $status = (string)($authenticatedBase['status'] ?? '');
            return WritingTaskCancellationBaseStatus::taskSnapshot(
                in_array($status, ['outlined', 'skipped', 'failed'], true)
                    ? $status
                    : null
            );
        } catch (Throwable $snapshotError) {
            error_log(
                'cancel_write base snapshot authentication failed for chapter '
                . $chapterId . ': ' . $snapshotError->getMessage()
            );
            return WritingTaskCancellationBaseStatus::taskSnapshot(null);
        }
    }

    public function releaseNonCanonicalClaim(
        int $chapterId,
        string $claimToken,
        string $restoreStatus
    ): bool {
        if (!in_array($restoreStatus, ['outlined', 'skipped', 'failed'], true)) {
            throw new InvalidArgumentException('Cancellation restore status is invalid.');
        }
        return DB::update(
            'chapters',
            ['status' => $restoreStatus, 'claim_token' => ''],
            'id=? AND status="writing" AND claim_token=?'
                . ' AND COALESCE(canonical_state,"none")<>"canonical"',
            [$chapterId, $claimToken]
        ) === 1;
    }

    public function pauseNovelAfterCancel(int $novelId, int $userId): void
    {
        DB::query(
            'UPDATE novels SET status = "paused", daemon_write = 0
             WHERE id = ? AND user_id = ?',
            [$novelId, $userId]
        );
    }

    public function unfinishedContentChapterIds(int $novelId): array
    {
        $rows = DB::fetchAll(
            'SELECT id FROM chapters
             WHERE novel_id=? AND status!="completed" AND content IS NOT NULL AND content<>""',
            [$novelId]
        );
        return array_map(static fn(array $row): int => (int)$row['id'], $rows);
    }

    public function resetPartialChapter(int $chapterId, int $novelId): void
    {
        ChapterMutationService::mutateChapter($chapterId, $novelId, [
            'content' => '',
            'words' => 0,
            'status' => 'outlined',
        ], [
            'backup_version' => true,
            'prevent_writing' => false,
            'force_content_invalidation' => true,
            'reason' => 'cancel_reset_partial',
        ]);
    }

    public function resetAllUnfinishedChapters(int $novelId): void
    {
        DB::query(
            'UPDATE chapters SET content = "", words = 0, status = "outlined", claim_token = "",
                    canonical_state = "none", content_revision_hash = NULL,
                    canonicalized_at = NULL, write_policy_snapshot_json = NULL,
                    canonical_candidate_uid = NULL
             WHERE novel_id = ? AND status != "completed"',
            [$novelId]
        );
    }

    public function pauseNovelAfterReset(int $novelId, int $userId): void
    {
        DB::query(
            'UPDATE novels SET status = "paused", cancel_flag = 0
             WHERE id = ? AND user_id = ? AND status IN ("writing", "draft", "paused")',
            [$novelId, $userId]
        );
    }

    public function lockChapter(int $chapterId, int $novelId): ?array
    {
        $chapter = DB::fetch(
            'SELECT id, status FROM chapters WHERE id = ? AND novel_id = ? FOR UPDATE',
            [$chapterId, $novelId]
        );
        if (!is_array($chapter)) {
            return null;
        }
        return [
            'id' => (int)$chapter['id'],
            'status' => (string)($chapter['status'] ?? ''),
        ];
    }

    public function pauseAndCancelNovelForChapter(int $novelId, int $userId): void
    {
        DB::query(
            'UPDATE novels SET cancel_flag = 1, status = "paused", daemon_write = 0
             WHERE id = ? AND user_id = ?',
            [$novelId, $userId]
        );
    }

    public function resetChapter(int $chapterId, int $novelId): void
    {
        ChapterMutationService::mutateChapter($chapterId, $novelId, [
            'content' => '',
            'words' => 0,
            'status' => 'outlined',
            'claim_token' => '',
        ], [
            'backup_version' => true,
            'prevent_writing' => false,
            'force_content_invalidation' => true,
            'reason' => 'cancel_reset_chapter',
        ]);
    }

    public function updateNovelStats(int $novelId): void
    {
        if (function_exists('updateNovelStats')) {
            updateNovelStats($novelId);
        }
    }

    private function connection(): PDO
    {
        $connection = DB::getPdo();
        if ($this->pdo !== null && $this->pdo !== $connection) {
            throw new LogicException(
                'Cancellation store PDO must be the connection owned by DB.'
            );
        }
        return $connection;
    }
}

final class NovelProjectionWritingTaskCancellationFence
    implements WritingTaskCancellationFencePort
{
    public function acquire(int $novelId, int $timeoutSeconds): string
    {
        return NovelProjectionFence::acquire($novelId, $timeoutSeconds);
    }

    public function release(string $token): void
    {
        NovelProjectionFence::release($token);
    }
}

final class FilesystemWritingTaskCancellationFlagPort
    implements WritingTaskCancellationFlagPort
{
    public function apply(
        WritingTaskCancellationFlagMutation $mutation,
        int $novelId
    ): void {
        if ($mutation === WritingTaskCancellationFlagMutation::NONE) {
            return;
        }
        if ($mutation === WritingTaskCancellationFlagMutation::WRITE) {
            if (!defined('CFG_PROGRESS_DIR')) {
                throw new RuntimeException('CFG_PROGRESS_DIR is unavailable.');
            }
            @mkdir(CFG_PROGRESS_DIR, 0755, true);
            if (@file_put_contents(
                WriteEngine::cancelFlagPath($novelId),
                (string)time(),
                LOCK_EX
            ) === false) {
                throw new RuntimeException('Cancel acceleration flag write failed.');
            }
            return;
        }
        @unlink(WriteEngine::cancelFlagPath($novelId));
    }
}

final class WritingTaskCancellationService
{
    private WritingTaskCancellationStorePort $store;
    private WritingTaskCancellationFencePort $fence;
    private WritingTaskCancellationFlagPort $flags;

    public function __construct(
        ?WritingTaskCancellationStorePort $store = null,
        ?WritingTaskCancellationFencePort $fence = null,
        ?WritingTaskCancellationFlagPort $flags = null
    ) {
        $this->store = $store ?? new DbWritingTaskCancellationStore();
        $this->fence = $fence ?? new NovelProjectionWritingTaskCancellationFence();
        $this->flags = $flags ?? new FilesystemWritingTaskCancellationFlagPort();
    }

    public function execute(WritingTaskCancellationRequest $request): WritingTaskCancellationOutcome
    {
        // Reject a foreign/deleted novel before acquiring its named lock. The
        // same owner is authenticated again under the novel row lock below.
        $this->store->assertNovelOwned($request->novelId, $request->userId);

        $novelId = $request->novelId;
        $projectionFence = $this->fence->acquire($novelId, 30);
        try {
            $outcome = $this->executeTransaction($request);
            try {
                // Filesystem flags accelerate polling only. They are never
                // mutated until the authoritative database commit is durable.
                // 审计修复 S-1（2026-09-27）后 DB 是唯一权威取消通道
                // （writing_tasks.cancel_requested + generation_runs.cancel_requested），
                // flag 写失败仅损失 legacy 流式路径的加速感知，不再使取消失效。
                $this->flags->apply($outcome->flagMutation, $request->novelId);
            } catch (Throwable $flagError) {
                error_log(
                    'cancel_write: durable cancellation committed but flag mutation failed for novel '
                    . $request->novelId . ': ' . $flagError->getMessage()
                );
            }
            return $outcome;
        } finally {
            try {
                $this->fence->release($projectionFence);
            } catch (Throwable $releaseError) {
                error_log(
                    'cancel_write projection fence release failed: '
                    . $releaseError->getMessage()
                );
            }
        }
    }

    private function executeTransaction(
        WritingTaskCancellationRequest $request
    ): WritingTaskCancellationOutcome {
        $this->store->beginTransaction();
        try {
            // Global order: all chapter rows -> owned novel -> task rows.
            // Writers use the same order, preventing chapter/novel/task cycles.
            $this->store->lockOwnedNovelScope($request->novelId, $request->userId);
            $outcome = match ($request->action) {
                WritingTaskCancellationAction::CANCEL => $this->cancel($request),
                WritingTaskCancellationAction::RESET => $this->reset($request),
                WritingTaskCancellationAction::RESET_CHAPTER => $this->resetChapter($request),
            };
            $this->store->commit();
            return $outcome;
        } catch (Throwable $error) {
            if ($this->store->inTransaction()) {
                $this->store->rollBack();
            }
            throw $error;
        }
    }

    private function cancel(
        WritingTaskCancellationRequest $request
    ): WritingTaskCancellationOutcome {
        $novelId = $request->novelId;
        // This call covers legacy off, shadow and authority writing tasks; the
        // repository keeps each task's own lease/fence checks authoritative.
        $this->store->requestCancel($novelId);
        // 审计修复 S-1（2026-09-27）：chapter authority Run 的取消通道从文件
        // flag 接线到权威 DB。文件 flag 写失败（目录不可写/磁盘满）时取消
        // 不再失效：正在跑的 Run 由 M62 探针发现 cancel_requested 安全中止，
        // retry_wait 的 Run 重拉后由 preflight 落 CANCELED（而非误报
        // stale_revision FAILED 白耗 claim/重试次数）。
        $this->store->requestAuthorityRunCancel($novelId);
        $this->store->markNovelCancelRequested($novelId, $request->userId);
        $this->store->normalizeCanonicalClaims($novelId);

        foreach ($this->store->nonCanonicalWritingClaims($novelId) as $claim) {
            if (!$claim->hasValidFenceToken()) {
                error_log(
                    'cancel_write refused an unfenced chapter claim: chapter='
                    . $claim->chapterId
                );
                continue;
            }
            $baseStatus = $this->store->taskBaseStatus(
                $novelId,
                $claim->chapterId,
                $claim->claimToken
            );
            if ($baseStatus->taskSnapshotExists) {
                // A present but unauthenticated task snapshot fails closed.
                $restoreStatus = $baseStatus->authenticatedStatus ?? 'failed';
            } else {
                // SSE/daemon claims have no writing_tasks base snapshot.
                $restoreStatus = $claim->hasDurableHardFailure()
                    ? 'failed'
                    : 'outlined';
            }
            $this->store->releaseNonCanonicalClaim(
                $claim->chapterId,
                $claim->claimToken,
                $restoreStatus
            );
        }

        $this->store->pauseNovelAfterCancel($novelId, $request->userId);
        return new WritingTaskCancellationOutcome(
            $request->action,
            '已取消写作',
            WritingTaskCancellationFlagMutation::WRITE
        );
    }

    private function reset(
        WritingTaskCancellationRequest $request
    ): WritingTaskCancellationOutcome {
        $novelId = $request->novelId;
        $this->store->normalizeCanonicalClaims($novelId);
        foreach ($this->store->unfinishedContentChapterIds($novelId) as $chapterId) {
            $this->store->resetPartialChapter($chapterId, $novelId);
        }
        $this->store->resetAllUnfinishedChapters($novelId);
        $this->store->pauseNovelAfterReset($novelId, $request->userId);

        return new WritingTaskCancellationOutcome(
            $request->action,
            '已重置所有未完成章节',
            WritingTaskCancellationFlagMutation::UNLINK
        );
    }

    private function resetChapter(
        WritingTaskCancellationRequest $request
    ): WritingTaskCancellationOutcome {
        $chapterId = $request->chapterId;
        if ($chapterId === null) {
            throw new LogicException('Validated reset request lost its chapter id.');
        }
        $chapter = $this->store->lockChapter($chapterId, $request->novelId);
        if ($chapter === null) {
            throw new RuntimeException('章节不存在');
        }

        $resettingWriting = $chapter['status'] === 'writing';
        if ($resettingWriting) {
            $this->store->requestCancel($request->novelId);
            // 审计修复 S-1（2026-09-27）：同 cancel()——重置写作中的章节同样
            // 需要权威 DB 通道命中 chapter authority Run。
            $this->store->requestAuthorityRunCancel($request->novelId);
            $this->store->pauseAndCancelNovelForChapter(
                $request->novelId,
                $request->userId
            );
        }
        $this->store->resetChapter($chapterId, $request->novelId);
        $this->store->updateNovelStats($request->novelId);

        return new WritingTaskCancellationOutcome(
            $request->action,
            $resettingWriting ? '已取消写作并重置章节' : '已重置章节内容',
            $resettingWriting
                ? WritingTaskCancellationFlagMutation::WRITE
                : WritingTaskCancellationFlagMutation::UNLINK
        );
    }
}
