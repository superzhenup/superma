<?php
/**
 * XSS 渲染静态回归测试（2026-05-31 审计 P0：不可信文本直接写入 innerHTML）。
 *
 * 守护审计明确点名的高风险渲染点：
 *   - includes/wizard/blueprint.php：聊天里的用户输入 / AI 输出 / 错误文案
 *     在写入 innerHTML 前必须经 escHtml() 转义（与 content.php / launch.php 一致）。
 *   - novel.php：封面路径、标题片段拼进 innerHTML 的属性/文本前必须经 _escAttr() 转义。
 *
 * 纯静态：检查源码不再包含"未转义直插"的指纹，并确认转义调用到位。
 */

$root = dirname(__DIR__);

function xss_read(string $rel): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        throw new RuntimeException("Missing source file: {$rel}");
    }
    return file_get_contents($path);
}
function xss_assert(bool $cond, string $msg): void
{
    if (!$cond) { throw new RuntimeException($msg); }
}
function xss_absent(string $needle, string $hay, string $msg): void
{
    xss_assert(strpos($hay, $needle) === false, $msg);
}
function xss_present(string $needle, string $hay, string $msg): void
{
    xss_assert(strpos($hay, $needle) !== false, $msg);
}

// ---- 蓝图聊天（blueprint.php）----
$bp = xss_read('includes/wizard/blueprint.php');
xss_present('function escHtml(', $bp, 'blueprint.php must define an escHtml() helper for chat rendering.');
xss_present("'<div class=\"msg-bubble\">' + escHtml(text || '')", $bp, 'Chat bubble must HTML-escape the message text before innerHTML.');
xss_absent("'<div class=\"msg-bubble\">' + (text || '')", $bp, 'Regression: chat bubble must not insert raw user/AI text into innerHTML.');
xss_present('escHtml(cleanForChat(fullText))', $bp, 'Streaming AI output must be escaped before innerHTML.');
xss_present("'⚠️ ' + escHtml(err)", $bp, 'Chat error text must be escaped before innerHTML.');

// ---- 封面 / 标题渲染（novel.php）----
$np = xss_read('novel.php');
xss_present('function _escAttr(', $np, 'novel.php must define an attribute-safe _escAttr() helper.');
xss_present('_escAttr(data.path)', $np, 'Cover image src must escape the server-returned path.');
xss_absent("'<img src=\"' + data.path", $np, 'Regression: cover image src must not concatenate the raw path.');
xss_present('_escAttr(title.substring(0, 4))', $np, 'Cover placeholder must escape the title fragment.');
xss_absent("+ title.substring(0, 4) + '</div>'", $np, 'Regression: title fragment must not be inserted raw into innerHTML.');

echo "xss_render_static_test passed\n";
