<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI mode only\n"); }
ini_set('display_errors', '0');
$phase = 'initialization';
$emitted = false;
$emit = static function (array $report) use (&$emitted): void {
    $emitted = true;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
};
register_shutdown_function(static function () use (&$phase, &$emitted, $emit): void {
    $error = error_get_last();
    if (!$emitted && $error !== null && in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        $emit(['ok'=>false,'phase'=>$phase,'error'=>$error['message'],
            'file'=>$error['file'],'line'=>$error['line']]);
    }
});
try {
    defined('APP_LOADED') or define('APP_LOADED', true);
    $root = dirname(__DIR__);
    $phase = 'config.php';
    require_once $root . '/config.php';
    // Same dependency order as the write entry points. No chapter claim, DB
    // migration, model invocation or writing workflow is executed by this probe.
    foreach (['db.php','ai.php','functions.php','write_engine.php',
        'tasks/PostprocessRunner.php','stats_tracker.php','harness/runtime/bootstrap.php',
        'harness/stages/LegacyWriteEngineStageAdapter.php'] as $file) {
        $phase = 'includes/' . $file;
        require_once $root . '/' . $phase;
    }
    $checks = [
        'token_budget_adapter' => interface_exists('TokenBudgetModelAdapter', false),
        'model_accounting' => interface_exists('ModelInvocationAccounting', false),
        'chapter_accounting' => class_exists('ChapterRunAIInvocationAccounting', false),
        'budget_ledger' => class_exists('RunModelBudgetLedger', false),
        'request_accounting_hook' => method_exists('AIInvocationAccountingScope', 'performRequest'),
        'model_client' => class_exists('AIClient', false),
    ];
    $ok = !in_array(false, $checks, true);
    $emit(['ok'=>$ok,'status'=>$ok ? 'WRITE_BOOT_OK' : 'WRITE_BOOT_INCOMPLETE',
        'php_version'=>PHP_VERSION,'checks'=>$checks]);
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    $emit(['ok'=>false,'phase'=>$phase,'error'=>$error::class . ': ' . $error->getMessage(),
        'file'=>$error->getFile(),'line'=>$error->getLine()]);
    exit(1);
}
