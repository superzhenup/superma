<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/../persistence/HarnessSensitiveDataGuard.php';
require_once __DIR__ . '/CandidateArtifactProvenance.php';

enum HarnessCandidateSource: string
{
    case INITIAL = 'initial';
    case REWRITE = 'rewrite';
}

enum HarnessCandidateLifecycle: string
{
    case DRAFT = 'draft';
    case EVALUATED = 'evaluated'; // M60-M62 compatibility.
    case QUARANTINED = 'quarantined';
    case APPROVED = 'approved';
    case COMMITTED = 'committed';
    case REJECTED = 'rejected';
    case STALE = 'stale';
    case SUPERSEDED = 'superseded'; // M60-M62 compatibility.

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMMITTED,
            self::REJECTED,
            self::STALE,
            self::SUPERSEDED,
        ], true);
    }
}

enum HarnessCandidateGateState: string
{
    case PENDING = 'pending';
    case PASSED = 'passed';
    case QUARANTINED = 'quarantined';
    case ERROR = 'error';
}

/** The exact Chapter state against which the Candidate was generated. */
final readonly class HarnessCandidateBaseSnapshot
{
    private const FIELDS = [
        'status',
        'canonical_state',
        'content_revision_hash',
        'content_hash',
        'claim_token',
    ];

    public string $status;
    public string $canonicalState;
    public ?string $contentRevisionHash;
    public ?string $contentHash;
    public string $claimToken;
    public string $json;

    public function __construct(
        string $status,
        string $canonicalState,
        ?string $contentRevisionHash,
        ?string $contentHash,
        string $claimToken
    ) {
        if (!in_array($status, [
            'pending',
            'outlined',
            'writing',
            'completed',
            'skipped',
            'failed',
        ], true)) {
            throw new InvalidArgumentException('Candidate base Chapter status is invalid.');
        }
        if (!in_array($canonicalState, ['none', 'legacy', 'canonical'], true)) {
            throw new InvalidArgumentException('Candidate base Canon state is invalid.');
        }
        $revisionHash = HarnessPersistenceValue::optionalHash(
            $contentRevisionHash,
            'base.content_revision_hash'
        );
        $bodyHash = HarnessPersistenceValue::optionalHash($contentHash, 'base.content_hash');
        $claim = HarnessPersistenceValue::uid($claimToken, 'base.claim_token');

        if ($canonicalState === 'none' && $revisionHash !== null) {
            throw new InvalidArgumentException(
                'A non-Canon base cannot carry a Canon revision hash.'
            );
        }
        if ($canonicalState !== 'none'
            && ($revisionHash === null
                || $bodyHash === null
                || !hash_equals($revisionHash, $bodyHash))) {
            throw new InvalidArgumentException(
                'An existing Canon base requires matching body and revision hashes.'
            );
        }

        $this->status = $status;
        $this->canonicalState = $canonicalState;
        $this->contentRevisionHash = $revisionHash;
        $this->contentHash = $bodyHash;
        $this->claimToken = $claim;
        // claim_token is an application authorization coordinate frozen by this
        // schema, not a provider credential. Validate its exact UID shape above
        // instead of passing the object through the generic credential-key guard.
        $this->json = HarnessPersistenceValue::canonicalMysqlJson($this->toArray());
    }

    /** @param array<mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_is_list($value)
            || array_diff(self::FIELDS, array_keys($value)) !== []
            || array_diff(array_keys($value), self::FIELDS) !== []) {
            throw new InvalidArgumentException(
                'Candidate base snapshot fields do not match the frozen schema.'
            );
        }
        if (!is_string($value['status'])
            || !is_string($value['canonical_state'])
            || (!is_string($value['claim_token']))) {
            throw new InvalidArgumentException('Candidate base snapshot scalar types are invalid.');
        }
        return new self(
            $value['status'],
            $value['canonical_state'],
            is_string($value['content_revision_hash'])
                ? $value['content_revision_hash']
                : ($value['content_revision_hash'] === null
                    ? null
                    : throw new InvalidArgumentException('base.content_revision_hash is invalid.')),
            is_string($value['content_hash'])
                ? $value['content_hash']
                : ($value['content_hash'] === null
                    ? null
                    : throw new InvalidArgumentException('base.content_hash is invalid.')),
            $value['claim_token']
        );
    }

    public static function fromJson(mixed $json): self
    {
        return self::fromArray(
            HarnessPersistenceValue::decodeJsonArray($json, 'base_snapshot_json')
        );
    }

    /** @return array<string,string|null> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'canonical_state' => $this->canonicalState,
            'content_revision_hash' => $this->contentRevisionHash,
            'content_hash' => $this->contentHash,
            'claim_token' => $this->claimToken,
        ];
    }
}

final readonly class HarnessCandidateDraft
{
    public string $candidateUid;
    public int $userId;
    public int $novelId;
    public int $chapterId;
    public HarnessCandidateSource $source;
    public ?string $sourceTaskId;
    public ?string $parentCandidateUid;
    public ?string $baseRevisionHash;
    public HarnessCandidateBaseSnapshot $baseSnapshot;
    public string $inlineContent;
    public string $contentHash;
    public int $words;
    public string $policySnapshotJson;
    public string $policySnapshotHash;
    public CandidateArtifactProvenance $provenance;

    /** @param array<mixed> $policySnapshot */
    public function __construct(
        string $candidateUid,
        int $userId,
        int $novelId,
        int $chapterId,
        HarnessCandidateSource $source,
        ?string $sourceTaskId,
        ?string $parentCandidateUid,
        ?string $baseRevisionHash,
        HarnessCandidateBaseSnapshot $baseSnapshot,
        string $inlineContent,
        int $words,
        array $policySnapshot,
        CandidateArtifactProvenance $provenance
    ) {
        $this->candidateUid = HarnessPersistenceValue::uid($candidateUid, 'candidate_uid');
        $this->userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $this->novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $this->chapterId = HarnessPersistenceValue::positiveInt($chapterId, 'chapter_id');
        $this->source = $source;
        $this->sourceTaskId = $sourceTaskId === null
            ? null
            : HarnessPersistenceValue::identifier($sourceTaskId, 'source_task_id', 64);
        $this->parentCandidateUid = $parentCandidateUid === null
            ? null
            : HarnessPersistenceValue::uid($parentCandidateUid, 'parent_candidate_uid');
        $this->baseRevisionHash = HarnessPersistenceValue::optionalHash(
            $baseRevisionHash,
            'base_revision_hash'
        );
        $this->baseSnapshot = $baseSnapshot;
        if ($this->baseRevisionHash !== $baseSnapshot->contentRevisionHash) {
            throw new InvalidArgumentException(
                'Candidate base_revision_hash must equal the frozen base snapshot revision.'
            );
        }

        if (preg_match('//u', $inlineContent) !== 1) {
            throw new InvalidArgumentException('Candidate content must be valid UTF-8.');
        }
        HarnessSensitiveDataGuard::assertSafeText($inlineContent, 'candidate.inline_content');
        $this->inlineContent = $inlineContent;
        $this->contentHash = hash('sha256', $inlineContent);
        $this->words = HarnessPersistenceValue::nonNegativeInt($words, 'words');

        HarnessSensitiveDataGuard::assertSafeStructure($policySnapshot, 'candidate.policy_snapshot');
        $this->policySnapshotJson = HarnessPersistenceValue::canonicalMysqlJson(
            $policySnapshot
        );
        HarnessSensitiveDataGuard::assertSafeText(
            $this->policySnapshotJson,
            'candidate.policy_snapshot'
        );
        $this->policySnapshotHash = HarnessPersistenceValue::policySnapshotHash(
            $policySnapshot,
            $this->policySnapshotJson
        );
        $this->provenance = $provenance;
        $provenance->assertCreationProvenance();
        if (!hash_equals($this->contentHash, $provenance->draftContentHash)) {
            throw new InvalidArgumentException(
                'Candidate content hash must equal its Draft Artifact hash.'
            );
        }

        if ($source === HarnessCandidateSource::INITIAL) {
            if ($this->parentCandidateUid !== null
                || $baseSnapshot->canonicalState !== 'none') {
                throw new InvalidArgumentException(
                    'An initial Candidate requires a non-Canon base and no parent.'
                );
            }
        } elseif ($this->parentCandidateUid === null
            || $this->baseRevisionHash === null
            || $baseSnapshot->canonicalState === 'none') {
            throw new InvalidArgumentException(
                'A rewrite Candidate requires an existing Canon base, revision, and parent.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row, string $sourceRunUid): HarnessCandidate
    {
        $candidate = HarnessCandidate::fromRow($row);
        if ($candidate->candidateUid !== $this->candidateUid
            || $candidate->userId !== $this->userId
            || $candidate->novelId !== $this->novelId
            || $candidate->chapterId !== $this->chapterId
            || $candidate->runId !== $this->provenance->runId
            || $candidate->source !== $this->source
            || $candidate->sourceRunUid !== $sourceRunUid
            || $candidate->sourceTaskId !== $this->sourceTaskId
            || $candidate->parentCandidateUid !== $this->parentCandidateUid
            || $candidate->baseRevisionHash !== $this->baseRevisionHash
            || $candidate->baseSnapshot === null
            || !hash_equals($candidate->baseSnapshot->json, $this->baseSnapshot->json)
            || $candidate->draftArtifactId !== $this->provenance->draftArtifactId
            || !hash_equals($candidate->inlineContent, $this->inlineContent)
            || !hash_equals($candidate->contentHash, $this->contentHash)
            || $candidate->words !== $this->words
            || !hash_equals($candidate->policySnapshotJson ?? '', $this->policySnapshotJson)
            || $candidate->policySnapshotHash === null
            || !hash_equals($candidate->policySnapshotHash, $this->policySnapshotHash)) {
            throw new HarnessIdempotencyConflictException(
                'Candidate uniqueness resolved to different immutable coordinates.'
            );
        }
        return $candidate;
    }
}

/** Artifact-backed evidence for the only quality-gate write transition. */
final readonly class HarnessCandidateValidationEvidence
{
    public CandidateArtifactProvenance $provenance;
    public HarnessCandidateGateState $gateState;
    public string $validationJson;
    public string $validationHash;
    public ?string $quarantineReason;
    public DateTimeImmutable $evaluatedAt;

    /** @param array<mixed> $validation */
    public function __construct(
        CandidateArtifactProvenance $provenance,
        HarnessCandidateGateState $gateState,
        array $validation,
        ?string $quarantineReason,
        DateTimeImmutable $evaluatedAt
    ) {
        $provenance->assertTransitionProvenance();
        if (!in_array($gateState, [
            HarnessCandidateGateState::PASSED,
            HarnessCandidateGateState::QUARANTINED,
        ], true)) {
            throw new InvalidArgumentException(
                'Validation evidence must decide passed or quarantined.'
            );
        }
        HarnessSensitiveDataGuard::assertSafeStructure($validation, 'candidate.validation');
        $json = HarnessPersistenceValue::canonicalMysqlJson($validation);
        HarnessSensitiveDataGuard::assertSafeText($json, 'candidate.validation');
        $hash = hash('sha256', $json);
        if ($provenance->validationContentHash === null
            || !hash_equals($provenance->validationContentHash, $hash)) {
            throw new InvalidArgumentException(
                'Validation evidence hash must equal its Validation Artifact hash.'
            );
        }

        $reason = self::optionalFreeText($quarantineReason, 'quarantine_reason', 4000);
        if ($gateState === HarnessCandidateGateState::QUARANTINED && $reason === null) {
            throw new InvalidArgumentException('A quarantined Candidate requires a reason.');
        }
        if ($gateState === HarnessCandidateGateState::PASSED && $reason !== null) {
            throw new InvalidArgumentException('A passed Candidate cannot carry a quarantine reason.');
        }

        $this->provenance = $provenance;
        $this->gateState = $gateState;
        $this->validationJson = $json;
        $this->validationHash = $hash;
        $this->quarantineReason = $reason;
        $this->evaluatedAt = $evaluatedAt;
    }

    private static function optionalFreeText(
        ?string $value,
        string $field,
        int $maximumBytes
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("{$field} is malformed.");
        }
        HarnessSensitiveDataGuard::assertSafeText($value, $field);
        return $value;
    }
}

/** Fully validated chapter_candidates record. */
final readonly class HarnessCandidate
{
    public function __construct(
        public int $id,
        public string $candidateUid,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public ?int $runId,
        public HarnessCandidateSource $source,
        public ?string $sourceRunUid,
        public ?string $sourceTaskId,
        public ?string $parentCandidateUid,
        public ?string $baseRevisionHash,
        public ?HarnessCandidateBaseSnapshot $baseSnapshot,
        public ?int $draftArtifactId,
        public string $inlineContent,
        public string $contentHash,
        public int $words,
        public HarnessCandidateLifecycle $lifecycle,
        public HarnessCandidateGateState $gateState,
        public ?int $validationArtifactId,
        public ?string $validationJson,
        public ?string $quarantineReason,
        public ?string $policySnapshotJson,
        public ?string $policySnapshotHash,
        public ?string $evaluatedAt,
        public ?string $quarantinedAt,
        public ?string $approvedAt,
        public ?string $rejectedAt,
        public ?string $staleAt,
        public ?string $committedAt,
        public string $createdAt,
        public string $updatedAt
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'candidate_uid', 'user_id', 'novel_id', 'chapter_id', 'run_id',
            'source', 'source_run_uid', 'source_task_id', 'parent_candidate_uid',
            'base_revision_hash', 'base_snapshot_json', 'draft_artifact_id',
            'inline_content', 'content_hash', 'words', 'lifecycle', 'gate_state',
            'validation_artifact_id', 'validation_json', 'quarantine_reason',
            'policy_snapshot_json', 'policy_snapshot_hash', 'evaluated_at',
            'quarantined_at', 'approved_at', 'rejected_at', 'stale_at',
            'committed_at', 'created_at', 'updated_at',
        ], 'chapter_candidate');

        $source = is_string($row['source'])
            ? HarnessCandidateSource::tryFrom($row['source'])
            : null;
        $lifecycle = is_string($row['lifecycle'])
            ? HarnessCandidateLifecycle::tryFrom($row['lifecycle'])
            : null;
        $gate = is_string($row['gate_state'])
            ? HarnessCandidateGateState::tryFrom($row['gate_state'])
            : null;
        if (!$source instanceof HarnessCandidateSource
            || !$lifecycle instanceof HarnessCandidateLifecycle
            || !$gate instanceof HarnessCandidateGateState) {
            throw new InvalidArgumentException('Candidate contains an unknown enum value.');
        }

        $inlineContent = $row['inline_content'];
        if (!is_string($inlineContent) || preg_match('//u', $inlineContent) !== 1) {
            throw new InvalidArgumentException('Candidate inline_content must be UTF-8 text.');
        }
        HarnessSensitiveDataGuard::assertSafeText($inlineContent, 'candidate.inline_content');
        $contentHash = HarnessPersistenceValue::hash($row['content_hash'], 'content_hash');
        if (!hash_equals(hash('sha256', $inlineContent), $contentHash)) {
            throw new HarnessPersistenceException('Candidate content hash is invalid.');
        }

        $base = null;
        if ($row['base_snapshot_json'] !== null && $row['base_snapshot_json'] !== '') {
            $base = HarnessCandidateBaseSnapshot::fromJson($row['base_snapshot_json']);
        }
        $baseRevisionHash = HarnessPersistenceValue::optionalHash(
            $row['base_revision_hash'],
            'base_revision_hash'
        );
        if ($base !== null && $base->contentRevisionHash !== $baseRevisionHash) {
            throw new HarnessPersistenceException(
                'Candidate base revision differs from its base snapshot.'
            );
        }

        $policyJson = null;
        $policyHash = HarnessPersistenceValue::optionalHash(
            $row['policy_snapshot_hash'],
            'policy_snapshot_hash'
        );
        if ($row['policy_snapshot_json'] !== null && $row['policy_snapshot_json'] !== '') {
            $policy = HarnessPersistenceValue::decodeJsonArray(
                $row['policy_snapshot_json'],
                'policy_snapshot_json'
            );
            HarnessSensitiveDataGuard::assertSafeStructure($policy, 'candidate.policy_snapshot');
            $policyJson = HarnessPersistenceValue::canonicalMysqlJson($policy);
            HarnessSensitiveDataGuard::assertSafeText($policyJson, 'candidate.policy_snapshot');
            if ($policyHash === null
                || !hash_equals(
                    HarnessPersistenceValue::policySnapshotHash($policy, $policyJson),
                    $policyHash
                )) {
                throw new HarnessPersistenceException('Candidate policy snapshot hash is invalid.');
            }
        } elseif ($policyHash !== null) {
            throw new HarnessPersistenceException(
                'Candidate policy hash exists without its snapshot.'
            );
        }

        $validationJson = null;
        if ($row['validation_json'] !== null && $row['validation_json'] !== '') {
            $validation = HarnessPersistenceValue::decodeJsonArray(
                $row['validation_json'],
                'validation_json'
            );
            HarnessSensitiveDataGuard::assertSafeStructure($validation, 'candidate.validation');
            $validationJson = HarnessPersistenceValue::canonicalMysqlJson($validation);
            HarnessSensitiveDataGuard::assertSafeText($validationJson, 'candidate.validation');
        }
        $reason = self::optionalFreeText($row['quarantine_reason'], 'quarantine_reason', 4000);

        $sourceRunUid = $row['source_run_uid'] === null || $row['source_run_uid'] === ''
            ? null
            : HarnessPersistenceValue::uid($row['source_run_uid'], 'source_run_uid');
        $sourceTaskId = $row['source_task_id'] === null || $row['source_task_id'] === ''
            ? null
            : HarnessPersistenceValue::identifier($row['source_task_id'], 'source_task_id', 64);
        $parentUid = $row['parent_candidate_uid'] === null || $row['parent_candidate_uid'] === ''
            ? null
            : HarnessPersistenceValue::uid(
                $row['parent_candidate_uid'],
                'parent_candidate_uid'
            );
        if ($source === HarnessCandidateSource::INITIAL && $parentUid !== null) {
            throw new HarnessPersistenceException('Initial Candidate cannot name a parent.');
        }
        if ($source === HarnessCandidateSource::REWRITE && $parentUid === null) {
            throw new HarnessPersistenceException('Rewrite Candidate must name a parent.');
        }

        $evaluatedAt = HarnessPersistenceValue::optionalMysqlDate($row['evaluated_at'], 'evaluated_at');
        $quarantinedAt = HarnessPersistenceValue::optionalMysqlDate($row['quarantined_at'], 'quarantined_at');
        $approvedAt = HarnessPersistenceValue::optionalMysqlDate($row['approved_at'], 'approved_at');
        $rejectedAt = HarnessPersistenceValue::optionalMysqlDate($row['rejected_at'], 'rejected_at');
        $staleAt = HarnessPersistenceValue::optionalMysqlDate($row['stale_at'], 'stale_at');
        $committedAt = HarnessPersistenceValue::optionalMysqlDate($row['committed_at'], 'committed_at');
        self::assertLifecycleShape(
            $lifecycle,
            $gate,
            $validationJson,
            $reason,
            $evaluatedAt,
            $quarantinedAt,
            $approvedAt,
            $rejectedAt,
            $staleAt,
            $committedAt
        );

        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            HarnessPersistenceValue::uid($row['candidate_uid'], 'candidate_uid'),
            HarnessPersistenceValue::positiveInt($row['user_id'], 'user_id'),
            HarnessPersistenceValue::positiveInt($row['novel_id'], 'novel_id'),
            HarnessPersistenceValue::positiveInt($row['chapter_id'], 'chapter_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['run_id'], 'run_id'),
            $source,
            $sourceRunUid,
            $sourceTaskId,
            $parentUid,
            $baseRevisionHash,
            $base,
            HarnessPersistenceValue::optionalPositiveInt(
                $row['draft_artifact_id'],
                'draft_artifact_id'
            ),
            $inlineContent,
            $contentHash,
            HarnessPersistenceValue::nonNegativeInt($row['words'], 'words'),
            $lifecycle,
            $gate,
            HarnessPersistenceValue::optionalPositiveInt(
                $row['validation_artifact_id'],
                'validation_artifact_id'
            ),
            $validationJson,
            $reason,
            $policyJson,
            $policyHash,
            $evaluatedAt,
            $quarantinedAt,
            $approvedAt,
            $rejectedAt,
            $staleAt,
            $committedAt,
            HarnessPersistenceValue::optionalMysqlDate($row['created_at'], 'created_at')
                ?? throw new InvalidArgumentException('Candidate created_at is required.'),
            HarnessPersistenceValue::optionalMysqlDate($row['updated_at'], 'updated_at')
                ?? throw new InvalidArgumentException('Candidate updated_at is required.')
        );
    }

    public function assertLiveArtifactCoordinates(): void
    {
        if ($this->runId === null
            || $this->sourceRunUid === null
            || $this->baseSnapshot === null
            || $this->draftArtifactId === null
            || $this->policySnapshotJson === null
            || $this->policySnapshotHash === null) {
            throw new HarnessPersistenceException(
                'Candidate has lost required Run, snapshot, policy, or Draft Artifact provenance.'
            );
        }
    }

    private static function assertLifecycleShape(
        HarnessCandidateLifecycle $lifecycle,
        HarnessCandidateGateState $gate,
        ?string $validationJson,
        ?string $reason,
        ?string $evaluatedAt,
        ?string $quarantinedAt,
        ?string $approvedAt,
        ?string $rejectedAt,
        ?string $staleAt,
        ?string $committedAt
    ): void {
        if ($lifecycle === HarnessCandidateLifecycle::DRAFT) {
            if ($gate !== HarnessCandidateGateState::PENDING
                || $validationJson !== null
                || $reason !== null
                || $evaluatedAt !== null
                || $quarantinedAt !== null
                || $approvedAt !== null
                || $rejectedAt !== null
                || $staleAt !== null
                || $committedAt !== null) {
                throw new HarnessPersistenceException('Draft Candidate contains transition state.');
            }
            return;
        }

        if ($lifecycle === HarnessCandidateLifecycle::EVALUATED) {
            if ($gate !== HarnessCandidateGateState::PASSED
                || $validationJson === null
                || $evaluatedAt === null
                || $committedAt !== null) {
                throw new HarnessPersistenceException('Legacy evaluated Candidate is inconsistent.');
            }
            return;
        }

        if ($lifecycle === HarnessCandidateLifecycle::QUARANTINED) {
            if ($gate !== HarnessCandidateGateState::QUARANTINED
                || $validationJson === null
                || $reason === null
                || $evaluatedAt === null
                || $quarantinedAt === null
                || $approvedAt !== null
                || $rejectedAt !== null
                || $staleAt !== null
                || $committedAt !== null) {
                throw new HarnessPersistenceException('Quarantined Candidate is inconsistent.');
            }
            return;
        }

        if ($lifecycle === HarnessCandidateLifecycle::APPROVED) {
            if (!in_array($gate, [
                HarnessCandidateGateState::PASSED,
                HarnessCandidateGateState::QUARANTINED,
            ], true)
                || $validationJson === null
                || $evaluatedAt === null
                || $approvedAt === null
                || $rejectedAt !== null
                || $staleAt !== null
                || $committedAt !== null) {
                throw new HarnessPersistenceException('Approved Candidate is inconsistent.');
            }
            return;
        }

        if ($lifecycle === HarnessCandidateLifecycle::COMMITTED) {
            if (!in_array($gate, [
                HarnessCandidateGateState::PASSED,
                HarnessCandidateGateState::QUARANTINED,
            ], true)
                || $validationJson === null
                || $evaluatedAt === null
                || $committedAt === null
                || $rejectedAt !== null
                || $staleAt !== null) {
                throw new HarnessPersistenceException('Committed Candidate is inconsistent.');
            }
            return;
        }

        if ($lifecycle === HarnessCandidateLifecycle::REJECTED) {
            if ($rejectedAt === null || $staleAt !== null || $committedAt !== null) {
                throw new HarnessPersistenceException('Rejected Candidate is inconsistent.');
            }
            return;
        }

        if ($lifecycle === HarnessCandidateLifecycle::STALE) {
            if ($staleAt === null || $rejectedAt !== null || $committedAt !== null) {
                throw new HarnessPersistenceException('Stale Candidate is inconsistent.');
            }
            return;
        }

        if ($committedAt !== null || $rejectedAt !== null || $staleAt !== null) {
            throw new HarnessPersistenceException('Superseded Candidate is inconsistent.');
        }
    }

    private static function optionalFreeText(
        mixed $value,
        string $field,
        int $maximumBytes
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)
            || strlen($value) > $maximumBytes
            || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("{$field} is malformed.");
        }
        HarnessSensitiveDataGuard::assertSafeText($value, $field);
        return $value;
    }
}
