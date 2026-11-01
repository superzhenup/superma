<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ToolExecutor.php';

interface StoryToolBackend
{
    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function read(
        string $toolName,
        ToolExecutionContext $context,
        array $arguments
    ): array;

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function propose(
        string $toolName,
        ToolExecutionContext $context,
        array $arguments
    ): array;
}

final class StoryToolCatalog
{
    /** @var list<string> */
    public const READ_TOOLS = [
        'story.get_state',
        'story.search_memory',
        'story.get_character_state',
        'story.get_chapter_outline',
        'story.get_due_foreshadows',
        'story.get_previous_tail',
        'story.validate_candidate',
    ];

    /** @var list<string> */
    public const PROPOSE_TOOLS = [
        'story.propose_outline_patch',
        'story.propose_character_update',
        'story.propose_foreshadow_resolution',
    ];

    public static function build(StoryToolBackend $backend): ToolRegistry
    {
        $registry = new ToolRegistry();
        foreach (self::definitions() as $definition) {
            $descriptor = new ToolDescriptor(
                $definition['name'],
                '1.0.0',
                $definition['description'],
                $definition['schema'],
                $definition['effect'],
                5000,
                16384
            );
            $registry->register($descriptor, new ClosureToolHandler(
                static function (
                    ToolExecutionContext $context,
                    array $arguments
                ) use ($backend, $descriptor): array {
                    return $descriptor->effect === ToolEffect::READ
                        ? $backend->read($descriptor->name, $context, $arguments)
                        : $backend->propose($descriptor->name, $context, $arguments);
                }
            ));
        }
        return $registry;
    }

    /** @return list<array{name:string,description:string,effect:ToolEffect,schema:array<string,mixed>}> */
    private static function definitions(): array
    {
        $object = static fn (array $properties = [], array $required = []): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $string = static fn (int $max = 500, int $min = 1): array => [
            'type' => 'string',
            'minLength' => $min,
            'maxLength' => $max,
        ];
        $integer = static fn (int $min, int $max): array => [
            'type' => 'integer',
            'minimum' => $min,
            'maximum' => $max,
        ];
        $uid = ['type' => 'string', 'pattern' => '/^[a-f0-9]{32}$/D'];
        $hash = ['type' => 'string', 'pattern' => '/^[a-f0-9]{64}$/D'];

        return [
            ['name' => 'story.get_state', 'description' => 'Read the authenticated novel and current story state.', 'effect' => ToolEffect::READ, 'schema' => $object()],
            ['name' => 'story.search_memory', 'description' => 'Search scoped memory atoms by a short query.', 'effect' => ToolEffect::READ, 'schema' => $object([
                'query' => $string(300), 'limit' => $integer(1, 20),
            ], ['query'])],
            ['name' => 'story.get_character_state', 'description' => 'Read one scoped character state card by exact name.', 'effect' => ToolEffect::READ, 'schema' => $object([
                'character_name' => $string(100),
            ], ['character_name'])],
            ['name' => 'story.get_chapter_outline', 'description' => 'Read one chapter outline from the authenticated novel.', 'effect' => ToolEffect::READ, 'schema' => $object([
                'chapter_number' => $integer(1, 1000000),
            ], ['chapter_number'])],
            ['name' => 'story.get_due_foreshadows', 'description' => 'Read unresolved foreshadows due by a chapter boundary.', 'effect' => ToolEffect::READ, 'schema' => $object([
                'before_chapter' => $integer(1, 1000000), 'limit' => $integer(1, 20),
            ], ['before_chapter'])],
            ['name' => 'story.get_previous_tail', 'description' => 'Read the canonical tail before a chapter.', 'effect' => ToolEffect::READ, 'schema' => $object([
                'before_chapter' => $integer(1, 1000000), 'max_chars' => $integer(100, 4000),
            ], ['before_chapter'])],
            ['name' => 'story.validate_candidate', 'description' => 'Run deterministic validation over a scoped Candidate.', 'effect' => ToolEffect::READ, 'schema' => $object([
                'candidate_uid' => $uid,
            ], ['candidate_uid'])],
            ['name' => 'story.propose_outline_patch', 'description' => 'Create an immutable outline patch proposal; does not update Canon.', 'effect' => ToolEffect::PROPOSE, 'schema' => $object([
                'chapter_number' => $integer(1, 1000000), 'base_revision_hash' => $hash,
                'replacement_outline' => $string(10000), 'reason' => $string(1000),
            ], ['chapter_number', 'base_revision_hash', 'replacement_outline', 'reason'])],
            ['name' => 'story.propose_character_update', 'description' => 'Create an immutable character-state proposal; does not write state.', 'effect' => ToolEffect::PROPOSE, 'schema' => $object([
                'character_name' => $string(100), 'base_revision_hash' => $hash,
                'field' => $string(64), 'proposed_value' => $string(4000), 'reason' => $string(1000),
            ], ['character_name', 'base_revision_hash', 'field', 'proposed_value', 'reason'])],
            ['name' => 'story.propose_foreshadow_resolution', 'description' => 'Create a foreshadow-resolution proposal; does not mark it resolved.', 'effect' => ToolEffect::PROPOSE, 'schema' => $object([
                'foreshadow_id' => $integer(1, PHP_INT_MAX), 'base_revision_hash' => $hash,
                'resolution' => $string(4000), 'reason' => $string(1000),
            ], ['foreshadow_id', 'base_revision_hash', 'resolution', 'reason'])],
        ];
    }
}

