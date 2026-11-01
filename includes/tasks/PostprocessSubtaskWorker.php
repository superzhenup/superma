<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostprocessSubtaskRepository.php';
require_once __DIR__ . '/PostprocessAnalysisTasks.php';
require_once __DIR__ . '/PostprocessConcurrencySlots.php';
require_once dirname(__DIR__) . '/harness/model/PostprocessModelBudgetScope.php';

/** One bounded sidecar computes candidates; it never acquires the novel projection fence. */
final class PostprocessSubtaskWorker
{
    public static function runOne(int $id, ?callable $parentHeartbeat = null): bool
    {
        $task = PostprocessSubtaskRepository::claimById($id, 'postprocess-compute:' . getmypid());
        if ($task === null) return false;
        $slot = null;
        $lastHeartbeat = 0.0;
        $lost = null;
        $heartbeat = static function () use ($task, $parentHeartbeat, &$lastHeartbeat): void {
            if ($parentHeartbeat !== null) $parentHeartbeat();
            if (HarnessRuntime::preciseTime() - $lastHeartbeat >= 10) {
                PostprocessSubtaskRepository::heartbeat($task);
                $lastHeartbeat = HarnessRuntime::preciseTime();
            }
        };
        $previousHeartbeat = $GLOBALS['sendHeartbeat'] ?? null;
        try {
            $slot = PostprocessConcurrencySlots::acquire($heartbeat);
            $input = PostprocessSubtaskRepository::decodeInput($task);
            $snapshot = ChapterRunInputSnapshot::fromArray($input['_input_snapshot'] ?? []);
            $snapshot->assertScope((int)$task['novel_id'], (int)$task['chapter_id']);
            if (!hash_equals((string)$task['revision_hash'], hash('sha256', (string)($input['content'] ?? '')))) {
                throw new RuntimeException('Computation body does not match its accepted revision.');
            }
            // cURL callbacks only record failure; AIClient aborts outside the C callback.
            $GLOBALS['sendHeartbeat'] = static function () use ($heartbeat, &$lost): void {
                try { $heartbeat(); } catch (Throwable $error) { $lost = $error; }
            };
            if ($parentHeartbeat === null) AIClient::setFatalAbortProbe(static function () use (&$lost): bool { return $lost !== null; });
            $parent = PostprocessJobRepository::findById((int)$task['parent_job_id']);
            if ($parent === null) throw new WriteEnginePostprocessLeaseLostException('Computation parent disappeared.');
            $result = ChapterRunFrozenConfigScope::run($snapshot, static fn(): array =>
                PostprocessModelBudgetScope::run(DB::getPdo(), $parent,
                    static fn(): array => PostprocessAnalysisTasks::compute((string)$task['task_type'], $input), $task));
            if ($lost !== null) throw $lost;
            $heartbeat();
            if (!PostprocessSubtaskRepository::complete($task, $result)) {
                throw new WriteEnginePostprocessLeaseLostException('Computation completion lost its lease.');
            }
            return true;
        } catch (Throwable $error) {
            try { PostprocessSubtaskRepository::fail($task, $error); }
            catch (Throwable $leaseError) { error_log('[postprocess-compute] failure settlement: ' . $leaseError->getMessage()); }
            error_log('[postprocess-compute:' . $id . '] ' . $error->getMessage());
            return false;
        } finally {
            if ($previousHeartbeat === null) unset($GLOBALS['sendHeartbeat']);
            else $GLOBALS['sendHeartbeat'] = $previousHeartbeat;
            if ($parentHeartbeat === null) AIClient::setFatalAbortProbe(null);
            PostprocessConcurrencySlots::release($slot);
        }
    }
}
