<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/NovelProjectionFence.php';

/**
 * Raised when a user mutation races with an active chapter writer.
 */
class ChapterMutationConflict extends RuntimeException {}

/**
 * Central boundary for user-initiated chapter mutations.
 *
 * A chapter's content is the source of several rebuildable projections.  Updating
 * only chapters.content leaves summaries, RAG atoms, foreshadowing lifecycle data,
 * quality reports and graph edges pointing at text that no longer exists.  This
 * service changes the source row and invalidates projections in one transaction.
 *
 * It intentionally does not claim to rebuild cumulative character cards or Story
 * Bible nodes: those tables do not carry a complete per-revision event history.
 * Instead novel_state is rewound and receives an explicit rebuild warning so the
 * next prompt does not treat the cumulative state as authoritative.
 */
final class ChapterMutationService
{
    /** @var array<string,mixed> Chapter columns derived from the current body revision. */
    private const CONTENT_DERIVED_DEFAULTS = [
        'chapter_summary'          => null,
        'used_tropes'              => null,
        'quality_score'            => null,
        'rewritten'                => 0,
        'critic_scores'            => null,
        'human_critic_scores'      => null,
        'calibrated_critic_scores' => null,
        'ai_pattern_issues'        => null,
        'iterations_used'          => 0,
        'total_improvement'        => 0.0,
        'iterative_history'        => null,
        'iteration_evaluation'     => null,
        'rewrite_time'             => null,
        'cognitive_load'           => null,
        'style_drift_report'       => null,
        'gate_results'             => null,
        'tokens_used'              => 0,
        'cache_hit_tokens'         => 0,
        'duration_ms'              => 0,
        'emotion_density'          => null,
        'emotion_score'            => null,
        'actual_cool_point_types'  => null,
        'actual_opening_type'      => null,
        'hook_resolved'            => null,
        // Harness identity belongs to the exact body revision. Any direct user
        // mutation must either establish a new legacy Canon below or clear it.
        'canonical_state'          => 'none',
        'content_revision_hash'    => null,
        'canonicalized_at'         => null,
        'write_policy_snapshot_json' => null,
        'canonical_candidate_uid'  => null,
    ];

    private static int $savepointSequence = 0;

