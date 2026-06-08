<?php
/**
 * AI chapter title optimization API.
 *
 * Returns a suggested title only. The caller decides whether to save it.
 */

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

requireLoginApi();
session_write_close();
set_time_limit(120); // 标题任务很短；留足 fallback 多模型重试余量，避免 PHP 中途被杀

// 安全网：即便发生 try/catch 抓不到的致命错误（执行超时 / 内存超限等），
// 也保证返回一段 JSON，避免前端拿到空响应而报 "Unexpected end of JSON input"。
$__titleResponded = false;
register_shutdown_function(function () use (&$__titleResponded) {
    if ($__titleResponded) return;
    $err = error_get_last();
    $rid = function_exists('error_trace_id') ? error_trace_id() : '';
    if ($err) {
        error_log('[' . $rid . '] optimize_chapter_title fatal: ' . $err['message'] . ' in ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? 0));
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => 'TITLE_OPTIMIZE_FATAL',
        'msg'   => '标题优化超时或服务器繁忙，请重试' . ($rid !== '' ? '（追踪号 ' . $rid . '）' : ''),
    ], JSON_UNESCAPED_UNICODE);
});

try {
    $input = read_title_optimize_input();
    $chapterId = (int)($input['chapter_id'] ?? 0);
    if (!$chapterId) {
        throw new RuntimeException('缺少章节ID');
    }

    $chapter = getChapter($chapterId);
    if (!$chapter) {
        throw new RuntimeException('章节不存在');
    }

    $novel = getNovel((int)$chapter['novel_id']);
    if (!$novel) {
        throw new RuntimeException('小说不存在');
    }

    $context = normalize_title_context($input, $chapter);
    if (
        $context['current_title'] === '' &&
        $context['outline'] === '' &&
        $context['key_points'] === '' &&
        $context['hook'] === ''
    ) {
        throw new RuntimeException('请先填写原标题或本章大纲信息');
    }

    $messages = build_title_optimize_messages($novel, $chapter, $context);

    // 关键约束：必须在上游代理(nginx/Cloudflare)超时前返回，否则浏览器拿到空响应报
    // "Unexpected end of JSON input"（此时连 PHP 的 shutdown 兜底也救不了——连接已被代理关闭）。
    // 策略：小 token 上限('title' 档) + 关闭 thinking + 逐模型尝试：
    //   - 小上限把每次生成压到几秒：普通模型快速产出标题；推理模型也快速结束（额度耗尽→空内容）；
    //   - 空内容不算成功 → 自动换下一个模型（只要有一个普通模型即可拿到标题）；
    //   - 全部为空 → 给可操作提示（多半是纯推理模型，应为标题换普通模型/关思考），而非空响应。
    $models   = getModelFallbackList(!empty($novel['model_id']) ? (int)$novel['model_id'] : null, 'structured');
    $rawTitle = '';
    $lastErr  = null;
    foreach ($models as $m) {
        try {
            $cfg = $m;
            $cfg['thinking_enabled'] = 0; // 标题无需深度思考（对参数式思考模型可进一步提速）
            $ai = new AIClient($cfg);
            $rawTitle = $ai->chat($messages, 'title'); // 'title' 档 = 小 max_tokens，几秒内返回，避免代理超时
            if (trim((string)$rawTitle) !== '') {
                break; // 拿到非空内容即成功
            }
        } catch (\Throwable $e) {
            $lastErr = $e;
            error_log('optimize_chapter_title: 模型 #' . ($m['id'] ?? '?') . ' 失败，尝试下一个 — ' . $e->getMessage());
            continue;
        }
    }

    $title = clean_title_suggestion((string)$rawTitle);

    if ($title === '') {
        if ($lastErr) throw $lastErr; // 有模型抛错（如 API Error/鉴权）→ 透出真实错误便于诊断
        throw new RuntimeException('AI 未返回标题。若当前模型是“深度思考/推理”型，请在【模型设置】中为它关闭深度思考，或为标题任务改用一个普通模型后重试。');
    }

    $__titleResponded = true;
    echo json_encode([
        'ok' => true,
        'data' => [
            'title' => $title,
        ],
        'msg' => '标题已优化，请确认后保存',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $__titleResponded = true;
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'msg' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

function read_title_optimize_input(): array
{
    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : null;
    if (is_array($input)) {
        return $input;
    }
    return $_POST ?: [];
}

function normalize_title_context(array $input, array $chapter): array
{
    $dbKeyPoints = normalize_key_points((string)($chapter['key_points'] ?? ''));
    $inputKeyPoints = normalize_key_points($input['key_points'] ?? '');
    $currentTitle = trim((string)($input['current_title'] ?? ''));
    $outline = trim((string)($input['outline'] ?? ''));
    $hook = trim((string)($input['hook'] ?? ''));

    return [
        'current_title' => $currentTitle !== '' ? $currentTitle : trim((string)($chapter['title'] ?? '')),
        'outline' => $outline !== '' ? $outline : trim((string)($chapter['outline'] ?? '')),
        'key_points' => $inputKeyPoints !== '' ? $inputKeyPoints : $dbKeyPoints,
        'hook' => $hook !== '' ? $hook : trim((string)($chapter['hook'] ?? '')),
    ];
}

function normalize_key_points(mixed $value): string
{
    if (is_array($value)) {
        $items = array_map(
            static fn($item) => trim((string)$item),
            $value
        );
        return implode("\n", array_values(array_filter($items, static fn($item) => $item !== '')));
    }

    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return normalize_key_points($decoded);
    }

    return $text;
}

function build_title_optimize_messages(array $novel, array $chapter, array $context): array
{
    $chapterNumber = (int)($chapter['chapter_number'] ?? 0);
    $genre = trim((string)($novel['genre'] ?? ''));
    $style = trim((string)($novel['writing_style'] ?? ($novel['style'] ?? '')));

    $user = <<<PROMPT
请根据本章信息，优化原来的章节标题。

【小说信息】
书名：《{$novel['title']}》
类型：{$genre}
风格：{$style}

【章节信息】
章节：第{$chapterNumber}章
原标题：{$context['current_title']}

【本章大纲概要】
{$context['outline']}

【关键情节点】
{$context['key_points']}

【结尾钩子】
{$context['hook']}

【标题要求】
1. 保留本章核心卖点，强化悬念、冲突或爽点。
2. 适合网络小说章节标题，短促、有画面感，一般 4 到 12 个中文字符。
3. 不要照抄原标题；如果原标题已经可用，也要做轻微增强。
4. 不要输出“第X章”，不要输出书名号、引号、解释、备选列表或 Markdown。
5. 只输出一个最终标题。
PROMPT;

    return [
        [
            'role' => 'system',
            'content' => '你是一位资深网络小说编辑，擅长把章节大纲提炼成更有点击欲和情绪张力的标题。',
        ],
        [
            'role' => 'user',
            'content' => $user,
        ],
    ];
}

function clean_title_suggestion(string $raw): string
{
    $text = trim($raw);
    if ($text === '') {
        return '';
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded) && isset($decoded['title'])) {
        $text = (string)$decoded['title'];
    }

    $text = preg_replace('/^```(?:json|text)?\s*/iu', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/u', '', $text) ?? $text;
    $text = trim($text);
    $lines = preg_split('/\R/u', $text) ?: [$text];
    $text = trim((string)($lines[0] ?? ''));
    $text = preg_replace('/^\s*(标题|新标题|优化标题|章节标题)\s*[:：]\s*/u', '', $text) ?? $text;
    $text = preg_replace('/^\s*第[0-9一二三四五六七八九十百千万零〇两]+章\s*/u', '', $text) ?? $text;
    $text = trim($text, " \t\n\r\0\x0B\"'“”‘’《》");

    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 32) {
        $text = mb_substr($text, 0, 32, 'UTF-8');
    }

    return trim($text);
}
