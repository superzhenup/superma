<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ChapterMutationService.php';
require_once __DIR__ . '/memory/MemoryEngine.php';
require_once __DIR__ . '/memory.php';

/**
 * Makes one immutable body revision the sole source for chapter memory.
 *
 * The SHA-256 marker lives in writing_logs, so no schema addition is required.
 * User mutations remove that marker through ChapterMutationService.  An advisory
 * lock serializes concurrent async post-process requests; the content hash is
 * checked both before and after the summary AI call to reject stale workers.
 */
final class ChapterMemoryFinalizer
{
    private const MARKER_ACTION = 'memory_finalized';
    private const MARKER_PREFIX = 'content_sha256:';

    /**
     * @return array{ok:bool,duplicate:bool,stale:bool,historical_deferred:bool,hash:string,summary:array,ingest:array,reset:array}
     */
    public static function finalize(
        int $novelId,
        int $chapterId,
        string $content,
        ?MemoryEngine $engine = null,
        ?array $summaryData = null
    ): array {
        $hash = hash('sha256', $content);
        $empty = [
            'ok' => false,
            'duplicate' => false,
            'stale' => false,
            'historical_deferred' => false,
            'hash' => $hash,
            'summary' => [],
            'ingest' => [],
            'reset' => [],
        ];
        if ($novelId <= 0 || $chapterId <= 0 || trim($content) === '') {
            return $empty;
        }

        $pdo = DB::getPdo();
        // MemoryEngine writes novel-wide cumulative state.  A per-chapter lock
        // still permits chapter N+1 to finish before chapter N, so serialize all
        // finalization for the same novel.
        $lockName = 'novel_memory_finalize_' . $novelId;
        $locked = false;
        try {
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
            $stmt->execute([$lockName]);
            $lockResult = $stmt->fetchColumn();
            $stmt->closeCursor();
            $locked = ((int)$lockResult === 1);
            // 不能把锁失败伪装成成功：当前流程没有可靠的后台重放队列，
            // 一旦下一章完成，本章又会因“历史章节”守卫而禁止乱序补录，最终
            // 永久缺失摘要/人物/伏笔状态。正文已经安全落盘，但后处理必须明确
            // 报错；若并发任务已经完成相同正文，则按幂等标记返回 duplicate。
            if (!$locked) {
                if (self::hasFinalizedRevision($novelId, $chapterId, $hash)) {
                    $empty['ok'] = true;
                    $empty['duplicate'] = true;
                    return $empty;
                }
                $reason = $lockResult === null ? '数据库未返回锁状态' : '另一记忆任务仍在执行';
                throw new RuntimeException("章节记忆处理繁忙：{$reason}，请稍后重试");
            }

            $chapter = self::currentChapter($novelId, $chapterId);
            if (!$chapter
                || ($chapter['status'] ?? '') !== 'completed'
                || !hash_equals($hash, hash('sha256', (string)($chapter['content'] ?? '')))) {
                $empty['stale'] = true;
                return $empty;
            }

            if (self::hasFinalizedRevision($novelId, $chapterId, $hash)) {
                $empty['ok'] = true;
                $empty['duplicate'] = true;
                return $empty;
            }

            // Character cards, Story Bible nodes and novel_state are cumulative
            // projections.  Re-ingesting an edited/backfilled historical chapter
            // on top of later completed chapters would apply an old event to the
            // present state.  Keep the source body saved and the stale watermark
            // intact; a future ordered replay can rebuild it safely.
            if (self::hasLaterCompletedChapter($novelId, (int)$chapter['chapter_number'])) {
                $empty['historical_deferred'] = true;
                return $empty;
            }

            $novel = DB::fetch(
                'SELECT id, title, genre, protagonist_name, protagonist_info, model_id
                 FROM novels WHERE id=?',
                [$novelId]
            ) ?: ['id' => $novelId];

            if ($summaryData === null) {
                try {
                    $summaryData = generateChapterSummary($novel, $chapter, $content);
                } catch (Throwable $e) {
                    error_log('ChapterMemoryFinalizer summary failed: ' . $e->getMessage());
                    $summaryData = null;
                }
            }
            if (empty($summaryData)) {
                $summaryData = self::fallbackSummary($chapter, $content);
            }

            // The AI summary can take seconds.  A manual edit/polish during that
            // window invalidates this worker; never ingest its now-stale result.
            $latest = self::currentChapter($novelId, $chapterId);
            if (!$latest
                || ($latest['status'] ?? '') !== 'completed'
                || !hash_equals($hash, hash('sha256', (string)($latest['content'] ?? '')))) {
                $empty['stale'] = true;
                return $empty;
            }
            if (self::hasLaterCompletedChapter($novelId, (int)$latest['chapter_number'])) {
                $empty['historical_deferred'] = true;
                return $empty;
            }
            $chapter = $latest;

            $ownTx = !$pdo->inTransaction();
            $savepoint = null;
            if ($ownTx) {
                if (!$pdo->beginTransaction()) {
                    throw new RuntimeException('无法开启章节记忆最终化事务');
                }
            } else {
                $savepoint = 'sp_memory_finalize_' . $chapterId;
                $pdo->exec("SAVEPOINT `{$savepoint}`");
            }

            try {
                // Lock and verify once more inside the write transaction.
                $lockedChapter = DB::fetch(
                    'SELECT id, novel_id, chapter_number, content, status FROM chapters
                     WHERE id=? AND novel_id=? AND status="completed" FOR UPDATE',
                    [$chapterId, $novelId]
                );
                if (!$lockedChapter
                    || !hash_equals($hash, hash('sha256', (string)($lockedChapter['content'] ?? '')))) {
                    throw new RuntimeException('stale_chapter_revision');
                }
                if (self::hasLaterCompletedChapter($novelId, (int)$lockedChapter['chapter_number'], true)) {
                    throw new RuntimeException('historical_chapter_requires_replay');
                }

                // “提取记忆”可能针对历史章节。MemoryEngine 会无条件写
                // last_ingested_chapter / 场景状态，因此先锁住更晚的累计状态，
                // 完成本章投影重建后再原样恢复，禁止旧章倒退全书游标。
                $stateBefore = DB::fetch(
                    'SELECT story_momentum, current_location, location_chapter,
                            location_transition, current_arc_summary,
                            last_ingested_chapter, graph_start_chapter
                     FROM novel_state WHERE novel_id=? FOR UPDATE',
                    [$novelId]
                );
                $preserveNewerState = $stateBefore
                    && (int)($stateBefore['last_ingested_chapter'] ?? 0) > (int)$chapter['chapter_number'];

                $reset = ChapterMutationService::resetGeneratedProjectionsForReingest(
                    $novelId,
                    (int)$chapter['chapter_number'],
                    $chapterId
                );

                $updates = [
                    'chapter_summary' => trim((string)($summaryData['narrative_summary'] ?? '')) ?: null,
                    'used_tropes' => !empty($summaryData['used_tropes'])
                        ? json_encode($summaryData['used_tropes'], JSON_UNESCAPED_UNICODE)
                        : null,
                    'cool_point_type' => null,
                ];
                if (!empty($summaryData['cool_point_type'])) {
                    $type = trim((string)$summaryData['cool_point_type']);
                    if (defined('COOL_POINT_TYPES') && isset(COOL_POINT_TYPES[$type])) {
                        $updates['cool_point_type'] = $type;
                    }
                }
                DB::update('chapters', $updates, 'id=? AND novel_id=?', [$chapterId, $novelId]);

                $engine ??= new MemoryEngine($novelId);
                $ingest = $engine->ingestChapter((int)$chapter['chapter_number'], $summaryData);
                if (!empty($ingest['errors'])) {
                    throw new RuntimeException(
                        'MemoryEngine ingest incomplete: ' . implode('; ', (array)$ingest['errors'])
                    );
                }

                self::trackFinalContent($novelId, (int)$chapter['chapter_number'], $content);

                if ($preserveNewerState) {
                    DB::update('novel_state', [
                        'story_momentum' => $stateBefore['story_momentum'] ?? null,
                        'current_location' => $stateBefore['current_location'] ?? null,
                        'location_chapter' => $stateBefore['location_chapter'] ?? null,
                        'location_transition' => $stateBefore['location_transition'] ?? null,
                        'current_arc_summary' => $stateBefore['current_arc_summary'] ?? null,
                        'last_ingested_chapter' => (int)$stateBefore['last_ingested_chapter'],
                        'graph_start_chapter' => $stateBefore['graph_start_chapter'] ?? null,
                    ], 'novel_id=?', [$novelId]);
                }

                DB::insert('writing_logs', [
                    'novel_id' => $novelId,
                    'chapter_id' => $chapterId,
                    'action' => self::MARKER_ACTION,
                    'message' => self::MARKER_PREFIX . $hash,
                ]);

                if ($ownTx) {
                    if (!$pdo->commit()) {
                        throw new RuntimeException('章节记忆最终化提交失败');
                    }
                } elseif ($savepoint !== null) {
                    $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
                }
            } catch (Throwable $e) {
                try {
                    if ($ownTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    } elseif (!$ownTx && $savepoint !== null && $pdo->inTransaction()) {
                        $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
                        $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
                    }
                } catch (Throwable $rollbackError) {
                    error_log('ChapterMemoryFinalizer rollback failed: ' . $rollbackError->getMessage());
                }
                if ($e->getMessage() === 'stale_chapter_revision') {
                    $empty['stale'] = true;
                    return $empty;
                }
                if ($e->getMessage() === 'historical_chapter_requires_replay') {
                    $empty['historical_deferred'] = true;
                    return $empty;
                }
                throw $e;
            }

            return [
                'ok' => true,
                'duplicate' => false,
                'stale' => false,
                'historical_deferred' => false,
                'hash' => $hash,
                'summary' => $summaryData,
                'ingest' => $ingest,
                'reset' => $reset,
            ];
        } finally {
            if ($locked) {
                try {
                    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $stmt->execute([$lockName]);
                    $stmt->fetchColumn();
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                    error_log('ChapterMemoryFinalizer lock release failed: ' . $e->getMessage());
                }
            }
        }
    }

