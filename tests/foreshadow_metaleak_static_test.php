<?php
/**
 * 伏笔回收"章节坐标穿帮"回归测试。
 *
 * 背景：伏笔回收指令里把绝对章节号（如「第129章埋」）以自然语言喂给模型，
 * 模型会原样抄进正文，产出「根据第129章炼骨圣火的克制记录……」这类穿帮句。
 *
 * 本测试守护三层修复：
 *   1) 注入文本不再出现绝对章节号（ForeshadowingResolver / ChapterPromptBuilder）
 *   2) 伏笔段带有"禁止把章节号写进正文"的硬性护栏
 *   3) 正文落盘前经 stripMetaLeaks() 兜底净化，且 write_engine 已接线
 *
 * 静态为主 + 对纯函数 stripMetaLeaks 做真实功能断言。无 DB / API 依赖。
 */

$root = dirname(__DIR__);

function read_source(string $relativePath): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        throw new RuntimeException("Missing source file: {$relativePath}");
    }
    return file_get_contents($path);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, $message);
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) === false, $message);
}

function assert_same(string $expected, string $actual, string $message): void
{
    assert_true($expected === $actual, $message . " — 期望「{$expected}」实得「{$actual}」");
}

// ============================================================
// Layer 3：stripMetaLeaks() 真实功能断言
// ============================================================
define('APP_LOADED', true);            // helpers.php 是纯工具函数，可独立加载
require_once $root . '/includes/helpers.php';

assert_true(function_exists('stripMetaLeaks'), 'helpers.php 必须定义 stripMetaLeaks()');

// 原始穿帮句：去掉章节坐标，句子其余部分保留
assert_same(
    '她语速极快，根据炼骨圣火的克制记录，尸祖心脏若为真品。',
    stripMetaLeaks('她语速极快，根据第129章炼骨圣火的克制记录，尸祖心脏若为真品。'),
    'stripMetaLeaks 应剜除「第129章」坐标并保留句子'
);

// “据第N章记载” 连接词形式
assert_same(
    '据记载，火可灭尸。',
    stripMetaLeaks('据第3章记载，火可灭尸。'),
    'stripMetaLeaks 应处理「据第N章」并保留连接词'
);

// “第N章的XX记录” → 去掉坐标，保留引述名词
assert_same(
    '克制记录显示，它怕火。',
    stripMetaLeaks('第129章的克制记录显示，它怕火。'),
    'stripMetaLeaks 应剜除「第N章的」前缀'
);

// 括注整段删除
assert_same(
    '它怕火。',
    stripMetaLeaks('它怕火。（详见第88章）'),
    'stripMetaLeaks 应删除「（详见第N章）」括注'
);

// 中文数字章节号同样处理
assert_same(
    '根据残卷，此乃真品。',
    stripMetaLeaks('根据第一百二十九章残卷，此乃真品。'),
    'stripMetaLeaks 应支持中文数字章节号'
);

// 负向：正常正文里的“第一章/第N章”若非引用，不应被破坏
assert_same(
    '第一章战斗结束了，他松了口气。',
    stripMetaLeaks('第一章战斗结束了，他松了口气。'),
    'stripMetaLeaks 不应误伤非引用语境的章节词'
);

// ============================================================
// Layer 1：注入文本不再出现绝对章节号
// ============================================================
$resolver = read_source('includes/memory/ForeshadowingResolver.php');
assert_not_contains('【第{$planted}章埋】', $resolver, 'ForeshadowingResolver 回收任务行不得再注入「第N章埋」坐标');
assert_not_contains('建议第{$item[\'deadline_chapter\']}章前回收', $resolver, 'ForeshadowingResolver 不得把 deadline 章节号注入回收行');

$builder = read_source('includes/ChapterPromptBuilder.php');
assert_not_contains('第{$planted}章埋：', $builder, 'ChapterPromptBuilder 倒计时行不得再注入「第N章埋」坐标');
assert_not_contains('第{$f[\'chapter\']}章埋：', $builder, 'ChapterPromptBuilder 到期提醒行不得再注入「第N章埋」坐标');

// ============================================================
// Layer 2：硬性护栏存在
// ============================================================
assert_contains('严禁出现在正文', $resolver, 'ForeshadowingResolver 必须含禁止章节号入正文的护栏');
assert_contains('严禁出现在正文', $builder, 'ChapterPromptBuilder 伏笔段必须含禁止章节号入正文的护栏');

// ============================================================
// Layer 3 接线：write_engine 落盘前调用 stripMetaLeaks
// ============================================================
$engine = read_source('includes/write_engine.php');
assert_contains('stripMetaLeaks($fullContent)', $engine, 'write_engine 必须在落盘前调用 stripMetaLeaks');
assert_true(
    strpos($engine, 'stripSegmentMarkers($fullContent)') < strpos($engine, 'stripMetaLeaks($fullContent)'),
    'stripMetaLeaks 应在 stripSegmentMarkers 之后执行'
);

echo "OK: foreshadow_metaleak_static_test 全部通过\n";
