<?php
/**
 * API 统一路由分发器
 *
 * 纯 PHP 入口，前端通过 POST JSON { route: 'actions', action: 'xxx', ... } 调用。
 * 分发器负责：公共引导、鉴权、路由查找、错误处理策略选择、分发到 handler。
 *
 * CLI 进程（write_chapter_worker.php）不走此入口，保持独立 require_once。
 */

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
define('APP_LOADED', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

// ---- 路由表（白名单，防目录穿越） ----
$ROUTES = [
    'actions'             => ['file' => 'actions.php',            'type' => 'json'],
    'chapter_list'        => ['file' => 'chapter_list.php',       'type' => 'json'],
    'chapter_actions'     => ['file' => 'chapter_actions.php',    'type' => 'json'],
    'knowledge'           => ['file' => 'knowledge.php',           'type' => 'json'],
    'creative_assist'     => ['file' => 'creative_assist.php',     'type' => 'json'],
    'memory_actions'      => ['file' => 'memory_actions.php',      'type' => 'json'],
    'cover_actions'       => ['file' => 'cover_actions.php',       'type' => 'json'],
    'good_moling'         => ['file' => 'good_moling.php',         'type' => 'json'],
    'short_story'         => ['file' => 'short_story.php',         'type' => 'json'],
    'hot_novels'          => ['file' => 'hot_novels.php',           'type' => 'json'],
    // 注意：hot_novels_ingest.php 是外部 Agent 推送接口，不经过 router（避免 requireLoginApi 拦截）
    // Agent 直接 POST 到 /api/hot_novels_ingest.php，仅靠 X-Ingest-Key 认证
    'import_novel_excel'  => ['file' => 'import_novel_excel.php',  'type' => 'json'],
    'export_novel'        => ['file' => 'export_novel.php',        'type' => 'json'],
    'export_short_story'  => ['file' => 'export_short_story.php',  'type' => 'json'],
    'write_start'         => ['file' => 'write_start.php',         'type' => 'json'],
    'write_poll'          => ['file' => 'write_poll.php',          'type' => 'json'],
    'cancel_write'        => ['file' => 'cancel_write.php',        'type' => 'json'],
    'wizard'              => ['file' => 'wizard.php',              'type' => 'json'],
    'iterative_config'    => ['file' => 'iterative_config.php',    'type' => 'json'],
    'iterative_monitor'   => ['file' => 'iterative_monitor.php',   'type' => 'json'],
    'author_profile'      => ['file' => 'author_profile.php',      'type' => 'json'],
    'stats_report'        => ['file' => 'stats_report.php',        'type' => 'json'],
    'health_dashboard'    => ['file' => 'health_dashboard.php',    'type' => 'json'],
    'system_diagnostic'   => ['file' => 'system_diagnostic.php',   'type' => 'json'],
    'writing_params'      => ['file' => 'writing_params.php',      'type' => 'json'],
    'agent_status'        => ['file' => 'agent_status.php',        'type' => 'json'],
    'character_network_data' => ['file' => 'character_network_data.php', 'type' => 'json'],
    'diagnose_embedding'  => ['file' => 'diagnose_embedding.php',  'type' => 'json'],
    'emotion_curve'       => ['file' => 'emotion_curve.php',       'type' => 'json'],
    'human_critic'        => ['file' => 'human_critic.php',        'type' => 'json'],
    'novel_export'        => ['file' => 'novel_export.php',        'type' => 'json'],
    'novel_import'        => ['file' => 'novel_import.php',        'type' => 'json'],
    'get_optimize_config' => ['file' => 'get_optimize_config.php', 'type' => 'json'],
    'get_optimize_progress' => ['file' => 'get_optimize_progress.php', 'type' => 'json'],
    'optimize_outline_ajax' => ['file' => 'optimize_outline_ajax.php', 'type' => 'json'],
    'chapter_versions'    => ['file' => 'chapter_versions.php',    'type' => 'json'],
    'update_chapter_synopsis' => ['file' => 'update_chapter_synopsis.php', 'type' => 'json'],
    'export_chapter_synopses' => ['file' => 'export_chapter_synopses.php', 'type' => 'json'],
    'import_chapter_synopses' => ['file' => 'import_chapter_synopses.php', 'type' => 'json'],
    'get_chapter_synopsis' => ['file' => 'get_chapter_synopsis.php', 'type' => 'json'],
    'generate_chapter_synopsis' => ['file' => 'generate_chapter_synopsis.php', 'type' => 'json'],
    'optimize_chapter_synopsis' => ['file' => 'optimize_chapter_synopsis.php', 'type' => 'json'],
    'optimize_chapter_title' => ['file' => 'optimize_chapter_title.php', 'type' => 'json'],
    'compress_chapter'    => ['file' => 'compress_chapter.php',    'type' => 'json'],
    'fix_semantic'        => ['file' => 'fix_semantic.php',        'type' => 'json'],
    'rebuild_embeddings'  => ['file' => 'rebuild_embeddings.php',  'type' => 'json'],
    'get_story_outline'   => ['file' => 'get_story_outline.php',   'type' => 'json'],
    'update_story_outline' => ['file' => 'update_story_outline.php', 'type' => 'json'],
    'validate_consistency' => ['file' => 'validate_consistency.php', 'type' => 'json'],
    'daemon_write'        => ['file' => 'daemon_write.php',        'type' => 'json'],
    'daemon_token'        => ['file' => 'daemon_token.php',        'type' => 'json'],
    'polish_selection'    => ['file' => 'polish_selection.php',    'type' => 'json'],

    'write_chapter'       => ['file' => 'write_chapter.php',       'type' => 'sse'],
    'generate_outline'    => ['file' => 'generate_outline.php',    'type' => 'sse'],
    'supplement_outline'  => ['file' => 'supplement_outline.php',  'type' => 'sse'],
    'polish_chapter'      => ['file' => 'polish_chapter.php',      'type' => 'sse'],
    'optimize_outline'    => ['file' => 'optimize_outline.php',    'type' => 'sse'],
    'optimize_outline_v2' => ['file' => 'optimize_outline_v2.php', 'type' => 'sse'],
    'generate_volume_outline' => ['file' => 'generate_volume_outline.php', 'type' => 'sse'],
    'generate_story_outline' => ['file' => 'generate_story_outline.php', 'type' => 'sse'],
    'workshop'            => ['file' => 'workshop.php',            'type' => 'sse'],
];

$route = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['route'] ?? '');

if ($route === '') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '缺少 route 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($ROUTES[$route])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '未知路由: ' . $route], JSON_UNESCAPED_UNICODE);
    exit;
}

$routeInfo = $ROUTES[$route];
$handlerFile = __DIR__ . '/' . $routeInfo['file'];

if (!file_exists($handlerFile)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '处理器不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 根据类型注册不同的错误处理策略 ----
if ($routeInfo['type'] === 'json') {
    require_once dirname(__DIR__) . '/includes/error_handler.php';
    registerApiErrorHandlers();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    requireLoginApi();
} else {
    require_once dirname(__DIR__) . '/includes/sse_error_handler.php';
    ob_end_clean();
    requireLoginApi();
}

// ---- 分发到 handler ----
require $handlerFile;
