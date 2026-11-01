<?php
/**
 * ConstraintStateUpdater — 章节完成后更新约束状态库
 *
 * 在 postProcess() 阶段调用，根据本章内容更新 constraint_state 表中的状态数据。
 *
 * 更新内容（Phase 1）：
 *   - pacing: 冲突类型历史、最近高潮章节、本章功能标签
 *   - style:  禁用词使用计数
 *   - plot:   巧合计数、last_processed_chapter、processed_chapters
 *
 * 注意：protagonist_power 从 character_cards 同步（非正文猜测）。
 * 状态由 ConstraintStateDB 的 buildWritingPromptHints() 回灌下一章写作 prompt。
 *
 * 核心投影失败向任务层传播；正文已保存，后处理必须能够重试。
 *
 * @package ConstraintFramework
 */

defined('APP_LOADED') or die('Direct access denied.');

class ConstraintStateUpdater
{
    private int               $novelId;
    private array             $chapter;
    private string            $content;
    private ConstraintStateDB $db;

    public function __construct(int $novelId, array $chapter, string $content)
    {
        $this->novelId = $novelId;
        $this->chapter = $chapter;
        $this->content = $content;
        $this->db      = new ConstraintStateDB($novelId, true);
    }

    /**
     * 执行全部状态更新
     */
    public function updateAll(): void
    {
        if (!ConstraintConfig::isEnabled()) return;

        // 审计修复（2026-07-19 H-中8）：增量计数非幂等。
        // 重试/重写后同章重复调用 updateAll 会把 coincidence_count、banned_word_usage、
        // 冲突历史翻倍/重复写入。记录已处理章节，处理过的章节不再增量计数。
        $chNum = (int)($this->chapter['chapter_number'] ?? 0);
        $batch = [];
        if ($chNum > 0) {
            // 审计修复（2026-08-17 P3）：原门禁 $lastProcessed >= $chNum 会挡住乱序补写
            // 历史章（先写第10章再补第5章时，第5章约束状态完全不更新）。改为按章号
            // 集合 processed_chapters 精确匹配同一章重复；兼容旧键 last_processed_chapter
            // （仅相等视为已处理，不含大于，避免旧值压住乱序补写）。
            $processedChapters = $this->db->get('plot', 'processed_chapters', []);
            if (!is_array($processedChapters)) $processedChapters = [];
            $lastProcessed = $this->db->get('plot', 'last_processed_chapter', 0);

            $alreadyProcessed = in_array($chNum, $processedChapters, true)
                || ($lastProcessed && (int)$lastProcessed === $chNum);
            if ($alreadyProcessed) {
                return; // 精确同一章重复处理，跳过增量计数
            }

            // 追加本章到集合（去重、保持 int、截断保留最近 200 个防膨胀）
            $processedChapters[] = $chNum;
            $processedChapters = array_slice(
                array_values(array_unique(array_map('intval', $processedChapters))),
                -200
            );
            $batch[] = ['type' => 'plot', 'key' => 'processed_chapters', 'value' => $processedChapters];
            // last_processed_chapter 保持向后兼容（取历史最大值，乱序补写不回退）
            $batch[] = ['type' => 'plot', 'key' => 'last_processed_chapter', 'value' => max((int)$lastProcessed, $chNum)];
        }

        try {
            // 审计修复（2026-08-17 P3）：原先 last_processed_chapter 先行落库、三计数器
            // 逐条 set 非原子（中途失败会造成"已标记未计数"的永久漏记）。改为收集全部
            // 写入条目后 setBatch 单事务提交。读-改-写在单事务内提交，同小说写作进程
            // 基本串行，可接受；如需强一致可升级 SELECT FOR UPDATE。
            $batch = array_merge($batch, $this->updatePacingState());
            $batch = array_merge($batch, $this->updateStyleState());
            $batch = array_merge($batch, $this->updatePlotState());
            $this->db->setBatch($batch);
            $this->updateProtagonistPowerState();
        } catch (\Throwable $e) {
            error_log("ConstraintStateUpdater::updateAll failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 从 character_cards 同步主角境界/等级到 constraint_state（轻量回写）。
     */
    public function updateProtagonistPowerState(): void
    {
        try {
            $novel = DB::fetch('SELECT protagonist_name FROM novels WHERE id=?', [$this->novelId]);
            $name = trim((string)($novel['protagonist_name'] ?? ''));
            if ($name === '') {
                $this->clearProtagonistPowerState();
                return;
            }

            $card = DB::fetch(
                'SELECT name, attributes FROM character_cards WHERE novel_id=? AND name=? LIMIT 1',
                [$this->novelId, $name]
            );
            if (!$card) {
                $this->clearProtagonistPowerState();
                return;
            }

            $attrs = json_decode((string)($card['attributes'] ?? ''), true);
            if (!is_array($attrs)) $attrs = [];
            $realm = trim((string)($attrs['realm'] ?? $attrs['level'] ?? ''));
            if ($realm === '') {
                $this->clearProtagonistPowerState();
                return;
            }

            $this->db->set('character', 'protagonist_power', [
                'name'           => (string)($card['name'] ?? $name),
                'realm'          => $realm,
                'power_level'    => isset($attrs['power']) ? (int)$attrs['power'] : null,
                'updated_chapter'=> (int)($this->chapter['chapter_number'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            error_log('ConstraintStateUpdater::updateProtagonistPowerState: ' . $e->getMessage());
            throw $e;
        }
    }

    private function clearProtagonistPowerState(): void
    {
        DB::execute('DELETE FROM constraint_state WHERE novel_id=? AND state_type="character" AND state_key="protagonist_power"', [$this->novelId]);
    }

    // ============================================================
    //  节奏状态更新
    // ============================================================

    /**
     * 计算节奏状态条目。
     * 审计修复（2026-08-17 P3）：改为计算并返回写入条目，由 updateAll 合并 setBatch 提交。
     * @return array<int, array{type: string, key: string, value: mixed}>
     */
    private function updatePacingState(): array
    {
        $chNum = (int)($this->chapter['chapter_number'] ?? 0);
        $entries = [];

        // 1. 更新冲突类型历史（保留最近 20 条）
        $conflictType = $this->detectConflictType();
        $history = $this->db->getConflictHistory(20);
        if (!is_array($history)) $history = [];
        array_unshift($history, ['chapter' => $chNum, 'type' => $conflictType]);
        // 截断至 20 条
        $history = array_slice($history, 0, 20);

        $entries[] = ['type' => 'pacing', 'key' => 'conflict_history', 'value' => $history];

        // 2. 检测是否为高潮章
        if ($this->isClimaxChapter()) {
            $entries[] = ['type' => 'pacing', 'key' => 'last_climax_chapter', 'value' => $chNum];
        }

        // 3. 记录本章功能标签
        $function = $this->detectChapterFunction();
        $entries[] = ['type' => 'pacing', 'key' => 'last_chapter_function', 'value' => [
            'chapter'  => $chNum,
            'function' => $function,
        ]];

        return $entries;
    }

    /**
     * 检测本章冲突类型
     * 关键词匹配法：武力冲突/智力博弈/情感冲突/社会斗争/环境危机
     */
    private function detectConflictType(): string
    {
        $scores = [
            'combat'    => 0,   // 武力冲突
            'intellect' => 0,   // 智力博弈
            'emotion'   => 0,   // 情感冲突
            'social'    => 0,   // 社会斗争
            'survival'  => 0,   // 环境危机
        ];

        $keywords = [
            'combat'    => ['战斗', '攻击', '出手', '斩杀', '击杀', '对决', '激战', '交手', '碰撞', '轰击',
                            '一拳', '一剑', '法术', '功法', '修为', '爆发', '气息', '威压', '雷霆',
                            '闪避', '格挡', '反击', '致命', '受伤', '鲜血'],
            'intellect' => ['计谋', '推理', '布局', '算计', '谋划', '策略', '试探', '博弈', '分析',
                            '线索', '谜题', '谜团', '伪装', '演算', '推导', '预判', '圈套'],
            'emotion'   => ['情感', '心动', '温柔', '泪水', '拥抱', '眼神', '心跳', '温暖', '牵手',
                            '守护', '担忧', '思念', '心疼', '柔情', '陪伴'],
            'social'    => ['势力', '家族', '权力', '地位', '竞争', '打压', '排挤', '联盟', '背叛',
                            '交易', '谈判', '威慑', '拉拢', '站队', '派系'],
            'survival'  => ['危机', '绝境', '逃生', '死亡', '崩塌', '毒', '陷阱', '追杀', '逃亡',
                            '求生', '绝地', '险境', '吞噬', '封锁'],
        ];

        foreach ($keywords as $type => $words) {
            foreach ($words as $word) {
                $scores[$type] += mb_substr_count($this->content, $word);
            }
        }

        // 返回最高分类型
        arsort($scores);
        $topType = array_key_first($scores);

        // 如果最高分也很低（<3），归为混合型
        if ($scores[$topType] < 3) return 'mixed';

        return $topType;
    }

    /**
     * 检测是否为高潮/爽点章
     */
    private function isClimaxChapter(): bool
    {
        $climaxKeywords = ['高潮', '决战', '突破', '觉醒', '斩杀', '击败', '逆转', '翻盘',
                          '爆发', '进化', '蜕变', '横扫', '震惊全场', '万众瞩目'];
        $score = 0;
        foreach ($climaxKeywords as $kw) {
            $score += mb_substr_count($this->content, $kw);
        }

        // 高潮关键词 >=5 次且字数 > 1500 视为高潮章
        $words = countWords($this->content);
        return $score >= 5 && $words > 1500;
    }

    /**
     * 检测本章功能标签
     */
    private function detectChapterFunction(): string
    {
        $chNum   = (int)($this->chapter['chapter_number'] ?? 0);
        $coolType = $this->chapter['cool_point_type'] ?? null;
        $hookType = $this->chapter['hook_type'] ?? null;

        if ($coolType || $this->isClimaxChapter()) {
            return 'release';   // 释放型
        }

        // 检查是否为蓄势型
        $setupKeywords = ['修炼', '准备', '筹划', '收集', '情报', '探查', '偷偷', '暗中', '计划', '部署'];
        $setupScore = 0;
        foreach ($setupKeywords as $kw) {
            $setupScore += mb_substr_count($this->content, $kw);
        }

        if ($setupScore >= 5) return 'setup';     // 蓄势型
        if ($hookType && str_contains($hookType, 'cliffhanger')) return 'hook';  // 钩子型

        return 'development';  // 发展型（默认）
    }

    // ============================================================
    //  风格状态更新
    // ============================================================

    /**
     * 计算风格状态条目。
     * 审计修复（2026-08-17 P3）：改为计算并返回写入条目，由 updateAll 合并 setBatch 提交。
     * @return array<int, array{type: string, key: string, value: mixed}>
     */
    private function updateStyleState(): array
    {
        $entries = [];
        // 禁用词使用计数
        $banned = ConstraintConfig::bannedWords();
        if (empty($banned)) return $entries;

        $usage = $this->db->getBannedWordUsage();
        if (!is_array($usage)) $usage = [];

        foreach ($banned as $word) {
            $count = mb_substr_count($this->content, $word);
            if ($count > 0) {
                $usage[$word] = ($usage[$word] ?? 0) + $count;
            }
        }

        $entries[] = ['type' => 'style', 'key' => 'banned_word_usage', 'value' => $usage];

        return $entries;
    }

    // ============================================================
    //  情节状态更新
    // ============================================================

    /**
     * 计算情节状态条目。
     * 审计修复（2026-08-17 P3）：改为计算并返回写入条目，由 updateAll 合并 setBatch 提交。
     * @return array<int, array{type: string, key: string, value: mixed}>
     */
    private function updatePlotState(): array
    {
        $entries = [];
        // 巧合计数
        $coincidenceKWs = ['恰好', '正巧', '刚好', '碰巧', '凑巧', '巧合', '恰逢', '不偏不倚', '鬼使神差'];
        $newCount = 0;
        foreach ($coincidenceKWs as $kw) {
            $newCount += mb_substr_count($this->content, $kw);
        }

        if ($newCount > 0) {
            $current = $this->db->getCoincidenceCount();
            $entries[] = ['type' => 'plot', 'key' => 'coincidence_count', 'value' => $current + $newCount];
        }

        return $entries;
    }
}
