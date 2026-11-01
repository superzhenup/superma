<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/HarnessFeatureSnapshot.php';
require_once __DIR__ . '/ReviewCoordinator.php';
require_once __DIR__ . '/ReviewAggregator.php';
require_once __DIR__ . '/ReviewIterationService.php';

/**
 * Content-addressed context captured before generation. Reviewers and the
 * Editor receive this same immutable value on every iteration.
 */
final readonly class ReviewStableContext
{
    /** @var array<string,mixed> */
    public array $manifest;
    public string $manifestHash;

    /** @param array<string,mixed> $manifest */
    public function __construct(array $manifest)
    {
        HarnessSensitiveDataGuard::assertSafeStructure(
            $manifest,
            'post_generation_review.stable_context'
        );
        $this->manifest = $manifest;
        $this->manifestHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson([
                'schema' => 'harness.review.stable_context.v1',
                'manifest' => $manifest,
            ])
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 'harness.review.stable_context.v1',
            'manifest_hash' => $this->manifestHash,
            'manifest' => $this->manifest,
        ];
    }
}

/** The sole mutation authority in the post-generation Review pipeline. */
interface ProvenancedCandidateEditor extends SingleCandidateEditor
{
    public function name(): string;
    public function version(): string;
}

final class CallableProvenancedCandidateEditor implements ProvenancedCandidateEditor
{
    private Closure $editor;

    /** @param callable(ReviewInput,AggregatedReview,int):EditedCandidate $editor */
    public function __construct(
        private readonly string $editorName,
        private readonly string $editorVersion,
        callable $editor
    ) {
        HarnessPersistenceValue::identifier($editorName, 'review_editor.name', 64);
        HarnessPersistenceValue::identifier($editorVersion, 'review_editor.version', 32);
        $this->editor = Closure::fromCallable($editor);
    }

    public function name(): string { return $this->editorName; }
    public function version(): string { return $this->editorVersion; }

    public function edit(
        ReviewInput $input,
        AggregatedReview $review,
        int $round
    ): EditedCandidate {
        $candidate = ($this->editor)($input, $review, $round);
        if (!$candidate instanceof EditedCandidate
            || !hash_equals($candidate->parentDraftHash, $input->draftHash)) {
            throw new RuntimeException(
                'Provenanced Editor returned a Candidate for stale coordinates.'
            );
        }
        return $candidate;
    }
}

/** Versioned deterministic hard-gate identity included in Review provenance. */
interface ProvenancedReviewHardGate extends ReviewHardGate
{
    public function name(): string;
    public function version(): string;
}

final class CallableProvenancedReviewHardGate implements ProvenancedReviewHardGate
{
    private Closure $gate;

    /** @param callable(string,string):ReviewGateOutcome $gate */
    public function __construct(
        private readonly string $gateName,
        private readonly string $gateVersion,
        callable $gate
    ) {
        HarnessPersistenceValue::identifier($gateName, 'review_hard_gate.name', 64);
        HarnessPersistenceValue::identifier($gateVersion, 'review_hard_gate.version', 32);
        $this->gate = Closure::fromCallable($gate);
    }

    public function name(): string { return $this->gateName; }
    public function version(): string { return $this->gateVersion; }

    public function evaluate(string $content, string $contentHash): ReviewGateOutcome
    {
        if (!hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException(
                'Review hard gate received mismatched Candidate bytes.'
            );
        }
        $outcome = ($this->gate)($content, $contentHash);
        if (!$outcome instanceof ReviewGateOutcome) {
            throw new RuntimeException('Review hard gate returned no typed outcome.');
        }
        return $outcome;
    }
}

enum PostGenerationReviewDisposition: string
{
    /** Feature was frozen off; the raw Draft is returned without side effects. */
    case BYPASSED = 'bypassed';
    case ACCEPTED = 'accepted';
    case WAITING_APPROVAL = 'waiting_approval';
    case QUARANTINED = 'quarantined';
}

