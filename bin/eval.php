<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI mode only\n");
}

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/harness/eval/bootstrap.php';

/** @return never */
function harnessEvalUsage(int $code = 0): void
{
    $text = "Usage:\n"
        . "  php bin/eval.php --suite=hard --workflow=chapter_generate_v1 --offline [--seed=1] [--json] [--no-write]\n"
        . "  php bin/eval.php --suite=pairwise --baseline=<id> --candidate=<id> [--annotations=<id>] [--seed=1] [--json] [--no-write]\n"
        . "  php bin/eval.php --approve=chapter_generate_v1 --expected=<64-hex-current-manifest> [--json]\n"
        . "  php bin/eval.php --report=<32-hex-id> --format=json|html\n";
    fwrite($code === 0 ? STDOUT : STDERR, $text);
    exit($code);
}

$options = [
    'suite' => null,
    'workflow' => 'chapter_generate_v1',
    'offline' => false,
    'json' => false,
    'no_write' => false,
    'seed' => 1,
    'baseline' => null,
    'candidate' => null,
    'annotations' => null,
    'report' => null,
    'format' => 'json',
    'approve' => null,
    'expected' => null,
];
$seen = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') harnessEvalUsage(0);
    if ($argument === '--offline' || $argument === '--json' || $argument === '--no-write') {
        $key = str_replace('-', '_', substr($argument, 2));
        if (isset($seen[$key])) harnessEvalUsage(2);
        $seen[$key] = true;
        $options[$key] = true;
        continue;
    }
    if (preg_match('/^--([a-z]+)=(.*)$/D', $argument, $match) !== 1
        || !array_key_exists($match[1], $options) || isset($seen[$match[1]])) {
        harnessEvalUsage(2);
    }
    $key = $match[1];
    $seen[$key] = true;
    $options[$key] = $match[2];
}
if (is_string($options['seed'])) {
    if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $options['seed']) !== 1) harnessEvalUsage(2);
    $options['seed'] = filter_var($options['seed'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
    ]);
}
if (!is_int($options['seed'])) harnessEvalUsage(2);

$root = dirname(__DIR__);
$reportsDirectory = $root . '/evals/reports';

