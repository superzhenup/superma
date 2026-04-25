<?php
/**
 * 取消写作 API
 * POST JSON: { novel_id, chapter_id? }
 * 
 * 功能：
 * 1. 设置取消标志
 * 2. 重置章节状态
 * 3. 清空正在生成的内容
 */

ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $novelId = (int)($input['novel_id'] ?? 0);
    $chapterId = (int)($input['chapter_id'] ?? 0);
    $action = $input['action'] ?? 'cancel'; // cancel | reset | reset_chapter
    $validActions = ['cancel', 'reset', 'reset_chapter'];
    if (!in_array($action, $validActions, true)) {
        throw new Exception('无效操作类型：' . $action);
    }
    
    if (!$novelId) {
        throw new Exception('缺少小说 ID');
    }
    
    if ($action === 'reset_chapter' && !$chapterId) {
        throw new Exception('重置单个章节需要提供 chapter_id');
    }
    
    // 检查小说是否存在
    $novel = DB::fetch('SELECT id, status FROM novels WHERE id = ?', [$novelId]);
    if (!$novel) {
        throw new Exception('小说不存在');
    }
    
    $pdo = DB::connect();
    $pdo->beginTransaction();
    
    try {
        if ($action === 'cancel') {
            // 取消写作：设置取消标志
            DB::query('UPDATE novels SET cancel_flag = 1 WHERE id = ?', [$novelId]);
            
            // 重置所有正在写作的章节
            DB::query(
                'UPDATE chapters SET status = "outlined", content = "", words = 0 
                 WHERE novel_id = ? AND status = "writing"',
                [$novelId]
            );
            
            // 重置小说状态
            DB::query('UPDATE novels SET status = "paused" WHERE id = ?', [$novelId]);
            
            $message = '已取消写作';
        } else if ($action === 'reset') {
            // 重置：清空所有未完成的章节内容
            DB::query(
                'UPDATE chapters SET content = "", words = 0, status = "outlined" 
                 WHERE novel_id = ? AND status != "completed"',
                [$novelId]
            );
            
            // 重置小说状态（paused 为有效枚举值，outlined 不在 novels.status 枚举中）
            DB::query('UPDATE novels SET status = "paused", cancel_flag = 0 WHERE id = ?', [$novelId]);
            
            $message = '已重置所有未完成章节';
        } else if ($action === 'reset_chapter' && $chapterId) {
            // 重置单个章节
            DB::query(
                'UPDATE chapters SET content = "", words = 0, status = "outlined" 
                 WHERE id = ? AND novel_id = ?',
                [$chapterId, $novelId]
            );
            
            $message = '已重置章节内容';
        }
        
        $pdo->commit();
        
        echo json_encode([
            'ok' => true,
            'msg' => $message
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
