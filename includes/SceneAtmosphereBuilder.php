<?php
defined('APP_LOADED') or die('Direct access denied.');

class SceneAtmosphereBuilder
{
    private string $genre;
    private string $outline;
    private string $pacing;
    private string $location;
    private int    $chNum;
    private int    $novelId;

    private const TONE_KEYWORDS = [
        'tense'   => ['追杀','围困','对峙','危机','阴谋','伏击','陷阱','暗算','生死','决战','血战','围攻','绝杀','拼命','大敌','强敌','杀机'],
        'tragic'  => ['牺牲','陨落','离别','死亡','覆灭','背叛','绝望','惨烈','代价','永别','痛失','殒命','凋零','诀别'],
        'triumph' => ['突破','逆袭','反杀','觉醒','翻盘','大获','大胜','碾压','顿悟','渡劫','成功','凯旋','击败','斩杀','灭杀'],
        'warm'    => ['重逢','守护','陪伴','温馨','日常','休整','归家','团圆','治愈','安慰','关怀','温馨','微笑','拥抱','团聚'],
        'eerie'   => ['诡异','阴暗','古墓','深渊','幽灵','诅咒','封印','禁地','血月','黑暗','迷雾','鬼火','阴森','诡异','未知'],
        'epic'    => ['天劫','浩劫','毁灭','降世','觉醒','远古','传承','禁忌','天命','万古','纪元','天地','混沌','洪荒'],
    ];

