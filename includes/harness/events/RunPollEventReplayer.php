<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/LegacyWritingTaskRunIdentity.php';
require_once __DIR__ . '/WorkflowStateSemantics.php';
require_once __DIR__ . '/EventStreamIntegrityValidator.php';
require_once dirname(__DIR__) . '/persistence/bootstrap.php';
require_once dirname(__DIR__) . '/retention/RetentionEventRoutingReader.php';
require_once __DIR__ . '/EventProjectionCheckpoint.php';

final class HarnessEventProjectionMismatchException extends RuntimeException
{
}

final class AuthorityWritingTaskRunIdentity
{
    public static function idempotencyKey(string $taskId): string
    {
        LegacyWritingTaskRunIdentity::assertTaskId($taskId);
        return 'chapter:v1:writing-task:' . $taskId;
    }

    private function __construct()
    {
    }
}

final class HarnessEventSourcePolicy
{
    public const LEGACY = 'legacy';
    public const DUAL = 'dual';
    public const EVENTS = 'events';

    private function __construct()
    {
    }

    public static function normalize(mixed $source): string
    {
        return is_string($source) && in_array($source, [
            self::LEGACY,
            self::DUAL,
            self::EVENTS,
        ], true) ? $source : self::LEGACY;
    }

    public static function readApplication(): string
    {
        if (!class_exists('ConfigCenter', false)) {
            return self::LEGACY;
        }
        try {
            return self::normalize(ConfigCenter::get(
                'ws_harness_event_source',
                self::LEGACY,
                'string'
            ));
        } catch (Throwable) {
            return self::LEGACY;
        }
    }
}

/** Resolves an inline Artifact or one tiered into a verified Retention bundle. */
final class AuthorityContentArtifactResolver
{
    private const MAX_EXTERNAL_BYTES = 268435456;
    private Closure $externalReader;

    /** @param callable(string):string|null $externalReader */
    public function __construct(?callable $externalReader = null)
    {
        $this->externalReader = Closure::fromCallable(
            $externalReader ?? [self::class, 'readLocalFileUri']
        );
    }

    /**
     * @param array<string,mixed>|null $artifact
     * @return array<string,mixed>|null
     */
    public function resolve(?array $artifact, ?int $expectedRunId = null): ?array
    {
        if ($artifact === null) {
            return null;
        }
        if ($expectedRunId !== null) {
            $expectedRunId = HarnessPersistenceValue::positiveInt(
                $expectedRunId,
                'poll.expected_run_id'
            );
            $artifactRunId = HarnessPersistenceValue::optionalPositiveInt(
                $artifact['run_id'] ?? null,
                'poll.artifact_run_id'
            );
            if ($artifactRunId !== null && $artifactRunId !== $expectedRunId) {
                throw new HarnessEventProjectionMismatchException(
                    'Poll content Artifact belongs to a different Run.'
                );
            }
        }
        $logicalKey = HarnessPersistenceValue::identifier(
            $artifact['logical_key'] ?? null,
            'poll.artifact_logical_key',
            191
        );
        if (!in_array($logicalKey, [
            'canonical.content',
            'final.response',
            'legacy.draft.full',
            'draft.full',
        ], true)) {
            throw new HarnessEventProjectionMismatchException(
                'Poll content Artifact has an unsupported logical key.'
            );
        }
        $contentHash = HarnessPersistenceValue::hash(
            $artifact['content_hash'] ?? null,
            'poll.artifact_content_hash'
        );
        $storageKind = $artifact['storage_kind'] ?? null;
        if ($storageKind === null && is_string($artifact['inline_text'] ?? null)) {
            // Compatibility for callers/tests created before storage_kind was selected.
            $storageKind = 'inline';
        }
        if ($storageKind === 'inline') {
            if (!is_string($artifact['inline_text'] ?? null)) {
                throw new HarnessEventProjectionMismatchException(
                    'Inline poll content Artifact has no text payload.'
                );
            }
            return $artifact;
        }
        if ($storageKind !== 'external') {
            throw new HarnessEventProjectionMismatchException(
                'Poll content Artifact storage kind is unsupported.'
            );
        }

        $storageUri = HarnessPersistenceValue::string(
            $artifact['storage_uri'] ?? null,
            'poll.artifact_storage_uri',
            1,
            1000
        );
        HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
        try {
            $bytes = ($this->externalReader)($storageUri);
        } catch (Throwable $exception) {
            throw new HarnessEventProjectionMismatchException(
                'External poll content Artifact could not be retrieved.',
                0,
                $exception
            );
        }
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_EXTERNAL_BYTES) {
            throw new HarnessEventProjectionMismatchException(
                'External poll content Artifact bytes are unavailable or oversized.'
            );
        }

