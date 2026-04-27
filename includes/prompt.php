<?php
defined('APP_LOADED') or die('Direct access denied.');

// ================================================================
// prompt.php — Prompt 构建层（依赖 data.php / helpers.php，不含 AI 调用）
// 包含：大纲/正文/故事大纲/章节简介 的 messages 数组构建
//
// v6/B3：prompt 不再直接调 KnowledgeBase。KB 的语义召回现已融入
// MemoryEngine::semanticSearch(includeKB=true)，在 memoryCtx['semantic_hits']
// 里返回。需要使用 KB 的文件（write_chapter、knowledge 页面、api/knowledge
// 等）请自行 require includes/embedding.php。
// ================================================================

/**
 * 计算动态容差
 * 
 * @param int $targetWords 目标字数
 * @return array 包含 min, max, tolerance, early_finish 的数组
 */
function calculateDynamicTolerance(int $targetWords): array
{
    // 读取配置
    $ratio = (int)getSystemSetting('ws_dynamic_tolerance_ratio', 10, 'int') / 100;
    $minTolerance = (int)getSystemSetting('ws_min_tolerance', 100, 'int');
    $maxTolerance = (int)getSystemSetting('ws_max_tolerance', 500, 'int');
    
    // 计算动态容差
    $calculatedTolerance = (int)($targetWords * $ratio);
    $tolerance = max($minTolerance, min($maxTolerance, $calculatedTolerance));
    
    return [
        'min' => $targetWords - $tolerance,
        'max' => $targetWords + $tolerance,
        'tolerance' => $tolerance,
        'early_finish' => (int)($targetWords * 0.80), // 80% 时开始收尾
        'ratio' => $ratio
    ];
}

/**
 * 生成多级预警提示
 * 
 * @param int $targetWords 目标字数
 * @return string 格式化的预警提示文本
 */
function generateWordCountWarnings(int $targetWords): string
{
    $levels = [
        ['percent' => 70, 'label' => '提示', 'message' => '注意控制节奏，避免冗余描写'],
        ['percent' => 80, 'label' => '警告', 'message' => '开始准备收尾，禁止引入新情节'],
        ['percent' => 90, 'label' => '严重', 'message' => '立即进入钩子段，严禁新内容'],
        ['percent' => 95, 'label' => '紧急', 'message' => '立即停笔，完成当前句子即可'],
    ];
    
    $warnings = [];
    foreach ($levels as $level) {
        $words = (int)($targetWords * $level['percent'] / 100);
        $warnings[] = "   - {$level['percent']}%（约{$words}字）[{$level['label']}]：{$level['message']}";
    }
    
    return implode("\n", $warnings);
}

/**
 * 构建大纲生成 Prompt（三层记忆：弧段摘要 + 近章大纲 + 上批钩子）
 */
