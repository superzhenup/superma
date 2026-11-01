<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ToolContracts.php';

/**
 * An execution boundary must be able to interrupt the handler while it is
 * running.  Merely comparing elapsed time after a blocking call returns does
 * not satisfy this contract.
 */
interface ToolExecutionBoundary
{
    public function invoke(Closure $operation, int $timeoutMs): mixed;
}

/**
 * Hard timeout boundary for Unix PHP workers with the pcntl extension.
 *
 * SIGALRM has one-second precision, so the alarm is rounded up. ToolExecutor
 * still applies the exact millisecond budget after return. Existing process
 * alarms are never stolen: that situation fails closed before user code runs.
 */
final class PcntlAlarmToolExecutionBoundary implements ToolExecutionBoundary
{
    private static bool $active = false;

    public static function supported(): bool
    {
        return defined('SIGALRM')
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler')
            && function_exists('pcntl_async_signals');
    }

    public function invoke(Closure $operation, int $timeoutMs): mixed
    {
        if (!self::supported()) {
            throw new ToolTimeoutUnavailableException(
                'Interruptible Tool timeouts require the pcntl extension.'
            );
        }
        if (self::$active) {
            throw new ToolTimeoutUnavailableException(
                'Nested interruptible Tool execution is not supported.'
            );
        }

        $seconds = max(1, (int)ceil($timeoutMs / 1000));
        $previousAlarm = pcntl_alarm(0);
        if ($previousAlarm > 0) {
            pcntl_alarm($previousAlarm);
            throw new ToolTimeoutUnavailableException(
                'Tool execution cannot replace an existing process alarm.'
            );
        }

        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsync = pcntl_async_signals();
        self::$active = true;
        try {
            pcntl_async_signals(true);
            pcntl_signal(
                SIGALRM,
                static function (): never {
                    throw new ToolExecutionTimedOutException(
                        'Tool execution was interrupted at its timeout boundary.'
                    );
                }
            );
            pcntl_alarm($seconds);
            return $operation();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
            self::$active = false;
        }
    }
}

/** Fail-closed boundary used on runtimes that cannot interrupt PHP handlers. */
final class UnavailableToolExecutionBoundary implements ToolExecutionBoundary
{
    public function invoke(Closure $operation, int $timeoutMs): mixed
    {
        unset($operation, $timeoutMs);
        throw new ToolTimeoutUnavailableException(
            'No interruptible Tool execution boundary is available in this PHP runtime.'
        );
    }
}

final class ToolExecutionBoundaryFactory
{
    private function __construct()
    {
    }

    public static function forRuntime(): ToolExecutionBoundary
    {
        return PcntlAlarmToolExecutionBoundary::supported()
            ? new PcntlAlarmToolExecutionBoundary()
            : new UnavailableToolExecutionBoundary();
    }
}
