<?php
/**
 * 思想情感分析器 - 作者画像系统
 * 分析情感基调、思想深度、审美倾向等思想情感特征
 */

class SentimentAnalyzer
{
    private const EMOTION_WORDS = [
        'joy' => ['快乐', '开心', '高兴', '喜悦', '欢快', '愉悦', '兴奋', '激动', '欣喜', '狂喜', '欢乐', '喜滋滋', '笑眯眯', '眉开眼笑', '喜出望外', '手舞足蹈', '欢呼', '雀跃'],
        'anger' => ['愤怒', '生气', '恼火', '发怒', '气愤', '怒火', '大怒', '震怒', '暴怒', '恼怒', '愤恨', '怨恨', '痛恨', '憎恨', '咬牙切齿', '怒不可遏', '勃然大怒', '怒目圆睁'],
        'sadness' => ['悲伤', '难过', '伤心', '悲痛', '哀伤', '凄凉', '沮丧', '忧郁', '哀愁', '痛苦', '心碎', '欲哭无泪', '痛不欲生', '泪流满面', '哀嚎', '呜咽', '抽泣', '痛哭'],
        'fear' => ['恐惧', '害怕', '畏惧', '惊恐', '胆怯', '慌张', '不安', '忐忑', '惶恐', '战栗', '发抖', '瑟缩', '惊慌', '惊慌失措', '魂飞魄散', '毛骨悚然', '不寒而栗'],
        'surprise' => ['惊讶', '吃惊', '震惊', '诧异', '意外', '惊奇', '惊愕', '愕然', '大惊', '瞠目结舌', '目瞪口呆', '大吃一惊', '始料未及', '出人意料'],
        'love' => ['爱', '喜欢', '爱慕', '倾心', '暗恋', '痴迷', '深情', '柔情', '温情', '恩爱', '甜蜜', '幸福', '温馨', '心心相印', '情投意合', '海枯石烂', '永结同心'],
        'disgust' => ['厌恶', '讨厌', '憎恶', '反感', '恶心', '不屑', '鄙视', '唾弃', '嫌弃', '作呕', '厌恶之情', '不屑一顾', '嗤之以鼻'],
    ];

    private const THEME_KEYWORDS = [
        'growth' => ['成长', '蜕变', '进步', '成熟', '磨练', '磨砺', '进化', '突破', '蜕化', '升华'],
        'love' => ['爱情', '恋爱', '感情', '爱恋', '情愫', '情缘', '相思', '热恋', '真爱', '情'],
        'revenge' => ['复仇', '报仇', '雪恨', '报复', '清算', '讨债', '雪耻'],
        'freedom' => ['自由', '解放', '挣脱', '解脱', '无拘无束', '翱翔', '飞翔', '天地任游'],
        'power' => ['力量', '权力', '能力', '实力', '强大', '称霸', '统治', '征服', '王者', '无敌'],
        'friendship' => ['友情', '友谊', '兄弟情', '姐妹情', '义气', '情义', '生死之交', '义结金兰'],
        'family' => ['亲情', '家人', '家族', '血缘', '血脉', '传承', '传承'],
        'justice' => ['正义', '公道', '公平', '善良', '邪恶', '光明', '黑暗', '真理'],
        'survival' => ['生存', '挣扎', '求生', '绝境', '逆境', '险境', '死里逃生'],
        'identity' => ['身份', '身世', '秘密', '真相', '伪装', '隐藏', '真实'],
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
            'overall_tone' => 'neutral',
            'emotional_range' => null,
            'emotion_intensity' => 'moderate',
            'depth_level' => 'entertaining',
            'thematic_complexity' => 0,
            'themes' => null,
            'aesthetic_style' => null,
            'beauty_description_focus' => null,
            'violence_level' => 'moderate',
            'moral_framework' => null,
            'values_tendency' => null,
            'confidence' => 0,
        ];

        $emotionStats = $this->analyzeEmotions();
        $this->stats['overall_tone'] = $this->determineOverallTone($emotionStats);
        $this->stats['emotional_range'] = $emotionStats;
        $this->stats['emotion_intensity'] = $this->calculateEmotionIntensity($emotionStats);

        $themeStats = $this->analyzeThemes();
        $this->stats['themes'] = $themeStats['primary_themes'];
        $this->stats['thematic_complexity'] = $themeStats['complexity'];

