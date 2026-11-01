<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/AgentLoop.php';

/** Production-facing role facade. It intentionally exposes no Writer method. */
final class StoryAgentService
{
    private ?Closure $observerFactory;
    private ?Closure $monotonicMs;

    /**
     * @param callable(AgentLoopRole,ToolExecutionContext):AgentLoopObserver|null $observerFactory
     * @param callable():int|null $monotonicMs
     */
    public function __construct(
        private readonly ModelGateway $models,
        private readonly ToolRegistry $catalog,
        ?callable $observerFactory = null,
        ?callable $monotonicMs = null,
        private readonly ?RunSideEffectRegistry $sideEffects = null
    ) {
        $this->observerFactory = $observerFactory === null
            ? null
            : Closure::fromCallable($observerFactory);
        $this->monotonicMs = $monotonicMs === null
            ? null
            : Closure::fromCallable($monotonicMs);
    }

    /** @param list<array<string,mixed>> $messages */
    public function plan(
        ToolExecutionContext $context,
        array $messages,
        AgentLoopBudget $budget = new AgentLoopBudget()
    ): AgentLoopResult {
        return $this->execute(AgentLoopRole::PLANNER, $context, $messages, $budget);
    }

    /** @param list<array<string,mixed>> $messages */
    public function research(
        ToolExecutionContext $context,
        array $messages,
        AgentLoopBudget $budget = new AgentLoopBudget()
    ): AgentLoopResult {
        return $this->execute(
            AgentLoopRole::MEMORY_RESEARCHER,
            $context,
            $messages,
            $budget
        );
    }

    /** @param list<array<string,mixed>> $messages */
    public function review(
        ToolExecutionContext $context,
        array $messages,
        AgentLoopBudget $budget = new AgentLoopBudget()
    ): AgentLoopResult {
        return $this->execute(AgentLoopRole::REVIEWER, $context, $messages, $budget);
    }

    /** @param list<array<string,mixed>> $messages */
    private function execute(
        AgentLoopRole $role,
        ToolExecutionContext $context,
        array $messages,
        AgentLoopBudget $budget
    ): AgentLoopResult {
        if ($context->actorRole !== $role->value) {
            throw new ToolPolicyViolationException('Story Agent role/context mismatch.');
        }
        $registry = $this->catalog->subset(self::toolsFor($role));
        $observer = $this->observerFactory === null
            ? new NullAgentLoopObserver()
            : ($this->observerFactory)($role, $context);
        if (!$observer instanceof AgentLoopObserver) {
            throw new RuntimeException('Story Agent observer factory returned an invalid observer.');
        }
        return (new AgentLoop(
            $this->models,
            $registry,
            new ToolExecutor($registry, $this->monotonicMs, sideEffects: $this->sideEffects),
            $observer,
            $this->monotonicMs
        ))->run($role, $context, $messages, $budget);
    }

    /** @return list<string> */
    private static function toolsFor(AgentLoopRole $role): array
    {
        return match ($role) {
            AgentLoopRole::PLANNER => array_merge(
                StoryToolCatalog::READ_TOOLS,
                StoryToolCatalog::PROPOSE_TOOLS
            ),
            AgentLoopRole::MEMORY_RESEARCHER, AgentLoopRole::REVIEWER => [
                'story.get_state',
                'story.search_memory',
                'story.get_character_state',
                'story.get_chapter_outline',
                'story.get_due_foreshadows',
                'story.get_previous_tail',
            ],
        };
    }
}
