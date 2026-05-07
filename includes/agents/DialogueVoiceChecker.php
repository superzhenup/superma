<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * DialogueVoiceChecker — 人物对话差异化检测
 *
 * 纯PHP模块，无需AI调用：
 *   1. 抽取章节内容中的对话及说话人
 *   2. 按角色语音指纹（voice_profile）比对句长、语气词、禁用词
 *   3. 偏离超阈值时生成告警
 */
class DialogueVoiceChecker
{
    private int $novelId;

    /** 偏离阈值 */
    private const DEVIATION_THRESHOLD = 0.3;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 检测章节对话差异化
     *
     * @param string $content    章节正文
     * @param array  $voiceMap   角色名 => voice_profile 数组
     * @return array{issues: array, character_dialogues: array, checked: bool}
     */
    public function check(string $content, array $voiceMap): array
    {
        if (empty($voiceMap) || mb_strlen($content) < 300) {
            return ['issues' => [], 'character_dialogues' => [], 'checked' => false];
        }

        $dialogues = $this->extractDialogues($content);
        if (empty($dialogues)) {
            return ['issues' => [], 'character_dialogues' => [], 'checked' => false];
        }

        $issues = [];
        $characterDialogues = [];

        foreach ($dialogues as $item) {
            $speaker = $item['speaker'];
            if ($speaker && isset($voiceMap[$speaker])) {
                $characterDialogues[$speaker][] = $item['text'];
            }
        }

        foreach ($characterDialogues as $name => $lines) {
            if (count($lines) < 2) continue;

            $profile = $voiceMap[$name] ?? null;
            if (!$profile) continue;

            $voiceIssues = $this->compareVoice($name, $lines, $profile);
            if ($voiceIssues) {
                $issues = array_merge($issues, $voiceIssues);
            }
        }

        return [
            'issues' => $issues,
            'character_dialogues' => $characterDialogues,
            'checked' => true,
        ];
    }

    /**
     * 从正文中提取对话及说话人
     * 支持格式：
     *   "xxx"XXX说道 / XXX说："xxx" / 「xxx」XXX道 / 『xxx』XXX道
     */
    private function extractDialogues(string $content): array
    {
        $results = [];

        $dialogueTags = '(?:说道|道|笑道|喝道|问道|答道|怒道|叹道|冷道|喝道|嚷道|喊道|骂道|低声道|轻声道|沉声道|厉声道|淡淡道|平静道|冷冷道|缓缓道|急忙道|大声道|开口道|接着道|接口道)';

        // Pattern 1: "xxx" 后跟说话人  ——  "你好啊"张三说道
        preg_match_all(
            '/[「『""\'](.+?)[」』""\'][ \t]*(?:,|，)?\s*([^\n。！？]{0,8}?)' . $dialogueTags . '/u',
            $content, $m1, PREG_SET_ORDER
        );
        foreach ($m1 as $m) {
            $speaker = $this->normalizeSpeaker($m[2] ?? '');
            $results[] = ['text' => $m[1], 'speaker' => $speaker];
        }

        // Pattern 2: 说话人在前 ——  张三说："xxx"
        preg_match_all(
            '/([^\n。！？]{1,8}?)' . $dialogueTags . '[：:"][ \t]*[「『""\'](.+?)[」』""\']/u',
            $content, $m2, PREG_SET_ORDER
        );
        foreach ($m2 as $m) {
            $speaker = $this->normalizeSpeaker($m[1] ?? '');
            $results[] = ['text' => $m[2], 'speaker' => $speaker];
        }

        // Pattern 3: 单书名号『xxx』后跟说话人 —— 『你好啊』张三说道
        preg_match_all(
            '/[『](.+?)[』][ \t]*(?:,|，)?\s*([^\n。！？]{0,8}?)' . $dialogueTags . '/u',
            $content, $m3, PREG_SET_ORDER
        );
        foreach ($m3 as $m) {
            $speaker = $this->normalizeSpeaker($m[2] ?? '');
            if ($speaker) {
                $results[] = ['text' => $m[1], 'speaker' => $speaker];
            }
        }

        // Pattern 4: 说话人在前，单书名号 —— 张三：『你好啊』
        preg_match_all(
            '/([^\n。！？]{1,8}?)' . $dialogueTags . '[：:][ \t]*[『](.+?)[』]/u',
            $content, $m4, PREG_SET_ORDER
        );
        foreach ($m4 as $m) {
            $speaker = $this->normalizeSpeaker($m[1] ?? '');
            if ($speaker) {
                $results[] = ['text' => $m[2], 'speaker' => $speaker];
            }
        }

        // Pattern 5: 无标签但有缩进的对话（段落以空格或tab开头+引号）
        preg_match_all(
            '/^[ \t]{0,3}([「『""\'](.+?)[」』""\'])[ \t]*$/um',
            $content, $m5, PREG_SET_ORDER
        );
        foreach ($m5 as $m) {
            $text = trim($m[2] ?? $m[1]);
            if (mb_strlen($text) > 0) {
                $results[] = ['text' => $text, 'speaker' => ''];
            }
        }

        return $results;
    }

    /**
     * 将说话人文本规范化为人名（去空格/标点/动作描述）
     */
    private function normalizeSpeaker(string $raw): string
    {
        $name = trim($raw);
        $name = preg_replace('/[,，。！？、；：\s]+/u', '', $name);
        $name = preg_replace('/^(他|她|其|那|这|一|几|半|数)/u', '', $name);
        return mb_strlen($name) <= 6 ? $name : '';
    }

