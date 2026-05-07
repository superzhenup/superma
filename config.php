<?php
// ============================================================
// 运行环境兼容性检测
// ============================================================
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die('系统要求 PHP 8.0+，当前版本：' . PHP_VERSION . '。请在宝塔面板或 php.ini 中切换 PHP 版本。');
}

// ============================================================
// 数据库配置U2FsdGVkX1/JF1AwCx1RG7WVHCeT1XOOxqDjoFoPhAZOHGJycN8SMHlC/ZxOaYdB
// ============================================================
define('DB_HOST',    'localhost');
define('DB_NAME',    'ai_novel');
define('DB_USER',    'ai_novel');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 后台账号（由安装向导写入，请勿手动修改密码明文）
// ============================================================
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', '');   // password_hash 散列值，由 install.php 生成

// ============================================================
// 站点配置
// ============================================================
define('SITE_NAME', 'AI小说创作系统');
define('BASE_PATH', __DIR__);

// ============================================================
// 默认生成参数
// ============================================================
define('DEFAULT_CHAPTER_WORDS',   2000);   // 每章目标字数
define('DEFAULT_OUTLINE_BATCH',   20);     // 每次生成大纲章节数
define('AUTO_WRITE_INTERVAL',     2);      // 自动写作间隔(秒)

// ============================================================
// 文字数据统计 隐私化统计 仅统计文字数量 可以关闭
// ============================================================
define('STATS_REPORT_ENABLED',    true);                                        // 是否启用统计上报（true/false）
define('STATS_SERVER_URL',        'https://www.itzo.cn/api/stats_receiver.php'); // 上报服务器地址
define('STATS_SITE_ID',           '');                                          // 站点唯一标识（留空则自动生成）

// ============================================================
// 细纲/大纲生成时显示模型思考过程（reasoning_content）
// 0=关闭  1=开启  开启后前端会实时展示AI的推理内容，缓解等待焦虑
// ============================================================
define('CFG_SHOW_OUTLINE_THINKING', 1);

// ============================================================
// 细纲/大纲生成时模型思考过程的静默超时（秒）
// 深度思考模型在生成细纲前会先推理较长时间，默认600秒（10分钟）
// ============================================================
define('CFG_OUTLINE_THINKING_TIMEOUT', 600);

// ---- 禁止直接访问 includes/api 文件（由各入口文件定义） ----
defined('APP_LOADED') or define('APP_LOADED', true);

// ---- 引入集中配置常量（Phase 3） ----
require_once __DIR__ . '/includes/config_constants.php';

// ---- 引入配置中心类（Agent机制依赖） ----
require_once __DIR__ . '/includes/config_center.php';

// ============================================================
// 系统设置读取辅助函数（写作参数等全局配置）
// 所有参数存储在 system_settings 表中，key 前缀 ws_ = writing settings
// ============================================================
/**
 * 从 system_settings 读取单个设置值，找不到时返回默认值。
 * 注意：此函数需要 DB 已连接，建议在 config.php 之后、业务代码中使用。
 *
 * @param string $key          setting_key
 * @param mixed  $default      默认值
 * @param string $type         类型转换: int|float|string|bool
 * @return mixed
 */
if (!function_exists('getSystemSetting')) {
function getSystemSetting(string $key, $default = null, string $type = 'string') {
    try {
        // DB 类可能尚未加载，做防御性检查
        if (!class_exists('DB', false)) {
            return $default;
        }
        $row = DB::fetch('SELECT setting_value FROM system_settings WHERE setting_key=?', [$key]);
        if (!$row) {
            return $default;
        }
        $val = $row['setting_value'];
        return match ($type) {
            'int'    => (int)$val,
            'float'  => (float)$val,
            'bool'   => in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on']),
            default  => (string)$val,
        };
    } catch (\Throwable) {
        return $default;
    }
}
}

/**
 * 批量读取写作参数，返回 key=>value 数组。
 * 用于需要一次性获取多个参数的场景（如构建 Prompt 时）。
 *
 * @param array $keys  ['ws_chapter_words'=>'int', 'ws_temperature_chapter'=>'float', ...]
 * @return array
 */
if (!function_exists('getWritingSettings')) {
function getWritingSettings(array $keys): array {
    $result = [];
    // 从全局唯一默认值中提取（Phase 3 配置集中化）
    $defaults = getWritingDefaults();
    foreach ($keys as $key => $type) {
        $def     = $defaults[$key] ?? ['default'=>null, 'type'=>'string'];
        $result[$key] = getSystemSetting($key, $def['default'], $type ?: $def['type']);
    }
    return $result;
}
} // function_exists('getWritingSettings')

// ============================================================
// Agent决策机制配置
// ============================================================
/**
 * 获取Agent配置
 * 
 * @return array Agent配置数组
 */
if (!function_exists('getAgentConfig')) {
function getAgentConfig(): array {
    return [
        'enabled' => getSystemSetting('agent.enabled', true, 'bool'),
        
        'strategy_agent' => [
            'enabled' => getSystemSetting('agent.strategy_agent.enabled', true, 'bool'),
            'decision_interval' => getSystemSetting('agent.strategy_agent.decision_interval', 10, 'int'),
        ],
        
        'quality_agent' => [
            'enabled' => getSystemSetting('agent.quality_agent.enabled', true, 'bool'),
            'check_interval' => getSystemSetting('agent.quality_agent.check_interval', 5, 'int'),
            'auto_fix' => getSystemSetting('agent.quality_agent.auto_fix', true, 'bool'),
        ],
        
        'optimization_agent' => [
            'enabled' => getSystemSetting('agent.optimization_agent.enabled', true, 'bool'),
            'optimization_interval' => getSystemSetting('agent.optimization_agent.optimization_interval', 20, 'int'),
        ],
    ];
}
} // function_exists('getAgentConfig')

