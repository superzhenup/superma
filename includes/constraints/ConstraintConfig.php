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
        return self::bool('cf_enabled', true);
    }

    /**
     * 严格模式：0=仅提醒不拦截，1=P0违规阻止落盘
     *
     * 审计修复（2026-08-17 写作逻辑 E）：产品口径 = E-文档 + 向导显式开关。
     * 全局默认保持关（记录问题、写下章紧急指令，不拦截落盘）；高阶向导立项页
     * 可为新书写小说级覆盖（novel_settings.cf_strict_mode），存量书不受影响。
     * $novelId 为空时读全局，与旧调用兼容。
     */
    public static function isStrictMode(?int $novelId = null): bool
    {
        if ($novelId !== null && $novelId > 0) {
            try {
                if (!class_exists('ConfigCenter', false)) {
                    $ccPath = dirname(__DIR__) . '/config_center.php';
                    if (is_file($ccPath)) {
                        require_once $ccPath;
                    }
                }
                if (class_exists('ConfigCenter', false)) {
                    return (bool)ConfigCenter::getNovel(
                        $novelId,
                        'cf_strict_mode',
                        self::bool('cf_strict_mode', false),
                        'bool'
                    );
                }
            } catch (\Throwable $e) {
                // 小说级覆盖读取失败时回退全局口径
            }
        }
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
        return max(1, self::int('cf_max_same_conflict', 1));
    }

    public static function maxCoincidences(): int
    {
        return self::int('cf_max_coincidences', 5);
    }

    // ── 信息/伏笔约束 ──

    public static function foreshadowingRecoveryMin(): int
    {
        return max(0, min(100, self::int('cf_foreshadowing_recovery_min', 70)));
    }

    public static function maxNewInfoPerChapter(): int
    {
        return max(1, self::int('cf_max_new_info_per_ch', 2));
    }

    // ── 节奏约束 ──

    public static function minBufferRelease(): int
    {
        return max(1, self::int('cf_min_buffer_release', 2));
    }

    public static function cooldownAfterClimax(): int
    {
        return max(1, self::int('cf_cooldown_after_climax', 1));
    }

    // ── 语言/风格约束 ──

    public static function maxBannedWordUsage(): int
    {
        return max(1, self::int('cf_max_banned_word_usage', 15));
    }

    /** @return string[] */
    public static function bannedWords(): array
    {
        $raw = self::str('cf_banned_words', '绝境,反杀,真相,背水,逆袭');
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $word): bool => $word !== ''
        ));
    }

    /**
     * Capture every mutable ConstraintConfig input currently consumed by the
     * post-write validator. The returned values are fully resolved scalars and
     * an ordered string list so callers can serialize them into an immutable
     * chapter policy without retaining any dependency on this live reader.
     *
     * Strict-mode disposition is intentionally not duplicated here; it already
     * lives in WritePolicySnapshot.constraint_strict and may be novel-scoped.
     *
     * @return array{
     *   constraint_enabled:bool,
     *   constraint_banned_words:list<string>,
     *   constraint_max_banned_word_usage:int,
     *   constraint_max_same_conflict:int,
     *   constraint_max_coincidences:int
     * }
     */
    public static function capturePostWriteProfile(): array
    {
        return [
            'constraint_enabled' => self::isEnabled(),
            'constraint_banned_words' => self::bannedWords(),
            'constraint_max_banned_word_usage' => self::maxBannedWordUsage(),
            'constraint_max_same_conflict' => self::maxSameConflict(),
            'constraint_max_coincidences' => max(0, self::maxCoincidences()),
        ];
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
