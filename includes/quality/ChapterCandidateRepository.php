<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/harness/persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/WritePolicySnapshot.php';
require_once __DIR__ . '/QualityGateDecision.php';

/**
 * Persistence boundary for pre-Canon chapter candidates.
 *
 * This repository deliberately never mutates chapters or opens a transaction.
 * A caller can therefore compose a candidate transition and the corresponding
 * Canon/task mutations inside one externally-owned transaction.
 */
final class ChapterCandidateRepository
{
    public const SOURCE_INITIAL = 'initial';
    public const SOURCE_REWRITE = 'rewrite';

    public const LIFECYCLE_DRAFT = 'draft';
    public const LIFECYCLE_EVALUATED = 'evaluated';
    public const LIFECYCLE_QUARANTINED = 'quarantined';
    public const LIFECYCLE_COMMITTED = 'committed';
    public const LIFECYCLE_SUPERSEDED = 'superseded';

    public const GATE_PENDING = 'pending';
    public const GATE_PASSED = 'passed';
    public const GATE_QUARANTINED = 'quarantined';

    /** @var list<string> */
    private const DRAFT_FIELDS = [
        'candidate_uid',
        'user_id',
        'novel_id',
        'chapter_id',
        'source',
        'source_run_uid',
        'source_task_id',
        'parent_candidate_uid',
        'base_revision_hash',
        'base_snapshot_json',
        'inline_content',
        'content_hash',
        'words',
    ];

    /** @var list<string> */
    private const BASE_SNAPSHOT_FIELDS = [
        'status',
        'canonical_state',
        'content_revision_hash',
        'content_hash',
        'claim_token',
    ];

