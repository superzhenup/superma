<?php
/**
 * 小说导出 API
 * 支持单本/全部导出为JSON格式
 * 包含：小说基本信息、章节、章节概要、全书大纲、人物卡片、伏笔、记忆原子、弧段摘要等
 */

error_reporting(E_ALL);
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
        // 全部导出
        $novels = DB::fetchAll('SELECT id FROM novels ORDER BY updated_at DESC');
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
        ];
        $fileName = 'novels_all_' . date('Ymd_His');
    }

    // 输出JSON文件下载
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '导出失败：' . $e->getMessage()]);
}

/**
 * 导出单本小说的完整数据
 */
function exportSingleNovel(int $novelId, bool $skipContent = false): ?array {
    // 1. 小说基本信息
    $novel = DB::fetch('SELECT * FROM novels WHERE id = ?', [$novelId]);
    if (!$novel) return null;

    // 移除不必要导出的字段
    unset($novel['cancel_flag']);

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

    // 4. 全书故事大纲
    $storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id = ?', [$novelId]);

    // 5. 卷大纲
    $volumeOutlines = DB::fetchAll(
        'SELECT * FROM volume_outlines WHERE novel_id = ? ORDER BY volume_number', [$novelId]
    );

    // 6. 人物卡片
    $characters = DB::fetchAll(
        'SELECT * FROM character_cards WHERE novel_id = ? ORDER BY id', [$novelId]
    );

    // 7. 伏笔
    $foreshadowing = DB::fetchAll(
        'SELECT id, novel_id, description, planted_chapter, deadline_chapter, resolved_chapter, created_at
         FROM foreshadowing_items WHERE novel_id = ? ORDER BY planted_chapter', [$novelId]
    );

    // 8. 记忆原子
    $memoryAtoms = DB::fetchAll(
        'SELECT id, novel_id, atom_type, content, source_chapter, confidence, metadata, created_at
         FROM memory_atoms WHERE novel_id = ? ORDER BY source_chapter, id', [$novelId]
    );

    // 9. 弧段摘要
    $arcSummaries = DB::fetchAll(
        'SELECT * FROM arc_summaries WHERE novel_id = ? ORDER BY arc_index', [$novelId]
    );

    // 10. 小说状态
    $novelState = DB::fetch('SELECT * FROM novel_state WHERE novel_id = ?', [$novelId]);

    // 11. 一致性日志（可选，数据量可能较大，默认不导出content详情）
    $consistencyLogs = DB::fetchAll(
        'SELECT id, novel_id, chapter_number, check_type, issues, created_at
         FROM consistency_logs WHERE novel_id = ? ORDER BY chapter_number', [$novelId]
    );

    return [
        'export_version'   => '1.0',
        'export_time'      => date('Y-m-d H:i:s'),
        'novel'            => $novel,
        'chapters'         => $chapters,
        'chapter_synopses' => $synopses,
        'story_outline'    => $storyOutline ?: null,
        'volume_outlines'  => $volumeOutlines,
        'characters'       => $characters,
        'foreshadowing'    => $foreshadowing,
        'memory_atoms'     => $memoryAtoms,
        'arc_summaries'    => $arcSummaries,
        'novel_state'      => $novelState ?: null,
        'consistency_logs' => $consistencyLogs,
    ];
}
