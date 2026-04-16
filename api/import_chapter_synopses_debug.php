<?php
/**
 * 调试版导入章节概要 API
 */

// 开启错误显示（调试用）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 定义常量，允许直接访问
define('APP_LOADED', true);

// 开启输出缓冲
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '加载文件失败: ' . $e->getMessage()]);
    exit;
}

// 获取缓冲内容
$buffer = ob_get_clean();

// 如果有意外输出，记录下来
if (!empty($buffer)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '意外输出: ' . $buffer]);
    exit;
}

// 设置 JSON 响应头
header('Content-Type: application/json; charset=utf-8');

// 验证登录
if (empty($_SESSION['logged_in'])) {
    echo json_encode(['error' => '未登录', 'session' => $_SESSION]);
    exit;
}

// 只接受POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '方法不允许', 'method' => $_SERVER['REQUEST_METHOD']]);
    exit;
}

// 获取参数
$novelId = intval($_POST['novel_id'] ?? 0);
$importMode = $_POST['import_mode'] ?? 'incremental';

if (!$novelId) {
    echo json_encode(['error' => '缺少小说ID', 'post' => $_POST]);
    exit;
}

// 验证小说存在
try {
    $novel = DB::fetch('SELECT id, title FROM novels WHERE id = ?', [$novelId]);
} catch (Exception $e) {
    echo json_encode(['error' => '数据库查询失败: ' . $e->getMessage()]);
    exit;
}

if (!$novel) {
    echo json_encode(['error' => '小说不存在', 'novel_id' => $novelId]);
    exit;
}

// 检查文件上传
if (!isset($_FILES['file'])) {
    echo json_encode(['error' => '没有文件上传', 'files' => $_FILES]);
    exit;
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => '文件超过 php.ini 中 upload_max_filesize 限制',
        UPLOAD_ERR_FORM_SIZE => '文件超过表单中 MAX_FILE_SIZE 限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '缺少临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '写入文件失败',
        UPLOAD_ERR_EXTENSION => 'PHP 扩展停止了文件上传',
    ];
    $errorCode = $_FILES['file']['error'];
    echo json_encode(['error' => '文件上传失败: ' . ($errors[$errorCode] ?? "错误码 $errorCode")]);
    exit;
}

$file = $_FILES['file'];
$filePath = $file['tmp_name'];
$fileName = $file['name'];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// 检查文件是否存在
if (!file_exists($filePath)) {
    echo json_encode(['error' => '临时文件不存在', 'tmp_name' => $filePath]);
    exit;
}

// 检查文件内容
$fileContent = file_get_contents($filePath);
if (empty($fileContent)) {
    echo json_encode(['error' => '文件内容为空']);
    exit;
}

