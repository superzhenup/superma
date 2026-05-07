<?php
/**
 * 动态节奏调整器
 * 
 * 基于1590本小说分析的节奏标准
 * 根据全书进度、近期爽点历史、章节序号
 * 动态调整本章的节奏参数和爽点要求
 * 
 * @author AI Writing System
 * @version 1.0
 * @date 2026-04-27
 */

defined('APP_LOADED') or die('Direct access denied.');

class RhythmAdjuster
{
    protected int $novelId;
    protected int $targetChapters;
    
    /**
     * 节奏阶段定义
     * 基于研究资料：小高潮每3-5章，中高潮每10-15章，大高潮每30-50章
     */
    const RHYTHM_STAGES = [
        'setup' => [
            'min' => 0, 
            'max' => 0.2, 
            'name' => '铺垫期',
            'description' => '建立世界观和人物关系，节奏较慢',
            'tension_level' => 4,
        ],
        'rising' => [
            'min' => 0.2, 
            'max' => 0.5, 
            'name' => '发展期',
            'description' => '逐步提升冲突，每5章一个爽点',
            'tension_level' => 6,
        ],
        'climax' => [
            'min' => 0.5, 
            'max' => 0.8, 
            'name' => '高潮期',
            'description' => '密集爽点，每3章一个高潮',
            'tension_level' => 8,
        ],
        'resolve' => [
            'min' => 0.8, 
            'max' => 0.95, 
            'name' => '收束期',
            'description' => '回收伏笔，准备结局',
            'tension_level' => 7,
        ],
        'ending' => [
            'min' => 0.95, 
            'max' => 1.0, 
            'name' => '结局期',
            'description' => '最终高潮，所有伏笔必须回收',
            'tension_level' => 9,
        ],
    ];
    
    /**
     * 构造函数
     */
    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
        
