<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WritePolicySnapshot.php';

final class WritePolicyEmergencyStopException extends RuntimeException
{
}

final class UnsupportedWritePolicyCombinationException extends RuntimeException
{
}

/**
 * Resolves every mutable write setting once at a chapter boundary and turns it
 * into a verifiable WritePolicySnapshot.
 */
final class WritePolicySnapshotFactory
{
    public const LEGACY_SNAPSHOT_SCHEMA_VERSION = WritePolicySnapshot::SCHEMA_V1;
    public const PROFILE_SNAPSHOT_SCHEMA_VERSION = WritePolicySnapshot::SCHEMA_V2;
    public const SNAPSHOT_SCHEMA_VERSION = WritePolicySnapshot::SCHEMA_V3;
    public const LEGACY_QUALITY_RULE_VERSION = 'canonical-quality-v1';
    public const QUALITY_RULE_VERSION = 'canonical-quality-v2';
    public const CONSTRAINT_RULE_VERSION = WritePolicySnapshot::CONSTRAINT_RULE_POST_WRITE_V1;
    public const WORKFLOW_COMPAT_VERSION = 'legacy-write-engine-v1';

    /** @var list<string> */
    public const HARD_RULE_IDS = [
        'content.truncated',
        'constraint.p0',
        'outline.severe_drift',
        'continuity.severe',
        'word_count.minimum',
        'quality.gate_unavailable',
    ];

    /** @var list<string> */
    private const RESOLVED_FIELDS_V1 = [
        'canon_policy',
        'constraint_strict',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'rewrite_limit',
        'target_words',
        'min_words',
        'max_words',
        'emergency_epoch',
    ];

    /** @var list<string> */
    private const RESOLVED_FIELDS_V2 = [
        'canon_policy',
        'constraint_strict',
        'constraint_profile_complete',
        'constraint_enabled',
        'constraint_banned_words',
        'constraint_max_banned_word_usage',
        'constraint_max_same_conflict',
        'constraint_max_coincidences',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'rewrite_limit',
        'target_words',
        'min_words',
        'max_words',
        'emergency_epoch',
    ];

    /** @var list<string> */
    private const RESOLVED_FIELDS_V3 = [
        'harness_mode',
        'fencing_enforced',
        'canon_policy',
        'constraint_strict',
        'constraint_profile_complete',
        'constraint_enabled',
        'constraint_banned_words',
        'constraint_max_banned_word_usage',
        'constraint_max_same_conflict',
        'constraint_max_coincidences',
        'quality_check_enabled',
        'block_short_chapter',
        'rewrite_enabled',
        'rewrite_limit',
        'target_words',
        'min_words',
        'max_words',
        'emergency_epoch',
    ];

    private const DEFAULT_CANON_POLICY = WritePolicySnapshot::CANON_POLICY_LEGACY_WARN;
    private const DEFAULT_CONSTRAINT_STRICT = false;
    private const DEFAULT_QUALITY_CHECK_ENABLED = true;
    private const DEFAULT_BLOCK_SHORT_CHAPTER = true;
    private const DEFAULT_REWRITE_ENABLED = false;
    private const DEFAULT_REWRITE_LIMIT = 3;
    private const DEFAULT_EMERGENCY_EPOCH = 0;
    /** @var list<string> */
    private const DEFAULT_CONSTRAINT_BANNED_WORDS = ['绝境', '反杀', '真相', '背水', '逆袭'];
    private const DEFAULT_MAX_BANNED_WORD_USAGE = 15;
    private const DEFAULT_MAX_SAME_CONFLICT = 1;
    private const DEFAULT_MAX_COINCIDENCES = 5;

    private function __construct()
    {
    }

