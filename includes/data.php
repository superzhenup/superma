<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/cache.php';

// ================================================================
// data.php — 数据访问层（仅操作数据库，不调用 AI）
// 包含：小说/章节读写、日志、人物状态、伏笔追踪、版本管理
// ================================================================

// ----------------------------------------------------------------
// 缓存管理
//
// [v42] 请求级运行时缓存（审计 2026-06-10 快照 P1-A/P1-B，台账条目 7/23）：
// getNovel / getChapter / getNovelChapters(:all) / getNovelChapterCount 原先经
// Cache（文件/APCu）跨请求缓存 300s，但 chapters/novels 的写点遍布 80+ 处，
// 逐点失效不可维护，实际造成「保存章节后 5 分钟内读到旧正文」「版本恢复看似
// 不生效」「导入后列表滞后」类缺陷。这些读取均为主键/索引查询（毫秒级），
// 跨请求缓存收益接近零；改为仅单请求内复用——请求结束自动释放，写点不再
// 需要跨请求失效。clearNovelCache / clearChapterCache 仍保留：负责清理本请求
// 内存值，并顺带删除升级前可能残留的跨请求缓存条目。
// ----------------------------------------------------------------

/**
 * 请求级运行时缓存存储（键名沿用历史缓存键，便于排查与测试断言）
 *
 * 审计修复 H-1（2026-07-20）：原实现在条目超过 64 时全量清空缓存，CLI worker
 * 连续写多章时会导致前 63 章的小说/章节列表/计数器缓存全部失效，每写一章需
 * 重新查询 DB。
 *
 * 修复策略：
 *   1. 上限提升至 128（千章书每章最多产生 1 条 chapter:N 缓存）
 *   2. 超限时仅淘汰一半最早条目而不全量清空
 *   3. PHP 关联数组天然保持插入顺序，直接用 array_keys 获取 FIFO 序列
 *   4. 复用同一 static 的 $order 列表追踪写入顺序
 */
function &novelRuntimeCache(): array
{
    static $cache = [];
    static $maxSize = 128; // 从 64 提升至 128

    if (count($cache) > $maxSize) {
        $allKeys = array_keys($cache);
        $evictCount = (int)(count($allKeys) / 2);
        $evictKeys = array_slice($allKeys, 0, $evictCount);
        foreach ($evictKeys as $key) {
            unset($cache[$key]);
        }
    }
    return $cache;
}

/**
 * 向运行时缓存写入条目。
 * 与直接 $rt[$key] = $value 等价，但同时更新 FIFO 淘汰所需的插入序。
 * 直接赋值的写点（跨函数 static 无法共享）仍然工作，只是淘汰序可能
 * 不完全精确——不影响正确性，仅影响"保留哪些条目"的最优性。
 *
 * 审计修复 H-1（2026-07-20）：新增。
 */
function novelRuntimeCacheSet(string $key, $value): void
{
    $rt = &novelRuntimeCache();
    // 先删除旧键再写入，使键在 PHP 内部数组序中位于末尾 (FIFO)
    unset($rt[$key]);
    $rt[$key] = $value;
}

/**
 * 清除指定小说的所有相关缓存
 *
 * @param int $novelId 小说ID
 */
function clearNovelCache(int $novelId): void
{
    $rt = &novelRuntimeCache();
    unset(
        $rt["novel:{$novelId}"],
        $rt["novel_chapters:{$novelId}:all"],
        $rt["novel_chapter_count:{$novelId}"],
        $rt["novel_settings:{$novelId}"]
    );

    // 历史版本曾把以下条目写入跨请求缓存（文件/APCu），升级后可能仍有残留，顺带清理
    Cache::delete("novel:{$novelId}");
    Cache::delete("novel_chapters:{$novelId}:all");
    Cache::delete("novel_chapter_count:{$novelId}");
    Cache::delete("novel_settings:{$novelId}");
}

/**
 * 清除指定章节的缓存
 *
 * @param int $chapterId 章节ID
 * @param int $novelId 小说ID（用于清除章节列表缓存）
 */
