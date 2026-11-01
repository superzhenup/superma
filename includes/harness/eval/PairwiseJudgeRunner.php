<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PairwiseProtocol.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

final class PairwiseJudgeBudgetExceededException extends RuntimeException
{
}

final readonly class PairwiseJudgeRunLimits
{
    /** @param array<string,mixed> $source */
    public static function fromArray(array $source): self
    {
        unset($source['max_cost_micros']); // Ignore retired currency metadata in old input bundles.
        self::exactFields($source, [
            'trials_per_case', 'max_cases', 'max_case_characters',
            'max_total_characters', 'max_requests',
            'max_input_tokens_per_request', 'max_total_input_tokens',
            'max_output_tokens_per_request', 'max_total_output_tokens',
        ]);
        return new self(
            self::integer($source['trials_per_case'], 'trials_per_case', 3, 20),
            self::integer($source['max_cases'], 'max_cases', 1, 1000),
            self::integer(
                $source['max_case_characters'],
                'max_case_characters',
                1,
                2000000
            ),
            self::integer(
                $source['max_total_characters'],
                'max_total_characters',
                1,
                20000000
            ),
            self::integer($source['max_requests'], 'max_requests', 1, 10000),
            self::integer(
                $source['max_input_tokens_per_request'],
                'max_input_tokens_per_request',
                1,
                2000000
            ),
            self::integer(
                $source['max_total_input_tokens'],
                'max_total_input_tokens',
                1,
                20000000
            ),
            self::integer(
                $source['max_output_tokens_per_request'],
                'max_output_tokens_per_request',
                1,
                100000
            ),
            self::integer(
                $source['max_total_output_tokens'],
                'max_total_output_tokens',
                1,
                10000000
            )
        );
    }

    public function __construct(
        public int $trialsPerCase,
        public int $maxCases,
        public int $maxCaseCharacters,
        public int $maxTotalCharacters,
        public int $maxRequests,
        public int $maxInputTokensPerRequest,
        public int $maxTotalInputTokens,
        public int $maxOutputTokensPerRequest,
        public int $maxTotalOutputTokens
    ) {
    }

    /** @return array<string,int> */
    public function evidenceArray(): array
    {
        return [
            'trials_per_case' => $this->trialsPerCase,
            'max_cases' => $this->maxCases,
            'max_case_characters' => $this->maxCaseCharacters,
            'max_total_characters' => $this->maxTotalCharacters,
            'max_requests' => $this->maxRequests,
            'max_input_tokens_per_request' => $this->maxInputTokensPerRequest,
            'max_total_input_tokens' => $this->maxTotalInputTokens,
            'max_output_tokens_per_request' => $this->maxOutputTokensPerRequest,
            'max_total_output_tokens' => $this->maxTotalOutputTokens,
        ];
    }

    /** @param array<string,mixed> $source @param list<string> $expected */
    private static function exactFields(array $source, array $expected): void
    {
        $actual = array_keys($source);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Pairwise Judge limit fields are not exact.');
        }
    }

    private static function integer(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("Pairwise Judge {$field} is outside bounds.");
        }
        return $value;
    }
}

