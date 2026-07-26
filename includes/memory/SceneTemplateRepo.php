<?php
defined('APP_LOADED') or die('Direct access denied.');

final class SceneTemplateRepo
{
    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    public function add(string $templateId, string $coolPointType, int $chapterNumber): int
    {
        return (int)DB::insert('novel_scene_templates', [
            'novel_id'        => $this->novelId,
            'chapter_number'  => $chapterNumber,
            'template_id'     => $templateId,
            'cool_point_type' => $coolPointType,
        ]);
    }

    public function batchAdd(array $templateIds, int $chapterNumber): int
    {
        $count = 0;
        foreach ($templateIds as $templateId) {
            $tpl = SCENE_TEMPLATES[$templateId] ?? null;
            if (!$tpl) continue;
            try {
                $this->add($templateId, $tpl['cool_type'], $chapterNumber);
                $count++;
            } catch (\Throwable $e) {
                error_log("SceneTemplateRepo::batchAdd failed for {$templateId}: " . $e->getMessage());
            }
        }
        return $count;
    }

    public function getTemplateHistory(): array
    {
        $rows = DB::fetchAll(
            'SELECT template_id, chapter_number FROM novel_scene_templates WHERE novel_id=? ORDER BY chapter_number ASC',
            [$this->novelId]
        );
        $history = [];
        foreach ($rows as $r) {
            $tid = $r['template_id'];
            if (!isset($history[$tid])) {
                $history[$tid] = [
                    'template_id' => $tid,
                    'use_count'   => 0,
                    'chapters'    => [],
                ];
            }
            $history[$tid]['use_count']++;
            $history[$tid]['chapters'][] = (int)$r['chapter_number'];
            $history[$tid]['last_chapter'] = (int)$r['chapter_number'];
        }
        return $history;
    }

    public function getExhaustedTemplates(): array
    {
        $history = $this->getTemplateHistory();
        $exhausted = [];
        foreach ($history as $tid => $info) {
            $tpl = SCENE_TEMPLATES[$tid] ?? null;
            if (!$tpl) continue;
            $maxUses = $tpl['max_uses'] ?? 0;
            if ($maxUses > 0 && $info['use_count'] >= $maxUses) {
                $exhausted[$tid] = [
                    'name'       => $tpl['name'],
                    'cool_type'  => $tpl['cool_type'],
                    'use_count'  => $info['use_count'],
                    'max_uses'   => $maxUses,
                    'last_chapter' => $info['last_chapter'],
                ];
            }
        }
        return $exhausted;
    }

    public function getRecentlyUsedTemplates(int $currentChapter, int $lookback = 20): array
    {
        $from = max(1, $currentChapter - $lookback);
        $rows = DB::fetchAll(
            'SELECT template_id, chapter_number FROM novel_scene_templates
             WHERE novel_id=? AND chapter_number>=? AND chapter_number<?
             ORDER BY chapter_number ASC',
            [$this->novelId, $from, $currentChapter]
        );
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'template_id'    => $r['template_id'],
                'chapter_number' => (int)$r['chapter_number'],
            ];
        }
        return $result;
    }

    public function getAlternativeTemplates(string $coolType, int $currentChapter): array
    {
        $history = $this->getTemplateHistory();
        $alternatives = [];

        foreach (SCENE_TEMPLATES as $tid => $tpl) {
            if ($tpl['cool_type'] !== $coolType) continue;

            $info = $history[$tid] ?? null;
            $useCount = $info['use_count'] ?? 0;
            $lastChapter = $info['last_chapter'] ?? 0;
            $maxUses = $tpl['max_uses'] ?? 0;
            $cooldown = $tpl['cooldown'] ?? 0;

            if ($maxUses > 0 && $useCount >= $maxUses) continue;
            if ($cooldown > 0 && $lastChapter > 0 && ($currentChapter - $lastChapter) < $cooldown) continue;

            $gap = $lastChapter > 0 ? $currentChapter - $lastChapter : 9999;
            $alternatives[] = [
                'template_id' => $tid,
                'name'        => $tpl['name'],
                'use_count'   => $useCount,
                'gap'         => $gap,
            ];
        }

        usort($alternatives, fn($a, $b) => $b['gap'] <=> $a['gap']);
        return $alternatives;
    }

    public function countUsage(string $templateId): int
    {
        $row = DB::fetch(
            'SELECT COUNT(*) as cnt FROM novel_scene_templates WHERE novel_id=? AND template_id=?',
            [$this->novelId, $templateId]
        );
        return (int)($row['cnt'] ?? 0);
    }
}
