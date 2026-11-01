<?php
defined('APP_LOADED') or die('Direct access denied.');
require_once __DIR__ . '/RequestSecurity.php';
require_once __DIR__ . '/ContentSecurityPolicy.php';
require_once __DIR__ . '/maintenance.php';
ContentSecurityPolicy::send();

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
    // 设置 session cookie 安全属性（S2：兼容反向代理协议检测）
    $secure = RequestSecurity::isHttps(
        $_SERVER,
        defined('FORCE_HTTPS') && FORCE_HTTPS === true,
        defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : []
    );
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

// 审计 2026-08-13 #3 修复补充（2026-08-19）：adminCredentialsConfigured()/doLogin()
// 依赖 helpers.php 的 loadAdminCredentials()，但 login.php 等入口只加载本文件，
// 导致 "Call to undefined function loadAdminCredentials()" 致命错误。
// 此处显式加载（helpers.php 为纯工具函数，无 DB/反向依赖），保证本文件自洽可用。
require_once __DIR__ . '/helpers.php';

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
    // 审计修复 F02（2026-11-01）：恢复期间的维护标记必须挡住已登录会话的根页面
    // （创建/设置/导入等页面的 POST 都在 requireLogin 之后才碰数据库）。
    maintenanceGuardPage();
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
 * 获取当前登录用户 ID（统一会话键回退顺序）。
 * 单用户系统下登录即管理员（user_id=1）。
 */
function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
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

    // 审计修复 F02：路由器已在入口检查维护标记；这里覆盖不经路由器直连的 API 文件
    // （Nginx 未配置 api/ 拦截时），保证任何 HTTP 写入口在恢复期间都返回 503。
    maintenanceGuardHttp();

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

/** 管理员账号和 password_hash 散列是否已正确写入配置。
 *  审计 2026-08-13 #3：通过 loadAdminCredentials() 统一读取，
 *  兼容 storage/admin_credentials.json（优先）与 config.php 常量（回退）。 */
