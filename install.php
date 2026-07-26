<?php
/**
 * 系统安装向导 — 一键安装数据库并设置管理员账号
 * v1.2：新增 v7 大纲增强 + 知识库完整建表（角色/世界观/情节/风格向量字段）
 *       + v10 深度思考(Thinking)开关 + chapter_versions + consistency_logs
 *       + v16 封面图片功能（cover_image 字段 + 图片生成 API 配置）
 *       + v17 Agent决策机制（智能写作策略、质量监控、系统优化）
 *       + v17.1 作者画像系统（写作习惯/叙事手法/思想情感/创作个性分析）
 */

// install.php 是安装向导，不依赖 config.php（它是被安装程序创建的）
// 但后续 include 的 schema.php 等文件需要此常量，在此手动定义
define('APP_LOADED', true);
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/model_presets.php';

define('LOCK_FILE', __DIR__ . '/install.lock');
define('INSTALL_SCHEMA_VERSION', 54);

// 安全加固：已安装后访问此页面直接返回 404。
// 原因：install.php 暴露数据库配置格式和管理员账号结构，
// 攻击者可借此探测系统安装状态。安装完成后应彻底隐藏入口。
// 如需重新安装，请先手动删除根目录下的 install.lock 文件。
//
// 审计说明（2026-06-12）：本系统为单用户系统，安装完成后自动生成
// install.lock 文件锁，此后 install.php 返回 404 不可访问。
// install.lock 需要服务器文件系统权限才能删除，攻击者无法远程删除。
// 因此 install.php 无 CSRF 保护在当前架构下不构成安全风险，无需修复。
if (file_exists(LOCK_FILE)) {
    http_response_code(404);
    exit('Not found.');
}

