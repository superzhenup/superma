<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/HarnessSensitiveDataGuard.php';

enum GenerationEventSeverity: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
}

final readonly class GenerationEventDraft
{
    public int $runId;
    public ?int $stepId;
    public ?int $attemptId;
    public string $eventType;
    public int $eventVersion;
    public GenerationEventSeverity $severity;
    public string $eventKey;
    public string $actorType;
    public ?string $actorId;
    public ?int $causationEventId;
    public bool $clientVisible;
    public string $payloadJson;
    public string $payloadHash;

    /** @param array<string,mixed> $payload */
    public function __construct(
        int $runId,
        ?int $stepId,
        ?int $attemptId,
        string $eventType,
        int $eventVersion,
        GenerationEventSeverity $severity,
        string $eventKey,
        string $actorType,
        ?string $actorId,
        ?int $causationEventId,
        bool $clientVisible,
        array $payload
    ) {
        $this->runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $this->stepId = HarnessPersistenceValue::optionalPositiveInt($stepId, 'step_id');
        $this->attemptId = HarnessPersistenceValue::optionalPositiveInt($attemptId, 'attempt_id');
        $this->eventType = HarnessPersistenceValue::identifier($eventType, 'event_type', 96);
        $this->eventVersion = HarnessPersistenceValue::boundedInt(
            $eventVersion,
            'event_version',
            1,
            1000
        );
        $this->severity = $severity;
        $this->eventKey = HarnessPersistenceValue::asciiString($eventKey, 'event_key', 1, 191);
        $this->actorType = HarnessPersistenceValue::identifier($actorType, 'actor_type', 32);
        $this->actorId = HarnessPersistenceValue::optionalString($actorId, 'actor_id', 191);
        $this->causationEventId = HarnessPersistenceValue::optionalPositiveInt(
            $causationEventId,
            'causation_event_id'
        );
        $this->clientVisible = $clientVisible;
        HarnessSensitiveDataGuard::assertSafeStructure($payload, 'event.payload');
        $this->payloadJson = HarnessPersistenceValue::canonicalMysqlJson($payload);
        HarnessSensitiveDataGuard::assertSafeText($this->payloadJson, 'event.payload');
        $this->payloadHash = hash('sha256', $this->payloadJson);
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        $event = GenerationEvent::fromRow($row);
        if ($event->runId !== $this->runId
            || $event->stepId !== $this->stepId
            || $event->attemptId !== $this->attemptId
            || $event->eventType !== $this->eventType
            || $event->eventVersion !== $this->eventVersion
            || $event->severity !== $this->severity
            || $event->eventKey !== $this->eventKey
            || $event->actorType !== $this->actorType
            || $event->actorId !== $this->actorId
            || $event->causationEventId !== $this->causationEventId
            || $event->clientVisible !== $this->clientVisible
            || !hash_equals($event->payloadHash, $this->payloadHash)) {
            throw new HarnessIdempotencyConflictException(
                'Event key resolved to a different immutable event payload.'
            );
        }
    }
}

final readonly class GenerationEvent
{
    public function __construct(
        public int $id,
        public int $runId,
        public ?int $stepId,
        public ?int $attemptId,
        public int $eventNo,
        public string $eventType,
        public int $eventVersion,
        public GenerationEventSeverity $severity,
        public string $eventKey,
        public string $actorType,
        public ?string $actorId,
        public ?int $causationEventId,
        public bool $clientVisible,
        public string $payloadJson,
        public string $payloadHash,
        public string $createdAt
    ) {
    }

    /**
     * Adapts a verified Retention bundle Event row (payload as a decoded
     * array, run identity held at the bundle level) back into the immutable
     * live-row representation. fromRow then re-validates every immutable
     * field and the payload hash, so the archive bytes are never trusted
     * blindly.
     *
     * @param array<string,mixed> $event
     */
    public static function fromArchiveEvent(array $event, int $runId): self
    {
        if (array_key_exists('payload_json', $event)
            || array_key_exists('run_id', $event)
            || !array_key_exists('payload', $event)
            || !is_array($event['payload'])) {
            throw new InvalidArgumentException('Archive Event payload is malformed.');
        }
        $row = $event;
        unset($row['payload']);
        $row['run_id'] = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $row['payload_json'] = HarnessPersistenceValue::canonicalMysqlJson($event['payload']);
        return self::fromRow($row);
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'run_id', 'step_id', 'attempt_id', 'event_no', 'event_type',
            'event_version', 'severity', 'event_key', 'actor_type', 'actor_id',
            'causation_event_id', 'client_visible', 'payload_json', 'payload_hash',
            'created_at',
        ], 'generation_event');
        $severity = is_string($row['severity'])
            ? GenerationEventSeverity::tryFrom($row['severity'])
            : null;
        if (!$severity instanceof GenerationEventSeverity) {
            throw new InvalidArgumentException('generation_event.severity is unknown.');
        }
        $payload = HarnessPersistenceValue::decodeJsonArray($row['payload_json'], 'payload_json');
        HarnessSensitiveDataGuard::assertSafeStructure($payload, 'event.payload');
        $payloadJson = HarnessPersistenceValue::canonicalMysqlJson($payload);
        HarnessSensitiveDataGuard::assertSafeText($payloadJson, 'event.payload');
        $payloadHash = HarnessPersistenceValue::hash($row['payload_hash'], 'payload_hash');
        if (!hash_equals(hash('sha256', $payloadJson), $payloadHash)) {
            throw new HarnessPersistenceException('Event payload hash does not match canonical JSON.');
        }

        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            HarnessPersistenceValue::positiveInt($row['run_id'], 'run_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['step_id'], 'step_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['attempt_id'], 'attempt_id'),
            HarnessPersistenceValue::positiveInt($row['event_no'], 'event_no'),
            HarnessPersistenceValue::identifier($row['event_type'], 'event_type', 96),
            HarnessPersistenceValue::boundedInt($row['event_version'], 'event_version', 1, 1000),
            $severity,
            HarnessPersistenceValue::asciiString($row['event_key'], 'event_key', 1, 191),
            HarnessPersistenceValue::identifier($row['actor_type'], 'actor_type', 32),
            HarnessPersistenceValue::optionalString($row['actor_id'], 'actor_id', 191),
            HarnessPersistenceValue::optionalPositiveInt(
                $row['causation_event_id'],
                'causation_event_id'
            ),
            HarnessPersistenceValue::bool($row['client_visible'], 'client_visible'),
            $payloadJson,
            $payloadHash,
            HarnessPersistenceValue::optionalMysqlDate($row['created_at'], 'created_at')
                ?? throw new InvalidArgumentException('generation_event.created_at is required.')
        );
    }
}
