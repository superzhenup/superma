<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/GenerationEvent.php';
require_once __DIR__ . '/EventContractRegistry.php';
require_once __DIR__ . '/RunEventStore.php';

enum GenerationTransitionSubject: string
{
    case RUN = 'run';
    case STEP = 'step';
    case ATTEMPT = 'attempt';
}

enum GenerationTransitionClassification: string
{
    case LIFECYCLE = 'lifecycle';
    case INTENT = 'intent';
    case OBSERVATION = 'observation';
    case OWNERSHIP = 'ownership';
}

final readonly class GenerationTransitionFact
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public GenerationTransitionSubject $subject,
        public int $subjectId,
        public string $before,
        public string $after,
        public string $reason,
        public GenerationTransitionClassification $classification,
        public ?GenerationEventDraft $eventDraft = null,
        public array $metadata = []
    ) {
        HarnessPersistenceValue::positiveInt($subjectId, 'transition.subject_id');
        HarnessPersistenceValue::identifier($before, 'transition.before', 64);
        HarnessPersistenceValue::identifier($after, 'transition.after', 64);
        HarnessPersistenceValue::identifier($reason, 'transition.reason', 96);
        HarnessSensitiveDataGuard::assertSafeStructure($metadata, 'transition.metadata');
    }
}

final readonly class GenerationTransitionResult
{
    /** @param list<GenerationTransitionFact> $facts */
    public function __construct(public mixed $value, public array $facts)
    {
        foreach ($facts as $fact) {
            if (!$fact instanceof GenerationTransitionFact) {
                throw new InvalidArgumentException('Transition result contains an invalid fact.');
            }
        }
    }
}

final class GenerationTransitionCoordinator
{
    private HarnessPersistenceDriver $db;
    private RunEventStore $events;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->events = new RunEventStore($this->db);
    }

    /** @param callable():GenerationTransitionResult $mutation */
    public function execute(callable $mutation): GenerationTransitionResult
    {
        return $this->db->transactional(function () use ($mutation): GenerationTransitionResult {
            $result = $mutation();
            if (!$result instanceof GenerationTransitionResult) {
                throw new LogicException('Transition coordinator mutation returned an invalid result.');
            }
            foreach ($result->facts as $fact) {
                $this->assertAuthoritativeFact($fact);
                $this->events->append($fact->eventDraft);
            }
            return $result;
        });
    }

    private function assertAuthoritativeFact(GenerationTransitionFact $fact): void
    {
        $draft = $fact->eventDraft;
        if (!$draft instanceof GenerationEventDraft) {
            throw new LogicException(
                'Coordinator transition facts require an authoritative Event.'
            );
        }
        $contract = EventContractRegistry::validate($draft);
        $expectedCategory = match ($fact->classification) {
            GenerationTransitionClassification::LIFECYCLE => EventContractCategory::LIFECYCLE,
            GenerationTransitionClassification::INTENT => EventContractCategory::INTENT,
            GenerationTransitionClassification::OBSERVATION => EventContractCategory::OBSERVATION,
            GenerationTransitionClassification::OWNERSHIP => EventContractCategory::OWNERSHIP,
        };
        if ($contract->category !== $expectedCategory) {
            throw new LogicException('Transition fact classification conflicts with Event category.');
        }
        if ($fact->classification === GenerationTransitionClassification::LIFECYCLE
            && !$contract->projectsLifecycle) {
            throw new LogicException('Lifecycle transition Event must project lifecycle state.');
        }
        if ($draft->runId !== $this->factRunId($fact)
            || $draft->stepId !== $this->factStepId($fact)
            || $draft->attemptId !== $this->factAttemptId($fact)) {
            throw new LogicException('Transition fact subject conflicts with Event coordinates.');
        }
        if ($fact->classification === GenerationTransitionClassification::LIFECYCLE) {
            $payload = HarnessPersistenceValue::decodeJsonArray(
                $draft->payloadJson,
                'transition.event_payload'
            );
            if (($payload['state'] ?? null) !== $fact->after) {
                throw new LogicException('Transition fact state conflicts with Event payload state.');
            }
        }
    }

    private function factRunId(GenerationTransitionFact $fact): int
    {
        return $fact->subject === GenerationTransitionSubject::RUN
            ? $fact->subjectId
            : HarnessPersistenceValue::positiveInt(
                $fact->metadata['run_id'] ?? null,
                'transition.metadata.run_id'
            );
    }

    private function factStepId(GenerationTransitionFact $fact): ?int
    {
        return match ($fact->subject) {
            GenerationTransitionSubject::RUN => null,
            GenerationTransitionSubject::STEP => $fact->subjectId,
            GenerationTransitionSubject::ATTEMPT => HarnessPersistenceValue::positiveInt(
                $fact->metadata['step_id'] ?? null,
                'transition.metadata.step_id'
            ),
        };
    }

    private function factAttemptId(GenerationTransitionFact $fact): ?int
    {
        return $fact->subject === GenerationTransitionSubject::ATTEMPT
            ? $fact->subjectId
            : null;
    }
}
