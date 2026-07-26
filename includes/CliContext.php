<?php
/**
 * CLI 上下文标记
 *
 * 审计优化 P3-9（2026-06-16）：替代 write_chapter_worker.php 中
 * 硬编码 $_SESSION['logged_in']=true 的做法。
 *
 * CLI worker 通过 CliContext::activate() 显式声明运行上下文，
 * auth.php 的 requireLoginApi / enforceSessionLifecycle 检测到
 * CLI 上下文后直接短路，不再依赖伪造的 session 状态。
 *
 * 安全：activate() 仅在 PHP_SAPI === 'cli' 时可调用，
 * 防止 HTTP 请求伪造 CLI 上下文绕过登录。
 */

defined('APP_LOADED') or die('Direct access denied.');

class CliContext
{
    private static bool $active = false;

    /**
     * 允许激活 CLI 上下文的入口脚本白名单（basename）。
     * 仅这些脚本可以绕过登录检查，其他 CLI 脚本不应激活。
     */
    private const ALLOWED_ENTRYPOINTS = [
        'write_chapter_worker.php',
        'daemon_write.php',
    ];

    /**
     * 激活 CLI 上下文。仅在 CLI 模式下、且入口脚本在白名单内时可调用。
     * @throws RuntimeException 非 CLI 模式或非白名单入口调用时抛出
     */
    public static function activate(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('CliContext can only be activated in CLI mode');
        }
        // 纵深防御：校验入口脚本是否在白名单中，防止其他 CLI 脚本误激活
        $entryScript = basename($_SERVER['argv'][0] ?? '');
        if ($entryScript !== '' && !in_array($entryScript, self::ALLOWED_ENTRYPOINTS, true)) {
            error_log("CliContext: rejected activation from non-whitelisted entry '$entryScript'");
            throw new RuntimeException("CliContext: entry script '$entryScript' not in whitelist");
        }
        self::$active = true;
        if (!defined('CLI_MODE')) {
            define('CLI_MODE', true);
        }
    }

    public static function isActive(): bool
    {
        return self::$active;
    }
}
