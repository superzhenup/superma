<?php
/**
 * 心跳助手 - 在长时间阻塞操作期间保持连接活跃
 * 
 * 使用方法：
 * 1. 在开始阻塞操作前调用 HeartbeatHelper::start()
 * 2. 在阻塞操作完成后调用 HeartbeatHelper::stop()
 */

class HeartbeatHelper {
    private static $process = null;
    private static $pipes = [];
    
    /**
     * 启动心跳进程
     * @param int $interval 心跳间隔（秒）
     * @return bool 是否成功启动
     */
    public static function start(int $interval = 10): bool {
        // 如果已经在运行，先停止
        if (self::$process !== null) {
            self::stop();
        }
        
        // 创建子进程发送心跳
        $cmd = sprintf(
            'php -r \'while (true) { echo "event: heartbeat\ndata: " . json_encode(["time" => time(), "msg" => "keep-alive"]) . "\n\n"; flush(); sleep(%d); }\'',
            $interval
        );
        
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout - 心跳输出
            2 => ['pipe', 'w'],  // stderr
        ];
        
        self::$process = proc_open($cmd, $descriptorSpec, self::$pipes);
        
        if (!is_resource(self::$process)) {
            error_log("HeartbeatHelper: Failed to start heartbeat process");
            return false;
        }
        
        // 设置非阻塞模式
        stream_set_blocking(self::$pipes[1], false);
        
        return true;
    }
    
    /**
     * 停止心跳进程
     */
    public static function stop(): void {
        if (self::$process !== null) {
            // 关闭管道
            foreach (self::$pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            
            // 终止进程
            $status = proc_get_status(self::$process);
            if ($status['running']) {
                proc_terminate(self::$process);
            }
            proc_close(self::$process);
            
            self::$process = null;
            self::$pipes = [];
        }
    }
    
    /**
     * 检查心跳进程是否正在运行
     */
    public static function isRunning(): bool {
        if (self::$process === null) {
            return false;
        }
        
        $status = proc_get_status(self::$process);
        return $status['running'];
    }
    
    /**
     * 简化版：使用文件锁实现心跳（更可靠）
     * 
     * @param callable $sendHeartbeat 发送心跳的回调函数
     * @param int $interval 心跳间隔（秒）
     * @return string 返回锁文件路径，用于后续停止
     */
    public static function startSimple(callable $sendHeartbeat, int $interval = 10): string {
        // 创建临时锁文件
        $lockFile = sys_get_temp_dir() . '/heartbeat_' . uniqid() . '.lock';
        touch($lockFile);
        
        // 注册心跳回调
        $GLOBALS['heartbeat_callback'] = $sendHeartbeat;
        $GLOBALS['heartbeat_interval'] = $interval;
        $GLOBALS['heartbeat_lock_file'] = $lockFile;
        
        // 注册停止函数（在脚本结束时自动调用）
        register_shutdown_function(function() {
            self::stopSimple();
        });
        
        return $lockFile;
    }
    
    /**
     * 停止简化版心跳
     */
    public static function stopSimple(): void {
        // 删除锁文件
        if (isset($GLOBALS['heartbeat_lock_file']) && file_exists($GLOBALS['heartbeat_lock_file'])) {
            unlink($GLOBALS['heartbeat_lock_file']);
        }
    }
    
    /**
     * 在 curl 阻塞期间手动发送心跳
     * 这个方法需要在 CURLOPT_PROGRESSFUNCTION 中调用
     */
    public static function sendHeartbeatIfNecessary(): void {
        static $lastTime = 0;
        $now = time();
        $interval = $GLOBALS['heartbeat_interval'] ?? CFG_SSE_HEARTBEAT;
        
        if ($now - $lastTime >= $interval) {
            if (isset($GLOBALS['heartbeat_callback']) && is_callable($GLOBALS['heartbeat_callback'])) {
                call_user_func($GLOBALS['heartbeat_callback']);
            }
            $lastTime = $now;
        }
    }
}
