<?php
/**
 * 网文创作专家知识库
 *
 * 基于网文创作理论和实践经验，为Agent决策提供专业知识支持
 *
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

class WritingExpertise
{
    /**
     * 专家知识库：问题类型 → 诊断 → 解决方案
     */
    private const KNOWLEDGE_BASE = [
        // ==================== 质量问题 ====================
        'quality_low' => [
            'subtypes' => [
                'dialogue_weak' => [
                    'diagnosis' => '对话缺乏张力',
                    'symptoms' => ['对话回合过长', '信息单向输出', '缺乏立场对立'],
                    'solutions' => [
                        '每轮对话控制在3句以内，必须有回应或反应',
                        '对话双方必须有不同目标/信息/态度',
                        '插入动作描写：表情、手势、眼神变化',
                        '用"说"以外的动词：反驳、追问、冷笑、打断',
                    ],
                    'example_fix' => '原：他说："我觉得应该这样做。" → 改：他敲了敲桌子："没有别的选择了吗？"',
                ],
                'pacing_slow' => [
                    'diagnosis' => '节奏拖沓',
                    'symptoms' => ['铺垫段过长', '无事件段落超过300字', '过渡句过多'],
                    'solutions' => [
                        '开篇200字内必须有问题/悬念/冲突',
                        '检查铺垫段：超过300字无事件必须打断',
                        '删除过渡句：如"过了好一会""时间一分一秒过去"',
                        '用动作代替时间流逝的描写',
                    ],
                    'example_fix' => '原：过了好一会，他才开口说话。 → 改：他猛地抬头。',
                ],
                'description_flat' => [
                    'diagnosis' => '描写平淡',
                    'symptoms' => ['缺乏感官细节', '全是视觉描写', '形容词堆砌'],
                    'solutions' => [
                        '调用五感：不要只写"看到"，要写"听到""闻到""感觉到"',
                        '用具体动作代替抽象形容词',
                        '场景描写要有情绪色彩，不只是罗列物品',
                    ],
                    'example_fix' => '原：房间很乱。 → 改：桌上堆满了外卖盒，空气中弥漫着变质的饭菜味。',
                ],
                'emotion_hollow' => [
                    'diagnosis' => '情感空洞',
                    'symptoms' => ['人物反应平淡', '缺乏内心独白', '重大事件无情绪波澜'],
                    'solutions' => [
                        '重大事件后必须有情绪反应',
                        '用生理反应代替直接说情绪：手抖、心跳加速、喉咙发紧',
                        '内心独白要和对话穿插',
                    ],
                    'example_fix' => '原：他很生气。 → 改：他攥紧了拳头，指节发白。',
                ],
            ],
        ],

        // ==================== 爽点问题 ====================
        'coolpoint_low' => [
            'subtypes' => [
                'no_climax' => [
                    'diagnosis' => '缺乏高潮爽点',
                    'solutions' => [
                        '每章至少安排一个"读者看到这里会叫好"的时刻',
                        '高潮必须解决一个具体问题或带来重大突破',
                        '用"预期-反转"结构制造惊喜',
                    ],
                ],
                'coolpoint_rushed' => [
                    'diagnosis' => '爽点铺垫不足',
                    'solutions' => [
                        '爽点要有"压抑-爆发"过程，不能太突然',
                        '先让读者感受到压抑/不公/困境',
                        '爆发时要有足够的笔墨渲染',
                    ],
                ],
                'coolpoint_repetitive' => [
                    'diagnosis' => '爽点类型重复',
                    'solutions' => [
                        '检查近5章是否使用了相同的爽点类型',
                        '轮换使用：打脸、逆袭、获宝、扮猪吃虎、智斗',
                        '每种爽点间隔至少3章再用',
                    ],
                ],
            ],
        ],

        // ==================== 节奏问题 ====================
        'pacing_issue' => [
            'subtypes' => [
                'opening_weak' => [
                    'diagnosis' => '开篇吸引力不足',
                    'solutions' => [
                        '开篇第一句必须有钩子：悬念/冲突/意外',
                        '避免从"天气很好"或"人物介绍"开始',
                        '用"倒叙"或"中途切入"增加张力',
                    ],
                    'opening_types' => [
                        '悬念式' => '用未解之谜开场',
                        '冲突式' => '直接进入矛盾冲突',
                        '反转式' => '先给出意外信息再解释',
                        '悬念式' => '抛出读者想知道答案的问题',
                    ],
                ],
                'middle_sagging' => [
                    'diagnosis' => '中段疲软',
                    'solutions' => [
                        '每500字必须有一个小转折或新信息',
                        '用子目标分解主线，让读者有阶段性成就感',
                        '插入意外事件打断平淡',
                    ],
                ],
                'ending_rushed' => [
                    'diagnosis' => '结尾仓促',
                    'solutions' => [
                        '高潮后留出200-300字收尾空间',
                        '结尾必须有钩子：悬念/预告/情绪余韵',
                        '避免用"就这样"式的硬着陆结尾',
                    ],
                ],
            ],
        ],

        // ==================== 情绪问题 ====================
        'emotion_low' => [
            'subtypes' => [
                'emotion_monotone' => [
                    'diagnosis' => '情绪单调',
                    'solutions' => [
                        '情绪曲线要有起伏：不能一直是兴奋或一直是平静',
                        '用"对比"制造情绪张力：期待vs失望、希望vs绝望',
                        '每个场景确定一个主情绪，其他情绪为辅',
                    ],
                ],
                'emotion_inauthentic' => [
                    'diagnosis' => '情绪不真实',
                    'solutions' => [
                        '情绪反应要符合人物性格',
                        '用具体细节代替笼统的情绪词',
                        '避免"他非常愤怒"这种直接告知，用行动展示',
                    ],
                ],
            ],
        ],

        // ==================== 伏笔问题 ====================
        'foreshadowing_issue' => [
            'subtypes' => [
                'foreshadowing_forgotten' => [
                    'diagnosis' => '伏笔遗忘',
                    'solutions' => [
                        '在伏笔表中标注提醒章节',
                        '每隔5章检查一次待回收伏笔',
                        '重要伏笔在回收前2-3章再次提及',
                    ],
                ],
                'foreshadowing_rushed' => [
                    'diagnosis' => '伏笔回收仓促',
                    'solutions' => [
                        '回收伏笔时要有铺垫，不能太突然',
                        '回收方式要有惊喜感，最好超出读者预期',
                        '一个伏笔回收至少用200字来展开',
                    ],
                ],
            ],
        ],
    ];

    /**
     * 题材特定建议
     */
    private const GENGE_ADVICE = [
        '玄幻' => [
            'focus' => ['爽点密度', '等级体系', '功法设定'],
            'pacing' => '快节奏，每章都有进展',
            'coolpoint_types' => ['逆袭', '获宝', '扮猪吃虎', '打脸'],
            'common_pitfalls' => ['等级混乱', '功法重复', '反派智商掉线'],
        ],
        '都市' => [
            'focus' => ['生活细节', '人物关系', '情感变化'],
            'pacing' => '中等节奏，张弛有度',
            'coolpoint_types' => ['打脸', '逆袭', '智斗', '事业突破'],
            'common_pitfalls' => ['脱离现实', '人物脸谱化', '感情线混乱'],
        ],
        '言情' => [
            'focus' => ['情感描写', '人物互动', '心理活动'],
            'pacing' => '慢节奏，注重细节',
            'coolpoint_types' => ['心动时刻', '误会解除', '告白', '保护'],
            'common_pitfalls' => ['过度纠结', '角色降智', '狗血情节'],
        ],
        '科幻' => [
            'focus' => ['设定严谨', '逻辑自洽', '细节真实'],
            'pacing' => '中等节奏，层层递进',
            'coolpoint_types' => ['科技突破', '发现真相', '逆转局势'],
            'common_pitfalls' => ['设定冲突', '硬伤', '人物工具化'],
        ],
        '历史' => [
            'focus' => ['历史细节', '权谋策略', '人物群像'],
            'pacing' => '慢节奏，厚重感',
            'coolpoint_types' => ['智斗', '翻盘', '成就大事'],
            'common_pitfalls' => ['历史错误', '人物扁平', '权谋幼稚'],
        ],
    ];

    /**
     * 获取专家建议
     *
     * @param string $issueType 问题类型
     * @param array $context 上下文信息
     * @return array 建议结果
     */
    public static function getAdvice(string $issueType, array $context = []): array
    {
        $knowledge = self::KNOWLEDGE_BASE[$issueType] ?? null;

        if (!$knowledge) {
            return [
                'found' => false,
                'message' => "未找到问题类型「{$issueType}」的专家知识",
            ];
        }

        // 提取所有子类型的建议
        $subtypes = $knowledge['subtypes'] ?? [];
        $allSolutions = [];
        $allDiagnoses = [];

        foreach ($subtypes as $subtypeName => $subtype) {
            $allDiagnoses[] = [
                'type' => $subtypeName,
                'diagnosis' => $subtype['diagnosis'] ?? '',
                'symptoms' => $subtype['symptoms'] ?? [],
            ];
            $allSolutions[$subtypeName] = $subtype['solutions'] ?? [];
        }

        // 如果有上下文，尝试匹配具体子类型
        $matchedSubtype = null;
        if (!empty($context['symptoms'])) {
            $matchedSubtype = self::matchSubtype($context['symptoms'], $subtypes);
        }

        // 如果有题材信息，添加题材特定建议
        $genreAdvice = null;
        if (!empty($context['genre'])) {
            $genreAdvice = self::GENGE_ADVICE[$context['genre']] ?? null;
        }

        return [
            'found' => true,
            'issue_type' => $issueType,
            'diagnoses' => $allDiagnoses,
            'solutions' => $allSolutions,
            'matched_subtype' => $matchedSubtype,
            'matched_solutions' => $matchedSubtype ? ($allSolutions[$matchedSubtype] ?? []) : [],
            'genre_advice' => $genreAdvice,
        ];
    }

    /**
     * 根据症状匹配子类型
     */
    private static function matchSubtype(array $symptoms, array $subtypes): ?string
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($subtypes as $name => $subtype) {
            $subtypeSymptoms = $subtype['symptoms'] ?? [];
            $score = count(array_intersect($symptoms, $subtypeSymptoms));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $name;
            }
        }

        return $bestMatch;
    }

    /**
     * 生成针对具体问题的改进指令
     */
    public static function generateDirective(string $issueType, string $subtype, array $context = []): string
    {
        $knowledge = self::KNOWLEDGE_BASE[$issueType]['subtypes'][$subtype] ?? null;

        if (!$knowledge) {
            return '';
        }

        $diagnosis = $knowledge['diagnosis'] ?? '';
        $solutions = $knowledge['solutions'] ?? [];

        // 选取前3条最相关的建议
        $selectedSolutions = array_slice($solutions, 0, 3);

        $directive = "【问题诊断】{$diagnosis}。\n";
        $directive .= "【改进建议】\n";
        foreach ($selectedSolutions as $i => $solution) {
            $directive .= ($i + 1) . ". {$solution}\n";
        }

        // 如果有示例修复
        if (!empty($knowledge['example_fix'])) {
            $directive .= "【示例】{$knowledge['example_fix']}";
        }

        return $directive;
    }

    /**
     * 获取题材特定建议
     */
    public static function getGenreAdvice(string $genre): ?array
    {
        return self::GENGE_ADVICE[$genre] ?? null;
    }

    /**
     * 获取开篇类型建议
     */
    public static function getOpeningAdvice(string $type): string
    {
        $openings = self::KNOWLEDGE_BASE['pacing_issue']['subtypes']['opening_weak']['opening_types'] ?? [];
        return $openings[$type] ?? '';
    }

    /**
     * 检查是否为常见陷阱
     */
    public static function checkPitfall(string $genre, string $issue): bool
    {
        $pitfalls = self::GENGE_ADVICE[$genre]['common_pitfalls'] ?? [];
        return in_array($issue, $pitfalls);
    }
}
