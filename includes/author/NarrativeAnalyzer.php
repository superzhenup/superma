<?php
/**
 * 叙事手法分析器 - 作者画像系统
 * 分析叙事视角、节奏特征、篇章结构等叙事手法
 */

class NarrativeAnalyzer
{
    private array $chapterTexts;
    private array $stats = [];

    public function __construct(array $chapterTexts)
    {
        $this->chapterTexts = $chapterTexts;
    }

    public function analyze(): array
    {
        $this->stats = [
            'narrative_pov' => 'third_limited',
            'pov_switch_frequency' => 'rare',
            'pacing_type' => 'medium',
            'scene_transition_style' => null,
            'tension_curve' => null,
            'chapter_structure' => 'linear',
            'arc_pattern' => null,
            'cliffhanger_usage' => 0,
            'interior_monologue' => 0,
            'description_density' => 'moderate',
            'confidence' => 0,
        ];

        $this->stats['narrative_pov'] = $this->detectPOV();
        $this->stats['pov_switch_frequency'] = $this->analyzePOVSwitch();
        $this->stats['pacing_type'] = $this->analyzePacing();
        $this->stats['scene_transition_style'] = $this->analyzeSceneTransitions();
        $this->stats['tension_curve'] = $this->analyzeTensionCurve();
        $this->stats['chapter_structure'] = $this->analyzeChapterStructure();
        $this->stats['cliffhanger_usage'] = $this->analyzeCliffhangers();
        $this->stats['interior_monologue'] = $this->analyzeInteriorMonologue();
        $this->stats['description_density'] = $this->analyzeDescriptionDensity();
        $this->stats['confidence'] = $this->calculateConfidence();

        return $this->stats;
    }

    private function detectPOV(): string
    {
        $firstPersonCount = 0;
        $secondPersonCount = 0;
        $thirdPersonCount = 0;
        $thirdOmniCount = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $firstPersonCount += preg_match_all('/(?:我|我们|我的|我们的)(?![^，。！？；：]*[你您他她它])/u', $text);
            $firstPersonCount += preg_match_all('/(?:我自己|我自己)/u', $text);

            $secondPersonCount += preg_match_all('/(?:你|您|你们|你的|您的)/u', $text);

            $thirdPersonCount += preg_match_all('/(?:他|她|它|他们|她们|它们|他的|她的|它的)/u', $text);

            $thirdOmniCount += preg_match_all('/(?:然而|不过|但是|实际上|其实)/u', $text);
            $thirdOmniCount += preg_match_all('/(?:与此同时|与此同时|另一边|与此同时)/u', $text);
        }

        $total = $firstPersonCount + $secondPersonCount + $thirdPersonCount;
        if ($total === 0) return 'third_limited';

        $firstRatio = $firstPersonCount / $total;
        $secondRatio = $secondPersonCount / $total;
        $thirdRatio = $thirdPersonCount / $total;

        if ($firstRatio > 0.6) return 'first_person';
        if ($secondRatio > 0.5) return 'second_person';
        if ($thirdRatio > 0.8 && $thirdOmniCount > count($this->chapterTexts) * 2) return 'third_omniscient';
        if ($thirdRatio > 0.5) {
            $hasPOVMix = $this->hasMultiplePOVInChapter();
            return $hasPOVMix ? 'multiple' : 'third_limited';
        }

