<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Immutable result of the Canon quality gate.
 *
 * The decision deliberately contains no database or configuration access. A
 * caller must supply the policy mode and the complete, already-evaluated issue
 * set so the same input always produces the same disposition.
 */
final class QualityGateDecision
{
    public const DISPOSITION_ALLOW = 'allow';
    public const DISPOSITION_ALLOW_WITH_WARNINGS = 'allow_with_warnings';
    public const DISPOSITION_QUARANTINE = 'quarantine';

    public const POLICY_LEGACY_WARN = 'legacy_warn';
    public const POLICY_SHADOW = 'shadow';
    public const POLICY_STRICT = 'strict';

    private const SERIALIZED_FIELDS = [
        'disposition',
        'would_quarantine',
        'hard_issues',
        'soft_warnings',
        'policy_mode',
        'policy_version',
        'content_hash',
        'evaluated_at',
        'evaluator',
    ];

    /** @var list<array{rule_id:string,message:string,category:string,evidence:array}> */
    private readonly array $hardIssues;

    /** @var list<array{rule_id:string,message:string,evidence:array}> */
    private readonly array $softWarnings;

    private function __construct(
        private readonly string $disposition,
        private readonly bool $wouldQuarantine,
        array $hardIssues,
        array $softWarnings,
        private readonly string $policyMode,
        private readonly string $policyVersion,
        private readonly string $contentHash,
        private readonly string $evaluatedAt,
        private readonly string $evaluator
    ) {
        self::assertDisposition($disposition);
        self::assertPolicyMode($policyMode);
        self::assertNonEmptyToken($policyVersion, 'policy_version', 64);
        self::assertSha256($contentHash);
        self::assertTimestamp($evaluatedAt);
        self::assertNonEmptyToken($evaluator, 'evaluator', 120);

        $this->hardIssues = self::normalizeHardIssues($hardIssues);
        $this->softWarnings = self::normalizeSoftWarnings($softWarnings);
        $hasHardIssues = $this->hardIssues !== [];
        $hasAlwaysBlock = self::containsAlwaysBlock($this->hardIssues);

        if ($wouldQuarantine !== $hasHardIssues) {
            throw new InvalidArgumentException(
                'would_quarantine must be true exactly when hard_issues is non-empty.'
            );
        }
        if ($disposition === self::DISPOSITION_QUARANTINE
            && (!$hasHardIssues || ($policyMode !== self::POLICY_STRICT && !$hasAlwaysBlock))) {
            throw new InvalidArgumentException(
                'quarantine requires a hard issue plus strict policy or an always_block issue.'
            );
        }
        if ((($policyMode === self::POLICY_STRICT && $hasHardIssues) || $hasAlwaysBlock)
            && $disposition !== self::DISPOSITION_QUARANTINE) {
            throw new InvalidArgumentException(
                'Strict hard issues and always_block issues must quarantine the candidate.'
            );
        }
        if (($hasHardIssues || $this->softWarnings !== [])
            && $disposition === self::DISPOSITION_ALLOW) {
            throw new InvalidArgumentException('A decision with issues cannot use allow disposition.');
        }
        if (!$hasHardIssues && $this->softWarnings === []
            && $disposition !== self::DISPOSITION_ALLOW) {
            throw new InvalidArgumentException('An issue-free decision must use allow disposition.');
        }
    }

