<?php
/**
 * 配置中心类
 * 
 * 提供统一的配置读写接口，用于替代直接调用getSystemSetting()函数
 * 支持读写system_settings表中的配置项
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

class ConfigCenter
{
    /**
     * @var array 配置缓存（按 key+type 分桶，避免不同 type 混淆）
     * 注：底层 getSystemSetting 已基于 _systemSettingsAll() 提供整表请求级缓存，
     * 本类缓存仅用于跨 type 的类型转换结果短路。set/delete 时一并失效，
     * 同时调用 clearSystemSettingsCache() 让 getSystemSetting 路径同步生效。
     */
    private static array $cache = [];
    
    /**
     * 获取配置值
     * 
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @param string $type 类型转换: int|float|string|bool
     * @return mixed
     */
    public static function get(string $key, $default = null, string $type = 'string')
    {
        // 修复 Q1：缓存键带上 type，避免先 string 后 int 读到错误类型
        $cacheKey = $key . '|' . $type;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }
        
        $value = getSystemSetting($key, $default, $type);
        
        // 缓存结果（包括null值，避免重复查询不存在的key）
        self::$cache[$cacheKey] = $value;
        
        return $value;
    }

    /**
     * 失效本类的内存缓存（由 set/delete/setMultiple 调用，且当 system_settings 被外部
     * 直接写入时也应调用此方法保持一致性）
     */
    public static function clearCache(?string $key = null): void
    {
        if ($key === null) {
            self::$cache = [];
        } else {
            foreach (array_keys(self::$cache) as $k) {
                if (str_starts_with($k, $key . '|')) unset(self::$cache[$k]);
            }
        }
        if (function_exists('clearSystemSettingsCache')) clearSystemSettingsCache();
    }
    
    /**
     * 设置配置值
     * 
     * @param string $key 配置键名
     * @param mixed $value 配置值
     * @param string $type 类型: int|float|string|bool
     * @return bool 是否成功
     */
    public static function set(string $key, $value, string $type = 'string'): bool
    {
        try {
            // 确保DB类已加载
            if (!class_exists('DB', false)) {
                return false;
            }
            
            // 类型转换
            $stringValue = match ($type) {
                'int' => (string)(int)$value,
                'float' => (string)(float)$value,
                'bool' => $value ? '1' : '0',
                default => (string)$value,
            };
            
            // 使用INSERT ... ON DUPLICATE KEY UPDATE语法
            $pdo = DB::connect();
            $stmt = $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value) 
                 VALUES (?, ?) 
                 ON DUPLICATE KEY UPDATE setting_value = ?"
            );
            
            $result = $stmt->execute([$key, $stringValue, $stringValue]);
            
            // 失效缓存（修复 Q1：旧实现仅 self::$cache[$key] 单 key 写入，
            // 不同 type 桶下的旧值不会被清；改为通过 clearCache 统一失效）
            if ($result) {
                self::clearCache($key);
            }

            return $result;
        } catch (Throwable $e) {
            error_log("ConfigCenter::set 失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除配置项
     * 
     * @param string $key 配置键名
     * @return bool 是否成功
     */
    public static function delete(string $key): bool
    {
        try {
            // 确保DB类已加载
            if (!class_exists('DB', false)) {
                return false;
            }
            
            $pdo = DB::connect();
            $stmt = $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?");
            $result = $stmt->execute([$key]);
            
            // 清除缓存
            if ($result) {
                self::clearCache($key);
            }

            return $result;
        } catch (Throwable $e) {
            error_log("ConfigCenter::delete 失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查配置项是否存在
     * 
     * @param string $key 配置键名
     * @return bool 是否存在
     */
    public static function has(string $key): bool
    {
        try {
            // 确保DB类已加载
            if (!class_exists('DB', false)) {
                return false;
            }
            
            $result = DB::fetch(
                "SELECT 1 FROM system_settings WHERE setting_key = ?",
                [$key]
            );
            
            return !empty($result);
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * 获取所有配置项
     * 
     * @return array 配置数组 [key => value]
     */
    public static function all(): array
    {
        try {
            // 确保DB类已加载
            if (!class_exists('DB', false)) {
                return [];
            }
            
            $rows = DB::fetchAll("SELECT setting_key, setting_value FROM system_settings");
            $result = [];
            
            foreach ($rows as $row) {
                $result[$row['setting_key']] = $row['setting_value'];
            }
            
            return $result;
        } catch (Throwable $e) {
            return [];
        }
    }
    
    /**
     * 批量设置配置项
     *
     * @param array $configs 配置数组 [key => value]
     * @return bool 是否全部成功
     */
    public static function setMultiple(array $configs): bool
    {
        try {
            // 确保DB类已加载
            if (!class_exists('DB', false)) {
                return false;
            }

            $pdo = DB::connect();
            $stmt = $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?"
            );

            // 审计修复(2026-07-19 H-中3)：用 DB::beginTransaction 替代 raw PDO，
            // 避免外层已有事务时 beginTransaction() 抛 PDOException 进入 catch、
            // catch 内 $pdo->inTransaction() 为真（外层事务未结束）→ rollBack() 误回滚调用方事务。
            $ownTx = DB::beginTransaction();

            foreach ($configs as $key => $value) {
                $stringValue = (string)$value;
                if (!$stmt->execute([$key, $stringValue, $stringValue])) {
                    if ($ownTx) DB::rollBack();
                    return false;
                }
            }

            if ($ownTx) DB::commit();
            // 修复 Q1：批量写入后整体失效本类缓存与 system_settings 全表缓存
            self::clearCache();
            return true;
        } catch (Throwable $e) {
            error_log("ConfigCenter::setMultiple 失败: " . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // 小说级配置（审计修复 P2-12，2026-07-12）
    //
    // 背景：原 ConfigCenter::set/get 操作的是全局 system_settings 表，
    // 当某个小说的 Agent 调整参数（如爽点密度）后会污染其他小说。
    // 这里新增 novel-scoped 读写，readers 优先取 novel-scoped，
    // 找不到再回退到全局 system_settings，最后回退到 default。
    // ============================================================

    /**
     * 读取小说级配置，找不到时回退到全局 system_settings，再回退到 default
     *
     * @param int $novelId
     * @param string $key
     * @param mixed $default
     * @param string $type int|float|string|bool
     * @return mixed
     */
    public static function getNovel(int $novelId, string $key, $default = null, string $type = 'string')
    {
        if ($novelId <= 0) {
            return self::get($key, $default, $type);
        }
        try {
            $row = DB::fetch(
                'SELECT setting_value FROM novel_settings WHERE novel_id = ? AND setting_key = ?',
                [$novelId, $key]
            );
            if ($row) {
                return self::castValue($row['setting_value'], $type);
            }
        } catch (\Throwable $e) {
            error_log('ConfigCenter::getNovel 失败: ' . $e->getMessage());
        }
        // 回退到全局
        return self::get($key, $default, $type);
    }

    /**
     * 写入小说级配置
     *
     * @param int $novelId
     * @param string $key
     * @param mixed $value
     * @param string $type int|float|string|bool
     * @return bool
     */
    public static function setNovel(int $novelId, string $key, $value, string $type = 'string'): bool
    {
        if ($novelId <= 0) return false;
        try {
            $stringValue = self::castToString($value, $type);
            DB::execute(
                "INSERT INTO novel_settings (novel_id, setting_key, setting_value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?",
                [$novelId, $key, $stringValue, $stringValue]
            );
            return true;
        } catch (\Throwable $e) {
            error_log('ConfigCenter::setNovel 失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 类型转换：字符串 → 目标类型
     */
    private static function castValue(string $raw, string $type)
    {
        return match ($type) {
            'int'    => (int)$raw,
            'float'  => (float)$raw,
            'bool'   => (bool)$raw,
            default  => (string)$raw,
        };
    }

    /**
     * 类型转换：值 → 存储字符串
     */
    private static function castToString($value, string $type): string
    {
        return match ($type) {
            'int'    => (string)(int)$value,
            'float'  => (string)(float)$value,
            'bool'   => $value ? '1' : '0',
            default  => (string)$value,
        };
    }
}
