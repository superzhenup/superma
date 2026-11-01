<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * Cron 工作令牌（daemon / drama-cron）的共享签发、轮换与可选 TTL 门
 * （审计 Low-3，2026-10-31）。
 *
 * 契约：
 *   - 首次生成与每次轮换都写入签发时间（<token>_issued_at，unix 秒）；
 *   - 轮换以 random_bytes 生成新值并覆盖旧值，旧令牌立即失效；
 *   - 可选 TTL（<token>_ttl_days，默认 0=永不过期）由各 cron 端点的
 *     鉴权段通过 cronSecretTtlExpired() 强制：>0 且已过期返回 401 +
 *     token_expired。升级前签发、没有 issued_at 的既有令牌 fail-safe
 *     永不过期，保证升级后现有 cron 不失效。
 */

/**
 * Read the daemon token without creating or exposing a filesystem fallback.
 */
function getDaemonToken(): string
{
    $row = DB::fetch("SELECT setting_value FROM system_settings WHERE setting_key='daemon_token'");
    return trim((string)($row['setting_value'] ?? ''));
}

/**
 * Record the issuance time of a cron secret (unix seconds).
 * Called on first generation and on every rotation.
 */
function writeCronSecretIssuedAt(string $issuedAtKey): void
{
    DB::query(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$issuedAtKey, (string)time()]
    );
}

/**
 * Rotate a cron secret: overwrite the old value with a fresh random_bytes
 * token and stamp a new issuance time. Returns the new token.
 */
function rotateCronSecretToken(string $tokenKey, string $issuedAtKey): string
{
    $generated = bin2hex(random_bytes(24));
    DB::query(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$tokenKey, $generated]
    );
    writeCronSecretIssuedAt($issuedAtKey);
    if (function_exists('clearSystemSettingsCache')) clearSystemSettingsCache();

    $row = DB::fetch('SELECT setting_value FROM system_settings WHERE setting_key=?', [$tokenKey]);
    $token = trim((string)($row['setting_value'] ?? ''));
    return $token !== '' ? $token : $generated;
}

/** Rotate the daemon cron token (audit Low-3): the old token stops working immediately. */
function rotateDaemonToken(): string
{
    return rotateCronSecretToken('daemon_token', 'daemon_token_issued_at');
}

/**
 * Initialize the daemon token for an authenticated operator.
 */
function getOrCreateDaemonToken(): string
{
    $token = getDaemonToken();
    if ($token !== '') {
        return $token;
    }

    $generated = bin2hex(random_bytes(24));
    DB::query(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('daemon_token', ?)
         ON DUPLICATE KEY UPDATE setting_value = setting_value",
        [$generated]
    );
    // 审计 Low-3：首次生成记录签发时间（TTL 门与轮换共用同一时间戳）。
    writeCronSecretIssuedAt('daemon_token_issued_at');
    if (function_exists('clearSystemSettingsCache')) clearSystemSettingsCache();

    $token = getDaemonToken();
    return $token !== '' ? $token : $generated;
}

/**
 * Optional TTL gate shared by the daemon / drama-cron header auth sections
 * (audit Low-3). All branches fail safe for existing crons:
 *   - TTL 缺失或 <= 0（默认 0）= 永不过期；
 *   - 升级前签发的令牌没有 issued_at 时间戳 = 永不过期（不得因设置
 *     TTL 或升级本身让既有 cron 立即失效）；
 *   - 仅当 TTL > 0 且 now - issued_at > TTL*86400 时判定过期。
 * 读取走单键直查（与 getDaemonToken 同源），不经过请求级设置缓存，
 * 鉴权路径永远读到最新值。
 */
function cronSecretTtlExpired(string $ttlDaysKey, string $issuedAtKey): bool
{
    $rows = DB::fetchAll(
        'SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?)',
        [$ttlDaysKey, $issuedAtKey]
    );
    $values = [];
    foreach ($rows as $row) {
        $values[(string)($row['setting_key'] ?? '')] = (string)($row['setting_value'] ?? '');
    }

    $ttlDays = (int)trim($values[$ttlDaysKey] ?? '0');
    if ($ttlDays <= 0) {
        return false;
    }
    $issuedAt = (int)trim($values[$issuedAtKey] ?? '0');
    if ($issuedAt <= 0) {
        return false;
    }
    return (time() - $issuedAt) > $ttlDays * 86400;
}
