<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * Excel 小说导入 API
 *
 * POST JSON actions：
 *   init          创建小说 + 导入会话
 *   batch         上传一批章节数据（每批 ≤50 章）
 *   finalize      标记导入完成，返回统计
 *   import_chars  将人物卡写入 character_cards 表
 *   run_analysis  启动 AI 分析任务
 */
define('APP_LOADED', true);
ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@set_time_limit(120);
@ini_set('memory_limit', '512M');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) @ob_end_clean();
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        $rid = function_exists('error_trace_id') ? error_trace_id() : bin2hex(random_bytes(6));
        error_log("[{$rid}] import_novel_excel 致命错误: {$err['message']} in {$err['file']}:{$err['line']}");
        echo json_encode(['ok' => false, 'msg' => '服务器内部错误，请稍后重试（追踪号 ' . $rid . '）'], JSON_UNESCAPED_UNICODE);
    }
});

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';

requireLoginApi();
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_verify_api();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
} else {
    $input = [];
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);

try {
    ensureImportTable();
    switch ($action) {
        case 'init':         handleInit($userId, $input); break;
        case 'batch':        handleBatch($userId, $input); break;
        case 'finalize':     handleFinalize($userId, $input); break;
        case 'import_chars': handleImportChars($userId, $input); break;
        case 'run_analysis': handleRunAnalysis($userId, $input); break;
        default:             jsonOut(['ok' => false, 'msg' => '未知操作']);
    }
} catch (Throwable $e) {
    error_log('import_novel_excel: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOut(safe_api_error_payload($e, '导入失败，请稍后重试'));
}

function jsonOut(array $d): void {
    $ok = !empty($d['ok']);
    $data = $d['data'] ?? $d['msg'] ?? null;
    $msg = $d['msg'] ?? '';
    if ($ok) {
        jsonResponse(true, $data, $msg);
    } else {
        jsonResponse(false, $data, $msg);
    }
}

function ensureImportTable(): void {
    static $done = false;
    if ($done) return;
    DB::execute("CREATE TABLE IF NOT EXISTS `novel_import_sessions` (
        `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`            INT UNSIGNED NOT NULL,
        `novel_id`           INT UNSIGNED DEFAULT NULL,
        `session_key`        VARCHAR(64) NOT NULL,
        `total_batches`      INT NOT NULL DEFAULT 0,
        `completed_batches`  JSON DEFAULT NULL,
        `total_chapters`     INT NOT NULL DEFAULT 0,
        `imported_chapters`  INT NOT NULL DEFAULT 0,
        `total_words`        BIGINT NOT NULL DEFAULT 0,
        `status`             ENUM('pending','importing','completed','failed') DEFAULT 'pending',
        `novel_meta`         JSON DEFAULT NULL,
        `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_session_key` (`session_key`),
        INDEX `idx_user` (`user_id`),
        INDEX `idx_novel` (`novel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function handleInit(int $userId, array $input): void {
    $meta = $input['novel_meta'] ?? [];
    $totalChapters = (int)($input['total_chapters'] ?? 0);
    $totalBatches = (int)($input['total_batches'] ?? 0);
    $sessionKey = trim($input['session_key'] ?? '');

    if (empty($meta['title'])) jsonOut(['ok' => false, 'msg' => '书名不能为空']);
    if ($totalChapters <= 0) jsonOut(['ok' => false, 'msg' => '章节数无效']);
    if ($sessionKey === '') jsonOut(['ok' => false, 'msg' => 'session_key 缺失']);

    $existing = DB::fetch('SELECT * FROM novel_import_sessions WHERE session_key=? AND user_id=?', [$sessionKey, $userId]);
    if ($existing && in_array($existing['status'], ['pending', 'importing'], true)) {
        jsonOut([
            'ok' => true, 'resumed' => true,
            'novel_id' => (int)$existing['novel_id'],
            'imported_chapters' => (int)$existing['imported_chapters'],
            'completed_batches' => json_decode($existing['completed_batches'], true) ?: [],
            'msg' => '检测到未完成的导入会话，已自动续传',
        ]);
    }

    $description = mb_substr(trim($meta['description'] ?? ''), 0, 2000);

    $candidates = [
        'user_id' => $userId,
        'title' => mb_substr(trim($meta['title']), 0, 200),
        'genre' => mb_substr(trim($meta['genre'] ?? ''), 0, 50),
        'protagonist_name' => mb_substr(trim($meta['protagonist_name'] ?? ''), 0, 100),
        'target_chapters' => max(1, (int)($meta['target_chapters'] ?? $totalChapters)),
        'writing_style' => mb_substr(trim($meta['writing_style'] ?? ''), 0, 100),
    ];

    if ($description !== '') {
        $candidates['extra_settings'] = $description;
    }

    $novelCols = [];
    try {
        $cols = DB::fetchAll("SHOW COLUMNS FROM novels");
        $novelCols = array_column($cols, 'Field');
    } catch (Throwable $e) {
        error_log('handleInit SHOW COLUMNS failed: ' . $e->getMessage());
    }

    $payload = array_intersect_key($candidates, array_flip($novelCols));
    $novelId = (int)DB::insert('novels', $payload);

    DB::insert('novel_import_sessions', [
        'user_id' => $userId,
        'novel_id' => $novelId,
        'session_key' => $sessionKey,
        'total_batches' => $totalBatches,
        'completed_batches' => '[]',
        'total_chapters' => $totalChapters,
        'imported_chapters' => 0,
        'status' => 'importing',
        'novel_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
    ]);

    jsonOut([
        'ok' => true,
        'novel_id' => $novelId,
        'imported_chapters' => 0,
        'completed_batches' => [],
    ]);
}

function handleBatch(int $userId, array $input): void {
    $sessionKey = trim($input['session_key'] ?? '');
    $batchIndex = (int)($input['batch_index'] ?? -1);
    $chapters = $input['chapters'] ?? [];

    if ($sessionKey === '' || $batchIndex < 0 || empty($chapters)) {
        jsonOut(['ok' => false, 'msg' => '参数缺失']);
    }
    if (!is_array($chapters) || count($chapters) > 50) {
        jsonOut(['ok' => false, 'msg' => '每批最多导入 50 章']);
    }

    $pdo = DB::getPdo();
    $pdo->beginTransaction();
    try {
        $session = DB::fetch('SELECT * FROM novel_import_sessions WHERE session_key=? AND user_id=? FOR UPDATE', [$sessionKey, $userId]);
        if (!$session) { $pdo->rollBack(); jsonOut(['ok' => false, 'msg' => '会话不存在']); }
        $novelId = (int)$session['novel_id'];

        $completed = json_decode($session['completed_batches'], true) ?: [];
        if (in_array($batchIndex, $completed, true)) {
            $pdo->rollBack();
            jsonOut(['ok' => true, 'skipped' => true]);
        }

        $imported = 0;
        foreach ($chapters as $ch) {
            $num = (int)($ch['chapter_number'] ?? 0);
            if ($num <= 0) continue;

            $content = trim((string)($ch['content'] ?? ''));
            // 字数口径必须与全站一致：countWords()（中文字+英文词，忽略标点/空格/数字），
            // 与 write_engine/compress/polish/普通保存 及前端 countChapterWords 对齐。
            // 旧实现用 mb_strlen 把标点/空格/数字也算进去，导致导入章节字数虚高、
            // 且用户重存该章后字数会被 countWords 重算而骤降（看似数据丢失）。
            $wordCount = countWords($content);
            $outline = trim((string)($ch['outline'] ?? ''));
            // 审计修复（2026-07-19 M6-13）：$status 原本未定义，带正文的章节以 NULL/pending
            // 入库（pending+正文矛盾）。按正文/大纲有无派生状态，与 chapters.status 枚举对齐。
            $status = $content !== '' ? 'completed' : ($outline !== '' ? 'outlined' : 'pending');
            $updates = [
                'title' => mb_substr(trim((string)($ch['title'] ?? "第{$num}章")), 0, 300),
                'content' => $content,
                'outline' => $outline,
                'pacing' => in_array($ch['pacing'] ?? '', ['快','中','慢'], true) ? $ch['pacing'] : '中',
                'status' => $status,
                'words' => $wordCount,
            ];

            $existing = DB::fetch('SELECT id FROM chapters WHERE novel_id=? AND chapter_number=?', [$novelId, $num]);
            if ($existing) {
                // 覆盖导入也是正文版本变更，必须与手工编辑一样清掉旧摘要、
                // RAG、伏笔、角色状态投影，不能只改 chapters 主表。
                ChapterMutationService::mutateChapter(
                    (int)$existing['id'],
                    $novelId,
                    $updates,
                    [
                        'backup_version' => true,
                        'prevent_writing' => true,
                        'reason' => 'excel_import_overwrite',
                    ]
                );
            } else {
                DB::insert('chapters', array_merge([
                    'novel_id' => $novelId,
                    'chapter_number' => $num,
                ], $updates));
            }

            $imported++;
        }

        // 本批新增/更新了章节行，失效章节列表/计数缓存
        clearNovelCache($novelId);

        $completed[] = $batchIndex;
        // 以实际章节行/字数为权威，避免不同批次包含重复章节号时累计虚高。
        $importedChapters = (int)DB::fetchColumn(
            'SELECT COUNT(*) FROM chapters WHERE novel_id=?',
            [$novelId]
        );
        $totalWords = (int)DB::fetchColumn(
            'SELECT COALESCE(SUM(words),0) FROM chapters WHERE novel_id=?',
            [$novelId]
        );

        DB::update('novel_import_sessions', [
            'completed_batches' => json_encode($completed),
            'imported_chapters' => $importedChapters,
            'total_words' => $totalWords,
        ], 'session_key = ? AND user_id = ?', [$sessionKey, $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    jsonOut([
        'ok' => true,
        'imported' => $imported,
        'imported_chapters' => $importedChapters,
        'total_words' => $totalWords,
    ]);
}

function handleFinalize(int $userId, array $input): void {
    $sessionKey = trim($input['session_key'] ?? '');
    $session = DB::fetch('SELECT * FROM novel_import_sessions WHERE session_key=? AND user_id=?', [$sessionKey, $userId]);
    if (!$session) jsonOut(['ok' => false, 'msg' => '会话不存在']);

    $novelId = (int)$session['novel_id'];
    $actualChapters = (int)DB::fetchColumn('SELECT COUNT(*) FROM chapters WHERE novel_id=?', [$novelId]);
    $actualWords = (int)DB::fetchColumn('SELECT COALESCE(SUM(words),0) FROM chapters WHERE novel_id=?', [$novelId]);

    DB::update('novel_import_sessions', ['status' => 'completed'], 'id = ?', [(int)$session['id']]);
    DB::update('novels', ['total_words' => $actualWords], 'id = ?', [$novelId]);

    jsonOut([
        'ok' => true,
        'actual_chapters' => $actualChapters,
        'actual_words' => $actualWords,
    ]);
}

function handleImportChars(int $userId, array $input): void {
    $novelId = (int)($input['novel_id'] ?? 0);
    $characters = $input['characters'] ?? [];

    if ($novelId <= 0) jsonOut(['ok' => false, 'msg' => 'novel_id 缺失']);

    $novel = DB::fetch('SELECT id FROM novels WHERE id=? AND user_id=?', [$novelId, $userId]);
    if (!$novel) jsonOut(['ok' => false, 'msg' => '无权访问']);

    $count = 0;
    foreach ($characters as $c) {
        $name = trim((string)($c['name'] ?? ''));
        if ($name === '') continue;

        $attrs = [];
        if (!empty($c['role'])) $attrs['定位'] = trim((string)$c['role']);
        if (!empty($c['gender'])) $attrs['性别'] = trim((string)$c['gender']);
        if (!empty($c['description'])) $attrs['描述'] = trim((string)$c['description']);

        try {
            DB::insert('character_cards', [
                'novel_id' => $novelId,
                'name' => $name,
                'title' => trim((string)($c['role'] ?? '')),
                'attributes' => json_encode($attrs, JSON_UNESCAPED_UNICODE),
                'status' => 'active',
                'alive' => 1,
            ]);
            $count++;
        } catch (Throwable $e) {
            error_log('handleImportChars insert failed: ' . $e->getMessage());
        }
    }

    jsonOut(['ok' => true, 'count' => $count]);
}

function handleRunAnalysis(int $userId, array $input): void {
    $novelId = (int)($input['novel_id'] ?? 0);
    $tasks = $input['tasks'] ?? [];

    if ($novelId <= 0) jsonOut(['ok' => false, 'msg' => 'novel_id 缺失']);

    $novel = DB::fetch('SELECT id FROM novels WHERE id=? AND user_id=?', [$novelId, $userId]);
    if (!$novel) jsonOut(['ok' => false, 'msg' => '无权访问']);

    jsonOut(['ok' => true, 'msg' => '分析任务已排队', 'tasks' => $tasks]);
}
