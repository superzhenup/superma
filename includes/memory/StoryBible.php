<?php
/**
 * StoryBible — 全书圣经（1000章长程记忆的根治方案）
 *
 * 问题：标准记忆只保留最近 ~10 章详细 + ~50 章弧段摘要 + 滑动窗口语义召回，
 *       中段（数百章前）的人物现状/世界规则/主线时间线会被遗忘。
 *
 * 方案：每 N 章用一次 AI 调用，把「上一版圣经 + 增量信息」压缩成一份不超过 ~3000 字的
 *       稳定快照（世界规则 / 人物现状 / 主线时间线）。它被注入到 prompt 的稳定前缀里，
 *       每 N 章才变一次 → 命中提示词前缀缓存，几乎免费，却给全书设定零漂移。
 */
defined('APP_LOADED') or die('Direct access denied.');

class StoryBible
{
    private static bool $tableReady = false;

    private static function ensureTable(): void
    {
        if (self::$tableReady) return;
        DB::execute("CREATE TABLE IF NOT EXISTS `novel_bible` (
            `novel_id` INT UNSIGNED PRIMARY KEY,
            `world_md` MEDIUMTEXT,
            `character_md` MEDIUMTEXT,
            `timeline_md` MEDIUMTEXT,
            `updated_chapter` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$tableReady = true;
    }

    /**
     * 读取当前圣经，无则返回 null。
     * @return array{world_md:string,character_md:string,timeline_md:string,updated_chapter:int}|null
     */
    public static function get(int $novelId): ?array
    {
        try {
            self::ensureTable();
            $row = DB::fetch("SELECT world_md, character_md, timeline_md, updated_chapter FROM novel_bible WHERE novel_id=?", [$novelId]);
            if (!$row) return null;
            return [
                'world_md'        => (string)($row['world_md'] ?? ''),
                'character_md'    => (string)($row['character_md'] ?? ''),
                'timeline_md'     => (string)($row['timeline_md'] ?? ''),
                'updated_chapter' => (int)($row['updated_chapter'] ?? 0),
            ];
        } catch (\Throwable $e) {
            error_log('StoryBible::get ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 增量重建圣经：基于上一版 + updated_chapter 之后到 chNum 的新增信息。
     * 失败时保留旧版（不覆盖），绝不影响主写作流程。
     */
    public static function regenerate(int $novelId, int $chNum, ?int $modelId = null): void
    {
        self::ensureTable();

        $old = self::get($novelId);
        $fromCh = $old ? $old['updated_chapter'] + 1 : 1;
        if ($fromCh > $chNum) return; // 没有新增章节

        $maxChars = max(800, (int)getSystemSetting('ws_bible_max_chars', 3000, 'int'));

        // ── 增量输入：fromCh..chNum 的章节摘要 ──
        $summaries = DB::fetchAll(
            "SELECT chapter_number, title, chapter_summary
             FROM chapters
             WHERE novel_id=? AND chapter_number BETWEEN ? AND ?
               AND chapter_summary IS NOT NULL AND chapter_summary <> ''
             ORDER BY chapter_number ASC",
            [$novelId, $fromCh, $chNum]
        );
        if (empty($summaries) && $old) return; // 无新摘要且已有圣经，无需重建

        $summaryText = '';
        foreach ($summaries as $s) {
            $summaryText .= "第{$s['chapter_number']}章《{$s['title']}》：" . trim((string)$s['chapter_summary']) . "\n";
        }
        $summaryText = mb_substr($summaryText, 0, 8000);

        // ── 人物现状（角色卡）──
        $charText = '';
        try {
            $cards = DB::fetchAll(
                "SELECT name, title, status, attributes FROM character_cards WHERE novel_id=? ORDER BY id LIMIT 40",
                [$novelId]
            );
            foreach ($cards as $c) {
                $attrs = !empty($c['attributes']) ? (json_decode($c['attributes'], true) ?: []) : [];
                $brief = [];
                foreach (['角色类型','定位','性格','境界','等级'] as $k) {
                    if (!empty($attrs[$k])) $brief[] = "{$k}:{$attrs[$k]}";
                }
                $charText .= "・{$c['name']}" . ($c['title'] ? "（{$c['title']}）" : '')
                    . ($c['status'] ? " 现状:{$c['status']}" : '')
                    . ($brief ? ' ' . implode('；', $brief) : '') . "\n";
            }
        } catch (\Throwable $e) { /* 角色缺失不阻塞 */ }
        $charText = mb_substr($charText, 0, 4000);

        // ── 未回收伏笔（主线线索）──
        $fsText = '';
        try {
            $fs = DB::fetchAll(
                "SELECT description, planted_chapter, priority FROM foreshadowing_items
                 WHERE novel_id=? AND resolved_chapter IS NULL
                 ORDER BY FIELD(priority,'critical','major','minor'), planted_chapter ASC LIMIT 40",
                [$novelId]
            );
            foreach ($fs as $f) {
                $fsText .= "・[{$f['priority']}] 第{$f['planted_chapter']}章埋设：{$f['description']}\n";
            }
        } catch (\Throwable $e) { /* 忽略 */ }
        $fsText = mb_substr($fsText, 0, 3000);

        $novel = DB::fetch("SELECT title, genre, protagonist_name FROM novels WHERE id=?", [$novelId]);
        $title = $novel['title'] ?? '';
        $protagonist = $novel['protagonist_name'] ?? '';

        $oldBlock = $old
            ? "【上一版圣经（截至第{$old['updated_chapter']}章）】\n# 世界规则\n{$old['world_md']}\n# 人物现状\n{$old['character_md']}\n# 主线时间线\n{$old['timeline_md']}\n"
            : "（首次生成，暂无上一版圣经）";

        $sys = "你是长篇小说《{$title}》的设定档案管理员。你的任务是维护一份「全书圣经」——"
            . "一份高度浓缩、只保留对后续写作有长期价值的稳定事实的档案。"
            . "主角固定为「{$protagonist}」。严禁编造未在输入中出现的设定。";

        $user = "{$oldBlock}\n\n"
            . "【第{$fromCh}-{$chNum}章新增章节摘要】\n{$summaryText}\n\n"
            . "【当前人物档案】\n{$charText}\n\n"
            . "【未回收伏笔】\n{$fsText}\n\n"
            . "请基于「上一版圣经」融合上述新增信息，输出**更新后的全书圣经**。要求：\n"
            . "1. 三个部分：世界规则（修炼/魔法/社会体系等稳定规则）、人物现状（主要角色当前身份/境界/关系/生死）、主线时间线（已发生的关键转折，按时间顺序）。\n"
            . "2. 只保留对后续写作有用的**稳定**事实，删除已过时/已被取代的旧状态。\n"
            . "3. 三部分合计不超过 {$maxChars} 字，精炼不啰嗦。\n"
            . "4. 严格输出 JSON（不要 markdown 代码块包裹）：{\"world\":\"...\",\"character\":\"...\",\"timeline\":\"...\"}";

        try {
            $ai = getAIClient($modelId ?: null);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $user],
            ], 'structured'));

            $data = self::parseJson($raw);
            if (!$data) {
                error_log("StoryBible::regenerate novel#{$novelId} 解析失败，保留旧版");
                return;
            }

            $world     = mb_substr(trim((string)($data['world'] ?? ($old['world_md'] ?? ''))), 0, $maxChars);
            $character = mb_substr(trim((string)($data['character'] ?? ($old['character_md'] ?? ''))), 0, $maxChars);
            $timeline  = mb_substr(trim((string)($data['timeline'] ?? ($old['timeline_md'] ?? ''))), 0, $maxChars);

            if ($world === '' && $character === '' && $timeline === '') return; // 全空，不覆盖

            DB::execute(
                "INSERT INTO novel_bible (novel_id, world_md, character_md, timeline_md, updated_chapter)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE world_md=VALUES(world_md), character_md=VALUES(character_md),
                     timeline_md=VALUES(timeline_md), updated_chapter=VALUES(updated_chapter)",
                [$novelId, $world, $character, $timeline, $chNum]
            );
            addLog($novelId, 'info', "全书圣经已更新至第{$chNum}章（世界" . mb_strlen($world) . "字/人物" . mb_strlen($character) . "字/时间线" . mb_strlen($timeline) . "字）");
        } catch (\Throwable $e) {
            error_log('StoryBible::regenerate ' . $e->getMessage());
        }
    }

    /** 容错解析 JSON（去除可能的 ```json 包裹） */
    private static function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        // 去 markdown 代码块
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        // 退而求其次：截取首个 { 到末个 }
        $s = strpos($raw, '{');
        $e = strrpos($raw, '}');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
