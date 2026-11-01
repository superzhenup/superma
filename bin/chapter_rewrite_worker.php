<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardWorker();

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI mode only'); }
if (count($argv) !== 4 || filter_var($argv[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
    || preg_match('/^[a-f0-9]{32}$/D', $argv[2]) !== 1 || !in_array($argv[3], ['generate', 'finalize'], true)) {
    fwrite(STDERR, "Usage: php bin/chapter_rewrite_worker.php <chapterId> <taskId> <generate|finalize>\n"); exit(2);
}
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/quality/ChapterRewriteTaskStore.php';
$chapterId = (int)$argv[1]; $taskId = $argv[2]; $phase = $argv[3];
$store = new ChapterRewriteTaskStore();
$owned = false; $complete = false;
register_shutdown_function(static function () use ($store, &$owned, &$complete): void {
    if ($owned && !$complete) {
        try { $store->fail('worker_interrupted', '后台改写进程异常退出，原文未变，可重新生成候选稿'); } catch (Throwable) {}
    }
    $store->close();
});
try {
    if ($phase === 'generate') {
        $task = $store->claim($chapterId, $taskId);
        if ($task === null) exit(0);
        $owned = true;
    }
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    // No session, forged login or user argument: both branches validate a
    // server-recorded exact task before making any model or mutation call.
    $chapter = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
    if (!$chapter) throw new RuntimeException('Chapter no longer exists.');
    $novel = DB::fetch('SELECT * FROM novels WHERE id=?', [(int)$chapter['novel_id']]);
    if (!$novel || (int)($novel['user_id'] ?? 0) < 1) throw new RuntimeException('Novel owner unavailable.');
    if ($phase === 'finalize') {
        // A successor draft may replace the latest state before this child
        // starts. The exact adopted receipt survives that replacement.
        $task = $store->adoptionReceipt((int)$novel['user_id'], (int)$novel['id'], $chapterId, $taskId);
        if ($task === null || !hash_equals($task['candidate_hash'], hash('sha256', (string)$chapter['content']))) exit(0);
        set_time_limit(900);
        require_once dirname(__DIR__) . '/includes/ChapterMemoryFinalizer.php';
        // Keep the same source authority across finalize -> historical replay.
        // Both helpers re-enter this fence; an edit cannot slip between them.
        $projectionFence = NovelProjectionFence::acquire((int)$novel['id'], 30);
        try {
            $chapter = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
            $currentNovel = DB::fetch('SELECT * FROM novels WHERE id=?', [(int)$novel['id']]);
            if (!$chapter || !$currentNovel || (int)$chapter['novel_id'] !== $task['novel_id']
                || (int)$currentNovel['user_id'] !== $task['user_id']
                || ($chapter['status'] ?? '') !== 'completed'
                || !hash_equals($task['candidate_hash'], hash('sha256', (string)$chapter['content']))) return;
            $result = ChapterMemoryFinalizer::finalize((int)$novel['id'], $chapterId, (string)$chapter['content']);
            if (!empty($result['stale'])) return;
            if (!empty($result['historical_deferred'])) {
                $chapterNumber = (int)$chapter['chapter_number'];
                $result = ChapterMemoryFinalizer::replayFrom((int)$novel['id'], $chapterNumber, null,
                    [$chapterNumber => (array)($result['summary'] ?? [])]);
            }
            if (empty($result['ok'])) {
                throw new RuntimeException('Adopted rewrite memory refresh did not complete.');
            }
        } finally {
            NovelProjectionFence::release($projectionFence);
        }
        // The memory finalizer has its own exact-revision marker and novel
        // fence. It never needs to keep the candidate lock through an AI call.
        exit(0);
    }
    if ((int)$novel['user_id'] !== $task['user_id'] || (int)$novel['id'] !== $task['novel_id']
        || !hash_equals($task['source_hash'], hash('sha256', (string)$chapter['content']))
        || ($chapter['status'] ?? '') === 'writing') {
        $store->fail('source_changed', '正文或章节状态已变化，请刷新后重新改写');
        $complete = true;
        exit(1);
    }
    set_time_limit(900);
    require_once dirname(__DIR__) . '/includes/quality/ChapterQualityRewriteGenerator.php';
    $result = ChapterQualityRewriteGenerator::generate($chapter, $novel,
        static function (string $stage, array $metrics = []) use ($store): void { $store->record($stage, $metrics); });
    $store->complete($result['content'], $result['words'], $result['quality_before'], $result['quality_after']);
    $complete = true;
} catch (Throwable $error) {
    if ($owned) {
        try {
            $store->fail('generation_failed', '后台改写未能完成，原文未变，请检查模型连接后重新生成');
            $complete = true;
        } catch (Throwable) {}
    }
    error_log('[' . $taskId . '] chapter rewrite ' . $phase . ' failed (' . get_class($error) . ')');
    exit(1);
} finally { $store->close(); }
