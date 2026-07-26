<?php
/**
 * 情绪词汇库
 * 
 * 基于1590本小说分析的高频情绪词汇
 * 用于统计和控制章节中的情绪词汇密度
 * 
 * @author AI Writing System
 * @version 1.0
 * @date 2026-04-27
 */

class EmotionDictionary
{
    // ==================== 情绪词汇库 ====================
    
    /**
     * 愤怒类词汇
     * 目标密度：15-20次/万字
     */
    const ANGER_WORDS = [
        // 基础词汇
        '愤怒', '怒火', '暴怒', '怒气', '恼怒', '气恼', '怒视', '怒吼',
        '愤怒地', '恼火', '气愤', '怒不可遏', '怒发冲冠', '怒目而视',
        
        // 动作描写
        '咬牙切齿', '火冒三丈', '怒火中烧', '勃然大怒', '大发雷霆',
        '拍案而起', '怒目圆睁', '气得发抖', '气得浑身发抖',
        
        // 情绪状态
        '气炸了', '气坏了', '气疯了', '气得要命', '怒气冲冲',
        '满腔怒火', '怒气腾腾', '怒火万丈',
        
        // 口语化表达
        '气死我了', '气死', '气死人', '气得要死', '气不打一处来',
    ];
    
    /**
     * 喜悦类词汇
     * 目标密度：20-30次/万字
     */
    const JOY_WORDS = [
        // 基础词汇
        '喜悦', '高兴', '开心', '快乐', '欣喜', '狂喜', '兴奋', '激动',
        '欢喜', '愉悦', '欢快', '欢欣', '欣喜若狂',
        
        // 成语表达
        '喜出望外', '心花怒放', '欢天喜地', '眉开眼笑', '笑逐颜开',
        '喜上眉梢', '乐不可支', '喜形于色', '喜笑颜开', '欢欣鼓舞',
        
        // 动作描写
        '咧嘴笑', '哈哈大笑', '捧腹大笑', '开怀大笑', '笑得合不拢嘴',
        '笑得前仰后合', '笑得眼泪都出来了',
        
        // 心理状态
        '心情大好', '心情愉快', '心情舒畅', '心情美', '心里美滋滋',
        '心里乐开了花', '心里甜滋滋', '心里暖洋洋',
        
        // 口语化表达
        '太好了', '太棒了', '真好', '真高兴', '真开心', '乐坏了',
    ];
    
    /**
     * 惊讶类词汇
     * 目标密度：10-15次/万字
     */
    const SURPRISE_WORDS = [
        // 基础词汇
        '惊讶', '震惊', '惊愕', '诧异', '意外', '吃惊', '惊奇',
        
        // 成语表达
        '不可思议', '难以置信', '大吃一惊', '目瞪口呆', '瞠目结舌',
        '惊骇', '骇然', '惊诧', '愕然',
        
        // 心理反应
        '吓了一跳', '吓了一跳', '吓坏了', '吓傻了', '吓懵了',
        '惊呆了', '惊住了', '愣住了', '傻眼了',
        
        // 口语化表达
        '怎么可能', '不可能', '居然', '竟然', '想不到', '没想到',
        '不会吧', '真的假的', '天哪', '我的天', '天啊',
        
        // 动作描写
        '瞪大眼睛', '睁大眼睛', '倒吸一口凉气', '倒吸凉气',
    ];
    
    /**
     * 恐惧类词汇
     * 目标密度：5-10次/万字
     */
    const FEAR_WORDS = [
        // 基础词汇
        '恐惧', '害怕', '惊恐', '惶恐', '畏惧', '胆寒', '战栗', '颤抖',
        
        // 成语表达
        '不寒而栗', '毛骨悚然', '心惊胆战', '魂飞魄散', '胆战心惊',
        '惊弓之鸟', '风声鹤唳', '草木皆兵',
        
        // 心理状态
        '吓坏了', '吓死了', '吓破胆', '吓尿了', '吓得要命',
        '心里发毛', '心里发怵', '心里发虚', '心里没底',
        
        // 身体反应
        '浑身发抖', '瑟瑟发抖', '浑身战栗', '双腿发软', '手脚冰凉',
        '冷汗直流', '冷汗淋漓', '汗毛倒竖', '汗毛竖起',
        
        // 口语化表达
        '怕死了', '怕得要命', '吓得半死', '吓得魂飞魄散',
    ];
    
