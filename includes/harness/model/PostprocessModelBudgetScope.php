<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ChapterRunAIInvocationAccounting.php';

/** Restores a chapter's existing budget for an independently claimed projection job. */
final class PostprocessModelBudgetScope
{
    public static function run(PDO|HarnessPersistenceDriver $connection, array $job, callable $callback, ?array $subtask = null): mixed
    {
        AIInvocationAccountingScope::assertHealthy();
        $db = HarnessPersistenceValue::driver($connection);
        $authority = null;
        if ($subtask !== null) {
            $authority = new PostprocessModelBudgetAuthority(
                (int)$job['id'],(string)$job['job_uid'],(int)$job['novel_id'],(int)$job['chapter_id'],
                (string)$job['lease_owner'],(string)$job['lease_token'],(int)$job['fence_generation'],$subtask
            );
            $authority->assertLive($db);
        }
        $run = PostprocessModelBudgetAuthority::resolveRun($db, $job);
        if ($run === null) {
            // Historical off/shadow jobs do not have an authority Run budget.
            // An enclosing scope, when present, remains active for these calls.
            $result = $callback();
            AIInvocationAccountingScope::assertHealthy();
            return $result;
        }
        $flags = json_decode((string)($run['flag_snapshot_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($flags) || !is_array($flags['input_snapshot'] ?? null)) {
            throw new RuntimeControlException('Post-processing Run has no frozen model snapshot.');
        }
        $snapshot = ChapterRunInputSnapshot::fromArray($flags['input_snapshot']);
        if (!is_string($flags['input_snapshot_checksum'] ?? null)
            || !hash_equals($snapshot->checksum, $flags['input_snapshot_checksum'])) {
            throw new RuntimeControlException('Post-processing Run model snapshot checksum mismatch.');
        }
        $snapshot->assertScope((int)$job['novel_id'], (int)$job['chapter_id']);
        $budget = new RunBudget((int)$run['token_budget'], (string)$run['cost_budget']);
        $authority ??= new PostprocessModelBudgetAuthority(
            (int)$job['id'], (string)$job['job_uid'], (int)$job['novel_id'], (int)$job['chapter_id'],
            (string)$job['lease_owner'], (string)$job['lease_token'], (int)$job['fence_generation']
        );
        $ledger = new RunModelBudgetLedger($connection, (int)$run['id'], (string)$run['run_uid'], $budget,
            $authority->leaseOwner, $authority->leaseToken, $authority->fenceGeneration, $authority);
        $accounting = new ChapterRunAIInvocationAccounting($snapshot, (int)$run['id'], (string)$run['run_uid'],
            $budget, null, $ledger, $connection,
            $subtask === null ? 'ai-scope:v1' : 'postprocess-subtask:v1:'.$subtask['task_uid'].':'.$subtask['fence_generation']);
        return ChapterRunFrozenConfigScope::run($snapshot,
            static fn (): mixed => AIInvocationAccountingScope::run($accounting, $callback));
    }
}
