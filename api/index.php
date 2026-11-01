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

require_once dirname(__DIR__) . '/includes/maintenance.php';
maintenanceGuardHttp();
require_once dirname(__DIR__) . '/includes/RequestBody.php';

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
    'good_moling'         => ['file' => 'good_moling.php',         'type' => 'json', 'sse_actions' => ['chat']],
    'short_story'         => ['file' => 'short_story.php',         'type' => 'json', 'sse_actions' => ['write_draft', 'write_chapter']],
    'hot_novels'          => ['file' => 'hot_novels.php',           'type' => 'json'],
    // 注意：hot_novels_ingest.php 是外部 Agent 推送接口，不经过 router（避免 requireLoginApi 拦截）
    // Agent 直接 POST 到 /api/hot_novels_ingest.php，仅靠 X-Ingest-Key 认证
    // 审计修复 M3（2026-08-16）：daemon_write.php 同理不经过 router——它是 Cron 直连入口
    // （X-Daemon-Token 鉴权，见文件头注释与 deploy/nginx.conf.example 规则5放行清单）。
    // 前端挂机面板也直接 fetch('api/daemon_write.php')，不携带 route 参数；
    // 原路由表项（需 session 登录的 json 路由）与文件的 token 鉴权互斥，属无效死配置，已移除。
    'import_novel_excel'  => ['file' => 'import_novel_excel.php',  'type' => 'json'],
    // 安装成功页尚未建立普通登录态；handler 使用短时一次性 token 自行鉴权。
    'install_models'      => ['file' => 'install_models.php',      'type' => 'json', 'auth' => 'handler'],
    'export_novel'        => ['file' => 'export_novel.php',        'type' => 'json'],
    'export_short_story'  => ['file' => 'export_short_story.php',  'type' => 'json'],
    'write_start'         => ['file' => 'write_start.php',         'type' => 'json'],
    'write_poll'          => ['file' => 'write_poll.php',          'type' => 'json'],
    'retry_chapter_postprocess' => ['file' => 'retry_chapter_postprocess.php', 'type' => 'json', 'max_body' => 8 * 1024],
    'run_status'          => ['file' => 'run_status.php',          'type' => 'json'],
    'chapter_candidates'  => ['file' => 'chapter_candidates.php',  'type' => 'json', 'max_body' => 8 * 1024],
    'approval_decide'     => ['file' => 'approval_decide.php',     'type' => 'json', 'max_body' => 8 * 1024],
    'approval_commit'     => ['file' => 'approval_commit.php',     'type' => 'json', 'max_body' => 8 * 1024],
    'candidate_retry'     => ['file' => 'candidate_retry.php',     'type' => 'json', 'max_body' => 8 * 1024],
    'branch_actions'      => ['file' => 'branch_actions.php',      'type' => 'json', 'max_body' => 5 * 1024 * 1024],
    'cancel_write'        => ['file' => 'cancel_write.php',        'type' => 'json'],
    'wizard'              => ['file' => 'wizard.php',              'type' => 'json', 'sse_actions' => ['chat', 'review_check']],
    'iterative_config'    => ['file' => 'iterative_config.php',    'type' => 'json'],
    'iterative_monitor'   => ['file' => 'iterative_monitor.php',   'type' => 'json'],
    'author_profile'      => ['file' => 'author_profile.php',      'type' => 'json', 'max_body' => 52 * 1024 * 1024],
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
    'novel_import'        => ['file' => 'novel_import.php',        'type' => 'json', 'max_body' => 52 * 1024 * 1024],
    'analyze_book'        => ['file' => 'analyze_book.php',        'type' => 'json', 'sse_actions' => ['analyze_step']],
    'get_optimize_config' => ['file' => 'get_optimize_config.php', 'type' => 'json'],
    'get_optimize_progress' => ['file' => 'get_optimize_progress.php', 'type' => 'json'],
    'optimize_outline_ajax' => ['file' => 'optimize_outline_ajax.php', 'type' => 'json'],
    'chapter_versions'    => ['file' => 'chapter_versions.php',    'type' => 'json'],
    'update_chapter_synopsis' => ['file' => 'update_chapter_synopsis.php', 'type' => 'json'],
    'export_chapter_synopses' => ['file' => 'export_chapter_synopses.php', 'type' => 'json'],
    'import_chapter_synopses' => ['file' => 'import_chapter_synopses.php', 'type' => 'json'],
    'get_chapter_synopsis' => ['file' => 'get_chapter_synopsis.php', 'type' => 'json'],
    'generate_chapter_synopsis' => ['file' => 'generate_chapter_synopsis.php', 'type' => 'sse'],
    'optimize_chapter_synopsis' => ['file' => 'optimize_chapter_synopsis.php', 'type' => 'json'],
    'optimize_chapter_title' => ['file' => 'optimize_chapter_title.php', 'type' => 'json'],
    'compress_chapter'    => ['file' => 'compress_chapter.php',    'type' => 'sse'],
    'fix_semantic'        => ['file' => 'fix_semantic.php',        'type' => 'json'],
    'rebuild_embeddings'  => ['file' => 'rebuild_embeddings.php',  'type' => 'json'],
    'get_story_outline'   => ['file' => 'get_story_outline.php',   'type' => 'json'],
    'update_story_outline' => ['file' => 'update_story_outline.php', 'type' => 'json'],
    'validate_consistency' => ['file' => 'validate_consistency.php', 'type' => 'json'],
    'daemon_token'        => ['file' => 'daemon_token.php',        'type' => 'json'],
    'polish_selection'    => ['file' => 'polish_selection.php',    'type' => 'json'],
    'chapter_rewrite'     => ['file' => 'chapter_rewrite.php',     'type' => 'json', 'max_body' => 8 * 1024],
    'drama_actions'       => ['file' => 'drama_actions.php',       'type' => 'json'],
    'drama_generate'      => ['file' => 'drama_generate.php',      'type' => 'json'],
    'drama_poll'          => ['file' => 'drama_poll.php',          'type' => 'json'],
    'drama_media'         => ['file' => 'drama_media.php',         'type' => 'json', 'max_body' => 12 * 1024 * 1024],
    'drama_video_config'  => ['file' => 'drama_video_config.php',  'type' => 'json'],

    'write_chapter'       => ['file' => 'write_chapter.php',       'type' => 'sse'],
    'generate_outline'    => ['file' => 'generate_outline.php',    'type' => 'sse'],
    'supplement_outline'  => ['file' => 'supplement_outline.php',  'type' => 'sse'],
    'polish_chapter'      => ['file' => 'polish_chapter.php',      'type' => 'sse'],
    'optimize_outline'    => ['file' => 'optimize_outline.php',    'type' => 'sse'],
    // 审计修复 H5（2026-08-16）：optimize_outline_v2 已删除——全项目（assets/js）无任何
    // 调用方，属未上线的实验残留；活跃路径为 optimize_outline（SSE）与 optimize_outline_ajax（JSON）。
    'generate_volume_outline' => ['file' => 'generate_volume_outline.php', 'type' => 'sse'],
    'generate_story_outline' => ['file' => 'generate_story_outline.php', 'type' => 'json', 'max_body' => 8 * 1024],
    'story_outline_status' => ['file' => 'story_outline_status.php', 'type' => 'json'],
    'story_outline_cancel' => ['file' => 'story_outline_cancel.php', 'type' => 'json', 'max_body' => 8 * 1024],
    'workshop'            => ['file' => 'workshop.php',            'type' => 'json', 'sse_actions' => ['generate_stream']],
];

