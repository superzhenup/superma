<?php
defined('APP_LOADED') or die('Direct access denied.');

/** Versioned fact summaries in the existing TEXT column; no schema migration. */
final class NarrativeSummary
{
    private const FORMAT = 'canon_narrative_summary_v1';

    public static function encode(string $kind, string $text, array $sources): string
    {
        ksort($sources, SORT_NUMERIC);
        return json_encode([
            'format' => self::FORMAT,
            'kind' => $kind,
            'text' => trim($text),
            'source_revisions' => $sources,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $value, string $kind): ?array
    {
        $data = json_decode($value, true);
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT
            || ($data['kind'] ?? '') !== $kind || !is_string($data['text'] ?? null)
            || trim($data['text']) === '' || !is_array($data['source_revisions'] ?? null)
            || $data['source_revisions'] === []) return null;
        foreach ($data['source_revisions'] as $chapter => $hash) {
            if (!ctype_digit((string)$chapter) || (int)$chapter < 1
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) return null;
        }
        return $data;
    }

    public static function covers(array $sources, int $from, int $to): bool
    {
        if ($from < 1 || $to < $from || count($sources) !== $to - $from + 1) return false;
        for ($chapter = $from; $chapter <= $to; $chapter++) {
            if (!isset($sources[$chapter])) return false;
        }
        return true;
    }

    public static function currentSources(int $novelId, int $from, int $to): array
    {
        $rows = DB::fetchAll(
            "SELECT chapter_number, SHA2(content, 256) AS body_hash FROM chapters
             WHERE novel_id=? AND chapter_number>=? AND chapter_number<=?
               AND status='completed' AND content IS NOT NULL AND content<>''",
            [$novelId, $from, $to]
        );
        $sources = [];
        foreach ($rows as $row) {
            $sources[(int)$row['chapter_number']] = (string)$row['body_hash'];
        }
        return $sources;
    }

    public static function matches(array $sources, array $current): bool
    {
        foreach ($sources as $chapter => $hash) {
            if (!isset($current[$chapter]) || !hash_equals($hash, $current[$chapter])) return false;
        }
        return true;
    }

    /** Reject old planning summaries and stale versions; return displayable text. */
    public static function filterCurrentRows(int $novelId, array $rows, string $kind, bool $includeSources = false): array
    {
        $candidates = [];
        $from = PHP_INT_MAX;
        $to = 0;
        foreach ($rows as $row) {
            $data = self::decode((string)($row['summary'] ?? ''), $kind);
            $rowFrom = (int)($row['chapter_from'] ?? 0);
            $rowTo = (int)($row['chapter_to'] ?? 0);
            if ($data === null || !self::covers($data['source_revisions'], $rowFrom, $rowTo)) continue;
            $candidates[] = [$row, $data];
            $from = min($from, $rowFrom);
            $to = max($to, $rowTo);
        }
        if (!$candidates) return [];
        $current = self::currentSources($novelId, $from, $to);
        $result = [];
        foreach ($candidates as [$row, $data]) {
            if (!self::matches($data['source_revisions'], $current)) continue;
            $row['summary'] = $data['text'];
            if ($includeSources) $row['source_revisions'] = $data['source_revisions'];
            $result[] = $row;
        }
        return $result;
    }
}
