<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ReviewContracts.php';
require_once dirname(__DIR__) . '/persistence/GenerationArtifact.php';

/** Stores immutable ReviewReports through the consumer-owned Artifact sink. */
final class ArtifactReviewReportStore implements ReviewReportStore
{
    private Closure $artifactSink;

    /**
     * @param callable(GenerationArtifactDraft):mixed $artifactSink
     */
    public function __construct(
        callable $artifactSink,
        private readonly int $runId,
        private readonly ?int $stepId,
        private readonly ?DateTimeImmutable $retentionUntil = null
    ) {
        HarnessPersistenceValue::positiveInt($runId, 'review_store.run_id');
        HarnessPersistenceValue::optionalPositiveInt($stepId, 'review_store.step_id');
        $this->artifactSink = Closure::fromCallable($artifactSink);
    }

    public function storeIdempotent(ReviewReport $report): ReviewReport
    {
        $coordinate = implode(':', [
            'review',
            $report->reviewer,
            $report->version,
            $report->draftHash,
        ]);
        $uid = substr(hash('sha256', 'review-artifact-v1' . "\0" . $coordinate), 0, 32);
        $draft = GenerationArtifactDraft::inlineJson(
            $uid,
            $this->runId,
            $this->stepId,
            'review.report',
            $coordinate,
            1,
            $report->toArray(),
            GenerationArtifactSensitivity::RESTRICTED,
            [
                'schema_version' => 1,
                'draft_hash' => $report->draftHash,
                'reviewer' => $report->reviewer,
                'reviewer_version' => $report->version,
                'report_uid' => $report->reportUid,
            ],
            null,
            $this->retentionUntil
        );
        ($this->artifactSink)($draft);
        return $report;
    }
}
