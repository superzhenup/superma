<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__, 2) . '/ai/AIInvocationAccountingScope.php';
// This file declares an adapter implementing the contract below. Import its
// owner directly; an older ledger must not determine whether bootstrap works.
require_once __DIR__ . '/ModelContracts.php';
require_once __DIR__ . '/RunModelBudgetLedger.php';
require_once dirname(__DIR__) . '/runtime/ChapterRunInputSnapshot.php';
require_once dirname(__DIR__) . '/runtime/StageResult.php';

/** Final provider token estimate. No transport or credentials are retained. */
final readonly class ChapterPayloadTokenAdapter implements TokenBudgetModelAdapter
{
    public function __construct(private string $providerName, private string $modelName, private ModelUsage $estimate) {}
    public function provider(): string { return $this->providerName; }
    public function model(): string { return $this->modelName; }
    public function capabilities(): array { return [ModelCapability::TEXT]; }
    public function reservationUsage(ModelRequest $request): ModelUsage { return $this->estimate; }
    public function invoke(ModelRequest $request): ModelResponse
    {
        throw new LogicException('A payload token estimate must never invoke a provider.');
    }
}

final readonly class ChapterAIInvocationTicket
{
    public function __construct(public string $reservation, public string $provider, public string $model) {}
}

/** Bridges every scoped AIClient HTTP request to one durable Run reservation. */
final class ChapterRunAIInvocationAccounting implements AIInvocationAccounting
{
    private ?HarnessPersistenceDriver $db;
    private ?ModelInvocationAccounting $ledger;
    private bool $hasInvocations = false;
    private readonly ?PDO $pdo;
    private int $callOrdinal = 0;

    public function __construct(
        private readonly ChapterRunInputSnapshot $snapshot,
        private readonly int $runId,
        private readonly string $runUid,
        private readonly RunBudget $budget,
        private readonly ?ChapterRunAuthorityFence $authority = null,
        ?ModelInvocationAccounting $ledger = null,
        PDO|HarnessPersistenceDriver|null $connection = null,
        private readonly string $operationKeyPrefix = 'ai-scope:v1'
    ) {
        HarnessPersistenceValue::positiveInt($runId, 'chapter_accounting.run_id');
        HarnessPersistenceValue::uid($runUid, 'chapter_accounting.run_uid');
        HarnessPersistenceValue::asciiString($operationKeyPrefix, 'chapter_accounting.operation_prefix', 1, 150);
        $budget->assertFunded();
        if ($authority !== null && ($authority->runId !== $runId || $authority->runUid !== $runUid)) {
            throw new LeaseLostException('AI accounting authority belongs to another Run.');
        }
        $this->ledger = $ledger;
        $this->pdo = $connection instanceof PDO ? $connection : null;
        $this->db = $connection === null ? null : HarnessPersistenceValue::driver($connection);
    }

    public function beforeRequest(array $modelConfig, array $payload): mixed
    {
        if ($this->pdo?->inTransaction()) {
            throw new HarnessPersistenceException('AI requests must run outside database transactions so paid usage cannot roll back.');
        }
        $this->snapshot->assertModelRowMatches($modelConfig);
        $frozen = null;
        foreach ($this->snapshot->toArray()['models'] as $model) {
            if ((int)$model['id'] === (int)($modelConfig['id'] ?? 0)) {
                $frozen = $model;
                break;
            }
        }
        if (!is_array($frozen) || ($payload['model'] ?? null) !== $frozen['model_name']) {
            throw new ModelBudgetEstimateUnavailableException('Provider payload differs from the frozen model coordinate.');
        }
        $output = HarnessPersistenceValue::boundedInt($payload['max_tokens'] ?? null,
            'chapter_accounting.max_tokens', 1, 16384);
        // Count the FINAL provider envelope, including system text, tools and
        // tool results. Bytes plus framing are conservative for text tokenizers.
        $input = strlen(HarnessPersistenceValue::canonicalJson($payload)) + 4096;
        $estimate = new ModelUsage($input, $output, $input + $output,
            0, 'estimated');
        $request = new ModelRequest([['role' => 'user', 'content' => 'budgeted provider payload']],
            'accounted', maxOutputTokens: $output,
            // Sequential per-instance ordinal: one instance spans exactly one
            // fenced stage/job execution, so the ledger's fence binding makes
            // the durable operation identity unique and rebuildable.
            operationKey: $this->operationKeyPrefix . ':call:' . (++$this->callOrdinal));
        $reservation = $this->ledger()->reserve(new ChapterPayloadTokenAdapter($frozen['provider'], $frozen['model_name'], $estimate), $request);
        $this->hasInvocations = true;
        return new ChapterAIInvocationTicket($reservation, $frozen['provider'], $frozen['model_name']);
    }

