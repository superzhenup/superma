<?php
/**
 * 登录页面
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';  // 会话启动 + 登录限速辅助函数

// 已登录直接跳首页
if (!empty($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

// 未安装（install.lock 不存在）则跳安装向导
if (!file_exists(__DIR__ . '/install.lock')) {
    header('Location: install.php');
    exit;
}

$error = '';

// 暴力破解保护：按客户端 IP 服务端持久化（不可通过丢弃 cookie 绕过）
$clientIp = clientIp();
$throttle = loginThrottleState($clientIp);

// 配置损坏时不要再显示误导性的“用户名或密码错误”，也不要累计锁定次数。
// 旧版安装器可能因正则 replacement 解析 bcrypt 中的 "$2y$" 而写坏 ADMIN_PASS。
if (!adminCredentialsConfigured()) {
    $error = '管理员登录凭据未正确生成，请修复 config.php 中的 ADMIN_PASS 或重新安装。';
} elseif ($throttle['lockout_until'] > time()) {
    // 检查是否被锁定
    $remaining = $throttle['lockout_until'] - time();
    $error = '登录尝试次数过多，请 ' . ceil($remaining / 60) . ' 分钟后再试。';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify(); // 2026-06-12 R2: 登录 CSRF 防护
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';

        // 计算延迟（每次失败增加2秒，最多30秒）
        $delay = min(30, $throttle['attempts'] * 2);
        if ($delay > 0 && time() - $throttle['last_attempt'] < $delay) {
            $error = '请等待 ' . $delay . ' 秒后再试。';
        } else {
            if (hash_equals(ADMIN_USER, $user) && doLogin($user, $pass)) {
                // 登录成功，清除该 IP 限速记录
                loginThrottleReset($clientIp);
                $_SESSION['logged_in']  = true;
                $_SESSION['username']   = $user;
                $_SESSION['user_id']    = 1;
                $_SESSION['created_at'] = time();
                $_SESSION['last_seen_at'] = time();
                $_SESSION['rotated_at'] = time();
                session_regenerate_id(true);
                // 审计修复 P2-9（2026-07-12）：登录后轮换 CSRF token
                // 防止登录前的 CSRF token 跨特权变更边界复用
                unset($_SESSION['csrf_token']);
                header('Location: index.php');
                exit;
            } else {
                // 登录失败，持久化记录一次
                $throttle = loginThrottleRecordFail($clientIp);
                if ($throttle['lockout_until'] > time()) {
                    $error = '登录尝试次数过多，账户已被锁定15分钟。';
                } else {
                    $error = '用户名或密码错误，请重试。';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 - <?= defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'AI小说创作系统' ?></title>
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<script>(function(){ var t='dark'; try{ t=localStorage.getItem('novel-theme')||'dark'; }catch(e){} document.documentElement.setAttribute('data-theme',t); })();</script>
<style>
body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-body, #0f0f1a);
}
.login-wrap {
    width: 100%;
    max-width: 420px;
    padding: 1rem;
}
.login-card {
    background: var(--bg-card, #1a1a2e);
    border: 1px solid var(--border-color, #2d2d4e);
    border-radius: 16px;
    padding: 2.5rem;
    box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.login-logo {
    font-size: 2rem;
    font-weight: 700;
    background: linear-gradient(135deg, #6366f1, #a78bfa);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.login-card .form-control {
    background: var(--bg-input, #12122a);
    border-color: var(--border-color, #2d2d4e);
    color: var(--text-primary, #e0e0f0);
    padding: .65rem 1rem;
}
.login-card .form-control:focus {
    background: var(--bg-input, #12122a);
    border-color: #6366f1;
    color: var(--text-primary, #e0e0f0);
    box-shadow: 0 0 0 .2rem rgba(99,102,241,.25);
}
.login-card .form-label {
    color: var(--text-secondary, #a0a0c0);
    font-size: .875rem;
}
.btn-login {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border: none;
    padding: .7rem;
    font-weight: 600;
    letter-spacing: .03em;
    transition: opacity .2s;
}
.btn-login:hover { opacity: .9; }
.input-group-text {
    background: var(--bg-input, #12122a);
    border-color: var(--border-color, #2d2d4e);
    color: var(--text-secondary, #a0a0c0);
}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="text-center mb-4">
      <div class="login-logo mb-1">✦ AI小说创作</div>
      <p class="text-muted small mb-0">请登录以继续</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small">
      <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="_token" value="<?= csrf_token() ?>">
      <div class="mb-3">
        <label class="form-label">用户名</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" name="username" class="form-control"
                 placeholder="请输入用户名"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 autocomplete="username" required autofocus>
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label">密码</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" name="password" class="form-control"
                 placeholder="请输入密码"
                 autocomplete="current-password" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-login w-100">
        <i class="bi bi-box-arrow-in-right me-1"></i>登录
      </button>
    </form>
  </div>
</div>
</body>
</html>
