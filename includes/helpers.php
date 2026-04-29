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
 * 过滤AI模型误生成的段落标记
 * 移除正文中的"铺垫段""发展段""高潮段""钩子段"等结构化标注
 * @param string $content 原始内容
 * @return string 过滤后的内容
 */
function stripSegmentMarkers(string $content): string
{
    // 模式1：**(铺垫段:约XXX字，xxx)**
    // 模式2：**发展段(约XXX字)**
    // 模式3：**高潮段:约XXX字**
    // 模式4：单独的**铺垫段** / **高潮段** 等
    $patterns = [
        // 带字数描述的完整标记：**铺垫段:约600字，对话密集)**
        '/\*{1,2}\s*(铺垫段|发展段|高潮段|钩子段|收尾段)[：:]\s*约?\d+\s*字[^\)]*\)?\*{1,2}/iu',
        // 带括号的标记：**发展段(约600字)**
        '/\*{1,2}\s*(铺垫段|发展段|高潮段|钩子段|收尾段)\s*\(约?\d+\s*字[^\)]*\)\*{1,2}/iu',
        // 仅段落名称标记：**铺垫段**、**发展段** 等
        '/\*{1,2}\s*(铺垫段|发展段|高潮段|钩子段|收尾段)\s*\*{1,2}/iu',
        // 无星号的纯标记行：铺垫段:约600字
        '/^(铺垫段|发展段|高潮段|钩子段|收尾段)[：:]\s*.*$/imu',
        // 带括号的纯标记行：(发展段:约600字，对话密集)
        '/^[\*\-—\s]*[\(（]\s*(铺垫段|发展段|高潮段|钩子段|收尾段)[：:]\s*[^\)）]*[\)）][\*\-—\s]*$/imu',
        // 中文括号纯标记行：（高潮段）
        '/^[\*\-—\s]*[\(（]\s*(铺垫段|发展段|高潮段|钩子段|收尾段)\s*[\)）][\*\-—\s]*$/imu',
    ];

    $content = preg_replace($patterns, '', $content);

    // 清理可能产生的连续空行（超过2个换行压缩为2个）
    $content = preg_replace("/\n{3,}/", "\n\n", $content);

    // 去除首尾空白
    return trim($content);
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
 * 将 character_arcs（对象/数组）格式化为可读文本（用于页面展示）
 * 输入可能是 JSON 字符串或已解码数组
 */
function formatCharacterArcsForDisplay($characterArcs): string {
    $arcs = is_string($characterArcs) ? json_decode($characterArcs, true) : $characterArcs;
    if (!is_array($arcs) || empty($arcs)) return is_string($characterArcs) ? (string)$characterArcs : '';

    // 简单字符串数组：[ "line1", "line2" ]
    if (isset($arcs[0]) && is_string($arcs[0])) {
        return implode("\n", $arcs);
    }

    // 对象格式：{"主角": {"start": "...", "midpoint": "...", "end": "..."}}
    $lines = [];
    foreach ($arcs as $name => $data) {
        if (is_array($data)) {
            $parts = [];
            if (!empty($data['start']))    $parts[] = "起始：{$data['start']}";
            if (!empty($data['midpoint'])) $parts[] = "中期：{$data['midpoint']}";
            $lines[] = $name . '：' . implode(' → ', $parts);
        } else {
            $lines[] = $name . '：' . $data;
        }
    }
    return implode("\n", $lines);
}

/**
 * 从 character_arcs 对象中提取各人物的弧线终点（end 值）
 * 输入可能是 JSON 字符串或已解码数组
 */
function extractCharacterEndpoints($characterArcs): string {
    $arcs = is_string($characterArcs) ? json_decode($characterArcs, true) : $characterArcs;
    if (!is_array($arcs) || empty($arcs)) return '';

    // 简单字符串数组没有 end 概念
    if (isset($arcs[0]) && is_string($arcs[0])) return '';

    $endpoints = [];
    foreach ($arcs as $name => $data) {
        if (is_array($data) && !empty($data['end'])) {
            $endpoints[] = $name . '：' . $data['end'];
        }
    }
    return implode("\n", $endpoints);
}

/**
 * 将 character_arcs 格式化为编辑框文本（新行分隔）
 * 输入可能是 JSON 字符串或已解码数组
 */
function formatCharacterArcsForEdit($characterArcs): string {
    $arcs = is_string($characterArcs) ? json_decode($characterArcs, true) : $characterArcs;
    if (!is_array($arcs) || empty($arcs)) return is_string($characterArcs) ? (string)$characterArcs : '';

    // 简单字符串数组
    if (isset($arcs[0]) && is_string($arcs[0])) {
        return implode("\n", $arcs);
    }

    // 对象格式：转换为 "角色：起始 → 中期 → 终点" 格式
    $lines = [];
    foreach ($arcs as $name => $data) {
        if (is_array($data)) {
            $parts = [];
            if (!empty($data['start']))    $parts[] = $data['start'];
            if (!empty($data['midpoint'])) $parts[] = $data['midpoint'];
            if (!empty($data['end']))      $parts[] = $data['end'];
            $lines[] = $name . '：' . implode(' → ', $parts);
        } else {
            $lines[] = $name . '：' . $data;
        }
    }
    return implode("\n", $lines);
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
