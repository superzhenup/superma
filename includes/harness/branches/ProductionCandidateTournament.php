<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/CandidateTournament.php';

/**
 * Narrow Artifact boundary used by the production tournament and its unit
 * tests.  A candidate body and its model provenance are intentionally stored
 * in one JSON Artifact so a worker cannot leave a half-persisted candidate.
 */
interface BranchTournamentArtifactGateway
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function storeJson(
        RunContext $context,
        string $logicalKey,
        string $artifactType,
        array $payload,
        GenerationArtifactSensitivity $sensitivity
    ): array;

    /** @return array{reference:array<string,mixed>,payload:array<string,mixed>}|null */
    public function findJson(
        RunContext $context,
        string $logicalKey,
        string $artifactType
    ): ?array;
}

/**
 * Immutable coordinates shared by both generation calls.
 *
 * The branch UID is deliberately Run-local: this tournament never creates a
 * story_branches row, advances a Branch head, or opens a writable Memory
 * namespace.  Both candidates receive the exact same already-frozen Prompt.
 */
final readonly class ProductionBranchTournamentScope
{
    public const SCHEMA = 'harness.branch_tournament.scope.v1';
    public const SELECTOR = 'deterministic_chapter_pairwise';
    public const SELECTOR_VERSION = '1.0.0';

    public string $branchUid;
    public string $baseCoordinateKind;
    public string $scopeHash;

    public function __construct(
        public string $runUid,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public string $baseCoordinateHash,
        public ?string $canonRevisionHash,
        public string $outlineHash,
        public string $promptHash,
        public string $featureSnapshotChecksum
    ) {
        HarnessPersistenceValue::uid($runUid, 'branch_tournament.run_uid');
        HarnessPersistenceValue::positiveInt($userId, 'branch_tournament.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'branch_tournament.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'branch_tournament.chapter_id');
        HarnessPersistenceValue::hash(
            $baseCoordinateHash,
            'branch_tournament.base_coordinate_hash'
        );
        HarnessPersistenceValue::optionalHash(
            $canonRevisionHash,
            'branch_tournament.canon_revision_hash'
        );
        HarnessPersistenceValue::hash($outlineHash, 'branch_tournament.outline_hash');
        HarnessPersistenceValue::hash($promptHash, 'branch_tournament.prompt_hash');
        HarnessPersistenceValue::hash(
            $featureSnapshotChecksum,
            'branch_tournament.feature_snapshot_checksum'
        );
        if ($canonRevisionHash !== null
            && !hash_equals($canonRevisionHash, $baseCoordinateHash)) {
            throw new InvalidArgumentException(
                'A Canon-backed tournament base must use the exact Canon revision hash.'
            );
        }

        $this->baseCoordinateKind = $canonRevisionHash === null
            ? 'run_snapshot_digest'
            : 'canon_revision';
        $this->branchUid = substr(hash(
            'sha256',
            implode("\0", [
                'run-tournament-branch-v1',
                $runUid,
                (string)$chapterId,
                $baseCoordinateHash,
                $outlineHash,
                $promptHash,
            ])
        ), 0, 32);
        $this->scopeHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson($this->manifestWithoutHash())
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->manifestWithoutHash() + ['scope_hash' => $this->scopeHash];
    }

    public function candidateUid(int $ordinal): string
    {
        HarnessPersistenceValue::boundedInt(
            $ordinal,
            'branch_tournament.candidate_ordinal',
            1,
            2
        );
        return substr(hash(
            'sha256',
            "run-tournament-candidate-v1\0{$this->scopeHash}\0{$ordinal}"
        ), 0, 32);
    }

    /** @return array<string,mixed> */
    private function manifestWithoutHash(): array
    {
        return [
            'schema' => self::SCHEMA,
            'coordinate_kind' => 'run_tournament_only',
            'run_uid' => $this->runUid,
            'user_id' => $this->userId,
            'novel_id' => $this->novelId,
            'chapter_id' => $this->chapterId,
            'branch_uid' => $this->branchUid,
            'branch_persisted' => false,
            'branch_head_writes' => false,
            'canon_writes' => false,
            'memory_access' => 'frozen_prompt_read_only',
            'memory_writes' => false,
            'base_coordinate_kind' => $this->baseCoordinateKind,
            'base_coordinate_hash' => $this->baseCoordinateHash,
            'canon_revision_hash' => $this->canonRevisionHash,
            'outline_hash' => $this->outlineHash,
            'prompt_hash' => $this->promptHash,
            'feature_snapshot_checksum' => $this->featureSnapshotChecksum,
            'candidate_count' => 2,
            'selector' => self::SELECTOR,
            'selector_version' => self::SELECTOR_VERSION,
        ];
    }
}

