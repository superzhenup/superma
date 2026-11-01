<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';

/** Durable global identity: MySQL 5.7 AUTO_INCREMENT may rewind after a purge and restart. */
final class RunEventIdAllocator
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    /** Caller already holds its Run lock and transaction; allocator is always locked second. */
    public function allocate(): int
    {
        $next = $this->lockedNext();
        $live = $this->db->fetchOne('SELECT COALESCE(MAX(id),0) AS maximum_id FROM generation_events');
        if ($live === null) {
            throw new HarnessPersistenceException('Global Event identity census is unavailable.');
        }
        $maximum = HarnessPersistenceValue::nonNegativeInt($live['maximum_id'] ?? null, 'event_allocator.maximum_id');
        $id = max($next, $this->successor($maximum));
        $this->advance($next, $this->successor($id));
        return $id;
    }

    /** Freeze a high-water mark before purge, including legacy/imported explicit Event IDs. */
    public function observe(int $maximumId): void
    {
        HarnessPersistenceValue::nonNegativeInt($maximumId, 'event_allocator.observed_id');
        $next = $this->lockedNext();
        $required = $this->successor($maximumId);
        if ($required > $next) {
            $this->advance($next, $required);
        }
    }

    private function lockedNext(): int
    {
        $row = $this->db->fetchOne('SELECT next_id FROM generation_event_id_allocator WHERE allocator_id=1 FOR UPDATE');
        if ($row === null) {
            throw new HarnessPersistenceException('Global Event identity allocator is uninitialized; apply M70 migration.');
        }
        return HarnessPersistenceValue::positiveInt($row['next_id'] ?? null, 'event_allocator.next_id');
    }

    private function advance(int $old, int $next): void
    {
        if ($this->db->execute('UPDATE generation_event_id_allocator SET next_id=?,updated_at=UTC_TIMESTAMP(6) WHERE allocator_id=1 AND next_id=?',
            [$next, $old]) !== 1) {
            throw new HarnessPersistenceException('Global Event identity allocator lost its locked CAS.');
        }
    }

    private function successor(int $id): int
    {
        if ($id >= PHP_INT_MAX) {
            throw new HarnessPersistenceException('Global Event identity sequence is exhausted.');
        }
        return $id + 1;
    }

    /**
     * Migration-only initialization under writer drain. Every archive must be available:
     * neither MAX(live.id) nor the volatile AUTO_INCREMENT counter proves historical IDs.
     * One segment is decoded at a time; a connection-local temporary index detects reused
     * archive IDs without retaining every payload in PHP memory.
     */
    public static function initialize(PDO $pdo): void
    {
        require_once dirname(__DIR__) . '/retention/RetentionEventSegments.php';
        $driver = new PdoHarnessPersistenceDriver($pdo);
        $temporary = 'tmp_event_id_initialization';
        $pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS {$temporary}
            (event_id BIGINT UNSIGNED PRIMARY KEY, identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL) ENGINE=InnoDB");
        $pdo->exec("DELETE FROM {$temporary}");
        try {
            $driver->transactional(function () use ($pdo, $driver, $temporary): void {
                $current = $driver->fetchOne('SELECT next_id FROM generation_event_id_allocator WHERE allocator_id=1 FOR UPDATE');
                $maximum = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM generation_events')->fetchColumn();
                $auto = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generation_events'")->fetchColumn();
                if ($auto !== false && $auto !== null) {
                    $maximum = max($maximum, HarnessPersistenceValue::positiveInt($auto, 'event_allocator.auto_increment') - 1);
                }
                $record = $pdo->prepare("INSERT IGNORE INTO {$temporary}(event_id,identity_hash) VALUES (?,?)");
                $lookup = $pdo->prepare("SELECT identity_hash FROM {$temporary} WHERE event_id=?");
                $live = $pdo->prepare('SELECT * FROM generation_events WHERE id=?');
                $observe = static function (GenerationEvent $event) use (&$maximum, $record, $lookup, $live): void {
                    $identity = hash('sha256', HarnessPersistenceValue::canonicalJson(RetentionEventSegments::row($event)));
                    $record->execute([$event->id, $identity]);
                    if ($record->rowCount() === 0) {
                        $lookup->execute([$event->id]);
                        if (!hash_equals($identity, (string)$lookup->fetchColumn())) {
                            throw new HarnessIdempotencyConflictException('Archived Event ID was reused by a different immutable Event; migration is blocked.');
                        }
                    }
                    $live->execute([$event->id]);
                    $row = $live->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row) && !hash_equals($identity, hash('sha256', HarnessPersistenceValue::canonicalJson(
                        RetentionEventSegments::row(GenerationEvent::fromRow($row)))))) {
                        throw new HarnessIdempotencyConflictException('Live Event ID conflicts with archived history; reconcile identity reuse before migration.');
                    }
                    $maximum = max($maximum, $event->id);
                };
                $archives = $pdo->query('SELECT * FROM harness_retention_archives ORDER BY id');
                while (($base = $archives->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $manifest = array_merge($base, ['segment_no' => 0, 'format_version' => 0]);
                    if ((int)$base['event_count'] === 0) {
                        $verified = (new RetentionArchiveReader())->verifyBytes(RetentionEventSegments::bytes($manifest), (string)$base['storage_uri']);
                        if ($verified->eventCount !== 0 || $verified->runId !== (int)$base['run_id'] || $verified->archiveUid !== $base['archive_uid']) {
                            throw new HarnessPersistenceException('Empty Event archive metadata is inconsistent.');
                        }
                    } else {
                        foreach (RetentionEventSegments::events($manifest, (int)$base['run_id']) as $event) {
                            $observe($event);
                        }
                    }
                }
                $segments = $pdo->query('SELECT * FROM generation_event_segments ORDER BY run_id,segment_no');
                while (($segment = $segments->fetch(PDO::FETCH_ASSOC)) !== false) {
                    foreach (RetentionEventSegments::events($segment, (int)$segment['run_id']) as $event) {
                        $observe($event);
                    }
                }
                if ($maximum >= PHP_INT_MAX) {
                    throw new HarnessPersistenceException('Historical Event identity sequence is exhausted.');
                }
                $next = max($maximum + 1, $current === null ? 1 : HarnessPersistenceValue::positiveInt($current['next_id'], 'event_allocator.next_id'));
                $driver->execute('INSERT INTO generation_event_id_allocator(allocator_id,next_id,updated_at) VALUES (1,?,UTC_TIMESTAMP(6))
                    ON DUPLICATE KEY UPDATE next_id=GREATEST(next_id,VALUES(next_id)),updated_at=VALUES(updated_at)', [$next]);
            });
        } finally {
            $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$temporary}");
        }
    }
}
