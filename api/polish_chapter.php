<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 一键润色 API（流式 SSE + 质量检测反馈驱动）
 * POST JSON: { chapter_id, quality_feedback?, mode? }
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

defined('APP_LOADED') or define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';
require_once dirname(__DIR__) . '/includes/ChapterMemoryFinalizer.php';
require_once dirname(__DIR__) . '/includes/quality/Gates.php';
require_once dirname(__DIR__) . '/includes/quality/QualityRewriteContext.php';
require_once dirname(__DIR__) . '/includes/quality/ChapterGenerationIntegrity.php';
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
$mode             = ($input['mode'] ?? '') === 'quality_rewrite' ? 'quality_rewrite' : 'polish';
$isQualityRewrite = $mode === 'quality_rewrite';

// Cached pages must not silently use the retired auto-save quality rewrite.
// The current page submits a background draft via chapter_rewrite instead.
if ($isQualityRewrite) {
    $sse->msg(['error' => '质量改写已升级为候选稿采纳流程，请刷新页面后重新操作', 'code' => 'client_update_required']);
    $sse->done();
    exit;
}

// 审计 P0（2026-06-12）：章节归属校验
$userId = currentUserId();
if ($chapterId > 0) checkChapterOwnership($chapterId, $userId);

$ch = DB::fetch('SELECT * FROM chapters WHERE id=?', [$chapterId]);
if (!$ch) { $sse->msg(['error' => '章节不存在']); $sse->done(); exit; }

if (empty($ch['content'])) {
    $sse->msg(['error' => '章节内容为空，无法润色']); $sse->done(); exit;
}

$novel = DB::fetch('SELECT id, writing_style, model_id, chapter_words, target_chapters, protagonist_name, protagonist_info, world_settings, plot_settings, extra_settings FROM novels WHERE id=?', [$ch['novel_id']]);
if (!$novel) { $sse->msg(['error' => '小说不存在']); $sse->done(); exit; }

$ch['genre'] = $ch['genre'] ?? DB::fetchColumn('SELECT genre FROM novels WHERE id=?', [$ch['novel_id']]);
$ch['chapter_words'] = (int)($novel['chapter_words'] ?? 0);
$qualityBefore = null;
if ($isQualityRewrite && getSystemSetting('ws_quality_check_enabled', true, 'bool')) {
    // 一键改写必须以服务端刚刚计算的全量七关反馈为准，不能只信任前端传入的
    // 文本，防止漏掉跟纲与落盘硬质检，也避免旧页面缓存造成反馈过期。
    $qualityBefore = runChapterQualityChecks($ch, (int)$novel['id'], (string)$ch['content']);
    $qualityFeedback = buildQualityRewriteFeedback($qualityBefore['gates']);
}

$rewriteContextSection = '';
if ($isQualityRewrite) {
    try {
        $previous = DB::fetch(
            'SELECT chapter_number, content FROM chapters WHERE novel_id=? AND chapter_number < ? AND status="completed" ORDER BY chapter_number DESC LIMIT 1',
            [(int)$novel['id'], (int)$ch['chapter_number']]
        );
        $next = DB::fetch(
            'SELECT chapter_number, outline, key_points, content, status FROM chapters WHERE novel_id=? AND chapter_number > ? ORDER BY chapter_number ASC LIMIT 1',
            [(int)$novel['id'], (int)$ch['chapter_number']]
        );
        $rewriteContextSection = QualityRewriteContext::build($ch, $novel, $previous ?: null, $next ?: null);
    } catch (Throwable $e) {
        error_log('Quality rewrite context unavailable: ' . $e->getMessage());
        $sse->msg(['error' => '未能读取改写所需的章节上下文，请稍后重试']);
        $sse->done();
        exit;
    }
}

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
$polishSystem = $isQualityRewrite
    ? '你是一位资深网络小说改稿编辑。你的任务是根据完整质检报告重写问题段落并输出可直接发布的整章正文。'
    : '你是一位专业的网络小说润色编辑，擅长在保持原文风格和情节的基础上优化文字表达。';

$baseRules = <<<RULES
1. **文风统一**：保持原文的叙事视角、语气和文风，不改变作者风格
2. **描写增强**：适度增加感官描写（视觉、听觉、触觉等），让场景更立体，但不要堆砌华丽辞藻
3. **对话优化**：让对话更自然、更有个性，去除生硬感，适当添加动作/神情/语气描写
4. **节奏优化**：长段拆分，短句点缀，增强阅读节奏感
5. **去AI痕迹**：去除典型的AI写作痕迹（如"不禁""缓缓""微微"等重复用词，过度排比，空洞的感叹）
6. **保持情节**：绝对不改变任何情节、人物关系和事件走向，只优化表达方式
7. **字数控制**：润色后字数与原文相近，增减不超过10%
RULES;

