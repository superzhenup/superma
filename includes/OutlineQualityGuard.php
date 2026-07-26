<?php
/**
 * Deterministic quality checks for generated chapter outlines.
 *
 * The guard intentionally stays lightweight: it catches common repetition and
 * weak progression before the AI output is persisted, then builds a focused
 * repair prompt for only the problematic chapters.
 */
defined('APP_LOADED') or die('Direct access denied.');

class OutlineQualityGuard
{
    private float $repeatThreshold;

    public function __construct(float $repeatThreshold = 0.56)
    {
        $this->repeatThreshold = $repeatThreshold;
    }

    public function evaluate(array $batch, array $history = []): array
    {
        $issues = [];
        $normalizedBatch = $this->normalizeOutlines($batch);
        $normalizedHistory = $this->normalizeOutlines($history);
        $comparisonPool = array_merge($normalizedHistory, $normalizedBatch);

        foreach ($normalizedBatch as $idx => $item) {
            $chapterNumber = (int)($item['chapter_number'] ?? 0);
            $title = (string)($item['title'] ?? '');
            $summary = (string)($item['summary'] ?? '');
            $hook = (string)($item['hook'] ?? '');
            $keyPoints = $item['key_points'] ?? [];

            if ($chapterNumber <= 0) {
                $issues[] = $this->issue('invalid_chapter', 0, '章节号缺失，无法保存到正确位置。', 'blocking');
                continue;
            }

            if ($summary === '' && empty($keyPoints)) {
                $issues[] = $this->issue('missing_progression', $chapterNumber, '缺少概要和关键情节点，本章没有可执行推进。', 'blocking');
            } elseif ($this->looksLikeWeakProgression($item)) {
                $issues[] = $this->issue('missing_progression', $chapterNumber, '本章缺少明确的冲突结果或局面变化，容易变成空转铺垫。', 'warning');
            }

            if ($hook === '') {
                $issues[] = $this->issue('weak_hook', $chapterNumber, '结尾钩子为空，无法自然触发下一章。', 'warning');
            }

            if ($title !== '') {
                foreach ($comparisonPool as $other) {
                    if ((int)$other['chapter_number'] === $chapterNumber) {
                        continue;
                    }
                    if ($title === (string)($other['title'] ?? '')) {
                        $issues[] = $this->issue(
                            'duplicate_title',
                            $chapterNumber,
                            "标题与第{$other['chapter_number']}章重复：{$title}",
                            'blocking',
                            ['related_chapter' => (int)$other['chapter_number']]
                        );
                        break;
                    }
                }
            }

            foreach ($comparisonPool as $other) {
                $otherNumber = (int)($other['chapter_number'] ?? 0);
                if ($otherNumber <= 0 || $otherNumber === $chapterNumber) {
                    continue;
                }
                if ($otherNumber > $chapterNumber) {
                    continue;
                }
                $similarity = $this->outlineSimilarity($item, $other);
                if ($similarity >= $this->repeatThreshold) {
                    $issues[] = $this->issue(
                        'repeat',
                        $chapterNumber,
                        "与第{$otherNumber}章情节相似度过高，可能只是换皮重复。",
                        'blocking',
                        ['related_chapter' => $otherNumber, 'similarity' => round($similarity, 3)]
                    );
                    break;
                }
            }

            if ($idx > 0) {
                $previous = $normalizedBatch[$idx - 1];
                if (!$this->hookSeemsContinued((string)($previous['hook'] ?? ''), $item)) {
                    $issues[] = $this->issue(
                        'logic_break',
                        $chapterNumber,
                        "未明显承接第{$previous['chapter_number']}章结尾钩子。",
                        'warning',
                        ['related_chapter' => (int)$previous['chapter_number']]
                    );
                }
            }
        }

        return $this->dedupeIssues($issues);
    }

