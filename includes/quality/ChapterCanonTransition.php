<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/NovelProjectionFence.php';
require_once __DIR__ . '/WritePolicySnapshot.php';
require_once __DIR__ . '/WritePolicySnapshotFactory.php';
require_once __DIR__ . '/QualityGateDecision.php';
require_once __DIR__ . '/ChapterCandidateRepository.php';

/**
 * A failed Candidate -> Canon compare-and-swap.
 *
 * Callers may safely retry only after acquiring a fresh chapter claim and
 * creating (or reloading) the matching frozen Candidate.
 */
final class ChapterCanonTransitionException extends RuntimeException
{
}

/**
 * Minimal persistence seam used to test transaction ownership and ordering
 * without weakening the production DB boundary.
 */
interface ChapterCanonTransitionStore
{
    public function inTransaction(): bool;

    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;

    /** Lock and authenticate the live emergency epoch until transaction end. */
    public function lockEmergencyEpoch(WritePolicySnapshot $snapshot): void;

    /** @return array<string,mixed>|null */
    public function lockChapter(int $chapterId): ?array;

    /** @return array<string,mixed>|null */
    public function lockCandidate(string $candidateUid): ?array;

    public function markCandidateEvaluated(
        string $candidateUid,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision
    ): bool;

    public function markCandidateQuarantined(
        string $candidateUid,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision,
        string $reason
    ): bool;

    public function promoteChapter(
        int $chapterId,
        int $novelId,
        string $claimToken,
        string $expectedCanonicalState,
        ?string $expectedRevisionHash,
        string $content,
        int $words,
        string $revisionHash,
        string $candidateUid,
        string $policySnapshotJson
    ): bool;

    public function restoreChapter(
        int $chapterId,
        int $novelId,
        string $claimToken,
        string $baseStatus,
        string $expectedCanonicalState,
        ?string $expectedRevisionHash
    ): bool;

    public function markCandidateCommitted(
        string $candidateUid,
        string $contentHash,
        WritePolicySnapshot $policySnapshot
    ): bool;
}

/** Production adapter. It deliberately delegates Candidate CAS operations. */
final class DbChapterCanonTransitionStore implements ChapterCanonTransitionStore
{
    public function inTransaction(): bool
    {
        return DB::inTransaction();
    }

    public function beginTransaction(): bool
    {
        return DB::beginTransaction();
    }

    public function commit(): bool
    {
        return DB::commit();
    }

    public function rollBack(): bool
    {
        return DB::rollBack();
    }

    public function lockEmergencyEpoch(WritePolicySnapshot $snapshot): void
    {
        WritePolicySnapshotFactory::lockEmergencyEpochForCommit($snapshot);
    }

    public function lockChapter(int $chapterId): ?array
    {
        $row = DB::fetch(
            'SELECT c.*, n.user_id AS novel_user_id
             FROM `chapters` c
             INNER JOIN `novels` n ON n.id=c.novel_id
             WHERE c.id=?
             LIMIT 1
             FOR UPDATE',
            [$chapterId]
        );

        return is_array($row) ? $row : null;
    }

