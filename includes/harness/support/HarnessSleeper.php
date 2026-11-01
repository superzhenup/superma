<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

interface HarnessSleeper
{
    public function sleepSeconds(int $seconds): void;

    public function sleepMicroseconds(int $microseconds): void;
}

final class NativeHarnessSleeper implements HarnessSleeper
{
    public function sleepSeconds(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Sleep duration cannot be negative.');
        }
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    public function sleepMicroseconds(int $microseconds): void
    {
        if ($microseconds < 0) {
            throw new InvalidArgumentException('Sleep duration cannot be negative.');
        }
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
