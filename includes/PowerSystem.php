<?php
/**
 * PowerSystem — 等级框架服务
 * 
 * 功能：
 *   1. 从 novel_worldbuilding 中动态读取境界链（替代硬编码）
 *   2. 计算角色境界在体系中的位置
 *   3. 生成反派实力对标规则
 *   4. 为章节生成提示词注入等级约束
 */
defined('APP_LOADED') or die('Direct access denied.');

class PowerSystem
{
    private int $novelId;

    /** @var array|null 缓存的境界链 */
    private ?array $realmChain = null;

    /**
     * 分组默认境界链（按体系分类，消除歧义）
     *
     * 优化说明：原来将5套体系的40+个境界名混在一个扁平数组中，
     * 导致 getRealmIndex/getRealmOffset 跨体系计算时产生错误结果。
     * 现在按体系独立存储，每次只返回单一体系链。
     */
    private const REALM_SYSTEMS = [
        'xiuxian' => [
            'name'     => '修仙体系',
            'realms'   => ['炼气', '筑基', '金丹', '元婴', '化神', '炼虚', '合体', '大乘', '渡劫'],
            'keywords' => ['炼气', '筑基', '金丹', '元婴', '化神', '渡劫', '修炼', '灵力'],
        ],
        'martial' => [
            'name'     => '武道体系',
            'realms'   => ['凡人', '武者', '武师', '武王', '武皇', '武宗', '武尊', '武圣', '武帝'],
            'keywords' => ['武者', '武师', '武王', '武皇', '武宗', '武帝', '内力', '真气'],
        ],
        'rank' => [
            'name'     => '阶级体系',
            'realms'   => ['见习', '初级', '中级', '高级', '特区', 'S级', 'SS级', 'SSS级'],
            'keywords' => ['见习', 'S级', 'SS级', 'SSS级', '特区'],
        ],
        'tier' => [
            'name'     => '阶位体系',
            'realms'   => ['一阶', '二阶', '三阶', '四阶', '五阶', '六阶', '七阶', '八阶', '九阶'],
            'keywords' => ['一阶', '二阶', '九阶', '阶位'],
        ],
        'doupo' => [
            'name'     => '斗破体系',
            'realms'   => ['斗者', '斗师', '大斗师', '斗灵', '斗王', '斗皇', '斗宗', '斗尊', '斗圣', '斗帝'],
            'keywords' => ['斗者', '斗师', '斗气', '斗灵', '斗王'],
        ],
    ];

    /** 默认使用的体系（检测失败时的兜底选择） */
    private const DEFAULT_SYSTEM_KEY = 'xiuxian';

    /**
     * @deprecated 兼容常量，不再使用。保留以防止外部引用报错
     * 实际逻辑已迁移到 REALM_SYSTEMS 分组 + detectPowerSystem() 智能选择
     */
    private const DEFAULT_REALM_ORDER = [];

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    // ================================================================
    // 0. 体系检测（消除歧义核心）
    // ================================================================

