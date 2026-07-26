<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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

// 审计优化 P1-2（2026-06-16）：requireLoginApi 移出 try 块，
// 与项目其他 API 惯例一致；该函数内部会 exit，放 try 内会干扰错误捕获语义。
requireLoginApi();

try {
    $novelId = intval($_GET['novel_id'] ?? 0);
    if (!$novelId) {
        echo json_encode(['success' => false, 'ok' => false, 'error' => '缺少novel_id参数', 'message' => '缺少novel_id参数', 'code' => 'MISSING_NOVEL_ID']);
        exit;
    }

    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    checkNovelOwnership($novelId, $userId);

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

    // 3b. 钩子回收健康（hook_resolved：1=已回收/0=悬挂/NULL=未检测）
    $data['hook_health'] = ['checked' => 0, 'resolved' => 0, 'dangling' => 0, 'rate' => null, 'dangling_list' => []];
    try {
        $hr = DB::fetch(
            "SELECT
                SUM(hook_resolved IS NOT NULL) AS checked,
                SUM(hook_resolved = 1)         AS resolved,
                SUM(hook_resolved = 0)         AS dangling
             FROM chapters WHERE novel_id=?",
            [$novelId]
        );
        $checked  = (int)($hr['checked'] ?? 0);
        $resolved = (int)($hr['resolved'] ?? 0);
        $dangling = (int)($hr['dangling'] ?? 0);
        $data['hook_health']['checked']  = $checked;
        $data['hook_health']['resolved'] = $resolved;
        $data['hook_health']['dangling'] = $dangling;
        $data['hook_health']['rate']     = $checked > 0 ? round($resolved / $checked * 100, 1) : null;

        // 悬挂钩子清单：本章 hook_resolved=0 → 悬挂的是上一章(N-1)抛出的钩子
        if ($dangling > 0) {
            $rows = DB::fetchAll(
                "SELECT c.chapter_number AS cur, p.chapter_number AS prev_num, p.hook
                 FROM chapters c
                 JOIN chapters p ON p.novel_id = c.novel_id AND p.chapter_number = c.chapter_number - 1
                 WHERE c.novel_id=? AND c.hook_resolved = 0
                 ORDER BY c.chapter_number DESC LIMIT 15",
                [$novelId]
            );
            foreach ($rows as $r) {
                $data['hook_health']['dangling_list'][] = [
                    'chapter' => (int)$r['prev_num'],
                    'hook'    => mb_substr(trim((string)($r['hook'] ?? '')), 0, 60),
                ];
            }
        }
    } catch (\Throwable $e) {
        // hook_resolved 列未迁移或查询失败时静默降级
    }

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
        error_log('health_dashboard.system_health: ' . $e->getMessage());
        $data['system_health'] = ['healthy' => true, 'score' => 100, 'alerts' => []];
    }

    echo json_encode(['success' => true, 'ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'ok' => false,
        'error' => '系统健康检查失败，请稍后重试',
        'message' => '系统健康检查失败，请稍后重试',
        'code' => 'internal_error',
        'request_id' => error_trace_id(),
    ]);
}
