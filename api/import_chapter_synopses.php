<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 导入章节概要 API
 * 支持格式：JSON / Excel(CSV) / TXT
 */

// 关闭错误显示，由统一错误处理器记录日志
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 开启输出缓冲
ob_start();

// 定义常量，允许直接访问
defined('APP_LOADED') or define('APP_LOADED', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
registerApiErrorHandlers();

// 大文件导入需要更长时间和内存（必须在 config.php 之后，因为 CFG_TIME_MEDIUM 在那里定义）
set_time_limit(CFG_TIME_MEDIUM);   // 5分钟超时
ini_set('memory_limit', '512M');

// 审计修复 M9（2026-08-16）：移除生产调试日志（storage/import_debug.log）。
// 原实现在每次请求无条件追加含 php.ini 配置、文件名等信息的 JSON 日志，无开关、
// 大部分路径无轮转，长期运行无限膨胀且泄露服务器环境信息。
// 错误诊断统一走 error_log（PHP 错误日志自带轮转），本文件各错误路径均已保留
// error_log 输出（含追踪号、异常类、堆栈），信息无损失。

// 注册关闭函数，捕获致命错误
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR])) {
        // 清空所有输出缓冲区
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // 设置响应头
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        // 记录错误（审计修复 M9：仅走 PHP 错误日志，不再写 import_debug.log）

        $rid = error_trace_id();
        error_log(sprintf('[%s] import_chapter_synopses shutdown: %s in %s:%d', $rid, $error['message'], $error['file'], $error['line']));
        echo json_encode([
            'error'      => '服务器内部错误，请稍后重试',
            'code'       => 'internal_error',
            'request_id' => $rid,
        ], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ChapterSynopsisTextFormat.php';

// 清空所有输出缓冲区
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 设置 JSON 响应头
header('Content-Type: application/json; charset=utf-8');

// 只接受POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $jsonResponse = json_encode(['error' => '方法不允许']);
    echo $jsonResponse === false ? '{"error":"方法不允许"}' : $jsonResponse;
    exit;
}

// 获取参数
$novelId = intval($_POST['novel_id'] ?? 0);
$importMode = $_POST['import_mode'] ?? 'incremental'; // incremental / overwrite

if (!$novelId) {
    http_response_code(400);
    $jsonResponse = json_encode(['error' => '缺少小说ID']);
    echo $jsonResponse === false ? '{"error":"缺少小说ID"}' : $jsonResponse;
    exit;
}

// 归属权校验
$userId = currentUserId();
checkNovelOwnership($novelId, $userId);

// 验证小说存在
$novel = DB::fetch('SELECT id, title FROM novels WHERE id = ?', [$novelId]);
if (!$novel) {
    http_response_code(404);
    $jsonResponse = json_encode(['error' => '小说不存在']);
    echo $jsonResponse === false ? '{"error":"小说不存在"}' : $jsonResponse;
    exit;
}

// 检查文件上传
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $jsonResponse = json_encode(['error' => '文件上传失败']);
    echo $jsonResponse === false ? '{"error":"文件上传失败"}' : $jsonResponse;
    exit;
}

$file = $_FILES['file'];
$filePath = $file['tmp_name'];
$fileName = $file['name'];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// 审计修复 M9（2026-08-16）：移除 file_upload 调试日志写入（见文件头注释）

