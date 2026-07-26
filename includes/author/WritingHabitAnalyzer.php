<?php
/**
 * 写作习惯分析器 - 作者画像系统
 * 分析用词偏好、句式结构、常用修辞等写作习惯
 */

class WritingHabitAnalyzer
{
    private const CHINESE_STOPWORDS = [
        '的', '了', '是', '在', '有', '和', '与', '对', '于', '为', '以', '及', '等', '把', '被', '给', '跟', '同', '向', '往',
        '这', '那', '这个', '那个', '他', '她', '它', '他们', '她们', '它们', '我', '我们', '你', '你们', '谁', '什么', '哪', '哪个',
        '就', '才', '都', '也', '还', '又', '再', '要', '会', '能', '可', '要', '想', '该', '应该', '必须', '得', '很', '太',
        '一个', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十', '百', '千', '万', '第一', '第二',
    ];

    private array $chapterTexts;
    private array $stats = [];

    public function __construct(array $chapterTexts)
    {
        $this->chapterTexts = $chapterTexts;
    }

    public function analyze(): array
    {
        $this->stats = [
            'vocabulary_preference' => $this->analyzeVocabulary(),
            'word_complexity' => $this->analyzeWordComplexity(),
            'sentence_length_avg' => 0,
            'paragraph_length_avg' => 0,
            'sentence_patterns' => $this->analyzeSentencePatterns(),
            'use_passive' => 0,
            'use_dialogue' => 0,
            'rhetorical_devices' => $this->analyzeRhetoricalDevices(),
            'metaphor_frequency' => 'medium',
            'uniqueness_score' => 0,
            'confidence' => 0,
            'source_chapter_count' => count($this->chapterTexts),
        ];

        $sentenceLengths = [];
        $paragraphLengths = [];
        $dialogueCount = 0;
        $passiveCount = 0;
        $totalSentences = 0;
        $metaphorCount = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            preg_match_all('/[。！？；\.]/u', $text, $matches);
            $sentenceCount = count($matches[0]);
            $totalSentences += $sentenceCount;
            if ($sentenceCount > 0) {
                $sentenceLengths[] = mb_strlen($text) / $sentenceCount;
            }

            $paragraphs = preg_split('/\n\s*\n/u', $text);
            foreach ($paragraphs as $para) {
                $paraLen = mb_strlen(trim($para));
                if ($paraLen > 0) {
                    $paragraphLengths[] = $paraLen;
                }
            }

            $dialogueCount += preg_match_all('/[""「」『』"\'][^""「」『』"\']*[""「」『』"\']/u', $text);
            $passiveCount += preg_match_all('/被[\p{L}]{1,5}/u', $text);
            $metaphorCount += $this->countMetaphors($text);
        }

        $totalChars = array_sum(array_map(fn($c) => mb_strlen($c['content'] ?? ''), $this->chapterTexts));
        $totalParagraphs = count($paragraphLengths);

        if ($totalSentences > 0) {
            $this->stats['sentence_length_avg'] = (int)round(array_sum($sentenceLengths) / count($sentenceLengths));
        }

        if ($totalParagraphs > 0) {
            $this->stats['paragraph_length_avg'] = (int)round(array_sum($paragraphLengths) / $totalParagraphs);
        }

        if ($totalChars > 0) {
            $this->stats['use_dialogue'] = round($dialogueCount * 50 / $totalChars, 4);
            $this->stats['use_passive'] = round($passiveCount * 1000 / $totalChars, 4);
        }

        $avgChapterLen = $totalChars / max(1, count($this->chapterTexts));
        if ($avgChapterLen > 5000) {
            $this->stats['metaphor_frequency'] = $metaphorCount / count($this->chapterTexts) > 5 ? 'high' : 'medium';
        } else {
            $this->stats['metaphor_frequency'] = $metaphorCount / count($this->chapterTexts) > 3 ? 'medium' : 'low';
        }

        $this->stats['uniqueness_score'] = $this->calculateUniquenessScore();
        $this->stats['confidence'] = $this->calculateConfidence();

