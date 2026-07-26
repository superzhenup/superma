<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * 四种写作模式统一配置
 *
 * 每种模式包含：
 *   mode_key        — 唯一标识
 *   mode_name       — 显示名称
 *   description     — 简短描述
 *   icon            — Bootstrap Icons 图标类
 *   card_class      — CSS 卡片样式类
 *   icon_class      — CSS 图标样式类
 *   cta_color       — CTA 文字颜色
 *   entry_url       — 入口页面路径
 *   required_fields — 必填字段列表
 *   prompt_template — AI 提示词模板（可选，部分模式由独立 API 处理）
 *   handler         — 后端处理方式描述
 *   features        — 功能亮点列表
 *   recommended     — 是否推荐模式
 *   estimated_time  — 预估耗时
 *
 * 扩展新写作模式：在此数组追加一项即可，前端/后端会自动识别。
 */
function getWritingModes(): array {
    return [
        [
            'mode_key'        => 'wizard',
            'mode_name'       => '高阶写作模式',
            'description'     => '引导式向导 · AI 全程陪写',
            'icon'            => 'bi-stars',
            'card_class'      => 'card-wizard',
            'icon_class'      => 'icon-wizard',
            'cta_color'       => '#818cf8',
            'entry_url'       => 'novel_wizard.php',
            'required_fields' => ['title', 'genre', 'idea'],
            'prompt_template' => 'wizard',
            'handler'         => 'api/index.php?route=wizard',
            'features'        => [
                '四阶段可视化创作流程',
                'AI 辅助立项 / 蓝图 / 大纲生成',
                '每步都有数字精灵助手',
                '随时保存，断点续作',
                '适合：想有系统规划的作者',
            ],
            'recommended'     => true,
            'estimated_time'  => '15-20 分钟',
        ],
        [
            'mode_key'        => 'workshop',
            'mode_name'       => '创意工坊',
            'description'     => '一句话 · AI 生成完整框架',
            'icon'            => 'bi-lightbulb',
            'card_class'      => 'card-workshop',
            'icon_class'      => 'icon-workshop',
            'cta_color'       => '#fbbf24',
            'entry_url'       => 'workshop.php',
            'required_fields' => ['idea'],
            'prompt_template' => 'workshop',
            'handler'         => 'api/index.php?route=workshop',
            'features'        => [
                '输入一两句灵感即可启动',
                'AI 自动生成大纲 / 人设 / 世界观',
                '多套框架方案供选择',
                '生成后可自由调整细节',
                '适合：灵感型、想法多的作者',
            ],
            'recommended'     => false,
            'estimated_time'  => '3-5 分钟',
        ],
        [
            'mode_key'        => 'classic',
            'mode_name'       => '传统新建',
            'description'     => '已有构思 · 直接填表动笔',
            'icon'            => 'bi-file-earmark-plus',
            'card_class'      => 'card-classic',
            'icon_class'      => 'icon-classic',
            'cta_color'       => '#2dd4bf',
            'entry_url'       => 'create.php',
            'required_fields' => ['title'],
            'prompt_template' => null,
            'handler'         => 'create.php',
            'features'        => [
                '极简表单，30 秒完成创建',
                '书名 / 类型 / 简介一次填好',
                '直接进入章节编辑流程',
                '支持随时补充世界观设定',
                '适合：有完整构思的老手',
            ],
            'recommended'     => false,
            'estimated_time'  => '1 分钟',
        ],
        [
            'mode_key'        => 'import',
            'mode_name'       => '导入续写',
            'description'     => '旧稿导入 · AI 接力续写',
            'icon'            => 'bi-file-earmark-excel',
            'card_class'      => 'card-import',
            'icon_class'      => 'icon-import',
            'cta_color'       => '#4ade80',
            'entry_url'       => 'import_novel.php',
            'required_fields' => ['file'],
            'prompt_template' => null,
            'handler'         => 'api/index.php?route=novel_import',
            'features'        => [
                '支持 JSON / Excel 格式导入',
                '浏览器本地解析，文件不上传',
                '分批写入，断点自动续传',
                '可选：AI 生成大纲 / 人物卡',
                '适合：已有存稿想用 AI 续写的作者',
            ],
            'recommended'     => false,
            'estimated_time'  => '按需',
        ],
        [
            'mode_key'        => 'short_story',
            'mode_name'       => '短篇创作',
            'description'     => '快速创作 · 3000字左右短篇',
            'icon'            => 'bi-journal-text',
            'card_class'      => 'card-short',
            'icon_class'      => 'icon-short',
            'cta_color'       => '#ec4899',
            'entry_url'       => 'short_create.php',
            'required_fields' => ['title', 'genre', 'premise'],
            'prompt_template' => 'short_story',
            'handler'         => 'api/index.php?route=short_story',
            'features'        => [
                '6-8节拍结构快速创作',
                'AI辅助生成故事梗概和节拍',
                '一键生成完整初稿',
                '质量检测与优化建议',
                '适合：快速完成短篇故事',
            ],
            'recommended'     => false,
            'estimated_time'  => '10-15 分钟',
        ],
    ];
}

/**
 * 根据 mode_key 获取单个模式配置
 */
function getWritingMode(string $modeKey): ?array {
    $modes = getWritingModes();
    foreach ($modes as $mode) {
        if ($mode['mode_key'] === $modeKey) {
            return $mode;
        }
    }
    return null;
}

/**
 * 获取所有模式的 mode_key 列表（用于后端校验）
 */
function getWritingModeKeys(): array {
    return array_column(getWritingModes(), 'mode_key');
}
