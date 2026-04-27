<?php
defined('APP_LOADED') or die('Direct access denied.');

// ================================================================
// helpers.php — 纯工具函数（无 DB / AI 依赖）
// 包含：字符串处理、HTML 辅助、SSE 输出、JSON 解析
// ================================================================

/**
 * HTML 转义，防止 XSS
 * 兼容 null 输入（PHP 8.1+ 严格模式下 htmlspecialchars 不接受 null）
 */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 多字节安全字符串截取（兼容无 mbstring 扩展的环境）
 */
function safe_substr(string $string, int $start, ?int $length = null): string {
    if (function_exists('mb_substr')) {
        return mb_substr($string, $start, $length, 'UTF-8');
    }
    // 降级方案：使用正则匹配 UTF-8 字符
    if ($length === null) {
        $length = PHP_INT_MAX;
    }
    $pattern = '/^.{0,' . ($start + $length) . '}/us';
    preg_match($pattern, $string, $matches);
    $result = $matches[0] ?? '';
    // 截取从 $start 开始的字符
    if ($start > 0) {
        preg_match('/^.{0,' . $start . '}/us', $result, $prefix);
        $result = substr($result, strlen($prefix[0] ?? ''));
    }
    return $result;
}

/**
 * 多字节安全字符串长度（兼容无 mbstring 扩展的环境）
 */
function safe_strlen(string $string): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen($string, 'UTF-8');
    }
    // 降级方案：使用正则匹配 UTF-8 字符
    return preg_match_all('/./us', $string, $matches);
}

/**
 * 多字节安全字符串查找（兼容无 mbstring 扩展的环境）
 */
function safe_strpos(string $haystack, string $needle, int $offset = 0) {
    if (function_exists('mb_strpos')) {
        return mb_strpos($haystack, $needle, $offset, 'UTF-8');
    }
    // 降级方案：使用正则匹配
    $pattern = '/' . preg_quote($needle, '/') . '/u';
    if ($offset > 0) {
        // 先截取 offset 之后的内容
        $haystack = safe_substr($haystack, $offset);
    }
    if (preg_match($pattern, $haystack, $matches, PREG_OFFSET_CAPTURE)) {
        return $matches[0][1] + $offset;
    }
    return false;
}

/**
 * 统计中文字数 + 英文单词数
 */
function countWords(string $text): int {
    $cn = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text, $m);
    $en = str_word_count(preg_replace('/[\x{4e00}-\x{9fa5}]/u', '', $text));
    return ($cn ?: 0) + ($en ?: 0);
}

/**
 * 按字数上限截断文本至最近段落/句子边界
 * 用于 finish_reason=length 时的兜底截断，保持行文完整性
 *
 * @param string $content  原始内容
 * @param int    $maxWords 最大字数（中文字符数）
 * @return string 截断后的内容
 */
function truncateToWordLimit(string $content, int $maxWords): string
{
    if (mb_strlen($content) <= $maxWords) return $content;

    // 从 92% 处往前找最近的双换行（段落边界）
    $searchEnd = min((int)($maxWords * 1.05), mb_strlen($content));
    $searchStart = (int)($maxWords * 0.88);

    $sub = mb_substr($content, 0, $searchEnd);
    // 优先找双换行（段落）
    $pos = mb_strrpos($sub, "\n\n");
    if ($pos !== false && $pos >= $searchStart) {
        return mb_substr($content, 0, $pos);
    }
    // 退而求其次找单换行
    $pos = mb_strrpos($sub, "\n");
    if ($pos !== false && $pos >= $searchStart) {
        return mb_substr($content, 0, $pos);
    }
    // 最后找句号/叹号/问号
    foreach (['。', '！', '？', '!', '?'] as $punct) {
        $pos = mb_strrpos(mb_substr($content, 0, $maxWords + 50), $punct);
        if ($pos !== false && $pos >= $searchStart) {
            return mb_substr($content, 0, $pos + 1);
        }
    }
    // 实在找不到边界，硬截
    return mb_substr($content, 0, $maxWords);
}

/**
 * 文本相似度（0-100），用于情节重复检测
 */
function textSimilarity(string $text1, string $text2): float {
    $text1 = preg_replace('/\s+/', '', $text1);
    $text2 = preg_replace('/\s+/', '', $text2);
    if (empty($text1) || empty($text2)) return 0;
    similar_text($text1, $text2, $percent);
    return round($percent, 1);
}

/**
 * 随机生成封面色
 */
function randomColor(): string {
    $colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444'];
    return $colors[array_rand($colors)];
}

/**
 * 状态 Badge HTML
 */
