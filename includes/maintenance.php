<?php
/** Application maintenance guard; intentionally usable before config/session/DB bootstrap. */
function maintenanceFilePath(): string
{
    return (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/storage/.maintenance';
}

function maintenanceStatus(): array
{
    $path = maintenanceFilePath();
    clearstatcache(true, $path);
    $active = file_exists($path) || is_link($path);
    $raw = $active && is_file($path) ? @file_get_contents($path) : false;
    return ['active' => $active, 'path' => $path,
        'marker' => $raw === false ? null : (json_decode($raw, true) ?? $raw)];
}

function maintenanceGuardHttp(): void
{
    if (!maintenanceStatus()['active']) return;
    http_response_code(503);
    header('Retry-After: 300');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Service is in maintenance; retry later.'], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Root pages (审计修复 F02，2026-11-01)：与 API 同样在任何业务 DB 操作前失败关闭，
 * 只是给浏览器渲染 HTML。由 requireLogin() 统一调用，覆盖全部根页面的 GET/POST。
 */
function maintenanceGuardPage(): void
{
    if (!maintenanceStatus()['active']) return;
    http_response_code(503);
    header('Retry-After: 300');
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>\n<html lang=\"zh-CN\"><head><meta charset=\"UTF-8\"><title>系统维护中</title></head><body>\n"
        . "<h1>系统维护中</h1>\n<p>系统正在进行备份恢复或维护，期间暂停所有页面与写入操作，请稍后刷新。</p>\n</body></html>\n";
    exit;
}

function maintenanceGuardWorker(): void
{
    if (!maintenanceStatus()['active']) return;
    if (PHP_SAPI !== 'cli') maintenanceGuardHttp();
    fwrite(STDERR, "Maintenance active: worker refused before claiming work. Check php bin/maintenance.php --status\n");
    exit(75);
}
