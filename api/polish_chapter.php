<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 一键润色 API（流式 SSE + 质量检测反馈驱动）
 * POST JSON: { chapter_id, quality_feedback? }
 *
 * - 如传入 quality_feedback，基于质量检测的⚠️问题定向润色
 * - 如未传入，使用通用润色规则（不含定向改进）
 * - 润色前自动备份到 chapter_versions
 *
 * 前端调用流程：
 * 1. 先调 validate_consistency.php 运行质量检测
 * 2. 提取⚠️/❌问题拼成 quality_feedback 字符串
 * 3. 调用本接口进行流式润色
 */

while (ob_get_level()) ob_end_clean();
ini_set('implicit_flush', 'On');
ini_set('zlib.output_compression', 'Off');

ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';
require_once dirname(__DIR__) . '/includes/ChapterMemoryFinalizer.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
requireLoginApi();
session_write_close();

ob_end_clean();
set_time_limit(CFG_TIME_MEDIUM);

// 审计修复（2026-07-19 H-中11）：SSE 端点必须 ignore_user_abort
ignore_user_abort(true);

while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$sse = new SseChannel(null, null, 10);

// ---- 解析入参 ----
$input            = json_decode(file_get_contents('php://input'), true) ?? [];
$chapterId        = (int)($input['chapter_id'] ?? 0);
$qualityFeedback  = trim($input['quality_feedback'] ?? '');

// 审计 P0（2026-06-12）：章节归属校验
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($chapterId > 0) checkChapterOwnership($chapterId, $userId);

$ch = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
if (!$ch) { $sse->msg(['error' => '章节不存在']); $sse->done(); exit; }

if (empty($ch['content'])) {
    $sse->msg(['error' => '章节内容为空，无法润色']); $sse->done(); exit;
}

$novel = DB::fetch('SELECT id, writing_style, model_id FROM novels WHERE id=?', [$ch['novel_id']]);
if (!$novel) { $sse->msg(['error' => '小说不存在']); $sse->done(); exit; }

// ---- 备份原内容到版本历史 ----
$oldContent = $ch['content'] ?? '';
$oldWords   = (int)($ch['words'] ?? 0);
if (!empty($oldContent) && $oldWords > 100) {
    $maxVer = (int)(DB::fetch(
        'SELECT COALESCE(MAX(version), 0) AS v FROM chapter_versions WHERE chapter_id=?',
        [$chapterId]
    )['v'] ?? 0);
    $newVer = $maxVer + 1;
    DB::insert('chapter_versions', [
        'chapter_id' => $chapterId,
        'version'    => $newVer,
        'content'    => $oldContent,
        'outline'    => $ch['outline'] ?? '',
        'title'      => $ch['title']   ?? '',
        'words'      => $oldWords,
    ]);
    $sse->msg(['version_saved' => true, 'version' => $newVer, 'words' => $oldWords]);

    // 保留最近 $keep 个版本（审计修复 PERF-H1：用 version 范围删除替代 NOT IN 子查询）
    $keep = defined('CFG_VERSIONS_KEEP') ? CFG_VERSIONS_KEEP : 10;
    if ($newVer > $keep) {
        DB::execute(
            'DELETE FROM chapter_versions WHERE chapter_id=? AND version <= ?',
            [$chapterId, $newVer - $keep]
        );
    }
}

// ---- 构建润色 Prompt（含质量检测反馈） ----
$polishSystem = '你是一位专业的网络小说润色编辑，擅长在保持原文风格和情节的基础上优化文字表达。';

$baseRules = <<<RULES
1. **文风统一**：保持原文的叙事视角、语气和文风，不改变作者风格
2. **描写增强**：适度增加感官描写（视觉、听觉、触觉等），让场景更立体，但不要堆砌华丽辞藻
3. **对话优化**：让对话更自然、更有个性，去除生硬感，适当添加动作/神情/语气描写
4. **节奏优化**：长段拆分，短句点缀，增强阅读节奏感
5. **去AI痕迹**：去除典型的AI写作痕迹（如"不禁""缓缓""微微"等重复用词，过度排比，空洞的感叹）
6. **保持情节**：绝对不改变任何情节、人物关系和事件走向，只优化表达方式
7. **字数控制**：润色后字数与原文相近，增减不超过10%
RULES;

$feedbackSection = '';
if (!empty($qualityFeedback)) {
    $feedbackSection = <<<FEEDBACK

【⚠️质量检测反馈——重点改进项】
以下是自动质量检测发现的问题，请在润色时**优先针对这些问题进行改进**：

{$qualityFeedback}

⚠️ 上述反馈中标注⚠️和❌的项目是必须改进的，请在润色时着重处理。如果没有对应问题（如角色未出场），则在描写中增加相关角色的提及或互动。
FEEDBACK;
}

