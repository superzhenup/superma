<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/WorkflowRunner.php';
require_once __DIR__ . '/ChapterRunService.php';
require_once __DIR__ . '/ChapterAuthorityExecutionFactory.php';
require_once dirname(__DIR__) . '/support/HarnessRuntime.php';

/**
 * 专项一（2026-09-27）：三入口（异步 Worker / SSE 直连 / Daemon 挂机）的
 * authority Chapter Run 编排收敛为一份公共编排器。
 *
 * 编排器固定持有并负责：
 *  - ChapterRunInvocation::forEntryPoint 的统一组装；
 *  - ChapterAuthorityExecutionFactory（leaseSeconds=180）+ chapterRuns 服务；
 *  - restoreChapter 恢复闭包的组装（认证回调与文案由入口注入）；
 *  - makeAdapter + runToTerminal 的调用顺序；
 *  - RETRY_WAIT 等待循环骨架（250ms tick + 入口钩子）；
 *  - 终态映射（SUCCEEDED / QUARANTINED / CANCELED / FAILED 四分支 +
 *    content_saved 判定），收尾动作经 WriteTermination 端口交还入口。
 *
 * 入口继续持有：
 *  - 冻结快照捕获（策略 / 特性 / 输入三快照）与 authority 分岔判定；
 *  - LegacyChapterTransport 回调集合（进度文件 / SSE 输出流 / 锁文件+日志）；
 *  - acquire/restore 认证闭包（任务租约或 claim token 安全语义）；
 *  - 终态收尾（worker 的 exit 码与进度终态、SSE 的 [DONE] 输出、
 *    daemon 的类型化异常转译）。
 */
final class ChapterWriteOrchestrator
{
    public function __construct(
        private readonly PDO|HarnessPersistenceDriver $connection
    ) {
    }

    /**
     * 运行一次 authority Chapter Run 至终态并执行终态映射。
     *
     * 返回值仅供入口在 termination 回调既未 exit 也未抛出异常时继续
     * 消费（例如 daemon 用返回结果构造外层 writeResult）。worker/SSE 的
     * termination 回调内部 exit，本方法不会返回。
     *
     * 注意：编排器不捕获 runToTerminal 抛出的异常——入口保留各自的
     * authority 启动失败 catch（worker/SSE 映射为友好错误，daemon 依赖
     * 外层统一异常处理做 Canon 恢复 / 取消 / 重试决策）。
     */
    public function run(ChapterWriteRunRequest $request): WorkflowExecutionResult
    {
        $invocation = ChapterRunInvocation::forEntryPoint(
            entryPoint: $request->entryPoint,
            userId: $request->userId,
            novelId: $request->novelId,
            chapterId: $request->chapterId,
            authorityIdentity: $request->authorityIdentity,
            policySnapshot: $request->policySnapshot,
            featureSnapshot: $request->featureSnapshot,
            inputSnapshot: $request->inputSnapshot,
            budget: RunBudget::forChapter(),
            workerId: $request->workerId,
            baseSnapshot: $request->baseSnapshot,
            goal: $request->goal
        );

        $authority = new ChapterAuthorityExecutionFactory(
            $this->connection,
            leaseSeconds: 180
        );
        $service = $authority->chapterRuns();
        $restore = $authority->restoreChapter(
            $request->novelId,
            $request->restoreBase,
            $request->restoreVerifier,
            $request->restoreMissingMessage,
            $request->restoreInactiveMessage
        );
        $makeAdapter = static function () use ($authority, $request, $restore): LegacyWriteEngineStageAdapter {
            return $authority->adapter(
                policySnapshot: $request->policySnapshot,
                featureSnapshot: $request->featureSnapshot,
                inputSnapshot: $request->inputSnapshot,
                transport: $request->transport,
                acquireChapter: static function (RunContext $context) use ($request, $restore): array {
                    return ($request->acquireChapter)($restore, $context);
                },
                restoreChapter: $restore,
                postprocessWorkerId: $request->postprocessWorkerId,
                writingTaskId: $request->writingTaskId,
                writingTaskLease: $request->writingTaskLease,
                legacyCancellationProbe: $request->legacyCancellationProbe,
                waitingCallback: $request->waitingCallback
            );
        };

        $onRetryScheduled = $request->onRetryScheduled;
        $retryTick = $request->retryTick;
        if ($retryTick !== null) {
            $runUid = $invocation->runUid();
            $retryTick = static function (WorkflowExecutionResult $retryResult) use ($retryTick, $runUid): ?WorkflowExecutionResult {
                return $retryTick($runUid, $retryResult);
            };
        }
        $onRetryWait = static function (
            DateTimeImmutable $retryAt,
            WorkflowExecutionResult $retryResult
        ) use ($onRetryScheduled, $retryTick): ?WorkflowExecutionResult {
            if ($onRetryScheduled !== null) {
                $onRetryScheduled($retryResult);
            }
            while (new DateTimeImmutable('now', new DateTimeZone('UTC')) < $retryAt) {
                if ($retryTick !== null) {
                    $early = $retryTick($retryResult);
                    if ($early !== null) {
                        return $early;
                    }
                }
                HarnessRuntime::sleepMicroseconds(250000);
            }
            return null;
        };

        $result = $service->runToTerminal($invocation, $makeAdapter, $onRetryWait);

        if ($request->postRun !== null) {
            ($request->postRun)($result);
        }

        $contentSaved = isset($result->context->attributes['committed_revision_hash']);

        if ($result->state === GenerationRunState::SUCCEEDED) {
            $request->termination->succeeded($result, $contentSaved);
        } elseif ($result->state === GenerationRunState::QUARANTINED) {
            $request->termination->quarantined($result, $contentSaved);
        } elseif ($result->state === GenerationRunState::CANCELED) {
            $request->termination->canceled($result, $contentSaved);
        } else {
            $request->termination->failed($result, $contentSaved);
        }
        return $result;
    }
}

