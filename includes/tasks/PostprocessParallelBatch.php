<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostprocessSubtaskWorker.php';
require_once dirname(__DIR__) . '/story_outline/StoryOutlineWorkerLauncher.php';
require_once dirname(__DIR__) . '/agents/AgentCoordinator.php';

final class PostprocessComputationFailedException extends RuntimeException {}
final class PostprocessPublicationFailedException extends RuntimeException {}

/** One sidecar plus the original coordinator: at most two computations per chapter. */
final class PostprocessParallelBatch
{
    private array $tasks = [];
    private mixed $process = null;
    private ?int $processPid = null;
    private ?string $slot = null;
    private bool $closed = false;
    private bool $serialRetries = false;

    private function __construct(private array $parent, private mixed $heartbeat) {}

    public static function start(array $context, array $novel, array $chapter, string $content,
        ?callable $heartbeat): ?self
    {
        $jobId = (int)($context['job_id'] ?? 0);
        if ($jobId < 1) return null;
        $enabled = getSystemSetting('ws_postprocess_parallel_enabled', false, 'bool');
        $admitted = PostprocessSubtaskRepository::hasTasks($jobId);
        if (!$enabled && !$admitted) return null;
        $cooldown = PostprocessSubtaskRepository::hasRecentRateLimit();
        if (!$admitted && $cooldown) return null;
        $parent = PostprocessJobRepository::findById((int)$context['job_id']);
        if ($parent === null) throw new WriteEnginePostprocessLeaseLostException('Parallel parent disappeared.');
        $lease = PostprocessJobRepository::leaseIdentityFromRow($parent);
        if ($lease['owner'] !== ($context['lease_owner'] ?? null)
            || $lease['token'] !== ($context['lease_token'] ?? null)
            || $lease['generation'] !== (int)($context['fence_generation'] ?? 0)) {
            throw new WriteEnginePostprocessLeaseLostException('Parallel coordinator lost its parent lease.');
        }
        // A Rewrite successor has a different Candidate-owned parent. Keep that
        // existing path serial in v1 rather than borrowing its predecessor lease.
        if (!hash_equals((string)$parent['revision_hash'], hash('sha256', $content))
            || ($parent['accepted_candidate_uid'] ?? null) !== ($chapter['canonical_candidate_uid'] ?? null)) return null;

        // A disabled flag stops new admission, while retries retain their old
        // applied markers and input instead of repeating legacy KB increments.
        if ($admitted) {
            $batch = new self($parent, $heartbeat);
            $batch->serialRetries = $cooldown || !$enabled;
            try {
                $batch->slot = PostprocessConcurrencySlots::acquire($heartbeat);
                $batch->tasks = PostprocessSubtaskRepository::resumeTasks($parent);
                if (!$batch->serialRetries) $batch->launch();
                return $batch;
            } catch (Throwable $error) {
                $batch->close();
                throw $error;
            }
        }

        $inputs = [];
        if (getSystemSetting('ws_embedding_async', true, 'bool')) {
            $inputs['knowledge'] = ['novel_id' => (int)$novel['id'],
                'chapter_number' => (int)$chapter['chapter_number'], 'content' => $content];
        }
        $critic = AgentCoordinator::freezeCriticInput((int)$novel['id'], $chapter, $content, $novel);
        if ($critic !== null) $inputs['critic'] = $critic;
        if ($inputs === []) return null;
        $run = PostprocessModelBudgetAuthority::resolveRun(HarnessPersistenceValue::driver(DB::getPdo()), $parent);
        if ($run !== null) {
            $flags = json_decode((string)$run['flag_snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
            $snapshot = ChapterRunInputSnapshot::fromArray($flags['input_snapshot'] ?? []);
            if (!hash_equals($snapshot->checksum, (string)($flags['input_snapshot_checksum'] ?? ''))) {
                throw new RuntimeControlException('Parallel model snapshot checksum mismatch.');
            }
        } else {
            $snapshot = ChapterRunInputSnapshotFactory::capture(
                DB::fetch('SELECT * FROM novels WHERE id=?', [(int)$novel['id']]) ?? $novel, $chapter);
        }
        $batch = new self($parent, $heartbeat);
        try {
            $batch->slot = PostprocessConcurrencySlots::acquire($heartbeat);
            foreach ($inputs as $type => $input) {
                $input['_input_snapshot'] = $snapshot->toArray();
                $batch->tasks[$type] = PostprocessSubtaskRepository::enqueue($parent, $type, $input);
            }
            $batch->launch();
            return $batch;
        } catch (Throwable $error) {
            $batch->close();
            throw $error;
        }
    }

    public function has(string $type): bool { return isset($this->tasks[$type]); }

    /** Publishing effects and their applied marker share one short transaction. */
    public function apply(string $type, callable $publish): mixed
    {
        try { $task = $this->await($type); }
        catch (PostprocessComputationFailedException|WriteEnginePostprocessLeaseLostException $error) { throw $error; }
        catch (Throwable $error) {
            throw new PostprocessPublicationFailedException($type . ' 结果读取失败：' . $error->getMessage(), 0, $error);
        }
        $pdo = null;
        $ownTransaction = false;
        try {
            $result = PostprocessSubtaskRepository::decodeResult($task);
            $pdo = DB::getPdo();
            if ($pdo->inTransaction()) throw new LogicException('Computation publication cannot borrow a transaction.');
            if (!$pdo->beginTransaction()) throw new RuntimeException('Computation publication could not begin.');
            $ownTransaction = true;
            $value = PostprocessSubtaskRepository::markApplied($task) ? $publish($result) : null;
            if (!$pdo->commit()) throw new RuntimeException('Computation publication commit failed.');
            return $value;
        } catch (Throwable $error) {
            try {
                if ($ownTransaction && $pdo !== null && $pdo->inTransaction()) $pdo->rollBack();
            } catch (Throwable $rollbackError) {
                throw new PostprocessPublicationFailedException($type . ' 发布回滚失败：' . $rollbackError->getMessage(), 0, $error);
            }
            if ($error instanceof WriteEnginePostprocessLeaseLostException) throw $error;
            throw new PostprocessPublicationFailedException($type . ' 结果发布失败：' . $error->getMessage(), 0, $error);
        }
    }

    private function await(string $type): array
    {
        if (!$this->has($type)) throw new InvalidArgumentException('Unknown computation type.');
        // A coordinator waiting for its sidecar must not occupy the last slot.
        PostprocessConcurrencySlots::release($this->slot);
        $this->slot = null;
        $started = HarnessRuntime::preciseTime();
        try {
            while (HarnessRuntime::preciseTime() - $started < 1500) {
                if ($this->heartbeat !== null) ($this->heartbeat)();
                $this->checkProcess();
                $task = PostprocessSubtaskRepository::findById((int)$this->tasks[$type]['id']);
                if ($task === null) throw new WriteEnginePostprocessLeaseLostException('Computation was invalidated.');
                PostprocessSubtaskRepository::assertBinding($task, $this->parent);
                PostprocessSubtaskRepository::assertParentLive($task);
                if ($task['state'] === 'done') return $task;
                if ($task['state'] === 'failed') {
                    throw new PostprocessComputationFailedException($type . ' 计算重试失败：' . (string)$task['last_error']);
                }
                // Launch failure, a completed sidecar or an available retry uses
                // the identical durable compute path inline, never an extra AI call.
                if ($task['state'] === 'retry'
                    && preg_match('/\b429\b|rate.?limit|too many requests/iu', (string)$task['last_error'])) {
                    $this->serialRetries = true;
                }
                if ($this->process === null || ($task['state'] === 'retry' && !$this->serialRetries)) {
                    PostprocessSubtaskWorker::runOne((int)$task['id'], $this->heartbeat);
                }
                HarnessRuntime::sleepMicroseconds(250000);
            }
            throw new PostprocessComputationFailedException($type . ' 计算等待超时');
        } finally {
            $this->slot = PostprocessConcurrencySlots::acquire($this->heartbeat);
        }
    }

    private function launch(): void
    {
        $ids = array_map(static fn(array $task): int => (int)$task['id'],
            array_filter($this->tasks, static fn(array $task): bool => $task['state'] !== 'done'));
        if ($ids === [] || !function_exists('proc_open')) return;
        $root = dirname(__DIR__, 2);
        $directory = defined('CFG_PROGRESS_DIR') ? CFG_PROGRESS_DIR : $root . '/storage/write_progress';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) return;
        $log = $directory . '/postprocess-compute-' . (int)$this->parent['id'] . '.log';
        $output = @fopen($log, 'ab');
        if ($output === false) return;
        try {
            $pipes = [];
            $this->process = @proc_open([StoryOutlineWorkerLauncher::resolvePhpCli(),
                $root . '/bin/postprocess_subtask_worker.php', '--tasks=' . implode(',', $ids)],
                [0 => ['pipe', 'r'], 1 => $output, 2 => $output], $pipes, $root, null,
                ['bypass_shell' => true, 'create_new_console' => false]);
            foreach ($pipes as $pipe) fclose($pipe);
            if (!is_resource($this->process)) { $this->process = null; return; }
            $status = proc_get_status($this->process);
            $this->processPid = (int)$status['pid'];
        } catch (Throwable $error) {
            error_log('[postprocess-parallel] sidecar unavailable: ' . $error->getMessage());
            $this->process = null;
        } finally { fclose($output); }
    }

    private function checkProcess(): void
    {
        if (!is_resource($this->process)) { $this->process = null; return; }
        if (proc_get_status($this->process)['running']) return;
        proc_close($this->process);
        $this->process = null;
        if ($this->processPid !== null) PostprocessSubtaskRepository::recoverExitedWorker($this->parent, $this->processPid);
    }

    public function close(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        try {
            if (is_resource($this->process)) {
                if (proc_get_status($this->process)['running']) proc_terminate($this->process);
                proc_close($this->process);
            }
        } finally {
            $this->process = null;
            PostprocessConcurrencySlots::release($this->slot);
            $this->slot = null;
        }
    }
}