/** One completed model call; persisted atomically with its raw candidate. */
final readonly class ProductionBranchCandidateGeneration
{
    /** @var array<string,mixed> */
    public array $usage;

    /** @param array<string,mixed>|null $usage */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public string $modelId,
        public string $modelLabel,
        ?array $usage,
        public ?int $durationMs
    ) {
        if (trim($content) === '') {
            throw new InvalidArgumentException(
                'A completed Branch candidate cannot be empty.'
            );
        }
        HarnessPersistenceValue::identifier($provider, 'branch_candidate.provider', 64);
        HarnessPersistenceValue::string($model, 'branch_candidate.model', 1, 191);
        HarnessPersistenceValue::string($modelId, 'branch_candidate.model_id', 1, 64);
        HarnessPersistenceValue::string(
            $modelLabel,
            'branch_candidate.model_label',
            1,
            191
        );
        if ($durationMs !== null) {
            HarnessPersistenceValue::nonNegativeInt(
                $durationMs,
                'branch_candidate.duration_ms'
            );
        }
        $this->usage = self::normalizeUsage($usage);
    }

    /** @return array<string,mixed> */
    public function artifactPayload(
        ProductionBranchTournamentScope $scope,
        int $ordinal
    ): array {
        return [
            'schema' => 'harness.branch_tournament.candidate.v1',
            'scope_hash' => $scope->scopeHash,
            'candidate_ordinal' => $ordinal,
            'candidate_uid' => $scope->candidateUid($ordinal),
            'branch_uid' => $scope->branchUid,
            'base_coordinate_hash' => $scope->baseCoordinateHash,
            'outline_hash' => $scope->outlineHash,
            'prompt_hash' => $scope->promptHash,
            'content' => $this->content,
            'content_hash' => hash('sha256', $this->content),
            'provider' => $this->provider,
            'model' => $this->model,
            'model_id' => $this->modelId,
            'model_label' => $this->modelLabel,
            'usage' => $this->usage,
            'duration_ms' => $this->durationMs,
            'canon_writes' => false,
            'memory_writes' => false,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromArtifact(
        array $payload,
        ProductionBranchTournamentScope $scope,
        int $ordinal
    ): self {
        $usage = $payload['usage'] ?? null;
        $duration = $payload['duration_ms'] ?? null;
        $generation = new self(
            self::requiredString($payload, 'content'),
            self::requiredString($payload, 'provider'),
            self::requiredString($payload, 'model'),
            self::requiredString($payload, 'model_id'),
            self::requiredString($payload, 'model_label'),
            is_array($usage) ? $usage : null,
            is_int($duration) ? $duration : null
        );
        if (HarnessPersistenceValue::canonicalMysqlJson($payload)
            !== HarnessPersistenceValue::canonicalMysqlJson(
                $generation->artifactPayload($scope, $ordinal)
            )) {
            throw new WorkflowCheckpointException(
                'Recovered Branch candidate has stale or incomplete coordinates.'
            );
        }
        return $generation;
    }

    /** @param array<string,mixed> $payload */
    private static function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new WorkflowCheckpointException(
                "Recovered Branch candidate field {$field} is invalid."
            );
        }
        return $value;
    }

    /** @param array<string,mixed>|null $usage @return array<string,mixed> */
    private static function normalizeUsage(?array $usage): array
    {
        $availability = $usage['usage_available'] ?? null;
        if ($availability !== null && !is_bool($availability)) {
            throw new InvalidArgumentException(
                'Branch candidate usage_available must be boolean.'
            );
        }
        $available = is_bool($availability) ? $availability : $usage !== null;
        $prompt = self::tokenCount($usage['prompt_tokens'] ?? 0, 'prompt_tokens');
        $completion = self::tokenCount(
            $usage['completion_tokens'] ?? 0,
            'completion_tokens'
        );
        $reportedTotal = self::tokenCount(
            $usage['total_tokens'] ?? ($prompt + $completion),
            'total_tokens'
        );
        $cacheHit = self::tokenCount(
            $usage['cache_hit_tokens'] ?? 0,
            'cache_hit_tokens'
        );
        return [
            'usage_available' => $available,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $reportedTotal,
            'cache_hit_tokens' => $cacheHit,
        ];
    }

    private static function tokenCount(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException(
                "Branch candidate {$field} must be a non-negative integer."
            );
        }
        return HarnessPersistenceValue::nonNegativeInt(
            (int)$value,
            "branch_candidate.{$field}"
        );
    }
}