        $content = hash_equals(hash('sha256', $bytes), $contentHash)
            ? $bytes
            : $this->extractRetentionArtifact(
                $bytes,
                $artifact,
                $storageUri,
                $contentHash,
                $expectedRunId
            );
        if ($content === '' || !hash_equals(hash('sha256', $content), $contentHash)) {
            throw new HarnessEventProjectionMismatchException(
                'External poll content Artifact failed its hash check.'
            );
        }
        $artifact['inline_text'] = $content;
        $artifact['resolved_from_external'] = true;
        return $artifact;
    }

    /** @param array<string,mixed> $artifact */
    private function extractRetentionArtifact(
        string $bytes,
        array $artifact,
        string $storageUri,
        string $contentHash,
        ?int $expectedRunId
    ): string {
        try {
            $bundle = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HarnessEventProjectionMismatchException(
                'External Artifact is neither direct content nor a Retention bundle.',
                0,
                $exception
            );
        }
        if (!is_array($bundle)
            || ($bundle['schema'] ?? null) !== 'harness.retention.run.v1'
            || !is_array($bundle['artifacts'] ?? null)) {
            throw new HarnessEventProjectionMismatchException(
                'External Artifact Retention bundle is malformed.'
            );
        }
        if ($expectedRunId !== null
            && HarnessPersistenceValue::positiveInt(
                $bundle['run_id'] ?? null,
                'poll.retention_run_id'
            ) !== $expectedRunId) {
            throw new HarnessEventProjectionMismatchException(
                'Retention bundle belongs to a different Run.'
            );
        }
        $rowUid = is_string($artifact['artifact_uid'] ?? null)
            ? HarnessPersistenceValue::uid($artifact['artifact_uid'], 'poll.artifact_uid')
            : null;
        $fragmentUid = self::fragmentArtifactUid($storageUri);
        if ($rowUid !== null && $fragmentUid !== null && !hash_equals($rowUid, $fragmentUid)) {
            throw new HarnessEventProjectionMismatchException(
                'External Artifact URI identity does not match its row.'
            );
        }
        $expectedUid = $rowUid ?? $fragmentUid;
        if ($expectedUid === null) {
            throw new HarnessEventProjectionMismatchException(
                'External Artifact has no immutable identity.'
            );
        }
        $logicalKey = (string)$artifact['logical_key'];
        $matches = [];
        foreach ($bundle['artifacts'] as $entry) {
            if (!is_array($entry)
                || ($entry['logical_key'] ?? null) !== $logicalKey
                || ($entry['content_hash'] ?? null) !== $contentHash
                || ($entry['artifact_uid'] ?? null) !== $expectedUid
                || ($entry['storage_kind'] ?? null) !== 'inline'
                || !is_string($entry['inline_text'] ?? null)) {
                continue;
            }
            $candidate = $entry['inline_text'];
            if (!hash_equals(hash('sha256', $candidate), $contentHash)) {
                throw new HarnessEventProjectionMismatchException(
                    'Retention bundle Artifact payload failed its hash check.'
                );
            }
            $matches[] = $candidate;
        }
        if (count($matches) !== 1) {
            throw new HarnessEventProjectionMismatchException(
                'Retention bundle did not resolve one exact content Artifact.'
            );
        }
        return $matches[0];
    }

    private static function fragmentArtifactUid(string $storageUri): ?string
    {
        $parts = parse_url($storageUri);
        $fragment = is_array($parts) ? ($parts['fragment'] ?? null) : null;
        if (!is_string($fragment) || $fragment === '') {
            return null;
        }
        parse_str($fragment, $values);
        return isset($values['artifact']) && is_string($values['artifact'])
            ? HarnessPersistenceValue::uid($values['artifact'], 'poll.fragment_artifact_uid')
            : null;
    }

    private static function readLocalFileUri(string $storageUri): string
    {
        $parts = parse_url($storageUri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'file') {
            throw new RuntimeException(
                'No external Artifact reader is configured for this storage scheme.'
            );
        }
        $host = (string)($parts['host'] ?? '');
        if ($host !== '' && strtolower($host) !== 'localhost') {
            throw new RuntimeException('Remote file Artifact hosts are forbidden.');
        }
        $path = rawurldecode((string)($parts['path'] ?? ''));
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^/[A-Za-z]:/#', $path) === 1) {
            $path = substr($path, 1);
        }
        $real = realpath($path);
        $size = is_string($real) && is_file($real) ? filesize($real) : false;
        if (!is_string($real) || !is_int($size) || $size < 1 || $size > self::MAX_EXTERNAL_BYTES) {
            throw new RuntimeException('External file Artifact is unavailable or oversized.');
        }
        $bytes = file_get_contents($real);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new RuntimeException('External file Artifact could not be read completely.');
        }
        return $bytes;
    }
}