    public static function captureForChapter(
        int $novelId,
        int $targetWords,
        ?DateTimeImmutable $capturedAt = null
    ): WritePolicySnapshot {
        self::assertPositiveTarget($targetWords);
        self::loadCurrentReaders();
        self::refreshCurrentReaders($novelId);
        $constraintProfile = self::captureConstraintProfile();
        $harnessControls = HarnessControlPlane::captureForNovel($novelId);

        $canonPolicy = self::normalizeCanonPolicy(self::readSafely(
            static function () use ($novelId): mixed {
                if (!class_exists('ConfigCenter', false)) {
                    return self::DEFAULT_CANON_POLICY;
                }
                if ($novelId > 0) {
                    return ConfigCenter::getNovel(
                        $novelId,
                        'ws_harness_canon_policy',
                        self::DEFAULT_CANON_POLICY,
                        'string'
                    );
                }
                return ConfigCenter::get(
                    'ws_harness_canon_policy',
                    self::DEFAULT_CANON_POLICY,
                    'string'
                );
            },
            self::DEFAULT_CANON_POLICY
        ));

        $constraintStrict = self::normalizeBoolean(self::readSafely(
            static fn(): mixed => class_exists('ConstraintConfig', false)
                ? ConstraintConfig::isStrictMode($novelId > 0 ? $novelId : null)
                : self::DEFAULT_CONSTRAINT_STRICT,
            self::DEFAULT_CONSTRAINT_STRICT
        ), self::DEFAULT_CONSTRAINT_STRICT);

        $qualityCheckEnabled = self::normalizeBoolean(self::readSafely(
            static fn(): mixed => class_exists('ConfigCenter', false)
                ? ConfigCenter::get('ws_quality_check_enabled', self::DEFAULT_QUALITY_CHECK_ENABLED, 'bool')
                : self::DEFAULT_QUALITY_CHECK_ENABLED,
            self::DEFAULT_QUALITY_CHECK_ENABLED
        ), self::DEFAULT_QUALITY_CHECK_ENABLED);

        $blockShortChapter = self::normalizeBoolean(self::readSafely(
            static function () use ($novelId): mixed {
                if (!class_exists('ConfigCenter', false)) {
                    return self::DEFAULT_BLOCK_SHORT_CHAPTER;
                }
                if ($novelId > 0) {
                    return ConfigCenter::getNovel(
                        $novelId,
                        'ws_block_short_chapter',
                        self::DEFAULT_BLOCK_SHORT_CHAPTER,
                        'bool'
                    );
                }
                return ConfigCenter::get(
                    'ws_block_short_chapter',
                    self::DEFAULT_BLOCK_SHORT_CHAPTER,
                    'bool'
                );
            },
            self::DEFAULT_BLOCK_SHORT_CHAPTER
        ), self::DEFAULT_BLOCK_SHORT_CHAPTER);

        $legacyRewriteEnabled = self::normalizeBoolean(self::readSafely(
            static fn(): mixed => class_exists('ConfigCenter', false)
                ? ConfigCenter::get('ws_rewrite_enabled', self::DEFAULT_REWRITE_ENABLED, 'bool')
                : self::DEFAULT_REWRITE_ENABLED,
            self::DEFAULT_REWRITE_ENABLED
        ), self::DEFAULT_REWRITE_ENABLED);

        $rewriteEnabled = self::normalizeBoolean(self::readSafely(
            static fn(): mixed => function_exists('getSetting')
                ? getSetting('rewrite.enabled', $legacyRewriteEnabled, $novelId)
                : $legacyRewriteEnabled,
            $legacyRewriteEnabled
        ), $legacyRewriteEnabled);

        $rewriteLimit = self::normalizeInteger(self::readSafely(
            static fn(): mixed => function_exists('getSetting')
                ? getSetting('iterative_refinement.max_iterations', self::DEFAULT_REWRITE_LIMIT, $novelId)
                : self::DEFAULT_REWRITE_LIMIT,
            self::DEFAULT_REWRITE_LIMIT
        ), self::DEFAULT_REWRITE_LIMIT);

        $emergencyEpoch = self::normalizeInteger(self::readSafely(
            static fn(): mixed => class_exists('ConfigCenter', false)
                ? ConfigCenter::get(
                    'ws_harness_emergency_stop_epoch',
                    self::DEFAULT_EMERGENCY_EPOCH,
                    'int'
                )
                : self::DEFAULT_EMERGENCY_EPOCH,
            self::DEFAULT_EMERGENCY_EPOCH
        ), self::DEFAULT_EMERGENCY_EPOCH);

        // This is deliberately the only dynamic-tolerance invocation in a
        // capture. Later phases consume min_words/max_words from the snapshot.
        $range = self::resolveDynamicRange($targetWords, $novelId);

        $snapshot = self::fromResolved([
            'harness_mode' => $harnessControls['harness_mode'],
            'fencing_enforced' => $harnessControls['fencing_enforced'],
            'canon_policy' => $canonPolicy,
            'constraint_strict' => $constraintStrict,
            'constraint_profile_complete' => $constraintProfile['complete'],
            'constraint_enabled' => $constraintProfile['constraint_enabled'],
            'constraint_banned_words' => $constraintProfile['constraint_banned_words'],
            'constraint_max_banned_word_usage' => $constraintProfile['constraint_max_banned_word_usage'],
            'constraint_max_same_conflict' => $constraintProfile['constraint_max_same_conflict'],
            'constraint_max_coincidences' => $constraintProfile['constraint_max_coincidences'],
            'quality_check_enabled' => $qualityCheckEnabled,
            'block_short_chapter' => $blockShortChapter,
            'rewrite_enabled' => $rewriteEnabled,
            'rewrite_limit' => $rewriteLimit,
            'target_words' => $targetWords,
            'min_words' => $range['min'],
            'max_words' => $range['max'],
            'emergency_epoch' => $emergencyEpoch,
        ], $capturedAt);
        self::assertExecutionSupported($snapshot);
        return $snapshot;
    }

