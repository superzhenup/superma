<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionArchiveReader.php';
require_once __DIR__ . '/FilesystemRetentionArchiveSink.php';
require_once dirname(__DIR__) . '/persistence/RunEventStore.php';
require_once __DIR__ . '/RetentionEventSegments.php';

/** Segment metadata travels with the page that was actually read. */
final readonly class RetentionEventPage
{
    /** @param list<GenerationEvent> $events */
    public function __construct(public array $events, public bool $archived, public ?string $streamToken = null)
    {
    }
}

/**
 * Segment-routing Event reader for one Run.
 *
 * A Retention purge physically deletes the Run's ENTIRE live Event stream and
 * a restore re-materializes it entirely, so the live table and the archive
 * remain mutually exclusive in default v1 mode:
 *
 *   - live non-empty  -> the live table IS the complete stream (a restored
 *     Run keeps its ledger row for re-archive idempotency but holds all
 *     Events, including any appended after the restore);
 *   - live empty      -> only the archive can supply the stream, and it must
 *     cover exactly 1..next_event_no-1 or the state is unrecoverable loss
 *     that fails closed instead of silently surfacing as an empty stream.
 * Explicit v2 mode joins the verified frozen prefix segments and contiguous
 * live tail. The page token detects manifest changes during pagination.
 */
final class RetentionEventRoutingReader
{
    private HarnessPersistenceDriver $db;
    private ?string $verifiedStreamKey = null;
    /** @var list<GenerationEvent> */
    private array $verifiedStream = [];

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    /** @return list<GenerationEvent> */
    public function readAfter(int $runId, int $cursor, int $limit): array
    {
        return $this->readPageAfter($runId, $cursor, $limit)->events;
    }

    public function readPageAfter(int $runId, int $cursor, int $limit): RetentionEventPage
    {
        $runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $cursor = HarnessPersistenceValue::nonNegativeInt($cursor, 'event_no');
        $limit = HarnessPersistenceValue::boundedInt($limit, 'limit', 1, 1000);

        $archive = $this->db->fetchOne(
            'SELECT hra.archive_uid, hra.storage_uri, hra.first_event_no, hra.last_event_no, hra.event_count,
                    gr.event_stream_version
             FROM harness_retention_archives hra JOIN generation_runs gr ON gr.id=hra.run_id WHERE hra.run_id=?',
            [$runId]
        );
        $live = new RunEventStore($this->db);
        if ($archive === null) {
            return new RetentionEventPage($live->readAfter($runId, $cursor, $limit), false);
        }
        if ((int)($archive['event_stream_version'] ?? 1) > 1) {
            return $this->readSegmented($runId, $cursor, $limit, (int)$archive['event_stream_version']);
        }
        if ($live->readAfter($runId, 0, 1) !== []) {
            return new RetentionEventPage($live->readAfter($runId, $cursor, $limit), false);
        }
        $run = $this->db->fetchOne(
            'SELECT next_event_no FROM generation_runs WHERE id=?',
            [$runId]
        );
        if ($run === null) {
            return new RetentionEventPage([], false);
        }
        $nextEventNo = HarnessPersistenceValue::positiveInt(
            $run['next_event_no'] ?? null,
            'next_event_no'
        );
        if ($nextEventNo === 1) {
            // The Run never emitted an Event; an empty stream is legitimate.
            return new RetentionEventPage([], true);
        }

        // Verified archive event numbers are exactly 1..next_event_no-1.
        $page = array_slice($this->readPurgedStream($archive, $runId, $nextEventNo), $cursor, $limit);
        return new RetentionEventPage($page, true);
    }