/** Deterministically reconstructs the legacy poll view from a Run snapshot and Events. */
final class RunPollEventReplayer
{
    /**
     * @param array<string,mixed> $run
     * @param list<GenerationEvent> $events
     * @param array<string,mixed>|null $contentArtifact
     * @return array<string,mixed>
     */
    public function replay(array $run, array $events, ?array $contentArtifact, ?EventProjectionCheckpoint $checkpoint = null): array
    {
        $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'poll.run_id');
        $runUid = HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'poll.run_uid');
        $state = is_string($run['state'] ?? null)
            ? GenerationRunState::tryFrom($run['state'])
            : null;
        if (!$state instanceof GenerationRunState) {
            throw new HarnessEventProjectionMismatchException('Run poll state is unknown.');
        }
        $progress = HarnessPersistenceValue::boundedInt(
            $run['progress'] ?? null,
            'poll.progress',
            0,
            100
        );
        $eventState = null;
        $contentSaved = false;
        try {
            if ($checkpoint !== null && $checkpoint->runId !== $runId) {
                throw new HarnessPersistenceException('Checkpoint belongs to a different Run.');
            }
            $integrity = EventStreamIntegrityValidator::validateRange(
                $runId,
                $checkpoint?->cursor ?? 0,
                HarnessPersistenceValue::positiveInt(
                    $run['next_event_no'] ?? null,
                    'poll.next_event_no'
                ),
                $events
            );
        } catch (Throwable $exception) {
            throw new HarnessEventProjectionMismatchException(
                'Run Event stream failed completeness verification.',
                0,
                $exception
            );
        }
        $lastEventNo = $integrity->lastEventNo ?? $checkpoint?->cursor ?? 0;
        try {
            $folded = EventProjectionReducer::fold($events, $checkpoint?->state);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HarnessEventProjectionMismatchException('Lifecycle Event reduction failed.', 0, $exception);
        }
        $eventState = $folded['state'] === null ? null : GenerationRunState::from($folded['state']);
        $runFence = (int)($run['fence_generation'] ?? $folded['fence_generation']);
        if ($folded['fence_generation'] > $runFence) {
            throw new HarnessEventProjectionMismatchException('Event fence is ahead of its Run snapshot.');
        }
        if ($folded['fence_generation'] === $runFence) {
            $progress = max($progress, $folded['progress']);
        }
        if ($eventState instanceof GenerationRunState
            && WorkflowStateSemantics::isExecutionSettled($eventState)
            && $eventState !== $state) {
            throw new HarnessEventProjectionMismatchException(
                'Settled Event state does not match the materialized Run snapshot.'
            );
        }
        $projectedState = $eventState ?? $state;
        // The Run row and Events commit together in authority mode. The row is
        // the compact snapshot when old Events have already been archived.
        if (!WorkflowStateSemantics::isExecutionSettled($projectedState)) {
            $projectedState = $state;
        }

        $result = self::safeResult($run['result_json'] ?? null);
        $committed = WorkflowStateSemantics::isCommitted($projectedState);
        $content = '';
        if ($contentArtifact !== null) {
            $artifactText = $contentArtifact['inline_text'] ?? null;
            $artifactHash = $contentArtifact['content_hash'] ?? null;
            $logicalKey = $contentArtifact['logical_key'] ?? null;
            if (!is_string($artifactText)
                || $artifactText === ''
                || !is_string($artifactHash)
                || !is_string($logicalKey)
                || !in_array($logicalKey, [
                    'canonical.content',
                    'final.response',
                    'legacy.draft.full',
                    'draft.full',
                ], true)
                || !hash_equals(hash('sha256', $artifactText), $artifactHash)) {
                throw new HarnessEventProjectionMismatchException(
                    'Completed content Artifact failed its hash check.'
                );
            }
            if ($committed) {
                self::assertCommittedContentProof($run, $artifactHash);
                $content = $artifactText;
                $contentSaved = true;
            }
        } elseif ($committed) {
            throw new HarnessEventProjectionMismatchException(
                'A committed authority Run has no retrievable content Artifact.'
            );
        }

        $status = WorkflowStateSemantics::pollStatus($projectedState, $contentSaved);
        if ($status === 'done') {
            $progress = 100;
        }

        return array_merge($result, [
            'ok' => true,
            'status' => $status,
            'progress' => $progress,
            'content' => $content,
            'words' => $content === '' ? (int)($result['words'] ?? 0) : self::wordCount($content),
            'chapter_id' => HarnessPersistenceValue::optionalPositiveInt(
                $run['chapter_id'] ?? null,
                'poll.chapter_id'
            ),
            'error' => $projectedState === GenerationRunState::CANCELED
                ? '用户已取消写作'
                : ($run['error_message'] ?? null),
            'error_code' => $run['error_code'] ?? null,
            'canceled' => $projectedState === GenerationRunState::CANCELED,
            'content_saved' => $contentSaved,
            'durable_fallback' => true,
            'source' => HarnessEventSourcePolicy::EVENTS,
            'run_uid' => $runUid,
            'workflow_version' => $run['workflow_version'] ?? null,
            'event_cursor' => $lastEventNo,
        ]);
    }

    /** @return array<string,mixed> */
    private static function safeResult(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (!is_string($json)) {
            throw new HarnessEventProjectionMismatchException('Run result is malformed.');
        }
        $result = HarnessPersistenceValue::decodeJsonArray($json, 'poll.result_json');
        HarnessSensitiveDataGuard::assertSafeStructure($result, 'poll.result_json');
        // Content must come from its hash-checked Artifact, never an opaque result field.
        unset($result['content']);
        return $result;
    }

    private static function wordCount(string $content): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen(preg_replace('/\s+/u', '', $content) ?? $content, 'UTF-8')
            : strlen(preg_replace('/\s+/', '', $content) ?? $content);
    }

    /** @param array<string,mixed> $run */
    private static function assertCommittedContentProof(array $run, string $artifactHash): void
    {
        try {
            $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'poll.run_id');
            $ledgerRunId = HarnessPersistenceValue::positiveInt(
                $run['ledger_run_id'] ?? null,
                'poll.ledger_run_id'
            );
            $chapterId = HarnessPersistenceValue::positiveInt(
                $run['chapter_id'] ?? null,
                'poll.chapter_id'
            );
            $ledgerChapterId = HarnessPersistenceValue::positiveInt(
                $run['ledger_chapter_id'] ?? null,
                'poll.ledger_chapter_id'
            );
            $draftHash = HarnessPersistenceValue::hash(
                $run['ledger_draft_hash'] ?? null,
                'poll.ledger_draft_hash'
            );
            $committedHash = HarnessPersistenceValue::hash(
                $run['ledger_committed_revision_hash'] ?? null,
                'poll.ledger_committed_revision_hash'
            );
            $canonicalHash = HarnessPersistenceValue::hash(
                $run['canonical_revision_hash'] ?? null,
                'poll.canonical_revision_hash'
            );
            $canonicalCandidateUid = self::optionalUid(
                $run['canonical_candidate_uid'] ?? null,
                'poll.canonical_candidate_uid'
            );
            $ledgerCandidateUid = self::optionalUid(
                $run['ledger_candidate_uid'] ?? null,
                'poll.ledger_candidate_uid'
            );
            $candidateHash = HarnessPersistenceValue::optionalHash(
                $run['ledger_candidate_content_hash'] ?? null,
                'poll.ledger_candidate_content_hash'
            );
        } catch (InvalidArgumentException $exception) {
            throw new HarnessEventProjectionMismatchException(
                'Committed authority proof is missing or malformed.',
                0,
                $exception
            );
        }

        if ($ledgerRunId !== $runId
            || $ledgerChapterId !== $chapterId
            || !hash_equals($draftHash, $artifactHash)
            || !hash_equals($committedHash, $artifactHash)
            || !hash_equals($canonicalHash, $artifactHash)
            || (($canonicalCandidateUid === null) !== ($ledgerCandidateUid === null))
            || ($canonicalCandidateUid !== null
                && !hash_equals($canonicalCandidateUid, (string)$ledgerCandidateUid))
            || (($ledgerCandidateUid === null) !== ($candidateHash === null))
            || ($candidateHash !== null && !hash_equals($candidateHash, $artifactHash))) {
            throw new HarnessEventProjectionMismatchException(
                'Content Artifact is not the exact committed canonical Chapter revision.'
            );
        }
    }

    private static function optionalUid(mixed $value, string $field): ?string
    {
        return $value === null || $value === ''
            ? null
            : HarnessPersistenceValue::uid($value, $field);
    }
}

