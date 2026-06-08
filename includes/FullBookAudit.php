<?php
/**
 * FullBookAudit — 全书一致性体检（每 N 章一次的「侦探式审计」）
 *
 * 定位：唯一一个让 AI 带推理、横扫全书宏观视图找矛盾的环节。
 * 与「全书圣经」互补——圣经是预防（维护正确状态注入 prompt），
 * 本审计是检测（审查已写出的几百章里是否自相矛盾 / 伏笔黑洞 / 宏观节奏问题）。
 *
 * 产物：存 novel_audits（评分 + markdown 报告 + 结构化问题），写一行摘要到 writing_logs。
 * 默认不自动改稿（ws_fullbook_audit_autofix=0）；开启后把高危问题写成下一章 Agent 指令。
 */
defined('APP_LOADED') or die('Direct access denied.');

class FullBookAudit
{
    private static bool $tableReady = false;

    private static function ensureTable(): void
    {
        if (self::$tableReady) return;
        DB::execute("CREATE TABLE IF NOT EXISTS `novel_audits` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `novel_id` INT UNSIGNED NOT NULL,
            `chapter_number` INT UNSIGNED NOT NULL,
            `score` DECIMAL(3,1) DEFAULT NULL,
            `verdict` VARCHAR(60) DEFAULT NULL,
            `report` MEDIUMTEXT,
            `issues` JSON DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_novel` (`novel_id`, `chapter_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$tableReady = true;
    }

    /**
     * 执行一次全书体检，存库并返回结果。失败返回 null（绝不影响主写作流程）。
     */
    public static function run(int $novelId, int $chNum, ?int $modelId = null): ?array
    {
        try {
            self::ensureTable();
            $view = self::buildCondensedView($novelId, $chNum);
            if ($view === '') return null;

            $novel = DB::fetch("SELECT title, genre, protagonist_name FROM novels WHERE id=?", [$novelId]);
            $title = $novel['title'] ?? '';
            $protagonist = $novel['protagonist_name'] ?? '';

            $sys = "你是资深网文主编，正在对长篇小说《{$title}》做**全书宏观一致性体检**。"
                . "主角固定为「{$protagonist}」。你只能基于提供的全书压缩视图判断，严禁臆测未给出的内容。"
                . "重点找：①跨章人物/设定矛盾 ②设定漂移（体系/规则被悄悄改） ③伏笔黑洞（积压或已不可能回收） "
                . "④宏观节奏问题（主线停滞/重复地图/缺转折） ⑤支线烂尾。";

            $user = $view . "\n\n严格输出 JSON（不要 markdown 代码块包裹）：\n"
                . "{\"score\": 0-10 的数字, "
                . "\"highlights\": [\"亮点1\",...], "
                . "\"issues\": [{\"severity\":\"high|medium|low\",\"desc\":\"具体问题，须指向具体章节/人物/设定\"},...], "
                . "\"suggestions\": [\"可执行修正建议\",...], "
                . "\"verdict\": \"继续推进|需局部修订|建议大修\"}\n"
                . "要求：issues 至少给出 3 条；评分严格（9-10 近乎无瑕，7-8 有小问题，5-6 明显问题，<5 严重矛盾）。";

            $ai = getAIClient($modelId ?: null);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $user],
            ], 'structured'));

            $data = self::parseJson($raw);
            if (!$data) {
                error_log("FullBookAudit::run novel#{$novelId} 解析失败");
                return null;
            }

            $score   = isset($data['score']) ? round((float)$data['score'], 1) : null;
            $verdict = mb_substr(trim((string)($data['verdict'] ?? '')), 0, 60);
            $issues  = is_array($data['issues'] ?? null) ? $data['issues'] : [];
            $report  = self::buildMarkdown($data);

            DB::insert('novel_audits', [
                'novel_id'       => $novelId,
                'chapter_number' => $chNum,
                'score'          => $score,
                'verdict'        => $verdict,
                'report'         => $report,
                'issues'         => json_encode($issues, JSON_UNESCAPED_UNICODE),
            ]);

            $highCount = count(array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'high'));
            addLog($novelId, 'info', sprintf(
                '全书体检（第%d章）：评分 %s/10，%d 个问题（高危%d），结论：%s',
                $chNum, $score === null ? '—' : $score, count($issues), $highCount, $verdict ?: '—'
            ));

            // 可选：把高危问题写成下一章 Agent 指令（默认关闭）
            if (getSystemSetting('ws_fullbook_audit_autofix', false, 'bool') && $highCount > 0) {
                self::writeDirectives($novelId, $chNum, $issues);
            }

            return ['score' => $score, 'verdict' => $verdict, 'issues' => $issues, 'report' => $report];
        } catch (\Throwable $e) {
            error_log('FullBookAudit::run ' . $e->getMessage());
            return null;
        }
    }

    /** 最近 N 条体检报告（供 UI 检索） */
    public static function recent(int $novelId, int $limit = 10): array
    {
        try {
            self::ensureTable();
            return DB::fetchAll(
                "SELECT id, chapter_number, score, verdict, report, issues, created_at
                 FROM novel_audits WHERE novel_id=? ORDER BY chapter_number DESC, id DESC LIMIT " . max(1, (int)$limit),
                [$novelId]
            );
        } catch (\Throwable $e) {
            error_log('FullBookAudit::recent ' . $e->getMessage());
            return [];
        }
    }

    /** 组装全书压缩视图（圣经 + 三幕 + 弧段 + 人物 + 未回收伏笔 + 近况），各段限长防膨胀 */
    private static function buildCondensedView(int $novelId, int $chNum): string
    {
        $blocks = [];

        // 全书圣经
        try {
            require_once __DIR__ . '/memory/StoryBible.php';
            $bible = StoryBible::get($novelId);
            if ($bible) {
                $blocks[] = "【全书圣经】\n世界规则：{$bible['world_md']}\n人物现状：{$bible['character_md']}\n主线时间线：{$bible['timeline_md']}";
            }
        } catch (\Throwable $e) { /* 忽略 */ }

        // 全书主线 + 三幕结构（story_outlines 真实列：story_arc / act_division）
        try {
            $so = DB::fetch("SELECT story_arc, act_division FROM story_outlines WHERE novel_id=? ORDER BY id DESC LIMIT 1", [$novelId]);
            if ($so && (!empty($so['story_arc']) || !empty($so['act_division']))) {
                $txt = trim((string)($so['story_arc'] ?? ''));
                if (!empty($so['act_division'])) $txt .= "\n三幕划分：" . (string)$so['act_division'];
                $blocks[] = "【全书主线/三幕结构】\n" . mb_substr(trim($txt), 0, 1800);
            }
        } catch (\Throwable $e) { /* 忽略 */ }

        // 弧段摘要（全量，按时间正序，限长）
        try {
            $arcs = DB::fetchAll(
                "SELECT chapter_from, chapter_to, summary FROM arc_summaries WHERE novel_id=? ORDER BY chapter_from ASC",
                [$novelId]
            );
            if ($arcs) {
                $t = '';
                foreach ($arcs as $a) {
                    $t .= "第{$a['chapter_from']}-{$a['chapter_to']}章：" . trim((string)$a['summary']) . "\n";
                }
                $blocks[] = "【全书弧段摘要】\n" . mb_substr($t, 0, 9000);
            }
        } catch (\Throwable $e) { /* 忽略 */ }

        // 人物档案
        try {
            $cards = DB::fetchAll("SELECT name, title, status, alive FROM character_cards WHERE novel_id=? ORDER BY id LIMIT 50", [$novelId]);
            if ($cards) {
                $t = '';
                foreach ($cards as $c) {
                    $t .= "・{$c['name']}" . ($c['title'] ? "（{$c['title']}）" : '')
                        . ((int)($c['alive'] ?? 1) === 0 ? ' [已死亡]' : '')
                        . ($c['status'] ? " 现状:{$c['status']}" : '') . "\n";
                }
                $blocks[] = "【人物档案】\n" . mb_substr($t, 0, 3500);
            }
        } catch (\Throwable $e) { /* 忽略 */ }

        // 未回收伏笔（伏笔黑洞核心证据）
        try {
            $cnt = (int)(DB::fetch("SELECT COUNT(*) c FROM foreshadowing_items WHERE novel_id=? AND resolved_chapter IS NULL", [$novelId])['c'] ?? 0);
            $fs = DB::fetchAll(
                "SELECT description, planted_chapter, priority FROM foreshadowing_items
                 WHERE novel_id=? AND resolved_chapter IS NULL
                 ORDER BY FIELD(priority,'critical','major','minor'), planted_chapter ASC LIMIT 50",
                [$novelId]
            );
            $t = "当前未回收伏笔共 {$cnt} 条：\n";
            foreach ($fs as $f) {
                $t .= "・[{$f['priority']}] 第{$f['planted_chapter']}章：{$f['description']}\n";
            }
            $blocks[] = "【未回收伏笔】\n" . mb_substr($t, 0, 3500);
        } catch (\Throwable $e) { /* 忽略 */ }

        // 近况（最近 8 章摘要）
        try {
            $recent = DB::fetchAll(
                "SELECT chapter_number, title, chapter_summary FROM chapters
                 WHERE novel_id=? AND chapter_number<=? AND chapter_summary IS NOT NULL AND chapter_summary<>''
                 ORDER BY chapter_number DESC LIMIT 8",
                [$novelId, $chNum]
            );
            if ($recent) {
                $recent = array_reverse($recent);
                $t = '';
                foreach ($recent as $r) {
                    $t .= "第{$r['chapter_number']}章《{$r['title']}》：" . trim((string)$r['chapter_summary']) . "\n";
                }
                $blocks[] = "【最近章节】\n" . mb_substr($t, 0, 3000);
            }
        } catch (\Throwable $e) { /* 忽略 */ }

        if (empty($blocks)) return '';
        return "以下是《》全书截至第{$chNum}章的压缩视图：\n\n" . implode("\n\n", $blocks);
    }

    /** 把结构化结果渲染成可读 markdown 报告 */
    private static function buildMarkdown(array $d): string
    {
        $md = '';
        if (isset($d['score'])) $md .= "## 📊 总体评分：" . round((float)$d['score'], 1) . "/10\n\n";
        if (!empty($d['verdict'])) $md .= "**结论：** {$d['verdict']}\n\n";
        if (!empty($d['highlights']) && is_array($d['highlights'])) {
            $md .= "### ✅ 一致性亮点\n";
            foreach ($d['highlights'] as $h) $md .= "- " . trim((string)$h) . "\n";
            $md .= "\n";
        }
        if (!empty($d['issues']) && is_array($d['issues'])) {
            $md .= "### ⚠️ 矛盾与薄弱点\n";
            $sevMark = ['high' => '🔴', 'medium' => '🟠', 'low' => '🟡'];
            foreach ($d['issues'] as $i) {
                $sev = $i['severity'] ?? 'low';
                $md .= "- " . ($sevMark[$sev] ?? '🟡') . " " . trim((string)($i['desc'] ?? '')) . "\n";
            }
            $md .= "\n";
        }
        if (!empty($d['suggestions']) && is_array($d['suggestions'])) {
            $md .= "### 💡 优化建议\n";
            foreach ($d['suggestions'] as $s) $md .= "- " . trim((string)$s) . "\n";
            $md .= "\n";
        }
        return trim($md);
    }

    /** 自动改稿：高危问题写成下一章 Agent 指令 */
    private static function writeDirectives(int $novelId, int $chNum, array $issues): void
    {
        try {
            require_once __DIR__ . '/agents/AgentDirectives.php';
            $high = array_values(array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'high'));
            $msgs = array_map(fn($i) => trim((string)($i['desc'] ?? '')), array_slice($high, 0, 2));
            $msgs = array_filter($msgs);
            if (!$msgs) return;
            AgentDirectives::add(
                $novelId, $chNum + 1, 'quality',
                '全书体检发现高危一致性问题，后续章节须留意修正：' . implode('；', $msgs),
                3, 72
            );
        } catch (\Throwable $e) {
            error_log('FullBookAudit::writeDirectives ' . $e->getMessage());
        }
    }

    /** 容错 JSON 解析（去 markdown 包裹 / 截取花括号） */
    private static function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) $raw = trim($m[1]);
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        $s = strpos($raw, '{'); $e = strrpos($raw, '}');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
