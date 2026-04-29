<?php
/**
 * 获取全书故事大纲
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireLoginApi();
    
    $novelId = intval($_GET['novel_id'] ?? 0);

    if (!$novelId) {
        echo json_encode(['success' => false, 'message' => '缺少novel_id参数']);
        exit;
    }

    $outline = DB::fetch('
        SELECT id, story_arc, act_division, major_turning_points, 
               character_arcs, character_endpoints, world_evolution, recurring_motifs,
               created_at, updated_at
        FROM story_outlines 
        WHERE novel_id = ?
    ', [$novelId]);

    if (!$outline) {
        echo json_encode(['success' => false, 'message' => '暂无故事大纲']);
        exit;
    }

    // 解码JSON字段
    $outline['act_division'] = json_decode($outline['act_division'], true);
    $outline['major_turning_points'] = json_decode($outline['major_turning_points'], true);
    
    // character_arcs 格式化为编辑框文本（支持对象/数组两种格式）
    $outline['character_arcs'] = formatCharacterArcsForEdit($outline['character_arcs']);
    
    // character_endpoints：如果 DB 为空，尝试从 character_arcs 中提取
    if (empty($outline['character_endpoints'])) {
        $rawArcs = DB::fetch('SELECT character_arcs FROM story_outlines WHERE novel_id = ?', [$novelId]);
        if ($rawArcs && !empty($rawArcs['character_arcs'])) {
            $outline['character_endpoints'] = extractCharacterEndpoints($rawArcs['character_arcs']);
        }
    }
    
    $outline['recurring_motifs'] = json_decode($outline['recurring_motifs'], true);

    echo json_encode(['success' => true, 'data' => $outline]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
