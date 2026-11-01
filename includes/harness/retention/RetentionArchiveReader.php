<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionContracts.php';
require_once __DIR__ . '/FilesystemRetentionArchiveSink.php';
require_once dirname(__DIR__) . '/persistence/EventContractRegistry.php';

/**
 * A fully checked, immutable view of one harness.retention.run.v1 file.
 */
final readonly class VerifiedRunRetentionArchive
{
    /** @param array<string,mixed> $bundle */
    public function __construct(
        public string $archiveUid,
        public string $storageUri,
        public string $bytes,
        public string $contentHash,
        public string $snapshotHash,
        public int $runId,
        public string $runUid,
        public string $completedAt,
        public array $bundle,
        public int $eventCount,
        public int $artifactCount,
        public ?int $firstEventNo,
        public ?int $lastEventNo
    ) {
        HarnessPersistenceValue::uid($archiveUid, 'retention_restore.archive_uid');
        HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
        HarnessPersistenceValue::hash($contentHash, 'retention_restore.content_hash');
        HarnessPersistenceValue::hash($snapshotHash, 'retention_restore.snapshot_hash');
        HarnessPersistenceValue::positiveInt($runId, 'retention_restore.run_id');
        HarnessPersistenceValue::uid($runUid, 'retention_restore.run_uid');
        HarnessPersistenceValue::optionalMysqlDate($completedAt, 'retention_restore.completed_at')
            ?? throw new InvalidArgumentException('Retention archive completion time is required.');
    }

    /** @return list<array<string,mixed>> */
    public function events(): array
    {
        /** @var list<array<string,mixed>> $events */
        $events = $this->bundle['events'];
        return $events;
    }

    /** @return list<array<string,mixed>> */
    public function artifacts(): array
    {
        /** @var list<array<string,mixed>> $artifacts */
        $artifacts = $this->bundle['artifacts'];
        return $artifacts;
    }
}

/**
 * Strict archive boundary. It rejects non-canonical JSON, unknown/missing
 * fields, unsafe content, broken row hashes, duplicate coordinates, moved or
 * renamed files, and files under the application web root.
 */
final class RetentionArchiveReader
{
    private const MAX_ARCHIVE_BYTES = 536870912;
    public const MAX_ARCHIVE_ROWS = 100000;
    /** @var list<string> */
    private const TERMINAL_STATES = [
        'succeeded', 'aborted_after_commit', 'failed', 'canceled',
    ];