    /**
     * 悲伤类词汇
     * 目标密度：5-10次/万字
     */
    const SADNESS_WORDS = [
        // 基础词汇
        '悲伤', '悲痛', '哀伤', '忧伤', '凄凉', '悲凉', '心酸', '心碎',
        '难过', '伤心', '痛苦', '悲恸',
        
        // 成语表达
        '黯然神伤', '痛不欲生', '悲从中来', '潸然泪下', '泪流满面',
        '悲痛欲绝', '肝肠寸断', '痛彻心扉', '撕心裂肺',
        
        // 动作描写
        '流下眼泪', '泪水涌出', '泪如雨下', '泪如泉涌', '泪眼朦胧',
        '泣不成声', '失声痛哭', '嚎啕大哭', '痛哭流涕',
        
        // 心理状态
        '心如刀绞', '心如刀割', '心如死灰', '心灰意冷', '万念俱灰',
        '绝望', '无助', '凄惨', '悲惨',
        
        // 口语化表达
        '太惨了', '太可怜了', '好可怜', '好惨', '真惨', '惨不忍睹',
    ];
    
    /**
     * 爽点相关词汇（用于检测爽点）
     */
    const COOL_POINT_WORDS = [
        // 打脸类
        '打脸', '脸被打肿', '狠狠打脸', '当场打脸', '啪啪打脸',
        
        // 逆袭类
        '逆袭', '翻盘', '反转', '逆转', '反败为胜', '绝地反击',
        
        // 装逼类
        '装逼', '低调装逼', '扮猪吃虎', '深藏不露', '一鸣惊人',
        
        // 震惊类
        '震惊全场', '震撼全场', '所有人震惊', '全场哗然', '一片哗然',
        
        // 碾压类
        '碾压', '秒杀', '吊打', '完虐', '完爆', '实力碾压',
    ];
    
    // ==================== 核心方法 ====================
    
    /**
     * 统计情绪词汇密度
     * 
     * @param string $content 章节内容
     * @return array 情绪词汇统计结果
     */
    public static function countEmotionDensity(string $content): array
    {
        $result = [
            'anger'    => 0,
            'joy'      => 0,
            'surprise' => 0,
            'fear'     => 0,
            'sadness'  => 0,
            'cool_point' => 0,
        ];
        
        // 统计各类情绪词汇出现次数
        $result['anger']    = self::countWords($content, self::ANGER_WORDS);
        $result['joy']      = self::countWords($content, self::JOY_WORDS);
        $result['surprise'] = self::countWords($content, self::SURPRISE_WORDS);
        $result['fear']     = self::countWords($content, self::FEAR_WORDS);
        $result['sadness']  = self::countWords($content, self::SADNESS_WORDS);
        $result['cool_point'] = self::countWords($content, self::COOL_POINT_WORDS);
        
        // 计算每万字的密度
        $wordCount = self::countChineseCharacters($content);
        $wanzi = $wordCount / 10000;
        
        if ($wanzi > 0) {
            $result['anger_density']    = round($result['anger'] / $wanzi, 2);
            $result['joy_density']      = round($result['joy'] / $wanzi, 2);
            $result['surprise_density'] = round($result['surprise'] / $wanzi, 2);
            $result['fear_density']     = round($result['fear'] / $wanzi, 2);
            $result['sadness_density']  = round($result['sadness'] / $wanzi, 2);
            $result['cool_point_density'] = round($result['cool_point'] / $wanzi, 2);
        } else {
            $result['anger_density']    = 0;
            $result['joy_density']      = 0;
            $result['surprise_density'] = 0;
            $result['fear_density']     = 0;
            $result['sadness_density']  = 0;
            $result['cool_point_density'] = 0;
        }
        
        $result['total_words'] = $wordCount;
        $result['total_emotion_words'] = $result['anger'] + $result['joy'] + 
                                         $result['surprise'] + $result['fear'] + $result['sadness'];
        
        return $result;
    }
    
    /**
     * 统计指定词汇列表在文本中出现的总次数
     * 
     * @param string $content 文本内容
     * @param array $words 词汇列表
     * @return int 出现次数
     */
    private static function countWords(string $content, array $words): int
    {
        $count = 0;
        foreach ($words as $word) {
            $count += mb_substr_count($content, $word);
        }
        return $count;
    }
    
    /**
     * 统计中文字符数（排除空格、标点等）
     * 
     * @param string $content 文本内容
     * @return int 中文字符数
     */
    public static function countChineseCharacters(string $content): int
    {
        // 移除空格、换行、制表符
        $content = preg_replace('/\s+/u', '', $content);
        
        // 统计中文字符数
        preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $content, $matches);
        
