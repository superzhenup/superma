<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

final class StoryOutlineSource
{
    /** Completed chapters count even when their derived summaries are empty. Drafts do not. */
    public static function capture(PDO $db, int $novelId, bool $lock = false): array
    {
        $read = static function(string $sql,array $params) use ($db): array {
            $statement=$db->prepare($sql); $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        };
        $suffix=$lock?' FOR UPDATE':'';
        // Lock the completed range before counting when this is the publication guard.
        if ($lock) $read("SELECT id FROM chapters WHERE novel_id=? AND status='completed' ORDER BY chapter_number,id FOR UPDATE",[$novelId]);
        $stats=$read("SELECT COUNT(*) AS completed_count,COALESCE(MAX(chapter_number),0) AS last_chapter_number FROM chapters WHERE novel_id=? AND status='completed'",[$novelId])[0];
        $select="SELECT id,chapter_number,title,outline,chapter_summary,SHA2(COALESCE(content,''),256) AS content_revision FROM chapters WHERE novel_id=? AND status='completed'";
        $head=$read($select.' ORDER BY chapter_number ASC,id ASC LIMIT 50'.$suffix,[$novelId]);
        $tail=$read($select.' ORDER BY chapter_number DESC,id DESC LIMIT 20'.$suffix,[$novelId]);
        $chapters=[];
        foreach (array_merge($head,array_reverse($tail)) as $row) $chapters[(int)$row['id']]=$row;
        $chapters=array_values($chapters);
        usort($chapters,static fn(array $a,array $b): int=>[(int)$a['chapter_number'],(int)$a['id']]<=>[(int)$b['chapter_number'],(int)$b['id']]);
        $summaries=$read('SELECT volume_number,chapter_from,chapter_to,summary,updated_at FROM volume_summaries WHERE novel_id=? ORDER BY volume_number'.$suffix,[$novelId]);
        return ['selection_version'=>2,'chapters'=>$chapters,'progress'=>[
            'completed_count'=>(int)$stats['completed_count'],'last_chapter_number'=>(int)$stats['last_chapter_number']],
            'volume_summaries'=>$summaries];
    }
}
