<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PairwiseMetrics.php';

/** Builds the identity-free payload shown to a pairwise Judge. */
final class BlindPairwiseProtocol
{
    public const VERSION = 'blind-pairwise-v1';
    public const LABELS = ['a', 'b', 'tie'];

    private function __construct()
    {
    }

    public static function prepare(
        string $caseId,
        string $category,
        int $trial,
        string $baselineId,
        string $candidateId,
        string $baselineContent,
        string $candidateContent,
        string $rubric,
        int $seed
    ): BlindPairwiseTrial {
        self::identifier($caseId, 'pairwise.case_id');
        self::identifier($category, 'pairwise.category');
        self::identifier($baselineId, 'pairwise.baseline_id');
        self::identifier($candidateId, 'pairwise.candidate_id');
        HarnessPersistenceValue::boundedInt($trial, 'pairwise.trial', 1, 1000);
        HarnessPersistenceValue::nonNegativeInt($seed, 'pairwise.seed');
        if ($baselineId === $candidateId || $baselineContent === '' || $candidateContent === '') {
            throw new InvalidArgumentException('Pairwise identities and contents must be distinct/non-empty.');
        }
        $baselineHash = hash('sha256', $baselineContent);
        $candidateHash = hash('sha256', $candidateContent);
        $coordinates = self::coordinates(
            $caseId,
            $trial,
            $baselineId,
            $candidateId,
            $baselineHash,
            $candidateHash,
            $seed
        );
        $baselineAtA = $coordinates['baseline_position'] === 'a';
        return new BlindPairwiseTrial(
            $caseId,
            $category,
            $trial,
            $coordinates['pair_id'],
            $baselineId,
            $candidateId,
            $coordinates['baseline_position'],
            $baselineAtA ? $baselineContent : $candidateContent,
            $baselineAtA ? $candidateContent : $baselineContent,
            $baselineAtA ? $baselineHash : $candidateHash,
            $baselineAtA ? $candidateHash : $baselineHash,
            $rubric,
            hash('sha256', $rubric),
            $coordinates['assignment_commitment']
        );
    }

    /** @return array{pair_id:string,baseline_position:string,assignment_commitment:string} */
    public static function coordinates(
        string $caseId,
        int $trial,
        string $baselineId,
        string $candidateId,
        string $baselineHash,
        string $candidateHash,
        int $seed
    ): array {
        self::identifier($caseId, 'pairwise.case_id');
        self::identifier($baselineId, 'pairwise.baseline_id');
        self::identifier($candidateId, 'pairwise.candidate_id');
        HarnessPersistenceValue::boundedInt($trial, 'pairwise.trial', 1, 1000);
        HarnessPersistenceValue::nonNegativeInt($seed, 'pairwise.seed');
        self::sha($baselineHash, 'pairwise.baseline_hash');
        self::sha($candidateHash, 'pairwise.candidate_hash');
        $orientationDigest = hash('sha256', implode("\0", [
            self::VERSION,
            'orientation',
            (string)$seed,
            $caseId,
            $baselineHash,
            $candidateHash,
        ]));
        $digest = hash('sha256', implode("\0", [
            self::VERSION,
            (string)$seed,
            $caseId,
            (string)$trial,
            $baselineHash,
            $candidateHash,
        ]));
        // Seeded initial orientation plus deterministic alternation gives every
        // 3-trial case both orders without exposing either identity to Judge.
        $orientation = (hexdec($orientationDigest[0]) + $trial - 1) & 1;
        $baselinePosition = $orientation === 0 ? 'a' : 'b';
        $pairId = substr($digest, 0, 32);
        $commitment = hash('sha256', implode("\0", [
            'blind-assignment-commitment-v1',
            $pairId,
            $baselineId,
            $candidateId,
            $baselinePosition,
            $baselineHash,
            $candidateHash,
        ]));
        return [
            'pair_id' => $pairId,
            'baseline_position' => $baselinePosition,
            'assignment_commitment' => $commitment,
        ];
    }

