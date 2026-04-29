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

    // ── 角色约束 ──

    public static function combatRatioMin(): int
    {
        return self::int('cf_combat_ratio_min', 40);
    }

    public static function combatRatioMax(): int
    {
        return self::int('cf_combat_ratio_max', 60);
    }

    public static function speedFactor(): int
    {
        return self::int('cf_speed_factor', 10);
    }

    public static function rivalFactor(): float
    {
        return self::float('cf_rival_factor', 0.8);
    }

    // ── 情节约束 ──

    public static function maxSameConflict(): int
    {
        return self::int('cf_max_same_conflict', 1);
    }

    public static function maxCoincidences(): int
    {
        return self::int('cf_max_coincidences', 5);
    }

    // ── 信息/伏笔约束 ──

    public static function foreshadowingRecoveryMin(): int
    {
        return self::int('cf_foreshadowing_recovery_min', 70);
    }

    public static function maxNewInfoPerChapter(): int
    {
        return self::int('cf_max_new_info_per_ch', 2);
    }

    // ── 节奏约束 ──

    public static function minBufferRelease(): int
    {
        return self::int('cf_min_buffer_release', 2);
    }

    public static function cooldownAfterClimax(): int
    {
        return self::int('cf_cooldown_after_climax', 1);
    }

    // ── 语言/风格约束 ──

    public static function maxBannedWordUsage(): int
    {
        return self::int('cf_max_banned_word_usage', 15);
    }

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