function buildOutlinePrompt(
    array $novel,
    int $startChapter,
    int $endChapter,
    array $recentOutlines = [],
    string $prevHook = '',
    ?array $memoryCtx = null,
    ?array $volumeContext = null  // v7 新增：卷大纲上下文
): array {
    $count = $endChapter - $startChapter + 1;

    // 截断辅助：控制 prompt 长度
    $truncate = fn(string $text, int $limit = 500): string =>
        safe_strlen($text) > $limit ? safe_substr($text, 0, $limit) . '…' : $text;

    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 500);
    $plotSettings    = $truncate($novel['plot_settings']    ?? '', 500);
    $worldSettings   = $truncate($novel['world_settings']   ?? '', 500);
    $extraSettings   = $truncate($novel['extra_settings']   ?? '', 300);

    // ========= 记忆数据来源：MemoryEngine 上下文（优先） =========
    $arcSummaries      = $memoryCtx['L2_arc_summaries'] ?? $memoryCtx['arc_summaries'] ?? [];
    $keyEvents         = $memoryCtx['key_events']            ?? [];
    $characterStates   = $memoryCtx['character_states']      ?? [];
    $pendingForeshadow = $memoryCtx['pending_foreshadowing'] ?? [];
    $storyMomentum     = $memoryCtx['story_momentum']        ?? '';

    // 弧段摘要（全局历史记忆）
    $arcSection   = '';
    if (!empty($arcSummaries)) {
        $arcLines = [];
        foreach ($arcSummaries as $arc) {
            if ((int)$arc['chapter_to'] < $startChapter) {
                $arcLines[] = "第{$arc['chapter_from']}-{$arc['chapter_to']}章：{$arc['summary']}";
            }
        }
        if (!empty($arcLines)) {
            $arcSection = "\n【故事线回顾】\n" . implode("\n", $arcLines) . "\n";
        }
    }

    // 近章局部大纲（微观衔接）
    $contextSection = '';
    if (!empty($recentOutlines)) {
        $lines = [];
        foreach (array_slice($recentOutlines, -10) as $oc) {
            $summary = safe_substr(trim($oc['outline'] ?? ''), 0, 120);
            $hookTip = !empty($oc['hook']) ? " →钩：{$oc['hook']}" : '';
            $ocNum = (int)($oc['chapter_number'] ?? $oc['chapter'] ?? 0);
            $lines[] = "第{$ocNum}章《{$oc['title']}》：{$summary}{$hookTip}";
        }
        $contextSection = "\n【前情参考】\n" . implode("\n", $lines) . "\n";
    }

    // 上批结尾钩子
    $prevHookSection = $prevHook !== ''
        ? "\n【上章钩子（第{$startChapter}章须承接）】\n{$prevHook}\n"
        : '';

    // 全书关键事件日志
    $keyEventsSection = '';
    if (!empty($keyEvents)) {
        $lines = [];
        foreach ($keyEvents as $e) {
            $lines[] = "第{$e['chapter']}章：{$e['event']}";
        }
        $keyEventsSection = "\n【已发生关键事件（禁重复）】\n" . implode("\n", $lines) . "\n";
    }

    // 人物当前状态
    $characterSection = '';
    if (!empty($characterStates)) {
        $lines = [];
        foreach ($characterStates as $name => $state) {
            if (isset($state['alive']) && !$state['alive']) continue;
            $parts = [];
            if (!empty($state['title']))  $parts[] = "职：{$state['title']}";
            if (!empty($state['status'])) $parts[] = "境：{$state['status']}";
            if (!empty($parts)) {
                $lines[] = "{$name}——" . implode('，', $parts);
            }
        }
        if (!empty($lines)) {
            $characterSection = "\n【人物状态】\n" . implode("\n", $lines) . "\n";
        }
    }

    // 待回收伏笔
    $foreshadowSection = '';
    $chNum = $startChapter;
    if (!empty($pendingForeshadow)) {
        $lines = [];
        foreach ($pendingForeshadow as $f) {
            $deadline = '';
            if (!empty($f['deadline'])) {
                $deadline = $chNum >= (int)$f['deadline'] - 5
                    ? "⚠️紧急{$f['deadline']}章前回收"
                    : "（{$f['deadline']}章前回收）";
            }
            $lines[] = "第{$f['chapter']}章埋：{$f['desc']}{$deadline}";
        }
        $foreshadowSection = "\n【待回收伏笔】\n" . implode("\n", $lines) . "\n";
    }

    // 故事势能摘要
    $momentumSection = $storyMomentum
        ? "\n【故事势能】\n{$storyMomentum}\n"
        : '';

    // 卷大纲上下文（强化：注入 volume_goals + must_resolve_foreshadowing）
    $volumeSection = '';
    $volumeForceResolve = '';  // 本卷必须回收的伏笔（强制前3章安排）
    if (!empty($volumeContext)) {
        $vol = $volumeContext;
        $volGoals = json_decode($vol['volume_goals'] ?? '[]', true) ?: [];
        $mustResolve = json_decode($vol['must_resolve_foreshadowing'] ?? '[]', true) ?: [];
        $keyEvents = json_decode($vol['key_events'] ?? '[]', true) ?: [];

        $volumeSection = "\n【当前卷】第{$vol['volume_number']}卷《{$vol['title']}》\n"
            . "主题：{$vol['theme']}  冲突：{$vol['conflict']}\n"
            . "概要：{$vol['summary']}\n"
            . "关键事件：" . implode('、', $keyEvents) . "\n"
            . "章节：{$vol['start_chapter']}-{$vol['end_chapter']}\n";

        if (!empty($volGoals)) {
            $volumeSection .= "【本卷写作目标——必须全部完成】\n"
                . implode("\n", array_map(fn($g) => "· {$g}", $volGoals)) . "\n";
        }

        if (!empty($mustResolve)) {
            $volumeForceResolve = "\n【⚠️ 本卷必须回收的逾期伏笔（强制安排在本批前3章内回收，不得推迟）】\n"
                . implode("\n", array_map(fn($f) => "· {$f}", $mustResolve)) . "\n";
        }
    }

    // 逾期伏笔强制注入（来自 ForeshadowingRepo，不依赖卷大纲）
    $overdueSection = '';
    try {
        require_once dirname(__DIR__) . '/includes/memory/ForeshadowingRepo.php';
        $fRepo = new \ForeshadowingRepo((int)$novel['id']);
        $overdueItems = $fRepo->listOverdue($startChapter, 0);  // buffer=0：严格逾期
        if (!empty($overdueItems)) {
            $overdueLines = [];
            foreach ($overdueItems as $ov) {
                $overdueLines[] = "· 第{$ov['planted_chapter']}章埋、应{$ov['deadline_chapter']}章前回收：{$ov['description']}";
            }
            $overdueSection = "\n【🚨 严重逾期伏笔——必须在本批第1章或第2章内强制回收，不得跳过】\n"
                . implode("\n", $overdueLines) . "\n";
        }
    } catch (\Throwable $e) {
        // ForeshadowingRepo 不可用时静默跳过
    }

    // 计算本批爽点排期（Phase 1 新增）
    $coolPointSchedule = calculateCoolPointSchedule($startChapter, $count);

    // ── 全书进度感知 + 故事大纲引用（F1/F3/F6 优化）───────────────────
    $progressSection  = '';
    $endingContext    = '';  // 细纲层收尾指令
    $storyOutlineSection = ''; // 故事大纲上下文
    $targetChapters   = (int)($novel['target_chapters'] ?? 100);
    $batchProgress    = $targetChapters > 0 ? $endChapter / $targetChapters : 0;
    $remainingChapters = max(0, $targetChapters - $endChapter);

    try {
        // F1: 修正进度计算——基于本批生成的结束章号，而非 status="completed"
        $outlinedBefore = (int)(DB::count('chapters',
            'novel_id=? AND status != "pending" AND chapter_number < ?',
            [$novel['id'], $startChapter])
        );
        $outlinedPct = $targetChapters > 0
            ? (int)round($outlinedBefore / $targetChapters * 100)
            : 0;
        $batchEndPct = $targetChapters > 0
            ? (int)round($endChapter / $targetChapters * 100)
            : 0;

        // 三幕定位（基于本批结束编号）
        $actPhase = '';
        if ($batchProgress <= 0.2) {
            $actPhase = '第一幕（开局建立期）';
        } elseif ($batchProgress <= 0.8) {
            $actPhase = '第二幕（发展对抗期）';
        } else {
            $actPhase = '第三幕（高潮收束期）';
        }

        // 获取伏笔统计（复用 MemoryEngine 的伏笔逻辑）
        $progressLines = [];
        $progressLines[] = "📖 本批生成：第{$startChapter}～{$endChapter}章"
            . " / 全书{$targetChapters}章（本批结束后进度 {$batchEndPct}%，剩余{$remainingChapters}章）";
        $progressLines[] = "🎭 叙事阶段：{$actPhase}";
        if ($outlinedBefore > 0) {
            $progressLines[] = "📝 已生成细纲：{$outlinedBefore}章（{$outlinedPct}%）";
        }

        // 伏笔统计
        try {
            $pendingForeshadowCount = 0;
            $overdueForeshadowCount = 0;
            $pendingList = [];
            $overdueList = [];
            if (class_exists('\\ForeshadowingRepo')) {
                require_once __DIR__ . '/memory/ForeshadowingRepo.php';
                $fRepo = new \ForeshadowingRepo((int)$novel['id']);
                $allPending = $fRepo->listPending();
                $allOverdue = $fRepo->listOverdue($startChapter, 0);
                $pendingForeshadowCount = count($allPending);
                $overdueForeshadowCount = count($allOverdue);

                // 前5条待回收
                usort($allPending, fn($a, $b) => ($a['deadline_chapter'] ?? 99999) <=> ($b['deadline_chapter'] ?? 99999));
                foreach (array_slice($allPending, 0, 5) as $p) {
                    $dl = $p['deadline_chapter'] ? "（应第{$p['deadline_chapter']}章前回收）" : '';
                    $pendingList[] = "第{$p['planted_chapter']}章埋：{$p['description']}{$dl}";
                }
                foreach ($allOverdue as $ov) {
                    $overdueList[] = "第{$ov['planted_chapter']}章埋、应{$ov['deadline_chapter']}章前回收：{$ov['description']}";
                }
            }
            if ($pendingForeshadowCount > 0) {
                $odNote = $overdueForeshadowCount > 0 ? "（其中{$overdueForeshadowCount}条已逾期！）" : '';
                $progressLines[] = "🧵 待回收伏笔：共{$pendingForeshadowCount}条{$odNote}";
                foreach ($pendingList as $f) {
                    $progressLines[] = "  · {$f}";
                }
            }
        } catch (\Throwable $e) {
            // 伏笔统计失败静默跳过
        }

        // F3/F6: 读取全书故事大纲（细纲层首次引入）
        $storyOutline = DB::fetch(
            'SELECT story_arc, act_division, character_arcs, world_evolution, major_turning_points
             FROM story_outlines WHERE novel_id=?',
            [$novel['id']]
        );
        if ($storyOutline) {
            // 故事主线（全程可用）
            $storyArc = trim($storyOutline['story_arc'] ?? '');
            if ($storyArc) {
                $storyOutlineSection .= "\n【全书故事主线——所有细纲必须与此对齐，禁止偏离】\n{$storyArc}\n";
            }

            // 第三幕信息（收尾阶段特别注入）
            if ($batchProgress >= 0.80) {
                $actDiv = json_decode($storyOutline['act_division'] ?? '{}', true);
                $act3   = $actDiv['act3'] ?? null;
                if ($act3) {
                    $storyOutlineSection .= "\n【🏁 第三幕设计——高潮收束期（当前正在此阶段）】\n";
                    $storyOutlineSection .= "主题：{$act3['theme']}\n";
                    $storyOutlineSection .= "章节范围：{$act3['chapters']}\n";
                    if (!empty($act3['key_events'])) {
                        $storyOutlineSection .= "关键事件线：" . implode(' → ', $act3['key_events']) . "\n";
                    }
                    if (!empty($act3['character_growth'])) {
                        $storyOutlineSection .= "人物成长目标：{$act3['character_growth']}\n";
                    }
                }

                // 人物弧线终点（收尾阶段显示目标状态）
                $charArcs = json_decode($storyOutline['character_arcs'] ?? '{}', true);
                if (!empty($charArcs)) {
                    $storyOutlineSection .= "\n【👤 人物弧线终点——大纲必须引导角色抵达以下目标状态】\n";
                    foreach ($charArcs as $cName => $cArc) {
                        $endState = $cArc['end'] ?? '';
                        if ($endState) {
                            $storyOutlineSection .= "· {$cName}：{$endState}\n";
                        }
                    }
                }
            }

            // 世界观演变（收尾阶段注入终局形态）
            $worldEvo = trim($storyOutline['world_evolution'] ?? '');
            if ($worldEvo && $batchProgress >= 0.80) {
                $storyOutlineSection .= "\n【🌍 世界观演变目标——终局形态，大纲须体现】\n{$worldEvo}\n";
            }

            // 未触发的转折点（所有阶段都显示）
            $tps = json_decode($storyOutline['major_turning_points'] ?? '[]', true);
            if (!empty($tps)) {
                $passedTPs  = array_filter($tps, fn($tp) => ($tp['chapter'] ?? 0) < $startChapter);
                $pendingTPs = array_filter($tps, fn($tp) => ($tp['chapter'] ?? 0) >= $startChapter);
                $progressLines[] = "🔖 全书转折点：已过 " . count($passedTPs) . " / 共 " . count($tps) . " 个";
                foreach (array_slice(array_values($pendingTPs), 0, 3) as $tp) {
                    $urgent = ($tp['chapter'] ?? 0) <= $endChapter ? '【本批必须触发】' : '';
                    $progressLines[] = "  → 待触发（第{$tp['chapter']}章）：{$tp['event']} {$urgent}";
                }
            }

            // 人物弧线全程感知（基于实际进度而非 completed 状态）
            $charArcsFull = json_decode($storyOutline['character_arcs'] ?? '{}', true);
            if (!empty($charArcsFull)) {
                foreach ($charArcsFull as $charName => $arc) {
                    if ($batchProgress < 0.4) {
                        $stage = $arc['start'] ?? '';
                        $label = '初始状态';
                    } elseif ($batchProgress < 0.8) {
                        $stage = $arc['midpoint'] ?? '';
                        $label = '中期状态';
                    } else {
                        $stage = $arc['end'] ?? '';
                        $label = '终点状态';
                    }
                    if ($stage) {
                        $progressLines[] = "👤 {$charName}当前弧线（{$label}）：{$stage}";
                    }
                }
            }
        }

        $progressSection = "\n【📊 全书进度感知——生成细纲时必须对齐以下进度】\n"
            . implode("\n", $progressLines) . "\n";

        // F2/F5: 细纲层收尾指令（基于本批进度，替代原 buildEndingContext）
        $endingContext = buildOutlineEndingContext(
            $endChapter,
            $targetChapters,
            $pendingForeshadowCount ?? 0,
            $pendingList ?? [],
            $overdueForeshadowCount ?? 0,
            $overdueList ?? []
        );

    } catch (\Throwable $e) {
        // 进度感知失败静默跳过，不影响主流程
    }

    // F4: 根据进度动态调整 hook_type 规则
    if ($endChapter >= $targetChapters) {
        // 包含最终章：豁免常规hook规则
        $hookTypeRule = 'hook_type：本批包含最终章。最终章的hook可为空("")或"resolution"(完结收束)，pacing建议"慢"，suspense为"无"，以圆满收束替代悬念结尾。其余章五选一：crisis_interrupt/info_bomb/plot_twist/emotional_impact/upgrade_omen（最终章之前禁用new_goal，不再开启新剧情线），相邻不重复';
    } elseif ($batchProgress >= 0.80 && $endChapter >= $targetChapters - 5) {
        // 倒数5章内：高潮冲刺
        $hookTypeRule = 'hook_type：全书收束高潮期，五选一：crisis_interrupt/info_bomb/plot_twist/emotional_impact/upgrade_omen，禁用new_goal（不再开启新剧情线），优先emotional_impact和plot_twist，相邻不重复';
    } elseif ($batchProgress >= 0.80) {
        // 收束期
        $hookTypeRule = 'hook_type：全书收束期，优先crisis_interrupt/info_bomb/plot_twist/emotional_impact，禁用new_goal（不再开启新剧情线），相邻不重复';
    } else {
        // 正常期
        $hookTypeRule = 'hook_type六选一：crisis_interrupt/info_bomb/plot_twist/new_goal/emotional_impact/upgrade_omen，相邻不重复';
    }

    $system = <<<EOT
你是专业网文大纲作家。输出纯JSON数组，无前缀后缀无markdown代码块。

【铁律】
1. summary≤150字，key_points每条≤30字，hook≤40字
2. 必须输出{$count}个完整对象，不得截断
3. 严禁重复【已发生关键事件】
4. 人物职务/身份/处境须与【人物状态】一致
5. 如有【待回收伏笔】，适时安排回收
6. 如有【上章钩子】，本批第一章必须直接承接
7. 每章hook为下章埋具体悬念，禁空洞表述
8. 相邻章有因果关系，前章hook=后章开头触发
9. 如有【故事线回顾】，本批须在其基础上演进，不得矛盾
10. 节奏：{$count}章中≥1"快"≥1"慢"
11. 悬念：≥2章suspense:"有"
12. 爽点排期：{$coolPointSchedule}（相邻章不重复）
13. {$hookTypeRule}
14. 第1章须前500字展示核心悬念，结尾强烈钩子
15. 如有【本卷写作目标】，本批大纲必须推进至少一条目标，本批最后一章须体现目标进展
16. 如有【严重逾期伏笔】，必须在第1章或第2章的key_points中明确安排回收，违反则大纲无效
17. 如有【本卷必须回收的逾期伏笔】，须在本批内安排完毕，不得拖到下一批
18. 如处于收尾阶段，核心矛盾须获得实质性推进或解决，不再开启新支线
19. 尽量不要用"——"、禁用高频AI词汇，包括但不限于：深邃、凝视、缓缓、蓦然、骤然、 indeed、无疑、显然、事实上、值得注意的是、毫无疑问、通常来说、在此基础上、应当注意到、铁锈味、指节泛白、沉默下来、看了几秒、一愣、沉默了几秒、泛白、愣在原地、愣了一下等。换成更生动的表达，比如"老实说"、"很多时候"、"这么一来"、"这里有个细节"、少用破折号【】以及禁用非对话的""