function clearChapterCache(int $chapterId, int $novelId): void
{
    $rt = &novelRuntimeCache();
    unset($rt["chapter:{$chapterId}"]);
    if ($novelId > 0) {
        unset($rt["novel_chapters:{$novelId}:all"], $rt["novel_chapter_count:{$novelId}"]);
    }

    // 升级前残留的跨请求缓存条目
    Cache::delete("chapter:{$chapterId}");
    if ($novelId > 0) {
        Cache::delete("novel_chapters:{$novelId}:all");
    }
}

/**
 * 更新章节数据并自动清除缓存
 *
 * 审计修复 A07（2026-06-16）：增加可选 $backupVersion 参数，统一封装 chapter_versions 备份逻辑。
 * 之前 chapter_actions.php 的 clear_content 手写 backup_chapter_detail_version()，
 * 而 write_engine.php 的 saveChapter 又另写一套备份代码，策略分散。
 * 现统一通过本函数入口，调用方按需启用备份。
 *
 * @param int $chapterId 章节ID
 * @param int $novelId 小说ID
 * @param array $data 要更新的数据
 * @param bool $backupVersion 是否在更新前备份当前章节到 chapter_versions（默认 false）
 * @return int 受影响的行数
 */
function updateChapter(int $chapterId, int $novelId, array $data, bool $backupVersion = false): int {
    // 审计修复 A07：更新前备份（若启用）
    if ($backupVersion) {
        backupChapterVersion($chapterId);
    }

    $affected = DB::update('chapters', $data, 'id=?', [$chapterId]);

    // 清除相关缓存
    if ($affected > 0) {
        clearChapterCache($chapterId, $novelId);
    }

    return $affected;
}

/**
 * 备份章节当前内容到 chapter_versions 表
 *
 * 审计修复 A07（2026-06-16）：从 chapter_actions.php 的 backup_chapter_detail_version
 * 和 write_engine.php 的内联备份逻辑中提取，统一备份策略。
 *
 * 备份条件：章节存在 + 内容非空 + 字数超过 CFG_CHAPTER_VERSION_MIN_WORDS（默认 100）
 * 自动保留最新 10 个版本（与 write_engine.php 一致）
 *
 * @param int $chapterId 章节ID
 */
function backupChapterVersion(int $chapterId): void {
    $pdo = DB::getPdo();
    $ownTx = !$pdo->inTransaction();
    static $savepointSequence = 0;
    $savepoint = null;
    $newMaxVersion = 0;

    try {
        if ($ownTx) {
            if (!$pdo->beginTransaction()) {
                throw new \RuntimeException('无法开启章节版本备份事务');
            }
        } else {
            // 调用者（如 save_chapter / regenerate_and_reset）已经开启事务时，
            // 绝不能由本函数 commit/rollback 外层事务。Savepoint 仅隔离本函数失败。
            $savepoint = 'sp_backup_chapter_' . $chapterId . '_' . (++$savepointSequence);
            $pdo->exec("SAVEPOINT `{$savepoint}`");
        }

        // 先锁章节行：既保证备份内容与随后更新前的数据库快照一致，也把同一章节
        // 的并发备份串行化，避免两个请求同时计算出相同的 N+1 版本号。
        $chapter = DB::fetch(
            'SELECT id, content, words, outline, title FROM chapters WHERE id=? FOR UPDATE',
            [$chapterId]
        );
        if (!$chapter) {
            if ($ownTx) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            }
            return;
        }

        $content = (string)($chapter['content'] ?? '');
        $words = (int)($chapter['words'] ?? 0);
        $minWords = defined('CFG_CHAPTER_VERSION_MIN_WORDS') ? max(0, (int)CFG_CHAPTER_VERSION_MIN_WORDS) : 100;

        // 内容为空或字数过少不备份（与 write_engine.php 的 $oldWords > 100 阈值一致）
        if ($content === '' || $words <= $minWords) {
            if ($ownTx) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            }
            return;
        }

        $maxVersion = (int)(DB::fetch(
            'SELECT COALESCE(MAX(version), 0) AS v FROM chapter_versions WHERE chapter_id=?',
            [$chapterId]
        )['v'] ?? 0);

        $newMaxVersion = $maxVersion + 1;
        DB::insert('chapter_versions', [
            'chapter_id' => $chapterId,
            'version'    => $newMaxVersion,
            'content'    => $content,
            'outline'    => $chapter['outline'] ?? '',
            'title'      => $chapter['title'] ?? '',
            'words'      => $words,
        ]);

        if ($ownTx) {
            $pdo->commit();
        } elseif ($savepoint !== null) {
            $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
        }
    } catch (\Throwable $e) {
        try {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownTx && $savepoint !== null && $pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
                $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
            }
        } catch (\Throwable $rollbackError) {
            error_log("backupChapterVersion({$chapterId}) 回滚失败: " . $rollbackError->getMessage());
        }
        error_log("backupChapterVersion({$chapterId}) 失败: " . $e->getMessage());
        return;
    }

    // 保留最新 10 个版本（与 write_engine.php 一致）—— 放在事务外，失败不影响备份
    // 审计修复 PERF-H1（2026-07-01）：原实现用 `id NOT IN (子查询 ORDER BY version DESC LIMIT 10)`
    // 对每行做 NOT IN 匹配，随版本数增长呈 O(n²)。改用 version 列直接范围删除，
    // 走 UNIQUE KEY(chapter_id, version) 的 O(log n) 区间扫描，且无需子查询。
    if ($newMaxVersion > 10) {
        DB::execute(
            'DELETE FROM chapter_versions WHERE chapter_id=? AND version <= ?',
            [$chapterId, $newMaxVersion - 10]
        );
    }
}