    /**
     * Pure construction path for callers that already resolved all mutable
     * settings. No global configuration, DB, or tolerance function is read.
     *
     * @param array<string, mixed> $resolved
     */
    public static function fromResolved(
        array $resolved,
        ?DateTimeImmutable $capturedAt = null
    ): WritePolicySnapshot {
        $isLegacy = self::hasExactFields($resolved, self::RESOLVED_FIELDS_V1);
        $isV2 = self::hasExactFields($resolved, self::RESOLVED_FIELDS_V2);
        $isV3 = self::hasExactFields($resolved, self::RESOLVED_FIELDS_V3);
        if (!$isLegacy && !$isV2 && !$isV3) {
            throw new InvalidArgumentException(
                'Resolved write policy fields must match a supported factory schema exactly.'
            );
        }

        $targetWords = self::requireInteger($resolved['target_words'], 'target_words');
        self::assertPositiveTarget($targetWords);

        $hardMinWords = self::hardMinimumWords($targetWords);
        $resolvedMin = self::requireInteger($resolved['min_words'], 'min_words');
        $resolvedMax = self::requireInteger($resolved['max_words'], 'max_words');
        $minWords = max($hardMinWords, min($targetWords, $resolvedMin));
        $maxWords = max($targetWords, $resolvedMax);

        $rewriteEnabled = self::requireBoolean($resolved['rewrite_enabled'], 'rewrite_enabled');
        $rewriteLimit = self::requireInteger($resolved['rewrite_limit'], 'rewrite_limit');
        $rewriteLimit = $rewriteEnabled ? max(0, min(5, $rewriteLimit)) : 0;

        $emergencyEpoch = self::requireInteger($resolved['emergency_epoch'], 'emergency_epoch');

        $policy = [
            'version' => $isV3
                ? self::SNAPSHOT_SCHEMA_VERSION
                : ($isV2
                    ? self::PROFILE_SNAPSHOT_SCHEMA_VERSION
                    : self::LEGACY_SNAPSHOT_SCHEMA_VERSION),
            'canon_policy' => self::normalizeCanonPolicy($resolved['canon_policy']),
            'quality_rule_version' => ($isV2 || $isV3)
                ? self::QUALITY_RULE_VERSION
                : self::LEGACY_QUALITY_RULE_VERSION,
            'hard_rule_ids' => self::HARD_RULE_IDS,
            'rewrite_limit' => $rewriteLimit,
            'workflow_compat_version' => self::WORKFLOW_COMPAT_VERSION,
            'constraint_strict' => self::requireBoolean(
                $resolved['constraint_strict'],
                'constraint_strict'
            ),
            'quality_check_enabled' => self::requireBoolean(
                $resolved['quality_check_enabled'],
                'quality_check_enabled'
            ),
            'block_short_chapter' => self::requireBoolean(
                $resolved['block_short_chapter'],
                'block_short_chapter'
            ),
            'rewrite_enabled' => $rewriteEnabled,
            'target_words' => $targetWords,
            'hard_min_words' => $hardMinWords,
            'min_words' => $minWords,
            'max_words' => $maxWords,
            'emergency_epoch' => max(0, $emergencyEpoch),
        ];

        if ($isV2 || $isV3) {
            $policy += [
                'constraint_rule_version' => self::CONSTRAINT_RULE_VERSION,
                'constraint_profile_complete' => self::requireBoolean(
                    $resolved['constraint_profile_complete'],
                    'constraint_profile_complete'
                ),
                'constraint_enabled' => self::requireBoolean(
                    $resolved['constraint_enabled'],
                    'constraint_enabled'
                ),
                'constraint_banned_words' => $resolved['constraint_banned_words'],
                'constraint_max_banned_word_usage' => self::requireInteger(
                    $resolved['constraint_max_banned_word_usage'],
                    'constraint_max_banned_word_usage'
                ),
                'constraint_max_same_conflict' => self::requireInteger(
                    $resolved['constraint_max_same_conflict'],
                    'constraint_max_same_conflict'
                ),
                'constraint_max_coincidences' => self::requireInteger(
                    $resolved['constraint_max_coincidences'],
                    'constraint_max_coincidences'
                ),
            ];
        }

        if ($isV3) {
            // Rebuild in the v3 canonical order. These ordinary rollout flags
            // are checksummed alongside the write policy and may never be
            // reread by an in-flight worker.
            $policy = [
                'version' => $policy['version'],
                'harness_mode' => self::requireHarnessMode($resolved['harness_mode']),
                'fencing_enforced' => self::requireBoolean(
                    $resolved['fencing_enforced'],
                    'fencing_enforced'
                ),
            ] + array_diff_key($policy, [
                'version' => true,
            ]);
        }

        return WritePolicySnapshot::capture($policy, $capturedAt);
    }

