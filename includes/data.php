<?php
defined('APP_LOADED') or die('Direct access denied.');

// ================================================================
// data.php — 数据访问层（仅操作数据库，不调用 AI）
// 包含：小说/章节读写、日志、人物状态、伏笔追踪、版本管理
// ================================================================

// ----------------------------------------------------------------
// 基础读取
// ----------------------------------------------------------------

function getNovel(int $id): array|false {
    return DB::fetch('SELECT * FROM novels WHERE id=?', [$id]);
}

function getChapter(int $id): array|false {
    return DB::fetch('SELECT * FROM chapters WHERE id=?', [$id]);
}

function getNovelChapters(int $novelId): array {
    return DB::fetchAll(
        'SELECT * FROM chapters WHERE novel_id=? ORDER BY chapter_number ASC',
        [$novelId]
    );
}

// ----------------------------------------------------------------
// 统计 & 日志
// ----------------------------------------------------------------

/**
 * 重新计算并更新小说的已完成章数 / 总字数
 */
function updateNovelStats(int $novelId): void {
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

/**
 * 写入写作日志，并自动保留最新 200 条（防止无限膨胀）
 */
function addLog(int $novelId, string $action, string $message, ?int $chapterId = null): void {
    DB::insert('writing_logs', [
        'novel_id'   => $novelId,
        'chapter_id' => $chapterId,
        'action'     => $action,
        'message'    => $message,
    ]);
    // 每部小说只保留最近 200 条日志，多余的自动删除
    DB::execute(
        'DELETE FROM writing_logs WHERE novel_id=? AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM writing_logs WHERE novel_id=? ORDER BY id DESC LIMIT 200
            ) t
        )',
        [$novelId, $novelId]
    );
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
 * @return mixed
 */
function getSetting(string $key, $default = null, int $novelId = 0) {
    try {
        if (!class_exists('DB', false)) {
            return $default;
        }

        $parts = explode('.', $key, 2);
        $settingKey = $parts[0];
        $subKey = $parts[1] ?? null;

        // 优先读取小说级设置，再读全局
        $row = DB::fetch(
            'SELECT setting_value FROM iterative_settings WHERE novel_id = ? AND setting_key = ?',
            [$novelId, $settingKey]
        );

        if (!$row && $novelId > 0) {
            $row = DB::fetch(
                'SELECT setting_value FROM iterative_settings WHERE novel_id = 0 AND setting_key = ?',
                [$settingKey]
            );
        }

        if (!$row) {
            return $default;
        }

        $values = json_decode($row['setting_value'], true, 512);
        if (!is_array($values)) {
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('getSetting JSON解析失败: ' . json_last_error_msg() . ' - key: ' . $key);
            }
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

/**
 * 获取章节的版本历史（只含元数据，不含正文）
 */
function getChapterVersions(int $chapterId): array {
    return DB::fetchAll(
        'SELECT id, version, words, created_at FROM chapter_versions
         WHERE chapter_id=? ORDER BY version DESC',
        [$chapterId]
    );
}

// ----------------------------------------------------------------
// 伏笔追踪
//
// v6 说明：旧的 foreshadowing_log 表 + logForeshadowing() + getForeshadowingStatus()
// 以及 updateNovelMeta() 已全部移除。现在统一由 MemoryEngine 管理：
//   - 埋设：MemoryEngine::ingestChapter() → ForeshadowingRepo::plant()
//   - 回收：ForeshadowingRepo::tryResolve()
//   - 查询：ForeshadowingRepo::status() / listPending() / listOverdue() / listDueSoon()
// ----------------------------------------------------------------