    public function lockCandidate(string $candidateUid): ?array
    {
        $row = DB::fetch(
            'SELECT * FROM `chapter_candidates`
             WHERE `candidate_uid`=?
             LIMIT 1
             FOR UPDATE',
            [$candidateUid]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Reacquire only the frozen base for a terminal human approval. The claim
     * is created and consumed inside this same Canon transaction, so waiting
     * approval never retains a worker or chapter lease.
     */
    public function claimApprovedChapter(
        int $chapterId,
        int $novelId,
        string $claimToken,
        string $baseStatus,
        string $expectedCanonicalState,
        ?string $expectedRevisionHash
    ): bool {
        return DB::execute(
            "UPDATE `chapters`
             SET `status`='writing', `claim_token`=?
             WHERE `id`=? AND `novel_id`=? AND `status`=?
               AND `canonical_state`=? AND `content_revision_hash` <=> ?",
            [
                $claimToken,
                $chapterId,
                $novelId,
                $baseStatus,
                $expectedCanonicalState,
                $expectedRevisionHash,
            ]
        ) === 1;
    }

    public function markCandidateEvaluated(
        string $candidateUid,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision
    ): bool {
        return ChapterCandidateRepository::markEvaluated(
            $candidateUid,
            $policySnapshot,
            $decision
        );
    }

    public function markCandidateQuarantined(
        string $candidateUid,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision,
        string $reason
    ): bool {
        return ChapterCandidateRepository::markQuarantined(
            $candidateUid,
            $policySnapshot,
            $decision,
            $reason
        );
    }

    public function promoteChapter(
        int $chapterId,
        int $novelId,
        string $claimToken,
        string $expectedCanonicalState,
        ?string $expectedRevisionHash,
        string $content,
        int $words,
        string $revisionHash,
        string $candidateUid,
        string $policySnapshotJson
    ): bool {
        return DB::execute(
            "UPDATE `chapters`
             SET `content`=?, `words`=?, `status`='completed',
                 `canonical_state`='canonical', `content_revision_hash`=?,
                 `canonical_candidate_uid`=?, `write_policy_snapshot_json`=?,
                 `canonicalized_at`=NOW(6), `claim_token`=''
             WHERE `id`=? AND `novel_id`=? AND `status`='writing'
               AND `claim_token`=? AND `canonical_state`=?
               AND `content_revision_hash` <=> ?",
            [
                $content,
                $words,
                $revisionHash,
                $candidateUid,
                $policySnapshotJson,
                $chapterId,
                $novelId,
                $claimToken,
                $expectedCanonicalState,
                $expectedRevisionHash,
            ]
        ) === 1;
    }

    public function restoreChapter(
        int $chapterId,
        int $novelId,
        string $claimToken,
        string $baseStatus,
        string $expectedCanonicalState,
        ?string $expectedRevisionHash
    ): bool {
        // Quarantine must never mutate content or any Canon metadata. For a
        // rejected rewrite this restores status=completed around the old Canon.
        return DB::execute(
            "UPDATE `chapters`
             SET `status`=?, `claim_token`=''
             WHERE `id`=? AND `novel_id`=? AND `status`='writing'
               AND `claim_token`=? AND `canonical_state`=?
               AND `content_revision_hash` <=> ?",
            [
                $baseStatus,
                $chapterId,
                $novelId,
                $claimToken,
                $expectedCanonicalState,
                $expectedRevisionHash,
            ]
        ) === 1;
    }

    public function markCandidateCommitted(
        string $candidateUid,
        string $contentHash,
        WritePolicySnapshot $policySnapshot
    ): bool {
        return ChapterCandidateRepository::markCommitted(
            $candidateUid,
            $contentHash,
            $policySnapshot
        );
    }
}

/**
 * The only mutation boundary that may turn a frozen Candidate into Canon.
 *
 * It refuses to borrow an outer transaction: otherwise a helper could commit
 * or roll back state owned by a caller. Both the allow and quarantine branches
 * therefore own exactly one transaction from locks through the final CAS.
 */
final class ChapterCanonTransition
{
    /** @var Closure(WritePolicySnapshot):void */
    private readonly Closure $emergencyCheckpoint;

    public function __construct(
        private readonly ChapterCanonTransitionStore $store = new DbChapterCanonTransitionStore(),
        ?callable $emergencyCheckpoint = null
    ) {
        $this->emergencyCheckpoint = $emergencyCheckpoint === null
            ? static function (WritePolicySnapshot $snapshot): void {
                WritePolicySnapshotFactory::assertEmergencyEpochCurrent($snapshot);
            }
            : Closure::fromCallable($emergencyCheckpoint);
    }

    /**
     * The optional callback runs inside this transaction after evaluation but
     * before chapter promotion. It receives a prospective successful result
     * and the still-unmodified locked chapter, and must not manage transactions.
     *
     * @param null|callable(array<string,mixed>):void $commitCallback
     * @return array{
     *   committed:bool,
     *   quarantined:bool,
     *   accepted_content:?string,
     *   revision_hash:?string,
     *   candidate_uid:string,
     *   decision:QualityGateDecision
     * }
     */
    public function applyDecision(
        int $userId,
        int $novelId,
        int $chapterId,
        string $candidateUid,
        string $claimToken,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision,
        ?callable $commitCallback = null,
        bool $approvedOverride = false
    ): array {
        self::assertPositiveId($userId, 'user_id');
        self::assertPositiveId($novelId, 'novel_id');
        self::assertPositiveId($chapterId, 'chapter_id');
        self::assertUid($candidateUid, 'candidate_uid');
        self::assertUid($claimToken, 'claim_token');

        // Production Canon transitions participate in the same novel-wide
        // projection fence as workers and manual mutations. Test stores remain
        // database-independent and model only the transactional state machine.
        $projectionFence = $this->store instanceof DbChapterCanonTransitionStore
            ? NovelProjectionFence::acquire($novelId, 30)
            : null;
        try {
            if ($this->store->inTransaction()) {
                throw new ChapterCanonTransitionException(
                    'Candidate to Canon transition refuses a nested transaction.'
                );
            }
            if (!$this->store->beginTransaction() || !$this->store->inTransaction()) {
                throw new ChapterCanonTransitionException(
                    'Unable to start the Candidate to Canon transaction.'
                );
            }

            try {
                $chapter = $this->store->lockChapter($chapterId);
            $this->assertTransactionActive('chapter lock');
            if ($chapter === null) {
                throw new ChapterCanonTransitionException('Chapter not found while locking Canon.');
            }
            $candidate = $this->store->lockCandidate($candidateUid);
            $this->assertTransactionActive('candidate lock');
            if ($candidate === null) {
                throw new ChapterCanonTransitionException('Candidate not found while locking Canon.');
            }

            // Serialize the whole mutation boundary with HarnessEmergencyStop
            // before an approved claim, Candidate lifecycle, projection, or
            // Canon row can be changed. A concurrent bump either wins first
            // and this transaction refuses, or waits until this commit ends.
            $this->store->lockEmergencyEpoch($policySnapshot);
            $this->assertTransactionActive('emergency epoch row lock');

            if ($approvedOverride) {
                if (!method_exists($this->store, 'claimApprovedChapter')) {
                    throw new ChapterCanonTransitionException(
                        'The Canon store does not support approved Candidate claims.'
                    );
                }
                $approvedBase = self::decodeBaseSnapshot(
                    $candidate['base_snapshot_json'] ?? null
                );
                if (!$this->store->claimApprovedChapter(
                    $chapterId,
                    $novelId,
                    $claimToken,
                    $approvedBase['status'],
                    $approvedBase['canonical_state'],
                    $approvedBase['content_revision_hash']
                )) {
                    throw new ChapterCanonTransitionException(
                        'Approved Candidate could not acquire its exact Chapter base.'
                    );
                }
                $chapter['status'] = 'writing';
                $chapter['claim_token'] = $claimToken;
            }

            $base = self::validateLockedState(
                $chapter,
                $candidate,
                $userId,
                $novelId,
                $chapterId,
                $candidateUid,
                $claimToken,
                $policySnapshot,
                $decision,
                $approvedOverride
            );

            // The emergency epoch is deliberately live and is checked after
            // both locks, immediately before the first mutation.
            ($this->emergencyCheckpoint)($policySnapshot);
            $this->assertTransactionActive('emergency checkpoint');

            if ($decision->shouldQuarantine() && !$approvedOverride) {
                if (!$this->store->markCandidateQuarantined(
                    $candidateUid,
                    $policySnapshot,
                    $decision,
                    self::quarantineReason($decision)
                )) {
                    throw new ChapterCanonTransitionException(
                        'Candidate quarantine CAS failed.'
                    );
                }
                $this->assertTransactionActive('Candidate quarantine');
                if (!$this->store->restoreChapter(
                    $chapterId,
                    $novelId,
                    $claimToken,
                    $base['status'],
                    $base['canonical_state'],
                    $base['content_revision_hash']
                )) {
                    throw new ChapterCanonTransitionException(
                        'Chapter quarantine restore CAS failed.'
                    );
                }
                $this->assertTransactionActive('chapter quarantine restore');

                $acceptedContent = $base['canonical_state'] === 'none'
                    ? null
                    : self::requireChapterContent($chapter['content'] ?? null);
                $result = [
                    'committed' => false,
                    'quarantined' => true,
                    'accepted_content' => $acceptedContent,
                    'revision_hash' => $base['content_revision_hash'],
                    'candidate_uid' => $candidateUid,
                    'decision' => $decision,
                ];
            } else {
                if (!$approvedOverride) {
                    if (!$this->store->markCandidateEvaluated(
                        $candidateUid,
                        $policySnapshot,
                        $decision
                    )) {
                        throw new ChapterCanonTransitionException(
                            'Candidate evaluation CAS failed.'
                        );
                    }
                    $this->assertTransactionActive('Candidate evaluation');
                }

                $content = self::requireCandidateContent($candidate['inline_content'] ?? null);
                if ($content === '') {
                    throw new ChapterCanonTransitionException(
                        'An empty Candidate may only be quarantined, never promoted.'
                    );
                }
                // validateLockedState() already authenticated the stored count;
                // derive the promoted value from the locked content itself so
                // Canon never trusts a denormalized Candidate counter.
                $words = countWords($content);
                $revisionHash = $decision->contentHash();
                // Build the prospective result before Canon is overwritten.
                // The callback deliberately runs after Candidate evaluation
                // but before promotion so it can backup the old chapter and
                // write transactional metadata/stats against that old state.
                $result = [
                    'committed' => true,
                    'quarantined' => false,
                    'accepted_content' => $content,
                    'revision_hash' => $revisionHash,
                    'candidate_uid' => $candidateUid,
                    'decision' => $decision,
                ];

                if ($commitCallback !== null) {
                    $commitCallback([
                        'result' => $result,
                        'chapter' => $chapter,
                        'candidate' => $candidate,
                        'base_snapshot' => $base,
                        'policy_snapshot' => $policySnapshot,
                    ]);
                    $this->assertTransactionActive('commit callback');
                }

                // The callback may perform backups and metadata/stat writes.
                // Re-read the live emergency epoch immediately before the
                // irreversible Canon CAS so a mid-callback kill switch rolls
                // the entire Candidate transaction back.
                ($this->emergencyCheckpoint)($policySnapshot);
                $this->assertTransactionActive('pre-promotion emergency checkpoint');

                if (!$this->store->promoteChapter(
                    $chapterId,
                    $novelId,
                    $claimToken,
                    $base['canonical_state'],
                    $base['content_revision_hash'],
                    $content,
                    $words,
                    $revisionHash,
                    $candidateUid,
                    $policySnapshot->toJson()
                )) {
                    throw new ChapterCanonTransitionException(
                        'Chapter Canon promotion CAS failed.'
                    );
                }
                $this->assertTransactionActive('Canon promotion');
                if (!$this->store->markCandidateCommitted(
                    $candidateUid,
                    $revisionHash,
                    $policySnapshot
                )) {
                    throw new ChapterCanonTransitionException(
                        'Candidate commit CAS failed.'
                    );
                }
                $this->assertTransactionActive('Candidate commit');
            }

            if (!$this->store->commit()) {
                throw new ChapterCanonTransitionException(
                    'Candidate to Canon transaction commit failed.'
                );
            }

            return $result;
            } catch (Throwable $exception) {
                if ($this->store->inTransaction()) {
                    try {
                        $this->store->rollBack();
                    } catch (Throwable) {
                        // Preserve the original failure; the DB connection owns
                        // recovery after an exceptional rollback failure.
                    }
                }
                throw $exception;
            }
        } finally {
            if ($projectionFence !== null) {
                NovelProjectionFence::release($projectionFence);
            }
        }
    }

    private function assertTransactionActive(string $stage): void
    {
        if (!$this->store->inTransaction()) {
            throw new ChapterCanonTransitionException(
                "Candidate to Canon transaction was lost during {$stage}."
            );
        }
    }

    /**
     * @param array<string,mixed> $chapter
     * @param array<string,mixed> $candidate
     * @return array{
     *   status:string,
     *   canonical_state:string,
     *   content_revision_hash:?string,
     *   content_hash:?string,
     *   claim_token:string
     * }
     */
    private static function validateLockedState(
        array $chapter,
        array $candidate,
        int $userId,
        int $novelId,
        int $chapterId,
        string $candidateUid,
        string $claimToken,
        WritePolicySnapshot $policySnapshot,
        QualityGateDecision $decision,
        bool $approvedOverride = false
    ): array {
        self::assertDatabaseIdentity($chapter, 'id', $chapterId, 'chapter');
        self::assertDatabaseIdentity($chapter, 'novel_id', $novelId, 'chapter');
        self::assertDatabaseIdentity($chapter, 'novel_user_id', $userId, 'chapter owner');
        if (($chapter['status'] ?? null) !== 'writing') {
            throw new ChapterCanonTransitionException('Chapter is no longer in writing state.');
        }
        self::assertMatchingString($chapter['claim_token'] ?? null, $claimToken, 'chapter claim token');

        self::assertDatabaseIdentity($candidate, 'user_id', $userId, 'candidate owner');
        self::assertDatabaseIdentity($candidate, 'novel_id', $novelId, 'candidate novel');
        self::assertDatabaseIdentity($candidate, 'chapter_id', $chapterId, 'candidate chapter');
        self::assertMatchingString(
            $candidate['candidate_uid'] ?? null,
            $candidateUid,
            'candidate UID'
        );
        if ($approvedOverride) {
            if (($candidate['lifecycle'] ?? null) !== 'approved'
                || ($candidate['gate_state'] ?? null) !== ChapterCandidateRepository::GATE_QUARANTINED
                || !is_string($candidate['validation_json'] ?? null)
                || ($candidate['validation_json'] ?? '') === ''
                || (int)($candidate['validation_artifact_id'] ?? 0) <= 0
                || (int)($candidate['run_id'] ?? 0) <= 0) {
                throw new ChapterCanonTransitionException(
                    'Candidate is not an authenticated approved override.'
                );
            }
        } elseif (($candidate['lifecycle'] ?? null) !== ChapterCandidateRepository::LIFECYCLE_DRAFT
            || ($candidate['gate_state'] ?? null) !== ChapterCandidateRepository::GATE_PENDING
            || ($candidate['validation_json'] ?? null) !== null) {
                throw new ChapterCanonTransitionException(
                    'Candidate is not an unevaluated draft.'
                );
        }

        $content = self::requireCandidateContent($candidate['inline_content'] ?? null);
        $contentHash = self::requireSha256($candidate['content_hash'] ?? null, 'candidate.content_hash');
        if (!hash_equals(hash('sha256', $content), $contentHash)
            || !hash_equals($decision->contentHash(), $contentHash)) {
            throw new ChapterCanonTransitionException(
                'Candidate content, hash, and quality decision do not match.'
            );
        }
        self::assertCandidateWordCount($candidate['words'] ?? null, $content);

        $frozenPolicy = self::decodeCandidatePolicy($candidate['policy_snapshot_json'] ?? null);
        $policyHash = self::requireSha256(
            $candidate['policy_snapshot_hash'] ?? null,
            'candidate.policy_snapshot_hash'
        );
        if (!hash_equals($frozenPolicy->checksum(), $policyHash)
            || !hash_equals($policySnapshot->checksum(), $policyHash)
            || !hash_equals($decision->policyMode(), $policySnapshot->canonPolicy())
            || !hash_equals($decision->policyVersion(), $policySnapshot->qualityRuleVersion())) {
            throw new ChapterCanonTransitionException(
                'Candidate policy snapshot and quality decision do not match.'
            );
        }

        $base = self::decodeBaseSnapshot($candidate['base_snapshot_json'] ?? null);
        self::assertMatchingString($base['claim_token'], $claimToken, 'base claim token');
        $candidateBaseRevision = self::requireOptionalSha256(
            $candidate['base_revision_hash'] ?? null,
            'candidate.base_revision_hash'
        );
        if (!self::sameNullableHash($candidateBaseRevision, $base['content_revision_hash'])) {
            throw new ChapterCanonTransitionException(
                'Candidate base revision does not match its base snapshot.'
            );
        }

        $chapterCanonicalState = $chapter['canonical_state'] ?? null;
        if (!is_string($chapterCanonicalState)
            || !hash_equals($base['canonical_state'], $chapterCanonicalState)) {
            throw new ChapterCanonTransitionException(
                'Current chapter Canon state differs from the Candidate base.'
            );
        }
        $chapterRevision = self::requireOptionalSha256(
            $chapter['content_revision_hash'] ?? null,
            'chapter.content_revision_hash'
        );
        if (!self::sameNullableHash($base['content_revision_hash'], $chapterRevision)) {
            throw new ChapterCanonTransitionException(
                'Current chapter Canon revision differs from the Candidate base.'
            );
        }
        self::assertBaseContentUnchanged($chapter['content'] ?? null, $base['content_hash']);

        $source = $candidate['source'] ?? null;
        $parentCandidateUid = $candidate['parent_candidate_uid'] ?? null;
        if ($source === ChapterCandidateRepository::SOURCE_INITIAL) {
            if ($parentCandidateUid !== null) {
                throw new ChapterCanonTransitionException(
                    'An initial Candidate cannot name a parent.'
                );
            }
            if ($base['status'] === 'completed' || $base['canonical_state'] !== 'none') {
                throw new ChapterCanonTransitionException(
                    'An initial Candidate cannot replace an existing completed Canon.'
                );
            }
        } elseif ($source === ChapterCandidateRepository::SOURCE_REWRITE) {
            if ($base['status'] !== 'completed' || $base['canonical_state'] === 'none') {
                throw new ChapterCanonTransitionException(
                    'A rewrite Candidate requires an existing completed Canon base.'
                );
            }
            self::assertUidValue($parentCandidateUid, 'candidate.parent_candidate_uid');
            $currentCandidateUid = $chapter['canonical_candidate_uid'] ?? null;
            if ($currentCandidateUid !== null && $currentCandidateUid !== '') {
                self::assertMatchingString(
                    $currentCandidateUid,
                    $parentCandidateUid,
                    'rewrite parent Candidate UID'
                );
            }
        } else {
            throw new ChapterCanonTransitionException('Candidate source is invalid.');
        }

        return $base;
    }

    /**
     * @return array{
     *   status:string,
     *   canonical_state:string,
     *   content_revision_hash:?string,
     *   content_hash:?string,
     *   claim_token:string
     * }
     */
    private static function decodeBaseSnapshot(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            throw new ChapterCanonTransitionException('Candidate base snapshot JSON is missing.');
        }
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ChapterCanonTransitionException(
                'Candidate base snapshot JSON is invalid.',
                0,
                $exception
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ChapterCanonTransitionException(
                'Candidate base snapshot must be a JSON object.'
            );
        }

        $expected = [
            'status',
            'canonical_state',
            'content_revision_hash',
            'content_hash',
            'claim_token',
        ];
        if (array_diff($expected, array_keys($decoded)) !== []
            || array_diff(array_keys($decoded), $expected) !== []) {
            throw new ChapterCanonTransitionException(
                'Candidate base snapshot fields do not match the frozen schema.'
            );
        }

        $status = $decoded['status'];
        if (!is_string($status) || !in_array($status, [
            'pending',
            'outlined',
            'completed',
            'skipped',
            'failed',
        ], true)) {
            throw new ChapterCanonTransitionException('Candidate base status is invalid.');
        }
        $canonicalState = $decoded['canonical_state'];
        if (!is_string($canonicalState)
            || !in_array($canonicalState, ['none', 'legacy', 'canonical'], true)) {
            throw new ChapterCanonTransitionException('Candidate base Canon state is invalid.');
        }
        $revisionHash = self::requireOptionalSha256(
            $decoded['content_revision_hash'],
            'base.content_revision_hash'
        );
        $contentHash = self::requireOptionalSha256(
            $decoded['content_hash'],
            'base.content_hash'
        );
        self::assertUidValue($decoded['claim_token'], 'base.claim_token');

        if ($canonicalState === 'none') {
            if ($revisionHash !== null) {
                throw new ChapterCanonTransitionException(
                    'A non-Canon base cannot carry a Canon revision.'
                );
            }
        } elseif ($revisionHash === null
            || $contentHash === null
            || !hash_equals($revisionHash, $contentHash)) {
            throw new ChapterCanonTransitionException(
                'An existing Canon base requires one matching content revision.'
            );
        }

        return [
            'status' => $status,
            'canonical_state' => $canonicalState,
            'content_revision_hash' => $revisionHash,
            'content_hash' => $contentHash,
            'claim_token' => $decoded['claim_token'],
        ];
    }

    private static function decodeCandidatePolicy(mixed $json): WritePolicySnapshot
    {
        if (!is_string($json) || $json === '') {
            throw new ChapterCanonTransitionException('Candidate policy snapshot JSON is missing.');
        }
        try {
            return WritePolicySnapshot::fromJson($json);
        } catch (Throwable $exception) {
            throw new ChapterCanonTransitionException(
                'Candidate policy snapshot JSON is invalid.',
                0,
                $exception
            );
        }
    }

    private static function assertBaseContentUnchanged(mixed $content, ?string $expectedHash): void
    {
        if ($expectedHash === null) {
            if ($content !== null && $content !== '') {
                throw new ChapterCanonTransitionException(
                    'Current chapter content differs from the empty Candidate base.'
                );
            }
            return;
        }
        if (!is_string($content) || !hash_equals(hash('sha256', $content), $expectedHash)) {
            throw new ChapterCanonTransitionException(
                'Current chapter content differs from the Candidate base snapshot.'
            );
        }
    }

    private static function quarantineReason(QualityGateDecision $decision): string
    {
        $parts = [];
        foreach ($decision->hardIssues() as $issue) {
            $parts[] = $issue['rule_id'] . ': ' . $issue['message'];
        }
        $reason = implode('; ', $parts);
        if ($reason === '') {
            throw new ChapterCanonTransitionException(
                'A quarantine decision must contain a hard issue.'
            );
        }

        return function_exists('mb_substr')
            ? mb_substr($reason, 0, 4000, 'UTF-8')
            : substr($reason, 0, 4000);
    }

    private static function assertDatabaseIdentity(
        array $row,
        string $field,
        int $expected,
        string $label
    ): void {
        $value = $row[$field] ?? null;
        if ((is_int($value) || (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1))
            && (int)$value === $expected) {
            return;
        }
        throw new ChapterCanonTransitionException("{$label} identity mismatch.");
    }

    private static function assertMatchingString(mixed $actual, string $expected, string $label): void
    {
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new ChapterCanonTransitionException("{$label} mismatch.");
        }
    }

