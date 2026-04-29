<?php
/**
 * 集中配置常量 — 全局唯一的配置权威来源
 * 
 * 所有业务/技术常量、写作参数默认值统一在此文件定义，
 * 由 config.php 统一引入，确保全局只有一份定义。
 * 
 * 命名规范：
 *   RT_*       — 模型回退/重试策略
 *   CFG_TIME_* — 执行时间限制
 *   CFG_CURL_* — CURL 超时
 *   CFG_SSE_*  — SSE / 心跳
 *   CFG_PATH_* — 路径相关
 *   CFG_ZOMBIE_* — 僵死/Lock 检测
 *   CFG_TOKEN_* — Token 估算
 *   CFG_WORD_*  — 字数/收尾相关
 *   CFG_POST_*  — 后处理阈值
 *   CFG_OPTIMIZE_* — 大纲优化
 */

defined('APP_LOADED') or die('Direct access denied.');

// ============================================================
// 一、模型回退/重试策略（RT_*）
// 原分散在 write_chapter.php / daemon_write.php / write_engine.php
// 使用 if(!defined) 允许调用方提前覆盖
// ============================================================
if (!defined('RT_NONTHINKING_TIMEOUT')) define('RT_NONTHINKING_TIMEOUT', 50);
if (!defined('RT_THINKING_TIMEOUT'))     define('RT_THINKING_TIMEOUT', 90);
if (!defined('RT_SAME_MODEL_MAX'))       define('RT_SAME_MODEL_MAX', 3);
if (!defined('RT_MODEL_ERR_MAX'))        define('RT_MODEL_ERR_MAX', 5);
if (!defined('RT_RETRY_DELAY'))          define('RT_RETRY_DELAY', 15);
if (!defined('RT_POLL_INTERVAL'))        define('RT_POLL_INTERVAL', 5);

// ============================================================
// 二、执行时间限制（原分散在 10+ 个 API 文件的 set_time_limit()）
// ============================================================
if (!defined('CFG_TIME_LONG'))           define('CFG_TIME_LONG', 600);   // 写作/大纲生成
if (!defined('CFG_TIME_MEDIUM'))         define('CFG_TIME_MEDIUM', 300); // 压缩/润色/分析
if (!defined('CFG_TIME_SHORT'))          define('CFG_TIME_SHORT', 120);  // 同步请求
if (!defined('CFG_TIME_UNLIMITED'))      define('CFG_TIME_UNLIMITED', 0); // CLI Worker / 重建

// ============================================================
// 三、CURL 超时（原硬编码在 ai.php / EmbeddingProvider.php）
// ============================================================
if (!defined('CFG_CURL_TIMEOUT_STREAM')) define('CFG_CURL_TIMEOUT_STREAM', 600);
if (!defined('CFG_CURL_TIMEOUT_SYNC'))   define('CFG_CURL_TIMEOUT_SYNC', 120);
if (!defined('CFG_CURL_TIMEOUT_EMBED'))  define('CFG_CURL_TIMEOUT_EMBED', 60);

// ============================================================
// 四、SSE / 心跳（原分散在多处且有重复定义）
// ============================================================
if (!defined('CFG_SSE_HEARTBEAT'))       define('CFG_SSE_HEARTBEAT', 10);  // SSE 心跳间隔（秒）
if (!defined('CFG_SSE_SILENCE'))         define('CFG_SSE_SILENCE', 30);    // AI 静默检测阈值（秒）
if (!defined('CFG_SSE_AI_CHECK'))        define('CFG_SSE_AI_CHECK', 5);    // AI chunk 心跳间隔（秒）

// ============================================================
// 五、路径（原在 6+ 个文件中拼写相同字符串）
// ============================================================
if (!defined('CFG_PROGRESS_DIR'))        define('CFG_PROGRESS_DIR', sys_get_temp_dir() . '/novel_write_progress');

// ============================================================
// 六、僵死/Lock 超时检测（原在各文件中不一致）
// ============================================================
if (!defined('CFG_ZOMBIE_PROGRESS'))     define('CFG_ZOMBIE_PROGRESS', 60);   // 进度文件僵死阈值（秒）
if (!defined('CFG_ZOMBIE_DB'))           define('CFG_ZOMBIE_DB', 300);        // DB 章节僵死阈值（秒）
if (!defined('CFG_LOCK_TTL'))            define('CFG_LOCK_TTL', 300);         // Lock 文件过期（秒）
if (!defined('CFG_PROGRESS_STALE'))      define('CFG_PROGRESS_STALE', 300);   // 过期进度文件清理（秒）

// ============================================================
// 七、Token 估算（原在 write_engine.php / daemon_write.php 不一致）
// ============================================================
if (!defined('CFG_TOKEN_RATIO'))         define('CFG_TOKEN_RATIO', 2.2);     // 字 → token 换算系数
if (!defined('CFG_TOKEN_BUFFER'))        define('CFG_TOKEN_BUFFER', 600);    // 额外缓冲

// ============================================================
// 八、字数/收尾控制（原硬编码在 prompt.php 中）
// ============================================================
if (!defined('CFG_EARLY_FINISH_RATIO'))  define('CFG_EARLY_FINISH_RATIO', 0.85); // 单章提前收尾触发比例
if (!defined('CFG_TOLERANCE_MIN'))       define('CFG_TOLERANCE_MIN', 80);        // 容差兜底最小值（字）
if (!defined('CFG_TOLERANCE_RATIO'))     define('CFG_TOLERANCE_RATIO', 0.03);   // 容差兜底比例
if (!defined('CFG_ENDING_START_RATIO'))  define('CFG_ENDING_START_RATIO', 0.90); // 全书收尾触发比例（进度≥90%时注入收尾指令）

