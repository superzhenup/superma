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
     * @param array $entries [['type'=>'...', 'key'=>'...', 'value'=>...], ...]
     */
    public function setBatch(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->set($entry['type'], $entry['key'], $entry['value']);
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
        // 取最近 lookback 条，反转使最近的在前
        return array_reverse(array_slice(array_reverse($state), 0, $lookback));
    }

    /**
     * 获取主角当前能力值（从character状态中读取）
     * @return array{realm?: string, power_level?: int, recent_growth?: float}
     */
    public function getProtagonistPower(): array
    {
        return $this->get('character', 'protagonist_power', []);
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
}
