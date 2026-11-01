<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';
require_once __DIR__ . '/GenerationEvent.php';
require_once __DIR__ . '/EventContractRegistry.php';
require_once __DIR__ . '/RunEventIdAllocator.php';

final class RunEventStore
{
    public const MAX_MATERIALIZED_HISTORY_EVENTS = 100000;
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function append(GenerationEventDraft $draft, ?ModelUsage $usage = null): GenerationEvent
    {
        EventContractRegistry::validate($draft);
        return $this->db->transactional(function () use ($draft, $usage): GenerationEvent {
            // The Run row is the per-Run sequencer. Holding this lock makes
            // event_key lookup and next_event_no advancement one short critical
            // section. The Retention ledger row joins the same lock so a
            // default purged Run cannot receive a new Event: Retention purges
            // physically delete live Events, so a post-purge append would
            // silently fork the archived stream and destroy the idempotency
            // authority of every reused Event key. A restored Run keeps its
            // ledger row for re-archive idempotency but holds live Events
            // again, so it stays appendable. Explicit v2 continuation validates
            // every immutable prefix segment before authorizing a live tail.
            $run = $this->db->fetchOne(
                'SELECT gr.id, gr.next_event_no, gr.event_stream_version, hra.id AS retention_archive_id,
                        (SELECT COUNT(*) FROM generation_events ge WHERE ge.run_id=gr.id) AS live_event_count
                 FROM generation_runs gr
                 LEFT JOIN harness_retention_archives hra ON hra.run_id=gr.id
                 WHERE gr.id=? FOR UPDATE',
                [$draft->runId]
            );
            if ($run === null) {
                throw new HarnessPersistenceException('Cannot append an event to a missing Run.');
            }
            $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'run_id');
            if ($runId !== $draft->runId) {
                throw new HarnessPersistenceException('Locked Run identity does not match the event.');
            }
            $segmented = (int)($run['event_stream_version'] ?? 1) > 1;
            if (!$segmented && ($run['retention_archive_id'] ?? null) !== null
                && (int)($run['live_event_count'] ?? 0) === 0) {
                throw new HarnessPersistenceException(
                    'Cannot append an Event to a Run that has been Retention-purged.'
                );
            }

            $archived = [];
            if ($segmented) {
                require_once dirname(__DIR__) . '/retention/RetentionEventSegments.php';
                $archivedMatch = null;
                $lastArchived = 0;
                foreach (RetentionEventSegments::manifests($this->db, $runId) as $manifest) {
                    // Bound memory to one segment; never concatenate an unbounded archive history.
                    foreach (RetentionEventSegments::events($manifest, $runId) as $event) {
                        $lastArchived = $event->eventNo;
                        if ($event->eventKey === $draft->eventKey) {
                            if ($archivedMatch !== null) {
                                throw new HarnessIdempotencyConflictException('Archive segments duplicate an Event key.');
                            }
                            $archivedMatch = $event;
                        }
                        if ($draft->causationEventId !== null && $event->id === $draft->causationEventId) {
                            if ($archived !== []) {
                                throw new HarnessIdempotencyConflictException('Archive segments duplicate a causal Event identity.');
                            }
                            $archived = [$event];
                        }
                    }
                }
                $tail = $this->db->fetchOne(
                    'SELECT COUNT(*) AS total, MIN(event_no) AS first_no, MAX(event_no) AS last_no
                     FROM generation_events WHERE run_id=?', [$runId]
                );
                $next = (int)$run['next_event_no'];
                if ($tail === null || (int)$tail['total'] !== $next - $lastArchived - 1
                    || ((int)$tail['total'] > 0 && ((int)$tail['first_no'] !== $lastArchived + 1
                        || (int)$tail['last_no'] !== $next - 1))) {
                    throw new HarnessPersistenceException('Segmented Event append requires an exact contiguous live tail.');
                }
                if ($archivedMatch !== null) {
                    if ($this->db->fetchOne('SELECT id FROM generation_events WHERE run_id=? AND event_key=?',
                        [$runId, $draft->eventKey]) !== null) {
                        throw new HarnessIdempotencyConflictException('Live and archived Events duplicate an immutable key.');
                    }
                    $draft->assertIdempotentRow(RetentionEventSegments::row($archivedMatch));
                    return $archivedMatch;
                }
            }
            $this->assertOwnedReferences($draft, $archived, (int)$run['next_event_no']);

            $existing = $this->db->fetchOne(
                'SELECT * FROM generation_events WHERE run_id=? AND event_key=?',
                [$draft->runId, $draft->eventKey]
            );
            if ($existing !== null) {
                $draft->assertIdempotentRow($existing);
                return GenerationEvent::fromRow($existing);
            }

            if ($usage !== null) {
                $cost = '0'; // Monetary accounting was removed; preserve the legacy column.
                $updated = $this->db->execute(
                    'UPDATE generation_runs SET input_tokens=input_tokens+?, output_tokens=output_tokens+?, cost_total=cost_total+? WHERE id=?',
                    [$usage->inputTokens, $usage->outputTokens, $cost, $draft->runId]
                );
                if ($updated !== 1 && ($usage->inputTokens + $usage->outputTokens) > 0) {
                    throw new HarnessPersistenceException('Run usage update lost its locked row.');
                }
            }

            $eventNo = HarnessPersistenceValue::positiveInt(
                $run['next_event_no'] ?? null,
                'next_event_no'
            );
            if ($eventNo === PHP_INT_MAX) {
                throw new HarnessPersistenceException('Run event sequence is exhausted.');
            }
            $nextEventNo = $eventNo + 1;
            $advanced = $this->db->execute(
                'UPDATE generation_runs SET next_event_no=? WHERE id=? AND next_event_no=?',
                [$nextEventNo, $draft->runId, $eventNo]
            );
            if ($advanced !== 1) {
                throw new HarnessPersistenceException('Run event sequencer lost its locked CAS.');
            }

            $eventId = (new RunEventIdAllocator($this->db))->allocate();
            $inserted = $this->db->execute(
                "INSERT INTO generation_events
                    (id, run_id, step_id, attempt_id, event_no, event_type,
                     event_version, severity, event_key, actor_type, actor_id,
                     causation_event_id, client_visible, payload_json,
                     payload_hash, created_at)
                 VALUES ({$eventId}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))",
                [
                    $draft->runId,
                    $draft->stepId,
                    $draft->attemptId,
                    $eventNo,
                    $draft->eventType,
                    $draft->eventVersion,
                    $draft->severity->value,
                    $draft->eventKey,
                    $draft->actorType,
                    $draft->actorId,
                    $draft->causationEventId,
                    $draft->clientVisible ? 1 : 0,
                    $draft->payloadJson,
                    $draft->payloadHash,
                ]
            );
            if ($inserted !== 1) {
                throw new HarnessPersistenceException('Run event insert did not affect exactly one row.');
            }

            $row = $this->db->fetchOne(
                'SELECT * FROM generation_events WHERE run_id=? AND event_key=?',
                [$draft->runId, $draft->eventKey]
            );
            if ($row === null) {
                throw new HarnessPersistenceException('Inserted Run event disappeared.');
            }
            $draft->assertIdempotentRow($row);
            $event = GenerationEvent::fromRow($row);
            if ($event->eventNo !== $eventNo || $event->id !== $eventId) {
                throw new HarnessPersistenceException('Inserted Run event received the wrong sequence number or durable global ID.');
            }
            return $event;
        });
    }

    public function modelUsage(int $runId): ModelUsage
    {
        $row = $this->db->fetchOne(
            'SELECT input_tokens, output_tokens, cost_total FROM generation_runs WHERE id=?',
            [$runId]
        );
        if ($row === null) {
            throw new HarnessPersistenceException('Run usage is unavailable.');
        }
        return new ModelUsage(
            (int)$row['input_tokens'], (int)$row['output_tokens'],
            (int)$row['input_tokens'] + (int)$row['output_tokens'],
            0
        );
    }

    /** Full immutable history for operation resolvers; includes every retained segment. */
    public function readIncludingArchives(int $runId): array
    {
        $events = [];
        foreach ($this->iterateIncludingArchives($runId) as $event) {
            if (count($events) >= self::MAX_MATERIALIZED_HISTORY_EVENTS) {
                throw new HarnessPersistenceException('Materialized Event history exceeds 100000 rows; use the streaming iterator.');
            }
            $events[] = $event;
        }
        return $events;
    }

    /** Caller must hold the Run lock when using history for an authoritative mutation. */
    public function iterateIncludingArchives(int $runId): iterable
    {
        require_once dirname(__DIR__) . '/retention/RetentionEventRoutingReader.php';
        $run = $this->db->fetchOne('SELECT next_event_no FROM generation_runs WHERE id=?', [$runId]);
        if ($run === null) {
            throw new HarnessPersistenceException('Operation history Run is missing.');
        }
        $next = HarnessPersistenceValue::positiveInt($run['next_event_no'], 'operation_history.next_event_no');
        $reader = new RetentionEventRoutingReader($this->db);
        $cursor = 0;
        do {
            $page = $reader->readAfter($runId, $cursor, 1000);
            foreach ($page as $event) {
                if ($event->eventNo !== $cursor + 1) {
                    throw new HarnessPersistenceException('Operation history has a missing or duplicate Event.');
                }
                $cursor = $event->eventNo;
                yield $event;
            }
        } while (count($page) === 1000);
        if ($cursor !== $next - 1) {
            throw new HarnessPersistenceException('Operation history is incomplete or changed during iteration.');
        }
    }

    public function findIncludingArchives(int $runId, string $eventKey): ?GenerationEvent
    {
        $found = null;
        foreach ($this->iterateIncludingArchives($runId) as $event) {
            if ($event->eventKey === $eventKey) {
                if ($found !== null) {
                    throw new HarnessIdempotencyConflictException('Multiple stream segments reuse an immutable Event key.');
                }
                $found = $event;
            }
        }
        return $found;
    }

    /** @return list<GenerationEvent> */
    public function readAfter(int $runId, int $eventNo, int $limit = 100): array
    {
        $runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $eventNo = HarnessPersistenceValue::nonNegativeInt($eventNo, 'event_no');
        $limit = HarnessPersistenceValue::boundedInt($limit, 'limit', 1, 1000);
        $rows = $this->db->fetchAll(
            "SELECT * FROM generation_events
             WHERE run_id=? AND event_no>?
             ORDER BY event_no
             LIMIT {$limit}",
            [$runId, $eventNo]
        );
        return array_map(
            static fn (array $row): GenerationEvent => GenerationEvent::fromRow($row),
            $rows
        );
    }

    private function assertOwnedReferences(GenerationEventDraft $draft, array $archived = [], ?int $nextEventNo = null): void
    {
        if ($draft->stepId !== null) {
            $step = $this->db->fetchOne(
                'SELECT id, run_id FROM generation_steps WHERE id=? FOR UPDATE',
                [$draft->stepId]
            );
            if ($step === null
                || HarnessPersistenceValue::positiveInt($step['id'] ?? null, 'step_id')
                    !== $draft->stepId
                || HarnessPersistenceValue::positiveInt($step['run_id'] ?? null, 'step.run_id')
                    !== $draft->runId) {
                throw new HarnessPersistenceException(
                    'Event Step must exist and belong to the same Run.'
                );
            }
        }

        if ($draft->attemptId !== null) {
            // An Attempt is always scoped through its Step. Requiring both
            // coordinates prevents a syntactically valid but ambiguous event.
            if ($draft->stepId === null) {
                throw new HarnessPersistenceException(
                    'An event with an Attempt must also identify that Attempt\'s Step.'
                );
            }
            $attempt = $this->db->fetchOne(
                "SELECT a.id, a.step_id, s.run_id
                 FROM generation_attempts a
                 INNER JOIN generation_steps s ON s.id=a.step_id
                 WHERE a.id=? FOR UPDATE",
                [$draft->attemptId]
            );
            if ($attempt === null
                || HarnessPersistenceValue::positiveInt($attempt['id'] ?? null, 'attempt_id')
                    !== $draft->attemptId
                || HarnessPersistenceValue::positiveInt(
                    $attempt['step_id'] ?? null,
                    'attempt.step_id'
                ) !== $draft->stepId
                || HarnessPersistenceValue::positiveInt(
                    $attempt['run_id'] ?? null,
                    'attempt.run_id'
                ) !== $draft->runId) {
                throw new HarnessPersistenceException(
                    'Event Attempt must belong to the identified Step and Run.'
                );
            }
        }

        if ($draft->causationEventId !== null) {
            $causation = $this->db->fetchOne(
                'SELECT id, run_id, event_no FROM generation_events WHERE id=? FOR UPDATE',
                [$draft->causationEventId]
            );
            if ($causation === null) {
                foreach ($archived as $event) {
                    if ($event->id === $draft->causationEventId) {
                        $causation = ['id' => $event->id, 'run_id' => $event->runId, 'event_no' => $event->eventNo];
                        break;
                    }
                }
            }
            if ($causation === null
                || HarnessPersistenceValue::positiveInt(
                    $causation['id'] ?? null,
                    'causation_event_id'
                ) !== $draft->causationEventId
                || HarnessPersistenceValue::positiveInt(
                    $causation['run_id'] ?? null,
                    'causation.run_id'
                    ) !== $draft->runId
                || (isset($causation['event_no']) && (int)$causation['event_no'] >= $nextEventNo)) {
                throw new HarnessPersistenceException(
                    'Event causation must reference an earlier event from the same Run.'
                );
            }
        }
    }
}