    /**
     * Persist a complete draft before invoking the quality gate.
     *
     * candidate_uid is the sole idempotency identity. Reusing the same UID with
     * the exact immutable payload returns the persisted row; a different UID is
     * always a distinct attempt even when its chapter content is byte-identical.
     * A UID collision with any different immutable field fails closed.
     *
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    public static function createDraft(array $draft, WritePolicySnapshot $policySnapshot): array
    {
        $row = self::normalizeDraft($draft, $policySnapshot);

        $existing = self::findByUid($row['candidate_uid']);
        if ($existing !== null) {
            self::assertIdempotentRow($existing, $row);
            return $existing;
        }

        DB::execute(
            "INSERT INTO `chapter_candidates` (
                `candidate_uid`, `user_id`, `novel_id`, `chapter_id`, `source`,
                `source_run_uid`, `source_task_id`, `parent_candidate_uid`,
                `base_revision_hash`, `base_snapshot_json`, `inline_content`,
                `content_hash`, `words`, `lifecycle`, `gate_state`,
                `validation_json`, `quarantine_reason`, `policy_snapshot_json`,
                `policy_snapshot_hash`
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`)",
            [
                $row['candidate_uid'],
                $row['user_id'],
                $row['novel_id'],
                $row['chapter_id'],
                $row['source'],
                $row['source_run_uid'],
                $row['source_task_id'],
                $row['parent_candidate_uid'],
                $row['base_revision_hash'],
                $row['base_snapshot_json'],
                $row['inline_content'],
                $row['content_hash'],
                $row['words'],
                self::LIFECYCLE_DRAFT,
                self::GATE_PENDING,
                null,
                null,
                $row['policy_snapshot_json'],
                $row['policy_snapshot_hash'],
            ]
        );

        $created = self::findByUid($row['candidate_uid']);
        if ($created === null) {
            throw new RuntimeException(
                'Candidate could not be read back by its idempotency UID.'
            );
        }

        self::assertIdempotentRow($created, $row);
        return $created;
    }

    /** @return array<string,mixed>|null */
    public static function findByUid(string $candidateUid): ?array
    {
        self::assertUid($candidateUid, 'candidate_uid');
        $row = DB::fetch(
            'SELECT * FROM `chapter_candidates` WHERE `candidate_uid`=? LIMIT 1',
            [$candidateUid]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * CAS: draft/pending -> evaluated/passed.
     *
     * Quarantine decisions must use markQuarantined(), which keeps the blocking
     * decision and quarantine state in one database mutation.
     */
    public static function markEvaluated(
        string $candidateUid,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision
    ): bool {
        self::assertUid($candidateUid, 'candidate_uid');
        self::assertDecisionMatchesPolicy($decision, $policySnapshot);
        if ($decision->shouldQuarantine()) {
            throw new InvalidArgumentException(
                'A quarantine decision must use markQuarantined(), not markEvaluated().'
            );
        }

        $m63 = (int)(DB::fetchColumn(
            'SELECT COUNT(*) FROM chapter_candidates '
            . 'WHERE candidate_uid=? AND run_id IS NOT NULL '
            . 'AND draft_artifact_id IS NOT NULL AND validation_artifact_id IS NOT NULL',
            [$candidateUid]
        ) ?: 0) === 1;
        $targetLifecycle = $m63 ? 'approved' : self::LIFECYCLE_EVALUATED;

        return DB::execute(
            "UPDATE `chapter_candidates`
             SET `lifecycle`=?, `gate_state`=?, `validation_json`=?,
                 `evaluated_at`=?, `approved_at`=IF(?='approved', ?, `approved_at`),
                 `updated_at`=NOW(6)
             WHERE `candidate_uid`=? AND `lifecycle`=? AND `gate_state`=?
               AND `content_hash`=? AND `policy_snapshot_hash`=?
               AND `validation_json` IS NULL",
            [
                $targetLifecycle,
                self::GATE_PASSED,
                self::encodeJson($decision->toArray()),
                self::toDatabaseTimestamp($decision->evaluatedAt()),
                $targetLifecycle,
                self::toDatabaseTimestamp($decision->evaluatedAt()),
                $candidateUid,
                self::LIFECYCLE_DRAFT,
                self::GATE_PENDING,
                $decision->contentHash(),
                $policySnapshot->checksum(),
            ]
        ) === 1;
    }

    /** CAS: draft/pending -> quarantined/quarantined. */
    public static function markQuarantined(
        string $candidateUid,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision,
        string $reason
    ): bool {
        self::assertUid($candidateUid, 'candidate_uid');
        self::assertDecisionMatchesPolicy($decision, $policySnapshot);
        if (!$decision->shouldQuarantine()) {
            throw new InvalidArgumentException('markQuarantined() requires a quarantine decision.');
        }
        $reason = self::requireReason($reason);

        return DB::execute(
            "UPDATE `chapter_candidates`
             SET `lifecycle`=?, `gate_state`=?, `validation_json`=?,
                 `quarantine_reason`=?, `evaluated_at`=?,
                 `quarantined_at`=NOW(6), `updated_at`=NOW(6)
             WHERE `candidate_uid`=? AND `lifecycle`=? AND `gate_state`=?
               AND `content_hash`=? AND `policy_snapshot_hash`=?
               AND `validation_json` IS NULL",
            [
                self::LIFECYCLE_QUARANTINED,
                self::GATE_QUARANTINED,
                self::encodeJson($decision->toArray()),
                $reason,
                self::toDatabaseTimestamp($decision->evaluatedAt()),
                $candidateUid,
                self::LIFECYCLE_DRAFT,
                self::GATE_PENDING,
                $decision->contentHash(),
                $policySnapshot->checksum(),
            ]
        ) === 1;
    }

    /** CAS: evaluated/passed -> committed. This method never updates Canon. */
    public static function markCommitted(
        string $candidateUid,
        string $contentHash,
        WritePolicySnapshot $policySnapshot
    ): bool {
        self::assertUid($candidateUid, 'candidate_uid');
        self::assertSha256($contentHash, 'content_hash');

        return DB::execute(
            "UPDATE `chapter_candidates`
             SET `lifecycle`=?, `committed_at`=NOW(6), `updated_at`=NOW(6)
             WHERE `candidate_uid`=?
               AND ((`lifecycle`='evaluated' AND `gate_state`='passed')
                    OR (`lifecycle`='approved' AND `gate_state`='passed')
                    OR (`lifecycle`='approved' AND `gate_state`='quarantined'))
               AND `content_hash`=? AND `policy_snapshot_hash`=?
               AND `validation_json` IS NOT NULL",
            [
                self::LIFECYCLE_COMMITTED,
                $candidateUid,
                $contentHash,
                $policySnapshot->checksum(),
            ]
        ) === 1;
    }

    /**
     * CAS an exact non-committed lifecycle into superseded.
     *
     * Requiring the caller's expected lifecycle prevents a delayed retry from
     * superseding a candidate that another worker has already advanced.
     */
    public static function markSuperseded(string $candidateUid, string $expectedLifecycle): bool
    {
        self::assertUid($candidateUid, 'candidate_uid');
        if (!in_array($expectedLifecycle, [
            self::LIFECYCLE_DRAFT,
            self::LIFECYCLE_EVALUATED,
            self::LIFECYCLE_QUARANTINED,
        ], true)) {
            throw new InvalidArgumentException('Only a non-committed candidate may be superseded.');
        }

        return DB::execute(
            "UPDATE `chapter_candidates`
             SET `lifecycle`=?, `updated_at`=NOW(6)
             WHERE `candidate_uid`=? AND `lifecycle`=?",
            [self::LIFECYCLE_SUPERSEDED, $candidateUid, $expectedLifecycle]
        ) === 1;
    }

    /**
     * @param array<string,mixed> $draft
     * @return array<string,int|string|null>
     */
    private static function normalizeDraft(array $draft, WritePolicySnapshot $policySnapshot): array
    {
        self::assertExactFields($draft, self::DRAFT_FIELDS, 'candidate draft');

        $candidateUid = self::requireUid($draft['candidate_uid'], 'candidate_uid');
        $userId = self::requirePositiveInteger($draft['user_id'], 'user_id');
        $novelId = self::requirePositiveInteger($draft['novel_id'], 'novel_id');
        $chapterId = self::requirePositiveInteger($draft['chapter_id'], 'chapter_id');
        $source = self::requireString($draft['source'], 'source');
        if (!in_array($source, [self::SOURCE_INITIAL, self::SOURCE_REWRITE], true)) {
            throw new InvalidArgumentException('source must be initial or rewrite.');
        }

        $sourceRunUid = self::requireOptionalUid($draft['source_run_uid'], 'source_run_uid');
        $sourceTaskId = self::requireOptionalTaskId($draft['source_task_id']);
        $parentCandidateUid = self::requireOptionalUid(
            $draft['parent_candidate_uid'],
            'parent_candidate_uid'
        );
        if ($source === self::SOURCE_INITIAL && $parentCandidateUid !== null) {
            throw new InvalidArgumentException('An initial candidate cannot have a parent candidate.');
        }
        if ($source === self::SOURCE_REWRITE && $parentCandidateUid === null) {
            throw new InvalidArgumentException('A rewrite candidate requires parent_candidate_uid.');
        }
        if ($parentCandidateUid !== null && hash_equals($candidateUid, $parentCandidateUid)) {
            throw new InvalidArgumentException('A candidate cannot be its own parent.');
        }

        $baseRevisionHash = self::requireOptionalSha256(
            $draft['base_revision_hash'],
            'base_revision_hash'
        );
        $baseSnapshot = self::normalizeBaseSnapshot($draft['base_snapshot_json'], $baseRevisionHash);

        // Even an empty/truncated model result is evidence that belongs in the
        // Candidate ledger.  It will be deterministically quarantined by the
        // canonical quality gate; rejecting it here would recreate the old
        // "validate before persistence" blind spot.
        if (!is_string($draft['inline_content'])) {
            throw new InvalidArgumentException('inline_content must be a string.');
        }
        $content = $draft['inline_content'];
        $contentHash = self::requireSha256($draft['content_hash'], 'content_hash');
        if (!hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException('content_hash does not match inline_content.');
        }
        $words = self::requireNonNegativeInteger($draft['words'], 'words');

        return [
            'candidate_uid' => $candidateUid,
            'user_id' => $userId,
            'novel_id' => $novelId,
            'chapter_id' => $chapterId,
            'source' => $source,
            'source_run_uid' => $sourceRunUid,
            'source_task_id' => $sourceTaskId,
            'parent_candidate_uid' => $parentCandidateUid,
            'base_revision_hash' => $baseRevisionHash,
            'base_snapshot_json' => self::encodeJson($baseSnapshot),
            'inline_content' => $content,
            'content_hash' => $contentHash,
            'words' => $words,
            'policy_snapshot_json' => self::encodeJson($policySnapshot->toArray()),
            'policy_snapshot_hash' => $policySnapshot->checksum(),
        ];
    }

    /**
     * @return array{status:string,canonical_state:string,content_revision_hash:?string,content_hash:?string,claim_token:string}
     */
    private static function normalizeBaseSnapshot(mixed $value, ?string $baseRevisionHash): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('base_snapshot_json must be supplied as a JSON-object array.');
        }
        self::assertExactFields($value, self::BASE_SNAPSHOT_FIELDS, 'base snapshot');

        $status = self::requireString($value['status'], 'base_snapshot_json.status');
        if (preg_match('/^[a-z][a-z0-9_]{0,23}$/D', $status) !== 1) {
            throw new InvalidArgumentException('base_snapshot_json.status is invalid.');
        }
        if ($status === 'writing') {
            throw new InvalidArgumentException(
                'base_snapshot_json.status must describe the state before chapter acquisition.'
            );
        }
        $canonicalState = self::requireString(
            $value['canonical_state'],
            'base_snapshot_json.canonical_state'
        );
        if (!in_array($canonicalState, ['none', 'legacy', 'canonical'], true)) {
            throw new InvalidArgumentException('base_snapshot_json.canonical_state is invalid.');
        }

        $revisionHash = self::requireOptionalSha256(
            $value['content_revision_hash'],
            'base_snapshot_json.content_revision_hash'
        );
        $contentHash = self::requireOptionalSha256(
            $value['content_hash'],
            'base_snapshot_json.content_hash'
        );
        $claimToken = self::requireUid($value['claim_token'], 'base_snapshot_json.claim_token');

        if ($canonicalState === 'none') {
            if ($baseRevisionHash !== null || $revisionHash !== null) {
                throw new InvalidArgumentException(
                    'A base snapshot without Canon must not contain a Canon revision hash.'
                );
            }
        } elseif (
            $baseRevisionHash === null
            || $revisionHash === null
            || $contentHash === null
            || !hash_equals($baseRevisionHash, $revisionHash)
            || !hash_equals($revisionHash, $contentHash)
        ) {
            throw new InvalidArgumentException(
                'A Canon base snapshot requires one matching base/content revision hash.'
            );
        }

        return [
            'status' => $status,
            'canonical_state' => $canonicalState,
            'content_revision_hash' => $revisionHash,
            'content_hash' => $contentHash,
            'claim_token' => $claimToken,
        ];
    }

