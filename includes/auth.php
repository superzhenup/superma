<?php
defined('APP_LOADED') or die('Direct access denied.');

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
    // 设置 session cookie 安全属性
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * 检测系统是否已完成安装（install.lock 存在即视为已安装）
 * BASE_PATH 由 config.php 定义，指向系统根目录。
 */
function isInstalled(): bool {
    $lockFile = defined('BASE_PATH')
        ? BASE_PATH . '/install.lock'
        : dirname(__DIR__) . '/install.lock';
    return file_exists($lockFile);
}

/**
 * 页面级鉴权（适用于根目录 .php 页面）
 * 优先级：未安装 → install.php；未登录 → login.php
 */
function requireLogin(): void {
    if (!isInstalled()) {
        header('Location: install.php');
        exit;
    }
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * API 级鉴权（适用于 api/ 目录接口，返回 JSON 而非跳转）
 *
 * 行为：
 *  1. 未登录 → 返回 JSON {ok:false, msg:...} 并退出
 *  2. 对非 GET/HEAD 请求（POST/PUT/DELETE/PATCH）自动强制 CSRF 校验
 *     —— 前端必须通过 X-CSRF-Token 请求头携带 token，否则拒绝
 *
 * 如需显式跳过 CSRF（极少数场景，例如第三方回调）：
 *   requireLoginApi(false);
 */
function requireLoginApi(bool $enforceCsrf = true): void {
    if (empty($_SESSION['logged_in'])) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $msg = isInstalled() ? '未登录，请先登录' : '系统尚未安装';
        echo json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 非 GET/HEAD 请求必须通过 CSRF 校验（防 CSRF 攻击）
    if ($enforceCsrf) {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            csrf_verify_api();
        }
    }
}

/**
 * 验证账号密码
 */
/**
 * Reject unsupported HTTP methods with a JSON 405 response.
 *
 * @param string|string[] $allowed
 */
function requireHttpMethod($allowed): void {
    $allowedMethods = array_map('strtoupper', (array)$allowed);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, $allowedMethods, true)) {
        return;
    }

    if (ob_get_level()) ob_end_clean();
    http_response_code(405);
    header('Allow: ' . implode(', ', $allowedMethods));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'msg' => 'Method not allowed',
        'error' => 'method_not_allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function doLogin(string $user, string $pass): bool {
    if (!defined('ADMIN_USER') || !defined('ADMIN_PASS') || ADMIN_USER === '') {
        return false;
    }
    return $user === ADMIN_USER && password_verify($pass, ADMIN_PASS);
}

/**
 * 注销登录
 */
function doLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 86400, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * 生成 CSRF Token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证 CSRF Token（页面级，失败跳转）
 */
function csrf_verify(): void {
    $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        die('CSRF 验证失败，请刷新页面重试');
    }
}

/**
 * 验证 CSRF Token（API 级，返回 JSON 错误）
 */
function csrf_verify_api(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token)) {
        $token = $_POST['_token'] ?? '';
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
        if (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'msg'   => 'CSRF 验证失败，请刷新页面',
            'error' => 'csrf_invalid',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================
// 登录限速（服务端持久化，按客户端 IP）
//
// 旧实现把失败计数存在 $_SESSION 中——攻击者只要不带 session cookie
// 每次请求就会拿到全新计数器，暴力破解防护形同虚设。
// 这里改为按 REMOTE_ADDR 写文件持久化（不可通过丢弃 cookie / 伪造头绕过）。
// ============================================================

/**
 * 取连接方真实 IP。
 * 故意只用 REMOTE_ADDR——X-Forwarded-For 等头可被客户端伪造，
 * 一旦用它做限速键，攻击者改头即可绕过。
 */
function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** 限速数据目录（不存在则创建，并放置 deny-all .htaccess） */
function loginThrottleDir(): string {
    $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/storage/login_throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

/** 单个 IP 的限速状态文件路径 */
function loginThrottleFile(string $ip): string {
    return loginThrottleDir() . '/' . sha1($ip) . '.json';
}

/**
 * 读取某 IP 的限速状态。
 * @return array{attempts:int,last_attempt:int,lockout_until:int}
 */
function loginThrottleState(string $ip): array {
    $file = loginThrottleFile($ip);
    $default = ['attempts' => 0, 'last_attempt' => 0, 'lockout_until' => 0];
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return $default;
    return [
        'attempts'      => (int)($data['attempts'] ?? 0),
        'last_attempt'  => (int)($data['last_attempt'] ?? 0),
        'lockout_until' => (int)($data['lockout_until'] ?? 0),
    ];
}

/** 写入限速状态 */
function loginThrottleSave(string $ip, array $state): void {
    $file = loginThrottleFile($ip);
    @file_put_contents(
        $file,
        json_encode($state, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    @chmod($file, 0600);
}

/**
 * 记录一次登录失败，返回更新后的状态。
 * 达到 10 次锁定 15 分钟。
 */
function loginThrottleRecordFail(string $ip): array {
    $s = loginThrottleState($ip);
    $s['attempts']     = $s['attempts'] + 1;
    $s['last_attempt'] = time();
    if ($s['attempts'] >= 10) {
        $s['lockout_until'] = time() + 15 * 60;
    }
    loginThrottleSave($ip, $s);
    return $s;
}

/** 登录成功后清除该 IP 的限速记录 */
function loginThrottleReset(string $ip): void {
    $file = loginThrottleFile($ip);
    if (is_file($file)) @unlink($file);
}
