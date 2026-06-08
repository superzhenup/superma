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

    /**
     * 初始化缓存系统
     */
    private static function init(): void
    {
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
    }

    /**
     * 确保缓存系统已初始化且目录有效
     */
    private static function ensureReady(): void
    {
        if (self::$cacheDir === null && !self::$useApcu) {
            // 目录之前不可用，重新检查一次
            self::init();
        }
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
        self::init();
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
        self::init();
        self::ensureReady();

        // 尝试APCu
        if (self::$useApcu) {
            return apcu_store($key, $value, $ttl);
        }

        // 文件缓存（仅当目录存在时）
        if (self::$cacheDir === null) {
            return false;
        }

        // 文件缓存
        $file = self::getCacheFile($key);
        $data = [
            'key' => $key,
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time(),
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        // 审计 P3：原先直接写目标文件，并发写或写到一半被中断会留下残缺 JSON，
        // 读取端 json_decode 失败（缓存击穿甚至读到坏数据）。改为「写临时文件 + 原子 rename」：
        // 同目录内的 rename 在 POSIX 上是原子的——读者只会看到旧的完整文件或新的完整文件，
        // 绝不会读到半截内容。临时文件名带 .cache.tmp 后缀，不会被 get()/has()/clear() 当成缓存项。
        $tmp = $file . '.tmp.' . getmypid() . '.' . uniqid('', true);
        if (@file_put_contents($tmp, $json) === false) {
            // 写入失败，标记目录不可用，后续不再尝试文件缓存
            @unlink($tmp);
            self::$cacheDir = null;
            return false;
        }

        if (@rename($tmp, $file)) {
            return true;
        }

        // rename 兜底（如 Windows NTFS 上目标已存在导致 rename 失败）：
        // 先删旧文件再重试 rename；若仍失败则用 copy + unlink 替代。
        @unlink($file);
        if (@rename($tmp, $file)) {
            return true;
        }

        // Windows NTFS 最终兜底：copy + unlink（非原子但保证数据落盘）
        if (@copy($tmp, $file)) {
            @unlink($tmp);
            return true;
        }

        // 仍失败：清理临时文件，避免残留。
        @unlink($tmp);
        return false;
    }

    /**
     * 删除缓存
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public static function delete(string $key): bool
    {
        self::init();

        // 尝试APCu
        if (self::$useApcu) {
            return apcu_delete($key);
        }

        // 文件缓存（仅当目录存在时）
        if (self::$cacheDir === null) {
            return true; // 没有文件缓存，视为成功
        }

        $file = self::getCacheFile($key);
        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
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
