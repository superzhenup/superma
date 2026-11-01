<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ChapterMutationService.php';
require_once __DIR__ . '/NovelProjectionFence.php';
require_once __DIR__ . '/memory/MemoryEngine.php';
require_once __DIR__ . '/memory/ForeshadowingReplayContext.php';
require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/tasks/PostprocessLeaseLostException.php';

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

        // Lock order is projection fence -> memory-finalizer lock -> row locks.
        // This also makes nested projection resets safe inside the transaction.
        $pdo = DB::getPdo();
        try {
            $projectionFence = NovelProjectionFence::acquire($novelId, 30);
        } catch (Throwable $lockError) {
            if (self::hasFinalizedRevision($novelId, $chapterId, $hash)) {
                $empty['ok'] = true;
                $empty['duplicate'] = true;
                return $empty;
            }
            throw new RuntimeException('Chapter projection processing is busy; retry later.', 0, $lockError);
        }
        // MemoryEngine writes novel-wide cumulative state.  A per-chapter lock
        // still permits chapter N+1 to finish before chapter N, so serialize all
        // finalization for the same novel.
        $lockName = 'novel_memory_finalize_' . $novelId;
        $locked = false;
        try {
            // P2-10 修复（2026-08-09）：原超时 10 秒过短。摘要 AI 调用可达数十秒，
            // 若同小说另一章的最终化正在执行（GET_LOCK 持有中），10 秒往往等不到锁，
            // 直接抛错后无后台重放队列 → 本章记忆永久缺失。延至 30 秒覆盖常态耗时。
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, 30)');
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
            //
            // 审计修复 P1-4（2026-08-08）：deferral 不再提前 return。
            // 原实现在此直接返回，chapter_summary 永不落盘，而 StoryBible 增量
            // 水位照样推进 → 该章内容永远不进全书圣经。章级摘要（chapter_summary/
            // used_tropes/cool_point_type）是非累积字段，历史章落盘它们绝对安全，
            // 因此先生成摘要、在下方 post-AI 复查点按 deferral 分支单独落盘摘要。
            $novel = DB::fetch(
                'SELECT id, title, genre, protagonist_name, protagonist_info, model_id
                 FROM novels WHERE id=?',
                [$novelId]
            ) ?: ['id' => $novelId];

            $summaryData = self::requireUsableSummary(
                $summaryData ?? generateChapterSummary($novel, $chapter, $content),
                (int)$chapter['chapter_number']
            );

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
                // P1-4：历史章只落盘章级摘要，累积投影（人物卡/atom/伏笔/novel_state）
                // 仍保持推迟，等待未来的有序重放。
                self::persistDeferredSummary($novelId, $chapterId, $summaryData);
                $empty['historical_deferred'] = true;
                $empty['summary'] = $summaryData;
                return $empty;
            }
            $chapter = $latest;

            // 审计修复 P0（2026-09-27）：伏笔语义匹配的 embedding HTTP 调用
            // （curl 超时 60s）移出 finalize 事务。原实现 ingestChapter →
            // tryResolve → selectBestCandidate 在事务内同步调用 embedding
            // API，期间 novel_state/chapters 行锁与 novel 级 GET_LOCK 悬挂，
            // 同小说相邻章节的记忆固化（GET_LOCK 仅等 30s）会连锁失败并
            // 阻断写作主流程。事务开启前对回收描述批量预计算向量，事务内
            // 仅做内存余弦比对；预计算失败自动降级为关键词匹配。
            $engine ??= new MemoryEngine($novelId);
            $engine->foreshadowing()->precomputeResolveEmbeddings(array_values(array_filter(
                (array)($summaryData['resolved_foreshadowing'] ?? []),
                'is_string'
            )));

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

                // Replacing the latest chapter also retracts its former cumulative
                // character contribution. Source-less legacy history must not be
                // silently treated as a successfully rebuilt current card.
                $engine ??= new MemoryEngine($novelId);
                $engine->cards()->rewindFromChapter((int)$chapter['chapter_number']);

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
                    // 审计修复（2026-08-09 R11-2）：呼吸/过渡章标记须可落盘
                    if (function_exists('isPersistedCoolPointType')
                        ? isPersistedCoolPointType($type)
                        : (defined('COOL_POINT_TYPES') && isset(COOL_POINT_TYPES[$type]))) {
                        $updates['cool_point_type'] = $type;
                    }
                }
                DB::update('chapters', $updates, 'id=? AND novel_id=?', [$chapterId, $novelId]);

                // 审计修复（2026-08-02 H13）：重写/再落盘同一章时，旧正文的记忆
                // （key_event/cool_point atom、本章埋设的伏笔、伏笔提及计数、金句回调计数）
                // 必须在本章二次 ingest 前回滚，否则无条件 INSERT 会产生重复记忆原子、
                // 重复埋设伏笔、叠加计数，导致语义召回被同一条信息重复污染。
                self::revertChapterMemory($novelId, (int)$chapter['chapter_number']);

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
                    // P1-4：竞态推迟（复查后才有更晚章节完成）同样落盘章级摘要
                    // 审计修复 R4（2026-08-10）：ownTx=false 时（如 postProcess 事务内调用），
                    // persistDeferredSummary 的写入会进入外层事务，若外层后续回滚则摘要丢失。
                    // 审计修复（2026-08-13）：ownTx=false 时改用独立连接写入，摘要立即 autocommit，
                    // 不再被外层事务回滚吞掉，消除 R4 记录的不确定性。
                    self::persistDeferredSummary($novelId, $chapterId, $summaryData ?? [], !$ownTx);
                    $empty['historical_deferred'] = true;
                    $empty['summary'] = is_array($summaryData) ? $summaryData : [];
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
            try {
                NovelProjectionFence::release($projectionFence);
            } catch (Throwable $e) {
                error_log('ChapterMemoryFinalizer projection fence release failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * 从指定章号起按顺序重放累积记忆（人物卡 / atom / 伏笔 / novel_state）。
     *
     * finalize() 遇到更晚已完成章时只落章级摘要，避免把旧事件叠到当前游标。
     * 先取得全部可用摘要，再在同一事务内回退游标并重建记忆；摘要服务失败或
     * 租约丢失时不发布半套状态。触发章可用 seedSummaries 跳过二次摘要 AI。
     *
     * @param array<int,array> $seedSummaries 章号 => generateChapterSummary 结果
     * @return array{ok:bool,replayed:int,from:int,to:int,ingest:array,error:?string}
     */
    public static function replayFrom(
        int $novelId,
        int $fromChapterNumber,
        ?MemoryEngine $engine = null,
        array $seedSummaries = [],
        ?callable $onHeartbeat = null
    ): array {
        $empty = [
            'ok' => false,
            'replayed' => 0,
            'from' => $fromChapterNumber,
            'to' => $fromChapterNumber,
            'ingest' => [],
            'error' => null,
        ];
        if ($novelId <= 0 || $fromChapterNumber <= 0) {
            $empty['error'] = 'invalid_replay_target';
            return $empty;
        }

        self::replayHeartbeat($onHeartbeat);
        $pdo = DB::getPdo();
        try {
            $projectionFence = NovelProjectionFence::acquire($novelId, 30);
        } catch (Throwable) {
            $empty['error'] = 'lock_busy';
            return $empty;
        }
        $lockName = 'novel_memory_finalize_' . $novelId;
        $locked = false;
        $scopeStarted = false;
        $ownTx = false;
        $savepoint = null;
        try {
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, 30)');
            $stmt->execute([$lockName]);
            $lockResult = $stmt->fetchColumn();
            $stmt->closeCursor();
            $locked = ((int)$lockResult === 1);
            if (!$locked) {
                $empty['error'] = 'lock_busy';
                return $empty;
            }

            $novel = DB::fetch(
                'SELECT id, title, genre, protagonist_name, protagonist_info, model_id
                 FROM novels WHERE id=?',
                [$novelId]
            ) ?: ['id' => $novelId];

            $engine ??= new MemoryEngine($novelId);
            $empty['to'] = $fromChapterNumber;

            // AI calls happen before the write transaction. Keep only summary
            // data and revision coordinates between pages, not entire books.
            $foreshadowing = new ForeshadowingReplayContext(new ForeshadowingRepo($novelId), DB::fetchAll(
                'SELECT id, description, priority, planted_chapter, resolved_chapter, embedding, embedding_model
                 FROM foreshadowing_items WHERE novel_id=? AND planted_chapter<?
                   AND (resolved_chapter IS NULL OR resolved_chapter>=?)',
                [$novelId, $fromChapterNumber, $fromChapterNumber]
            ), $fromChapterNumber);
            $prepared = [];
            $triggerIngest = [];
            $pageFrom = $fromChapterNumber;
            $pageSize = 40;
            $sawAny = false;
            while (true) {
                $chapters = DB::fetchAll(
                    'SELECT * FROM chapters
                     WHERE novel_id=? AND status="completed" AND chapter_number>=?
                     ORDER BY chapter_number ASC LIMIT ' . $pageSize,
                    [$novelId, $pageFrom]
                );
                if ($chapters === []) {
                    break;
                }
                $sawAny = true;
                $prevFrom = $pageFrom;
                $lastInPage = (int)($chapters[count($chapters) - 1]['chapter_number'] ?? 0);
                if ($lastInPage >= $pageFrom) {
                    $pageFrom = $lastInPage + 1;
                }
                if ($pageFrom <= $prevFrom) {
                    break;
                }
                foreach ($chapters as $ch) {
                    self::replayHeartbeat($onHeartbeat);
                    $chId = (int)($ch['id'] ?? 0);
                    $chNum = (int)($ch['chapter_number'] ?? 0);
                    $content = (string)($ch['content'] ?? '');
                    if ($chId <= 0 || $chNum <= 0 || trim($content) === '') {
                        continue;
                    }

                    $summary = $seedSummaries[$chNum] ?? null;
                    if (!is_array($summary) || $summary === []) {
                        $summary = generateChapterSummary($novel, $ch, $content, $foreshadowing->forPrompt());
                    }
                    $summary = self::requireUsableSummary($summary, $chNum);
                    $summary = $foreshadowing->advance($chNum, $summary);
                    self::replayHeartbeat($onHeartbeat);
                    $prepared[] = [
                        'chapter_id' => $chId,
                        'chapter_number' => $chNum,
                        'hash' => hash('sha256', $content),
                        'summary' => $summary,
                    ];
                }
                if (count($chapters) < $pageSize) {
                    break;
                }
            }

            if (!$sawAny) {
                $empty['ok'] = true;
                $empty['error'] = 'no_completed_chapters';
                return $empty;
            }

            if ($prepared === []) {
                $empty['error'] = 'nothing_ingested';
                return $empty;
            }

            // 审计修复 P0（2026-09-27）：与 finalize 相同——重放事务内的
            // ingestChapter → tryResolve 语义匹配所需的回收描述向量在此
            // 批量预计算（事务开启前），事务内零网络调用，避免行锁与
            // novel 级 GET_LOCK 悬挂 60s（curl 超时）。
            $resolveDescriptions = [];
            foreach ($prepared as $item) {
                foreach ((array)($item['summary']['resolved_foreshadowing'] ?? []) as $resolved) {
                    if (is_string($resolved) && trim($resolved) !== '') {
                        $resolveDescriptions[] = $resolved;
                    }
                }
            }
            $engine->foreshadowing()->precomputeResolveEmbeddings($resolveDescriptions);

            self::replayHeartbeat($onHeartbeat);
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                if (!$pdo->beginTransaction()) {
                    throw new RuntimeException('无法开启记忆重放事务');
                }
            } else {
                $savepoint = 'sp_memory_replay_' . $novelId;
                $pdo->exec("SAVEPOINT `{$savepoint}`");
            }
            $scopeStarted = true;

            // Restore the trusted pre-revision state once, then replay in order.
            // This shares the transaction with markers and every other memory write.
            $engine->cards()->rewindFromChapter($fromChapterNumber);

            // Match the historical view before ingest, even when a formerly later
            // resolution moves earlier. Per-chapter reset alone would reopen it too late.
            DB::execute(
                'UPDATE foreshadowing_items SET resolved_chapter=NULL, resolved_at=NULL
                 WHERE novel_id=? AND planted_chapter<? AND resolved_chapter>=?',
                [$novelId, $fromChapterNumber, $fromChapterNumber]
            );
            DB::execute(
                'UPDATE novel_state
                 SET story_momentum=NULL, current_location=NULL, location_chapter=NULL,
                     location_transition=NULL, current_arc_summary=NULL,
                     last_ingested_chapter=?
                 WHERE novel_id=? AND last_ingested_chapter>=?',
                [max(0, $fromChapterNumber - 1), $novelId, $fromChapterNumber]
            );

            foreach ($prepared as $item) {
                self::replayHeartbeat($onHeartbeat);
                $chId = $item['chapter_id'];
                $chNum = $item['chapter_number'];
                $ch = DB::fetch(
                    'SELECT id, chapter_number, content FROM chapters
                     WHERE id=? AND novel_id=? AND status="completed" FOR UPDATE',
                    [$chId, $novelId]
                );
                if (!$ch || (int)$ch['chapter_number'] !== $chNum
                    || !hash_equals($item['hash'], hash('sha256', (string)$ch['content']))) {
                    throw new RuntimeException('stale_chapter_revision');
                }

                ChapterMutationService::resetGeneratedProjectionsForReingest(
                    $novelId, $chNum, $chId, memoryOnly: true
                );
                self::persistDeferredSummary($novelId, $chId, $item['summary'], strict: true);
                self::revertChapterMemory($novelId, $chNum);
                $ingest = $engine->ingestChapter($chNum, $item['summary']);
                if (!empty($ingest['errors'])) {
                    throw new RuntimeException(
                        "第{$chNum}章记忆重放失败：" . implode('; ', (array)$ingest['errors'])
                    );
                }
                self::trackFinalContent($novelId, $chNum, (string)$ch['content']);
                DB::insert('writing_logs', [
                    'novel_id' => $novelId,
                    'chapter_id' => $chId,
                    'action' => self::MARKER_ACTION,
                    'message' => self::MARKER_PREFIX . $item['hash'],
                ]);
                $empty['replayed']++;
                $empty['to'] = $chNum;
                if ($chNum === $fromChapterNumber) {
                    $triggerIngest = $ingest;
                }
            }

            // A lease can expire during ingest as well as during model calls.
            // Its failure must roll back every deletion and marker together.
            self::replayHeartbeat($onHeartbeat);
            if ($ownTx) {
                if (!$pdo->commit()) {
                    throw new RuntimeException('记忆重放提交失败');
                }
            } else {
                $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            }
            $scopeStarted = false;

            $empty['ok'] = $empty['replayed'] > 0;
            $empty['ingest'] = $triggerIngest;
            if (!$empty['ok']) {
                $empty['error'] = 'nothing_ingested';
            }
            return $empty;
        } catch (Throwable $e) {
            if ($scopeStarted && $pdo->inTransaction()) {
                if ($ownTx) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
                    $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
                }
            }
            if ($e instanceof WriteEnginePostprocessLeaseLostException) {
                throw $e;
            }
            $empty['replayed'] = 0;
            $empty['to'] = $fromChapterNumber;
            $empty['error'] = $e->getMessage();
            error_log('ChapterMemoryFinalizer::replayFrom failed: ' . $e->getMessage());
            return $empty;
        } finally {
            if ($locked) {
                try {
                    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $stmt->execute([$lockName]);
                    $stmt->fetchColumn();
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                    error_log('ChapterMemoryFinalizer replay lock release failed: ' . $e->getMessage());
                }
            }
            try {
                NovelProjectionFence::release($projectionFence);
            } catch (Throwable $e) {
                error_log('ChapterMemoryFinalizer replay projection fence release failed: ' . $e->getMessage());
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

    /**
     * 审计修复 P1-4（2026-08-08）：历史章 deferral 路径的章级摘要落盘。
     *
     * 只写非累积字段（chapter_summary / used_tropes / cool_point_type）：
     * 不触碰人物卡、memory_atoms、伏笔、novel_state 等累积投影，
     * 也不写 memory_finalized 幂等标记（有序重放仍可补做完整 ingest）。
     * StoryBible 增量窗口按 chapter_summary 非空筛选章节，此落盘让
     * 被推迟的章节也能进入全书圣经，闭合「摘要永久缺失 + 水位照样推进」的空洞。
     */
    private static function persistDeferredSummary(
        int $novelId,
        int $chapterId,
        array $summaryData,
        bool $independentConnection = false,
        bool $strict = false
    ): void
    {
        try {
            $updates = [
                'chapter_summary' => trim((string)($summaryData['narrative_summary'] ?? '')) ?: null,
                'used_tropes' => !empty($summaryData['used_tropes'])
                    ? json_encode($summaryData['used_tropes'], JSON_UNESCAPED_UNICODE)
                    : null,
            ];
            if (!empty($summaryData['cool_point_type'])) {
                $type = trim((string)$summaryData['cool_point_type']);
                if (function_exists('isPersistedCoolPointType')
                    ? isPersistedCoolPointType($type)
                    : (defined('COOL_POINT_TYPES') && isset(COOL_POINT_TYPES[$type]))) {
                    $updates['cool_point_type'] = $type;
                }
            }
            // 审计修复（2026-08-13）：ownTx=false（嵌套于外层事务）时，DB::update 走共享
            // 连接、写入进入外层事务，外层后续回滚会吞掉摘要，且无重放入口。改用独立
            // 连接立即提交，使历史章章级摘要确实落盘。ownTx=true 时事务已 rollBack、
            // autocommit 恢复，共享连接写入即独立提交，无需新建连接。
            if ($independentConnection) {
                self::updateChaptersWithFreshConnection($updates, 'id=? AND novel_id=?', [$chapterId, $novelId]);
            } else {
                DB::update('chapters', $updates, 'id=? AND novel_id=?', [$chapterId, $novelId]);
            }
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            // 摘要落盘失败不应阻断 deferral 返回（正文已安全保存）
            error_log('ChapterMemoryFinalizer persistDeferredSummary failed: ' . $e->getMessage());
        }
    }

    /**
     * 通过全新 PDO 连接执行 chapters UPDATE，立即 autocommit。
     * 用于嵌套事务场景：共享连接的写入会进入外层事务，外层回滚即丢失；
     * 独立连接不受外层事务影响，保证落盘。
     * 列名均为硬编码白名单字段，无需走 DB::validateColumns。
     */
    private static function updateChaptersWithFreshConnection(array $updates, string $where, array $params): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            defined('DB_PORT') ? (int)DB_PORT : 3306,
            DB_NAME,
            DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // This independent autocommit connection bypasses DB::connect(), so it
        // must establish the same fail-closed UTC session contract itself.
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        try {
            $set = [];
            $args = [];
            foreach ($updates as $col => $val) {
                $set[] = '`' . $col . '` = ?';
                $args[] = $val;
            }
            $sql = 'UPDATE chapters SET ' . implode(', ', $set) . ' WHERE ' . $where;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($args, $params));
        } finally {
            $pdo = null;
        }
    }

    private static function requireUsableSummary(array $summary, int $chapterNumber): array
    {
        // Preserve usable supplied/legacy summaries. A missing model result is
        // different: neither an outline nor a prose excerpt proves that memory
        // extraction completed, so let the durable postprocess job retry.
        foreach (['narrative_summary', 'key_event'] as $field) {
            if (isset($summary[$field]) && is_string($summary[$field])
                && trim($summary[$field]) !== '') {
                return $summary;
            }
        }
        throw new RuntimeException("第{$chapterNumber}章摘要提取失败，记忆尚未完成，请重试后处理");
    }

    private static function replayHeartbeat(?callable $onHeartbeat): void
    {
        if ($onHeartbeat === null) {
            return;
        }
        try {
            $onHeartbeat();
        } catch (WriteEnginePostprocessLeaseLostException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WriteEnginePostprocessLeaseLostException(
                'Memory replay could not authenticate its postprocess lease.', 0, $e
            );
        }
    }

    /**
     * 审计修复（2026-08-02 H13）：重写/再落盘同一章前，回滚本章旧正文产生的非幂等记忆。
     * 全部为幂等安全操作：首次 finalize 时无对应记录，自然 no-op。
     *
     * 审计修复 P0（2026-09-27）：移除吞错 catch。原实现吞掉回滚失败后主事务
     * 照常提交，随后的 ingestChapter 无条件 INSERT 产生重复记忆原子、重复
     * 埋设伏笔、叠加提及/回调计数，直接污染语义召回且无业务告警。回滚失败
     * 必须让 finalize 主事务整体回滚（正文已提交，无数据损失），由持久化
     * postprocess 任务安全重试（重试会重新执行 revert + ingest，幂等成立）。
     */
    private static function revertChapterMemory(int $novelId, int $chapterNumber): void
    {
        require_once __DIR__ . '/memory/ForeshadowingRepo.php';
        require_once __DIR__ . '/memory/AtomRepo.php';
        require_once __DIR__ . '/memory/CatchphraseRepo.php';

        $foreshadowing = new ForeshadowingRepo($novelId);
        $foreshadowing->revertMentionsForChapter($chapterNumber);
        $foreshadowing->revertPlantsForChapter($chapterNumber);

        $atoms = new AtomRepo($novelId);
        $atoms->deleteByChapterAndType($chapterNumber, 'plot_detail');
        $atoms->deleteByChapterAndType($chapterNumber, 'cool_point');

        (new CatchphraseRepo($novelId))->revertCallbacksForChapter($chapterNumber);
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