/** Pure, deterministic and versioned comparison; it performs no I/O. */
final class DeterministicChapterPairwiseReviewer implements PurePairwiseReviewer
{
    public const NAME = ProductionBranchTournamentScope::SELECTOR;
    public const VERSION = ProductionBranchTournamentScope::SELECTOR_VERSION;

    public function compare(BranchCandidate $a, BranchCandidate $b): PairwiseReview
    {
        $scoreA = self::score($a->content);
        $scoreB = self::score($b->content);
        $winner = $scoreA === $scoreB
            ? PairwiseWinner::TIE
            : ($scoreA > $scoreB ? PairwiseWinner::A : PairwiseWinner::B);
        $distance = abs($scoreA - $scoreB);
        $confidence = $winner === PairwiseWinner::TIE
            ? 0.5
            : min(0.99, 0.55 + ($distance / 200));
        return new PairwiseReview(
            self::NAME,
            self::VERSION,
            $a->candidateUid,
            $b->candidateUid,
            $winner,
            $confidence,
            HarnessPersistenceValue::canonicalJson([
                'schema' => 'harness.branch_pairwise.evidence.v1',
                'candidate_a_score' => $scoreA,
                'candidate_b_score' => $scoreB,
                'score_distance' => $distance,
                'features' => [
                    'usable_length',
                    'paragraph_diversity',
                    'punctuation_closure',
                    'placeholder_penalty',
                    'repetition_penalty',
                ],
            ])
        );
    }

    private static function score(string $content): int
    {
        $plain = preg_replace('/\s+/u', '', $content);
        $plain = is_string($plain) ? $plain : $content;
        $length = mb_strlen($plain, 'UTF-8');
        $lengthScore = min(45, intdiv(min($length, 3600), 80));

        $paragraphs = preg_split('/\R{1,}/u', trim($content)) ?: [];
        $paragraphs = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $paragraphs
        ), static fn (string $value): bool => $value !== ''));
        $uniqueParagraphs = array_values(array_unique($paragraphs));
        $paragraphScore = $paragraphs === []
            ? 0
            : min(25, (int)round(25 * count($uniqueParagraphs) / count($paragraphs)));

        $closureScore = preg_match('/[。！？!?…」”』]$/u', trim($content)) === 1
            ? 15
            : 0;
        $placeholderPenalty = preg_match(
            '/(?:TODO|TBD|待补|占位|作为AI|无法继续|以下是正文)/iu',
            $content
        ) === 1 ? 35 : 0;
        $repeatPenalty = count($paragraphs) - count($uniqueParagraphs);
        $repeatPenalty = min(25, max(0, $repeatPenalty) * 5);

        return max(
            0,
            min(100, 15 + $lengthScore + $paragraphScore + $closureScore
                - $placeholderPenalty - $repeatPenalty)
        );
    }
}

final readonly class ProductionBranchTournamentSelection
{
    /** @var array<string,mixed> */
    public array $usage;
    /** @var array<string,array<string,mixed>> */
    public array $artifacts;

    /**
     * @param array<string,mixed> $usage
     * @param array<string,array<string,mixed>> $artifacts
     */
    public function __construct(
        public string $content,
        public string $contentHash,
        public string $winnerUid,
        public int $winnerOrdinal,
        public string $winnerProvider,
        public string $winnerModel,
        public string $winnerModelId,
        public string $winnerModelLabel,
        array $usage,
        public ?int $durationMs,
        array $artifacts,
        public int $recoveredCandidateCount,
        public bool $recoveredResult
    ) {
        if (!hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException('Tournament winner hash is invalid.');
        }
        HarnessPersistenceValue::uid($winnerUid, 'branch_tournament.winner_uid');
        HarnessPersistenceValue::boundedInt(
            $winnerOrdinal,
            'branch_tournament.winner_ordinal',
            1,
            2
        );
        $this->usage = $usage;
        $this->artifacts = $artifacts;
    }
}

/**
 * Recoverable two-candidate production runner.  It has no BranchRepository,
 * Canon, or Memory dependency by design.
 */
final class ProductionBranchCandidateTournamentRunner
{
    private Closure $generator;

    /**
     * @param callable(int,ProductionBranchTournamentScope):ProductionBranchCandidateGeneration $generator
     */
    public function __construct(
        private readonly BranchTournamentArtifactGateway $artifacts,
        callable $generator,
        private readonly PurePairwiseReviewer $reviewer = new DeterministicChapterPairwiseReviewer()
    ) {
        $this->generator = Closure::fromCallable($generator);
    }