function adminCredentialsConfigured(): bool {
    $cred = loadAdminCredentials();
    if ($cred['user'] === '' || $cred['pass_hash'] === '') {
        return false;
    }

    $hash = $cred['pass_hash'];
    return strlen($hash) >= 50
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
    $cred = loadAdminCredentials();
    return hash_equals($cred['user'], $user) && password_verify($pass, $cred['pass_hash']);
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
// 这里改为按 REMOTE_ADDR 持久化（不可通过丢弃 cookie / 伪造头绕过）。
//
// 审计修复（2026-08-02 H1-DB）：存储从文件锁迁移到数据库。
//   - Windows NTFS 上 flock(LOCK_EX|LOCK_NB) 在某些 PHP 版本永远返回
//     false → 旧实现会改为不加锁直接读改写，并发失败请求丢失计数，绕过锁定阈值。
//   - 文件方案并发写还会触发 JSON 截断 / flock 饥饿。
//   - DB 方案用 InnoDB 行锁 + 事务保证原子性，跨平台行为一致。
//   - install.php 运行时表尚未建好，或 DB 不可用时降级回文件方案（锁定阈值不变）。
//
// 审计整改 C2（2026-10-31）：
//   - login.php 原先从未加载 DB 类，上面的 DB 路径实际从没生效；现已加载。
//   - 登录改为在校验密码之前由 loginThrottleAdmit() 原子地“判定 + 计数”。
//   - 文件锁拿不到、DB 事务失败时一律拒绝本次尝试（busy），不再无锁读改写，
//     也不在 DB 出错时改写文件。代价：若某平台 flock 始终失败且 DB 不可用，登录会被拒绝
//     并在错误日志里留下原因——这比放开暴力破解更安全。
// ============================================================

/** 登录失败锁定阈值：达到 10 次锁定 15 分钟 */
function loginThrottleLockoutThreshold(): int { return 10; }
function loginThrottleLockoutSeconds(): int { return 15 * 60; }

/**
 * 取连接方真实 IP。
 * 故意只用 REMOTE_ADDR——X-Forwarded-For 等头可被客户端伪造，
 * 一旦用它做限速键，攻击者改头即可绕过。
 */
function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * 检测 DB 限速表是否可用（install 阶段 / DB 故障时返回 false）。
 * 单次请求内缓存结果，避免每次登录都做 information_schema 探测。
 */
function loginThrottleDbReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (!class_exists('DB', false)) {
            $ready = false;
            return $ready;
        }
        // 用 SHOW TABLES 比 information_schema 查询轻量；
        // 失败（连接错误/表不存在）一律降级到文件方案。
        $row = \DB::fetch("SHOW TABLES LIKE 'login_throttle'");
        $ready = !empty($row);
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** 限速数据目录（文件降级路径用，不存在则创建，并放置 deny-all .htaccess） */
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

/** 单个 IP 的限速状态文件路径（仅文件降级路径使用） */
function loginThrottleFile(string $ip): string {
    return loginThrottleDir() . '/' . sha1($ip) . '.json';
}

/**
 * 读取某 IP 的限速状态。
 * @return array{attempts:int,last_attempt:int,lockout_until:int}
 */
function loginThrottleState(string $ip): array {
    $default = ['attempts' => 0, 'last_attempt' => 0, 'lockout_until' => 0];

    // DB 主路径
    if (loginThrottleDbReady()) {
        try {
            $row = \DB::fetch(
                'SELECT attempts, last_attempt, lockout_until FROM login_throttle WHERE ip_hash=? LIMIT 1',
                [sha1($ip)]
            );
            if ($row) {
                return [
                    'attempts'      => (int)$row['attempts'],
                    'last_attempt'  => (int)$row['last_attempt'],
                    'lockout_until' => (int)$row['lockout_until'],
                ];
            }
            return $default;
        } catch (\Throwable $e) {
            // DB 异常时降级到文件读取，确保登录页可用
            error_log('loginThrottleState DB read failed, fallback to file: ' . $e->getMessage());
        }
    }

    // 文件降级路径
    $file = loginThrottleFile($ip);
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return $default;
    return [
        'attempts'      => (int)($data['attempts'] ?? 0),
        'last_attempt'  => (int)($data['last_attempt'] ?? 0),
        'lockout_until' => (int)($data['lockout_until'] ?? 0),
    ];
}

/**
 * 写入限速状态（仅文件降级路径使用）。
 * 保留为 public 以便旧测试桩 monkey-patch；生产路径不调用。
 */
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
 * 在存储的排他锁内对某 IP 的限速状态做一次“读-判-写”。
 *
 * 审计整改 C2（2026-10-31）：登录准入必须是原子的。DB 可用时用事务 + FOR UPDATE 行锁
 * （先确保行存在，避免首次访问时锁不到行）；否则用文件 flock。拿不到锁、事务失败时
 * 返回 null，由调用方按“失败即拒绝”处理——不再有无锁读改写回退，也不在 DB 出错时
 * 改用文件，避免同一 IP 的计数分裂到两个存储里。
 *
 * @param callable(array{attempts:int,last_attempt:int,lockout_until:int}, int): array{0: ?array, 1: mixed} $mutate
 *        返回 [要写回的新状态（null 表示不写）, 结果]
 * @return array{result: mixed}|null
 */
function loginThrottleMutate(string $ip, callable $mutate): ?array {
    $normalize = static fn(array $s): array => [
        'attempts'      => (int)($s['attempts'] ?? 0),
        'last_attempt'  => (int)($s['last_attempt'] ?? 0),
        'lockout_until' => (int)($s['lockout_until'] ?? 0),
    ];

    // ===== DB 主路径：事务 + FOR UPDATE 行锁 =====
    if (loginThrottleDbReady()) {
        $ipHash = sha1($ip);
        for ($try = 1; $try <= 3; $try++) {
            try {
                \DB::execute(
                    'INSERT INTO login_throttle (ip_hash, attempts, last_attempt, lockout_until) VALUES (?, 0, 0, 0)'
                    . ' ON DUPLICATE KEY UPDATE ip_hash = ip_hash',
                    [$ipHash]
                );
                if (!\DB::beginTransaction()) {
                    throw new \RuntimeException('nested transaction');
                }
                try {
                    $row = \DB::fetch(
                        'SELECT attempts, last_attempt, lockout_until FROM login_throttle WHERE ip_hash=? FOR UPDATE',
                        [$ipHash]
                    );
                    [$next, $result] = $mutate($normalize(is_array($row) ? $row : []), time());
                    if ($next !== null) {
                        $next = $normalize($next);
                        \DB::execute(
                            'UPDATE login_throttle SET attempts=?, last_attempt=?, lockout_until=? WHERE ip_hash=?',
                            [$next['attempts'], $next['last_attempt'], $next['lockout_until'], $ipHash]
                        );
                    }
                    \DB::commit();
                    return ['result' => $result];
                } catch (\Throwable $e) {
                    \DB::rollBack();
                    throw $e;
                }
            } catch (\Throwable $e) {
                // 首次访问时并发插入同一键可能死锁（SQLSTATE 40001），重试即可；其他错误直接失败即拒绝。
                $deadlock = $e instanceof \PDOException && (string)$e->getCode() === '40001';
                if ($deadlock && $try < 3) continue;
                error_log('login throttle DB update failed, denying this attempt: ' . $e->getMessage());
                return null;
            }
        }
        return null;
    }

    // ===== 文件路径：有界等待的排他 flock；拿不到锁就失败即拒绝 =====
    $file = loginThrottleFile($ip);
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        error_log('login throttle state file cannot be opened, denying this attempt: ' . $file);
        return null;
    }
    $deadline = microtime(true) + 2.0;
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            fclose($fp);
            error_log('login throttle state file stayed locked for 2s, denying this attempt: ' . $file);
            return null;
        }
        usleep(20000);
    }
    try {
        rewind($fp);
        $data = json_decode((string)stream_get_contents($fp), true);
        [$next, $result] = $mutate($normalize(is_array($data) ? $data : []), time());
        if ($next !== null) {
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($normalize($next), JSON_UNESCAPED_UNICODE));
            fflush($fp);
        }
        return ['result' => $result];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
        @chmod($file, 0600);
    }
}

