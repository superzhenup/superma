<?php
defined('APP_LOADED') or die('Direct access denied.');

class ShortStoryQualityGuard
{
    public function evaluate(array $story): array
    {
        $issues = [];
        $suggestions = [];
        $score = 100;

        $content = $story['content'] ?? '';
        $targetWords = (int)($story['target_words'] ?? 3000);
        $brief = $story['brief_json'] ?? [];
        $beats = $story['beats_json'] ?? [];
        $endingDirection = $story['ending_direction'] ?? '';

        $wordCount = mb_strlen(preg_replace('/\s+/', '', $content));

        $score -= $this->checkWordCount($wordCount, $targetWords, $issues, $suggestions);
        $score -= $this->checkCompleteArc($brief, $issues, $suggestions);
        $score -= $this->checkBeatsCovered($beats, $content, $issues, $suggestions);
        $score -= $this->checkRepetition($content, $issues, $suggestions);
        $score -= $this->checkEndingClosure($content, $endingDirection, $brief, $issues, $suggestions);

        $score = max(0, min(100, $score));

        return [
            'score'       => $score,
            'passed'      => $score >= 60,
            'word_count'  => $wordCount,
            'target_words' => $targetWords,
            'issues'      => $issues,
            'suggestions' => $suggestions,
        ];
    }

    private function checkWordCount(int $wordCount, int $targetWords, array &$issues, array &$suggestions): int
    {
        $penalty = 0;
        $ratio = $targetWords > 0 ? $wordCount / $targetWords : 0;

        if ($wordCount === 0) {
            $issues[] = ['type' => 'word_count_fit', 'severity' => 'error', 'message' => '内容为空'];
            return 30;
        }

        if ($ratio < 0.7) {
            $diff = $targetWords - $wordCount;
            $issues[] = ['type' => 'word_count_fit', 'severity' => 'warning', 'message' => "字数不足，当前{$wordCount}字，目标{$targetWords}字，差{$diff}字"];
            $suggestions[] = '可以扩展场景描写、增加对话、深化人物内心活动来补充字数';
            $penalty = 15;
        } elseif ($ratio > 1.3) {
            $issues[] = ['type' => 'word_count_fit', 'severity' => 'warning', 'message' => "字数超标，当前{$wordCount}字，目标{$targetWords}字，超出" . ($wordCount - $targetWords) . "字"];
            $suggestions[] = '可以精简冗余描写、压缩过渡段落来控制字数';
            $penalty = 10;
        }

        return $penalty;
    }

    private function checkCompleteArc(array $brief, array &$issues, array &$suggestions): int
    {
        if (empty($brief)) return 0;

        $penalty = 0;
        $requiredKeys = ['protagonist_goal', 'obstacle', 'turning_point', 'ending_echo'];
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (empty($brief[$key])) {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            $issues[] = ['type' => 'has_complete_arc', 'severity' => 'warning', 'message' => '故事概要缺少关键要素：' . implode('、', $missing)];
            $suggestions[] = '完善故事概要中的缺失要素，确保故事有完整的弧线';
            $penalty = 5 * count($missing);
        }

        return min($penalty, 20);
    }

    private function checkBeatsCovered(array $beats, string $content, array &$issues, array &$suggestions): int
    {
        if (empty($beats) || empty($content)) return 0;

        $covered = 0;
        $total = count($beats);
        foreach ($beats as $beat) {
            $keywords = [];
            if (!empty($beat['event'])) {
                $words = preg_split('/[，。、；：！？\s]+/u', $beat['event']);
                $keywords = array_filter($words, fn($w) => mb_strlen($w) >= 2);
            }
            if (!empty($beat['name'])) {
                $keywords[] = $beat['name'];
            }
            $keywords = array_slice(array_unique($keywords), 0, 5);

            $found = false;
            foreach ($keywords as $kw) {
                if (mb_strpos($content, $kw) !== false) {
                    $found = true;
                    break;
                }
            }
            if ($found) $covered++;
        }

        $coverage = $total > 0 ? $covered / $total : 1;
        if ($coverage < 0.7) {
            $issues[] = ['type' => 'beats_covered', 'severity' => 'warning', 'message' => "节拍覆盖率仅" . round($coverage * 100) . "%，建议至少70%"];
            $suggestions[] = '检查是否有节拍被遗漏，补充缺失的情节段落';
            return (int)round((1 - $coverage) * 20);
        }

        return 0;
    }

    private function checkRepetition(string $content, array &$issues, array &$suggestions): int
    {
        if (mb_strlen($content) < 200) return 0;

        $paragraphs = array_filter(explode("\n", $content), fn($p) => mb_strlen(trim($p)) > 20);
        if (count($paragraphs) < 3) return 0;

        $seen = [];
        $duplicates = 0;
        foreach ($paragraphs as $p) {
            $normalized = mb_strtolower(trim($p));
            $key = mb_substr($normalized, 0, 40);
            if (isset($seen[$key])) {
                $duplicates++;
            } else {
                $seen[$key] = true;
            }
        }

        $dupRatio = $duplicates / count($paragraphs);
        if ($dupRatio > 0.1) {
            $issues[] = ['type' => 'low_repetition', 'severity' => 'warning', 'message' => "重复段落比例" . round($dupRatio * 100) . "%，建议低于10%"];
            $suggestions[] = '删除或改写重复段落，用不同的方式表达相同的内容';
            return 10;
        }

        return 0;
    }

    private function checkEndingClosure(string $content, string $endingDirection, array $brief, array &$issues, array &$suggestions): int
    {
        if (empty($content) || mb_strlen($content) < 100) return 0;

        $ending = mb_substr($content, -(int)min(mb_strlen($content), 300));
        if (mb_strlen(trim($ending)) < 20) {
            $issues[] = ['type' => 'ending_closure', 'severity' => 'warning', 'message' => '结尾内容过短或为空'];
            $suggestions[] = '补充结尾，让故事有完整的收束';
            return 10;
        }

        $hasClosure = false;
        if (!empty($brief['ending_echo'])) {
            $echoWords = preg_split('/[，。、；：！？\s]+/u', $brief['ending_echo']);
            $echoWords = array_filter($echoWords, fn($w) => mb_strlen($w) >= 2);
            foreach ($echoWords as $w) {
                if (mb_strpos($ending, $w) !== false) {
                    $hasClosure = true;
                    break;
                }
            }
        }
        if (!empty($endingDirection)) {
            $dirWords = preg_split('/[，。、；：！？\s]+/u', $endingDirection);
            $dirWords = array_filter($dirWords, fn($w) => mb_strlen($w) >= 2);
            foreach ($dirWords as $w) {
                if (mb_strpos($ending, $w) !== false) {
                    $hasClosure = true;
                    break;
                }
            }
        }

        $closureSignals = ['终于', '原来', '最终', '后来', '从此', '回首', '多年后', '那一刻'];
        foreach ($closureSignals as $sig) {
            if (mb_strpos($ending, $sig) !== false) {
                $hasClosure = true;
                break;
            }
        }

        if (!$hasClosure && !empty($brief)) {
            $issues[] = ['type' => 'ending_closure', 'severity' => 'info', 'message' => '结尾未检测到与开头或主题的呼应'];
            $suggestions[] = '在结尾呼应开头的意象或主题，让故事形成闭环';
            return 5;
        }

        return 0;
    }
}
