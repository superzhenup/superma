<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PromptPackage.php';

interface PromptCompiler
{
    /**
     * @param callable():list<array<string,mixed>> $legacyBuilder
     * @param list<array{id:string,version:string,content_hash:string}> $selectedSkills
     * @param array<string,mixed> $sourceSnapshot
     */
    public function compile(
        callable $legacyBuilder,
        ContextBudgetResult $context,
        array $selectedSkills,
        array $sourceSnapshot
    ): PromptPackage;
}

final class LegacyPromptCompiler implements PromptCompiler
{
    public const VERSION = 'legacy-prompt-compiler-v1';

    public function __construct(private readonly ContextTokenEstimator $estimator)
    {
    }

    public function compile(
        callable $legacyBuilder,
        ContextBudgetResult $context,
        array $selectedSkills,
        array $sourceSnapshot
    ): PromptPackage {
        $messages = $legacyBuilder();
        if (!is_array($messages)) {
            throw new RuntimeException('Legacy prompt builder must return a message list.');
        }
        $messages = PromptPackage::normalizeMessages(array_values($messages));
        HarnessSensitiveDataGuard::assertSafeStructure(
            $sourceSnapshot,
            'prompt.source_snapshot'
        );
        ksort($sourceSnapshot, SORT_STRING);
        usort($selectedSkills, static function (array $a, array $b): int {
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''))
                ?: strcmp((string)($a['version'] ?? ''), (string)($b['version'] ?? ''));
        });

        $contextManifest = $context->manifest();
        $contextIds = array_map(
            static fn (ContextItem $item): string => $item->id,
            $context->selected
        );
        $dropped = $contextManifest['dropped'];
        $promptJson = HarnessPersistenceValue::canonicalJson($messages);
        $promptHash = hash('sha256', $promptJson);
        $messageTokens = PromptMessageTokenCounter::estimate($messages, $this->estimator);
        $manifest = [
            'schema_version' => 1,
            'compiler' => self::VERSION,
            'prompt_hash' => $promptHash,
            'message_count' => count($messages),
            'message_estimated_tokens' => $messageTokens,
            'source_snapshot' => $sourceSnapshot,
            'context' => $contextManifest,
            'selected_skills' => $selectedSkills,
        ];
        $manifest['manifest_hash'] = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson($manifest)
        );

        return new PromptPackage(
            $messages,
            $promptHash,
            $contextIds,
            $selectedSkills,
            $dropped,
            $messageTokens,
            $manifest
        );
    }
}

final class PromptShadowComparator
{
    /** @param list<array<string,mixed>> $legacyMessages @return array<string,mixed> */
    public static function compare(array $legacyMessages, PromptPackage $package): array
    {
        $legacy = PromptPackage::normalizeMessages($legacyMessages);
        $legacyHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalJson($legacy)
        );
        $same = hash_equals($legacyHash, $package->promptHash);
        return [
            'schema_version' => 1,
            'same_messages' => $same,
            'legacy_prompt_hash' => $legacyHash,
            'compiled_prompt_hash' => $package->promptHash,
            'legacy_message_count' => count($legacy),
            'compiled_message_count' => count($package->messages),
            'manifest_hash' => (string)($package->manifest['manifest_hash'] ?? ''),
            'online_generation_triggered' => false,
        ];
    }
}
