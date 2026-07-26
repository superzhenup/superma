<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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

// 审计优化 P1-2（2026-06-16）：requireLoginApi 移出 try 块，
// 与项目其他 API 惯例一致；该函数内部会 exit，放 try 内会干扰错误捕获语义。
requireLoginApi();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $novelId = intval($input['novel_id'] ?? 0);
    $storyArc = trim($input['story_arc'] ?? '');
    $actDivision = $input['act_division'] ?? null;
    $majorTurningPoints = $input['major_turning_points'] ?? null;
    $characterArcsStr = trim($input['character_arcs'] ?? '');
    $characterEndpoints = trim($input['character_endpoints'] ?? '');
    $characterProgression = $input['character_progression'] ?? null;
    $worldEvolution = trim($input['world_evolution'] ?? '');
    $recurringMotifs = $input['recurring_motifs'] ?? null;

    if (!$novelId || !$storyArc) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        exit;
    }

    // 审计修复（2026-07-19 H-01）：补齐归属校验，防止越权改写他人小说大纲
    checkNovelOwnership($novelId, (int)($_SESSION['user_id'] ?? 0));

    // 处理 character_arcs：将换行分隔的文本转换为数组
    $characterArcs = null;
    if ($characterArcsStr) {
        $lines = array_filter(array_map('trim', explode("\n", $characterArcsStr)));
        if (!empty($lines)) {
            $characterArcs = json_encode($lines, JSON_UNESCAPED_UNICODE);
        }
    }

    // 检查是否已存在
    // M-22 修复：upsert 包裹事务，防止并发 INSERT 产生重复键
    try {
        DB::beginTransaction();
        $exists = DB::fetch('SELECT id FROM story_outlines WHERE novel_id = ?', [$novelId]);

        // 兜底：确保 character_progression 列存在
        try {
            $hasCol = DB::fetch("SHOW COLUMNS FROM story_outlines LIKE 'character_progression'");
            if (!$hasCol) {
                DB::query("ALTER TABLE `story_outlines` ADD COLUMN `character_progression` JSON DEFAULT NULL COMMENT '角色等级/境界发展轨迹' AFTER `character_endpoints`");
            }
        } catch (\Throwable $e) { error_log('update_story_outline DDL failed: ' . $e->getMessage()); }

        if ($exists) {
        // 更新（审计修复 2026-07-19 H-02）：按 array_key_exists 部分更新，
        // 未提交的字段保持原值，避免前端"部分字段编辑"语义下把其余字段抹为 NULL
        $updateData = [];
        if (array_key_exists('story_arc', $input)) $updateData['story_arc'] = $storyArc;
        if (array_key_exists('act_division', $input)) $updateData['act_division'] = $actDivision ? json_encode($actDivision, JSON_UNESCAPED_UNICODE) : null;
        if (array_key_exists('major_turning_points', $input)) $updateData['major_turning_points'] = $majorTurningPoints ? json_encode($majorTurningPoints, JSON_UNESCAPED_UNICODE) : null;
        if (array_key_exists('character_arcs', $input)) $updateData['character_arcs'] = $characterArcs;
        if (array_key_exists('character_endpoints', $input)) $updateData['character_endpoints'] = $characterEndpoints ?: null;
        if (array_key_exists('character_progression', $input)) $updateData['character_progression'] = $characterProgression ? json_encode($characterProgression, JSON_UNESCAPED_UNICODE) : null;
        if (array_key_exists('world_evolution', $input)) $updateData['world_evolution'] = $worldEvolution;
        if (array_key_exists('recurring_motifs', $input)) $updateData['recurring_motifs'] = $recurringMotifs ? json_encode($recurringMotifs, JSON_UNESCAPED_UNICODE) : null;
        if ($updateData) {
            DB::update('story_outlines', $updateData, 'novel_id = ?', [$novelId]);
        }
    } else {
        // 插入
        DB::insert('story_outlines', [
            'novel_id' => $novelId,
            'story_arc' => $storyArc,
            'act_division' => $actDivision ? json_encode($actDivision, JSON_UNESCAPED_UNICODE) : null,
            'major_turning_points' => $majorTurningPoints ? json_encode($majorTurningPoints, JSON_UNESCAPED_UNICODE) : null,
            'character_arcs' => $characterArcs,
            'character_endpoints' => $characterEndpoints ?: null,
            'character_progression' => $characterProgression ? json_encode($characterProgression, JSON_UNESCAPED_UNICODE) : null,
            'world_evolution' => $worldEvolution,
            'recurring_motifs' => $recurringMotifs ? json_encode($recurringMotifs, JSON_UNESCAPED_UNICODE) : null
        ]);

        // 更新novels表标记
        DB::update('novels', ['has_story_outline' => 1], 'id = ?', [$novelId]);
    }
        DB::commit();
    } catch (\Throwable $txE) {
        DB::rollback();
        throw $txE;
    }

    echo json_encode(['success' => true, 'message' => '故事大纲已保存']);

} catch (Exception $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] update_story_outline: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    echo json_encode(['success' => false, 'message' => '保存失败，请稍后重试', 'request_id' => $rid]);
}
