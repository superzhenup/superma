<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/SkillManifest.php';

final class NovelSkillRegistry
{
    /** @var array<string,NovelSkillManifest> */
    private array $skills = [];

    public static function load(string $root): self
    {
        $root = realpath($root) ?: throw new RuntimeException('Skill registry root does not exist.');
        $registry = new self();
        $directories = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
            $instructionsPath = $directory . DIRECTORY_SEPARATOR . 'SKILL.md';
            if (!is_file($manifestPath) || !is_file($instructionsPath)) {
                continue;
            }
            $manifestRaw = file_get_contents($manifestPath);
            $instructions = file_get_contents($instructionsPath);
            if (!is_string($manifestRaw) || !is_string($instructions)) {
                throw new RuntimeException('Skill files could not be read.');
            }
            try {
                $data = json_decode($manifestRaw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Skill manifest is not valid JSON.', 0, $exception);
            }
            if (!is_array($data) || array_is_list($data)) {
                throw new RuntimeException('Skill manifest must contain a JSON object.');
            }
            HarnessPersistenceValue::requireFields($data, [
                'id', 'version', 'description', 'triggers', 'priority',
                'token_budget', 'requires', 'conflicts',
            ], 'skill_manifest');
            if (!is_array($data['triggers']) || array_is_list($data['triggers'])
                || !is_array($data['requires']) || !array_is_list($data['requires'])
                || !is_array($data['conflicts']) || !array_is_list($data['conflicts'])) {
                throw new RuntimeException('Skill manifest trigger/dependency fields are malformed.');
            }
            $canonicalManifest = HarnessPersistenceValue::canonicalJson($data);
            $skill = new NovelSkillManifest(
                (string)$data['id'],
                (string)$data['version'],
                (string)$data['description'],
                $data['triggers'],
                HarnessPersistenceValue::boundedInt($data['priority'], 'skill.priority', 0, 1000),
                HarnessPersistenceValue::positiveInt($data['token_budget'], 'skill.token_budget'),
                $data['requires'],
                $data['conflicts'],
                $instructions,
                hash('sha256', $canonicalManifest . "\0" . $instructions)
            );
            if (basename($directory) !== $skill->id) {
                throw new RuntimeException('Skill directory name must match its manifest ID.');
            }
            if (isset($registry->skills[$skill->id])) {
                throw new RuntimeException("Duplicate Skill ID {$skill->id}.");
            }
            $registry->skills[$skill->id] = $skill;
        }
        ksort($registry->skills, SORT_STRING);
        $registry->validateGraph();
        return $registry;
    }

    public function get(string $id): NovelSkillManifest
    {
        return $this->skills[$id]
            ?? throw new OutOfBoundsException("Unknown Skill {$id}.");
    }

    /** @return list<NovelSkillManifest> */
    public function all(): array
    {
        return array_values($this->skills);
    }

    private function validateGraph(): void
    {
        foreach ($this->skills as $skill) {
            foreach ($skill->requires as $required) {
                if (!isset($this->skills[$required])) {
                    throw new RuntimeException(
                        "Skill {$skill->id} requires missing Skill {$required}."
                    );
                }
            }
            foreach ($skill->conflicts as $conflict) {
                if (!isset($this->skills[$conflict])) {
                    throw new RuntimeException(
                        "Skill {$skill->id} conflicts with missing Skill {$conflict}."
                    );
                }
            }
            $this->assertAcyclic($skill->id, [], []);
        }
    }

    /** @param array<string,bool> $visiting @param array<string,bool> $visited */
    private function assertAcyclic(string $id, array $visiting, array $visited): void
    {
        if (isset($visited[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            throw new RuntimeException("Skill dependency cycle detected at {$id}.");
        }
        $visiting[$id] = true;
        foreach ($this->skills[$id]->requires as $required) {
            $this->assertAcyclic($required, $visiting, $visited);
        }
    }
}
