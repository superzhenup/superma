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
// 人物状态 & 关键事件
// ----------------------------------------------------------------

function getCharacterStates(int $novelId): array {
    $novel = DB::fetch('SELECT character_states FROM novels WHERE id=?', [$novelId]);
    if (!$novel || empty($novel['character_states'])) return [];
    $states = json_decode($novel['character_states'], true);
    return is_array($states) ? $states : [];
}

/**
 * 获取全书关键事件（最多取 $limit 条）
 */
function getKeyEvents(int $novelId, int $limit = 50): array {
    $novel = DB::fetch('SELECT key_events FROM novels WHERE id=?', [$novelId]);
    if (!$novel || empty($novel['key_events'])) return [];
    $events = json_decode($novel['key_events'], true);
    if (!is_array($events)) return [];
    return array_slice($events, -$limit);
}

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
        $tropes = json_decode($r['used_tropes'] ?? '[]', true) ?: [];
        $all    = array_merge($all, $tropes);
    }
    return array_values(array_unique($all));
}

/**
 * 获取待回收伏笔列表
 */
function getPendingForeshadowing(int $novelId): array {
    $novel = DB::fetch('SELECT pending_foreshadowing FROM novels WHERE id=?', [$novelId]);
    if (!$novel || empty($novel['pending_foreshadowing'])) return [];
    $list = json_decode($novel['pending_foreshadowing'], true);
    return is_array($list) ? $list : [];
}

/**
 * 获取当前故事势能（悬念/冲突状态摘要）
 */
function getStoryMomentum(int $novelId): string {
    $novel = DB::fetch('SELECT story_momentum FROM novels WHERE id=?', [$novelId]);
    if (!$novel || empty($novel['story_momentum'])) return '';
    return (string)$novel['story_momentum'];
}

// ----------------------------------------------------------------
// 上下文获取（用于构建 Prompt）
// ----------------------------------------------------------------

/**
 * 获取前情摘要：近 $lookback 章详细摘要 + 全书关键事件
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

    $parts = [];

    if ($rows) {
        $lines = [];
        foreach (array_reverse($rows) as $r) {
            $summary = $r['chapter_summary'] ?: $r['outline'];
            $lines[] = "第{$r['chapter_number']}章《{$r['title']}》：{$summary}";
        }
        $parts[] = "【近期章节摘要】\n" . implode("\n", $lines);
    }

    $keyEvents = getKeyEvents($novelId);
    if (!empty($keyEvents)) {
        $lines = [];
        foreach ($keyEvents as $e) {
            $lines[] = "第{$e['chapter']}章：{$e['event']}";
        }
        $parts[] = "【全书关键事件一览】\n" . implode("\n", $lines);
    }

    return implode("\n\n", $parts);
}

/**
 * 获取前一章的尾部原文（$tailChars 字），用于微观衔接
 */
