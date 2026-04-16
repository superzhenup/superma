<?php
/**
 * 系统安装向导 — 一键安装数据库并设置管理员账号
 * v3：新增 pending_foreshadowing、story_momentum 字段
 */

define('LOCK_FILE', __DIR__ . '/install.lock');

// 安全加固：已安装后访问此页面直接返回 404。
// 原因：install.php 暴露数据库配置格式和管理员账号结构，
// 攻击者可借此探测系统安装状态。安装完成后应彻底隐藏入口。
// 如需重新安装，请先手动删除根目录下的 install.lock 文件。
if (file_exists(LOCK_FILE)) {
    http_response_code(404);
    exit('Not found.');
}

$alreadyInstalled = false;

$host       = 'localhost';
$user       = 'root';
$pass       = '';
$dbname     = 'ai_novel';
$adminUser  = 'admin';
$adminPass  = '';
$adminPass2 = '';
$error      = '';
$success    = '';

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host       = trim($_POST['db_host']     ?? 'localhost');
    $user       = trim($_POST['db_user']     ?? 'root');
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
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbname`");

            // ================================================================
            // 建表 SQL（v3 完整版，含所有优化字段）
            // ================================================================
            $statements = [

                // AI 模型配置表
                "CREATE TABLE IF NOT EXISTS `ai_models` (
                    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name`        VARCHAR(100)  NOT NULL COMMENT '模型名称',
                    `api_url`     VARCHAR(500)  NOT NULL COMMENT 'API地址',
                    `api_key`     VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'API密钥',
                    `model_name`  VARCHAR(200)  NOT NULL COMMENT '模型标识符',
                    `max_tokens`  INT           NOT NULL DEFAULT 4096,
                    `temperature` FLOAT         NOT NULL DEFAULT 0.8,
                    `is_default`  TINYINT(1)    NOT NULL DEFAULT 0,
                    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 小说主表（v3 完整字段）
                "CREATE TABLE IF NOT EXISTS `novels` (
                    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `title`                 VARCHAR(200) NOT NULL COMMENT '书名',
                    `genre`                 VARCHAR(100) NOT NULL DEFAULT '' COMMENT '类型',
                    `writing_style`         VARCHAR(200) NOT NULL DEFAULT '' COMMENT '写作风格',
                    `protagonist_name`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '主角姓名',
                    `protagonist_info`      TEXT COMMENT '主角信息',
                    `plot_settings`         TEXT COMMENT '情节设定',
                    `world_settings`        TEXT COMMENT '世界设定',
                    `extra_settings`        TEXT COMMENT '其他设定',
                    `target_chapters`       INT  NOT NULL DEFAULT 100 COMMENT '目标总章数',
                    `chapter_words`         INT  NOT NULL DEFAULT 2000 COMMENT '每章目标字数',
                    `model_id`              INT UNSIGNED DEFAULT NULL COMMENT '使用的模型',
                    `has_story_outline`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否已生成全书故事大纲',
                    `status`                ENUM('draft','writing','paused','completed') NOT NULL DEFAULT 'draft',
                    `current_chapter`       INT  NOT NULL DEFAULT 0 COMMENT '已写章数',
                    `total_words`           INT  NOT NULL DEFAULT 0 COMMENT '总字数',
                    `cancel_flag`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '写作取消标志',
                    `cover_color`           VARCHAR(7)   NOT NULL DEFAULT '#6366f1',
                    `character_states`      JSON DEFAULT NULL COMMENT '人物当前状态卡片，防止职务混乱',
                    `key_events`            JSON DEFAULT NULL COMMENT '全书关键事件日志，防止情节重复',
                    `pending_foreshadowing` JSON DEFAULT NULL COMMENT '待回收伏笔列表：[{\"chapter\":1,\"desc\":\"...\",\"deadline\":20}]',
                    `story_momentum`        VARCHAR(200) NOT NULL DEFAULT '' COMMENT '当前故事势能/悬念状态，供大纲生成参考',
                    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 章节表（v3 完整字段）
                "CREATE TABLE IF NOT EXISTS `chapters` (
                    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`        INT UNSIGNED NOT NULL,
                    `chapter_number`  INT          NOT NULL COMMENT '章节序号',
                    `title`           VARCHAR(300) NOT NULL DEFAULT '',
                    `outline`         TEXT COMMENT '章节大纲',
                    `key_points`      TEXT COMMENT '关键情节点(JSON)',
                    `hook`            VARCHAR(500) NOT NULL DEFAULT '' COMMENT '结尾钩子',
                    `synopsis_id`     INT UNSIGNED DEFAULT NULL COMMENT '章节简介ID',
                    `content`         LONGTEXT COMMENT '章节正文',
                    `words`           INT  NOT NULL DEFAULT 0,
                    `status`          ENUM('pending','outlined','writing','completed') NOT NULL DEFAULT 'pending',
                    `chapter_summary` TEXT COMMENT 'AI生成的章节摘要，供续写参考',
                    `used_tropes`     TEXT COMMENT '本章已用意象/场景(JSON)，近5章规避',
                    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_novel_chapter` (`novel_id`, `chapter_number`),
                    KEY `idx_novel_status`  (`novel_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 写作日志表
                "CREATE TABLE IF NOT EXISTS `writing_logs` (
                    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`   INT UNSIGNED NOT NULL,
                    `chapter_id` INT UNSIGNED DEFAULT NULL,
                    `action`     VARCHAR(100) NOT NULL,
                    `message`    TEXT,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_novel` (`novel_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 全书故事大纲表（v2+）
                "CREATE TABLE IF NOT EXISTS `story_outlines` (
                    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`              INT UNSIGNED NOT NULL UNIQUE,
                    `story_arc`             TEXT COMMENT '故事主线发展脉络',
                    `act_division`          JSON COMMENT '三幕划分',
                    `major_turning_points`  JSON COMMENT '重大转折点',
                    `character_arcs`        JSON COMMENT '人物成长轨迹',
                    `world_evolution`       TEXT COMMENT '世界观演变',
                    `recurring_motifs`      JSON COMMENT '全书重复意象',
                    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (`novel_id`) REFERENCES `novels`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 章节详细简介表（v2+）
                "CREATE TABLE IF NOT EXISTS `chapter_synopses` (
                    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`        INT UNSIGNED NOT NULL,
                    `chapter_number`  INT          NOT NULL,
                    `synopsis`        TEXT COMMENT '章节简介200-300字',
                    `scene_breakdown` JSON COMMENT '场景分解',
                    `dialogue_beats`  JSON COMMENT '对话要点',
                    `sensory_details` JSON COMMENT '感官细节',
                    `pacing`          VARCHAR(20)  COMMENT '节奏：快/中/慢',
                    `cliffhanger`     TEXT COMMENT '结尾悬念',
                    `foreshadowing`   JSON COMMENT '本章埋下的伏笔',
                    `callbacks`       JSON COMMENT '呼应前文的点',
                    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_chapter` (`novel_id`, `chapter_number`),
                    FOREIGN KEY (`novel_id`) REFERENCES `novels`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 弧段摘要表（三层记忆架构第二层，每10章压缩一次）
                "CREATE TABLE IF NOT EXISTS `arc_summaries` (
                    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `novel_id`     INT UNSIGNED NOT NULL,
                    `arc_index`    INT NOT NULL COMMENT '弧段编号，从1开始',
                    `chapter_from` INT NOT NULL COMMENT '起始章节',
                    `chapter_to`   INT NOT NULL COMMENT '结束章节',
                    `summary`      TEXT COMMENT '200字弧段故事线摘要',
                    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_arc` (`novel_id`, `arc_index`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                // 索引（MySQL 不支持 CREATE INDEX IF NOT EXISTS，改用 ALTER TABLE 忽略重复）
                "ALTER TABLE `chapters` ADD INDEX `idx_chapter_synopsis` (`novel_id`, `chapter_number`, `synopsis_id`)",
                "ALTER TABLE `novels`   ADD INDEX `idx_story_outline`    (`id`, `has_story_outline`)",
            ];

            foreach ($statements as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    // 忽略"索引已存在"错误(1061)，其余错误继续抛出
                    if ((int)($e->errorInfo[1] ?? 0) !== 1061) throw $e;
                }
            }

            // 生成密码散列
            $passHash = password_hash($adminPass, PASSWORD_BCRYPT);
            $esc = fn(string $s) => addslashes($s);

            // 写入 config.php
            $configContent = <<<PHP