    private function readSegmented(int $runId, int $cursor, int $limit, int $version): RetentionEventPage
    {
        $manifests = RetentionEventSegments::manifests($this->db, $runId);
        $key = hash('sha256', HarnessPersistenceValue::canonicalJson([$runId, $version, $manifests]));
        $needed = array_values(array_filter($manifests, static fn(array $row): bool =>
            (int)$row['last_event_no'] > $cursor && (int)$row['first_event_no'] <= $cursor + $limit));
        $cacheKey = $key . ':' . ($needed[0]['first_event_no'] ?? 'tail') . ':'
            . ($needed === [] ? 'tail' : $needed[array_key_last($needed)]['last_event_no']);
        if ($this->verifiedStreamKey !== $cacheKey) {
            $events = [];
            foreach ($needed as $manifest) {
                array_push($events, ...RetentionEventSegments::events($manifest, $runId));
            }
            $this->verifiedStream = $events;
            $this->verifiedStreamKey = $cacheKey;
        }
        $last = $manifests === [] ? 0 : (int)$manifests[array_key_last($manifests)]['last_event_no'];
        $page = array_slice(array_values(array_filter($this->verifiedStream,
            static fn(GenerationEvent $event): bool => $event->eventNo > $cursor)), 0, $limit);
        if (count($page) < $limit) {
            $tail = (new RunEventStore($this->db))->readAfter($runId, max($cursor, $last), $limit - count($page));
            array_push($page, ...$tail);
        }
        $expected = $cursor + 1;
        foreach ($page as $event) {
            if ($event->eventNo !== $expected++) {
                throw new HarnessPersistenceException('Segmented Event cursor encountered a gap or overlap.');
            }
        }
        return new RetentionEventPage($page, true, $key);
    }

    /**
     * @param array<string,mixed> $archive
     * @return list<GenerationEvent>
     */
    private function readPurgedStream(
        array $archive,
        int $runId,
        int $nextEventNo
    ): array {
        $firstEventNo = HarnessPersistenceValue::optionalPositiveInt(
            $archive['first_event_no'] ?? null,
            'retention_archive.first_event_no'
        );
        $lastEventNo = HarnessPersistenceValue::optionalPositiveInt(
            $archive['last_event_no'] ?? null,
            'retention_archive.last_event_no'
        );
        $eventCount = HarnessPersistenceValue::nonNegativeInt(
            $archive['event_count'] ?? null,
            'retention_archive.event_count'
        );
        if ($firstEventNo !== 1
            || $lastEventNo !== $nextEventNo - 1
            || $eventCount !== $nextEventNo - 1) {
            throw new HarnessPersistenceException(
                'Retention archive does not cover the purged Run Event stream.'
            );
        }
        // A Poll reader owns this router only for one readTask call. Recheck
        // routing and ledger coverage on every page, but verify immutable
        // archive bytes only once so a 100k stream is not parsed 101 times.
        $streamKey = hash('sha256', HarnessPersistenceValue::canonicalJson([
            'run_id' => $runId, 'next_event_no' => $nextEventNo, 'archive' => $archive,
        ]));
        if ($this->verifiedStreamKey === $streamKey) {
            return $this->verifiedStream;
        }
        $storageUri = HarnessPersistenceValue::string(
            $archive['storage_uri'] ?? null,
            'retention_archive.storage_uri',
            1,
            1000
        );
        HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
        $path = FilesystemRetentionArchiveSink::fileUriPath($storageUri);
        if ($path === '') {
            throw new HarnessPersistenceException(
                'Retention archive storage URI is not a local file.'
            );
        }
        try {
            $verified = (new RetentionArchiveReader())->readFile($path);
        } catch (Throwable $exception) {
            throw new HarnessPersistenceException(
                'Retention archive could not be verified for Event read-through.',
                0,
                $exception
            );
        }
        if ($verified->runId !== $runId
            || !hash_equals(
                (string)($archive['archive_uid'] ?? ''),
                $verified->archiveUid
            )) {
            throw new HarnessPersistenceException(
                'Retention archive identity does not match its ledger row.'
            );
        }
        $events = [];
        foreach ($verified->events() as $row) {
            $events[] = GenerationEvent::fromArchiveEvent($row, $verified->runId);
        }
        $this->verifiedStreamKey = $streamKey;
        return $this->verifiedStream = $events;
    }
}
