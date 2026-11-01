<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/runtime/bootstrap.php';

/**
 * Transport-neutral bridge from the durable Workflow to the public legacy
 * WriteEngine phases. Implementations own their ephemeral service objects;
 * only the StageResult patches are eligible for durable checkpoints.
 */
interface LegacyChapterStageAdapter
{
    /** Stable implementation identity included in every Stage input hash. */
    public function version(): string;

    public function acquire(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult;

    public function buildContext(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult;

    public function compilePrompt(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult;

    public function generateDraft(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult;

    public function commit(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult;

    public function postprocess(
        RunContext $context,
        CancellationToken $cancellation,
        Deadline $deadline,
        LeaseHeartbeat $heartbeat
    ): StageResult;
}

final readonly class ChapterRunAuthorityFence
{
    public function __construct(
        public int $runId,
        public string $runUid,
        public string $leaseOwner,
        public string $leaseToken,
        public int $fenceGeneration
    ) {
        HarnessPersistenceValue::positiveInt($runId, 'chapter_authority.run_id');
        HarnessPersistenceValue::uid($runUid, 'chapter_authority.run_uid');
        HarnessPersistenceValue::identifier(
            $leaseOwner,
            'chapter_authority.lease_owner',
            120
        );
        HarnessPersistenceValue::uid($leaseToken, 'chapter_authority.lease_token');
        HarnessPersistenceValue::positiveInt(
            $fenceGeneration,
            'chapter_authority.fence_generation'
        );
    }
}

interface ChapterRunAuthorityAwareAdapter
{
    public function bindAuthority(ChapterRunAuthorityFence $authority): void;
}

abstract class LegacyChapterStage implements RunStage
{
    public function __construct(
        protected readonly LegacyChapterStageAdapter $adapter,
        private readonly RetryPolicy $policy
    ) {
    }

    final public function type(): string
    {
        return 'legacy.write_engine';
    }

    final public function inputHash(RunContext $context): string
    {
        return RuntimeData::hashArray([
            'schema' => 'harness.legacy_stage_input.v1',
            'stage_name' => $this->name(),
            'stage_type' => $this->type(),
            'adapter_version' => HarnessPersistenceValue::identifier(
                $this->adapter->version(),
                'legacy_stage.adapter_version',
                64
            ),
            'input_context_hash' => $context->hash(),
        ], 'legacy_stage.input');
    }

    final public function retryPolicy(): RetryPolicy
    {
        return $this->policy;
    }

    public function commitBoundary(RunContext $context): ?CommitBoundary
    {
        return null;
    }

    protected static function retryInfrastructure(int $attempts = 3): RetryPolicy
    {
        return new RetryPolicy(
            $attempts,
            5,
            2,
            120,
            [RetryableInfrastructureException::class]
        );
    }

    protected static function retryGeneration(): RetryPolicy
    {
        return new RetryPolicy(
            3,
            5,
            2,
            120,
            [
                RetryableModelException::class,
                RetryableInfrastructureException::class,
            ]
        );
    }
}