function getPreviousTail(int $novelId, int $currentChapterNumber, int $tailChars = 800): string {
    if ($currentChapterNumber <= 1) return '';
    $prev = DB::fetch(
        'SELECT content FROM chapters
         WHERE novel_id=? AND chapter_number=? AND status="completed" LIMIT 1',
        [$novelId, $currentChapterNumber - 1]
    );
    if (!$prev || empty($prev['content'])) return '';
    $content = trim($prev['content']);
    $len     = mb_strlen($content);
    if ($len <= $tailChars) return $content;
    return mb_substr($content, $len - $tailChars);
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
        'total_estimate' => mb_strlen($tailContent)
            + array_sum(array_map(fn($a) => mb_strlen($a['summary']), $relevantArcs))
            + array_sum(array_map(fn($r) => mb_strlen($r['outline'] ?? ''), $recentOutlines)),
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
// ----------------------------------------------------------------

/**
 * 记录伏笔埋设 / 回收到专用日志表
 */
function logForeshadowing(
    int   $novelId,
    int   $chapterId,
    int   $chapterNumber,
    array $newForeshadowing,
    array $resolvedForeshadowing
): void {
    foreach ($newForeshadowing as $f) {
        if (!empty($f['desc'])) {
            DB::insert('foreshadowing_log', [
                'novel_id'           => $novelId,
                'chapter_id'         => $chapterId,
                'chapter_number'     => $chapterNumber,
                'foreshadowing_desc' => (string)$f['desc'],
                'deadline_chapter'   => (int)($f['suggested_payoff_chapter'] ?? 0) ?: null,
                'is_resolved'        => 0,
            ]);
        }
    }

    foreach ($resolvedForeshadowing as $resolved) {
        $resolved = (string)$resolved;
        $pending  = DB::fetch(
            'SELECT id FROM foreshadowing_log
             WHERE novel_id=? AND is_resolved=0 AND foreshadowing_desc LIKE ?',
            [$novelId, '%' . mb_substr($resolved, 0, 15) . '%']
        );
        if ($pending) {
            DB::update('foreshadowing_log', [
                'is_resolved'      => 1,
                'resolved_chapter' => $chapterNumber,
                'resolved_at'      => date('Y-m-d H:i:s'),
            ], 'id=?', [$pending['id']]);
        }
    }
}

/**
 * 获取伏笔回收状态概览（总待回收数 + 已逾期列表）
 */
function getForeshadowingStatus(int $novelId): array {
    $total   = DB::count('foreshadowing_log', 'novel_id=? AND is_resolved=0', [$novelId]);
    $overdue = DB::fetchAll(
        'SELECT * FROM foreshadowing_log
         WHERE novel_id=? AND is_resolved=0 AND deadline_chapter IS NOT NULL
           AND deadline_chapter < (
               SELECT COALESCE(MAX(chapter_number), 0) FROM chapters
               WHERE novel_id=? AND status="completed"
           ) - 3
         ORDER BY deadline_chapter ASC',
        [$novelId, $novelId]
    );
    return [
        'total_pending' => $total,
        'overdue_count' => count($overdue),
        'overdue'       => $overdue,
    ];
}

// ----------------------------------------------------------------
// 全局状态更新（章节完成后汇总写入）
// ----------------------------------------------------------------

/**
 * 章节完成后，批量更新人物状态、关键事件、伏笔列表、故事势能
 */
function updateNovelMeta(
    int    $novelId,
    int    $chapterNumber,
    array  $characterUpdates,
    string $keyEvent,
    array  $newForeshadowing      = [],
    array  $resolvedForeshadowing = [],
    string $storyMomentum         = ''
): void {
    // 1. 合并更新人物状态卡片
    if (!empty($characterUpdates)) {
        $novel  = DB::fetch('SELECT character_states FROM novels WHERE id=?', [$novelId]);
        $states = [];
        if ($novel && !empty($novel['character_states'])) {
            $states = json_decode($novel['character_states'], true) ?: [];
        }
        foreach ($characterUpdates as $name => $update) {
            if (!is_array($update)) continue;
            if (!isset($states[$name])) $states[$name] = [];
            foreach ($update as $k => $v) {
                if ($v !== '' && $v !== null) $states[$name][$k] = $v;
            }
        }
        DB::update('novels', [
            'character_states' => json_encode($states, JSON_UNESCAPED_UNICODE),
        ], 'id=?', [$novelId]);
    }

    // 2. 追加关键事件（保留最近 100 条）
    if ($keyEvent !== '') {
        $novel  = DB::fetch('SELECT key_events FROM novels WHERE id=?', [$novelId]);
        $events = [];
        if ($novel && !empty($novel['key_events'])) {
            $events = json_decode($novel['key_events'], true) ?: [];
        }
        $events[] = ['chapter' => $chapterNumber, 'event' => $keyEvent];
        if (count($events) > 100) $events = array_slice($events, -100);
        DB::update('novels', [
            'key_events' => json_encode($events, JSON_UNESCAPED_UNICODE),
        ], 'id=?', [$novelId]);
    }

    // 3. 更新待回收伏笔列表
    $novel   = DB::fetch('SELECT pending_foreshadowing FROM novels WHERE id=?', [$novelId]);
    $pending = [];
    if ($novel && !empty($novel['pending_foreshadowing'])) {
        $pending = json_decode($novel['pending_foreshadowing'], true) ?: [];
    }
    if (!empty($resolvedForeshadowing)) {
        $pending = array_values(array_filter($pending, function ($f) use ($resolvedForeshadowing) {
            foreach ($resolvedForeshadowing as $resolved) {
                if (mb_strpos($f['desc'] ?? '', mb_substr($resolved, 0, 10)) !== false) return false;
            }
            return true;
        }));
    }
    foreach ($newForeshadowing as $f) {
        if (!empty($f['desc'])) {
            $pending[] = [
                'chapter'  => $chapterNumber,
                'desc'     => (string)$f['desc'],
                'deadline' => (int)($f['suggested_payoff_chapter'] ?? 0) ?: null,
            ];
        }
    }
    if (count($pending) > 50) $pending = array_slice($pending, -50);
    DB::update('novels', [
        'pending_foreshadowing' => json_encode($pending, JSON_UNESCAPED_UNICODE),
    ], 'id=?', [$novelId]);

    // 4. 更新故事势能
    if ($storyMomentum !== '') {
        DB::update('novels', ['story_momentum' => $storyMomentum], 'id=?', [$novelId]);
    }
}
