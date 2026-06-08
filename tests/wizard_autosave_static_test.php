<?php
/**
 * 向导各阶段「中途退出自动保存」静态回归测试。
 *
 * 修复点：blueprint 阶段早有 autosave，但 content（卷大纲）与 launch（额外设定）阶段
 * 在用户切走/关闭页面/点退出时不保存编辑内容 → 进度丢失。本测试确保三个"有持久化内容"
 * 的中途阶段都具备「输入防抖自动保存 + 页面隐藏(keepalive) + beforeunload」三件套。
 */

$root = dirname(__DIR__);

function wa_read(string $rel): string
{
    global $root;
    $p = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($p)) throw new RuntimeException("Missing source file: {$rel}");
    return file_get_contents($p);
}
function wa_assert(bool $c, string $m): void { if (!$c) throw new RuntimeException($m); }

// 每个阶段：[文件, 保存用的 action, 该阶段自动保存函数名/标志]
$stages = [
    'blueprint' => ['includes/wizard/blueprint.php', 'action=save_doc',    'saveBlueprint'],
    'content'   => ['includes/wizard/content.php',   'action=save_volumes', 'autoSaveVolumes'],
    'launch'    => ['includes/wizard/launch.php',     'action=save_extra',   'autoSaveExtra'],
];

foreach ($stages as $name => [$file, $action, $fn]) {
    $src = wa_read($file);
    wa_assert(strpos($src, "visibilitychange") !== false,
        "{$name} 阶段必须监听 visibilitychange，在页面切走/隐藏时保存（移动端可靠）。");
    wa_assert(strpos($src, "beforeunload") !== false,
        "{$name} 阶段必须监听 beforeunload，在关闭/刷新前保存。");
    wa_assert(strpos($src, "keepalive") !== false,
        "{$name} 阶段退出保存必须用 keepalive 请求，确保离开时请求仍能发出。");
    wa_assert(strpos($src, $action) !== false,
        "{$name} 阶段自动保存必须调用 {$action}。");
    wa_assert(strpos($src, $fn) !== false,
        "{$name} 阶段必须有自动保存函数 {$fn}。");
}

echo "wizard_autosave_static_test passed\n";