/** Complete immutable evidence for one Reviewer -> Editor -> gate round. */
final readonly class PostGenerationReviewRoundEvidence
{
    /** @var list<ReviewReport> */
    public array $reports;

    /** @param list<ReviewReport> $reports */
    public function __construct(
        public int $round,
        public string $inputHash,
        array $reports,
        public AggregatedReview $aggregate,
        public EditedCandidate $editedCandidate,
        public ReviewGateOutcome $previousGate,
        public ReviewGateOutcome $candidateGate,
        public int $improvement,
        public ReviewExecutionTrace $execution,
        public string $roundDecision
    ) {
        HarnessPersistenceValue::boundedInt($round, 'review_round.round', 1, 2);
        HarnessPersistenceValue::hash($inputHash, 'review_round.input_hash');
        if ($reports === []) {
            throw new InvalidArgumentException('Review round requires Reviewer reports.');
        }
        $uids = [];
        foreach ($reports as $report) {
            if (!$report instanceof ReviewReport
                || !hash_equals($report->draftHash, $inputHash)) {
                throw new InvalidArgumentException(
                    'Review round reports must bind to its immutable input.'
                );
            }
            if (isset($uids[$report->reportUid])) {
                throw new InvalidArgumentException('Review round contains duplicate reports.');
            }
            $uids[$report->reportUid] = true;
        }
        if (!hash_equals($aggregate->draftHash, $inputHash)
            || !hash_equals($editedCandidate->parentDraftHash, $inputHash)) {
            throw new InvalidArgumentException(
                'Review aggregate and Edited Candidate must share the round input.'
            );
        }
        $aggregateUids = $aggregate->reportUids;
        $reportUids = array_keys($uids);
        sort($aggregateUids, SORT_STRING);
        sort($reportUids, SORT_STRING);
        if ($aggregateUids !== $reportUids) {
            throw new InvalidArgumentException(
                'Review aggregate must bind the exact Reviewer report set.'
            );
        }
        if ($execution->reviewerCount !== count($reports)) {
            throw new InvalidArgumentException(
                'Review execution provenance has the wrong Reviewer count.'
            );
        }
        if ($improvement !== $candidateGate->score - $previousGate->score) {
            throw new InvalidArgumentException('Review improvement evidence is inconsistent.');
        }
        if (!in_array($roundDecision, [
            'accepted', 'continue', 'waiting_approval', 'quarantined',
        ], true)) {
            throw new InvalidArgumentException('Review round decision is invalid.');
        }
        $this->reports = array_values($reports);
    }

    /** Restricted payload: includes Reviewer evidence and suggestions. */
    public function toArray(): array
    {
        return $this->provenanceArray() + [
            'reports' => array_map(
                static fn (ReviewReport $report): array => $report->toArray(),
                $this->reports
            ),
            'issues' => array_map(
                static fn (ReviewIssue $issue): array => $issue->toArray(),
                $this->aggregate->issues
            ),
            'previous_gate_hard_findings' => $this->previousGate->hardFindings,
            'candidate_gate_hard_findings' => $this->candidateGate->hardFindings,
        ];
    }

    /** Internal payload: hashes and identities only, no prose evidence. */
    public function provenanceArray(): array
    {
        return [
            'round' => $this->round,
            'input_hash' => $this->inputHash,
            'output_hash' => $this->editedCandidate->contentHash,
            'report_uids' => $this->aggregate->reportUids,
            'aggregate_score' => $this->aggregate->score,
            'issue_count' => count($this->aggregate->issues),
            'previous_gate' => [
                'passed' => $this->previousGate->passed,
                'score' => $this->previousGate->score,
                'hard_finding_count' => count($this->previousGate->hardFindings),
                'hard_findings_hash' => hash(
                    'sha256',
                    HarnessPersistenceValue::canonicalJson(
                        $this->previousGate->hardFindings
                    )
                ),
            ],
            'candidate_gate' => [
                'passed' => $this->candidateGate->passed,
                'score' => $this->candidateGate->score,
                'hard_finding_count' => count($this->candidateGate->hardFindings),
                'hard_findings_hash' => hash(
                    'sha256',
                    HarnessPersistenceValue::canonicalJson(
                        $this->candidateGate->hardFindings
                    )
                ),
            ],
            'improvement' => $this->improvement,
            'execution' => $this->execution->toArray(),
            'decision' => $this->roundDecision,
        ];
    }
}

