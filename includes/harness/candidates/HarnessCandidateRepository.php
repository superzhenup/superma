<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/../persistence/GenerationArtifact.php';
require_once __DIR__ . '/HarnessCandidate.php';

/**
 * M63 Candidate persistence boundary.
 *
 * Every mutating method owns its transaction, locks the complete ownership
 * chain, authenticates Artifact provenance, and performs an exact lifecycle
 * compare-and-swap. It deliberately never mutates Chapter Canon.
 */
final class HarnessCandidateRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function createIdempotent(HarnessCandidateDraft $draft): HarnessCandidate
    {
        return $this->db->transactional(function () use ($draft): HarnessCandidate {
            $ownership = $this->fetchCreationOwnership($draft);
            $sourceRunUid = $this->assertCreationOwnership($draft, $ownership);

            $artifact = $this->loadArtifactForUpdate($draft->provenance->draftArtifactId);
            $draft->provenance->assertDraftArtifact($artifact, $draft->inlineContent);

            if ($draft->source === HarnessCandidateSource::REWRITE) {
                $this->assertRewriteParent($draft, $ownership);
            }

            $this->db->execute(
                "INSERT INTO chapter_candidates
                    (candidate_uid, user_id, novel_id, chapter_id, run_id, source,
                     source_run_uid, source_task_id, parent_candidate_uid,
                     base_revision_hash, base_snapshot_json, draft_artifact_id,
                     inline_content, content_hash, words, lifecycle, gate_state,
                     validation_artifact_id, validation_json, quarantine_reason,
                     policy_snapshot_json, policy_snapshot_hash,
                     evaluated_at, quarantined_at, approved_at, rejected_at,
                     stale_at, committed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         'draft', 'pending', NULL, NULL, NULL, ?, ?,
                         NULL, NULL, NULL, NULL, NULL, NULL,
                         UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->candidateUid,
                    $draft->userId,
                    $draft->novelId,
                    $draft->chapterId,
                    $draft->provenance->runId,
                    $draft->source->value,
                    $sourceRunUid,
                    $draft->sourceTaskId,
                    $draft->parentCandidateUid,
                    $draft->baseRevisionHash,
                    $draft->baseSnapshot->json,
                    $draft->provenance->draftArtifactId,
                    $draft->inlineContent,
                    $draft->contentHash,
                    $draft->words,
                    $draft->policySnapshotJson,
                    $draft->policySnapshotHash,
                ]
            );

            $row = $this->db->fetchOne(
                'SELECT * FROM chapter_candidates WHERE candidate_uid=? FOR UPDATE',
                [$draft->candidateUid]
            );
            if ($row === null) {
                // The second unique identity protects one Run from persisting
                // the same bytes under competing Candidate UIDs.
                $row = $this->db->fetchOne(
                    'SELECT * FROM chapter_candidates WHERE run_id=? AND content_hash=? FOR UPDATE',
                    [$draft->provenance->runId, $draft->contentHash]
                );
            }
            if ($row === null) {
                throw new HarnessIdempotencyConflictException(
                    'Candidate insert collided without resolving a managed identity.'
                );
            }

            $candidate = $draft->assertIdempotentRow($row, $sourceRunUid);
            $candidate->assertLiveArtifactCoordinates();
            return $candidate;
        });
    }

    public function findByUid(
        int $userId,
        int $novelId,
        string $candidateUid
    ): ?HarnessCandidate {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $candidateUid = HarnessPersistenceValue::uid($candidateUid, 'candidate_uid');
        $row = $this->db->fetchOne(
            "SELECT cc.*
             FROM chapter_candidates cc
             INNER JOIN novels n ON n.id=cc.novel_id AND n.user_id=?
             INNER JOIN chapters ch ON ch.id=cc.chapter_id AND ch.novel_id=?
             WHERE cc.user_id=? AND cc.novel_id=? AND cc.candidate_uid=?",
            [$userId, $novelId, $userId, $novelId, $candidateUid]
        );
        return $row === null ? null : HarnessCandidate::fromRow($row);
    }

    public function findById(int $userId, int $novelId, int $candidateId): ?HarnessCandidate
    {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $candidateId = HarnessPersistenceValue::positiveInt($candidateId, 'candidate_id');
        $row = $this->db->fetchOne(
            "SELECT cc.*
             FROM chapter_candidates cc
             INNER JOIN novels n ON n.id=cc.novel_id AND n.user_id=?
             INNER JOIN chapters ch ON ch.id=cc.chapter_id AND ch.novel_id=?
             WHERE cc.user_id=? AND cc.novel_id=? AND cc.id=?",
            [$userId, $novelId, $userId, $novelId, $candidateId]
        );
        return $row === null ? null : HarnessCandidate::fromRow($row);
    }

    /** @return list<HarnessCandidate> */
    public function listForChapter(
        int $userId,
        int $novelId,
        int $chapterId,
        int $limit = 100
    ): array {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $chapterId = HarnessPersistenceValue::positiveInt($chapterId, 'chapter_id');
        $limit = HarnessPersistenceValue::boundedInt($limit, 'limit', 1, 500);
        $rows = $this->db->fetchAll(
            "SELECT cc.*
             FROM chapter_candidates cc
             INNER JOIN novels n ON n.id=cc.novel_id AND n.user_id=?
             INNER JOIN chapters ch ON ch.id=cc.chapter_id AND ch.novel_id=?
             WHERE cc.user_id=? AND cc.novel_id=? AND cc.chapter_id=?
             ORDER BY cc.created_at DESC, cc.id DESC
             LIMIT {$limit}",
            [$userId, $novelId, $userId, $novelId, $chapterId]
        );
        return array_map(
            static fn(array $row): HarnessCandidate => HarnessCandidate::fromRow($row),
            $rows
        );
    }

    /**
     * CAS draft/pending into approved/passed or quarantined/quarantined.
     * Passing is safe-policy approval; a quarantine remains blocked until an
     * explicit approval transition overrides it.
     */
    public function recordValidation(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash,
        HarnessCandidateValidationEvidence $evidence
    ): HarnessCandidate {
        [$userId, $novelId, $candidateUid, $expectedBaseRevisionHash] =
            $this->normalizeCoordinates(
                $userId,
                $novelId,
                $candidateUid,
                $expectedBaseRevisionHash
            );

        return $this->db->transactional(function () use (
            $userId,
            $novelId,
            $candidateUid,
            $expectedBaseRevisionHash,
            $evidence
        ): HarnessCandidate {
            $row = $this->lockOwnedCandidate($userId, $novelId, $candidateUid);
            $candidate = HarnessCandidate::fromRow($row);
            $this->assertOperationalOwnership($candidate, $row);
            $this->assertFrozenBase($candidate, $expectedBaseRevisionHash);

            $target = $evidence->gateState === HarnessCandidateGateState::PASSED
                ? HarnessCandidateLifecycle::APPROVED
                : HarnessCandidateLifecycle::QUARANTINED;
            if ($candidate->lifecycle === $target) {
                $this->authenticateStoredArtifacts($candidate, $evidence->provenance, true);
                $this->assertValidationReplay($candidate, $evidence, $target);
                return $candidate;
            }
            if ($candidate->lifecycle !== HarnessCandidateLifecycle::DRAFT
                || $candidate->gateState !== HarnessCandidateGateState::PENDING
                || $candidate->validationArtifactId !== null
                || $candidate->validationJson !== null) {
                throw new HarnessStateTransitionException(
                    'Only an unevaluated draft Candidate may record validation.'
                );
            }

            $this->assertLiveBase($candidate, $row, true);
            $this->authenticateStoredArtifacts($candidate, $evidence->provenance, false);
            $validationArtifact = $this->loadArtifactForUpdate(
                $evidence->provenance->validationArtifactId
                    ?? throw new InvalidArgumentException('Validation Artifact id is required.')
            );
            $evidence->provenance->assertValidationArtifact(
                $validationArtifact,
                HarnessPersistenceValue::decodeJsonArray(
                    $evidence->validationJson,
                    'validation_json'
                )
            );

            $evaluatedAt = HarnessPersistenceValue::mysqlDate($evidence->evaluatedAt);
            $changed = $this->db->execute(
                "UPDATE chapter_candidates
                 SET lifecycle=?, gate_state=?, validation_artifact_id=?,
                     validation_json=?, quarantine_reason=?, evaluated_at=?,
                     quarantined_at=?, approved_at=?, updated_at=?
                 WHERE id=? AND lifecycle='draft' AND gate_state='pending'
                   AND validation_artifact_id IS NULL AND validation_json IS NULL
                   AND base_revision_hash <=> ? AND policy_snapshot_hash=?
                   AND draft_artifact_id=?",
                [
                    $target->value,
                    $evidence->gateState->value,
                    $evidence->provenance->validationArtifactId,
                    $evidence->validationJson,
                    $evidence->quarantineReason,
                    $evaluatedAt,
                    $target === HarnessCandidateLifecycle::QUARANTINED ? $evaluatedAt : null,
                    $target === HarnessCandidateLifecycle::APPROVED ? $evaluatedAt : null,
                    $evaluatedAt,
                    $candidate->id,
                    $expectedBaseRevisionHash,
                    $candidate->policySnapshotHash,
                    $candidate->draftArtifactId,
                ]
            );

            $updated = $this->reloadAfterCas($candidate->id, $changed, 'validation');
            $this->assertValidationReplay($updated, $evidence, $target);
            $this->authenticateStoredArtifacts($updated, $evidence->provenance, true);
            return $updated;
        });
    }

    /** CAS quarantined -> approved while preserving its blocking gate evidence. */
    public function approve(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash,
        DateTimeImmutable $approvedAt
    ): HarnessCandidate {
        [$userId, $novelId, $candidateUid, $expectedBaseRevisionHash] =
            $this->normalizeCoordinates(
                $userId,
                $novelId,
                $candidateUid,
                $expectedBaseRevisionHash
            );
        $timestamp = HarnessPersistenceValue::mysqlDate($approvedAt);

        return $this->db->transactional(function () use (
            $userId,
            $novelId,
            $candidateUid,
            $expectedBaseRevisionHash,
            $timestamp
        ): HarnessCandidate {
            $row = $this->lockOwnedCandidate($userId, $novelId, $candidateUid);
            $candidate = HarnessCandidate::fromRow($row);
            $this->assertOperationalOwnership($candidate, $row);
            $this->assertFrozenBase($candidate, $expectedBaseRevisionHash);
            $this->authenticateStoredArtifacts($candidate, null, true);

            if ($candidate->lifecycle === HarnessCandidateLifecycle::APPROVED) {
                if ($candidate->approvedAt !== $timestamp) {
                    throw new HarnessIdempotencyConflictException(
                        'Candidate was approved by a different transition.'
                    );
                }
                return $candidate;
            }
            if ($candidate->lifecycle !== HarnessCandidateLifecycle::QUARANTINED
                || $candidate->gateState !== HarnessCandidateGateState::QUARANTINED) {
                throw new HarnessStateTransitionException(
                    'Only a quarantined Candidate may receive an approval override.'
                );
            }
            $this->assertLiveBase($candidate, $row, true);

            $changed = $this->db->execute(
                "UPDATE chapter_candidates
                 SET lifecycle='approved', approved_at=?, updated_at=?
                 WHERE id=? AND lifecycle='quarantined' AND gate_state='quarantined'
                   AND validation_artifact_id=? AND validation_json IS NOT NULL
                   AND base_revision_hash <=> ?",
                [
                    $timestamp,
                    $timestamp,
                    $candidate->id,
                    $candidate->validationArtifactId,
                    $expectedBaseRevisionHash,
                ]
            );
            $updated = $this->reloadAfterCas($candidate->id, $changed, 'approval');
            if ($updated->lifecycle !== HarnessCandidateLifecycle::APPROVED
                || $updated->approvedAt !== $timestamp) {
                throw new HarnessStateTransitionException(
                    'Candidate approval CAS resolved to a different state.'
                );
            }
            return $updated;
        });
    }

    /** CAS an exact live lifecycle into rejected. */
    public function reject(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash,
        HarnessCandidateLifecycle $expectedLifecycle,
        DateTimeImmutable $rejectedAt
    ): HarnessCandidate {
        if (!in_array($expectedLifecycle, [
            HarnessCandidateLifecycle::DRAFT,
            HarnessCandidateLifecycle::EVALUATED,
            HarnessCandidateLifecycle::QUARANTINED,
            HarnessCandidateLifecycle::APPROVED,
        ], true)) {
            throw new InvalidArgumentException('Only a live Candidate may be rejected.');
        }
        return $this->terminalTransition(
            $userId,
            $novelId,
            $candidateUid,
            $expectedBaseRevisionHash,
            $expectedLifecycle,
            HarnessCandidateLifecycle::REJECTED,
            HarnessPersistenceValue::mysqlDate($rejectedAt),
            false,
            false
        );
    }

    /**
     * CAS an exact live lifecycle into stale, but only after the frozen Chapter
     * base has actually diverged. Expiry-derived staleness belongs to Approval.
     */
    public function markStale(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash,
        HarnessCandidateLifecycle $expectedLifecycle,
        DateTimeImmutable $staleAt
    ): HarnessCandidate {
        if (!in_array($expectedLifecycle, [
            HarnessCandidateLifecycle::DRAFT,
            HarnessCandidateLifecycle::EVALUATED,
            HarnessCandidateLifecycle::QUARANTINED,
            HarnessCandidateLifecycle::APPROVED,
        ], true)) {
            throw new InvalidArgumentException('Only a live Candidate may become stale.');
        }
        return $this->terminalTransition(
            $userId,
            $novelId,
            $candidateUid,
            $expectedBaseRevisionHash,
            $expectedLifecycle,
            HarnessCandidateLifecycle::STALE,
            HarnessPersistenceValue::mysqlDate($staleAt),
            false,
            true
        );
    }

    /** CAS an exact live lifecycle into the M60-M62 compatibility terminal. */
    public function supersede(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash,
        HarnessCandidateLifecycle $expectedLifecycle,
        DateTimeImmutable $supersededAt
    ): HarnessCandidate {
        if (!in_array($expectedLifecycle, [
            HarnessCandidateLifecycle::DRAFT,
            HarnessCandidateLifecycle::EVALUATED,
            HarnessCandidateLifecycle::QUARANTINED,
            HarnessCandidateLifecycle::APPROVED,
        ], true)) {
            throw new InvalidArgumentException('Only a live Candidate may be superseded.');
        }
        return $this->terminalTransition(
            $userId,
            $novelId,
            $candidateUid,
            $expectedBaseRevisionHash,
            $expectedLifecycle,
            HarnessCandidateLifecycle::SUPERSEDED,
            HarnessPersistenceValue::mysqlDate($supersededAt),
            false,
            false
        );
    }

    /** CAS approved -> committed. This never writes Chapter Canon. */
    public function markCommitted(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash,
        DateTimeImmutable $committedAt
    ): HarnessCandidate {
        [$userId, $novelId, $candidateUid, $expectedBaseRevisionHash] =
            $this->normalizeCoordinates(
                $userId,
                $novelId,
                $candidateUid,
                $expectedBaseRevisionHash
            );
        $timestamp = HarnessPersistenceValue::mysqlDate($committedAt);

        return $this->db->transactional(function () use (
            $userId,
            $novelId,
            $candidateUid,
            $expectedBaseRevisionHash,
            $timestamp
        ): HarnessCandidate {
            $row = $this->lockOwnedCandidate($userId, $novelId, $candidateUid);
            $candidate = HarnessCandidate::fromRow($row);
            $this->assertOperationalOwnership($candidate, $row);
            $this->assertFrozenBase($candidate, $expectedBaseRevisionHash);
            $this->authenticateStoredArtifacts($candidate, null, true);

            if ($candidate->lifecycle === HarnessCandidateLifecycle::COMMITTED) {
                if ($candidate->committedAt !== $timestamp) {
                    throw new HarnessIdempotencyConflictException(
                        'Candidate was committed by a different transition.'
                    );
                }
                return $candidate;
            }
            if ($candidate->lifecycle !== HarnessCandidateLifecycle::APPROVED
                || !in_array($candidate->gateState, [
                    HarnessCandidateGateState::PASSED,
                    HarnessCandidateGateState::QUARANTINED,
                ], true)) {
                throw new HarnessStateTransitionException(
                    'Only an approved, validated Candidate may be committed.'
                );
            }
            $this->assertLiveBase($candidate, $row, true);

            $changed = $this->db->execute(
                "UPDATE chapter_candidates
                 SET lifecycle='committed', committed_at=?, updated_at=?
                 WHERE id=? AND lifecycle='approved'
                   AND gate_state IN ('passed','quarantined')
                   AND validation_artifact_id=? AND validation_json IS NOT NULL
                   AND base_revision_hash <=> ? AND content_hash=?
                   AND policy_snapshot_hash=?",
                [
                    $timestamp,
                    $timestamp,
                    $candidate->id,
                    $candidate->validationArtifactId,
                    $expectedBaseRevisionHash,
                    $candidate->contentHash,
                    $candidate->policySnapshotHash,
                ]
            );
            $updated = $this->reloadAfterCas($candidate->id, $changed, 'commit');
            if ($updated->lifecycle !== HarnessCandidateLifecycle::COMMITTED
                || $updated->committedAt !== $timestamp) {
                throw new HarnessStateTransitionException(
                    'Candidate commit CAS resolved to a different state.'
                );
            }
            return $updated;
        });
    }

    /** @return array<string,mixed> */
    private function fetchCreationOwnership(HarnessCandidateDraft $draft): array
    {
        $row = $this->db->fetchOne(
            "SELECT r.id AS owner_run_id, r.run_uid AS owner_run_uid,
                    r.user_id AS owner_run_user_id, r.novel_id AS owner_run_novel_id,
                    r.chapter_id AS owner_run_chapter_id,
                    n.id AS owner_novel_id, n.user_id AS owner_novel_user_id,
                    ch.id AS owner_chapter_id, ch.novel_id AS owner_chapter_novel_id,
                    ch.status AS owner_chapter_status,
                    ch.canonical_state AS owner_chapter_canonical_state,
                    ch.content_revision_hash AS owner_chapter_revision_hash,
                    ch.content AS owner_chapter_content,
                    ch.claim_token AS owner_chapter_claim_token,
                    ch.canonical_candidate_uid AS owner_canonical_candidate_uid
             FROM generation_runs r
             INNER JOIN novels n ON n.id=r.novel_id
             INNER JOIN chapters ch ON ch.id=? AND ch.novel_id=n.id
             WHERE r.id=? FOR UPDATE",
            [$draft->chapterId, $draft->provenance->runId]
        );
        if ($row === null) {
            throw new HarnessPersistenceException(
                'Candidate Run, Novel, or Chapter ownership does not exist.'
            );
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function assertCreationOwnership(
        HarnessCandidateDraft $draft,
        array $row
    ): string {
        if ($this->positive($row, 'owner_run_id') !== $draft->provenance->runId
            || $this->positive($row, 'owner_run_user_id') !== $draft->userId
            || $this->positive($row, 'owner_run_novel_id') !== $draft->novelId
            || $this->positive($row, 'owner_run_chapter_id') !== $draft->chapterId
            || $this->positive($row, 'owner_novel_id') !== $draft->novelId
            || $this->positive($row, 'owner_novel_user_id') !== $draft->userId
            || $this->positive($row, 'owner_chapter_id') !== $draft->chapterId
            || $this->positive($row, 'owner_chapter_novel_id') !== $draft->novelId) {
            throw new HarnessPersistenceException(
                'Candidate User/Novel/Chapter/Run ownership does not match.'
            );
        }
        $sourceRunUid = HarnessPersistenceValue::uid(
            $row['owner_run_uid'] ?? null,
            'run.run_uid'
        );
        if (($row['owner_chapter_status'] ?? null) !== 'writing') {
            throw new HarnessStateTransitionException(
                'Candidate may only be created while its Chapter is claimed for writing.'
            );
        }
        $this->assertSnapshotMatchesChapter($draft->baseSnapshot, $row);
        return $sourceRunUid;
    }

    /** @param array<string,mixed> $ownership */
    private function assertRewriteParent(
        HarnessCandidateDraft $draft,
        array $ownership
    ): void {
        $parentUid = $draft->parentCandidateUid
            ?? throw new InvalidArgumentException('Rewrite parent UID is required.');
        $row = $this->db->fetchOne(
            'SELECT * FROM chapter_candidates WHERE candidate_uid=? FOR UPDATE',
            [$parentUid]
        );
        if ($row === null
            || $this->positive($row, 'user_id') !== $draft->userId
            || $this->positive($row, 'novel_id') !== $draft->novelId
            || $this->positive($row, 'chapter_id') !== $draft->chapterId
            || ($row['lifecycle'] ?? null) !== HarnessCandidateLifecycle::COMMITTED->value
            || !is_string($row['content_hash'] ?? null)
            || $draft->baseRevisionHash === null
            || !hash_equals($draft->baseRevisionHash, (string)$row['content_hash'])) {
            throw new HarnessPersistenceException(
                'Rewrite parent must be the committed Candidate for the exact frozen Canon base.'
            );
        }
        $canonicalUid = $ownership['owner_canonical_candidate_uid'] ?? null;
        if ($canonicalUid !== null
            && $canonicalUid !== ''
            && (!is_string($canonicalUid) || !hash_equals($parentUid, $canonicalUid))) {
            throw new HarnessStateTransitionException(
                'Rewrite parent is no longer the Chapter canonical Candidate.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function lockOwnedCandidate(
        int $userId,
        int $novelId,
        string $candidateUid
    ): array {
        $row = $this->db->fetchOne(
            "SELECT cc.*,
                    n.user_id AS owner_novel_user_id,
                    ch.novel_id AS owner_chapter_novel_id,
                    ch.status AS owner_chapter_status,
                    ch.canonical_state AS owner_chapter_canonical_state,
                    ch.content_revision_hash AS owner_chapter_revision_hash,
                    ch.content AS owner_chapter_content,
                    ch.claim_token AS owner_chapter_claim_token,
                    ch.canonical_candidate_uid AS owner_canonical_candidate_uid,
                    r.run_uid AS owner_run_uid,
                    r.user_id AS owner_run_user_id,
                    r.novel_id AS owner_run_novel_id,
                    r.chapter_id AS owner_run_chapter_id
             FROM chapter_candidates cc
             INNER JOIN novels n ON n.id=cc.novel_id
             INNER JOIN chapters ch ON ch.id=cc.chapter_id
             LEFT JOIN generation_runs r ON r.id=cc.run_id
             WHERE cc.user_id=? AND cc.novel_id=? AND cc.candidate_uid=?
               AND n.user_id=? AND ch.novel_id=? FOR UPDATE",
            [$userId, $novelId, $candidateUid, $userId, $novelId]
        );
        if ($row === null) {
            throw new HarnessPersistenceException(
                'Candidate does not exist within the requested ownership scope.'
            );
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function assertOperationalOwnership(HarnessCandidate $candidate, array $row): void
    {
        $candidate->assertLiveArtifactCoordinates();
        $runId = $candidate->runId
            ?? throw new HarnessPersistenceException('Candidate Run provenance was deleted.');
        if ($this->positive($row, 'owner_novel_user_id') !== $candidate->userId
            || $this->positive($row, 'owner_chapter_novel_id') !== $candidate->novelId
            || $this->positive($row, 'owner_run_user_id') !== $candidate->userId
            || $this->positive($row, 'owner_run_novel_id') !== $candidate->novelId
            || $this->positive($row, 'owner_run_chapter_id') !== $candidate->chapterId
            || HarnessPersistenceValue::uid($row['owner_run_uid'] ?? null, 'run.run_uid')
                !== $candidate->sourceRunUid
            || $runId < 1) {
            throw new HarnessPersistenceException(
                'Candidate ownership chain or Run provenance no longer authenticates.'
            );
        }
    }

    private function authenticateStoredArtifacts(
        HarnessCandidate $candidate,
        ?CandidateArtifactProvenance $expected,
        bool $requireValidation
    ): CandidateArtifactProvenance {
        $candidate->assertLiveArtifactCoordinates();
        $draftId = $candidate->draftArtifactId
            ?? throw new HarnessPersistenceException('Candidate Draft Artifact was deleted.');
        $draftArtifact = $this->loadArtifactForUpdate($draftId);
        $derived = CandidateArtifactProvenance::forDraft($draftArtifact);
        if ($derived->runId !== $candidate->runId
            || !hash_equals($derived->draftContentHash, $candidate->contentHash)) {
            throw new HarnessPersistenceException(
                'Candidate Draft Artifact is not owned by its Run or content.'
            );
        }
        $derived->assertDraftArtifact($draftArtifact, $candidate->inlineContent);
        if ($expected !== null) {
            $expected->assertDraftArtifact($draftArtifact, $candidate->inlineContent);
        }

        if ($candidate->validationArtifactId !== null) {
            if ($candidate->validationJson === null) {
                throw new HarnessPersistenceException(
                    'Candidate names a Validation Artifact without validation payload.'
                );
            }
            $validationArtifact = $this->loadArtifactForUpdate(
                $candidate->validationArtifactId
            );
            $derived = $derived->withValidation($validationArtifact);
            $payload = HarnessPersistenceValue::decodeJsonArray(
                $candidate->validationJson,
                'validation_json'
            );
            $derived->assertValidationArtifact($validationArtifact, $payload);
            if ($expected !== null) {
                $expected->assertValidationArtifact($validationArtifact, $payload);
            }
        } elseif ($requireValidation) {
            throw new HarnessPersistenceException(
                'Candidate Validation Artifact provenance is required for this transition.'
            );
        } elseif ($expected !== null && $expected->hasValidation()) {
            // recordValidation authenticates the new Artifact separately.
            if ($expected->runId !== $derived->runId
                || $expected->draftArtifactId !== $derived->draftArtifactId) {
                throw new HarnessPersistenceException(
                    'Validation evidence belongs to a different Candidate provenance chain.'
                );
            }
        }
        return $derived;
    }

    private function loadArtifactForUpdate(int $artifactId): GenerationArtifact
    {
        $artifactId = HarnessPersistenceValue::positiveInt($artifactId, 'artifact_id');
        $row = $this->db->fetchOne(
            'SELECT * FROM generation_artifacts WHERE id=? FOR UPDATE',
            [$artifactId]
        );
        if ($row === null) {
            throw new HarnessPersistenceException('Candidate Artifact does not exist.');
        }
        $artifact = GenerationArtifact::fromRow($row);
        if ($artifact->id !== $artifactId) {
            throw new HarnessPersistenceException('Candidate Artifact identity mismatch.');
        }
        return $artifact;
    }

    /** @param array<string,mixed> $row */
    private function assertLiveBase(
        HarnessCandidate $candidate,
        array $row,
        bool $requireWriting
    ): void {
        if ($requireWriting && ($row['owner_chapter_status'] ?? null) !== 'writing') {
            throw new HarnessStateTransitionException(
                'Candidate Chapter is no longer claimed for writing.'
            );
        }
        $snapshot = $candidate->baseSnapshot
            ?? throw new HarnessPersistenceException('Candidate base snapshot is missing.');
        $this->assertSnapshotMatchesChapter($snapshot, $row);

        if ($candidate->source === HarnessCandidateSource::REWRITE) {
            $canonicalUid = $row['owner_canonical_candidate_uid'] ?? null;
            if ($canonicalUid !== null
                && $canonicalUid !== ''
                && (!is_string($canonicalUid)
                    || $candidate->parentCandidateUid === null
                    || !hash_equals($candidate->parentCandidateUid, $canonicalUid))) {
                throw new HarnessStateTransitionException(
                    'Candidate rewrite parent is no longer canonical.'
                );
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function assertSnapshotMatchesChapter(
        HarnessCandidateBaseSnapshot $snapshot,
        array $row
    ): void {
        $chapterRevision = HarnessPersistenceValue::optionalHash(
            $row['owner_chapter_revision_hash'] ?? null,
            'chapter.content_revision_hash'
        );
        if (!$this->sameNullable($snapshot->contentRevisionHash, $chapterRevision)
            || ($row['owner_chapter_canonical_state'] ?? null) !== $snapshot->canonicalState
            || !is_string($row['owner_chapter_claim_token'] ?? null)
            || !hash_equals(
                $snapshot->claimToken,
                (string)$row['owner_chapter_claim_token']
            )) {
            throw new HarnessStateTransitionException(
                'Chapter identity or Canon base differs from the Candidate snapshot.'
            );
        }

        $content = $row['owner_chapter_content'] ?? null;
        if ($snapshot->contentHash === null) {
            if ($content !== null && $content !== '') {
                throw new HarnessStateTransitionException(
                    'Chapter content differs from the empty Candidate base.'
                );
            }
        } elseif (!is_string($content)
            || !hash_equals(hash('sha256', $content), $snapshot->contentHash)) {
            throw new HarnessStateTransitionException(
                'Chapter content differs from the Candidate base snapshot.'
            );
        }
    }

    private function assertFrozenBase(
        HarnessCandidate $candidate,
        ?string $expectedBaseRevisionHash
    ): void {
        if (!$this->sameNullable($candidate->baseRevisionHash, $expectedBaseRevisionHash)) {
            throw new HarnessStateTransitionException(
                'Caller expected a different Candidate base revision.'
            );
        }
    }

    private function assertValidationReplay(
        HarnessCandidate $candidate,
        HarnessCandidateValidationEvidence $evidence,
        HarnessCandidateLifecycle $target
    ): void {
        $timestamp = HarnessPersistenceValue::mysqlDate($evidence->evaluatedAt);
        $targetTimestamp = $target === HarnessCandidateLifecycle::APPROVED
            ? $candidate->approvedAt
            : $candidate->quarantinedAt;
        if ($candidate->lifecycle !== $target
            || $candidate->gateState !== $evidence->gateState
            || $candidate->validationArtifactId !== $evidence->provenance->validationArtifactId
            || $candidate->validationJson === null
            || !hash_equals($candidate->validationJson, $evidence->validationJson)
            || $candidate->quarantineReason !== $evidence->quarantineReason
            || $candidate->evaluatedAt !== $timestamp
            || $targetTimestamp !== $timestamp) {
            throw new HarnessIdempotencyConflictException(
                'Candidate validation was already resolved by different evidence.'
            );
        }
    }

    private function terminalTransition(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash,
        HarnessCandidateLifecycle $expectedLifecycle,
        HarnessCandidateLifecycle $targetLifecycle,
        string $timestamp,
        bool $requireValidation,
        bool $requireDivergedBase
    ): HarnessCandidate {
        [$userId, $novelId, $candidateUid, $expectedBaseRevisionHash] =
            $this->normalizeCoordinates(
                $userId,
                $novelId,
                $candidateUid,
                $expectedBaseRevisionHash
            );

        return $this->db->transactional(function () use (
            $userId,
            $novelId,
            $candidateUid,
            $expectedBaseRevisionHash,
            $expectedLifecycle,
            $targetLifecycle,
            $timestamp,
            $requireValidation,
            $requireDivergedBase
        ): HarnessCandidate {
            $row = $this->lockOwnedCandidate($userId, $novelId, $candidateUid);
            $candidate = HarnessCandidate::fromRow($row);
            $this->assertOperationalOwnership($candidate, $row);
            $this->assertFrozenBase($candidate, $expectedBaseRevisionHash);
            $this->authenticateStoredArtifacts($candidate, null, $requireValidation);

            if ($candidate->lifecycle === $targetLifecycle) {
                $storedTimestamp = match ($targetLifecycle) {
                    HarnessCandidateLifecycle::REJECTED => $candidate->rejectedAt,
                    HarnessCandidateLifecycle::STALE => $candidate->staleAt,
                    HarnessCandidateLifecycle::SUPERSEDED => $candidate->updatedAt,
                    default => throw new LogicException('Unknown terminal target.'),
                };
                if ($storedTimestamp !== $timestamp) {
                    throw new HarnessIdempotencyConflictException(
                        'Candidate terminal transition was already resolved differently.'
                    );
                }
                return $candidate;
            }
            if ($candidate->lifecycle !== $expectedLifecycle) {
                throw new HarnessStateTransitionException(
                    'Candidate lifecycle changed before the requested terminal CAS.'
                );
            }

            if ($requireDivergedBase) {
                try {
                    $this->assertLiveBase($candidate, $row, false);
                } catch (HarnessStateTransitionException) {
                    // Divergence is the required proof for stale.
                    $isDiverged = true;
                }
                if (!isset($isDiverged)) {
                    throw new HarnessStateTransitionException(
                        'Candidate base is still current and cannot be marked stale.'
                    );
                }
            }

            $changed = match ($targetLifecycle) {
                HarnessCandidateLifecycle::REJECTED => $this->db->execute(
                    "UPDATE chapter_candidates
                     SET lifecycle='rejected', rejected_at=?, updated_at=?
                     WHERE id=? AND lifecycle=? AND base_revision_hash <=> ?",
                    [
                        $timestamp,
                        $timestamp,
                        $candidate->id,
                        $expectedLifecycle->value,
                        $expectedBaseRevisionHash,
                    ]
                ),
                HarnessCandidateLifecycle::STALE => $this->db->execute(
                    "UPDATE chapter_candidates
                     SET lifecycle='stale', stale_at=?, updated_at=?
                     WHERE id=? AND lifecycle=? AND base_revision_hash <=> ?",
                    [
                        $timestamp,
                        $timestamp,
                        $candidate->id,
                        $expectedLifecycle->value,
                        $expectedBaseRevisionHash,
                    ]
                ),
                HarnessCandidateLifecycle::SUPERSEDED => $this->db->execute(
                    "UPDATE chapter_candidates
                     SET lifecycle='superseded', updated_at=?
                     WHERE id=? AND lifecycle=? AND base_revision_hash <=> ?",
                    [
                        $timestamp,
                        $candidate->id,
                        $expectedLifecycle->value,
                        $expectedBaseRevisionHash,
                    ]
                ),
                default => throw new LogicException('Unknown terminal Candidate target.'),
            };

            $updated = $this->reloadAfterCas(
                $candidate->id,
                $changed,
                $targetLifecycle->value
            );
            if ($updated->lifecycle !== $targetLifecycle) {
                throw new HarnessStateTransitionException(
                    'Candidate terminal CAS resolved to a different lifecycle.'
                );
            }
            $storedTimestamp = match ($targetLifecycle) {
                HarnessCandidateLifecycle::REJECTED => $updated->rejectedAt,
                HarnessCandidateLifecycle::STALE => $updated->staleAt,
                HarnessCandidateLifecycle::SUPERSEDED => $updated->updatedAt,
                default => null,
            };
            if ($storedTimestamp !== $timestamp) {
                throw new HarnessStateTransitionException(
                    'Candidate terminal CAS did not preserve its exact timestamp.'
                );
            }
            return $updated;
        });
    }

    private function reloadAfterCas(
        int $candidateId,
        int $changed,
        string $transition
    ): HarnessCandidate {
        $row = $this->db->fetchOne(
            'SELECT * FROM chapter_candidates WHERE id=? FOR UPDATE',
            [$candidateId]
        );
        if ($row === null) {
            throw new HarnessStateTransitionException(
                "Candidate disappeared during {$transition} CAS."
            );
        }
        if ($changed !== 1) {
            // Returning the reloaded row lets the caller-specific replay check
            // distinguish an exact retry from a concurrent conflicting write.
            return HarnessCandidate::fromRow($row);
        }
        return HarnessCandidate::fromRow($row);
    }

    /**
     * @return array{0:int,1:int,2:string,3:?string}
     */
    private function normalizeCoordinates(
        int $userId,
        int $novelId,
        string $candidateUid,
        ?string $expectedBaseRevisionHash
    ): array {
        return [
            HarnessPersistenceValue::positiveInt($userId, 'user_id'),
            HarnessPersistenceValue::positiveInt($novelId, 'novel_id'),
            HarnessPersistenceValue::uid($candidateUid, 'candidate_uid'),
            HarnessPersistenceValue::optionalHash(
                $expectedBaseRevisionHash,
                'expected_base_revision_hash'
            ),
        ];
    }

    /** @param array<string,mixed> $row */
    private function positive(array $row, string $field): int
    {
        return HarnessPersistenceValue::positiveInt($row[$field] ?? null, $field);
    }

    private function sameNullable(?string $left, ?string $right): bool
    {
        return $left === null || $right === null
            ? $left === $right
            : hash_equals($left, $right);
    }
}
