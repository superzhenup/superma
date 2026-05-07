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
    ?array $volumeContext = null,  // v7 新增：卷大纲上下文
    array $existingTitles = []    // 全书已有章节标题（防重复）
): array {
    $count = $endChapter - $startChapter + 1;

    // 截断辅助：控制 prompt 长度
    $truncate = fn(string $text, int $limit = 500): string =>
        safe_strlen($text) > $limit ? safe_substr($text, 0, $limit) . '…' : $text;

    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 800);
    $plotSettings    = $truncate($novel['plot_settings']    ?? '', 800);
    $worldSettings   = $truncate($novel['world_settings']   ?? '', 800);
    $extraSettings   = $truncate($novel['extra_settings']   ?? '', 400);

    // ========= 记忆数据来源：MemoryEngine 上下文（优先） =========
    $arcSummaries      = $memoryCtx['L2_arc_summaries'] ?? $memoryCtx['arc_summaries'] ?? [];
    $keyEvents         = $memoryCtx['key_events']            ?? [];
    $characterStates   = $memoryCtx['character_states']      ?? [];
    $pendingForeshadow = $memoryCtx['pending_foreshadowing'] ?? [];
    $storyMomentum     = $memoryCtx['story_momentum']        ?? '';
    $currentArcSummary = $memoryCtx['current_arc_summary']   ?? '';
    $recentHookTypes   = $memoryCtx['recent_hook_types']     ?? [];
    $coolPointHistory  = $memoryCtx['cool_point_history']    ?? [];

    // ========= 伏笔数据一次性加载（逾期伏笔 + 进度统计共用） =========
    $foreshadowAllPending = [];
    $foreshadowAllOverdue = [];
    try {
        require_once dirname(__DIR__) . '/includes/memory/ForeshadowingRepo.php';
        $fRepo = new \ForeshadowingRepo((int)$novel['id']);
        $foreshadowAllPending = $fRepo->listPending();
        $foreshadowAllOverdue = $fRepo->listOverdue($startChapter, 0);
    } catch (\Throwable $e) {
        // ForeshadowingRepo 不可用时静默跳过
    }

    // ========= 场景模板耗尽数据一次性加载 =========
    $stExhausted = [];
    try {
        require_once dirname(__DIR__) . '/includes/memory/SceneTemplateRepo.php';
        $stRepo = new \SceneTemplateRepo((int)$novel['id']);
        $stExhausted = $stRepo->getExhaustedTemplates();
    } catch (\Throwable $e) {
        // SceneTemplateRepo 不可用时静默跳过
    }

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
            $openTip = !empty($oc['opening_type']) ? " [{$oc['opening_type']}式]" : '';
            $emoTip  = '';
            if (isset($oc['emotion_score']) && $oc['emotion_score'] !== null && (float)$oc['emotion_score'] < 60) {
                $emoTip = " ⚠️情绪{$oc['emotion_score']}分";
            }
            $ocNum = (int)($oc['chapter_number'] ?? $oc['chapter'] ?? 0);
            $lines[] = "第{$ocNum}章《{$oc['title']}》：{$summary}{$hookTip}{$openTip}{$emoTip}";
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
            // 扩展属性（境界/等级/能力等）
            $attrs = $state['attributes'] ?? [];
            if (is_array($attrs)) {
                $attrLabels = [
                    'realm' => '境界', 'level' => '等级', 'power' => '战力',
                    'ability' => '能力', 'bloodline' => '血脉', 'treasure' => '法宝',
                ];
                foreach ($attrLabels as $key => $label) {
                    if (!empty($attrs[$key]) && is_scalar($attrs[$key])) $parts[] = "{$label}：{$attrs[$key]}";
                }
                // 其他未命名的属性也展示
                foreach ($attrs as $key => $val) {
                    if (isset($attrLabels[$key]) || $key === 'recent_change') continue;
                    if (is_scalar($val) && !empty($val)) $parts[] = "{$key}：{$val}";
                }
            }
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

    // 当前弧段摘要（从 novel_state）
    $arcSummarySection = $currentArcSummary
        ? "\n【当前弧段摘要】\n{$currentArcSummary}\n"
        : '';

    // 近章钩子类型历史
    $hookHistorySection = '';
    if (!empty($recentHookTypes)) {
        $lines = [];
        foreach (array_slice($recentHookTypes, 0, 5) as $h) {
            $lines[] = "第{$h['chapter']}章：{$h['hook_type']}";
        }
        $hookHistorySection = "\n【近章钩子类型（避免连续重复）】\n" . implode("\n", $lines) . "\n";
    }

    // 爽点类型历史 + 近期禁用列表（强化防重复）
    $coolPointSection = '';
    $recentCoolTypes = [];  // 近3章已用爽点类型（用于铁律禁令）
    if (!empty($coolPointHistory)) {
        // $coolPointHistory 是按章节升序排列的，取最后8条（最近的）
        $recentCoolPoints = array_slice($coolPointHistory, -8);
        $lines = [];
        $totalRecent = count($recentCoolPoints);
        foreach ($recentCoolPoints as $idx => $c) {
            // 最后3条标记为禁用
            $isRecent = $idx >= $totalRecent - 3;
            $mark = $isRecent ? ' 🚫本章禁用' : '';
            $lines[] = "第{$c['chapter']}章：{$c['name']}{$mark}";
            if ($isRecent && !empty($c['type'])) {
                $recentCoolTypes[] = $c['type'];
            }
        }
        $coolPointSection = "\n【近期爽点类型记录】\n" . implode("\n", $lines) . "\n";
    }

    // 构建近期禁用爽点说明（用于铁律）
    $bannedCoolTypesRule = '';
    if (!empty($recentCoolTypes)) {
        $bannedNames = [];
        foreach (array_unique($recentCoolTypes) as $t) {
            $name = COOL_POINT_TYPES[$t]['name'] ?? $t;
            $bannedNames[] = $name;
        }
        if (!empty($bannedNames)) {
            $bannedCoolTypesRule = "12a. 禁止使用近3章已用爽点类型：" . implode('、', $bannedNames) . "（违反则大纲无效）";
        }
    }

    // ── 表达词语防重复：从近章大纲中检测已用词语 ─────────────────────────
    $usedExpressions = [];
    if (!empty($recentOutlines)) {
        foreach ($recentOutlines as $oc) {
            $text = ($oc['title'] ?? '') . ' ' . ($oc['outline'] ?? '');
            foreach (COOL_POINT_EXPRESSIONS as $type => $words) {
                foreach ($words as $w) {
                    if (mb_strpos($text, $w) !== false) {
                        $usedExpressions[$type][] = $w;
                    }
                }
            }
        }
        // 去重
        foreach ($usedExpressions as $type => $words) {
            $usedExpressions[$type] = array_unique($words);
        }
    }

    // 构建表达词库建议（注入 prompt）
    $expressionGuide = '';
    if (!empty($usedExpressions)) {
        $guideLines = [];
        foreach ($usedExpressions as $type => $used) {
            $typeName = COOL_POINT_TYPES[$type]['name'] ?? $type;
            $allWords = COOL_POINT_EXPRESSIONS[$type] ?? [];
            $available = array_diff($allWords, $used);
            if (!empty($used) && !empty($available)) {
                $guideLines[] = "· {$typeName}已用：" . implode('、', $used) . " → 可改用：" . implode('、', array_slice($available, 0, 3));
            }
        }
        if (!empty($guideLines)) {
            $expressionGuide = "\n【表达词语防重复——以下词语已用过，请换词】\n" . implode("\n", $guideLines) . "\n";
        }
    }

    $sceneTemplateSection = '';
    if (!empty($stExhausted)) {
        $stLines = [];
        foreach (array_slice($stExhausted, 0, 8, true) as $tid => $info) {
            $stLines[] = "{$info['name']}({$tid})：已用{$info['use_count']}/{$info['max_uses']}次达上限";
        }
        $sceneTemplateSection = "\n【🚫 已耗尽场景模板（设计大纲时严禁再安排以下模板）】\n"
            . implode("\n", $stLines) . "\n";
    }

    // 已用章节标题黑名单（防重复标题）
    $usedTitlesSection = '';
    if (!empty($existingTitles)) {
        $titleLines = [];
        $total = count($existingTitles);
        if ($total <= 100) {
            foreach ($existingTitles as $ch => $t) {
                $titleLines[] = "第{$ch}章《{$t}》";
            }
        } else {
            // 超过100条时智能截断：最近80条 + 最早20条，中间省略
            $earliest = array_slice($existingTitles, 0, 20, true);
            $latest   = array_slice($existingTitles, -80, 80, true);
            foreach ($earliest as $ch => $t) {
                $titleLines[] = "第{$ch}章《{$t}》";
            }
            $firstKey = array_key_first($latest);
            $titleLines[] = "……（省略第" . (array_key_last($earliest) + 1) . "～" . ($firstKey - 1) . "章标题）……";
            foreach ($latest as $ch => $t) {
                $titleLines[] = "第{$ch}章《{$t}》";
            }
        }
        $usedTitlesSection = "\n【🚫 已用章节标题——新章节的 title 严禁与以下任何一条相同或高度相似（如「初入×宗」和「初入×门」视为相似）】\n"
            . implode("\n", $titleLines) . "\n";
    }

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

        if (!empty($vol['_next_volume_title'])) {
            $volumeSection .= "\n【下卷预告——本批末尾章节须为下卷做铺垫】\n"
                . "第" . ($vol['volume_number'] + 1) . "卷《{$vol['_next_volume_title']}》\n"
                . "主题：{$vol['_next_volume_theme']}  冲突：{$vol['_next_volume_conflict']}\n"
                . "关键事件：{$vol['_next_volume_key_events']}\n";
        }
    }

    // 逾期伏笔强制注入（复用已加载的伏笔数据）
    $overdueSection = '';
    if (!empty($foreshadowAllOverdue)) {
        $overdueLines = [];
        foreach ($foreshadowAllOverdue as $ov) {
            $overdueLines[] = "· 第{$ov['planted_chapter']}章埋、应{$ov['deadline_chapter']}章前回收：{$ov['description']}";
        }
        $overdueSection = "\n【🚨 严重逾期伏笔——必须在本批第1章或第2章内强制回收，不得跳过】\n"
            . implode("\n", $overdueLines) . "\n";
    }

    // 计算本批爽点排期（Phase 1 + v1.6 P1#7: 优先使用 memoryCtx 中的实际历史，其次从 DB 自动加载）
    $coolPointHistory = ($memoryCtx['cool_point_history'] ?? []);
    $coolPointSchedule = calculateCoolPointSchedule($startChapter, $count, $coolPointHistory, (int)$novel['id']);

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

        // 伏笔统计（复用已加载的伏笔数据）
        $pendingForeshadowCount = count($foreshadowAllPending);
        $overdueForeshadowCount = count($foreshadowAllOverdue);
        $pendingList = [];
        $overdueList = [];
        if ($pendingForeshadowCount > 0) {
            $allPending = $foreshadowAllPending;
            usort($allPending, fn($a, $b) => ($a['deadline_chapter'] ?? 99999) <=> ($b['deadline_chapter'] ?? 99999));
            foreach (array_slice($allPending, 0, 5) as $p) {
                $dl = $p['deadline_chapter'] ? "（应第{$p['deadline_chapter']}章前回收）" : '';
                $pendingList[] = "第{$p['planted_chapter']}章埋：{$p['description']}{$dl}";
            }
        }
        foreach ($foreshadowAllOverdue as $ov) {
            $overdueList[] = "第{$ov['planted_chapter']}章埋、应{$ov['deadline_chapter']}章前回收：{$ov['description']}";
        }
        if ($pendingForeshadowCount > 0) {
            $odNote = $overdueForeshadowCount > 0 ? "（其中{$overdueForeshadowCount}条已逾期！）" : '';
            $progressLines[] = "🧵 待回收伏笔：共{$pendingForeshadowCount}条{$odNote}";
            foreach ($pendingList as $f) {
                $progressLines[] = "  · {$f}";
            }
        }

        // F3/F6: 读取全书故事大纲（优先使用调用方预加载的数据，避免重复查询）
        $storyOutline = $novel['_story_outline'] ?? null;
        if (!$storyOutline) {
            try {
                $storyOutline = DB::fetch(
                    'SELECT story_arc, act_division, character_arcs, character_progression, world_evolution, major_turning_points, recurring_motifs
                     FROM story_outlines WHERE novel_id=?',
                    [$novel['id']]
                );
            } catch (\Throwable) {
                try {
                    $storyOutline = DB::fetch(
                        'SELECT story_arc, act_division, character_arcs, world_evolution, major_turning_points, recurring_motifs
                         FROM story_outlines WHERE novel_id=?',
                        [$novel['id']]
                    );
                } catch (\Throwable) {
                    $storyOutline = null;
                }
            }
        }
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

            // 角色等级发展轨迹（全程注入，辅助大纲层合理规划境界晋升）
            $charProgression = json_decode($storyOutline['character_progression'] ?? 'null', true);
            if (!empty($charProgression) && is_array($charProgression)) {
                $storyOutlineSection .= "\n【📈 角色等级发展轨迹——细纲必须按此规划安排境界/等级晋升，禁止跳级】\n";
                foreach ($charProgression as $cName => $cProg) {
                    $ps = $cProg['power_system'] ?? '';
                    $plan = $cProg['progression_plan'] ?? [];
                    if ($ps) $storyOutlineSection .= "{$cName} 修炼体系：{$ps}\n";
                    foreach ($plan as $step) {
                        $stage = $step['stage'] ?? '';
                        $range = $step['chapter_range'] ?? '';
                        $realm = $step['realm'] ?? '';
                        if ($realm) {
                            $storyOutlineSection .= "  · {$range}：{$realm}（{$stage}）\n";
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

            // 全书重复意象（供大纲设计参考）
            $motifs = json_decode($storyOutline['recurring_motifs'] ?? '[]', true) ?: [];
            if (!empty($motifs)) {
                $progressLines[] = "♻️ 全书重复意象（大纲中可安排呼应）：" . implode('、', $motifs);
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

    // F4: 根据进度动态调整 hook_type 规则（v1.5: 九选一，含 truth_reveal/last_stand/sacrifice）
    if ($endChapter >= $targetChapters) {
        // 包含最终章：豁免常规hook规则
        $hookTypeRule = 'hook_type：本批包含最终章。最终章的hook可为空("")或"resolution"(完结收束)，pacing建议"慢"，suspense为"无"，以圆满收束替代悬念结尾。其余章八选一：crisis_interrupt/info_bomb/plot_twist/emotional_impact/upgrade_omen/truth_reveal/last_stand/sacrifice（最终章之前禁用new_goal，不再开启新剧情线），相邻不重复';
    } elseif ($batchProgress >= 0.80 && $endChapter >= $targetChapters - 5) {
        // 倒数5章内：高潮冲刺
        $hookTypeRule = 'hook_type：全书收束高潮期，八选一：crisis_interrupt/info_bomb/plot_twist/emotional_impact/truth_reveal/last_stand/sacrifice/upgrade_omen，禁用new_goal（不再开启新剧情线），优先emotional_impact/plot_twist/truth_reveal/last_stand，相邻不重复';
    } elseif ($batchProgress >= 0.80) {
        // 收束期
        $hookTypeRule = 'hook_type：全书收束期，优先crisis_interrupt/info_bomb/plot_twist/emotional_impact/truth_reveal/last_stand，禁用new_goal（不再开启新剧情线），相邻不重复';
    } else {
        // 正常期
        $hookTypeRule = 'hook_type九选一：crisis_interrupt/info_bomb/plot_twist/new_goal/emotional_impact/upgrade_omen/truth_reveal/last_stand/sacrifice，相邻不重复';
    }

    // 构建设定锚定摘要（确保 AI 始终看到核心设定，不受截断影响）
    $protagonistName = $novel['protagonist_name'] ?? '';
    $anchorLines = [];
    if ($protagonistName) $anchorLines[] = "主角：{$protagonistName}";
    $anchorLines[] = "类型：{$novel['genre']}  风格：{$novel['writing_style']}";
    if ($protagonistInfo) $anchorLines[] = "人设：{$protagonistInfo}";
    if ($plotSettings)    $anchorLines[] = "情节：{$plotSettings}";
    if ($worldSettings)   $anchorLines[] = "世界观：{$worldSettings}";
    $settingAnchor = implode("\n", $anchorLines);
    $protagonistAnchorRule = $protagonistName
        ? "22. 主角名锚定：本小说主角固定为「{$protagonistName}」，大纲中所有涉及主角的描述必须使用此名字，不可更改或替换为其他名字"
        : '';

    $system = <<<EOT
你是专业网文大纲作家。输出纯JSON数组，无前缀后缀无markdown代码块。

【设定锚定——所有情节必须严格依据以下设定，禁止偏离、凭空编造与设定矛盾的内容】
{$settingAnchor}

【铁律】
1. summary≤150字，key_points每条≤30字，hook≤40字
2. 必须输出{$count}个完整对象，不得截断
3. 严禁重复，【禁止已发生关键事件】、标题不得与【已用章节标题】中任何一条相同或高度相似（如「初入×宗」和「初入×门」视为相似），每章标题必须独特
4. 人物职务/身份/处境须与【人物状态】一致
5. 如有【待回收伏笔】，适时安排回收
6. 如有【上章钩子】，本批第一章必须直接承接
7. 每章hook为下章埋具体悬念，禁空洞表述
8. 相邻章有因果关系，前章hook=后章开头触发
9. 如有【故事线回顾】，本批须在其基础上演进，不得矛盾
10. 节奏：{$count}章中≥1"快"≥1"慢"
11. 悬念：≥2章suspense:"有"
12. 爽点排期：{$coolPointSchedule}（相邻章不重复）
{$bannedCoolTypesRule}
12b. 表达词语禁止重复：若【表达词语防重复】中列出已用词语，大纲中不得再用，必须换用建议的替代词
13. {$hookTypeRule}
14. 第1章须前500字展示核心悬念，结尾强烈钩子
15. 如有【本卷写作目标】，本批大纲必须推进至少一条目标，本批最后一章须体现目标进展
16. 如有【严重逾期伏笔】，必须在第1章或第2章的key_points中明确安排回收，违反则大纲无效
17. 如有【本卷必须回收的逾期伏笔】，须在本批内安排完毕，不得拖到下一批
18. 如处于收尾阶段，核心矛盾须获得实质性推进或解决，不再开启新支线
19. 如有【全书故事主线】，本批大纲的情节走向必须与主线严格对齐，不得自行发明新主线或改变故事方向
20. 禁止使用"——"、禁用高频AI词汇，包括但不限于：深邃、凝视、缓缓、蓦然、骤然、 indeed、无疑、显然、事实上、值得注意的是、毫无疑问、通常来说、在此基础上、应当注意到、铁锈味、指节泛白、沉默下来、看了几秒、一愣、沉默了几秒、泛白、愣在原地、愣了一下等。换成更生动的表达，比如"老实说"、"很多时候"、"这么一来"、"这里有个细节"、少用破折号【】以及禁用非对话的""
21. 我发给你的提示词不要出现在文章里面，举例：**章末钩子(info bomb型)：**、【信息爆炸型钩子：检测到信息揭示情节】**高潮段(约700字)**、**(发展段：约600字，对话密集)**、**（铺垫段：约450字）**
{$protagonistAnchorRule}

EOT;

    $user = <<<EOT
为《{$novel['title']}》生成第{$startChapter}～{$endChapter}章大纲（共{$count}章）。

类型：{$novel['genre']}  风格：{$novel['writing_style']}
主角名：{$protagonistName}
主角人设：{$protagonistInfo}
情节：{$plotSettings}
世界观：{$worldSettings}
其他：{$extraSettings}
{$progressSection}{$endingContext}{$storyOutlineSection}{$volumeSection}{$overdueSection}{$volumeForceResolve}{$arcSection}{$contextSection}{$prevHookSection}{$momentumSection}{$arcSummarySection}{$hookHistorySection}{$coolPointSection}{$expressionGuide}{$sceneTemplateSection}{$usedTitlesSection}{$keyEventsSection}{$characterSection}{$foreshadowSection}
输出JSON数组（{$count}个元素）：
[{"chapter_number":整数,"title":"标题","summary":"概要","key_points":["点1","点2"],"hook":"钩子","hook_type":"九选一","pacing":"快/中/慢","suspense":"有/无"},...]

直接输出JSON，从 [ 开始：
EOT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/**
 * 从已有章节反向推导全书故事大纲 Prompt
 * 适用于已有章节内容的情况下，基于已写内容反推出故事框架
 */
function buildStoryOutlineFromChaptersPrompt(array $novel, array $chaptersData): array {
    $truncate = fn(string $text, int $limit = 200): string =>
        safe_strlen($text) > $limit ? safe_substr($text, 0, $limit) . '…' : $text;

    $protagonistInfo = $truncate($novel['protagonist_info'] ?? '', 200);
    $targetChapters  = (int)($novel['target_chapters'] ?? 100);
    $act1End = (int)($targetChapters * 0.2);
    $act2End = (int)($targetChapters * 0.8);

    // 组装已有章节信息（章节号、标题、大纲、摘要、首200字正文）
    $chapterLines = [];
    foreach ($chaptersData as $ch) {
        $num = $ch['chapter_number'] ?? 0;
        $title = $ch['title'] ?? '';
        $outline = $truncate($ch['outline'] ?? '', 100);
        $summary = $truncate($ch['chapter_summary'] ?? '', 100);
        if (!$summary && !$outline) continue;

        $line = "第{$num}章《{$title}》";
        if ($outline) $line .= " | 大纲：{$outline}";
        if ($summary && $summary !== $outline) $line .= " | 摘要：{$summary}";
        $chapterLines[] = $line;

    }

    // 截断：最多取前50章和后20章（避免 token 爆炸）
    $totalChapters = count($chapterLines);
    if ($totalChapters > 70) {
        $head = array_slice($chapterLines, 0, 50);
        $tail = array_slice($chapterLines, -20);
        $tailStart = $totalChapters - 20 + 1;
        $chapterLines = array_merge($head, ["……（省略第51-{$tailStart}章）……"], $tail);
    }
    $chaptersText = implode("\n", $chapterLines);

    // 统计边界
    $completedCount = count($chaptersData);
    $lastChapterNum = $completedCount > 0 ? $chaptersData[count($chaptersData) - 1]['chapter_number'] : 0;
    $remainingChapters = max(0, $targetChapters - $lastChapterNum);

    $system = <<<EOT
你是一位资深的小说编辑，擅长从已完成的内容中反向归纳故事框架。
输出规则（必须严格遵守）：
1. 只输出纯JSON,不要有任何前缀、后缀或markdown代码块
2. story_arc 必须基于已有章节内容归纳，不能凭空编造后续情节
3. act_division 的三幕划分要反映已写内容的实际结构
4. character_progression 的 progression_plan 从现有章节中提取实际发生的境界/等级变化
5. 所有字段值中不得出现未转义的双引号
EOT;

    $user = <<<EOT
请从已有章节反向推导小说《{$novel['title']}》的故事大纲。

【小说基本信息】
书名：{$novel['title']}
类型：{$novel['genre']}
风格：{$novel['writing_style']}
主角：{$novel['protagonist_name']}
主角人设：{$protagonistInfo}
目标总章数：{$targetChapters}章
已写：{$completedCount}章（第1-{$lastChapterNum}章），剩余{$remainingChapters}章

【已有章节信息（大纲+摘要）】
{$chaptersText}

请输出以下格式的JSON（从已有章节反推，不要编造未写的内容）：
{
  "story_arc": "基于已写{$completedCount}章归纳的全书故事主线（200字，剩余章节走向可做合理推断但标注为\"推测\"）",
  "act_division": {
    "act1": {"chapters": "1-{$act1End}", "theme": "开篇主题（从第1-某些章归纳）", "key_events": ["已发生的核心事件1","事件2"], "character_growth": "主角已完成的成长"},
    "act2": {"chapters": "{$act1End}-{$act2End}", "theme": "发展阶段", "key_events": ["已发生事件1","已发生事件2"], "character_growth": "主角本段成长"},
    "act3": {"chapters": "{$act2End}-{$targetChapters}", "theme": "后续主题（根据已有伏笔推测）", "key_events": ["推测事件（标注为推测）"], "character_growth": "预期成长"}
  },
  "major_turning_points": [
    {"chapter": 具体已写章节号, "event": "转折事件（已发生的标注为已写）", "impact": "影响", "volume": 卷号}
  ],
  "character_arcs": {
    "{$novel['protagonist_name']}": {"start": "从第1章提取的初始状态", "midpoint": "从最新章节提取的当前状态", "end": "推测的最终状态"}
  },
  "character_endpoints": "从当前章节推测的人物弧线终点",
  "character_progression": {
    "{$novel['protagonist_name']}": {
      "power_system": "从已有章节中归纳的修炼/等级体系",
      "progression_plan": [
        从已有章节中提取实际发生的境界变化，如 {"stage": "初始", "chapter_range": "1-15", "realm": "炼气期"}; 剩余章节做推测并标注
      ]
    }
  },
  "world_evolution": "从已写内容归纳的世界观演变",
  "recurring_motifs": ["从已写章节提取的重复意象"]
}

重要规则：
1. 所有标记为"推测"的内容必须基于已有章节的伏笔和走向，不能凭空编造
2. character_progression 的 progression_plan 中，已有章节的境界变化必须从实际内容提取
3. major_turning_points 中标注「已写」的事件必须有对应章节号
4. 如果已有章节未揭示境界体系，power_system 填"未明确"，不要编造
直接输出JSON，从 { 开始：
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
    // v1.4: 委托给 ChapterPromptBuilder，保留此函数保证向后兼容
    require_once __DIR__ . '/ChapterPromptBuilder.php';
    $builder = new ChapterPromptBuilder($novel, $chapter, $previousSummary, $previousTail, $memoryCtx);
    return $builder->build();
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

    $protagonistNameSO = $novel['protagonist_name'] ?? '';
    $protagonistRuleSO = $protagonistNameSO
        ? "\n6. 主角名锚定：本小说主角固定为「{$protagonistNameSO}」，character_growth 和所有描述必须使用此名字，不可更改"
        : '';
    $system = <<<EOT
你是一位资深的小说策划师,擅长构建完整的故事框架。
输出规则（必须严格遵守）：
1. 只输出纯JSON,不要有任何前缀、后缀或markdown代码块
2. 确保故事有清晰的开端、发展、高潮、结局
3. 每个幕的主题明确,转折点合理
4. 人物成长轨迹清晰可信
5. 所有字段值中不得出现未转义的双引号{$protagonistRuleSO}
EOT;

    $user = <<<EOT
为小说《{$novel['title']}》设计全书故事大纲。

书名：{$novel['title']}  类型：{$novel['genre']}  风格：{$novel['writing_style']}
主角名：{$novel['protagonist_name']}
主角人设：{$protagonistInfo}
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
  "character_endpoints": "各人物在故事结局时的最终状态（人物弧线终点，如：主角：成长为一代宗师、配角：牺牲自己完成救赎）",
  "character_progression": {
    "{$novel['protagonist_name']}": {
      "power_system": "修炼/等级体系名称（如修仙境界链：炼气→筑基→金丹→元婴→化神；或通用体系：LV.1-100）",
      "progression_plan": [
        {"stage": "初始阶段描述", "chapter_range": "1-章节号", "realm": "当前境界/等级"},
        {"stage": "第一次突破", "chapter_range": "章节号-章节号", "realm": "新境界/等级"},
        {"stage": "中期成长", "chapter_range": "章节号-章节号", "realm": "新境界/等级"},
        {"stage": "后期蜕变", "chapter_range": "章节号-章节号", "realm": "新境界/等级"}
      ]
    }
  },
  "world_evolution": "世界观如何随故事发展演变（50字）",
  "recurring_motifs": ["重复意象1", "重复意象2", "重复意象3"]
}

要求：
1. major_turning_points 每卷至少安排1个转折点，章节号须落在该卷范围内。
2. character_progression 的 progression_plan 必须合理递进，不能跳级（如不能从炼气直接跳到金丹）。
3. 如果小说是修真/玄幻类，power_system 填写修炼境界链，realm 对应具体境界。
4. 如果小说是都市/科幻/历史类，power_system 填写等级体系（如LV体系、军衔体系），realm 对应具体等级。
5. progression_plan 的阶段划分应与 act_division 的三幕结构对齐。
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

    $protagonistNameCS = $novel['protagonist_name'] ?? '';
    $protagonistRuleCS = $protagonistNameCS
        ? "\n6. 主角名锚定：本小说主角固定为「{$protagonistNameCS}」，characters 列表中必须包含此名字且不可更改"
        : '';
    $system = <<<EOT
你是一位小说场景设计师,擅长将章节大纲细化为可执行的写作蓝图。
输出规则（必须严格遵守）：
1. 只输出纯JSON,不要有任何前缀、后缀或markdown代码块
2. 场景分解要具体,有画面感
3. 对话要点要符合人物性格
4. 感官细节要丰富,有代入感
5. 章节逻辑要合理经得起推敲，并且章节不要重复
6. 所有字段值中不得出现未转义的双引号{$protagonistRuleCS}
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
主角名：{$novel['protagonist_name']}
主角人设：{$protagonistInfo}
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
        $lines[] = '2. hook_type 豁免常规九选一，使用"resolution"收束型结尾';
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
    'truth_reveal'      => [
        'name'     => '真相揭露型',
        'desc'     => '阴谋败露 / 真凶现身 / 隐藏身份曝光',
        'usage'    => '10%，适合悬疑解谜章',
        'template' => '原来幕后的人是……/他终于看到了真相……',
    ],
    'last_stand'        => [
        'name'     => '背水一战型',
        'desc'     => '绝境反击 / 孤注一掷 / 破釜沉舟',
        'usage'    => '7%，最高热血值',
        'template' => '已经没有退路了……/要么赢，要么死……',
    ],
    'sacrifice'         => [
        'name'     => '牺牲感动型',
        'desc'     => '重要角色舍身 / 以命相救 / 壮烈成仁',
        'usage'    => '5%，最催泪',
        'template' => '他挡在了前面……/为了你，我愿意……',
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
    'truth_reveal'  => ['name' => '真相揭露',     'weight' => 14, 'cooldown' => 8],
    'last_stand'    => ['name' => '背水一战',     'weight' => 13, 'cooldown' => 6],
    'sacrifice'     => ['name' => '牺牲感动',     'weight' => 8,  'cooldown' => 12],
];

/**
 * 爽点类型表达词库：同一种爽点可以用不同词语表达，避免重复
 * 用法：当某个类型用过一次后，下次必须从剩余词库中选择
 */
const COOL_POINT_EXPRESSIONS = [
    'underdog_win'  => ['越级战斗胜利', '以弱胜强', '逆袭翻盘', '实力碾压', '一招制敌', '力挽狂澜'],
    'face_slap'     => ['打脸反转', '当众打脸', '实力打脸', '无声打脸', '借势打脸', '疯狂打脸', '狠狠打脸'],
    'treasure_find' => ['宝物/奇遇', '意外收获', '天降机缘', '神秘传承', '上古秘宝', '意外所得', '意外之喜'],
    'breakthrough'  => ['修为突破', '境界跃升', '实力暴涨', '功法大成', '顿悟突破', '蜕变进化', '脱胎换骨'],
    'power_expand'  => ['势力扩张', '势力壮大', '招兵买马', '地盘扩张', '建立势力', '一统一方', '威名远播'],
    'romance_win'   => ['红颜倾心', '美人青睐', '情愫暗生', '红颜相伴', '情感升温', '佳人相伴', '红颜知己'],
    'truth_reveal'  => ['真相揭露', '阴谋败露', '真相大白', '惊天秘密', '秘密揭开', '内幕曝光', '隐秘浮现'],
    'last_stand'    => ['背水一战', '命悬一线', '孤注一掷', '破釜沉舟', '绝地求生', '生死一线', '置之死地', '悬崖勒马', '困兽之斗'],
    'sacrifice'     => ['牺牲感动', '舍己救人', '自我牺牲', '大义凛然', '舍生取义', '以命换命', '血性担当'],
];

/**
 * 场景模板库：每种爽点类型对应多种执行模板
 * 用于语义级防重复——同类爽点不重复使用相同的执行方式
 *
 * max_uses: 全书该模板最多使用次数（0=不限）
 * cooldown: 与同一模板上次使用的最小间隔（章）
 * keywords: 用于从正文中检测该模板的关键词（命中一个即算）
 */
const SCENE_TEMPLATES = [
    // ── underdog_win 越级战斗胜利 ──
    'underdog_counter'     => ['cool_type' => 'underdog_win', 'name' => '绝地反击',
        'max_uses' => 5, 'cooldown' => 8,
        'keywords' => ['绝地反击', '绝境反杀', '拼死一搏', '最后一击', '最后一搏', '绝境中爆发', '死里逃生', '垂死挣扎']],
    'underdog_hidden_power' => ['cool_type' => 'underdog_win', 'name' => '隐藏实力',
        'max_uses' => 4, 'cooldown' => 10,
        'keywords' => ['隐藏实力', '深藏不露', '扮猪吃虎', '假装弱势', '实力深不可测', '一直在隐藏', '真正的实力']],
    'underdog_smart_win'   => ['cool_type' => 'underdog_win', 'name' => '智取胜',
        'max_uses' => 5, 'cooldown' => 8,
        'keywords' => ['以智取胜', '智取', '利用弱点', '设下陷阱', '瓮中捉鳖', '暗算', '中了埋伏', '请君入瓮']],
    'underdog_desperate_break' => ['cool_type' => 'underdog_win', 'name' => '绝境突破',
        'max_uses' => 4, 'cooldown' => 12,
        'keywords' => ['绝境中突破', '走火入魔边缘', '燃烧精血', '以命换命', '透支', '燃烧寿元', '自爆', '碎丹', '燃烧血脉']],

    // ── face_slap 打脸反转 ──
    'face_slap_humiliation' => ['cool_type' => 'face_slap', 'name' => '当众羞辱反杀',
        'max_uses' => 5, 'cooldown' => 6,
        'keywords' => ['当众羞辱', '嘲笑', '看不起', '不屑', '蝼蚁', '不配', '自取其辱', '颜面扫地', '无地自容']],
    'face_slap_silent_show' => ['cool_type' => 'face_slap', 'name' => '无声展示实力',
        'max_uses' => 4, 'cooldown' => 8,
        'keywords' => ['一言不发', '直接出手', '随手', '轻描淡写', '不以为意', '淡淡', '不屑一顾', '连看都没看']],
    'face_slap_evidence'    => ['cool_type' => 'face_slap', 'name' => '铁证打脸',
        'max_uses' => 4, 'cooldown' => 8,
        'keywords' => ['拿出证据', '铁证', '事实摆在面前', '当场揭穿', '谎言', '骗不了', '原形毕露', '真相大白']],
    'face_slap_spectator_shock' => ['cool_type' => 'face_slap', 'name' => '观众震惊',
        'max_uses' => 5, 'cooldown' => 6,
        'keywords' => ['震惊全场', '全场哗然', '所有人震惊', '不敢相信', '目瞪口呆', '下巴', '怎么可能', '这不可能']],

    // ── treasure_find 宝物/奇遇 ──
    'treasure_secret_realm' => ['cool_type' => 'treasure_find', 'name' => '秘境探宝',
        'max_uses' => 4, 'cooldown' => 10,
        'keywords' => ['秘境', '秘藏', '古墓', '遗迹', '密室', '暗道', '地下', '洞府', '禁地']],
    'treasure_inheritance'  => ['cool_type' => 'treasure_find', 'name' => '传承获得',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['传承', '传承记忆', '功法传承', '远古传承', '大能传承', '上古', '血脉觉醒', '天赋神通']],
    'treasure_auction'      => ['cool_type' => 'treasure_find', 'name' => '拍卖/竞争获宝',
        'max_uses' => 3, 'cooldown' => 12,
        'keywords' => ['拍卖', '竞价', '争夺', '竞拍', '天价', '价高者得', '豪掷', '一掷千金']],
    'treasure_accidental'   => ['cool_type' => 'treasure_find', 'name' => '意外收获',
        'max_uses' => 4, 'cooldown' => 10,
        'keywords' => ['意外发现', '偶然获得', '不经意', '误入', '不小心', '捡到', '误触', '无意中']],

    // ── breakthrough 修为突破 ──
    'breakthrough_battle'    => ['cool_type' => 'breakthrough', 'name' => '战斗中突破',
        'max_uses' => 5, 'cooldown' => 8,
        'keywords' => ['战斗中突破', '战场上', '生死之间', '激战中', '对战中突破', '边打边突破']],
    'breakthrough_epiphany'  => ['cool_type' => 'breakthrough', 'name' => '顿悟突破',
        'max_uses' => 4, 'cooldown' => 12,
        'keywords' => ['顿悟', '感悟', '参悟', '悟道', '天地共鸣', '心有所感', '灵光一闪', '豁然开朗']],
    'breakthrough_pill'      => ['cool_type' => 'breakthrough', 'name' => '丹药/外力辅助突破',
        'max_uses' => 3, 'cooldown' => 12,
        'keywords' => ['丹药', '灵丹', '突破丹', '灵药', '天材地宝', '服下', '炼化', '吸收']],
    'breakthrough_life_death' => ['cool_type' => 'breakthrough', 'name' => '生死关突破',
        'max_uses' => 4, 'cooldown' => 15,
        'keywords' => ['生死关', '生死之间', '死亡边缘', '濒死', '九死一生', '置之死地', '破而后立', '死中求活']],

    // ── power_expand 势力扩张 ──
    'power_defeat_leader'   => ['cool_type' => 'power_expand', 'name' => '击败首领收服',
        'max_uses' => 4, 'cooldown' => 10,
        'keywords' => ['击败首领', '打败头目', '降服', '收服', '臣服', '跪下', '归顺', '效忠']],
    'power_alliance'        => ['cool_type' => 'power_expand', 'name' => '结盟壮大',
        'max_uses' => 4, 'cooldown' => 10,
        'keywords' => ['结盟', '联手', '联盟', '合作', '同盟', '合力', '携手']],
    'power_takeover'        => ['cool_type' => 'power_expand', 'name' => '接管势力',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['接管', '取代', '自立', '开宗立派', '建势力', '成立', '创建门派', '称霸']],
    'power_defection'       => ['cool_type' => 'power_expand', 'name' => '敌人倒戈',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['倒戈', '叛变', '投靠', '转投', '弃暗投明', '改邪归正', '反水']],

    // ── romance_win 红颜倾心 ──
    'romance_rescue'        => ['cool_type' => 'romance_win', 'name' => '英雄救美',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['英雄救美', '救下', '挡在身前', '保护', '替她挡', '护在身后']],
    'romance_confession'    => ['cool_type' => 'romance_win', 'name' => '表白/告白',
        'max_uses' => 3, 'cooldown' => 20,
        'keywords' => ['表白', '告白', '喜欢你', '我的心', '对你说', '心意', '倾诉']],
    'romance_sacrifice_love' => ['cool_type' => 'romance_win', 'name' => '为爱牺牲/成全',
        'max_uses' => 2, 'cooldown' => 25,
        'keywords' => ['为你死', '替你死', '不后悔', '只要你活着', '离开你', '放手', '成全']],
    'romance_reunion'       => ['cool_type' => 'romance_win', 'name' => '重逢',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['重逢', '再次相见', '多年后', '终于找到', '久别重逢', '再见面', '相认']],

    // ── truth_reveal 真相揭露 ──
    'truth_identity'        => ['cool_type' => 'truth_reveal', 'name' => '身份揭开',
        'max_uses' => 4, 'cooldown' => 12,
        'keywords' => ['真实身份', '身份揭开', '原来他就是', '竟然是', '真实面目', '真名', '隐藏身份']],
    'truth_conspiracy'      => ['cool_type' => 'truth_reveal', 'name' => '阴谋真相',
        'max_uses' => 4, 'cooldown' => 12,
        'keywords' => ['阴谋', '幕后黑手', '真相是', '一切都是', '策划', '布局', '棋局', '幕后']],
    'truth_world_secret'    => ['cool_type' => 'truth_reveal', 'name' => '世界秘密',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['世界的秘密', '天道', '真相', '创世', '远古秘辛', '历史真相', '万年', '天地大秘']],
    'truth_betrayal_truth'  => ['cool_type' => 'truth_reveal', 'name' => '背叛真相',
        'max_uses' => 3, 'cooldown' => 12,
        'keywords' => ['原来', '真相竟然是', '骗了', '一直被蒙在鼓里', '全是假的', '欺骗', '利用']],

    // ── last_stand 背水一战 ──
    'last_stand_defense'    => ['cool_type' => 'last_stand', 'name' => '死守据点',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['死守', '绝不后退', '守护', '阵地', '城墙', '最后防线', '守住', '誓死']],
    'last_stand_counter'    => ['cool_type' => 'last_stand', 'name' => '最后一击反杀',
        'max_uses' => 3, 'cooldown' => 12,
        'keywords' => ['最后一口气', '最后一击', '拼死一搏', '最后一招', '最后一剑', '最后的力量', '最后的底牌']],
    'last_stand_sacrifice_cover' => ['cool_type' => 'last_stand', 'name' => '牺牲掩护',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['用身体挡住', '替他挡', '以身为盾', '挡在前面', '护住', '以命相护']],
    'last_stand_one_vs_many' => ['cool_type' => 'last_stand', 'name' => '以一敌多',
        'max_uses' => 3, 'cooldown' => 12,
        'keywords' => ['以一敌百', '一人对抗', '独战', '单枪匹马', '以一己之力', '一人之力', '力战群雄']],
    'last_stand_power_awakening' => ['cool_type' => 'last_stand', 'name' => '绝境觉醒',
        'max_uses' => 3, 'cooldown' => 15,
        'keywords' => ['绝境中觉醒', '苏醒', '沉睡的力量', '封印解开', '解封', '觉醒', '远古血脉', '隐藏力量觉醒']],

    // ── sacrifice 牺牲感动 ──
    'sacrifice_mentor'      => ['cool_type' => 'sacrifice', 'name' => '师父/长辈牺牲',
        'max_uses' => 2, 'cooldown' => 25,
        'keywords' => ['师父', '前辈', '长辈', '为了你', '死得其所', '无怨无悔', '遗言', '最后一课']],
    'sacrifice_friend'      => ['cool_type' => 'sacrifice', 'name' => '挚友牺牲',
        'max_uses' => 2, 'cooldown' => 20,
        'keywords' => ['兄弟', '挚友', '伙伴', '战友', '来世', '下辈子', '不要忘记', '替我活下去']],
    'sacrifice_self_hero'   => ['cool_type' => 'sacrifice', 'name' => '主角自我牺牲',
        'max_uses' => 2, 'cooldown' => 30,
        'keywords' => ['主角牺牲', '以命换', '燃烧一切', '燃烧生命', '拼上一切', '豁出性命', '与敌同归']],
    'sacrifice_loved_one'   => ['cool_type' => 'sacrifice', 'name' => '爱人牺牲',
        'max_uses' => 1, 'cooldown' => 40,
        'keywords' => ['她死了', '她闭上了眼', '怀中', '气息消散', '最后一笑', '诀别', '永远离开']],
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
    // 注意：truth_reveal 必须在 info_bomb 之前，否则"真相/揭秘"等词会被 info_bomb 先吞
    $matched = null;
    if (preg_match('/战斗|对决|击败|反击|越级|打脸|厮杀|搏斗|迎战|交手|出手|动手|暴揍|碾压|秒杀|强敌|围攻/u', $outline)) {
        $matched = ['type' => 'crisis_interrupt', 'reason' => '检测到战斗/冲突情节'];
    } elseif (preg_match('/背叛|反转|陷阱|阴谋|算计|被坑|被骗|出卖|叛变|真凶|幕后|黑手|暗算/u', $outline)) {
        $matched = ['type' => 'plot_twist', 'reason' => '检测到反转/阴谋元素'];
    } elseif (preg_match('/真相|揭秘|身份暴露|幕后|原来是|真正的|隐藏的秘密|内幕|真凶现身|阴谋败露|大白于天下|真面目|揭穿|水落石出/u', $outline)) {
        $matched = ['type' => 'truth_reveal', 'reason' => '检测到真相揭露情节'];
    } elseif (preg_match('/秘密|发现|身份|揭开|隐情|谜底|来历|查明|知晓|得知|预言|应验|惊天秘密/u', $outline)) {
        $matched = ['type' => 'info_bomb', 'reason' => '检测到信息揭示情节'];
    } elseif (preg_match('/突破|晋级|晋升|觉醒|升级|进阶|化神|凝核|开窍|天赋|顿悟|蜕变|突飞猛进/u', $outline)) {
        $matched = ['type' => 'upgrade_omen', 'reason' => '检测到成长/突破节点'];
    } elseif (preg_match('/情感|重逢|离别|表白|告白|感情|暗恋|心动|眼神|牵手|拥抱|失去|生死/u', $outline)) {
        $matched = ['type' => 'emotional_impact', 'reason' => '检测到强情感元素'];
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

    // ── 默认轮换：使用优化的轮换公式，确保分布均匀 ──
    // 避免章节1永远是 crisis_interrupt（数组第一个元素）
    // 从索引1开始（跳过 crisis_interrupt 作为默认首选项）
    $defaultIdx = $chNum % count($types);
    $candidate = $types[$defaultIdx];

    // 若候选与最近1章相同，往后移一位
    if (!empty($recentHookTypes) && $recentHookTypes[0] === $candidate) {
        $candidate = $types[($defaultIdx + 1) % count($types)];
    }

    return ['type' => $candidate, 'reason' => '默认轮换（已避开近期重复类型）'];
}

/**
 * 开篇五式定义
 * 用于自动识别章节开篇风格，辅助 AI 保持风格多样性
 */
const OPENING_TYPES = [
    'action'   => [
        'name'     => '动作开篇',
        'desc'     => '以战斗/追逐/紧张动作场景开场',
        'keywords' => '战斗|对决|出手|厮杀|搏斗|追杀|逃亡|暴起|突袭|反击|迎战|交锋',
    ],
    'dialogue' => [
        'name'     => '对话开篇',
        'desc'     => '以对话/台词/争论引入情节',
        'keywords' => '说道|喊道|怒喝|冷笑|质问|回答|低声道|沉声道|开口|出声|喝道|问道',
    ],
    'mystery'  => [
        'name'     => '悬念开篇',
        'desc'     => '以谜团/疑问/诡异氛围引入',
        'keywords' => '奇怪|诡异|神秘|不解|疑惑|蹊跷|异常|离奇|古怪|莫名|不知|暗中',
    ],
    'scene'    => [
        'name'     => '场景开篇',
        'desc'     => '以环境描写/场景铺陈开场',
        'keywords' => '阳光|月色|星空|山峰|山谷|宫殿|密林|深渊|废墟|古城|天地|苍穹|云海|晨曦|暮色',
    ],
    'emotion'  => [
        'name'     => '情感开篇',
        'desc'     => '以内心独白/情感渲染引入',
        'keywords' => '心中|内心|暗想|回忆|感慨|悲痛|愤怒|不甘|释然|觉悟|感悟|叹息|暗叹',
    ],
];

/**
 * 根据章节大纲自动推荐开篇类型
 * 与 suggestHookType 同模式：关键词匹配 + 防连续重复
 */
function suggestOpeningType(array $chapter): array
{
    $outline = $chapter['outline'] ?? '';
    $chNum   = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 1);
    $novelId = (int)($chapter['novel_id'] ?? 0);

    // ── 关键词匹配 ──
    $matched = null;
    foreach (OPENING_TYPES as $type => $def) {
        if (preg_match('/' . $def['keywords'] . '/u', $outline)) {
            $matched = ['type' => $type, 'reason' => "检测到{$def['name']}元素"];
            break;
        }
    }

    $types = array_keys(OPENING_TYPES);

    // ── 防连续同类型：读取前2章已用开篇类型 ──
    $recentTypes = [];
    if ($novelId > 0 && $chNum > 1) {
        $lookback = min(2, $chNum - 1);
        $recentChapters = \DB::fetchAll(
            'SELECT opening_type FROM chapters
             WHERE novel_id=? AND chapter_number BETWEEN ? AND ? AND opening_type IS NOT NULL AND opening_type != ""
             ORDER BY chapter_number DESC LIMIT ?',
            [$novelId, $chNum - $lookback, $chNum - 1, $lookback]
        );
        foreach ($recentChapters as $rc) {
            if (!empty($rc['opening_type'])) {
                $recentTypes[] = $rc['opening_type'];
            }
        }
    }

    // 若命中结果与前2章都相同，强制轮换
    if ($matched && !empty($recentTypes) && $recentTypes[0] === $matched['type']) {
        if (count($recentTypes) >= 2 && $recentTypes[1] === $matched['type']) {
            $matched = null;
        }
    }

    if ($matched) {
        return $matched;
    }

    // ── 默认轮换：按章号取模，跳过最近已用类型 ──
    $defaultIdx = ($chNum - 1) % count($types);
    $candidate  = $types[$defaultIdx];

    if (!empty($recentTypes) && $recentTypes[0] === $candidate) {
        $candidate = $types[($defaultIdx + 1) % count($types)];
    }

    return ['type' => $candidate, 'reason' => '默认轮换（已避开近期重复类型）'];
}

/**
 * 检测正文实际使用的开篇类型（与 suggestOpeningType 建议对比，可发现 AI 偏离）
 * 用于 postProcess 阶段将检测结果写入 chapters.actual_opening_type 字段
 *
 * @param string $content 章节正文
 * @return array ['type' => string, 'reason' => string]|empty
 */
function detectOpeningType(string $content): array
{
    if (empty(trim($content))) return [];

    $firstParagraph = preg_split('/[\r\n]+/u', trim($content));
    $firstParagraph = $firstParagraph[0] ?? '';

    if (empty($firstParagraph)) return [];

    $first200 = mb_substr($firstParagraph, 0, 200);

    $matched = null;
    foreach (OPENING_TYPES as $type => $def) {
        if (preg_match('/' . $def['keywords'] . '/u', $first200)) {
            $matched = ['type' => $type, 'reason' => "检测到{$def['name']}元素"];
            break;
        }
    }

    if ($matched) return $matched;

    return [];
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
 * @param int      $startChapter 起始章号
 * @param int      $count        章节数量
 * @param array    $history      已有爽点记录 [['chapter'=>N,'type'=>'xxx'], ...]
 * @param int|null $novelId      小说ID（v1.6: 用于自动加载实际爽点历史）
 * @return string 可读的爽点排期说明
 */
function calculateCoolPointSchedule(int $startChapter, int $count, array $history = [], ?int $novelId = null): string
{
    // v1.6 P1#7: 当未传入历史时，自动从 chapters 表读取实际检测到的爽点类型
    // 这解决了"排期器只看计划不看实际"的反馈缺失问题
    if (empty($history) && $novelId !== null) {
        try {
            $actualRows = \DB::fetchAll(
                'SELECT chapter_number, actual_cool_point_types FROM chapters
                 WHERE novel_id=? AND chapter_number < ? AND actual_cool_point_types IS NOT NULL
                 ORDER BY chapter_number ASC',
                [$novelId, $startChapter]
            );
            foreach ($actualRows as $row) {
                $types = json_decode($row['actual_cool_point_types'] ?? '[]', true) ?: [];
                foreach ($types as $t) {
                    if (is_string($t) && !empty($t)) {
                        $history[] = [
                            'chapter' => (int)$row['chapter_number'],
                            'type'    => $t,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // 自动加载失败不影响排期，使用空历史继续
        }
    }

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

// ================================================================
// v1.6 P1#7: 爽点实际类型识别——关键词匹配映射
// 修复反馈缺失：之前 calculateCoolPointSchedule 的 lastUsed 只记录"计划排期"
// 而非 AI 实际写到的类型，导致排期器无法学习真实行为
// ================================================================

/**
 * 9 种爽点类型的关键词映射
 * 用于在章节正文中检测 AI 实际写到的爽点类型
 */
const COOL_POINT_KEYWORDS = [
    'underdog_win'  => ['越级', '以弱胜强', '跨境界', '逆袭击败', '低境击败', '跨境击杀',
                        '越阶', '跨境', '不可思议地击败', '跨级秒杀', '碾压高阶',
                        '以下犯上', '低阶击败高阶', '弱者赢', '蝼蚁撼大树'],
    'face_slap'     => ['打脸', '啪啪打脸', '当场打脸', '狠狠打脸', '被羞辱',
                        '啪啪响', '被打脸', '自取其辱', '自取其咎', '颜面扫地',
                        '脸色铁青', '脸色苍白', '脸色一变', '尴尬', '下不了台',
                        '哑口无言', '无地自容', '脸色难看', '气得发抖'],
    'treasure_find' => ['宝物', '奇遇', '机缘', '秘境', '传承', '异宝',
                        '天材地宝', '神兵', '灵宝', '造化', '机缘巧合',
                        '获得', '到手', '收获颇丰', '满载而归', '入手'],
    'breakthrough'  => ['突破', '晋级', '晋升', '境界突破', '修为突破', '瓶颈突破',
                        '蜕变', '进阶', '破境', '修为暴涨', '力量暴涨',
                        '更上一层', '迈入', '踏入', '气息暴涨', '实力大增'],
    'power_expand'  => ['势力扩张', '吞并', '收服', '联盟', '招揽', '壮大势力',
                        '势力', '扩张版图', '建立势力', '收编', '归顺',
                        '麾下', '臣服', '效忠', '投靠', '收为'],
    'romance_win'   => ['红颜', '倾心', '倾慕', '芳心', '情愫', '钟情',
                        '爱慕', '心动', '脸红', '亲密接触', '以身相许', '暗生情愫',
                        '面红耳赤', '心跳加速', '怦然心动', '含情脉脉', '深情对望'],
    'truth_reveal'  => ['真相', '揭露', '秘密曝光', '身份揭晓', '幕后黑手',
                        '原来是', '竟然是他', '惊天秘密', '真相大白', '水落石出',
                        '真面目', '揭开', '原来如此', '幕后真凶', '大白于天下'],
    'last_stand'    => ['背水一战', '绝境', '孤注一掷', '拼死', '舍命',
                        '最后一击', '同归于尽', '死战', '绝地', '别无选择',
                        '生死关头', '九死一生', '险象环生', '绝处', '殊死搏斗'],
    'sacrifice'     => ['牺牲', '舍身', '献身', '以命相救', '用生命',
                        '以身挡', '赴死', '为救', '舍己', '用身体挡住',
                        '挡在前面', '替死', '以命换命', '挡下', '挺身而出'],
];

/**
 * 从章节正文中检测实际写到的爽点类型
 *
 * v1.5.2 改进：
 * - 关键词扩充（每类增加 5 个场景化短语，总词数从 90 → 135）
 * - LLM 已判定的 cool_point_type 兜底（关键词 0 命中时回退）
 *
 * @param string $content 章节正文（去除段落标记后）
 * @param string|null $llmJudgedType LLM summary 给出的爽点类型（兜底用）
 * @return array 检测到的爽点类型数组 ['underdog_win', 'face_slap', ...]
 */
function detectCoolPointTypes(string $content, ?string $llmJudgedType = null): array
{
    $detected = [];
    foreach (COOL_POINT_KEYWORDS as $typeId => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_strpos($content, $kw) !== false) {
                $detected[] = $typeId;
                break; // 命中一个关键词即可
            }
        }
    }
    $detected = array_unique($detected);

    // 兜底：关键词全没命中时，用 LLM 判定的类型作为唯一结果
    // 关键词词典再大也无法覆盖所有写法，LLM 看完整章的语义判断更可靠
    if (empty($detected) && $llmJudgedType && isset(COOL_POINT_KEYWORDS[$llmJudgedType])) {
        $detected[] = $llmJudgedType;
    }

    return $detected;
}

/**
 * 从章节正文中检测实际使用的场景模板
 * 用于语义级防重复——追踪每种爽点类型的具体执行方式
 *
 * @param string $content 章节正文
 * @return array 检测到的模板ID数组 ['last_stand_counter', 'face_slap_humiliation', ...]
 */
function detectSceneTemplates(string $content): array
{
    $detected = [];
    foreach (SCENE_TEMPLATES as $templateId => $tpl) {
        foreach ($tpl['keywords'] as $kw) {
            if (mb_strpos($content, $kw) !== false) {
                $detected[] = $templateId;
                break;
            }
        }
    }
    return array_values(array_unique($detected));
}
