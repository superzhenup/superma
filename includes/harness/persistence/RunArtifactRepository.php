<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/GenerationArtifact.php';

final class RunArtifactRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function storeIdempotent(GenerationArtifactDraft $draft): GenerationArtifact
    {
        return $this->db->transactional(function () use ($draft): GenerationArtifact {
            $this->assertOwnedReferences($draft);

            $this->db->execute(
                "INSERT INTO generation_artifacts
                    (artifact_uid, run_id, step_id, artifact_type, logical_key,
                     revision_no, content_hash, mime_type, encoding, storage_kind,
                     inline_text, inline_json, storage_uri, size_bytes, sensitivity,
                     metadata_json, supersedes_artifact_id, retention_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id=id",
                [
                    $draft->artifactUid,
                    $draft->runId,
                    $draft->stepId,
                    $draft->artifactType,
                    $draft->logicalKey,
                    $draft->revisionNo,
                    $draft->contentHash,
                    $draft->mimeType,
                    $draft->encoding,
                    $draft->storageKind->value,
                    $draft->inlineText,
                    $draft->inlineJson,
                    $draft->storageUri,
                    $draft->sizeBytes,
                    $draft->sensitivity->value,
                    $draft->metadataJson,
                    $draft->supersedesArtifactId,
                    $draft->retentionUntil === null
                        ? null
                        : HarnessPersistenceValue::mysqlDate($draft->retentionUntil),
                ]
            );
            $row = $this->db->fetchOne(
                "SELECT * FROM generation_artifacts
                 WHERE run_id=? AND logical_key=? AND revision_no=? FOR UPDATE",
                [$draft->runId, $draft->logicalKey, $draft->revisionNo]
            );
            if ($row === null) {
                throw new HarnessIdempotencyConflictException(
                    'Artifact insert collided with another artifact_uid.'
                );
            }
            $draft->assertIdempotentRow($row);
            return GenerationArtifact::fromRow($row);
        });
    }

    public function findByUid(string $artifactUid): ?GenerationArtifact
    {
        $uid = HarnessPersistenceValue::uid($artifactUid, 'artifact_uid');
        $row = $this->db->fetchOne(
            'SELECT * FROM generation_artifacts WHERE artifact_uid=?',
            [$uid]
        );
        return $row === null ? null : GenerationArtifact::fromRow($row);
    }

    private function assertOwnedReferences(GenerationArtifactDraft $draft): void
    {
        $run = $this->db->fetchOne(
            'SELECT id FROM generation_runs WHERE id=? FOR UPDATE',
            [$draft->runId]
        );
        if ($run === null
            || HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'run_id')
                !== $draft->runId) {
            throw new HarnessPersistenceException('Artifact Run does not exist.');
        }

        if ($draft->stepId !== null) {
            $step = $this->db->fetchOne(
                'SELECT id, run_id FROM generation_steps WHERE id=? FOR UPDATE',
                [$draft->stepId]
            );
            if ($step === null
                || HarnessPersistenceValue::positiveInt($step['id'] ?? null, 'step_id')
                    !== $draft->stepId
                || HarnessPersistenceValue::positiveInt($step['run_id'] ?? null, 'step.run_id')
                    !== $draft->runId) {
                throw new HarnessPersistenceException(
                    'Artifact Step must exist and belong to the same Run.'
                );
            }
        }

        if ($draft->supersedesArtifactId !== null) {
            $superseded = $this->db->fetchOne(
                'SELECT id, run_id FROM generation_artifacts WHERE id=? FOR UPDATE',
                [$draft->supersedesArtifactId]
            );
            if ($superseded === null
                || HarnessPersistenceValue::positiveInt(
                    $superseded['id'] ?? null,
                    'supersedes_artifact_id'
                ) !== $draft->supersedesArtifactId
                || HarnessPersistenceValue::positiveInt(
                    $superseded['run_id'] ?? null,
                    'superseded.run_id'
                ) !== $draft->runId) {
                throw new HarnessPersistenceException(
                    'Superseded Artifact must belong to the same Run.'
                );
            }
        }
    }
}