        return count($matches[0] ?? []);
    }
    
    /**
     * 获取情绪词汇详情（用于调试和展示）
     * 
     * @param string $content 章节内容
     * @return array 包含具体词汇出现位置的详细信息
     */
    public static function getEmotionDetails(string $content): array
    {
        $details = [];
        
        // 愤怒类词汇详情
        $angerDetails = self::findWordPositions($content, self::ANGER_WORDS);
        if (!empty($angerDetails)) {
            $details['anger'] = [
                'count'   => count($angerDetails),
                'words'   => $angerDetails,
            ];
        }
        
        // 喜悦类词汇详情
        $joyDetails = self::findWordPositions($content, self::JOY_WORDS);
        if (!empty($joyDetails)) {
            $details['joy'] = [
                'count'   => count($joyDetails),
                'words'   => $joyDetails,
            ];
        }
        
        // 惊讶类词汇详情
        $surpriseDetails = self::findWordPositions($content, self::SURPRISE_WORDS);
        if (!empty($surpriseDetails)) {
            $details['surprise'] = [
                'count'   => count($surpriseDetails),
                'words'   => $surpriseDetails,
            ];
        }
        
        // 恐惧类词汇详情
        $fearDetails = self::findWordPositions($content, self::FEAR_WORDS);
        if (!empty($fearDetails)) {
            $details['fear'] = [
                'count'   => count($fearDetails),
                'words'   => $fearDetails,
            ];
        }
        
        // 悲伤类词汇详情
        $sadnessDetails = self::findWordPositions($content, self::SADNESS_WORDS);
        if (!empty($sadnessDetails)) {
            $details['sadness'] = [
                'count'   => count($sadnessDetails),
                'words'   => $sadnessDetails,
            ];
        }
        
        return $details;
    }
    
    /**
     * 查找词汇在文本中的位置
     * 
     * @param string $content 文本内容
     * @param array $words 词汇列表
     * @return array [词汇 => [位置1, 位置2, ...], ...]
     */
    private static function findWordPositions(string $content, array $words): array
    {
        $positions = [];
        
        foreach ($words as $word) {
            $offset = 0;
            $wordPositions = [];
            
            while (($pos = mb_strpos($content, $word, $offset)) !== false) {
                $wordPositions[] = $pos;
                $offset = $pos + mb_strlen($word);
            }
            
            if (!empty($wordPositions)) {
                $positions[$word] = $wordPositions;
            }
        }
        
        return $positions;
    }
    
    /**
     * 评估情绪密度是否符合标准
     * 
     * @param array $density 情绪密度统计结果
     * @param array $targets 目标密度 ['anger' => 18, 'joy' => 25, ...]
     * @return array 评估结果
     */
    public static function evaluateDensity(array $density, array $targets = []): array
    {
        // 默认目标密度（基于研究资料）
        $defaultTargets = [
            'anger'    => 18,
            'joy'      => 25,
            'surprise' => 12,
            'fear'     => 7,
            'sadness'  => 7,
        ];
        
        $targets = array_merge($defaultTargets, $targets);
        
        $evaluation = [
            'overall_score' => 100,
            'issues'        => [],
            'suggestions'   => [],
        ];
        
        // 评估愤怒类词汇
        $angerDensity = $density['anger_density'] ?? 0;
        $angerTarget = $targets['anger'];
        if ($angerDensity < $angerTarget * 0.6) {
            $evaluation['issues'][] = "愤怒类词汇密度不足（实际{$angerDensity}，目标{$angerTarget}）";
            $evaluation['suggestions'][] = "建议增加愤怒、怒火、恼怒等词汇的使用";
            $evaluation['overall_score'] -= 15;
        } elseif ($angerDensity > $angerTarget * 1.5) {
            $evaluation['issues'][] = "愤怒类词汇密度过高（实际{$angerDensity}，目标{$angerTarget}）";
            $evaluation['suggestions'][] = "建议减少愤怒类词汇，避免情绪过于压抑";
            $evaluation['overall_score'] -= 10;
        }
        
        // 评估喜悦类词汇
        $joyDensity = $density['joy_density'] ?? 0;
        $joyTarget = $targets['joy'];
        if ($joyDensity < $joyTarget * 0.6) {
            $evaluation['issues'][] = "喜悦类词汇密度不足（实际{$joyDensity}，目标{$joyTarget}）";
            $evaluation['suggestions'][] = "建议增加喜悦、高兴、兴奋等词汇的使用";
            $evaluation['overall_score'] -= 15;
        } elseif ($joyDensity > $joyTarget * 1.5) {
            $evaluation['issues'][] = "喜悦类词汇密度过高（实际{$joyDensity}，目标{$joyTarget}）";
            $evaluation['overall_score'] -= 10;
        }
        
        // 评估惊讶类词汇
        $surpriseDensity = $density['surprise_density'] ?? 0;
        $surpriseTarget = $targets['surprise'];
        if ($surpriseDensity < $surpriseTarget * 0.6) {
            $evaluation['issues'][] = "惊讶类词汇密度不足（实际{$surpriseDensity}，目标{$surpriseTarget}）";
            $evaluation['suggestions'][] = "建议增加惊讶、震惊、意外等词汇的使用";
            $evaluation['overall_score'] -= 10;
        }
        
        // 评估恐惧类词汇
        $fearDensity = $density['fear_density'] ?? 0;
        $fearTarget = $targets['fear'];
        if ($fearDensity > $fearTarget * 2) {
            $evaluation['issues'][] = "恐惧类词汇密度过高（实际{$fearDensity}，目标{$fearTarget}）";
            $evaluation['suggestions'][] = "建议减少恐惧类词汇，避免情绪过于紧张";
            $evaluation['overall_score'] -= 10;
        }
        
        // 评估悲伤类词汇
        $sadnessDensity = $density['sadness_density'] ?? 0;
        $sadnessTarget = $targets['sadness'];
        if ($sadnessDensity > $sadnessTarget * 2) {
            $evaluation['issues'][] = "悲伤类词汇密度过高（实际{$sadnessDensity}，目标{$sadnessTarget}）";
            $evaluation['suggestions'][] = "建议减少悲伤类词汇，避免情绪过于压抑";
            $evaluation['overall_score'] -= 10;
        }
        
        $evaluation['overall_score'] = max(0, min(100, $evaluation['overall_score']));
        
        return $evaluation;
    }
    
    /**
     * 生成情绪密度报告（用于质量检测）
     * 
     * @param string $content 章节内容
     * @param array $targets 目标密度
     * @return array 完整报告
     */
    public static function generateReport(string $content, array $targets = []): array
    {
        $density = self::countEmotionDensity($content);
        $evaluation = self::evaluateDensity($density, $targets);
        
        return [
            'density'    => $density,
            'evaluation' => $evaluation,
            'status'     => $evaluation['overall_score'] >= 60 ? 'pass' : 'fail',
            'score'      => $evaluation['overall_score'],
        ];
    }
    
    /**
     * 获取所有情绪词汇列表（用于前端展示）
     * 
     * @return array 分类词汇列表
     */
    public static function getAllWords(): array
    {
        return [
            'anger'    => self::ANGER_WORDS,
            'joy'      => self::JOY_WORDS,
            'surprise' => self::SURPRISE_WORDS,
            'fear'     => self::FEAR_WORDS,
            'sadness'  => self::SADNESS_WORDS,
            'cool_point' => self::COOL_POINT_WORDS,
        ];
    }
    
    /**
     * 获取情绪词汇统计信息
     * 
     * @return array 各类情绪词汇的数量
     */
    public static function getWordCount(): array
    {
        return [
            'anger'    => count(self::ANGER_WORDS),
            'joy'      => count(self::JOY_WORDS),
            'surprise' => count(self::SURPRISE_WORDS),
            'fear'     => count(self::FEAR_WORDS),
            'sadness'  => count(self::SADNESS_WORDS),
            'cool_point' => count(self::COOL_POINT_WORDS),
            'total'    => count(self::ANGER_WORDS) + count(self::JOY_WORDS) + 
                          count(self::SURPRISE_WORDS) + count(self::FEAR_WORDS) + 
                          count(self::SADNESS_WORDS) + count(self::COOL_POINT_WORDS),
        ];
    }
}

// ==================== 辅助函数 ====================

/**
 * 快速统计情绪密度（全局函数）
 * 
 * @param string $content 章节内容
 * @return array 情绪密度统计
 */
function count_emotion_density(string $content): array
{
    return EmotionDictionary::countEmotionDensity($content);
}

/**
 * 快速生成情绪报告（全局函数）
 * 
 * @param string $content 章节内容
 * @param array $targets 目标密度
 * @return array 情绪报告
 */
function generate_emotion_report(string $content, array $targets = []): array
{
    return EmotionDictionary::generateReport($content, $targets);
}
