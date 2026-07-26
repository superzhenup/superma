<?php
defined('APP_LOADED') or die('Direct access denied.');

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
    // 设置 session cookie 安全属性（S2：兼容反向代理协议检测）
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (defined('FORCE_HTTPS') && FORCE_HTTPS === true);
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

// 审计优化 P3-9（2026-06-16）：加载 CLI 上下文标记类
// 用于 requireLoginApi / enforceSessionLifecycle 短路 CLI worker 的会话检查
require_once __DIR__ . '/CliContext.php';

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
 *
 * 审计修复 C-1（2026-06-22）：标注 @return never，提示调用方函数在未登录/未安装时会 exit，
 * 不会返回到调用点。PHP 8.1+ 可用 never 类型，但为兼容性仍用 void + 注释说明。
 * @return never
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
    enforceSessionLifecycle(false);
}

function enforceSessionLifecycle(bool $api): void {
    // 审计优化 P3-9（2026-06-16）：CLI worker 上下文跳过会话生命周期检查
    if (CliContext::isActive()) return;

    $now = time();
    $idleTimeout = 30 * 60;
    $absoluteTimeout = 12 * 60 * 60;
    $rotateInterval = 15 * 60;

    $createdAt = (int)($_SESSION['created_at'] ?? $now);
    $lastSeen = (int)($_SESSION['last_seen_at'] ?? $now);
    $_SESSION['created_at'] = $createdAt;

    if (($now - $lastSeen) > $idleTimeout || ($now - $createdAt) > $absoluteTimeout) {
        doLogout();
        if ($api) {
            if (ob_get_level()) ob_end_clean();
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => '登录已过期，请重新登录', 'error' => 'session_expired'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Location: login.php');
        }
        exit;
    }

    if (($now - (int)($_SESSION['rotated_at'] ?? $createdAt)) > $rotateInterval) {
        // [修复] 会话 ID 轮换竞态：原 session_regenerate_id(true) 会【立即删除旧会话文件】。
        // 向导等页面有高频自动保存 + 提交并发请求（PHP 按会话文件加锁串行化），排在
        // 轮换之后、仍持旧 cookie 的在途请求拿到锁时旧文件已被删除 → 读到空会话 →
        // 误判"未登录，请先登录"（草稿保存失败）。改为 false：保留旧会话数据，旧 cookie
        // 的在途请求仍能命中；浏览器后续改用新 cookie。旧 ID 在 gc 前短暂有效——本应用
        // 已有 idle(30min)+absolute(12h) 双超时，周期轮换仅作纵深防御，此权衡可接受。
        session_regenerate_id(false);
        $_SESSION['rotated_at'] = $now;
    }
    $_SESSION['last_seen_at'] = $now;
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
 *
 * 审计修复 C-1（2026-06-22）：标注 @return never，函数在未登录/CSRF 失败时会 exit。
 * @return never
 */
function requireLoginApi(bool $enforceCsrf = true): void {
    // 审计优化 P3-9（2026-06-16）：CLI worker 上下文跳过登录校验
    // CLI worker 由 write_start.php 在已验证登录后启动，无需重复校验
    if (CliContext::isActive()) return;

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
    enforceSessionLifecycle(true);
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

/** 管理员账号和 password_hash 散列是否已正确写入配置。 */
function adminCredentialsConfigured(): bool {
    if (!defined('ADMIN_USER') || !defined('ADMIN_PASS') || ADMIN_USER === '') {
        return false;
    }

    $hash = (string)ADMIN_PASS;
    return $hash !== ''
        && strlen($hash) >= 50
        && preg_match('/^\$(2[axy]|argon2(i|id)?)\$/', $hash) === 1;
}

function doLogin(string $user, string $pass): bool {
    // 安全加固（S1）：明确拒绝空密码或非 password_hash 格式的 ADMIN_PASS。
    // 防止安装脚本未正确写入 hash 时，攻击者用任意密码登录。
    if (!adminCredentialsConfigured()) {
        error_log('doLogin: ADMIN_PASS 未正确初始化（非 password_hash 散列），登录拒绝');
        return false;
    }
    if ($pass === '') {
        return false;
    }
    $hash = (string)ADMIN_PASS;
    return hash_equals(ADMIN_USER, $user) && password_verify($pass, $hash);
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
 *
 * 审计修复 C-1（2026-06-22）：标注 @return never，CSRF 校验失败时会 exit。
 * @return never
 */
function csrf_verify(): void {
    $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        // L-6 修复：返回 JSON 403 而非 die() 纯文本，与 csrf_verify_api() 一致
        if (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'msg' => 'CSRF 验证失败，请刷新页面重试',
            'error' => 'csrf_invalid',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * 验证 CSRF Token（API 级，返回 JSON 错误）
 *
 * 审计修复 C-1（2026-06-22）：标注 @return never，CSRF 校验失败时会 exit。
 * @return never
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
    $file = loginThrottleFile($ip);

    // M-6 修复（2026-07-20）：原无锁回退路径存在 TOCTOU 竞态——
    // loginThrottleState() 无锁读取 → 内存 +1 → loginThrottleSave()
    // 带锁写入，并发时丢失计数。改为 retry 方案：最多重试 3 次获取
    // 文件锁，每次间隔 50ms，仍失败才走无锁回退（此时写入仍带 LOCK_EX，
    // 已尽量降低丢失概率）。
    $maxRetries = 3;
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        $fp = @fopen($file, 'c+');
        if ($fp !== false && flock($fp, LOCK_EX | LOCK_NB)) {
            // 正常带锁路径
            rewind($fp);
            $data = json_decode((string)stream_get_contents($fp), true);
            $s = is_array($data) ? [
                'attempts'      => (int)($data['attempts'] ?? 0),
                'last_attempt'  => (int)($data['last_attempt'] ?? 0),
                'lockout_until' => (int)($data['lockout_until'] ?? 0),
            ] : ['attempts' => 0, 'last_attempt' => 0, 'lockout_until' => 0];

            $s['attempts']     = $s['attempts'] + 1;
            $s['last_attempt'] = $now = time();

            if ($now > $s['lockout_until'] && $s['lockout_until'] > 0) {
                $s['attempts'] = 1;
                $s['lockout_until'] = 0;
            }

            if ($s['attempts'] >= 10) {
                $s['lockout_until'] = $now + 15 * 60;
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            @chmod($file, 0600);
            return $s;
        }
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        if ($retry < $maxRetries - 1) {
            usleep(50000); // 50ms 等待后重试
        }
    }

    // 重试耗尽，走无锁回退（仍尽量降低并发窗口）
    $s = loginThrottleState($ip);
    $s['attempts']++;
    $s['last_attempt'] = $now = time();
    if ($now > $s['lockout_until'] && $s['lockout_until'] > 0) {
        $s['attempts'] = 1;
        $s['lockout_until'] = 0;
    }
    if ($s['attempts'] >= 10) {
        $s['lockout_until'] = $now + 15 * 60;
    }
    loginThrottleSave($ip, $s);
    return $s;
}

/** 登录成功后清除该 IP 的限速记录 */
function loginThrottleReset(string $ip): void {
    $file = loginThrottleFile($ip);
    if (is_file($file)) @unlink($file);
}

/**
 * 强制校验小说归属权，防止越权访问（多用户加固）
 *
 * 设计说明：本系统为单用户系统，同一时刻仅有一个管理员账户，
 * 因此 API 端点无需逐一调用 checkNovelOwnership / checkChapterOwnership
 * 做归属校验。这两个函数保留供未来多用户扩展使用。
 * 当前所有已登录用户即管理员，不存在越权访问风险。
 *
 * @param int $novelId 小说ID
 * @param int $userId 当前登录用户ID
 */
function checkNovelOwnership(int $novelId, int $userId): void {
    $novel = DB::fetch('SELECT id, user_id FROM novels WHERE id = ?', [$novelId]);
    if (!$novel) {
        http_response_code(404);
        jsonResponse(false, null, '小说不存在', 'novel_not_found');
    }
    // 审计修复 H2（2026-06-12）：拒绝 NULL user_id 旁路。
    // 旧逻辑用 `isset(...) && ... !== null` 在 NULL 时静默放行，
    // 导致历史/导入数据被任意已登录用户读写。
    //
    // 处理策略：
    //   1) session 异常（userId<=0）→ 拒绝（保障调用方正确性）
    //   2) novel.user_id === null → 自动认领（向后兼容已有数据）
    //   3) novel.user_id !== userId → 拒绝
    if ($userId <= 0) {
        http_response_code(403);
        jsonResponse(false, null, '会话异常，请重新登录', 'forbidden');
    }
    if ($novel['user_id'] === null) {
        // 审计修复 H-NEW-1（2026-06-15）：自动认领后验证 UPDATE 确实影响了行，
        // 防止并发请求竞态——两个请求同时通过 user_id IS NULL 检查。
        // MySQL 行锁保证只有一方能成功 update，另一方 affected=0 须重新验证。
        $affected = DB::execute(
            'UPDATE novels SET user_id=? WHERE id=? AND user_id IS NULL',
            [$userId, $novelId]
        );
        if ($affected === 0) {
            $recheck = DB::fetch('SELECT user_id FROM novels WHERE id=?', [$novelId]);
            if ((int)($recheck['user_id'] ?? 0) !== $userId) {
                http_response_code(403);
                jsonResponse(false, null, '无权操作此小说', 'forbidden');
            }
        }
    } elseif ((int)$novel['user_id'] !== $userId) {
        http_response_code(403);
        jsonResponse(false, null, '无权操作此小说', 'forbidden');
    }
}

/**
 * 判断当前会话是否为管理员
 *
 * 设计说明：本系统为单用户系统，login.php 登录成功后固定写入 user_id=1，
 * 即"已登录"等价于"管理员"。此函数为 cover_actions.php / hot_novels.php
 * 等需要管理员权限的接口提供统一鉴权入口，并为未来多用户扩展预留
 * （届时可改为读取 users.role 字段）。
 *
 * 审计修复 SEC-C1（2026-07-01）：此前代码调用未定义的 isAdmin() 触发
 * Error，被全局异常处理器吞掉，导致鉴权形同虚设。
 */
function isAdmin(): bool {
    return !empty($_SESSION['logged_in']);
}

/**
 * 强制校验章节归属权，防止越权访问（多用户加固）
 *
 * 设计说明：同 checkNovelOwnership，单用户系统下无需逐一调用。
 *
 * @param int $chapterId 章节ID
 * @param int $userId 当前登录用户ID
 */
function checkChapterOwnership(int $chapterId, int $userId): void {
    $chapter = DB::fetch(
        'SELECT c.id, c.novel_id, n.user_id FROM chapters c 
         JOIN novels n ON c.novel_id = n.id 
         WHERE c.id = ?',
        [$chapterId]
    );
    if (!$chapter) {
        http_response_code(404);
        jsonResponse(false, null, '章节不存在', 'chapter_not_found');
    }
    // 审计修复 H2（2026-06-12）：同 checkNovelOwnership 策略。
    if ($userId <= 0) {
        http_response_code(403);
        jsonResponse(false, null, '会话异常，请重新登录', 'forbidden');
    }
    if ($chapter['user_id'] === null) {
        // 审计修复 H-NEW-1（2026-06-15）：同 checkNovelOwnership 竞态修复
        $affected = DB::execute(
            'UPDATE novels SET user_id=? WHERE id=? AND user_id IS NULL',
            [$userId, (int)$chapter['novel_id']]
        );
        if ($affected === 0) {
            $recheck = DB::fetch('SELECT user_id FROM novels WHERE id=?', [(int)$chapter['novel_id']]);
            if ((int)($recheck['user_id'] ?? 0) !== $userId) {
                http_response_code(403);
                jsonResponse(false, null, '无权操作此章节', 'forbidden');
            }
        }
    } elseif ((int)$chapter['user_id'] !== $userId) {
        http_response_code(403);
        jsonResponse(false, null, '无权操作此章节', 'forbidden');
    }
}

/**
 * 页面级小说归属权校验（审计修复 P2-14，2026-07-12）
 *
 * 设计说明：与 checkNovelOwnership() 同策略，但用于页面入口。
 * 失败时重定向到首页而非输出 JSON。接收已加载的 novel 数组避免重复查询。
 *
 * @param array|false $novel 已加载的小说记录
 * @param string $redirect 失败时的重定向目标
 * @return array 校验通过的 novel 数组（user_id 可能被自动认领更新）
 */
function requireNovelAccess($novel, string $redirect = 'index.php'): array {
    if (!$novel) {
        header('Location: ' . $redirect);
        exit;
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        header('Location: ' . $redirect);
        exit;
    }
    if (($novel['user_id'] ?? null) === null) {
        $affected = DB::execute(
            'UPDATE novels SET user_id=? WHERE id=? AND user_id IS NULL',
            [$userId, (int)$novel['id']]
        );
        if ($affected === 0) {
            $recheck = DB::fetch('SELECT user_id FROM novels WHERE id=?', [(int)$novel['id']]);
            if ((int)($recheck['user_id'] ?? 0) !== $userId) {
                header('Location: ' . $redirect);
                exit;
            }
        }
        $novel['user_id'] = $userId;
    } elseif ((int)$novel['user_id'] !== $userId) {
        header('Location: ' . $redirect);
        exit;
    }
    return $novel;
}

/**
 * 页面级短篇归属权校验（审计修复 P2-14，2026-07-12）
 *
 * 设计说明：同 requireNovelAccess，但用于短篇工作台入口。
 *
 * @param array|null $story 已加载的短篇记录
 * @param string $redirect 失败时的重定向目标
 * @return array 校验通过的短篇数组
 */
function requireShortStoryAccess(?array $story, string $redirect = 'shorts.php'): array {
    if (!$story) {
        header('Location: ' . $redirect);
        exit;
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        header('Location: ' . $redirect);
        exit;
    }
    if (($story['user_id'] ?? null) === null) {
        $affected = DB::execute(
            'UPDATE short_stories SET user_id=? WHERE id=? AND user_id IS NULL',
            [$userId, (int)$story['id']]
        );
        if ($affected === 0) {
            $recheck = DB::fetch('SELECT user_id FROM short_stories WHERE id=?', [(int)$story['id']]);
            if ((int)($recheck['user_id'] ?? 0) !== $userId) {
                header('Location: ' . $redirect);
                exit;
            }
        }
        $story['user_id'] = $userId;
    } elseif ((int)$story['user_id'] !== $userId) {
        header('Location: ' . $redirect);
        exit;
    }
    return $story;
}