/** Fetches an exact owned Run, its complete Event stream, and final Artifact. */
final class EventAuthoritativePollReader
{
    public const EVENT_PAGE_SIZE = 1000;
    public const MAX_LIVE_REPLAY_EVENTS = 10000;
    public const MAX_ARCHIVE_REPLAY_EVENTS = RetentionArchiveReader::MAX_ARCHIVE_ROWS;

    private Closure $runLookup;
    private ?Closure $eventReader;
    private Closure $artifactLookup;
    private Closure $commitProofLookup;
    private AuthorityContentArtifactResolver $artifactResolver;
    private bool $durableRead;

    public function __construct(
        ?callable $runLookup = null,
        ?callable $eventReader = null,
        ?callable $artifactLookup = null,
        private readonly RunPollEventReplayer $replayer = new RunPollEventReplayer(),
        ?callable $commitProofLookup = null,
        ?callable $externalArtifactReader = null
    ) {
        $this->durableRead = $runLookup === null && $eventReader === null
            && $artifactLookup === null && $commitProofLookup === null;
        $this->runLookup = Closure::fromCallable($runLookup ?? static function (
            int $userId,
            string $idempotencyKey
        ): ?array {
            return DB::fetch(
                "SELECT id, run_uid, user_id, novel_id, chapter_id, workflow_version,
                        authority_mode, state, progress, next_event_no, result_json,
                        error_code, error_message, fence_generation, event_stream_version
                 FROM generation_runs
                 WHERE user_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE",
                [$userId, $idempotencyKey]
            ) ?: null;
        });
        $this->eventReader = $eventReader === null ? null : Closure::fromCallable($eventReader);
        $this->artifactLookup = Closure::fromCallable($artifactLookup ?? static function (
            int $runId
        ): ?array {
            return DB::fetch(
                "SELECT run_id, artifact_uid, revision_no, storage_kind, inline_text, storage_uri,
                        content_hash, logical_key
                 FROM generation_artifacts
                 WHERE run_id=?
                   AND logical_key IN ('canonical.content','final.response','legacy.draft.full','draft.full')
                 ORDER BY CASE logical_key
                    WHEN 'canonical.content' THEN 1 WHEN 'final.response' THEN 2
                    WHEN 'legacy.draft.full' THEN 3 ELSE 4 END,
                    revision_no DESC, id DESC LIMIT 1",
                [$runId]
            ) ?: null;
        });
        $this->commitProofLookup = Closure::fromCallable(
            $commitProofLookup ?? static function (int $runId, int $chapterId): ?array {
                $rows = DB::fetchAll(
                    "SELECT l.run_id AS ledger_run_id,
                            l.chapter_id AS ledger_chapter_id,
                            l.draft_hash AS ledger_draft_hash,
                            l.committed_revision_hash AS ledger_committed_revision_hash,
                            ch.content_revision_hash AS canonical_revision_hash,
                            ch.canonical_candidate_uid,
                            cc.candidate_uid AS ledger_candidate_uid,
                            cc.content_hash AS ledger_candidate_content_hash
                     FROM chapter_commit_ledger l
                     INNER JOIN chapters ch
                        ON ch.id=l.chapter_id
                       AND ch.status='completed'
                       AND BINARY ch.content_revision_hash=BINARY l.committed_revision_hash
                     LEFT JOIN chapter_candidates cc ON cc.id=l.candidate_id
                     WHERE l.run_id=? AND l.chapter_id=?
                       AND (
                         (l.candidate_id IS NULL AND ch.canonical_candidate_uid IS NULL)
                         OR
                         (l.candidate_id IS NOT NULL
                          AND cc.chapter_id=l.chapter_id
                          AND BINARY cc.candidate_uid=BINARY ch.canonical_candidate_uid
                          AND BINARY cc.content_hash=BINARY l.committed_revision_hash)
                       )
                     ORDER BY l.id DESC LIMIT 2",
                    [$runId, $chapterId]
                );
                if (count($rows) !== 1 || !is_array($rows[0] ?? null)) {
                    throw new HarnessEventProjectionMismatchException(
                        'Committed authority Run has no unique canonical commit proof.'
                    );
                }
                return $rows[0];
            }
        );
        $this->artifactResolver = new AuthorityContentArtifactResolver($externalArtifactReader);
    }

