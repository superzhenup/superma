<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ContextItem.php';

final readonly class ContextProviderRequest
{
    /** @param array<string,mixed> $sources */
    public function __construct(
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public int $chapterNumber,
        public array $sources
    ) {
        HarnessPersistenceValue::positiveInt($userId, 'context_request.user_id');
        HarnessPersistenceValue::positiveInt($novelId, 'context_request.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'context_request.chapter_id');
        HarnessPersistenceValue::positiveInt($chapterNumber, 'context_request.chapter_number');
    }
}

interface ContextProvider
{
    public function name(): string;

    /** @return list<ContextItem> */
    public function provide(ContextProviderRequest $request, ContextTokenEstimator $estimator): array;
}

/**
 * Initial migration adapter: wraps one already-produced legacy context section
 * with explicit source provenance. It never queries a different user or novel.
 */
abstract class LegacySectionContextProvider implements ContextProvider
{
    public function __construct(
        private readonly string $section,
        private readonly string $kind,
        private readonly ContextAuthority $authority,
        private readonly int $priority,
        private readonly bool $required,
        private readonly ContextPositionHint $positionHint = ContextPositionHint::MIDDLE,
        private readonly ContextSensitivity $sensitivity = ContextSensitivity::INTERNAL
    ) {
    }

    final public function provide(
        ContextProviderRequest $request,
        ContextTokenEstimator $estimator
    ): array {
        $raw = $request->sources[$this->section] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $descriptor = is_array($raw)
            && array_key_exists('content', $raw)
            && array_key_exists('source_id', $raw)
            && array_key_exists('source_revision', $raw)
                ? $raw
                : [
                    'content' => $raw,
                    'source_id' => (string)$request->chapterId,
                    'source_revision' => hash(
                        'sha256',
                        HarnessPersistenceValue::canonicalJson(['value' => $raw])
                    ),
                    'metadata' => [],
                ];
        $content = is_string($descriptor['content'])
            ? $descriptor['content']
            : HarnessPersistenceValue::canonicalJson(['value' => $descriptor['content']]);
        $sourceId = (string)$descriptor['source_id'];
        $revision = (string)$descriptor['source_revision'];
        $metadata = is_array($descriptor['metadata'] ?? null)
            ? $descriptor['metadata']
            : [];
        $metadata['scope'] = [
            'user_id' => $request->userId,
            'novel_id' => $request->novelId,
            'chapter_id' => $request->chapterId,
        ];

        return [ContextItem::create(
            $this->kind,
            $this->name(),
            $sourceId,
            $revision,
            $content,
            $this->priority,
            $this->required,
            $this->authority,
            $estimator->estimate($content),
            (float)($descriptor['relevance'] ?? 1.0),
            (float)($descriptor['freshness'] ?? 1.0),
            isset($descriptor['dedup_key']) ? (string)$descriptor['dedup_key'] : null,
            $this->positionHint,
            $this->sensitivity,
            is_array($descriptor['tags'] ?? null) ? array_values($descriptor['tags']) : [],
            $metadata
        )];
    }
}

final class PlatformRuleProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('platform_rules', 'platform.rules', ContextAuthority::PLATFORM, 0, true, ContextPositionHint::SYSTEM); }
    public function name(): string { return 'platform_rule'; }
}

final class NovelProfileProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('novel_profile', 'novel.profile', ContextAuthority::CANON, 0, true, ContextPositionHint::PREFIX); }
    public function name(): string { return 'novel_profile'; }
}

final class ChapterOutlineProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('chapter_outline', 'chapter.outline', ContextAuthority::OUTLINE, 0, true, ContextPositionHint::TAIL); }
    public function name(): string { return 'chapter_outline'; }
}

final class CanonMemoryProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('canon_memory', 'canon.memory', ContextAuthority::CANON, 1, false); }
    public function name(): string { return 'canon_memory'; }
}

final class PreviousChapterProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('previous_chapter', 'chapter.previous', ContextAuthority::CANON, 1, false, ContextPositionHint::TAIL); }
    public function name(): string { return 'previous_chapter'; }
}

final class ForeshadowProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('foreshadow', 'story.foreshadow', ContextAuthority::MEMORY, 1, false); }
    public function name(): string { return 'foreshadow'; }
}

final class CharacterStateProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('character_state', 'character.state', ContextAuthority::CANON, 1, false); }
    public function name(): string { return 'character_state'; }
}

final class AgentDirectiveProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('agent_directive', 'agent.directive', ContextAuthority::UNTRUSTED, 3, false, ContextPositionHint::TAIL, ContextSensitivity::RESTRICTED); }
    public function name(): string { return 'agent_directive'; }
}

final class AuthorStyleProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('author_style', 'author.style', ContextAuthority::USER, 2, false); }
    public function name(): string { return 'author_style'; }
}

final class SkillContextProvider extends LegacySectionContextProvider
{
    public function __construct() { parent::__construct('skill_context', 'skill.context', ContextAuthority::PLATFORM, 1, false, ContextPositionHint::SYSTEM); }
    public function name(): string { return 'skill_context'; }
}