try {
    if ($options['approve'] === null && $options['expected'] !== null) {
        harnessEvalUsage(2);
    }
    if (is_string($options['approve'])) {
        if ($options['suite'] !== null || $options['report'] !== null
            || $options['baseline'] !== null || $options['candidate'] !== null
            || $options['annotations'] !== null || $options['offline'] === true
            || $options['no_write'] === true
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $options['approve']) !== 1
            || !is_string($options['expected'])
            || preg_match('/^[a-f0-9]{64}$/D', $options['expected']) !== 1) {
            harnessEvalUsage(2);
        }
        $manifest = OfflineEvalRunner::resolveWorkflowManifest($root, $options['approve']);
        $approval = ApprovedWorkflowManifestGate::approve(
            $root . '/evals/approved/' . $options['approve'] . '.json',
            $manifest,
            $options['expected']
        );
        if ($options['json'] === true) {
            echo json_encode(
                ['ok' => true, 'approval' => $approval],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } else {
            printf(
                "Approved Eval workflow %s manifest %s.\n",
                $options['approve'],
                $approval['manifest_hash']
            );
        }
        exit(0);
    }

    if (is_string($options['report'])) {
        if (preg_match('/^[a-f0-9]{32}$/D', $options['report']) !== 1
            || !in_array($options['format'], ['json', 'html'], true)) {
            harnessEvalUsage(2);
        }
        $path = $reportsDirectory . '/' . $options['report'] . '.json';
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) throw new RuntimeException('Eval report not found.');
        $report = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if ($options['format'] === 'json') {
            echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            $summary = $report['summary'] ?? [];
            $title = htmlspecialchars('Harness Eval ' . $options['report'], ENT_QUOTES, 'UTF-8');
            $body = htmlspecialchars(
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ENT_QUOTES,
                'UTF-8'
            );
            echo "<!doctype html><meta charset=\"utf-8\"><title>{$title}</title>"
                . "<h1>{$title}</h1><pre>{$body}</pre>" . PHP_EOL;
        }
        exit(0);
    }

    if ($options['suite'] === 'hard') {
        if ($options['offline'] !== true || !is_string($options['workflow'])
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $options['workflow']) !== 1) {
            throw new InvalidArgumentException('Hard suite requires --offline and a valid workflow.');
        }
        $suite = OfflineEvalRunner::loadSuite($root . '/evals/cases/hard-v1.json');
        $workflowManifest = OfflineEvalRunner::resolveWorkflowManifest(
            $root,
            $options['workflow']
        );
        $report = (new OfflineEvalRunner(new ProductionBoundOfflineHardGate()))->run(
            $suite['cases'],
            $suite['version'],
            [
                'provenance' => $suite['provenance'],
                'source_hash' => $suite['source_hash'],
            ],
            $workflowManifest,
            $options['seed']
        );
        $report = ApprovedWorkflowManifestGate::applyToReport(
            $report,
            ApprovedWorkflowManifestGate::inspect(
                $root . '/evals/approved/' . $options['workflow'] . '.json',
                $workflowManifest
            )
        );
    } elseif ($options['suite'] === 'pairwise') {
        foreach (['baseline', 'candidate'] as $field) {
            if (!is_string($options[$field])
                || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $options[$field]) !== 1) {
                throw new InvalidArgumentException('Pairwise suite requires safe baseline/candidate IDs.');
            }
        }
        if ($options['annotations'] !== null
            && (!is_string($options['annotations'])
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62}[A-Za-z0-9])?$/D',
                    $options['annotations']) !== 1)) {
            throw new InvalidArgumentException('Pairwise annotations require a safe evidence ID.');
        }
        $pairPath = $root . '/evals/baselines/'
            . $options['baseline'] . '--' . $options['candidate'] . '.json';
        if (!is_file($pairPath)) {
            throw new RuntimeException(
                'Pairwise outcomes are absent; provide an explicitly budgeted judge run first.'
            );
        }
        $pairBytes = file_get_contents($pairPath);
        if (!is_string($pairBytes)) throw new RuntimeException('Pairwise evidence is unreadable.');
        $pair = json_decode($pairBytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($pair)) {
            throw new InvalidArgumentException('Pairwise v2 evidence must be a JSON object.');
        }
        $bundle = PairwiseEvidenceLoader::fromArray(
            $pair,
            $options['baseline'],
            $options['candidate'],
            $options['seed']
        );

        $annotationEvidence = null;
        $annotationStatus = 'not_requested';
        if (is_string($options['annotations'])) {
            $annotationPath = $root . '/evals/annotations/' . $options['annotations'] . '.json';
            if (is_file($annotationPath)) {
                $annotationBytes = file_get_contents($annotationPath);
                if (!is_string($annotationBytes)) {
                    throw new RuntimeException('Human annotation evidence is unreadable.');
                }
                $annotationEvidence = json_decode(
                    $annotationBytes,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($annotationEvidence)) {
                    throw new InvalidArgumentException(
                        'Human annotation evidence must be a JSON object.'
                    );
                }
                $annotationStatus = 'loaded';
            } else {
                // Missing human evidence is a release failure, not fabricated calibration.
                $annotationStatus = 'missing';
            }
        }

        $summary = PairwiseReleaseGate::summarize(
            $bundle,
            $options['seed'],
            $annotationEvidence
        );
        $annotationSourceHash = $summary['human_calibration']['annotation_source_hash'] ?? null;
        $reportId = substr(hash('sha256', HarnessPersistenceValue::canonicalJson([
            'schema' => 'harness.eval.report.v1',
            'mode' => 'pairwise',
            'baseline' => $options['baseline'],
            'candidate' => $options['candidate'],
            'seed' => $options['seed'],
            'pairwise_source_hash' => $bundle->sourceHash(),
            'annotation_source_hash' => $annotationSourceHash,
        ])), 0, 32);
        $report = [
            'schema' => 'harness.eval.report.v1',
            'report_id' => $reportId,
            'mode' => 'pairwise',
            'baseline' => $options['baseline'],
            'candidate' => $options['candidate'],
            'seed' => $options['seed'],
            'provenance' => [
                'pairwise_source_hash' => $bundle->sourceHash(),
                'pairwise' => $bundle->provenance(),
                'annotations_id' => $options['annotations'],
                'annotations_status' => $annotationStatus,
                'annotation_source_hash' => $annotationSourceHash,
            ],
            'summary' => $summary,
        ];
    } else {
        harnessEvalUsage(2);
    }

    if ($options['no_write'] !== true) {
        ImmutableEvalReportStore::persist($reportsDirectory, $report);
    }

    if ($options['json'] === true) {
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        printf(
            "Eval %s: %s (report %s)\n",
            $report['mode'],
            ($report['summary']['release_pass'] ?? false) ? 'PASS' : 'FAIL',
            $report['report_id']
        );
    }
    exit(($report['summary']['release_pass'] ?? false) ? 0 : 1);
} catch (Throwable $exception) {
    $error = [
        'ok' => false,
        'error' => 'eval_failed',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ];
    fwrite(
        STDERR,
        ($options['json'] === true
            ? json_encode($error, JSON_UNESCAPED_UNICODE)
            : 'Eval failed: ' . $exception->getMessage()) . PHP_EOL
    );
    exit(2);
}