final readonly class PairwiseJudgeInputBundle
{
    private const DIMENSIONS = [
        'canon_causality', 'outline_foreshadow', 'voice_pov', 'immersion',
        'pacing', 'freshness', 'naturalness', 'read_on_intent',
    ];

    /** @var array<string,mixed> */
    public array $baseline;
    /** @var array<string,mixed> */
    public array $candidate;
    /** @var list<string> */
    public array $rubricDimensions;
    /** @var list<array{id:string,category:string,baseline_content:string,candidate_content:string}> */
    public array $cases;
    public string $rubricHash;
    public string $fixtureManifestHash;
    public string $promptManifestHash;

    /** @param array<string,mixed> $source */
    public static function fromArray(array $source, int $expectedSeed): self
    {
        self::exactFields($source, [
            'schema', 'suite_version', 'fixture_version', 'fixture_provenance',
            'seed', 'rubric', 'baseline', 'candidate', 'judge', 'limits', 'cases',
        ], 'input bundle');
        if (($source['schema'] ?? null) !== 'harness.eval.pairwise.input.v1'
            || !is_int($source['seed'] ?? null)
            || $source['seed'] !== $expectedSeed
            || !is_array($source['rubric'] ?? null)
            || !is_array($source['baseline'] ?? null)
            || !is_array($source['candidate'] ?? null)
            || !is_array($source['judge'] ?? null)
            || !is_array($source['limits'] ?? null)
            || !is_array($source['cases'] ?? null)
            || !array_is_list($source['cases'])) {
            throw new InvalidArgumentException('Pairwise Judge input envelope is invalid.');
        }
        HarnessPersistenceValue::identifier(
            $source['suite_version'],
            'pairwise_runner.suite_version',
            96
        );
        HarnessPersistenceValue::identifier(
            $source['fixture_version'],
            'pairwise_runner.fixture_version',
            96
        );
        HarnessPersistenceValue::nonNegativeInt($expectedSeed, 'pairwise_runner.seed');
        if (!in_array(
            $source['fixture_provenance'],
            ['synthetic', 'authorized_deidentified'],
            true
        )) {
            throw new InvalidArgumentException(
                'Pairwise Judge fixtures must be synthetic or authorized/deidentified.'
            );
        }

        self::exactFields($source['rubric'], ['version', 'text', 'dimensions'], 'rubric');
        if (!is_string($source['rubric']['text'] ?? null)
            || !is_array($source['rubric']['dimensions'] ?? null)
            || !array_is_list($source['rubric']['dimensions'])) {
            throw new InvalidArgumentException('Pairwise Judge rubric is malformed.');
        }
        HarnessPersistenceValue::identifier(
            $source['rubric']['version'],
            'pairwise_runner.rubric_version',
            96
        );
        HarnessPersistenceValue::string(
            $source['rubric']['text'],
            'pairwise_runner.rubric',
            1,
            20000
        );
        HarnessSensitiveDataGuard::assertSafeText(
            $source['rubric']['text'],
            'pairwise_runner.rubric'
        );
        $dimensions = $source['rubric']['dimensions'];
        foreach ($dimensions as $dimension) {
            if (!is_string($dimension)) {
                throw new InvalidArgumentException('Pairwise rubric dimension is malformed.');
            }
            HarnessPersistenceValue::identifier(
                $dimension,
                'pairwise_runner.rubric_dimension',
                96
            );
        }
        $normalizedDimensions = $dimensions;
        sort($normalizedDimensions, SORT_STRING);
        $requiredDimensions = self::DIMENSIONS;
        sort($requiredDimensions, SORT_STRING);
        if ($normalizedDimensions !== $requiredDimensions) {
            throw new InvalidArgumentException('Pairwise rubric dimensions are incomplete.');
        }

        $baseline = self::variant($source['baseline'], 'baseline');
        $candidate = self::variant($source['candidate'], 'candidate');
        if ($baseline['id'] === $candidate['id']) {
            throw new InvalidArgumentException('Pairwise Writer identities must be distinct.');
        }
        unset($source['judge']['input_cost_micros_per_million_tokens'],
            $source['judge']['output_cost_micros_per_million_tokens']);
        self::exactFields($source['judge'], ['model', 'system_prompt'], 'judge');
        $judgeModel = self::safeText($source['judge']['model'], 'judge.model', 191);
        $systemPrompt = self::safeText(
            $source['judge']['system_prompt'],
            'judge.system_prompt',
            12000
        );
        if (hash_equals($judgeModel, (string)$baseline['model'])
            || hash_equals($judgeModel, (string)$candidate['model'])) {
            throw new InvalidArgumentException(
                'Pairwise Writer and Judge models must be different.'
            );
        }

        $limits = PairwiseJudgeRunLimits::fromArray($source['limits']);
        if (count($source['cases']) > $limits->maxCases || $source['cases'] === []) {
            throw new PairwiseJudgeBudgetExceededException(
                'Pairwise case count exceeds its fixed limit.'
            );
        }
        $cases = [];
        $seen = [];
        $totalCharacters = 0;
        foreach ($source['cases'] as $case) {
            if (!is_array($case)) {
                throw new InvalidArgumentException('Pairwise case must be an object.');
            }
            self::exactFields(
                $case,
                ['id', 'category', 'baseline_content', 'candidate_content'],
                'case'
            );
            $caseId = self::identifier($case['id'], 'case.id');
            $category = self::identifier($case['category'], 'case.category');
            if (isset($seen[$caseId])) {
                throw new InvalidArgumentException('Pairwise case IDs must be unique.');
            }
            $seen[$caseId] = true;
            $baselineContent = self::safeText(
                $case['baseline_content'],
                'case.baseline_content',
                1000000
            );
            $candidateContent = self::safeText(
                $case['candidate_content'],
                'case.candidate_content',
                1000000
            );
            if (hash_equals($baselineContent, $candidateContent)) {
                throw new InvalidArgumentException(
                    'Pairwise case Writer contents must be distinct.'
                );
            }
            $characters = self::characters($baselineContent)
                + self::characters($candidateContent);
            if ($characters > $limits->maxCaseCharacters) {
                throw new PairwiseJudgeBudgetExceededException(
                    "Pairwise case {$caseId} exceeds its character limit."
                );
            }
            $totalCharacters += $characters;
            if ($totalCharacters > $limits->maxTotalCharacters) {
                throw new PairwiseJudgeBudgetExceededException(
                    'Pairwise fixtures exceed the total character limit.'
                );
            }
            $cases[] = [
                'id' => $caseId,
                'category' => $category,
                'baseline_content' => $baselineContent,
                'candidate_content' => $candidateContent,
            ];
        }

        return new self(
            $source['suite_version'],
            $source['fixture_version'],
            $source['fixture_provenance'],
            $expectedSeed,
            $source['rubric']['version'],
            $source['rubric']['text'],
            $dimensions,
            $baseline,
            $candidate,
            $judgeModel,
            $systemPrompt,
            $limits,
            $cases,
            $totalCharacters
        );
    }

    /**
     * @param list<string> $rubricDimensions
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $candidate
     * @param list<array{id:string,category:string,baseline_content:string,candidate_content:string}> $cases
     */
    private function __construct(
        public string $suiteVersion,
        public string $fixtureVersion,
        public string $fixtureProvenance,
        public int $seed,
        public string $rubricVersion,
        public string $rubric,
        array $rubricDimensions,
        array $baseline,
        array $candidate,
        public string $judgeModel,
        public string $systemPrompt,
        public PairwiseJudgeRunLimits $limits,
        array $cases,
        public int $totalCharacters
    ) {
        $this->rubricDimensions = array_values($rubricDimensions);
        $this->baseline = $baseline;
        $this->candidate = $candidate;
        $this->cases = array_values($cases);
        $this->rubricHash = hash('sha256', $rubric);
        $manifestCases = array_map(
            static fn (array $case): array => [
                'id' => $case['id'],
                'category' => $case['category'],
                'baseline_hash' => hash('sha256', $case['baseline_content']),
                'candidate_hash' => hash('sha256', $case['candidate_content']),
            ],
            $this->cases
        );
        $this->fixtureManifestHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson([
                'schema' => 'harness.eval.pairwise.fixture_manifest.v1',
                'suite_version' => $suiteVersion,
                'fixture_version' => $fixtureVersion,
                'fixture_provenance' => $fixtureProvenance,
                'baseline_artifact_manifest_hash' => $baseline['artifact_manifest_hash'],
                'candidate_artifact_manifest_hash' => $candidate['artifact_manifest_hash'],
                'cases' => $manifestCases,
            ])
        );
        $this->promptManifestHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson([
                'schema' => 'harness.eval.pairwise.judge_prompt.v1',
                'protocol' => BlindPairwiseProtocol::VERSION,
                'system_prompt' => $systemPrompt,
                'rubric_hash' => $this->rubricHash,
                'response_schema' => PairwiseJudgeResponse::jsonSchema(),
            ])
        );
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private static function variant(array $source, string $label): array
    {
        unset($source['cost_per_chapter_micros']);
        self::exactFields($source, [
            'id', 'workflow_version', 'model', 'prompt_manifest_hash',
            'artifact_manifest_hash', 'p95_latency_ms',
        ], $label);
        self::identifier($source['id'], "{$label}.id");
        self::identifier($source['workflow_version'], "{$label}.workflow_version");
        self::safeText($source['model'], "{$label}.model", 191);
        foreach (['prompt_manifest_hash', 'artifact_manifest_hash'] as $field) {
            if (!is_string($source[$field] ?? null)) {
                throw new InvalidArgumentException("Pairwise {$label} {$field} is invalid.");
            }
            HarnessPersistenceValue::hash($source[$field], "pairwise_runner.{$label}.{$field}");
        }
        self::boundedInteger(
            $source['p95_latency_ms'],
            "{$label}.p95_latency_ms",
            0,
            86400000
        );
        return $source;
    }

    /** @param array<string,mixed> $source @param list<string> $expected */
    private static function exactFields(array $source, array $expected, string $label): void
    {
        $actual = array_keys($source);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException("Pairwise Judge {$label} fields are not exact.");
        }
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("Pairwise Judge {$field} is invalid.");
        }
        HarnessPersistenceValue::identifier($value, "pairwise_runner.{$field}", 96);
        return $value;
    }

    private static function safeText(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("Pairwise Judge {$field} must be text.");
        }
        HarnessPersistenceValue::string($value, "pairwise_runner.{$field}", 1, $max);
        HarnessSensitiveDataGuard::assertSafeText($value, "pairwise_runner.{$field}");
        return $value;
    }

    private static function boundedInteger(
        mixed $value,
        string $field,
        int $min,
        int $max
    ): int {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("Pairwise Judge {$field} is outside bounds.");
        }
        return $value;
    }

    private static function characters(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}