// ----------------------------------------------------------------
// 基础读取（带缓存优化）
// ----------------------------------------------------------------

function getNovel(int $id): array|false {
    $cacheKey = "novel:{$id}";
    $rt = &novelRuntimeCache();
    if (array_key_exists($cacheKey, $rt)) {
        return $rt[$cacheKey];
    }

    $novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$id]);

    if ($novel) {
        novelRuntimeCacheSet($cacheKey, $novel);
    }

    return $novel;
}

function getChapter(int $id): array|false {
    $cacheKey = "chapter:{$id}";
    $rt = &novelRuntimeCache();
    if (array_key_exists($cacheKey, $rt)) {
        return $rt[$cacheKey];
    }

    $chapter = DB::fetch('SELECT * FROM chapters WHERE id=?', [$id]);

    if ($chapter) {
        novelRuntimeCacheSet($cacheKey, $chapter);
    }

    return $chapter;
}

function getNovelChapters(int $novelId, int $page = 0, int $pageSize = 0): array {
    // 如果未指定分页，返回所有章节（向后兼容）。
    // [v42] 列裁剪（审计 P1-A）：此分支曾 SELECT *（含正文 LONGTEXT 与十余个 JSON 大列），
    // 千章书一次拉取数十 MB。现与分页分支同列——如需正文请用 getChapter() 或显式查询。
    // [审计优化 P1-3（2026-06-16）] 增加 CFG_CHAPTER_LIST_MAX 硬上限，
    // 超过该上限的章节需通过分页分支获取，避免单次拉取过大。
    if ($pageSize <= 0) {
        $cacheKey = "novel_chapters:{$novelId}:all";
        $rt = &novelRuntimeCache();
        if (array_key_exists($cacheKey, $rt)) {
            return $rt[$cacheKey];
        }

        $limit = defined('CFG_CHAPTER_LIST_MAX') ? max(1, (int)CFG_CHAPTER_LIST_MAX) : 500;
        // 审计修复 L-3（2026-07-20）：LIMIT 改为参数化，与 DB 类安全设计保持一致
        $chapters = DB::fetchAll(
            'SELECT id, novel_id, chapter_number, title, outline, hook, pacing, suspense, status, words, quality_score, synopsis_id, updated_at FROM chapters WHERE novel_id=? ORDER BY chapter_number ASC LIMIT ?',
            [$novelId, $limit]
        );

        novelRuntimeCacheSet($cacheKey, $chapters);

        return $chapters;
    }

    // 分页列表只读取渲染所需字段，避免长篇正文/大 JSON 字段拖慢章节列表
    $offset = ($page - 1) * $pageSize;
    return DB::fetchAll(
        'SELECT id, novel_id, chapter_number, title, outline, hook, pacing, suspense, status, words, quality_score, synopsis_id, updated_at FROM chapters WHERE novel_id=? ORDER BY chapter_number ASC LIMIT ? OFFSET ?',
        [$novelId, $pageSize, $offset]
    );
}

