-- ============================================
-- Agent自然语言指令注入机制
-- 版本: 003
-- 日期: 2026-04-27
-- 说明: 创建Agent指令表，实现Agent决策到写作流程的直接注入
-- ============================================

-- Agent指令表
CREATE TABLE IF NOT EXISTS `agent_directives` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `novel_id` INT NOT NULL COMMENT '小说ID',
    `apply_from` INT NOT NULL COMMENT '起始章节号（从第几章开始生效）',
    `apply_to` INT NOT NULL COMMENT '失效章节号（到第几章失效）',
    `type` VARCHAR(30) NOT NULL COMMENT '指令类型: quality/strategy/optimization',
    `directive` TEXT NOT NULL COMMENT '自然语言指令内容',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `expires_at` DATETIME COMMENT '过期时间（可选）',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否激活',
    INDEX `idx_novel_chapter` (`novel_id`, `apply_from`, `apply_to`),
    INDEX `idx_type` (`type`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent自然语言指令表';

-- 添加外键约束（如果novels表存在）
-- ALTER TABLE `agent_directives` 
-- ADD CONSTRAINT `fk_directive_novel` FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE;

-- 插入示例指令（用于测试）
-- INSERT INTO `agent_directives` (`novel_id`, `apply_from`, `apply_to`, `type`, `directive`) VALUES
-- (1, 10, 15, 'quality', '本章特别注意：最近5章角色一致性偏低，请重点保持主角口头禅。'),
-- (1, 20, 25, 'strategy', '当前爽点密度不足，建议在本章中段增加一个反转情节。'),
-- (1, 30, 35, 'optimization', '最近章节字数偏长，请控制在3000字左右，避免冗余描写。');