-- ============================================
-- Agent决策机制数据库迁移
-- 版本: 002
-- 日期: 2026-04-27
-- 说明: 创建Agent决策日志和动作日志表
-- ============================================

-- Agent决策日志表
CREATE TABLE IF NOT EXISTS `agent_decision_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agent_type` VARCHAR(50) NOT NULL COMMENT 'Agent类型: writing_strategy, quality_monitor, optimization',
    `decision_data` TEXT COMMENT '决策数据JSON',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    INDEX `idx_agent_type` (`agent_type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent决策日志表';

-- Agent动作日志表
CREATE TABLE IF NOT EXISTS `agent_action_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `novel_id` INT NOT NULL COMMENT '小说ID',
    `agent_type` VARCHAR(50) NOT NULL COMMENT 'Agent类型',
    `action` VARCHAR(100) NOT NULL COMMENT '动作名称',
    `status` VARCHAR(20) NOT NULL COMMENT '执行状态: success, failed, skipped',
    `params` TEXT COMMENT '动作参数JSON',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    INDEX `idx_novel_id` (`novel_id`),
    INDEX `idx_agent_type` (`agent_type`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent动作日志表';

-- Agent性能统计表(可选,用于长期性能监控)
CREATE TABLE IF NOT EXISTS `agent_performance_stats` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agent_type` VARCHAR(50) NOT NULL COMMENT 'Agent类型',
    `stat_date` DATE NOT NULL COMMENT '统计日期',
    `decision_count` INT DEFAULT 0 COMMENT '决策次数',
    `action_count` INT DEFAULT 0 COMMENT '动作次数',
    `success_count` INT DEFAULT 0 COMMENT '成功次数',
    `failed_count` INT DEFAULT 0 COMMENT '失败次数',
    `avg_decision_time_ms` FLOAT DEFAULT 0 COMMENT '平均决策时间(毫秒)',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    UNIQUE KEY `uk_agent_date` (`agent_type`, `stat_date`),
    INDEX `idx_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent性能统计表';

-- 插入示例数据(用于测试)
-- INSERT INTO `agent_decision_logs` (`agent_type`, `decision_data`, `created_at`) VALUES
-- ('writing_strategy', '{"word_count_adjustment": {"action": "increase_tolerance", "value": 0.15}}', NOW()),
-- ('quality_monitor', '{"issues": [{"type": "character_inconsistency", "severity": "high"}]}', NOW());

-- 添加外键约束(如果novels表存在)
-- ALTER TABLE `agent_action_logs` 
-- ADD CONSTRAINT `fk_agent_novel` FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE;