/**
 * 获取小说章节总数（用于分页）
 */
function getNovelChapterCount(int $novelId): int {
    $cacheKey = "novel_chapter_count:{$novelId}";
    $rt = &novelRuntimeCache();
    if (array_key_exists($cacheKey, $rt)) {
        return (int)$rt[$cacheKey];
    }

    $count = (int)DB::fetchColumn(
        'SELECT COUNT(*) FROM chapters WHERE novel_id=?',
        [$novelId]
    );

    novelRuntimeCacheSet($cacheKey, $count);

    return $count;
}

// ----------------------------------------------------------------
// 统计 & 日志
// ----------------------------------------------------------------

/**
 * 更新小说的已完成章数 / 总字数。
 * 优先使用增量更新（原子加减），对大书更高效；
 * 未提供增量时退化为全量重算（作为一致性修复机制）。
 */
function updateNovelStats(int $novelId, ?int $chapterDelta = null, ?int $wordsDelta = null): void {
    if ($chapterDelta !== null || $wordsDelta !== null) {
        $sqlSet = [];
        $params = [];
        if ($chapterDelta !== null) {
            $sqlSet[] = 'current_chapter = GREATEST(0, current_chapter + ?)';
            $params[] = $chapterDelta;
        }
        if ($wordsDelta !== null) {
            $sqlSet[] = 'total_words = GREATEST(0, total_words + ?)';
            $params[] = $wordsDelta;
        }
        $params[] = $novelId;
        try {
            DB::execute('UPDATE novels SET ' . implode(', ', $sqlSet) . ' WHERE id=?', $params);
        } catch (\Throwable $e) {
            // 增量失败退化为全量重算（最终一致性）
            $row = DB::fetch(
                'SELECT COUNT(*) AS cnt, SUM(words) AS total
                 FROM chapters WHERE novel_id=? AND status="completed"',
                [$novelId]
            );
            DB::update('novels', [
                'current_chapter' => (int)($row['cnt']   ?? 0),
                'total_words'     => (int)($row['total'] ?? 0),
            ], 'id=?', [$novelId]);
        }
    } else {
        // 全量重算（用于一致性修复 / 删除章节 / 重跑章节等场景）
        $row = DB::fetch(
            'SELECT COUNT(*) AS cnt, SUM(words) AS total
             FROM chapters WHERE novel_id=? AND status="completed"',
            [$novelId]
        );
        DB::update('novels', [
            'current_chapter' => (int)($row['cnt']   ?? 0),
            'total_words'     => (int)($row['total'] ?? 0),
        ], 'id=?', [$novelId]);
    }

    // 清除小说缓存（因为统计信息已变更）
    clearNovelCache($novelId);
}

/**
 * 写入写作日志，并自动保留最新 200 条（防止无限膨胀）
 */
function addLog(int $novelId, string $action, string $message, ?int $chapterId = null): void {
    $insertId = (int)DB::insert('writing_logs', [
        'novel_id'   => $novelId,
        'chapter_id' => $chapterId,
        'action'     => $action,
        'message'    => $message,
    ]);
    // [v41] 1000章优化：原先每条日志都跑一次带子查询的 DELETE（postProcess 每章调用 30+ 次，
    // 1000 章即 3 万次无谓删除）。改为每约 25 条才裁剪一次，保留上限放宽到 220（200+缓冲）。
    // 用插入 id 取模触发，确定性、无随机、与日志量自然挂钩。
    if ($insertId > 0 && $insertId % 25 === 0) {
        DB::execute(
            'DELETE FROM writing_logs WHERE novel_id=? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM writing_logs WHERE novel_id=? ORDER BY id DESC LIMIT 200
                ) t
            )',
            [$novelId, $novelId]
        );
    }
}

