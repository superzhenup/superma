<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PromptPackage.php';
require_once dirname(__DIR__) . '/skills/SkillManifest.php';

final class SkillPromptDecorator
{
    /**
     * @param list<array<string,mixed>> $messages
     * @param list<NovelSkillManifest> $skills
     * @return list<array<string,mixed>>
     */
    public static function decorate(array $messages, array $skills): array
    {
        $messages = PromptPackage::normalizeMessages($messages);
        if ($skills === []) {
            return $messages;
        }
        foreach ($skills as $skill) {
            if (!$skill instanceof NovelSkillManifest) {
                throw new InvalidArgumentException('Skill prompt input must contain Skill manifests.');
            }
        }
        usort($skills, static fn (NovelSkillManifest $a, NovelSkillManifest $b): int =>
            ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id)
        );
        $blocks = ['【版本化写作 Skills】'];
        foreach ($skills as $skill) {
            $blocks[] = sprintf(
                "[Skill %s@%s hash=%s]\n%s",
                $skill->id,
                $skill->version,
                $skill->contentHash,
                trim($skill->instructions)
            );
        }
        $injection = implode("\n\n", $blocks);
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? null) !== 'system'
                || !is_string($message['content'] ?? null)) {
                continue;
            }
            $messages[$index]['content'] = rtrim($message['content']) . "\n\n" . $injection;
            return PromptPackage::normalizeMessages($messages);
        }
        array_unshift($messages, ['role' => 'system', 'content' => $injection]);
        return PromptPackage::normalizeMessages($messages);
    }
}
