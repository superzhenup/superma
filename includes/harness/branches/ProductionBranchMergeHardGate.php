<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchMergeService.php';
require_once dirname(__DIR__, 2) . '/quality/CanonicalQualityGate.php';
require_once dirname(__DIR__, 2) . '/quality/WritePolicySnapshot.php';

/**
 * Production merge gate bound to one frozen WritePolicySnapshot and one owned
 * Chapter row. Expensive validators only collect findings; the canonical gate
 * remains the single policy authority.
 */
final class ProductionBranchMergeHardGate implements BranchMergeHardGate
{
    /** @var array<string,mixed> */
    private array $chapter;

    /**
     * @var Closure(int,array<string,mixed>,string,WritePolicySnapshot):array{
     *   hard_findings:list<array<string,mixed>>,
     *   soft_warnings:list<array<string,mixed>>,
     *   evaluator_error:?string
     * }
     */
    private Closure $findingCollector;

    /**
     * @param array<string,mixed> $chapter Exact owned Chapter row.
     * @param null|callable(int,array<string,mixed>,string,WritePolicySnapshot):array{
     *   hard_findings:list<array<string,mixed>>,
     *   soft_warnings:list<array<string,mixed>>,
     *   evaluator_error:?string
     * } $findingCollector
     */
    public function __construct(
        private readonly WritePolicySnapshot $policy,
        array $chapter,
        ?callable $findingCollector = null
    ) {
        $chapterId = (int)($chapter['id'] ?? 0);
        $novelId = (int)($chapter['novel_id'] ?? 0);
        if ($chapterId <= 0 || $novelId <= 0) {
            throw new InvalidArgumentException(
                'Production Branch merge gate requires an owned Chapter coordinate.'
            );
        }
        $this->chapter = $chapter;
        $this->findingCollector = $findingCollector === null
            ? Closure::fromCallable([self::class, 'collectProductionFindings'])
            : Closure::fromCallable($findingCollector);
    }

