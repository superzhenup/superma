<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/DeterministicOfflineHardGate.php';

final class OfflineEvalRunner
{
    public const RUBRIC_VERSION = 'hard-release-rubric-v1';
    public const BASELINE_VERSION = 'approved-workflow-manifest-v1';
    private const RUBRIC_DEFINITION =
        'block every expected hard failure; allow every expected safe fixture; '
        . 'hard recall=1.0; false block rate<=0.02';

    public function __construct(private readonly OfflineHarnessHardGate $gate)
    {
    }

    /**
     * @param list<HarnessEvalCase> $cases
     * @return array<string,mixed>
     */
    public function run(
        array $cases,
        string $suiteVersion,
        array $suiteEvidence,
        array $workflowManifest,
        int $seed = 1
    ): array {
        if ($cases === []) {
            throw new InvalidArgumentException('Offline Eval suite cannot be empty.');
        }
        HarnessPersistenceValue::identifier($suiteVersion, 'eval.suite_version', 64);
        $suiteEvidence = self::validateSuiteEvidence($suiteEvidence);
        $workflowManifest = self::validateWorkflowManifest($workflowManifest);
        HarnessPersistenceValue::nonNegativeInt($seed, 'eval.seed');
        usort($cases, static fn (HarnessEvalCase $a, HarnessEvalCase $b): int =>
            strcmp($a->id, $b->id)
        );
        $rows = [];
        $expectedBlocked = 0;
        $trueBlocked = 0;
        $expectedAllowed = 0;
        $falseBlocked = 0;
        $categories = [];
        foreach ($cases as $case) {
            if (!$case instanceof HarnessEvalCase) {
                throw new InvalidArgumentException('Offline Eval cases must be typed.');
            }
            $decision = $this->gate->evaluate($case);
            $actualCodes = $decision->codes();
            $missingCodes = array_values(array_diff($case->expectedCodes, $actualCodes));
            $passed = $decision->blocked() === $case->expectedBlock && $missingCodes === [];
            if ($case->expectedBlock) {
                $expectedBlocked++;
                if ($decision->blocked()) $trueBlocked++;
            } else {
                $expectedAllowed++;
                if ($decision->blocked()) $falseBlocked++;
            }
            $categories[$case->category] ??= ['total' => 0, 'passed' => 0];
            $categories[$case->category]['total']++;
            if ($passed) $categories[$case->category]['passed']++;
            $rows[] = [
                'case_id' => $case->id,
                'category' => $case->category,
                'expected_block' => $case->expectedBlock,
                'actual_block' => $decision->blocked(),
                'expected_codes' => $case->expectedCodes,
                'actual_codes' => $actualCodes,
                'missing_codes' => $missingCodes,
                'passed' => $passed,
                'findings' => array_map(
                    static fn (HarnessHardFinding $finding): array => $finding->toArray(),
                    $decision->findings
                ),
            ];
        }
        ksort($categories, SORT_STRING);
        $recall = $expectedBlocked === 0 ? 1.0 : $trueBlocked / (float)$expectedBlocked;
        $falseBlockRate = $expectedAllowed === 0
            ? 0.0
            : $falseBlocked / (float)$expectedAllowed;
        $caseHash = hash('sha256', HarnessPersistenceValue::canonicalJson(array_map(
            static fn (HarnessEvalCase $case): array => [
                'id' => $case->id,
                'category' => $case->category,
                'expected_block' => $case->expectedBlock,
                'input' => $case->input,
                'expected_codes' => $case->expectedCodes,
            ],
            $cases
        )));
        $reportId = substr(hash('sha256', implode("\0", [
            'offline-eval-report-v3',
            $suiteVersion,
            $suiteEvidence['source_hash'],
            $workflowManifest['manifest_hash'],
            $this->gate->ruleVersion(),
            self::sourceHash($this->gate),
            self::RUBRIC_VERSION,
            self::rubricHash(),
            self::BASELINE_VERSION,
            (string)$seed,
            $caseHash,
        ])), 0, 32);
        $productionBound = $this->gate instanceof ProductionBoundOfflineHardGate;
        $bindingProvenance = $productionBound
            ? $this->gate->bindingProvenance()
            : [
                'finding_source' => 'evaluator',
                'finding_detector_class' => $this->gate::class,
                'production_finding_detectors_executed' => false,
                'production_decision_gate_executed' => false,
            ];
        return [
            'schema' => 'harness.eval.report.v1',
            'report_id' => $reportId,
            'mode' => 'offline',
            'suite_version' => $suiteVersion,
            'workflow_id' => $workflowManifest['workflow_id'],
            'workflow_version' => $workflowManifest['workflow_version'],
            'rule_version' => $this->gate->ruleVersion(),
            'rubric_version' => self::RUBRIC_VERSION,
            'baseline_version' => self::BASELINE_VERSION,
            'source_revision' => $workflowManifest['manifest_hash'],
            'model' => null,
            'prompt_manifest_hash' => null,
            'seed' => $seed,
            'case_hash' => $caseHash,
            'provenance' => [
                'suite' => $suiteEvidence,
                'workflow' => $workflowManifest,
                'rubric' => [
                    'version' => self::RUBRIC_VERSION,
                    'definition_hash' => self::rubricHash(),
                ],
                'baseline' => [
                    'version' => self::BASELINE_VERSION,
                    'workflow_manifest_hash' => $workflowManifest['manifest_hash'],
                ],
                'source_revision' => $workflowManifest['manifest_hash'],
                'evaluator' => [
                    'class' => $this->gate::class,
                    'source_hash' => self::sourceHash($this->gate),
                    'production_canonical_gate_bound' => $productionBound,
                    'production_gate_class' => CanonicalQualityGate::class,
                    'production_gate_source_hash' => hash_file(
                        'sha256',
                        dirname(__DIR__, 2) . '/quality/CanonicalQualityGate.php'
                    ),
                ] + $bindingProvenance,
            ],
            'summary' => [
                'total' => count($cases),
                'passed' => count(array_filter($rows, static fn (array $r): bool => $r['passed'])),
                'expected_blocked' => $expectedBlocked,
                'hard_recall' => $recall,
                'expected_allowed' => $expectedAllowed,
                'false_block_rate' => $falseBlockRate,
                'release_pass' => $productionBound
                    && $recall === 1.0 && $falseBlockRate <= 0.02
                    && !in_array(false, array_column($rows, 'passed'), true),
            ],
            'categories' => $categories,
            'cases' => $rows,
        ];
    }

