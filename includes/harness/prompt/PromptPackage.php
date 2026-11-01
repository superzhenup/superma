<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/context/bootstrap.php';

final readonly class PromptPackage
{
    /** @var list<array<string,mixed>> */
    public array $messages;
    /** @var list<string> */
    public array $contextItemIds;
    /** @var list<array{id:string,version:string,content_hash:string}> */
    public array $selectedSkills;
    /** @var list<array<string,mixed>> */
    public array $droppedItems;
    /** @var array<string,mixed> */
    public array $manifest;

    /**
     * @param list<array<string,mixed>> $messages
     * @param list<string> $contextItemIds
     * @param list<array{id:string,version:string,content_hash:string}> $selectedSkills
     * @param list<array<string,mixed>> $droppedItems
     * @param array<string,mixed> $manifest
     */
    public function __construct(
        array $messages,
        public string $promptHash,
        array $contextItemIds,
        array $selectedSkills,
        array $droppedItems,
        public int $estimatedTokens,
        array $manifest
    ) {
        HarnessPersistenceValue::hash($promptHash, 'prompt_package.prompt_hash');
        HarnessPersistenceValue::nonNegativeInt(
            $estimatedTokens,
            'prompt_package.estimated_tokens'
        );
        $canonicalMessages = self::normalizeMessages($messages);
        if (!hash_equals(
            hash('sha256', HarnessPersistenceValue::canonicalJson($canonicalMessages)),
            $promptHash
        )) {
            throw new InvalidArgumentException('Prompt hash does not match canonical messages.');
        }
        foreach ($contextItemIds as $id) {
            HarnessPersistenceValue::uid($id, 'prompt_package.context_item_id');
        }
        if (count($contextItemIds) !== count(array_unique($contextItemIds))) {
            throw new InvalidArgumentException('Prompt package context item IDs must be unique.');
        }
        foreach ($selectedSkills as $skill) {
            HarnessPersistenceValue::requireFields(
                $skill,
                ['id', 'version', 'content_hash'],
                'prompt_package.selected_skill'
            );
            HarnessPersistenceValue::identifier($skill['id'], 'selected_skill.id', 64);
            HarnessPersistenceValue::identifier($skill['version'], 'selected_skill.version', 32);
            HarnessPersistenceValue::hash($skill['content_hash'], 'selected_skill.content_hash');
        }
        HarnessSensitiveDataGuard::assertSafeStructure($manifest, 'prompt_package.manifest');

        $this->messages = $canonicalMessages;
        $this->contextItemIds = array_values($contextItemIds);
        $this->selectedSkills = array_values($selectedSkills);
        $this->droppedItems = array_values($droppedItems);
        $this->manifest = $manifest;
    }

    /** @param list<array<string,mixed>> $messages @return list<array<string,mixed>> */
    public static function normalizeMessages(array $messages): array
    {
        $normalized = [];
        foreach ($messages as $index => $message) {
            if (!is_array($message) || array_is_list($message)) {
                throw new InvalidArgumentException("Prompt message {$index} must be an object array.");
            }
            $role = $message['role'] ?? null;
            if (!is_string($role)
                || !in_array($role, ['system', 'developer', 'user', 'assistant', 'tool'], true)) {
                throw new InvalidArgumentException("Prompt message {$index} has an invalid role.");
            }
            if (!array_key_exists('content', $message)) {
                throw new InvalidArgumentException("Prompt message {$index} is missing content.");
            }
            self::assertJsonValue($message['content'], "messages.{$index}.content");
            $copy = $message;
            ksort($copy, SORT_STRING);
            $normalized[] = $copy;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('Prompt package requires at least one message.');
        }
        return $normalized;
    }

    private static function assertJsonValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidArgumentException("{$path} exceeds the prompt JSON depth limit.");
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!is_int($key) && !is_string($key)) {
                    throw new InvalidArgumentException("{$path} contains an invalid key.");
                }
                self::assertJsonValue($item, "{$path}.{$key}", $depth + 1);
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }
        if (is_float($value) && is_finite($value)) {
            return;
        }
        throw new InvalidArgumentException("{$path} contains a non-serializable value.");
    }
}

/** One shared estimate for renderer enforcement and Prompt manifest evidence. */
final class PromptMessageTokenCounter
{
    /** @param list<array<string,mixed>> $messages */
    public static function estimate(
        array $messages,
        ContextTokenEstimator $estimator
    ): int {
        $tokens = 0;
        foreach (PromptPackage::normalizeMessages($messages) as $message) {
            $tokens += $estimator->estimate(
                HarnessPersistenceValue::canonicalJson($message)
            );
        }
        return $tokens;
    }
}
