<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RuntimeExceptions.php';
require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';

final class LeaseHeartbeat
{
    private Closure $renew;
    private Closure $clock;
    private ?DateTimeImmutable $lastBeatAt = null;

    public function __construct(
        callable $renew,
        private readonly int $intervalSeconds,
        ?callable $clock = null
    ) {
        if ($intervalSeconds < 1 || $intervalSeconds > 3600) {
            throw new InvalidArgumentException(
                'Lease heartbeat interval must be between 1 and 3600 seconds.'
            );
        }
        $this->renew = Closure::fromCallable($renew);
        $this->clock = $clock === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            )
            : Closure::fromCallable($clock);
    }

    public function beatIfDue(): bool
    {
        $now = $this->now();
        if ($this->lastBeatAt !== null
            && $now < $this->lastBeatAt->modify('+' . $this->intervalSeconds . ' seconds')) {
            return false;
        }
        $this->renewAt($now);
        return true;
    }

    public function force(): void
    {
        $this->renewAt($this->now());
    }

    private function renewAt(DateTimeImmutable $now): void
    {
        try {
            ($this->renew)();
        } catch (HarnessLeaseLostException $exception) {
            throw new LeaseLostException(previous: $exception);
        }
        $this->lastBeatAt = $now;
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeControlException(
                'Lease heartbeat clock must return DateTimeImmutable.'
            );
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }
}
