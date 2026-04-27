<?php
/**
 * api/knowledge.php — 知识库 CRUD API
 *
 * 所有操作均归属 novel_id 校验，安全防跨小说污染。
 */
define('APP_LOADED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
registerApiErrorHandlers();
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/embedding.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── 统一响应格式 ──────────────────────────────────────────────
function json_ok(array $data = [], string $message = 'ok'): void {
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 基础校验 ──────────────────────────────────────────────────
if ($method === 'GET') {
    $input = $_GET;
} else {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}
$novelId = (int)($input['novel_id'] ?? 0);
if ($novelId <= 0) {
    json_err('无效的 novel_id');
}

// 初始化 KnowledgeBase
$kb = new KnowledgeBase($novelId);

// ════════════════════════════════════════════════════════════════
// 角色操作
// ════════════════════════════════════════════════════════════════

if ($action === 'get_characters') {
    $roleType = $input['role_type'] ?? null;
    $list = $kb->getCharacters($roleType);
    json_ok($list);
}

if ($action === 'get_character') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $list = $kb->getCharacters();
    $item = null;
    foreach ($list as $c) {
        if ((int)$c['id'] === $id) { $item = $c; break; }
    }
    if (!$item) json_err('角色不存在', 404);
    json_ok($item);
}

if ($action === 'save_character') {
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        // 更新模式：先查一次确保记录存在
        $existing = DB::fetch('SELECT id FROM novel_characters WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) json_err('角色不存在或不属于当前小说', 404);
    }
    $saved = $kb->saveCharacter($input);
    json_ok(['id' => $saved], $id > 0 ? '角色已更新' : '角色已添加');
}

if ($action === 'delete_character') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $kb->deleteCharacter($id);
    json_ok([], '角色已删除');
}

// ════════════════════════════════════════════════════════════════
// 世界观操作
// ════════════════════════════════════════════════════════════════

if ($action === 'get_worldbuilding') {
    $category = $input['category'] ?? null;
    $list = $kb->getWorldbuilding($category);
    json_ok($list);
}

if ($action === 'get_worldbuilding_item') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $list = $kb->getWorldbuilding();
    $item = null;
    foreach ($list as $w) {
        if ((int)$w['id'] === $id) { $item = $w; break; }
    }
    if (!$item) json_err('设定不存在', 404);
    json_ok($item);
}

if ($action === 'save_worldbuilding') {
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        $existing = DB::fetch('SELECT id FROM novel_worldbuilding WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) json_err('设定不存在或不属于当前小说', 404);
    }
    $saved = $kb->saveWorldbuilding($input);
    json_ok(['id' => $saved], $id > 0 ? '设定已更新' : '设定已添加');
}

if ($action === 'delete_worldbuilding') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $kb->deleteWorldbuilding($id);
    json_ok([], '设定已删除');
}

// ════════════════════════════════════════════════════════════════
// 情节操作
// ════════════════════════════════════════════════════════════════

if ($action === 'get_plots') {
    $eventType = $input['event_type'] ?? null;
    $status    = $input['status'] ?? null;
    $list = $kb->getPlots($eventType, $status);
    json_ok($list);
}

if ($action === 'get_plot') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $list = $kb->getPlots();
    $item = null;
    foreach ($list as $p) {
        if ((int)$p['id'] === $id) { $item = $p; break; }
    }
    if (!$item) json_err('情节不存在', 404);
    json_ok($item);
}

if ($action === 'save_plot') {
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        $existing = DB::fetch('SELECT id FROM novel_plots WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) json_err('情节不存在或不属于当前小说', 404);
    }
    $saved = $kb->savePlot($input);
    json_ok(['id' => $saved], $id > 0 ? '情节已更新' : '情节已添加');
}

if ($action === 'delete_plot') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $kb->deletePlot($id);
    json_ok([], '情节已删除');
}

// ════════════════════════════════════════════════════════════════
// 风格操作
// ════════════════════════════════════════════════════════════════

if ($action === 'get_styles') {
    $category = $input['category'] ?? null;
    $list = $kb->getStyles($category);
    json_ok($list);
}

if ($action === 'get_style') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $list = $kb->getStyles();
    $item = null;
    foreach ($list as $s) {
        if ((int)$s['id'] === $id) { $item = $s; break; }
    }
    if (!$item) json_err('风格不存在', 404);
    json_ok($item);
}

if ($action === 'save_style') {
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        $existing = DB::fetch('SELECT id FROM novel_style WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) json_err('风格不存在或不属于当前小说', 404);
    }
    $saved = $kb->saveStyle($input);
    json_ok(['id' => $saved], $id > 0 ? '风格已更新' : '风格已添加');
}

if ($action === 'delete_style') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_err('无效的 id');
    $kb->deleteStyle($id);
    json_ok([], '风格已删除');
}

// ════════════════════════════════════════════════════════════════
// 语义搜索
// ════════════════════════════════════════════════════════════════

if ($action === 'search') {
    $query = trim((string)($input['query'] ?? ''));
    if ($query === '') json_err('搜索内容不能为空');

    if (!$kb->isAvailable()) {
        json_err('Embedding 服务未配置，无法进行语义搜索');
    }

    $results = $kb->search($query);
    json_ok($results);
}

// ── 未知 action ───────────────────────────────────────────────
json_err('未知操作: ' . $action);