/**
 * 登录准入的判定（纯函数，在 loginThrottleMutate 的锁内调用）：
 * 锁定期内拒绝；渐进延迟（每次尝试 +2 秒，最多 30 秒）未到拒绝；否则占用一次尝试额度，
 * 达到阈值即同时设置锁定。被拒绝的请求不计数。
 *
 * @return array{0: ?array, 1: array{allowed:bool, reason:string, wait:int, state:array}}
 */
function loginThrottleAdmitDecision(array $s, int $now): array {
    if ($s['lockout_until'] > $now) {
        return [null, ['allowed' => false, 'reason' => 'locked', 'wait' => $s['lockout_until'] - $now, 'state' => $s]];
    }
    if ($s['lockout_until'] > 0) {
        // 锁定期已过：重新计数
        $s['attempts'] = 0;
        $s['lockout_until'] = 0;
    }
    $delay = min(30, $s['attempts'] * 2);
    if ($delay > 0 && $now - $s['last_attempt'] < $delay) {
        return [null, ['allowed' => false, 'reason' => 'wait', 'wait' => $delay, 'state' => $s]];
    }
    $s['attempts']++;
    $s['last_attempt'] = $now;
    if ($s['attempts'] >= loginThrottleLockoutThreshold()) {
        $s['lockout_until'] = $now + loginThrottleLockoutSeconds();
    }
    return [$s, ['allowed' => true, 'reason' => '', 'wait' => 0, 'state' => $s]];
}

/**
 * 在校验密码之前原子地占用一次尝试额度。
 *
 * 原流程先读状态、校验密码、失败后才记录：并发请求能同时通过读检查，渐进延迟和锁定阈值
 * 都可被并发绕过。现在“判定 + 计数”在同一把锁内完成；登录成功再由 loginThrottleReset() 清零。
 * 存储不可用（打不开、锁不住、事务失败）时拒绝本次尝试（reason=busy）。
 *
 * @return array{allowed:bool, reason:string, wait:int, state:array}
 */
function loginThrottleAdmit(string $ip): array {
    $outcome = loginThrottleMutate($ip, 'loginThrottleAdmitDecision');
    if ($outcome === null) {
        return ['allowed' => false, 'reason' => 'busy', 'wait' => 0,
            'state' => ['attempts' => 0, 'last_attempt' => 0, 'lockout_until' => 0]];
    }
    return $outcome['result'];
}

/**
 * 记录一次登录失败，返回更新后的状态。
 * 达到 loginThrottleLockoutThreshold() 次锁定 loginThrottleLockoutSeconds() 秒。
 *
 * 登录页已改用 loginThrottleAdmit() 在校验前计数；本函数保留给需要“事后记一次失败”的调用方，
 * 与准入共用同一把锁。存储不可用时不写入、只记日志，返回当前读到的状态。
 */
