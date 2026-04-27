-- phpMyAdmin SQL Dump
-- version 5.0.4
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2026-04-23 21:08:25
-- 服务器版本： 5.7.44-log
-- PHP 版本： 7.3.32

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `ai_novel`
--

-- --------------------------------------------------------

--
-- 表的结构 `ai_models`
--

CREATE TABLE `ai_models` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模型名称',
  `api_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'API地址',
  `api_key` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'API密钥',
  `model_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模型标识符',
  `max_tokens` int(11) NOT NULL DEFAULT '4096',
  `temperature` float NOT NULL DEFAULT '0.8',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `embedding_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用Embedding模型',
  `thinking_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用深度思考(Thinking)',
  `can_embed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '此API端点是否可调embedding',
  `embedding_model_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'embedding模型名',
  `embedding_dim` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'embedding向量维度',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `arc_summaries`
--

CREATE TABLE `arc_summaries` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `arc_index` int(11) NOT NULL COMMENT '弧段编号，从1开始',
  `chapter_from` int(11) NOT NULL COMMENT '起始章节',
  `chapter_to` int(11) NOT NULL COMMENT '结束章节',
  `summary` text COLLATE utf8mb4_unicode_ci COMMENT '200字弧段故事线摘要',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `book_analyses`
--

CREATE TABLE `book_analyses` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '书名',
  `author` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '作者',
  `genre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '类型',
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分析结果(Markdown)',
  `source_text` mediumtext COLLATE utf8mb4_unicode_ci COMMENT '原始章节文本',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='拆书分析表';

-- --------------------------------------------------------

--
-- 表的结构 `chapters`
--

CREATE TABLE `chapters` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `chapter_number` int(11) NOT NULL COMMENT '章节序号',
  `title` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `outline` text COLLATE utf8mb4_unicode_ci COMMENT '章节大纲',
  `key_points` text COLLATE utf8mb4_unicode_ci COMMENT '关键情节点(JSON)',
  `hook` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '结尾钩子',
  `hook_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '钩子六式类型',
  `cool_point_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '爽点类型',
  `opening_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '开篇五式类型',
  `pacing` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '中' COMMENT '节奏：快/中/慢',
  `suspense` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '无' COMMENT '悬念：有/无',
  `quality_score` decimal(3,1) DEFAULT NULL COMMENT '质量评分(0-100)',
  `gate_results` json DEFAULT NULL COMMENT '五关检测结果',
  `synopsis_id` int(10) UNSIGNED DEFAULT NULL COMMENT '章节简介ID',
  `content` longtext COLLATE utf8mb4_unicode_ci COMMENT '章节正文',
  `words` int(11) NOT NULL DEFAULT '0',
  `status` enum('pending','outlined','writing','completed','skipped','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `chapter_summary` text COLLATE utf8mb4_unicode_ci COMMENT 'AI生成的章节摘要，供续写参考',
  `used_tropes` text COLLATE utf8mb4_unicode_ci COMMENT '本章已用意象/场景(JSON)，近5章规避',
  `retry_count` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '写作重试次数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `chapter_synopses`
--

CREATE TABLE `chapter_synopses` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `chapter_number` int(11) NOT NULL,
  `synopsis` text COLLATE utf8mb4_unicode_ci COMMENT '章节简介200-300字',
  `scene_breakdown` json DEFAULT NULL COMMENT '场景分解',
  `dialogue_beats` json DEFAULT NULL COMMENT '对话要点',
  `sensory_details` json DEFAULT NULL COMMENT '感官细节',
  `pacing` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '节奏：快/中/慢',
  `cliffhanger` text COLLATE utf8mb4_unicode_ci COMMENT '结尾悬念',
  `foreshadowing` json DEFAULT NULL COMMENT '本章埋下的伏笔',
  `callbacks` json DEFAULT NULL COMMENT '呼应前文的点',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `chapter_versions`
--

CREATE TABLE `chapter_versions` (
  `id` int(11) NOT NULL,
  `chapter_id` int(11) NOT NULL,
  `version` int(11) NOT NULL DEFAULT '1',
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `outline` text COLLATE utf8mb4_unicode_ci,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `words` int(11) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='章节版本快照表';

-- --------------------------------------------------------

--
-- 表的结构 `character_cards`
--

CREATE TABLE `character_cards` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '人物名',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '当前职务/称号',
  `status` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '当前处境一句话',
  `alive` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否存活',
  `attributes` json DEFAULT NULL COMMENT '扩展属性:等级/能力/关系等',
  `last_updated_chapter` int(10) UNSIGNED DEFAULT NULL COMMENT '最近一次被哪一章更新',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='人物状态卡片表';

-- --------------------------------------------------------

--
-- 表的结构 `character_card_history`
--

CREATE TABLE `character_card_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `card_id` int(10) UNSIGNED NOT NULL,
  `chapter_number` int(10) UNSIGNED NOT NULL,
  `field_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_value` text COLLATE utf8mb4_unicode_ci,
  `new_value` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='人物卡片变更历史表';

-- --------------------------------------------------------

--
-- 表的结构 `consistency_logs`
--

CREATE TABLE `consistency_logs` (
  `id` int(11) NOT NULL,
  `novel_id` int(11) NOT NULL,
  `chapter_number` int(11) NOT NULL,
  `check_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issues` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='一致性检测日志表';

-- --------------------------------------------------------

--
-- 表的结构 `foreshadowing_items`
--

CREATE TABLE `foreshadowing_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '伏笔内容',
  `planted_chapter` int(10) UNSIGNED NOT NULL COMMENT '埋设章节',
  `deadline_chapter` int(10) UNSIGNED DEFAULT NULL COMMENT '建议回收章节,NULL=无期限',
  `resolved_chapter` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL=未回收',
  `resolved_at` timestamp NULL DEFAULT NULL,
  `embedding` blob COMMENT '向量(用于语义匹配回收)',
  `embedding_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `embedding_updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='伏笔独立表';

-- --------------------------------------------------------

--
-- 表的结构 `memory_atoms`
--

CREATE TABLE `memory_atoms` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `atom_type` enum('character_trait','world_setting','plot_detail','style_preference','constraint','technique','world_state') COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_chapter` int(10) UNSIGNED DEFAULT NULL,
  `confidence` float NOT NULL DEFAULT '0.8',
  `metadata` json DEFAULT NULL,
  `embedding` blob COMMENT '向量,float32 packed',
  `embedding_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `embedding_updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='原子记忆表';

-- --------------------------------------------------------

--
-- 表的结构 `novels`
--

CREATE TABLE `novels` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '书名',
  `genre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '类型',
  `writing_style` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '写作风格',
  `protagonist_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '主角姓名',
  `protagonist_info` text COLLATE utf8mb4_unicode_ci COMMENT '主角信息',
  `plot_settings` text COLLATE utf8mb4_unicode_ci COMMENT '情节设定',
  `world_settings` text COLLATE utf8mb4_unicode_ci COMMENT '世界设定',
  `extra_settings` text COLLATE utf8mb4_unicode_ci COMMENT '其他设定',
  `target_chapters` int(11) NOT NULL DEFAULT '100' COMMENT '目标总章数',
  `chapter_words` int(11) NOT NULL DEFAULT '2000' COMMENT '每章目标字数',
  `model_id` int(10) UNSIGNED DEFAULT NULL COMMENT '使用的模型',
  `has_story_outline` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已生成全书故事大纲',
  `optimized_chapter` int(11) NOT NULL DEFAULT '0' COMMENT '大纲优化进度（最后优化的章节号）',
  `status` enum('draft','writing','paused','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `current_chapter` int(11) NOT NULL DEFAULT '0' COMMENT '已写章数',
  `total_words` int(11) NOT NULL DEFAULT '0' COMMENT '总字数',
  `cancel_flag` tinyint(1) NOT NULL DEFAULT '0' COMMENT '写作取消标志',
  `daemon_write` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用挂机写作',
  `cover_color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#6366f1',
  `style_vector` text COLLATE utf8mb4_unicode_ci COMMENT '四维风格向量(JSON)',
  `ref_author` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '参考作者',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `novel_characters`
--

CREATE TABLE `novel_characters` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色名',
  `alias` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '别名/绰号',
  `role_type` enum('protagonist','major','minor') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'minor' COMMENT '角色类型',
  `role_template` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other' COMMENT '功能模板:mentor/opponent/romantic/brother/protagonist/other',
  `gender` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '性别',
  `appearance` text COLLATE utf8mb4_unicode_ci COMMENT '外貌特征',
  `personality` text COLLATE utf8mb4_unicode_ci COMMENT '性格特点',
  `background` text COLLATE utf8mb4_unicode_ci COMMENT '背景故事',
  `abilities` text COLLATE utf8mb4_unicode_ci COMMENT '能力/特长',
  `relationships` json DEFAULT NULL COMMENT '人物关系',
  `first_appear` int(10) UNSIGNED DEFAULT NULL COMMENT '首次出场章节',
  `last_appear` int(10) UNSIGNED DEFAULT NULL COMMENT '最后出场章节',
  `appear_count` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '出场次数',
  `first_chapter` int(11) DEFAULT NULL COMMENT '首次出场章节（界面字段）',
  `climax_chapter` int(11) DEFAULT NULL COMMENT '预计高潮/退场章节',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT '备注',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色库';

-- --------------------------------------------------------

--
-- 表的结构 `novel_embeddings`
--

CREATE TABLE `novel_embeddings` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `source_type` enum('character','worldbuilding','plot','style','chapter') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '来源类型',
  `source_id` int(10) UNSIGNED NOT NULL COMMENT '来源ID',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '原始文本（用于展示）',
  `embedding_blob` longblob COMMENT 'float32 向量二进制',
  `embedding_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '向量模型名',
  `embedding_updated_at` timestamp NULL DEFAULT NULL COMMENT '向量更新时间',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='向量存储表';

-- --------------------------------------------------------

--
-- 表的结构 `novel_plots`
--

CREATE TABLE `novel_plots` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `chapter_from` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '起始章节',
  `chapter_to` int(10) UNSIGNED DEFAULT NULL COMMENT '结束章节',
  `event_type` enum('main','side','foreshadowing','callback') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main' COMMENT '事件类型',
  `foreshadow_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '伏笔类型:character/item/speech/faction/realm/identity',
  `expected_payoff` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '预期回收方式',
  `deadline_chapter` int(10) UNSIGNED DEFAULT NULL COMMENT '建议回收章节',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标题',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '描述',
  `characters` json DEFAULT NULL COMMENT '涉及角色',
  `status` enum('planted','active','resolving','resolved','abandoned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '状态',
  `importance` tinyint(4) NOT NULL DEFAULT '3' COMMENT '重要程度1-5',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT '备注',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='情节库';

--
-- 转存表中的数据 `novel_plots`
--

INSERT INTO `novel_plots` (`id`, `novel_id`, `chapter_from`, `chapter_to`, `event_type`, `foreshadow_type`, `expected_payoff`, `deadline_chapter`, `title`, `description`, `characters`, `status`, `importance`, `notes`, `created_at`, `updated_at`) VALUES
(511, 2, 133, NULL, 'main', NULL, NULL, NULL, '雨林祭坛危机与地脉能量抽取', '“导师”在雨林地下祭坛启动装置，暴力抽取地脉能量以开启星门，导致地质结构濒临崩坏，并将丁川和玛利亚作为祭品目标。', NULL, 'active', 5, NULL, '2026-04-23 13:06:59', '2026-04-23 13:06:59'),
(512, 2, 133, NULL, 'main', NULL, NULL, NULL, '玉印觉醒与丁川血脉共鸣', '在危机中，丁川的玉印被地脉能量激活，吸收并引导能量进入丁川体内，改造其身体，使其获得感知和引导地脉能量的能力，并揭示其与“星门守护者”的关联。', NULL, 'active', 5, NULL, '2026-04-23 13:07:00', '2026-04-23 13:07:00'),
(513, 2, 133, NULL, 'main', NULL, NULL, NULL, '丁川修复地脉与摧毁祭坛', '丁川利用玉印引导地脉能量，修复被破坏的能量平衡，并将能量逆流导入祭坛装置，导致其过载爆炸被毁，成功阻止了星门开启和地质灾难。', NULL, 'active', 5, NULL, '2026-04-23 13:07:00', '2026-04-23 13:07:00'),
(514, 2, 133, NULL, 'foreshadowing', NULL, NULL, NULL, '“导师”逃脱与黄石公园新线索', '在祭坛爆炸前，“导师”被残存守卫救走。摧毁的祭坛星图显示出新的能量轨迹，指向正在苏醒的黄石公园巨型能量节点，暗示危机并未结束，且转移至更危险的区域。', NULL, 'active', 5, NULL, '2026-04-23 13:07:00', '2026-04-23 13:07:00');

-- --------------------------------------------------------

--
-- 表的结构 `novel_state`
--

CREATE TABLE `novel_state` (
  `novel_id` int(10) UNSIGNED NOT NULL,
  `story_momentum` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '当前故事势能/悬念一句话',
  `current_arc_summary` text COLLATE utf8mb4_unicode_ci COMMENT '最近一个活跃弧段的摘要',
  `last_ingested_chapter` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最近成功记忆化的章节号',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说状态表';

-- --------------------------------------------------------

--
-- 表的结构 `novel_style`
--

CREATE TABLE `novel_style` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other' COMMENT '类别',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '详细风格说明',
  `vec_style` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '文风:concise/ornate/humorous',
  `vec_pacing` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '节奏:fast/slow/alternating',
  `vec_emotion` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '情感:passionate/warm/dark',
  `vec_intellect` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '智慧:strategy/power/balanced',
  `ref_author` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '参考作者',
  `keywords` text COLLATE utf8mb4_unicode_ci COMMENT '逗号分隔高频词',
  `examples` json DEFAULT NULL COMMENT '示例片段',
  `usage_count` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '使用次数',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT '备注',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风格库';

-- --------------------------------------------------------

--
-- 表的结构 `novel_worldbuilding`
--

CREATE TABLE `novel_worldbuilding` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `category` enum('location','faction','rule','item','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other' COMMENT '类别',
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '描述',
  `attributes` json DEFAULT NULL COMMENT '扩展属性',
  `related_chapters` json DEFAULT NULL COMMENT '相关章节',
  `importance` tinyint(4) NOT NULL DEFAULT '3' COMMENT '重要程度1-5',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT '备注',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='世界观库';

-- --------------------------------------------------------

--
-- 表的结构 `story_outlines`
--

CREATE TABLE `story_outlines` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `story_arc` text COLLATE utf8mb4_unicode_ci COMMENT '故事主线发展脉络',
  `act_division` json DEFAULT NULL COMMENT '三幕划分',
  `major_turning_points` json DEFAULT NULL COMMENT '重大转折点',
  `character_arcs` json DEFAULT NULL COMMENT '人物成长轨迹',
  `world_evolution` text COLLATE utf8mb4_unicode_ci COMMENT '世界观演变',
  `recurring_motifs` json DEFAULT NULL COMMENT '全书重复意象',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置表';

--
-- 转存表中的数据 `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('daemon_token', 'a3d4c4d03e3a2fb32cc0586f1ff5a0f15ea550c1a9bf13d1', '2026-04-23 05:18:02'),
('ws_auto_write_interval', '2', '2026-04-23 05:17:25'),
('ws_chapter_words', '2000', '2026-04-23 05:17:25'),
('ws_cool_point_density_target', '0.9', '2026-04-23 09:49:39'),
('ws_cool_point_hunger_threshold', '0.6', '2026-04-23 05:17:25'),
('ws_double_coolpoint_gap', '3', '2026-04-23 05:17:25'),
('ws_embedding_top_k', '5', '2026-04-23 05:17:25'),
('ws_foreshadowing_lookback', '10', '2026-04-23 05:17:25'),
('ws_max_tokens_chapter', '8192', '2026-04-23 05:17:25'),
('ws_max_tokens_outline', '4096', '2026-04-23 05:17:25'),
('ws_memory_lookback', '5', '2026-04-23 05:17:25'),
('ws_outline_batch', '20', '2026-04-23 05:17:25'),
('ws_quality_check_enabled', '1', '2026-04-23 05:17:25'),
('ws_quality_min_score', '6', '2026-04-23 09:49:39'),
('ws_segment_ratio_climax', '35', '2026-04-23 05:17:25'),
('ws_segment_ratio_hook', '15', '2026-04-23 05:17:25'),
('ws_segment_ratio_rising', '30', '2026-04-23 05:17:25'),
('ws_segment_ratio_setup', '20', '2026-04-23 05:17:25'),
('ws_temperature_chapter', '0.8', '2026-04-23 05:17:25'),
('ws_temperature_outline', '0.3', '2026-04-23 05:17:25');

-- --------------------------------------------------------

--
-- 表的结构 `volume_outlines`
--

CREATE TABLE `volume_outlines` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `volume_number` int(11) NOT NULL COMMENT '卷号，从1开始',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '卷标题',
  `summary` text COLLATE utf8mb4_unicode_ci COMMENT '卷概要（300-500字）',
  `theme` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '本卷主题',
  `start_chapter` int(11) NOT NULL COMMENT '起始章节号',
  `end_chapter` int(11) NOT NULL COMMENT '结束章节号',
  `key_events` json DEFAULT NULL COMMENT '本卷关键事件列表',
  `character_focus` json DEFAULT NULL COMMENT '本卷重点描写的人物',
  `conflict` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '本卷核心冲突',
  `resolution` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '本卷解决方式',
  `foreshadowing` json DEFAULT NULL COMMENT '本卷埋下的伏笔',
  `status` enum('pending','generated','revised') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `writing_logs`
--

CREATE TABLE `writing_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `novel_id` int(10) UNSIGNED NOT NULL,
  `chapter_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转储表的索引
--

--
-- 表的索引 `ai_models`
--
ALTER TABLE `ai_models`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `arc_summaries`
--
ALTER TABLE `arc_summaries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_arc` (`novel_id`,`arc_index`);

--
-- 表的索引 `book_analyses`
--
ALTER TABLE `book_analyses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created` (`created_at`);

--
-- 表的索引 `chapters`
--
ALTER TABLE `chapters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_novel_chapter` (`novel_id`,`chapter_number`),
  ADD KEY `idx_novel_status` (`novel_id`,`status`);

--
-- 表的索引 `chapter_synopses`
--
ALTER TABLE `chapter_synopses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_chapter` (`novel_id`,`chapter_number`);

--
-- 表的索引 `chapter_versions`
--
ALTER TABLE `chapter_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_chapter_version` (`chapter_id`,`version`),
  ADD KEY `idx_chapter_id` (`chapter_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- 表的索引 `character_cards`
--
ALTER TABLE `character_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_novel_name` (`novel_id`,`name`),
  ADD KEY `idx_novel` (`novel_id`);

--
-- 表的索引 `character_card_history`
--
ALTER TABLE `character_card_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_chapter` (`card_id`,`chapter_number`),
  ADD KEY `idx_field` (`card_id`,`field_name`);

--
-- 表的索引 `consistency_logs`
--
ALTER TABLE `consistency_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_novel_id` (`novel_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- 表的索引 `foreshadowing_items`
--
ALTER TABLE `foreshadowing_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_novel_unresolved` (`novel_id`,`resolved_chapter`),
  ADD KEY `idx_deadline` (`novel_id`,`deadline_chapter`),
  ADD KEY `idx_embedding_null` (`novel_id`,`embedding_updated_at`);
ALTER TABLE `foreshadowing_items` ADD FULLTEXT KEY `ft_description` (`description`);

--
-- 表的索引 `memory_atoms`
--
ALTER TABLE `memory_atoms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_novel_type` (`novel_id`,`atom_type`),
  ADD KEY `idx_chapter` (`source_chapter`),
  ADD KEY `idx_embedding_null` (`novel_id`,`embedding_updated_at`);
ALTER TABLE `memory_atoms` ADD FULLTEXT KEY `ft_content` (`content`);

--
-- 表的索引 `novels`
--
ALTER TABLE `novels`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_updated` (`updated_at`);

--
-- 表的索引 `novel_characters`
--
ALTER TABLE `novel_characters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_novel_name` (`novel_id`,`name`),
  ADD KEY `idx_novel` (`novel_id`),
  ADD KEY `idx_role_type` (`novel_id`,`role_type`),
  ADD KEY `idx_template` (`novel_id`,`role_template`);

--
-- 表的索引 `novel_embeddings`
--
ALTER TABLE `novel_embeddings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_source` (`novel_id`,`source_type`,`source_id`),
  ADD KEY `idx_novel_type` (`novel_id`,`source_type`),
  ADD KEY `idx_embedding_null` (`novel_id`,`embedding_updated_at`);

--
-- 表的索引 `novel_plots`
--
ALTER TABLE `novel_plots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_novel_title_type` (`novel_id`,`title`,`event_type`),
  ADD KEY `idx_novel` (`novel_id`),
  ADD KEY `idx_chapter` (`novel_id`,`chapter_from`,`chapter_to`),
  ADD KEY `idx_event_type` (`novel_id`,`event_type`),
  ADD KEY `idx_status` (`novel_id`,`status`);

--
-- 表的索引 `novel_state`
--
ALTER TABLE `novel_state`
  ADD PRIMARY KEY (`novel_id`);

--
-- 表的索引 `novel_style`
--
ALTER TABLE `novel_style`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_novel_name_cat` (`novel_id`,`name`,`category`),
  ADD KEY `idx_novel` (`novel_id`),
  ADD KEY `idx_usage` (`novel_id`,`usage_count`);

--
-- 表的索引 `novel_worldbuilding`
--
ALTER TABLE `novel_worldbuilding`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_novel_name_cat` (`novel_id`,`name`,`category`),
  ADD KEY `idx_novel` (`novel_id`),
  ADD KEY `idx_category` (`novel_id`,`category`),
  ADD KEY `idx_importance` (`novel_id`,`importance`);

--
-- 表的索引 `story_outlines`
--
ALTER TABLE `story_outlines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `novel_id` (`novel_id`);

--
-- 表的索引 `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- 表的索引 `volume_outlines`
--
ALTER TABLE `volume_outlines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_volume` (`novel_id`,`volume_number`),
  ADD KEY `idx_novel_volume` (`novel_id`,`volume_number`),
  ADD KEY `idx_chapter_range` (`start_chapter`,`end_chapter`);

--
-- 表的索引 `writing_logs`
--
ALTER TABLE `writing_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_novel` (`novel_id`),
  ADD KEY `idx_novel_created` (`novel_id`,`created_at`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `ai_models`
--
ALTER TABLE `ai_models`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `arc_summaries`
--
ALTER TABLE `arc_summaries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=153;

--
-- 使用表AUTO_INCREMENT `book_analyses`
--
ALTER TABLE `book_analyses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `chapters`
--
ALTER TABLE `chapters`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=801;

--
-- 使用表AUTO_INCREMENT `chapter_synopses`
--
ALTER TABLE `chapter_synopses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=701;

--
-- 使用表AUTO_INCREMENT `chapter_versions`
--
ALTER TABLE `chapter_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用表AUTO_INCREMENT `character_cards`
--
ALTER TABLE `character_cards`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=113;

--
-- 使用表AUTO_INCREMENT `character_card_history`
--
ALTER TABLE `character_card_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1513;

--
-- 使用表AUTO_INCREMENT `consistency_logs`
--
ALTER TABLE `consistency_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `foreshadowing_items`
--
ALTER TABLE `foreshadowing_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=356;

--
-- 使用表AUTO_INCREMENT `memory_atoms`
--
ALTER TABLE `memory_atoms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=142;

--
-- 使用表AUTO_INCREMENT `novels`
--
ALTER TABLE `novels`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用表AUTO_INCREMENT `novel_characters`
--
ALTER TABLE `novel_characters`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=166;

--
-- 使用表AUTO_INCREMENT `novel_embeddings`
--
ALTER TABLE `novel_embeddings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2058;

--
-- 使用表AUTO_INCREMENT `novel_plots`
--
ALTER TABLE `novel_plots`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=515;

--
-- 使用表AUTO_INCREMENT `novel_style`
--
ALTER TABLE `novel_style`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=504;

--
-- 使用表AUTO_INCREMENT `novel_worldbuilding`
--
ALTER TABLE `novel_worldbuilding`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=501;

--
-- 使用表AUTO_INCREMENT `story_outlines`
--
ALTER TABLE `story_outlines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用表AUTO_INCREMENT `volume_outlines`
--
ALTER TABLE `volume_outlines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `writing_logs`
--
ALTER TABLE `writing_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1025;

--
-- 限制导出的表
--

--
-- 限制表 `chapter_synopses`
--
ALTER TABLE `chapter_synopses`
  ADD CONSTRAINT `chapter_synopses_ibfk_1` FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE;

--
-- 限制表 `story_outlines`
--
ALTER TABLE `story_outlines`
  ADD CONSTRAINT `story_outlines_ibfk_1` FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE;

--
-- 限制表 `volume_outlines`
--
ALTER TABLE `volume_outlines`
  ADD CONSTRAINT `volume_outlines_ibfk_1` FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