    /** @return array{version:string,provenance:string,source_hash:string,cases:list<HarnessEvalCase>} */
    public static function loadSuite(string $path): array
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('Unable to read Eval suite.');
        }
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['schema'] ?? null) !== 'harness.eval.cases.v1'
            || !is_string($decoded['version'] ?? null)
            || !is_string($decoded['provenance'] ?? null)
            || !is_array($decoded['cases'] ?? null)) {
            throw new InvalidArgumentException('Eval suite schema is invalid.');
        }
        $provenance = HarnessPersistenceValue::asciiString(
            $decoded['provenance'],
            'eval.suite_provenance',
            1,
            191
        );
        HarnessSensitiveDataGuard::assertSafeLabel($provenance, 'eval.suite_provenance');
        $cases = array_map(
            static fn (mixed $row): HarnessEvalCase => is_array($row)
                ? HarnessEvalCase::fromArray($row)
                : throw new InvalidArgumentException('Eval suite contains a malformed case.'),
            $decoded['cases']
        );
        $ids = array_map(static fn (HarnessEvalCase $case): string => $case->id, $cases);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Eval case IDs must be unique.');
        }
        return [
            'version' => $decoded['version'],
            'provenance' => $provenance,
            'source_hash' => hash('sha256', $bytes),
            'cases' => $cases,
        ];
    }

    /** @return array<string,mixed> */
    public static function resolveWorkflowManifest(string $root, string $workflowId): array
    {
        if ($workflowId !== 'chapter_generate_v1') {
            throw new InvalidArgumentException('Unknown Eval workflow; arbitrary labels are forbidden.');
        }
        $coordinates = [
            'workflow_id' => $workflowId,
            'workflow_name' => 'chapter_generate',
            'workflow_version' => 'v1',
            'entrypoint' => 'config/workflows/chapter_generate_v1.php',
        ];
        $paths = [
            'bin/eval.php',
            'includes/db/migrations/LeaseClockMigration.php',
            'includes/db/migrations/EventEvolutionMigration.php',
            'config/workflows/chapter_generate_v1.php',
            'evals/cases/hard-v1.json',
            'includes/harness/eval/ApprovedWorkflowManifestGate.php',
            'includes/harness/eval/DeterministicOfflineHardGate.php',
            'includes/harness/eval/EvalContracts.php',
            'includes/harness/eval/OfflineEvalRunner.php',
            'includes/harness/eval/ProductionBoundOfflineHardGate.php',
            'includes/harness/HarnessControlPlane.php',
            'includes/harness/HarnessFeatureSnapshot.php',
            'includes/harness/runtime/CancellationToken.php',
            'includes/harness/runtime/ChapterRunInputSnapshot.php',
            'includes/harness/runtime/ChapterRunService.php',
            'includes/harness/runtime/Deadline.php',
            'includes/harness/runtime/LeaseHeartbeat.php',
            'includes/harness/runtime/M62WorkflowRuntimeStore.php',
            'includes/harness/runtime/RetryPolicy.php',
            'includes/harness/runtime/RunBudget.php',
            'includes/harness/runtime/RunContext.php',
            'includes/harness/runtime/RunRequest.php',
            'includes/harness/runtime/RunStage.php',
            'includes/harness/runtime/RuntimeData.php',
            'includes/harness/runtime/RuntimeExceptions.php',
            'includes/harness/runtime/StageResult.php',
            'includes/harness/runtime/WorkflowDefinition.php',
            'includes/harness/runtime/WorkflowFaultPoints.php',
            'includes/harness/runtime/WorkflowRuntimeStore.php',
            'includes/harness/runtime/WorkflowRunner.php',
            'includes/harness/review/ForkedReviewExecutor.php',
            'includes/harness/review/PostGenerationReviewBridge.php',
            'includes/harness/review/PostGenerationReviewContracts.php',
            'includes/harness/review/ProductionReviewFindingDetector.php',
            'includes/harness/review/ReviewAggregator.php',
            'includes/harness/review/ReviewContracts.php',
            'includes/harness/review/ReviewCoordinator.php',
            'includes/harness/review/RoleReviewers.php',
            'includes/harness/stages/LegacyAcquireStage.php',
            'includes/harness/stages/LegacyChapterStageAdapter.php',
            'includes/harness/stages/LegacyCommitStage.php',
            'includes/harness/stages/LegacyContextStage.php',
            'includes/harness/stages/LegacyGenerateStage.php',
            'includes/harness/stages/LegacyPostprocessStage.php',
            'includes/harness/stages/LegacyPromptStage.php',
            'includes/harness/stages/LegacyWriteEngineStageAdapter.php',
            'includes/harness/review/ProductionPostGenerationReviewFactory.php',
            'includes/write_engine.php',
            'includes/constraints/PostWriteValidator.php',
            'includes/quality/CanonicalQualityGate.php',
            'includes/quality/Gates.php',
            'includes/quality/WritePolicySnapshot.php',
            'includes/quality/WritePolicySnapshotFactory.php',
        ];
        // Bind the implementation behind every optional chapter_generate
        // feature, not merely the top-level adapter that calls it. Otherwise
        // a dependency-only change (for example Canon recovery, tournament,
        // Prompt budgeting, Tool policy, or Review effects) could retain an
        // approved manifest even though production behavior changed.
        foreach ([
            'includes/agents',
            'includes/ai',
            'includes/author',
            'includes/constraints',
            'includes/drama',
            'includes/harness/artifacts',
            'includes/harness/branches',
            'includes/harness/candidates',
            'includes/harness/context',
            'includes/harness/events',
            'includes/harness/model',
            'includes/harness/persistence',
            'includes/harness/prompt',
            'includes/harness/retention',
            'includes/harness/review',
            'includes/harness/runtime',
            'includes/harness/side_effects',
            'includes/harness/skills',
            'includes/harness/stages',
            'includes/harness/support',
            'includes/harness/tools',
            'includes/memory',
            'includes/quality',
            'includes/tasks',
        ] as $relativeDirectory) {
            $directory = rtrim($root, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
            if (!is_dir($directory)) {
                throw new RuntimeException(
                    "Eval workflow source directory is missing: {$relativeDirectory}"
                );
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo
                    || !$file->isFile()
                    || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $absolute = str_replace('\\', '/', $file->getPathname());
                $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
                $prefix = $normalizedRoot . '/';
                if (!str_starts_with($absolute, $prefix)) {
                    throw new RuntimeException(
                        'Eval workflow source escaped the project root.'
                    );
                }
                $paths[] = substr($absolute, strlen($prefix));
            }
        }
        $skillRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR
            . 'includes' . DIRECTORY_SEPARATOR . 'skills';
        if (!is_dir($skillRoot)) {
            throw new RuntimeException('Eval workflow Skill source directory is missing.');
        }
        $skillIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $skillRoot,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($skillIterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()
                || !in_array(strtolower($file->getExtension()), ['json', 'md'], true)) {
                continue;
            }
            $absolute = str_replace('\\', '/', $file->getPathname());
            $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
            $prefix = $normalizedRoot . '/';
            if (!str_starts_with($absolute, $prefix)) {
                throw new RuntimeException('Eval Skill source escaped the project root.');
            }
            $paths[] = substr($absolute, strlen($prefix));
        }
        $includesRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'includes';
        $topLevelSources = glob($includesRoot . DIRECTORY_SEPARATOR . '*.php');
        if ($topLevelSources === false) {
            throw new RuntimeException('Eval workflow top-level sources cannot be enumerated.');
        }
        foreach ($topLevelSources as $absolute) {
            $paths[] = 'includes/' . basename($absolute);
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $relative) {
            $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($hash)) {
                throw new RuntimeException("Eval workflow source is missing: {$relative}");
            }
            $files[$relative] = $hash;
        }
        ksort($files, SORT_STRING);
        $payload = $coordinates + ['files' => $files];
        return $payload + [
            'manifest_hash' => hash(
                'sha256',
                HarnessPersistenceValue::canonicalJson($payload)
            ),
        ];
    }

    /** @param array<string,mixed> $evidence @return array{provenance:string,source_hash:string} */
    private static function validateSuiteEvidence(array $evidence): array
    {
        if (array_keys($evidence) !== ['provenance', 'source_hash']) {
            throw new InvalidArgumentException('Eval suite evidence fields are invalid.');
        }
        $provenance = HarnessPersistenceValue::asciiString(
            $evidence['provenance'] ?? null,
            'eval.suite_provenance',
            1,
            191
        );
        HarnessSensitiveDataGuard::assertSafeLabel($provenance, 'eval.suite_provenance');
        return [
            'provenance' => $provenance,
            'source_hash' => HarnessPersistenceValue::hash(
                $evidence['source_hash'] ?? null,
                'eval.suite_source_hash'
            ),
        ];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private static function validateWorkflowManifest(array $manifest): array
    {
        foreach ([
            'workflow_id', 'workflow_name', 'workflow_version',
            'entrypoint', 'files', 'manifest_hash',
        ] as $field) {
            if (!array_key_exists($field, $manifest)) {
                throw new InvalidArgumentException("Eval workflow manifest is missing {$field}.");
            }
        }
        if (!is_array($manifest['files']) || $manifest['files'] === []) {
            throw new InvalidArgumentException('Eval workflow manifest files are invalid.');
        }
        $hash = HarnessPersistenceValue::hash(
            $manifest['manifest_hash'],
            'eval.workflow_manifest_hash'
        );
        $payload = $manifest;
        unset($payload['manifest_hash']);
        if (!hash_equals(
            $hash,
            hash('sha256', HarnessPersistenceValue::canonicalJson($payload))
        )) {
            throw new InvalidArgumentException('Eval workflow manifest checksum mismatch.');
        }
        return $manifest;
    }

    private static function sourceHash(object $object): string
    {
        $file = (new ReflectionClass($object))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (!is_string($hash)) {
            throw new RuntimeException('Eval evaluator source cannot be hashed.');
        }
        return $hash;
    }

    private static function rubricHash(): string
    {
        return hash('sha256', self::RUBRIC_DEFINITION);
    }
}
