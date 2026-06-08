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

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/data.php';

class WriteEngineValidationException extends RuntimeException {}
class WriteEnginePersistenceException extends RuntimeException {}

class WriteEngine
{
    /** 后处理「进行中」标志文件路径（防止下一章在记忆/指令落库前就开写） */
    private static function postProcessPendingFlag(int $novelId, int $chNum): string
    {
        return BASE_PATH . "/storage/pp_pending_{$novelId}_{$chNum}.flag";
    }

    /**
     * 等待指定章节的后处理完成（摘要/记忆入库/Agent指令落库）。
     * 仅当存在「进行中」标志时才等待，避免对历史章节空等。
     * 超时后放行并记日志（防止后台进程意外退出导致死等）。
     */
    private static function waitForPostProcess(int $novelId, int $chNum, int $maxWaitSec = 90): void
    {
        if ($chNum < 1) return;
        $flag = self::postProcessPendingFlag($novelId, $chNum);
        if (!file_exists($flag)) return; // 未在后处理中（已完成或为历史章节）

        $deadline = time() + $maxWaitSec;
        while (time() < $deadline) {
            usleep(400000); // 0.4s
            if (!file_exists($flag)) {
                addLog($novelId, 'info', "已等待第{$chNum}章后处理完成，开始写下一章");
                return;
            }
        }
        // 超时兜底：清除标志并放行，避免死等
        @unlink($flag);
        addLog($novelId, 'warn', "等待第{$chNum}章后处理超时（{$maxWaitSec}秒），继续写作（记忆可能滞后）");
    }

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
        $flagFile = BASE_PATH . "/storage/write_cancel_{$novelId}.flag";