<?php
// ============================================================
// 数据库配置
// ============================================================
define('DB_HOST',    '{$esc($host)}');
define('DB_NAME',    '{$esc($dbname)}');
define('DB_USER',    '{$esc($user)}');
define('DB_PASS',    '{$esc($pass)}');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 后台账号（由安装向导写入，请勿手动修改密码明文）
// ============================================================
define('ADMIN_USER', '{$esc($adminUser)}');
define('ADMIN_PASS', '{$esc($passHash)}');

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

defined('APP_LOADED') or define('APP_LOADED', true);
PHP;

            file_put_contents(__DIR__ . '/config.php', $configContent);
            file_put_contents(LOCK_FILE,
                "Installed at: " . date('Y-m-d H:i:s') . "\n" .
                "DB Host: $host\n" .
                "DB Name: $dbname\n" .
                "Admin: $adminUser\n" .
                "Version: v3\n"
            );

            $success = "安装成功！管理员账号：<strong>" . htmlspecialchars($adminUser) . "</strong>，数据库已就绪。";

        } catch (PDOException $e) {
            $error = '数据库连接失败：' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 - AI小说创作系统</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<script>(function(){ var t=localStorage.getItem('novel-theme')||'dark'; document.documentElement.setAttribute('data-theme',t); })();</script>
<style>
:root {
    --bg-body:  #0f0f1a;
    --bg-card:  #1a1a2e;
    --border:   #2d2d4e;
    --text:     #e0e0f0;
    --muted:    #8888aa;
    --input-bg: #12122a;
}
[data-theme="light"] {
    --bg-body:  #f0f2f5;
    --bg-card:  #ffffff;
    --border:   #d0d0e0;
    --text:     #1a1a2e;
    --muted:    #666688;
    --input-bg: #f8f8ff;
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
</style>
</head>
<body>
<div class="container py-4" style="max-width:560px">
  <div class="card-install p-4 p-md-5 shadow-lg">
    <div class="text-center mb-4">
      <div class="logo">✦ AI小说创作系统</div>
      <p class="text-muted mt-1 mb-0" style="font-size:.8rem">安装向导 · v3</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="bi bi-check-circle me-1"></i><?= $success ?>
    </div>
    <div class="mb-3 p-3" style="background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.2);border-radius:8px">
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:6px;font-weight:600;">已创建数据库结构 (v3)</div>
      <ul class="feature-list">
        <li>ai_models · novels · chapters · writing_logs</li>
        <li>story_outlines — 全书故事大纲</li>
        <li>chapter_synopses — 章节详细简介</li>
        <li>arc_summaries — 弧段故事线摘要（三层记忆）</li>
        <li>novels.pending_foreshadowing — 伏笔追踪</li>
        <li>novels.story_momentum — 故事势能</li>
        <li>novels.character_states — 人物状态卡片</li>
        <li>novels.key_events — 全书事件日志</li>
      </ul>
    </div>
    <a href="login.php" class="btn btn-primary btn-install w-100">
      <i class="bi bi-box-arrow-in-right me-1"></i>前往登录 →
    </a>

    <?php else: ?>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small">
      <i class="bi bi-exclamation-triangle me-1"></i><?= $error ?>
    </div>
    <?php endif; ?>

    <form method="post" id="installForm">
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

      <!-- 将创建的数据库结构预览 -->
      <div class="mb-3 p-3" style="background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.15);border-radius:8px">
        <div style="font-size:.75rem;color:#6366f1;font-weight:600;margin-bottom:6px;">安装后将创建以下数据库结构</div>
        <ul class="feature-list">
          <li>ai_models / novels / chapters / writing_logs（基础表）</li>
          <li>story_outlines — 全书故事大纲表</li>
          <li>chapter_synopses — 章节详细简介表</li>
          <li>arc_summaries — 弧段故事线摘要表（三层记忆架构）</li>
          <li>novels.pending_foreshadowing — 伏笔追踪字段</li>
          <li>novels.story_momentum — 故事势能字段</li>
          <li>novels.character_states — 人物状态卡片字段</li>
          <li>novels.key_events — 全书事件日志字段</li>
        </ul>
      </div>

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
</body>
</html>
