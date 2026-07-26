<?php

defined('APP_LOADED') or die('Direct access denied.');

/**
 * 漫剧一致性 Prompt 组装（Drama Studio v1.8）。
 *
 * 跨镜一致性三层策略的核心层：资产 profile 块注入。
 * 组装顺序：风格前缀 → 出场角色描述块 → 场景描述块 → 景别/运镜 → 分镜画面 prompt。
 */
final class DramaPromptBuilder
{
    /** 分镜图最终 prompt。$assets 为出场角色/场景的 drama_assets 行。 */
    public static function buildImagePrompt(array $project, array $shot, array $assets): string
    {
        $parts = [];

        $style = trim((string)($project['style_prompt'] ?? ''));
        if ($style !== '') $parts[] = $style;

        $characterBlocks = [];
        $sceneBlocks = [];
        foreach ($assets as $asset) {
            $desc = trim((string)($asset['description'] ?? ''));
            if ($desc === '') continue;
            if (($asset['type'] ?? '') === 'character') {
                $characterBlocks[] = '角色' . $asset['name'] . '：' . $desc;
            } elseif (($asset['type'] ?? '') === 'scene') {
                $sceneBlocks[] = '场景' . $asset['name'] . '：' . $desc;
            }
        }
        if ($characterBlocks) {
            $parts[] = '【出场角色，必须保持外观一致】' . implode('；', $characterBlocks);
        }
        if ($sceneBlocks) {
            $parts[] = '【场景环境】' . implode('；', $sceneBlocks);
        }

        $camera = self::cameraLanguage($shot);
        if ($camera !== '') $parts[] = $camera;

        $imagePrompt = trim((string)($shot['image_prompt'] ?? ''));
        if ($imagePrompt !== '') $parts[] = $imagePrompt;

        return self::limit(implode('。', array_filter($parts)), 1500);
    }

    /** 视频（i2v）运动 prompt：运镜 + 运动描述。 */
    public static function buildVideoPrompt(array $project, array $shot): string
    {
        $parts = [];
        $movement = trim((string)($shot['camera_movement'] ?? ''));
        if ($movement !== '' && $movement !== '固定') {
            $parts[] = '镜头' . $movement;
        }
        $videoPrompt = trim((string)($shot['video_prompt'] ?? ''));
        if ($videoPrompt !== '') $parts[] = $videoPrompt;
        if (!$parts) $parts[] = '画面主体轻微动态，自然流畅';
        return self::limit(implode('，', $parts), 800);
    }

    /** 资产定妆照/参考图 prompt。 */
    public static function buildAssetPrompt(array $project, array $asset): string
    {
        $style = trim((string)($project['style_prompt'] ?? ''));
        $desc = trim((string)($asset['description'] ?? ''));
        $name = (string)($asset['name'] ?? '');

        $template = match ((string)($asset['type'] ?? '')) {
            'character' => '动漫角色设定图，{name}，全身正面立绘，纯白色背景，人物居中，细节清晰。{desc}',
            'scene'     => '场景概念设计图，{name}，无人物空镜，环境氛围完整。{desc}',
            'prop'      => '道具设定图，{name}，单个物体居中展示，纯白色背景。{desc}',
            default     => '{name}。{desc}',
        };
        $prompt = str_replace(['{name}', '{desc}'], [$name, $desc], $template);
        if ($style !== '') $prompt = $style . '。' . $prompt;
        return self::limit($prompt, 1000);
    }

    /** 项目负向提示词（空时给通用默认）。 */
    public static function negativePrompt(array $project): string
    {
        $neg = trim((string)($project['style_negative'] ?? ''));
        if ($neg !== '') return $neg;
        return '低画质，模糊，变形，多余肢体，五官错位，文字水印，logo';
    }

    private static function cameraLanguage(array $shot): string
    {
        $parts = [];
        $shotType = trim((string)($shot['shot_type'] ?? ''));
        if ($shotType !== '') $parts[] = $shotType;
        $movement = trim((string)($shot['camera_movement'] ?? ''));
        if ($movement !== '' && $movement !== '固定') $parts[] = $movement . '镜头';
        return $parts ? ('【镜头】' . implode('，', $parts)) : '';
    }

    private static function limit(string $text, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) : $text;
    }
}