// 根据文件类型解析
try {
    // 记录请求参数，便于调试
    error_log('import_chapter_synopses.php 请求参数: novel_id=' . $novelId . ', import_mode=' . $importMode . ', file=' . $fileName . ', file_ext=' . $fileExt);
    
    $chapters = [];
    
    switch ($fileExt) {
        case 'json':
            $chapters = parseJsonFile($filePath);
            break;
        case 'csv':
            $chapters = parseCsvFile($filePath);
            break;
        case 'txt':
            $chapters = parseTxtFile($filePath);
            break;
        default:
            http_response_code(400);
            $jsonResponse = json_encode(['error' => '不支持的文件格式，仅支持 JSON / CSV / TXT']);
            echo $jsonResponse === false ? '{"error":"不支持的文件格式，仅支持 JSON / CSV / TXT"}' : $jsonResponse;
            exit;
    }
    
    if (empty($chapters)) {
        http_response_code(400);
        $jsonResponse = json_encode(['error' => '文件中没有有效的章节数据']);
        echo $jsonResponse === false ? '{"error":"文件中没有有效的章节数据"}' : $jsonResponse;
        exit;
    }
    
    // 导入数据
    $result = importChapters($novelId, $chapters, $importMode);
    
    // 清空所有输出缓冲区，确保响应不被污染
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $jsonResponse = json_encode([
        'success' => true,
        'message' => '导入成功',
        'imported_count' => $result['imported_count'],
        'skipped_count' => $result['skipped_count'],
        'error_count' => $result['error_count']
    ]);
    if ($jsonResponse === false) {
        // JSON编码失败，返回简单的成功消息
        echo '{"success":true,"message":"导入成功"}';
    } else {
        echo $jsonResponse;
    }
    
} catch (Exception $e) {
    // 清空所有输出缓冲区，确保错误响应不被污染
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code(500);
    // 审计 P0：客户端只看到友好文案 + 追踪号；完整异常细节写日志与调试文件。
    $rid = error_trace_id();
    $errorMessage = '导入失败，请稍后重试（追踪号 ' . $rid . '）';
    error_log(sprintf('[%s] import_chapter_synopses %s: %s in %s:%d',
        $rid, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    error_log('[' . $rid . '] 堆栈跟踪: ' . $e->getTraceAsString());

    // 审计修复 M9（2026-08-16）：异常细节已完整写入 PHP 错误日志（上方两条 error_log），
    // 不再冗余写 storage/import_debug.log（无轮转、泄露环境信息）。

    // 确保设置响应头
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    $payload = [
        'error'      => $errorMessage,
        'code'       => 'import_failed',
        'request_id' => $rid,
    ];
    // 仅在显式开启调试开关时附带内部细节（默认关闭，见审计 P0「由环境开关控制」）。
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $payload['debug'] = [
            'message' => $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
            'trace'   => $e->getTrace(),
        ];
    }
    $jsonResponse = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($jsonResponse === false) {
        // JSON 编码失败，返回稳定的兜底错误
        echo '{"error":"导入失败，请稍后重试","code":"import_failed"}';
    } else {
        echo $jsonResponse;
    }
}

/**
 * 解析JSON文件
 */
function parseJsonFile(string $filePath): array {
    $content = file_get_contents($filePath);
    $data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON格式错误：' . json_last_error_msg());
    }
    
    // 支持两种格式：
    // 1. { "chapters": [...] }
    // 2. [...]
    $chapters = $data['chapters'] ?? $data;
    
    if (!is_array($chapters)) {
        throw new Exception('JSON格式错误：缺少chapters数组');
    }
    
    return $chapters;
}

/**
 * 解析CSV文件
 */
function parseCsvFile(string $filePath): array {
    $chapters = [];
    
    // 读取文件内容
    $content = file_get_contents($filePath);

    // 检测BOM并移除
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }

    // 审计修复（2026-07-19 H-中6）：CSV 文件可能用 GBK 编码（Excel 默认），
    // 中文表头「章节编号」等在 GBK 下无法与 UTF-8 匹配 -> 所有列查找失败。
    // 检测非 UTF-8 编码并转换为 UTF-8。
    if (!mb_check_encoding($content, 'UTF-8')) {
        $gbk = mb_convert_encoding($content, 'UTF-8', 'GBK');
        if ($gbk !== false) $content = $gbk;
    }
    
    // 将内容写入临时流
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    
    // 读取标题行
    $headers = fgetcsv($stream);
    if (!$headers) {
        fclose($stream);
        throw new Exception('CSV文件为空');
    }
    
    // 标准化标题（去除空格，转小写）
    $headers = array_map(function($h) {
        return strtolower(trim($h));
    }, $headers);
    
    // 查找必需列的索引
    $colMap = [
        'chapter_number' => array_search('章节编号', $headers),
        'title' => array_search('章节标题', $headers),
        'outline' => array_search('章节大纲', $headers),
        'synopsis' => array_search('章节概要', $headers),
        'pacing' => array_search('节奏', $headers),
        'cliffhanger' => array_search('结尾悬念', $headers)
    ];
    
    // 如果找不到中文列名，尝试英文
    if ($colMap['chapter_number'] === false) {
        $colMap['chapter_number'] = array_search('chapter_number', $headers);
    }
    if ($colMap['title'] === false) {
        $colMap['title'] = array_search('title', $headers);
    }
    if ($colMap['outline'] === false) {
        $colMap['outline'] = array_search('outline', $headers);
    }
    if ($colMap['synopsis'] === false) {
        $colMap['synopsis'] = array_search('synopsis', $headers);
    }
    if ($colMap['pacing'] === false) {
        $colMap['pacing'] = array_search('pacing', $headers);
    }
    if ($colMap['cliffhanger'] === false) {
        $colMap['cliffhanger'] = array_search('cliffhanger', $headers);
    }
    
    // 章节编号是必需的
    if ($colMap['chapter_number'] === false) {
        fclose($stream);
        throw new Exception('CSV缺少"章节编号"列');
    }
    
    // 读取数据行
    while (($row = fgetcsv($stream)) !== false) {
        // 审计修复（2026-07-19 H-中6）：$colMap['title'] 可能是 false（列不存在），
        // $row[false] 等价于 $row[0] -> 数据错位。改为显式检查 false。
        $chapter = [
            'chapter_number' => $colMap['chapter_number'] !== false ? intval($row[$colMap['chapter_number']] ?? 0) : 0,
            'title'          => $colMap['title']          !== false ? ($row[$colMap['title']]          ?? '') : '',
            'outline'        => $colMap['outline']        !== false ? ($row[$colMap['outline']]        ?? '') : '',
            'synopsis'       => $colMap['synopsis']       !== false ? ($row[$colMap['synopsis']]       ?? '') : '',
            'pacing'         => $colMap['pacing']         !== false ? ($row[$colMap['pacing']]         ?? '中') : '中',
            'cliffhanger'    => $colMap['cliffhanger']    !== false ? ($row[$colMap['cliffhanger']]    ?? '') : '',
        ];

        if ($chapter['chapter_number'] > 0) {
            $chapters[] = $chapter;
        }
    }
    
    fclose($stream);
    return $chapters;
}

