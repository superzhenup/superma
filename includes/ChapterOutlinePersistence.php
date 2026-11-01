<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/harness/persistence/HarnessPersistenceSupport.php';

final class ChapterOutlineSourceChangedException extends RuntimeException {}

/** A model may replace only the exact outline/blueprint it observed before dispatch. */
final class ChapterOutlinePersistence
{
    private HarnessPersistenceDriver $db;
    private ?PDO $pdo;
    /** @var array<int,array{chapter:?string,synopsis:?string}> */
    private array $baseline = [];
    private array $conflicts = [];
    private ?array $optimizationBase = null;
    private array $optimizationNumbers = [];
    private array $optimizationContextNumbers = [];
    private const NOVEL_SOURCE_FIELDS = ['id', 'user_id', 'title', 'genre', 'writing_style',
        'protagonist_info', 'plot_settings', 'world_settings', 'extra_settings', 'model_id'];
    private const FIELDS = ['id', 'novel_id', 'chapter_number', 'title', 'outline', 'key_points',
        'hook', 'pacing', 'suspense', 'hook_type', 'cool_point_type', 'status', 'canonical_state',
        'content', 'content_revision_hash', 'synopsis_id', 'updated_at'];
    private const WRITABLE = ['title', 'outline', 'key_points', 'hook', 'pacing', 'suspense',
        'hook_type', 'cool_point_type', 'status'];

    public function __construct(PDO|HarnessPersistenceDriver $connection, private int $userId, private int $novelId)
    {
        if ($userId < 1 || $novelId < 1) throw new InvalidArgumentException('Invalid outline owner.');
        $this->pdo = $connection instanceof PDO ? $connection : null;
        $this->db = HarnessPersistenceValue::driver($connection);
    }

    /** Freeze absent chapters too, so a concurrent insert never becomes our update. */
    public function capture(int $from, int $to): void
    {
        if ($from < 1 || $to < $from || $to - $from >= 100) throw new InvalidArgumentException('Invalid outline range.');
        if ($this->baseline !== []) throw new LogicException('Outline base is already frozen.');
        $this->assertOwner();
        $chapters = $this->db->fetchAll('SELECT * FROM chapters WHERE novel_id=? AND chapter_number BETWEEN ? AND ? ORDER BY chapter_number', [$this->novelId, $from, $to]);
        $synopses = $this->db->fetchAll('SELECT * FROM chapter_synopses WHERE novel_id=? AND chapter_number BETWEEN ? AND ? ORDER BY chapter_number', [$this->novelId, $from, $to]);
        $chapterMap = array_column($chapters, null, 'chapter_number');
        $synopsisMap = array_column($synopses, null, 'chapter_number');
        for ($number = $from; $number <= $to; ++$number) {
            $this->baseline[$number] = self::revision($chapterMap[$number] ?? null, $synopsisMap[$number] ?? null);
        }
    }

    /** False is a source/ownership conflict. Errors roll back outline and synopsis together. */
    public function save(int $number, array $values): bool
    {
        if (!isset($this->baseline[$number])) throw new LogicException('Outline chapter was not frozen before generation.');
        if (array_diff(array_keys($values), self::WRITABLE) !== [] || ($values['status'] ?? null) !== 'outlined') {
            throw new InvalidArgumentException('Invalid generated outline fields.');
        }
        if ($this->pdo?->inTransaction()) throw new LogicException('Outline publication requires its own transaction.');
        $expected = $this->baseline[$number];
        try {
            $published = $this->db->transactional(function () use ($number, $values, $expected): ?array {
                // The chapter/range lock prevents changes between comparison and publication.
                $chapter = $this->chapter($number, true);
                if (!$this->assertOwner(true)) return ['conflict' => true];
                $synopsis = $this->db->fetchOne('SELECT * FROM chapter_synopses WHERE novel_id=? AND chapter_number=? FOR UPDATE', [$this->novelId, $number]);
                if (self::revision($chapter, $synopsis) !== $expected) return ['conflict' => true];
                if ($chapter !== null && (!in_array($chapter['status'], ['pending', 'outlined', 'skipped', 'failed'], true)
                    || ($chapter['canonical_state'] ?? 'none') !== 'none')) return null;

                // A blueprint is derived from every outline field, not only its prose.
                $values['synopsis_id'] = null;
                if ($chapter === null) {
                    $insert = $values + ['novel_id' => $this->novelId, 'chapter_number' => $number];
                    $columns = implode(',', array_keys($insert));
                    $this->db->execute('INSERT INTO chapters (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($insert), '?')) . ')', array_values($insert));
                } else {
                    $sets = implode(',', array_map(static fn(string $field): string => $field . '=?', array_keys($values)));
                    $this->db->execute('UPDATE chapters SET ' . $sets . ' WHERE id=? AND novel_id=?', array_merge(array_values($values), [(int)$chapter['id'], $this->novelId]));
                }
                // Under READ COMMITTED an absent child has no gap lock. A manual
                // INSERT after our comparison belongs to its author, not this run.
                if ($synopsis !== null) {
                    $this->db->execute('DELETE FROM chapter_synopses WHERE id=? AND novel_id=? AND chapter_number=?',
                        [(int)$synopsis['id'], $this->novelId, $number]);
                }
                $saved = $this->chapter($number, true);
                if ($saved === null) throw new RuntimeException('Published outline disappeared.');
                return self::revision($saved, null);
            });
        } catch (PDOException $error) {
            // On READ COMMITTED a missing-row read need not gap-lock. A competing
            // insert wins the unique key; it must never be retried as an UPDATE.
            if ((int)($error->errorInfo[1] ?? 0) === 1062) { $this->conflicts[$number] = $number; return false; }
            throw $error;
        }
        if ($published === null) return false;
        if (isset($published['conflict'])) { $this->conflicts[$number] = $number; return false; }
        // Advance only after commit. Legacy enhancement may replace our own base,
        // but any edit after this save still causes the next comparison to fail.
        $this->baseline[$number] = $published;
        return true;
    }