    /**
     * 审计修复（2026-07-19 H-中1）：原实现 `return !empty($issues)` 会让 warning 级别的
     * 问题也触发 blocking 路径（最多 2 轮 AI 返修并标记 failed）。
     * 仅 severity==='blocking' 认为有阻塞性问题，warning 只用于提示。
     */
    public function hasBlockingIssues(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'blocking') {
                return true;
            }
        }
        return false;
    }

    public function formatIssuesForPrompt(array $issues): string
    {
        if (empty($issues)) {
            return '未发现明显问题。';
        }

        $lines = [];
        foreach ($issues as $issue) {
            $chapter = (int)($issue['chapter_number'] ?? 0);
            $prefix = $chapter > 0 ? "第{$chapter}章" : '未知章节';
            $lines[] = "- {$prefix}：{$issue['message']}";
        }
        return implode("\n", $lines);
    }

    public function buildRepairMessages(array $novel, array $batch, array $issues, array $context = []): array
    {
        $issueChapterNumbers = $this->issueChapterNumbers($issues);
        $historyText = $this->formatOutlines($context['history'] ?? []);
        $batchText = $this->formatOutlines($batch);
        $issueText = $this->formatIssuesForPrompt($issues);

        $system = '你是一位资深网文大纲编辑，专门修复章节重复、逻辑断裂和剧情空转。';
        $user = <<<PROMPT
请只修复有问题的章节，未列出问题的章节保持原意和章节号不变。

【小说信息】
书名：《{$novel['title']}》
类型：{$novel['genre']}
风格：{$novel['writing_style']}

【前文参考】
{$historyText}

【本批章节】
{$batchText}

【必须修复的问题】
{$issueText}

【修复要求】
1. 只修复这些章节：{$issueChapterNumbers}。
2. 每个修复章必须有新的 story_delta、conflict、result、next_trigger、unique_role。
3. 修复后不能与前文或本批其他章节重复。
4. 前章 hook 要能触发后章开头，后章要给出明确承接。
5. 不要改变 chapter_number，不要删除章节，不要新增章节。
6. 只输出 JSON 数组，不要 markdown。

输出格式：
[{"chapter_number":整数,"title":"标题","summary":"概要","key_points":["点1","点2"],"hook":"钩子","hook_type":"九选一","pacing":"快/中/慢","suspense":"有/无","story_delta":"本章新增变化","conflict":"核心冲突","result":"本章结果","next_trigger":"下一章触发点","unique_role":"本章唯一作用","changed":true}]
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    public function mergeRepairedOutlines(array $original, array $repaired, array $issues): array
    {
        $issueNumbers = [];
        foreach ($issues as $issue) {
            $chapter = (int)($issue['chapter_number'] ?? 0);
            if ($chapter > 0) {
                $issueNumbers[$chapter] = true;
            }
        }

        $repairMap = [];
        foreach ($repaired as $item) {
            $chapter = (int)($item['chapter_number'] ?? 0);
            if ($chapter > 0 && isset($issueNumbers[$chapter])) {
                $repairMap[$chapter] = $item;
            }
        }

        $merged = [];
        foreach ($original as $item) {
            $chapter = (int)($item['chapter_number'] ?? 0);
            $merged[] = $repairMap[$chapter] ?? $item;
        }

        return $merged;
    }

    public function issueChapterNumbers(array $issues): string
    {
        $numbers = [];
        foreach ($issues as $issue) {
            $chapter = (int)($issue['chapter_number'] ?? 0);
            if ($chapter > 0) {
                $numbers[$chapter] = true;
            }
        }
        return implode('、', array_map(static fn($n) => "第{$n}章", array_keys($numbers)));
    }

    private function normalizeOutlines(array $outlines): array
    {
        $normalized = [];
        foreach ($outlines as $item) {
            if (!is_array($item)) {
                continue;
            }
            $keyPoints = $item['key_points'] ?? [];
            if (is_string($keyPoints)) {
                $decoded = json_decode($keyPoints, true);
                $keyPoints = is_array($decoded) ? $decoded : preg_split('/\R/u', $keyPoints);
            }
            if (!is_array($keyPoints)) {
                $keyPoints = [];
            }

            $normalized[] = array_merge($item, [
                'chapter_number' => (int)($item['chapter_number'] ?? $item['chapter'] ?? 0),
                'title' => trim((string)($item['title'] ?? '')),
                'summary' => trim((string)($item['summary'] ?? $item['outline'] ?? '')),
                'key_points' => array_values(array_filter(array_map(static fn($v) => trim((string)$v), $keyPoints))),
                'hook' => trim((string)($item['hook'] ?? '')),
            ]);
        }
        return $normalized;
    }

    private function outlineSimilarity(array $a, array $b): float
    {
        $textA = $this->outlineText($a);
        $textB = $this->outlineText($b);
        if ($textA === '' || $textB === '') {
            return 0.0;
        }

        $shinglesA = $this->shingles($textA);
        $shinglesB = $this->shingles($textB);
        if (empty($shinglesA) || empty($shinglesB)) {
            return 0.0;
        }

        $intersection = array_intersect_key($shinglesA, $shinglesB);
        $union = $shinglesA + $shinglesB;
        return count($intersection) / max(1, count($union));
    }

    private function outlineText(array $item): string
    {
        return implode(' ', array_filter([
            $item['title'] ?? '',
            $item['summary'] ?? '',
            implode(' ', $item['key_points'] ?? []),
            $item['story_delta'] ?? '',
            $item['conflict'] ?? '',
            $item['result'] ?? '',
        ]));
    }

    private function shingles(string $text): array
    {
        $text = preg_replace('/[^\p{Han}A-Za-z0-9]+/u', '', mb_strtolower($text, 'UTF-8')) ?? '';
        $len = mb_strlen($text, 'UTF-8');
        if ($len === 0) {
            return [];
        }

        $size = $len < 6 ? 2 : 3;
        $grams = [];
        for ($i = 0; $i <= $len - $size; $i++) {
            $gram = mb_substr($text, $i, $size, 'UTF-8');
            if ($gram !== '') {
                $grams[$gram] = true;
            }
        }
        return $grams;
    }

    private function looksLikeWeakProgression(array $item): bool
    {
        $summary = (string)($item['summary'] ?? '');
        $keyPoints = $item['key_points'] ?? [];
        $hasQualityFields = trim((string)($item['story_delta'] ?? '')) !== ''
            || trim((string)($item['result'] ?? '')) !== ''
            || trim((string)($item['conflict'] ?? '')) !== '';

        if ($hasQualityFields) {
            return false;
        }

        if (count($keyPoints) < 2 && mb_strlen($summary, 'UTF-8') < 28) {
            return true;
        }

        $progressWords = ['确认', '揭露', '击败', '突破', '失去', '获得', '决定', '暴露', '反转', '回收', '改变', '锁定', '达成'];
        foreach ($progressWords as $word) {
            if (mb_strpos($summary, $word) !== false) {
                return false;
            }
        }

        return count($keyPoints) < 2;
    }

    private function hookSeemsContinued(string $previousHook, array $current): bool
    {
        $previousHook = trim($previousHook);
        if ($previousHook === '') {
            return true;
        }

        $currentText = $this->outlineText($current);
        $hookGrams = $this->shingles($previousHook);
        $currentGrams = $this->shingles($currentText);
        if (empty($hookGrams) || empty($currentGrams)) {
            return true;
        }

        $overlap = count(array_intersect_key($hookGrams, $currentGrams));
        return $overlap >= 2;
    }

    private function formatOutlines(array $outlines): string
    {
        $outlines = $this->normalizeOutlines($outlines);
        if (empty($outlines)) {
            return '无';
        }

        $lines = [];
        foreach ($outlines as $item) {
            $keyPoints = !empty($item['key_points']) ? ' 情节点：' . implode('、', $item['key_points']) : '';
            $hook = !empty($item['hook']) ? ' 钩子：' . $item['hook'] : '';
            $lines[] = "第{$item['chapter_number']}章《{$item['title']}》：{$item['summary']}{$keyPoints}{$hook}";
        }
        return implode("\n", $lines);
    }

    private function issue(string $type, int $chapterNumber, string $message, string $severity, array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'chapter_number' => $chapterNumber,
            'message' => $message,
            'severity' => $severity,
        ], $extra);
    }

    private function dedupeIssues(array $issues): array
    {
        $seen = [];
        $result = [];
        foreach ($issues as $issue) {
            $key = ($issue['type'] ?? '') . ':' . ($issue['chapter_number'] ?? 0) . ':' . ($issue['related_chapter'] ?? 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $issue;
        }
        return $result;
    }
}

