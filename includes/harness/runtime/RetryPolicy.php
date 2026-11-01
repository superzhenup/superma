<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RuntimeExceptions.php';
require_once __DIR__ . '/RuntimeData.php';
require_once __DIR__ . '/Deadline.php';

final readonly class RetryPolicy
{
    /** @var list<class-string<Throwable>> */
    public array $retryableExceptions;

    /** @param list<class-string<Throwable>> $retryableExceptions */
    public function __construct(
        public int $maxAttempts = 3,
        public int $initialDelaySeconds = 5,
        public int $multiplier = 2,
        public int $maximumDelaySeconds = 300,
        array $retryableExceptions = [
            RetryableModelException::class,
            RetryableInfrastructureException::class,
        ]
    ) {
        HarnessPersistenceValue::boundedInt($maxAttempts, 'retry.max_attempts', 1, 100);
        HarnessPersistenceValue::boundedInt(
            $initialDelaySeconds,
            'retry.initial_delay_seconds',
            1,
            86400
        );
        HarnessPersistenceValue::boundedInt($multiplier, 'retry.multiplier', 1, 100);
        HarnessPersistenceValue::boundedInt(
            $maximumDelaySeconds,
            'retry.maximum_delay_seconds',
            1,
            604800
        );
        if ($initialDelaySeconds > $maximumDelaySeconds) {
            throw new InvalidArgumentException(
                'Retry initial delay cannot exceed its maximum delay.'
            );
        }

        $normalized = [];
        foreach ($retryableExceptions as $class) {
            if (!is_string($class) || !is_a($class, Throwable::class, true)) {
                throw new InvalidArgumentException(
                    'Retry policy exception entries must name Throwable classes.'
                );
            }
            $normalized[$class] = $class;
        }
        $this->retryableExceptions = array_values($normalized);
    }

    public static function noRetry(): self
    {
        return new self(1, 1, 1, 1, []);
    }

    public function isRetryable(Throwable $exception): bool
    {
        foreach ($this->retryableExceptions as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }
        return false;
    }

    public function delayForAttempt(int $attempt): int
    {
        $attempt = HarnessPersistenceValue::boundedInt($attempt, 'retry.attempt', 1, 100);
        $delay = $this->initialDelaySeconds;
        for ($index = 1; $index < $attempt; $index++) {
            if ($delay >= $this->maximumDelaySeconds
                || $delay > intdiv($this->maximumDelaySeconds, $this->multiplier)) {
                return $this->maximumDelaySeconds;
            }
            $delay *= $this->multiplier;
        }
        return min($delay, $this->maximumDelaySeconds);
    }

    public function retryAt(
        int $failedAttempt,
        DateTimeImmutable $now,
        Deadline $deadline
    ): ?DateTimeImmutable {
        if ($failedAttempt >= $this->maxAttempts) {
            return null;
        }
        $retryAt = $now->modify('+' . $this->delayForAttempt($failedAttempt) . ' seconds');
        return $deadline->allows($retryAt) ? $retryAt : null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return RuntimeData::normalizeArray([
            'max_attempts' => $this->maxAttempts,
            'initial_delay_seconds' => $this->initialDelaySeconds,
            'multiplier' => $this->multiplier,
            'maximum_delay_seconds' => $this->maximumDelaySeconds,
            'retryable_exception_classes' => $this->retryableExceptions,
        ], 'retry.policy');
    }
}