/**
 * 解析TXT文件
 */
function parseTxtFile(string $filePath): array {
    $content = file_get_contents($filePath);
    if ($content === false) throw new RuntimeException('无法读取章节概要 TXT 文件');
    return ChapterSynopsisTextFormat::decode($content);
}

/**
 * 导入章节到数据库（批量优化版，支持千级章节）
 */
function importChapters(int $novelId, array $chapters, string $importMode): array {
    $importedCount = 0;
    $skippedCount = 0;
    $errorCount = 0;
    $createdCount = 0;
    
    // 提高PHP执行超时时间
    set_time_limit(CFG_TIME_MEDIUM); // 5分钟
    ini_set('memory_limit', '512M');
    
    $pdo = DB::getPdo();
    $pdo->beginTransaction();
    
    try {
        // 记录导入开始
        error_log('importChapters 开始导入: novel_id=' . $novelId . ', chapters_count=' . count($chapters) . ', import_mode=' . $importMode);
        
        // ===== 第1步：批量获取现有章节 =====
        // 审计修复（2026-07-19 M6-5a）：同时取回 title/outline，供增量模式判断是否仅填充空字段。
        $existingChapters = DB::fetchAll(
            'SELECT id, chapter_number, title, outline FROM chapters WHERE novel_id = ?', [$novelId]
        );
        $chapterMap = [];
        foreach ($existingChapters as $ch) {
            $chapterMap[$ch['chapter_number']] = [
                'id'      => $ch['id'],
                'title'   => (string)($ch['title'] ?? ''),
                'outline' => (string)($ch['outline'] ?? ''),
            ];
        }
        
        // ===== 第2步：批量获取现有概要 =====
        $existingSynopses = DB::fetchAll(
            'SELECT id, chapter_number FROM chapter_synopses WHERE novel_id = ?', [$novelId]
        );
        $synopsisMap = [];
        foreach ($existingSynopses as $s) {
            $synopsisMap[$s['chapter_number']] = $s['id'];
        }
        
        // ===== 第3步：分类——哪些需要新建，哪些需要更新 =====
        $newChapters = [];       // 需要新建的章节
        $updateChapters = [];    // 需要更新outline的已有章节
        $newSynopses = [];       // 需要新建的概要
        $updateSynopses = [];    // 需要更新概要的

        // 审计修复（2026-07-19 M6-5b）：文件内重复章号去重，避免 INSERT 撞唯一键致整单 500。
        // 保留首次出现的章节数据，后续重复章号计入 errorCount 跳过。
        $seenInFile = [];
        $dedupedChapters = [];
        foreach ($chapters as $ch) {
            $cn = intval($ch['chapter_number'] ?? 0);
            if ($cn <= 0) {
                $dedupedChapters[] = $ch;
                continue;
            }
            if (isset($seenInFile[$cn])) {
                $errorCount++;
                continue;
            }
            $seenInFile[$cn] = true;
            $dedupedChapters[] = $ch;
        }
        $chapters = $dedupedChapters;

        foreach ($chapters as $ch) {
            $chapterNumber = intval($ch['chapter_number'] ?? 0);
            if ($chapterNumber <= 0) {
                $errorCount++;
                continue;
            }
            
            // 章节不存在 → 需要新建
            if (!isset($chapterMap[$chapterNumber])) {
                $newChapters[] = [
                    'novel_id'       => $novelId,
                    'chapter_number' => $chapterNumber,
                    'title'          => $ch['title'] ?? ('第' . $chapterNumber . '章'),
                    'outline'        => $ch['outline'] ?? '',
                    'status'         => 'outlined',
                ];
            } 
            // 章节已存在 → 按导入模式决定是否更新
            else {
                $existing = $chapterMap[$chapterNumber];
                $patch = ['id' => $existing['id']];
                if (!empty($ch['title'])) {
                    // 审计修复（2026-07-19 M6-5a）：增量模式仅填充空字段，不覆盖用户已有数据；
                    // 覆盖模式用导入值替换（与 UI 文案一致）。
                    $shouldUpdateTitle = ($importMode === 'overwrite') || trim($existing['title']) === '';
                    if ($shouldUpdateTitle) {
                        $patch['title'] = $ch['title'];
                    }
                }
                if (!empty($ch['outline'])) {
                    $shouldUpdateOutline = ($importMode === 'overwrite') || trim($existing['outline']) === '';
                    if ($shouldUpdateOutline) {
                        $patch['outline'] = $ch['outline'];
                    }
                }
                if (isset($patch['title']) || isset($patch['outline'])) {
                    $updateChapters[] = $patch;
                }
            }
            
            // 概要不存在 → 需要新建
            if (!isset($synopsisMap[$chapterNumber])) {
                $newSynopses[] = [
                    'novel_id'       => $novelId,
                    'chapter_number' => $chapterNumber,
                    'synopsis'       => $ch['synopsis'] ?? '',
                    'pacing'         => $ch['pacing'] ?? '中',
                    'cliffhanger'    => $ch['cliffhanger'] ?? '',
                ];
            }
            // 概要已存在 → 需要更新
            else {
                $updateSynopses[] = [
                    'id'          => $synopsisMap[$chapterNumber],
                    'synopsis'    => $ch['synopsis'] ?? '',
                    'pacing'      => $ch['pacing'] ?? '',
                    'cliffhanger' => $ch['cliffhanger'] ?? '',
                ];
            }
            
            $importedCount++;
        }
        
        // ===== 第4步：批量INSERT新章节（每批100条） =====
        $batchSize = 100;
        $newChapterBatches = array_chunk($newChapters, $batchSize);
        foreach ($newChapterBatches as $batch) {
            $placeholders = [];
            $values = [];
            foreach ($batch as $c) {
                $placeholders[] = '(?, ?, ?, ?, ?)';
                $values[] = $c['novel_id'];
                $values[] = $c['chapter_number'];
                $values[] = $c['title'];
                $values[] = $c['outline'];
                $values[] = $c['status'];
            }
            $sql = 'INSERT INTO chapters (novel_id, chapter_number, title, outline, status) VALUES ' . implode(', ', $placeholders);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        }
        $createdCount = count($newChapters);
        
        // ===== 第5步：批量UPDATE已有章节（每批50条） =====
        $updateBatches = array_chunk($updateChapters, 50);
        foreach ($updateBatches as $batch) {
            $cases = [];
            $values = [];
            // 批量更新outline
            $outlineCases = [];
            $outlineValues = [];
            $titleCases = [];
            $titleValues = [];
            $ids = [];
            foreach ($batch as $c) {
                $ids[] = $c['id'];
                if (!empty($c['outline'])) {
                    $outlineCases[] = 'WHEN ? THEN ?';
                    $outlineValues[] = $c['id'];
                    $outlineValues[] = $c['outline'];
                }
                if (!empty($c['title'])) {
                    $titleCases[] = 'WHEN ? THEN ?';
                    $titleValues[] = $c['id'];
                    $titleValues[] = $c['title'];
                }
            }
            
            $setParts = [];
            $allValues = [];
            if (!empty($outlineCases)) {
                $setParts[] = 'outline = CASE id ' . implode(' ', $outlineCases) . ' ELSE outline END';
                $allValues = array_merge($allValues, $outlineValues);
            }
            if (!empty($titleCases)) {
                $setParts[] = 'title = CASE id ' . implode(' ', $titleCases) . ' ELSE title END';
                $allValues = array_merge($allValues, $titleValues);
            }
            
            if (!empty($setParts)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $allValues = array_merge($allValues, $ids);
                $sql = 'UPDATE chapters SET ' . implode(', ', $setParts) . ' WHERE id IN (' . $placeholders . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($allValues);
            }
        }
        
        // ===== 第6步：批量INSERT新概要（每批100条） =====
        $newSynopsisBatches = array_chunk($newSynopses, $batchSize);
        foreach ($newSynopsisBatches as $batch) {
            $placeholders = [];
            $values = [];
            foreach ($batch as $s) {
                $placeholders[] = '(?, ?, ?, ?, ?)';
                $values[] = $s['novel_id'];
                $values[] = $s['chapter_number'];
                $values[] = $s['synopsis'];
                $values[] = $s['pacing'];
                $values[] = $s['cliffhanger'];
            }
            $sql = 'INSERT INTO chapter_synopses (novel_id, chapter_number, synopsis, pacing, cliffhanger) VALUES ' . implode(', ', $placeholders);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        }
        
        // ===== 第7步：批量UPDATE已有概要（每批50条） =====
        if ($importMode === 'overwrite') {
            // 覆盖模式：直接批量更新
            $synopsisUpdateBatches = array_chunk($updateSynopses, 50);
            foreach ($synopsisUpdateBatches as $batch) {
                $synopsisCases = [];
                $pacingCases = [];
                $cliffCases = [];
                $values = [];
                $ids = [];
                foreach ($batch as $s) {
                    $ids[] = $s['id'];
                    $synopsisCases[] = 'WHEN ? THEN ?';
                    $values[] = $s['id'];
                    $values[] = $s['synopsis'];
                    $pacingCases[] = 'WHEN ? THEN ?';
                    $values[] = $s['id'];
                    $values[] = $s['pacing'];
                    $cliffCases[] = 'WHEN ? THEN ?';
                    $values[] = $s['id'];
                    $values[] = $s['cliffhanger'];
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $values = array_merge($values, $ids);
                $sql = 'UPDATE chapter_synopses SET '
                    . 'synopsis = CASE id ' . implode(' ', $synopsisCases) . ' END, '
                    . 'pacing = CASE id ' . implode(' ', $pacingCases) . ' END, '
                    . 'cliffhanger = CASE id ' . implode(' ', $cliffCases) . ' END '
                    . 'WHERE id IN (' . $placeholders . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
            }
        } else {
            // 增量模式：只更新非空字段
            foreach ($updateSynopses as $s) {
                $updateFields = [];
                $updateValues = [];
                if (!empty($s['synopsis'])) {
                    $updateFields[] = 'synopsis = ?';
                    $updateValues[] = $s['synopsis'];
                }
                if (!empty($s['pacing'])) {
                    $updateFields[] = 'pacing = ?';
                    $updateValues[] = $s['pacing'];
                }
                if (!empty($s['cliffhanger'])) {
                    $updateFields[] = 'cliffhanger = ?';
                    $updateValues[] = $s['cliffhanger'];
                }
                if (!empty($updateFields)) {
                    $updateValues[] = $s['id'];
                    DB::execute(
                        'UPDATE chapter_synopses SET ' . implode(', ', $updateFields) . ' WHERE id = ?',
                        $updateValues
                    );
                }
            }
        }
        
        // 审计修复（2026-07-19 H-中4）：current_chapter 表示"已完成正文章数"，
        // 概要导入不应污染该字段（否则进度虚高，挂机判断错乱）。
        // 原 SQL `WHERE current_chapter < $maxChapter` 会把所有概要导入章节计入"已完成"。
        // 删除该 UPDATE，让 current_chapter 仅由实际章节落盘流程更新。
        // （如需迁移历史数据，应另写一次性脚本，而非在每次导入时执行）
        
        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // 导入新增/更新了章节行与小说进度，失效相关缓存（章节列表/计数/小说行）
    clearNovelCache($novelId);

    return [
        'imported_count' => $importedCount,
        'created_count'  => $createdCount,
        'skipped_count'  => $skippedCount,
        'error_count'    => $errorCount,
    ];
}
