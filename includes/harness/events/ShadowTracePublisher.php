<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ShadowTraceAdapter.php';
require_once dirname(__DIR__, 2) . '/db.php';

/**
 * Fail-open orchestration boundary for legacy writing task shadow traces.
 *
 * The frozen WritePolicySnapshot is the only enablement input. No live
 * ConfigCenter/control-plane read is allowed here. A true return means the
 * adapter actually completed; skipped and failed publications both return
 * false, and every failure is reported explicitly.
 */
final class ShadowTracePublisher
{
    private Closure $logger;

    public function __construct(
        private readonly ShadowTraceAdapter $adapter,
        ?callable $logger = null
    ) {
        $this->logger = $logger === null
            ? static function (string $message): void {
                error_log($message);
            }
            : Closure::fromCallable($logger);
    }

    public function publish(ShadowTrace $trace): bool
    {
        try {
            $row = $this->adapter->findLegacyTask($trace->taskId);
            if ($row === null) {
                throw new RuntimeException('Legacy writing task disappeared before shadow publish.');
            }
            return $this->publishRow($trace, $row);
        } catch (Throwable $exception) {
            $this->reportFailure($trace->taskId, $trace->transition, $exception);
            return false;
        }
    }

    public function publishCancellationScope(int $novelId, ?string $taskId = null): int
    {
        try {
            $rows = $this->adapter->findCancellationScope($novelId, $taskId);
        } catch (Throwable $exception) {
            $this->reportFailure(
                $taskId ?? ('novel_' . max(0, $novelId)),
                ShadowTrace::CANCEL_REQUESTED,
                $exception
            );
            return 0;
        }

        $published = 0;
        foreach ($rows as $row) {
            $rowTaskId = $row['task_id'] ?? null;
            try {
                if (!is_string($rowTaskId)) {
                    throw new RuntimeException('Cancellation scope returned no task_id.');
                }
                $trace = ShadowTrace::cancelRequested($rowTaskId);
                if ($this->publishRow($trace, $row)) {
                    $published++;
                }
            } catch (Throwable $exception) {
                $safeTaskId = is_string($rowTaskId)
                    && preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $rowTaskId) === 1
                        ? $rowTaskId
                        : 'unknown_task';
                $this->reportFailure(
                    $safeTaskId,
                    ShadowTrace::CANCEL_REQUESTED,
                    $exception
                );
            }
        }
        return $published;
    }

    /**
     * Application entry point used by the legacy repository. It also catches
     * connection/adapter construction failures, before an instance exists.
     */
    public static function publishApplication(ShadowTrace $trace): bool
    {
        try {
            return (new self(new M62ShadowTraceAdapter(DB::getPdo())))->publish($trace);
        } catch (Throwable $exception) {
            self::writeFallbackError($trace->taskId, $trace->transition, $exception);
            return false;
        }
    }

    public static function publishCancellationApplication(
        int $novelId,
        ?string $taskId = null
    ): int {
        try {
            return (new self(new M62ShadowTraceAdapter(DB::getPdo())))
                ->publishCancellationScope($novelId, $taskId);
        } catch (Throwable $exception) {
            self::writeFallbackError(
                $taskId ?? ('novel_' . max(0, $novelId)),
                ShadowTrace::CANCEL_REQUESTED,
                $exception
            );
            return 0;
        }
    }

    /** @param array<string,mixed> $row */
    private function publishRow(ShadowTrace $trace, array $row): bool
    {
        $rowTaskId = $row['task_id'] ?? null;
        if (!is_string($rowTaskId) || !hash_equals($trace->taskId, $rowTaskId)) {
            throw new RuntimeException('Shadow publication task identity does not match its row.');
        }

        $snapshot = self::frozenPolicyFromRow($row);
        if ($snapshot === null || $snapshot->harnessMode() === HarnessControlPlane::MODE_OFF) {
            return false;
        }

        // P1-02 is a projection only. Even a frozen canary/on task remains a
        // shadow Run; P2 ChapterRunService is the sole authority Run creator.
        $this->adapter->persist(
            $trace,
            $row,
            $snapshot,
            LegacyWritingTaskRunIdentity::AUTHORITY_MODE
        );
        return true;
    }

    /** @param array<string,mixed> $row */
    private static function frozenPolicyFromRow(array $row): ?WritePolicySnapshot
    {
        if (!array_key_exists('policy_snapshot_json', $row)
            || !array_key_exists('policy_snapshot_hash', $row)) {
            throw new RuntimeException('Shadow source row is missing frozen policy columns.');
        }
        $json = $row['policy_snapshot_json'];
        $hash = $row['policy_snapshot_hash'];
        $jsonMissing = $json === null || $json === '';
        $hashMissing = $hash === null || $hash === '';
        if ($jsonMissing && $hashMissing) {
            // Pre-M60 compatibility row: it has no authenticated enablement
            // decision, therefore it cannot opt itself into shadow writes.
            return null;
        }
        if ($jsonMissing !== $hashMissing
            || !is_string($json)
            || !is_string($hash)
            || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Shadow source policy pair is malformed.');
        }

        $snapshot = WritePolicySnapshot::fromJson($json);
        if (!hash_equals($snapshot->checksum(), $hash)) {
            throw new RuntimeException('Shadow source policy row hash is invalid.');
        }
        return $snapshot;
    }

    private function reportFailure(
        string $taskId,
        string $transition,
        Throwable $exception
    ): void {
        $message = self::failureMessage($taskId, $transition, $exception);
        try {
            ($this->logger)($message);
        } catch (Throwable) {
            // Observability failure is itself fail-open for the legacy task.
            self::writeFallbackError($taskId, $transition, $exception);
        }
    }

    private static function writeFallbackError(
        string $taskId,
        string $transition,
        Throwable $exception
    ): void {
        try {
            error_log(self::failureMessage($taskId, $transition, $exception));
        } catch (Throwable) {
        }
    }

    private static function failureMessage(
        string $taskId,
        string $transition,
        Throwable $exception
    ): string {
        $safeTaskId = preg_match('/^[a-zA-Z0-9_]{8,64}$/D', $taskId) === 1
            ? $taskId
            : 'unknown_task';
        $safeTransition = preg_match('/^[a-z_]{3,32}$/D', $transition) === 1
            ? $transition
            : 'unknown_transition';
        return sprintf(
            '[HarnessShadow][ERROR] publish_failed task_id=%s transition=%s exception=%s code=%d',
            $safeTaskId,
            $safeTransition,
            $exception::class,
            (int)$exception->getCode()
        );
    }
}
