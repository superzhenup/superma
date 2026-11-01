<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 小说导入 API
 * 支持从JSON文件导入单本/多本小说数据
 * 自动处理ID冲突：导入时重新分配ID，关联数据随之映射
 */

// 禁止向页面输出错误，由统一错误处理器处理
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

defined('APP_LOADED') or define('APP_LOADED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
registerApiErrorHandlers();
require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/NovelBackupContract.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => '方法不允许'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取参数
$novelId      = intval($_POST['novel_id'] ?? 0);  // 0=新建, >0=导入到指定小说
$importMode   = $_POST['import_mode'] ?? 'create'; // create=新建导入
$skipContent  = ($_POST['skip_content'] ?? '0') === '1'; // 是否跳过正文（大文件时可加速）

if ($importMode !== 'create') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'JSON merge import is not supported; use import_mode=create'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '文件上传失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file     = $_FILES['file'];
$fileExt  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($fileExt !== 'json') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '仅支持JSON格式文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 审计修复 M-04（2026-06-12）：添加文件大小限制（50MB）
$maxFileSize = 50 * 1024 * 1024;
if ($file['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '文件超过50MB限制'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $content = file_get_contents($file['tmp_name']);
    // 安全加固（S3）：限制 JSON 解析深度，防止超大嵌套导致栈溢出
    $data    = json_decode($content, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON格式错误：' . json_last_error_msg());
    }
    if (!is_array($data)) throw new InvalidArgumentException('无法识别的文件格式');
    NovelBackupContract::assertVersion($data);

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

    // 审计 P0：归属由 session userId 强制注入，禁止文件控制
    $sessionUserId = currentUserId();
    if ($sessionUserId <= 0) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '会话异常，请重新登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $results = [];
    foreach ($novels as $novelData) {
        $results[] = importSingleNovel($novelData, $skipContent, $sessionUserId);
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
    echo json_encode(safe_api_error_payload($e, '导入失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
}

/**
 * 导入单本小说
 */
function importSingleNovel(array $data, bool $skipContent, int $sessionUserId): array {
    NovelBackupContract::assertVersion($data);
    if ($sessionUserId <= 0) throw new InvalidArgumentException('导入需要有效用户');
    $srcNovel = $data['novel'] ?? [];
    if (!is_array($srcNovel)) throw new InvalidArgumentException('小说资料格式无效');
    $payload = NovelBackupContract::importNovel($srcNovel);
    $title = $payload['title'] ?? '未命名小说';
    if (trim($title) === '') throw new InvalidArgumentException('小说标题不能为空');

    $chapters = $data['chapters'] ?? [];
    $synopses = $data['chapter_synopses'] ?? [];
    if (!is_array($chapters) || !is_array($synopses)) throw new InvalidArgumentException('章节或概要格式无效');
    $sourceSynopsisIds = [];
    foreach (array_merge($chapters, $synopses) as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('章节或概要格式无效');
        NovelBackupContract::assertSourceNovel($row, $srcNovel);
    }
    foreach ($synopses as $synopsis) {
        $sourceId = $synopsis['id'] ?? null;
        if ($sourceId === null) continue;
        if ((!is_int($sourceId) && !is_string($sourceId))
            || filter_var($sourceId, FILTER_VALIDATE_INT) === false || (int)$sourceId <= 0
            || isset($sourceSynopsisIds[(int)$sourceId])) {
            throw new InvalidArgumentException('概要源 ID 无效或重复');
        }
        $sourceSynopsisIds[(int)$sourceId] = true;
    }

    // 检查同名小说
    $existing = DB::fetch('SELECT id FROM novels WHERE title = ?', [$title]);
    if ($existing) {
        // 同名则追加后缀
        $title .= '_导入_' . date('md_His');
    }

    // 1. Restore portable writing configuration. Instance foreign keys and
    // active worker controls are deliberately excluded by the shared contract.
    $payload['title'] = $title;
    // 审计 P0：归属强制为当前 session userId
    $payload['user_id'] = $sessionUserId;
    $newNovelId = DB::insert('novels', $payload);

    $chapterCount   = 0;
    $characterCount = 0;
    $chapterIdMap   = []; // 旧ID => 新ID 映射
    $completedChapters  = 0; // 实际以正文导入的已完成章
    $completedWords     = 0;
    $unfinishedChapters = 0; // pending/outlined/failed：整书不能再算已完成

    // 2. 导入章节

    // v1.11.9: 导入前逻辑连贯性一致性前置审查，防编号断档导致 Resolve 闪退
    if (!empty($chapters)) {
        $numbers = array_filter(array_column($chapters, 'chapter_number'), fn($n) => $n !== null && $n !== '');
        if (!empty($numbers)) {
            $numbers = array_map('intval', $numbers);
            sort($numbers);
            $min = min($numbers);
            $max = max($numbers);
            // 校验章节范围与数组长度是否契合，且不重叠
            if (count($numbers) !== ($max - $min + 1) || count(array_unique($numbers)) !== count($numbers)) {
                throw new Exception("导入小说失败：大纲数据中的章节编号不连续或存在重复（{$min}~{$max}章之间有断档），请先检查数据源。");
            }
        }
    }

    // 审计修复（2026-07-19 H-03）：synopsis_id 是源库自增 ID，原样导入会造成
    // 悬空/跨书引用。导入时不携带该列，待概要导入完成后按 旧ID→新ID 映射回写。
    $pendingSynopsisLinks = []; // newChapterId => original source chapter
    $synopsisIdMap = [];        // oldSynopsisId => newSynopsisId
    $synopsisChapterMap = [];   // source chapter number => new IDs (must be unique)

    foreach ($chapters as $ch) {
        $oldId = $ch['id'] ?? 0;
        $sourceChapter = $ch;
        unset($ch['id']);
        $ch['novel_id'] = $newNovelId;

        // 可选跳过正文。已完成状态不能脱离正文单独导入，否则会形成
        // completed + empty + non-Canon 的非法源状态。
        if ($skipContent) {
            $ch['content'] = '';
            $ch['words'] = 0;
            if (($ch['status'] ?? '') === 'completed') {
                $ch['status'] = trim((string)($ch['outline'] ?? '')) !== ''
                    ? 'outlined'
                    : 'pending';
            }
        }

        // 只保留chapters表存在的字段（synopsis_id 除外，导入后统一回写）
        $filtered = NovelBackupContract::importChapter($ch);
        $filtered['novel_id'] = $newNovelId;

        // Exported Candidate/policy identifiers are not authoritative in the
        // new database. Imported completed prose is legacy Canon identified by
        // its exact stored bytes; no Candidate, policy, or promotion timestamp
        // is fabricated. Empty completed rows are downgraded to writable state.
        $storedContent = (string)($filtered['content'] ?? '');
        if (($filtered['status'] ?? '') === 'completed' && trim($storedContent) === '') {
            $filtered['status'] = trim((string)($filtered['outline'] ?? '')) !== ''
                ? 'outlined'
                : 'pending';
            $filtered['words'] = 0;
        }
        $isLegacyCanon = ($filtered['status'] ?? '') === 'completed'
            && trim($storedContent) !== '';
        $filtered['canonical_state'] = $isLegacyCanon ? 'legacy' : 'none';
        $filtered['content_revision_hash'] = $isLegacyCanon
            ? hash('sha256', $storedContent)
            : null;
        $filtered['canonicalized_at'] = null;
        $filtered['canonical_candidate_uid'] = null;
        $filtered['write_policy_snapshot_json'] = null;

        $newChapterId = DB::insert('chapters', $filtered);
        if ($oldId > 0) {
            $chapterIdMap[$oldId] = $newChapterId;
        }
        $pendingSynopsisLinks[$newChapterId] = $sourceChapter;
        $chapterCount++;
        if ($isLegacyCanon) {
            $completedChapters++;
            $completedWords += max(0, (int)($filtered['words'] ?? 0));
        } elseif (($filtered['status'] ?? 'pending') !== 'skipped') {
            $unfinishedChapters++;
        }
    }

    // 审计修复 F05（2026-11-01）：主表进度必须由实际导入的章节重建。源文件的
    // current_chapter/total_words/completed 在跳过正文（已完成章被降级）时不再成立；
    // 口径与 updateNovelStats() 全量重算一致：已完成章数、已完成章字数之和。
    $progress = ['current_chapter' => $completedChapters, 'total_words' => $completedWords];
    if (($payload['status'] ?? '') === 'completed' && ($completedChapters === 0 || $unfinishedChapters > 0)) {
        $progress['status'] = $completedChapters > 0 ? 'paused' : 'draft';
    }
    DB::update('novels', $progress, 'id = ?', [$newNovelId]);

    // 3. 导入章节概要
    foreach ($synopses as $syn) {
        $oldSynopsisId = (int)($syn['id'] ?? 0);
        unset($syn['id']);
        $syn['novel_id'] = $newNovelId;
        $validCols = ['novel_id','chapter_number','synopsis','scene_breakdown','dialogue_beats',
                      'sensory_details','pacing','cliffhanger','foreshadowing','callbacks'];
        $filtered = array_intersect_key($syn, array_flip($validCols));
        $newSynopsisId = DB::insert('chapter_synopses', $filtered);
        if ($oldSynopsisId > 0) {
            $synopsisIdMap[$oldSynopsisId] = (int)$newSynopsisId;
        }
        $synopsisChapterMap[(int)($syn['chapter_number'] ?? 0)][] = (int)$newSynopsisId;
    }

    // 3.1 Explicit links use local ID remapping. Legacy files without the key
    // may infer a unique chapter-number match; explicit null never infers a link.
    foreach ($pendingSynopsisLinks as $linkedChapterId => $sourceChapter) {
        $linkedSynopsisId = NovelBackupContract::synopsisLink($sourceChapter, $synopsisIdMap, $synopsisChapterMap);
        if ($linkedSynopsisId !== null) {
            DB::update('chapters', ['synopsis_id' => $linkedSynopsisId], 'id = ? AND novel_id = ?', [$linkedChapterId, $newNovelId]);
        }
    }

    // 4. 导入全书故事大纲
    $storyOutline = $data['story_outline'] ?? null;
    if ($storyOutline) {
        unset($storyOutline['id']);
        $storyOutline['novel_id'] = $newNovelId;
        $validCols = ['novel_id','story_arc','act_division','major_turning_points',
                      'character_arcs','character_endpoints','character_progression',
                      'world_evolution','recurring_motifs'];
        $filtered = array_intersect_key($storyOutline, array_flip($validCols));
        DB::insert('story_outlines', $filtered);
    }

    // 5. 导入卷大纲
    $volumeOutlines = $data['volume_outlines'] ?? [];
    $volumeCols = [];
    try {
        $volumeCols = array_column(DB::fetchAll('SHOW COLUMNS FROM volume_outlines'), 'Field');
    } catch (Throwable $e) {
        error_log('importSingleNovel SHOW volume_outlines columns failed: ' . $e->getMessage());
    }
    $volumeColSet = array_flip($volumeCols);

    foreach ($volumeOutlines as $vo) {
        unset($vo['id']);
        $vo['novel_id'] = $newNovelId;

        if (isset($vo['volume_number']) && !isset($vo['volume_index'])) $vo['volume_index'] = $vo['volume_number'];
        if (isset($vo['volume_index']) && !isset($vo['volume_number'])) $vo['volume_number'] = $vo['volume_index'];
        if (isset($vo['start_chapter']) && !isset($vo['chapter_from'])) $vo['chapter_from'] = $vo['start_chapter'];
        if (isset($vo['chapter_from']) && !isset($vo['start_chapter'])) $vo['start_chapter'] = $vo['chapter_from'];
        if (isset($vo['end_chapter']) && !isset($vo['chapter_to'])) $vo['chapter_to'] = $vo['end_chapter'];
        if (isset($vo['chapter_to']) && !isset($vo['end_chapter'])) $vo['end_chapter'] = $vo['chapter_to'];
        if (isset($vo['themes']) && !isset($vo['theme'])) $vo['theme'] = $vo['themes'];
        if (isset($vo['theme']) && !isset($vo['themes'])) $vo['themes'] = $vo['theme'];
        if (!empty($vo['chapters_range']) && (!isset($vo['start_chapter']) || !isset($vo['end_chapter']))
            && preg_match('/(\d+)\D+(\d+)/', (string)$vo['chapters_range'], $m)) {
            $vo['start_chapter'] = $vo['chapter_from'] = (int)$m[1];
            $vo['end_chapter'] = $vo['chapter_to'] = (int)$m[2];
        }

        $filtered = array_intersect_key($vo, $volumeColSet);
        if ($filtered) {
            DB::insert('volume_outlines', $filtered);
        }
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
        $validCols = ['novel_id','story_momentum','current_location','location_chapter',
                      'location_transition','current_arc_summary','last_ingested_chapter',
                      'graph_start_chapter'];
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

    // 12. 恢复小说级自适应参数。只接受短、可打印的配置键和标量值，
    // 防止导入文件借动态列名或超大对象扩大数据库/提示词负担。
    $novelSettings = $data['novel_settings'] ?? [];
    if (is_array($novelSettings)) {
        foreach ($novelSettings as $setting) {
            if (!is_array($setting)) continue;
            $key = trim((string)($setting['setting_key'] ?? ''));
            $value = $setting['setting_value'] ?? null;
            if (
                $key === ''
                || preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $key) !== 1
                || (!is_scalar($value) && $value !== null)
            ) {
                continue;
            }
            $value = mb_substr((string)$value, 0, 10000);
            DB::insert('novel_settings', [
                'novel_id' => $newNovelId,
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }
        // 审计修复 L6（2026-08-16）：直写 novel_settings 后失效 ConfigCenter 的请求级
        // 缓存桶，防止同请求内后续 getNovel 读到导入前的空桶而错误回退全局默认值。
        if (class_exists('ConfigCenter')) {
            ConfigCenter::invalidateNovelCache($newNovelId);
        }
    }

    return [
        'status'          => 'success',
        'title'           => $title,
        'new_novel_id'    => $newNovelId,
        'chapter_count'   => $chapterCount,
        'character_count' => $characterCount,
    ];
}