        return 'third_limited';
    }

    private function hasMultiplePOVInChapter(): bool
    {
        $multiPOVChapters = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $firstCount = preg_match_all('/(?:我|我们)/u', $text);
            $thirdCount = preg_match_all('/(?:他|她|它)/u', $text);

            if ($firstCount > 10 && $thirdCount > 50) {
                $multiPOVChapters++;
            }
        }

        return $multiPOVChapters > count($this->chapterTexts) * 0.3;
    }

    private function analyzePOVSwitch(): string
    {
        $switchCount = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $switchCount += preg_match_all('/(?:视角切换|与此同时|另一边的|镜头一转)/u', $text);
            $switchCount += preg_match_all('/(?:画面转到|镜头切到|场景切换)/u', $text);
        }

        $avgSwitches = $switchCount / max(1, count($this->chapterTexts));

        if ($avgSwitches > 2) return 'frequent';
        if ($avgSwitches > 0.5) return 'occasional';
        if ($avgSwitches > 0) return 'rare';
        return 'never';
    }

    private function analyzePacing(): string
    {
        $actionCount = 0;
        $dialogueCount = 0;
        $descriptionCount = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $actionCount += preg_match_all('/(?:打|战斗|冲|跳|跑|追|杀|攻击|防御|躲闪)/u', $text);
            $dialogueCount += preg_match_all('/["""\'].*?["""\']/u', $text);
            $descriptionCount += preg_match_all('/(?:只见|望着|看着|周围是|这里有)/u', $text);
        }

        $total = $actionCount + $dialogueCount + $descriptionCount;
        if ($total === 0) return 'medium';

        $actionRatio = $actionCount / $total;
        $dialogueRatio = $dialogueCount / $total;
        $descRatio = $descriptionCount / $total;

        if ($actionRatio > 0.4) return 'fast';
        if ($descRatio > 0.5 && $dialogueRatio < 0.2) return 'slow';

        $chapterLengths = array_map(fn($c) => mb_strlen($c['content'] ?? ''), $this->chapterTexts);
        $avgLen = array_sum($chapterLengths) / count($chapterLengths);

        if ($avgLen > 4000) return 'slow';
        if ($avgLen < 2000) return 'fast';

        return 'variable';
    }

    private function analyzeSceneTransitions(): string
    {
        $transitionStyles = [
            'blank_line' => 0,
            'ellipsis' => 0,
            'time_marker' => 0,
            'scene_tag' => 0,
        ];

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $transitionStyles['blank_line'] += preg_match_all('/\n\n\n+/u', $text);
            $transitionStyles['ellipsis'] += preg_match_all('/(?:……|------|----|====)/u', $text);
            $transitionStyles['time_marker'] += preg_match_all('/(?:片刻之后|不多时|转眼间|很快|第二天|数日后|数月后|一年后|时光流逝)/u', $text);
            $transitionStyles['scene_tag'] += preg_match_all('/(?:镜头|画面|视角)/u', $text);
        }

        $total = array_sum($transitionStyles);
        if ($total === 0) return 'seamless';

        $maxStyle = array_keys($transitionStyles, max($transitionStyles))[0];

        return match ($maxStyle) {
            'blank_line' => 'section_break',
            'ellipsis' => 'ellipsis_break',
            'time_marker' => 'time_jump',
            'scene_tag' => 'explicit_marker',
            default => 'mixed',
        };
    }

    private function analyzeTensionCurve(): array
    {
        $curves = [];

        foreach ($this->chapterTexts as $index => $chapter) {
            $text = $chapter['content'] ?? '';

            $tensionMarkers = [
                'peak' => preg_match_all('/(?:危机|危险|紧急|生死|一触即发|千钧一发|命悬一线)/u', $text),
                'rising' => preg_match_all('/(?:紧张|对峙|僵持|阴谋|计谋|布局)/u', $text),
                'falling' => preg_match_all('/(?:松了口气|暂时安全|危机解除|暂时平息)/u', $text),
                'low' => preg_match_all('/(?:平静|安宁|和谐|日常|轻松)/u', $text),
            ];

            $totalMarkers = array_sum($tensionMarkers);
            if ($totalMarkers === 0) {
                $curves[] = 'neutral';
                continue;
            }

            $maxTension = array_keys($tensionMarkers, max($tensionMarkers))[0];
            $curves[] = $maxTension;
        }

        $distribution = array_count_values($curves);
        return [
            'distribution' => $distribution,
            'pattern' => $this->detectTensionPattern($curves),
        ];
    }

    private function detectTensionPattern(array $curves): string
    {
        if (count($curves) < 3) return 'unknown';

        $rising = 0;
        $falling = 0;
        $flat = 0;

        for ($i = 1; $i < count($curves); $i++) {
            $prev = $curves[$i - 1];
            $curr = $curves[$i];

            $order = ['low' => 0, 'falling' => 1, 'rising' => 2, 'peak' => 3, 'neutral' => 1];

            if (($order[$curr] ?? 0) > ($order[$prev] ?? 0)) $rising++;
            elseif (($order[$curr] ?? 0) < ($order[$prev] ?? 0)) $falling++;
            else $flat++;
        }

        if ($rising > $falling * 2) return 'escalating';
        if ($falling > $rising * 2) return 'descending';
        if ($rising > $flat) return 'wave_like';

        return 'steady';
    }

    private function analyzeChapterStructure(): string
    {
        $parallelCount = 0;
        $alternatingCount = 0;
        $circularCount = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';

            $parallelCount += preg_match_all('/(?:与此同时|另一边的|镜头一转|与此同时)/u', $text);
            $alternatingCount += preg_match_all('/(?:然而|但是|不过|与此同时)/u', $text);
        }

        $avgParallel = $parallelCount / max(1, count($this->chapterTexts));
        $avgAlternating = $alternatingCount / max(1, count($this->chapterTexts));

        if ($avgParallel > 1) return 'parallel';
        if ($avgAlternating > 3) return 'alternating';

        $firstChapter = $this->chapterTexts[0]['content'] ?? '';
        $lastChapter = $this->chapterTexts[count($this->chapterTexts) - 1]['content'] ?? '';
        $similarOpening = similar_text(mb_substr($firstChapter, 0, 100), mb_substr($lastChapter, 0, 100)) > 60;

        if ($similarOpening) return 'circular';

        return 'linear';
    }

    private function analyzeCliffhangers(): float
    {
        $cliffhangerCount = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $lastPara = $this->getLastParagraph($text);

            $hasCliffhanger = preg_match_all('/(?:欲知后事|悬念|请看下回|未完待续|精彩继续|敬请期待|敬请下章)/u', $lastPara);
            $hasQuestion = preg_match_all('/[？]$/u', trim($lastPara));
            $hasEllipsis = preg_match_all('/(?:……|。。。)$/u', trim($lastPara));
            $hasSuddenEnd = mb_strlen(trim($lastPara)) > 50 && !preg_match('/[。！？]$/u', trim($lastPara));

            if ($hasCliffhanger > 0 || $hasQuestion > 0 || $hasEllipsis > 0 || $hasSuddenEnd > 0) {
                $cliffhangerCount++;
            }
        }

        return round($cliffhangerCount / max(1, count($this->chapterTexts)), 2);
    }

    private function getLastParagraph(string $text): string
    {
        $paragraphs = preg_split('/\n\n+/u', trim($text));
        $paragraphs = array_filter($paragraphs, fn($p) => mb_strlen(trim($p)) > 0);
        if (empty($paragraphs)) return '';
        return end($paragraphs);
    }

    private function analyzeInteriorMonologue(): float
    {
        $monologueCount = 0;
        $totalChars = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $totalChars += mb_strlen($text);

            $monologueCount += preg_match_all('/(?:心想|暗想|想着|思忖|思索|思考|想到|思索着|内心)/u', $text);
            $monologueCount += preg_match_all('/(?:他(?:自己)?(?:的)?|她(?:自己)?(?:的)?)?(?:心想|暗想|思索|思忖)：?["""\']([^"""\']+)["""\']/u', $text);
        }

        if ($totalChars === 0) return 0;
        return round($monologueCount * 1000 / $totalChars, 4);
    }

    private function analyzeDescriptionDensity(): string
    {
        $descWordCount = 0;
        $totalWords = 0;

        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $totalWords += mb_strlen($text);

            $descWordCount += preg_match_all('/(?:只见|望着|看着|周围|远处|近处|天空|大地|宫殿|密林|深渊)/u', $text);
            $descWordCount += preg_match_all('/(?:金色的|银色的|漆黑的|碧绿的|蔚蓝的|苍白的|血红的)/u', $text);
            $descWordCount += preg_match_all('/(?:缓缓地|静静地|静静地|默默地|轻轻地)/u', $text);
        }

        if ($totalWords === 0) return 'moderate';

        $ratio = $descWordCount / $totalWords;
        if ($ratio > 0.03) return 'detailed';
        if ($ratio < 0.01) return 'sparse';
        return 'moderate';
    }

    private function calculateConfidence(): float
    {
        $chapterCount = count($this->chapterTexts);
        $chapterScore = min(1, $chapterCount / 5) * 0.6;

        $povIndicators = 0;
        foreach ($this->chapterTexts as $chapter) {
            $text = $chapter['content'] ?? '';
            $povIndicators += preg_match_all('/(?:我|他|她|你)/u', $text);
        }
        $povScore = min(1, $povIndicators / 1000) * 0.4;

        return round($chapterScore + $povScore, 2);
    }
}
