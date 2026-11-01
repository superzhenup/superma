<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RuntimeExceptions.php';

final class CancellationToken
{
    private Closure $probe;
    private bool $requested = false;

    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe === null
            ? static fn (): bool => false
            : Closure::fromCallable($probe);
    }

    public function request(): void
    {
        $this->requested = true;
    }

    public function isCancellationRequested(): bool
    {
        if ($this->requested) {
            return true;
        }
        $result = ($this->probe)();
        if (!is_bool($result)) {
            throw new RuntimeControlException(
                'Cancellation probe must return a boolean value.'
            );
        }
        return $result;
    }

    public function throwIfCancellationRequested(): void
    {
        if ($this->isCancellationRequested()) {
            throw new RunCanceledException();
        }
    }
}
