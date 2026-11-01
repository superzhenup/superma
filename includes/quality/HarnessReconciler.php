<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WritePolicySnapshot.php';
require_once dirname(__DIR__) . '/harness/events/LegacyWritingTaskRunIdentity.php';

/**
 * Read-only consistency audit for the Candidate -> Canon write harness.
 *
 * The optional reader keeps the rules independently unit-testable. Its
 * signature is: fn(string $dataset, string $sql, array $params): array.
 * Production uses DB::fetchAll() and never executes a mutating statement.
 */
final class HarnessReconciler
{
    private const ORPHAN_GRACE_SECONDS = 1800;

    /** @var list<string> */
    private const ISSUE_TYPES = [
        'chapter_revision_mismatch',
        'canonical_candidate_mismatch',
        'quarantined_candidate_is_canon',
        'postprocess_job_binding_mismatch',
        'writing_lease_incomplete',
        'postprocess_lease_incomplete',
        'expired_active_lease',
        'permanent_writing_orphan',
        'orphan_async_row',
        'shadow_run_missing',
        'shadow_run_identity_mismatch',
        'shadow_state_progress_mismatch',
        'shadow_content_revision_mismatch',
        'shadow_postprocess_mismatch',
        'shadow_event_mismatch',
    ];

    /** @var Closure(string,string,array<int,mixed>):array<int,array<string,mixed>> */
    private Closure $reader;
    private DateTimeImmutable $now;

    public function __construct(?callable $reader = null, ?DateTimeImmutable $now = null)
    {
        if ($reader === null) {
            if (!class_exists('DB')) {
                throw new LogicException('DB must be loaded when no reconciler reader is supplied.');
            }
            $reader = static function (string $_dataset, string $sql, array $params): array {
                return DB::fetchAll($sql, $params);
            };
        }

        $this->reader = Closure::fromCallable($reader);
        $this->now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array{
     *   ok:bool,
     *   scope:string,
     *   sample_limit:int,
     *   checked_at:string,
     *   summary:array{datasets:int,rows:int,violations:int},
     *   datasets:array<string,int>,
     *   counts:array<string,int>,
     *   samples:array<string,list<array<string,mixed>>>
     * }
     */
    public function reconcile(string $scope = 'all', int $sampleLimit = 20): array
    {
        if ($sampleLimit < 1 || $sampleLimit > 1000) {
            throw new InvalidArgumentException('limit must be between 1 and 1000.');
        }
        $selection = self::parseScope($scope);
        $report = $this->emptyReport($selection['normalized'], $sampleLimit);

        if ($this->includes($selection, 'chapters')) {
            $this->auditChapterRevisions($selection, $report);
        }
        if ($this->includes($selection, 'candidates')) {
            $this->auditCanonicalCandidates($selection, $report);
            $this->auditQuarantinedCandidates($selection, $report);
        }
        if ($this->includes($selection, 'jobs')) {
            $this->auditPostprocessJobs($selection, $report);
        }
        if ($this->includes($selection, 'leases') || $selection['group'] === 'orphans') {
            $this->auditWritingLeases($selection, $report);
            $this->auditPostprocessLeases($selection, $report);
        }
        if ($this->includes($selection, 'orphans')) {
            $this->auditPermanentWriting($selection, $report);
        }
        if ($this->includes($selection, 'runs')) {
            $this->auditLegacyShadowRuns($selection, $report);
        }

        $report['summary']['violations'] = array_sum($report['counts']);
        $report['ok'] = $report['summary']['violations'] === 0;
        return $report;
    }

    /**
     * Supported values: all, chapters, candidates, jobs, leases, orphans,
     * novel:<id>, and chapter:<id>.
     *
     * @return array{normalized:string,group:string,filter:?string,id:?int}
     */
    public static function parseScope(string $scope): array
    {
        $value = strtolower(trim($scope));
        $groups = ['all', 'chapters', 'candidates', 'jobs', 'leases', 'orphans', 'runs'];
        if (in_array($value, $groups, true)) {
            return ['normalized' => $value, 'group' => $value, 'filter' => null, 'id' => null];
        }

        if (preg_match('/^(novel|chapter):([1-9][0-9]*)$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException(
                'scope must be all, chapters, candidates, jobs, leases, orphans, runs, novel:<id>, or chapter:<id>.'
            );
        }
        $id = filter_var($matches[2], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);
        if (!is_int($id)) {
            throw new InvalidArgumentException('scope id is outside the supported integer range.');
        }
        return [
            'normalized' => $matches[1] . ':' . $id,
            'group' => 'all',
            'filter' => $matches[1],
            'id' => $id,
        ];
    }

    /** @param array{group:string} $selection */
    private function includes(array $selection, string $group): bool
    {
        return $selection['group'] === 'all' || $selection['group'] === $group;
    }

    /** @return array<string,mixed> */
    private function emptyReport(string $scope, int $sampleLimit): array
    {
        $counts = [];
        $samples = [];
        foreach (self::ISSUE_TYPES as $type) {
            $counts[$type] = 0;
            $samples[$type] = [];
        }
        return [
            'ok' => true,
            'scope' => $scope,
            'sample_limit' => $sampleLimit,
            'checked_at' => $this->now->format(DateTimeInterface::ATOM),
            'summary' => ['datasets' => 0, 'rows' => 0, 'violations' => 0],
            'datasets' => [],
            'counts' => $counts,
            'samples' => $samples,
        ];
    }

    /** @param array<string,mixed> $report */
    private function auditChapterRevisions(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'ch', 'id');
        $rows = $this->rows(
            'completed_chapters',
            "SELECT ch.id AS chapter_id, ch.novel_id, ch.chapter_number,
                    ch.status, ch.canonical_state, ch.content_revision_hash,
                    (ch.content IS NULL) AS content_is_null,
                    IF(ch.content IS NULL, 0, OCTET_LENGTH(ch.content)=0) AS content_is_empty,
                    IF(ch.content IS NULL OR ch.content_revision_hash IS NULL, 0,
                       BINARY ch.content_revision_hash=BINARY SHA2(ch.content, 256))
                        AS content_hash_matches_revision
             FROM chapters ch
             WHERE (ch.status='completed'
                    OR ch.canonical_state IN ('legacy','canonical')){$filter}
             ORDER BY ch.id",
            $params
        );
        $this->recordDataset($report, 'completed_chapters', count($rows));

        foreach ($rows as $row) {
            $revision = $row['content_revision_hash'] ?? null;
            $status = $row['status'] ?? null;
            $canonicalState = $row['canonical_state'] ?? null;
            $contentIsNull = $this->boolean01($row['content_is_null'] ?? null);
            $contentIsEmpty = $this->boolean01($row['content_is_empty'] ?? null);
            $contentHashMatches = $this->boolean01(
                $row['content_hash_matches_revision'] ?? null
            );
            $reasons = [];
            if ($status === 'completed'
                && !in_array($canonicalState, ['legacy', 'canonical'], true)) {
                // Reconciliation is the deployment gate after historical
                // backfill. It must count completed rows that have not entered
                // either readable Canon state, not silently filter them out.
                $reasons[] = 'completed_chapter_not_backfilled';
            }
            if ($status !== 'completed'
                && in_array($canonicalState, ['legacy', 'canonical'], true)) {
                // A readable Canon body is terminal. Keeping its Canon marker
                // while downgrading the chapter status makes normal readers
                // hide authoritative content and must never reconcile cleanly.
                $reasons[] = 'canonical_state_requires_completed';
            }
            if ($contentIsNull !== false) {
                $reasons[] = 'content_not_string';
            } elseif ($contentIsEmpty !== false) {
                $reasons[] = 'completed_content_empty';
            }
            if (!$this->isHash($revision)) {
                $reasons[] = 'revision_hash_invalid';
            } elseif ($contentHashMatches !== true) {
                $reasons[] = 'revision_hash_does_not_match_content';
            }
            if ($reasons !== []) {
                $this->issue($report, 'chapter_revision_mismatch', [
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'status' => $this->safeEnum($status),
                    'canonical_state' => $this->safeEnum($canonicalState),
                    'reasons' => $reasons,
                ]);
            }
        }
    }