$polishUser = <<<PROMPT
请对以下章节正文进行润色优化，要求：

{$baseRules}
{$feedbackSection}

【小说风格】{$novel['writing_style']}
【第{$ch['chapter_number']}章】{$ch['title']}

以下是原文：
---
{$ch['content']}
---

请直接输出润色后的完整正文，从"第{$ch['chapter_number']}章 {$ch['title']}"这一行开始，不要有任何前言、后记、解释或"好的，我来润色"等废话。保留原文的章节标题行。
PROMPT;

$sse->msg(['status' => 'polishing', 'has_feedback' => !empty($qualityFeedback)]);

// ---- 流式润色 ----
$fullContent  = '';
$usedModel    = null;
$novelId      = (int)$novel['id'];

try {
    withModelFallback($novel['model_id'] ?? null, function($ai) use ($polishSystem, $polishUser, $oldWords, &$fullContent, &$usedModel, $sse) {
        // 回退模型必须从空缓冲重新开始，不能把失败模型的半截正文拼进最终结果。
        $fullContent = '';
        $usedModel = $ai->modelLabel;
        $polishMaxTokens = (int)(($oldWords > 100 ? $oldWords : 3000) * 1.1 * 2.1) + 400;
        $ai->setMaxTokens(max($ai->getMaxTokens(), $polishMaxTokens));

        $ai->chatStream(
            [
                ['role' => 'system', 'content' => $polishSystem],
                ['role' => 'user',   'content' => $polishUser],
            ],
            function(string $chunk) use (&$fullContent, $sse) {
                $fullContent .= $chunk;
                $sse->chunk($chunk);
            },
            'creative'
        );

        return $fullContent;
    }, function($nextAi, $errMsg) use ($sse) {
        $sse->msg(['model_switch' => true, 'to' => $nextAi->modelLabel, 'reason' => $errMsg]);
    });
} catch (Throwable $e) {
    error_log('polish_chapter error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $sse->msg(safe_sse_error_payload($e, '润色服务暂时不可用，请稍后重试'));
    $sse->done();
    exit;
}

// ---- 保存润色结果 ----
// 审计修复 P2-2（2026-07-01）：加 status 守卫防止覆盖 worker 正在写入的正文
$words = countWords($fullContent);
$minPolishWords = min(max(1, $oldWords), max(200, (int)ceil(max(1, $oldWords) * 0.70)));
if ($words < $minPolishWords) {
    $sse->msg(['error' => "润色结果疑似截断（{$words}字 < 安全下限{$minPolishWords}字），原文未被覆盖"]);
    $sse->done();
    exit;
}
try {
    ChapterMutationService::mutateChapter($chapterId, $novelId, [
        'content' => $fullContent,
        'words'   => $words,
    ], [
        // 本接口上方已备份并向 SSE 返回版本号，禁止服务再次重复备份。
        'backup_version' => false,
        'prevent_writing' => true,
        'expected_content_hash' => hash('sha256', (string)$oldContent),
        'reason' => 'polish_chapter',
    ]);
} catch (ChapterMutationConflict $e) {
    $sse->msg(['error' => '章节状态已变更（可能正在写作中），润色结果未保存']);
    $sse->done();
    exit;
} catch (Throwable $e) {
    error_log('polish_chapter save failed: ' . $e->getMessage());
    $sse->msg(['error' => '润色结果保存失败，请稍后重试']);
    $sse->done();
    exit;
}
updateNovelStats($novelId);

// 正文修改服务已经清除了旧记忆；这里只对数据库确认后的最终正文重建一次。
$sse->msg(['status' => 'refreshing_memory']);
try {
    $memoryResult = ChapterMemoryFinalizer::finalize($novelId, $chapterId, $fullContent);
    if (empty($memoryResult['ok'])) {
        $reason = !empty($memoryResult['historical_deferred'])
            ? '这是历史章节，累计记忆需按章节顺序重放'
            : (!empty($memoryResult['stale']) ? '正文已再次变化' : '记忆服务未完成');
        $sse->msg(['warning' => "润色已保存，但记忆刷新跳过：{$reason}"]);
    }
} catch (Throwable $e) {
    error_log('polish_chapter memory refresh failed: ' . $e->getMessage());
    $sse->msg(['warning' => '润色已保存，但记忆刷新失败；旧记忆已失效，不会污染后续章节']);
}

$sse->msg(['stats' => "润色完成，共 {$words} 字，模型：{$usedModel}"]);
$sse->done();
