<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchContracts.php';

final readonly class BranchCandidate
{
    public function __construct(
        public string $candidateUid,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public string $branchUid,
        public string $baseRevisionHash,
        public string $outlineHash,
        public string $content,
        public string $contentHash
    ) {
        HarnessPersistenceValue::uid($candidateUid, 'tournament.candidate_uid');
        HarnessPersistenceValue::positiveInt($userId, 'tournament.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'tournament.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'tournament.chapter_id');
        HarnessPersistenceValue::uid($branchUid, 'tournament.branch_uid');
        HarnessPersistenceValue::hash($baseRevisionHash, 'tournament.base_revision_hash');
        HarnessPersistenceValue::hash($outlineHash, 'tournament.outline_hash');
        HarnessPersistenceValue::hash($contentHash, 'tournament.content_hash');
        if (!hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException('Candidate content hash is invalid.');
        }
    }
}

enum PairwiseWinner: string
{
    case A = 'a';
    case B = 'b';
    case TIE = 'tie';
}

final readonly class PairwiseReview
{
    public function __construct(
        public string $reviewer,
        public string $version,
        public string $candidateAUid,
        public string $candidateBUid,
        public PairwiseWinner $winner,
        public float $confidence,
        public string $evidence
    ) {
        HarnessPersistenceValue::identifier($reviewer, 'pairwise.reviewer', 64);
        HarnessPersistenceValue::identifier($version, 'pairwise.version', 32);
        HarnessPersistenceValue::uid($candidateAUid, 'pairwise.a_uid');
        HarnessPersistenceValue::uid($candidateBUid, 'pairwise.b_uid');
        if ($candidateAUid === $candidateBUid || !is_finite($confidence)
            || $confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Pairwise Review coordinates are invalid.');
        }
        HarnessPersistenceValue::string($evidence, 'pairwise.evidence', 1, 4000);
    }
}

interface PurePairwiseReviewer
{
    public function compare(BranchCandidate $a, BranchCandidate $b): PairwiseReview;
}

final readonly class CandidateTournamentResult
{
    /** @var list<PairwiseReview> */
    public array $reviews;
    /** @var array<string,int> */
    public array $points;

    /** @param list<PairwiseReview> $reviews @param array<string,int> $points */
    public function __construct(
        public BranchCandidate $winner,
        array $reviews,
        array $points
    ) {
        $this->reviews = $reviews;
        $this->points = $points;
    }
}

final class BranchCandidateTournament
{
    public function __construct(private readonly PurePairwiseReviewer $reviewer)
    {
    }

    /** @param list<BranchCandidate> $candidates */
    public function select(array $candidates): CandidateTournamentResult
    {
        if (count($candidates) < 2 || count($candidates) > 3) {
            throw new InvalidArgumentException('A Branch tournament requires 2 or 3 candidates.');
        }
        usort($candidates, static fn (BranchCandidate $a, BranchCandidate $b): int =>
            strcmp($a->candidateUid, $b->candidateUid)
        );
        $first = $candidates[0];
        $points = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof BranchCandidate
                || $candidate->userId !== $first->userId
                || $candidate->novelId !== $first->novelId
                || $candidate->chapterId !== $first->chapterId
                || $candidate->branchUid !== $first->branchUid
                || !hash_equals($candidate->baseRevisionHash, $first->baseRevisionHash)
                || !hash_equals($candidate->outlineHash, $first->outlineHash)) {
                throw new InvalidArgumentException(
                    'Tournament candidates must share immutable scope, base, and outline.'
                );
            }
            if (isset($points[$candidate->candidateUid])) {
                throw new InvalidArgumentException('Tournament candidate UID is duplicated.');
            }
            $points[$candidate->candidateUid] = 0;
        }

        $reviews = [];
        for ($i = 0; $i < count($candidates); $i++) {
            for ($j = $i + 1; $j < count($candidates); $j++) {
                $review = $this->reviewer->compare($candidates[$i], $candidates[$j]);
                if ($review->candidateAUid !== $candidates[$i]->candidateUid
                    || $review->candidateBUid !== $candidates[$j]->candidateUid) {
                    throw new RuntimeException('Pairwise Reviewer returned stale candidate coordinates.');
                }
                $reviews[] = $review;
                match ($review->winner) {
                    PairwiseWinner::A => $points[$review->candidateAUid] += 3,
                    PairwiseWinner::B => $points[$review->candidateBUid] += 3,
                    PairwiseWinner::TIE => [$points[$review->candidateAUid]++, $points[$review->candidateBUid]++],
                };
            }
        }
        uasort($points, static fn (int $a, int $b): int => $b <=> $a);
        $max = max($points);
        $winnerUid = min(array_keys(array_filter(
            $points,
            static fn (int $value): bool => $value === $max
        )));
        $winner = array_values(array_filter(
            $candidates,
            static fn (BranchCandidate $candidate): bool => $candidate->candidateUid === $winnerUid
        ))[0];
        ksort($points, SORT_STRING);
        return new CandidateTournamentResult($winner, $reviews, $points);
    }
}
