<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ModelContracts.php';
require_once __DIR__ . '/PostprocessModelBudgetAuthority.php';
require_once dirname(__DIR__) . '/runtime/RunBudget.php';
require_once dirname(__DIR__) . '/persistence/RunEventStore.php';

/**
 * Short Run-row transactions reserve before network I/O and settle afterwards.
 * Outstanding/unknown calls remain charged at their upper estimate, including
 * after a worker crash. The event ledger identifies estimates for reconciliation.
 * No transaction is held across network I/O and no new schema is required.
 */
final class RunModelBudgetLedger implements ModelInvocationAccounting
{
    private HarnessPersistenceDriver $db;
    private RunEventStore $events;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        private readonly int $runId,
        private readonly string $runUid,
        private readonly RunBudget $budget,
        private readonly string $leaseOwner,
        private readonly string $leaseToken,
        private readonly int $fenceGeneration,
        private readonly ?PostprocessModelBudgetAuthority $postprocessAuthority = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->events = new RunEventStore($this->db);
        HarnessPersistenceValue::positiveInt($runId, 'model_ledger.run_id');
        HarnessPersistenceValue::uid($runUid, 'model_ledger.run_uid');
        HarnessPersistenceValue::identifier($leaseOwner, 'model_ledger.lease_owner', 120);
        HarnessPersistenceValue::uid($leaseToken, 'model_ledger.lease_token');
        HarnessPersistenceValue::positiveInt($fenceGeneration, 'model_ledger.fence_generation');
        $budget->assertFunded();
        if ($postprocessAuthority !== null
            && ($postprocessAuthority->leaseOwner !== $leaseOwner
                || $postprocessAuthority->leaseToken !== $leaseToken
                || $postprocessAuthority->fenceGeneration !== $fenceGeneration)) {
            throw new InvalidArgumentException('Post-processing budget lease identities disagree.');
        }
    }

    private const MAX_RESERVATION_ATTEMPTS = 10000;

    /**
     * Deterministic reservation identity derived only from durable
     * coordinates: the Run lease fence scopes every logical operation, the
     * caller supplies the business operation key, and the sequential attempt
     * ordinal disambiguates repeated provider attempts of the same operation
     * (e.g. a not-started release followed by a fallback re-run). A retried
     * business action rebuilds the exact same token instead of inventing a
     * new random reservation that no crash-recovery process could reclaim.
     */
    public static function deriveReservation(
        string $runUid,
        int $fenceGeneration,
        string $postprocessJobUid,
        string $operationKey,
        string $provider,
        string $model,
        int $attempt
    ): string {
        HarnessPersistenceValue::uid($runUid, 'model_ledger.run_uid');
        HarnessPersistenceValue::positiveInt($fenceGeneration, 'model_ledger.fence_generation');
        if ($postprocessJobUid !== '') {
            HarnessPersistenceValue::uid($postprocessJobUid, 'model_ledger.postprocess_job_uid');
        }
        HarnessPersistenceValue::asciiString($operationKey, 'model_ledger.operation_key', 1, 191);
        HarnessPersistenceValue::identifier($provider, 'model_ledger.provider', 64);
        HarnessPersistenceValue::string($model, 'model_ledger.model', 1, 191);
        HarnessPersistenceValue::positiveInt($attempt, 'model_ledger.reservation_attempt');
        return substr(hash('sha256', implode("\0", [
            'model-budget-reservation-v1',
            $runUid,
            (string)$fenceGeneration,
            $postprocessJobUid,
            $operationKey,
            $provider,
            $model,
            (string)$attempt,
        ])), 0, 32);
    }

    public function reserve(ModelAdapter $adapter, ModelRequest $request): string
    {
        $this->assertIndependentTransaction();
        if (!$adapter instanceof TokenBudgetModelAdapter) {
            throw new ModelBudgetEstimateUnavailableException('Run admission requires a token reservation estimate.');
        }
        $operationKey = $request->operationKey ?? throw new InvalidArgumentException(
            'Model reservation requires a durable operation key derived from persistent coordinates.'
        );
        $usage = $adapter->reservationUsage($request);
        if ($usage->totalTokens <= 0) {
            throw new ModelBudgetEstimateUnavailableException('Model reservation has no positive token estimate.');
        }
        return $this->db->transactional(
            function () use ($adapter, $request, $usage, $operationKey): string {
                $row = $this->lockedRun();
                $allowedStates = $this->postprocessAuthority === null
                    ? ['running'] : ['running', 'succeeded', 'retry_wait'];
                if (!in_array($row['state'] ?? '', $allowedStates, true) || (int)($row['cancel_requested'] ?? 0) !== 0) {
                    throw new RunCanceledException('Only an active Run may reserve a model invocation.');
                }
                if ((int)($row['deadline_open'] ?? 0) !== 1) {
                    throw new DeadlineExceededException('Run deadline expired before model budget admission.');
                }
                if ($this->postprocessAuthority !== null) {
                    $this->postprocessAuthority->assertAdmitted($this->db, $row);
                } elseif ((int)($row['lease_live'] ?? 0) !== 1) {
                    throw new LeaseLostException('Run lease expired before model budget admission.');
                }
                if ($this->postprocessAuthority === null && (!hash_equals($this->leaseOwner, (string)($row['lease_owner'] ?? ''))
                    || !hash_equals($this->leaseToken, (string)($row['lease_token'] ?? ''))
                    || $this->fenceGeneration !== (int)($row['fence_generation'] ?? 0))) {
                    throw new LeaseLostException('Run model budget caller no longer owns the exact lease fence.');
                }

                [$reservation, $attempt, $existing] = $this->resolveOpenReservation(
                    $adapter, $operationKey, $this->archivedHistory($row, adapter: $adapter, operationKey: $operationKey)
                );
                $draft = $this->draft('reserved', $reservation, [
                    'reservation' => $reservation,
                    'operation_key' => $operationKey,
                    'reservation_attempt' => $attempt,
                    'provider' => $adapter->provider(), 'model' => $adapter->model(),
                    'fence_generation' => $this->fenceGeneration,
                    'postprocess_job_id' => $this->postprocessAuthority?->jobId,
                    'max_output_tokens' => $request->maxOutputTokens,
                    'usage' => self::usagePayload($usage),
                ]);
                if ($existing !== null) {
                    // Idempotent re-entry of the same durable operation: the
                    // estimate is already charged, so return without appending.
                    $this->assertCompatibleReplay($draft, $existing);
                    return $reservation;
                }

                $spent = new RunBudget(
                    $this->budget->tokenLimit, $this->budget->costLimit,
                    max($this->budget->inputTokens, (int)$row['input_tokens']),
                    max($this->budget->outputTokens, (int)$row['output_tokens']),
                    '0'
                );
                $spent->consume($usage->inputTokens, $usage->outputTokens,
                    '0');
                $this->events->append($draft, $usage);
                return $reservation;
            }
        );
    }

    /**
     * Walks the durable reservation attempts of one operation identity. A
     * settled (or explicitly released) attempt is closed forever and can only
     * be followed by its sequential successor; an outstanding attempt is the
     * idempotent re-entry point for a retried business action.
     *
     * @return array{0:string,1:int,2:?array<string,mixed>}
     */
    private function resolveOpenReservation(TokenBudgetModelAdapter $adapter, string $operationKey, ?array $history = null): array
    {
        $attempt = 1;
        while (true) {
            $reservation = self::deriveReservation(
                $this->runUid,
                $this->fenceGeneration,
                $this->postprocessAuthority?->jobUid ?? '',
                $operationKey,
                $adapter->provider(),
                $adapter->model(),
                $attempt
            );
            $reserved = $this->reservationEvent('model-budget:reserved:' . $reservation, $history);
            if ($reserved === null) {
                return [$reservation, $attempt, null];
            }
            $settled = $this->reservationEvent('model-budget:settled:' . $reservation, $history);
            if ($settled === null) {
                return [$reservation, $attempt, $reserved];
            }
            $attempt++;
            if ($attempt > self::MAX_RESERVATION_ATTEMPTS) {
                throw new HarnessPersistenceException(
                    'Model operation exceeded its durable reservation attempt budget.'
                );
            }
        }
    }

    public function received(string $reservation, ModelResponse $response): void
    {
        $this->settle($reservation, $response->usage, [
            'status' => 'response_received', 'provider' => $response->provider,
            'model' => $response->model, 'finish_reason' => $response->finishReason,
            'content_hash' => hash('sha256', $response->content),
        ]);
        if ($response->usage->source !== 'provider' || $response->usage->totalTokens <= 0) {
            throw new ModelInvocationException(
                'Provider usage is missing; the full reservation was retained and the Run must stop.'
            );
        }
    }

    public function failed(string $reservation, Throwable $exception): void
    {
        $usage = $exception instanceof ModelPaidInvocationException ? $exception->usage : null;
        // An explicit pre-invocation failure can safely release its reservation.
        if ($exception instanceof ModelInvocationNotStartedException) {
            $usage = new ModelUsage(0, 0, 0);
        }
        $metadata = ['status' => 'failed', 'exception_class' => $exception::class];
        if ($exception instanceof ModelPaidInvocationException) {
            $metadata['provider'] = $exception->provider;
            $metadata['model'] = $exception->model;
        }
        $this->settle($reservation, $usage, $metadata,
            $exception instanceof ModelInvocationNotStartedException);
    }

    /**
     * Reconciliation only: retain an unresolved reservation, or acknowledge a
     * verified settlement that won the Run lock. Ordinary received/failed
     * calls still require exact immutable equality for repeated settlement.
     */
    public function abandonIfOutstanding(string $reservation, Throwable $reason): bool
    {
        return $this->settle($reservation, null,
            ['status' => 'failed', 'exception_class' => $reason::class], false, true);
    }

    /** @param array<string,mixed> $metadata */
    private function settle(string $reservation, ?ModelUsage $actual, array $metadata,
        bool $unstarted = false, bool $onlyIfOutstanding = false): bool
    {
        $this->assertIndependentTransaction();
        HarnessPersistenceValue::uid($reservation, 'model_ledger.reservation');
        $changed = $this->db->transactional(function () use (
            $reservation, $actual, $metadata, $unstarted, $onlyIfOutstanding
        ): bool {
            $run = $this->lockedRun();
            if ($onlyIfOutstanding && !in_array($run['state'],
                ['succeeded', 'aborted_after_commit', 'failed', 'canceled'], true)) {
                return false;
            }
            $history = $this->archivedHistory($run, reservation: $reservation);
            $reserved = $this->reservationEvent('model-budget:reserved:' . $reservation, $history);
            if ($reserved === null) {
                throw new HarnessPersistenceException('Cannot settle an unknown model reservation.');
            }
            $payload = json_decode(GenerationEvent::fromRow($reserved)->payloadJson, true, 64, JSON_THROW_ON_ERROR);
            $estimate = $payload['usage'];
            $trusted = $unstarted || ($actual !== null && $actual->source === 'provider'
                && $actual->totalTokens > 0
                && ($metadata['provider'] ?? null) === ($payload['provider'] ?? null)
                && ($metadata['model'] ?? null) === ($payload['model'] ?? null));
            $charged = $trusted ? $actual : new ModelUsage(
                max((int)$estimate['input_tokens'], $actual?->inputTokens ?? 0),
                max((int)$estimate['output_tokens'], $actual?->outputTokens ?? 0),
                max((int)$estimate['input_tokens'], $actual?->inputTokens ?? 0)
                    + max((int)$estimate['output_tokens'], $actual?->outputTokens ?? 0),
                0, 'estimated'
            );
            $draft = $this->draft('settled', $reservation, $metadata + [
                'reservation' => $reservation,
                'usage' => self::usagePayload($charged),
                'provider_usage' => $actual === null ? null : self::usagePayload($actual),
                'reservation_retained' => !$trusted,
            ]);
            $existing = $this->reservationEvent($draft->eventKey, $history);
            if ($existing !== null) {
                if ($onlyIfOutstanding) {
                    $this->assertRecordedSettlement($existing, $reservation);
                } else {
                    $this->assertCompatibleReplay($draft, $existing);
                }
                return false;
            }
            // Persist even an unexpectedly over-budget paid response. Rejection
            // must never roll back the supplier's already incurred usage.
            $this->db->execute(
                'UPDATE generation_runs SET input_tokens=input_tokens-?+?, output_tokens=output_tokens-?+?, cost_total=cost_total-?+? WHERE id=?',
                [(int)$estimate['input_tokens'], $charged->inputTokens,
                    (int)$estimate['output_tokens'], $charged->outputTokens,
                    '0',
                    '0', $this->runId]
            );
            $this->events->append($draft);
            return true;
        });
        if ($onlyIfOutstanding && !$changed) return false;
        // This check deliberately runs AFTER COMMIT, retaining paid evidence.
        $spent = $this->events->modelUsage($this->runId);
        new RunBudget($this->budget->tokenLimit, $this->budget->costLimit,
            $spent->inputTokens, $spent->outputTokens,
            '0');
        return $changed;
    }

    /** @param array<string,mixed> $row */
    private function assertRecordedSettlement(array $row, string $reservation): void
    {
        $event = GenerationEvent::fromRow($row);
        $payload = HarnessPersistenceValue::decodeJsonArray($event->payloadJson, 'settled.payload');
        if ($event->runId !== $this->runId || $event->eventKey !== 'model-budget:settled:' . $reservation
            || $event->eventType !== 'model.budget.settled' || $event->eventVersion !== 1
            || $event->stepId !== null || $event->attemptId !== null || $event->causationEventId !== null
            || $event->actorType !== 'system' || $event->actorId !== 'model_gateway'
            || $event->severity !== GenerationEventSeverity::INFO || $event->clientVisible
            || ($payload['reservation'] ?? null) !== $reservation
            || !in_array($payload['status'] ?? null, ['failed', 'response_received'], true)
            || !is_bool($payload['reservation_retained'] ?? null)
            || !is_array($payload['usage'] ?? null) || !array_key_exists('provider_usage', $payload)) {
            throw new HarnessPersistenceException('Recorded model settlement identity or payload is malformed.');
        }
        self::assertRecordedUsage($payload['usage']);
        if ($payload['provider_usage'] !== null) {
            if (!is_array($payload['provider_usage'])) {
                throw new HarnessPersistenceException('Recorded provider usage is malformed.');
            }
            self::assertRecordedUsage($payload['provider_usage']);
        }
        if ($payload['status'] === 'failed') {
            HarnessPersistenceValue::string($payload['exception_class'] ?? null, 'settled.exception_class', 1, 500);
        } else {
            HarnessPersistenceValue::identifier($payload['provider'] ?? null, 'settled.provider', 64);
            HarnessPersistenceValue::string($payload['model'] ?? null, 'settled.model', 1, 191);
            HarnessPersistenceValue::identifier($payload['finish_reason'] ?? null, 'settled.finish_reason', 64);
            HarnessPersistenceValue::hash($payload['content_hash'] ?? null, 'settled.content_hash');
        }
    }

    /** @param array<string,mixed> $usage */
    private static function assertRecordedUsage(array $usage): void
    {
        foreach (['input_tokens', 'output_tokens', 'total_tokens', 'cost_micros'] as $field) {
            if (!is_int($usage[$field] ?? null)) {
                throw new HarnessPersistenceException('Recorded model usage is malformed.');
            }
        }
        if (!is_string($usage['source'] ?? null) || !array_key_exists('pricing_hash', $usage)
            || ($usage['pricing_hash'] !== null && !is_string($usage['pricing_hash']))) {
            throw new HarnessPersistenceException('Recorded model usage provenance is malformed.');
        }
        new ModelUsage($usage['input_tokens'], $usage['output_tokens'], $usage['total_tokens'],
            $usage['cost_micros'], $usage['source'], $usage['pricing_hash']);
    }

    private function assertIndependentTransaction(): void
    {
        if ($this->db instanceof PdoHarnessPersistenceDriver && $this->db->inTransaction()) {
            throw new RuntimeControlException(
                'Model budget accounting cannot run inside a caller transaction; the reservation must remain durable.'
            );
        }
    }

    /**
     * Built once per locked transaction; never cached across paid invocations.
     * A compacted closed attempt must still advance the next attempt ordinal,
     * and an archived settlement must remain authoritative for late replays.
     * @param array<string,mixed> $run
     * @return array<string,array<string,mixed>>|null
     */
    private function archivedHistory(array $run, ?string $reservation = null,
        ?TokenBudgetModelAdapter $adapter = null, ?string $operationKey = null): ?array
    {
        if ((int)($run['event_stream_version'] ?? 1) < 2) return null;
        require_once dirname(__DIR__) . '/retention/RetentionEventSegments.php';
        $history = [];
        $reservations = [];
        foreach ($this->events->iterateIncludingArchives($this->runId) as $event) {
            if ($reservation !== null) {
                if (!in_array($event->eventKey, ['model-budget:reserved:' . $reservation,
                    'model-budget:settled:' . $reservation], true)) continue;
            } elseif ($event->eventType === 'model.budget.reserved') {
                $payload = HarnessPersistenceValue::decodeJsonArray($event->payloadJson, 'budget.history_payload');
                if (($payload['operation_key'] ?? null) !== $operationKey
                    || ($payload['provider'] ?? null) !== $adapter?->provider()
                    || ($payload['model'] ?? null) !== $adapter?->model()
                    || ($payload['fence_generation'] ?? null) !== $this->fenceGeneration
                    || ($payload['postprocess_job_id'] ?? null) !== $this->postprocessAuthority?->jobId) continue;
                $reservations[$event->eventKey] = true;
                if (count($reservations) > self::MAX_RESERVATION_ATTEMPTS) {
                    throw new HarnessPersistenceException('Model operation history exceeds its durable attempt budget.');
                }
            } elseif ($event->eventType === 'model.budget.settled') {
                $reservedKey = 'model-budget:reserved:' . substr($event->eventKey, strlen('model-budget:settled:'));
                if (!isset($reservations[$reservedKey])) continue;
            } else {
                continue;
            }
            if (isset($history[$event->eventKey])) throw new HarnessIdempotencyConflictException('Model budget history contains duplicate Event identities.');
            $history[$event->eventKey] = RetentionEventSegments::row($event);
        }
        return $history;
    }

    /** @param array<string,array<string,mixed>>|null $history @return array<string,mixed>|null */
    private function reservationEvent(string $key, ?array $history): ?array
    {
        if ($history !== null) return $history[$key] ?? null;
        return $this->db->fetchOne('SELECT * FROM generation_events WHERE run_id=? AND event_key=?', [$this->runId, $key]);
    }

    /** @return array<string,mixed> */
    private function lockedRun(): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, run_uid, state, cancel_requested, token_budget, cost_budget, input_tokens, output_tokens, cost_total,
                lease_owner, lease_token, fence_generation, event_stream_version,
                (deadline_at IS NULL OR deadline_at>UTC_TIMESTAMP(6)) AS deadline_open,
                (lease_expires_at IS NOT NULL AND lease_expires_at>UTC_TIMESTAMP(6)) AS lease_live
             FROM generation_runs WHERE id=? FOR UPDATE',
            [$this->runId]
        );
        if ($row === null || ($row['run_uid'] ?? null) !== $this->runUid
            || (int)($row['token_budget'] ?? 0) !== $this->budget->tokenLimit) {
            throw new HarnessPersistenceException('Run model budget does not match its frozen durable limits.');
        }
        return $row;
    }

    /** Retired monetary metadata cannot invalidate an otherwise identical legacy replay. */
    private function assertCompatibleReplay(GenerationEventDraft $draft, array $existing): void
    {
        // Authenticate the immutable original before ignoring any retired fields.
        $event = GenerationEvent::fromRow($existing);
        $stored = HarnessPersistenceValue::decodeJsonArray($event->payloadJson, 'budget.replay');
        $current = HarnessPersistenceValue::decodeJsonArray($draft->payloadJson, 'budget.replay');
        $normalize = static function (array $payload): array {
            foreach (['usage', 'provider_usage'] as $key) {
                if (isset($payload[$key]) && is_array($payload[$key])) {
                    unset($payload[$key]['cost_micros'], $payload[$key]['pricing_hash']);
                }
            }
            return $payload;
        };
        if (HarnessPersistenceValue::canonicalMysqlJson($normalize($stored))
            !== HarnessPersistenceValue::canonicalMysqlJson($normalize($current))) {
            throw new HarnessIdempotencyConflictException('Model reservation replay changed immutable token usage or identity.');
        }
        // Compare every Event coordinate with the authenticated original payload/hash.
        (new GenerationEventDraft($draft->runId, $draft->stepId, $draft->attemptId,
            $draft->eventType, $draft->eventVersion, $draft->severity, $draft->eventKey,
            $draft->actorType, $draft->actorId, $draft->causationEventId,
            $draft->clientVisible, $stored))->assertIdempotentRow($existing);
    }

    /** @param array<string,mixed> $payload */
    private function draft(string $status, string $reservation, array $payload): GenerationEventDraft
    {
        return new GenerationEventDraft($this->runId, null, null,
            'model.budget.' . $status, 1, GenerationEventSeverity::INFO,
            'model-budget:' . $status . ':' . $reservation,
            'system', 'model_gateway', null, false, $payload);
    }

    /** @return array<string,int|string|null> */
    private static function usagePayload(ModelUsage $usage): array
    {
        return ['input_tokens' => $usage->inputTokens, 'output_tokens' => $usage->outputTokens,
            'total_tokens' => $usage->totalTokens, 'cost_micros' => 0,
            'source' => $usage->source, 'pricing_hash' => null];
    }
}