    public function run(
        RunContext $context,
        ProductionBranchTournamentScope $scope
    ): ProductionBranchTournamentSelection {
        if ($context->request->runUid !== $scope->runUid
            || $context->request->userId !== $scope->userId
            || $context->request->novelId !== $scope->novelId
            || $context->request->chapterId !== $scope->chapterId) {
            throw new WorkflowCheckpointException(
                'Branch tournament scope does not match the active Run.'
            );
        }

        $scopeReference = $this->recoverOrStoreExact(
            $context,
            'branch.tournament.scope',
            'branch.tournament.scope',
            $scope->toArray(),
            GenerationArtifactSensitivity::INTERNAL
        );

        $generations = [];
        $candidateReferences = [];
        $recoveredCandidates = 0;
        for ($ordinal = 1; $ordinal <= 2; $ordinal++) {
            $logicalKey = "branch.tournament.candidate.{$ordinal}";
            $found = $this->artifacts->findJson(
                $context,
                $logicalKey,
                'branch.candidate'
            );
            if ($found !== null) {
                $generation = ProductionBranchCandidateGeneration::fromArtifact(
                    $found['payload'],
                    $scope,
                    $ordinal
                );
                $reference = $found['reference'];
                $recoveredCandidates++;
            } else {
                $generation = ($this->generator)($ordinal, $scope);
                if (!$generation instanceof ProductionBranchCandidateGeneration) {
                    throw new RuntimeException(
                        'Branch candidate generator returned no typed result.'
                    );
                }
                $reference = $this->artifacts->storeJson(
                    $context,
                    $logicalKey,
                    'branch.candidate',
                    $generation->artifactPayload($scope, $ordinal),
                    GenerationArtifactSensitivity::RESTRICTED
                );
            }
            $generations[$ordinal] = $generation;
            $candidateReferences[$logicalKey] = $reference;
        }

        $candidates = [];
        foreach ($generations as $ordinal => $generation) {
            $candidates[$ordinal] = new BranchCandidate(
                $scope->candidateUid($ordinal),
                $scope->userId,
                $scope->novelId,
                $scope->chapterId,
                $scope->branchUid,
                $scope->baseCoordinateHash,
                $scope->outlineHash,
                $generation->content,
                hash('sha256', $generation->content)
            );
        }
        $tournament = (new BranchCandidateTournament($this->reviewer))->select(
            array_values($candidates)
        );
        $winnerOrdinal = null;
        foreach ($candidates as $ordinal => $candidate) {
            if ($candidate->candidateUid === $tournament->winner->candidateUid) {
                $winnerOrdinal = $ordinal;
                break;
            }
        }
        if (!is_int($winnerOrdinal)) {
            throw new RuntimeException('Tournament winner is outside the candidate set.');
        }

        $usage = self::aggregateUsage($generations);
        $durationMs = self::aggregateDuration($generations);
        $resultPayload = self::resultPayload(
            $scope,
            $generations,
            $tournament,
            $winnerOrdinal,
            $usage,
            $durationMs
        );
        $foundResult = $this->artifacts->findJson(
            $context,
            'branch.tournament.result',
            'branch.tournament.result'
        );
        $recoveredResult = $foundResult !== null;
        if ($foundResult !== null) {
            if (HarnessPersistenceValue::canonicalMysqlJson($foundResult['payload'])
                !== HarnessPersistenceValue::canonicalMysqlJson($resultPayload)) {
                throw new WorkflowCheckpointException(
                    'Recovered Branch tournament result is stale or incomplete.'
                );
            }
            $resultReference = $foundResult['reference'];
        } else {
            $resultReference = $this->artifacts->storeJson(
                $context,
                'branch.tournament.result',
                'branch.tournament.result',
                $resultPayload,
                GenerationArtifactSensitivity::INTERNAL
            );
        }

        $winnerGeneration = $generations[$winnerOrdinal];
        return new ProductionBranchTournamentSelection(
            $winnerGeneration->content,
            hash('sha256', $winnerGeneration->content),
            $scope->candidateUid($winnerOrdinal),
            $winnerOrdinal,
            $winnerGeneration->provider,
            $winnerGeneration->model,
            $winnerGeneration->modelId,
            $winnerGeneration->modelLabel,
            $usage,
            $durationMs,
            ['branch.tournament.scope' => $scopeReference]
                + $candidateReferences
                + ['branch.tournament.result' => $resultReference],
            $recoveredCandidates,
            $recoveredResult
        );
    }

