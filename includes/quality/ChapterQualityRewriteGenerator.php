<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/harness/support/HarnessRuntime.php';
require_once __DIR__ . '/Gates.php';
require_once __DIR__ . '/QualityRewriteContext.php';
require_once __DIR__ . '/ChapterGenerationIntegrity.php';
if (!function_exists('withModelFallback')) require_once dirname(__DIR__) . '/ai.php';

/** Produces a candidate only. The caller supplies the ordinary DB/settings bootstrap. */
final class ChapterQualityRewriteGenerator
{
    /**
     * @param callable(string,array):void $progress Only stages and numeric/boolean metrics.
     * @return array{content:string,words:int,quality_before:?array,quality_after:?array,model_label:?string}
     */
    public static function generate(array $chapter, array $novel, callable $progress): array
    {
        $novelId = (int)($novel['id'] ?? 0);
        if ($novelId < 1 || (int)($chapter['novel_id'] ?? 0) !== $novelId
            || (int)($chapter['id'] ?? 0) < 1 || (int)($chapter['chapter_number'] ?? 0) < 1
            || !is_string($chapter['content'] ?? null) || trim($chapter['content']) === '') {
            throw new InvalidArgumentException('章节与小说不匹配，或章节正文为空。');
        }
        if (!function_exists('getSystemSetting')) throw new LogicException('Quality rewrite requires the settings bootstrap.');

        $started = HarnessRuntime::preciseTime();
        $deadline = $started + 600.0;
        $progressFailure = null;
        $ensureHealthy = static function () use ($deadline, &$progressFailure): void {
            if ($progressFailure !== null) throw $progressFailure;
            if (HarnessRuntime::preciseTime() >= $deadline) {
                throw new RuntimeException('章节改写已超过600秒处理时限，请稍后重试。');
            }
        };
        $report = static function (string $stage, array $metrics = []) use ($progress, $started, $ensureHealthy, &$progressFailure): void {
            $ensureHealthy();
            try {
                $progress($stage, $metrics + ['elapsed_seconds' => round(max(0.0, HarnessRuntime::preciseTime() - $started), 3)]);
            } catch (Throwable $error) {
                // A failed state/lease callback must not be swallowed by model
                // fallback and start another request after ownership was lost.
                $progressFailure = $error;
                throw $error;
            }
        };

        $report('quality_check');
        $chapter['genre'] = $chapter['genre'] ?? $novel['genre']
            ?? DB::fetchColumn('SELECT genre FROM novels WHERE id=?', [$novelId]);
        $chapter['chapter_words'] = (int)($novel['chapter_words'] ?? 0);
        // 审计修复 P2（2026-09-27）：改写链的字数下限与正典链同源。正典链的
        // 生成承诺下限是 hardMinimumWords(target) = min(target, max(200, ceil
        // (target*0.30)))（WritePolicySnapshotFactory::hardMinimumWords），
        // 原实现只按"原文 70%"判定——同章的生成承诺与人工改写使用两套
        // 完全不同的字数口径。此处复刻同一公式，取正典承诺下限与改写防半截
        // 下限的较大者。target 为 0/null 时回退全局 ws_chapter_words（与
        // Gate1 的 W-9 回退同口径）。
        $targetWords = (int)($chapter['chapter_words'] ?? 0);
        if ($targetWords <= 0) {
            $targetWords = (int)getSystemSetting('ws_chapter_words', 2000, 'int');
        }
        $targetWords = max(1, $targetWords);
        $canonicalHardMin = min($targetWords, max(200, (int)ceil($targetWords * 0.30)));
        $qualityEnabled = (bool)getSystemSetting('ws_quality_check_enabled', true, 'bool');
        $qualityBefore = $qualityEnabled ? runChapterQualityChecks($chapter, $novelId, $chapter['content']) : null;
        $qualityFeedback = $qualityBefore === null ? '' : buildQualityRewriteFeedback($qualityBefore['gates']);

        $report('context_prepare');
        try {
            $previous = DB::fetch(
                'SELECT chapter_number, content FROM chapters WHERE novel_id=? AND chapter_number < ? AND status="completed" ORDER BY chapter_number DESC LIMIT 1',
                [$novelId, (int)$chapter['chapter_number']]
            );
            $next = DB::fetch(
                'SELECT chapter_number, outline, key_points, content, status FROM chapters WHERE novel_id=? AND chapter_number > ? ORDER BY chapter_number ASC LIMIT 1',
                [$novelId, (int)$chapter['chapter_number']]
            );
            $context = QualityRewriteContext::build($chapter, $novel, $previous ?: null, $next ?: null);
        } catch (Throwable $error) {
            throw new RuntimeException('未能读取改写所需的章节上下文，请稍后重试。', 0, $error);
        }
        $messages = self::messages($chapter, $novel, $qualityFeedback, $context);
        $oldWords = max(0, (int)($chapter['words'] ?? 0));
        $fullContent = '';
        $usedModel = null;
        $attempt = 0;
        $lastTouch = HarnessRuntime::preciseTime();
        $heartbeat = static function () use ($report, $ensureHealthy, &$lastTouch, &$attempt): void {
            $ensureHealthy();
            $now = HarnessRuntime::preciseTime();
            if ($now - $lastTouch >= 5.0) {
                $report('model_request', ['attempt' => $attempt]);
                $lastTouch = $now;
            }
        };
        $waiting = static function (int $elapsed) use ($heartbeat): void { $heartbeat(); };

        $preferredModelId = (int)($novel['model_id'] ?? 0);
        withModelFallback($preferredModelId > 0 ? $preferredModelId : null,
            static function ($ai) use ($messages, $oldWords, $deadline, $report, $ensureHealthy, $heartbeat, $waiting,
                &$fullContent, &$usedModel, &$attempt, &$lastTouch) {
                $ensureHealthy();
                ++$attempt;
                $report('model_request', ['attempt' => $attempt]);
                $lastTouch = HarnessRuntime::preciseTime();
                // Each model/429 retry starts from an empty candidate buffer.
                $fullContent = '';
                $usedModel = $ai->modelLabel;
                $minimumTokens = (int)(($oldWords > 100 ? $oldWords : 3000) * 1.1 * 2.1) + 400;
                $ai->setMaxTokens(max($ai->getMaxTokens(), $minimumTokens));
                $ai->setStreamTimeoutPolicy([
                    'request_timeout_seconds' => 600,
                    'idle_timeout_seconds' => max(180, defined('CFG_OUTLINE_THINKING_TIMEOUT') ? (int)CFG_OUTLINE_THINKING_TIMEOUT : 600),
                    'deadline_unix' => $deadline,
                ]);
                $ai->setCallbacks($heartbeat, $waiting);
                $ai->chatStream($messages,
                    static function (string $chunk) use (&$fullContent, $heartbeat): void {
                        $heartbeat();
                        if ($chunk !== '[DONE]') $fullContent .= $chunk;
                    },
                    'creative',
                    static function (string $thinking) use ($heartbeat): void { $heartbeat(); }
                );
                $ensureHealthy();
                ChapterGenerationIntegrity::assertComplete($ai->lastFinishReason);
                return $fullContent;
            },
            static function ($nextAi, string $message) use ($report, &$attempt): void {
                // Provider errors, model names and generated text never enter request state.
                $report('model_request', ['attempt' => $attempt, 'model_switch' => true]);
            },
            null,
            $waiting
        );
        $ensureHealthy();
        // 审计修复 P2-1（2026-10-18）：内联 <think> 推理内容不得进入改写候选或计入字数
        $fullContent = stripInlineReasoning($fullContent);
        $words = countWords($fullContent);
        // 审计修复 P2（2026-09-27）：下限取"正典承诺 hardMin（30%×target）"与
        // "改写防半截（原文 70% 与 200 的较大者）"的较大者——改写稿既不得低于
        // 同章生成承诺，也不得是原文残稿。
        $rewriteSafetyMin = min(max(1, $oldWords), max(200, (int)ceil(max(1, $oldWords) * 0.70)));
        $minimumWords = max($canonicalHardMin, $rewriteSafetyMin);
        if ($words < $minimumWords) {
            throw new IncompleteChapterGenerationException("改写结果疑似截断（{$words}字 < 安全下限{$minimumWords}字），未生成可采纳候选。");
        }

        $report('quality_recheck', ['words' => $words]);
        $qualityAfter = null;
        if ($qualityEnabled) {
            $rewritten = $chapter;
            $rewritten['content'] = $fullContent;
            $rewritten['words'] = $words;
            try {
                $qualityAfter = runChapterQualityChecks($rewritten, $novelId, $fullContent);
            } catch (Throwable $error) {
                // Candidate review remains possible; null explicitly means no recheck result.
                $report('quality_recheck', ['words' => $words, 'recheck_failed' => true]);
            }
        }
        $ensureHealthy();
        return ['content' => $fullContent, 'words' => $words, 'quality_before' => $qualityBefore,
            'quality_after' => $qualityAfter, 'model_label' => $usedModel];
    }

