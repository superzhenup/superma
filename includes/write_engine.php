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
     * Phase 1: 解析待写章节（含僵死 writing 状态清理）
     * @return array{n: array, ch: array}
     * @throws RuntimeException
     */
    public static function resolveChapter(int $novelId, ?int $chapterId = null): array
    {
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
        $pdo = DB::connect();
        $pdo->beginTransaction();
        try {
            DB::update('novels',   ['cancel_flag' => 0], 'id=?', [$novelId]);
            DB::update('chapters', ['status' => 'writing', 'retry_count' => 0], 'id=?', [$ch['id']]);
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
        return buildChapterPrompt($novel, $chapter, $previousSummary, $previousTail, $memoryCtx);
    }

    /**
     * Phase 4: 带模型回退的流式写作
     * @param callable $onChunk      fn(string $token): void
     * @param callable $onMsg        fn(array $payload): void
     * @param callable $onHeartbeat  fn(): void
     * @return array{content: string, model: ?AIClient}
     * @throws Exception 取消或全部模型失败
     */
    public static function streamWrite(
        array $messages,
        int $targetWords,
        int $novelId,
        callable $onChunk,
        callable $onMsg,
        callable $onHeartbeat
    ): array {
        $modelList   = getModelFallbackList(null);
        $modelErrors = [];
        $fullContent = '';
        $usedModel   = null;
        $estTokens   = (int)($targetWords * CFG_TOKEN_RATIO) + CFG_TOKEN_BUFFER;

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
                        if (DB::fetch('SELECT cancel_flag FROM novels WHERE id=?', [$novelId])['cancel_flag'] ?? 0) {
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
                    $ai->chatStream($messages, function(string $token) use (&$fullContent, $novelId, &$canceled, &$cancelCount, $onChunk) {
                        if (!$canceled && ++$cancelCount % 50 === 0) {
                            $row = DB::fetch('SELECT cancel_flag FROM novels WHERE id=?', [$novelId]);
                            if ($row && $row['cancel_flag']) $canceled = true;
                        }
                        if ($canceled) throw new Exception('用户取消了写作');
                        if ($token === '[DONE]') return;
                        $fullContent .= $token;
                        $onChunk($token);
                    }, 'creative');
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
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue 2;
                    continue 2;
                }

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

        return ['content' => $fullContent, 'model' => $usedModel];
    }

    /**
     * Phase 5: 落盘正文 + 版本备份 + 取消检测
     * @return array{words: int, chapter: array}
     * @throws RuntimeException
     */
    public static function saveChapter(int $chapterId, int $novelId, string $fullContent, int $targetWords, ?AIClient $usedModel, array $chapter): array
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

        // 落盘前取消检测
        $currentFlag = DB::fetch('SELECT cancel_flag FROM novels WHERE id=?', [$novelId]);
        if ($currentFlag && $currentFlag['cancel_flag']) {
            throw new RuntimeException('canceled');
        }

        $words = countWords($fullContent);
        $affected = DB::update('chapters', [
            'content' => $fullContent, 'words' => $words, 'status' => 'completed',
        ], 'id=? AND status="writing"', [$chId]);

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
                    $validCoolTypes = ['underdog_win','face_slap','treasure_find','breakthrough','power_expand','romance_win'];
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

        addLog($novelId, 'info', "第{$chapter['chapter_number']}章后处理完成（摘要/记忆/知识库/质检）");
    }
}
