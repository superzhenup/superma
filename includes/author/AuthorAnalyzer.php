<?php
/**
 * 作者画像分析引擎 - 作者画像系统核心
 * 协调各维度分析器，生成完整作者画像
 */

require_once __DIR__ . '/WritingHabitAnalyzer.php';
require_once __DIR__ . '/NarrativeAnalyzer.php';
require_once __DIR__ . '/SentimentAnalyzer.php';

class AuthorAnalyzer
{
    private int $profileId;
    private array $chapters = [];
    private array $results = [];

    public function __construct(int $profileId, array $chapters = [])
    {
        $this->profileId = $profileId;
        $this->chapters = $chapters;
    }

    public function analyze(array $chapters = []): array
    {
        if (!empty($chapters)) {
            $this->chapters = $chapters;
        }

        if (empty($this->chapters)) {
            return [
                'success' => false,
                'error' => '没有可分析的章节内容',
            ];
        }

        $this->updateProfileStatus('analyzing');

        try {
            $this->results = [
                'writing_habits' => $this->analyzeWritingHabits(),
                'narrative_style' => $this->analyzeNarrativeStyle(),
                'sentiment' => $this->analyzeSentiment(),
                'creative_identity' => $this->analyzeCreativeIdentity(),
            ];

            $this->saveAnalysisResults();
            $this->updateProfileStatus('completed');

            return [
                'success' => true,
                'profile_id' => $this->profileId,
                'results' => $this->results,
                'summary' => $this->generateSummary(),
            ];
        } catch (\Throwable $e) {
            $this->updateProfileStatus('failed');
            error_log('AuthorAnalyzer failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 分析单个维度
     * @param string $dimension 维度名: writing_habits, narrative_style, sentiment, creative_identity
     */
    public function analyzeDimension(string $dimension): array
    {
        if (empty($this->chapters)) {
            return [
                'success' => false,
                'error' => '没有可分析的章节内容',
            ];
        }

        try {
            switch ($dimension) {
                case 'writing_habits':
                    $result = $this->analyzeWritingHabits();
                    break;
                case 'narrative_style':
                    $result = $this->analyzeNarrativeStyle();
                    break;
                case 'sentiment':
                    $result = $this->analyzeSentiment();
                    break;
                case 'creative_identity':
                    $this->results['writing_habits'] = $this->results['writing_habits'] ?? $this->analyzeWritingHabits();
                    $this->results['narrative_style'] = $this->results['narrative_style'] ?? $this->analyzeNarrativeStyle();
                    $this->results['sentiment'] = $this->results['sentiment'] ?? $this->analyzeSentiment();
                    $result = $this->analyzeCreativeIdentity();
                    break;
                default:
                    return ['success' => false, 'error' => '未知维度'];
            }

            $this->results[$dimension] = $result;
            $this->saveDimensionResult($dimension, $result);

            return [
                'success' => true,
                'dimension' => $dimension,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            error_log("AuthorAnalyzer::analyzeDimension($dimension) failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取所有维度名称
     */
    public static function getDimensions(): array
    {
        return [
            'writing_habits' => ['name' => '写作习惯', 'icon' => '✍️'],
            'narrative_style' => ['name' => '叙事手法', 'icon' => '📖'],
            'sentiment' => ['name' => '思想情感', 'icon' => '💭'],
            'creative_identity' => ['name' => '创作个性', 'icon' => '🎨'],
        ];
    }

    /**
     * 获取当前分析进度
     */
    public function getProgress(): array
    {
        $dimensions = self::getDimensions();
        $completed = 0;
        foreach (array_keys($dimensions) as $dim) {
            if (!empty($this->results[$dim])) $completed++;
        }
        return [
            'completed' => $completed,
            'total' => count($dimensions),
            'dimensions' => $this->results,
        ];
    }

    /**
     * 保存单个维度结果
     */
    private function saveDimensionResult(string $dimension, array $result): void
    {
        switch ($dimension) {
            case 'writing_habits':
                $existingId = DB::fetch('SELECT id FROM author_writing_habits WHERE profile_id=?', [$this->profileId])['id'] ?? null;
                $rowData = [
                    'vocabulary_preference' => json_encode($result['vocabulary_preference'] ?? [], JSON_UNESCAPED_UNICODE),
                    'word_complexity' => $result['word_complexity'] ?? 'moderate',
                    'sentence_length_avg' => $result['sentence_length_avg'] ?? 0,
                    'paragraph_length_avg' => $result['paragraph_length_avg'] ?? 0,
                    'sentence_patterns' => json_encode($result['sentence_patterns'] ?? [], JSON_UNESCAPED_UNICODE),
                    'use_passive' => $result['use_passive'] ?? 0,
                    'use_dialogue' => $result['use_dialogue'] ?? 0,
                    'rhetorical_devices' => json_encode($result['rhetorical_devices'] ?? [], JSON_UNESCAPED_UNICODE),
                    'metaphor_frequency' => $result['metaphor_frequency'] ?? 'medium',
                    'uniqueness_score' => $result['uniqueness_score'] ?? 0,
                    'confidence' => $result['confidence'] ?? 0,
                    'source_chapter_count' => count($this->chapters),
                ];
                if ($existingId) {
                    DB::update('author_writing_habits', $rowData, 'id=?', [$existingId]);
                } else {
                    $rowData['profile_id'] = $this->profileId;
                    DB::insert('author_writing_habits', $rowData);
                }
                break;

            case 'narrative_style':
                $existingId = DB::fetch('SELECT id FROM author_narrative_styles WHERE profile_id=?', [$this->profileId])['id'] ?? null;
                $rowData = [
                    'narrative_pov' => $result['narrative_pov'] ?? 'third_limited',
                    'pov_switch_frequency' => $result['pov_switch_frequency'] ?? 'rare',
                    'pacing_type' => $result['pacing_type'] ?? 'medium',
                    'scene_transition_style' => $result['scene_transition_style'] ?? null,
                    'tension_curve' => json_encode($result['tension_curve'] ?? [], JSON_UNESCAPED_UNICODE),
                    'chapter_structure' => $result['chapter_structure'] ?? 'linear',
                    'cliffhanger_usage' => $result['cliffhanger_usage'] ?? 0,
                    'interior_monologue' => $result['interior_monologue'] ?? 0,
                    'description_density' => $result['description_density'] ?? 'moderate',
                    'confidence' => $result['confidence'] ?? 0,
                ];
                if ($existingId) {
                    DB::update('author_narrative_styles', $rowData, 'id=?', [$existingId]);
                } else {
                    $rowData['profile_id'] = $this->profileId;
                    DB::insert('author_narrative_styles', $rowData);
                }
                break;

            case 'sentiment':
                $existingId = DB::fetch('SELECT id FROM author_sentiment_analysis WHERE profile_id=?', [$this->profileId])['id'] ?? null;
                $rowData = [
                    'overall_tone' => $result['overall_tone'] ?? 'neutral',
                    'emotional_range' => json_encode($result['emotional_range'] ?? [], JSON_UNESCAPED_UNICODE),
                    'emotion_intensity' => $result['emotion_intensity'] ?? 'moderate',
                    'depth_level' => $result['depth_level'] ?? 'entertaining',
                    'thematic_complexity' => $result['thematic_complexity'] ?? 0,
                    'themes' => json_encode($result['themes'] ?? [], JSON_UNESCAPED_UNICODE),
                    'aesthetic_style' => $result['aesthetic_style'] ?? null,
                    'beauty_description_focus' => json_encode($result['beauty_description_focus'] ?? [], JSON_UNESCAPED_UNICODE),
                    'violence_level' => $result['violence_level'] ?? 'moderate',
                    'moral_framework' => $result['moral_framework'] ?? null,
                    'values_tendency' => json_encode($result['values_tendency'] ?? [], JSON_UNESCAPED_UNICODE),
                    'confidence' => $result['confidence'] ?? 0,
                ];
                if ($existingId) {
                    DB::update('author_sentiment_analysis', $rowData, 'id=?', [$existingId]);
                } else {
                    $rowData['profile_id'] = $this->profileId;
                    DB::insert('author_sentiment_analysis', $rowData);
                }
                break;

            case 'creative_identity':
                $existingId = DB::fetch('SELECT id FROM author_creative_identity WHERE profile_id=?', [$this->profileId])['id'] ?? null;
                $rowData = [
                    'signature_phrases' => json_encode($result['signature_phrases'] ?? [], JSON_UNESCAPED_UNICODE),
                    'unique_techniques' => json_encode($result['unique_techniques'] ?? [], JSON_UNESCAPED_UNICODE),
                    'trademark_elements' => json_encode($result['trademark_elements'] ?? [], JSON_UNESCAPED_UNICODE),
                    'genre_preferences' => json_encode($result['genre_preferences'] ?? [], JSON_UNESCAPED_UNICODE),
                    'character_archetype_favorites' => json_encode($result['character_archetype_favorites'] ?? [], JSON_UNESCAPED_UNICODE),
                    'plot_preferences' => json_encode($result['plot_preferences'] ?? [], JSON_UNESCAPED_UNICODE),
                    'style_tags' => json_encode($result['style_tags'] ?? [], JSON_UNESCAPED_UNICODE),
                    'influence_sources' => json_encode($result['influence_sources'] ?? [], JSON_UNESCAPED_UNICODE),
                    'writing_voice' => $result['writing_voice'] ?? null,
                    'editing_style' => $result['editing_style'] ?? 'moderate',
                    'planning_style' => $result['planning_style'] ?? 'hybrid',
                    'confidence' => $result['confidence'] ?? 0,
                ];
                if ($existingId) {
                    DB::update('author_creative_identity', $rowData, 'id=?', [$existingId]);
                } else {
                    $rowData['profile_id'] = $this->profileId;
                    DB::insert('author_creative_identity', $rowData);
                }
                break;
        }
    }

    private function analyzeWritingHabits(): array
    {
        $analyzer = new WritingHabitAnalyzer($this->chapters);
        return $analyzer->analyze();
    }

    private function analyzeNarrativeStyle(): array
    {
        $analyzer = new NarrativeAnalyzer($this->chapters);
        return $analyzer->analyze();
    }

    private function analyzeSentiment(): array
    {
        $analyzer = new SentimentAnalyzer($this->chapters);
        return $analyzer->analyze();
    }

    private function analyzeCreativeIdentity(): array
    {
        $allText = '';
        foreach ($this->chapters as $chapter) {
            $allText .= $chapter['content'] ?? '';
        }

        $signaturePhrases = $this->extractSignaturePhrases($allText);
        $uniqueTechniques = $this->detectUniqueTechniques();
        $genrePreferences = $this->detectGenrePreferences($allText);

        return [
            'signature_phrases' => $signaturePhrases,
            'unique_techniques' => $uniqueTechniques,
            'trademark_elements' => $this->detectTrademarkElements($allText),
            'genre_preferences' => $genrePreferences,
            'character_archetype_favorites' => $this->detectCharacterArchetypes(),
            'plot_preferences' => $this->detectPlotPreferences($allText),
            'style_tags' => $this->generateStyleTags(),
            'influence_sources' => [],
            'writing_voice' => $this->describeWritingVoice(),
            'writing_schedule' => null,
            'editing_style' => $this->detectEditingStyle(),
            'planning_style' => $this->detectPlanningStyle(),
            'confidence' => round(count($this->chapters) / 10 * 0.6 + 0.4, 2),
        ];
    }

    private function extractSignaturePhrases(string $text): array
    {
        $patterns = [
            '/(?:“[^”]{5,20}”)/u',
            '/(?:【[^】]{5,20}】)/u',
            '/(?:「[^」]{5,20}」)/u',
        ];

        $phrases = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            $phrases = array_merge($phrases, $matches[0] ?? []);
        }

        $phraseFreq = array_count_values($phrases);
        arsort($phraseFreq);

        return array_keys(array_slice($phraseFreq, 0, 10, true));
    }

    private function detectUniqueTechniques(): array
    {
        $techniques = [];

        foreach ($this->chapters as $chapter) {
            $text = $chapter['content'] ?? '';

            if (preg_match_all('/(?:镜头|画面一转|视角切换)/u', $text)) {
                $techniques['cinematic_editing'] = '电影化镜头语言';
            }
            if (preg_match_all('/(?:心想|暗想|内心独白)/u', $text) > 3) {
                $techniques['interior_monologue'] = '大量内心独白';
            }
            if (preg_match_all('/(?:蒙太奇|闪回|回忆)/u', $text)) {
                $techniques['montage'] = '蒙太奇叙事';
            }
            if (preg_match_all('/(?:快进|时光流转|岁月如梭)/u', $text) > 2) {
                $techniques['time_compression'] = '时间压缩叙事';
            }
            if (preg_match_all('/(?:与此同时|另一边)/u', $text) > 3) {
                $techniques['parallel_narrative'] = '多线并行叙事';
            }
        }

        return array_values($techniques);
    }

    private function detectGenrePreferences(string $text): array
    {
        $genreIndicators = [
            'fantasy' => ['魔法', '修仙', '斗气', '灵力', '神兽', '仙界', '修士', '境界', '功法'],
            'xianxia' => ['渡劫', '飞升', '元婴', '金丹', '天道', '因果', '业火', '道心'],
            'urban' => ['都市', '现代', '商战', '职场', '总裁', '豪门', '都市'],
            'romance' => ['爱情', '甜蜜', '心动', '告白', '约会', '恋人', '深情'],
            'martial_arts' => ['武林', '江湖', '剑法', '拳法', '掌门', '门派', '武林盟主'],
            'scifi' => ['星际', '飞船', '科技', 'AI', '机器人', '赛博', '未来'],
            'historical' => ['古代', '王朝', '皇宫', '将军', '皇子', '丞相', '盛世'],
            'horror' => ['恐怖', '惊悚', '诡异', '灵异', '鬼魂', '诅咒'],
        ];

        $scores = [];
        foreach ($genreIndicators as $genre => $keywords) {
            $count = 0;
            foreach ($keywords as $kw) {
                $count += mb_substr_count($text, $kw);
            }
            if ($count > 0) {
                $scores[$genre] = $count;
            }
        }

        arsort($scores);
        return array_keys(array_slice($scores, 0, 3, true));
    }

    private function detectCharacterArchetypes(): array
    {
        $archetypes = [
            'hero' => ['主角', '英雄', '强者', '天才'],
            'mentor' => ['师父', '导师', '前辈', '长老'],
            'villain' => ['反派', 'boss', '敌人', '魔头'],
            'love_interest' => ['女主', '男主', '心上人', '意中人'],
            'comic_relief' => ['搞笑', '逗比', '活宝', '话痨'],
            'lancer' => ['搭档', '兄弟', '闺蜜', '基友'],
        ];

        $favorites = [];
        foreach ($this->chapters as $chapter) {
            $text = $chapter['content'] ?? '';
            foreach ($archetypes as $archetype => $keywords) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($text, $kw) !== false) {
                        $favorites[$archetype] = ($favorites[$archetype] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($favorites);
        return array_keys(array_slice($favorites, 0, 3, true));
    }

    private function detectPlotPreferences(string $text): array
    {
        $preferences = [];

        if (mb_substr_count($text, '误会') > 3) $preferences[] = '误会流';
        if (mb_substr_count($text, '打脸') > 2) $preferences[] = '打脸流';
        if (mb_substr_count($text, '系统') > 5) $preferences[] = '系统流';
        if (mb_substr_count($text, '穿越') > 2) $preferences[] = '穿越流';
        if (mb_substr_count($text, '重生') > 2) $preferences[] = '重生流';
        if (mb_substr_count($text, '退婚') > 1) $preferences[] = '退婚流';
        if (mb_substr_count($text, '扮猪吃虎') > 1) $preferences[] = '扮猪吃虎';
        if (mb_substr_count($text, '逆袭') > 2) $preferences[] = '逆袭流';

        return array_unique($preferences);
    }

    private function detectTrademarkElements(string $text): array
    {
        $elements = [];

        if (mb_substr_count($text, '白衣') > 5) $elements[] = '白衣形象';
        if (mb_substr_count($text, '黑衣') > 3) $elements[] = '黑衣形象';
        if (mb_substr_count($text, '冷傲') > 2) $elements[] = '冷傲性格';
        if (mb_substr_count($text, '腹黑') > 2) $elements[] = '腹黑性格';
        if (preg_match_all('/(?:天空中|星河璀璨)/u', $text) > 2) $elements[] = '星空意象';
        if (preg_match_all('/(?:鲜血|血泊)/u', $text) > 5) $elements[] = '血腥描写';
        if (preg_match_all('/(?:月色|银辉)/u', $text) > 3) $elements[] = '月色意象';

        return $elements;
    }

    private function generateStyleTags(): array
    {
        $tags = [];

        $narrative = $this->results['narrative_style'] ?? [];
        if (!empty($narrative)) {
            if (($narrative['pacing_type'] ?? '') === 'fast') $tags[] = '快节奏';
            if (($narrative['pacing_type'] ?? '') === 'slow') $tags[] = '慢节奏';
            if (($narrative['narrative_pov'] ?? '') === 'first_person') $tags[] = '第一人称';
            if (($narrative['cliffhanger_usage'] ?? 0) > 0.5) $tags[] = '悬念大师';
        }

        $sentiment = $this->results['sentiment'] ?? [];
        if (!empty($sentiment)) {
            if (($sentiment['overall_tone'] ?? '') === 'optimistic') $tags[] = '积极向上';
            if (($sentiment['overall_tone'] ?? '') === 'dark') $tags[] = '暗黑风格';
            if (($sentiment['depth_level'] ?? '') === 'philosophical') $tags[] = '深度思考';
        }

        $writingHabits = $this->results['writing_habits'] ?? [];
        if (!empty($writingHabits)) {
            if (($writingHabits['word_complexity'] ?? '') === 'complex') $tags[] = '文笔华丽';
            if (($writingHabits['word_complexity'] ?? '') === 'simple') $tags[] = '简洁明了';
        }

        return array_unique($tags);
    }

    private function describeWritingVoice(): string
    {
        $habits = $this->results['writing_habits'] ?? [];
        $narrative = $this->results['narrative_style'] ?? [];
        $sentiment = $this->results['sentiment'] ?? [];

        $descriptions = [];

        if (($narrative['pacing_type'] ?? '') === 'fast') {
            $descriptions[] = '节奏明快，情节推进迅速';
        } elseif (($narrative['pacing_type'] ?? '') === 'slow') {
            $descriptions[] = '节奏舒缓，注重氛围营造';
        }

        if (($sentiment['overall_tone'] ?? '') === 'optimistic') {
            $descriptions[] = '充满正能量，情感积极向上';
        } elseif (($sentiment['overall_tone'] ?? '') === 'dark') {
            $descriptions[] = '基调偏暗，擅长刻画复杂人性';
        }

        if (($habits['use_dialogue'] ?? 0) > 0.02) {
            $descriptions[] = '对话密集，角色互动生动';
        }

        if (($habits['metaphor_frequency'] ?? '') === 'high') {
            $descriptions[] = '善用修辞，想象力丰富';
        }

        return implode('；', $descriptions) ?: '文风独特，值得细品';
    }

    private function detectEditingStyle(): string
    {
        $chapterLengths = array_map(fn($c) => mb_strlen($c['content'] ?? ''), $this->chapters);
        $variance = $this->calculateVariance($chapterLengths);

        if ($variance > 500000) return 'extensive';
        if ($variance > 200000) return 'moderate';
        return 'minimal';
    }

    private function detectPlanningStyle(): string
    {
        $chapterLengths = array_map(fn($c) => mb_strlen($c['content'] ?? ''), $this->chapters);
        $avgLen = array_sum($chapterLengths) / count($chapterLengths);
        $normalizedLengths = array_map(fn($l) => $l / $avgLen, $chapterLengths);

        $variance = $this->calculateVariance($normalizedLengths);

        if ($variance < 0.1) return 'plotter';
        if ($variance > 0.5) return 'pantser';
        return 'hybrid';
    }

    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) return 0;
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        return array_sum($squaredDiffs) / count($values);
    }

    private function saveAnalysisResults(): void
    {
        $writingHabits = $this->results['writing_habits'] ?? [];
        $narrative = $this->results['narrative_style'] ?? [];
        $sentiment = $this->results['sentiment'] ?? [];
        $creative = $this->results['creative_identity'] ?? [];

        if (!empty($writingHabits)) {
            $habitId = DB::fetch('SELECT id FROM author_writing_habits WHERE profile_id=?', [$this->profileId])['id'] ?? null;
            $habitData = [
                'vocabulary_preference' => json_encode($writingHabits['vocabulary_preference'] ?? [], JSON_UNESCAPED_UNICODE),
                'word_complexity' => $writingHabits['word_complexity'] ?? 'moderate',
                'sentence_length_avg' => $writingHabits['sentence_length_avg'] ?? 0,
                'paragraph_length_avg' => $writingHabits['paragraph_length_avg'] ?? 0,
                'sentence_patterns' => json_encode($writingHabits['sentence_patterns'] ?? [], JSON_UNESCAPED_UNICODE),
                'use_passive' => $writingHabits['use_passive'] ?? 0,
                'use_dialogue' => $writingHabits['use_dialogue'] ?? 0,
                'rhetorical_devices' => json_encode($writingHabits['rhetorical_devices'] ?? [], JSON_UNESCAPED_UNICODE),
                'metaphor_frequency' => $writingHabits['metaphor_frequency'] ?? 'medium',
                'uniqueness_score' => $writingHabits['uniqueness_score'] ?? 0,
                'confidence' => $writingHabits['confidence'] ?? 0,
                'source_chapter_count' => count($this->chapters),
            ];
            if ($habitId) {
                DB::update('author_writing_habits', $habitData, 'id=?', [$habitId]);
            } else {
                $habitData['profile_id'] = $this->profileId;
                DB::insert('author_writing_habits', $habitData);
            }
        }

        if (!empty($narrative)) {
            $narrId = DB::fetch('SELECT id FROM author_narrative_styles WHERE profile_id=?', [$this->profileId])['id'] ?? null;
            $narrData = [
                'narrative_pov' => $narrative['narrative_pov'] ?? 'third_limited',
                'pov_switch_frequency' => $narrative['pov_switch_frequency'] ?? 'rare',
                'pacing_type' => $narrative['pacing_type'] ?? 'medium',
                'scene_transition_style' => $narrative['scene_transition_style'],
                'tension_curve' => json_encode($narrative['tension_curve'] ?? [], JSON_UNESCAPED_UNICODE),
                'chapter_structure' => $narrative['chapter_structure'] ?? 'linear',
                'cliffhanger_usage' => $narrative['cliffhanger_usage'] ?? 0,
                'interior_monologue' => $narrative['interior_monologue'] ?? 0,
                'description_density' => $narrative['description_density'] ?? 'moderate',
                'confidence' => $narrative['confidence'] ?? 0,
            ];
            if ($narrId) {
                DB::update('author_narrative_styles', $narrData, 'id=?', [$narrId]);
            } else {
                $narrData['profile_id'] = $this->profileId;
                DB::insert('author_narrative_styles', $narrData);
            }
        }

        if (!empty($sentiment)) {
            $sentId = DB::fetch('SELECT id FROM author_sentiment_analysis WHERE profile_id=?', [$this->profileId])['id'] ?? null;
            $sentData = [
                'overall_tone' => $sentiment['overall_tone'] ?? 'neutral',
                'emotional_range' => json_encode($sentiment['emotional_range'] ?? [], JSON_UNESCAPED_UNICODE),
                'emotion_intensity' => $sentiment['emotion_intensity'] ?? 'moderate',
                'depth_level' => $sentiment['depth_level'] ?? 'entertaining',
                'thematic_complexity' => $sentiment['thematic_complexity'] ?? 0,
                'themes' => json_encode($sentiment['themes'] ?? [], JSON_UNESCAPED_UNICODE),
                'aesthetic_style' => $sentiment['aesthetic_style'],
                'beauty_description_focus' => json_encode($sentiment['beauty_description_focus'] ?? [], JSON_UNESCAPED_UNICODE),
                'violence_level' => $sentiment['violence_level'] ?? 'moderate',
                'moral_framework' => $sentiment['moral_framework'],
                'values_tendency' => json_encode($sentiment['values_tendency'] ?? [], JSON_UNESCAPED_UNICODE),
                'confidence' => $sentiment['confidence'] ?? 0,
            ];
            if ($sentId) {
                DB::update('author_sentiment_analysis', $sentData, 'id=?', [$sentId]);
            } else {
                $sentData['profile_id'] = $this->profileId;
                DB::insert('author_sentiment_analysis', $sentData);
            }
        }

        if (!empty($creative)) {
            $creaId = DB::fetch('SELECT id FROM author_creative_identity WHERE profile_id=?', [$this->profileId])['id'] ?? null;
            $creaData = [
                'signature_phrases' => json_encode($creative['signature_phrases'] ?? [], JSON_UNESCAPED_UNICODE),
                'unique_techniques' => json_encode($creative['unique_techniques'] ?? [], JSON_UNESCAPED_UNICODE),
                'trademark_elements' => json_encode($creative['trademark_elements'] ?? [], JSON_UNESCAPED_UNICODE),
                'genre_preferences' => json_encode($creative['genre_preferences'] ?? [], JSON_UNESCAPED_UNICODE),
                'character_archetype_favorites' => json_encode($creative['character_archetype_favorites'] ?? [], JSON_UNESCAPED_UNICODE),
                'plot_preferences' => json_encode($creative['plot_preferences'] ?? [], JSON_UNESCAPED_UNICODE),
                'style_tags' => json_encode($creative['style_tags'] ?? [], JSON_UNESCAPED_UNICODE),
                'influence_sources' => json_encode($creative['influence_sources'] ?? [], JSON_UNESCAPED_UNICODE),
                'writing_voice' => $creative['writing_voice'],
                'editing_style' => $creative['editing_style'] ?? 'moderate',
                'planning_style' => $creative['planning_style'] ?? 'hybrid',
                'confidence' => $creative['confidence'] ?? 0,
            ];
            if ($creaId) {
                DB::update('author_creative_identity', $creaData, 'id=?', [$creaId]);
            } else {
                $creaData['profile_id'] = $this->profileId;
                DB::insert('author_creative_identity', $creaData);
            }
        }
    }

    private function updateProfileStatus(string $status): void
    {
        try {
            DB::update('author_profiles', ['analysis_status' => $status], 'id=?', [$this->profileId]);
        } catch (\Throwable $e) {
            error_log('Failed to update profile status: ' . $e->getMessage());
        }
    }

    private function generateSummary(): array
    {
        $narrative = $this->results['narrative_style'] ?? [];
        $sentiment = $this->results['sentiment'] ?? [];
        $creative = $this->results['creative_identity'] ?? [];

        $povLabels = [
            'first_person' => '第一人称',
            'second_person' => '第二人称',
            'third_limited' => '第三人称限视',
            'third_omniscient' => '第三人称全知',
            'multiple' => '多视角',
        ];

        $toneLabels = [
            'optimistic' => '积极乐观',
            'pessimistic' => '消极悲观',
            'neutral' => '中立客观',
            'bittersweet' => '苦乐参半',
            'dark' => '暗黑压抑',
            'uplifting' => '振奋人心',
        ];

        return [
            'profile_name' => '自动生成的作者画像',
            'pov' => $povLabels[$narrative['narrative_pov'] ?? ''] ?? '第三人称',
            'pacing' => ($narrative['pacing_type'] ?? '') === 'fast' ? '快节奏' : (($narrative['pacing_type'] ?? '') === 'slow' ? '慢节奏' : '中等节奏'),
            'tone' => $toneLabels[$sentiment['overall_tone'] ?? ''] ?? '中立',
            'primary_genres' => implode('、', array_slice($creative['genre_preferences'] ?? [], 0, 3)),
            'writing_voice' => $creative['writing_voice'] ?? '风格独特',
            'analyzed_chapters' => count($this->chapters),
        ];
    }

    public static function analyzeFromWork(int $profileId, array $workData): array
    {
        if (empty($workData['chapters'])) {
            return [
                'success' => false,
                'error' => '作品没有章节数据',
            ];
        }

        $analyzer = new self($profileId, $workData['chapters']);
        return $analyzer->analyze();
    }

    public function generatePrompts(): array
    {
        $prompts = [];
        
        $writingHabits = $this->results['writing_habits'] ?? [];
        $narrative = $this->results['narrative_style'] ?? [];
        $sentiment = $this->results['sentiment'] ?? [];
        $creative = $this->results['creative_identity'] ?? [];

        $povLabels = [
            'first_person' => '第一人称',
            'second_person' => '第二人称',
            'third_limited' => '第三人称限视',
            'third_omniscient' => '第三人称全知',
            'multiple' => '多视角',
        ];

        $toneLabels = [
            'optimistic' => '积极乐观',
            'pessimistic' => '消极悲观',
            'neutral' => '中立客观',
            'bittersweet' => '苦乐参半',
            'dark' => '暗黑压抑',
            'uplifting' => '振奋人心',
        ];

        $sentimentLabels = [
            'joy' => '喜悦',
            'sadness' => '悲伤',
            'anger' => '愤怒',
            'fear' => '恐惧',
            'love' => '爱',
            'hate' => '恨',
            'hope' => '希望',
            'despair' => '绝望',
        ];

        $prompts['writing_habits'] = $this->generateWritingHabitsPrompt($writingHabits);
        $prompts['narrative_style'] = $this->generateNarrativeStylePrompt($narrative, $povLabels);
        $prompts['sentiment'] = $this->generateSentimentPrompt($sentiment, $toneLabels, $sentimentLabels);
        $prompts['creative_identity'] = $this->generateCreativeIdentityPrompt($creative);

        return $prompts;
    }

    private function generateWritingHabitsPrompt(array $data): string
    {
        $habits = [];
        
        if (!empty($data['sentence_length'])) {
            $length = $data['sentence_length'];
            if ($length['short'] > $length['medium'] && $length['short'] > $length['long']) {
                $habits[] = '偏好使用短句，语言简洁有力';
            } elseif ($length['long'] > $length['short'] && $length['long'] > $length['medium']) {
                $habits[] = '擅长使用长句，句式结构复杂优美';
            } else {
                $habits[] = '句式长短适中，节奏感良好';
            }
        }

        if (!empty($data['paragraph_length'])) {
            $para = $data['paragraph_length'];
            if ($para['short'] > $para['medium'] && $para['short'] > $para['long']) {
                $habits[] = '段落简短精炼';
            } elseif ($para['long'] > $para['short'] && $para['long'] > $para['medium']) {
                $habits[] = '段落结构完整，内容丰富';
            }
        }

        if (!empty($data['dialogue_ratio']) && $data['dialogue_ratio'] > 40) {
            $habits[] = '对话占比较高，人物互动频繁';
        } elseif (!empty($data['dialogue_ratio'])) {
            $habits[] = '描写细腻，对话与叙述平衡';
        }

        if (!empty($data['vocabulary_richness']) && $data['vocabulary_richness'] > 0.7) {
            $habits[] = '词汇丰富，用词精准';
        }

        if (empty($habits)) {
            return '写作习惯待分析';
        }

        return implode('，', $habits) . '。';
    }

    private function generateNarrativeStylePrompt(array $data, array $povLabels): string
    {
        $elements = [];

        if (!empty($data['narrative_pov'])) {
            $elements[] = '采用' . ($povLabels[$data['narrative_pov']] ?? '第三人称') . '叙事视角';
        }

        if (!empty($data['pacing_type'])) {
            $pacing = $data['pacing_type'];
            if ($pacing === 'fast') {
                $elements[] = '节奏紧凑，情节推进迅速';
            } elseif ($pacing === 'slow') {
                $elements[] = '节奏舒缓，注重细节描写';
            } else {
                $elements[] = '叙事节奏适中';
            }
        }

        if (!empty($data['scene_complexity']) && $data['scene_complexity'] > 0.6) {
            $elements[] = '场景转换复杂，多线叙事';
        } else {
            $elements[] = '叙事结构清晰，脉络分明';
        }

        if (!empty($data['description_ratio']) && $data['description_ratio'] > 50) {
            $elements[] = '描写细腻生动，画面感强';
        }

        if (empty($elements)) {
            return '叙事风格待分析';
        }

        return implode('，', $elements) . '。';
    }

    private function generateSentimentPrompt(array $data, array $toneLabels, array $sentimentLabels): string
    {
        $elements = [];

        if (!empty($data['overall_tone'])) {
            $elements[] = '整体基调' . ($toneLabels[$data['overall_tone']] ?? '平和');
        }

        if (!empty($data['emotional_intensity']) && $data['emotional_intensity'] > 0.6) {
            $elements[] = '情感表达强烈饱满';
        } elseif (!empty($data['emotional_intensity'])) {
            $elements[] = '情感表达含蓄内敛';
        }

        if (!empty($data['dominant_emotions'])) {
            $emotions = array_slice($data['dominant_emotions'], 0, 3);
            $emotionLabels = [];
            foreach ($emotions as $emotion) {
                $emotionLabels[] = $sentimentLabels[$emotion] ?? $emotion;
            }
            if (!empty($emotionLabels)) {
                $elements[] = '主要情感包含' . implode('、', $emotionLabels);
            }
        }

        if (!empty($data['themes'])) {
            $themes = array_slice($data['themes'], 0, 3);
            $elements[] = '主题涉及' . implode('、', $themes);
        }

        if (empty($elements)) {
            return '思想情感待分析';
        }

        return implode('，', $elements) . '。';
    }

    private function generateCreativeIdentityPrompt(array $data): string
    {
        $elements = [];

        if (!empty($data['writing_voice'])) {
            $elements[] = '文风' . $data['writing_voice'];
        }

        if (!empty($data['genre_preferences'])) {
            $genres = array_slice($data['genre_preferences'], 0, 3);
            $elements[] = '擅长' . implode('、', $genres) . '类型';
        }

        if (!empty($data['style_tags'])) {
            $tags = array_slice($data['style_tags'], 0, 4);
            $elements[] = '风格标签：' . implode('、', $tags);
        }

        if (!empty($data['unique_elements'])) {
            $unique = array_slice($data['unique_elements'], 0, 2);
            $elements[] = '独特之处：' . implode('、', $unique);
        }

        if (empty($elements)) {
            return '创作个性待分析';
        }

        return implode('，', $elements) . '。';
    }
}
