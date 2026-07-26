<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
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
require_once dirname(__DIR__) . '/includes/write_engine.php';
require_once dirname(__DIR__) . '/includes/ChapterMutationService.php';
require_once dirname(__DIR__) . '/includes/tasks/WritingTaskRepository.php';
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

    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    checkNovelOwnership($novelId, $userId);

    if ($action === 'reset_chapter' && !$chapterId) {
        throw new Exception('重置单个章节需要提供 chapter_id');
    }

    // 审计修复 L-7（2026-07-01）：reset_chapter 前验证章节所有权
    if ($action === 'reset_chapter' && $chapterId) {
        checkChapterOwnership($chapterId, $userId);
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
            // 取消写作：设置取消标志（后端写作进程会检查此标志自行终止）
            DB::query('UPDATE novels SET cancel_flag = 1 WHERE id = ?', [$novelId]);
            WritingTaskRepository::requestCancel($novelId);
            // v1.4 文件系统加速：避免每 50 token 查一次 DB，用 file_exists() 快 100+ 倍
            // 修复：路径统一到 CFG_PROGRESS_DIR（与 pp_pending / 进度文件同目录）
            @mkdir(CFG_PROGRESS_DIR, 0755, true);
            file_put_contents(WriteEngine::cancelFlagPath($novelId), time(), LOCK_EX);
            
            // 重置所有正在写作的章节状态（不清空内容，保留部分写作成果供恢复）
            // 注意：如果后端 ignore_user_abort 仍在运行，落盘时 WHERE status="writing" 条件
            // 会因状态已变更为 "outlined" 而阻止覆盖，避免竞态条件
            DB::query(
                'UPDATE chapters SET status = "outlined"
                 WHERE novel_id = ? AND status = "writing"',
                [$novelId]
            );
            
            // 重置小说状态（审计修复 2026-07-19 H-05：同步关闭挂机开关，
            // 否则 cron 每分钟会重新唤起 daemon_write，用户无法真正停止挂机写作）
            DB::query('UPDATE novels SET status = "paused", daemon_write = 0 WHERE id = ?', [$novelId]);

            $message = '已取消写作';
        } else if ($action === 'reset') {
            // 重置：清空所有未完成的章节内容
            // 仅逐章处理真实含有部分正文的行，避免对上千个空 outlined 行运行
            // 派生失效；空行随后用一条批量 UPDATE 统一恢复状态。
            $partialChapters = DB::fetchAll(
                'SELECT id FROM chapters
                 WHERE novel_id=? AND status!="completed" AND content IS NOT NULL AND content<>""',
                [$novelId]
            );
            foreach ($partialChapters as $partial) {
                ChapterMutationService::mutateChapter((int)$partial['id'], $novelId, [
                    'content' => '',
                    'words'   => 0,
                    'status'  => 'outlined',
                ], [
                    'backup_version' => true,
                    'prevent_writing' => false,
                    'force_content_invalidation' => true,
                    'reason' => 'cancel_reset_partial',
                ]);
            }
            DB::query(
                'UPDATE chapters SET content = "", words = 0, status = "outlined" 
                 WHERE novel_id = ? AND status != "completed"',
                [$novelId]
            );
            
            // 重置小说状态（paused 为有效枚举值，outlined 不在 novels.status 枚举中）
            DB::query('UPDATE novels SET status = "paused", cancel_flag = 0 WHERE id = ?', [$novelId]);
            @unlink(WriteEngine::cancelFlagPath($novelId));
            
            $message = '已重置所有未完成章节';
        } else if ($action === 'reset_chapter' && $chapterId) {
            // 重置单个章节
            @unlink(WriteEngine::cancelFlagPath($novelId));
            ChapterMutationService::mutateChapter($chapterId, $novelId, [
                'content' => '',
                'words'   => 0,
                'status'  => 'outlined',
            ], [
                'backup_version' => true,
                'prevent_writing' => false,
                'force_content_invalidation' => true,
                'reason' => 'cancel_reset_chapter',
            ]);
            
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
    echo json_encode(safe_api_error_payload($e, '取消写作失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
}
