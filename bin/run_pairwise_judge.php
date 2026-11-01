<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

/** @return never */
function pairwiseJudgeUsage(int $code = 0, bool $json = false): void
{
    $usage = "Usage:\n"
        . "  php bin/run_pairwise_judge.php --input=<bundle.json> --seed=<n> --plan [--json]\n"
        . "  php bin/run_pairwise_judge.php --input=<bundle.json> --seed=<n> "
        . "--provider-config=<provider.json> --output=<evidence.json> [--json]\n"
        . "--dry-run is an alias for --plan. Provider credentials are read only from the "
        . "environment variable named by provider config; no credential CLI option exists.";
    $stream = $code === 0 ? STDOUT : STDERR;
    if ($json) {
        fwrite($stream, json_encode([
            'ok' => $code === 0,
            'error' => $code === 0 ? null : 'invalid_arguments',
            'usage' => $usage,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite($stream, $usage . PHP_EOL);
    }
    exit($code);
}

/** @return array<string,mixed> */
function pairwiseJudgeReadJson(string $path, string $label): array
{
    if ($path === '' || str_contains($path, "\0") || !is_file($path)) {
        throw new InvalidArgumentException("{$label} file is unavailable.");
    }
    $size = filesize($path);
    if (!is_int($size) || $size <= 0 || $size > 25000000) {
        throw new InvalidArgumentException("{$label} file size is outside bounds.");
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        throw new RuntimeException("{$label} file is unreadable.");
    }
    $decoded = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException("{$label} must be a JSON object.");
    }
    return $decoded;
}

$arguments = array_slice($argv, 1);
$json = in_array('--json', $arguments, true);
$options = [
    'input' => null,
    'seed' => null,
    'provider-config' => null,
    'output' => null,
    'plan' => false,
    'dry-run' => false,
    'json' => false,
];
$seen = [];
foreach ($arguments as $argument) {
    if ($argument === '--help') {
        pairwiseJudgeUsage(0, $json);
    }
    if (in_array($argument, ['--plan', '--dry-run', '--json'], true)) {
        $key = substr($argument, 2);
        if (isset($seen[$key])) {
            pairwiseJudgeUsage(2, $json);
        }
        $seen[$key] = true;
        $options[$key] = true;
        continue;
    }
    if (preg_match('/^--(input|seed|provider-config|output)=(.+)$/D', $argument, $match) !== 1
        || isset($seen[$match[1]])) {
        pairwiseJudgeUsage(2, $json);
    }
    $seen[$match[1]] = true;
    $options[$match[1]] = $match[2];
}
if (!is_string($options['input'])
    || !is_string($options['seed'])
    || preg_match('/^(?:0|[1-9][0-9]*)$/D', $options['seed']) !== 1) {
    pairwiseJudgeUsage(2, $json);
}
$seed = filter_var($options['seed'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
]);
if (!is_int($seed)) {
    pairwiseJudgeUsage(2, $json);
}
$planOnly = $options['plan'] === true || $options['dry-run'] === true;
if ($options['plan'] === true && $options['dry-run'] === true) {
    pairwiseJudgeUsage(2, $json);
}
if ($planOnly
    ? ($options['provider-config'] !== null || $options['output'] !== null)
    : (!is_string($options['provider-config']) || !is_string($options['output']))) {
    pairwiseJudgeUsage(2, $json);
}

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/harness/eval/bootstrap.php';

try {
    $inputSource = pairwiseJudgeReadJson($options['input'], 'Pairwise input');
    $input = PairwiseJudgeInputBundle::fromArray($inputSource, $seed);
    // Full worst-case planning is always completed before provider config is
    // loaded or any adapter can issue a network request.
    $plan = (new PairwiseJudgeRunner())->plan($input);
    if ($planOnly) {
        $payload = ['ok' => true, 'mode' => 'plan', 'plan' => $plan->toArray()];
        if ($json) {
            echo json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } else {
            printf(
                "Pairwise plan OK: cases=%d requests=%d (no network)\n",
                $payload['plan']['case_count'],
                $payload['plan']['request_count']
            );
        }
        exit(0);
    }

    $provider = pairwiseJudgeReadJson(
        $options['provider-config'],
        'Judge provider config'
    );
    $adapter = OpenAICompatiblePairwiseJudgeAdapter::fromConfig($provider, $input);
    $result = (new PairwiseJudgeRunner($adapter))->run($input);
    $path = ImmutablePairwiseEvidenceStore::persist(
        $options['output'],
        $result->evidence
    );
    $payload = [
        'ok' => true,
        'mode' => 'live',
        'external_provider_acceptance_required' => true,
        'evidence_path' => $path,
        'evidence_hash' => $result->verified->sourceHash(),
        'trial_count' => count($result->verified->trials),
    ];
    if ($json) {
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        printf(
            "Pairwise Judge completed %d trials; evidence=%s; external provider acceptance remains required.\n",
            $payload['trial_count'],
            $path
        );
    }
    exit(0);
} catch (PairwiseJudgeBudgetExceededException $exception) {
    $error = [
        'ok' => false,
        'error' => 'pairwise_budget_exceeded',
        'evidence_accepted' => false,
        'message' => $exception->getMessage(),
    ];
} catch (Throwable $exception) {
    $error = [
        'ok' => false,
        'error' => 'pairwise_judge_failed',
        'evidence_accepted' => false,
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ];
}
fwrite(
    STDERR,
    ($json
        ? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : 'Pairwise Judge failed: ' . $error['message']) . PHP_EOL
);
exit(2);
