<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/harness/persistence/HarnessPersistenceSupport.php';

final class ChapterSynopsisSourceChangedException extends RuntimeException {}

/** Keep the previous blueprint until an unchanged source can be replaced in one commit. */
final class ChapterSynopsisPersistence
{
    private HarnessPersistenceDriver $db;
    private ?PDO $pdo;
    private array $baseline = [];
    private const SOURCE_FIELDS = ['id', 'novel_id', 'chapter_number', 'title', 'outline', 'key_points',
        'hook', 'pacing', 'suspense', 'hook_type', 'cool_point_type', 'status', 'canonical_state',
        'content', 'content_revision_hash', 'synopsis_id', 'updated_at'];
    private const FIELDS = ['synopsis', 'scene_breakdown', 'dialogue_beats', 'sensory_details',
        'pacing', 'cliffhanger', 'foreshadowing', 'callbacks'];
    private const NOVEL_SOURCE_FIELDS = ['id', 'user_id', 'title', 'genre', 'style', 'writing_style', 'chapter_words',
        'protagonist_name', 'protagonist_info', 'plot_settings', 'world_settings', 'extra_settings', 'model_id'];

    public function __construct(PDO|HarnessPersistenceDriver $connection, private int $userId, private int $novelId)
    {
        if ($userId < 1 || $novelId < 1) throw new InvalidArgumentException('Invalid synopsis owner.');
        $this->pdo = $connection instanceof PDO ? $connection : null;
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    /** Return the exact chapter and story outline used by the prompt, without holding a model-time lock. */
    public function capture(int $number, bool $force, bool $requireStory = true): array
    {
        $this->assertOwnTransaction();
        $snapshot = $this->db->transactional(function () use ($number, $force, $requireStory): array {
            [$chapter, $synopsis, $novel] = $this->lockedRows($number);
            if (!in_array($chapter['status'], ['outlined', 'writing', 'completed'], true)) {
                throw new ChapterSynopsisSourceChangedException('Chapter is not ready for a synopsis.');
            }
            if (!$force && $synopsis !== null && trim((string)($synopsis['synopsis'] ?? '')) !== '') {
                throw new ChapterSynopsisSourceChangedException('Chapter already has a synopsis.');
            }
            $story = $this->db->fetchOne('SELECT * FROM story_outlines WHERE novel_id=? FOR UPDATE', [$this->novelId]);
            if ($requireStory && $story === null) throw new ChapterSynopsisSourceChangedException('Story outline no longer exists.');
            return ['chapter' => $chapter, 'synopsis' => $synopsis, 'story' => $story, 'novel' => $novel];
        });
        $this->baseline[$number] = self::revision($snapshot);
        return $snapshot;
    }

    /** A false return is an edit conflict; failed writes roll back the row and its chapter link. */
    public function saveGenerated(int $number, array $values, ?string $expectedRevision = null): bool
    {
        if ($expectedRevision !== null && preg_match('/^[a-f0-9]{64}$/D', $expectedRevision) !== 1) throw new InvalidArgumentException('Invalid synopsis source revision.');
        $expectedRevision ??= $this->baseline[$number] ?? null;
        if ($expectedRevision === null) throw new LogicException('Synopsis source was not captured.');
        $this->validateFields($values);
        $this->assertOwnTransaction();
        try {
            $saved = $this->db->transactional(function () use ($number, $values, $expectedRevision): bool {
                [$chapter, $synopsis, $novel] = $this->lockedRows($number);
                $story = $this->db->fetchOne('SELECT * FROM story_outlines WHERE novel_id=? FOR UPDATE', [$this->novelId]);
                if (!hash_equals($expectedRevision, self::revision(['chapter'=>$chapter, 'synopsis'=>$synopsis, 'story'=>$story, 'novel'=>$novel]))) return false;
                $this->write($number, $chapter, $synopsis, $values);
                return true;
            });
        } catch (ChapterSynopsisSourceChangedException $error) {
            return false;
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1062) return false;
            throw $error;
        }
        if ($saved) unset($this->baseline[$number]);
        return $saved;
    }

    public function sourceRevision(int $number): string
    {
        return $this->baseline[$number] ?? throw new LogicException('Synopsis source was not captured.');
    }

    /** Manual edits take the same parent lock, including the first synopsis INSERT. */
    public function saveManual(int $number, array $values): void
    {
        $this->validateFields($values);
        $this->assertOwnTransaction();
        $this->db->transactional(function () use ($number, $values): void {
            [$chapter, $synopsis] = $this->lockedRows($number);
            $this->write($number, $chapter, $synopsis, $values);
        });
    }

    private function lockedRows(int $number): array
    {
        // Match outline publication's chapter -> novel -> synopsis lock order.
        $chapter = $this->db->fetchOne('SELECT * FROM chapters WHERE novel_id=? AND chapter_number=? FOR UPDATE', [$this->novelId, $number]);
        if ($chapter === null) throw new ChapterSynopsisSourceChangedException('Chapter no longer exists.');
        $novel = $this->db->fetchOne('SELECT * FROM novels WHERE id=? AND user_id=? FOR UPDATE', [$this->novelId, $this->userId]);
        if ($novel === null) {
            throw new ChapterSynopsisSourceChangedException('Synopsis novel ownership changed.');
        }
        $synopsis = $this->db->fetchOne('SELECT * FROM chapter_synopses WHERE novel_id=? AND chapter_number=? FOR UPDATE', [$this->novelId, $number]);
        return [$chapter, $synopsis, $novel];
    }

