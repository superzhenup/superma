<?php
/**
 * Static checks for the system logic visualization document.
 */

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'system-logic-visualization.html';

function slv_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function slv_contains(string $needle, string $haystack, string $message): void
{
    slv_assert(strpos($haystack, $needle) !== false, $message);
}

slv_assert(is_file($path), 'Visualization HTML file must exist.');
$html = file_get_contents($path);
slv_assert($html !== false && trim($html) !== '', 'Visualization HTML file must not be empty.');

foreach ([
    '系统运行逻辑透视',
    '软件功能总览',
    '核心创作流程',
    'AI 生成链路透视',
    '数据库与模块关系',
    '关键功能模块',
    'novel.php',
    'chapter.php',
    'assist.php',
    'api/generate_outline.php',
    'includes/prompt.php',
    'includes/OutlineQualityGuard.php',
    'memory_atoms',
    'character_cards',
    'chapter_versions',
    'story_outlines',
] as $needle) {
    slv_contains($needle, $html, "Visualization must contain {$needle}.");
}

slv_contains('<style>', $html, 'Visualization should be self-contained with inline CSS.');
slv_contains('<script>', $html, 'Visualization should include inline interaction script.');
slv_contains('data-flow-target', $html, 'Visualization should include clickable flow targets.');
slv_contains('const flowDetails', $html, 'Visualization should include flow detail data.');

echo "system_logic_visualization_test passed\n";