/** Durable, content-addressed output returned only after enabled evidence stores. */
final readonly class PostGenerationReviewResult
{
    /** @var list<PostGenerationReviewRoundEvidence> */
    public array $rounds;
    public string $rawDraftHash;
    public string $contentHash;
    public string $resultUid;

    /** @param list<PostGenerationReviewRoundEvidence> $rounds */
    public function __construct(
        public PostGenerationReviewDisposition $disposition,
        public string $rawDraft,
        public string $content,
        array $rounds,
        public string $featureSnapshotChecksum,
        public string $stableContextHash,
        public string $editorCoordinate,
        public string $hardGateCoordinate,
        public string $bridgeVersion,
        public ?string $bypassReason = null
    ) {
        if ($rawDraft === '') {
            throw new InvalidArgumentException('Post-generation Review requires a raw Draft.');
        }
        HarnessPersistenceValue::hash(
            $featureSnapshotChecksum,
            'post_generation_review.feature_snapshot_checksum'
        );
        HarnessPersistenceValue::hash(
            $stableContextHash,
            'post_generation_review.stable_context_hash'
        );
        HarnessPersistenceValue::identifier(
            $editorCoordinate,
            'post_generation_review.editor_coordinate',
            100
        );
        HarnessPersistenceValue::identifier(
            $hardGateCoordinate,
            'post_generation_review.hard_gate_coordinate',
            100
        );
        HarnessPersistenceValue::identifier(
            $bridgeVersion,
            'post_generation_review.bridge_version',
            32
        );
        foreach ($rounds as $index => $round) {
            if (!$round instanceof PostGenerationReviewRoundEvidence
                || $round->round !== $index + 1) {
                throw new InvalidArgumentException(
                    'Post-generation Review rounds must be typed and contiguous.'
                );
            }
        }
        if (count($rounds) > 2) {
            throw new InvalidArgumentException('Post-generation Review exceeds two rounds.');
        }
        if ($disposition === PostGenerationReviewDisposition::BYPASSED) {
            if ($rounds !== [] || $bypassReason !== 'parallel_review_flag_disabled'
                || !hash_equals($rawDraft, $content)) {
                throw new InvalidArgumentException(
                    'Bypassed Review must transparently return the raw Draft.'
                );
            }
        } elseif ($rounds === [] || $bypassReason !== null) {
            throw new InvalidArgumentException(
                'Enabled Review must include round evidence and no bypass reason.'
            );
        }
        $this->rounds = array_values($rounds);
        $this->rawDraftHash = hash('sha256', $rawDraft);
        $this->contentHash = hash('sha256', $content);
        $this->resultUid = substr(hash(
            'sha256',
            'post-generation-review-result-v1' . "\0"
                . HarnessPersistenceValue::canonicalJson($this->provenancePayload(false))
        ), 0, 32);
    }

    public function commitAllowed(): bool
    {
        return in_array($this->disposition, [
            PostGenerationReviewDisposition::BYPASSED,
            PostGenerationReviewDisposition::ACCEPTED,
        ], true);
    }

    /** Restricted Artifact payload containing all immutable Reviewer reports. */
    public function reportsArtifact(): array
    {
        return [
            'schema' => 'harness.review.reports.v1',
            'result_uid' => $this->resultUid,
            'raw_draft_hash' => $this->rawDraftHash,
            'rounds' => array_map(
                static fn (PostGenerationReviewRoundEvidence $round): array => $round->toArray(),
                $this->rounds
            ),
        ];
    }

    /** Internal Artifact payload; callers must refuse commit when false. */
    public function decisionArtifact(): array
    {
        return [
            'schema' => 'harness.review.decision.v1',
            'result_uid' => $this->resultUid,
            'disposition' => $this->disposition->value,
            'commit_allowed' => $this->commitAllowed(),
            'raw_draft_hash' => $this->rawDraftHash,
            'selected_draft_hash' => $this->contentHash,
            'round_count' => count($this->rounds),
            'bypass_reason' => $this->bypassReason,
        ];
    }

    /** Restricted Artifact payload for the only Candidate eligible downstream. */
    public function editedDraftArtifact(): array
    {
        return [
            'schema' => 'harness.review.edited_draft.v1',
            'result_uid' => $this->resultUid,
            'content' => $this->content,
            'content_hash' => $this->contentHash,
            'raw_draft_hash' => $this->rawDraftHash,
            'changed' => !hash_equals($this->rawDraftHash, $this->contentHash),
            'editor_coordinate' => $this->editorCoordinate,
        ];
    }

    /** Internal Artifact payload with no Draft or Reviewer prose. */
    public function provenanceArtifact(): array
    {
        return $this->provenancePayload(true);
    }

    /** @return array<string,array<string,mixed>> */
    public function artifactBundle(): array
    {
        return [
            'reviewer_reports' => $this->reportsArtifact(),
            'decision' => $this->decisionArtifact(),
            'edited_draft' => $this->editedDraftArtifact(),
            'provenance' => $this->provenanceArtifact(),
        ];
    }

    /** @return array<string,mixed> */
    private function provenancePayload(bool $includeResultUid): array
    {
        $payload = [
            'schema' => 'harness.review.provenance.v1',
            'bridge_version' => $this->bridgeVersion,
            'feature_snapshot_checksum' => $this->featureSnapshotChecksum,
            'stable_context_hash' => $this->stableContextHash,
            'raw_draft_hash' => hash('sha256', $this->rawDraft),
            'selected_draft_hash' => hash('sha256', $this->content),
            'disposition' => $this->disposition->value,
            'bypass_reason' => $this->bypassReason,
            'editor_coordinate' => $this->editorCoordinate,
            'hard_gate_coordinate' => $this->hardGateCoordinate,
            'rounds' => array_map(
                static fn (PostGenerationReviewRoundEvidence $round): array =>
                    $round->provenanceArray(),
                $this->rounds
            ),
        ];
        return $includeResultUid ? ['result_uid' => $this->resultUid] + $payload : $payload;
    }
}

