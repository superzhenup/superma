<?php
defined('APP_LOADED') or die('Direct access denied.');

class ChapterTypeDetector
{
    private const TYPE_KEYWORDS = [
        'combat' => [
            'keywords' => ['战','打','斗','杀','攻击','对决','交手','搏杀','迎战','反杀','围攻','阵法','剑','刀','拳','爆','轰','碎','灭','斩','挡','闪','冲','破','毁'],
            'label'    => '战斗章',
            'ratios'   => ['setup' => 10, 'rising' => 20, 'climax' => 55, 'hook' => 15],
            'guidance' => '战斗章：短段落快节奏，动作描写为主（60%），对话穿插（20%），心理/描写（20%）。句式以短句为主（8-15字/句），战斗高潮可用3-5字极短句。',
        ],
        'strategy' => [
            'keywords' => ['计','谋','策','局','布局','阴谋','陷阱','谈判','联盟','势力','商议','议事','密谈','试探','暗棋','棋局'],
            'label'    => '谋略章',
            'ratios'   => ['setup' => 15, 'rising' => 40, 'climax' => 30, 'hook' => 15],
            'guidance' => '谋略章：长对话信息密集（50%），心理博弈（20%），场景描写（30%）。每轮对话必须有信息增量，用微表情和肢体语言传递暗流。',
        ],
        'cultivation' => [
            'keywords' => ['修炼','闭关','突破','晋级','炼丹','炼器','感悟','顿悟','冥想','灵气','经脉','丹田','功法','吸收','炼化','凝聚','淬炼'],
            'label'    => '修炼章',
            'ratios'   => ['setup' => 20, 'rising' => 30, 'climax' => 35, 'hook' => 15],
            'guidance' => '修炼章：描写细腻（40%），心理活动（30%），动作/过程（30%）。可用较长的感官描写（体内灵气流动、意识变化），节奏由缓到急。',
        ],
        'daily' => [
            'keywords' => ['日常','休整','休息','闲聊','逛街','吃','酒','茶','宴','聚','玩','闹','笑','玩笑','逗','嬉','温馨','陪伴','守护'],
            'label'    => '日常章',
            'ratios'   => ['setup' => 25, 'rising' => 35, 'climax' => 25, 'hook' => 15],
            'guidance' => '日常章：对话为主（60%），轻松幽默，动作（20%），心理（20%）。口语化，可用语气词和省略。日常中暗藏伏笔，用反差制造阅读趣味。',
        ],
        'revelation' => [
            'keywords' => ['真相','揭秘','发现','秘密','原来','竟是','没想到','居然','暴露','揭露','坦白','认罪','身世','遗言','传承','线索','答案'],
            'label'    => '揭秘章',
            'ratios'   => ['setup' => 15, 'rising' => 25, 'climax' => 45, 'hook' => 15],
            'guidance' => '揭秘章：叙事（40%）+ 角色反应（30%）+ 对话（30%）。信息逐层揭露，每揭一层配一次角色震惊反应。用短句+冲击性信息交替推进。',
        ],
        'travel' => [
            'keywords' => ['赶','出发','旅途','路上','前往','穿越','森林','山脉','荒野','城池','抵达','到达','进入','来到','飞','传送'],
            'label'    => '旅途章',
            'ratios'   => ['setup' => 30, 'rising' => 30, 'climax' => 25, 'hook' => 15],
            'guidance' => '旅途章：环境描写（35%）+ 对话（30%）+ 事件（35%）。允许较长的场景描写，在旅途中安排偶遇/小冲突/见闻，禁止纯赶路无事件。',
        ],
    ];

    public static function detect(string $outline): array
    {
        if (trim($outline) === '') {
            return [
                'type'     => 'normal',
                'label'    => '常规章',
                'ratios'   => null,
                'guidance' => '',
                'confidence' => 0,
            ];
        }

        $scores = [];
        foreach (self::TYPE_KEYWORDS as $type => $config) {
            $score = 0;
            foreach ($config['keywords'] as $kw) {
                $count = mb_substr_count($outline, $kw);
                $score += $count;
            }
            if ($score > 0) {
                $scores[$type] = $score;
            }
        }

        if (empty($scores)) {
            return [
                'type'     => 'normal',
                'label'    => '常规章',
                'ratios'   => null,
                'guidance' => '',
                'confidence' => 0,
            ];
        }

        arsort($scores);
        $topType  = array_key_first($scores);
        $topScore = $scores[$topType];
        $totalScore = array_sum($scores);
        $confidence = $totalScore > 0 ? round($topScore / $totalScore, 2) : 0;

        $config = self::TYPE_KEYWORDS[$topType];

        return [
            'type'       => $topType,
            'label'      => $config['label'],
            'ratios'     => $config['ratios'],
            'guidance'   => $config['guidance'],
            'confidence' => $confidence,
            'scores'     => $scores,
        ];
    }

    public static function adjustRatios(array $baseRatios, string $outline): array
    {
        $detected = self::detect($outline);
        if ($detected['type'] === 'normal' || $detected['ratios'] === null) {
            return $baseRatios;
        }

        if ($detected['confidence'] < 0.35) {
            return $baseRatios;
        }

        $typeRatios = $detected['ratios'];
        $blend = min(0.6, $detected['confidence']);

        return [
            'setup'  => (int)round($baseRatios['setup']  * (1 - $blend) + $typeRatios['setup']  * $blend),
            'rising' => (int)round($baseRatios['rising'] * (1 - $blend) + $typeRatios['rising'] * $blend),
            'climax' => (int)round($baseRatios['climax'] * (1 - $blend) + $typeRatios['climax'] * $blend),
            'hook'   => (int)round($baseRatios['hook']   * (1 - $blend) + $typeRatios['hook']   * $blend),
        ];
    }

    public static function buildGuidanceSection(string $outline): string
    {
        $detected = self::detect($outline);
        if ($detected['type'] === 'normal' || $detected['guidance'] === '') {
            return '';
        }
        if ($detected['confidence'] < 0.35) {
            return '';
        }
        return "【📝 章节类型：{$detected['label']}】\n{$detected['guidance']}\n\n";
    }
}
