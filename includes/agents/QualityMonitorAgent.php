<?php
/**
 * 质量监控Agent
 * 
 * 职责: 实时监控写作质量,发现问题并生成改进建议
 * 
 * 监控指标:
 * - 整体质量评分
 * - 角色一致性
 * - 剧情连贯性
 * - 爽点有效性
 * - 字数准确率
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BaseAgent.php';

class QualityMonitorAgent extends BaseAgent
{
    /** @var int 小说ID */
    private $novelId;
    
    /** @var array 质量阈值配置 */
    private $qualityThresholds = [
        'excellent' => 85,
        'good' => 70,
        'acceptable' => 60,
        'poor' => 50,
    ];
    
    /**
     * 构造函数
     * 
     * @param int $novelId 小说ID
     */
    public function __construct(int $novelId)
    {
        parent::__construct('quality_monitor');
        $this->novelId = $novelId;
    }
    
    /**
     * 决策: 监控质量并发现问题
     * 
     * @param array $context 决策上下文
     * @return array 决策结果
     */
    public function decide(array $context): array
    {
        $startTime = microtime(true);
        
        // 1. 收集质量指标
        $metrics = $this->collectQualityMetrics($context);
        
        // 2. 识别质量问题
        $issues = $this->identifyQualityIssues($metrics);
        
        // 3. 评估风险等级
        $risks = $this->assessRisks($issues);
        
        // 4. 生成改进建议
        $recommendations = $this->generateRecommendations($issues, $risks);
        
        // 5. 记录决策日志
        $decisionData = [
            'metrics' => $metrics,
            'issues' => $issues,
            'risks' => $risks,
            'recommendations' => $recommendations,
            'execution_time_ms' => (microtime(true) - $startTime) * 1000,
        ];
        
        $this->logDecision($decisionData);
        
        return [
            'success' => true,
            'metrics' => $metrics,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }
    
    /**
     * 收集质量指标
     * 
     * @param array $context 上下文
     * @return array 质量指标
     */
    private function collectQualityMetrics(array $context): array
    {
        $recentChapters = $this->getRecentChapters(10);
        
        return [
            'overall_quality' => $this->calculateOverallQuality($recentChapters),
            'structure_score' => $this->calculateStructureScore($recentChapters),
            'character_consistency' => $this->calculateCharacterConsistency($recentChapters),
            'description_richness' => $this->calculateDescriptionRichness($recentChapters),
            'plot_coherence' => $this->calculatePlotCoherence($recentChapters),
            'coolpoint_effectiveness' => $this->calculateCoolPointEffectiveness($recentChapters),
            'word_count_accuracy' => $this->calculateWordCountAccuracy($recentChapters),
            'foreshadowing_usage' => $this->calculateForeshadowingUsage(),
        ];
    }
    
    /**
     * 识别质量问题
     * 
     * @param array $metrics 质量指标
     * @return array 问题列表
     */
    private function identifyQualityIssues(array $metrics): array
    {
        $issues = [];
        
        // 问题1: 整体质量下降
        if ($metrics['overall_quality'] < $this->qualityThresholds['acceptable']) {
            $issues[] = [
                'type' => 'quality_decline',
                'severity' => 'high',
                'description' => "整体质量评分{$metrics['overall_quality']}分,低于可接受阈值",
                'metric' => $metrics['overall_quality'],
                'threshold' => $this->qualityThresholds['acceptable'],
            ];
        }
        
        // 问题2: 结构问题
        if ($metrics['structure_score'] < $this->qualityThresholds['good']) {
            $issues[] = [
                'type' => 'structure_issue',
                'severity' => 'medium',
                'description' => "章节结构评分{$metrics['structure_score']}分,需要优化",
                'metric' => $metrics['structure_score'],
                'threshold' => $this->qualityThresholds['good'],
            ];
        }
        
        // 问题3: 角色一致性下降
        if ($metrics['character_consistency'] < 0.8) {
            $issues[] = [
                'type' => 'character_inconsistency',
                'severity' => 'high',
                'description' => "角色一致性{$metrics['character_consistency']},存在OOC风险",
                'metric' => $metrics['character_consistency'],
                'threshold' => 0.8,
            ];
        }
        
        // 问题4: 描写贫乏
        if ($metrics['description_richness'] < 0.6) {
            $issues[] = [
                'type' => 'poor_description',
                'severity' => 'medium',
                'description' => "描写丰富度{$metrics['description_richness']},建议增强",
                'metric' => $metrics['description_richness'],
                'threshold' => 0.6,
            ];
        }
        
        // 问题5: 剧情不连贯
        if ($metrics['plot_coherence'] < 0.75) {
            $issues[] = [
                'type' => 'plot_incoherence',
                'severity' => 'high',
                'description' => "剧情连贯性{$metrics['plot_coherence']},存在逻辑问题",
                'metric' => $metrics['plot_coherence'],
                'threshold' => 0.75,
            ];
        }
        
        // 问题6: 爽点效果不佳
        if ($metrics['coolpoint_effectiveness'] < 0.7) {
            $issues[] = [
                'type' => 'weak_coolpoint',
                'severity' => 'medium',
                'description' => "爽点有效性{$metrics['coolpoint_effectiveness']},需要调整",
                'metric' => $metrics['coolpoint_effectiveness'],
                'threshold' => 0.7,
            ];
        }
        
        // 问题7: 字数控制不佳
        if ($metrics['word_count_accuracy'] < 0.8) {
            $issues[] = [
                'type' => 'word_count_issue',
                'severity' => 'medium',
                'description' => "字数准确率{$metrics['word_count_accuracy']},控制不稳定",
                'metric' => $metrics['word_count_accuracy'],
                'threshold' => 0.8,
            ];
        }
        
        // 问题8: 伏笔利用率低
        if ($metrics['foreshadowing_usage'] < 0.5) {
            $issues[] = [
                'type' => 'unused_foreshadowing',
                'severity' => 'low',
                'description' => "伏笔利用率{$metrics['foreshadowing_usage']},存在未回收伏笔",
                'metric' => $metrics['foreshadowing_usage'],
                'threshold' => 0.5,
            ];
        }
        
        return $issues;
    }
    
    /**
     * 评估风险等级
     * 
     * @param array $issues 问题列表
     * @return array 风险评估
     */
    private function assessRisks(array $issues): array
    {
        $risks = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];
        
        foreach ($issues as $issue) {
            $severity = $issue['severity'];
            
            // 计算风险得分
            $riskScore = $this->calculateRiskScore($issue);
            
            $risks[$severity][] = [
                'issue_type' => $issue['type'],
                'risk_score' => $riskScore,
                'impact' => $this->assessImpact($issue),
                'urgency' => $this->assessUrgency($issue),
            ];
        }
        
        return $risks;
    }
    
    /**
     * 生成改进建议
     * 
     * @param array $issues 问题列表
     * @param array $risks 风险评估
     * @return array 改进建议
     */
    private function generateRecommendations(array $issues, array $risks): array
    {
        $recommendations = [];
        
        $recommendationMap = [
            'quality_decline' => [
                'action' => 'enhance_quality_check',
                'description' => '启用严格质量检查模式',
                'priority' => 10,
                'params' => ['strictness' => 'high'],
            ],
            'character_inconsistency' => [
                'action' => 'strengthen_character_tracking',
                'description' => '加强角色一致性检查',
                'priority' => 9,
                'params' => ['check_frequency' => 'every_paragraph'],
            ],
            'plot_incoherence' => [
                'action' => 'enhance_plot_analysis',
                'description' => '启用深度剧情分析',
                'priority' => 9,
                'params' => ['depth' => 'deep'],
            ],
            'weak_coolpoint' => [
                'action' => 'adjust_coolpoint_strategy',
                'description' => '调整爽点策略,增强强度',
                'priority' => 7,
                'params' => ['intensity_boost' => 0.2],
            ],
            'poor_description' => [
                'action' => 'enrich_description',
                'description' => '增加感官描写细节',
                'priority' => 6,
                'params' => ['sensory_details' => true],
            ],
            'word_count_issue' => [
                'action' => 'refine_word_control',
                'description' => '优化字数控制算法',
                'priority' => 5,
                'params' => ['tolerance_reduction' => 0.05],
            ],
            'unused_foreshadowing' => [
                'action' => 'schedule_foreshadowing',
                'description' => '规划伏笔回收计划',
                'priority' => 4,
                'params' => ['look_ahead_chapters' => 5],
            ],
        ];
        
        foreach ($issues as $issue) {
            if (isset($recommendationMap[$issue['type']])) {
                $recommendations[] = array_merge(
                    $recommendationMap[$issue['type']],
                    ['issue' => $issue]
                );
            }
        }
        
        // 按优先级排序
        usort($recommendations, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        return $recommendations;
    }
    
    /**
     * 执行质量改进
     * 
     * @param array $recommendations 改进建议
     * @return array 执行结果
     */
    public function execute(array $recommendations): array
    {
        $results = [];
        
        foreach ($recommendations as $rec) {
            $result = $this->executeRecommendation($rec);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * 执行单个建议
     * 
     * @param array $rec 建议
     * @return array 执行结果
     */
    private function executeRecommendation(array $rec): array
    {
        try {
            switch ($rec['action']) {
                case 'enhance_quality_check':
                    ConfigCenter::set('ws_quality_strictness', 'high', 'string');
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $issueMetric = $rec['issue']['metric'] ?? 'N/A';
                    $issueThreshold = $rec['issue']['threshold'] ?? 'N/A';
                    $this->writeDirective('quality', "本章启用严格质量检查。触发原因：整体质量评分{$issueMetric}分，低于可接受阈值{$issueThreshold}分。重点检查：结构完整性、角色一致性、剧情连贯性。");
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已启用严格质量检查'];
                
                case 'strengthen_character_tracking':
                    ConfigCenter::set('character_check_frequency', 'every_paragraph', 'string');
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $issueMetric = $rec['issue']['metric'] ?? 'N/A';
                    $this->writeDirective('quality', "本章加强角色一致性检查。触发原因：角色一致性{$issueMetric}，存在OOC风险。重点检查：角色对话风格、行为逻辑、性格特征是否与设定一致。");
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已加强角色追踪'];
                
                case 'adjust_coolpoint_strategy':
                    $currentIntensity = ConfigCenter::get('cool_point_intensity', 1.0);
                    ConfigCenter::set('cool_point_intensity', $currentIntensity + 0.2, 'float');
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $issueMetric = $rec['issue']['metric'] ?? 'N/A';
                    $this->writeDirective('quality', "本章增加爽点强度，从{$currentIntensity}提升至" . ($currentIntensity + 0.2) . "。触发原因：爽点有效性{$issueMetric}，低于0.7阈值。重点：增强冲突张力、加快剧情节奏、强化情感冲击。");
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已调整爽点强度'];
                
                case 'enrich_description':
                    ConfigCenter::set('enable_sensory_details', true, 'bool');
                    $this->logAction($this->novelId, $rec['action'], 'success', $rec['params']);
                    $issueMetric = $rec['issue']['metric'] ?? 'N/A';
                    $this->writeDirective('quality', "本章启用感官描写。触发原因：描写丰富度{$issueMetric}，低于0.6阈值。重点：增加视觉、听觉、触觉、嗅觉、味觉描写，丰富场景细节和氛围营造。");
                    return ['action' => $rec['action'], 'status' => 'success', 'message' => '已启用感官描写'];
                
                default:
                    return ['action' => $rec['action'], 'status' => 'skipped', 'message' => '未实现的操作'];
            }
        } catch (\Throwable $e) {
            $this->logAction($this->novelId, $rec['action'], 'failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'action' => $rec['action'],
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    // ==================== 辅助计算方法 ====================
    
    private function getRecentChapters(int $limit): array
    {
        try {
            return DB::fetchAll(
                'SELECT * FROM chapters 
                 WHERE novel_id = ? AND status = "completed"
                 ORDER BY chapter_number DESC LIMIT ?',
                [$this->novelId, $limit]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    private function calculateOverallQuality(array $chapters): float
    {
        if (empty($chapters)) return 100.0;
        
        $total = 0;
        foreach ($chapters as $ch) {
            $total += (float)($ch['quality_score'] ?? 75);
        }
        
        return $total / count($chapters);
    }
    
    private function calculateStructureScore(array $chapters): float
    {
        // 简化的结构评分
        if (empty($chapters)) return 75.0;
        
        $total = 0;
        foreach ($chapters as $ch) {
            // 检查章节是否有合理的开头和结尾
            $content = $ch['content'] ?? '';
            $hasOpening = mb_strlen($content, 'UTF-8') > 100;
            $hasEnding = preg_match('/[。！？][\s]*$/u', $content);
            
            $score = ($hasOpening ? 50 : 0) + ($hasEnding ? 50 : 0);
            $total += $score;
        }
        
        return count($chapters) > 0 ? $total / count($chapters) : 75.0;
    }
    
    private function calculateCharacterConsistency(array $chapters): float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total, 
                        SUM(CASE WHEN consistency_check = "pass" THEN 1 ELSE 0 END) as pass 
                 FROM character_mentions
                 WHERE novel_id = ?',
                [$this->novelId]
            );
            
            return $stats['total'] > 0 ? (int)$stats['pass'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateDescriptionRichness(array $chapters): float
    {
        if (empty($chapters)) return 0.65;
        
        $totalScore = 0;
        foreach ($chapters as $ch) {
            $content = $ch['content'] ?? '';
            if (empty($content)) continue;
            
            // 统计描写性词汇比例
            $wordCount = mb_strlen(preg_replace('/\s+/', '', $content), 'UTF-8');
            $descWords = preg_match_all('/(的|地|得|着|了|过|极其|非常|十分|格外)/u', $content);
            
            $richness = $wordCount > 0 ? min(1.0, $descWords / ($wordCount / 100) / 10) : 0;
            $totalScore += $richness;
        }
        
        return count($chapters) > 0 ? $totalScore / count($chapters) : 0.65;
    }
    
    private function calculatePlotCoherence(array $chapters): float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total, 
                        SUM(CASE WHEN resolved_chapter IS NOT NULL THEN 1 ELSE 0 END) as good 
                 FROM foreshadowing_items
                 WHERE novel_id = ?',
                [$this->novelId]
            );
            
            return $stats['total'] > 0 ? (int)$stats['good'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateCoolPointEffectiveness(array $chapters): float
    {
        try {
            $coolPoints = DB::fetchAll(
                'SELECT * FROM memory_atoms
                 WHERE novel_id = ? AND atom_type = "cool_point"
                 ORDER BY created_at DESC LIMIT 20',
                [$this->novelId]
            );
            
            if (empty($coolPoints)) return 1.0;
            
            $effective = 0;
            foreach ($coolPoints as $cp) {
                $metadata = json_decode($cp['metadata'] ?? '{}', true);
                if (($metadata['intensity'] ?? 0) >= 0.7) {
                    $effective++;
                }
            }
            
            return count($coolPoints) > 0 ? $effective / count($coolPoints) : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateWordCountAccuracy(array $chapters): float
    {
        if (empty($chapters)) return 1.0;
        
        try {
            $targetWords = DB::fetch(
                'SELECT chapter_words FROM novels WHERE id = ?',
                [$this->novelId]
            );
            
            $target = (int)($targetWords['chapter_words'] ?? 3000);
            $tolerance = $target * 0.1;
            
            $accurate = 0;
            foreach ($chapters as $ch) {
                $actual = (int)($ch['words'] ?? 0);
                if (abs($actual - $target) <= $tolerance) {
                    $accurate++;
                }
            }
            
            return count($chapters) > 0 ? $accurate / count($chapters) : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateForeshadowingUsage(): float
    {
        try {
            $stats = DB::fetch(
                'SELECT COUNT(*) as total, 
                        SUM(CASE WHEN resolved_chapter IS NOT NULL THEN 1 ELSE 0 END) as resolved 
                 FROM foreshadowing_items
                 WHERE novel_id = ?',
                [$this->novelId]
            );
            
            return $stats['total'] > 0 ? (int)$stats['resolved'] / (int)$stats['total'] : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }
    
    private function calculateRiskScore(array $issue): float
    {
        $severityWeights = [
            'critical' => 1.0,
            'high' => 0.8,
            'medium' => 0.5,
            'low' => 0.2,
        ];
        
        $weight = $severityWeights[$issue['severity']] ?? 0.5;
        $deviation = abs($issue['metric'] - $issue['threshold']) / max(1, $issue['threshold']);
        
        return min(1.0, $weight * (1 + $deviation));
    }
    
    private function assessImpact(array $issue): string
    {
        $impacts = [
            'quality_decline' => '读者流失风险',
            'character_inconsistency' => '角色崩坏风险',
            'plot_incoherence' => '剧情混乱风险',
            'weak_coolpoint' => '读者体验下降',
            'poor_description' => '阅读体验下降',
            'word_count_issue' => '成本控制问题',
            'unused_foreshadowing' => '剧情完整性问题',
        ];
        
        return $impacts[$issue['type']] ?? '未知影响';
    }
    
    private function assessUrgency(array $issue): string
    {
        if ($issue['severity'] === 'critical' || $issue['severity'] === 'high') {
            return 'immediate';
        } elseif ($issue['severity'] === 'medium') {
            return 'within_24h';
        } else {
            return 'within_week';
        }
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
     * 获取当前章节号
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
}
