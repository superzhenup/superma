<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/../persistence/HarnessSensitiveDataGuard.php';
require_once __DIR__ . '/../persistence/GenerationArtifact.php';

/**
 * Immutable proof that a Candidate is only a projection of durable Artifacts.
 *
 * The database stores Artifact ids on chapter_candidates. The additional UID,
 * Run and hash coordinates held here force callers to authenticate the exact
 * Artifact again inside the Candidate transaction, rather than trusting an id
 * supplied by another worker.
 */
final readonly class CandidateArtifactProvenance
{
    private const DRAFT_TYPE = 'draft.full';
    private const VALIDATION_TYPE = 'quality.decision';

    public int $runId;
    public int $draftArtifactId;
    public string $draftArtifactUid;
    public string $draftContentHash;
    public ?int $validationArtifactId;
    public ?string $validationArtifactUid;
    public ?string $validationContentHash;

    private function __construct(
        int $runId,
        int $draftArtifactId,
        string $draftArtifactUid,
        string $draftContentHash,
        ?int $validationArtifactId,
        ?string $validationArtifactUid,
        ?string $validationContentHash
    ) {
        $this->runId = HarnessPersistenceValue::positiveInt($runId, 'provenance.run_id');
        $this->draftArtifactId = HarnessPersistenceValue::positiveInt(
            $draftArtifactId,
            'provenance.draft_artifact_id'
        );
        $this->draftArtifactUid = HarnessPersistenceValue::uid(
            $draftArtifactUid,
            'provenance.draft_artifact_uid'
        );
        $this->draftContentHash = HarnessPersistenceValue::hash(
            $draftContentHash,
            'provenance.draft_content_hash'
        );
        $this->validationArtifactId = HarnessPersistenceValue::optionalPositiveInt(
            $validationArtifactId,
            'provenance.validation_artifact_id'
        );
        $this->validationArtifactUid = $validationArtifactUid === null
            ? null
            : HarnessPersistenceValue::uid(
                $validationArtifactUid,
                'provenance.validation_artifact_uid'
            );
        $this->validationContentHash = HarnessPersistenceValue::optionalHash(
            $validationContentHash,
            'provenance.validation_content_hash'
        );

        $validationCoordinates = (int)($this->validationArtifactId !== null)
            + (int)($this->validationArtifactUid !== null)
            + (int)($this->validationContentHash !== null);
        if (!in_array($validationCoordinates, [0, 3], true)) {
            throw new InvalidArgumentException(
                'Validation Artifact provenance must be entirely absent or entirely present.'
            );
        }
    }

    public static function forDraft(GenerationArtifact $draftArtifact): self
    {
        self::assertDraftRole($draftArtifact);

        return new self(
            $draftArtifact->runId,
            $draftArtifact->id,
            $draftArtifact->artifactUid,
            $draftArtifact->contentHash,
            null,
            null,
            null
        );
    }

    public function withValidation(GenerationArtifact $validationArtifact): self
    {
        if ($this->hasValidation()) {
            if ($this->validationArtifactId === $validationArtifact->id
                && $this->validationArtifactUid === $validationArtifact->artifactUid
                && $this->validationContentHash !== null
                && hash_equals($this->validationContentHash, $validationArtifact->contentHash)) {
                self::assertValidationRole($validationArtifact, $this->runId);
                return $this;
            }
            throw new HarnessIdempotencyConflictException(
                'Candidate provenance already names a different Validation Artifact.'
            );
        }

        self::assertValidationRole($validationArtifact, $this->runId);
        return new self(
            $this->runId,
            $this->draftArtifactId,
            $this->draftArtifactUid,
            $this->draftContentHash,
            $validationArtifact->id,
            $validationArtifact->artifactUid,
            $validationArtifact->contentHash
        );
    }

    public function hasValidation(): bool
    {
        return $this->validationArtifactId !== null;
    }

    public function assertCreationProvenance(): void
    {
        if ($this->hasValidation()) {
            throw new InvalidArgumentException(
                'A new Candidate must not be born with a Validation Artifact.'
            );
        }
    }

    public function assertTransitionProvenance(): void
    {
        if (!$this->hasValidation()) {
            throw new InvalidArgumentException(
                'A Candidate validation transition requires a Validation Artifact.'
            );
        }
    }

    public function assertDraftArtifact(
        GenerationArtifact $artifact,
        string $candidateContent
    ): void {
        self::assertDraftRole($artifact);
        if ($artifact->id !== $this->draftArtifactId
            || $artifact->runId !== $this->runId
            || $artifact->artifactUid !== $this->draftArtifactUid
            || !hash_equals($artifact->contentHash, $this->draftContentHash)) {
            throw new HarnessPersistenceException(
                'Draft Artifact no longer matches the frozen Candidate provenance.'
            );
        }

        HarnessSensitiveDataGuard::assertSafeText($candidateContent, 'candidate.inline_content');
        if (!hash_equals(hash('sha256', $candidateContent), $artifact->contentHash)) {
            throw new HarnessPersistenceException(
                'Candidate bytes do not match the Draft Artifact content hash.'
            );
        }
        if ($artifact->inlineText !== null
            && !hash_equals($artifact->inlineText, $candidateContent)) {
            throw new HarnessPersistenceException(
                'Candidate bytes differ from its inline Draft Artifact.'
            );
        }
    }

    /** @param array<mixed> $validationPayload */
    public function assertValidationArtifact(
        GenerationArtifact $artifact,
        array $validationPayload
    ): void {
        $this->assertTransitionProvenance();
        self::assertValidationRole($artifact, $this->runId);
        if ($artifact->id !== $this->validationArtifactId
            || $artifact->artifactUid !== $this->validationArtifactUid
            || $this->validationContentHash === null
            || !hash_equals($artifact->contentHash, $this->validationContentHash)) {
            throw new HarnessPersistenceException(
                'Validation Artifact no longer matches the frozen Candidate provenance.'
            );
        }

        HarnessSensitiveDataGuard::assertSafeStructure(
            $validationPayload,
            'candidate.validation'
        );
        $canonical = HarnessPersistenceValue::canonicalMysqlJson($validationPayload);
        HarnessSensitiveDataGuard::assertSafeText($canonical, 'candidate.validation');
        if (!hash_equals(hash('sha256', $canonical), $artifact->contentHash)
            || $artifact->inlineJson === null
            || !hash_equals($artifact->inlineJson, $canonical)) {
            throw new HarnessPersistenceException(
                'Validation payload is not the canonical payload of its Artifact.'
            );
        }
    }

    private static function assertDraftRole(GenerationArtifact $artifact): void
    {
        if ($artifact->artifactType !== self::DRAFT_TYPE
            || $artifact->mimeType !== 'text/plain'
            || strtolower($artifact->encoding) !== 'utf-8'
            || $artifact->sensitivity !== GenerationArtifactSensitivity::RESTRICTED
            || $artifact->inlineJson !== null) {
            throw new HarnessPersistenceException(
                'Candidate Draft provenance requires a restricted draft.full text Artifact.'
            );
        }
    }

    private static function assertValidationRole(
        GenerationArtifact $artifact,
        int $runId
    ): void {
        if ($artifact->runId !== $runId
            || $artifact->artifactType !== self::VALIDATION_TYPE
            || $artifact->mimeType !== 'application/json'
            || strtolower($artifact->encoding) !== 'utf-8'
            || $artifact->storageKind !== GenerationArtifactStorageKind::INLINE
            || $artifact->inlineJson === null
            || $artifact->sensitivity === GenerationArtifactSensitivity::PUBLIC) {
            throw new HarnessPersistenceException(
                'Candidate validation requires a non-public inline quality.decision JSON Artifact from the same Run.'
            );
        }
    }
}
