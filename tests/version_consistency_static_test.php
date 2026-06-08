<?php
/**
 * Static regression checks for the public product version.
 *
 * Product-facing pages and telemetry must read a single APP_VERSION source.
 * Historical migration comments and DB::SCHEMA_VERSION intentionally remain
 * independent because they describe compatibility history, not the release.
 */

$root = dirname(__DIR__);

function version_read_source(string $relativePath): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        throw new RuntimeException("Missing source file: {$relativePath}");
    }
    return file_get_contents($path);
}

function version_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function version_contains(string $needle, string $haystack, string $message): void
{
    version_assert(strpos($haystack, $needle) !== false, $message);
}

function version_not_contains(string $needle, string $haystack, string $message): void
{
    version_assert(strpos($haystack, $needle) === false, $message);
}

$version = version_read_source('includes/version.php');
$config = version_read_source('config.php');
$installer = version_read_source('install.php');
$layout = version_read_source('includes/layout.php');
$stats = version_read_source('includes/stats_tracker.php');
$debugStats = version_read_source('debug_stats.php');

version_contains("define('APP_VERSION', '1.7');", $version, 'APP_VERSION must be defined once as 1.7.');
version_contains("require_once __DIR__ . '/includes/version.php';", $config, 'Runtime config must load the shared product version.');
version_contains("require_once __DIR__ . '/includes/version.php';", $installer, 'Installer must load the shared product version.');
version_assert(substr_count($installer, "require_once __DIR__ . '/includes/version.php';") >= 2, 'Installer-generated config must load the shared product version.');
version_contains("APP_VERSION", $layout, 'Layout footer must render the shared product version.');
version_contains("'version' => APP_VERSION", $stats, 'Telemetry payload must use the shared product version.');
version_contains("'User-Agent: Super-Ma-Novel-System/' . APP_VERSION", $stats, 'Telemetry user agent must use the shared product version.');
version_contains("APP_VERSION . '-test'", $debugStats, 'Telemetry diagnostics must use the shared product version.');

version_not_contains('安装向导 · v1.5', $installer, 'Installer UI must not advertise v1.5.');
version_not_contains('数据库结构 (v1.5)', $installer, 'Installer schema summary must not advertise v1.5.');
version_not_contains("'version' => '1.5'", $stats, 'Telemetry payload must not advertise protocol version 1.5.');
version_not_contains('Super-Ma-Novel-System/1.5', $stats, 'Telemetry user agent must not advertise version 1.5.');
version_not_contains("'version' => '1.5-test'", $debugStats, 'Telemetry diagnostics must not advertise version 1.5-test.');
version_not_contains('Super-Ma-Novel-System/1.5-test', $debugStats, 'Telemetry diagnostics user agent must not advertise version 1.5-test.');

echo "version_consistency_static_test passed\n";