    /**
     * @param list<array{rule_id:string,message:string,category?:string,evidence?:array}> $hardIssues
     * @param list<array{rule_id:string,message:string,evidence?:array}> $softWarnings
     */
    public static function decide(
        string $policyMode,
        string $policyVersion,
        string $contentHash,
        array $hardIssues = [],
        array $softWarnings = [],
        string $evaluator = 'canonical_quality_gate',
        ?string $evaluatedAt = null
    ): self {
        self::assertPolicyMode($policyMode);
        $normalizedHardIssues = self::normalizeHardIssues($hardIssues);
        $hasHardIssues = $normalizedHardIssues !== [];
        $mustQuarantine = $hasHardIssues
            && ($policyMode === self::POLICY_STRICT || self::containsAlwaysBlock($normalizedHardIssues));
        $disposition = $mustQuarantine
            ? self::DISPOSITION_QUARANTINE
            : (($hasHardIssues || $softWarnings !== [])
                ? self::DISPOSITION_ALLOW_WITH_WARNINGS
                : self::DISPOSITION_ALLOW);

        return new self(
            $disposition,
            $hasHardIssues,
            $normalizedHardIssues,
            $softWarnings,
            $policyMode,
            $policyVersion,
            strtolower($contentHash),
            $evaluatedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            $evaluator
        );
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactFields($payload);

        foreach (['disposition', 'policy_mode', 'policy_version', 'content_hash', 'evaluated_at', 'evaluator'] as $field) {
            if (!is_string($payload[$field])) {
                throw new InvalidArgumentException("{$field} must be a string.");
            }
        }
        if (!is_bool($payload['would_quarantine'])) {
            throw new InvalidArgumentException('would_quarantine must be a boolean.');
        }
        if (!is_array($payload['hard_issues']) || !array_is_list($payload['hard_issues'])) {
            throw new InvalidArgumentException('hard_issues must be a list.');
        }
        if (!is_array($payload['soft_warnings']) || !array_is_list($payload['soft_warnings'])) {
            throw new InvalidArgumentException('soft_warnings must be a list.');
        }

        return new self(
            $payload['disposition'],
            $payload['would_quarantine'],
            $payload['hard_issues'],
            $payload['soft_warnings'],
            $payload['policy_mode'],
            $payload['policy_version'],
            strtolower($payload['content_hash']),
            $payload['evaluated_at'],
            $payload['evaluator']
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid quality decision JSON.', 0, $e);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Quality decision JSON must contain an object.');
        }
        return self::fromArray($payload);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'disposition' => $this->disposition,
            'would_quarantine' => $this->wouldQuarantine,
            'hard_issues' => $this->hardIssues,
            'soft_warnings' => $this->softWarnings,
            'policy_mode' => $this->policyMode,
            'policy_version' => $this->policyVersion,
            'content_hash' => $this->contentHash,
            'evaluated_at' => $this->evaluatedAt,
            'evaluator' => $this->evaluator,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public function disposition(): string { return $this->disposition; }
    public function wouldQuarantine(): bool { return $this->wouldQuarantine; }
    public function shouldQuarantine(): bool { return $this->disposition === self::DISPOSITION_QUARANTINE; }

    /** @return list<array{rule_id:string,message:string,category:string,evidence:array}> */
    public function hardIssues(): array { return $this->hardIssues; }

    /** @return list<array{rule_id:string,message:string,evidence:array}> */
    public function softWarnings(): array { return $this->softWarnings; }

    public function policyMode(): string { return $this->policyMode; }
    public function policyVersion(): string { return $this->policyVersion; }
    public function contentHash(): string { return $this->contentHash; }
    public function evaluatedAt(): string { return $this->evaluatedAt; }
    public function evaluator(): string { return $this->evaluator; }