    /**
     * @param array<int,ProductionBranchCandidateGeneration> $generations
     * @param array<string,mixed> $usage
     * @return array<string,mixed>
     */
    private static function resultPayload(
        ProductionBranchTournamentScope $scope,
        array $generations,
        CandidateTournamentResult $result,
        int $winnerOrdinal,
        array $usage,
        ?int $durationMs
    ): array {
        $candidateManifest = [];
        foreach ($generations as $ordinal => $generation) {
            $candidateManifest[] = [
                'candidate_ordinal' => $ordinal,
                'candidate_uid' => $scope->candidateUid($ordinal),
                'content_hash' => hash('sha256', $generation->content),
                'provider' => $generation->provider,
                'model' => $generation->model,
                'model_id' => $generation->modelId,
                'model_label' => $generation->modelLabel,
                'usage' => $generation->usage,
                'duration_ms' => $generation->durationMs,
            ];
        }
        $reviews = array_map(
            static fn (PairwiseReview $review): array => [
                'reviewer' => $review->reviewer,
                'version' => $review->version,
                'candidate_a_uid' => $review->candidateAUid,
                'candidate_b_uid' => $review->candidateBUid,
                'winner' => $review->winner->value,
                'confidence' => $review->confidence,
                'evidence' => $review->evidence,
            ],
            $result->reviews
        );
        $payload = [
            'schema' => 'harness.branch_tournament.result.v1',
            'scope_hash' => $scope->scopeHash,
            'candidate_count' => 2,
            'candidates' => $candidateManifest,
            'pairwise_reviews' => $reviews,
            'points' => $result->points,
            'winner' => [
                'candidate_ordinal' => $winnerOrdinal,
                'candidate_uid' => $result->winner->candidateUid,
                'content_hash' => $result->winner->contentHash,
            ],
            'aggregate_usage' => $usage,
            'aggregate_duration_ms' => $durationMs,
            'provenance' => [
                'selector' => ProductionBranchTournamentScope::SELECTOR,
                'selector_version' =>
                    ProductionBranchTournamentScope::SELECTOR_VERSION,
                'deterministic' => true,
                'pure_reviewer' => true,
                'tie_break' => 'lowest_candidate_uid',
                'coordinate_kind' => 'run_tournament_only',
                'frozen_prompt_hash' => $scope->promptHash,
                'frozen_outline_hash' => $scope->outlineHash,
                'frozen_base_coordinate_hash' => $scope->baseCoordinateHash,
                'memory_access' => 'frozen_prompt_read_only',
                'memory_writes' => false,
                'branch_persisted' => false,
                'branch_head_writes' => false,
                'canon_writes' => false,
            ],
        ];
        return $payload + [
            'result_hash' => hash(
                'sha256',
                HarnessPersistenceValue::canonicalMysqlJson($payload)
            ),
        ];
    }

    /**
     * @param array<int,ProductionBranchCandidateGeneration> $generations
     * @return array<string,mixed>
     */
    private static function aggregateUsage(array $generations): array
    {
        $result = [
            'usage_available' => true,
            'reported_candidate_count' => 0,
            'candidate_count' => count($generations),
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cache_hit_tokens' => 0,
            'source' => 'tournament_aggregate',
        ];
        foreach ($generations as $generation) {
            if (($generation->usage['usage_available'] ?? false) === true) {
                $result['reported_candidate_count']++;
            } else {
                $result['usage_available'] = false;
            }
            foreach ([
                'prompt_tokens', 'completion_tokens',
                'total_tokens', 'cache_hit_tokens',
            ] as $field) {
                $result[$field] += (int)$generation->usage[$field];
            }
        }
        return $result;
    }

    /** @param array<int,ProductionBranchCandidateGeneration> $generations */
    private static function aggregateDuration(array $generations): ?int
    {
        $total = 0;
        foreach ($generations as $generation) {
            if ($generation->durationMs === null) {
                return null;
            }
            $total += $generation->durationMs;
        }
        return $total;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function recoverOrStoreExact(
        RunContext $context,
        string $logicalKey,
        string $artifactType,
        array $payload,
        GenerationArtifactSensitivity $sensitivity
    ): array {
        $found = $this->artifacts->findJson($context, $logicalKey, $artifactType);
        if ($found !== null) {
            if (HarnessPersistenceValue::canonicalMysqlJson($found['payload'])
                !== HarnessPersistenceValue::canonicalMysqlJson($payload)) {
                throw new WorkflowCheckpointException(
                    "Recovered Branch tournament Artifact {$logicalKey} is stale."
                );
            }
            return $found['reference'];
        }
        return $this->artifacts->storeJson(
            $context,
            $logicalKey,
            $artifactType,
            $payload,
            $sensitivity
        );
    }
}