function statusBadge(string $status): string {
    $map = [
        'draft'     => ['secondary', '草稿'],
        'writing'   => ['primary',   '写作中'],
        'paused'    => ['warning',   '已暂停'],
        'completed' => ['success',   '已完成'],
        'pending'   => ['secondary', '待处理'],
        'outlined'  => ['info',      '已大纲'],
        'skipped'   => ['warning',   '已跳过'],
        'failed'    => ['danger',    '失败'],
        'error'     => ['danger',    '错误'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return "<span class=\"badge bg-{$cls}\">" . h($label) . "</span>";
}

/**
 * 小说类型选项
 */
function genreOptions(): array {
    return [
        '玄幻修仙', '都市言情', '科幻末世', '历史穿越', '武侠仙侠',
        '悬疑推理', '奇幻冒险', '军事战争', '游戏竞技', '其他',
        '__custom__' => '自定义',
    ];
}

/**
 * 写作风格选项
 */
function styleOptions(): array {
    return [
        '轻松幽默', '热血爽文', '细腻深情', '黑暗沉重', '悬疑烧脑', '清新甜宠',
        '__custom__' => '自定义',
    ];
}

/**
 * 输出 JSON 响应并终止
 */
function jsonResponse(bool $ok, $data = null, string $msg = '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'data' => $data, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================
// SSE 辅助（Server-Sent Events）
// ================================================================

function sse(string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function sseDone(): void {
    echo "data: [DONE]\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ================================================================
// JSON 解析工具
// ================================================================

/**
 * 鲁棒解析大纲 JSON 数组
 * AI 输出常带 markdown 代码块或前缀文字，此函数自动清理后解析
 */
function extractOutlineObjects(string $raw): array {
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
        $raw = $m[1];
    }

    $raw   = trim($raw);
    $start = strpos($raw, '[');
    if ($start !== false) {
        $raw = substr($raw, $start);
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded) && !empty($decoded)) {
        return $decoded;
    }

    // 逐对象提取兜底（应对截断 JSON）
    $objects  = [];
    $len      = strlen($raw);
    $depth    = 0;
    $inStr    = false;
    $escape   = false;
    $objStart = null;

    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($escape)               { $escape = false; continue; }
        if ($c === '\\' && $inStr) { $escape = true;  continue; }
        if ($c === '"')            { $inStr = !$inStr; continue; }
        if ($inStr)                continue;

        if ($c === '{') {
            if ($depth === 0) $objStart = $i;
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0 && $objStart !== null) {
                $objStr = substr($raw, $objStart, $i - $objStart + 1);
                $objStr = fixJsonString($objStr);
                $obj    = json_decode($objStr, true);
                if (is_array($obj) && isset($obj['chapter_number'])) {
                    $objects[] = $obj;
                }
                $objStart = null;
            }
        }
    }

    return $objects;
}

/**
 * 修复 JSON 字段内的未转义引号（AI 常见输出问题）
 */
function fixJsonString(string $s): string {
    return preg_replace_callback(
        '/"(chapter_number|title|summary|hook|outline)":\s*"((?:[^"\\\\]|\\\\.)*)"$/mu',
        function ($m) {
            $val = str_replace('"', '\\"', $m[2]);
            $val = str_replace('\\\\"', '\\"', $val);
            return '"' . $m[1] . '": "' . $val . '"';
        },
        $s
    ) ?? $s;
}

/**
 * 解析全书故事大纲 JSON
 */
function extractStoryOutline(string $raw): array {
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
        $raw = $m[1];
    }
    $raw   = trim($raw);
    $start = strpos($raw, '{');
    if ($start !== false) $raw = substr($raw, $start);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * 解析章节简介 JSON，并规范化字段类型
 */
function extractChapterSynopsis(string $raw): array {
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
        $raw = $m[1];
    }
    $raw   = trim($raw);
    $start = strpos($raw, '{');
    if ($start !== false) $raw = substr($raw, $start);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];

    return [
        'chapter_number'  => (int)($decoded['chapter_number']  ?? 0),
        'title'           => (string)($decoded['title']         ?? ''),
        'synopsis'        => (string)($decoded['synopsis']      ?? ''),
        'scene_breakdown' => (array)($decoded['scene_breakdown'] ?? []),
        'dialogue_beats'  => (array)($decoded['dialogue_beats'] ?? []),
        'sensory_details' => (array)($decoded['sensory_details'] ?? []),
        'pacing'          => (string)($decoded['pacing']        ?? '中'),
        'cliffhanger'     => (string)($decoded['cliffhanger']   ?? ''),
        'foreshadowing'   => (array)($decoded['foreshadowing']  ?? []),
        'callbacks'       => (array)($decoded['callbacks']      ?? []),
    ];
}

/**
 * 从全书故事大纲中获取当前章节所在幕信息
 */
function getActInfo(array $storyOutline, int $chapterNumber): array {
    $actDivision = is_array($storyOutline['act_division'] ?? null)
        ? $storyOutline['act_division']
        : (json_decode($storyOutline['act_division'] ?? '{}', true) ?: []);

    if (empty($actDivision)) {
        return ['theme' => '未知', 'key_events' => '未知'];
    }

    foreach ($actDivision as $act) {
        $range = $act['chapters'] ?? '';
        if (preg_match('/(\d+)\s*-\s*(\d+)/', $range, $m)) {
            if ($chapterNumber >= (int)$m[1] && $chapterNumber <= (int)$m[2]) {
                $keyEvents = is_array($act['key_events'] ?? null)
                    ? $act['key_events']
                    : (json_decode($act['key_events'] ?? '[]', true) ?: []);
                return [
                    'theme'      => $act['theme'] ?? '未知',
                    'key_events' => implode('、', $keyEvents),
                ];
            }
        }
    }

    return ['theme' => '未知', 'key_events' => '未知'];
}