interface PostGenerationReviewEvidenceStore
{
    /** Must be idempotent by PostGenerationReviewResult::resultUid. */
    public function storeIdempotent(PostGenerationReviewResult $result): void;
}

final class InMemoryPostGenerationReviewEvidenceStore implements
    PostGenerationReviewEvidenceStore
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $stored = [];

    public function storeIdempotent(PostGenerationReviewResult $result): void
    {
        $payload = $result->artifactBundle();
        $existing = $this->stored[$result->resultUid] ?? null;
        if ($existing !== null && $existing !== $payload) {
            throw new HarnessIdempotencyConflictException(
                'Review result UID resolved to different evidence.'
            );
        }
        $this->stored[$result->resultUid] = $payload;
    }

    /** @return array<string,array<string,mixed>>|null */
    public function find(string $resultUid): ?array
    {
        return $this->stored[$resultUid] ?? null;
    }
}

/** Adapter for a Run-owned durable Artifact transaction/sink. */
final class CallablePostGenerationReviewEvidenceStore implements
    PostGenerationReviewEvidenceStore
{
    private Closure $sink;

    /** @param callable(PostGenerationReviewResult):void $sink */
    public function __construct(callable $sink)
    {
        $this->sink = Closure::fromCallable($sink);
    }

    public function storeIdempotent(PostGenerationReviewResult $result): void
    {
        ($this->sink)($result);
    }
}

/** Enabled Review failures never return a success-shaped result. */
final class PostGenerationReviewFailureException extends RuntimeException
{
    public function __construct(
        public readonly string $phase,
        Throwable $previous
    ) {
        HarnessPersistenceValue::identifier(
            $phase,
            'post_generation_review.failure_phase',
            64
        );
        parent::__construct(
            "Post-generation Review failed during {$phase}.",
            0,
            $previous
        );
    }
}
