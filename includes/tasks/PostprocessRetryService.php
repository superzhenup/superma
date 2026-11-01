<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');
require_once dirname(__DIR__) . '/NovelProjectionFence.php';
require_once __DIR__ . '/PostprocessJobRepository.php';

final class PostprocessRetryConflict extends RuntimeException {}

/** Requeue only the failed job belonging to the current saved prose and Canon generation. */
final class PostprocessRetryService
{
    public static function retry(int $userId, int $chapterId, int $jobId, string $expectedHash): string
    {
        if ($userId < 1 || $chapterId < 1 || $jobId < 1 || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
            throw new InvalidArgumentException('Invalid postprocess retry identity.');
        }
        $owned = DB::fetch('SELECT c.novel_id FROM chapters c INNER JOIN novels n ON n.id=c.novel_id WHERE c.id=? AND n.user_id=?', [$chapterId, $userId]);
        if (!$owned) throw new PostprocessRetryConflict('章节不存在或无权访问');
        $novelId = (int)$owned['novel_id'];
        $fence = NovelProjectionFence::acquire($novelId, 5);
        $pdo = DB::getPdo();
        try {
            if (!$pdo->beginTransaction()) throw new RuntimeException('Could not begin postprocess retry transaction.');
            $chapter = DB::fetch('SELECT * FROM chapters WHERE id=? AND novel_id=? FOR UPDATE', [$chapterId, $novelId]);
            $owner = DB::fetch('SELECT id FROM novels WHERE id=? AND user_id=? FOR UPDATE', [$novelId, $userId]);
            $job = DB::fetch('SELECT * FROM postprocess_jobs WHERE id=? AND chapter_id=? AND novel_id=? FOR UPDATE', [$jobId, $chapterId, $novelId]);
            if (!$chapter || !$owner || !$job || !hash_equals($expectedHash, hash('sha256', (string)$chapter['content']))) {
                throw new PostprocessRetryConflict('正文或任务已变化，请刷新章节页后重试');
            }
            $candidateUid = $chapter['canonical_candidate_uid'] ?? null;
            $candidate = $candidateUid === null ? null : DB::fetch(
                'SELECT * FROM chapter_candidates WHERE candidate_uid=? AND chapter_id=? AND novel_id=? FOR UPDATE',
                [$candidateUid, $chapterId, $novelId]
            );
            self::assertCurrentIdentity($job, $chapter, $candidate);
            $state = (string)$job['state'];
            if ($state === 'failed') {
                if ($job['lease_owner'] !== null || $job['lease_token'] !== null) throw new PostprocessRetryConflict('任务仍有执行租约，请稍后刷新');
                // Identity, frozen policy, rewrite eligibility and fence generation
                // are immutable here. A normal worker claim will mint a new lease.
                $changed = DB::execute(
                    'UPDATE postprocess_jobs SET state="queued", attempt=0, available_at=NOW(), last_error=NULL,
                         started_at=NULL, completed_at=NULL, lease_expires_at=NULL
                     WHERE id=? AND state="failed" AND lease_owner IS NULL AND lease_token IS NULL', [$jobId]
                );
                if ($changed !== 1) throw new PostprocessRetryConflict('任务状态已变化，请刷新后重试');
                $state = 'queued';
            } elseif (!in_array($state, ['queued', 'retry', 'running', 'done'], true)) {
                throw new PostprocessRetryConflict('当前任务状态不支持重试');
            }
            if (!$pdo->commit()) throw new RuntimeException('Could not commit postprocess retry transaction.');
            return $state;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally { NovelProjectionFence::release($fence); }
    }

    /** Public for offline identity-contract tests; it never writes or calls a model. */
    public static function assertCurrentIdentity(array $job, array $chapter, ?array $candidate): void
    {
        $content = (string)($chapter['content'] ?? '');
        $revision = hash('sha256', $content);
        if (($chapter['status'] ?? '') !== 'completed' || trim($content) === ''
            || (int)($job['chapter_id'] ?? 0) !== (int)($chapter['id'] ?? 0)
            || (int)($job['novel_id'] ?? 0) !== (int)($chapter['novel_id'] ?? 0)
            || ($job['stage'] ?? '') !== 'full' || !hash_equals($revision, (string)($job['revision_hash'] ?? ''))) {
            throw new PostprocessRetryConflict('该任务不属于当前已保存正文，请刷新章节页');
        }
        $policy = PostprocessJobRepository::snapshotFromRow($job);
        $candidateUid = PostprocessJobRepository::acceptedCandidateFromRow($job);
        $rewriteAllowed = PostprocessJobRepository::rewriteAllowedFromRow($job);
        if (!hash_equals(PostprocessJobRepository::jobUid((int)$chapter['id'], $revision, 'full', $candidateUid), (string)($job['job_uid'] ?? ''))) {
            throw new PostprocessRetryConflict('任务身份不一致，不能重试');
        }
        if (($chapter['canonical_state'] ?? '') === 'canonical') {
            if ($policy === null || $candidateUid === null || ($chapter['canonical_candidate_uid'] ?? null) !== $candidateUid
                || ($chapter['content_revision_hash'] ?? null) !== $revision || $candidate === null
                || ($candidate['candidate_uid'] ?? null) !== $candidateUid
                || (int)($candidate['chapter_id'] ?? 0) !== (int)$chapter['id'] || (int)($candidate['novel_id'] ?? 0) !== (int)$chapter['novel_id']
                || ($candidate['lifecycle'] ?? '') !== 'committed' || ($candidate['gate_state'] ?? '') !== 'passed'
                || ($candidate['content_hash'] ?? '') !== $revision || ($candidate['policy_snapshot_hash'] ?? '') !== $policy->checksum()
                || !in_array($candidate['source'] ?? '', ['initial', 'rewrite'], true)
                || $rewriteAllowed !== ($candidate['source'] === 'initial')) {
                throw new PostprocessRetryConflict('已采纳稿件身份已变化，不能重试旧任务');
            }
            $chapterPolicy = WritePolicySnapshot::fromJson((string)($chapter['write_policy_snapshot_json'] ?? ''));
            if (!hash_equals($chapterPolicy->checksum(), $policy->checksum())) throw new PostprocessRetryConflict('任务写作策略与当前正文不一致');
        } elseif (($chapter['canonical_state'] ?? '') !== 'legacy'
            || ($chapter['content_revision_hash'] ?? null) !== $revision || ($chapter['canonicalized_at'] ?? null) !== null
            || $candidateUid !== null || ($chapter['canonical_candidate_uid'] ?? null) !== null || $rewriteAllowed
            || $policy !== null || ($chapter['write_policy_snapshot_json'] ?? null) !== null) {
            throw new PostprocessRetryConflict('历史正文任务不能携带稿件或改写权限');
        }
    }
}
