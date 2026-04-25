<?php
/**
 * 小说导入 API
 * 支持从JSON文件导入单本/多本小说数据
 * 自动处理ID冲突：导入时重新分配ID，关联数据随之映射
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
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '方法不允许']);
    exit;
}

// 获取参数
$novelId      = intval($_POST['novel_id'] ?? 0);  // 0=新建, >0=导入到指定小说
$importMode   = $_POST['import_mode'] ?? 'create'; // create=新建导入 / merge=合并到已有小说
$skipContent  = ($_POST['skip_content'] ?? '0') === '1'; // 是否跳过正文（大文件时可加速）

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => '文件上传失败']);
    exit;
}

$file     = $_FILES['file'];
$fileExt  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($fileExt !== 'json') {
    http_response_code(400);
    echo json_encode(['error' => '仅支持JSON格式文件']);
    exit;
}

try {
    $content = file_get_contents($file['tmp_name']);
    $data    = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON格式错误：' . json_last_error_msg());
    }

    // 判断是单本还是多本格式
    $novels = [];
    if (isset($data['novels']) && is_array($data['novels'])) {
        // 全部导出格式：{ novels: [...] }
        $novels = $data['novels'];
    } elseif (isset($data['novel']) && is_array($data['novel'])) {
        // 单本导出格式：{ novel: {...}, chapters: [...] }
        $novels = [$data];
    } else {
        throw new Exception('无法识别的文件格式');
    }

    $pdo = DB::getPdo();
    $pdo->beginTransaction();

    $results = [];
    foreach ($novels as $novelData) {
        $results[] = importSingleNovel($novelData, $importMode, $skipContent);
    }

    $pdo->commit();

    $totalImported  = count($results);
    $totalChapters  = array_sum(array_column($results, 'chapter_count'));
    $totalChars     = array_sum(array_column($results, 'character_count'));
    $totalErrors    = count(array_filter($results, fn($r) => $r['status'] === 'error'));

    echo json_encode([
        'success'        => true,
        'imported_count' => $totalImported,
        'total_chapters' => $totalChapters,
        'total_chars'    => $totalChars,
        'error_count'    => $totalErrors,
        'details'        => $results,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => '导入失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 导入单本小说
 */
