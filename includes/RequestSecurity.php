<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

final class RequestSecurity
{
    /** Forwarded protocol is meaningful only when the immediate peer is trusted. */
    public static function isHttps(array $server, bool $forceHttps = false, array $trustedProxies = []): bool
    {
        if ($forceHttps
            || (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off')
            || (int)($server['SERVER_PORT'] ?? 0) === 443
            || self::hasTrustedServerHttpsFlag($server)) {
            return true;
        }
        $peer = (string)($server['REMOTE_ADDR'] ?? '');
        if ($peer === '' || !in_array($peer, $trustedProxies, true)) {
            return false;
        }
        // Reject chains and ambiguous values: the trusted proxy must overwrite this header.
        return strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }

    /**
     * Set only by the web server after checking its actual connection peer and
     * forwarded protocol. Works before config.php exists (the installer).
     * Apache prefixes inherited environment keys during internal redirects;
     * client headers always have HTTP_ and must never qualify as this flag.
     */
    private static function hasTrustedServerHttpsFlag(array $server): bool
    {
        foreach ($server as $key => $value) {
            if ($value === '1' && preg_match('/^(?:REDIRECT_)*NOVEL_TRUSTED_HTTPS$/D', (string)$key) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function bearerToken(array $server, string $headerName): string
    {
        $token = trim((string)($server[$headerName] ?? ''));
        if ($token !== '') return $token;
        $authorization = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/^\s*Bearer\s+([^\s]+)\s*$/iD', $authorization, $matches) === 1
            ? $matches[1] : '';
    }
}