final readonly class PairwiseJudgeRequest
{
    /** @var array<string,mixed> */
    public array $payload;

    /** @param array<string,mixed> $payload */
    public function __construct(
        array $payload,
        public string $model,
        public int $seed,
        public int $maxOutputTokens
    ) {
        $expected = ['protocol', 'pair_id', 'trial', 'rubric', 'a', 'b'];
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || ($payload['protocol'] ?? null) !== BlindPairwiseProtocol::VERSION) {
            throw new InvalidArgumentException('Judge received a non-blind request payload.');
        }
        foreach (['pair_id', 'trial', 'rubric', 'a', 'b'] as $field) {
            if (str_contains($field, 'baseline') || str_contains($field, 'candidate')) {
                throw new LogicException('Judge request payload leaked Writer identity.');
            }
        }
        HarnessSensitiveDataGuard::assertSafeStructure($payload, 'pairwise_judge.request');
        $this->payload = $payload;
    }
}

final readonly class PairwiseJudgeResponse
{
    /** @param array<string,mixed> $source */
    public static function fromArray(array $source): self
    {
        unset($source['cost_micros']); // Legacy adapter metadata has no effect.
        $expected = [
            'label', 'evidence', 'confidence', 'usage', 'latency_ms',
        ];
        $actual = array_keys($source);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected || !is_array($source['usage'] ?? null)) {
            throw new InvalidArgumentException('Judge response fields are not exact.');
        }
        $usageFields = array_keys($source['usage']);
        sort($usageFields, SORT_STRING);
        if ($usageFields !== ['input_tokens', 'output_tokens']) {
            throw new InvalidArgumentException('Judge usage fields are not exact.');
        }
        if (!is_string($source['label'] ?? null)
            || !in_array($source['label'], BlindPairwiseProtocol::LABELS, true)
            || !is_string($source['evidence'] ?? null)
            || (!is_float($source['confidence'] ?? null)
                && !is_int($source['confidence'] ?? null))) {
            throw new InvalidArgumentException('Judge typed response is invalid.');
        }
        return new self(
            $source['label'],
            $source['evidence'],
            (float)$source['confidence'],
            self::integer($source['usage']['input_tokens'], 'input_tokens'),
            self::integer($source['usage']['output_tokens'], 'output_tokens'),
            self::integer($source['latency_ms'], 'latency_ms')
        );
    }

    public function __construct(
        public string $label,
        public string $evidence,
        public float $confidence,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs
    ) {
        if (!in_array($label, BlindPairwiseProtocol::LABELS, true)
            || !is_finite($confidence)
            || $confidence < 0.0
            || $confidence > 1.0) {
            throw new InvalidArgumentException('Judge label/confidence is invalid.');
        }
        HarnessPersistenceValue::string($evidence, 'pairwise_judge.evidence', 1, 4000);
        HarnessSensitiveDataGuard::assertSafeText($evidence, 'pairwise_judge.evidence');
        if ($inputTokens < 0 || $inputTokens > 20000000
            || $outputTokens < 0 || $outputTokens > 20000000
            || $latencyMs < 0 || $latencyMs > 86400000) {
            throw new InvalidArgumentException('Judge usage values are outside bounds.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'evidence' => $this->evidence,
            'confidence' => $this->confidence,
            'usage' => [
                'input_tokens' => $this->inputTokens,
                'output_tokens' => $this->outputTokens,
            ],
            'latency_ms' => $this->latencyMs,
        ];
    }

    /** @return array<string,mixed> */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['label', 'evidence', 'confidence'],
            'properties' => [
                'label' => ['type' => 'string', 'enum' => BlindPairwiseProtocol::LABELS],
                'evidence' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }

    private static function integer(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Judge {$field} is invalid.");
        }
        return $value;
    }
}