    /**
     * 根据主角当前境界（或 hint 字符串）自动检测所属等级体系
     *
     * 检测策略（两级匹配）：
     *   Level 1: 精确/模糊匹配 — 境界名直接命中某体系的 realms 列表
     *   Level 2: 关键词辅助匹配 — 命中该体系的特征关键词（如"修炼"→修仙）
     *
     * @param string $hint 待检测的境界名或相关文本
     * @return string|null 体系 key (xiuxian/martial/rank/tier/doupo)，未检测到返回 null
     */
    public function detectPowerSystem(string $hint): ?string
    {
        if (trim($hint) === '') return null;

        // Level 1: 遍历所有体系的境界列表，做子串匹配
        foreach (self::REALM_SYSTEMS as $key => $system) {
            foreach ($system['realms'] as $label) {
                // 双向子串匹配："金丹初期" 含 "金丹"，或 "金丹" 含 "金丹"
                if (mb_strpos($hint, $label) !== false || mb_strpos($label, $hint) !== false) {
                    return $key;
                }
            }
        }

        // Level 2: 用特征关键词辅助检测（覆盖更宽泛的表述）
        foreach (self::REALM_SYSTEMS as $key => $system) {
            foreach ($system['keywords'] as $kw) {
                if (mb_strpos($hint, $kw) !== false) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * 尝试从数据库获取主角当前境界作为检测 hint
     * 用于 getDefaultRealmChain() 在无 worldbuilding 配置时智能选链
     */
    private function fetchProtagonistRealmHint(): string
    {
        try {
            $card = DB::fetch(
                "SELECT cc.attributes 
                 FROM character_cards cc 
                 JOIN novel_characters nc ON nc.name = cc.name AND nc.novel_id = cc.novel_id
                 WHERE cc.novel_id=? AND nc.role_type='protagonist' 
                 LIMIT 1",
                [$this->novelId]
            );
            if ($card && !empty($card['attributes'])) {
                $attrs = is_string($card['attributes'])
                    ? json_decode($card['attributes'], true)
                    : $card['attributes'];
                if (is_array($attrs) && !empty($attrs['realm'])) {
                    return (string)$attrs['realm'];
                }
            }
        } catch (\Throwable $e) {
            // 静默失败，返回空串触发兜底
        }
        return '';
    }

    // ================================================================
    // 1. 获取境界链
    // ================================================================

    /**
     * 获取当前小说的境界链
     * 优先从 novel_worldbuilding(category='rule', name 含"境界/等级/修炼") 读取
     * 找不到则回退到硬编码默认值
     * 
     * @return array ['realms' => ['炼气','筑基',...], 'source' => 'worldbuilding'|'default', 'power_system' => '修仙境界']
     */
    public function getRealmChain(): array
    {
        if ($this->realmChain !== null) {
            return $this->realmChain;
        }

        // 尝试从 worldbuilding 读取
        try {
            $rules = DB::fetchAll(
                "SELECT name, description, attributes FROM novel_worldbuilding 
                 WHERE novel_id=? AND category='rule' 
                 AND (name LIKE '%境界%' OR name LIKE '%等级%' OR name LIKE '%修炼%' OR name LIKE '%实力%' OR name LIKE '%power%')
                 ORDER BY id",
                [$this->novelId]
            );

            if (!empty($rules)) {
                foreach ($rules as $rule) {
                    $realms = $this->parseRealmChainFromRule($rule);
                    if (!empty($realms)) {
                        $this->realmChain = [
                            'realms'       => $realms,
                            'source'       => 'worldbuilding',
                            'power_system' => $rule['name'],
                        ];
                        return $this->realmChain;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 查询失败，回退默认
        }

        // 回退到智能默认：先尝试检测主角境界所属体系
        $realmHint = $this->fetchProtagonistRealmHint();
        $this->realmChain = $this->buildDefaultRealmChain($realmHint);
        return $this->realmChain;
    }

    /**
     * 构建智能回退境界链
     *
     * 逻辑：
     *   1. 有 hint 且匹配到体系 → 返回该体系的子链（精确）
     *   2. 无 hint 或未匹配 → 返回 DEFAULT_SYSTEM_KEY 的子链（修仙体系兜底）
     *
     * @param string $realmHint 主角当前境界名，用于体系检测
     * @return array 标准境界链结构 ['realms'=>[], 'source'=>string, 'power_system'=>string]
     */
    private function buildDefaultRealmChain(string $realmHint = ''): array
    {
        // 尝试自动检测
        $detectedKey = null;
        if ($realmHint !== '') {
            $detectedKey = $this->detectPowerSystem($realmHint);
        }

        $systemKey = $detectedKey ?: self::DEFAULT_SYSTEM_KEY;
        $system = self::REALM_SYSTEMS[$systemKey] ?? self::REALM_SYSTEMS[self::DEFAULT_SYSTEM_KEY];

        $sourceLabel = 'default:' . $systemKey;
        if ($detectedKey) {
            $sourceLabel .= '(auto-detected)';
        } else {
            $sourceLabel .= '(fallback)';
        }

        return [
            'realms'       => $system['realms'],
            'source'       => $sourceLabel,
            'power_system' => $system['name'],
        ];
    }

    /**
     * 从 worldbuilding 规则记录中解析境界链
     * 支持格式：
     *   - description: "炼气→筑基→金丹→元婴→化神"
     *   - description: "炼气、筑基、金丹、元婴、化神"
     *   - attributes JSON: {"realm_order": ["炼气","筑基","金丹"]}
     */
    private function parseRealmChainFromRule(array $rule): array
    {
        // 优先从 attributes JSON 读取
        $attrs = $rule['attributes'] ?? null;
        if ($attrs) {
            if (is_string($attrs)) {
                $attrs = json_decode($attrs, true);
            }
            if (is_array($attrs) && !empty($attrs['realm_order']) && is_array($attrs['realm_order'])) {
                return array_filter($attrs['realm_order'], fn($r) => is_string($r) && trim($r) !== '');
            }
        }

        // 从 description 解析
        $desc = $rule['description'] ?? '';
        if (empty($desc)) return [];

        // 格式1: "炼气→筑基→金丹→元婴"
        if (mb_strpos($desc, '→') !== false) {
            return array_map('trim', array_filter(explode('→', $desc)));
        }
        // 格式2: "炼气->筑基->金丹->元婴"
        if (mb_strpos($desc, '->') !== false) {
            return array_map('trim', array_filter(explode('->', $desc)));
        }
        // 格式3: "炼气、筑基、金丹、元婴"
        if (mb_strpos($desc, '、') !== false) {
            return array_map('trim', array_filter(explode('、', $desc)));
        }
        // 格式4: "炼气,筑基,金丹,元婴"
        if (mb_strpos($desc, ',') !== false) {
            return array_map('trim', array_filter(explode(',', $desc)));
        }

        return [];
    }

    // ================================================================
    // 2. 境界位置计算
    // ================================================================

    /**
     * 获取角色境界在链中的索引位置
     * @return int 索引（0开始），未匹配返回 -1
     */
    public function getRealmIndex(string $realm): int
    {
        $chain = $this->getRealmChain();
        $realms = $chain['realms'];

        // 精确匹配
        foreach ($realms as $i => $label) {
            if ($realm === $label) return $i;
        }

        // 模糊匹配（境界名可能含前缀后缀，如"金丹初期"匹配"金丹"）
        foreach ($realms as $i => $label) {
            if (mb_strpos($realm, $label) !== false) return $i;
            if (mb_strpos($label, $realm) !== false) return $i;
        }

        return -1;
    }

    /**
     * 获取指定偏移量的境界名
     * @param string $realm 当前境界
     * @param int $offset 偏移量（正数=更高, 负数=更低）
     * @return string|null 境界名，超出范围返回 null
     */
    public function getRealmOffset(string $realm, int $offset): ?string
    {
        $chain = $this->getRealmChain();
        $idx = $this->getRealmIndex($realm);
        if ($idx < 0) return null;

        $targetIdx = $idx + $offset;
        if ($targetIdx < 0 || $targetIdx >= count($chain['realms'])) return null;

        return $chain['realms'][$targetIdx];
    }

    // ================================================================
    // 3. 反派实力对标
    // ================================================================

    /**
     * 生成反派实力对标提示词
     * 规则：反派境界应比主角高1-2个境界
     * 
     * @param string $protagonistName 主角名
     * @param string $protagonistRealm 主角当前境界
     * @return string 提示词片段，无境界信息时返回空串
     */
    public function buildAntagonistConstraint(string $protagonistName, string $protagonistRealm): string
    {
        if (empty($protagonistRealm)) return '';

        $chain = $this->getRealmChain();
        $idx = $this->getRealmIndex($protagonistRealm);
        if ($idx < 0) return '';

        $realms = $chain['realms'];
        $total = count($realms);

        // 计算反派建议境界范围
        $antagonistMinIdx = min($idx + 1, $total - 1);
        $antagonistMaxIdx = min($idx + 2, $total - 1);

        $antagonistMinRealm = $realms[$antagonistMinIdx];
        $antagonistMaxRealm = $realms[$antagonistMaxIdx];

        $constraint = "【等级对标规则——必须遵守】\n";
        $constraint .= "当前主角「{$protagonistName}」的境界为「{$protagonistRealm}」。\n";
        $constraint .= "本章出现的反派/对手的境界必须在「{$antagonistMinRealm}」到「{$antagonistMaxRealm}」之间";
        
        if ($antagonistMinRealm === $antagonistMaxRealm) {
            $constraint .= "（即「{$antagonistMinRealm}」）";
        }
        $constraint .= "。\n";
        $constraint .= "规则说明：\n";
        $constraint .= "1. 反派实力应比主角高1-2个境界，制造合理的压力感和成长空间\n";
        $constraint .= "2. 禁止安排远超主角的碾压级反派（如高3个境界以上），这会导致剧情崩坏\n";
        $constraint .= "3. 禁止安排低于主角境界的反派作为主要威胁，除非有特殊设定（如智谋型、陷阱型）\n";
        $constraint .= "4. 如果主角即将突破，可在战斗中安排反派恰好比主角高1个境界，突破后形成反杀\n";
        $constraint .= "5. 如果是群战/多反派场景，主要反派遵循上述规则，小兵可以低于主角境界\n";

        return $constraint;
    }

    /**
     * 获取主角当前境界
     * 从 character_cards 中查找 role_type='protagonist' 的角色
     */
    public function getProtagonistRealm(): array
    {
        try {
            // 优先从 character_cards 查找
            $card = DB::fetch(
                "SELECT cc.name, cc.attributes 
                 FROM character_cards cc 
                 JOIN novel_characters nc ON nc.name = cc.name AND nc.novel_id = cc.novel_id
                 WHERE cc.novel_id=? AND nc.role_type='protagonist' 
                 LIMIT 1",
                [$this->novelId]
            );

            if (!$card) {
                // 回退：只查 character_cards
                $card = DB::fetch(
                    "SELECT name, attributes FROM character_cards 
                     WHERE novel_id=? AND name IN (
                         SELECT name FROM novel_characters WHERE novel_id=? AND role_type='protagonist'
                     ) LIMIT 1",
                    [$this->novelId, $this->novelId]
                );
            }

            if ($card && !empty($card['attributes'])) {
                $attrs = is_string($card['attributes']) 
                    ? json_decode($card['attributes'], true) 
                    : $card['attributes'];
                if (is_array($attrs) && !empty($attrs['realm'])) {
                    return [
                        'name'  => $card['name'],
                        'realm' => $attrs['realm'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // 查询失败
        }

        return ['name' => '', 'realm' => ''];
    }

    // ================================================================
    // 4. 兼容旧代码：提供 realmOrder 数组
    // ================================================================

    /**
     * 获取境界顺序数组（兼容 MemoryEngine::detectRealmSkip）
     * @return array
     */
    public function getRealmOrder(): array
    {
        $chain = $this->getRealmChain();
        return $chain['realms'];
    }

    /**
     * 检测境界跳级（替代 MemoryEngine 中的硬编码版本）
     * 
     * @param string $name 角色名
     * @param string|null $oldRealm 旧境界
     * @param string $newRealm 新境界
     * @param int $chapterNumber 章节号
     * @return string|null 警告消息，无跳级返回 null
     */
    public function detectRealmSkip(string $name, ?string $oldRealm, string $newRealm, int $chapterNumber): ?string
    {
        if (!$oldRealm || $oldRealm === $newRealm) return null;

        $realmOrder = $this->getRealmOrder();
        $oldIdx = -1;
        $newIdx = -1;

        foreach ($realmOrder as $i => $label) {
            if (mb_strpos($oldRealm, $label) !== false) $oldIdx = $i;
            if (mb_strpos($newRealm, $label) !== false) $newIdx = $i;
        }

        if ($oldIdx >= 0 && $newIdx >= 0 && $newIdx > $oldIdx + 1) {
            $skipped = [];
            for ($i = $oldIdx + 1; $i < $newIdx; $i++) {
                $skipped[] = $realmOrder[$i];
            }
            return "⚠️ 境界跳级警告：{$name} 由「{$oldRealm}」直接晋升「{$newRealm}」，跳过了 " . implode('→', $skipped) . "（第{$chapterNumber}章）";
        }

        return null;
    }
}
