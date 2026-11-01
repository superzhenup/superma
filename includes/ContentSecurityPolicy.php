<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/** One unpredictable nonce per response; only trusted source templates receive it. */
final class ContentSecurityPolicy
{
    private static ?string $nonce = null;
    /** @var array<string,true> */
    private static array $frameSources = [];

    /** Admit only an administrator-selected HTTPS frame origin, never raw header text. */
    public static function allowFrameUrl(string $url): bool
    {
        if ($url === '') return false;
        if (preg_match('/[\x00-\x20\x7f]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/^[a-zA-Z0-9.-]+$/D', $parts['host'] ?? '') !== 1) return false;
        $source = 'https://' . strtolower($parts['host']);
        if (isset($parts['port'])) $source .= ':' . $parts['port'];
        self::$frameSources[$source] = true;
        return true;
    }

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(24));
    }

    public static function policy(): string
    {
        return "default-src 'self'; "
            . "script-src 'self' 'nonce-" . self::nonce()
            . "' https://cdn.jsdelivr.net/npm/vis-network@9.1.9/dist/vis-network.min.js"
            . " https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js; "
            . "script-src-attr 'none'; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
            . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; "
            . "media-src 'self' blob:; object-src 'none'; base-uri 'self'; "
            . "form-action 'self'; frame-src 'self'" . (self::$frameSources ? ' ' . implode(' ', array_keys(self::$frameSources)) : '')
            . "; frame-ancestors 'self'";
    }

    public static function send(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        $reportOnly = defined('CSP_REPORT_ONLY') && CSP_REPORT_ONLY === true;
        $header = $reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        header($header . ': ' . self::policy());
    }
}

function cspNonce(): string
{
    return ContentSecurityPolicy::nonce();
}
