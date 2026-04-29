<?php
/**
 * WriteEngine — 章节写作核心引擎
 * 提取 write_chapter.php 和 write_chapter_worker.php 的共享逻辑，
 * 通过回调注入实现 SSE / 进度文件 两种 I/O 模式的解耦。
 *
 * 6 个阶段：
 *   1. resolveChapter() — 解析待写章节 + 僵死任务清理
 *   2. initMemory()     — 初始化记忆引擎 + 语义召回
 *   3. buildPrompt()    — 组装 AI 写作 prompt
 *   4. streamWrite()    — 带模型回退的流式写作
 *   5. saveChapter()    — 落盘正文 + 版本备份
 *   6. postProcess()    — 摘要/记忆/知识库/质检
 */

defined('APP_LOADED') or die('Direct access denied.');

class WriteEngine
{
    /**
     * Phase 1: 解析待写章节（含僵死 writing 状态清理 + Agent决策）
     * @return array{n: array, ch: array}
     * @throws RuntimeException
     */
    public static function resolveChapter(int $novelId, ?int $chapterId = null): array
    {
        // Agent决策：在写作前运行Agent决策流程
        self::runPreWriteAgents($novelId);
        
        $novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
        if (!$novel) throw new RuntimeException('小说不存在');

        $ch = $chapterId
            ? DB::fetch('SELECT * FROM chapters WHERE id=? AND novel_id=?', [$chapterId, $novelId])
            : DB::fetch(
                'SELECT * FROM chapters WHERE novel_id=? AND status IN ("outlined","skipped") ORDER BY chapter_number ASC LIMIT 1',
                [$novelId]
            );

        // 僵死 writing → outlined
        if ($ch && $ch['status'] === 'writing') {
            DB::update('chapters', ['status' => 'outlined'], 'id=?', [$ch['id']]);
            $ch['status'] = 'outlined';
            addLog($novelId, 'info', "第{$ch['chapter_number']}章状态从 writing 重置为 outlined（上次中断）");
        }

        // 未指定章节时，清理所有僵死 writing 章节
        if (!$chapterId) {
            $stuck = DB::fetchAll(
                'SELECT id, chapter_number FROM chapters WHERE novel_id=? AND status="writing"', [$novelId]
            );
            foreach ($stuck as $s) {
                DB::update('chapters', ['status' => 'outlined'], 'id=?', [$s['id']]);
                addLog($novelId, 'info', "第{$s['chapter_number']}章重置为 outlined（僵死清理）");
            }
            DB::update('novels', ['status' => 'draft'], 'id=? AND status=?', [$novelId, 'writing']);
            if (!$ch || $ch['status'] !== 'outlined') {
                $ch = DB::fetch(
                    'SELECT * FROM chapters WHERE novel_id=? AND status IN ("outlined","skipped") ORDER BY chapter_number ASC LIMIT 1',
                    [$novelId]
                );
            }
        }

        if (!$ch) throw new RuntimeException('没有待写章节，请先生成大纲。');

        // 事务包裹：取消标志清零 + 章节置 writing + 小说置 writing 必须原子执行
        // 注意：unlink 文件清理不在事务内——文件不存在不应对事务产生影响
        $flagFile = BASE_PATH . "/storage/write_cancel_{$novelId}.flag";
        if (file_exists($flagFile)) {
            @unlink($flagFile);
        }

        $pdo = DB::connect();
        $pdo->beginTransaction();
        try {
            DB::update('novels',   ['cancel_flag' => 0], 'id=?', [$novelId]);
            DB::update('chapters', ['status' => 'writing'], 'id=?', [$ch['id']]);
            DB::update('novels',   ['status' => 'writing'], 'id=?', [$novelId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['n' => $novel, 'ch' => $ch];
    }

    /**
     * Phase 2: 初始化记忆引擎 + 语义召回
     * @return array{engine: MemoryEngine, memoryCtx: ?array}
     */
    public static function initMemory(int $novelId, array $chapter): array
    {
        require_once __DIR__ . '/memory/MemoryEngine.php';
        $engine = new MemoryEngine($novelId);

        try { $engine->ensureEmbeddings(30); }
        catch (Throwable $e) { addLog($novelId, 'warn', 'ensureEmbeddings 失败：' . $e->getMessage()); }

        $queryText = trim(($chapter['title'] ?? '') . '：' . ($chapter['outline'] ?? ''));
        $semanticTopK = max(1, min(20, (int)getSystemSetting('ws_embedding_top_k', 5, 'int')));

        try {
            $memoryCtx = $engine->getPromptContext((int)$chapter['chapter_number'], $queryText, CFG_MEMORY_TOKEN_BUDGET, 20, $semanticTopK);
        } catch (Throwable $e) {
            addLog($novelId, 'error', 'MemoryEngine.getPromptContext 失败：' . $e->getMessage());
            $memoryCtx = null;
        }

        $hitCount = is_array($memoryCtx['semantic_hits'] ?? null) ? count($memoryCtx['semantic_hits']) : 0;
        if ($hitCount > 0) {
            addLog($novelId, 'info', "语义召回生效：命中{$hitCount}条相关线索");
        } elseif (isset($memoryCtx['debug']['semantic_error'])) {
            addLog($novelId, 'warn', '语义召回失败：' . $memoryCtx['debug']['semantic_error']);
        }

        return ['engine' => $engine, 'memoryCtx' => $memoryCtx];
    }

    /**
     * Phase 3: 组装 AI 写作 prompt
     * @return array AI messages 数组
     */
    public static function buildPrompt(array $novel, array $chapter, ?array $memoryCtx): array
    {
        $previousSummary = getPreviousSummary($novel['id'], (int)$chapter['chapter_number']);
        $previousTail    = $memoryCtx['L4_previous_tail']
            ?? getPreviousTail($novel['id'], (int)$chapter['chapter_number']);
        // v1.4: 使用 ChapterPromptBuilder 替代 497 行函数，段落独立可测试
        require_once __DIR__ . '/ChapterPromptBuilder.php';
        $builder = new ChapterPromptBuilder($novel, $chapter, $previousSummary, $previousTail, $memoryCtx);
        return $builder->build();
    }

    /**
     * Phase 4: 带模型回退的流式写作
     * @param callable $onChunk      fn(string $token): void
     * @param callable $onMsg        fn(array $payload): void
     * @param callable $onHeartbeat  fn(): void
     * @param callable|null $onThinking fn(string $reasoningChunk): void  深度思考过程回调
     * @return array{content: string, model: ?AIClient}
     * @throws Exception 取消或全部模型失败
     */
    public static function streamWrite(
        array $messages,
        int $targetWords,
        int $novelId,
        callable $onChunk,
        callable $onMsg,
        callable $onHeartbeat,
        ?callable $onThinking = null
    ): array {
        $modelList   = getModelFallbackList(null);
        $modelErrors = [];
        $fullContent = '';
        $usedModel   = null;
        $estTokens   = (int)($targetWords * CFG_TOKEN_RATIO) + CFG_TOKEN_BUFFER;
        $usage       = null;
        $durationMs  = null;

        foreach ($modelList as $modelCfg) {
            $modelId    = (int)($modelCfg['id'] ?? 0);
            $modelLabel = $modelCfg['name'] ?? "模型{$modelId}";
            $isThinking = !empty($modelCfg['thinking_enabled']);
            $timeoutSec = $isThinking ? RT_THINKING_TIMEOUT : RT_NONTHINKING_TIMEOUT;

            if (($modelErrors[$modelId] ?? 0) >= RT_MODEL_ERR_MAX) {
                $onMsg(['info' => "模型 {$modelLabel} 错误次数过多，跳过"]);
                continue;
            }

            $sameModelRetries = 0;
            while ($sameModelRetries < RT_SAME_MODEL_MAX) {
                if ($sameModelRetries > 0) {
                    $retryDelay = RT_RETRY_DELAY * $sameModelRetries;
                    $onMsg(['waiting' => true, 'msg' => "等待 {$retryDelay} 秒后重试..."]);
                    for ($w = 0; $w < $retryDelay; $w += RT_POLL_INTERVAL) {
                        sleep(min(RT_POLL_INTERVAL, $retryDelay - $w));
                        $onHeartbeat();
                        // v1.4 文件系统检查替代 DB 查询，file_exists() 比 PDO prepare+execute 快 100+ 倍
                        if (file_exists(BASE_PATH . "/storage/write_cancel_{$novelId}.flag")) {
                            throw new Exception('用户取消了写作');
                        }
                    }
                }

                $streamStart = time();
                $fullContent = '';
                $ai = new AIClient($modelCfg);
                $usedModel = $ai;

                $desired = max($ai->getMaxTokens(), $estTokens);
                if ($desired > $ai->getMaxTokens()) {
                    $ai->setMaxTokens($desired);
                    $onMsg(['info' => "📊 max_tokens 调至 {$desired}"]);
                }

                $onMsg([
                    'model' => $modelLabel, 'attempt' => $sameModelRetries + 1,
                    'timeout' => $timeoutSec, 'thinking' => $isThinking,
                    'info' => ($isThinking ? '🔄 ' : '📡 ') . "{$modelLabel} 第" . ($sameModelRetries + 1) . "次尝试，超时{$timeoutSec}秒",
                ]);

                $canceled = false; $cancelCount = 0;
                try {
                    $usage = $ai->chatStream($messages, function(string $token) use (&$fullContent, $novelId, &$canceled, &$cancelCount, $onChunk) {
                        if (!$canceled && ++$cancelCount % 50 === 0) {
                            // v1.4 文件系统检查替代 DB 查询，file_exists() 比 PDO prepare+execute 快 100+ 倍
                            if (file_exists(BASE_PATH . "/storage/write_cancel_{$novelId}.flag")) $canceled = true;
                        }
                        if ($canceled) throw new Exception('用户取消了写作');
                        if ($token === '[DONE]') return;
                        $fullContent .= $token;
                        $onChunk($token);
                    }, 'creative', $onThinking);
                } catch (Exception $e) {
                    $errMsg = $e->getMessage();
                    if ($errMsg === '用户取消了写作') throw $e;
                    $modelErrors[$modelId] = ($modelErrors[$modelId] ?? 0) + 1;
                    $sameModelRetries++;
                    $onMsg([
                        'waiting' => true,
                        'reason' => "API错误（{$errMsg}，已耗时" . (time() - $streamStart) . "秒）",
                        'retry' => "第{$sameModelRetries}次 / " . RT_SAME_MODEL_MAX,
                    ]);
                    // 同模型内重试：未达上限则留在当前 while 循环，达上限则跳到下一模型
                    if ($sameModelRetries >= RT_SAME_MODEL_MAX) continue 2;
                    continue; // 重试当前模型
                }

                // v1.4: 采集 token 用量和实际耗时，为 OptimizationAgent 提供真实数据基础
                $durationMs = (time() - $streamStart) * 1000;

                $sinceLast = time() - ($ai->lastChunkTime ?: $streamStart);
                if ($sinceLast >= $timeoutSec) {
                    $sameModelRetries++;
                    $onMsg(['waiting' => true, 'reason' => "超时（{$sinceLast}秒无有效输出，已重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX]);
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
                    continue 2;
                }

                $modelErrors[$modelId] = 0;

                if ($ai->lastFinishReason === 'length') {
                    $actualWords = countWords($fullContent);
                    $lenTol = max(CFG_TOLERANCE_MIN, (int)($targetWords * CFG_TOLERANCE_RATIO));
                    $lenMax = $targetWords + $lenTol;
                    if ($actualWords > $lenMax) {
                        $fullContent = truncateToWordLimit($fullContent, $lenMax);
                        $onMsg(['warning' => "⚠️ max_tokens截断后超字（{$actualWords}字），已修剪至 " . countWords($fullContent) . " 字"]);
                    } else {
                        $onMsg(['info' => "📋 触发max_tokens上限（{$actualWords}字），内容在允许范围内"]);
                    }
                }
                break 2;
            }
        }

        return ['content' => $fullContent, 'model' => $usedModel, 'usage' => $usage, 'duration_ms' => $durationMs];
    }

    /**
     * Phase 5: 落盘正文 + 版本备份 + 取消检测
     * @param ?array $usage     chatStream() 返回的 usage 数组 ['prompt_tokens','completion_tokens','total_tokens']
     * @param ?int   $durationMs 本章生成耗时（毫秒）
     * @return array{words: int, chapter: array}
     * @throws RuntimeException
     */
    public static function saveChapter(int $chapterId, int $novelId, string $fullContent, int $targetWords, ?AIClient $usedModel, array $chapter, ?array $usage = null, ?int $durationMs = null): array
    {
        $ch = $chapter;
        $chId = $chapterId;

        // 版本备份
        $oldContent = $ch['content'] ?? '';
        $oldWords   = (int)($ch['words'] ?? 0);
        if (!empty($oldContent) && $oldWords > 100) {
            $maxVer = (int)(DB::fetch(
                'SELECT COALESCE(MAX(version), 0) AS v FROM chapter_versions WHERE chapter_id=?', [$chId]
            )['v'] ?? 0);
            DB::insert('chapter_versions', [
                'chapter_id' => $chId, 'version' => $maxVer + 1,
                'content' => $oldContent, 'outline' => $ch['outline'] ?? '',
                'title' => $ch['title'] ?? '', 'words' => $oldWords,
            ]);
            DB::execute(
                'DELETE FROM chapter_versions WHERE chapter_id=? AND id NOT IN ('
                . 'SELECT id FROM (SELECT id FROM chapter_versions WHERE chapter_id=? ORDER BY version DESC LIMIT 10) t)',
                [$chId, $chId]
            );
        }

        // 落盘前取消检测（v1.4 文件系统加速）
        if (file_exists(BASE_PATH . "/storage/write_cancel_{$novelId}.flag")) {
            throw new RuntimeException('canceled');
        }

        // 过滤AI误生成的段落标记
        $fullContent = stripSegmentMarkers($fullContent);

        // === 约束框架后置校验（Phase 1）===
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/ConstraintStateDB.php';
            require_once __DIR__ . '/constraints/PostWriteValidator.php';
            $validator = new PostWriteValidator($novelId, $ch, $fullContent, $targetWords);
            $validationResult = $validator->run();
            if ($validationResult['has_p0'] && ConstraintConfig::isStrictMode()) {
                addLog($novelId, 'warn', "第{$ch['chapter_number']}章触发P0约束：{$validationResult['p0_issues'][0]['issue_desc']}");
            } elseif ($validationResult['has_p1']) {
                $p1Count = count($validationResult['p1_issues']);
                addLog($novelId, 'warn', "第{$ch['chapter_number']}章触发{$p1Count}项P1约束");
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '约束后置校验跳过：' . $e->getMessage());
        }

        $words = countWords($fullContent);
        $updates = [
            'content' => $fullContent, 'words' => $words, 'status' => 'completed',
        ];
        // v1.4: 落盘 token 用量和耗时数据，为 OptimizationAgent 提供真实数据基础
        if ($usage !== null && isset($usage['total_tokens'])) {
            $updates['tokens_used'] = (int)$usage['total_tokens'];
        }
        if ($durationMs !== null) {
            $updates['duration_ms'] = $durationMs;
        }
        // v1.5: 落盘 hook_type，激活 suggestHookType 的"防连续重复"机制
        // 之前该字段从未被写入，导致防重复逻辑形同虚设
        try {
            $hookSuggestion = suggestHookType($ch);
            if (!empty($hookSuggestion['type'])) {
                $updates['hook_type'] = $hookSuggestion['type'];
            }
        } catch (Throwable $e) {
            // 钩子类型推荐失败不影响落盘
        }
        $affected = DB::update('chapters', $updates, 'id=? AND status="writing"', [$chId]);

        if ($affected === 0) {
            addLog($novelId, 'warn', "第{$ch['chapter_number']}章落盘被阻止：状态已被外部修改");
            throw new RuntimeException('写作已被中断（章节状态已变更）');
        }

        updateNovelStats($novelId);

        $modelInfo = $usedModel ? "（{$usedModel->modelLabel}）" : '';
        $wordDiff = $words - $targetWords;
        $wordDiffPct = $targetWords > 0 ? round(abs($wordDiff) / $targetWords * 100, 1) : 0;
        $diffMark = $wordDiff > 0 ? "+{$wordDiff}" : "{$wordDiff}";
        addLog($novelId, 'write',
            "完成第{$ch['chapter_number']}章《{$ch['title']}》，共{$words}字（目标{$targetWords}字，偏差{$diffMark}字/{$wordDiffPct}%）{$modelInfo}",
            $chId
        );

        $pendingCount = DB::count('chapters', 'novel_id=? AND status != "completed"', [$novelId]);
        if ($pendingCount === 0) {
            DB::update('novels', ['status' => 'completed'], 'id=?', [$novelId]);
        }

        return ['words' => $words, 'chapter' => $ch, 'all_done' => $pendingCount === 0, 'model_info' => $modelInfo];
    }

    /**
     * Phase 6: 后处理（摘要/记忆引擎/知识库/质检）
     * 所有异常内部捕获，保证正文不受影响
     */
    public static function postProcess(int $novelId, array $chapter, string $fullContent, MemoryEngine $engine): void
    {
        $chId = $chapter['id'];

        // --- 摘要 + 记忆引擎 ---
        try {
            $summaryData = generateChapterSummary(
                ['id' => $novelId], $chapter, $fullContent
            );
            if (!empty($summaryData)) {
                $updates = [];
                if (!empty($summaryData['narrative_summary'])) {
                    $updates['chapter_summary'] = $summaryData['narrative_summary'];
                }
                if (!empty($summaryData['used_tropes'])) {
                    $updates['used_tropes'] = json_encode($summaryData['used_tropes'], JSON_UNESCAPED_UNICODE);
                }
                if (!empty($summaryData['cool_point_type'])) {
                    $cpt = trim($summaryData['cool_point_type']);
                    $validCoolTypes = array_keys(COOL_POINT_TYPES);
                    if (in_array($cpt, $validCoolTypes)) $updates['cool_point_type'] = $cpt;
                }
                if (!empty($updates)) DB::update('chapters', $updates, 'id=?', [$chId]);

                try {
                    $ingest = $engine->ingestChapter((int)$chapter['chapter_number'], $summaryData);
                    if (!empty($ingest['errors'])) {
                        addLog($novelId, 'warn', 'MemoryEngine.ingestChapter 部分失败：' . implode('; ', $ingest['errors']));
                    }
                    addLog($novelId, 'info', sprintf(
                        '记忆入库：人物%d / 特征%d / 事件%d / 伏笔+%d / 回收%d',
                        $ingest['cards_upserted'] ?? 0, $ingest['traits_added'] ?? 0,
                        $ingest['events_added'] ?? 0, $ingest['foreshadowing_added'] ?? 0,
                        $ingest['foreshadowing_resolved'] ?? 0
                    ));
                } catch (Throwable $e) {
                    addLog($novelId, 'error', 'MemoryEngine.ingestChapter 失败：' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            addLog($novelId, 'error', '摘要/记忆引擎失败：' . $e->getMessage());
        }

        // --- 知识库提取 ---
        try {
            require_once __DIR__ . '/embedding.php';
            $kb = new KnowledgeBase($novelId);
            $kbStats = $kb->extractFromChapter((int)$chapter['chapter_number'], $fullContent);
            if (!empty(array_filter($kbStats))) {
                addLog($novelId, 'info', '知识库提取完成：角色' . ($kbStats['characters']??0) . '个，世界观' . ($kbStats['worldbuilding']??0) . '个，情节' . ($kbStats['plots']??0) . '个');
            }
        } catch (Throwable $e) {
            addLog($novelId, 'error', '知识库提取失败：' . $e->getMessage());
        }

        // --- 质量检测 ---
        try {
            if (!defined('CLI_MODE')) define('CLI_MODE', true);
            require_once __DIR__ . '/../api/validate_consistency.php';

            $vChapter = DB::fetch(
                'SELECT c.*, n.genre, n.chapter_words, n.writing_style '
                . 'FROM chapters c JOIN novels n ON c.novel_id = n.id '
                . 'WHERE c.id = ? AND c.novel_id = ?',
                [$chId, $novelId]
            );
            $vContent = $vChapter['content'] ?? $fullContent;

            if ($vChapter && !empty(trim($vContent))) {
                $results = [];
                $results[] = checkGate1_Structure($vChapter, $vContent);
                $results[] = checkGate2_Characters($novelId, $vContent);
                $results[] = checkGate3_Description($vChapter['genre'] ?? null, $vContent);
                $results[] = checkGate4_CoolPoint($vContent, $vChapter['outline'] ?? null);
                $results[] = checkGate5_Consistency($chId, $novelId, $vContent);

                $scores = array_column($results, 'score');
                $avgScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

                $qUpdates = [];
                if ($avgScore > 0) { $qUpdates['quality_score'] = (float)$avgScore; }
                $qUpdates['gate_results'] = json_encode($results, JSON_UNESCAPED_UNICODE);
                if (!empty($qUpdates)) {
                    DB::update('chapters', $qUpdates, 'id=?', [$chId]);
                }
                addLog($novelId, 'info', sprintf('质量检测：总分 %.1f/100', $avgScore));
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '质量检测跳过：' . $e->getMessage());
        }

        // --- v1.5 情绪密度检测（激活 EmotionDictionary）---
        // 之前 EmotionDictionary 模块完全是死代码，prompt 里教 AI 满足情绪密度
        // 但写完后从未验证。本节将统计落盘，并在偏低时让 Agent 写指令影响下一章
        try {
            require_once __DIR__ . '/emotion_dict.php';
            $emoDensity = EmotionDictionary::countEmotionDensity($fullContent);
            $emoEval    = EmotionDictionary::evaluateDensity($emoDensity);

            DB::update('chapters', [
                'emotion_density' => json_encode($emoDensity, JSON_UNESCAPED_UNICODE),
                'emotion_score'   => (float)$emoEval['overall_score'],
            ], 'id=?', [$chId]);

            addLog($novelId, 'info', sprintf(
                '情绪密度：得分 %.1f/100（%d 项问题）',
                $emoEval['overall_score'],
                count($emoEval['issues'] ?? [])
            ));

            // 偏低时写一条 Agent 指令影响下章
            if ($emoEval['overall_score'] < 60 && !empty($emoEval['issues'])) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $issuesText = implode('；', array_slice($emoEval['issues'], 0, 2));
                AgentDirectives::add(
                    $novelId,
                    (int)$chapter['chapter_number'] + 1,
                    'quality',
                    "前章情绪密度偏低（得分{$emoEval['overall_score']}）。问题：{$issuesText}。本章必须加大相应类别的情绪词使用频率。",
                    3,  // 持续 3 章
                    24  // 24 小时过期
                );
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '情绪密度检测跳过：' . $e->getMessage());
        }

        // --- v1.6 爽点实际类型检测（P1#7: 反馈闭环）---
        // 之前 calculateCoolPointSchedule 的 lastUsed 记录的是"计划排期"
        // 而非 AI 实际写到的类型。本节用关键词匹配检测正文中实际出现的爽点类型
        // v1.5.2: 关键词检测无命中时回退到 LLM summary 给出的类型
        try {
            $llmJudgedType = (isset($summaryData) && is_array($summaryData))
                ? ($summaryData['cool_point_type'] ?? null)
                : null;
            $actualCoolTypes = detectCoolPointTypes($fullContent, $llmJudgedType);
            DB::update('chapters', [
                'actual_cool_point_types' => !empty($actualCoolTypes)
                    ? json_encode($actualCoolTypes, JSON_UNESCAPED_UNICODE)
                    : null,
            ], 'id=?', [$chId]);

            if (!empty($actualCoolTypes)) {
                $typeNames = array_map(fn($t) => COOL_POINT_TYPES[$t]['name'] ?? $t, $actualCoolTypes);
                addLog($novelId, 'info', sprintf(
                    '爽点检测：识别到 %d 种类型 —— %s',
                    count($actualCoolTypes),
                    implode('、', $typeNames)
                ));
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '爽点检测跳过：' . $e->getMessage());
        }

        // --- v1.5 收尾期合规检查（激活 EndingEnforcer.checkEndingCompliance）---
        // 之前该方法是死代码，收尾期 AI 可能继续埋新伏笔/写新支线，系统不会发现
        try {
            require_once __DIR__ . '/ending_enforcer.php';
            $enforcer = new EndingEnforcer($novelId, (int)$chapter['chapter_number']);
            if ($enforcer->needsEndingEnforcement()) {
                $compliance = $enforcer->checkEndingCompliance($fullContent);

                if (!empty($compliance['issues'])) {
                    $stage = $enforcer->getEndingStage();
                    $issues = implode('；', array_slice($compliance['issues'], 0, 3));
                    addLog($novelId, 'warn', sprintf(
                        '收尾合规警告（%s阶段）：%s',
                        $stage, $issues
                    ));

                    // 让下一章 prompt 注意修正
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    AgentDirectives::add(
                        $novelId,
                        (int)$chapter['chapter_number'] + 1,
                        'quality',
                        "前章收尾合规警告（{$stage}阶段）：{$issues}。本章必须按收尾阶段规则写作，回收旧伏笔，禁止引入新支线。",
                        2,
                        24
                    );
                }
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '收尾合规检查跳过：' . $e->getMessage());
        }

        // --- Agent 指令效果反馈闭环（v1.5） ---
        try {
            require_once __DIR__ . '/agents/AgentDirectives.php';
            $outcomeResult = AgentDirectives::recordOutcomes($novelId, (int)$chapter['chapter_number']);
            if ($outcomeResult['recorded'] > 0) {
                $improved = count(array_filter($outcomeResult['outcomes'], fn($o) => $o['quality_change'] > 0));
                addLog($novelId, 'info', sprintf(
                    'Agent反馈闭环：评估%d条指令效果，%d条正向改善',
                    $outcomeResult['recorded'], $improved
                ));
            }
        } catch (Throwable $e) {
            // 反馈闭环失败不影响主流程
        }

        // === 约束框架状态更新（Phase 1）===
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/ConstraintStateDB.php';
            require_once __DIR__ . '/constraints/ConstraintStateUpdater.php';
            $stateUpdater = new ConstraintStateUpdater($novelId, $chapter, $fullContent);
            $stateUpdater->updateAll();
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '约束状态更新失败：' . $e->getMessage());
        }

        addLog($novelId, 'info', "第{$chapter['chapter_number']}章后处理完成（摘要/记忆/知识库/质检）");
    }
    
    /**
     * Agent决策：在写作前运行Agent决策流程
     * 
     * @param int $novelId 小说ID
     * @return void
     */
    private static function runPreWriteAgents(int $novelId): void
    {
        try {
            // 检查是否启用Agent
            if (!ConfigCenter::get('agent.enabled', true)) {
                return;
            }
            
            // 加载Agent协调器
            require_once __DIR__ . '/agents/AgentCoordinator.php';
            
            $coordinator = new AgentCoordinator($novelId);
            
            // 收集决策上下文
            $context = [
                'pending_foreshadowing_count' => self::countPendingForeshadowings($novelId),
                'recent_chapters' => self::getRecentChapters($novelId, 5),
                'current_progress' => self::getCurrentProgress($novelId),
                'current_chapter_number' => self::getCurrentChapterNumber($novelId),
            ];
            
            // 运行Agent决策
            $decisionResult = $coordinator->runDecisionCycle($context);
            
            // 记录决策结果
            if (!empty($decisionResult['execution_summary'])) {
                $summary = $decisionResult['execution_summary'];
                addLog($novelId, 'info', sprintf(
                    'Agent决策完成：决策%d次，执行%d个动作，成功%d个',
                    $summary['total_decisions'],
                    $summary['total_actions'],
                    $summary['successful_actions']
                ));
            }
            
        } catch (Throwable $e) {
            // Agent决策失败不影响主流程
            addLog($novelId, 'warn', 'Agent决策失败：' . $e->getMessage());
        }
    }
    
    /**
     * 统计待回收伏笔数量
     */
    private static function countPendingForeshadowings(int $novelId): int
    {
        try {
            $result = DB::fetch(
                'SELECT COUNT(*) as cnt FROM foreshadowing_items WHERE novel_id = ? AND resolved_chapter IS NULL',
                [$novelId]
            );
            return (int)($result['cnt'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取最近的章节
     */
    private static function getRecentChapters(int $novelId, int $limit): array
    {
        try {
            return DB::fetchAll(
                'SELECT * FROM chapters WHERE novel_id = ? AND status = "completed" ORDER BY chapter_number DESC LIMIT ?',
                [$novelId, $limit]
            );
        } catch (Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取当前进度
     */
    private static function getCurrentProgress(int $novelId): float
    {
        try {
            $novel = DB::fetch('SELECT target_chapters FROM novels WHERE id = ?', [$novelId]);
            $target = (int)($novel['target_chapters'] ?? 0);
            
            if ($target <= 0) return 0;
            
            $completed = DB::fetch(
                'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id = ? AND status = "completed"',
                [$novelId]
            );
            
            return (int)($completed['cnt'] ?? 0) / $target;
        } catch (Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取当前章节号（已completed章节数+1，即下一个要写的章节）
     */
    private static function getCurrentChapterNumber(int $novelId): int
    {
        try {
            $chapter = DB::fetch(
                'SELECT COUNT(*) + 1 as next_chapter FROM chapters WHERE novel_id = ? AND status = "completed"',
                [$novelId]
            );
            
            return (int)($chapter['next_chapter'] ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }
}