interface PairwiseJudgeAdapter
{
    public function model(): string;
    public function judge(PairwiseJudgeRequest $request): PairwiseJudgeResponse;
}

interface PairwiseJudgeTokenEstimator
{
    /** @param array<string,mixed> $identityFreePayload */
    public function estimateInputTokens(string $systemPrompt, array $identityFreePayload): int;
}

/** Conservative byte upper bound; deterministic and tokenizer-independent. */
final class ConservativePairwiseJudgeTokenEstimator implements PairwiseJudgeTokenEstimator
{
    public function estimateInputTokens(string $systemPrompt, array $identityFreePayload): int
    {
        $bytes = strlen($systemPrompt)
            + strlen(HarnessPersistenceValue::canonicalJson($identityFreePayload))
            + 256;
        return max(1, $bytes);
    }
}

final readonly class PairwiseJudgePlannedTrial
{
    public function __construct(
        public BlindPairwiseTrial $trial,
        public int $estimatedInputTokens,
        public int $maximumOutputTokens
    ) {
    }
}

final readonly class PairwiseJudgeExecutionPlan
{
    /** @var list<PairwiseJudgePlannedTrial> */
    public array $trials;

    /** @param list<PairwiseJudgePlannedTrial> $trials */
    public function __construct(
        public PairwiseJudgeInputBundle $input,
        array $trials,
        public int $estimatedInputTokens,
        public int $maximumOutputTokens
    ) {
        $this->trials = array_values($trials);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 'harness.eval.pairwise.plan.v1',
            'runner_version' => PairwiseJudgeRunner::VERSION,
            'suite_version' => $this->input->suiteVersion,
            'fixture_version' => $this->input->fixtureVersion,
            'fixture_manifest_hash' => $this->input->fixtureManifestHash,
            'rubric_hash' => $this->input->rubricHash,
            'judge_model' => $this->input->judgeModel,
            'seed' => $this->input->seed,
            'case_count' => count($this->input->cases),
            'request_count' => count($this->trials),
            'fixture_characters' => $this->input->totalCharacters,
            'estimated_input_tokens' => $this->estimatedInputTokens,
            'maximum_output_tokens' => $this->maximumOutputTokens,
            'limits' => $this->input->limits->evidenceArray(),
            'network_requests_made' => 0,
        ];
    }
}

