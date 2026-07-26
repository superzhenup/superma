<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostprocessJobRepository.php';
require_once __DIR__ . '/ProjectionRunRepository.php';
require_once dirname(__DIR__) . '/memory/MemoryEngine.php';
require_once dirname(__DIR__) . '/write_engine.php';

final class PostprocessRunner
{
    /**
     * 正文落盘后先持久入队，再由当前进程抢占执行。即使进程崩溃，任务仍可由
     * bin/postprocess_worker.php 在租约过期后重试。
     *
     * @return array{job_id:int,state:string,revision_hash:string}
     */
    public static function enqueueAndRun(
        int $novelId,
        array $chapter,
        string $fullContent,
        ?MemoryEngine $engine = null,
        string $workerId = ''
    ): array {
        $chapterId = (int)($chapter['id'] ?? 0);
        $stored = DB::fetch(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" LIMIT 1',
            [$chapterId, $novelId]
        );
        if (!$stored) {
            throw new RuntimeException('Cannot enqueue post-processing before chapter content is committed.');
        }

        $storedContent = (string)($stored['content'] ?? '');
        if ($storedContent === '') {
            throw new RuntimeException('Cannot enqueue post-processing for empty chapter content.');
        }
        $revisionHash = hash('sha256', $storedContent);
        $jobId = PostprocessJobRepository::enqueue($novelId, $chapterId, $revisionHash);
        $workerId = $workerId !== '' ? $workerId : self::defaultWorkerId('inline');
        $job = PostprocessJobRepository::claimById($jobId, $workerId);

        if (!$job) {
            $existing = PostprocessJobRepository::findById($jobId);
            return [
                'job_id' => $jobId,
                'state' => (string)($existing['state'] ?? 'pending'),
                'revision_hash' => $revisionHash,
            ];
        }

        return self::runClaimed($job, $engine, array_merge($chapter, $stored), $storedContent);
    }

    /** @return array{job_id:int,state:string,revision_hash:string}|null */
    public static function runNext(string $workerId = ''): ?array
    {
        $workerId = $workerId !== '' ? $workerId : self::defaultWorkerId('queue');
        $job = PostprocessJobRepository::claimNext($workerId);
        if (!$job) return null;
        return self::runClaimed($job, null, null, null);
    }

    /**
     * @return array{job_id:int,state:string,revision_hash:string}
     */
    private static function runClaimed(
        array $job,
        ?MemoryEngine $engine,
        ?array $chapter,
        ?string $content
    ): array {
        $jobId = (int)$job['id'];
        $novelId = (int)$job['novel_id'];
        $chapterId = (int)$job['chapter_id'];
        $revisionHash = (string)$job['revision_hash'];
        $stage = (string)($job['stage'] ?? 'full');

        try {
            if (ProjectionRunRepository::isCompleted($chapterId, $revisionHash, $stage)) {
                PostprocessJobRepository::markDone($jobId);
                return ['job_id' => $jobId, 'state' => 'done', 'revision_hash' => $revisionHash];
            }

            if ($chapter === null || $content === null) {
                $chapter = DB::fetch(
                    'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" LIMIT 1',
                    [$chapterId, $novelId]
                ) ?: null;
                $content = $chapter ? (string)($chapter['content'] ?? '') : null;
            }

            // 已被人工重写/恢复覆盖的旧任务直接收敛为 stale，不再污染当前投影。
            if (!$chapter || $content === null || !hash_equals($revisionHash, hash('sha256', $content))) {
                PostprocessJobRepository::markDone($jobId);
                return ['job_id' => $jobId, 'state' => 'stale', 'revision_hash' => $revisionHash];
            }

            $engine ??= new MemoryEngine($novelId);
            WriteEngine::postProcess($novelId, $chapter, $content, $engine, true);
            ProjectionRunRepository::markCompleted(
                $novelId,
                $chapterId,
                $revisionHash,
                $stage,
                hash('sha256', $content)
            );
            PostprocessJobRepository::markDone($jobId);
            return ['job_id' => $jobId, 'state' => 'done', 'revision_hash' => $revisionHash];
        } catch (Throwable $e) {
            $state = PostprocessJobRepository::markRetryOrFailed($jobId, $e->getMessage());
            error_log(sprintf('[postprocess_job:%d] %s: %s', $jobId, $state, $e->getMessage()));
            return ['job_id' => $jobId, 'state' => $state, 'revision_hash' => $revisionHash];
        }
    }

    private static function defaultWorkerId(string $kind): string
    {
        $host = gethostname() ?: 'localhost';
        return sprintf('%s:%s:%d', $kind, $host, getmypid());
    }
}