    /** @param array<string,mixed> $task @return array<string,mixed>|null */
    public function read(array $task, bool $requireAuthority = true): ?array
    {
        return $this->lockedRead($task, $requireAuthority, false);
    }

    /** @param array<string,mixed> $task @return array<string,mixed>|null */
    public function readAuthority(array $task): ?array
    {
        return $this->lockedRead($task, true, true);
    }

    private function lockedRead(array $task, bool $requireAuthority, bool $exactAuthority): ?array
    {
        if (!$this->durableRead) {
            return $this->readTask($task, $requireAuthority, $exactAuthority);
        }
        return (new PdoHarnessPersistenceDriver(DB::connect()))->transactional(
            fn(): ?array => $this->readTask($task, $requireAuthority, $exactAuthority)
        );
    }

    /** @param array<string,mixed> $task @return array<string,mixed>|null */
    private function readTask(array $task, bool $requireAuthority, bool $exactAuthority): ?array
    {
        $taskId = $task['task_id'] ?? null;
        if (!is_string($taskId)) {
            throw new InvalidArgumentException('Poll task has no durable identity.');
        }
        LegacyWritingTaskRunIdentity::assertTaskId($taskId);
        $userId = HarnessPersistenceValue::positiveInt($task['user_id'] ?? null, 'poll.user_id');
        $novelId = HarnessPersistenceValue::positiveInt($task['novel_id'] ?? null, 'poll.novel_id');
        $chapterId = HarnessPersistenceValue::optionalPositiveInt(
            $task['chapter_id'] ?? null,
            'poll.task_chapter_id'
        );
        $run = ($this->runLookup)(
            $userId,
            AuthorityWritingTaskRunIdentity::idempotencyKey($taskId)
        );
        if ($run === null && !$exactAuthority) {
            // During dual/shadow rollout the legacy projection may be the only
            // Run. Event authority still rejects it below when required.
            $run = ($this->runLookup)(
                $userId,
                LegacyWritingTaskRunIdentity::idempotencyKey($taskId)
            );
        }
        if ($run === null) {
            return null;
        }
        if (HarnessPersistenceValue::positiveInt($run['user_id'] ?? null, 'poll.run_user_id') !== $userId
            || HarnessPersistenceValue::positiveInt($run['novel_id'] ?? null, 'poll.run_novel_id') !== $novelId
            || HarnessPersistenceValue::optionalPositiveInt(
                $run['chapter_id'] ?? null,
                'poll.run_chapter_id'
            ) !== $chapterId) {
            throw new HarnessEventProjectionMismatchException(
                'Event Run ownership does not match the writing task.'
            );
        }
        if ($requireAuthority && ($run['authority_mode'] ?? null) !== 'authority') {
            throw new HarnessEventProjectionMismatchException(
                'Event authority was requested for a non-authority Run.'
            );
        }

        $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'poll.run_id');
        $runState = is_string($run['state'] ?? null)
            ? GenerationRunState::tryFrom($run['state'])
            : null;
        if (in_array($runState, [
            GenerationRunState::SUCCEEDED,
            GenerationRunState::ABORTED_AFTER_COMMIT,
        ], true)) {
            if ($chapterId === null) {
                throw new HarnessEventProjectionMismatchException(
                    'Committed writing Run has no Chapter identity.'
                );
            }
            $proof = ($this->commitProofLookup)($runId, $chapterId);
            if (!is_array($proof)) {
                throw new HarnessEventProjectionMismatchException(
                    'Committed authority proof lookup is malformed.'
                );
            }
            $run = array_merge($run, $proof);
        }
        $checkpoint = $this->durableRead
            ? (new EventProjectionCheckpointStore(DB::connect()))->load($run) : null;
        $events = [];
        $cursor = $checkpoint?->cursor ?? 0;
        // Never retain verified archive bytes across separate poll requests.
        $routingReader = $this->eventReader === null
            ? new RetentionEventRoutingReader(DB::connect()) : null;
        $archivedSegment = null;
        $streamToken = null;
        do {
            $read = $this->eventReader !== null
                ? ($this->eventReader)($runId, $cursor, self::EVENT_PAGE_SIZE)
                : $routingReader->readPageAfter($runId, $cursor, self::EVENT_PAGE_SIZE);
            // Existing injected array readers retain the conservative live bound.
            $pageArchived = $read instanceof RetentionEventPage && $read->archived;
            $pageToken = $read instanceof RetentionEventPage ? $read->streamToken : null;
            if ($streamToken !== null && $streamToken !== $pageToken) {
                throw new HarnessEventProjectionMismatchException('Event segment manifest changed during pagination.');
            }
            $streamToken = $pageToken;
            $page = $read instanceof RetentionEventPage ? $read->events : $read;
            if (!is_array($page) || count($page) > self::EVENT_PAGE_SIZE) {
                throw new HarnessEventProjectionMismatchException('Event page is malformed.');
            }
            if ($archivedSegment !== null && $archivedSegment !== $pageArchived) {
                throw new HarnessEventProjectionMismatchException(
                    'Run Event replay segment changed during pagination; retry the poll.'
                );
            }
            $archivedSegment = $pageArchived;
            foreach ($page as $event) {
                if (!$event instanceof GenerationEvent || $event->eventNo <= $cursor) {
                    throw new HarnessEventProjectionMismatchException('Event page is unordered.');
                }
                $events[] = $event;
                $cursor = $event->eventNo;
            }
            $maxEvents = $archivedSegment
                ? self::MAX_ARCHIVE_REPLAY_EVENTS : self::MAX_LIVE_REPLAY_EVENTS;
            if (count($events) > $maxEvents) {
                throw new HarnessEventProjectionMismatchException(
                    sprintf('Run Event replay exceeded its %s segment limit of %d events.',
                        $archivedSegment ? 'archive' : 'live', $maxEvents)
                );
            }
        } while (count($page) === self::EVENT_PAGE_SIZE);

