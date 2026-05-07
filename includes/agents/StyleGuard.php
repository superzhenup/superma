<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * StyleGuard — 风格漂移监测 + AI痕迹清洗
 *
 * 两个纯PHP模块，无需AI调用:
 *   1. StyleDriftMonitor  — 每20章对比近5章与开篇5章的量化风格特征
 *   2. AIPatternsCleaner — 检测典型AI写作痕迹（副词密度/转折词/情绪三件套/对话标签重复）
 *
 * 生效论证：
 *   - 风格漂移检测基于句长均值、对话密度、感官词比例等可统计特征
 *   - AI痕迹检测基于正则密度扫描，阈值来自网文读者实测反馈
 */
class StyleGuard
{
    private int $novelId;

    /** AI典型痕迹特征库 */
    private const AI_PATTERNS = [
        'opening_adverbs' => [
            'regex' => '/^[^「」\n]*?(突然|忽然|猛然|顿时|瞬间|骤然|蓦然)/mu',
            'max_per_1000_chars' => 5,
            'label' => '段首副词过度',
            'suggestion' => '减少段首副词使用，改用具体动作或感官描写开篇',
        ],
        'transition_cliches' => [
            'regex' => '/(竟然|居然|不禁|忍不住|不由得|鬼使神差)/u',
            'max_per_1000_chars' => 8,
            'label' => '转折词过度',
            'suggestion' => '减少这些转折词，用具体情境代替情绪表达',
        ],
        'three_layer_emotion' => [
            'regex' => '/(攥紧|握紧|捏紧).{0,30}(脸色|眼神|表情|目光).{0,40}(心中|内心|心底|想到|暗想)/u',
            'max_per_chapter' => 3,
            'label' => '情绪三件套',
            'suggestion' => '情绪描写只保留1-2个维度，让读者自己脑补',
        ],
        'dialogue_tag_monotony' => [
            'regex' => '/(说道|说道|道|笑道|喝道|问道|答道)/u',
            'max_unique_ratio' => 0.5,
            'label' => '对话标签单一',
            'suggestion' => '多样化对话表达，用动作替代"XX说道"',
        ],
    ];

    /** 五感关键词库 */
    private const SENSORY_KEYWORDS = [
        'visual'    => ['看见','望去','出现','浮现','身影','景象','映入','耀眼','闪烁','发亮','光亮',
                        '青色','金色','红色','白色','黑色','流光','一道','光芒','影子','面庞',
                        '视线','目光','注释','注视','凝视','打量','端详','望见','瞥见','瞧见','瞅见'],
        'auditory'  => ['听到','声响','轰鸣','低语','轻响','脚步','呼吸声','回荡','震耳','嗡嗡',
                        '咆哮','嘶吼','尖叫','哭喊','叹息','啜泣','潺潺','沙沙','啪嗒','滴答',
                        '铿锵','叮当','嘶嘶','隆隆','簌簌','叮咚','咆哮','呢喃','耳畔','耳边'],
        'tactile'   => ['冰冷','温热','粗糙','柔软','刺痛','拂过','触及','触感','微凉','灼热',
                        '滚烫','湿滑','干裂','紧绷','酥麻','僵硬','沉重','轻盈','黏腻','刺骨',
                        '寒意','暖意','热气','凉意','温润','炽热','冰冷','阴冷','闷热'],
        'olfactory' => ['气味','香气','腥气','血腥','清香','芬芳','草木','花香','恶臭','腐臭',
                        '檀香','幽香','酸臭','刺鼻','弥漫','飘散','扑鼻','暗香','芳草','泥土'],
        'gustatory' => ['尝到','苦涩','甘甜','腥甜','淡淡的咸','酸涩','辛辣','鲜美','入喉',
                        '回味','甜美','清甜','苦味','咸味','涩口','满口','唇齿','舌尖'],
    ];

    /** 五感维度标签 */
    private const SENSORY_LABELS = [
        'visual'    => '视觉',
        'auditory'  => '听觉',
        'tactile'   => '触觉',
        'olfactory' => '嗅觉',
        'gustatory' => '味觉',
    ];

