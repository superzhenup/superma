<?php
/**
 * ConstraintConfig — 约束框架配置中心
 *
 * 读取 system_settings 中 cf_* 前缀的配置项，提供类型化访问。
 * 所有访问通过 getSystemSetting() 复用现有缓存机制。
 *
 * @package ConstraintFramework
 */

defined('APP_LOADED') or die('Direct access denied.');

class ConstraintConfig
{
    /** @var array<string,mixed> 本地缓存 */
    private static array $cache = [];

    /**
     * 约束框架是否启用
     */
    public static function isEnabled(): bool
    {
        return self::bool('cf_enabled', false);
    }

    /**
     * 严格模式：0=仅提醒不拦截，1=P0违规阻止落盘
     */
    public static function isStrictMode(): bool
    {
        return self::bool('cf_strict_mode', false);
    }

    // ── 情节约束 ──

    public static function maxCoincidences(): int
    {
        return self::int('cf_max_coincidences', 5);
    }

    // ── 语言/风格约束 ──

    // 审计修复（2026-07-19 M4-2）：原 10 个零调用 getter 已移除——
    // combatRatioMin/Max、speedFactor、rivalFactor、maxSameConflict、
    // foreshadowingRecoveryMin、maxNewInfoPerChapter、minBufferRelease、
    // cooldownAfterClimax、maxBannedWordUsage 均无任何消费方，保留会制造
    // "约束在生效"的错觉。对应 cf_* 设置键为预留项，在 config_constants.php 中已标注。

    /** @return string[] */
    public static function bannedWords(): array
    {
        $raw = self::str('cf_banned_words', '绝境,反杀,真相,背水,逆袭');
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    // ── 低级访问器 ──

    private static function bool(string $key, bool $default): bool
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = (bool)getSystemSetting($key, $default, 'bool');
        }
        return self::$cache[$key];
    }

    private static function int(string $key, int $default): int
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = (int)getSystemSetting($key, $default, 'int');
        }
        return self::$cache[$key];
    }

    private static function float(string $key, float $default): float
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = (float)getSystemSetting($key, $default, 'float');
        }
        return self::$cache[$key];
    }

    private static function str(string $key, string $default): string
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = (string)getSystemSetting($key, $default, 'string');
        }
        return self::$cache[$key];
    }

    /**
     * 清除缓存（用于测试或配置热更新）
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
