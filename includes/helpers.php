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
 * 转义 SQL LIKE 通配符（%, _, \）
 * 审计修复 P3（2026-07-12）：防止用户输入的 %/_ 被当作通配符
 *
 * 用法：
 *   $sql = "... WHERE title LIKE ? ESCAPE '\\\\'";
 *   $params[] = '%' . escapeLikeWildcards($userInput) . '%';
 *
 * @param string $s 原始输入
 * @return string 转义后的字符串（%, _, \ 已转义）
 */
function escapeLikeWildcards(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * 生成本次请求的稳定追踪 ID（每个请求内多次调用返回同一值）。
 *
 * 用途（2026-05-31 审计 P0）：把"客户端看到的友好错误文案"与"服务端日志里的
 * 完整异常细节（消息/文件/行号/堆栈）"关联起来，使运维可凭追踪号定位问题，
 * 同时不向客户端泄露任何内部结构或路径。ID 本身不含敏感信息。
 *
 * 以 function_exists 守护：error_handler.php / sse_error_handler.php 可能在
 * 未加载 helpers.php 的上下文里各自定义同名函数，避免重复声明致命错误。
 */
if (!function_exists('error_trace_id')) {
    function error_trace_id(): string {
        static $rid = null;
        if ($rid === null) {
            try {
                $rid = bin2hex(random_bytes(6));
            } catch (\Throwable $e) {
                $rid = substr(md5(uniqid('', true)), 0, 12);
            }
        }
        return $rid;
    }
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
 * 统计中文字数 + 英文单词数
 */
function countWords(string $text): int {
    // 修复（2026-06-18）：与前端 countChapterWords 完全一致
    // 中文按单字计，英文按单词计，忽略标点/空格/数字
    $cn    = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text);
    $nonCn = preg_replace('/[\x{4e00}-\x{9fa5}]/u', ' ', $text);
    $en    = preg_match_all('/[A-Za-z]+(?:[\'\-][A-Za-z]+)*/', $nonCn);
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
    if ($maxWords <= 0) return '';
    if (countWords($content) <= $maxWords) return $content;

    // v1.5.3 修复：searchEnd 严格限制在 maxWords，与 Prompt 铁律一致
    // 不允许超字，在 maxWords 以内寻找最佳截断点
    $searchEnd = $maxWords;  // 严格限制，不再 * 1.05
    $searchStart = (int)($maxWords * 0.85);  // 从 85% 处开始寻找边界

    $sub = mb_substr($content, 0, $searchEnd);

    // 优先找双换行（段落边界）
    $pos = mb_strrpos($sub, "\n\n");
    if ($pos !== false && $pos >= $searchStart) {
        return mb_substr($content, 0, $pos);
    }
    // 退而求其次找单换行
    $pos = mb_strrpos($sub, "\n");
    if ($pos !== false && $pos >= $searchStart) {
        return mb_substr($content, 0, $pos);
    }
    // 找句号/叹号/问号（句末边界）
    foreach (['。', '！', '？', '」', '』', '!', '?'] as $punct) {
        $pos = mb_strrpos($sub, $punct);
        if ($pos !== false && $pos >= $searchStart) {
            return mb_substr($content, 0, $pos + 1);
        }
    }
    // 找对话结束标记
    foreach (['……', '——'] as $marker) {
        $pos = mb_strrpos($sub, $marker);
        if ($pos !== false && $pos >= $searchStart) {
            return mb_substr($content, 0, $pos + mb_strlen($marker));
        }
    }
    // 实在找不到边界，硬截（严格限制在 maxWords）
    return mb_substr($content, 0, $maxWords);
}

/**
 * 超字截断但尽量保留章末钩子段（审计优化 2026-07-20）。
 *
 * 策略：从原文尾部抽出约 12% 字数的收束/钩子片段，主体在剩余额度内截断，
 * 再拼回尾部——避免「章末必须有钩子」却被硬切掉。
 */
function truncateToWordLimitPreservingHook(string $content, int $maxWords): string
{
    if ($maxWords <= 0) return '';
    if (countWords($content) <= $maxWords) return $content;

    // 过短章节没有「钩子预算」空间，退回普通截断
    if ($maxWords < 500) {
        return truncateToWordLimit($content, $maxWords);
    }

    $hookBudget = min(480, max(180, (int)round($maxWords * 0.12)));
    [$bodySource, $tail] = extractTrailingHookParts($content, $hookBudget);
    $tailWords = countWords($tail);

    // 尾部太短或抽不出独立尾段 → 普通截断
    if ($tailWords < 40 || $bodySource === '' || $tail === '') {
        return truncateToWordLimit($content, $maxWords);
    }

    $bodyMax = max(100, $maxWords - $tailWords);
    $body = truncateToWordLimit($bodySource, $bodyMax);
    $joined = rtrim($body) . "\n\n" . ltrim($tail);

    // 安全阀：拼合后仍超则再缩主体
    if (countWords($joined) > $maxWords) {
        $body = truncateToWordLimit($bodySource, max(80, $maxWords - countWords($tail)));
        $joined = rtrim($body) . "\n\n" . ltrim($tail);
    }
    if (countWords($joined) > $maxWords) {
        return truncateToWordLimit($content, $maxWords);
    }
    return $joined;
}

/**
 * 从正文尾部抽出钩子/收束段与主体。
 * @return array{0:string,1:string} [bodySource, tail]
 */
function extractTrailingHookParts(string $content, int $hookBudget): array
{
    $content = rtrim($content);
    if ($content === '' || $hookBudget <= 0) {
        return [$content, ''];
    }

    $paras = preg_split('/\n\s*\n/u', $content) ?: [$content];
    $paras = array_values(array_filter(array_map('trim', $paras), fn($p) => $p !== ''));
    if (count($paras) < 2) {
        $len = mb_strlen($content);
        if ($len <= $hookBudget) {
            return ['', $content];
        }
        $start = max(0, $len - $hookBudget);
        $chunk = mb_substr($content, $start);
        foreach (['。', '！', '？', '」', '』'] as $punct) {
            $pos = mb_strpos($chunk, $punct);
            if ($pos !== false && $pos < (int)($hookBudget * 0.35)) {
                $start += $pos + 1;
                break;
            }
        }
        return [rtrim(mb_substr($content, 0, $start)), ltrim(mb_substr($content, $start))];
    }

    $tailParas = [];
    $tailWords = 0;
    for ($i = count($paras) - 1; $i >= 1; $i--) { // 至少留 1 段给主体
        $p = $paras[$i];
        $w = countWords($p);
        if ($tailWords > 0 && ($tailWords + $w) > $hookBudget) {
            break;
        }
        array_unshift($tailParas, $p);
        $tailWords += $w;
        if ($tailWords >= $hookBudget || count($tailParas) >= 3) {
            break;
        }
    }

    if (empty($tailParas)) {
        return [$content, ''];
    }

    $bodyParas = array_slice($paras, 0, count($paras) - count($tailParas));
    return [
        rtrim(implode("\n\n", $bodyParas)),
        ltrim(implode("\n\n", $tailParas)),
    ];
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
 * 过滤AI误把"章节坐标"写进正文造成的穿帮
 * 例："根据第129章炼骨圣火的克制记录" → "根据炼骨圣火的克制记录"
 * 只剜除指向章节号/卷册编号的引用，保留句子其余部分。
 * @param string $content 原始内容
 * @return string 过滤后的内容
 */
function stripMetaLeaks(string $content): string
{
    $num = '[一二三四五六七八九十百千万零〇\d]+';
    $content = preg_replace([
        // “根据/依据/按照/参考/据第129章(的)” → 去掉坐标，保留连接词
        "/(根据|依据|按照|参考|据)第{$num}章的?/u",
        // “第129章的XX”所有格交叉引用 → 去掉“第129章的”，保留其后内容
        // 注：要求“的”+后接中文，避免误伤“第一章战斗”这类无所有格的词；
        //     代价是会改写极少数“世界观内书籍第N章的…”的合法引用，安全网取舍可接受
        "/第{$num}章的(?=[\\x{4e00}-\\x{9fa5}])/u",
    ], ['$1', ''], $content);
    // 整段引用括注直接删除：（见第129章）/（详见第129章）
    $content = preg_replace("/[（(]\\s*(?:详见|另见|见)?第{$num}章[^）)]*[）)]/u", '', $content);
    // 清理可能产生的连续空行
    $content = preg_replace("/\n{3,}/", "\n\n", $content);
    return trim($content);
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
        '悬疑推理', '奇幻冒险', '军事战争', '游戏竞技', '同人小说', '其他',
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
function jsonResponse(bool $ok, $data = null, string $msg = '', string $errorCode = '') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = ['ok' => $ok];
    if ($ok) {
        $payload['data'] = $data;
        if ($msg !== '') $payload['msg'] = $msg;
    } else {
        $payload['error'] = $msg ?: '未知错误';
        if ($errorCode !== '') $payload['code'] = $errorCode;
        if ($data !== null) $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 提示词注入消毒：移除已知的注入模式，防止用户输入污染 AI 系统提示词。
 * 当前为单用户系统风险有限，但提供基础防护层以备多用户扩展。
 *
 * @param string $text 用户原始输入（小说标题、角色名、设定等）
 * @return string 消毒后的文本
 */
function sanitizeForPrompt(string $text): string {
    // 移除常见的越狱/注入前缀
    $patterns = [
        '/\bignore\s+(all\s+)?(previous|prior|above)\s+(instructions?|directives?|prompts?|rules?)\b/i',
        '/\byou\s+are\s+now\b.*?(?:\.|$)/i',
        '/\boverride\s+(all\s+)?(system|instructions?|prompts?)\b/i',
        '/\bdisregard\s+(all\s+)?(previous|prior|above)\b/i',
        '/\bNEW\s*SYSTEM\s*PROMPT\b/i',
        '/\bAI\s*ASSISTANT\s*:\s*/i',
        '/\bsystem\s*message\s*:\s*/i',
        '/\[SYSTEM\].*?\[\/SYSTEM\]/is',
        '/\[INST\].*?\[\/INST\]/is',
        '/<\|im_start\|>.*?<\|im_end\|>/is',
        '/<\|system\|>.*?<\|end\|>/is',
    ];

    $cleaned = preg_replace($patterns, '[已过滤]', $text);

    // 长度截断保护（单字段最长 2000 字符）
    if (mb_strlen($cleaned) > 2000) {
        $cleaned = mb_substr($cleaned, 0, 2000);
    }

    return $cleaned;
}

/**
 * 审计修复 H-4（2026-06-17）：写作 worker task_id 绑定会话。
 *
 * 旧 task_id 是 16 字符纯随机 hex，攻击者猜中/泄露后可通过 write_poll.php
 * 读取任意进行中的章节内容。改为将 task_id 与 user_id + novel_id 通过 HMAC
 * 绑定 —— 进度文件需校验会话属于该 task_id 的归属用户。
 *
 * task_id 格式: w + 8hex(nonce) + 16hex(hmac 截断)
 *   - 前 8hex 随机数保证唯一性
 *   - 后 16hex 是 HMAC-SHA256(secret, userId|nonce) 的前 8 字节
 *   - secret 取自 ADMIN_PASS（已由 doLogin 强校验为 password_hash 格式）
 *     实际只取 hash 字符串本身作为 secret 字节，保证稳定且不可远程推导
 */

/**
 * 取 HMAC secret —— 优先 ADMIN_PASS（已哈希且非空），回退到一个基于 BASE_PATH 的
 * 静态盐（首次安装时 ADMIN_PASS 可能为空字符串）。getSystemSetting('ws_task_secret', '')
 * 允许管理员显式设置更强的密钥。
 */
function _taskIdSecret(): string {
    $cfg = getSystemSetting('ws_task_secret', '');
    if ($cfg !== '' && strlen($cfg) >= 16) return $cfg;
    if (defined('ADMIN_PASS') && ADMIN_PASS !== '' && strlen(ADMIN_PASS) >= 50) return ADMIN_PASS;
    // 兜底：用 BASE_PATH + 一个常量盐做基础保护（非生产场景）
    $salt = 'ai_novel_task_secret_2026';
    return hash('sha256', (defined('BASE_PATH') ? BASE_PATH : __DIR__) . '|' . $salt);
}

function taskIdSignature(string $taskId, int $userId, int $novelId): string {
    // task_id 已含 nonce；签名 = HMAC(secret, userId|novelId|taskId)
    return hash_hmac('sha256', $userId . '|' . $novelId . '|' . $taskId, _taskIdSecret());
}

/** 生成绑定任务 ID */
function generateBoundTaskId(int $userId, int $novelId): string {
    $nonce  = bin2hex(random_bytes(4));  // 8 hex
    $tempId = 'w' . $nonce;
    $sig    = hash_hmac('sha256', $userId . '|' . $novelId . '|' . $tempId, _taskIdSecret());
    return 'w' . $nonce . substr($sig, 0, 16);  // 总长 25 字符：w + 8 + 16
}

/** 校验 task_id 是否属于给定 user（用恒定时间比较） */
function verifyTaskIdOwnership(string $taskId, int $userId, int $novelId): bool {
    // task_id 必须是 w + 24hex
    if (!preg_match('/^w[0-9a-f]{24}$/', $taskId)) return false;
    $expected = taskIdSignature($taskId, $userId, $novelId);
    // 进度文件中存的是完整 64 字符签名（用于整体签名校验）
    // 这里我们重新计算 16hex 后缀进行比对
    $noncePart = substr($taskId, 1, 8);
    $sigPart   = substr($taskId, 9, 16);
    $recalc    = hash_hmac('sha256', $userId . '|' . $novelId . '|' . 'w' . $noncePart, _taskIdSecret());
    return hash_equals(substr($recalc, 0, 16), $sigPart);
}

/**
 * 审计修复 H-3（2026-06-17）：AI 错误信息脱敏。
 *
 * AI 服务商（如 OpenAI / Anthropic / 第三方代理）的错误响应可能包含：
 *  - API key 前缀（"Bearer sk-xxx"）
 *  - 内部 endpoint 路径
 *  - 调试栈、配置 JSON
 * 直接写入 writing_logs / 返回给客户端会泄露敏感信息。
 *
 * 策略：白名单化错误分类标签，过滤掉 URL/Key 模式。
 *
 * @param string $errMsg 原始异常消息
 * @return string 脱敏后的安全消息（≤ 200 字符）
 */
function sanitizeAiErrorMessage(string $errMsg): string {
    // 1) 移除形如 sk-xxx / Bearer xxx / API key 段
    $cleaned = preg_replace('/(sk-[A-Za-z0-9_\-]{8,})/', '[API_KEY]', $errMsg);
    $cleaned = preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]{6,}/i', '$1[API_KEY]', $cleaned);
    // 2) 移除 URL 中的查询串（可能含 token）
    $cleaned = preg_replace('#https?://[^\s\'"<>]+#i', '[URL]', $cleaned);
    // 3) 移除 JSON 块（可能是 provider 返回的 error 对象）
    $cleaned = preg_replace('/\{[^{}]{20,}\}/s', '[JSON]', $cleaned);
    // 4) 截断
    if (mb_strlen($cleaned) > 200) {
        $cleaned = mb_substr($cleaned, 0, 200) . '…';
    }
    return $cleaned;
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

class SseChannel {
    private bool $isAsync;
    private ?string $progressFile;
    private ?string $taskId;
    private array $messages = [];
    private int $lastHeartbeat = 0;
    private int $heartbeatInterval = 10;

    public function __construct(?string $taskId = null, ?string $progressFile = null, int $heartbeatInterval = 10) {
        $this->taskId = $taskId;
        $this->progressFile = $progressFile;
        $this->isAsync = $taskId !== null && $progressFile !== null;
        $this->lastHeartbeat = time();
        $this->heartbeatInterval = $heartbeatInterval;
    }

    public function chunk(string $text): void {
        $this->heartbeat();
        if ($this->isAsync) {
            // 审计修复 P2-10（2026-07-12）：消除 read-modify-write 竞态
            // 旧实现先 readProgress()（LOCK_SH）再 appendProgress()（LOCK_EX），
            // 两次锁之间其他进程的写入会被本次 stale read 覆盖。
            // 改为在单个 LOCK_EX 内完成读-拼-写。
            $this->modifyProgress(function (array $progress) use ($text): array {
                $progress['content'] = ($progress['content'] ?? '') . $text;
                $progress['status']  = 'writing';
                return $progress;
            });
        } else {
            echo 'data: ' . json_encode(['chunk' => $text], JSON_UNESCAPED_UNICODE) . "\n\n";
            $this->flush();
        }
    }

    public function msg(array $payload): void {
        $this->heartbeat();
        $this->messages[] = $payload;
        if ($this->isAsync) {
            if (!empty($payload['reset_content'])) {
                $this->modifyProgress(function (array $progress): array {
                    $progress['content'] = '';
                    $progress['content_revision'] = (int)($progress['content_revision'] ?? 0) + 1;
                    return $progress;
                });
                if ($this->progressFile !== null) {
                    if (file_put_contents($this->progressFile . '.content', '', LOCK_EX) === false) {
                        throw new RuntimeException('Unable to reset failed streaming content.');
                    }
                }
            }
            // 审计修复（2026-07-19 H-低11）：messages 数组随章节正文线性增长，
            // 每个 token 都触发一次全量 JSON 重写 → O(n²) 总 IO。
            // 改为 2 秒或 30 条节流 + 仅保留最近 60 条，大幅降低进度文件写入。
            $this->_msgBatchCount = ($this->_msgBatchCount ?? 0) + 1;
            $elapsed = microtime(true) - ($this->_msgLastFlush ?? 0);
            if ($elapsed < 2 && $this->_msgBatchCount < 30) return;
            $this->_msgBatchCount = 0;
            $this->_msgLastFlush = microtime(true);
            if (count($this->messages) > 60) {
                $this->messages = array_slice($this->messages, -60);
            }
            $this->appendProgress(['messages' => $this->messages, 'status' => $payload['status'] ?? (($payload['waiting'] ?? false) ? 'waiting' : 'writing')]);
        } else {
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            $this->flush();
        }
    }

    public function done(): void {
        if ($this->isAsync) {
            $this->appendProgress(['status' => 'done', 'progress' => 100]);
        } else {
            echo "data: [DONE]\n\n";
            $this->flush();
        }
    }

    public function thinking(string $content): void {
        if ($this->isAsync) return;
        echo "event: thinking\n";
        echo 'data: ' . json_encode(['thinking' => $content], JSON_UNESCAPED_UNICODE) . "\n\n";
        $this->flush();
    }

    public function event(string $eventName, array $data): void {
        $this->heartbeat();
        echo "event: {$eventName}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        $this->flush();
    }

    public function heartbeat(): void {
        $now = time();
        if ($now - $this->lastHeartbeat < $this->heartbeatInterval) return;
        if ($this->isAsync) {
            $this->appendProgress(['status' => 'writing', 'heartbeat' => $now]);
        } else {
            echo "event: heartbeat\n";
            echo 'data: ' . json_encode(['time' => $now, 'msg' => 'keep-alive'], JSON_UNESCAPED_UNICODE) . "\n\n";
            $this->flush();
        }
        $this->lastHeartbeat = $now;
    }

    public function getTaskId(): ?string { return $this->taskId; }
    public function getProgressFile(): ?string { return $this->progressFile; }
    public function isAsync(): bool { return $this->isAsync; }

    private function flush(): void {
        if (ob_get_level()) ob_flush();
        flush();
    }

    private function readProgress(): array {
        if (!$this->progressFile || !file_exists($this->progressFile)) return [];
        $fp = fopen($this->progressFile, 'r');
        if (!$fp) return [];
        flock($fp, LOCK_SH);
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($data, true) ?: [];
    }

    private function appendProgress(array $updates): void {
        if (!$this->progressFile || !file_exists($this->progressFile)) return;
        $fp = fopen($this->progressFile, 'r+');
        if (!$fp) return;
        flock($fp, LOCK_EX);
        $data = stream_get_contents($fp);
        $progress = json_decode($data, true) ?: [];
        fseek($fp, 0);
        ftruncate($fp, 0);
        $progress = array_merge($progress, $updates, ['updated_at' => time()]);
        fwrite($fp, json_encode($progress, JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * 在单个 LOCK_EX 内执行读-改-写，消除 read-modify-write 竞态
     * 审计修复 P2-10（2026-07-12）
     *
     * @param callable $modifier 接收当前 progress 数组，返回更新后的数组
     */
    private function modifyProgress(callable $modifier): void {
        if (!$this->progressFile || !file_exists($this->progressFile)) return;
        $fp = fopen($this->progressFile, 'r+');
        if (!$fp) return;
        flock($fp, LOCK_EX);
        try {
            $data     = stream_get_contents($fp);
            $progress = json_decode($data, true) ?: [];
            $updated  = $modifier($progress);
            if (!is_array($updated)) $updated = [];
            $updated['updated_at'] = time();
            fseek($fp, 0);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($updated, JSON_UNESCAPED_UNICODE));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
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
        return array_map('normalizeOutlineObject', $decoded);
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
                if (is_array($obj)) {
                    $obj = normalizeOutlineObject($obj);
                    if (isset($obj['chapter_number'])) {
                        $objects[] = $obj;
                    }
                }
                $objStart = null;
            }
        }
    }

    // 兜底兜底（2026-06-18）：若逐对象提取仍空，尝试用正则按章节号粗提取
    // 应对 AI 输出非标准 JSON（如 Markdown 列表 + 内嵌 JSON 片段）的极端情况
    if (empty($objects)) {
        $objects = extractOutlineByRegex($raw);
    }

    return $objects;
}

/**
 * 章节大纲对象字段规范化（2026-06-18 强化兜底）
 *
 * AI 偶尔会用字段别名，导致 isset($obj['chapter_number']) 校验失败而整批丢弃。
 * 此函数将常见别名映射到标准字段名：
 *   chapter / ch / ch_num / 章节 / 章节号 → chapter_number
 *   概要 / 简介 / 大纲 → summary（若 summary 为空）
 *   要点 / 关键点 → key_points（若 key_points 为空）
 *
 * @param array $obj 解析出的原始对象
 * @return array 规范化后的对象
 */
function normalizeOutlineObject(array $obj): array {
    // 章节号别名
    if (!isset($obj['chapter_number']) || $obj['chapter_number'] === 0) {
        foreach (['chapter', 'ch', 'ch_num', 'chapter_no', '章节', '章节号'] as $alias) {
            if (isset($obj[$alias]) && (int)$obj[$alias] > 0) {
                $obj['chapter_number'] = (int)$obj[$alias];
                break;
            }
        }
    }
    // 概要别名
    if (empty($obj['summary']) && empty($obj['outline'])) {
        foreach (['概要', '简介', '大纲', 'content', 'description'] as $alias) {
            if (!empty($obj[$alias])) {
                $obj['summary'] = $obj[$alias];
                break;
            }
        }
    }
    // 标题别名
    if (empty($obj['title'])) {
        foreach (['标题', 'name', 'chapter_title'] as $alias) {
            if (!empty($obj[$alias])) {
                $obj['title'] = $obj[$alias];
                break;
            }
        }
    }
    // 钩子别名
    if (empty($obj['hook'])) {
        foreach (['钩子', 'cliffhanger', '悬念'] as $alias) {
            if (!empty($obj[$alias])) {
                $obj['hook'] = $obj[$alias];
                break;
            }
        }
    }
    return $obj;
}

/**
 * 正则粗提取章节大纲（2026-06-18 终极兜底）
 *
 * 当 JSON 解析和逐对象提取都失败时，尝试用正则从文本中提取章节信息。
 * 应对 AI 输出纯 Markdown 列表（如 "第36章 标题：xxx\n概要：xxx"）的极端情况。
 * 提取精度低，仅作为"有总比没有好"的最后手段，避免整批丢弃。
 *
 * @param string $raw 原始响应文本
 * @return array 提取出的章节对象数组
 */
function extractOutlineByRegex(string $raw): array {
    $objects = [];

    // 模式1：第X章 标题：xxx ... 概要/简介：xxx
    $pattern1 = '/第\s*(\d+)\s*章[^\n]*?\n(.*?)(?=第\s*\d+\s*章|$)/su';
    if (preg_match_all($pattern1, $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $chNum = (int)$m[1];
            $body  = trim($m[2]);
            if ($chNum <= 0 || $body === '') continue;

            $title = '';
            $summary = '';

            // 尝试从正文首行提取标题
            $lines = explode("\n", $body);
            $firstLine = trim($lines[0] ?? '');
            if (preg_match('/^(?:标题|title)\s*[:：]\s*(.+)$/iu', $firstLine, $tm)) {
                $title = trim($tm[1]);
            } elseif (!preg_match('/^(?:概要|简介|大纲|summary|outline)\s*[:：]/iu', $firstLine)) {
                // 首行不是"概要:"开头，视为标题
                $title = $firstLine;
            }

            // 提取概要
            if (preg_match('/(?:概要|简介|大纲|summary|outline)\s*[:：]\s*(.+?)(?=\n(?:要点|关键点|钩子|hook|节奏|pacing|悬念|suspense|标题|title)\s*[:：]|$)/isu', $body, $sm)) {
                $summary = trim($sm[1]);
            } elseif ($title !== '') {
                // 无明确概要标记，用标题后的正文作为概要
                $summary = trim(preg_replace('/^' . preg_quote($title, '/') . '/', '', $body));
            }

            $objects[] = [
                'chapter_number' => $chNum,
                'title'          => $title,
                'summary'        => $summary ?: $title,
                'key_points'     => [],
                'hook'           => '',
                'pacing'         => '中',
                'suspense'       => '无',
            ];
        }
    }

    return $objects;
}

/**
 * 修复 JSON 字段内的未转义引号（AI 常见输出问题）
 */
function fixJsonString(string $s): string {
    // v1.11.9a: 前置校验 — 若 JSON 已合法则直接返回，避免对已转义引号造成双重转义
    $test = json_decode($s, true);
    if (is_array($test) || is_object($test)) {
        return $s;
    }

    // v1.11.9: 对包含常见文本字段的行进行未转义双引号的鲁棒提取与自动规整
    $s = preg_replace_callback(
        '/"(chapter_number|title|summary|hook|outline)":\s*"(.*)"\s*(,?)$/mu',
        function ($m) {
            $val = $m[2];
            // 将内部未带有反斜杠转义的所有双引号全部补全转义
            $val = preg_replace('/(?<!\\\\)"/u', '\\"', $val);
            return '"' . $m[1] . '": "' . $val . '"' . $m[3];
        },
        $s
    );
    // 对钩子/节奏类型等字段也应用同理匹配，规整可能带有的空格或未转义文本
    $s = preg_replace_callback(
        '/"(hook_type|pacing|suspense|cool_point_type)":\s*"(.*)"\s*(,?)$/mu',
        function ($m) {
            $val = $m[2];
            $val = preg_replace('/(?<!\\\\)"/u', '\\"', $val);
            return '"' . $m[1] . '": "' . $val . '"' . $m[3];
        },
        $s
    );
    return $s;
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
    if (!is_array($decoded)) {
        if (mb_strlen(trim($raw)) > 50) {
            return ['synopsis' => trim($raw), 'pacing' => '中'];
        }
        return [];
    }

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

/**
 * v1.10.3: 情绪曲线异常检测
 * 检查近20章的情绪分数，识别异常模式
 *
 * @return array|null 异常信息（type/avg/variance/severity），无异常返回 null
 */
function detectEmotionCurveAnomaly(int $novelId): ?array
{
    $scores = DB::fetchAll(
        'SELECT chapter_number, emotion_score FROM chapters
         WHERE novel_id=? AND emotion_score IS NOT NULL AND status="completed"
         ORDER BY chapter_number DESC LIMIT 20',
        [$novelId]
    );
    if (count($scores) < 10) return null;

    $recent10 = array_slice(array_map(fn($s) => (float)$s['emotion_score'], $scores), 0, 10);
    $avgRecent = array_sum($recent10) / count($recent10);

    // 方差计算
    $variance = 0;
    foreach ($recent10 as $v) {
        $variance += ($v - $avgRecent) ** 2;
    }
    $variance /= count($recent10);

    // 异常1：连续10章情绪低位（均值 < 50 且最高分 < 60）
    if ($avgRecent < 50 && max($recent10) < 60) {
        return [
            'type'     => 'low_emotion_streak',
            'severity' => 'high',
            'avg'      => $avgRecent,
            'variance' => $variance,
            'max'      => max($recent10),
        ];
    }

    // 异常2：方差过低（情绪持平，读者疲劳）
    if ($variance < 100) {
        return [
            'type'     => 'flat_emotion_curve',
            'severity' => 'medium',
            'avg'      => $avgRecent,
            'variance' => $variance,
        ];
    }

    return null;
}

/**
 * v1.10.3: 读者画像配置
 * 为不同平台读者定制写作偏好
 */
const READER_PROFILES = [
    'qidian_male' => [
        'label'                    => '起点男频',
        'cool_point_density'       => 'high',
        'cool_point_types_priority'=> ['underdog_win', 'face_slap', 'breakthrough'],
        'dialogue_density'         => 'medium',
        'description_density'      => 'low',
        'foreshadowing_complexity' => 'low',
        'pace_preference'          => 'fast',
        'prompt_hint'              => '节奏快、爽点密、每章必有爽感，读者追求即时满足',
    ],
    'qidian_female' => [
        'label'                    => '起点女频',
        'cool_point_density'       => 'medium',
        'cool_point_types_priority'=> ['romance_win', 'truth_reveal', 'underdog_win'],
        'dialogue_density'         => 'high',
        'description_density'      => 'medium',
        'foreshadowing_complexity' => 'medium',
        'pace_preference'          => 'medium',
        'prompt_hint'              => '偏感情线、人物深、设定细，注重情感共鸣',
    ],
    'jjwxc' => [
        'label'                    => '晋江',
        'cool_point_density'       => 'low',
        'cool_point_types_priority'=> ['romance_win', 'sacrifice', 'truth_reveal'],
        'character_inner_world'    => 'high',
        'dialogue_density'         => 'high',
        'description_density'      => 'high',
        'sensory_richness'         => 'high',
        'foreshadowing_complexity' => 'high',
        'pace_preference'          => 'slow',
        'prompt_hint'              => '注重文笔质感、人物心理描写、五感细节丰富，读者偏好沉浸式阅读',
    ],
    'fanqie' => [
        'label'                    => '番茄',
        'cool_point_density'       => 'high',
        'cool_point_types_priority'=> ['underdog_win', 'face_slap', 'revenge'],
        'dialogue_density'         => 'high',
        'description_density'      => 'low',
        'foreshadowing_complexity' => 'low',
        'pace_preference'          => 'fast',
        'prompt_hint'              => '节奏极快、章节短爽点足、语言直白，读者碎片化阅读',
    ],
    'physical_book' => [
        'label'                    => '实体出版',
        'cool_point_density'       => 'low',
        'cool_point_types_priority'=> ['truth_reveal', 'sacrifice', 'breakthrough'],
        'dialogue_density'         => 'medium',
        'description_density'      => 'high',
        'foreshadowing_complexity' => 'high',
        'pace_preference'          => 'slow',
        'prompt_hint'              => '偏文笔质感、世界观深、伏笔精密，读者注重文学性',
    ],
    'general' => [
        'label'                    => '通用',
        'cool_point_density'       => 'medium',
        'cool_point_types_priority'=> ['underdog_win', 'breakthrough', 'truth_reveal'],
        'dialogue_density'         => 'medium',
        'description_density'      => 'medium',
        'foreshadowing_complexity' => 'medium',
        'pace_preference'          => 'medium',
        'prompt_hint'              => '平衡各类要素，无特殊偏向',
    ],
];

function readerProfileOptions(): array
{
    $options = [];
    foreach (READER_PROFILES as $key => $profile) {
        $options[$key] = $profile['label'];
    }
    return $options;
}

function getReaderProfile(string $targetReader): array
{
    return READER_PROFILES[$targetReader] ?? READER_PROFILES['general'];
}
