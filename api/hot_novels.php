<?php
/**
 * 热门选题前端查询 / 配置管理接口
 */
define('APP_LOADED', true);
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/HotNovelService.php';
require_once dirname(__DIR__) . '/includes/HotNovelQuery.php';

requireLoginApi(true);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
if (in_array($action, ['regenerate_key', 'save_settings'], true)) {
    requireHttpMethod('POST');
}

$query = new HotNovelQuery();

switch ($action) {
    case 'list':
        $filter = [
            'source'    => $input['source']    ?? $_GET['source']    ?? 'all',
            'channel'   => $input['channel']   ?? $_GET['channel']   ?? 'all',
            'category'  => $input['category']  ?? $_GET['category']  ?? 'all',
            'rank_type' => $input['rank_type'] ?? $_GET['rank_type'] ?? 'all',
            'days'      => (int)($input['days']    ?? $_GET['days']  ?? 7),
            'keyword'   => trim($input['keyword'] ?? $_GET['keyword'] ?? ''),
        ];
        $page     = (int)($input['page'] ?? $_GET['page'] ?? 1);
        $pageSize = (int)($input['page_size'] ?? $_GET['page_size'] ?? 24);
        $result = $query->list($filter, max(1, $page), min(100, max(1, $pageSize)));
        jsonResponse(true, $result);
        break;

    case 'summary':
        $filter = [
            'source' => $input['source'] ?? $_GET['source'] ?? 'all',
            'days'   => (int)($input['days'] ?? $_GET['days'] ?? 7),
        ];
        $result = $query->summary($filter);
        // 附 source 计数
        $result['source_counts'] = $query->sourceCounts(['days' => $filter['days']]);
        $result['rank_types']    = $query->rankTypes($filter['source']);
        jsonResponse(true, $result);
        break;

    case 'get':
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, null, '缺少id');
        $novel = $query->get($id);
        if (!$novel) jsonResponse(false, null, '未找到');
        jsonResponse(true, $novel);
        break;

    case 'get_settings':
        jsonResponse(true, [
            'has_key'                  => HotNovelService::getIngestKey() !== '',
            'ingest_key'               => HotNovelService::getIngestKey() !== '' ? '***已设置***' : '',
            'unsupported_categories'   => HotNovelService::setting('hot_novels_unsupported_categories', ''),
            'min_confidence'           => HotNovelService::getMinConfidence(),
            'ingest_enabled'           => HotNovelService::isIngestEnabled(),
            'ingest_url'               => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                                          . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                                          . dirname($_SERVER['SCRIPT_NAME']) . '/hot_novels_ingest.php',
        ]);
        break;

    case 'regenerate_key':
        // M-9 修复：重新生成 ingest key 需管理员权限
        if (!isAdmin()) jsonResponse(false, null, '需要管理员权限');
        $newKey = HotNovelService::regenerateIngestKey();
        jsonResponse(true, ['ingest_key' => $newKey]);
        break;

    case 'save_settings':
        // M-9 修复：保存 ingest 设置需管理员权限
        if (!isAdmin()) jsonResponse(false, null, '需要管理员权限');
        if (isset($input['unsupported_categories'])) {
            HotNovelService::setSetting('hot_novels_unsupported_categories', (string)$input['unsupported_categories']);
        }
        if (isset($input['min_confidence'])) {
            $mc = max(0, min(100, (int)$input['min_confidence']));
            HotNovelService::setSetting('hot_novels_min_confidence', (string)$mc);
        }
        if (isset($input['ingest_enabled'])) {
            HotNovelService::setSetting('hot_novels_ingest_enabled', !empty($input['ingest_enabled']) ? '1' : '0');
        }
        jsonResponse(true, ['ok' => true]);
        break;

    default:
        jsonResponse(false, null, '未知 action: ' . $action);
}
