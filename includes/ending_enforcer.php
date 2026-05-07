<?php
/**
 * 收尾阶段强制指令系统
 * 
 * 基于1590本小说分析的收尾规律
 * 当小说进入收束期（80-95%）和结局期（95-100%）时
 * 强制注入收尾指令，确保AI自动收尾
 * 
 * @author AI Writing System
 * @version 1.0
 * @date 2026-04-27
 */

defined('APP_LOADED') or die('Direct access denied.');

class EndingEnforcer
{
    private int $novelId;
    private int $targetChapters;
    private int $currentChapter;
    private float $progressPct;
    
    /**
     * 收尾阶段定义
     */
    const ENDING_STAGES = [
        'normal' => [
            'min' => 0,
            'max' => 0.8,
            'name' => '正常期',
            'description' => '正常写作，可以埋设新伏笔',
        ],
        'resolve' => [
            'min' => 0.8,
            'max' => 0.95,
            'name' => '收束期',
            'description' => '开始收尾，禁止新伏笔，回收旧伏笔',
        ],
        'ending' => [
            'min' => 0.95,
            'max' => 1.0,
            'name' => '结局期',
            'description' => '强制收尾，所有线索必须完结',
        ],
    ];
    
    /**
     * 收尾优先级规则
     */
    const RESOLUTION_PRIORITY = [
        'critical' => [
            'name' => '核心主线',
            'description' => '主角核心目标、主要矛盾、核心人物关系',
            'must_resolve_by' => 0.95,  // 必须在95%前解决
        ],
        'major' => [
            'name' => '重要支线',
            'description' => '重要配角、主要势力、关键道具',
            'must_resolve_by' => 0.98,
        ],
        'minor' => [
            'name' => '次要支线',
            'description' => '背景设定、次要人物、环境描写',
            'can_leave_open' => true,  // 可以留白
        ],
    ];
    
    /**
     * 构造函数
     */
    public function __construct(int $novelId, int $currentChapter)
    {
        $this->novelId = $novelId;
        $this->currentChapter = $currentChapter;

        // 获取目标章节数
        $novel = DB::fetch('SELECT target_chapters FROM novels WHERE id=?', [$novelId]);
        $this->targetChapters = (int)($novel['target_chapters'] ?? 0);

        if ($this->targetChapters <= 0) {
            $this->progressPct = 0;
            return;
        }

        $this->progressPct = $this->currentChapter / $this->targetChapters;
    }
    
    /**
     * 获取当前收尾阶段
     */
    public function getEndingStage(): string
    {
        foreach (self::ENDING_STAGES as $stage => $config) {
            if ($this->progressPct >= $config['min'] && $this->progressPct < $config['max']) {
                return $stage;
            }
        }
        return 'ending';
    }
    
    /**
     * 检查是否需要强制收尾
     */
    public function needsEndingEnforcement(): bool
    {
        if ($this->targetChapters <= 0) return false;
        return $this->progressPct >= 0.8;
    }
    
    /**
     * 获取待回收的伏笔（按优先级和截止章排序）
     * priority 字段：critical（核心）、major（重要）、minor（次要）
     */
    public function getPendingForeshadowing(): array
    {
        $pending = DB::fetchAll(
            'SELECT planted_chapter as chapter, description, deadline_chapter as deadline,
                    COALESCE(priority, "minor") as priority
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
             ORDER BY
                CASE COALESCE(priority, "minor")
                    WHEN "critical" THEN 1
                    WHEN "major" THEN 2
                    WHEN "minor" THEN 3
                END,
                deadline_chapter ASC',
            [$this->novelId]
        );

        return $pending ?: [];
    }
    
    /**
     * 获取未解决的主要矛盾
     * 实际数据存储在 memory_atoms 表，通过 metadata.is_key_event=1 标记
     */
    public function getUnresolvedConflicts(): array
    {
        $conflicts = DB::fetchAll(
            'SELECT source_chapter as chapter, content as event,
                    COALESCE(JSON_EXTRACT(metadata, "$.importance"), 5) + 0.0 as importance
             FROM memory_atoms
             WHERE novel_id=? AND atom_type="plot_detail"
               AND JSON_EXTRACT(metadata, "$.is_key_event") = 1
             ORDER BY importance DESC, source_chapter ASC',
            [$this->novelId]
        );

        return $conflicts ?: [];
    }
    