    private const GENRE_ATMOSPHERE = [
        '玄幻' => [
            'tense'   => ['灵气波动剧烈，空气中弥漫着肃杀之意', '天地元气躁动不安', '方圆数里的飞鸟走兽早已逃散', '远处的山峦在灵压之下微微颤抖'],
            'tragic'  => ['残阳如血，染红了半边天空', '风卷残云，枯叶漫天', '断壁残垣间只剩下呜咽的风声', '天地间仿佛都蒙上了一层灰败之色'],
            'triumph' => ['天地灵气倒卷而来，方圆百里的飞鸟惊起', '云层翻涌，一道金光直冲九霄', '大地震颤，山石崩裂', '万物似有感应，天地间异象纷呈'],
            'warm'    => ['山间清泉潺潺，鸟鸣声声', '炊烟袅袅升起，远处传来孩童嬉闹声', '月光如水，洒在院落中的老树上', '晨曦透过窗棂，映出细密的尘埃'],
            'eerie'   => ['阴风阵阵，火把的光芒被压得只剩豆大一点', '黑雾从地缝中渗出，带着一股腐朽的气息', '枯树如鬼爪般伸向天空，月光惨白', '脚下的石板路湿滑冰冷，远处传来若有若无的哭嚎'],
            'epic'    => ['九天之上雷霆翻滚，万里之内风云变色', '天地间一道巨大的裂缝缓缓撕开', '远古的气息穿越时空扑面而来', '日月无光，星辰都在颤抖'],
        ],
        '都市' => [
            'tense'   => ['霓虹灯在雨水中晕开，街上的行人匆匆', '远处的警笛声越来越近', '写字楼的玻璃幕墙映出阴沉的天空', '深夜的地下车库只有日光灯嗡嗡作响'],
            'tragic'  => ['窗外下起了小雨，玻璃上的水痕像泪', '空荡的房间里只剩时钟的滴答声', '路灯把影子拉得很长，像一道孤独的剪影', '手机屏幕亮了又灭，没有新消息'],
            'triumph' => ['落地窗外是整座城市的夜景，灯火璀璨', '阳光穿过百叶窗，在桌上投下一道道金色的线', '咖啡的香气弥漫在宽敞的办公室里'],
            'warm'    => ['厨房传来炒菜的声响，油烟味混着饭菜香', '阳台上晒着被子，阳光暖洋洋的', '小区里老人在下棋，孩子在追猫', '深夜便利店的灯光温暖而安静'],
            'eerie'   => ['走廊的灯忽明忽暗', '老式居民楼的楼梯间回荡着不知从哪来的脚步声', '窗户上映出的倒影似乎比本人慢了半拍'],
            'epic'    => ['整座城市的灯火在夜色中连成一片星海', '摩天大楼的玻璃幕墙映出漫天的火烧云'],
        ],
        '言情' => [
            'tense'   => ['空气仿佛凝固了，两人之间的距离不过一步却像隔了一道墙', '手机屏幕上的未接来电在黑暗中格外刺眼'],
            'tragic'  => ['雨不知道什么时候停了，但天空依旧灰蒙蒙的', '风吹散了桌上的花瓣，像一场无声的告别'],
            'triumph' => ['阳光刚好照在她脸上，她眯着眼睛笑的样子让他心跳漏了一拍'],
            'warm'    => ['午后的阳光透过纱帘，在地板上投下斑驳的光影', '空气中有他身上淡淡的洗衣液味道'],
            'eerie'   => ['月光从窗帘缝隙透进来，照在他空荡荡的半边床上'],
            'epic'    => ['漫天烟火在头顶绽放，她的眼睛比烟花还亮'],
        ],
        '历史' => [
            'tense'   => ['城墙上旌旗猎猎，远处的号角声低沉而悠长', '大殿之上鸦雀无声，只有烛火偶尔发出噼啪的声响', '斥候快马入城，马蹄声在青石板路上格外清脆'],
            'tragic'  => ['残阳照在断壁残垣之上，城中的烟火早已熄灭', '秋风萧瑟，枯草在废墟间摇曳', '战场上的血腥味被风吹散，却吹不散满目疮痍'],
            'triumph' => ['万军齐呼，声震九霄', '战鼓雷动，旌旗蔽日', '旭日东升，金色的阳光洒在铠甲上熠熠生辉'],
            'warm'    => ['街市上人声鼎沸，小贩的叫卖声此起彼伏', '酒肆中觥筹交错，丝竹之声不绝于耳'],
            'eerie'   => ['深宫的长廊幽深不见尽头，只有偶尔的更鼓声打破沉寂'],
            'epic'    => ['铁骑踏破关山，万里江山尽收眼底', '大军压境，旌旗遮天蔽日'],
        ],
        '科幻' => [
            'tense'   => ['警报灯将走廊染成一片血红色', '全息投影上的数据疯狂跳动', '飞船外壳传来令人不安的金属挤压声'],
            'tragic'  => ['舱外是无尽的星海，而他再也回不去了', '量子通讯器里只剩下沙沙的白噪声'],
            'triumph' => ['引擎轰鸣，星图上的航线终于连通', '全息屏幕上数据流如瀑布般倾泻而下'],
            'warm'    => ['人工重力区的模拟阳光温暖而柔和', '生态舱里的植物在LED光谱下郁郁葱葱'],
            'eerie'   => ['休眠舱的指示灯一盏接一盏地熄灭', '废弃空间站的走廊里回荡着不知名的低频嗡鸣'],
            'epic'    => ['星云在舷窗外翻涌，如同一只巨兽的呼吸', '戴森球的框架在恒星的光芒中缓缓合拢'],
        ],
    ];

    private const PACING_GUIDANCE = [
        'fast' => '快节奏场景，环境描写控制在句间点缀（每处≤15字），以动态感官为主（风声、震动、闪光），融入动作中而非独立段落',
        'slow' => '慢节奏场景，允许较长的环境铺陈（每处30-60字），注重静态感官（光影变化、气味、温度），让环境成为情绪的载体',
        'default' => '正常节奏，环境描写穿插在叙事中（每处15-30字），兼顾动态与静态感官',
    ];

    public function __construct(int $novelId, string $genre, string $outline, string $pacing, string $location, int $chNum)
    {
        $this->novelId = $novelId;
        $this->genre     = $genre;
        $this->outline   = $outline;
        $this->pacing    = $pacing;
        $this->location  = $location;
        $this->chNum     = $chNum;
    }

