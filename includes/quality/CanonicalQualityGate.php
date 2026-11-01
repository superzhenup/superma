<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WritePolicySnapshot.php';
require_once __DIR__ . '/QualityGateDecision.php';

/**
 * Pure Candidate quality policy.
 *
 * Expensive validators run outside this class and return findings. This class
 * only applies the frozen chapter policy, word boundaries and hard-rule
 * allowlist; it never reads live configuration or writes persistence state.
 */
final class CanonicalQualityGate
{
    private const RULE_TRUNCATED = 'content.truncated';
    private const RULE_CONSTRAINT_P0 = 'constraint.p0';
    private const RULE_SHORT = 'word_count.minimum';
    private const RULE_GATE_UNAVAILABLE = 'quality.gate_unavailable';

    /** @var list<string> */
    private const ALWAYS_BLOCK_RULES = [
        self::RULE_TRUNCATED,
        self::RULE_CONSTRAINT_P0,
        'review.blocked',
    ];

    /** @var list<string> */
    private const QUALITY_RULES = [
        'outline.severe_drift',
        'continuity.severe',
        self::RULE_GATE_UNAVAILABLE,
    ];

    private function __construct()
    {
    }

    /**
     * @param list<array{rule_id:string,message:string,evidence?:array}> $hardFindings
     * @param list<array{rule_id:string,message:string,evidence?:array}> $softWarnings
     */
    public static function evaluate(
        WritePolicySnapshot $policy,
        string $content,
        int $wordCount,
        array $hardFindings = [],
        array $softWarnings = [],
        ?string $evaluatorError = null,
        ?string $evaluatedAt = null
    ): QualityGateDecision {
        if ($wordCount < 0) {
            throw new InvalidArgumentException('wordCount must be greater than or equal to zero.');
        }

        $hard = [];
        $soft = self::normalizeInputFindings($softWarnings, 'softWarnings');
        $allowlist = array_fill_keys($policy->hardRuleIds(), true);

        if (trim($content) === '' || $wordCount < $policy->hardMinWords()) {
            $hard[] = self::hardIssue(
                self::RULE_TRUNCATED,
                sprintf(
                    '正文疑似截断：%d 字，安全下限 %d 字。',
                    $wordCount,
                    $policy->hardMinWords()
                ),
                'always_block',
                [
                    'actual_words' => $wordCount,
                    'hard_min_words' => $policy->hardMinWords(),
                ]
            );
        } elseif ($policy->blockShortChapter() && $wordCount < $policy->minWords()) {
            self::routeHardFinding(
                self::hardIssue(
                    self::RULE_SHORT,
                    sprintf(
                        '正文 %d 字低于冻结的承诺下限 %d 字。',
                        $wordCount,
                        $policy->minWords()
                    ),
                    'strict_block',
                    [
                        'actual_words' => $wordCount,
                        'min_words' => $policy->minWords(),
                        'target_words' => $policy->targetWords(),
                    ]
                ),
                $allowlist,
                $hard,
                $soft
            );
        }

        if ($wordCount > $policy->maxWords()) {
            $soft[] = [
                'rule_id' => 'word_count.maximum',
                'message' => sprintf('正文 %d 字超过篇幅上限 %d 字；已保留完整剧情，请审阅篇幅。', $wordCount, $policy->maxWords()),
                'evidence' => ['actual_words' => $wordCount, 'max_words' => $policy->maxWords()],
            ];
        }

        foreach (self::normalizeInputFindings($hardFindings, 'hardFindings') as $finding) {
            $ruleId = $finding['rule_id'];
            if ($ruleId === self::RULE_CONSTRAINT_P0 && !$policy->constraintStrict()) {
                $soft[] = self::demote($finding, 'constraint_strict_disabled');
                continue;
            }
            if (in_array($ruleId, self::QUALITY_RULES, true) && !$policy->qualityCheckEnabled()) {
                continue;
            }

            $category = in_array($ruleId, self::ALWAYS_BLOCK_RULES, true)
                ? 'always_block'
                : 'strict_block';
            self::routeHardFinding(
                self::hardIssue($ruleId, $finding['message'], $category, $finding['evidence']),
                $allowlist,
                $hard,
                $soft
            );
        }

        $evaluatorError = $evaluatorError === null ? null : trim($evaluatorError);
        if ($evaluatorError !== null && $evaluatorError !== '' && $policy->qualityCheckEnabled()) {
            $unavailable = self::hardIssue(
                self::RULE_GATE_UNAVAILABLE,
                '质量门执行失败，无法证明候选稿满足 Canon 硬规则。',
                'strict_block',
                ['error' => self::limit($evaluatorError, 500)]
            );
            // Evaluator availability is a meta-safety rule: strict mode cannot
            // prove a Candidate safe when the evaluator itself failed. It is
            // therefore fail-closed even if a stale allowlist omitted the ID.
            if ($policy->canonPolicy() === WritePolicySnapshot::CANON_POLICY_STRICT) {
                $hard[] = $unavailable;
            } else {
                self::routeHardFinding($unavailable, $allowlist, $hard, $soft);
            }
        }

        [$hard, $soft] = self::deduplicate($hard, $soft);

        return QualityGateDecision::decide(
            $policy->canonPolicy(),
            $policy->qualityRuleVersion(),
            hash('sha256', $content),
            $hard,
            $soft,
            'canonical_quality_gate',
            $evaluatedAt
        );
    }

