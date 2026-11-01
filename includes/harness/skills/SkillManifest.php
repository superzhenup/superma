<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

final readonly class NovelSkillManifest
{
    /** @var array<string,mixed> */
    public array $triggers;
    /** @var list<string> */
    public array $requires;
    /** @var list<string> */
    public array $conflicts;
    public int $estimatedInstructionTokens;

    /**
     * @param array<string,mixed> $triggers
     * @param list<string> $requires
     * @param list<string> $conflicts
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $description,
        array $triggers,
        public int $priority,
        public int $tokenBudget,
        array $requires,
        array $conflicts,
        public string $instructions,
        public string $contentHash
    ) {
        HarnessPersistenceValue::identifier($id, 'skill.id', 64);
        HarnessPersistenceValue::identifier($version, 'skill.version', 32);
        HarnessPersistenceValue::string($description, 'skill.description', 1, 500);
        if ($priority < 0 || $priority > 1000) {
            throw new InvalidArgumentException('Skill priority must be between 0 and 1000.');
        }
        HarnessPersistenceValue::positiveInt($tokenBudget, 'skill.token_budget');
        if ($instructions === ''
            || strlen($instructions) > 200000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $instructions) === 1) {
            throw new InvalidArgumentException('skill.instructions is malformed.');
        }
        HarnessPersistenceValue::hash($contentHash, 'skill.content_hash');
        HarnessSensitiveDataGuard::assertSafeStructure($triggers, 'skill.triggers');

        $allowedTriggerKeys = ['chapter_types', 'keywords', 'outline_tags', 'final_chapter'];
        $unknown = array_diff(array_keys($triggers), $allowedTriggerKeys);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Skill contains unknown trigger keys: ' . implode(',', $unknown)
            );
        }
        foreach (['chapter_types', 'keywords', 'outline_tags'] as $key) {
            if (!array_key_exists($key, $triggers)) {
                continue;
            }
            if (!is_array($triggers[$key]) || !array_is_list($triggers[$key])) {
                throw new InvalidArgumentException("Skill trigger {$key} must be a list.");
            }
            foreach ($triggers[$key] as $value) {
                HarnessPersistenceValue::string($value, "skill.triggers.{$key}", 1, 100);
            }
        }
        if (array_key_exists('final_chapter', $triggers)
            && !is_bool($triggers['final_chapter'])) {
            throw new InvalidArgumentException('Skill final_chapter trigger must be boolean.');
        }

        $this->triggers = $triggers;
        // Keep the manifest declaration as a conservative reservation, while
        // binding it to the actual SKILL.md bytes so a changed instruction file
        // can never silently consume more context than the selector charges.
        $characters = function_exists('mb_strlen')
            ? mb_strlen($instructions, 'UTF-8')
            : strlen($instructions);
        $this->estimatedInstructionTokens = max(1, (int)ceil($characters * 1.15));
        $this->requires = self::normalizeSkillIds($requires, 'skill.requires');
        $this->conflicts = self::normalizeSkillIds($conflicts, 'skill.conflicts');
        if (in_array($id, $this->requires, true) || in_array($id, $this->conflicts, true)) {
            throw new InvalidArgumentException('A Skill cannot require or conflict with itself.');
        }
    }

    /** @return array{id:string,version:string,content_hash:string} */
    public function coordinate(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'content_hash' => $this->contentHash,
        ];
    }

    public function effectiveTokenCost(): int
    {
        return max($this->tokenBudget, $this->estimatedInstructionTokens);
    }

    /** @param list<mixed> $values @return list<string> */
    private static function normalizeSkillIds(array $values, string $field): array
    {
        $result = [];
        foreach ($values as $value) {
            $id = HarnessPersistenceValue::identifier($value, $field, 64);
            $result[$id] = $id;
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }
}
