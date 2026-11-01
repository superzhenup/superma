<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ChapterRewriteTaskStore.php';
require_once dirname(__DIR__) . '/ChapterMutationService.php';

final class ChapterRewriteConflict extends RuntimeException {}

/** Explicit adoption of a server-held candidate, guarded by the original revision. */
final class ChapterRewriteAdoptionService
{
    private Closure $read;
    private Closure $commit;

    public function __construct(?callable $read = null, ?callable $commit = null)
    {
        $this->read = Closure::fromCallable($read ?? static function (int $userId, int $chapterId): ?array {
            return DB::fetch('SELECT c.*, n.user_id AS owner_id FROM chapters c'
                . ' INNER JOIN novels n ON n.id=c.novel_id WHERE c.id=? AND n.user_id=?', [$chapterId, $userId]);
        });
        $this->commit = Closure::fromCallable($commit ?? [self::class, 'commitCandidate']);
    }

    /** Read only recovery: a lost HTTP reply never causes another rewrite/model call. */
    public function status(ChapterRewriteTaskStore $store, int $userId, int $chapterId, ?string $taskId = null): ?array
    {
        $task = $store->snapshot($userId, $chapterId, $taskId, true);
        if (($task['state'] ?? null) !== 'adopted'
            && (($task['state'] ?? null) !== 'adopting' || ($task['active'] ?? null) !== false)) return $task;
        $chapter = ($this->read)($userId, $chapterId);
        if (!$chapter) return $task;
        $hash = hash('sha256', (string)$chapter['content']);
        $task['current_hash'] = $hash;
        if ($task['state'] === 'adopted') return $task;
        if (hash_equals((string)$task['candidate_hash'], $hash)) {
            $task['state'] = 'adopted'; $task['stage'] = 'adopted';
            $task['message'] = '改写稿已采纳';
            $task['recovery_required'] = true;
        } else {
            $task['state'] = 'ready'; $task['stage'] = 'ready';
            $task['message'] = hash_equals((string)$task['source_hash'], $hash)
                ? '上次采纳未完成，候选稿仍保留，可以重试采纳'
                : '正文已被其他操作修改，候选稿仍保留，请刷新后重新改写';
        }
        return $task;
    }

    /** Caller explicitly starts a successor; settle only an idle prior adoption. */
    public function reconcileBeforeStart(ChapterRewriteTaskStore $store, int $userId, int $chapterId): ?string
    {
        $previous = $store->snapshot($userId, $chapterId, null, false);
        if (($previous['state'] ?? null) !== 'adopting' || ($previous['active'] ?? null) !== false) return null;
        $task = $store->lockReady($userId, $chapterId, $previous['task_id']);
        if ($task === null) return null;
        try {
            $chapter = ($this->read)($userId, $chapterId);
            if (!$chapter || (int)$chapter['novel_id'] !== $task['novel_id']) throw new ChapterRewriteConflict('章节不存在或归属已变化');
            if (hash_equals($task['candidate_hash'], hash('sha256', (string)$chapter['content']))) {
                $store->markAdopted();
                return $task['task_id'];
            } else {
                // No old worker owns the lock; retain the exact candidate until
                // this explicit new start successfully publishes its own task.
                $store->complete($task['content'], $task['words'], $task['quality_before'] ?? null, $task['quality_after'] ?? null);
            }
            return null;
        } finally { $store->close(); }
    }

    public function adopt(ChapterRewriteTaskStore $store, int $userId, int $chapterId, string $taskId): array
    {
        $task = $store->lockReady($userId, $chapterId, $taskId);
        if ($task === null) throw new ChapterRewriteConflict('候选稿仍在处理、已被更新或不存在，请先刷新任务状态');
        try {
            $chapter = ($this->read)($userId, $chapterId);
            if (!$chapter || (int)$chapter['novel_id'] !== $task['novel_id']) {
                throw new ChapterRewriteConflict('章节不存在或归属已变化，未覆盖正文');
            }
            $currentHash = hash('sha256', (string)$chapter['content']);
            if (($chapter['status'] ?? '') === 'writing' && $task['state'] !== 'adopted') {
                throw new ChapterRewriteConflict('章节正在写作中，未覆盖正文；候选稿仍保留');
            }
            if (hash_equals($task['candidate_hash'], $currentHash)) {
                // Handles both an unchanged proposal and DB commit followed by
                // a lost filesystem receipt. Do not back up or mutate twice.
                if ($task['state'] !== 'adopted') $store->markAdopting();
                $store->markAdopted();
            } else {
                if ($task['state'] === 'adopted' || !hash_equals($task['source_hash'], $currentHash)
                    || ($chapter['status'] ?? '') === 'writing') {
                    throw new ChapterRewriteConflict('正文或写作状态已变化，候选稿仍保留；请刷新后重新改写');
                }
                $store->markAdopting();
                // A crash after this point is reconciled from the DB hash, not
                // from a stale page or a client-supplied body.
                ($this->commit)($task);
                $store->markAdopted();
            }
            // Freeze the receipt before releasing admission to another tab.
            $receipt = $store->snapshot($userId, $chapterId, $taskId, true)
                ?? throw new RuntimeException('Adopted task receipt unavailable.');
            $receipt['active'] = false;
            $receipt['current_hash'] = $task['candidate_hash'];
            return $receipt;
        } finally { $store->close(); }
    }