    /** @param array<string,mixed> $report */
    private function auditCanonicalCandidates(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'ch', 'id');
        $rows = $this->rows(
            'canonical_candidates',
            "SELECT ch.id AS chapter_id, ch.novel_id, ch.canonical_candidate_uid,
                    ch.content_revision_hash,
                    (ch.content IS NULL) AS chapter_content_is_null,
                    ch.write_policy_snapshot_json AS chapter_policy_json,
                    n.user_id AS novel_user_id,
                    cc.candidate_uid, cc.user_id AS candidate_user_id,
                    cc.novel_id AS candidate_novel_id,
                    cc.chapter_id AS candidate_chapter_id, cc.source,
                    cc.content_hash AS candidate_content_hash,
                    (cc.inline_content IS NULL) AS candidate_content_is_null,
                    IF(ch.content IS NULL OR cc.inline_content IS NULL, 0,
                       BINARY ch.content=BINARY cc.inline_content)
                        AS candidate_content_owns_canon,
                    IF(cc.inline_content IS NULL OR cc.content_hash IS NULL, 0,
                       BINARY cc.content_hash=BINARY SHA2(cc.inline_content, 256))
                        AS candidate_content_hash_valid,
                    IF(cc.inline_content IS NULL OR ch.content_revision_hash IS NULL, 0,
                       BINARY ch.content_revision_hash=BINARY SHA2(cc.inline_content, 256))
                        AS candidate_revision_binding_valid,
                    cc.lifecycle, cc.gate_state,
                    cc.policy_snapshot_json AS candidate_policy_json,
                    cc.policy_snapshot_hash AS candidate_policy_hash
             FROM chapters ch
             LEFT JOIN novels n ON n.id=ch.novel_id
             LEFT JOIN chapter_candidates cc
               ON cc.candidate_uid=ch.canonical_candidate_uid
             WHERE ch.canonical_state='canonical'{$filter}
             ORDER BY ch.id",
            $params
        );
        $this->recordDataset($report, 'canonical_candidates', count($rows));