if (session_status() === PHP_SESSION_NONE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// CSRF Token 生成（安装向导也需防护跨站请求伪造）
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$alreadyInstalled = false;
$installModelPresets = getInstallSkyhostModelPresets();
$installModelToken = '';

$host       = 'localhost';
$user       = 'ai_novel';
$pass       = '';
$dbname     = 'ai_novel';
$adminUser  = 'admin';
$adminPass  = '';
$adminPass2 = '';
$error      = '';
$success    = '';

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    $csrfToken = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['install_csrf'] ?? '', $csrfToken)) {
        $error = '安全验证失败，请刷新页面重试。';
    } else {
    $host       = trim($_POST['db_host']     ?? 'localhost');
    $user       = trim($_POST['db_user']     ?? 'ai_novel');
    $pass       = $_POST['db_pass']          ?? '';
    $dbname     = trim($_POST['db_name']     ?? 'ai_novel');
    $adminUser  = trim($_POST['admin_user']  ?? 'admin');
    $adminPass  = $_POST['admin_pass']       ?? '';
    $adminPass2 = $_POST['admin_pass2']      ?? '';

    if ($adminUser === '') {
        $error = '管理员用户名不能为空。';
    } elseif (strlen($adminPass) < 6) {
        $error = '管理员密码至少需要 6 位。';
    } elseif ($adminPass !== $adminPass2) {
        $error = '两次输入的密码不一致。';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) {
        $error = '数据库名称只能包含字母、数字和下划线。';
    } else {
        try {
            // 审计修复 P0-1（2026-07-12）：原子安装锁消除并发安装竞态
            // fopen('x') 原子独占创建：第二个并发请求返回 false，杜绝 TOCTOU 窗口
            $installInProgressLock = __DIR__ . '/storage/install_in_progress.lock';
            $installLockFp = @fopen($installInProgressLock, 'x');
            if ($installLockFp === false) {
                throw new RuntimeException('检测到另一个安装进程正在进行中，请等待几分钟后重试。如确信无其他安装进程，请手动删除 storage/install_in_progress.lock 文件。');
            }
            // 确保脚本结束时释放锁（成功/失败/致命错误均生效）
            register_shutdown_function(function() use (&$installLockFp, $installInProgressLock) {
                if (is_resource($installLockFp)) {
                    @fclose($installLockFp);
                }
                @unlink($installInProgressLock);
            });

            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbname`");

            // 空库必须先建立基础表，再执行默认数据写入和兼容旧库的 ALTER。
            // 之前的顺序会先 INSERT system_settings / ALTER 业务表，空库首次安装
            // 因 1146（表不存在）直接中止。Schema 同时负责按内联 FK 依赖排序建表。
            require_once __DIR__ . '/includes/schema.php';
            if (!class_exists('Schema')) {
                throw new RuntimeException('Schema class unavailable during installation');
            }
            Schema::applyAll($pdo);

            // ================================================================
            // 建表 SQL（v3 完整版，含所有优化字段）
            // ================================================================
            $statements = [

                // 初始化 system_settings：embedding 模型 ID + 写作参数默认值
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
                  ('global_embedding_model_id', ''),
                  ('ws_chapter_words',              '2000'),
                  ('ws_chapter_word_tolerance',     '150'),
                  ('ws_outline_batch',              '5'),
                  ('ws_auto_write_interval',        '2'),
                  ('ws_cool_point_density_target',  '0.88'),
                  ('ws_cool_point_hunger_threshold','0.6'),
                  ('ws_double_coolpoint_gap',       '3'),
                  ('ws_segment_ratio_setup',        '20'),
                  ('ws_segment_ratio_rising',       '30'),
                  ('ws_segment_ratio_climax',       '35'),
                  ('ws_segment_ratio_hook',         '15'),
                  ('ws_foreshadowing_lookback',     '10'),
                  ('ws_memory_lookback',            '5'),
                  ('ws_embedding_top_k',            '5'),
                  ('ws_temperature_outline',        '0.3'),
                  ('ws_temperature_chapter',        '0.8'),
                  ('ws_max_tokens_outline',         '4096'),
                  ('ws_max_tokens_chapter',         '8192'),
                  ('ws_quality_check_enabled',      '1'),
                  ('ws_quality_min_score',          '6.0'),
                  ('image_gen_api_url',             ''),
                  ('image_gen_api_key',             ''),
                  ('image_gen_api_mode',            'images'),
                  ('image_gen_model',               'gpt-image-2'),
                  ('image_gen_size',                '1024x1536'),
                  ('image_gen_prompt_prefix',       '')",

                // P3 优化：字数容差可配置（老库升级兜底，新安装已包含在上方 INSERT IGNORE 块中）
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('ws_chapter_word_tolerance', '150')",

                // 卷级目标 + 强制伏笔回收（老库升级兜底，新安装已包含在 CREATE TABLE 中）
                // MySQL 不支持 ADD COLUMN IF NOT EXISTS，重复执行会抛 1060，已在下方 catch 中忽略
                "ALTER TABLE `volume_outlines` ADD COLUMN `volume_goals` JSON COMMENT '本卷写作目标' AFTER `foreshadowing`",
                "ALTER TABLE `volume_outlines` ADD COLUMN `must_resolve_foreshadowing` JSON COMMENT '本卷必须回收的伏笔描述列表' AFTER `volume_goals`",

                // foreshadowing_items priority 字段（老库升级兜底，新安装已包含在 CREATE TABLE 中）
                // 索引 idx_priority 由 schema.php CREATE TABLE 内 KEY 定义 + IndexMigrations::up 兜底，此处无需重复
                "ALTER TABLE `foreshadowing_items` ADD COLUMN `priority` ENUM('critical','major','minor') NOT NULL DEFAULT 'minor' COMMENT '伏笔优先级' AFTER `description`",

                // P1#7: ai_models capabilities 字段（模型能力标签，老库升级兜底）
                // 用于智能模型选择，按任务类型(creative/structured/synopsis)排序模型
                "ALTER TABLE `ai_models` ADD COLUMN `capabilities` JSON DEFAULT NULL COMMENT '模型能力标签' AFTER `embedding_dim`",

                // v1.6: chapters actual_opening_type 字段（开篇类型实际检测，老库升级兜底）
                "ALTER TABLE `chapters` ADD COLUMN `actual_opening_type` VARCHAR(30) DEFAULT NULL COMMENT '实际检测到的开篇类型' AFTER `opening_type`",

                // v1.7: novels.author_profile_id 字段（绑定作者画像，老库升级兜底）
                "ALTER TABLE `novels` ADD COLUMN `author_profile_id` INT UNSIGNED DEFAULT NULL COMMENT '绑定的作者画像ID' AFTER `ref_author`",

                // author_profiles 四个风格提示词字段（老库升级兜底）
                "ALTER TABLE `author_profiles` ADD COLUMN `writing_habits_prompt` TEXT DEFAULT NULL COMMENT '写作习惯提示词' AFTER `influences`",
                "ALTER TABLE `author_profiles` ADD COLUMN `narrative_style_prompt` TEXT DEFAULT NULL COMMENT '叙事手法提示词' AFTER `writing_habits_prompt`",
                "ALTER TABLE `author_profiles` ADD COLUMN `sentiment_prompt` TEXT DEFAULT NULL COMMENT '思想情感提示词' AFTER `narrative_style_prompt`",
                "ALTER TABLE `author_profiles` ADD COLUMN `creative_identity_prompt` TEXT DEFAULT NULL COMMENT '创作个性提示词' AFTER `sentiment_prompt`",

                // v5: story_outlines.character_progression 字段（角色等级发展轨迹，老库升级兜底）
                "ALTER TABLE `story_outlines` ADD COLUMN `character_progression` JSON DEFAULT NULL COMMENT '角色等级/境界发展轨迹' AFTER `character_endpoints`",

                // v31: chapters 表新增 v1.9 盲点修复字段（老库升级兜底）
                "ALTER TABLE `chapters` ADD COLUMN `rewritten` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被RewriteAgent重写过' AFTER `quality_score`",
                "ALTER TABLE `chapters` ADD COLUMN `critic_scores` JSON DEFAULT NULL COMMENT 'CriticAgent读者视角评分' AFTER `rewritten`",
                "ALTER TABLE `chapters` ADD COLUMN `ai_pattern_issues` JSON DEFAULT NULL COMMENT 'StyleGuard AI痕迹检测结果' AFTER `critic_scores`",

                // v32: chapters 表新增 v1.10 迭代精炼系统字段（之前在独立 update 脚本，现纳入主流程）
                "ALTER TABLE `chapters` ADD COLUMN `iterations_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '迭代改进轮数' AFTER `ai_pattern_issues`",
                "ALTER TABLE `chapters` ADD COLUMN `total_improvement` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '总质量提升分数' AFTER `iterations_used`",
                "ALTER TABLE `chapters` ADD COLUMN `iterative_history` JSON DEFAULT NULL COMMENT '迭代历史详情' AFTER `total_improvement`",
                "ALTER TABLE `chapters` ADD COLUMN `iteration_evaluation` JSON DEFAULT NULL COMMENT '迭代效果评估' AFTER `iterative_history`",
                "ALTER TABLE `chapters` ADD COLUMN `rewrite_time` DATETIME DEFAULT NULL COMMENT '最后一次重写时间' AFTER `iteration_evaluation`",

                // 热门选题默认配置
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
                    ('hot_novels_ingest_key',             ''),
                    ('hot_novels_ingest_enabled',         '1'),
                    ('hot_novels_unsupported_categories', '奇闻异事,游戏,体育,古风世情'),
                    ('hot_novels_min_confidence',         '50')",

                // Agent默认配置
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
                    ('agent.enabled', '1'),
                    ('agent.strategy_agent.enabled', '1'),
                    ('agent.strategy_agent.decision_interval', '10'),
                    ('agent.quality_agent.enabled', '1'),
                    ('agent.quality_agent.check_interval', '5'),
                    ('agent.quality_agent.auto_fix', '1'),
                    ('agent.optimization_agent.enabled', '1'),
                    ('agent.optimization_agent.optimization_interval', '20')",

                // v1.9 重写/迭代改进默认配置（AdaptiveParameterTuner 动态调参基线）
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
                    ('ws_rewrite_enabled',          '0'),
                    ('ws_rewrite_threshold',        '70'),
                    ('ws_rewrite_min_gain',         '10'),
                    ('ir_max_iterations',           '3'),
                    ('ir_target_score',             '80'),
                    ('ir_min_improvement',          '5.0'),
                    ('ir_quality_decline_threshold','3.0')",

                // v1.10+ Agent 后置检测开关
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
                    ('ws_critic_enabled',              '1'),
                    ('ws_style_guard_enabled',          '1'),
                    ('ws_ai_patterns_check_enabled',    '1')",

                // 约束框架默认配置（Phase 1）
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
                    ('cf_enabled', '1'),
                    ('cf_strict_mode', '0'),
                    ('cf_word_tolerance_pct', '30'),
                    ('cf_title_banned_words', '??,震惊,擦,卧槽,草,妈的,跌下神坛,扮猪吃虎,扮猪吃老虎'),
                    ('cf_max_combat_ratio', '40'),
                    ('cf_min_combat_ratio', '5'),
                    ('cf_max_same_conflict', '3'),
                    ('cf_cooldown_after_climax', '5'),
                    ('cf_min_buffer_release', '2'),
                    ('cf_coincidence_limit', '2'),
                    ('cf_repeated_sentence_count', '3'),
                    ('cf_direct_emotion_limit', '3'),
                    ('cf_banned_word_strict', '0'),
                    ('cf_protagonist_voice_ratio', '60')",

                // v1.10.3: 写作能力优化字段（老库升级兜底）
                "ALTER TABLE `novels` ADD COLUMN `target_reader` VARCHAR(30) NOT NULL DEFAULT 'general' COMMENT '目标读者画像' AFTER `author_profile_id`",
                "ALTER TABLE `chapters` ADD COLUMN `human_critic_scores` JSON DEFAULT NULL COMMENT '人工读者视角评分(5维)' AFTER `critic_scores`",
                "ALTER TABLE `chapters` ADD COLUMN `calibrated_critic_scores` JSON DEFAULT NULL COMMENT '校准后的Critic评分' AFTER `human_critic_scores`",
                "ALTER TABLE `character_cards` ADD COLUMN `voice_profile` JSON DEFAULT NULL COMMENT '语音指纹' AFTER `alive`",
                "ALTER TABLE `foreshadowing_items` ADD COLUMN `last_mentioned_chapter` INT UNSIGNED DEFAULT NULL COMMENT '最近提及章节' AFTER `resolved_at`",
                "ALTER TABLE `foreshadowing_items` ADD COLUMN `mention_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '提及次数' AFTER `last_mentioned_chapter`",

                // v40: 短篇分章节写作支持（老库升级兜底）
                "ALTER TABLE `short_stories` ADD COLUMN `chapter_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '章节数量,1=单篇模式' AFTER `structure_type`",
                "ALTER TABLE `short_stories` ADD COLUMN `chapters_json` JSON DEFAULT NULL COMMENT '章节数组' AFTER `beats_json`",
                "ALTER TABLE `short_story_versions` ADD COLUMN `chapters_json` JSON DEFAULT NULL AFTER `beats_json`",

                // novel_state 新增图谱起始章节字段（老库升级兜底）
                "ALTER TABLE `novel_state` ADD COLUMN `graph_start_chapter` INT UNSIGNED DEFAULT NULL COMMENT '图谱关系起始构建章节号(NULL=未启用)' AFTER `last_ingested_chapter`",

                // 圣经节点化 + 图谱关系 + Blueprint 默认配置
                "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
                    ('ws_bible_crud_enabled',       '1'),
                    ('ws_graph_search_enabled',     '1'),
                    ('ws_graph_max_hops',           '2'),
                    ('ws_graph_hop1_limit',         '30'),
                    ('ws_graph_hop2_limit',         '20'),
                    ('ws_blueprint_enabled',        '0')",
            ];

            foreach ($statements as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    // 仅忽略幂等重试时的"索引已存在"(1061)和"列已存在"(1060)。
                    // 列不存在等结构错误必须中止安装，避免把不完整数据库标记为成功。
                    $code = (int)($e->errorInfo[1] ?? 0);
                    if ($code !== 1061 && $code !== 1060) throw $e;
                }
            }

            // 新安装不会再进入历史迁移流程，因此在写入当前 schema 版本前，
            // 显式应用核心外键；任何失败都必须阻止 install.lock 落盘。
            require_once __DIR__ . '/includes/db/migrations/ForeignKeyMigrations.php';
            $foreignKeyErrors = [];
            ForeignKeyMigrations::up($pdo, $foreignKeyErrors);
            if ($foreignKeyErrors !== []) {
                throw new RuntimeException(
                    '安装数据库外键不完整：' . implode(' | ', $foreignKeyErrors)
                );
            }

            // 默认数据和兼容性 ALTER 完成后验证；失败必须中止安装，不能创建 install.lock。
            $schemaCheck = Schema::verify($pdo);
            if (!($schemaCheck['ok'] ?? false)) {
                throw new RuntimeException(
                    '安装数据库结构不完整，缺少表：' . implode(', ', $schemaCheck['missing'] ?? [])
                );
            }

            // 新安装已经达到当前结构版本，避免首次进入首页重复执行全部历史迁移。
            $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?"
            )->execute([
                'schema_version_migrated',
                (string)INSTALL_SCHEMA_VERSION,
                (string)INSTALL_SCHEMA_VERSION,
            ]);

            // 生成密码散列
            $passHash = password_hash($adminPass, PASSWORD_BCRYPT);
            // 审计修复 H-NEW-2（2026-06-15）：addslashes 对 $、{} 等不转义，改用 var_export
            // 生成合法的 PHP 字符串字面量（返回值自带引号，模板中不再外包引号）。
            $esc = fn(string $s) => var_export($s, true);

            // 写入 config.php
            $configContent = <<<PHP