final readonly class PairwiseJudgeRunResult
{
    /** @var array<string,mixed> */
    public array $evidence;

    /** @param array<string,mixed> $evidence */
    public function __construct(
        array $evidence,
        public PairwiseEvidenceBundle $verified,
        public PairwiseJudgeExecutionPlan $plan
    ) {
        $this->evidence = $evidence;
    }
}

final class PairwiseJudgeRunner
{
    public const VERSION = 'pairwise-judge-runner-v1';

    public function __construct(
        private readonly ?PairwiseJudgeAdapter $adapter = null,
        private readonly PairwiseJudgeTokenEstimator $estimator =
            new ConservativePairwiseJudgeTokenEstimator()
    ) {
    }

    public function plan(PairwiseJudgeInputBundle $input): PairwiseJudgeExecutionPlan
    {
        $limits = $input->limits;
        $requestCount = count($input->cases) * $limits->trialsPerCase;
        if ($requestCount > $limits->maxRequests) {
            throw new PairwiseJudgeBudgetExceededException(
                'Pairwise request count exceeds its fixed limit before execution.'
            );
        }
        $maximumOutput = $requestCount * $limits->maxOutputTokensPerRequest;
        if ($maximumOutput > $limits->maxTotalOutputTokens) {
            throw new PairwiseJudgeBudgetExceededException(
                'Pairwise maximum output tokens exceed the fixed total before execution.'
            );
        }

        $planned = [];
        $estimatedInput = 0;
        foreach ($input->cases as $case) {
            for ($trialNumber = 1;
                $trialNumber <= $limits->trialsPerCase;
                $trialNumber++) {
                $trial = BlindPairwiseProtocol::prepare(
                    $case['id'],
                    $case['category'],
                    $trialNumber,
                    $input->baseline['id'],
                    $input->candidate['id'],
                    $case['baseline_content'],
                    $case['candidate_content'],
                    $input->rubric,
                    $input->seed
                );
                $inputTokens = $this->estimator->estimateInputTokens(
                    $input->systemPrompt,
                    $trial->judgePayload()
                );
                if ($inputTokens > $limits->maxInputTokensPerRequest) {
                    throw new PairwiseJudgeBudgetExceededException(
                        'Pairwise estimated request exceeds per-request input tokens.'
                    );
                }
                $estimatedInput += $inputTokens;
                $planned[] = new PairwiseJudgePlannedTrial(
                    $trial,
                    $inputTokens,
                    $limits->maxOutputTokensPerRequest
                );
            }
        }
        if ($estimatedInput > $limits->maxTotalInputTokens) {
            throw new PairwiseJudgeBudgetExceededException(
                'Pairwise estimated input tokens exceed the fixed total before execution.'
            );
        }
        return new PairwiseJudgeExecutionPlan(
            $input,
            $planned,
            $estimatedInput,
            $maximumOutput
        );
    }