        $pdo = DB::connect();
        $pdo->beginTransaction();
        try {
            DB::update('novels',   ['cancel_flag' => 0], 'id=?', [$novelId]);
            DB::update('chapters', ['status' => 'writing'], 'id=?', [$ch['id']]);
            DB::update('novels',   ['status' => 'writing'], 'id=?', [$novelId]);
            $pdo->commit();
            // 事务成功后再清除取消标志文件，确保状态一致性
            if (file_exists($flagFile)) {
                @unlink($flagFile);
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // v2: 记忆竞态修复——若上一章后处理（摘要/记忆/指令）仍在后台进行，
        // 先等它完成再构建本章 prompt，否则本章会读到滞后的记忆与漏掉的 Agent 指令。
        self::waitForPostProcess($novelId, (int)$ch['chapter_number'] - 1);

        return ['n' => $novel, 'ch' => $ch];
    }

    /**
     * Phase 2: 初始化记忆引擎 + 语义召回
     * @param int $novelId 小说ID
     * @param array $chapter 章节数据
     * @param ?AIClient $aiClient AI客户端（用于检测1M上下文支持）
     * @return array{engine: MemoryEngine, memoryCtx: ?array}
     */
    public static function initMemory(int $novelId, array $chapter, ?AIClient $aiClient = null): array
    {
        require_once __DIR__ . '/memory/MemoryEngine.php';
        $engine = new MemoryEngine($novelId);

        try { $engine->ensureEmbeddings(30); }
        catch (Throwable $e) { addLog($novelId, 'warn', 'ensureEmbeddings 失败：' . $e->getMessage()); }

        $queryText = trim(($chapter['title'] ?? '') . '：' . ($chapter['outline'] ?? ''));
        $semanticTopK = max(1, min(40, (int)getSystemSetting('ws_embedding_top_k', 16, 'int')));

        $contextMode = getSystemSetting('ws_context_mode', 'auto', 'string');
        $is1MSupported = $aiClient ? $aiClient->is1MContext() : false;
        $useFullContext = false;
        $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);

        if ($contextMode === 'full' && $is1MSupported) {
            $useFullContext = true;
        } elseif ($contextMode === 'auto' && $is1MSupported) {
            $fullContextThreshold = (int)getSystemSetting('ws_full_context_threshold', 10, 'int');
            if ($chNum >= $fullContextThreshold) {
                $useFullContext = true;
            }
        }

        try {
            if ($useFullContext) {
                // 1M上下文模式：注入完整历史，不做token裁剪
                $memoryCtx = $engine->getFullPromptContext((int)$chapter['chapter_number'], 100);
                addLog($novelId, 'info', sprintf(
                    '使用1M完整上下文模式：大纲%d章 / 正文%d章 / 伏笔%d条 / 角色%d个',
                    $memoryCtx['full_context_stats']['total_outlines'] ?? 0,
                    $memoryCtx['full_context_stats']['full_content_chapters'] ?? 0,
                    $memoryCtx['full_context_stats']['foreshadowing_count'] ?? 0,
                    $memoryCtx['full_context_stats']['character_count'] ?? 0
                ));
            } else {
                // 标准压缩模式
                // v41: token 预算配置化（缓存让大上下文便宜，默认从 6000 提到 20000）
                $memBudget = max(2000, (int)getSystemSetting('ws_memory_token_budget', 20000, 'int'));
                $memoryCtx = $engine->getPromptContext((int)$chapter['chapter_number'], $queryText, $memBudget, 20, $semanticTopK);
            }
        } catch (Throwable $e) {
            addLog($novelId, 'error', 'MemoryEngine 上下文构建失败：' . $e->getMessage());
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

        $resolverResult = null;
        try {
            $resolverResult = self::runForeshadowingResolver($novel, $chapter);
        } catch (\Throwable $e) {
            addLog((int)$novel['id'], 'warn', 'ForeshadowingResolver 失败：' . $e->getMessage());
        }
        if ($resolverResult) {
            $builder->setResolverResult($resolverResult);
        }

        return $builder->build();
    }

    /**
     * Phase 2.5: 主动伏笔回收规划
     *
     * 根据当前章节大纲和全书进度，从待回收伏笔中挑选最合适的伏笔，
     * 生成具体的回收指令，注入到 Prompt 中引导 AI 回收。
     */
    private static function runForeshadowingResolver(array $novel, array $chapter): ?array
    {
        $novelId = (int)$novel['id'];
        $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);
        $targetChapters = (int)($novel['target_chapters'] ?? 0);

        // 调试日志：检查前置条件
        addLog($novelId, 'debug', sprintf(
            '伏笔回收检查：章节=%d, target_chapters=%d',
            $chNum,
            $targetChapters
        ));

        if ($targetChapters <= 0) {
            addLog($novelId, 'warn', '伏笔回收跳过：target_chapters 未设置或为0');
            return null;
        }

        require_once __DIR__ . '/memory/ForeshadowingResolver.php';
        $resolver = new ForeshadowingResolver($novelId, $chNum, $targetChapters);
        $outline = trim((string)($chapter['outline'] ?? ''));
        $result = $resolver->planResolution($outline);

        // 调试日志：输出规划结果统计
        addLog($novelId, 'debug', sprintf(
            '伏笔回收规划结果：should_resolve=%s, pending=%d, phase=%s, pressure=%.2f',
            $result['should_resolve'] ? 'true' : 'false',
            $result['stats']['pending_count'] ?? 0,
            $result['stats']['plan']['phase'] ?? 'unknown',
            $result['stats']['pressure'] ?? 0
        ));

        if (!empty($result['should_resolve'])) {
            $itemDescs = array_map(
                fn($it) => "第{$it['planted_chapter']}章:{$it['description']}",
                $result['items']
            );
            addLog($novelId, 'info', sprintf(
                '伏笔回收规划：选中%d条 → %s',
                count($result['items']),
                implode(' | ', $itemDescs)
            ));
        }

        return $result;
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
        ?callable $onThinking = null,
        ?int $preferredModelId = null,
        int $chapterNumber = 0,
        int $targetChapters = 0
    ): array {
        $modelList   = getModelFallbackList($preferredModelId);
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
                $ai->setCallbacks(
                    $onHeartbeat,
                    null,
                    function() use ($novelId) {
                        return file_exists(BASE_PATH . "/storage/write_cancel_{$novelId}.flag");
                    }
                );

                $desired = max($ai->getMaxTokens(), $estTokens);
                if ($desired > $ai->getMaxTokens()) {
                    $ai->setMaxTokens($desired);
                    $onMsg(['info' => "📊 max_tokens 调至 {$desired}"]);
                }

                $onMsg([
                    'model' => $modelLabel, 'attempt' => $sameModelRetries + 1,
                    'timeout' => $timeoutSec, 'thinking' => $isThinking,
                ]);

                if ($chapterNumber > 0 && $targetChapters > 0) {
                    $progressRatio = $chapterNumber / $targetChapters;
                    $origTemp = $ai->getTemperature();
                    $tempDelta = 0.0;

                    $isVolumeEnd = false;
                    try {
                        $volEnd = DB::fetch(
                            'SELECT 1 FROM volume_outlines WHERE novel_id=? AND end_chapter=?',
                            [$novelId, $chapterNumber]
                        );
                        $isVolumeEnd = !empty($volEnd);
                    } catch (Throwable) {}

                    $chMeta = null;
                    try {
                        $chMeta = DB::fetch(
                            'SELECT outline, pacing, cool_point_type FROM chapters WHERE novel_id=? AND chapter_number=?',
                            [$novelId, $chapterNumber]
                        );
                    } catch (Throwable) {}

                    $outline = trim((string)($chMeta['outline'] ?? ''));
                    $pacing  = $chMeta['pacing'] ?? '';
                    $cpType  = $chMeta['cool_point_type'] ?? '';

                    $highIntensityKw = ['决战','反杀','爆发','突破','觉醒','大战','终极','生死','逆袭','翻盘','对决','屠杀','毁灭','渡劫','天劫','围攻','血战','绝杀','拼死'];
                    $midIntensityKw  = ['对峙','阴谋','追杀','围困','抉择','考验','危机','伏击','暗算','冲突','对抗','谈判','布局','陷阱','试探'];
                    $lowIntensityKw  = ['休整','修炼','日常','回忆','感悟','疗伤','整顿','休憩','闲聊','游历','探索','修炼','冥想','闭关','温习'];

                    $highHits = 0; $midHits = 0; $lowHits = 0;
                    if ($outline !== '') {
                        foreach ($highIntensityKw as $kw) { if (mb_strpos($outline, $kw) !== false) $highHits++; }
                        foreach ($midIntensityKw as $kw) { if (mb_strpos($outline, $kw) !== false) $midHits++; }
                        foreach ($lowIntensityKw as $kw) { if (mb_strpos($outline, $kw) !== false) $lowHits++; }
                    }

                    $outlineScore = min(0.15, $highHits * 0.05)
                                  + min(0.06, $midHits * 0.02)
                                  - min(0.12, $lowHits * 0.04);

                    $pacingDelta = match($pacing) {
                        'fast' => 0.05,
                        'slow' => -0.05,
                        default => 0.0,
                    };

                    $cpDelta = 0.0;
                    if ($cpType !== '' && $cpType !== 'none') {
                        $highCp = ['underdog_win','face_slap','last_stand','truth_reveal'];
                        $cpDelta = in_array($cpType, $highCp, true) ? 0.06 : 0.03;
                    }

                    if ($isVolumeEnd) {
                        $tempDelta += 0.12;
                    } elseif ($progressRatio > 0.85) {
                        $tempDelta += 0.10;
                    } elseif ($progressRatio < 0.1) {
                        $tempDelta -= 0.08;
                    }

                    $tempDelta += $outlineScore + $pacingDelta + $cpDelta;
                    $tempDelta = max(-0.2, min(0.25, $tempDelta));

                    if (abs($tempDelta) >= 0.02) {
                        $ai->setTemperature($origTemp + $tempDelta);
                    }
                }

                $canceled = false; $cancelCount = 0;
                $cancelCheckInterval = 10;
                try {
                    $usage = $ai->chatStream($messages, function(string $token) use (&$fullContent, $novelId, &$canceled, &$cancelCount, $cancelCheckInterval, $onChunk) {
                        if (!$canceled && ++$cancelCount % $cancelCheckInterval === 0) {
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

                if ($ai->lastFinishReason === 'silence_timeout') {
                    if (mb_strlen(trim($fullContent)) >= 200) {
                        $onMsg(['warning' => '⚠️ AI 静默超时，使用已生成的部分内容（' . countWords($fullContent) . '字）']);
                        break 2;
                    }
                    $fullContent = '';
                    $sameModelRetries++;
                    $onMsg(['waiting' => true, 'reason' => "静默超时（" . (CFG_SSE_SILENCE * 3) . "秒无输出），重试{$sameModelRetries}/" . RT_SAME_MODEL_MAX]);
                    if ($sameModelRetries < RT_SAME_MODEL_MAX) continue;
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

                // v1.5.4: 所有完成状态都检查字数超限（不仅限 finish_reason=length）
                // AI 模型常忽略字数指令正常完成(finish_reason=stop)，导致 1000→2000 的偏差
                $actualWords = countWords($fullContent);
                $lenTol = calculateDynamicTolerance($targetWords);
                $lenMax = $lenTol['max'];
                if ($actualWords > $lenMax) {
                    $fullContent = truncateToWordLimit($fullContent, $lenMax);
                    $reason = $ai->lastFinishReason === 'length' ? 'max_tokens截断后' : 'AI正常完成但';
                    $onMsg(['warning' => "⚠️ {$reason}超字（{$actualWords}字），已修剪至 " . countWords($fullContent) . " 字"]);
                } elseif ($ai->lastFinishReason === 'length') {
                    $onMsg(['info' => "📋 触发max_tokens上限（{$actualWords}字），内容在允许范围内"]);
                }
                break 2;
            }
        }

        if (empty($fullContent)) {
            $errorSummary = [];
            foreach ($modelErrors as $mid => $cnt) {
                $errorSummary[] = "模型#{$mid}失败{$cnt}次";
            }
            throw new RuntimeException('所有模型均未产出内容：' . implode('；', $errorSummary ?: ['无可用模型']));
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
        // 过滤AI误把章节坐标写进正文（伏笔回收穿帮）
        $fullContent = stripMetaLeaks($fullContent);

        // === 标题禁用词「落盘前自动改写」（根治：标题P0阻断落盘→挂机死锁）===
        // 必须在后置校验之前执行，使校验看到的是已清洗的标题，从根上避免 P0 阻断。
        $titleAutoFixed = false;
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/TitleSanitizer.php';
            if (ConstraintConfig::isEnabled()) {
                $san = TitleSanitizer::sanitize((string)($ch['title'] ?? ''), (int)($ch['chapter_number'] ?? 0));
                if ($san['changed']) {
                    $oldTitle       = (string)$ch['title'];
                    $ch['title']    = $san['title'];
                    $titleAutoFixed = true;
                    addLog($novelId, 'info', sprintf(
                        '第%d章标题含禁用词「%s」，已自动改写：《%s》→《%s》（不再阻断落盘）',
                        (int)$ch['chapter_number'],
                        implode('、', $san['hit_words']),
                        $oldTitle,
                        $ch['title']
                    ), $chId);
                }
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '标题自动改写跳过：' . $e->getMessage());
        }

        // === 约束框架后置校验 ===
        // P0 判定在 try 外部执行：即使校验过程发生异常，已检测到的 P0 也不会被静默放行
        $validationResult = null;
        try {
            require_once __DIR__ . '/constraints/ConstraintConfig.php';
            require_once __DIR__ . '/constraints/ConstraintStateDB.php';
            require_once __DIR__ . '/constraints/PostWriteValidator.php';
            $validator = new PostWriteValidator($novelId, $ch, $fullContent, $targetWords);
            $validationResult = $validator->run();
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '约束后置校验跳过：' . $e->getMessage());
        }

        $p0StrictBlock = false;

        if ($validationResult && $validationResult['has_p0']) {
            // === 紧急响应通道（工程控制论：缩短响应延迟）===
            // P0 严重问题不管是否严格模式，都立即写紧急指令到下一章
            $urgentIssues = [];
            foreach ($validationResult['p0_issues'] as $p0) {
                $urgentIssues[] = $p0['issue_desc'];
            }
            $urgentMsg = "【紧急修复】上章发生严重问题：" . implode('；', $urgentIssues) .
                "。本章必须立即修正，避免问题延续。";
            try {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                AgentDirectives::add(
                    $novelId,
                    (int)$ch['chapter_number'] + 1,
                    'urgent',
                    $urgentMsg,
                    1,    // 只影响下一章
                    24    // 24小时过期
                );
                addLog($novelId, 'warn', sprintf(
                    '紧急通道触发：第%d章P0问题已写紧急指令',
                    (int)$ch['chapter_number']
                ));
            } catch (\Throwable $e) {
                addLog($novelId, 'warn', '紧急指令写入失败：' . $e->getMessage());
            }

            if (ConstraintConfig::isStrictMode()) {
                $issue = $validationResult['p0_issues'][0]['issue_desc'];
                $p0StrictBlock = "严格模式：第{$ch['chapter_number']}章 P0 违规阻止落盘 — {$issue}";
            }
        } elseif ($validationResult && $validationResult['has_p1']) {
            $p1Count = count($validationResult['p1_issues']);
            addLog($novelId, 'warn', "第{$ch['chapter_number']}章触发{$p1Count}项P1约束");
        }

        if ($p0StrictBlock !== false) {
            throw new WriteEngineValidationException($p0StrictBlock);
        }

        $words = countWords($fullContent);
        $updates = [
            'content' => $fullContent, 'words' => $words, 'status' => 'completed',
        ];
        // 标题被自动改写时，连同新标题一起落盘（否则恢复后仍是旧标题，死锁复现）
        if ($titleAutoFixed) {
            $updates['title'] = (string)$ch['title'];
        }
        // v1.4: 落盘 token 用量和耗时数据，为 OptimizationAgent 提供真实数据基础
        if ($usage !== null && isset($usage['total_tokens'])) {
            $updates['tokens_used'] = (int)$usage['total_tokens'];
        }
        // v41: 落盘提示词缓存命中 token（1000章优化埋点）
        if ($usage !== null && isset($usage['cache_hit_tokens'])) {
            $updates['cache_hit_tokens'] = (int)$usage['cache_hit_tokens'];
            $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
            if ($promptTokens > 0 && $usage['cache_hit_tokens'] > 0) {
                $hitPct = round($usage['cache_hit_tokens'] / $promptTokens * 100, 1);
                addLog($novelId, 'info', "提示词缓存命中：{$usage['cache_hit_tokens']}/{$promptTokens} tokens（{$hitPct}%）", $chId);
            }
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
        // v1.7: 落盘 opening_type，与 hook_type 同模式
        try {
            $openingSuggestion = suggestOpeningType($ch);
            if (!empty($openingSuggestion['type'])) {
                $updates['opening_type'] = $openingSuggestion['type'];
            }
        } catch (Throwable $e) {
            // 开篇类型推荐失败不影响落盘
        }
        $affected = DB::update('chapters', $updates, 'id=? AND status="writing"', [$chId]);

        // 清除章节缓存
        if ($affected > 0) {
            clearChapterCache($chId, $novelId);
        }

        // v1.5.3: 落盘异常保底逻辑 — 若主更新失败，尝试最小化保存
        if ($affected === 0) {
            // 检查章节是否仍存在且状态为writing
            $currentStatus = DB::fetchColumn('SELECT status FROM chapters WHERE id=?', [$chId]);
            if ($currentStatus === 'writing') {
                // 状态未变但更新失败，尝试最小化保存
                // 仍需 AND status="writing" 防止与外部状态变更（如用户取消）竞态
                try {
                    $minimalAffected = DB::update(
                        'chapters',
                        ['content' => $fullContent, 'words' => $words, 'status' => 'completed'],
                        'id=? AND status="writing"',
                        [$chId]
                    );
                    if ($minimalAffected > 0) {
                        addLog($novelId, 'warn', "第{$ch['chapter_number']}章通过保底逻辑落盘（主更新失败）");
                        $affected = $minimalAffected;
                        // 清除章节缓存
                        clearChapterCache($chId, $novelId);
                    }
                } catch (\Throwable $fallbackError) {
                    addLog($novelId, 'error', "第{$ch['chapter_number']}章保底落盘也失败：" . $fallbackError->getMessage());
                }
            }
        }

        if ($affected === 0) {
            addLog($novelId, 'warn', "第{$ch['chapter_number']}章落盘被阻止：状态已被外部修改");
            throw new WriteEnginePersistenceException('写作已被中断（章节状态已变更）');
        }

        updateNovelStats($novelId);

        // v2: 标记本章「后处理进行中」——下一章 resolveChapter 会等待此标志清除，
        // 确保摘要/记忆/Agent指令落库后再构建下一章 prompt（消除记忆竞态）。
        try {
            @file_put_contents(
                self::postProcessPendingFlag($novelId, (int)$ch['chapter_number']),
                (string)time()
            );
        } catch (\Throwable $e) { /* 标志写入失败不影响落盘 */ }

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

        // 预查下一章 ID（供前端自动写作循环直接跳转，省一次 HTTP 轮询）
        $nextCh = DB::fetch(
            'SELECT id, chapter_number FROM chapters WHERE novel_id=? AND status IN ("outlined","skipped") ORDER BY chapter_number ASC LIMIT 1',
            [$novelId]
        );

        return [
            'words'            => $words,
            'chapter'          => $ch,
            'all_done'         => $pendingCount === 0,
            'model_info'       => $modelInfo,
            'next_chapter_id'  => $nextCh['id'] ?? null,
            'next_chapter_num' => $nextCh['chapter_number'] ?? null,
        ];
    }
    public static function postProcess(int $novelId, array $chapter, string $fullContent, MemoryEngine $engine): void
    {
        $chId = $chapter['id'];

        // --- 摘要 + 记忆引擎 ---
        $summaryData = null;
        $novelData = ['id' => $novelId];
        try {
            $fetched = DB::fetch(
                'SELECT id, title, protagonist_name, protagonist_info, model_id FROM novels WHERE id=?',
                [$novelId]
            );
            if ($fetched) $novelData = $fetched;
            $summaryData = generateChapterSummary(
                $novelData, $chapter, $fullContent
            );
        } catch (Throwable $e) {
            addLog($novelId, 'error', '摘要生成失败：' . $e->getMessage());
        }

        if (empty($summaryData)) {
            $summaryData = self::buildFallbackSummary($chapter, $fullContent);
            addLog($novelId, 'warn', 'AI摘要失败，使用降级摘要（仅大纲+关键事件），记忆引擎仅写入基本数据');
        }

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

            // v1.11.8: 详细日志
            $resolvedDetails = $ingest['resolved_details'] ?? [];
            $resolvedLog = '';
            if (!empty($resolvedDetails)) {
                $resolvedLog = ' → ' . implode(' | ', array_map(fn($r) => "ID:{$r['id']}「{$r['desc']}」", $resolvedDetails));
            }
            addLog($novelId, 'info', sprintf(
                '记忆入库：人物%d / 特征%d / 事件%d / 伏笔+%d / 回收%d%s',
                $ingest['cards_upserted'] ?? 0, $ingest['traits_added'] ?? 0,
                $ingest['events_added'] ?? 0, $ingest['foreshadowing_added'] ?? 0,
                $ingest['foreshadowing_resolved'] ?? 0,
                $resolvedLog
            ));
        } catch (Throwable $e) {
            addLog($novelId, 'error', 'MemoryEngine.ingestChapter 失败：' . $e->getMessage());
        }

        // --- v1.11.2 认知负荷检测（信息密度管理）---
        try {
            require_once __DIR__ . '/CognitiveLoadMonitor.php';
            $loadMonitor = new CognitiveLoadMonitor($novelId);
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $loadResult = $loadMonitor->analyze($chNum, $ingest ?? []);
            $loadMonitor->persistMetrics($chId, $loadResult);

            // 认知负荷超标时写 Agent 指令
            if (isset($loadResult['severity']) && in_array($loadResult['severity'], ['high', 'medium'])) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $existingCL = DB::fetch(
                    "SELECT id FROM agent_directives 
                     WHERE novel_id=? AND type='strategy' AND is_active=1
                       AND apply_from <= ? AND apply_to >= ?
                       AND directive LIKE '%认知负荷%'
                     LIMIT 1",
                    [$novelId, $chNum + 1, $chNum + 1]
                );
                if (!$existingCL) {
                    AgentDirectives::add(
                        $novelId,
                        $chNum + 1,
                        'strategy',
                        $loadResult['directive'],
                        2,
                        48
                    );
                }
                addLog($novelId, 'warn', sprintf(
                    '认知负荷警告：本章引入 %d 个新元素（近5章累计 %d 个）',
                    $loadResult['total_new'] ?? 0,
                    $loadResult['recent_5_sum'] ?? 0
                ));
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '认知负荷检测跳过：' . $e->getMessage());
        }

        // --- 伏笔提及追踪 (v1.10.3) ---
        try {
            require_once __DIR__ . '/memory/ForeshadowingRepo.php';
            $foreshadowRepo = new ForeshadowingRepo($novelId);
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $mentionCount = $foreshadowRepo->trackMentionsInContent($fullContent, $chNum);
            if ($mentionCount > 0) {
                addLog($novelId, 'info', "伏笔提及追踪：本章提及{$mentionCount}条伏笔");
            }
            $foreshadowAlerts = $foreshadowRepo->checkHealth($chNum);
            if (!empty($foreshadowAlerts)) {
                require_once __DIR__ . '/agents/AgentDirectives.php';
                $highAlerts = array_filter($foreshadowAlerts, fn($a) => $a['severity'] === 'high');
                if ($highAlerts) {
                    $msg = implode('；', array_map(fn($a) => $a['message'], array_slice($highAlerts, 0, 2)));
                    $sug = implode('；', array_map(fn($a) => $a['suggestion'], array_slice($highAlerts, 0, 2)));
                    AgentDirectives::add(
                        $novelId, $chNum + 1, 'quality',
                        "伏笔健康告警：{$msg}。{$sug}",
                        2, 48
                    );
                }
                addLog($novelId, 'info', sprintf(
                    '伏笔健康检测：%d条告警（高危%d）',
                    count($foreshadowAlerts),
                    count($highAlerts)
                ));
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '伏笔提及追踪跳过：' . $e->getMessage());
        }

        // --- 角色出场追踪 (v1.11.8) ---
        try {
            require_once __DIR__ . '/memory/CharacterCardRepo.php';
            $cardRepo = new CharacterCardRepo($novelId);
            $chNum = (int)($chapter['chapter_number'] ?? 0);

            // 获取所有已知角色名
            $allCards = $cardRepo->listAll(false);
            if (!empty($allCards)) {
                $presentNames = [];
                foreach ($allCards as $card) {
                    $name = $card['name'];
                    // 角色名出现在正文中即视为出场
                    if (mb_strpos($fullContent, $name) !== false) {
                        $presentNames[] = $name;
                    }
                }
                if (!empty($presentNames)) {
                    $touched = $cardRepo->touchPresenceBatch($presentNames, $chNum);
                    if ($touched > 0) {
                        addLog($novelId, 'info', "角色出场追踪：本章{$touched}个角色出场");
                    }
                }
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '角色出场追踪跳过：' . $e->getMessage());
        }

        // --- 金句回调追踪 (v1.10.3) ---
        try {
            require_once __DIR__ . '/memory/CatchphraseRepo.php';
            $catchRepo = new CatchphraseRepo($novelId);
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $cbCount = $catchRepo->trackCallbacksInContent($fullContent, $chNum);
            if ($cbCount > 0) {
                addLog($novelId, 'info', "金句回调追踪：本章callback {$cbCount}条金句");
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '金句回调追踪跳过：' . $e->getMessage());
        }

        // --- v41 钩子回收验证（闭环：本章是否承接了上章章末钩子）---
        try {
            require_once __DIR__ . '/HookPayoffChecker.php';
            HookPayoffChecker::run($novelId, $chapter, $fullContent);
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '钩子回收验证跳过：' . $e->getMessage());
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

        // --- v1.9 RewriteAgent — 低分章节自动重写（盲点1修复）---
        $rewriteEnabled = (bool)getSystemSetting('ws_rewrite_enabled', false, 'bool');
        if ($rewriteEnabled) {
            try {
                require_once __DIR__ . '/agents/RewriteAgent.php';
                $gates = json_decode(
                    DB::fetch("SELECT gate_results FROM chapters WHERE id=?", [$chId])['gate_results'] ?? '[]',
                    true
                ) ?: [];
                $qScore = (float)(DB::fetch("SELECT quality_score FROM chapters WHERE id=?", [$chId])['quality_score'] ?? 100);

                $rewriter = new RewriteAgent($novelId);
                $rewriteResult = $rewriter->rewriteIfNeeded(
                    $chapter, $fullContent, $gates, $qScore,
                    $novelData['model_id'] ?? null
                );
                if ($rewriteResult['rewritten']) {
                    $fullContent = $rewriteResult['content'];
                    $novelRow = DB::fetch('SELECT chapter_words FROM novels WHERE id=?', [$novelId]);
                    $rwTarget = (int)($novelRow['chapter_words'] ?? 2000);
                    $rwWords = countWords($fullContent);

                    // 统一使用动态容忍度计算（与 IterativeRefinementController 保持一致）
                    $rwTol = calculateDynamicTolerance($rwTarget);
                    $rwMax = $rwTol['max'];

                    if ($rwWords > $rwMax) {
                        $fullContent = truncateToWordLimit($fullContent, $rwMax);
                        addLog($novelId, 'info', sprintf(
                            'RewriteAgent结果超字（%d字 > %d字，容忍度±%d），已压缩至 %d 字',
                            $rwWords, $rwMax, $rwTol['tolerance'], countWords($fullContent)
                        ));
                    }
                    $newWords = countWords($fullContent);
                    DB::update('chapters', [
                        'content' => $fullContent,
                        'words'   => $newWords,
                    ], 'id=?', [$chId]);
                    addLog($novelId, 'info', sprintf(
                        'RewriteAgent重写已落盘：%d字 → %d字',
                        (int)($chapter['words'] ?? 0), $newWords
                    ));

                    // v1.11.2 Bug #1 修复：重写后重新跑 ingestChapter，
                    // 否则原始内容抽取的 character_card / emotion_history / foreshadowing_items
                    // 与 chapters.content 不一致，导致后续章节 prompt 引用幻象数据。
                    //
                    // v1.11.5 修复：重写前先回滚伏笔提及和金句回调的追踪记录，
                    // 防止 last_mentioned_chapter / last_callback_chapter 指向已删除的内容。
                    // ingestChapter 是幂等的（按章节号 update 而非 insert），重新跑安全。
                    $chNum = (int)($chapter['chapter_number'] ?? 0);
                    try {
                        require_once __DIR__ . '/memory/ForeshadowingRepo.php';
                        $foreshadowRepo = new ForeshadowingRepo($novelId);
                        $revF = $foreshadowRepo->revertMentionsForChapter($chNum);

                        require_once __DIR__ . '/memory/CatchphraseRepo.php';
                        $catchRepo = new CatchphraseRepo($novelId);
                        $revC = $catchRepo->revertCallbacksForChapter($chNum);

                        if ($revF > 0 || $revC > 0) {
                            addLog($novelId, 'info', "重写后回滚追踪：伏笔提及{$revF}条、金句回调{$revC}条");
                        }
                    } catch (Throwable $e) {
                        addLog($novelId, 'warn', '重写后回滚追踪失败：' . $e->getMessage());
                    }
                    try {
                        $newSummary = generateChapterSummary($novelData, $chapter, $fullContent);
                        if (!empty($newSummary)) {
                            // 重新落盘 chapters.chapter_summary / used_tropes / cool_point_type
                            $reUpdates = [];
                            if (!empty($newSummary['narrative_summary'])) {
                                $reUpdates['chapter_summary'] = $newSummary['narrative_summary'];
                            }
                            if (!empty($newSummary['used_tropes'])) {
                                $reUpdates['used_tropes'] = json_encode($newSummary['used_tropes'], JSON_UNESCAPED_UNICODE);
                            }
                            if (!empty($newSummary['cool_point_type'])) {
                                $cpt = trim($newSummary['cool_point_type']);
                                $validCoolTypes = array_keys(COOL_POINT_TYPES);
                                if (in_array($cpt, $validCoolTypes)) {
                                    $reUpdates['cool_point_type'] = $cpt;
                                }
                            }
                            if (!empty($reUpdates)) DB::update('chapters', $reUpdates, 'id=?', [$chId]);

                            // 重新跑记忆引擎入库（人物卡/情绪/伏笔/事件全量同步）
                            $reIngest = $engine->ingestChapter((int)$chapter['chapter_number'], $newSummary);
                            addLog($novelId, 'info', sprintf(
                                '重写后重新入库：人物新增%d/更新%d / 情绪%d / 事件%d / 伏笔+%d/回收%d',
                                $reIngest['cards_inserted'] ?? 0,
                                $reIngest['cards_updated'] ?? 0,
                                $reIngest['emotions_added'] ?? 0,
                                $reIngest['events_added'] ?? 0,
                                $reIngest['foreshadowing_added'] ?? 0,
                                $reIngest['foreshadowing_resolved'] ?? 0
                            ));

                            // 同步刷新 $summaryData 供下文（伏笔追踪/金句/KB）使用
                            $summaryData = $newSummary;

                            // v1.11.5: 重写后基于新内容重新跑伏笔提及和金句回调追踪
                            try {
                                require_once __DIR__ . '/memory/ForeshadowingRepo.php';
                                require_once __DIR__ . '/memory/CatchphraseRepo.php';
                                $reForeshadowRepo = new ForeshadowingRepo($novelId);
                                $reCatchRepo = new CatchphraseRepo($novelId);
                                $reMention = $reForeshadowRepo->trackMentionsInContent($fullContent, $chNum);
                                $reCallback = $reCatchRepo->trackCallbacksInContent($fullContent, $chNum);
                                if ($reMention > 0 || $reCallback > 0) {
                                    addLog($novelId, 'info', "重写后重跑追踪：伏笔提及{$reMention}条、金句回调{$reCallback}条");
                                }
                            } catch (Throwable $et) {
                                addLog($novelId, 'warn', '重写后重跑追踪失败：' . $et->getMessage());
                            }
                        } else {
                            addLog($novelId, 'warn', '重写后摘要生成失败，数据库可能不一致');
                        }
                    } catch (Throwable $e) {
                        addLog($novelId, 'warn', '重写后重新入库失败：' . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                addLog($novelId, 'warn', 'RewriteAgent跳过：' . $e->getMessage());
            }
        }

        // --- v1.9 CriticAgent + StyleGuard — 统一纳入 AgentCoordinator ---
        try {
            require_once __DIR__ . '/agents/AgentCoordinator.php';
            AgentCoordinator::postWriteAgents($novelId, $chapter, $fullContent, [
                'title'            => $novelData['title'] ?? '',
                'genre'            => $novelData['genre'] ?? '',
                'protagonist_name' => $novelData['protagonist_name'] ?? '',
                'model_id'         => $novelData['model_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '后置Agent协调器跳过：' . $e->getMessage());
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

        // --- v1.10.3 情绪曲线异常检测（每10章触发）---
        try {
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            if ($chNum > 0 && $chNum % 10 === 0) {
                $emotionAnomaly = detectEmotionCurveAnomaly($novelId);
                if ($emotionAnomaly) {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    $msg = match ($emotionAnomaly['type']) {
                        'low_emotion_streak' => "近10章情绪均值仅" . round($emotionAnomaly['avg']) . "分，持续低位（建议<50分需干预）。请在本章安排高强度情绪事件（冲突/反转/危机），打破低潮。",
                        'flat_emotion_curve' => "近10章情绪方差仅" . round($emotionAnomaly['variance']) . "，起伏极小，读者疲劳。本章必须有明显的情绪高低峰落差。",
                        default => '情绪曲线异常，请注意情绪节奏。',
                    };
                    AgentDirectives::add(
                        $novelId, $chNum + 1, 'quality',
                        "情绪曲线告警：{$msg}",
                        3, 48
                    );
                    addLog($novelId, 'info', sprintf(
                        '情绪曲线异常检测：%s（均值%.1f，方差%.1f）',
                        $emotionAnomaly['type'], $emotionAnomaly['avg'], $emotionAnomaly['variance']
                    ));
                }
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '情绪曲线异常检测跳过：' . $e->getMessage());
        }

        // --- v1.11.5 角色情绪异常跳变检测（事后检测）---
        try {
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            require_once __DIR__ . '/memory/CharacterEmotionRepo.php';
            $emotionRepo = new CharacterEmotionRepo($novelId);
            $emotionAnomalies = $emotionRepo->detectEmotionAnomalies($chNum);
            if (!empty($emotionAnomalies)) {
                $highAnomalies = array_filter($emotionAnomalies, fn($a) => $a['severity'] === 'high');
                if (!empty($highAnomalies)) {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    $msg = implode('；', array_map(fn($a) => $a['message'], array_slice($highAnomalies, 0, 2)));
                    AgentDirectives::add(
                        $novelId, $chNum + 1, 'quality',
                        "情绪断裂告警：{$msg}。下章请安排合理的情绪过渡或回调。",
                        2, 24
                    );
                }
                addLog($novelId, 'info', sprintf(
                    '情绪异常跳变检测：%d项异常（高危%d）',
                    count($emotionAnomalies),
                    count($highAnomalies)
                ));
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '情绪异常跳变检测跳过：' . $e->getMessage());
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

        // --- v1.6 开篇类型实际检测（P1#7: 反馈闭环）---
        // 检测正文实际使用的开篇类型，与 suggestOpeningType 建议的 opening_type 对比
        // 可发现 AI 写作时是否偏离了推荐的开篇策略
        try {
            $actualOpening = detectOpeningType($fullContent);
            if (!empty($actualOpening['type'])) {
                DB::update('chapters', [
                    'actual_opening_type' => $actualOpening['type']
                ], 'id=?', [$chId]);
                addLog($novelId, 'info', sprintf(
                    '开篇检测：识别为 %s（%s）',
                    $actualOpening['type'],
                    OPENING_TYPES[$actualOpening['type']]['name'] ?? $actualOpening['type']
                ));
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '开篇检测跳过：' . $e->getMessage());
        }

        // --- v1.11 场景模板检测（语义级防套路化）---
        try {
            require_once __DIR__ . '/memory/SceneTemplateRepo.php';
            $sceneTemplates = detectSceneTemplates($fullContent);
            if (!empty($sceneTemplates)) {
                $stRepo = new SceneTemplateRepo($novelId);
                $saved = $stRepo->batchAdd($sceneTemplates, (int)$chapter['chapter_number']);
                $tplNames = array_map(fn($t) => SCENE_TEMPLATES[$t]['name'] ?? $t, $sceneTemplates);
                addLog($novelId, 'info', sprintf(
                    '场景模板检测：识别到 %d 种 —— %s（入库%d条）',
                    count($sceneTemplates),
                    implode('、', $tplNames),
                    $saved
                ));
            }
        } catch (Throwable $e) {
            addLog($novelId, 'warn', '场景模板检测跳过：' . $e->getMessage());
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

        // === v41 角色语音指纹生成（每 N 章为缺指纹的主要角色补差异化语音规则）===
        try {
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $vpInterval = max(3, (int)getSystemSetting('ws_voice_profile_interval', 12, 'int'));
            if ($chNum > 0 && getSystemSetting('ws_voice_profile_enabled', true, 'bool')
                && $chNum % $vpInterval === 0) {
                require_once __DIR__ . '/VoiceProfileGenerator.php';
                VoiceProfileGenerator::generateMissing($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '角色语音指纹生成跳过：' . $e->getMessage());
        }

        // === PID控制器（工程控制论：P/I/D整定）===
        // 每章写完后对4个核心控制变量做PID评估，产生智能调控建议
        try {
            require_once __DIR__ . '/PIDController.php';
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $pid = new PIDController($novelId);

            $recentScores = DB::fetchAll(
                'SELECT quality_score, emotion_score FROM chapters
                 WHERE novel_id=? AND chapter_number <= ? AND (quality_score IS NOT NULL OR emotion_score IS NOT NULL)
                 ORDER BY chapter_number DESC LIMIT 1',
                [$novelId, $chNum]
            );
            $currentQuality = $recentScores[0]['quality_score'] ?? null;
            $currentEmotion = $recentScores[0]['emotion_score'] ?? null;

            $targetWords = (int)($chapter['words'] ?? 0) > 0
                ? (int)(DB::fetch('SELECT chapter_words FROM novels WHERE id=?', [$novelId])['chapter_words'] ?? 2000)
                : 0;
            $actualWords = (int)($chapter['words'] ?? 0);
            $wordAccuracy = ($targetWords > 0 && $actualWords > 0)
                ? round(min(1.0, $actualWords / $targetWords), 3)
                : null;

            $recentCool = DB::fetchAll(
                'SELECT cool_point_type FROM chapters
                 WHERE novel_id=? AND chapter_number BETWEEN ? AND ? AND status="completed"
                 ORDER BY chapter_number DESC LIMIT 10',
                [$novelId, max(1, $chNum - 9), $chNum]
            );
            $coolCount = 0;
            foreach ($recentCool as $rc) {
                if (!empty($rc['cool_point_type'])) $coolCount++;
            }
            $coolDensity = count($recentCool) > 0
                ? round($coolCount / count($recentCool), 3)
                : null;

            $pidResults = $pid->evaluateAll([
                'quality_score'  => $currentQuality,
                'emotion_score'  => $currentEmotion,
                'word_count_accuracy' => $wordAccuracy,
                'cool_point_density'  => $coolDensity,
            ]);

            $criticalIssues = array_filter($pidResults, fn($r) => $r['severity'] === 'critical');
            if (!empty($criticalIssues)) {
                $vars = array_keys($criticalIssues);
                $msgs = array_map(fn($v, $r) => $r['recommendation'], $vars, $criticalIssues);
                require_once __DIR__ . '/agents/AgentDirectives.php';
                AgentDirectives::add(
                    $novelId, $chNum + 1, 'quality',
                    implode('；', $msgs),
                    2, 24
                );
                addLog($novelId, 'info', sprintf(
                    'PID控制器：%d项严重偏差，已写入指令',
                    count($criticalIssues)
                ));
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', 'PID控制器跳过：' . $e->getMessage());
        }

        // === 参数自适应调优（工程控制论：自适应控制）===
        // 每 10 章分析历史数据，自动调整迭代参数
        if ($chNum > 0 && $chNum % 10 === 0) {
            try {
                require_once __DIR__ . '/AdaptiveParameterTuner.php';
                $tuner = new AdaptiveParameterTuner($novelId);
                $tuner->tune($chNum);
            } catch (\Throwable $e) {
                addLog($novelId, 'warn', '参数自适应调优跳过：' . $e->getMessage());
            }
        }

        // === 全书级控制器（工程控制论：层级控制）===
        // 每 20 章触发一次，做全书级5项检查
        try {
            require_once __DIR__ . '/GlobalNovelController.php';
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $globalResult = GlobalNovelController::regulate(
                $novelId,
                $chNum,
                $novelData['model_id'] ?? null
            );
            if ($globalResult['triggered']) {
                $directivesWritten = $globalResult['directives'];
                $checkSummary = [];
                foreach ($globalResult['checks'] as $name => $check) {
                    if (!empty($check['issue'])) {
                        $checkSummary[] = $name . ':有问题';
                    } elseif ($check['checked'] ?? false) {
                        $checkSummary[] = $name . ':正常';
                    }
                }
                addLog($novelId, 'info', sprintf(
                    '全书级控制器执行：%d项检查，%d条全局指令（%s）',
                    count($globalResult['checks']),
                    $directivesWritten,
                    implode(', ', $checkSummary)
                ));
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '全书级控制器跳过：' . $e->getMessage());
        }

        // === 系统健康监控（工程控制论：鲁棒性）===
        // 每 20 章执行一次系统体检
        if ($chNum > 0 && $chNum % 20 === 0) {
            try {
                require_once __DIR__ . '/SystemHealthMonitor.php';
                $healthMonitor = new SystemHealthMonitor($novelId);
                $healthResult = $healthMonitor->check();
                if ($healthResult['score'] < 70) {
                    foreach ($healthResult['alerts'] as $alert) {
                        addLog($novelId, 'warn', sprintf(
                            '系统健康告警[%s]：%s',
                            $alert['level'] ?? 'warning',
                            $alert['message']
                        ));
                    }
                }
                if (!empty($healthResult['alerts'])) {
                    addLog($novelId, 'info', sprintf(
                        '系统健康分：%d/100，%d条告警',
                        $healthResult['score'], count($healthResult['alerts'])
                    ));
                }
            } catch (\Throwable $e) {
                addLog($novelId, 'warn', '系统健康监控跳过：' . $e->getMessage());
            }
        }

        // v2: 清除「后处理进行中」标志，放行下一章写作
        // 注意：放在全书圣经之前——圣经允许滞后一章，不阻塞下一章开写。
        @unlink(self::postProcessPendingFlag($novelId, (int)$chapter['chapter_number']));

        // === v41 全书圣经（每 N 章增量压缩，根治中段遗忘）===
        try {
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $bibleInterval = max(5, (int)getSystemSetting('ws_bible_interval', 20, 'int'));
            if ($chNum > 0 && getSystemSetting('ws_story_bible_enabled', true, 'bool')
                && $chNum % $bibleInterval === 0) {
                require_once __DIR__ . '/memory/StoryBible.php';
                StoryBible::regenerate($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '全书圣经更新跳过：' . $e->getMessage());
        }

        // === v41 全书一致性体检（每 N 章一次的宏观审计，存报告供作者查看）===
        try {
            $chNum = (int)($chapter['chapter_number'] ?? 0);
            $auditInterval = max(10, (int)getSystemSetting('ws_fullbook_audit_interval', 50, 'int'));
            if ($chNum > 0 && getSystemSetting('ws_fullbook_audit_enabled', true, 'bool')
                && $chNum % $auditInterval === 0) {
                require_once __DIR__ . '/FullBookAudit.php';
                FullBookAudit::run($novelId, $chNum, $novelData['model_id'] ?? null);
            }
        } catch (\Throwable $e) {
            addLog($novelId, 'warn', '全书体检跳过：' . $e->getMessage());
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
            if (!ConfigCenter::get('agent.enabled', true)) {
                return;
            }

            $chNum = self::getCurrentChapterNumber($novelId);

            if ($chNum <= 5) {
                return;
            }

            $anyTrigger = false;
            $baseIntervals = [5, 10, 20];
            foreach ($baseIntervals as $interval) {
                if ($chNum % $interval === 0) {
                    $anyTrigger = true;
                    break;
                }
            }
            if (!$anyTrigger) {
                return;
            }

            require_once __DIR__ . '/agents/AgentCoordinator.php';

            $coordinator = new AgentCoordinator($novelId);

            $context = [
                'pending_foreshadowing_count' => self::countPendingForeshadowings($novelId),
                'recent_chapters' => self::getRecentChapters($novelId, 5),
                'current_progress' => self::getCurrentProgress($novelId),
                'current_chapter_number' => $chNum,
            ];

            $decisionResult = $coordinator->runDecisionCycle($context);

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
            // 使用 MAX(chapter_number)+1 而非 COUNT(completed)+1
            // 避免存在 skipped/failed 章节时章节号错位
            $chapter = DB::fetch(
                'SELECT COALESCE(MAX(chapter_number), 0) + 1 as next_chapter FROM chapters WHERE novel_id = ?',
                [$novelId]
            );
            
            return (int)($chapter['next_chapter'] ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    /**
     * 降级摘要：AI摘要失败时，从大纲和正文提取最基本的记忆数据
     *
     * 保证 ingestChapter 至少拿到 key_event + narrative_summary + story_momentum，
     * 避免整条记忆链断裂。人物更新/伏笔/爽点等深度分析字段留空。
     */
    private static function buildFallbackSummary(array $chapter, string $fullContent): array
    {
        $outline = trim((string)($chapter['outline'] ?? ''));
        $title = trim((string)($chapter['title'] ?? ''));
        $chNum = (int)($chapter['chapter_number'] ?? 0);

        $narrativeSummary = $outline ?: safe_substr(trim($fullContent), 0, 200) . '…';

        $keyEvent = $outline ?: $title;
        if (mb_strlen($keyEvent) > 20) {
            $keyEvent = safe_substr($keyEvent, 0, 20);
        }

        $momentum = '';
        if (!empty($outline)) {
            $momentum = safe_substr($outline, 0, 30);
        }

        return [
            'narrative_summary'      => $narrativeSummary,
            'character_updates'      => [],
            'character_traits'       => [],
            'key_event'              => $keyEvent,
            'used_tropes'            => [],
            'new_foreshadowing'      => [],
            'resolved_foreshadowing' => [],
            'story_momentum'         => $momentum,
            'cool_point_type'        => '',
            'character_emotions'     => [],
        ];
    }
}
