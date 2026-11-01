<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/StyleGuard.php';

/**
 * StreamStyleMonitor — 流式写作 chunk 级实时风格监测
 *
 * 审计修复（2026-08-02 P2）：在 streamWrite 阶段对累积正文做节流的风格检测，
 * 提前发现 AI 痕迹累积趋势，而非等整章写完再回头告警。
 *
 * 设计要点：
 *   1. 纯 PHP 正则+统计，无 AI 调用开销（复用 StyleGuard 的模式库）
 *   2. 节流：每累积 ~400 字符扫描一次，避免每个 token 都跑正则
 *   3. 双层反馈：
 *      - 警告（warning）：密度超阈值 → 经 onMsg 实时推给用户
 *      - 中止（abort）：早期（前 1200 字）出现严重漂移 → 抛异常触发重试，
 *        重试时注入修正指令（"减少段首副词..."）
 *   4. 中止阈值保守：密度需 > 2x 正常上限才触发，且只在前 1200 字窗口内
 *      （太晚中止会浪费已生成内容，且 AI 后段可能自我修正）
 *   5. 最多触发 1 次中止重试，避免无限循环（由调用方计数）
 *
 * 不是替代 StyleGuard.detectAIPatterns()（后者在 postProcess 做完整章扫描），
 * 而是补充一个"写作中"的早期预警层。
 */
class StreamStyleMonitor
{
    /** 节流：每累积多少字符跑一次扫描 */
    private const SCAN_INTERVAL_CHARS = 400;

    /** 中止判定窗口：仅在前 N 字符内触发（晚期 AI 可能自我修正，不值得浪费已生成内容） */
    private const ABORT_WINDOW_CHARS = 1200;

    /** 中止倍率：实测密度 > 阈值 × 此倍率才中止 */
    private const ABORT_SEVERITY_MULTIPLIER = 2.0;

    /** 单次写作最多触发中止重试次数（防止无限循环） */
    public const MAX_ABORT_RETRIES = 1;

    private string $buffer = '';
    private int $scannedChars = 0;
    private array $warnings = [];          // 累积警告（去重）
    private array $warningKeys = [];       // 已推送警告的去重键
    private array $patternCounts = [];     // 各模式累计命中数
    private bool $aborted = false;
    private ?string $abortReason = null;
    /** 审计修复（2026-08-09 R11-6）：重试后仍保留上次中止原因供报告 */
    private ?string $lastAbortReason = null;
    private int $abortRetriesUsed = 0;

    /** 复用 StyleGuard 的模式定义（单一真理源） */
    private static function patterns(): array
    {
        // 直接用 StyleGuard 的 AI_PATTERNS 常量，避免阈值漂移
        $reflection = new \ReflectionClass(StyleGuard::class);
        return $reflection->getConstant('AI_PATTERNS');
    }

    /**
     * 喂入一个 token chunk。内部节流，不必每个 token 都跑正则。
     */
    public function feed(string $token): void
    {
        if ($token === '' || $token === '[DONE]') return;
        $this->buffer .= $token;

        $currentLen = mb_strlen($this->buffer);
        if ($currentLen - $this->scannedChars < self::SCAN_INTERVAL_CHARS) {
            return;
        }
        $this->scan();
    }

    /**
     * 执行一次扫描，更新 patternCounts 和 warnings。
     */
    private function scan(): void
    {
        $patterns = self::patterns();
        $charCount = mb_strlen($this->buffer);
        if ($charCount < 200) return; // 太短，密度不可靠

        foreach ($patterns as $key => $rule) {
            $count = @preg_match_all($rule['regex'], $this->buffer);
            if ($count === false) continue;
            $prev = $this->patternCounts[$key] ?? 0;
            $this->patternCounts[$key] = $count;

            // 仅在密度首次突破阈值时推送警告（去重）
            if (isset($rule['max_per_1000_chars'])) {
                $density = $count * 1000 / $charCount;
                $warnKey = $key . '_over';
                if ($density > $rule['max_per_1000_chars'] && !isset($this->warningKeys[$warnKey])) {
                    $this->warnings[] = [
                        'type'     => $key,
                        'severity' => 'warning',
                        'message'  => "{$rule['label']}：密度 " . round($density, 1) . "/千字（建议≤{$rule['max_per_1000_chars']}）",
                        'suggestion' => $rule['suggestion'],
                        'at_chars' => $charCount,
                    ];
                    $this->warningKeys[$warnKey] = true;
                }
            } elseif (isset($rule['max_per_chapter']) && $key === 'three_layer_emotion') {
                $warnKey = $key . '_over';
                if ($count > $rule['max_per_chapter'] && !isset($this->warningKeys[$warnKey])) {
                    $this->warnings[] = [
                        'type'     => $key,
                        'severity' => 'warning',
                        'message'  => "{$rule['label']}：已 {$count} 处（建议≤{$rule['max_per_chapter']} 处/章）",
                        'suggestion' => $rule['suggestion'],
                        'at_chars' => $charCount,
                    ];
                    $this->warningKeys[$warnKey] = true;
                }
            }
            // dialogue_tag_monotony 需要占比计算，放到 finalReport 做，不在流中预警
        }

        $this->scannedChars = $charCount;

        // 检查是否达到中止阈值
        if (!$this->aborted && $charCount <= self::ABORT_WINDOW_CHARS) {
            $this->checkAbortThreshold($charCount);
        }
    }

