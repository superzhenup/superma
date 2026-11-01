<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/retention/RetentionEventSegments.php';

/** Validated reducer seed; raw persisted JSON never bypasses source verification. */
final readonly class EventProjectionCheckpoint
{
    public function __construct(public int $runId, public int $cursor, public array $state)
    {
    }
}

final class EventProjectionReducer
{
    public const VERSION = 1;

    public static function contractHash(): string
    {
        return hash('sha256', self::VERSION . ':' . hash_file('sha256', __FILE__) . ':'
            . hash_file('sha256', dirname(__DIR__) . '/persistence/EventContractRegistry.php') . ':'
            . hash_file('sha256', __DIR__ . '/WorkflowStateSemantics.php'));
    }

    public static function fold(array $events, ?array $seed = null): array
    {
        $result = $seed ?? ['state' => null, 'progress' => 0, 'fence_generation' => 0, 'artifact_revision' => 0];
        if (array_keys($result) !== ['state', 'progress', 'fence_generation', 'artifact_revision']
            || ($result['state'] !== null && GenerationRunState::tryFrom((string)$result['state']) === null)) {
            throw new HarnessPersistenceException('Event projection checkpoint state is malformed.');
        }
        HarnessPersistenceValue::boundedInt($result['progress'], 'checkpoint.progress', 0, 100);
        HarnessPersistenceValue::nonNegativeInt($result['fence_generation'], 'checkpoint.fence');
        HarnessPersistenceValue::nonNegativeInt($result['artifact_revision'], 'checkpoint.revision');
        foreach ($events as $event) {
            if (!$event instanceof GenerationEvent) {
                throw new HarnessPersistenceException('Projection Event is malformed.');
            }
            $payload = HarnessPersistenceValue::decodeJsonArray($event->payloadJson, 'projection.payload');
            $resolution = EventContractRegistry::resolve(EventResolutionScenario::REPLAY,
                $event->eventType, $event->eventVersion, $payload);
            if (!$resolution->projectsLifecycle()) {
                continue;
            }
            $fence = array_key_exists('fence_generation', $payload)
                ? HarnessPersistenceValue::nonNegativeInt($payload['fence_generation'], 'projection.fence')
                : $result['fence_generation'];
            $revision = array_key_exists('artifact_revision', $payload)
                ? HarnessPersistenceValue::nonNegativeInt($payload['artifact_revision'], 'projection.revision')
                : $result['artifact_revision'];
            if ($fence < $result['fence_generation']
                || ($fence === $result['fence_generation'] && $revision < $result['artifact_revision'])) {
                throw new HarnessPersistenceException('Lifecycle Event scope moved backwards.');
            }
            if ($fence !== $result['fence_generation'] || $revision !== $result['artifact_revision']) {
                $result['progress'] = 0;
                $result['fence_generation'] = $fence;
                $result['artifact_revision'] = $revision;
            }
            if (isset($payload['progress'])) {
                $result['progress'] = max($result['progress'],
                    HarnessPersistenceValue::boundedInt($payload['progress'], 'projection.progress', 0, 100));
            }
            $candidate = $payload['state'] ?? null;
            $next = is_string($candidate) ? GenerationRunState::tryFrom($candidate) : null;
            if (isset($payload['legacy_state']) && is_string($payload['legacy_state'])) {
                $next = WorkflowStateSemantics::runFromLegacy($payload['legacy_state']);
            }
            if ($next !== null) {
                $previous = $result['state'] === null ? null : GenerationRunState::from($result['state']);
                if ($previous !== null && $previous !== $next) {
                    GenerationStateMachine::assertRunTransition($previous, $next);
                }
                $result['state'] = $next->value;
            }
        }
        return $result;
    }
}

