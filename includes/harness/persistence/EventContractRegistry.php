<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/GenerationEvent.php';
require_once __DIR__ . '/GenerationStateMachine.php';

enum EventContractCategory: string
{
    case LIFECYCLE = 'lifecycle';
    case INTENT = 'intent';
    case OBSERVATION = 'observation';
    case OWNERSHIP = 'ownership';
    case HISTORICAL = 'historical';
}

enum EventResolutionScenario: string
{
    case APPEND = 'append';
    case REPLAY = 'replay';
    case CLIENT_STREAM = 'client_stream';
    case ARCHIVE = 'archive';
}

enum EventContractRegistration: string
{
    case CURRENT = 'current';
    case REGISTERED_HISTORICAL = 'registered_historical';
    case OPAQUE_HISTORICAL = 'opaque_historical';
}

final readonly class EventContract
{
    /** @param Closure(array<string,mixed>):void $validator */
    public function __construct(
        public EventContractCategory $category,
        public bool $projectsLifecycle,
        private Closure $validator
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function validate(array $payload): void
    {
        ($this->validator)($payload);
    }
}

final readonly class EventContractResolution
{
    public function __construct(
        public EventResolutionScenario $scenario,
        public EventContractRegistration $registration,
        public EventContract $contract,
        public bool $clientOutputAllowed
    ) {
    }

    public function projectsLifecycle(): bool
    {
        return $this->contract->projectsLifecycle;
    }
}

final class EventContractRegistry
{
    /** @var array<string,EventContract>|null */
    private static ?array $contracts = null;
    /** @var array<string,EventContract>|null */
    private static ?array $historicalContracts = null;

    /** @param array<string,mixed> $payload */
    public static function resolve(
        EventResolutionScenario $scenario,
        string $eventType,
        int $eventVersion,
        array $payload
    ): EventContractResolution {
        $key = $eventType . ':' . $eventVersion;
        $contract = self::contracts()[$key] ?? null;
        $registration = EventContractRegistration::CURRENT;
        if (!$contract instanceof EventContract && $scenario !== EventResolutionScenario::APPEND) {
            $contract = self::historicalContracts()[$key] ?? null;
            $registration = EventContractRegistration::REGISTERED_HISTORICAL;
        }
        if (!$contract instanceof EventContract
            && $scenario !== EventResolutionScenario::APPEND
            && str_starts_with($eventType, 'historical.opaque.')
            && !array_key_exists('state', $payload)
            && (($payload['projects_lifecycle'] ?? false) === false)) {
            $contract = new EventContract(
                EventContractCategory::HISTORICAL,
                false,
                static function (): void {
                }
            );
            $registration = EventContractRegistration::OPAQUE_HISTORICAL;
        }
        if (!$contract instanceof EventContract) {
            throw new InvalidArgumentException(
                $scenario === EventResolutionScenario::APPEND
                    ? 'Run Event type or version is not registered as current.'
                    : 'Event type or version is not authorized for this resolution scenario.'
            );
        }
        $contract->validate($payload);
        if ($registration === EventContractRegistration::OPAQUE_HISTORICAL
            && $contract->projectsLifecycle) {
            throw new LogicException('Opaque historical Events must be non-projecting.');
        }
        return new EventContractResolution(
            $scenario,
            $registration,
            $contract,
            $scenario !== EventResolutionScenario::CLIENT_STREAM
                || $registration !== EventContractRegistration::OPAQUE_HISTORICAL
        );
    }

    public static function contract(string $eventType, int $eventVersion): EventContract
    {
        $contract = self::contracts()[$eventType . ':' . $eventVersion] ?? null;
        if (!$contract instanceof EventContract) {
            throw new InvalidArgumentException('Run Event type or version is not registered as current.');
        }
        return $contract;
    }

    public static function validate(GenerationEventDraft $draft): EventContract
    {
        $payload = HarnessPersistenceValue::decodeJsonArray(
            $draft->payloadJson,
            'event.contract_payload'
        );
        return self::resolve(
            EventResolutionScenario::APPEND,
            $draft->eventType,
            $draft->eventVersion,
            $payload
        )->contract;
    }

    /** @param array<string,mixed> $payload */
    public static function validateHistorical(
        string $eventType,
        int $eventVersion,
        array $payload
    ): EventContract {
        return self::resolve(
            EventResolutionScenario::ARCHIVE,
            $eventType,
            $eventVersion,
            $payload
        )->contract;
    }

    /** @return array<string,EventContract> */
    private static function contracts(): array
    {
        if (self::$contracts !== null) {
            return self::$contracts;
        }
        $any = static function (array $payload): void {
        };
        $stepState = static function (array $allowed): Closure {
            return static function (array $payload) use ($allowed): void {
                if (!is_string($payload['stage_name'] ?? null)
                    || !is_int($payload['ordinal'] ?? null)
                    || !is_int($payload['attempt'] ?? null)
                    || !is_string($payload['state'] ?? null)
                    || !in_array($payload['state'], $allowed, true)) {
                    throw new InvalidArgumentException(
                        'Step Event type and payload state conflict.'
                    );
                }
            };
        };
        $attemptState = static function (array $allowed): Closure {
            return static function (array $payload) use ($allowed): void {
                $state = $payload['state'] ?? $payload['status'] ?? null;
                if (!is_string($state) || !in_array($state, $allowed, true)
                    || (isset($payload['attempt_no']) && !is_int($payload['attempt_no']))) {
                    throw new InvalidArgumentException(
                        'Attempt Event type and payload state conflict.'
                    );
                }
            };
        };
        $legacy = static function (array $payload): void {
            if (($payload['source'] ?? null) !== 'writing_tasks'
                || !is_string($payload['legacy_task_id'] ?? null)
                || !is_string($payload['transition'] ?? null)
                || !is_string($payload['legacy_state'] ?? null)
                || !is_int($payload['progress'] ?? null)
                || $payload['progress'] < 0 || $payload['progress'] > 100
                || !is_bool($payload['cancel_requested'] ?? null)
                || !is_bool($payload['content_saved'] ?? null)) {
                throw new InvalidArgumentException('Legacy writing Event payload is invalid.');
            }
        };
        $budget = static function (array $payload): void {
            if (!is_string($payload['reservation'] ?? null)
                || !is_array($payload['usage'] ?? null)) {
                throw new InvalidArgumentException('Model budget Event payload is invalid.');
            }
        };
        $runState = static function (array $allowed): Closure {
            return static function (array $payload) use ($allowed): void {
                $state = $payload['state'] ?? null;
                $progress = $payload['progress'] ?? null;
                if (!is_string($state) || !in_array($state, $allowed, true)
                    || ($progress !== null && (!is_int($progress)
                        || $progress < 0 || $progress > 100))) {
                    throw new InvalidArgumentException(
                        'Run lifecycle Event type and payload state conflict.'
                    );
                }
            };
        };

        $contracts = [];
        $register = static function (
            string $type,
            EventContractCategory $category,
            bool $projectsLifecycle,
            Closure $validator
        ) use (&$contracts): void {
            $contracts[$type . ':1'] = new EventContract($category, $projectsLifecycle, $validator);
        };
        $register('run.completed', EventContractCategory::LIFECYCLE, true,
            $runState(['succeeded', 'aborted_after_commit']));
        $register('run.canceled', EventContractCategory::LIFECYCLE, true,
            $runState(['canceled']));
        $register('run.failed', EventContractCategory::LIFECYCLE, true,
            $runState(['failed']));
        $register('run.retry_scheduled', EventContractCategory::LIFECYCLE, true,
            $runState(['retry_wait']));
        $register('approval.requested', EventContractCategory::LIFECYCLE, true,
            $runState(['waiting_approval']));
        $register('canonical.quarantined', EventContractCategory::LIFECYCLE, true,
            $runState(['quarantined']));
        $register('run.cancel_requested', EventContractCategory::INTENT, false,
            static function (array $payload): void {
                $state = $payload['state'] ?? null;
                if (!is_string($state)
                    || !GenerationRunState::tryFrom($state) instanceof GenerationRunState
                    || ($payload['cancel_requested'] ?? null) !== true) {
                    throw new InvalidArgumentException('Run cancel intent payload is invalid.');
                }
            });
        $register('step.started', EventContractCategory::LIFECYCLE, false,
            $stepState(['running']));
        $register('step.completed', EventContractCategory::LIFECYCLE, false,
            $stepState(['succeeded']));
        $register('step.failed', EventContractCategory::LIFECYCLE, false,
            $stepState(['retry_wait', 'failed']));
        $register('step.canceled', EventContractCategory::LIFECYCLE, false,
            $stepState(['canceled']));
        $register('attempt.started', EventContractCategory::OBSERVATION, false,
            $attemptState(['running']));
        $register('attempt.completed', EventContractCategory::OBSERVATION, false,
            $attemptState(['completed']));
        $register('attempt.failed', EventContractCategory::OBSERVATION, false,
            $attemptState(['failed']));
        $register('attempt.canceled', EventContractCategory::OBSERVATION, false,
            $attemptState(['canceled']));
        foreach (['queued', 'claimed', 'heartbeat', 'terminal', 'cancel_requested'] as $transition) {
            $register('legacy.writing_task.' . $transition,
                EventContractCategory::LIFECYCLE, true, $legacy);
        }
        foreach (['model.budget.reserved', 'model.budget.settled'] as $type) {
            $register($type, EventContractCategory::OBSERVATION, false, $budget);
        }
        foreach (['reserved', 'invoking', 'unknown', 'settled', 'released'] as $operationState) {
            $register('side_effect.' . $operationState, EventContractCategory::OBSERVATION, false,
                static function (array $payload) use ($operationState): void {
                    if (($payload['operation_state'] ?? null) !== $operationState) {
                        throw new InvalidArgumentException('Side-effect Event state conflicts with its type.');
                    }
                    HarnessPersistenceValue::hash($payload['operation_uid'] ?? null, 'operation.uid');
                    HarnessPersistenceValue::hash($payload['request_hash'] ?? null, 'operation.request_hash');
                    HarnessPersistenceValue::asciiString($payload['operation_key'] ?? null, 'operation.key', 1, 191);
                    HarnessPersistenceValue::identifier($payload['kind'] ?? null, 'operation.kind', 32);
                    HarnessPersistenceValue::asciiString($payload['target'] ?? null, 'operation.target', 1, 191);
                    HarnessPersistenceValue::positiveInt($payload['fence_generation'] ?? null, 'operation.fence');
                    if ($operationState === 'settled') {
                        HarnessPersistenceValue::hash($payload['result_hash'] ?? null, 'operation.result_hash');
                    }
                });
        }
        foreach (['agent.model.completed', 'agent.model.failed', 'agent.tool.requested',
            'agent.tool.completed', 'agent.tool.failed', 'agent.loop.completed',
            'agent.loop.failed', 'run.created', 'draft.reviewed',
            'quality.completed', 'probe.concurrent_append'] as $type) {
            $register($type, EventContractCategory::OBSERVATION, false, $any);
        }
        $register('run.claimed', EventContractCategory::OWNERSHIP, false,
            static function (array $payload): void {
                if (!is_int($payload['attempt'] ?? null)
                    || $payload['attempt'] < 1
                    || !is_int($payload['fence_generation'] ?? null)
                    || $payload['fence_generation'] < 1
                    || !is_string($payload['lease_owner'] ?? null)
                    || $payload['lease_owner'] === ''
                    || !is_bool($payload['reclaimed'] ?? null)
                    || !is_bool($payload['recovery'] ?? null)) {
                    throw new InvalidArgumentException('Run claimed Event payload is invalid.');
                }
            });
        $register('step.claimed', EventContractCategory::OWNERSHIP, false,
            static function (array $payload): void {
                if (!is_int($payload['attempt'] ?? null)
                    || $payload['attempt'] < 1
                    || !is_int($payload['fence_generation'] ?? null)
                    || $payload['fence_generation'] < 1
                    || !is_bool($payload['reclaimed'] ?? null)) {
                    throw new InvalidArgumentException('Step claimed Event payload is invalid.');
                }
            });
        $register('run.progressed', EventContractCategory::OBSERVATION, true,
            static function (array $payload): void {
                if (!is_int($payload['progress'] ?? null)
                    || $payload['progress'] < 0 || $payload['progress'] > 100) {
                    throw new InvalidArgumentException('Run progress Event payload is invalid.');
                }
            });
        return self::$contracts = $contracts;
    }

    /** @return array<string,EventContract> */
    private static function historicalContracts(): array
    {
        if (self::$historicalContracts !== null) {
            return self::$historicalContracts;
        }
        return self::$historicalContracts = [
            'historical.run.completed:1' => new EventContract(
                EventContractCategory::HISTORICAL,
                false,
                static function (array $payload): void {
                    if (($payload['state'] ?? null) !== 'completed'
                        || ($payload['projects_lifecycle'] ?? null) !== false) {
                        throw new InvalidArgumentException('Historical completed Event is invalid.');
                    }
                }
            ),
        ];
    }

    private function __construct()
    {
    }
}
