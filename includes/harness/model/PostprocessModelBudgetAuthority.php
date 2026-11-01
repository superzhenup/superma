<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ModelContracts.php';
require_once dirname(__DIR__) . '/runtime/RuntimeExceptions.php';

/** A job may spend its originating chapter Run budget only with its own live fence. */
final readonly class PostprocessModelBudgetAuthority
{
    public function __construct(
        public int $jobId,
        public string $jobUid,
        public int $novelId,
        public int $chapterId,
        public string $leaseOwner,
        public string $leaseToken,
        public int $fenceGeneration,
        private ?array $subtask = null
    ) {
        HarnessPersistenceValue::positiveInt($jobId, 'postprocess_budget.job_id');
        HarnessPersistenceValue::uid($jobUid, 'postprocess_budget.job_uid');
        HarnessPersistenceValue::positiveInt($novelId, 'postprocess_budget.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'postprocess_budget.chapter_id');
        HarnessPersistenceValue::identifier($leaseOwner, 'postprocess_budget.lease_owner', 120);
        HarnessPersistenceValue::uid($leaseToken, 'postprocess_budget.lease_token');
        HarnessPersistenceValue::positiveInt($fenceGeneration, 'postprocess_budget.fence_generation');
        if ($subtask !== null) {
            foreach (['id','fence_generation'] as $field) HarnessPersistenceValue::positiveInt($subtask[$field] ?? null, 'postprocess_budget.subtask_'.$field);
            foreach (['task_uid','lease_token'] as $field) HarnessPersistenceValue::uid($subtask[$field] ?? null, 'postprocess_budget.subtask_'.$field);
            HarnessPersistenceValue::identifier($subtask['lease_owner'] ?? null, 'postprocess_budget.subtask_owner', 120);
            if (($subtask['state'] ?? '') !== 'running'
                || preg_match('/^[a-f0-9]{64}$/D',(string)($subtask['input_hash'] ?? '')) !== 1) {
                throw new InvalidArgumentException('Post-processing subtask budget requires a running immutable input.');
            }
        }
    }

    /** Called inside the already-held Run row transaction; never across network I/O. */
    public function assertAdmitted(HarnessPersistenceDriver $db, array $run): void
    {
        $job = $this->assertLive($db, true);
        $origin = self::resolveRun($db, $job);
        if ($origin === null || (int)$origin['id'] !== (int)$run['id']
            || !hash_equals((string)$origin['run_uid'], (string)$run['run_uid'])) {
            throw new RuntimeControlException('Post-processing job does not own this chapter Run budget.');
        }
    }

    /** Authenticate both fences even for historical jobs without an authority budget. */
    public function assertLive(HarnessPersistenceDriver $db, bool $lock = false): array
    {
        $job = $db->fetchOne(
            'SELECT *, (lease_expires_at IS NOT NULL AND lease_expires_at>NOW()) AS budget_lease_live
             FROM postprocess_jobs WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$this->jobId]
        );
        if ($job === null || ($job['state'] ?? '') !== 'running'
            || (int)($job['budget_lease_live'] ?? 0) !== 1
            || (string)($job['job_uid'] ?? '') !== $this->jobUid
            || (int)($job['novel_id'] ?? 0) !== $this->novelId
            || (int)($job['chapter_id'] ?? 0) !== $this->chapterId
            || (string)($job['lease_owner'] ?? '') !== $this->leaseOwner
            || !hash_equals($this->leaseToken, (string)($job['lease_token'] ?? ''))
            || (int)($job['fence_generation'] ?? 0) !== $this->fenceGeneration) {
            throw new LeaseLostException('Post-processing model caller no longer owns the exact job lease.');
        }
        if ($this->subtask !== null) {
            $task = $db->fetchOne('SELECT *, (lease_expires_at IS NOT NULL AND lease_expires_at>NOW()) AS budget_lease_live
                FROM postprocess_subtasks WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [(int)$this->subtask['id']]);
            if ($task === null || ($task['state'] ?? '') !== 'running' || (int)($task['budget_lease_live'] ?? 0) !== 1) {
                throw new LeaseLostException('Post-processing subtask budget lease is no longer live.');
            }
            foreach (['task_uid','input_hash','lease_owner','lease_token','fence_generation','parent_job_id',
                'parent_job_uid','parent_lease_owner','parent_lease_token','parent_fence_generation'] as $field) {
                if ((string)($task[$field] ?? '') !== (string)($this->subtask[$field] ?? '')) {
                    throw new LeaseLostException('Post-processing subtask budget identity changed.');
                }
            }
            foreach (['parent_job_id'=>'id','parent_job_uid'=>'job_uid','novel_id'=>'novel_id','chapter_id'=>'chapter_id',
                'revision_hash'=>'revision_hash','parent_lease_owner'=>'lease_owner','parent_lease_token'=>'lease_token',
                'parent_fence_generation'=>'fence_generation'] as $field=>$parentField) {
                if ((string)($task[$field] ?? '') !== (string)($job[$parentField] ?? '')) {
                    throw new LeaseLostException('Post-processing subtask belongs to another parent lease.');
                }
            }
            if (($task['accepted_candidate_uid'] ?? null) !== ($job['accepted_candidate_uid'] ?? null)) {
                throw new LeaseLostException('Post-processing subtask belongs to another Candidate.');
            }
        }
        return $job;
    }

    /**
     * Resolve durable Canon provenance, including a postprocess Rewrite successor.
     * NULL means a legacy chapter with no Harness Run, never a corrupt Run link.
     * No caller-supplied _run_id or current worker identity is trusted here.
     * @return array<string,mixed>|null
     */
    public static function resolveRun(HarnessPersistenceDriver $db, array $job): ?array
    {
        $chapterId = HarnessPersistenceValue::positiveInt($job['chapter_id'] ?? null, 'postprocess_budget.chapter_id');
        $novelId = HarnessPersistenceValue::positiveInt($job['novel_id'] ?? null, 'postprocess_budget.novel_id');
        $jobId = HarnessPersistenceValue::positiveInt($job['id'] ?? null, 'postprocess_budget.job_id');
        $receipt = $db->fetchOne(
            'SELECT run_id, candidate_id, committed_revision_hash FROM chapter_commit_ledger
             WHERE postprocess_job_id=? AND chapter_id=?', [$jobId, $chapterId]
        );
        $candidateUid = $job['accepted_candidate_uid'] ?? null;
        if ($receipt !== null) {
            if (!hash_equals((string)$receipt['committed_revision_hash'], (string)($job['revision_hash'] ?? ''))) {
                throw new RuntimeControlException('Post-processing budget receipt revision mismatch.');
            }
            if ($receipt['candidate_id'] !== null) {
                $owner = $db->fetchOne(
                    'SELECT candidate_uid FROM chapter_candidates WHERE id=? AND novel_id=? AND chapter_id=?',
                    [(int)$receipt['candidate_id'], $novelId, $chapterId]
                );
                if ($owner === null || ($owner['candidate_uid'] ?? null) !== $candidateUid) {
                    throw new RuntimeControlException('Post-processing budget receipt Candidate mismatch.');
                }
            } elseif ($candidateUid !== null) {
                throw new RuntimeControlException('Legacy budget receipt cannot own a Candidate job.');
            }
            return self::runRow($db, (int)$receipt['run_id'], $novelId, $chapterId);
        }

        $seen = [];
        while ($candidateUid !== null) {
            HarnessPersistenceValue::uid($candidateUid, 'postprocess_budget.candidate_uid');
            if (isset($seen[$candidateUid]) || count($seen) >= 32) {
                throw new RuntimeControlException('Post-processing budget Candidate lineage is cyclic or too deep.');
            }
            $seen[$candidateUid] = true;
            $candidate = $db->fetchOne(
                'SELECT id, run_id, source_run_uid, source, parent_candidate_uid, lifecycle, content_hash
                 FROM chapter_candidates WHERE candidate_uid=? AND novel_id=? AND chapter_id=?',
                [$candidateUid, $novelId, $chapterId]
            );
            if ($candidate === null || !in_array($candidate['lifecycle'] ?? '', ['committed', 'superseded'], true)) {
                throw new RuntimeControlException('Post-processing budget Candidate provenance is unavailable.');
            }
            if (count($seen) === 1
                && !hash_equals((string)$candidate['content_hash'], (string)($job['revision_hash'] ?? ''))) {
                throw new RuntimeControlException('Post-processing budget Candidate revision mismatch.');
            }
            $receipt = $db->fetchOne(
                'SELECT run_id FROM chapter_commit_ledger WHERE candidate_id=? AND chapter_id=?',
                [(int)$candidate['id'], $chapterId]
            );
            if ($receipt !== null) {
                return self::runRow($db, (int)$receipt['run_id'], $novelId, $chapterId);
            }
            // A Run-associated Candidate without an atomic receipt must never
            // downgrade into an unmetered legacy job.
            //
            // 审计修复 P0（2026-10-18）：legacy 异步 worker（write_start →
            // write_chapter_worker，Harness 默认 off）提交时，saveChapter 把
            // writing_tasks 的租约令牌写进 source_run_uid（无 run_id、无提交
            // 账本）。原判定仅凭 source_run_uid 非空即视为"缺回执的 Run 候选"，
            // 导致默认路径每章后处理确定性失败、写完第 1 章即无法继续。
            // 只有该 uid 真实对应一条 generation_runs 行时才要求回执；租约令牌
            // 与任何 Run 的 run_uid 都不同（shadow Run 用任务 ID 的哈希），
            // 不会让真实 Run 的候选绕过预算。
            if ($candidate['run_id'] !== null
                || ($candidate['source_run_uid'] !== null
                    && $db->fetchOne(
                        'SELECT id FROM generation_runs WHERE run_uid=? LIMIT 1',
                        [(string)$candidate['source_run_uid']]
                    ) !== null)) {
                throw new RuntimeControlException('Post-processing Run Candidate is missing its budget receipt.');
            }
            $parent = $candidate['parent_candidate_uid'];
            if (($candidate['source'] ?? '') === 'rewrite' && $parent === null) {
                throw new RuntimeControlException('Post-processing Rewrite Candidate is missing its parent.');
            }
            $candidateUid = $parent;
        }
        return null;
    }

    private static function runRow(HarnessPersistenceDriver $db, int $runId, int $novelId, int $chapterId): array
    {
        $run = $db->fetchOne('SELECT * FROM generation_runs WHERE id=?', [$runId]);
        if ($run === null || (int)$run['novel_id'] !== $novelId || (int)$run['chapter_id'] !== $chapterId
            || ($run['authority_mode'] ?? '') !== 'authority') {
            throw new RuntimeControlException('Post-processing budget Run provenance is invalid.');
        }
        return $run;
    }
}