    private static function identifier(string $value, string $field): void
    {
        HarnessPersistenceValue::identifier($value, $field, 96);
    }

    private static function sha(string $value, string $field): void
    {
        HarnessPersistenceValue::hash($value, $field);
    }
}

final readonly class BlindPairwiseTrial
{
    public function __construct(
        public string $caseId,
        public string $category,
        public int $trial,
        public string $pairId,
        private string $baselineId,
        private string $candidateId,
        public string $baselinePosition,
        private string $aContent,
        private string $bContent,
        public string $aHash,
        public string $bHash,
        private string $rubric,
        public string $rubricHash,
        public string $assignmentCommitment
    ) {
        if (!in_array($baselinePosition, ['a', 'b'], true)
            || !hash_equals(hash('sha256', $aContent), $aHash)
            || !hash_equals(hash('sha256', $bContent), $bHash)
            || !hash_equals(hash('sha256', $rubric), $rubricHash)) {
            throw new InvalidArgumentException('Blind pairwise trial hashes are invalid.');
        }
    }

    /** This is the only payload that may be sent to the Judge. */
    public function judgePayload(): array
    {
        return [
            'protocol' => BlindPairwiseProtocol::VERSION,
            'pair_id' => $this->pairId,
            'trial' => $this->trial,
            'rubric' => $this->rubric,
            'a' => $this->aContent,
            'b' => $this->bContent,
        ];
    }

    /** @return array<string,mixed> identity reveal produced only after a Judge label exists */
    public function reveal(
        string $judgeLabel,
        string $evidence,
        float $confidence,
        int $latencyMs
    ): array {
        if (!in_array($judgeLabel, BlindPairwiseProtocol::LABELS, true)
            || !is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0
            || $latencyMs < 0) {
            throw new InvalidArgumentException('Blind Judge result is invalid.');
        }
        $evidence = trim($evidence);
        if ($evidence === '' || strlen($evidence) > 4000) {
            throw new InvalidArgumentException('Blind Judge evidence must be bounded and non-empty.');
        }
        HarnessSensitiveDataGuard::assertSafeStructure(['evidence' => $evidence], 'pairwise.evidence');
        $outcome = $judgeLabel === 'tie'
            ? 'tie'
            : ($judgeLabel === $this->baselinePosition ? 'baseline' : 'candidate');
        $requestHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson($this->judgePayload())
        );
        return [
            'case_id' => $this->caseId,
            'category' => $this->category,
            'trial' => $this->trial,
            'pair_id' => $this->pairId,
            'baseline_position' => $this->baselinePosition,
            'a_hash' => $this->aHash,
            'b_hash' => $this->bHash,
            'assignment_commitment' => $this->assignmentCommitment,
            'judge_request_hash' => $requestHash,
            'judge_request_commitment' => self::requestCommitment(
                $this->pairId,
                $this->trial,
                $this->rubricHash,
                $this->aHash,
                $this->bHash,
                $requestHash
            ),
            'judge_label' => $judgeLabel,
            'outcome' => $outcome,
            'evidence' => $evidence,
            'confidence' => $confidence,
            'latency_ms' => $latencyMs,
        ];
    }

    public static function requestCommitment(
        string $pairId,
        int $trial,
        string $rubricHash,
        string $aHash,
        string $bHash,
        string $requestHash
    ): string {
        return hash('sha256', implode("\0", [
            'blind-judge-request-binding-v1',
            $pairId,
            (string)$trial,
            $rubricHash,
            $aHash,
            $bHash,
            $requestHash,
        ]));
    }
}