    /** 风格特征提取维度 */
    private const STYLE_DIMENSIONS = [
        'avg_sentence_len',
        'dialogue_density',
        'sensory_ratio',
        'paragraph_len_avg',
    ];

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 检测AI写作痕迹 — 纯正则+统计，无AI调用
     * @return array{issues: array, suggestions: array, total_issues: int}
     */
    public function detectAIPatterns(string $content): array
    {
        $charCount = mb_strlen($content);
        if ($charCount < 500) return ['issues' => [], 'suggestions' => [], 'total_issues' => 0];

        $issues = [];
        $suggestions = [];

        foreach (self::AI_PATTERNS as $key => $rule) {
            $count = preg_match_all($rule['regex'], $content);

            if ($key === 'three_layer_emotion') {
                $limit = $rule['max_per_chapter'];
                if ($count > $limit) {
                    $issues[] = "{$rule['label']}：{$count}处（建议≤{$limit}处/章）";
                    $suggestions[] = $rule['suggestion'];
                }
            } elseif ($key === 'dialogue_tag_monotony') {
                // 检查是否同一标签占比过高
                if ($count > 5) {
                    $tags = [];
                    preg_match_all($rule['regex'], $content, $tagMatches);
                    foreach ($tagMatches[0] as $tag) {
                        $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                    }
                    $mostFrequent = max($tags);
                    if ($count > 0 && ($mostFrequent / $count) > 0.6) {
                        $domTag = array_search($mostFrequent, $tags);
                        $issues[] = "{$rule['label']}：{$count}处中「{$domTag}」占{$mostFrequent}次";
                        $suggestions[] = $rule['suggestion'];
                    }
                }
            } else {
                $density = $count * 1000 / $charCount;
                if ($density > $rule['max_per_1000_chars']) {
                    $issues[] = "{$rule['label']}：密度" . round($density, 1) . "/千字（建议≤{$rule['max_per_1000_chars']}）";
                    $suggestions[] = $rule['suggestion'];
                }
            }
        }

        return [
            'issues' => $issues,
            'suggestions' => $suggestions,
            'total_issues' => count($issues),
            'directives' => $this->generateDirectives($issues, $suggestions),
        ];
    }

    private function generateDirectives(array $issues, array $suggestions): array
    {
        if (empty($issues)) return [];

        $directives = [];
        $directives[] = [
            'type' => 'ai_pattern_cleanup',
            'priority' => 'medium',
            'instructions' => '本章检测到AI写作痕迹：' . implode('; ', $issues)
                            . '。建议：' . implode('; ', $suggestions),
        ];

        return $directives;
    }

    /**
     * 风格漂移检测 — 每20章触发一次，对比近5章与开篇5章
     * @return array|null 漂移报告，无需处理则返回null
     */
    public function checkStyleDrift(int $currentChapter): ?array
    {
        if ($currentChapter % 20 !== 0 || $currentChapter < 25) return null;

        $baseline = $this->extractStyleVector(1, min(5, $currentChapter));
        $recent   = $this->extractStyleVector($currentChapter - 4, $currentChapter);

        if (!$baseline || !$recent) return null;

        $drifts = [];
        foreach (self::STYLE_DIMENSIONS as $dim) {
            $bVal = $baseline[$dim] ?? 0;
            $rVal = $recent[$dim] ?? 0;
            if ($bVal <= 0) continue;

            $change = abs($rVal - $bVal) / $bVal;
            if ($change > 0.25) {
                $direction = $rVal > $bVal ? '上升' : '下降';
                $drifts[$dim] = [
                    'baseline' => round($bVal, 1),
                    'recent' => round($rVal, 1),
                    'change_pct' => round($change * 100, 1),
                    'direction' => $direction,
                ];
            }
        }

        if (empty($drifts)) return null;

        return [
            'current_chapter' => $currentChapter,
            'drifts' => $drifts,
            'severity' => count($drifts) >= 3 ? 'high' : (count($drifts) >= 2 ? 'medium' : 'low'),
        ];
    }