// ----------------------------------------------------------------
// 迭代改进设置读取（iterative_settings 表）
// ----------------------------------------------------------------

/**
 * 从 iterative_settings 表读取设置值（支持点号分隔的嵌套键）
 *
 * 用法：
 *   getSetting('iterative_refinement.max_iterations', 3)
 *   → 查询 setting_key='iterative_refinement'，取 JSON 中 max_iterations 字段
 *
 *   getSetting('rewrite.threshold', 70)
 *   → 查询 setting_key='rewrite'，取 JSON 中 threshold 字段
 *
 * @param string $key   点号分隔的键（setting_key.sub_key）
 * @param mixed  $default 默认值
 * @param int    $novelId 小说ID，0=全局
 * @param bool   $reset   内部用：true 时清空请求级缓存（供 clearGetSettingCache 调用）
 * @return mixed
 */
function getSetting(string $key, $default = null, int $novelId = 0, bool $reset = false) {
    // 修复：加请求级缓存（2026-07-22）。RewriteAgent/IterativeRefinementController
    // 每次实例化共调用 8 次 getSetting，原先每次调用产生 1-2 条 iterative_settings SQL。
    // 缓存键 = "novelId|settingKey"（novelId=0 全局与 >0 小说级语义不同，须完整区分）；
    // 缓存值 = 该设置行的解析结果（小说级无效时已含全局回退；null = 无有效行）。
    // 子键提取与 $default 回退不入缓存、每次现算，避免不同默认值/子键的调用互相污染。
    static $rowCache = [];
    if ($reset) { // 仿 _systemSettingsAll(true) 模式：写入方经 clearGetSettingCache 失效缓存
        $rowCache = [];
        return null;
    }
    try {
        if (!class_exists('DB', false)) {
            return $default;
        }

        $parts = explode('.', $key, 2);
        $settingKey = $parts[0];
        $subKey = $parts[1] ?? null;

        $rowCacheKey = $novelId . '|' . $settingKey;
        if (!array_key_exists($rowCacheKey, $rowCache)) {
            $rowCache[$rowCacheKey] = _getSettingFetchValues($settingKey, $novelId, $key);
        }
        $values = $rowCache[$rowCacheKey];

        if ($values === null) {
            return $default;
        }

        if ($subKey !== null) {
            return array_key_exists($subKey, $values) ? $values[$subKey] : $default;
        }

        return $values;
    } catch (\Throwable $e) {
        error_log('getSetting 失败：' . $e->getMessage());
        return $default;
    }
}

/**
 * iterative_settings 写入成功后调用：清空 getSetting 请求级缓存（2026-07-22 配套）。
 * 写入方（api/iterative_config.php saveSetting、AdaptiveParameterTuner setExecSetting）
 * 按整行写入，与读取方的子键粒度不同，逐个精确失效易遗漏；
 * 整清代价极低，仅影响当前请求后续读取。
 */
function clearGetSettingCache(): void {
    getSetting('', null, 0, true);
}

/**
 * getSetting 底层读取（不含缓存）：解析 iterative_settings 行，
 * 返回 values 数组；无有效行（小说级无效且全局回退也无效）时返回 null。
 */
