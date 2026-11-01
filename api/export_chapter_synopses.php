<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * 导出章节概要 API
 * 支持格式：JSON / Excel / TXT
 */

// 定义常量，允许直接访问
defined('APP_LOADED') or define('APP_LOADED', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
registerApiErrorHandlers();
require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ChapterSynopsisTextFormat.php';

// 获取参数
$novelId = intval($_GET['novel_id'] ?? 0);
$format = $_GET['format'] ?? 'json'; // json / excel / txt

if (!$novelId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '缺少小说ID'], JSON_UNESCAPED_UNICODE);
    exit;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
checkNovelOwnership($novelId, $userId);

// 验证小说存在
$novel = DB::fetch('SELECT id, title FROM novels WHERE id = ?', [$novelId]);
if (!$novel) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => '小说不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取所有章节及概要
$chapters = DB::fetchAll('
    SELECT 
        c.chapter_number,
        c.title,
        c.outline,
        cs.synopsis,
        cs.pacing,
        cs.cliffhanger
    FROM chapters c
    LEFT JOIN chapter_synopses cs ON c.chapter_number = cs.chapter_number AND c.novel_id = cs.novel_id
    WHERE c.novel_id = ?
    ORDER BY c.chapter_number ASC
', [$novelId]);

if (empty($chapters)) {
    http_response_code(404);
    echo json_encode(['error' => '没有章节数据']);
    exit;
}

// 根据格式导出
switch ($format) {
    case 'json':
        exportAsJson($novel, $chapters);
        break;
    case 'excel':
        exportAsExcel($novel, $chapters);
        break;
    case 'txt':
        exportAsTxt($novel, $chapters);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => '不支持的格式']);
        exit;
}

/**
 * 导出为JSON格式
 */
function exportAsJson(array $novel, array $chapters): void {
    $data = [
        'novel_id' => $novel['id'],
        'novel_title' => $novel['title'],
        'export_time' => date('Y-m-d H:i:s'),
        'total_chapters' => count($chapters),
        'chapters' => array_map(function($ch) {
            return [
                'chapter_number' => intval($ch['chapter_number']),
                'title' => $ch['title'] ?? '',
                'outline' => $ch['outline'] ?? '',
                'synopsis' => $ch['synopsis'] ?? '',
                'pacing' => $ch['pacing'] ?? '中',
                'cliffhanger' => $ch['cliffhanger'] ?? ''
            ];
        }, $chapters)
    ];
    
    // 设置响应头
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: ' . contentDispositionAttachment($novel['title'] . '_章节概要_' . date('Ymd_His') . '.json'));
    header('Cache-Control: no-cache, must-revalidate');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * 导出为Excel格式（CSV，兼容Excel）
 * 防御：
 *  1) Content-Disposition 文件名注入 → 统一经 contentDispositionAttachment() 清洗（RFC 6266/5987）
 *  2) CSV 公式注入（= + - @ 开头）→ 通过 ecsCsvSafe() 转义
 */
function exportAsExcel(array $novel, array $chapters): void {
    // 设置响应头
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: ' . contentDispositionAttachment($novel['title'] . '_章节概要_' . date('Ymd_His') . '.csv'));
    header('Cache-Control: no-cache, must-revalidate');

    // 输出UTF-8 BOM，确保Excel正确识别中文
    echo "\xEF\xBB\xBF";

    // 创建输出流
    $output = fopen('php://output', 'w');

    // 写入标题行
    fputcsv($output, ['章节编号', '章节标题', '章节大纲', '章节概要', '节奏', '结尾悬念']);

    // 写入数据行（CSV 单元格经 ecsCsvSafe 防公式注入）
    foreach ($chapters as $ch) {
        fputcsv($output, [
            (int)($ch['chapter_number'] ?? 0),
            ecsCsvSafe($ch['title']     ?? ''),
            ecsCsvSafe($ch['outline']    ?? ''),
            ecsCsvSafe($ch['synopsis']   ?? ''),
            ecsCsvSafe($ch['pacing']     ?? '中'),
            ecsCsvSafe($ch['cliffhanger'] ?? ''),
        ]);
    }

    fclose($output);
}

/**
 * 导出为TXT格式
 */
function exportAsTxt(array $novel, array $chapters): void {
    // 设置响应头
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: ' . contentDispositionAttachment($novel['title'] . '_章节概要_' . date('Ymd_His') . '.txt'));
    header('Cache-Control: no-cache, must-revalidate');

    echo ChapterSynopsisTextFormat::encode($novel, $chapters);
}

/**
 * CSV 单元格防公式注入：
 *  - 以 = + - @ \t \r 开头的单元格在 Excel/Sheets 中会被解释为公式。
 *  - 通过前缀 ' (单引号) 转义，保留原始数据展示。
 *  - 同时去掉单元格内的 \r，避免 CSV 行结构被破坏。
 */
function ecsCsvSafe(?string $raw): string
{
    $s = (string)($raw ?? '');
    $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);
    if ($s !== '' && preg_match('/^[=+\-\@\t]/', $s)) {
        $s = "'" . $s;
    }
    return $s;
}
