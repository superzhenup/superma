<?php
// ============================================================
// 运行环境兼容性检测
// ============================================================
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    die('系统要求 PHP 8.2+，当前版本：' . PHP_VERSION . '。请在宝塔面板或 php.ini 中切换 PHP 版本。');
}

// 审计修复 L-2（2026-07-01）：显式关闭错误显示，防止 PHP 错误堆栈暴露给用户
// 不依赖 php.ini 的 display_errors 设置
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    // PHP 基线升至 8.2（2026-08-16）：CLI worker（bin/*.php、cron 任务）同样
    // 排除 E_DEPRECATED——8.2 起动态属性等弃用项会以 E_DEPRECATED 输出到
    // stderr/cron 日志造成噪音；php.ini production 模板默认排除，但开发
    // 环境模板不排除，此处统一口径（B4 加固）。
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// ============================================================
// 数据库配置
// ============================================================
define('DB_HOST',    'localhost');
define('DB_PORT',    3306);
define('DB_NAME',    'ai_novel');
define('DB_USER',    'ai_novel');
define('DB_PASS',    '');   // 安装向导写入真实密码；未安装时为空
define('DB_CHARSET', 'utf8mb4');

// 审计修复 C-1（2026-06-17）：禁止空密码上线
// 安装向导会写入真实密码；若 config.php 仍是占位空值则拒绝启动，
// 防止"绕过安装向导"或"安装失败"等场景下数据库完全无防护。
//
// 修复（2026-06-18）：未安装时跳转 install.php 而非 die，让用户能完成安装。
// 原代码两个问题：
//   1) 在 DB_PASS 定义之前引用，PHP 8+ 触发 fatal error 直接 500
//   2) 未安装时本应引导用户去 install.php，却 die 报错卡死
// 现逻辑：install.lock 不存在 → 跳转 install.php；install.lock 存在但 DB_PASS 仍空 → die
//
// 未安装跳转对所有非 CLI SAPI 生效（含 cli-server 开发服务器）；
// DB_PASS 空 die 仅对生产 SAPI 生效（cli-server 开发环境宽容，便于调试）。
$isCli = (php_sapi_name() === 'cli');
$isDevServer = (PHP_SAPI === 'cli-server');
if (!$isCli) {
    $installLockPath = __DIR__ . '/install.lock';
    if (!file_exists($installLockPath)) {
        // 未安装：跳转安装向导（install.php 自身不 require config.php，不会循环）
        // 仅对浏览器请求跳转；API 请求返回 JSON 提示
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isApi = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false)
              || (strpos($accept, 'application/json') !== false);
        if ($isApi) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => '系统未安装，请访问 install.php 完成安装', 'error' => 'not_installed'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Location: install.php');
        }
        exit;
    }
    if (!$isDevServer && DB_PASS === '') {
        // 审计修复 D-3（2026-06-22）：API 请求返回 JSON 错误，页面请求返回纯文本。
        // 原实现统一 die 纯文本，API 调用方无法解析错误。
        $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)
              || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        http_response_code(500);
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'msg'   => '数据库密码未配置，请联系管理员',
                'error' => 'db_not_configured',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            die('数据库密码未配置（DB_PASS 为空）。请删除 install.lock 并重新运行 install.php 完成安装。');
        }
        exit;
    }
}

// ============================================================
// 后台账号（由安装向导写入，请勿手动修改密码明文）
// ============================================================
// 审计 2026-08-13 #3：安装向导同时将凭证写入 storage/admin_credentials.json
// （storage/ 受 deny-all .htaccess 保护，web 不可达）。
// loadAdminCredentials() 优先读取 json 文件，以下常量作为向后兼容回退。
// 旧安装升级后无需改动——json 不存在时自动回退到常量。
//
// 审计修复 M2（2026-08-16）：随源码分发的默认 bcrypt 哈希构成"回退默认凭据"——
// 任何跳过安装向导手动创建 install.lock 的部署（或 json 写入失败且 config.php
// 未被重写的升级场景），该公开哈希即可离线破解后直接登录。
// 改为空哨兵：adminCredentialsConfigured() 对 <50 字符直接判"未配置"，
// doLogin 拒绝并记录日志，强制用户走 install.php 完成安装。
// install.php 的替换正则（[^;\r\n]*）对空值兼容，安装时正常注入真实哈希。
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', '');   // 空 = 未安装哨兵；install.php 会写入真实 password_hash 散列

// ============================================================
// 站点配置
// ============================================================
define('SITE_NAME', 'AI小说创作系统');
define('BASE_PATH', __DIR__);

// 开发模式开关。当前仅作为扩展/诊断能力的统一环境标记；
// 本地 Ollama 的精确 loopback 放行由模型 URL 校验独立控制。
define('DEV_MODE', PHP_SAPI === 'cli-server');

// ============================================================
// 默认生成参数
// ============================================================
define('DEFAULT_CHAPTER_WORDS',   2000);   // 每章目标字数
define('DEFAULT_OUTLINE_BATCH',   20);     // 每次生成大纲章节数
define('AUTO_WRITE_INTERVAL',     2);      // 自动写作间隔(秒)

// ============================================================
// 文字数据统计 隐私化统计 仅统计文字数量 可以关闭
// ============================================================
define('STATS_REPORT_ENABLED',    false);                                       // 是否启用统计上报（true/false）
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

// ---- 产品版本单一来源 ----
require_once __DIR__ . '/includes/version.php';

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

        // [v42] 请求级缓存（审计 2026-06-10 P2-C）：优先走整表缓存
        // （includes/config_constants.php::_systemSettingsAll），载入失败时回退单键直查。
        $val = null;
        $found = false;
        if (function_exists('_systemSettingsAll')) {
            $all = _systemSettingsAll();
            if ($all !== null) {
                if (!array_key_exists($key, $all)) {
                    return $default;
                }
                $val = $all[$key];
                $found = true;
            }
        }

        if (!$found) {
            $row = DB::fetch('SELECT setting_value FROM system_settings WHERE setting_key=?', [$key]);
            if (!$row) {
                return $default;
            }
            $val = $row['setting_value'];
        }

        return match ($type) {
            'int'    => (int)$val,
            'float'  => (float)$val,
            'bool'   => in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on']),
            default  => (string)$val,
        };
    } catch (\Throwable $e) {
        // 审计修复（2026-08-30 M-5）：单键直查也失败意味着 DB 故障期间所有配置
        // 读取静默回退默认值（如 harness 模式从 on 突变 off），原空 catch 零告警。
        // static 标志保证每请求只记一次，防止高频调用刷屏。
        static $lookupFailureLogged = false;
        if (!$lookupFailureLogged) {
            $lookupFailureLogged = true;
            error_log('[config] system_settings per-key lookup failed, returning defaults: '
                . $e->getMessage());
        }
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