    private function write(int $number, array $chapter, ?array $synopsis, array $values): void
    {
        if ($synopsis === null) {
            $row = $values + ['novel_id'=>$this->novelId, 'chapter_number'=>$number];
            $this->db->execute('INSERT INTO chapter_synopses (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
            $synopsis = $this->db->fetchOne('SELECT * FROM chapter_synopses WHERE novel_id=? AND chapter_number=? FOR UPDATE', [$this->novelId, $number]);
            if ($synopsis === null) throw new RuntimeException('Saved synopsis disappeared.');
        } else {
            $sets = implode(',', array_map(static fn(string $field): string => $field . '=?', array_keys($values)));
            $this->db->execute('UPDATE chapter_synopses SET ' . $sets . ' WHERE id=? AND novel_id=?', array_merge(array_values($values), [(int)$synopsis['id'], $this->novelId]));
        }
        $this->db->execute('UPDATE chapters SET synopsis_id=? WHERE id=? AND novel_id=?', [(int)$synopsis['id'], (int)$chapter['id'], $this->novelId]);
    }

    private function validateFields(array $values): void
    {
        if (array_diff(array_keys($values), self::FIELDS) !== [] || !is_string($values['synopsis'] ?? null) || trim($values['synopsis']) === '') {
            throw new InvalidArgumentException('Synopsis content is required.');
        }
    }

    private function assertOwnTransaction(): void
    {
        if ($this->pdo?->inTransaction()) throw new LogicException('Synopsis publication requires its own transaction.');
    }

    private static function revision(array $snapshot): string
    {
        $snapshot['chapter'] = array_replace(array_fill_keys(self::SOURCE_FIELDS, null),
            array_intersect_key($snapshot['chapter'], array_flip(self::SOURCE_FIELDS)));
        $snapshot['novel'] = array_replace(array_fill_keys(self::NOVEL_SOURCE_FIELDS, null),
            array_intersect_key($snapshot['novel'], array_flip(self::NOVEL_SOURCE_FIELDS)));
        return hash('sha256', HarnessPersistenceValue::canonicalJson($snapshot));
    }
}

/** Strict model-output validation; the permissive shared parser remains compatible with legacy callers. */
final class ChapterSynopsisOutput
{
    public static function parse(string $raw, int $number, bool $partial = false): array
    {
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $match)) $raw = $match[1];
        $raw = trim($raw);
        $start = strpos($raw, '{');
        if ($start !== false) $raw = substr($raw, $start);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['synopsis'] ?? null) || trim($decoded['synopsis']) === '') {
            throw new InvalidArgumentException('Model synopsis is empty.');
        }
        if ((!$partial || isset($decoded['chapter_number'])) && filter_var($decoded['chapter_number'] ?? null, FILTER_VALIDATE_INT) !== $number) {
            throw new InvalidArgumentException('Model synopsis belongs to a different chapter.');
        }
        $values = ['synopsis'=>trim($decoded['synopsis'])];
        foreach (['scene_breakdown', 'dialogue_beats', 'sensory_details', 'foreshadowing', 'callbacks'] as $field) {
            if ($partial && !array_key_exists($field, $decoded)) continue;
            $items = $decoded[$field] ?? [];
            if (!is_array($items) || ($field !== 'sensory_details' && !array_is_list($items))) {
                throw new InvalidArgumentException('Invalid synopsis structure: ' . $field);
            }
            foreach ($items as $item) {
                if ($field === 'scene_breakdown') {
                    if (!is_array($item) || !is_string($item['action'] ?? null) || trim($item['action']) === '') {
                        throw new InvalidArgumentException('Invalid synopsis scene.');
                    }
                    foreach (['location', 'emotion', 'purpose'] as $key) {
                        if (isset($item[$key]) && !is_string($item[$key])) throw new InvalidArgumentException('Invalid scene field.');
                    }
                    if (isset($item['characters']) && (!is_array($item['characters']) || !array_is_list($item['characters'])
                        || array_filter($item['characters'], static fn($value): bool => !is_string($value)) !== [])) {
                        throw new InvalidArgumentException('Invalid scene characters.');
                    }
                } elseif (!is_string($item)) {
                    throw new InvalidArgumentException('Invalid synopsis detail.');
                }
            }
            $values[$field] = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        foreach (['pacing'=>'中', 'cliffhanger'=>''] as $field => $default) {
            if ($partial && !array_key_exists($field, $decoded)) continue;
            if (isset($decoded[$field]) && !is_string($decoded[$field])) throw new InvalidArgumentException('Invalid synopsis text field.');
            $values[$field] = $decoded[$field] ?? $default;
        }
        return $values;
    }

    /** Optimization is a patch: absence preserves data; an explicit empty list clears it. */
    public static function parseOptimization(string $raw, int $number): array
    {
        $values = self::parse($raw, $number, true);
        foreach (['scene_breakdown', 'dialogue_beats', 'sensory_details', 'foreshadowing', 'callbacks'] as $field) {
            if (array_key_exists($field, $values)) $values[$field] = json_decode($values[$field], true, 512, JSON_THROW_ON_ERROR);
        }
        return $values;
    }
}