final readonly class PairwiseEvidenceBundle
{
    /** @var array<string,mixed> */
    public array $source;
    /** @var list<array<string,mixed>> */
    public array $trials;

    /** @param array<string,mixed> $source @param list<array<string,mixed>> $trials */
    public function __construct(array $source, array $trials)
    {
        $this->source = $source;
        $this->trials = $trials;
    }

    /** @return list<string> */
    public function outcomes(): array
    {
        return array_column($this->trials, 'outcome');
    }

    public function sourceHash(): string
    {
        return hash('sha256', HarnessPersistenceValue::canonicalJson($this->source));
    }

    /** @return array<string,mixed> */
    public function provenance(): array
    {
        return array_diff_key($this->source, ['trials' => true]);
    }

    /** @return array<string,int|float> */
    public function operationalMetrics(): array
    {
        $latencies = array_map('intval', array_column($this->trials, 'latency_ms'));
        sort($latencies, SORT_NUMERIC);
        $p95 = $latencies[(int)ceil(count($latencies) * 0.95) - 1];
        $baseline = $this->source['baseline'];
        $candidate = $this->source['candidate'];
        $baseLatency = (int)$baseline['p95_latency_ms'];
        $candidateLatency = (int)$candidate['p95_latency_ms'];
        return [
            'judge_p95_latency_ms' => $p95,
            'chapter_p95_latency_increase' => self::increase($baseLatency, $candidateLatency),
        ];
    }

    private static function increase(int $baseline, int $candidate): float
    {
        if ($baseline === 0) return $candidate === 0 ? 0.0 : INF;
        return ($candidate - $baseline) / $baseline;
    }
}

final class PairwiseEvidenceLoader
{
    private const DIMENSIONS = [
        'canon_causality', 'outline_foreshadow', 'voice_pov', 'immersion',
        'pacing', 'freshness', 'naturalness', 'read_on_intent',
    ];

    private function __construct()
    {
    }