// 根据文件扩展名解析
try {
    $chapters = [];
    
    switch ($fileExt) {
        case 'json':
            $data = json_decode($fileContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['error' => 'JSON解析失败: ' . json_last_error_msg(), 'content_preview' => substr($fileContent, 0, 200)]);
                exit;
            }
            $chapters = $data['chapters'] ?? $data;
            break;
            
        case 'csv':
        case 'txt':
            echo json_encode(['error' => 'CSV/TXT 格式暂不支持调试，请使用 JSON 格式']);
            exit;
            
        default:
            echo json_encode(['error' => '不支持的文件格式: ' . $fileExt]);
            exit;
    }
    
    if (empty($chapters)) {
        echo json_encode(['error' => '文件中没有有效的章节数据', 'data' => $data ?? null]);
        exit;
    }
    
    // 实际导入数据
    $result = importChapters($novelId, $chapters, $importMode);
    
    echo json_encode([
        'success' => true,
        'message' => '导入成功',
        'imported_count' => $result['imported_count'],
        'skipped_count' => $result['skipped_count'],
        'error_count' => $result['error_count'],
        'details' => $result['details'],
        'debug_info' => [
            'novel_id' => $novelId,
            'novel_title' => $novel['title'],
            'import_mode' => $importMode,
            'chapters_count' => count($chapters),
            'file_name' => $fileName
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['error' => '处理失败: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

/**
 * 导入章节到数据库
 */
function importChapters(int $novelId, array $chapters, string $importMode): array {
    $importedCount = 0;
    $skippedCount = 0;
    $errorCount = 0;
    $details = [];
    
    // 获取所有现有章节
    $existingChapters = DB::fetchAll('
        SELECT id, chapter_number 
        FROM chapters 
        WHERE novel_id = ?
    ', [$novelId]);
    
    $chapterMap = [];
    foreach ($existingChapters as $ch) {
        $chapterMap[$ch['chapter_number']] = $ch['id'];
    }
    
    // 开始事务
    $pdo = DB::getPdo();
    $pdo->beginTransaction();
    
    try {
        foreach ($chapters as $ch) {
            $chapterNumber = $ch['chapter_number'] ?? 0;
            
            if ($chapterNumber <= 0) {
                $errorCount++;
                $details[] = [
                    'chapter_number' => $chapterNumber,
                    'status' => 'error',
                    'message' => '章节编号无效'
                ];
                continue;
            }
            
            // 检查章节是否存在
            if (!isset($chapterMap[$chapterNumber])) {
                $skippedCount++;
                $details[] = [
                    'chapter_number' => $chapterNumber,
                    'status' => 'skipped',
                    'message' => '章节不存在'
                ];
                continue;
            }
            
            $chapterId = $chapterMap[$chapterNumber];
            
            // 更新章节标题和大纲
            DB::execute('
                UPDATE chapters 
                SET title = ?, outline = ?
                WHERE id = ?
            ', [$ch['title'] ?? '', $ch['outline'] ?? '', $chapterId]);
            
            // 检查是否已有概要（使用 novel_id 和 chapter_number 关联）
            $existingSynopsis = DB::fetch('
                SELECT id FROM chapter_synopses WHERE novel_id = ? AND chapter_number = ?
            ', [$novelId, $chapterNumber]);
            
            if ($existingSynopsis) {
                // 增量模式：只更新非空字段
                if ($importMode === 'incremental') {
                    $updateFields = [];
                    $updateValues = [];
                    
                    if (!empty($ch['synopsis'])) {
                        $updateFields[] = 'synopsis = ?';
                        $updateValues[] = $ch['synopsis'];
                    }
                    if (!empty($ch['pacing'])) {
                        $updateFields[] = 'pacing = ?';
                        $updateValues[] = $ch['pacing'];
                    }
                    if (!empty($ch['cliffhanger'])) {
                        $updateFields[] = 'cliffhanger = ?';
                        $updateValues[] = $ch['cliffhanger'];
                    }
                    
                    if (!empty($updateFields)) {
                        $updateValues[] = $existingSynopsis['id'];
                        DB::execute('
                            UPDATE chapter_synopses 
                            SET ' . implode(', ', $updateFields) . '
                            WHERE id = ?
                        ', $updateValues);
                    }
                }
                // 覆盖模式：直接更新所有字段
                else {
                    DB::execute('
                        UPDATE chapter_synopses 
                        SET synopsis = ?, pacing = ?, cliffhanger = ?
                        WHERE id = ?
                    ', [
                        $ch['synopsis'] ?? '',
                        $ch['pacing'] ?? '中',
                        $ch['cliffhanger'] ?? '',
                        $existingSynopsis['id']
                    ]);
                }
            } else {
                // 创建新概要（使用 novel_id 和 chapter_number）
                DB::execute('
                    INSERT INTO chapter_synopses (novel_id, chapter_number, synopsis, pacing, cliffhanger)
                    VALUES (?, ?, ?, ?, ?)
                ', [
                    $novelId,
                    $chapterNumber,
                    $ch['synopsis'] ?? '',
                    $ch['pacing'] ?? '中',
                    $ch['cliffhanger'] ?? ''
                ]);
            }
            
            $importedCount++;
            $details[] = [
                'chapter_number' => $chapterNumber,
                'status' => 'success',
                'message' => '导入成功'
            ];
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
    return [
        'imported_count' => $importedCount,
        'skipped_count' => $skippedCount,
        'error_count' => $errorCount,
        'details' => $details
    ];
}