EOT;

    $user = <<<EOT
为《{$novel['title']}》生成第{$startChapter}～{$endChapter}章大纲（共{$count}章）。

类型：{$novel['genre']}  风格：{$novel['writing_style']}
主角：{$protagonistInfo}
情节：{$plotSettings}
世界观：{$worldSettings}
其他：{$extraSettings}
{$progressSection}{$endingContext}{$storyOutlineSection}{$volumeSection}{$overdueSection}{$volumeForceResolve}{$arcSection}{$contextSection}{$prevHookSection}{$momentumSection}{$keyEventsSection}{$characterSection}{$foreshadowSection}
输出JSON数组（{$count}个元素）：
[{"chapter_number":整数,"title":"标题","summary":"概要","key_points":["点1","点2"],"hook":"钩子","hook_type":"六选一","pacing":"快/中/慢","suspense":"有/无"},...]

直接输出JSON，从 [ 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 构建章节正文写作 Prompt（强化 System 约束 + 三层记忆注入）
 */
/**
 * 构建章节正文 Prompt
 *
 * @param array  $novel            小说主记录
 * @param array  $chapter          本章记录
 * @param string $previousSummary  近几章摘要拼接（prompt 里"前情提要"段落）
 * @param string $previousTail     前章尾文（L4）
 * @param array|null $memoryCtx    MemoryEngine::getPromptContext() 的返回值。
 *                                 传入即走新记忆引擎，不传则走 L1/L4 + novels 基础字段的简化模式。
 *                                 结构详见 MemoryEngine::getPromptContext() 文档。
 */
function buildChapterPrompt(
    array $novel,
    array $chapter,
    string $previousSummary,
    string $previousTail = '',
    ?array $memoryCtx = null
): array {
    $targetWords = (int)$novel['chapter_words'];
    
    // 使用动态容差计算
    $dynamicTolerance = calculateDynamicTolerance($targetWords);
    $minWords = $dynamicTolerance['min'];
    $maxWords = $dynamicTolerance['max'];
    $earlyFinishWords = $dynamicTolerance['early_finish'];
    
    // 生成多级预警提示
    $wordCountWarnings = generateWordCountWarnings($targetWords);

    $genre = $novel['genre'] ?? '';
    $densityGuidelines = getDensityGuidelines($genre);

    // 章号（提前赋值，供 $system heredoc 使用）
    $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);

    // 章末钩子类型推荐
    $hookSuggestion = suggestHookType($chapter);
    $chapterHookType = $hookSuggestion['type'];
    $hookTypeDescription = (HOOK_TYPES[$chapterHookType]['name'] ?? '') . '：' . ($hookSuggestion['reason'] ?? '默认轮换');

    // v11: 从系统设置读取四段式结构占比
    $segSetup  = (int)getSystemSetting('ws_segment_ratio_setup', 20, 'int');
    $segRising = (int)getSystemSetting('ws_segment_ratio_rising', 30, 'int');
    $segClimax = (int)getSystemSetting('ws_segment_ratio_climax', 35, 'int');
    $segHook   = (int)getSystemSetting('ws_segment_ratio_hook', 15, 'int');

    $system = <<<EOT
你是一位专业的网络小说作家，正在创作小说《{$novel['title']}》。

【写作铁律，必须遵守优先级最高】
1. 字数硬性限制（最高优先级）：正文必须严格控制在 {$minWords} ~ {$maxWords} 字之间，绝对不可超过 {$maxWords} 字。
2. 字数预警系统（写作时心中估算字数进度）：
{$wordCountWarnings}
3. 字数控制技巧：
   - 开头快速入戏，黄金三行直接抓人
   - 中间情节紧凑，对话与动作交替推进
   - 写到约 {$earlyFinishWords} 字时必须进入钩子收尾
   - 严禁超过 {$maxWords} 字，字数到达即停笔
4. 人物一致性：所有人物的职务、身份、生死状态必须与【人物当前状态】完全一致，不得擅自改变
5. 情节不重复：【全书已发生事件】中出现的任何事件，严禁以任何形式重演或变体重复
6. 逻辑自洽：本章发生的事件必须是前情的自然延伸，因果链条清晰，不得出现无因之果
7. 直接开始：从"第{$chNum}章 {$chapter['title']}"这一行直接开始输出正文，不要有任何前言、后记、解释或"好的，我来写"等废话
8. 风格统一：保持与前文一致的叙事视角、语气和文风，不得中途切换人称

【🔥 黄金三行——本章前三行必须满足以下至少一条】
A. 悬念引导型：反常现象/危机爆发 → 主角处境 → 读者追问"接下来怎么办"
B. 场景代入型：强画面感场景 → 主角感官体验 → 即将发生的变故
C. 动作切入型：战斗/对抗正在进行 → 主角的险境 → 翻盘契机
D. 对话切入型：关键对话 → 冲突暴露 → 行动决定
⚠️ 禁忌：前三行内不得出现超过半行的纯环境/天气/风景描写！

【📊 四段式节奏结构】
- 铺垫段(~{$segSetup}%)：承接上文、建立场景、引入新信息（≤200字纯环境描写）
- 发展段(~{$segRising}%)：推进冲突、角色互动、信息揭露（对话密集区）
- 高潮段(~{$segClimax}%)：爽点释放、情绪顶点、反转或冲突升级
- 钩子段(~{$segHook}%)：使用指定钩子类型收尾，制造强烈悬念

【🎣 章末钩子——必须使用以下指定类型】
本章节尾钩子类型：{$chapterHookType}
类型说明：{$hookTypeDescription}
⚠️ 绝对禁止以平静句结尾！（如"大家都睡了""夜深了""一切归于平静"等）

【📏 描写密度标准——题材：{$genre}】
{$densityGuidelines}

【😊 情绪词汇要求——基于1590本小说分析】
根据网络小说创作规律，本章必须满足以下情绪词汇密度标准：
· 愤怒类词汇：15-20次/万字（如：愤怒、怒火、暴怒、咬牙切齿、火冒三丈）
· 喜悦类词汇：20-30次/万字（如：喜悦、高兴、兴奋、狂喜、心花怒放）
· 惊讶类词汇：10-15次/万字（如：惊讶、震惊、不可思议、目瞪口呆）
· 恐惧类词汇：5-10次/万字（如：恐惧、害怕、战栗、毛骨悚然）
· 悲伤类词汇：5-10次/万字（如：悲伤、悲痛、心碎、黯然神伤）

情绪词汇使用原则：
1. 情绪词汇要自然融入情节，不要刻意堆砌
2. 每个情绪爆发点都要有铺垫和释放
3. 避免情绪过于单一，要有起伏变化
4. 高潮部分要加大情绪词汇密度
5. 不同类型小说可适当调整：玄幻/游戏类愤怒词可更多，都市/言情类喜悦词可更多

【💬 对话与文风】
- 对话密度目标：每千字40-80句对话（都市类可更高至60-100）
- 连续非对话文字不得超过300字（含描写+心理+叙述），超过时必须插入对话或动作打断
- 平均段落150-300字，平均句长20-40字，避免超长段落造成阅读疲劳
- 多用短句推进情节，少用长句堆砌描写
EOT;

    // 关键情节点
    $keyPoints = '';
    if (!empty($chapter['key_points'])) {
        $pts = json_decode($chapter['key_points'], true) ?? [];
        if ($pts) $keyPoints = "\n关键情节点：\n- " . implode("\n- ", $pts);
    }
    $hookLine = !empty($chapter['hook'])
        ? "\n结尾钩子（本章结尾要体现）：{$chapter['hook']}"
        : '';

    // ================================================================
    // 记忆数据来源：优先 MemoryEngine 上下文；未传入时降级为空段
    // ================================================================
    // 弧段摘要（L2）
    $arcSummaries = $memoryCtx['L2_arc_summaries']
        ?? $memoryCtx['arc_summaries']
        ?? [];

    // 人物状态（新 schema：title/status/attributes.recent_change/alive）
    $characterStates = $memoryCtx['character_states'] ?? [];

    // 全书关键事件
    $keyEvents = $memoryCtx['key_events'] ?? [];

    // 待回收伏笔（已按紧急度排序：overdue + due_soon）
    $pendingForeshadow = $memoryCtx['pending_foreshadowing'] ?? [];

    // 故事势能
    $storyMomentum = $memoryCtx['story_momentum'] ?? '';

    // 近章大纲（L3）—— 提供最近 8 章的大纲结构信息，与 previousSummary（摘要）互补
    // L3 侧重"章节结构和情节点"，previousSummary 侧重"情节摘要回顾"
    $recentChapters = $memoryCtx['L3_recent_chapters'] ?? [];

    // 近5章意象禁用（这项 MemoryEngine 未聚合，继续走 chapters.used_tropes）
    $prevTropes = getPreviousUsedTropes((int)$novel['id'], $chNum);

    // 弧段摘要（第二层记忆，全局历史防失忆）
    $arcChapterSection = '';
    if (!empty($arcSummaries)) {
        $arcLines = [];
        foreach ($arcSummaries as $arc) {
            if ((int)$arc['chapter_to'] < $chNum) {
                $arcLines[] = "【第{$arc['chapter_from']}-{$arc['chapter_to']}章故事线】{$arc['summary']}";
            }
        }
        if (!empty($arcLines)) {
            $arcChapterSection = "【全书故事线回顾（必须与此保持一致，不得产生矛盾）】\n"
                               . implode("\n\n", $arcLines) . "\n\n";
        }
    }

    // 前情提要
    $prevSection = $previousSummary
        ? "【前情提要（前几章摘要）】\n{$previousSummary}\n\n"
        : "【说明】本章为小说第一章，请从头开始。\n\n";

    // 近章大纲（L3）—— 最近 8 章的结构信息
    $recentChapterSection = '';
    if (!empty($recentChapters)) {
        $lines = [];
        foreach ($recentChapters as $rc) {
            $rcNum = (int)($rc['chapter_number'] ?? $rc['chapter'] ?? 0);
            $title = $rc['title'] ?? '';
            $outline = safe_substr(trim($rc['outline'] ?? ''), 0, 100);
            $hookTip = !empty($rc['hook']) ? "  →钩子：{$rc['hook']}" : '';
            $lines[] = "第{$rcNum}章《{$title}》：{$outline}{$hookTip}";
        }
        $recentChapterSection = "【近章大纲（章节结构参考，保持连贯）】\n"
                              . implode("\n", $lines) . "\n\n";
    }

    // 前文衔接
    $tailSection = $previousTail
        ? "【前文衔接（上一章结尾原文，请自然衔接，不要重复这段文字）】\n……{$previousTail}\n\n"
        : '';

    // 人物当前状态（MemoryEngine schema: title/status/alive/attributes/last_chapter）
    $characterSection = '';
    if (!empty($characterStates)) {
        $lines = [];
        foreach ($characterStates as $name => $state) {
            // 死亡人物不再出现在写作指引里（alive=false）
            if (isset($state['alive']) && !$state['alive']) continue;

            $parts = [];
            if (!empty($state['title']))  $parts[] = "职务：{$state['title']}";
            if (!empty($state['status'])) $parts[] = "处境：{$state['status']}";

            // 近况从 attributes.recent_change 读取（mapLegacyCharacterUpdate 迁移过来的）
            $attrs = $state['attributes'] ?? [];
            if (is_array($attrs) && !empty($attrs['recent_change'])) {
                $parts[] = "近况：{$attrs['recent_change']}";
            }

            if (!empty($parts)) {
                $lines[] = "· {$name}——" . implode('，', $parts);
            }
        }
        if (!empty($lines)) {
            $characterSection = "【人物当前状态（必须严格遵守，不得与此矛盾）】\n"
                              . implode("\n", $lines) . "\n\n";
        }
    }

    // 全书关键事件
    $eventsSection = '';
    if (!empty($keyEvents)) {
        $lines = [];
        foreach ($keyEvents as $e) {
            $lines[] = "第{$e['chapter']}章：{$e['event']}";
        }
        $eventsSection = "【全书已发生事件（严禁重写或矛盾）】\n" . implode("\n", $lines) . "\n\n";
    }

    // 故事势能（MemoryEngine 新引入，帮助保持冲突张力）
    $momentumSection = '';
    if ($storyMomentum !== '') {
        $momentumSection = "【当前故事势能（本章需延续或推进此张力）】\n{$storyMomentum}\n\n";
    }

    // 近5章意象禁用
    $tropesSection = '';
    if (!empty($prevTropes)) {
        $tropesSection = "【本章禁止重复使用的意象/场景（近期已用，需要新鲜感）】："
                       . implode('、', $prevTropes) . "\n\n";
    }

    // 临近 deadline 的伏笔提示
    // MemoryEngine 输出已按紧急度排好序（overdue 在前），这里只负责格式化
    $foreshadowSection = '';
    $dueForeshadow = array_filter(
        $pendingForeshadow,
        fn($f) => !empty($f['deadline']) && $chNum >= (int)$f['deadline'] - 3
    );
    if (!empty($dueForeshadow)) {
        $lines = [];
        foreach ($dueForeshadow as $f) {
            $deadline = (int)$f['deadline'];
            $lines[]  = ($chNum >= $deadline - 2 && $chNum <= $deadline + 2)
                ? "⚠️【紧急】第{$f['chapter']}章埋：{$f['desc']}（应{$deadline}章前回收）"
                : "第{$f['chapter']}章埋：{$f['desc']}（建议{$deadline}章前回收）";
        }
        $foreshadowSection = "【本章应考虑回收的伏笔（已到期或临近，请自然安排回收）】\n"
                           . implode("\n", $lines) . "\n\n";
    }

    // 语义召回命中（MemoryEngine 的 semantic_hits）—— 长尾记忆补充
    // hits 可能混合两种来源：source='atom'（MemoryEngine）/ source='kb'（KnowledgeBase）
    $semanticSection = '';
    $semanticHits = $memoryCtx['semantic_hits'] ?? [];
    if (!empty($semanticHits)) {
        // KB 来源的类别名称映射（用于提示 AI 这条线索属于什么资料）
        $kbTypeLabels = [
            'character'     => '角色资料',
            'worldbuilding' => '世界观设定',
            'plot'          => '情节线索',
            'style'         => '风格参考',
        ];
        $lines = [];
        foreach ($semanticHits as $hit) {
            if (($hit['source'] ?? 'atom') === 'kb') {
                $label = $kbTypeLabels[$hit['type']] ?? 'KB';
                $lines[] = "· [{$label}] {$hit['content']}";
            } else {
                $chTag = !empty($hit['chapter']) ? "[第{$hit['chapter']}章] " : '';
                $lines[] = "· {$chTag}{$hit['content']}";
            }
        }
        $semanticSection = "【相关历史线索（语义关联，可作背景参考）】\n"
                         . implode("\n", $lines) . "\n\n";
    }

    // 章节简介蓝图（如已生成）
    $synopsisSection = '';
    if (!empty($chapter['synopsis_id'])) {
        $synopsis = \DB::fetch('SELECT * FROM chapter_synopses WHERE id=?', [$chapter['synopsis_id']]);
        if ($synopsis) {
            $synopsisSection = "【章节简介（详细写作蓝图，必须遵循）】\n简介：{$synopsis['synopsis']}\n\n";

            $sceneBreakdown = json_decode($synopsis['scene_breakdown'] ?? '[]', true);
            if (!empty($sceneBreakdown)) {
                $synopsisSection .= "场景分解：\n";
                foreach ($sceneBreakdown as $scene) {
                    $chars = implode('、', $scene['characters'] ?? []);
                    $synopsisSection .= "场景{$scene['scene']}：{$scene['location']}，人物：{$chars}，{$scene['action']}（{$scene['emotion']}）\n";
                }
                $synopsisSection .= "\n";
            }

            $dialogueBeats = json_decode($synopsis['dialogue_beats'] ?? '[]', true);
            if (!empty($dialogueBeats)) {
                $synopsisSection .= "对话要点：" . implode('；', $dialogueBeats) . "\n\n";
            }

            $sensoryDetails = json_decode($synopsis['sensory_details'] ?? '{}', true);
            if (!empty($sensoryDetails)) {
                $parts = [];
                if (!empty($sensoryDetails['visual']))     $parts[] = "视觉-{$sensoryDetails['visual']}";
                if (!empty($sensoryDetails['auditory']))   $parts[] = "听觉-{$sensoryDetails['auditory']}";
                if (!empty($sensoryDetails['atmosphere'])) $parts[] = "氛围-{$sensoryDetails['atmosphere']}";
                $synopsisSection .= "感官细节：" . implode(' ', $parts) . "\n\n";
            }

            $synopsisSection .= "节奏：{$synopsis['pacing']}  |  结尾悬念：{$synopsis['cliffhanger']}\n\n";
        }
    }

    // 当前卷目标注入（让正文写作感知全书进度目标）
    $volumeGoalSection = '';
    try {
        $currentVolume = \DB::fetch(
            'SELECT volume_goals, must_resolve_foreshadowing, volume_number, title
             FROM volume_outlines
             WHERE novel_id=? AND start_chapter <= ? AND end_chapter >= ?
             LIMIT 1',
            [(int)$novel['id'], $chNum, $chNum]
        );
        if ($currentVolume) {
            $volGoals    = json_decode($currentVolume['volume_goals'] ?? '[]', true) ?: [];
            $mustResolve = json_decode($currentVolume['must_resolve_foreshadowing'] ?? '[]', true) ?: [];
            if (!empty($volGoals)) {
                $volumeGoalSection .= "【第{$currentVolume['volume_number']}卷《{$currentVolume['title']}》写作目标（本章需推进）】\n"
                    . implode("\n", array_map(fn($g) => "· {$g}", $volGoals)) . "\n\n";
            }
            if (!empty($mustResolve)) {
                $volumeGoalSection .= "【本卷必须回收的伏笔（若本章是回收时机，请自然融入情节）】\n"
                    . implode("\n", array_map(fn($f) => "· {$f}", $mustResolve)) . "\n\n";
            }
        }
    } catch (\Throwable $e) {
        // 查询失败静默跳过
    }

    // 全书进度感知（精简版，正文写作用）
    $chapterProgressSection = '';
    $chapterEndingContext   = '';  // 全书收尾指令（进度 >= 90% 时注入）
    try {
        // 优先使用传入的 memoryCtx 中的进度上下文，避免重复创建 MemoryEngine
        if (isset($memoryCtx['progress_context'])) {
            $prog = $memoryCtx['progress_context'];
        } else {
            $progressEngine = new MemoryEngine((int)$novel['id']);
            $prog = $progressEngine->getProgressContext($chNum);
        }
        if ($prog['target_chapters'] > 0) {
            $pLines = [];
            $pLines[] = "当前第{$chNum}章 / 全书{$prog['target_chapters']}章（{$prog['progress_pct']}%，剩余{$prog['remaining_chapters']}章）";
            if ($prog['act_phase'])      $pLines[] = "叙事阶段：{$prog['act_phase']}";
            if ($prog['volume_progress']) $pLines[] = "所在卷：{$prog['volume_progress']}";
            $pendingCnt = $prog['pending_foreshadowing_count'];
            $overdueCnt = $prog['overdue_foreshadowing_count'];
            if ($pendingCnt > 0) {
                $overdueNote = $overdueCnt > 0 ? "，{$overdueCnt}条已逾期" : '';
                $pLines[] = "未回收伏笔：{$pendingCnt}条{$overdueNote}";
            }
            // 最近未触发的转折点
            $nextTurning = array_values(array_filter(
                $prog['major_turning_points'],
                fn($t) => !$t['passed'] && $t['chapter'] > $chNum
            ));
            if (!empty($nextTurning)) {
                $nt = $nextTurning[0];
                $pLines[] = "下一个全书转折点：第{$nt['chapter']}章——{$nt['event']}";
            }
            $chapterProgressSection = "【📊 全书进度】" . implode("  ·  ", $pLines) . "\n\n";

            // 全书收尾感知：进度 >= CFG_ENDING_START_RATIO 时注入收尾指令
            $chapterEndingContext = buildEndingContext($prog, $chNum);
        }
    } catch (\Throwable $e) {
        // 静默跳过
    }

    // ── 动态节奏调整（基于1590本小说分析）────────────────────────────────────
    $rhythmSection = '';
    try {
        require_once __DIR__ . '/rhythm_adjuster.php';
        $rhythmAdjuster = new RhythmAdjuster((int)$novel['id']);
        
        // 获取近期爽点历史（从数据库读取）
        $coolPointHistory = [];
        $recentChapters = DB::fetchAll(
            'SELECT chapter_number, cool_point_type FROM chapters 
             WHERE novel_id=? AND chapter_number < ? AND cool_point_type IS NOT NULL 
             ORDER BY chapter_number DESC LIMIT 20',
            [(int)$novel['id'], $chNum]
        );
        foreach ($recentChapters as $rc) {
            if (!empty($rc['cool_point_type'])) {
                $coolPointHistory[] = [
                    'chapter' => (int)$rc['chapter_number'],
                    'type' => $rc['cool_point_type'],
                ];
            }
        }
        
        $rhythm = $rhythmAdjuster->calculateRhythm($chNum, $coolPointHistory);
        $rhythmSection = $rhythmAdjuster->generateRhythmInstructions($rhythm);
        
        // 如果节奏调整器要求爽点，更新四段式比例
        if (!empty($rhythm['segment_ratios'])) {
            $segSetup  = $rhythm['segment_ratios']['setup'];
            $segRising = $rhythm['segment_ratios']['rising'];
            $segClimax = $rhythm['segment_ratios']['climax'];
            $segHook   = $rhythm['segment_ratios']['hook'];
        }
    } catch (\Throwable $e) {
        // 节奏调整器失败静默跳过
    }
    
    // ── 收尾阶段强制指令（解决后续章节无法自动收尾问题）────────────────────
    $endingSection = '';
    try {
        require_once __DIR__ . '/ending_enforcer.php';
        $endingEnforcer = new EndingEnforcer((int)$novel['id'], $chNum);
        
        if ($endingEnforcer->needsEndingEnforcement()) {
            $endingSection = $endingEnforcer->generateEndingInstructions();
            
            // 如果有伏笔回收建议，也添加进来
            $foreshadowAdvice = $endingEnforcer->generateForeshadowResolutionAdvice();
            if ($foreshadowAdvice) {
                $endingSection .= "\n\n" . $foreshadowAdvice;
            }
        }
    } catch (\Throwable $e) {
        // 收尾强制指令失败静默跳过
    }

    // ================================================================
    // v6/B3：KB 上下文注入改由 MemoryEngine::semanticSearch(includeKB=true) 承担。
    // ================================================================

    $user = <<<EOT
{$arcChapterSection}{$prevSection}{$recentChapterSection}{$tailSection}{$characterSection}{$momentumSection}{$eventsSection}{$semanticSection}{$tropesSection}{$foreshadowSection}{$volumeGoalSection}{$chapterProgressSection}{$chapterEndingContext}{$rhythmSection}{$endingSection}{$synopsisSection}【小说信息】
书名：{$novel['title']}  |  类型：{$genre}  |  风格：{$novel['writing_style']}
主角：{$novel['protagonist_info']}
世界观：{$novel['world_settings']}

【本章大纲】
第{$chNum}章：{$chapter['title']}
概要：{$chapter['outline']}{$keyPoints}{$hookLine}

【📏 本章描写密度要求（题材：{$genre}）】
{$densityGuidelines}
请严格按此比例分配各元素篇幅。

【🎣 本章章末钩子类型】
指定类型：{$chapterHookType}（{$hookTypeDescription}）
结尾必须用该类型制造强烈悬念，绝对禁止平静收尾！

【写作铁律——逐条遵守】
1. 【字数硬限】正文必须严格控制在 {$minWords} ~ {$maxWords} 字，超过 {$maxWords} 字即为违规。当已写约 {$earlyFinishWords} 字时立即启动收尾，禁止再引入新情节。
2. 【黄金三行】本章前三行必须是动作/对话/悬念/异常之一，禁止纯环境描写开头
3. 【四段节奏】铺垫(~{$segSetup}%)→发展/对话密集区(~{$segRising}%)→高潮/爽点释放(~{$segClimax}%)→钩子收尾(~{$segHook}%)
4. 【情绪密度】必须满足情绪词汇密度标准（愤怒15-20次/万字、喜悦20-30次/万字、惊讶10-15次/万字），高潮段落要加大情绪词密度
5. 对话自然生动，有个性，符合各自性格；对话密度每千字40-80句
6. 连续非对话文字不超过300字，超长段落必须插入对话或动作打断
7. 情节紧凑，张弛有度，不拖沓
8. 结尾使用指定钩子类型制造强烈悬念，引发读者急切想知道下文
9. 与前文衔接自然，保持语气和叙事风格一致
10. 人物职务/身份必须与上方【人物当前状态】完全一致
11. 如有【章节简介】，必须严格遵循其中的场景分解和对话要点

【字数控制技巧】
- 开头快速入戏，黄金三行直接抓人，不要冗长的环境铺垫
- 中间情节紧凑，对话与动作交替推进，保持描写密度符合题材标准
- 高潮段集中释放爽点情绪，不要分散
- 写到约 {$earlyFinishWords} 字时必须进入钩子收尾，禁止继续新情节
- 严禁超过 {$maxWords} 字，字数到达即停笔

请开始写作：
EOT;

    // ── Agent 指令注入（AgentDirectives）────────────────────────────────────
    $agentDirectivesSection = '';
    try {
        require_once __DIR__ . '/agents/AgentDirectives.php';
        $directives = AgentDirectives::active((int)$novel['id'], $chNum);
        
        if (!empty($directives)) {
            $directiveLines = [];
            foreach ($directives as $directive) {
                $typeLabel = match($directive['type']) {
                    'quality' => '质量监控',
                    'strategy' => '写作策略',
                    'optimization' => '优化建议',
                    default => 'Agent指令'
                };
                $directiveLines[] = "· [{$typeLabel}] {$directive['directive']}";
            }
            
            $agentDirectivesSection = "\n\n【🤖 Agent 指令（本章写作必须遵循）】\n"
                . implode("\n", $directiveLines) . "\n";
        }
    } catch (\Throwable $e) {
        // Agent 指令获取失败静默跳过
    }
    
    // 将 Agent 指令添加到 user content 末尾
    $user .= $agentDirectivesSection;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 构建全书故事大纲 Prompt（三幕式结构）
 */
function buildStoryOutlinePrompt(array $novel): array {
    $truncate = fn(string $text, int $limit = 300): string =>
        safe_strlen($text) > $limit ? safe_substr($text, 0, $limit) . '…（略）' : $text;

    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 300);
    $plotSettings    = $truncate($novel['plot_settings']    ?? '', 300);
    $worldSettings   = $truncate($novel['world_settings']   ?? '', 300);
    $extraSettings   = $truncate($novel['extra_settings']   ?? '', 200);

    $targetChapters = (int)($novel['target_chapters'] ?? 100);
    $act1End = (int)($targetChapters * 0.2);
    $act2End = (int)($targetChapters * 0.8);

    // 读取用户预设的卷结构（新建时已写入 volume_outlines）
    $volumePlanSection = '';
    try {
        $presetVolumes = \DB::fetchAll(
            'SELECT volume_number, title, start_chapter, end_chapter
             FROM volume_outlines WHERE novel_id=? ORDER BY volume_number ASC',
            [(int)$novel['id']]
        );
        if (!empty($presetVolumes)) {
            $volLines = [];
            foreach ($presetVolumes as $pv) {
                $vtitle = $pv['title'] ?: "第{$pv['volume_number']}卷";
                $chCount = $pv['end_chapter'] - $pv['start_chapter'] + 1;
                $volLines[] = "第{$pv['volume_number']}卷《{$vtitle}》：第{$pv['start_chapter']}-{$pv['end_chapter']}章（共{$chCount}章）";
            }
            $volumePlanSection = "\n【用户预设卷结构——故事大纲必须严格按照此卷划分设计，转折点和关键事件需落在对应卷内】\n"
                . implode("\n", $volLines) . "\n";
        }
    } catch (\Throwable $e) {
        // 无卷结构时静默跳过
    }

    $system = <<<EOT
你是一位资深的小说策划师,擅长构建完整的故事框架。
输出规则（必须严格遵守）：
1. 只输出纯JSON,不要有任何前缀、后缀或markdown代码块
2. 确保故事有清晰的开端、发展、高潮、结局
3. 每个幕的主题明确,转折点合理
4. 人物成长轨迹清晰可信
5. 所有字段值中不得出现未转义的双引号
EOT;

    $user = <<<EOT
为小说《{$novel['title']}》设计全书故事大纲。

书名：{$novel['title']}  类型：{$novel['genre']}  风格：{$novel['writing_style']}
主角：{$protagonistInfo}
情节：{$plotSettings}
世界观：{$worldSettings}
其他：{$extraSettings}
目标章数：{$targetChapters}章
{$volumePlanSection}
请输出以下格式的JSON（只输出JSON,不要有其他文字）：
{
  "story_arc": "全书故事主线发展脉络（200字）",
  "act_division": {
    "act1": {"chapters": "1-{$act1End}", "theme": "开篇主题", "key_events": ["事件1","事件2","事件3"], "character_growth": "主角本幕成长"},
    "act2": {"chapters": "{$act1End}-{$act2End}", "theme": "发展主题", "key_events": ["事件1","事件2","事件3"], "character_growth": "主角本幕成长"},
    "act3": {"chapters": "{$act2End}-{$targetChapters}", "theme": "高潮主题", "key_events": ["事件1","事件2","事件3"], "character_growth": "主角本幕成长"}
  },
  "major_turning_points": [
    {"chapter": 章节号, "event": "转折事件描述（必须落在对应卷的章节范围内）", "impact": "对故事的影响", "volume": 卷号}
  ],
  "character_arcs": {
    "{$novel['protagonist_name']}": {"start": "初始状态", "midpoint": "中期变化", "end": "最终状态"}
  },
  "world_evolution": "世界观如何随故事发展演变（50字）",
  "recurring_motifs": ["重复意象1", "重复意象2", "重复意象3"]
}

要求：major_turning_points 每卷至少安排1个转折点，章节号须落在该卷范围内。
直接输出JSON，从 { 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 构建章节详细简介 Prompt（场景分解 + 对话要点 + 感官细节）
 */
function buildChapterSynopsisPrompt(array $novel, array $chapter, array $storyOutline): array {
    $truncate = fn(string $text, int $limit = 200): string =>
        safe_strlen($text) > $limit ? safe_substr($text, 0, $limit) . '…' : $text;

    $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);
    $actInfo         = getActInfo($storyOutline, $chNum);
    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 200);
    $worldSettings   = $truncate($novel['world_settings']   ?? '', 200);

    // 简介字数与章节目标联动：集中配置管理
    $chTargetWords  = (int)($novel['chapter_words'] ?? 2000);
    $synopsisMin    = max(CFG_SYNOPSIS_MIN_WORDS, (int)($chTargetWords * CFG_SYNOPSIS_MIN_RATIO));
    $synopsisMax    = min(CFG_SYNOPSIS_MAX_WORDS, (int)($chTargetWords * CFG_SYNOPSIS_MAX_RATIO));
    $synopsisRange  = "{$synopsisMin}-{$synopsisMax}";

    $keyPoints = '';
    if (!empty($chapter['key_points'])) {
        $pts = json_decode($chapter['key_points'], true) ?? [];
        if ($pts) $keyPoints = "\n关键情节点：\n- " . implode("\n- ", $pts);
    }

    $system = <<<EOT
你是一位小说场景设计师,擅长将章节大纲细化为可执行的写作蓝图。
输出规则（必须严格遵守）：
1. 只输出纯JSON,不要有任何前缀、后缀或markdown代码块
2. 场景分解要具体,有画面感
3. 对话要点要符合人物性格
4. 感官细节要丰富,有代入感
5. 所有字段值中不得出现未转义的双引号
EOT;

    $storyArcExcerpt = $truncate($storyOutline['story_arc'] ?? '', 150);

    $user = <<<EOT
为小说《{$novel['title']}》第{$chNum}章生成详细简介。

【全书定位】
当前幕：{$actInfo['theme']}
本幕关键事件：{$actInfo['key_events']}
故事主线：{$storyArcExcerpt}

【章节大纲】
标题：{$chapter['title']}
概要：{$chapter['outline']}{$keyPoints}
钩子：{$chapter['hook']}

【小说设定】
主角：{$protagonistInfo}
风格：{$novel['writing_style']}
世界观：{$worldSettings}

请输出以下格式的JSON（只输出JSON,不要有其他文字）：
{
  "chapter_number": {$chNum},
  "title": "{$chapter['title']}",
  "synopsis": "{$synopsisRange}字详细简介，包含场景转换、人物行动、情感变化",
  "scene_breakdown": [
    {"scene": 1, "location": "具体地点", "characters": ["人物1","人物2"], "action": "主要行动", "emotion": "情感基调", "purpose": "场景作用"}
  ],
  "dialogue_beats": ["关键对话要点1", "关键对话要点2", "关键对话要点3"],
  "sensory_details": {"visual": "视觉细节", "auditory": "听觉细节", "atmosphere": "氛围营造"},
  "pacing": "快/中/慢",
  "cliffhanger": "结尾悬念设计",
  "foreshadowing": ["埋下的伏笔1", "埋下的伏笔2"],
  "callbacks": ["呼应前文的点1", "呼应前文的点2"]
}

直接输出JSON，从 { 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 构建细纲专用的收尾上下文 Prompt 片段（F2/F5 新增）
 *
 * 用于 buildOutlinePrompt()——细纲生成层的结局设计指导。
 * 与 buildEndingContext() 的区别：细纲层关注情节结构/高潮设计/人物归宿，
 * 而非正文层的字数/文笔/情感基调。
 *
 * @param int   $endChapter              本批生成的结束章号
 * @param int   $targetChapters           全书目标章数
 * @param int   $pendingForeshadowCount   待回收伏笔总数
 * @param array $pendingList              待回收伏笔列表
 * @param int   $overdueForeshadowCount   逾期伏笔数
 * @param array $overdueList              逾期伏笔列表
 * @return string 收尾指令文本（无需收尾时返回空字符串）
 */
function buildOutlineEndingContext(
    int $endChapter,
    int $targetChapters,
    int $pendingForeshadowCount = 0,
    array $pendingList = [],
    int $overdueForeshadowCount = 0,
    array $overdueList = []
): string {
    if ($targetChapters <= 0) return '';

    $batchEndPct = $endChapter / $targetChapters;
    $remaining   = $targetChapters - $endChapter;

    // 80% 以前不注入结尾感知（比正文层 CFG_ENDING_START_RATIO=0.90 略早，以便细纲提前规划收束结构）
    if ($batchEndPct < 0.80) return '';

    $lines = [];

    // === 收束期（80%-95%）===
    if ($batchEndPct < 0.95) {
        $lines[] = '';
        $lines[] = '【📐 全书收束期——大纲设计必须遵循】';
        $lines[] = '全书已完成约 ' . (int)($batchEndPct * 100) . '%，剩余约' . $remaining . '章。你现在处于收束期。';
        $lines[] = '';
        $lines[] = '收束期设计要求：';
        $lines[] = '1. 禁止在大纲中引入全新主线冲突或新核心人物';
        $lines[] = '2. 各支线应开始向主线收拢，为最终高潮铺路';
        $lines[] = '3. 主角成长弧线应接近目标状态，展现质变成果';
        $lines[] = '4. 对抗强度应逐步升级，为最终决战蓄力';
        $lines[] = '5. 节奏应由"展开"转为"收束"，减少新场景展开';
    }

    // === 结局期（95%-100%）===
    if ($batchEndPct >= 0.95) {
        $lines[] = '';
        $lines[] = '【🏁 全书结局设计——这是大结局章节的大纲】';
        $lines[] = '全书仅剩余约' . $remaining . '章。你的大纲必须为全书收尾。';
        $lines[] = '';
        $lines[] = '结局期设计结构（剩余章节按此分配）：';

        if ($remaining >= 5) {
            $lines[] = '  前段（约40%章节）：阴谋揭露/终极对抗准备阶段';
            $lines[] = '  中段（约35%章节）：大决战/核心矛盾全面爆发（全书最高潮）';
            $lines[] = '  后段（约15%章节）：冲突解决/尘埃落定/人物归宿';
            $lines[] = '  末尾（约10%章节）：终章/余韵/落幕感';
        } elseif ($remaining >= 3) {
            $lines[] = '  前段（约60%章节）：大决战/核心矛盾爆发解决';
            $lines[] = '  后段（约40%章节）：终章/人物归宿/落幕余韵';
        } else {
            $lines[] = '  必须在本批中完成：核心矛盾解决 + 人物结局 + 落幕感';
        }

        $lines[] = '';
        $lines[] = '结局期铁律：';
        $lines[] = '1. 核心矛盾必须在本批中得到最终解决，不得再拖延';
        $lines[] = '2. 所有主要人物的命运必须在本批中明确交代';
        $lines[] = '3. 世界观演化应有"最终形态"的呈现';
        $lines[] = '4. 情感基调由对抗紧张逐步过渡到释然/圆满（悲剧除外）';
    }

    // === 最终章特殊规则 ===
    if ($endChapter >= $targetChapters) {
        $lines[] = '';
        $lines[] = '【📖 最终章（第' . $targetChapters . '章）特别规则】';
        $lines[] = '1. 本章不使用悬念钩子（hook可为空或"resolution"收束型结尾）';
        $lines[] = '2. hook_type 豁免常规六选一，使用"resolution"收束型结尾';
        $lines[] = '3. 概要（summary）应聚焦"事件结局+人物归宿+落幕感"';
        $lines[] = '4. key_points 应以"回望/释然/传承/新生"等收束性主题为主';
        $lines[] = '5. pacing 建议为"慢"，给读者足够的余韵空间';
        $lines[] = '6. suspense 应为"无"——以圆满收束结尾';
    }

    // 倒数第2-3章：高潮冲刺
    if ($endChapter >= $targetChapters - 3 && $endChapter < $targetChapters) {
        $lines[] = '';
        $lines[] = '【⚡ 高潮冲刺——距最终章仅' . ($targetChapters - $endChapter) . '章】';
        $lines[] = '1. 本章是大决战/核心矛盾爆发的关键章节';
        $lines[] = '2. 节奏尽量"快"，全力推进冲突解决';
        $lines[] = '3. 爽点类型优先选择：越级战斗胜利/真相揭露/背水一战';
        $lines[] = '4. hook_type 优先 emotional_impact（情感冲击）或 plot_twist（反转），不做新悬念';
    }

    // F5: 收尾阶段伏笔强制回收
    if ($batchEndPct >= 0.85 && $pendingForeshadowCount > 0) {
        $lines[] = '';
        $lines[] = '【🧵 收尾伏笔强制回收——以下伏笔必须在剩余章节中全部回收】';
        $lines[] = '当前待回收伏笔：' . $pendingForeshadowCount . '条'
            . ($overdueForeshadowCount > 0 ? '（其中' . $overdueForeshadowCount . '条已逾期）' : '');
        $lines[] = '';
        $lines[] = '强制要求：';
        $lines[] = '1. 所有逾期伏笔必须在本批第1-3章内完成回收';
        $lines[] = '2. 剩余未逾期伏笔按 deadline 分配到对应章节';
        $lines[] = '3. 最终章前的倒数第2章必须完成所有伏笔回收';
        $lines[] = '4. 每回收一条伏笔，在对应章的 key_points 中明确标注"回收：xxx伏笔"';

        // 列举需要回收的伏笔
        if (!empty($overdueList)) {
            $lines[] = '  逾期伏笔（优先回收）：';
            foreach ($overdueList as $f) {
                $lines[] = '    ' . $f;
            }
        }
        if (!empty($pendingList)) {
            $lines[] = '  待回收伏笔：';
            $idx = 0;
            foreach ($pendingList as $f) {
                if ($idx++ >= 8) break;
                $lines[] = '    ' . $f;
            }
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * 构建全书收尾上下文 Prompt 片段
 *
 * 当全书进度 >= CFG_ENDING_START_RATIO 时注入，指导 AI 收束故事线并写结局。
 * 用于 buildChapterPrompt() 和 buildOutlinePrompt()。
 *
 * @param array $prog          MemoryEngine::getProgressContext() 的返回值
 * @param int   $currentChapter 当前正在处理的章号
 * @return string 收尾指令文本（无需收尾时返回空字符串）
 */
function buildEndingContext(array $prog, int $currentChapter): string
{
    if (($prog['target_chapters'] ?? 0) <= 0) return '';

    $pct       = (int)($prog['progress_pct'] ?? 0);
    $remaining = (int)($prog['remaining_chapters'] ?? 0);
    $target    = (int)($prog['target_chapters'] ?? 0);
    $endingThreshold = (int)(CFG_ENDING_START_RATIO * 100);

    if ($pct < $endingThreshold) return '';

    $lines = [];

    // === 收尾阶段（进度 >= 90%）===
    $lines[] = '';
    $lines[] = '【🏁 全书收尾阶段——以下指令优先级高于常规写作规则】';
    $lines[] = "本书已完成{$pct}%，仅剩{$remaining}章。你现在处于收尾阶段，必须逐步收束所有故事线。";
    $lines[] = '';
    $lines[] = '收尾约束：';
    $lines[] = '1. 禁止引入新主线冲突、新核心人物、新势力阵营——精力集中在现有故事线的推进和收束';
    $lines[] = '2. 核心矛盾必须在剩余章节中获得实质性推进或解决，不可再拖延';
    $lines[] = '3. 所有未回收的伏笔（当前' . ($prog['pending_foreshadowing_count'] ?? 0) . '条），必须在剩余' . $remaining . '章中全部回收完毕';
    $lines[] = '4. 主角成长弧线应逐渐趋近目标状态（参考【全书进度感知】中的角色弧线信息）';
    $lines[] = '5. 情感基调由危机/对抗逐步向释然/达成过渡（悲剧/虐文除外，按故事基调执行）';
    $lines[] = '6. 章节节奏应由"推进"逐步转为"收束"，减少新支线展开，增加回收和照应';

    // === 终局冲刺（剩余 <= 3 章）===
    if ($remaining <= 3 && $remaining > 1) {
        $lines[] = '';
        $lines[] = "【🎯 终局冲刺——仅剩{$remaining}章，即将完结】";
        $lines[] = '1. 核心矛盾必须在剩余章节中彻底解决，不可留悬';
        $lines[] = '2. 剩余伏笔加速回收，本章能回收的绝对不拖到下一章';
        $lines[] = '3. 情绪强度应推至全书最高或次高区间，读者期待感最强';
        $lines[] = '4. 每个剩余章节都必须有实质性收束推进，不可灌水拖延';
    }

    // === 最终章（剩余 <= 1 且当前章 >= 目标章数）===
    if ($remaining <= 1 && $currentChapter >= $target) {
        $lines[] = '';
        $lines[] = '【📖 最终章特殊规则——这是全书最后一章】';
        $lines[] = '1. 本章不设章末悬念钩子（钩子规则在此章豁免），应以圆满收束替代悬念结尾';
        $lines[] = '2. 给主要人物明确的结局或去向交代，主角成长弧线在本章达到终点';
        $lines[] = '3. 结局应有情感重量和完成感（大团圆/悲壮/开放式/轮回暗示均可，但必须是完整结局）';
        $lines[] = '4. 结尾300-500字营造"落幕感"，让读者感受到故事的结束与余韵';
        $lines[] = '5. 如有续集计划，可在结尾隐晦埋下宏观伏笔（世界观级线索），但本章本身必须给出本作的完整结局';
    }

    return implode("\n", $lines) . "\n";
}

// ================================================================
// Phase 1 优化：写作特征辅助函数
// 来源：《网络小说写作特点分析报告》量化规则
// ================================================================

/**
 * 章末钩子六式定义
 * 来源：报告第二章·叙事节奏模型
 */
const HOOK_TYPES = [
    'crisis_interrupt'   => [
        'name'     => '危机打断型',
        'desc'     => '对话被突然中断 / 敌人意外出现 / 紧急消息传来',
        'usage'    => '25%，最常用的高张力钩子',
        'template' => '就在这时……/突然……/一道身影闪现……',
    ],
    'info_bomb'          => [
        'name'     => '信息爆炸型',
        'desc'     => '发现惊天秘密 / 身份揭晓 / 预言应验',
        'usage'    => '20%，适合转折章',
        'template' => '原来……/真相竟然是……/他终于知道了……',
    ],
    'plot_twist'         => [
        'name'     => '反转颠覆型',
        'desc'     => '盟友背叛 / 敌人是自己人 / 得到的是假货',
        'usage'    => '20%，冲击力最强',
        'template' => '然而他没想到……/一切都在他的算计之中……',
    ],
    'new_goal'           => [
        'name'     => '新目标型',
        'desc'     => '收到邀请 / 发现新地图 / 新任务发布',
        'usage'    => '15%，适合过渡到新篇章',
        'template' => '与此同时，远方的……/一封信送到了……',
    ],
    'emotional_impact'   => [
        'name'     => '情感冲击型',
        'desc'     => '重要人物遇险 / 关系破裂 / 惊喜重逢',
        'usage'    => '12%，催泪/燃情利器',
        'template' => '当他看到那个身影时……/这一刻，一切都变了……',
    ],
    'upgrade_omen'       => [
        'name'     => '升级预示型',
        'desc'     => '突破征兆 / 新能力觉醒 / 境界松动',
        'usage'    => '8%，为下一波爽点蓄力',
        'template' => '体内的力量开始躁动……/某种变化正在悄然发生……',
    ],
];

/**
 * 六大爽点类型定义及权重
 * 来源：报告第四章·情节框架模式
 *
 * 2026-04-21 优化说明（基于《万古神帝》99章实测分析）：
 *   - 参考作品爽点密度：0.88/章；目标区间：0.8-1.0/章
 *   - 旧版 cooldown 过长，理论最大密度仅约 0.68，无法达标
 *   - 新版将所有 cooldown 按约 ×0.55 压缩，理论上限提升至 ~1.20
 *   - 配合 calculateCoolPointSchedule() 的双爽点机制，实际输出稳定在 0.85-1.0 区间
 *
 * 各类型新 cooldown 依据（参考小说实测间隔）：
 *   face_slap    3章 ← 最高频，每小弧度必备，实测 3.2章/次
 *   underdog_win 4章 ← 核心爽点，实测 4.1章/次
 *   treasure_find 5章 ← 奇遇驱动，实测 5.3章/次
 *   breakthrough  6章 ← 修为节点，实测 6.8章/次（阶段性）
 *   power_expand  7章 ← 势力线推进，实测 7.5章/次
 *   romance_win   9章 ← 情感线辅助，实测 9.2章/次
 */
const COOL_POINT_TYPES = [
    'underdog_win'  => ['name' => '越级战斗胜利', 'weight' => 20, 'cooldown' => 4],
    'face_slap'     => ['name' => '打脸反转',     'weight' => 18, 'cooldown' => 3],
    'treasure_find' => ['name' => '宝物/奇遇',    'weight' => 15, 'cooldown' => 5],
    'breakthrough'  => ['name' => '修为突破',     'weight' => 15, 'cooldown' => 6],
    'power_expand'  => ['name' => '势力扩张',     'weight' => 12, 'cooldown' => 7],
    'romance_win'   => ['name' => '红颜倾心',     'weight' => 10, 'cooldown' => 9],
    // 新增：扩充爽点库，防止1000章重复疲劳
    'truth_reveal'  => ['name' => '真相揭露',     'weight' => 14, 'cooldown' => 8],   // 惊天秘密、身份揭开、幕后黑手
    'last_stand'    => ['name' => '背水一战',     'weight' => 13, 'cooldown' => 6],   // 绝境反杀、以弱胜强、孤注一掷
    'sacrifice'     => ['name' => '牺牲感动',     'weight' => 8,  'cooldown' => 12],  // 重要人物牺牲/受伤、情感震撼催泪
];

/**
 * 根据小说类型返回描写密度指南
 * 数据来源：《网络小说写作特点分析报告》第三章
 *
 * @param string $genre 小说类型
 * @return string 描写密度配置文本
 */
function getDensityGuidelines(string $genre): string
{
    $matrix = [
        '玄幻' => [
            'scene' => '25%', 'action' => '30%', 'dialogue' => '25%',
            'psychology' => '10%', 'setting' => '10%',
            'tip' => '玄幻类：场景和动作占比高，注重战斗场面和修炼描写的画面感',
        ],
        '仙侠' => [
            'scene' => '25%', 'action' => '30%', 'dialogue' => '25%',
            'psychology' => '10%', 'setting' => '10%',
            'tip' => '仙侠类：同玄幻，增加意境描写和道韵感悟',
        ],
        '都市' => [
            'scene' => '10%', 'action' => '25%', 'dialogue' => '40%',
            'psychology' => '15%', 'setting' => '10%',
            'tip' => '都市类：对话占比最高（40%），注重日常互动和心理活动',
        ],
        '历史' => [
            'scene' => '20%', 'action' => '35%', 'dialogue' => '25%',
            'psychology' => '15%', 'setting' => '5%',
            'tip' => '历史类：动作（战争/权谋）占比最高，注重谋略过程',
        ],
        '武侠' => [
            'scene' => '20%', 'action' => '35%', 'dialogue' => '25%',
            'psychology' => '15%', 'setting' => '5%',
            'tip' => '武侠类：招式拆解细腻，江湖氛围浓厚',
        ],
        '科幻' => [
            'scene' => '20%', 'action' => '20%', 'dialogue' => '30%',
            'psychology' => '15%', 'setting' => '15%',
            'tip' => '科幻类：世界观设定占比高（15%），注重科技逻辑与人文反思，对话推进思想冲突',
        ],
        '悬疑' => [
            'scene' => '20%', 'action' => '15%', 'dialogue' => '35%',
            'psychology' => '25%', 'setting' => '5%',
            'tip' => '悬疑类：心理描写最重（25%），对话藏锋含线索，克制动作场面，气氛渲染优先',
        ],
        '言情' => [
            'scene' => '15%', 'action' => '15%', 'dialogue' => '40%',
            'psychology' => '25%', 'setting' => '5%',
            'tip' => '言情类：对话与心理双高（合计65%），细腻刻画情绪波动与人物关系张力',
        ],
        '轻小说' => [
            'scene' => '15%', 'action' => '25%', 'dialogue' => '40%',
            'psychology' => '15%', 'setting' => '5%',
            'tip' => '轻小说类：对话轻快活泼（40%），节奏快，吐槽与反应描写丰富，避免沉重叙述',
        ],
        '末世' => [
            'scene' => '25%', 'action' => '35%', 'dialogue' => '20%',
            'psychology' => '15%', 'setting' => '5%',
            'tip' => '末世类：动作与场景共同撑起紧张感（60%），展示生存危机与环境破败，对话简短有力',
        ],
        '灵异' => [
            'scene' => '30%', 'action' => '20%', 'dialogue' => '25%',
            'psychology' => '20%', 'setting' => '5%',
            'tip' => '灵异类：场景营造恐怖氛围（30%），心理描写渲染恐惧（20%），节制动作保持神秘感',
        ],
        // 默认配置（通用）
        '_default' => [
            'scene' => '18%', 'action' => '28%', 'dialogue' => '30%',
            'psychology' => '14%', 'setting' => '10%',
            'tip' => '通用配置：均衡分配各元素，保持阅读舒适度',
        ],
    ];

    $key = $genre;
    if (!isset($matrix[$key])) {
        // 模糊匹配
        foreach ($matrix as $k => $v) {
            if ($k !== '_default' && strpos($genre, $k) !== false) { $key = $k; break; }
        }
        if (!isset($matrix[$key])) $key = '_default';
    }

    $d = $matrix[$key];
    return "场景描写{$d['scene']} | 动作描写{$d['action']} | 对话{$d['dialogue']} "
         . "| 心理描写{$d['psychology']} | 设定交代{$d['setting']}\n"
         . "→ {$d['tip']}";
}

/**
 * 根据章节大纲自动推荐钩子类型
 * v2：扩充关键词覆盖 + 防连续同类型（读取前2章已用钩子，强制轮换）
 */
function suggestHookType(array $chapter): array
{
    $outline = $chapter['outline'] ?? '';
    $chNum   = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 1);
    $novelId = (int)($chapter['novel_id'] ?? 0);

    // ── 关键词匹配（优先级从高到低）──
    $matched = null;
    if (preg_match('/战斗|对决|击败|反击|越级|打脸|厮杀|搏斗|迎战|交手|出手|动手|暴揍|碾压|秒杀|强敌|围攻/u', $outline)) {
        $matched = ['type' => 'crisis_interrupt', 'reason' => '检测到战斗/冲突情节'];
    } elseif (preg_match('/背叛|反转|陷阱|阴谋|算计|被坑|被骗|出卖|叛变|真凶|幕后|黑手|暗算/u', $outline)) {
        $matched = ['type' => 'plot_twist', 'reason' => '检测到反转/阴谋元素'];
    } elseif (preg_match('/秘密|发现|真相|身份|揭开|揭秘|隐情|谜底|来历|查明|知晓|得知|原来/u', $outline)) {
        $matched = ['type' => 'info_bomb', 'reason' => '检测到信息揭示情节'];
    } elseif (preg_match('/突破|晋级|晋升|觉醒|升级|进阶|化神|凝核|开窍|天赋|顿悟|蜕变|突飞猛进/u', $outline)) {
        $matched = ['type' => 'upgrade_omen', 'reason' => '检测到成长/突破节点'];
    } elseif (preg_match('/情感|重逢|离别|表白|告白|感情|暗恋|心动|眼神|牵手|拥抱|失去|生死/u', $outline)) {
        $matched = ['type' => 'emotional_impact', 'reason' => '检测到强情感元素'];
    } elseif (preg_match('/真相|揭秘|身份暴露|幕后|原来是|真正的|隐藏的秘密|内幕|真凶现身/u', $outline)) {
        $matched = ['type' => 'truth_reveal', 'reason' => '检测到真相揭露情节'];
    } elseif (preg_match('/绝境|生死关头|孤注一掷|背水一战|最后一战|决一死战|以命相搏|残血|绝地反击/u', $outline)) {
        $matched = ['type' => 'last_stand', 'reason' => '检测到背水一战情节'];
    } elseif (preg_match('/牺牲|殒落|陨落|以死|为了.*死|壮烈|含泪|痛哭|永别|葬礼|遗言/u', $outline)) {
        $matched = ['type' => 'sacrifice', 'reason' => '检测到牺牲/感动情节'];
    } elseif (preg_match('/邀请|地图|任务|消息|信件|线报|情报|委托|指令|契机|机会|宝藏|传闻|招募/u', $outline)) {
        $matched = ['type' => 'new_goal', 'reason' => '检测到新目标/过渡元素'];
    } elseif (preg_match('/危机|险境|包围|困境|灾难|爆炸|崩塌|失控|撤退|逃脱|追杀|生死一线/u', $outline)) {
        $matched = ['type' => 'crisis_interrupt', 'reason' => '检测到危机/险境情节'];
    }

    $types = array_keys(HOOK_TYPES);

    // ── 防连续同类型：读取前2章已用钩子，若匹配结果与近期重复则降级轮换 ──
    $recentHookTypes = [];
    if ($novelId > 0 && $chNum > 1) {
        $lookback = min(2, $chNum - 1);
        $recentChapters = \DB::fetchAll(
            'SELECT hook_type FROM chapters
             WHERE novel_id=? AND chapter_number BETWEEN ? AND ? AND hook_type IS NOT NULL AND hook_type != ""
             ORDER BY chapter_number DESC LIMIT ?',
            [$novelId, $chNum - $lookback, $chNum - 1, $lookback]
        );
        foreach ($recentChapters as $rc) {
            if (!empty($rc['hook_type'])) {
                $recentHookTypes[] = $rc['hook_type'];
            }
        }
    }

    // 若命中结果与前1章相同，尝试找一个不同的关键词类型
    if ($matched && !empty($recentHookTypes) && $recentHookTypes[0] === $matched['type']) {
        // 前两章都一样才强制切换（容忍偶尔相邻相同）
        if (count($recentHookTypes) >= 2 && $recentHookTypes[1] === $matched['type']) {
            $matched = null; // 强制走轮换逻辑
        }
    }

    if ($matched) {
        return $matched;
    }

    // ── 默认轮换：按章号取模，同时跳过最近已用类型 ──
    $defaultIdx = ($chNum - 1) % count($types);
    $candidate  = $types[$defaultIdx];

    // 若候选与最近1章相同，往后移一位
    if (!empty($recentHookTypes) && $recentHookTypes[0] === $candidate) {
        $candidate = $types[($defaultIdx + 1) % count($types)];
    }

    return ['type' => $candidate, 'reason' => '默认轮换（已避开近期重复类型）'];
}

/**
 * 计算本批章节的爽点排期
 *
 * 2026-04-21 优化说明（目标爽点密度 0.8-1.0/章）：
 *   1. 饥饿阈值从 0.8 降至 0.6：冷却期过 60% 即可参选，扩大候选池
 *   2. 支持双爽点/章：候选 ≥ 3 个时，选两个不同类型的爽点（第二个需饥饿度 ≥ 0.8）
 *      - 避免连续章都是双爽点（用 $prevDouble 节制，最多每3章出现一次双爽点章）
 *      - 双爽点原则：主爽点(高分) + 副爽点(次高分，且 type 不同)
 *   3. 输出格式增加 【双爽点】【大爽点】 标记，便于 AI 写章时感知爽点强度
 *   4. 过渡章（无候选时）降低频率：cooldown 压缩后，过渡章比例应 <10%
 *
 * @param int   $startChapter 起始章号
 * @param int   $count        章节数量
 * @param array $history      已有爽点记录 [['chapter'=>N,'type'=>'xxx'], ...]
 * @return string 可读的爽点排期说明
 */
function calculateCoolPointSchedule(int $startChapter, int $count, array $history = []): string
{
    $densityTarget = (float)getSystemSetting('ws_cool_point_density_target', 0.88, 'float');
    if ($densityTarget < 0.3) $densityTarget = 0.3;
    $cooldownMultiplier = 0.88 / $densityTarget;

    $lines      = [];
    $lastUsed   = [];
    $doubleGap  = 0; // 距上次双爽点章的间隔（节制用）

    // 从历史加载上次使用时间
    foreach ($history as $h) {
        $t = $h['type'] ?? '';
        $c = (int)($h['chapter'] ?? 0);
        if ($t && (!isset($lastUsed[$t]) || $c > $lastUsed[$t])) {
            $lastUsed[$t] = $c;
        }
    }

    for ($i = 0; $i < $count; $i++) {
        $chNum = $startChapter + $i;

        // ── 计算每种爽点的饥饿度 ──────────────────────────────────────
        $candidates = [];
        foreach (COOL_POINT_TYPES as $typeId => $cp) {
            $last   = $lastUsed[$typeId] ?? 0;
            $gap    = $chNum - $last;
            $adjustedCooldown = max(1, $cp['cooldown'] * $cooldownMultiplier);
            $hunger = $gap / $adjustedCooldown;

            // v11: 从系统设置读取饥饿阈值（默认 0.6）
            $hungerThreshold = (float)getSystemSetting('ws_cool_point_hunger_threshold', 0.6, 'float');
            if ($hunger >= $hungerThreshold) {
                $score = $cp['weight'] * $hunger;
                $candidates[] = [
                    'type'   => $typeId,
                    'name'   => $cp['name'],
                    'score'  => $score,
                    'hunger' => round($hunger, 2),
                ];
            }
        }

        // 按分数降序排列
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        // ── 防止主爽点与上一章单爽点重复 ────────────────────────────
        $prevType  = $i > 0 ? ($lines[$i - 1]['primary']['type']  ?? '') : '';
        $selected  = $candidates[0] ?? null;
        if ($selected && $selected['type'] === $prevType && count($candidates) > 1) {
            $selected = $candidates[1];
            // 把选中的移到首位（方便后续取第二个）
            $candidates = array_values(array_filter($candidates, fn($c) => $c['type'] !== $selected['type']));
            array_unshift($candidates, $selected);
        }

        // ── 双爽点逻辑 ───────────────────────────────────────────────
        // v11: 从系统设置读取双爽点最小间隔
        $doubleCoolGap = max(1, min(10, (int)getSystemSetting('ws_double_coolpoint_gap', 3, 'int')));
        // 条件：候选数 ≥ 3 AND 距上次双爽点已间隔 ≥ 设定值 章 AND 第二候选饥饿度 ≥ 0.8
        $secondary   = null;
        $doubleGap++;
        if (
            $selected
            && count($candidates) >= 3
            && $doubleGap >= $doubleCoolGap
        ) {
            // 寻找第一个与主爽点不同类型、且饥饿度 ≥ 0.8 的候选
            foreach ($candidates as $cand) {
                if ($cand['type'] !== $selected['type'] && $cand['hunger'] >= 0.8) {
                    $secondary = $cand;
                    break;
                }
            }
            if ($secondary) {
                $doubleGap = 0; // 重置双爽点间隔计数
            }
        }

        // ── 记录结果 ─────────────────────────────────────────────────
        if ($selected) {
            $lines[] = [
                'chapter'   => $chNum,
                'primary'   => $selected,
                'secondary' => $secondary,
            ];
            $lastUsed[$selected['type']] = $chNum;
            if ($secondary) {
                $lastUsed[$secondary['type']] = $chNum;
            }
        } else {
            $lines[] = [
                'chapter'   => $chNum,
                'primary'   => ['type' => 'development', 'name' => '发展铺垫（过渡章）', 'hunger' => 0],
                'secondary' => null,
            ];
        }
    }

    // ── 格式化输出 ────────────────────────────────────────────────────
    $bigTypes = ['underdog_win', 'face_slap'];
    $result   = [];
    foreach ($lines as $l) {
        $pri  = $l['primary'];
        $sec  = $l['secondary'];
        $flag = in_array($pri['type'], $bigTypes) ? '【大爽点】' : '';

        if ($sec) {
            $secFlag = in_array($sec['type'], $bigTypes) ? '【大爽点】' : '';
            $result[] = "  第{$l['chapter']}章 → {$pri['name']}{$flag} + {$sec['name']}{$secFlag} 【双爽点】";
        } else {
            $result[] = "  第{$l['chapter']}章 → {$pri['name']} {$flag}";
        }
    }

    return implode("\n", $result);
}