/**
 * authority Chapter Run 的终态收尾端口。
 *
 * 四个方法分别对应编排器终态映射的唯一分支；实现可以选择 exit（worker/SSE）
 * 或抛出类型化异常交由入口外层统一处理（daemon）。$contentSaved 表示本次
 * Run 是否已将正文提交为 Canon（committed_revision_hash 存在）。
 */
interface WriteTermination
{
    public function succeeded(WorkflowExecutionResult $result, bool $contentSaved): void;

    public function quarantined(WorkflowExecutionResult $result, bool $contentSaved): void;

    public function canceled(WorkflowExecutionResult $result, bool $contentSaved): void;

    public function failed(WorkflowExecutionResult $result, bool $contentSaved): void;
}

/** 回调式 WriteTermination 通用实现（与 CallbackLegacyChapterTransport 同构）。 */
final class CallbackWriteTermination implements WriteTermination
{
    /** @var array<string,Closure> */
    private array $callbacks;

    /**
     * @param array{
     *   succeeded?:callable,quarantined?:callable,canceled?:callable,
     *   failed?:callable
     * } $callbacks
     */
    public function __construct(array $callbacks = [])
    {
        $allowed = ['succeeded', 'quarantined', 'canceled', 'failed'];
        $unknown = array_diff(array_keys($callbacks), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Chapter write termination received an unknown callback.'
            );
        }
        $this->callbacks = [];
        foreach ($callbacks as $name => $callback) {
            if (!is_callable($callback)) {
                throw new InvalidArgumentException(
                    'Chapter write termination callbacks must be callable.'
                );
            }
            $this->callbacks[$name] = Closure::fromCallable($callback);
        }
    }

    public function succeeded(WorkflowExecutionResult $result, bool $contentSaved): void
    {
        $this->call('succeeded', $result, $contentSaved);
    }

    public function quarantined(WorkflowExecutionResult $result, bool $contentSaved): void
    {
        $this->call('quarantined', $result, $contentSaved);
    }

    public function canceled(WorkflowExecutionResult $result, bool $contentSaved): void
    {
        $this->call('canceled', $result, $contentSaved);
    }

    public function failed(WorkflowExecutionResult $result, bool $contentSaved): void
    {
        $this->call('failed', $result, $contentSaved);
    }

    private function call(string $name, WorkflowExecutionResult $result, bool $contentSaved): void
    {
        if (isset($this->callbacks[$name])) {
            ($this->callbacks[$name])($result, $contentSaved);
        }
    }
}

/**
 * authority Chapter Run 编排请求：入口已捕获的三快照 + 编排差异维度。
 * acquireChapter 签名为 (Closure $restore, RunContext $context): array——
 * 编排器负责构造 restore 恢复闭包并注入（异步 Worker 的采纳已冻结
 * base snapshot 分支依赖该闭包复用恢复认证）。
 */
final readonly class ChapterWriteRunRequest
{
    /**
     * @param array<string,mixed>|null $baseSnapshot SSE/Daemon 必填（预占快照）。
     * @param array<string,mixed> $goal 入口附加 Goal 坐标（Worker 携带 legacy_task_id）。
     * @param Closure(Closure(RunContext):array{n:array<string,mixed>,ch:array<string,mixed>},RunContext):array{n:array<string,mixed>,ch:array<string,mixed>} $acquireChapter
     * @param Closure(RunContext,array<string,mixed>):array<string,mixed> $restoreBase
     * @param null|Closure(RunContext,array<string,mixed>,array<string,mixed>):void $restoreVerifier
     * @param array<string,mixed>|null $writingTaskLease
     * @param null|Closure(WorkflowExecutionResult):void $onRetryScheduled RETRY_WAIT 进入时回调一次。
     * @param null|Closure(string,WorkflowExecutionResult):?WorkflowExecutionResult $retryTick 每 250ms tick；返回非 null 提前终态或抛异常。
     * @param null|Closure(WorkflowExecutionResult):void $postRun runToTerminal 返回后的统一安全点（Worker 的致命退出探测）。
     */
    public function __construct(
        public ChapterRunEntryPoint $entryPoint,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public string $authorityIdentity,
        public WritePolicySnapshot $policySnapshot,
        public HarnessFeatureSnapshot $featureSnapshot,
        public ChapterRunInputSnapshot $inputSnapshot,
        public string $workerId,
        public LegacyChapterTransport $transport,
        public Closure $acquireChapter,
        public Closure $restoreBase,
        public string $restoreMissingMessage,
        public string $restoreInactiveMessage,
        public string $postprocessWorkerId,
        public WriteTermination $termination,
        public ?array $baseSnapshot = null,
        public array $goal = [],
        public ?Closure $restoreVerifier = null,
        public ?string $writingTaskId = null,
        public ?array $writingTaskLease = null,
        public ?Closure $legacyCancellationProbe = null,
        public ?Closure $waitingCallback = null,
        public ?Closure $onRetryScheduled = null,
        public ?Closure $retryTick = null,
        public ?Closure $postRun = null
    ) {
    }
}
