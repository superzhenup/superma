<?php
/**
 * 作者画像数据模型 - 作者画像系统
 * 提供画像的 CRUD 操作和格式化输出
 */

class AuthorProfile
{
    private int $id;
    private ?int $userId = null;
    private array $data = [];
    private array $habits = [];
    private array $narrative = [];
    private array $sentiment = [];
    private array $creative = [];

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->load();
    }

    public static function create(array $data): ?self
    {
        $defaults = [
            'profile_name' => '新建画像',
            'analysis_status' => 'pending',
            'is_default' => 0,
            'usage_count' => 0,
        ];

        $data = array_merge($defaults, $data);

        try {
            $id = DB::insert('author_profiles', $data);
            if ($id) {
                return new self($id);
            }
        } catch (\Throwable $e) {
            error_log('AuthorProfile::create failed: ' . $e->getMessage());
        }

        return null;
    }

    public static function find(int $id): ?self
    {
        $exists = DB::fetch('SELECT id FROM author_profiles WHERE id=?', [$id]);
        if (!$exists) return null;
        return new self($id);
    }

    public static function findByUser(?int $userId): array
    {
        if ($userId === null) {
            $profiles = DB::fetchAll(
                'SELECT * FROM author_profiles ORDER BY is_default DESC, updated_at DESC'
            );
        } else {
            $profiles = DB::fetchAll(
                'SELECT * FROM author_profiles WHERE user_id=? OR user_id IS NULL ORDER BY is_default DESC, updated_at DESC',
                [$userId]
            );
        }
        return array_map(fn($p) => (new self($p['id']))->toArray(), $profiles ?: []);
    }

    public static function getDefault(): ?self
    {
        $row = DB::fetch('SELECT id FROM author_profiles WHERE is_default=1 LIMIT 1');
        if (!$row) {
            $row = DB::fetch('SELECT id FROM author_profiles ORDER BY usage_count DESC LIMIT 1');
        }
        if (!$row) return null;
        return new self($row['id']);
    }

    public static function listAll(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'analysis_status = ?';
            $params[] = $filters['status'];
        }

        $countSql = 'SELECT COUNT(*) FROM author_profiles WHERE ' . implode(' AND ', $where);
        $total = (int)DB::fetch($countSql, $params)['COUNT(*)'] ?: 0;

        $offset = ($page - 1) * $pageSize;
        $sql = 'SELECT * FROM author_profiles WHERE ' . implode(' AND ', $where) . " ORDER BY updated_at DESC LIMIT $offset, $pageSize";

        $rows = DB::fetchAll($sql, $params) ?: [];

        return [
            'items' => array_map(fn($p) => (new self($p['id']))->toArray(), $rows),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize),
        ];
    }

    private function load(): void
    {
        $this->data = DB::fetch('SELECT * FROM author_profiles WHERE id=?', [$this->id]) ?: [];

        if (!empty($this->data)) {
            $this->habits = DB::fetch('SELECT * FROM author_writing_habits WHERE profile_id=?', [$this->id]) ?: [];
            $this->narrative = DB::fetch('SELECT * FROM author_narrative_styles WHERE profile_id=?', [$this->id]) ?: [];
            $this->sentiment = DB::fetch('SELECT * FROM author_sentiment_analysis WHERE profile_id=?', [$this->id]) ?: [];
            $this->creative = DB::fetch('SELECT * FROM author_creative_identity WHERE profile_id=?', [$this->id]) ?: [];
        }
    }

    public function update(array $data): bool
    {
        $allowed = [
            'profile_name', 'avatar_url', 'gender', 'age_range', 'mbti', 'constellation',
            'occupation', 'education_bg', 'writing_experience', 'influences', 'is_default',
            'writing_habits_prompt', 'narrative_style_prompt', 'sentiment_prompt', 'creative_identity_prompt',
        ];

        $updateData = array_intersect_key($data, array_flip($allowed));

        if (isset($updateData['is_default']) && $updateData['is_default']) {
            DB::update('author_profiles', ['is_default' => 0], '1=1');
        }

        try {
            DB::update('author_profiles', $updateData, 'id=?', [$this->id]);
            $this->data = array_merge($this->data, $updateData);
            return true;
        } catch (\Throwable $e) {
            error_log('AuthorProfile::update failed: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(): bool
    {
        try {
            DB::delete('author_profiles', 'id=?', [$this->id]);
            return true;
        } catch (\Throwable $e) {
            error_log('AuthorProfile::delete failed: ' . $e->getMessage());
            return false;
        }
    }

    public function incrementUsage(): void
    {
        try {
            DB::exec('UPDATE author_profiles SET usage_count = usage_count + 1 WHERE id=?', [$this->id]);
            $this->data['usage_count'] = ($this->data['usage_count'] ?? 0) + 1;
        } catch (\Throwable $e) {
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->data['user_id'] ?? null,
            'profile_name' => $this->data['profile_name'] ?? '未命名',
            'avatar_url' => $this->data['avatar_url'] ?? null,
            'basic_info' => [
                'gender' => $this->data['gender'] ?? null,
                'age_range' => $this->data['age_range'] ?? null,
                'mbti' => $this->data['mbti'] ?? null,
                'constellation' => $this->data['constellation'] ?? null,
                'occupation' => $this->data['occupation'] ?? null,
            ],
            'background' => [
                'education_bg' => $this->data['education_bg'] ?? null,
                'writing_experience' => $this->data['writing_experience'] ?? null,
                'influences' => $this->data['influences'] ?? null,
            ],
            'writing_habits_prompt' => $this->data['writing_habits_prompt'] ?? null,
            'narrative_style_prompt' => $this->data['narrative_style_prompt'] ?? null,
            'sentiment_prompt' => $this->data['sentiment_prompt'] ?? null,
            'creative_identity_prompt' => $this->data['creative_identity_prompt'] ?? null,
            'analysis_status' => $this->data['analysis_status'] ?? 'pending',
            'source_work_id' => $this->data['source_work_id'] ?? null,
            'is_default' => $this->data['is_default'] ?? 0,
            'usage_count' => $this->data['usage_count'] ?? 0,
            'writing_habits' => $this->formatHabits(),
            'narrative_style' => $this->formatNarrative(),
            'sentiment' => $this->formatSentiment(),
            'creative_identity' => $this->formatCreative(),
            'style_guide' => $this->generateStyleGuide(),
            'created_at' => $this->data['created_at'] ?? null,
            'updated_at' => $this->data['updated_at'] ?? null,
        ];
    }

    private function formatHabits(): array
    {
        if (empty($this->habits)) return [];

        return [
            'vocabulary_preference' => json_decode($this->habits['vocabulary_preference'] ?? '[]', true) ?: [],
            'word_complexity' => $this->habits['word_complexity'] ?? 'moderate',
            'sentence_length_avg' => $this->habits['sentence_length_avg'] ?? 0,
            'paragraph_length_avg' => $this->habits['paragraph_length_avg'] ?? 0,
            'sentence_patterns' => json_decode($this->habits['sentence_patterns'] ?? '[]', true) ?: [],
            'use_passive' => (float)($this->habits['use_passive'] ?? 0),
            'use_dialogue' => (float)($this->habits['use_dialogue'] ?? 0),
            'rhetorical_devices' => json_decode($this->habits['rhetorical_devices'] ?? '[]', true) ?: [],
            'metaphor_frequency' => $this->habits['metaphor_frequency'] ?? 'medium',
            'uniqueness_score' => (float)($this->habits['uniqueness_score'] ?? 0),
            'confidence' => (float)($this->habits['confidence'] ?? 0),
        ];
    }

    private function formatNarrative(): array
    {
        if (empty($this->narrative)) return [];

        $povLabels = [
            'first_person' => '第一人称',
            'second_person' => '第二人称',
            'third_limited' => '第三人称限视',
            'third_omniscient' => '第三人称全知',
            'multiple' => '多视角切换',
        ];

        return [
            'narrative_pov' => $this->narrative['narrative_pov'] ?? 'third_limited',
            'narrative_pov_label' => $povLabels[$this->narrative['narrative_pov'] ?? ''] ?? '第三人称',
            'pov_switch_frequency' => $this->narrative['pov_switch_frequency'] ?? 'rare',
            'pacing_type' => $this->narrative['pacing_type'] ?? 'medium',
            'pacing_label' => ($this->narrative['pacing_type'] ?? '') === 'fast' ? '快节奏' : (($this->narrative['pacing_type'] ?? '') === 'slow' ? '慢节奏' : '中等节奏'),
            'scene_transition_style' => $this->narrative['scene_transition_style'] ?? null,
            'tension_curve' => json_decode($this->narrative['tension_curve'] ?? '[]', true) ?: [],
            'chapter_structure' => $this->narrative['chapter_structure'] ?? 'linear',
            'cliffhanger_usage' => (float)($this->narrative['cliffhanger_usage'] ?? 0),
            'interior_monologue' => (float)($this->narrative['interior_monologue'] ?? 0),
            'description_density' => $this->narrative['description_density'] ?? 'moderate',
            'confidence' => (float)($this->narrative['confidence'] ?? 0),
        ];
    }

    private function formatSentiment(): array
    {
        if (empty($this->sentiment)) return [];

        $toneLabels = [
            'optimistic' => '积极乐观',
            'pessimistic' => '消极悲观',
            'neutral' => '中立客观',
            'bittersweet' => '苦乐参半',
            'dark' => '暗黑压抑',
            'uplifting' => '振奋人心',
        ];

        return [
            'overall_tone' => $this->sentiment['overall_tone'] ?? 'neutral',
            'tone_label' => $toneLabels[$this->sentiment['overall_tone'] ?? ''] ?? '中立',
            'emotional_range' => json_decode($this->sentiment['emotional_range'] ?? '[]', true) ?: [],
            'emotion_intensity' => $this->sentiment['emotion_intensity'] ?? 'moderate',
            'depth_level' => $this->sentiment['depth_level'] ?? 'entertaining',
            'thematic_complexity' => (float)($this->sentiment['thematic_complexity'] ?? 0),
            'themes' => json_decode($this->sentiment['themes'] ?? '[]', true) ?: [],
            'aesthetic_style' => $this->sentiment['aesthetic_style'] ?? null,
            'violence_level' => $this->sentiment['violence_level'] ?? 'moderate',
            'confidence' => (float)($this->sentiment['confidence'] ?? 0),
        ];
    }

    private function formatCreative(): array
    {
        if (empty($this->creative)) return [];

        return [
            'signature_phrases' => json_decode($this->creative['signature_phrases'] ?? '[]', true) ?: [],
            'unique_techniques' => json_decode($this->creative['unique_techniques'] ?? '[]', true) ?: [],
            'trademark_elements' => json_decode($this->creative['trademark_elements'] ?? '[]', true) ?: [],
            'genre_preferences' => json_decode($this->creative['genre_preferences'] ?? '[]', true) ?: [],
            'character_archetype_favorites' => json_decode($this->creative['character_archetype_favorites'] ?? '[]', true) ?: [],
            'plot_preferences' => json_decode($this->creative['plot_preferences'] ?? '[]', true) ?: [],
            'style_tags' => json_decode($this->creative['style_tags'] ?? '[]', true) ?: [],
            'writing_voice' => $this->creative['writing_voice'] ?? null,
            'editing_style' => $this->creative['editing_style'] ?? 'moderate',
            'planning_style' => $this->creative['planning_style'] ?? 'hybrid',
            'confidence' => (float)($this->creative['confidence'] ?? 0),
        ];
    }

    public function generateStyleGuide(): string
    {
        $guide = ["【作者风格指导】"];

        $habitsPrompt = $this->data['writing_habits_prompt'] ?? '';
        $narrativePrompt = $this->data['narrative_style_prompt'] ?? '';
        $sentimentPrompt = $this->data['sentiment_prompt'] ?? '';
        $creativePrompt = $this->data['creative_identity_prompt'] ?? '';

        if ($habitsPrompt) {
            $guide[] = "\n【写作习惯】\n{$habitsPrompt}";
        }

        if ($narrativePrompt) {
            $guide[] = "\n【叙事手法】\n{$narrativePrompt}";
        }

        if ($sentimentPrompt) {
            $guide[] = "\n【思想情感】\n{$sentimentPrompt}";
        }

        if ($creativePrompt) {
            $guide[] = "\n【创作个性】\n{$creativePrompt}";
        }

        if ($habitsPrompt || $narrativePrompt || $sentimentPrompt || $creativePrompt) {
            $guide[] = "\n请在写作时参考上述风格偏好，使生成内容与作者风格保持一致。";
            return implode("\n", $guide);
        }

        $narrative = $this->formatNarrative();
        if (!empty($narrative)) {
            $guide[] = "叙事视角：{$narrative['narrative_pov_label']}";
            $guide[] = "叙事节奏：{$narrative['pacing_label']}";
            if (!empty($narrative['scene_transition_style'])) {
                $guide[] = "场景切换：{$narrative['scene_transition_style']}";
            }
        }

        $sentiment = $this->formatSentiment();
        if (!empty($sentiment)) {
            $guide[] = "情感基调：{$sentiment['tone_label']}";
            if (!empty($sentiment['themes'])) {
                $guide[] = "主题偏好：" . implode('、', $sentiment['themes']);
            }
        }

        $habits = $this->formatHabits();
        if (!empty($habits)) {
            $complexity = match ($habits['word_complexity']) {
                'simple' => '简洁明了',
                'complex' => '文笔华丽',
                default => '中等复杂度',
            };
            $guide[] = "用词风格：{$complexity}";

            if (!empty($habits['rhetorical_devices'])) {
                $guide[] = "常用修辞：" . implode('、', array_keys($habits['rhetorical_devices']));
            }
        }

        $creative = $this->formatCreative();
        if (!empty($creative)) {
            if (!empty($creative['writing_voice'])) {
                $guide[] = "写作声音：{$creative['writing_voice']}";
            }
            if (!empty($creative['signature_phrases'])) {
                $guide[] = "标志性表达：" . implode('、', array_slice($creative['signature_phrases'], 0, 5));
            }
            if (!empty($creative['style_tags'])) {
                $guide[] = "风格标签：" . implode('、', $creative['style_tags']);
            }
        }

        $guide[] = "\n（以上风格指导基于作品分析生成，可作为写作参考）";

        return implode("\n", $guide);
    }

    public function toVectorStyle(): array
    {
        $narrative = $this->formatNarrative();
        $sentiment = $this->formatSentiment();
        $habits = $this->formatHabits();

        $vecStyle = match (true) {
            ($habits['word_complexity'] ?? '') === 'simple' => 'concise',
            ($habits['word_complexity'] ?? '') === 'complex' => 'ornate',
            default => 'balanced',
        };

        $vecPacing = match ($narrative['pacing_type'] ?? '') {
            'fast' => 'fast',
            'slow' => 'slow',
            'variable' => 'alternating',
            default => 'balanced',
        };

        $vecEmotion = match ($sentiment['overall_tone'] ?? '') {
            'optimistic', 'uplifting' => 'passionate',
            'bittersweet' => 'warm',
            'dark' => 'dark',
            default => 'balanced',
        };

        $intellect = 'balanced';

        return [
            'vec_style' => $vecStyle,
            'vec_pacing' => $vecPacing,
            'vec_emotion' => $vecEmotion,
            'vec_intellect' => $intellect,
        ];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->data['analysis_status'] ?? 'pending';
    }

    public function isAnalysisComplete(): bool
    {
        return ($this->data['analysis_status'] ?? '') === 'completed';
    }
}
