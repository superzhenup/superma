<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 小说导出 API
 * 支持单本/全部导出为JSON格式
 * 包含：小说基本信息、章节、章节概要、全书大纲、人物卡片、伏笔、记忆原子、弧段摘要等
 */

// 禁止向页面输出错误，由统一错误处理器处理
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

define('APP_LOADED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
registerApiErrorHandlers();
require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

ob_end_clean();

// 只接受GET请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => '方法不允许']);
    exit;
}

$novelId = intval($_GET['novel_id'] ?? 0); // 0=全部
$format  = $_GET['format'] ?? 'json';
$skipContent = isset($_GET['skip_content']) && $_GET['skip_content'] === '1';

// 审计 P0（2026-06-12）：归属隔离。
// - 单本导出：必须校验小说归属
// - 全量导出：只导出当前用户名下的书，禁止跨用户拖库
$sessionUserId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if ($sessionUserId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '会话异常，请重新登录'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($novelId > 0) {
    checkNovelOwnership($novelId, $sessionUserId);
}

try {
    if ($novelId > 0) {
        // 单本导出
        $data = exportSingleNovel($novelId, $skipContent);
        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => '小说不存在']);
            exit;
        }
        $fileName = 'novel_' . preg_replace('/[^\w]/', '_', $data['novel']['title'] ?? 'unknown') . '_' . date('Ymd_His');
    } else {
        // 全部导出（严格限定当前用户名下；归属为 NULL 的历史书需先在 UI 内打开认领后再导出，
        // 避免跨用户拖库）
        // 审计修复 M-5（2026-07-01）：添加安全上限防止内存耗尽
        $novels = DB::fetchAll(
            'SELECT id FROM novels WHERE user_id = ? ORDER BY updated_at DESC',
            [$sessionUserId]
        );
        $maxExport = defined('CFG_MAX_EXPORT_NOVELS') ? (int)CFG_MAX_EXPORT_NOVELS : 100;
        $truncated = false;
        if (count($novels) > $maxExport) {
            $novels = array_slice($novels, 0, $maxExport);
            $truncated = true;
        }
        $allData = [];
        foreach ($novels as $n) {
            $d = exportSingleNovel($n['id'], $skipContent);
            if ($d) $allData[] = $d;
        }
        $data = [
            'export_version' => '1.0',
            'export_time'    => date('Y-m-d H:i:s'),
            'novel_count'    => count($allData),
            'novels'         => $allData,
            'truncated'      => $truncated,
            'truncated_msg'  => $truncated ? "导出已截断为最近 {$maxExport} 本小说" : null,
        ];
        $fileName = 'novels_all_' . date('Ymd_His');
    }

    // 输出JSON文件下载
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(safe_api_error_payload($e, '导出失败，请稍后重试'));
}

/**
 * 导出单本小说的完整数据
 */
