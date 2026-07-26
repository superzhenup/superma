<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/data.php';

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
    ];

    private static int $savepointSequence = 0;

    /**
     * Atomically update one chapter and invalidate affected projections.
     *
     * Supported options:
     *  - backup_version (bool, default true when content changes)
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
            if ($preventWriting && ($chapter['status'] ?? '') === 'writing') {
                throw new ChapterMutationConflict('章节正在写作中，无法修改');
            }

            $allowedStatuses = array_values(array_filter(
                (array)($options['allowed_statuses'] ?? []),
                static fn($status): bool => is_string($status)
                    && preg_match('/^[a-z_]{1,24}$/D', $status) === 1
            ));
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

            $outlineChanged = (bool)($options['force_outline_invalidation'] ?? false);
            if (array_key_exists('outline', $updates)) {
                $outlineChanged = $outlineChanged
                    || (string)($chapter['outline'] ?? '') !== (string)$updates['outline'];
            }

            if ($contentChanged && (bool)($options['backup_version'] ?? true)) {
                backupChapterVersion($chapterId);
            }

            if ($contentChanged) {
                // Invalidation wins over caller-supplied derived values.  A later
                // explicit post-process may repopulate them from the new revision.
                $updates = array_merge($updates, self::CONTENT_DERIVED_DEFAULTS);
            }
            if ($outlineChanged) {
                $updates['synopsis_id'] = null;
            }

            $where = 'id=? AND novel_id=?';
            $params = [$chapterId, $novelId];
            if ($preventWriting) {
                $where .= ' AND status NOT IN ("writing")';
            }
            $affected = DB::update('chapters', $updates, $where, $params);

            $invalidated = [];
            if ($contentChanged) {
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
            ];
        } catch (Throwable $e) {
            self::rollbackScope($pdo, $ownTx, $savepoint);
            throw $e;
        }
    }

    /**
     * Delete a chapter and every projection that can be tied to it safely.
     * The caller remains responsible for refreshing aggregate novel word counts.
     */
    public static function deleteChapter(int $chapterId, int $novelId): array
    {
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
    }

    /**
     * Remove only projections produced by one generated chapter before a fresh,
     * atomic MemoryEngine ingest.  Unlike a user mutation this does not discard
     * arc summaries, PID history or global constraint state.
     *
     * The caller may already own a transaction; in that case this method uses a
     * savepoint so reset + subsequent ingest can commit or roll back together.
     */
    public static function resetGeneratedProjectionsForReingest(
        int $novelId,
        int $chapterNumber,
        int $chapterId
    ): array {
        if ($novelId <= 0 || $chapterNumber <= 0 || $chapterId <= 0) {
            throw new InvalidArgumentException('Invalid generated projection target');
        }

        [$pdo, $ownTx, $savepoint] = self::beginScope('reingest_' . $chapterId);
        try {
            $report = [
                'memory_atoms' => DB::delete(
                    'memory_atoms',
                    'novel_id=? AND source_chapter=?',
                    [$novelId, $chapterNumber]
                ),
                'novel_embeddings' => DB::delete(
                    'novel_embeddings',
                    'novel_id=? AND source_type="chapter" AND source_id=?',
                    [$novelId, $chapterId]
                ),
                'character_emotions' => DB::delete(
                    'character_emotion_history',
                    'novel_id=? AND chapter_number=?',
                    [$novelId, $chapterNumber]
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
                'finalization_markers' => DB::delete(
                    'writing_logs',
                    'novel_id=? AND chapter_id=? AND action="memory_finalized"',
                    [$novelId, $chapterId]
                ),
            ];

            $report += self::invalidateForeshadowing($novelId, $chapterNumber, false);
            $report += self::invalidateCatchphrases($novelId, $chapterNumber, false);

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

        $report['memory_atoms'] = DB::delete(
            'memory_atoms',
            "novel_id=? AND source_chapter {$op} ?",
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
        foreach ($mentions as $mention) {
            $itemId = (int)$mention['foreshadowing_id'];
            $count = max(1, (int)$mention['cnt']);
            $mentionRows += $count;
            if (isset($plantSet[$itemId])) {
                continue;
            }
            $previous = DB::fetch(
                'SELECT MAX(chapter_number) AS chapter_number
                 FROM foreshadowing_mention_log WHERE novel_id=? AND foreshadowing_id=?',
                [$novelId, $itemId]
            );
            DB::execute(
                'UPDATE foreshadowing_items
                 SET mention_count=GREATEST(mention_count-?,0), last_mentioned_chapter=?
                 WHERE id=? AND novel_id=?',
                [$count, $previous['chapter_number'] ?? null, $itemId, $novelId]
            );
        }

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
    private static function invalidateCatchphrases(int $novelId, int $chapterNumber, bool $fromChapter): array
    {
        $op = $fromChapter ? '>=' : '=';
        $newPhrases = DB::fetchAll(
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
        foreach ($callbacks as $callback) {
            $phraseId = (int)$callback['catchphrase_id'];
            $count = max(1, (int)$callback['cnt']);
            $callbackRows += $count;
            if (isset($phraseSet[$phraseId])) {
                continue;
            }
            $previous = DB::fetch(
                'SELECT MAX(chapter_number) AS chapter_number
                 FROM catchphrase_callback_log WHERE novel_id=? AND catchphrase_id=?',
                [$novelId, $phraseId]
            );
            DB::execute(
                'UPDATE novel_catchphrases
                 SET callback_count=GREATEST(callback_count-?,0), last_callback_chapter=?
                 WHERE id=? AND novel_id=?',
                [$count, $previous['chapter_number'] ?? null, $phraseId, $novelId]
            );
        }

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