    /**
     * 五感平衡检测 — 纯关键词统计，无AI调用
     * 检测场景描写的五感分布是否过度偏向视觉
     * @return array{balanced: bool, counts: array, ratios: array, issue: ?array}
     */
    public function checkSensoryBalance(string $content): array
    {
        $charCount = mb_strlen($content);
        if ($charCount < 500) {
            return ['balanced' => true, 'counts' => [], 'ratios' => [], 'issue' => null];
        }

        $counts = [];
        foreach (self::SENSORY_KEYWORDS as $sense => $keywords) {
            $hit = 0;
            foreach ($keywords as $kw) {
                $hit += mb_substr_count($content, $kw);
            }
            $counts[$sense] = $hit;
        }

        $total = array_sum($counts);
        if ($total < 5) {
            return ['balanced' => true, 'counts' => $counts, 'ratios' => [], 'issue' => null];
        }

        $ratios = [];
        foreach ($counts as $sense => $cnt) {
            $ratios[$sense] = round($cnt / $total * 100, 1);
        }

        $visualRatio = $counts['visual'] / $total;
        $issue = null;

        if ($visualRatio > 0.7) {
            $nonVisual = [];
            foreach ($ratios as $sense => $pct) {
                if ($sense !== 'visual' && $pct > 0) {
                    $nonVisual[] = self::SENSORY_LABELS[$sense] . $pct . '%';
                }
            }
            $nonVisualStr = $nonVisual ? '（' . implode('、', $nonVisual) . '）' : '';
            $issue = [
                'type'      => 'visual_heavy',
                'severity'  => 'medium',
                'message'   => '场景描写过度依赖视觉（占' . round($visualRatio * 100, 1) . '%）' . $nonVisualStr,
                'suggestion'=> '建议加入1-2处听觉/触觉/嗅觉描写，如"空气中弥漫着淡淡的檀香味"、"指尖触到冰冷的石壁"，提升代入感',
            ];
        } elseif ($total >= 10) {
            $activeSenses = array_filter($counts, fn($c) => $c > 0);
            if (count($activeSenses) <= 2) {
                $used = [];
                foreach ($activeSenses as $sense => $_) {
                    $used[] = self::SENSORY_LABELS[$sense];
                }
                $issue = [
                    'type'      => 'sensory_narrow',
                    'severity'  => 'low',
                    'message'   => '五感描写集中在' . implode('、', $used) . '，其余感官完全缺失',
                    'suggestion'=> '适当补充其他感官描写，丰富场景层次',
                ];
            }
        }

        return [
            'balanced' => $issue === null,
            'counts'   => $counts,
            'ratios'   => $ratios,
            'issue'    => $issue,
        ];
    }

    /**
     * 提取指定章节范围的内容风格向量
     */
    private function extractStyleVector(int $fromChapter, int $toChapter): ?array
    {
        $contents = DB::fetchAll(
            'SELECT content, words FROM chapters
             WHERE novel_id=? AND chapter_number BETWEEN ? AND ?
               AND status="completed" AND content IS NOT NULL
             ORDER BY chapter_number ASC',
            [$this->novelId, $fromChapter, $toChapter]
        );
        if (empty($contents)) return null;

        $totalSentences = 0;
        $totalChars = 0;
        $totalDialogueChars = 0;
        $totalParagraphs = 0;
        $sensoryHits = 0;

        foreach ($contents as $ch) {
            $text = $ch['content'] ?? '';
            $totalChars += mb_strlen($text);

            // 句子数 = 句号+感叹号+问号
            $totalSentences += preg_match_all('/[。！？?!]/u', $text);

            // 对话字数 = 引号内的内容
            preg_match_all('/[「『""\'\'""].*?[」』""\'\'""]/u', $text, $dialogueMatches);
            foreach ($dialogueMatches[0] as $d) {
                $totalDialogueChars += mb_strlen($d);
            }

            // 段落数
            $totalParagraphs += preg_match_all('/\n\s*\n/', $text) + 1;

            // 感官词
            $sensoryKeywords = ['看到', '听到', '闻到', '嗅到', '触到', '摸到', '感觉到',
                '暖和', '冰冷', '炽热', '刺鼻', '清香', '腥臭', '刺耳', '悦耳',
                '柔软', '粗糙', '坚硬', '光滑', '黑暗', '明亮', '闪烁', '低沉'];
            foreach ($sensoryKeywords as $kw) {
                $sensoryHits += mb_substr_count($text, $kw);
            }
        }

        return [
            'avg_sentence_len' => $totalSentences > 0 ? round($totalChars / $totalSentences, 1) : 0,
            'dialogue_density' => $totalChars > 0 ? round($totalDialogueChars / $totalChars * 100, 1) : 0,
            'paragraph_len_avg' => $totalParagraphs > 0 ? round($totalChars / $totalParagraphs, 1) : 0,
            'sensory_ratio' => $totalChars > 0 ? round($sensoryHits * 1000 / $totalChars, 1) : 0,
        ];
    }
}
