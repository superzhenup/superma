<?php
/**
 * ConstraintStateDB — 约束状态库 CRUD
 *
 * 管理 constraint_state 和 constraint_logs 两张表的读写。
 * 约束框架的核心数据层。
 *
 * @package ConstraintFramework
 */

defined('APP_LOADED') or die('Direct access denied.');

class ConstraintStateDB
{
    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    // ============================================================
    //  状态库读取
    // ============================================================

    /**
     * 加载小说的全部约束状态
     * @return array<string, array<string, mixed>>
     */
    public function loadAll(): array
    {
        try {
            $rows = DB::fetchAll(
                'SELECT state_type, state_key, state_value FROM constraint_state WHERE novel_id = ?',
                [$this->novelId]
            );
            $state = [];
            foreach ($rows as $row) {
                $value = json_decode($row['state_value'], true) ?? [];
                $state[$row['state_type']][$row['state_key']] = $value;
            }
            return $state;
        } catch (\Throwable $e) {
            error_log("ConstraintStateDB::loadAll failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 读取单个状态值
     */
    public function get(string $stateType, string $stateKey, $default = null)
    {
        try {
            $row = DB::fetch(
                'SELECT state_value FROM constraint_state WHERE novel_id = ? AND state_type = ? AND state_key = ?',
                [$this->novelId, $stateType, $stateKey]
            );
            if (!$row) return $default;
            return json_decode($row['state_value'], true) ?? $default;
        } catch (\Throwable $e) {
            error_log("ConstraintStateDB::get failed: {$e->getMessage()}");
            return $default;
        }
    }

    // ============================================================
    //  状态库写入
    // ============================================================

    /**
     * 更新/新增一个状态条目
     */
    public function set(string $stateType, string $stateKey, $value): void
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            DB::execute(
                'INSERT INTO constraint_state (novel_id, state_type, state_key, state_value)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)',
                [$this->novelId, $stateType, $stateKey, $json]
            );
        } catch (\Throwable $e) {
            error_log("ConstraintStateDB::set failed: {$e->getMessage()}");
        }
    }

    /**
     * 批量更新状态
     * 使用事务保证原子性，避免并发写入时丢失更新
     * @param array $entries [['type'=>'...', 'key'=>'...', 'value'=>...], ...]
     */
    public function setBatch(array $entries): void
    {
        if (empty($entries)) return;

        try {
            $pdo = DB::connect();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO constraint_state (novel_id, state_type, state_key, state_value)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)'
            );

            foreach ($entries as $entry) {
                $stmt->execute([
                    $this->novelId,
                    $entry['type'],
                    $entry['key'],
                    json_encode($entry['value'], JSON_UNESCAPED_UNICODE)
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("ConstraintStateDB::setBatch failed: {$e->getMessage()}");
        }
    }

    // ============================================================
    //  约束日志
    // ============================================================

    /**
     * 记录一条约束违规日志
     */
    public function logViolation(
        int    $chapterNumber,
        ?int   $chapterId,
        string $dimension,
        string $level,
        string $issueType,
        string $issueDesc,
        string $checkPhase = 'post_write',
        bool   $autoFixed = false
    ): void {
        try {
            DB::insert('constraint_logs', [
                'novel_id'        => $this->novelId,
                'chapter_id'      => $chapterId,
                'chapter_number'  => $chapterNumber,
                'check_phase'     => $checkPhase,
                'dimension'       => $dimension,
                'level'           => $level,
                'issue_type'      => $issueType,
                'issue_desc'      => mb_substr($issueDesc, 0, 500),
                'auto_fixed'      => $autoFixed ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            error_log("ConstraintStateDB::logViolation failed: {$e->getMessage()}");
        }
    }

    /**
     * 获取近N章的约束违规统计
     * @return array{total: int, by_dimension: array, p0_count: int}
     */
    public function getRecentViolations(int $lookback = 10): array
    {
        try {
            $rows = DB::fetchAll(
                'SELECT dimension, level, COUNT(*) as cnt
                 FROM constraint_logs
                 WHERE novel_id = ? AND chapter_number > ?
                 GROUP BY dimension, level
                 ORDER BY cnt DESC',
                [$this->novelId, max(0, $this->getLatestChapterNumber() - $lookback)]
            );

            $result = ['total' => 0, 'by_dimension' => [], 'p0_count' => 0];
            foreach ($rows as $row) {
                $result['total'] += (int)$row['cnt'];
                $result['by_dimension'][$row['dimension']] = ($result['by_dimension'][$row['dimension']] ?? 0) + (int)$row['cnt'];
                if ($row['level'] === 'P0') {
                    $result['p0_count'] += (int)$row['cnt'];
                }
            }
            return $result;
        } catch (\Throwable $e) {
            return ['total' => 0, 'by_dimension' => [], 'p0_count' => 0];
        }
    }

    // ============================================================
    //  专用查询
    // ============================================================

    /**
     * 获取最近N章的冲突类型历史
     * @return string[] 冲突类型列表（最近的在前面）
     */
    public function getConflictHistory(int $lookback = 5): array
    {
        $state = $this->get('pacing', 'conflict_history', []);
        if (!is_array($state)) return [];
        // 审计修复（2026-07-19 H-中3）：写入端 array_unshift 头插（最新在前），
        // 原实现 array_reverse(array_slice(array_reverse($state), 0, $lookback))
        // 实际取到最旧 N 条（lookback<20 时）。改为直接从头取 lookback 条。
        return array_slice($state, 0, $lookback);
    }

    /**
     * 获取主角当前能力值（优先 constraint_state，回退 character_cards）
     * @return array{name?: string, realm?: string, power_level?: int|null, updated_chapter?: int}
     */
    public function getProtagonistPower(): array
    {
        $cached = $this->get('character', 'protagonist_power', []);
        if (is_array($cached) && !empty($cached['realm'])) {
            return $cached;
        }
        try {
            $novel = DB::fetch('SELECT protagonist_name FROM novels WHERE id=?', [$this->novelId]);
            $name = trim((string)($novel['protagonist_name'] ?? ''));
            if ($name === '') return is_array($cached) ? $cached : [];
            $card = DB::fetch(
                'SELECT name, attributes FROM character_cards WHERE novel_id=? AND name=? LIMIT 1',
                [$this->novelId, $name]
            );
            if (!$card) return is_array($cached) ? $cached : [];
            $attrs = json_decode((string)($card['attributes'] ?? ''), true);
            if (!is_array($attrs)) $attrs = [];
            $realm = trim((string)($attrs['realm'] ?? $attrs['level'] ?? ''));
            if ($realm === '') return is_array($cached) ? $cached : [];
            return [
                'name' => (string)($card['name'] ?? $name),
                'realm' => $realm,
                'power_level' => isset($attrs['power']) ? (int)$attrs['power'] : null,
            ];
        } catch (\Throwable $e) {
            return is_array($cached) ? $cached : [];
        }
    }

    /**
     * 获取全书已用巧合数
     */
    public function getCoincidenceCount(): int
    {
        $state = $this->get('plot', 'coincidence_count', 0);
        return is_numeric($state) ? (int)$state : 0;
    }

    /**
     * 获取最新章节号
     */
    private function getLatestChapterNumber(): int
    {
        try {
            $row = DB::fetch(
                'SELECT MAX(chapter_number) as max_ch FROM chapters WHERE novel_id = ? AND status = "completed"',
                [$this->novelId]
            );
            return (int)($row['max_ch'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取最近一次高潮/爽点的章节号
     */
    public function getLastClimaxChapter(): int
    {
        $state = $this->get('pacing', 'last_climax_chapter', 0);
        return is_numeric($state) ? (int)$state : 0;
    }

    /**
     * 获取禁用词使用计数
     * @return array<string, int> 词 => 累计使用次数
     */
    public function getBannedWordUsage(): array
    {
        return $this->get('style', 'banned_word_usage', []);
    }

    /**
     * 获取活跃伏笔数量
     */
    public function getActiveForeshadowingCount(): int
    {
        try {
            return DB::count(
                'foreshadowing_items',
                'novel_id = ? AND resolved_chapter IS NULL',
                [$this->novelId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取已回收伏笔数量
     */
    public function getResolvedForeshadowingCount(): int
    {
        try {
            return DB::count(
                'foreshadowing_items',
                'novel_id = ? AND resolved_chapter IS NOT NULL',
                [$this->novelId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 审计优化（2026-07-20）：把 constraint_state 压成短提示注入写作 prompt。
     * 仅返回有实质建议的行；无数据时返回空串（调用方跳过注入）。
     */
    public function buildWritingPromptHints(int $currentChapter = 0): string
    {
        try {
            require_once __DIR__ . '/ConstraintConfig.php';
            if (!ConstraintConfig::isEnabled()) {
                return '';
            }

            $lines = [];
            $power = $this->getProtagonistPower();
            if (!empty($power['realm'])) {
                $pname = trim((string)($power['name'] ?? '主角'));
                $lines[] = "主角能力锚定：{$pname} 当前「{$power['realm']}」——本章不得无故跳级/退化。";
            }

            $coinc = $this->getCoincidenceCount();
            $maxCoinc = ConstraintConfig::maxCoincidences();
            if ($coinc > 0) {
                $remain = max(0, $maxCoinc - $coinc);
                $lines[] = $coinc >= $maxCoinc
                    ? "巧合额度已用尽（{$coinc}/{$maxCoinc}）：本章禁止再靠巧合推进，须用角色主动选择。"
                    : "巧合已用 {$coinc}/{$maxCoinc}（剩余{$remain}）：能不用巧合就不用。";
            }

            $lastClimax = $this->getLastClimaxChapter();
            if ($lastClimax > 0 && $currentChapter > $lastClimax) {
                $gap = $currentChapter - $lastClimax;
                if ($gap >= 5) {
                    $lines[] = "距上次高潮已隔 {$gap} 章：本章宜安排冲突升级或爽点释放。";
                } elseif ($gap <= 1) {
                    $lines[] = "上章刚高潮：本章可略缓，避免连续高潮疲劳。";
                }
            }

            $history = $this->getConflictHistory(3);
            if ($history) {
                $types = [];
                foreach ($history as $h) {
                    $t = is_array($h) ? trim((string)($h['type'] ?? '')) : trim((string)$h);
                    if ($t !== '') $types[] = $t;
                }
                if ($types) {
                    $lines[] = "近章冲突类型：" . implode('、', array_unique($types)) . "——本章尽量换类型或换打法。";
                }
            }

            $bannedUsage = $this->getBannedWordUsage();
            if (is_array($bannedUsage) && $bannedUsage) {
                arsort($bannedUsage);
                $hot = [];
                foreach (array_slice($bannedUsage, 0, 3, true) as $word => $cnt) {
                    if ((int)$cnt >= 3) {
                        $hot[] = "{$word}×{$cnt}";
                    }
                }
                if ($hot) {
                    $lines[] = "禁用词累计偏高：" . implode('、', $hot) . "——本章回避或换说法。";
                }
            }

            $viol = $this->getRecentViolations(5);
            if (($viol['total'] ?? 0) >= 2) {
                $dims = $viol['by_dimension'] ?? [];
                arsort($dims);
                $top = array_slice(array_keys($dims), 0, 2);
                $dimText = $top ? implode('、', $top) : '多项';
                $lines[] = "近5章约束告警 {$viol['total']} 次（偏弱：{$dimText}）——本章优先修正。";
            }

            if (!$lines) {
                return '';
            }

            return "【约束状态——影响本章写法】\n"
                . implode("\n", array_map(fn($l) => "· {$l}", $lines))
                . "\n\n";
        } catch (\Throwable $e) {
            error_log("ConstraintStateDB::buildWritingPromptHints failed: {$e->getMessage()}");
            return '';
        }
    }
}