    /**
     * 生成收尾强制指令
     */
    public function generateEndingInstructions(): string
    {
        $stage = $this->getEndingStage();
        
        // 正常期不需要收尾指令
        if ($stage === 'normal') {
            return '';
        }
        
        $lines = [];
        $stageConfig = self::ENDING_STAGES[$stage];
        
        // ── 收尾阶段头部 ─────────────────────────────────────
        $lines[] = "【🚨 收尾阶段强制指令——必须严格遵守】";
        $lines[] = "";
        $lines[] = "⚠️ 当前阶段：{$stageConfig['name']}（进度" . round($this->progressPct * 100, 1) . "%）";
        $lines[] = "阶段说明：{$stageConfig['description']}";
        $lines[] = "";
        
        // ── 收束期指令（80-95%）─────────────────────────────
        if ($stage === 'resolve') {
            $lines[] = "【收束期强制规则】";
            $lines[] = "";
            $lines[] = "1. ❌ 禁止引入新伏笔";
            $lines[] = "   · 不得再埋设任何需要后续章节回收的线索";
            $lines[] = "   · 不得引入新的主要人物或势力";
            $lines[] = "   · 不得开启新的故事线";
            $lines[] = "";
            $lines[] = "2. ✅ 必须回收旧伏笔";
            
            // 获取待回收伏笔
            $pending = $this->getPendingForeshadowing();
            if (!empty($pending)) {
                $lines[] = "   待回收伏笔列表（按优先级分组）：";
                $priorityGroups = ['critical' => [], 'major' => [], 'minor' => []];
                foreach ($pending as $p) {
                    $priority = $p['priority'] ?? 'minor';
                    if (!isset($priorityGroups[$priority])) {
                        $priority = 'minor';
                    }
                    $deadline = (int)($p['deadline'] ?? 0);
                    $deadlineText = $deadline > 0 ? "（建议第{$deadline}章前回收）" : "";
                    $priorityGroups[$priority][] = "      第{$p['chapter']}章埋：{$p['description']}{$deadlineText}";
                }

                if (!empty($priorityGroups['critical'])) {
                    $lines[] = "   🔴 核心伏笔（必须回收）：";
                    $lines = array_merge($lines, $priorityGroups['critical']);
                }
                if (!empty($priorityGroups['major'])) {
                    $lines[] = "   🟠 重要伏笔（建议回收）：";
                    $lines = array_merge($lines, $priorityGroups['major']);
                }
                if (!empty($priorityGroups['minor'])) {
                    $lines[] = "   🟢 次要伏笔（可选回收）：";
                    $lines = array_merge($lines, $priorityGroups['minor']);
                }
            } else {
                $lines[] = "   · 暂无待回收伏笔";
            }
            $lines[] = "";
            $lines[] = "3. 📊 收束节奏要求";
            $lines[] = "   · 每章至少回收1-2个伏笔或推进1条主线";
            $lines[] = "   · 冲突强度逐步提升，为结局做准备";
            $lines[] = "   · 主要人物命运开始明朗化";
            $lines[] = "";
            $lines[] = "4. ⚖️ 收束优先级";
            $lines[] = "   · 核心主线（主角目标、主要矛盾）→ 优先解决";
            $lines[] = "   · 重要支线（主要配角、关键势力）→ 逐步收束";
            $lines[] = "   · 次要支线（背景设定、环境描写）→ 可以留白";
            $lines[] = "";
        }
        
        // ── 结局期指令（95-100%）─────────────────────────────
        if ($stage === 'ending') {
            $remaining = $this->targetChapters - $this->currentChapter;
            
            $lines[] = "【结局期强制规则——最高优先级】";
            $lines[] = "";
            $lines[] = "🚨 警告：全书即将完结，剩余{$remaining}章！";
            $lines[] = "";
            $lines[] = "1. ❌ 绝对禁止事项";
            $lines[] = "   · 禁止引入任何新角色、新势力、新伏笔";
            $lines[] = "   · 禁止开启任何新的故事线";
            $lines[] = "   · 禁止留下任何核心主线未解决";
            $lines[] = "";
            $lines[] = "2. ✅ 强制完成事项";
            
            // 获取未解决的矛盾
            $conflicts = $this->getUnresolvedConflicts();
            if (!empty($conflicts)) {
                $lines[] = "   未解决的核心矛盾（必须在本章或剩余章节解决）：";
                foreach ($conflicts as $c) {
                    $importance = (int)($c['importance'] ?? 5);
                    $urgency = $importance >= 9 ? '🔴紧急' : ($importance >= 7 ? '🟠重要' : '🟢一般');
                    $lines[] = "   · [{$urgency}] 第{$c['chapter']}章：{$c['event']}";
                }
            }
            
            // 获取待回收伏笔
            $pending = $this->getPendingForeshadowing();
            $criticalPending = array_filter($pending, fn($p) => ($p['priority'] ?? '') === 'critical');
            if (!empty($criticalPending)) {
                $lines[] = "";
                $lines[] = "   🔴 核心伏笔（必须回收）：";
                foreach ($criticalPending as $p) {
                    $lines[] = "   · 第{$p['chapter']}章埋：{$p['description']}";
                }
            }
            $lines[] = "";
            
            // 根据剩余章数给出具体指令
            if ($remaining <= 1) {
                $lines[] = "3. 🎬 最终章要求";
                $lines[] = "   · 必须解决所有核心矛盾";
                $lines[] = "   · 必须给出主角的最终结局";
                $lines[] = "   · 必须回收所有核心伏笔";
                $lines[] = "   · 结尾要有情感爆发点（大团圆/悲壮/开放式）";
                $lines[] = "   · 不得留下任何悬念（除非明确设计为开放式结局）";
            } elseif ($remaining <= 3) {
                $lines[] = "3. 📝 倒数第{$remaining}章要求";
                $lines[] = "   · 必须开始解决核心矛盾";
                $lines[] = "   · 主要人物命运必须明确";
                $lines[] = "   · 至少回收50%的核心伏笔";
                $lines[] = "   · 为最终章做铺垫";
            } elseif ($remaining <= 5) {
                $lines[] = "3. 📝 收尾阶段要求";
                $lines[] = "   · 逐步解决主要矛盾";
                $lines[] = "   · 回收重要伏笔";
                $lines[] = "   · 主要人物命运开始定型";
            }
            $lines[] = "";
            
            $lines[] = "4. 🎯 结局质量标准";
            $lines[] = "   · 情感爆发：结局必须有强烈的情感冲击";
            $lines[] = "   · 逻辑自洽：所有事件必须有因果解释";
            $lines[] = "   · 人物弧光：主要人物必须有成长或变化";
            $lines[] = "   · 读者满意：给读者一个完整的交代";
            $lines[] = "";
        }
        
        // ── 收尾检查清单 ─────────────────────────────────────
        $lines[] = "【收尾检查清单】";
        $lines[] = "";
        $lines[] = "写作本章前，请确认：";
        
        if ($stage === 'resolve') {
            $lines[] = "□ 是否有新伏笔被引入？（应为否）";
            $lines[] = "□ 是否回收了至少1个旧伏笔？";
            $lines[] = "□ 是否推进了主线剧情？";
            $lines[] = "□ 冲突强度是否在提升？";
        }
        
        if ($stage === 'ending') {
            $lines[] = "□ 是否解决了至少1个核心矛盾？";
            $lines[] = "□ 是否回收了至少1个核心伏笔？";
            $lines[] = "□ 主要人物命运是否更明确？";
            $lines[] = "□ 是否为最终结局做好了准备？";
        }
        
        $lines[] = "";
        $lines[] = "⚠️ 违反上述规则将导致小说烂尾！请务必严格遵守！";
        
        return implode("\n", $lines);
    }
    
