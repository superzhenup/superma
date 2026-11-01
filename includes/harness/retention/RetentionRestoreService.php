<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionArchiveReader.php';

final readonly class RetentionRestoreResult
{
    public function __construct(
        public string $mode,
        public string $archiveUid,
        public int $runId,
        public int $eventCount,
        public int $inlineArtifactCount,
        public bool $idempotent
    ) {
        if (!in_array($mode, ['dry-run', 'apply'], true)) {
            throw new InvalidArgumentException('Retention restore mode is invalid.');
        }
        HarnessPersistenceValue::uid($archiveUid, 'retention_restore_result.archive_uid');
        HarnessPersistenceValue::positiveInt($runId, 'retention_restore_result.run_id');
        HarnessPersistenceValue::nonNegativeInt($eventCount, 'retention_restore_result.event_count');
        HarnessPersistenceValue::nonNegativeInt(
            $inlineArtifactCount,
            'retention_restore_result.inline_artifact_count'
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok' => true,
            'mode' => $this->mode,
            'archive_uid' => $this->archiveUid,
            'run_id' => $this->runId,
            'event_count' => $this->eventCount,
            'inline_artifact_count' => $this->inlineArtifactCount,
            'idempotent' => $this->idempotent,
        ];
    }
}

interface RunRetentionRestoreRepository
{
    /**
     * Dry-run must perform every lock/read/compare used by apply but execute no
     * INSERT/UPDATE/DELETE statement.
     */
    public function restore(
        VerifiedRunRetentionArchive $archive,
        bool $apply
    ): RetentionRestoreResult;
}

final readonly class RetentionRestoreService
{
    public function __construct(private RetentionArchiveReader $reader)
    {
    }

    public function verify(string $archivePath, ?string $webRoot = null): VerifiedRunRetentionArchive
    {
        return $this->reader->readFile($archivePath, $webRoot);
    }

    /**
     * Emits row-shaped canonical JSON for review/import into an isolated
     * environment. It never contains SQL and never mutates a database.
     */
    public function rehydrateExport(VerifiedRunRetentionArchive $archive): string
    {
        $events = array_map(
            static function (array $event) use ($archive): array {
                return [
                    'id' => $event['id'],
                    'run_id' => $archive->runId,
                    'step_id' => $event['step_id'],
                    'attempt_id' => $event['attempt_id'],
                    'event_no' => $event['event_no'],
                    'event_type' => $event['event_type'],
                    'event_version' => $event['event_version'],
                    'severity' => $event['severity'],
                    'event_key' => $event['event_key'],
                    'actor_type' => $event['actor_type'],
                    'actor_id' => $event['actor_id'],
                    'causation_event_id' => $event['causation_event_id'],
                    'client_visible' => $event['client_visible'],
                    'payload_json' => HarnessPersistenceValue::canonicalMysqlJson($event['payload']),
                    'payload_hash' => $event['payload_hash'],
                    'created_at' => $event['created_at'],
                ];
            },
            $archive->events()
        );
        $payloads = [];
        foreach ($archive->artifacts() as $artifact) {
            if (($artifact['storage_kind'] ?? null) !== 'inline') {
                continue;
            }
            $payloads[] = [
                'id' => $artifact['id'],
                'run_id' => $archive->runId,
                'artifact_uid' => $artifact['artifact_uid'],
                'content_hash' => $artifact['content_hash'],
                'size_bytes' => $artifact['size_bytes'],
                'storage_kind' => 'inline',
                'inline_text' => $artifact['inline_text'],
                'inline_json' => $artifact['inline_json'] === null
                    ? null
                    : HarnessPersistenceValue::canonicalMysqlJson($artifact['inline_json']),
                'storage_uri' => null,
            ];
        }
        return HarnessPersistenceValue::canonicalJson([
            'schema' => 'harness.retention.rehydrate.v1',
            'source' => [
                'archive_uid' => $archive->archiveUid,
                'storage_uri' => $archive->storageUri,
                'content_hash' => $archive->contentHash,
                'snapshot_hash' => $archive->snapshotHash,
            ],
            'run' => [
                'id' => $archive->runId,
                'run_uid' => $archive->runUid,
                'completed_at' => $archive->completedAt,
                'snapshot' => $archive->bundle['run'],
            ],
            'generation_events' => $events,
            'generation_artifact_payloads' => $payloads,
        ]);
    }

    public function restore(
        VerifiedRunRetentionArchive $archive,
        RunRetentionRestoreRepository $repository,
        bool $apply
    ): RetentionRestoreResult {
        return $repository->restore($archive, $apply);
    }
}

/**
 * Atomic, no-clobber writer for rehydrate exports. The parent must already
 * exist outside the web root. Existing identical bytes are idempotent.
 */
