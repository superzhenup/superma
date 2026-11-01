<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/jobs/bootstrap.php';
require_once __DIR__ . '/OutlineEnhancementRunFactory.php';

/**
 * Additive M64 bridge. The Generation Run remains intact for rollback and
 * atomic outline CAS; the Job owns generic dispatch and lifecycle evidence.
 */
final class OutlineEnhancementJobMapper
{
    public const JOB_TYPE = 'outline.enhancement';
    public const LEGACY_TYPE = 'generation_run';
    public const ENVELOPE_RECOVERY_ATTEMPTS = 2;

    private ?HarnessPersistenceDriver $db;

    public function __construct(
        private readonly HarnessJobEnqueuer $jobs,
        PDO|HarnessPersistenceDriver|null $connection = null
    ) {
        $this->db = $connection === null
            ? null
            : HarnessPersistenceValue::driver($connection);
    }

    public function map(GenerationRun $run): HarnessJob
    {
        $this->assertOutlineRun($run);
        $goal = HarnessPersistenceValue::decodeJsonArray($run->goalJson, 'outline_job.goal');
        $emergencyEpoch = OutlineEmergencyEpoch::normalize($goal['emergency_epoch'] ?? null);
        $payload = [
            'schema' => 'harness.outline_job.v2',
            'run_uid' => $run->runUid,
            'run_id' => $run->id,
            'workflow' => $run->workflowName,
            'workflow_version' => $run->workflowVersion,
            'authority_mode' => $run->authorityMode,
            'input_hash' => $run->inputHash,
            'context_snapshot_hash' => $run->contextSnapshotHash,
            'emergency_epoch' => $emergencyEpoch,
        ];
        $availableAt = new DateTimeImmutable(
            $run->availableAt,
            new DateTimeZone('UTC')
        );
        return $this->jobs->enqueue(new HarnessJobDraft(
            substr(hash('sha256', 'outline-job:v1:' . $run->runUid), 0, 32),
            $run->id,
            null,
            OutlineEnhancementRunFactory::QUEUE,
            self::JOB_TYPE,
            $run->userId,
            $run->novelId,
            $run->chapterId,
            'outline-job:v1:' . $run->runUid,
            $payload,
            0,
            max(
                3,
                $run->maxAttempts + self::ENVELOPE_RECOVERY_ATTEMPTS
            ),
            $availableAt,
            self::LEGACY_TYPE,
            $run->id
        ));
    }

    /**
     * Lazily repairs pre-M64 outline Runs. It intentionally maps one row per
     * worker turn so deployment never performs an unbounded web migration.
     */
    public function backfillNext(): ?HarnessJob
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetchOne(
            "SELECT r.* FROM generation_runs r
             LEFT JOIN jobs j
               ON j.legacy_type='generation_run' AND j.legacy_id=r.id
             WHERE r.queue=? AND r.run_type=?
               AND r.workflow_name=? AND r.workflow_version=?
               AND r.state IN ('queued','retry_wait','running')
               AND j.id IS NULL
             ORDER BY r.id LIMIT 1",
            [
                OutlineEnhancementRunFactory::QUEUE,
                OutlineEnhancementRunFactory::RUN_TYPE,
                OutlineEnhancementRunFactory::WORKFLOW,
                OutlineEnhancementRunFactory::WORKFLOW_VERSION,
            ]
        );
        return $row === null ? null : $this->map(GenerationRun::fromRow($row));
    }

    private function assertOutlineRun(GenerationRun $run): void
    {
        if ($run->queue !== OutlineEnhancementRunFactory::QUEUE
            || $run->runType !== OutlineEnhancementRunFactory::RUN_TYPE
            || $run->workflowName !== OutlineEnhancementRunFactory::WORKFLOW
            || $run->workflowVersion !== OutlineEnhancementRunFactory::WORKFLOW_VERSION) {
            throw new InvalidArgumentException(
                'Only an outline enhancement Run can map to an outline Job.'
            );
        }
    }
}
