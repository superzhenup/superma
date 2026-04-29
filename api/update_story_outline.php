<?php
/**
 * 更新全书故事大纲
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireLoginApi();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $novelId = intval($input['novel_id'] ?? 0);
    $storyArc = trim($input['story_arc'] ?? '');
    $actDivision = $input['act_division'] ?? null;
    $majorTurningPoints = $input['major_turning_points'] ?? null;
    $characterArcsStr = trim($input['character_arcs'] ?? '');
    $characterEndpoints = trim($input['character_endpoints'] ?? '');
    $worldEvolution = trim($input['world_evolution'] ?? '');
    $recurringMotifs = $input['recurring_motifs'] ?? null;

    if (!$novelId || !$storyArc) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        exit;
    }

    // 处理 character_arcs：将换行分隔的文本转换为数组
    $characterArcs = null;
    if ($characterArcsStr) {
        $lines = array_filter(array_map('trim', explode("\n", $characterArcsStr)));
        if (!empty($lines)) {
            $characterArcs = json_encode($lines, JSON_UNESCAPED_UNICODE);
        }
    }

    // 检查是否已存在
    $exists = DB::fetch('SELECT id FROM story_outlines WHERE novel_id = ?', [$novelId]);

    if ($exists) {
        // 更新
        DB::update(
            'story_outlines',
            [
                'story_arc' => $storyArc,
                'act_division' => $actDivision ? json_encode($actDivision, JSON_UNESCAPED_UNICODE) : null,
                'major_turning_points' => $majorTurningPoints ? json_encode($majorTurningPoints, JSON_UNESCAPED_UNICODE) : null,
                'character_arcs' => $characterArcs,
                'character_endpoints' => $characterEndpoints ?: null,
                'world_evolution' => $worldEvolution,
                'recurring_motifs' => $recurringMotifs ? json_encode($recurringMotifs, JSON_UNESCAPED_UNICODE) : null
            ],
            'novel_id = ?',
            [$novelId]
        );
    } else {
        // 插入
        DB::insert('story_outlines', [
            'novel_id' => $novelId,
            'story_arc' => $storyArc,
            'act_division' => $actDivision ? json_encode($actDivision, JSON_UNESCAPED_UNICODE) : null,
            'major_turning_points' => $majorTurningPoints ? json_encode($majorTurningPoints, JSON_UNESCAPED_UNICODE) : null,
            'character_arcs' => $characterArcs,
            'character_endpoints' => $characterEndpoints ?: null,
            'world_evolution' => $worldEvolution,
            'recurring_motifs' => $recurringMotifs ? json_encode($recurringMotifs, JSON_UNESCAPED_UNICODE) : null
        ]);

        // 更新novels表标记
        DB::update('novels', ['has_story_outline' => 1], 'id = ?', [$novelId]);
    }

    echo json_encode(['success' => true, 'message' => '故事大纲已保存']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
