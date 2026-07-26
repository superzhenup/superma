<?php
/**
 * StoryBible — 全书圣经（1000章长程记忆的根治方案）
 *
 * v1.7 PRO 升级：支持 bible_nodes 节点化 CRUD 模式
 *
 * 旧模式：每 N 章 AI 将「上一版圣经 + 增量信息」整体压缩为 3000 字快照，存入 novel_bible。
 *         写到 200 章时前 100 章设定被反复压缩，信息流失严重。
 *
 * 新模式（CRUD）：圣经拆分为模块化节点(bible_nodes)，AI 仅对发生变化的节点输出 CRUD 操作。
 *         零信息丢失，is_locked 节点 AI 绝对不可修改。
 *
 * 向后兼容：get() 接口不变，内部自动优先从 bible_nodes 聚合，无节点时降级读 novel_bible。
 */
defined('APP_LOADED') or die('Direct access denied.');

class StoryBible
{
    private static bool $tableReady = false;
    private static bool $nodeTableReady = false;

    // 节点分类 → 旧三段文本的映射
    private const CATEGORY_MAP = [
        'world_md'     => ['world_rules', 'magic_system', 'items'],
        'character_md' => ['characters', 'factions'],
        'timeline_md'  => ['timeline'],
    ];

    // 所有合法的节点分类
    private const VALID_CATEGORIES = [
        'world_rules', 'magic_system', 'items',
        'characters', 'factions', 'timeline',
    ];

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

