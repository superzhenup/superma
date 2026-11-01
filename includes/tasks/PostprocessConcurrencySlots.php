<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostprocessLeaseLostException.php';
require_once dirname(__DIR__) . '/harness/support/HarnessRuntime.php';

/** Installation-wide slots for participating postprocessors, released on DB disconnect. */
final class PostprocessConcurrencySlots
{
    public static function acquire(?callable $heartbeat = null): string
    {
        $started = HarnessRuntime::preciseTime();
        // Fixed across processes: a live setting change must not create two pools.
        while (HarnessRuntime::preciseTime() - $started < 1500) {
            if ($heartbeat !== null) $heartbeat();
            for ($i = 0; $i < 3; $i++) {
                $name = 'novel_postprocess_compute_v1_' . $i;
                if ((int)DB::fetchColumn('SELECT GET_LOCK(?,0)', [$name]) === 1) return $name;
            }
            HarnessRuntime::sleepMicroseconds(250000);
        }
        throw new RuntimeException('Timed out waiting for a post-processing computation slot.');
    }

    public static function release(?string $slot): void
    {
        if ($slot !== null) DB::fetchColumn('SELECT RELEASE_LOCK(?)', [$slot]);
    }
}
