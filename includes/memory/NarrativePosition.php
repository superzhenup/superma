<?php
defined('APP_LOADED') or die('Direct access denied.');

/** Story position belongs to the chapter being written, not the job completion count. */
final class NarrativePosition
{
    public static function forChapter(int $chapterNumber, int $targetChapters): array
    {
        $before = min(max(0, $targetChapters), max(0, $chapterNumber - 1));
        return [
            'progress_pct' => $targetChapters > 0 ? (int)floor(($before * 100) / $targetChapters) : 0,
            'remaining_chapters' => max(0, $targetChapters - $before),
        ];
    }

    public static function currentAct(array $division, int $chapterNumber, int $targetChapters): string
    {
        $hasRanges = false;
        foreach (['act1', 'act2', 'act3'] as $key) {
            $range = $division[$key]['chapters'] ?? '';
            if (!is_scalar($range) || !preg_match('/(\d+)\s*(?:-|—|–|~|～|至|到)\s*(\d+)/u', (string)$range, $m)) continue;
            $from = (int)$m[1];
            $to = (int)$m[2];
            if ($from < 1 || $to < $from) continue;
            $hasRanges = true;
            if ($chapterNumber >= $from && $chapterNumber <= $to) return $key;
        }
        // A gap in an explicit division is not permission to invent another act.
        if ($hasRanges || $targetChapters <= 0) return '';
        $pct = max(1, $chapterNumber) / $targetChapters;
        return $pct <= 0.2 ? 'act1' : ($pct <= 0.8 ? 'act2' : 'act3');
    }

    public static function phase(string $act): string
    {
        return ['act1' => '第一幕（开局建立期）', 'act2' => '第二幕（发展对抗期）', 'act3' => '第三幕（高潮收束期）'][$act] ?? '';
    }
}