    /** @param array<string,mixed> $source */
    public static function fromArray(
        array $source,
        string $expectedBaseline,
        string $expectedCandidate,
        int $expectedSeed
    ): PairwiseEvidenceBundle {
        self::exactFields($source, [
            'schema', 'suite_version', 'fixture_manifest_hash', 'rubric_version',
            'rubric_hash', 'rubric_dimensions', 'fixture_provenance', 'baseline',
            'candidate', 'judge', 'seed', 'trials',
        ], 'pairwise evidence');
        if (($source['schema'] ?? null) !== 'harness.eval.pairwise.v2'
            || !is_array($source['baseline']) || !is_array($source['candidate'])
            || !is_array($source['judge']) || !is_array($source['trials'])
            || !is_array($source['rubric_dimensions'])
            || !array_is_list($source['rubric_dimensions'])
            || !array_is_list($source['trials'])
            || !is_int($source['seed'])
            || $source['seed'] !== $expectedSeed) {
            throw new InvalidArgumentException('Pairwise v2 evidence envelope is invalid.');
        }
        self::identifier($source['suite_version'], 'pairwise.suite_version');
        self::identifier($source['rubric_version'], 'pairwise.rubric_version');
        self::sha($source['fixture_manifest_hash'], 'pairwise.fixture_manifest_hash');
        self::sha($source['rubric_hash'], 'pairwise.rubric_hash');
        if (!in_array(
            $source['fixture_provenance'],
            ['synthetic', 'authorized_deidentified'],
            true
        )) {
            throw new InvalidArgumentException('Pairwise fixture provenance is not authorized.');
        }
        $dimensions = $source['rubric_dimensions'];
        foreach ($dimensions as $dimension) {
            self::identifier($dimension, 'pairwise.rubric_dimension');
        }
        sort($dimensions, SORT_STRING);
        $requiredDimensions = self::DIMENSIONS;
        sort($requiredDimensions, SORT_STRING);
        if ($dimensions !== $requiredDimensions) {
            throw new InvalidArgumentException('Pairwise rubric dimensions are incomplete.');
        }

        $baseline = self::variant($source['baseline'], 'baseline');
        $candidate = self::variant($source['candidate'], 'candidate');
        if ($baseline['id'] !== $expectedBaseline || $candidate['id'] !== $expectedCandidate
            || $baseline['id'] === $candidate['id']) {
            throw new InvalidArgumentException('Pairwise variant identities do not match the request.');
        }
        $runnerEvidence = array_key_exists('runner_version', $source['judge']);
        $judgeFields = ['model', 'prompt_manifest_hash'];
        if ($runnerEvidence) {
            $judgeFields = array_merge($judgeFields, [
                'runner_version', 'trials_per_case', 'max_cases',
                'max_case_characters', 'max_total_characters', 'max_requests',
                'max_input_tokens_per_request', 'max_total_input_tokens',
                'max_output_tokens_per_request', 'max_total_output_tokens',
            ]);
        }
        self::exactFields($source['judge'], $judgeFields, 'pairwise judge',
            ['budget_micros', 'input_cost_micros_per_million_tokens', 'output_cost_micros_per_million_tokens']);
        self::text($source['judge']['model'], 'pairwise.judge.model', 191);
        self::sha($source['judge']['prompt_manifest_hash'], 'pairwise.judge.prompt_manifest_hash');
        if ($source['judge']['model'] === $baseline['model']
            || $source['judge']['model'] === $candidate['model']) {
            throw new InvalidArgumentException('Pairwise Writer and Judge models must be different.');
        }
        $runnerLimits = null;
        if ($runnerEvidence) {
            self::identifier($source['judge']['runner_version'], 'pairwise.judge.runner_version');
            $runnerLimits = [
                'trials_per_case' => self::boundedInt(
                    $source['judge']['trials_per_case'],
                    'pairwise.judge.trials_per_case',
                    3,
                    20
                ),
                'max_cases' => self::boundedInt(
                    $source['judge']['max_cases'],
                    'pairwise.judge.max_cases',
                    1,
                    1000
                ),
                'max_case_characters' => self::boundedInt(
                    $source['judge']['max_case_characters'],
                    'pairwise.judge.max_case_characters',
                    1,
                    2000000
                ),
                'max_total_characters' => self::boundedInt(
                    $source['judge']['max_total_characters'],
                    'pairwise.judge.max_total_characters',
                    1,
                    20000000
                ),
                'max_requests' => self::boundedInt(
                    $source['judge']['max_requests'],
                    'pairwise.judge.max_requests',
                    1,
                    10000
                ),
                'max_input_tokens_per_request' => self::boundedInt(
                    $source['judge']['max_input_tokens_per_request'],
                    'pairwise.judge.max_input_tokens_per_request',
                    1,
                    2000000
                ),
                'max_total_input_tokens' => self::boundedInt(
                    $source['judge']['max_total_input_tokens'],
                    'pairwise.judge.max_total_input_tokens',
                    1,
                    20000000
                ),
                'max_output_tokens_per_request' => self::boundedInt(
                    $source['judge']['max_output_tokens_per_request'],
                    'pairwise.judge.max_output_tokens_per_request',
                    1,
                    100000
                ),
                'max_total_output_tokens' => self::boundedInt(
                    $source['judge']['max_total_output_tokens'],
                    'pairwise.judge.max_total_output_tokens',
                    1,
                    10000000
                ),
            ];
        }

        $trials = [];
        $seen = [];
        $byCase = [];
        $inputTokensSpent = 0;
        $outputTokensSpent = 0;
        foreach ($source['trials'] as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Pairwise trial must be an object.');
            $trialFields = [
                'case_id', 'category', 'trial', 'pair_id', 'baseline_position',
                'a_hash', 'b_hash', 'assignment_commitment', 'judge_request_hash',
                'judge_request_commitment', 'judge_label', 'outcome', 'evidence',
                'confidence', 'latency_ms',
            ];
            if ($runnerEvidence) {
                $trialFields[] = 'input_tokens';
                $trialFields[] = 'output_tokens';
            }
            self::exactFields($row, $trialFields, 'pairwise trial', ['cost_micros']);
            $caseId = self::identifier($row['case_id'], 'pairwise.case_id');
            self::identifier($row['category'], 'pairwise.category');
            $trial = self::positiveInt($row['trial'], 'pairwise.trial');
            $key = $caseId . ':' . $trial;
            if (isset($seen[$key])) throw new InvalidArgumentException('Pairwise trial coordinates repeat.');
            $seen[$key] = true;
            $aHash = self::sha($row['a_hash'], 'pairwise.a_hash');
            $bHash = self::sha($row['b_hash'], 'pairwise.b_hash');
            $position = $row['baseline_position'];
            if (!in_array($position, ['a', 'b'], true)) {
                throw new InvalidArgumentException('Pairwise baseline position is invalid.');
            }
            $baselineHash = $position === 'a' ? $aHash : $bHash;
            $candidateHash = $position === 'a' ? $bHash : $aHash;
            $coordinates = BlindPairwiseProtocol::coordinates(
                $caseId,
                $trial,
                $baseline['id'],
                $candidate['id'],
                $baselineHash,
                $candidateHash,
                $expectedSeed
            );
            foreach ([
                'pair_id' => $coordinates['pair_id'],
                'baseline_position' => $coordinates['baseline_position'],
                'assignment_commitment' => $coordinates['assignment_commitment'],
            ] as $field => $expected) {
                if (!is_string($row[$field]) || !hash_equals($expected, $row[$field])) {
                    throw new InvalidArgumentException("Pairwise {$field} does not match blind assignment.");
                }
            }
            $requestHash = self::sha(
                $row['judge_request_hash'],
                'pairwise.judge_request_hash'
            );
            $expectedRequestCommitment = BlindPairwiseTrial::requestCommitment(
                $row['pair_id'],
                $trial,
                $source['rubric_hash'],
                $aHash,
                $bHash,
                $requestHash
            );
            if (!is_string($row['judge_request_commitment'])
                || !hash_equals(
                    $expectedRequestCommitment,
                    $row['judge_request_commitment']
                )) {
                throw new InvalidArgumentException(
                    'Pairwise Judge request hash is not bound to the blind trial.'
                );
            }
            $label = $row['judge_label'];
            $outcome = $row['outcome'];
            if (!in_array($label, BlindPairwiseProtocol::LABELS, true)
                || !in_array($outcome, ['baseline', 'candidate', 'tie'], true)
                || ($label === 'tie' ? $outcome !== 'tie'
                    : $outcome !== ($label === $position ? 'baseline' : 'candidate'))) {
                throw new InvalidArgumentException('Pairwise Judge label/reveal is inconsistent.');
            }
            self::text($row['evidence'], 'pairwise.evidence', 4000);
            if (!is_float($row['confidence']) && !is_int($row['confidence'])) {
                throw new InvalidArgumentException('Pairwise confidence must be numeric.');
            }
            $confidence = (float)$row['confidence'];
            if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
                throw new InvalidArgumentException('Pairwise confidence is outside 0..1.');
            }
            self::nonNegativeInt($row['latency_ms'], 'pairwise.latency_ms');
            if ($runnerLimits !== null) {
                $inputTokens = self::nonNegativeInt(
                    $row['input_tokens'],
                    'pairwise.input_tokens'
                );
                $outputTokens = self::nonNegativeInt(
                    $row['output_tokens'],
                    'pairwise.output_tokens'
                );
                if ($inputTokens > $runnerLimits['max_input_tokens_per_request']
                    || $outputTokens > $runnerLimits['max_output_tokens_per_request']) {
                    throw new InvalidArgumentException('Pairwise trial usage exceeds token limits.');
                }
                $inputTokensSpent += $inputTokens;
                $outputTokensSpent += $outputTokens;
            }
            if (isset($byCase[$caseId])
                && $byCase[$caseId]['category'] !== $row['category']) {
                throw new InvalidArgumentException(
                    "Pairwise case {$caseId} changed category between trials."
                );
            }
            $byCase[$caseId] ??= ['category' => $row['category'], 'positions' => []];
            $byCase[$caseId]['positions'][] = $position;
            $trials[] = $row;
        }
        if ($trials === []) {
            throw new InvalidArgumentException('Pairwise evidence is empty.');
        }
        if ($runnerLimits !== null
            && (count($byCase) > $runnerLimits['max_cases']
                || count($trials) > $runnerLimits['max_requests']
                || $inputTokensSpent > $runnerLimits['max_total_input_tokens']
                || $outputTokensSpent > $runnerLimits['max_total_output_tokens'])) {
            throw new InvalidArgumentException(
                'Pairwise runner evidence exceeded its fixed cases/requests/token limits.'
            );
        }
        foreach ($byCase as $caseId => $case) {
            $positions = $case['positions'];
            $expectedTrials = $runnerLimits['trials_per_case'] ?? null;
            if (count($positions) < 3
                || ($expectedTrials !== null && count($positions) !== $expectedTrials)
                || count(array_unique($positions)) !== 2) {
                throw new InvalidArgumentException(
                    "Pairwise case {$caseId} requires at least three trials and both A/B orders."
                );
            }
        }
        usort($trials, static fn (array $a, array $b): int =>
            strcmp($a['case_id'], $b['case_id']) ?: ($a['trial'] <=> $b['trial'])
        );
        $source['trials'] = $trials;
        return new PairwiseEvidenceBundle($source, $trials);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function variant(array $row, string $label): array
    {
        self::exactFields($row, [
            'id', 'workflow_version', 'model', 'prompt_manifest_hash',
            'artifact_manifest_hash', 'p95_latency_ms',
        ], "pairwise {$label}", ['cost_per_chapter_micros']);
        self::identifier($row['id'], "pairwise.{$label}.id");
        self::identifier($row['workflow_version'], "pairwise.{$label}.workflow_version");
        self::text($row['model'], "pairwise.{$label}.model", 191);
        self::sha($row['prompt_manifest_hash'], "pairwise.{$label}.prompt_manifest_hash");
        self::sha($row['artifact_manifest_hash'], "pairwise.{$label}.artifact_manifest_hash");
        self::nonNegativeInt($row['p95_latency_ms'], "pairwise.{$label}.latency");
        return $row;
    }

