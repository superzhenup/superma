<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionArchiveReader.php';
require_once __DIR__ . '/FilesystemRetentionArchiveSink.php';
require_once dirname(__DIR__) . '/persistence/GenerationEvent.php';

/** Immutable prefix manifests. Files are verified even when a checkpoint skips JSON replay. */
final class RetentionEventSegments
{
    public const FORMAT = 'harness.events.segment.v1';
    public const MAX_BYTES = 268435456;

    public static function manifests(HarnessPersistenceDriver $db, int $runId): array
    {
        $base = $db->fetchOne('SELECT * FROM harness_retention_archives WHERE run_id=?', [$runId]);
        if ($base === null) {
            throw new HarnessPersistenceException('Segmented Run requires its original Retention archive.');
        }
        $result = [];
        $count = (int)$base['event_count'];
        if ($count > 0) {
            $result[] = array_merge($base, ['segment_no' => 0, 'format_version' => 0]);
        } else {
            $emptyManifest = array_merge($base, ['segment_no' => 0, 'format_version' => 0]);
            $verified = (new RetentionArchiveReader())->verifyBytes(self::bytes($emptyManifest), (string)$base['storage_uri']);
            if ($count !== 0 || $verified->runId !== $runId || $verified->archiveUid !== $base['archive_uid']
                || $verified->eventCount !== 0 || $base['first_event_no'] !== null || $base['last_event_no'] !== null) {
                throw new HarnessPersistenceException('Empty base archive identity or Event census is inconsistent.');
            }
        }
        foreach ($db->fetchAll(
            'SELECT * FROM generation_event_segments WHERE run_id=? ORDER BY segment_no', [$runId]
        ) as $row) {
            $result[] = $row;
        }
        $last = 0;
        $number = 0;
        foreach ($result as $row) {
            $first = HarnessPersistenceValue::positiveInt($row['first_event_no'], 'segment.first');
            $end = HarnessPersistenceValue::positiveInt($row['last_event_no'], 'segment.last');
            $version = (int)$row['format_version'];
            if (($version !== 0 && $version !== 1)
                || ($version === 1 && (int)$row['segment_no'] !== ++$number)
                || $first !== $last + 1 || $end < $first
                || (int)$row['event_count'] !== $end - $first + 1) {
                throw new HarnessPersistenceException('Event segment manifest has a gap, overlap or unknown format.');
            }
            $last = $end;
        }
        return $result;
    }

    public static function bytes(array $manifest): string
    {
        $uri = HarnessPersistenceValue::string($manifest['storage_uri'] ?? null, 'segment.uri', 1, 1000);
        HarnessSensitiveDataGuard::assertSafeStorageUri($uri);
        $path = FilesystemRetentionArchiveSink::fileUriPath($uri);
        $size = HarnessPersistenceValue::positiveInt($manifest['size_bytes'] ?? null, 'segment.size');
        $hash = HarnessPersistenceValue::hash($manifest['content_hash'] ?? null, 'segment.hash');
        if ($path === '' || !is_file($path) || is_link($path) || $size > self::MAX_BYTES
            || filesize($path) !== $size) {
            throw new HarnessPersistenceException('Event segment file is unavailable or oversized.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size || !hash_equals($hash, hash('sha256', $bytes))) {
            throw new HarnessPersistenceException('Event segment content hash does not match its manifest.');
        }
        return $bytes;
    }

    public static function events(array $manifest, int $runId): array
    {
        $bytes = self::bytes($manifest);
        if ((int)$manifest['format_version'] === 0) {
            $archive = (new RetentionArchiveReader())->verifyBytes($bytes, (string)$manifest['storage_uri']);
            if ($archive->runId !== $runId || $archive->archiveUid !== $manifest['archive_uid']) {
                throw new HarnessPersistenceException('Base archive identity mismatch.');
            }
            $events = array_map(static fn(array $row): GenerationEvent =>
                GenerationEvent::fromArchiveEvent($row, $runId), $archive->events());
        } else {
            $bundle = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($bundle) || ($bundle['schema'] ?? null) !== self::FORMAT
                || ($bundle['run_id'] ?? null) !== $runId
                || ($bundle['segment_no'] ?? null) !== (int)$manifest['segment_no']
                || !is_array($bundle['events'] ?? null)
                || count($bundle['events']) > RetentionArchiveReader::MAX_ARCHIVE_ROWS) {
                throw new HarnessPersistenceException('Event segment envelope is malformed.');
            }
            $events = array_map(static fn(array $row): GenerationEvent => GenerationEvent::fromRow($row), $bundle['events']);
        }
        EventStreamIntegrityValidator::validateRange($runId, (int)$manifest['first_event_no'] - 1,
            (int)$manifest['last_event_no'] + 1, $events);
        return $events;
    }

    /** Hashes verified immutable bytes and exact range metadata, never mutable Run progress. */
    public static function fingerprint(array $manifests, int $cursor): string
    {
        $proof = [];
        $last = 0;
        foreach ($manifests as $row) {
            if ((int)$row['last_event_no'] > $cursor) {
                break;
            }
            self::bytes($row);
            $proof[] = [(int)$row['segment_no'], (int)$row['first_event_no'],
                (int)$row['last_event_no'], (int)$row['event_count'], (int)$row['format_version'],
                (string)$row['content_hash'], (int)$row['size_bytes']];
            $last = (int)$row['last_event_no'];
        }
        if ($last !== $cursor) {
            throw new HarnessPersistenceException('Checkpoint cursor is not an immutable segment boundary.');
        }
        return hash('sha256', HarnessPersistenceValue::canonicalJson($proof));
    }

    public static function row(GenerationEvent $event): array
    {
        return ['id' => $event->id, 'run_id' => $event->runId, 'step_id' => $event->stepId,
            'attempt_id' => $event->attemptId, 'event_no' => $event->eventNo,
            'event_type' => $event->eventType, 'event_version' => $event->eventVersion,
            'severity' => $event->severity->value, 'event_key' => $event->eventKey,
            'actor_type' => $event->actorType, 'actor_id' => $event->actorId,
            'causation_event_id' => $event->causationEventId, 'client_visible' => $event->clientVisible ? 1 : 0,
            'payload_json' => $event->payloadJson, 'payload_hash' => $event->payloadHash,
            'created_at' => $event->createdAt];
    }
}