final class ImmutableRetentionRehydrateExporter
{
    public function persist(string $outputPath, string $bytes, ?string $webRoot = null): string
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('Retention rehydrate export cannot be empty.');
        }
        if (!$this->isAbsolutePath($outputPath) || $this->hasDotSegment($outputPath)
            || strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)) !== 'json') {
            throw new InvalidArgumentException(
                'Retention rehydrate output must be an absolute .json path without dot segments.'
            );
        }
        $directory = dirname($outputPath);
        // Reuse the archive sink's junction/symlink and web-root traversal
        // checks even though the export has its own filename contract.
        new FilesystemRetentionArchiveSink($directory, $webRoot);
        $realDirectory = realpath($directory);
        if (!is_string($realDirectory) || !is_dir($realDirectory) || is_link($directory)) {
            throw new InvalidArgumentException(
                'Retention rehydrate output parent must be a pre-created regular directory.'
            );
        }
        if ($webRoot !== null) {
            $realWebRoot = realpath($webRoot);
            if (!is_string($realWebRoot) || !is_dir($realWebRoot)) {
                throw new InvalidArgumentException('Retention web root cannot be resolved.');
            }
            $directoryKey = rtrim($this->pathKey($realDirectory), '/') . '/';
            $webRootKey = rtrim($this->pathKey($realWebRoot), '/') . '/';
            if (str_starts_with($directoryKey, $webRootKey)) {
                throw new InvalidArgumentException(
                    'Sensitive Retention rehydrate exports must be outside the web root.'
                );
            }
        }
        $directoryIdentity = $this->directoryIdentity($realDirectory);
        $canonicalPath = rtrim($realDirectory, "\\/") . DIRECTORY_SEPARATOR . basename($outputPath);
        if (is_link($canonicalPath) || (file_exists($canonicalPath) && !is_file($canonicalPath))) {
            throw new RuntimeException('Existing Retention rehydrate output is unsafe.');
        }
        if (is_file($canonicalPath)) {
            $existing = file_get_contents($canonicalPath);
            if (!is_string($existing) || !hash_equals($existing, $bytes)) {
                throw new HarnessIdempotencyConflictException(
                    'Existing Retention rehydrate output has different immutable bytes.'
                );
            }
            return $canonicalPath;
        }

        $temporary = $canonicalPath . '.tmp.' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create Retention rehydrate temporary output.');
        }
        try {
            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset, min(1048576, $length - $offset)));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('Unable to write complete Retention rehydrate output.');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Unable to write complete Retention rehydrate output.');
            }
        } finally {
            fclose($handle);
        }
        if (!@chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to protect Retention rehydrate output.');
        }
        $this->assertDirectoryIdentity(
            $directory,
            $realDirectory,
            $directoryIdentity,
            $webRoot
        );
        $published = @link($temporary, $canonicalPath);
        @unlink($temporary);
        if (!$published) {
            if (is_file($canonicalPath)) {
                $existing = file_get_contents($canonicalPath);
                if (is_string($existing) && hash_equals($existing, $bytes)) {
                    return $canonicalPath;
                }
            }
            throw new HarnessIdempotencyConflictException(
                'Retention rehydrate output could not be published without clobbering.'
            );
        }
        $this->assertDirectoryIdentity(
            $directory,
            $realDirectory,
            $directoryIdentity,
            $webRoot
        );
        $publishedBytes = file_get_contents($canonicalPath);
        if (!is_string($publishedBytes) || !hash_equals($publishedBytes, $bytes)) {
            throw new RuntimeException('Published Retention rehydrate output failed verification.');
        }
        return $canonicalPath;
    }

    private function assertDirectoryIdentity(
        string $requested,
        string $expectedReal,
        string $expectedIdentity,
        ?string $webRoot
    ): void {
        new FilesystemRetentionArchiveSink($requested, $webRoot);
        $real = realpath($requested);
        if (!is_string($real)
            || !hash_equals($this->pathKey($expectedReal), $this->pathKey($real))
            || !hash_equals($expectedIdentity, $this->directoryIdentity($real))) {
            throw new RuntimeException(
                'Retention rehydrate output directory identity changed.'
            );
        }
    }

    private function directoryIdentity(string $path): string
    {
        $stat = @stat($path);
        $device = is_array($stat) ? ($stat['dev'] ?? null) : null;
        $inode = is_array($stat) ? ($stat['ino'] ?? null) : null;
        if (!is_int($device) || !is_int($inode) || $device < 0 || $inode <= 0) {
            throw new RuntimeException(
                'Retention rehydrate filesystem has no stable directory identity.'
            );
        }
        return (string)$device . ':' . (string)$inode;
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/'
            || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':'
                && ($path[2] === '/' || $path[2] === '\\')));
    }

    private function hasDotSegment(string $path): bool
    {
        foreach (preg_split('~[\\\\/]~', $path) ?: [] as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }
        return false;
    }

    private function pathKey(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
