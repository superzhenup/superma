<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/SkillRegistry.php';

final readonly class SkillSelectionRequest
{
    /** @var list<string> */
    public array $outlineTags;

    /** @param list<string> $outlineTags */
    public function __construct(
        public string $chapterType,
        public string $outlineText,
        array $outlineTags,
        public bool $finalChapter,
        public int $tokenBudget
    ) {
        HarnessPersistenceValue::identifier($chapterType, 'skill_selection.chapter_type', 64);
        HarnessPersistenceValue::nonNegativeInt($tokenBudget, 'skill_selection.token_budget');
        $tags = [];
        foreach ($outlineTags as $tag) {
            $tag = HarnessPersistenceValue::identifier($tag, 'skill_selection.outline_tag', 64);
            $tags[$tag] = $tag;
        }
        ksort($tags, SORT_STRING);
        $this->outlineTags = array_values($tags);
    }
}

final readonly class SkillSelectionResult
{
    /** @var list<NovelSkillManifest> */
    public array $selected;
    /** @var list<array<string,mixed>> */
    public array $decisions;

    /** @param list<NovelSkillManifest> $selected @param list<array<string,mixed>> $decisions */
    public function __construct(array $selected, array $decisions, public int $usedTokens)
    {
        $this->selected = $selected;
        $this->decisions = $decisions;
    }

    /** @return list<array{id:string,version:string,content_hash:string}> */
    public function coordinates(): array
    {
        return array_map(
            static fn (NovelSkillManifest $skill): array => $skill->coordinate(),
            $this->selected
        );
    }
}

final class DeterministicSkillSelector
{
    public function __construct(private readonly NovelSkillRegistry $registry)
    {
    }

    public function select(SkillSelectionRequest $request): SkillSelectionResult
    {
        $matches = [];
        $decisions = [];
        foreach ($this->registry->all() as $skill) {
            $reasons = $this->matchReasons($skill, $request);
            if ($reasons === []) {
                $decisions[] = ['skill_id' => $skill->id, 'selected' => false, 'reason' => 'no_trigger'];
                continue;
            }
            $matches[$skill->id] = ['skill' => $skill, 'reasons' => $reasons];
        }
        uasort($matches, static function (array $a, array $b): int {
            /** @var NovelSkillManifest $as */
            $as = $a['skill'];
            /** @var NovelSkillManifest $bs */
            $bs = $b['skill'];
            return ($as->priority <=> $bs->priority) ?: strcmp($as->id, $bs->id);
        });

        $selected = [];
        $used = 0;
        foreach ($matches as $id => $match) {
            /** @var NovelSkillManifest $skill */
            $skill = $match['skill'];
            $conflict = $this->selectedConflict($skill, $selected);
            if ($conflict !== null) {
                $decisions[] = [
                    'skill_id' => $id,
                    'selected' => false,
                    'reason' => 'conflict',
                    'with' => $conflict,
                    'triggers' => $match['reasons'],
                ];
                continue;
            }
            $closure = $this->dependencyClosure($skill);
            $additional = array_filter(
                $closure,
                static fn (NovelSkillManifest $candidate): bool => !isset($selected[$candidate->id])
            );
            $cost = array_sum(array_map(
                static fn (NovelSkillManifest $candidate): int => $candidate->effectiveTokenCost(),
                $additional
            ));
            if ($used + $cost > $request->tokenBudget) {
                $decisions[] = [
                    'skill_id' => $id,
                    'selected' => false,
                    'reason' => 'token_budget',
                    'required_tokens' => $cost,
                    'triggers' => $match['reasons'],
                ];
                continue;
            }
            foreach ($additional as $candidate) {
                $dependencyConflict = $this->selectedConflict($candidate, $selected);
                if ($dependencyConflict !== null) {
                    throw new RuntimeException(
                        "Required Skill {$candidate->id} conflicts with selected Skill {$dependencyConflict}."
                    );
                }
                $selected[$candidate->id] = $candidate;
                $used += $candidate->effectiveTokenCost();
            }
            $decisions[] = [
                'skill_id' => $id,
                'selected' => true,
                'reason' => 'trigger_match',
                'triggers' => $match['reasons'],
                'dependencies' => $skill->requires,
            ];
        }

        uasort($selected, static fn (NovelSkillManifest $a, NovelSkillManifest $b): int =>
            ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id)
        );
        return new SkillSelectionResult(array_values($selected), $decisions, $used);
    }

    /** @return list<string> */
    private function matchReasons(
        NovelSkillManifest $skill,
        SkillSelectionRequest $request
    ): array {
        $reasons = [];
        $types = $skill->triggers['chapter_types'] ?? [];
        if (in_array($request->chapterType, $types, true)) {
            $reasons[] = 'chapter_type:' . $request->chapterType;
        }
        foreach (($skill->triggers['keywords'] ?? []) as $keyword) {
            if (self::contains($request->outlineText, (string)$keyword)) {
                $reasons[] = 'keyword:' . $keyword;
            }
        }
        foreach (($skill->triggers['outline_tags'] ?? []) as $tag) {
            if (in_array($tag, $request->outlineTags, true)) {
                $reasons[] = 'outline_tag:' . $tag;
            }
        }
        if (($skill->triggers['final_chapter'] ?? null) === true && $request->finalChapter) {
            $reasons[] = 'final_chapter';
        }
        sort($reasons, SORT_STRING);
        return array_values(array_unique($reasons));
    }

    /** @param array<string,NovelSkillManifest> $selected */
    private function selectedConflict(NovelSkillManifest $skill, array $selected): ?string
    {
        foreach ($selected as $other) {
            if (in_array($other->id, $skill->conflicts, true)
                || in_array($skill->id, $other->conflicts, true)) {
                return $other->id;
            }
        }
        return null;
    }

    /** @return list<NovelSkillManifest> */
    private function dependencyClosure(NovelSkillManifest $skill, array $seen = []): array
    {
        if (isset($seen[$skill->id])) {
            return [];
        }
        $seen[$skill->id] = true;
        $result = [];
        foreach ($skill->requires as $requiredId) {
            $required = $this->registry->get($requiredId);
            foreach ($this->dependencyClosure($required, $seen) as $dependency) {
                $result[$dependency->id] = $dependency;
            }
            $result[$required->id] = $required;
        }
        $result[$skill->id] = $skill;
        uasort($result, static fn (NovelSkillManifest $a, NovelSkillManifest $b): int =>
            ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id)
        );
        return array_values($result);
    }

    private static function contains(string $haystack, string $needle): bool
    {
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        return stripos($haystack, $needle) !== false;
    }
}