    /** @param array<string,mixed> $payload */
    private static function assertExactFields(array $payload): void
    {
        $actual = array_keys($payload);
        $missing = array_values(array_diff(self::SERIALIZED_FIELDS, $actual));
        $unknown = array_values(array_diff($actual, self::SERIALIZED_FIELDS));
        if ($missing !== [] || $unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Quality decision fields mismatch; missing=[%s] unknown=[%s].',
                implode(',', $missing),
                implode(',', $unknown)
            ));
        }
    }

    /**
     * @param list<mixed> $issues
     * @return list<array{rule_id:string,message:string,category:string,evidence:array}>
     */
    private static function normalizeHardIssues(array $issues): array
    {
        if (!array_is_list($issues)) {
            throw new InvalidArgumentException('hard_issues must be a list.');
        }
        $normalized = [];
        foreach ($issues as $index => $issue) {
            if (!is_array($issue) || array_is_list($issue)) {
                throw new InvalidArgumentException("hard_issues[{$index}] must be an object.");
            }
            $unknown = array_diff(array_keys($issue), ['rule_id', 'message', 'category', 'evidence']);
            if ($unknown !== []) {
                throw new InvalidArgumentException("hard_issues[{$index}] contains unknown fields.");
            }
            $ruleId = $issue['rule_id'] ?? null;
            $message = $issue['message'] ?? null;
            $category = $issue['category'] ?? 'strict_block';
            $evidence = $issue['evidence'] ?? [];
            if (!is_string($ruleId) || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $ruleId)) {
                throw new InvalidArgumentException("hard_issues[{$index}].rule_id is invalid.");
            }
            if (!is_string($message) || trim($message) === '') {
                throw new InvalidArgumentException("hard_issues[{$index}].message is required.");
            }
            if (!is_string($category) || !in_array($category, ['always_block', 'strict_block'], true)) {
                throw new InvalidArgumentException("hard_issues[{$index}].category is invalid.");
            }
            if (!is_array($evidence) || ($evidence !== [] && array_is_list($evidence))) {
                throw new InvalidArgumentException("hard_issues[{$index}].evidence must be an object.");
            }
            $normalized[] = [
                'rule_id' => $ruleId,
                'message' => trim($message),
                'category' => $category,
                'evidence' => self::sortObject($evidence),
            ];
        }
        return $normalized;
    }

    /**
     * @param list<mixed> $issues
     * @return list<array{rule_id:string,message:string,evidence:array}>
     */
    private static function normalizeSoftWarnings(array $issues): array
    {
        if (!array_is_list($issues)) {
            throw new InvalidArgumentException('soft_warnings must be a list.');
        }
        $normalized = [];
        foreach ($issues as $index => $issue) {
            if (!is_array($issue) || array_is_list($issue)) {
                throw new InvalidArgumentException("soft_warnings[{$index}] must be an object.");
            }
            $unknown = array_diff(array_keys($issue), ['rule_id', 'message', 'evidence']);
            if ($unknown !== []) {
                throw new InvalidArgumentException("soft_warnings[{$index}] contains unknown fields.");
            }
            $ruleId = $issue['rule_id'] ?? null;
            $message = $issue['message'] ?? null;
            $evidence = $issue['evidence'] ?? [];
            if (!is_string($ruleId) || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $ruleId)) {
                throw new InvalidArgumentException("soft_warnings[{$index}].rule_id is invalid.");
            }
            if (!is_string($message) || trim($message) === '') {
                throw new InvalidArgumentException("soft_warnings[{$index}].message is required.");
            }
            if (!is_array($evidence) || ($evidence !== [] && array_is_list($evidence))) {
                throw new InvalidArgumentException("soft_warnings[{$index}].evidence must be an object.");
            }
            $normalized[] = [
                'rule_id' => $ruleId,
                'message' => trim($message),
                'evidence' => self::sortObject($evidence),
            ];
        }
        return $normalized;
    }

    /** @param list<array{rule_id:string,message:string,category:string,evidence:array}> $issues */
    private static function containsAlwaysBlock(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['category'] ?? '') === 'always_block') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function sortObject(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item) && !array_is_list($item)) {
                $value[$key] = self::sortObject($item);
            }
        }
        return $value;
    }

    private static function assertDisposition(string $value): void
    {
        if (!in_array($value, [
            self::DISPOSITION_ALLOW,
            self::DISPOSITION_ALLOW_WITH_WARNINGS,
            self::DISPOSITION_QUARANTINE,
        ], true)) {
            throw new InvalidArgumentException('Invalid quality gate disposition.');
        }
    }

    private static function assertPolicyMode(string $value): void
    {
        if (!in_array($value, [self::POLICY_LEGACY_WARN, self::POLICY_SHADOW, self::POLICY_STRICT], true)) {
            throw new InvalidArgumentException('Invalid Canon policy mode.');
        }
    }

    private static function assertSha256(string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($hash))) {
            throw new InvalidArgumentException('content_hash must be a lowercase SHA-256 value.');
        }
    }

    private static function assertTimestamp(string $value): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('evaluated_at must use UTC Y-m-dTH:i:sZ format.');
        }
    }

    private static function assertNonEmptyToken(string $value, string $field, int $maxLength): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if (trim($value) === '' || $length > $maxLength) {
            throw new InvalidArgumentException("{$field} must be non-empty and at most {$maxLength} characters.");
        }
    }
}
