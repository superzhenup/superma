<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/LegacyWritingTaskRunIdentity.php';
require_once dirname(__DIR__) . '/persistence/bootstrap.php';
require_once dirname(__DIR__) . '/retention/RetentionEventRoutingReader.php';

final readonly class LegacyWritingTaskEventPage
{
    /** @param list<array<string,mixed>> $events */
    public function __construct(
        public string $status,
        public int $nextCursor,
        public array $events,
        public ?string $runUid
    ) {
    }

    /** @return array{status:string,next_cursor:int,events:list<array<string,mixed>>,run_uid:?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'next_cursor' => $this->nextCursor,
            'events' => $this->events,
            'run_uid' => $this->runUid,
        ];
    }
}

/**
 * Optional event cursor for legacy write_poll clients.
 *
 * The legacy task remains the authority during P1. A missing shadow Run is
 * reported as unavailable instead of being presented as an empty successful
 * trace. Private events still advance the cursor but are never returned.
 */
final class LegacyWritingTaskEventCursor
{
    /** @var Closure(int,string):?array<string,mixed> */
    private Closure $runLookup;

    /** @var Closure(int,int,int):array<int,GenerationEvent> */
    private Closure $eventReader;

    public function __construct(?callable $runLookup = null, ?callable $eventReader = null)
    {
        $this->runLookup = Closure::fromCallable($runLookup ?? static function (
            int $userId,
            string $idempotencyKey
        ): ?array {
            if (!class_exists('DB')) {
                throw new HarnessPersistenceException('DB is unavailable for the Harness event cursor.');
            }
            return DB::fetch(
                "SELECT id, run_uid, user_id, novel_id, chapter_id, queue,
                        run_type, workflow_name, workflow_version, authority_mode
                 FROM generation_runs
                 WHERE user_id=? AND idempotency_key=? LIMIT 1",
                [$userId, $idempotencyKey]
            ) ?: null;
        });

        $this->eventReader = Closure::fromCallable($eventReader ?? static function (
            int $runId,
            int $cursor,
            int $limit
        ): array {
            if (!class_exists('DB')) {
                throw new HarnessPersistenceException('DB is unavailable for the Harness event cursor.');
            }
            // Routes between the live segment and a verified Retention
            // archive so an archived shadow Run pages its real history.
            return (new RetentionEventRoutingReader(DB::connect()))
                ->readAfter($runId, $cursor, $limit);
        });
    }

    /** @param array<string,mixed> $task */
    public function read(array $task, int $cursor, int $limit = 50): LegacyWritingTaskEventPage
    {
        $taskId = $task['task_id'] ?? null;
        if (!is_string($taskId)) {
            throw new InvalidArgumentException('Writing task row has no task_id.');
        }
        LegacyWritingTaskRunIdentity::assertTaskId($taskId);
        $userId = HarnessPersistenceValue::positiveInt($task['user_id'] ?? null, 'task.user_id');
        $novelId = HarnessPersistenceValue::positiveInt($task['novel_id'] ?? null, 'task.novel_id');
        $chapterId = HarnessPersistenceValue::optionalPositiveInt(
            $task['chapter_id'] ?? null,
            'task.chapter_id'
        );
        $cursor = HarnessPersistenceValue::nonNegativeInt($cursor, 'event_cursor');
        $limit = HarnessPersistenceValue::boundedInt($limit, 'event_limit', 1, 100);

        $run = ($this->runLookup)(
            $userId,
            LegacyWritingTaskRunIdentity::idempotencyKey($taskId)
        );
        if ($run === null) {
            return new LegacyWritingTaskEventPage('unavailable', $cursor, [], null);
        }
        $this->assertExactShadowRun($run, $taskId, $userId, $novelId, $chapterId);

        $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'run.id');
        $runUid = HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'run.run_uid');
        $records = ($this->eventReader)($runId, $cursor, $limit);
        $nextCursor = $cursor;
        $events = [];
        foreach ($records as $event) {
            if (!$event instanceof GenerationEvent
                || $event->runId !== $runId
                || $event->eventNo <= $nextCursor) {
                throw new HarnessPersistenceException(
                    'Harness event cursor reader returned an invalid or unordered event.'
                );
            }
            $nextCursor = $event->eventNo;
            if (!$event->clientVisible) {
                continue;
            }
            $events[] = [
                'event_no' => $event->eventNo,
                'type' => $event->eventType,
                'version' => $event->eventVersion,
                'severity' => $event->severity->value,
                'payload' => HarnessPersistenceValue::decodeJsonArray(
                    $event->payloadJson,
                    'event.payload_json'
                ),
                'created_at' => $event->createdAt,
            ];
        }

        return new LegacyWritingTaskEventPage('available', $nextCursor, $events, $runUid);
    }

    /** @param array<string,mixed> $run */
    private function assertExactShadowRun(
        array $run,
        string $taskId,
        int $userId,
        int $novelId,
        ?int $chapterId
    ): void {
        $matches = HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'run.run_uid')
                === LegacyWritingTaskRunIdentity::runUid($taskId)
            && HarnessPersistenceValue::positiveInt($run['user_id'] ?? null, 'run.user_id') === $userId
            && HarnessPersistenceValue::positiveInt($run['novel_id'] ?? null, 'run.novel_id') === $novelId
            && HarnessPersistenceValue::optionalPositiveInt(
                $run['chapter_id'] ?? null,
                'run.chapter_id'
            ) === $chapterId
            && ($run['queue'] ?? null) === LegacyWritingTaskRunIdentity::QUEUE
            && ($run['run_type'] ?? null) === LegacyWritingTaskRunIdentity::RUN_TYPE
            && ($run['workflow_name'] ?? null) === LegacyWritingTaskRunIdentity::WORKFLOW_NAME
            && ($run['workflow_version'] ?? null) === LegacyWritingTaskRunIdentity::WORKFLOW_VERSION
            && ($run['authority_mode'] ?? null) === LegacyWritingTaskRunIdentity::AUTHORITY_MODE;
        if (!$matches) {
            throw new HarnessPersistenceException(
                'Writing task shadow Run identity or ownership does not match.'
            );
        }
    }
}