    /**
     * Live emergency-stop checkpoint. Unlike ordinary policy resolution this
     * is fail-closed: a missing/unreadable/malformed live epoch stops the run.
     */
    public static function assertEmergencyEpochCurrent(WritePolicySnapshot $snapshot): void
    {
        try {
            if (!class_exists('ConfigCenter', false)) {
                require_once dirname(__DIR__) . '/config_center.php';
            }
            if (!class_exists('ConfigCenter', false) || !method_exists('ConfigCenter', 'clearCache')) {
                throw new RuntimeException('ConfigCenter is unavailable.');
            }

            // Emergency epoch is the one deliberately live setting. Evict the
            // request cache so a kill-switch increment is visible immediately.
            ConfigCenter::clearCache('ws_harness_emergency_stop_epoch');
            $raw = ConfigCenter::get('ws_harness_emergency_stop_epoch', null, 'string');
            $currentEpoch = self::parseLiveEmergencyEpoch($raw);
        } catch (WritePolicyEmergencyStopException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WritePolicyEmergencyStopException(
                'Live emergency stop epoch could not be read; write is stopped fail-closed.',
                0,
                $exception
            );
        }

        self::assertEmergencyEpoch($snapshot, $currentEpoch);
    }

    /**
     * Serialize an irreversible Canon transaction with the monotonic emergency
     * stop. The caller must already own the DB transaction and must keep it
     * open through its final commit/rollback, thereby holding this row lock.
     */
    public static function lockEmergencyEpochForCommit(
        WritePolicySnapshot $snapshot
    ): void {
        if (!class_exists('DB', false)
            || !method_exists('DB', 'inTransaction')
            || !DB::inTransaction()) {
            throw new WritePolicyEmergencyStopException(
                'Emergency epoch commit lock requires the owned Canon transaction.'
            );
        }
        try {
            $row = DB::fetch(
                'SELECT setting_value FROM system_settings '
                    . 'WHERE setting_key=? FOR UPDATE',
                ['ws_harness_emergency_stop_epoch']
            );
            if (!is_array($row)) {
                throw new RuntimeException('Emergency epoch row is missing.');
            }
            $currentEpoch = self::parseLiveEmergencyEpoch(
                $row['setting_value'] ?? null
            );
        } catch (WritePolicyEmergencyStopException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WritePolicyEmergencyStopException(
                'Emergency epoch could not be locked for Canon commit.',
                0,
                $exception
            );
        }
        self::assertEmergencyEpoch($snapshot, $currentEpoch);
    }

    /**
     * Strict rejection currently precedes the post-Canon RewriteAgent stage.
     * Until the pre-Canon C0 -> C1 repair chain exists, accepting this setting
     * pair would promise an automatic rescue that can never execute.
     */
    public static function assertExecutionSupported(WritePolicySnapshot $snapshot): void
    {
        HarnessControlPlane::assertAuthorityIsFenced(
            $snapshot->harnessMode(),
            $snapshot->fencingEnforced()
        );
        if ($snapshot->canonPolicy() === WritePolicySnapshot::CANON_POLICY_STRICT
            && $snapshot->rewriteEnabled()) {
            throw new UnsupportedWritePolicyCombinationException(
                'strict Canon policy cannot be combined with post-Canon rewrite; '
                . 'disable rewrite or use legacy_warn until pre-Canon repair is available.'
            );
        }
    }

