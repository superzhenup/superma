<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

final class IncompleteChapterGenerationException extends RuntimeException {}

/** Completion evidence for chapter text, independent of word count or score. */
final class ChapterGenerationIntegrity
{
    private const INCOMPLETE_REASONS = [
        'length', 'max_tokens', 'max_output_tokens', 'silence_timeout',
        'canceled', 'cancelled', 'error', 'content_filter', 'incomplete',
    ];

    /** @return list<array{rule_id:string,message:string,evidence:array}> */
    public static function findings(?string $finishReason, ?string $generationError = null): array
    {
        $reason = strtolower(trim($finishReason ?? ''));
        $error = trim($generationError ?? '');
        if ($error === '' && !in_array($reason, self::INCOMPLETE_REASONS, true)) {
            // Some compatible providers omit finish_reason even on a clean
            // terminal event. Absence is not fabricated evidence of truncation.
            return [];
        }
        return [[
            'rule_id' => 'content.truncated',
            'message' => '模型生成未完整结束，不能以字数达标代替正文完整性。',
            'evidence' => [
                'finish_reason' => $reason !== '' ? $reason : null,
                'generation_error' => $error !== '' ? mb_substr($error, 0, 500) : null,
            ],
        ]];
    }

    public static function assertComplete(?string $finishReason, ?string $generationError = null): void
    {
        if (self::findings($finishReason, $generationError) !== []) {
            throw new IncompleteChapterGenerationException(
                '模型正文未完整结束（' . ($finishReason ?: 'stream_error') . '），需要重新生成。'
            );
        }
    }
}
