<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/harness/retention/FilesystemRetentionArchiveSink.php';
require_once __DIR__ . '/WriterDrainCensus.php';

/** Separate external-archive backup; never writes the source DB or restores URIs. */
final class HarnessArchiveSnapshot
{
    private const FORMAT = 'harness.archive-snapshot.v1';
    private const MAX_FILES = 100000;
    private const MAX_FILE_BYTES = 268435456;
    private const MAX_MANIFEST_BYTES = 67108864;
    /**
     * Schemas whose archive ledgers this snapshot knows how to read. The M65 ledger exists in all of
     * them; the M70 segment ledger exists from Schema 70 on. Schema 71 only added book_analyses /
     * volume_outlines columns, so its archive structure equals 70. Schema 72 (审计修复 F06，
     * 2026-11-01) only added the postprocess_subtasks writer queue: the ledgers equal 70/71, and
     * the queue joins the drain census (WriterDrainCensus::SINCE_SCHEMA). A newer schema fails
     * closed until its ledgers are reviewed and listed here.
     */
    public const SUPPORTED_SCHEMAS = [69, 70, 71, 72];
    private const SEGMENT_LEDGER_SINCE = 70;
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection, private readonly string $webRoot)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
        if ($connection instanceof PDO && $connection->inTransaction()) {
            throw new InvalidArgumentException('Archive snapshot requires an independent connection so final reads see committed changes.');
        }
    }

    /** External maintenance/drain must remain in effect through the matching SQL backup. */
    public function create(string $outputDirectory): array
    {
        // Reuse the archive sink's absolute-path, Web-root and ancestor-link checks.
        new FilesystemRetentionArchiveSink($outputDirectory, $this->webRoot);
        $outputDirectory = (string)realpath($outputDirectory);
        $started = gmdate('c');
        $before = $this->captureState();
        $uid = bin2hex(random_bytes(16));
        $directory = $outputDirectory . DIRECTORY_SEPARATOR . 'harness-archive-snapshot-' . $uid;
        $oldMask = umask(0077);
        try {
            if (!mkdir($directory, 0700) || !mkdir($directory . '/archives', 0700)) {
                throw new RuntimeException('Could not exclusively create archive snapshot directory.');
            }
            $entries = [];
            foreach ($before['inventory'] as $row) {
                $source = self::sourcePath($row['storage_uri'], $this->webRoot);
                $relative = 'archives/' . $row['content_hash'] . '.json';
                $destination = $directory . '/' . $relative;
                if (file_exists($destination)) {
                    self::verifyFile($source, $row['content_hash'], $row['size_bytes']);
                    self::verifyFile($destination, $row['content_hash'], $row['size_bytes']);
                } else {
                    $this->copyVerified($source, $destination, $row['content_hash'], $row['size_bytes']);
                }
                $entries[] = $row + ['path' => $relative];
            }
            $after = $this->captureState();
            if ($before !== $after) {
                throw new RuntimeException('Database inventory or drain census changed during archive snapshot; keep maintenance active and create a new snapshot.');
            }
            new FilesystemRetentionArchiveSink($directory, $this->webRoot);
            $manifest = [
                'format' => self::FORMAT, 'snapshot_uid' => $uid,
                'started_at_utc' => $started, 'completed_at_utc' => gmdate('c'),
                'database' => $before['database'], 'drain_counts' => $before['drain_counts'],
                'requires_continuous_external_drain' => true,
                'inventory_hash' => hash('sha256', HarnessPersistenceValue::canonicalJson($before['inventory'])),
                'entries' => $entries,
            ];
            $bytes = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($bytes) > self::MAX_MANIFEST_BYTES) throw new RuntimeException('Archive snapshot manifest exceeds its size limit.');
            // No manifest is emitted for incomplete or drifting copies.
            self::writeExclusive($directory . '/manifest.json', $bytes);
            $hash = hash('sha256', $bytes);
            self::writeExclusive($directory . '/manifest.sha256', $hash . "\n");
            $verified = self::verify($directory, $this->webRoot, $hash);
            return $verified + ['path' => $directory, 'source_database_writes' => false];
        } finally {
            umask($oldMask);
        }
    }

    /** Offline verification of snapshot files only; never reads/writes original URIs. */
    public static function verify(string $directory, string $webRoot, ?string $expectedHash = null): array
    {
        new FilesystemRetentionArchiveSink($directory, $webRoot);
        $directory = (string)realpath($directory);
        $manifestPath = self::regularPath($directory . '/manifest.json');
        $checksumPath = self::regularPath($directory . '/manifest.sha256');
        if (filesize($manifestPath) > self::MAX_MANIFEST_BYTES || filesize($checksumPath) > 128) {
            throw new RuntimeException('Archive snapshot manifest/checksum exceeds its size limit.');
        }
        $bytes = file_get_contents($manifestPath);
        $storedHash = trim((string)file_get_contents($checksumPath));
        HarnessPersistenceValue::hash($storedHash, 'snapshot.manifest_hash');
        if ($expectedHash !== null) HarnessPersistenceValue::hash($expectedHash, 'snapshot.expected_manifest_hash');
        if (!is_string($bytes) || !hash_equals($storedHash, hash('sha256', $bytes))
            || ($expectedHash !== null && !hash_equals($expectedHash, $storedHash))) {
            throw new RuntimeException('Archive snapshot manifest hash mismatch.');
        }
        $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT
            || !is_array($manifest['entries'] ?? null) || !array_is_list($manifest['entries'])
            || count($manifest['entries']) > self::MAX_FILES
            || ($manifest['requires_continuous_external_drain'] ?? null) !== true) {
            throw new RuntimeException('Archive snapshot manifest shape is invalid.');
        }
        HarnessPersistenceValue::uid($manifest['snapshot_uid'] ?? null, 'snapshot.uid');
        $schema = (int)($manifest['database']['schema_version'] ?? 0);
        if (!in_array($schema, self::SUPPORTED_SCHEMAS, true)) {
            throw new RuntimeException('Archive snapshot source schema is invalid.');
        }
        // The census required for the recorded schema; older manifests keep their original five queues.
        foreach (WriterDrainCensus::tablesFor($schema) as $name) {
            if (($manifest['drain_counts'][$name] ?? null) !== 0) throw new RuntimeException('Archive snapshot has no verified drained census.');
        }
        $inventory = [];
        $identities = [];
        $paths = [];
        $total = 0;
        foreach ($manifest['entries'] as $entry) {
            $row = self::inventoryRow($entry);
            if ($row['archive_kind'] === 'm70' && $schema < self::SEGMENT_LEDGER_SINCE) {
                throw new RuntimeException('Archive snapshot lists M70 segments for a schema without the segment ledger.');
            }
            $identity = $row['archive_kind'] . ':' . $row['id'];
            if (isset($identities[$identity])) throw new RuntimeException('Duplicate archive ledger identity in snapshot manifest.');
            $identities[$identity] = true;
            $relative = 'archives/' . $row['content_hash'] . '.json';
            if (($entry['path'] ?? '') !== $relative) throw new RuntimeException('Unsafe archive snapshot entry path.');
            if (!isset($paths[$relative])) {
                new FilesystemRetentionArchiveSink($directory . '/archives', $webRoot);
                self::verifyFile(self::regularPath($directory . '/' . $relative), $row['content_hash'], $row['size_bytes']);
                $paths[$relative] = true;
                $total += $row['size_bytes'];
            } else {
                self::verifyFile($directory . '/' . $relative, $row['content_hash'], $row['size_bytes']);
            }
            $inventory[] = $row;
        }
        $inventoryHash = HarnessPersistenceValue::hash($manifest['inventory_hash'] ?? null, 'snapshot.inventory_hash');
        if (!hash_equals($inventoryHash, hash('sha256', HarnessPersistenceValue::canonicalJson($inventory)))) {
            throw new RuntimeException('Archive snapshot inventory hash mismatch.');
        }
        return ['ok' => true, 'manifest_sha256' => $storedHash, 'inventory_hash' => $inventoryHash,
            'ledger_entries' => count($inventory), 'files' => count($paths), 'bytes' => $total];
    }

    private function captureState(): array
    {
        $database = $this->db->fetchOne("SELECT DATABASE() AS database_name,@@hostname AS server_host,@@port AS server_port,@@version AS server_version,
            (SELECT setting_value FROM system_settings WHERE setting_key='schema_version_migrated') AS schema_version");
        $schema = is_array($database) ? (int)($database['schema_version'] ?? 0) : 0;
        if (!in_array($schema, self::SUPPORTED_SCHEMAS, true)) {
            throw new RuntimeException('Archive snapshot requires Schema ' . implode(', ', self::SUPPORTED_SCHEMAS) . '; found ' . $schema . '.');
        }
        $tables = $this->db->fetchOne("SELECT COALESCE(SUM(TABLE_NAME='generation_event_segments'),0) AS segments,
            COALESCE(SUM(TABLE_NAME='postprocess_subtasks'),0) AS subtasks
            FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('generation_event_segments','postprocess_subtasks')");
        $hasSegments = (int)($tables['segments'] ?? 0) === 1;
        $hasSubtasks = (int)($tables['subtasks'] ?? 0) === 1;
        // The ledger/queue set must match the recorded version; a mismatch means an incomplete migration.
        if ($schema >= self::SEGMENT_LEDGER_SINCE && !$hasSegments) {
            throw new RuntimeException("Schema {$schema} archive segment ledger is missing.");
        }
        if ($schema < self::SEGMENT_LEDGER_SINCE && $hasSegments) {
            throw new RuntimeException("Schema {$schema} already has the M70 segment ledger; finish or roll back the migration before snapshotting.");
        }
        $subtasksSince = WriterDrainCensus::SINCE_SCHEMA['postprocess_subtasks'];
        if ($schema >= $subtasksSince && !$hasSubtasks) {
            throw new RuntimeException("Schema {$schema} postprocess_subtasks queue is missing; the drain census cannot be verified.");
        }
        if ($schema < $subtasksSince && $hasSubtasks) {
            throw new RuntimeException("Schema {$schema} already has the v72 postprocess_subtasks queue; finish or roll back the migration before snapshotting.");
        }
        $names = WriterDrainCensus::tablesFor($schema);
        $drain = $this->db->fetchOne('SELECT ' . implode(',', array_map(
            static fn (string $name): string => '(SELECT COUNT(*) FROM ' . $name . ' WHERE ' . WriterDrainCensus::ACTIVE_ROWS[$name] . ') AS ' . $name,
            $names
        )));
        $counts = [];
        foreach ($names as $name) {
            $counts[$name] = HarnessPersistenceValue::nonNegativeInt($drain[$name] ?? null, 'snapshot.drain.' . $name);
            if ($counts[$name] !== 0) throw new RuntimeException('Archive snapshot requires drained writers: ' . $name . ' has active rows.');
        }
        $query = "SELECT 'm65' AS archive_kind,id,run_id,storage_uri,content_hash,size_bytes FROM harness_retention_archives";
        if ($hasSegments) $query .= " UNION ALL SELECT 'm70' AS archive_kind,id,run_id,storage_uri,content_hash,size_bytes FROM generation_event_segments";
        $inventory = $this->db->fetchAll($query . ' ORDER BY archive_kind,id LIMIT 100001');
        if (count($inventory) > self::MAX_FILES) throw new RuntimeException('Archive snapshot inventory exceeds its safe limit.');
        return ['database' => $database, 'drain_counts' => $counts, 'inventory' => array_map(self::inventoryRow(...), $inventory)];
    }

    private static function inventoryRow(mixed $row): array
    {
        if (!is_array($row) || !in_array($row['archive_kind'] ?? null, ['m65','m70'], true)) {
            throw new InvalidArgumentException('Archive snapshot inventory row is invalid.');
        }
        $uri = HarnessPersistenceValue::string($row['storage_uri'] ?? null, 'snapshot.storage_uri', 1, 1000);
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'file'
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['port'])
            || !in_array(strtolower($parts['host'] ?? ''), ['', 'localhost'], true)) {
            throw new InvalidArgumentException('Archive snapshot supports local file URIs only.');
        }
        $path = str_replace('\\', '/', rawurldecode((string)($parts['path'] ?? '')));
        $segments = explode('/', $path);
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '//')
            || (!str_starts_with($path, '/') && preg_match('#^[A-Za-z]:/#D', $path) !== 1)
            || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('Archive source URI must contain an absolute local path without dot segments.');
        }
        return ['archive_kind' => $row['archive_kind'],
            'id' => HarnessPersistenceValue::positiveInt($row['id'] ?? null, 'snapshot.id'),
            'run_id' => HarnessPersistenceValue::positiveInt($row['run_id'] ?? null, 'snapshot.run_id'),
            'storage_uri' => $uri,
            'content_hash' => HarnessPersistenceValue::hash($row['content_hash'] ?? null, 'snapshot.content_hash'),
            'size_bytes' => HarnessPersistenceValue::boundedInt($row['size_bytes'] ?? null, 'snapshot.size_bytes', 1, self::MAX_FILE_BYTES)];
    }

    private static function sourcePath(string $uri, string $webRoot): string
    {
        $path = FilesystemRetentionArchiveSink::fileUriPath($uri);
        if ($path === '' || str_contains($path, "\0")) throw new InvalidArgumentException('Archive source URI path is invalid.');
        new FilesystemRetentionArchiveSink(dirname($path), $webRoot);
        return self::regularPath($path);
    }

    private static function regularPath(string $path): string
    {
        clearstatcache(true, $path);
        $real = realpath($path);
        $key = static fn (string $value): string => DIRECTORY_SEPARATOR === '\\'
            ? strtolower(str_replace('\\', '/', $value)) : str_replace('\\', '/', $value);
        $stat = @lstat($path);
        if (!is_file($path) || is_link($path) || !is_string($real)
            || $key($real) !== $key($path)
            || !is_array($stat) || (((int)$stat['mode'] & 0170000) !== 0100000)) {
            throw new RuntimeException('Archive snapshot requires regular files without links.');
        }
        return $real;
    }

    private function copyVerified(string $source, string $destination, string $hash, int $size): void
    {
        $input = fopen($source, 'rb');
        if ($input === false) throw new RuntimeException('Archive source could not be opened.');
        $output = @fopen($destination, 'xb');
        if ($output === false) { fclose($input); throw new RuntimeException('Snapshot files must not overwrite existing files.'); }
        try {
            $stat = fstat($input);
            if (!is_array($stat) || (int)$stat['size'] !== $size || (((int)$stat['mode'] & 0170000) !== 0100000)) {
                throw new RuntimeException('Archive source size/type differs from its ledger.');
            }
            $context = hash_init('sha256');
            $copied = 0;
            while (!feof($input)) {
                $bytes = fread($input, 1048576);
                if ($bytes === false || ($bytes === '' && !feof($input))) throw new RuntimeException('Archive source read failed.');
                $copied += strlen($bytes);
                if ($copied > $size) throw new RuntimeException('Archive source grew during snapshot.');
                hash_update($context, $bytes);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($output, substr($bytes, $offset));
                    if (!is_int($written) || $written < 1) throw new RuntimeException('Archive snapshot write failed.');
                    $offset += $written;
                }
            }
            if ($copied !== $size || !hash_equals($hash, hash_final($context))) throw new RuntimeException('Archive source checksum differs from its ledger.');
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) throw new RuntimeException('Archive snapshot flush failed.');
        } finally {
            fclose($input);
            fclose($output);
        }
        self::verifyFile(self::regularPath($source), $hash, $size);
        self::verifyFile(self::regularPath($destination), $hash, $size);
    }

    private static function verifyFile(string $path, string $hash, int $size): void
    {
        clearstatcache(true, $path);
        if (filesize($path) !== $size || !hash_equals($hash, (string)hash_file('sha256', $path))) {
            throw new RuntimeException('Archive snapshot file size or checksum mismatch.');
        }
    }

    private static function writeExclusive(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) throw new RuntimeException('Archive snapshot metadata must not overwrite existing files.');
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Archive snapshot metadata write failed.');
        } finally { fclose($handle); }
    }
}