    /**
     * Enforce the exact frozen deployment protocol at writing-worker startup.
     * The subsequent repository isLeaseCurrent() read remains the live durable
     * lease check; this method prevents a malformed carrier from reaching it
     * when the operator explicitly enabled the deployment barrier.
     *
     * @param array<string,mixed> $lease
     * @param array<string,mixed> $taskRow
     */
    public static function assertWritingWorkerFenceEnforced(
        WritePolicySnapshot $snapshot,
        array $lease,
        array $taskRow
    ): void {
        // Persisted v3 snapshots are untrusted input at restore time. Reject an
        // authority mode without its deployment fence before inspecting the
        // worker carrier, so malformed canary/on snapshots fail immediately.
        self::assertExecutionSupported($snapshot);
        HarnessControlPlane::assertWritingWorkerFence(
            $snapshot->fencingEnforced(),
            $lease,
            $taskRow
        );
    }

    /**
     * Enforce the frozen authority invariant and exact post-processing lease
     * coordinates before a restored job may touch projection state.
     *
     * @param array<string,mixed> $jobRow
     * @param array<string,mixed> $lease
     */
    public static function assertPostprocessWorkerFenceEnforced(
        WritePolicySnapshot $snapshot,
        array $jobRow,
        array $lease
    ): void {
        self::assertExecutionSupported($snapshot);
        HarnessControlPlane::assertPostprocessWorkerFence(
            $snapshot->fencingEnforced(),
            $jobRow,
            $lease
        );
    }

    /**
     * Pure emergency epoch comparison for workers and deterministic tests.
     */
    public static function assertEmergencyEpoch(WritePolicySnapshot $snapshot, int $currentEpoch): void
    {
        if ($currentEpoch < 0) {
            throw new WritePolicyEmergencyStopException(
                'Current emergency stop epoch is invalid; write is stopped fail-closed.'
            );
        }

        $capturedEpoch = $snapshot->emergencyEpoch();
        if ($currentEpoch !== $capturedEpoch) {
            throw new WritePolicyEmergencyStopException(sprintf(
                'Emergency stop epoch changed from %d to %d; the in-flight write must stop.',
                $capturedEpoch,
                $currentEpoch
            ));
        }
    }

    private static function loadCurrentReaders(): void
    {
        $loaders = [
            static function (): void {
                if (!class_exists('ConfigCenter', false)) {
                    require_once dirname(__DIR__) . '/config_center.php';
                }
            },
            static function (): void {
                if (!class_exists('ConstraintConfig', false)) {
                    require_once dirname(__DIR__) . '/constraints/ConstraintConfig.php';
                }
            },
            static function (): void {
                if (!function_exists('getSetting')) {
                    require_once dirname(__DIR__) . '/data.php';
                }
            },
            static function (): void {
                if (!function_exists('calculateDynamicTolerance')) {
                    require_once dirname(__DIR__) . '/prompt.php';
                }
            },
        ];

        foreach ($loaders as $loader) {
            try {
                $loader();
            } catch (Throwable) {
                // Each reader below has an explicit legacy-compatible default.
            }
        }
    }

    /**
     * Long-running workers span many chapter boundaries in one PHP process.
     * Refresh request-local readers once before the next immutable capture so
     * ordinary setting changes can affect the next chapter, never the current
     * one.
     */
    private static function refreshCurrentReaders(int $novelId): void
    {
        try {
            if (class_exists('ConfigCenter', false)) {
                ConfigCenter::clearCache();
                if ($novelId > 0 && method_exists('ConfigCenter', 'invalidateNovelCache')) {
                    ConfigCenter::invalidateNovelCache($novelId);
                }
            } elseif (function_exists('clearSystemSettingsCache')) {
                clearSystemSettingsCache();
            }
        } catch (Throwable) {
            // The capture below still has deterministic compatibility defaults.
        }

        try {
            if (class_exists('ConstraintConfig', false)) {
                ConstraintConfig::clearCache();
            }
        } catch (Throwable) {
            // The capture below marks the profile incomplete on read failure.
        }
    }

