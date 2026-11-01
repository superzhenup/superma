<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ReviewCoordinator.php';
require_once __DIR__ . '/ReviewAggregator.php';

final readonly class EditedCandidate
{
    public function __construct(
        public string $content,
        public string $contentHash,
        public string $parentDraftHash
    ) {
        HarnessPersistenceValue::hash($contentHash, 'edited_candidate.content_hash');
        HarnessPersistenceValue::hash($parentDraftHash, 'edited_candidate.parent_hash');
        if (!hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException('Edited Candidate hash does not match content.');
        }
    }
}

/** This is the only component in Review iteration allowed to change a Candidate. */
interface SingleCandidateEditor
{
    public function edit(
        ReviewInput $input,
        AggregatedReview $review,
        int $round
    ): EditedCandidate;
}

final readonly class ReviewGateOutcome
{
    /** @var list<array<string,mixed>> */
    public array $hardFindings;

    /** @param list<array<string,mixed>> $hardFindings */
    public function __construct(
        public bool $passed,
        public int $score,
        array $hardFindings = []
    ) {
        HarnessPersistenceValue::boundedInt($score, 'review_gate.score', 0, 100);
        HarnessSensitiveDataGuard::assertSafeStructure($hardFindings, 'review_gate.findings');
        $this->hardFindings = $hardFindings;
    }
}

interface ReviewHardGate
{
    public function evaluate(string $content, string $contentHash): ReviewGateOutcome;
}

enum ReviewIterationDisposition: string
{
    case ACCEPTED = 'accepted';
    case QUARANTINED = 'quarantined';
    case WAITING_APPROVAL = 'waiting_approval';
}

final readonly class ReviewIterationResult
{
    /** @var list<array<string,mixed>> */
    public array $history;

    /** @param list<array<string,mixed>> $history */
    public function __construct(
        public ReviewIterationDisposition $disposition,
        public string $content,
        public string $contentHash,
        public int $rounds,
        array $history
    ) {
        $this->history = $history;
    }
}

final class ReviewIterationService
{
    public function __construct(
        private readonly ReviewCoordinator $coordinator,
        private readonly ReviewAggregator $aggregator,
        private readonly SingleCandidateEditor $editor,
        private readonly ReviewHardGate $hardGate,
        private readonly int $maxRounds = 2,
        private readonly int $minimumImprovement = 3
    ) {
        HarnessPersistenceValue::boundedInt($maxRounds, 'review_iteration.max_rounds', 1, 2);
        HarnessPersistenceValue::boundedInt(
            $minimumImprovement,
            'review_iteration.minimum_improvement',
            0,
            100
        );
    }

    public function improve(
        ReviewInput $initial,
        bool $approvalAvailable
    ): ReviewIterationResult {
        $current = $initial;
        $previousGate = $this->hardGate->evaluate($current->draft, $current->draftHash);
        $history = [[
            'round' => 0,
            'draft_hash' => $current->draftHash,
            'hard_gate_passed' => $previousGate->passed,
            'gate_score' => $previousGate->score,
        ]];

        // 审计修复 P1（2026-09-27）：追踪历史最优候选（含初始稿）。原实现
        // blocked() 固定返回"最后一轮编辑稿"——improvement 可为负（编辑比
        // 上轮更差）时，退化稿仍被 WAITING_APPROVAL 批准入正典；更优的中间
        // 轮被静默丢弃。隔离/待审批一律返回历史最优稿。
        $bestContent = $current->draft;
        $bestHash = $current->draftHash;
        $bestScore = $previousGate->score;
        $bestRound = 0;

        for ($round = 1; $round <= $this->maxRounds; $round++) {
            $reports = $this->coordinator->review($current);
            $aggregate = $this->aggregator->aggregate($reports);
            $edited = $this->editor->edit($current, $aggregate, $round);
            if (!hash_equals($edited->parentDraftHash, $current->draftHash)) {
                throw new RuntimeException('Single Editor edited a stale draft hash.');
            }
            $gate = $this->hardGate->evaluate($edited->content, $edited->contentHash);
            $improvement = $gate->score - $previousGate->score;
            $history[] = [
                'round' => $round,
                'input_hash' => $current->draftHash,
                'output_hash' => $edited->contentHash,
                'review_score' => $aggregate->score,
                'issue_count' => count($aggregate->issues),
                'hard_gate_passed' => $gate->passed,
                'gate_score' => $gate->score,
                'improvement' => $improvement,
            ];
            if ($gate->score > $bestScore) {
                $bestContent = $edited->content;
                $bestHash = $edited->contentHash;
                $bestScore = $gate->score;
                $bestRound = $round;
            }
            if ($gate->passed && $improvement >= $this->minimumImprovement) {
                return new ReviewIterationResult(
                    ReviewIterationDisposition::ACCEPTED,
                    $edited->content,
                    $edited->contentHash,
                    $round,
                    $history
                );
            }
            // 审计修复 P1（2026-09-27）：已通过硬门的编辑稿即为合格稿。
            // minimumImprovement 只用于决定"未过硬门时是否值得继续迭代"，
            // 不得把已过硬门的合格稿退回隔离/待审批（原实现 passed=1 且
            // 提升 +2 < 3 时仍 blocked，合格稿被误隔离）。
            if ($gate->passed) {
                return new ReviewIterationResult(
                    ReviewIterationDisposition::ACCEPTED,
                    $edited->content,
                    $edited->contentHash,
                    $round,
                    $history
                );
            }
            if ($improvement < $this->minimumImprovement) {
                return $this->blocked(
                    $bestContent,
                    $bestHash,
                    $bestRound,
                    $history,
                    $approvalAvailable
                );
            }
            $current = new ReviewInput(
                $edited->content,
                $edited->contentHash,
                $initial->contextManifest
            );
            $previousGate = $gate;
        }

        return $this->blocked(
            $bestContent,
            $bestHash,
            $bestRound,
            $history,
            $approvalAvailable
        );
    }

    /** @param list<array<string,mixed>> $history */
    private function blocked(
        string $content,
        string $contentHash,
        int $round,
        array $history,
        bool $approvalAvailable
    ): ReviewIterationResult {
        return new ReviewIterationResult(
            $approvalAvailable
                ? ReviewIterationDisposition::WAITING_APPROVAL
                : ReviewIterationDisposition::QUARANTINED,
            $content,
            $contentHash,
            $round,
            $history
        );
    }
}
