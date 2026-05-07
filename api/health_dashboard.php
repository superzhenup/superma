<?php
/**
 * API: 全书健康度仪表板数据
 *
 * 提供 novel.php 「健康监控」tab 所需的全部数据：
 *   - 情绪曲线 (最近50章)
 *   - 质量曲线 (最近50章)
 *   - 爽点分布
 *   - 钩子分布
 *   - 角色出场频率
 *   - 待回收伏笔
 *   - 系统健康分
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/prompt.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireLoginApi();
    $novelId = intval($_GET['novel_id'] ?? 0);
    if (!$novelId) {
        echo json_encode(['success' => false, 'message' => '缺少novel_id参数']);
        exit;
    }

    $data = [];

    // 1. 情绪曲线（最近50章）
    $emotionRows = DB::fetchAll(
        'SELECT chapter_number, emotion_score, quality_score
         FROM chapters WHERE novel_id=? AND status="completed"
         ORDER BY chapter_number DESC LIMIT 50',
        [$novelId]
    );
    $emotionCurve = [];
    $qualityCurve = [];
    foreach ($emotionRows as $row) {
        $emotionCurve[] = [
            'x' => (int)$row['chapter_number'],
            'y' => round((float)($row['emotion_score'] ?: $row['quality_score'] ?: 0), 1),
        ];
        $qualityCurve[] = [
            'x' => (int)$row['chapter_number'],
            'y' => round((float)($row['quality_score'] ?: 0), 1),
        ];
    }
    // 反转为 ASC 顺序，保证图表 X 轴从左到右递增
    $emotionCurve = array_reverse($emotionCurve);
    $qualityCurve = array_reverse($qualityCurve);
    $data['emotion_curve'] = $emotionCurve;
    $data['quality_curve'] = $qualityCurve;

    // 2. 爽点分布
    $coolRows = DB::fetchAll(
        'SELECT cool_point_type, COUNT(*) as cnt
         FROM chapters WHERE novel_id=? AND cool_point_type IS NOT NULL AND cool_point_type != ""
         GROUP BY cool_point_type ORDER BY cnt DESC',
        [$novelId]
    );
    $coolDist = [];
    foreach ($coolRows as $row) {
        $typeName = COOL_POINT_TYPES[$row['cool_point_type']]['name'] ?? $row['cool_point_type'];
        $coolDist[] = ['label' => $typeName, 'count' => (int)$row['cnt']];
    }
    $data['cool_point_distribution'] = $coolDist;

    // 爽点密度计算
    $totalCompleted = (int)(DB::fetch(
        'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id=? AND status="completed"',
        [$novelId]
    )['cnt'] ?? 0);
    $totalCool = (int)(DB::fetch(
        'SELECT COUNT(*) as cnt FROM chapters WHERE novel_id=? AND cool_point_type IS NOT NULL AND cool_point_type != ""',
        [$novelId]
    )['cnt'] ?? 0);
    $data['cool_point_density'] = $totalCompleted > 0
        ? round($totalCompleted / max(1, $totalCool), 1)
        : 0;
    $data['total_completed'] = $totalCompleted;
    $data['total_cool_points'] = $totalCool;

    // 3. 钩子分布
    $hookRows = DB::fetchAll(
        'SELECT hook_type, COUNT(*) as cnt
         FROM chapters WHERE novel_id=? AND hook_type IS NOT NULL AND hook_type != ""
         GROUP BY hook_type ORDER BY cnt DESC',
        [$novelId]
    );
    $hookDist = [];
    foreach ($hookRows as $row) {
        $hookDist[] = ['label' => $row['hook_type'], 'count' => (int)$row['cnt']];
    }
    $data['hook_distribution'] = $hookDist;

    // 4. 角色出场（从character_cards获取）
    $charRows = DB::fetchAll(
        'SELECT cc.name, COALESCE(nc.role_type, "minor") AS importance, cc.last_updated_chapter
         FROM character_cards cc
         LEFT JOIN novel_characters nc
           ON cc.novel_id = nc.novel_id AND cc.name = nc.name
         WHERE cc.novel_id=? AND cc.name IS NOT NULL
         ORDER BY cc.last_updated_chapter DESC,
                  FIELD(COALESCE(nc.role_type,"minor"),"protagonist","major","minor","background") DESC
         LIMIT 10',
        [$novelId]
    );
    $characters = [];
    foreach ($charRows as $row) {
        $gap = $totalCompleted - (int)($row['last_updated_chapter'] ?? 0);
        $characters[] = [
            'name'       => $row['name'],
            'importance' => $row['importance'] ?? 'minor',
            'last_chapter' => (int)($row['last_updated_chapter'] ?? 0),
            'gap'        => $gap > 0 ? $gap : 0,
        ];
    }
    $data['characters'] = $characters;

    // 5. 待回收伏笔
    $foreshadowRows = DB::fetchAll(
        'SELECT id, description, planted_chapter, last_mentioned_chapter, deadline_chapter
         FROM foreshadowing_items WHERE novel_id=? AND resolved_chapter IS NULL
         ORDER BY planted_chapter ASC LIMIT 20',
        [$novelId]
    );
    $foreshadows = [];
    $agedCount = 0;
    foreach ($foreshadowRows as $row) {
        $age = $totalCompleted - (int)$row['planted_chapter'];
        $sinceLastMention = $totalCompleted - max(
            (int)($row['last_mentioned_chapter'] ?? $row['planted_chapter']),
            (int)$row['planted_chapter']
        );
        $foreshadows[] = [
            'description'     => $row['description'],
            'planted_chapter' => (int)$row['planted_chapter'],
            'age'             => $age,
            'since_last'      => $sinceLastMention,
            'deadline'        => (int)($row['deadline_chapter'] ?? 0),
            'warning'         => $age > 25 || $sinceLastMention > 15,
        ];
        if ($age > 25) $agedCount++;
    }
    $data['foreshadowing'] = [
        'items'      => $foreshadows,
        'total'      => count($foreshadowRows),
        'aged_count' => $agedCount,
    ];

    // 6. 系统健康分
    try {
        require_once __DIR__ . '/../includes/SystemHealthMonitor.php';
        $monitor = new SystemHealthMonitor($novelId);
        $health = $monitor->check();
        $data['system_health'] = $health;
    } catch (\Throwable $e) {
        $data['system_health'] = ['healthy' => true, 'score' => 100, 'alerts' => [], 'error' => $e->getMessage()];
    }

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