    private static function assertDecisionMatchesPolicy(
        QualityGateDecision $decision,
        WritePolicySnapshot $policySnapshot
    ): void {
        if (!hash_equals($decision->policyMode(), $policySnapshot->canonPolicy())) {
            throw new InvalidArgumentException('Quality decision policy mode does not match the frozen snapshot.');
        }
        if (!hash_equals($decision->policyVersion(), $policySnapshot->qualityRuleVersion())) {
            throw new InvalidArgumentException(
                'Quality decision rule version does not match the frozen snapshot.'
            );
        }
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $draft */
    private static function assertIdempotentRow(array $existing, array $draft): void
    {
        $existingCandidateUid = $existing['candidate_uid'] ?? null;
        $existingContentHash = $existing['content_hash'] ?? null;
        $existingPolicyHash = $existing['policy_snapshot_hash'] ?? null;
        $existingBaseRevisionHash = $existing['base_revision_hash'] ?? null;
        $existingBaseSnapshot = self::decodeJsonObject(
            $existing['base_snapshot_json'] ?? null,
            'existing base_snapshot_json'
        );
        $draftBaseSnapshot = self::decodeJsonObject(
            $draft['base_snapshot_json'] ?? null,
            'draft base_snapshot_json'
        );
        $existingPolicySnapshot = self::decodeJsonObject(
            $existing['policy_snapshot_json'] ?? null,
            'existing policy_snapshot_json'
        );
        $draftPolicySnapshot = self::decodeJsonObject(
            $draft['policy_snapshot_json'] ?? null,
            'draft policy_snapshot_json'
        );

        if (
            !is_string($existingCandidateUid)
            || !hash_equals($draft['candidate_uid'], $existingCandidateUid)
            || (int)($existing['user_id'] ?? 0) !== $draft['user_id']
            || (int)($existing['novel_id'] ?? 0) !== $draft['novel_id']
            || (int)($existing['chapter_id'] ?? 0) !== $draft['chapter_id']
            || ($existing['source'] ?? null) !== $draft['source']
            || ($existing['source_run_uid'] ?? null) !== $draft['source_run_uid']
            || ($existing['source_task_id'] ?? null) !== $draft['source_task_id']
            || ($existing['parent_candidate_uid'] ?? null) !== $draft['parent_candidate_uid']
            || $existingBaseRevisionHash !== $draft['base_revision_hash']
            || !is_string($existing['inline_content'] ?? null)
            || !hash_equals($draft['inline_content'], $existing['inline_content'])
            || !is_string($existingContentHash)
            || !hash_equals($draft['content_hash'], $existingContentHash)
            || (int)($existing['words'] ?? -1) !== $draft['words']
            || !is_string($existingPolicyHash)
            || !hash_equals($draft['policy_snapshot_hash'], $existingPolicyHash)
            || $existingBaseSnapshot !== $draftBaseSnapshot
            || $existingPolicySnapshot !== $draftPolicySnapshot
        ) {
            throw new RuntimeException(
                'Candidate UID resolved to a different immutable draft payload.'
            );
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertExactFields(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        $missing = array_values(array_diff($expected, $actual));
        $unknown = array_values(array_diff($actual, $expected));
        if ($missing !== [] || $unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s fields mismatch; missing=[%s] unknown=[%s].',
                $label,
                implode(',', $missing),
                implode(',', $unknown)
            ));
        }
    }

    private static function assertUid(string $value, string $field): void
    {
        self::requireUid($value, $field);
    }

    private static function requireUid(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase 32-character hex UID.");
        }
        return $value;
    }

