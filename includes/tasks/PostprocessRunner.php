<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostprocessJobRepository.php';
require_once __DIR__ . '/ProjectionRunRepository.php';
require_once __DIR__ . '/WritingTaskRepository.php';
require_once dirname(__DIR__) . '/memory/MemoryEngine.php';
require_once dirname(__DIR__) . '/write_engine.php';
require_once dirname(__DIR__) . '/ChapterMemoryFinalizer.php';
require_once dirname(__DIR__) . '/NovelProjectionFence.php';
require_once dirname(__DIR__) . '/quality/WritePolicySnapshot.php';
require_once dirname(__DIR__) . '/quality/WritePolicySnapshotFactory.php';

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
        string $workerId = '',
        ?WritePolicySnapshot $policySnapshot = null,
        ?array $writingTaskLease = null,
        ?callable $workflowHeartbeat = null
    ): array {
        $writingTaskLease = self::normalizeWritingTaskLease($writingTaskLease);
        $chapterId = (int)($chapter['id'] ?? 0);
        $stored = DB::fetch(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
            [$chapterId, $novelId]
        );
        if (!$stored) {
            throw new RuntimeException('Cannot enqueue post-processing before chapter content is committed.');
        }
        $storedStatus = (string)($stored['status'] ?? '');
        // 落盘硬质检会把章标为 failed：正文已在，但不得进入记忆投影。
        // 原先要求 status=completed，SSE 路径会在 [DONE] 之后抛未捕获异常，
        // 前端把「质检未过」显示成「服务器内部错误（追踪号）」。
        if ($storedStatus !== 'completed') {
            return [
                'job_id' => 0,
                'state' => 'skipped',
                'revision_hash' => '',
                'skipped_status' => $storedStatus,
            ];
        }

        $storedContent = (string)($stored['content'] ?? '');
        if ($storedContent === '') {
            throw new RuntimeException('Cannot enqueue post-processing for empty chapter content.');
        }
        $revisionHash = hash('sha256', $storedContent);
        $policySnapshot = self::resolveSnapshotForEnqueue(
            $stored,
            $policySnapshot,
            $revisionHash
        );
        [$acceptedCandidateUid, $rewriteAllowed] = self::resolveJobCandidateForEnqueue(
            $stored,
            $policySnapshot,
            $revisionHash
        );
        $jobId = PostprocessJobRepository::enqueue(
            $novelId,
            $chapterId,
            $revisionHash,
            'full',
            3,
            $policySnapshot,
            $acceptedCandidateUid,
            $rewriteAllowed
        );
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

        return self::runClaimed(
            $job,
            $engine,
            array_merge($chapter, $stored),
            $storedContent,
            $writingTaskLease,
            $workflowHeartbeat
        );
    }

    /** @return array{job_id:int,state:string,revision_hash:string}|null */
    public static function runNext(string $workerId = ''): ?array
    {
        $workerId = $workerId !== '' ? $workerId : self::defaultWorkerId('queue');
        $job = PostprocessJobRepository::claimNext($workerId);
        if (!$job) return null;
        return self::runClaimed($job, null, null, null, null);
    }

    /**
     * @return array{job_id:int,state:string,revision_hash:string}
     */
    private static function runClaimed(
        array $job,
        ?MemoryEngine $engine,
        ?array $chapter,
        ?string $content,
        ?array $writingTaskLease,
        ?callable $workflowHeartbeat = null
    ): array {
        $jobId = (int)$job['id'];
        $novelId = (int)$job['novel_id'];
        $chapterId = (int)$job['chapter_id'];
        $revisionHash = (string)$job['revision_hash'];
        $stage = (string)($job['stage'] ?? 'full');
        // P2-6：提取本 worker 持有的租约身份，传给 markDone 做归属校验，
        // 防止过期 worker 恢复后误把"已被新 worker 接管的 job"标记完成。
        try {
            $lease = PostprocessJobRepository::leaseIdentityFromRow($job);
        } catch (Throwable $e) {
            error_log(sprintf('[postprocess_job:%d] lease_lost: %s', $jobId, $e->getMessage()));
            return [
                'job_id' => $jobId,
                'state' => 'lease_lost',
                'revision_hash' => $revisionHash,
            ];
        }
        $leaseOwner = $lease['owner'];
        $leaseToken = $lease['token'];
        $fenceGeneration = $lease['generation'];

        $projectionFence = null;
        try {
            // Keep the same novel-wide fence from fresh Canon authentication
            // through projection publication and the exact job completion CAS.
            // A manual mutation that wins first deletes this job; a mutation
            // that waits for us invalidates everything only after publication.
            $projectionFence = NovelProjectionFence::acquire($novelId, 30);
            // Snapshot-aware attempts restore only the value frozen on their
            // job row. A legacy NULL remains NULL; the runner never invents a
            // replacement snapshot during retry.
            $policySnapshot = PostprocessJobRepository::snapshotFromRow($job);
            $acceptedCandidateUid = PostprocessJobRepository::acceptedCandidateFromRow($job);
            $rewriteAllowed = PostprocessJobRepository::rewriteAllowedFromRow($job);
            if ($policySnapshot !== null) {
                WritePolicySnapshotFactory::assertPostprocessWorkerFenceEnforced(
                    $policySnapshot,
                    $job,
                    $lease
                );
            }
            self::assertRunnableSnapshot($policySnapshot, $job, $chapter);

            // Authenticate against a fresh chapter row on every attempt. The
            // inline caller's array may carry useful transient fields, but it
            // must never override current persisted Canon metadata/content.
            $currentChapter = DB::fetch(
                'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                [$chapterId, $novelId]
            );
            if (!is_array($currentChapter)) {
                self::markDoneOrThrow($jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid);
                return ['job_id' => $jobId, 'state' => 'stale', 'revision_hash' => $revisionHash];
            }
            $currentStatus = (string)($currentChapter['status'] ?? '');
            if ($currentStatus === 'writing'
                && (string)($currentChapter['canonical_state'] ?? '') === 'canonical') {
                // A process may die after claiming an existing Canon for a
                // rewrite but before ChapterCanonTransition can settle it. Do
                // not mark that predecessor job done: authenticate its draft,
                // frozen base and current lease, release only the exact claim,
                // then let this fenced retry resume from the old Canon.
                if ($policySnapshot === null
                    || $acceptedCandidateUid === null
                    || !$rewriteAllowed) {
                    throw new RuntimeException(
                        'A claimed Canon rewrite cannot be recovered by this post-processing job identity.'
                    );
                }
                $orphanedClaimToken = (string)($currentChapter['claim_token'] ?? '');
                $recovered = WriteEngine::recoverOrphanedRewriteClaim(
                    $novelId,
                    $chapterId,
                    $jobId,
                    $revisionHash,
                    $orphanedClaimToken,
                    $policySnapshot,
                    [
                        'owner' => $leaseOwner,
                        'token' => $leaseToken,
                        'generation' => $fenceGeneration,
                    ]
                );
                if (!$recovered) {
                    throw new RuntimeException(
                        'The claimed Canon rewrite is not yet safely recoverable; keep the job retryable.'
                    );
                }
                $currentChapter = DB::fetch(
                    'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" LIMIT 1',
                    [$chapterId, $novelId]
                );
                if (!is_array($currentChapter)) {
                    throw new RuntimeException(
                        'Recovered rewrite claim did not restore the completed Canon.'
                    );
                }
                $currentStatus = 'completed';
            }
            if ($currentStatus !== 'completed') {
                self::markDoneOrThrow($jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid);
                return ['job_id' => $jobId, 'state' => 'stale', 'revision_hash' => $revisionHash];
            }
            $chapter = array_merge($chapter ?? [], $currentChapter);
            $content = (string)($currentChapter['content'] ?? '');

            // 已被人工重写/恢复覆盖的旧任务直接收敛为 stale，不再污染当前投影。
            if (!hash_equals($revisionHash, hash('sha256', $content))) {
                self::markDoneOrThrow($jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid);
                return ['job_id' => $jobId, 'state' => 'stale', 'revision_hash' => $revisionHash];
            }

            // Content hashes are not Canon-generation identities. A later
            // Candidate may legitimately restore byte-identical prose
            // (A -> B -> A). Authenticate that newer current Candidate using
            // the chapter's own frozen policy, then retire this historical job
            // as stale before comparing it against the predecessor owner.
            $currentCandidateUid = $currentChapter['canonical_candidate_uid'] ?? null;
            $candidateGenerationChanged =
                (string)($currentChapter['canonical_state'] ?? '') === 'canonical'
                && is_string($currentCandidateUid)
                && preg_match('/^[a-f0-9]{32}$/D', $currentCandidateUid) === 1
                && ($acceptedCandidateUid === null
                    || !hash_equals($acceptedCandidateUid, $currentCandidateUid));
            if ($candidateGenerationChanged) {
                $currentPolicyJson = $currentChapter['write_policy_snapshot_json'] ?? null;
                if (!is_string($currentPolicyJson) || $currentPolicyJson === '') {
                    throw new RuntimeException(
                        'Superseding Canon generation is missing its frozen policy.'
                    );
                }
                try {
                    $currentPolicy = WritePolicySnapshot::fromJson($currentPolicyJson);
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        'Superseding Canon generation has an invalid frozen policy.',
                        0,
                        $exception
                    );
                }
                [$authenticatedCurrentUid] = self::resolveJobCandidateForEnqueue(
                    $currentChapter,
                    $currentPolicy,
                    $revisionHash
                );
                if (!is_string($authenticatedCurrentUid)
                    || !hash_equals($currentCandidateUid, $authenticatedCurrentUid)) {
                    throw new RuntimeException(
                        'Superseding Canon generation could not authenticate its Candidate.'
                    );
                }
                self::markDoneOrThrow(
                    $jobId,
                    $leaseOwner,
                    $leaseToken,
                    $fenceGeneration,
                    $acceptedCandidateUid
                );
                return [
                    'job_id' => $jobId,
                    'state' => 'stale',
                    'revision_hash' => $revisionHash,
                ];
            }

            // A different content hash is a normal superseding Canon and was
            // handled as stale above. When the content still matches, every
            // policy/revision representation must authenticate before work.
            self::authenticateCanonicalChapter(
                $policySnapshot,
                $chapter,
                $content,
                $revisionHash
            );
            self::authenticateJobCandidate(
                $policySnapshot,
                $chapter,
                $revisionHash,
                $acceptedCandidateUid,
                $rewriteAllowed
            );

            // A projection marker is meaningful only after the current Canon
            // and immutable Candidate-owned job have both authenticated. This
            // prevents a historical byte-identical revision from completing a
            // newer Canon generation's job without checking ownership.
            if (ProjectionRunRepository::isCompleted($chapterId, $revisionHash, $stage)) {
                self::markDoneOrThrow($jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid);
                return ['job_id' => $jobId, 'state' => 'done', 'revision_hash' => $revisionHash];
            }

            // Keep an explicit internal carrier for compatibility with the
            // current engine and also pass the value as the appended argument.
            // A NULL carrier intentionally selects the historical legacy path.
            $chapter['_write_policy_snapshot'] = $policySnapshot;

            // 审计修复 P1-8（2026-08-08）：后处理执行期间周期性续租 job 与写作任务。
            // 此前 extendLease 定义后全库无调用：后处理超过 1800s 时 job 租约与
            // writing_tasks 租约几乎同时到期，findActiveForNovel 的孤儿清扫会把
            // 活跃的后处理误判 postprocess_orphaned，导致 write_start 拉起第二个
            // worker 双写；而 H3 的 1800s 宽限（第三道清扫）永远轮不到生效。
            // The job may outlive the chapter worker. Restore its originating
            // Run's budget using this job's own exact lease, never a stale Run lease.
            require_once dirname(__DIR__) . '/harness/model/PostprocessModelBudgetScope.php';
            $postProcessOutcome = PostprocessModelBudgetScope::run(
                DB::getPdo(), $job, static function () use (
                    $novelId, $chapter, $content, $engine, $jobId, $leaseOwner,
                    $leaseToken, $fenceGeneration, $writingTaskLease, $workflowHeartbeat,
                    $policySnapshot, $stage, $acceptedCandidateUid, $rewriteAllowed
                ): array {
                    $engine ??= new MemoryEngine($novelId);
                    return WriteEngine::postProcess(
                        $novelId, $chapter, $content, $engine, true,
                        self::makeLeaseHeartbeat(
                            $jobId,
                            $leaseOwner,
                            $leaseToken,
                            $fenceGeneration,
                            $writingTaskLease,
                            $workflowHeartbeat
                        ),
                        $policySnapshot,
                        [
                            'job_id' => $jobId,
                            'stage' => $stage,
                            'accepted_candidate_uid' => $acceptedCandidateUid,
                            'rewrite_allowed' => $rewriteAllowed,
                            'lease_owner' => $leaseOwner,
                            'lease_token' => $leaseToken,
                            'fence_generation' => $fenceGeneration,
                        ]
                    );
                }
            );

            $postProcessState = (string)($postProcessOutcome['state'] ?? '');
            if ($postProcessState === 'stale') {
                self::markDoneOrThrow($jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid);
                return ['job_id' => $jobId, 'state' => 'stale', 'revision_hash' => $revisionHash];
            }
            if ($postProcessState === 'pending') {
                throw new RuntimeException(
                    'Post-processing projections are pending for the accepted Canon revision.'
                );
            }
            if (!in_array($postProcessState, ['processed', 'duplicate'], true)) {
                throw new RuntimeException('Post-processing returned an invalid outcome state.');
            }

            // postProcess may have promoted a Rewrite Candidate. From this
            // point onward the old job revision/content are untrusted: every
            // projection identity is derived from a fresh completed Canon row.
            $freshChapter = DB::fetch(
                'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                [$chapterId, $novelId]
            );
            $freshStatus = is_array($freshChapter)
                ? (string)($freshChapter['status'] ?? '')
                : '';
            if (!is_array($freshChapter) || $freshStatus !== 'completed') {
                self::markDoneOrThrow($jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid);
                return ['job_id' => $jobId, 'state' => 'done', 'revision_hash' => $revisionHash];
            }
            $freshContent = (string)($freshChapter['content'] ?? '');
            $freshRevisionHash = hash('sha256', $freshContent);
            $outcomeRevisionHash = (string)($postProcessOutcome['revision_hash'] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/D', $outcomeRevisionHash) !== 1
                || !hash_equals($outcomeRevisionHash, $freshRevisionHash)) {
                throw new RuntimeException(
                    'Post-processing outcome does not match the fresh completed Canon revision.'
                );
            }
            self::authenticateCanonicalChapter(
                $policySnapshot,
                $freshChapter,
                $freshContent,
                $freshRevisionHash
            );

            $outcomeCandidateUid = $postProcessOutcome['candidate_uid'] ?? null;
            if ($outcomeCandidateUid !== null
                && (!is_string($outcomeCandidateUid)
                    || preg_match('/^[a-f0-9]{32}$/D', $outcomeCandidateUid) !== 1)) {
                throw new RuntimeException('Post-processing outcome has an invalid Candidate UID.');
            }
            $candidateGenerationAdvanced = is_string($outcomeCandidateUid)
                && ($acceptedCandidateUid === null
                    || !hash_equals($acceptedCandidateUid, $outcomeCandidateUid));
            $outcomeSuccessorJobId = $postProcessOutcome['successor_job_id'] ?? null;
            if ($candidateGenerationAdvanced) {
                if (!$rewriteAllowed
                    || !is_int($outcomeSuccessorJobId)
                    || $outcomeSuccessorJobId <= 0) {
                    throw new RuntimeException(
                        'A new Canon Candidate requires its transactional successor job.'
                    );
                }
                $successor = PostprocessJobRepository::findById($outcomeSuccessorJobId);
                if (!is_array($successor)
                    || (int)($successor['novel_id'] ?? 0) !== $novelId
                    || (int)($successor['chapter_id'] ?? 0) !== $chapterId
                    || (string)($successor['revision_hash'] ?? '') !== $freshRevisionHash
                    || (string)($successor['stage'] ?? '') !== $stage
                    || PostprocessJobRepository::acceptedCandidateFromRow($successor)
                        !== $outcomeCandidateUid
                    || PostprocessJobRepository::rewriteAllowedFromRow($successor)
                    || !hash_equals(
                        PostprocessJobRepository::jobUid(
                            $chapterId,
                            $freshRevisionHash,
                            $stage,
                            $outcomeCandidateUid
                        ),
                        (string)($successor['job_uid'] ?? '')
                    )) {
                    throw new RuntimeException(
                        'The promoted Canon Candidate has an invalid successor job identity.'
                    );
                }
                $successorPolicy = PostprocessJobRepository::snapshotFromRow($successor);
                if ($successorPolicy === null
                    || $policySnapshot === null
                    || !hash_equals($policySnapshot->checksum(), $successorPolicy->checksum())) {
                    throw new RuntimeException(
                        'The promoted Canon successor job has a different frozen policy.'
                    );
                }
            } elseif ($outcomeSuccessorJobId !== null) {
                throw new RuntimeException(
                    'A post-processing successor job cannot exist without a new Canon Candidate.'
                );
            }
            $freshOwnerCandidateUid = is_string($outcomeCandidateUid)
                ? $outcomeCandidateUid
                : $acceptedCandidateUid;
            if ($freshOwnerCandidateUid !== null) {
                $freshCandidateUid = $freshChapter['canonical_candidate_uid'] ?? null;
                if (!is_string($freshCandidateUid)
                    || !hash_equals($freshOwnerCandidateUid, $freshCandidateUid)) {
                    throw new RuntimeException(
                        'Accepted post-processing Candidate does not own the fresh Canon revision.'
                    );
                }
            }

            $chapter = array_merge($chapter, $freshChapter);
            $content = $freshContent;
            $revisionHash = $freshRevisionHash;

            // P2-8 / 2026-08-17：历史章 finalize 只落摘要；有序重放成功后会写
            // memory_finalized。未重放成功时不 markCompleted，避免把「仅摘要」
            // 当成投影完成、挡住后续重放。
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $historicalDeferred = $chNum > 0 && (bool)DB::fetch(
                'SELECT id FROM chapters
                 WHERE novel_id=? AND status="completed" AND chapter_number>?
                 ORDER BY chapter_number ASC LIMIT 1',
                [$novelId, $chNum]
            );
            // 历史章在有序重放成功后会写入 memory_finalized；此时累积投影已重建，
            // 应标记投影完成，避免 worker 反复重放同一 revision。
            $replayFinalized = ChapterMemoryFinalizer::hasFinalizedRevision(
                $novelId,
                $chapterId,
                $revisionHash
            );
            if (!$historicalDeferred || $replayFinalized) {
                ProjectionRunRepository::markCompleted(
                    $novelId,
                    $chapterId,
                    $revisionHash,
                    $stage,
                    hash('sha256', $content)
                );
            }
            // Never attach a Candidate from a newly promoted revision to this
            // predecessor job: accepted_candidate_uid is immutable identity
            // for the job's own revision. The successor already carries it.
            self::markDoneOrThrow($jobId, $leaseOwner, $leaseToken, $fenceGeneration, $acceptedCandidateUid);
            return ['job_id' => $jobId, 'state' => 'done', 'revision_hash' => $revisionHash];
        } catch (WriteEnginePostprocessLeaseLostException $e) {
            error_log(sprintf('[postprocess_job:%d] lease_lost: %s', $jobId, $e->getMessage()));
            return ['job_id' => $jobId, 'state' => 'lease_lost', 'revision_hash' => $revisionHash];
        } catch (Throwable $e) {
            $state = PostprocessJobRepository::markRetryOrFailed(
                $jobId,
                $leaseOwner,
                $leaseToken,
                $fenceGeneration,
                $e->getMessage()
            );
            if ($state === null) {
                error_log(sprintf(
                    '[postprocess_job:%d] lease_lost while recording failure: %s',
                    $jobId,
                    $e->getMessage()
                ));
                return [
                    'job_id' => $jobId,
                    'state' => 'lease_lost',
                    'revision_hash' => $revisionHash,
                ];
            }
            error_log(sprintf('[postprocess_job:%d] %s: %s', $jobId, $state, $e->getMessage()));
            return ['job_id' => $jobId, 'state' => $state, 'revision_hash' => $revisionHash];
        } finally {
            if ($projectionFence !== null) {
                try {
                    NovelProjectionFence::release($projectionFence);
                } catch (Throwable $releaseError) {
                    error_log(sprintf(
                        '[postprocess_job:%d] projection fence release failed: %s',
                        $jobId,
                        $releaseError->getMessage()
                    ));
                }
            }
        }
    }

    private static function markDoneOrThrow(
        int $jobId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration,
        ?string $acceptedCandidateUid
    ): void {
        if (!PostprocessJobRepository::markDone(
            $jobId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration,
            $acceptedCandidateUid
        )) {
            throw new WriteEnginePostprocessLeaseLostException(
                'Post-processing completion lost its lease fence.'
            );
        }
    }

    /**
     * @param array<string,mixed>|null $lease
     * @return array{task_id:string,lease_owner:string,lease_token:string,fence_generation:int}|null
     */
    private static function normalizeWritingTaskLease(?array $lease): ?array
    {
        if ($lease === null) {
            return null;
        }
        foreach (['task_id', 'lease_owner', 'lease_token', 'fence_generation'] as $field) {
            if (!array_key_exists($field, $lease)) {
                throw new InvalidArgumentException(
                    "Writing-task lease carrier is missing {$field}."
                );
            }
        }

        $taskId = $lease['task_id'];
        $owner = $lease['lease_owner'];
        $token = $lease['lease_token'];
        $generation = $lease['fence_generation'];
        if (!is_string($taskId) || preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $taskId) !== 1) {
            throw new InvalidArgumentException('Writing-task lease carrier has an invalid task id.');
        }
        if (!is_string($owner) || preg_match('/^[a-zA-Z0-9_.:@-]{1,120}$/D', $owner) !== 1) {
            throw new InvalidArgumentException('Writing-task lease carrier has an invalid owner.');
        }
        if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Writing-task lease carrier has an invalid token.');
        }
        if (is_string($generation)) {
            if (preg_match('/^[1-9][0-9]*$/D', $generation) !== 1) {
                throw new InvalidArgumentException(
                    'Writing-task lease carrier has an invalid fence generation.'
                );
            }
            $normalizedGeneration = (int)$generation;
            if ((string)$normalizedGeneration !== $generation) {
                throw new InvalidArgumentException(
                    'Writing-task lease carrier fence generation is out of range.'
                );
            }
            $generation = $normalizedGeneration;
        }
        if (!is_int($generation) || $generation <= 0) {
            throw new InvalidArgumentException(
                'Writing-task lease carrier has an invalid fence generation.'
            );
        }

        return [
            'task_id' => $taskId,
            'lease_owner' => $owner,
            'lease_token' => $token,
            'fence_generation' => $generation,
        ];
    }

    /**
     * @param array<string,mixed> $chapter
     * @return array{0:?string,1:bool}
     */
    private static function resolveJobCandidateForEnqueue(
        array $chapter,
        ?WritePolicySnapshot $policySnapshot,
        string $revisionHash
    ): array {
        if ((string)($chapter['canonical_state'] ?? '') !== 'canonical') {
            return [null, false];
        }
        if ($policySnapshot === null) {
            throw new RuntimeException(
                'A canonical post-processing job requires a frozen policy.'
            );
        }
        $chapterRevisionHash = $chapter['content_revision_hash'] ?? null;
        if (!is_string($chapterRevisionHash)
            || preg_match('/^[a-f0-9]{64}$/D', $chapterRevisionHash) !== 1
            || !hash_equals($revisionHash, $chapterRevisionHash)) {
            throw new RuntimeException(
                'Canonical chapter revision does not match the post-processing identity.'
            );
        }
        $candidateUid = $chapter['canonical_candidate_uid'] ?? null;
        if (!is_string($candidateUid)
            || preg_match('/^[a-f0-9]{32}$/D', $candidateUid) !== 1) {
            throw new RuntimeException(
                'Canonical chapter is missing its accepted Candidate identity.'
            );
        }
        $candidate = DB::fetch(
            'SELECT source, lifecycle, gate_state, content_hash, policy_snapshot_hash
             FROM chapter_candidates
             WHERE candidate_uid=? AND chapter_id=? AND novel_id=? LIMIT 1',
            [
                $candidateUid,
                (int)($chapter['id'] ?? 0),
                (int)($chapter['novel_id'] ?? 0),
            ]
        );
        if (!is_array($candidate)
            || (string)($candidate['lifecycle'] ?? '') !== 'committed'
            || (string)($candidate['gate_state'] ?? '') !== 'passed'
            || !is_string($candidate['content_hash'] ?? null)
            || !hash_equals($revisionHash, $candidate['content_hash'])
            || !is_string($candidate['policy_snapshot_hash'] ?? null)
            || !hash_equals($policySnapshot->checksum(), $candidate['policy_snapshot_hash'])) {
            throw new RuntimeException(
                'Canonical post-processing Candidate ownership cannot be authenticated.'
            );
        }
        $source = (string)($candidate['source'] ?? '');
        if (!in_array($source, ['initial', 'rewrite'], true)) {
            throw new RuntimeException('Canonical Candidate source is invalid.');
        }
        return [$candidateUid, $source === 'initial'];
    }

    /**
     * @param array<string,mixed> $chapter
     */
    private static function authenticateJobCandidate(
        ?WritePolicySnapshot $policySnapshot,
        array $chapter,
        string $revisionHash,
        ?string $acceptedCandidateUid,
        bool $rewriteAllowed
    ): void {
        if ((string)($chapter['canonical_state'] ?? '') !== 'canonical') {
            if ($acceptedCandidateUid !== null || $rewriteAllowed) {
                throw new RuntimeException(
                    'A legacy post-processing job cannot claim Candidate ownership.'
                );
            }
            return;
        }
        if ($acceptedCandidateUid === null || $policySnapshot === null) {
            throw new RuntimeException(
                'Canonical post-processing job is missing Candidate ownership.'
            );
        }
        $chapterCandidateUid = $chapter['canonical_candidate_uid'] ?? null;
        if (!is_string($chapterCandidateUid)
            || !hash_equals($acceptedCandidateUid, $chapterCandidateUid)) {
            throw new RuntimeException(
                'Post-processing job Candidate does not own the current Canon.'
            );
        }
        $candidate = DB::fetch(
            'SELECT source, lifecycle, gate_state, content_hash, policy_snapshot_hash
             FROM chapter_candidates
             WHERE candidate_uid=? AND chapter_id=? AND novel_id=? LIMIT 1',
            [
                $acceptedCandidateUid,
                (int)($chapter['id'] ?? 0),
                (int)($chapter['novel_id'] ?? 0),
            ]
        );
        if (!is_array($candidate)
            || (string)($candidate['lifecycle'] ?? '') !== 'committed'
            || (string)($candidate['gate_state'] ?? '') !== 'passed'
            || !is_string($candidate['content_hash'] ?? null)
            || !hash_equals($revisionHash, $candidate['content_hash'])
            || !is_string($candidate['policy_snapshot_hash'] ?? null)
            || !hash_equals($policySnapshot->checksum(), $candidate['policy_snapshot_hash'])) {
            throw new RuntimeException(
                'Post-processing job Candidate metadata is inconsistent with Canon.'
            );
        }
        $expectedRewriteAllowed = (string)($candidate['source'] ?? '') === 'initial';
        if ($expectedRewriteAllowed !== $rewriteAllowed) {
            throw new RuntimeException(
                'Post-processing rewrite eligibility disagrees with Candidate source.'
            );
        }
    }

    /**
     * Resolve the one snapshot that will be persisted with a new job.
     *
     * A snapshot committed beside chapter content is authoritative. An
     * explicit caller snapshot must agree with it; it may only supply the
     * missing value for a non-Canon legacy/none row. Canon also binds the
     * projection to its committed content revision hash.
     *
     * @param array<string,mixed> $storedChapter
     */
    private static function resolveSnapshotForEnqueue(
        array $storedChapter,
        ?WritePolicySnapshot $explicit,
        string $revisionHash
    ): ?WritePolicySnapshot {
        if (preg_match('/^[a-f0-9]{64}$/D', $revisionHash) !== 1) {
            throw new InvalidArgumentException('Post-processing revision hash is invalid.');
        }

        $canonicalState = (string)($storedChapter['canonical_state'] ?? '');
        $json = $storedChapter['write_policy_snapshot_json'] ?? null;
        $storedSnapshot = null;
        if ($json !== null) {
            if (!is_string($json) || $json === '') {
                throw new RuntimeException('Stored chapter has an invalid frozen policy snapshot.');
            }
            try {
                $storedSnapshot = WritePolicySnapshot::fromJson($json);
            } catch (Throwable $e) {
                throw new RuntimeException('Stored chapter has an invalid frozen policy snapshot.', 0, $e);
            }
        }

        if ($canonicalState === 'canonical' && $storedSnapshot === null) {
            throw new RuntimeException('Canonical chapter is missing its frozen policy snapshot.');
        }

        if (
            $storedSnapshot !== null
            && $explicit !== null
            && !hash_equals($storedSnapshot->checksum(), $explicit->checksum())
        ) {
            throw new RuntimeException(
                'Explicit frozen policy does not match the chapter frozen policy snapshot.'
            );
        }

        if ($canonicalState === 'canonical') {
            $contentRevisionHash = $storedChapter['content_revision_hash'] ?? null;
            if (
                !is_string($contentRevisionHash)
                || preg_match('/^[a-f0-9]{64}$/D', $contentRevisionHash) !== 1
            ) {
                throw new RuntimeException('Canonical chapter has an invalid content revision hash.');
            }
            if (!hash_equals($revisionHash, $contentRevisionHash)) {
                throw new RuntimeException(
                    'Canonical chapter content does not match its committed revision hash.'
                );
            }
        }

        if ($storedSnapshot !== null) {
            return $storedSnapshot;
        }
        if ($explicit !== null) {
            return $explicit;
        }

        // Compatibility boundary for historical pre-M60 rows. Such chapters
        // have canonical_state absent/none/legacy and no frozen policy. They
        // continue through the pre-snapshot post-processing behavior.
        return null;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed>|null $chapter
     */
    private static function assertRunnableSnapshot(
        ?WritePolicySnapshot $snapshot,
        array $job,
        ?array $chapter
    ): void {
        if ($snapshot !== null) {
            return;
        }

        if ($chapter !== null && array_key_exists('canonical_state', $chapter)) {
            $canonicalState = (string)$chapter['canonical_state'];
        } else {
            $canonicalState = (string)(DB::fetchColumn(
                'SELECT canonical_state FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                [(int)($job['chapter_id'] ?? 0), (int)($job['novel_id'] ?? 0)]
            ) ?? '');
        }

        if ($canonicalState === 'canonical') {
            throw new RuntimeException('Canonical post-processing job is missing its frozen policy snapshot.');
        }

        // Explicit legacy compatibility path: pre-M60 non-Canon jobs keep the
        // historical post-processing behavior. This runner does not synthesize
        // a snapshot for them; downstream legacy behavior remains unchanged.
    }

    /**
     * Re-authenticate a Canon row immediately before post-processing. Neither
     * the job nor the chapter is trusted alone: their frozen policies and all
     * three revision representations must agree.
     *
     * @param array<string,mixed> $chapter
     */
    private static function authenticateCanonicalChapter(
        ?WritePolicySnapshot $jobSnapshot,
        array $chapter,
        string $content,
        string $jobRevisionHash
    ): void {
        if ((string)($chapter['canonical_state'] ?? '') !== 'canonical') {
            return;
        }
        if ($jobSnapshot === null) {
            throw new RuntimeException('Canonical post-processing job is missing its frozen policy snapshot.');
        }

        $chapterJson = $chapter['write_policy_snapshot_json'] ?? null;
        if (!is_string($chapterJson) || $chapterJson === '') {
            throw new RuntimeException('Canonical chapter is missing its frozen policy snapshot.');
        }
        try {
            $chapterSnapshot = WritePolicySnapshot::fromJson($chapterJson);
        } catch (Throwable $e) {
            throw new RuntimeException('Canonical chapter has an invalid frozen policy snapshot.', 0, $e);
        }
        if (!hash_equals($jobSnapshot->checksum(), $chapterSnapshot->checksum())) {
            throw new RuntimeException(
                'Post-processing job frozen policy does not match the Canon chapter snapshot.'
            );
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $jobRevisionHash) !== 1) {
            throw new RuntimeException('Post-processing job has an invalid revision hash.');
        }
        $contentRevisionHash = $chapter['content_revision_hash'] ?? null;
        if (
            !is_string($contentRevisionHash)
            || preg_match('/^[a-f0-9]{64}$/D', $contentRevisionHash) !== 1
        ) {
            throw new RuntimeException('Canonical chapter has an invalid content revision hash.');
        }

        $contentHash = hash('sha256', $content);
        if (
            !hash_equals($jobRevisionHash, $contentHash)
            || !hash_equals($jobRevisionHash, $contentRevisionHash)
        ) {
            throw new RuntimeException(
                'Canonical chapter content revision does not match the post-processing job.'
            );
        }
    }

    /** 将工作流续租与限频的后处理、写作任务租约心跳组合执行。 */
    private static function makeLeaseHeartbeat(
        int $jobId,
        string $leaseOwner,
        string $leaseToken,
        int $fenceGeneration,
        ?array $writingTaskLease,
        ?callable $workflowHeartbeat = null
    ): callable {
        $last = 0;
        return function () use (
            &$last,
            $jobId,
            $leaseOwner,
            $leaseToken,
            $fenceGeneration,
            $writingTaskLease,
            $workflowHeartbeat
        ): void {
            if ($workflowHeartbeat !== null) {
                try {
                    $workflowHeartbeat();
                } catch (Throwable $exception) {
                    throw new WriteEnginePostprocessLeaseLostException(
                        'Workflow lease heartbeat failed during post-processing.',
                        0,
                        $exception
                    );
                }
            }
            $now = time();
            if ($now - $last < 60) return;
            $last = $now;
            try {
                $extended = PostprocessJobRepository::extendLease(
                    $jobId,
                    $leaseOwner,
                    $leaseToken,
                    $fenceGeneration,
                    1800
                );
            } catch (Throwable $e) {
                error_log("[postprocess_job:{$jobId}] lease heartbeat failed: " . $e->getMessage());
                throw new WriteEnginePostprocessLeaseLostException(
                    'Post-processing lease heartbeat could not authenticate ownership.',
                    0,
                    $e
                );
            }
            if (!$extended) {
                throw new WriteEnginePostprocessLeaseLostException(
                    'Post-processing heartbeat lost its lease fence.'
                );
            }

            if ($writingTaskLease === null) {
                return;
            }
            try {
                $taskExtended = WritingTaskRepository::heartbeat(
                    $writingTaskLease['task_id'],
                    'postprocessing',
                    null,
                    null,
                    1800,
                    $writingTaskLease['lease_owner'],
                    $writingTaskLease['lease_token'],
                    $writingTaskLease['fence_generation']
                );
            } catch (Throwable $e) {
                error_log("[postprocess_job:{$jobId}] writing-task heartbeat failed: " . $e->getMessage());
                throw new WriteEnginePostprocessLeaseLostException(
                    'Writing-task lease heartbeat could not authenticate ownership.',
                    0,
                    $e
                );
            }
            if (!$taskExtended) {
                throw new WriteEnginePostprocessLeaseLostException(
                    'Post-processing lost its explicit writing-task lease fence.'
                );
            }
        };
    }

    private static function defaultWorkerId(string $kind): string
    {
        $host = gethostname() ?: 'localhost';
        return sprintf('%s:%s:%d', $kind, $host, getmypid());
    }
}