function _getSettingFetchValues(string $settingKey, int $novelId, string $fullKey): ?array {
    // 优先读取小说级设置
    $row = DB::fetch(
        'SELECT setting_value FROM iterative_settings WHERE novel_id = ? AND setting_key = ?',
        [$novelId, $settingKey]
    );

    // M-2 修复（2026-07-20）：小说级 setting_value 为空/NULL/无效 JSON 时，
    // 回退到全局设置，避免空值覆盖全局默认。
    $novelValueValid = false;
    $values = null;
    if ($row) {
        $rawValue = $row['setting_value'];
        // M-4 修复（2026-07-20）：区分 JSON 字面量 null 与 SQL NULL。
        // json_decode('null') → null 且 json_last_error() === JSON_ERROR_NONE，
        // 与 "行不存在"无法区分。显式检查原始值是否为 'null' 字符串。
        if ($rawValue === null || $rawValue === '' || $rawValue === 'null') {
            // 空值/NULL/JSON-null → 视为无效，回退到全局
        } else {
            $values = json_decode($rawValue, true, 512);
            if (is_array($values)) {
                $novelValueValid = true;
            } elseif (json_last_error() !== JSON_ERROR_NONE) {
                error_log('getSetting JSON解析失败: ' . json_last_error_msg() . ' - key: ' . $fullKey);
            }
        }
    }

    // 小说级无效时回退到全局
    if (!$novelValueValid && $novelId > 0) {
        $row = DB::fetch(
            'SELECT setting_value FROM iterative_settings WHERE novel_id = 0 AND setting_key = ?',
            [$settingKey]
        );
        if ($row) {
            $rawValue = $row['setting_value'];
            if ($rawValue === null || $rawValue === '' || $rawValue === 'null') {
                return null;
            }
            $values = json_decode($rawValue, true, 512);
            if (!is_array($values)) {
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('getSetting JSON解析失败(global): ' . json_last_error_msg() . ' - key: ' . $fullKey);
                }
                return null;
            }
        } else {
            return null;
        }
    }

    if (!$novelValueValid && $novelId === 0 && $values === null) {
        return null;
    }

    return $values;
}

// ----------------------------------------------------------------
// 章节意象（from chapters.used_tropes）
//
// 历史说明：人物状态 / 关键事件 / 待回收伏笔 / 故事势能 这 4 类记忆
// 在 v6 已迁移到 MemoryEngine 的专用表，老函数
// getCharacterStates / getKeyEvents / getPendingForeshadowing / getStoryMomentum
// 以及写入函数 updateNovelMeta / logForeshadowing 已移除。
// 上述数据请通过 MemoryEngine::getPromptContext() / ingestChapter() 访问。
// ----------------------------------------------------------------

/**
 * 获取近 $lookback 章已使用的意象/场景关键词（用于防止场景模板化）
 */
function getPreviousUsedTropes(int $novelId, int $currentChapterNumber, int $lookback = 5): array {
    if ($currentChapterNumber <= 1) return [];
    $from = max(1, $currentChapterNumber - $lookback);
    $rows = DB::fetchAll(
        'SELECT used_tropes FROM chapters
         WHERE novel_id=? AND chapter_number>=? AND chapter_number<?
           AND status="completed" AND used_tropes IS NOT NULL',
        [$novelId, $from, $currentChapterNumber]
    );
    $all = [];
    foreach ($rows as $r) {
        $tropes = json_decode($r['used_tropes'] ?? '[]', true, 128) ?: [];
        $all    = array_merge($all, $tropes);
    }
    return array_values(array_unique($all));
}

// ----------------------------------------------------------------
// 上下文获取（用于构建 Prompt）
// ----------------------------------------------------------------

/**
 * 获取前情摘要：近 $lookback 章详细摘要 + 全书关键事件
 */
/**
 * 获取前情摘要：近 $lookback 章详细摘要。
 *
 * 注意：全书关键事件已由 MemoryEngine::getPromptContext() 通过 memoryCtx['key_events']
 * 在 buildChapterPrompt 里独立注入，这里不再拼接，避免重复。
 */
function getPreviousSummary(int $novelId, int $currentChapterNumber, int $lookback = 5): string {
    if ($currentChapterNumber <= 1) return '';

    $from = max(1, $currentChapterNumber - $lookback);
    $rows = DB::fetchAll(
        'SELECT chapter_number, title, outline, chapter_summary FROM chapters
         WHERE novel_id=? AND chapter_number>=? AND chapter_number<? AND status="completed"
         ORDER BY chapter_number DESC',
        [$novelId, $from, $currentChapterNumber]
    );

    if (!$rows) return '';

    $lines = [];
    foreach (array_reverse($rows) as $r) {
        $summary = $r['chapter_summary'] ?: $r['outline'];
        $chNum = $r['chapter_number'] ?? $r['chapter'] ?? 0;
        $lines[] = "第{$chNum}章《{$r['title']}》：{$summary}";
    }
    return "【近期章节摘要】\n" . implode("\n", $lines);
}

