<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/harness/HarnessControlPlane.php';

/**
 * Immutable policy inputs used for the complete lifetime of one chapter write.
 *
 * The checksum covers the canonical JSON representation of every field except
 * the checksum itself. Serialized snapshots therefore remain verifiable even
 * when their input object keys arrive in a different order.
 */
final readonly class WritePolicySnapshot
{
    public const CANON_POLICY_LEGACY_WARN = 'legacy_warn';
    public const CANON_POLICY_SHADOW = 'shadow';
    public const CANON_POLICY_STRICT = 'strict';
    public const SCHEMA_V1 = 'write-policy-v1';
    public const SCHEMA_V2 = 'write-policy-v2';
    public const SCHEMA_V3 = 'write-policy-v3';
    public const CONSTRAINT_RULE_POST_WRITE_V1 = 'post-write-validator-v1';

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const CAPTURE_FIELDS_V1 = [
        'version',
        'canon_policy',
        'quality_rule_version',
        'hard_rule_ids',
        'rewrite_limit',
        'workflow_compat_version',
        'constraint_strict',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'target_words',
        'hard_min_words',
        'min_words',
        'max_words',
        'emergency_epoch',
    ];

    /** @var list<string> */
    private const SERIALIZED_FIELDS_V1 = [
        'version',
        'captured_at',
        'canon_policy',
        'quality_rule_version',
        'hard_rule_ids',
        'rewrite_limit',
        'workflow_compat_version',
        'constraint_strict',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'target_words',
        'hard_min_words',
        'min_words',
        'max_words',
        'emergency_epoch',
        'checksum',
    ];

    /** @var list<string> */
    private const CAPTURE_FIELDS_V2 = [
        'version',
        'canon_policy',
        'quality_rule_version',
        'hard_rule_ids',
        'rewrite_limit',
        'workflow_compat_version',
        'constraint_strict',
        'constraint_rule_version',
        'constraint_profile_complete',
        'constraint_enabled',
        'constraint_banned_words',
        'constraint_max_banned_word_usage',
        'constraint_max_same_conflict',
        'constraint_max_coincidences',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'target_words',
        'hard_min_words',
        'min_words',
        'max_words',
        'emergency_epoch',
    ];

    /** @var list<string> */
    private const SERIALIZED_FIELDS_V2 = [
        'version',
        'captured_at',
        'canon_policy',
        'quality_rule_version',
        'hard_rule_ids',
        'rewrite_limit',
        'workflow_compat_version',
        'constraint_strict',
        'constraint_rule_version',
        'constraint_profile_complete',
        'constraint_enabled',
        'constraint_banned_words',
        'constraint_max_banned_word_usage',
        'constraint_max_same_conflict',
        'constraint_max_coincidences',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'target_words',
        'hard_min_words',
        'min_words',
        'max_words',
        'emergency_epoch',
        'checksum',
    ];

    /** @var list<string> */
    private const CAPTURE_FIELDS_V3 = [
        'version',
        'harness_mode',
        'fencing_enforced',
        'canon_policy',
        'quality_rule_version',
        'hard_rule_ids',
        'rewrite_limit',
        'workflow_compat_version',
        'constraint_strict',
        'constraint_rule_version',
        'constraint_profile_complete',
        'constraint_enabled',
        'constraint_banned_words',
        'constraint_max_banned_word_usage',
        'constraint_max_same_conflict',
        'constraint_max_coincidences',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'target_words',
        'hard_min_words',
        'min_words',
        'max_words',
        'emergency_epoch',
    ];

    /** @var list<string> */
    private const SERIALIZED_FIELDS_V3 = [
        'version',
        'captured_at',
        'harness_mode',
        'fencing_enforced',
        'canon_policy',
        'quality_rule_version',
        'hard_rule_ids',
        'rewrite_limit',
        'workflow_compat_version',
        'constraint_strict',
        'constraint_rule_version',
        'constraint_profile_complete',
        'constraint_enabled',
        'constraint_banned_words',
        'constraint_max_banned_word_usage',
        'constraint_max_same_conflict',
        'constraint_max_coincidences',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'target_words',
        'hard_min_words',
        'min_words',
        'max_words',
        'emergency_epoch',
        'checksum',
    ];

    private function __construct(
        private string $version,
        private string $capturedAt,
        private string $harnessMode,
        private bool $fencingEnforced,
        private string $canonPolicy,
        private string $qualityRuleVersion,
        /** @var list<string> */
        private array $hardRuleIds,
        private int $rewriteLimit,
        private string $workflowCompatVersion,
        private bool $constraintStrict,
        private ?string $constraintRuleVersion,
        private bool $constraintProfileComplete,
        private ?bool $constraintEnabled,
        /** @var ?list<string> */
        private ?array $constraintBannedWords,
        private ?int $constraintMaxBannedWordUsage,
        private ?int $constraintMaxSameConflict,
        private ?int $constraintMaxCoincidences,
        private bool $qualityCheckEnabled,
        private bool $blockShortChapter,
        private bool $rewriteEnabled,
        private int $targetWords,
        private int $hardMinWords,
        private int $minWords,
        private int $maxWords,
        private int $emergencyEpoch,
        private string $checksum,
    ) {
    }

    /**
     * Capture a new snapshot. captured_at and checksum are generated here and
     * must not be supplied inside $policy.
     *
     * @param array<string, mixed> $policy
     */
    public static function capture(array $policy, ?DateTimeImmutable $capturedAt = null): self
    {
        $version = self::requireSupportedVersion($policy['version'] ?? null);
        self::assertExactFields(
            $policy,
            match ($version) {
                self::SCHEMA_V1 => self::CAPTURE_FIELDS_V1,
                self::SCHEMA_V2 => self::CAPTURE_FIELDS_V2,
                self::SCHEMA_V3 => self::CAPTURE_FIELDS_V3,
            }
        );

        $payloadInput = [
            'version' => $policy['version'],
            'captured_at' => self::formatTimestamp(
                $capturedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            ),
            'canon_policy' => $policy['canon_policy'],
            'quality_rule_version' => $policy['quality_rule_version'],
            'hard_rule_ids' => $policy['hard_rule_ids'],
            'rewrite_limit' => $policy['rewrite_limit'],
            'workflow_compat_version' => $policy['workflow_compat_version'],
            'constraint_strict' => $policy['constraint_strict'],
            'quality_check_enabled' => $policy['quality_check_enabled'],
            'block_short_chapter' => $policy['block_short_chapter'],
            'rewrite_enabled' => $policy['rewrite_enabled'],
            'target_words' => $policy['target_words'],
            'hard_min_words' => $policy['hard_min_words'],
            'min_words' => $policy['min_words'],
            'max_words' => $policy['max_words'],
            'emergency_epoch' => $policy['emergency_epoch'],
        ];
        if ($version === self::SCHEMA_V2) {
            $payloadInput += [
                'constraint_rule_version' => $policy['constraint_rule_version'],
                'constraint_profile_complete' => $policy['constraint_profile_complete'],
                'constraint_enabled' => $policy['constraint_enabled'],
                'constraint_banned_words' => $policy['constraint_banned_words'],
                'constraint_max_banned_word_usage' => $policy['constraint_max_banned_word_usage'],
                'constraint_max_same_conflict' => $policy['constraint_max_same_conflict'],
                'constraint_max_coincidences' => $policy['constraint_max_coincidences'],
            ];
        }
        if ($version === self::SCHEMA_V3) {
            $payloadInput += [
                'harness_mode' => $policy['harness_mode'],
                'fencing_enforced' => $policy['fencing_enforced'],
                'constraint_rule_version' => $policy['constraint_rule_version'],
                'constraint_profile_complete' => $policy['constraint_profile_complete'],
                'constraint_enabled' => $policy['constraint_enabled'],
                'constraint_banned_words' => $policy['constraint_banned_words'],
                'constraint_max_banned_word_usage' => $policy['constraint_max_banned_word_usage'],
                'constraint_max_same_conflict' => $policy['constraint_max_same_conflict'],
                'constraint_max_coincidences' => $policy['constraint_max_coincidences'],
            ];
        }
        $payload = self::normalizePayload($payloadInput, $version);

        return self::fromNormalizedPayload($payload, self::computeChecksum($payload));
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        $version = self::requireSupportedVersion($snapshot['version'] ?? null);
        self::assertExactFields(
            $snapshot,
            match ($version) {
                self::SCHEMA_V1 => self::SERIALIZED_FIELDS_V1,
                self::SCHEMA_V2 => self::SERIALIZED_FIELDS_V2,
                self::SCHEMA_V3 => self::SERIALIZED_FIELDS_V3,
            }
        );
        $payload = self::normalizePayload($snapshot, $version);
        $checksum = self::requireChecksum($snapshot['checksum']);
        $expected = self::computeChecksum($payload);

        if (!hash_equals($expected, $checksum)) {
            throw new InvalidArgumentException('WritePolicySnapshot checksum mismatch.');
        }

        return self::fromNormalizedPayload($payload, $checksum);
    }

    public static function fromJson(string $json): self
    {
        if ($json === '') {
            throw new InvalidArgumentException('WritePolicySnapshot JSON must not be empty.');
        }

        $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('WritePolicySnapshot JSON root must be an object.');
        }

        return self::fromArray(get_object_vars($decoded));
    }

    /** @return array<string, bool|int|string|list<string>> */
    public function toArray(): array
    {
        return $this->payload() + ['checksum' => $this->checksum];
    }

    public function toJson(): string
    {
        return self::encodeCanonical($this->toArray());
    }

    public function version(): string
    {
        return $this->version;
    }

    public function capturedAt(): string
    {
        return $this->capturedAt;
    }

    /**
     * Historical v1/v2 snapshots predate the Harness rollout controls and are
     * deliberately interpreted as the compatibility-safe off mode.
     */
    public function harnessMode(): string
    {
        return $this->harnessMode;
    }

    public function fencingEnforced(): bool
    {
        return $this->fencingEnforced;
    }

    public function canonPolicy(): string
    {
        return $this->canonPolicy;
    }

    public function qualityRuleVersion(): string
    {
        return $this->qualityRuleVersion;
    }

    /** @return list<string> */
    public function hardRuleIds(): array
    {
        return $this->hardRuleIds;
    }

    public function rewriteLimit(): int
    {
        return $this->rewriteLimit;
    }

    public function workflowCompatVersion(): string
    {
        return $this->workflowCompatVersion;
    }

    public function constraintStrict(): bool
    {
        return $this->constraintStrict;
    }

    /**
     * v1 snapshots predate the frozen post-write profile. A null rule version
     * is an explicit compatibility signal and must not be replaced from live
     * configuration by a consumer.
     */
    public function constraintRuleVersion(): ?string
    {
        return $this->constraintRuleVersion;
    }

    public function constraintProfileComplete(): bool
    {
        return $this->constraintProfileComplete;
    }

    public function constraintEnabled(): ?bool
    {
        return $this->constraintEnabled;
    }

    /** @return ?list<string> */
    public function constraintBannedWords(): ?array
    {
        return $this->constraintBannedWords;
    }

    public function constraintMaxBannedWordUsage(): ?int
    {
        return $this->constraintMaxBannedWordUsage;
    }

    public function constraintMaxSameConflict(): ?int
    {
        return $this->constraintMaxSameConflict;
    }

    public function constraintMaxCoincidences(): ?int
    {
        return $this->constraintMaxCoincidences;
    }

    public function qualityCheckEnabled(): bool
    {
        return $this->qualityCheckEnabled;
    }

    public function blockShortChapter(): bool
    {
        return $this->blockShortChapter;
    }

    public function rewriteEnabled(): bool
    {
        return $this->rewriteEnabled;
    }

    public function targetWords(): int
    {
        return $this->targetWords;
    }

    public function hardMinWords(): int
    {
        return $this->hardMinWords;
    }

    public function minWords(): int
    {
        return $this->minWords;
    }

    public function maxWords(): int
    {
        return $this->maxWords;
    }

    public function emergencyEpoch(): int
    {
        return $this->emergencyEpoch;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    /** @return array<string, bool|int|string|list<string>> */
    private function payload(): array
    {
        $payload = [
            'version' => $this->version,
            'captured_at' => $this->capturedAt,
            'canon_policy' => $this->canonPolicy,
            'quality_rule_version' => $this->qualityRuleVersion,
            'hard_rule_ids' => $this->hardRuleIds,
            'rewrite_limit' => $this->rewriteLimit,
            'workflow_compat_version' => $this->workflowCompatVersion,
            'constraint_strict' => $this->constraintStrict,
            'quality_check_enabled' => $this->qualityCheckEnabled,
            'block_short_chapter' => $this->blockShortChapter,
            'rewrite_enabled' => $this->rewriteEnabled,
            'target_words' => $this->targetWords,
            'hard_min_words' => $this->hardMinWords,
            'min_words' => $this->minWords,
            'max_words' => $this->maxWords,
            'emergency_epoch' => $this->emergencyEpoch,
        ];

        if ($this->version === self::SCHEMA_V2) {
            $payload = [
                'version' => $this->version,
                'captured_at' => $this->capturedAt,
                'canon_policy' => $this->canonPolicy,
                'quality_rule_version' => $this->qualityRuleVersion,
                'hard_rule_ids' => $this->hardRuleIds,
                'rewrite_limit' => $this->rewriteLimit,
                'workflow_compat_version' => $this->workflowCompatVersion,
                'constraint_strict' => $this->constraintStrict,
                'constraint_rule_version' => $this->constraintRuleVersion,
                'constraint_profile_complete' => $this->constraintProfileComplete,
                'constraint_enabled' => $this->constraintEnabled,
                'constraint_banned_words' => $this->constraintBannedWords,
                'constraint_max_banned_word_usage' => $this->constraintMaxBannedWordUsage,
                'constraint_max_same_conflict' => $this->constraintMaxSameConflict,
                'constraint_max_coincidences' => $this->constraintMaxCoincidences,
                'quality_check_enabled' => $this->qualityCheckEnabled,
                'block_short_chapter' => $this->blockShortChapter,
                'rewrite_enabled' => $this->rewriteEnabled,
                'target_words' => $this->targetWords,
                'hard_min_words' => $this->hardMinWords,
                'min_words' => $this->minWords,
                'max_words' => $this->maxWords,
                'emergency_epoch' => $this->emergencyEpoch,
            ];
        }

        if ($this->version === self::SCHEMA_V3) {
            $payload = [
                'version' => $this->version,
                'captured_at' => $this->capturedAt,
                'harness_mode' => $this->harnessMode,
                'fencing_enforced' => $this->fencingEnforced,
                'canon_policy' => $this->canonPolicy,
                'quality_rule_version' => $this->qualityRuleVersion,
                'hard_rule_ids' => $this->hardRuleIds,
                'rewrite_limit' => $this->rewriteLimit,
                'workflow_compat_version' => $this->workflowCompatVersion,
                'constraint_strict' => $this->constraintStrict,
                'constraint_rule_version' => $this->constraintRuleVersion,
                'constraint_profile_complete' => $this->constraintProfileComplete,
                'constraint_enabled' => $this->constraintEnabled,
                'constraint_banned_words' => $this->constraintBannedWords,
                'constraint_max_banned_word_usage' => $this->constraintMaxBannedWordUsage,
                'constraint_max_same_conflict' => $this->constraintMaxSameConflict,
                'constraint_max_coincidences' => $this->constraintMaxCoincidences,
                'quality_check_enabled' => $this->qualityCheckEnabled,
                'block_short_chapter' => $this->blockShortChapter,
                'rewrite_enabled' => $this->rewriteEnabled,
                'target_words' => $this->targetWords,
                'hard_min_words' => $this->hardMinWords,
                'min_words' => $this->minWords,
                'max_words' => $this->maxWords,
                'emergency_epoch' => $this->emergencyEpoch,
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, bool|int|string|list<string>>
     */
    private static function normalizePayload(array $input, string $version): array
    {
        if (!hash_equals($version, self::requireSupportedVersion($input['version'] ?? null))) {
            throw new InvalidArgumentException('WritePolicySnapshot version dispatch mismatch.');
        }
        $capturedAt = self::requireTimestamp($input['captured_at'] ?? null);
        $canonPolicy = self::requireNonEmptyString($input['canon_policy'] ?? null, 'canon_policy');
        $qualityRuleVersion = self::requireNonEmptyString(
            $input['quality_rule_version'] ?? null,
            'quality_rule_version'
        );
        $hardRuleIds = self::requireCanonicalStringList($input['hard_rule_ids'] ?? null, 'hard_rule_ids');
        $rewriteLimit = self::requireInteger($input['rewrite_limit'] ?? null, 'rewrite_limit');
        $workflowCompatVersion = self::requireNonEmptyString(
            $input['workflow_compat_version'] ?? null,
            'workflow_compat_version'
        );

        if (!in_array($canonPolicy, [
            self::CANON_POLICY_LEGACY_WARN,
            self::CANON_POLICY_SHADOW,
            self::CANON_POLICY_STRICT,
        ], true)) {
            throw new InvalidArgumentException('canon_policy must be legacy_warn, shadow, or strict.');
        }

        $targetWords = self::requireInteger($input['target_words'] ?? null, 'target_words');
        $hardMinWords = self::requireInteger($input['hard_min_words'] ?? null, 'hard_min_words');
        $minWords = self::requireInteger($input['min_words'] ?? null, 'min_words');
        $maxWords = self::requireInteger($input['max_words'] ?? null, 'max_words');
        $emergencyEpoch = self::requireInteger($input['emergency_epoch'] ?? null, 'emergency_epoch');

        if ($hardMinWords < 1 || $hardMinWords > $minWords || $minWords > $targetWords || $targetWords > $maxWords) {
            throw new InvalidArgumentException(
                'Word limits must satisfy 1 <= hard_min_words <= min_words <= target_words <= max_words.'
            );
        }
        if ($emergencyEpoch < 0) {
            throw new InvalidArgumentException('emergency_epoch must be greater than or equal to zero.');
        }
        if ($rewriteLimit < 0) {
            throw new InvalidArgumentException('rewrite_limit must be greater than or equal to zero.');
        }

        $normalized = [
            'version' => $version,
            'captured_at' => $capturedAt,
            'canon_policy' => $canonPolicy,
            'quality_rule_version' => $qualityRuleVersion,
            'hard_rule_ids' => $hardRuleIds,
            'rewrite_limit' => $rewriteLimit,
            'workflow_compat_version' => $workflowCompatVersion,
            'constraint_strict' => self::requireBoolean($input['constraint_strict'] ?? null, 'constraint_strict'),
            'quality_check_enabled' => self::requireBoolean(
                $input['quality_check_enabled'] ?? null,
                'quality_check_enabled'
            ),
            'block_short_chapter' => self::requireBoolean(
                $input['block_short_chapter'] ?? null,
                'block_short_chapter'
            ),
            'rewrite_enabled' => self::requireBoolean($input['rewrite_enabled'] ?? null, 'rewrite_enabled'),
            'target_words' => $targetWords,
            'hard_min_words' => $hardMinWords,
            'min_words' => $minWords,
            'max_words' => $maxWords,
            'emergency_epoch' => $emergencyEpoch,
        ];

        if ($version === self::SCHEMA_V2 || $version === self::SCHEMA_V3) {
            $constraintRuleVersion = self::requireNonEmptyString(
                $input['constraint_rule_version'] ?? null,
                'constraint_rule_version'
            );
            if ($constraintRuleVersion !== self::CONSTRAINT_RULE_POST_WRITE_V1) {
                throw new InvalidArgumentException('Unsupported constraint_rule_version.');
            }
            $maxBannedWordUsage = self::requireInteger(
                $input['constraint_max_banned_word_usage'] ?? null,
                'constraint_max_banned_word_usage'
            );
            $maxSameConflict = self::requireInteger(
                $input['constraint_max_same_conflict'] ?? null,
                'constraint_max_same_conflict'
            );
            $maxCoincidences = self::requireInteger(
                $input['constraint_max_coincidences'] ?? null,
                'constraint_max_coincidences'
            );
            if ($maxBannedWordUsage < 1) {
                throw new InvalidArgumentException(
                    'constraint_max_banned_word_usage must be greater than or equal to one.'
                );
            }
            if ($maxSameConflict < 1) {
                throw new InvalidArgumentException(
                    'constraint_max_same_conflict must be greater than or equal to one.'
                );
            }
            if ($maxCoincidences < 0) {
                throw new InvalidArgumentException(
                    'constraint_max_coincidences must be greater than or equal to zero.'
                );
            }

            $profilePrefix = [
                'version' => $normalized['version'],
                'captured_at' => $normalized['captured_at'],
            ];
            if ($version === self::SCHEMA_V3) {
                $profilePrefix += [
                    'harness_mode' => self::requireHarnessMode($input['harness_mode'] ?? null),
                    'fencing_enforced' => self::requireBoolean(
                        $input['fencing_enforced'] ?? null,
                        'fencing_enforced'
                    ),
                ];
            }
            $normalized = $profilePrefix + [
                'canon_policy' => $normalized['canon_policy'],
                'quality_rule_version' => $normalized['quality_rule_version'],
                'hard_rule_ids' => $normalized['hard_rule_ids'],
                'rewrite_limit' => $normalized['rewrite_limit'],
                'workflow_compat_version' => $normalized['workflow_compat_version'],
                'constraint_strict' => $normalized['constraint_strict'],
                'constraint_rule_version' => $constraintRuleVersion,
                'constraint_profile_complete' => self::requireBoolean(
                    $input['constraint_profile_complete'] ?? null,
                    'constraint_profile_complete'
                ),
                'constraint_enabled' => self::requireBoolean(
                    $input['constraint_enabled'] ?? null,
                    'constraint_enabled'
                ),
                'constraint_banned_words' => self::requireOrderedStringList(
                    $input['constraint_banned_words'] ?? null,
                    'constraint_banned_words'
                ),
                'constraint_max_banned_word_usage' => $maxBannedWordUsage,
                'constraint_max_same_conflict' => $maxSameConflict,
                'constraint_max_coincidences' => $maxCoincidences,
                'quality_check_enabled' => $normalized['quality_check_enabled'],
                'block_short_chapter' => $normalized['block_short_chapter'],
                'rewrite_enabled' => $normalized['rewrite_enabled'],
                'target_words' => $normalized['target_words'],
                'hard_min_words' => $normalized['hard_min_words'],
                'min_words' => $normalized['min_words'],
                'max_words' => $normalized['max_words'],
                'emergency_epoch' => $normalized['emergency_epoch'],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, bool|int|string|list<string>> $payload
     */
    private static function fromNormalizedPayload(array $payload, string $checksum): self
    {
        return new self(
            $payload['version'],
            $payload['captured_at'],
            $payload['harness_mode'] ?? HarnessControlPlane::DEFAULT_MODE,
            $payload['fencing_enforced'] ?? HarnessControlPlane::DEFAULT_FENCING_ENFORCED,
            $payload['canon_policy'],
            $payload['quality_rule_version'],
            $payload['hard_rule_ids'],
            $payload['rewrite_limit'],
            $payload['workflow_compat_version'],
            $payload['constraint_strict'],
            $payload['constraint_rule_version'] ?? null,
            $payload['constraint_profile_complete'] ?? false,
            $payload['constraint_enabled'] ?? null,
            $payload['constraint_banned_words'] ?? null,
            $payload['constraint_max_banned_word_usage'] ?? null,
            $payload['constraint_max_same_conflict'] ?? null,
            $payload['constraint_max_coincidences'] ?? null,
            $payload['quality_check_enabled'],
            $payload['block_short_chapter'],
            $payload['rewrite_enabled'],
            $payload['target_words'],
            $payload['hard_min_words'],
            $payload['min_words'],
            $payload['max_words'],
            $payload['emergency_epoch'],
            $checksum,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $expectedFields
     */
    private static function assertExactFields(array $input, array $expectedFields): void
    {
        $actual = array_keys($input);
        sort($actual, SORT_STRING);
        $expected = $expectedFields;
        sort($expected, SORT_STRING);

        if ($actual === $expected) {
            return;
        }

        $missing = array_values(array_diff($expected, $actual));
        $unknown = array_values(array_diff($actual, $expected));
        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing fields: ' . implode(', ', $missing);
        }
        if ($unknown !== []) {
            $parts[] = 'unknown fields: ' . implode(', ', $unknown);
        }

        throw new InvalidArgumentException('Invalid WritePolicySnapshot fields (' . implode('; ', $parts) . ').');
    }

    private static function requireNonEmptyString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException($field . ' must be a non-empty string without surrounding whitespace.');
        }

        return $value;
    }

    private static function requireSupportedVersion(mixed $value): string
    {
        $version = self::requireNonEmptyString($value, 'version');
        if (!in_array($version, [self::SCHEMA_V1, self::SCHEMA_V2, self::SCHEMA_V3], true)) {
            throw new InvalidArgumentException('Unsupported WritePolicySnapshot version.');
        }

        return $version;
    }

    private static function requireHarnessMode(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, [
            HarnessControlPlane::MODE_OFF,
            HarnessControlPlane::MODE_SHADOW,
            HarnessControlPlane::MODE_CANARY,
            HarnessControlPlane::MODE_ON,
        ], true)) {
            throw new InvalidArgumentException(
                'harness_mode must be off, shadow, canary, or on.'
            );
        }

        return $value;
    }

    private static function requireBoolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($field . ' must be a boolean.');
        }

        return $value;
    }

    private static function requireInteger(mixed $value, string $field): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException($field . ' must be an integer.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function requireCanonicalStringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($field . ' must be a JSON-compatible list of strings.');
        }

        $unique = [];
        foreach ($value as $item) {
            $normalized = self::requireNonEmptyString($item, $field . ' item');
            $unique[$normalized] = true;
        }

        $items = array_keys($unique);
        sort($items, SORT_STRING);

        return $items;
    }

    /** @return list<string> */
    private static function requireOrderedStringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($field . ' must be a JSON-compatible list of strings.');
        }

        $items = [];
        foreach ($value as $item) {
            $items[] = self::requireNonEmptyString($item, $field . ' item');
        }

        return $items;
    }

    private static function requireTimestamp(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('captured_at must be a canonical UTC timestamp string.');
        }

        $parsed = DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new InvalidArgumentException('captured_at must use canonical UTC format Y-m-d\\TH:i:s.u\\Z.');
        }

        return $value;
    }

    private static function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT);
    }

    private static function requireChecksum(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('checksum must be a lowercase SHA-256 hex string.');
        }

        return $value;
    }

    /** @param array<string, bool|int|string|list<string>> $payload */
    private static function computeChecksum(array $payload): string
    {
        return hash('sha256', self::encodeCanonical($payload));
    }

    /** @param array<string, bool|int|string|list<string>> $value */
    private static function encodeCanonical(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