// ============================================================
// 九、后处理阈值
// ============================================================
if (!defined('CFG_MEMORY_TOKEN_BUDGET'))  define('CFG_MEMORY_TOKEN_BUDGET', 12000); // 记忆上下文 token 预算
if (!defined('CFG_KB_PREVIEW_CHARS'))     define('CFG_KB_PREVIEW_CHARS', 3000);    // 知识库预览字数
if (!defined('CFG_VERSIONS_KEEP'))        define('CFG_VERSIONS_KEEP', 10);         // 版本保留数
if (!defined('CFG_CANCEL_CHECK_FREQ'))    define('CFG_CANCEL_CHECK_FREQ', 50);     // 取消检测频率（token）

// ============================================================
// 十、大纲优化（原 config_optimize.php，现统一至此）
// ============================================================
if (!defined('CFG_OPTIMIZE_MODE'))       define('CFG_OPTIMIZE_MODE', 'ajax');
if (!defined('CFG_OPTIMIZE_BATCH'))      define('CFG_OPTIMIZE_BATCH', 10);
if (!defined('CFG_OPTIMIZE_AJAX_DELAY')) define('CFG_OPTIMIZE_AJAX_DELAY', 500);

// ============================================================
// 十一、章节摘要字数联动参数
// ============================================================
if (!defined('CFG_SYNOPSIS_MIN_RATIO')) define('CFG_SYNOPSIS_MIN_RATIO', 0.05); // 摘要最小比例
if (!defined('CFG_SYNOPSIS_MAX_RATIO')) define('CFG_SYNOPSIS_MAX_RATIO', 0.08); // 摘要最大比例
if (!defined('CFG_SYNOPSIS_MIN_WORDS')) define('CFG_SYNOPSIS_MIN_WORDS', 150);   // 摘要最小字数
if (!defined('CFG_SYNOPSIS_MAX_WORDS')) define('CFG_SYNOPSIS_MAX_WORDS', 400);   // 摘要最大字数

// ============================================================
// 十二、写作参数默认值 — 全局唯一来源
// 原在 config.php / db.php / writing_settings.php / install.php 四处重复
// ============================================================
function getWritingDefaults(): array
{
    return [
        // ── 基础生成参数 ──
        'ws_chapter_words'               => ['default' => 2000,    'type' => 'int'],
        'ws_chapter_word_tolerance'      => ['default' => 150,     'type' => 'int'],
        'ws_outline_batch'               => ['default' => 20,      'type' => 'int'],
        'ws_auto_write_interval'         => ['default' => 2,       'type' => 'int'],
        // ── 爽点调度参数 ──
        'ws_cool_point_density_target'   => ['default' => 0.88,    'type' => 'float'],
        'ws_cool_point_hunger_threshold' => ['default' => 0.6,     'type' => 'float'],
        'ws_double_coolpoint_gap'        => ['default' => 3,       'type' => 'int'],
        // ── 章节结构参数 ──
        'ws_segment_ratio_setup'         => ['default' => 20,      'type' => 'int'],
        'ws_segment_ratio_rising'        => ['default' => 30,      'type' => 'int'],
        'ws_segment_ratio_climax'        => ['default' => 35,      'type' => 'int'],
        'ws_segment_ratio_hook'          => ['default' => 15,      'type' => 'int'],
        // ── 伏笔与记忆参数 ──
        'ws_foreshadowing_lookback'      => ['default' => 10,      'type' => 'int'],
        'ws_memory_lookback'             => ['default' => 5,       'type' => 'int'],
        'ws_embedding_top_k'             => ['default' => 5,       'type' => 'int'],
        // ── AI 生成参数 ──
        'ws_temperature_outline'         => ['default' => 0.3,     'type' => 'float'],
        'ws_temperature_chapter'         => ['default' => 0.8,     'type' => 'float'],
        'ws_max_tokens_outline'          => ['default' => 4096,    'type' => 'int'],
        'ws_max_tokens_chapter'          => ['default' => 8192,    'type' => 'int'],
        // ── 质量检查参数 ──
        'ws_quality_check_enabled'       => ['default' => true,    'type' => 'bool'],
        'ws_quality_min_score'           => ['default' => 6.0,     'type' => 'float'],
    ];
}

/**
 * 约束框架默认配置 — 全局唯一来源
 * @return array
 */
function getConstraintDefaults(): array
{
    return [
        // ── 总开关 ──
        'cf_enabled'                     => ['default' => '1',     'type' => 'bool'],
        'cf_strict_mode'                 => ['default' => '0',     'type' => 'bool'],
        // ── 角色约束 ──
        'cf_combat_ratio_min'            => ['default' => '40',    'type' => 'int'],
        'cf_combat_ratio_max'            => ['default' => '60',    'type' => 'int'],
        'cf_speed_factor'                => ['default' => '10',    'type' => 'int'],
        'cf_rival_factor'                => ['default' => '0.8',   'type' => 'float'],
        // ── 情节约束 ──
        'cf_max_same_conflict'           => ['default' => '1',     'type' => 'int'],
        'cf_max_coincidences'            => ['default' => '5',     'type' => 'int'],
        // ── 信息/伏笔约束 ──
        'cf_foreshadowing_recovery_min'  => ['default' => '70',    'type' => 'int'],
        'cf_max_new_info_per_ch'         => ['default' => '2',     'type' => 'int'],
        // ── 节奏约束 ──
        'cf_min_buffer_release'          => ['default' => '2',     'type' => 'int'],
        'cf_cooldown_after_climax'       => ['default' => '1',     'type' => 'int'],
        // ── 语言/风格约束 ──
        'cf_max_banned_word_usage'       => ['default' => '15',    'type' => 'int'],
        'cf_banned_words'                => ['default' => '绝境,反杀,真相,背水,逆袭', 'type' => 'string'],
    ];
}
