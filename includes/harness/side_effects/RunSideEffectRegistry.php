<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/RunEventStore.php';

final class SideEffectUnresolvedException extends HarnessPersistenceException {}

/** A logical business action, independent of observations and worker lease changes. */
final readonly class SideEffectOperationRequest
{
    public string $requestHash;

    /** @param array<string,mixed> $request */
    public function __construct(
        public string $operationKey,
        public string $kind,
        public string $target,
        array $request
    ) {
        HarnessPersistenceValue::asciiString($operationKey, 'operation.key', 1, 191);
        HarnessPersistenceValue::identifier($kind, 'operation.kind', 32);
        HarnessPersistenceValue::asciiString($target, 'operation.target', 1, 191);
        HarnessSensitiveDataGuard::assertSafeStructure($request, 'operation.request');
        $this->requestHash = hash('sha256', HarnessPersistenceValue::canonicalMysqlJson($request));
    }

    public function uid(string $runUid): string
    {
        HarnessPersistenceValue::uid($runUid, 'operation.run_uid');
        return hash('sha256', "side-effect-v1\0{$runUid}\0{$this->operationKey}");
    }
}

/**
 * Durable, conservative admission for tools, storage and external operations.
 * Only one caller may leave reserved; invoking/unknown never auto-retry. All
 * state changes and immutable Events commit together, outside external I/O.
 * Run fences authorize admission, not the recording of an already-paid fact.
 */