    private static function assertPositiveId(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("{$field} must be positive.");
        }
    }

    private static function assertUid(string $value, string $field): void
    {
        self::assertUidValue($value, $field);
    }

    private static function assertUidValue(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new ChapterCanonTransitionException(
                "{$field} must be a lowercase 32-character hex UID."
            );
        }
    }

    private static function requireSha256(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ChapterCanonTransitionException(
                "{$field} must be a lowercase SHA-256 hash."
            );
        }
        return $value;
    }

    private static function requireOptionalSha256(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::requireSha256($value, $field);
    }

    private static function sameNullableHash(?string $left, ?string $right): bool
    {
        return $left === null || $right === null
            ? $left === $right
            : hash_equals($left, $right);
    }

    private static function requireCandidateContent(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ChapterCanonTransitionException(
                'Candidate inline content must be a string.'
            );
        }
        return $value;
    }

    private static function assertCandidateWordCount(mixed $storedValue, string $content): void
    {
        $storedWords = self::requireNonNegativeDatabaseInteger(
            $storedValue,
            'candidate.words'
        );
        $computedWords = countWords($content);
        if ($storedWords !== $computedWords) {
            throw new ChapterCanonTransitionException(sprintf(
                'Candidate word count mismatch: stored=%d computed=%d.',
                $storedWords,
                $computedWords
            ));
        }
    }

    private static function requireChapterContent(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ChapterCanonTransitionException(
                'Existing Canon content must be a string.'
            );
        }
        return $value;
    }

    private static function requireNonNegativeDatabaseInteger(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9]\d*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if (is_int($parsed)) {
                return $parsed;
            }
        }
        throw new ChapterCanonTransitionException("{$field} must be a non-negative integer.");
    }
}
