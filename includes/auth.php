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
