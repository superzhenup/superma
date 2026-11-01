<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/**
 * Portable JSON backup 1.1. Missing/1.0 versions remain readable.
 * IDs are source-local references, never authority in the destination database.
 * 1.1 adds explicit synopsis links and preserves author-controlled writing fields.
 * Instance model/profile IDs, file paths, worker state and Candidate identity are
 * intentionally outside this contract. ref_author is portable text, not an ID.
 */
final class NovelBackupContract
{
    public const VERSION = '1.1';
    public const NOVEL_FIELDS = [
        'title', 'genre', 'writing_style', 'protagonist_name', 'protagonist_info',
        'plot_settings', 'world_settings', 'extra_settings', 'target_chapters',
        'chapter_words', 'has_story_outline', 'optimized_chapter', 'status',
        'current_chapter', 'total_words', 'cover_color', 'style_vector', 'ref_author',
        'target_reader', 'narrative_structure', 'narrative_method', 'narrative_pov',
        'literary_genre', 'world_setting_era', 'novel_types', 'writing_tone',
        'protagonist_traits', 'core_conflicts', 'appeal_points', 'taboos',
        'opening_type', 'protagonist_entrance', 'custom_settings', 'chapter_word_target',
    ];
    public const CHAPTER_FIELDS = [
        'chapter_number', 'title', 'outline', 'content', 'chapter_summary',
        'used_tropes', 'hook', 'status', 'words', 'pacing', 'suspense', 'key_points',
        'hook_type', 'cool_point_type', 'opening_type',
    ];
    private const TAG_FIELDS = [
        'novel_types', 'writing_tone', 'protagonist_traits', 'core_conflicts',
        'appeal_points', 'taboos',
    ];
    private const INTEGER_FIELDS = [
        'target_chapters', 'chapter_words', 'has_story_outline', 'optimized_chapter',
        'current_chapter', 'total_words', 'chapter_word_target', 'chapter_number', 'words',
    ];

    public static function assertVersion(array $data): void
    {
        if (array_key_exists('export_version', $data)
            && !in_array($data['export_version'], ['1.0', self::VERSION], true)) {
            throw new InvalidArgumentException('不支持的小说备份版本');
        }
    }

    /** Source ID is retained only to validate/remap child rows during restore. */
    public static function exportNovel(array $novel): array
    {
        return array_intersect_key($novel, array_flip(array_merge(['id'], self::NOVEL_FIELDS)));
    }

    public static function importNovel(array $novel): array
    {
        $payload = self::typedFields($novel, self::NOVEL_FIELDS);
        if (isset($payload['status']) && !in_array($payload['status'], ['draft', 'writing', 'paused', 'completed'], true)) {
            throw new InvalidArgumentException('小说状态无效');
        }
        // A restored book has no corresponding running task. Never resume paid
        // background generation solely because an uploaded backup requested it.
        if (($payload['status'] ?? '') === 'writing') $payload['status'] = 'paused';
        $payload['daemon_write'] = 0;
        return $payload;
    }

    public static function importChapter(array $chapter): array
    {
        $payload = self::typedFields($chapter, self::CHAPTER_FIELDS);
        if (!isset($payload['chapter_number']) || $payload['chapter_number'] <= 0) {
            throw new InvalidArgumentException('章节编号必须为正整数');
        }
        if (isset($payload['status']) && !in_array($payload['status'], ['pending', 'outlined', 'writing', 'completed', 'skipped', 'failed'], true)) {
            throw new InvalidArgumentException('章节状态无效');
        }
        if (($payload['status'] ?? '') === 'writing') {
            $payload['status'] = trim((string)($payload['outline'] ?? '')) !== '' ? 'outlined' : 'pending';
        }
        return $payload;
    }

    private static function typedFields(array $row, array $fields): array
    {
        $payload = array_intersect_key($row, array_flip($fields));
        foreach ($payload as $field => $value) {
            if ($value === null) continue;
            if (in_array($field, self::TAG_FIELDS, true)) {
                $tags = is_string($value) ? json_decode($value, true, 16, JSON_THROW_ON_ERROR) : $value;
                if ($tags !== null && (!is_array($tags) || !array_is_list($tags))) {
                    throw new InvalidArgumentException('备份标签字段必须为数组：' . $field);
                }
                foreach ($tags ?? [] as $tag) {
                    if (!is_string($tag)) throw new InvalidArgumentException('备份标签必须为文本：' . $field);
                }
                $payload[$field] = is_string($value) ? $value : json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif (in_array($field, self::INTEGER_FIELDS, true)) {
                if ((!is_int($value) && (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1))
                    || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0) {
                    throw new InvalidArgumentException('备份整数字段无效：' . $field);
                }
                $payload[$field] = (int)$value;
            } elseif (!is_string($value)) {
                throw new InvalidArgumentException('备份文本字段无效：' . $field);
            }
        }
        return $payload;
    }

    /** Reject mixed-book row sets instead of turning a foreign source ID into a local link. */
    public static function assertSourceNovel(array $row, array $sourceNovel): void
    {
        if (isset($row['novel_id'], $sourceNovel['id'])
            && (string)$row['novel_id'] !== (string)$sourceNovel['id']) {
            throw new InvalidArgumentException('备份包含其他小说的章节或概要');
        }
    }

    /**
     * Explicit null/zero/broken references stay unlinked. Only an absent key in
     * old backups permits inference, and only from a unique source chapter number.
     * Both indexes contain newly inserted synopses of this one restored novel.
     */
    public static function synopsisLink(array $chapter, array $byId, array $byChapter): ?int
    {
        if (array_key_exists('synopsis_id', $chapter)) {
            $sourceId = $chapter['synopsis_id'];
            if (!is_int($sourceId) && !is_string($sourceId)) return null;
            $targetId = $byId[(string)$sourceId] ?? null;
            $sameChapter = $byChapter[(int)($chapter['chapter_number'] ?? 0)] ?? [];
            return $targetId !== null && in_array($targetId, $sameChapter, true) ? $targetId : null;
        }
        $matches = $byChapter[(int)($chapter['chapter_number'] ?? 0)] ?? [];
        return count($matches) === 1 ? $matches[0] : null;
    }
}