    public function run(PairwiseJudgeInputBundle $input): PairwiseJudgeRunResult
    {
        $plan = $this->plan($input);
        if (!$this->adapter instanceof PairwiseJudgeAdapter) {
            throw new LogicException('Live pairwise execution requires a Judge adapter.');
        }
        if (!hash_equals($input->judgeModel, $this->adapter->model())
            || hash_equals($this->adapter->model(), (string)$input->baseline['model'])
            || hash_equals($this->adapter->model(), (string)$input->candidate['model'])) {
            throw new InvalidArgumentException(
                'Pairwise Writer and live Judge model coordinates must be different.'
            );
        }

        $trials = [];
        $actualInput = 0;
        $actualOutput = 0;
        foreach ($plan->trials as $planned) {
            $request = new PairwiseJudgeRequest(
                $planned->trial->judgePayload(),
                $input->judgeModel,
                $input->seed,
                $input->limits->maxOutputTokensPerRequest
            );
            $response = $this->adapter->judge($request);
            // Round-trip through the exact typed representation even for an
            // in-process adapter, preventing accidental extra/untyped fields.
            $response = PairwiseJudgeResponse::fromArray($response->toArray());
            $actualInput += $response->inputTokens;
            $actualOutput += $response->outputTokens;
            if ($response->inputTokens > $input->limits->maxInputTokensPerRequest
                || $actualInput > $input->limits->maxTotalInputTokens
                || $response->outputTokens > $input->limits->maxOutputTokensPerRequest
                || $actualOutput > $input->limits->maxTotalOutputTokens) {
                // No partial PairwiseJudgeRunResult is exposed. CLI persistence
                // only occurs after this method returns and loader self-validates.
                throw new PairwiseJudgeBudgetExceededException(
                    'Judge actual usage exceeded the fixed run budget; evidence rejected.'
                );
            }
            $row = $planned->trial->reveal(
                $response->label,
                $response->evidence,
                $response->confidence,
                $response->latencyMs
            );
            $row['input_tokens'] = $response->inputTokens;
            $row['output_tokens'] = $response->outputTokens;
            $trials[] = $row;
        }

        $judge = [
            'model' => $input->judgeModel,
            'prompt_manifest_hash' => $input->promptManifestHash,
            'runner_version' => self::VERSION,
        ] + $input->limits->evidenceArray();
        $evidence = [
            'schema' => 'harness.eval.pairwise.v2',
            'suite_version' => $input->suiteVersion,
            'fixture_manifest_hash' => $input->fixtureManifestHash,
            'fixture_provenance' => $input->fixtureProvenance,
            'rubric_version' => $input->rubricVersion,
            'rubric_hash' => $input->rubricHash,
            'rubric_dimensions' => $input->rubricDimensions,
            'baseline' => $input->baseline,
            'candidate' => $input->candidate,
            'judge' => $judge,
            'seed' => $input->seed,
            'trials' => $trials,
        ];
        $verified = PairwiseEvidenceLoader::fromArray(
            $evidence,
            $input->baseline['id'],
            $input->candidate['id'],
            $input->seed
        );
        return new PairwiseJudgeRunResult($verified->source, $verified, $plan);
    }
}