final class EventProjectionCheckpointStore
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function create(int $runId, bool $apply = false): array
    {
        return $this->db->transactional(function () use ($runId, $apply): array {
            $run = $this->db->fetchOne('SELECT * FROM generation_runs WHERE id=? FOR UPDATE', [$runId]);
            if ($run === null) {
                throw new HarnessPersistenceException('Projection checkpoint Run is missing.');
            }
            $cursor = 0;
            $state = null;
            if ((int)$run['event_stream_version'] === 1) {
                $cursor = (int)$run['next_event_no'] - 1;
                [$streamHash, $state] = $this->livePrefix($runId, $cursor, true);
            } else {
                $manifests = RetentionEventSegments::manifests($this->db, $runId);
                foreach ($manifests as $manifest) {
                    $events = RetentionEventSegments::events($manifest, $runId);
                    EventStreamIntegrityValidator::validateRange($runId, $cursor, (int)$manifest['last_event_no'] + 1, $events);
                    $state = EventProjectionReducer::fold($events, $state);
                    $cursor = (int)$manifest['last_event_no'];
                    unset($events);
                }
                $streamHash = RetentionEventSegments::fingerprint($manifests, $cursor);
            }
            $state ??= EventProjectionReducer::fold([]);
            $json = HarnessPersistenceValue::canonicalMysqlJson($state);
            if ($apply) {
                $this->db->execute('INSERT INTO generation_event_checkpoints
                    (run_id,projector_version,contract_hash,event_cursor,stream_version,stream_hash,state_json,state_hash,created_at)
                    VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE
                    projector_version=VALUES(projector_version),contract_hash=VALUES(contract_hash),event_cursor=VALUES(event_cursor),
                    stream_version=VALUES(stream_version),stream_hash=VALUES(stream_hash),state_json=VALUES(state_json),
                    state_hash=VALUES(state_hash),created_at=VALUES(created_at)',
                    [$runId, EventProjectionReducer::VERSION, EventProjectionReducer::contractHash(), $cursor,
                        (int)$run['event_stream_version'], $streamHash, $json, hash('sha256', $json)]);
            }
            return ['run_id' => $runId, 'event_cursor' => $cursor, 'stream_hash' => $streamHash, 'apply' => $apply];
        });
    }

    public function load(array $run): ?EventProjectionCheckpoint
    {
        $row = $this->db->fetchOne('SELECT * FROM generation_event_checkpoints WHERE run_id=?', [(int)$run['id']]);
        if ($row === null) {
            return null;
        }
        $cursor = HarnessPersistenceValue::nonNegativeInt($row['event_cursor'], 'checkpoint.cursor');
        if ((int)$row['projector_version'] !== EventProjectionReducer::VERSION
            || !hash_equals(EventProjectionReducer::contractHash(), (string)$row['contract_hash'])
            || (int)$row['stream_version'] > (int)$run['event_stream_version']
            || (((int)$row['stream_version'] === 1) !== ((int)$run['event_stream_version'] === 1))
            || $cursor >= (int)$run['next_event_no']) {
            throw new HarnessPersistenceException('Projection checkpoint contract or stream version is stale; rebuild it.');
        }
        $state = HarnessPersistenceValue::decodeJsonArray($row['state_json'], 'checkpoint.state');
        $json = HarnessPersistenceValue::canonicalMysqlJson($state);
        $streamHash = (int)$run['event_stream_version'] === 1
            ? $this->livePrefix((int)$run['id'], $cursor, false)[0]
            : RetentionEventSegments::fingerprint(RetentionEventSegments::manifests($this->db, (int)$run['id']), $cursor);
        if (!hash_equals(hash('sha256', $json), (string)$row['state_hash'])
            || !hash_equals((string)$row['stream_hash'], $streamHash)) {
            throw new HarnessPersistenceException('Projection checkpoint state or source segment integrity failed.');
        }
        // Canonical JSON sorts object keys; normalize before strict reducer validation.
        $normalized = ['state' => $state['state'] ?? null, 'progress' => $state['progress'] ?? null,
            'fence_generation' => $state['fence_generation'] ?? null, 'artifact_revision' => $state['artifact_revision'] ?? null];
        if (count($state) !== 4) {
            throw new HarnessPersistenceException('Projection checkpoint has unknown state fields.');
        }
        EventProjectionReducer::fold([], $normalized);
        return new EventProjectionCheckpoint((int)$run['id'], $cursor, $normalized);
    }

    /** Live compaction keeps audit rows and streams integrity validation with bounded memory. */
    private function livePrefix(int $runId, int $cursor, bool $fold): array
    {
        require_once dirname(__DIR__) . '/persistence/RunEventStore.php';
        $store = new RunEventStore($this->db);
        $digest = hash_init('sha256');
        hash_update($digest, 'live-event-prefix-v1:' . $runId . ':' . $cursor . "\n");
        $last = 0;
        $state = null;
        while ($last < $cursor) {
            $page = $store->readAfter($runId, $last, min(1000, $cursor - $last));
            if ($page === []) {
                throw new HarnessPersistenceException('Live checkpoint prefix is incomplete.');
            }
            foreach ($page as $event) {
                if ($event->runId !== $runId || $event->eventNo !== $last + 1 || $event->eventNo > $cursor) {
                    throw new HarnessPersistenceException('Live checkpoint prefix is unordered or has a gap.');
                }
                hash_update($digest, HarnessPersistenceValue::canonicalJson(RetentionEventSegments::row($event)) . "\n");
                $last = $event->eventNo;
            }
            if ($fold) {
                $state = EventProjectionReducer::fold($page, $state);
            }
        }
        return [hash_final($digest), $state];
    }
}