    public function readFile(string $path, ?string $webRoot = null): VerifiedRunRetentionArchive
    {
        // Reuse the archival writer's component-by-component reparse-point,
        // directory identity and web-root boundary checks for the read side.
        $sink = new FilesystemRetentionArchiveSink(dirname($path), $webRoot);
        $realPath = $this->safeArchivePath($path, $webRoot);
        $handle = @fopen($realPath, 'rb');
        if (!is_resource($handle) || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to lock Retention archive for verification.');
        }
        try {
            $before = fstat($handle);
            if (!is_array($before)) {
                throw new RuntimeException('Unable to stat Retention archive.');
            }
            $size = HarnessPersistenceValue::nonNegativeInt(
                $before['size'] ?? null,
                'retention_restore.file_size'
            );
            if ($size === 0 || $size > self::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('Retention archive size is outside safe bounds.');
            }
            $bytes = stream_get_contents($handle, $size + 1);
            $after = fstat($handle);
            if (!is_string($bytes)
                || strlen($bytes) !== $size
                || !is_array($after)
                || ($after['dev'] ?? null) !== ($before['dev'] ?? null)
                || ($after['ino'] ?? null) !== ($before['ino'] ?? null)
                || ($after['size'] ?? null) !== ($before['size'] ?? null)
                || ($after['mtime'] ?? null) !== ($before['mtime'] ?? null)) {
                throw new RuntimeException('Retention archive changed while it was read.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $verified = $this->verifyBytes($bytes, self::fileUri($realPath));
        if (basename($realPath) !== $verified->archiveUid . '.json') {
            throw new HarnessIdempotencyConflictException(
                'Retention archive filename does not match its derived archive UID.'
            );
        }
        if (!$sink->verify(
            $verified->storageUri,
            $verified->contentHash,
            strlen($verified->bytes)
        )) {
            throw new RuntimeException(
                'Retention archive failed its filesystem boundary verification.'
            );
        }
        return $verified;
    }

    public function verifyBytes(string $bytes, string $storageUri): VerifiedRunRetentionArchive
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_ARCHIVE_BYTES) {
            throw new InvalidArgumentException('Retention archive bytes are outside safe bounds.');
        }
        HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
        try {
            $bundle = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Retention archive is not valid JSON.', 0, $exception);
        }
        if (!is_array($bundle) || array_is_list($bundle)) {
            throw new InvalidArgumentException('Retention archive must be a JSON object.');
        }
        if (!hash_equals(HarnessPersistenceValue::canonicalJson($bundle), $bytes)) {
            throw new HarnessIdempotencyConflictException(
                'Retention archive bytes are not canonical JSON.'
            );
        }
        self::assertExactKeys($bundle, [
            'schema', 'run_id', 'run_uid', 'completed_at', 'run', 'events', 'artifacts',
        ], 'archive');
        if (($bundle['schema'] ?? null) !== 'harness.retention.run.v1') {
            throw new InvalidArgumentException('Retention archive schema is unsupported.');
        }
        $runId = HarnessPersistenceValue::positiveInt($bundle['run_id'] ?? null, 'archive.run_id');
        $runUid = HarnessPersistenceValue::uid($bundle['run_uid'] ?? null, 'archive.run_uid');
        $completedAt = HarnessPersistenceValue::optionalMysqlDate(
            $bundle['completed_at'] ?? null,
            'archive.completed_at'
        ) ?? throw new InvalidArgumentException('Retention archive completion time is required.');
        $run = $bundle['run'] ?? null;
        $events = $bundle['events'] ?? null;
        $artifacts = $bundle['artifacts'] ?? null;
        if (!is_array($run) || array_is_list($run)
            || !is_array($events) || !array_is_list($events)
            || !is_array($artifacts) || !array_is_list($artifacts)) {
            throw new InvalidArgumentException('Retention archive row containers are malformed.');
        }
        if (count($events) > self::MAX_ARCHIVE_ROWS
            || count($artifacts) > self::MAX_ARCHIVE_ROWS) {
            throw new InvalidArgumentException('Retention archive row count is outside safe bounds.');
        }
        $this->validateRun($run);
        [$firstEventNo, $lastEventNo] = $this->validateEvents($runId, $events);
        $this->validateArtifacts($artifacts);
        HarnessSensitiveDataGuard::assertSafeStructure($bundle, 'retention_restore.archive');

        // Reconstructing the original candidate is an independent check of
        // canonical snapshot semantics used by the archival writer.
        $candidate = new RunRetentionCandidate(
            $runId,
            $runUid,
            $completedAt,
            $run,
            $events,
            $artifacts,
            ($lastEventNo ?? 0) + 1
        );
        if (!hash_equals(HarnessPersistenceValue::canonicalJson($candidate->bundle()), $bytes)) {
            throw new HarnessIdempotencyConflictException(
                'Retention archive does not round-trip through its canonical snapshot contract.'
            );
        }
        $contentHash = hash('sha256', $bytes);
        $snapshotHash = $candidate->snapshotHash;
        if (!hash_equals($snapshotHash, $contentHash)) {
            throw new HarnessIdempotencyConflictException(
                'Retention archive content and snapshot hashes diverge.'
            );
        }
        $archiveUid = substr(hash('sha256', implode("\0", [
            'retention-archive-v1', $runUid, $snapshotHash,
        ])), 0, 32);

        /** @var array<string,mixed> $bundle */
        return new VerifiedRunRetentionArchive(
            $archiveUid,
            $storageUri,
            $bytes,
            $contentHash,
            $snapshotHash,
            $runId,
            $runUid,
            $completedAt,
            $bundle,
            count($events),
            count($artifacts),
            $firstEventNo,
            $lastEventNo
        );
    }

    /** @param array<string,mixed> $run */
    private function validateRun(array $run): void
    {
        self::assertExactKeys($run, [
            'state', 'progress', 'workflow_name', 'workflow_version', 'authority_mode',
            'user_id', 'novel_id', 'chapter_id', 'result', 'error_code', 'error_message',
        ], 'archive.run');
        if (!is_string($run['state'] ?? null)
            || !in_array($run['state'], self::TERMINAL_STATES, true)) {
            throw new InvalidArgumentException('Retention archive Run is not terminal.');
        }
        HarnessPersistenceValue::boundedInt($run['progress'] ?? null, 'archive.run.progress', 0, 100);
        HarnessPersistenceValue::identifier($run['workflow_name'] ?? null, 'archive.run.workflow_name', 96);
        HarnessPersistenceValue::identifier($run['workflow_version'] ?? null, 'archive.run.workflow_version', 32);
        HarnessPersistenceValue::identifier($run['authority_mode'] ?? null, 'archive.run.authority_mode', 32);
        HarnessPersistenceValue::positiveInt($run['user_id'] ?? null, 'archive.run.user_id');
        HarnessPersistenceValue::positiveInt($run['novel_id'] ?? null, 'archive.run.novel_id');
        HarnessPersistenceValue::optionalPositiveInt($run['chapter_id'] ?? null, 'archive.run.chapter_id');
        if (!is_array($run['result'] ?? null)) {
            throw new InvalidArgumentException('Retention archive Run result is malformed.');
        }
        HarnessPersistenceValue::optionalString($run['error_code'] ?? null, 'archive.run.error_code', 64);
        HarnessPersistenceValue::optionalString($run['error_message'] ?? null, 'archive.run.error_message', 500);
    }

    /** @param list<array<string,mixed>> $events @return array{?int,?int} */
    private function validateEvents(int $runId, array $events): array
    {
        $integrity = EventStreamIntegrityValidator::validate(
            $runId,
            count($events) + 1,
            $events,
            static fn (mixed $event, int $index): int => $runId,
            static fn (mixed $event, int $index): mixed => is_array($event)
                ? ($event['event_no'] ?? null)
                : null
        );
        $ids = [];
        $numbers = [];
        $keys = [];
        $previousNo = 0;
        foreach ($events as $index => $event) {
            if (!is_array($event) || array_is_list($event)) {
                throw new InvalidArgumentException("Retention Event {$index} is malformed.");
            }
            self::assertExactKeys($event, [
                'id', 'event_no', 'step_id', 'attempt_id', 'event_type', 'event_version',
                'severity', 'event_key', 'actor_type', 'actor_id', 'causation_event_id',
                'client_visible', 'payload', 'payload_hash', 'created_at',
            ], "archive.events.{$index}");
            $id = HarnessPersistenceValue::positiveInt($event['id'] ?? null, "archive.events.{$index}.id");
            $number = HarnessPersistenceValue::positiveInt(
                $event['event_no'] ?? null,
                "archive.events.{$index}.event_no"
            );
            if (isset($ids[$id]) || isset($numbers[$number])) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Event identity/order coordinates collide.'
                );
            }
            $eventKey = HarnessPersistenceValue::asciiString(
                $event['event_key'] ?? null,
                "archive.events.{$index}.event_key",
                1,
                191
            );
            if (isset($keys[$eventKey])) {
                throw new HarnessIdempotencyConflictException('Retention Event keys collide.');
            }
            HarnessPersistenceValue::optionalPositiveInt($event['step_id'] ?? null, "archive.events.{$index}.step_id");
            HarnessPersistenceValue::optionalPositiveInt($event['attempt_id'] ?? null, "archive.events.{$index}.attempt_id");
            HarnessPersistenceValue::identifier($event['event_type'] ?? null, "archive.events.{$index}.event_type", 96);
            HarnessPersistenceValue::boundedInt($event['event_version'] ?? null, "archive.events.{$index}.event_version", 1, 1000);
            $severity = $event['severity'] ?? null;
            if (!is_string($severity) || !in_array($severity, ['debug', 'info', 'warning', 'error'], true)) {
                throw new InvalidArgumentException('Retention Event severity is unknown.');
            }
            HarnessPersistenceValue::identifier($event['actor_type'] ?? null, "archive.events.{$index}.actor_type", 32);
            HarnessPersistenceValue::optionalString($event['actor_id'] ?? null, "archive.events.{$index}.actor_id", 191);
            $cause = HarnessPersistenceValue::optionalPositiveInt(
                $event['causation_event_id'] ?? null,
                "archive.events.{$index}.causation_event_id"
            );
            if ($cause !== null && !isset($ids[$cause])) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Event causation must reference an earlier archived Event.'
                );
            }
            if (!is_bool($event['client_visible'] ?? null) || !is_array($event['payload'] ?? null)) {
                throw new InvalidArgumentException('Retention Event visibility/payload is malformed.');
            }
            $payloadJson = HarnessPersistenceValue::canonicalMysqlJson($event['payload']);
            $payloadHash = HarnessPersistenceValue::hash(
                $event['payload_hash'] ?? null,
                "archive.events.{$index}.payload_hash"
            );
            if (!hash_equals(hash('sha256', $payloadJson), $payloadHash)) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Event payload failed its canonical hash check.'
                );
            }
            EventContractRegistry::resolve(
                EventResolutionScenario::ARCHIVE,
                (string)$event['event_type'],
                (int)$event['event_version'],
                $event['payload']
            );
            HarnessPersistenceValue::optionalMysqlDate(
                $event['created_at'] ?? null,
                "archive.events.{$index}.created_at"
            ) ?? throw new InvalidArgumentException('Retention Event timestamp is required.');
            $ids[$id] = true;
            $numbers[$number] = true;
            $keys[$eventKey] = true;
            $previousNo = $number;
        }
        return [$integrity->firstEventNo, $integrity->lastEventNo];
    }

    /** @param list<array<string,mixed>> $artifacts */
    private function validateArtifacts(array $artifacts): void
    {
        $ids = [];
        $uids = [];
        $revisions = [];
        $previousId = 0;
        foreach ($artifacts as $index => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) {
                throw new InvalidArgumentException("Retention Artifact {$index} is malformed.");
            }
            self::assertExactKeys($artifact, [
                'id', 'artifact_uid', 'step_id', 'artifact_type', 'logical_key',
                'revision_no', 'content_hash', 'mime_type', 'encoding', 'storage_kind',
                'inline_text', 'inline_json', 'storage_uri', 'size_bytes', 'sensitivity',
                'metadata', 'created_at',
            ], "archive.artifacts.{$index}");
            $id = HarnessPersistenceValue::positiveInt($artifact['id'] ?? null, "archive.artifacts.{$index}.id");
            $uid = HarnessPersistenceValue::uid($artifact['artifact_uid'] ?? null, "archive.artifacts.{$index}.artifact_uid");
            if ($id <= $previousId || isset($ids[$id]) || isset($uids[$uid])) {
                throw new HarnessIdempotencyConflictException(
                    'Retention Artifact identity/order coordinates collide.'
                );
            }
            HarnessPersistenceValue::optionalPositiveInt($artifact['step_id'] ?? null, "archive.artifacts.{$index}.step_id");
            HarnessPersistenceValue::identifier($artifact['artifact_type'] ?? null, "archive.artifacts.{$index}.artifact_type", 64);
            $logicalKey = HarnessPersistenceValue::identifier(
                $artifact['logical_key'] ?? null,
                "archive.artifacts.{$index}.logical_key",
                191
            );
            $revisionNo = HarnessPersistenceValue::positiveInt(
                $artifact['revision_no'] ?? null,
                "archive.artifacts.{$index}.revision_no"
            );
            $revisionKey = $logicalKey . "\0" . $revisionNo;
            if (isset($revisions[$revisionKey])) {
                throw new HarnessIdempotencyConflictException('Retention Artifact revisions collide.');
            }
            $contentHash = HarnessPersistenceValue::hash(
                $artifact['content_hash'] ?? null,
                "archive.artifacts.{$index}.content_hash"
            );
            HarnessPersistenceValue::string($artifact['mime_type'] ?? null, "archive.artifacts.{$index}.mime_type", 1, 127);
            HarnessPersistenceValue::identifier($artifact['encoding'] ?? null, "archive.artifacts.{$index}.encoding", 32);
            $size = HarnessPersistenceValue::nonNegativeInt($artifact['size_bytes'] ?? null, "archive.artifacts.{$index}.size_bytes");
            HarnessPersistenceValue::identifier($artifact['sensitivity'] ?? null, "archive.artifacts.{$index}.sensitivity", 24);
            if (!is_array($artifact['metadata'] ?? null)) {
                throw new InvalidArgumentException('Retention Artifact metadata is malformed.');
            }
            HarnessPersistenceValue::optionalMysqlDate(
                $artifact['created_at'] ?? null,
                "archive.artifacts.{$index}.created_at"
            ) ?? throw new InvalidArgumentException('Retention Artifact timestamp is required.');

            $kind = $artifact['storage_kind'] ?? null;
            $inlineText = $artifact['inline_text'] ?? null;
            $inlineJson = $artifact['inline_json'] ?? null;
            $storageUri = $artifact['storage_uri'] ?? null;
            if ($kind === 'inline') {
                if ((int)is_string($inlineText) + (int)is_array($inlineJson) !== 1
                    || $storageUri !== null) {
                    throw new InvalidArgumentException('Retention inline Artifact payload is malformed.');
                }
                $payload = is_string($inlineText)
                    ? $inlineText
                    : HarnessPersistenceValue::canonicalMysqlJson($inlineJson);
                if (strlen($payload) !== $size
                    || !hash_equals(hash('sha256', $payload), $contentHash)) {
                    throw new HarnessIdempotencyConflictException(
                        'Retention inline Artifact payload failed its size/hash check.'
                    );
                }
            } elseif ($kind === 'external') {
                if ($inlineText !== null || $inlineJson !== null || !is_string($storageUri)) {
                    throw new InvalidArgumentException('Retention external Artifact payload is malformed.');
                }
                HarnessPersistenceValue::string($storageUri, "archive.artifacts.{$index}.storage_uri", 1, 1000);
                HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
            } else {
                throw new InvalidArgumentException('Retention Artifact storage kind is unknown.');
            }
            $ids[$id] = true;
            $uids[$uid] = true;
            $revisions[$revisionKey] = true;
            $previousId = $id;
        }
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function assertExactKeys(array $value, array $keys, string $field): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new InvalidArgumentException("{$field} has missing or unknown fields.");
        }
    }

    private function safeArchivePath(string $path, ?string $webRoot): string
    {
        if (!self::isAbsolutePath($path) || self::hasDotSegment($path)) {
            throw new InvalidArgumentException(
                'Retention archive path must be absolute and contain no dot segments.'
            );
        }
        if (is_link($path) || !is_file($path)) {
            throw new InvalidArgumentException('Retention archive must be an existing regular file.');
        }
        $realPath = realpath($path);
        if (!is_string($realPath) || is_link($realPath)) {
            throw new InvalidArgumentException('Retention archive path cannot be resolved safely.');
        }
        if ($webRoot !== null) {
            $realWebRoot = realpath($webRoot);
            if (!is_string($realWebRoot) || !is_dir($realWebRoot)) {
                throw new InvalidArgumentException('Retention web root cannot be resolved safely.');
            }
            $pathKey = self::pathKey($realPath);
            $rootKey = rtrim(self::pathKey($realWebRoot), '/') . '/';
            if (str_starts_with($pathKey . '/', $rootKey)) {
                throw new InvalidArgumentException(
                    'Sensitive Retention archive must be outside the web root.'
                );
            }
        }
        return $realPath;
    }

    public static function fileUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return 'file:///' . ltrim(str_replace('%2F', '/', rawurlencode($normalized)), '/');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/'
            || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':'
                && ($path[2] === '/' || $path[2] === '\\')));
    }

    private static function hasDotSegment(string $path): bool
    {
        foreach (preg_split('~[\\\\/]~', $path) ?: [] as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }
        return false;
    }

    private static function pathKey(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
