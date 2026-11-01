<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/OutlineEnhancementContracts.php';
require_once __DIR__ . '/OutlineEnhancementRunFactory.php';

final class DbOutlineEnhancementStore implements OutlineEnhancementStore
{
    private HarnessPersistenceDriver $db;
    private readonly ?PDO $pdo;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->pdo = $connection instanceof PDO ? $connection : null;
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function capture(
        int $userId,
        int $novelId,
        array $chapterNumbers
    ): OutlineEnhancementSnapshot {
        [$snapshot] = $this->captureRows($userId, $novelId, $chapterNumbers, false);
        return $snapshot;
    }

    public function loadNovel(int $userId, int $novelId): array
    {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $novel = $this->db->fetchOne(
            'SELECT * FROM novels WHERE id=? AND user_id=?',
            [$novelId, $userId]
        );
        if ($novel === null) {
            throw new OutlineEnhancementPermanentException(
                'Outline enhancement Novel does not exist or changed ownership.'
            );
        }

        try {
            $storyOutline = $this->db->fetchOne(
                'SELECT story_arc, act_division, character_arcs, character_progression, '
                    . 'world_evolution, major_turning_points, recurring_motifs '
                    . 'FROM story_outlines WHERE novel_id=?',
                [$novelId]
            );
            if ($storyOutline !== null) {
                $novel['_story_outline'] = $storyOutline;
            }
        } catch (Throwable) {
            // Older schemas may not yet contain character_progression. The
            // enhancement core remains valid without the optional story row.
        }

        try {
            $goldenFingerFile = dirname(__DIR__, 2) . '/GoldenFinger.php';
            if (is_readable($goldenFingerFile)) {
                require_once $goldenFingerFile;
            }
            if (class_exists('GoldenFinger')) {
                $novel['golden_finger'] = (new GoldenFinger($novelId))->get();
            }
        } catch (Throwable) {
            // Optional structured setting; legacy behavior also fails open.
        }
        return $novel;
    }

    public function loadRecentOutlines(int $novelId, int $beforeChapter, int $limit): array
    {
        $novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $beforeChapter = HarnessPersistenceValue::positiveInt($beforeChapter, 'before_chapter');
        $limit = HarnessPersistenceValue::boundedInt($limit, 'outline_lookback', 1, 100);
        $rows = $this->db->fetchAll(
            "SELECT chapter_number, title, outline, hook, pacing, suspense FROM chapters "
                . "WHERE novel_id=? AND chapter_number<? AND outline IS NOT NULL AND outline<>'' "
                . 'ORDER BY chapter_number DESC LIMIT ' . $limit,
            [$novelId, $beforeChapter]
        );
        return array_reverse($rows);
    }

    public function commit(
        GenerationRunLease $lease,
        OutlineEnhancementSnapshot $base,
        array $enhanced,
        bool $authority,
        int $emergencyEpoch,
        callable $releaseSucceeded
    ): OutlineEnhancementCommitOutcome {
        // An authority commit must own the transaction that retains the
        // emergency-epoch row lock through the real database commit. Borrowing
        // an outer PDO transaction would let this method report APPLIED and run
        // afterCommit work while the caller can still roll everything back.
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            throw new HarnessPersistenceException(
                'Outline enhancement commit refuses a nested transaction.'
            );
        }
        $prepared = $this->prepareEnhanced($base, $enhanced);