        foreach ($rows as $row) {
            $reasons = [];
            $canonicalUid = $row['canonical_candidate_uid'] ?? null;
            $candidateUid = $row['candidate_uid'] ?? null;
            if (!$this->isUid($canonicalUid)) {
                $reasons[] = 'canonical_candidate_uid_invalid';
            }
            if (!$this->isUid($candidateUid)) {
                $reasons[] = 'candidate_missing';
            } elseif (!is_string($canonicalUid) || !hash_equals($canonicalUid, $candidateUid)) {
                $reasons[] = 'candidate_uid_mismatch';
            }

            if ($this->positiveIntOrNull($row['candidate_chapter_id'] ?? null)
                !== $this->positiveIntOrNull($row['chapter_id'] ?? null)) {
                $reasons[] = 'candidate_chapter_ownership_mismatch';
            }
            if ($this->positiveIntOrNull($row['candidate_novel_id'] ?? null)
                !== $this->positiveIntOrNull($row['novel_id'] ?? null)) {
                $reasons[] = 'candidate_novel_ownership_mismatch';
            }
            if ($this->positiveIntOrNull($row['candidate_user_id'] ?? null)
                !== $this->positiveIntOrNull($row['novel_user_id'] ?? null)) {
                $reasons[] = 'candidate_user_ownership_mismatch';
            }
            if (($row['lifecycle'] ?? null) !== 'committed') {
                $reasons[] = 'candidate_not_committed';
            }
            if (($row['gate_state'] ?? null) !== 'passed') {
                $reasons[] = 'candidate_gate_not_passed';
            }

            $candidateHash = $row['candidate_content_hash'] ?? null;
            $chapterContentIsNull = $this->boolean01(
                $row['chapter_content_is_null'] ?? null
            );
            $candidateContentIsNull = $this->boolean01(
                $row['candidate_content_is_null'] ?? null
            );
            $candidateOwnsCanon = $this->boolean01(
                $row['candidate_content_owns_canon'] ?? null
            );
            $candidateHashValid = $this->boolean01(
                $row['candidate_content_hash_valid'] ?? null
            );
            $candidateRevisionValid = $this->boolean01(
                $row['candidate_revision_binding_valid'] ?? null
            );
            if ($chapterContentIsNull !== false || $candidateContentIsNull !== false) {
                $reasons[] = 'candidate_content_missing';
            } else {
                if ($candidateOwnsCanon !== true) {
                    $reasons[] = 'candidate_content_does_not_own_canon';
                }
                if (!$this->isHash($candidateHash) || $candidateHashValid !== true) {
                    $reasons[] = 'candidate_content_hash_mismatch';
                }
                if (!$this->isHash($row['content_revision_hash'] ?? null)
                    || $candidateRevisionValid !== true) {
                    $reasons[] = 'candidate_revision_binding_mismatch';
                }
            }

            $chapterPolicy = $this->policy($row['chapter_policy_json'] ?? null, null, false);
            $candidatePolicy = $this->policy(
                $row['candidate_policy_json'] ?? null,
                $row['candidate_policy_hash'] ?? null,
                false,
                true
            );
            foreach ($chapterPolicy['errors'] as $error) {
                $reasons[] = 'chapter_' . $error;
            }
            foreach ($candidatePolicy['errors'] as $error) {
                $reasons[] = 'candidate_' . $error;
            }
            if ($chapterPolicy['checksum'] !== null && $candidatePolicy['checksum'] !== null
                && !hash_equals($chapterPolicy['checksum'], $candidatePolicy['checksum'])) {
                $reasons[] = 'candidate_policy_does_not_match_canon';
            }

            if ($reasons !== []) {
                $this->issue($report, 'canonical_candidate_mismatch', [
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'candidate_uid' => $this->safeUid($canonicalUid),
                    'reasons' => array_values(array_unique($reasons)),
                ]);
            }
        }
    }

    /** @param array<string,mixed> $report */
    private function auditQuarantinedCandidates(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'cc');
        $rows = $this->rows(
            'quarantined_candidates',
            "SELECT cc.candidate_uid, cc.novel_id, cc.chapter_id,
                    cc.lifecycle, cc.gate_state,
                    ch.id AS canonical_chapter_id
             FROM chapter_candidates cc
             LEFT JOIN chapters ch
               ON ch.canonical_candidate_uid=cc.candidate_uid
              AND ch.canonical_state='canonical'
             WHERE (cc.lifecycle='quarantined' OR cc.gate_state='quarantined'){$filter}
             ORDER BY cc.id",
            $params
        );
        $this->recordDataset($report, 'quarantined_candidates', count($rows));

        foreach ($rows as $row) {
            if ($this->positiveIntOrNull($row['canonical_chapter_id'] ?? null) === null) {
                continue;
            }
            $this->issue($report, 'quarantined_candidate_is_canon', [
                'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                'candidate_uid' => $this->safeUid($row['candidate_uid'] ?? null),
                'reasons' => ['quarantined_candidate_referenced_by_canon'],
            ]);
        }
    }

    /** @param array<string,mixed> $report */
    private function auditPostprocessJobs(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'pj');
        $rows = $this->rows(
            'postprocess_jobs',
            "SELECT pj.id AS job_id, pj.job_uid, pj.novel_id, pj.chapter_id,
                    pj.revision_hash, pj.stage, pj.state,
                    pj.policy_snapshot_json AS job_policy_json,
                    pj.policy_snapshot_hash AS job_policy_hash,
                    pj.accepted_candidate_uid, pj.rewrite_allowed,
                    ch.id AS stored_chapter_id,
                    ch.novel_id AS chapter_novel_id, ch.canonical_state,
                    ch.content_revision_hash AS chapter_revision_hash,
                    ch.canonical_candidate_uid,
                    ch.write_policy_snapshot_json AS chapter_policy_json,
                    n.user_id AS novel_user_id,
                    cc.candidate_uid, cc.user_id AS candidate_user_id,
                    cc.novel_id AS candidate_novel_id,
                    cc.chapter_id AS candidate_chapter_id, cc.source,
                    cc.content_hash AS candidate_content_hash, cc.lifecycle,
                    cc.gate_state,
                    cc.policy_snapshot_json AS candidate_policy_json,
                    cc.policy_snapshot_hash AS candidate_policy_hash
             FROM postprocess_jobs pj
             LEFT JOIN chapters ch ON ch.id=pj.chapter_id
             LEFT JOIN novels n ON n.id=pj.novel_id
             LEFT JOIN chapter_candidates cc
               ON cc.candidate_uid=pj.accepted_candidate_uid
             WHERE 1=1{$filter}
             ORDER BY pj.id",
            $params
        );
        $this->recordDataset($report, 'postprocess_jobs', count($rows));

        foreach ($rows as $row) {
            $reasons = [];
            $jobState = $row['state'] ?? null;
            $isActiveState = in_array($jobState, ['queued', 'retry', 'running'], true);
            $jobUid = $row['job_uid'] ?? null;
            $acceptedUid = $row['accepted_candidate_uid'] ?? null;
            $revision = $row['revision_hash'] ?? null;
            $stage = $row['stage'] ?? null;
            $chapterId = $this->positiveIntOrNull($row['chapter_id'] ?? null);
            if ($chapterId === null || $this->positiveIntOrNull($row['stored_chapter_id'] ?? null) === null) {
                $reasons[] = 'job_chapter_missing';
            }
            if ($this->positiveIntOrNull($row['novel_id'] ?? null) === null) {
                $reasons[] = 'job_novel_invalid';
            } elseif ($this->positiveIntOrNull($row['novel_id'] ?? null)
                !== $this->positiveIntOrNull($row['chapter_novel_id'] ?? null)) {
                $reasons[] = 'job_chapter_novel_mismatch';
            }
            if (!$this->isHash($revision)) {
                $reasons[] = 'job_revision_invalid';
            }
            if (!is_string($stage) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $stage) !== 1) {
                $reasons[] = 'job_stage_invalid';
            }
            if (!$this->isUid($jobUid)) {
                $reasons[] = 'job_uid_invalid';
            } elseif ($chapterId !== null && is_string($revision) && is_string($stage)) {
                $owner = $this->isUid($acceptedUid)
                    ? 'candidate:' . $acceptedUid
                    : 'legacy:' . $chapterId . ':' . $revision;
                $expectedJobUid = substr(hash('sha256', 'postprocess_job_v2:' . $owner . ':' . $stage), 0, 32);
                if (!hash_equals($expectedJobUid, $jobUid)) {
                    $reasons[] = 'job_uid_identity_mismatch';
                }
            }

            $rewriteAllowed = $this->boolean01($row['rewrite_allowed'] ?? null);
            if ($rewriteAllowed === null) {
                $reasons[] = 'rewrite_allowed_invalid';
            }

            $candidatePolicy = ['checksum' => null, 'errors' => []];
            if ($acceptedUid === null) {
                if ($rewriteAllowed === true) {
                    $reasons[] = 'legacy_job_cannot_rewrite';
                }
                if ($isActiveState
                    && ($row['canonical_state'] ?? null) === 'canonical'
                    && $this->sameHash($revision, $row['chapter_revision_hash'] ?? null)) {
                    $reasons[] = 'current_canon_job_missing_candidate_binding';
                }
            } elseif (!$this->isUid($acceptedUid)) {
                $reasons[] = 'accepted_candidate_uid_invalid';
            } else {
                if (!$this->isUid($row['candidate_uid'] ?? null)) {
                    $reasons[] = 'accepted_candidate_missing';
                }
                if ($this->positiveIntOrNull($row['candidate_chapter_id'] ?? null) !== $chapterId) {
                    $reasons[] = 'accepted_candidate_chapter_mismatch';
                }
                if ($this->positiveIntOrNull($row['candidate_novel_id'] ?? null)
                    !== $this->positiveIntOrNull($row['novel_id'] ?? null)) {
                    $reasons[] = 'accepted_candidate_novel_mismatch';
                }
                if ($this->positiveIntOrNull($row['candidate_user_id'] ?? null)
                    !== $this->positiveIntOrNull($row['novel_user_id'] ?? null)) {
                    $reasons[] = 'accepted_candidate_user_mismatch';
                }
                if (($row['lifecycle'] ?? null) !== 'committed') {
                    $reasons[] = 'accepted_candidate_not_committed';
                }
                if (($row['gate_state'] ?? null) !== 'passed') {
                    $reasons[] = 'accepted_candidate_gate_not_passed';
                }
                if (!$this->sameHash($revision, $row['candidate_content_hash'] ?? null)) {
                    $reasons[] = 'job_revision_candidate_mismatch';
                }
                if (!in_array($row['source'] ?? null, ['initial', 'rewrite'], true)) {
                    $reasons[] = 'accepted_candidate_source_invalid';
                }
                if ($rewriteAllowed === true && ($row['source'] ?? null) !== 'initial') {
                    $reasons[] = 'only_initial_candidate_job_may_rewrite';
                }
                if ($rewriteAllowed === false && ($row['source'] ?? null) === 'initial') {
                    $reasons[] = 'initial_candidate_job_lost_rewrite_eligibility';
                }
                $candidatePolicy = $this->policy(
                    $row['candidate_policy_json'] ?? null,
                    $row['candidate_policy_hash'] ?? null,
                    false,
                    true
                );
                foreach ($candidatePolicy['errors'] as $error) {
                    $reasons[] = 'candidate_' . $error;
                }
            }

            $jobPolicy = $this->policy(
                $row['job_policy_json'] ?? null,
                $row['job_policy_hash'] ?? null,
                $acceptedUid === null,
                true
            );
            foreach ($jobPolicy['errors'] as $error) {
                $reasons[] = 'job_' . $error;
            }
            if ($this->isUid($acceptedUid)
                && $jobPolicy['checksum'] !== null && $candidatePolicy['checksum'] !== null
                && !hash_equals($jobPolicy['checksum'], $candidatePolicy['checksum'])) {
                $reasons[] = 'job_policy_candidate_mismatch';
            }

            $isCurrentRevision = $this->sameHash(
                $revision,
                $row['chapter_revision_hash'] ?? null
            );
            $isCurrentCanon = ($row['canonical_state'] ?? null) === 'canonical'
                && $isCurrentRevision;
            // A revision hash is content identity, not generation identity. In
            // an A -> B -> A sequence a historical terminal job can share the
            // current bytes while belonging to a different Candidate. Only a
            // runnable generation must bind to the current Canon; terminal jobs
            // remain auditable against their own immutable Candidate above.
            if ($isActiveState && $isCurrentCanon) {
                $canonUid = $row['canonical_candidate_uid'] ?? null;
                if (!$this->isUid($canonUid)) {
                    $reasons[] = 'current_canon_candidate_uid_invalid';
                } elseif (!$this->isUid($acceptedUid) || !hash_equals($canonUid, $acceptedUid)) {
                    $reasons[] = 'current_canon_candidate_job_mismatch';
                }
                $chapterPolicy = $this->policy($row['chapter_policy_json'] ?? null, null, false);
                foreach ($chapterPolicy['errors'] as $error) {
                    $reasons[] = 'chapter_' . $error;
                }
                if ($chapterPolicy['checksum'] !== null && $jobPolicy['checksum'] !== null
                    && !hash_equals($chapterPolicy['checksum'], $jobPolicy['checksum'])) {
                    $reasons[] = 'current_canon_job_policy_mismatch';
                }
            } elseif ($isActiveState && !$isCurrentRevision) {
                $reasons[] = 'active_job_targets_noncurrent_revision';
            }

            if ($reasons !== []) {
                $this->issue($report, 'postprocess_job_binding_mismatch', [
                    'job_id' => $this->positiveIntOrNull($row['job_id'] ?? null),
                    'chapter_id' => $chapterId,
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'job_uid' => $this->safeUid($jobUid),
                    'candidate_uid' => $this->safeUid($acceptedUid),
                    'state' => $this->safeEnum($jobState),
                    'reasons' => array_values(array_unique($reasons)),
                ]);
            }
        }
    }

    /** @param array<string,mixed> $report */
    private function auditWritingLeases(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'wt');
        $rows = $this->rows(
            'writing_leases',
            "SELECT wt.id, wt.task_id, wt.novel_id, wt.chapter_id, wt.state,
                    wt.lease_owner, wt.lease_expires_at, wt.lease_token,
                    wt.fence_generation, wt.cancel_requested,
                    ch.id AS stored_chapter_id
             FROM writing_tasks wt
             LEFT JOIN chapters ch ON ch.id=wt.chapter_id
             WHERE wt.state IN ('queued','preparing','generating','validating',
                                'content_saved','postprocessing'){$filter}
             ORDER BY wt.id",
            $params
        );
        $this->recordDataset($report, 'writing_leases', count($rows));

        foreach ($rows as $row) {
            $state = $row['state'] ?? null;
            $claimed = in_array($state, [
                'preparing', 'generating', 'validating', 'content_saved', 'postprocessing',
            ], true);
            $reasons = [];
            if ($claimed) {
                if (!is_string($row['lease_owner'] ?? null) || trim($row['lease_owner']) === '') {
                    $reasons[] = 'lease_owner_missing';
                }
                if (!$this->isUid($row['lease_token'] ?? null)) {
                    $reasons[] = 'lease_token_invalid';
                }
                if (($this->nonNegativeIntOrNull($row['fence_generation'] ?? null) ?? 0) < 1) {
                    $reasons[] = 'fence_generation_not_claimed';
                }
                if ($this->dateOrNull($row['lease_expires_at'] ?? null) === null) {
                    $reasons[] = 'lease_expiry_invalid';
                }
            } else {
                if (($row['lease_owner'] ?? null) !== null || ($row['lease_token'] ?? null) !== null) {
                    $reasons[] = 'queued_task_has_partial_owner_fence';
                }
            }
            if ($reasons !== []) {
                $this->issue($report, 'writing_lease_incomplete', [
                    'task_id' => $this->safeTaskId($row['task_id'] ?? null),
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'state' => $this->safeEnum($state),
                    'reasons' => $reasons,
                ]);
            }

            $expiry = $this->dateOrNull($row['lease_expires_at'] ?? null);
            if ($expiry !== null && $expiry < $this->now) {
                $this->issue($report, 'expired_active_lease', [
                    'kind' => 'writing_task',
                    'task_id' => $this->safeTaskId($row['task_id'] ?? null),
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'state' => $this->safeEnum($state),
                    'reasons' => ['active_writing_task_lease_expired'],
                ]);
            }
            if ($claimed && ($row['chapter_id'] ?? null) !== null
                && $this->positiveIntOrNull($row['stored_chapter_id'] ?? null) === null) {
                $this->issue($report, 'orphan_async_row', [
                    'kind' => 'writing_task',
                    'task_id' => $this->safeTaskId($row['task_id'] ?? null),
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'state' => $this->safeEnum($state),
                    'reasons' => ['claimed_task_chapter_missing'],
                ]);
            }
        }
    }

    /** @param array<string,mixed> $report */
    private function auditPostprocessLeases(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'pj');
        $rows = $this->rows(
            'postprocess_leases',
            "SELECT pj.id AS job_id, pj.job_uid, pj.novel_id, pj.chapter_id,
                    pj.state, pj.lease_owner, pj.lease_expires_at,
                    pj.lease_token, pj.fence_generation,
                    ch.id AS stored_chapter_id
             FROM postprocess_jobs pj
             LEFT JOIN chapters ch ON ch.id=pj.chapter_id
             WHERE pj.state IN ('queued','retry','running'){$filter}
             ORDER BY pj.id",
            $params
        );
        $this->recordDataset($report, 'postprocess_leases', count($rows));

        foreach ($rows as $row) {
            $running = ($row['state'] ?? null) === 'running';
            $reasons = [];
            if ($running) {
                if (!is_string($row['lease_owner'] ?? null) || trim($row['lease_owner']) === '') {
                    $reasons[] = 'lease_owner_missing';
                }
                if (!$this->isUid($row['lease_token'] ?? null)) {
                    $reasons[] = 'lease_token_invalid';
                }
                if (($this->nonNegativeIntOrNull($row['fence_generation'] ?? null) ?? 0) < 1) {
                    $reasons[] = 'fence_generation_not_claimed';
                }
                if ($this->dateOrNull($row['lease_expires_at'] ?? null) === null) {
                    $reasons[] = 'lease_expiry_invalid';
                }
            } elseif (($row['lease_owner'] ?? null) !== null
                || ($row['lease_token'] ?? null) !== null
                || ($row['lease_expires_at'] ?? null) !== null) {
                $reasons[] = 'waiting_job_retains_lease_identity';
            }
            if ($reasons !== []) {
                $this->issue($report, 'postprocess_lease_incomplete', [
                    'job_id' => $this->positiveIntOrNull($row['job_id'] ?? null),
                    'job_uid' => $this->safeUid($row['job_uid'] ?? null),
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'state' => $this->safeEnum($row['state'] ?? null),
                    'reasons' => $reasons,
                ]);
            }
            $expiry = $this->dateOrNull($row['lease_expires_at'] ?? null);
            if ($running && $expiry !== null && $expiry < $this->now) {
                $this->issue($report, 'expired_active_lease', [
                    'kind' => 'postprocess_job',
                    'job_id' => $this->positiveIntOrNull($row['job_id'] ?? null),
                    'job_uid' => $this->safeUid($row['job_uid'] ?? null),
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'state' => 'running',
                    'reasons' => ['running_postprocess_lease_expired'],
                ]);
            }
            if ($this->positiveIntOrNull($row['stored_chapter_id'] ?? null) === null) {
                $this->issue($report, 'orphan_async_row', [
                    'kind' => 'postprocess_job',
                    'job_id' => $this->positiveIntOrNull($row['job_id'] ?? null),
                    'job_uid' => $this->safeUid($row['job_uid'] ?? null),
                    'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                    'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                    'state' => $this->safeEnum($row['state'] ?? null),
                    'reasons' => ['postprocess_job_chapter_missing'],
                ]);
            }
        }
    }

    /** @param array<string,mixed> $report */
    private function auditPermanentWriting(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'ch', 'id');
        $rows = $this->rows(
            'writing_chapters',
            "SELECT ch.id AS chapter_id, ch.novel_id, ch.status, ch.updated_at,
                    ch.claim_token,
                    EXISTS(
                        SELECT 1 FROM writing_tasks wt
                        WHERE wt.chapter_id=ch.id AND wt.cancel_requested=0
                          AND wt.state IN ('queued','preparing','generating','validating',
                                           'content_saved','postprocessing')
                          AND wt.lease_expires_at IS NOT NULL
                          AND wt.lease_expires_at >= ?
                    ) AS has_live_writing_task,
                    EXISTS(
                        SELECT 1 FROM postprocess_jobs pj
                        WHERE pj.chapter_id=ch.id
                          AND (pj.state IN ('queued','retry')
                               OR (pj.state='running' AND pj.lease_expires_at IS NOT NULL
                                   AND pj.lease_expires_at >= ?))
                    ) AS has_live_postprocess_job
             FROM chapters ch
             WHERE ch.status='writing'{$filter}
             ORDER BY ch.id",
            array_merge([
                $this->now->format('Y-m-d H:i:s'),
                $this->now->format('Y-m-d H:i:s'),
            ], $params)
        );
        $this->recordDataset($report, 'writing_chapters', count($rows));

        foreach ($rows as $row) {
            $updatedAt = $this->dateOrNull($row['updated_at'] ?? null);
            if ($updatedAt === null) {
                $oldEnough = true;
                $age = null;
            } else {
                $age = max(0, $this->now->getTimestamp() - $updatedAt->getTimestamp());
                $oldEnough = $age >= self::ORPHAN_GRACE_SECONDS;
            }
            if (!$oldEnough
                || $this->boolean01($row['has_live_writing_task'] ?? null) === true
                || $this->boolean01($row['has_live_postprocess_job'] ?? null) === true) {
                continue;
            }
            $this->issue($report, 'permanent_writing_orphan', [
                'chapter_id' => $this->positiveIntOrNull($row['chapter_id'] ?? null),
                'novel_id' => $this->positiveIntOrNull($row['novel_id'] ?? null),
                'age_seconds' => $age,
                'claim_present' => is_string($row['claim_token'] ?? null)
                    && $row['claim_token'] !== '',
                'reasons' => ['writing_state_without_live_async_owner'],
            ]);
        }
    }

    /** @param array<string,mixed> $report */
    private function auditLegacyShadowRuns(array $selection, array &$report): void
    {
        [$filter, $params] = $this->scopeSql($selection, 'wt');
        $rows = $this->rows(
            'legacy_shadow_runs',
            "SELECT wt.task_id, wt.user_id, wt.novel_id, wt.chapter_id,
                    wt.state AS task_state, wt.progress AS task_progress,
                    wt.content_saved, wt.chapter_revision_hash AS task_revision_hash,
                    wt.policy_snapshot_json, wt.policy_snapshot_hash,
                    gr.id AS run_id, gr.run_uid, gr.user_id AS run_user_id,
                    gr.novel_id AS run_novel_id, gr.chapter_id AS run_chapter_id,
                    gr.queue AS run_queue, gr.run_type, gr.workflow_name,
                    gr.workflow_version, gr.authority_mode, gr.state AS run_state,
                    gr.progress AS run_progress,
                    ch.content_revision_hash AS chapter_revision_hash,
                    ga.content_hash AS draft_content_hash,
                    EXISTS(
                        SELECT 1 FROM postprocess_jobs pj
                        WHERE pj.chapter_id=wt.chapter_id
                          AND pj.novel_id=wt.novel_id
                          AND BINARY pj.revision_hash=BINARY wt.chapter_revision_hash
                    ) AS has_revision_postprocess_job,
                    (SELECT COUNT(*) FROM generation_events ge
                     WHERE ge.run_id=gr.id) AS shadow_event_count,
                    (SELECT JSON_UNQUOTE(JSON_EXTRACT(ge.payload_json, '$.legacy_state'))
                     FROM generation_events ge
                     WHERE ge.run_id=gr.id
                     ORDER BY ge.event_no DESC LIMIT 1) AS latest_event_state,
                    (SELECT JSON_UNQUOTE(JSON_EXTRACT(ge.payload_json, '$.progress'))
                     FROM generation_events ge
                     WHERE ge.run_id=gr.id
                     ORDER BY ge.event_no DESC LIMIT 1) AS latest_event_progress
             FROM writing_tasks wt
             LEFT JOIN generation_runs gr
               ON gr.user_id=wt.user_id
              AND gr.idempotency_key=CONCAT('legacy:writing_task:', wt.task_id)
             LEFT JOIN chapters ch ON ch.id=wt.chapter_id
             LEFT JOIN generation_artifacts ga
               ON ga.id=(
                   SELECT ga2.id FROM generation_artifacts ga2
                   WHERE ga2.run_id=gr.id AND ga2.logical_key='legacy.draft.full'
                   ORDER BY ga2.revision_no DESC, ga2.id DESC LIMIT 1
               )
             WHERE JSON_UNQUOTE(JSON_EXTRACT(wt.policy_snapshot_json, '$.harness_mode'))
                   IN ('shadow','canary','on'){$filter}
             ORDER BY wt.id",
            $params
        );
        $this->recordDataset($report, 'legacy_shadow_runs', count($rows));

        foreach ($rows as $row) {
            $taskId = $row['task_id'] ?? null;
            $taskIdSafe = $this->safeTaskId($taskId);
            $novelId = $this->positiveIntOrNull($row['novel_id'] ?? null);
            $chapterId = $this->positiveIntOrNull($row['chapter_id'] ?? null);

            try {
                if (!is_string($taskId)) {
                    throw new InvalidArgumentException('task_id is malformed.');
                }
                LegacyWritingTaskRunIdentity::assertTaskId($taskId);
                $policyJson = $row['policy_snapshot_json'] ?? null;
                $policyHash = $row['policy_snapshot_hash'] ?? null;
                if (!is_string($policyJson) || !is_string($policyHash)) {
                    throw new InvalidArgumentException('policy snapshot pair is missing.');
                }
                $policy = WritePolicySnapshot::fromJson($policyJson);
                if (!hash_equals($policy->checksum(), $policyHash)
                    || $policy->harnessMode() === 'off') {
                    throw new InvalidArgumentException('policy snapshot is not an enabled authenticated snapshot.');
                }
            } catch (Throwable) {
                $this->issue($report, 'shadow_run_identity_mismatch', [
                    'task_id' => $taskIdSafe,
                    'novel_id' => $novelId,
                    'chapter_id' => $chapterId,
                    'diff' => ['policy_snapshot' => ['expected' => 'authenticated_non_off', 'actual' => 'invalid']],
                ]);
                continue;
            }

            $runId = $this->positiveIntOrNull($row['run_id'] ?? null);
            if ($runId === null) {
                $this->issue($report, 'shadow_run_missing', [
                    'task_id' => $taskIdSafe,
                    'novel_id' => $novelId,
                    'chapter_id' => $chapterId,
                    'diff' => ['run_id' => ['expected' => 'present', 'actual' => null]],
                ]);
                continue;
            }

            $identityDiff = [];
            $expectedIdentity = [
                'run_uid' => LegacyWritingTaskRunIdentity::runUid($taskId),
                'user_id' => $this->positiveIntOrNull($row['user_id'] ?? null),
                'novel_id' => $novelId,
                'chapter_id' => $chapterId,
                'queue' => LegacyWritingTaskRunIdentity::QUEUE,
                'run_type' => LegacyWritingTaskRunIdentity::RUN_TYPE,
                'workflow_name' => LegacyWritingTaskRunIdentity::WORKFLOW_NAME,
                'workflow_version' => LegacyWritingTaskRunIdentity::WORKFLOW_VERSION,
                'authority_mode' => LegacyWritingTaskRunIdentity::AUTHORITY_MODE,
            ];
            $actualIdentity = [
                'run_uid' => is_string($row['run_uid'] ?? null) ? $row['run_uid'] : null,
                'user_id' => $this->positiveIntOrNull($row['run_user_id'] ?? null),
                'novel_id' => $this->positiveIntOrNull($row['run_novel_id'] ?? null),
                'chapter_id' => $this->positiveIntOrNull($row['run_chapter_id'] ?? null),
                'queue' => $this->safeEnum($row['run_queue'] ?? null),
                'run_type' => $this->safeIdentifier($row['run_type'] ?? null),
                'workflow_name' => $this->safeIdentifier($row['workflow_name'] ?? null),
                'workflow_version' => $this->safeIdentifier($row['workflow_version'] ?? null),
                'authority_mode' => $this->safeEnum($row['authority_mode'] ?? null),
            ];
            foreach ($expectedIdentity as $field => $expected) {
                if ($actualIdentity[$field] !== $expected) {
                    $identityDiff[$field] = ['expected' => $expected, 'actual' => $actualIdentity[$field]];
                }
            }
            if ($identityDiff !== []) {
                $this->issue($report, 'shadow_run_identity_mismatch', [
                    'task_id' => $taskIdSafe,
                    'novel_id' => $novelId,
                    'chapter_id' => $chapterId,
                    'run_id' => $runId,
                    'diff' => $identityDiff,
                ]);
            }

            $taskState = $this->safeEnum($row['task_state'] ?? null);
            $expectedRunState = $this->expectedShadowRunState($taskState);
            $taskProgress = $this->nonNegativeIntOrNull($row['task_progress'] ?? null);
            $runProgress = $this->nonNegativeIntOrNull($row['run_progress'] ?? null);
            $stateDiff = [];
            if (($row['run_state'] ?? null) !== $expectedRunState) {
                $stateDiff['state'] = [
                    'expected' => $expectedRunState,
                    'actual' => $this->safeEnum($row['run_state'] ?? null),
                ];
            }
            if ($runProgress !== $taskProgress) {
                $stateDiff['progress'] = ['expected' => $taskProgress, 'actual' => $runProgress];
            }
            if ($stateDiff !== []) {
                $this->issue($report, 'shadow_state_progress_mismatch', [
                    'task_id' => $taskIdSafe,
                    'novel_id' => $novelId,
                    'chapter_id' => $chapterId,
                    'run_id' => $runId,
                    'diff' => $stateDiff,
                ]);
            }

            $contentSaved = $this->boolean01($row['content_saved'] ?? null) === true;
            if ($contentSaved) {
                $expectedHash = $row['task_revision_hash'] ?? null;
                $contentDiff = [];
                foreach ([
                    'chapter_revision_hash' => $row['chapter_revision_hash'] ?? null,
                    'draft_content_hash' => $row['draft_content_hash'] ?? null,
                ] as $field => $actualHash) {
                    if (!$this->sameHash($expectedHash, $actualHash)) {
                        $contentDiff[$field] = [
                            'expected' => $this->isHash($expectedHash) ? $expectedHash : null,
                            'actual' => $this->isHash($actualHash) ? $actualHash : null,
                        ];
                    }
                }
                if ($contentDiff !== []) {
                    $this->issue($report, 'shadow_content_revision_mismatch', [
                        'task_id' => $taskIdSafe,
                        'novel_id' => $novelId,
                        'chapter_id' => $chapterId,
                        'run_id' => $runId,
                        'diff' => $contentDiff,
                    ]);
                }
                if ($this->boolean01($row['has_revision_postprocess_job'] ?? null) !== true) {
                    $this->issue($report, 'shadow_postprocess_mismatch', [
                        'task_id' => $taskIdSafe,
                        'novel_id' => $novelId,
                        'chapter_id' => $chapterId,
                        'run_id' => $runId,
                        'diff' => ['postprocess_job' => ['expected' => 'revision_bound', 'actual' => 'missing']],
                    ]);
                }
            }

            $eventDiff = [];
            if ($this->nonNegativeIntOrNull($row['shadow_event_count'] ?? null) === 0) {
                $eventDiff['event_count'] = ['expected' => 'positive', 'actual' => 0];
            }
            if (($row['latest_event_state'] ?? null) !== $taskState) {
                $eventDiff['legacy_state'] = [
                    'expected' => $taskState,
                    'actual' => $this->safeEnum($row['latest_event_state'] ?? null),
                ];
            }
            $eventProgress = $this->nonNegativeIntOrNull($row['latest_event_progress'] ?? null);
            if ($eventProgress !== $taskProgress) {
                $eventDiff['progress'] = ['expected' => $taskProgress, 'actual' => $eventProgress];
            }
            if ($eventDiff !== []) {
                $this->issue($report, 'shadow_event_mismatch', [
                    'task_id' => $taskIdSafe,
                    'novel_id' => $novelId,
                    'chapter_id' => $chapterId,
                    'run_id' => $runId,
                    'diff' => $eventDiff,
                ]);
            }
        }
    }

    private function expectedShadowRunState(?string $legacyState): ?string
    {
        return match ($legacyState) {
            'queued' => 'queued',
            'preparing', 'generating', 'validating', 'content_saved', 'postprocessing' => 'running',
            'done' => 'succeeded',
            'failed' => 'failed',
            'canceled' => 'canceled',
            default => null,
        };
    }

    /**
     * @param array{filter:?string,id:?int} $selection
     * @return array{string,array<int,int>}
     */
    private function scopeSql(
        array $selection,
        string $alias,
        string $chapterColumn = 'chapter_id'
    ): array
    {
        if ($selection['filter'] === null) {
            return ['', []];
        }
        if ($selection['filter'] === 'novel') {
            return [" AND {$alias}.novel_id=?", [(int)$selection['id']]];
        }
        return [" AND {$alias}.{$chapterColumn}=?", [(int)$selection['id']]];
    }

    /**
     * @param array<int,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $dataset, string $sql, array $params): array
    {
        $reader = $this->reader;
        $rows = $reader($dataset, $sql, $params);
        if (!is_array($rows)) {
            throw new UnexpectedValueException("Reconciler dataset {$dataset} did not return an array.");
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new UnexpectedValueException("Reconciler dataset {$dataset} returned a non-row value.");
            }
            $normalized[] = $row;
        }
        return $normalized;
    }

    /** @param array<string,mixed> $report */
    private function recordDataset(array &$report, string $dataset, int $rows): void
    {
        $report['datasets'][$dataset] = $rows;
        $report['summary']['datasets']++;
        $report['summary']['rows'] += $rows;
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $sample */
    private function issue(array &$report, string $type, array $sample): void
    {
        if (!array_key_exists($type, $report['counts'])) {
            throw new LogicException("Unknown harness reconciliation issue type: {$type}");
        }
        $report['counts'][$type]++;
        if (count($report['samples'][$type]) < $report['sample_limit']) {
            $report['samples'][$type][] = $sample;
        }
    }

    /**
     * @return array{checksum:?string,errors:list<string>}
     */
    private function policy(
        mixed $json,
        mixed $rowHash,
        bool $allowMissing,
        bool $requireRowHash = false
    ): array
    {
        $jsonMissing = $json === null || $json === '';
        $hashMissing = $rowHash === null || $rowHash === '';
        if ($jsonMissing && (!$requireRowHash || $hashMissing)) {
            return $allowMissing
                ? ['checksum' => null, 'errors' => []]
                : ['checksum' => null, 'errors' => ['policy_snapshot_missing']];
        }
        if ($jsonMissing || ($requireRowHash && $hashMissing)) {
            return ['checksum' => null, 'errors' => ['policy_snapshot_pair_incomplete']];
        }
        if (!is_string($json)) {
            return ['checksum' => null, 'errors' => ['policy_snapshot_json_invalid']];
        }
        try {
            $snapshot = WritePolicySnapshot::fromJson($json);
        } catch (Throwable) {
            return ['checksum' => null, 'errors' => ['policy_snapshot_checksum_invalid']];
        }
        $checksum = $snapshot->checksum();
        if ($requireRowHash && (!$this->isHash($rowHash) || !hash_equals($checksum, $rowHash))) {
            return ['checksum' => $checksum, 'errors' => ['policy_snapshot_row_hash_mismatch']];
        }
        return ['checksum' => $checksum, 'errors' => []];
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function sameHash(mixed $left, mixed $right): bool
    {
        return $this->isHash($left) && $this->isHash($right) && hash_equals($left, $right);
    }

    private function isUid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
    }

    private function safeUid(mixed $value): ?string
    {
        return $this->isUid($value) ? $value : null;
    }

    private function safeTaskId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $value) === 1
            ? $value
            : null;
    }

    private function safeEnum(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $value) === 1
            ? $value
            : null;
    }

    private function safeIdentifier(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,63}$/D', $value) === 1
            ? $value
            : null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $parsed = $this->nonNegativeIntOrNull($value);
        return $parsed !== null && $parsed > 0 ? $parsed : null;
    }

    private function nonNegativeIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
        ]);
        return is_int($parsed) ? $parsed : null;
    }

    private function boolean01(mixed $value): ?bool
    {
        if ($value === 1 || $value === '1' || $value === true) {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === false) {
            return false;
        }
        return null;
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            $this->now->getTimezone()
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable
            || (is_array($errors)
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d H:i:s') !== $value) {
            return null;
        }
        return $parsed;
    }
}
