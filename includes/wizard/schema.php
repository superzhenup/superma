<?php
/**
 * 高阶向导 — Schema 自适应 & 数据访问层
 *
 * 职责：
 *   - 探测 novels 表实际列名（只读；向导字段由统一迁移 ColumnMigrations [v37] 补齐）
 *   - 把向导宽数据安全写入（filterCols 兜底）
 *   - 提供阶段间共用的常量数据（标签库、阶段元数据）
 */
defined('APP_LOADED') or die('Direct access denied.');

if (!function_exists('wizardEnsureSchema')) {

function wizardEnsureSchema(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // 只读探测。向导字段（narrative_structure … chapter_word_target）由统一迁移补齐，
    // 缺列说明未执行 php bin/migrate.php --apply，此时 DB::connect 的 schema 屏障已拒绝服务。
    try {
        $cols = DB::fetchAll("SHOW COLUMNS FROM `novels`");
        $existing = array_column($cols, 'Field');
    } catch (\Throwable $e) {
        error_log('wizardEnsureSchema: ' . $e->getMessage());
        return $cache = ['cols' => [], 'added' => []];
    }

    return $cache = ['cols' => $existing, 'added' => []];
}

/**
 * 向导字数目标同时写入 chapter_word_target（向导 UI）与 chapter_words（写作引擎）。
 * 两者不同步时，高阶模式设的 2500 会 silently 按 schema 默认 2000 写章。
 */
function wizardChapterWordFields(int $wordTarget): array
{
    $wordTarget = max(800, min(10000, $wordTarget));
    return [
        'chapter_word_target' => $wordTarget,
        'chapter_words'       => $wordTarget,
    ];
}

function wizardFilterNovelRow(array $row): array {
    $sch = wizardEnsureSchema();
    $out = [];
    foreach ($row as $k => $v) {
        if (in_array($k, $sch['cols'], true)) $out[$k] = $v;
    }
    return $out;
}

/**
 * 返回 volume_outlines 表当前列集合（缓存，只读）。
 * 新旧两套列（volume_index/chapter_from/chapter_to 与 volume_number/start_chapter/
 * end_chapter）、theme 扩容、旧 NOT NULL 列放宽与回填均由统一迁移 v71
 * （ColumnMigrations::convergeVolumeOutlines）完成；Web 请求不执行 DDL。
 */
function wizardVolumeOutlinesColumns(): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    try {
        $rows = DB::fetchAll("SHOW COLUMNS FROM `volume_outlines`");
        $cols = array_column($rows, 'Field');
    } catch (\Throwable $e) {
        $cols = [];
    }
    return $cols;
}

/**
 * 只清理没有 AI 内容的卷。向导早期创建的简表没有 summary/key_events/status，
 * 不能在 DELETE 中直接引用；按实际列构造条件，同时保留其他富字段中的内容。
 */
function wizardEmptyVolumeGuard(array $cols): string {
    if (!$cols) return '1 = 0'; // 列探测失败时不删除任何卷

    $checks = [];
    foreach (['summary', 'conflict', 'resolution'] as $col) {
        if (in_array($col, $cols, true)) {
            $checks[] = "({$col} IS NULL OR {$col} = '')";
        }
    }
    foreach (['key_events', 'character_focus', 'foreshadowing', 'volume_goals', 'must_resolve_foreshadowing'] as $col) {
        if (in_array($col, $cols, true)) {
            // MySQL 原生 JSON null 与 SQL 字符串 'null' 不直接相等；
            // 转文本后比较，同时兼容旧表把 JSON 存在 TEXT 中的情况。
            $checks[] = "({$col} IS NULL OR CAST({$col} AS CHAR) = 'null')";
        }
    }
    if (in_array('status', $cols, true)) $checks[] = "status = 'pending'";

    return $checks ? implode(' AND ', $checks) : '1 = 1';
}

function wizardStages(): array {
    return [
        'topic'     => ['name' => '立项',     'icon' => 'bi-bullseye',         'desc' => '基础信息 + 全部设定标签'],
        'blueprint' => ['name' => '蓝图',     'icon' => 'bi-diagram-3',        'desc' => '策划 + 世界观（AI 一次双产物）'],
        'content'   => ['name' => '内容',     'icon' => 'bi-people',           'desc' => '角色卡 + 卷大纲（左右双面板）'],
        'launch'    => ['name' => '启航',     'icon' => 'bi-rocket-takeoff',   'desc' => 'AI 体检 + 跳转完整编辑页'],
    ];
}

function wizardStageRedirect(string $oldStage): string {
    return [
        'planning'  => 'blueprint',
        'world'     => 'blueprint',
        'character' => 'content',
        'outline'   => 'content',
        'settings'  => 'topic',
        'review'    => 'launch',
        'completed' => 'launch', // 已完成的向导再次打开时落在启航页，而非回退到选题
    ][$oldStage] ?? $oldStage;
}