        $artifact = null;
        if (in_array($runState, [
            GenerationRunState::SUCCEEDED,
            GenerationRunState::ABORTED_AFTER_COMMIT,
        ], true)) {
            $artifact = ($this->artifactLookup)($runId);
            if ($artifact !== null && !is_array($artifact)) {
                throw new HarnessEventProjectionMismatchException(
                    'Content Artifact lookup is malformed.'
                );
            }
            try {
                $artifact = $this->artifactResolver->resolve($artifact, $runId);
            } catch (HarnessEventProjectionMismatchException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new HarnessEventProjectionMismatchException(
                    'Content Artifact resolution failed closed.',
                    0,
                    $exception
                );
            }
        }
        $projection = $this->replayer->replay($run, $events, $artifact, $checkpoint);
        return $this->durableRead ? $this->applyWatermark($run, $projection, $artifact) : $projection;
    }

    /** Called while holding the Run lock used for stream and current Canon proof. */
    private function applyWatermark(array $run, array $projection, ?array $artifact): array
    {
        $driver = new PdoHarnessPersistenceDriver(DB::connect());
        $runId = (int)$run['id'];
        $fence = (int)$run['fence_generation'];
        $revision = (int)($artifact['revision_no'] ?? 0);
        $cursor = (int)$projection['event_cursor'];
        $hash = $projection['content_saved'] ? (string)$artifact['content_hash'] : null;
        $previous = $driver->fetchOne('SELECT * FROM generation_poll_watermarks WHERE run_id=? FOR UPDATE', [$runId]);
        if ($previous !== null) {
            $oldFence = (int)$previous['fence_generation'];
            $oldRevision = (int)$previous['artifact_revision'];
            if ($fence < $oldFence || $cursor < (int)$previous['event_cursor']
                || ($fence === $oldFence && $revision < $oldRevision)) {
                throw new HarnessEventProjectionMismatchException('Poll snapshot scope or cursor moved backwards.');
            }
            if ($fence === $oldFence && $revision === $oldRevision) {
                $projection['progress'] = max((int)$projection['progress'], (int)$previous['progress']);
                if ($previous['content_hash'] !== null
                    && ($hash === null || !hash_equals((string)$previous['content_hash'], $hash))) {
                    throw new HarnessEventProjectionMismatchException('Previously proved Canon revision lost its exact proof.');
                }
            }
        }
        $driver->execute('INSERT INTO generation_poll_watermarks
            (run_id,fence_generation,artifact_revision,event_cursor,progress,content_hash,updated_at)
            VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE
            fence_generation=VALUES(fence_generation),artifact_revision=VALUES(artifact_revision),
            event_cursor=VALUES(event_cursor),progress=VALUES(progress),content_hash=VALUES(content_hash),updated_at=VALUES(updated_at)',
            [$runId, $fence, $revision, $cursor, $projection['progress'], $hash]);
        return $projection;
    }
}