// ---- 请求体大小限制（Content-Length 仅预检，实际读取同样有界） ----
$defaultMaxBodyBytes = 10 * 1024 * 1024;
// 与最大上传路由一致；JSON 中才提供 route 时先受此全局硬上限保护。
$globalMaxBodyBytes = 52 * 1024 * 1024;
$preflightRoute = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['route'] ?? ''));
$maxBodyBytes = $preflightRoute !== ''
    ? (int)($ROUTES[$preflightRoute]['max_body'] ?? $defaultMaxBodyBytes)
    : $globalMaxBodyBytes;
$rawBody = readRequestBodyBounded($maxBodyBytes);
$jsonInput = $rawBody !== null && $rawBody !== '' ? json_decode($rawBody, true) : [];
$jsonInput = is_array($jsonInput) ? $jsonInput : [];
$route = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['route'] ?? ($jsonInput['route'] ?? '')));
$resolvedMaxBodyBytes = (int)($ROUTES[$route]['max_body'] ?? $defaultMaxBodyBytes);
// multipart 的原始流为空，仍按该路由的 Content-Length 预检，保留 $_FILES。
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? 0);
if ($rawBody === null || strlen($rawBody) > $resolvedMaxBodyBytes || $contentLength > $resolvedMaxBodyBytes) {
    ob_end_clean();
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '请求体过大'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($route === '') {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '缺少 route 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($ROUTES[$route])) {
    ob_end_clean();
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '未知路由'], JSON_UNESCAPED_UNICODE);
    exit;
}

$routeInfo = $ROUTES[$route];
$handlerFile = __DIR__ . '/' . $routeInfo['file'];
$requestAction = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['action'] ?? ($jsonInput['action'] ?? '')));
$routeType = $routeInfo['type'];
$routerRequiresLogin = ($routeInfo['auth'] ?? 'login') !== 'handler';
if ($requestAction !== '' && in_array($requestAction, $routeInfo['sse_actions'] ?? [], true)) {
    $routeType = 'sse';
}

if (!file_exists($handlerFile)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '处理器不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 根据类型注册不同的错误处理策略 ----
if ($routeType === 'json') {
    require_once dirname(__DIR__) . '/includes/error_handler.php';
    registerApiErrorHandlers();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if ($routerRequiresLogin) requireLoginApi();
} else {
    require_once dirname(__DIR__) . '/includes/sse_error_handler.php';
    ob_end_clean();
    if ($routerRequiresLogin) requireLoginApi();
}

// ---- 分发到 handler ----
// 审计修复 ARCH-C2（2026-07-01）：定义路由器守卫常量
// handler 文件顶部应加 `defined('API_VIA_ROUTER') or http_response_code(403); exit;`
// 防止 nginx 部署（无 .htaccess）或 Apache rewrite 失效时 handler 被直接访问绕过鉴权
define('API_VIA_ROUTER', true);
require $handlerFile;
