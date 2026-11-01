<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

final class HarnessFencingEnforcementException extends RuntimeException
{
}

/**
 * Typed Harness rollout controls shared by policy capture and workers.
 *
 * Ordinary controls are read only at the chapter/run boundary and then frozen
 * in WritePolicySnapshot. The emergency-stop epoch deliberately does not live
 * here: it is the single live guard and remains owned by
 * WritePolicySnapshotFactory::assertEmergencyEpochCurrent().
 */
final class HarnessControlPlane
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_CANARY = 'canary';
    public const MODE_ON = 'on';

    public const DEFAULT_MODE = self::MODE_OFF;
    public const DEFAULT_FENCING_ENFORCED = false;

    private function __construct()
    {
    }

    /** @return array{harness_mode:string,configured_mode:string,fencing_enforced:bool,rollout_bps:int,rollout_bucket:int|null} */
    public static function captureForNovel(int $novelId): array
    {
        $mode = self::DEFAULT_MODE;
        $fencingEnforced = self::DEFAULT_FENCING_ENFORCED;
        $rolloutBps = 0;

        if (class_exists('ConfigCenter', false)) {
            try {
                $mode = self::normalizeMode(
                    $novelId > 0
                        ? ConfigCenter::getNovel(
                            $novelId,
                            'ws_harness_mode',
                            self::DEFAULT_MODE,
                            'string'
                        )
                        : ConfigCenter::get(
                            'ws_harness_mode',
                            self::DEFAULT_MODE,
                            'string'
                        )
                );
            } catch (Throwable) {
                // An unreadable rollout mode must never opt a write into the
                // new Harness path.
                $mode = self::DEFAULT_MODE;
            }

            try {
                $rolloutBps = self::normalizeRolloutBps(
                    ConfigCenter::get(
                        'ws_harness_rollout_bps',
                        0,
                        'int'
                    )
                );
            } catch (Throwable) {
                $rolloutBps = 0;
            }

            try {
                // Fencing is a deployment-wide protocol barrier. It cannot be
                // overridden per novel even though rollout mode can.
                $fencingEnforced = self::normalizeBoolean(
                    ConfigCenter::get(
                        'ws_harness_fencing_enforced',
                        self::DEFAULT_FENCING_ENFORCED,
                        'bool'
                    ),
                    self::DEFAULT_FENCING_ENFORCED
                );
            } catch (Throwable) {
                $fencingEnforced = self::DEFAULT_FENCING_ENFORCED;
            }
        }

        $configuredMode = $mode;
        $bucket = $novelId > 0 ? self::stableRolloutBucket($novelId) : null;
        if ($mode === self::MODE_CANARY) {
            $mode = $novelId > 0
                ? self::effectiveModeForNovel($mode, $novelId, $rolloutBps)
                : self::MODE_SHADOW;
        }

        return [
            'harness_mode' => $mode,
            'configured_mode' => $configuredMode,
            'fencing_enforced' => $fencingEnforced,
            'rollout_bps' => $rolloutBps,
            'rollout_bucket' => $bucket,
        ];
    }

    public static function normalizeMode(mixed $value): string
    {
        return is_string($value) && in_array($value, [
            self::MODE_OFF,
            self::MODE_SHADOW,
            self::MODE_CANARY,
            self::MODE_ON,
        ], true) ? $value : self::DEFAULT_MODE;
    }

    public static function normalizeBoolean(mixed $value, bool $default): bool
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

    public static function allowsNewHarnessRun(string $mode): bool
    {
        return self::requireMode($mode) !== self::MODE_OFF;
    }

    public static function usesAuthority(string $mode): bool
    {
        return in_array(self::requireMode($mode), [self::MODE_CANARY, self::MODE_ON], true);
    }

    public static function stableRolloutBucket(int $novelId): int
    {
        if ($novelId <= 0) {
            throw new InvalidArgumentException('Harness rollout novel_id must be positive.');
        }
        $bytes = hash('sha256', 'harness-novel-rollout-v1:' . $novelId, true);
        $value = unpack('Nbucket', substr($bytes, 0, 4));
        return (int)$value['bucket'] % 10000;
    }

    public static function normalizeRolloutBps(mixed $value): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        return is_int($value) && $value >= 0 && $value <= 10000 ? $value : 0;
    }

    /** Canary authority is stable per novel; non-cohort novels remain shadow. */
    public static function effectiveModeForNovel(
        string $configuredMode,
        int $novelId,
        int $rolloutBps
    ): string {
        $mode = self::requireMode($configuredMode);
        $rolloutBps = self::normalizeRolloutBps($rolloutBps);
        if ($mode !== self::MODE_CANARY) {
            return $mode;
        }
        return self::stableRolloutBucket($novelId) < $rolloutBps
            ? self::MODE_CANARY
            : self::MODE_SHADOW;
    }

    public static function assertAuthorityIsFenced(string $mode, bool $fencingEnforced): void
    {
        if (self::usesAuthority($mode) && !$fencingEnforced) {
            throw new HarnessFencingEnforcementException(
                'Harness canary/on authority requires ws_harness_fencing_enforced=true.'
            );
        }
    }

    /**
     * Fail-closed validation for a writing worker after it restored the frozen
     * snapshot. A disabled flag keeps pre-rollout snapshots compatible; an
     * explicitly enabled flag requires every durable coordinate to be present
     * and byte-for-byte equal to the claimed lease.
     *
     * @param array<string,mixed> $lease
     * @param array<string,mixed> $taskRow
     */
    public static function assertWritingWorkerFence(
        bool $fencingEnforced,
        array $lease,
        array $taskRow
    ): void {
        if (!$fencingEnforced) {
            return;
        }

        $taskId = $lease['task_id'] ?? null;
        $owner = $lease['lease_owner'] ?? null;
        $token = $lease['lease_token'] ?? null;
        $generation = $lease['fence_generation'] ?? null;
        $rowTaskId = $taskRow['task_id'] ?? null;
        $rowOwner = $taskRow['lease_owner'] ?? null;
        $rowToken = $taskRow['lease_token'] ?? null;
        $rowGeneration = $taskRow['fence_generation'] ?? null;

        $normalizedGeneration = self::positiveInteger($generation);
        $normalizedRowGeneration = self::positiveInteger($rowGeneration);
        if (!is_string($taskId)
            || preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $taskId) !== 1
            || !is_string($rowTaskId) || !hash_equals($taskId, $rowTaskId)
            || !is_string($owner)
            || preg_match('/^[a-zA-Z0-9_.:@-]{1,120}$/D', $owner) !== 1
            || !is_string($rowOwner) || !hash_equals($owner, $rowOwner)
            || !is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || !is_string($rowToken) || !hash_equals($token, $rowToken)
            || $normalizedGeneration === null || $normalizedRowGeneration === null
            || $normalizedGeneration !== $normalizedRowGeneration) {
            throw new HarnessFencingEnforcementException(
                'Harness writing worker is missing an exact durable lease fence.'
            );
        }
    }

    /**
     * @param array<string,mixed> $jobRow
     * @param array<string,mixed> $leaseIdentity
     */
    public static function assertPostprocessWorkerFence(
        bool $fencingEnforced,
        array $jobRow,
        array $leaseIdentity
    ): void {
        if (!$fencingEnforced) {
            return;
        }

        $owner = $jobRow['lease_owner'] ?? null;
        $token = $jobRow['lease_token'] ?? null;
        $generation = $jobRow['fence_generation'] ?? null;
        $claimedOwner = $leaseIdentity['owner'] ?? null;
        $claimedToken = $leaseIdentity['token'] ?? null;
        $claimedGeneration = $leaseIdentity['generation'] ?? null;

        $jobId = self::positiveInteger($jobRow['id'] ?? null);
        $normalizedGeneration = self::positiveInteger($generation);
        $normalizedClaimedGeneration = self::positiveInteger($claimedGeneration);
        if ($jobId === null
            || !is_string($owner)
            || preg_match('/^[a-zA-Z0-9_.:@-]{1,120}$/D', $owner) !== 1
            || !is_string($claimedOwner) || !hash_equals($owner, $claimedOwner)
            || !is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || !is_string($claimedToken) || !hash_equals($token, $claimedToken)
            || $normalizedGeneration === null || $normalizedClaimedGeneration === null
            || $normalizedGeneration !== $normalizedClaimedGeneration) {
            throw new HarnessFencingEnforcementException(
                'Harness post-processing worker is missing an exact durable lease fence.'
            );
        }
    }

    private static function requireMode(string $mode): string
    {
        if (!in_array($mode, [
            self::MODE_OFF,
            self::MODE_SHADOW,
            self::MODE_CANARY,
            self::MODE_ON,
        ], true)) {
            throw new InvalidArgumentException('Invalid Harness mode.');
        }

        return $mode;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($parsed) || (string)$parsed !== $value) {
            return null;
        }

        return $parsed;
    }
}
