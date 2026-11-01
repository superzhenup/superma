<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/OutlineEnhancementContracts.php';
require_once dirname(__DIR__) . '/HarnessEmergencyStop.php';

final class OutlineEnhancementEmergencyStopException extends RuntimeException
{
}

/**
 * The emergency epoch is the only deliberately live outline setting. Runs
 * freeze its value, while every execution/commit boundary rereads it.
 */
final class OutlineEmergencyEpoch
{
    public static function current(): int
    {
        try {
            if (!class_exists('ConfigCenter', false)) {
                require_once dirname(__DIR__, 2) . '/config_center.php';
            }
            if (!class_exists('ConfigCenter', false)
                || !method_exists('ConfigCenter', 'clearCache')) {
                throw new RuntimeException('ConfigCenter is unavailable.');
            }
            ConfigCenter::clearCache(HarnessEmergencyStop::SETTING_KEY);
            return self::normalize(ConfigCenter::get(
                HarnessEmergencyStop::SETTING_KEY,
                null,
                'string'
            ));
        } catch (OutlineEnhancementEmergencyStopException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutlineEnhancementEmergencyStopException(
                'Live outline emergency epoch could not be read; execution stopped fail-closed.',
                0,
                $exception
            );
        }
    }

    public static function normalize(mixed $value): int
    {
        if (is_int($value)) {
            $epoch = $value;
        } elseif (is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 0,
                    'max_range' => HarnessEmergencyStop::MAX_EPOCH,
                ],
            ]);
            $epoch = is_int($parsed) ? $parsed : -1;
        } else {
            $epoch = -1;
        }
        if ($epoch < 0 || $epoch > HarnessEmergencyStop::MAX_EPOCH) {
            throw new OutlineEnhancementEmergencyStopException(
                'Live outline emergency epoch is missing, malformed, or out of range.'
            );
        }
        return $epoch;
    }

    public static function assertCurrent(int $captured, int $current): void
    {
        $captured = self::normalize($captured);
        $current = self::normalize($current);
        if ($captured !== $current) {
            throw new OutlineEnhancementEmergencyStopException(sprintf(
                'Outline emergency epoch changed from %d to %d.',
                $captured,
                $current
            ));
        }
    }

    private function __construct()
    {
    }
}
