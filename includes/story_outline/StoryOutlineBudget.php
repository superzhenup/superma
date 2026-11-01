<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Token limits for bounded story-outline generation. */
final class StoryOutlineBudget
{
    public const TOKEN_LIMIT = 200000;
    public const OUTPUT_LIMIT = 16384;
    public const INPUT_LIMIT = 65536;
    private const MODEL_FIELDS = ['id','name','model_name','api_url','max_tokens','temperature','thinking_enabled','capabilities'];

    public static function freezeModels(array $models): array
    {
        if ($models === []) throw new InvalidArgumentException('请先配置故事大纲模型。');
        $frozen = [];
        foreach ($models as $model) {
            $entry = array_intersect_key($model, array_flip(self::MODEL_FIELDS));
            $entry['max_tokens'] = min(self::OUTPUT_LIMIT, max(1, (int)($model['max_tokens'] ?? 4096)));
            // Thinking APIs disagree on whether hidden tokens share max_tokens.
            // This bounded workflow disables optional thinking until it has a total-token contract.
            $entry['thinking_enabled'] = 0;
            $entry['temperature'] = (float)($model['structured_temperature'] ?? 0.3);
            $frozen[] = $entry;
        }
        return self::normalizeFrozenModels($frozen);
    }

    /** Read older immutable snapshots without carrying retired pricing into new attempts. */
    public static function normalizeFrozenModels(array $models): array
    {
        $normalized = [];
        foreach ($models as $model) {
            $entry = array_intersect_key($model, array_flip(self::MODEL_FIELDS));
            ksort($entry, SORT_STRING);
            $entry['config_hash'] = hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR));
            $normalized[] = $entry;
        }
        return $normalized;
    }

    public static function reservation(array $model, array $messages): array
    {
        // UTF-8 bytes conservatively bound ordinary text tokenizers. Add framing overhead.
        $input = strlen(json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) + count($messages) * 64 + 1024;
        if ($input > self::INPUT_LIMIT) throw new StoryOutlineBudgetException('故事大纲输入超过单次预算，请先缩减设定或补充精简摘要。');
        $output = min(self::OUTPUT_LIMIT, max(1, (int)$model['max_tokens']));
        return ['input_tokens'=>$input,'output_tokens'=>$output];
    }

    public static function assertUsage(int $input, int $output): void
    {
        if ($input < 0 || $output < 0 || $input > 10000000 || $output > 10000000 - $input) {
            throw new StoryOutlineBudgetException('模型 usage 超出可记录范围。');
        }
    }
}

final class StoryOutlineBudgetException extends RuntimeException {}