function loginThrottleRecordFail(string $ip): array {
    $outcome = loginThrottleMutate($ip, static function (array $s, int $now): array {
        if ($s['lockout_until'] > 0 && $now > $s['lockout_until']) {
            // 锁定期已过 → 复位为本次失败
            $s['attempts'] = 1;
            $s['lockout_until'] = 0;
        } else {
            $s['attempts']++;
            if ($s['attempts'] >= loginThrottleLockoutThreshold()) {
                $s['lockout_until'] = $now + loginThrottleLockoutSeconds();
            }
        }
        $s['last_attempt'] = $now;
        return [$s, $s];
    });
    if ($outcome === null) {
        error_log('loginThrottleRecordFail could not lock the throttle store; failure not recorded');
        return loginThrottleState($ip);
    }
    return $outcome['result'];
}

/** 登录成功后清除该 IP 的限速记录 */
function loginThrottleReset(string $ip): void {
    // DB 主路径
    if (loginThrottleDbReady()) {
        try {
            \DB::execute('DELETE FROM login_throttle WHERE ip_hash=?', [sha1($ip)]);
            return;
        } catch (\Throwable $e) {
            error_log('loginThrottleReset DB delete failed, fallback to file: ' . $e->getMessage());
        }
    }

    // 文件降级路径
    $file = loginThrottleFile($ip);
    if (is_file($file)) @unlink($file);
}

/**
 * 归属校验共用的“NULL 自动认领 + 竞态复核”（复审 T7，2026-10-31）。
 *
 * 原先在 checkNovelOwnership / checkChapterOwnership / requireNovelAccess /
 * requireShortStoryAccess 中逐字重复，鉴权口径改动需同步四处。语义保持不变：
 *   - 已有归属：仅当等于当前用户时通过，不写库；
 *   - 归属为 NULL：条件 UPDATE 认领（H-NEW-1：并发时只有一方 affected=1），
 *     affected=0 说明被抢先认领，复核最新归属是否为当前用户。
 *   - 审计修复 F03（2026-11-01）：short_stories.user_id 是 NOT NULL DEFAULT 0，历史无主短篇
 *     为 0（旧库可能是 NULL），两者都按无主认领；novels 仍只有 NULL 表示无主。无效用户一律拒绝。
 *
 * @param string $table        归属表（白名单：novels / short_stories）
 * @param int    $id           行 ID
 * @param int    $userId       当前用户（调用方应已确保 > 0，此处再次拒绝 <= 0）
 * @param mixed  $currentOwner 已读取的 user_id（NULL=未认领；short_stories 的 0 同样未认领）
 * @return bool 当前用户拥有该行（含刚认领成功）
 */
function claimOrVerifyOwner(string $table, int $id, int $userId, mixed $currentOwner): bool {
    if (!in_array($table, ['novels', 'short_stories'], true)) {
        throw new InvalidArgumentException('Unsupported ownership table.');
    }
    if ($userId <= 0) {
        return false;
    }
    $zeroIsUnowned = $table === 'short_stories';
    if ($currentOwner !== null && !($zeroIsUnowned && (int)$currentOwner === 0)) {
        return (int)$currentOwner === $userId;
    }
    $unowned = $zeroIsUnowned ? '(user_id IS NULL OR user_id=0)' : 'user_id IS NULL';
    $affected = DB::execute(
        "UPDATE {$table} SET user_id=? WHERE id=? AND {$unowned}",
        [$userId, $id]
    );
    if ($affected !== 0) {
        return true;
    }
    $recheck = DB::fetch("SELECT user_id FROM {$table} WHERE id=?", [$id]);
    return (int)($recheck['user_id'] ?? 0) === $userId;
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
    // 审计修复 H-NEW-1（2026-06-15）的认领竞态复核已收敛到 claimOrVerifyOwner()。
    if (!claimOrVerifyOwner('novels', $novelId, $userId, $novel['user_id'])) {
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
    if (!claimOrVerifyOwner('novels', (int)$chapter['novel_id'], $userId, $chapter['user_id'])) {
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
    $wasUnowned = ($novel['user_id'] ?? null) === null;
    if (!claimOrVerifyOwner('novels', (int)$novel['id'], $userId, $novel['user_id'] ?? null)) {
        header('Location: ' . $redirect);
        exit;
    }
    if ($wasUnowned) {
        $novel['user_id'] = $userId;
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
    // short_stories 的历史无主值是 0（旧库可能为 NULL），与 claimOrVerifyOwner 口径一致。
    $wasUnowned = ($story['user_id'] ?? null) === null || (int)$story['user_id'] === 0;
    if (!claimOrVerifyOwner('short_stories', (int)$story['id'], $userId, $story['user_id'] ?? null)) {
        header('Location: ' . $redirect);
        exit;
    }
    if ($wasUnowned) {
        $story['user_id'] = $userId;
    }
    return $story;
}
