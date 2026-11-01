<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessSensitiveDataGuard.php';

enum GenerationArtifactStorageKind: string
{
    case INLINE = 'inline';
    case EXTERNAL = 'external';
}

enum GenerationArtifactSensitivity: string
{
    case INTERNAL = 'internal';
    case RESTRICTED = 'restricted';
    case PUBLIC = 'public';
}

final readonly class GenerationArtifactDraft
{
    public string $artifactUid;
    public int $runId;
    public ?int $stepId;
    public string $artifactType;
    public string $logicalKey;
    public int $revisionNo;
    public string $contentHash;
    public string $mimeType;
    public string $encoding;
    public GenerationArtifactStorageKind $storageKind;
    public ?string $inlineText;
    public ?string $inlineJson;
    public ?string $storageUri;
    public int $sizeBytes;
    public GenerationArtifactSensitivity $sensitivity;
    public string $metadataJson;
    public ?int $supersedesArtifactId;
    public ?DateTimeImmutable $retentionUntil;

    /** @param array<string,mixed> $metadata */
    public static function inlineText(
        string $artifactUid,
        int $runId,
        ?int $stepId,
        string $artifactType,
        string $logicalKey,
        int $revisionNo,
        string $content,
        string $mimeType,
        string $encoding,
        GenerationArtifactSensitivity $sensitivity,
        array $metadata = [],
        ?int $supersedesArtifactId = null,
        ?DateTimeImmutable $retentionUntil = null
    ): self {
        return new self(
            $artifactUid,
            $runId,
            $stepId,
            $artifactType,
            $logicalKey,
            $revisionNo,
            $content,
            null,
            null,
            hash('sha256', $content),
            strlen($content),
            $mimeType,
            $encoding,
            GenerationArtifactStorageKind::INLINE,
            $sensitivity,
            $metadata,
            $supersedesArtifactId,
            $retentionUntil
        );
    }

    /** @param array<mixed> $content @param array<string,mixed> $metadata */
    public static function inlineJson(
        string $artifactUid,
        int $runId,
        ?int $stepId,
        string $artifactType,
        string $logicalKey,
        int $revisionNo,
        array $content,
        GenerationArtifactSensitivity $sensitivity,
        array $metadata = [],
        ?int $supersedesArtifactId = null,
        ?DateTimeImmutable $retentionUntil = null
    ): self {
        // MySQL 5.7's native JSON storage normalizes integral floats (including
        // negative zero) to integers. Bind Artifact identity to those durable
        // bytes up front so insert/read validation cannot drift after storage.
        $canonical = HarnessPersistenceValue::canonicalMysqlJson($content);
        return new self(
            $artifactUid,
            $runId,
            $stepId,
            $artifactType,
            $logicalKey,
            $revisionNo,
            null,
            $canonical,
            null,
            hash('sha256', $canonical),
            strlen($canonical),
            'application/json',
            'utf-8',
            GenerationArtifactStorageKind::INLINE,
            $sensitivity,
            $metadata,
            $supersedesArtifactId,
            $retentionUntil
        );
    }

    /** @param array<string,mixed> $metadata */
    public static function external(
        string $artifactUid,
        int $runId,
        ?int $stepId,
        string $artifactType,
        string $logicalKey,
        int $revisionNo,
        string $storageUri,
        string $contentHash,
        int $sizeBytes,
        string $mimeType,
        string $encoding,
        GenerationArtifactSensitivity $sensitivity,
        array $metadata = [],
        ?int $supersedesArtifactId = null,
        ?DateTimeImmutable $retentionUntil = null
    ): self {
        return new self(
            $artifactUid,
            $runId,
            $stepId,
            $artifactType,
            $logicalKey,
            $revisionNo,
            null,
            null,
            $storageUri,
            $contentHash,
            $sizeBytes,
            $mimeType,
            $encoding,
            GenerationArtifactStorageKind::EXTERNAL,
            $sensitivity,
            $metadata,
            $supersedesArtifactId,
            $retentionUntil
        );
    }

    /** @param array<string,mixed> $metadata */
    private function __construct(
        string $artifactUid,
        int $runId,
        ?int $stepId,
        string $artifactType,
        string $logicalKey,
        int $revisionNo,
        ?string $inlineText,
        ?string $inlineJson,
        ?string $storageUri,
        string $contentHash,
        int $sizeBytes,
        string $mimeType,
        string $encoding,
        GenerationArtifactStorageKind $storageKind,
        GenerationArtifactSensitivity $sensitivity,
        array $metadata,
        ?int $supersedesArtifactId,
        ?DateTimeImmutable $retentionUntil
    ) {
        $this->artifactUid = HarnessPersistenceValue::uid($artifactUid, 'artifact_uid');
        $this->runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $this->stepId = HarnessPersistenceValue::optionalPositiveInt($stepId, 'step_id');
        $this->artifactType = HarnessPersistenceValue::identifier(
            $artifactType,
            'artifact_type',
            64
        );
        $this->logicalKey = HarnessPersistenceValue::identifier($logicalKey, 'logical_key', 191);
        HarnessSensitiveDataGuard::assertSafeLabel($this->artifactType, 'artifact_type');
        HarnessSensitiveDataGuard::assertSafeLabel($this->logicalKey, 'logical_key');
        $this->revisionNo = HarnessPersistenceValue::positiveInt($revisionNo, 'revision_no');
        $this->contentHash = HarnessPersistenceValue::hash($contentHash, 'content_hash');
        $this->sizeBytes = HarnessPersistenceValue::nonNegativeInt($sizeBytes, 'size_bytes');
        $this->mimeType = HarnessPersistenceValue::string($mimeType, 'mime_type', 1, 127);
        $this->encoding = HarnessPersistenceValue::identifier($encoding, 'encoding', 32);
        $this->storageKind = $storageKind;
        $this->sensitivity = $sensitivity;
        $this->supersedesArtifactId = HarnessPersistenceValue::optionalPositiveInt(
            $supersedesArtifactId,
            'supersedes_artifact_id'
        );
        $this->retentionUntil = $retentionUntil;

        HarnessSensitiveDataGuard::assertSafeStructure($metadata, 'artifact.metadata');
        $this->metadataJson = HarnessPersistenceValue::canonicalMysqlJson($metadata);
        HarnessSensitiveDataGuard::assertSafeText($this->metadataJson, 'artifact.metadata');

        $present = (int)($inlineText !== null) + (int)($inlineJson !== null) + (int)($storageUri !== null);
        if ($present !== 1) {
            throw new InvalidArgumentException('Artifact must have exactly one storage payload.');
        }
        if ($storageKind === GenerationArtifactStorageKind::INLINE
            && $inlineText === null
            && $inlineJson === null
            || $storageKind === GenerationArtifactStorageKind::EXTERNAL && $storageUri === null) {
            throw new InvalidArgumentException('Artifact storage kind does not match its payload.');
        }

        if ($inlineText !== null) {
            HarnessSensitiveDataGuard::assertSafeText($inlineText, 'artifact.inline_text');
            if (strlen($inlineText) !== $this->sizeBytes
                || !hash_equals(hash('sha256', $inlineText), $this->contentHash)) {
                throw new InvalidArgumentException('Inline text size/hash does not match its bytes.');
            }
        }
        if ($inlineJson !== null) {
            $decoded = HarnessPersistenceValue::decodeJsonArray($inlineJson, 'inline_json');
            HarnessSensitiveDataGuard::assertSafeStructure($decoded, 'artifact.inline_json');
            $canonical = HarnessPersistenceValue::canonicalMysqlJson($decoded);
            HarnessSensitiveDataGuard::assertSafeText($canonical, 'artifact.inline_json');
            if (strlen($canonical) !== $this->sizeBytes
                || !hash_equals(hash('sha256', $canonical), $this->contentHash)) {
                throw new InvalidArgumentException('Inline JSON size/hash does not match canonical bytes.');
            }
            $inlineJson = $canonical;
        }
        if ($storageUri !== null) {
            $storageUri = HarnessPersistenceValue::string($storageUri, 'storage_uri', 1, 1000);
            HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
        }

        $this->inlineText = $inlineText;
        $this->inlineJson = $inlineJson;
        $this->storageUri = $storageUri;
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        $artifact = GenerationArtifact::fromRow($row);
        $retentionUntil = $this->retentionUntil === null
            ? null
            : HarnessPersistenceValue::mysqlDate($this->retentionUntil);
        if ($artifact->artifactUid !== $this->artifactUid
            || $artifact->runId !== $this->runId
            || $artifact->stepId !== $this->stepId
            || $artifact->artifactType !== $this->artifactType
            || $artifact->logicalKey !== $this->logicalKey
            || $artifact->revisionNo !== $this->revisionNo
            || !hash_equals($artifact->contentHash, $this->contentHash)
            || $artifact->mimeType !== $this->mimeType
            || $artifact->encoding !== $this->encoding
            || $artifact->storageKind !== $this->storageKind
            || $artifact->inlineText !== $this->inlineText
            || $artifact->inlineJson !== $this->inlineJson
            || $artifact->storageUri !== $this->storageUri
            || $artifact->sizeBytes !== $this->sizeBytes
            || $artifact->sensitivity !== $this->sensitivity
            || $artifact->metadataJson !== $this->metadataJson
            || $artifact->supersedesArtifactId !== $this->supersedesArtifactId
            || $artifact->retentionUntil !== $retentionUntil) {
            throw new HarnessIdempotencyConflictException(
                'Artifact logical revision resolved to different immutable content.'
            );
        }
    }

}