function importSingleNovel(array $data, string $mode, bool $skipContent): array {
    $srcNovel = $data['novel'] ?? [];
    $title    = $srcNovel['title'] ?? '未命名小说';

    // 检查同名小说
    $existing = DB::fetch('SELECT id FROM novels WHERE title = ?', [$title]);
    if ($existing && $mode === 'create') {
        // 同名则追加后缀
        $title .= '_导入_' . date('md_His');
    }

    // 1. 创建小说记录（去掉原ID，让数据库自动分配）
    $novelFields = [];
    $novelValues = [];
    $skipCols = ['id', 'cancel_flag', 'created_at', 'updated_at'];

    foreach ($srcNovel as $k => $v) {
        if (in_array($k, $skipCols)) continue;
        $novelFields[] = $k;
        $novelValues[] = $v;
    }

    // 确保必填字段
    if (!in_array('title', $novelFields)) {
        $novelFields[] = 'title';
        $novelValues[] = $title;
    }
    // 覆盖标题为处理后的标题
    else {
        $idx = array_search('title', $novelFields);
        $novelValues[$idx] = $title;
    }

    $placeholders = array_fill(0, count($novelFields), '?');
    $newNovelId = DB::insert('novels', array_combine($novelFields, $novelValues));

    $chapterCount   = 0;
    $characterCount = 0;
    $chapterIdMap   = []; // 旧ID => 新ID 映射

    // 2. 导入章节
    $chapters = $data['chapters'] ?? [];
    foreach ($chapters as $ch) {
        $oldId = $ch['id'] ?? 0;
        unset($ch['id']);
        $ch['novel_id'] = $newNovelId;

        // 可选跳过正文
        if ($skipContent && isset($ch['content'])) {
            $ch['content'] = '';
        }

        // 只保留chapters表存在的字段
        $validCols = ['novel_id','chapter_number','title','outline','content','chapter_summary',
                      'used_tropes','hook','synopsis_id','status','words'];
        $filtered = array_intersect_key($ch, array_flip($validCols));

        $newChapterId = DB::insert('chapters', $filtered);
        if ($oldId > 0) {
            $chapterIdMap[$oldId] = $newChapterId;
        }
        $chapterCount++;
    }

    // 3. 导入章节概要
    $synopses = $data['chapter_synopses'] ?? [];
    foreach ($synopses as $syn) {
        unset($syn['id']);
        $syn['novel_id'] = $newNovelId;
        $validCols = ['novel_id','chapter_number','synopsis','scene_breakdown','dialogue_beats',
                      'sensory_details','pacing','cliffhanger','foreshadowing','callbacks'];
        $filtered = array_intersect_key($syn, array_flip($validCols));
        DB::insert('chapter_synopses', $filtered);
    }

    // 4. 导入全书故事大纲
    $storyOutline = $data['story_outline'] ?? null;
    if ($storyOutline) {
        unset($storyOutline['id']);
        $storyOutline['novel_id'] = $newNovelId;
        $validCols = ['novel_id','story_arc','act_division','major_turning_points',
                      'character_arcs','world_evolution','recurring_motifs'];
        $filtered = array_intersect_key($storyOutline, array_flip($validCols));
        DB::insert('story_outlines', $filtered);
    }

    // 5. 导入卷大纲
    $volumeOutlines = $data['volume_outlines'] ?? [];
    foreach ($volumeOutlines as $vo) {
        unset($vo['id']);
        $vo['novel_id'] = $newNovelId;
        $validCols = ['novel_id','volume_number','title','summary','chapters_range','themes'];
        $filtered = array_intersect_key($vo, array_flip($validCols));
        DB::insert('volume_outlines', $filtered);
    }

    // 6. 导入人物卡片
    $characters = $data['characters'] ?? [];
    $charIdMap = [];
    foreach ($characters as $char) {
        $oldId = $char['id'] ?? 0;
        unset($char['id']);
        $char['novel_id'] = $newNovelId;
        $validCols = ['novel_id','name','title','status','alive','attributes','last_updated_chapter'];
        $filtered = array_intersect_key($char, array_flip($validCols));
        $newCharId = DB::insert('character_cards', $filtered);
        if ($oldId > 0) $charIdMap[$oldId] = $newCharId;
        $characterCount++;
    }

    // 7. 导入伏笔
    $foreshadowing = $data['foreshadowing'] ?? [];
    foreach ($foreshadowing as $fs) {
        unset($fs['id']);
        $fs['novel_id'] = $newNovelId;
        $validCols = ['novel_id','description','planted_chapter','deadline_chapter','resolved_chapter'];
        $filtered = array_intersect_key($fs, array_flip($validCols));
        DB::insert('foreshadowing_items', $filtered);
    }

    // 8. 导入记忆原子
    $memoryAtoms = $data['memory_atoms'] ?? [];
    foreach ($memoryAtoms as $ma) {
        unset($ma['id']);
        $ma['novel_id'] = $newNovelId;
        // 兼容旧导出格式的列名映射
        if (isset($ma['category'])) { $ma['atom_type'] = $ma['category']; unset($ma['category']); }
        if (isset($ma['chapter_number'])) { $ma['source_chapter'] = $ma['chapter_number']; unset($ma['chapter_number']); }
        if (isset($ma['importance'])) { $ma['confidence'] = $ma['importance']; unset($ma['importance']); }
        $validCols = ['novel_id','atom_type','content','source_chapter','confidence','metadata'];
        $filtered = array_intersect_key($ma, array_flip($validCols));
        DB::insert('memory_atoms', $filtered);
    }

    // 9. 导入弧段摘要
    $arcSummaries = $data['arc_summaries'] ?? [];
    foreach ($arcSummaries as $arc) {
        unset($arc['id']);
        $arc['novel_id'] = $newNovelId;
        $validCols = ['novel_id','arc_index','chapter_from','chapter_to','summary'];
        $filtered = array_intersect_key($arc, array_flip($validCols));
        DB::insert('arc_summaries', $filtered);
    }

    // 10. 导入小说状态
    $novelState = $data['novel_state'] ?? null;
    if ($novelState) {
        $novelState['novel_id'] = $newNovelId;
        $validCols = ['novel_id','story_momentum','current_arc_summary','last_ingested_chapter'];
        $filtered = array_intersect_key($novelState, array_flip($validCols));
        DB::insert('novel_state', $filtered);
    }

    // 11. 导入一致性日志
    $consistencyLogs = $data['consistency_logs'] ?? [];
    foreach ($consistencyLogs as $cl) {
        unset($cl['id']);
        $cl['novel_id'] = $newNovelId;
        $validCols = ['novel_id','chapter_number','check_type','issues'];
        $filtered = array_intersect_key($cl, array_flip($validCols));
        DB::insert('consistency_logs', $filtered);
    }

    return [
        'status'          => 'success',
        'title'           => $title,
        'new_novel_id'    => $newNovelId,
        'chapter_count'   => $chapterCount,
        'character_count' => $characterCount,
    ];
}