    public function conflicts(): array { return array_values($this->conflicts); }

    /** Capture the exact prompt inputs and publication baseline in one short transaction. */
    public function captureOptimizationBatch(array $numbers, array $contextNumbers = []): array
    {
        if ($this->optimizationBase !== null || $this->baseline !== []) throw new LogicException('Outline base is already frozen.');
        foreach ([$numbers, $contextNumbers] as $list) {
            if (count($list) > 100 || array_filter($list, static fn($n): bool => !is_int($n) || $n < 1) !== []) {
                throw new InvalidArgumentException('Invalid optimization chapter list.');
            }
        }
        if ($numbers === [] || count(array_unique($numbers)) !== count($numbers)) throw new InvalidArgumentException('Invalid optimization targets.');
        if ($this->pdo?->inTransaction()) throw new LogicException('Outline capture requires its own transaction.');
        $this->optimizationNumbers = $numbers;
        $this->optimizationContextNumbers = array_values(array_diff(array_unique($contextNumbers), $numbers));
        $snapshot = $this->db->transactional(fn(): array => $this->lockedOptimizationSnapshot());
        foreach ($snapshot['chapters'] as $chapter) {
            if (!in_array($chapter['status'], ['pending', 'outlined', 'skipped', 'failed'], true)
                || ($chapter['canonical_state'] ?? 'none') !== 'none') {
                throw new ChapterOutlineSourceChangedException('Optimization chapter is no longer writable.');
            }
        }
        $this->optimizationBase = $snapshot;
        return $snapshot;
    }

    /** Publish the whole batch and its progress atomically, or retain every current row. */
    public function saveOptimizedBatch(array $updates, ?int $progressChapter = null): bool
    {
        if ($this->optimizationBase === null) throw new LogicException('Optimization sources were not captured.');
        $expectedNumbers = $this->optimizationNumbers;
        $receivedNumbers = array_keys($updates);
        sort($expectedNumbers, SORT_NUMERIC);
        sort($receivedNumbers, SORT_NUMERIC);
        if ($expectedNumbers !== $receivedNumbers) throw new InvalidArgumentException('Model output must cover the exact optimization batch.');
        foreach ($updates as $values) {
            if (!is_array($values) || array_diff(array_keys($values), self::WRITABLE) !== []
                || array_key_exists('status', $values) || !is_string($values['outline'] ?? null) || trim($values['outline']) === '') {
                throw new InvalidArgumentException('Invalid optimized outline fields.');
            }
        }
        if ($this->pdo?->inTransaction()) throw new LogicException('Outline publication requires its own transaction.');
        try {
            return $this->db->transactional(function () use ($updates, $progressChapter): bool {
                $current = $this->lockedOptimizationSnapshot();
                if ($this->optimizationRevision($current) !== $this->optimizationRevision($this->optimizationBase)) {
                    $this->conflicts = array_combine($this->optimizationNumbers, $this->optimizationNumbers);
                    return false;
                }
                foreach ($current['chapters'] as $chapter) {
                    $number = (int)$chapter['chapter_number'];
                    $values = $updates[$number] + ['synopsis_id' => null];
                    $sets = implode(',', array_map(static fn(string $field): string => $field . '=?', array_keys($values)));
                    $this->db->execute('UPDATE chapters SET ' . $sets . ' WHERE id=? AND novel_id=?', array_merge(array_values($values), [(int)$chapter['id'], $this->novelId]));
                    $synopsis = $current['synopses'][$number] ?? null;
                    if ($synopsis !== null) $this->db->execute('DELETE FROM chapter_synopses WHERE id=? AND novel_id=? AND chapter_number=?', [(int)$synopsis['id'], $this->novelId, $number]);
                }
                if ($progressChapter !== null) {
                    $this->db->execute('UPDATE novels SET optimized_chapter=? WHERE id=? AND user_id=?', [$progressChapter, $this->novelId, $this->userId]);
                }
                return true;
            });
        } catch (ChapterOutlineSourceChangedException $error) {
            $this->conflicts = array_combine($this->optimizationNumbers, $this->optimizationNumbers);
            return false;
        }
    }