function exportSingleNovel(int $novelId, bool $skipContent = false): ?array {
    // 审计优化 P2-4（2026-06-16）：将 1:1 关系的 story_outlines / novel_state
    // 通过 LEFT JOIN 合并到 novels 主查询，减少 2 次串行 DB 往返。
    // 注：story_outlines / novel_state 均为 novel_id UNIQUE，LEFT JOIN 不会放大行数。
    $novel = DB::fetch(
        'SELECT n.*, so.story_arc, so.act_division, so.major_turning_points,
                so.character_arcs, so.character_endpoints, so.character_progression,
                so.world_evolution, so.recurring_motifs,
                ns.story_momentum, ns.current_location, ns.location_chapter,
                ns.location_transition, ns.current_arc_summary,
                ns.last_ingested_chapter, ns.graph_start_chapter
         FROM novels n
         LEFT JOIN story_outlines so ON so.novel_id = n.id
         LEFT JOIN novel_state ns ON ns.novel_id = n.id
         WHERE n.id = ?',
        [$novelId]
    );
    if (!$novel) return null;

    // 移除不必要导出的字段
    unset($novel['cancel_flag']);

    // 拆分 JOIN 结果：novel / story_outline / novel_state
    $storyOutline = null;
    if (!empty($novel['story_arc']) || !empty($novel['act_division'])) {
        $storyOutline = [
            'novel_id'              => $novelId,
            'story_arc'             => $novel['story_arc'] ?? null,
            'act_division'          => $novel['act_division'] ?? null,
            'major_turning_points'  => $novel['major_turning_points'] ?? null,
            'character_arcs'        => $novel['character_arcs'] ?? null,
            'character_endpoints'   => $novel['character_endpoints'] ?? null,
            'character_progression' => $novel['character_progression'] ?? null,
            'world_evolution'       => $novel['world_evolution'] ?? null,
            'recurring_motifs'      => $novel['recurring_motifs'] ?? null,
        ];
    }
    $novelState = null;
    if (isset($novel['story_momentum']) || isset($novel['current_arc_summary']) || isset($novel['last_ingested_chapter'])) {
        $novelState = [
            'novel_id'              => $novelId,
            'story_momentum'        => $novel['story_momentum'] ?? null,
            'current_location'      => $novel['current_location'] ?? null,
            'location_chapter'      => $novel['location_chapter'] ?? null,
            'location_transition'   => $novel['location_transition'] ?? null,
            'current_arc_summary'   => $novel['current_arc_summary'] ?? null,
            'last_ingested_chapter' => $novel['last_ingested_chapter'] ?? null,
            'graph_start_chapter'   => $novel['graph_start_chapter'] ?? null,
        ];
    }
    // 清理 novel 数组中 JOIN 进来的字段
    foreach (['story_arc','act_division','major_turning_points','character_arcs',
              'character_endpoints','character_progression','world_evolution','recurring_motifs',
              'story_momentum','current_location','location_chapter','location_transition',
              'current_arc_summary','last_ingested_chapter','graph_start_chapter'] as $k) {
        unset($novel[$k]);
    }

    // 2. 章节列表
    $chapterCols = $skipContent
        ? 'id, chapter_number, title, outline, chapter_summary, hook, status, words, created_at, updated_at'
        : 'id, chapter_number, title, outline, content, chapter_summary, hook, status, words, created_at, updated_at';
    $chapters = DB::fetchAll(
        "SELECT {$chapterCols} FROM chapters WHERE novel_id = ? ORDER BY chapter_number", [$novelId]
    );

    // 3. 章节概要
    $synopses = DB::fetchAll(
        'SELECT * FROM chapter_synopses WHERE novel_id = ? ORDER BY chapter_number', [$novelId]
    );

    // 4. 卷大纲
    $volumeOutlines = DB::fetchAll(
        'SELECT * FROM volume_outlines WHERE novel_id = ? ORDER BY volume_number', [$novelId]
    );

    // 5. 人物卡片
    $characters = DB::fetchAll(
        'SELECT * FROM character_cards WHERE novel_id = ? ORDER BY id', [$novelId]
    );

    // 6. 伏笔
    $foreshadowing = DB::fetchAll(
        'SELECT id, novel_id, description, planted_chapter, deadline_chapter, resolved_chapter, created_at
         FROM foreshadowing_items WHERE novel_id = ? ORDER BY planted_chapter', [$novelId]
    );

    // 7. 记忆原子
    $memoryAtoms = DB::fetchAll(
        'SELECT id, novel_id, atom_type, content, source_chapter, confidence, metadata, created_at
         FROM memory_atoms WHERE novel_id = ? ORDER BY source_chapter, id', [$novelId]
    );

    // 8. 弧段摘要
    $arcSummaries = DB::fetchAll(
        'SELECT * FROM arc_summaries WHERE novel_id = ? ORDER BY arc_index', [$novelId]
    );

    // 9. 一致性日志（可选，数据量可能较大，默认不导出content详情）
    $consistencyLogs = DB::fetchAll(
        'SELECT id, novel_id, chapter_number, check_type, issues, created_at
         FROM consistency_logs WHERE novel_id = ? ORDER BY chapter_number', [$novelId]
    );

    // 10. 小说级自适应参数。它们直接影响爽点密度与迭代控制，备份时
    // 必须随小说导出，否则恢复后会静默退回全局默认。
    $novelSettings = DB::fetchAll(
        'SELECT setting_key, setting_value, updated_at
         FROM novel_settings WHERE novel_id = ? ORDER BY setting_key',
        [$novelId]
    );

    return [
        'export_version'   => '1.0',
        'export_time'      => date('Y-m-d H:i:s'),
        'novel'            => $novel,
        'chapters'         => $chapters,
        'chapter_synopses' => $synopses,
        'story_outline'    => $storyOutline,
        'volume_outlines'  => $volumeOutlines,
        'characters'       => $characters,
        'foreshadowing'    => $foreshadowing,
        'memory_atoms'     => $memoryAtoms,
        'arc_summaries'    => $arcSummaries,
        'novel_state'      => $novelState,
        'consistency_logs' => $consistencyLogs,
        'novel_settings'   => $novelSettings,
    ];
}
