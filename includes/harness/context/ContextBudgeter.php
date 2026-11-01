<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ContextItem.php';

interface ContextTokenEstimator
{
    public function name(): string;
    public function estimate(string $content): int;
}

/** Conservative deterministic fallback when a provider tokenizer is absent. */
final class ConservativeContextTokenEstimator implements ContextTokenEstimator
{
    public function name(): string { return 'unicode_conservative_v1'; }

    public function estimate(string $content): int
    {
        if ($content === '') {
            return 0;
        }
        $characters = function_exists('mb_strlen')
            ? mb_strlen($content, 'UTF-8')
            : strlen($content);
        // CJK is often near one token/character. The 1.15 multiplier reserves
        // tokenizer and message-envelope variance instead of under-budgeting.
        return max(1, (int)ceil($characters * 1.15));
    }
}

final class RequiredContextBudgetExceededException extends RuntimeException
{
}

final readonly class ContextDrop
{
    public function __construct(public ContextItem $item, public string $reason)
    {
        HarnessPersistenceValue::identifier($reason, 'context_drop.reason', 64);
    }
}

final readonly class ContextBudgetResult
{
    /** @var list<ContextItem> */
    public array $selected;
    /** @var list<ContextDrop> */
    public array $dropped;

    /** @param list<ContextItem> $selected @param list<ContextDrop> $dropped */
    public function __construct(
        array $selected,
        array $dropped,
        public int $modelContextTokens,
        public int $outputReserveTokens,
        public int $systemOverheadTokens,
        public int $availableContextTokens,
        public int $selectedTokens,
        public string $estimator
    ) {
        $this->selected = $selected;
        $this->dropped = $dropped;
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return [
            'model_context_tokens' => $this->modelContextTokens,
            'output_reserve_tokens' => $this->outputReserveTokens,
            'system_overhead_tokens' => $this->systemOverheadTokens,
            'available_context_tokens' => $this->availableContextTokens,
            'selected_tokens' => $this->selectedTokens,
            'estimator' => $this->estimator,
            'selected' => array_map(
                static fn (ContextItem $item): array => $item->manifestEntry(),
                $this->selected
            ),
            'dropped' => array_map(
                static fn (ContextDrop $drop): array => [
                    'item' => $drop->item->manifestEntry(),
                    'reason' => $drop->reason,
                ],
                $this->dropped
            ),
        ];
    }
}

final class DeterministicContextBudgeter
{
    public function __construct(private readonly ContextTokenEstimator $estimator)
    {
    }

    /** @param list<ContextItem> $items */
    public function select(
        array $items,
        int $modelContextTokens,
        int $outputReserveTokens,
        int $systemOverheadTokens
    ): ContextBudgetResult {
        HarnessPersistenceValue::positiveInt($modelContextTokens, 'budget.model_context_tokens');
        HarnessPersistenceValue::nonNegativeInt($outputReserveTokens, 'budget.output_reserve_tokens');
        HarnessPersistenceValue::nonNegativeInt($systemOverheadTokens, 'budget.system_overhead_tokens');
        $available = $modelContextTokens - $outputReserveTokens - $systemOverheadTokens;
        if ($available < 0) {
            throw new RequiredContextBudgetExceededException(
                'Output reserve and fixed system overhead exceed the model context window.'
            );
        }

        foreach ($items as $item) {
            if (!$item instanceof ContextItem) {
                throw new InvalidArgumentException('Context budget input must contain ContextItem values.');
            }
        }

        usort($items, [self::class, 'compare']);
        $selected = [];
        $dropped = [];
        $seenDedup = [];
        $seenHash = [];
        $used = 0;

        foreach ($items as $item) {
            if ($item->validity !== ContextValidity::VALID) {
                if ($item->required || $item->priority === 0) {
                    throw new RequiredContextBudgetExceededException(sprintf(
                        'Required/P0 Context item %s is %s (%s).',
                        $item->id,
                        $item->validity->value,
                        $item->validityReason ?? 'no reason supplied'
                    ));
                }
                $dropped[] = new ContextDrop($item, 'invalid_or_stale');
                continue;
            }
            if (isset($seenDedup[$item->dedupKey]) || isset($seenHash[$item->contentHash])) {
                $dropped[] = new ContextDrop($item, 'duplicate');
                continue;
            }

            // Provider estimates are useful provenance, but they are not a
            // trusted budget boundary. A stale/buggy provider must not be able
            // to under-report bytes and push the real prompt beyond the model
            // window, so selection always charges the larger estimate.
            $cost = max(
                $item->estimatedTokens,
                $this->estimator->estimate($item->content)
            );
            if ($used + $cost > $available) {
                if ($item->required || $item->priority === 0) {
                    throw new RequiredContextBudgetExceededException(sprintf(
                        'Required/P0 Context item %s exceeds the remaining context budget.',
                        $item->id
                    ));
                }
                $dropped[] = new ContextDrop($item, 'token_budget');
                continue;
            }

            $selected[] = $item;
            $seenDedup[$item->dedupKey] = true;
            $seenHash[$item->contentHash] = true;
            $used += $cost;
        }

        usort($selected, static function (ContextItem $a, ContextItem $b): int {
            $position = [
                ContextPositionHint::SYSTEM->value => 0,
                ContextPositionHint::PREFIX->value => 1,
                ContextPositionHint::MIDDLE->value => 2,
                ContextPositionHint::TAIL->value => 3,
            ];
            return ($position[$a->positionHint->value] <=> $position[$b->positionHint->value])
                ?: strcmp($a->id, $b->id);
        });

        return new ContextBudgetResult(
            $selected,
            $dropped,
            $modelContextTokens,
            $outputReserveTokens,
            $systemOverheadTokens,
            $available,
            $used,
            $this->estimator->name()
        );
    }

    private static function compare(ContextItem $a, ContextItem $b): int
    {
        $authorityOrder = [
            ContextAuthority::PLATFORM->value => 0,
            ContextAuthority::CANON->value => 1,
            ContextAuthority::OUTLINE->value => 2,
            ContextAuthority::MEMORY->value => 3,
            ContextAuthority::USER->value => 4,
            ContextAuthority::UNTRUSTED->value => 5,
        ];
        return ($a->priority <=> $b->priority)
            ?: ($authorityOrder[$a->authority->value] <=> $authorityOrder[$b->authority->value])
            ?: ($b->required <=> $a->required)
            ?: ($b->relevance <=> $a->relevance)
            ?: ($b->freshness <=> $a->freshness)
            ?: ($a->estimatedTokens <=> $b->estimatedTokens)
            ?: strcmp($a->id, $b->id);
    }
}
