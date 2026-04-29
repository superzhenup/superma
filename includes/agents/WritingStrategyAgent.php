<?php
/**
 * 写作策略Agent
 * 
 * 职责: 根据小说特征和历史数据动态调整写作策略
 * 
 * 决策内容:
 * - 字数目标调整
 * - 爽点密度调整
 * - 节奏控制策略
 * - Prompt模板选择
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseAgent.php';

class WritingStrategyAgent extends BaseAgent
{
    /** @var string 小说题材 */
    private $genre;
    
    /** @var int 当前已写章节数 */
    private $currentChapter;
    
    /** @var int 目标章节数 */
    private $targetChapters;
    
    /** @var array 题材特征配置 */
    private $genreConfigs = [
        '玄幻' => ['coolpoint_density' => 1.2, 'pacing' => 'fast'],
        '都市' => ['coolpoint_density' => 1.0, 'pacing' => 'medium'],
        '言情' => ['coolpoint_density' => 0.8, 'pacing' => 'slow'],
        '科幻' => ['coolpoint_density' => 1.0, 'pacing' => 'medium'],
        '历史' => ['coolpoint_density' => 0.9, 'pacing' => 'slow'],
    ];
    
    /**
     * 构造函数
     * 
     * @param int $novelId 小说ID
     */
    public function __construct(int $novelId)
    {
        parent::__construct('writing_strategy', $novelId);
        $this->loadNovelInfo();
    }
    
    /**
     * 决策: 调整写作策略
     * 
     * @param array $context 决策上下文
     * @return array 决策结果
     */
    public function decide(array $context): array
    {
        $startTime = microtime(true);
        
        // 1. 验证上下文
        $validation = $this->validateContext($context, ['pending_foreshadowing_count']);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => '缺少必需字段: ' . implode(', ', $validation['missing']),
            ];
        }
        
        // 2. 收集决策依据
        $factors = $this->collectDecisionFactors($context);
        
        // 3. 分析当前状态
        $analysis = $this->analyzeCurrentState($factors);
        
        // 4. 生成决策建议
        $decisions = $this->generateDecisions($analysis, $factors);
        
        // 5. 记录决策日志
        $decisionData = [
            'factors' => $factors,
            'analysis' => $analysis,
            'decisions' => $decisions,
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ];
        
        $this->logDecision($decisionData);
        
        return [
            'success' => true,
            'decisions' => $decisions,
            'analysis' => $analysis,
        ];
    }
    
    /**
     * 收集决策依据
     * 
     * @param array $context 上下文
     * @return array 决策因素
     */
    private function collectDecisionFactors(array $context): array
    {
        return [
            'genre' => $this->genre,
            'progress' => $this->targetChapters > 0 ? $this->currentChapter / $this->targetChapters : 0,
            'avg_quality_score' => $this->getAverageQualityScore(),
            'recent_word_accuracy' => $this->getRecentWordAccuracy(),
            'recent_coolpoint_density' => $this->getRecentCoolPointDensity(),
            'api_success_rate' => $this->getAPISuccessRate(),
            'pending_foreshadowing' => $context['pending_foreshadowing_count'] ?? 0,
            'current_chapter' => $this->currentChapter,
        ];
    }
    
    /**
     * 分析当前状态
     * 
     * @param array $factors 决策因素
     * @return array 状态分析
     */
    private function analyzeCurrentState(array $factors): array
    {
        $analysis = [
            'strengths' => [],
            'weaknesses' => [],
            'opportunities' => [],
            'threats' => [],
            'recommendations' => [],
        ];
        
        // 分析质量
        if ($factors['avg_quality_score'] >= 80) {
            $analysis['strengths'][] = '质量评分优秀';
        } elseif ($factors['avg_quality_score'] < 60) {
            $analysis['weaknesses'][] = '质量评分偏低';
            $analysis['recommendations'][] = '建议启用严格质量检查';
        }
        
        // 分析字数准确率
        if ($factors['recent_word_accuracy'] >= 0.9) {
            $analysis['strengths'][] = '字数控制准确';
        } elseif ($factors['recent_word_accuracy'] < 0.7) {
            $analysis['weaknesses'][] = '字数控制不佳';
            $analysis['recommendations'][] = '建议调整字数容差';
        }
        
        // 分析爽点密度
        $targetDensity = $this->getTargetCoolPointDensity();
        if ($factors['recent_coolpoint_density'] < $targetDensity * 0.8) {
            $analysis['opportunities'][] = '爽点密度可提升';
            $analysis['recommendations'][] = '建议增加爽点密度';
        }
        
        // 分析进度
        if ($factors['progress'] > 0.8) {
            $analysis['threats'][] = '接近完结,需回收伏笔';
        } elseif ($factors['progress'] < 0.2) {
            $analysis['opportunities'][] = '开篇阶段,可大胆铺垫';
        }
        
        // 分析伏笔
        if ($factors['pending_foreshadowing'] > 10) {
            $analysis['threats'][] = '伏笔堆积过多';
            $analysis['recommendations'][] = '建议规划伏笔回收';
        }
        
        return $analysis;
    }
    
    /**
     * 生成决策建议
     * 
     * @param array $analysis 状态分析
     * @param array $factors 决策因素
     * @return array 决策建议
     */
    private function generateDecisions(array $analysis, array $factors): array
    {
        $decisions = [
            'word_count_adjustment' => null,
            'coolpoint_density_adjustment' => null,
            'pacing_strategy' => null,
            'foreshadowing_strategy' => null,
            'reasoning' => [],
        ];
        
        // 决策1: 字数调整
        if (in_array('字数控制不佳', $analysis['weaknesses'])) {
            $decisions['word_count_adjustment'] = [
                'action' => 'increase_tolerance',
                'value' => 0.15,
                'reason' => '字数控制不佳,增加容差范围至±15%',
            ];
            $decisions['reasoning'][] = '字数准确率低于70%,需要放宽容差';
        }
        
        // 决策2: 爽点密度调整
        foreach ($analysis['opportunities'] as $opp) {
            if (strpos($opp, '爽点密度可提升') !== false) {
                $targetDensity = $this->getTargetCoolPointDensity();
                $decisions['coolpoint_density_adjustment'] = [
                    'action' => 'increase_density',
                    'value' => $targetDensity * 1.2,
                    'reason' => '爽点密度低于目标,建议提升20%',
                ];
                $decisions['reasoning'][] = '当前爽点密度不足,需要增强';
                break;
            }
        }
        
        // 决策3: 节奏策略
        if ($factors['progress'] > 0.8) {
            $decisions['pacing_strategy'] = [
                'action' => 'accelerate',
                'reason' => '接近完结,加快节奏',
            ];
            $decisions['reasoning'][] = '进度超过80%,进入收尾阶段';
        } elseif ($factors['progress'] < 0.2) {
            $decisions['pacing_strategy'] = [
                'action' => 'steady',
                'reason' => '开篇阶段,稳步推进',
            ];
        }
        
        // 决策4: 伏笔策略
        if ($factors['pending_foreshadowing'] > 10) {
            $decisions['foreshadowing_strategy'] = [
                'action' => 'prioritize_resolution',
                'value' => min(3, $factors['pending_foreshadowing'] - 5),
                'reason' => '伏笔堆积,优先回收',
            ];
            $decisions['reasoning'][] = "待回收伏笔{$factors['pending_foreshadowing']}个,需要优先处理";
        }
        
        return $decisions;
    }
    
    /**
     * 执行决策
     * 
     * @param array $decisions 决策建议
     * @return array 执行结果
     */
    public function execute(array $decisions): array
    {
        $results = [];
        
        // 执行字数调整
        if (!empty($decisions['word_count_adjustment'])) {
            $result = $this->executeWordCountAdjustment($decisions['word_count_adjustment']);
            $results['word_count_adjustment'] = $result;
            
            // 写入自然语言指令
            if ($result['status'] === 'success') {
                $this->writeDirective('strategy', 
                    "字数控制调整：{$decisions['word_count_adjustment']['reason']}");
            }
        }
        
        // 执行爽点密度调整
        if (!empty($decisions['coolpoint_density_adjustment'])) {
            $result = $this->executeCoolPointAdjustment($decisions['coolpoint_density_adjustment']);
            $results['coolpoint_density_adjustment'] = $result;
            
            // 写入自然语言指令
            if ($result['status'] === 'success') {
                $this->writeDirective('strategy', 
                    "爽点密度调整：{$decisions['coolpoint_density_adjustment']['reason']}");
            }
        }
        
        // 执行节奏策略
        if (!empty($decisions['pacing_strategy'])) {
            $result = $this->executePacingStrategy($decisions['pacing_strategy']);
            $results['pacing_strategy'] = $result;
            
            // 写入自然语言指令
            if ($result['status'] === 'success') {
                $this->writeDirective('strategy', 
                    "节奏策略调整：{$decisions['pacing_strategy']['reason']}");
            }
        }
        
        // 执行伏笔策略
        if (!empty($decisions['foreshadowing_strategy'])) {
            $result = $this->executeForeshadowingStrategy($decisions['foreshadowing_strategy']);
            $results['foreshadowing_strategy'] = $result;
            
            // 写入自然语言指令
            if ($result['status'] === 'success') {
                $this->writeDirective('strategy', 
                    "伏笔策略调整：{$decisions['foreshadowing_strategy']['reason']}");
            }
        }
        
        return $results;
    }
    
    /**
     * 执行字数调整
     */
    private function executeWordCountAdjustment(array $decision): array
    {
        try {
            $currentTolerance = ConfigCenter::get('ws_chapter_word_tolerance_ratio', 0.1);
            $newTolerance = $decision['value'];
            
            ConfigCenter::set('ws_chapter_word_tolerance_ratio', $newTolerance, 'float');
            
            $this->logAction($this->novelId, 'adjust_word_tolerance', 'success', [
                'old_value' => $currentTolerance,
                'new_value' => $newTolerance,
            ]);
            
            return [
                'action' => 'adjust_word_tolerance',
                'status' => 'success',
                'message' => "字数容差已从{$currentTolerance}调整至{$newTolerance}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'adjust_word_tolerance', 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => 'adjust_word_tolerance',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 执行爽点密度调整
     */
    private function executeCoolPointAdjustment(array $decision): array
    {
        try {
            $currentDensity = ConfigCenter::get('ws_cool_point_density_target', 1.0);
            $newDensity = $decision['value'];
            
            ConfigCenter::set('ws_cool_point_density_target', $newDensity, 'float');
            
            $this->logAction($this->novelId, 'adjust_coolpoint_density', 'success', [
                'old_value' => $currentDensity,
                'new_value' => $newDensity,
            ]);
            
            return [
                'action' => 'adjust_coolpoint_density',
                'status' => 'success',
                'message' => "爽点密度已从{$currentDensity}调整至{$newDensity}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'adjust_coolpoint_density', 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => 'adjust_coolpoint_density',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 执行节奏策略
     */
    private function executePacingStrategy(array $decision): array
    {
        try {
            $pacing = $decision['action'];
            
            ConfigCenter::set('ws_pacing_strategy', $pacing, 'string');
            
            $this->logAction($this->novelId, 'adjust_pacing', 'success', [
                'pacing' => $pacing,
            ]);
            
            return [
                'action' => 'adjust_pacing',
                'status' => 'success',
                'message' => "节奏策略已设置为: {$pacing}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'adjust_pacing', 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => 'adjust_pacing',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    // ==================== 辅助方法 ====================
    
    /**
     * 加载小说信息
     */
    private function loadNovelInfo(): void
    {
        try {
            $novel = DB::fetch(
                'SELECT genre, target_chapters FROM novels WHERE id = ?',
                [$this->novelId]
            );
            
            $this->genre = $novel['genre'] ?? '玄幻';
            $this->targetChapters = (int)($novel['target_chapters'] ?? 100);
            
            $this->currentChapter = (int)(DB::fetch(
                'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id = ? AND status = "completed"',
                [$this->novelId]
            )['cnt'] ?? 0);
        } catch (\Throwable $e) {
            error_log("加载小说信息失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取平均质量评分
     */
    private function getAverageQualityScore(): float
    {
        try {
            $score = DB::fetch(
                'SELECT AVG(quality_score) as avg FROM chapters 
                 WHERE novel_id = ? AND quality_score IS NOT NULL
                 ORDER BY chapter_number DESC LIMIT 10',
                [$this->novelId]
            );
            
            return (float)($score['avg'] ?? 75);
        } catch (\Throwable $e) {
            return 75.0;
        }
    }
    
    /**
     * 获取近期字数准确率
     */
    private function getRecentWordAccuracy(): float
    {
        try {
            $chapters = DB::fetchAll(
                'SELECT c.words, n.chapter_words as target
                 FROM chapters c
                 JOIN novels n ON c.novel_id = n.id
                 WHERE c.novel_id = ? AND c.status = "completed"
                 ORDER BY c.chapter_number DESC LIMIT 10',
                [$this->novelId]
            );
            
            if (empty($chapters)) return 1.0;
            
            $accurate = 0;
            foreach ($chapters as $ch) {
                $target = (int)$ch['target'];
                $actual = (int)$ch['words'];
                $tolerance = $target * 0.1;
                
                if (abs($actual - $target) <= $tolerance) {
                    $accurate++;
                }
            }
            
            return count($chapters) > 0 ? $accurate / count($chapters) : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    /**
     * 获取近期爽点密度
     */
    private function getRecentCoolPointDensity(): float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total FROM memory_atoms
                 WHERE novel_id = ? AND atom_type = "cool_point"
                   AND source_chapter >= ?',
                [$this->novelId, max(1, $this->currentChapter - 20)]
            );
            
            $count = (int)($stats['total'] ?? 0);
            $chapters = min(20, max(1, $this->currentChapter));
            
            return $count / $chapters;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    /**
     * 获取API成功率（基于chapters表，v1.5修复）
     */
    private function getAPISuccessRate(): float
    {
        try {
            // v1.5: 用chapters表替代不存在的performance_logs
            $stats = DB::fetch(
                'SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed
                 FROM chapters
                 WHERE novel_id = ?
                   AND chapter_number >= GREATEST(1, (SELECT MAX(chapter_number) - 20 FROM chapters WHERE novel_id = ?))',
                [$this->novelId, $this->novelId]
            );
            
            return $stats['total'] > 0 ? (int)$stats['completed'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    /**
     * 获取目标爽点密度(基于题材)
     */
    private function getTargetCoolPointDensity(): float
    {
        return $this->genreConfigs[$this->genre]['coolpoint_density'] ?? 1.0;
    }
    
    /**
     * 写入Agent指令
     */
    private function writeDirective(string $type, string $directive): void
    {
        try {
            require_once __DIR__ . '/AgentDirectives.php';
            
            // 获取下一个要写的章节号（getCurrentChapterNumber() 已返回 COUNT(*) + 1）
            $nextChapter = $this->getCurrentChapterNumber();
            
            AgentDirectives::add(
                $this->novelId,
                $nextChapter,
                $type,
                $directive,
                3, // 生效3章
                24 // 24小时后过期
            );
        } catch (\Throwable $e) {
            error_log("写入Agent指令失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取当前章节号（已completed章节数+1，即下一个要写的章节）
     */
    private function getCurrentChapterNumber(): int
    {
        try {
            $result = DB::fetch(
                'SELECT COUNT(*) + 1 as next_chapter 
                 FROM chapters 
                 WHERE novel_id = ? AND status = "completed"',
                [$this->novelId]
            );
            
            return (int)($result['next_chapter'] ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }
    
    /**
     * 执行伏笔策略
     */
    private function executeForeshadowingStrategy(array $decision): array
    {
        try {
            $action = $decision['action'];
            $value = $decision['value'] ?? 1;
            
            // 这里可以添加具体的伏笔回收逻辑
            // 目前只是记录日志
            $this->logAction($this->novelId, 'foreshadowing_strategy', 'success', [
                'action' => $action,
                'value' => $value,
                'reason' => $decision['reason'] ?? '',
            ]);
            
            return [
                'action' => 'foreshadowing_strategy',
                'status' => 'success',
                'message' => "伏笔策略已执行: {$action}",
            ];
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, 'foreshadowing_strategy', 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => 'foreshadowing_strategy',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
}
