<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchContracts.php';
require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

final class BranchMemoryScopeException extends RuntimeException
{
}

final class BranchMemoryCasMissException extends RuntimeException
{
}

final readonly class BranchMemoryScope
{
    public string $namespace;

    public function __construct(
        public int $userId,
        public int $novelId,
        public string $branchUid
    ) {
        HarnessPersistenceValue::positiveInt($userId, 'branch_memory.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'branch_memory.novel_id');
        HarnessPersistenceValue::uid($branchUid, 'branch_memory.branch_uid');
        $this->namespace = BranchMemoryNamespace::forBranch(
            $userId,
            $novelId,
            $branchUid
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'novel_id' => $this->novelId,
            'branch_uid' => $this->branchUid,
            'namespace' => $this->namespace,
        ];
    }
}

final readonly class BranchMemoryMutation
{
    public const MAX_PAYLOAD_BYTES = 262144;

    /** @var array<string,mixed> */
    public array $payload;
    public string $payloadJson;
    public string $payloadHash;
    public string $memoryUid;

    /**
     * expectedVersion=null creates only; an integer performs exact CAS.
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public BranchMemoryScope $scope,
        public string $memoryKey,
        public string $recordType,
        array $payload,
        public ?string $sourceRevisionUid = null,
        public ?int $expectedVersion = null
    ) {
        self::memoryKey($memoryKey);
        HarnessPersistenceValue::identifier(
            $recordType,
            'branch_memory.record_type',
            64
        );
        if ($sourceRevisionUid !== null) {
            HarnessPersistenceValue::uid(
                $sourceRevisionUid,
                'branch_memory.source_revision_uid'
            );
        }
        if ($expectedVersion !== null) {
            HarnessPersistenceValue::positiveInt(
                $expectedVersion,
                'branch_memory.expected_version'
            );
        }
        HarnessSensitiveDataGuard::assertSafeStructure(
            $payload,
            'branch_memory.payload'
        );
        $this->payload = $payload;
        $this->payloadJson = HarnessPersistenceValue::canonicalMysqlJson($payload);
        if (strlen($this->payloadJson) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(
                'Branch memory payload exceeds the 256 KiB safety limit.'
            );
        }
        $this->payloadHash = hash('sha256', $this->payloadJson);
        $this->memoryUid = substr(hash(
            'sha256',
            "branch-memory-v1\0{$scope->namespace}\0{$memoryKey}"
        ), 0, 32);
    }

    public static function memoryKey(mixed $value): string
    {
        $key = HarnessPersistenceValue::string(
            $value,
            'branch_memory.memory_key',
            1,
            191
        );
        if ($key !== trim($key)
            || preg_match('/[\x00-\x1F\x7F]/u', $key) === 1) {
            throw new InvalidArgumentException(
                'Branch memory key must be trimmed and contain no control characters.'
            );
        }
        return $key;
    }
}

final readonly class BranchMemoryRecord
{
    /** @var array<string,mixed> */
    public array $payload;

    /** @param array<string,mixed> $payload */
    public function __construct(
        public int $id,
        public string $memoryUid,
        public BranchMemoryScope $scope,
        public string $memoryKey,
        public string $recordType,
        array $payload,
        public string $payloadHash,
        public ?string $sourceRevisionUid,
        public int $recordVersion,
        public string $createdAt,
        public string $updatedAt
    ) {
        HarnessPersistenceValue::positiveInt($id, 'branch_memory.id');
        HarnessPersistenceValue::uid($memoryUid, 'branch_memory.memory_uid');
        BranchMemoryMutation::memoryKey($memoryKey);
        HarnessPersistenceValue::identifier(
            $recordType,
            'branch_memory.record_type',
            64
        );
        HarnessPersistenceValue::hash($payloadHash, 'branch_memory.payload_hash');
        if ($sourceRevisionUid !== null) {
            HarnessPersistenceValue::uid(
                $sourceRevisionUid,
                'branch_memory.source_revision_uid'
            );
        }
        HarnessPersistenceValue::positiveInt(
            $recordVersion,
            'branch_memory.record_version'
        );
        $canonicalPayload = HarnessPersistenceValue::canonicalMysqlJson($payload);
        if (strlen($canonicalPayload) > BranchMemoryMutation::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(
                'Stored Branch memory payload exceeds the 256 KiB safety limit.'
            );
        }
        if (!hash_equals(hash('sha256', $canonicalPayload), $payloadHash)) {
            throw new InvalidArgumentException('Branch memory payload hash is invalid.');
        }
        if (HarnessPersistenceValue::optionalMysqlDate(
            $createdAt,
            'branch_memory.created_at'
        ) === null || HarnessPersistenceValue::optionalMysqlDate(
            $updatedAt,
            'branch_memory.updated_at'
        ) === null) {
            throw new InvalidArgumentException(
                'Branch memory timestamps are required MySQL UTC timestamps.'
            );
        }
        $expectedUid = substr(hash(
            'sha256',
            "branch-memory-v1\0{$scope->namespace}\0{$memoryKey}"
        ), 0, 32);
        if (!hash_equals($expectedUid, $memoryUid)) {
            throw new InvalidArgumentException(
                'Branch memory UID does not match its exact namespace/key.'
            );
        }
        $this->payload = $payload;
    }
}

interface BranchMemoryRepository
{
    public function get(BranchMemoryScope $scope, string $memoryKey): ?BranchMemoryRecord;

    /** @param list<string> $recordTypes @return list<BranchMemoryRecord> */
    public function list(
        BranchMemoryScope $scope,
        array $recordTypes = [],
        int $limit = 100
    ): array;

    public function write(BranchMemoryMutation $mutation): BranchMemoryRecord;
}

/**
 * SQL-backed M66 repository. Every query carries all three ownership columns;
 * a globally valid branch UID alone is never authorization.
 *
 * Main branches are rejected deliberately. The existing MemoryEngine remains
 * the sole main/legacy authority until an explicit, separately evaluated
 * migration replaces it.
 */
final class M66BranchMemoryRepository implements BranchMemoryRepository
{
    private HarnessPersistenceDriver $db;

    public function __construct(PDO|HarnessPersistenceDriver $connection)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    public function get(
        BranchMemoryScope $scope,
        string $memoryKey
    ): ?BranchMemoryRecord {
        $memoryKey = BranchMemoryMutation::memoryKey($memoryKey);
        return $this->db->transactional(function () use ($scope, $memoryKey): ?BranchMemoryRecord {
            $this->assertNonMainActiveScope($scope, false);
            $row = $this->db->fetchOne(
                self::selectSql()
                . ' WHERE user_id=? AND novel_id=? AND branch_uid=? AND memory_key=? LIMIT 1',
                [$scope->userId, $scope->novelId, $scope->branchUid, $memoryKey]
            );
            return $row === null ? null : $this->record($row, $scope);
        });
    }

    public function list(
        BranchMemoryScope $scope,
        array $recordTypes = [],
        int $limit = 100
    ): array {
        $limit = HarnessPersistenceValue::boundedInt(
            $limit,
            'branch_memory.limit',
            1,
            500
        );
        $recordTypes = array_values(array_unique(array_map(
            static fn (mixed $type): string => HarnessPersistenceValue::identifier(
                $type,
                'branch_memory.record_type',
                64
            ),
            $recordTypes
        )));
        sort($recordTypes, SORT_STRING);
        return $this->db->transactional(function () use (
            $scope,
            $recordTypes,
            $limit
        ): array {
            $this->assertNonMainActiveScope($scope, false);
            $sql = self::selectSql()
                . ' WHERE user_id=? AND novel_id=? AND branch_uid=?';
            $params = [$scope->userId, $scope->novelId, $scope->branchUid];
            if ($recordTypes !== []) {
                $sql .= ' AND record_type IN ('
                    . implode(',', array_fill(0, count($recordTypes), '?')) . ')';
                array_push($params, ...$recordTypes);
            }
            $sql .= " ORDER BY memory_key ASC LIMIT {$limit}";
            return array_map(
                fn (array $row): BranchMemoryRecord => $this->record($row, $scope),
                $this->db->fetchAll($sql, $params)
            );
        });
    }

    public function write(BranchMemoryMutation $mutation): BranchMemoryRecord
    {
        return $this->db->transactional(function () use ($mutation): BranchMemoryRecord {
            $scope = $mutation->scope;
            $this->assertNonMainActiveScope($scope, true);
            $this->assertSourceRevision($mutation);

            if ($mutation->expectedVersion === null) {
                $this->db->execute(
                    'INSERT INTO branch_memory_records'
                    . ' (memory_uid,user_id,novel_id,branch_uid,memory_key,record_type,'
                    . ' payload_json,payload_hash,source_revision_uid,record_version)'
                    . ' VALUES (?,?,?,?,?,?,?,?,?,1)'
                    . ' ON DUPLICATE KEY UPDATE memory_uid=memory_uid',
                    [
                        $mutation->memoryUid,
                        $scope->userId,
                        $scope->novelId,
                        $scope->branchUid,
                        $mutation->memoryKey,
                        $mutation->recordType,
                        $mutation->payloadJson,
                        $mutation->payloadHash,
                        $mutation->sourceRevisionUid,
                    ]
                );
                $expectedStoredVersion = 1;
            } else {
                $this->db->execute(
                    'UPDATE branch_memory_records'
                    . ' SET record_type=?,payload_json=?,payload_hash=?,'
                    . ' source_revision_uid=?,record_version=record_version+1,'
                    . ' updated_at=UTC_TIMESTAMP(6)'
                    . ' WHERE user_id=? AND novel_id=? AND branch_uid=?'
                    . ' AND memory_key=? AND memory_uid=? AND record_version=?',
                    [
                        $mutation->recordType,
                        $mutation->payloadJson,
                        $mutation->payloadHash,
                        $mutation->sourceRevisionUid,
                        $scope->userId,
                        $scope->novelId,
                        $scope->branchUid,
                        $mutation->memoryKey,
                        $mutation->memoryUid,
                        $mutation->expectedVersion,
                    ]
                );
                $expectedStoredVersion = $mutation->expectedVersion + 1;
            }

            $row = $this->db->fetchOne(
                self::selectSql()
                . ' WHERE user_id=? AND novel_id=? AND branch_uid=? AND memory_key=? LIMIT 1',
                [
                    $scope->userId,
                    $scope->novelId,
                    $scope->branchUid,
                    $mutation->memoryKey,
                ]
            );
            if ($row === null) {
                throw new BranchMemoryCasMissException(
                    'Branch memory write did not resolve inside its exact scope.'
                );
            }
            $stored = $this->record($row, $scope);
            $matches = $stored->memoryUid === $mutation->memoryUid
                && $stored->recordType === $mutation->recordType
                && hash_equals($stored->payloadHash, $mutation->payloadHash)
                && $stored->sourceRevisionUid === $mutation->sourceRevisionUid
                && $stored->recordVersion === $expectedStoredVersion;
            if (!$matches) {
                throw new BranchMemoryCasMissException(
                    'Branch memory key changed or idempotency coordinates conflict.'
                );
            }
            return $stored;
        });
    }

    private function assertNonMainActiveScope(
        BranchMemoryScope $scope,
        bool $lock
    ): void {
        $row = $this->db->fetchOne(
            'SELECT branch_uid,user_id,novel_id,is_main,state FROM story_branches'
            . ' WHERE user_id=? AND novel_id=? AND branch_uid=? LIMIT 1'
            . ($lock ? ' FOR UPDATE' : ''),
            [$scope->userId, $scope->novelId, $scope->branchUid]
        );
        if ($row === null
            || (int)($row['user_id'] ?? 0) !== $scope->userId
            || (int)($row['novel_id'] ?? 0) !== $scope->novelId
            || ($row['branch_uid'] ?? null) !== $scope->branchUid
            || (int)($row['is_main'] ?? -1) !== 0
            || ($row['state'] ?? null) !== StoryBranchState::ACTIVE->value) {
            throw new BranchMemoryScopeException(
                'Branch memory requires an exact owned, active, non-main Branch.'
            );
        }
    }

    private function assertSourceRevision(BranchMemoryMutation $mutation): void
    {
        if ($mutation->sourceRevisionUid === null) {
            return;
        }
        $scope = $mutation->scope;
        $row = $this->db->fetchOne(
            'SELECT revision_uid FROM chapter_revisions'
            . ' WHERE revision_uid=? AND user_id=? AND novel_id=? AND branch_uid=? LIMIT 1',
            [
                $mutation->sourceRevisionUid,
                $scope->userId,
                $scope->novelId,
                $scope->branchUid,
            ]
        );
        if (($row['revision_uid'] ?? null) !== $mutation->sourceRevisionUid) {
            throw new BranchMemoryScopeException(
                'Branch memory source Revision is outside the exact Branch scope.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    private function record(array $row, BranchMemoryScope $expectedScope): BranchMemoryRecord
    {
        $scope = new BranchMemoryScope(
            HarnessPersistenceValue::positiveInt($row['user_id'] ?? null, 'branch_memory.user_id'),
            HarnessPersistenceValue::positiveInt($row['novel_id'] ?? null, 'branch_memory.novel_id'),
            HarnessPersistenceValue::uid($row['branch_uid'] ?? null, 'branch_memory.branch_uid')
        );
        if (!hash_equals($scope->namespace, $expectedScope->namespace)) {
            throw new BranchMemoryScopeException(
                'Branch memory row crossed its requested namespace.'
            );
        }
        $rawPayload = $row['payload_json'] ?? null;
        if (!is_string($rawPayload)) {
            throw new HarnessPersistenceException(
                'Branch memory payload_json must be returned as JSON text.'
            );
        }
        $payload = HarnessPersistenceValue::decodeJsonArray(
            $rawPayload,
            'branch_memory.payload_json'
        );
        HarnessSensitiveDataGuard::assertSafeStructure(
            $payload,
            'branch_memory.payload'
        );
        return new BranchMemoryRecord(
            HarnessPersistenceValue::positiveInt($row['id'] ?? null, 'branch_memory.id'),
            HarnessPersistenceValue::uid($row['memory_uid'] ?? null, 'branch_memory.memory_uid'),
            $scope,
            BranchMemoryMutation::memoryKey($row['memory_key'] ?? null),
            HarnessPersistenceValue::identifier(
                $row['record_type'] ?? null,
                'branch_memory.record_type',
                64
            ),
            $payload,
            HarnessPersistenceValue::hash(
                $row['payload_hash'] ?? null,
                'branch_memory.payload_hash'
            ),
            self::optionalUid(
                $row['source_revision_uid'] ?? null,
                'branch_memory.source_revision_uid'
            ),
            HarnessPersistenceValue::positiveInt(
                $row['record_version'] ?? null,
                'branch_memory.record_version'
            ),
            (string)($row['created_at'] ?? ''),
            (string)($row['updated_at'] ?? '')
        );
    }

    private static function optionalUid(mixed $value, string $field): ?string
    {
        return $value === null || $value === ''
            ? null
            : HarnessPersistenceValue::uid($value, $field);
    }

    private static function selectSql(): string
    {
        return 'SELECT id,memory_uid,user_id,novel_id,branch_uid,memory_key,'
            . ' record_type,payload_json,payload_hash,source_revision_uid,'
            . ' record_version,created_at,updated_at FROM branch_memory_records';
    }
}

final readonly class BranchMemoryContextSnapshot
{
    public const MAX_CONTEXT_BYTES = 4194304;

    /** @var list<array<string,mixed>> */
    public array $records;
    public string $snapshotHash;

    /** @param list<array<string,mixed>> $records */
    public function __construct(
        public BranchMemoryScope $scope,
        array $records
    ) {
        HarnessSensitiveDataGuard::assertSafeStructure(
            $records,
            'branch_memory.context_records'
        );
        if (strlen(HarnessPersistenceValue::canonicalJson($records))
            > self::MAX_CONTEXT_BYTES) {
            throw new InvalidArgumentException(
                'Branch memory context exceeds the 4 MiB safety limit.'
            );
        }
        $this->records = $records;
        $this->snapshotHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson($this->manifestWithoutHash())
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->manifestWithoutHash() + ['snapshot_hash' => $this->snapshotHash];
    }

    /** @return array<string,mixed> */
    private function manifestWithoutHash(): array
    {
        return [
            'schema' => 'harness.branch_memory.context.v1',
            'scope' => $this->scope->toArray(),
            'records' => $this->records,
            'record_count' => count($this->records),
            'legacy_main_memory_included' => false,
        ];
    }
}

/** Read-only context API for a future persistent Branch generation entry. */
final class BranchMemoryContextProvider
{
    public function __construct(private readonly BranchMemoryRepository $repository)
    {
    }

    /** @param list<string> $recordTypes */
    public function load(
        BranchMemoryScope $scope,
        array $recordTypes = [],
        int $limit = 100
    ): BranchMemoryContextSnapshot {
        $records = array_map(
            static fn (BranchMemoryRecord $record): array => [
                'memory_uid' => $record->memoryUid,
                'memory_key' => $record->memoryKey,
                'record_type' => $record->recordType,
                'payload' => $record->payload,
                'payload_hash' => $record->payloadHash,
                'source_revision_uid' => $record->sourceRevisionUid,
                'record_version' => $record->recordVersion,
            ],
            $this->repository->list($scope, $recordTypes, $limit)
        );
        return new BranchMemoryContextSnapshot($scope, $records);
    }
}