    private static function commitCandidate(array $task): void
    {
        // Never perform AI or memory generation while serving adoption. Keep
        // the existing projection-fence -> transaction -> row-lock order.
        // 审计修复 H2（2026-09-27）：栅栏超时与正典提交路径对齐。原 5 秒远小于
        // ChapterCanonTransition 持有窗口（30 秒）——正典事务在 30 秒内做模型
        // 后处理时，用户采纳改写稿会在 5 秒后直接失败。统一为 30 秒同口径。
        $fence = NovelProjectionFence::acquire($task['novel_id'], 30);
        $pdo = DB::getPdo();
        try {
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) throw new RuntimeException('Cannot start adoption transaction.');
            try {
                $novel = DB::fetch('SELECT id, user_id FROM novels WHERE id=? FOR UPDATE', [$task['novel_id']]);
                if (!$novel || (int)$novel['user_id'] !== $task['user_id']) {
                    throw new ChapterRewriteConflict('小说归属已变化，未覆盖正文');
                }
                ChapterMutationService::mutateChapter($task['chapter_id'], $task['novel_id'], [
                    'content' => $task['content'], 'words' => $task['words'],
                ], [
                    'backup_version' => true, 'strict_backup' => true, 'prevent_writing' => true,
                    'expected_content_hash' => $task['source_hash'], 'reason' => 'quality_rewrite_adopt',
                ]);
                $quality = $task['quality_after'] ?? null;
                if (is_array($quality) && isset($quality['total_score'], $quality['gates'])) {
                    ChapterMutationService::mutateChapter($task['chapter_id'], $task['novel_id'], [
                        'quality_score' => $quality['total_score'],
                        'gate_results' => json_encode($quality['gates'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'rewritten' => 1, 'rewrite_time' => date('Y-m-d H:i:s'),
                    ], ['backup_version' => false, 'prevent_writing' => true,
                        'expected_content_hash' => $task['candidate_hash'], 'reason' => 'quality_rewrite_metadata']);
                } else {
                    // 审计修复 H1（2026-09-27）：复检结果缺失（生成器复检异常被吞 /
                    // ws_quality_check_enabled=false）时，原实现保留旧正文的
                    // quality_score/gate_results 且 rewritten 标志不更新——正文已
                    // 替换但质量元数据仍描述旧稿。改写事实必须记录（rewritten/
                    // rewrite_time 与复检无关），旧稿五关分数不得挂在新正文上：
                    // 置空等待完整五关重新计算，与正典链重写路径同口径
                    // （write_engine postProcess：gate_results 置 null 等待重算）。
                    ChapterMutationService::mutateChapter($task['chapter_id'], $task['novel_id'], [
                        'rewritten' => 1, 'rewrite_time' => date('Y-m-d H:i:s'),
                    ], ['backup_version' => false, 'prevent_writing' => true,
                        'expected_content_hash' => $task['candidate_hash'], 'reason' => 'quality_rewrite_metadata']);
                    DB::update(
                        'chapters',
                        ['quality_score' => null, 'gate_results' => null],
                        'id=? AND novel_id=? AND content_revision_hash=?',
                        [$task['chapter_id'], $task['novel_id'], $task['candidate_hash']]
                    );
                }
                updateNovelStats($task['novel_id']);
                if (!$pdo->commit()) throw new RuntimeException('Cannot commit adopted rewrite.');
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        } finally { NovelProjectionFence::release($fence); }
    }
}