<?php
// ============================================================
// 运行环境兼容性检测
// ============================================================
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die('系统要求 PHP 8.0+，当前版本：' . PHP_VERSION . '。请在宝塔面板或 php.ini 中切换 PHP 版本。');
}

// ============================================================
// 数据库配置
// ============================================================
define('DB_HOST',    {$esc($host)});
define('DB_NAME',    {$esc($dbname)});
define('DB_USER',    {$esc($user)});
define('DB_PASS',    {$esc($pass)});
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 后台账号（由安装向导写入，请勿手动修改密码明文）
// ============================================================
define('ADMIN_USER', {$esc($adminUser)});
define('ADMIN_PASS', {$esc($passHash)});

// ============================================================
// 站点配置
// ============================================================
define('SITE_NAME', 'AI小说创作系统');
define('BASE_PATH', __DIR__);

// ============================================================
// 默认生成参数
// ============================================================
define('DEFAULT_CHAPTER_WORDS',   2000);
define('DEFAULT_OUTLINE_BATCH',   20);
define('AUTO_WRITE_INTERVAL',     2);

// ============================================================
// 文字数据统计 隐私化统计 仅统计文字数量 可以关闭
// ============================================================
define('STATS_REPORT_ENABLED',    false);                                       // 是否启用统计上报（true/false）
define('STATS_SERVER_URL',        'https://www.itzo.cn/api/stats_receiver.php'); // 上报服务器地址
define('STATS_SITE_ID',           '');                                          // 站点唯一标识（留空则自动生成）

// ---- 禁止直接访问 includes/api 文件（由各入口文件定义） ----
defined('APP_LOADED') or define('APP_LOADED', true);

// ---- 产品版本单一来源 ----
require_once __DIR__ . '/includes/version.php';

// ---- 引入集中配置常量 ----
require_once __DIR__ . '/includes/config_constants.php';

// ---- 引入配置中心类（Agent机制依赖） ----
require_once __DIR__ . '/includes/config_center.php';

// ============================================================
// 系统设置读取辅助函数（写作参数等全局配置）
// 所有参数存储在 system_settings 表中，key 前缀 ws_ = writing settings
// ============================================================
/**
 * 从 system_settings 读取单个设置值，找不到时返回默认值。
 * [v42] 优先走请求级整表缓存（includes/config_constants.php::_systemSettingsAll），
 * 载入失败时回退单键直查（审计 2026-06-10 P2-C）。
 */
function getSystemSetting(string \$key, \$default = null, string \$type = 'string') {
    try {
        if (!class_exists('DB', false)) {
            return \$default;
        }
        \$val = null;
        \$found = false;
        if (function_exists('_systemSettingsAll')) {
            \$all = _systemSettingsAll();
            if (\$all !== null) {
                if (!array_key_exists(\$key, \$all)) {
                    return \$default;
                }
                \$val = \$all[\$key];
                \$found = true;
            }
        }
        if (!\$found) {
            \$row = DB::fetch('SELECT setting_value FROM system_settings WHERE setting_key=?', [\$key]);
            if (!\$row) {
                return \$default;
            }
            \$val = \$row['setting_value'];
        }
        return match (\$type) {
            'int'    => (int)\$val,
            'float'  => (float)\$val,
            'bool'   => in_array(strtolower((string)\$val), ['1', 'true', 'yes', 'on']),
            default  => (string)\$val,
        };
    } catch (\Throwable \$e) {
        return \$default;
    }
}

/**
 * 批量读取写作参数，返回 key=>value 数组。
 */