        // 获取目标章节数
        $novel = DB::fetch('SELECT target_chapters FROM novels WHERE id=?', [$novelId]);
        $this->targetChapters = (int)($novel['target_chapters'] ?? 100);
    }
    
    /**
     * 计算当前章节的节奏参数
     * 
     * @param int $currentChapter 当前章节号
     * @param array $coolPointHistory 近期爽点历史 [['chapter'=>N,'type'=>'xxx'], ...]
     * @return array 节奏参数
     */
    public function calculateRhythm(int $currentChapter, array $coolPointHistory = []): array
    {
        $progressPct = $this->targetChapters > 0 
            ? $currentChapter / $this->targetChapters 
            : 0;
        
        $stage = $this->getStage($progressPct);
        $stageConfig = self::RHYTHM_STAGES[$stage];
        
        $result = [
            'stage'               => $stage,
            'stage_name'          => $stageConfig['name'],
            'stage_description'   => $stageConfig['description'],
            'progress_pct'        => $progressPct,
            'progress_percent'    => round($progressPct * 100, 1),
            'current_chapter'     => $currentChapter,
            'target_chapters'     => $this->targetChapters,
            'remaining_chapters'  => max(0, $this->targetChapters - $currentChapter),
            
            'require_cool_point'  => false,
            'cool_point_type'     => null,
            'cool_point_urgency'  => 'normal',
            
            'tension_level'       => $stageConfig['tension_level'],
            'tension_description' => $this->getTensionDescription($stageConfig['tension_level']),
            
            'segment_ratios'      => [],
            'paragraph_ratio'     => ['long' => 0.2, 'medium' => 0.5, 'short' => 0.3],
            
            'instructions'        => [],
            'warnings'            => [],
        ];
        
        // ── 根据阶段调整 ─────────────────────────────────────
        switch ($stage) {
            case 'setup':
                // 铺垫期：建立世界观，节奏较慢
                $result['tension_level'] = 4;
                $result['segment_ratios'] = [
                    'setup'  => 30,
                    'rising' => 30,
                    'climax' => 25,
                    'hook'   => 15,
                ];
                $result['paragraph_ratio'] = ['long' => 0.3, 'medium' => 0.5, 'short' => 0.2];
                $result['require_cool_point'] = ($currentChapter % 5 == 0);  // 每5章一个爽点
                $result['instructions'][] = "📖 铺垫期：充分建立世界观和人物关系，节奏宜慢不宜快";
                $result['instructions'][] = "建议：多描写环境、人物性格、世界观设定";
                break;
                
            case 'rising':
                // 发展期：逐步提升冲突
                $result['tension_level'] = 6;
                $result['segment_ratios'] = [
                    'setup'  => 20,
                    'rising' => 35,
                    'climax' => 30,
                    'hook'   => 15,
                ];
                $result['require_cool_point'] = ($currentChapter % 5 == 0);  // 每5章一个爽点
                $result['instructions'][] = "📈 发展期：逐步提升冲突强度，每5章安排一个爽点";
                $result['instructions'][] = "建议：逐步建立矛盾，埋设伏笔";
                break;
                
            case 'climax':
                // 高潮期：密集爽点
                $result['tension_level'] = 8;
                $result['segment_ratios'] = [
                    'setup'  => 15,
                    'rising' => 30,
                    'climax' => 40,
                    'hook'   => 15,
                ];
                $result['require_cool_point'] = ($currentChapter % 3 == 0);  // 每3章一个爽点
                $result['cool_point_urgency'] = 'high';
                $result['instructions'][] = "🔥 高潮期：密集爽点，每3章一个高潮，节奏加快";
                $result['instructions'][] = "建议：集中释放冲突，制造大场面";
                break;
                
            case 'resolve':
                // 收束期：回收伏笔，准备结局
                $result['tension_level'] = 7;
                $result['segment_ratios'] = [
                    'setup'  => 10,
                    'rising' => 25,
                    'climax' => 50,
                    'hook'   => 15,
                ];
                $result['require_cool_point'] = true;  // 每章都要有爽点/回收
                $result['cool_point_urgency'] = 'high';
                $result['instructions'][] = "🎯 收束期：回收伏笔，每章都要有进展，节奏紧凑";
                $result['instructions'][] = "建议：开始回收主要伏笔，解决次要矛盾";
                break;
                
            case 'ending':
                // 结局期：最终高潮
                $result['tension_level'] = 9;
                $result['segment_ratios'] = [
                    'setup'  => 5,
                    'rising' => 20,
                    'climax' => 60,
                    'hook'   => 15,
                ];
                $result['require_cool_point'] = true;
                $result['cool_point_urgency'] = 'critical';
                $result['instructions'][] = "🚨 结局期：最终高潮，所有伏笔必须回收，节奏最快";
                $result['instructions'][] = "建议：集中解决所有矛盾，给读者完整结局";
                break;
        }
        
        // ── 根据近期爽点历史调整 ─────────────────────────────────
        $recentCoolPoints = $this->getRecentCoolPoints($coolPointHistory, $currentChapter, 5);
        $coolPointCount = count($recentCoolPoints);
        
        // 计算爽点密度（近5章）
        $coolPointDensity = $coolPointCount / 5;
        $targetDensity = (float)getSystemSetting('ws_cool_point_density_target', 0.88, 'float');
        
        if ($coolPointDensity < $targetDensity * 0.6) {
            // 爽点饥饿：建议安排爽点
            $result['cool_point_type'] = $this->selectCoolPointType($coolPointHistory, $currentChapter);
            $result['require_cool_point'] = true;
            $result['cool_point_urgency'] = 'critical';
            $result['warnings'][] = "💥 爽点饥饿：近5章爽点密度过低（{$coolPointDensity}），本章必须安排爽点";
            $result['tension_level'] = min(10, $result['tension_level'] + 1);
            
        } elseif ($coolPointDensity > $targetDensity * 1.5) {
            // 爽点过密：建议过渡章
            $result['warnings'][] = "📉 爽点过密：近5章爽点密度过高（{$coolPointDensity}），建议安排过渡章节";
            $result['tension_level'] = max(1, $result['tension_level'] - 1);
        }
        
        // ── 节奏检查：是否符合研究资料的标准 ─────────────────────
        $rhythmCheck = $this->checkRhythmStandard($currentChapter, $coolPointHistory);
        if (!$rhythmCheck['passed']) {
            $result['warnings'] = array_merge($result['warnings'], $rhythmCheck['issues']);
        }
        
        // ── 最终节奏参数 ─────────────────────────────────────
        if (empty($result['segment_ratios'])) {
            // 使用系统默认值
            $result['segment_ratios'] = [
                'setup'  => (int)getSystemSetting('ws_segment_ratio_setup', 20, 'int'),
                'rising' => (int)getSystemSetting('ws_segment_ratio_rising', 30, 'int'),
                'climax' => (int)getSystemSetting('ws_segment_ratio_climax', 35, 'int'),
                'hook'   => (int)getSystemSetting('ws_segment_ratio_hook', 15, 'int'),
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取当前阶段
     */
    private function getStage(float $progressPct): string
    {
        foreach (self::RHYTHM_STAGES as $stage => $config) {
            if ($progressPct >= $config['min'] && $progressPct < $config['max']) {
                return $stage;
            }
        }
        return 'ending';
    }
    
    /**
     * 获取张力等级描述
     */
    private function getTensionDescription(int $level): string
    {
        $descriptions = [
            1 => '极低（几乎无冲突）',
            2 => '低（轻微冲突）',
            3 => '中低（日常为主）',
            4 => '中等（铺垫期标准）',
            5 => '中（过渡阶段）',
            6 => '中高（发展期标准）',
            7 => '高（收束期标准）',
            8 => '很高（高潮期标准）',
            9 => '极高（结局期标准）',
            10 => '最高（最终高潮）',
        ];
        
        return $descriptions[$level] ?? '中等';
    }
    
    /**
     * 获取近期爽点
     */
    private function getRecentCoolPoints(array $history, int $currentChapter, int $lookback): array
    {
        return array_filter($history, fn($cp) => 
            ($cp['chapter'] ?? 0) >= $currentChapter - $lookback && 
            ($cp['chapter'] ?? 0) < $currentChapter
        );
    }
    
    /**
     * 选择爽点类型（根据进度阶段和历史使用情况）
     */
    private function selectCoolPointType(array $coolPointHistory, int $currentChapter): string
    {
        $recentTypes = array_column(array_slice($coolPointHistory, -5), 'type');

        $progressPct = $this->targetChapters > 0
            ? $currentChapter / $this->targetChapters
            : 0;

        $stageAppropriateTypes = match(true) {
            $progressPct >= 0.95 => ['truth_reveal', 'last_stand', 'romance_win', 'face_slap', 'underdog_win'],
            $progressPct >= 0.80 => ['power_expand', 'truth_reveal', 'breakthrough', 'face_slap', 'underdog_win'],
            $progressPct >= 0.50 => ['face_slap', 'treasure_find', 'breakthrough', 'underdog_win', 'power_expand'],
            default => ['underdog_win', 'treasure_find', 'face_slap', 'breakthrough', 'romance_win'],
        };

        foreach ($stageAppropriateTypes as $type) {
            if (!in_array($type, $recentTypes)) {
                return $type;
            }
        }

        $typeLastUsed = [];
        foreach (array_reverse($coolPointHistory) as $cp) {
            $type = $cp['type'] ?? '';
            $ch = $cp['chapter'] ?? 0;
            if ($type && $ch && !isset($typeLastUsed[$type])) {
                $typeLastUsed[$type] = $ch;
            }
        }

        if (!empty($typeLastUsed)) {
            asort($typeLastUsed);
            return array_key_first($typeLastUsed);
        }

        return $stageAppropriateTypes[0] ?? 'underdog_win';
    }
    
    /**
     * 检查节奏是否符合研究资料的标准
     * 小高潮每3-5章，中高潮每10-15章，大高潮每30-50章
     */
    private function checkRhythmStandard(int $currentChapter, array $coolPointHistory): array
    {
        $result = [
            'passed' => true,
            'issues' => [],
        ];
        
        // 检查小高潮（每3-5章）
        $smallClimaxGap = $this->getLastCoolPointGap($coolPointHistory, $currentChapter);
        if ($smallClimaxGap > 5) {
            $result['passed'] = false;
            $result['issues'][] = "⚠️ 已连续{$smallClimaxGap}章无爽点，超过研究资料建议的3-5章标准";
        }
        
        // 检查中高潮（每10-15章）
        $mediumClimaxGap = $this->getLastBigCoolPointGap($coolPointHistory, $currentChapter);
        if ($mediumClimaxGap > 15) {
            $result['passed'] = false;
            $result['issues'][] = "⚠️ 已连续{$mediumClimaxGap}章无大爽点，超过研究资料建议的10-15章标准";
        }
        
        return $result;
    }
    
    /**
     * 获取距离上一个爽点的间隔
     */
    private function getLastCoolPointGap(array $history, int $currentChapter): int
    {
        $lastChapter = 0;
        foreach (array_reverse($history) as $cp) {
            $ch = $cp['chapter'] ?? 0;
            if ($ch < $currentChapter && $ch > $lastChapter) {
                $lastChapter = $ch;
                break;
            }
        }
        
        return $currentChapter - $lastChapter;
    }
    
    /**
     * 获取距离上一个大爽点的间隔
     */
    private function getLastBigCoolPointGap(array $history, int $currentChapter): int
    {
        $bigTypes = ['underdog_win', 'face_slap', 'last_stand'];
        $lastChapter = 0;
        
        foreach (array_reverse($history) as $cp) {
            $ch = $cp['chapter'] ?? 0;
            $type = $cp['type'] ?? '';
            if ($ch < $currentChapter && in_array($type, $bigTypes)) {
                $lastChapter = $ch;
                break;
            }
        }
        
        return $currentChapter - $lastChapter;
    }
    
    /**
     * 生成节奏指令文本（用于注入 prompt）
     */
    public function generateRhythmInstructions(array $rhythm): string
    {
        $lines = [];
        
        $lines[] = "【🎼 章节节奏控制——基于1590本小说分析】";
        $lines[] = "";
        $lines[] = "当前阶段：{$rhythm['stage_name']}（进度{$rhythm['progress_percent']}%）";
        $lines[] = "阶段说明：{$rhythm['stage_description']}";
        $lines[] = "张力等级：{$rhythm['tension_level']}/10（{$rhythm['tension_description']}）";
        $lines[] = "";
        
        if (!empty($rhythm['segment_ratios'])) {
            $lines[] = "建议章节比例：";
            $lines[] = "· 铺垫段：{$rhythm['segment_ratios']['setup']}%";
            $lines[] = "· 发展段：{$rhythm['segment_ratios']['rising']}%";
            $lines[] = "· 高潮段：{$rhythm['segment_ratios']['climax']}%";
            $lines[] = "· 钩子段：{$rhythm['segment_ratios']['hook']}%";
            $lines[] = "";
        }
        
        $lines[] = "段落长度比例：长:中:短 = 2:5:3";
        $lines[] = "· 长段落（30-50字）：世界观描写、心理活动";
        $lines[] = "· 中段落（15-30字）：对话、动作描写";
        $lines[] = "· 短段落（5-15字）：战斗高潮、情绪爆发";
        $lines[] = "";
        
        if ($rhythm['require_cool_point']) {
            $urgency = $rhythm['cool_point_urgency'];
            $urgencyText = $urgency === 'critical' ? '🔴 强制' : ($urgency === 'high' ? '🟠 建议' : '🟢 可选');
            
            $lines[] = "⚠️ 本章{$urgencyText}安排爽点！";
            
            if ($rhythm['cool_point_type']) {
                $typeNames = [
                    'underdog_win' => '越级战斗胜利',
                    'face_slap' => '打脸反转',
                    'treasure_find' => '宝物/奇遇',
                    'breakthrough' => '修为突破',
                    'power_expand' => '势力扩张',
                    'romance_win' => '红颜倾心',
                    'truth_reveal' => '真相揭露',
                    'last_stand' => '背水一战',
                ];
                $typeName = $typeNames[$rhythm['cool_point_type']] ?? $rhythm['cool_point_type'];
                $lines[] = "建议爽点类型：{$typeName}";
            }
            $lines[] = "";
        }
        
        if (!empty($rhythm['instructions'])) {
            $lines[] = "节奏指令：";
            foreach ($rhythm['instructions'] as $inst) {
                $lines[] = "· {$inst}";
            }
            $lines[] = "";
        }
        
        if (!empty($rhythm['warnings'])) {
            $lines[] = "⚠️ 节奏警告：";
            foreach ($rhythm['warnings'] as $warning) {
                $lines[] = "· {$warning}";
            }
            $lines[] = "";
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * 获取所有阶段定义（用于前端展示）
     */
    public static function getAllStages(): array
    {
        return self::RHYTHM_STAGES;
    }
}
