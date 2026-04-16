<?php
/**
 * 导入章节概要 API
 * 支持格式：JSON / Excel(CSV) / TXT
 */

// 关闭错误显示，只记录日志
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 开启输出缓冲
ob_start();

// 定义常量，允许直接访问
define('APP_LOADED', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 清空输出缓冲
ob_end_clean();

// 设置 JSON 响应头
header('Content-Type: application/json; charset=utf-8');

// 验证登录
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

// 只接受POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '方法不允许']);
    exit;
}

// 获取参数
$novelId = intval($_POST['novel_id'] ?? 0);
$importMode = $_POST['import_mode'] ?? 'incremental'; // incremental / overwrite

if (!$novelId) {
    http_response_code(400);
    echo json_encode(['error' => '缺少小说ID']);
    exit;
}

// 验证小说存在
$novel = DB::fetch('SELECT id, title FROM novels WHERE id = ?', [$novelId]);
if (!$novel) {
    http_response_code(404);
    echo json_encode(['error' => '小说不存在']);
    exit;
}

// 检查文件上传
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => '文件上传失败']);
    exit;
}

$file = $_FILES['file'];
$filePath = $file['tmp_name'];
$fileName = $file['name'];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// 根据文件扩展名解析
try {
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
            echo json_encode(['error' => '不支持的文件格式，仅支持 JSON / CSV / TXT']);
            exit;
    }
    
    if (empty($chapters)) {
        http_response_code(400);
        echo json_encode(['error' => '文件中没有有效的章节数据']);
        exit;
    }
    
    // 导入数据
    $result = importChapters($novelId, $chapters, $importMode);
    
    echo json_encode([
        'success' => true,
        'message' => '导入成功',
        'imported_count' => $result['imported_count'],
        'skipped_count' => $result['skipped_count'],
        'error_count' => $result['error_count'],
        'details' => $result['details']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '导入失败：' . $e->getMessage()]);
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
        $chapter = [
            'chapter_number' => intval($row[$colMap['chapter_number']] ?? 0),
            'title' => $row[$colMap['title']] ?? '',
            'outline' => $row[$colMap['outline']] ?? '',
            'synopsis' => $row[$colMap['synopsis']] ?? '',
            'pacing' => $row[$colMap['pacing']] ?? '中',
            'cliffhanger' => $row[$colMap['cliffhanger']] ?? ''
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
    $chapters = [];
    
    // 按章节分割（支持多种分隔符）
    $lines = explode("\n", $content);
    $currentChapter = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // 跳过空行和分隔线
        if (empty($line) || strpos($line, '===') === 0 || strpos($line, '---') === 0) {
            continue;
        }
        
        // 检测章节标题
        if (preg_match('/^【第(\d+)章】(.+)$/', $line, $matches)) {
            // 保存上一章节
            if ($currentChapter !== null) {
                $chapters[] = $currentChapter;
            }
            
            // 开始新章节
            $currentChapter = [
                'chapter_number' => intval($matches[1]),
                'title' => trim($matches[2]),
                'outline' => '',
                'synopsis' => '',
                'pacing' => '中',
                'cliffhanger' => ''
            ];
        }
        // 解析字段
        elseif ($currentChapter !== null) {
            if (strpos($line, '【大纲】') === 0) {
                $currentChapter['outline'] = trim(substr($line, strlen('【大纲】')));
            }
            elseif (strpos($line, '【概要】') === 0) {
                $currentChapter['synopsis'] = trim(substr($line, strlen('【概要】')));
            }
            elseif (strpos($line, '【节奏】') === 0) {
                $currentChapter['pacing'] = trim(substr($line, strlen('【节奏】')));
            }
            elseif (strpos($line, '【悬念】') === 0) {
                $currentChapter['cliffhanger'] = trim(substr($line, strlen('【悬念】')));
            }
        }
    }
    
    // 保存最后一个章节
    if ($currentChapter !== null) {
        $chapters[] = $currentChapter;
    }
    
    return $chapters;
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