    /**
     * 检查是否达到中止阈值：早期窗口内密度严重超标。
     */
    private function checkAbortThreshold(int $charCount): void
    {
        if ($this->abortRetriesUsed >= self::MAX_ABORT_RETRIES) return;

        $patterns = self::patterns();
        foreach ($patterns as $key => $rule) {
            if (!isset($rule['max_per_1000_chars'])) continue;
            $count = $this->patternCounts[$key] ?? 0;
            if ($count <= 0) continue;
            $density = $count * 1000 / $charCount;
            $abortLimit = $rule['max_per_1000_chars'] * self::ABORT_SEVERITY_MULTIPLIER;
            if ($density > $abortLimit) {
                $this->aborted = true;
                $this->abortReason = "{$rule['label']}密度 " . round($density, 1)
                    . "/千字 远超上限 {$rule['max_per_1000_chars']}（{$charCount}字处）";
                return;
            }
        }
    }

    /**
     * 是否应中止当前流并触发重试。
     */
    public function shouldAbort(): bool
    {
        return $this->aborted;
    }

    /**
     * 中止原因（供日志/用户提示）。
     */
    public function abortReason(): ?string
    {
        return $this->abortReason;
    }

    /**
     * 标记已用掉一次中止重试（调用方在重试后调用，防止无限循环）。
     */
    public function markAbortRetryUsed(): void
    {
        if ($this->abortReason !== null) {
            $this->lastAbortReason = $this->abortReason;
        }
        $this->abortRetriesUsed++;
        // 重置状态，准备监测重试后的新流（保留 lastAbortReason）
        $this->aborted = false;
        $this->abortReason = null;
        $this->buffer = '';
        $this->scannedChars = 0;
        $this->patternCounts = [];
        // warnings 保留（累计报告用），但不重复推送
    }

    /**
     * 获取重试时应注入的修正指令（拼到 user prompt 尾部）。
     */
    public function getCorrectionDirective(): string
    {
        if (empty($this->warnings)) return '';
        $suggestions = array_unique(array_map(
            fn($w) => $w['suggestion'] ?? '',
            $this->warnings
        ));
        $suggestions = array_filter($suggestions);
        if (empty($suggestions)) return '';
        return "⚠️ 前次输出风格漂移，请特别注意：\n- " . implode("\n- ", $suggestions);
    }

    /**
     * 拉取并清空新警告（供调用方周期性推送给用户）。
     */
    public function drainWarnings(): array
    {
        $new = [];
        foreach ($this->warnings as $w) {
            if (!($w['pushed'] ?? false)) {
                $new[] = $w;
                $w['pushed'] = true;
            }
        }
        // 标记已推送
        foreach ($this->warnings as &$w) {
            $w['pushed'] = true;
        }
        unset($w);
        return $new;
    }

    /**
     * 流结束后获取完整报告（含对话标签单一性等需全章统计的项）。
     */
    public function getFinalReport(): array
    {
        // 最后再扫一次，确保 buffer 尾部被覆盖
        if (mb_strlen($this->buffer) > $this->scannedChars) {
            $this->scan();
        }

        $charCount = mb_strlen($this->buffer);
        $patterns = self::patterns();

        // 对话标签单一性（需全章统计）
        $dialogueIssues = [];
        $tagRule = $patterns['dialogue_tag_monotony'] ?? null;
        if ($tagRule && $charCount >= 200) {
            $count = @preg_match_all($tagRule['regex'], $this->buffer);
            if ($count !== false && $count > 5) {
                $tags = [];
                @preg_match_all($tagRule['regex'], $this->buffer, $tagMatches);
                foreach (($tagMatches[0] ?? []) as $tag) {
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
                $mostFrequent = $tags ? max($tags) : 0;
                if ($count > 0 && ($mostFrequent / $count) > 0.6) {
                    $domTag = $tags ? array_search($mostFrequent, $tags) : '';
                    $dialogueIssues[] = "{$tagRule['label']}：{$count}处中「{$domTag}」占{$mostFrequent}次";
                }
            }
        }

        return [
            'char_count'      => $charCount,
            'pattern_counts'  => $this->patternCounts,
            'warnings'        => $this->warnings,
            'dialogue_issues' => $dialogueIssues,
            'aborted'         => $this->abortRetriesUsed > 0,
            'abort_reason'    => $this->abortRetriesUsed > 0
                ? ($this->lastAbortReason ?? $this->abortReason)
                : null,
        ];
    }

    /**
     * 开新流前的部分重置（审计修复 2026-08-09 R3）。
     * 用于 API 错误/超时等非风格中止的重试：旧流残留 buffer 若不清空，
     * 会与新流叠加导致密度虚高、误 abort。
     * 保留 abortRetriesUsed（跨重试的中止次数上限）与 warnings（累计报告/修正指令）。
     * 风格中止路径由 markAbortRetryUsed() 清理，重复调用本方法无副作用。
     */
    public function resetForNewStream(): void
    {
        $this->buffer = '';
        $this->scannedChars = 0;
        $this->patternCounts = [];
        $this->aborted = false;
        $this->abortReason = null;
    }

    /**
     * 重置全部状态（用于完全重用同一实例）。
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->scannedChars = 0;
        $this->warnings = [];
        $this->warningKeys = [];
        $this->patternCounts = [];
        $this->aborted = false;
        $this->abortReason = null;
        $this->abortRetriesUsed = 0;
    }
}
