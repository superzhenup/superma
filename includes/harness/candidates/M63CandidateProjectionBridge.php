<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/GenerationArtifact.php';
require_once dirname(__DIR__) . '/persistence/RunArtifactRepository.php';
require_once dirname(__DIR__) . '/support/HarnessRuntime.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/quality/QualityGateDecision.php';

/**
 * Add M63 Artifact coordinates to the pre-M63 Candidate projection without
 * changing the proven Candidate -> Canon transaction state machine.
 */
final class M63CandidateProjectionBridge
{
    private RunArtifactRepository $artifacts;

    public function __construct(?RunArtifactRepository $artifacts = null)
    {
        $this->artifacts = $artifacts ?? new RunArtifactRepository(DB::getPdo());
    }

    public function bindDraft(
        int $runId,
        string $runUid,
        int $revisionNo,
        int $userId,
        int $novelId,
        int $chapterId,
        string $candidateUid,
        string $content
    ): GenerationArtifact {
        $artifact = $this->artifacts->storeIdempotent(GenerationArtifactDraft::inlineText(
            self::artifactUid($runUid, 'candidate.draft.full', $revisionNo, hash('sha256', $content)),
            $runId,
            null,
            'draft.full',
            'candidate.draft.full',
            $revisionNo,
            $content,
            'text/plain',
            'utf-8',
            GenerationArtifactSensitivity::RESTRICTED,
            ['schema' => 'candidate.provenance.v1', 'boundary' => 'pre_canon']
        ));

        DB::execute(
            'UPDATE chapter_candidates SET run_id=?, draft_artifact_id=?, updated_at=UTC_TIMESTAMP(6) '
            . 'WHERE candidate_uid=? AND user_id=? AND novel_id=? AND chapter_id=? '
            . 'AND source_run_uid=? AND content_hash=? AND lifecycle="draft" '
            . 'AND (run_id IS NULL OR run_id=?) '
            . 'AND (draft_artifact_id IS NULL OR draft_artifact_id=?)',
            [
                $runId,
                $artifact->id,
                $candidateUid,
                $userId,
                $novelId,
                $chapterId,
                $runUid,
                hash('sha256', $content),
                $runId,
                $artifact->id,
            ]
        );
        $this->assertBound($runId, $userId, $novelId, $chapterId, $candidateUid, $artifact, false);
        return $artifact;
    }

    public function bindValidation(
        int $runId,
        string $runUid,
        int $revisionNo,
        int $userId,
        int $novelId,
        int $chapterId,
        string $candidateUid,
        QualityGateDecision $decision
    ): GenerationArtifact {
        $payload = $decision->toArray();
        $payloadJson = HarnessPersistenceValue::canonicalMysqlJson($payload);
        $artifact = $this->artifacts->storeIdempotent(GenerationArtifactDraft::inlineJson(
            self::artifactUid(
                $runUid,
                'candidate.quality.decision',
                $revisionNo,
                hash('sha256', $payloadJson)
            ),
            $runId,
            null,
            'quality.decision',
            'candidate.quality.decision',
            $revisionNo,
            $payload,
            GenerationArtifactSensitivity::INTERNAL,
            ['schema' => 'candidate.provenance.v1', 'boundary' => 'quality_gate']
        ));

        DB::execute(
            'UPDATE chapter_candidates SET validation_artifact_id=?, updated_at=UTC_TIMESTAMP(6) '
            . 'WHERE candidate_uid=? AND user_id=? AND novel_id=? AND chapter_id=? '
            . 'AND run_id=? AND content_hash=? AND lifecycle="draft" AND gate_state="pending" '
            . 'AND (validation_artifact_id IS NULL OR validation_artifact_id=?)',
            [
                $artifact->id,
                $candidateUid,
                $userId,
                $novelId,
                $chapterId,
                $runId,
                $decision->contentHash(),
                $artifact->id,
            ]
        );
        $this->assertBound($runId, $userId, $novelId, $chapterId, $candidateUid, $artifact, true);
        return $artifact;
    }

    private function assertBound(
        int $runId,
        int $userId,
        int $novelId,
        int $chapterId,
        string $candidateUid,
        GenerationArtifact $artifact,
        bool $validation
    ): void {
        $column = $validation ? 'validation_artifact_id' : 'draft_artifact_id';
        $row = DB::fetch(
            "SELECT cc.id, cc.run_id, cc.{$column} AS artifact_id,
                    r.user_id AS run_user_id, r.novel_id AS run_novel_id,
                    r.chapter_id AS run_chapter_id,
                    ga.run_id AS artifact_run_id, ga.artifact_uid,
                    ga.artifact_type, ga.content_hash
             FROM chapter_candidates cc
             INNER JOIN generation_runs r ON r.id=cc.run_id
             INNER JOIN generation_artifacts ga ON ga.id=cc.{$column}
             WHERE cc.candidate_uid=? AND cc.user_id=? AND cc.novel_id=?
               AND cc.chapter_id=? LIMIT 1",
            [$candidateUid, $userId, $novelId, $chapterId]
        );
        if (!is_array($row)
            || (int)$row['run_id'] !== $runId
            || (int)$row['run_user_id'] !== $userId
            || (int)$row['run_novel_id'] !== $novelId
            || (int)$row['run_chapter_id'] !== $chapterId
            || (int)$row['artifact_id'] !== $artifact->id
            || (int)$row['artifact_run_id'] !== $runId
            || !hash_equals((string)$row['artifact_uid'], $artifact->artifactUid)
            || !hash_equals((string)$row['artifact_type'], $artifact->artifactType)
            || !hash_equals((string)$row['content_hash'], $artifact->contentHash)) {
            throw new HarnessPersistenceException(
                'M63 Candidate Artifact binding did not preserve Run ownership.'
            );
        }
    }

    private static function artifactUid(
        string $runUid,
        string $logicalKey,
        int $revisionNo,
        string $contentHash
    ): string {
        HarnessPersistenceValue::uid($runUid, 'run_uid');
        HarnessPersistenceValue::positiveInt($revisionNo, 'revision_no');
        return substr(hash('sha256', implode("\0", [
            'candidate-artifact-v1',
            $runUid,
            $logicalKey,
            (string)$revisionNo,
            $contentHash,
        ])), 0, 32);
    }
}