function getWritingSettings(array \$keys): array {
    \$result = [];
    // 从全局唯一默认值中提取
    \$defaults = getWritingDefaults();
    foreach (\$keys as \$key => \$type) {
        \$def = \$defaults[\$key] ?? ['default'=>null, 'type'=>'string'];
        \$result[\$key] = getSystemSetting(\$key, \$def['default'], \$type ?: \$def['type']);
    }
    return \$result;
}
PHP;

            // config.php 必须只有一个真实模板来源。上面的旧内嵌模板为向后兼容暂保留，
            // 实际安装改为读取当前仓库模板并只替换环境相关凭据，避免安装后丢失
            // 错误隐藏、未安装保护、DEV_MODE、Agent 配置等后续新增的安全逻辑。
            $configTemplate = @file_get_contents(__DIR__ . '/config.php');
            if ($configTemplate === false || $configTemplate === '') {
                throw new RuntimeException('无法读取 config.php 配置模板');
            }
            $configContent = $configTemplate;
            $replaceConfigDefine = static function (string $name, string $literal) use (&$configContent): void {
                $pattern = '~define\(\s*[\'\"]' . preg_quote($name, '~') . '[\'\"]\s*,\s*[^;\r\n]*\);~';
                $replacement = "define('{$name}', {$literal});";
                // preg_replace() 会把 replacement 里的 $1/$2/$10 当成反向引用。
                // bcrypt/Argon 哈希本身以 "$2y$..." / "$argon2..." 开头，直接替换会
                // 静默删除哈希片段，导致安装后任何密码都无法登录。回调返回值按原样写入。
                $updated = preg_replace_callback(
                    $pattern,
                    static fn(array $matches): string => $replacement,
                    $configContent,
                    1,
                    $count
                );
                if ($updated === null || $count !== 1) {
                    throw new RuntimeException("config.php 模板缺少唯一的 {$name} 定义");
                }
                $configContent = $updated;
            };
            foreach ([
                'DB_HOST'    => $esc($host),
                'DB_NAME'    => $esc($dbname),
                'DB_USER'    => $esc($user),
                'DB_PASS'    => $esc($pass),
                'ADMIN_USER' => $esc($adminUser),
                'ADMIN_PASS' => $esc($passHash),
            ] as $configName => $configLiteral) {
                $replaceConfigDefine($configName, $configLiteral);
            }

            // 写入前检查目录权限
            if (!is_writable(__DIR__)) {
                $who = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : (getenv('USERNAME') ?: getenv('USER') ?: 'web');
                if (PHP_OS_FAMILY === 'Windows') {
                    $error = "项目目录不可写（" . __DIR__ . "），Web 进程用户（{$who}）没有写入权限。"
                           . "请右键目录 → 属性 → 安全，授予 Web 进程用户写入权限。";
                } else {
                    $error = "项目目录不可写（" . __DIR__ . "），Web 进程用户（{$who}）没有写入权限。"
                           . "请在服务器执行：<code>chmod -R 755 " . htmlspecialchars(__DIR__) . "</code> "
                           . "或 <code>chown -R www:www " . htmlspecialchars(__DIR__) . "</code>"
                           . "（将 www:www 替换为你的 Web 用户）";
                }
            } else {
                $configBytes = @file_put_contents(__DIR__ . '/config.php', $configContent, LOCK_EX);
                if ($configBytes === false || $configBytes !== strlen($configContent)) {
                    throw new RuntimeException('config.php 写入不完整，安装锁未创建；请检查文件权限和磁盘空间');
                }

                $lockContent =
                    "Installed at: " . date('Y-m-d H:i:s') . "\n" .
                    "DB Host: $host\n" .
                    "DB Name: $dbname\n" .
                    "Admin: $adminUser\n" .
                    "Version: v" . APP_VERSION . " (Thinking + KnowledgeBase + CoverImage + Agent)\n";
                $lockBytes = @file_put_contents(LOCK_FILE, $lockContent, LOCK_EX);
                if ($lockBytes === false || $lockBytes !== strlen($lockContent)) {
                    @unlink(LOCK_FILE);
                    throw new RuntimeException('install.lock 写入不完整，安装未标记完成；请检查文件权限和磁盘空间');
                }

                // 审计修复 D-2（2026-06-22）：清理开发预览日志，避免残留文件随仓库分发
                foreach (glob(__DIR__ . '/storage/install_preview.*.log') ?: [] as $previewLog) {
                    @unlink($previewLog);
                }

                session_regenerate_id(true);
                unset($_SESSION['install_model_tested_models']);
                $_SESSION['install_model_token'] = bin2hex(random_bytes(32));
                $_SESSION['install_model_token_expires_at'] = time() + 1800;
                $installModelToken = $_SESSION['install_model_token'];

                // 确保缓存目录存在且可写
                $cacheDir = __DIR__ . '/storage/cache';
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                if (is_dir($cacheDir) && !is_writable($cacheDir)) {
                    @chmod($cacheDir, 0755);
                }

                $success = "安装成功！管理员账号：<strong>" . htmlspecialchars($adminUser) . "</strong>，数据库已就绪。";

                // 审计优化 P1-10（2026-06-16）：诊断工具生产环境关闭检查
                // 默认 CFG_DIAGNOSTIC_ENABLED=false（config_constants.php），无需额外操作；
                // 仅当用户在 config.php 手动开启时提示风险。
                if (defined('CFG_DIAGNOSTIC_ENABLED') && CFG_DIAGNOSTIC_ENABLED) {
                    $success .= '<br><span style="color:#ffc107">⚠ 警告：CFG_DIAGNOSTIC_ENABLED 已开启，诊断工具（diagnose_embedding.php、system_diagnostic.php）可被访问。生产环境建议在 config.php 设置 CFG_DIAGNOSTIC_ENABLED=false。</span>';
                }
            }

            // 关闭安装时的 PDO 连接，避免后续请求冲突
            $pdo = null;

        } catch (Throwable $e) {
            error_log('Install failed: ' . $e->getMessage());
            $error = '安装失败：' . htmlspecialchars($e->getMessage());
        }
    }
    } // end CSRF else
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 - Super Ma  AI小说创作系统</title>
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
<script>(function(){ var t='dark'; try{ t=localStorage.getItem('novel-theme')||'dark'; }catch(e){} document.documentElement.setAttribute('data-theme',t); })();</script>
<style>
:root {
    --bg-body:  #0f0f1a;
    --bg-card:  #1a1a2e;
    --border:   #2d2d4e;
    --text:     #e0e0f0;
    --muted:    #c8c8e0;
    --input-bg: #12122a;
    --model-help-text: #e2e4f5;
    --model-help-link: #b9c2ff;
    --model-help-bg: rgba(99,102,241,.12);
}
[data-theme="light"] {
    --bg-body:  #f0f2f5;
    --bg-card:  #ffffff;
    --border:   #d0d0e0;
    --text:     #1a1a2e;
    --muted:    #666688;
    --input-bg: #f8f8ff;
    --model-help-text: #3f3f59;
    --model-help-link: #4338ca;
    --model-help-bg: rgba(79,70,229,.08);
}
body { background: var(--bg-body); color: var(--text); min-height:100vh; display:flex; align-items:center; }
.card-install { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; }
.form-control, .form-select, .input-group-text {
    background: var(--input-bg); border-color: var(--border); color: var(--text);
}
.form-control:focus {
    background: var(--input-bg); border-color: #6366f1; color: var(--text);
    box-shadow: 0 0 0 .2rem rgba(99,102,241,.25);
}
.form-label { color: var(--muted); font-size: .875rem; }
.input-group-text { color: var(--muted); }
.logo { font-size:1.8rem; font-weight:700; background:linear-gradient(135deg,#6366f1,#a78bfa); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
.section-title { font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color:#6366f1; font-weight:600; border-bottom:1px solid var(--border); padding-bottom:.4rem; margin-bottom:1rem; }
.btn-install { background:linear-gradient(135deg,#6366f1,#8b5cf6); border:none; padding:.7rem; font-weight:600; }
.btn-install:hover { opacity:.9; }
.step-badge { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#6366f1; color:#fff; font-size:.7rem; font-weight:700; margin-right:.5rem; flex-shrink:0; }
.already-installed { background:rgba(99,102,241,.1); border:1px solid rgba(99,102,241,.3); border-radius:12px; }
.feature-list { list-style:none; padding:0; margin:0; }
.feature-list li { font-size:.8rem; color:var(--muted); padding:2px 0; }
.feature-list li::before { content:"✓ "; color:#10b981; font-weight:700; }
.install-model-card { background:rgba(99,102,241,.06); border:1px solid rgba(99,102,241,.3); border-radius:12px; }
.install-model-help {
    color:var(--model-help-text);
    background:var(--model-help-bg);
    border-left:3px solid #818cf8;
    border-radius:7px;
    padding:.65rem .75rem;
    font-size:.88rem;
    line-height:1.65;
}
.install-model-help a {
    color:var(--model-help-link);
    font-weight:700;
    text-decoration:underline;
    text-underline-offset:2px;
}
.install-model-help a:hover,
.install-model-help a:focus { color:var(--text); }
.install-model-option { display:flex; align-items:center; gap:.5rem; padding:.55rem .65rem; border:1px solid var(--border); border-radius:8px; color:var(--muted); font-size:.82rem; cursor:pointer; }
.install-model-option:has(input:checked) { border-color:#6366f1; background:rgba(99,102,241,.12); color:var(--text); }
.install-model-option .install-model-name { flex:1; }
.install-model-test-button { white-space:nowrap; }
.install-model-status { min-height:20px; font-size:.76rem; margin-top:.25rem; }
</style>
</head>
<body>
<div class="container py-4" style="max-width:560px">
  <div class="card-install p-4 p-md-5 shadow-lg">
    <div class="text-center mb-4">
      <div class="logo">✦ Super Ma AI创作系统</div>
      <p class="text-muted mt-1 mb-0" style="font-size:.8rem">安装向导 · v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="bi bi-check-circle me-1"></i><?= $success ?>
    </div>

    <form id="install-model-form" class="install-model-card p-3 mb-3">
      <div class="section-title"><span class="step-badge">3</span>接入 AI 模型（可跳过）</div>
      <div class="install-model-help mb-3">
        使用 Skyhost OpenAI 兼容接口逐个测试并添加模型。还没有 API Key？
        <a href="<?= htmlspecialchars(INSTALL_SKYHOST_REGISTER_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">前往注册获取密钥 <i class="bi bi-box-arrow-up-right"></i></a>
      </div>

      <div class="mb-3">
        <label class="form-label">API 地址</label>
        <input type="url" class="form-control form-control-sm" value="<?= htmlspecialchars(INSTALL_SKYHOST_API_URL, ENT_QUOTES, 'UTF-8') ?>" readonly>
      </div>
      <div class="mb-3">
        <label class="form-label" for="install-model-api-key">API Key</label>
        <input type="password" class="form-control form-control-sm" id="install-model-api-key" placeholder="sk-..." autocomplete="off">
        <div class="form-text text-muted">输入密钥后，请点击各模型右侧的“测试”按钮。修改密钥会清除已有测试结果。</div>
      </div>

      <div class="form-label">选择需要接入的模型</div>
      <div class="row g-2 mb-3">
        <?php foreach ($installModelPresets as $modelKey => $preset): ?>
        <div class="col-12 col-sm-6">
          <div class="install-model-option">
            <input type="checkbox" class="form-check-input mt-0 install-model-checkbox"
                   value="<?= htmlspecialchars($modelKey, ENT_QUOTES, 'UTF-8') ?>"
                   disabled>
            <span class="install-model-name"><?= htmlspecialchars($preset['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button"
                    class="btn btn-outline-primary btn-sm install-model-test-button"
                    data-test-model="<?= htmlspecialchars($modelKey, ENT_QUOTES, 'UTF-8') ?>">测试</button>
          </div>
          <div class="install-model-status text-muted" data-model-status="<?= htmlspecialchars($modelKey, ENT_QUOTES, 'UTF-8') ?>"></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="mb-3">
        <label class="form-label" for="install-default-model">默认模型</label>
        <select class="form-select form-select-sm" id="install-default-model">
          <?php foreach ($installModelPresets as $modelKey => $preset): ?>
          <option value="<?= htmlspecialchars($modelKey, ENT_QUOTES, 'UTF-8') ?>" <?= $modelKey === 'deepseek-v4-flash' ? 'selected' : '' ?>>
            <?= htmlspecialchars($preset['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="install-model-summary" class="small mb-2" style="display:none"></div>
      <button type="submit" class="btn btn-primary btn-install w-100" id="install-model-submit">
        <i class="bi bi-save me-1"></i>保存已测试成功的模型
      </button>
    </form>

      <div class="mb-3 p-3" style="background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.2);border-radius:8px">
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px;font-weight:600;">已创建数据库结构 (v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?>)</div>
      <ul class="feature-list">
        <li>ai_models · novels · chapters · writing_logs</li>
        <li>story_outlines — 全书故事大纲</li>
        <li>volume_outlines — 卷大纲（中层规划）</li>
        <li>chapter_synopses — 章节详细简介</li>
        <li>arc_summaries — 弧段故事线摘要（L2记忆）</li>
        <li><strong style="color:#10b981">novel_characters — 角色库（含功能模板/出场章节）</strong></li>
        <li><strong style="color:#10b981">novel_worldbuilding — 世界观库</strong></li>
        <li><strong style="color:#10b981">novel_plots — 情节库（含伏笔类型/回收章节）</strong></li>
        <li><strong style="color:#10b981">novel_style — 风格库（含四维向量/参考作者）</strong></li>
        <li><strong style="color:#10b981">novel_embeddings — 向量存储（语义搜索）</strong></li>
        <li><strong style="color:#10b981">character_cards — 人物状态卡片（记忆引擎）</strong></li>
        <li><strong style="color:#10b981">character_card_history — 人物变更历史</strong></li>
        <li><strong style="color:#10b981">foreshadowing_items — 伏笔独立表</strong></li>
        <li><strong style="color:#10b981">novel_state — 小说状态表</strong></li>
        <li><strong style="color:#10b981">novel_scene_templates — 场景模板使用记录（防套路化）</strong></li>
        <li><strong style="color:#10b981">memory_atoms — 原子记忆表</strong></li>
        <li><strong style="color:#10b981">book_analyses — 拆书分析表</strong></li>
        <li><strong style="color:#10b981">chapter_versions — 章节版本快照表</strong></li>
        <li><strong style="color:#10b981">consistency_logs — 一致性检测日志表</strong></li>
        <li><strong style="color:#10b981">system_settings — 系统设置表（含写作参数默认值）</strong></li>
        <li><strong style="color:#10b981">ai_models 扩展：thinking_enabled / can_embed / embedding_model_name / embedding_dim</strong></li>
        <li><strong style="color:#10b981">agent_decision_logs — Agent决策日志表</strong></li>
        <li><strong style="color:#10b981">agent_action_logs — Agent动作日志表</strong></li>

        <li><strong style="color:#10b981">agent_directives — Agent自然语言指令表（指令注入机制）</strong></li>
        <li><strong style="color:#10b981">agent_directive_outcomes — Agent指令效果反馈表（决策闭环）</strong></li>
        <li><strong style="color:#10b981">iterative_settings — 迭代改进设置表</strong></li>
        <li><strong style="color:#10b981">novel_catchphrases — 金句调度表（v1.3.5）</strong></li>
        <li><strong style="color:#10b981">pid_states — PID控制器状态表（v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?>）</strong></li>
      </ul>
    </div>
    <a href="login.php" class="btn btn-outline-secondary w-100" id="install-login-link">
      <i class="bi bi-box-arrow-in-right me-1"></i>跳过模型配置，前往登录 →
    </a>

    <?php else: ?>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small">
      <i class="bi bi-exclamation-triangle me-1"></i><?= $error ?>
    </div>
    <?php endif; ?>

    <form method="post" id="installForm">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['install_csrf'] ?? '') ?>">
      <!-- 环境检测 -->
      <?php
      $disabledFns = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
      $cliBinaryOk = false; $cliBinaryPath = '';
      if (!in_array('exec', $disabledFns) && function_exists('exec')) {
          if (PHP_OS_FAMILY === 'Windows') {
              @exec('where php 2>nul', $out, $code);
              $cliBinaryOk = ($code === 0 && !empty($out));
              $cliBinaryPath = $cliBinaryOk ? trim($out[0]) : '';
          } else {
              @exec('which php 2>/dev/null', $out, $code);
              $cliBinaryOk = ($code === 0 && !empty($out));
              $cliBinaryPath = $cliBinaryOk ? trim($out[0]) : '';
              // 兜底：常见路径
              if (!$cliBinaryOk) {
                  foreach (['/usr/bin/php', '/usr/local/bin/php', '/bin/php'] as $candidate) {
                      @exec(escapeshellarg($candidate) . ' -r "echo 1;" 2>/dev/null', $testOut, $testCode);
                      if ($testCode === 0 && !empty($testOut)) {
                          $cliBinaryOk = true; $cliBinaryPath = $candidate; break;
                      }
                  }
              }
          }
      }
      // 进度文件目录可写性
      $tmpWritable = is_writable(sys_get_temp_dir());
      $projectWritable = is_writable(__DIR__);
      $envChecks = [
          ['PHP 版本',   version_compare(PHP_VERSION, '8.0', '>='),                       '需要 PHP 8.0+（当前 ' . PHP_VERSION . '）'],
          ['exec()',     !in_array('exec', $disabledFns) && function_exists('exec'),      '异步写作模式需要（禁用后自动回退到SSE直连模式）'],
          ['popen()',    !in_array('popen', $disabledFns) && function_exists('popen'),    '异步写作模式需要（禁用后自动回退到SSE直连模式）'],
          ['pclose()',   !in_array('pclose', $disabledFns) && function_exists('pclose'),  '异步写作模式需要（禁用后自动回退到SSE直连模式）'],
          ['proc_open',  !in_array('proc_open', $disabledFns) && function_exists('proc_open'), '异步写作 Windows 备选（proc_open 比 popen 更可靠）'],
          ['flock()',    function_exists('flock'),                                          '进度文件并发锁（多进程写作安全）'],
          ['chmod()',    function_exists('chmod'),                                          'Shell wrapper 可执行权限设置'],
          ['curl',       function_exists('curl_init'),                                      'AI接口调用需要'],
          ['pdo_mysql',  extension_loaded('pdo_mysql'),                                     '数据库连接需要'],
          ['json',       function_exists('json_encode'),                                    '数据交互需要'],
          ['mbstring',   extension_loaded('mbstring'),                                      '中文字数统计需要'],
          ['session',    function_exists('session_start'),                                  '登录鉴权需要'],
          ['allow_url_fopen', (bool)ini_get('allow_url_fopen'),                             'HTTP Stream fallback（curl不可用时的备选）'],
          ['PHP CLI',    $cliBinaryOk,                                                      '异步写作核心依赖' . ($cliBinaryOk ? '（' . $cliBinaryPath . '）' : '（未找到，异步写作将不可用）')],
          ['进度目录(/tmp)', $tmpWritable,                                                  '异步写作进度文件写入' . ($tmpWritable ? '（可写）' : '（不可写，将回退到项目目录）')],
          ['项目目录可写', $projectWritable,                                                '配置文件/锁文件写入' . ($projectWritable ? '（可写）' : '（不可写）')],
      ];
      $hasWarning = false;
      foreach ($envChecks as $c) { if (!$c[1]) $hasWarning = true; }

      // ================================================================
      // 异步写作深入诊断（模拟 test_write_diag.php 核心检测）
      // 关键区别：不仅检查 disable_functions，还实测 exec/popen/proc_open
      // ================================================================
      $asyncDiag = [
          'tested'           => false,
          'exec_works'       => false,
          'popen_works'      => false,
          'proc_open_works'  => false,
          'php_binary'       => '',
          'php_binary_raw'   => '',
          'php_binary_fixed' => false,
          'worker_exists'    => false,
          'worker_syntax_ok' => false,
          'worker_syntax_out'=> '',
          'cli_pdo_mysql'    => false,
          'cli_ext_list'     => '',
          'mini_exec_ok'     => false,
          'verdict'          => 'skipped',
          'verdict_msg'      => '',
      ];

      $asyncCanTest = (!in_array('exec', $disabledFns) && function_exists('exec'));

      if ($asyncCanTest) {
          $asyncDiag['tested'] = true;
          $asyncDiag['php_binary_raw'] = PHP_BINARY ?: 'php';
          $phpBin = $asyncDiag['php_binary_raw'];

          // 1. 实测 exec()
          $testOut = []; $testCode = -1;
          if (PHP_OS_FAMILY === 'Windows') {
              @exec('echo 1', $testOut, $testCode);
          } else {
              @exec('echo 1 2>/dev/null', $testOut, $testCode);
          }
          $asyncDiag['exec_works'] = ($testCode === 0);

          // 2. 实测 popen()
          if (function_exists('popen') && function_exists('pclose')) {
              $cmd = (PHP_OS_FAMILY === 'Windows') ? 'echo 1' : 'echo 1 2>/dev/null';
              $p = @popen($cmd, 'r');
              if ($p) { pclose($p); $asyncDiag['popen_works'] = true; }
          }

          // 3. 实测 proc_open()
          if (function_exists('proc_open') && function_exists('proc_close')) {
              $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
              $cmd = (PHP_OS_FAMILY === 'Windows') ? 'echo 1' : 'echo 1 2>/dev/null';
              $proc = @proc_open($cmd, $desc, $pipes);
              if ($proc !== false) {
                  foreach ($pipes as $pp) fclose($pp);
                  proc_close($proc);
                  $asyncDiag['proc_open_works'] = true;
              }
          }

          // 4. PHP CLI 二进制修正（php-fpm → php-cli，宝塔关键修复）
          // Windows：Web SAPI 下 PHP_BINARY 多为 php-cgi.exe，须转 php.exe（CLI），否则 worker 被 403/argv 丢失打死
          if (PHP_OS_FAMILY === 'Windows' && preg_match('#php-cgi\.exe$#i', $phpBin)) {
              $phpBin = preg_replace('#php-cgi\.exe$#i', 'php.exe', $phpBin);
              $asyncDiag['php_binary_fixed'] = true;
          }
          if (PHP_OS_FAMILY !== 'Windows' && preg_match('#/php-fpm\d*$#', $phpBin)) {
              @exec('which php 2>/dev/null', $whichOut, $whichCode);
              if ($whichCode === 0 && !empty($whichOut[0])) {
                  $candidate = trim($whichOut[0]);
                  @exec(escapeshellarg($candidate) . ' -r "echo 1;" 2>/dev/null', $rTest, $rCode);
                  if ($rCode === 0) {
                      $phpBin = $candidate;
                      $asyncDiag['php_binary_fixed'] = true;
                  }
              }
              if (!$asyncDiag['php_binary_fixed']) {
                  $phpBin = str_replace('/sbin/php-fpm', '/bin/php', $phpBin);
                  $asyncDiag['php_binary_fixed'] = true;
              }
          }
          $asyncDiag['php_binary'] = $phpBin;

          // 5. Worker 脚本检查
          $workerScript = __DIR__ . '/api/write_chapter_worker.php';
          $asyncDiag['worker_exists'] = file_exists($workerScript);

          // 6. Worker 语法检查（PHP lint）
          if ($asyncDiag['exec_works'] && $asyncDiag['worker_exists']) {
              $syncCmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($workerScript) . ' 2>&1';
              @exec($syncCmd, $syncOut, $syncCode);
              $asyncDiag['worker_syntax_ok'] = ($syncCode === 0);
              $asyncDiag['worker_syntax_out'] = implode(' ', $syncOut);
          }

          // 7. CLI pdo_mysql 扩展检测（cli 可能加载不同的 php.ini）
          if ($asyncDiag['exec_works']) {
              @exec(escapeshellarg($phpBin) . ' -m 2>&1', $modOut, $modCode);
              $asyncDiag['cli_ext_list'] = implode(', ', array_slice($modOut, 2));
              foreach ($modOut as $line) {
                  if (stripos($line, 'pdo_mysql') !== false || stripos($line, 'PDO') !== false) {
                      $asyncDiag['cli_pdo_mysql'] = true;
                      break;
                  }
              }
          }

          // 8. 最小化后台执行测试（验证进程启动机制整体可用）
          if ($asyncDiag['exec_works']) {
              $miniScript = sys_get_temp_dir() . '/install_test_' . bin2hex(random_bytes(4)) . '.php';
              $miniOut    = sys_get_temp_dir() . '/install_test_' . bin2hex(random_bytes(4)) . '.txt';
              file_put_contents($miniScript, '<?php file_put_contents("' . addslashes($miniOut) . '", "OK_".time()."\n");');
              if (PHP_OS_FAMILY === 'Windows') {
                  $miniCmd = 'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($miniScript) . ' 2>nul';
                  @exec($miniCmd, $miniOutArr, $miniCode);
              } else {
                  $miniCmd = 'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($miniScript) . ' > /dev/null 2>&1 &';
                  @exec($miniCmd, $miniOutArr, $miniCode);
              }
              sleep(2);
              $asyncDiag['mini_exec_ok'] = (file_exists($miniOut) && strpos(@file_get_contents($miniOut) ?? '', 'OK_') !== false);
              @unlink($miniScript);
              @unlink($miniOut);
          }

          // 9. 综合判定
          $anyProcOk = $asyncDiag['exec_works'] || $asyncDiag['popen_works'] || $asyncDiag['proc_open_works'];
          if (!$anyProcOk) {
              $asyncDiag['verdict'] = 'sse_only';
              $asyncDiag['verdict_msg'] = '所有进程启动函数均被禁用或不可用';
          } elseif (PHP_OS_FAMILY !== 'Windows' && preg_match('#/php-fpm\d*$#', $asyncDiag['php_binary_raw']) && !$asyncDiag['php_binary_fixed']) {
              $asyncDiag['verdict'] = 'sse_only';
              $asyncDiag['verdict_msg'] = 'PHP_BINARY 为 php-fpm，无法执行 CLI 脚本，且未能找到 php-cli';
          } elseif (!$asyncDiag['cli_pdo_mysql']) {
              $asyncDiag['verdict'] = 'unstable';
              $asyncDiag['verdict_msg'] = 'PHP CLI 缺少 pdo_mysql 扩展，异步写作可能失败（CLI 可能使用不同的 php.ini）';
          } elseif ($asyncDiag['mini_exec_ok']) {
              $asyncDiag['verdict'] = 'ok';
              $asyncDiag['verdict_msg'] = '异步写作环境正常，后台进程启动验证通过';
          } else {
              $asyncDiag['verdict'] = 'unstable';
              $asyncDiag['verdict_msg'] = '后台进程启动测试失败。异步写作可能不可用，将自动回退到 SSE 直连模式。';
          }
      } else {
          $asyncDiag['verdict'] = 'sse_only';
          $asyncDiag['verdict_msg'] = 'exec() 被禁用，无法检测异步写作环境';
      }
      ?>
      <?php
      // 异步写作深度检测判定（已合并入「环境检测」区块，故提前到此计算以决定整体配色）
      $v = $asyncDiag['verdict'];
      $isOk      = ($v === 'ok');
      $isUnstable= ($v === 'unstable');
      $isSseOnly = ($v === 'sse_only');
      $sectionWarn = $hasWarning || !$isOk;   // 环境有警告，或异步写作非「可用」，整体按警告色
      ?>
      <div class="section-title"><span class="step-badge">0</span>环境检测 · 异步写作深度检测</div>
      <div class="mb-3 p-3" style="background:<?= $sectionWarn ? 'rgba(245,158,11,.06)' : 'rgba(16,185,129,.05)' ?>;border:1px solid <?= $sectionWarn ? 'rgba(245,158,11,.2)' : 'rgba(16,185,129,.2)' ?>;border-radius:8px">
        <?php foreach ($envChecks as $check): list($name, $ok, $desc) = $check; ?>
        <div class="d-flex align-items-center gap-2 py-1" style="font-size:.82rem">
          <?php if ($ok): ?>
            <i class="bi bi-check-circle-fill text-success"></i>
            <span style="color:#fff"><?= $name ?></span>
            <span style="font-size:.72rem;color:#fff">— <?= $desc ?></span>
          <?php else: ?>
            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
            <span class="fw-semibold" style="color:#fff"><?= $name ?></span>
            <span style="font-size:.72rem;color:#fff">— 已禁用，<?= $desc ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($hasWarning): ?>
        <div class="mt-2 pt-2" style="border-top:1px solid var(--border);font-size:.75rem;color:#fff">
          <i class="bi bi-info-circle me-1"></i>警告项不影响安装，但可能影响部分功能。exec/popen/pclose 禁用时写作将自动使用 SSE 直连模式（可能受 Nginx 超时限制）。PHP CLI 未找到时异步写作不可用。
        </div>
        <?php endif; ?>
        <!-- 异步写作深度检测（已合并入「环境检测」区块，原为独立 section） -->
        <div class="mt-3 pt-3" style="border-top:1px solid var(--border)">
          <div class="fw-semibold mb-2" style="font-size:.8rem;color:#fff"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>异步写作深度检测</div>
        <?php if (!$asyncDiag['tested']): ?>
        <div style="font-size:.82rem;color:#fff">
          <i class="bi bi-info-circle me-1"></i>exec() 被禁用，跳过异步写作深度检测。写作将自动使用 SSE 直连模式。
        </div>
        <?php else: ?>

        <!-- 总判定 -->
        <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border)">
          <div class="fw-semibold" style="font-size:.85rem">
            <?php if ($isOk): ?>
              <i class="bi bi-check-circle-fill text-success me-1"></i>异步写作可用
            <?php elseif ($isUnstable): ?>
              <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>SSE写作不稳定（会偶发自动写作章节断开问题）
            <?php else: ?>
              <i class="bi bi-x-circle-fill text-danger me-1"></i>仅 SSE 直连模式
            <?php endif; ?>
          </div>
          <div style="font-size:.75rem;color:#fff;margin-top:2px"><?= htmlspecialchars($asyncDiag['verdict_msg']) ?></div>
        </div>

        <!-- 详细检测项 -->
        <?php
        $diagItems = [
            ['exec() 实测',       $asyncDiag['exec_works'],      '后台进程启动核心函数'],
            ['popen() 实测',      $asyncDiag['popen_works'],     'Linux 备选进程启动方式'],
            ['proc_open() 实测',  $asyncDiag['proc_open_works'], 'Windows 备选进程启动方式'],
        ];
        if ($asyncDiag['worker_exists']) {
            $diagItems[] = ['Worker 语法检查', $asyncDiag['worker_syntax_ok'], $asyncDiag['worker_syntax_ok'] ? '通过' : $asyncDiag['worker_syntax_out']];
        } else {
            $diagItems[] = ['Worker 脚本存在', $asyncDiag['worker_exists'], 'api/write_chapter_worker.php 缺失'];
        }
        $diagItems[] = ['CLI pdo_mysql',   $asyncDiag['cli_pdo_mysql'],   $asyncDiag['cli_pdo_mysql'] ? 'CLI 已加载' : 'CLI 未加载（可能使用不同的php.ini）'];
        $diagItems[] = ['后台进程测试',    $asyncDiag['mini_exec_ok'],    $asyncDiag['mini_exec_ok'] ? '通过（nohup启动成功）' : '未通过（进程未能正常启动）'];
        if ($asyncDiag['php_binary_fixed']) {
            $diagItems[] = ['PHP_BINARY修正', true, $asyncDiag['php_binary_raw'] . ' → ' . $asyncDiag['php_binary']];
        }
        if ($asyncDiag['cli_ext_list']) {
            $diagItems[] = ['CLI 扩展列表', true, mb_strlen($asyncDiag['cli_ext_list']) > 200 ? mb_substr($asyncDiag['cli_ext_list'], 0, 200) . '…' : $asyncDiag['cli_ext_list']];
        }
        ?>
        <?php foreach ($diagItems as $item): list($name, $ok, $desc) = $item; ?>
        <div class="d-flex align-items-center gap-2 py-1" style="font-size:.78rem">
          <?php if ($ok): ?>
            <i class="bi bi-check-circle-fill text-success" style="font-size:.7rem"></i>
            <span style="color:#fff"><?= $name ?></span>
            <span style="font-size:.7rem;color:#fff">— <?= $desc ?></span>
          <?php else: ?>
            <i class="bi bi-x-circle-fill text-danger" style="font-size:.7rem"></i>
            <span class="fw-semibold" style="color:#fff"><?= $name ?></span>
            <span style="color:#fff;font-size:.7rem">— <?= $desc ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
        </div>
      </div>

      <?php if ($isSseOnly || $isUnstable): ?>
      <!-- SSE模式 / 不稳定警告 -->
      <div class="mb-3 p-3" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:8px">
        <div style="font-size:.82rem;color:#ef4444;font-weight:600;margin-bottom:4px">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          <?= $isSseOnly ? '异步写作不可用，仅 SSE 直连模式' : '异步写作可能不稳定' ?>
        </div>
        <div style="font-size:.75rem;color:#fff">
          <?php if ($isSseOnly): ?>
          写作将使用 SSE 直连模式（Server-Sent Events），该模式受 Nginx/Apache 超时限制（通常 60-120 秒），
          长篇章节写作可能被截断。建议在服务器上安装并启用 PHP CLI，确保 <code>exec()</code> 未被禁用。
          <?php else: ?>
          异步写作环境不完全满足要求，写作任务可能启动失败并自动回退到 SSE 直连模式。
          建议检查 PHP CLI 配置，确保 CLI 与 FPM 使用相同的扩展（尤其是 pdo_mysql）。
          <?php endif; ?>
        </div>
        <div style="font-size:.72rem;color:#fff;margin-top:4px">
          <i class="bi bi-check2-square me-1"></i>你仍可继续安装，但写作功能可能因超时而中断。
        </div>
      </div>
      <?php endif; ?>

      <div class="section-title"><span class="step-badge">1</span>数据库连接信息</div>

      <div class="row g-2 mb-2">
        <div class="col-8">
          <label class="form-label">数据库主机</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-hdd-network"></i></span>
            <input type="text" name="db_host" class="form-control"
                   value="<?= htmlspecialchars($host) ?>" placeholder="localhost" required>
          </div>
        </div>
        <div class="col-4">
          <label class="form-label">端口（可选）</label>
          <input type="text" class="form-control form-control-sm" placeholder="3306" disabled
                 style="opacity:.5" title="默认3306，如需修改请直接修改config.php">
        </div>
      </div>

      <div class="row g-2 mb-2">
        <div class="col-6">
          <label class="form-label">数据库用户名</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
            <input type="text" name="db_user" class="form-control"
                   value="<?= htmlspecialchars($user) ?>" required>
          </div>
        </div>
        <div class="col-6">
          <label class="form-label">数据库密码</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" name="db_pass" class="form-control" autocomplete="off">
          </div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">数据库名称</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-database"></i></span>
          <input type="text" name="db_name" class="form-control"
                 value="<?= htmlspecialchars($dbname) ?>" required>
        </div>
        <div class="form-text" style="color:var(--muted)">数据库不存在时将自动创建</div>
      </div>

      <div class="section-title mt-3"><span class="step-badge">2</span>设置后台管理员账号</div>

      <div class="mb-2">
        <label class="form-label">管理员用户名</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-person-circle"></i></span>
          <input type="text" name="admin_user" class="form-control"
                 value="<?= htmlspecialchars($adminUser) ?>"
                 placeholder="admin" required autocomplete="off">
        </div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label">密码 <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" name="admin_pass" id="adminPass" class="form-control"
                   placeholder="至少6位" required autocomplete="new-password">
          </div>
        </div>
        <div class="col-6">
          <label class="form-label">确认密码 <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input type="password" name="admin_pass2" id="adminPass2" class="form-control"
                   placeholder="再次输入" required autocomplete="new-password">
          </div>
        </div>
      </div>
      <div id="passError" class="text-danger small mb-2" style="display:none">
        <i class="bi bi-exclamation-circle me-1"></i>两次密码不一致
      </div>

      <!-- 将创建的数据库结构预览 
      <div class="mb-3 p-3" style="background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.15);border-radius:8px">
        <div style="font-size:.75rem;color:#6366f1;font-weight:600;margin-bottom:6px;">安装后将创建以下数据库结构 (v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?>)</div>
        <ul class="feature-list">
          <li>ai_models / novels / chapters / writing_logs（基础表）</li>
          <li>story_outlines — 全书故事大纲表</li>
          <li>volume_outlines — 卷大纲表（中层规划）</li>
          <li>chapter_synopses — 章节详细简介表</li>
          <li>arc_summaries — 弧段故事线摘要表（L2记忆）</li>
          <li><strong>novel_characters — 角色库（含功能模板/出场章节）</strong></li>
          <li><strong>novel_worldbuilding — 世界观库</strong></li>
          <li><strong>novel_plots — 情节库（含伏笔类型/回收章节）</strong></li>
          <li><strong>novel_style — 风格库（含四维向量/参考作者/高频词）</strong></li>
          <li><strong>novel_embeddings — 向量存储表（语义搜索）</strong></li>
          <li>character_cards — 人物状态卡片表（记忆引擎）</li>
          <li>character_card_history — 人物变更历史表</li>
          <li>foreshadowing_items — 伏笔独立表</li>
          <li>novel_state — 小说状态表</li>
          <li>novel_scene_templates — 场景模板使用记录（防套路化）</li>
          <li>memory_atoms — 原子记忆表</li>
          <li>book_analyses — 拆书分析表</li>
          <li><strong>chapter_versions — 章节版本快照表</strong></li>
          <li><strong>consistency_logs — 一致性检测日志表</strong></li>
          <li>system_settings — 系统设置表（含写作参数默认值）</li>
          <li>ai_models 扩展：thinking_enabled / can_embed / embedding_model_name / embedding_dim</li>
          <li><strong>agent_decision_logs — Agent决策日志表</strong></li>
          <li><strong>agent_action_logs — Agent动作日志表</strong></li>

          <li><strong>agent_directives — Agent自然语言指令表（指令注入机制）</strong></li>
          <li><strong>agent_directive_outcomes — Agent指令效果反馈表（决策闭环）</strong></li>
        <li><strong>iterative_settings — 迭代改进设置表</strong></li>
        <li><strong>novel_catchphrases — 金句调度表（v1.10.3）</strong></li>
        <li><strong>pid_states — PID控制器状态表（v1.10.3）</strong></li>
      </ul>
    </div>-->

    <button type="submit" class="btn btn-primary btn-install w-100 mt-1">
        <i class="bi bi-lightning-charge me-1"></i>一键安装
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
    var p1 = document.getElementById('adminPass');
    var p2 = document.getElementById('adminPass2');
    var err = document.getElementById('passError');
    if (!p1) return;
    function check(){ if(p2.value && p1.value !== p2.value){ err.style.display=''; } else { err.style.display='none'; } }
    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
    document.getElementById('installForm').addEventListener('submit', function(e){
        if (p1.value !== p2.value) { e.preventDefault(); err.style.display=''; }
    });
})();
</script>
<?php if ($success && $installModelToken !== ''): ?>
<script>
(function(){
    const installToken = <?= json_encode($installModelToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const form = document.getElementById('install-model-form');
    const apiKeyInput = document.getElementById('install-model-api-key');
    const submitButton = document.getElementById('install-model-submit');
    const defaultSelect = document.getElementById('install-default-model');
    const summary = document.getElementById('install-model-summary');
    const checkboxes = Array.from(document.querySelectorAll('.install-model-checkbox'));
    const testButtons = Array.from(document.querySelectorAll('.install-model-test-button'));
    const verifiedModels = new Set();
    let completed = false;
    let saving = false;

    function selectedModels() {
        return checkboxes
            .filter(function(box){ return box.checked && verifiedModels.has(box.value); })
            .map(function(box){ return box.value; });
    }

    function syncDefaultOptions() {
        Array.from(defaultSelect.options).forEach(function(option){
            option.disabled = !verifiedModels.has(option.value);
        });
        if (!verifiedModels.has(defaultSelect.value) && verifiedModels.size > 0) {
            defaultSelect.value = Array.from(verifiedModels)[0];
        }
        defaultSelect.disabled = completed || saving || verifiedModels.size === 0;
    }

    function setModelStatus(model, ok, message) {
        const el = document.querySelector('[data-model-status="' + CSS.escape(model) + '"]');
        if (!el) return;
        if (!message) {
            el.className = 'install-model-status text-muted';
            el.textContent = '';
            return;
        }
        el.className = 'install-model-status ' + (ok === true ? 'text-success' : (ok === false ? 'text-danger' : 'text-muted'));
        el.textContent = (ok === true ? '✓ ' : (ok === false ? '✗ ' : '… ')) + message;
    }

    function showSummary(type, message) {
        summary.style.display = '';
        summary.className = 'small mb-2 text-' + type;
        summary.textContent = message;
    }

    function setSavingControlsDisabled(disabled) {
        apiKeyInput.disabled = disabled;
        testButtons.forEach(function(button){ button.disabled = disabled; });
        checkboxes.forEach(function(box){ box.disabled = disabled || !verifiedModels.has(box.value); });
        submitButton.disabled = disabled;
        syncDefaultOptions();
    }

    function resetVerifiedModels() {
        verifiedModels.clear();
        checkboxes.forEach(function(box){
            box.checked = false;
            box.disabled = true;
            setModelStatus(box.value, undefined, '');
        });
        summary.style.display = 'none';
        syncDefaultOptions();
    }

    async function requestInstallModels(body) {
        const response = await fetch('api/index.php?route=install_models', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        const responseText = await response.text();
        if (!responseText) throw new Error('服务器返回空响应');
        const payload = JSON.parse(responseText);
        if (!payload.ok) throw new Error(payload.msg || '模型操作失败');
        return payload;
    }

    async function testInstallModel(model, button) {
        if (saving || completed) return;
        const apiKey = apiKeyInput.value.trim();
        if (!apiKey) {
            showSummary('danger', '请先填写 API Key。');
            apiKeyInput.focus();
            return;
        }

        button.disabled = true;
        button.textContent = '测试中...';
        setModelStatus(model, null, '正在测试连接');

        try {
            const payload = await requestInstallModels({
                action: 'test',
                token: installToken,
                api_key: apiKey,
                model: model
            });
            if (apiKeyInput.value.trim() !== apiKey) return;
            const result = payload.data && payload.data.result ? payload.data.result : {};
            const checkbox = checkboxes.find(function(box){ return box.value === model; });
            if (result.ok === true) {
                verifiedModels.add(model);
                checkbox.disabled = false;
                checkbox.checked = true;
                setModelStatus(model, true, result.message || '连接成功');
                showSummary('success', '模型测试成功，已自动勾选。');
            } else {
                verifiedModels.delete(model);
                checkbox.checked = false;
                checkbox.disabled = true;
                setModelStatus(model, false, result.message || '连接失败');
                showSummary('danger', '模型连接失败，请检查密钥或模型可用性。');
            }
        } catch (error) {
            if (apiKeyInput.value.trim() !== apiKey) return;
            verifiedModels.delete(model);
            const checkbox = checkboxes.find(function(box){ return box.value === model; });
            checkbox.checked = false;
            checkbox.disabled = true;
            setModelStatus(model, false, error.message);
            showSummary('danger', '连接测试失败：' + error.message);
        } finally {
            button.disabled = completed;
            button.textContent = '测试';
            syncDefaultOptions();
        }
    }

    async function saveVerifiedModels(event) {
        event.preventDefault();
        if (saving || completed) return;

        const apiKey = apiKeyInput.value.trim();
        const models = selectedModels();
        if (!apiKey) {
            showSummary('danger', '请先填写 API Key。');
            return;
        }
        if (models.length === 0) {
            showSummary('danger', '请先逐个测试并勾选至少一个模型。');
            return;
        }

        saving = true;
        setSavingControlsDisabled(true);
        showSummary('muted', '正在保存已测试成功的模型...');
        try {
            const payload = await requestInstallModels({
                action: 'save',
                token: installToken,
                api_key: apiKey,
                models: models,
                default_model: defaultSelect.value
            });
            const data = payload.data || {};
            completed = true;
            apiKeyInput.value = '';
            showSummary('success', '已保存 ' + data.saved_models.length + ' 个模型，默认模型：' + data.default_model + '。');
            submitButton.innerHTML = '<i class="bi bi-check-circle me-1"></i>模型接入完成';
            document.getElementById('install-login-link').innerHTML = '<i class="bi bi-box-arrow-in-right me-1"></i>前往登录 →';
        } catch (error) {
            showSummary('danger', '模型保存失败：' + error.message);
        } finally {
            saving = false;
            if (!completed) setSavingControlsDisabled(false);
        }
    }

    checkboxes.forEach(function(box){ box.addEventListener('change', syncDefaultOptions); });
    testButtons.forEach(function(button){
        button.addEventListener('click', function(){
            testInstallModel(button.dataset.testModel, button);
        });
    });
    apiKeyInput.addEventListener('input', resetVerifiedModels);
    form.addEventListener('submit', saveVerifiedModels);
    syncDefaultOptions();
})();
</script>
<?php endif; ?>
</body>
</html>