    public function succeeded(mixed $ticket, array $usage): void
    {
        $ticket = $this->ticket($ticket);
        $this->ledger()->received($ticket->reservation, new ModelResponse('', [], 'stop',
            $this->modelUsage($usage), $ticket->provider,
            $ticket->model, [ModelCapability::TEXT]));
    }

    public function failed(mixed $ticket, Throwable $error, array $usage): void
    {
        $ticket = $this->ticket($ticket);
        $spent = $this->modelUsage($usage);
        $this->ledger()->failed($ticket->reservation, $spent->totalTokens > 0
            ? new ModelPaidInvocationException($spent, $ticket->provider, $ticket->model, $error)
            : $error);
    }

    public function usage(): ModelUsage
    {
        return (new RunEventStore($this->connection()))->modelUsage($this->runId);
    }

    /** Replace per-feature counts with the uncheckpointed durable total exactly once. */
    public function accountStageResult(StageResult $result, RunBudget $baseline): StageResult
    {
        if (!$result->disposition->checkpointsOutput()) return $result;
        // Direct pure fixtures without a connection or actual HTTP call retain
        // their simulated usage. Production always injects its shared DB driver.
        if ($this->db === null && !$this->hasInvocations) return $result;
        $spent = $this->usage();
        $input = max(0, $spent->inputTokens - $baseline->inputTokens);
        $output = max(0, $spent->outputTokens - $baseline->outputTokens);
        $cost = '0';
        return new StageResult($result->disposition, $result->attributePatch, $result->artifactPatch,
            $input, $output, $cost, $result->errorCode,
            array_replace($result->metadata, ['usage_accounting' => 'durable_run_total_v1']));
    }

    private function connection(): HarnessPersistenceDriver
    {
        if ($this->db !== null) return $this->db;
        $pdo = DB::getPdo();
        if ($pdo->inTransaction()) {
            throw new HarnessPersistenceException('AI accounting cannot start inside an application transaction.');
        }
        return $this->db = HarnessPersistenceValue::driver($pdo);
    }

    private function ledger(): ModelInvocationAccounting
    {
        if ($this->ledger !== null) return $this->ledger;
        $fence = $this->authority ?? throw new LeaseLostException('Chapter AI request has no bound authority fence.');
        return $this->ledger = new RunModelBudgetLedger($this->connection(), $this->runId, $this->runUid,
            $this->budget, $fence->leaseOwner, $fence->leaseToken, $fence->fenceGeneration);
    }

    private function ticket(mixed $ticket): ChapterAIInvocationTicket
    {
        if (!$ticket instanceof ChapterAIInvocationTicket) {
            throw new HarnessPersistenceException('Chapter AI reservation ticket is invalid.');
        }
        return $ticket;
    }

    private function modelUsage(array $raw): ModelUsage
    {
        $input = max(0, (int)($raw['prompt_tokens'] ?? $raw['input_tokens'] ?? 0));
        $output = max(0, (int)($raw['completion_tokens'] ?? $raw['output_tokens'] ?? 0));
        $total = max($input + $output, (int)($raw['total_tokens'] ?? 0));
        // Include any unexplained total usage on the more conservative output side.
        $output = max($output, $total - $input);
        $source = ($raw['source'] ?? 'unknown') === 'provider' ? 'provider' : 'unknown';
        return new ModelUsage($input, $output, $input + $output,
            0, $source);
    }
}