final class RunSideEffectRegistry
{
    private HarnessPersistenceDriver $db;
    private RunEventStore $events;
    public const MAX_RESULT_BYTES = 1048576;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        private readonly int $runId,
        private readonly string $runUid,
        private readonly ?string $leaseOwner = null,
        private readonly ?string $leaseToken = null,
        private readonly ?int $fenceGeneration = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->events = new RunEventStore($this->db);
        HarnessPersistenceValue::positiveInt($runId, 'operation.run_id');
        HarnessPersistenceValue::uid($runUid, 'operation.run_uid');
        if ($leaseOwner !== null || $leaseToken !== null || $fenceGeneration !== null) {
            HarnessPersistenceValue::identifier($leaseOwner, 'operation.lease_owner', 120);
            HarnessPersistenceValue::uid($leaseToken, 'operation.lease_token');
            HarnessPersistenceValue::positiveInt($fenceGeneration, 'operation.fence_generation');
        }
    }

    /** @return array<string,mixed> */
    public function reserve(SideEffectOperationRequest $request): array
    {
        return $this->transaction(function () use ($request): array {
            $this->assertAdmission($this->lockedRun());
            $uid = $request->uid($this->runUid);
            $existing = $this->find($uid);
            if ($existing !== null) {
                foreach (['operation_key' => $request->operationKey, 'kind' => $request->kind,
                    'target' => $request->target, 'request_hash' => $request->requestHash] as $key => $value) {
                    if (!hash_equals($value, (string)$existing[$key])) {
                        throw new HarnessIdempotencyConflictException('Side-effect operation identity has different immutable input.');
                    }
                }
                $this->assertRow($existing);
                return $existing;
            }
            $this->db->execute(
                "INSERT INTO generation_side_effect_operations
                    (operation_uid,run_id,operation_key,kind,target,request_hash,fence_generation,state)
                 VALUES (?,?,?,?,?,?,?,'reserved')",
                [$uid, $this->runId, $request->operationKey, $request->kind, $request->target,
                    $request->requestHash, $this->fenceGeneration]
            );
            $row = $this->required($uid);
            $this->event($row, 'reserved', []);
            return $row;
        });
    }

    /** Single admission winner; no transaction is held during the caller's I/O. */
    public function begin(string $uid): void
    {
        $this->transaction(function () use ($uid): void {
            $this->assertAdmission($this->lockedRun());
            $row = $this->required($uid);
            if ((int)$row['fence_generation'] !== $this->fenceGeneration) {
                throw new HarnessLeaseLostException('Side-effect reservation belongs to an earlier fence; reconcile it explicitly.');
            }
            if ($row['state'] !== 'reserved') {
                throw new SideEffectUnresolvedException('Side-effect invocation already started or closed; execution is not authorized.');
            }
            $this->change($row, 'invoking', []);
        });
    }

    /** @param array<string,mixed> $result */
    public function settle(string $uid, array $result): void
    {
        $json = self::resultJson($result);
        $this->transaction(function () use ($uid, $json): void {
            $this->lockedRun();
            $row = $this->required($uid);
            $this->assertSettlementOwner($row);
            $hash = hash('sha256', $json);
            if ($row['state'] === 'settled') {
                if (!hash_equals($hash, (string)$row['result_hash'])) {
                    throw new HarnessIdempotencyConflictException('Side-effect result conflicts with the first durable settlement.');
                }
                return;
            }
            if (!in_array($row['state'], ['invoking', 'unknown'], true)) {
                throw new HarnessStateTransitionException('Only an invoked operation may settle.');
            }
            $this->change($row, 'settled', ['result_hash' => $hash], $json);
        });
    }

    public function unknown(string $uid, string $errorCode): void
    {
        HarnessPersistenceValue::identifier($errorCode, 'operation.error_code', 96);
        $this->transaction(function () use ($uid, $errorCode): void {
            $this->lockedRun();
            $row = $this->required($uid);
            $this->assertSettlementOwner($row);
            if ($row['state'] === 'unknown') {
                $this->event($row, 'unknown', ['error_code' => $errorCode]);
                return;
            }
            if ($row['state'] !== 'invoking') {
                throw new HarnessStateTransitionException('Only an invoked operation can have an unknown outcome.');
            }
            $this->change($row, 'unknown', ['error_code' => $errorCode]);
        });
    }

    /** Release is safe without external evidence only before invocation. */
    public function release(string $uid, string $reason): void
    {
        HarnessPersistenceValue::identifier($reason, 'operation.release_reason', 96);
        $this->transaction(function () use ($uid, $reason): void {
            $this->assertAdmission($this->lockedRun());
            $row = $this->required($uid);
            if ((int)$row['fence_generation'] !== $this->fenceGeneration) {
                throw new HarnessLeaseLostException('Only the reservation owner may release it before invocation.');
            }
            if (!in_array($row['state'], ['reserved', 'released'], true)) {
                throw new SideEffectUnresolvedException('An invoked/unknown operation requires explicit reconciliation evidence.');
            }
            $details = ['reason' => $reason];
            if ($row['state'] === 'released') $this->event($row, 'released', $details);
            else $this->change($row, 'released', $details);
        });
    }

    /**
     * Reconcile a stopped Run only with authoritative outcome evidence. A
     * timeout alone is never proof that a remote write did not happen.
     * @param array{outcome:string,reference:string,evidence_hash:string} $evidence
     * @param array<string,mixed>|null $result
     */
    public function reconcile(
        string $uid, string $expectedRequestHash, array $evidence,
        ?array $result = null, int $graceSeconds = 86400
    ): void {
        HarnessPersistenceValue::hash($expectedRequestHash, 'operation.expected_request_hash');
        HarnessPersistenceValue::boundedInt($graceSeconds, 'operation.grace_seconds', 60, 2592000);
        $evidenceKeys = array_keys($evidence);
        sort($evidenceKeys, SORT_STRING);
        if ($evidenceKeys !== ['evidence_hash', 'outcome', 'reference']
            || !in_array($evidence['outcome'] ?? null, ['applied', 'not_applied'], true)) {
            throw new InvalidArgumentException('Reconciliation requires outcome, reference and evidence_hash.');
        }
        HarnessPersistenceValue::string($evidence['reference'], 'operation.evidence_reference', 1, 512);
        HarnessPersistenceValue::hash($evidence['evidence_hash'], 'operation.evidence_hash');
        HarnessSensitiveDataGuard::assertSafeStructure($evidence, 'operation.reconciliation');
        if (($evidence['outcome'] === 'applied') !== ($result !== null)) {
            throw new InvalidArgumentException('Applied reconciliation requires a result; not_applied must not contain one.');
        }
        $json = $result === null ? null : self::resultJson($result);
        $resolution = hash('sha256', HarnessPersistenceValue::canonicalMysqlJson($evidence));
        $this->transaction(function () use ($uid, $expectedRequestHash, $evidence, $json, $resolution, $graceSeconds): void {
            $run = $this->lockedRun();
            $row = $this->required($uid);
            if (!hash_equals((string)$row['request_hash'], $expectedRequestHash)) {
                throw new HarnessIdempotencyConflictException('Reconciliation targets another immutable request.');
            }
            $state = $evidence['outcome'] === 'applied' ? 'settled' : 'released';
            $resultHash = $json === null ? null : hash('sha256', $json);
            if (in_array($row['state'], ['settled', 'released'], true)) {
                if ($row['state'] !== $state || $row['resolution_hash'] !== $resolution || $row['result_hash'] !== $resultHash) {
                    throw new HarnessIdempotencyConflictException('Operation was already resolved by a different outcome or evidence.');
                }
                return;
            }
            if (!in_array($run['state'], ['succeeded','failed','canceled','aborted_after_commit','quarantined'], true)
                || (int)$run['lease_live'] !== 0 || (int)$row['age_seconds'] < $graceSeconds) {
                throw new SideEffectUnresolvedException('Reconciliation requires a terminal Run, expired lease and elapsed grace window.');
            }
            $details = ['reconciliation' => $evidence, 'resolution_hash' => $resolution];
            if ($resultHash !== null) $details['result_hash'] = $resultHash;
            $this->change($row, $state, $details, $json, $resolution);
        });
    }

    /** @param callable():array<string,mixed> $effect @return array<string,mixed> */
    public function execute(SideEffectOperationRequest $request, callable $effect): array
    {
        $row = $this->reserve($request);
        if ($row['state'] === 'settled') return $this->result($row);
        if ($row['state'] !== 'reserved') {
            throw new SideEffectUnresolvedException('Operation has no safely replayable result; reconcile before retrying.');
        }
        $uid = (string)$row['operation_uid'];
        $this->begin($uid);
        try {
            $result = $effect();
            if (!is_array($result)) throw new UnexpectedValueException('Side-effect result must be an array.');
            self::resultJson($result);
        } catch (Throwable $error) {
            // If recording fails, invoking remains a blocking durable state.
            $this->unknown($uid, 'execution_failed');
            throw $error;
        }
        // A lost settlement commit must never authorize an automatic replay.
        $this->settle($uid, $result);
        return $result;
    }

    /** Read-only operational inventory. @return list<array<string,mixed>> */
    public function pending(int $limit = 100): array
    {
        HarnessPersistenceValue::boundedInt($limit, 'operation.limit', 1, 1000);
        return $this->db->fetchAll(
            "SELECT operation_uid,operation_key,kind,target,request_hash,fence_generation,state,created_at,updated_at
             FROM generation_side_effect_operations WHERE run_id=? AND state IN ('reserved','invoking','unknown')
             ORDER BY id LIMIT " . (int)$limit, [$this->runId]
        );
    }

    private function transaction(Closure $operation): mixed
    {
        if ($this->db instanceof PdoHarnessPersistenceDriver && $this->db->inTransaction()) {
            throw new HarnessPersistenceException('Side-effect admission and settlement require independent durable transactions.');
        }
        return $this->db->transactional($operation);
    }

    /** @return array<string,mixed> */
    private function lockedRun(): array
    {
        $row = $this->db->fetchOne(
            'SELECT *, (lease_expires_at IS NOT NULL AND lease_expires_at>UTC_TIMESTAMP(6)) AS lease_live,
             (deadline_at IS NULL OR deadline_at>UTC_TIMESTAMP(6)) AS deadline_open
             FROM generation_runs WHERE id=? FOR UPDATE', [$this->runId]
        );
        if ($row === null || !hash_equals($this->runUid, (string)$row['run_uid'])) {
            throw new HarnessPersistenceException('Side-effect Run identity is missing or changed.');
        }
        return $row;
    }

    /** @param array<string,mixed> $run */
    private function assertAdmission(array $run): void
    {
        if ($this->leaseOwner === null || $this->leaseToken === null || $this->fenceGeneration === null
            || (int)$run['lease_live'] !== 1
            || !hash_equals($this->leaseOwner, (string)$run['lease_owner'])
            || !hash_equals($this->leaseToken, (string)$run['lease_token'])
            || $this->fenceGeneration !== (int)$run['fence_generation']) {
            throw new HarnessLeaseLostException('Side-effect admission lost its exact Run lease fence.');
        }
        if ($run['state'] !== 'running' || (int)$run['cancel_requested'] !== 0 || (int)$run['deadline_open'] !== 1) {
            throw new HarnessStateTransitionException('Only an uncanceled running Run within its deadline may invoke side effects.');
        }
    }

    /** A replacement worker or read-only reconciler cannot invent a provider receipt. */
    private function assertSettlementOwner(array $row): void
    {
        if ($this->fenceGeneration === null || $this->fenceGeneration !== (int)$row['fence_generation']) {
            throw new HarnessLeaseLostException('Only the original operation fence may record an invocation outcome; use reconciliation otherwise.');
        }
    }

    /** @return array<string,mixed>|null */
    private function find(string $uid): ?array
    {
        HarnessPersistenceValue::hash($uid, 'operation.uid');
        return $this->db->fetchOne(
            'SELECT *, TIMESTAMPDIFF(SECOND,updated_at,UTC_TIMESTAMP(6)) AS age_seconds
             FROM generation_side_effect_operations WHERE run_id=? AND operation_uid=? FOR UPDATE', [$this->runId, $uid]
        );
    }

    /** @return array<string,mixed> */
    private function required(string $uid): array
    {
        $row = $this->find($uid) ?? throw new HarnessPersistenceException('Side-effect reservation does not exist.');
        $this->assertRow($row);
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function assertRow(array $row): void
    {
        HarnessPersistenceValue::hash($row['request_hash'] ?? null, 'operation.request_hash');
        HarnessPersistenceValue::identifier($row['kind'] ?? null, 'operation.kind', 32);
        HarnessPersistenceValue::asciiString($row['target'] ?? null, 'operation.target', 1, 191);
        HarnessPersistenceValue::positiveInt($row['fence_generation'] ?? null, 'operation.fence_generation');
        if (!in_array($row['state'] ?? null, ['reserved','invoking','unknown','settled','released'], true)
            || (int)$row['run_id'] !== $this->runId
            || !hash_equals(hash('sha256', "side-effect-v1\0{$this->runUid}\0{$row['operation_key']}"), (string)$row['operation_uid'])) {
            throw new HarnessPersistenceException('Side-effect persisted identity or state is invalid.');
        }
        if ($row['state'] === 'settled') $this->result($row);
        elseif ($row['result_json'] !== null || $row['result_hash'] !== null) {
            throw new HarnessPersistenceException('Unsettled side effect contains an unauthorized result.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function result(array $row): array
    {
        if (!is_string($row['result_json']) || !is_string($row['result_hash'])
            || !hash_equals(hash('sha256', $row['result_json']), $row['result_hash'])) {
            throw new HarnessPersistenceException('Side-effect persisted result checksum is invalid.');
        }
        $result = HarnessPersistenceValue::decodeJsonArray($row['result_json'], 'operation.result');
        if (self::resultJson($result) !== $row['result_json']) {
            throw new HarnessPersistenceException('Side-effect result is not canonical.');
        }
        return $result;
    }

    /** @param array<string,mixed> $result */
    private static function resultJson(array $result): string
    {
        HarnessSensitiveDataGuard::assertSafeStructure($result, 'operation.result');
        $json = HarnessPersistenceValue::canonicalMysqlJson($result);
        if (strlen($json) > self::MAX_RESULT_BYTES) throw new InvalidArgumentException('Side-effect result exceeds its storage bound.');
        return $json;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $details */
    private function change(array $row, string $state, array $details, ?string $result = null, ?string $resolution = null): void
    {
        if ($this->db->execute(
            'UPDATE generation_side_effect_operations SET state=?,result_json=?,result_hash=?,resolution_hash=?,updated_at=UTC_TIMESTAMP(6)
             WHERE id=? AND state=?',
            [$state, $result, $result === null ? null : hash('sha256', $result), $resolution, $row['id'], $row['state']]
        ) !== 1) throw new HarnessPersistenceException('Side-effect state transition lost its locked row.');
        $this->event($row, $state, $details);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $details */
    private function event(array $row, string $state, array $details): void
    {
        $this->events->append(new GenerationEventDraft(
            $this->runId, null, null, 'side_effect.' . $state, 1,
            GenerationEventSeverity::INFO, 'side-effect:' . $row['operation_uid'] . ':' . $state,
            'side_effect_registry', null, null, false,
            ['operation_uid' => $row['operation_uid'], 'operation_key' => $row['operation_key'],
                'kind' => $row['kind'], 'target' => $row['target'], 'request_hash' => $row['request_hash'],
                'fence_generation' => (int)$row['fence_generation'], 'operation_state' => $state] + $details
        ));
    }
}
