<?php
/**
 * 系统功能诊断工具
 * 
 * 全面验证小说写作系统的核心功能是否正常生效
 * 包括：记忆引擎、伏笔系统、质量检测、写作引擎、进度感知等
 */

defined('APP_LOADED') || define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
require_once dirname(__DIR__) . '/includes/auth.php';
registerApiErrorHandlers();
requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: $_GET;
$novelId = (int)($input['novel_id'] ?? 0);
$testType = $input['test_type'] ?? 'all';

if (!$novelId) {
    echo json_encode(['error' => '缺少 novel_id 参数'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) {
    echo json_encode(['error' => '小说不存在'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$diagnostic = [
    'novel_id'    => $novelId,
    'novel_title' => $novel['title'],
    'test_time'   => date('Y-m-d H:i:s'),
    'tests'       => [],
    'summary'     => [
        'total_tests' => 0,
        'passed'      => 0,
        'warnings'    => 0,
        'failed'      => 0,
        'critical'    => 0,
    ],
];

function runTest(string $category, string $testName, callable $testFn, array &$diagnostic): void
{
    $diagnostic['summary']['total_tests']++;
    
    try {
        $result = $testFn();
        $status = $result['status'] ?? 'unknown';
        
        $diagnostic['tests'][$category][$testName] = [
            'status'   => $status,
            'message'  => $result['message'] ?? '',
            'details'  => $result['details'] ?? [],
            'duration' => $result['duration'] ?? 0,
        ];
        
        switch ($status) {
            case 'pass':     $diagnostic['summary']['passed']++; break;
            case 'warning':  $diagnostic['summary']['warnings']++; break;
            case 'fail':     $diagnostic['summary']['failed']++; break;
            case 'critical': $diagnostic['summary']['critical']++; break;
        }
    } catch (Throwable $e) {
        $diagnostic['tests'][$category][$testName] = [
            'status'  => 'error',
            'message' => '测试执行异常：' . $e->getMessage(),
            'file'    => $e->getFile() . ':' . $e->getLine(),
        ];
        $diagnostic['summary']['failed']++;
    }
}

// 加载测试用例
require_once dirname(__FILE__) . '/diagnostic_tests.php';

// 执行所有测试
runAllTests($novelId, $novel, $testType, $diagnostic);

// 生成总结
$summary = $diagnostic['summary'];
$total = $summary['total_tests'];
$passed = $summary['passed'];
$healthScore = $total > 0 ? round($passed / $total * 100, 1) : 0;

$diagnostic['health_score'] = $healthScore;
$diagnostic['health_status'] = $healthScore >= 80 ? 'healthy' : ($healthScore >= 60 ? 'warning' : 'critical');

echo json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
