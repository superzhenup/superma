<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

enum ContextAuthority: string
{
    case PLATFORM = 'platform';
    case CANON = 'canon';
    case OUTLINE = 'outline';
    case MEMORY = 'memory';
    case USER = 'user';
    case UNTRUSTED = 'untrusted';
}

enum ContextPositionHint: string
{
    case SYSTEM = 'system';
    case PREFIX = 'prefix';
    case MIDDLE = 'middle';
    case TAIL = 'tail';
}

enum ContextSensitivity: string
{
    case INTERNAL = 'internal';
    case RESTRICTED = 'restricted';
    case PUBLIC = 'public';
}

enum ContextValidity: string
{
    case VALID = 'valid';
    case STALE = 'stale';
    case INVALID = 'invalid';
}

final readonly class ContextItem
{
    /** @var list<string> */
    public array $tags;
    /** @var array<string,mixed> */
    public array $metadata;

    /**
     * Priority is P0..P4, represented by 0..4 (lower is more important).
     *
     * @param list<string> $tags
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $sourceType,
        public string $sourceId,
        public string $sourceRevision,
        public string $content,
        public string $contentHash,
        public int $priority,
        public bool $required,
        public ContextAuthority $authority,
        public int $estimatedTokens,
        public float $relevance,
        public float $freshness,
        public string $dedupKey,
        public ContextPositionHint $positionHint,
        public ContextSensitivity $sensitivity,
        array $tags = [],
        array $metadata = [],
        public ContextValidity $validity = ContextValidity::VALID,
        public ?string $validityReason = null
    ) {
        HarnessPersistenceValue::uid($id, 'context_item.id');
        HarnessPersistenceValue::identifier($kind, 'context_item.kind', 64);
        HarnessPersistenceValue::identifier($sourceType, 'context_item.source_type', 64);
        HarnessPersistenceValue::string($sourceId, 'context_item.source_id', 1, 191);
        HarnessPersistenceValue::string($sourceRevision, 'context_item.source_revision', 1, 191);
        HarnessPersistenceValue::hash($contentHash, 'context_item.content_hash');
        if (!hash_equals(hash('sha256', $content), $contentHash)) {
            throw new InvalidArgumentException('Context item content hash does not match its bytes.');
        }
        if ($priority < 0 || $priority > 4) {
            throw new InvalidArgumentException('Context item priority must be between P0 and P4.');
        }
        HarnessPersistenceValue::nonNegativeInt($estimatedTokens, 'context_item.estimated_tokens');
        self::assertScore($relevance, 'context_item.relevance');
        self::assertScore($freshness, 'context_item.freshness');
        HarnessPersistenceValue::string($dedupKey, 'context_item.dedup_key', 1, 191);
        HarnessSensitiveDataGuard::assertSafeLabel($kind, 'context_item.kind');
        HarnessSensitiveDataGuard::assertSafeLabel($sourceType, 'context_item.source_type');
        HarnessSensitiveDataGuard::assertSafeStructure($metadata, 'context_item.metadata');

        $normalizedTags = [];
        foreach ($tags as $tag) {
            $tag = HarnessPersistenceValue::identifier($tag, 'context_item.tag', 64);
            HarnessSensitiveDataGuard::assertSafeLabel($tag, 'context_item.tag');
            $normalizedTags[$tag] = $tag;
        }
        ksort($normalizedTags, SORT_STRING);
        $this->tags = array_values($normalizedTags);
        $this->metadata = $metadata;

        if ($validity === ContextValidity::VALID && $validityReason !== null) {
            throw new InvalidArgumentException('A valid Context item cannot carry a validity reason.');
        }
        if ($validity !== ContextValidity::VALID) {
            HarnessPersistenceValue::string(
                $validityReason,
                'context_item.validity_reason',
                1,
                500
            );
        }
    }

    /**
     * @param list<string> $tags
     * @param array<string,mixed> $metadata
     */
    public static function create(
        string $kind,
        string $sourceType,
        string $sourceId,
        string $sourceRevision,
        string $content,
        int $priority,
        bool $required,
        ContextAuthority $authority,
        int $estimatedTokens,
        float $relevance = 1.0,
        float $freshness = 1.0,
        ?string $dedupKey = null,
        ContextPositionHint $positionHint = ContextPositionHint::MIDDLE,
        ContextSensitivity $sensitivity = ContextSensitivity::INTERNAL,
        array $tags = [],
        array $metadata = [],
        ContextValidity $validity = ContextValidity::VALID,
        ?string $validityReason = null
    ): self {
        $contentHash = hash('sha256', $content);
        $dedupKey ??= implode(':', [$sourceType, $sourceId, $sourceRevision, $contentHash]);
        $id = substr(hash('sha256', implode("\0", [
            'context-item-v1',
            $kind,
            $sourceType,
            $sourceId,
            $sourceRevision,
            $contentHash,
        ])), 0, 32);

        return new self(
            $id,
            $kind,
            $sourceType,
            $sourceId,
            $sourceRevision,
            $content,
            $contentHash,
            $priority,
            $required,
            $authority,
            $estimatedTokens,
            $relevance,
            $freshness,
            $dedupKey,
            $positionHint,
            $sensitivity,
            $tags,
            $metadata,
            $validity,
            $validityReason
        );
    }

    /** @return array<string,mixed> Manifest-safe provenance without content. */
    public function manifestEntry(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_revision' => $this->sourceRevision,
            'content_hash' => $this->contentHash,
            'priority' => 'P' . $this->priority,
            'required' => $this->required,
            'authority' => $this->authority->value,
            'estimated_tokens' => $this->estimatedTokens,
            'relevance' => $this->relevance,
            'freshness' => $this->freshness,
            'dedup_key' => $this->dedupKey,
            'position_hint' => $this->positionHint->value,
            'sensitivity' => $this->sensitivity->value,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'validity' => $this->validity->value,
            'validity_reason' => $this->validityReason,
        ];
    }

    private static function assertScore(float $score, string $field): void
    {
        if (!is_finite($score) || $score < 0.0 || $score > 1.0) {
            throw new InvalidArgumentException("{$field} must be between 0 and 1.");
        }
    }
}
