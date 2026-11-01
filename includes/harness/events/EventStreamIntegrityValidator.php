<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

final class EventStreamIntegrityException extends RuntimeException
{
}

final readonly class EventStreamIntegritySummary
{
    public function __construct(
        public int $eventCount,
        public ?int $firstEventNo,
        public ?int $lastEventNo
    ) {
    }
}

final class EventStreamIntegrityValidator
{
    /**
     * @param list<mixed> $events
     * @param null|callable(mixed,int):mixed $runIdOf
     * @param null|callable(mixed,int):mixed $eventNoOf
     */
    public static function validate(
        int $expectedRunId,
        int $nextEventNo,
        array $events,
        ?callable $runIdOf = null,
        ?callable $eventNoOf = null
    ): EventStreamIntegritySummary {
        return self::validateRange($expectedRunId, 0, $nextEventNo, $events, $runIdOf, $eventNoOf);
    }

    /** Validate a complete tail after an independently verified immutable prefix. */
    public static function validateRange(
        int $expectedRunId,
        int $afterEventNo,
        int $nextEventNo,
        array $events,
        ?callable $runIdOf = null,
        ?callable $eventNoOf = null
    ): EventStreamIntegritySummary {
        if ($afterEventNo < 0 || $afterEventNo >= $nextEventNo) {
            throw new EventStreamIntegrityException('Event prefix cursor is outside the stream.');
        }
        if ($expectedRunId < 1) {
            throw new EventStreamIntegrityException('Event stream expected Run ID must be positive.');
        }
        if ($nextEventNo < 1) {
            throw new EventStreamIntegrityException('Event stream next_event_no must be at least 1.');
        }
        if ($events === []) {
            if ($nextEventNo !== $afterEventNo + 1) {
                throw new EventStreamIntegrityException(
                    "Empty Event stream for Run {$expectedRunId} requires next_event_no=1,"
                    . " found next_event_no={$nextEventNo}."
                );
            }
            return new EventStreamIntegritySummary(0, null, null);
        }

        $previous = $afterEventNo;
        foreach ($events as $index => $event) {
            $runId = $runIdOf === null
                ? self::runId($event, $index)
                : $runIdOf($event, $index);
            if (!is_int($runId) || $runId !== $expectedRunId) {
                throw new EventStreamIntegrityException(
                    "Event stream item {$index} belongs to Run "
                    . (is_int($runId) ? $runId : 'an unknown')
                    . ", expected Run {$expectedRunId}."
                );
            }
            $eventNo = $eventNoOf === null
                ? self::eventNo($event, $index)
                : $eventNoOf($event, $index);
            if (!is_int($eventNo) || $eventNo < 1) {
                throw new EventStreamIntegrityException(
                    "Event stream item {$index} has an invalid event_no."
                );
            }
            $expected = $afterEventNo + $index + 1;
            if ($eventNo !== $expected || $eventNo !== $previous + 1) {
                throw new EventStreamIntegrityException(
                    "Event stream item {$index} of Run {$expectedRunId} has"
                    . " event_no={$eventNo}; the complete stream requires"
                    . " event_no={$expected}."
                );
            }
            $previous = $eventNo;
        }

        if ($previous !== $nextEventNo - 1) {
            throw new EventStreamIntegrityException(
                "Event stream tail {$previous} of Run {$expectedRunId} does not"
                . " match next_event_no={$nextEventNo}."
            );
        }
        return new EventStreamIntegritySummary(count($events), $afterEventNo + 1, $previous);
    }

    private static function runId(mixed $event, int $index): mixed
    {
        if ($event instanceof GenerationEvent) {
            return $event->runId;
        }
        if (is_array($event)) {
            return $event['run_id'] ?? null;
        }
        throw new EventStreamIntegrityException("Event stream item {$index} is malformed.");
    }

    private static function eventNo(mixed $event, int $index): mixed
    {
        if ($event instanceof GenerationEvent) {
            return $event->eventNo;
        }
        if (is_array($event)) {
            return $event['event_no'] ?? null;
        }
        throw new EventStreamIntegrityException("Event stream item {$index} is malformed.");
    }

    private function __construct()
    {
    }
}