        $this->stats['depth_level'] = $this->analyzeDepthLevel();
        $this->stats['aesthetic_style'] = $this->analyzeAestheticStyle();
        $this->stats['beauty_description_focus'] = $this->analyzeBeautyFocus();
        $this->stats['violence_level'] = $this->analyzeViolenceLevel();
        $this->stats['moral_framework'] = $this->analyzeMoralFramework();
        $this->stats['values_tendency'] = $this->analyzeValuesTendency();
        $this->stats['confidence'] = $this->calculateConfidence();

        return $this->stats;
    }

    private function analyzeEmotions(): array
    {
        $results = [];
        $totalChars = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $totalChars += mb_strlen($text);

            foreach (self::EMOTION_WORDS as $emotion => $words) {
                if (!isset($results[$emotion])) {
                    $results[$emotion] = 0;
                }
                foreach ($words as $word) {
                    $results[$emotion] += mb_substr_count($text, $word);
                }
            }
        }

        $density = [];
        foreach ($results as $emotion => $count) {
            $density[$emotion] = $totalChars > 0 ? round($count * 10000 / $totalChars, 2) : 0;
        }

        return $density;
    }

    private function determineOverallTone(array $emotionStats): string
    {
        $positive = ($emotionStats['joy'] ?? 0) + ($emotionStats['love'] ?? 0);
        $negative = ($emotionStats['anger'] ?? 0) + ($emotionStats['sadness'] ?? 0) + ($emotionStats['fear'] ?? 0) + ($emotionStats['disgust'] ?? 0);

        $ratio = $positive / max(1, $positive + $negative);

        if ($ratio > 0.7) {
            $intensity = ($emotionStats['joy'] ?? 0) + ($emotionStats['sadness'] ?? 0);
            if ($intensity > 10) return 'bittersweet';
            return 'optimistic';
        }
        if ($ratio < 0.3) {
            $fearDisgust = ($emotionStats['fear'] ?? 0) + ($emotionStats['disgust'] ?? 0);
            if ($fearDisgust > 5) return 'dark';
            return 'pessimistic';
        }

        $surprise = $emotionStats['surprise'] ?? 0;
        if ($surprise > 5) return 'uplifting';

        return 'neutral';
    }

    private function calculateEmotionIntensity(array $emotionStats): string
    {
        $total = array_sum($emotionStats);
        $maxSingle = max($emotionStats);

        if ($total > 30 || $maxSingle > 15) return 'intense';
        if ($total < 5) return 'subtle';
        return 'moderate';
    }

    private function analyzeThemes(): array
    {
        $themeCounts = [];
        $totalChars = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $totalChars += mb_strlen($text);

            foreach (self::THEME_KEYWORDS as $theme => $keywords) {
                if (!isset($themeCounts[$theme])) {
                    $themeCounts[$theme] = 0;
                }
                foreach ($keywords as $keyword) {
                    $themeCounts[$theme] += mb_substr_count($text, $keyword);
                }
            }
        }

        arsort($themeCounts);
        $topThemes = array_slice($themeCounts, 0, 5, true);
        $themeDensity = array_sum($topThemes) / max(1, $totalChars) * 10000;

        $complexity = min(1, count(array_filter($topThemes, fn($c) => $c > 5)) / 3);

        return [
            'primary_themes' => array_keys($topThemes),
            'theme_counts' => $topThemes,
            'complexity' => round($complexity, 2),
            'density' => round($themeDensity, 2),
        ];
    }

    private function analyzeDepthLevel(): string
    {
        $philosophicalCount = 0;
        $abstractCount = 0;
        $questionCount = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $philosophicalCount += preg_match_all('/(?:人生|生命|意义|价值|存在|真理|命运|哲学)/u', $text);
            $abstractCount += preg_match_all('/(?:灵魂|精神|意志|思想|信念|信仰|理念)/u', $text);
            $questionCount += preg_match_all('/[？]/u', $text);
        }

        $total = $philosophicalCount + $abstractCount + $questionCount;
        $avgPerChapter = $total / max(1, count($this->chapterTexts));

        if ($avgPerChapter > 5) return 'philosophical';
        if ($avgPerChapter > 2) return 'thoughtful';
        if ($avgPerChapter > 0.5) return 'entertaining';
        return 'surface';
    }

    private function analyzeAestheticStyle(): string
    {
        $styles = [
            'romantic' => 0,
            'realistic' => 0,
            'fantasy' => 0,
            'classical' => 0,
            'modern' => 0,
        ];

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $styles['romantic'] += preg_match_all('/(?:月色|星河|春风|花开|浪漫|柔情|爱意)/u', $text);
            $styles['realistic'] += preg_match_all('/(?:现实|实际|生活|平凡|普通|日常)/u', $text);
            $styles['fantasy'] += preg_match_all('/(?:魔法|修仙|灵力|斗气|异能|神兽|仙鹤)/u', $text);
            $styles['classical'] += preg_match_all('/(?:古风|诗词|江湖|侠客|武林|剑客|王朝)/u', $text);
            $styles['modern'] += preg_match_all('/(?:都市|现代|科技|网络|手机|电脑|公司)/u', $text);
        }

        arsort($styles);
        $topStyle = array_keys($styles)[0];

        return $topStyle;
    }

    private function analyzeBeautyFocus(): array
    {
        $focuses = [
            'nature' => 0,
            'character' => 0,
            'architecture' => 0,
            'action' => 0,
            'emotion' => 0,
        ];

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $focuses['nature'] += preg_match_all('/(?:山川|河流|森林|大海|星空|云彩|日出|日落|花开|叶落)/u', $text);
            $focuses['character'] += preg_match_all('/(?:美貌|容颜|眉眼|长发|白衣|气质|风度)/u', $text);
            $focuses['architecture'] += preg_match_all('/(?:宫殿|庙宇|城池|高楼|庭院|园林|楼阁)/u', $text);
            $focuses['action'] += preg_match_all('/(?:挥剑|出拳|奔跑|跳跃|飞身|暴起|凌空)/u', $text);
            $focuses['emotion'] += preg_match_all('/(?:心动|心痛|心碎|心暖|心酸|心醉)/u', $text);
        }

        arsort($focuses);
        $topFocuses = array_keys(array_slice($focuses, 0, 3, true));

        return $topFocuses;
    }

    private function analyzeViolenceLevel(): string
    {
        $violenceCount = 0;
        $goreCount = 0;
        $totalChars = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $totalChars += mb_strlen($text);

            $violenceCount += preg_match_all('/(?:死亡|杀死|斩杀|击杀|斩杀|屠戮|血战)/u', $text);
            $goreCount += preg_match_all('/(?:鲜血|血泊|血肉|血腥|血溅|血染)/u', $text);
        }

        $ratio = ($violenceCount + $goreCount) / max(1, $totalChars) * 10000;

        if ($ratio > 5) return 'graphic';
        if ($ratio > 2) return 'moderate';
        if ($ratio > 0.5) return 'mild';
        return 'none';
    }

    private function analyzeMoralFramework(): string
    {
        $moralKeywords = [
            'karma' => ['因果', '报应', '善恶', '轮回', '天理', '公道'],
            'individualism' => ['自我', '自由', '个性', '解放', '突破'],
            'collectivism' => ['集体', '家族', '门派', '国家', '使命'],
            'relativism' => ['灰色', '复杂', '无奈', '身不由己', '立场'],
        ];

        $scores = [];

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            foreach ($moralKeywords as $framework => $keywords) {
                if (!isset($scores[$framework])) $scores[$framework] = 0;
                foreach ($keywords as $kw) {
                    $scores[$framework] += mb_substr_count($text, $kw);
                }
            }
        }

        if (empty($scores)) return 'balanced';

        arsort($scores);
        $topFramework = array_keys($scores)[0];

        return match ($topFramework) {
            'karma' => '善恶有报',
            'individualism' => '个人英雄主义',
            'collectivism' => '集体主义',
            'relativism' => '道德相对主义',
            default => 'balanced',
        };
    }

    private function analyzeValuesTendency(): array
    {
        $values = [
            'success' => ['成功', '成就', '胜利', '荣耀', '辉煌'],
            'love' => ['真情', '爱情', '守护', '陪伴', '温暖'],
            'freedom' => ['自由', '解脱', '解放', '翱翔', '无拘'],
            'justice' => ['正义', '公道', '光明', '真理', '守护'],
            'family' => ['家族', '传承', '血脉', '责任', '担当'],
        ];

        $scores = [];

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            foreach ($values as $value => $keywords) {
                if (!isset($scores[$value])) $scores[$value] = 0;
                foreach ($keywords as $kw) {
                    $scores[$value] += mb_substr_count($text, $kw);
                }
            }
        }

        arsort($scores);
        return array_keys(array_slice($scores, 0, 3, true));
    }

    private function calculateConfidence(): float
    {
        $chapterCount = count($this->chapterTexts);
        $chapterScore = min(1, $chapterCount / 5) * 0.6;

        $emotionSum = array_sum($this->stats['emotional_range'] ?? []);
        $emotionScore = min(1, $emotionSum / 50) * 0.4;

        return round($chapterScore + $emotionScore, 2);
    }
}
