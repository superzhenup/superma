<?php
/**
 * 高阶向导 — 上下文构建
 *
 * 把当前小说的所有已确认设定组装为文本，注入到各阶段 AI 的 system prompt 中
 */
defined('APP_LOADED') or die('Direct access denied.');

if (!function_exists('buildFullWizardContext')) {

function buildFullWizardContext(int $novelId): array {
    $novel = DB::fetch("SELECT * FROM novels WHERE id = ?", [$novelId]);
    if (!$novel) return [];

    $ctx = [];

    $chapters = (int)($novel['target_chapters'] ?? 0);
    $wordsPer = (int)($novel['chapter_word_target'] ?? 0);
    $totalLine = ($chapters > 0 && $wordsPer > 0)
        ? "预计总字数：约 " . round($chapters * $wordsPer / 10000, 1) . " 万字（{$chapters} 章 × {$wordsPer} 字）\n"
        : '';
    $ctx['basic'] = "作品名：{$novel['title']}\n"
                  . "类型：{$novel['genre']}\n"
                  . "写作风格：{$novel['writing_style']}\n"
                  . "目标章节数：{$chapters}\n"
                  . ($wordsPer > 0 ? "单章目标字数：{$wordsPer}\n" : '')
                  . $totalLine
                  . "主角：{$novel['protagonist_name']}";

    if (!empty($novel['extra_settings'])) {
        $ctx['idea'] = "核心想法：\n{$novel['extra_settings']}";
    }

    $tagFields = [
        'narrative_structure','narrative_method','narrative_pov',
        'literary_genre','world_setting_era','novel_types','writing_tone',
        'protagonist_traits','core_conflicts','appeal_points','taboos',
        'opening_type','protagonist_entrance',
    ];
    $tagTexts = [];
    foreach ($tagFields as $f) {
        if (empty($novel[$f])) continue;
        $val = $novel[$f];
        if (is_string($val) && str_starts_with($val, '[')) {
            $arr = json_decode($val, true);
            if (is_array($arr)) $val = implode('、', $arr);
        }
        $label = match($f) {
            'narrative_structure' => '叙事结构',
            'narrative_method' => '叙事方法',
            'narrative_pov' => '叙事视角',
            'literary_genre' => '文学流派',
            'world_setting_era' => '世界设定',
            'novel_types' => '小说类型',
            'writing_tone' => '文风',
            'protagonist_traits' => '主角设定',
            'core_conflicts' => '核心冲突',
            'appeal_points' => '爽点',
            'taboos' => '禁忌',
            'opening_type' => '开篇类型',
            'protagonist_entrance' => '主角出场',
            default => $f,
        };
        $tagTexts[] = "{$label}：{$val}";
    }
    if (!empty($tagTexts)) {
        $ctx['tags'] = "设定标签：\n" . implode("\n", $tagTexts);
    }

    if (!empty($novel['custom_settings'])) {
        $ctx['tags'] = ($ctx['tags'] ?? '') . "\n自定义设定：\n{$novel['custom_settings']}";
    }

    $progress = DB::fetch("SELECT metadata FROM novel_wizard_progress WHERE novel_id = ?", [$novelId]);
    if ($progress && $progress['metadata']) {
        $meta = json_decode($progress['metadata'], true) ?: [];
        if (!empty($meta['planning_doc'])) {
            $ctx['planning'] = "策划文档：\n{$meta['planning_doc']}";
        }
        if (!empty($meta['world_doc'])) {
            $ctx['world'] = "世界观文档：\n{$meta['world_doc']}";
        }
    }

    if (!empty($novel['world_settings'])) {
        $ctx['world'] = ($ctx['world'] ?? '') . "\n世界观设定：\n{$novel['world_settings']}";
    }
    if (!empty($novel['plot_settings'])) {
        $ctx['planning'] = ($ctx['planning'] ?? '') . "\n情节设定：\n{$novel['plot_settings']}";
    }

    $characters = DB::fetchAll("SELECT * FROM character_cards WHERE novel_id = ? ORDER BY id", [$novelId]);
    if (!empty($characters)) {
        $charTexts = [];
        foreach ($characters as $c) {
            $attrs = !empty($c['attributes']) ? json_decode($c['attributes'], true) : [];
            $attrStr = is_array($attrs) ? implode('；', array_map(fn($k,$v) => "{$k}：{$v}", array_keys($attrs), array_values($attrs))) : '';
            $charTexts[] = "【{$c['name']}】" . ($c['title'] ? "（{$c['title']}）" : '') . " {$attrStr}";
        }
        $ctx['characters'] = "角色卡：\n" . implode("\n", $charTexts);
    }

    $volumes = DB::fetchAll("SELECT * FROM volume_outlines WHERE novel_id = ? ORDER BY volume_index", [$novelId]);
    if (!empty($volumes)) {
        $volTexts = [];
        foreach ($volumes as $v) {
            $volTexts[] = "第{$v['volume_index']}卷「{$v['title']}」：{$v['theme']}（{$v['chapter_from']}-{$v['chapter_to']}章）";
        }
        $ctx['volumes'] = "卷大纲：\n" . implode("\n", $volTexts);
    }

    return $ctx;
}

function wizardContextToText(array $ctx, array $includeKeys, int $maxChars = 3500): string {
    $text = '';
    foreach ($includeKeys as $key) {
        if (isset($ctx[$key]) && $ctx[$key] !== '') {
            $text .= $ctx[$key] . "\n\n";
        }
    }
    if (mb_strlen($text) > $maxChars) {
        $text = mb_substr($text, 0, $maxChars) . '...（已截断）';
    }
    return trim($text);
}

} // end function_exists guard
