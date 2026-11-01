<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/** Pure TXT codec: v2 JSON strings make embedded line breaks and markers unambiguous. */
final class ChapterSynopsisTextFormat
{
    private const HEADER = 'AI-NOVEL-CHAPTER-SYNOPSES-TXT:2';
    private const FIELDS = ['大纲' => 'outline', '概要' => 'synopsis', '节奏' => 'pacing', '悬念' => 'cliffhanger'];

    public static function encode(array $novel, array $chapters): string
    {
        $quote = static fn(string $value): string => json_encode(
            $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $lines = [self::HEADER, '小说标题：' . $quote((string)($novel['title'] ?? '')),
            '导出时间：' . date('Y-m-d H:i:s'), '总章节数：' . count($chapters), ''];
        foreach ($chapters as $chapter) {
            $lines[] = '【第' . (int)$chapter['chapter_number'] . '章】' . $quote((string)($chapter['title'] ?? ''));
            foreach (self::FIELDS as $label => $field) {
                $lines[] = '【' . $label . '】' . $quote((string)($chapter[$field] ?? ($field === 'pacing' ? '中' : '')));
            }
            $lines[] = str_repeat('-', 40);
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    public static function decode(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_starts_with($text, 'AI-NOVEL-CHAPTER-SYNOPSES-TXT:')) {
            [$header, $body] = array_pad(explode("\n", $text, 2), 2, '');
            if ($header !== self::HEADER) throw new InvalidArgumentException('不支持的章节概要 TXT 版本');
            return self::decodeV2($body);
        }
        return self::decodeLegacy($text);
    }

    private static function decodeV2(string $text): array
    {
        $chapters = [];
        $current = null;
        $finish = static function () use (&$chapters, &$current): void {
            if ($current === null) return;
            foreach (self::FIELDS as $field) {
                if (!array_key_exists($field, $current)) throw new InvalidArgumentException('TXT 章节字段不完整');
            }
            $chapters[] = $current;
            $current = null;
        };
        $decodeString = static function (string $value): string {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_string($decoded)) throw new InvalidArgumentException('TXT 字段必须为 JSON 字符串');
            return $decoded;
        };
        foreach (explode("\n", $text) as $line) {
            if ($line === '' || $line === str_repeat('-', 40)) continue;
            if (preg_match('/^【第([1-9][0-9]*)章】(.*)$/D', $line, $match)) {
                $finish();
                $current = ['chapter_number' => (int)$match[1], 'title' => $decodeString($match[2])];
                continue;
            }
            if ($current === null && !$chapters && preg_match('/^(小说标题|导出时间|总章节数)：/', $line)) continue;
            if ($current !== null && preg_match('/^【(大纲|概要|节奏|悬念)】(.*)$/D', $line, $match)) {
                $field = self::FIELDS[$match[1]];
                if (array_key_exists($field, $current)) throw new InvalidArgumentException('TXT 章节字段重复');
                $current[$field] = $decodeString($match[2]);
                continue;
            }
            throw new InvalidArgumentException('无法识别的章节概要 TXT 内容');
        }
        $finish();
        return $chapters;
    }

    private static function decodeLegacy(string $text): array
    {
        // Only the old application's complete export header proves HTML encoding.
        // Handwritten TXT and v2 strings must retain literal entities such as &amp;.
        $exported = preg_match('/\A={40}\n小说标题：[^\n]*\n导出时间：\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\n总章节数：\d+\n={40}\n/', $text, $header) === 1;
        if ($exported) $text = substr($text, strlen($header[0]));
        $lines = explode("\n", $text);
        if (end($lines) === '') array_pop($lines); // Physical final newline is not field content.
        $chapters = [];
        $current = null;
        $field = null;
        $finish = static function () use (&$chapters, &$current, &$field, $exported): void {
            if ($current === null) return;
            if ($exported) {
                foreach (array_merge(['title'], array_values(self::FIELDS)) as $key) {
                    $current[$key] = htmlspecialchars_decode($current[$key], ENT_QUOTES);
                }
            }
            $chapters[] = $current;
            $current = null;
            $field = null;
        };
        foreach ($lines as $index => $line) {
            if (preg_match('/^【第([0-9]+)章】(.*)$/D', $line, $match)) {
                $finish();
                $current = ['chapter_number' => (int)$match[1], 'title' => $match[2],
                    'outline' => '', 'synopsis' => '', 'pacing' => '中', 'cliffhanger' => ''];
                continue;
            }
            if ($current === null) continue;
            if (preg_match('/^-{3,}$/D', $line)) {
                $next = $index + 1;
                while (isset($lines[$next]) && $lines[$next] === '') ++$next;
                // Keep literal separator lines followed by more prose.
                if (!isset($lines[$next]) || preg_match('/^【第[0-9]+章】/', $lines[$next])) {
                    // Old exports add one blank line before the chapter separator.
                    if ($field !== null && str_ends_with($current[$field], "\n")) {
                        $current[$field] = substr($current[$field], 0, -1);
                    }
                    $finish();
                    continue;
                }
            }
            if (preg_match('/^【(大纲|概要|节奏|悬念)】(.*)$/D', $line, $match)) {
                $field = self::FIELDS[$match[1]];
                $current[$field] = $match[2];
            } elseif ($field !== null) {
                $current[$field] .= "\n" . $line;
            }
        }
        $finish();
        return $chapters;
    }
}