        return $this->db->transactional(function () use (
            $lease,
            $base,
            $prepared,
            $authority,
            $emergencyEpoch,
            $releaseSucceeded
        ): OutlineEnhancementCommitOutcome {
            $run = $this->db->fetchOne(
                "SELECT id, user_id, novel_id, queue, run_type, workflow_name,
                        workflow_version, authority_mode, input_hash,
                        context_snapshot_hash, state, cancel_requested,
                        attempt, lease_owner, lease_token, fence_generation,
                        lease_expires_at, deadline_at, UTC_TIMESTAMP(6) AS now_utc
                 FROM generation_runs WHERE id=? FOR UPDATE",
                [$lease->run->id]
            );
            if ($run === null) {
                return OutlineEnhancementCommitOutcome::LEASE_LOST;
            }
            $nowSql = HarnessPersistenceValue::optionalMysqlDate(
                $run['now_utc'] ?? null,
                'database_now_utc'
            );
            $leaseExpiresAt = HarnessPersistenceValue::optionalMysqlDate(
                $run['lease_expires_at'] ?? null,
                'lease_expires_at'
            );
            $leaseMatches = (int)($run['id'] ?? 0) === $lease->run->id
                && (int)($run['user_id'] ?? 0) === $base->userId
                && (int)($run['novel_id'] ?? 0) === $base->novelId
                && (string)($run['queue'] ?? '') === OutlineEnhancementRunFactory::QUEUE
                && (string)($run['run_type'] ?? '') === OutlineEnhancementRunFactory::RUN_TYPE
                && (string)($run['workflow_name'] ?? '') === OutlineEnhancementRunFactory::WORKFLOW
                && (string)($run['workflow_version'] ?? '') === OutlineEnhancementRunFactory::WORKFLOW_VERSION
                && (string)($run['authority_mode'] ?? '') === ($authority ? 'authority' : 'shadow')
                && hash_equals(
                    (string)($run['input_hash'] ?? ''),
                    OutlineEnhancementRunFactory::frozenInputHash($base, $emergencyEpoch)
                )
                && (string)($run['context_snapshot_hash'] ?? '') === $base->revisionHash
                && (string)($run['state'] ?? '') === GenerationRunState::RUNNING->value
                && (int)($run['attempt'] ?? 0) === $lease->run->attempt
                && (string)($run['lease_owner'] ?? '') === $lease->owner
                && (string)($run['lease_token'] ?? '') === $lease->token
                && (int)($run['fence_generation'] ?? 0) === $lease->generation
                && $leaseExpiresAt === $lease->expiresAt
                && $nowSql !== null
                && $leaseExpiresAt !== null
                && $leaseExpiresAt >= $nowSql;
            if (!$leaseMatches) {
                return OutlineEnhancementCommitOutcome::LEASE_LOST;
            }
            if (HarnessPersistenceValue::bool(
                $run['cancel_requested'] ?? null,
                'cancel_requested'
            )) {
                return OutlineEnhancementCommitOutcome::CANCELED;
            }
            $deadlineAt = HarnessPersistenceValue::optionalMysqlDate(
                $run['deadline_at'] ?? null,
                'deadline_at'
            );
            if ($deadlineAt !== null && $deadlineAt <= $nowSql) {
                return OutlineEnhancementCommitOutcome::DEADLINE_EXCEEDED;
            }
            [$current, $currentRows] = $this->captureRows(
                $base->userId,
                $base->novelId,
                $base->chapterNumbers,
                true
            );
            if (!hash_equals($base->canonicalHash, $current->canonicalHash)
                || !hash_equals($base->revisionHash, $current->revisionHash)) {
                return OutlineEnhancementCommitOutcome::BASE_CHANGED;
            }

            // Match the irreversible Canon lock order: lock the affected
            // Chapter rows before the global emergency epoch. Harness bump()
            // takes only the epoch row, so this order cannot form the former
            // Canon(chapter -> epoch) / Outline(epoch -> chapter) cycle. The
            // epoch lock remains held through projection and Run release.
            $epochRow = $this->db->fetchOne(
                'SELECT setting_value FROM system_settings WHERE setting_key=? FOR UPDATE',
                [HarnessEmergencyStop::SETTING_KEY]
            );
            try {
                OutlineEmergencyEpoch::assertCurrent(
                    $emergencyEpoch,
                    OutlineEmergencyEpoch::normalize($epochRow['setting_value'] ?? null)
                );
            } catch (OutlineEnhancementEmergencyStopException) {
                return OutlineEnhancementCommitOutcome::EMERGENCY_STOPPED;
            }
            if (!$authority) {
                $released = $releaseSucceeded();
                if (!$released instanceof GenerationRun
                    || $released->state !== GenerationRunState::SUCCEEDED) {
                    throw new HarnessPersistenceException(
                        'Outline shadow commit did not atomically release the Run.'
                    );
                }
                return OutlineEnhancementCommitOutcome::SHADOW;
            }

            $ids = [];
            $synopsisIds = [];
            foreach ($currentRows as $row) {
                if (in_array($row['status'], ['pending', 'outlined', 'skipped', 'failed'], true)
                    && ($row['canonical_state'] ?? 'none') === 'none') {
                    $ids[(int)$row['chapter_number']] = (int)$row['id'];
                    $synopsisIds[(int)$row['chapter_number']] = (int)($row['synopsis_row_id'] ?? 0);
                }
            }
            foreach ($prepared as $chapterNumber => $outline) {
                if (!isset($ids[$chapterNumber])) continue;
                $affected = $this->db->execute(
                    "UPDATE chapters
                     SET title=?, outline=?, key_points=?, hook=?, pacing=?, suspense=?,
                         hook_type=?, cool_point_type=?, synopsis_id=NULL, updated_at=UTC_TIMESTAMP(6)
                     WHERE id=? AND novel_id=?
                       AND status IN ('pending','outlined','skipped','failed')
                       AND COALESCE(canonical_state,'none')='none'",
                    [
                        $outline['title'],
                        $outline['summary'],
                        HarnessPersistenceValue::canonicalJson($outline['key_points']),
                        $outline['hook'],
                        $outline['pacing'],
                        $outline['suspense'],
                        $outline['hook_type'] === '' ? null : $outline['hook_type'],
                        $outline['cool_point_type'] === '' ? null : $outline['cool_point_type'],
                        $ids[$chapterNumber],
                        $base->novelId,
                    ]
                );
                if ($affected > 1) {
                    throw new OutlineEnhancementRetryableException(
                        'Outline enhancement CAS updated more than one chapter row.'
                    );
                }
                // Delete only the child whose revision we compared under lock.
                // In READ COMMITTED a concurrent INSERT may follow an absent read.
                if ($synopsisIds[$chapterNumber] > 0) {
                    $this->db->execute('DELETE FROM chapter_synopses WHERE id=? AND novel_id=? AND chapter_number=?',
                        [$synopsisIds[$chapterNumber], $base->novelId, $chapterNumber]);
                }
            }
            $released = $releaseSucceeded();
            if (!$released instanceof GenerationRun
                || $released->state !== GenerationRunState::SUCCEEDED) {
                throw new HarnessPersistenceException(
                    'Outline authority commit did not atomically release the Run.'
                );
            }
            return OutlineEnhancementCommitOutcome::APPLIED;
        });
    }

    public function afterCommit(
        int $novelId,
        int $startChapter,
        int $endChapter,
        array $outlines
    ): void {
        $novel = $this->db->fetchOne(
            'SELECT * FROM novels WHERE id=?',
            [$novelId]
        );
        if ($novel === null) {
            $this->log($novelId, 'warn', '大纲增强后处理跳过：小说不存在');
            return;
        }

        if (function_exists('clearNovelCache')) {
            clearNovelCache($novelId);
        }

        $shouldCompress = ($endChapter % 10 === 0)
            || (($endChapter - $startChapter + 1) >= 5 && count($outlines) >= 3);
        if ($shouldCompress) {
            try {
                if (function_exists('generateAndSaveArcSummary')) {
                    $arcFrom = (int)(floor(($endChapter - 1) / 10) * 10) + 1;
                    generateAndSaveArcSummary($novel, $arcFrom, $endChapter);
                }
            } catch (Throwable $exception) {
                $this->log($novelId, 'warn', '大纲弧段摘要生成失败：' . $exception->getMessage());
            }
        }

        try {
            if (function_exists('getSystemSetting')
                && getSystemSetting('ws_volume_summary_enabled', true, 'bool')
                && function_exists('generateAndSaveVolumeSummary')
                && function_exists('parseVolumeRows')) {
                $volumeRows = $this->db->fetchAll(
                    'SELECT * FROM volume_outlines WHERE novel_id=? ORDER BY id ASC',
                    [$novelId]
                );
                $bounds = [];
                foreach (parseVolumeRows($volumeRows) as $volume) {
                    if ((int)$volume['chapter_to'] >= $startChapter
                        && (int)$volume['chapter_to'] <= $endChapter) {
                        $bounds[] = $volume;
                    }
                }
                if ($volumeRows === []) {
                    for ($to = (int)ceil($startChapter / 50) * 50; $to <= $endChapter; $to += 50) {
                        $bounds[] = [
                            'volume_number' => intdiv($to, 50),
                            'chapter_from' => $to - 49,
                            'chapter_to' => $to,
                        ];
                    }
                }
                foreach ($bounds as $bound) {
                    generateAndSaveVolumeSummary(
                        $novelId,
                        (int)$bound['volume_number'],
                        (int)$bound['chapter_from'],
                        (int)$bound['chapter_to'],
                        isset($novel['model_id']) ? (int)$novel['model_id'] : null
                    );
                }
            }
        } catch (Throwable $exception) {
            $this->log($novelId, 'warn', '大纲卷级摘要生成失败：' . $exception->getMessage());
        }

        try {
            $totalOutlined = (int)($this->db->fetchOne(
                "SELECT COUNT(*) AS total FROM chapters "
                    . "WHERE novel_id=? AND status<>'pending'",
                [$novelId]
            )['total'] ?? 0);
            if ($totalOutlined >= (int)($novel['target_chapters'] ?? PHP_INT_MAX)
                && empty($novel['status'])) {
                $this->db->execute(
                    "UPDATE novels SET status='draft' "
                        . "WHERE id=? AND (status IS NULL OR status='')",
                    [$novelId]
                );
            }
        } catch (Throwable $exception) {
            $this->log($novelId, 'warn', '大纲增强后小说状态复核失败：' . $exception->getMessage());
        }

        $this->log(
            $novelId,
            'outline',
            "第{$startChapter}-{$endChapter}章 durable Harness 大纲增强完成"
        );
    }

    public function log(int $novelId, string $level, string $message): void
    {
        if (function_exists('addLog')) {
            addLog($novelId, $level, $message);
        }
    }

    /**
     * @param list<int> $chapterNumbers
     * @return array{0:OutlineEnhancementSnapshot,1:list<array<string,mixed>>}
     */
    private function captureRows(
        int $userId,
        int $novelId,
        array $chapterNumbers,
        bool $lock
    ): array {
        $chapterNumbers = OutlineEnhancementSnapshot::normalizeChapterNumbers($chapterNumbers);
        if ($chapterNumbers === []) {
            throw new OutlineEnhancementPermanentException(
                'Outline enhancement chapter set is empty.'
            );
        }
        $placeholders = implode(',', array_fill(0, count($chapterNumbers), '?'));
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.chapter_number, c.title, c.outline, c.key_points,
                    c.hook, c.pacing, c.suspense, c.hook_type, c.cool_point_type,
                    c.status, c.canonical_state, c.updated_at, c.synopsis_id, cs.id AS synopsis_row_id,
                    CASE WHEN cs.id IS NULL THEN NULL ELSE SHA2(CAST(JSON_OBJECT(
                        'id',cs.id,'synopsis',cs.synopsis,'scenes',cs.scene_breakdown,
                        'dialogue',cs.dialogue_beats,'sensory',cs.sensory_details,'pacing',cs.pacing,
                        'cliffhanger',cs.cliffhanger,'foreshadowing',cs.foreshadowing,'callbacks',cs.callbacks,
                        'updated_at',cs.updated_at) AS CHAR),256) END AS synopsis_revision
             FROM chapters c
             INNER JOIN novels n ON n.id=c.novel_id AND n.user_id=?
             LEFT JOIN chapter_synopses cs ON cs.novel_id=c.novel_id AND cs.chapter_number=c.chapter_number
             WHERE c.novel_id=? AND c.chapter_number IN ({$placeholders})
             ORDER BY c.chapter_number" . ($lock ? ' FOR UPDATE' : ''),
            array_merge([$userId, $novelId], $chapterNumbers)
        );
        return [
            OutlineEnhancementSnapshot::fromRows($userId, $novelId, $chapterNumbers, $rows),
            $rows,
        ];
    }

    /**
     * @param list<array<string,mixed>> $enhanced
     * @return array<int,array<string,mixed>>
     */
    private function prepareEnhanced(
        OutlineEnhancementSnapshot $base,
        array $enhanced
    ): array {
        $baseByChapter = [];
        foreach ($base->outlines as $outline) {
            $baseByChapter[(int)$outline['chapter_number']] = $outline;
        }
        $provided = [];
        foreach ($enhanced as $item) {
            $chapterNumber = HarnessPersistenceValue::positiveInt(
                $item['chapter_number'] ?? null,
                'enhanced_outline.chapter_number'
            );
            if (!isset($baseByChapter[$chapterNumber]) || isset($provided[$chapterNumber])) {
                throw new OutlineEnhancementPermanentException(
                    'Enhanced outline changed or duplicated the durable chapter set.'
                );
            }
            $merged = array_merge($baseByChapter[$chapterNumber], $item);
            $keyPoints = $merged['key_points'] ?? [];
            if (!is_array($keyPoints)) {
                throw new OutlineEnhancementPermanentException(
                    'Enhanced outline key_points must remain an array.'
                );
            }
            $pacing = (string)($merged['pacing'] ?? '中');
            $suspense = (string)($merged['suspense'] ?? '无');
            $hookType = trim((string)($merged['hook_type'] ?? ''));
            $validHookTypes = defined('HOOK_TYPES') ? array_keys(\HOOK_TYPES) : [];
            if ($hookType !== '' && !in_array($hookType, $validHookTypes, true)) {
                $hookType = '';
            }
            $coolPointType = trim((string)($merged['cool_point_type'] ?? ''));
            if ($coolPointType !== ''
                && (!function_exists('isPersistedCoolPointType')
                    || !isPersistedCoolPointType($coolPointType))) {
                $coolPointType = '';
            }
            $provided[$chapterNumber] = [
                'chapter_number' => $chapterNumber,
                'title' => trim((string)($merged['title'] ?? '')),
                'summary' => trim((string)($merged['summary'] ?? $merged['outline'] ?? '')),
                'key_points' => $keyPoints,
                'hook' => trim((string)($merged['hook'] ?? '')),
                'pacing' => in_array($pacing, ['快', '中', '慢'], true) ? $pacing : '中',
                'suspense' => in_array($suspense, ['有', '无'], true) ? $suspense : '无',
                'hook_type' => $hookType,
                'cool_point_type' => $coolPointType,
            ];
        }
        ksort($provided, SORT_NUMERIC);
        if (array_keys($provided) !== $base->chapterNumbers) {
            throw new OutlineEnhancementPermanentException(
                'Enhanced outline omitted one or more durable base chapters.'
            );
        }
        return $provided;
    }
}
