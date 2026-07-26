<?php
/**
 * functions.php — 入口加载器
 *
 * 历史上此文件承载了全部 1300 行业务逻辑，违反单一职责原则。
 * 现已按职责拆分为 4 个独立文件：
 *
 *   helpers.php  — 纯工具函数（无 DB / AI 依赖）
 *   data.php     — 数据访问层（仅操作数据库）
 *   prompt.php   — Prompt 构建层（组装 AI 请求消息）
 *   memory.php   — AI 记忆层（调用 AI 生成摘要 / 检测冲突）
 *
 * 所有现有 require_once 调用此文件的代码无需修改，保持向后兼容。
 */
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/memory.php';
