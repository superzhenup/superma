<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Read-only progress based on saved outline content, including holes in a batch. */
final class ChapterOutlineProgress
{
    public static function read(PDO $db, int $novelId, int $target, ?int $start = null, ?int $end = null): array
    {
        $statement = $db->prepare("SELECT chapter_number FROM chapters
            WHERE novel_id=? AND outline IS NOT NULL AND TRIM(outline)<>'' ORDER BY chapter_number");
        $statement->execute([$novelId]);
        return self::summarize($statement->fetchAll(PDO::FETCH_COLUMN), $target, $start, $end);
    }

    public static function summarize(array $numbers, int $target, ?int $start = null, ?int $end = null): array
    {
        if ($target < 1) throw new InvalidArgumentException('小说目标章节数无效');
        if (($start === null) !== ($end === null)
            || ($start !== null && ($start < 1 || $end < $start || $end > $target || $end - $start >= 1000))) {
            throw new InvalidArgumentException('章节进度查询范围无效');
        }
        $saved = [];
        foreach ($numbers as $number) {
            $number = filter_var($number, FILTER_VALIDATE_INT);
            if ($number !== false && $number > 0) $saved[$number] = true;
        }
        $contiguous = 0;
        while ($contiguous < $target && isset($saved[$contiguous + 1])) $contiguous++;
        $result = [
            'outlined' => count($saved),
            'total' => $target,
            'last_outlined' => $saved ? max(array_keys($saved)) : 0,
            'contiguous_outlined' => $contiguous,
        ];
        if ($start !== null) {
            $rangeLast = $start - 1;
            while ($rangeLast < $end && isset($saved[$rangeLast + 1])) $rangeLast++;
            $rangeCount = 0;
            for ($chapter = $start; $chapter <= $end; $chapter++) {
                if (isset($saved[$chapter])) $rangeCount++;
            }
            $result += [
                'range_start' => $start,
                'range_end' => $end,
                'range_last_outlined' => $rangeLast,
                'range_saved' => $rangeCount,
                'range_complete' => $rangeLast === $end,
            ];
        }
        return $result;
    }
}