    public function build(): string
    {
        if (!getSystemSetting('ws_scene_atmosphere_enabled', true, 'bool')) return '';

        $tone = $this->detectTone();
        $atmosphereHints = $this->getAtmosphereHints($tone);
        $pacingGuide = $this->getPacingGuidance();
        $sensoryFocus = $this->getSensoryFocus($tone);

        $lines = [];
        $lines[] = "【🎨 场景意境指引】";

        if ($atmosphereHints !== '') {
            $lines[] = "氛围参考：{$atmosphereHints}";
        }

        if ($this->location !== '') {
            $lines[] = "当前场景：{$this->location}，将环境描写与角色情感呼应";
        }

        if ($pacingGuide !== '') {
            $lines[] = $pacingGuide;
        }

        if ($sensoryFocus !== '') {
            $lines[] = $sensoryFocus;
        }

        $lines[] = "⚠️ 意境融入叙事，禁止大段纯景物描写（单次≤60字），用五感细节代替笼统形容词";

        return implode("\n", $lines) . "\n\n";
    }

    private function detectTone(): string
    {
        if ($this->outline === '') return 'neutral';

        $scores = [];
        foreach (self::TONE_KEYWORDS as $tone => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                if (mb_strpos($this->outline, $kw) !== false) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$tone] = $score;
            }
        }

        if (empty($scores)) return 'neutral';

        arsort($scores);
        return array_key_first($scores);
    }

    private function getAtmosphereHints(string $tone): string
    {
        $genreMap = self::GENRE_ATMOSPHERE[$this->genre] ?? null;
        if (!$genreMap) {
            $genreMap = self::GENRE_ATMOSPHERE['玄幻'] ?? [];
        }

        $hints = $genreMap[$tone] ?? [];
        if (empty($hints)) return '';

        $recentUsed = $this->getRecentlyUsedAtmosphere();
        $available = array_diff($hints, $recentUsed);
        if (empty($available)) {
            $available = $hints;
        }

        $selected = $available[array_rand($available)];
        $this->recordAtmosphereUsage($selected);

        return $selected;
    }

    private function getPacingGuidance(): string
    {
        $key = match($this->pacing) {
            '快', 'fast' => 'fast',
            '慢', 'slow' => 'slow',
            default => 'default',
        };
        return self::PACING_GUIDANCE[$key] ?? '';
    }

    private function getSensoryFocus(string $tone): string
    {
        $focusMap = [
            'tense'   => '感官重点：听觉（脚步声、呼吸声、金属碰撞）+ 触觉（肌肉紧绷、冷汗、心跳加速）',
            'tragic'  => '感官重点：视觉（灰暗色调、空旷场景）+ 触觉（寒冷、空洞感）',
            'triumph' => '感官重点：视觉（光影变化、宏大场面）+ 听觉（欢呼、轰鸣、风声）',
            'warm'    => '感官重点：嗅觉（食物、花草、阳光的气息）+ 触觉（温暖、柔软、舒适）',
            'eerie'   => '感官重点：听觉（不明声响、寂静中的异响）+ 触觉（寒冷、潮湿、粘腻）',
            'epic'    => '感官重点：视觉（天地异象、宏大景象）+ 触觉（震动、压迫感、能量波动）',
        ];
        return $focusMap[$tone] ?? '';
    }

    private function getRecentlyUsedAtmosphere(): array
    {
        try {
            $recent = DB::fetchAll(
                "SELECT content FROM memory_atoms
                 WHERE novel_id=? AND atom_type='style_preference'
                   AND JSON_VALID(metadata)=1 AND JSON_EXTRACT(metadata,'$.is_atmosphere')=true
                 ORDER BY source_chapter DESC LIMIT 5",
                [$this->novelId]
            );
            return array_map(fn($r) => $r['content'] ?? '', $recent);
        } catch (\Throwable) {
            return [];
        }
    }

    private function recordAtmosphereUsage(string $hint): void
    {
        try {
            DB::insert('memory_atoms', [
                'novel_id'       => $this->novelId,
                'atom_type'      => 'style_preference',
                'content'        => $hint,
                'source_chapter' => $this->chNum,
                'confidence'     => 0.5,
                'metadata'       => json_encode(['tone' => $this->detectTone(), 'genre' => $this->genre, 'is_atmosphere' => true], JSON_UNESCAPED_UNICODE),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }
}