final class HarnessPollProjectionComparator
{
    /** @return array<string,array{legacy:mixed,events:mixed}> */
    public static function diff(array $legacy, array $events): array
    {
        $diff = [];
        foreach (['status', 'chapter_id', 'content_saved'] as $field) {
            if (($legacy[$field] ?? null) !== ($events[$field] ?? null)) {
                $diff[$field] = [
                    'legacy' => $legacy[$field] ?? null,
                    'events' => $events[$field] ?? null,
                ];
            }
        }
        $terminal = is_string($events['status'] ?? null)
            && WorkflowStateSemantics::isPollTerminal($events['status']);
        if ($terminal && (int)($legacy['progress'] ?? 0) !== (int)($events['progress'] ?? 0)) {
            $diff['progress'] = [
                'legacy' => (int)($legacy['progress'] ?? 0),
                'events' => (int)($events['progress'] ?? 0),
            ];
        }
        $legacyContent = $legacy['content'] ?? null;
        $eventContent = $events['content'] ?? null;
        $legacyHasContent = is_string($legacyContent) && $legacyContent !== '';
        $eventHasContent = is_string($eventContent) && $eventContent !== '';
        $compareContent = $terminal
            || ($legacy['content_saved'] ?? false) === true
            || ($events['content_saved'] ?? false) === true
            || $legacyHasContent
            || $eventHasContent;
        $legacyHash = $legacyHasContent ? hash('sha256', $legacyContent) : null;
        $eventHash = $eventHasContent ? hash('sha256', $eventContent) : null;
        if ($compareContent
            && ($legacyHasContent !== $eventHasContent
                || ($legacyHasContent && !hash_equals((string)$legacyHash, (string)$eventHash)))) {
            $diff['content_hash'] = [
                'legacy' => $legacyHash,
                'events' => $eventHash,
            ];
        }
        ksort($diff, SORT_STRING);
        return $diff;
    }
}