    /** @param array<string,mixed> $row @param list<string> $expected */
    private static function exactFields(array $row, array $expected, string $label, array $retired = []): void
    {
        // Preserve historical evidence bytes/identities while ignoring retired currency fields.
        $actual = array_values(array_diff(array_keys($row), $retired));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) throw new InvalidArgumentException("{$label} fields are not exact.");
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value)) throw new InvalidArgumentException("{$field} must be a string.");
        HarnessPersistenceValue::identifier($value, $field, 96);
        return $value;
    }

    private static function sha(mixed $value, string $field): string
    {
        if (!is_string($value)) throw new InvalidArgumentException("{$field} must be a hash.");
        HarnessPersistenceValue::hash($value, $field);
        return $value;
    }

    private static function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) throw new InvalidArgumentException("{$field} must be text.");
        HarnessPersistenceValue::string($value, $field, 1, $max);
        HarnessSensitiveDataGuard::assertSafeStructure([$field => $value], $field);
        return $value;
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) throw new InvalidArgumentException("{$field} is invalid.");
        return $value;
    }

    private static function boundedInt(
        mixed $value,
        string $field,
        int $minimum,
        int $maximum
    ): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$field} is outside fixed bounds.");
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) throw new InvalidArgumentException("{$field} is invalid.");
        return $value;
    }

}