final readonly class GenerationArtifact
{
    public function __construct(
        public int $id,
        public string $artifactUid,
        public int $runId,
        public ?int $stepId,
        public string $artifactType,
        public string $logicalKey,
        public int $revisionNo,
        public string $contentHash,
        public string $mimeType,
        public string $encoding,
        public GenerationArtifactStorageKind $storageKind,
        public ?string $inlineText,
        public ?string $inlineJson,
        public ?string $storageUri,
        public int $sizeBytes,
        public GenerationArtifactSensitivity $sensitivity,
        public string $metadataJson,
        public ?int $supersedesArtifactId,
        public ?string $retentionUntil,
        public string $createdAt
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'artifact_uid', 'run_id', 'step_id', 'artifact_type',
            'logical_key', 'revision_no', 'content_hash', 'mime_type', 'encoding',
            'storage_kind', 'inline_text', 'inline_json', 'storage_uri', 'size_bytes',
            'sensitivity', 'metadata_json', 'supersedes_artifact_id',
            'retention_until', 'created_at',
        ], 'generation_artifact');
        $kind = is_string($row['storage_kind'])
            ? GenerationArtifactStorageKind::tryFrom($row['storage_kind'])
            : null;
        $sensitivity = is_string($row['sensitivity'])
            ? GenerationArtifactSensitivity::tryFrom($row['sensitivity'])
            : null;
        if (!$kind instanceof GenerationArtifactStorageKind
            || !$sensitivity instanceof GenerationArtifactSensitivity) {
            throw new InvalidArgumentException('generation_artifact enum value is unknown.');
        }

        $metadata = HarnessPersistenceValue::decodeJsonArray($row['metadata_json'], 'metadata_json');
        $inlineJson = null;
        if ($row['inline_json'] !== null && $row['inline_json'] !== '') {
            $inlineJson = HarnessPersistenceValue::canonicalMysqlJson(
                HarnessPersistenceValue::decodeJsonArray($row['inline_json'], 'inline_json')
            );
        }
        if ($kind === GenerationArtifactStorageKind::INLINE
            && is_string($row['inline_text'])
            && $row['inline_json'] === null) {
            $draft = GenerationArtifactDraft::inlineText(
                HarnessPersistenceValue::uid($row['artifact_uid'], 'artifact_uid'),
                HarnessPersistenceValue::positiveInt($row['run_id'], 'run_id'),
                HarnessPersistenceValue::optionalPositiveInt($row['step_id'], 'step_id'),
                HarnessPersistenceValue::identifier($row['artifact_type'], 'artifact_type', 64),
                HarnessPersistenceValue::identifier($row['logical_key'], 'logical_key', 191),
                HarnessPersistenceValue::positiveInt($row['revision_no'], 'revision_no'),
                $row['inline_text'],
                HarnessPersistenceValue::string($row['mime_type'], 'mime_type', 1, 127),
                HarnessPersistenceValue::identifier($row['encoding'], 'encoding', 32),
                $sensitivity,
                $metadata,
                HarnessPersistenceValue::optionalPositiveInt($row['supersedes_artifact_id'], 'supersedes_artifact_id')
            );
        } elseif ($kind === GenerationArtifactStorageKind::INLINE
            && $row['inline_text'] === null
            && $inlineJson !== null) {
            $draft = GenerationArtifactDraft::inlineJson(
                HarnessPersistenceValue::uid($row['artifact_uid'], 'artifact_uid'),
                HarnessPersistenceValue::positiveInt($row['run_id'], 'run_id'),
                HarnessPersistenceValue::optionalPositiveInt($row['step_id'], 'step_id'),
                HarnessPersistenceValue::identifier($row['artifact_type'], 'artifact_type', 64),
                HarnessPersistenceValue::identifier($row['logical_key'], 'logical_key', 191),
                HarnessPersistenceValue::positiveInt($row['revision_no'], 'revision_no'),
                HarnessPersistenceValue::decodeJsonArray($inlineJson, 'inline_json'),
                $sensitivity,
                $metadata,
                HarnessPersistenceValue::optionalPositiveInt($row['supersedes_artifact_id'], 'supersedes_artifact_id')
            );
        } elseif ($kind === GenerationArtifactStorageKind::EXTERNAL
            && $row['inline_text'] === null
            && $row['inline_json'] === null) {
            $draft = GenerationArtifactDraft::external(
                HarnessPersistenceValue::uid($row['artifact_uid'], 'artifact_uid'),
                HarnessPersistenceValue::positiveInt($row['run_id'], 'run_id'),
                HarnessPersistenceValue::optionalPositiveInt($row['step_id'], 'step_id'),
                HarnessPersistenceValue::identifier($row['artifact_type'], 'artifact_type', 64),
                HarnessPersistenceValue::identifier($row['logical_key'], 'logical_key', 191),
                HarnessPersistenceValue::positiveInt($row['revision_no'], 'revision_no'),
                is_string($row['storage_uri']) ? $row['storage_uri'] : throw new InvalidArgumentException('storage_uri is required.'),
                HarnessPersistenceValue::hash($row['content_hash'], 'content_hash'),
                HarnessPersistenceValue::nonNegativeInt($row['size_bytes'], 'size_bytes'),
                HarnessPersistenceValue::string($row['mime_type'], 'mime_type', 1, 127),
                HarnessPersistenceValue::identifier($row['encoding'], 'encoding', 32),
                $sensitivity,
                $metadata,
                HarnessPersistenceValue::optionalPositiveInt($row['supersedes_artifact_id'], 'supersedes_artifact_id')
            );
        } else {
            throw new InvalidArgumentException('Artifact storage columns are not mutually exclusive.');
        }
        if (!hash_equals($draft->contentHash, HarnessPersistenceValue::hash($row['content_hash'], 'content_hash'))
            || $draft->sizeBytes !== HarnessPersistenceValue::nonNegativeInt($row['size_bytes'], 'size_bytes')
            || $draft->mimeType !== HarnessPersistenceValue::string($row['mime_type'], 'mime_type', 1, 127)
            || $draft->encoding !== HarnessPersistenceValue::identifier($row['encoding'], 'encoding', 32)) {
            throw new HarnessPersistenceException(
                'Artifact content hash, size, MIME type, or encoding does not match its stored payload.'
            );
        }

        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            $draft->artifactUid,
            $draft->runId,
            $draft->stepId,
            $draft->artifactType,
            $draft->logicalKey,
            $draft->revisionNo,
            $draft->contentHash,
            $draft->mimeType,
            $draft->encoding,
            $draft->storageKind,
            $draft->inlineText,
            $draft->inlineJson,
            $draft->storageUri,
            $draft->sizeBytes,
            $draft->sensitivity,
            $draft->metadataJson,
            $draft->supersedesArtifactId,
            HarnessPersistenceValue::optionalMysqlDate($row['retention_until'], 'retention_until'),
            HarnessPersistenceValue::optionalMysqlDate($row['created_at'], 'created_at')
                ?? throw new InvalidArgumentException('generation_artifact.created_at is required.')
        );
    }
}