/** OpenAI-compatible chat-completions adapter; credentials stay in env only. */
final class OpenAICompatiblePairwiseJudgeAdapter implements PairwiseJudgeAdapter
{
    private function __construct(
        private readonly string $modelName,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $systemPrompt,
        private readonly int $timeoutSeconds
    ) {
    }

    /**
     * @param array<string,mixed> $source
     * @param callable(string):string|false|null $environment
     */
    public static function fromConfig(
        array $source,
        PairwiseJudgeInputBundle $input,
        ?callable $environment = null
    ): self {
        $expected = ['schema', 'base_url', 'api_key_env', 'timeout_seconds'];
        $actual = array_keys($source);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || ($source['schema'] ?? null) !== 'harness.eval.judge_provider.v1'
            || !is_string($source['base_url'] ?? null)
            || !is_string($source['api_key_env'] ?? null)
            || preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $source['api_key_env']) !== 1
            || !is_int($source['timeout_seconds'] ?? null)
            || $source['timeout_seconds'] < 1
            || $source['timeout_seconds'] > 600) {
            throw new InvalidArgumentException('Judge provider config is invalid.');
        }
        $parts = parse_url($source['base_url']);
        $host = is_array($parts) ? normalizeApiUrlHost((string)($parts['host'] ?? '')) : '';
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $localHttp = $scheme === 'http'
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if (!is_array($parts)
            || ($scheme !== 'https' && !$localHttp)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException(
                'Judge provider base_url must be HTTPS or loopback HTTP without credentials.'
            );
        }
        if (!apiUrlHostIsLocal($host) && @inet_pton($host) !== false && ipIsDisallowedForApi($host)) {
            throw new InvalidArgumentException('Judge provider must not use private or reserved addresses.');
        }
        $environment ??= static fn (string $name): string|false => getenv($name);
        $apiKey = $environment($source['api_key_env']);
        if (!is_string($apiKey) || $apiKey === '' || strlen($apiKey) > 4096) {
            throw new RuntimeException('Judge provider credential environment is unavailable.');
        }
        return new self(
            $input->judgeModel,
            rtrim($source['base_url'], '/'),
            $apiKey,
            $input->systemPrompt,
            $source['timeout_seconds']
        );
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function judge(PairwiseJudgeRequest $request): PairwiseJudgeResponse
    {
        if (!hash_equals($this->modelName, $request->model)) {
            throw new InvalidArgumentException('Judge adapter model coordinate changed.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Judge provider requires ext-curl.');
        }
        $body = HarnessPersistenceValue::canonicalJson([
            'model' => $this->modelName,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                [
                    'role' => 'user',
                    'content' => HarnessPersistenceValue::canonicalJson($request->payload),
                ],
            ],
            'temperature' => 0,
            'seed' => $request->seed,
            'max_tokens' => $request->maxOutputTokens,
            'response_format' => ['type' => 'json_object'],
        ]);
        // Share production DNS validation and pin the verified addresses for this request.
        // Resolve immediately before every request, including retries.
        $endpoint = $this->baseUrl . '/chat/completions';
        $resolve = assertPublicApiEndpointResolve($endpoint);
        $protocol = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) === 'http'
            ? CURLPROTO_HTTP : CURLPROTO_HTTPS;
        $handle = curl_init($endpoint);
        if ($handle === false) {
            throw new RuntimeException('Judge provider transport initialization failed.');
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => min(30, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $protocol,
            CURLOPT_REDIR_PROTOCOLS => $protocol,
            // A proxy would resolve independently and bypass the pinned endpoint.
            CURLOPT_PROXY => '',
        ];
        if ($resolve !== []) $options[CURLOPT_RESOLVE] = $resolve;
        $started = hrtime(true);
        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Judge provider transport policy could not be applied.');
            }
            $responseBytes = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if (!is_string($responseBytes) || $status < 200 || $status >= 300) {
                throw new RuntimeException('Judge provider request failed.');
            }
        } finally {
            curl_close($handle);
        }
        $latencyMs = max(0, (int)ceil((hrtime(true) - $started) / 1000000));
        try {
            $outer = json_decode($responseBytes, true, 128, JSON_THROW_ON_ERROR);
            $content = $outer['choices'][0]['message']['content'] ?? null;
            $usage = $outer['usage'] ?? null;
            if (!is_array($outer) || !is_string($content) || !is_array($usage)) {
                throw new InvalidArgumentException('Judge provider response is incomplete.');
            }
            $typed = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($typed)) {
                throw new InvalidArgumentException('Judge model output is not a JSON object.');
            }
            $fields = array_keys($typed);
            sort($fields, SORT_STRING);
            if ($fields !== ['confidence', 'evidence', 'label']) {
                throw new InvalidArgumentException('Judge model output fields are not exact.');
            }
            $inputTokens = $usage['prompt_tokens'] ?? null;
            $outputTokens = $usage['completion_tokens'] ?? null;
            if (!is_int($inputTokens) || !is_int($outputTokens)) {
                throw new InvalidArgumentException('Judge provider usage is not typed.');
            }
            return PairwiseJudgeResponse::fromArray([
                'label' => $typed['label'],
                'evidence' => $typed['evidence'],
                'confidence' => $typed['confidence'],
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                ],
                'latency_ms' => $latencyMs,
            ]);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Judge provider returned malformed JSON.',
                0,
                $exception
            );
        }
    }
}

final class ImmutablePairwiseEvidenceStore
{
    /** @param array<string,mixed> $evidence */
    public static function persist(string $path, array $evidence): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Pairwise evidence output path is invalid.');
        }
        $directory = dirname($path);
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Pairwise evidence directory.');
        }
        $bytes = HarnessPersistenceValue::canonicalJson($evidence) . PHP_EOL;
        if (is_file($path)) {
            $existing = file_get_contents($path);
            if (!is_string($existing) || !hash_equals($bytes, $existing)) {
                throw new HarnessIdempotencyConflictException(
                    'Pairwise evidence path already contains different immutable bytes.'
                );
            }
            return $path;
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Unable to write complete Pairwise evidence bytes.');
            }
            if (!@link($temporary, $path)) {
                if (is_file($path)) {
                    $existing = file_get_contents($path);
                    if (is_string($existing) && hash_equals($bytes, $existing)) {
                        return $path;
                    }
                }
                throw new RuntimeException('Unable to publish Pairwise evidence atomically.');
            }
            return $path;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function __construct()
    {
    }
}