    /**
     * @param array{rule_id:string,message:string,category:string,evidence:array} $finding
     * @param array<string,bool> $allowlist
     * @param list<array{rule_id:string,message:string,category:string,evidence:array}> $hard
     * @param list<array{rule_id:string,message:string,evidence:array}> $soft
     */
    private static function routeHardFinding(
        array $finding,
        array $allowlist,
        array &$hard,
        array &$soft
    ): void {
        if ($finding['category'] === 'always_block' || isset($allowlist[$finding['rule_id']])) {
            $hard[] = $finding;
            return;
        }
        $soft[] = self::demote($finding, 'not_in_frozen_hard_allowlist');
    }

    /**
     * @param list<mixed> $findings
     * @return list<array{rule_id:string,message:string,evidence:array}>
     */
    private static function normalizeInputFindings(array $findings, string $field): array
    {
        if (!array_is_list($findings)) {
            throw new InvalidArgumentException("{$field} must be a list.");
        }
        $normalized = [];
        foreach ($findings as $index => $finding) {
            if (!is_array($finding) || array_is_list($finding)) {
                throw new InvalidArgumentException("{$field}[{$index}] must be an object.");
            }
            $unknown = array_diff(array_keys($finding), ['rule_id', 'message', 'evidence']);
            if ($unknown !== []) {
                throw new InvalidArgumentException("{$field}[{$index}] contains unknown fields.");
            }
            $ruleId = $finding['rule_id'] ?? null;
            $message = $finding['message'] ?? null;
            $evidence = $finding['evidence'] ?? [];
            if (!is_string($ruleId) || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $ruleId)) {
                throw new InvalidArgumentException("{$field}[{$index}].rule_id is invalid.");
            }
            if (!is_string($message) || trim($message) === '') {
                throw new InvalidArgumentException("{$field}[{$index}].message is required.");
            }
            if (!is_array($evidence) || ($evidence !== [] && array_is_list($evidence))) {
                throw new InvalidArgumentException("{$field}[{$index}].evidence must be an object.");
            }
            $normalized[] = [
                'rule_id' => $ruleId,
                'message' => trim($message),
                'evidence' => $evidence,
            ];
        }
        return $normalized;
    }

    /** @return array{rule_id:string,message:string,category:string,evidence:array} */
    private static function hardIssue(
        string $ruleId,
        string $message,
        string $category,
        array $evidence
    ): array {
        return [
            'rule_id' => $ruleId,
            'message' => $message,
            'category' => $category,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array{rule_id:string,message:string,evidence:array}|array{rule_id:string,message:string,category:string,evidence:array} $finding
     * @return array{rule_id:string,message:string,evidence:array}
     */
    private static function demote(array $finding, string $reason): array
    {
        $evidence = $finding['evidence'];
        $evidence['demoted_from_hard'] = true;
        $evidence['demotion_reason'] = $reason;
        return [
            'rule_id' => $finding['rule_id'],
            'message' => $finding['message'],
            'evidence' => $evidence,
        ];
    }

    /**
     * @param list<array{rule_id:string,message:string,category:string,evidence:array}> $hard
     * @param list<array{rule_id:string,message:string,evidence:array}> $soft
     * @return array{0:list<array{rule_id:string,message:string,category:string,evidence:array}>,1:list<array{rule_id:string,message:string,evidence:array}>}
     */
    private static function deduplicate(array $hard, array $soft): array
    {
        $seen = [];
        $uniqueHard = [];
        foreach ($hard as $finding) {
            $key = $finding['rule_id'] . "\0" . $finding['message'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $uniqueHard[] = $finding;
        }

        $uniqueSoft = [];
        foreach ($soft as $finding) {
            $key = $finding['rule_id'] . "\0" . $finding['message'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $uniqueSoft[] = $finding;
        }
        return [$uniqueHard, $uniqueSoft];
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