    public function evaluate(
        string $content,
        string $contentHash,
        ChapterRevisionRecord $source,
        ?ChapterRevisionRecord $target
    ): BranchMergeGateDecision {
        $chapterId = (int)$this->chapter['id'];
        $novelId = (int)$this->chapter['novel_id'];
        if ($source->chapterId !== $chapterId
            || $source->novelId !== $novelId
            || ($target !== null
                && ($target->chapterId !== $chapterId
                    || $target->novelId !== $novelId))) {
            throw new RuntimeException(
                'Branch merge gate Revision coordinates do not match the frozen Chapter.'
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1
            || !hash_equals(hash('sha256', $content), $contentHash)) {
            throw new RuntimeException('Branch merge content hash is invalid or stale.');
        }

        try {
            $collected = ($this->findingCollector)(
                $novelId,
                $this->chapter,
                $content,
                $this->policy
            );
            self::assertCollectorResult($collected);
        } catch (Throwable $exception) {
            $error = self::errorEvidence($exception);
            $collected = [
                'hard_findings' => $this->policy->constraintStrict() ? [[
                    'rule_id' => 'constraint.p0',
                    'message' => 'Branch merge validators are unavailable.',
                    'evidence' => $error,
                ]] : [],
                'soft_warnings' => [],
                'evaluator_error' => 'branch merge validators failed ['
                    . $error['error_fingerprint'] . ']',
            ];
        }

        $decision = CanonicalQualityGate::evaluate(
            $this->policy,
            $content,
            self::wordCount($content),
            $collected['hard_findings'],
            $collected['soft_warnings'],
            $collected['evaluator_error']
        );

        $findings = [];
        foreach ($decision->hardIssues() as $finding) {
            $findings[] = [
                'severity' => 'hard',
                'rule_id' => $finding['rule_id'],
                'message' => $finding['message'],
                'category' => $finding['category'],
                'evidence' => $finding['evidence'],
            ];
        }
        foreach ($decision->softWarnings() as $finding) {
            $findings[] = [
                'severity' => 'warning',
                'rule_id' => $finding['rule_id'],
                'message' => $finding['message'],
                'evidence' => $finding['evidence'],
            ];
        }

        // A merge "hard gate" fails on every finding which the canonical
        // policy classifies as hard, even when a shadow policy would merely
        // observe it. Human approval cannot override a failed hard gate.
        return new BranchMergeGateDecision(!$decision->wouldQuarantine(), $findings);
    }

    /**
     * @param array<string,mixed> $chapter
     * @return array{
     *   hard_findings:list<array<string,mixed>>,
     *   soft_warnings:list<array<string,mixed>>,
     *   evaluator_error:?string
     * }
     */
    private static function collectProductionFindings(
        int $novelId,
        array $chapter,
        string $content,
        WritePolicySnapshot $policy
    ): array {
        $hard = [];
        $soft = [];
        $errors = [];

        try {
            require_once dirname(__DIR__, 2) . '/constraints/ConstraintConfig.php';
            require_once dirname(__DIR__, 2) . '/constraints/ConstraintStateDB.php';
            require_once dirname(__DIR__, 2) . '/constraints/PostWriteValidator.php';
            $validation = (new PostWriteValidator(
                $novelId,
                $chapter,
                $content,
                $policy->targetWords(),
                $policy
            ))->run();

            $validP0 = 0;
            foreach (array_slice((array)($validation['p0_issues'] ?? []), 0, 20) as $issue) {
                $message = is_array($issue)
                    ? trim((string)($issue['issue_desc'] ?? ''))
                    : '';
                if ($message === '') {
                    continue;
                }
                $validP0++;
                $hard[] = [
                    'rule_id' => 'constraint.p0',
                    'message' => self::limit($message, 500),
                    'evidence' => [
                        'validator_issue_type' => self::limit(
                            (string)($issue['constraint_type'] ?? $issue['type'] ?? 'p0'),
                            80
                        ),
                    ],
                ];
            }
            if (!empty($validation['has_p0']) && $validP0 === 0) {
                $hard[] = [
                    'rule_id' => 'constraint.p0',
                    'message' => 'Constraint validator reported P0 without auditable details.',
                    'evidence' => ['validator_contract_error' => 'missing_p0_issue'],
                ];
            }
            foreach (array_slice((array)($validation['p1_issues'] ?? []), 0, 20) as $issue) {
                $message = is_array($issue)
                    ? trim((string)($issue['issue_desc'] ?? ''))
                    : '';
                if ($message === '') {
                    continue;
                }
                $soft[] = [
                    'rule_id' => 'constraint.p1',
                    'message' => self::limit($message, 500),
                    'evidence' => [
                        'validator_issue_type' => self::limit(
                            (string)($issue['constraint_type'] ?? $issue['type'] ?? 'p1'),
                            80
                        ),
                    ],
                ];
            }
        } catch (Throwable $exception) {
            $error = self::errorEvidence($exception);
            if ($policy->constraintStrict()) {
                $hard[] = [
                    'rule_id' => 'constraint.p0',
                    'message' => 'Constraint validation is unavailable for Branch merge.',
                    'evidence' => $error,
                ];
            } else {
                $soft[] = [
                    'rule_id' => 'constraint.validator_unavailable',
                    'message' => 'Constraint validation is unavailable for Branch merge.',
                    'evidence' => $error,
                ];
            }
        }

        if ($policy->qualityCheckEnabled()) {
            try {
                require_once dirname(__DIR__, 2) . '/quality/Gates.php';
                if (function_exists('checkGate6_OutlineAdherence')) {
                    try {
                        $gate = checkGate6_OutlineAdherence($chapter, $content);
                        if (empty($gate['status'])) {
                            $hard[] = [
                                'rule_id' => 'outline.severe_drift',
                                'message' => 'Merged content severely diverges from the Chapter outline.',
                                'evidence' => [
                                    'issues' => self::boundedIssueStrings($gate['issues'] ?? []),
                                ],
                            ];
                        }
                    } catch (Throwable $exception) {
                        $errors[] = 'outline gate failed ['
                            . self::errorEvidence($exception)['error_fingerprint'] . ']';
                    }
                }
                if (function_exists('checkGate5_Consistency')) {
                    try {
                        $gate = checkGate5_Consistency(
                            (int)$chapter['id'],
                            $novelId,
                            $content
                        );
                        $issues = self::boundedIssueStrings($gate['issues'] ?? []);
                        $severe = false;
                        foreach ($issues as $issue) {
                            if (str_contains($issue, '❌')
                                || str_contains(strtolower($issue), 'severe')) {
                                $severe = true;
                                break;
                            }
                        }
                        if (empty($gate['status']) && $severe) {
                            $hard[] = [
                                'rule_id' => 'continuity.severe',
                                'message' => 'Merged content contains a severe continuity violation.',
                                'evidence' => ['issues' => $issues],
                            ];
                        }
                    } catch (Throwable $exception) {
                        $errors[] = 'continuity gate failed ['
                            . self::errorEvidence($exception)['error_fingerprint'] . ']';
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = 'quality gates could not be loaded ['
                    . self::errorEvidence($exception)['error_fingerprint'] . ']';
            }
        }

        return [
            'hard_findings' => $hard,
            'soft_warnings' => $soft,
            'evaluator_error' => $errors === [] ? null : implode('; ', $errors),
        ];
    }

    /** @param array<string,mixed> $result */
    private static function assertCollectorResult(array $result): void
    {
        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        if ($keys !== ['evaluator_error', 'hard_findings', 'soft_warnings']
            || !is_array($result['hard_findings'])
            || !array_is_list($result['hard_findings'])
            || !is_array($result['soft_warnings'])
            || !array_is_list($result['soft_warnings'])
            || (!is_string($result['evaluator_error'])
                && $result['evaluator_error'] !== null)) {
            throw new RuntimeException('Branch merge finding collector returned an invalid contract.');
        }
    }

    /** @return list<string> */
    private static function boundedIssueStrings(mixed $issues): array
    {
        $bounded = [];
        foreach (array_slice((array)$issues, 0, 5) as $issue) {
            $bounded[] = self::limit((string)$issue, 500);
        }
        return $bounded;
    }

    private static function wordCount(string $content): int
    {
        if (!function_exists('countWords')) {
            require_once dirname(__DIR__, 2) . '/helpers.php';
        }
        return function_exists('countWords')
            ? max(0, (int)countWords($content))
            : strlen(preg_replace('/\s+/u', '', $content) ?? $content);
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }

    /** @return array{exception_class:string,error_fingerprint:string} */
    private static function errorEvidence(Throwable $exception): array
    {
        return [
            'exception_class' => get_class($exception),
            'error_fingerprint' => substr(hash(
                'sha256',
                get_class($exception) . "\0" . $exception->getMessage()
            ), 0, 16),
        ];
    }
}
