<?php
defined('APP_LOADED') or die('Direct access denied.');

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
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
 */
function requireLoginApi(): void {
    if (empty($_SESSION['logged_in'])) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $msg = isInstalled() ? '未登录，请先登录' : '系统尚未安装';
        echo json_encode(['ok' => false, 'msg' => $msg]);
        exit;
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
