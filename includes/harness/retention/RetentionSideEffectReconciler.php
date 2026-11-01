<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionContracts.php';
require_once dirname(__DIR__) . '/model/RunModelBudgetLedger.php';
require_once dirname(__DIR__) . '/runtime/RunBudget.php';

/** Evidence class recorded on settlements produced by the Retention reconciler. */
final class RetentionSideEffectAbandonedException extends RuntimeException
{
    public function __construct(string $what)
    {
        parent::__construct(
            'Retention reconciliation abandoned an unsettled side effect: ' . $what
        );
    }
}

/**
 * Explicitly closes the side effects that block a terminal Run's Retention
 * archive (专项十 P2.2): outstanding model-budget reservations and Attempts
 * with unknown settlement. The dedicated Retention CLI applies this after a
 * grace window. A late settlement that wins the Run lock is preserved and the
 * conservative budget semantics are preserved:
 *
 *   - a reservation is settled RETAINED: the upper estimate stays charged
 *     and reservation_retained=true, exactly like an unknown provider
 *     outcome inside RunModelBudgetLedger;
 *   - an Attempt is marked failed at its own reservation estimate; the
 *     estimate enters the durable Run usage, matching the admission math
 *     that already counted the pending reservation against the budget.
 */
final class RetentionSideEffectReconciler
{
    private HarnessPersistenceDriver $db;
    private Closure $now;

    public function __construct(PDO|HarnessPersistenceDriver $connection, ?callable $now = null)
    {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->now = $now === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : Closure::fromCallable($now);
    }

    /**
     * Dry-run census of the side effects that would be abandoned after the
     * grace window on terminal Runs.
     *
     * @return array{reservations:int,attempts:int}
     */
    public function report(int $graceSeconds, int $limit): array
    {
        $graceSeconds = self::graceSeconds($graceSeconds);
        $limit = self::limit($limit);
        $cutoff = self::graceCutoff(($this->now)(), $graceSeconds);
        return [
            'reservations' => count($this->outstandingReservations($cutoff, $limit)),
            'attempts' => count($this->unsettledAttempts($cutoff, $limit)),
        ];
    }

    /**
     * @return array{
     *     abandoned_reservations:int,
     *     abandoned_attempts:int,
     *     run_ids:list<int>
     * }
     */
    public function apply(int $graceSeconds, int $limit): array
    {
        $graceSeconds = self::graceSeconds($graceSeconds);
        $limit = self::limit($limit);
        $cutoff = self::graceCutoff(($this->now)(), $graceSeconds);

        $abandonedReservations = 0;
        $abandonedAttempts = 0;
        $runIds = [];
        foreach ($this->outstandingReservations($cutoff, $limit) as $row) {
            if ($this->abandonReservation($row)) {
                $abandonedReservations++;
                $runIds[(int)$row['run_id']] = true;
            }
        }
        foreach ($this->unsettledAttempts($cutoff, $limit) as $row) {
            if ($this->abandonAttempt($row, $cutoff)) {
                $abandonedAttempts++;
                $runIds[$this->attemptRunId($row)] = true;
            }
        }
        ksort($runIds, SORT_NUMERIC);
        return [
            'abandoned_reservations' => $abandonedReservations,
            'abandoned_attempts' => $abandonedAttempts,
            'run_ids' => array_map('intval', array_keys($runIds)),
        ];
    }

    /**
     * Settles one outstanding model reservation through the real ledger's
     * retained-estimate path. Settlement is deliberately lease-free (a late
     * paid response must settle without owning the lease). The Event keeps the
     * ledger's system/model_gateway actor coordinates and identifies this
     * reconciliation through RetentionSideEffectAbandonedException evidence.
     *
     * @param array<string,mixed> $reservedEventRow run_id, event_key, payload_json
     */
    public function abandonReservation(array $reservedEventRow): bool
    {
        $runId = HarnessPersistenceValue::positiveInt(
            $reservedEventRow['run_id'] ?? null,
            'reconcile.reservation_run_id'
        );
        $eventKey = HarnessPersistenceValue::asciiString(
            $reservedEventRow['event_key'] ?? null,
            'reconcile.reservation_event_key',
            1,
            191
        );
        $payload = HarnessPersistenceValue::decodeJsonArray(
            $reservedEventRow['payload_json'] ?? null,
            'reconcile.reservation_payload'
        );
        $reservation = HarnessPersistenceValue::uid(
            $payload['reservation'] ?? null,
            'reconcile.reservation_token'
        );
        if (!hash_equals('model-budget:reserved:' . $reservation, $eventKey)) {
            throw new HarnessPersistenceException(
                'Reservation Event key does not match its payload token.'
            );
        }
        $fenceGeneration = HarnessPersistenceValue::positiveInt(
            $payload['fence_generation'] ?? null,
            'reconcile.reservation_fence'
        );
        $run = $this->db->fetchOne(
            'SELECT run_uid, token_budget, cost_budget, state
             FROM generation_runs WHERE id=?',
            [$runId]
        );
        if ($run === null || !in_array(
            (string)($run['state'] ?? ''),
            ['succeeded', 'aborted_after_commit', 'failed', 'canceled'],
            true
        )) {
            // Not reconcilable: a live Run keeps its own settlement authority.
            throw new HarnessPersistenceException(
                'Reservation Run is missing or not terminal.'
            );
        }
        $runUid = HarnessPersistenceValue::uid($run['run_uid'] ?? null, 'reconcile.run_uid');
        $budget = new RunBudget(
            (int)$run['token_budget'],
            (string)$run['cost_budget']
        );
        $ledger = new RunModelBudgetLedger(
            $this->db,
            $runId,
            $runUid,
            $budget,
            'retention-reconciler',
            substr(hash('sha256', 'retention-reconciler:' . $runUid), 0, 32),
            $fenceGeneration
        );
        return $ledger->abandonIfOutstanding(
            $reservation,
            new RetentionSideEffectAbandonedException('model budget reservation ' . $reservation)
        );
    }