/**
 * Run deterministic outline checks, ask AI for local repairs when needed, then
 * return the best current outline batch.
 *
 * @return array{outlines: array, issues: array, attempts: int, passed: bool}
 */
function repairOutlineBatchWithGuard(
    array $novel,
    array $batch,
    array $history = [],
    ?callable $report = null,
    int $maxAttempts = 2
): array {
    $guard = new OutlineQualityGuard();
    $current = $batch;
    $issues = $guard->evaluate($current, $history);

    if (!$guard->hasBlockingIssues($issues)) {
        if ($report) {
            $report('quality_pass', [
                'msg' => '细纲质量检查通过',
                'issues' => [],
            ]);
        }
        return [
            'outlines' => $current,
            'issues' => [],
            'attempts' => 0,
            'passed' => true,
        ];
    }

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        if ($report) {
            $report('quality_issue', [
                'msg' => "检测到细纲逻辑问题，正在第 {$attempt} 次局部返修",
                'issues' => $issues,
                'summary' => $guard->formatIssuesForPrompt($issues),
            ]);
        }

        $messages = $guard->buildRepairMessages($novel, $current, $issues, ['history' => $history]);
        $raw = '';

        try {
            withModelFallback(
                !empty($novel['model_id']) ? (int)$novel['model_id'] : null,
                function ($ai) use ($messages, &$raw) {
                    $raw = $ai->chat($messages, 'structured');
                },
                null,
                'structured'
            );
        } catch (Throwable $e) {
            if ($report) {
                $report('quality_issue', [
                    'msg' => '细纲局部返修失败，已保留当前最优版本：' . $e->getMessage(),
                    'issues' => $issues,
                    'summary' => $guard->formatIssuesForPrompt($issues),
                ]);
            }
            break;
        }

        $repaired = extractOutlineObjects($raw);
        if (empty($repaired)) {
            break;
        }

        $current = $guard->mergeRepairedOutlines($current, $repaired, $issues);
        $issues = $guard->evaluate($current, $history);

        if (!$guard->hasBlockingIssues($issues)) {
            if ($report) {
                $report('quality_pass', [
                    'msg' => "细纲质量检查通过，已完成 {$attempt} 次返修",
                    'issues' => [],
                ]);
            }
            return [
                'outlines' => $current,
                'issues' => [],
                'attempts' => $attempt,
                'passed' => true,
            ];
        }
    }

    if ($report) {
        $report('quality_issue', [
            'msg' => '细纲仍存在部分逻辑风险，已保留当前最优版本',
            'issues' => $issues,
            'summary' => $guard->formatIssuesForPrompt($issues),
        ]);
    }

    return [
        'outlines' => $current,
        'issues' => $issues,
        'attempts' => $maxAttempts,
        'passed' => false,
    ];
}

