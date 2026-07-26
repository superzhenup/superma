<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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
requireLoginApi();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/embedding.php';

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$writeActions = [
    'save_character', 'delete_character',
    'save_worldbuilding', 'delete_worldbuilding',
    'save_plot', 'delete_plot',
    'save_style', 'delete_style',
];
if (in_array($action, $writeActions, true)) {
    requireHttpMethod('POST');
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
    jsonResponse(false, null, '无效的 novel_id');
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
checkNovelOwnership($novelId, $userId);

$kb = new KnowledgeBase($novelId);

if ($action === 'get_characters') {
    $roleType = $input['role_type'] ?? null;
    $list = $kb->getCharacters($roleType);
    jsonResponse(true, $list);
}

if ($action === 'get_character') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $item = $kb->getCharacterById($id);
    if (!$item) jsonResponse(false, null, '角色不存在', 'NOT_FOUND');
    jsonResponse(true, $item);
}

if ($action === 'save_character') {
    $id = (int)($input['id'] ?? 0);
    if ($id === 0 && empty(trim((string)($input['name'] ?? '')))) {
        jsonResponse(false, null, '角色名称不能为空', 'INVALID_NAME');
    }
    if ($id > 0) {
        $existing = DB::fetch('SELECT id FROM novel_characters WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) jsonResponse(false, null, '角色不存在或不属于当前小说', 'NOT_FOUND');
    }
    $saved = $kb->saveCharacter($input);
    jsonResponse(true, ['id' => $saved], $id > 0 ? '角色已更新' : '角色已添加');
}

if ($action === 'delete_character') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $kb->deleteCharacter($id);
    jsonResponse(true, null, '角色已删除');
}

if ($action === 'get_worldbuilding') {
    $category = $input['category'] ?? null;
    $list = $kb->getWorldbuilding($category);
    jsonResponse(true, $list);
}

if ($action === 'get_worldbuilding_item') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $item = $kb->getWorldbuildingById($id);
    if (!$item) jsonResponse(false, null, '设定不存在', 'NOT_FOUND');
    jsonResponse(true, $item);
}

if ($action === 'save_worldbuilding') {
    $id = (int)($input['id'] ?? 0);
    if ($id === 0 && empty(trim((string)($input['name'] ?? '')))) {
        jsonResponse(false, null, '世界观设定名称不能为空', 'INVALID_NAME');
    }
    if ($id > 0) {
        $existing = DB::fetch('SELECT id FROM novel_worldbuilding WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) jsonResponse(false, null, '设定不存在或不属于当前小说', 'NOT_FOUND');
    }
    $saved = $kb->saveWorldbuilding($input);
    jsonResponse(true, ['id' => $saved], $id > 0 ? '设定已更新' : '设定已添加');
}

if ($action === 'delete_worldbuilding') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $kb->deleteWorldbuilding($id);
    jsonResponse(true, null, '设定已删除');
}

if ($action === 'get_plots') {
    $eventType = $input['event_type'] ?? null;
    $status    = $input['status'] ?? null;
    $list = $kb->getPlots($eventType, $status);
    jsonResponse(true, $list);
}

if ($action === 'get_plot') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $item = $kb->getPlotById($id);
    if (!$item) jsonResponse(false, null, '情节不存在', 'NOT_FOUND');
    jsonResponse(true, $item);
}

if ($action === 'save_plot') {
    $id = (int)($input['id'] ?? 0);
    if ($id === 0 && empty(trim((string)($input['title'] ?? '')))) {
        jsonResponse(false, null, '情节标题不能为空', 'INVALID_NAME');
    }
    if ($id > 0) {
        $existing = DB::fetch('SELECT id FROM novel_plots WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) jsonResponse(false, null, '情节不存在或不属于当前小说', 'NOT_FOUND');
    }
    $saved = $kb->savePlot($input);
    jsonResponse(true, ['id' => $saved], $id > 0 ? '情节已更新' : '情节已添加');
}

if ($action === 'delete_plot') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $kb->deletePlot($id);
    jsonResponse(true, null, '情节已删除');
}

if ($action === 'get_styles') {
    $category = $input['category'] ?? null;
    $list = $kb->getStyles($category);
    jsonResponse(true, $list);
}

if ($action === 'get_style') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $item = $kb->getStyleById($id);
    if (!$item) jsonResponse(false, null, '风格不存在', 'NOT_FOUND');
    jsonResponse(true, $item);
}

if ($action === 'save_style') {
    $id = (int)($input['id'] ?? 0);
    if ($id === 0 && empty(trim((string)($input['name'] ?? '')))) {
        jsonResponse(false, null, '风格名称不能为空', 'INVALID_NAME');
    }
    if ($id > 0) {
        $existing = DB::fetch('SELECT id FROM novel_style WHERE id=? AND novel_id=?', [$id, $novelId]);
        if (!$existing) jsonResponse(false, null, '风格不存在或不属于当前小说', 'NOT_FOUND');
    }
    $saved = $kb->saveStyle($input);
    jsonResponse(true, ['id' => $saved], $id > 0 ? '风格已更新' : '风格已添加');
}

if ($action === 'delete_style') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, null, '无效的 id');
    $kb->deleteStyle($id);
    jsonResponse(true, null, '风格已删除');
}

if ($action === 'search') {
    $query = trim((string)($input['query'] ?? ''));
    if ($query === '') jsonResponse(false, null, '搜索内容不能为空');

    if (!$kb->isAvailable()) {
        jsonResponse(false, null, 'Embedding 服务未配置，无法进行语义搜索');
    }

    $results = $kb->search($query);
    jsonResponse(true, $results);
}

jsonResponse(false, null, '未知操作: ' . $action);