final readonly class AuthorityRunEventPage
{
    /** @param list<array<string,mixed>> $events */
    public function __construct(
        public int $nextCursor,
        public array $events,
        public string $runUid
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => 'available',
            'next_cursor' => $this->nextCursor,
            'events' => $this->events,
            'run_uid' => $this->runUid,
        ];
    }
}

/** Incremental client-visible cursor for the authority Run. */
final class AuthorityWritingTaskEventCursor
{
    /** @param array<string,mixed> $task */
    public function read(array $task, int $cursor, int $limit = 50): AuthorityRunEventPage
    {
        $taskId = $task['task_id'] ?? null;
        if (!is_string($taskId)) {
            throw new InvalidArgumentException('Authority cursor task identity is missing.');
        }
        LegacyWritingTaskRunIdentity::assertTaskId($taskId);
        $userId = HarnessPersistenceValue::positiveInt($task['user_id'] ?? null, 'cursor.user_id');
        $novelId = HarnessPersistenceValue::positiveInt($task['novel_id'] ?? null, 'cursor.novel_id');
        $chapterId = HarnessPersistenceValue::optionalPositiveInt(
            $task['chapter_id'] ?? null,
            'cursor.chapter_id'
        );
        $cursor = HarnessPersistenceValue::nonNegativeInt($cursor, 'cursor.event_no');
        $limit = HarnessPersistenceValue::boundedInt($limit, 'cursor.limit', 1, 100);
        $run = DB::fetch(
            "SELECT id, run_uid, user_id, novel_id, chapter_id, authority_mode
             FROM generation_runs WHERE user_id=? AND idempotency_key=? LIMIT 1",
            [$userId, AuthorityWritingTaskRunIdentity::idempotencyKey($taskId)]
        );
        if (!is_array($run)
            || ($run['authority_mode'] ?? null) !== 'authority'
            || HarnessPersistenceValue::positiveInt($run['user_id'] ?? null, 'cursor.run_user_id') !== $userId
            || HarnessPersistenceValue::positiveInt($run['novel_id'] ?? null, 'cursor.run_novel_id') !== $novelId
            || HarnessPersistenceValue::optionalPositiveInt(
                $run['chapter_id'] ?? null,
                'cursor.run_chapter_id'
            ) !== $chapterId) {
            throw new HarnessEventProjectionMismatchException(
                'Authority cursor Run does not match the owned writing task.'
            );
        }
        $runId = HarnessPersistenceValue::positiveInt($run['id'] ?? null, 'cursor.run_id');
        $runUid = HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'cursor.run_uid');
        $records = (new RetentionEventRoutingReader(DB::connect()))
            ->readAfter($runId, $cursor, $limit);
        $events = [];
        $next = $cursor;
        foreach ($records as $event) {
            if ($event->eventNo <= $next) {
                throw new HarnessEventProjectionMismatchException('Authority cursor is unordered.');
            }
            $next = $event->eventNo;
            $clientEvent = self::clientEvent($event);
            if ($clientEvent !== null) {
                $events[] = $clientEvent;
            }
        }
        return new AuthorityRunEventPage($next, $events, $runUid);
    }

    /** @return array<string,mixed>|null */
    public static function clientEvent(GenerationEvent $event): ?array
    {
        $payload = HarnessPersistenceValue::decodeJsonArray(
            $event->payloadJson,
            'cursor.payload'
        );
        $resolution = EventContractRegistry::resolve(
            EventResolutionScenario::CLIENT_STREAM,
            $event->eventType,
            $event->eventVersion,
            $payload
        );
        if (!$event->clientVisible || !$resolution->clientOutputAllowed) {
            return null;
        }
        return [
            'event_no' => $event->eventNo,
            'type' => $event->eventType,
            'version' => $event->eventVersion,
            'severity' => $event->severity->value,
            'payload' => $payload,
            'created_at' => $event->createdAt,
        ];
    }
}