/**
 * v41 结构兑现验证：核对"本应在本批触发的全书转折点"是否真的写进了细纲。
 * 顶层规划（story_outline.major_turning_points 带章号）→ 自下而上核验执行，防主线跑偏。
 * 缺失则做一次定向返修，把转折点自然融入对应章节细纲。
 *
 * @return array{outlines:array, missing:array, repaired:bool}
 */
function enforceTurningPoints(array $novel, int $startCh, int $endCh, array $outlines, ?callable $report = null): array
{
    $out = ['outlines' => $outlines, 'missing' => [], 'repaired' => false];
    if (!getSystemSetting('ws_turning_point_check', true, 'bool')) return $out;

    $so = $novel['_story_outline'] ?? null;
    if (!$so) return $out;
    $tps = json_decode($so['major_turning_points'] ?? '[]', true);
    if (!is_array($tps) || empty($tps)) return $out;

    // 本批应触发的转折点
    $required = [];
    foreach ($tps as $tp) {
        $ch = (int)($tp['chapter'] ?? 0);
        $event = trim((string)($tp['event'] ?? ''));
        if ($event !== '' && $ch >= $startCh && $ch <= $endCh) {
            $required[] = ['chapter' => $ch, 'event' => $event];
        }
    }
    if (empty($required)) return $out;

    // 本批细纲全文（用于覆盖检测）
    $batchText = '';
    foreach ($outlines as $it) {
        $batchText .= ($it['title'] ?? '') . ' ' . ($it['summary'] ?? '') . ' '
            . (is_array($it['key_points'] ?? null) ? implode(' ', $it['key_points']) : '')
            . ' ' . ($it['conflict'] ?? '') . ' ' . ($it['result'] ?? '') . "\n";
    }

    // 关键词覆盖：转折点事件抽 2-3gram，命中任一即视为已覆盖（保守，只标明显遗漏）
    $missing = [];
    foreach ($required as $tp) {
        if (!_tpCovered($tp['event'], $batchText)) $missing[] = $tp;
    }
    $out['missing'] = $missing;
    if (empty($missing)) return $out;

    if ($report) {
        $report('quality_issue', [
            'msg' => '检测到本批应触发的转折点未写入细纲，正在定向返修',
            'issues' => array_map(fn($t) => "第{$t['chapter']}章应触发：{$t['event']}", $missing),
        ]);
    }

    // 定向返修：要求把缺失转折点融入对应章节
    $count = count($outlines);
    $missList = implode("\n", array_map(fn($t) => "- 第{$t['chapter']}章必须发生：{$t['event']}", $missing));
    $batchJson = json_encode(array_values($outlines), JSON_UNESCAPED_UNICODE);
    $messages = [
        ['role' => 'system', 'content' => '你是网文大纲修订师。只输出纯 JSON 数组，无前缀后缀无 markdown。'],
        ['role' => 'user', 'content' =>
            "以下是已生成的本批章节细纲（JSON 数组，共{$count}条）：\n{$batchJson}\n\n"
            . "但下列【全书关键转折点】本应在对应章节发生，却未体现在细纲中：\n{$missList}\n\n"
            . "请修订相关章节的细纲，把这些转折点自然融入（调整对应章节的 summary / key_points / conflict / result，"
            . "保持其它章节与原有字段结构不变），输出修订后的**完整批次** JSON 数组（仍为{$count}条，chapter_number 不变）。"],
    ];

    try {
        $raw = '';
        withModelFallback(
            !empty($novel['model_id']) ? (int)$novel['model_id'] : null,
            function ($ai) use ($messages, &$raw) { $raw = $ai->chat($messages, 'structured'); },
            null,
            'structured'
        );
        $repaired = extractOutlineObjects($raw);
        if (!empty($repaired)) {
            // 审计修复（2026-07-19 H-中10）：原实现用返修条目整条覆盖原条目，
            // AI 只返回被修改字段时会丢掉其他扩展字段（如 hook、pacing）。
            // 改为按字段合并：返修版字段覆盖原条目同名字段，原条目独有字段保留。
            // 且按 chapter_number 白名单过滤，避免 AI 多返回章节被混入违反条数约定。
            $byNum = [];
            foreach ($outlines as $it) $byNum[(int)($it['chapter_number'] ?? 0)] = $it;
            $originalCount = count($byNum);
            foreach ($repaired as $it) {
                $cn = (int)($it['chapter_number'] ?? 0);
                if ($cn > 0 && isset($byNum[$cn])) {
                    $byNum[$cn] = array_merge($byNum[$cn], $it);
                }
            }
            ksort($byNum);
            // 条数约定：返修后总数应与原批次一致
            $out['outlines'] = array_values($byNum);
            $out['repaired'] = true;
            if ($report) $report('quality_pass', ['msg' => '转折点已融入细纲', 'issues' => []]);
        }
    } catch (\Throwable $e) {
        if ($report) $report('quality_issue', ['msg' => '转折点返修失败，保留原细纲：' . $e->getMessage(), 'issues' => []]);
    }

    return $out;
}

/** 转折点事件是否被批次文本覆盖（关键词重叠，保守判定） */
function _tpCovered(string $event, string $batchText): bool
{
    $stop = ['主角','他们','她们','一个','这个','那个','发生','开始','结束','出现','决定','终于','突然'];
    $clean = preg_replace('/[^\x{4e00}-\x{9fa5}]+/u', '', $event);
    $len = mb_strlen($clean);
    if ($len < 2) return true; // 无可判定实体，不误报
    $grams = [];
    for ($g = 3; $g >= 2; $g--) {
        for ($i = 0; $i + $g <= $len; $i++) {
            $t = mb_substr($clean, $i, $g);
            if (!in_array($t, $stop, true)) $grams[] = $t;
        }
    }
    $grams = array_slice(array_values(array_unique($grams)), 0, 12);
    if (empty($grams)) return true;
    foreach ($grams as $t) {
        if (mb_strpos($batchText, $t) !== false) return true;
    }
    return false;
}