if ($isQualityRewrite) {
    $baseRules = <<<RULES
1. **逐项修复**：逐条落实质检反馈，优先修复未通过项与落盘硬质检，不得仅换同义词敷衍
2. **结构优化**：可在本章内部调整段落顺序、补足因果和转折，让开篇、推进、高潮、收束清晰
3. **角色优化**：补足应出场角色的有效行动、反应或互动，保持人物身份、能力、关系与生死状态一致
4. **描写优化**：用动作、对话、感官与环境细节增强画面，删除空泛解释和重复套话
5. **爽点优化**：强化铺垫—压迫—反转/兑现—余波链条；核心事件结果以本章完整大纲为准，修正原稿偏离大纲的结果，不得改变大纲规定结果
6. **严格跟纲**：必须兑现本章大纲和关键情节点，不新增会改变后续走向的核心设定或重大事件
7. **硬质检修复**：补齐承诺字数、严重因果链或跟纲缺口；正文必须完整，不能截断
8. **文风与连续性**：保持原叙事视角、作者风格和前后章连续性，禁止元话语、分析报告与AI套话
9. **输出约束**：只输出改写后的完整正文；总字数尽量保持在原文的90%—115%
RULES;
}

$feedbackSection = '';
if (!empty($qualityFeedback)) {
    $feedbackSection = <<<FEEDBACK

【⚠️质量检测反馈——重点改进项】
以下是自动质量检测发现的问题，请在润色时**优先针对这些问题进行改进**：

{$qualityFeedback}

⚠️ 上述反馈是必须处理的改稿清单。请结合大纲与既有设定修复，不得为了过检凭空加入无关角色、设定或事件。
FEEDBACK;
}

$operationName = $isQualityRewrite ? '改写优化' : '润色优化';

$polishUser = <<<PROMPT
请对以下章节正文进行{$operationName}，要求：

{$baseRules}
{$feedbackSection}
{$rewriteContextSection}

【小说风格】{$novel['writing_style']}
【第{$ch['chapter_number']}章】{$ch['title']}

以下是原文：
---
{$ch['content']}
---

请直接输出{$operationName}后的完整正文，从"第{$ch['chapter_number']}章 {$ch['title']}"这一行开始，不要有任何前言、后记、解释、质检报告或"好的，我来处理"等废话。保留原文的章节标题行。
PROMPT;

$sse->msg([
    'status'         => $isQualityRewrite ? 'quality_rewriting' : 'polishing',
    'has_feedback'   => !empty($qualityFeedback),
    'quality_before' => $qualityBefore['total_score'] ?? null,
    'issue_count'    => $qualityFeedback === '' ? 0 : substr_count($qualityFeedback, "\n") + 1,
]);

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

        ChapterGenerationIntegrity::assertComplete($ai->lastFinishReason);
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
// 审计修复 P2-1（2026-10-18）：内联 <think> 推理内容不得落盘或计入字数下限
$fullContent = stripInlineReasoning($fullContent);
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
        'reason' => $isQualityRewrite ? 'quality_rewrite_chapter' : 'polish_chapter',
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
$acceptedContentHash = hash('sha256', (string)$fullContent);
updateNovelStats($novelId);

// 一键改写完成后立即用最终正文重跑同一套完整质检并持久化，页面无需等待
// 下一次手动检测即可看到改写后的分数和全部关卡反馈。
$qualityAfter = null;
if ($isQualityRewrite && getSystemSetting('ws_quality_check_enabled', true, 'bool')) {
    try {
        $qualityChapter = $ch;
        $qualityChapter['content'] = $fullContent;
        $qualityChapter['words'] = $words;
        $qualityAfter = runChapterQualityChecks($qualityChapter, $novelId, $fullContent);
        ChapterMutationService::mutateChapter($chapterId, $novelId, [
            'quality_score' => $qualityAfter['total_score'],
            'gate_results'  => json_encode($qualityAfter['gates'], JSON_UNESCAPED_UNICODE),
            'rewritten'     => 1,
            'rewrite_time'  => date('Y-m-d H:i:s'),
        ], [
            'backup_version' => false,
            'prevent_writing' => true,
            'expected_content_hash' => $acceptedContentHash,
            'reason' => 'quality_rewrite_metadata',
        ]);
        $sse->msg([
            'quality_result' => true,
            'before_score'   => $qualityBefore['total_score'] ?? null,
            'after_score'    => $qualityAfter['total_score'],
            'passes'         => $qualityAfter['passes'],
            'gates'          => $qualityAfter['gates'],
            'summary'        => $qualityAfter['summary'],
        ]);
    } catch (ChapterMutationConflict $e) {
        $qualityAfter = null;
        error_log('polish_chapter quality metadata skipped after chapter revision changed: ' . $e->getMessage());
        $sse->msg(['warning' => 'The rewrite was saved, but the chapter changed again before quality metadata could be stored.']);
    } catch (Throwable $e) {
        $qualityAfter = null;
        error_log('polish_chapter quality recheck failed: ' . $e->getMessage());
        $sse->msg(['warning' => '改写已保存，但自动复检失败，可点击“一键检测”重试']);
    }
}

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

$doneLabel = $isQualityRewrite ? '改写完成' : '润色完成';
$scoreLabel = $qualityAfter !== null ? "，复检 {$qualityAfter['total_score']} 分" : '';
$sse->msg(['stats' => "{$doneLabel}，共 {$words} 字{$scoreLabel}，模型：{$usedModel}"]);
$sse->done();
