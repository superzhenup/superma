<?php
/**
 * 缓存类 — 支持文件缓存和APCu缓存
 * 
 * 使用方式：
 *   Cache::get('key') — 获取缓存
 *   Cache::set('key', $data, 3600) — 设置缓存（1小时）
 *   Cache::delete('key') — 删除缓存
 *   Cache::clear() — 清空所有缓存
 */
defined('APP_LOADED') or die('Direct access denied.');

class Cache
{
    /** @var string 缓存目录 */
    private static $cacheDir = null;

    /** @var bool 是否使用APCu */
    private static $useApcu = false;

    /** @var bool 是否已初始化（审计 2026-06-10 P3-D：init 结果记忆化，
     *  避免每次 get/set/delete/has 都重跑 apcu 探测 + is_dir + is_writable 系统调用） */
    private static $initialized = false;
    private static $writeFailCount = 0;

    /**
     * 初始化缓存系统（每请求只完整执行一次；初始化失败时下次调用会重试）
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // 检查是否可以使用APCu
        self::$useApcu = function_exists('apcu_enabled') && apcu_enabled();

        // 设置文件缓存目录
        if (defined('BASE_PATH')) {
            self::$cacheDir = BASE_PATH . '/storage/cache';
        } elseif (defined('ROOT_PATH')) {
            self::$cacheDir = ROOT_PATH . '/storage/cache';
        } else {
            self::$cacheDir = dirname(__DIR__) . '/storage/cache';
        }

        // 确保缓存目录存在且可写
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
        if (!is_dir(self::$cacheDir) || !is_writable(self::$cacheDir)) {
            // 目录不存在或不可写，禁用文件缓存
            self::$cacheDir = null;
        }

        // 两个后端都不可用时不置位，让下一次调用重试初始化（目录可能随后被创建/授权）
        self::$initialized = (self::$cacheDir !== null || self::$useApcu);
    }

    /**
     * 确保缓存系统已初始化且目录有效
     */
    private static function ensureReady(): void
    {
        self::init();
    }

    /**
     * 获取缓存
     * 
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::ensureReady();

        // 尝试APCu
        if (self::$useApcu) {
            $value = apcu_fetch($key, $success);
            if ($success) {
                return $value;
            }
        }

        // 文件缓存（仅当目录存在时）
        if (self::$cacheDir === null) {
            return $default;
        }

        $file = self::getCacheFile($key);
        if (!file_exists($file)) {
            return $default;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return $default;
        }

        $data = json_decode($content, true);
        if (!$data || !isset($data['expires_at'])) {
            return $default;
        }

        // 检查是否过期
        if (time() > $data['expires_at']) {
            @unlink($file);
            return $default;
        }

        return $data['value'] ?? $default;
    }

    /**
     * 设置缓存
     * 
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 生存时间（秒），默认1小时
     * @return bool
     */
    public static function set(string $key, $value, int $ttl = 3600): bool
    {
        self::ensureReady();

        $ok = true;

        // 审计修复（2026-07-19 H-中6）：APCu 与文件缓存必须同时写入两个后端。
        // 混合 SAPI 环境下（CLI 写文件、Web 读 APCu 命中/回退文件）单后端写入
        // 会导致 delete 时"删了又回来"、expire 后从文件读到陈旧值的脏读。
        // 尝试APCu（非阻塞：失败也继续文件写入）
        if (self::$useApcu) {
            $ok = apcu_store($key, $value, $ttl) || $ok;
        }

        // 文件缓存（仅当目录存在时）
        if (self::$cacheDir === null) {
            return $ok;
        }

        // 文件缓存
        $file = self::getCacheFile($key);
        $data = [
            'key' => $key,
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time(),
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return $ok;
        }

        $tmp = $file . '.tmp.' . getmypid() . '.' . uniqid('', true);
        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            if (++self::$writeFailCount >= 3) {
                self::$cacheDir = null;
            }
            return $ok;
        }

        if (@rename($tmp, $file)) {
            return true;
        }

        @unlink($file);
        if (@rename($tmp, $file)) {
            return true;
        }

        if (@copy($tmp, $file)) {
            @unlink($tmp);
            return true;
        }

        @unlink($tmp);
        return $ok;
    }

    /**
     * 删除缓存
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public static function delete(string $key): bool
    {
        // 审计修复 M-5（2026-07-20）：统一使用 ensureReady()，
        // 与 get()/set() 保持一致，避免未来 ensureReady() 增加额外
        // 初始化步骤时 delete() 跳过它们。
        self::ensureReady();

        $ok = true;

        // 审计修复（2026-07-19 H-中6）：同时删除两个后端。
        // 单删 APCu 时文件副本会被 get() 回退读取复活。
        if (self::$useApcu) {
            $ok = apcu_delete($key) || $ok;
        }

        // 文件缓存
        if (self::$cacheDir === null) {
            return $ok;
        }

        $file = self::getCacheFile($key);
        if (file_exists($file)) {
            $ok = @unlink($file) || $ok;
        }

        return $ok;
    }

    /**
     * 清空所有缓存
     * 
     * @return bool
     */
    public static function clear(): bool
    {
        self::init();

        // 清空APCu
        if (self::$useApcu) {
            apcu_clear_cache();
        }

        // 清空文件缓存（仅当目录存在时）
        if (self::$cacheDir !== null) {
            $files = glob(self::$cacheDir . '/*.cache');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            // 顺带清理原子写可能残留的临时文件（进程中途被杀等极端情况）
            $tmps = glob(self::$cacheDir . '/*.cache.tmp.*');
            if ($tmps) {
                foreach ($tmps as $t) {
                    @unlink($t);
                }
            }
        }

        return true;
    }

    /**
     * 获取缓存文件路径
     * 
     * @param string $key 缓存键
     * @return string
     */
    private static function getCacheFile(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key);
        return self::$cacheDir . '/' . $safeKey . '.cache';
    }

    /**
     * 检查缓存是否存在且未过期
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::init();

        // 检查APCu
        if (self::$useApcu) {
            return apcu_exists($key);
        }

        // 检查文件缓存（仅当目录存在时）
        if (self::$cacheDir === null) {
            return false;
        }

        // 检查文件缓存
        $file = self::getCacheFile($key);
        if (!file_exists($file)) {
            return false;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (!$data || !isset($data['expires_at'])) {
            return false;
        }

        // 检查是否过期
        if (time() > $data['expires_at']) {
            @unlink($file);
            return false;
        }

        return true;
    }
}
