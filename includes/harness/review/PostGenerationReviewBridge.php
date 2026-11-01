<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostGenerationReviewContracts.php';

/**
 * Production seam between raw model generation and downstream validation.
 *
 * The frozen parallel_review flag is the sole admission decision. When off,
 * the bridge performs no Reviewer, Editor, gate, or persistence work. When on,
 * every mutation comes from the one ProvenancedCandidateEditor; PureReviewers
 * only emit immutable reports. Enabled failures throw and never degrade into a
 * raw-draft success.
 */
final class PostGenerationReviewBridge
{
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly ReviewCoordinator $coordinator,
        private readonly ReviewAggregator $aggregator,
        private readonly ProvenancedCandidateEditor $editor,
        private readonly ProvenancedReviewHardGate $hardGate,
        private readonly PostGenerationReviewEvidenceStore $evidenceStore,
        private readonly int $maxRounds = 2,
        private readonly int $minimumImprovement = 3
    ) {
        HarnessPersistenceValue::boundedInt(
            $maxRounds,
            'post_generation_review.max_rounds',
            1,
            2
        );
        HarnessPersistenceValue::boundedInt(
            $minimumImprovement,
            'post_generation_review.minimum_improvement',
            0,
            100
        );
    }

    public function review(
        string $rawDraft,
        HarnessFeatureSnapshot $featureSnapshot,
        ReviewStableContext $stableContext,
        bool $approvalAvailable = false
    ): PostGenerationReviewResult {
        if ($rawDraft === '') {
            throw new InvalidArgumentException(
                'Post-generation Review cannot process an empty raw Draft.'
            );
        }

        $editorCoordinate = $this->editor->name() . '@' . $this->editor->version();
        $gateCoordinate = $this->hardGate->name() . '@' . $this->hardGate->version();

        if (!$featureSnapshot->parallelReview) {
            // Deliberately no durable write: frozen-off behavior is transparent.
            return new PostGenerationReviewResult(
                PostGenerationReviewDisposition::BYPASSED,
                $rawDraft,
                $rawDraft,
                [],
                $featureSnapshot->checksum,
                $stableContext->manifestHash,
                $editorCoordinate,
                $gateCoordinate,
                self::VERSION,
                'parallel_review_flag_disabled'
            );
        }

        $current = new ReviewInput(
            $rawDraft,
            hash('sha256', $rawDraft),
            $stableContext->manifest
        );
        $previousGate = $this->phase(
            'initial_hard_gate',
            fn (): ReviewGateOutcome =>
                $this->hardGate->evaluate($current->draft, $current->draftHash)
        );
        $rounds = [];

        for ($round = 1; $round <= $this->maxRounds; $round++) {
            $reports = $this->phase(
                "reviewers_round_{$round}",
                fn (): array => $this->coordinator->review($current)
            );
            $execution = $this->coordinator->lastExecutionTrace();
            if (!$execution instanceof ReviewExecutionTrace) {
                throw new PostGenerationReviewFailureException(
                    "reviewers_round_{$round}",
                    new RuntimeException(
                        'Review executor omitted required execution provenance.'
                    )
                );
            }
            $aggregate = $this->phase(
                "aggregate_round_{$round}",
                fn (): AggregatedReview => $this->aggregator->aggregate($reports)
            );
            $edited = $this->phase(
                "editor_round_{$round}",
                fn (): EditedCandidate => $this->editor->edit($current, $aggregate, $round)
            );
            if ($edited->content === '') {
                throw new PostGenerationReviewFailureException(
                    "editor_round_{$round}",
                    new RuntimeException('Editor returned an empty Candidate.')
                );
            }
            if (!hash_equals($edited->parentDraftHash, $current->draftHash)) {
                throw new PostGenerationReviewFailureException(
                    "editor_round_{$round}",
                    new RuntimeException('Editor returned a stale Candidate.')
                );
            }
            $candidateGate = $this->phase(
                "candidate_hard_gate_round_{$round}",
                fn (): ReviewGateOutcome =>
                    $this->hardGate->evaluate($edited->content, $edited->contentHash)
            );
            $improvement = $candidateGate->score - $previousGate->score;
            // A failing baseline must improve by the configured margin. A
            // baseline that already passed may remain unchanged, but may not
            // regress and still be auto-accepted.
            $acceptanceImprovement = $previousGate->passed
                ? 0
                : $this->minimumImprovement;
            $accepted = $candidateGate->passed
                && $improvement >= $acceptanceImprovement;
            $terminalWithoutAcceptance = $improvement < $this->minimumImprovement
                || $round === $this->maxRounds;

            if ($accepted) {
                $disposition = PostGenerationReviewDisposition::ACCEPTED;
                $roundDecision = 'accepted';
            } elseif ($terminalWithoutAcceptance) {
                $disposition = $approvalAvailable
                    ? PostGenerationReviewDisposition::WAITING_APPROVAL
                    : PostGenerationReviewDisposition::QUARANTINED;
                $roundDecision = $disposition->value;
            } else {
                $disposition = null;
                $roundDecision = 'continue';
            }

            $rounds[] = new PostGenerationReviewRoundEvidence(
                $round,
                $current->draftHash,
                $reports,
                $aggregate,
                $edited,
                $previousGate,
                $candidateGate,
                $improvement,
                $execution,
                $roundDecision
            );

            if ($disposition instanceof PostGenerationReviewDisposition) {
                return $this->complete(
                    $disposition,
                    $rawDraft,
                    $edited->content,
                    $rounds,
                    $featureSnapshot,
                    $stableContext,
                    $editorCoordinate,
                    $gateCoordinate
                );
            }

            $current = new ReviewInput(
                $edited->content,
                $edited->contentHash,
                $stableContext->manifest
            );
            $previousGate = $candidateGate;
        }

        // Constructor validation makes this unreachable. Keep the path
        // fail-closed if the loop is ever refactored incorrectly.
        throw new PostGenerationReviewFailureException(
            'iteration_policy',
            new LogicException('Review iteration exited without a disposition.')
        );
    }

    /**
     * @param list<PostGenerationReviewRoundEvidence> $rounds
     */
    private function complete(
        PostGenerationReviewDisposition $disposition,
        string $rawDraft,
        string $content,
        array $rounds,
        HarnessFeatureSnapshot $featureSnapshot,
        ReviewStableContext $stableContext,
        string $editorCoordinate,
        string $gateCoordinate
    ): PostGenerationReviewResult {
        $result = $this->phase(
            'result_assembly',
            fn (): PostGenerationReviewResult => new PostGenerationReviewResult(
                $disposition,
                $rawDraft,
                $content,
                $rounds,
                $featureSnapshot->checksum,
                $stableContext->manifestHash,
                $editorCoordinate,
                $gateCoordinate,
                self::VERSION
            )
        );
        $this->phase(
            'evidence_persistence',
            function () use ($result): bool {
                $this->evidenceStore->storeIdempotent($result);
                return true;
            }
        );
        return $result;
    }

    private function phase(string $phase, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PostGenerationReviewFailureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PostGenerationReviewFailureException($phase, $exception);
        }
    }
}

