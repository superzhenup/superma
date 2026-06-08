<?php
/**
 * 部署保护静态测试（2026-05-31 审计 P1：部署保护依赖 Apache .htaccess）。
 *
 * 仓库内的 .htaccess 仅在 Apache 下生效。本测试校验随仓库提供的 Nginx 示例配置
 * （deploy/nginx.conf.example）拒绝了与各 .htaccess 等价的敏感路径，
 * 同时**不会**误伤需要对外服务的封面目录 /storage/covers/。
 */

$root = dirname(__DIR__);

function deploy_assert(bool $cond, string $msg): void
{
    if (!$cond) { throw new RuntimeException($msg); }
}
function deploy_has(string $needle, string $hay, string $msg): void
{
    deploy_assert(strpos($hay, $needle) !== false, $msg);
}

$cfgPath = $root . '/deploy/nginx.conf.example';
deploy_assert(is_file($cfgPath), 'Missing deploy/nginx.conf.example — Nginx deployments would be unprotected.');
$cfg = file_get_contents($cfgPath);

// ---- 必须拒绝的非公开目录（对应 config/includes/tests/cache 的 .htaccess + 审计点名的 /docs）----
foreach (['/config/', '/includes/', '/tests/', '/docs/', '/storage/cache/'] as $dir) {
    deploy_has('location ^~ ' . $dir, $cfg, "Nginx config must have a deny location for {$dir}.");
}

// ---- 必须拒绝主配置文件、敏感扩展名、点文件（对应根 .htaccess）----
deploy_has('location = /config.php', $cfg, 'Nginx config must deny direct access to config.php.');
deploy_has('(lock|log|sh|sql|bak|ini|sample|md)', $cfg, 'Nginx config must deny sensitive file extensions (incl. logs under /storage).');
deploy_has('location ~ /\\.', $cfg, 'Nginx config must deny dotfiles (.git/.env).');
deploy_has('deny all', $cfg, 'Nginx config must actually use deny all.');

// ---- 封面目录必须仍可访问：示例必须显式说明 /storage/covers/ 不被拦截 ----
deploy_has('/storage/covers/', $cfg, 'Nginx config must account for serving cover images at /storage/covers/.');
// 反向保险：不得出现把整个 /storage/ 一刀切拒绝的规则（会让封面 404）。
deploy_assert(
    strpos($cfg, 'location ^~ /storage/ ') === false && strpos($cfg, 'location ^~ /storage/{') === false,
    'Nginx config must not blanket-deny all of /storage/ — that would break cover images.'
);

echo "deploy_protection_static_test passed\n";