function wizardTagLibrary(): array {
    return [
        'narrative_structure' => [
            'label' => '叙事结构', 'multi' => false, 'allow_custom' => true,
            'options' => ['单线推进','双线并行','多线交织','单元剧','环形叙事','倒叙/插叙','碎片化叙事'],
        ],
        'narrative_method' => [
            'label' => '叙事方法', 'multi' => false, 'allow_custom' => true,
            'options' => ['顺叙','倒叙','插叙','意识流','书信体','日记体','对话体'],
        ],
        'narrative_pov' => [
            'label' => '叙事视角', 'multi' => false, 'allow_custom' => true,
            'options' => ['第一人称','第三人称限制','第三人称全知','第二人称','多视角切换'],
        ],
        'literary_genre' => [
            'label' => '文学流派', 'multi' => false, 'allow_custom' => true,
            'options' => ['爽文','虐文','甜文','暗黑','治愈','搞笑','正剧','实验文学'],
        ],
        'world_setting_era' => [
            'label' => '世界设定（时代）', 'multi' => false, 'allow_custom' => true,
            'options' => ['古代','近代','现代','未来','架空','异世界','末世','星际'],
        ],
        'novel_types' => [
            'label' => '小说类型', 'multi' => true, 'allow_custom' => true,
            'options' => ['玄幻','仙侠','都市','言情','武侠','科幻','悬疑','历史','游戏','体育','军事','灵异','轻小说','二次元'],
        ],
        'writing_tone' => [
            'label' => '文风', 'multi' => true, 'allow_custom' => true,
            'options' => ['热血','轻松','暗黑','温馨','烧脑','史诗','细腻','快节奏','慢热'],
        ],
        'protagonist_traits' => [
            'label' => '主角设定', 'multi' => true, 'allow_custom' => true,
            'options' => ['废柴逆袭','天才型','腹黑','圣母','杀伐果断','苟','搞笑','高冷','闷骚','疯批'],
        ],
        'core_conflicts' => [
            'label' => '核心冲突', 'multi' => true, 'allow_custom' => true,
            'options' => ['复仇','争霸','求生','探秘','成长','守护','解谜','对抗命运','情感纠葛'],
        ],
        'appeal_points' => [
            'label' => '爽点', 'multi' => true, 'allow_custom' => true,
            'options' => ['升级打怪','扮猪吃虎','装逼打脸','金手指','后宫','逆袭','智商碾压','团宠','养成'],
        ],
        'taboos' => [
            'label' => '禁忌', 'multi' => true, 'allow_custom' => true,
            'options' => ['禁圣母','禁虐主','禁NTR','禁后宫','禁降智','禁水字数','禁烂尾','禁太监'],
        ],
        'opening_type' => [
            'label' => '开篇类型', 'multi' => false, 'allow_custom' => true,
            'options' => ['穿越/重生','意外事件','日常切入','悬念开头','倒叙开头','对话开头','场景描写'],
        ],
        'protagonist_entrance' => [
            'label' => '主角出场', 'multi' => false, 'allow_custom' => true,
            'options' => ['弱小起步','天赋异禀','隐藏身份','被迫卷入','主动出击','神秘来历'],
        ],
    ];
}

function wizardPresetPacks(): array {
    return [
        'hot_blood' => [
            'name' => '🔥 热血爽文',
            'tags' => [
                'narrative_structure' => '单线推进',
                'narrative_pov' => '第三人称限制',
                'writing_tone' => ['热血','快节奏'],
                'protagonist_traits' => ['杀伐果断','废柴逆袭'],
                'appeal_points' => ['升级打怪','扮猪吃虎','装逼打脸'],
                'taboos' => ['禁圣母','禁虐主'],
                'opening_type' => '穿越/重生',
                'protagonist_entrance' => '弱小起步',
            ],
        ],
        'brain_burn' => [
            'name' => '🧠 智斗烧脑',
            'tags' => [
                'narrative_structure' => '多线交织',
                'narrative_pov' => '多视角切换',
                'writing_tone' => ['烧脑','细腻'],
                'protagonist_traits' => ['腹黑','天才型'],
                'core_conflicts' => ['探秘','对抗命运'],
                'appeal_points' => ['智商碾压','逆袭'],
                'taboos' => ['禁降智','禁水字数'],
                'opening_type' => '悬念开头',
                'protagonist_entrance' => '被迫卷入',
            ],
        ],
        'warm_heal' => [
            'name' => '🌸 温馨治愈',
            'tags' => [
                'narrative_structure' => '单元剧',
                'narrative_pov' => '第一人称',
                'writing_tone' => ['温馨','慢热'],
                'protagonist_traits' => ['搞笑','高冷'],
                'core_conflicts' => ['守护','成长'],
                'appeal_points' => ['团宠','养成'],
                'taboos' => ['禁虐主','禁NTR'],
                'opening_type' => '日常切入',
                'protagonist_entrance' => '主动出击',
            ],
        ],
    ];
}

} // end function_exists guard
