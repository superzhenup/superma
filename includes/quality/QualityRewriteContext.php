<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Current-chapter requirements and neighbouring boundaries for a repair prompt. */
final class QualityRewriteContext
{
    public static function build(array $chapter, array $novel, ?array $previous = null, ?array $next = null): string
    {
        $points = $chapter['key_points'] ?? [];
        if (is_string($points)) {
            $decoded = json_decode($points, true);
            if (is_array($decoded)) $points = $decoded;
        }
        $text = static fn($value): string => is_array($value)
            ? (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '')
            : trim((string)($value ?? ''));
        $blocks = [
            '【本章完整大纲——修复依据，不得以跑偏原稿替代】' . "\n" . ($text($chapter['outline'] ?? '') ?: '未提供'),
            '【本章完整关键情节点】' . "\n" . ($text($points) ?: '未提供'),
        ];
        $settings = [];
        foreach (['protagonist_name' => '主角', 'protagonist_info' => '人物设定', 'world_settings' => '世界设定',
                  'plot_settings' => '情节设定', 'extra_settings' => '补充设定'] as $key => $label) {
            if ($text($novel[$key] ?? '') !== '') $settings[] = $label . '：' . $text($novel[$key]);
        }
        if ($settings !== []) $blocks[] = '【已有设定】' . "\n" . implode("\n", $settings);
        if ($previous && trim((string)($previous['content'] ?? '')) !== '') {
            $blocks[] = '【前章已发生事实——仅供衔接】' . "\n"
                . '第' . (int)$previous['chapter_number'] . '章正文结尾：' . "\n"
                . mb_substr(trim((string)$previous['content']), -1800);
        }
        if ($next) {
            // Outline/key points are future-plot fences. Completed next-chapter
            // prose must not enter this prompt: historical rewrite would leak
            // already-written events into chapter N.
            $blocks[] = '【后章边界——禁止提前兑现或改变】' . "\n"
                . '第' . (int)$next['chapter_number'] . '章大纲：' . $text($next['outline'] ?? '') . "\n"
                . '情节点：' . $text($next['key_points'] ?? []);
        }
        if ((int)($novel['target_chapters'] ?? 0) > 0
            && (int)($chapter['chapter_number'] ?? 0) >= (int)$novel['target_chapters']) {
            $blocks[] = '【最终章】完成收束与主题回响，不再新开悬念或冲突。';
        }
        return implode("\n\n", $blocks);
    }
}