        return $this->stats;
    }

    private function analyzeVocabulary(): array
    {
        $allText = '';
        $maxChars = 100000; // 限制10万字，避免大文本卡顿
        foreach ($this->chapterTexts as $chapter) {
            $allText .= $chapter['content'] ?? '';
            if (mb_strlen($allText) > $maxChars) {
                $allText = mb_substr($allText, 0, $maxChars);
                break;
            }
        }

        $words = $this->extractWords($allText);
        $wordFreq = array_count_values($words);
        arsort($wordFreq);

        $topWords = array_slice(array_keys($wordFreq), 0, 50, true);
        $result = [];
        foreach ($topWords as $word) {
            if (!in_array($word, self::CHINESE_STOPWORDS) && mb_strlen($word) >= 2) {
                $result[$word] = $wordFreq[$word];
            }
        }

        return array_slice($result, 0, 30, true);
    }

    private function extractWords(string $text): array
    {
        $text = preg_replace('/[^\p{L}\p{N}]/u', ' ', $text);
        $words = preg_split('/\s+/', $text);
        return array_filter($words, fn($w) => mb_strlen($w) >= 2);
    }

    private function analyzeWordComplexity(): string
    {
        $totalWords = 0;
        $complexWords = 0;

        foreach ($this->chapterTexts as $chapter) {
            $words = $this->extractWords($chapter['content'] ?? '');
            $totalWords += count($words);
            foreach ($words as $word) {
                if (mb_strlen($word) >= 4) {
                    $complexWords++;
                }
            }
        }

        if ($totalWords === 0) return 'moderate';

        $complexRatio = $complexWords / $totalWords;
        if ($complexRatio > 0.4) return 'complex';
        if ($complexRatio < 0.2) return 'simple';
        return 'moderate';
    }

    private function analyzeSentencePatterns(): array
    {
        $patterns = [
            'short_sentence' => 0,
            'long_sentence' => 0,
            'declarative' => 0,
            'exclamatory' => 0,
            'interrogative' => 0,
        ];

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $sentences = preg_split('/[。！？；\.]/u', $text);
            foreach ($sentences as $s) {
                $len = mb_strlen(trim($s));
                if ($len > 0) {
                    if ($len < 20) $patterns['short_sentence']++;
                    elseif ($len > 80) $patterns['long_sentence']++;
                }
            }

            $patterns['declarative'] += preg_match_all('/[。](?!\！|\？)/u', $text);
            $patterns['exclamatory'] += preg_match_all('/[！]/u', $text);
            $patterns['interrogative'] += preg_match_all('/[？]/u', $text);
        }

        $total = array_sum($patterns);
        if ($total === 0) return $patterns;

        return [
            'short_ratio' => round($patterns['short_sentence'] / $total, 2),
            'long_ratio' => round($patterns['long_sentence'] / $total, 2),
            'declarative_ratio' => round($patterns['declarative'] / $total, 2),
            'exclamatory_ratio' => round($patterns['exclamatory'] / $total, 2),
            'interrogative_ratio' => round($patterns['interrogative'] / $total, 2),
        ];
    }

    private function analyzeRhetoricalDevices(): array
    {
        $devices = [
            'metaphor' => 0,
            'simile' => 0,
            'personification' => 0,
            'hyperbole' => 0,
            'parallelism' => 0,
            'repetition' => 0,
        ];

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $devices['simile'] += preg_match_all('/像[\p{L}\p{N}]+/u', $text);
            $devices['simile'] += preg_match_all('/如同[\p{L}\p{N}]+/u', $text);
            $devices['simile'] += preg_match_all('/犹如[\p{L}\p{N}]+/u', $text);
            $devices['simile'] += preg_match_all('/仿佛[\p{L}\p{N}]+/u', $text);

            $devices['personification'] += preg_match_all('/[\p{L}]+(?:在|会|能|会)[\p{L}]+(?:说话|呼吸|思考|心跳)/u', $text);

            $devices['hyperbole'] += preg_match_all('/(?:千|万|亿)+[\p{L}]+/u', $text);
            $devices['hyperbole'] += preg_match_all('/(?:瞬|一)+刻(?:间|之间)/u', $text);

            $devices['parallelism'] += preg_match_all('/(?:[，。]).*?[，。].*?[，。].*?[，。]/u', $text);

            $devices['repetition'] += preg_match_all('/([\p{L}]{2,3})\1{2,}/u', $text);
        }

        return array_filter($devices, fn($count) => $count > 0);
    }

    private function countMetaphors(string $text): int
    {
        $count = 0;
        $count += preg_match_all('/像[\p{L}\p{N}]+/u', $text);
        $count += preg_match_all('/如同[\p{L}\p{N}]+/u', $text);
        $count += preg_match_all('/是[\p{L}\p{N}]{1,5}的/u', $text);
        return $count;
    }

    private function calculateUniquenessScore(): float
    {
        $totalWords = 0;
        $uniqueWords = [];

        foreach ($this->chapterTexts as $chapter) {
            $words = array_unique($this->extractWords($chapter['content'] ?? ''));
            $totalWords += count($words);
            $uniqueWords = array_merge($uniqueWords, $words);
        }

        $uniqueWords = array_unique($uniqueWords);
        $vocabRichness = count($uniqueWords) / max(1, $totalWords);

        $chapterWordCounts = array_map(fn($c) => mb_strlen($c['content'] ?? ''), $this->chapterTexts);
        $variance = $this->calculateVariance($chapterWordCounts);
        $variationScore = min(1, $variance / 1000000);

        return round(min(1, $vocabRichness * 0.7 + $variationScore * 0.3), 2);
    }

    private function calculateConfidence(): float
    {
        $chapterCount = count($this->chapterTexts);
        $totalChars = array_sum(array_map(fn($c) => mb_strlen($c['content'] ?? ''), $this->chapterTexts));

        $chapterScore = min(1, $chapterCount / 10) * 0.5;
        $volumeScore = min(1, $totalChars / 50000) * 0.5;

        return round($chapterScore + $volumeScore, 2);
    }

    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) return 0;
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        return array_sum($squaredDiffs) / count($values);
    }
}