    private static function requireOptionalUid(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::requireUid($value, $field);
    }

    private static function requireOptionalTaskId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('source_task_id is invalid.');
        }
        return $value;
    }

    private static function requireSha256(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$field} must be a lowercase SHA-256 hash.");
        }
        self::assertSha256($value, $field);
        return $value;
    }

    private static function requireOptionalSha256(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::requireSha256($value, $field);
    }

    private static function assertSha256(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase SHA-256 hash.");
        }
    }

    private static function requirePositiveInteger(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }
        return $value;
    }

    private static function requireNonNegativeInteger(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException("{$field} must be a non-negative integer.");
        }
        return $value;
    }

    private static function requireString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$field} must be a non-empty string.");
        }
        return $value;
    }

    private static function requireReason(string $reason): string
    {
        $reason = trim($reason);
        $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($reason === '' || $length > 4000) {
            throw new InvalidArgumentException('quarantine_reason must contain 1 to 4000 characters.');
        }
        return $reason;
    }

    /** @param array<string,mixed> $value */
    private static function encodeJson(array $value): string
    {
        return HarnessPersistenceValue::canonicalMysqlJson($value);
    }

    /** @return array<string,mixed> */
    private static function decodeJsonObject(mixed $value, string $field): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("{$field} is not valid JSON.", 0, $e);
            }
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException("{$field} must contain a JSON object.");
        }
        ksort($value, SORT_STRING);
        return $value;
    }

    private static function toDatabaseTimestamp(string $timestamp): string
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $timestamp,
            new DateTimeZone('UTC')
        );
        if ($parsed === false) {
            throw new InvalidArgumentException('Quality decision timestamp is invalid.');
        }
        return $parsed->format('Y-m-d H:i:s.000000');
    }
}