    /**
     * 生成伏笔回收建议
     */
    public function generateForeshadowResolutionAdvice(): string
    {
        $pending = $this->getPendingForeshadowing();
        
        if (empty($pending)) {
            return '';
        }
        
        $lines = [];
        $lines[] = "【伏笔回收建议】";
        $lines[] = "";
        
        // 按优先级分组
        $critical = array_filter($pending, fn($p) => ($p['priority'] ?? '') === 'critical');
        $major = array_filter($pending, fn($p) => ($p['priority'] ?? '') === 'major');
        $minor = array_filter($pending, fn($p) => ($p['priority'] ?? '') === 'minor');
        
        if (!empty($critical)) {
            $lines[] = "🔴 核心伏笔（必须回收）：";
            foreach ($critical as $p) {
                $lines[] = "· 第{$p['chapter']}章：{$p['description']}";
                $lines[] = "  建议回收方式：自然融入主线剧情，给读者惊喜感";
            }
            $lines[] = "";
        }
        
        if (!empty($major)) {
            $lines[] = "🟠 重要伏笔（建议回收）：";
            foreach ($major as $p) {
                $lines[] = "· 第{$p['chapter']}章：{$p['description']}";
            }
            $lines[] = "";
        }
        
        if (!empty($minor)) {
            $lines[] = "🟢 次要伏笔（可以留白）：";
            foreach ($minor as $p) {
                $lines[] = "· 第{$p['chapter']}章：{$p['description']}";
            }
            $lines[] = "";
            $lines[] = "注：次要伏笔可以不回收，留白反而增加真实感";
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * 检查本章是否符合收尾要求
     * （用于写作后评估）
     */
    public function checkEndingCompliance(string $content): array
    {
        $result = [
            'passed' => true,
            'issues' => [],
            'warnings' => [],
            'suggestions' => [],
        ];
        
        $stage = $this->getEndingStage();
        
        // 正常期不检查
        if ($stage === 'normal') {
            return $result;
        }
        
        // 检测是否引入了新伏笔关键词
        $foreshadowKeywords = [
            '伏笔', '暗示', '埋下', '暗中', '似乎有什么',
            '神秘', '未完待续', '后续', '将来', '某天',
        ];
        
        foreach ($foreshadowKeywords as $keyword) {
            if (mb_strpos($content, $keyword) !== false) {
                $result['warnings'][] = "检测到可能的伏笔关键词：「{$keyword}」";
            }
        }
        
        // 检测是否引入了新角色
        $newCharacterPatterns = [
            '/新来的/', '/从未见过的/', '/陌生的/', '/第一次见到/',
        ];
        
        foreach ($newCharacterPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $result['warnings'][] = "检测到可能的新角色引入";
                break;
            }
        }
        
        // 收束期检查
        if ($stage === 'resolve') {
            // 检查是否有伏笔回收
            $resolveKeywords = ['原来', '真相', '终于', '揭晓', '答案'];
            $hasResolution = false;
            foreach ($resolveKeywords as $keyword) {
                if (mb_strpos($content, $keyword) !== false) {
                    $hasResolution = true;
                    break;
                }
            }
            
            if (!$hasResolution) {
                $result['suggestions'][] = "建议在本章回收至少1个伏笔";
            }
        }
        
        // 结局期检查
        if ($stage === 'ending') {
            // 检查是否有结局关键词
            $endingKeywords = ['结局', '终章', '完结', '最后', '最终'];
            $hasEnding = false;
            foreach ($endingKeywords as $keyword) {
                if (mb_strpos($content, $keyword) !== false) {
                    $hasEnding = true;
                    break;
                }
            }
            
            $remaining = $this->targetChapters - $this->currentChapter;
            if ($remaining <= 1 && !$hasEnding) {
                $result['suggestions'][] = "最终章应有明确的结局感";
            }
        }
        
        return $result;
    }
    
    /**
     * 获取所有收尾阶段定义（用于前端展示）
     */
    public static function getAllStages(): array
    {
        return self::ENDING_STAGES;
    }
}
