<?php
/**
 * Agent基类
 * 
 * 提供Agent决策的通用功能:
 * - 决策日志记录
 * - 历史决策查询
 * - 决策执行跟踪
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

abstract class BaseAgent
{
    /** @var string Agent类型标识 */
    protected $agentType;
    
    /** @var int 小说ID（0=全局） */
    protected $novelId = 0;
    
    /** @var array 内存中的决策历史 */
    protected $decisionHistory = [];
    
    /** @var int 决策历史最大保留数 */
    protected $maxHistorySize = 100;
    
    /**
     * 构造函数
     * 
     * @param string $agentType Agent类型
     * @param int $novelId 小说ID（0=全局）
     */
    public function __construct(string $agentType, int $novelId = 0)
    {
        $this->agentType = $agentType;
        $this->novelId   = $novelId;
    }
    
    /**
     * 子类必须实现的决策方法
     * 
     * @param array $context 决策上下文
     * @return array 决策结果
     */
    abstract public function decide(array $context): array;
    
    /**
     * 记录决策日志
     * 
     * @param array $decision 决策数据
     * @return bool 是否记录成功
     */
    protected function logDecision(array $decision): bool
    {
        $logEntry = [
            'agent_type' => $this->agentType,
            'decision' => $decision,
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage' => memory_get_usage(true),
        ];
        
        // 保存到内存历史
        $this->decisionHistory[] = $logEntry;
        
        // 限制历史大小
        if (count($this->decisionHistory) > $this->maxHistorySize) {
            array_shift($this->decisionHistory);
        }
        
        // 检查DB类是否存在
        if (!class_exists('DB')) {
            error_log("Agent决策日志记录失败: DB类不存在");
            return false;
        }
        
        // 写入数据库
        try {
            // 检查JSON编码是否成功
            $jsonData = json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($jsonData === false) {
                error_log("Agent决策日志JSON编码失败: " . json_last_error_msg());
                $jsonData = '{}';
            }
            
            $result = DB::insert('agent_decision_logs', [
                'novel_id'   => $this->novelId,
                'agent_type' => $this->agentType,
                'decision_data' => $jsonData,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            return $result !== false;
        } catch (\Throwable $e) {
            error_log("Agent决策日志记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取历史决策(从数据库)
     * 
     * @param int $limit 限制数量
     * @return array 历史决策列表
     */
    public function getDecisionHistory(int $limit = 10): array
    {
        // 检查DB类是否存在
        if (!class_exists('DB')) {
            error_log("获取Agent决策历史失败: DB类不存在");
            return [];
        }
        
        try {
            $logs = DB::fetchAll(
                'SELECT * FROM agent_decision_logs 
                 WHERE agent_type = ? AND novel_id = ?
                 ORDER BY created_at DESC 
                 LIMIT ?',
                [$this->agentType, $this->novelId, $limit]
            );
            
            // 检查返回值是否有效
            if (empty($logs) || !is_array($logs)) {
                return [];
            }
            
            // 解析JSON数据
            foreach ($logs as &$log) {
                $log['decision_data'] = json_decode($log['decision_data'], true);
            }
            
            return $logs;
        } catch (\Throwable $e) {
            error_log("获取Agent决策历史失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取内存中的决策历史
     * 
     * @param int $limit 限制数量
     * @return array 内存中的决策历史
     */
    public function getMemoryHistory(int $limit = 10): array
    {
        $history = array_slice($this->decisionHistory, -$limit);
        return array_reverse($history);
    }
    
    /**
     * 清空内存中的决策历史
     * 
     * @return void
     */
    public function clearMemoryHistory(): void
    {
        $this->decisionHistory = [];
    }
    
    /**
     * 记录动作执行日志
     * 
     * @param int $novelId 小说ID
     * @param string $action 动作名称
     * @param string $status 执行状态
     * @param array $params 动作参数
     * @return bool 是否记录成功
     */
    protected function logAction(int $novelId, string $action, string $status, array $params = []): bool
    {
        // 检查DB类是否存在
        if (!class_exists('DB')) {
            error_log("Agent动作日志记录失败: DB类不存在");
            return false;
        }
        
        try {
            // 检查JSON编码是否成功
            $jsonData = json_encode($params, JSON_UNESCAPED_UNICODE);
            if ($jsonData === false) {
                error_log("Agent动作日志JSON编码失败: " . json_last_error_msg());
                $jsonData = '{}';
            }
            
            return DB::insert('agent_action_logs', [
                'novel_id' => $novelId,
                'agent_type' => $this->agentType,
                'action' => $action,
                'status' => $status,
                'params' => $jsonData,
                'created_at' => date('Y-m-d H:i:s'),
            ]) !== false;
        } catch (\Throwable $e) {
            error_log("Agent动作日志记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取Agent统计信息
     * 
     * @param int $hours 统计时间范围(小时)
     * @return array 统计信息
     */
    public function getStatistics(int $hours = 24): array
    {
        // 检查DB类是否存在
        if (!class_exists('DB')) {
            error_log("获取Agent统计信息失败: DB类不存在");
            return [
                'agent_type' => $this->agentType,
                'decision_count' => 0,
                'action_count' => 0,
                'success_rate' => 0,
            ];
        }
        
        try {
            $stats = DB::fetch(
                'SELECT
                    COUNT(*) as decision_count,
                    MIN(created_at) as first_decision,
                    MAX(created_at) as last_decision
                 FROM agent_decision_logs
                 WHERE agent_type = ? AND novel_id = ?
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
                [$this->agentType, $this->novelId, $hours]
            );

            $actionStats = DB::fetch(
                'SELECT
                    COUNT(*) as action_count,
                    SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count
                 FROM agent_action_logs
                 WHERE agent_type = ? AND novel_id = ?
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
                [$this->agentType, $this->novelId, $hours]
            );
            
            // 防止除零错误
            $actionCount = (int)($actionStats['action_count'] ?? 0);
            $successCount = (int)($actionStats['success_count'] ?? 0);
            
            return [
                'agent_type' => $this->agentType,
                'decision_count' => (int)($stats['decision_count'] ?? 0),
                'action_count' => $actionCount,
                'success_rate' => $actionCount > 0 ? $successCount / $actionCount : 0,
                'first_decision' => $stats['first_decision'] ?? null,
                'last_decision' => $stats['last_decision'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log("获取Agent统计信息失败: " . $e->getMessage());
            return [
                'agent_type' => $this->agentType,
                'decision_count' => 0,
                'action_count' => 0,
                'success_rate' => 0,
            ];
        }
    }
    
    /**
     * 验证决策上下文
     * 
     * @param array $context 决策上下文
     * @param array $requiredFields 必需字段
     * @return array [是否有效, 缺失字段列表]
     */
    protected function validateContext(array $context, array $requiredFields): array
    {
        $missing = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($context[$field])) {
                $missing[] = $field;
            }
        }
        
        return [
            'valid' => empty($missing),
            'missing' => $missing,
        ];
    }
    
    /**
     * 获取Agent类型
     * 
     * @return string Agent类型
     */
    public function getAgentType(): string
    {
        return $this->agentType;
    }
    
    /**
     * 批量执行动作
     * 
     * @param int $novelId 小说ID
     * @param array $actions 动作列表
     * @return array 执行结果
     */
    protected function executeActions(int $novelId, array $actions): array
    {
        $results = [];
        
        foreach ($actions as $action) {
            $result = $this->executeSingleAction($novelId, $action);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * 执行单个动作(子类可重写)
     * 
     * @param int $novelId 小说ID
     * @param array $action 动作配置
     * @return array 执行结果
     */
    protected function executeSingleAction(int $novelId, array $action): array
    {
        return [
            'action' => $action['name'] ?? 'unknown',
            'status' => 'skipped',
            'message' => '子类未实现此动作',
        ];
    }
}
