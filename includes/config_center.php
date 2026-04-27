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
    /** @var array 配置缓存 */
    private static $cache = [];
    
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
        // 检查缓存
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        
        $value = getSystemSetting($key, $default, $type);
        
        // 缓存结果（包括null值，避免重复查询不存在的key）
        self::$cache[$key] = $value;
        
        return $value;
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
            
            // 更新缓存
            if ($result) {
                self::$cache[$key] = $value;
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
                unset(self::$cache[$key]);
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
            
            $pdo->beginTransaction();
            
            foreach ($configs as $key => $value) {
                $stringValue = (string)$value;
                if (!$stmt->execute([$key, $stringValue, $stringValue])) {
                    $pdo->rollBack();
                    return false;
                }
            }
            
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            error_log("ConfigCenter::setMultiple 失败: " . $e->getMessage());
            return false;
        }
    }
}