    /**
     * @return array{
     *   complete:bool,
     *   constraint_enabled:bool,
     *   constraint_banned_words:list<string>,
     *   constraint_max_banned_word_usage:int,
     *   constraint_max_same_conflict:int,
     *   constraint_max_coincidences:int
     * }
     */
    private static function captureConstraintProfile(): array
    {
        $fallback = [
            'complete' => false,
            'constraint_enabled' => true,
            'constraint_banned_words' => self::DEFAULT_CONSTRAINT_BANNED_WORDS,
            'constraint_max_banned_word_usage' => self::DEFAULT_MAX_BANNED_WORD_USAGE,
            'constraint_max_same_conflict' => self::DEFAULT_MAX_SAME_CONFLICT,
            'constraint_max_coincidences' => self::DEFAULT_MAX_COINCIDENCES,
        ];

        try {
            if (!class_exists('ConstraintConfig', false)
                || !method_exists('ConstraintConfig', 'capturePostWriteProfile')) {
                return $fallback;
            }
            $profile = ConstraintConfig::capturePostWriteProfile();
            $expected = [
                'constraint_enabled',
                'constraint_banned_words',
                'constraint_max_banned_word_usage',
                'constraint_max_same_conflict',
                'constraint_max_coincidences',
            ];
            if (!is_array($profile) || !self::hasExactFields($profile, $expected)) {
                return $fallback;
            }
            if (!is_bool($profile['constraint_enabled'])
                || !is_array($profile['constraint_banned_words'])
                || !array_is_list($profile['constraint_banned_words'])
                || !is_int($profile['constraint_max_banned_word_usage'])
                || $profile['constraint_max_banned_word_usage'] < 1
                || !is_int($profile['constraint_max_same_conflict'])
                || $profile['constraint_max_same_conflict'] < 1
                || !is_int($profile['constraint_max_coincidences'])
                || $profile['constraint_max_coincidences'] < 0) {
                return $fallback;
            }
            foreach ($profile['constraint_banned_words'] as $word) {
                if (!is_string($word) || $word === '' || trim($word) !== $word) {
                    return $fallback;
                }
            }

            return ['complete' => true] + $profile;
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** @return array{min: int, max: int} */
    private static function resolveDynamicRange(int $targetWords, int $novelId): array
    {
        $fallback = self::fallbackDynamicRange($targetWords);
        $raw = self::readSafely(
            static fn(): mixed => function_exists('calculateDynamicTolerance')
                ? calculateDynamicTolerance($targetWords, $novelId > 0 ? $novelId : null)
                : $fallback,
            $fallback
        );

        if (!is_array($raw)) {
            return $fallback;
        }

        $min = self::normalizeInteger($raw['min'] ?? null, $fallback['min']);
        $max = self::normalizeInteger($raw['max'] ?? null, $fallback['max']);

        return $min <= $targetWords && $max >= $targetWords
            ? ['min' => $min, 'max' => $max]
            : $fallback;
    }

    /** @return array{min: int, max: int} */
    private static function fallbackDynamicRange(int $targetWords): array
    {
        // calculateDynamicTolerance defaults: 10%, minimum 100, maximum 500.
        $tolerance = max(100, min(500, (int)($targetWords * 0.10)));

        return [
            'min' => $targetWords - $tolerance,
            'max' => $targetWords + $tolerance,
        ];
    }

    private static function hardMinimumWords(int $targetWords): int
    {
        return min($targetWords, max(200, (int)ceil($targetWords * 0.30)));
    }

    private static function normalizeCanonPolicy(mixed $value): string
    {
        return is_string($value) && in_array($value, [
            WritePolicySnapshot::CANON_POLICY_LEGACY_WARN,
            WritePolicySnapshot::CANON_POLICY_SHADOW,
            WritePolicySnapshot::CANON_POLICY_STRICT,
        ], true) ? $value : self::DEFAULT_CANON_POLICY;
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

    private static function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        return $default;
    }

    private static function normalizeInteger(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int)$value;
        }

        return $default;
    }

    private static function parseLiveEmergencyEpoch(mixed $value): int
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }
        } elseif (
            is_string($value)
            && preg_match('/^(?:0|[1-9]\d*)$/D', $value) === 1
        ) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new WritePolicyEmergencyStopException(
            'Live emergency stop epoch is missing or invalid; write is stopped fail-closed.'
        );
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

    private static function assertPositiveTarget(int $targetWords): void
    {
        if ($targetWords <= 0) {
            throw new InvalidArgumentException('target_words must be greater than zero.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $expectedFields
     */
    private static function hasExactFields(array $input, array $expectedFields): bool
    {
        $actual = array_keys($input);
        sort($actual, SORT_STRING);
        $expected = $expectedFields;
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private static function readSafely(callable $reader, mixed $default): mixed
    {
        try {
            return $reader();
        } catch (Throwable) {
            return $default;
        }
    }
}
