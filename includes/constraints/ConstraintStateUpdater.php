<?php
/**
 * ConstraintStateUpdater — 章节完成后更新约束状态库
 *
 * 在 postProcess() 阶段调用，根据本章内容更新 constraint_state 表中的状态数据。
 *
 * 更新内容（Phase 1）：
 *   - pacing: 冲突类型历史、最近高潮章节、本章功能标签
 *   - style:  禁用词使用计数
 *   - plot:   巧合计数
 *   - character: 主角能力状态（基础）
 *
 * 所有异常内部捕获，保证正文不受影响。
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
        $this->db      = new ConstraintStateDB($novelId);
    }

    /**
     * 执行全部状态更新
     */
    public function updateAll(): void
    {
        if (!ConstraintConfig::isEnabled()) return;

        try {
            $this->updatePacingState();
            $this->updateStyleState();
            $this->updatePlotState();
        } catch (\Throwable $e) {
            error_log("ConstraintStateUpdater::updateAll failed: {$e->getMessage()}");
        }
    }

    // ============================================================
    //  节奏状态更新
    // ============================================================

    private function updatePacingState(): void
    {
        $chNum = (int)($this->chapter['chapter_number'] ?? 0);

        // 1. 更新冲突类型历史（保留最近 20 条）
        $conflictType = $this->detectConflictType();
        $history = $this->db->getConflictHistory(20);
        if (!is_array($history)) $history = [];
        array_unshift($history, ['chapter' => $chNum, 'type' => $conflictType]);
        // 截断至 20 条
        $history = array_slice($history, 0, 20);

        $this->db->set('pacing', 'conflict_history', $history);

        // 2. 检测是否为高潮章
        if ($this->isClimaxChapter()) {
            $this->db->set('pacing', 'last_climax_chapter', $chNum);
        }

        // 3. 记录本章功能标签
        $function = $this->detectChapterFunction();
        $this->db->set('pacing', 'last_chapter_function', [
            'chapter'  => $chNum,
            'function' => $function,
        ]);
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

    private function updateStyleState(): void
    {
        // 禁用词使用计数
        $banned = ConstraintConfig::bannedWords();
        if (empty($banned)) return;

        $usage = $this->db->getBannedWordUsage();
        if (!is_array($usage)) $usage = [];

        foreach ($banned as $word) {
            $count = mb_substr_count($this->content, $word);
            if ($count > 0) {
                $usage[$word] = ($usage[$word] ?? 0) + $count;
            }
        }

        $this->db->set('style', 'banned_word_usage', $usage);
    }

    // ============================================================
    //  情节状态更新
    // ============================================================

    private function updatePlotState(): void
    {
        // 巧合计数
        $coincidenceKWs = ['恰好', '正巧', '刚好', '碰巧', '凑巧', '巧合', '恰逢', '不偏不倚', '鬼使神差'];
        $newCount = 0;
        foreach ($coincidenceKWs as $kw) {
            $newCount += mb_substr_count($this->content, $kw);
        }

        if ($newCount > 0) {
            $current = $this->db->getCoincidenceCount();
            $this->db->set('plot', 'coincidence_count', $current + $newCount);
        }
    }
}