    private static function messages(array $chapter, array $novel, string $feedback, string $context): array
    {
        $system = '你是一位资深网络小说改稿编辑。你的任务是根据完整质检报告重写问题段落并输出可直接发布的整章正文。';
        $rules = <<<'RULES'
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
        $feedbackSection = $feedback === '' ? '' : <<<FEEDBACK

【⚠️质量检测反馈——重点改进项】
以下是自动质量检测发现的问题，请在润色时**优先针对这些问题进行改进**：

{$feedback}

⚠️ 上述反馈是必须处理的改稿清单。请结合大纲与既有设定修复，不得为了过检凭空加入无关角色、设定或事件。
FEEDBACK;
        $style = (string)($novel['writing_style'] ?? '');
        $title = (string)($chapter['title'] ?? '');
        $number = (int)$chapter['chapter_number'];
        $content = $chapter['content'];
        $user = <<<PROMPT
请对以下章节正文进行改写优化，要求：

{$rules}
{$feedbackSection}
{$context}

【小说风格】{$style}
【第{$number}章】{$title}

以下是原文：
---
{$content}
---

请直接输出改写优化后的完整正文，从"第{$number}章 {$title}"这一行开始，不要有任何前言、后记、解释、质检报告或"好的，我来处理"等废话。保留原文的章节标题行。
PROMPT;
        return [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    }
}