    private static function ensureNodeTable(): void
    {
        if (self::$nodeTableReady) return;
        // 优先检测：如果表存在则跳过
        try {
            DB::fetch("SELECT 1 FROM bible_nodes LIMIT 1");
            self::$nodeTableReady = true;
            return;
        } catch (\Throwable) {
            // 表不存在，主动创建（兜底：避免 Schema::applyAll 未触发场景）
        }
        try {
            DB::execute("CREATE TABLE IF NOT EXISTS `bible_nodes` (
                `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `novel_id`             INT UNSIGNED NOT NULL,
                `category`             VARCHAR(50) NOT NULL,
                `node_key`             VARCHAR(100) NOT NULL,
                `node_title`           VARCHAR(255) NOT NULL,
                `content_md`           MEDIUMTEXT,
                `is_locked`            TINYINT NOT NULL DEFAULT 0,
                `last_updated_chapter` INT UNSIGNED DEFAULT 0,
                `updated_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `idx_novel_node` (`novel_id`, `category`, `node_key`),
                INDEX `idx_novel_category` (`novel_id`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('StoryBible::ensureNodeTable create failed: ' . $e->getMessage());
        }
        self::$nodeTableReady = true;
    }

    // =================================================================
    // 节点 CRUD 操作
    // =================================================================

    /**
     * 查询指定小说的圣经节点
     * @param int $novelId 小说ID
     * @param string|null $category 按分类过滤，null=全部
     * @return array 节点列表
     */
    public static function getNodes(int $novelId, ?string $category = null): array
    {
        self::ensureNodeTable();
        try {
            if ($category !== null) {
                return DB::fetchAll(
                    "SELECT id, category, node_key, node_title, content_md, is_locked, last_updated_chapter
                     FROM bible_nodes WHERE novel_id=? AND category=? ORDER BY node_key ASC",
                    [$novelId, $category]
                );
            }
            return DB::fetchAll(
                "SELECT id, category, node_key, node_title, content_md, is_locked, last_updated_chapter
                 FROM bible_nodes WHERE novel_id=? ORDER BY category, node_key ASC",
                [$novelId]
            );
        } catch (\Throwable $e) {
            error_log('StoryBible::getNodes ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 执行 CRUD 变更数组
     * 每条操作格式：{"action":"create|update|delete", "category":"...", "key":"...", "title":"...", "content":"..."}
     *
     * @param int $novelId 小说ID
     * @param array $changes CRUD 操作数组
     * @param int $chapterNumber 触发变更的章节号
     * @return array{applied:int, skipped:int, errors:string[]}
     */
    public static function applyChanges(int $novelId, array $changes, int $chapterNumber): array
    {
        self::ensureNodeTable();
        $report = ['applied' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($changes as $change) {
            if (!is_array($change)) continue;
            $action   = trim((string)($change['action'] ?? ''));
            $category = trim((string)($change['category'] ?? ''));
            $key      = trim((string)($change['key'] ?? ''));
            $title    = trim((string)($change['title'] ?? ''));
            $content  = trim((string)($change['content'] ?? ''));

            if (!in_array($action, ['create', 'update', 'delete'], true)) {
                $report['errors'][] = "无效操作: {$action}";
                continue;
            }
            if ($category === '' || $key === '') {
                $report['errors'][] = "缺少 category 或 key";
                continue;
            }
            if (!in_array($category, self::VALID_CATEGORIES, true)) {
                $report['errors'][] = "无效分类: {$category}";
                continue;
            }

            try {
                switch ($action) {
                    case 'create':
                        $existing = DB::fetch(
                            "SELECT id, is_locked FROM bible_nodes WHERE novel_id=? AND category=? AND node_key=?",
                            [$novelId, $category, $key]
                        );
                        if ($existing) {
                            if ($existing['is_locked']) {
                                $report['skipped']++;
                                $report['errors'][] = "节点 {$category}/{$key} 已锁定，跳过重复创建";
                                break;
                            }
                            $updateData = ['last_updated_chapter' => $chapterNumber];
                            if ($title !== '')   $updateData['node_title'] = $title;
                            if ($content !== '') $updateData['content_md'] = $content;
                            DB::update('bible_nodes', $updateData, 'id=?', [$existing['id']]);
                        } else {
                            if ($title === '') $title = $key;
                            DB::execute(
                                "INSERT INTO bible_nodes (novel_id, category, node_key, node_title, content_md, last_updated_chapter)
                                 VALUES (?, ?, ?, ?, ?, ?)",
                                [$novelId, $category, $key, $title, $content, $chapterNumber]
                            );
                        }
                        $report['applied']++;
                        break;

                    case 'update':
                        // 检查 is_locked
                        $existing = DB::fetch(
                            "SELECT id, is_locked FROM bible_nodes WHERE novel_id=? AND category=? AND node_key=?",
                            [$novelId, $category, $key]
                        );
                        if (!$existing) {
                            // 不存在则创建
                            if ($title === '') $title = $key;
                            DB::execute(
                                "INSERT INTO bible_nodes (novel_id, category, node_key, node_title, content_md, last_updated_chapter)
                                 VALUES (?, ?, ?, ?, ?, ?)",
                                [$novelId, $category, $key, $title, $content, $chapterNumber]
                            );
                        } elseif ($existing['is_locked']) {
                            $report['skipped']++;
                            $report['errors'][] = "节点 {$category}/{$key} 已锁定，跳过更新";
                            break;
                        } else {
                            $updateData = ['last_updated_chapter' => $chapterNumber];
                            if ($content !== '') $updateData['content_md'] = $content;
                            if ($title !== '')   $updateData['node_title'] = $title;
                            DB::update('bible_nodes', $updateData, 'id=?', [$existing['id']]);
                        }
                        $report['applied']++;
                        break;

                    case 'delete':
                        $existing = DB::fetch(
                            "SELECT id, is_locked FROM bible_nodes WHERE novel_id=? AND category=? AND node_key=?",
                            [$novelId, $category, $key]
                        );
                        if ($existing && !$existing['is_locked']) {
                            DB::execute("DELETE FROM bible_nodes WHERE id=?", [$existing['id']]);
                            $report['applied']++;
                        } else {
                            $report['skipped']++;
                        }
                        break;
                }
            } catch (\Throwable $e) {
                $report['errors'][] = "{$action} {$category}/{$key}: " . $e->getMessage();
            }
        }

        return $report;
    }

    // =================================================================
    // 读取路径（向后兼容）
    // =================================================================

    /**
     * 读取当前圣经，无则返回 null。
     * 优先从 bible_nodes 聚合；无节点时降级读 novel_bible。
     * @return array{world_md:string,character_md:string,timeline_md:string,updated_chapter:int}|null
     */
    public static function get(int $novelId): ?array
    {
        // 优先尝试从 bible_nodes 聚合
        $fromNodes = self::getFromNodes($novelId);
        if ($fromNodes !== null) return $fromNodes;

        // 降级：读旧表 novel_bible
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
     * 从 bible_nodes 聚合为旧三段格式
     * @return array|null 有节点时返回聚合结果，无节点返回 null
     */
    private static function getFromNodes(int $novelId): ?array
    {
        self::ensureNodeTable();
        try {
            $nodes = DB::fetchAll(
                "SELECT category, node_title, content_md FROM bible_nodes
                 WHERE novel_id=? ORDER BY category, node_key ASC",
                [$novelId]
            );
        } catch (\Throwable) {
            return null;
        }

        if (empty($nodes)) return null;

        // 按旧三段分组拼接
        $sections = ['world_md' => '', 'character_md' => '', 'timeline_md' => ''];
        $maxChapter = 0;

        foreach ($nodes as $node) {
            $cat = $node['category'];
            $text = "### {$node['node_title']}\n" . trim((string)$node['content_md']) . "\n";
            foreach (self::CATEGORY_MAP as $section => $categories) {
                if (in_array($cat, $categories, true)) {
                    $sections[$section] .= $text;
                    break;
                }
            }
        }

        // 获取最后更新的章节号
        try {
            $row = DB::fetch(
                "SELECT MAX(last_updated_chapter) as max_ch FROM bible_nodes WHERE novel_id=?",
                [$novelId]
            );
            $maxChapter = (int)($row['max_ch'] ?? 0);
        } catch (\Throwable) {}

        // 全空则视为无数据
        if (trim($sections['world_md']) === '' && trim($sections['character_md']) === '' && trim($sections['timeline_md']) === '') {
            return null;
        }

        return [
            'world_md'        => trim($sections['world_md']),
            'character_md'    => trim($sections['character_md']),
            'timeline_md'     => trim($sections['timeline_md']),
            'updated_chapter' => $maxChapter,
        ];
    }

    // =================================================================
    // 写入路径
    // =================================================================

    /**
     * 增量重建圣经入口。
     * 如果启用了 CRUD 模式(ws_bible_crud_enabled)，走节点化 diff 模式；
     * 否则走旧的整体压缩模式。
     */
    public static function regenerate(int $novelId, int $chNum, ?int $modelId = null): void
    {
        if (getSystemSetting('ws_bible_crud_enabled', true, 'bool')) {
            self::regenerateCrud($novelId, $chNum, $modelId);
        } else {
            self::regenerateLegacy($novelId, $chNum, $modelId);
        }
    }

    /**
     * CRUD 模式：AI 输出增删改操作数组，系统逐条执行。
     * 失败时保留旧数据（不覆盖），绝不影响主写作流程。
     */
    private static function regenerateCrud(int $novelId, int $chNum, ?int $modelId = null): void
    {
        self::ensureNodeTable();
        self::ensureTable();

        // 确定增量章节范围
        $lastChapter = self::getLastUpdatedChapter($novelId);
        $fromCh = $lastChapter + 1;
        if ($fromCh > $chNum) return;

        // 如果 bible_nodes 为空但 novel_bible 有数据，先迁移
        $existingNodes = self::getNodes($novelId);
        if (empty($existingNodes)) {
            self::migrateFromOldBible($novelId);
            $existingNodes = self::getNodes($novelId);
        }

        // ── 增量输入：fromCh..chNum 的章节摘要 ──
        $summaries = DB::fetchAll(
            "SELECT chapter_number, title, chapter_summary
             FROM chapters
             WHERE novel_id=? AND chapter_number BETWEEN ? AND ?
               AND chapter_summary IS NOT NULL AND chapter_summary <> ''
             ORDER BY chapter_number ASC",
            [$novelId, $fromCh, $chNum]
        );
        // 如果既没有任何章节摘要，也没有已有的节点知识，则无任何输入可产生变更
        if (empty($summaries) && empty($existingNodes)) return;
        // 如果没有新摘要可学（即使已有节点），也无需调用 AI
        if (empty($summaries)) return;

        $summaryText = '';
        foreach ($summaries as $s) {
            $summaryText .= "第{$s['chapter_number']}章《{$s['title']}》：" . trim((string)$s['chapter_summary']) . "\n";
        }
        $summaryText = mb_substr($summaryText, 0, 8000);

        // ── 当前节点快照 ──
        $nodesText = '';
        foreach ($existingNodes as $n) {
            $lockTag = $n['is_locked'] ? ' [锁定]' : '';
            $nodesText .= "- [{$n['category']}/{$n['node_key']}] {$n['node_title']}{$lockTag}\n"
                . "  内容: " . mb_substr((string)$n['content_md'], 0, 200) . "\n";
        }
        $nodesText = mb_substr($nodesText, 0, 6000);

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

        $novel = DB::fetch("SELECT title, genre, protagonist_name FROM novels WHERE id=?", [$novelId]);
        $title = $novel['title'] ?? '';
        $protagonist = $novel['protagonist_name'] ?? '';

        $categories = implode(', ', self::VALID_CATEGORIES);

        $sys = "你是长篇小说《{$title}》的设定档案管理员。你的任务是维护「全书圣经」的模块化节点。"
            . "主角固定为「{$protagonist}」。严禁编造未在输入中出现的设定。"
            . "标记为 [锁定] 的节点绝对不可修改或删除。";

        $user = "【当前圣经节点列表】\n{$nodesText}\n\n"
            . "【第{$fromCh}-{$chNum}章新增章节摘要】\n{$summaryText}\n\n"
            . "【当前人物档案】\n{$charText}\n\n"
            . "请对比「当前节点」与「新增信息」，输出需要执行的 CRUD 操作。要求：\n"
            . "1. 合法分类: {$categories}\n"
            . "2. 合法操作: create(新建节点), update(更新已有节点), delete(删除过时节点)\n"
            . "3. key 使用拼音或英文标识（如 lin_feng, zhan_shen_dian），不要中文\n"
            . "4. 只输出有变化的操作，无变化则输出空数组 []\n"
            . "5. 锁定节点不可 update/delete\n"
            . "6. 严格输出 JSON 数组（不要 markdown 代码块包裹）：\n"
            . "   [{\"action\":\"create\",\"category\":\"characters\",\"key\":\"lin_feng\",\"title\":\"林枫\",\"content\":\"筑基期，持有无双剑，性格沉稳\"}, ...]\n"
            . "   [{\"action\":\"update\",\"category\":\"timeline\",\"key\":\"ch15_event\",\"title\":\"第15章关键事件\",\"content\":\"林枫获得无双剑\"}, ...]";

        try {
            $ai = getAIClient($modelId ?: null);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            $changes = self::parseJsonArray($raw);
            if ($changes === null) {
                // 尝试解析为单个对象（AI 有时会输出 {"changes": [...]}）
                $obj = self::parseJson($raw);
                if ($obj && isset($obj['changes']) && is_array($obj['changes'])) {
                    $changes = $obj['changes'];
                }
            }

            if ($changes === null || !is_array($changes)) {
                error_log("StoryBible::regenerateCrud novel#{$novelId} JSON 解析失败，保留旧版");
                return;
            }

            if (empty($changes)) {
                // 无变更，仅更新章节号
                self::touchUpdatedChapter($novelId, $chNum);
                addLog($novelId, 'info', "全书圣经(CRUD)：第{$fromCh}-{$chNum}章无设定变更");
                return;
            }

            $report = self::applyChanges($novelId, $changes, $chNum);

            // 同步更新旧表（兼容降级读取）
            self::syncLegacyTable($novelId, $chNum);

            addLog($novelId, 'info', sprintf(
                '全书圣经(CRUD)已更新至第%d章：应用%d条/跳过%d条/错误%d条',
                $chNum, $report['applied'], $report['skipped'], count($report['errors'])
            ));

            if (!empty($report['errors'])) {
                $errSample = implode('；', array_slice($report['errors'], 0, 3));
                addLog($novelId, 'warn', "圣经CRUD部分失败：{$errSample}");
            }
        } catch (\Throwable $e) {
            error_log('StoryBible::regenerateCrud ' . $e->getMessage());
        }
    }

    /**
     * 旧模式：整体压缩（保留作为降级路径）
     */
    private static function regenerateLegacy(int $novelId, int $chNum, ?int $modelId = null): void
    {
        self::ensureTable();

        $old = self::getFromLegacy($novelId);
        $fromCh = $old ? $old['updated_chapter'] + 1 : 1;
        if ($fromCh > $chNum) return;

        $maxChars = max(800, (int)getSystemSetting('ws_bible_max_chars', 3000, 'int'));

        $summaries = DB::fetchAll(
            "SELECT chapter_number, title, chapter_summary
             FROM chapters
             WHERE novel_id=? AND chapter_number BETWEEN ? AND ?
               AND chapter_summary IS NOT NULL AND chapter_summary <> ''
             ORDER BY chapter_number ASC",
            [$novelId, $fromCh, $chNum]
        );
        if (empty($summaries) && $old) return;

        $summaryText = '';
        foreach ($summaries as $s) {
            $summaryText .= "第{$s['chapter_number']}章《{$s['title']}》：" . trim((string)$s['chapter_summary']) . "\n";
        }
        $summaryText = mb_substr($summaryText, 0, 8000);

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
                ['role' => 'user',   'content' => $user],
            ], 'structured'));

            $data = self::parseJson($raw);
            if (!$data) {
                error_log("StoryBible::regenerateLegacy novel#{$novelId} 解析失败，保留旧版");
                return;
            }

            $world     = mb_substr(trim((string)($data['world'] ?? ($old['world_md'] ?? ''))), 0, $maxChars);
            $character = mb_substr(trim((string)($data['character'] ?? ($old['character_md'] ?? ''))), 0, $maxChars);
            $timeline  = mb_substr(trim((string)($data['timeline'] ?? ($old['timeline_md'] ?? ''))), 0, $maxChars);

            if ($world === '' && $character === '' && $timeline === '') return;

            DB::execute(
                "INSERT INTO novel_bible (novel_id, world_md, character_md, timeline_md, updated_chapter)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE world_md=VALUES(world_md), character_md=VALUES(character_md),
                     timeline_md=VALUES(timeline_md), updated_chapter=VALUES(updated_chapter)",
                [$novelId, $world, $character, $timeline, $chNum]
            );
            addLog($novelId, 'info', "全书圣经已更新至第{$chNum}章（世界" . mb_strlen($world) . "字/人物" . mb_strlen($character) . "字/时间线" . mb_strlen($timeline) . "字）");
        } catch (\Throwable $e) {
            error_log('StoryBible::regenerateLegacy ' . $e->getMessage());
        }
    }

    // =================================================================
    // 迁移与同步
    // =================================================================

    /**
     * 从旧 novel_bible 迁移到 bible_nodes（一次性）
     * 仅在 bible_nodes 为空且 novel_bible 有数据时调用
     */
    public static function migrateFromOldBible(int $novelId): void
    {
        self::ensureTable();
        self::ensureNodeTable();

        $old = self::getFromLegacy($novelId);
        if (!$old) return;

        // 老圣经 updated_chapter 为 0 时，至少标记为 1，避免节点的 last_updated_chapter=0
        // 导致 getLastUpdatedChapter() 仍返回 0，引发"无限重复迁移 + 增量起点永远为 1"的死循环
        $chNum = max(1, (int)$old['updated_chapter']);

        // 世界规则 → world_rules 节点
        if (trim($old['world_md']) !== '') {
            $sections = self::splitMarkdownSections($old['world_md']);
            foreach ($sections as $title => $content) {
                $key = self::toNodeKey($title);
                try {
                    DB::execute(
                        "INSERT IGNORE INTO bible_nodes (novel_id, category, node_key, node_title, content_md, last_updated_chapter)
                         VALUES (?, 'world_rules', ?, ?, ?, ?)",
                        [$novelId, $key, $title, $content, $chNum]
                    );
                } catch (\Throwable $e) { /* 单条失败不阻塞 */ }
            }
        }

        // 人物现状 → characters 节点
        if (trim($old['character_md']) !== '') {
            $sections = self::splitMarkdownSections($old['character_md']);
            foreach ($sections as $title => $content) {
                $key = self::toNodeKey($title);
                try {
                    DB::execute(
                        "INSERT IGNORE INTO bible_nodes (novel_id, category, node_key, node_title, content_md, last_updated_chapter)
                         VALUES (?, 'characters', ?, ?, ?, ?)",
                        [$novelId, $key, $title, $content, $chNum]
                    );
                } catch (\Throwable $e) { /* 单条失败不阻塞 */ }
            }
        }

        // 主线时间线 → timeline 节点
        if (trim($old['timeline_md']) !== '') {
            $sections = self::splitMarkdownSections($old['timeline_md']);
            foreach ($sections as $title => $content) {
                $key = self::toNodeKey($title);
                try {
                    DB::execute(
                        "INSERT IGNORE INTO bible_nodes (novel_id, category, node_key, node_title, content_md, last_updated_chapter)
                         VALUES (?, 'timeline', ?, ?, ?, ?)",
                        [$novelId, $key, $title, $content, $chNum]
                    );
                } catch (\Throwable $e) { /* 单条失败不阻塞 */ }
            }
        }

        addLog($novelId, 'info', "全书圣经已从 novel_bible 迁移至 bible_nodes 节点模式");
    }

    /**
     * 将 bible_nodes 聚合结果同步回 novel_bible（兼容降级读取）
     */
    private static function syncLegacyTable(int $novelId, int $chNum): void
    {
        self::ensureTable();
        $aggregated = self::getFromNodes($novelId);
        if (!$aggregated) return;

        try {
            DB::execute(
                "INSERT INTO novel_bible (novel_id, world_md, character_md, timeline_md, updated_chapter)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE world_md=VALUES(world_md), character_md=VALUES(character_md),
                     timeline_md=VALUES(timeline_md), updated_chapter=VALUES(updated_chapter)",
                [$novelId, $aggregated['world_md'], $aggregated['character_md'], $aggregated['timeline_md'], $chNum]
            );
        } catch (\Throwable $e) {
            error_log('StoryBible::syncLegacyTable ' . $e->getMessage());
        }
    }

    // =================================================================
    // 辅助方法
    // =================================================================

    /**
     * 获取某小说圣经最后更新的章节号（综合 bible_nodes 和 novel_bible）
     */
    private static function getLastUpdatedChapter(int $novelId): int
    {
        $nodeChapter = 0;
        self::ensureNodeTable();
        try {
            $row = DB::fetch(
                "SELECT MAX(last_updated_chapter) as max_ch FROM bible_nodes WHERE novel_id=?",
                [$novelId]
            );
            $nodeChapter = (int)($row['max_ch'] ?? 0);
        } catch (\Throwable) {}

        $legacyChapter = 0;
        self::ensureTable();
        try {
            $row = DB::fetch("SELECT updated_chapter FROM novel_bible WHERE novel_id=?", [$novelId]);
            $legacyChapter = (int)($row['updated_chapter'] ?? 0);
        } catch (\Throwable $e) { error_log('StoryBible legacyChapter fetch failed: ' . $e->getMessage()); }

        return max($nodeChapter, $legacyChapter);
    }

    /** 仅更新旧表的章节号（无变更时使用） */
    private static function touchUpdatedChapter(int $novelId, int $chNum): void
    {
        self::ensureTable();
        try {
            DB::execute(
                "INSERT INTO novel_bible (novel_id, updated_chapter) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE updated_chapter=GREATEST(updated_chapter, VALUES(updated_chapter))",
                [$novelId, $chNum]
            );
        } catch (\Throwable) {}
    }

    /** 直接读旧表（不走 bible_nodes 聚合） */
    private static function getFromLegacy(int $novelId): ?array
    {
        self::ensureTable();
        try {
            $row = DB::fetch("SELECT world_md, character_md, timeline_md, updated_chapter FROM novel_bible WHERE novel_id=?", [$novelId]);
            if (!$row) return null;
            return [
                'world_md'        => (string)($row['world_md'] ?? ''),
                'character_md'    => (string)($row['character_md'] ?? ''),
                'timeline_md'     => (string)($row['timeline_md'] ?? ''),
                'updated_chapter' => (int)($row['updated_chapter'] ?? 0),
            ];
        } catch (\Throwable $e) {
            error_log('StoryBible::getFromLegacy ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 将 Markdown 文本按 ### 标题拆分为 section
     * @return array<string,string> title => content
     */
    private static function splitMarkdownSections(string $text): array
    {
        $sections = [];
        $currentTitle = 'default';
        $currentContent = '';

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $m)) {
                // 保存上一段
                if (trim($currentContent) !== '') {
                    $sections[$currentTitle] = trim($currentContent);
                }
                $currentTitle = trim($m[1]);
                $currentContent = '';
            } else {
                $currentContent .= $line . "\n";
            }
        }
        // 最后一段
        if (trim($currentContent) !== '') {
            $sections[$currentTitle] = trim($currentContent);
        }

        // 如果没有拆分出任何 section，整体作为一条
        if (empty($sections)) {
            $sections['default'] = trim($text);
        }

        return $sections;
    }

    /**
     * 中文标题转 node_key（拼音/英文安全标识）
     * 简单策略：去特殊字符 + 转下划线 + 截断
     */
    private static function toNodeKey(string $title): string
    {
        // 去除 markdown 标记和特殊字符
        $key = preg_replace('/[#*`\[\]\(\)]/', '', $title);
        // 保留中英文数字和常见分隔符
        $key = preg_replace('/[^\w\x{4e00}-\x{9fff}]/u', '_', $key);
        $key = preg_replace('/_+/', '_', $key);
        $key = trim($key, '_');
        if ($key === '') $key = 'node_' . substr(md5($title), 0, 8);
        return mb_substr($key, 0, 100);
    }

    /** 容错解析 JSON 对象 */
    private static function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        $s = strpos($raw, '{');
        $e = strrpos($raw, '}');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }

    /** 容错解析 JSON 数组 */
    private static function parseJsonArray(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $data = json_decode($raw, true);
        if (is_array($data) && (empty($data) || isset($data[0]))) return $data;
        // 截取首个 [ 到末个 ]
        $s = strpos($raw, '[');
        $e = strrpos($raw, ']');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