/** First-party scoped backend. Proposal methods only return data; they never UPDATE. */
final class DatabaseStoryToolBackend implements StoryToolBackend
{
    private HarnessPersistenceDriver $db;
    private ?Closure $candidateValidator;

    /** @param callable(array<string,mixed>):array<string,mixed>|null $candidateValidator */
    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        ?callable $candidateValidator = null
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->candidateValidator = $candidateValidator === null
            ? null
            : Closure::fromCallable($candidateValidator);
    }

    public function read(
        string $toolName,
        ToolExecutionContext $context,
        array $arguments
    ): array {
        $this->assertNovelOwnership($context);
        return match ($toolName) {
            'story.get_state' => $this->getState($context),
            'story.search_memory' => $this->searchMemory($context, $arguments),
            'story.get_character_state' => $this->getCharacter($context, $arguments),
            'story.get_chapter_outline' => $this->getOutline($context, $arguments),
            'story.get_due_foreshadows' => $this->getForeshadows($context, $arguments),
            'story.get_previous_tail' => $this->getPreviousTail($context, $arguments),
            'story.validate_candidate' => $this->validateCandidate($context, $arguments),
            default => throw new ToolNotRegisteredException("Unknown Story read Tool {$toolName}."),
        };
    }

    public function propose(
        string $toolName,
        ToolExecutionContext $context,
        array $arguments
    ): array {
        $this->assertNovelOwnership($context);
        if (!in_array($toolName, StoryToolCatalog::PROPOSE_TOOLS, true)) {
            throw new ToolNotRegisteredException("Unknown Story proposal Tool {$toolName}.");
        }
        if ($toolName === 'story.propose_foreshadow_resolution') {
            $row = $this->db->fetchOne(
                'SELECT id FROM foreshadowing_items WHERE id=? AND novel_id=? '
                    . 'AND resolved_chapter IS NULL',
                [(int)$arguments['foreshadow_id'], $context->novelId]
            );
            if ($row === null) {
                throw new ToolPolicyViolationException(
                    'Foreshadow proposal target does not belong to this novel or is already resolved.'
                );
            }
        }
        $payload = [
            'proposal_type' => $toolName,
            'run_uid' => $context->runUid,
            'user_id' => $context->userId,
            'novel_id' => $context->novelId,
            'chapter_id' => $context->chapterId,
            'arguments' => $arguments,
        ];
        return [
            'status' => 'proposed',
            'proposal_uid' => substr(hash(
                'sha256',
                'story-proposal-v1' . "\0" . HarnessPersistenceValue::canonicalJson($payload)
            ), 0, 32),
            'proposal' => $payload,
            'canon_updated' => false,
        ];
    }

    private function assertNovelOwnership(ToolExecutionContext $context): void
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM novels WHERE id=? AND user_id=?',
            [$context->novelId, $context->userId]
        );
        if ($row === null) {
            throw new ToolPolicyViolationException(
                'Tool execution novel does not belong to the authenticated user.'
            );
        }
        if ($context->chapterId !== null) {
            $chapter = $this->db->fetchOne(
                'SELECT id FROM chapters WHERE id=? AND novel_id=?',
                [$context->chapterId, $context->novelId]
            );
            if ($chapter === null) {
                throw new ToolPolicyViolationException(
                    'Tool execution chapter does not belong to the authenticated novel.'
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function getState(ToolExecutionContext $context): array
    {
        $novel = $this->db->fetchOne(
            'SELECT id,title,genre,status,target_chapters,total_words,updated_at '
                . 'FROM novels WHERE id=? AND user_id=?',
            [$context->novelId, $context->userId]
        );
        $state = $this->db->fetchOne(
            'SELECT story_momentum,current_location,location_chapter,'
                . 'location_transition,current_arc_summary,last_ingested_chapter,updated_at '
                . 'FROM novel_state WHERE novel_id=?',
            [$context->novelId]
        );
        return ['novel' => $novel ?? [], 'state' => $state ?? []];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function searchMemory(ToolExecutionContext $context, array $arguments): array
    {
        $limit = min(20, max(1, (int)($arguments['limit'] ?? 8)));
        $query = '%' . self::escapeLike((string)$arguments['query']) . '%';
        $rows = $this->db->fetchAll(
            'SELECT id,atom_type,content,source_chapter,confidence,created_at '
                . 'FROM memory_atoms WHERE novel_id=? AND content LIKE ? ESCAPE "\\\\" '
                . "ORDER BY confidence DESC,id DESC LIMIT {$limit}",
            [$context->novelId, $query]
        );
        return ['items' => $rows, 'count' => count($rows)];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function getCharacter(ToolExecutionContext $context, array $arguments): array
    {
        $row = $this->db->fetchOne(
            'SELECT id,name,title,status,alive,attributes,voice_profile,'
                . 'last_updated_chapter,updated_at FROM character_cards '
                . 'WHERE novel_id=? AND name=? LIMIT 1',
            [$context->novelId, (string)$arguments['character_name']]
        );
        return ['found' => $row !== null, 'character' => $row ?? []];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function getOutline(ToolExecutionContext $context, array $arguments): array
    {
        $row = $this->db->fetchOne(
            'SELECT id,chapter_number,title,outline,key_points,hook,pacing,suspense,'
                . 'hook_type,cool_point_type,status,content_revision_hash,updated_at '
                . 'FROM chapters WHERE novel_id=? AND chapter_number=? LIMIT 1',
            [$context->novelId, (int)$arguments['chapter_number']]
        );
        return ['found' => $row !== null, 'chapter' => $row ?? []];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function getForeshadows(ToolExecutionContext $context, array $arguments): array
    {
        $limit = min(20, max(1, (int)($arguments['limit'] ?? 10)));
        $rows = $this->db->fetchAll(
            'SELECT id,description,priority,planted_chapter,deadline_chapter,'
                . 'last_mentioned_chapter,mention_count,created_at '
                . 'FROM foreshadowing_items WHERE novel_id=? AND resolved_chapter IS NULL '
                . 'AND deadline_chapter IS NOT NULL AND deadline_chapter<=? '
                . "ORDER BY deadline_chapter ASC,id ASC LIMIT {$limit}",
            [$context->novelId, (int)$arguments['before_chapter']]
        );
        return ['items' => $rows, 'count' => count($rows)];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function getPreviousTail(ToolExecutionContext $context, array $arguments): array
    {
        $row = $this->db->fetchOne(
            'SELECT id,chapter_number,content,content_revision_hash,canonical_state '
                . 'FROM chapters WHERE novel_id=? AND chapter_number<? '
                . 'AND status="completed" AND content<>"" '
                . 'AND canonical_state IN ("legacy","canonical") '
                . 'AND content_revision_hash IS NOT NULL '
                . 'ORDER BY chapter_number DESC LIMIT 1',
            [$context->novelId, (int)$arguments['before_chapter']]
        );
        if ($row === null) {
            return ['found' => false, 'chapter' => []];
        }
        $content = $row['content'] ?? null;
        $revisionHash = $row['content_revision_hash'] ?? null;
        if (!is_string($content) || $content === ''
            || !is_string($revisionHash)
            || preg_match('/^[a-f0-9]{64}$/D', $revisionHash) !== 1
            || !hash_equals($revisionHash, hash('sha256', $content))) {
            throw new ToolPolicyViolationException(
                'Previous canonical chapter failed revision authentication.'
            );
        }
        $maxChars = min(4000, max(100, (int)($arguments['max_chars'] ?? 1200)));
        $tail = $content;
        $row['tail'] = function_exists('mb_substr')
            ? mb_substr($tail, -$maxChars, null, 'UTF-8')
            : substr($tail, -$maxChars);
        unset($row['content']);
        return ['found' => true, 'chapter' => $row];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function validateCandidate(ToolExecutionContext $context, array $arguments): array
    {
        $row = $this->db->fetchOne(
            'SELECT cc.candidate_uid,cc.chapter_id,cc.lifecycle,cc.gate_state,'
                . 'cc.content_hash,cc.words,cc.validation_json,cc.policy_snapshot_hash '
                . 'FROM chapter_candidates cc INNER JOIN chapters c ON c.id=cc.chapter_id '
                . 'INNER JOIN novels n ON n.id=cc.novel_id AND n.user_id=? '
                . 'WHERE cc.candidate_uid=? AND cc.novel_id=? AND c.novel_id=cc.novel_id LIMIT 1',
            [$context->userId, (string)$arguments['candidate_uid'], $context->novelId]
        );
        if ($row === null) {
            throw new ToolPolicyViolationException(
                'Candidate does not belong to the authenticated novel.'
            );
        }
        $validation = $this->candidateValidator === null
            ? [
                'deterministic_only' => true,
                'valid' => ($row['gate_state'] ?? null) === 'passed',
                'gate_state' => (string)($row['gate_state'] ?? ''),
            ]
            : ($this->candidateValidator)($row);
        if (!is_array($validation)) {
            throw new RuntimeException('Candidate validator must return an object array.');
        }
        return ['candidate' => $row, 'validation' => $validation];
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
