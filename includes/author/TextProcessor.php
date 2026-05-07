<?php
/**
 * 文本预处理器 - 作者画像系统
 * 负责文本清洗、规范化、分章等预处理操作
 */

class TextProcessor
{
    private string $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function clean(): self
    {
        $text = $this->content;

        $text = preg_replace('/\r\n|\r/', "\n", $text);

        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        $this->content = trim($text);
        return $this;
    }

    public function normalize(): self
    {
        $text = $this->content;

        $text = preg_replace('/["「」""]/u', '"', $text);
        $text = preg_replace("/[''']/u", "'", $text);

        $text = preg_replace('/[…‹›«»]/u', '...', $text);

        $text = preg_replace('/\s+([，。！？；：、）】』》」}])/u', '$1', $text);
        $text = preg_replace('/([（【《「『{([])\s+/u', '$1', $text);

        $this->content = $text;
        return $this;
    }

    public function chunkByChapter(int $minChapterLength = 200): array
    {
        $chapters = [];

        $chapterPattern = '/(第[一二三四五六七八九十百千万零〇\d]+[章回部节集][^\n]*|Chapter\s*\.?\s*\d+[^\n]*)/iu';

        $parts = preg_split($chapterPattern, $this->content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (count($parts) > 1) {
            $currentTitle = '';
            $currentContent = '';

            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) continue;

                if (preg_match('/^(第[一二三四五六七八九十百千万零〇\d]+[章回部节集]|Chapter\s*\.?\s*\d+)/iu', $part)) {
                    if ($currentTitle !== '' && mb_strlen($currentContent) >= $minChapterLength) {
                        $chapters[] = [
                            'number' => count($chapters) + 1,
                            'title' => $currentTitle,
                            'content' => trim($currentContent),
                            'char_count' => mb_strlen(trim($currentContent)),
                        ];
                    } elseif ($currentTitle !== '' && mb_strlen($currentContent) > 0) {
                        $chapters[] = [
                            'number' => count($chapters) + 1,
                            'title' => $currentTitle,
                            'content' => trim($currentContent),
                            'char_count' => mb_strlen(trim($currentContent)),
                        ];
                    }
                    $currentTitle = $this->extractChapterTitle($part);
                    $currentContent = '';
                } else {
                    $currentContent .= "\n" . $part;
                }
            }

            if ($currentTitle !== '' && mb_strlen(trim($currentContent)) > 0) {
                $chapters[] = [
                    'number' => count($chapters) + 1,
                    'title' => $currentTitle,
                    'content' => trim($currentContent),
                    'char_count' => mb_strlen(trim($currentContent)),
                ];
            }
        }

        if (empty($chapters)) {
            $chapters = $this->chunkByEmptyLines($minChapterLength);
        }

        return $chapters;
    }

    private function extractChapterTitle(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^第[一二三四五六七八九十百千万零〇\d]+[章回部节集]\s*/u', '', $line);
        $line = preg_replace('/^Chapter\s*\.?\s*\d+\s*[:\.]\s*/iu', '', $line);
        return mb_substr($line, 0, 100);
    }

    private function chunkByEmptyLines(int $minChapterLength): array
    {
        $chapters = [];
        $paragraphs = preg_split('/\n\s*\n/u', $this->content);
        $currentChapter = ['title' => '第一章', 'content' => [], 'char_count' => 0];

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;

            $currentChapter['content'][] = $para;
            $currentChapter['char_count'] += mb_strlen($para);

            if ($currentChapter['char_count'] >= $minChapterLength) {
                $content = trim(implode("\n", $currentChapter['content']));
                $chapters[] = [
                    'number' => count($chapters) + 1,
                    'title' => $currentChapter['title'],
                    'content' => $content,
                    'char_count' => $currentChapter['char_count'],
                ];
                $currentChapter = ['title' => '第' . $this->intToChinese(count($chapters) + 1) . '章', 'content' => [], 'char_count' => 0];
            }
        }

        if ($currentChapter['char_count'] > 0) {
            $content = trim(implode("\n", $currentChapter['content']));
            $chapters[] = [
                'number' => count($chapters) + 1,
                'title' => $currentChapter['title'],
                'content' => $content,
                'char_count' => $currentChapter['char_count'],
            ];
        }

        return $chapters;
    }

    private function intToChinese(int $num): string
    {
        $chars = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
        if ($num <= 10) return $chars[$num] ?? (string)$num;
        if ($num < 20) return '十' . ($num > 10 ? $chars[$num - 10] : '');
        if ($num < 100) return $chars[intval($num / 10)] . '十' . ($num % 10 > 0 ? $chars[$num % 10] : '');
        return (string)$num;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCharCount(): int
    {
        return mb_strlen($this->content);
    }

    public function getParagraphCount(): int
    {
        $paragraphs = preg_split('/\n\s*\n/u', $this->content);
        return count(array_filter($paragraphs, fn($p) => mb_strlen(trim($p)) > 0));
    }

    public function extractFirstNChapters(int $n, int $minLength = 1000): array
    {
        $chapters = $this->chunkByChapter($minLength);
        return array_slice($chapters, 0, $n);
    }
}
