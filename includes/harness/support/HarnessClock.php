<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * One time source for lease, retry and worker orchestration code.
 *
 * Production uses SystemHarnessClock. Tests may install a deterministic clock
 * only through HarnessRuntime's CLI test capability.
 */
interface HarnessClock
{
    public function unixTime(): int;

    public function preciseTime(): float;

    public function sqlDateTime(?int $unixTime = null): string;
}

final class SystemHarnessClock implements HarnessClock
{
    public function unixTime(): int
    {
        return time();
    }

    public function preciseTime(): float
    {
        return microtime(true);
    }

    public function sqlDateTime(?int $unixTime = null): string
    {
        return gmdate('Y-m-d H:i:s', $unixTime ?? $this->unixTime());
    }
}