    /**
     * 对比某角色的对话行与 voice_profile
     */
    private function compareVoice(string $name, array $lines, array $profile): array
    {
        $issues = [];

        // 1. 检查禁用词
        $forbiddenWords = $profile['forbidden_words'] ?? [];
        if ($forbiddenWords) {
            foreach ($lines as $line) {
                foreach ($forbiddenWords as $fw) {
                    if (mb_strpos($line, $fw) !== false) {
                        $issues[] = [
                            'type'     => 'forbidden_word',
                            'name'     => $name,
                            'severity' => 'high',
                            'message'  => "「{$name}」对话中出现禁用词「{$fw}」（原文：{$line}）",
                            'suggestion' => "「{$name}」的设定禁用词为「" . implode('、', $forbiddenWords) . "」，请替换为符合角色身份的表达",
                        ];
                        break;
                    }
                }
            }
        }

        // 2. 句长偏离检测
        $sentenceLen = $profile['sentence_length'] ?? '';
        if ($sentenceLen && count($lines) >= 3) {
            $avgLen = array_sum(array_map('mb_strlen', $lines)) / count($lines);
            $expected = match ($sentenceLen) {
                'short'  => ['min' => 0, 'max' => 15, 'label' => '短句（≤15字）'],
                'medium' => ['min' => 10, 'max' => 35, 'label' => '中等句长（10-35字）'],
                'long'   => ['min' => 25, 'max' => 200, 'label' => '长句（≥25字）'],
                default  => null,
            };
            if ($expected) {
                if ($sentenceLen === 'short' && $avgLen > 20) {
                    $issues[] = [
                        'type'     => 'sentence_length',
                        'name'     => $name,
                        'severity' => 'medium',
                        'message'  => "「{$name}」应为{$expected['label']}，实际平均" . round($avgLen, 1) . "字/句",
                        'suggestion' => "「{$name}」设定为短句风格，请缩短对话长度",
                    ];
                } elseif ($sentenceLen === 'long' && $avgLen < 18) {
                    $issues[] = [
                        'type'     => 'sentence_length',
                        'name'     => $name,
                        'severity' => 'medium',
                        'message'  => "「{$name}」应为{$expected['label']}，实际平均" . round($avgLen, 1) . "字/句",
                        'suggestion' => "「{$name}」设定为长句风格，请增加对话复杂度",
                    ];
                }
            }
        }

        // 3. 语气词偏好检测
        $catchphrases = $profile['catchphrases'] ?? [];
        $emotionalMarkers = $profile['emotional_markers'] ?? [];
        if (($catchphrases || $emotionalMarkers) && count($lines) >= 2) {
            $hasCatchphrase = false;
            $allText = implode('', $lines);
            foreach (array_merge($catchphrases, $emotionalMarkers) as $cp) {
                if (mb_strpos($allText, $cp) !== false) {
                    $hasCatchphrase = true;
                    break;
                }
            }
            if (!$hasCatchphrase && count($lines) >= 4) {
                $issueLabels = array_merge(
                    array_map(fn($c) => "「{$c}」", array_slice($catchphrases, 0, 3)),
                    array_map(fn($e) => "「{$e}」", array_slice($emotionalMarkers, 0, 3))
                );
                if ($issueLabels) {
                    $issues[] = [
                        'type'     => 'missing_catchphrase',
                        'name'     => $name,
                        'severity' => 'low',
                        'message'  => "「{$name}」有" . count($lines) . "句对话，但未体现角色口癖（如" . implode('、', array_slice($issueLabels, 0, 4)) . "）",
                        'suggestion' => "「{$name}」应自然融入角色口癖，增强辨识度",
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * 为指定小说的所有有 voice_profile 的角色生成 prompt 注入文本
     *
     * @param array $voiceMap 角色名 => voice_profile
     * @return string 用于注入 prompt 的文本段落
     */
    public static function buildVoiceSection(array $voiceMap): string
    {
        if (empty($voiceMap)) return '';

        $lines = [];
        foreach ($voiceMap as $name => $vp) {
            $parts = [];
            if (!empty($vp['dialogue_style'])) {
                $parts[] = $vp['dialogue_style'];
            }
            if (!empty($vp['sentence_length'])) {
                $lenLabel = match ($vp['sentence_length']) {
                    'short' => '短句为主',
                    'long'  => '长句为主',
                    default => '中等句长',
                };
                $parts[] = $lenLabel;
            }
            if (!empty($vp['catchphrases'])) {
                $parts[] = '口癖：' . implode('、', array_slice($vp['catchphrases'], 0, 3));
            }
            if (!empty($vp['forbidden_words'])) {
                $parts[] = '禁用：' . implode('、', array_slice($vp['forbidden_words'], 0, 3));
            }
            if (!empty($vp['emotional_range'])) {
                $parts[] = $vp['emotional_range'];
            }
            if (!empty($vp['vocabulary_level'])) {
                $parts[] = '用语：' . $vp['vocabulary_level'];
            }
            if ($parts) {
                $lines[] = "· {$name}：" . implode('，', $parts);
            }
        }

        return $lines
            ? "【🎭 角色语音规则】\n" . implode("\n", $lines) . "\n\n"
            : '';
    }
}