    /**
     * Closes one unsettled Attempt at its reservation estimate. Returns false
     * when a concurrent settlement won the race (idempotent no-op).
     *
     * @param array<string,mixed> $attemptRow id, step_id, attempt_no
     */
    public function abandonAttempt(array $attemptRow, ?DateTimeImmutable $cutoff = null): bool
    {
        $attemptId = HarnessPersistenceValue::positiveInt(
            $attemptRow['id'] ?? null,
            'reconcile.attempt_id'
        );
        $stepId = HarnessPersistenceValue::positiveInt(
            $attemptRow['step_id'] ?? null,
            'reconcile.step_id'
        );
        return $this->db->transactional(function () use ($attemptId, $stepId, $cutoff): bool {
            // Same lock order as the story-outline settlement (run, then
            // attempt) so reconciliation never deadlocks a late settle.
            $step = $this->db->fetchOne(
                'SELECT id, run_id FROM generation_steps WHERE id=?',
                [$stepId]
            );
            if ($step === null) {
                throw new HarnessPersistenceException('Reconciled Attempt Step is missing.');
            }
            $runId = HarnessPersistenceValue::positiveInt($step['run_id'] ?? null, 'reconcile.step_run_id');
            $run = $this->db->fetchOne(
                'SELECT id, state FROM generation_runs WHERE id=? FOR UPDATE',
                [$runId]
            );
            if ($run === null || !in_array(
                (string)($run['state'] ?? ''),
                ['succeeded', 'aborted_after_commit', 'failed', 'canceled'],
                true
            )) {
                return false;
            }
            $attempt = $this->db->fetchOne(
                'SELECT * FROM generation_attempts WHERE id=? FOR UPDATE',
                [$attemptId]
            );
            if ($attempt === null
                || (($attempt['state'] ?? '') !== 'running'
                    && ($attempt['error_code'] ?? '') !== 'usage_unknown')) {
                return false;
            }
            if ($cutoff !== null) {
                // The scan is only a candidate list. A partial late response
                // can refresh completed_at while retaining usage_unknown.
                $lastObserved = HarnessPersistenceValue::optionalMysqlDate(
                    $attempt['completed_at'] ?? $attempt['started_at'] ?? null,
                    'reconcile.attempt_last_observed'
                );
                if ($lastObserved === null
                    || new DateTimeImmutable($lastObserved, new DateTimeZone('UTC')) >= $cutoff) {
                    return false;
                }
            }
            $attemptNo = HarnessPersistenceValue::positiveInt(
                $attempt['attempt_no'] ?? null,
                'reconcile.attempt_no'
            );
            $configJson = $attempt['model_config_json'] ?? null;
            $config = is_string($configJson) && $configJson !== ''
                ? HarnessPersistenceValue::decodeJsonArray($configJson, 'reconcile.attempt_config')
                : [];
            $recordedInput = HarnessPersistenceValue::nonNegativeInt($attempt['input_tokens'] ?? null,
                'reconcile.attempt_input_tokens');
            $recordedOutput = HarnessPersistenceValue::nonNegativeInt($attempt['output_tokens'] ?? null,
                'reconcile.attempt_output_tokens');
            $recordedCost = (string)($attempt['cost'] ?? '0'); // Preserve historical metadata only.
            $targetInput = $recordedInput;
            $targetOutput = $recordedOutput;
            
            $reservation = $config['reservation'] ?? null;
            if (array_key_exists('reservation', $config)) {
                if (!is_array($reservation)) {
                    throw new HarnessPersistenceException('Attempt reservation is malformed.');
                }
                $targetInput = max($recordedInput, HarnessPersistenceValue::nonNegativeInt(
                    $reservation['input_tokens'] ?? null, 'reconcile.reserved_input_tokens'));
                $targetOutput = max($recordedOutput, HarnessPersistenceValue::nonNegativeInt(
                    $reservation['output_tokens'] ?? null, 'reconcile.reserved_output_tokens'));

            }

            // Partial provider usage was already added by settleAttempt. Top up
            // to the conservative bound once, without reducing known usage.
            if ($targetInput > $recordedInput || $targetOutput > $recordedOutput) {
                $charged = $this->db->execute(
                    'UPDATE generation_runs
                     SET input_tokens=input_tokens+?, output_tokens=output_tokens+?,
                         cost_total=cost_total+?
                     WHERE id=?',
                    [
                        $targetInput - $recordedInput,
                        $targetOutput - $recordedOutput,
                        '0',
                        $runId,
                    ]
                );
                if ($charged !== 1) {
                    throw new HarnessPersistenceException(
                        'Reconciled Attempt estimate charge lost its Run row.'
                    );
                }
            }
            $closed = $this->db->execute(
                "UPDATE generation_attempts
                 SET state='failed',
                     input_tokens=?,
                     output_tokens=?,
                     cost=?,
                     error_code='abandoned_usage_unknown',
                     error_message='Retention reconciliation closed an unsettled Attempt at its reservation estimate.',
                     completed_at=?
                 WHERE id=? AND (state='running' OR error_code='usage_unknown')",
                [
                    $targetInput,
                    $targetOutput,
                    $recordedCost,
                    HarnessPersistenceValue::mysqlDate(($this->now)()),
                    $attemptId,
                ]
            );
            if ($closed !== 1) {
                // A concurrent settlement won between the lock re-read and
                // the CAS; surface it as a loud inconsistency instead of a
                // silent skip because both rows were already locked.
                throw new HarnessPersistenceException(
                    'Reconciled Attempt lost its fenced CAS closure.'
                );
            }
            (new RunEventStore($this->db))->append(new GenerationEventDraft(
                $runId,
                $stepId,
                $attemptId,
                'attempt.failed',
                1,
                GenerationEventSeverity::WARNING,
                'reconcile-attempt:failed:' . $attemptId . ':abandoned_usage_unknown',
                'system',
                null,
                null,
                false,
                [
                    'state' => 'failed',
                    'attempt_no' => $attemptNo,
                    'classification' => 'abandoned_usage_unknown',
                ]
            ));
            return true;
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function outstandingReservations(DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.run_id, e.event_key, e.payload_json, e.created_at
             FROM generation_events e
             INNER JOIN generation_runs r ON r.id=e.run_id
             WHERE r.state IN ('succeeded','aborted_after_commit','failed','canceled')
               AND e.event_type='model.budget.reserved'
               AND e.created_at<?
               AND NOT EXISTS (
                   SELECT 1 FROM generation_events s
                    WHERE s.run_id=e.run_id
                      AND s.event_key=CONCAT('model-budget:settled:',
                                             SUBSTRING(e.event_key, 23))
               )
             ORDER BY e.id LIMIT {$limit}",
            [HarnessPersistenceValue::mysqlDate($cutoff)]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function unsettledAttempts(DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT a.id, a.step_id, s.run_id, a.attempt_no, a.state, a.error_code,
                    a.model_config_json
             FROM generation_attempts a
             INNER JOIN generation_steps s ON s.id=a.step_id
             INNER JOIN generation_runs r ON r.id=s.run_id
             WHERE r.state IN ('succeeded','aborted_after_commit','failed','canceled')
               AND (a.state='running' OR a.error_code='usage_unknown')
               AND COALESCE(a.completed_at, a.started_at)<?
             ORDER BY s.run_id, a.id LIMIT {$limit}",
            [HarnessPersistenceValue::mysqlDate($cutoff)]
        );
    }

    /** @param array<string,mixed> $attemptRow */
    private function attemptRunId(array $attemptRow): int
    {
        return HarnessPersistenceValue::positiveInt(
            $attemptRow['run_id'] ?? null,
            'reconcile.report_run_id'
        );
    }

    private static function graceSeconds(int $graceSeconds): int
    {
        return HarnessPersistenceValue::boundedInt(
            $graceSeconds,
            'reconcile.grace_seconds',
            3600,
            2592000
        );
    }

    private static function limit(int $limit): int
    {
        return HarnessPersistenceValue::boundedInt($limit, 'reconcile.limit', 1, 1000);
    }

    private static function graceCutoff(DateTimeImmutable $now, int $graceSeconds): DateTimeImmutable
    {
        return $now->modify("-{$graceSeconds} seconds");
    }
}