/**
 * 获取前一章的尾部原文（$tailChars 字），用于微观衔接
 * 改进：如果前一章不存在或未完成，向前搜索最近的已完成章节
 */
function getPreviousTail(int $novelId, int $currentChapterNumber, int $tailChars = 800): string {
    if ($currentChapterNumber <= 1) return '';
    
    // 先尝试直接获取前一章
    $prev = DB::fetch(
        'SELECT content FROM chapters
         WHERE novel_id=? AND chapter_number=? AND status="completed" LIMIT 1',
        [$novelId, $currentChapterNumber - 1]
    );
    
    // 如果前一章不存在或未完成，向前搜索最近的已完成章节
    if (!$prev || empty($prev['content'])) {
        $prev = DB::fetch(
            'SELECT content FROM chapters
             WHERE novel_id=? AND chapter_number<? AND status="completed"
             ORDER BY chapter_number DESC LIMIT 1',
            [$novelId, $currentChapterNumber]
        );
    }
    
    if (!$prev || empty($prev['content'])) return '';
    $content = trim($prev['content']);
    $len     = safe_strlen($content);
    if ($len <= $tailChars) return $content;
    return safe_substr($content, $len - $tailChars);
}

/**
 * 分层上下文获取（L2 弧段 + L3 近章大纲 + L4 前章尾文）
 * 用于需要全面上下文时的综合组装
 */
function getLayeredContext(int $novelId, int $chapterNumber): array {
    // L2：弧段摘要（当前弧段 + 前一弧段）
    $arcSummaries = getArcSummaries($novelId);
    $currentArc   = (int)ceil($chapterNumber / 10);
    $relevantArcs = array_values(array_filter(
        $arcSummaries,
        fn($arc) => $arc['arc_index'] >= $currentArc - 1 && $arc['arc_index'] <= $currentArc
    ));

    // L3：近5章微观上下文
    $recentOutlines = array_reverse(DB::fetchAll(
        'SELECT chapter_number, title, outline, hook, chapter_summary FROM chapters
         WHERE novel_id=? AND chapter_number < ? AND status="completed"
         ORDER BY chapter_number DESC LIMIT 5',
        [$novelId, $chapterNumber]
    ));

    // L4：前章尾部原文
    $tailContent = getPreviousTail($novelId, $chapterNumber, 600);

    return [
        'arcs'          => $relevantArcs,
        'recent'        => $recentOutlines,
        'tail'          => $tailContent,
        'total_estimate' => safe_strlen($tailContent)
            + array_sum(array_map(fn($a) => safe_strlen($a['summary']), $relevantArcs))
            + array_sum(array_map(fn($r) => safe_strlen($r['outline'] ?? ''), $recentOutlines)),
    ];
}

// ----------------------------------------------------------------
// 弧段摘要（Arc Summary）
// ----------------------------------------------------------------

/**
 * 获取所有弧段摘要（按弧段编号正序），用于大纲/正文生成时注入全局记忆
 */
function getArcSummaries(int $novelId): array {
    return DB::fetchAll(
        'SELECT arc_index, chapter_from, chapter_to, summary
         FROM arc_summaries WHERE novel_id=? ORDER BY arc_index ASC',
        [$novelId]
    );
}

// ----------------------------------------------------------------
// 版本管理
// ----------------------------------------------------------------

// ----------------------------------------------------------------
// 伏笔追踪
//
// v6 说明：旧的 foreshadowing_log 表 + logForeshadowing() + getForeshadowingStatus()
// 以及 updateNovelMeta() 已全部移除。现在统一由 MemoryEngine 管理：
//   - 埋设：MemoryEngine::ingestChapter() → ForeshadowingRepo::plant()
//   - 回收：ForeshadowingRepo::tryResolve()
//   - 查询：ForeshadowingRepo::status() / listPending() / listOverdue() / listDueSoon()
// ----------------------------------------------------------------
