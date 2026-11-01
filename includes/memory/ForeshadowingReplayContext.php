<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ForeshadowingRepo.php';

/** An uncommitted historical view: advance it after each extracted chapter, before the next AI call. */
final class ForeshadowingReplayContext
{
    private array $pending = [];
    private int $nextId = 1;

    public function __construct(private ForeshadowingRepo $repo, array $items, int $fromChapter)
    {
        foreach ($items as $item) {
            if ((int)$item['planted_chapter'] >= $fromChapter
                || ($item['resolved_chapter'] !== null && (int)$item['resolved_chapter'] < $fromChapter)) continue;
            $id = (int)$item['id'];
            $this->pending[$id] = $item;
            $this->nextId = max($this->nextId, $id + 1);
        }
    }

    public function forPrompt(): array
    {
        $items = array_values($this->pending);
        $rank = ['critical' => 0, 'major' => 1, 'minor' => 2];
        usort($items, static fn(array $a, array $b): int =>
            [$rank[$a['priority']] ?? 2, (int)$a['planted_chapter'], (int)$a['id']]
            <=> [$rank[$b['priority']] ?? 2, (int)$b['planted_chapter'], (int)$b['id']]);
        return array_slice($items, 0, 20);
    }

    /** Normalize resolved descriptions so the later ingest executes the already chosen matches. */
    public function advance(int $chapterNumber, array $summary): array
    {
        // MemoryEngine ingests newly planted items first, with minor priority.
        foreach ((array)($summary['new_foreshadowing'] ?? []) as $item) {
            $desc = trim((string)($item['desc'] ?? ''));
            if ($desc === '') continue;
            $id = $this->nextId++;
            $this->pending[$id] = [
                'id' => $id, 'description' => $desc, 'priority' => 'minor',
                'planted_chapter' => $chapterNumber, 'resolved_chapter' => null,
            ];
        }
        $resolved = [];
        foreach ((array)($summary['resolved_foreshadowing'] ?? []) as $desc) {
            if (!is_string($desc) || trim($desc) === '') continue;
            $id = $this->repo->matchPendingDescription($desc, array_values($this->pending));
            if ($id > 0) {
                $resolved[] = $this->pending[$id]['description'];
                unset($this->pending[$id]);
            }
        }
        $summary['resolved_foreshadowing'] = $resolved;
        return $summary;
    }
}
