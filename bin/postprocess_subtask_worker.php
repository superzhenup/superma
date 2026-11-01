<?php
require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardWorker();
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI mode only\n"); }
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/includes/CliContext.php';
CliContext::activate();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks/PostprocessSubtaskWorker.php';
$options = getopt('', ['tasks:']);
$ids = explode(',', (string)($options['tasks'] ?? ''));
if (count($ids) < 1 || count($ids) > 2) exit(2);
foreach ($ids as $id) {
    if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) exit(2);
}
foreach ($ids as $id) {
    maintenanceGuardWorker();
    PostprocessSubtaskWorker::runOne((int)$id);
}
