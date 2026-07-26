<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * Read the daemon token without creating or exposing a filesystem fallback.
 */
function getDaemonToken(): string
{
    $row = DB::fetch("SELECT setting_value FROM system_settings WHERE setting_key='daemon_token'");
    return trim((string)($row['setting_value'] ?? ''));
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
    if (function_exists('clearSystemSettingsCache')) clearSystemSettingsCache();

    $token = getDaemonToken();
    return $token !== '' ? $token : $generated;
}

