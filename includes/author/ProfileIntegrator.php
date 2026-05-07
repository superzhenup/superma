<?php
/**
 * 画像系统集成器 - 作者画像系统
 * 将作者画像数据集成到小说生成系统的各个模块
 */

require_once __DIR__ . '/AuthorProfile.php';

class ProfileIntegrator
{
    private ?AuthorProfile $profile = null;
    private array $styleGuide = [];
    private array $vectorStyle = [];

    public function __construct(?AuthorProfile $profile = null)
    {
        $this->profile = $profile;
        if ($profile) {
            $this->loadProfileData();
        }
    }

    public function setProfile(AuthorProfile $profile): self
    {
        $this->profile = $profile;
        $this->loadProfileData();
        return $this;
    }

    private function loadProfileData(): void
    {
        if (!$this->profile) return;

        $data = $this->profile->toArray();
        $this->styleGuide = $data['style_guide'] ?? '';
        $this->vectorStyle = $this->profile->toVectorStyle();
    }

    public function applyToNovel(int $novelId, int $profileId): bool
    {
        $profile = AuthorProfile::find($profileId);
        if (!$profile) return false;

        $vectorStyle = $profile->toVectorStyle();
        $styleGuide = $profile->generateStyleGuide();

        try {
            DB::update('novels', [
                'ref_author' => $vectorStyle['vec_style'] ?? null,
                'writing_style' => $styleGuide,
            ], 'id=?', [$novelId]);

            $this->profile = $profile;
            $this->loadProfileData();

            return true;
        } catch (\Throwable $e) {
            error_log('ProfileIntegrator::applyToNovel failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getStyleGuide(): string
    {
        return $this->styleGuide;
    }

    public function getStyleGuideForPrompt(): array
    {
        if (empty($this->styleGuide)) {
            return [];
        }

        return [
            'author_style' => $this->styleGuide,
            'vector_style' => $this->vectorStyle,
        ];
    }

    public function buildStyleSection(): string
    {
        if (!$this->profile) return '';

        $data = $this->profile->toArray();

        $habitsPrompt = $data['writing_habits_prompt'] ?? '';
        $narrativePrompt = $data['narrative_style_prompt'] ?? '';
        $sentimentPrompt = $data['sentiment_prompt'] ?? '';
        $creativePrompt = $data['creative_identity_prompt'] ?? '';

        if ($habitsPrompt || $narrativePrompt || $sentimentPrompt || $creativePrompt) {
            $sections = ["【作者风格偏好】"];

            if ($habitsPrompt) {
                $sections[] = "写作习惯：{$habitsPrompt}";
            }
            if ($narrativePrompt) {
                $sections[] = "叙事手法：{$narrativePrompt}";
            }
            if ($sentimentPrompt) {
                $sections[] = "思想情感：{$sentimentPrompt}";
            }
            if ($creativePrompt) {
                $sections[] = "创作个性：{$creativePrompt}";
            }

            $sections[] = "\n请在写作时参考上述风格偏好，使生成内容与目标作者风格保持一致。";
            return implode("\n", $sections);
        }

        $sections = ["【作者风格偏好】"];

        $narrative = $data['narrative_style'] ?? [];
        if (!empty($narrative)) {
            $sections[] = "叙事视角：{$narrative['narrative_pov_label']}";
            $sections[] = "叙事节奏：{$narrative['pacing_label']}";
            if (!empty($narrative['scene_transition_style'])) {
                $sections[] = "场景切换风格：{$narrative['scene_transition_style']}";
            }
        }

        $sentiment = $data['sentiment'] ?? [];
        if (!empty($sentiment)) {
            $sections[] = "情感基调：{$sentiment['tone_label']}";
            if (!empty($sentiment['themes'])) {
                $sections[] = "主题偏好：" . implode('、', $sentiment['themes']);
            }
        }

        $habits = $data['writing_habits'] ?? [];
        if (!empty($habits)) {
            $complexity = match ($habits['word_complexity']) {
                'simple' => '简洁明了',
                'complex' => '文笔华丽',
                default => '中等复杂度',
            };
            $sections[] = "用词风格：{$complexity}";

            if (!empty($habits['rhetorical_devices'])) {
                $devices = array_keys($habits['rhetorical_devices']);
                $sections[] = "常用修辞：" . implode('、', $devices);
            }
        }

        $creative = $data['creative_identity'] ?? [];
        if (!empty($creative)) {
            if (!empty($creative['writing_voice'])) {
                $sections[] = "写作声音：{$creative['writing_voice']}";
            }
            if (!empty($creative['signature_phrases'])) {
                $phrases = array_slice($creative['signature_phrases'], 0, 5);
                $sections[] = "标志性表达：" . implode('、', $phrases);
            }
            if (!empty($creative['style_tags'])) {
                $sections[] = "风格标签：" . implode('、', $creative['style_tags']);
            }
        }

        $sections[] = "\n请在写作时参考上述风格偏好，使生成内容与目标作者风格保持一致。";

        return implode("\n", $sections);
    }

    public function buildNarrativeGuidance(): string
    {
        if (!$this->profile) return '';

        $data = $this->profile->toArray();
        $narrativePrompt = $data['narrative_style_prompt'] ?? '';
        if ($narrativePrompt) {
            return "【叙事手法指导】\n{$narrativePrompt}";
        }

        $narrative = $data['narrative_style'] ?? [];
        if (empty($narrative)) return '';

        $guidance = ["【叙事手法指导】"];

        $pov = $narrative['narrative_pov'] ?? '';
        $povInstruction = match ($pov) {
            'first_person' => '使用第一人称视角（"我"），深入主角内心世界',
            'second_person' => '使用第二人称视角（"你"），增强代入感',
            'third_limited' => '使用第三人称限视，聚焦主角视角',
            'third_omniscient' => '使用第三人称全知，灵活切换视角',
            'multiple' => '使用多视角叙事，展现不同角色的故事线',
            default => '使用第三人称限视叙事',
        };
        $guidance[] = $povInstruction;

        $pacing = $narrative['pacing_type'] ?? '';
        $pacingInstruction = match ($pacing) {
            'fast' => '节奏明快，情节推进迅速，减少冗余描写',
            'slow' => '节奏舒缓，注重氛围营造和细节刻画',
            'variable' => '节奏张弛有度，紧张与平缓交替',
            default => '节奏适中',
        };
        $guidance[] = $pacingInstruction;

        if (($narrative['cliffhanger_usage'] ?? 0) > 0.3) {
            $guidance[] = '善用悬念和钩子，保持读者阅读兴趣';
        }

        if (($narrative['description_density'] ?? '') === 'detailed') {
            $guidance[] = '注重环境描写和细节刻画';
        } elseif (($narrative['description_density'] ?? '') === 'sparse') {
            $guidance[] = '描写简洁有力，避免过度铺陈';
        }

        return implode("\n", $guidance);
    }

    public function buildEmotionalGuidance(): string
    {
        if (!$this->profile) return '';

        $data = $this->profile->toArray();
        $sentimentPrompt = $data['sentiment_prompt'] ?? '';
        if ($sentimentPrompt) {
            return "【情感基调指导】\n{$sentimentPrompt}";
        }

        $sentiment = $data['sentiment'] ?? [];
        if (empty($sentiment)) return '';

        $guidance = ["【情感基调指导】"];

        $tone = $sentiment['overall_tone'] ?? '';
        $toneInstruction = match ($tone) {
            'optimistic' => '整体基调积极向上，充满正能量',
            'pessimistic' => '基调偏沉重，展现人性复杂',
            'neutral' => '基调客观中立，冷静叙述',
            'bittersweet' => '苦乐参半，喜中有悲',
            'dark' => '基调暗黑，展现人性阴暗面',
            'uplifting' => '振奋人心，结局给人希望',
            default => '基调中立客观',
        };
        $guidance[] = $toneInstruction;

        $emotionRange = $sentiment['emotional_range'] ?? [];
        if (!empty($emotionRange)) {
            $emotions = [];
            foreach ($emotionRange as $emotion => $density) {
                if ($density > 0) {
                    $label = match ($emotion) {
                        'joy' => '喜悦',
                        'anger' => '愤怒',
                        'sadness' => '悲伤',
                        'fear' => '恐惧',
                        'surprise' => '惊讶',
                        'love' => '爱情',
                        default => $emotion,
                    };
                    $emotions[] = "{$label}({$density}/万字)";
                }
            }
            if (!empty($emotions)) {
                $guidance[] = "情绪词汇密度：" . implode('，', $emotions);
            }
        }

        $depth = $sentiment['depth_level'] ?? '';
        $depthInstruction = match ($depth) {
            'philosophical' => '注重思想深度，引发读者思考',
            'thoughtful' => '有一定深度，不失娱乐性',
            'entertaining' => '以娱乐为主，轻松阅读',
            'surface' => '表层叙事，轻快流畅',
            default => '适度深度',
        };
        $guidance[] = "思想深度：{$depthInstruction}";

        return implode("\n", $guidance);
    }

    public function buildCompleteGuidance(): string
    {
        $sections = [];

        $style = $this->buildStyleSection();
        if ($style) $sections[] = $style;

        $narrative = $this->buildNarrativeGuidance();
        if ($narrative) $sections[] = $narrative;

        $emotional = $this->buildEmotionalGuidance();
        if ($emotional) $sections[] = $emotional;

        return implode("\n\n", $sections);
    }

    public function getVectorStyle(): array
    {
        return $this->vectorStyle;
    }

    public function hasProfile(): bool
    {
        return $this->profile !== null;
    }

    public function isAnalysisComplete(): bool
    {
        return $this->profile?->isAnalysisComplete() ?? false;
    }

    public static function getForNovel(int $novelId): ?self
    {
        $novel = DB::fetch('SELECT author_profile_id FROM novels WHERE id=?', [$novelId]);
        if (!$novel || empty($novel['author_profile_id'])) {
            return null;
        }

        $profile = AuthorProfile::find((int)$novel['author_profile_id']);
        if (!$profile) {
            return null;
        }

        return new self($profile);
    }

    public static function attachToNovel(int $novelId, int $profileId): bool
    {
        try {
            $profile = AuthorProfile::find($profileId);
            if (!$profile) return false;

            $integrator = new self($profile);
            $integrator->applyToNovel($novelId, $profileId);

            DB::update('novels', ['author_profile_id' => $profileId], 'id=?', [$novelId]);

            return true;
        } catch (\Throwable $e) {
            error_log('ProfileIntegrator::attachToNovel failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function detachFromNovel(int $novelId): bool
    {
        try {
            DB::update('novels', ['author_profile_id' => null], 'id=?', [$novelId]);
            return true;
        } catch (\Throwable $e) {
            error_log('ProfileIntegrator::detachFromNovel failed: ' . $e->getMessage());
            return false;
        }
    }
}
