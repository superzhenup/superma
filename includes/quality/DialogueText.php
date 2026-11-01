<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Equivalent paired quotation styles for lightweight writing diagnostics. */
final class DialogueText
{
    private const PAIRS = '~“[^“”]*”|「[^「」]*」|『[^『』]*』|"[^"\r\n]*"|‘[^‘’]*’|(?<![\p{L}\p{N}])\x27[^\x27\r\n]+\x27(?![\p{L}\p{N}])~u';

    public static function passages(string $text): array
    {
        preg_match_all(self::PAIRS, $text, $matches);
        return $matches[0] ?? [];
    }

    public static function beginsDialogue(string $text): bool
    {
        $text = preg_replace('/^\s+/u', '', $text) ?? $text;
        $passages = self::passages($text);
        return $passages !== [] && str_starts_with($text, $passages[0]);
    }

    public static function endsSentence(string $text): bool
    {
        return preg_match('~[。！？.!?][」』”’"\x27）)\]]*\s*$~u', $text) === 1;
    }

    public static function visibleLength(string $text): int
    {
        return mb_strlen(preg_replace('/\s+/u', '', $text) ?? $text);
    }
}