    private function lockedOptimizationSnapshot(): array
    {
        $numbers = array_values(array_unique(array_merge($this->optimizationNumbers, $this->optimizationContextNumbers)));
        sort($numbers, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        // Same chapter -> novel -> synopsis lock order as the other publishers.
        $chapters = $this->db->fetchAll('SELECT * FROM chapters WHERE novel_id=? AND chapter_number IN (' . $placeholders . ') ORDER BY chapter_number FOR UPDATE', array_merge([$this->novelId], $numbers));
        if (count($chapters) !== count($numbers)) throw new ChapterOutlineSourceChangedException('Optimization chapter no longer exists.');
        $novel = $this->db->fetchOne('SELECT * FROM novels WHERE id=? AND user_id=? FOR UPDATE', [$this->novelId, $this->userId]);
        if ($novel === null) throw new ChapterOutlineSourceChangedException('Optimization ownership changed.');
        $synopses = $this->db->fetchAll('SELECT * FROM chapter_synopses WHERE novel_id=? AND chapter_number IN (' . $placeholders . ') ORDER BY chapter_number FOR UPDATE', array_merge([$this->novelId], $numbers));
        $story = $this->db->fetchOne('SELECT * FROM story_outlines WHERE novel_id=? FOR UPDATE', [$this->novelId]);
        if ($story === null) throw new ChapterOutlineSourceChangedException('Story outline no longer exists.');
        return ['novel' => $novel, 'story' => $story,
            'chapters' => array_values(array_filter($chapters, fn(array $row): bool => in_array((int)$row['chapter_number'], $this->optimizationNumbers, true))),
            'context' => array_values(array_filter($chapters, fn(array $row): bool => in_array((int)$row['chapter_number'], $this->optimizationContextNumbers, true))),
            'synopses' => array_column($synopses, null, 'chapter_number')];
    }

    private function optimizationRevision(array $snapshot): string
    {
        $snapshot['novel'] = array_replace(array_fill_keys(self::NOVEL_SOURCE_FIELDS, null), array_intersect_key($snapshot['novel'], array_flip(self::NOVEL_SOURCE_FIELDS)));
        foreach (['chapters', 'context'] as $key) {
            $snapshot[$key] = array_map(static fn(array $row): array => self::revision($row, null), $snapshot[$key]);
        }
        return hash('sha256', HarnessPersistenceValue::canonicalJson($snapshot));
    }

    private function chapter(int $number, bool $lock): ?array
    {
        return $this->db->fetchOne('SELECT * FROM chapters WHERE novel_id=? AND chapter_number=?' . ($lock ? ' FOR UPDATE' : ''), [$this->novelId, $number]);
    }

    private function assertOwner(bool $lock = false): bool
    {
        $owned = $this->db->fetchOne('SELECT id FROM novels WHERE id=? AND user_id=?' . ($lock ? ' FOR UPDATE' : ''), [$this->novelId, $this->userId]) !== null;
        if (!$owned && !$lock) throw new RuntimeException('Outline novel ownership changed.');
        return $owned;
    }

    private static function revision(?array $chapter, ?array $synopsis): array
    {
        $source = $chapter === null ? null : array_replace(array_fill_keys(self::FIELDS, null), array_intersect_key($chapter, array_flip(self::FIELDS)));
        return ['chapter' => $source === null ? null : hash('sha256', HarnessPersistenceValue::canonicalJson($source)),
            'synopsis' => $synopsis === null ? null : hash('sha256', HarnessPersistenceValue::canonicalJson($synopsis))];
    }
}
