<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RuntimeExceptions.php';
require_once __DIR__ . '/RuntimeData.php';
require_once __DIR__ . '/RunRequest.php';

final class Deadline
{
    private Closure $clock;

    public function __construct(
        private readonly ?DateTimeImmutable $deadlineAt,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            )
            : Closure::fromCallable($clock);
    }

    public static function fromRequest(RunRequest $request, ?callable $clock = null): self
    {
        $deadline = $request->deadlineAt === null
            ? null
            : new DateTimeImmutable($request->deadlineAt, new DateTimeZone('UTC'));
        return new self($deadline, $clock);
    }

    public function at(): ?DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeControlException('Deadline clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function isExceeded(): bool
    {
        return $this->deadlineAt !== null && $this->now() >= $this->deadlineAt;
    }

    public function allows(DateTimeImmutable $instant): bool
    {
        return $this->deadlineAt === null
            || $instant->setTimezone(new DateTimeZone('UTC')) < $this->deadlineAt;
    }

    public function throwIfExceeded(): void
    {
        if ($this->isExceeded()) {
            throw new DeadlineExceededException();
        }
    }
}