final class HumanAnnotationEvidence
{
    private function __construct()
    {
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public static function summarize(array $source, PairwiseEvidenceBundle $pairwise): array
    {
        self::exactFields($source, [
            'schema', 'pairwise_source_hash', 'rubric_hash', 'annotations',
        ], 'annotation evidence');
        if (($source['schema'] ?? null) !== 'harness.eval.annotations.v1'
            || !is_array($source['annotations'] ?? null)
            || !is_string($source['pairwise_source_hash'] ?? null)
            || !hash_equals($pairwise->sourceHash(), $source['pairwise_source_hash'])
            || !is_string($source['rubric_hash'] ?? null)
            || !hash_equals((string)$pairwise->source['rubric_hash'], $source['rubric_hash'])) {
            throw new InvalidArgumentException('Human annotations are not bound to Pairwise evidence.');
        }
        $judgeByPair = [];
        foreach ($pairwise->trials as $trial) $judgeByPair[$trial['pair_id']] = $trial['outcome'];
        $left = $right = $judge = $human = [];
        $seen = [];
        foreach ($source['annotations'] as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Annotation row must be an object.');
            self::exactFields($row, [
                'pair_id', 'annotator_a', 'annotator_b', 'adjudicator', 'judge_outcome',
            ], 'annotation row');
            $pairId = $row['pair_id'];
            if (!is_string($pairId) || !isset($judgeByPair[$pairId]) || isset($seen[$pairId])) {
                throw new InvalidArgumentException('Annotation pair is unknown or duplicated.');
            }
            $seen[$pairId] = true;
            $a = self::label($row['annotator_a'], 'annotator_a');
            $b = self::label($row['annotator_b'], 'annotator_b');
            if ($a['blind_id'] === $b['blind_id']) {
                throw new InvalidArgumentException('Human annotations require two independent blind annotators.');
            }
            if ($a['label'] === $b['label']) {
                if ($row['adjudicator'] !== null) {
                    throw new InvalidArgumentException('An agreed pair must not be overwritten by adjudication.');
                }
                $resolved = $a['label'];
            } else {
                $adjudicator = self::label($row['adjudicator'], 'adjudicator');
                if (in_array($adjudicator['blind_id'], [$a['blind_id'], $b['blind_id']], true)) {
                    throw new InvalidArgumentException('Conflict adjudicator must be an independent third person.');
                }
                $resolved = $adjudicator['label'];
            }
            if ($row['judge_outcome'] !== $judgeByPair[$pairId]) {
                throw new InvalidArgumentException('Annotation Judge label was rewritten after evaluation.');
            }
            $left[] = $a['label'];
            $right[] = $b['label'];
            $judge[] = $row['judge_outcome'];
            $human[] = $resolved;
        }
        $minimum = max(30, (int)ceil(count($pairwise->trials) * 0.20));
        if (count($left) < $minimum) {
            throw new InvalidArgumentException(
                "Human calibration requires at least {$minimum} independently labelled pairs."
            );
        }
        return [
            'schema' => 'harness.eval.calibration.v1',
            'annotation_source_hash' => hash(
                'sha256',
                HarnessPersistenceValue::canonicalJson($source)
            ),
            'annotated_pairs' => count($left),
            'minimum_pairs' => $minimum,
            'weighted_kappa' => AnnotationAgreementMetrics::weightedKappa($left, $right),
            'judge_human_agreement' => AnnotationAgreementMetrics::exactAgreement($judge, $human),
        ];
    }

    /** @return array{blind_id:string,label:string} */
    private static function label(mixed $row, string $field): array
    {
        if (!is_array($row)) throw new InvalidArgumentException("{$field} label is missing.");
        self::exactFields($row, ['blind_id', 'label'], $field);
        if (!is_string($row['blind_id']) || !is_string($row['label'])
            || !in_array($row['label'], ['baseline', 'candidate', 'tie'], true)) {
            throw new InvalidArgumentException("{$field} label is invalid.");
        }
        HarnessPersistenceValue::identifier($row['blind_id'], "{$field}.blind_id", 96);
        return $row;
    }

    /** @param array<string,mixed> $row @param list<string> $expected */
    private static function exactFields(array $row, array $expected, string $label): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) throw new InvalidArgumentException("{$label} fields are not exact.");
    }
}