    /**
     * Explicitly remove immutable Harness rows that use RESTRICT semantics
     * before their Candidate/Chapter parents are deleted. The caller must own
     * the surrounding transaction; this method verifies and locks every target
     * chapter so an ID from another novel can never enter the deletion scope.
     *
     * @param list<int> $chapterIds
     * @return array<string,int>
     */
    public static function purgeChapterDeletionDependencies(
        int $novelId,
        array $chapterIds,
        bool $purgeAllNovelBranchMemory = false
    ): array {
        $pdo = DB::getPdo();
        if ($novelId <= 0 || !$pdo->inTransaction()) {
            throw new LogicException(
                'Harness chapter dependency cleanup requires an active owned transaction.'
            );
        }

        $ids = [];
        foreach ($chapterIds as $chapterId) {
            if (!is_int($chapterId) || $chapterId <= 0) {
                throw new InvalidArgumentException('Chapter deletion IDs must be positive integers.');
            }
            $ids[$chapterId] = $chapterId;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return $purgeAllNovelBranchMemory
                ? ['branch_memory_records' => DB::delete(
                    'branch_memory_records',
                    'novel_id=?',
                    [$novelId]
                )]
                : [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $ownedRows = DB::fetchAll(
            "SELECT id FROM chapters WHERE novel_id=? AND id IN ({$placeholders}) FOR UPDATE",
            array_merge([$novelId], $ids)
        );
        $ownedIds = array_map(static fn (array $row): int => (int)$row['id'], $ownedRows);
        sort($ownedIds, SORT_NUMERIC);
        if ($ownedIds !== $ids) {
            throw new RuntimeException('Chapter deletion scope changed or crosses novel ownership.');
        }

        $novelAndIds = array_merge([$novelId], $ids);
        $report = [];

        // Merge receipts restrict Approval, Candidate, Chapter, Branch and both
        // Revision parents, so they are always the first durable rows removed.
        $report['branch_merge_commit_ledger'] = DB::execute(
            "DELETE FROM branch_merge_commit_ledger WHERE chapter_id IN ({$placeholders})",
            $ids
        );
        $report['chapter_canonical_heads'] = DB::execute(
            "DELETE FROM chapter_canonical_heads WHERE novel_id=? AND chapter_id IN ({$placeholders})",
            $novelAndIds
        );
        $report['chapter_branch_heads'] = DB::execute(
            "DELETE FROM chapter_branch_heads WHERE chapter_id IN ({$placeholders})",
            $ids
        );
        $report['branch_memory_records'] = $purgeAllNovelBranchMemory
            ? DB::delete('branch_memory_records', 'novel_id=?', [$novelId])
            : DB::execute(
                'DELETE bmr FROM branch_memory_records bmr'
                . ' INNER JOIN chapter_revisions cr'
                . ' ON cr.revision_uid=bmr.source_revision_uid'
                . " WHERE cr.novel_id=? AND cr.chapter_id IN ({$placeholders})",
                $novelAndIds
            );
        $report['chapter_revisions'] = DB::execute(
            "DELETE FROM chapter_revisions WHERE novel_id=? AND chapter_id IN ({$placeholders})",
            $novelAndIds
        );

        // The M67 ledgers/jobs must precede their Candidate/Chapter parents.
        $report['chapter_commit_ledger'] = DB::execute(
            "DELETE FROM chapter_commit_ledger WHERE chapter_id IN ({$placeholders})",
            $ids
        );
        $report['approval_requests'] = DB::execute(
            "DELETE FROM approval_requests WHERE novel_id=? AND chapter_id IN ({$placeholders})",
            $novelAndIds
        );
        $report['postprocess_jobs'] = DB::execute(
            "DELETE FROM postprocess_jobs WHERE novel_id=? AND chapter_id IN ({$placeholders})",
            $novelAndIds
        );
        $report['chapter_candidates'] = DB::execute(
            "DELETE FROM chapter_candidates WHERE novel_id=? AND chapter_id IN ({$placeholders})",
            $novelAndIds
        );

        return $report;
    }

    /**
     * Novel deletion additionally removes the archive ledger that RESTRICTs
     * generation_runs, which themselves cascade from the Novel parent.
     *
     * @param list<int> $chapterIds
     * @return array<string,int>
     */
    public static function purgeNovelDeletionDependencies(
        int $novelId,
        array $chapterIds
    ): array {
        $pdo = DB::getPdo();
        if ($novelId <= 0 || !$pdo->inTransaction()) {
            throw new LogicException(
                'Harness novel dependency cleanup requires an active owned transaction.'
            );
        }
        $report = self::purgeChapterDeletionDependencies($novelId, $chapterIds, true);
        $report['harness_retention_archives'] = DB::execute(
            'DELETE hra FROM harness_retention_archives hra'
            . ' INNER JOIN generation_runs gr ON gr.id=hra.run_id'
            . ' WHERE gr.novel_id=?',
            [$novelId]
        );
        return $report;
    }

    /**
     * Atomically update one chapter and invalidate affected projections.
     *
     * Supported options:
     *  - backup_version (bool, default true when content changes)
     *  - strict_backup (bool, default false; fail the mutation if its backup fails)
     *  - prevent_writing (bool, default true)
     *  - force_content_invalidation (bool)
     *  - force_outline_invalidation (bool)
     *  - expected_content_hash (SHA-256 string; optimistic concurrency guard)
     *  - allowed_statuses (string[]; reject the mutation unless the locked row
     *    currently has one of these statuses)
     *  - reason (short diagnostic label)
     *
     * @return array{affected:int,chapter:array,content_changed:bool,outline_changed:bool,invalidated:array}
     */
    public static function mutateChapter(
        int $chapterId,
        int $novelId,
        array $updates,
        array $options = []
    ): array {
        if ($chapterId <= 0 || $novelId <= 0) {
            throw new InvalidArgumentException('Invalid chapter mutation target');
        }
        if ($updates === []) {
            throw new InvalidArgumentException('Chapter mutation has no updates');
        }

        $projectionFence = NovelProjectionFence::acquire($novelId);
        try {
            [$pdo, $ownTx, $savepoint] = self::beginScope('mutate_' . $chapterId);
            try {
            $chapter = DB::fetch(
                'SELECT * FROM chapters WHERE id=? AND novel_id=? FOR UPDATE',
                [$chapterId, $novelId]
            );
            if (!$chapter) {
                throw new RuntimeException('章节不存在');
            }

            $preventWriting = (bool)($options['prevent_writing'] ?? true);

            $allowedStatuses = array_values(array_filter(
                (array)($options['allowed_statuses'] ?? []),
                static fn($status): bool => is_string($status)
                    && preg_match('/^[a-z_]{1,24}$/D', $status) === 1
            ));

            // 审计修复 R8（2026-08-10）：原代码 prevent_writing 检查先于 allowed_statuses，
            // 若 caller 同时设置 prevent_writing=true 与 allowed_statuses=['writing']，
            // 前者立即抛异常，后者永不生效。改为：allowed_statuses 显式包含 'writing'
            // 时视为 caller 已确认要在写作中修改，跳过 prevent_writing 硬阻断。
            $writingExplicitlyAllowed = in_array('writing', $allowedStatuses, true);

            if ($preventWriting
                && !$writingExplicitlyAllowed
                && ($chapter['status'] ?? '') === 'writing') {
                throw new ChapterMutationConflict('章节正在写作中，无法修改');
            }

            if ($allowedStatuses !== []
                && !in_array((string)($chapter['status'] ?? ''), $allowedStatuses, true)) {
                throw new ChapterMutationConflict('章节状态已变化，本次修改已取消');
            }

            $expectedHash = trim((string)($options['expected_content_hash'] ?? ''));
            if ($expectedHash !== '' && !hash_equals(
                $expectedHash,
                hash('sha256', (string)($chapter['content'] ?? ''))
            )) {
                throw new ChapterMutationConflict('章节正文已被其他操作修改');
            }

            $contentChanged = (bool)($options['force_content_invalidation'] ?? false);
            if (array_key_exists('content', $updates)) {
                $contentChanged = $contentChanged
                    || (string)($chapter['content'] ?? '') !== (string)$updates['content'];
            }

            $mutatedContent = (string)($updates['content'] ?? $chapter['content'] ?? '');
            $mutatedStatus = (string)($updates['status'] ?? $chapter['status'] ?? '');
            // Accepting already-stored prose as completed creates a new source
            // revision boundary even when the bytes are identical. In
            // particular, Excel overwrite may promote an outlined/failed row
            // without changing its body; that row still needs a complete legacy
            // Canon identity and its old projections must not survive.
            $completedRevisionAccepted = $mutatedContent !== ''
                && $mutatedStatus === 'completed'
                && (string)($chapter['status'] ?? '') !== 'completed';
            $contentInvalidated = $contentChanged || $completedRevisionAccepted;

            $outlineChanged = (bool)($options['force_outline_invalidation'] ?? false);
            if (array_key_exists('outline', $updates)) {
                $outlineChanged = $outlineChanged
                    || (string)($chapter['outline'] ?? '') !== (string)$updates['outline'];
            }

            if ($contentChanged && (bool)($options['backup_version'] ?? true)) {
                backupChapterVersion($chapterId, (bool)($options['strict_backup'] ?? false));
            }

            if ($contentInvalidated) {
                // Invalidation wins over caller-supplied derived values.  A later
                // explicit post-process may repopulate them from the new revision.
                $updates = array_merge($updates, self::CONTENT_DERIVED_DEFAULTS);
                if ($mutatedContent !== '' && $mutatedStatus === 'completed') {
                    // A deliberate human/API replacement of completed prose is
                    // authoritative but has no model Candidate/policy lineage.
                    // Represent it explicitly as legacy Canon, never as the old
                    // Candidate-owned revision.
                    $updates['canonical_state'] = 'legacy';
                    $updates['content_revision_hash'] = hash('sha256', $mutatedContent);
                    // canonicalized_at is provenance for a real Candidate ->
                    // canonical promotion. Legacy prose has no such transition.
                    $updates['canonicalized_at'] = null;
                }
            }
            if ($outlineChanged) {
                $updates['synopsis_id'] = null;
            }

            $where = 'id=? AND novel_id=?';
            $params = [$chapterId, $novelId];
            // 审计修复 R8-followup（2026-08-13）：R8 让 allowed_statuses=['writing']
            // 跳过状态抛错，但原 WHERE 仍拼接 status NOT IN ("writing")，导致 writing
            // 状态章被状态检查放行却被 UPDATE WHERE 排除，$affected 静默为 0。此处与
            // R8 对齐：仅当未显式允许 writing 时才在 WHERE 排除 writing。
            if ($preventWriting && !$writingExplicitlyAllowed) {
                $where .= ' AND status NOT IN ("writing")';
            }
            $affected = DB::update('chapters', $updates, $where, $params);

            $invalidated = [];
            if ($contentInvalidated) {
                $invalidated = self::invalidateContentInternal(
                    $novelId,
                    (int)$chapter['chapter_number'],
                    $chapterId,
                    false,
                    (string)($options['reason'] ?? 'manual_edit')
                );
            }
            if ($outlineChanged) {
                $invalidated['chapter_synopses'] = DB::delete(
                    'chapter_synopses',
                    'novel_id=? AND chapter_number=?',
                    [$novelId, (int)$chapter['chapter_number']]
                );
            }

            // Publish the repair obligation with the new source revision. A
            // process dying after commit must not leave accepted human prose
            // without any durable way to rebuild its dependent context.
            $postprocessJobId = null;
            if ($contentInvalidated && $mutatedStatus === 'completed' && $mutatedContent !== '') {
                require_once __DIR__ . '/tasks/PostprocessJobRepository.php';
                $postprocessJobId = PostprocessJobRepository::enqueue(
                    $novelId, $chapterId, hash('sha256', $mutatedContent),
                    'full', 3, null, null, false
                );
            }

            self::finishScope($pdo, $ownTx, $savepoint);
            if (function_exists('clearChapterCache')) {
                clearChapterCache($chapterId, $novelId);
            }

            return [
                'affected'        => $affected,
                'chapter'         => $chapter,
                'content_changed' => $contentChanged,
                'outline_changed' => $outlineChanged,
                'invalidated'     => $invalidated,
                'postprocess_job_id' => $postprocessJobId,
            ];
            } catch (Throwable $e) {
                self::rollbackScope($pdo, $ownTx, $savepoint);
                throw $e;
            }
        } finally {
            NovelProjectionFence::release($projectionFence);
        }
    }

    /**
     * 重置章节（章节列表的“取消 / 重置”）：清空正文、回到 outlined、释放写作认领。
     *
     * 审计整改 C1（2026-10-31）：只在章节仍处于调用方读到的状态时生效，判定与更新在同一行锁内完成。
     *   - 读到的不是写作中、期间被写作认领：冲突，不清空正在写的章节（调用方也没有发过取消信号）；
     *   - 读到写作中、期间已写完或释放：冲突，不清空刚写好的正文。
     * 写作中的章节由调用方先发取消信号；worker 的最终写回仍受 status + claim_token 栅栏保护。
     */
    public static function resetChapter(int $chapterId, int $novelId, string $observedStatus): array
    {
        return self::mutateChapter($chapterId, $novelId, [
            'content'     => '',
            'words'       => 0,
            'status'      => 'outlined',
            'claim_token' => '',
        ], [
            'backup_version' => true,
            // allowed_statuses=['writing'] 时 mutateChapter 会放行写作中的章节（等同原先的 prevent_writing=false）
            'prevent_writing' => $observedStatus !== 'writing',
            'allowed_statuses' => [$observedStatus],
            'force_content_invalidation' => true,
            'reason' => 'reset_chapter',
        ]);
    }

    /**
     * Delete a chapter and every projection that can be tied to it safely.
     * The caller remains responsible for refreshing aggregate novel word counts.
     */
    public static function deleteChapter(int $chapterId, int $novelId): array
    {
        $projectionFence = NovelProjectionFence::acquire($novelId);
        try {
            [$pdo, $ownTx, $savepoint] = self::beginScope('delete_' . $chapterId);
            try {
            $chapter = DB::fetch(
                'SELECT * FROM chapters WHERE id=? AND novel_id=? FOR UPDATE',
                [$chapterId, $novelId]
            );
            if (!$chapter) {
                throw new RuntimeException('章节不存在');
            }
            if (($chapter['status'] ?? '') === 'writing') {
                throw new ChapterMutationConflict('该章节正在写作中，请先取消写作再删除');
            }

            $chapterNumber = (int)$chapter['chapter_number'];
            $invalidated = self::invalidateContentInternal(
                $novelId,
                $chapterNumber,
                $chapterId,
                false,
                'delete_chapter'
            );
            $invalidated['chapter_synopses'] = DB::delete(
                'chapter_synopses',
                'novel_id=? AND chapter_number=?',
                [$novelId, $chapterNumber]
            );
            $invalidated['chapter_versions'] = DB::delete(
                'chapter_versions',
                'chapter_id=?',
                [$chapterId]
            );
            foreach (self::purgeChapterDeletionDependencies($novelId, [$chapterId])
                as $table => $deleted) {
                $invalidated[$table] = ($invalidated[$table] ?? 0) + $deleted;
            }
            DB::delete('chapters', 'id=? AND novel_id=?', [$chapterId, $novelId]);

            self::finishScope($pdo, $ownTx, $savepoint);
            if (function_exists('clearChapterCache')) {
                clearChapterCache($chapterId, $novelId);
            }

            return ['chapter' => $chapter, 'invalidated' => $invalidated];
            } catch (Throwable $e) {
                self::rollbackScope($pdo, $ownTx, $savepoint);
                throw $e;
            }
        } finally {
            NovelProjectionFence::release($projectionFence);
        }
    }

    /**
     * Bulk invalidation used before clearing a chapter range.  It removes only
     * chapter-derived projections; user-authored outlines/settings are untouched.
     */
    public static function invalidateNovelFromChapter(
        int $novelId,
        int $fromChapter = 1,
        string $reason = 'bulk_reset'
    ): array {
        if ($novelId <= 0 || $fromChapter <= 0) {
            throw new InvalidArgumentException('Invalid novel invalidation range');
        }

        $projectionFence = NovelProjectionFence::acquire($novelId);
        try {
            [$pdo, $ownTx, $savepoint] = self::beginScope('invalidate_' . $novelId);
            try {
            $report = self::invalidateContentInternal(
                $novelId,
                $fromChapter,
                null,
                true,
                $reason
            );
            self::finishScope($pdo, $ownTx, $savepoint);
            if (function_exists('clearNovelCache')) {
                clearNovelCache($novelId);
            }
            return $report;
            } catch (Throwable $e) {
                self::rollbackScope($pdo, $ownTx, $savepoint);
                throw $e;
            }
        } finally {
            NovelProjectionFence::release($projectionFence);
        }
    }

    /**
     * Remove only projections produced by one generated chapter before a fresh,
     * atomic MemoryEngine ingest.  Unlike a user mutation this does not discard
     * arc summaries, PID history or global constraint state.
     *
     * The caller may already own a transaction; in that case this method uses a
     * savepoint so reset + subsequent ingest can commit or roll back together.
     * Memory-only replay preserves graph/vector/scene completion and phrase
     * definitions because MemoryEngine does not reconstruct those projections.
     */
    public static function resetGeneratedProjectionsForReingest(
        int $novelId,
        int $chapterNumber,
        int $chapterId,
        bool $memoryOnly = false
    ): array {
        if ($novelId <= 0 || $chapterNumber <= 0 || $chapterId <= 0) {
            throw new InvalidArgumentException('Invalid generated projection target');
        }

        $projectionFence = NovelProjectionFence::acquire($novelId);
        try {
            [$pdo, $ownTx, $savepoint] = self::beginScope('reingest_' . $chapterId);
            try {
            $report = [
                // 审计修复 P1-3（2026-08-08）：character_trait 不参与按章清理。
                // ingestChapter 的特征去重会把旧章 atom 的 source_chapter 前移到本章
                // （MemoryEngine.php:208-216）；若按 source_chapter 全类型删除，
                // 重写/重跑本章会把诞生于更早章节、只是被本章「确认过」的长期特征
                // 一并删除，历史记忆永久丢失。character_trait 按 (character_name,
                // trait_key) UPDATE 去重，重 ingest 幂等无需清理；plot_detail/cool_point
                // 是直接 INSERT 的非幂等类型，必须按章清理防重复——与
                // AtomRepo::deleteByChapterAndType 的保护策略一致。
                'memory_atoms' => DB::delete(
                    'memory_atoms',
                    'novel_id=? AND source_chapter=? AND atom_type IN ("plot_detail","cool_point")',
                    [$novelId, $chapterNumber]
                ),
                'character_emotions' => DB::delete(
                    'character_emotion_history',
                    'novel_id=? AND chapter_number=?',
                    [$novelId, $chapterNumber]
                ),
                'finalization_markers' => DB::delete(
                    'writing_logs',
                    'novel_id=? AND chapter_id=? AND action="memory_finalized"',
                    [$novelId, $chapterId]
                ),
            ];

            // Ordered memory replay does not rebuild embeddings, graph edges,
            // or scene templates. Preserve those unchanged chapter projections
            // and their full-postprocess completion marker. Content mutations
            // keep the default full reset, including A -> B -> A invalidation.
            if (!$memoryOnly) {
                $report += [
                    'novel_embeddings' => DB::delete(
                        'novel_embeddings',
                        'novel_id=? AND source_type="chapter" AND source_id=?',
                        [$novelId, $chapterId]
                    ),
                    'story_relations' => DB::delete(
                        'story_relations',
                        'novel_id=? AND source_chapter=?',
                        [$novelId, $chapterNumber]
                    ),
                    'scene_templates' => DB::delete(
                        'novel_scene_templates',
                        'novel_id=? AND chapter_number=?',
                        [$novelId, $chapterNumber]
                    ),
                    'projection_runs' => DB::delete(
                        'chapter_projection_runs',
                        'novel_id=? AND chapter_id=?',
                        [$novelId, $chapterId]
                    ),
                ];
            }

            $report += self::invalidateForeshadowing($novelId, $chapterNumber, false);
            $report += self::invalidateCatchphrases($novelId, $chapterNumber, false, $memoryOnly);

            // Rewind only when this chapter is exactly the latest ingest.  A state
            // newer than it may belong to intentionally out-of-order content and
            // cannot be safely reconstructed here.
            $report['novel_state_rewound'] = DB::execute(
                'UPDATE novel_state
                 SET story_momentum=NULL, current_location=NULL, location_chapter=NULL,
                     location_transition=NULL, current_arc_summary=NULL,
                     last_ingested_chapter=?
                 WHERE novel_id=? AND last_ingested_chapter=?',
                [max(0, $chapterNumber - 1), $novelId, $chapterNumber]
            );

            self::finishScope($pdo, $ownTx, $savepoint);
            return $report;
            } catch (Throwable $e) {
                self::rollbackScope($pdo, $ownTx, $savepoint);
                throw $e;
            }
        } finally {
            NovelProjectionFence::release($projectionFence);
        }
    }

    /** @return array<string,int|bool> */
    private static function invalidateContentInternal(
        int $novelId,
        int $chapterNumber,
        ?int $chapterId,
        bool $fromChapter,
        string $reason
    ): array {
        $op = $fromChapter ? '>=' : '=';
        $report = [];

        // 审计修复 P1-3（2026-08-08）：与 resetGeneratedProjectionsForReingest 同策略——
        // character_trait 是跨章累积的长期记忆（去重时 source_chapter 会被前移），
        // 按章删除会误伤诞生于更早章节的特征；仅清理非幂等的 plot_detail/cool_point。
        // 注意：用户修改导致某特征在新正文中消失时，该特征 atom 会保留（安全网取舍，
        // 与 AtomRepo::deleteByChapterAndType 注释一致——丢失历史累积的代价更高）。
        $report['memory_atoms'] = DB::delete(
            'memory_atoms',
            "novel_id=? AND source_chapter {$op} ? AND atom_type IN (\"plot_detail\",\"cool_point\")",
            [$novelId, $chapterNumber]
        );

        if ($chapterId !== null) {
            $report['novel_embeddings'] = DB::delete(
                'novel_embeddings',
                'novel_id=? AND source_type="chapter" AND source_id=?',
                [$novelId, $chapterId]
            );
        } else {
            $report['novel_embeddings'] = DB::execute(
                'DELETE ne FROM novel_embeddings ne
                 INNER JOIN chapters c ON c.id=ne.source_id AND c.novel_id=ne.novel_id
                 WHERE ne.novel_id=? AND ne.source_type="chapter" AND c.chapter_number>=?',
                [$novelId, $chapterNumber]
            );
        }

        $report['character_emotions'] = DB::delete(
            'character_emotion_history',
            "novel_id=? AND chapter_number {$op} ?",
            [$novelId, $chapterNumber]
        );
        $report['story_relations'] = DB::delete(
            'story_relations',
            "novel_id=? AND source_chapter {$op} ?",
            [$novelId, $chapterNumber]
        );
        $report['scene_templates'] = DB::delete(
            'novel_scene_templates',
            "novel_id=? AND chapter_number {$op} ?",
            [$novelId, $chapterNumber]
        );
        $report['consistency_logs'] = DB::delete(
            'consistency_logs',
            "novel_id=? AND chapter_number {$op} ?",
            [$novelId, $chapterNumber]
        );
        $report['constraint_logs'] = DB::delete(
            'constraint_logs',
            "novel_id=? AND chapter_number {$op} ?",
            [$novelId, $chapterNumber]
        );
        $report['agent_outcomes'] = DB::delete(
            'agent_directive_outcomes',
            "novel_id=? AND chapter_number {$op} ?",
            [$novelId, $chapterNumber]
        );
        $report['finalization_markers'] = $chapterId !== null
            ? DB::delete(
                'writing_logs',
                'novel_id=? AND chapter_id=? AND action="memory_finalized"',
                [$novelId, $chapterId]
            )
            : DB::execute(
                'DELETE wl FROM writing_logs wl
                 INNER JOIN chapters c ON c.id=wl.chapter_id AND c.novel_id=wl.novel_id
                 WHERE wl.novel_id=? AND wl.action="memory_finalized" AND c.chapter_number>=?',
                [$novelId, $chapterNumber]
            );

        // These are snapshots/cumulative controllers.  A prior chapter change
        // invalidates later snapshots, and their contribution cannot be subtracted.
        $report['arc_summaries'] = DB::delete(
            'arc_summaries',
            'novel_id=? AND chapter_to>=?',
            [$novelId, $chapterNumber]
        );
        $report['novel_audits'] = DB::delete(
            'novel_audits',
            'novel_id=? AND chapter_number>=?',
            [$novelId, $chapterNumber]
        );
        $report['constraint_state'] = DB::delete('constraint_state', 'novel_id=?', [$novelId]);
        $report['pid_states'] = DB::delete('pid_states', 'novel_id=?', [$novelId]);

        // 审计修复 P1-7（2026-08-08）：正文变更必须同步失效「投影账本」。
        // chapter_projection_runs 的 completed 记录会让 PostprocessRunner::runClaimed
        // 对同一 revision hash 直接 markDone 短路（假完成）；postprocess_jobs 的
        // failed 行会让 enqueue 的 ON DUPLICATE 仅更新 max_attempts（永不复活）。
        // 正文已失效时两本账同步删除，否则版本回滚后记忆永久缺失且无恢复入口。
        if ($chapterId !== null && !$fromChapter) {
            $report['projection_runs'] = DB::delete(
                'chapter_projection_runs', 'novel_id=? AND chapter_id=?', [$novelId, $chapterId]
            );
            $report['postprocess_jobs'] = DB::delete(
                'postprocess_jobs', 'novel_id=? AND chapter_id=?', [$novelId, $chapterId]
            );
        } else {
            $report['projection_runs'] = DB::execute(
                'DELETE pr FROM chapter_projection_runs pr
                 INNER JOIN chapters c ON c.id=pr.chapter_id AND c.novel_id=pr.novel_id
                 WHERE pr.novel_id=? AND c.chapter_number>=?',
                [$novelId, $chapterNumber]
            );
            $report['postprocess_jobs'] = DB::execute(
                'DELETE pj FROM postprocess_jobs pj
                 INNER JOIN chapters c ON c.id=pj.chapter_id AND c.novel_id=pj.novel_id
                 WHERE pj.novel_id=? AND c.chapter_number>=?',
                [$novelId, $chapterNumber]
            );
        }

        $report += self::invalidateForeshadowing($novelId, $chapterNumber, $fromChapter);
        $report += self::invalidateCatchphrases($novelId, $chapterNumber, $fromChapter);
        $report['cumulative_state_stale'] = self::markCumulativeStateStale(
            $novelId,
            $chapterNumber,
            $reason
        );

        return $report;
    }

    /** @return array<string,int> */
    private static function invalidateForeshadowing(int $novelId, int $chapterNumber, bool $fromChapter): array
    {
        $op = $fromChapter ? '>=' : '=';
        $plants = DB::fetchAll(
            "SELECT id FROM foreshadowing_items WHERE novel_id=? AND planted_chapter {$op} ?",
            [$novelId, $chapterNumber]
        );
        $plantIds = array_map('intval', array_column($plants, 'id'));
        $plantSet = array_fill_keys($plantIds, true);

        $mentions = DB::fetchAll(
            "SELECT foreshadowing_id, COUNT(*) AS cnt
             FROM foreshadowing_mention_log
             WHERE novel_id=? AND chapter_number {$op} ?
             GROUP BY foreshadowing_id",
            [$novelId, $chapterNumber]
        );
        DB::delete(
            'foreshadowing_mention_log',
            "novel_id=? AND chapter_number {$op} ?",
            [$novelId, $chapterNumber]
        );

        $mentionRows = 0;
        $decrements = [];
        foreach ($mentions as $mention) {
            $itemId = (int)$mention['foreshadowing_id'];
            $count = max(1, (int)$mention['cnt']);
            $mentionRows += $count;
            if (isset($plantSet[$itemId])) {
                continue;
            }
            $decrements[$itemId] = $count;
        }
        self::decrementProjectionCounters($novelId, 'foreshadowing', $decrements);

        $reopened = DB::execute(
            "UPDATE foreshadowing_items
             SET resolved_chapter=NULL, resolved_at=NULL
             WHERE novel_id=? AND resolved_chapter {$op} ?",
            [$novelId, $chapterNumber]
        );

        $deletedPlants = 0;
        if ($plantIds !== []) {
            $ph = implode(',', array_fill(0, count($plantIds), '?'));
            DB::execute(
                "DELETE FROM foreshadowing_mention_log WHERE novel_id=? AND foreshadowing_id IN ({$ph})",
                array_merge([$novelId], $plantIds)
            );
            $deletedPlants = DB::execute(
                "DELETE FROM foreshadowing_items WHERE novel_id=? AND id IN ({$ph})",
                array_merge([$novelId], $plantIds)
            );
        }

        return [
            'foreshadowing_mentions' => $mentionRows,
            'foreshadowing_plants'   => $deletedPlants,
            'foreshadowing_reopened' => $reopened,
        ];
    }

    /** @return array<string,int> */
    private static function invalidateCatchphrases(
        int $novelId,
        int $chapterNumber,
        bool $fromChapter,
        bool $preservePhrases = false
    ): array
    {
        $op = $fromChapter ? '>=' : '=';
        // Memory replay only reconstructs callbacks; it does not extract the
        // original phrase definitions. Content invalidation still removes them.
        $newPhrases = $preservePhrases ? [] : DB::fetchAll(
            "SELECT id FROM novel_catchphrases WHERE novel_id=? AND first_chapter {$op} ?",
            [$novelId, $chapterNumber]
        );
        $phraseIds = array_map('intval', array_column($newPhrases, 'id'));
        $phraseSet = array_fill_keys($phraseIds, true);

        $callbacks = DB::fetchAll(
            "SELECT catchphrase_id, COUNT(*) AS cnt
             FROM catchphrase_callback_log
             WHERE novel_id=? AND chapter_number {$op} ?
             GROUP BY catchphrase_id",
            [$novelId, $chapterNumber]
        );
        DB::delete(
            'catchphrase_callback_log',
            "novel_id=? AND chapter_number {$op} ?",
            [$novelId, $chapterNumber]
        );

        $callbackRows = 0;
        $decrements = [];
        foreach ($callbacks as $callback) {
            $phraseId = (int)$callback['catchphrase_id'];
            $count = max(1, (int)$callback['cnt']);
            $callbackRows += $count;
            if (isset($phraseSet[$phraseId])) {
                continue;
            }
            $decrements[$phraseId] = $count;
        }
        self::decrementProjectionCounters($novelId, 'catchphrase', $decrements);

        $deletedPhrases = 0;
        if ($phraseIds !== []) {
            $ph = implode(',', array_fill(0, count($phraseIds), '?'));
            DB::execute(
                "DELETE FROM catchphrase_callback_log WHERE novel_id=? AND catchphrase_id IN ({$ph})",
                array_merge([$novelId], $phraseIds)
            );
            $deletedPhrases = DB::execute(
                "DELETE FROM novel_catchphrases WHERE novel_id=? AND id IN ({$ph})",
                array_merge([$novelId], $phraseIds)
            );
        }

        return [
            'catchphrase_callbacks' => $callbackRows,
            'catchphrases'          => $deletedPhrases,
        ];
    }

    /** Two queries per bounded batch, inside the caller's mutation transaction. */
    private static function decrementProjectionCounters(int $novelId, string $kind, array $counts): void
    {
        [$table, $log, $foreignKey, $counter, $lastChapter] = match ($kind) {
            'foreshadowing' => ['foreshadowing_items', 'foreshadowing_mention_log', 'foreshadowing_id', 'mention_count', 'last_mentioned_chapter'],
            'catchphrase' => ['novel_catchphrases', 'catchphrase_callback_log', 'catchphrase_id', 'callback_count', 'last_callback_chapter'],
            default => throw new InvalidArgumentException('Unknown projection counter'),
        };
        foreach (array_chunk($counts, 250, true) as $batch) {
            $ids = array_keys($batch);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $remaining = DB::fetchAll(
                "SELECT {$foreignKey} AS item_id, MAX(chapter_number) AS chapter_number
                 FROM {$log} WHERE novel_id=? AND {$foreignKey} IN ({$ph}) GROUP BY {$foreignKey}",
                array_merge([$novelId], $ids)
            );
            $latest = array_column($remaining, 'chapter_number', 'item_id');
            $decreaseCases = $lastCases = [];
            $decreaseValues = $lastValues = [];
            foreach ($batch as $id => $count) {
                $decreaseCases[] = 'WHEN ? THEN ?';
                array_push($decreaseValues, $id, $count);
                $lastCases[] = 'WHEN ? THEN ?';
                array_push($lastValues, $id, $latest[$id] ?? null);
            }
            DB::execute(
                "UPDATE {$table} SET {$counter}=GREATEST(CAST({$counter} AS SIGNED)-(CASE id "
                . implode(' ', $decreaseCases) . " ELSE 0 END),0), {$lastChapter}=CASE id "
                . implode(' ', $lastCases) . " ELSE {$lastChapter} END WHERE novel_id=? AND id IN ({$ph})",
                array_merge($decreaseValues, $lastValues, [$novelId], $ids)
            );
        }
    }

    private static function markCumulativeStateStale(int $novelId, int $chapterNumber, string $reason): bool
    {
        $state = DB::fetch(
            'SELECT last_ingested_chapter FROM novel_state WHERE novel_id=? FOR UPDATE',
            [$novelId]
        );
        if (!$state || (int)$state['last_ingested_chapter'] < $chapterNumber) {
            return false;
        }

        $watermark = max(0, $chapterNumber - 1);
        $label = trim($reason) !== '' ? trim($reason) : 'manual_edit';
        $warning = "第{$chapterNumber}章正文已变更（{$label}），累计人物/圣经状态尚未自动重放；请以当前正文和大纲为准。";
        // M-11 修复（2026-07-25）：原代码用 $warning 覆盖 story_momentum，原 momentum 永久丢失，
        // 且警告文本会被注入下游 prompt 当作"剧情动力"。改为：与 current_location 等字段一致置 NULL，
        // 警告通过 addLog 单独记录，不污染业务字段。
        addLog($novelId, 'warn', $warning);
        DB::execute(
            'UPDATE novel_state
             SET story_momentum=NULL, current_location=NULL, location_chapter=NULL,
                 location_transition=NULL, current_arc_summary=NULL,
                 last_ingested_chapter=LEAST(last_ingested_chapter, ?)
             WHERE novel_id=?',
            [$watermark, $novelId]
        );
        return true;
    }

    /** @return array{PDO,bool,?string} */
    private static function beginScope(string $label): array
    {
        $pdo = DB::getPdo();
        $ownTx = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownTx) {
            if (!$pdo->beginTransaction()) {
                throw new RuntimeException('无法开启章节变更事务');
            }
        } else {
            $safeLabel = preg_replace('/[^a-zA-Z0-9_]/', '_', $label) ?: 'chapter';
            $savepoint = 'sp_chapter_mutation_' . $safeLabel . '_' . (++self::$savepointSequence);
            $pdo->exec("SAVEPOINT `{$savepoint}`");
        }
        return [$pdo, $ownTx, $savepoint];
    }

    private static function finishScope(PDO $pdo, bool $ownTx, ?string $savepoint): void
    {
        if ($ownTx) {
            if (!$pdo->commit()) {
                throw new RuntimeException('章节变更事务提交失败');
            }
        } elseif ($savepoint !== null) {
            $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
        }
    }

    private static function rollbackScope(PDO $pdo, bool $ownTx, ?string $savepoint): void
    {
        try {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownTx && $savepoint !== null && $pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
                $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            }
        } catch (Throwable $rollbackError) {
            error_log('ChapterMutationService rollback failed: ' . $rollbackError->getMessage());
        }
    }
}