    public static function hasFinalizedRevision(int $novelId, int $chapterId, string $hash): bool
    {
        return (bool)DB::fetch(
            'SELECT id FROM writing_logs
             WHERE novel_id=? AND chapter_id=? AND action=? AND message=? LIMIT 1',
            [$novelId, $chapterId, self::MARKER_ACTION, self::MARKER_PREFIX . $hash]
        );
    }

    private static function currentChapter(int $novelId, int $chapterId): array|false
    {
        return DB::fetch(
            'SELECT * FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
            [$chapterId, $novelId]
        );
    }

    private static function hasLaterCompletedChapter(
        int $novelId,
        int $chapterNumber,
        bool $forUpdate = false
    ): bool {
        $sql = 'SELECT id FROM chapters
                WHERE novel_id=? AND status="completed" AND chapter_number>?
                ORDER BY chapter_number ASC LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return (bool)DB::fetch($sql, [$novelId, $chapterNumber]);
    }

    private static function fallbackSummary(array $chapter, string $content): array
    {
        $outline = trim((string)($chapter['outline'] ?? ''));
        $excerpt = trim(mb_substr($content, 0, 500));
        return [
            'narrative_summary' => $outline !== '' ? $outline : $excerpt,
            'key_event' => $outline !== '' ? mb_substr($outline, 0, 300) : mb_substr($excerpt, 0, 300),
            'character_updates' => [],
            'character_traits' => [],
            'new_foreshadowing' => [],
            'resolved_foreshadowing' => [],
            'character_emotions' => [],
            'used_tropes' => [],
            'story_momentum' => '',
        ];
    }

    private static function trackFinalContent(int $novelId, int $chapterNumber, string $content): void
    {
        require_once __DIR__ . '/memory/ForeshadowingRepo.php';
        require_once __DIR__ . '/memory/CatchphraseRepo.php';
        require_once __DIR__ . '/memory/CharacterCardRepo.php';

        (new ForeshadowingRepo($novelId))->trackMentionsInContent($content, $chapterNumber);
        (new CatchphraseRepo($novelId))->trackCallbacksInContent($content, $chapterNumber);

        $cards = new CharacterCardRepo($novelId);
        $present = [];
        foreach ($cards->listAll(false) as $card) {
            $name = trim((string)($card['name'] ?? ''));
            if ($name !== '' && mb_strpos($content, $name) !== false) {
                $present[] = $name;
            }
        }
        if ($present !== []) {
            $cards->touchPresenceBatch($present, $chapterNumber);
        }
    }
}
