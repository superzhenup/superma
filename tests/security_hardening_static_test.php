<?php
/**
 * Static regression checks for first-phase security hardening.
 *
 * These checks avoid a live database while guarding the HTTP and secret
 * boundaries that must remain stable across deployments.
 */

$root = dirname(__DIR__);

function security_read_source(string $relativePath): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        throw new RuntimeException("Missing source file: {$relativePath}");
    }
    return file_get_contents($path);
}

function security_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function security_contains(string $needle, string $haystack, string $message): void
{
    security_assert(strpos($haystack, $needle) !== false, $message);
}

function security_not_contains(string $needle, string $haystack, string $message): void
{
    security_assert(strpos($haystack, $needle) === false, $message);
}

$auth = security_read_source('includes/auth.php');
$daemonHelper = security_read_source('includes/daemon_auth.php');
$daemonTokenApi = security_read_source('api/daemon_token.php');
$daemonWriteApi = security_read_source('api/daemon_write.php');
$router = security_read_source('api/index.php');
$statsApi = security_read_source('api/stats_report.php');
$chapterVersionsApi = security_read_source('api/chapter_versions.php');
$chapterPage = security_read_source('chapter.php');
$rebuildApi = security_read_source('api/rebuild_embeddings.php');
$fixSemanticApi = security_read_source('api/fix_semantic.php');
$knowledgeApi = security_read_source('api/knowledge.php');
$hotNovelsApi = security_read_source('api/hot_novels.php');
$coverApi = security_read_source('api/cover_actions.php');
$goodMolingApi = security_read_source('api/good_moling.php');
$diagnoseEmbeddingApi = security_read_source('api/diagnose_embedding.php');
$foreshadowingRepo = security_read_source('includes/memory/ForeshadowingRepo.php');
$config = security_read_source('config.php');
$installer = security_read_source('install.php');
$indexPage = security_read_source('index.php');
$novelPage = security_read_source('novel.php');

security_assert(!is_file($root . '/assets/js/app-fallback.js'), 'Dormant PHP-in-JS fallback file must be removed.');

security_contains('function requireHttpMethod', $auth, 'Auth helpers must expose a reusable HTTP method guard.');

security_contains('function getDaemonToken', $daemonHelper, 'Daemon token lookup must live in a shared helper.');
security_contains('function getOrCreateDaemonToken', $daemonHelper, 'Daemon token initialization must live in a shared helper.');
security_contains('requireLoginApi(true);', $daemonTokenApi, 'Daemon token management must require login and CSRF.');
security_contains("requireHttpMethod('POST');", $daemonTokenApi, 'Daemon token management must be POST-only.');
security_contains("'daemon_token'", $router, 'Authenticated router must expose daemon_token management.');
security_contains("apiPost('daemon_token'", $novelPage, 'Novel page must request daemon token through authenticated API routing.');
security_not_contains("'token' => \$daemonToken", $daemonWriteApi, 'Public daemon endpoint must never disclose the daemon token.');
security_not_contains("BASE_PATH . '/daemon.token'", $daemonWriteApi, 'Daemon token fallback must not write into the Web root.');
security_contains('http_response_code(401);', $daemonWriteApi, 'Public daemon endpoint must reject missing tokens.');
security_contains('HTTP_X_DAEMON_TOKEN', $daemonWriteApi, 'Daemon endpoint must accept the token from a request header (audit P1: keep it out of access logs).');
security_contains('Bearer', $daemonWriteApi, 'Daemon endpoint must also accept Authorization: Bearer token.');

security_contains('requireLoginApi();', $statsApi, 'Direct statistics endpoint must require login.');
security_contains("requireHttpMethod('POST');", $statsApi, 'Statistics endpoint mutations must require POST.');
security_contains("requireHttpMethod('POST');", $chapterVersionsApi, 'Chapter rollback must require POST.');
security_contains("method: 'POST'", $chapterPage, 'Chapter rollback client must submit POST.');
security_contains("requireHttpMethod('POST');", $rebuildApi, 'Embedding rebuild must require POST.');
security_contains("requireHttpMethod('POST');", $fixSemanticApi, 'Semantic repair mutations must require POST.');
security_contains('requireHttpMethod', $knowledgeApi, 'Knowledge mutations must enforce POST.');
security_contains('requireHttpMethod', $hotNovelsApi, 'Hot-novel settings mutations must enforce POST.');
security_contains('requireHttpMethod', $coverApi, 'Cover mutations must enforce POST.');
security_contains('requireHttpMethod', $goodMolingApi, 'Good Moling mutations must enforce POST.');

security_contains("define('STATS_REPORT_ENABLED',    false)", $config, 'Existing config must disable telemetry by default.');
security_contains("define('STATS_REPORT_ENABLED',    false)", $installer, 'Installer template must default telemetry to disabled.');
security_not_contains("StatsTracker::reportRealtime", $novelPage, 'Novel rendering must not block on external telemetry.');
security_not_contains("stats_report.php?action=report", $indexPage, 'Index rendering must not perform server-side HTTP telemetry calls.');

security_contains('$safeCfg', $diagnoseEmbeddingApi, 'Embedding diagnostics must build a redacted config payload.');
security_not_contains("json_encode(\$cfg", $diagnoseEmbeddingApi, 'Embedding diagnostics must not serialize raw model configuration.');
security_contains("@unserialize(\$blob, ['allowed_classes' => false])", $foreshadowingRepo, 'Legacy vectors must disable object instantiation during unserialize.');

$inputPos = strpos($rebuildApi, '$input = json_decode');
$headerPos = strpos($rebuildApi, "header('Content-Type: text/event-stream");
security_assert($inputPos !== false && $headerPos !== false && $inputPos < $headerPos, 'Embedding rebuild must parse output mode before selecting headers.');

echo "security_hardening_static_test passed\n";
